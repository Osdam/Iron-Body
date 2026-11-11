<?php

namespace Tests\Feature\Outreach\V9;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Services\Outreach\Run\Channels\ChannelAttempt;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachSendGate;
use App\Services\Telephony\OutboundPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\Feature\Outreach\V9\Support\V9World;
use Tests\TestCase;

/**
 * V9 · EL CANAL DE VOZ POR ESTRATEGIA EN EL ÁRBOL COMPARTIDO (contrato H9,
 * D9-8 etapas 6–7, D9-9.4; §13.1 #18, #19), con las dos constantes de código
 * cerradas (`OutreachSendGate::SENDS_ENABLED_IN_CODE` y
 * `VoiceCallPolicy::CALLS_ENABLED_IN_CODE`) y la voz SIN declarar en
 * `OutreachSendGate::LIVE_CHANNELS`:
 *
 *  - en SOMBRA nada se pide: el trabajo es del canal simulado y la voz del
 *    H8 no gana ni una fila;
 *  - en VIVO con la constante cerrada, la escalera cancela el trabajo por
 *    `sends_disabled_in_code` (N4) y no llega al canal; sin consentimiento de
 *    voz, por N1 (`no_consent_for_purpose_channel`) antes que nada;
 *  - el canal, llamado a mano con la voz ya declarada viva (L9-81, Enmienda
 *    17), se niega por la constante del H9 (`sends_disabled_in_code`) y no
 *    pide nada;
 *  - lo que diga la puerta se traduce sin reintentos: cada uno de los 28
 *    motivos de `OutboundPolicy` es `failed_to_start` con ese detalle; 202 es
 *    `started` con la referencia de la llamada; y el despachador lo asienta
 *    (referencia una vez; reserva liberada y `failed` con el detalle).
 *
 * Cero red; la cola, falsa.
 */
class V9VoiceChannelTest extends TestCase
{
    use RefreshDatabase;
    use V9World;

    /** Martes 17 de noviembre de 2026, 10:00 de Bogotá. */
    private const NOW = '2026-11-17 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->v9VoiceConfig();
        $this->threePeople();
        $this->v9GreenVoice();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function assertNadaPedido(): void
    {
        $this->assertSame(['voice_calls' => 0, 'voice_call_events' => 0], $this->v9VoiceRows(), 'la puerta de la voz no recibió nada');
        Queue::assertNotPushed(PlaceOutboundVoiceCall::class);
        Http::assertNothingSent();
    }

    public function test_en_sombra_nada_se_pide(): void
    {
        $this->runFlags('shadow', ['outreach.voice.enabled' => true]);
        $this->v9VoiceStrategy('PUBLISHED');
        $this->v9Member();

        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $plan = $planner->planDue(now());
        $this->assertSame(1, $plan['planned'] ?? null, 'la persona califica y su paso de voz se planifica… '.json_encode($plan));
        $trabajo = DB::table('outreach_jobs')->sole();
        $this->assertSame(['simulated', 'shadow', 0, 0], [$trabajo->channel, $trabajo->mode, (int) $trabajo->reserved_units, (int) $trabajo->reserved_micros], '…como trabajo SIMULADO y sin reserva');

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(30));
        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(['simulated' => 1], $r['results']);
        $this->assertSame('simulated', DB::table('outreach_jobs')->value('status'));
        $this->assertSame(['not_attempted', 'simulated'], [DB::table('outreach_outcomes')->value('outcome'), DB::table('outreach_outcomes')->value('source')]);
        $this->assertNadaPedido();
    }

    public function test_en_vivo_con_la_constante_cerrada_la_escalera_cancela_y_no_llega_a_la_puerta(): void
    {
        $this->runFlags('live', ['outreach.voice.enabled' => true]);
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $m = $this->v9Member();
        $t = $this->v9VoiceJob($vivo, $m, 'claimed')['job'];

        $r = (new OutreachDispatcher)->process((int) $t->id, now());
        $this->assertSame('denied', $r);
        $trabajo = DB::table('outreach_jobs')->where('id', $t->id)->first();
        $this->assertSame(['canceled', 'sends_disabled_in_code'], [$trabajo->status, $trabajo->cancel_reason], 'N4: la constante del H9, la primera');
        $this->assertNotNull($trabajo->budget_released_at, 'la reserva vuelve');
        $decision = DB::table('outreach_decisions')->where('kind', 'cancel')->sole();
        $this->assertSame('N4', $decision->level);
        $this->assertContains('sends_disabled_in_code', json_decode((string) $decision->reasons, true));
        $this->assertNotContains('channel_not_live', json_decode((string) $decision->reasons, true), 'la voz está declarada viva (L9-81): implementada no es abierta');
        $this->assertNadaPedido();
    }

    public function test_sin_consentimiento_de_voz_niega_n1_antes_de_llegar(): void
    {
        $this->runFlags('live', ['outreach.voice.enabled' => true]);
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $m = $this->v9Member(consent: false);
        $t = $this->v9VoiceJob($vivo, $m, 'claimed')['job'];

        (new OutreachDispatcher)->process((int) $t->id, now());
        $this->assertSame(['canceled', 'no_consent_for_purpose_channel'], [DB::table('outreach_jobs')->where('id', $t->id)->value('status'), DB::table('outreach_jobs')->where('id', $t->id)->value('cancel_reason')]);
        $this->assertSame('N1', DB::table('outreach_decisions')->where('kind', 'cancel')->value('level'), 'el primer nivel que niega decide: la ley y el consentimiento');
        $this->assertNadaPedido();
    }

    public function test_el_canal_declarado_se_niega_con_la_constante_cerrada_y_no_pide_nada(): void
    {
        $this->assertTrue(OutreachSendGate::channelLive(VoiceStrategyChannel::NAME), 'el lead declaró la voz (L9-81, Enmienda 17 del H8)');
        $this->assertFalse(OutreachSendGate::sendsEnabledInCode());
        $this->runFlags('live', ['outreach.voice.enabled' => true]);
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $m = $this->v9Member();
        $p = $this->v9VoiceJob($vivo, $m, 'dispatching');
        $live = (new OutreachPublishedStrategies)->liveVersion($vivo['version'], $vivo['part']);

        $intento = (new VoiceStrategyChannel)->attempt($p['job'], $p['enrollment'], $live, now()->toDateTimeImmutable());
        $this->assertSame([ChannelAttempt::FAILED_TO_START, 'sends_disabled_in_code', []], [$intento->status, $intento->detailCode, $intento->refs]);
        $this->assertNadaPedido();
    }

    public function test_cada_motivo_de_la_puerta_es_failed_to_start_con_ese_detalle_y_sin_reintento(): void
    {
        foreach (OutboundPolicy::REASONS as $motivo) {
            $intento = VoiceStrategyChannel::fromGate(['http' => 409, 'body' => ['ok' => false, 'code' => 'outbound_denied', 'reasons' => [$motivo]], 'callId' => null]);
            $this->assertSame([ChannelAttempt::FAILED_TO_START, $motivo, false], [$intento->status, $intento->detailCode, $intento->wentOut()], $motivo);
        }
        $this->assertCount(28, OutboundPolicy::REASONS, 'los 28 motivos del H8 (E16-21)');
        // Varios: el primero del orden cerrado (lo más de raíz).
        $this->assertSame('calls_disabled_in_code', VoiceStrategyChannel::fromGate(['http' => 409, 'body' => ['reasons' => ['calls_disabled_in_code', 'no_voice_consent', 'outside_hours']], 'callId' => null])->detailCode);
        // Aceptada: `started` con la referencia de la llamada; la misma llamada del trabajo (200), igual.
        foreach ([202, 200] as $http) {
            $ok = VoiceStrategyChannel::fromGate(['http' => $http, 'body' => ['ok' => true], 'callId' => 41]);
            $this->assertSame([ChannelAttempt::STARTED, null, ['voice_call_id' => 41]], [$ok->status, $ok->detailCode, $ok->refs], (string) $http);
        }
        // Aceptada sin referencia: no se puede probar qué fila es → `ambiguous`, nunca `failed` (que liberaría la reserva).
        $this->assertSame([ChannelAttempt::AMBIGUOUS, 'voice_call_ref_missing'], [VoiceStrategyChannel::fromGate(['http' => 202, 'callId' => null])->status, VoiceStrategyChannel::fromGate(['http' => 202, 'callId' => null])->detailCode]);
        // Las negativas que no son de la política: su código.
        $this->assertSame('job_uuid_invalid', VoiceStrategyChannel::fromGate(['http' => 422, 'body' => ['code' => 'strategy_request_invalid', 'reasons' => ['job_uuid_invalid']], 'callId' => null])->detailCode);
        $this->assertSame('outbound_race', VoiceStrategyChannel::fromGate(['http' => 409, 'body' => ['code' => 'outbound_race'], 'callId' => null])->detailCode);
        $this->assertSame('client_request_reused', VoiceStrategyChannel::fromGate(['http' => 409, 'body' => ['code' => 'client_request_reused'], 'callId' => null])->detailCode);
        // Lo que no tiene forma de código no pasa como detalle.
        $this->assertSame('voice_gate_denied', VoiceStrategyChannel::fromGate(['http' => 500, 'body' => ['reasons' => ['Texto Libre'], 'code' => 'x y'], 'callId' => null])->detailCode);
        $this->assertSame('voice_gate_denied', VoiceStrategyChannel::fromGate([])->detailCode);
    }

    public function test_el_despachador_asienta_la_referencia_o_libera_la_reserva_con_el_detalle(): void
    {
        $this->runFlags('live', ['outreach.voice.enabled' => true]);
        $vivo = $this->v9VoiceStrategy('PUBLISHED');
        $asentar = new ReflectionMethod(OutreachDispatcher::class, 'settle');
        $despachador = new OutreachDispatcher;

        // 202 → `started` con la referencia de la llamada (una vez); sin resultado todavía: lo pondrá el normalizador.
        $m = $this->v9Member();
        $p = $this->v9VoiceJob($vivo, $m, 'dispatching');
        $llamada = $this->v9StrategyCall((string) $p['job']->uuid, $m['contact'], $m['e164'], 'requested');
        $this->assertSame('started', $asentar->invoke($despachador, $p['job'], $p['enrollment'], $vivo['strategyUuid'], VoiceStrategyChannel::fromGate(['http' => 202, 'callId' => $llamada]), now()->toDateTimeImmutable()));
        $j = DB::table('outreach_jobs')->where('id', $p['job']->id)->first();
        $this->assertSame(['started', $llamada, null], [$j->status, (int) $j->voice_call_id, $j->budget_released_at]);
        $this->assertSame(0, DB::table('outreach_outcomes')->where('job_id', $p['job']->id)->count());

        // Una negativa de la política → `failed_to_start`, la reserva liberada y `failed` con el motivo como detalle; nada se reintenta.
        $otra = $this->v9Member(phone: '3001239010');
        $q = $this->v9VoiceJob($vivo, $otra, 'dispatching', ['idempotency_key' => hash('sha256', 'v9-otra')]);
        $this->assertSame('failed', $asentar->invoke($despachador, $q['job'], $q['enrollment'], $vivo['strategyUuid'],
            VoiceStrategyChannel::fromGate(['http' => 409, 'body' => ['reasons' => ['outside_hours']], 'callId' => null]), now()->toDateTimeImmutable()));
        $k = DB::table('outreach_jobs')->where('id', $q['job']->id)->first();
        $this->assertSame('failed_to_start', $k->status);
        $this->assertNull($k->voice_call_id);
        $this->assertNotNull($k->budget_released_at, 'la reserva vuelve');
        $resultado = DB::table('outreach_outcomes')->where('job_id', $q['job']->id)->sole();
        $this->assertSame(['failed', 'system', 'outside_hours', 'voice_call'], [$resultado->outcome, $resultado->source, $resultado->detail_code, $resultado->channel]);
        $this->assertSame(2, DB::table('outreach_jobs')->count(), 'ningún reintento automático');
    }
}
