<?php

namespace App\Services\UltronStudio;

use App\Models\UltronStudio\StudioCanaryNumber;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Services\Twilio\TwilioVoiceCarrier;
use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceProviders;
use App\Services\Voice\VoiceProviderStatus;
use App\Support\Access\CrmPermission;
use App\Support\Privacy\PhoneFingerprint;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LAS INTEGRACIONES COMERCIALES, DE UN VISTAZO (contrato H6 §I6.6 y §I7.2): qué
 * puede afirmar el Studio de Meta Ads, Twilio, Twilio Voice, el registro de
 * números canarios y ElevenLabs, con su evidencia. SIN RED: todo sale del
 * espejo local, de la configuración cacheada y de las tablas del Studio.
 *
 * CADA BLOQUE SE CALCULA POR SU CUENTA: si su fuente falla, sale
 * `{"id": …, "available": false}` y una línea de log con la clase del error,
 * nunca un valor inventado.
 *
 * Aquí viven también las banderas y los topes del hito, para que todas las
 * rutas los lean igual: la bandera de escrituras (rutas 47–54), la de la sonda
 * (56) y los topes de canarios y asociaciones, acotados otra vez aunque la
 * configuración ya los recorte.
 *
 * Del motor solo LEE (`UltronStudioEngineReadOnlyTest`): el estado del espejo de
 * Meta ({@see StudioMetaCampaigns}), el del OTP ({@see StudioTwilioPanel}) y la
 * coherencia con el canario ({@see StudioCanaryCoherence}).
 */
final class StudioIntegrations
{
    /** Los bloques de `GET integrations/status`, en su orden (§I7.2). */
    public const PROVIDER_IDS = ['meta_ads', 'twilio', 'twilio_voice', 'canaries', 'elevenlabs'];

    /**
     * Lo que puede afirmar cada bloque («nunca otra cosa»). Hito 8 (D8-21 e
     * «Impacto»): `twilio_voice` gana `connected_voice` (número, bandera y la
     * subcuenta de voz completa).
     */
    public const PROVIDER_CLASSIFICATIONS = [
        'meta_ads' => ['connected_read', 'degraded', 'not_configured'],
        'twilio' => ['connected_otp_only', 'degraded', 'not_configured'],
        'twilio_voice' => ['not_configured', 'disabled', 'degraded', 'connected_voice'],
        'canaries' => ['registry'],
        'elevenlabs' => ['not_configured', 'disabled', 'degraded', 'connected_read'],
    ];

    /** La clasificación de ElevenLabs sale de su código (§I7.2). */
    public const ELEVENLABS_CLASSIFICATION = [
        VoiceProviderStatus::STATE_NOT_CONFIGURED => 'not_configured',
        VoiceProviderStatus::STATE_DISABLED => 'disabled',
        VoiceProviderStatus::STATE_UNVERIFIED => 'degraded',
        VoiceProviderStatus::STATE_VERIFIED => 'connected_read',
    ];

    public const STATUS_NOTE = 'Las banderas son las del proceso web, con su caché de configuración.';

    /** Los tres niveles de prueba de voz (§I6.6), en su orden. */
    public const VOICE_LEVELS = ['text_simulation', 'integration_test', 'real_call'];

    /** Lo que una prueba de integración futura no necesita: no llamará a nadie (§I6.6). */
    public const INTEGRATION_TEST_EXCLUDED = ['not_an_active_voice_canary', 'daily_cap_zero'];

    /**
     * El bloque `can` de la ruta 45 (Hito 8, S8-c, E5-6): las 7 llaves de
     * D8-22, nombre en el DTO → permiso, en el orden de la tabla.
     */
    public const VOICE_CAPABILITIES = [
        'voiceManage' => CrmPermission::ULTRON_VOICE_MANAGE,
        'approve' => StudioAccess::APPROVE,
        'voicePublish' => CrmPermission::ULTRON_VOICE_PUBLISH,
        'voiceLabRun' => CrmPermission::ULTRON_VOICE_LAB_RUN,
        'telephonyView' => CrmPermission::TELEPHONY_VIEW,
        'telephonyCallPlace' => CrmPermission::TELEPHONY_CALL_PLACE,
        'telephonyContactsManage' => CrmPermission::TELEPHONY_CONTACTS_MANAGE,
    ];

    public const DEFAULT_CANARY_MAX_ACTIVE = 3;

    public const CANARY_MAX_LIVE = 10;

    public const DEFAULT_COUNTRY_CODES = ['57'];

    public function __construct(
        private readonly StudioMetaCampaigns $meta,
        private readonly StudioTwilioPanel $twilio,
        private readonly StudioCanaryCoherence $coherence,
    ) {}

    // ── Banderas y topes ────────────────────────────────────────────────────

    /** La bandera de escrituras del hito (rutas 47–54). Reconocer la configuración no depende de ella. */
    public static function writesEnabled(): bool
    {
        return config('ultron_studio.integrations.writes_enabled') === true;
    }

    public static function probeEnabled(): bool
    {
        return config('ultron_studio.integrations.twilio_probe.enabled') === true;
    }

    /** Activos por canal: 1–10. */
    public static function canaryMaxActive(): int
    {
        $max = config('ultron_studio.integrations.canary.max_active');

        return is_int($max) ? min(10, max(1, $max)) : self::DEFAULT_CANARY_MAX_ACTIVE;
    }

    /** Vivos por canal: fijo. */
    public static function canaryMaxLive(): int
    {
        return self::CANARY_MAX_LIVE;
    }

    /** @return list<string> los prefijos de país admitidos, `['57']` si no queda ninguno válido */
    public static function countryCodes(): array
    {
        $lista = config('ultron_studio.integrations.canary.country_codes');
        $validos = [];
        foreach (is_array($lista) ? $lista : [] as $prefijo) {
            if (is_string($prefijo) && preg_match(StudioCanaryPhone::COUNTRY_CODE_PATTERN, $prefijo) === 1 && ! in_array($prefijo, $validos, true)) {
                $validos[] = $prefijo;
            }
        }

        return $validos === [] ? self::DEFAULT_COUNTRY_CODES : $validos;
    }

    /**
     * Toma el cerrojo consultivo de la transacción en curso (PostgreSQL), con
     * READ COMMITTED PRIMERO (lección B-2 de G, 4.6 de H4 y A10.6 de H5): en
     * REPEATABLE READ la instantánea la tomaría la propia sentencia del cerrojo,
     * antes de esperar, y quien esperó no vería la fila del otro. Va la primera
     * dentro de la transacción. SQLite escribe en serie por sí mismo.
     */
    public static function lock(string $key): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32($key)]);
        }
    }

    // ── 39 · GET integrations/status ────────────────────────────────────────

    /** @return array{can:array<string,bool>,flags:array<string,bool>,providers:list<array<string,mixed>>,note:string} */
    public function status(StudioAccess $access): array
    {
        return [
            'can' => $access->integrationCapabilities(),
            'flags' => self::flags(),
            'providers' => $this->providers(),
            'note' => self::STATUS_NOTE,
        ];
    }

    /** @return array{writesEnabled:bool,twilioProbeEnabled:bool} */
    public static function flags(): array
    {
        return ['writesEnabled' => self::writesEnabled(), 'twilioProbeEnabled' => self::probeEnabled()];
    }

    /**
     * Los cinco bloques, en su orden, cada uno por su cuenta. Lo usan la ruta
     * 39 y `ultron-studio:integrations-doctor`.
     *
     * @return list<array<string,mixed>>
     */
    public function providers(): array
    {
        return [
            self::block('meta_ads', fn (): array => $this->metaBlock()),
            self::block('twilio', fn (): array => $this->twilioBlock()),
            self::block('twilio_voice', fn (): array => self::twilioVoiceBlock()),
            self::block('canaries', fn (): array => $this->canariesBlock()),
            self::block('elevenlabs', fn (): array => self::elevenLabsBlock()),
        ];
    }

    /** @return array<string,mixed> */
    private function metaBlock(): array
    {
        $conexion = $this->meta->connection();

        return self::metaBlockFrom($conexion['connection'], $conexion['classification'], $conexion['coveredTo'], $this->meta->accountCount(),
            is_string($v = config('meta.ads.graph_version')) ? $v : null);
    }

    /**
     * El bloque `meta_ads` (§I7.2) a partir de la conexión ya calculada. Pura:
     * la usa también la exportación de fixtures.
     *
     * @param  array<string,mixed>  $connection  el bloque `connection` de {@see StudioMetaCampaigns::connection()}
     * @return array<string,mixed>
     */
    public static function metaBlockFrom(array $connection, string $classification, ?string $coveredTo, int $accounts, ?string $graphVersion): array
    {
        return [
            'classification' => $classification,
            'state' => $connection['state'],
            'reason' => $connection['reason'],
            'evidence' => [
                'accounts' => $accounts,
                'lastSuccessAt' => $connection['lastSuccessAt'],
                'minutesSinceSuccess' => $connection['minutesSinceSuccess'],
                'staleAfterMinutes' => $connection['staleAfterMinutes'],
                'coveredTo' => $coveredTo,
                'syncEnabled' => $connection['syncEnabled'],
                'graphVersion' => $graphVersion,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function twilioBlock(): array
    {
        return self::twilioBlockFrom($this->twilio->summary());
    }

    /**
     * El bloque `twilio` (§I7.2) a partir del resumen del panel. Pura.
     *
     * @param  array<string,mixed>  $summary  {@see StudioTwilioPanel::summary()}
     * @return array<string,mixed>
     */
    public static function twilioBlockFrom(array $summary): array
    {
        return [
            'classification' => $summary['classification'],
            'state' => $summary['state'],
            'reason' => $summary['reason'],
            'evidence' => [
                'otpDriver' => $summary['otp']['driver'],
                'verifyActive' => $summary['otp']['verifyActive'],
                'sendEnabled' => $summary['otp']['sendEnabled'],
                'otpVerified' => $summary['otp']['verified'],
                'estimatedSpentTodayUsd' => $summary['cost']['estimatedSpentUsd'] ?? null,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function twilioVoiceBlock(): array
    {
        return self::twilioVoiceBlockFrom(self::twilioVoice(), TwilioVoiceCarrier::configured());
    }

    /**
     * @param  array{enabled:bool,fromConfigured:bool,dailyCallCap:int}  $voice
     * @param  bool  $subaccountReady  la credencial de la subcuenta de voz completa (Hito 8, D8-5)
     * @return array<string,mixed>
     */
    public static function twilioVoiceBlockFrom(array $voice, bool $subaccountReady = false): array
    {
        return [
            'classification' => self::classifyTwilioVoice($voice['fromConfigured'], $voice['enabled'], $subaccountReady),
            'evidence' => ['enabled' => $voice['enabled'], 'fromConfigured' => $voice['fromConfigured']],
        ];
    }

    /** @return array<string,mixed> */
    private function canariesBlock(): array
    {
        $recuentos = StudioCanaryNumber::query()->toBase()
            ->selectRaw('channel, status, count(*) as n')
            ->groupBy('channel', 'status')
            ->get()
            ->map(static fn (object $f): array => ['channel' => (string) $f->channel, 'status' => (string) $f->status, 'n' => (int) $f->n])
            ->all();

        return self::canariesBlockFrom($recuentos, $this->coherence->read()['verdict']);
    }

    /**
     * El bloque `canaries` (§I7.2): recuentos del registro y el veredicto de
     * la coherencia. Pura.
     *
     * @param  list<array{channel:string,status:string,n:int}>  $counts
     * @return array<string,mixed>
     */
    public static function canariesBlockFrom(array $counts, string $verdict): array
    {
        $de = static fn (?string $canal, string $estado): int => array_sum(array_map(
            static fn (array $f): int => ($canal === null || $f['channel'] === $canal) && $f['status'] === $estado ? $f['n'] : 0,
            $counts,
        ));

        return [
            'classification' => 'registry',
            'evidence' => [
                'active' => [
                    'whatsapp' => $de(StudioCanaryPhone::CHANNEL_WHATSAPP, StudioCanaryNumber::STATUS_ACTIVE),
                    'voice' => $de(StudioCanaryPhone::CHANNEL_VOICE, StudioCanaryNumber::STATUS_ACTIVE),
                ],
                'pending' => $de(null, StudioCanaryNumber::STATUS_PENDING),
                'authorized' => $de(null, StudioCanaryNumber::STATUS_AUTHORIZED),
                'verdict' => $verdict,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function elevenLabsBlock(): array
    {
        return self::elevenLabsBlockFrom(VoiceProviders::status(), self::voiceState());
    }

    /**
     * El bloque `elevenlabs` (§I7.2): la clasificación sale del código del Studio. Pura.
     *
     * @return array<string,mixed>
     */
    public static function elevenLabsBlockFrom(VoiceProviderStatus $status, string $code): array
    {
        return [
            'classification' => self::ELEVENLABS_CLASSIFICATION[$code],
            'code' => $code,
            'evidence' => ['enabled' => $status->enabled, 'missing' => $status->missing],
        ];
    }

    // ── 45 · GET integrations/voice ─────────────────────────────────────────

    /**
     * La voz, sin red y sin llamar a nadie: el estado de ElevenLabs (con el
     * `ELEVENLABS_VERIFIED` que solo calcula el Studio), Twilio Voice, la
     * política de llamadas y los tres niveles de prueba. En H6 la política
     * nunca permite: `calls_disabled_in_code` va siempre el primero.
     *
     * Hito 8 (S8-c, E5-6): además, lo que puede la sesión con las llaves de la
     * voz ({@see voiceCapabilities()}) y la plantilla vigente para el
     * formulario del perfil ({@see voiceTemplate()}).
     *
     * @return array<string,mixed>
     */
    public function voicePanel(StudioAccess $access): array
    {
        $estado = VoiceProviders::status();
        $codigo = self::voiceState();
        $ultima = StudioIntegrationConfig::latest(StudioIntegrationSnapshot::PROVIDER_VOICE, StudioIntegrationSnapshot::KIND_PROBE);
        $panel = self::voicePanelFrom($estado, $codigo, $ultima === null ? null : [
            'at' => $ultima->created_at?->toIso8601String(),
            'outcome' => is_string($ultima->facts['outcome'] ?? null) ? $ultima->facts['outcome'] : null,
            'agentAudioUlaw8000' => is_bool($ultima->facts['agentAudioUlaw8000'] ?? null) ? $ultima->facts['agentAudioUlaw8000'] : null,
        ], self::twilioVoice(), self::voiceCanary());

        // Hito 8: el estado real de la voz, la fase que se deduce, el freno y
        // la publicación vigente. Cada bloque por su cuenta: si su fuente falla,
        // `{available:false}` y una línea de log (nunca un valor inventado).
        $telefonia = self::block('telephonyState', fn (): array => self::voiceTelephony($codigo));
        $panel['telephonyState'] = $telefonia;
        $panel['phase'] = ($telefonia['available'] ?? false) === true ? self::voicePhase($telefonia) : null;
        $panel['brake'] = self::block('brake', fn (): array => ['state' => StudioVoiceEvidence::brakeState()]);
        $panel['publishing'] = self::block('publishing', fn (): array => StudioVoicePublishing::status());
        $panel['publication'] = self::block('publication', fn (): array => StudioVoicePublications::state());

        // S8-c: sin bloque propio. Las llaves ya se calcularon al entrar
        // (`requirePerson`) y la plantilla son constantes del código.
        $panel['can'] = self::voiceCapabilities($access);
        $panel['template'] = self::voiceTemplate();

        return $panel;
    }

    /**
     * El bloque `can` de la ruta 45: las 7 llaves de D8-22 ({@see
     * VOICE_CAPABILITIES}) resueltas para la sesión, con el mecanismo de
     * {@see StudioAccess::integrationCapabilities()}: cada llave presupone ver
     * el Studio. Solo dice qué enseñar; cada ruta lo vuelve a exigir.
     *
     * @return array<string,bool>
     */
    public static function voiceCapabilities(StudioAccess $access): array
    {
        $ve = $access->can(StudioAccess::VIEW);

        return array_map(static fn (string $permiso): bool => $ve && $access->can($permiso), self::VOICE_CAPABILITIES);
    }

    /**
     * El bloque `template` de la ruta 45 (Q-2 de FE8): lo que el formulario
     * del perfil de voz necesita de la plantilla vigente
     * ({@see StudioVoiceTemplate}, con P-3 aplicado a las herramientas del
     * servidor, que es el catálogo que admite el compilador) y los topes del
     * perfil. `maxDurationSeconds.max` es el tope del servidor
     * (`VOICE_PER_CALL_MAX_SECONDS` recortado): un perfil que lo supere no se
     * crea ni compila (`duration_exceeds_limit`); `ceiling` es el del esquema.
     * Solo lectura y solo constantes del código: sin teléfonos ni secretos.
     *
     * @return array<string,mixed>
     */
    public static function voiceTemplate(): array
    {
        return [
            'version' => StudioVoiceTemplate::VERSION,
            'hash' => StudioVoiceTemplate::hash(),
            'aiNotice' => StudioVoiceTemplate::AI_NOTICE,
            'greetingMax' => StudioVoiceTemplate::GREETING_MAX,
            'textMin' => StudioVoiceTemplate::TEXT_MIN,
            'holes' => StudioVoiceTemplate::HOLES,
            'tones' => StudioVoiceTemplate::TONES,
            'goals' => StudioVoiceTemplate::GOALS,
            'fallbacks' => StudioVoiceTemplate::FALLBACKS,
            'serverTools' => StudioVoiceTemplate::serverTools(),
            'systemTools' => StudioVoiceTemplate::SYSTEM_TOOLS,
            'limits' => [
                'maxDurationSeconds' => [
                    'min' => StudioVoiceCompiler::MIN_DURATION_SECONDS,
                    'max' => StudioVoicePublishing::maxCallSeconds(),
                    'ceiling' => StudioVoiceCompiler::MAX_DURATION_CEILING,
                ],
                'retentionDays' => ['max' => StudioVoiceCompiler::MAX_RETENTION_DAYS],
                'transferDestinations' => ['max' => StudioVoiceCompiler::MAX_DESTINATIONS],
            ],
        ];
    }

    /**
     * Las banderas y piezas de la telefonía, SIN SECRETOS: solo booleanos,
     * recuentos y el estado del proveedor (Hito 8, D8-11, D8-12, D8-15).
     *
     * @return array<string,mixed>
     */
    public static function voiceTelephony(string $providerState): array
    {
        return [
            'providerState' => $providerState,
            'subaccountReady' => TwilioVoiceCarrier::configured(),
            'numberImported' => is_string($id = config('voice.elevenlabs.agent_phone_number_id')) && trim($id) !== '',
            'inbound' => ['enabled' => config('voice.inbound.enabled') === true, 'dailyCap' => (int) config('voice.inbound.daily_cap')],
            'outbound' => [
                'enabled' => config('voice.outbound.enabled') === true,
                'scope' => config('voice.outbound.scope') === 'contacts' ? 'contacts' : 'canary',
                'dailyCap' => (int) config('voice.twilio.daily_call_cap'),
            ],
        ];
    }

    /**
     * La fase del despliegue que se DEDUCE de la configuración (contrato H8,
     * «Fases de despliegue»), pura: 4b con salientes a contactos, 4a con
     * salientes a canarios, 3 con entrantes, 2 con ElevenLabs con credenciales
     * y la subcuenta y el número listos, y 1 en cualquier otro caso. Es una
     * lectura, no una autorización: cada fase la autoriza el propietario.
     *
     * @param  array<string,mixed>  $telephony
     * @return array{id:string,basis:string}
     */
    public static function voicePhase(array $telephony): array
    {
        $credenciales = in_array($telephony['providerState'] ?? null, [VoiceProviderStatus::STATE_UNVERIFIED, VoiceProviderStatus::STATE_VERIFIED], true);
        $id = match (true) {
            ($telephony['outbound']['enabled'] ?? false) === true && ($telephony['outbound']['scope'] ?? null) === 'contacts' => '4b',
            ($telephony['outbound']['enabled'] ?? false) === true => '4a',
            ($telephony['inbound']['enabled'] ?? false) === true => '3',
            $credenciales && ($telephony['subaccountReady'] ?? false) === true && ($telephony['numberImported'] ?? false) === true => '2',
            default => '1',
        };

        return ['id' => $id, 'basis' => 'config'];
    }

    /**
     * `GET integrations/voice` a partir de sus piezas: la política la decide
     * `VoiceCallPolicy` con el contexto que arma el Studio (`source` es siempre
     * el registro). Pura: la usa también la exportación de fixtures.
     *
     * @param  array{at:?string,outcome:?string,agentAudioUlaw8000:?bool}|null  $lastVerification
     * @param  array{enabled:bool,fromConfigured:bool,dailyCallCap:int}  $voice
     * @return array<string,mixed>
     */
    public static function voicePanelFrom(VoiceProviderStatus $status, string $code, ?array $lastVerification, array $voice, string $canary): array
    {
        $politica = VoiceCallPolicy::decide(new VoiceCallContext(
            twilioVoiceEnabled: $voice['enabled'],
            twilioVoiceFromConfigured: $voice['fromConfigured'],
            providerState: $code,
            canary: $canary,
            source: VoiceCallContext::SOURCE_CANARY_REGISTRY,
            dailyCap: $voice['dailyCallCap'],
        ));

        return [
            'elevenlabs' => [
                'state' => $code,
                'enabled' => $status->enabled,
                'missing' => $status->missing,
                'region' => $status->region,
                'lastVerification' => $lastVerification,
            ],
            'twilioVoice' => $voice,
            'policy' => ['allowed' => $politica->allowed, 'reasons' => $politica->reasons],
            'levels' => self::voiceLevels($politica->reasons),
        ];
    }

    /**
     * Los tres niveles (§I6.6): la simulación de texto está siempre (en el
     * laboratorio, sin coste); la prueba de integración y la llamada real
     * reutilizan los motivos de la política, y la de integración no exige
     * canario de voz activo ni cupo (no llamará a nadie).
     *
     * @param  list<string>  $reasons  los de la política, en su orden
     * @return list<array<string,mixed>>
     */
    public static function voiceLevels(array $reasons): array
    {
        $integracion = array_values(array_filter($reasons, static fn (string $r): bool => ! in_array($r, self::INTEGRATION_TEST_EXCLUDED, true)));

        return [
            ['id' => 'text_simulation', 'available' => true, 'cost' => 'none', 'where' => 'laboratorio'],
            ['id' => 'integration_test', 'available' => $integracion === [], 'reasons' => $integracion],
            ['id' => 'real_call', 'available' => $reasons === [], 'reasons' => $reasons],
        ];
    }

    /**
     * El estado de la voz que afirma el Studio: el del proveedor y, además,
     * `ELEVENLABS_VERIFIED` si está `UNVERIFIED` y la ÚLTIMA verificación de voz
     * salió `ok` hace menos de 30 días.
     */
    public static function voiceState(?CarbonInterface $now = null): string
    {
        $estado = VoiceProviders::status()->state;
        if ($estado !== VoiceProviderStatus::STATE_UNVERIFIED) {
            return $estado;
        }
        $ultima = StudioIntegrationConfig::latest(StudioIntegrationSnapshot::PROVIDER_VOICE, StudioIntegrationSnapshot::KIND_PROBE);

        return self::studioVoiceState($estado, $ultima === null ? null : ['outcome' => (string) $ultima->outcome, 'at' => $ultima->created_at], $now ?? CarbonImmutable::now());
    }

    /**
     * Pura (paridad con `voiceDoctor · studioState`): `VERIFIED` = `UNVERIFIED`
     * + la última verificación `ok` de hace menos de 30 días (estricto).
     *
     * @param  array{outcome:string,at:CarbonInterface|string|null}|null  $lastProbe
     */
    public static function studioVoiceState(string $providerState, ?array $lastProbe, CarbonInterface $now): string
    {
        if ($providerState !== VoiceProviderStatus::STATE_UNVERIFIED || $lastProbe === null || $lastProbe['outcome'] !== 'ok' || $lastProbe['at'] === null) {
            return $providerState;
        }
        $en = $lastProbe['at'] instanceof CarbonInterface ? $lastProbe['at'] : CarbonImmutable::parse($lastProbe['at']);
        $edad = $en->diffInSeconds($now, false);

        return $edad < StudioIntegrationConfig::VOICE_VERIFIED_MAX_AGE_SECONDS ? VoiceProviderStatus::STATE_VERIFIED : $providerState;
    }

    /**
     * El canario de voz para la política (§I6.6): `active_voice` si hay alguno
     * activo; si no, `not_active` si hay alguno vivo; si no, `not_registered`.
     */
    public static function voiceCanary(): string
    {
        $estados = StudioCanaryNumber::query()
            ->where('channel', StudioCanaryPhone::CHANNEL_VOICE)
            ->distinct()
            ->pluck('status')
            ->all();

        return self::voiceCanaryFrom($estados);
    }

    /** @param  list<string>  $voiceStatuses */
    public static function voiceCanaryFrom(array $voiceStatuses): string
    {
        return match (true) {
            in_array(StudioCanaryNumber::STATUS_ACTIVE, $voiceStatuses, true) => VoiceCallContext::CANARY_ACTIVE_VOICE,
            array_intersect($voiceStatuses, StudioCanaryNumber::LIVE) !== [] => VoiceCallContext::CANARY_NOT_ACTIVE,
            default => VoiceCallContext::CANARY_NOT_REGISTERED,
        };
    }

    /**
     * Twilio Voice tras su bandera (§I5.5): nada lo usa para ejecutar.
     *
     * @return array{enabled:bool,fromConfigured:bool,dailyCallCap:int}
     */
    public static function twilioVoice(): array
    {
        return [
            'enabled' => config('voice.twilio.enabled') === true,
            'fromConfigured' => self::voiceFromConfigured(config('voice.twilio.from')),
            'dailyCallCap' => (int) config('voice.twilio.daily_call_cap'),
        ];
    }

    /** ¿Hay número de voz? Solo un E.164 estricto: con espacios o separadores, no (PC-2 del fixture). */
    public static function voiceFromConfigured(mixed $from): bool
    {
        return is_string($from) && preg_match(PhoneFingerprint::E164_PATTERN, $from) === 1;
    }

    // ── Reglas de clasificación (§I7.2), puras ──────────────────────────────

    /**
     * Twilio: sin el driver de Twilio o sin Verify completo, `not_configured`;
     * con el interruptor bajado o el techo duro alcanzado, `degraded`; si no,
     * `connected_otp_only`. Los recuentos de retos verificados nunca degradan.
     *
     * @return array{classification:string,state:string,reason:?string}
     */
    public static function classifyTwilio(string $otpDriver, bool $verifyActive, bool $sendEnabled, float $spentUsd, float $hardLimitUsd): array
    {
        return match (true) {
            $otpDriver !== 'twilio' => ['classification' => 'not_configured', 'state' => 'not_configured', 'reason' => 'otp_driver_not_twilio'],
            ! $verifyActive => ['classification' => 'not_configured', 'state' => 'not_configured', 'reason' => 'verify_incomplete'],
            ! $sendEnabled => ['classification' => 'degraded', 'state' => 'degraded', 'reason' => 'kill_switch'],
            $hardLimitUsd > 0 && $spentUsd >= $hardLimitUsd => ['classification' => 'degraded', 'state' => 'degraded', 'reason' => 'hard_limit_reached'],
            default => ['classification' => 'connected_otp_only', 'state' => 'ok', 'reason' => null],
        };
    }

    /**
     * Twilio Voice: sin número E.164, `not_configured`; si no, `disabled` con
     * la bandera apagada; con ella encendida, `connected_voice` si además la
     * credencial de la subcuenta de voz está completa (Hito 8) y `degraded` si
     * no. Sin el tercer argumento, la regla del Hito 6.
     */
    public static function classifyTwilioVoice(bool $fromConfigured, bool $enabled, bool $subaccountReady = false): string
    {
        return match (true) {
            ! $fromConfigured => 'not_configured',
            ! $enabled => 'disabled',
            $subaccountReady => 'connected_voice',
            default => 'degraded',
        };
    }

    /**
     * Un bloque de `GET integrations/status` en una línea legible para la
     * consola (`ultron-studio:integrations-doctor`): id, clasificación y, si los
     * hay, estado, motivo, código y el veredicto de la coherencia.
     *
     * @param  array<string,mixed>  $provider
     */
    public static function describe(array $provider): string
    {
        $extra = array_filter([
            isset($provider['state']) ? 'estado '.$provider['state'] : null,
            isset($provider['reason']) ? 'motivo '.$provider['reason'] : null,
            isset($provider['code']) ? 'código '.$provider['code'] : null,
            isset($provider['evidence']['verdict']) ? 'coherencia '.$provider['evidence']['verdict'] : null,
        ]);

        return $provider['id'].': '.$provider['classification'].($extra === [] ? '' : ' ('.implode(', ', $extra).')');
    }

    /**
     * Un bloque de `GET integrations/status`, calculado por su cuenta: si su
     * fuente falla, `{id, available:false}` y una línea de log con la clase.
     *
     * @param  callable():array<string,mixed>  $calcular
     * @return array<string,mixed>
     */
    private static function block(string $id, callable $calcular): array
    {
        try {
            return ['id' => $id, 'available' => true] + $calcular();
        } catch (Throwable $e) {
            // Tragado a propósito: un bloque caído no tumba el estado de los
            // demás, y no se inventa ningún valor. Sin el mensaje.
            Log::warning('ultron_studio.integration_status_unavailable', ['provider' => $id, 'error_class' => class_basename($e)]);

            return ['id' => $id, 'available' => false];
        }
    }
}
