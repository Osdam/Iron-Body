<?php

namespace App\Services\Outreach\Facts;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Member;
use App\Services\Membership\MembershipAccess;
use App\Services\Outreach\Core\RelativeTime;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de SOLO LECTURA de la membresía (contrato H9, D9-2, D9-3, D9-4).
 * Tablas: `users`, `members`, `plans`, `membership_subscriptions` (por
 * {@see FactPass::subscriptions()}) y `payments` (renovación).
 *
 * LA MEMBRESÍA VIVE EN `users` (plan + fechas; `MembershipService`). Las
 * reglas, con el reloj inyectado (nunca `now()`):
 *  - `membership_end_date` es un DÍA de Bogotá: el acceso vale hasta su
 *    medianoche local (`MembershipService::endsAt()`). Activa ⇔ hay plan y
 *    (no hay fecha o hoy de Bogotá ≤ fecha). «Vence hoy» = 0 días.
 *  - `days_to_expiry`, `days_since_expiry` y `end_date` son la MISMA fecha
 *    vista de tres formas, todas CON SIGNO (DF-T1): `days_to_expiry` = días de
 *    Bogotá hasta la fecha (0 = vence hoy, −1 = venció ayer), `end_date` el
 *    mismo desplazamiento (`date_relative`, futuro > 0) y `days_since_expiry`
 *    su opuesto.
 *  - CONGELADA (`membership_frozen_at` no nulo): congelar pone la fecha AYER
 *    para que la app y el torniquete denieguen (`MembershipFreeze::freeze()`);
 *    esa fecha es artificial, así que los días quedan DESCONOCIDOS y la
 *    membresía no está activa. Un congelado nunca parece «vencido ayer».
 *  - El plan se guarda por NOMBRE: se casa con `plans` con la normalización
 *    de `MembershipAccess::planKey()` y sale su id ENTERO (dominio dinámico
 *    `plans`); si dos planes vigentes casan, es ambiguo y queda desconocido.
 *  - Menor: con fecha de nacimiento (socio o usuario) manda la edad en días de
 *    Bogotá (cumplir 18 hoy ya es adulto); sin fecha, la marca `is_minor`
 *    (si dice menor); si no, DESCONOCIDO (D9-15: no se infiere). Solo sale el
 *    booleano, nunca la fecha.
 */
final class MembershipFacts
{
    public const TABLES = ['users', 'members', 'plans', 'membership_subscriptions', 'payments'];

    public const FACTS = [
        'subject.is_member', 'subject.is_minor',
        'membership.active', 'membership.days_to_expiry', 'membership.days_since_expiry', 'membership.end_date', 'membership.plan_id',
        'membership.plan_duration_days', 'membership.days_as_member', 'membership.frozen',
    ];

    public const EVENT_EXPIRING = 'membership.expiring_window';

    public const EVENT_EXPIRED = 'membership.expired_window';

    /** @return array<string, array<string, bool|int|string|null>> por clave pedida */
    public function facts(FactPass $b): array
    {
        $usuarios = [];
        $principales = $b->primaryUserIds();
        if ($principales !== []) {
            foreach (DB::table('users')->whereIn('id', $principales)->orderBy('id')
                ->get(['id', 'plan', 'birth_date', 'membership_start_date', 'membership_end_date', 'membership_frozen_at']) as $u) {
                $usuarios[(int) $u->id] = $u;
            }
        }

        $socios = [];
        $ids = $b->memberIds();
        if ($ids !== []) {
            foreach (DB::table('members')->whereIn('id', $ids)->orderBy('id')->get(['id', 'is_minor', 'birth_date']) as $m) {
                $socios[(int) $m->id] = $m;
            }
        }

        $planes = $this->plansByKey($usuarios);
        $suscripciones = $b->subscriptions();

        $salida = [];
        $this->transitions = [];
        foreach ($b->subjects as $clave => $s) {
            $u = $s->primaryUserId !== null ? ($usuarios[$s->primaryUserId] ?? null) : null;
            $salida[$clave] = $this->forSubject($b, $s, $u, $socios, $planes, $suscripciones[$clave] ?? null);
            $this->transitions[$clave] = $this->stateTransitions($b, $u);
        }

        return $salida;
    }

    /** @var array<string, list<array{at: string, entity: string, from: string, to: string}>> */
    private array $transitions = [];

    /**
     * Las transiciones de la membresía que PRUEBA el estado actual, de la
     * última llamada a {@see facts()} (D9-4: «se lee de `commercial_events` y
     * del estado actual»): una membresía vencida (con plan, sin congelar) pasó
     * de activa a vencida a las 00:00 de Bogotá del día siguiente a su fecha;
     * una congelada, de activa a congelada a las 00:00 del día en que se
     * congeló. Lo demás lo prueban los eventos ({@see EngineFacts::transitionsFrom()}).
     *
     * @return array<string, list<array{at: string, entity: string, from: string, to: string}>> por clave pedida
     */
    public function lastTransitions(): array
    {
        return $this->transitions;
    }

    /** @return list<array{at: string, entity: string, from: string, to: string}> */
    private function stateTransitions(FactPass $b, ?object $u): array
    {
        if ($u === null || ! is_string($u->plan) || trim($u->plan) === '') {
            return [];
        }
        $congelada = FactValues::date($u->membership_frozen_at);
        if ($congelada !== null && $congelada <= $b->today) {
            return [['at' => RelativeTime::format(FactValues::startOfBogotaDay($congelada)), 'entity' => 'membership', 'from' => 'active', 'to' => 'frozen']];
        }
        $fin = FactValues::date($u->membership_end_date);
        if ($u->membership_frozen_at === null && $fin !== null && $fin < $b->today) {
            return [['at' => RelativeTime::format(FactValues::startOfBogotaDay(FactValues::addDays($fin, 1))), 'entity' => 'membership', 'from' => 'active', 'to' => 'expired']];
        }

        return [];
    }

    /**
     * ¿Renovó desde un instante? (D9-6 `success`, N6 `success_reached`): un
     * cobro de membresía COBRADO (`payments` con plan y un estado de
     * `PaymentController::PAID_STATUSES`) fechado en [`$since`, ahora]. Un
     * cobro anulado ya no está en esos estados. null si la persona no tiene
     * usuario (no se sabe).
     *
     * @return array<string, ?bool> por clave pedida
     */
    public function renewedSince(FactPass $b, DateTimeInterface $since): array
    {
        $salida = [];
        $usuarios = $b->userIds();
        $con = [];
        if ($usuarios !== []) {
            $filas = DB::table('payments')
                ->whereIn('user_id', $usuarios)
                ->whereNotNull('plan_id')
                ->whereIn(DB::raw('LOWER(status)'), PaymentController::PAID_STATUSES)
                ->whereRaw('COALESCE(paid_at, created_at) >= ?', [FactValues::sql(RelativeTime::utc($since))])
                ->whereRaw('COALESCE(paid_at, created_at) <= ?', [FactValues::sql($b->now)])
                ->distinct()
                ->pluck('user_id');
            foreach ($filas as $userId) {
                foreach ($b->keysForUser((int) $userId) as $clave) {
                    $con[$clave] = true;
                }
            }
        }
        foreach ($b->subjects as $clave => $s) {
            $salida[$clave] = ! $s->resolved() || $s->userIds === [] ? null : isset($con[$clave]);
        }

        return $salida;
    }

    // ── Prefiltros (D9-5): indexados por `membership_end_date` ──────────────

    /**
     * Socios con plan, sin congelar, cuya fecha de fin cae en [hoy + `$fromDays`,
     * hoy + `$toDays`] (días de Bogotá). Para `membership.expiring_window`.
     *
     * @return array{candidates: list<Candidate>, next: ?int}
     */
    public function endingBetween(DateTimeInterface $now, int $fromDays, int $toDays, int $limit = 500, int $afterUserId = 0): array
    {
        $hoy = RelativeTime::bogotaDate($now);

        return $this->byEndDate(FactValues::addDays($hoy, $fromDays), FactValues::addDays($hoy, $toDays), self::EVENT_EXPIRING, $limit, $afterUserId);
    }

    /**
     * Socios con plan, sin congelar, cuya membresía venció hace entre
     * `$minDaysAgo` y `$maxDaysAgo` días (los dos extremos dentro; ≥ 1, porque
     * el día de la fecha todavía es activo). Para `membership.expired_window`.
     *
     * @return array{candidates: list<Candidate>, next: ?int}
     */
    public function endedBetween(DateTimeInterface $now, int $minDaysAgo, int $maxDaysAgo, int $limit = 500, int $afterUserId = 0): array
    {
        $hoy = RelativeTime::bogotaDate($now);
        $min = max(1, $minDaysAgo);

        return $this->byEndDate(FactValues::addDays($hoy, -$maxDaysAgo), FactValues::addDays($hoy, -$min), self::EVENT_EXPIRED, $limit, $afterUserId);
    }

    /** @return array{candidates: list<Candidate>, next: ?int} */
    private function byEndDate(string $desde, string $hasta, string $evento, int $limit, int $afterUserId): array
    {
        if ($desde > $hasta) {
            return ['candidates' => [], 'next' => null];
        }
        $limite = max(1, min(500, $limit));
        // Intervalo semiabierto [desde, hasta + 1): vale con `date` en PG y con
        // «Y-m-d 00:00:00» en SQLite, y deja usar el índice de la columna.
        $filas = DB::table('users')
            ->where('id', '>', $afterUserId)
            ->where('membership_end_date', '>=', $desde)
            ->where('membership_end_date', '<', FactValues::addDays($hasta, 1))
            ->whereNotNull('plan')->where('plan', '<>', '')
            ->whereNull('membership_frozen_at')
            ->whereExists(fn ($q) => $q->from('members')->whereColumn('members.user_id', 'users.id'))
            ->orderBy('id')
            ->limit($limite)
            ->get(['id', 'membership_end_date']);

        $sujetos = (new SubjectResolver)->forUserIds($filas->pluck('id')->map(fn ($id) => (int) $id)->all());
        $candidatos = [];
        foreach ($filas as $f) {
            $s = $sujetos[(int) $f->id] ?? null;
            $fin = FactValues::date($f->membership_end_date);
            if ($s === null || $fin === null) {
                continue;
            }
            $candidatos[] = new Candidate((string) $s->key, $evento, ['membership_end_date' => $fin], (int) $f->id);
        }

        return ['candidates' => $candidatos, 'next' => $filas->count() === $limite ? (int) $filas->last()->id : null];
    }

    // ── Por persona ─────────────────────────────────────────────────────────

    /**
     * @param  array<int, object>  $socios
     * @param  array<string, list<object>>  $planes
     * @return array<string, bool|int|string|null>
     */
    private function forSubject(FactPass $b, Subject $s, ?object $u, array $socios, array $planes, ?object $suscripcion): array
    {
        $nulos = array_fill_keys(self::FACTS, null);
        if (! $s->resolved()) {
            // `subject.is_member` no admite nulo (catálogo): sin ficha encontrada, no es socio.
            return ['subject.is_member' => false] + $nulos;
        }

        $hechos = $nulos;
        $hechos['subject.is_member'] = $s->isMember();
        $hechos['subject.is_minor'] = $this->isMinor($b, $s, $u, $socios);

        if ($u === null) {
            // Sin usuario no hay membresía en el CRM: se sabe que no está activa
            // ni congelada; lo demás no existe y queda desconocido.
            $hechos['membership.active'] = false;
            $hechos['membership.frozen'] = false;

            return $hechos;
        }

        $plan = is_string($u->plan) && trim($u->plan) !== '' ? $u->plan : null;
        $fin = FactValues::date($u->membership_end_date);
        $congelada = $u->membership_frozen_at !== null;

        $hechos['membership.frozen'] = $congelada;
        $hechos['membership.active'] = ! $congelada && $plan !== null && ($fin === null || $b->today <= $fin);
        if (! $congelada && $fin !== null) {
            $faltan = RelativeTime::daysUntilDate($fin, $b->now);
            $hechos['membership.days_to_expiry'] = $faltan;
            $hechos['membership.end_date'] = $faltan;
            $hechos['membership.days_since_expiry'] = -$faltan;
        }

        if ($plan !== null) {
            $elegido = self::pickPlan($planes[MembershipAccess::planKey($plan)] ?? []);
            if ($elegido !== null) {
                $hechos['membership.plan_id'] = (int) $elegido->id;
                // El catálogo exige ≥ 1: un plan sin duración no tiene una.
                $hechos['membership.plan_duration_days'] = $elegido->duration_days !== null && (int) $elegido->duration_days >= 1 ? (int) $elegido->duration_days : null;
            }
        }

        $inicio = FactValues::date($suscripcion?->current_period_start) ?? FactValues::date($u->membership_start_date);
        if ($inicio !== null) {
            $dias = -RelativeTime::daysUntilDate($inicio, $b->now);
            // `duration` (≥ 0): un periodo que empieza en el futuro todavía no cuenta días.
            $hechos['membership.days_as_member'] = $dias >= 0 ? $dias : null;
        }

        return $hechos;
    }

    /** @param  array<int, object>  $socios */
    private function isMinor(FactPass $b, Subject $s, ?object $u, array $socios): ?bool
    {
        if (! $s->isMember()) {
            // Un prospecto sin ficha: no hay fecha y no se infiere (D9-15).
            return null;
        }
        $nacimiento = null;
        $marca = false;
        foreach ($s->memberIds as $id) {
            $m = $socios[$id] ?? null;
            if ($m === null) {
                continue;
            }
            $nacimiento ??= FactValues::date($m->birth_date);
            $marca = $marca || FactValues::bool($m->is_minor);
        }
        $nacimiento ??= FactValues::date($u?->birth_date);

        if ($nacimiento !== null && $nacimiento <= $b->today) {
            return self::ageOn($nacimiento, $b->today) < Member::legalAdultAge();
        }

        return $marca ? true : null;
    }

    /** Años cumplidos en la fecha (calendario; quien nace un 29-02 cumple el 01-03 en un año no bisiesto). */
    private static function ageOn(string $birth, string $today): int
    {
        $edad = (int) substr($today, 0, 4) - (int) substr($birth, 0, 4);

        return substr($today, 5) < substr($birth, 5) ? $edad - 1 : $edad;
    }

    /**
     * Los planes por su clave normalizada (solo si alguna persona del lote tiene plan).
     *
     * @param  array<int, object>  $usuarios
     * @return array<string, list<object>>
     */
    private function plansByKey(array $usuarios): array
    {
        $hayPlan = false;
        foreach ($usuarios as $u) {
            if (is_string($u->plan) && trim($u->plan) !== '') {
                $hayPlan = true;

                break;
            }
        }
        if (! $hayPlan) {
            return [];
        }
        $porClave = [];
        foreach (DB::table('plans')->orderBy('id')->get(['id', 'name', 'duration_days', 'archived_at']) as $p) {
            // Por nombre y por id escrito como texto, como `PlanController`.
            if (is_string($p->name)) {
                $porClave[MembershipAccess::planKey($p->name)][] = $p;
            }
            $porClave[MembershipAccess::planKey((string) $p->id)][] = $p;
        }

        return $porClave;
    }

    /**
     * El plan de un nombre: el único que casa, o el único VIGENTE (sin archivar)
     * si casan varios; si no, ambiguo (null).
     *
     * @param  list<object>  $casan
     */
    private static function pickPlan(array $casan): ?object
    {
        $unicos = [];
        foreach ($casan as $p) {
            $unicos[(int) $p->id] = $p;
        }
        if (count($unicos) === 1) {
            return array_values($unicos)[0];
        }
        $vigentes = array_values(array_filter($unicos, static fn (object $p): bool => $p->archived_at === null));

        return count($vigentes) === 1 ? $vigentes[0] : null;
    }
}
