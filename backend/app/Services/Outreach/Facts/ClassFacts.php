<?php

namespace App\Services\Outreach\Facts;

use App\Models\ClassAttendance;
use App\Models\MyClass;
use App\Services\Outreach\Core\RelativeTime;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de SOLO LECTURA de las clases (contrato H9, D9-3
 * `class.upcoming_with_capacity`, D9-4 «Agenda»). Tablas: `classes`,
 * `class_reservations`, `class_sessions`, `class_attendances` y, en el
 * prefiltro, `users`/`members`.
 *
 * LAS REGLAS DE LA AGENDA SON LAS DE `ClassBookingService` (no se reinventan):
 *  - una OCURRENCIA es (clase, fecha de Bogotá); sus fechas las da
 *    `MyClass::occurrenceDateTimeInWeek()` (pura: no lee el reloj);
 *  - el CUPO de una ocurrencia cuenta las reservas de esa fecha MÁS las
 *    heredadas sin fecha (`occurrenceQuery()`), contra `max_capacity`;
 *  - una sesión que el entrenador ya inició o cerró (`class_sessions`) no
 *    admite reservas (`occurrenceBlock()`); una fecha pasada, tampoco.
 *
 * `class.reserved_next_7d`: alguna reserva de una clase ACTIVA con fecha en
 * [hoy, hoy + 7] (días de Bogotá) o heredada sin fecha (ocupa todas las
 * ocurrencias de su clase: excluir de más es el lado seguro de una invitación).
 * `class.attended_type_last_60d`: el tipo (valor del dominio dinámico
 * `class_types`, {@see FactValues::slug()}) de la última clase a la que asistió
 * (presente o tarde) en [hoy − 60, hoy]; null si no asistió a ninguna o si no
 * es socio (el catálogo no tiene «ninguno»: null es «no hay», DF-T7).
 */
final class ClassFacts
{
    public const TABLES = ['classes', 'class_reservations', 'class_sessions', 'class_attendances', 'users', 'members'];

    public const FACTS = ['class.reserved_next_7d', 'class.attended_type_last_60d'];

    public const EVENT_UPCOMING = 'class.upcoming_with_capacity';

    public const RESERVED_WINDOW_DAYS = 7;

    public const ATTENDED_WINDOW_DAYS = 60;

    /** Lo que cuenta como haber ido (`ClassAttendance`). */
    public const ATTENDED_STATUSES = [ClassAttendance::STATUS_PRESENT, ClassAttendance::STATUS_LATE];

    /** @return array<string, array<string, bool|string|null>> por clave pedida */
    public function facts(FactPass $b): array
    {
        $socios = $b->memberIds();
        $reservados = [];
        $tipos = [];
        if ($socios !== []) {
            $hasta = FactValues::addDays($b->today, self::RESERVED_WINDOW_DAYS + 1);
            $reservados = array_fill_keys(DB::table('class_reservations as r')
                ->join('classes as c', 'c.id', '=', 'r.class_id')
                ->whereIn('r.member_id', $socios)
                ->where('c.status', 'active')
                ->where(fn ($q) => $q->whereNull('r.session_date')
                    ->orWhere(fn ($w) => $w->where('r.session_date', '>=', $b->today)->where('r.session_date', '<', $hasta)))
                ->distinct()->pluck('r.member_id')->map(fn ($id) => (int) $id)->all(), true);

            // La última asistencia de cada socio en la ventana, con el tipo de su clase.
            $filas = DB::table('class_attendances as a')
                ->join('classes as c', 'c.id', '=', 'a.class_id')
                ->whereIn('a.member_id', $socios)
                ->whereIn('a.status', self::ATTENDED_STATUSES)
                ->where('a.session_date', '>=', FactValues::addDays($b->today, -self::ATTENDED_WINDOW_DAYS))
                ->where('a.session_date', '<', FactValues::addDays($b->today, 1))
                ->orderByDesc('a.session_date')->orderByDesc('a.id')
                ->get(['a.member_id', 'a.session_date', 'c.type']);
            foreach ($filas as $f) {
                $tipos[(int) $f->member_id] ??= ['date' => FactValues::date($f->session_date), 'type' => FactValues::slug($f->type)];
            }
        }

        $salida = [];
        foreach ($b->subjects as $clave => $s) {
            $hechos = array_fill_keys(self::FACTS, null);
            if ($s->resolved() && $s->isMember()) {
                $hechos['class.reserved_next_7d'] = false;
                $ultima = null;
                foreach ($s->memberIds as $m) {
                    if (isset($reservados[$m])) {
                        $hechos['class.reserved_next_7d'] = true;
                    }
                    $t = $tipos[$m] ?? null;
                    if ($t !== null && $t['date'] !== null && ($ultima === null || $t['date'] > $ultima['date'])) {
                        $ultima = $t;
                    }
                }
                $hechos['class.attended_type_last_60d'] = $ultima['type'] ?? null;
            }
            $salida[$clave] = $hechos;
        }

        return $salida;
    }

    /**
     * Las ocurrencias de clases ACTIVAS en [hoy, hoy + `$withinDays`] (días de
     * Bogotá) que todavía admiten reservas y tienen cupo. Sin personas: es la
     * fuente del disparador; a quién invitar lo dice la audiencia.
     *
     * `class_session_id` es la fila de `class_sessions` de esa fecha si existe:
     * esas filas nacen al INICIAR la clase (`ClassSessionService`), así que una
     * ocurrencia futura casi nunca la tiene (ver el informe: Q-3).
     *
     * @return list<array{class_id: int, session_date: string, class_session_id: ?int, class_type: ?string, capacity: int, booked: int, available: int}>
     */
    public function upcomingWithCapacity(DateTimeInterface $now, int $withinDays): array
    {
        $hoy = RelativeTime::bogotaDate($now);
        $fin = FactValues::addDays($hoy, max(0, min(14, $withinDays)));

        $clases = MyClass::query()->where('status', 'active')->orderBy('id')
            ->get(['id', 'type', 'day_of_week', 'start_time', 'date_time', 'is_recurring', 'max_capacity', 'status']);

        $ocurrencias = [];
        foreach ($clases as $clase) {
            // Los lunes de las semanas que tocan la ventana (como «Organizar mi semana»).
            $lunes = Carbon::parse($hoy, RelativeTime::ZONE)->startOfWeek(Carbon::MONDAY);
            $ultimoLunes = Carbon::parse($fin, RelativeTime::ZONE)->startOfWeek(Carbon::MONDAY);
            for ($semana = $lunes->copy(); $semana->lessThanOrEqualTo($ultimoLunes); $semana->addWeek()) {
                $occ = $clase->occurrenceDateTimeInWeek($semana->copy());
                $fecha = $occ?->toDateString();
                if ($fecha !== null && $fecha >= $hoy && $fecha <= $fin) {
                    $ocurrencias[] = ['class' => $clase, 'date' => $fecha];
                }
            }
        }
        if ($ocurrencias === []) {
            return [];
        }

        $idsClases = array_values(array_unique(array_map(fn (array $o): int => (int) $o['class']->id, $ocurrencias)));
        $reservas = DB::table('class_reservations')
            ->whereIn('class_id', $idsClases)
            ->where(fn ($q) => $q->whereNull('session_date')
                ->orWhere(fn ($w) => $w->where('session_date', '>=', $hoy)->where('session_date', '<', FactValues::addDays($fin, 1))))
            ->get(['class_id', 'session_date']);
        $heredadas = [];
        $porFecha = [];
        foreach ($reservas as $r) {
            $fecha = FactValues::date($r->session_date);
            if ($fecha === null) {
                $heredadas[(int) $r->class_id] = ($heredadas[(int) $r->class_id] ?? 0) + 1;
            } else {
                $porFecha[(int) $r->class_id.'|'.$fecha] = ($porFecha[(int) $r->class_id.'|'.$fecha] ?? 0) + 1;
            }
        }
        $sesiones = [];
        foreach (DB::table('class_sessions')->whereIn('class_id', $idsClases)
            ->where('session_date', '>=', $hoy)->where('session_date', '<', FactValues::addDays($fin, 1))
            ->get(['id', 'class_id', 'session_date', 'started_at', 'ended_at']) as $ses) {
            $sesiones[(int) $ses->class_id.'|'.FactValues::date($ses->session_date)] = $ses;
        }

        $salida = [];
        foreach ($ocurrencias as $o) {
            $id = (int) $o['class']->id;
            $clave = $id.'|'.$o['date'];
            $sesion = $sesiones[$clave] ?? null;
            if ($sesion !== null && ($sesion->started_at !== null || $sesion->ended_at !== null)) {
                continue; // en curso o cerrada: no admite reservas
            }
            $ocupadas = ($porFecha[$clave] ?? 0) + ($heredadas[$id] ?? 0);
            $cupo = max(0, (int) $o['class']->max_capacity);
            if ($ocupadas >= $cupo) {
                continue;
            }
            $salida[] = [
                'class_id' => $id,
                'session_date' => $o['date'],
                'class_session_id' => $sesion !== null ? (int) $sesion->id : null,
                'class_type' => FactValues::slug($o['class']->type),
                'capacity' => $cupo,
                'booked' => $ocupadas,
                'available' => $cupo - $ocupadas,
            ];
        }
        usort($salida, fn (array $a, array $b): int => [$a['session_date'], $a['class_id']] <=> [$b['session_date'], $b['class_id']]);

        return $salida;
    }

    /**
     * Personas a las que se PODRÍA invitar a una ocurrencia: socios con
     * membresía activa (plan, sin congelar, vigente hoy) que no la tienen ya
     * reservada (ni por fecha ni heredada). Es un superconjunto acotado de
     * cualquier audiencia razonable; nunca la base entera de golpe (≤ 500 por
     * página, por `users.id`).
     *
     * @return array{candidates: list<Candidate>, next: ?int}
     */
    public function candidatesForOccurrence(DateTimeInterface $now, int $classId, string $sessionDate, ?int $classSessionId, int $limit = 500, int $afterUserId = 0): array
    {
        $limite = max(1, min(500, $limit));
        $hoy = RelativeTime::bogotaDate($now);
        $filas = DB::table('users')
            ->where('users.id', '>', $afterUserId)
            ->whereNotNull('users.plan')->where('users.plan', '<>', '')
            ->whereNull('users.membership_frozen_at')
            ->where(fn ($q) => $q->whereNull('users.membership_end_date')->orWhere('users.membership_end_date', '>=', $hoy))
            ->whereExists(fn ($q) => $q->from('members')->whereColumn('members.user_id', 'users.id'))
            ->whereNotExists(fn ($q) => $q->from('class_reservations as r')
                ->join('members as m', 'm.id', '=', 'r.member_id')
                ->whereColumn('m.user_id', 'users.id')
                ->where('r.class_id', $classId)
                // La fecha como intervalo [fecha, fecha + 1): SQLite puede guardar «Y-m-d 00:00:00».
                ->where(fn ($w) => $w->whereNull('r.session_date')
                    ->orWhere(fn ($d) => $d->where('r.session_date', '>=', $sessionDate)->where('r.session_date', '<', FactValues::addDays($sessionDate, 1)))))
            ->orderBy('users.id')
            ->limit($limite)
            ->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        $sujetos = (new SubjectResolver)->forUserIds($filas);
        $candidatos = [];
        foreach ($filas as $id) {
            if (isset($sujetos[$id])) {
                $candidatos[] = new Candidate((string) $sujetos[$id]->key, self::EVENT_UPCOMING, [
                    'class_session_id' => $classSessionId, 'class_id' => $classId, 'session_date' => $sessionDate,
                ], $id);
            }
        }

        return ['candidates' => $candidatos, 'next' => count($filas) === $limite ? $filas[count($filas) - 1] : null];
    }
}
