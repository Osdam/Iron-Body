<?php

namespace App\Services\Classes;

use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Services\Caja\MembershipFinancialStanding;
use App\Services\Moderation\SessionEnforcer;
use App\Support\Access\TrainerMemberScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El dominio de reservas de clases: UNA sola fuente para la app y para el CRM.
 *
 * DE DÓNDE SALE. Hasta ahora la reserva vivía repartida. El núcleo —bloqueo,
 * anti-doble y cupo— era un método protegido de un trait de controlador
 * (`MemberClassContext::reserveOccurrence`), y todo lo demás estaba COPIADO en
 * `ClassController` y en `AppClassController`: las comprobaciones previas, el
 * borrado de la cancelación y los avisos en tiempo real. Las copias ya habían
 * empezado a divergir. Un CRM que «reutilizara la lógica de la app» habría sido
 * la cuarta variante.
 *
 * QUÉ HAY AQUÍ, Y QUÉ NO.
 *
 *   · Aquí: las REGLAS (quién puede reservar qué, y cuándo), el NÚCLEO
 *     transaccional y los AVISOS. Todo lo que decide si una reserva existe.
 *   · En los controladores: la traducción a HTTP. Cada puerta conserva sus
 *     mensajes y su forma de respuesta, porque la app publicada los muestra
 *     literalmente y no se puede romper su contrato.
 *
 * LOS DOS CANALES. La app y el mostrador aplican las MISMAS reglas con los
 * MISMOS predicados, pero no las comprueban en el mismo sitio:
 *
 *   · App (socio): la cuenta bloqueada y la deuda vencida las corta la PUERTA,
 *     con `auth.member` y `membership.benefits`, antes de llegar aquí.
 *   · CRM (personal): esas puertas no existen —no hay socio autenticado, y
 *     `membership.benefits` deja pasar cuando falta—, así que las comprueba el
 *     servicio llamando a las mismas funciones: `Member::isSuspended()`,
 *     `SessionEnforcer::blockFor()` y `MembershipFinancialStanding::isOverdue()`.
 *     Sin esto, recepción habría inscrito en silencio a quien la app retiene
 *     por deuda o tiene la cuenta cerrada.
 *
 * La ÚNICA diferencia deliberada entre canales es `allow_online_booking`: esa
 * bandera dice si el socio puede reservar POR SU CUENTA desde la app. Inscribir
 * en el mostrador es precisamente la reserva que no es online.
 */
class ClassBookingService
{
    // ── Resultado del núcleo ──────────────────────────────────────────────
    public const RESERVED = 'reserved';

    public const ALREADY = 'already';

    public const FULL = 'full';

    /** Baja hecha: la fila existía y se borró. */
    public const REMOVED = 'removed';

    // ── Canales ───────────────────────────────────────────────────────────
    public const CHANNEL_MEMBER = 'member';

    public const CHANNEL_STAFF = 'staff';

    // ── Motivos de rechazo (estables: cada puerta los traduce a su texto) ─
    public const NOT_IN_PLAN = 'classes_not_in_plan';

    public const CLASS_UNAVAILABLE = 'class_unavailable';

    public const ACCOUNT_BLOCKED = 'account_blocked';

    public const PAYMENT_OVERDUE = 'membership_payment_overdue';

    public const NOT_AN_OCCURRENCE = 'not_an_occurrence';

    public const OCCURRENCE_PAST = 'past';

    public const OCCURRENCE_CLOSED = 'closed';

    public const OCCURRENCE_LIVE = 'live';

    /** Zona operativa del gimnasio: el backend corre en UTC. */
    public const TZ = 'America/Bogota';

    public function __construct(
        private readonly MembershipFinancialStanding $standing,
        private readonly SessionEnforcer $moderation,
        private readonly ClassEventBus $events,
    ) {}

    // ── Reglas ────────────────────────────────────────────────────────────

    /**
     * ¿Puede este socio reservar esta clase por este canal? `null` = sí.
     *
     * El orden es el de la app: primero la persona, después su plan, después la
     * clase. Así el motivo que se enseña es el más útil para quien atiende.
     */
    public function ineligibility(Member $member, MyClass $class, string $channel): ?string
    {
        if ($channel === self::CHANNEL_STAFF) {
            // Lo mismo que corta `auth.member` en la app, con los mismos
            // predicados: cuenta eliminada, suspendida por seguridad o retirada
            // de la app por moderación.
            if ($member->status === Member::STATUS_DELETED
                || $member->isSuspended()
                || $this->moderation->blockFor((int) $member->getKey()) !== null) {
                return self::ACCOUNT_BLOCKED;
            }
            // Lo mismo que corta `membership.benefits` en la app.
            if ($this->standing->isOverdue($member)) {
                return self::PAYMENT_OVERDUE;
            }
        }

        if (! $this->hasClassesFeature($member)) {
            return self::NOT_IN_PLAN;
        }

        return $this->isOpenFor($class, $channel) ? null : self::CLASS_UNAVAILABLE;
    }

    /**
     * ¿La clase admite reservas por este canal?
     *
     * Para el socio, la regla es la del modelo (`isBookableByMembers`: activa y
     * con reserva online), que ya era la fuente única del catálogo de la app.
     * Para el mostrador basta con que esté activa: la reserva online es la del
     * socio por su cuenta, y inscribir en persona es justo la otra.
     */
    public function isOpenFor(MyClass $class, string $channel): bool
    {
        return $channel === self::CHANNEL_MEMBER
            ? $class->isBookableByMembers()
            : $class->status === 'active';
    }

    /**
     * ¿El plan del socio incluye clases, con la membresía vigente?
     *
     * Es la MISMA fuente que usa el cliente para bloquear la pestaña Clases
     * (`Member::resolvedFeatures()`): así backend y app no pueden divergir.
     */
    public function hasClassesFeature(Member $member): bool
    {
        return (bool) ($member->resolvedFeatures()['classes'] ?? false);
    }

    /**
     * ¿Esa fecha concreta admite reservas? `null` = sí.
     *
     * Son las reglas que hasta ahora solo aplicaba «Organizar mi semana», con su
     * misma precedencia: cerrada → en curso → vencida. Una clase en curso que ya
     * pasó su hora se etiqueta «en curso», no «vencida».
     */
    public function occurrenceBlock(MyClass $class, string $date): ?string
    {
        $session = ClassSession::where('class_id', $class->getKey())->whereDate('session_date', $date)->first();

        if ($bloqueo = $this->cancelBlock($session)) {
            return $bloqueo;
        }
        // Vencida por DÍA operativo, nunca por hora: una clase de las 20:00 se
        // puede reservar ese mismo día hasta que el entrenador la inicie.
        if ($date < $this->today()->toDateString()) {
            return self::OCCURRENCE_PAST;
        }

        return null;
    }

    /**
     * ¿Se puede cancelar respecto a esta sesión? `null` = sí.
     *
     * Quien llama decide QUÉ sesión es la relevante (la de una fecha explícita o
     * la vigente de hoy); la regla —no se cancela lo que el entrenador ya
     * inició— vive aquí una sola vez.
     */
    public function cancelBlock(?ClassSession $session): ?string
    {
        return match ($this->sessionStatus($session)) {
            'finished' => self::OCCURRENCE_CLOSED,
            'live' => self::OCCURRENCE_LIVE,
            default => null,
        };
    }

    /**
     * Estado OFICIAL de una sesión: 'finished' si el entrenador la cerró
     * (`ended_at`), 'live' si la inició y no la ha cerrado (`started_at`), o
     * 'scheduled' en cualquier otro caso. Lo controla el entrenador, NUNCA la
     * hora local. Fuente única: la usan Clases, el detalle, «Organizar mi
     * semana» y el mostrador.
     */
    public function sessionStatus(?ClassSession $session): string
    {
        if ($session?->ended_at) {
            return 'finished';
        }
        if ($session?->started_at) {
            return 'live';
        }

        return 'scheduled';
    }

    /**
     * ¿Es esa fecha una ocurrencia real de la clase?
     *
     * Sin esto se podía reservar una clase de los lunes para un miércoles: esa
     * fila consume cupo en un día en que la clase no existe. Se reutiliza el
     * cálculo del planificador semanal, que es el que ya decide qué días hay.
     */
    public function isOccurrence(MyClass $class, string $date): bool
    {
        $occ = $class->occurrenceDateTimeInWeek(Carbon::parse($date, self::TZ));

        return $occ !== null && $occ->toDateString() === $date;
    }

    /**
     * La fecha que se reserva cuando nadie dice otra: la próxima ocurrencia en
     * día operativo. FUENTE ÚNICA del `session_date`: la usan la reserva
     * individual de la app, el roster del entrenador y ahora el mostrador.
     */
    public function operationalDate(MyClass $class): string
    {
        return optional($class->operationalOccurrence(self::TZ))->toDateString()
            ?? $this->today()->toDateString();
    }

    /**
     * La ocurrencia que se está MIRANDO: la que pide el cliente si es un día
     * real de la clase; si no, la operativa.
     *
     * Existe por un fallo de producción: la app del entrenador pedía la lista
     * de HOY (fecha del dispositivo) y la reserva estaba en la PRÓXIMA
     * ocurrencia, así que una clase del lunes consultada un miércoles salía
     * «Sin inscritos» mientras la app de los socios contaba 1/20. Con esto,
     * pedir un día en que la clase no se dicta devuelve la misma ocurrencia
     * que cuentan la app y el CRM, y la respuesta dice cuál es.
     *
     * Solo se acepta `Y-m-d`: una fecha con hora o zona se recorta al día, sin
     * convertirla, porque es un DÍA del gimnasio y no un instante.
     */
    public function resolveSessionDate(MyClass $class, ?string $requested): string
    {
        $pedida = $this->plainDate($requested);

        if ($pedida !== null && $this->isOccurrence($class, $pedida)) {
            return $pedida;
        }

        return $this->operationalDate($class);
    }

    /** Hoy en el gimnasio (Bogotá), como `Y-m-d`. */
    public function todayDate(): string
    {
        return $this->today()->toDateString();
    }

    /**
     * Fecha mínima de una sesión que cuenta como EN CURSO para los socios: la
     * de hoy o la de ayer (una clase que pasa de la medianoche). Una sesión que
     * el entrenador olvidó cerrar hace días no deja la clase «en curso» para
     * siempre; él sí puede cerrarla cuando quiera.
     */
    public function liveSince(): string
    {
        return $this->today()->subDay()->toDateString();
    }

    /**
     * El instante en que empieza la ocurrencia de esa fecha. `null` si la clase
     * no tiene hora.
     *
     * La hora de una clase es de PARED, la del gimnasio. `nextOccurrence()` la
     * ponía sobre el reloj UTC del servidor: el recordatorio de una clase de
     * las 18:00 salía a las 10:00, y a quien había reservado cualquier semana.
     */
    public function occurrenceStart(MyClass $class, string $date): ?Carbon
    {
        $hora = preg_match('/^(\d{1,2}):(\d{2})/', (string) $class->start_time, $m)
            ? sprintf('%02d:%s', (int) $m[1], $m[2])
            : ($class->date_time ? Carbon::parse($class->date_time)->format('H:i') : null);

        return $hora === null ? null : Carbon::createFromFormat('Y-m-d H:i', "{$date} {$hora}", self::TZ)->startOfMinute();
    }

    /**
     * `Y-m-d` si el texto empieza por una fecha de calendario válida; si no, null.
     */
    public function plainDate(?string $valor): ?string
    {
        if ($valor === null || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /**
     * ¿Este socio ocupa esa ocurrencia? Con la MISMA condición que cuenta el
     * cupo: reserva de esa fecha o heredada sin fecha.
     */
    public function occupies(int $memberId, MyClass $class, string $date): bool
    {
        return $this->occurrenceQuery($class, $date)->where('member_id', $memberId)->exists();
    }

    /**
     * Los socios que ocupan una ocurrencia, sin repetir. Para avisar a quien
     * reservó ESA sesión, y no a cualquiera que alguna vez reservó la clase.
     *
     * @return list<int>
     */
    public function occurrenceMemberIds(MyClass $class, string $date): array
    {
        return $this->occurrenceQuery($class, $date)
            ->distinct()
            ->pluck('member_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Las próximas sesiones que ocupa un socio, por hora de inicio.
     *
     * Para contarle a él (o a su asistente) qué tiene reservado: la fecha de
     * CADA reserva —una heredada sin fecha, en la próxima ocurrencia de su
     * clase— a la hora de pared de la clase. Solo las que aún no han empezado,
     * de clases activas. Antes se tomaba la última reserva creada, aunque
     * fuera de una clase de hace un mes.
     *
     * @return list<array{class: MyClass, session_date: string, starts_at: Carbon}>
     */
    public function upcomingFor(int $memberId, int $limit = 3): array
    {
        $ahora = Carbon::now();

        $filas = ClassReservation::query()
            ->with('gymClass')
            ->where('member_id', $memberId)
            ->where(fn ($q) => $q->whereNull('session_date')->orWhereDate('session_date', '>=', $this->todayDate()))
            ->get();

        $sesiones = [];
        foreach ($filas as $r) {
            $clase = $r->gymClass;
            if (! $clase || $clase->status !== 'active') {
                continue;
            }
            $fecha = $r->session_date?->toDateString() ?? $this->operationalDate($clase);
            $inicio = $this->occurrenceStart($clase, $fecha);
            if ($inicio === null || $inicio->lt($ahora)) {
                continue;
            }
            $sesiones[$clase->getKey().'|'.$fecha] = ['class' => $clase, 'session_date' => $fecha, 'starts_at' => $inicio];
        }

        usort($sesiones, fn (array $a, array $b) => $a['starts_at'] <=> $b['starts_at']);

        return array_slice($sesiones, 0, max(0, $limit));
    }

    /**
     * Las próximas ocurrencias reservables, empezando por la operativa.
     *
     * No calcula fechas por su cuenta: parte de `operationalDate()` y se queda
     * con las semanas siguientes que `isOccurrence()` reconoce, que es el
     * cálculo del planificador semanal. Una clase de fecha única devuelve una
     * sola fecha.
     *
     * @return list<string>
     */
    public function upcomingOccurrences(MyClass $class, int $weeks = 4): array
    {
        $first = Carbon::parse($this->operationalDate($class), self::TZ);

        $dates = [];
        for ($i = 0; $i < $weeks; $i++) {
            $date = $first->copy()->addWeeks($i)->toDateString();
            if ($this->isOccurrence($class, $date)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    // ── Núcleo ────────────────────────────────────────────────────────────

    /**
     * Reserva una ocurrencia (clase + fecha). Devuelve reserved | already | full.
     *
     * CONCURRENCIA. Bloquea la FILA DE LA CLASE (`SELECT … FOR UPDATE`): todas
     * las reservas de esa clase, vengan de la app o del mostrador, se ponen en
     * fila. Dentro del bloqueo se vuelve a mirar si ya estaba y cuánto cupo
     * queda, así que dos peticiones simultáneas por el último sitio no pueden
     * pasar las dos. Es lo que impide un 21/20.
     *
     * El cupo se cuenta SIN `FOR UPDATE`: PostgreSQL prohíbe bloquear agregados
     * («FOR UPDATE is not allowed with aggregate functions») y no hace falta,
     * porque el bloqueo de la clase ya serializa a quien podría cambiarlo.
     *
     * IDEMPOTENCIA. Mismo socio + misma clase + misma fecha no puede dar dos
     * filas: se comprueba dentro del bloqueo y, además, lo garantiza el índice
     * único `(class_id, member_id, session_date)`. Si algún camino futuro se
     * saltara el bloqueo y chocara contra el índice, eso se trata como «ya
     * estaba» y no como un 500: el efecto que se pedía ya existe.
     *
     * `$enLaTransaccion` corre DENTRO de la misma transacción, justo después de
     * crear la fila, y solo si se creó. Es para la auditoría del mostrador: o
     * quedan las dos cosas o ninguna.
     *
     * EL HECHO. Cada reserva creada deja `booking.created` en el registro de
     * eventos en la misma transacción, y los avisos a la app y al entrenador
     * salen tras el commit ({@see ClassEventBus}). Es el ÚNICO sitio que crea
     * reservas, así que ningún camino puede olvidarse de avisar.
     *
     * @param  (callable(ClassReservation): void)|null  $enLaTransaccion
     */
    public function reserveOccurrence(
        Member $member,
        MyClass $class,
        string $date,
        ?callable $enLaTransaccion = null,
        string $source = ClassEventBus::SOURCE_APP,
    ): string {
        try {
            return DB::transaction(function () use ($member, $class, $date, $enLaTransaccion, $source): string {
                $locked = MyClass::whereKey($class->getKey())->lockForUpdate()->first() ?? $class;

                $already = $this->occurrenceQuery($class, $date)
                    ->where('member_id', $member->getKey())
                    ->exists();
                if ($already) {
                    return self::ALREADY;
                }

                $booked = $this->occurrenceQuery($class, $date)->count();
                if ($booked >= (int) $locked->max_capacity) {
                    return self::FULL;
                }

                $reservation = ClassReservation::create([
                    'class_id' => $class->getKey(),
                    'member_id' => $member->getKey(),
                    'session_date' => $date,
                    'reserved_at' => now(),
                ]);

                if ($enLaTransaccion !== null) {
                    $enLaTransaccion($reservation);
                }

                $this->events->booking(ClassEventBus::BOOKING_CREATED, $class, $date, $source, (int) $reservation->id);

                return self::RESERVED;
            });
        } catch (UniqueConstraintViolationException) {
            return self::ALREADY;
        }
    }

    /**
     * Borra la reserva de un socio en una ocurrencia. Devuelve cuántas filas.
     *
     * Con fecha explícita borra ESA ocurrencia y nada más. Sin ella, la fecha
     * operativa más las reservas heredadas sin fecha: es el comportamiento
     * histórico de la cancelación individual, que estaba copiado en dos
     * controladores.
     *
     * No hay estado «cancelada»: cancelar es un DELETE y el cupo se libera en
     * el acto, porque se cuenta en vivo.
     *
     * Cada fila borrada deja `booking.cancelled` en el registro de eventos,
     * en la misma transacción. Es el ÚNICO sitio que cancela reservas.
     *
     * @param  (callable(int): void)|null  $enLaTransaccion  recibe las filas borradas
     */
    public function cancelOccurrence(
        Member $member,
        MyClass $class,
        ?string $explicitDate,
        string $fallbackDate,
        ?callable $enLaTransaccion = null,
        string $source = ClassEventBus::SOURCE_APP,
    ): int {
        return DB::transaction(function () use ($member, $class, $explicitDate, $fallbackDate, $enLaTransaccion, $source): int {
            $query = ClassReservation::where('class_id', $class->getKey())
                ->where('member_id', $member->getKey());

            if ($explicitDate !== null) {
                $query->whereDate('session_date', $explicitDate);
            } else {
                $query->where(function ($q) use ($fallbackDate): void {
                    $q->whereNull('session_date')->orWhereDate('session_date', $fallbackDate);
                });
            }

            $filas = (clone $query)->get(['id', 'session_date']);
            $deleted = $query->delete();

            if ($deleted > 0 && $enLaTransaccion !== null) {
                $enLaTransaccion($deleted);
            }

            foreach ($filas as $fila) {
                $this->events->booking(
                    ClassEventBus::BOOKING_CANCELLED,
                    $class,
                    $fila->session_date?->toDateString() ?? $explicitDate ?? $fallbackDate,
                    $source,
                    (int) $fila->id,
                );
            }

            return $deleted;
        });
    }

    /** Cupo ocupado de una ocurrencia: reservas de esa fecha + heredadas sin fecha. */
    public function bookedCount(MyClass $class, string $date): int
    {
        return (int) $this->occurrenceQuery($class, $date)->count();
    }

    /**
     * Quién ocupa el cupo de una ocurrencia, en orden de llegada.
     *
     * Es la MISMA consulta que cuenta el cupo, así que la lista y el número no
     * pueden contar cosas distintas.
     *
     * @return Collection<int, ClassReservation>
     */
    public function occurrenceReservations(MyClass $class, string $date): Collection
    {
        return $this->occurrenceQuery($class, $date)
            ->with('member:id,user_id,full_name,document_number')
            ->orderBy('reserved_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Las filas que ocupan cupo en una ocurrencia: las de esa fecha y las
     * heredadas sin fecha (anteriores a que existiera `session_date`).
     *
     * Único sitio donde se escribe esa condición: la usan el anti-doble y el
     * cupo dentro del bloqueo, el contador y el listado del CRM.
     *
     * @return Builder<ClassReservation>
     */
    private function occurrenceQuery(MyClass $class, string $date): Builder
    {
        return ClassReservation::query()
            ->where('class_id', $class->getKey())
            ->where(function ($q) use ($date): void {
                $q->whereNull('session_date')->orWhereDate('session_date', $date);
            });
    }

    // ── La app del socio ──────────────────────────────────────────────────

    /**
     * Reserva desde la app la ocurrencia operativa, con las MISMAS reglas que
     * el mostrador: plan, clase abierta al canal, ocurrencia no vencida, no en
     * curso y no cerrada, cupo. Antes la reserva individual no miraba si la
     * sesión ya había empezado o terminado: la app ocultaba el botón, pero una
     * llamada directa reservaba igual.
     *
     * Cada puerta traduce el resultado a sus textos (la app publicada los
     * muestra literalmente).
     */
    public function reserveForMember(Member $member, MyClass $class): ClassBookingResult
    {
        $date = $this->operationalDate($class);

        if ($motivo = $this->ineligibility($member, $class, self::CHANNEL_MEMBER)) {
            return ClassBookingResult::rejected($motivo, $date);
        }
        if ($motivo = $this->occurrenceBlock($class, $date)) {
            return ClassBookingResult::rejected($motivo, $date);
        }

        $outcome = $this->reserveOccurrence($member, $class, $date, null, ClassEventBus::SOURCE_APP);

        return $outcome === self::RESERVED
            ? ClassBookingResult::done($outcome, $date)
            : ClassBookingResult::rejected($outcome, $date);
    }

    /**
     * Cancela desde la app. Con fecha explícita («Organizar mi semana»), esa
     * ocurrencia; sin ella, la operativa más la heredada sin fecha.
     *
     * La sesión que se mira es la de ESA fecha. Antes se miraba la sesión
     * «relevante» de la clase, que puede ser la de la semana pasada: una clase
     * finalizada el lunes impedía cancelar la reserva del lunes siguiente con
     * «La clase ya finalizó.».
     */
    public function cancelForMember(Member $member, MyClass $class, ?string $explicitDate): ClassBookingResult
    {
        $date = $explicitDate ?? $this->operationalDate($class);

        $session = ClassSession::where('class_id', $class->getKey())->whereDate('session_date', $date)->first();
        if ($motivo = $this->cancelBlock($session)) {
            return ClassBookingResult::rejected($motivo, $date);
        }

        // Lo que ocupa esa sesión: la fila de su fecha y la heredada sin fecha,
        // igual que al quitar desde el mostrador. Con solo la de la fecha, una
        // reserva heredada no se podía cancelar desde «Organizar mi semana».
        $deleted = $this->cancelOccurrence($member, $class, null, $date, null, ClassEventBus::SOURCE_APP);

        return $deleted > 0
            ? ClassBookingResult::done(self::REMOVED, $date)
            : ClassBookingResult::rejected(self::ALREADY, $date);
    }

    // ── Cambio de horario ─────────────────────────────────────────────────

    /**
     * La clase cambió de día (o de fecha): las reservas FUTURAS que ya no caen
     * en un día real de la clase se mueven a la ocurrencia de SU MISMA SEMANA.
     * Si esa ocurrencia ya pasó, no existe o no cabe, la reserva se cancela.
     * Las de una sesión que el entrenador ya inició o cerró (la de hoy) no se
     * tocan: esa clase ya se dictó en su día y la reserva es su registro.
     *
     * Antes quedaban huérfanas: con fecha de un día en que la clase ya no se
     * dicta, la app contaba 0/20, el entrenador veía «Sin inscritos» y el socio
     * creía tener plaza. Bajo el bloqueo de la clase, como cualquier reserva.
     *
     * @return array{moved: list<ClassReservation>, dropped: list<array{reservation: ClassReservation, date: string}>}
     */
    public function reconcileFutureReservations(MyClass $class): array
    {
        return DB::transaction(function () use ($class): array {
            MyClass::whereKey($class->getKey())->lockForUpdate()->first();
            $hoy = $this->todayDate();

            $filas = ClassReservation::query()
                ->where('class_id', $class->getKey())
                ->whereNotNull('session_date')
                ->whereDate('session_date', '>=', $hoy)
                ->orderBy('reserved_at')
                ->orderBy('id')
                ->get();

            $movidas = [];
            $canceladas = [];
            foreach ($filas as $reserva) {
                $vieja = $reserva->session_date->toDateString();
                if ($this->isOccurrence($class, $vieja)) {
                    continue;
                }
                // Moverla le daría al socio una reserva que no pidió en otro día,
                // y borrarla, perder quién estaba en la clase que ya se dictó.
                $sesion = ClassSession::where('class_id', $class->getKey())->whereDate('session_date', $vieja)->first();
                if ($this->cancelBlock($sesion) !== null) {
                    continue;
                }

                $nueva = optional($class->occurrenceDateTimeInWeek(Carbon::parse($vieja, self::TZ)))->toDateString();
                $cabe = $nueva !== null
                    && $nueva >= $hoy
                    && ! $this->occupies((int) $reserva->member_id, $class, $nueva)
                    && $this->bookedCount($class, $nueva) < (int) $class->max_capacity;

                if ($cabe) {
                    $reserva->update(['session_date' => $nueva]);
                    $this->events->booking(ClassEventBus::BOOKING_CANCELLED, $class, $vieja, ClassEventBus::SOURCE_CRM, (int) $reserva->id);
                    $this->events->booking(ClassEventBus::BOOKING_CREATED, $class, $nueva, ClassEventBus::SOURCE_CRM, (int) $reserva->id);
                    $movidas[] = $reserva;
                } else {
                    $reserva->delete();
                    $this->events->booking(ClassEventBus::BOOKING_CANCELLED, $class, $vieja, ClassEventBus::SOURCE_CRM, (int) $reserva->id);
                    $canceladas[] = ['reservation' => $reserva, 'date' => $vieja];
                }
            }

            return ['moved' => $movidas, 'dropped' => $canceladas];
        });
    }

    /**
     * Libera las reservas de un socio que TODAVÍA ocupan cupo (de hoy en
     * adelante y las heredadas sin fecha), cada una con su hecho para el CRM,
     * el entrenador y la app. Las pasadas se quedan: son el historial.
     *
     * La baja de una cuenta la anonimizaba sin tocar sus reservas: seguían
     * ocupando plazas y salían en las listas como «Cuenta eliminada».
     */
    public function releaseUpcomingFor(Member $member, string $source = ClassEventBus::SOURCE_SYSTEM): int
    {
        $filas = ClassReservation::query()
            ->with('gymClass')
            ->where('member_id', $member->getKey())
            ->where(fn ($q) => $q->whereNull('session_date')->orWhereDate('session_date', '>=', $this->todayDate()))
            ->get();

        foreach ($filas as $fila) {
            $fila->delete();
            if ($fila->gymClass !== null) {
                $fecha = $fila->session_date?->toDateString() ?? $this->operationalDate($fila->gymClass);
                $this->events->booking(ClassEventBus::BOOKING_CANCELLED, $fila->gymClass, $fecha, $source, (int) $fila->id);
            }
        }

        return $filas->count();
    }

    /**
     * ¿Ese aforo deja alguna sesión futura con más inscritos que plazas? El
     * texto del rechazo, o null si cabe. Cuenta como el cupo: las reservas de
     * cada fecha más las heredadas sin fecha, que ocupan todas las sesiones.
     */
    public function capacityConflict(MyClass $class, int $capacity): ?string
    {
        $classId = (int) $class->getKey();
        $conteos = $this->countsByDate([$classId], $this->todayDate(), '9999-12-31')[$classId] ?? [];
        $heredadas = (int) ($conteos['*'] ?? 0);
        unset($conteos['*']);

        $fecha = $this->operationalDate($class);
        $inscritos = $heredadas;
        foreach ($conteos as $dia => $n) {
            if ($n + $heredadas > $inscritos) {
                [$fecha, $inscritos] = [$dia, $n + $heredadas];
            }
        }

        return $inscritos > $capacity
            ? "La sesión del {$fecha} ya tiene {$inscritos} inscritos: el aforo no puede quedar por debajo. Quita inscritos antes o elige un aforo de {$inscritos} o más."
            : null;
    }

    /**
     * Socios con alguna reserva que TODAVÍA importa (de hoy en adelante, o
     * heredada sin fecha). Para avisar de un cambio de la clase a quien le
     * afecta, y no a quien fue en agosto.
     *
     * @return \Illuminate\Support\Collection<int, Member>
     */
    public function membersWithUpcomingReservations(MyClass $class): \Illuminate\Support\Collection
    {
        $hoy = $this->todayDate();

        return ClassReservation::query()
            ->where('class_id', $class->getKey())
            ->where(function ($q) use ($hoy): void {
                $q->whereNull('session_date')->orWhereDate('session_date', '>=', $hoy);
            })
            // Sin el alcance del entrenador del CRM: es a quién avisar, no lo
            // que él ve. Con ese alcance, quien no tenía asignado se quedaba sin
            // el aviso de que su clase cambió o se canceló.
            ->with(['member' => fn ($q) => $q->withoutGlobalScope(TrainerMemberScope::NAME)])
            ->get()
            ->pluck('member')
            ->filter()
            ->unique('id')
            ->values();
    }

    // ── Lecturas en bloque (listados sin N+1) ─────────────────────────────

    /**
     * Las reservas que ocupan la ocurrencia operativa de cada clase, en una
     * sola consulta. Misma condición que el cupo: esa fecha o heredada.
     *
     * @param  iterable<MyClass>  $classes
     * @return array<int, \Illuminate\Support\Collection<int, ClassReservation>> clase => filas
     */
    public function operationalRowsFor(iterable $classes): array
    {
        $fechas = [];
        foreach ($classes as $class) {
            $fechas[(int) $class->getKey()] = $this->operationalDate($class);
        }
        if ($fechas === []) {
            return [];
        }

        // `whereDate` y no `whereIn` sobre la fecha: en SQLite la columna guarda
        // '2026-10-05 00:00:00' y la igualdad de texto no casaría nunca.
        $desde = min($fechas);
        $filas = ClassReservation::query()
            ->whereIn('class_id', array_keys($fechas))
            ->where(function ($q) use ($desde): void {
                $q->whereNull('session_date')->orWhereDate('session_date', '>=', $desde);
            })
            ->get(['id', 'class_id', 'member_id', 'session_date', 'reserved_at'])
            ->groupBy('class_id');

        $out = [];
        foreach ($fechas as $classId => $fecha) {
            $out[$classId] = ($filas->get($classId) ?? collect())
                ->filter(fn (ClassReservation $r) => $r->session_date === null || $r->session_date->toDateString() === $fecha)
                ->values();
        }

        return $out;
    }

    /**
     * Cupo ocupado por clase y fecha en un rango, incluidas las heredadas sin
     * fecha (que ocupan TODAS las ocurrencias de su clase).
     *
     * @param  list<int>  $classIds
     * @return array<int, array<string, int>> clase => [fecha => ocupados]
     */
    public function countsByDate(array $classIds, string $from, string $to): array
    {
        if ($classIds === []) {
            return [];
        }

        $conFecha = ClassReservation::query()
            ->whereIn('class_id', $classIds)
            ->whereDate('session_date', '>=', $from)
            ->whereDate('session_date', '<=', $to)
            ->get(['class_id', 'session_date']);
        $heredadas = ClassReservation::query()
            ->whereIn('class_id', $classIds)
            ->whereNull('session_date')
            ->selectRaw('class_id, COUNT(*) AS n')
            ->groupBy('class_id')
            ->pluck('n', 'class_id');

        $out = [];
        foreach ($conFecha as $r) {
            $fecha = $r->session_date->toDateString();
            $out[(int) $r->class_id][$fecha] = ($out[(int) $r->class_id][$fecha] ?? 0) + 1;
        }
        foreach ($classIds as $classId) {
            $out[$classId] ??= [];
            $out[$classId]['*'] = (int) ($heredadas[$classId] ?? 0);
        }

        return $out;
    }

    /**
     * Próximas ocurrencias de cada clase con su cupo ocupado (la operativa
     * primero). Para el calendario del CRM.
     *
     * @param  iterable<MyClass>  $classes
     * @return array<int, list<array{session_date:string, booked:int}>>
     */
    public function upcomingSessionsFor(iterable $classes, int $weeks = 4): array
    {
        $fechas = [];
        foreach ($classes as $class) {
            $fechas[(int) $class->getKey()] = $this->upcomingOccurrences($class, $weeks);
        }
        $todas = array_merge([], ...array_values($fechas));
        if ($todas === []) {
            return array_map(fn () => [], $fechas);
        }

        $conteos = $this->countsByDate(array_keys($fechas), min($todas), max($todas));

        $out = [];
        foreach ($fechas as $classId => $lista) {
            $heredadas = $conteos[$classId]['*'] ?? 0;
            $out[$classId] = array_map(fn (string $f) => [
                'session_date' => $f,
                'booked' => ($conteos[$classId][$f] ?? 0) + $heredadas,
            ], $lista);
        }

        return $out;
    }

    // ── El mostrador ──────────────────────────────────────────────────────

    /**
     * Inscribe a un socio desde el CRM, con las MISMAS reglas que la app.
     *
     * Sin fecha, la ocurrencia operativa (la misma que reserva la app). Con
     * fecha, la que se pida. En los dos casos tiene que ser una ocurrencia real
     * de la clase y no puede estar vencida, en curso ni cerrada: son las reglas
     * del planificador semanal, que es el único camino que ya reservaba fechas
     * explícitas.
     *
     * IDEMPOTENTE. Si el socio ya estaba inscrito en esa ocurrencia, se devuelve
     * éxito con `already`: el efecto pedido ya existe, y un doble clic o un
     * reintento de red no pueden convertirse en un error para quien atiende.
     *
     * @param  (callable(ClassReservation): void)|null  $auditar  corre dentro de la transacción
     */
    public function enrollByStaff(
        Member $member,
        MyClass $class,
        ?string $explicitDate,
        ?callable $auditar = null,
    ): ClassBookingResult {
        $date = $explicitDate ?? $this->operationalDate($class);

        // También sin fecha explícita: una clase mal configurada (sin día ni
        // fecha) no tiene ocurrencia, y `operationalDate()` caería a «hoy».
        if (! $this->isOccurrence($class, $date)) {
            return ClassBookingResult::rejected(self::NOT_AN_OCCURRENCE, $date);
        }

        if ($motivo = $this->ineligibility($member, $class, self::CHANNEL_STAFF)) {
            return ClassBookingResult::rejected($motivo, $date);
        }

        if ($motivo = $this->occurrenceBlock($class, $date)) {
            return ClassBookingResult::rejected($motivo, $date);
        }

        // Solo se avisa de lo que cambió: «ya estaba» no movió ningún cupo, y el
        // núcleo solo emite el hecho cuando crea la fila.
        $outcome = $this->reserveOccurrence($member, $class, $date, $auditar, ClassEventBus::SOURCE_CRM);

        if ($outcome === self::FULL) {
            return ClassBookingResult::rejected(self::FULL, $date);
        }

        return ClassBookingResult::done($outcome, $date);
    }

    /**
     * Quita a un socio de una ocurrencia desde el CRM.
     *
     * La MISMA regla que la app: no se quita a nadie de una clase que el
     * entrenador ya inició o cerró. Lo que NO se aplica es la retención por
     * deuda: en la app un socio con saldo vencido no puede cancelar y se queda
     * reteniendo el cupo; liberarlo desde el mostrador no es darle ningún
     * beneficio, es devolver la plaza a quien sí puede usarla.
     *
     * Se borra lo que OCUPA esa ocurrencia —la fila de esa fecha y la heredada
     * sin fecha—, que es exactamente lo que cuentan el cupo y el listado. Si
     * solo se borrara la de la fecha, una reserva heredada seguiría en la
     * lista después de «quitarla».
     *
     * IDEMPOTENTE: quitar a quien ya no estaba es éxito con `already`.
     *
     * @param  (callable(int): void)|null  $auditar  corre dentro de la transacción
     */
    public function removeByStaff(
        Member $member,
        MyClass $class,
        string $date,
        ?callable $auditar = null,
    ): ClassBookingResult {
        $session = ClassSession::where('class_id', $class->getKey())->whereDate('session_date', $date)->first();
        if ($motivo = $this->cancelBlock($session)) {
            return ClassBookingResult::rejected($motivo, $date);
        }

        $deleted = $this->cancelOccurrence($member, $class, null, $date, $auditar, ClassEventBus::SOURCE_CRM);

        return ClassBookingResult::done($deleted === 0 ? self::ALREADY : self::REMOVED, $date);
    }

    private function today(): Carbon
    {
        return Carbon::today(self::TZ);
    }
}
