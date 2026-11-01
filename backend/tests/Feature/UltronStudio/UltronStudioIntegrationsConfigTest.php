<?php

namespace Tests\Feature\UltronStudio;

use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioIntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LA CONFIGURACIÓN AUTORIZADA (contrato H6 §I5.3, rutas 46 y 55) y los dos
 * comandos del hito: `ultron-studio:integrations-snapshot` (el registro de
 * cambios de cada despliegue) y `ultron-studio:integrations-doctor` (lo que
 * afirma el Studio, para el smoke). Un GET nunca escribe; reconocer no depende
 * de la bandera de escrituras; ni los `facts` ni el libro llevan secretos.
 */
class UltronStudioIntegrationsConfigTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const TOKEN = 'h6pruebaTWILIOTOKENcentinela00001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->integrationsFlags(writes: false);
        config([
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'AC'.str_repeat('0', 28).'1234',
            'otp.twilio.token' => self::TOKEN,
            'otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e',
            'ultron_studio.integrations.twilio_aliases' => ['sid_source' => 'TWILIO_SID', 'token_source' => 'TWILIO_TOKEN', 'sid_consistent' => true, 'token_consistent' => true],
        ]);
    }

    public function test_get_config_dice_never_authorized_con_lo_efectivo_y_no_escribe_nada(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->as($ana);

        DB::enableQueryLog();
        $data = $this->integrations('GET', 'config', $ana)->assertOk()->json('data');
        $escrituras = array_filter(DB::getQueryLog(), static fn (array $q): bool => preg_match('/^\s*(insert|update|delete)/i', $q['query']) === 1 && ! str_contains($q['query'], 'admin_sessions'));
        DB::disableQueryLog();
        $this->assertSame([], array_values($escrituras), 'un GET nunca escribe');

        $this->assertSame(['meta_ads', 'twilio', 'voice'], array_column($data['providers'], 'provider'));
        $twilio = $data['providers'][1];
        $this->assertSame('never_authorized', $twilio['state']);
        $this->assertNull($twilio['authorized']);
        $this->assertNull($twilio['observed']);
        $this->assertSame([], $twilio['drift']);
        $this->assertSame(StudioIntegrationConfig::FACT_KEYS['twilio'], array_keys($twilio['effective']));
        $this->assertSame(['present' => true, 'source' => 'TWILIO_TOKEN', 'fp' => StudioIntegrationConfig::valueFingerprint(self::TOKEN)], $twilio['effective']['authToken']);
        $this->assertSame(['present' => true, 'source' => 'TWILIO_SID', 'ref' => 'AC…1234'], $twilio['effective']['accountSid']);
        $this->assertSame('12.00', $twilio['effective']['costHardUsd'], 'los importes como texto con dos decimales (Enmienda 1)');
        $this->assertSame(hash('sha256', StudioContentHasher::canonicalJson($twilio['effective'])), $twilio['effectiveFingerprint']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($data));
    }

    public function test_reconocer_sin_la_bandera_de_escrituras_y_la_deriva_cuando_cambia(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $antes = $this->integrations('GET', 'config', $root)->json('data.providers.1');

        $r = $this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => $antes['effectiveFingerprint'], 'note' => 'Revisado con el propietario'])
            ->assertCreated();
        $r->assertJsonPath('data.replayed', false)->assertJsonPath('data.snapshot.provider', 'twilio')->assertJsonPath('data.snapshot.fingerprint', $antes['effectiveFingerprint'])
            ->assertJsonPath('data.snapshot.by.name', 'Root')->assertJsonPath('data.snapshot.note', 'Revisado con el propietario');
        $fila = StudioIntegrationSnapshot::query()->sole();
        $this->assertSame(['config', 'admin', $root->id, null], [$fila->kind, $fila->actor_type, $fila->actor_admin_id, $fila->outcome]);
        $evento = StudioAuditEvent::query()->where('event', 'integration.config_acknowledged')->sole();
        $this->assertSame(['integration', $fila->uuid], [$evento->subject_type, $evento->subject_uuid]);
        $this->assertSame(['provider' => 'twilio', 'fingerprint' => $antes['effectiveFingerprint'], 'driftKeys' => []], $evento->diff);
        $this->assertSame('Configuración de Twilio reconocida', $evento->summary);

        $despues = $this->integrations('GET', 'config', $root)->json('data.providers.1');
        $this->assertSame('authorized', $despues['state']);
        $this->assertSame($fila->uuid, $despues['authorized']['uuid']);

        // Cambia el servicio de Verify: deriva en esa clave, y reconocer la huella vieja es 409.
        config(['otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'1a2b']);
        $deriva = $this->integrations('GET', 'config', $root)->json('data.providers.1');
        $this->assertSame('drift', $deriva['state']);
        $this->assertSame([['key' => 'verifyServiceSid', 'authorized' => ['present' => true, 'ref' => 'VA…9f0e'], 'effective' => ['present' => true, 'ref' => 'VA…1a2b']]], $deriva['drift']);
        $this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => $antes['effectiveFingerprint']])
            ->assertStatus(409)->assertJsonPath('code', 'config_changed')->assertJsonPath('provider', 'twilio')
            ->assertJsonPath('effectiveFingerprint', $deriva['effectiveFingerprint'])->assertJsonPath('drift.0.key', 'verifyServiceSid');
        $this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => $deriva['effectiveFingerprint']])->assertCreated();
        $this->assertSame(['verifyServiceSid'], StudioAuditEvent::query()->where('event', 'integration.config_acknowledged')->orderByDesc('id')->value('diff')['driftKeys']);
        $this->assertSame('authorized', $this->integrations('GET', 'config', $root)->json('data.providers.1.state'));
    }

    public function test_el_reintento_va_antes_que_la_huella_y_otra_persona_o_cuerpo_es_409(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $otro = $this->personWith(self::SUPER_KEYS, 'Otro');
        $huella = $this->integrations('GET', 'config', $root)->json('data.providers.0.effectiveFingerprint');
        $cuerpo = ['clientRequestId' => (string) Str::uuid(), 'provider' => 'meta_ads', 'fingerprint' => $huella];

        $uuid = $this->integrations('POST', 'config/acknowledge', $root, $cuerpo)->assertCreated()->json('data.snapshot.uuid');
        config(['meta.ads.graph_version' => 'v27.0']);
        $this->integrations('POST', 'config/acknowledge', $root, $cuerpo)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.snapshot.uuid', $uuid);
        $this->integrations('POST', 'config/acknowledge', $root, ['note' => 'Otra nota'] + $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->integrations('POST', 'config/acknowledge', $otro, $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertSame(1, StudioIntegrationSnapshot::query()->count());
    }

    public function test_la_forma_y_el_permiso(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $bueno = ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => str_repeat('a', 64)];

        $this->integrations('POST', 'config/acknowledge', $root, ['provider' => 'otro'] + $bueno)->assertStatus(422);
        $this->integrations('POST', 'config/acknowledge', $root, ['fingerprint' => str_repeat('A', 64)] + $bueno)->assertStatus(422);
        $this->integrations('POST', 'config/acknowledge', $root, ['note' => str_repeat('x', 501)] + $bueno)->assertStatus(422);
        $this->integrations('POST', 'config/acknowledge', $ana, $bueno)->assertStatus(403)->assertJsonPath('required_permission', 'ultron.integrations_manage');
        $this->integrations('POST', 'config/acknowledge', $root, $bueno)->assertStatus(409)->assertJsonPath('code', 'config_changed');
    }

    public function test_el_comando_de_instantaneas_anota_solo_lo_que_cambio_y_no_sale_a_la_red(): void
    {
        Http::preventStrayRequests();

        $this->assertSame(0, Artisan::call('ultron-studio:integrations-snapshot', ['--json' => true]));
        $primera = json_decode(Artisan::output(), true);
        $this->assertSame(['meta_ads', 'twilio', 'voice'], array_column($primera['snapshots'], 'provider'));
        $this->assertSame([true, true, true], array_column($primera['snapshots'], 'recorded'));
        $this->assertNull($primera['voice']);
        $this->assertSame(3, StudioIntegrationSnapshot::query()->where('actor_type', 'system')->where('actor_name', 'ultron-studio:integrations-snapshot')->count());

        $this->assertSame(0, Artisan::call('ultron-studio:integrations-snapshot', ['--json' => true]));
        $this->assertSame([false, false, false], array_column(json_decode(Artisan::output(), true)['snapshots'], 'recorded'));

        config(['otp.cost.daily_soft' => 7.5]);
        $this->assertSame(0, Artisan::call('ultron-studio:integrations-snapshot'));
        $this->assertStringContainsString('twilio: anotada', Artisan::output());
        $this->assertSame(4, StudioIntegrationSnapshot::query()->count());
        $this->assertSame('7.50', StudioIntegrationSnapshot::query()->orderByDesc('id')->first()->facts['costSoftUsd']);

        $observada = collect($this->integrations('GET', 'config', $this->personWith(self::ADMIN_KEYS))->json('data.providers'))->firstWhere('provider', 'twilio');
        $this->assertSame('never_authorized', $observada['state'], 'lo del sistema es observado, no autorizado');
        $this->assertNotNull($observada['observed']);
        $this->assertSame(0, StudioAuditEvent::query()->count(), 'el registro de cambios no escribe en el libro');
        $this->assertStringNotContainsString(self::TOKEN, json_encode(StudioIntegrationSnapshot::query()->pluck('facts')));
    }

    public function test_verify_voice_solo_llama_con_la_voz_sin_verificar_y_deja_la_evidencia(): void
    {
        Http::preventStrayRequests();
        $this->assertSame(1, Artisan::call('ultron-studio:integrations-snapshot', ['--verify-voice' => true]));
        $this->assertStringContainsString('ELEVENLABS_NOT_CONFIGURED', Artisan::output());
        $this->assertSame(0, StudioIntegrationSnapshot::query()->where('kind', 'probe')->count());

        config([
            'voice.elevenlabs.enabled' => true,
            'voice.elevenlabs.api_key' => 'sk_h6pruebaELEVENLABScentinela01',
            'voice.elevenlabs.agent_id' => 'agent_h6_0001',
            'voice.elevenlabs.webhook_secret' => 'wsec_h6pruebaWEBHOOKcentinela01',
            'voice.elevenlabs.region' => 'default',
        ]);
        Http::fake(['https://api.elevenlabs.io/v1/convai/agents/agent_h6_0001' => Http::response(['conversation_config' => [
            'tts' => ['agent_output_audio_format' => 'ulaw_8000'], 'asr' => ['user_input_audio_format' => 'ulaw_8000'],
        ]], 200)]);

        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $r = $this->integrations('GET', 'voice', $ana);
        $this->assertSame('ELEVENLABS_UNVERIFIED', $r->json('data.elevenlabs.state'), $r->getContent());
        $this->assertSame(0, Artisan::call('ultron-studio:integrations-snapshot', ['--verify-voice' => true, '--json' => true]));
        $salida = json_decode(Artisan::output(), true);
        $this->assertSame(['verified' => true, 'outcome' => 'credentials_ok', 'snapshotOutcome' => 'ok'], array_intersect_key($salida['voice'], ['verified' => 1, 'outcome' => 1, 'snapshotOutcome' => 1]));
        $sonda = StudioIntegrationSnapshot::query()->where('kind', 'probe')->sole();
        $this->assertSame(['voice', 'ok', 'system'], [$sonda->provider, $sonda->outcome, $sonda->actor_type]);
        $this->assertSame(['outcome', 'httpStatus', 'agentAudioUlaw8000', 'latencyMs'], array_keys($sonda->facts));
        Http::assertSentCount(1);

        $voz = $this->integrations('GET', 'voice', $ana)->json('data.elevenlabs');
        $this->assertSame('ELEVENLABS_VERIFIED', $voz['state'], 'el Studio afirma VERIFIED con la evidencia de menos de 30 días');
        $this->assertSame(['at' => $voz['lastVerification']['at'], 'outcome' => 'credentials_ok', 'agentAudioUlaw8000' => true], $voz['lastVerification']);
        $this->travel(30)->days();
        // La sesión del personal también caduca con el salto: otra nueva.
        $this->sesiones = [];
        $r = $this->integrations('GET', 'voice', $ana);
        $this->assertSame('ELEVENLABS_UNVERIFIED', $r->json('data.elevenlabs.state'), $r->getContent());
        foreach (['sk_h6pruebaELEVENLABScentinela01', 'wsec_h6pruebaWEBHOOKcentinela01'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $salida === null ? '' : json_encode($salida));
            $this->assertStringNotContainsString($secreto, json_encode(StudioIntegrationSnapshot::query()->pluck('facts')));
        }
    }

    public function test_env_example_documenta_las_variables_y_la_configuracion_nace_cerrada(): void
    {
        $ejemplo = (string) file_get_contents(base_path('.env.example'));
        foreach ([
            'ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false', 'ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false', 'ULTRON_STUDIO_TWILIO_PROBE_KEY_SID=',
            'ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET=', 'ULTRON_STUDIO_TWILIO_PROBE_BASE=', 'ULTRON_STUDIO_CANARY_MAX_ACTIVE=3',
            'ULTRON_STUDIO_CANARY_COUNTRY_CODES=57', 'ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE=50',
            'ELEVENLABS_ENABLED=false', 'ELEVENLABS_API_KEY=', 'ELEVENLABS_AGENT_ID=', 'ELEVENLABS_WEBHOOK_SECRET=', 'ELEVENLABS_REGION=default',
            'ELEVENLABS_AGENT_PHONE_NUMBER_ID=', 'ELEVENLABS_TOOL_SECRET=', 'ELEVENLABS_BASE_URL=', 'ELEVENLABS_TIMEOUT_SECONDS=10',
            'TWILIO_VOICE_ENABLED=false', 'TWILIO_VOICE_FROM=', 'TWILIO_VOICE_DAILY_CALL_CAP=0',
            '# META_ADS_ACCESS_TOKEN=', '# META_ADS_GRAPH_VERSION=', '# META_ADS_SYNC_ENABLED=', '# META_AD_ACCOUNT_IDS=',
            '# TWILIO_SID=', '# TWILIO_TOKEN=', '# TWILIO_SEND_ENABLED=', '# TWILIO_VERIFY_RATE_LIMIT_NAME=',
            'FACTUS_CONSUMER_FINAL_NAME=Consumidor final',
        ] as $linea) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($linea, '/').'$/m', $ejemplo, $linea);
        }

        // Evaluada SIN las variables en el entorno: escrituras y sonda cerradas, topes del contrato.
        $nombres = ['ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED', 'ULTRON_STUDIO_TWILIO_PROBE_ENABLED', 'ULTRON_STUDIO_CANARY_MAX_ACTIVE', 'ULTRON_STUDIO_CANARY_COUNTRY_CODES', 'ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE'];
        $config = $this->configWith(array_fill_keys($nombres, null));
        $this->assertFalse($config['integrations']['writes_enabled']);
        $this->assertFalse($config['integrations']['twilio_probe']['enabled']);
        $this->assertSame(300, $config['integrations']['twilio_probe']['cooldown_seconds']);
        $this->assertSame([3, 10, ['57'], 50], [$config['integrations']['canary']['max_active'], $config['integrations']['canary']['max_live'],
            $config['integrations']['canary']['country_codes'], $config['integrations']['campaign_links']['max_active']]);

        // Recortes y valores mal escritos.
        $c = $this->configWith(['ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED' => 'off', 'ULTRON_STUDIO_TWILIO_PROBE_ENABLED' => 'sí', 'ULTRON_STUDIO_CANARY_MAX_ACTIVE' => '99',
            'ULTRON_STUDIO_CANARY_COUNTRY_CODES' => ' 1, 057,abc, 57 ,1', 'ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE' => '0']);
        $this->assertFalse($c['integrations']['writes_enabled']);
        $this->assertFalse($c['integrations']['twilio_probe']['enabled']);
        $this->assertSame(10, $c['integrations']['canary']['max_active']);
        $this->assertSame(['1', '57'], $c['integrations']['canary']['country_codes']);
        $this->assertSame(1, $c['integrations']['campaign_links']['max_active']);
        $this->assertSame(['57'], $this->configWith(['ULTRON_STUDIO_CANARY_COUNTRY_CODES' => ', ,'])['integrations']['canary']['country_codes']);
        $this->assertTrue($this->configWith(['ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED' => 'true'])['integrations']['writes_enabled']);
    }

    /**
     * `config/ultron_studio.php` evaluado con unas variables (null = sin ella).
     *
     * @param  array<string, ?string>  $variables
     * @return array<string, mixed>
     */
    private function configWith(array $variables): array
    {
        $antes = [];
        foreach ($variables as $nombre => $valor) {
            $antes[$nombre] = [$_SERVER[$nombre] ?? null, $_ENV[$nombre] ?? null, getenv($nombre)];
            unset($_SERVER[$nombre], $_ENV[$nombre]);
            putenv($nombre);
            if ($valor !== null) {
                $_SERVER[$nombre] = $_ENV[$nombre] = $valor;
                putenv("{$nombre}={$valor}");
            }
        }
        try {
            return require base_path('config/ultron_studio.php');
        } finally {
            foreach ($antes as $nombre => [$servidor, $entorno, $proceso]) {
                unset($_SERVER[$nombre], $_ENV[$nombre]);
                putenv($nombre);
                if ($servidor !== null) {
                    $_SERVER[$nombre] = $servidor;
                }
                if ($entorno !== null) {
                    $_ENV[$nombre] = $entorno;
                }
                if ($proceso !== false) {
                    putenv("{$nombre}={$proceso}");
                }
            }
        }
    }

    public function test_el_doctor_imprime_las_clasificaciones_y_falla_cuando_no_se_cumple_lo_esperado(): void
    {
        $this->assertSame(0, Artisan::call('ultron-studio:integrations-doctor'));
        $salida = Artisan::output();
        $this->assertStringContainsString('meta_ads: not_configured', $salida);
        $this->assertStringContainsString('elevenlabs: not_configured', $salida);

        config(['otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e']);
        $this->assertSame(0, Artisan::call('ultron-studio:integrations-doctor', ['--expect' => 'twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED,canaries:registry']));
        $this->assertSame(1, Artisan::call('ultron-studio:integrations-doctor', ['--expect' => 'meta_ads:connected_read']));
        $this->assertStringContainsString('meta_ads: se esperaba connected_read y es not_configured', Artisan::output());
        $this->assertSame(1, Artisan::call('ultron-studio:integrations-doctor', ['--expect' => 'meta:connected_read']));
        $this->assertSame(1, Artisan::call('ultron-studio:integrations-doctor', ['--expect' => 'twilio']));

        $this->assertSame(1, Artisan::call('ultron-studio:integrations-doctor', ['--json' => true, '--expect' => 'meta_ads:connected_read']));
        $json = json_decode(Artisan::output(), true);
        $this->assertFalse($json['ok']);
        $this->assertSame(['id' => 'meta_ads', 'expected' => 'connected_read', 'actual' => 'not_configured'], $json['mismatches'][0]);
        $this->assertSame(['meta_ads', 'twilio', 'twilio_voice', 'canaries', 'elevenlabs'], array_column($json['providers'], 'id'));
        $this->assertStringNotContainsString(self::TOKEN, json_encode($json));
    }

    /** AS-1 (Enmienda 3): la nota de reconocer no admite un teléfono ni un correo; una fecha, sí. */
    public function test_la_nota_de_reconocer_rechaza_telefonos_y_correos(): void
    {
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $huella = StudioIntegrationConfig::fingerprintOf(StudioIntegrationConfig::facts('twilio'));

        foreach (['+57 300 000 0280', '300-000-0280', '3000000280', 'juan@correo.co'] as $dato) {
            $r = $this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => $huella, 'note' => "Revisado con {$dato}"])
                ->assertStatus(422);
            $this->assertSame([StudioCanaryPhone::CONTACT_DATA_MESSAGE], $r->json('errors.note'), $dato);
            $this->assertStringNotContainsString($dato, $r->getContent());
        }
        $this->assertSame(0, StudioIntegrationSnapshot::query()->count());
        $this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => $huella, 'note' => 'Revisado el 2026-11-01'])
            ->assertCreated();
    }

    /**
     * N-3 (Enmienda 3): «base oficial» solo con `https`, el host oficial exacto
     * (sin distinguir mayúsculas, como el DNS), sin otro puerto que el 443 y sin
     * credenciales en la URL; de una que no lo es, solo el host.
     */
    public function test_la_base_oficial_exige_https_el_host_exacto_el_puerto_443_y_sin_credenciales(): void
    {
        $casos = [
            'https://api.twilio.com' => ['official' => true],
            'https://API.Twilio.com/' => ['official' => true],
            'https://api.twilio.com:443' => ['official' => true],
            'http://api.twilio.com' => ['official' => false, 'host' => 'api.twilio.com'],
            'https://api.twilio.com:8443' => ['official' => false, 'host' => 'api.twilio.com'],
            'https://usuario:clavesecreta@api.twilio.com' => ['official' => false, 'host' => 'api.twilio.com'],
            'https://usuario@api.twilio.com' => ['official' => false, 'host' => 'api.twilio.com'],
            'https://api.twilio.com.evil.test' => ['official' => false, 'host' => 'api.twilio.com.evil.test'],
            'http://127.0.0.1:8090' => ['official' => false, 'host' => '127.0.0.1'],
        ];
        foreach ($casos as $base => $esperado) {
            config(['otp.twilio.base' => $base, 'otp.twilio.verify_base' => str_ireplace('api.twilio.com', 'verify.twilio.com', $base)]);
            $hechos = StudioIntegrationConfig::facts('twilio');
            $this->assertSame($esperado, $hechos['apiBase'], $base);
            $this->assertSame(isset($esperado['host']) ? ['official' => false, 'host' => str_replace('api.twilio.com', 'verify.twilio.com', $esperado['host'])] : $esperado, $hechos['verifyBase'], $base);
            $this->assertStringNotContainsString('clavesecreta', json_encode($hechos));
            $this->assertStringNotContainsString('usuario', json_encode($hechos));
        }
    }
}
