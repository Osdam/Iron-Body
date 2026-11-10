<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Facts\Candidate;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * El planificador (contrato H9, D9-8 etapas 1–5; §13.1 #1, #2, #3, #14 y la
 * sombra de D9-8): el barrido con la ventana configurable, la ingesta por
 * cursor idempotente por ocurrencia y la planificación en sombra (un trabajo
 * `simulated`, sin reserva), todo en filas.
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z): día hábil, dentro de
 * la ventana legal. El socio de las pruebas tiene un prospecto de WhatsApp con
 * autorización (`granted`), conversación y un número canario activo (la línea
 * base del Studio declara «solo canario», N3), y la fuente de eventos del
 * motor encendida (P9-24, L9-26: sin ella N6 no sabe si pidió una persona).
 */
class OutreachPlannerTest extends TestCase
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

    /** Un socio que vence en `$days` días, con su prospecto autorizado de WhatsApp y su número canario. @return array{user: int, member: int, key: string, lead: int} */
    private function eligibleMember(int $days, string $phone): array
    {
        $r = $this->expiringMember(self::utcAt(self::NOW), $days);
        $lead = $this->lead(['member_id' => $r['member'], 'phone' => $phone, 'consent_status' => 'granted']);
        $this->conversation($lead);
        $this->canary('+57'.$phone, 'whatsapp');

        return $r + ['lead' => $lead];
    }

    // ── #1 · ventana configurable ───────────────────────────────────────────

    public function test_1_la_ventana_de_vencimiento_configurable_inscribe_a_quien_vence_en_n_dias_y_no_en_n_mas_uno(): void
    {
        $this->runFlags('shadow');
        $plantilla = $this->assistedTemplate();
        $siete = $this->liveStrategy($this->validContent($plantilla, ['trigger' => ['event' => 'membership.expiring_window', 'params' => ['daysBefore' => 7]]]), 'CANARY', ['key' => 'renovacion-7d']);
        $enSiete = $this->eligibleMember(7, '3001234567');
        $enOcho = $this->eligibleMember(8, '3001234568');

        $r = (new OutreachPlanner)->sweep(now(), true);
        $this->assertFalse($r['skipped']);
        $c = $r['strategies'][$siete['strategyUuid']];
        $this->assertSame(1, $c['candidates'], 'el prefiltro no trae a quien vence en N + 1');
        $this->assertSame(1, $c['enrolled']);
        $inscripciones = DB::table('outreach_enrollments')->get();
        $this->assertCount(1, $inscripciones);
        $this->assertSame($enSiete['key'], $inscripciones[0]->subject_key);
        $this->assertSame('exp:'.(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('America/Bogota'))->modify('+7 days')->format('Y-m-d'), $inscripciones[0]->cycle_key);
        $this->assertSame('shadow', $inscripciones[0]->mode);
        $this->assertSame('canary', $inscripciones[0]->part);
        $this->assertSame($siete['seq'], (int) $inscripciones[0]->publication_seq);
        $decision = DB::table('outreach_decisions')->where('kind', 'enroll')->first();
        $this->assertNotNull($decision);
        $this->assertSame((int) $inscripciones[0]->id, (int) $decision->enrollment_id);
        $this->assertSame('completed', DB::table('outreach_runs')->where('kind', 'sweep')->value('status'));
        $this->assertStringNotContainsString('3001234567', json_encode(DB::table('outreach_decisions')->get()), 'ni teléfonos ni nombres en las decisiones');

        // Otra versión con otro N cambia la audiencia sin desplegar nada.
        $ocho = $this->liveStrategy($this->validContent($plantilla, ['trigger' => ['event' => 'membership.expiring_window', 'params' => ['daysBefore' => 8]]]), 'CANARY', ['key' => 'renovacion-8d']);
        $r = (new OutreachPlanner)->sweep(now(), true);
        $this->assertSame(2, $r['strategies'][$ocho['strategyUuid']]['candidates'], '[hoy, hoy + 8] incluye a quien vence en 7');
        $this->assertSame(2, $r['strategies'][$ocho['strategyUuid']]['enrolled'], 'otra estrategia es otra inscripción: la de 7 días no la bloquea (la prioridad entre las dos la decide la etapa 3)');
        $this->assertSame(1, $r['strategies'][$siete['strategyUuid']]['already_enrolled']);
        $this->assertSame(3, DB::table('outreach_enrollments')->count());
        $this->assertSame(1, DB::table('outreach_enrollments')->where('subject_key', $enOcho['key'])->count());
        $this->assertSame(2, DB::table('outreach_enrollments')->where('subject_key', $enSiete['key'])->count());
    }

    // ── #2 · vence hoy ──────────────────────────────────────────────────────

    public function test_2_dias_before_cero_inscribe_a_quien_vence_hoy_y_a_nadie_mas_y_no_se_repite_el_mismo_dia(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['trigger' => ['event' => 'membership.expiring_window', 'params' => ['daysBefore' => 0]]]));
        $hoy = $this->eligibleMember(0, '3001234567');
        $this->eligibleMember(1, '3001234568');
        $this->eligibleMember(-1, '3001234569');

        $r = (new OutreachPlanner)->sweep(now(), true);
        $this->assertSame(['candidates' => 1, 'enrolled' => 1], array_intersect_key($r['strategies'][$vivo['strategyUuid']], ['candidates' => 1, 'enrolled' => 1]));
        $this->assertSame([$hoy['key']], DB::table('outreach_enrollments')->pluck('subject_key')->all());

        // La misma pasada otra vez (forzada): la ocurrencia ya está inscrita; sin forzar, la cadencia la salta.
        $r = (new OutreachPlanner)->sweep(now(), true);
        $this->assertSame(1, $r['strategies'][$vivo['strategyUuid']]['already_enrolled']);
        $this->assertSame(0, $r['strategies'][$vivo['strategyUuid']]['enrolled']);
        $this->assertSame(1, DB::table('outreach_enrollments')->count());
        $this->assertSame(['skipped' => 'cadence'], (new OutreachPlanner)->sweep(now())['strategies'][$vivo['strategyUuid']]);
    }

    // ── #3 · vencida hace N días ────────────────────────────────────────────

    public function test_3_la_ventana_de_vencida_respeta_el_intervalo_cerrado_en_los_dos_extremos(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'trigger' => ['event' => 'membership.expired_window', 'params' => ['daysAfterMin' => 3, 'daysAfterMax' => 5]],
            'audience' => ['all' => [['fact' => 'membership.active', 'op' => 'eq', 'value' => false], ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false]]],
            'success' => ['fact' => 'membership.active', 'op' => 'eq', 'value' => true],
        ]));
        $hace = [];
        foreach ([2, 3, 5, 6] as $i => $dias) {
            $hace[$dias] = $this->eligibleMember(-$dias, '300123456'.$i);
        }
        $r = (new OutreachPlanner)->sweep(now(), true);
        $this->assertSame(2, $r['strategies'][$vivo['strategyUuid']]['candidates']);
        $this->assertSame(2, $r['strategies'][$vivo['strategyUuid']]['enrolled']);
        $this->assertEqualsCanonicalizing([$hace[3]['key'], $hace[5]['key']], DB::table('outreach_enrollments')->pluck('subject_key')->all());
    }

    // ── #14 · evento duplicado ──────────────────────────────────────────────

    public function test_14_la_misma_ocurrencia_entregada_dos_veces_produce_una_sola_inscripcion(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'trigger' => ['event' => 'membership_expired'],
            'audience' => ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false],
            'success' => ['fact' => 'membership.days_to_expiry', 'op' => 'gt', 'value' => 30],
        ]));
        $socio = $this->eligibleMember(-1, '3001234567');
        $this->commercialEvent('membership_expired', '2026-11-17 05:00', $socio['member']);
        $planner = new OutreachPlanner;

        $r = $planner->ingest(now());
        $this->assertSame(1, $r['events']);
        $this->assertSame(1, $r['strategies'][$vivo['strategyUuid']]['enrolled']);
        $this->assertSame(1, DB::table('outreach_enrollments')->count());
        $this->assertSame((int) DB::table('commercial_events')->max('id'), (new OutreachStore)->cursor('commercial_events:shadow'));

        // Entregada otra vez (un trabajador que repite la misma ocurrencia; el cursor no puede volver atrás: la base lo impide): ya está inscrita.
        $idEvento = (int) DB::table('commercial_events')->max('id');
        $live = (new OutreachPublishedStrategies)->liveVersion($vivo['version'], 'canary');
        $r = $planner->evaluateCandidates($live, [new Candidate($socio['key'], 'membership_expired', ['commercial_event_id' => $idEvento], $idEvento)], self::utcAt(self::NOW), null, 'shadow');
        $this->assertSame(1, $r['already_enrolled']);
        $this->assertSame(0, $r['enrolled']);
        $this->assertSame(1, DB::table('outreach_enrollments')->count());
        try {
            // En un savepoint: en PostgreSQL una sentencia rechazada envenena la transacción de la prueba si no se deshace.
            DB::transaction(static fn () => DB::table('outreach_cursors')->where('source', 'commercial_events:shadow')->update(['last_id' => 0]));
            $this->fail('El cursor retrocedió.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('solo avanza', $e->getMessage());
        }

        // Un segundo evento de la misma persona: otra ocurrencia, pero la inscripción abierta de esa estrategia manda (una abierta por persona).
        $this->commercialEvent('membership_expired', '2026-11-17 06:00', $socio['member']);
        $r = $planner->ingest(now());
        $this->assertSame(1, $r['strategies'][$vivo['strategyUuid']]['open_enrollment']);
        $this->assertSame(1, DB::table('outreach_enrollments')->count());
        $this->assertSame(2, DB::table('outreach_runs')->where('kind', 'ingest')->where('status', 'completed')->count());
    }

    // ── Sombra: etapas 3–5 ──────────────────────────────────────────────────

    public function test_en_sombra_la_inscripcion_se_planifica_como_un_trabajo_simulado_sin_reserva_y_con_su_decision(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $socio = $this->eligibleMember(5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);

        $r = $planner->planDue(now());
        $this->assertSame(1, $r['due']);
        $this->assertSame(1, $r['planned'], json_encode($r));
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertNotNull($trabajo);
        $this->assertSame('planned', $trabajo->status);
        $this->assertSame('simulated', $trabajo->channel, 'en sombra el canal es el simulado');
        $this->assertNull($trabajo->medium);
        $this->assertSame(0, (int) $trabajo->reserved_units);
        $this->assertSame(0, (int) $trabajo->reserved_micros);
        $this->assertSame('shadow', $trabajo->mode);
        $this->assertSame($socio['key'], $trabajo->subject_key);
        $this->assertNotNull($trabajo->template_version_id, 'la plantilla del canal que habría salido');
        $this->assertNotNull($trabajo->phone_ref, 'L9-30: el seudónimo del teléfono va al crear el trabajo');
        $this->assertSame(64, strlen((string) $trabajo->phone_ref));
        $this->assertGreaterThanOrEqual(self::NOW, (string) $trabajo->not_before, 'nunca antes de ahora');
        $this->assertLessThan('2026-11-17 15:15:00', (string) $trabajo->not_before, 'el desfase determinista cabe en 15 min dentro del hueco');
        $this->assertGreaterThan((string) $trabajo->not_before, (string) $trabajo->expires_at);
        $this->assertSame(0, DB::table('outreach_budget_counters')->count(), 'en sombra no se reserva nada');

        $plan = DB::table('outreach_decisions')->where('kind', 'plan')->first();
        $this->assertNotNull($plan);
        $this->assertSame((int) $trabajo->id, (int) $plan->job_id);
        $traza = json_decode((string) $plan->trace_json, true);
        $this->assertIsList($traza, 'la traza se guarda como lista (CHECK odec_trace_chk)');
        $this->assertSame('assisted:whatsapp', $traza[0]['channel']['channel']);
        $this->assertSame('allow', $traza[0]['policy']['assisted:whatsapp']['output']['verdict']);
        $this->assertSame([], $traza[0]['policy']['assisted:whatsapp']['input']['phone']['contacts']);
        $this->assertNotSame([], $traza[0]['policy']['assisted:whatsapp']['input']['phone']['refs'], 'L9-33: el teléfono siempre va a la escalera');
        $this->assertSame(hash('sha256', (string) $plan->trace_json), $plan->trace_hash);
        $this->assertSame(hash('sha256', (string) $plan->facts_json), $plan->facts_hash);
        $this->assertStringNotContainsString('3001234567', (string) $plan->trace_json);
        $this->assertStringNotContainsString('Socio', (string) $plan->trace_json);

        // La inscripción ya no toca (espera a su trabajo); otra pasada no planifica nada más.
        $this->assertNull(DB::table('outreach_enrollments')->value('next_due_at'));
        $this->assertSame(0, $planner->planDue(now())['due']);
    }

    public function test_con_la_parada_global_echada_no_nacen_inscripciones_y_la_pasada_queda_aborted(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->eligibleMember(5, '3001234567');
        $this->artisan('outreach:stop', ['--all' => true, '--reason' => 'Parada de la prueba'])->assertExitCode(0);

        $planner = new OutreachPlanner;
        $this->assertSame('outreach_brake_global', $planner->sweep(now(), true)['reason']);
        $this->assertSame('outreach_brake_global', $planner->ingest(now())['reason']);
        $this->assertSame('outreach_brake_global', $planner->planDue(now())['reason']);
        $this->assertSame(0, DB::table('outreach_enrollments')->count());
        $this->assertSame(2, DB::table('outreach_runs')->where('status', 'aborted')->where('error_code', 'outreach_brake_global')->count());
    }

    public function test_la_orden_sweep_apagada_no_hace_trabajo_y_encendida_recorre_las_tres_etapas(): void
    {
        $this->artisan('outreach:sweep')->expectsOutputToContain('apagada (master_flag_off)')->assertExitCode(0);
        $this->assertSame(0, DB::table('outreach_runs')->count());
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->eligibleMember(5, '3001234567');
        $this->artisan('outreach:sweep', ['--force' => true])->expectsOutputToContain('inscritos 1')->expectsOutputToContain('trabajos 1')->assertExitCode(0);
        $this->assertSame(1, DB::table('outreach_jobs')->count());
    }
}
