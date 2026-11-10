<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * §13.1 #23, el lado de la EJECUCIÓN (contrato H9, D9-8 etapa 6, L9-25 y la
 * tabla `waitsForRelease`/`effect` de informe-run.md §2.8, fila «Cadena:
 * pausa»; REV9 M-3, TEST9-c): pausar una estrategia EN LA CADENA cancela sus
 * trabajos pendientes en la pasada siguiente del despachador (ya reclamados:
 * `strategy_paused`, decisión `cancel` N5), no toca lo que ya salió, y la
 * INSCRIPCIÓN NO SE CANCELA: espera 15 min, la planificación la sigue
 * haciendo esperar mientras dure la pausa y, reanudada la estrategia, vuelve
 * a planificarse (otro intento, otra fila). El lado del gobierno (la entrada
 * `pause` firmada y los estados de las versiones) es de S9
 * (`UltronStudioStrategyRollbackTest::test_23`).
 *
 * La pausa no es un freno: la reclamación (6.2) solo mira los frenos, así que
 * el trabajo pausado SÍ se reclama y es 6.3 (la cadena) quien lo cancela. Con
 * el monitor encendido entre la pausa y el despacho, la deriva echaría antes
 * el freno de la estrategia y el trabajo esperaría `planned`
 * (`OutreachStopAndGateTest`): las dos vías acaban en «nada sale».
 */
class H9PauseCancelTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    /** Martes 17-11-2026, 10:00 de Bogotá: día hábil dentro de la ventana legal. */
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

    /** Reanuda la estrategia en la cadena: una entrada `resume` firmada, sin versión (`usspub_shape_chk`), con el estado activo otra vez. */
    private function resumeStrategy(array $live): int
    {
        $parte = ['contentHash' => (string) $live['v']->content_hash, 'version' => (string) $live['v']->uuid];
        $estado = $live['part'] === 'rest' ? ['canary' => null, 'rest' => $parte, 'status' => 'active'] : ['canary' => $parte, 'rest' => null, 'status' => 'active'];

        return $this->signedPublication($live['strategy'], null, 'resume', $estado, 'Se reanuda la estrategia');
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

    public function test_23_pausar_cancela_el_trabajo_ya_reclamado_strategy_paused_no_toca_lo_enviado_y_la_inscripcion_espera_hasta_la_reanudacion(): void
    {
        $w = $this->twoPlannedJobs();
        // Antes de la pausa sale UN trabajo (el más antiguo): lo ya enviado.
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), 1, true)['results']);
        $enviado = DB::table('outreach_jobs')->where('status', 'simulated')->first();
        $pendiente = DB::table('outreach_jobs')->where('status', 'planned')->first();
        $this->assertNotNull($enviado);
        $this->assertNotNull($pendiente);

        $this->pauseStrategy($w['live']);
        $this->assertSame(['global' => false, 'channels' => [], 'strategies' => []], (new OutreachStop)->openScopes(), 'la pausa vive en la cadena, no en los frenos');

        // La pasada siguiente: el pendiente se reclama (6.2 no mira la cadena) y 6.3 lo cancela con su motivo, sin llamar al canal.
        $r = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame(1, $r['claimed']);
        $this->assertSame(['canceled' => 1], $r['results']);
        $cancelado = DB::table('outreach_jobs')->where('id', $pendiente->id)->first();
        $this->assertSame('canceled', $cancelado->status);
        $this->assertSame('strategy_paused', $cancelado->cancel_reason);
        $this->assertNotNull($cancelado->claimed_at, 'se reclamó antes de cancelarse');
        $this->assertNull($cancelado->budget_released_at, 'en sombra no había reserva que liberar');
        $decision = DB::table('outreach_decisions')->where('kind', 'cancel')->first();
        $this->assertNotNull($decision);
        $this->assertSame('N5', $decision->level);
        $this->assertSame(['strategy_paused'], json_decode((string) $decision->reasons, true));
        $this->assertSame((int) $pendiente->id, (int) $decision->job_id);
        $traza = json_decode((string) $decision->trace_json, true);
        $this->assertIsList($traza);
        $this->assertSame('dispatch', $traza[0]['gate']['recheck']);
        // La INSCRIPCIÓN no se cancela: espera 15 min (fila «Cadena: pausa» de §2.8), sin resultado ni salida.
        $e = DB::table('outreach_enrollments')->where('id', $pendiente->enrollment_id)->first();
        $this->assertSame('active', $e->status);
        $this->assertNull($e->exit_reason);
        $this->assertNull($e->ended_at);
        $this->assertSame('2026-11-17 15:30:00', (string) $e->next_due_at);
        $this->assertSame(0, (int) $e->touches, 'no salió: no cuenta como contacto');
        $this->assertSame(0, DB::table('outreach_outcomes')->where('enrollment_id', $e->id)->count(), 'cancelar por la cadena no es un resultado');
        // Lo ya enviado no se toca.
        $intacto = DB::table('outreach_jobs')->where('id', $enviado->id)->first();
        $this->assertSame('simulated', $intacto->status);
        $this->assertNull($intacto->cancel_reason);
        $this->assertSame('not_attempted', DB::table('outreach_outcomes')->where('job_id', $enviado->id)->value('outcome'));
        $this->assertSame('active', DB::table('outreach_enrollments')->where('id', $enviado->enrollment_id)->value('status'));
        $this->assertSame(1, (int) DB::table('outreach_enrollments')->where('id', $enviado->enrollment_id)->value('touches'));

        // Mientras siga pausada: la planificación la hace esperar otros 15 min (sin decisión ni trabajo), nada se reclama y nadie nuevo se inscribe.
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:30:00', 'UTC'));
        $planner = new OutreachPlanner;
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['due']);
        $this->assertSame(1, $r['waiting'], json_encode($r));
        $this->assertSame('2026-11-17 15:45:00', (string) DB::table('outreach_enrollments')->where('id', $e->id)->value('next_due_at'));
        $this->assertSame(2, DB::table('outreach_jobs')->count());
        $this->assertSame(1, DB::table('outreach_decisions')->where('kind', 'cancel')->count(), 'esperar no es una decisión');
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed']);
        $this->qualifyingMember(self::utcAt(self::NOW), 4, '3001234569');
        $this->assertSame([], $planner->sweep(now(), true)['strategies'], 'pausada no barre: no nacen inscripciones');
        $this->assertSame(2, DB::table('outreach_enrollments')->count());

        // Reanudada: la inscripción se planifica otra vez (otro intento, otra fila; el cancelado no vuelve) y sale.
        $this->resumeStrategy($w['live']);
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:45:00', 'UTC'));
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['planned'], json_encode($r));
        $nuevo = DB::table('outreach_jobs')->orderByDesc('id')->first();
        $this->assertSame((int) $e->id, (int) $nuevo->enrollment_id);
        $this->assertSame('planned', $nuevo->status);
        $this->assertSame(0, (int) $nuevo->step_index);
        $this->assertSame(2, (int) $nuevo->attempt, 'cada intento es una fila: el cancelado no se reutiliza');
        $this->assertSame(3, DB::table('outreach_jobs')->count());
        $this->assertSame('canceled', DB::table('outreach_jobs')->where('id', $pendiente->id)->value('status'));
        Carbon::setTestNow(Carbon::parse('2026-11-17 16:00:00', 'UTC'));
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $this->assertSame('active', DB::table('outreach_enrollments')->where('id', $e->id)->value('status'));
        $this->assertSame(1, (int) DB::table('outreach_enrollments')->where('id', $e->id)->value('touches'));
    }

    public function test_23_en_vivo_la_cancelacion_por_pausa_libera_las_tres_parcelas_de_la_reserva_y_la_inscripcion_espera(): void
    {
        $p = $this->plannedJobWorld(self::utcAt(self::NOW), 'live');
        $trabajo = $p['job'];
        $uuid = $p['live']['strategyUuid'];
        $this->assertSame(1, (int) $trabajo->reserved_units);
        $reservado = fn (string $scope): int => (int) DB::table('outreach_budget_counters')->where('budget_day', $trabajo->budget_day)->where('mode', 'live')->where('scope', $scope)->value('reserved_units');
        $parcelas = ['channel:assisted', 'strategy:'.$uuid, 'phone:'.$trabajo->phone_ref];
        $this->assertSame([1, 1, 1], array_map($reservado, $parcelas));

        $this->pauseStrategy($p['live']);
        $this->assertSame(['canceled' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $cancelado = DB::table('outreach_jobs')->first();
        $this->assertSame('canceled', $cancelado->status);
        $this->assertSame('strategy_paused', $cancelado->cancel_reason);
        $this->assertNotNull($cancelado->budget_released_at);
        $this->assertSame([0, 0, 0], array_map($reservado, $parcelas), 'las tres parcelas vuelven (canal, estrategia y teléfono)');
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame('active', $e->status);
        $this->assertSame('2026-11-17 15:30:00', (string) $e->next_due_at);
        $this->assertSame(0, (int) $e->touches);
        $this->assertSame(0, DB::table('outreach_outcomes')->count());
    }

    public function test_23_en_la_planificacion_la_pausa_y_el_freno_de_la_estrategia_hacen_esperar_sin_cancelar(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(self::NOW, (string) DB::table('outreach_enrollments')->value('next_due_at'), 'toca ya');

        // Pausada antes de planificar: la inscripción (sin trabajo todavía) espera 15 min; ni decisión ni trabajo.
        $this->pauseStrategy($vivo);
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['due']);
        $this->assertSame(1, $r['waiting'], json_encode($r));
        $this->assertSame(0, $r['planned'] + $r['canceled'] + $r['denied']);
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame('active', $e->status);
        $this->assertSame('2026-11-17 15:15:00', (string) $e->next_due_at);
        $this->assertSame(0, DB::table('outreach_jobs')->count());
        $this->assertSame(0, DB::table('outreach_decisions')->where('kind', '<>', 'enroll')->count(), 'esperar no es una decisión');
        $this->qualifyingMember(self::utcAt(self::NOW), 6, '3001234568');
        $this->assertSame([], $planner->sweep(now(), true)['strategies'], 'pausada no barre');
        $this->assertSame(1, DB::table('outreach_enrollments')->count());

        // El freno de la estrategia (N2, `waitsForRelease`) hace lo mismo en su ámbito: reanudada pero frenada, espera; el barrido la salta.
        $this->resumeStrategy($vivo);
        (new OutreachStop)->open('strategy', $vivo['strategyUuid'], 'manual', 'Freno de la prueba', $this->threePeople()['ana'], (string) Str::uuid());
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:15:00', 'UTC'));
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['waiting'], json_encode($r));
        $this->assertSame('2026-11-17 15:30:00', (string) DB::table('outreach_enrollments')->value('next_due_at'));
        $this->assertSame(['skipped' => 'outreach_brake_strategy'], $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']]);
        $this->assertSame(0, DB::table('outreach_jobs')->count());
        $this->assertSame('active', DB::table('outreach_enrollments')->value('status'));
    }
}
