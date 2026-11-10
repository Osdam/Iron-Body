<?php

namespace App\Services\Outreach\Facts;

use App\Models\ValuationAppointment;
use App\Services\Outreach\Core\RelativeTime;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de SOLO LECTURA de las valoraciones (contrato H9, D9-3
 * `valuation.outdated`, D9-4 «Agenda»). Tablas: `valuation_appointments`,
 * `physical_evaluations` y, en el prefiltro, `users`/`members`.
 *
 *  - LA ÚLTIMA VALORACIÓN es la más reciente de: una cita COMPLETADA (su
 *    `completed_at`, o su hora de inicio si no lo tiene) y una evaluación
 *    física registrada (`physical_evaluations.created_at`). Vigente o
 *    caducada lo dice la condición sobre `valuation.days_since_last` (días
 *    de Bogotá). Quien NUNCA se valoró queda DESCONOCIDO, como la asistencia.
 *  - PRÓXIMA (`valuation.has_upcoming`): una cita viva
 *    (`ValuationAppointment::ACTIVE_STATUSES`) que todavía no ha terminado;
 *    `valuation.next_date`: días de Bogotá hasta el inicio de la más cercana
 *    (`date_relative` ≥ 0: una en curso es 0); null si no hay ninguna.
 *  - La clave de ciclo de `valuation.outdated` es la ÚLTIMA valoración
 *    (`val:{last_valuation_id}`, DF-K5): el id de la cita completada o, si la
 *    última fue una evaluación física sin cita, el de la evaluación (Q-4 del
 *    informe: los dos ids viven en tablas distintas).
 */
final class ValuationFacts
{
    public const TABLES = ['valuation_appointments', 'physical_evaluations', 'users', 'members'];

    public const FACTS = ['valuation.days_since_last', 'valuation.has_upcoming', 'valuation.next_date'];

    public const EVENT_OUTDATED = 'valuation.outdated';

    /** @return array<string, array<string, bool|int|null>> por clave pedida */
    public function facts(FactPass $b): array
    {
        $socios = $b->memberIds();
        $ahora = FactValues::sql($b->now);
        $ultima = [];
        $proxima = [];
        if ($socios !== []) {
            $citas = DB::table('valuation_appointments')
                ->whereIn('member_id', $socios)
                ->where(fn ($q) => $q->where('status', ValuationAppointment::STATUS_COMPLETED)
                    ->orWhere(fn ($w) => $w->whereIn('status', ValuationAppointment::ACTIVE_STATUSES)->where('ends_at', '>', $ahora)))
                ->get(['member_id', 'status', 'starts_at', 'completed_at']);
            foreach ($citas as $c) {
                $socio = (int) $c->member_id;
                if ($c->status === ValuationAppointment::STATUS_COMPLETED) {
                    $cuando = FactValues::instant($c->completed_at) ?? FactValues::instant($c->starts_at);
                    $ultima[$socio] = self::later($ultima[$socio] ?? null, $cuando, $b->now);
                } else {
                    $inicio = FactValues::instant($c->starts_at);
                    if ($inicio !== null && (! isset($proxima[$socio]) || $inicio < $proxima[$socio])) {
                        $proxima[$socio] = $inicio;
                    }
                }
            }
            $evaluaciones = DB::table('physical_evaluations')
                ->whereIn('member_id', $socios)
                ->where('created_at', '<=', $ahora)
                ->groupBy('member_id')
                ->selectRaw('member_id, MAX(created_at) AS last_at')
                ->get();
            foreach ($evaluaciones as $e) {
                $socio = (int) $e->member_id;
                $ultima[$socio] = self::later($ultima[$socio] ?? null, FactValues::instant($e->last_at), $b->now);
            }
        }

        $salida = [];
        foreach ($b->subjects as $clave => $s) {
            $hechos = array_fill_keys(self::FACTS, null);
            if ($s->resolved() && $s->isMember()) {
                $mas = null;
                $cercana = null;
                foreach ($s->memberIds as $m) {
                    $mas = self::later($mas, $ultima[$m] ?? null, $b->now);
                    if (isset($proxima[$m]) && ($cercana === null || $proxima[$m] < $cercana)) {
                        $cercana = $proxima[$m];
                    }
                }
                $hechos['valuation.days_since_last'] = $mas !== null ? RelativeTime::daysAgo($mas, $b->now) : null;
                $hechos['valuation.has_upcoming'] = $cercana !== null;
                $hechos['valuation.next_date'] = $cercana !== null ? max(0, -RelativeTime::daysAgo($cercana, $b->now)) : null;
            }
            $salida[$clave] = $hechos;
        }

        return $salida;
    }

    /**
     * Socios con membresía activa cuya última valoración fue hace `$days` días
     * o más (días de Bogotá) y que no tienen una cita viva. Quien nunca se
     * valoró no entra (desconocido). Clave de ciclo: la semana ISO.
     *
     * @return array{candidates: list<Candidate>, next: ?int}
     */
    public function outdated(DateTimeInterface $now, int $days, int $limit = 500, int $afterUserId = 0): array
    {
        $limite = max(1, min(500, $limit));
        $hoy = RelativeTime::bogotaDate($now);
        $ahora = FactValues::sql(RelativeTime::utc($now));
        // «Hace ≥ N días» ⇔ nada desde las 00:00 de Bogotá de hoy − (N − 1).
        $corte = FactValues::sql(FactValues::startOfBogotaDay(FactValues::addDays($hoy, -(max(1, $days) - 1))));

        $completada = fn ($q, ?string $desde = null) => $q->from('valuation_appointments as va')
            ->whereColumn('va.member_id', 'members.id')
            ->where('va.status', ValuationAppointment::STATUS_COMPLETED)
            ->when($desde !== null, fn ($w) => $w->whereRaw('COALESCE(va.completed_at, va.starts_at) >= ?', [$desde]));
        $evaluada = fn ($q, ?string $desde = null) => $q->from('physical_evaluations as pe')
            ->whereColumn('pe.member_id', 'members.id')
            ->where('pe.created_at', '<=', $ahora)
            ->when($desde !== null, fn ($w) => $w->where('pe.created_at', '>=', $desde));

        $filas = DB::table('users')
            ->join('members', 'members.user_id', '=', 'users.id')
            ->where('users.id', '>', $afterUserId)
            ->whereNotNull('users.plan')->where('users.plan', '<>', '')
            ->whereNull('users.membership_frozen_at')
            ->where(fn ($q) => $q->whereNull('users.membership_end_date')->orWhere('users.membership_end_date', '>=', $hoy))
            ->where(fn ($q) => $q->whereExists(fn ($w) => $completada($w))->orWhereExists(fn ($w) => $evaluada($w)))
            ->whereNotExists(fn ($w) => $completada($w, $corte))
            ->whereNotExists(fn ($w) => $evaluada($w, $corte))
            ->whereNotExists(fn ($w) => $w->from('valuation_appointments as vp')->whereColumn('vp.member_id', 'members.id')
                ->whereIn('vp.status', ValuationAppointment::ACTIVE_STATUSES)->where('vp.ends_at', '>', $ahora))
            ->orderBy('users.id')
            ->limit($limite)
            ->distinct()
            ->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        $sujetos = (new SubjectResolver)->forUserIds($filas);
        $ultimas = $this->lastValuationIds(array_values(array_unique(array_merge(...array_map(fn ($s) => $s->memberIds, array_values($sujetos) ?: [[]])))));
        $candidatos = [];
        foreach ($filas as $id) {
            $s = $sujetos[$id] ?? null;
            if ($s === null) {
                continue;
            }
            $ultima = null;
            foreach ($s->memberIds as $m) {
                $u = $ultimas[$m] ?? null;
                if ($u !== null && ($ultima === null || $u['at'] > $ultima['at'])) {
                    $ultima = $u;
                }
            }
            $candidatos[] = new Candidate((string) $s->key, self::EVENT_OUTDATED, ['last_valuation_id' => $ultima['id'] ?? null], $id);
        }

        return ['candidates' => $candidatos, 'next' => count($filas) === $limite ? $filas[count($filas) - 1] : null];
    }

    /**
     * El id de la última valoración de cada socio (cita completada o evaluación física).
     *
     * @param  list<int>  $socios
     * @return array<int, array{id: int, at: string}>
     */
    private function lastValuationIds(array $socios): array
    {
        if ($socios === []) {
            return [];
        }
        $salida = [];
        foreach (DB::table('valuation_appointments')->whereIn('member_id', $socios)->where('status', ValuationAppointment::STATUS_COMPLETED)
            ->orderBy('id')->get(['id', 'member_id', 'starts_at', 'completed_at']) as $c) {
            $at = (string) ($c->completed_at ?? $c->starts_at);
            if (! isset($salida[(int) $c->member_id]) || $at >= $salida[(int) $c->member_id]['at']) {
                $salida[(int) $c->member_id] = ['id' => (int) $c->id, 'at' => $at];
            }
        }
        foreach (DB::table('physical_evaluations')->whereIn('member_id', $socios)->orderBy('id')->get(['id', 'member_id', 'created_at']) as $e) {
            $at = (string) $e->created_at;
            if (! isset($salida[(int) $e->member_id]) || $at > $salida[(int) $e->member_id]['at']) {
                $salida[(int) $e->member_id] = ['id' => (int) $e->id, 'at' => $at];
            }
        }

        return $salida;
    }

    /** El más reciente de dos instantes que no sean futuros. */
    private static function later(?DateTimeImmutable $a, ?DateTimeImmutable $b, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $b = $b !== null && $b <= $now ? $b : null;
        if ($a === null) {
            return $b;
        }

        return $b !== null && $b > $a ? $b : $a;
    }
}
