<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoicePostCall;
use App\Services\UltronStudio\StudioVoiceLabSessions;
use App\Services\UltronStudio\StudioVoiceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\UltronStudio\PublicationFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\Feature\UltronStudio\VoiceLabFixtures;
use Tests\TestCase;

/**
 * EL INFORME POST-LLAMADA DEL LABORATORIO HABLADO, POR EL CAMINO REAL
 * (contrato H8, D8-19 y D8-23; LAB8 §2.6; TEST8-b, hallazgo F-1).
 *
 * El hallazgo: nadie escuchaba `voice.lab.post_call` fuera de las pruebas, así
 * que una post-llamada FIRMADA de un agente de laboratorio respondía
 * `{"ok":true,"unknown":true}`, dejaba `unknown_provider_call`, ECHABA EL FRENO
 * y la sesión seguía `open` (veredicto imposible). En producción, cada
 * conversación del laboratorio habría echado el freno y publicar habría dado
 * `voice_braked`.
 *
 * Aquí NADIE CONECTA NADA A MANO: el oyente es el que registra la aplicación
 * al arrancar (`AppServiceProvider`). Se abre una sesión de verdad por la API
 * del Studio (ElevenLabs simulado sobre `Http::fake`, red cerrada), llega la
 * post-llamada web firmada con el secreto del webhook por la ruta pública y
 * la sesión queda cerrada con su informe, sin evento de la telefonía y sin
 * freno. Una reentrega (ElevenLabs reintenta) es `duplicate` y tampoco frena.
 */
class VoiceLabPostCallWiringTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use VoiceLabFixtures;

    /** El secreto del webhook de {@see VoiceLabFixtures::elevenLabsLikeProduction()}. */
    private const LAB_WEBHOOK_SECRET = 'whsec-centinela-laboratorio';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->voiceLabRoutes();
        $this->importBaseline();
    }

    public function test_el_oyente_lo_registra_la_aplicacion_al_arrancar_una_sola_vez_y_es_el_del_laboratorio(): void
    {
        $oyentes = Event::getRawListeners()[VoicePostCall::LAB_EVENT] ?? [];

        $this->assertSame([[StudioVoiceLabSessions::class, 'recordProviderReport']], $oyentes,
            'un único oyente, el del laboratorio: sin él, cada conversación del laboratorio echa el freno; dos, lo registrarían dos veces');
    }

    public function test_una_post_llamada_firmada_del_laboratorio_cierra_su_sesion_sin_freno_ni_llamada_desconocida(): void
    {
        $this->voiceLabElevenLabs();
        $this->voiceVerified();
        $this->fakeElevenLabs();
        $tina = $this->tester();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Autora');
        $d = $this->openSession($tina, $this->labVersion($ana)->uuid, $this->seedProfile($ana, null, 'submitted'), null, $this->acceptedMax($tina))
            ->assertCreated()->json('data');
        $this->assertSame('open', $d['status']);
        $peticionesAlAbrir = count(Http::recorded());

        // La conversación WEB del agente de esa sesión (sin llamada de Twilio), 90 de los 180 s del perfil.
        $cuerpo = self::webConversation($d['agent']['providerAgentId'], 'conv_lab_cableado_0001', 90);
        $this->signedPostCall($cuerpo)->assertOk()->assertExactJson(['ok' => true, 'lab' => 'recorded']);

        $this->assertSame(0, VoiceCallEvent::query()->count(), 'ni unknown_provider_call ni ningún otro evento: la conversación es del laboratorio');
        $this->assertSame(0, VoiceBrakeEntry::query()->count(), 'ningún freno');
        $this->assertFalse(VoiceBrake::isOpen());

        $s = $this->voiceLab('GET', "sessions/{$d['uuid']}", $tina)->assertOk()->json('data');
        $this->assertSame(['ended', 'provider_report', 90], [$s['status'], $s['endReason'], $s['metrics']['durationSeconds']]);
        $this->assertSame(['provider' => true, 'tester' => false], $s['metrics']['reported']);
        $this->assertSame(['consultar_horarios', 'end_call'], $s['metrics']['toolCalls']);
        // 3 min a 0,10 US$ reservados; 90 de 180 s hablados: la mitad (el prorrateo del laboratorio).
        $this->assertSame([300000, 150000], [$d['budget']['reservedMicros'], $s['budget']['costMicros']]);

        // ElevenLabs reintenta la misma entrega: duplicada, sin evento ni freno.
        $this->signedPostCall($cuerpo)->assertOk()->assertExactJson(['ok' => true, 'lab' => 'duplicate']);
        $this->assertSame(0, VoiceCallEvent::query()->count());
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame($peticionesAlAbrir, count(Http::recorded()), 'el informe no sale a la red');
    }

    public function test_con_el_oyente_real_una_conversacion_web_de_un_agente_que_no_es_del_laboratorio_sigue_echando_el_freno(): void
    {
        $this->voiceLabElevenLabs();
        $this->fakeElevenLabs();

        $this->signedPostCall(self::webConversation('agent_no_es_de_ninguna_sesion', 'conv_ajena_0001', 30))
            ->assertOk()->assertExactJson(['ok' => true, 'unknown' => true]);

        $this->assertSame(['unknown_provider_call'], VoiceCallEvent::query()->pluck('kind')->all());
        $this->assertTrue(VoiceBrake::isOpen(), 'not_a_lab_session: falla cerrado, como siempre');
    }

    /** El cuerpo de una conversación web: sin `metadata.phone_call`, con el aviso en el primer turno del agente. */
    private static function webConversation(string $agentId, string $conversationId, int $seconds): string
    {
        return (string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => now()->getTimestamp(), 'data' => [
            'agent_id' => $agentId,
            'conversation_id' => $conversationId,
            'status' => 'done',
            'transcript' => [
                ['role' => 'agent', 'message' => StudioVoiceTemplate::AI_NOTICE, 'tool_calls' => [], 'tool_results' => []],
                ['role' => 'user', 'message' => '¿A qué hora abren?', 'tool_calls' => [], 'tool_results' => []],
                ['role' => 'agent', 'message' => 'Te lo consulto.', 'tool_calls' => [['tool_name' => 'consultar_horarios', 'params_as_json' => '{}']], 'tool_results' => []],
                ['role' => 'agent', 'message' => 'Gracias por tu tiempo.', 'tool_calls' => [['tool_name' => 'end_call', 'params_as_json' => '{}']], 'tool_results' => []],
            ],
            'metadata' => ['call_duration_secs' => $seconds],
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** POST del cuerpo CRUDO por la ruta pública, firmado como ElevenLabs: `t=…,v0=hex(HMAC-SHA256(t.cuerpo))`. */
    private function signedPostCall(string $body): TestResponse
    {
        $t = now()->getTimestamp();

        return $this->call('POST', '/api/webhooks/voice/elevenlabs/post-call', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ELEVENLABS_SIGNATURE' => 't='.$t.',v0='.hash_hmac('sha256', $t.'.'.$body, self::LAB_WEBHOOK_SECRET),
        ], $body);
    }
}
