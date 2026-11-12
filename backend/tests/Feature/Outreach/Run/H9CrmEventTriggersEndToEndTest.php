<?php

namespace Tests\Feature\Outreach\Run;

use App\Models\MarketingLead;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * I-6 de la auditoría de cierre B (§2 y §4.2): los tres disparadores `crm_fact`
 * que solo pasaban por el camino genérico de la ingesta (probado con
 * `membership_expired`, `OutreachPlannerTest::test_14_…`) recorren aquí el
 * camino ENTERO en sombra, y desde su EMISOR REAL, no desde una fila sembrada:
 *
 *  - `lead_created` y `lead_qualified`: `MarketingLeadCommercialObserver`, al
 *    crear el prospecto o al cambiarle el estado (`interested`, `hot`, `warm`);
 *  - `renewal_window_opened`: `commercial:scan` (`ScanCommercialFacts`), con la
 *    ventana de renovación del motor (10 días).
 *
 * emisor → `commercial_events` → ingesta por cursor (`OutreachPlanner::ingest`)
 * → puerta → inscripción con la ocurrencia `ce:<id del evento>` → escalera →
 * `plan` con trabajo `simulated` (o `deny` N1 sin autorización) → despacho
 * simulado. Sobre las clases reales y la cadena firmada de `RunWorld`.
 *
 * Lo desplegado el 11-11 que se respeta: en sombra «solo canario» es AVISO de
 * N3 (I-1): quien no es canario se simula con los avisos N3 y N5; el canario,
 * sin avisos. Ninguno de los tres es de los seis eventos sin emisor (I-5).
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z), con la fuente de
 * eventos del motor encendida (`commercial.events_enabled`, que además ARMA a
 * los emisores), como el resto de B9-run.
 */
class H9CrmEventTriggersEndToEndTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    private const NON_CANARY_WARNINGS = [['level' => 'N3', 'reason' => 'canary_only_declared'], ['level' => 'N5', 'reason' => 'canary_scope_mismatch']];

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

    // ── Ayudas ──────────────────────────────────────────────────────────────

    /** Un prospecto de WhatsApp creado POR EL MODELO (el observador emite `lead_created`). @param  array<string, mixed>  $o */
    private function newLead(string $phone, string $consent, array $o = []): int
    {
        return (int) MarketingLead::create($o + ['channel' => 'whatsapp', 'phone' => $phone, 'status' => MarketingLead::STATUS_NEW, 'temperature' => 'warm', 'consent_status' => $consent])->id;
    }

    /** Cambia el estado del prospecto POR EL MODELO (el observador emite `lead_qualified` o `human_requested` según el estado). */
    private function setStatus(int $leadId, string $status): void
    {
        MarketingLead::query()->findOrFail($leadId)->update(['status' => $status]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private static function ladderOf(object $decision): array
    {
        $p = json_decode((string) $decision->trace_json, true)[0]['policy']['assisted:whatsapp'];

        return [$p['output'], $p['input']];
    }

    /** @param  array<string, mixed>  $c  @param  list<string>  $keys  @return array<string, mixed> */
    private static function pick(array $c, array $keys = ['candidates', 'evaluated', 'enrolled', 'not_in_audience', 'excluded', 'success_reached', 'insufficient_facts', 'already_enrolled', 'open_enrollment', 'unknown_subject']): array
    {
        $r = [];
        foreach ($keys as $k) {
            $r[$k] = $c[$k] ?? null;
        }

        return $r;
    }

    /** Los eventos registrados, por nombre. @return array<string, int> */
    private static function eventsByName(): array
    {
        $r = DB::table('commercial_events')->groupBy('event')->selectRaw('event, COUNT(*) AS n')->pluck('n', 'event')->map(fn ($n): int => (int) $n)->all();
        ksort($r);

        return $r;
    }

    /** El id del único evento `$event` de esa persona (por prospecto o por socio). */
    private static function eventId(string $event, ?int $leadId = null, ?int $memberId = null): int
    {
        $q = DB::table('commercial_events')->where('event', $event);
        $leadId !== null ? $q->where('marketing_lead_id', $leadId) : $q->where('member_id', $memberId);

        return (int) $q->sole('id')->id;
    }

    private function assertSimulatedPlan(string $subjectKey, array $warnings): void
    {
        $trabajo = DB::table('outreach_jobs')->where('subject_key', $subjectKey)->first();
        $this->assertNotNull($trabajo, "{$subjectKey} tiene su trabajo");
        $this->assertSame(['planned', 'simulated', null, 'shadow', 0, 0], [$trabajo->status, $trabajo->channel, $trabajo->medium, $trabajo->mode, (int) $trabajo->reserved_units, (int) $trabajo->reserved_micros]);
        $this->assertNotNull($trabajo->phone_ref, 'L9-30: el seudónimo del teléfono');
        $plan = DB::table('outreach_decisions')->where('kind', 'plan')->where('subject_key', $subjectKey)->first();
        $this->assertSame((int) $trabajo->id, (int) $plan->job_id);
        [$salida] = self::ladderOf($plan);
        $this->assertSame(['allow', null, [], $warnings], [$salida['verdict'], $salida['level'], $salida['reasons'], $salida['warnings']], $subjectKey);
        $this->assertSame(array_fill(0, 7, 'pass'), array_column($salida['levels'], 'status'));
    }

    private function assertDeniedNoConsent(string $subjectKey): void
    {
        $negada = DB::table('outreach_decisions')->where('kind', 'deny')->where('subject_key', $subjectKey)->first();
        $this->assertNotNull($negada, "{$subjectKey} tiene su `deny`");
        $this->assertSame(['N1', ['no_consent_for_purpose_channel']], [$negada->level, json_decode((string) $negada->reasons, true)]);
        [$salida, $entrada] = self::ladderOf($negada);
        $this->assertSame(['missing', [], ['deny', 'not_evaluated']], [$entrada['consent']['whatsapp'], $salida['warnings'], [$salida['levels'][0]['status'], $salida['levels'][2]['status']]]);
        $this->assertSame(0, DB::table('outreach_jobs')->where('subject_key', $subjectKey)->count(), 'ningún contacto, ni simulado');
        $this->assertSame(['blocked', 'no_consent_for_purpose_channel'], [DB::table('outreach_outcomes')->where('subject_key', $subjectKey)->value('outcome'), DB::table('outreach_outcomes')->where('subject_key', $subjectKey)->value('reason_code')]);
        $this->assertSame('waiting', DB::table('outreach_enrollments')->where('subject_key', $subjectKey)->value('status'));
    }

    // ── lead_created ────────────────────────────────────────────────────────

    /** Bienvenida al prospecto nuevo: prospecto que NO es socio; éxito, ganado. @return array<string, mixed> */
    private function leadCreatedStrategy(): array
    {
        return $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'goal' => 'close_plan',
            'trigger' => ['event' => 'lead_created'],
            'audience' => ['all' => [
                ['fact' => 'subject.is_lead', 'op' => 'eq', 'value' => true],
                ['fact' => 'subject.is_member', 'op' => 'eq', 'value' => false],
            ]],
            'success' => ['fact' => 'lead.commercial_phase', 'op' => 'eq', 'value' => 'WON'],
        ]));
    }

    public function test_lead_created_del_observador_inscribe_al_prospecto_nuevo_lo_simula_y_sin_autorizacion_lo_niega_n1(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->leadCreatedStrategy();
        // Califica: autorización expresa, conversación de WhatsApp, NO canario.
        $nuevo = $this->newLead('3001234561', 'granted');
        $this->conversation($nuevo, ['commercial_phase' => 'NEW_LEAD']);
        // Califica para la puerta (el consentimiento no es de la audiencia: D9-3), pero sin autorización: N1.
        $pendiente = $this->newLead('3001234562', 'pending');
        $this->conversation($pendiente, ['commercial_phase' => 'NEW_LEAD']);
        // `lead_created` de un prospecto que ya es socio: el evento lleva el socio y la persona es `m:` (el socio manda), fuera de la audiencia.
        $socio = $this->expiringMember(self::utcAt(self::NOW), 30);
        $yaSocio = $this->newLead('3001234563', 'granted', ['member_id' => $socio['member']]);
        $this->conversation($yaSocio, ['commercial_phase' => 'NEW_LEAD']);
        // Otro evento (`lead_qualified`) de un prospecto sembrado sin pasar por el modelo: no es de esta estrategia.
        $otro = $this->lead(['phone' => '3001234564', 'consent_status' => 'granted']);
        $this->conversation($otro, ['commercial_phase' => 'NEW_LEAD']);
        $this->setStatus($otro, MarketingLead::STATUS_HOT);
        $this->assertSame(['lead_created' => 3, 'lead_qualified' => 1], self::eventsByName(), 'lo que registran los emisores reales');
        $this->assertSame($socio['member'], (int) DB::table('commercial_events')->where('marketing_lead_id', $yaSocio)->value('member_id'));

        $planner = new OutreachPlanner;
        $r = $planner->ingest(now());
        $this->assertSame([4, (int) DB::table('commercial_events')->max('id')], [$r['events'], $r['cursor_to']]);
        $this->assertSame(['candidates' => 3, 'evaluated' => 3, 'enrolled' => 2, 'not_in_audience' => 1, 'excluded' => 0, 'success_reached' => 0, 'insufficient_facts' => 0, 'already_enrolled' => 0, 'open_enrollment' => 0, 'unknown_subject' => 0], self::pick($r['strategies'][$vivo['strategyUuid']]));
        $this->assertSame((int) DB::table('commercial_events')->max('id'), (new OutreachStore)->cursor('commercial_events:shadow'));
        $inscripciones = DB::table('outreach_enrollments')->orderBy('id')->get();
        $this->assertSame([SubjectKey::lead($nuevo), SubjectKey::lead($pendiente)], $inscripciones->pluck('subject_key')->all());
        $this->assertSame(['ce:'.self::eventId('lead_created', $nuevo), 'ce:'.self::eventId('lead_created', $pendiente)], $inscripciones->pluck('cycle_key')->all(), 'la ocurrencia es el evento');
        $this->assertSame([$nuevo, null, 'shadow', 'commercial'], [(int) $inscripciones[0]->lead_id, $inscripciones[0]->member_id, $inscripciones[0]->mode, $inscripciones[0]->purpose]);
        $this->assertSame(2, DB::table('outreach_decisions')->where('kind', 'enroll')->count());

        $r = $planner->planDue(now());
        $this->assertSame([2, 1, 1], [$r['due'], $r['planned'], $r['denied']], json_encode($r));
        $this->assertSimulatedPlan(SubjectKey::lead($nuevo), self::NON_CANARY_WARNINGS);
        $this->assertDeniedNoConsent(SubjectKey::lead($pendiente));

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(OutreachPlanner::OFFSET_MINUTES));
        $d = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame([1, ['simulated' => 1]], [$d['claimed'], $d['results']], json_encode($d));
        $this->assertSame(['not_attempted', 'simulated'], [DB::table('outreach_outcomes')->where('subject_key', SubjectKey::lead($nuevo))->value('outcome'), DB::table('outreach_outcomes')->where('subject_key', SubjectKey::lead($nuevo))->value('source')]);

        // La ingesta siguiente no tiene nada nuevo: el cursor ya pasó.
        $r = $planner->ingest(now());
        $this->assertSame([0, []], [$r['events'], $r['strategies']]);
        $this->assertSame(2, DB::table('outreach_enrollments')->count());
        $this->assertStringNotContainsString('3001234561', json_encode(DB::table('outreach_decisions')->get()), 'ni teléfonos en las decisiones');
    }

    public function test_lead_created_registrado_antes_de_publicar_no_dispara_despues_y_el_siguiente_si(): void
    {
        // Auditoría B §4.1: sin estrategias, la ingesta avanza el cursor igual (no retroactivo, por diseño).
        $this->runFlags('shadow');
        $antes = $this->newLead('3001234561', 'granted');
        $this->conversation($antes, ['commercial_phase' => 'NEW_LEAD']);
        $planner = new OutreachPlanner;
        $r = $planner->ingest(now());
        $this->assertSame([1, []], [$r['events'], $r['strategies']]);
        $this->assertSame(self::eventId('lead_created', $antes), (new OutreachStore)->cursor('commercial_events:shadow'));

        $vivo = $this->leadCreatedStrategy();
        $this->assertSame([0, []], [$planner->ingest(now())['events'], $planner->ingest(now())['strategies']]);
        $this->assertSame(0, DB::table('outreach_enrollments')->count(), 'el evento anterior a la publicación no inscribe');

        $despues = $this->newLead('3001234562', 'granted');
        $this->conversation($despues, ['commercial_phase' => 'NEW_LEAD']);
        $r = $planner->ingest(now());
        $this->assertSame(1, $r['events']);
        $this->assertSame(['candidates' => 1, 'enrolled' => 1], self::pick($r['strategies'][$vivo['strategyUuid']], ['candidates', 'enrolled']));
        $this->assertSame([SubjectKey::lead($despues)], DB::table('outreach_enrollments')->pluck('subject_key')->all());
    }

    // ── lead_qualified ──────────────────────────────────────────────────────

    /** Cerrar al prospecto calificado: caliente o muy caliente; fuera si una persona lleva la conversación. @return array<string, mixed> */
    private function leadQualifiedStrategy(): array
    {
        return $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'goal' => 'close_plan',
            'trigger' => ['event' => 'lead_qualified'],
            'audience' => ['all' => [
                ['fact' => 'subject.is_lead', 'op' => 'eq', 'value' => true],
                ['fact' => 'lead.temperature', 'op' => 'in', 'value' => ['hot', 'very_hot']],
            ]],
            'exclusions' => ['fact' => 'conversation.human_in_control', 'op' => 'eq', 'value' => true],
            'success' => ['fact' => 'lead.commercial_phase', 'op' => 'eq', 'value' => 'WON'],
        ]));
    }

    public function test_lead_qualified_del_observador_inscribe_al_caliente_lo_simula_y_una_segunda_calificacion_no_abre_otra_inscripcion(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->leadQualifiedStrategy();
        // Sembrados sin pasar por el modelo (sin `lead_created`); el cambio de estado sí pasa por el observador.
        $prospecto = function (string $phone, string $temperature, array $conversation = []): int {
            $id = $this->lead(['phone' => $phone, 'temperature' => $temperature, 'consent_status' => 'granted']);
            $this->conversation($id, $conversation + ['commercial_phase' => 'QUALIFICATION']);

            return $id;
        };
        $caliente = $prospecto('3001234561', 'hot');            // califica (canario: sin avisos)
        $this->canary('+573001234561', 'whatsapp');
        $tibio = $prospecto('3001234562', 'warm');               // calificado, pero fuera de la audiencia
        $conPersona = $prospecto('3001234563', 'very_hot', ['staff_review_pending' => true]);   // excluido: una persona lleva la conversación
        $frio = $prospecto('3001234564', 'cold');                // `cold` no es una calificación: no hay evento
        $pideHumano = $prospecto('3001234565', 'hot');           // `needs_human` emite `human_requested`, no `lead_qualified`
        $this->setStatus($caliente, MarketingLead::STATUS_HOT);
        $this->setStatus($tibio, MarketingLead::STATUS_WARM);
        $this->setStatus($conPersona, MarketingLead::STATUS_INTERESTED);
        $this->setStatus($frio, MarketingLead::STATUS_COLD);
        $this->setStatus($pideHumano, MarketingLead::STATUS_NEEDS_HUMAN);
        $this->assertSame(['human_requested' => 1, 'lead_qualified' => 3], self::eventsByName());

        $planner = new OutreachPlanner;
        $r = $planner->ingest(now());
        $this->assertSame(4, $r['events']);
        $this->assertSame(['candidates' => 3, 'evaluated' => 3, 'enrolled' => 1, 'not_in_audience' => 1, 'excluded' => 1, 'success_reached' => 0, 'insufficient_facts' => 0, 'already_enrolled' => 0, 'open_enrollment' => 0, 'unknown_subject' => 0], self::pick($r['strategies'][$vivo['strategyUuid']]));
        $e = DB::table('outreach_enrollments')->sole();
        $this->assertSame([SubjectKey::lead($caliente), 'ce:'.self::eventId('lead_qualified', $caliente), $caliente, null], [$e->subject_key, $e->cycle_key, (int) $e->lead_id, $e->member_id]);

        $r = $planner->planDue(now());
        $this->assertSame([1, 1, 0], [$r['due'], $r['planned'], $r['denied']], json_encode($r));
        $this->assertSimulatedPlan(SubjectKey::lead($caliente), []);
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(OutreachPlanner::OFFSET_MINUTES));
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);

        // De caliente a interesado: el emisor registra otra calificación (otra ocurrencia), pero la inscripción abierta manda.
        $this->setStatus($caliente, MarketingLead::STATUS_INTERESTED);
        $r = $planner->ingest(now());
        $this->assertSame(1, $r['events']);
        $this->assertSame(['candidates' => 1, 'evaluated' => 0, 'enrolled' => 0, 'open_enrollment' => 1], self::pick($r['strategies'][$vivo['strategyUuid']], ['candidates', 'evaluated', 'enrolled', 'open_enrollment']));
        $this->assertSame(1, DB::table('outreach_enrollments')->count());
        $this->assertSame(0, DB::table('outreach_enrollments')->whereIn('subject_key', [SubjectKey::lead($tibio), SubjectKey::lead($conPersona), SubjectKey::lead($frio), SubjectKey::lead($pideHumano)])->count());
    }

    // ── renewal_window_opened ───────────────────────────────────────────────

    public function test_renewal_window_opened_de_commercial_scan_inscribe_al_socio_por_vencer_lo_simula_y_al_socio_sin_prospecto_lo_niega_n1(): void
    {
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'goal' => 'renew',
            'trigger' => ['event' => 'renewal_window_opened'],
            'audience' => ['all' => [
                ['fact' => 'membership.active', 'op' => 'eq', 'value' => true],
                ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false],
            ]],
            'success' => ['fact' => 'membership.days_to_expiry', 'op' => 'gt', 'value' => 30],
        ]));
        $ahora = self::utcAt(self::NOW);
        $conWhatsapp = function (array $m, string $phone): array {
            $this->conversation($this->lead(['member_id' => $m['member'], 'phone' => $phone, 'consent_status' => 'granted']));

            return $m;
        };
        // Califica: vence en 5 días, prospecto de WhatsApp autorizado, NO canario.
        $porVencer = $conWhatsapp($this->expiringMember($ahora, 5), '3001234561');
        // Califica en el borde de la ventana del motor (10 días), socio SIN prospecto (I-2): N1 lo niega.
        $diez = $this->expiringMember($ahora, 10);
        // En la ventana pero congelado: el emisor lo registra, la audiencia lo deja fuera.
        $congelado = $conWhatsapp($this->expiringMember($ahora, 5, ['membership_frozen_at' => '2026-11-10']), '3001234563');
        // No hay `renewal_window_opened`: vence en 11 o en 40 días; venció ayer (es `membership_expired`); un usuario sin ficha de socio.
        $once = $conWhatsapp($this->expiringMember($ahora, 11), '3001234564');
        $this->expiringMember($ahora, 40);
        $ayer = $conWhatsapp($this->expiringMember($ahora, -1), '3001234566');
        DB::table('users')->insert(['name' => 'Sin ficha', 'email' => 'sinficha@run.test', 'password' => 'x', 'plan' => 'Mensual', 'membership_end_date' => '2026-11-22', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('commercial:scan')->assertExitCode(0);
        $this->assertSame(['membership_expired' => 1, 'renewal_window_opened' => 3], self::eventsByName(), 'lo que registra el emisor real');
        $this->assertSame(0, DB::table('commercial_events')->whereIn('member_id', [$once['member']])->count());
        $this->assertSame(1, DB::table('commercial_events')->where('member_id', $ayer['member'])->where('event', 'membership_expired')->count());

        $planner = new OutreachPlanner;
        $r = $planner->ingest(now());
        $this->assertSame(4, $r['events']);
        $this->assertSame(['candidates' => 3, 'evaluated' => 3, 'enrolled' => 2, 'not_in_audience' => 1, 'excluded' => 0, 'success_reached' => 0, 'insufficient_facts' => 0, 'already_enrolled' => 0, 'open_enrollment' => 0, 'unknown_subject' => 0], self::pick($r['strategies'][$vivo['strategyUuid']]));
        $inscripciones = DB::table('outreach_enrollments')->orderBy('id')->get();
        $this->assertSame([$porVencer['key'], $diez['key']], $inscripciones->pluck('subject_key')->all());
        $this->assertSame(['ce:'.self::eventId('renewal_window_opened', null, $porVencer['member']), 'ce:'.self::eventId('renewal_window_opened', null, $diez['member'])], $inscripciones->pluck('cycle_key')->all());
        $this->assertSame($porVencer['member'], (int) $inscripciones[0]->member_id);
        $this->assertSame(0, DB::table('outreach_enrollments')->where('subject_key', $congelado['key'])->count());

        $r = $planner->planDue(now());
        $this->assertSame([2, 1, 1], [$r['due'], $r['planned'], $r['denied']], json_encode($r));
        $this->assertSimulatedPlan($porVencer['key'], self::NON_CANARY_WARNINGS);
        $this->assertDeniedNoConsent($diez['key']);
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addMinutes(OutreachPlanner::OFFSET_MINUTES));
        $this->assertSame(['simulated' => 1], (new OutreachDispatcher)->dispatch(now(), null, true)['results']);

        // El escaneo de la hora siguiente no repite el hecho (una vez por vencimiento) y la ingesta no ve nada nuevo.
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC')->addHour());
        $this->artisan('commercial:scan')->assertExitCode(0);
        $this->assertSame(['membership_expired' => 1, 'renewal_window_opened' => 3], self::eventsByName());
        $this->assertSame(0, $planner->ingest(now())['events']);
        $this->assertSame(2, DB::table('outreach_enrollments')->count());
    }
}
