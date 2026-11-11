<?php

namespace Tests\Feature\Outreach\B9Cierre;

use App\Services\Outreach\Facts\SubjectTimeline;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachReconciler;
use App\Services\Outreach\Run\OutreachReplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Cierre de B9 · REV9 m-9 (L9-69, L9-73): las cancelaciones del CONCILIADOR
 * (`cancelOrphanedByChain`) y del PLANIFICADOR (`cancelEnrollment`) dejan su
 * decisión `cancel` —como ya hacía el despachador—, así que la persona no
 * desaparece de su historial (D9-8 etapa 6, L9-45) sin explicación: nivel,
 * motivo, la inscripción, la pasada del conciliador, y la decisión se puede
 * volver a evaluar (`outreach:replay`). Una sola por cancelación (repetir la
 * pasada no escribe otra).
 */
class CancelDecisionTimelineTest extends TestCase
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
        $this->runFlags('shadow');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array<string, int|string|null>> las decisiones del historial de la persona */
    private function timelineDecisions(string $subjectKey): array
    {
        return array_values(array_filter((new SubjectTimeline)->forSubject($subjectKey)['entries'], static fn (array $e): bool => $e['kind'] === 'decision'));
    }

    public function test_la_cancelacion_del_planificador_deja_su_decision_cancel_en_el_historial(): void
    {
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->rollbackStrategy($vivo);

        $this->assertSame(1, $planner->planDue(now())['canceled']);

        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame(['canceled', 'strategy_moved'], [$e->status, $e->exit_reason]);
        $d = DB::table('outreach_decisions')->where('kind', 'cancel')->sole();
        $this->assertSame(['N5', ['strategy_moved'], (int) $e->id, null, null], [$d->level, json_decode((string) $d->reasons, true), (int) $d->enrollment_id, $d->job_id, $d->run_id]);
        $this->assertSame([(string) $e->subject_key, (string) $e->subject_ref], [(string) $d->subject_key, (string) $d->subject_ref]);
        $historial = $this->timelineDecisions($socio['key']);
        $this->assertSame(['cancel', 'strategy_moved', 'N5'], [$historial[0]['result'], $historial[0]['reason'], $historial[0]['level']], 'lo primero que ve el historial: la cancelación');
        $this->assertSame(OutreachReplay::SAME, (new OutreachReplay)->replay((string) $d->uuid)['result'], 'la traza se vuelve a evaluar igual');
        $this->assertSame(0, $planner->planDue(now())['due'], 'cerrada');
        $this->assertSame(1, DB::table('outreach_decisions')->where('kind', 'cancel')->count());
    }

    public function test_la_persona_que_ya_no_se_resuelve_se_cancela_con_su_decision_n1(): void
    {
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $e = $this->insertEnrollment($vivo, ['mode' => 'shadow', 'subject_key' => 'm:8009', 'member_id' => 8009, 'next_due_at' => self::NOW, 'window_ends_at' => '2026-11-30 15:00:00']);

        $this->assertSame(1, (new OutreachPlanner)->planDue(now())['canceled']);

        $this->assertSame(['canceled', 'unknown_subject'], [DB::table('outreach_enrollments')->where('id', $e)->value('status'), DB::table('outreach_enrollments')->where('id', $e)->value('exit_reason')]);
        $d = DB::table('outreach_decisions')->where('kind', 'cancel')->sole();
        $this->assertSame(['N1', ['unknown_subject'], $e], [$d->level, json_decode((string) $d->reasons, true), (int) $d->enrollment_id]);
    }

    public function test_la_cancelacion_del_conciliador_deja_su_decision_cancel_con_su_pasada_y_una_sola_vez(): void
    {
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(1, $planner->planDue(now())['planned']);
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:15:00', 'UTC'));
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $this->assertNull(DB::table('outreach_enrollments')->value('next_due_at'), 'espera su ventana: el planificador no la mira');
        $this->rollbackStrategy($vivo);

        $r = (new OutreachReconciler)->run(now());

        $this->assertSame(1, $r['canceledByChain'], json_encode($r));
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame(['canceled', 'strategy_moved'], [$e->status, $e->exit_reason]);
        $d = DB::table('outreach_decisions')->where('kind', 'cancel')->sole();
        $this->assertSame(['N5', ['strategy_moved'], (int) $e->id, null, $r['run']], [$d->level, json_decode((string) $d->reasons, true), (int) $d->enrollment_id, $d->job_id, (int) $d->run_id]);
        $historial = $this->timelineDecisions($socio['key']);
        $this->assertSame(['cancel', 'strategy_moved'], [$historial[0]['result'], $historial[0]['reason']]);
        $this->assertSame(OutreachReplay::SAME, (new OutreachReplay)->replay((string) $d->uuid)['result'], 'la traza se vuelve a evaluar igual');

        $this->assertSame(0, (new OutreachReconciler)->run(now())['canceledByChain'], 'idempotente');
        $this->assertSame(1, DB::table('outreach_decisions')->where('kind', 'cancel')->count(), 'una sola decisión por cancelación');
    }
}
