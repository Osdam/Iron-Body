<?php

namespace App\Services\Membership;

use App\Models\Plan;
use Carbon\CarbonImmutable;

/**
 * LAS REGLAS DE ACCESO DE UN PLAN, leídas una sola vez y bien.
 *
 * Un plan puede vender cuatro cosas a la vez, y hasta ahora solo sabía vender la
 * primera:
 *
 *   vigencia   → hasta cuándo vale (`duration_days`, la de siempre)
 *   entradas   → cuántas veces puede entrar dentro de esa vigencia
 *   días       → qué días de la semana
 *   franjas    → en qué horas («horas valle»)
 *
 * Esta clase es el traductor: coge lo que está guardado en la fila del plan
 * —columnas nuevas, valores viejos, nulos, JSON escrito a mano— y lo devuelve
 * normalizado y en el mismo formato SIEMPRE. Existe porque las tres cosas que
 * deciden en la puerta (el CRM, el terminal de recepción y este backend) tienen
 * que estar de acuerdo, y con cada una interpretando el JSON a su manera no lo
 * estarían.
 *
 * Todo lo que falta significa «sin restricción»: un plan de los de antes queda
 * ilimitado, sin tope de entradas, todos los días y a toda hora.
 */
class PlanAccessRules
{
    /** Entra cuantas veces quiera mientras la membresía esté vigente. */
    public const MODE_UNLIMITED = 'unlimited';

    /** Por consumo: cada DÍA que entra gasta una entrada. */
    public const MODE_ENTRIES = 'entries';

    public const MODES = [self::MODE_UNLIMITED, self::MODE_ENTRIES];

    /** Tope de entradas por periodo. Más que eso ya es un plan ilimitado. */
    public const MAX_ENTRIES = 400;

    /** Franjas por plan. Seis cubren de sobra mañana, mediodía, tarde y noche. */
    public const MAX_WINDOWS = 6;

    /** Nombres de los días, en el orden ISO (1 lunes … 7 domingo). */
    public const DAY_NAMES = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    public const DAY_SHORT = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

    public function __construct(
        public readonly string $mode,
        /** Entradas incluidas, o null cuando no se limita. */
        public readonly ?int $entries,
        /**
         * Días ISO permitidos. Vacío = todos.
         *
         * @var list<int>
         */
        public readonly array $days,
        /**
         * Franjas horarias permitidas. Vacío = a toda hora.
         *
         * @var list<array{from: string, to: string, label: string|null}>
         */
        public readonly array $windows,
    ) {}

    /** Sin restricciones: lo que era un plan antes de que esto existiera. */
    public static function none(): self
    {
        return new self(self::MODE_UNLIMITED, null, [], []);
    }

    public static function fromPlan(?Plan $plan): self
    {
        if (! $plan) {
            return self::none();
        }

        return self::fromArray([
            'access_mode' => $plan->access_mode,
            'entry_credits' => $plan->entry_credits,
            'access_days' => $plan->access_days,
            'access_windows' => $plan->access_windows,
        ]);
    }

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): self
    {
        $mode = is_string($raw['access_mode'] ?? null) ? strtolower(trim($raw['access_mode'])) : '';
        $mode = in_array($mode, self::MODES, true) ? $mode : self::MODE_UNLIMITED;

        $entries = $raw['entry_credits'] ?? null;
        $entries = ($entries === null || $entries === '') ? null : max(0, (int) $entries);

        // Un plan por consumo SIN número de entradas no es un plan por consumo:
        // sería una puerta cerrada. Se degrada a ilimitado en vez de dejar a
        // alguien fuera por un campo a medio llenar.
        if ($mode === self::MODE_ENTRIES && ($entries === null || $entries <= 0)) {
            $mode = self::MODE_UNLIMITED;
            $entries = null;
        }
        if ($mode === self::MODE_UNLIMITED) {
            $entries = null;
        }

        return new self(
            $mode,
            $entries,
            self::normalizeDays($raw['access_days'] ?? null),
            self::normalizeWindows($raw['access_windows'] ?? null),
        );
    }

    public function isByEntries(): bool
    {
        return $this->mode === self::MODE_ENTRIES;
    }

    public function restrictsDays(): bool
    {
        return $this->days !== [] && count($this->days) < 7;
    }

    public function restrictsHours(): bool
    {
        return $this->windows !== [];
    }

    public function hasAnyRestriction(): bool
    {
        return $this->isByEntries() || $this->restrictsDays() || $this->restrictsHours();
    }

    public function allowsDay(CarbonImmutable $moment): bool
    {
        return ! $this->restrictsDays() || in_array((int) $moment->isoWeekday(), $this->days, true);
    }

    /**
     * ¿La hora cae en alguna franja? Una franja que cruza la medianoche
     * (22:00 → 02:00) vale para los dos lados: el gimnasio nocturno existe.
     */
    public function allowsHour(CarbonImmutable $moment): bool
    {
        if (! $this->restrictsHours()) {
            return true;
        }

        $minuto = ((int) $moment->format('G')) * 60 + (int) $moment->format('i');

        foreach ($this->windows as $franja) {
            $desde = self::toMinutes($franja['from']);
            $hasta = self::toMinutes($franja['to']);

            $dentro = $desde <= $hasta
                ? ($minuto >= $desde && $minuto <= $hasta)
                : ($minuto >= $desde || $minuto <= $hasta);

            if ($dentro) {
                return true;
            }
        }

        return false;
    }

    /** La franja que viene después de `$moment`, para poder decir «vuelve a las …». */
    public function nextWindowAfter(CarbonImmutable $moment): ?string
    {
        if (! $this->restrictsHours()) {
            return null;
        }

        $minuto = ((int) $moment->format('G')) * 60 + (int) $moment->format('i');
        $siguientes = [];
        foreach ($this->windows as $franja) {
            if (self::toMinutes($franja['from']) > $minuto) {
                $siguientes[] = $franja['from'];
            }
        }
        sort($siguientes);

        // Sin ninguna por delante, la próxima es la primera de mañana.
        return $siguientes[0] ?? $this->windows[0]['from'];
    }

    /** Cómo se dicen los días permitidos: «Lun, Mié y Vie». */
    public function daysLabel(): ?string
    {
        if (! $this->restrictsDays()) {
            return null;
        }

        $nombres = array_map(fn (int $d): string => self::DAY_SHORT[$d], $this->days);

        if (count($nombres) === 1) {
            return $nombres[0];
        }

        $ultimo = array_pop($nombres);

        return implode(', ', $nombres).' y '.$ultimo;
    }

    /** Cómo se dicen las franjas: «06:00–10:00 y 14:00–17:00». */
    public function hoursLabel(): ?string
    {
        if (! $this->restrictsHours()) {
            return null;
        }

        return implode(' y ', array_map(fn (array $f): string => $f['from'].'–'.$f['to'], $this->windows));
    }

    /**
     * Las reglas tal como viajan al CRM y al terminal de recepción. Es el mismo
     * contrato en los dos sitios: si aquí se añade algo, los dos lo reciben.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'mode_label' => $this->isByEntries() ? 'Por consumo' : 'Ilimitado',
            'entries' => $this->entries,
            'days' => $this->days,
            'days_label' => $this->daysLabel(),
            'windows' => $this->windows,
            'hours_label' => $this->hoursLabel(),
            'restricted' => $this->hasAnyRestriction(),
        ];
    }

    /**
     * Días ISO, únicos y en orden. Acepta lo que puede haber guardado: lista de
     * números, de textos, o el JSON sin decodificar.
     *
     * @return list<int>
     */
    public static function normalizeDays(mixed $raw): array
    {
        $lista = self::decode($raw);
        if ($lista === []) {
            return [];
        }

        $dias = [];
        foreach ($lista as $valor) {
            // Un nulo o un texto vacío no son el domingo: sin esto, la casilla
            // sin marcar de un formulario se convertiría en (int) 0 y de ahí en
            // un día permitido que nadie eligió.
            if (! is_numeric($valor)) {
                continue;
            }

            $dia = (int) $valor;
            // El 0 como domingo aparece en cualquier calendario de JavaScript.
            if ($dia === 0) {
                $dia = 7;
            }
            if ($dia >= 1 && $dia <= 7) {
                $dias[$dia] = $dia;
            }
        }

        $dias = array_values($dias);
        sort($dias);

        return $dias;
    }

    /**
     * Franjas válidas, ordenadas y sin basura. Una franja sin las dos horas, o
     * con las dos iguales, no restringe nada y se descarta.
     *
     * @return list<array{from: string, to: string, label: string|null}>
     */
    public static function normalizeWindows(mixed $raw): array
    {
        $franjas = [];

        foreach (self::decode($raw) as $item) {
            if (is_string($item) && str_contains($item, '-')) {
                // «06:00-10:00» escrito a mano.
                [$desde, $hasta] = array_pad(explode('-', $item, 2), 2, null);
                $item = ['from' => $desde, 'to' => $hasta];
            }
            if (! is_array($item)) {
                continue;
            }

            $desde = self::toTime($item['from'] ?? $item['start'] ?? null);
            $hasta = self::toTime($item['to'] ?? $item['end'] ?? null);

            if ($desde === null || $hasta === null || $desde === $hasta) {
                continue;
            }

            $etiqueta = isset($item['label']) && trim((string) $item['label']) !== ''
                ? mb_substr(trim((string) $item['label']), 0, 40)
                : null;

            $franjas[$desde.$hasta] = ['from' => $desde, 'to' => $hasta, 'label' => $etiqueta];
        }

        $franjas = array_values($franjas);
        usort($franjas, fn (array $a, array $b): int => self::toMinutes($a['from']) <=> self::toMinutes($b['from']));

        return array_slice($franjas, 0, self::MAX_WINDOWS);
    }

    /** «6», «6:0», «06:00:00» → «06:00». Null si no es una hora. */
    public static function toTime(mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || is_array($raw)) {
            return null;
        }

        if (! preg_match('/^(\d{1,2})(?::(\d{1,2}))?/', trim((string) $raw), $m)) {
            return null;
        }

        $hora = (int) $m[1];
        $minuto = (int) ($m[2] ?? 0);

        if ($hora > 24 || $minuto > 59) {
            return null;
        }
        // Las 24:00 son el final del día y aparecen en los horarios escritos a
        // mano; hacia dentro son las 23:59, para que sigan siendo una hora real.
        if ($hora === 24) {
            return '23:59';
        }

        return sprintf('%02d:%02d', $hora, $minuto);
    }

    private static function toMinutes(string $hora): int
    {
        [$h, $m] = array_pad(explode(':', $hora, 2), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    /** @return list<mixed> */
    private static function decode(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? array_values($raw) : [];
    }
}
