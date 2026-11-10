<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceEvidence;
use App\Services\UltronStudio\StudioVoiceEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * La EVIDENCIA de una publicación de voz (contrato H8, D8-19 [P-16]; Enmienda
 * 4, E4-2; Enmienda 13, E13-3): `VoiceEvidence::forPublication(int $seq,
 * string $part, DateTimeInterface $since)` con exactamente siete claves,
 * enteros ≥ 0 (la séptima, `missingPostCall`, la última), y la prueba de
 * INTEGRACIÓN con el Studio: `StudioVoiceEvidence::measure()` (S8-b, S8-d)
 * devuelve los recuentos (no null) con una publicación sembrada. Los recuentos
 * ricos van a `summary()` y a `GET admin/voice/metrics`. Sin red.
 *
 * E13-3 (REV8 M-3): `calls` solo cuenta las llamadas selladas con su
 * post-llamada PROCESADA (`guard_verdict`); `missingPostCall`, las que deberían
 * tenerla y no la tienen (ni `guard_verdict` ni `call_initiation_failure`, y
 * fuera de `busy`, `no_answer` y `failed`, que nunca conectaron).
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

    public function test_siete_claves_enteras_y_cada_recuento_cuenta_lo_suyo(): void
    {
        $pub = $this->publishVoice();
        $desde = now()->subMinute()->startOfSecond();
        $this->assertSame(['calls' => 0, 'guardViolations' => 0, 'sealMismatches' => 0, 'driftEvents' => 0, 'unknownProviderCalls' => 0, 'budgetBreaches' => 0, 'missingPostCall' => 0],
            VoiceEvidence::forPublication($pub['seq'], 'canary', $desde));

        // Antes de la ventana: no cuenta, ni sus incidentes ni los del sistema de entonces.
        $vieja = $this->voiceCall('inbound', 'completed', ['created_at' => now()->subHours(2), 'remote' => '+573000000040']);
        $this->travelTo($desde->copy()->subHour());
        VoiceEvents::record('seal_mismatch', (int) $vieja->id, 'elevenlabs', 'CA_ev_old', ['outcome' => 'agent_differs']);
        VoiceEvents::record('unknown_provider_call', null, 'twilio', 'CA_ev_old2', ['outcome' => 'post_call_transcription']);
        VoiceBrake::open('agent_drift', 'drift:agent_viejo_0001', []);
        VoiceBrake::open('cost_cap', 'cost_cap:2026-11-07', []);
        $this->travelBack();
        // En el borde exacto de la ventana: cuenta, pero sin su post-llamada procesada no es evidencia (E13-3): falta.
        $this->voiceCall('inbound', 'completed', ['created_at' => $desde, 'remote' => '+573000000048']);
        // Cuenta como llamada: la sellada con su post-llamada procesada (su `guard_verdict`, abajo).
        $completada = $this->voiceCall('inbound', 'completed', ['remote' => '+573000000041']);
        // Ni llamada ni post-llamada que falte: una saliente que nunca conectó (E13-3).
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
        $this->assertSame(['calls' => 1, 'guardViolations' => 1, 'sealMismatches' => 1, 'driftEvents' => 1, 'unknownProviderCalls' => 1, 'budgetBreaches' => 2, 'missingPostCall' => 1], $medida);
        foreach ($medida as $valor) {
            $this->assertIsInt($valor);
        }
        // `$since` acota también los incidentes.
        $this->assertSame(0, VoiceEvidence::forPublication($pub['seq'], 'canary', now()->addMinute())['driftEvents']);
    }

    public function test_el_studio_la_lee_y_no_es_null_con_una_publicacion_sembrada(): void
    {
        $pub = $this->publishVoice();
        $llamada = $this->voiceCall('inbound', 'completed');
        VoiceEvents::record('guard_verdict', (int) $llamada->id, 'elevenlabs', 'conv_ev_studio', ['outcome' => 'clean']);
        $desde = Carbon::now()->subMinute()->toImmutable();

        $medida = StudioVoiceEvidence::measure($pub['seq'], 'canary', $desde);
        $this->assertIsArray($medida, 'la telefonía da la evidencia: promover ya no se niega por evidence_unavailable');
        $this->assertSame(VoiceEvidence::forPublication($pub['seq'], 'canary', $desde), $medida);
        $this->assertSame(1, $medida['calls']);
        $this->assertSame($medida, StudioVoiceEvidence::validMeasures(VoiceEvidence::forPublication($pub['seq'], 'canary', $desde)));
        Http::assertNothingSent();
    }

    /**
     * E13-3 (REV8 M-3, el escenario tal cual): el webhook post-llamada está caído
     * (la clave rotada sin registrar el secreto nuevo), así que llaman tres
     * canarios, la iniciación sella las tres y la conciliación las cierra desde
     * Twilio, pero ninguna pasa por la guarda posterior. Antes contaban como
     * `calls = 3` con todos los ceros: con el freno suelto y el laboratorio
     * aprobado, promover pasaba sin una sola transcripción inspeccionada.
     */
    public function test_e13_3_sin_post_llamada_procesada_no_es_evidencia_y_cuenta_como_que_falta(): void
    {
        $pub = $this->publishVoice();
        $desde = now()->subMinute()->startOfSecond();
        foreach (['+573000000051', '+573000000052', '+573000000053'] as $remoto) {
            $this->voiceCall('inbound', 'completed', ['remote' => $remoto]);
        }
        $medida = VoiceEvidence::forPublication($pub['seq'], 'canary', $desde);
        $this->assertSame([0, 3], [$medida['calls'], $medida['missingPostCall']], 'selladas sin guard_verdict: ni son evidencia ni pasan en silencio');
        foreach (['guardViolations', 'sealMismatches', 'driftEvents', 'unknownProviderCalls', 'budgetBreaches'] as $cero) {
            $this->assertSame(0, $medida[$cero], "{$cero}: ningún incidente, que es justo lo que no prueba nada");
        }
        // El Studio, con todo lo demás en verde, ya no promueve: faltan llamadas inspeccionadas.
        $registro = [
            'publication' => ['uuid' => (string) Str::uuid(), 'seq' => $pub['seq'], 'since' => now()->subHours(25)->utc()->format('Y-m-d\TH:i:s\Z')],
            'specHash' => $pub['specHash'], 'measures' => $medida, 'chainVerified' => true, 'brake' => StudioVoiceEvidence::BRAKE_RELEASED,
            'labVerdict' => ['latest' => 'approved'], 'labGate' => 'required', 'thresholds' => ['minHours' => 24, 'minCalls' => 3],
        ];
        $evaluacion = StudioVoiceEvidence::evaluate($registro, now());
        $this->assertFalse($evaluacion['passes']);
        $this->assertContains('too_few_calls', $evaluacion['failing']);

        // Con su post-llamada procesada (limpia o con violación), sí son evidencia y ya no faltan.
        foreach (VoiceCall::query()->where('voice_publication_seq', $pub['seq'])->orderBy('id')->pluck('id') as $i => $id) {
            VoiceEvents::record('guard_verdict', (int) $id, 'elevenlabs', "conv_e13_{$i}", ['outcome' => 'clean']);
        }
        $this->assertSame([3, 0], [($m = VoiceEvidence::forPublication($pub['seq'], 'canary', $desde))['calls'], $m['missingPostCall']]);
    }

    /** E13-3: qué estado cuenta como post-llamada que falta y cuál no (uno por caso). */
    public function test_e13_3_la_post_llamada_que_falta_estado_a_estado(): void
    {
        $pub = $this->publishVoice();
        $desde = now()->subMinute()->startOfSecond();
        $medir = fn (): array => array_intersect_key(VoiceEvidence::forPublication($pub['seq'], 'canary', $desde), ['calls' => 1, 'missingPostCall' => 1]);

        // Nunca conectaron: sin transcripción que esperar (también si las cierra la conciliación, sin webhook).
        $this->voiceCall('outbound', 'busy', ['remote' => '+573000000061']);
        $this->voiceCall('outbound', 'no_answer', ['remote' => '+573000000062']);
        $this->voiceCall('outbound', 'failed', ['remote' => '+573000000063']);
        $this->assertSame(['calls' => 0, 'missingPostCall' => 0], $medir());

        // En curso o sin resolver: falta hasta que llegue (bloquea promover mientras tanto, en el sentido seguro).
        $viva = $this->voiceCall('inbound', 'active', ['remote' => '+573000000064']);
        $marcando = $this->voiceCall('outbound', 'dialing', ['remote' => '+573000000065']);
        $this->voiceCall('outbound', 'ambiguous', ['remote' => '+573000000066']);
        $this->assertSame(['calls' => 0, 'missingPostCall' => 3], $medir());
        VoiceEvents::record('guard_verdict', (int) $viva->id, 'elevenlabs', 'conv_e13_viva', ['outcome' => 'clean']);
        VoiceEvents::record('guard_verdict', (int) $marcando->id, 'elevenlabs', 'conv_e13_marcando', ['outcome' => 'violation']);
        $this->assertSame(['calls' => 2, 'missingPostCall' => 1], $medir());

        // Cortada a mano: si el proveedor dice que no llegó a conectar, no falta nada; si no dice nada, falta.
        $cortadaAntes = $this->voiceCall('outbound', 'hung_up', ['remote' => '+573000000067']);
        VoiceEvents::record('call_initiation_failure', (int) $cortadaAntes->id, 'elevenlabs', 'conv_e13_cortada', ['outcome' => 'no-answer']);
        $this->voiceCall('outbound', 'hung_up', ['remote' => '+573000000068']);
        $this->assertSame(['calls' => 2, 'missingPostCall' => 2], $medir());

        // Lo que no es evidencia no cuenta en ningún lado (E6-2), aunque no tenga post-llamada.
        $this->voiceCall('inbound', 'denied', ['remote' => '+573000000069']);
        $this->voiceCall('outbound', 'requested', ['remote' => '+573000000070']);
        $this->voiceCall('outbound', 'canceled', ['remote' => '+573000000071']);
        $this->voiceCall('outbound', 'failed_to_start', ['remote' => '+573000000072']);
        $this->assertSame(['calls' => 2, 'missingPostCall' => 2], $medir());
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
