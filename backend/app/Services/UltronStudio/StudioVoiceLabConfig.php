<?php

namespace App\Services\UltronStudio;

use App\Services\Voice\Dto\VoiceAgentTarget;
use App\Services\Voice\Dto\VoiceLabSession;
use App\Services\Voice\VoiceProviderStatus;

/**
 * La configuración del LABORATORIO HABLADO (contrato H8, D8-23, protección 12 y
 * Enmienda 2, E2-4), interpretada una vez y sin secretos. Sale de las claves
 * `voice.lab.*` (las define B8-b en `config/voice.php`; mientras no existan,
 * los defectos de aquí dejan el laboratorio CERRADO) y de cuatro claves de la
 * voz que no son secretos: la base canónica de los webhooks, las dos
 * referencias de secretos del gestor de ElevenLabs y la del webhook
 * post-llamada. Ninguna clave ni ningún secreto pasa por aquí: el emisor y el
 * desplegador de B8 leen la clave en el momento de llamar.
 *
 * LOS CUATRO CERROJOS ANTES DE GASTAR (protección 12 y D8-23), independientes:
 *  1. la BANDERA ({@see $enabled}, `VOICE_LAB_ENABLED`, apagada por defecto):
 *     apagada, todo `voice-lab/*` salvo el estado es 409 `voice_lab_disabled`;
 *  2. el DRIVER ({@see $driver}): `fake` (el simulador, sin ElevenLabs y sin
 *     coste) por defecto; `elevenlabs` exige además todo lo de {@see reasons()};
 *  3. el PRESUPUESTO: el diario y el tope por sesión mayores que 0
 *     (`zero_budget`) y el precio OFICIAL por minuto de ElevenLabs con su
 *     fecha (`voice.prices.elevenlabs`, de B8; `missing_price`); además, la
 *     reserva atómica contra el día de Bogotá;
 *  4. la CREDENCIAL presente y VERIFICADA: `ELEVENLABS_VERIFIED`, que solo
 *     afirma el Studio con la última verificación `ok` de menos de 30 días
 *     ({@see StudioIntegrations::voiceState()}).
 * Y además el CUPO DE SESIONES por persona y día de Bogotá
 * ({@see $maxSessionsPerDay}) y como mucho 5 minutos por sesión
 * ({@see HARD_MAX_SESSION_SECONDS}).
 *
 * Los topes duros se vuelven a aplicar aquí aunque la configuración ya los
 * traiga: una configuración puesta a mano (en una prueba o en un tinker) no
 * puede saltárselos. Pura salvo {@see credentialsState()}, que consulta la
 * última verificación.
 */
final class StudioVoiceLabConfig
{
    public const DRIVER_FAKE = 'fake';

    public const DRIVER_ELEVENLABS = 'elevenlabs';

    public const DRIVERS = [self::DRIVER_FAKE, self::DRIVER_ELEVENLABS];

    /** El driver no es `fake` ni `elevenlabs`: no se cae en silencio a ninguno de los dos. */
    public const REASON_INVALID_DRIVER = 'invalid_driver';

    /** La credencial de ElevenLabs no está verificada (`ELEVENLABS_VERIFIED`, D8-23). */
    public const REASON_CREDENTIALS_UNVERIFIED = 'credentials_unverified';

    public const REASON_MISSING_PRICE = 'missing_price';

    public const REASON_ZERO_BUDGET = 'zero_budget';

    /** Sin cupo de sesiones al día (0): no se abre ninguna. */
    public const REASON_ZERO_SESSIONS = 'zero_sessions';

    /** `VOICE_WEBHOOK_BASE_URL` falta o no es una base https limpia: el agente no sabría a quién llamar. */
    public const REASON_MISSING_WEBHOOK_BASE = 'missing_webhook_base';

    /** Faltan las referencias de los secretos de las herramientas y de la iniciación en el gestor de ElevenLabs. */
    public const REASON_MISSING_SECRET_REFS = 'missing_secret_refs';

    /** Los motivos de `voice_lab_not_configured`, en el orden en que salen. */
    public const REASONS = [
        self::REASON_INVALID_DRIVER,
        self::REASON_CREDENTIALS_UNVERIFIED,
        self::REASON_MISSING_WEBHOOK_BASE,
        self::REASON_MISSING_SECRET_REFS,
        self::REASON_MISSING_PRICE,
        self::REASON_ZERO_BUDGET,
        self::REASON_ZERO_SESSIONS,
    ];

    // ── Fijos en código ─────────────────────────────────────────────────────

    /** El día del presupuesto y del cupo es el de Bogotá, nunca el UTC (protección 12). */
    public const BUDGET_TIMEZONE = 'America/Bogota';

    /** La URL firmada caduca a los 15 minutos (auditoría §d.1): hay que empezar antes. */
    public const URL_TTL_SECONDS = VoiceLabSession::MAX_AGE_SECONDS;

    /** Margen para que llegue el informe post-llamada después de la conversación más larga. */
    public const REPORT_GRACE_SECONDS = 120;

    /** Una sesión que en este tiempo no recibió su URL murió a mitad de abrirse: sin URL no hubo conversación. */
    public const OPEN_DEADLINE_SECONDS = 300;

    /** D8-23: como mucho 5 minutos por sesión (y el CHECK `usvl_limits_chk`: 60–300). */
    public const HARD_MAX_SESSION_SECONDS = 300;

    public const MIN_SESSION_SECONDS = 60;

    public const DEFAULT_MAX_SESSIONS_PER_DAY = 5;

    public const HARD_MAX_SESSIONS_PER_DAY = 50;

    /** Un agente de laboratorio atiende una conversación a la vez (su `call_limits`). */
    public const LAB_CONCURRENCY = 1;

    /** El destino de las URL del agente del simulador: nunca resuelve (`.invalid`, RFC 2606). */
    public const FAKE_WEBHOOK_BASE = 'https://voice-lab.invalid';

    public const FAKE_TOOL_SECRET_REF = 'fake-lab-tools';

    public const FAKE_INITIATION_SECRET_REF = 'fake-lab-initiation';

    private function __construct(
        public readonly bool $enabled,
        /** `fake`, `elevenlabs` o el valor no válido tal como llegó (recortado a 16 caracteres). */
        public readonly string $driver,
        /** micro-US$ por minuto de conversación; null = sin precio. */
        public readonly ?int $pricePerMinuteMicros,
        public readonly int $dailyBudgetMicros,
        public readonly int $sessionBudgetMicros,
        public readonly int $maxSessionSeconds,
        public readonly int $maxSessionsPerDay,
        /** El tope de duración de una llamada del servidor (`voice.limits.per_call_max_seconds`, 60–600): el que recibe el compilador. */
        public readonly int $perCallMaxSeconds,
        public readonly ?string $webhookBaseUrl,
        public readonly ?string $toolSecretRef,
        public readonly ?string $initiationSecretRef,
        public readonly ?string $postCallWebhookRef,
    ) {}

    /** La configuración en vigor. */
    public static function current(): self
    {
        return self::fromValues([
            'enabled' => config('voice.lab.enabled', false),
            'driver' => config('voice.lab.driver', self::DRIVER_FAKE),
            // El precio oficial por minuto de ElevenLabs, con su fecha (D8-15): el
            // de la conversación, sin tramo de Twilio (en el navegador no hay).
            'price_micros_per_minute' => config('voice.prices.elevenlabs'),
            'daily_budget_usd' => config('voice.lab.daily_budget_usd', '0'),
            'session_budget_usd' => config('voice.lab.session_budget_usd', '0'),
            'max_session_seconds' => config('voice.lab.max_session_seconds', self::HARD_MAX_SESSION_SECONDS),
            'max_sessions_per_day' => config('voice.lab.max_sessions_per_day', self::DEFAULT_MAX_SESSIONS_PER_DAY),
            'per_call_max_seconds' => config('voice.limits.per_call_max_seconds', 300),
            'webhook_base_url' => config('voice.webhook_base_url'),
            'tool_secret_ref' => config('voice.elevenlabs.tool_secret_ref'),
            'initiation_secret_ref' => config('voice.elevenlabs.initiation_secret_ref'),
            'post_call_webhook_ref' => config('voice.elevenlabs.post_call_webhook_ref'),
        ]);
    }

    /**
     * La misma interpretación sobre valores sueltos (con las claves de
     * {@see current()}).
     *
     * @param  array<string, mixed>  $v
     */
    public static function fromValues(array $v): self
    {
        $driver = strtolower(trim(is_string($v['driver'] ?? null) ? $v['driver'] : self::DRIVER_FAKE));
        $base = is_string($v['webhook_base_url'] ?? null) ? VoiceAgentTarget::cleanBase($v['webhook_base_url']) : null;
        // Un precio 0 o sin forma cuenta como ausente: con 0 ninguna sesión consumiría presupuesto.
        $precio = self::integer($v['price_micros_per_minute'] ?? null, 0);

        return new self(
            // Solo un booleano verdadero enciende (el `filter_var` lo hace config/voice.php).
            enabled: ($v['enabled'] ?? false) === true,
            driver: mb_substr($driver, 0, 16),
            pricePerMinuteMicros: $precio > 0 ? $precio : null,
            dailyBudgetMicros: StudioLabCost::microsFromUsd($v['daily_budget_usd'] ?? null) ?? 0,
            sessionBudgetMicros: StudioLabCost::microsFromUsd($v['session_budget_usd'] ?? null) ?? 0,
            maxSessionSeconds: self::clamp(self::integer($v['max_session_seconds'] ?? null, self::HARD_MAX_SESSION_SECONDS), self::MIN_SESSION_SECONDS, self::HARD_MAX_SESSION_SECONDS),
            maxSessionsPerDay: self::clamp(self::integer($v['max_sessions_per_day'] ?? null, self::DEFAULT_MAX_SESSIONS_PER_DAY), 0, self::HARD_MAX_SESSIONS_PER_DAY),
            perCallMaxSeconds: self::clamp(self::integer($v['per_call_max_seconds'] ?? null, 300), StudioVoiceCompiler::MIN_DURATION_SECONDS, StudioVoiceCompiler::MAX_DURATION_CEILING),
            webhookBaseUrl: $base,
            toolSecretRef: self::ref($v['tool_secret_ref'] ?? null),
            initiationSecretRef: self::ref($v['initiation_secret_ref'] ?? null),
            postCallWebhookRef: self::ref($v['post_call_webhook_ref'] ?? null),
        );
    }

    /** El driver de las sesiones: `fake` o `elevenlabs`; con un driver no válido, null (nada se abre). */
    public function mode(): ?string
    {
        return in_array($this->driver, self::DRIVERS, true) ? $this->driver : null;
    }

    public function isFake(): bool
    {
        return $this->mode() === self::DRIVER_FAKE;
    }

    public function isElevenLabs(): bool
    {
        return $this->mode() === self::DRIVER_ELEVENLABS;
    }

    /** ¿Se puede abrir una sesión en este modo? En `fake`, con cupo; en `elevenlabs`, sin ningún motivo pendiente. */
    public function configured(?string $credentialsState = null): bool
    {
        return $this->reasons($credentialsState) === [];
    }

    /**
     * Por qué no se puede abrir una sesión (`voice_lab_not_configured
     * {reasons}`), en el orden de {@see REASONS}. En `fake` solo cuenta el
     * cupo de sesiones (no hay nada que pagar); con un driver no válido, solo
     * ese. `$credentialsState` es el de {@see credentialsState()} (se pasa para
     * que la prueba no dependa de la base).
     *
     * @return list<string>
     */
    public function reasons(?string $credentialsState = null): array
    {
        if ($this->mode() === null) {
            return [self::REASON_INVALID_DRIVER];
        }
        $out = [];
        if ($this->isElevenLabs()) {
            if (($credentialsState ?? self::credentialsState()) !== VoiceProviderStatus::STATE_VERIFIED) {
                $out[] = self::REASON_CREDENTIALS_UNVERIFIED;
            }
            if ($this->webhookBaseUrl === null) {
                $out[] = self::REASON_MISSING_WEBHOOK_BASE;
            }
            if ($this->toolSecretRef === null || $this->initiationSecretRef === null) {
                $out[] = self::REASON_MISSING_SECRET_REFS;
            }
            if ($this->pricePerMinuteMicros === null) {
                $out[] = self::REASON_MISSING_PRICE;
            }
            // Un tope a cero cuenta como ausente: nada pararía el gasto.
            if ($this->dailyBudgetMicros <= 0 || $this->sessionBudgetMicros <= 0) {
                $out[] = self::REASON_ZERO_BUDGET;
            }
        }
        if ($this->maxSessionsPerDay <= 0) {
            $out[] = self::REASON_ZERO_SESSIONS;
        }

        return $out;
    }

    /**
     * El estado de la credencial que afirma el Studio: `ELEVENLABS_VERIFIED`
     * solo con la bandera, las piezas y la última verificación `ok` de menos
     * de 30 días. Sin red: lee la evidencia guardada.
     */
    public static function credentialsState(): string
    {
        return StudioIntegrations::voiceState();
    }

    /**
     * La reserva de una sesión de `$seconds` segundos: el precio por segundo
     * redondeado hacia arriba. En `fake`, 0. Sin precio, null.
     */
    public function reservationFor(int $seconds): ?int
    {
        if ($this->isFake()) {
            return 0;
        }
        if ($this->pricePerMinuteMicros === null) {
            return null;
        }

        return intdiv($seconds * $this->pricePerMinuteMicros + 59, 60);
    }

    /**
     * Lo que se puede enseñar de la configuración: nada de claves ni de
     * referencias de secretos (solo si están).
     *
     * @return array<string, mixed>
     */
    public function toArray(?string $credentialsState = null): array
    {
        return [
            'enabled' => $this->enabled,
            'mode' => $this->mode(),
            'configured' => $this->configured($credentialsState),
            'reasons' => $this->reasons($credentialsState),
            'pricePerMinuteMicros' => $this->isFake() ? 0 : $this->pricePerMinuteMicros,
            'dailyBudgetMicros' => $this->dailyBudgetMicros,
            'sessionBudgetMicros' => $this->sessionBudgetMicros,
            'maxSessionSeconds' => $this->maxSessionSeconds,
            'maxSessionsPerDay' => $this->maxSessionsPerDay,
            'timezone' => self::BUDGET_TIMEZONE,
        ];
    }

    /** Una referencia del gestor de secretos o de un webhook del proveedor (no es un secreto), o null. */
    private static function ref(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }
        $valor = trim($valor);

        return preg_match(VoiceAgentTarget::REF_PATTERN, $valor) === 1 ? $valor : null;
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
}
