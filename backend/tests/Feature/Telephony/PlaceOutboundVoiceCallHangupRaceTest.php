<?php

namespace Tests\Feature\Telephony;

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceHangup;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * E10-5 (SEC8-b M-3): GANA EL CORTE. El job no tiene cerrojo mientras espera a
 * ElevenLabs (hasta 20 s), así que el corte de una saliente `requested` y la
 * respuesta del proveedor se cruzan en dos órdenes, y los dos se reproducen
 * aquí tal cual, sobre la misma fila:
 *  1. El corte llega primero (`requested` → `canceled`) y el proveedor la
 *     inicia igual. Antes: la fila quedaba `canceled` sin ids, el evento sin el
 *     `callSid`, nadie podía colgarla y la conciliación la daba por
 *     desconocida (freno). Ahora: los ids en el evento, la llamada colgada EN
 *     EL ACTO por el transportista real (Twilio sobre `Http::fake`) y la
 *     conciliación la reconoce.
 *  2. El job marca primero (`dialing`) entre la lectura de la fila y el corte.
 *     Antes: el corte respondía `not_live` y la llamada seguía. Ahora: se
 *     corta como una llamada viva (`hung_up`).
 * Si Twilio no deja colgarla, la conciliación la ve viva y echa el freno (el
 * comportamiento de antes, en el sentido seguro).
 */
class PlaceOutboundVoiceCallHangupRaceTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const ACCOUNT = 'AC8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f';

    private const SIM = 'http://127.0.0.1:8095';

    private const SID = 'CA000000000000000000000000a8e50001';

    private Admin $pablo;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->voiceWorld([
            'voice.twilio.account_sid' => self::ACCOUNT,
            'voice.twilio.api_key_sid' => 'SK'.str_repeat('0', 31).'1',
            'voice.twilio.api_key_secret' => 'secreto-de-la-key-de-prueba',
            'voice.twilio.base_url' => self::SIM,
            'voice.limits.daily_budget_usd' => '1000',
        ]);
        $this->pablo = $this->person(Admin::ROLE_SUPER_ADMIN, 'Pablo Corta');
    }

    /** Twilio: colgar responde `$colgar` y la lista de la subcuenta enseña `$sid` en `$estado` con su precio. */
    private function twilio(int $colgar, string $sid = self::SID, string $estado = 'completed', ?string $precio = '-0.03770'): void
    {
        $pagina = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/twilio-webhook-bodies.json')), true)['callsPage'][0]['page'];
        $pagina['calls'] = [array_merge($pagina['calls'][0], ['sid' => $sid, 'status' => $estado, 'price' => $precio, 'duration' => '3'])];
        $pagina['end'] = 1;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '127.0.0.1:8095/2010-04-01/Accounts/'.self::ACCOUNT.'/Calls/CA*' => Http::response($colgar === 200 ? ['sid' => $sid, 'status' => 'completed'] : ['code' => 20500], $colgar),
            '127.0.0.1:8095/2010-04-01/Accounts/'.self::ACCOUNT.'/Calls.json*' => Http::response($pagina),
        ]);
    }

    private function record(VoiceCall $llamada, OutboundCallResult $resultado): string
    {
        return (new ReflectionMethod(PlaceOutboundVoiceCall::class, 'record'))->invoke(new PlaceOutboundVoiceCall((int) $llamada->id), $llamada, $resultado);
    }

    private static function hungUp(string $sid): \Closure
    {
        return static fn (Request $r): bool => $r->method() === 'POST' && $r->url() === self::SIM.'/2010-04-01/Accounts/'.self::ACCOUNT."/Calls/{$sid}.json" && $r['Status'] === 'completed';
    }

    /** @return array<string, mixed> */
    private function reconcile(): array
    {
        return app(VoiceReconciler::class)->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subHours(48));
    }

    public function test_el_corte_llega_mientras_el_job_espera_y_el_proveedor_la_inicia_igual_se_cuelga_en_el_acto(): void
    {
        $this->twilio(200);
        $llamada = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000051']);

        // El job ya pidió la llamada y espera a ElevenLabs (sin cerrojo): la persona pulsa «colgar».
        $corte = VoiceHangup::hangup(VoiceCall::query()->findOrFail($llamada->id), VoiceAdapters::carrier(), $this->pablo);
        $this->assertSame(['canceled', null, 'canceled'], [$corte['result'], $corte['carrier'], $corte['status']]);
        Http::assertNothingSent();

        // ElevenLabs responde: la llamada se inició igual.
        $estado = $this->record($llamada, new OutboundCallResult('started', null, 'conv_h8fx_m3_0001', self::SID, 200));

        $this->assertSame('canceled', $estado, 'gana el corte: la fila no vuelve a la vida');
        $fila = VoiceCall::query()->findOrFail($llamada->id);
        $this->assertSame(['canceled', 'hangup', null, null], [$fila->status, $fila->policy_reason, $fila->provider_call_sid, $fila->provider_conversation_id]);
        $iniciada = VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'outbound_started')->sole();
        $this->assertSame(['reason' => null, 'http' => 200, 'conversationId' => 'conv_h8fx_m3_0001', 'callSid' => self::SID, 'afterCancel' => true], $iniciada->codes, 'los ids, en el evento');
        Http::assertSent(self::hungUp(self::SID));
        Http::assertSentCount(1);
        $cortes = VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'hangup_requested')->orderBy('id')->get();
        $this->assertSame([['canceled_before_dial', 'admin'], ['requested', 'system']], $cortes->map(static fn ($e): array => [$e->outcome, $e->actor_type])->all());
        $this->assertSame(['carrierStatus' => 'completed', 'http' => 200, 'errorCode' => null, 'afterCancel' => true], $cortes[1]->codes);

        // La conciliación la reconoce: ni desconocida ni freno; su precio cuenta.
        $resumen = $this->reconcile();
        $this->assertSame([1, 0], [$resumen['known'], $resumen['unknown']]);
        $this->assertSame(0, VoiceCallEvent::query()->where('kind', 'unknown_provider_call')->count());
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame(['reconciled', 'cost_recorded'], VoiceCallEvent::query()->where('call_id', $llamada->id)->whereIn('kind', ['reconciled', 'cost_recorded'])->orderBy('id')->pluck('kind')->all());
        $this->assertSame(['canceled', 37700], [VoiceCall::query()->findOrFail($llamada->id)->status, VoiceCall::query()->findOrFail($llamada->id)->carrier_cost_micros]);
        $this->assertSame(37700, VoiceCostGuard::committedMicros(VoiceCostGuard::budgetDay(now())), 'lo que salió de verdad compromete gasto, aunque la fila diga cancelada');
    }

    public function test_el_job_marca_entre_la_lectura_y_el_corte_y_el_corte_cuelga_la_viva(): void
    {
        $this->twilio(200);
        $llamada = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000052']);
        $leida = VoiceCall::query()->findOrFail($llamada->id);   // lo que leyó el CRM: `requested`

        $this->assertSame('dialing', $this->record($llamada, new OutboundCallResult('started', null, 'conv_h8fx_m3_0002', self::SID, 200)));
        $corte = VoiceHangup::hangup($leida, VoiceAdapters::carrier(), $this->pablo);

        $this->assertSame(['hung_up', 'requested', 'hung_up'], [$corte['result'], $corte['carrier'], $corte['status']]);
        Http::assertSent(self::hungUp(self::SID));
        $this->assertSame(['emergency_hangup', self::SID], [VoiceCall::query()->findOrFail($llamada->id)->termination_reason, VoiceCall::query()->findOrFail($llamada->id)->provider_call_sid]);
        $this->assertSame(['requested'], VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'hangup_requested')->pluck('outcome')->all(), 'sin un «canceled_before_dial» que no pasó');
        $this->assertSame(['reason' => null, 'http' => 200], VoiceCallEvent::query()->where('kind', 'outbound_started')->sole()->codes, 'una saliente normal: el evento de siempre');
    }

    public function test_si_twilio_no_deja_colgarla_la_conciliacion_la_ve_viva_y_echa_el_freno(): void
    {
        $this->twilio(500, self::SID, 'in-progress', null);
        $llamada = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000053']);
        VoiceHangup::hangup(VoiceCall::query()->findOrFail($llamada->id), VoiceAdapters::carrier(), $this->pablo);

        $this->assertSame('canceled', $this->record($llamada, new OutboundCallResult('started', null, 'conv_h8fx_m3_0003', self::SID, 200)));
        Http::assertSent(self::hungUp(self::SID));
        $this->assertSame('error', VoiceCallEvent::query()->where('call_id', $llamada->id)->where('kind', 'hangup_requested')->where('actor_type', 'system')->sole()->outcome);

        // Viva en Twilio: el corte no surtió efecto → desconocida y freno, como antes del arreglo.
        $this->assertSame([0, 1], [($r = $this->reconcile())['known'], $r['unknown']]);
        $this->assertSame(['unknown_provider_call', 'unknown:'.self::SID], [VoiceBrakeEntry::query()->sole()->reason, VoiceBrakeEntry::query()->sole()->open_key]);

        // Cuando Twilio ya la da por terminada, es conocida (su precio queda en la fila cortada).
        $this->twilio(200, self::SID, 'completed', '-0.03770');
        $this->assertSame([1, 0], [($r = $this->reconcile())['known'], $r['unknown']]);
        $this->assertSame(37700, VoiceCall::query()->findOrFail($llamada->id)->carrier_cost_micros);
    }

    public function test_solo_se_cuelga_lo_que_corto_una_persona_no_lo_que_ya_termino(): void
    {
        // DF-S2: el webhook dejó la saliente en `busy` antes de que el job guardara `dialing`: nada que colgar.
        $this->twilio(200);
        $ocupada = $this->voiceCall('outbound', 'busy', ['remote' => '+573000000056']);
        $this->assertSame('busy', $this->record($ocupada, new OutboundCallResult('started', null, 'conv_h8fx_m3_0006', 'CA000000000000000000000000a8e50006', 200)));
        Http::assertNothingSent();
        $this->assertSame(0, VoiceCallEvent::query()->where('kind', 'hangup_requested')->count());
        $this->assertSame(['reason' => null, 'http' => 200], VoiceCallEvent::query()->where('kind', 'outbound_started')->sole()->codes);
    }

    public function test_una_ambigua_con_sid_sobre_una_cortada_tambien_se_cuelga_y_se_reconoce(): void
    {
        $this->twilio(200);
        $llamada = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000054']);
        VoiceHangup::hangup(VoiceCall::query()->findOrFail($llamada->id), VoiceAdapters::carrier(), $this->pablo);

        $this->assertSame('canceled', $this->record($llamada, new OutboundCallResult('ambiguous', 'server_error', null, self::SID, 502)));
        Http::assertSent(self::hungUp(self::SID));
        $this->assertTrue(VoiceCallEvent::query()->where('kind', 'outbound_ambiguous')->sole()->codes['afterCancel']);
        $this->assertSame([1, 0], [($r = $this->reconcile())['known'], $r['unknown']]);
        $this->assertFalse(VoiceBrake::isOpen());

        // Sin SID no hay nada que colgar: ninguna petición.
        $otra = $this->voiceCall('outbound', 'requested', ['remote' => '+573000000055']);
        VoiceHangup::hangup(VoiceCall::query()->findOrFail($otra->id), VoiceAdapters::carrier(), $this->pablo);
        $antes = count(Http::recorded());
        $this->assertSame('canceled', $this->record($otra, new OutboundCallResult('ambiguous', 'connection', null, null, null)));
        $this->assertCount($antes, Http::recorded());
    }
}
