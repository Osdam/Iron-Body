<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutcomeRecorder;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\SubjectSuppressions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Resultados y seguimientos (contrato H9, D9-8 etapas 8 y 9, D9-10; §13.1 #10
 * y #22; T14, T23): las fuentes del 15-11 producen resultados del conjunto
 * cerrado; un duplicado se descarta sin avanzar; un desconocido se ignora con
 * incidente; `opted_out` sale, cancela todo lo pendiente de la persona y la
 * suprime; `no_answer` solo sigue si la regla lo dice; la bandeja asistida
 * reclama una vez y registra con su persona.
 */
class OutreachOutcomesTest extends TestCase
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

    /** Un trabajo asistido en vivo que SALIÓ (bandeja). @return array{live: array<string, mixed>, member: array<string, mixed>, job: object} */
    private function startedAssisted(array $content = []): array
    {
        $p = $this->plannedJobWorld(self::utcAt(self::NOW), 'live', [], $content);
        $this->assertSame(['started' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $p['job'] = DB::table('outreach_jobs')->first();

        return $p;
    }

    // ── #22 · la bandeja ────────────────────────────────────────────────────

    public function test_22_la_bandeja_reclama_una_vez_y_solo_quien_reclamo_registra_el_resultado(): void
    {
        $gente = $this->threePeople();
        $p = $this->startedAssisted();
        $cola = new AssistedQueue;
        $lista = $cola->queue($gente['ana']);
        $this->assertCount(1, $lista);
        $tarea = $lista[0];
        $this->assertSame((string) $p['job']->uuid, $tarea['uuid']);
        $this->assertSame('whatsapp', $tarea['medium']);
        $this->assertNull($tarea['script'], 'el guion solo lo ve quien reclamó (REV9 m-2, L9-63)');
        $this->assertNotEmpty($tarea['reason'], 'el motivo, en frases');
        $this->assertNull($tarea['person'], 'el enlace a la ficha solo lo ve quien reclamó (REV9 m-2, L9-63)');
        $this->assertStringNotContainsString('3001234567', json_encode($lista));
        $this->assertStringNotContainsString('Socio', json_encode($lista));
        $this->assertNull($tarea['claimedBy']);

        $r = $cola->claim($gente['ana'], $tarea['uuid'], (string) Str::uuid());
        $this->assertFalse($r['replayed']);
        $this->assertSame('Ana', $r['job']['claimedBy']['name']);
        $this->assertNotEmpty($r['job']['script'], 'el guion de la plantilla, ya reclamado por Ana');
        $this->assertSame(['kind' => 'member', 'id' => $p['member']['member']], $r['job']['person'], 'el enlace a la ficha: ids internos, nunca el teléfono; solo para quien reclamó');
        $this->assertNull($cola->queue($gente['beto'])[0]['person'] ?? null, 'otra persona no ve la identidad del trabajo reclamado por Ana');
        $this->assertNull($cola->queue($gente['beto'])[0]['script'] ?? null, 'ni su guion');
        $this->assertTrue($cola->claim($gente['ana'], $tarea['uuid'], (string) Str::uuid())['replayed'], 'idempotente para quien reclamó');
        try {
            $cola->claim($gente['beto'], $tarea['uuid'], (string) Str::uuid());
            $this->fail('Otra persona reclamó un trabajo ya reclamado.');
        } catch (OutreachException $e) {
            $this->assertSame('already_claimed', $e->errorCode);
        }
        $this->assertCount(0, $cola->queue($gente['ana'], 'unclaimed'));
        $this->assertCount(1, $cola->queue($gente['ana'], 'mine'));
        $this->assertCount(0, $cola->queue($gente['beto'], 'mine'));
        // Solo quien reclamó registra el resultado.
        try {
            $cola->recordOutcome($gente['beto'], $tarea['uuid'], 'answered', (string) Str::uuid());
            $this->fail('Otra persona registró el resultado.');
        } catch (OutreachException $e) {
            $this->assertSame('not_claimer', $e->errorCode);
        }
        try {
            $cola->recordOutcome($gente['ana'], $tarea['uuid'], 'converted', (string) Str::uuid());
            $this->fail('El personal registró un resultado que no es suyo.');
        } catch (OutreachException $e) {
            $this->assertSame(422, $e->status);
        }
        $peticion = (string) Str::uuid();
        $r = $cola->recordOutcome($gente['ana'], $tarea['uuid'], 'answered', $peticion);
        $this->assertSame(OutcomeRecorder::RESULT_RECORDED, $r['result']);
        $this->assertSame('end_success', $r['transition'], 'on.answered = end_success en el contenido de la prueba');
        $fila = DB::table('outreach_outcomes')->where('source', 'staff')->first();
        $this->assertSame('answered', $fila->outcome);
        $this->assertSame((int) $gente['ana']->id, (int) $fila->recorded_by_admin_id);
        $this->assertSame('assisted', $fila->channel);
        $this->assertSame((int) $p['job']->id, (int) $fila->job_id);
        $this->assertSame('completed', DB::table('outreach_enrollments')->value('status'));
        $this->assertTrue($cola->recordOutcome($gente['ana'], $tarea['uuid'], 'answered', $peticion)['replayed'], 'la misma petición devuelve lo mismo');
        $this->assertSame(1, DB::table('outreach_outcomes')->where('source', 'staff')->count());
        $this->assertCount(0, $cola->queue($gente['ana']), 'con resultado deja la bandeja');
    }

    // ── #10 · opt-out ───────────────────────────────────────────────────────

    public function test_10_opted_out_sale_cancela_todo_lo_pendiente_de_la_persona_y_la_suprime(): void
    {
        $gente = $this->threePeople();
        $p = $this->startedAssisted();
        // Otra estrategia con una inscripción abierta y un trabajo planificado de la misma persona.
        $otra = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['priority' => 20]), 'CANARY', ['key' => 'otra']);
        $inscripcionOtra = $this->insertEnrollment($otra, ['mode' => 'live', 'subject_key' => $p['member']['key'], 'member_id' => $p['member']['member'], 'subject_ref' => DB::table('outreach_enrollments')->value('subject_ref'), 'cycle_key' => 'otro-ciclo']);
        $this->assertSame(2, DB::table('outreach_enrollments')->count());
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $p['job']->uuid, (string) Str::uuid());
        $r = $cola->recordOutcome($gente['ana'], (string) $p['job']->uuid, 'opted_out', (string) Str::uuid());
        $this->assertSame('end_exit', $r['transition']);
        $mia = DB::table('outreach_enrollments')->where('id', '!=', $inscripcionOtra)->first();
        $this->assertSame('opted_out', $mia->status);
        $this->assertSame('opted_out', $mia->exit_reason);
        $this->assertSame('exited', DB::table('outreach_enrollments')->where('id', $inscripcionOtra)->value('status'), 'las demás inscripciones abiertas de la persona se cierran');
        $this->assertSame(0, DB::table('outreach_jobs')->whereIn('status', ['planned', 'claimed', 'dispatching'])->count());
        $this->assertTrue((new SubjectSuppressions)->isSuppressed($p['member']['key'], 'commercial', 'whatsapp'));
        $this->assertFalse((new SubjectSuppressions)->isSuppressed($p['member']['key'], 'commercial', 'voice'), 'por finalidad Y medio');
        $this->assertFalse((new SubjectSuppressions)->isSuppressed($p['member']['key'], 'service', 'whatsapp'));

        // Una estrategia nueva, tres días después (ya sin el tope diario ni las 48 h de N1), no la vuelve a contactar:
        // N2 `global_suppression`, diga lo que diga la estrategia; la inscripción sale.
        Carbon::setTestNow(Carbon::parse('2026-11-20 15:00:00', 'UTC'));
        $nueva = $this->liveStrategy($this->validContent($this->assistedTemplate(), ['priority' => 99]), 'CANARY', ['key' => 'insistente']);
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(1, DB::table('outreach_enrollments')->where('strategy_id', $nueva['strategy'])->count(), 'la puerta inscribe (la supresión es de la escalera)');
        $plan = $planner->planDue(now());
        $this->assertSame(1, $plan['exited'], json_encode($plan));
        $negada = DB::table('outreach_decisions')->where('kind', 'deny')->orderByDesc('id')->first();
        $this->assertSame('N2', $negada->level);
        $this->assertContains('global_suppression', json_decode((string) $negada->reasons, true));
        $this->assertSame(0, DB::table('outreach_jobs')->where('strategy_id', $nueva['strategy'])->count());
        $this->assertSame('global_suppression', DB::table('outreach_enrollments')->where('strategy_id', $nueva['strategy'])->value('exit_reason'));
    }

    // ── T14 · fuentes, duplicados y desconocidos ────────────────────────────

    public function test_t14_cada_fuente_da_un_resultado_cerrado_el_duplicado_se_descarta_y_el_desconocido_se_ignora_con_incidente(): void
    {
        $gente = $this->threePeople();
        $p = $this->startedAssisted(['sequence' => [[
            'id' => 'aviso', 'delay' => ['n' => 0, 'unit' => 'hours', 'from' => 'enrollment'], 'channels' => ['assisted:whatsapp'],
            'templates' => ['assisted:whatsapp' => $this->templateRef()], 'on' => ['no_answer' => 'retry_step'],
        ]]]);
        $e = DB::table('outreach_enrollments')->first();
        $recorder = new OutcomeRecorder;

        // Un código fuera del conjunto cerrado y una fuente que no admite el resultado: se ignoran, con incidente, sin avanzar.
        $this->assertSame(OutcomeRecorder::RESULT_IGNORED, $recorder->record($e, 'thumbs_up', 'staff', 'x:1', now())['result']);
        $this->assertSame(OutcomeRecorder::RESULT_IGNORED, $recorder->record($e, 'converted', 'staff', 'x:2', now())['result'], '`converted` no es del personal');
        $this->assertSame(OutcomeRecorder::RESULT_IGNORED, $recorder->record($e, 'answered', 'carrier_pigeon', 'x:3', now())['result']);
        $this->assertSame(0, DB::table('outreach_outcomes')->count());
        $this->assertSame(3, DB::table('incidents')->where('kind', 'outreach_outcome_ignored')->count(), 'agrupados por clase (fuente + código): tres clases distintas');
        $this->assertSame('active', DB::table('outreach_enrollments')->value('status'));

        // `staff` · no_answer con on.no_answer = retry_step: el mismo paso, intento 2, no antes del siguiente hueco.
        $r = $recorder->record($e, 'no_answer', 'staff', 'staff:1', now(), ['job' => $p['job'], 'recordedBy' => $gente['ana']]);
        $this->assertSame(OutcomeRecorder::RESULT_RECORDED, $r['result']);
        $this->assertSame('retry_step', $r['transition']);
        $this->assertSame(2, $r['next']['attempt']);
        $e2 = DB::table('outreach_enrollments')->first();
        $this->assertSame('active', $e2->status);
        $this->assertSame(0, (int) $e2->step_index);
        $this->assertNotNull($e2->next_due_at, 'vuelve a tocar: el planificador creará el intento 2 (otra fila)');
        $this->assertSame('no_answer', $e2->last_outcome);
        // El mismo resultado de la misma fuente, otra vez: duplicado, nada avanza.
        $dup = $recorder->record($e2, 'no_answer', 'staff', 'staff:1', now(), ['job' => $p['job'], 'recordedBy' => $gente['ana']]);
        $this->assertSame(OutcomeRecorder::RESULT_DUPLICATE, $dup['result']);
        $this->assertSame(1, DB::table('outreach_outcomes')->count());
        $this->assertSame((string) $e2->next_due_at, (string) DB::table('outreach_enrollments')->value('next_due_at'));

        // `system` · failed sin on.failed: sale (end_exit) y la inscripción termina.
        $r = $recorder->record(DB::table('outreach_enrollments')->first(), 'failed', 'system', 'job:'.$p['job']->uuid, now(), ['job' => $p['job'], 'detailCode' => 'provider_timeout']);
        $this->assertSame('end_exit', $r['transition']);
        $this->assertSame('exited', DB::table('outreach_enrollments')->value('status'));
        $this->assertSame('failed', DB::table('outreach_enrollments')->value('exit_reason'));
        // Con la inscripción cerrada, otro resultado se guarda (el libro es de solo inserción) pero no cambia nada.
        $r = $recorder->record(DB::table('outreach_enrollments')->first(), 'answered', 'staff', 'staff:tarde', now(), ['job' => $p['job'], 'recordedBy' => $gente['ana']]);
        $this->assertSame('enrollment_final', $r['next']['reason']);
        $this->assertSame('exited', DB::table('outreach_enrollments')->value('status'));
    }

    public function test_t14_crm_fact_appointment_created_registra_appointment_booked_por_la_ingesta(): void
    {
        $p = $this->startedAssisted();
        $this->commercialEvent('appointment_created', '2026-11-17 15:10', $p['member']['member']);
        $r = (new OutreachPlanner)->ingest(now());
        $this->assertSame(1, $r['outcomes']);
        $fila = DB::table('outreach_outcomes')->where('source', 'crm_fact')->first();
        $this->assertSame('appointment_booked', $fila->outcome);
        $this->assertSame('end_success', $fila->next_action);
        $this->assertNull($fila->job_id, 'un hecho del CRM no viene de un trabajo');
        $this->assertSame('completed', DB::table('outreach_enrollments')->value('status'));
        // Otra vez el mismo evento (el cursor no retrocede; si llegara, la clave de la fuente lo descarta): nada cambia.
        $this->assertSame(1, DB::table('outreach_outcomes')->where('source', 'crm_fact')->count());
    }

    // ── T23 · seguimientos solo si la regla lo permite ──────────────────────

    public function test_t23_no_answer_sin_regla_sale_y_con_next_step_planifica_el_paso_siguiente_sin_acortar_su_retraso(): void
    {
        $gente = $this->threePeople();
        $p = $this->startedAssisted(['sequence' => [
            ['id' => 'aviso', 'delay' => ['n' => 0, 'unit' => 'hours', 'from' => 'enrollment'], 'channels' => ['assisted:whatsapp'], 'templates' => ['assisted:whatsapp' => $this->templateRef()], 'on' => ['no_answer' => 'next_step']],
            ['id' => 'recordatorio', 'delay' => ['n' => 3, 'unit' => 'days', 'from' => 'previous'], 'channels' => ['assisted:whatsapp'], 'templates' => ['assisted:whatsapp' => $this->templateRef()]],
        ]]);
        $cola = new AssistedQueue;
        $cola->claim($gente['ana'], (string) $p['job']->uuid, (string) Str::uuid());
        $r = $cola->recordOutcome($gente['ana'], (string) $p['job']->uuid, 'no_answer', (string) Str::uuid());
        $this->assertSame('next_step', $r['transition']);
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame('active', $e->status);
        $this->assertSame(1, (int) $e->step_index);
        $this->assertGreaterThanOrEqual('2026-11-20 15:15:00', (string) $e->next_due_at, 'nunca antes de los 3 días del paso');
        $this->assertSame(0, (new OutreachPlanner)->planDue(now())['due'], 'todavía no toca');

        // Tres días después: el paso 2, intento 1, otra fila.
        Carbon::setTestNow(Carbon::parse('2026-11-20 16:00:00', 'UTC'));
        $plan = (new OutreachPlanner)->planDue(now());
        $this->assertSame(1, $plan['planned'], json_encode($plan));
        $segundo = DB::table('outreach_jobs')->orderByDesc('id')->first();
        $this->assertSame(1, (int) $segundo->step_index);
        $this->assertSame(1, (int) $segundo->attempt);
        $this->assertSame(2, DB::table('outreach_jobs')->count());

        // En el paso 2 no hay regla para no_answer: sale (end_exit).
        Carbon::setTestNow(Carbon::parse('2026-11-20 16:20:00', 'UTC'));
        $this->assertSame(['started' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $cola->claim($gente['ana'], (string) $segundo->uuid, (string) Str::uuid());
        $r = $cola->recordOutcome($gente['ana'], (string) $segundo->uuid, 'no_answer', (string) Str::uuid());
        $this->assertSame('end_exit', $r['transition']);
        $this->assertSame('exited', DB::table('outreach_enrollments')->value('status'));
        $this->assertSame(2, (int) DB::table('outreach_enrollments')->value('touches'));
    }

    public function test_el_veredicto_needs_review_para_la_secuencia_y_deriva_sin_accion_automatica(): void
    {
        $p = $this->startedAssisted();
        $e = DB::table('outreach_enrollments')->first();
        $r = (new OutcomeRecorder)->record($e, 'ambiguous', 'classifier', 'cls:1', now(), ['job' => $p['job'], 'confidence' => 40]);
        $this->assertSame(OutcomeRecorder::RESULT_NEEDS_REVIEW, $r['result']);
        $this->assertSame('handed_off', DB::table('outreach_enrollments')->value('status'));
        $this->assertSame('needs_review', DB::table('outreach_enrollments')->value('exit_reason'));
        $fila = DB::table('outreach_outcomes')->first();
        $this->assertSame('human_requested', $fila->outcome, 'la derivación, con su detalle');
        $this->assertSame('needs_review', $fila->detail_code);
        $this->assertSame('handoff_human', $fila->next_action);
        $this->assertSame(0, DB::table('outreach_jobs')->whereIn('status', ['planned', 'claimed'])->count(), 'ninguna acción automática');
    }

    /** La referencia `{uuid, hash}` de la plantilla asistida aprobada que ya existe. @return array{uuid: string, hash: string} */
    private function templateRef(): array
    {
        $t = DB::table('ultron_studio_message_templates')->where('channel', 'assisted')->where('status', 'APPROVED')->orderBy('id')->first(['uuid', 'content_hash']);
        if ($t === null) {
            $id = $this->assistedTemplate();
            $t = DB::table('ultron_studio_message_templates')->where('id', $id)->first(['uuid', 'content_hash']);
        }

        return ['uuid' => (string) $t->uuid, 'hash' => (string) $t->content_hash];
    }
}
