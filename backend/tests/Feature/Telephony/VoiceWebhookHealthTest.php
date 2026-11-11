<?php

namespace Tests\Feature\Telephony;

use App\Models\Incident;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceWebhookHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;
use Tests\Unit\Voice\Support\FreshHttpFake;

/**
 * LA SALUD DEL WEBHOOK, automatizada (auditoría B-T5; contrato H8, D8-21,
 * decisión (f); riesgo R8-12): `voice:webhook-health`, su lectura en
 * `voice:doctor --telefonia` y en `GET admin/voice/status`, y su incidente de
 * IRON GUARD. Siempre contra `Http::fake` con la red cerrada.
 *
 *  - Con `VOICE_MONITOR_ENABLED` apagada: `disabled`, ninguna petición, nada
 *    guardado ni abierto.
 *  - Encendida: dos GET de solo lectura; coincide → 0 y sin incidente; no
 *    coincide → 1 e incidente `voice_webhook_unhealthy` (alto si algo no
 *    coincide, medio si no se pudo comprobar), uno por clase de avería.
 *  - SC-3 del cierre (R8-12): lo DEFINITIVO echa además el freno de voz
 *    (`webhook_unhealthy`, con su propio incidente `voice_brake`); el detalle,
 *    en VoiceWebhookHealthBrakeTest.
 *  - Nunca sale una URL, un id del proveedor ni un secreto.
 */
class VoiceWebhookHealthTest extends TestCase
{
    use FreshHttpFake;
    use RefreshDatabase;
    use VoiceFixtures;

    private const CLAVE = 'xi-centinela-SALUD-0123456789';

    private const SIM = 'http://127.0.0.1:8096';

    private const BASE = 'https://api.ironbody.test';

    private const WEBHOOK = 'wh_propio_0001';

    private const HERRAMIENTAS = 'sec_herramientas_01';

    private const INICIACION = 'sec_iniciacion_02';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->voiceWorld();
        config([
            'voice.monitor.enabled' => true,
            'voice.webhook_base_url' => self::BASE,
            'voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => self::CLAVE, 'voice.elevenlabs.agent_id' => 'agent_verify_0001',
            'voice.elevenlabs.region' => 'default', 'voice.elevenlabs.base_url' => self::SIM, 'voice.elevenlabs.timeout_seconds' => 7,
            'voice.elevenlabs.post_call_webhook_ref' => self::WEBHOOK, 'voice.elevenlabs.tool_secret_ref' => self::HERRAMIENTAS,
            'voice.elevenlabs.initiation_secret_ref' => self::INICIACION,
        ]);
    }

    /**
     * El proveedor: el webhook propio (con lo que cambie la prueba), uno ajeno y los secretos.
     *
     * @param  array<string, mixed>  $propio
     * @param  list<string>  $secretos
     */
    private function proveedor(array $propio = [], ?array $secretos = null, ?string $cursor = null, int $httpWebhooks = 200, int $httpSecretos = 200): void
    {
        $webhook = $propio + ['name' => 'ironbody-voz-post-llamada', 'webhook_id' => self::WEBHOOK, 'webhook_url' => self::BASE.VoiceWebhookHealth::POST_CALL_PATH,
            'is_disabled' => false, 'is_auto_disabled' => false, 'auth_type' => 'hmac'];
        $lista = array_map(static fn (string $id): array => ['type' => 'stored', 'secret_id' => $id, 'name' => 'ironbody-voz-'.$id],
            $secretos ?? [self::HERRAMIENTAS, self::INICIACION, 'twilio_token_ajeno']);
        self::freshFake([
            self::SIM.'/v1/workspace/webhooks' => Http::response(['webhooks' => [
                ['name' => 'ajeno', 'webhook_id' => 'wh_ajeno_0009', 'webhook_url' => 'https://ajeno.example/x', 'is_disabled' => true, 'is_auto_disabled' => true, 'auth_type' => 'oauth2'],
                $webhook,
            ]], $httpWebhooks),
            self::SIM.'/v1/convai/secrets*' => Http::response(['secrets' => $lista, 'next_cursor' => $cursor], $httpSecretos),
        ]);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} el código, el JSON y la salida entera */
    private function salud(array $opciones = []): array
    {
        $codigo = Artisan::call('voice:webhook-health', $opciones + ['--json' => true]);
        $salida = Artisan::output();

        return [$codigo, json_decode($salida, true, 64, JSON_THROW_ON_ERROR), $salida];
    }

    private function assertSinSecretosNiUrls(string $texto, string $donde): void
    {
        foreach ([self::CLAVE, self::BASE, 'ironbody.test', 'ajeno.example', self::WEBHOOK, self::HERRAMIENTAS, self::INICIACION, 'wh_ajeno_0009',
            'twilio_token_ajeno', self::WEBHOOK_SECRET, self::TOOL_SECRET, self::INITIATION_SECRET] as $s) {
            $this->assertStringNotContainsString($s, $texto, "{$donde}: {$s}");
        }
    }

    public function test_con_el_monitor_de_voz_apagado_no_sale_a_la_red_ni_guarda_ni_abre_nada(): void
    {
        config(['voice.monitor.enabled' => false]);
        self::freshFake();

        [$codigo, $r] = $this->salud();
        $this->assertSame([0, 'disabled', [], null], [$codigo, $r['state'], $r['problems'], $r['checkedAt']]);
        $this->assertSame(0, Artisan::call('voice:webhook-health'));
        $this->assertStringContainsString('Apagada (VOICE_MONITOR_ENABLED): no se ha hecho ninguna petición.', Artisan::output());
        Http::assertNothingSent();
        $this->assertNull(Cache::get(VoiceWebhookHealth::CACHE_KEY));
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_si_coincide_sale_con_0_sin_incidente_y_el_estado_y_el_doctor_lo_ensenan(): void
    {
        $this->proveedor();

        [$codigo, $r, $salida] = $this->salud();
        $this->assertSame([0, 'ok', []], [$codigo, $r['state'], $r['problems']]);
        $this->assertSame(['outcome' => 'observed', 'httpStatus' => 200, 'found' => true, 'urlMatches' => true, 'disabled' => false, 'autoDisabled' => false, 'authType' => 'hmac'], $r['webhook']);
        $this->assertSame(['outcome' => 'observed', 'httpStatus' => 200, 'truncated' => false, 'toolFound' => true, 'initiationFound' => true], $r['secrets']);
        $this->assertNull($r['incident']);
        $this->assertSame(0, Incident::query()->count());
        // Dos GET de solo lectura, con la clave en su cabecera.
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$peticion]) {
            $this->assertSame(['GET', [self::CLAVE]], [$peticion->method(), $peticion->header('xi-api-key')]);
        }
        $this->assertSinSecretosNiUrls($salida, 'la orden');

        $estado = $this->getJson('/api/admin/voice/status', $this->adminWithPermissions(['telephony.view']))->assertOk();
        $this->assertSame(['ok', [], $r['checkedAt']], [$estado->json('webhookHealth.state'), $estado->json('webhookHealth.problems'), $estado->json('webhookHealth.checkedAt')]);
        $this->assertSinSecretosNiUrls((string) $estado->getContent(), 'el estado');

        $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true]));
        $doctor = Artisan::output();
        $this->assertStringContainsString('Webhook    : coincide con Laravel · '.$r['checkedAt'].' · última entrega buena ninguna', $doctor);
        $this->assertSinSecretosNiUrls($doctor, 'el doctor');
        Http::assertSentCount(2, 'el estado y el doctor no salen a la red');
    }

    public function test_cada_desajuste_tiene_su_codigo_y_abre_un_incidente_alto(): void
    {
        $casos = [
            'otra URL' => [['webhook_url' => 'https://otra.example'.VoiceWebhookHealth::POST_CALL_PATH], null, ['webhook_url_mismatch']],
            'desactivado solo tras fallos (R8-12)' => [['is_auto_disabled' => true], null, ['webhook_auto_disabled']],
            'desactivado a mano' => [['is_disabled' => true], null, ['webhook_disabled']],
            'sin HMAC' => [['auth_type' => 'oauth2'], null, ['webhook_auth_not_hmac']],
            'el webhook no está' => [['webhook_id' => 'wh_otro_0003'], null, ['webhook_not_found']],
            'faltan los dos secretos' => [[], ['twilio_token_ajeno'], ['tool_secret_not_found', 'initiation_secret_not_found']],
        ];
        foreach ($casos as $caso => [$propio, $secretos, $problemas]) {
            Incident::query()->delete();
            $this->proveedor($propio, $secretos);

            [$codigo, $r, $salida] = $this->salud();
            $this->assertSame([1, 'unhealthy', $problemas], [$codigo, $r['state'], $r['problems']], $caso);
            $incidente = Incident::query()->where('kind', VoiceWebhookHealth::INCIDENT_KIND)->sole();
            $this->assertSame(['voice', 'voice_webhook_unhealthy', Incident::SEVERITY_HIGH, $r['incident']], [$incidente->source, $incidente->kind, $incidente->severity, $incidente->id], $caso);
            $ordenados = $problemas;
            sort($ordenados);
            $this->assertSame($ordenados, $incidente->evidence['problems'], $caso);
            // SC-3: todos estos son DEFINITIVOS: echan el freno, con su propio aviso (y nada más).
            $this->assertSame(['webhook_unhealthy', $ordenados, true], [$r['brake']['reason'], $r['brake']['problems'], $r['brake']['opened']], $caso);
            $this->assertSame(1, Incident::query()->where('kind', 'voice_brake')->count(), $caso);
            $this->assertSame(2, Incident::query()->count(), $caso);
            $this->assertSinSecretosNiUrls($salida.json_encode([$incidente->title, $incidente->evidence]), $caso);
        }
    }

    public function test_la_misma_averia_es_un_incidente_con_mas_ocurrencias_y_otra_averia_es_otro(): void
    {
        $this->proveedor(['is_auto_disabled' => true]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(1, $this->salud()[0]);
        }
        $incidente = Incident::query()->where('kind', VoiceWebhookHealth::INCIDENT_KIND)->sole();
        $this->assertSame(3, (int) $incidente->occurrences, 'idempotente: la misma huella suma');
        $this->assertSame(1, (int) Incident::query()->where('kind', 'voice_brake')->sum('occurrences'), 'SC-3: el freno se echa una vez mientras siga echado');

        $this->proveedor(['is_auto_disabled' => true, 'webhook_url' => 'https://otra.example/x']);
        $this->salud();
        $this->assertSame(2, Incident::query()->where('kind', 'voice_webhook_unhealthy')->count(), 'otra combinación de problemas, otra huella');
    }

    public function test_si_no_se_puede_comprobar_el_incidente_es_medio(): void
    {
        // El proveedor responde 500 a las dos listas.
        $this->proveedor([], null, null, 500, 500);
        [$codigo, $r] = $this->salud();
        $this->assertSame([1, ['webhooks_unreadable', 'secrets_unreadable']], [$codigo, $r['problems']]);
        $this->assertSame(Incident::SEVERITY_MEDIUM, Incident::query()->sole()->severity);

        // Una lista de secretos con más páginas y la referencia fuera de la primera: no se afirma que falte.
        Incident::query()->delete();
        $this->proveedor([], [self::HERRAMIENTAS], 'cursor_2');
        [, $r] = $this->salud();
        $this->assertSame(['secrets_list_truncated'], $r['problems']);
        $this->assertSame(Incident::SEVERITY_MEDIUM, Incident::query()->sole()->severity);
    }

    public function test_sin_las_piezas_de_elevenlabs_o_sin_las_referencias_lo_dice_y_sin_red(): void
    {
        self::freshFake();
        config(['voice.elevenlabs.enabled' => false, 'voice.webhook_base_url' => '', 'voice.elevenlabs.tool_secret_ref' => null]);

        [$codigo, $r] = $this->salud();
        $this->assertSame([1, ['provider_not_ready', 'base_url_invalid', 'tool_secret_ref_missing']], [$codigo, $r['problems']]);
        Http::assertNothingSent();
        $this->assertSame(Incident::SEVERITY_HIGH, Incident::query()->sole()->severity, 'faltan referencias: no es solo «sin comprobar»');
    }

    public function test_en_seco_lee_y_compara_pero_no_guarda_ni_abre_nada(): void
    {
        $this->proveedor(['is_disabled' => true]);
        [$codigo, $r] = $this->salud(['--dry-run' => true]);
        $this->assertSame([1, ['webhook_disabled'], true, null], [$codigo, $r['problems'], $r['dryRun'], $r['incident']]);
        Http::assertSentCount(2);
        $this->assertNull(Cache::get(VoiceWebhookHealth::CACHE_KEY));
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_la_ultima_entrega_buena_sale_de_la_post_llamada_aceptada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10T15:00:00Z'));
        VoiceEvents::record('post_call_transcription', null, 'elevenlabs', 'conv_salud_1', ['outcome' => 'recorded']);
        Carbon::setTestNow(Carbon::parse('2026-11-10T16:00:00Z'));
        // Lo que no es una entrega del webhook post-llamada (aquí, la iniciación) no cuenta.
        VoiceEvents::record('initiation_received', null, 'elevenlabs', 'conv_salud_2', ['outcome' => 'denied']);
        $this->proveedor();

        [, $r] = $this->salud();
        $this->assertSame('2026-11-10T15:00:00Z', $r['lastGoodDeliveryAt']);
        $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true]));
        $this->assertStringContainsString('· última entrega buena 2026-11-10T15:00:00Z', Artisan::output());
        Carbon::setTestNow();
    }

    public function test_el_doctor_y_el_estado_ensenan_el_desajuste_y_sin_comprobar(): void
    {
        $vista = $this->adminWithPermissions(['telephony.view']);
        $this->getJson('/api/admin/voice/status', $vista)->assertOk()->assertJsonPath('webhookHealth', null);
        $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true]));
        $this->assertStringContainsString('Webhook    : sin comprobar (voice:webhook-health;', Artisan::output());

        $this->proveedor(['is_auto_disabled' => true]);
        $this->salud();
        $this->getJson('/api/admin/voice/status', $vista)->assertOk()->assertJsonPath('webhookHealth.state', 'unhealthy')->assertJsonPath('webhookHealth.problems', ['webhook_auto_disabled']);
        $this->assertSame(0, Artisan::call('voice:doctor', ['--telefonia' => true]), 'el doctor informa: no falla por esto');
        $this->assertStringContainsString('Webhook    : NO COINCIDE: webhook_auto_disabled · ', Artisan::output());
    }
}
