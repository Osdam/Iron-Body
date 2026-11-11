<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachReplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * I-1 de la auditoría de cierre B (aprobada por el lead el 11-11-2026;
 * contrato H9, D9-11.6 y D9-7 N3), sobre las clases REALES de la ejecución:
 * en SOMBRA, «solo canario» (`governance.canaryOnly`, que declara toda
 * versión del Studio por R-12 y también la línea base) es un AVISO de N3: se
 * evalúa la audiencia entera, se anota quién quedaría fuera y la escalera
 * sigue con N4–N7. Fuera de la sombra (el asistido corre en `live`) niega
 * exactamente igual que antes. El despacho repite la escalera con la misma
 * regla y `outreach:replay` reproduce la decisión de sombra.
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z), con la fuente de
 * eventos del motor encendida (L9-26), como el resto de B9-run.
 */
class H9ShadowCanaryOnlyTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Un socio que califica en todo MENOS en el canario: prospecto de WhatsApp autorizado y conversación, sin número canario. @return array{user: int, member: int, key: string, lead: int} */
    private function nonCanaryMember(int $days, string $phone): array
    {
        $r = $this->expiringMember(self::utcAt(self::NOW), $days);
        $lead = $this->lead(['member_id' => $r['member'], 'phone' => $phone, 'consent_status' => 'granted']);
        $this->conversation($lead);

        return $r + ['lead' => $lead];
    }

    /** La salida y la entrada de la escalera del canal asistido de una decisión guardada. @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private static function ladderOf(object $decision): array
    {
        $p = json_decode((string) $decision->trace_json, true)[0]['policy']['assisted:whatsapp'];

        return [$p['output'], $p['input']];
    }

    public function test_en_sombra_quien_no_es_canario_se_evalua_entero_se_simula_y_queda_el_aviso_de_n3_y_n5(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $fuera = $this->nonCanaryMember(5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);

        $r = $planner->planDue(now());
        $this->assertSame([1, 1, 0], [$r['due'], $r['planned'], $r['denied']], json_encode($r));
        $plan = DB::table('outreach_decisions')->where('kind', 'plan')->first();
        $this->assertNotNull($plan, 'la persona fuera del canario tiene su decisión `plan` en sombra');
        $this->assertSame($fuera['key'], $plan->subject_key);
        [$salida, $entrada] = self::ladderOf($plan);
        $this->assertTrue($entrada['studio']['canaryOnly'], 'la línea base del Studio declara «solo canario»');
        $this->assertSame(['canary', false], [$entrada['strategy']['part'], $entrada['strategy']['inCanaryScope']], 'la parte canaria, la persona fuera');
        $this->assertSame(['allow', null, []], [$salida['verdict'], $salida['level'], $salida['reasons']]);
        $this->assertSame([['level' => 'N3', 'reason' => 'canary_only_declared'], ['level' => 'N5', 'reason' => 'canary_scope_mismatch']], $salida['warnings'], 'se anota quién quedaría fuera (would_scope)');
        $this->assertSame(array_fill(0, 7, 'pass'), array_column($salida['levels'], 'status'), 'N4–N7 se evalúan: ninguno queda `not_evaluated`');
        $this->assertSame(['planned', 'simulated', 'shadow'], [DB::table('outreach_jobs')->value('status'), DB::table('outreach_jobs')->value('channel'), DB::table('outreach_jobs')->value('mode')]);
        $this->assertSame(0, DB::table('outreach_decisions')->where('kind', 'deny')->count());

        // El despacho repite la escalera (D9-8 etapa 6) con la misma regla: se simula, no se niega.
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(OutreachPlanner::OFFSET_MINUTES));
        $d = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame([1, ['simulated' => 1]], [$d['claimed'], $d['results']], json_encode($d));
        $this->assertSame('simulated', DB::table('outreach_jobs')->value('status'));

        // La decisión de sombra se reproduce con el núcleo de ahora (D9-12).
        $replay = (new OutreachReplay)->replay((string) $plan->uuid);
        $this->assertSame([OutreachReplay::SAME, (string) $plan->trace_hash], [$replay['result'], $replay['replayedHash']]);
    }

    public function test_en_vivo_el_asistido_sigue_negando_en_n3_a_quien_no_es_canario(): void
    {
        $this->runFlags('live');
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $fuera = $this->nonCanaryMember(5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);

        $r = $planner->planDue(now());
        $this->assertSame([1, 0, 1], [$r['due'], $r['planned'], $r['denied']], json_encode($r));
        $this->assertSame(0, DB::table('outreach_jobs')->count(), 'ningún trabajo para quien no es canario');
        $negada = DB::table('outreach_decisions')->where('kind', 'deny')->first();
        $this->assertSame([$fuera['key'], 'N3', ['canary_only_declared']], [$negada->subject_key, $negada->level, json_decode((string) $negada->reasons, true)]);
        [$salida] = self::ladderOf($negada);
        $this->assertSame(['deny', [], false], [$salida['verdict'], $salida['warnings'], $salida['waitsForRelease']]);
        $this->assertSame(['pass', 'pass', 'deny', 'not_evaluated', 'not_evaluated', 'not_evaluated', 'not_evaluated'], array_column($salida['levels'], 'status'));
        $this->assertSame('canary_only_declared', DB::table('outreach_outcomes')->where('outcome', 'blocked')->value('reason_code'), 'la negativa permanente deja su `blocked` con el motivo');
    }

    public function test_en_sombra_la_persona_canaria_no_deja_aviso_de_canario(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);

        $this->assertSame(1, $planner->planDue(now())['planned']);
        [$salida, $entrada] = self::ladderOf(DB::table('outreach_decisions')->where('kind', 'plan')->first());
        $this->assertSame([true, true], [$entrada['studio']['canaryOnly'], $entrada['strategy']['inCanaryScope']]);
        $this->assertSame(['allow', []], [$salida['verdict'], $salida['warnings']]);
    }

    public function test_en_sombra_n1_sigue_negando_antes_que_el_aviso_del_canario(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        // Fuera del canario y sin autorización de WhatsApp: decide N1; N3 ni se evalúa.
        $r = $this->expiringMember(self::utcAt(self::NOW), 5);
        $this->conversation($this->lead(['member_id' => $r['member'], 'phone' => '3001234567', 'consent_status' => 'pending']));
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);

        $this->assertSame(1, $planner->planDue(now())['denied']);
        $negada = DB::table('outreach_decisions')->where('kind', 'deny')->first();
        $this->assertSame('N1', $negada->level);
        $this->assertContains('no_consent_for_purpose_channel', json_decode((string) $negada->reasons, true));
        [$salida] = self::ladderOf($negada);
        $this->assertSame([], $salida['warnings'], 'N3 no se evaluó: no hay aviso del canario');
        $this->assertSame(['deny', 'not_evaluated'], [$salida['levels'][0]['status'], $salida['levels'][2]['status']]);
        $this->assertSame(0, DB::table('outreach_jobs')->count());
    }
}
