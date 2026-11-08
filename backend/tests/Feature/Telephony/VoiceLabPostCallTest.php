<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoicePostCall;
use App\Services\UltronStudio\StudioVoiceLabSessions;
use App\Services\Voice\Dto\VoiceWebhookVerdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * El informe post-llamada de una conversación del LABORATORIO HABLADO (LAB8,
 * informe-lab §2.6): la telefonía no nombra el Studio, así que lo entrega por
 * el evento con nombre `voice.lab.post_call` (el veredicto neutro y los
 * códigos de la guarda). Solo si el laboratorio la reconoce como suya deja de
 * ser `unknown_provider_call`; sin oyente, con `not_a_lab_session`, con
 * llamada de Twilio o con el agente publicado, el freno se abre como siempre
 * (falla cerrado). Sin red.
 *
 * El oyente REAL lo registra la aplicación al arrancar (`AppServiceProvider`,
 * B8-c; TEST8-b F-1): estas pruebas no lo vuelven a conectar. Las que
 * necesitan otro oyente (uno que responda lo que la prueba quiere, o
 * ninguno) quitan antes el de arranque con `Event::forget`; el camino de
 * punta a punta con una sesión de verdad está en `VoiceLabPostCallWiringTest`.
 */
class VoiceLabPostCallTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const AGENTE_DE_LABORATORIO = 'agent_lab_session_0001';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
    }

    private function postCall(string $agente, string $conversacion, ?string $sid = null): TestResponse
    {
        $metadatos = ['call_duration_secs' => 37] + ($sid === null ? [] : ['phone_call' => ['call_sid' => $sid]]);
        $cuerpo = (string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => now()->getTimestamp(), 'data' => [
            'agent_id' => $agente, 'conversation_id' => $conversacion, 'status' => 'done', 'transcript' => [
                ['role' => 'agent', 'message' => 'Hola, ¿en qué te ayudo?', 'tool_calls' => [], 'tool_results' => []],
            ], 'metadata' => $metadatos,
        ]]);

        return $this->rawPost('/api/webhooks/voice/elevenlabs/post-call', $cuerpo, ['ElevenLabs-Signature' => $this->elevenSignature($cuerpo)]);
    }

    public function test_sin_oyente_una_conversacion_web_desconocida_sigue_abriendo_el_freno(): void
    {
        Event::forget(VoicePostCall::LAB_EVENT);
        $this->postCall(self::AGENTE_DE_LABORATORIO, 'conv_lab_0001')->assertOk()->assertJson(['unknown' => true]);
        $this->assertSame(['unknown_provider_call'], VoiceCallEvent::query()->pluck('kind')->all());
        $this->assertTrue(VoiceBrake::isOpen());
    }

    public function test_si_el_laboratorio_la_reconoce_no_es_desconocida_y_recibe_veredicto_y_guarda(): void
    {
        $recibido = [];
        Event::forget(VoicePostCall::LAB_EVENT);
        Event::listen(VoicePostCall::LAB_EVENT, function (VoiceWebhookVerdict $v, ?array $guarda) use (&$recibido): string {
            $recibido = [$v->conversationId, $v->agentId, $v->durationSeconds, $guarda];

            return 'recorded';
        });

        $this->postCall(self::AGENTE_DE_LABORATORIO, 'conv_lab_0002')->assertOk()->assertExactJson(['ok' => true, 'lab' => 'recorded']);
        $this->assertSame(['conv_lab_0002', self::AGENTE_DE_LABORATORIO, 37, [['turn' => 0, 'code' => 'voice_notice_missing']]], $recibido,
            'el laboratorio recibe la guarda posterior: solo códigos y turnos');
        $this->assertSame(0, VoiceCallEvent::query()->count(), 'la telefonía no escribe nada de una conversación del laboratorio');
        $this->assertFalse(VoiceBrake::isOpen());
        Http::assertNothingSent();
    }

    public function test_no_es_del_laboratorio_si_lo_dice_el_si_hay_llamada_de_twilio_o_si_es_el_agente_publicado(): void
    {
        $llamadas = 0;
        Event::forget(VoicePostCall::LAB_EVENT);
        Event::listen(VoicePostCall::LAB_EVENT, function () use (&$llamadas): string {
            $llamadas++;

            return 'not_a_lab_session';
        });
        $this->postCall('agent_desconocido_0001', 'conv_lab_0003')->assertOk()->assertJson(['unknown' => true]);
        $this->assertSame(1, $llamadas);

        Event::forget(VoicePostCall::LAB_EVENT);
        Event::listen(VoicePostCall::LAB_EVENT, function () use (&$llamadas): string {
            $llamadas++;

            return 'recorded';
        });
        // Con llamada de Twilio no es una conversación web: ni se pregunta.
        $this->postCall(self::AGENTE_DE_LABORATORIO, 'conv_lab_0004', 'CA'.str_repeat('4', 32))->assertOk()->assertJson(['unknown' => true]);
        // El agente publicado tampoco es de laboratorio.
        $this->publishVoice();
        $this->postCall(self::SEALED_AGENT, 'conv_lab_0005')->assertOk()->assertJson(['unknown' => true]);
        $this->assertSame(1, $llamadas, 'solo se preguntó en la primera');
        $this->assertSame(3, VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->count());
        $this->assertTrue(VoiceBrake::isOpen());
    }

    public function test_el_oyente_real_del_laboratorio_casa_con_la_carga_y_dice_que_un_agente_ajeno_no_es_suyo(): void
    {
        // El de arranque, sin conectarlo a mano: la firma de
        // StudioVoiceLabSessions::recordProviderReport(VoiceWebhookVerdict, ?array) casa con la carga del evento.
        $this->assertSame([[StudioVoiceLabSessions::class, 'recordProviderReport']], Event::getRawListeners()[VoicePostCall::LAB_EVENT] ?? []);
        $this->postCall('agent_desconocido_0002', 'conv_lab_0006')->assertOk()->assertJson(['unknown' => true]);
        $this->assertTrue(VoiceBrake::isOpen(), 'not_a_lab_session: el freno se abre como siempre');
    }
}
