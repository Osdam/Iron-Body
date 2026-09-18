<?php

namespace App\Services\Reports;

use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * El periodo que se está consultando, resuelto UNA vez para todo el informe.
 *
 * Existe porque cada pantalla resolvía «este mes» por su cuenta y con reglas
 * distintas: unas en UTC, otras en hora local, otras con `subDays(30)` contra
 * `startOfMonth()`. Dos tarjetas de la misma página podían por tanto contar
 * días distintos, y nadie sabía cuál era la buena.
 *
 * DOS REGLAS QUE NO SE NEGOCIAN
 *
 *  1. El día es el del NEGOCIO (America/Bogota). La base guarda en UTC, así que
 *     «hoy» en UTC empieza a las 19:00 de Neiva: sin esto, todo lo cobrado
 *     entre las 19:00 y la medianoche se contaba en el día siguiente y el
 *     cierre de la tarde salía corto.
 *  2. Los extremos van INCLUIDOS. «Del 5 al 12» incluye el 5 completo y el 12
 *     completo, hasta las 23:59:59.999.
 *
 * El periodo anterior se deriva de este —misma longitud, pegado por detrás—
 * para que toda comparación compare cosas comparables: un mes de 30 días contra
 * uno de 31 daría una variación inventada.
 */
final class ReportRange
{
    public const TZ = Member::BUSINESS_TZ;

    /** @var list<string> */
    public const PRESETS = [
        'today', 'yesterday', 'last_7', 'this_week', 'last_week',
        'last_30', 'this_month', 'last_month', 'this_year', 'last_year', 'custom',
    ];

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $preset,
        public readonly string $label,
    ) {}

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /**
     * Construye el rango a partir de lo que pidió el CRM.
     *
     * `preset` manda; con `custom` (o con `from`/`to` sueltos) se usan las
     * fechas explícitas. Una sola fecha —`from` sin `to`— es un DÍA concreto,
     * que es justo la pregunta de «¿qué pasó el 12 de septiembre?».
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromRequest(array $data): self
    {
        $preset = (string) ($data['preset'] ?? ($data['from'] ?? null ? 'custom' : 'last_30'));
        $hoy = self::today();

        if ($preset === 'custom' || isset($data['from']) || isset($data['to'])) {
            $from = self::parse($data['from'] ?? null) ?? $hoy;
            $to = self::parse($data['to'] ?? null) ?? $from;

            if ($to->lessThan($from)) {
                [$from, $to] = [$to, $from];
            }

            return new self($from, $to->endOfDay(), 'custom', self::describe($from, $to));
        }

        [$from, $to] = match ($preset) {
            'today' => [$hoy, $hoy],
            'yesterday' => [$hoy->subDay(), $hoy->subDay()],
            'last_7' => [$hoy->subDays(6), $hoy],
            'this_week' => [$hoy->startOfWeek(), $hoy],
            'last_week' => [$hoy->subWeek()->startOfWeek(), $hoy->subWeek()->endOfWeek()->startOfDay()],
            'last_30' => [$hoy->subDays(29), $hoy],
            'this_month' => [$hoy->startOfMonth(), $hoy],
            'last_month' => [$hoy->subMonthNoOverflow()->startOfMonth(), $hoy->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_year' => [$hoy->startOfYear(), $hoy],
            'last_year' => [$hoy->subYear()->startOfYear(), $hoy->subYear()->endOfYear()->startOfDay()],
            default => throw ValidationException::withMessages([
                'preset' => ['Periodo desconocido: '.$preset],
            ]),
        };

        return new self($from, $to->endOfDay(), $preset, self::presetLabel($preset));
    }

    /**
     * El periodo anterior con el que se compara: misma longitud, terminando
     * justo antes de que empiece este.
     *
     * Para los meses naturales se usa el mes anterior completo y no «30 días
     * antes»: comparar septiembre con «los 30 días previos al 1 de septiembre»
     * mezclaría media semana de agosto con otra de julio.
     */
    public function previous(): self
    {
        if ($this->preset === 'this_month' || $this->preset === 'last_month') {
            $inicio = $this->from->subMonthNoOverflow()->startOfMonth();
            // Mismo trozo de mes: si el actual va del 1 al 17, el anterior también.
            $fin = min(
                $inicio->addDays($this->from->diffInDays($this->to->startOfDay())),
                $inicio->endOfMonth()->startOfDay(),
            );

            return new self($inicio, $fin->endOfDay(), 'previous', 'Mes anterior');
        }

        if ($this->preset === 'this_year' || $this->preset === 'last_year') {
            $inicio = $this->from->subYear()->startOfYear();

            return new self($inicio, $inicio->addDays($this->from->diffInDays($this->to->startOfDay()))->endOfDay(), 'previous', 'Año anterior');
        }

        $dias = $this->days();
        $fin = $this->from->subDay();

        return new self($fin->subDays($dias - 1), $fin->endOfDay(), 'previous', 'Periodo anterior');
    }

    /** Días que abarca, con los dos extremos incluidos. */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /**
     * Cómo agrupar la serie para que se lea: por días en un mes, por semanas en
     * un trimestre, por meses en un año. Un año por días son 365 barras que no
     * dicen nada.
     */
    public function granularity(): string
    {
        return match (true) {
            $this->days() <= 62 => 'day',
            $this->days() <= 240 => 'week',
            default => 'month',
        };
    }

    /**
     * Los dos instantes UTC que delimitan el periodo, que es lo que entienden
     * las columnas `timestamp` de la base.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcBounds(): array
    {
        return [$this->from->utc(), $this->to->utc()];
    }

    /** @return array{from: string, to: string, preset: string, label: string, days: int, granularity: string} */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'preset' => $this->preset,
            'label' => $this->label,
            'days' => $this->days(),
            'granularity' => $this->granularity(),
        ];
    }

    /** Opciones del selector, con su etiqueta, para no duplicarlas en el CRM. */
    public static function presetOptions(): array
    {
        return array_map(
            fn (string $p) => ['value' => $p, 'label' => self::presetLabel($p)],
            self::PRESETS,
        );
    }

    private static function presetLabel(string $preset): string
    {
        return match ($preset) {
            'today' => 'Hoy',
            'yesterday' => 'Ayer',
            'last_7' => 'Últimos 7 días',
            'this_week' => 'Esta semana',
            'last_week' => 'Semana pasada',
            'last_30' => 'Últimos 30 días',
            'this_month' => 'Este mes',
            'last_month' => 'Mes pasado',
            'this_year' => 'Este año',
            'last_year' => 'Año anterior',
            'custom' => 'Rango personalizado',
            default => $preset,
        };
    }

    private static function describe(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $fmt = fn (CarbonImmutable $d) => $d->locale('es')->isoFormat('D MMM YYYY');

        return $from->isSameDay($to) ? $fmt($from) : $fmt($from).' – '.$fmt($to);
    }

    private static function parse(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr(trim($valor), 0, 10), self::TZ)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['from' => ['Fecha inválida: '.$valor]]);
        }
    }
}
