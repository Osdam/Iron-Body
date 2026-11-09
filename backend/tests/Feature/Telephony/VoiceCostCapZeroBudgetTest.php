<?php

namespace Tests\Feature\Telephony;

use App\Models\Voice\VoiceBrakeEntry;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\NullTelephonyCarrier;
use App\Services\Voice\NullVoiceAgentDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * E10-8 (DOC8-1, R8-13): con `VOICE_DAILY_BUDGET_USD=0` (las fases 1 y 2) la
 * primera fila del día echaba `cost_cap` en la siguiente conciliación, también
 * la `denied` de la prueba del secreto de iniciación (O-2): su reserva de un
 * minuto contaba como gasto comprometido.
 *
 * La regla: `cost_cap` solo si lo comprometido del día es MAYOR que el
 * presupuesto Y MAYOR QUE CERO; y una llamada denegada no compromete gasto (no
 * reserva nada: solo cuenta lo real conocido, como las que no tuvieron
 * conversación).
 */
class VoiceCostCapZeroBudgetTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const DAY = '2026-11-04';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->travelTo(Carbon::parse(self::DAY.' 10:00:00', 'America/Bogota'));
        $this->voiceWorld(['voice.limits.daily_budget_usd' => '0', 'voice.inbound.enabled' => false]);
    }

    /** @return array<string, mixed> */
    private function reconcile(): array
    {
        return (new VoiceReconciler)->run(new NullTelephonyCarrier, new NullVoiceAgentDeployer, now()->subHours(48));
    }

    public function test_presupuesto_cero_y_solo_la_denegada_de_la_prueba_o2_no_echa_el_freno(): void
    {
        // La prueba O-2 tal cual: una iniciación con el secreto bueno y un número de prueba, con las entrantes apagadas.
        $cuerpo = (string) json_encode(['caller_id' => '+573000000099', 'agent_id' => self::SEALED_AGENT, 'called_number' => self::VOICE_FROM,
            'call_sid' => 'CA000000000000000000000000a8e10801', 'conversation_id' => 'conv_h8fx_o2_0001']);
        $this->rawPost('/api/webhooks/voice/elevenlabs/initiation', $cuerpo, $this->initiationHeaders())->assertOk()
            ->assertJsonPath('dynamic_variables.caller_authorized', 'no');
        $denegada = VoiceCall::query()->sole();
        $this->assertSame('denied', $denegada->status);
        $this->assertGreaterThan(0, (int) $denegada->reserved_micros, 'la fila sigue llevando su reserva de un minuto (inmutable)');

        $this->assertSame(0, VoiceCostGuard::committedMicros(self::DAY), 'lo denegado no compromete gasto');
        $this->assertFalse($this->reconcile()['costCap']);
        $this->assertFalse(VoiceBrake::isOpen());
        $this->assertSame(0, VoiceBrakeEntry::query()->count());

        // Más denegadas el mismo día, tampoco.
        $this->voiceCall('inbound', 'denied', ['remote' => '+573000000098']);
        $this->assertFalse($this->reconcile()['costCap']);
        $this->assertSame(0, VoiceBrakeEntry::query()->count());
    }

    public function test_presupuesto_cero_y_una_llamada_con_coste_echa_el_freno(): void
    {
        $this->voiceCall('inbound', 'denied', ['remote' => '+573000000098']);
        $this->voiceCall('inbound', 'active', ['remote' => '+573000000097']);   // una autorizada: su reserva compromete gasto

        $this->assertSame(610500, VoiceCostGuard::committedMicros(self::DAY));
        $this->assertTrue($this->reconcile()['costCap']);
        $freno = VoiceBrakeEntry::query()->sole();
        $this->assertSame(['cost_cap', 'cost_cap:'.self::DAY, ['day' => self::DAY, 'committedMicros' => 610500, 'budgetMicros' => 0]],
            [$freno->reason, $freno->open_key, $freno->evidence]);
    }

    public function test_lo_real_conocido_de_una_denegada_si_cuenta(): void
    {
        // Lo denegado no RESERVA; lo que Twilio cobró de verdad (la conciliación) sí es gasto.
        $denegada = $this->voiceCall('inbound', 'denied', ['remote' => '+573000000098']);
        DB::table('voice_calls')->where('id', $denegada->id)->update(['carrier_cost_micros' => 1900]);
        $this->assertSame(1900, VoiceCostGuard::committedMicros(self::DAY));
        $this->assertTrue($this->reconcile()['costCap'], 'con presupuesto 0, cualquier gasto real lo supera');

        config(['voice.limits.daily_budget_usd' => '0.01']);   // 10 000 µUS$: ya cabe
        $this->assertSame(1900, VoiceCostGuard::committedMicros(self::DAY));
    }

    public function test_el_resto_de_la_regla_de_lo_comprometido_no_cambia(): void
    {
        $this->voiceCall('outbound', 'canceled', ['remote' => '+573000000081']);         // 0: no salió nada
        $this->voiceCall('outbound', 'failed_to_start', ['remote' => '+573000000082']);  // 0
        $ocupada = $this->voiceCall('outbound', 'busy', ['remote' => '+573000000083']);  // lo real
        DB::table('voice_calls')->where('id', $ocupada->id)->update(['carrier_cost_micros' => 300]);
        $this->voiceCall('outbound', 'requested', ['remote' => '+573000000084']);        // la reserva
        $completada = $this->voiceCall('outbound', 'completed', ['remote' => '+573000000085']);
        DB::table('voice_calls')->where('id', $completada->id)->update(['carrier_cost_micros' => 700000]);   // lo real, mayor que la reserva

        $this->assertSame(300 + 610500 + 700000, VoiceCostGuard::committedMicros(self::DAY));
    }

    public function test_la_regla_en_php_de_la_conciliacion_en_seco_es_la_del_sql(): void
    {
        // E10-9: la conciliación en seco recalcula lo comprometido con contribution(); tiene que dar lo mismo que el SQL, estado a estado.
        $n = 60;
        foreach (['requested', 'ambiguous', 'canceled', 'failed_to_start', 'dialing', 'active', 'completed', 'busy', 'no_answer', 'failed', 'hung_up'] as $estado) {
            foreach ([0, 300000, 700000] as $real) {
                $fila = $this->voiceCall('outbound', $estado, ['remote' => '+5730000000'.$n, 'budget_day' => Carbon::parse('2027-01-01')->addDays($n++)->toDateString()]);
                DB::table('voice_calls')->where('id', $fila->id)->update(['carrier_cost_micros' => $real]);
            }
        }
        foreach (['active', 'denied'] as $estado) {
            foreach ([0, 300000, 700000] as $real) {
                $fila = $this->voiceCall('inbound', $estado, ['remote' => '+5730000000'.$n, 'budget_day' => Carbon::parse('2027-01-01')->addDays($n++)->toDateString()]);
                DB::table('voice_calls')->where('id', $fila->id)->update(['carrier_cost_micros' => $real]);
            }
        }
        foreach (VoiceCall::query()->get() as $f) {
            $this->assertSame(VoiceCostGuard::committedMicros((string) $f->budget_day),
                VoiceCostGuard::contribution((string) $f->status, (int) $f->reserved_micros, (int) $f->provider_cost_micros + (int) $f->carrier_cost_micros),
                "{$f->direction} {$f->status} con {$f->carrier_cost_micros} µUS$ reales");
        }
    }
}
