<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\VoiceCallStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;
use Tests\Unit\Voice\Support\ClosedPolicyCopy;

/**
 * EL JOB DE LA SALIENTE CON LA CONSTANTE CERRADA, sobre su copia cerrada en
 * memoria (C-4; {@see ClosedPolicyCopy}): lo que afirmaban
 * `PlaceOutboundVoiceCallTest` y `PlaceOutboundVoiceCallRecheckTest` antes
 * del commit `voice(calls-open)`, que es el destino de la vuelta atrás.
 *
 * LO QUE SE PROTEGE (DF-G9): cerrado, el job cancela por
 * `calls_disabled_in_code` ANTES de mirar nada más —aunque todo lo demás esté
 * en regla, y también con las salientes apagadas—, sin llegar al iniciador,
 * sin red y liberando la reserva. Con TODO en regla, el job del árbol
 * (abierto) pasaría (`recheckAfterCode` sin motivo): lo único que cambia es la
 * constante.
 */
class PlaceOutboundVoiceCallClosedCopyTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        ClosedPolicyCopy::load();
        Http::preventStrayRequests();
        Http::fake();
        // Un miércoles laborable de Bogotá, dentro de la ventana legal (07:00–19:00).
        $this->travelTo(Carbon::parse('2026-11-04 10:00:00', 'America/Bogota'));
    }

    private static function sinMarcar(): VoiceCallStarter
    {
        return new class implements VoiceCallStarter
        {
            public function startOutbound(OutboundCallRequest $request): OutboundCallResult
            {
                throw new RuntimeException('cerrado, el job no tenía que llegar a marcar');
            }
        };
    }

    /** El job cerrado sobre `$llamada`: lo que devuelve `run()` y la fila. */
    private function cerrado(VoiceCall $llamada): VoiceCall
    {
        $clase = ClosedPolicyCopy::JOB;
        /** @var PlaceOutboundVoiceCall $job */
        $job = new $clase((int) $llamada->id);
        $this->assertSame('canceled', $job->run(self::sinMarcar()));

        return VoiceCall::query()->findOrFail($llamada->id);
    }

    public function test_cerrado_el_job_cancela_por_la_constante_aunque_todo_lo_demas_este_en_regla(): void
    {
        $this->voiceWorld(['voice.outbound.enabled' => true, 'voice.twilio.enabled' => true]);
        $this->publishVoice();
        Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.self::SEALED_AGENT, false, OutboundGate::DRIFT_CACHE_SECONDS);
        VoiceNumberGuard::rememberObservation(self::PHONE_NUMBER_ID, true, false);
        $llamada = $this->voiceCall('outbound', 'requested', ['canary_number_uuid' => $this->activeVoiceCanary(), 'remote' => self::CANARY_PHONE]);
        $dia = (string) $llamada->budget_day;

        // Todo en regla: el job del árbol (abierto) no encuentra ningún motivo.
        $this->assertNull((new ReflectionMethod(PlaceOutboundVoiceCall::class, 'recheckAfterCode'))->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), $llamada));
        $this->assertSame(610500, VoiceCostGuard::committedMicros($dia));

        // El cerrado cancela por la constante, sin petición, y libera la reserva.
        $fila = $this->cerrado($llamada);
        $this->assertSame(['canceled', 'calls_disabled_in_code'], [$fila->status, $fila->policy_reason]);
        $this->assertNotNull($fila->ended_at);
        $this->assertSame(0, VoiceCostGuard::committedMicros($dia));
        $this->assertSame([], VoiceCallEvent::query()->where('call_id', $llamada->id)->pluck('kind')->all());
        Http::assertNothingSent();
    }

    public function test_cerrado_la_constante_va_antes_que_las_banderas(): void
    {
        $this->voiceWorld();
        $this->assertNotTrue(config('voice.outbound.enabled'));
        $fila = $this->cerrado($this->voiceCall('outbound', 'requested'));
        $this->assertSame(['canceled', 'calls_disabled_in_code'], [$fila->status, $fila->policy_reason], 'la constante, la primera (DF-G9), no outbound_disabled');
        Http::assertNothingSent();
    }
}
