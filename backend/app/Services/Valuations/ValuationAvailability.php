<?php

namespace App\Services\Valuations;

use App\Exceptions\ValuationException;
use App\Models\MyClass;
use App\Models\ValuationAppointment;
use App\Models\ValuationAvailabilityBlock;
use App\Models\ValuationAvailabilityRule;
use App\Models\ValuationSetting;
use App\Services\Classes\ClassBookingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cuándo puede un entrenador hacer una valoración (contrato §3, reglas 6–10).
 *
 *  · Franjas: las reglas semanales ACTIVAS y VIGENTES de ese día de la semana en
 *    Bogotá. Sin franjas no hay huecos.
 *  · Bloqueos del entrenador (UTC).
 *  · Clases ACTIVAS del entrenador ese día, con la misma ocurrencia que cuentan la
 *    app y el CRM ({@see ClassBookingService::isOccurrence} / `occurrenceStart`) y
 *    su `end_time` de pared. Solo si `block_on_class_conflict`. Una clase
 *    `inactive` o `finished` no ocupa. SOLO SE LEEN: nada de aquí escribe en
 *    clases.
 *  · Otras citas activas del entrenador, ampliadas con `buffer_minutes` a ambos
 *    lados, y del socio (con cualquier entrenador, sin margen).
 *
 * Intervalos semiabiertos `[inicio, fin)`: los bordes que se tocan no chocan.
 *
 * Toda consulta compara instantes UTC formateados explícitamente: el query
 * builder escribe la hora de PARED del Carbon que recibe, y un Carbon en Bogotá
 * desplazaría la comparación cinco horas.
 */
final class ValuationAvailability
{
    public function __construct(
        private readonly ClassBookingService $classes,
    ) {}

    /** `Y-m-d H:i:s` en UTC, para comparar con las columnas `timestamp`. */
    public static function db(CarbonInterface $instante): string
    {
        return Carbon::instance($instante)->copy()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * Las reglas activas y vigentes de ese día (fecha de Bogotá), por hora.
     *
     * @return Collection<int, ValuationAvailabilityRule>
     */
    public function rulesFor(int $trainerId, string $fecha): Collection
    {
        return ValuationAvailabilityRule::query()
            ->where('trainer_id', $trainerId)
            ->where('active', true)
            ->where('weekday', ValuationTime::isoWeekday($fecha))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $fecha))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $fecha))
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Franjas de ese día como instantes UTC.
     *
     * @return list<array{start: Carbon, end: Carbon, start_hm: string, end_hm: string}>
     */
    public function windows(int $trainerId, string $fecha): array
    {
        return $this->rulesFor($trainerId, $fecha)
            ->map(fn (ValuationAvailabilityRule $r) => [
                'start' => ValuationTime::wallToUtc($fecha, $r->startHm()),
                'end' => ValuationTime::wallToUtc($fecha, $r->endHm()),
                'start_hm' => $r->startHm(),
                'end_hm' => $r->endHm(),
            ])
            ->filter(fn (array $w) => $w['end']->gt($w['start']))
            ->values()
            ->all();
    }

    /** @return Collection<int, ValuationAvailabilityBlock> */
    public function blocksOverlapping(int $trainerId, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return ValuationAvailabilityBlock::query()
            ->where('trainer_id', $trainerId)
            ->where('starts_at', '<', self::db($hasta))
            ->where('ends_at', '>', self::db($desde))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Ocurrencias de las clases ACTIVAS del entrenador en esa fecha de Bogotá.
     *
     * @return list<array{start: Carbon, end: Carbon, name: string}>
     */
    public function classIntervals(int $trainerId, string $fecha): array
    {
        $clases = MyClass::query()
            ->where('trainer_id', $trainerId)
            ->whereRaw('LOWER(status) IN (?, ?)', ['active', 'activa'])
            ->get();

        $out = [];
        foreach ($clases as $clase) {
            if (! $this->classes->isOccurrence($clase, $fecha)) {
                continue;
            }
            $inicio = $this->classes->occurrenceStart($clase, $fecha);
            if ($inicio === null) {
                continue;
            }
            $inicio = $inicio->copy()->utc();
            $fin = preg_match('/^(\d{1,2}):(\d{2})/', (string) $clase->end_time, $m)
                ? ValuationTime::wallToUtc($fecha, sprintf('%02d:%s', (int) $m[1], $m[2]))
                : $inicio->copy()->addMinutes(max(1, (int) ($clase->duration_minutes ?: 60)));
            if (! $fin->gt($inicio)) {
                continue;
            }
            $out[] = ['start' => $inicio, 'end' => $fin, 'name' => (string) ($clase->name ?: 'Clase')];
        }

        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $out;
    }

    /**
     * Citas ACTIVAS del entrenador que tocan `[desde, hasta)`.
     *
     * @return Collection<int, ValuationAppointment>
     */
    public function trainerAppointments(int $trainerId, CarbonInterface $desde, CarbonInterface $hasta, ?int $excluir = null): Collection
    {
        return ValuationAppointment::query()
            ->where('trainer_id', $trainerId)
            ->whereIn('status', ValuationAppointment::ACTIVE_STATUSES)
            ->when($excluir !== null, fn ($q) => $q->where('id', '!=', $excluir))
            ->where('starts_at', '<', self::db($hasta))
            ->where('ends_at', '>', self::db($desde))
            ->orderBy('starts_at')
            ->get(['id', 'uuid', 'trainer_id', 'member_id', 'starts_at', 'ends_at', 'status']);
    }

    /**
     * Reglas 6–10 sobre `[inicio, fin)`. Se llama CON las filas del entrenador y
     * del socio ya bloqueadas: lo que se comprueba aquí no puede cambiar hasta
     * que la transacción termine.
     *
     * @throws ValuationException 409 con el código de la primera regla que falla
     */
    public function assertBookable(int $trainerId, int $memberId, Carbon $inicio, Carbon $fin, ValuationSetting $ajustes, ?int $excluir = null): void
    {
        $inicio = $inicio->copy()->utc();
        $fin = $fin->copy()->utc();
        $fecha = ValuationTime::local($inicio)->toDateString();

        // 6. Entera dentro de UNA franja activa y vigente de ese día.
        $dentro = false;
        foreach ($this->windows($trainerId, $fecha) as $w) {
            if ($inicio->gte($w['start']) && $fin->lte($w['end'])) {
                $dentro = true;
                break;
            }
        }
        if (! $dentro) {
            throw ValuationException::outsideAvailability();
        }

        // 7. Bloqueos.
        $bloqueo = $this->blocksOverlapping($trainerId, $inicio, $fin)->first();
        if ($bloqueo !== null) {
            throw ValuationException::blocked((string) $bloqueo->kind);
        }

        // 8. Clases activas del entrenador ese día.
        if ($ajustes->block_on_class_conflict) {
            foreach ($this->classIntervals($trainerId, $fecha) as $clase) {
                if ($clase['start']->lt($fin) && $clase['end']->gt($inicio)) {
                    throw ValuationException::classConflict($clase['name']);
                }
            }
        }

        // 9. Otra cita activa del entrenador, con el margen a ambos lados.
        $margen = max(0, (int) $ajustes->buffer_minutes);
        if ($this->trainerAppointments($trainerId, $inicio->copy()->subMinutes($margen), $fin->copy()->addMinutes($margen), $excluir)->isNotEmpty()) {
            throw ValuationException::slotTaken();
        }

        // 10. Otra cita activa del SOCIO, con cualquier entrenador.
        $delSocio = ValuationAppointment::query()
            ->where('member_id', $memberId)
            ->whereIn('status', ValuationAppointment::ACTIVE_STATUSES)
            ->when($excluir !== null, fn ($q) => $q->where('id', '!=', $excluir))
            ->where('starts_at', '<', self::db($fin))
            ->where('ends_at', '>', self::db($inicio))
            ->exists();
        if ($delSocio) {
            throw ValuationException::memberOverlap();
        }
    }

    /**
     * Huecos de un día para el CRM: franjas, horas libres y lo ocupado (sin
     * nombres de socios).
     *
     * @return array{date: string, timezone: string, windows: list<array<string,string>>, slots: list<array<string,string>>, busy: list<array<string,string>>}
     */
    public function slots(int $trainerId, string $fecha, int $duracion, ValuationSetting $ajustes, ?int $excluir = null): array
    {
        $diaInicio = ValuationTime::dayStartUtc($fecha);
        $diaFin = ValuationTime::dayStartUtc(ValuationTime::local($diaInicio)->addDay()->toDateString());

        $ventanas = $this->windows($trainerId, $fecha);
        $bloqueos = $this->blocksOverlapping($trainerId, $diaInicio, $diaFin);
        $clases = $ajustes->block_on_class_conflict ? $this->classIntervals($trainerId, $fecha) : [];
        $margen = max(0, (int) $ajustes->buffer_minutes);
        // El margen puede asomar al día anterior o siguiente.
        $citas = $this->trainerAppointments($trainerId, $diaInicio->copy()->subMinutes($margen), $diaFin->copy()->addMinutes($margen), $excluir);

        $ocupado = [];
        foreach ($bloqueos as $b) {
            $ocupado[] = $this->busy($b->starts_at, $b->ends_at, $diaInicio, $diaFin, 'block');
        }
        foreach ($clases as $c) {
            $ocupado[] = $this->busy($c['start'], $c['end'], $diaInicio, $diaFin, 'class');
        }
        foreach ($citas as $a) {
            if ($a->starts_at->lt($diaFin) && $a->ends_at->gt($diaInicio)) {
                $ocupado[] = $this->busy($a->starts_at, $a->ends_at, $diaInicio, $diaFin, 'appointment');
            }
        }
        usort($ocupado, fn ($x, $y) => [$x['local_start'], $x['local_end']] <=> [$y['local_start'], $y['local_end']]);

        $huecos = [];
        $ahora = Carbon::now()->utc();
        $minimo = $ahora->copy()->addMinutes(max(0, (int) $ajustes->min_notice_minutes));
        $dentroDelHorizonte = $fecha <= ValuationTime::today()->addDays((int) $ajustes->max_horizon_days)->toDateString();
        $paso = max(1, (int) $ajustes->slot_interval_minutes);

        if ($dentroDelHorizonte) {
            foreach ($ventanas as $w) {
                for ($t = $w['start']->copy(); $t->copy()->addMinutes($duracion)->lte($w['end']); $t->addMinutes($paso)) {
                    $fin = $t->copy()->addMinutes($duracion);
                    if ($t->lt($ahora) || $t->lt($minimo)) {
                        continue;
                    }
                    if ($this->choca($t, $fin, $bloqueos, $clases, $citas, $margen)) {
                        continue;
                    }
                    $huecos[] = [
                        'starts_at' => $t->copy()->utc()->toIso8601String(),
                        'local_start' => ValuationTime::hm($t),
                        'local_end' => ValuationTime::hm($fin),
                    ];
                }
            }
        }

        return [
            'date' => $fecha,
            'timezone' => ValuationTime::TZ,
            'windows' => array_map(fn ($w) => ['start' => $w['start_hm'], 'end' => $w['end_hm']], $ventanas),
            'slots' => $huecos,
            'busy' => $ocupado,
        ];
    }

    /**
     * @param  Collection<int, ValuationAvailabilityBlock>  $bloqueos
     * @param  list<array{start: Carbon, end: Carbon, name: string}>  $clases
     * @param  Collection<int, ValuationAppointment>  $citas
     */
    private function choca(Carbon $inicio, Carbon $fin, Collection $bloqueos, array $clases, Collection $citas, int $margen): bool
    {
        foreach ($bloqueos as $b) {
            if ($b->starts_at->lt($fin) && $b->ends_at->gt($inicio)) {
                return true;
            }
        }
        foreach ($clases as $c) {
            if ($c['start']->lt($fin) && $c['end']->gt($inicio)) {
                return true;
            }
        }
        $desde = $inicio->copy()->subMinutes($margen);
        $hasta = $fin->copy()->addMinutes($margen);
        foreach ($citas as $a) {
            if ($a->starts_at->lt($hasta) && $a->ends_at->gt($desde)) {
                return true;
            }
        }

        return false;
    }

    /** Un tramo ocupado recortado al día. `24:00` = hasta el final del día. */
    private function busy(CarbonInterface $inicio, CarbonInterface $fin, Carbon $diaInicio, Carbon $diaFin, string $motivo): array
    {
        $desde = Carbon::instance($inicio)->max($diaInicio);
        $hasta = Carbon::instance($fin)->min($diaFin);

        return [
            'local_start' => ValuationTime::hm($desde),
            'local_end' => $hasta->gte($diaFin) ? '24:00' : ValuationTime::hm($hasta),
            'reason' => $motivo,
            'label' => 'Ocupado',
        ];
    }
}
