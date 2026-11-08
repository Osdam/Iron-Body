<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Twilio\TwilioVoiceCarrier;
use App\Services\Voice\NullTelephonyCarrier;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * La CONCILIACIÓN (contrato H8, D8-21; E1-2; fixture `twilio-webhook-bodies.json`
 * §callsPage): la página de la subcuenta del fixture pasa por el
 * transportista REAL de Twilio (`TwilioVoiceCarrier`, contra la base del
 * simulador con `Http::fake`, solo GET) y se cruza con `voice_calls`.
 *
 * TRADUCCIÓN DECLARADA: el fixture da a las ambiguas su `call_sid` en la fila;
 * DB8 no admite ids en una fila `ambiguous` (`vc_provider_binding_chk`), así
 * que el job deja el SID que conozca en su evento `outbound_ambiguous` y la
 * conciliación lo encuentra ahí.
 */
class VoiceReconcilerTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const ACCOUNT = 'AC8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f8f';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->voiceWorld([
            'voice.twilio.account_sid' => self::ACCOUNT,
            'voice.twilio.api_key_sid' => 'SK'.str_repeat('0', 31).'1',
            'voice.twilio.api_key_secret' => 'secreto-de-la-key-de-prueba',
            'voice.twilio.base_url' => 'http://127.0.0.1:8095',
            'voice.limits.daily_budget_usd' => '1000',
        ]);
    }

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/fixtures/voice/twilio-webhook-bodies.json')), true)['callsPage'][0];
    }

    private function given(array $porSid): void
    {
        foreach ($porSid as $sid => $estado) {
            if ($estado === 'denied') {
                $this->voiceCall('inbound', 'denied', ['provider_call_sid' => $sid, 'remote' => self::STRANGER_PHONE]);

                continue;
            }
            if ($estado === 'ambiguous') {
                $fila = $this->voiceCall('outbound', 'ambiguous', ['remote' => self::phoneFor($sid)]);
                VoiceEvents::record('outbound_ambiguous', (int) $fila->id, VoiceCallEvent::PROVIDER_ELEVENLABS, 'amb:'.$fila->uuid, ['outcome' => 'server_error', 'codes' => ['callSid' => $sid, 'conversationId' => null]]);

                continue;
            }
            $this->voiceCall('outbound', $estado, ['provider_call_sid' => $sid, 'remote' => self::phoneFor($sid)]);
        }
    }

    /** Un móvil de ficción distinto por llamada (una saliente viva por número, `vc_one_live_outbound`). */
    private static function phoneFor(string $sid): string
    {
        return '+5730000'.sprintf('%05d', hexdec(substr($sid, -4)));
    }

    public function test_la_pagina_del_fixture_queda_conciliada_como_dice_el_contrato(): void
    {
        $c = self::fixture();
        $this->given($c['given']['voiceCallsByCallSid']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:8095/2010-04-01/Accounts/'.self::ACCOUNT.'/Calls.json*' => Http::response($c['page'])]);

        $resumen = app(VoiceReconciler::class)->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subDays(2));
        $this->assertSame(['listed', 7, 1, 1, false], [$resumen['carrier'], $resumen['known'], $resumen['legs'], $resumen['unknown'], $resumen['costCap']]);

        foreach ($c['expected']['perCall'] as $sid => $e) {
            if ($e['match'] === 'unknown') {
                $this->assertSame(['unknown_provider_call'], VoiceCallEvent::query()->where('provider_event_key', $sid)->pluck('kind')->all(), $sid);

                continue;
            }
            if ($e['match'] === 'transfer_leg') {
                $madre = VoiceCall::query()->where('provider_call_sid', $e['parentCallSid'])->sole();
                $evento = VoiceCallEvent::query()->where('provider_event_key', 'leg:'.$sid)->sole();
                $this->assertSame(['cost_recorded', $madre->id, $e['costMicroUsd']], [$evento->kind, $evento->call_id, $evento->codes['micros']], $sid);
                $this->assertSame(0, VoiceCall::query()->where('provider_call_sid', $sid)->count(), 'el tramo no tiene fila: el operador no da su número');

                continue;
            }
            $fila = VoiceCall::query()->where('provider_call_sid', $sid)->sole();
            $this->assertSame($e['statusAfter'], $fila->status, $sid);
            $eventos = VoiceCallEvent::query()->where('call_id', $fila->id)->whereIn('kind', ['reconciled', 'cost_recorded'])
                ->where('provider_event_key', 'not like', 'leg:%')->orderBy('id')->pluck('kind')->all();
            $this->assertSame($e['events'], $eventos, $sid);
            $propio = VoiceCallEvent::query()->where('call_id', $fila->id)->where('provider_event_key', 'cost:'.$sid)->first();
            $this->assertSame($e['costMicroUsd'], $propio?->codes['micros'], $sid.' (precio)');
        }
        // La madre suma su precio y el de su tramo (DF-W4).
        $this->assertSame(70500 + 140000, VoiceCall::query()->where('provider_call_sid', 'CA000000000000000000000000a8000001')->sole()->carrier_cost_micros);
        // Solo la desconocida abre el freno.
        $frenos = VoiceBrakeEntry::query()->pluck('reason')->all();
        $this->assertSame([$c['expected']['brakeReason']], $frenos);

        // Otra pasada no duplica nada (idempotente).
        $antes = VoiceCallEvent::query()->count();
        app(VoiceReconciler::class)->run(VoiceAdapters::carrier(), new NullVoiceAgentDeployer, now()->subDays(2));
        $this->assertSame($antes, VoiceCallEvent::query()->count());
        $this->assertSame(1, VoiceBrakeEntry::query()->count());

        $dump = json_encode([VoiceCall::query()->get()->makeVisible('remote_hash')->toArray(), VoiceCallEvent::query()->get()->toArray(), VoiceBrakeEntry::query()->get()->toArray()]);
        foreach ($c['expected']['mustNotPersist'] as $s) {
            $this->assertStringNotContainsString($s, (string) $dump);
        }
        Http::assertSentCount(2);
        Http::assertSent(static fn ($r) => $r->method() === 'GET' && str_starts_with($r->url(), 'http://127.0.0.1:8095/'));
    }

    public function test_sin_credencial_de_la_subcuenta_no_sale_a_la_red_y_lo_dice(): void
    {
        config(['voice.twilio.api_key_secret' => null]);
        Http::fake();
        $this->assertFalse(TwilioVoiceCarrier::configured());
        $resumen = app(VoiceReconciler::class)->run(VoiceAdapters::carrier(), VoiceAdapters::deployer(), now()->subDays(2));
        $this->assertSame('not_configured', $resumen['carrier']);
        Http::assertNothingSent();
    }

    public function test_una_pedida_vieja_que_perdio_su_job_pasa_a_ambigua_y_el_techo_de_gasto_frena(): void
    {
        $vieja = $this->voiceCall('outbound', 'requested');
        $this->travel(16)->minutes();
        config(['voice.limits.daily_budget_usd' => '0.5']);   // 500 000 µUS$ < la reserva de 610 500
        $resumen = app(VoiceReconciler::class)->run(new NullTelephonyCarrier, new NullVoiceAgentDeployer, now()->subDays(2));
        $this->assertSame(1, $resumen['staleRequested']);
        $this->assertSame('ambiguous', VoiceCall::query()->findOrFail($vieja->id)->status);
        $this->assertTrue($resumen['costCap']);
        $this->assertSame(['cost_cap'], VoiceBrakeEntry::query()->pluck('reason')->all());
        $this->assertTrue(VoiceBrake::isOpen());
    }

    public function test_el_comando_resume_sin_numeros_y_sale_con_1_si_hay_una_desconocida(): void
    {
        $c = self::fixture();
        $this->given($c['given']['voiceCallsByCallSid']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['127.0.0.1:8095/*' => Http::response($c['page'])]);
        $codigo = Artisan::call('voice:reconcile', ['--json' => true]);
        $salida = Artisan::output();
        $this->assertSame(1, $codigo, 'una llamada que Laravel no inició');
        $this->assertSame(1, json_decode($salida, true)['unknown']);
        foreach (['+5730', '573000000', '0077'] as $s) {
            $this->assertStringNotContainsString($s, $salida);
        }
    }
}
