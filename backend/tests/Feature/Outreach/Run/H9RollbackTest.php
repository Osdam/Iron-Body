<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\StrategyContent;
use App\Services\Outreach\Facts\CatalogDomains;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachReconciler;
use App\Services\Outreach\Run\OutreachStore;
use App\Services\UltronStudio\StudioCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * §13.1 #25, el lado de la EJECUCIÓN (contrato H9, D9-8 etapa 6, §9.2 nivel 1,
 * L9-25 y la fila «Cadena: versión fuera (`strategy_moved`)» de
 * informe-run.md §2.8; §13.3 «una reversión durante `dispatching`»; REV9 M-3,
 * TEST9-c): revertir deja la versión fuera de la cadena. Un trabajo ya
 * reclamado se cancela `strategy_moved` (decisión `cancel` N5) y SU
 * INSCRIPCIÓN SE CIERRA `canceled` (no espera, a diferencia de la pausa);
 * una inscripción vencida sin trabajo se cancela en la planificación; las
 * abiertas SIN trabajo vivo (las que esperan la ventana con `next_due_at`
 * nulo) las cierra la conciliación (`canceledByChain`, M-4 de REV9, L9-66),
 * que no toca las de una versión solo pausada; lo que ya SALIÓ (el trabajo y
 * su resultado) no se toca; un trabajo EN DESPACHO no se reinterpreta (lo
 * cierra el canal o la conciliación); la cadena solo crece (la reversión es
 * una entrada más y las anteriores quedan intactas); y la versión restaurada arranca con
 * su sello nuevo (otra versión, otra `seq`) sin repetir en el mismo ciclo la
 * ocurrencia que ya se inscribió (una inscripción por ocurrencia, aunque esté
 * cancelada). El lado del gobierno es de S9
 * (`UltronStudioStrategyRollbackTest::test_25`).
 */
class H9RollbackTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    private const LATER = '2026-11-17 15:15:00';

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

    /**
     * Una estrategia viva en sombra, dos socios que califican y sus dos
     * trabajos planificados; el reloj queda en LATER (los dos vencidos).
     *
     * @return array{live: array<string, mixed>, a: array<string, mixed>, b: array<string, mixed>}
     */
    private function twoPlannedJobs(): array
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $a = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $b = $this->qualifyingMember(self::utcAt(self::NOW), 6, '3001234568');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $r = $planner->planDue(now());
        $this->assertSame(2, $r['planned'], json_encode($r));
        Carbon::setTestNow(Carbon::parse(self::LATER, 'UTC'));

        return ['live' => $vivo, 'a' => $a, 'b' => $b];
    }

    /**
     * Otra versión de la MISMA estrategia (aprobada por Beto), publicada en
     * canario con una entrada firmada NUEVA: la «restaurada» con su cursor nuevo.
     *
     * @param  array<string, mixed>  $content
     * @return array{version: int, seq: int}
     */
    private function publishNewVersion(array $live, array $content): array
    {
        $leido = StrategyContent::fromArray($content, (new CatalogDomains)->read());
        $this->assertNotNull($leido['canonical'], json_encode($leido['errors']));
        $v = $this->insertVersion($live['strategy'], [
            'content_json' => $leido['json'],
            'content_hash' => $leido['contentHash'],
            'catalog_version' => OutreachCatalog::VERSION,
            'studio_content_hash' => StudioCatalog::hash(),
        ]);
        $this->approveVersion($v);
        $fila = DB::table('ultron_studio_strategy_versions')->where('id', $v)->first(['uuid', 'content_hash']);
        $seq = $this->signedPublication($live['strategy'], $v, 'publish', ['canary' => ['contentHash' => (string) $fila->content_hash, 'version' => (string) $fila->uuid], 'rest' => null, 'status' => 'active']);
        $this->setVersionStatus($v, 'CANARY');

        return ['version' => $v, 'seq' => $seq];
    }

    public function test_25_revertir_cancela_el_trabajo_reclamado_strategy_moved_cierra_su_inscripcion_no_toca_lo_enviado_y_la_cadena_solo_crece(): void
    {
        $w = $this->twoPlannedJobs();
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), 1, true)['results']);
        $enviado = DB::table('outreach_jobs')->where('status', 'simulated')->first();
        $pendiente = DB::table('outreach_jobs')->where('status', 'planned')->first();
        $cabeza = DB::table('ultron_studio_strategy_publications')->orderByDesc('seq')->first(['seq', 'effective_hash']);
        $entradas = DB::table('ultron_studio_strategy_publications')->count();

        $seq = $this->rollbackStrategy($w['live']);
        // La cadena solo crece: la reversión es una entrada más y la cabeza anterior sigue intacta.
        $this->assertSame((int) $cabeza->seq + 1, $seq);
        $this->assertSame($entradas + 1, DB::table('ultron_studio_strategy_publications')->count());
        $this->assertSame((string) $cabeza->effective_hash, (string) DB::table('ultron_studio_strategy_publications')->where('seq', $cabeza->seq)->value('effective_hash'));
        $this->assertSame('ROLLED_BACK', DB::table('ultron_studio_strategy_versions')->where('id', $w['live']['version'])->value('status'));
        $this->assertNull((new OutreachPublishedStrategies)->liveVersion($w['live']['version'], 'canary'), 'la versión ya no está en la cadena');

        // La pasada siguiente: el pendiente se reclama y se cancela `strategy_moved`, sin llamar al canal.
        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(1, $r['claimed']);
        $this->assertSame(['canceled' => 1], $r['results']);
        $cancelado = DB::table('outreach_jobs')->where('id', $pendiente->id)->first();
        $this->assertSame('canceled', $cancelado->status);
        $this->assertSame('strategy_moved', $cancelado->cancel_reason);
        $decision = DB::table('outreach_decisions')->where('kind', 'cancel')->first();
        $this->assertNotNull($decision);
        $this->assertSame('N5', $decision->level);
        $this->assertSame(['strategy_moved'], json_decode((string) $decision->reasons, true));
        $this->assertSame((int) $pendiente->id, (int) $decision->job_id);
        // Su inscripción SE CIERRA (no espera): `canceled` con el motivo, sin resultado.
        $e = DB::table('outreach_enrollments')->where('id', $pendiente->enrollment_id)->first();
        $this->assertSame('canceled', $e->status);
        $this->assertSame('strategy_moved', $e->exit_reason);
        $this->assertSame(self::LATER, (string) $e->ended_at);
        $this->assertNull($e->next_due_at);
        $this->assertSame(0, DB::table('outreach_outcomes')->where('enrollment_id', $e->id)->count());
        // Lo ENVIADO no se toca: el trabajo y su resultado. Su inscripción (sin trabajo vivo, esperando la ventana con
        // `next_due_at` nulo) la cierra la conciliación (M-4, L9-66), no el despachador.
        $intacto = DB::table('outreach_jobs')->where('id', $enviado->id)->first();
        $this->assertSame('simulated', $intacto->status);
        $this->assertNull($intacto->cancel_reason);
        $this->assertSame('not_attempted', DB::table('outreach_outcomes')->where('job_id', $enviado->id)->value('outcome'));
        $deEnviado = DB::table('outreach_enrollments')->where('id', $enviado->enrollment_id)->first();
        $this->assertSame('active', $deEnviado->status, 'hasta la conciliación');
        $this->assertNull($deEnviado->next_due_at);
        $r = (new OutreachReconciler)->run(now());
        $this->assertSame(1, $r['canceledByChain'], json_encode($r));
        $deEnviado = DB::table('outreach_enrollments')->where('id', $enviado->enrollment_id)->first();
        $this->assertSame('canceled', $deEnviado->status);
        $this->assertSame('strategy_moved', $deEnviado->exit_reason);
        $this->assertSame(1, (int) $deEnviado->touches, 'lo que salió sigue contado');
        $this->assertSame('simulated', DB::table('outreach_jobs')->where('id', $enviado->id)->value('status'));
        $this->assertSame(1, DB::table('outreach_outcomes')->where('job_id', $enviado->id)->count());
        // Y de esa versión nada más nace ni sale.
        $planner = new OutreachPlanner;
        $this->assertSame(0, $planner->planDue(now())['due']);
        $this->assertSame([], $planner->sweep(now(), true)['strategies']);
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed']);
        $this->assertSame(2, DB::table('outreach_jobs')->count());
        $this->assertSame(0, (new OutreachReconciler)->run(now())['canceledByChain'], 'idempotente');
    }

    public function test_25_la_conciliacion_cancela_las_inscripciones_abiertas_sin_trabajo_vivo_de_la_version_revertida_y_no_las_de_una_pausada(): void
    {
        $w = $this->twoPlannedJobs();
        // A salió (sin trabajo vivo, espera la ventana con `next_due_at` nulo); B tiene su trabajo planificado (vivo);
        // D se inscribe después y todavía no se planifica (sin trabajo, `next_due_at` puesto).
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), 1, true)['results']);
        $a = DB::table('outreach_enrollments')->where('id', DB::table('outreach_jobs')->where('status', 'simulated')->value('enrollment_id'))->first();
        $b = DB::table('outreach_enrollments')->where('id', DB::table('outreach_jobs')->where('status', 'planned')->value('enrollment_id'))->first();
        $d = $this->qualifyingMember(self::utcAt(self::NOW), 4, '3001234569');
        $planner = new OutreachPlanner;
        $this->assertSame(1, $planner->sweep(now(), true)['strategies'][$w['live']['strategyUuid']]['enrolled']);
        $dFila = DB::table('outreach_enrollments')->where('subject_key', $d['key'])->first();
        $this->assertNull($a->next_due_at);
        $this->assertSame(self::LATER, (string) $dFila->next_due_at);
        $reconciler = new OutreachReconciler;

        // Solo PAUSADA: la versión sigue viva en la cadena; la conciliación no cancela nada y las tres siguen abiertas.
        $this->pauseStrategy($w['live']);
        $r = $reconciler->run(now());
        $this->assertSame(0, $r['canceledByChain'], json_encode($r));
        $this->assertSame(['active', 'active', 'active'], DB::table('outreach_enrollments')->whereIn('id', [$a->id, $b->id, $dFila->id])->orderBy('id')->pluck('status')->all());

        // REVERTIDA: la conciliación cierra las abiertas SIN trabajo vivo (A, que esperaba la ventana; D, que esperaba su turno)
        // con `strategy_moved`; B, con su trabajo planificado, la cierra el despachador al reclamarlo.
        $this->rollbackStrategy($w['live']);
        $r = $reconciler->run(now());
        $this->assertSame(2, $r['canceledByChain'], json_encode($r));
        $this->assertSame(0, $r['expiredEnrollments']);
        foreach ([$a->id, $dFila->id] as $id) {
            $fila = DB::table('outreach_enrollments')->where('id', $id)->first();
            $this->assertSame('canceled', $fila->status);
            $this->assertSame('strategy_moved', $fila->exit_reason);
            $this->assertSame(self::LATER, (string) $fila->ended_at);
            $this->assertNull($fila->next_due_at);
        }
        $this->assertSame('active', DB::table('outreach_enrollments')->where('id', $b->id)->value('status'), 'con trabajo vivo: la conciliación no la toca');
        $this->assertSame('planned', DB::table('outreach_jobs')->where('enrollment_id', $b->id)->value('status'));
        $this->assertSame('simulated', DB::table('outreach_jobs')->where('enrollment_id', $a->id)->value('status'), 'lo enviado sigue tal cual');
        $this->assertSame(0, DB::table('outreach_outcomes')->whereIn('enrollment_id', [$a->id, $dFila->id])->where('source', 'system')->count(), 'cancelar por la cadena no es un resultado');

        $this->assertSame(['canceled' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $this->assertSame('strategy_moved', DB::table('outreach_jobs')->where('enrollment_id', $b->id)->value('cancel_reason'));
        $this->assertSame('canceled', DB::table('outreach_enrollments')->where('id', $b->id)->value('status'));
        $this->assertSame(0, $reconciler->run(now())['canceledByChain'], 'nada abierto queda de esa versión');
        $this->assertSame(0, DB::table('outreach_enrollments')->whereIn('status', ['active', 'waiting'])->count());
    }

    public function test_25_en_la_planificacion_una_inscripcion_vencida_de_la_version_revertida_se_cancela_strategy_moved(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->rollbackStrategy($vivo);

        $r = $planner->planDue(now());
        $this->assertSame(1, $r['due']);
        $this->assertSame(1, $r['canceled'], json_encode($r));
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame('canceled', $e->status);
        $this->assertSame('strategy_moved', $e->exit_reason);
        $this->assertSame(self::NOW, (string) $e->ended_at);
        $this->assertNull($e->next_due_at);
        $this->assertSame(0, DB::table('outreach_jobs')->count());
        $this->assertSame(0, DB::table('outreach_decisions')->where('kind', '<>', 'enroll')->count());
        $this->assertSame(0, DB::table('outreach_outcomes')->count());
        $this->assertSame(0, $planner->planDue(now())['due'], 'cerrada: no vuelve a tocar');
    }

    public function test_25_la_version_restaurada_arranca_con_su_sello_nuevo_y_no_repite_en_el_ciclo_la_ocurrencia_ya_inscrita(): void
    {
        $w = $this->twoPlannedJobs();
        $this->rollbackStrategy($w['live']);
        $this->assertSame(['canceled' => 2], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $this->assertSame(2, DB::table('outreach_enrollments')->where('status', 'canceled')->where('exit_reason', 'strategy_moved')->count());
        $this->assertSame(2, DB::table('outreach_jobs')->where('cancel_reason', 'strategy_moved')->count());

        // La restaurada: otra versión de la MISMA estrategia con una entrada nueva (otra seq: el cursor nuevo) y una persona nueva.
        $v2 = $this->publishNewVersion($w['live'], $this->validContent($this->assistedTemplate(), ['priority' => 61]));
        $this->assertSame(['canary' => $v2['version']], array_map(static fn (object $o): int => (int) $o->versionId, array_column(array_map(static fn ($l) => ['k' => $l->part, 'o' => $l], (new OutreachPublishedStrategies)->liveStrategies()), 'o', 'k')));
        $c = $this->qualifyingMember(self::utcAt(self::NOW), 4, '3001234569');
        $planner = new OutreachPlanner;
        $r = $planner->sweep(now(), true)['strategies'][$w['live']['strategyUuid']];
        $this->assertSame(3, $r['candidates']);
        $this->assertSame(1, $r['enrolled'], 'solo la persona nueva');
        $this->assertSame(2, $r['already_enrolled'], 'la ocurrencia de A y B ya se inscribió en este ciclo (aunque cancelada): una inscripción por ocurrencia (L9-1)');
        $nueva = DB::table('outreach_enrollments')->where('subject_key', $c['key'])->first();
        $this->assertNotNull($nueva);
        $this->assertSame($w['live']['strategy'], (int) $nueva->strategy_id);
        $this->assertSame($v2['version'], (int) $nueva->strategy_version_id, 'el sello es el de la versión restaurada');
        $this->assertSame($v2['seq'], (int) $nueva->publication_seq, 'con su cursor nuevo');
        $this->assertSame('canary', $nueva->part);
        $this->assertSame(3, DB::table('outreach_enrollments')->count());
        $this->assertSame(1, $planner->planDue(now())['planned']);
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:30:00', 'UTC'));
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $this->assertSame(3, DB::table('outreach_jobs')->count());
        $this->assertSame($v2['version'], (int) DB::table('outreach_jobs')->where('status', 'simulated')->value('strategy_version_id'));
    }

    public function test_13_3_una_reversion_durante_dispatching_no_toca_el_trabajo_en_curso_y_lo_cierra_la_conciliacion(): void
    {
        $p = $this->plannedJobWorld(self::utcAt(self::NOW), 'live');
        $store = new OutreachStore;
        $this->assertTrue($store->transitionJob((int) $p['job']->id, ['planned'], 'claimed', self::utcAt(self::LATER), ['claimed_at' => self::LATER]));
        $this->assertTrue($store->transitionJob((int) $p['job']->id, ['claimed'], 'dispatching', self::utcAt(self::LATER)));

        $this->rollbackStrategy($p['live']);
        $this->assertSame('dispatching', DB::table('outreach_jobs')->value('status'), 'la reversión no toca un trabajo en despacho');
        $this->assertSame('not_claimed', (new OutreachDispatcher)->process((int) $p['job']->id, now()), 'un trabajo en despacho no se reinterpreta');
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed']);
        $this->assertSame(0, DB::table('outreach_decisions')->where('kind', 'cancel')->count());

        // Pasados 10 min sin respuesta del canal, la conciliación lo cierra `ambiguous` con su incidente (la reserva no se pierde,
        // nadie lo reenvía) y, ya sin trabajo vivo, cierra la inscripción de la versión revertida `canceled` (M-4, L9-66).
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:30:00', 'UTC'));
        $r = (new OutreachReconciler)->run(now());
        $this->assertSame(1, $r['ambiguous']);
        $this->assertSame(1, $r['canceledByChain']);
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('ambiguous', $trabajo->status);
        $this->assertNull($trabajo->cancel_reason);
        $this->assertNull($trabajo->budget_released_at, 'no se sabe si salió: la reserva se queda');
        $this->assertSame('stale', DB::table('outreach_outcomes')->where('outcome', 'ambiguous')->value('detail_code'));
        $this->assertSame(1, DB::table('incidents')->where('kind', 'outreach_job_stale')->count());
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame('canceled', $e->status);
        $this->assertSame('strategy_moved', $e->exit_reason);
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed']);
    }
}
