<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\ElevenLabs\ElevenLabsCallStarter;
use App\Services\Voice\NullVoiceCallStarter;
use App\Services\Voice\VoiceCallStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * El job de la saliente (contrato H8, D8-9, D8-12, D8-13; fixture
 * `outbound-gate-cases.json` §job). Sin red.
 *
 * EN EL ÁRBOL COMPARTIDO la constante está cerrada: el job cancela con
 * `calls_disabled_in_code` ANTES de mirar nada más (DF-G9) y nunca llega al
 * iniciador; se prueba de punta a punta. Las demás comprobaciones del job
 * (publicación movida, deriva, freno, banderas) van detrás de la constante:
 * las recorre TEST8 en la copia del banco con la constante girada. Aquí se
 * prueban además, por separado, el CUERPO de la petición (contra las
 * aserciones del fixture) y el MAPEO de cada resultado del iniciador a la
 * fila, el evento y la reserva.
 */
class PlaceOutboundVoiceCallTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
    }

    public function test_el_job_es_de_un_intento_en_su_carril_y_con_su_plazo(): void
    {
        $job = new PlaceOutboundVoiceCall(1);
        $this->assertSame([1, 30, true, 'voice', 'voice'], [$job->tries, $job->timeout, $job->failOnTimeout, $job->connection, $job->queue]);
        $this->assertFalse(method_exists($job, 'backoff'));
        $this->assertFalse(method_exists($job, 'retryUntil'));
        $this->assertSame('voice', config('queue.lanes.voice.queue'));
        $this->assertGreaterThan(30, config('queue.connections.voice.retry_after'));
    }

    public function test_con_la_constante_cerrada_cancela_sin_peticion_y_libera_la_reserva(): void
    {
        $llamada = $this->voiceCall('outbound', 'requested');
        $iniciador = new class implements VoiceCallStarter
        {
            public function startOutbound(OutboundCallRequest $request): OutboundCallResult
            {
                throw new RuntimeException('el job no tenía que llegar a marcar');
            }
        };
        $this->assertSame('canceled', (new PlaceOutboundVoiceCall((int) $llamada->id))->run($iniciador));
        $fila = VoiceCall::query()->findOrFail($llamada->id);
        $this->assertSame(['canceled', 'calls_disabled_in_code'], [$fila->status, $fila->policy_reason]);
        $this->assertNotNull($fila->ended_at);
        // `handle()` con la fábrica real tampoco sale a la red.
        $otra = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000035']);
        (new PlaceOutboundVoiceCall((int) $otra->id))->handle();
        $this->assertSame('canceled', VoiceCall::query()->findOrFail($otra->id)->status);
        Http::assertNothingSent();
    }

    public function test_una_llamada_que_ya_no_esta_requested_no_se_toca(): void
    {
        $llamada = $this->voiceCall('outbound', 'busy');
        (new PlaceOutboundVoiceCall((int) $llamada->id))->handle();
        $this->assertSame('busy', VoiceCall::query()->findOrFail($llamada->id)->status);
        $this->assertNull((new PlaceOutboundVoiceCall(999999))->run(new NullVoiceCallStarter), 'una llamada que no existe');
    }

    public function test_el_cuerpo_de_la_peticion_cuadra_con_las_aserciones_del_fixture(): void
    {
        $canario = $this->activeVoiceCanary();
        $llamada = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $canario, 'call_ref' => '8f000000-0000-4000-8000-000000000051']);
        $metodo = new ReflectionMethod(PlaceOutboundVoiceCall::class, 'request');
        /** @var OutboundCallRequest $peticion */
        $peticion = $metodo->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), $llamada);
        $cuerpo = ElevenLabsCallStarter::requestBody($peticion);

        $caso = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/outbound-gate-cases.json')), true)['job'][0];
        foreach ($caso['expected']['request']['bodyAssertions'] as $a) {
            $valor = Arr::get($cuerpo, $a['path']);
            if ($a['path'] === 'conversation_initiation_client_data.dynamic_variables') {
                // E1-5: además va `call_direction` (y la etiqueta), que el fixture no lista.
                $esperadas = $a['equals'];
                $vistas = array_intersect_key($valor, $esperadas);
                ksort($esperadas);
                ksort($vistas);
                $this->assertSame($esperadas, $vistas, $a['path']);
                $this->assertSame('saliente', $valor['call_direction']);

                continue;
            }
            $this->assertSame($a['equals'], $valor, $a['path']);
        }
        $this->assertSame(30, $cuerpo['telephony_call_config']['ringing_timeout_secs']);
        $this->assertSame('...0031', '...'.$peticion->toArray()['toLast4'], 'fuera del cuerpo, solo los 4 dígitos');

        // Un destino que ya no es el sellado (otra huella) no se marca.
        $ajena = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $canario, 'remote' => '+573000000036']);
        $this->assertNull($metodo->invoke(new PlaceOutboundVoiceCall((int) $ajena->id), $ajena));
    }

    public function test_cada_resultado_del_iniciador_deja_su_estado_su_evento_y_su_reserva(): void
    {
        $registrar = new ReflectionMethod(PlaceOutboundVoiceCall::class, 'record');
        $casos = [
            'started' => [new OutboundCallResult('started', null, 'conv_h8fx_0051', 'CA000000000000000000000000a8000033', 200), 'dialing', 'outbound_started'],
            'rejected' => [new OutboundCallResult('rejected', 'client_error', null, null, 422), 'failed_to_start', 'outbound_rejected'],
            'ambiguous' => [new OutboundCallResult('ambiguous', 'missing_ids', 'conv_h8fx_0052', null, 200), 'ambiguous', 'outbound_ambiguous'],
            'not_attempted' => [new OutboundCallResult('not_attempted', 'calls_disabled_in_code', null, null, null), 'canceled', null],
        ];
        $n = 40;
        foreach ($casos as $nombre => [$resultado, $estado, $evento]) {
            $llamada = $this->voiceCall('outbound', 'requested', ['remote' => '+5730000000'.$n++]);
            $this->assertSame($estado, $registrar->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), $llamada, $resultado), $nombre);
            $fila = VoiceCall::query()->findOrFail($llamada->id);
            $this->assertSame($estado, $fila->status, $nombre);
            $this->assertSame($evento === null ? [] : [$evento], VoiceCallEvent::query()->where('call_id', $llamada->id)->pluck('kind')->all(), $nombre);
        }
        $iniciada = VoiceCall::query()->where('status', 'dialing')->sole();
        $this->assertSame(['conv_h8fx_0051', 'CA000000000000000000000000a8000033'], [$iniciada->provider_conversation_id, $iniciada->provider_call_sid]);
        // La ambigua guarda en su evento el id que vino (la fila ambigua no lo admite, DB8), para conciliar.
        $this->assertSame('conv_h8fx_0052', VoiceCallEvent::query()->where('kind', 'outbound_ambiguous')->sole()->codes['conversationId']);
        $this->assertNull(VoiceCall::query()->where('status', 'ambiguous')->sole()->provider_conversation_id);

        // Si el webhook ya la dejó en un final (DF-S2), el job no escribe `dialing`.
        $carrera = $this->voiceCall('outbound', 'busy', ['remote' => '+573000000060']);
        $registrar->invoke(new PlaceOutboundVoiceCall((int) $carrera->id), $carrera, new OutboundCallResult('started', null, 'conv_h8fx_0060', 'CA000000000000000000000000a8000060', 200));
        $this->assertSame('busy', VoiceCall::query()->findOrFail($carrera->id)->status);
    }

    public function test_si_el_job_muere_la_llamada_queda_ambigua_nunca_se_repite(): void
    {
        $llamada = $this->voiceCall('outbound', 'requested');
        (new PlaceOutboundVoiceCall((int) $llamada->id))->failed(new RuntimeException('tiempo agotado'));
        $this->assertSame(['ambiguous', 'job_failed'], [VoiceCall::query()->findOrFail($llamada->id)->status, VoiceCall::query()->findOrFail($llamada->id)->failure_code]);
        $this->assertSame(['outbound_ambiguous'], VoiceCallEvent::query()->where('call_id', $llamada->id)->pluck('kind')->all());
    }
}
