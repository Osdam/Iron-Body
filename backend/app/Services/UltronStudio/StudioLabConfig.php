<?php

namespace App\Services\UltronStudio;

/**
 * La configuración del laboratorio de IA (contrato H4 §A1), interpretada una
 * vez y sin secretos. Sale de `config('ultron_studio.lab')` (que a su vez sale
 * del entorno y se cachea con `config:cache`) y no lee nada más: ni la
 * configuración del motor, ni la de IRON IA, ni la base, ni el reloj.
 *
 * LA CLAVE NO SALE DE AQUÍ. Esta clase sabe si hay clave y si es la misma que
 * la de OpenAI que ya usan IRON IA y el cerebro legado, comparando HUELLAS
 * (sha256): la de la otra clave la calcula `config/ultron_studio.php`
 * (`openai.forbidden_key_sha256`), para que ningún fichero del laboratorio
 * tenga que nombrar la configuración ajena (una prueba por tokens lo vigila).
 * Ningún método devuelve, registra ni guarda una clave: solo
 * {@see StudioLabOpenAiClient} la lee, en el momento de llamar.
 *
 * CUATRO CERROJOS ANTES DE GASTAR (contrato, «Protecciones intocables»): la
 * bandera apagada ({@see $enabled}), el driver `fake` ({@see $mode}), el
 * presupuesto diario 0 y la clave ausente o compartida. Los dos últimos, con
 * el modelo y los precios, son los motivos de {@see reasons()}, que solo
 * cuentan en `openai`: en `fake` no hay nada que pagar.
 *
 * Los topes duros se vuelven a aplicar aquí aunque la configuración ya los
 * traiga: una configuración puesta a mano (en una prueba o en un tinker) no
 * puede saltárselos.
 */
final class StudioLabConfig
{
    public const MODE_FAKE = 'fake';

    public const MODE_OPENAI = 'openai';

    public const MODES = [self::MODE_FAKE, self::MODE_OPENAI];

    /** El driver no es `fake` ni `openai`: no se cae en silencio a ninguno de los dos. */
    public const REASON_INVALID_DRIVER = 'invalid_driver';

    public const REASON_MISSING_KEY = 'missing_key';

    public const REASON_SHARED_KEY = 'shared_key';

    public const REASON_MISSING_MODEL = 'missing_model';

    public const REASON_MISSING_PRICE = 'missing_price';

    public const REASON_ZERO_BUDGET = 'zero_budget';

    /** Los motivos de `lab_not_configured`, en el orden en que salen. */
    public const REASONS = [
        self::REASON_INVALID_DRIVER,
        self::REASON_MISSING_KEY,
        self::REASON_SHARED_KEY,
        self::REASON_MISSING_MODEL,
        self::REASON_MISSING_PRICE,
        self::REASON_ZERO_BUDGET,
    ];

    // ── Fijos en código (contrato §A1) ──────────────────────────────────────

    /** Una ejecución en cola caduca a los 30 minutos. */
    public const QUEUED_EXPIRY_MINUTES = 30;

    /** Una ejecución dura como mucho 30 minutos. */
    public const RUN_DEADLINE_MINUTES = 30;

    /** Solo hay un caso en curso a la vez en todo el sistema. */
    public const MAX_RUNNING_CASES = 1;

    /** Dos errores de proveedor seguidos paran la ejecución. */
    public const MAX_CONSECUTIVE_PROVIDER_ERRORS = 2;

    /** El día del presupuesto es el de Bogotá, nunca el UTC. */
    public const BUDGET_TIMEZONE = 'America/Bogota';

    // ── Defectos y topes duros (contrato §A1) ───────────────────────────────

    public const DEFAULT_RUN_BUDGET_USD = '0.50';

    public const DEFAULT_MAX_RUNS_PER_DAY = 20;

    public const DEFAULT_MAX_CASES_PER_RUN = 12;

    public const HARD_MAX_CASES_PER_RUN = 20;

    public const DEFAULT_ACTOR_MAX_OUTPUT_TOKENS = 400;

    public const HARD_MAX_ACTOR_OUTPUT_TOKENS = 800;

    public const DEFAULT_JUDGE_MAX_OUTPUT_TOKENS = 900;

    public const HARD_MAX_JUDGE_OUTPUT_TOKENS = 1600;

    /** El mínimo de `max_output_tokens` que admite la Responses API. */
    public const MIN_OUTPUT_TOKENS = 16;

    public const DEFAULT_TIMEOUT_SECONDS = 60;

    /** El job tiene 150 s: dos llamadas de 60 s caben con margen. */
    public const HARD_MAX_TIMEOUT_SECONDS = 60;

    /** Por debajo, una llamada a un modelo caduca casi siempre y se cobra la reserva. */
    public const MIN_TIMEOUT_SECONDS = 10;

    /** Lo que se admite como nombre de modelo (lo valida OpenAI; aquí, solo que no sea basura). */
    private const MODEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,63}$/';

    /** `reasoning.effort`: una palabra; los valores que admite cada modelo los decide OpenAI. */
    private const EFFORT_PATTERN = '/^[a-z]{1,16}$/';

    /**
     * @param  ?int  $priceInputMicros  micro-US$ por millón de tokens de entrada; null = sin precio
     * @param  ?int  $priceOutputMicros  micro-US$ por millón de tokens de salida; null = sin precio
     */
    private function __construct(
        public readonly bool $enabled,
        /** `fake`, `openai` o el valor no válido tal como llegó (recortado a 16 caracteres). */
        public readonly string $driver,
        public readonly bool $hasKey,
        public readonly bool $sharedKey,
        public readonly ?string $model,
        public readonly ?int $priceInputMicros,
        public readonly ?int $priceOutputMicros,
        public readonly int $dailyBudgetMicros,
        public readonly int $runBudgetMicros,
        public readonly int $maxRunsPerDay,
        public readonly int $maxCasesPerRun,
        public readonly int $actorMaxOutputTokens,
        public readonly int $judgeMaxOutputTokens,
        public readonly int $timeoutSeconds,
        public readonly ?float $temperature,
        public readonly ?string $reasoningEffort,
    ) {}

    /** La configuración en vigor (`config('ultron_studio.lab')`). */
    public static function current(): self
    {
        $lab = config('ultron_studio.lab');

        return self::fromArray(is_array($lab) ? $lab : []);
    }

    /**
     * La misma interpretación sobre un array con la forma del bloque `lab`.
     *
     * @param  array<string,mixed>  $lab
     */
    public static function fromArray(array $lab): self
    {
        $openai = is_array($lab['openai'] ?? null) ? $lab['openai'] : [];

        $clave = is_string($openai['api_key'] ?? null) ? trim($openai['api_key']) : '';
        $otra = $openai['forbidden_key_sha256'] ?? null;
        // Se compara huella con huella y en tiempo constante; ninguna de las dos claves sale de aquí.
        $compartida = $clave !== '' && is_string($otra) && $otra !== '' && hash_equals($otra, hash('sha256', $clave));

        $driver = strtolower(trim(is_string($lab['driver'] ?? null) ? $lab['driver'] : self::MODE_FAKE));

        $modelo = is_string($openai['model'] ?? null) ? trim($openai['model']) : '';

        $esfuerzo = is_string($openai['reasoning_effort'] ?? null) ? strtolower(trim($openai['reasoning_effort'])) : '';

        return new self(
            enabled: ($lab['enabled'] ?? false) === true,
            driver: mb_substr($driver, 0, 16),
            hasKey: $clave !== '',
            sharedKey: $compartida,
            model: preg_match(self::MODEL_PATTERN, $modelo) === 1 ? $modelo : null,
            priceInputMicros: self::positiveMicros($lab['price_input_usd_per_mtok'] ?? null),
            priceOutputMicros: self::positiveMicros($lab['price_output_usd_per_mtok'] ?? null),
            dailyBudgetMicros: StudioLabCost::microsFromUsd($lab['daily_budget_usd'] ?? null) ?? 0,
            runBudgetMicros: StudioLabCost::microsFromUsd($lab['run_budget_usd'] ?? self::DEFAULT_RUN_BUDGET_USD) ?? 0,
            maxRunsPerDay: max(0, self::integer($lab['max_runs_per_day'] ?? null, self::DEFAULT_MAX_RUNS_PER_DAY)),
            maxCasesPerRun: self::clamp(self::integer($lab['max_cases_per_run'] ?? null, self::DEFAULT_MAX_CASES_PER_RUN), 1, self::HARD_MAX_CASES_PER_RUN),
            actorMaxOutputTokens: self::clamp(self::integer($lab['actor_max_output_tokens'] ?? null, self::DEFAULT_ACTOR_MAX_OUTPUT_TOKENS), self::MIN_OUTPUT_TOKENS, self::HARD_MAX_ACTOR_OUTPUT_TOKENS),
            judgeMaxOutputTokens: self::clamp(self::integer($lab['judge_max_output_tokens'] ?? null, self::DEFAULT_JUDGE_MAX_OUTPUT_TOKENS), self::MIN_OUTPUT_TOKENS, self::HARD_MAX_JUDGE_OUTPUT_TOKENS),
            timeoutSeconds: self::clamp(self::integer($lab['timeout_seconds'] ?? null, self::DEFAULT_TIMEOUT_SECONDS), self::MIN_TIMEOUT_SECONDS, self::HARD_MAX_TIMEOUT_SECONDS),
            temperature: self::temperature($openai['temperature'] ?? null),
            reasoningEffort: preg_match(self::EFFORT_PATTERN, $esfuerzo) === 1 ? $esfuerzo : null,
        );
    }

    /**
     * El modo de las ejecuciones: `fake` u `openai`. Con un driver no válido
     * es null: no hay modo y nada se ejecuta.
     */
    public function mode(): ?string
    {
        return in_array($this->driver, self::MODES, true) ? $this->driver : null;
    }

    public function isOpenAi(): bool
    {
        return $this->mode() === self::MODE_OPENAI;
    }

    public function isFake(): bool
    {
        return $this->mode() === self::MODE_FAKE;
    }

    /** ¿Se puede ejecutar en este modo? En `fake`, siempre; en `openai`, sin ningún motivo pendiente. */
    public function configured(): bool
    {
        return $this->reasons() === [];
    }

    /**
     * Por qué no se puede ejecutar (`lab_not_configured {reasons}`), en el
     * orden de {@see REASONS}. En `fake` no hay ninguno; con un driver no
     * válido, solo ese.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        if ($this->mode() === null) {
            return [self::REASON_INVALID_DRIVER];
        }
        if ($this->isFake()) {
            return [];
        }

        return $this->openAiReasons();
    }

    /**
     * Lo que le falta a `openai`, aunque el modo sea `fake`: sirve para decir
     * qué haría falta para pasar a OpenAI sin pasar.
     *
     * Un precio o un presupuesto a cero cuenta como ausente: con un precio 0
     * ninguna llamada consumiría presupuesto y el tope no pararía nada.
     *
     * @return list<string>
     */
    public function openAiReasons(): array
    {
        $out = [];
        if (! $this->hasKey) {
            $out[] = self::REASON_MISSING_KEY;
        } elseif ($this->sharedKey) {
            $out[] = self::REASON_SHARED_KEY;
        }
        if ($this->model === null) {
            $out[] = self::REASON_MISSING_MODEL;
        }
        if ($this->priceInputMicros === null || $this->priceOutputMicros === null) {
            $out[] = self::REASON_MISSING_PRICE;
        }
        if ($this->dailyBudgetMicros <= 0 || $this->runBudgetMicros <= 0) {
            $out[] = self::REASON_ZERO_BUDGET;
        }

        return $out;
    }

    /**
     * Los precios con los que se estima y se liquida, en micro-US$ por millón
     * de tokens. En `fake` cuesta 0; en `openai` sin precio, null (no hay
     * estimación posible).
     *
     * @return array{input:int,output:int}|null
     */
    public function prices(): ?array
    {
        if ($this->isFake()) {
            return ['input' => 0, 'output' => 0];
        }
        if ($this->priceInputMicros === null || $this->priceOutputMicros === null) {
            return null;
        }

        return ['input' => $this->priceInputMicros, 'output' => $this->priceOutputMicros];
    }

    /**
     * Lo que se puede enseñar o congelar en una ejecución: nada de claves ni
     * de huellas de claves.
     *
     * @return array{enabled:bool,mode:?string,configured:bool,reasons:list<string>,model:?string,prices:array{input:int,output:int}|null,dailyBudgetMicros:int,runBudgetMicros:int,maxRunsPerDay:int,maxCasesPerRun:int,actorMaxOutputTokens:int,judgeMaxOutputTokens:int,timeoutSeconds:int,timezone:string}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'mode' => $this->mode(),
            'configured' => $this->configured(),
            'reasons' => $this->reasons(),
            // En `fake` no hay modelo: el modo de prueba no consulta ninguno.
            'model' => $this->isOpenAi() ? $this->model : null,
            'prices' => $this->prices(),
            'dailyBudgetMicros' => $this->dailyBudgetMicros,
            'runBudgetMicros' => $this->runBudgetMicros,
            'maxRunsPerDay' => $this->maxRunsPerDay,
            'maxCasesPerRun' => $this->maxCasesPerRun,
            'actorMaxOutputTokens' => $this->actorMaxOutputTokens,
            'judgeMaxOutputTokens' => $this->judgeMaxOutputTokens,
            'timeoutSeconds' => $this->timeoutSeconds,
            'timezone' => self::BUDGET_TIMEZONE,
        ];
    }

    /** Un precio por millón de tokens en micro-US$, o null si falta, no vale o es 0. */
    private static function positiveMicros(mixed $usd): ?int
    {
        $micros = StudioLabCost::microsFromUsd($usd);

        return $micros !== null && $micros > 0 ? $micros : null;
    }

    private static function integer(mixed $valor, int $defecto): int
    {
        if (is_int($valor)) {
            return $valor;
        }
        if (is_string($valor) && preg_match('/^-?\d{1,9}$/', trim($valor)) === 1) {
            return (int) trim($valor);
        }

        return $defecto;
    }

    private static function clamp(int $valor, int $min, int $max): int
    {
        return max($min, min($max, $valor));
    }

    /** Entre 0 y 2, como admite la API; cualquier otra cosa no se envía. */
    private static function temperature(mixed $valor): ?float
    {
        if (is_string($valor)) {
            $valor = trim($valor);
            if (preg_match('/^\d(\.\d{1,3})?$/', $valor) !== 1) {
                return null;
            }
            $valor = (float) $valor;
        }
        if (is_int($valor)) {
            $valor = (float) $valor;
        }
        if (! is_float($valor) || ! is_finite($valor) || $valor < 0.0 || $valor > 2.0) {
            return null;
        }

        return $valor;
    }
}
