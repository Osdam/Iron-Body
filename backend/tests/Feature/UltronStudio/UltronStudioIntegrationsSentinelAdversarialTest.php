<?php

namespace Tests\Feature\UltronStudio;

use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioIntegrationsExport;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LA CENTINELA, AMPLIADA (contrato H6 §I1 P5, §I9.6 e I13). Lo que la de S
 * ({@see UltronStudioIntegrationsSentinelTest}) no recorre:
 *  - el EXPORTADOR de fixtures (`ultron-studio:export-fixtures`): su fichero
 *    acaba en el repositorio del CRM, así que ni un secreto de la máquina, ni
 *    su huella (con la `APP_KEY` real o con la sintética), ni un teléfono, ni
 *    los ids de Meta configurados pueden entrar en él (D-6 de S); y la
 *    configuración de la máquina queda como estaba;
 *  - las FILAS del registro de canarios: ninguna columna guarda el teléfono en
 *    claro; la huella es la HMAC con la subclave (no un sha256 sin clave, que
 *    se invierte recorriendo los ~10^10 móviles); el cifrado de voz es del
 *    número y desaparece al revocar;
 *  - los secretos que la de S no siembra: el de las herramientas de
 *    ElevenLabs, el id de su número, el número de voz del gimnasio y el SID de
 *    Verify, en respuestas, libro, `facts`, consola y log.
 */
class UltronStudioIntegrationsSentinelAdversarialTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const TWILIO_SID = 'AC5e17e1a55e17e1a55e17e1a55e17e1a5';

    private const TWILIO_TOKEN = 'CENTINELA2twilioTOKENad41';

    private const VERIFY_SID = 'VA5e17e1a55e17e1a55e17e1a55e177a31';

    private const SENTINEL_META_TOKEN = 'CENTINELA2metaTOKEN7c55';

    /** Cuentas de Meta de la máquina (no las de la exportación). */
    private const SENTINEL_ACCOUNTS = ['5550001110007311', '5550002220008422'];

    private const EL_API_KEY = 'CENTINELA2elevenAPIKEYb901';

    private const EL_WEBHOOK = 'CENTINELA2elevenWEBHOOK0e3f';

    private const EL_TOOL = 'CENTINELA2elevenTOOLSECRET4d2a';

    private const EL_AGENT = 'agent_centinela2xq81';

    private const EL_PHONE_NUMBER_ID = 'phnum_centinela2zz93';

    private const PROBE_KEY_SID = 'SK5e17e1a55e17e1a55e17e1a55e17e1a5';

    private const PROBE_SECRET = 'CENTINELA2probeSECRET6b17';

    /** El número de voz del gimnasio, el del lead canario y los que se registran. */
    private const VOICE_FROM = '+576017778866';

    private const LEAD_PHONE = '3004445566';

    private const WA_PHONE = '+573008889911';

    private const VOICE_PHONE = '+576012223344';

    /** @var list<string> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        Http::fake(static fn () => Http::response([
            'sid' => self::TWILIO_SID, 'status' => 'active', 'type' => 'Full', 'auth_token' => self::TWILIO_TOKEN,
            'incoming_phone_numbers' => [['phone_number' => self::VOICE_FROM, 'friendly_name' => 'Iron '.self::WA_PHONE, 'capabilities' => ['voice' => true, 'sms' => true]]],
            'alerts' => [['error_code' => '60200', 'log_level' => 'warning', 'alert_text' => 'Key '.self::PROBE_SECRET.' To '.self::WA_PHONE]],
        ], 200));
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->seen[] = 'log:'.$m->level.' '.$m->message.' '.json_encode($m->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
        $this->integrationsFlags(writes: true, probe: true);
        $this->metaConfigured();
        config([
            'meta.ads.access_token' => self::SENTINEL_META_TOKEN,
            'meta.ads.ad_account_ids' => self::SENTINEL_ACCOUNTS,
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => self::TWILIO_SID,
            'otp.twilio.token' => self::TWILIO_TOKEN,
            'otp.twilio.verify_service_sid' => self::VERIFY_SID,
            'ultron_studio.integrations.twilio_aliases' => ['sid_source' => 'TWILIO_SID', 'token_source' => 'TWILIO_TOKEN', 'sid_consistent' => true, 'token_consistent' => true],
            'ultron_studio.integrations.twilio_probe.key_sid' => self::PROBE_KEY_SID,
            'ultron_studio.integrations.twilio_probe.key_secret' => self::PROBE_SECRET,
            'voice.elevenlabs.enabled' => true,
            'voice.elevenlabs.api_key' => self::EL_API_KEY,
            'voice.elevenlabs.webhook_secret' => self::EL_WEBHOOK,
            'voice.elevenlabs.tool_secret' => self::EL_TOOL,
            'voice.elevenlabs.agent_id' => self::EL_AGENT,
            'voice.elevenlabs.agent_phone_number_id' => self::EL_PHONE_NUMBER_ID,
            'voice.twilio.enabled' => true,
            'voice.twilio.from' => self::VOICE_FROM,
        ]);
    }

    public function test_el_exportador_de_fixtures_no_lleva_secretos_huellas_telefonos_ni_ids_de_la_maquina(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $this->engineCanary(self::LEAD_PHONE);
        $wa = (string) $this->registerCanary($ana, self::WA_PHONE)->assertCreated()->json('data.number.uuid');
        $this->integrations('POST', "canaries/{$wa}/authorize", $root)->assertOk();
        $this->registerCanary($ana, self::VOICE_PHONE, 'voice', 'Fijo de recepción')->assertCreated();
        foreach (['meta_ads', 'twilio', 'voice'] as $i => $proveedor) {
            $huella = $this->integrations('GET', 'config', $root)->assertOk()->json("data.providers.{$i}.effectiveFingerprint");
            $this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => $proveedor, 'fingerprint' => $huella])->assertCreated();
        }
        $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        $claveAntes = config('app.key');

        $dir = sys_get_temp_dir().'/ultron-studio-h6-centinela-'.bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            $this->assertSame(0, Artisan::call('ultron-studio:export-fixtures', ['--path' => $dir]), Artisan::output());
            $this->seen[] = 'consola:'.Artisan::output();
            $ficheros = array_merge(glob($dir.'/*.ts') ?: [], glob($dir.'/testing/*.ts') ?: []);
            $this->assertCount(6, $ficheros, 'los seis ficheros del exportador');
            $exportado = implode("\n", array_map(static fn (string $f): string => basename($f)."\n".file_get_contents($f), $ficheros));
            $this->assertStringContainsString('backend-integrations.testing.ts', $exportado);
        } finally {
            foreach ([$dir.'/testing', $dir] as $d) {
                foreach (glob($d.'/*') ?: [] as $f) {
                    unlink($f);
                }
                if (is_dir($d)) {
                    rmdir($d);
                }
            }
        }

        // La máquina queda como estaba.
        $this->assertSame($claveAntes, config('app.key'));
        $this->assertSame(self::TWILIO_TOKEN, config('otp.twilio.token'));

        $this->assertNoSentinelIn($exportado.implode("\n", $this->seen), withMachineFingerprints: true, withSyntheticFingerprints: true);
    }

    public function test_ninguna_columna_del_registro_guarda_el_telefono_en_claro_y_la_huella_es_hmac_con_la_subclave(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $wa = (string) $this->registerCanary($ana, '+57 300 888 9911')->assertCreated()->json('data.number.uuid');
        $voz = (string) $this->registerCanary($ana, self::VOICE_PHONE, 'voice', 'Fijo de recepción')->assertCreated()->json('data.number.uuid');

        $filas = DB::table('ultron_studio_canary_numbers')->orderBy('id')->get();
        $this->assertCount(2, $filas);
        foreach ($filas as $fila) {
            $e164 = $fila->uuid === $wa ? self::WA_PHONE : self::VOICE_PHONE;
            $nacional = substr($e164, 3);
            foreach ((array) $fila as $columna => $valor) {
                if (! is_string($valor) || $columna === 'phone_encrypted') {
                    continue;
                }
                $this->assertStringNotContainsString($nacional, $valor, "la columna {$columna} guarda el teléfono en claro");
                $this->assertStringNotContainsString(substr($nacional, 0, 6), $valor, "la columna {$columna} guarda parte del teléfono");
            }
            $this->assertSame(substr($e164, -4), $fila->phone_last4);
            $this->assertSame(PhoneFingerprint::of($e164), $fila->phone_hash, 'la huella no es la HMAC del contrato (§I4.3)');
            foreach ([hash('sha256', $e164), hash('sha256', $nacional), hash('sha256', '57'.$nacional), hash_hmac('sha256', $e164, (string) config('app.key'))] as $sinSubclave) {
                $this->assertNotSame($sinSubclave, $fila->phone_hash, 'la huella se puede recalcular sin la subclave');
            }
        }
        $filaWa = $filas->firstWhere('uuid', $wa);
        $filaVoz = $filas->firstWhere('uuid', $voz);
        $this->assertNull($filaWa->phone_encrypted, 'WhatsApp no guarda el número, ni cifrado');
        $this->assertStringNotContainsString(substr(self::VOICE_PHONE, 3), (string) $filaVoz->phone_encrypted);
        $this->assertSame(self::VOICE_PHONE, Crypt::decryptString((string) $filaVoz->phone_encrypted), 'el cifrado de voz es el del número (cast encrypted, APP_KEY)');

        $this->integrations('POST', "canaries/{$voz}/revoke", $ana, ['reason' => 'Ya no se usa'])->assertOk();
        $this->assertNull(DB::table('ultron_studio_canary_numbers')->where('uuid', $voz)->value('phone_encrypted'), 'revocar no borró el cifrado');
        $libro = json_encode(DB::table('ultron_studio_audit_events')->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Fijo de recepción', $libro, 'el alias no va al libro (§I8)');
        $this->assertStringNotContainsString(PhoneFingerprint::of(self::VOICE_PHONE), $libro);
        $this->assertStringNotContainsString(substr(self::VOICE_PHONE, -4), $libro, 'ni los 4 dígitos (§I8)');
    }

    public function test_los_secretos_que_la_centinela_de_s_no_siembra_no_salen_por_ninguna_via(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $this->engineCanary(self::LEAD_PHONE);
        $wa = (string) $this->see($this->registerCanary($ana, self::WA_PHONE))->json('data.number.uuid');
        $this->see($this->integrations('POST', "canaries/{$wa}/authorize", $root));
        $this->see($this->integrations('POST', "canaries/{$wa}/activate", $root));
        foreach (['meta_ads', 'twilio', 'voice'] as $i => $proveedor) {
            $huella = $this->see($this->integrations('GET', 'config', $root))->json("data.providers.{$i}.effectiveFingerprint");
            $this->see($this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => $proveedor, 'fingerprint' => $huella, 'note' => 'Revisado']));
        }
        // Un cambio de un secreto: la deriva dice QUÉ clave cambió, nunca su valor ni su huella vieja en claro.
        config(['voice.elevenlabs.tool_secret' => self::EL_TOOL.'-rotado']);
        $this->see($this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'voice', 'fingerprint' => str_repeat('0', 64)]))->assertStatus(409);
        $this->see($this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()]));
        foreach (['status', 'meta/campaigns', 'meta/links', 'canaries?status=all', 'twilio', 'twilio/calls', 'voice', 'config', 'audit?subjectType=integration', 'audit?subjectType=canary_number'] as $lectura) {
            $this->see($lectura === 'audit?subjectType=integration' || $lectura === 'audit?subjectType=canary_number' ? $this->studio('GET', $lectura, $root) : $this->integrations('GET', $lectura, $root));
        }
        foreach ([['ultron-studio:integrations-doctor', ['--json' => true]], ['ultron-studio:integrations-snapshot', ['--json' => true]], ['voice:doctor', []]] as [$comando, $opciones]) {
            Artisan::call($comando, $opciones);
            $this->seen[] = 'consola:'.Artisan::output();
        }
        $this->seen[] = 'libro:'.json_encode(DB::table('ultron_studio_audit_events')->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->seen[] = 'instantaneas:'.json_encode(DB::table('ultron_studio_integration_snapshots')->get(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $todo = implode("\n", $this->seen);
        $this->assertStringContainsString('toolSecret', $todo, 'la clave del secreto de las herramientas sí sale (como huella)');
        $this->assertNoSentinelIn($todo, withMachineFingerprints: false, withSyntheticFingerprints: false);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    private function see(TestResponse $r): TestResponse
    {
        $this->assertLessThan(500, $r->status(), $r->getContent());
        $this->seen[] = 'respuesta:'.$r->getContent();

        return $r;
    }

    /**
     * Ningún secreto, teléfono ni id configurado. Con `$withMachineFingerprints`,
     * tampoco la huella de 8 hex de cada secreto con la `APP_KEY` de la máquina
     * (en el exportador no tiene que haber NADA derivado de un secreto real);
     * con `$withSyntheticFingerprints`, ni con la sintética del exportador.
     */
    private function assertNoSentinelIn(string $todo, bool $withMachineFingerprints, bool $withSyntheticFingerprints): void
    {
        $secretos = [self::TWILIO_TOKEN, self::SENTINEL_META_TOKEN, self::EL_API_KEY, self::EL_WEBHOOK, self::EL_TOOL, self::PROBE_SECRET];
        $centinelas = [
            ...$secretos, self::TWILIO_SID, self::VERIFY_SID, self::PROBE_KEY_SID, self::EL_PHONE_NUMBER_ID,
            ...self::SENTINEL_ACCOUNTS,
            self::LEAD_PHONE, '57'.self::LEAD_PHONE, substr(self::WA_PHONE, 3), substr(self::VOICE_PHONE, 3), substr(self::VOICE_FROM, 3),
            PhoneFingerprint::of('+57'.self::LEAD_PHONE), PhoneFingerprint::of(self::WA_PHONE), PhoneFingerprint::of(self::VOICE_PHONE),
        ];
        if ($withMachineFingerprints || $withSyntheticFingerprints) {
            // El exportador no puede llevar NADA de la máquina: ni el agente, ni las referencias de sus ids.
            $centinelas = [...$centinelas, self::EL_AGENT, 'AC…'.substr(self::TWILIO_SID, -4), 'VA…'.substr(self::VERIFY_SID, -4), '***'.substr(self::SENTINEL_ACCOUNTS[0], -4)];
        }
        foreach ($centinelas as $c) {
            $this->assertStringNotContainsString($c, $todo, "«{$c}» salió por alguna vía.");
        }
        $claves = [];
        if ($withMachineFingerprints) {
            $claves[] = (string) config('app.key');
        }
        if ($withSyntheticFingerprints) {
            $claves[] = StudioIntegrationsExport::SYNTHETIC_APP_KEY;
        }
        foreach ($claves as $clave) {
            $antes = config('app.key');
            config(['app.key' => $clave]);
            try {
                foreach ($secretos as $s) {
                    $fp = StudioIntegrationConfig::valueFingerprint($s);
                    $this->assertStringNotContainsString('"'.$fp.'"', $todo, "la huella de «{$s}» con ".($clave === StudioIntegrationsExport::SYNTHETIC_APP_KEY ? 'la clave sintética' : 'la APP_KEY de la máquina').' salió.');
                }
            } finally {
                config(['app.key' => $antes]);
            }
        }
    }
}
