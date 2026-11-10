<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * §13.1 #6 de PUNTA A PUNTA (contrato H9, D9-3 `lead.followup_due`, D9-7 N1,
 * L9-41; REV9 M-3, TEST9-c): el barrido encuentra los seguimientos
 * AUTORIZADOS vencidos (un mensaje o una llamada pendientes cuya hora llegó)
 * por el único prefiltro, la puerta inscribe a quien está en la audiencia
 * (el prefiltro NO mira el consentimiento: lo niega N1, D9-3) y la escalera
 * decide: con `consent_status = granted` el prospecto se planifica y sale en
 * sombra; sin autorización expresa (`pending` o sin registro) N1 niega
 * `no_consent_for_purpose_channel`; con `denied`, `consent_denied`. Nunca un
 * contacto, ni simulado, sin la autorización. La parte pura está en
 * `H9PolicyLadderN1Test` y los hechos en `LeadAndVoiceFactsTest`; aquí van
 * juntos, con filas reales, por el barrido y el despacho.
 */
class H9LeadFollowupTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    /** Martes 17-11-2026, 10:00 de Bogotá. */
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

    /** Un prospecto de WhatsApp SIN ficha de socio, con su conversación (fase conocida) y su número canario (la línea base del Studio es «solo canario», N3). */
    private function prospect(string $phone, ?string $consent): int
    {
        $lead = $this->lead(['phone' => $phone, 'consent_status' => $consent]);
        $this->conversation($lead, ['commercial_phase' => 'FOLLOW_UP']);
        $this->canary('+57'.$phone, 'whatsapp');

        return $lead;
    }

    private function followup(int $lead, string $type, string $status, string $dueUtc): int
    {
        return (int) DB::table('marketing_followups')->insertGetId([
            'lead_id' => $lead, 'type' => $type, 'status' => $status, 'due_at' => self::utc($dueUtc),
            'created_at' => self::utc('2026-11-10 12:00'), 'updated_at' => self::utc('2026-11-10 12:00'),
        ]);
    }

    /** Una estrategia de seguimiento a prospectos: el disparador sin parámetros, la audiencia por el hecho del seguimiento, el éxito por la fase. @return array<string, mixed> */
    private function followupContent(): array
    {
        return $this->validContent($this->assistedTemplate(), [
            'goal' => 'close_plan',
            'trigger' => ['event' => 'lead.followup_due'],
            'audience' => ['all' => [
                ['fact' => 'subject.is_lead', 'op' => 'eq', 'value' => true],
                ['fact' => 'lead.followup_due', 'op' => 'eq', 'value' => true],
            ]],
            'success' => ['fact' => 'lead.commercial_phase', 'op' => 'eq', 'value' => 'WON'],
        ]);
    }

    public function test_6_el_prospecto_con_seguimiento_vencido_y_autorizacion_expresa_se_inscribe_se_planifica_y_sale_en_sombra(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->followupContent());
        $autorizado = $this->prospect('3001234567', 'granted');
        $vencido = $this->followup($autorizado, 'message', 'pending', '2026-11-17 14:00:00');
        $futuro = $this->prospect('3001234568', 'granted');
        $this->followup($futuro, 'message', 'pending', '2026-11-17 15:00:01');   // todavía no vence
        $hecho = $this->prospect('3001234569', 'granted');
        $this->followup($hecho, 'call', 'done', '2026-11-17 14:00:00');          // ya se hizo
        $tarea = $this->prospect('3001234570', 'granted');
        $this->followup($tarea, 'task', 'pending', '2026-11-17 14:00:00');       // una tarea interna no es un contacto

        $planner = new OutreachPlanner;
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(1, $r['candidates'], 'solo el mensaje o la llamada pendientes cuya hora llegó');
        $this->assertSame(1, $r['enrolled']);
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame(SubjectKey::lead($autorizado), $e->subject_key);
        $this->assertSame($autorizado, (int) $e->lead_id);
        $this->assertNull($e->member_id);
        $this->assertSame('fu:'.$vencido, $e->cycle_key, 'la clave de ciclo es el seguimiento');
        $this->assertSame('commercial', $e->purpose);
        $this->assertSame($vivo['seq'], (int) $e->publication_seq);

        $r = $planner->planDue(now());
        $this->assertSame(1, $r['planned'], json_encode($r));
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame('planned', $trabajo->status);
        $this->assertSame('simulated', $trabajo->channel, 'en sombra el canal es el simulado');
        $this->assertSame(SubjectKey::lead($autorizado), $trabajo->subject_key);
        $this->assertNotNull($trabajo->phone_ref, 'L9-30: el seudónimo del teléfono del prospecto');
        $plan = json_decode((string) DB::table('outreach_decisions')->where('kind', 'plan')->value('trace_json'), true)[0];
        $politica = $plan['policy']['assisted:whatsapp'];
        $this->assertSame('granted', $politica['input']['consent']['whatsapp'], 'la autorización expresa llega a N1');
        $this->assertSame('allow', $politica['output']['verdict']);
        $this->assertSame(['level' => 'N1', 'status' => 'pass'], $politica['output']['levels'][0]);
        $this->assertStringNotContainsString('3001234567', json_encode(DB::table('outreach_decisions')->get()), 'ni teléfonos en las decisiones');

        Carbon::setTestNow(Carbon::parse('2026-11-17 15:15:00', 'UTC'));
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $resultado = DB::table('outreach_outcomes')->first();
        $this->assertSame('not_attempted', $resultado->outcome);
        $this->assertSame('simulated', $resultado->source);
        $this->assertSame(SubjectKey::lead($autorizado), $resultado->subject_key);
        $this->assertSame('active', DB::table('outreach_enrollments')->value('status'));
        $this->assertSame(1, (int) DB::table('outreach_enrollments')->value('touches'));

        // A las 15:15 el seguimiento «futuro» ya venció (su hora llegó: entra); otro seguimiento del prospecto ya inscrito es otra
        // ocurrencia, pero la inscripción abierta de esa estrategia manda (una abierta por persona): nada nuevo nace para él.
        $this->followup($autorizado, 'call', 'pending', '2026-11-17 14:30:00');
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(3, $r['candidates'], 'los dos seguimientos del inscrito y el que acaba de vencer');
        $this->assertSame(1, $r['enrolled']);
        $this->assertSame(1, $r['already_enrolled']);
        $this->assertSame(2, DB::table('outreach_enrollments')->count());
        $this->assertSame(SubjectKey::lead($futuro), DB::table('outreach_enrollments')->orderByDesc('id')->value('subject_key'));
    }

    public function test_6_sin_autorizacion_expresa_n1_niega_no_consent_for_purpose_channel_y_con_denied_consent_denied_sin_ningun_contacto(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->followupContent());
        $pendiente = $this->prospect('3001234571', 'pending');
        $this->followup($pendiente, 'message', 'pending', '2026-11-17 14:00:00');
        $sinRegistro = $this->prospect('3001234572', null);
        $this->followup($sinRegistro, 'call', 'pending', '2026-11-17 14:00:00');
        $denegado = $this->prospect('3001234573', 'denied');
        $this->followup($denegado, 'message', 'pending', '2026-11-17 14:00:00');

        $planner = new OutreachPlanner;
        $r = $planner->sweep(now(), true)['strategies'][$vivo['strategyUuid']];
        $this->assertSame(3, $r['candidates'], 'el prefiltro no mira el consentimiento: lo niega N1 (D9-3)');
        $this->assertSame(3, $r['enrolled'], 'la puerta inscribe: la audiencia se cumple');
        $r = $planner->planDue(now());
        $this->assertSame(3, $r['due']);
        $this->assertSame(3, $r['denied'], json_encode($r));
        $this->assertSame(0, $r['planned']);
        $this->assertSame(0, DB::table('outreach_jobs')->count(), 'ningún contacto, ni simulado');

        $motivos = [];
        foreach (DB::table('outreach_decisions')->where('kind', 'deny')->get() as $d) {
            $this->assertSame('N1', $d->level);
            $motivos[(string) $d->subject_key] = json_decode((string) $d->reasons, true);
        }
        $this->assertSame(['no_consent_for_purpose_channel'], $motivos[SubjectKey::lead($pendiente)]);
        $this->assertSame(['no_consent_for_purpose_channel'], $motivos[SubjectKey::lead($sinRegistro)], 'sin registro no es una autorización');
        $this->assertSame(['consent_denied'], $motivos[SubjectKey::lead($denegado)]);
        // La negativa permanente deja un resultado `blocked` con su motivo y la inscripción espera (`on.blocked`, por defecto `wait`): ningún nivel inferior la levanta.
        $bloqueados = DB::table('outreach_outcomes')->where('outcome', 'blocked')->get();
        $this->assertCount(3, $bloqueados);
        foreach ($bloqueados as $b) {
            $this->assertSame('system', $b->source);
            $this->assertSame('wait', $b->next_action);
        }
        $this->assertSame('no_consent_for_purpose_channel', DB::table('outreach_outcomes')->where('subject_key', SubjectKey::lead($pendiente))->value('reason_code'));
        $this->assertSame('consent_denied', DB::table('outreach_outcomes')->where('subject_key', SubjectKey::lead($denegado))->value('reason_code'));
        $this->assertSame(3, DB::table('outreach_enrollments')->where('status', 'waiting')->count());
        $this->assertSame('2026-11-18 15:00:00', (string) DB::table('outreach_enrollments')->value('next_due_at'), 'se vuelve a mirar como pronto a las 24 h (FollowUpScheduler::BLOCKED_RECHECK_HOURS)');
        $this->assertSame(0, (new OutreachDispatcher)->dispatch(now(), null, true)['claimed']);
    }

    /**
     * Regresión de D-1 (TEST9-c, informe-tester-c.md; L9-65): la promesa de
     * `FollowUpScheduler` («un `blocked` que espera se vuelve a mirar como
     * pronto a las 24 h: una autorización que llegue después sí se aprovecha
     * dentro de la ventana», informe-run.md §3.4) no se cumplía: la
     * inscripción quedaba `waiting` con su `next_due_at` y
     * `OutreachStore::dueEnrollments()` solo leía `active`, así que nadie la
     * volvía a mirar hasta que la ventana vencía. Falló en rojo sobre ese
     * código el 10-11-2026 (`due: 0`); con la corrección (`dueEnrollments()`
     * por `ENROLLMENT_OPEN_STATUSES` y `waiting` → `active` al planificar)
     * fija la conducta contratada.
     */
    public function test_6_una_autorizacion_que_llega_tarde_se_aprovecha_en_la_siguiente_mirada_a_las_24_h(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->followupContent());
        $pendiente = $this->prospect('3001234571', 'pending');
        $this->followup($pendiente, 'message', 'pending', '2026-11-17 14:00:00');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(1, $planner->planDue(now())['denied']);
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame('waiting', $e->status);
        $this->assertSame('2026-11-18 15:00:00', (string) $e->next_due_at);

        // La autorización llega; a las 24 h toca otra vez (dentro de la ventana de 14 días): tiene que planificarse.
        DB::table('marketing_leads')->where('id', $pendiente)->update(['consent_status' => 'granted']);
        Carbon::setTestNow(Carbon::parse('2026-11-18 16:00:00', 'UTC'));
        $r = $planner->planDue(now());
        $this->assertSame(1, $r['due'], 'D-1 (L9-65) ha vuelto: una inscripción `waiting` cuyo `next_due_at` ya pasó no vuelve a tocar ('.json_encode($r).')');
        $this->assertSame(1, $r['planned'], json_encode($r));
        $this->assertSame(SubjectKey::lead($pendiente), DB::table('outreach_jobs')->value('subject_key'));
        $this->assertSame('active', DB::table('outreach_enrollments')->value('status'));
    }

    /**
     * Regresión de D-1, segundo síntoma (T22/N7 en la ejecución; L9-65):
     * «llámame el viernes» del personal (`callback_requested`, transición
     * `wait`) deja la inscripción `waiting` con `next_due_at` en la hora
     * pedida; sin la corrección nadie la volvía a mirar y la persona que pidió
     * la llamada no la recibía nunca (falló en rojo el 10-11-2026).
     */
    public function test_6_la_llamada_pedida_por_el_personal_se_programa_a_su_hora(): void
    {
        $gente = $this->threePeople();
        $p = $this->plannedJobWorld(self::utcAt(self::NOW), 'live');
        $this->assertSame(['started' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);
        $cola = new AssistedQueue;
        $uuid = (string) $p['job']->uuid;
        $cola->claim($gente['ana'], $uuid, (string) Str::uuid());
        // El viernes 20-11 a las 10:00 de Bogotá: más de 48 h después del contacto (N1 no aplaza) y dentro de la ventana.
        $r = $cola->recordOutcome($gente['ana'], $uuid, 'callback_requested', (string) Str::uuid(), '2026-11-20T15:00:00Z');
        $this->assertSame('wait', $r['transition']);
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame('waiting', $e->status);
        $this->assertSame('callback_requested', $e->last_outcome);
        $this->assertSame('2026-11-20 15:00:00', (string) $e->next_due_at, 'toca a la hora pedida');

        Carbon::setTestNow(Carbon::parse('2026-11-20 16:00:00', 'UTC'));
        $r = (new OutreachPlanner)->planDue(now());
        $this->assertSame(1, $r['due'], 'D-1 (L9-65) ha vuelto: la inscripción `waiting` con la llamada pedida no vuelve a tocar ('.json_encode($r).')');
        $this->assertSame(1, $r['planned'], json_encode($r));
        $this->assertSame(2, DB::table('outreach_jobs')->count(), 'la llamada pedida se planifica');
    }
}
