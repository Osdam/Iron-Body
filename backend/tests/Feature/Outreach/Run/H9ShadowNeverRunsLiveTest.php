<?php

namespace Tests\Feature\Outreach\Run;

use App\Jobs\Outreach\DispatchOutreachJob;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * La invariante que hace seguro I-1 (recomendación R1 de `security`,
 * `informe-security-i1.md`), sobre las clases REALES: un trabajo o una
 * inscripción de SOMBRA nunca se reclama ni se planifica en VIVO. En sombra
 * «solo canario» es un aviso y quien no es canario llega a planificarse como
 * trabajo `simulated`. Al pasar a `live`:
 *  - el despacho no lo reclama, porque solo reclama trabajos del modo actual;
 *  - el planificador no planifica la inscripción de sombra;
 *  - un trabajo ya reclamado en sombra y procesado después se cancela en N2
 *    (`mode_off`) sin tocar ningún canal;
 *  - la pasada en vivo crea su propia inscripción, y N3 la niega
 *    (`canary_only_declared`).
 * Ningún mensaje sale.
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z).
 */
class H9ShadowNeverRunsLiveTest extends TestCase
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

    /** Un socio que califica en todo MENOS en el canario (prospecto autorizado y conversación, sin número canario). */
    private function nonCanaryMember(): void
    {
        $r = $this->expiringMember(self::utcAt(self::NOW), 5);
        $this->conversation($this->lead(['member_id' => $r['member'], 'phone' => '3001234567', 'consent_status' => 'granted']));
    }

    /** En sombra: la estrategia en canario, el socio no canario inscrito y su trabajo `simulated` planificado. */
    private function shadowPlannedJob(): OutreachPlanner
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->nonCanaryMember();
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(1, $planner->planDue(now())['planned'], 'en sombra «solo canario» avisa y se planifica (I-1)');
        $this->assertSame([['shadow', 'simulated', 'planned']], DB::table('outreach_jobs')->get(['mode', 'channel', 'status'])->map(static fn (object $j): array => array_values((array) $j))->all());

        return $planner;
    }

    public function test_un_trabajo_de_sombra_pendiente_no_se_reclama_en_vivo_y_la_inscripcion_en_vivo_la_niega_n3(): void
    {
        $planner = $this->shadowPlannedJob();

        // Se pasa a vivo con el trabajo de sombra todavía `planned`, y ya vencido.
        $this->runFlags('live');
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(OutreachPlanner::OFFSET_MINUTES + 1));
        $d = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(0, $d['claimed'], 'el despacho solo reclama trabajos del modo actual: '.json_encode($d));
        $this->assertSame('planned', DB::table('outreach_jobs')->value('status'), 'el trabajo de sombra sigue intacto');
        $r = $planner->planDue(now());
        $this->assertSame(0, $r['due'], 'la inscripción de sombra no se planifica en vivo: '.json_encode($r));

        // La pasada en vivo crea su PROPIA inscripción, y N3 la niega.
        $planner->sweep(now(), true);
        $r = $planner->planDue(now());
        $this->assertSame([1, 0, 1], [$r['due'], $r['planned'], $r['denied']], json_encode($r));
        $this->assertSame(['live', 'shadow'], DB::table('outreach_enrollments')->orderBy('mode')->pluck('mode')->all(), 'una inscripción por modo');
        $negada = DB::table('outreach_decisions')->where('kind', 'deny')->where('mode', 'live')->first();
        $this->assertSame(['N3', ['canary_only_declared']], [$negada->level, json_decode((string) $negada->reasons, true)]);
        $this->assertSame(0, DB::table('outreach_jobs')->where('mode', 'live')->count());
        $this->assertSame(0, DB::table('marketing_messages')->count(), 'ningún mensaje');
    }

    public function test_un_trabajo_reclamado_en_sombra_y_procesado_ya_en_vivo_se_cancela_sin_tocar_el_canal(): void
    {
        Queue::fake();
        $this->shadowPlannedJob();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(OutreachPlanner::OFFSET_MINUTES + 1));
        // Por la cola: reclamado en sombra, todavía sin procesar.
        $d = (new OutreachDispatcher)->dispatch(now());
        $this->assertSame(1, $d['claimed'], json_encode($d));
        Queue::assertPushed(DispatchOutreachJob::class, 1);
        $id = (int) DB::table('outreach_jobs')->value('id');
        $this->assertSame('claimed', DB::table('outreach_jobs')->value('status'));

        // El trabajador lo procesa DESPUÉS de pasar a vivo: N2 lo cancela por el modo, sin llegar al canal.
        $this->runFlags('live');
        $this->assertSame('canceled', (new OutreachDispatcher)->process($id, now()));
        $this->assertSame(['canceled', 'mode_off', 'simulated'], array_values((array) DB::table('outreach_jobs')->first(['status', 'cancel_reason', 'channel'])));
        $this->assertSame(0, DB::table('outreach_jobs')->where('mode', 'live')->count());
        $this->assertSame(0, DB::table('marketing_messages')->count(), 'ningún mensaje');
    }
}
