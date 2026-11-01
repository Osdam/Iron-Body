<?php

namespace Tests\Unit\UltronStudio;

use App\Services\Twilio\TwilioProbeSanitizer;
use App\Services\UltronStudio\StudioCanaryNumbers;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioIntegrations;
use App\Services\UltronStudio\StudioMetaCampaigns;
use App\Services\Voice\ElevenLabs\ElevenLabsConfig;
use App\Services\Voice\ElevenLabs\ElevenLabsVoiceProvider;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookEnvelope;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookSignature;
use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceProviders;
use App\Support\Privacy\PhoneFingerprint;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\UltronStudio\UltronStudioIntegrationsParityHttpTest;
use Tests\TestCase;
use Throwable;

/**
 * PARIDAD con el fixture del contrato (`tests/fixtures/ultron-studio/integrations-cases.json`,
 * contrato H6 §I11 y Enmienda 2), escrito a mano por el tester: si una
 * implementación discrepa, se corrige la implementación, nunca el fixture.
 *
 * Es el ÍNDICE de la paridad: {@see test_cada_caso_del_fixture_lo_ejecuta_un_arnes()}
 * comprueba que cada uno de los casos de las 22 secciones lo ejecuta un arnés.
 * Aquí van las que no necesitan la base, llamando a las clases REALES (O-6):
 *  - del Studio: `phone`, `alias`, `fingerprint`, `expectation`,
 *    `metaClassification` (salvo `possiblyDeleted`), `providerClassification`,
 *    `configFacts`, `configDrift`, `voiceDoctor · studioState` y los niveles de
 *    `voiceCallPolicy`;
 *  - de V (`App\Services\Voice\**`): `elevenlabsSignature`, `elevenlabsEnvelope`,
 *    `voiceDoctor · status|baseUrl`, `voiceVerify` (con `Http::fake`) y la
 *    decisión de `voiceCallPolicy`;
 *  - de TW (`App\Services\Twilio\**`): `twilioProbeSanitize`.
 * `twilioSignatureOfficial` no tiene clase en V ni en TW: el contrato (T-1 y la
 * fila de §I11) dice «nada en el código», y el vector es para el día que se
 * extraiga el validador de `usage-trigger`. Se recorre con la implementación de
 * referencia del algoritmo publicado ({@see twilioSignatureValid()}), que es la
 * prueba que ese validador tendrá que pasar.
 * Las secciones con estado (máquina de los canarios, asociaciones, sonda,
 * reconocimiento, coherencia, `possiblyDeleted`, la matriz y `errorOrder`) van
 * por HTTP en {@see UltronStudioIntegrationsParityHttpTest}.
 */
class StudioIntegrationsParityTest extends TestCase
{
    private static ?array $fixture = null;

    public static function fixture(): array
    {
        if (self::$fixture === null) {
            $ruta = dirname(__DIR__, 2).'/fixtures/ultron-studio/integrations-cases.json';
            self::$fixture = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR) : [];
        }

        return self::$fixture;
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    private static function section(string $section, ?string $kind = null): iterable
    {
        foreach (self::fixture()[$section] ?? [] as $caso) {
            if ($kind === null || $caso['kind'] === $kind) {
                yield $caso['name'] => [$caso];
            }
        }
    }

    public static function phoneCases(): iterable
    {
        return self::section('phone');
    }

    public static function aliasCases(): iterable
    {
        return self::section('alias');
    }

    public static function fingerprintCases(): iterable
    {
        return self::section('fingerprint');
    }

    public static function expectationCases(): iterable
    {
        return self::section('expectation');
    }

    public static function metaCases(): iterable
    {
        foreach (self::section('metaClassification') as $nombre => $caso) {
            if ($caso[0]['kind'] !== 'possiblyDeleted') {
                yield $nombre => $caso;
            }
        }
    }

    public static function providerCases(): iterable
    {
        return self::section('providerClassification');
    }

    public static function configFactsCases(): iterable
    {
        return self::section('configFacts');
    }

    public static function configDriftCases(): iterable
    {
        return self::section('configDrift');
    }

    public static function studioStateCases(): iterable
    {
        return self::section('voiceDoctor', 'studioState');
    }

    public static function voicePolicyCases(): iterable
    {
        return self::section('voiceCallPolicy');
    }

    public static function voiceStatusCases(): iterable
    {
        return self::section('voiceDoctor', 'status');
    }

    public static function voiceBaseUrlCases(): iterable
    {
        return self::section('voiceDoctor', 'baseUrl');
    }

    public static function voiceVerifyCases(): iterable
    {
        return self::section('voiceVerify');
    }

    public static function signatureCases(): iterable
    {
        return self::section('elevenlabsSignature');
    }

    public static function envelopeCases(): iterable
    {
        return self::section('elevenlabsEnvelope');
    }

    public static function sanitizeCases(): iterable
    {
        return self::section('twilioProbeSanitize');
    }

    public static function twilioSignatureCases(): iterable
    {
        return self::section('twilioSignatureOfficial');
    }

    /**
     * Qué arnés ejecuta cada sección: los proveedores de este fichero y los de
     * {@see UltronStudioIntegrationsParityHttpTest}.
     *
     * @return array<string, list<callable(): iterable>>
     */
    private static function harnesses(): array
    {
        $http = UltronStudioIntegrationsParityHttpTest::class;

        return [
            'phone' => [self::phoneCases(...)],
            'alias' => [self::aliasCases(...)],
            'fingerprint' => [self::fingerprintCases(...)],
            'canaryTransitions' => [[$http, 'canaryCases']],
            'coherence' => [[$http, 'coherenceCases']],
            'expectation' => [self::expectationCases(...)],
            'campaignLinkRules' => [[$http, 'campaignCases']],
            'metaClassification' => [self::metaCases(...), [$http, 'possiblyDeletedCases']],
            'providerClassification' => [self::providerCases(...)],
            'configFacts' => [self::configFactsCases(...)],
            'configDrift' => [self::configDriftCases(...)],
            'elevenlabsSignature' => [self::signatureCases(...)],
            'elevenlabsEnvelope' => [self::envelopeCases(...)],
            'voiceDoctor' => [self::voiceStatusCases(...), self::voiceBaseUrlCases(...), self::studioStateCases(...)],
            'voiceVerify' => [self::voiceVerifyCases(...)],
            'voiceCallPolicy' => [self::voicePolicyCases(...)],
            'twilioProbeSanitize' => [self::sanitizeCases(...)],
            'twilioSignatureOfficial' => [self::twilioSignatureCases(...)],
            'probeRules' => [[$http, 'probeCases']],
            'acknowledgeRules' => [[$http, 'acknowledgeCases']],
            'routeMatrix' => [[$http, 'routeCases']],
            'errorOrder' => [[$http, 'errorOrderCases']],
        ];
    }

    /** Un caso de PHPUnit puede llevar más datos detrás del caso del fixture (el paso de `errorOrder`). */
    public function test_cada_caso_del_fixture_lo_ejecuta_un_arnes(): void
    {
        $secciones = array_keys(array_filter(self::fixture(), static fn (mixed $v): bool => is_array($v) && array_is_list($v)));
        $this->assertCount(22, $secciones, 'las 22 secciones de la Enmienda 2');
        $this->assertSame($secciones, array_keys(self::harnesses()), 'cada sección tiene su arnés, en el orden del fichero');

        $total = 0;
        foreach (self::harnesses() as $seccion => $proveedores) {
            $vistos = [];
            foreach ($proveedores as $proveedor) {
                foreach ($proveedor() as $datos) {
                    $vistos[$datos[0]['name']] = true;
                }
            }
            $esperados = array_column(self::fixture()[$seccion], 'name');
            $this->assertSame($esperados, array_values(array_intersect($esperados, array_keys($vistos))), "{$seccion}: todos sus casos");
            $this->assertCount(count($esperados), $vistos, "{$seccion}: ningún caso ajeno");
            $total += count($esperados);
        }
        $this->assertSame(719, $total, 'los casos del fichero confirmado en 7df9ed9');
    }

    public function test_el_fixture_esta_y_trae_las_secciones_del_paquete_s(): void
    {
        $f = self::fixture();
        $this->assertNotSame([], $f, 'Falta tests/fixtures/ultron-studio/integrations-cases.json');
        $this->assertSame(1, $f['version']);
        $this->assertSame('2026-10-19.1', $f['catalogVersion']);
        foreach (['phone' => 60, 'alias' => 15, 'fingerprint' => 8, 'expectation' => 12, 'metaClassification' => 15, 'configFacts' => 6] as $seccion => $minimo) {
            $this->assertGreaterThanOrEqual($minimo, count($f[$seccion]), $seccion);
        }
        // Los conjuntos que comparten el servidor y el fixture.
        $c = $f['closedSets'];
        $this->assertSame(StudioCanaryNumbers::STATUS_LABELS, $c['canaryStatusLabels']);
        $this->assertSame(StudioCanaryNumbers::ALLOWED_FROM, $c['canaryAllowedFrom']);
        $this->assertSame(StudioCanaryNumbers::ACTION_REASONS, $c['actionReasonPrecedence']);
        $this->assertSame(StudioIntegrationConfig::FACT_KEYS, $c['configFactsKeys']);
        $this->assertSame(StudioIntegrations::PROVIDER_IDS, $c['statusProviderIds']);
        $this->assertSame(StudioIntegrationConfig::VOICE_OUTCOMES, $c['voiceVerificationToSnapshotOutcome']);
        $this->assertSame(PhoneFingerprint::LABEL, $f['keys']['phoneFingerprintLabel']);
        $this->assertSame(StudioIntegrationConfig::FINGERPRINT_LABEL, $f['keys']['configFingerprintLabel']);
        $this->assertSame(StudioIntegrationConfig::VOICE_VERIFIED_MAX_AGE_SECONDS, $c['constants']['voiceVerifiedMaxAgeSeconds']);
        $this->assertSame('Sustituida por otra asociación', $c['constants']['replacedDeactivationReason']);
    }

    #[DataProvider('phoneCases')]
    public function test_phone(array $caso): void
    {
        $e = $caso['expected'];
        if ($caso['kind'] === 'masked') {
            $this->assertSame($e['masked'], StudioCanaryPhone::masked($caso['countryCode'], $caso['last4']));

            return;
        }
        $this->assertSame($e, StudioCanaryPhone::parse($caso['raw'], $caso['channel'], $caso['allowedCountryCodes']));
    }

    #[DataProvider('aliasCases')]
    public function test_alias(array $caso): void
    {
        $this->assertSame($caso['expected']['error'], StudioCanaryPhone::aliasError($caso['alias']));
    }

    #[DataProvider('fingerprintCases')]
    public function test_fingerprint(array $caso): void
    {
        $e = $caso['expected'];
        if ($caso['kind'] === 'emptyKey') {
            config(['app.key' => $caso['appKey']]);
            $this->expectException(RuntimeException::class);
            PhoneFingerprint::of($caso['e164']);

            return;
        }
        if ($caso['kind'] === 'invalidE164') {
            try {
                PhoneFingerprint::withKey($caso['e164'], (string) hex2bin($caso['appKeyRawHex']));
                $this->fail('withKey aceptó un E.164 no válido');
            } catch (InvalidArgumentException) {
            }
            config(['app.key' => $caso['appKey']]);
            $this->expectException(InvalidArgumentException::class);
            PhoneFingerprint::of($caso['e164']);

            return;
        }
        $this->assertSame($e['subkeyHex'], bin2hex(hash_hmac('sha256', PhoneFingerprint::LABEL, (string) hex2bin($caso['appKeyRawHex']), true)));
        $this->assertSame($e['fingerprint'], PhoneFingerprint::withKey($caso['e164'], (string) hex2bin($caso['appKeyRawHex'])));
        config(['app.key' => $caso['appKey']]);
        $this->assertSame($e['fingerprint'], PhoneFingerprint::of($caso['e164']), 'of() lee la APP_KEY como Laravel');
    }

    #[DataProvider('expectationCases')]
    public function test_expectation(array $caso): void
    {
        $cadena = $caso['chain'];
        $consultada = false;
        $leer = static function () use ($cadena, &$consultada): array {
            $consultada = true;
            if ($cadena['throws']) {
                throw new RuntimeException('la cadena no se puede leer');
            }

            return ['state' => $cadena['state'], 'canary' => $cadena['canary'], 'rest' => $cadena['rest']];
        };
        $esperada = $caso['expectedVersion'] === null ? null
            : ['uuid' => $caso['expectedVersion']['uuid'], 'status' => $caso['expectedVersion']['status'], 'integrityOk' => $caso['expectedVersion']['integrityOk']];

        $r = StudioCanaryNumbers::resolveExpectation($caso['row']['channel'], $caso['row']['isEngineCanary'], $leer, $esperada);

        $this->assertSame($caso['expected']['effectiveVersion'], $r['effectiveVersion']);
        $this->assertSame($caso['expected']['expectation'], $r['expectation']);
        $this->assertSame($caso['expected']['expectedVersionStale'], $r['expectedVersionStale']);
        if ($caso['row']['channel'] !== 'whatsapp' || $caso['row']['isEngineCanary'] !== true) {
            $this->assertFalse($consultada, 'la cadena solo se consulta para el canario del motor');
        }
    }

    #[DataProvider('metaCases')]
    public function test_meta_classification(array $caso): void
    {
        $f = $caso['facts'];
        $e = $caso['expected'];
        match ($caso['kind']) {
            'classify' => $this->assertSame($e, StudioMetaCampaigns::classify($f['configurationProblem'], $f['statusAll'], $f['syncEnabled'])),
            'stale' => $this->assertSame($e['stale'], StudioMetaCampaigns::isStale($f['accountState'])),
            'order' => $this->assertSame($e['order'], array_column(StudioMetaCampaigns::sort($f['campaigns']), 'entityId')),
        };
    }

    #[DataProvider('providerCases')]
    public function test_provider_classification(array $caso): void
    {
        $f = $caso['facts'];
        $e = $caso['expected'];
        match ($caso['kind']) {
            'twilio' => $this->assertSame([$e['classification'], $e['reason']], array_values(array_intersect_key(
                StudioIntegrations::classifyTwilio($f['otpDriver'], $f['verifyActive'], $f['sendEnabled'], (float) $f['estimatedSpentTodayUsd'], (float) $f['dailyHardLimitUsd']),
                ['classification' => 1, 'reason' => 1],
            ))),
            'twilio_voice' => $this->assertSame($e['classification'], StudioIntegrations::classifyTwilioVoice(StudioIntegrations::voiceFromConfigured($f['from']), $f['enabled'])),
            'elevenlabs' => $this->assertSame([$e['classification'], $e['code']], [StudioIntegrations::ELEVENLABS_CLASSIFICATION[$f['state']], $f['state']]),
        };
    }

    #[DataProvider('configFactsCases')]
    public function test_config_facts(array $caso): void
    {
        config(['app.key' => $caso['appKey']]);
        config($caso['config']);
        $facts = StudioIntegrationConfig::facts($caso['kind']);

        $this->assertSame($caso['expected']['facts'], $facts, 'mismos hechos, en el orden de §I5.3');
        $this->assertSame(StudioIntegrationConfig::FACT_KEYS[$caso['kind']], array_keys($facts));
        $this->assertSame($caso['expected']['canonicalJson'], StudioContentHasher::canonicalJson($facts), 'los bytes canónicos');
        $this->assertSame($caso['expected']['fingerprint'], StudioIntegrationConfig::fingerprintOf($facts));
        $plano = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($caso['expected']['mustNotContain'] as $centinela) {
            $this->assertStringNotContainsString($centinela, $plano);
        }
    }

    #[DataProvider('configDriftCases')]
    public function test_config_drift(array $caso): void
    {
        $autorizada = $caso['authorized'];
        $e = $caso['expected'];
        $huella = StudioIntegrationConfig::fingerprintOf($caso['effective']);

        $this->assertSame($e['effectiveFingerprint'], $huella);
        $this->assertSame($e['state'], StudioIntegrationConfig::stateFor($autorizada['fingerprint'] ?? null, $huella));
        $this->assertSame($e['drift'], StudioIntegrationConfig::drift($autorizada['facts'] ?? null, $caso['effective']));
    }

    #[DataProvider('studioStateCases')]
    public function test_voice_studio_state(array $caso): void
    {
        $ultima = $caso['voiceProbes'] === [] ? null : $caso['voiceProbes'][array_key_last($caso['voiceProbes'])];

        $this->assertSame($caso['expected']['state'], StudioIntegrations::studioVoiceState(
            $caso['providerState'],
            $ultima === null ? null : ['outcome' => $ultima['outcome'], 'at' => CarbonImmutable::parse($ultima['at'])],
            CarbonImmutable::parse($caso['now']),
        ));
    }

    #[DataProvider('voicePolicyCases')]
    public function test_voice_call_policy_levels_and_context(array $caso): void
    {
        if ($caso['kind'] === 'context') {
            $this->assertSame($caso['expected']['canary'], StudioIntegrations::voiceCanaryFrom($caso['voiceRows']));

            return;
        }
        // La decisión es de V (`VoiceCallPolicy`, la clase real); los niveles los
        // arma el Studio con los motivos que V devuelve.
        $c = $caso['context'];
        $decision = VoiceCallPolicy::decide(new VoiceCallContext(
            $c['twilioVoiceEnabled'], $c['twilioVoiceFromConfigured'], $c['providerState'], $c['canary'], $c['source'], $c['dailyCap'],
        ))->toArray();
        $this->assertSame(['allowed' => $caso['expected']['allowed'], 'reasons' => $caso['expected']['reasons']], $decision);
        $this->assertSame($caso['expected']['levels'], StudioIntegrations::voiceLevels($decision['reasons']));
    }

    // ── V: firma y sobre del webhook de ElevenLabs ──────────────────────────

    #[DataProvider('signatureCases')]
    public function test_elevenlabs_signature(array $caso): void
    {
        if (isset($caso['expected']['throws'])) {
            $this->assertSame('InvalidArgumentException', $caso['expected']['throws']);
            $this->expectException(InvalidArgumentException::class);
        }

        $this->assertSame($caso['expected']['result'] ?? null, ElevenLabsWebhookSignature::verify($caso['rawBody'], $caso['header'], $caso['secret'], $caso['now']));
    }

    #[DataProvider('envelopeCases')]
    public function test_elevenlabs_envelope(array $caso): void
    {
        $this->assertSame($caso['expected']['type'], ElevenLabsWebhookEnvelope::type($caso['rawBody']));
    }

    // ── V: estado, URL base y verificación de ElevenLabs ────────────────────

    #[DataProvider('voiceStatusCases')]
    public function test_voice_doctor_status(array $caso): void
    {
        self::voiceConfig($caso['config']);

        $this->assertSame($caso['expected'], VoiceProviders::status()->toArray());
        // VZ-1: `provider` es el id de lo que devuelve la fábrica.
        $this->assertSame($caso['expected']['provider'], VoiceProviders::current()->id() === 'elevenlabs' ? 'elevenlabs' : 'none');
    }

    #[DataProvider('voiceBaseUrlCases')]
    public function test_voice_doctor_base_url(array $caso): void
    {
        $this->app['env'] = $caso['env'];
        self::voiceConfig($caso['config']);

        $this->assertSame($caso['env'] === 'production', app()->isProduction());
        $this->assertSame($caso['expected']['baseUrl'], ElevenLabsConfig::fromConfig()->baseUrl());
    }

    #[DataProvider('voiceVerifyCases')]
    public function test_voice_verify(array $caso): void
    {
        self::voiceConfig([
            'voice.elevenlabs.enabled' => true,
            'voice.elevenlabs.api_key' => 'sk_h6fixture_elevenlabs_000000000000000001',
            'voice.elevenlabs.agent_id' => 'agent_h6fixture_0001',
            'voice.elevenlabs.webhook_secret' => 'wsec_h6fixture_webhook_000000000000000001',
            'voice.elevenlabs.region' => 'default',
        ]);
        $respuesta = $caso['response'];
        Http::preventStrayRequests();
        Http::fake(['*' => match ($respuesta['connectionError'] ?? null) {
            null => Http::response($respuesta['rawBody'], $respuesta['status']),
            'unreachable' => Http::failedConnection(),
            'timeout' => static fn (HttpRequest $r) => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out', $r->toPsrRequest(), null, ['errno' => 28])),
        }]);

        $proveedor = VoiceProviders::current();
        $this->assertInstanceOf(ElevenLabsVoiceProvider::class, $proveedor, 'con la bandera y todas las piezas, la fábrica da el adaptador');
        $r = $proveedor->verifyCredentials();

        $e = $caso['expected'];
        $this->assertSame([$e['outcome'], $e['httpStatus'], $e['agentAudioUlaw8000']], [$r->outcome, $r->httpStatus, $r->agentAudioUlaw8000]);
        $this->assertSame($e['snapshotOutcome'], StudioIntegrationConfig::VOICE_OUTCOMES[$r->outcome], 'la correspondencia que guarda el Studio (§I5.3)');
        Http::assertSentCount(1);
        Http::assertSent(static fn (HttpRequest $q): bool => $q->method() === 'GET' && $q->url() === 'https://api.elevenlabs.io/v1/convai/agents/agent_h6fixture_0001');
    }

    // ── TW: el saneador de la sonda ─────────────────────────────────────────

    #[DataProvider('sanitizeCases')]
    public function test_twilio_probe_sanitize(array $caso): void
    {
        $r = TwilioProbeSanitizer::sanitize($caso['responses'], $caso['credential']);

        $this->assertSame($caso['expected']['outcome'], $r['outcome']);
        $this->assertSame($caso['expected']['facts'], $r['facts']);
        $plano = (string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($caso['expected']['mustNotContain'] as $centinela) {
            $this->assertStringNotContainsString($centinela, $plano);
        }
    }

    // ── Twilio: el vector oficial de X-Twilio-Signature ─────────────────────

    #[DataProvider('twilioSignatureCases')]
    public function test_twilio_signature_official(array $caso): void
    {
        $e = $caso['expected'];
        if (isset($e['bodySHA256'])) {
            $this->assertSame($e['bodySHA256'], hash('sha256', $caso['body']));
        }

        $this->assertSame($e['valid'], self::twilioSignatureValid($caso['authToken'], $caso['url'], $caso['params'], $e['signature'], $caso['body'] ?? null));
        if ($caso['official']) {
            $this->assertSame($e['signature'], self::twilioSignature($caso['authToken'], $caso['url'], isset($caso['body']) ? [] : $caso['params']), 'la firma publicada sale de la URL tal cual');
        }
    }

    public function test_un_canal_desconocido_es_un_error_de_quien_llama(): void
    {
        $this->expectException(Throwable::class);
        StudioCanaryPhone::parse('+573000000001', 'sms', ['57']);
    }

    /**
     * La configuración de voz de un caso, sobre una base sin nada configurado
     * (así una variable de la máquina no se cuela en el caso).
     *
     * @param  array<string, mixed>  $config
     */
    private static function voiceConfig(array $config): void
    {
        config(['voice.elevenlabs' => [
            'enabled' => false, 'api_key' => null, 'agent_id' => null, 'webhook_secret' => null, 'region' => 'default',
            'agent_phone_number_id' => null, 'tool_secret' => null, 'base_url' => null, 'timeout_seconds' => 10,
        ]]);
        config($config);
    }

    /**
     * `X-Twilio-Signature` según el algoritmo publicado (RequestValidator de los
     * SDK oficiales): la URL y, por clave en orden de bytes, la clave con cada
     * uno de sus valores distintos en orden; HMAC-SHA1 con el Auth Token, en base64.
     *
     * @param  list<array{0:string,1:string}>  $params
     */
    private static function twilioSignature(string $token, string $url, array $params): string
    {
        $porClave = [];
        foreach ($params as [$clave, $valor]) {
            $porClave[$clave][] = $valor;
        }
        ksort($porClave, SORT_STRING);
        $datos = $url;
        foreach ($porClave as $clave => $valores) {
            $valores = array_values(array_unique($valores));
            sort($valores, SORT_STRING);
            foreach ($valores as $valor) {
                $datos .= $clave.$valor;
            }
        }

        return base64_encode(hash_hmac('sha1', $datos, $token, true));
    }

    /**
     * La validación de referencia: con cuerpo JSON, `bodySHA256` de la URL tiene
     * que ser el SHA-256 del cuerpo y no hay parámetros; la firma vale contra la
     * URL sin puerto o con el puerto por defecto (los SDK prueban las dos).
     *
     * @param  list<array{0:string,1:string}>  $params
     */
    private static function twilioSignatureValid(string $token, string $url, array $params, string $signature, ?string $body): bool
    {
        $p = parse_url($url);
        if ($body !== null) {
            parse_str($p['query'] ?? '', $consulta);
            if (! hash_equals(hash('sha256', $body), (string) ($consulta['bodySHA256'] ?? ''))) {
                return false;
            }
            $params = [];
        }
        $base = $p['scheme'].'://'.$p['host'];
        $resto = ($p['path'] ?? '').(isset($p['query']) ? '?'.$p['query'] : '');
        $puerto = $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80);

        foreach ([$base.$resto, $base.':'.$puerto.$resto] as $variante) {
            if (hash_equals(self::twilioSignature($token, $variante, $params), $signature)) {
                return true;
            }
        }

        return false;
    }
}
