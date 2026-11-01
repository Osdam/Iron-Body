<?php

namespace App\Services\Voice\ElevenLabs;

use App\Services\Voice\VoiceProviderStatus;

/**
 * La configuración de ElevenLabs (contrato H6 §I6.1–§I6.3), interpretada una
 * vez, sin red y sin secretos. Sale de `config('voice.elevenlabs')` (que sale
 * del entorno y se cachea con `config:cache`) y no lee nada más.
 *
 * LOS SECRETOS NO SALEN DE AQUÍ. La clave de API y el secreto del webhook se
 * reducen a «hay» o «no hay» al construir el objeto y no se guardan en
 * ninguna propiedad; el secreto de las server tools ni se mira (no se exige).
 * Solo {@see ElevenLabsVoiceProvider} los lee, en el momento de usarlos, y
 * una prueba estática (`VoiceIsolationTest`) comprueba que ningún otro
 * fichero toma esas claves de la configuración.
 *
 * Las reglas de forma se vuelven a aplicar aquí aunque `config/voice.php` ya
 * las aplique: una configuración puesta a mano (en una prueba o en un tinker)
 * no puede saltárselas.
 */
final class ElevenLabsConfig
{
    /** Lista CERRADA (solo las confirmadas en la documentación oficial al congelar). Otra región exige una enmienda. */
    public const REGIONS = ['default' => 'https://api.elevenlabs.io', 'eu' => 'https://api.eu.residency.elevenlabs.io'];

    public const AGENT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';   // D: sin él PHP aceptaría un salto de línea final (N-5 de security, Enmienda 3)

    /** La región si `voice.elevenlabs.region` no existe (el defecto de `ELEVENLABS_REGION`). */
    public const DEFAULT_REGION = 'default';

    public const DEFAULT_TIMEOUT_SECONDS = 10;

    public const MIN_TIMEOUT_SECONDS = 3;

    public const MAX_TIMEOUT_SECONDS = 30;

    private function __construct(
        private readonly bool $enabled,
        private readonly bool $hasApiKey,
        private readonly ?string $agentId,
        private readonly bool $hasWebhookSecret,
        private readonly ?string $region,
        private readonly ?string $nonProductionBaseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        $c = config('voice.elevenlabs');
        $c = is_array($c) ? $c : [];

        // Una región ausente es la de por defecto, como el defecto del entorno;
        // cualquier otro valor se pasa a minúsculas y sin espacios alrededor y
        // tiene que ser una clave de REGIONS.
        $region = $c['region'] ?? self::DEFAULT_REGION;
        $region = is_string($region) ? strtolower(trim($region)) : '';

        $agente = is_string($c['agent_id'] ?? null) ? $c['agent_id'] : '';   // sin trim: el patrón (con D) decide; un salto de línea final se rechaza (N-5, fixture voiceDoctor)

        $base = is_string($c['base_url'] ?? null) ? trim($c['base_url']) : '';

        return new self(
            enabled: ($c['enabled'] ?? false) === true,
            hasApiKey: self::filled($c['api_key'] ?? null),
            agentId: preg_match(self::AGENT_ID_PATTERN, $agente) === 1 ? $agente : null,
            hasWebhookSecret: self::filled($c['webhook_secret'] ?? null),
            region: array_key_exists($region, self::REGIONS) ? $region : null,
            nonProductionBaseUrl: self::cleanBase($base) ? rtrim($base, '/') : null,
            timeoutSeconds: max(self::MIN_TIMEOUT_SECONDS, min(self::MAX_TIMEOUT_SECONDS, self::integer($c['timeout_seconds'] ?? null, self::DEFAULT_TIMEOUT_SECONDS))),
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @return list<string> piezas que faltan o no tienen forma válida, en el orden api_key, agent_id, webhook_secret, region */
    public function missing(): array
    {
        $out = [];
        if (! $this->hasApiKey) {
            $out[] = 'api_key';
        }
        if ($this->agentId === null) {
            $out[] = 'agent_id';
        }
        if (! $this->hasWebhookSecret) {
            $out[] = 'webhook_secret';
        }
        if ($this->region === null) {
            $out[] = 'region';
        }

        return $out;
    }

    public function region(): ?string
    {
        return $this->region;
    }

    /**
     * production → REGIONS[region]; fuera de production → voice.elevenlabs.base_url
     * si está (y es una URL http(s) limpia: sin usuario, consulta ni fragmento),
     * si no REGIONS[region]. null sin región válida.
     */
    public function baseUrl(): ?string
    {
        if ($this->region === null) {
            return null;
        }
        if (app()->isProduction() || $this->nonProductionBaseUrl === null) {
            return self::REGIONS[$this->region];
        }

        return $this->nonProductionBaseUrl;
    }

    /** El id del agente si casa con AGENT_ID_PATTERN (recortado); si no, null. No es un secreto. */
    public function agentId(): ?string
    {
        return $this->agentId;
    }

    /** `voice.elevenlabs.timeout_seconds`, entero recortado a 3–30 (10 si falta o no es entero). */
    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /** missing ≠ [] → NOT_CONFIGURED; si no, !enabled → DISABLED; si no, UNVERIFIED. */
    public function status(): VoiceProviderStatus
    {
        $faltan = $this->missing();
        $estado = match (true) {
            $faltan !== [] => VoiceProviderStatus::STATE_NOT_CONFIGURED,
            ! $this->enabled => VoiceProviderStatus::STATE_DISABLED,
            default => VoiceProviderStatus::STATE_UNVERIFIED,
        };

        return new VoiceProviderStatus(
            provider: $estado === VoiceProviderStatus::STATE_UNVERIFIED ? VoiceProviderStatus::PROVIDER_ELEVENLABS : VoiceProviderStatus::PROVIDER_NONE,
            state: $estado,
            enabled: $this->enabled,
            missing: $faltan,
            region: $this->region,
        );
    }

    /** ¿Hay un texto no vacío? Solo se mira si existe: el valor no se guarda. */
    private static function filled(mixed $valor): bool
    {
        return is_string($valor) && trim($valor) !== '';
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

    /** Una base http(s) limpia para el simulador local: con host y sin usuario, contraseña, consulta ni fragmento. */
    private static function cleanBase(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $partes = parse_url($url);

        return is_array($partes)
            && in_array(strtolower((string) ($partes['scheme'] ?? '')), ['http', 'https'], true)
            && ($partes['host'] ?? '') !== ''
            && ! isset($partes['user'])
            && ! isset($partes['pass'])
            && ! isset($partes['query'])
            && ! isset($partes['fragment']);
    }
}
