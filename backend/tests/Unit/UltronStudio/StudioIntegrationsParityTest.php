<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCanaryNumbers;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioIntegrations;
use App\Services\UltronStudio\StudioMetaCampaigns;
use App\Support\Privacy\PhoneFingerprint;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * PARIDAD del NÚCLEO PURO del paquete S con el fixture del contrato
 * (`tests/fixtures/ultron-studio/integrations-cases.json`, contrato H6 §I11),
 * escrito a mano por el tester: si una implementación discrepa, se corrige la
 * implementación, nunca el fixture.
 *
 * Aquí, las secciones que no necesitan la base: `phone`, `alias`,
 * `fingerprint`, `expectation`, `metaClassification` (salvo `possiblyDeleted`,
 * que lee las pasadas de la base), `providerClassification`, `configFacts`,
 * `configDrift`, `voiceDoctor · studioState` y la parte del Studio de
 * `voiceCallPolicy` (los niveles y el contexto). Las de estado (máquina de los
 * canarios, asociaciones, sonda, reconocimiento, coherencia, `possiblyDeleted`
 * y la matriz) van por HTTP en `UltronStudioIntegrationsParityHttpTest`. Las de
 * V y TW, en sus paquetes.
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
        // La decisión es de V; los niveles los arma el Studio con sus motivos.
        $this->assertSame($caso['expected']['levels'], StudioIntegrations::voiceLevels($caso['expected']['reasons']));
    }

    public function test_un_canal_desconocido_es_un_error_de_quien_llama(): void
    {
        $this->expectException(Throwable::class);
        StudioCanaryPhone::parse('+573000000001', 'sms', ['57']);
    }
}
