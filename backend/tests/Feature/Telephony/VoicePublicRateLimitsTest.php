<?php

namespace Tests\Feature\Telephony;

use App\Services\Telephony\VoiceRateLimits;
use App\Services\Twilio\TwilioRequestSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * LOS LÍMITES DE LAS CUATRO RUTAS PÚBLICAS (contrato H8, D8-16; Enmienda 5,
 * E5-4, hallazgo M-1 de SEC8): ningún límite por IP antes de autenticar
 * (ElevenLabs sale por dos IP que comparten todos sus clientes); después de
 * autenticar, un cubo por ruta y por llamada; y los fallos, en su propio cubo
 * `ruta|IP|fallo`, que nunca frena una credencial buena. Todo desde la MISMA IP
 * de la prueba, como llegaría ElevenLabs. Sin red.
 *
 * Enmienda 7 (E7-2, B8-c): además, un techo GLOBAL por ruta para lo
 * autenticado (entre todas las llamadas), que una credencial mala no gasta; y,
 * con el cubo de fallos agotado, una línea por minuto y ruta con los fallos
 * silenciados, sin la IP.
 */
class VoicePublicRateLimitsTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const USAGE_URI = '/api/webhooks/voice/twilio/usage-trigger';

    private const SIGNING = 'h8fixturesubaccountsigning000001';

    private const BASE = 'https://crm.ironbody.test';

    private const OTHER_IP = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld(['voice.twilio.signing_token' => self::SIGNING, 'voice.webhook_base_url' => self::BASE]);
    }

    // ── Las cuatro peticiones, con la credencial buena o con una mala ───────

    private function initiation(string $sid, bool $good = true): TestResponse
    {
        $cuerpo = (string) json_encode(['caller_id' => self::STRANGER_PHONE, 'agent_id' => self::SEALED_AGENT, 'called_number' => self::VOICE_FROM, 'call_sid' => $sid, 'conversation_id' => 'conv_'.substr($sid, -12)]);

        return $this->rawPost('/api/webhooks/voice/elevenlabs/initiation', $cuerpo, $this->initiationHeaders($good ? self::INITIATION_SECRET : 'h8fixture-initiation-0000000000000000000009'));
    }

    private function postCall(string $conversation, bool $good = true, string $type = 'post_call_transcription'): TestResponse
    {
        $cuerpo = (string) json_encode(['type' => $type, 'event_timestamp' => now()->getTimestamp(), 'data' => [
            'agent_id' => 'agent_desconocido_0001', 'conversation_id' => $conversation, 'status' => 'done', 'transcript' => [], 'metadata' => ['call_duration_secs' => 3],
        ]]);

        return $this->rawPost('/api/webhooks/voice/elevenlabs/post-call', $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo, secret: $good ? self::WEBHOOK_SECRET : 'whsec_otro_cliente_0000000000000000001')]);
    }

    private function tool(string $conversation, bool $good = true): TestResponse
    {
        return $this->rawPost('/api/voice/tools/consultar_horarios', (string) json_encode(['conversation_id' => $conversation]),
            ['Authorization' => 'Bearer '.($good ? self::TOOL_SECRET : 'h8fixture-tool-bearer-0000000000000000000009')]);
    }

    private function usage(string $token, bool $good = true): TestResponse
    {
        $cuerpo = http_build_query(['AccountSid' => 'AC8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f', 'UsageTriggerSid' => 'UT000000000000000000000000b8000001',
            'DateFired' => 'Wed, 04 Nov 2026 14:01:00 +0000', 'Recurring' => 'daily', 'UsageCategory' => 'totalprice', 'TriggerBy' => 'price',
            'TriggerValue' => '4.00', 'CurrentValue' => '4.02', 'IdempotencyToken' => $token]);
        $firma = $good
            ? TwilioRequestSignature::compute((string) TwilioRequestSignature::canonicalUrl(self::BASE, self::USAGE_URI), TwilioRequestSignature::formPairs($cuerpo), self::SIGNING)
            : str_repeat('A', 27).'=';

        return $this->rawPost(self::USAGE_URI, $cuerpo, ['Content-Type' => 'application/x-www-form-urlencoded', 'X-Twilio-Signature' => $firma]);
    }

    private function sid(int $n): string
    {
        return 'CA'.str_pad(dechex($n), 32, '0', STR_PAD_LEFT);
    }

    /** @return array<string, callable(bool): TestResponse> la ruta → una petición de prueba (buena o mala) */
    private function routes(): array
    {
        return [
            VoiceRateLimits::ROUTE_INITIATION => fn (bool $good): TestResponse => $this->initiation($this->sid(1), $good),
            VoiceRateLimits::ROUTE_POST_CALL => fn (bool $good): TestResponse => $this->postCall('conv_rl_0001', $good),
            VoiceRateLimits::ROUTE_TOOLS => fn (bool $good): TestResponse => $this->tool('conv_rl_0001', $good),
            VoiceRateLimits::ROUTE_USAGE_TRIGGER => fn (bool $good): TestResponse => $this->usage('AC8f-FIRES-UT0001-RL0001', $good),
        ];
    }

    // ── Las pruebas ─────────────────────────────────────────────────────────

    public function test_una_credencial_mala_no_gasta_el_presupuesto_de_la_buena_en_ninguna_ruta(): void
    {
        foreach ($this->routes() as $ruta => $peticion) {
            for ($i = 1; $i <= VoiceRateLimits::FAILURES_PER_MINUTE; $i++) {
                $peticion(false)->assertStatus(401);
            }
            // Pasado el cupo de fallos de esa ruta desde esa IP: 429, sin registrar nada.
            $peticion(false)->assertStatus(429)->assertHeader('Retry-After')->assertJson(['code' => 'too_many_requests']);
            // La credencial buena, desde la MISMA IP, pasa.
            $peticion(true)->assertOk();
            $this->assertNotSame(429, $peticion(true)->status(), "{$ruta}: la buena no se frena por los fallos de otros");
        }
    }

    public function test_el_cubo_de_fallos_es_de_su_ruta_y_de_su_ip(): void
    {
        for ($i = 0; $i <= VoiceRateLimits::FAILURES_PER_MINUTE; $i++) {
            $this->initiation($this->sid(2), false);
        }
        $this->initiation($this->sid(2), false)->assertStatus(429);
        // Otra ruta desde la misma IP: su propio cubo.
        $this->tool('conv_rl_0002', false)->assertStatus(401);
        $this->postCall('conv_rl_0002', false)->assertStatus(401);
        // La misma ruta desde otra IP: su propio cubo.
        $this->withServerVariables(['REMOTE_ADDR' => self::OTHER_IP]);
        $this->initiation($this->sid(2), false)->assertStatus(401);
    }

    public function test_cada_llamada_tiene_su_contador_y_cada_ruta_el_suyo(): void
    {
        $sid = $this->sid(3);
        for ($i = 1; $i <= VoiceRateLimits::PER_CALL[VoiceRateLimits::ROUTE_INITIATION]; $i++) {
            $this->initiation($sid)->assertOk();
        }
        $this->initiation($sid)->assertStatus(429)->assertHeader('Retry-After');
        $this->initiation($this->sid(4))->assertOk();   // otra llamada

        // Las demás rutas, para la MISMA llamada, llevan su propio contador.
        $this->tool('conv_'.substr($sid, -12))->assertOk();
        $this->postCall('conv_'.substr($sid, -12))->assertOk();

        // El post-llamada cuenta por EVENTO: tipo + conversación.
        for ($i = 1; $i <= VoiceRateLimits::PER_CALL[VoiceRateLimits::ROUTE_POST_CALL]; $i++) {
            $this->postCall('conv_rl_0005')->assertOk();
        }
        $this->postCall('conv_rl_0005')->assertStatus(429);
        $this->postCall('conv_rl_0005', type: 'post_call_audio')->assertOk();   // otro evento de la misma conversación
        $this->postCall('conv_rl_0006')->assertOk();

        // Una petición autenticada SIN llamada que la identifique cuenta en el cubo «sin llamada» de SU ruta.
        for ($i = 1; $i <= VoiceRateLimits::PER_CALL[VoiceRateLimits::ROUTE_TOOLS]; $i++) {
            $this->rawPost('/api/voice/tools/consultar_horarios', '{}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])->assertStatus(422);
        }
        $this->rawPost('/api/voice/tools/consultar_horarios', '{}', ['Authorization' => 'Bearer '.self::TOOL_SECRET])->assertStatus(429);
        $sinLlamada = (string) json_encode(['caller_id' => self::STRANGER_PHONE, 'agent_id' => self::SEALED_AGENT, 'called_number' => self::VOICE_FROM]);
        $this->rawPost('/api/webhooks/voice/elevenlabs/initiation', $sinLlamada, $this->initiationHeaders())->assertOk();

        // El aviso de Twilio, por su IdempotencyToken.
        for ($i = 1; $i <= VoiceRateLimits::PER_CALL[VoiceRateLimits::ROUTE_USAGE_TRIGGER]; $i++) {
            $this->usage('AC8f-FIRES-UT0001-RL0007')->assertOk();
        }
        $this->usage('AC8f-FIRES-UT0001-RL0007')->assertStatus(429);
        $this->usage('AC8f-FIRES-UT0001-RL0008')->assertOk();
    }

    public function test_las_herramientas_cuentan_por_llamada_ademas_del_tope_de_la_base(): void
    {
        for ($i = 1; $i <= VoiceRateLimits::PER_CALL[VoiceRateLimits::ROUTE_TOOLS]; $i++) {
            $this->tool('conv_rl_0009')->assertOk();
        }
        $this->tool('conv_rl_0009')->assertStatus(429);
        $this->tool('conv_rl_0010')->assertOk();
    }

    public function test_ya_no_hay_un_contador_por_ip_comun_a_las_rutas(): void
    {
        // E7-2: el techo GLOBAL de la ruta (120 por defecto) es otra cosa —lo autenticado de la ruta
        // entre todas las llamadas y todas las IP— y tiene su prueba abajo; aquí se sube para mirar
        // solo que ya no hay un cubo por IP.
        config(['voice.public_limits.global_per_minute.initiation' => 200]);
        // Antes: `throttle:120,1` en la iniciación, en un cubo sha1(dominio|IP) común a las cuatro rutas.
        for ($n = 100; $n < 100 + 125; $n++) {
            $this->initiation($this->sid($n))->assertOk();
        }
        $this->tool('conv_rl_0011')->assertOk();
        $this->postCall('conv_rl_0011')->assertOk();
        Http::assertNothingSent();
    }

    // ── E7-2: el techo global de lo autenticado y los fallos silenciados ────

    /** Una petición de la ruta con una llamada (o un aviso) DISTINTA en cada `$n`, buena o mala. */
    private function distinct(string $route, int $n, bool $good = true): TestResponse
    {
        return match ($route) {
            VoiceRateLimits::ROUTE_INITIATION => $this->initiation($this->sid(1000 + $n), $good),
            VoiceRateLimits::ROUTE_POST_CALL => $this->postCall('conv_global_'.$n, $good),
            VoiceRateLimits::ROUTE_TOOLS => $this->tool('conv_global_'.$n, $good),
            VoiceRateLimits::ROUTE_USAGE_TRIGGER => $this->usage('AC8f-FIRES-UT0001-G'.$n, $good),
        };
    }

    public function test_el_techo_global_frena_lo_autenticado_de_su_ruta_entre_todas_las_llamadas_y_una_credencial_mala_no_lo_gasta(): void
    {
        $this->assertSame([VoiceRateLimits::ROUTE_INITIATION => 120, VoiceRateLimits::ROUTE_POST_CALL => 240, VoiceRateLimits::ROUTE_TOOLS => 600, VoiceRateLimits::ROUTE_USAGE_TRIGGER => 60],
            array_map(VoiceRateLimits::globalPerMinute(...), array_combine(array_keys(VoiceRateLimits::PER_CALL), array_keys(VoiceRateLimits::PER_CALL))),
            'los defectos de config/voice.php');
        // Techos pequeños (el mínimo de cada ruta es su cupo por llamada) para recorrerlos aquí.
        $techos = [VoiceRateLimits::ROUTE_INITIATION => 12, VoiceRateLimits::ROUTE_POST_CALL => 12, VoiceRateLimits::ROUTE_TOOLS => 61, VoiceRateLimits::ROUTE_USAGE_TRIGGER => 12];
        config(['voice.public_limits.global_per_minute' => ['initiation' => 12, 'post_call' => 12, 'tools' => 61, 'usage_trigger' => 12]]);

        foreach ($techos as $ruta => $techo) {
            $this->assertSame($techo, VoiceRateLimits::globalPerMinute($ruta));
            // Sin la credencial, más de lo que da su cubo de fallos (401 y luego 429): no gasta el techo.
            for ($i = 0; $i < VoiceRateLimits::FAILURES_PER_MINUTE + 5; $i++) {
                $this->assertContains($this->distinct($ruta, $i, false)->status(), [401, 429]);
            }
            // Con la credencial, cada petición de una llamada DISTINTA: el techo entero, ni una menos.
            for ($i = 0; $i < $techo; $i++) {
                $this->assertNotSame(429, $this->distinct($ruta, $i)->status(), "{$ruta}: la buena número ".($i + 1).' de '.$techo);
            }
            $this->distinct($ruta, $techo)->assertStatus(429)->assertHeader('Retry-After')->assertJson(['code' => 'too_many_requests']);
        }
        // Con las cuatro agotadas, la credencial mala sigue en su cubo y su 401 o su 429, no en el techo.
        $this->withServerVariables(['REMOTE_ADDR' => self::OTHER_IP]);
        $this->distinct(VoiceRateLimits::ROUTE_TOOLS, 9000, false)->assertStatus(401);

        // Pasado el minuto, el techo vuelve entero.
        $this->travel(61)->seconds();
        $this->distinct(VoiceRateLimits::ROUTE_INITIATION, 500)->assertOk();
    }

    public function test_frenada_por_su_llamada_una_peticion_no_gasta_el_techo_global(): void
    {
        config(['voice.public_limits.global_per_minute.initiation' => 15]);
        $sid = $this->sid(7000);
        for ($i = 1; $i <= VoiceRateLimits::PER_CALL[VoiceRateLimits::ROUTE_INITIATION]; $i++) {
            $this->initiation($sid)->assertOk();
        }
        // Diez más de la misma llamada: 429 por su cupo, sin tocar el de la ruta.
        for ($i = 0; $i < 10; $i++) {
            $this->initiation($sid)->assertStatus(429);
        }
        // Quedan exactamente 15 − 10 = 5 para el resto de llamadas.
        for ($n = 1; $n <= 5; $n++) {
            $this->initiation($this->sid(7000 + $n))->assertOk();
        }
        $this->initiation($this->sid(7100))->assertStatus(429);
    }

    public function test_con_el_cubo_de_fallos_agotado_sale_una_linea_por_minuto_y_ruta_con_los_silenciados_y_sin_la_ip(): void
    {
        $registro = $this->voiceLogHandler();
        $silenciados = fn (): array => array_values(array_map(static fn (LogRecord $r): array => $r->context,
            array_filter($registro->getRecords(), static fn (LogRecord $r): bool => $r->message === 'voice.public.failures_silenced')));

        for ($i = 0; $i < VoiceRateLimits::FAILURES_PER_MINUTE; $i++) {
            $this->initiation($this->sid(1), false)->assertStatus(401);
        }
        $this->assertSame([], $silenciados(), 'mientras cabe en el cubo, cada fallo lleva su aviso y no hay línea de silenciados');
        for ($i = 0; $i < 5; $i++) {
            $this->initiation($this->sid(1), false)->assertStatus(429);
        }
        $this->assertSame([['route' => 'initiation', 'silenced' => 1]], $silenciados(), 'UNA línea, al silenciar el primero');

        // Otra ruta: su propia línea.
        for ($i = 0; $i <= VoiceRateLimits::FAILURES_PER_MINUTE; $i++) {
            $this->tool('conv_rl_silencio', false);
        }
        $this->assertSame([['route' => 'initiation', 'silenced' => 1], ['route' => 'tools', 'silenced' => 1]], $silenciados());

        // El minuto siguiente: el cubo vuelve a llenarse y la siguiente línea cuenta los 4 que se callaron
        // después de la primera más el que la dispara.
        $this->travel(61)->seconds();
        for ($i = 0; $i < VoiceRateLimits::FAILURES_PER_MINUTE; $i++) {
            $this->initiation($this->sid(1), false)->assertStatus(401);
        }
        $this->initiation($this->sid(1), false)->assertStatus(429);
        $this->initiation($this->sid(1), false)->assertStatus(429);
        $this->assertSame([['route' => 'initiation', 'silenced' => 1], ['route' => 'tools', 'silenced' => 1], ['route' => 'initiation', 'silenced' => 5]], $silenciados());

        // Sin la IP ni nada de la petición, en ninguna línea de la voz.
        foreach ($registro->getRecords() as $r) {
            $this->assertStringNotContainsString('127.0.0.1', $r->message.json_encode($r->context));
        }
        // Una credencial buena no deja línea de silenciados.
        $this->initiation($this->sid(2))->assertOk();
        $this->assertCount(3, $silenciados());
    }

    /** El canal `voice` en memoria (sin disco): el manejador de Monolog que recoge las líneas. */
    private function voiceLogHandler(): TestHandler
    {
        config(['logging.channels.voice' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
        Log::forgetChannel('voice');
        $manejador = Log::channel('voice')->getLogger()->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $manejador);

        return $manejador;
    }
}
