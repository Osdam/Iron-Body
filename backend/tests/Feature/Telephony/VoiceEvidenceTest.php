<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceEvidence;
use App\Services\UltronStudio\StudioVoiceEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * La EVIDENCIA de una publicación de voz (contrato H8, D8-19 [P-16]; Enmienda
 * 4, E4-2): `VoiceEvidence::forPublication(int $seq, string $part,
 * DateTimeInterface $since)` con exactamente seis claves, enteros ≥ 0, y la
 * prueba de INTEGRACIÓN con el Studio: `StudioVoiceEvidence::measure()` (S8-b)
 * devuelve los recuentos (no null) con una publicación sembrada. Los recuentos
 * ricos van a `summary()` y a `GET admin/voice/metrics`. Sin red.
 */
class VoiceEvidenceTest extends TestCase
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

    public function test_seis_claves_enteras_y_cada_recuento_cuenta_lo_suyo(): void
    {
        $pub = $this->publishVoice();
        $desde = now()->subMinute()->startOfSecond();
        $this->assertSame(['calls' => 0, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0],
            VoiceEvidence::forPublication($pub['seq'], 'canary', $desde));

        // Antes de la ventana: no cuenta, ni sus incidentes ni los del sistema de entonces.
        $vieja = $this->voiceCall('inbound', 'completed', ['created_at' => now()->subHours(2), 'remote' => '+573000000040']);
        $this->travelTo($desde->copy()->subHour());
        VoiceEvents::record('seal_mismatch', (int) $vieja->id, 'elevenlabs', 'CA_ev_old', ['outcome' => 'agent_differs']);
        VoiceEvents::record('unknown_provider_call', null, 'twilio', 'CA_ev_old2', ['outcome' => 'post_call_transcription']);
        VoiceBrake::open('agent_drift', 'drift:agent_viejo_0001', []);
        VoiceBrake::open('cost_cap', 'cost_cap:2026-11-07', []);
        $this->travelBack();
        // En el borde exacto de la ventana: cuenta.
        $this->voiceCall('inbound', 'completed', ['created_at' => $desde, 'remote' => '+573000000048']);
        // Cuentan: dos selladas que hablaron con el agente.
        $completada = $this->voiceCall('inbound', 'completed', ['remote' => '+573000000041']);
        $this->voiceCall('outbound', 'no_answer', ['remote' => '+573000000042']);
        // No cuentan como llamadas: la denegada, las salientes que no llegaron al agente y la de otra parte o entrada.
        $denegada = $this->voiceCall('inbound', 'denied', ['remote' => '+573000000043']);
        $this->voiceCall('outbound', 'canceled', ['remote' => '+573000000044']);
        $this->voiceCall('outbound', 'failed_to_start', ['remote' => '+573000000045']);
        $otraParte = $this->voiceCall('inbound', 'completed', ['part' => 'rest', 'remote' => '+573000000046']);
        $this->voiceCall('inbound', 'completed', ['voice_publication_seq' => $pub['seq'] + 1, 'remote' => '+573000000047']);

        // Los incidentes: los de cualquier fila sellada con la entrada (también la denegada) y los del sistema.
        VoiceEvents::record('guard_verdict', (int) $completada->id, 'elevenlabs', 'conv_ev_1', ['outcome' => 'violation', 'codes' => ['violations' => [['turn' => 0, 'code' => 'voice_notice_missing']]]]);
        VoiceEvents::record('guard_verdict', (int) $completada->id, 'elevenlabs', 'conv_ev_2', ['outcome' => 'clean']);
        VoiceEvents::record('seal_mismatch', (int) $denegada->id, 'elevenlabs', 'CA_ev_1', ['outcome' => 'agent_differs']);
        // Los de una fila sellada con OTRA parte no son de esta entrada.
        VoiceEvents::record('guard_verdict', (int) $otraParte->id, 'elevenlabs', 'conv_ev_3', ['outcome' => 'violation']);
        VoiceEvents::record('seal_mismatch', (int) $otraParte->id, 'elevenlabs', 'CA_ev_3', ['outcome' => 'agent_differs']);
        VoiceEvents::record('unknown_provider_call', null, 'twilio', 'CA_ev_2', ['outcome' => 'post_call_transcription']);
        VoiceBrake::open('agent_drift', 'drift:agent_h8fx_canary_0003', []);
        VoiceBrake::open('cost_cap', 'cost_cap:2026-11-08', []);
        VoiceBrake::open('subaccount_alert', 'usage:UT1', []);
        VoiceBrake::open('guard_violation', 'guard:conv_ev_1', []);   // no es de coste ni de deriva

        $medida = VoiceEvidence::forPublication($pub['seq'], 'canary', $desde);
        $this->assertSame(VoiceEvidence::KEYS, array_keys($medida));
        $this->assertSame(['calls' => 3, 'guardViolations' => 1, 'sealMismatches' => 1, 'driftEvents' => 1, 'unknownProviderCalls' => 1, 'budgetBreaches' => 2], $medida);
        foreach ($medida as $valor) {
            $this->assertIsInt($valor);
        }
        // `$since` acota también los incidentes.
        $this->assertSame(0, VoiceEvidence::forPublication($pub['seq'], 'canary', now()->addMinute())['driftEvents']);
    }

    public function test_el_studio_la_lee_y_no_es_null_con_una_publicacion_sembrada(): void
    {
        $pub = $this->publishVoice();
        $this->voiceCall('inbound', 'completed');
        $desde = Carbon::now()->subMinute()->toImmutable();

        $medida = StudioVoiceEvidence::measure($pub['seq'], 'canary', $desde);
        $this->assertIsArray($medida, 'la telefonía da la evidencia: promover ya no se niega por evidence_unavailable');
        $this->assertSame(VoiceEvidence::forPublication($pub['seq'], 'canary', $desde), $medida);
        $this->assertSame(1, $medida['calls']);
        $this->assertSame($medida, StudioVoiceEvidence::validMeasures(VoiceEvidence::forPublication($pub['seq'], 'canary', $desde)));
        Http::assertNothingSent();
    }

    public function test_las_metricas_de_la_telefonia_llevan_el_resumen_de_cada_parte(): void
    {
        $pub = $this->publishVoice();
        $this->voiceCall('inbound', 'completed');
        $r = $this->getJson('/api/admin/voice/metrics', $this->adminAs(Admin::ROLE_SUPER_ADMIN))->assertOk();
        $this->assertSame(['canary', $pub['seq'], 1], [$r->json('publications.canary.part'), $r->json('publications.canary.seq'), $r->json('publications.canary.sealedCalls')]);
        $this->assertSame(['part' => 'rest', 'status' => 'none'], $r->json('publications.rest'));
    }
}
