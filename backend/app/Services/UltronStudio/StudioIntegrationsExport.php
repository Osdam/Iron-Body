<?php

namespace App\Services\UltronStudio;

use App\Models\MetaAdEntity;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioCampaignLink;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Services\Twilio\TwilioProbeSanitizer;
use App\Services\Twilio\TwilioReadOnlyClient;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookSignature;
use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceCheckResult;
use App\Services\Voice\VoiceProviderStatus;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Las FORMAS de las integraciones comerciales para las pruebas del CRM
 * (contrato H6 §I10, «Fixtures»): lo que exporta `ultron-studio:export-fixtures`
 * a `backend-integrations.testing.ts`. Los conjuntos cerrados de §I2, los
 * códigos de §I7.3 con su estado HTTP, las 4 llaves con su texto literal, los
 * topes, los textos fijos y EJEMPLOS de cada DTO, hechos con los MISMOS
 * serializadores que las respuestas sobre datos sintéticos fijos: sin base de
 * datos, sin teléfonos en claro y sin secretos (de la configuración, solo
 * huellas). Las huellas y los ids opacos se calculan con una `APP_KEY`
 * SINTÉTICA fija, para que el fichero no dependa de la máquina que lo genera.
 */
final class StudioIntegrationsExport
{
    /** La de los fixtures del tester (bytes 00..1f): nunca la de un entorno real. */
    public const SYNTHETIC_APP_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** Los códigos que puede mandar el servidor en `integrations/*` (§I7.3), con su estado HTTP, en el orden del contrato. */
    public const ERRORS = [
        'integrations_writes_disabled' => 409,
        'meta_not_configured' => 409,
        'campaign_not_found' => 404,
        'link_not_found' => 404,
        'version_not_linkable' => 409,
        'campaign_already_linked' => 409,
        'campaign_link_moved' => 409,
        'campaign_links_limit' => 409,
        'canary_not_found' => 404,
        'canary_number_exists' => 409,
        'canary_live_limit' => 409,
        'canary_active_limit' => 409,
        'config_changed' => 409,
        'probe_disabled' => 409,
        'twilio_not_configured' => 409,
        'four_eyes_required' => 403,
        'invalid_transition' => 409,
        'client_request_reused' => 409,
        'integrity_mismatch' => 409,
        'version_not_found' => 404,
        'forbidden' => 403,
        'person_required' => 403,
    ];

    /** Las cuatro llaves nuevas, en su orden (§I7.1). */
    public const PERMISSIONS = [StudioAccess::CAMPAIGNS_MANAGE, StudioAccess::CANARY_MANAGE, StudioAccess::CANARY_AUTHORIZE, StudioAccess::INTEGRATIONS_MANAGE];

    /** Los eventos nuevos del libro (§I8). */
    public const AUDIT_EVENTS = [
        'campaign_link.created', 'campaign_link.deactivated', 'canary_number.registered', 'canary_number.authorized',
        'canary_number.four_eyes_denied', 'canary_number.activated', 'canary_number.deactivated', 'canary_number.revoked',
        'canary_number.expectation_set', 'integration.config_acknowledged', 'integration.probe_run',
    ];

    /** @return array<string,mixed> */
    public static function shapes(): array
    {
        $antes = config('app.key');
        config(['app.key' => self::SYNTHETIC_APP_KEY]);
        try {
            return [
                'version' => 1,
                'catalogVersion' => StudioCatalog::VERSION,
                'sets' => self::sets(),
                'providers' => StudioIntegrations::PROVIDER_IDS,
                'providerClassifications' => StudioIntegrations::PROVIDER_CLASSIFICATIONS,
                'canaryActions' => array_keys(StudioCanaryNumbers::ALLOWED_FROM),
                'canaryStatusLabels' => StudioCanaryNumbers::STATUS_LABELS,
                'probeSteps' => TwilioReadOnlyClient::STEPS,
                'probeStepOutcomes' => TwilioProbeSanitizer::STEP_OUTCOMES,
                'voiceLevels' => StudioIntegrations::VOICE_LEVELS,
                'auditSubjects' => StudioAuditEvent::SUBJECT_TYPES,
                'auditEvents' => self::AUDIT_EVENTS,
                'errors' => self::ERRORS,
                'permissions' => self::permissions(),
                'limits' => [
                    'perPageDefault' => 20,
                    'perPageMax' => StudioCanaryNumbers::PER_PAGE_MAX,
                    'reasonMin' => StudioCanaryNumbers::REASON_MIN,
                    'reasonMax' => StudioCanaryNumbers::REASON_MAX,
                    'noteMax' => StudioIntegrationConfig::NOTE_MAX,
                    'phoneMax' => StudioCanaryPhone::RAW_MAX,
                    'aliasMin' => StudioCanaryPhone::ALIAS_MIN,
                    'aliasMax' => StudioCanaryPhone::ALIAS_MAX,
                    'canaryMaxActive' => [StudioIntegrations::DEFAULT_CANARY_MAX_ACTIVE, 1, 10],
                    'canaryMaxLive' => StudioIntegrations::CANARY_MAX_LIVE,
                    'campaignLinksMaxActive' => [StudioCampaignLinks::DEFAULT_MAX_ACTIVE, 1, 200],
                    'probeCooldownSeconds' => StudioTwilioProbe::COOLDOWN_SECONDS,
                    'probePerHourPerPerson' => StudioTwilioProbe::PER_HOUR_PER_PERSON,
                    'probePerHourPerNetwork' => StudioTwilioProbe::PER_HOUR_PER_NETWORK,
                ],
                'messages' => [
                    'phoneInvalidFormat' => StudioCanaryPhone::MESSAGES[StudioCanaryPhone::INVALID_FORMAT],
                    'phoneCountryNotAllowed' => StudioCanaryPhone::MESSAGES[StudioCanaryPhone::COUNTRY_NOT_ALLOWED],
                    'phoneNotAllowedForChannel' => StudioCanaryPhone::MESSAGES[StudioCanaryPhone::NOT_ALLOWED_FOR_CHANNEL],
                    // O-2: el mismo error con la pista, solo en WhatsApp y con un número que no es móvil.
                    'phoneNotMobileForWhatsapp' => StudioCanaryPhone::NOT_MOBILE_MESSAGE,
                    'aliasInvalid' => StudioCanaryPhone::ALIAS_MESSAGE,
                    // AS-1: motivos (48, 52, 53) y nota (55) con un teléfono o un correo.
                    'contactData' => StudioCanaryPhone::CONTACT_DATA_MESSAGE,
                ],
                'examples' => self::examples(),
            ];
        } finally {
            config(['app.key' => $antes]);
        }
    }

    /** @return array<string, list<string>> */
    private static function sets(): array
    {
        return [
            'classification' => ['connected_read', 'connected_otp_only', 'degraded', 'disabled', 'not_configured', 'registry'],
            'metaConnectionState' => StudioMetaCampaigns::CONNECTION_STATES,
            'metaConnectionReason' => ['missing_access_token', 'missing_ad_account_id', 'invalid_ad_account_id', 'invalid_timezone', 'sync_disabled', 'stale', 'failed'],
            'metaEffectiveStatusFilter' => StudioMetaCampaigns::STATUS_FILTERS,
            'campaignLinkStatus' => StudioCampaignLink::STATUSES,
            'campaignLinkTargetKind' => StudioCampaignLink::TARGET_KINDS,
            'linkableVersionStatus' => StudioCampaignLinks::linkableStatuses(),
            'canaryChannel' => StudioCanaryPhone::CHANNELS,
            'canaryStatus' => StudioCanaryNumber::STATUSES,
            'coherenceVerdict' => StudioCanaryCoherence::VERDICTS,
            'effectiveVersionSource' => ['publication_chain', 'none'],
            'effectiveVersionState' => [StudioPublications::STATE_APPLIED, StudioPublications::STATE_LEGACY, StudioPublications::STATE_OFF, StudioPublications::STATE_FALLBACK],
            'effectiveVersionNoneReason' => ['not_engine_canary', 'no_voice_engine', 'coherence_unreadable', 'chain_unreadable'],
            'expectation' => ['none', 'match', 'mismatch', 'not_applicable'],
            'actionReason' => StudioCanaryNumbers::ACTION_REASONS,
            'snapshotProvider' => StudioIntegrationSnapshot::PROVIDERS,
            'snapshotKind' => StudioIntegrationSnapshot::KINDS,
            'snapshotActorType' => StudioIntegrationSnapshot::ACTOR_TYPES,
            'probeOutcome' => TwilioProbeSanitizer::OUTCOMES,
            'configState' => ['authorized', 'drift', 'never_authorized'],
            'voiceState' => [...VoiceProviderStatus::STATES, VoiceProviderStatus::STATE_VERIFIED],
            'voiceMissing' => VoiceProviderStatus::PIECES,
            'voiceVerificationOutcome' => VoiceCheckResult::OUTCOMES,
            'elevenlabsSignature' => ElevenLabsWebhookSignature::RESULTS,
            'voicePolicyReason' => VoiceCallPolicy::REASONS,
        ];
    }

    /** @return list<array{key:string,label:string,help:?string,administrator:bool}> */
    private static function permissions(): array
    {
        $filas = array_column(PermissionCatalog::rows(), null, 'key');
        $administrador = CrmPermission::defaultsFor('Administrador');

        return array_map(static fn (string $llave): array => [
            'key' => $llave,
            'label' => (string) ($filas[$llave]['label'] ?? $llave),
            'help' => $filas[$llave]['help'] ?? null,
            'administrator' => in_array($llave, $administrador, true),
        ], self::PERMISSIONS);
    }

    /** @return array<string,mixed> */
    private static function examples(): array
    {
        $ahora = CarbonImmutable::parse('2026-10-31T21:27:40+00:00');
        $cuentas = ['1000000000004714', '1000000000009023', '1000000000005856'];
        $nombres = ['IronBody2', 'Iron Body Sede Norte', null];
        $publicas = [];
        foreach ($cuentas as $i => $c) {
            $publicas[$c] = MetaAdEntity::publicAccount($c, $nombres[$i]);
        }

        // ── Meta y asociaciones ──
        $statusAll = [
            'status' => 'ok', 'last_success_at' => '2026-10-31T21:15:40+00:00', 'minutes_since_success' => 12, 'covered_to' => '2026-10-31',
            'accounts' => array_map(static fn (string $c): array => $publicas[$c] + ['status' => $c === $cuentas[2] ? 'stale' : 'ok', 'last_success_at' => '2026-10-31T21:15:40+00:00'], $cuentas),
        ];
        $conexion = StudioMetaCampaigns::connectionFrom(null, $statusAll, true, 180);
        $sinConfigurar = StudioMetaCampaigns::connectionFrom('missing_access_token', null, true, 180);

        $v = ['uuid' => '22222222-2222-4222-8222-222222222222', 'label' => 'Tono profesional (propuesta)', 'status' => 'CANARY', 'integrityOk' => true];
        $activa = self::link(1, 'a1a1a1a1-0000-4000-8000-000000000001', $cuentas[0], '120000000000000387', 'Pauta octubre ***4821', 'version', 'active', $ahora->subHours(3));
        $vieja = self::link(2, 'a1a1a1a1-0000-4000-8000-000000000002', $cuentas[0], '120000000000000387', 'Pauta octubre ***4821', 'baseline', 'inactive', $ahora->subDays(2), $ahora->subHours(3), 'Sustituida por otra asociación');
        $activaDto = StudioCampaignLinks::dto($activa, $v, true, 'Pauta octubre ***4821', null);
        $viejaDto = StudioCampaignLinks::dto($vieja, null, true, 'Pauta octubre ***4821', $activa->uuid);
        $cerrada = self::link(1, 'a1a1a1a1-0000-4000-8000-000000000001', $cuentas[0], '120000000000000387', 'Pauta octubre ***4821', 'version', 'inactive', $ahora->subHours(3), $ahora, 'Campaña terminada');

        $campanas = [
            StudioMetaCampaigns::campaignDto('120000000000000387', 'Pauta octubre 3001234821', 'ACTIVE', $ahora->subMinutes(42), $publicas[$cuentas[0]], 'ok', CarbonImmutable::parse('2026-10-31T21:15:00+00:00'), $ahora)
                + ['ultron' => ['link' => $activaDto]],
            StudioMetaCampaigns::campaignDto('120000000000000512', 'Retargeting gimnasio', 'PAUSED', CarbonImmutable::parse('2026-10-29T10:00:00+00:00'), $publicas[$cuentas[2]], 'stale', CarbonImmutable::parse('2026-10-31T21:15:00+00:00'), $ahora)
                + ['ultron' => ['link' => null]],
        ];

        // ── Números canarios ──
        $engine = ['verdict' => StudioCanaryCoherence::IN_SYNC, 'engineCanaryConfigured' => true, 'engineHash' => str_repeat('e', 64), 'activeWhatsapp' => 1];
        $cadena = static fn (): array => ['state' => 'applied', 'canary' => ['versionUuid' => $v['uuid'], 'label' => $v['label']], 'rest' => null];
        $sa = ['campaignsManage' => true, 'canaryManage' => true, 'canaryAuthorize' => true, 'integrationsManage' => true];
        $wa = self::number(1, 'c1c1c1c1-0000-4000-8000-000000000001', 'whatsapp', 'Móvil de pruebas 1', str_repeat('e', 64), '4821', 'active', $ahora->subDay());
        $voz = self::number(2, 'c1c1c1c1-0000-4000-8000-000000000002', 'voice', 'Fijo de recepción', str_repeat('d', 64), '0001', 'pending', $ahora->subHours(2));
        $esperada = ['uuid' => $v['uuid'], 'label' => $v['label'], 'status' => 'CANARY', 'integrityOk' => true];
        $waDto = StudioCanaryNumbers::dto($wa, $esperada, StudioCanaryNumbers::resolveExpectation('whatsapp', true, $cadena, $esperada), true,
            StudioCanaryNumbers::actionsFor($sa, true, 'active', false, 1, 3));
        $vozDto = StudioCanaryNumbers::dto($voz, null, StudioCanaryNumbers::resolveExpectation('voice', null, $cadena, null), null,
            StudioCanaryNumbers::actionsFor($sa, true, 'pending', true, 0, 3));
        // El teléfono del lead canario no se pudo leer y el registro sí: el recuento sale (Q-6); null solo si el recuento falla.
        $ilegible = ['verdict' => StudioCanaryCoherence::UNREADABLE, 'engineCanaryConfigured' => true, 'engineHash' => null, 'activeWhatsapp' => 1];
        $waIlegible = StudioCanaryNumbers::dto($wa, null, StudioCanaryNumbers::resolveExpectation('whatsapp', StudioCanaryCoherence::isEngineCanary($ilegible, 'whatsapp', 'active', str_repeat('e', 64)), $cadena, null),
            null, StudioCanaryNumbers::actionsFor($sa, false, 'active', false, 1, 3));
        $nuevo = self::number(3, 'c1c1c1c1-0000-4000-8000-000000000003', 'whatsapp', 'Móvil de pruebas 2', str_repeat('f', 64), '0002', 'pending', $ahora);
        $nuevoDto = StudioCanaryNumbers::dto($nuevo, null, StudioCanaryNumbers::resolveExpectation('whatsapp', null, $cadena, null), null,
            StudioCanaryNumbers::actionsFor(['canaryManage' => true, 'canaryAuthorize' => false], true, 'pending', true, 1, 3));
        $limites = ['maxActivePerChannel' => 3, 'maxLivePerChannel' => 10, 'countryCodes' => ['57'], 'writesEnabled' => true];

        // ── Twilio ──
        $hechosTwilio = self::withConfig(self::twilioConfig(), static fn (): array => StudioIntegrationConfig::twilioFacts());
        $resumen = StudioTwilioPanel::summaryFrom('twilio', true, true, ['date' => '2026-10-31', 'estimated_spent_usd' => 0.41, 'daily_soft_limit_usd' => 6.0, 'daily_hard_limit_usd' => 12.0],
            true, false, true, ['last24h' => 14, 'last7d' => 96, 'lastAt' => '2026-10-31T20:58:12+00:00']);
        $sonda = TwilioProbeSanitizer::sanitize(self::probeResponses(), 'otp');
        $sondaDto = StudioTwilioProbe::snapshotDto((new StudioIntegrationSnapshot)->forceFill([
            'uuid' => 'f1f1f1f1-0000-4000-8000-000000000001', 'provider' => 'twilio', 'kind' => 'probe', 'outcome' => $sonda['outcome'], 'facts' => $sonda['facts'],
            'actor_type' => 'admin', 'actor_name' => 'Root', 'actor_role' => 'Super Admin', 'created_at' => Carbon::parse('2026-10-31T21:30:00+00:00'),
        ]));
        $llamadas = ['total' => 0, 'withProviderSid' => 0, 'byStatus' => new \stdClass, 'byDirection' => new \stdClass, 'lastCreatedAt' => null];
        $vozTwilio = ['enabled' => false, 'fromConfigured' => false, 'dailyCallCap' => 0];

        // ── Voz ──
        $estadoVoz = new VoiceProviderStatus(VoiceProviderStatus::PROVIDER_NONE, VoiceProviderStatus::STATE_NOT_CONFIGURED, false, ['api_key', 'agent_id', 'webhook_secret'], 'default');

        // ── Configuración ──
        $config = [];
        foreach (StudioIntegrationSnapshot::PROVIDERS as $i => $proveedor) {
            // La voz, con su constructor puro: así el exportador no toca la
            // configuración de voz (los secretos solo los lee su proveedor) y
            // una clave real de esta máquina nunca entra en el fichero.
            $efectivo = $proveedor === StudioIntegrationSnapshot::PROVIDER_VOICE
                ? StudioIntegrationConfig::voiceFactsFrom(['api_key' => null, 'webhook_secret' => null, 'tool_secret' => null], false, null, 'default', null, false, null, 0)
                : self::withConfig([...self::twilioConfig(), ...self::metaConfig()], static fn (): array => StudioIntegrationConfig::facts($proveedor));
            $observada = self::snapshot('f1f1f1f1-0000-4000-8000-00000000000'.(2 + $i * 2), $proveedor, $efectivo, 'system', 'ultron-studio:integrations-snapshot', null, '2026-10-31T21:40:00+00:00');
            $autorizada = $proveedor === 'meta_ads' ? null : self::snapshot('f1f1f1f1-0000-4000-8000-00000000000'.(3 + $i * 2), $proveedor,
                $proveedor === 'twilio' ? array_replace($efectivo, ['verifyServiceSid' => ['present' => true, 'ref' => 'VA…1a2b']]) : $efectivo, 'admin', 'Root', 'Super Admin', '2026-10-31T21:45:00+00:00');
            $config[] = StudioIntegrationConfig::providerOverview($proveedor, $efectivo, $autorizada, $observada);
        }
        $reconocida = self::snapshot('f1f1f1f1-0000-4000-8000-000000000009', 'twilio', $config[1]['effective'], 'admin', 'Root', 'Super Admin', '2026-10-31T22:40:00+00:00', 'Nuevo servicio de Verify');

        $paginador = static fn (array $filas): array => ['data' => $filas, 'current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => count($filas)];
        $porDefecto = ['campaignsManage' => true, 'canaryManage' => true, 'canaryAuthorize' => false, 'integrationsManage' => false];

        return [
            'status' => [
                'can' => $porDefecto,
                'flags' => ['writesEnabled' => false, 'twilioProbeEnabled' => false],
                'providers' => [
                    ['id' => 'meta_ads', 'available' => true] + StudioIntegrations::metaBlockFrom($conexion['connection'], $conexion['classification'], $conexion['coveredTo'], 3, 'v26.0'),
                    ['id' => 'twilio', 'available' => true] + StudioIntegrations::twilioBlockFrom($resumen),
                    ['id' => 'twilio_voice', 'available' => true] + StudioIntegrations::twilioVoiceBlockFrom($vozTwilio),
                    ['id' => 'canaries', 'available' => true] + StudioIntegrations::canariesBlockFrom([], StudioCanaryCoherence::NOT_REGISTERED),
                    ['id' => 'elevenlabs', 'available' => true] + StudioIntegrations::elevenLabsBlockFrom($estadoVoz, VoiceProviderStatus::STATE_NOT_CONFIGURED),
                ],
                'note' => StudioIntegrations::STATUS_NOTE,
            ],
            'statusDegraded' => [
                'can' => $sa,
                'flags' => ['writesEnabled' => true, 'twilioProbeEnabled' => true],
                'providers' => [
                    ['id' => 'meta_ads', 'available' => true] + StudioIntegrations::metaBlockFrom(StudioMetaCampaigns::connectionFrom(null, ['status' => 'stale'] + $statusAll, true, 180)['connection'], 'degraded', '2026-10-30', 3, 'v26.0'),
                    ['id' => 'twilio', 'available' => true] + StudioIntegrations::twilioBlockFrom(StudioTwilioPanel::summaryFrom('twilio', true, false,
                        ['date' => '2026-10-31', 'estimated_spent_usd' => 0.0, 'daily_soft_limit_usd' => 6.0, 'daily_hard_limit_usd' => 12.0], true, false, true, ['last24h' => 0, 'last7d' => 12, 'lastAt' => null])),
                    ['id' => 'twilio_voice', 'available' => false],
                    ['id' => 'canaries', 'available' => true] + StudioIntegrations::canariesBlockFrom([['channel' => 'whatsapp', 'status' => 'active', 'n' => 2], ['channel' => 'whatsapp', 'status' => 'pending', 'n' => 1]], StudioCanaryCoherence::EXTRA_ACTIVE),
                    ['id' => 'elevenlabs', 'available' => true] + StudioIntegrations::elevenLabsBlockFrom(
                        new VoiceProviderStatus(VoiceProviderStatus::PROVIDER_ELEVENLABS, VoiceProviderStatus::STATE_UNVERIFIED, true, [], 'default'), VoiceProviderStatus::STATE_UNVERIFIED),
                ],
                'note' => StudioIntegrations::STATUS_NOTE,
            ],
            'metaCampaigns' => ['connection' => $conexion['connection'], 'campaigns' => $paginador($campanas)],
            'metaCampaignsNotConfigured' => ['connection' => $sinConfigurar['connection'], 'campaigns' => $paginador([])],
            'links' => $paginador([$activaDto, $viejaDto]),
            'linkWrite' => ['replayed' => false, 'link' => $activaDto, 'replaced' => $viejaDto],
            'linkDeactivated' => ['link' => StudioCampaignLinks::dto($cerrada, $v, true, 'Pauta octubre ***4821', null)],
            'canaries' => ['coherence' => StudioCanaryCoherence::publicBlock($engine), 'limits' => $limites, 'numbers' => $paginador([$waDto, $vozDto])],
            'canariesUnreadable' => ['coherence' => StudioCanaryCoherence::publicBlock($ilegible), 'limits' => ['writesEnabled' => false] + $limites, 'numbers' => $paginador([$waIlegible])],
            'canaryWrite' => ['replayed' => false, 'number' => $nuevoDto],
            'twilio' => StudioTwilioPanel::panelFrom($resumen, $hechosTwilio, $vozTwilio, $llamadas, false, 'otp', null),
            'twilioCalls' => $paginador([[
                'status' => 'completed', 'direction' => 'outbound', 'reason' => 'Recuperación de venta abierta', 'scheduledAt' => '2026-10-30T15:00:00+00:00',
                'startedAt' => '2026-10-30T15:00:04+00:00', 'endedAt' => '2026-10-30T15:02:10+00:00', 'durationSeconds' => 126, 'hasProviderSid' => true,
                'createdAt' => '2026-10-30T14:59:58+00:00',
            ]]),
            'probe' => ['replayed' => false, 'reused' => false, 'snapshot' => $sondaDto],
            'voice' => StudioIntegrations::voicePanelFrom($estadoVoz, VoiceProviderStatus::STATE_NOT_CONFIGURED, null, $vozTwilio, VoiceCallContext::CANARY_NOT_REGISTERED),
            'config' => ['providers' => $config],
            'acknowledgement' => ['replayed' => false, 'snapshot' => StudioIntegrationConfig::acknowledgementDto($reconocida)],
        ];
    }

    private static function link(int $id, string $uuid, string $account, string $campaign, string $name, string $kind, string $status, CarbonImmutable $created, ?CarbonImmutable $closed = null, ?string $reason = null): StudioCampaignLink
    {
        return (new StudioCampaignLink)->forceFill([
            'id' => $id, 'uuid' => $uuid, 'ad_account_id' => $account, 'campaign_id' => $campaign, 'campaign_name_at_link' => $name,
            'target_kind' => $kind, 'status' => $status, 'created_by_admin_id' => 9, 'created_by_name' => 'Ana Pérez', 'created_by_role' => 'Administrador',
            'created_at' => Carbon::instance($created),
            'deactivated_at' => $closed === null ? null : Carbon::instance($closed),
            'deactivated_by_admin_id' => $closed === null ? null : 7,
            'deactivated_by_name' => $closed === null ? null : 'Root',
            'deactivated_by_role' => $closed === null ? null : 'Super Admin',
            'deactivation_reason' => $reason,
        ]);
    }

    private static function number(int $id, string $uuid, string $channel, string $alias, string $hash, string $last4, string $status, CarbonImmutable $created): StudioCanaryNumber
    {
        $activo = $status === StudioCanaryNumber::STATUS_ACTIVE;

        return (new StudioCanaryNumber)->forceFill([
            'id' => $id, 'uuid' => $uuid, 'channel' => $channel, 'alias' => $alias, 'phone_hash' => $hash, 'phone_last4' => $last4, 'country_code' => '57',
            'status' => $status, 'consent_attested' => true,
            'registered_by_admin_id' => 9, 'registered_by_name' => 'Ana Pérez', 'registered_by_role' => 'Administrador',
            'authorized_by_admin_id' => $activo ? 7 : null, 'authorized_by_name' => $activo ? 'Root' : null, 'authorized_by_role' => $activo ? 'Super Admin' : null,
            'authorized_at' => $activo ? Carbon::instance($created->addHour()) : null,
            'activated_by_admin_id' => $activo ? 7 : null, 'activated_by_name' => $activo ? 'Root' : null, 'activated_by_role' => $activo ? 'Super Admin' : null,
            'activated_at' => $activo ? Carbon::instance($created->addHours(2)) : null,
            'status_changed_at' => Carbon::instance($activo ? $created->addHours(2) : $created),
            'created_at' => Carbon::instance($created),
        ]);
    }

    /** @param  array<string,mixed>  $facts */
    private static function snapshot(string $uuid, string $provider, array $facts, string $actorType, string $name, ?string $role, string $at, ?string $note = null): StudioIntegrationSnapshot
    {
        return (new StudioIntegrationSnapshot)->forceFill([
            'uuid' => $uuid, 'provider' => $provider, 'kind' => 'config', 'fingerprint' => StudioIntegrationConfig::fingerprintOf($facts), 'facts' => $facts,
            'actor_type' => $actorType, 'actor_admin_id' => $actorType === 'admin' ? 7 : null, 'actor_name' => $name, 'actor_role' => $role, 'note' => $note,
            'created_at' => Carbon::parse($at),
        ]);
    }

    /**
     * Ejecuta `$read` con una configuración sintética y la deja como estaba.
     *
     * @param  array<string,mixed>  $values
     */
    private static function withConfig(array $values, callable $read): mixed
    {
        $antes = [];
        foreach (array_keys($values) as $clave) {
            $antes[$clave] = config($clave);
        }
        config($values);
        try {
            return $read();
        } finally {
            config($antes);
        }
    }

    /** @return array<string,mixed> */
    private static function twilioConfig(): array
    {
        return [
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'AC'.str_repeat('0', 28).'1234',
            'otp.twilio.token' => 'export-synthetic-twilio-token-0001',
            'otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e',
            'otp.twilio.from' => '+15005550006',
            'otp.twilio.base' => 'https://api.twilio.com',
            'otp.twilio.verify_base' => 'https://verify.twilio.com',
            'otp.twilio.rate_limit_unique_name' => 'ironbody-otp',
            'otp.send_enabled' => true,
            'otp.legacy_sms_sender_enabled' => false,
            'otp.cost.daily_soft' => 6.0,
            'otp.cost.daily_hard' => 12.0,
            'ultron_studio.integrations.twilio_aliases' => ['sid_source' => 'TWILIO_SID', 'token_source' => 'TWILIO_TOKEN', 'sid_consistent' => true, 'token_consistent' => true],
            'ultron_studio.integrations.twilio_probe.enabled' => false,
            'ultron_studio.integrations.twilio_probe.key_sid' => null,
            'ultron_studio.integrations.twilio_probe.key_secret' => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function metaConfig(): array
    {
        return [
            'meta.ads.access_token' => 'export-synthetic-meta-token-0001',
            'meta.ads.ad_account_ids' => ['1000000000004714', '1000000000009023', '1000000000005856'],
            'meta.ads.ad_account_id' => null,
            'meta.ads.graph_version' => 'v26.0',
            'meta.ads.sync_enabled' => true,
            'meta.ads.sync_days' => 7,
            'meta.ads.stale_after_minutes' => 180,
            'meta.ads.timezone' => 'America/Bogota',
        ];
    }

    /**
     * Respuestas crudas sintéticas de los cinco pasos (con un nombre que lleva
     * un número, como las reales): el saneador real decide lo que queda.
     *
     * @return array<string, array<string,mixed>>
     */
    private static function probeResponses(): array
    {
        $sid = 'AC'.str_repeat('0', 28).'1234';
        $ok = static fn (array $cuerpo): array => ['ok' => true, 'status' => 200, 'body' => $cuerpo, 'error' => null];

        return [
            'account' => $ok(['sid' => $sid, 'friendly_name' => 'Iron Body +57 300 000 0021', 'status' => 'active', 'type' => 'Full']),
            'numbers' => $ok(['incoming_phone_numbers' => [['sid' => 'PN'.str_repeat('0', 32), 'phone_number' => '+15005550006', 'friendly_name' => '(500) 555-0006',
                'capabilities' => ['voice' => false, 'sms' => true, 'mms' => false, 'fax' => false]]], 'next_page_uri' => null]),
            'caller_ids' => $ok(['outgoing_caller_ids' => [], 'next_page_uri' => null]),
            'calls' => $ok(['calls' => [], 'next_page_uri' => null]),
            'alerts' => $ok(['alerts' => [
                ['error_code' => '60200', 'log_level' => 'warning', 'alert_text' => 'Invalid parameter'],
                ['error_code' => '60200', 'log_level' => 'warning', 'alert_text' => 'Invalid parameter'],
                ['error_code' => '60200', 'log_level' => 'error', 'alert_text' => 'Invalid parameter'],
            ], 'meta' => ['next_page_url' => null]]),
        ];
    }
}
