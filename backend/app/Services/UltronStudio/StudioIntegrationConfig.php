<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Services\Marketing\Attribution\LeadSourceClassifier;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use App\Services\Voice\ElevenLabs\ElevenLabsVoiceProvider;
use App\Services\Voice\VoiceCheckResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * LA CONFIGURACIÓN AUTORIZADA de Meta Ads, Twilio y voz (contrato H6 §I5.3):
 * qué hay hoy (los `facts` efectivos), qué reconoció una persona y qué anotó el
 * comando de cada despliegue, y en qué difieren. SIN RED y SIN SECRETOS.
 *
 * LOS HECHOS (`facts`) describen la configuración sin revelarla:
 *  - de un secreto, solo si está y una huella corta (`fp`, 8 hexadecimales de
 *    un HMAC con una subclave propia de la `APP_KEY`: {@see valueFingerprint()});
 *    las de voz las calcula SOLO `ElevenLabsVoiceProvider::secretFingerprints()`
 *    (Enmienda 1), que es el único que lee esos secretos;
 *  - de un identificador (SID, cuenta, agente), una referencia enmascarada;
 *  - de un número del gimnasio, sus cuatro últimos dígitos;
 *  - lo que no es secreto ni dato personal, tal cual. Los importes van como
 *    TEXTO con dos decimales (Enmienda 1, Q-1): la huella canónica del Studio
 *    no admite decimales ({@see StudioContentHasher::canonicalize()}).
 *
 * `fingerprint` = sha256 del JSON canónico de `facts`. `GET integrations/config`
 * NUNCA escribe; reconocer (ruta 55) inserta una instantánea de persona; el
 * comando `ultron-studio:integrations-snapshot` inserta una de sistema solo si
 * la huella cambió. Las instantáneas son de SOLO INSERCIÓN.
 *
 * Del motor solo LEE la lista de cuentas conectadas de Meta y el enmascarado de
 * ids (`UltronStudioEngineReadOnlyTest`). Ninguna fachada de red.
 */
final class StudioIntegrationConfig
{
    /** La etiqueta de la subclave de las huellas de valores (§I5.3). */
    public const FINGERPRINT_LABEL = 'ultron-studio-config-fingerprint/1';

    /** El actor de las instantáneas del comando de cada despliegue. */
    public const SYSTEM_ACTOR = 'ultron-studio:integrations-snapshot';

    public const NOTE_MAX = 500;

    /** Hasta cuándo vale una verificación `ok` de la voz para el estado `ELEVENLABS_VERIFIED` (§I6.3): 30 días, estricto. */
    public const VOICE_VERIFIED_MAX_AGE_SECONDS = 2592000;

    /** Los hosts oficiales de Twilio (§I5.2). */
    public const TWILIO_API_HOST = 'api.twilio.com';

    public const TWILIO_VERIFY_HOST = 'verify.twilio.com';

    /** Las claves de `facts`, en su orden, por proveedor (§I5.3). */
    public const FACT_KEYS = [
        StudioIntegrationSnapshot::PROVIDER_META_ADS => ['token', 'accounts', 'graphVersion', 'syncEnabled', 'syncDays', 'staleMinutes', 'timezone'],
        StudioIntegrationSnapshot::PROVIDER_TWILIO => ['otpDriver', 'accountSid', 'authToken', 'aliasesConsistent', 'verifyServiceSid', 'from', 'apiBase', 'verifyBase',
            'rateLimitName', 'sendEnabled', 'legacySenderEnabled', 'costSoftUsd', 'costHardUsd', 'probe'],
        StudioIntegrationSnapshot::PROVIDER_VOICE => ['elevenlabs', 'twilioVoice'],
    ];

    /** La correspondencia del resultado de la verificación de voz con el `outcome` de la instantánea (§I5.3). */
    public const VOICE_OUTCOMES = [
        VoiceCheckResult::OUTCOME_CREDENTIALS_OK => 'ok',
        VoiceCheckResult::OUTCOME_INVALID_CREDENTIALS => 'auth_failed',
        VoiceCheckResult::OUTCOME_AGENT_NOT_FOUND => 'degraded',
        VoiceCheckResult::OUTCOME_UNREACHABLE => 'unreachable',
        VoiceCheckResult::OUTCOME_ERROR => 'error',
    ];

    private const SUMMARIES = [
        StudioIntegrationSnapshot::PROVIDER_META_ADS => 'Configuración de Meta Ads reconocida',
        StudioIntegrationSnapshot::PROVIDER_TWILIO => 'Configuración de Twilio reconocida',
        StudioIntegrationSnapshot::PROVIDER_VOICE => 'Configuración de voz reconocida',
    ];

    public function __construct(private readonly StudioAudit $audit) {}

    // ── Huellas ─────────────────────────────────────────────────────────────

    /**
     * La subclave de las huellas de valores (32 bytes crudos):
     * HMAC-SHA256(clave = APP_KEY cruda, mensaje = FINGERPRINT_LABEL).
     *
     * @throws RuntimeException sin `APP_KEY`
     */
    public static function key(): string
    {
        $clave = config('app.key');
        if (is_string($clave) && str_starts_with($clave, 'base64:')) {
            $clave = base64_decode(substr($clave, 7));
        }
        if (! is_string($clave) || $clave === '') {
            throw new RuntimeException('Sin APP_KEY no se calculan las huellas de la configuración.');
        }

        return hash_hmac('sha256', self::FINGERPRINT_LABEL, $clave, true);
    }

    /** `substr(hash_hmac('sha256', $valor, $kCfg), 0, 8)`. Solo para secretos. */
    public static function valueFingerprint(#[\SensitiveParameter] string $valor): string
    {
        return substr(hash_hmac('sha256', $valor, self::key()), 0, 8);
    }

    /**
     * La huella de un conjunto de hechos: sha256 de su JSON canónico.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function fingerprintOf(array $facts): string
    {
        return StudioContentHasher::hash($facts);
    }

    // ── Hechos efectivos ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public static function facts(string $provider): array
    {
        return match ($provider) {
            StudioIntegrationSnapshot::PROVIDER_META_ADS => self::metaFacts(),
            StudioIntegrationSnapshot::PROVIDER_TWILIO => self::twilioFacts(),
            StudioIntegrationSnapshot::PROVIDER_VOICE => self::voiceFacts(),
            default => throw new InvalidArgumentException('Proveedor de configuración desconocido.'),
        };
    }

    /** @return array<string, mixed> */
    public static function metaFacts(): array
    {
        $token = config('meta.ads.access_token');
        $cuentas = app(MetaAdsApiClient::class)->accountIds();

        return [
            'token' => self::secret(is_string($token) ? $token : null),
            'accounts' => array_map(static fn (string $cuenta): array => ['ref' => LeadSourceClassifier::maskId($cuenta)], $cuentas),
            'graphVersion' => self::text(config('meta.ads.graph_version')),
            'syncEnabled' => config('meta.ads.sync_enabled') === true,
            'syncDays' => (int) config('meta.ads.sync_days'),
            'staleMinutes' => (int) config('meta.ads.stale_after_minutes'),
            'timezone' => self::text(config('meta.ads.timezone')),
        ];
    }

    /** @return array<string, mixed> */
    public static function twilioFacts(): array
    {
        $alias = config('ultron_studio.integrations.twilio_aliases');
        $alias = is_array($alias) ? $alias : [];
        $sid = self::text(config('otp.twilio.sid'));
        $token = self::text(config('otp.twilio.token'));

        return [
            'otpDriver' => self::text(config('otp.driver')),
            'accountSid' => filled($sid) ? ['present' => true, 'source' => self::text($alias['sid_source'] ?? null), 'ref' => self::ref((string) $sid)] : ['present' => false],
            'authToken' => filled($token) ? ['present' => true, 'source' => self::text($alias['token_source'] ?? null), 'fp' => self::valueFingerprint((string) $token)] : ['present' => false],
            'aliasesConsistent' => [
                'sid' => is_bool($alias['sid_consistent'] ?? null) ? $alias['sid_consistent'] : null,
                'token' => is_bool($alias['token_consistent'] ?? null) ? $alias['token_consistent'] : null,
            ],
            'verifyServiceSid' => self::identifier(config('otp.twilio.verify_service_sid')),
            'from' => self::number(config('otp.twilio.from')),
            'apiBase' => self::base(config('otp.twilio.base'), self::TWILIO_API_HOST),
            'verifyBase' => self::base(config('otp.twilio.verify_base'), self::TWILIO_VERIFY_HOST),
            'rateLimitName' => ['present' => filled(self::text(config('otp.twilio.rate_limit_unique_name')))],
            'sendEnabled' => (bool) config('otp.send_enabled', true),
            'legacySenderEnabled' => (bool) config('otp.legacy_sms_sender_enabled', false),
            'costSoftUsd' => self::amount(config('otp.cost.daily_soft')),
            'costHardUsd' => self::amount(config('otp.cost.daily_hard')),
            'probe' => [
                'enabled' => config('ultron_studio.integrations.twilio_probe.enabled') === true,
                'credential' => self::probeCredential(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function voiceFacts(): array
    {
        // Los secretos de voz SOLO por esta vía (Enmienda 1): el proveedor los
        // lee y devuelve sus huellas con nuestra subclave; aquí no se leen.
        return self::voiceFactsFrom(
            ElevenLabsVoiceProvider::secretFingerprints(self::key()),
            config('voice.elevenlabs.enabled') === true,
            self::text(config('voice.elevenlabs.agent_id')),
            config('voice.elevenlabs.region'),
            self::text(config('voice.elevenlabs.agent_phone_number_id')),
            config('voice.twilio.enabled') === true,
            config('voice.twilio.from'),
            (int) config('voice.twilio.daily_call_cap'),
        );
    }

    /**
     * Los hechos de voz a partir de sus piezas: las HUELLAS de los tres
     * secretos (nunca los secretos) y lo que no es secreto. Pura: la usa
     * también la exportación de fixtures, que así no toca la configuración de voz.
     *
     * @param  array{api_key:?string,webhook_secret:?string,tool_secret:?string}  $secretFingerprints
     * @return array<string, mixed>
     */
    public static function voiceFactsFrom(array $secretFingerprints, bool $enabled, ?string $agentId, mixed $region, ?string $phoneNumberId, bool $twilioEnabled, mixed $twilioFrom, int $dailyCallCap): array
    {
        $huella = static fn (?string $fp): array => $fp === null ? ['present' => false] : ['present' => true, 'fp' => $fp];

        return [
            'elevenlabs' => [
                'enabled' => $enabled,
                'apiKey' => $huella($secretFingerprints['api_key'] ?? null),
                'agentId' => filled($agentId) ? ['present' => true, 'ref' => mb_substr(trim((string) $agentId), -4)] : ['present' => false],
                'webhookSecret' => $huella($secretFingerprints['webhook_secret'] ?? null),
                // La región va tal cual (no es secreta): una fuera de la lista se ve como es.
                'region' => is_string($region) ? $region : null,
                'toolSecret' => $huella($secretFingerprints['tool_secret'] ?? null),
                'phoneNumberId' => ['present' => filled($phoneNumberId)],
            ],
            'twilioVoice' => [
                'enabled' => $twilioEnabled,
                'from' => self::number($twilioFrom),
                'dailyCallCap' => $dailyCallCap,
            ],
        ];
    }

    /** Con qué credencial leería la sonda de Twilio (§I5.4): la llave restringida solo si están las dos piezas. */
    public static function probeCredential(): string
    {
        return filled(self::text(config('ultron_studio.integrations.twilio_probe.key_sid')))
            && filled(self::text(config('ultron_studio.integrations.twilio_probe.key_secret')))
            ? 'restricted_key'
            : 'otp';
    }

    // ── Deriva y estado ─────────────────────────────────────────────────────

    /**
     * Las claves de PRIMER nivel cuyo valor difiere entre lo reconocido y lo
     * efectivo, en el orden de las claves efectivas (y después las que solo
     * tiene lo reconocido). Se comparan valores canónicos: el mismo objeto con
     * las claves en otro orden es el mismo valor; el orden de una lista, no.
     *
     * @param  array<string, mixed>|null  $authorized
     * @param  array<string, mixed>  $effective
     * @return list<array{key:string,authorized:mixed,effective:mixed}>
     */
    public static function drift(?array $authorized, array $effective): array
    {
        if ($authorized === null) {
            return [];
        }

        $out = [];
        foreach ($effective as $clave => $valor) {
            $antes = array_key_exists($clave, $authorized) ? $authorized[$clave] : null;
            if (! array_key_exists($clave, $authorized) || ! self::sameValue($antes, $valor)) {
                $out[] = ['key' => (string) $clave, 'authorized' => $antes, 'effective' => $valor];
            }
        }
        foreach ($authorized as $clave => $antes) {
            if (! array_key_exists($clave, $effective)) {
                $out[] = ['key' => (string) $clave, 'authorized' => $antes, 'effective' => null];
            }
        }

        return $out;
    }

    /** `never_authorized` sin reconocimiento; `drift` si las huellas difieren; si no, `authorized`. */
    public static function stateFor(?string $authorizedFingerprint, string $effectiveFingerprint): string
    {
        return match (true) {
            $authorizedFingerprint === null => 'never_authorized',
            ! hash_equals($authorizedFingerprint, $effectiveFingerprint) => 'drift',
            default => 'authorized',
        };
    }

    // ── 46 · GET integrations/config ────────────────────────────────────────

    /**
     * Los tres proveedores en su orden, cada uno con lo efectivo, lo reconocido
     * por una persona, lo observado por el sistema y la deriva. No escribe nada.
     *
     * @return array{providers:list<array<string,mixed>>}
     */
    public function overview(): array
    {
        $ultimas = self::latestConfigSnapshots();
        $proveedores = [];
        foreach (StudioIntegrationSnapshot::PROVIDERS as $proveedor) {
            $proveedores[] = self::providerOverview(
                $proveedor,
                self::facts($proveedor),
                $ultimas[$proveedor][StudioIntegrationSnapshot::ACTOR_ADMIN] ?? null,
                $ultimas[$proveedor][StudioIntegrationSnapshot::ACTOR_SYSTEM] ?? null,
            );
        }

        return ['providers' => $proveedores];
    }

    /**
     * Un proveedor de `GET integrations/config`: lo efectivo, lo reconocido
     * por una persona, lo observado por el sistema, el estado y la deriva.
     * Pura: la usa también la exportación de fixtures.
     *
     * @param  array<string,mixed>  $effective
     * @return array<string,mixed>
     */
    public static function providerOverview(string $provider, array $effective, ?StudioIntegrationSnapshot $authorized, ?StudioIntegrationSnapshot $observed): array
    {
        $huella = self::fingerprintOf($effective);

        return [
            'provider' => $provider,
            'state' => self::stateFor($authorized?->fingerprint, $huella),
            'effective' => $effective,
            'effectiveFingerprint' => $huella,
            'authorized' => $authorized === null ? null : [
                'uuid' => (string) $authorized->uuid,
                'fingerprint' => (string) $authorized->fingerprint,
                'at' => $authorized->created_at?->toIso8601String(),
                'by' => ['name' => (string) $authorized->actor_name, 'role' => $authorized->actor_role],
                'note' => $authorized->note,
            ],
            'observed' => $observed === null ? null : [
                'uuid' => (string) $observed->uuid,
                'fingerprint' => (string) $observed->fingerprint,
                'at' => $observed->created_at?->toIso8601String(),
            ],
            'drift' => self::drift(is_array($authorized?->facts) ? $authorized->facts : null, $effective),
        ];
    }

    // ── 55 · POST integrations/config/acknowledge ───────────────────────────

    /**
     * Reconocer como autorizada la configuración vigente de un proveedor. No
     * depende de la bandera de escrituras (decisión 11). `$fingerprint` es la
     * huella efectiva que vio la persona: si ya es otra, 409 `config_changed`.
     *
     * @return array{snapshot:array<string,mixed>,replayed:bool}
     */
    public function acknowledge(StudioAccess $access, Request $request, string $clientRequestId, string $provider, string $fingerprint, ?string $note): array
    {
        $access->require(StudioAccess::INTEGRATIONS_MANAGE);

        $cuerpo = ['provider' => $provider, 'fingerprint' => $fingerprint, 'note' => $note];
        if ($previa = $this->ackReplay($access, $clientRequestId, $cuerpo)) {
            return ['snapshot' => $previa, 'replayed' => true];
        }

        $efectivo = self::facts($provider);
        $huella = self::fingerprintOf($efectivo);
        $autorizada = self::latestConfigSnapshots()[$provider][StudioIntegrationSnapshot::ACTOR_ADMIN] ?? null;
        $deriva = self::drift(is_array($autorizada?->facts) ? $autorizada->facts : null, $efectivo);
        if (! hash_equals($huella, $fingerprint)) {
            throw UltronStudioException::configChanged($provider, $huella, $deriva);
        }

        try {
            $fila = DB::transaction(function () use ($access, $request, $clientRequestId, $provider, $efectivo, $huella, $note, $deriva): StudioIntegrationSnapshot {
                $yo = $access->snapshot();
                $fila = StudioIntegrationSnapshot::query()->forceCreate([
                    'client_request_id' => $clientRequestId,
                    'provider' => $provider,
                    'kind' => StudioIntegrationSnapshot::KIND_CONFIG,
                    'fingerprint' => $huella,
                    'facts' => $efectivo,
                    'outcome' => null,
                    'actor_type' => StudioIntegrationSnapshot::ACTOR_ADMIN,
                    'actor_admin_id' => $yo['id'],
                    'actor_name' => $yo['name'],
                    'actor_role' => $yo['role'],
                    'note' => $note,
                    'created_at' => now(),
                ]);

                // Al libro, solo la huella del conjunto y qué claves cambiaron: nunca valores.
                $this->audit->record($request, $access->actor, 'integration.config_acknowledged', StudioAuditEvent::SUBJECT_INTEGRATION, $fila->uuid,
                    self::SUMMARIES[$provider], [
                        'diff' => ['provider' => $provider, 'fingerprint' => $huella, 'driftKeys' => array_column($deriva, 'key')],
                    ]);

                return $fila;
            });
        } catch (UniqueConstraintViolationException $e) {
            // La misma llave llegó dos veces a la vez: se resuelve FUERA de la
            // transacción (en PostgreSQL, el error la deja abortada).
            if ($previa = $this->ackReplay($access, $clientRequestId, $cuerpo)) {
                return ['snapshot' => $previa, 'replayed' => true];
            }

            throw $e;
        }

        return ['snapshot' => self::acknowledgementDto($fila), 'replayed' => false];
    }

    /** @return array{uuid:string,provider:string,fingerprint:string,at:?string,by:array{name:string,role:?string},note:?string} */
    public static function acknowledgementDto(StudioIntegrationSnapshot $s): array
    {
        return [
            'uuid' => (string) $s->uuid,
            'provider' => (string) $s->provider,
            'fingerprint' => (string) $s->fingerprint,
            'at' => $s->created_at?->toIso8601String(),
            'by' => ['name' => (string) $s->actor_name, 'role' => $s->actor_role],
            'note' => $s->note,
        ];
    }

    // ── El comando de cada despliegue ───────────────────────────────────────

    /**
     * Por cada proveedor, una instantánea de SISTEMA solo si la huella efectiva
     * es distinta de la de la última instantánea `config` de ese proveedor, sea
     * de quien sea: es el registro de cambios.
     *
     * @return list<array{provider:string,fingerprint:string,recorded:bool,uuid:?string}>
     */
    public function recordSystemSnapshots(string $actorName = self::SYSTEM_ACTOR): array
    {
        $out = [];
        foreach (StudioIntegrationSnapshot::PROVIDERS as $proveedor) {
            $efectivo = self::facts($proveedor);
            $huella = self::fingerprintOf($efectivo);
            $ultima = StudioIntegrationSnapshot::query()
                ->where('provider', $proveedor)
                ->where('kind', StudioIntegrationSnapshot::KIND_CONFIG)
                ->orderByDesc('id')
                ->value('fingerprint');
            if (is_string($ultima) && hash_equals($ultima, $huella)) {
                $out[] = ['provider' => $proveedor, 'fingerprint' => $huella, 'recorded' => false, 'uuid' => null];

                continue;
            }

            $fila = StudioIntegrationSnapshot::query()->forceCreate([
                'client_request_id' => null,
                'provider' => $proveedor,
                'kind' => StudioIntegrationSnapshot::KIND_CONFIG,
                'fingerprint' => $huella,
                'facts' => $efectivo,
                'outcome' => null,
                'actor_type' => StudioIntegrationSnapshot::ACTOR_SYSTEM,
                'actor_admin_id' => null,
                'actor_name' => mb_substr($actorName, 0, 120),
                'actor_role' => null,
                'note' => null,
                'created_at' => now(),
            ]);
            $out[] = ['provider' => $proveedor, 'fingerprint' => $huella, 'recorded' => true, 'uuid' => (string) $fila->uuid];
        }

        return $out;
    }

    /**
     * La evidencia de una verificación de voz (`--verify-voice`): una
     * instantánea `probe` de sistema con el resultado, sin nada de la respuesta.
     * Un resultado sin correspondencia (`not_configured`) no se guarda.
     */
    public function recordVoiceVerification(VoiceCheckResult $result, string $actorName = self::SYSTEM_ACTOR): ?StudioIntegrationSnapshot
    {
        $outcome = self::VOICE_OUTCOMES[$result->outcome] ?? null;
        if ($outcome === null) {
            return null;
        }
        $facts = [
            'outcome' => $result->outcome,
            'httpStatus' => $result->httpStatus,
            'agentAudioUlaw8000' => $result->agentAudioUlaw8000,
            'latencyMs' => $result->latencyMs,
        ];

        return StudioIntegrationSnapshot::query()->forceCreate([
            'client_request_id' => null,
            'provider' => StudioIntegrationSnapshot::PROVIDER_VOICE,
            'kind' => StudioIntegrationSnapshot::KIND_PROBE,
            'fingerprint' => self::fingerprintOf($facts),
            'facts' => $facts,
            'outcome' => $outcome,
            'actor_type' => StudioIntegrationSnapshot::ACTOR_SYSTEM,
            'actor_admin_id' => null,
            'actor_name' => mb_substr($actorName, 0, 120),
            'actor_role' => null,
            'note' => null,
            'created_at' => now(),
        ]);
    }

    /** La última instantánea de un tipo y un proveedor (la sonda de Twilio, la verificación de voz…), o null. */
    public static function latest(string $provider, string $kind): ?StudioIntegrationSnapshot
    {
        return StudioIntegrationSnapshot::query()
            ->where('provider', $provider)
            ->where('kind', $kind)
            ->orderByDesc('id')
            ->first();
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * La última instantánea `config` de cada proveedor por tipo de actor: dos
     * consultas sobre el índice `usis_provider_idx`.
     *
     * @return array<string, array<string, StudioIntegrationSnapshot>>
     */
    private static function latestConfigSnapshots(): array
    {
        $ids = StudioIntegrationSnapshot::query()->toBase()
            ->where('kind', StudioIntegrationSnapshot::KIND_CONFIG)
            ->groupBy('provider', 'actor_type')
            ->selectRaw('max(id) as id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (StudioIntegrationSnapshot::query()->whereIn('id', $ids)->get() as $fila) {
            $out[(string) $fila->provider][(string) $fila->actor_type] = $fila;
        }

        return $out;
    }

    /**
     * La misma petición repetida devuelve la instantánea de la primera vez; la
     * misma llave de otra persona o con otro cuerpo es 409 `client_request_reused`.
     *
     * @param  array{provider:string,fingerprint:string,note:?string}  $cuerpo
     * @return array<string,mixed>|null
     */
    private function ackReplay(StudioAccess $access, string $clientRequestId, array $cuerpo): ?array
    {
        $previa = StudioIntegrationSnapshot::query()->where('client_request_id', strtolower($clientRequestId))->first();
        if ($previa === null) {
            return null;
        }
        if ((int) $previa->actor_admin_id !== $access->id()
            || $previa->kind !== StudioIntegrationSnapshot::KIND_CONFIG
            || $previa->provider !== $cuerpo['provider']
            || $previa->fingerprint !== $cuerpo['fingerprint']
            || $previa->note !== $cuerpo['note']) {
            throw UltronStudioException::clientRequestReused();
        }

        return self::acknowledgementDto($previa);
    }

    private static function sameValue(mixed $a, mixed $b): bool
    {
        try {
            return StudioContentHasher::canonicalJson($a) === StudioContentHasher::canonicalJson($b);
        } catch (InvalidArgumentException) {
            // Un valor que no se puede escribir en forma canónica (lo guardado a
            // mano con un decimal, por ejemplo) no es el mismo valor.
            return false;
        }
    }

    /** Un secreto: si está, y su huella corta. */
    private static function secret(#[\SensitiveParameter] ?string $valor): array
    {
        return filled($valor) ? ['present' => true, 'fp' => self::valueFingerprint((string) $valor)] : ['present' => false];
    }

    /** Un identificador (no secreto): si está, y «2 primeros…4 últimos». */
    private static function identifier(mixed $valor): array
    {
        $valor = self::text($valor);

        return filled($valor) ? ['present' => true, 'ref' => self::ref((string) $valor)] : ['present' => false];
    }

    /** Un número del gimnasio: si está, y sus cuatro últimos dígitos. */
    private static function number(mixed $valor): array
    {
        $valor = self::text($valor);
        if (! filled($valor)) {
            return ['present' => false];
        }

        return ['present' => true, 'last4' => substr((string) preg_replace('/[^0-9]/', '', (string) $valor), -4)];
    }

    /** `{official:true}` con el host oficial; si no, `{official:false, host}` (solo el host). */
    private static function base(mixed $valor, string $oficial): array
    {
        $host = is_string($valor) ? parse_url(trim($valor), PHP_URL_HOST) : null;
        $host = is_string($host) && $host !== '' ? strtolower($host) : null;

        return $host === $oficial ? ['official' => true] : ['official' => false, 'host' => $host];
    }

    /** «AC…1234»: los 2 primeros caracteres, `…` y los 4 últimos. */
    private static function ref(string $valor): string
    {
        $valor = trim($valor);

        return mb_substr($valor, 0, 2).'…'.mb_substr($valor, -4);
    }

    /** Un importe como texto con dos decimales (Enmienda 1, Q-1). */
    private static function amount(mixed $valor): string
    {
        return number_format(is_numeric($valor) ? (float) $valor : 0.0, 2, '.', '');
    }

    private static function text(mixed $valor): ?string
    {
        return is_string($valor) ? $valor : (is_int($valor) ? (string) $valor : null);
    }
}
