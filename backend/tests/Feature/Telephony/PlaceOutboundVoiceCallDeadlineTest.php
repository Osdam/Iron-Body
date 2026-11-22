<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentMapper;
use App\Services\Voice\ElevenLabs\ElevenLabsCallStarter;
use App\Services\Voice\VoiceCallStarter;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;
use Tests\Unit\Voice\Support\VoiceSpecSamples as M;

/**
 * EL PLAZO PARA MARCAR (Enmienda del 22-11-2026, E19-2; H-2 del informe
 * `informe-be-voz.md`). Con la caché de la deriva vacía, el job lee el agente
 * y sus herramientas antes del POST; con un proveedor lento, el job pasaba de
 * sus 30 s, moría y dejaba la fila `ambiguous` sin haber marcado. Ahora el POST
 * sale, como muy tarde, a los 6 s (30 − 20 − 4) y lo que no llega a tiempo
 * cancela SIN PETICIÓN con un motivo claro: un final limpio.
 *
 * El proveedor falso es LENTO adelantando el reloj de la aplicación (el del
 * plazo del job) en cada petición; sin esperar de verdad. Con la constante
 * cerrada, `run()` no pasa de ella: se recorren, como en
 * `PlaceOutboundVoiceCallRecheckTest`, `recheckAfterCode()` y el último paso
 * (`dialWithin()`), los mismos que llama `run()`. Sin red.
 */
class PlaceOutboundVoiceCallDeadlineTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const SIM = 'http://127.0.0.1:8096';

    /** @var list<float|int> los plazos (`timeout`) con que salió cada GET */
    private array $esperas = [];

    private string $proyeccion = '';

    private ?string $canario = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Un miércoles laborable de Bogotá, dentro de la ventana legal (07:00–19:00); el reloj, en UTC.
        $this->travelTo(Carbon::parse('2026-11-04 10:00:00', 'America/Bogota')->utc());
        $this->voiceWorld(['voice.outbound.enabled' => true, 'voice.twilio.enabled' => true]);
        // ElevenLabs con todas las piezas: el desplegador de verdad, contra el proveedor falso.
        config(['voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => 'xi-centinela-PLAZO-0123456789', 'voice.elevenlabs.agent_id' => 'agent_verify_0001',
            'voice.elevenlabs.region' => 'default', 'voice.elevenlabs.base_url' => self::SIM, 'voice.elevenlabs.timeout_seconds' => 10]);
        $this->proyeccion = (string) ElevenLabsAgentMapper::payload(M::voiceSpec(), M::target())->projectionHash;
        $this->publishVoice(agent: ['projection_hash' => $this->proyeccion]);
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, false);
        $this->assertNull(Cache::get(OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT), 'la caché de la deriva, vacía: el job la lee');
    }

    public function test_el_plazo_para_marcar_cuadra_con_el_del_job_y_el_del_iniciador(): void
    {
        $job = new PlaceOutboundVoiceCall(1);
        $this->assertSame(30, $job->timeout);
        $this->assertSame(20, ElevenLabsCallStarter::MAX_TIMEOUT_SECONDS);
        $this->assertSame(6000.0, $job->dialDeadline()->getPreciseTimestamp(3) - now()->getPreciseTimestamp(3), '30 − 20 − 4 = 6 s desde que empieza');
        $this->assertLessThanOrEqual($job->timeout, 6 + ElevenLabsCallStarter::MAX_TIMEOUT_SECONDS + PlaceOutboundVoiceCall::AFTER_DIAL_MARGIN_SECONDS,
            'aunque el POST agote su plazo, el job termina dentro del suyo');
    }

    public function test_con_un_proveedor_sano_la_deriva_se_lee_dentro_del_plazo_y_se_marca(): void
    {
        $this->proveedor(segundosPorPeticion: 0.2);
        $llamada = $this->canaryCall();
        $this->assertNull($this->recheck($llamada), 'sin deriva y a tiempo: ningún motivo');
        $this->assertCount(5, $this->esperas, 'el agente y sus cuatro herramientas');
        $this->assertEqualsWithDelta(6.0, $this->esperas[0], 0.0001, 'el primer GET, con lo que queda (6 s), no con los 10 de la configuración');
        foreach ($this->esperas as $espera) {
            $this->assertLessThanOrEqual(6.0, $espera);
        }
        $this->assertFalse(Cache::get(OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT), 'la observación queda en la caché');
    }

    public function test_un_proveedor_lento_cancela_con_drift_unverified_sin_marcar_y_sin_ambiguous(): void
    {
        $lineas = $this->lineas();
        $this->proveedor(segundosPorPeticion: 2.5);
        $llamada = $this->canaryCall();
        $dia = (string) $llamada->budget_day;
        $this->assertGreaterThan(0, VoiceCostGuard::committedMicros($dia), 'la reserva de la puerta');

        $motivo = $this->recheck($llamada);
        $this->assertSame('drift_unverified', $motivo, 'cinco GET de 2,5 s: la deriva no se comprobó a tiempo para marcar');
        $fila = $this->cancelar($llamada, $motivo);
        $this->assertSame(['canceled', 'drift_unverified'], [$fila->status, $fila->policy_reason], 'un final limpio, no ambiguous');
        $this->assertNotNull($fila->ended_at);
        $this->assertSame(0, VoiceCostGuard::committedMicros($dia), 'la reserva, liberada');
        $this->assertSame(0, VoiceCall::query()->whereIn('status', VoiceCall::LIVE)->count(), 'nada vivo que bloquee la siguiente');
        Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '/outbound-call'));
        $this->assertContains(['voice.outbound.dial_deadline', 'drift'], $lineas());
        $this->assertFalse(Cache::get(OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT), 'la observación, aunque tardía, queda en la caché');
    }

    public function test_sin_tiempo_para_leer_la_deriva_no_sale_ninguna_peticion(): void
    {
        $lineas = $this->lineas();
        $this->proveedor(segundosPorPeticion: 0.2);
        $this->assertSame('drift_unverified', $this->recheck($this->canaryCall(), now()->addMilliseconds(500)));
        Http::assertNothingSent();
        $this->assertContains(['voice.outbound.dial_deadline', 'drift'], $lineas());
    }

    public function test_una_deriva_vista_tarde_echa_el_freno_igual(): void
    {
        $this->proveedor(segundosPorPeticion: 2.5, deriva: true);
        $this->assertSame('agent_drift', $this->recheck($this->canaryCall()), 'lo que protege la deriva no depende del plazo');
        $this->assertSame(['agent_drift'], VoiceBrakeEntry::query()->where('kind', 'open')->pluck('reason')->all());
    }

    public function test_justo_antes_de_marcar_sin_tiempo_cancela_con_error_y_con_tiempo_marca(): void
    {
        $lineas = $this->lineas();
        $tarde = $this->canaryCall();
        $estado = $this->dial($tarde, now()->subMillisecond(), self::starter(static fn () => throw new RuntimeException('sin tiempo, no tenía que marcar')));
        $this->assertSame('canceled', $estado);
        $this->assertSame('error', VoiceCall::query()->findOrFail($tarde->id)->policy_reason, 'DF-G8: un fallo local antes de enviar');
        $this->assertContains(['voice.outbound.dial_deadline', 'before_dial'], $lineas());

        $marcadas = 0;
        $aTiempo = $this->canaryCall();
        $estado = $this->dial($aTiempo, now()->addSeconds(6), self::starter(static function () use (&$marcadas): OutboundCallResult {
            $marcadas++;

            return new OutboundCallResult(OutboundCallResult::OUTCOME_REJECTED, OutboundCallResult::REASON_PROVIDER_DECLINED, null, null, 200);
        }));
        $this->assertSame([1, 'failed_to_start'], [$marcadas, $estado], 'con tiempo, UNA petición y su resultado');
    }

    /** Una saliente pedida al canario de voz activo (el mismo canario en cada una). */
    private function canaryCall(): VoiceCall
    {
        $this->canario ??= $this->activeVoiceCanary();

        return $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $this->canario, 'remote' => self::CANARY_PHONE]);
    }

    private function recheck(VoiceCall $llamada, ?CarbonInterface $plazo = null): ?string
    {
        $job = new PlaceOutboundVoiceCall((int) $llamada->id);

        return (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'recheckAfterCode'))->invoke($job, VoiceCall::query()->findOrFail($llamada->id), $plazo ?? $job->dialDeadline());
    }

    private function cancelar(VoiceCall $llamada, string $motivo): VoiceCall
    {
        (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'cancel'))->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), $llamada, $motivo);

        return VoiceCall::query()->findOrFail($llamada->id);
    }

    /** El último paso del job (`dialWithin()`), con la petición que construye el job (`request()`). */
    private function dial(VoiceCall $llamada, CarbonInterface $plazo, VoiceCallStarter $starter): string
    {
        $job = new PlaceOutboundVoiceCall((int) $llamada->id);
        $fila = VoiceCall::query()->findOrFail($llamada->id);
        $peticion = (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'request'))->invoke($job, $fila);
        $this->assertInstanceOf(OutboundCallRequest::class, $peticion);

        return (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'dialWithin'))->invoke($job, $fila, $peticion, $starter, $plazo);
    }

    /** @param  \Closure(): OutboundCallResult  $hace */
    private static function starter(\Closure $hace): VoiceCallStarter
    {
        return new class($hace) implements VoiceCallStarter
        {
            public function __construct(private readonly \Closure $hace) {}

            public function startOutbound(OutboundCallRequest $request): OutboundCallResult
            {
                return ($this->hace)();
            }
        };
    }

    /** @return \Closure(): list<array{0: string, 1: ?string}> las líneas de la voz (mensaje y etapa) registradas desde ahora */
    private function lineas(): \Closure
    {
        $lineas = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$lineas): void {
            $lineas[] = [$m->message, $m->context['stage'] ?? null];
        });

        return function () use (&$lineas): array {
            return $lineas;
        };
    }

    /**
     * ElevenLabs falso: el agente sellado (sin tocar, o con la ráfaga encendida si `$deriva`) y sus cuatro
     * herramientas; cada petición «tarda» `$segundosPorPeticion` en el reloj de la aplicación.
     */
    private function proveedor(float $segundosPorPeticion, bool $deriva = false): void
    {
        $carga = ElevenLabsAgentMapper::payload(M::voiceSpec(), M::target());
        $herramientas = $carga->tools();
        $ids = ['tool_a' => 'consultar_horarios', 'tool_b' => 'consultar_planes', 'tool_c' => 'no_volver_a_llamar', 'tool_d' => 'solicitar_humano'];
        $agente = $carga->agentBody();
        $agente['agent_id'] = self::SEALED_AGENT;
        $agente['conversation_config']['agent']['prompt']['tool_ids'] = array_keys($ids);
        $agente['conversation_config']['agent']['prompt']['temperature'] = 0.0;
        $agente['conversation_config']['agent']['prompt']['built_in_tools']['skip_turn'] = null;
        if ($deriva) {
            $agente['platform_settings']['call_limits']['bursting_enabled'] = true;
        }

        Http::fake(function (Request $r, array $opciones) use ($agente, $herramientas, $ids, $segundosPorPeticion) {
            $this->esperas[] = $opciones['timeout'];
            Carbon::setTestNow(now()->addMilliseconds((int) round($segundosPorPeticion * 1000)));
            if ($r->url() === self::SIM.'/v1/convai/agents/'.self::SEALED_AGENT) {
                return Http::response($agente, 200);
            }
            foreach ($ids as $id => $nombre) {
                if ($r->url() === self::SIM.'/v1/convai/tools/'.$id) {
                    return Http::response(['id' => $id, 'tool_config' => $herramientas[$nombre] + ['assignments' => []], 'access_info' => []], 200);
                }
            }

            return Http::response('no', 404);
        });
    }
}
