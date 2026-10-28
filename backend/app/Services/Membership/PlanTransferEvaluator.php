<?php

namespace App\Services\Membership;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Exceptions\PlanTransferException;
use App\Http\Controllers\Api\PaymentController;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MembershipAdjustment;
use App\Models\MembershipSubscription;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Caja\MembershipFinancialStanding;
use App\Services\IronAiMembershipAccessService;
use App\Services\Payments\MembershipPeriod;
use App\Services\Wompi\PaymentStateMachine;
use App\Support\DocumentMask;
use App\Support\MemberPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ¿QUÉ LE PASARÍA A CADA SOCIO SI CAMBIARA DE PLAN? Sin escribir nada.
 *
 * Es la simulación del traslado de socios entre planes, y es también lo que la
 * ejecución vuelve a calcular bajo bloqueo para comprobar que nada cambió.
 * Por eso no tiene estado propio que valga más de una llamada: cada pregunta
 * se responde con lo que hay en la base en ese momento.
 *
 * NO REIMPLEMENTA NINGUNA REGLA. Para saber cómo quedaría un socio se le
 * pregunta a las MISMAS clases que deciden en producción, sobre un CLON en
 * memoria del socio con el plan destino puesto —un clon que nunca se guarda—:
 *
 *   puerta del gimnasio   → MembershipAccess::statesFor()
 *   funciones de la app   → MemberPayload::featuresFor()
 *   IRON IA               → IronAiMembershipAccessService
 *   mora                  → MembershipFinancialStanding::summary()
 *
 * Si mañana cambia cómo se cuentan las entradas, la simulación lo cuenta igual
 * que la puerta sin que nadie toque este fichero.
 *
 * SIN UNA CONSULTA POR SOCIO. Los hechos que hacen falta (cobros pendientes,
 * suscripciones, reservas, deudas…) se traen en una consulta por tipo para
 * todo el grupo. Las funciones de la app y las de IRON IA dependen solo del
 * nombre del plan y de la fecha de vencimiento, así que se memorizan por esa
 * pareja: se llama a la clase real una vez por combinación, no una por socio.
 */
class PlanTransferEvaluator
{
    /** Tope de la lista de candidatos: más que eso no se revisa a ojo. */
    public const MAX_CANDIDATES = 1000;

    /** Largo de `users.plan` y de los nombres congelados en el lote. */
    public const MAX_PLAN_NAME = 100;

    // ── Por qué un socio NO se traslada ────────────────────────────────────
    public const BLOCK_NOT_FOUND = 'member_not_found';

    public const BLOCK_PLAN_MISMATCH = 'plan_mismatch';

    public const BLOCK_ALREADY_THERE = 'already_in_destination';

    public const BLOCK_NO_MEMBERSHIP = 'no_membership';

    public const BLOCK_ENTRIES_EXHAUSTED = 'entries_exhausted';

    public const BLOCK_PENDING_PAYMENT = 'pending_origin_payment';

    public const BLOCK_LIVE_SUBSCRIPTION = 'live_subscription_origin';

    /** Avisos que solo informan y no obligan a confirmar nada. */
    private const INFO_WARNINGS = ['employee_access', 'no_app_account'];

    /** @var array<string, array<string, mixed>> */
    private array $memoFeatures = [];

    /** @var array<string, bool> */
    private array $memoAi = [];

    public function __construct(
        private readonly PlanConditions $conditions,
        private readonly IronAiMembershipAccessService $ai,
        private readonly MembershipFinancialStanding $standing,
    ) {}

    // ────────────────────────────────────────────────────────────────────────
    //  Lo que se puede preguntar
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Rechazos de la PETICIÓN entera, antes de mirar a ningún socio.
     *
     * @throws PlanTransferException
     */
    public function assertPlans(Plan $from, Plan $to): void
    {
        if ((int) $from->id === (int) $to->id) {
            throw PlanTransferException::samePlan();
        }
        if (! $to->active) {
            throw PlanTransferException::destinationInactive();
        }
        if ((int) $to->duration_days <= 0) {
            throw PlanTransferException::destinationWithoutDuration();
        }
        // `users.plan` y los nombres congelados del lote son varchar(100). En
        // PostgreSQL un nombre más largo no se recorta: revienta el INSERT con
        // un 500 a mitad del traslado. Se dice antes de empezar.
        if (mb_strlen((string) $to->name) > self::MAX_PLAN_NAME) {
            throw PlanTransferException::nameTooLong('to_plan_name_too_long', self::MAX_PLAN_NAME);
        }
        if (mb_strlen((string) $from->name) > self::MAX_PLAN_NAME) {
            throw PlanTransferException::nameTooLong('from_plan_name_too_long', self::MAX_PLAN_NAME);
        }

        // `MembershipAccess` resuelve `users.plan` por nombre normalizado —sin
        // tildes ni mayúsculas— y con dos planes que normalizan igual se queda
        // con uno de los dos. Trasladar entre ellos, o hacia uno de ellos, es
        // no saber qué reglas acabaría aplicando la puerta.
        $claveOrigen = MembershipAccess::planKey((string) $from->name);
        $claveDestino = MembershipAccess::planKey((string) $to->name);
        $choques = Plan::query()->get(['id', 'name'])
            ->filter(fn (Plan $p): bool => in_array(MembershipAccess::planKey((string) $p->name), [$claveOrigen, $claveDestino], true));

        if ($claveOrigen === $claveDestino || $choques->count() > 2) {
            throw PlanTransferException::ambiguousName($choques->pluck('name')->sort()->values()->all());
        }
    }

    /**
     * Quiénes están hoy en el plan origen, cada uno con lo que impediría o
     * complicaría trasladarlo. Para elegir, no para confirmar: la confirmación
     * sale de {@see simulate()}.
     *
     * @return array<string, mixed>
     */
    public function candidates(Plan $from, Plan $to, ?CarbonImmutable $now = null): array
    {
        $this->assertPlans($from, $to);
        $ahora = $this->now($now);

        $consulta = User::query()->whereIn('plan', $this->originNames($from));
        $total = (clone $consulta)->count();
        $users = $consulta->orderBy('name')->orderBy('id')->limit(self::MAX_CANDIDATES)->get();

        $analisis = $this->analyze($users, $from, $to, $ahora);

        $miembros = [];
        foreach ($users as $user) {
            $fila = $analisis[$user->id];
            $miembros[] = [
                'user_id' => (int) $user->id,
                'name' => $user->name,
                // Tapado: para elegir basta con distinguir a dos personas que se
                // llaman igual; el documento entero no sale del servidor.
                'document' => DocumentMask::mask($user->document),
                'plan' => $user->plan,
                'membership_start_date' => $user->membershipStartDate,
                'membership_end_date' => $user->membershipEndDate,
                'days_left' => $fila['before']['days_left'],
                'situation' => $fila['situation'],
                'eligible' => $fila['blockers'] === [],
                'blockers' => $fila['blockers'],
                'warning_codes' => array_column($fila['warnings'], 'code'),
                // Lo vencido no se preselecciona: trasladarlo es una decisión
                // que hay que tomar mirándolo, no por defecto.
                'preselect' => $fila['blockers'] === [] && $fila['situation'] !== 'expired',
            ];
        }

        return [
            'from_plan' => ['id' => (int) $from->id, 'name' => $from->name],
            'to_plan' => ['id' => (int) $to->id, 'name' => $to->name],
            'today' => $ahora->toDateString(),
            'total' => $total,
            'truncated' => $total > count($miembros),
            'members' => $miembros,
        ];
    }

    /**
     * La simulación completa del traslado de ESTOS socios. No escribe nada.
     *
     * `$locked` son los socios ya bloqueados por la ejecución: la segunda
     * simulación se hace sobre ellos, no sobre una lectura nueva.
     *
     * @param  list<int>  $userIds
     * @param  Collection<int, User>|null  $locked
     * @return array<string, mixed>
     */
    public function simulate(Plan $from, Plan $to, array $userIds, ?Collection $locked = null, ?CarbonImmutable $now = null): array
    {
        $this->assertPlans($from, $to);
        $ahora = $this->now($now);
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        $users = ($locked ?? User::query()->whereKey($ids)->orderBy('id')->get())
            ->filter(fn (User $u): bool => in_array((int) $u->id, $ids, true))
            ->keyBy('id');
        $analisis = $this->analyze($users->values(), $from, $to, $ahora);

        $desde = $this->conditions->of($from);
        $hasta = $this->conditions->of($to);
        $diferencias = $this->conditions->diff($desde, $hasta);
        $avisosPlan = $this->conditions->warnings($desde, $hasta, $diferencias);

        $items = [];
        foreach ($ids as $id) {
            $user = $users->get($id);
            if ($user === null) {
                $items[] = [
                    'user_id' => $id,
                    'name' => null,
                    'document' => null,
                    'eligible' => false,
                    'situation' => null,
                    'before' => null,
                    'after' => null,
                    'blockers' => [$this->blocker(self::BLOCK_NOT_FOUND, 'No existe un socio con ese número.')],
                    'warnings' => [],
                ];

                continue;
            }

            $fila = $analisis[$id];
            // De quien no está en el plan origen no se enseña nada de su
            // membresía: no se le va a tocar, y la simulación no es una forma
            // de consultar el plan de cualquier socio por su número.
            $ajeno = ($fila['blockers'][0]['code'] ?? null) === self::BLOCK_PLAN_MISMATCH;
            $items[] = [
                'user_id' => $id,
                'name' => $user->name,
                'document' => DocumentMask::mask($user->document),
                'eligible' => $fila['blockers'] === [],
                'situation' => $ajeno ? null : $fila['situation'],
                'before' => $ajeno ? null : $fila['before'],
                'after' => $ajeno ? null : $fila['after'],
                'blockers' => $fila['blockers'],
                'warnings' => $fila['warnings'],
            ];
        }

        $elegibles = array_values(array_filter($items, fn (array $i): bool => $i['eligible']));
        $conAvisos = array_values(array_filter($elegibles, fn (array $i): bool => self::hasWarnings($i['warnings'])));
        $avisosPlanFuertes = self::hasWarnings($avisosPlan);

        return [
            'fingerprint' => self::fingerprint([
                'today' => $ahora->toDateString(),
                'from' => $desde,
                'to' => $hasta,
                'items' => $this->fingerprintItems($items, 'user_id'),
                ...$this->watermarks(array_keys($users->all())),
            ]),
            'today' => $ahora->toDateString(),
            'from_plan' => $desde,
            'to_plan' => $hasta,
            'differences' => $diferencias,
            'plan_warnings' => $avisosPlan,
            'summary' => [
                'requested' => count($items),
                'eligible' => count($elegibles),
                'blocked' => count($items) - count($elegibles),
                'with_warnings' => count($conAvisos),
            ],
            // Solo hay algo que confirmar si hay algo que ejecutar.
            'requires_confirmation' => $elegibles !== [] && ($avisosPlanFuertes || $conAvisos !== []),
            'items' => $items,
        ];
    }

    /**
     * Cómo queda un grupo de socios si su `users.plan` pasa a `$targetName`,
     * con los avisos que eso merece. Lo usan el traslado y la reversión: la
     * reversión es un traslado hacia el texto que había antes.
     *
     * @param  Collection<int, User>  $users
     * @param  int|null  $leavingPlanId  plan del que salen, para los hechos que dependen de él
     * @param  string|null  $exactName  nombre exacto del plan del que salen, para avisar de variantes
     * @return array<int, array{before: array<string, mixed>, after: array<string, mixed>, warnings: list<array<string, string>>, situation: string, after_entries: array<string, mixed>|null}>
     */
    public function project(
        Collection $users,
        string $targetName,
        string $targetLabel,
        ?int $leavingPlanId,
        ?string $leavingLabel,
        ?string $exactName,
        CarbonImmutable $ahora,
    ): array {
        $this->memoFeatures = [];
        $this->memoAi = [];

        if ($users->isEmpty()) {
            return [];
        }

        $hoy = $ahora->startOfDay();
        $hechos = $this->facts($users, $leavingPlanId, $hoy);

        // La misma instancia para el antes y el después: comparten la lectura
        // del catálogo, así que las dos fotos son del mismo instante.
        $acceso = app(MembershipAccess::class);
        $antes = $acceso->statesFor($users, $ahora);
        $clones = $users->map(function (User $user) use ($targetName): User {
            // Un CLON en memoria. Nunca se guarda: solo existe para preguntarle
            // a las clases de producción cómo verían a este socio con el otro
            // plan. Las fechas, el estado y el congelamiento son los suyos.
            $clon = clone $user;
            $clon->setAttribute('plan', $targetName);

            return $clon;
        });
        $despues = $acceso->statesFor($clones, $ahora);

        $resultado = [];
        foreach ($users as $indice => $user) {
            $clon = $clones[$indice];
            $id = (int) $user->id;
            $ultimoPago = $hechos['last_payment'][$id] ?? null;

            $fotoAntes = $this->snapshot($user, $antes[$id], $ultimoPago, $hoy);
            $fotoDespues = $this->snapshot($clon, $despues[$id], $ultimoPago, $hoy);

            $resultado[$id] = [
                'before' => $fotoAntes,
                'after' => $fotoDespues,
                'warnings' => $this->warnings(
                    $user, $fotoAntes, $fotoDespues, $hechos, $targetLabel, $leavingLabel, $exactName,
                    // El acceso de empleado lo resuelve la puerta, no esto.
                    $antes[$id]['employee'] !== null,
                    $hoy,
                ),
                'situation' => $this->situation($user, $hoy),
                'after_entries' => $despues[$id]['entries'],
            ];
        }

        return $resultado;
    }

    /**
     * Huella de una simulación: sha256 de su JSON CANÓNICO —claves ordenadas,
     * importes como texto, fechas Y-m-d— para que la misma situación dé la
     * misma huella en PostgreSQL y en SQLite, y en cualquier orden de lectura.
     *
     * @param  array<string, mixed>  $partes
     */
    public static function fingerprint(array $partes): string
    {
        return hash('sha256', json_encode(
            self::canonical($partes),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Lo que de verdad cambia la decisión, por socio. Fuera quedan
     * `can_enter_now` y `reason`: dependen del MINUTO (una franja horaria que
     * abre a las 10:00) y no de los datos, y una huella que caduca con el reloj
     * pediría reconfirmar sin que nada hubiera cambiado. Los datos de los que
     * salen —fechas, reglas, entradas— sí están.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function fingerprintItems(array $items, string $key): array
    {
        $filas = array_map(fn (array $i): array => [
            $key => $i[$key],
            'user_id' => $i['user_id'] ?? null,
            'before' => $this->stable($i['before'] ?? null),
            'after' => $this->stable($i['after'] ?? null),
            'blockers' => array_column($i['blockers'], 'code'),
            'warnings' => array_column($i['warnings'], 'code'),
        ], $items);
        usort($filas, fn (array $a, array $b): int => $a[$key] <=> $b[$key]);

        return $filas;
    }

    /**
     * El último cobro y el último ajuste de estos socios, en consultas aparte
     * y SIN bloqueo: PostgreSQL no admite `FOR UPDATE` con agregados, y no
     * hace falta, porque los socios ya están bloqueados cuando importa.
     *
     * @param  list<int>  $userIds
     * @return array{last_payment_id: int|null, last_adjustment_id: int|null}
     */
    public function watermarks(array $userIds): array
    {
        if ($userIds === []) {
            return ['last_payment_id' => null, 'last_adjustment_id' => null];
        }

        $pago = Payment::query()->whereIn('user_id', $userIds)->max('id');
        $ajuste = MembershipAdjustment::query()->whereIn('user_id', $userIds)->max('id');

        return [
            'last_payment_id' => $pago !== null ? (int) $pago : null,
            'last_adjustment_id' => $ajuste !== null ? (int) $ajuste : null,
        ];
    }

    /** @param  list<array{severity: string}>  $avisos */
    public static function hasWarnings(array $avisos): bool
    {
        foreach ($avisos as $aviso) {
            if (($aviso['severity'] ?? null) === 'warning') {
                return true;
            }
        }

        return false;
    }

    /** @return array{code: string, message: string} */
    public function blocker(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    public function now(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now(MembershipPeriod::TZ))->setTimezone(MembershipPeriod::TZ);
    }

    // ────────────────────────────────────────────────────────────────────────
    //  El traslado: bloqueos propios y avisos
    // ────────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array{before: array<string, mixed>, after: array<string, mixed>, blockers: list<array<string, string>>, warnings: list<array<string, string>>, situation: string}>
     */
    private function analyze(Collection $users, Plan $from, Plan $to, CarbonImmutable $ahora): array
    {
        $proyeccion = $this->project($users, (string) $to->name, (string) $to->name, (int) $from->id, (string) $from->name, (string) $from->name, $ahora);
        $hechos = $this->blockingFacts($users, (int) $from->id);
        $claveOrigen = MembershipAccess::planKey((string) $from->name);

        $resultado = [];
        foreach ($users as $user) {
            $id = (int) $user->id;
            $p = $proyeccion[$id];
            $bloqueos = [];

            if ($user->plan === $to->name) {
                $bloqueos[] = $this->blocker(self::BLOCK_ALREADY_THERE, 'Ya está en «'.$to->name.'».');
            } elseif (MembershipAccess::planKey((string) ($user->plan ?? '')) !== $claveOrigen) {
                // Sin nombrar el plan que sí tiene: eso no es asunto del traslado.
                $bloqueos[] = $this->blocker(self::BLOCK_PLAN_MISMATCH, 'No está en «'.$from->name.'».');
            } else {
                if ($user->membershipEndDate === null) {
                    $bloqueos[] = $this->blocker(self::BLOCK_NO_MEMBERSHIP,
                        'No tiene una membresía registrada: no hay nada que trasladar.');
                }

                // La MISMA condición con la que la puerta niega el paso por
                // entradas (MembershipAccess::decideMembership), leída sobre el
                // clon: si con el plan destino ya no le quedan, el traslado lo
                // dejaría fuera del gimnasio.
                $entradas = $p['after_entries'];
                if ($entradas !== null && $entradas['left'] <= 0 && ! $entradas['used_today']) {
                    $bloqueos[] = $this->blocker(self::BLOCK_ENTRIES_EXHAUSTED,
                        'Ya usó '.$entradas['used'].' entradas en este periodo y «'.$to->name.'» trae '.
                        $entradas['total'].': se quedaría sin poder entrar.');
                }
                if (isset($hechos['pending'][$id])) {
                    $bloqueos[] = $this->blocker(self::BLOCK_PENDING_PAYMENT,
                        'Tiene un cobro sin confirmar de «'.$from->name.'». Resuélvelo antes: al confirmarse lo devolvería a ese plan.');
                }
                if (isset($hechos['subscription'][$id])) {
                    $bloqueos[] = $this->blocker(self::BLOCK_LIVE_SUBSCRIPTION,
                        'Tiene una suscripción automática de «'.$from->name.'»: el próximo cobro lo devolvería a ese plan. Cancélala antes.');
                }
            }

            $esDelOrigen = ! in_array($bloqueos[0]['code'] ?? null, [self::BLOCK_ALREADY_THERE, self::BLOCK_PLAN_MISMATCH], true);

            $resultado[$id] = [
                'before' => $p['before'],
                'after' => $p['after'],
                'blockers' => $bloqueos,
                // A quien no es del plan origen no se le avisa de nada: no se
                // le va a tocar.
                'warnings' => $esDelOrigen ? $p['warnings'] : [],
                'situation' => $p['situation'],
            ];
        }

        return $resultado;
    }

    /**
     * Los nombres con que `users.plan` escribe hoy el plan origen. El campo es
     * texto libre heredado y el mismo plan aparece de varias maneras; se
     * comparan en PHP con la misma normalización que usa la puerta, y la
     * consulta va con la lista exacta (un LOWER() en SQL no quita tildes en
     * PostgreSQL).
     *
     * @return list<string>
     */
    private function originNames(Plan $from): array
    {
        $clave = MembershipAccess::planKey((string) $from->name);

        return User::query()
            ->whereNotNull('plan')
            ->distinct()
            ->pluck('plan')
            ->filter(fn (?string $nombre): bool => $nombre !== null && MembershipAccess::planKey($nombre) === $clave)
            ->values()
            ->all();
    }

    /**
     * Lo que IMPIDE trasladar y depende del plan origen: un cobro que, al
     * confirmarse, lo devolvería a ese plan, y una suscripción automática que
     * lo cobraría de nuevo.
     *
     * Sin `$planId`, de CUALQUIER plan: es la pregunta de la transferencia de
     * membresía (MembershipTransfers), que reutiliza estos mismos hechos. El
     * traslado siempre pasa su plan y obtiene exactamente lo de antes.
     *
     * @param  Collection<int, User>  $users
     * @return array{pending: array<int, true>, subscription: array<int, true>}
     */
    public function blockingFacts(Collection $users, ?int $planId): array
    {
        $ids = $users->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $miembros = Member::query()->whereIn('user_id', $ids)->pluck('user_id', 'id');
        $socioDe = fn ($fila): ?int => $fila->user_id !== null
            ? (int) $fila->user_id
            : (isset($miembros[$fila->member_id]) ? (int) $miembros[$fila->member_id] : null);

        $pendientes = [];
        Payment::query()
            ->whereIn('user_id', $ids)
            ->when($planId !== null, fn ($q) => $q->where('plan_id', $planId), fn ($q) => $q->whereNotNull('plan_id'))
            ->whereRaw('LOWER(status) IN ('.implode(',', array_fill(0, count(PaymentController::PENDING_STATUSES), '?')).')',
                PaymentController::PENDING_STATUSES)
            ->pluck('user_id')
            ->each(function ($id) use (&$pendientes): void {
                $pendientes[(int) $id] = true;
            });

        // Un pago de pasarela en vuelo es el mismo riesgo: si se aprueba, el
        // activador de membresía aplica el plan que pagó.
        PaymentTransaction::query()
            ->when($planId !== null, fn ($q) => $q->where('plan_id', $planId), fn ($q) => $q->whereNotNull('plan_id'))
            ->whereIn('status', PaymentStateMachine::IN_FLIGHT)
            ->where(fn ($q) => $q->whereIn('user_id', $ids)->orWhereIn('member_id', $miembros->keys()->all()))
            ->get(['user_id', 'member_id'])
            ->each(function ($t) use (&$pendientes, $socioDe): void {
                if (($id = $socioDe($t)) !== null) {
                    $pendientes[$id] = true;
                }
            });

        $suscripciones = [];
        MembershipSubscription::query()
            ->when($planId !== null, fn ($q) => $q->where('plan_id', $planId))
            ->whereIn('status', MembershipSubscription::LIVE_STATUSES)
            ->where(fn ($q) => $q->whereIn('user_id', $ids)->orWhereIn('member_id', $miembros->keys()->all()))
            ->get(['user_id', 'member_id'])
            ->each(function ($s) use (&$suscripciones, $socioDe): void {
                if (($id = $socioDe($s)) !== null) {
                    $suscripciones[$id] = true;
                }
            });

        return ['pending' => $pendientes, 'subscription' => $suscripciones];
    }

    // ────────────────────────────────────────────────────────────────────────
    //  La foto de un socio y sus avisos
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Cómo está (o estaría) un socio: lo que lee la puerta, lo que ve en la
     * app y si tiene IRON IA. Las fechas salen del propio socio, y en el clon
     * son las mismas: el traslado no las toca.
     *
     * `last_payment_id` es la marca de agua de la reversión: un cobro con id
     * mayor llegó después del traslado.
     *
     * @param  array<string, mixed>  $estado  respuesta de MembershipAccess
     * @return array<string, mixed>
     */
    private function snapshot(User $user, array $estado, ?int $ultimoPago, CarbonImmutable $hoy): array
    {
        $reglas = $estado['rules'];
        $entradas = $estado['entries'];

        return [
            'plan' => $user->plan,
            'plan_id' => isset($estado['plan']['id']) ? (int) $estado['plan']['id'] : null,
            'membership_start_date' => $user->membershipStartDate,
            'membership_end_date' => $user->membershipEndDate,
            'days_left' => $this->daysLeft($user, $hoy),
            'status' => $user->status,
            'frozen' => $user->membership_frozen_at !== null,
            'access' => [
                'mode' => $reglas['mode'],
                'entries' => $entradas['total'] ?? null,
                'used' => $entradas['used'] ?? null,
                'left' => $entradas['left'] ?? null,
                'days_label' => $reglas['days_label'],
                'hours_label' => $reglas['hours_label'],
                'can_enter_now' => (bool) $estado['can_enter'],
                'reason' => $estado['reason'],
            ],
            'features' => $this->features($user),
            'ai_active' => $this->aiActive($user),
            'last_payment_id' => $ultimoPago,
        ];
    }

    /**
     * Lo que hay que saber de cada socio además de su ficha, de una consulta
     * por tipo para todo el grupo.
     *
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    private function facts(Collection $users, ?int $planId, CarbonImmutable $hoy): array
    {
        $ids = $users->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $miembros = Member::query()->whereIn('user_id', $ids)->get(['id', 'user_id'])->keyBy('user_id');
        $pagados = fn () => Payment::query()
            ->whereIn('user_id', $ids)
            ->whereRaw('LOWER(status) IN ('.implode(',', array_fill(0, count(PaymentController::PAID_STATUSES), '?')).')',
                PaymentController::PAID_STATUSES);

        $ultimoPago = Payment::query()
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(id) as last_id')
            ->pluck('last_id', 'user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $prepagados = [];
        $mejoras = [];
        if ($planId !== null) {
            $prepagados = array_fill_keys($pagados()->where('plan_id', $planId)
                ->whereDate('period_start', '>', $hoy->toDateString())
                ->pluck('user_id')->map(fn ($id): int => (int) $id)->all(), true);
            $mejoras = array_fill_keys($pagados()->where('plan_id', $planId)
                ->whereNotNull('upgraded_from_plan_id')
                ->pluck('user_id')->map(fn ($id): int => (int) $id)->all(), true);
        }

        $idsMiembro = $miembros->pluck('id')->all();

        // Deudas de GIMNASIO vivas: las mismas que pesan en la mora
        // (MembershipFinancialStanding), sin exigir todavía que estén vencidas.
        $conDeuda = Receivable::query()
            ->whereIn('member_id', $idsMiembro)
            ->where('debtor_type', DebtorType::MEMBER->value)
            ->ofType(CashShiftType::GYM)
            ->outstanding()
            ->pluck('member_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();

        // La mora la decide su dueño, y solo se le pregunta por quien debe
        // algo: el resto no puede estar en mora.
        $enMora = [];
        foreach ($miembros as $miembro) {
            if (in_array((int) $miembro->id, $conDeuda, true) && $this->standing->summary($miembro)['overdue']) {
                $enMora[(int) $miembro->id] = true;
            }
        }

        $reservas = ClassReservation::query()
            ->whereIn('member_id', $idsMiembro)
            ->whereDate('session_date', '>=', $hoy->toDateString())
            ->selectRaw('member_id, COUNT(*) as total')
            ->groupBy('member_id')
            ->pluck('total', 'member_id')
            ->map(fn ($n): int => (int) $n)
            ->all();

        return [
            'members' => $miembros,
            'last_payment' => $ultimoPago,
            'prepaid' => $prepagados,
            'upgrade' => $mejoras,
            'receivable' => array_fill_keys($conDeuda, true),
            'overdue' => $enMora,
            'reservations' => $reservas,
        ];
    }

    /**
     * Avisos de un socio. Los `warning` obligan a confirmar; los `info` no.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @param  array<string, mixed>  $hechos
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function warnings(
        User $user,
        array $antes,
        array $despues,
        array $hechos,
        string $destino,
        ?string $origen,
        ?string $nombreExacto,
        bool $esEmpleado,
        CarbonImmutable $hoy,
    ): array {
        $avisos = [];
        $add = function (string $code, string $message) use (&$avisos): void {
            $avisos[] = [
                'code' => $code,
                'severity' => in_array($code, self::INFO_WARNINGS, true) ? 'info' : 'warning',
                'message' => $message,
            ];
        };
        $id = (int) $user->id;
        $miembro = $hechos['members']->get($id);
        $inicio = $this->day($user->membershipStartDate);
        $fin = $this->day($user->membershipEndDate);
        $congelado = $user->membership_frozen_at !== null;
        $deOrigen = $origen !== null ? '«'.$origen.'»' : 'el plan anterior';

        if ($inicio !== null && $inicio->greaterThan($hoy)) {
            $add('future_start', 'Su membresía empieza el '.$inicio->toDateString().
                '. El cambio aplica desde ya y esa fecha no se mueve.');
        }
        if (isset($hechos['prepaid'][$id])) {
            $add('prepaid_origin_period', 'Tiene un periodo futuro ya pagado con '.$deOrigen.
                '. El pago no se toca: ese periodo lo usará con «'.$destino.'».');
        }
        if (! $congelado && $fin !== null && $fin->lessThan($hoy)) {
            $add('expired', 'Su membresía venció el '.$fin->toDateString().
                '. Se cambia igual, sin tocar fechas: al renovar, el mostrador verá «'.$destino.'».');
        }
        if ($congelado) {
            $add('frozen', 'Su membresía está congelada. El congelamiento no se toca: al reanudar recibe sus '.
                (int) ($user->membership_frozen_days_left ?? 0).' días con «'.$destino.'».');
        }
        if (! $congelado && strtolower((string) $user->status) !== 'active') {
            $add('account_inactive', 'Su cuenta está «'.($user->status ?? 'sin estado').'». El cambio no la activa ni la desactiva.');
        }

        $a = $antes['access'];
        $b = $despues['access'];
        if ($b['mode'] === PlanAccessRules::MODE_ENTRIES) {
            $add('entries_counted', 'Le quedarán '.$b['left'].' de '.$b['entries'].' entradas: las '.$b['used'].
                ' que ya usó en este periodo cuentan contra «'.$destino.'».');
        } elseif ($a['mode'] === PlanAccessRules::MODE_ENTRIES) {
            $add('stops_counting_entries', 'Sus entradas dejan de contarse con «'.$destino.'» (lleva '.$a['used'].' de '.
                $a['entries'].'). Si se revierte, vuelven a contar las de este periodo.');
        }
        if ($b['days_label'] !== null && $b['days_label'] !== $a['days_label']) {
            $add('dest_restricts_days', 'Con «'.$destino.'» solo podrá entrar '.$b['days_label'].'.');
        }
        if ($b['hours_label'] !== null && $b['hours_label'] !== $a['hours_label']) {
            $add('dest_restricts_hours', 'Con «'.$destino.'» solo podrá entrar de '.$b['hours_label'].'.');
        }

        $reservas = $miembro ? ($hechos['reservations'][(int) $miembro->id] ?? 0) : 0;
        if ($reservas > 0 && ! empty($antes['features']['classes']) && empty($despues['features']['classes'])) {
            $add('classes_lost_with_reservations', 'Tiene '.$reservas.' '.($reservas === 1 ? 'reserva' : 'reservas').
                ' de clase por delante y «'.$destino.'» no incluye clases. No se cancelan; no podrá hacer reservas nuevas.');
        }
        if (isset($hechos['upgrade'][$id])) {
            $add('upgrade_on_record', 'Tiene una mejora de plan cobrada hacia '.$deOrigen.
                '. Si alguien anula esa mejora, su plan volverá al de antes de la mejora.');
        }
        if ($miembro && isset($hechos['receivable'][(int) $miembro->id])) {
            $add('open_receivable', 'Tiene una cuenta por cobrar abierta (su concepto conserva el nombre del plan con que se vendió). No se toca.');
        }
        if ($miembro && isset($hechos['overdue'][(int) $miembro->id])) {
            $add('benefits_withheld', 'Está en mora: tiene los beneficios de la app retenidos hasta que pague. El cambio no lo altera.');
        }
        if ($user->membership_cancellation_requested_at !== null) {
            $add('cancellation_requested', 'Pidió cancelar su membresía. La solicitud se mantiene.');
        }
        if ($nombreExacto !== null && $user->plan !== $nombreExacto) {
            $add('plan_name_variant', 'Su plan está escrito «'.$user->plan.'». Quedará como «'.$destino.
                '»; si se revierte, vuelve a «'.$user->plan.'» tal cual.');
        }
        if ($esEmpleado) {
            $add('employee_access', 'Tiene acceso de empleado: sigue entrando por esa puerta pase lo que pase con su plan.');
        }
        if ($miembro === null) {
            $add('no_app_account', 'No tiene cuenta en la app: no hay teléfono al que avisar del cambio.');
        }

        return $avisos;
    }

    /** active | future | expired | frozen | no_membership, para filtrar la lista. */
    private function situation(User $user, CarbonImmutable $hoy): string
    {
        if ($user->membership_frozen_at !== null) {
            return 'frozen';
        }

        $inicio = $this->day($user->membershipStartDate);
        $fin = $this->day($user->membershipEndDate);

        if ($fin === null) {
            return 'no_membership';
        }
        if ($inicio !== null && $inicio->greaterThan($hoy)) {
            return 'future';
        }

        return $fin->lessThan($hoy) ? 'expired' : 'active';
    }

    /** Días que le quedan. Congelado: los que tiene guardados. */
    private function daysLeft(User $user, CarbonImmutable $hoy): ?int
    {
        if ($user->membership_frozen_at !== null) {
            return (int) ($user->membership_frozen_days_left ?? 0);
        }

        $fin = $this->day($user->membershipEndDate);

        return $fin === null ? null : max(0, (int) $hoy->diffInDays($fin, false));
    }

    /**
     * Las funciones de la app, como las calcula la app. Se memoriza por
     * (plan, vencimiento), que es todo lo que mira {@see MemberPayload::featuresFor()}.
     *
     * @return array<string, mixed>
     */
    private function features(User $user): array
    {
        $clave = ($user->plan ?? '')."\0".($user->membershipEndDate ?? '');

        if (! array_key_exists($clave, $this->memoFeatures)) {
            $funciones = MemberPayload::featuresFor($user);
            ksort($funciones);
            $this->memoFeatures[$clave] = $funciones;
        }

        return $this->memoFeatures[$clave];
    }

    /**
     * ¿Puede chatear con IRON IA por su membresía? Las dos condiciones que
     * mira la app antes que la cuota: membresía activa y chat encendido para
     * su plan. Memorizado por (plan, vencimiento), que es lo único que leen
     * getCurrentMembership() y getAiCapabilities().
     */
    private function aiActive(User $user): bool
    {
        $clave = ($user->plan ?? '')."\0".($user->membershipEndDate ?? '');

        if (! array_key_exists($clave, $this->memoAi)) {
            $membresia = $this->ai->getCurrentMembership(null, $user);
            $capacidades = $this->ai->getAiCapabilities($membresia);
            $this->memoAi[$clave] = (bool) $membresia['active']
                && (bool) ($capacidades['ai_enabled'] ?? true)
                && (bool) ($capacidades['ai_chat_enabled'] ?? true);
        }

        return $this->memoAi[$clave];
    }

    /**
     * La foto sin lo que depende del minuto. Ver {@see fingerprintItems()}.
     *
     * @param  array<string, mixed>|null  $foto
     * @return array<string, mixed>|null
     */
    private function stable(?array $foto): ?array
    {
        if ($foto === null) {
            return null;
        }

        unset($foto['access']['can_enter_now'], $foto['access']['reason']);

        return $foto;
    }

    /** Claves ordenadas en los objetos; las listas, en su orden. */
    private static function canonical(mixed $valor): mixed
    {
        if (is_float($valor)) {
            // Un float no viaja igual en todos los motores: se fija su texto.
            return rtrim(rtrim(sprintf('%.6F', $valor), '0'), '.');
        }
        if (! is_array($valor)) {
            return $valor;
        }

        $valor = array_map(self::canonical(...), $valor);
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }

    private function day(?string $fecha): ?CarbonImmutable
    {
        return $fecha ? CarbonImmutable::parse(substr($fecha, 0, 10), MembershipPeriod::TZ)->startOfDay() : null;
    }
}
