<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\RelativeTime;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de SOLO LECTURA de la asistencia al gimnasio (contrato H9, D9-4).
 * Tablas: `attendances` (y `users`/`members` en el prefiltro).
 *
 *  - Solo cuentan las ENTRADAS (`action = 'entry'`), como `CommercialSubject`
 *    y `ScanCommercialFacts`.
 *  - Se lee por `user_id` (no nulo, índice `(user_id, captured_at)`): todas las
 *    fichas de la persona.
 *  - `attendance.days_since_last`: días de Bogotá desde la última entrada.
 *    QUIEN NUNCA VINO es DESCONOCIDO, no inactivo (`ScanCommercialFacts.php:167-171`):
 *    eso es un socio que no ha empezado y se acompaña de otra manera.
 *  - `attendance.count_last_30d`: entradas en la ventana de 30 días de
 *    `RelativeTime::windowStart()` (desde las 00:00 de Bogotá de hoy − 30) hasta ahora.
 *  - `attendance.weekly_rate`: ese recuento / 4,3 a 2 decimales
 *    (`CommercialSubject::weeklyAttendanceRate()`), como ENTERO en centésimas
 *    (`decimal` de escala 2 del catálogo, DF-T4: 0,47 → 47).
 *  - Sin usuario (prospecto, o socio sin cuenta): todo desconocido.
 */
final class AttendanceFacts
{
    public const TABLES = ['attendances', 'users', 'members'];

    public const FACTS = ['attendance.days_since_last', 'attendance.count_last_30d', 'attendance.weekly_rate'];

    public const EVENT_INACTIVE = 'member.inactive';

    /** Divisor de `CommercialSubject::weeklyAttendanceRate()`. */
    public const WEEKS_PER_30_DAYS = 4.3;

    /** @return array<string, array<string, int|float|null>> por clave pedida */
    public function facts(FactPass $b): array
    {
        $porUsuario = [];
        $usuarios = $b->userIds();
        if ($usuarios !== []) {
            $desde = FactValues::sql(RelativeTime::windowStart($b->now, 30, 'days'));
            $hasta = FactValues::sql($b->now);
            $filas = DB::table('attendances')
                ->whereIn('user_id', $usuarios)
                ->where('action', 'entry')
                ->where('captured_at', '<=', $hasta)
                ->groupBy('user_id')
                ->selectRaw('user_id, MAX(captured_at) AS last_at, SUM(CASE WHEN captured_at >= ? THEN 1 ELSE 0 END) AS last30', [$desde])
                ->get();
            foreach ($filas as $f) {
                $porUsuario[(int) $f->user_id] = ['last' => FactValues::instant($f->last_at), 'n30' => (int) $f->last30];
            }
        }

        $salida = [];
        foreach ($b->subjects as $clave => $s) {
            $hechos = array_fill_keys(self::FACTS, null);
            if ($s->resolved() && $s->userIds !== []) {
                $ultima = null;
                $n30 = 0;
                foreach ($s->userIds as $u) {
                    $fila = $porUsuario[$u] ?? null;
                    if ($fila === null) {
                        continue;
                    }
                    $n30 += $fila['n30'];
                    if ($fila['last'] !== null && ($ultima === null || $fila['last'] > $ultima)) {
                        $ultima = $fila['last'];
                    }
                }
                $hechos['attendance.days_since_last'] = $ultima !== null ? RelativeTime::daysAgo($ultima, $b->now) : null;
                $hechos['attendance.count_last_30d'] = $n30;
                $hechos['attendance.weekly_rate'] = (int) round(round($n30 / self::WEEKS_PER_30_DAYS, 2) * 100);
            }
            $salida[$clave] = $hechos;
        }

        return $salida;
    }

    /**
     * Socios con membresía ACTIVA (plan, sin congelar, fecha ≥ hoy de Bogotá o
     * sin fecha) que vinieron alguna vez y cuya última entrada fue hace `$days`
     * días o más (días de Bogotá). Para `member.inactive`; la clave de ciclo es
     * la semana ISO de Bogotá (`ScanCommercialFacts.php:188`), que arma el núcleo.
     *
     * @return array{candidates: list<Candidate>, next: ?int}
     */
    public function inactiveSince(DateTimeInterface $now, int $days, int $limit = 500, int $afterUserId = 0): array
    {
        $limite = max(1, min(500, $limit));
        $hoy = RelativeTime::bogotaDate($now);
        // «Hace ≥ N días» ⇔ ninguna entrada desde las 00:00 de Bogotá de hoy − (N − 1).
        $corte = FactValues::sql(FactValues::startOfBogotaDay(FactValues::addDays($hoy, -(max(1, $days) - 1))));
        $ahora = FactValues::sql(RelativeTime::utc($now));

        $filas = DB::table('users')
            ->where('id', '>', $afterUserId)
            ->whereNotNull('plan')->where('plan', '<>', '')
            ->whereNull('membership_frozen_at')
            ->where(fn ($q) => $q->whereNull('membership_end_date')->orWhere('membership_end_date', '>=', $hoy))
            ->whereExists(fn ($q) => $q->from('members')->whereColumn('members.user_id', 'users.id'))
            ->whereExists(fn ($q) => $q->from('attendances')->whereColumn('attendances.user_id', 'users.id')
                ->where('attendances.action', 'entry')->where('attendances.captured_at', '<=', $ahora))
            ->whereNotExists(fn ($q) => $q->from('attendances')->whereColumn('attendances.user_id', 'users.id')
                ->where('attendances.action', 'entry')->where('attendances.captured_at', '>=', $corte)->where('attendances.captured_at', '<=', $ahora))
            ->orderBy('id')
            ->limit($limite)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $sujetos = (new SubjectResolver)->forUserIds($filas);
        $candidatos = [];
        foreach ($filas as $id) {
            if (isset($sujetos[$id])) {
                // La semana ISO de Bogotá la pone el núcleo con `now` (`inact:{bogota_iso_week}`).
                $candidatos[] = new Candidate((string) $sujetos[$id]->key, self::EVENT_INACTIVE, [], $id);
            }
        }

        return ['candidates' => $candidatos, 'next' => count($filas) === $limite ? $filas[count($filas) - 1] : null];
    }
}
