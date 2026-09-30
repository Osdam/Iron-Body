<?php

namespace App\Services\Classes;

use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Services\Caja\MembershipFinancialStanding;
use App\Services\Moderation\SessionEnforcer;
use App\Services\RealtimeEvents;
use App\Services\Trainer\TrainerRealtimeEvents;
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
     * @param  (callable(ClassReservation): void)|null  $enLaTransaccion
     */
    public function reserveOccurrence(Member $member, MyClass $class, string $date, ?callable $enLaTransaccion = null): string
    {
        try {
            return DB::transaction(function () use ($member, $class, $date, $enLaTransaccion): string {
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
     * @param  (callable(int): void)|null  $enLaTransaccion  recibe las filas borradas
     */
    public function cancelOccurrence(
        Member $member,
        MyClass $class,
        ?string $explicitDate,
        string $fallbackDate,
        ?callable $enLaTransaccion = null,
    ): int {
        return DB::transaction(function () use ($member, $class, $explicitDate, $fallbackDate, $enLaTransaccion): int {
            $query = ClassReservation::where('class_id', $class->getKey())
                ->where('member_id', $member->getKey());

            if ($explicitDate !== null) {
                $query->whereDate('session_date', $explicitDate);
            } else {
                $query->where(function ($q) use ($fallbackDate): void {
                    $q->whereNull('session_date')->orWhereDate('session_date', $fallbackDate);
                });
            }

            $deleted = $query->delete();

            if ($deleted > 0 && $enLaTransaccion !== null) {
                $enLaTransaccion($deleted);
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

    // ── Avisos ────────────────────────────────────────────────────────────

    /**
     * Avisa en tiempo real de que el cupo de una clase cambió.
     *
     * A la app de todos los socios (`classesChanged`) y al portal del entrenador
     * dueño de la clase. Son SEÑALES, no datos: quien las recibe vuelve a leer
     * de la base, que es la verdad.
     *
     * SIEMPRE DESPUÉS DEL COMMIT. `afterCommit` hace que nunca se anuncie una
     * reserva que luego se revierte, y que un aviso que falla no pueda tumbar
     * la reserva: ambos emisores ya se tragan sus errores. Si un aviso se
     * pierde, el dato no: la siguiente lectura lo trae. Fuera de una
     * transacción, `afterCommit` ejecuta en el acto.
     */
    public function announce(MyClass $class): void
    {
        $this->announceFor($class->trainer_id ? [(int) $class->trainer_id] : []);
    }

    /**
     * Un único aviso para un cambio que tocó varias clases (el plan semanal):
     * `classesChanged` una vez y cada entrenador afectado una vez, en lugar de
     * un anuncio por reserva.
     *
     * @param  array<int, int>  $trainerIds
     */
    public function announceFor(array $trainerIds): void
    {
        $trainerIds = array_values(array_unique(array_map('intval', $trainerIds)));

        DB::afterCommit(function () use ($trainerIds): void {
            RealtimeEvents::classesChanged();
            foreach ($trainerIds as $trainerId) {
                TrainerRealtimeEvents::emit($trainerId, TrainerRealtimeEvents::CLASS_EVENT, ['classes']);
            }
        });
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

        $outcome = $this->reserveOccurrence($member, $class, $date, $auditar);

        if ($outcome === self::FULL) {
            return ClassBookingResult::rejected(self::FULL, $date);
        }

        // Solo se anuncia lo que cambió: «ya estaba» no movió ningún cupo, y cada
        // anuncio escribe una fila por socio activo.
        if ($outcome === self::RESERVED) {
            $this->announce($class);
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

        $deleted = $this->cancelOccurrence($member, $class, null, $date, $auditar);

        if ($deleted === 0) {
            return ClassBookingResult::done(self::ALREADY, $date);
        }

        $this->announce($class);

        return ClassBookingResult::done(self::REMOVED, $date);
    }

    private function today(): Carbon
    {
        return Carbon::today(self::TZ);
    }
}
