<?php

namespace App\Services\Membership;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Attendance;
use App\Models\MembershipAdjustment;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\EmployeeAccess;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ¿PUEDE ENTRAR ESTA PERSONA, AHORA MISMO? Y si no, ¿por qué no.
 *
 * Hasta ahora la respuesta era una sola comparación de fechas, repetida en tres
 * sitios: el terminal de recepción, la pantalla de Asistencias y el perfil del
 * socio. Con los planes por consumo y las horas valle deja de alcanzar: el mismo
 * socio, vigente y al día, puede tener prohibido entrar hoy por ser jueves, a
 * esta hora, o porque ya gastó sus 15 entradas del mes.
 *
 * ESTA CLASE ES LA ÚNICA QUE DECIDE. Los tres clientes preguntan y muestran lo
 * que les diga; ninguno vuelve a interpretar reglas por su cuenta. Si el
 * terminal decidiera distinto al CRM, el gimnasio tendría dos verdades sobre
 * quién entra y ninguna forma de saber cuál vale.
 *
 * LAS ENTRADAS NO SE GUARDAN EN UN CONTADOR. Se CUENTAN: entradas registradas
 * dentro del periodo vigente, más los ajustes a mano, contra las que trae el
 * plan. Un contador se desincroniza con el primer registro que llega tarde del
 * terminal, con la primera asistencia corregida y con la primera anulación de
 * pago; un recuento no puede desincronizarse porque no guarda nada.
 *
 * SE CUENTAN DÍAS, NO MARCAJES. Quien entra, sale a comprar agua y vuelve gastó
 * UNA entrada, no dos. Es lo que el gimnasio vende («15 entradas al mes» = 15
 * días en que puede venir) y además hace que el doble marcaje del lector facial
 * no le cueste dinero a nadie.
 *
 * HAY DOS PUERTAS, Y BASTA CON QUE UNA ESTÉ ABIERTA. La membresía es una; el
 * acceso de empleado es la otra, y no tiene precio ni vencimiento comprado: el
 * gimnasio le permite entrenar porque trabaja aquí. Un entrenador puede tener
 * las dos —paga su mensualidad y además es empleado— y entonces entra por la
 * que esté abierta en ese momento. Ver {@see EmployeeAccess}.
 */
class MembershipAccess
{
    public const TZ = MembershipPeriod::TZ;

    /**
     * Por qué se le niega el paso. El orden en que se evalúan importa: primero
     * lo que dice que la membresía no vale hoy, después lo que dice que hoy no
     * es su día.
     */
    public const REASON_FROZEN = 'frozen';

    public const REASON_NO_MEMBERSHIP = 'no_membership';

    public const REASON_NOT_STARTED = 'not_started';

    public const REASON_EXPIRED = 'expired';

    public const REASON_WEEKDAY = 'weekday';

    public const REASON_HOURS = 'hours';

    public const REASON_NO_ENTRIES = 'no_entries';

    /** Por dónde entró, cuando entró. Lo necesita quien descuenta entradas. */
    public const VIA_MEMBERSHIP = 'membership';

    public const VIA_EMPLOYEE = 'employee';

    /** @var Collection<string, Plan>|null */
    private ?Collection $planCache = null;

    /**
     * El acceso de un socio, ya resuelto.
     *
     * @return array<string, mixed>
     */
    public function state(User $user, ?CarbonImmutable $at = null): array
    {
        $estados = $this->statesFor(collect([$user]), $at);

        return $estados[$user->id];
    }

    /**
     * Lo mismo para una página entera de socios, sin una consulta por fila: el
     * listado de Miembros y la sincronización del terminal piden cincuenta a la
     * vez y no pueden pagar 150 consultas por eso.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, mixed>>
     */
    public function statesFor(Collection $users, ?CarbonImmutable $at = null): array
    {
        $ahora = ($at ?? CarbonImmutable::now(self::TZ))->setTimezone(self::TZ);
        $hoy = $ahora->startOfDay();
        $pausas = app(MembershipFreeze::class);
        $planes = $this->plansByName();

        // Las reglas de cada socio primero: solo los planes por consumo pagan el
        // recuento de entradas, y son los menos.
        $reglas = [];
        $planDe = [];
        foreach ($users as $user) {
            $plan = $planes->get(self::planKey((string) ($user->plan ?? '')));
            $planDe[$user->id] = $plan;
            $reglas[$user->id] = PlanAccessRules::fromPlan($plan);
        }

        // Los permisos de empleado de toda la página, de una consulta. Son
        // pocos —el personal del gimnasio— pero la puerta los pregunta por
        // todos, así que no pueden costar una consulta por socio.
        $empleos = EmployeeAccess::query()
            ->whereIn('user_id', $users->pluck('id')->all())
            ->get()
            ->keyBy('user_id');

        $porConsumo = $users->filter(fn (User $u): bool => $reglas[$u->id]->isByEntries());
        $ventanas = $this->windowsFor($porConsumo, $hoy);
        $usadas = $this->usedEntriesFor($porConsumo, $ventanas, $ahora);
        $regaladas = $this->grantedEntriesFor($porConsumo, $ventanas);

        $estados = [];
        foreach ($users as $user) {
            $estados[$user->id] = $this->compose(
                $user,
                $reglas[$user->id],
                $planDe[$user->id],
                $pausas->state($user),
                $ventanas[$user->id] ?? null,
                $usadas[$user->id] ?? ['days' => [], 'last' => null],
                $regaladas[$user->id] ?? 0,
                $empleos->get($user->id),
                $ahora,
            );
        }

        return $estados;
    }

    /**
     * Arma la respuesta y toma la decisión.
     *
     * @param  array<string, mixed>  $pausa
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, source: string}|null  $ventana
     * @param  array{days: list<string>, last: string|null}  $usadas
     * @return array<string, mixed>
     */
    private function compose(
        User $user,
        PlanAccessRules $reglas,
        ?Plan $plan,
        array $pausa,
        ?array $ventana,
        array $usadas,
        int $regaladas,
        ?EmployeeAccess $empleo,
        CarbonImmutable $ahora,
    ): array {
        $hoy = $ahora->startOfDay();
        $inicio = $this->day($user->membership_start_date);
        $fin = $this->day($user->membership_end_date);
        $empleado = $this->employeeState($empleo, $ahora);

        // ── Las entradas ────────────────────────────────────────────────────
        $entradas = null;
        if ($reglas->isByEntries()) {
            $ventana ??= $this->fallbackWindow($inicio, $fin, $hoy);
            $incluidas = (int) $reglas->entries;
            $total = max(0, $incluidas + $regaladas);
            $diasUsados = $this->daysInWindow($usadas['days'], $ventana);
            $usadasCount = count($diasUsados);
            $hoyYaContada = in_array($hoy->toDateString(), $diasUsados, true);

            $entradas = [
                'included' => $incluidas,
                'granted' => $regaladas,
                'total' => $total,
                'used' => $usadasCount,
                'left' => max(0, $total - $usadasCount),
                // Ya entró hoy: puede volver a pasar sin gastar otra entrada.
                'used_today' => $hoyYaContada,
                'last_entry_on' => $usadas['last'],
                'window' => [
                    'from' => $ventana['from']->toDateString(),
                    'to' => $ventana['to']->toDateString(),
                    'source' => $ventana['source'],
                ],
            ];
        }

        // ── La decisión ─────────────────────────────────────────────────────
        [$motivo, $mensaje, $via] = $this->decide($reglas, $pausa, $entradas, $empleado, $inicio, $fin, $ahora);

        return [
            'plan' => $plan ? ['id' => $plan->id, 'name' => $plan->name] : null,
            'rules' => $reglas->toArray(),
            'entries' => $entradas,
            'employee' => $empleado,
            'can_enter' => $motivo === null,
            // Por qué puerta pasó. El registro de asistencia lo necesita para
            // NO descontarle una entrada a quien entró por ser empleado.
            'via' => $via,
            'reason' => $motivo,
            'message' => $mensaje,
            // Resumen de una línea para la ficha y el terminal: «7 de 15
            // entradas · Lun, Mié y Vie · 06:00–10:00».
            'summary' => $this->summary($reglas, $entradas, $empleado),
        ];
    }

    /**
     * LAS DOS PUERTAS.
     *
     * Primero la del empleado, porque es la que no cuesta nada: quien trabaja
     * aquí y está en su franja pasa, tenga o no membresía, y sin gastar una
     * entrada de ningún plan.
     *
     * Después la de la membresía, exactamente como siempre.
     *
     * Y si las dos están cerradas hay que elegir QUÉ se le dice. A quien solo
     * entra por ser empleado no le sirve «no tiene una membresía registrada»:
     * no la necesita, lo que pasa es que su permiso se acabó o no es su hora.
     * A quien además es socio se le da el motivo de la membresía, que es lo
     * que puede renovar.
     *
     * @param  array<string, mixed>  $pausa
     * @param  array<string, mixed>|null  $entradas
     * @param  array<string, mixed>|null  $empleado
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function decide(
        PlanAccessRules $reglas,
        array $pausa,
        ?array $entradas,
        ?array $empleado,
        ?CarbonImmutable $inicio,
        ?CarbonImmutable $fin,
        CarbonImmutable $ahora,
    ): array {
        if ($empleado !== null && $empleado['open_now']) {
            return [null, null, self::VIA_EMPLOYEE];
        }

        [$motivo, $mensaje] = $this->decideMembership($reglas, $pausa, $entradas, $inicio, $fin, $ahora);

        if ($motivo === null) {
            return [null, null, self::VIA_MEMBERSHIP];
        }

        if ($empleado !== null && $fin === null) {
            return $this->employeeReason($empleado, $ahora);
        }

        return [$motivo, $mensaje, null];
    }

    /**
     * Por qué no pasa un empleado que no tiene membresía.
     *
     * Se reutilizan los motivos que ya existen —el terminal y el CRM saben
     * pintarlos— y lo que cambia es el texto, que es lo único que de verdad
     * necesita ser distinto: a un empleado no se le habla de «su plan».
     *
     * @param  array<string, mixed>  $empleado
     * @return array{0: string, 1: string, 2: null}
     */
    private function employeeReason(array $empleado, CarbonImmutable $ahora): array
    {
        if (! $empleado['current']) {
            $hasta = $empleado['ends_on'] ?? null;

            return [
                self::REASON_NO_MEMBERSHIP,
                $hasta
                    ? 'Su acceso de empleado terminó el '.$this->prettyDay($hasta).'.'
                    : 'Su acceso de empleado está desactivado.',
                null,
            ];
        }

        $reglas = PlanAccessRules::fromArray([
            'access_days' => $empleado['rules']['days'],
            'access_windows' => $empleado['rules']['windows'],
        ]);

        if (! $reglas->allowsDay($ahora)) {
            return [self::REASON_WEEKDAY, 'Su acceso de empleado es solo '.$reglas->daysLabel().'.', null];
        }

        // Sin franjas no habría por qué negarle el paso, así que esto no
        // debería alcanzarse. Si se alcanza, se dice algo cierto en vez de
        // «Su acceso de empleado es de .».
        if (! $reglas->restrictsHours()) {
            return [self::REASON_NO_MEMBERSHIP, 'Su acceso de empleado no está disponible ahora.', null];
        }

        $siguiente = $reglas->nextWindowAfter($ahora);

        return [
            self::REASON_HOURS,
            'Su acceso de empleado es de '.$reglas->hoursLabel().
                ($siguiente ? '. Vuelve a abrir a las '.$siguiente : '').'.',
            null,
        ];
    }

    /**
     * La puerta de la membresía: la de siempre, intacta.
     *
     * @param  array<string, mixed>  $pausa
     * @param  array<string, mixed>|null  $entradas
     * @return array{0: string|null, 1: string|null}
     */
    private function decideMembership(
        PlanAccessRules $reglas,
        array $pausa,
        ?array $entradas,
        ?CarbonImmutable $inicio,
        ?CarbonImmutable $fin,
        CarbonImmutable $ahora,
    ): array {
        $hoy = $ahora->startOfDay();

        if (($pausa['frozen'] ?? false) === true) {
            return [self::REASON_FROZEN, 'Su membresía está congelada hasta el '.$this->prettyDay($pausa['until'] ?? null).'.'];
        }

        if ($fin === null) {
            return [self::REASON_NO_MEMBERSHIP, 'No tiene una membresía registrada.'];
        }

        if ($inicio !== null && $inicio->greaterThan($hoy)) {
            return [self::REASON_NOT_STARTED, 'Su membresía empieza el '.$this->prettyDay($inicio->toDateString()).'.'];
        }

        if ($fin->lessThan($hoy)) {
            return [self::REASON_EXPIRED, 'Su membresía venció el '.$this->prettyDay($fin->toDateString()).'.'];
        }

        if (! $reglas->allowsDay($ahora)) {
            return [self::REASON_WEEKDAY, 'Su plan solo permite entrar '.$reglas->daysLabel().'.'];
        }

        if (! $reglas->allowsHour($ahora)) {
            $siguiente = $reglas->nextWindowAfter($ahora);

            return [
                self::REASON_HOURS,
                'Su plan solo permite entrar '.$reglas->hoursLabel().
                    ($siguiente ? '. La próxima franja abre a las '.$siguiente : '').'.',
            ];
        }

        // Las entradas se revisan al final: es lo único que se GASTA, y no tiene
        // sentido descontarle una a quien de todas formas no iba a pasar. Si ya
        // entró hoy no se le cobra otra: el día ya está pagado.
        if ($entradas !== null && $entradas['left'] <= 0 && ! $entradas['used_today']) {
            return [
                self::REASON_NO_ENTRIES,
                'Ya usó las '.$entradas['total'].' entradas de su plan (periodo hasta el '.
                    $this->prettyDay($entradas['window']['to']).').',
            ];
        }

        return [null, null];
    }

    /**
     * El permiso de empleado, ya resuelto, o null si la persona no lo tiene.
     *
     * Se separan dos cosas que parecen una. `current` dice si el permiso VALE
     * hoy —está encendido y dentro de sus fechas—; `open_now` dice además si
     * es su día y su hora. Distinguirlas es lo que permite que recepción lea
     * «sí es empleado, pero su franja es de 14:00 a 17:00» en vez de un seco
     * «no puede entrar».
     *
     * @return array<string, mixed>|null
     */
    private function employeeState(?EmployeeAccess $empleo, CarbonImmutable $ahora): ?array
    {
        if ($empleo === null) {
            return null;
        }

        $reglas = $empleo->rules();
        $vigente = $empleo->isCurrentOn($ahora->startOfDay());

        return [
            'position' => $empleo->position,
            'active' => (bool) $empleo->active,
            'current' => $vigente,
            'open_now' => $vigente && $reglas->allowsDay($ahora) && $reglas->allowsHour($ahora),
            'starts_on' => $empleo->starts_on?->format('Y-m-d'),
            'ends_on' => $empleo->ends_on?->format('Y-m-d'),
            'note' => $empleo->note,
            'rules' => $reglas->toArray(),
            'granted_by_name' => $empleo->granted_by_name,
            'revoked_at' => $empleo->revoked_at?->toIso8601String(),
            'revoked_by_name' => $empleo->revoked_by_name,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $entradas
     * @param  array<string, mixed>|null  $empleado
     */
    private function summary(PlanAccessRules $reglas, ?array $entradas, ?array $empleado = null): ?string
    {
        $partes = [];

        if ($empleado !== null && $empleado['current']) {
            $partes[] = 'Empleado'.($empleado['position'] ? ' · '.$empleado['position'] : '');
            if ($empleado['rules']['days_label']) {
                $partes[] = (string) $empleado['rules']['days_label'];
            }
            if ($empleado['rules']['hours_label']) {
                $partes[] = (string) $empleado['rules']['hours_label'];
            }
        }

        if ($entradas !== null) {
            $partes[] = $entradas['used'].' de '.$entradas['total'].' entradas';
        }
        if ($reglas->restrictsDays()) {
            $partes[] = (string) $reglas->daysLabel();
        }
        if ($reglas->restrictsHours()) {
            $partes[] = (string) $reglas->hoursLabel();
        }

        return $partes === [] ? null : implode(' · ', $partes);
    }

    /**
     * El periodo al que pertenecen las entradas de un socio. Lo necesita quien
     * REGALA entradas: hay que anotar a qué mes se le dan.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, source: string}
     */
    public function entriesWindow(User $user, ?CarbonImmutable $at = null): array
    {
        $hoy = ($at ?? CarbonImmutable::now(self::TZ))->setTimezone(self::TZ)->startOfDay();

        return $this->windowsFor(collect([$user]), $hoy)[$user->id]
            ?? $this->fallbackWindow(
                $this->day($user->membership_start_date),
                $this->day($user->membership_end_date),
                $hoy,
            );
    }

    /** Las reglas del plan que hoy tiene el socio. */
    public function rulesFor(User $user): PlanAccessRules
    {
        return PlanAccessRules::fromPlan($this->plansByName()->get(self::planKey((string) ($user->plan ?? ''))));
    }

    /**
     * ¿Esta entrada consume una entrada del plan?
     *
     * Solo la primera del día, y solo en planes por consumo. Lo usan el registro
     * de asistencia y el terminal para no descontar dos veces el mismo día.
     *
     * @param  array<string, mixed>  $estado
     */
    public static function consumesEntry(array $estado, bool $isEntry): bool
    {
        // Quien pasó por ser empleado no gasta nada: su entrada no la vendió
        // nadie. Si además tiene un plan por consumo, las entradas que compró
        // siguen ahí para el día que venga fuera de su franja.
        if (($estado['via'] ?? null) === self::VIA_EMPLOYEE) {
            return false;
        }

        return $isEntry
            && is_array($estado['entries'] ?? null)
            && ! ($estado['entries']['used_today'] ?? false);
    }

    // ────────────────────────────────────────────────────────────────────────
    //  De dónde sale cada dato
    // ────────────────────────────────────────────────────────────────────────

    /**
     * El PERIODO al que pertenecen las entradas. No es la membresía completa:
     * un socio que lleva ocho meses renovando tiene una sola
     * `membership_start_date` —la de hace ocho meses— y sus 15 entradas son de
     * ESTE mes. El periodo que compró cada pago sí está congelado en el pago
     * (`period_start`/`period_end`), y es de ahí de donde se lee.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array{from: CarbonImmutable, to: CarbonImmutable, source: string}>
     */
    private function windowsFor(Collection $users, CarbonImmutable $hoy): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $ids = $users->pluck('id')->all();
        $marcadores = implode(',', array_fill(0, count(PaymentController::PAID_STATUSES), '?'));

        $pagos = Payment::query()
            ->whereIn('user_id', $ids)
            ->whereNotNull('plan_id')
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereRaw('LOWER(status) IN ('.$marcadores.')', PaymentController::PAID_STATUSES)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'period_start', 'period_end'])
            ->groupBy('user_id');

        $ventanas = [];
        foreach ($users as $user) {
            /** @var Collection<int, Payment> $suyos */
            $suyos = $pagos->get($user->id) ?? collect();

            // El periodo que cubre hoy. Si ninguno lo cubre —venció, o todavía
            // no empieza— vale el más reciente: es el que el socio está usando
            // o acaba de usar.
            $vigente = $suyos->first(function (Payment $p) use ($hoy): bool {
                $desde = $this->day($p->period_start->format('Y-m-d'));
                $hasta = $this->day($p->period_end->format('Y-m-d'));

                return $desde !== null && $hasta !== null
                    && $desde->lessThanOrEqualTo($hoy) && $hasta->greaterThanOrEqualTo($hoy);
            }) ?? $suyos->first();

            if ($vigente) {
                $ventanas[$user->id] = [
                    'from' => $this->day($vigente->period_start->format('Y-m-d')),
                    'to' => $this->day($vigente->period_end->format('Y-m-d')),
                    'source' => 'payment',
                ];

                continue;
            }

            // Sin pagos con periodo (importados del sistema anterior, o membresía
            // puesta a mano): la membresía del socio es lo único que hay.
            $ventanas[$user->id] = $this->fallbackWindow(
                $this->day($user->membership_start_date),
                $this->day($user->membership_end_date),
                $hoy,
            );
        }

        return $ventanas;
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, source: string} */
    private function fallbackWindow(?CarbonImmutable $inicio, ?CarbonImmutable $fin, CarbonImmutable $hoy): array
    {
        return [
            'from' => $inicio ?? ($fin ? $fin->subDays(30) : $hoy),
            'to' => $fin ?? $hoy,
            'source' => 'membership',
        ];
    }

    /**
     * Los DÍAS en que cada socio entró, dentro del rango que abarcan todas las
     * ventanas. Se traen los marcajes y se doblan en PHP a días del calendario
     * de Neiva: `captured_at` está en UTC, así que una entrada de las 7 de la
     * noche cae al día siguiente si se agrupa por la fecha cruda.
     *
     * @param  Collection<int, User>  $users
     * @param  array<int, array{from: CarbonImmutable, to: CarbonImmutable, source: string}>  $ventanas
     * @return array<int, array{days: list<string>, last: string|null}>
     */
    private function usedEntriesFor(Collection $users, array $ventanas, CarbonImmutable $ahora): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $ids = $users->pluck('id')->all();
        $desde = null;
        foreach ($ids as $id) {
            $inicio = $ventanas[$id]['from'] ?? null;
            if ($inicio && ($desde === null || $inicio->lessThan($desde))) {
                $desde = $inicio;
            }
        }
        $desde ??= $ahora->subDays(60)->startOfDay();

        $marcajes = Attendance::query()
            ->whereIn('user_id', $ids)
            ->where('action', 'entry')
            ->where('captured_at', '>=', $desde->startOfDay()->setTimezone('UTC'))
            ->orderBy('captured_at')
            ->get(['user_id', 'captured_at']);

        $porSocio = [];
        foreach ($marcajes as $marcaje) {
            $dia = CarbonImmutable::parse($marcaje->captured_at)->setTimezone(self::TZ)->toDateString();
            $porSocio[$marcaje->user_id]['days'][$dia] = $dia;
            $porSocio[$marcaje->user_id]['last'] = $dia;
        }

        $resultado = [];
        foreach ($ids as $id) {
            $resultado[$id] = [
                'days' => array_values($porSocio[$id]['days'] ?? []),
                'last' => $porSocio[$id]['last'] ?? null,
            ];
        }

        return $resultado;
    }

    /**
     * Entradas regaladas a mano que pertenecen al periodo en curso. Las de un
     * periodo anterior NO se arrastran: se dieron para ese mes.
     *
     * @param  Collection<int, User>  $users
     * @param  array<int, array{from: CarbonImmutable, to: CarbonImmutable, source: string}>  $ventanas
     * @return array<int, int>
     */
    private function grantedEntriesFor(Collection $users, array $ventanas): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $ajustes = MembershipAdjustment::query()
            ->whereIn('user_id', $users->pluck('id')->all())
            ->where('kind', MembershipAdjustment::KIND_ENTRIES)
            ->get(['user_id', 'amount', 'window_start']);

        $totales = [];
        foreach ($ajustes as $ajuste) {
            $ventana = $ventanas[$ajuste->user_id] ?? null;
            if (! $ventana) {
                continue;
            }

            $dia = $ajuste->window_start
                ? $this->day($ajuste->window_start->format('Y-m-d'))
                : null;

            // Sin ventana anotada se da por buena: es un ajuste viejo y negarlo
            // sería quitarle al socio entradas que alguien le concedió.
            $pertenece = $dia === null
                || ($dia->greaterThanOrEqualTo($ventana['from']) && $dia->lessThanOrEqualTo($ventana['to']));

            if ($pertenece) {
                $totales[$ajuste->user_id] = ($totales[$ajuste->user_id] ?? 0) + (int) $ajuste->amount;
            }
        }

        return $totales;
    }

    /**
     * @param  list<string>  $dias
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, source: string}  $ventana
     * @return list<string>
     */
    private function daysInWindow(array $dias, array $ventana): array
    {
        $desde = $ventana['from']->toDateString();
        $hasta = $ventana['to']->toDateString();

        return array_values(array_filter($dias, fn (string $d): bool => $d >= $desde && $d <= $hasta));
    }

    /**
     * Los planes, una sola vez por instancia y con las columnas que hacen falta.
     * `users.plan` guarda el NOMBRE del plan, así que el índice va por nombre
     * normalizado —sin acentos ni mayúsculas— para que «Plan Valera» y «plan
     * valera» sean el mismo.
     *
     * @return Collection<string, Plan>
     */
    private function plansByName(): Collection
    {
        if ($this->planCache !== null) {
            return $this->planCache;
        }

        return $this->planCache = Plan::query()
            ->get(['id', 'name', 'duration_days', 'access_mode', 'entry_credits', 'access_days', 'access_windows'])
            ->keyBy(fn (Plan $p): string => self::planKey((string) $p->name));
    }

    public static function planKey(string $value): string
    {
        return strtr(mb_strtolower(trim($value)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }

    private function day(mixed $fecha): ?CarbonImmutable
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr((string) $fecha, 0, 10), self::TZ)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** «5 de octubre», como lo diría alguien en recepción. */
    private function prettyDay(?string $fecha): string
    {
        $dia = $this->day($fecha);
        if (! $dia) {
            return 'la fecha registrada';
        }

        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $dia->day.' de '.$meses[(int) $dia->month];
    }
}
