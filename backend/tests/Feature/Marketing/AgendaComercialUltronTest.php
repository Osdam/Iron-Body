<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AuditLog;
use App\Models\MarketingAgentAction;
use App\Models\MarketingAiAction;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Models\RolePermission;
use App\Services\Commercial\Tools\Agenda\BookAppointmentTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MarketingAgendaVersion;
use App\Services\Marketing\MarketingAppointmentService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\BusinessClock;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\CourtesyRequestService;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * OBJETIVO 2 — LA AGENDA COMERCIAL Y ULTRON COMPARTEN UNA SOLA FUENTE.
 *
 * Lo que ULTRON gestiona por WhatsApp aparece en la Agenda comercial del CRM
 * como una cita SOLICITADA (`requested`), sin que nadie refresque nada, y una
 * persona del equipo la confirma desde la agenda (`scheduled`). Se mide aquí el
 * efecto de punta a punta —el commit real de ULTRON y los endpoints reales del
 * CRM—: estados, idempotencia, reprogramación sin duplicados, tiempo real,
 * pausa del agente, permisos y hora de Bogotá. La conversación con el lead
 * (qué se le puede decir) la siguen fijando CortesiaTest y AsesorSeniorTest.
 */
class AgendaComercialUltronTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const AGENDA = '/api/admin/marketing/appointments';

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        // Un lunes a las 9 de la mañana en Neiva.
        Carbon::setTestNow(Carbon::parse('2026-09-21 14:00', 'UTC'));

        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados y domingos de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
            'metadata' => ['windows' => [
                'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
                'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'],
                'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
            ]],
        ]);

        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::DISCOVERY]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    /** Un turno real de ULTRON: el entrante, el decide y el commit con la herramienta de cortesía. */
    private function turno(string $texto, string $wamid, array $overrides = [], array $extra = []): TestResponse
    {
        $m = MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $texto, 'meta_message_id' => $wamid, 'status' => 'received']);
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->internas())->json('decide_token');

        return $this->commit($m, $wamid, $token, $overrides, $extra);
    }

    /** `$extra` va en la raíz del commit: el veredicto del critic o la rendición de n8n. */
    private function commit(MarketingMessage $m, string $wamid, string $token, array $overrides = [], array $extra = []): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', array_merge([
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $wamid,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => array_merge([
                'strategy_goal' => 'book_visit', 'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Listo, queda registrada tu solicitud de día de cortesía. El equipo la revisará.',
                'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'offer_appointment',
                'confidence' => 0.9, 'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST],
                'courtesy_action' => 'request',
            ], $overrides),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], $extra), $this->internas());
    }

    /** «Quiero ir mañana a las 6»: martes 22 a las 18:00 de Neiva. */
    private function pideLaVisita(string $wamid = 'w.o2.1'): MarketingAppointment
    {
        $this->turno('quiero ir mañana a las 6 de la tarde', $wamid, [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
        ])->assertOk();

        return MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->latest('id')->firstOrFail();
    }

    private function internas(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    /** Sesión con un rol y, si se piden, permisos concedidos a mano a ese rol. */
    private function sesion(string $rol = Admin::ROLE_SUPER_ADMIN, array $permisos = []): array
    {
        if ($permisos !== []) {
            AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);
            foreach ($permisos as $permiso) {
                RolePermission::updateOrCreate(['role' => $rol, 'permission' => $permiso], ['granted' => true]);
            }
            app(RolePermissionPolicy::class)->flush();
        }

        return $this->actingAsAdmin(Admin::create([
            'name' => 'Persona '.$rol, 'email' => 'p-'.Str::random(8).'@ironbody.test',
            'password' => 'x', 'role' => $rol, 'status' => 'active',
        ]));
    }

    private function pausar(): void
    {
        DB::table('marketing_agent_settings')->where('id', 1)->update(['paused' => true, 'paused_at' => now(), 'version' => DB::raw('version + 1')]);
    }

    private function activas(): int
    {
        return MarketingAppointment::query()->where('marketing_lead_id', $this->lead->id)
            ->whereIn('status', MarketingAppointment::ACTIVE_STATUSES)->count();
    }

    // ── 1 · ULTRON crea una solicitud ────────────────────────────────────────

    public function test_1_ultron_deja_la_visita_solicitada_en_la_agenda(): void
    {
        $cita = $this->pideLaVisita();

        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->status, 'solicitada no es confirmada');
        $this->assertSame(MarketingAppointment::TYPE_VISIT, $cita->type);
        $this->assertSame($this->lead->id, $cita->marketing_lead_id);
        $this->assertSame($this->conversation->id, $cita->marketing_conversation_id);
        $this->assertSame('2026-09-22', data_get($cita->metadata, 'requested_date'));
        $this->assertSame('18:00', data_get($cita->metadata, 'requested_time'));
        $this->assertNull($cita->contact_phone, 'se duplicó el teléfono en la cita: ya vive en el lead');
        $this->assertSame('requested', data_get(collect($cita->metadata['history'])->first(), 'event'));
        $this->assertSame('ultron', data_get(collect($cita->metadata['history'])->first(), 'by'));
        // Y el objetivo activo de la conversación la recuerda, con la cita.
        $this->assertSame($cita->id, data_get($this->conversation->fresh()->memory, 'courtesy_request.appointment_id'));
        $this->assertSame('requested', data_get($this->conversation->fresh()->memory, 'courtesy_request.status'));
    }

    // ── 2 · aparece en la Agenda del CRM ─────────────────────────────────────

    public function test_2_la_solicitud_aparece_en_la_agenda_del_crm_con_lo_necesario(): void
    {
        $cita = $this->pideLaVisita();

        $fila = collect($this->getJson(self::AGENDA.'?date_from=2026-09-21', $this->sesion())->assertOk()->json('data'))->firstWhere('id', $cita->id);

        $this->assertNotNull($fila, 'la solicitud de ULTRON no salió en la agenda');
        $this->assertSame('requested', $fila['status']);
        $this->assertSame('ultron', $fila['source']);
        $this->assertSame(['date' => '2026-09-22', 'time' => '18:00'], $fila['requested']);
        $this->assertSame('Prospecto', $fila['lead']['name']);
        $this->assertSame('3150536026', $fila['lead']['phone']);
        $this->assertSame('visit', $fila['type']);
        $this->assertNotNull($fila['created_at']);
        $this->assertSame(['confirm' => true, 'cancel' => true, 'complete' => false, 'reschedule' => true], $fila['allowed_actions']);

        // El filtro «Por confirmar» y el de origen la encuentran.
        $this->assertCount(1, $this->getJson(self::AGENDA.'?status=requested&source=ultron', $this->sesion())->assertOk()->json('data'));
    }

    // ── 3 · un reintento no duplica ──────────────────────────────────────────

    public function test_3_un_reintento_de_n8n_o_de_meta_no_crea_otra_cita(): void
    {
        $m = MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'quiero ir mañana a las 6', 'meta_message_id' => 'w.o2.retry', 'status' => 'received']);
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->internas())->json('decide_token');
        $datos = ['courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00'];

        $this->commit($m, 'w.o2.retry', $token, $datos)->assertOk();
        // El mismo turno otra vez: el commit ya se decidió y no vuelve a ejecutar nada.
        $this->commit($m, 'w.o2.retry', $token, $datos)->assertStatus(409);
        // Y otro mensaje pidiendo lo mismo mueve la misma solicitud, no abre otra.
        $this->turno('si, mañana a las 6 de la tarde', 'w.o2.retry2', $datos)->assertOk();

        $this->assertSame(1, MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->count());
    }

    // ── 4 · solicitada no se presenta como confirmada ────────────────────────

    public function test_4_mientras_siga_solicitada_ultron_no_la_da_por_confirmada(): void
    {
        $this->turno('quiero ir mañana a las 6 de la tarde', 'w.o2.claim', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
            'reply_draft' => 'Listo, tu visita quedó confirmada para mañana a las 6. ¡Te esperamos!',
        ])->assertOk();

        $saliente = (string) MarketingMessage::query()->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->value('body');
        $this->assertStringNotContainsStringIgnoringCase('confirmada para', $saliente, 'se le dijo confirmada a una visita solo solicitada');
        $this->assertStringNotContainsStringIgnoringCase('te esperamos', $saliente);
        $this->assertStringContainsString('Dejé registrada tu solicitud de cortesía', $saliente, 'el acta de Laravel no contó lo que de verdad hay');

        $compromiso = app(CourtesyRequestService::class)->commitmentsForLead($this->lead)[0];
        $this->assertSame('requested', $compromiso['status']);
    }

    // ── 5 · confirmar ────────────────────────────────────────────────────────

    public function test_5_confirmar_desde_la_agenda_la_vuelve_en_firme_una_sola_vez(): void
    {
        $cita = $this->pideLaVisita();
        $s = $this->sesion();

        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $s)->assertOk()
            ->assertJsonPath('changed', true)->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.allowed_actions.complete', true)->assertJsonPath('data.allowed_actions.confirm', false);
        // Doble clic: sin efecto y sin otra traza.
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $s)->assertOk()->assertJsonPath('changed', false);

        $trazas = AuditLog::query()->where('entity', 'cita')->where('entity_id', (string) $cita->id)->where('action', 'status')->get();
        $this->assertCount(1, $trazas);
        $this->assertSame(['field' => 'estado', 'from' => 'requested', 'to' => 'scheduled'], $trazas->first()->changes[0]);
        $this->assertSame('Confirmó la cita', $trazas->first()->summary);

        // Y para ULTRON ya es una visita confirmada.
        $this->assertSame('confirmed', app(CourtesyRequestService::class)->commitmentsForLead($this->lead)[0]['status']);
    }

    // ── 6 · cancelar ─────────────────────────────────────────────────────────

    public function test_6_cancelar_cambia_el_estado_sin_borrar_y_es_idempotente(): void
    {
        $cita = $this->pideLaVisita();
        $s = $this->sesion();

        $this->postJson(self::AGENDA."/{$cita->id}/cancel", ['reason' => 'No contesta'], $s)->assertOk()
            ->assertJsonPath('changed', true)->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'No contesta');
        $this->postJson(self::AGENDA."/{$cita->id}/cancel", ['reason' => 'Otro motivo'], $s)->assertOk()
            ->assertJsonPath('changed', false)->assertJsonPath('data.cancellation_reason', 'No contesta');

        $this->assertSame(1, MarketingAppointment::query()->whereKey($cita->id)->count(), 'cancelar no puede borrar');
        // Lo cerrado no se reabre por la puerta de atrás.
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $s)->assertStatus(409)->assertJsonPath('code', 'appointment_closed');
    }

    // ── 7 · completar ────────────────────────────────────────────────────────

    public function test_7_completar_exige_que_la_visita_este_confirmada(): void
    {
        $cita = $this->pideLaVisita();
        $s = $this->sesion();

        $this->postJson(self::AGENDA."/{$cita->id}/complete", [], $s)->assertStatus(409)->assertJsonPath('code', 'appointment_not_confirmed');
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $s)->assertOk();
        // Completar exige además que haya llegado su hora (test_34): se va a ella, con una sesión viva.
        $this->travelTo($cita->fresh()->scheduled_at->copy()->addMinute());
        $s = $this->sesion();
        $this->postJson(self::AGENDA."/{$cita->id}/complete", ['note' => 'Vino con un amigo'], $s)->assertOk()
            ->assertJsonPath('changed', true)->assertJsonPath('data.status', 'completed');
        $this->postJson(self::AGENDA."/{$cita->id}/complete", [], $s)->assertOk()->assertJsonPath('changed', false);
        $this->postJson(self::AGENDA."/{$cita->id}/cancel", [], $s)->assertStatus(409)->assertJsonPath('code', 'appointment_closed');
        $this->assertNotNull($cita->fresh()->completed_at);
    }

    // ── 7.bis · completar, no antes de su hora ───────────────────────────────

    private const NO_HA_LLEGADO = 'La visita todavía no puede marcarse como completada porque su hora no ha llegado.';

    /** Una visita confirmada desde el CRM para esa hora de Bogotá. */
    private function confirmadaPara(string $bogota): MarketingAppointment
    {
        return MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'type' => MarketingAppointment::TYPE_VISIT, 'title' => 'Visita de prueba',
            'status' => MarketingAppointment::STATUS_SCHEDULED, 'source' => MarketingAppointment::SOURCE_CRM,
            'scheduled_at' => Carbon::parse($bogota, 'America/Bogota')->utc(),
        ]);
    }

    /**
     * Ese instante de Bogotá, con el reloj simulado en UTC como la app: con uno
     * en otra zona, Carbon lee las fechas sin zona de la base de datos en la
     * zona del reloj simulado, y la cita de las 18:00 pasaría a las 23:00.
     */
    private function enBogota(string $bogota): void
    {
        Carbon::setTestNow(Carbon::parse($bogota, 'America/Bogota')->utc());
    }

    private function auditoriasDe(MarketingAppointment $cita): int
    {
        return AuditLog::query()->where('entity', 'cita')->where('entity_id', $cita->id)->count();
    }

    /** Completar es decir que la visita ocurrió: antes de su hora, 409 y nada cambia (ni estado, ni historia, ni aviso, ni auditoría). */
    public function test_34_completar_antes_de_su_hora_da_409_y_no_toca_nada(): void
    {
        // Ahora es el lunes 21 a las 09:00 de Bogotá; la visita es el martes 22 a las 18:00.
        $cita = $this->confirmadaPara('2026-09-22 18:00');
        $version = MarketingAgendaVersion::current();
        $auditorias = $this->auditoriasDe($cita);

        $this->postJson(self::AGENDA."/{$cita->id}/complete", ['note' => 'antes de tiempo'], $this->sesion())
            ->assertStatus(409)->assertJsonPath('code', 'appointment_not_due')->assertJsonPath('message', self::NO_HA_LLEGADO);

        $fila = $cita->fresh();
        $this->assertSame(MarketingAppointment::STATUS_SCHEDULED, $fila->status);
        $this->assertNull($fila->completed_at);
        $this->assertNull($fila->notes);
        $this->assertSame([], (array) data_get($fila->metadata, 'history', []));
        $this->assertSame($version, MarketingAgendaVersion::current(), 'la agenda avisó un cambio que no hubo');
        $this->assertSame($auditorias, $this->auditoriasDe($cita));
    }

    /** En su hora exacta ya se puede, y una pasada también; repetirlo sigue siendo un «ya estaba así», sin aviso ni auditoría. */
    public function test_34b_en_su_hora_exacta_y_despues_se_completa_y_repetir_no_hace_nada(): void
    {
        $cita = $this->confirmadaPara('2026-09-22 18:00');
        $pasada = $this->confirmadaPara('2026-09-22 10:00');

        $this->enBogota('2026-09-22 18:00:00');
        $s = $this->sesion();
        $version = MarketingAgendaVersion::current();
        $this->postJson(self::AGENDA."/{$cita->id}/complete", [], $s)->assertOk()
            ->assertJsonPath('changed', true)->assertJsonPath('data.status', 'completed');
        $this->assertSame($version + 1, MarketingAgendaVersion::current());

        $auditorias = $this->auditoriasDe($cita);
        $this->postJson(self::AGENDA."/{$cita->id}/complete", [], $s)->assertOk()->assertJsonPath('changed', false);
        $this->assertSame($version + 1, MarketingAgendaVersion::current(), 'repetir avisó un cambio que no hubo');
        $this->assertSame($auditorias, $this->auditoriasDe($cita));

        $this->postJson(self::AGENDA."/{$pasada->id}/complete", [], $s)->assertOk()
            ->assertJsonPath('changed', true)->assertJsonPath('data.status', 'completed');
    }

    /** El borde de medianoche en Bogotá: a esa hora ya es otro día en UTC, y eso no puede engañar a la regla. */
    public function test_34c_el_borde_de_medianoche_en_bogota(): void
    {
        $alFinal = $this->confirmadaPara('2026-09-22 23:59');   // 2026-09-23 04:59 UTC
        $alEmpezar = $this->confirmadaPara('2026-09-23 00:01'); // 2026-09-23 05:01 UTC

        $this->enBogota('2026-09-22 23:58:59');
        $s = $this->sesion();
        $this->postJson(self::AGENDA."/{$alFinal->id}/complete", [], $s)->assertStatus(409)->assertJsonPath('code', 'appointment_not_due');

        $this->enBogota('2026-09-22 23:59:00');
        $this->postJson(self::AGENDA."/{$alFinal->id}/complete", [], $s)->assertOk()->assertJsonPath('changed', true);
        // Las 23:59 del 22 en Bogotá ya son el 23 en UTC, el mismo día que la visita de las 00:01: sigue sin poder completarse.
        $this->postJson(self::AGENDA."/{$alEmpezar->id}/complete", [], $s)->assertStatus(409)->assertJsonPath('code', 'appointment_not_due');

        $this->enBogota('2026-09-23 00:01:00');
        $this->postJson(self::AGENDA."/{$alEmpezar->id}/complete", [], $s)->assertOk()->assertJsonPath('changed', true);
    }

    /** Lo que ya se rechazaba conserva su motivo, antes y después de la hora: el estado se mira antes que el reloj. */
    public function test_34d_solicitada_cancelada_y_completada_conservan_su_respuesta(): void
    {
        $solicitada = $this->pideLaVisita();
        $cancelada = $this->confirmadaPara('2026-09-22 10:00');
        $this->postJson(self::AGENDA."/{$cancelada->id}/cancel", ['reason' => 'No puede venir'], $this->sesion())->assertOk();
        $noVino = $this->confirmadaPara('2026-09-22 11:00');
        $noVino->forceFill(['status' => MarketingAppointment::STATUS_NO_SHOW])->save();
        // Un dato heredado: completada aunque su fecha sea futura. Repetir sigue siendo un «ya estaba así».
        $completada = MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'type' => MarketingAppointment::TYPE_VISIT, 'title' => 'Heredada',
            'status' => MarketingAppointment::STATUS_COMPLETED, 'completed_at' => now(),
            'scheduled_at' => Carbon::parse('2026-09-25 18:00', 'America/Bogota')->utc(),
        ]);

        foreach (['2026-09-21 09:00:00', '2026-09-23 09:00:00'] as $cuando) {
            $this->enBogota($cuando);
            $s = $this->sesion();
            $this->postJson(self::AGENDA."/{$solicitada->id}/complete", [], $s)->assertStatus(409)->assertJsonPath('code', 'appointment_not_confirmed');
            $this->postJson(self::AGENDA."/{$cancelada->id}/complete", [], $s)->assertStatus(409)->assertJsonPath('code', 'appointment_closed');
            $this->postJson(self::AGENDA."/{$noVino->id}/complete", [], $s)->assertStatus(409)->assertJsonPath('code', 'appointment_closed');
            $this->postJson(self::AGENDA."/{$completada->id}/complete", [], $s)->assertOk()->assertJsonPath('changed', false);
        }
    }

    /** Doble clic en su hora: un solo efecto, una sola auditoría y un solo aviso. */
    public function test_34e_doble_clic_en_su_hora_un_solo_efecto(): void
    {
        $cita = $this->confirmadaPara('2026-09-22 18:00');
        $this->enBogota('2026-09-22 18:30:00');
        $s = $this->sesion();
        $version = MarketingAgendaVersion::current();

        $primera = $this->postJson(self::AGENDA."/{$cita->id}/complete", [], $s)->assertOk();
        $segunda = $this->postJson(self::AGENDA."/{$cita->id}/complete", [], $s)->assertOk();

        $this->assertSame([true, false], [$primera->json('changed'), $segunda->json('changed')]);
        $this->assertSame($version + 1, MarketingAgendaVersion::current());
        $this->assertSame(1, AuditLog::query()->where('entity', 'cita')->where('entity_id', $cita->id)->where('action', 'status')->count());
    }

    /** El PATCH que cambia el estado pasa por la misma regla: no es una puerta de atrás. */
    public function test_34f_el_patch_a_completada_tambien_respeta_la_hora(): void
    {
        $cita = $this->confirmadaPara('2026-09-22 18:00');

        $this->patchJson(self::AGENDA."/{$cita->id}", ['status' => 'completed'], $this->sesion())
            ->assertStatus(409)->assertJsonPath('code', 'appointment_not_due');

        $this->assertSame(MarketingAppointment::STATUS_SCHEDULED, $cita->fresh()->status);
    }

    // ── 8 · reprogramar no deja dos activas ──────────────────────────────────

    public function test_8_reprogramar_desde_el_crm_mueve_la_misma_cita_y_conserva_su_estado(): void
    {
        $cita = $this->pideLaVisita();
        $s = $this->sesion();

        $this->postJson(self::AGENDA."/{$cita->id}/reschedule", ['scheduled_at' => '2026-09-23T19:00:00-05:00'], $s)->assertOk()
            ->assertJsonPath('changed', true)->assertJsonPath('data.status', 'requested');
        $this->assertSame(1, $this->activas());
        $this->assertSame('2026-09-24 00:00:00', $cita->fresh()->scheduled_at->copy()->utc()->toDateTimeString());

        // Al pasado no; y cerrada, tampoco.
        $this->postJson(self::AGENDA."/{$cita->id}/reschedule", ['scheduled_at' => '2026-09-20T10:00:00-05:00'], $s)
            ->assertStatus(422)->assertJsonPath('code', 'scheduled_in_the_past');
        $this->postJson(self::AGENDA."/{$cita->id}/cancel", [], $s)->assertOk();
        $this->postJson(self::AGENDA."/{$cita->id}/reschedule", ['scheduled_at' => '2026-09-25T10:00:00-05:00'], $s)
            ->assertStatus(409)->assertJsonPath('code', 'appointment_closed');
    }

    /**
     * LA DEUDA: con la visita YA confirmada, la persona pide otro día por
     * WhatsApp. Antes nacía otra solicitud y la cita confirmada seguía viva:
     * dos activas. Ahora se mueve la MISMA cita y vuelve a solicitada.
     */
    public function test_8b_ultron_moviendo_una_visita_confirmada_no_deja_dos_activas(): void
    {
        $cita = $this->pideLaVisita();
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $this->sesion())->assertOk();

        $this->turno('mejor el miércoles a la misma hora', 'w.o2.mueve', [
            'courtesy_date' => '2026-09-23', 'courtesy_time' => '18:00',
            'reply_draft' => 'Listo, dejo registrada tu solicitud para el miércoles. El equipo la revisará.',
        ])->assertOk();

        $this->assertSame(1, $this->activas(), 'cambiar de día dejó dos citas vivas');
        $cita->refresh();
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->status, 'el día nuevo quedó confirmado sin que nadie lo mirara');
        $this->assertSame('2026-09-23', data_get($cita->metadata, 'requested_date'));
        $this->assertSame('reopened', data_get(collect($cita->metadata['history'])->last(), 'event'));
    }

    /**
     * La visita la confirmó el equipo desde el CRM (origen `crm`) y la persona
     * pide otro día por el chat dos veces: se mueve SIEMPRE esa misma cita. Si
     * ULTRON solo buscara sus propias solicitudes, la segunda vez no la vería y
     * abriría otra: dos activas.
     */
    public function test_8e_la_visita_agendada_desde_el_crm_se_mueve_siempre_la_misma(): void
    {
        $id = $this->postJson(self::AGENDA, [
            'type' => 'visit', 'title' => 'Visita', 'scheduled_at' => '2026-09-22T18:00:00-05:00', 'marketing_lead_id' => $this->lead->id,
        ], $this->sesion())->assertCreated()->json('data.id');

        $this->turno('mejor el miércoles a las 6 de la tarde', 'w.o2.crm1', ['courtesy_date' => '2026-09-23', 'courtesy_time' => '18:00'])->assertOk();
        $this->turno('no, mejor el jueves a las 6 de la tarde', 'w.o2.crm2', ['courtesy_date' => '2026-09-24', 'courtesy_time' => '18:00'])->assertOk();

        $this->assertSame(1, $this->activas(), 'la visita del CRM movida por el chat dejó dos activas');
        $cita = MarketingAppointment::findOrFail($id);
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->status);
        $this->assertSame('2026-09-24', data_get($cita->metadata, 'requested_date'));
        $this->assertSame(MarketingAppointment::SOURCE_CRM, $cita->source, 'el origen de la cita no se reescribe');
    }

    /** Repetir el mismo día y hora de una visita YA confirmada no la des-confirma. */
    public function test_8c_repetir_la_hora_de_una_visita_confirmada_no_la_toca(): void
    {
        $cita = $this->pideLaVisita();
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $this->sesion())->assertOk();

        $memoriaAntes = data_get($this->conversation->fresh()->memory, 'courtesy_request');
        $this->travel(5)->minutes(); // con el reloj quieto, una memoria reescrita sería idéntica
        $this->turno('perfecto, nos vemos mañana a las 6 entonces', 'w.o2.repite', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
        ])->assertOk();

        $this->assertSame(MarketingAppointment::STATUS_SCHEDULED, $cita->fresh()->status, 'repetir la hora des-confirmó la visita');
        $this->assertSame($memoriaAntes, data_get($this->conversation->fresh()->memory, 'courtesy_request'), 'la memoria volvió a contar como pendiente una visita ya confirmada');
        $this->assertSame(1, $this->activas());
    }

    /**
     * Una visita YA CONFIRMADA no la cancela ULTRON por su cuenta: la invariante
     * de promesas del commit no le deja afirmar «cancelé tu visita» sin una
     * solicitud abierta, y la cita sigue confirmada hasta que el equipo la
     * cancele desde la agenda. Es la regla de hoy; cambiarla es una decisión.
     */
    public function test_8d_ultron_no_cancela_por_su_cuenta_una_visita_ya_confirmada(): void
    {
        $cita = $this->pideLaVisita();
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $this->sesion())->assertOk();

        // Lo que dice ULTRON = lo que ejecutó Laravel: la cancelación no sale, sale el estado real.
        $this->turno('ya no voy a poder ir, cancela por favor', 'w.o2.cancela', [
            'courtesy_action' => 'cancel', 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Sin problema, cancelé tu visita. Cuando quieras la retomamos.',
        ])->assertOk();

        $this->assertSame(MarketingAppointment::STATUS_SCHEDULED, $cita->fresh()->status, 'la cita confirmada cambió sin que nadie del equipo la tocara');
        $sale = $this->ultimoSaliente();
        $this->assertStringNotContainsString('cancelé', $sale, 'se afirmó una cancelación que no ocurrió');
        $this->assertStringContainsString('sigue confirmada para el martes 22 de septiembre a las 6:00 p. m.', $sale);
    }

    /** Un turno que solo redacta: no pide herramientas ni toca la cita. */
    private function soloTexto(string $borrador, string $wamid): void
    {
        $this->turno('ok', $wamid, [
            'tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => $borrador,
        ])->assertOk();
    }

    private function ultimoSaliente(): string
    {
        return mb_strtolower((string) MarketingMessage::query()->where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->value('body'));
    }

    /**
     * El equipo cierra la cita JUSTO entre que ULTRON la elige y la mueve: el día
     * nuevo que pide la persona queda como una solicitud nueva, no como un turno
     * perdido con un 500.
     */
    public function test_16_si_el_equipo_cierra_la_cita_mientras_ultron_la_mueve_queda_una_solicitud_nueva(): void
    {
        $vieja = $this->pideLaVisita();
        $una = true;
        MarketingAppointment::retrieved(function (MarketingAppointment $a) use ($vieja, &$una): void {
            if ($una && $a->id === $vieja->id) {
                $una = false;
                DB::table('marketing_appointments')->where('id', $vieja->id)->update(['status' => MarketingAppointment::STATUS_CANCELLED, 'cancelled_at' => now()]);
            }
        });

        $r = app(CourtesyRequestService::class)->request($this->lead, $this->conversation, null, '2026-09-23T18:00:00-05:00', '2026-09-23', '18:00');

        $this->assertTrue($r['nueva'], 'el día nuevo no quedó anotado');
        $this->assertSame(MarketingAppointment::STATUS_CANCELLED, $vieja->fresh()->status);
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, MarketingAppointment::findOrFail($r['appointment_id'])->status);
        $this->assertSame(1, $this->activas());
    }

    /** Si alguien pasa a llamada una solicitud de ULTRON, la siguiente cortesía no choca contra ella. */
    public function test_17_cambiar_el_tipo_de_una_solicitud_no_bloquea_la_siguiente_cortesia(): void
    {
        $cita = $this->pideLaVisita();
        $this->patchJson(self::AGENDA."/{$cita->id}", ['type' => 'call'], $this->sesion())->assertOk();

        $this->turno('quiero ir el miércoles a las 6 de la tarde', 'w.o2.tipo', ['courtesy_date' => '2026-09-23', 'courtesy_time' => '18:00'])->assertOk();

        $this->assertSame(1, MarketingAppointment::query()->where('source', 'ultron')->where('type', 'visit')->where('status', 'requested')->count());
    }

    public function test_18_patch_con_solo_la_duracion_la_guarda_y_reenviar_la_fecha_no_falla(): void
    {
        $s = $this->sesion();
        $cita = $this->pideLaVisita();
        $this->patchJson(self::AGENDA."/{$cita->id}", ['duration_minutes' => 90], $s)->assertOk()->assertJsonPath('data.duration_minutes', 90);

        // Una cita de ayer que no vino: el cliente reenvía el objeto entero con la misma fecha.
        $ayer = MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'type' => 'call', 'status' => MarketingAppointment::STATUS_SCHEDULED,
            'title' => 'Llamada', 'scheduled_at' => now()->subDay(),
        ]);
        $this->patchJson(self::AGENDA."/{$ayer->id}", [
            'scheduled_at' => $ayer->scheduled_at->toIso8601String(), 'expected_scheduled_at' => $ayer->scheduled_at->toIso8601String(), 'status' => 'no_show', 'notes' => 'No contestó',
        ], $s)->assertOk()->assertJsonPath('data.status', 'no_show');

        // Y sobre una cita ya cerrada, reenviar su misma fecha para editar las notas no es moverla.
        $this->patchJson(self::AGENDA."/{$ayer->id}", [
            'scheduled_at' => $ayer->scheduled_at->toIso8601String(), 'expected_scheduled_at' => $ayer->scheduled_at->toIso8601String(), 'notes' => 'Se le volvió a escribir',
        ], $s)->assertOk()->assertJsonPath('data.notes', 'Se le volvió a escribir');

        // El formulario entero con la misma fecha y OTRA duración: se edita la duración, no se «mueve».
        $this->patchJson(self::AGENDA."/{$ayer->id}", [
            'scheduled_at' => $ayer->scheduled_at->toIso8601String(), 'expected_scheduled_at' => $ayer->scheduled_at->toIso8601String(), 'duration_minutes' => 45,
        ], $s)->assertOk()->assertJsonPath('data.duration_minutes', 45);
        $pasadaActiva = MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'type' => 'call', 'status' => MarketingAppointment::STATUS_SCHEDULED,
            'title' => 'Llamada de ayer', 'scheduled_at' => now()->subDay(),
        ]);
        $this->patchJson(self::AGENDA."/{$pasadaActiva->id}", [
            'scheduled_at' => $pasadaActiva->scheduled_at->toIso8601String(), 'expected_scheduled_at' => $pasadaActiva->scheduled_at->toIso8601String(), 'duration_minutes' => 50,
        ], $s)->assertOk()->assertJsonPath('data.duration_minutes', 50);
    }

    /** Volver a «visita» una solicitud que se pasó a llamada, cuando ya nació otra, es un conflicto: 409, no 500. */
    public function test_17b_volver_a_visita_una_solicitud_duplicada_da_409(): void
    {
        $s = $this->sesion();
        $primera = $this->pideLaVisita();
        $this->patchJson(self::AGENDA."/{$primera->id}", ['type' => 'call'], $s)->assertOk();
        $this->turno('quiero ir el miércoles a las 6 de la tarde', 'w.o2.dup', ['courtesy_date' => '2026-09-23', 'courtesy_time' => '18:00'])->assertOk();

        $this->patchJson(self::AGENDA."/{$primera->id}", ['type' => 'visit'], $s)
            ->assertStatus(409)->assertJsonPath('code', 'appointment_duplicate_request');
        $this->assertSame('call', $primera->fresh()->type);
    }

    /** El recomendador del CRM no sugiere otra visita encima de la solicitud viva de ULTRON. */
    public function test_19_el_recomendador_no_sugiere_otra_cita_con_una_solicitud_viva(): void
    {
        $s = $this->sesion();
        $sugerencias = function () use ($s): array {
            MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'quiero visitar el gimnasio', 'meta_message_id' => 'w.o2.rec.'.Str::random(6), 'status' => 'received']);

            return collect($this->postJson('/api/admin/marketing/agent-actions/recommend', ['marketing_conversation_id' => $this->conversation->id], $s)->assertOk()->json('actions'))->pluck('action_type')->all();
        };

        // Sin ninguna cita, sí la sugiere (la prueba puede fallar).
        $this->assertContains(MarketingAgentAction::TYPE_SUGGEST_APPOINTMENT, $sugerencias());
        MarketingAgentAction::query()->delete();

        $this->pideLaVisita();
        $tipos = $sugerencias();
        $this->assertNotContains(MarketingAgentAction::TYPE_SUGGEST_APPOINTMENT, $tipos, 'sugirió otra visita encima de la solicitud de ULTRON');
        $this->assertNotContains(MarketingAgentAction::TYPE_CREATE_APPOINTMENT, $tipos);
    }

    /**
     * La visita la agendó el equipo SIN conversación; ULTRON la movió y el equipo
     * la canceló. Un «¡Listo! Nos vemos el sábado a las 10.» después no puede
     * salir: la guarda tiene que saber que esa conversación tuvo una cortesía.
     */
    public function test_20_la_guarda_reconoce_la_visita_del_equipo_que_ultron_movio(): void
    {
        $s = $this->sesion();
        $id = $this->postJson(self::AGENDA, [
            'type' => 'visit', 'title' => 'Visita', 'scheduled_at' => '2026-09-22T18:00:00-05:00', 'marketing_lead_id' => $this->lead->id,
        ], $s)->assertCreated()->json('data.id');
        $this->turno('mejor el sábado a las 10', 'w.o2.guarda1', ['courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00'])->assertOk();
        $this->assertSame($this->conversation->id, MarketingAppointment::findOrFail($id)->marketing_conversation_id);
        $this->postJson(self::AGENDA."/{$id}/cancel", [], $s)->assertOk();

        $this->soloTexto('¡Listo! Nos vemos el sábado a las 10.', 'w.o2.guarda2');

        $this->assertStringNotContainsString('nos vemos el sábado a las 10', $this->ultimoSaliente(), 'se prometió una visita cancelada');
    }

    /** Una fecha que la agenda no puede guardar (el año 10000 en UTC) se rechaza sin crear nada. */
    public function test_21_una_fecha_fuera_de_rango_se_rechaza_sin_crear_nada(): void
    {
        $s = $this->sesion();
        $this->postJson(self::AGENDA, ['type' => 'call', 'title' => 'X', 'scheduled_at' => '9999-12-31T23:30:00-05:00'], $s)
            ->assertStatus(422)->assertJsonPath('code', 'scheduled_out_of_range');
        $this->assertSame(0, MarketingAppointment::count());

        $cita = $this->pideLaVisita();
        $this->postJson(self::AGENDA."/{$cita->id}/reschedule", ['scheduled_at' => '9999-12-31 23:30'], $s)
            ->assertStatus(422)->assertJsonPath('code', 'scheduled_out_of_range');
        $this->getJson(self::AGENDA.'?date_from=9999-12-31', $s)->assertStatus(422);
    }

    /**
     * Los contadores de la agenda vienen del servidor: exactos aunque solo se haya
     * cargado una página, iguales con o sin el filtro de estado, y dentro del
     * alcance de quien mira (un asesor no cuenta las citas de otro).
     */
    public function test_22_los_contadores_por_estado_son_exactos_y_respetan_el_alcance(): void
    {
        $this->pideLaVisita(); // 1 solicitada, sin dueño
        $otro = Admin::create(['name' => 'Otro', 'email' => 'o-'.Str::random(6).'@ironbody.test', 'password' => 'x', 'role' => 'Ventas', 'status' => 'active']);
        foreach (range(1, 3) as $i) {
            MarketingAppointment::create(['type' => 'call', 'status' => 'scheduled', 'title' => "Llamada {$i}", 'scheduled_at' => now()->addDays($i)]);
        }
        MarketingAppointment::create(['type' => 'call', 'status' => 'scheduled', 'title' => 'De otro', 'scheduled_at' => now()->addDay(), 'assigned_to_admin_id' => $otro->id]);
        MarketingAppointment::create(['type' => 'call', 'status' => 'cancelled', 'title' => 'Vieja', 'scheduled_at' => now()->subDays(3)]);

        $r = $this->getJson(self::AGENDA.'?per_page=1&status=requested', $this->sesion())->assertOk();
        $this->assertSame(1, $r->json('meta.total'), 'el total sí sigue al filtro de estado');
        $this->assertSame(1, $r->json('meta.counts.requested'));
        $this->assertSame(4, $r->json('meta.counts.scheduled'), 'los contadores no deben depender del filtro de estado ni de la página');
        $this->assertSame(1, $r->json('meta.counts.cancelled'));
        $this->assertSame(0, $r->json('meta.counts.completed'));

        // Con «Desde», cuentan solo las de ese rango.
        $desde = now()->setTimezone('America/Bogota')->toDateString();
        $this->assertSame(0, $this->getJson(self::AGENDA.'?date_from='.$desde, $this->sesion())->assertOk()->json('meta.counts.cancelled'));

        // Un asesor no ve, ni cuenta, la cita asignada a otro.
        $asesor = $this->sesion('Ventas', ['marketing.view', 'marketing.manage']);
        $this->assertSame(3, $this->getJson(self::AGENDA, $asesor)->assertOk()->json('meta.counts.scheduled'));
    }

    /**
     * El equipo agendó la visita desde ESTA conversación; la persona vuelve por
     * una conversación NUEVA y la mueve; el equipo la cancela. En la nueva, la
     * guarda tiene que seguir sabiendo que hubo una cortesía, con fecha y sin ella.
     */
    public function test_23_la_guarda_reconoce_la_cortesia_movida_desde_otra_conversacion(): void
    {
        $s = $this->sesion();
        $id = $this->postJson(self::AGENDA, [
            'type' => 'visit', 'title' => 'Visita', 'scheduled_at' => '2026-09-22T18:00:00-05:00',
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
        ], $s)->assertCreated()->json('data.id');

        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::DISCOVERY]);
        $this->turno('mejor el sábado a las 10', 'w.o2.otra1', ['courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00'])->assertOk();
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, MarketingAppointment::findOrFail($id)->status, 'ULTRON no movió la visita del equipo');
        $this->postJson(self::AGENDA."/{$id}/cancel", [], $s)->assertOk();

        $this->soloTexto('¡Listo! Nos vemos el sábado a las 10.', 'w.o2.otra2');
        $this->assertStringNotContainsString('nos vemos el sábado a las 10', $this->ultimoSaliente(), 'con fecha: se prometió una visita cancelada');
        $this->soloTexto('Te esperamos, ya está todo listo.', 'w.o2.otra3');
        $this->assertStringNotContainsString('te esperamos', $this->ultimoSaliente(), 'sin fecha: se prometió una visita cancelada');
    }

    /** ULTRON cancela, desde una conversación NUEVA, la solicitud nacida en otra: la nueva también sabe que hubo cortesía. */
    public function test_23b_cancelar_desde_otra_conversacion_tambien_cuenta_para_la_guarda(): void
    {
        $cita = $this->pideLaVisita();

        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::DISCOVERY]);
        $this->turno('ya no voy a poder ir, cancela', 'w.o2.otraCancela', [
            'courtesy_action' => 'cancel', 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Sin problema, cancelé la solicitud.',
        ])->assertOk();
        $this->assertSame(MarketingAppointment::STATUS_CANCELLED, $cita->fresh()->status);

        $this->soloTexto('Te esperamos, ya está todo listo.', 'w.o2.otraCancela2');
        $this->assertStringNotContainsString('te esperamos', $this->ultimoSaliente(), 'se prometió la visita que la persona acaba de cancelar');
    }

    /** La persona solo repite la hora de la visita del equipo (no-op); el equipo la cancela: la guarda lo sabe. */
    public function test_24_repetir_la_hora_de_la_visita_del_equipo_tambien_cuenta_para_la_guarda(): void
    {
        $s = $this->sesion();
        $id = $this->postJson(self::AGENDA, [
            'type' => 'visit', 'title' => 'Visita', 'scheduled_at' => '2026-09-22T18:00:00-05:00',
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
        ], $s)->assertCreated()->json('data.id');

        $this->turno('perfecto, nos vemos mañana a las 6 de la tarde', 'w.o2.rep1', ['courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00'])->assertOk();
        $this->assertSame(MarketingAppointment::STATUS_SCHEDULED, MarketingAppointment::findOrFail($id)->status, 'repetir la hora des-confirmó la visita');
        $this->postJson(self::AGENDA."/{$id}/cancel", [], $s)->assertOk();

        $this->soloTexto('Te esperamos, ya está todo listo.', 'w.o2.rep2');
        $this->assertStringNotContainsString('te esperamos', $this->ultimoSaliente(), 'se prometió una visita cancelada');
    }

    /** Confirmar es comprometerse con la fecha que se VIO: si ULTRON la movió entre tanto, 409 y sigue solicitada. */
    public function test_25_confirmar_exige_que_la_cita_siga_en_la_fecha_que_se_vio(): void
    {
        $s = $this->sesion();
        $cita = $this->pideLaVisita();
        $vista = $cita->scheduled_at->toIso8601String();
        $this->turno('mejor el miércoles a las 6 de la tarde', 'w.o2.mueve', ['courtesy_date' => '2026-09-23', 'courtesy_time' => '18:00'])->assertOk();

        $this->postJson(self::AGENDA."/{$cita->id}/confirm", ['expected_scheduled_at' => $vista], $s)
            ->assertStatus(409)->assertJsonPath('code', 'appointment_changed');
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->fresh()->status);

        // La fecha nueva, en hora de Bogotá y sin desfase, como la manda el CRM: confirma.
        $nueva = $cita->fresh()->scheduled_at->copy()->setTimezone('America/Bogota')->format('Y-m-d\TH:i');
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", ['expected_scheduled_at' => $nueva], $s)
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        // Un cliente viejo, sin precondición, sigue funcionando: idempotente.
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $s)->assertOk()->assertJsonPath('changed', false);
    }

    /** Reprogramar desde el panel con la fecha que se vio al abrirlo: si ULTRON la movió entre tanto, 409 y no se pisa el día que pidió la persona. */
    public function test_25b_reprogramar_exige_la_fecha_que_se_vio(): void
    {
        $s = $this->sesion();
        $cita = $this->pideLaVisita();
        $vista = $cita->scheduled_at->toIso8601String();
        $this->turno('mejor el miércoles a las 6 de la tarde', 'w.o2.mueve2', ['courtesy_date' => '2026-09-23', 'courtesy_time' => '18:00'])->assertOk();
        $pedida = $cita->fresh()->scheduled_at->toIso8601String();

        $this->postJson(self::AGENDA."/{$cita->id}/reschedule", ['scheduled_at' => '2026-09-24T09:00:00-05:00', 'expected_scheduled_at' => $vista], $s)
            ->assertStatus(409)->assertJsonPath('code', 'appointment_changed');
        $this->assertSame($pedida, $cita->fresh()->scheduled_at->toIso8601String(), 'se pisó el día que pidió la persona');

        $this->postJson(self::AGENDA."/{$cita->id}/reschedule", ['scheduled_at' => '2026-09-24T09:00:00-05:00', 'expected_scheduled_at' => $pedida], $s)->assertOk();
        // Un cliente viejo, sin precondición, sigue pudiendo reprogramar.
        $this->postJson(self::AGENDA."/{$cita->id}/reschedule", ['scheduled_at' => '2026-09-24T10:00:00-05:00'], $s)->assertOk();
    }

    /**
     * La puerta genérica (PATCH) no puede cambiar la fecha ni confirmar sin decir
     * qué fecha se vio: un formulario desactualizado no deshace, sin aviso, el día
     * que pidió la persona ni confirma uno que nadie revisó.
     */
    public function test_25c_el_patch_que_toca_la_fecha_o_confirma_exige_la_fecha_vista(): void
    {
        $s = $this->sesion();
        $cita = $this->pideLaVisita();
        $vista = $cita->scheduled_at->toIso8601String();

        $this->patchJson(self::AGENDA."/{$cita->id}", ['scheduled_at' => $vista, 'status' => 'scheduled'], $s)
            ->assertStatus(422)->assertJsonPath('code', 'expected_scheduled_at_required');
        $this->patchJson(self::AGENDA."/{$cita->id}", ['status' => 'scheduled'], $s)
            ->assertStatus(422)->assertJsonPath('code', 'expected_scheduled_at_required');

        // ULTRON la mueve; el formulario viejo, con su fecha y la que vio, no la devuelve ni la confirma.
        $this->turno('mejor el miércoles a las 6 de la tarde', 'w.o2.mueve3', ['courtesy_date' => '2026-09-23', 'courtesy_time' => '18:00'])->assertOk();
        $pedida = $cita->fresh()->scheduled_at->toIso8601String();
        $this->patchJson(self::AGENDA."/{$cita->id}", ['scheduled_at' => $vista, 'status' => 'scheduled', 'expected_scheduled_at' => $vista], $s)
            ->assertStatus(409)->assertJsonPath('code', 'appointment_changed');
        $this->patchJson(self::AGENDA."/{$cita->id}", ['scheduled_at' => $vista, 'notes' => 'x', 'expected_scheduled_at' => $vista], $s)
            ->assertStatus(409)->assertJsonPath('code', 'appointment_changed');
        $this->assertSame($pedida, $cita->fresh()->scheduled_at->toIso8601String());
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->fresh()->status);

        // Con la fecha que de verdad tiene, sí; y lo que no toca la fecha no la exige.
        $this->patchJson(self::AGENDA."/{$cita->id}", ['status' => 'scheduled', 'expected_scheduled_at' => $pedida], $s)
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->patchJson(self::AGENDA."/{$cita->id}", ['notes' => 'Trae ropa cómoda'], $s)->assertOk();
    }

    /**
     * La agenda se relee con cada aviso del canal y con «Cargar más»: esas
     * lecturas van con su propio limitador por credencial del admin y no gastan
     * el cubo por IP del login de toda la oficina. Y la agenda sigue limitada.
     */
    public function test_27_releer_la_agenda_no_gasta_el_cubo_del_login(): void
    {
        $s = $this->sesion();
        foreach (range(1, 9) as $i) {
            $this->getJson(self::AGENDA, $s)->assertOk();
        }
        Admin::create(['name' => 'Recepción', 'email' => 'login-o2@ironbody.test', 'password' => 'super-secret', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active']);
        $this->postJson('/api/admin/auth/login', ['email' => 'login-o2@ironbody.test', 'password' => 'super-secret'])->assertOk();

        // Su propio límite, por admin: 120 por minuto, y con un mensaje en español.
        foreach (range(1, 111) as $i) {
            $this->getJson(self::AGENDA.'/capabilities', $s)->assertOk();
        }
        $this->getJson(self::AGENDA.'/capabilities', $s)->assertStatus(429)->assertJsonPath('code', 'too_many_agenda_requests');
        $this->getJson(self::AGENDA.'/capabilities', $this->sesion())->assertOk(); // otra sesión, otro cubo
    }

    /** El motivo de cancelar cabe en su columna: por HTTP se valida, y por dentro se recorta sin tumbar la cancelación. */
    public function test_28_el_motivo_de_cancelar_cabe_en_su_columna(): void
    {
        $s = $this->sesion();
        $cita = $this->pideLaVisita();
        $this->postJson(self::AGENDA."/{$cita->id}/cancel", ['reason' => str_repeat('a', 256)], $s)->assertStatus(422);
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->fresh()->status);

        app(MarketingAppointmentService::class)->cancel($cita, str_repeat('ñ', 300));
        $this->assertSame(MarketingAppointment::STATUS_CANCELLED, $cita->fresh()->status);
        $this->assertSame(255, mb_strlen((string) $cita->fresh()->cancellation_reason));
    }

    // ── La fecha y la hora que dice ULTRON son las de la fila ───────────────

    /** El borrador y la fila guardada, tras un turno con la herramienta de cortesía. */
    private function trasElTurno(string $texto, string $wamid, array $propuesta): array
    {
        $this->turno($texto, $wamid, $propuesta)->assertOk();
        $cita = MarketingAppointment::query()->where('marketing_lead_id', $this->lead->id)->latest('updated_at')->latest('id')->first();

        return [$this->ultimoSaliente(), $cita?->scheduled_at?->copy()->setTimezone('America/Bogota')->format('Y-m-d H:i')];
    }

    /** Medido en el canario (2026-10-04): la fila a las 18:00 y la respuesta «a las 6:00 a. m.». Sale la hora de la fila. */
    public function test_29_registrada_a_las_18_ultron_dice_6_pm_aunque_el_borrador_diga_6_am(): void
    {
        [$sale, $fila] = $this->trasElTurno('quiero ir mañana a las 6 de la tarde', 'w.o2.h1', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
            'reply_draft' => 'Perfecto, te registro la visita de cortesía para mañana a las 6:00 a. m.',
        ]);
        $this->assertSame('2026-09-22 18:00', $fila);
        $this->assertStringNotContainsString('6:00 a. m.', $sale, 'salió una hora que no es la de la cita guardada');
        $this->assertStringContainsString('martes 22 de septiembre a las 6:00 p. m.', $sale);
        $this->assertStringNotContainsString('confirmad', $sale, 'una solicitud no se cuenta como confirmada');
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, MarketingAppointment::query()->where('marketing_lead_id', $this->lead->id)->sole()->status);
    }

    public function test_29b_registrada_a_las_06_ultron_dice_6_am_aunque_el_borrador_diga_6_pm(): void
    {
        [$sale, $fila] = $this->trasElTurno('quiero ir mañana a las 6 de la mañana', 'w.o2.h2', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '06:00',
            'reply_draft' => 'Listo, te registro la visita de cortesía para mañana a las 6:00 p. m.',
        ]);
        $this->assertSame('2026-09-22 06:00', $fila);
        $this->assertStringNotContainsString('6:00 p. m.', $sale);
        $this->assertStringContainsString('martes 22 de septiembre a las 6:00 a. m.', $sale);
    }

    /** Si el borrador ya dice la verdad —con la hora exacta o con un «a las 6» que admite las 18:00—, sale intacto. */
    public function test_29c_un_borrador_que_casa_con_la_fila_sale_intacto(): void
    {
        [$sale] = $this->trasElTurno('quiero ir mañana a las 6 de la tarde', 'w.o2.h3', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
            'reply_draft' => 'Listo, te registro la visita de cortesía para mañana a las 6:00 p. m. Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m.',
        ]);
        $this->assertStringContainsString('te registro la visita de cortesía para mañana a las 6:00 p. m.', $sale);
        $this->assertStringContainsString('abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m.', $sale, 'se retiró el horario, que no habla de la visita');
        $this->assertStringNotContainsString('dejé registrada tu solicitud', $sale, 'se añadió un acta sin que nada mintiera');

        MarketingAppointment::query()->delete();
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::DISCOVERY]);
        [$sale2] = $this->trasElTurno('quiero ir mañana a las 6 de la tarde', 'w.o2.h3b', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
            'reply_draft' => 'Listo, te registro la visita de cortesía para mañana a las 6.',
        ]);
        $this->assertStringContainsString('te registro la visita de cortesía para mañana a las 6', $sale2);
        $this->assertStringNotContainsString('dejé registrada tu solicitud', $sale2);
    }

    public function test_29d_un_dia_que_no_es_el_de_la_fila_tampoco_sale(): void
    {
        [$sale] = $this->trasElTurno('quiero ir mañana a las 6 de la tarde', 'w.o2.h4', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
            'reply_draft' => 'Perfecto, te registro la visita de cortesía para el miércoles a las 6:00 p. m.',
        ]);
        $this->assertStringNotContainsString('miércoles', $sale);
        $this->assertStringContainsString('martes 22 de septiembre a las 6:00 p. m.', $sale);
    }

    /** Al MOVER la visita, la respuesta dice el día y la hora nuevos de la fila. */
    public function test_29e_al_reprogramar_sale_el_dia_y_la_hora_nuevos_de_la_fila(): void
    {
        $this->pideLaVisita();
        [$sale, $fila] = $this->trasElTurno('mejor el miércoles a las 7 de la noche', 'w.o2.h5', [
            'courtesy_date' => '2026-09-23', 'courtesy_time' => '19:00',
            'reply_draft' => 'Listo, moví tu visita de cortesía para el jueves a las 7:00 p. m.',
        ]);
        $this->assertSame('2026-09-23 19:00', $fila);
        $this->assertStringNotContainsString('jueves', $sale);
        $this->assertStringContainsString('miércoles 23 de septiembre a las 7:00 p. m.', $sale);
        $this->assertSame(1, $this->activas());
    }

    /** Al CANCELAR, la respuesta nombra la visita que de verdad se canceló. */
    public function test_29f_al_cancelar_sale_la_visita_que_de_verdad_se_cancelo(): void
    {
        $cita = $this->pideLaVisita();
        $this->turno('ya no voy a poder ir, cancela', 'w.o2.h6', [
            'courtesy_action' => 'cancel', 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Sin problema, cancelé tu visita del miércoles a las 7:00 p. m.',
        ])->assertOk();
        $sale = $this->ultimoSaliente();
        $this->assertSame(MarketingAppointment::STATUS_CANCELLED, $cita->fresh()->status);
        $this->assertStringNotContainsString('miércoles', $sale);
        $this->assertStringContainsString('cancelé tu solicitud de visita para el martes 22 de septiembre a las 6:00 p. m.', $sale);
    }

    // ── Lo que dice ULTRON = lo que ejecutó Laravel = lo que enseña la agenda ─

    private function horaLocal(MarketingAppointment $cita): string
    {
        return $cita->fresh()->scheduled_at->copy()->setTimezone('America/Bogota')->format('Y-m-d H:i');
    }

    /**
     * Medido en el canario (2026-10-04): la visita a las 18:00, «Mejor mañana a
     * las 7», el modelo NO pidió la herramienta y escribió «cambio la visita…
     * 7:00 a. m.». No se mueve, no se afirma, se dice el estado real y se pregunta.
     */
    public function test_31_sin_herramienta_no_se_afirma_el_cambio_y_se_pregunta_la_hora(): void
    {
        $cita = $this->pideLaVisita();
        $version = MarketingAgendaVersion::current();

        $this->turno('Mejor mañana a las 7', 'w.o2.amb1', [
            'tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Perfecto, cambio la visita de cortesía para mañana a las 7:00 a. m. para que puedas venir a conocer el gimnasio.',
        ])->assertOk();

        $sale = $this->ultimoSaliente();
        $this->assertStringNotContainsString('cambio la visita', $sale, 'afirmó un cambio que nadie hizo');
        $this->assertStringContainsString('sigue registrada para el martes 22 de septiembre a las 6:00 p. m.', $sale);
        $this->assertStringContainsString('¿te refieres a las 7:00 a. m. o a las 7:00 p. m.?', $sale);
        $this->assertStringNotContainsString('p. m..', $sale);
        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita));
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->fresh()->status);
        $this->assertSame($version, MarketingAgendaVersion::current(), 'la agenda avisó un cambio que no hubo');
        $this->assertSame(['kind' => 'courtesy_request', 'status' => 'requested', 'cita' => $cita->id, 'pendiente' => ['2026-09-22', ['07:00', '19:00']]], [
            'kind' => data_get($this->conversation->fresh()->memory, 'active_goal.kind'),
            'status' => data_get($this->conversation->fresh()->memory, 'active_goal.status'),
            'cita' => data_get($this->conversation->fresh()->memory, 'active_goal.data.appointment_id'),
            'pendiente' => [
                data_get($this->conversation->fresh()->memory, 'active_goal.data.pending_hour.date'),
                data_get($this->conversation->fresh()->memory, 'active_goal.data.pending_hour.options'),
            ],
        ], 'el objetivo de la visita no se conservó con la aclaración pendiente');
    }

    /** El modelo SÍ pide la herramienta, pero con una hora ambigua: no se registra nada, se pregunta. */
    public function test_31b_con_herramienta_y_hora_ambigua_no_se_mueve_y_se_pregunta(): void
    {
        $cita = $this->pideLaVisita();

        $this->turno('Mejor mañana a las 7', 'w.o2.amb2', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '07:00',
            'reply_draft' => 'Listo, te cambio la visita para mañana a las 7:00 a. m.',
        ])->assertOk();

        $sale = $this->ultimoSaliente();
        $this->assertStringNotContainsString('te cambio la visita', $sale);
        $this->assertStringContainsString('sigue registrada para el martes 22 de septiembre a las 6:00 p. m.', $sale);
        $this->assertStringContainsString('¿te refieres a las 7:00 a. m. o a las 7:00 p. m.?', $sale);
        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita));
        $this->assertSame(1, $this->activas());
    }

    /** Aclarada la hora («7 p. m.»), la MISMA cita pasa a las 19:00 y ULTRON dice exactamente «7:00 p. m.». */
    public function test_31c_aclarada_la_hora_se_mueve_la_misma_cita_y_se_dice_7_pm(): void
    {
        $cita = $this->pideLaVisita();
        $this->turno('Mejor mañana a las 7', 'w.o2.amb3', [
            'tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Perfecto, cambio la visita para mañana a las 7:00 a. m.',
        ])->assertOk();
        $version = MarketingAgendaVersion::current();

        // El día no viene en este mensaje: sale del que quedó en recogida.
        $this->turno('7 p. m.', 'w.o2.amb4', [
            'courtesy_date' => null, 'courtesy_time' => '19:00',
            'reply_draft' => 'Listo, tu visita de cortesía quedó solicitada para mañana a las 7:00 p. m.; el equipo la confirma.',
        ])->assertOk();

        $sale = $this->ultimoSaliente();
        $this->assertSame('2026-09-22 19:00', $this->horaLocal($cita), 'no se movió la misma cita');
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->fresh()->status);
        $this->assertSame(1, $this->activas(), 'quedó una segunda cita');
        $this->assertStringContainsString('7:00 p. m.', $sale);
        $this->assertStringNotContainsString('a. m.', $sale);
        $this->assertGreaterThan($version, MarketingAgendaVersion::current(), 'la agenda no se enteró del cambio');
    }

    /** «mañana a las 7 pm» no es ambiguo: se registra a las 19:00. «de la mañana», a las 07:00. */
    public function test_31d_una_hora_sin_dudas_se_registra_tal_cual(): void
    {
        $this->turno('quiero ir mañana a las 7 pm', 'w.o2.amb5', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '19:00',
            'reply_draft' => 'Listo, dejé solicitada tu visita para mañana a las 7:00 p. m.; el equipo la confirma.',
        ])->assertOk();
        $cita = MarketingAppointment::query()->where('marketing_lead_id', $this->lead->id)->sole();
        $this->assertSame('2026-09-22 19:00', $this->horaLocal($cita));
        $this->assertStringContainsString('7:00 p. m.', $this->ultimoSaliente());
    }

    /** Una solicitud NUEVA con hora ambigua («mañana a las 6») tampoco se registra: se pregunta. */
    public function test_31e_una_solicitud_nueva_con_hora_ambigua_se_pregunta(): void
    {
        $this->turno('Quiero ir mañana a las 6', 'w.o2.amb6', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
            'reply_draft' => 'Perfecto, te registro la visita de cortesía para mañana a las 6:00 a. m.',
        ])->assertOk();

        $sale = $this->ultimoSaliente();
        $this->assertSame(0, MarketingAppointment::count(), 'se registró una hora que la persona no aclaró');
        $this->assertStringNotContainsString('te registro la visita', $sale);
        $this->assertStringContainsString('¿te refieres a las 6:00 a. m. o a las 6:00 p. m.?', $sale);
    }

    /** Con la hora ambigua, una constancia de ESTADO («queda registrada tu solicitud…») tampoco tumba el turno: se pregunta. */
    public function test_31h_una_constancia_con_hora_ambigua_se_reescribe_y_pregunta(): void
    {
        $this->turno('Quiero ir mañana a las 6', 'w.o2.amb9', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
            'reply_draft' => 'Listo, queda registrada tu solicitud de día de cortesía. El equipo la revisará.',
        ])->assertOk();

        $sale = $this->ultimoSaliente();
        $this->assertSame(0, MarketingAppointment::count());
        $this->assertStringNotContainsString('queda registrada', $sale);
        $this->assertStringContainsString('¿te refieres a las 6:00 a. m. o a las 6:00 p. m.?', $sale);
    }

    /** «Te la cancelo» sin nada que cancelar: no se afirma, se dice la verdad. */
    public function test_31f_cancelar_sin_nada_que_cancelar_no_se_afirma(): void
    {
        $this->turno('cancela mi visita', 'w.o2.amb7', [
            'courtesy_action' => 'cancel', 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Listo, te la cancelo.',
        ])->assertOk();
        $sale = $this->ultimoSaliente();
        $this->assertStringNotContainsString('cancelo', $sale);
        $this->assertStringContainsString('no encontré ninguna visita pendiente', $sale);
    }

    /** Si además promete OTRO efecto sin autoridad («lo dejo escalado»), el turno sigue cayendo en 422. */
    public function test_31g_otro_efecto_sin_autoridad_sigue_cayendo(): void
    {
        $this->pideLaVisita();
        // Con la hora sin dudas el turno no es de Laravel: va por el camino normal y su invariante.
        $this->turno('Mejor mañana a las 7 pm', 'w.o2.amb8', [
            'tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Cambio la visita para mañana a las 7 pm. Lo dejo escalado al equipo.',
        ])->assertStatus(422)->assertJsonPath('code', 'promised_effect_without_authority');
    }

    // ── La hora de la visita la decide Laravel, no la etiqueta del modelo ────

    /** El critic del canario (2026-10-04, ai#3215): segundo intento, suspenso. */
    private const CRITIC_CAIDO = ['critic' => ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3, 'issues' => ['context_use', 'question_discipline', 'phase_alignment'], 'hard_fail' => null]];

    /** La propuesta del canario: etiqueta `goal_fat_loss`, sin herramienta, y el borrador del Composer Reintento. */
    private const COMO_EL_CANARIO = [
        'strategy_goal' => 'collect_data', 'intent' => SalesIntents::GOAL_FAT_LOSS, 'next_best_action' => 'ask_discovery',
        'main_barrier' => 'other', 'confidence' => 0.85, 'tools_requested' => [],
        'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
        'reply_draft' => 'Perfecto, entonces te dejo registrada la visita de cortesía para mañana a las 7:00 a. m. mientras tanto, cuéntame qué te ha motivado a buscar bajar grasa y cómo has intentado lograrlo hasta ahora para acompañarte mejor.',
    ];

    private const ACLARACION = 'Tu solicitud de visita sigue registrada para el martes 22 de septiembre a las 6:00 p. m. ¿Te refieres a las 7:00 a. m. o a las 7:00 p. m.?';

    private function ultimoSalienteLiteral(): string
    {
        return (string) MarketingMessage::query()->where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->value('body');
    }

    /** La visita a las 18:00 y, antes, una charla de «bajar grasa» que dejó su pregunta en la memoria. */
    private function visitaConMemoriaDeBajarGrasa(): MarketingAppointment
    {
        $cita = $this->pideLaVisita();
        $this->turno('quiero bajar grasa', 'w.o2.grasa', [
            'intent' => SalesIntents::GOAL_FAT_LOSS, 'tools_requested' => [], 'courtesy_action' => null,
            'courtesy_date' => null, 'courtesy_time' => null, 'next_best_action' => 'ask_discovery',
            'reply_draft' => 'Para bajar grasa lo más importante es empezar con algo que puedas sostener. ¿Ya vienes entrenando o arrancas desde cero?',
        ])->assertOk();

        return $cita;
    }

    /**
     * EL CASO EXACTO DEL CANARIO. Visita a las 18:00, «Mejor mañana a las 7», el
     * modelo etiqueta `goal_fat_loss`, no pide la herramienta y el critic tumba
     * los dos borradores. Salía «Listo. Para bajar grasa…». Sale el estado real
     * y la pregunta; la cita no se toca y el objetivo sigue siendo la visita.
     */
    public function test_32_el_caso_del_canario_pregunta_la_hora_y_no_salta_a_bajar_grasa(): void
    {
        $cita = $this->visitaConMemoriaDeBajarGrasa();
        $version = MarketingAgendaVersion::current();

        $this->turno('Mejor mañana a las 7', 'w.o2.canario', self::COMO_EL_CANARIO, self::CRITIC_CAIDO)->assertOk();

        $this->assertSame(self::ACLARACION, $this->ultimoSalienteLiteral());
        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita), 'la cita se movió sin aclarar la hora');
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->fresh()->status);
        $this->assertSame(1, $this->activas());
        $this->assertSame($version, MarketingAgendaVersion::current(), 'la agenda avisó un cambio que no hubo');

        $objetivo = data_get($this->conversation->fresh()->memory, 'active_goal');
        $this->assertSame(['courtesy_request', 'requested', $cita->id, '18:00'], [$objetivo['kind'], $objetivo['status'], $objetivo['data']['appointment_id'], $objetivo['data']['time']], 'el objetivo de la visita no se conservó');
        $this->assertSame(['date' => '2026-09-22', 'options' => ['07:00', '19:00']], array_intersect_key($objetivo['data']['pending_hour'], ['date' => 1, 'options' => 1]));

        $accion = MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
        $this->assertSame('ask', data_get($accion->metadata, 'visit_hour.mode'));
        $this->assertSame(SalesIntents::GOAL_FAT_LOSS, data_get($accion->metadata, 'model_intent'), 'la etiqueta del modelo no quedó de evidencia');
        $this->assertNotSame(SalesIntents::GOAL_FAT_LOSS, data_get($accion->metadata, 'intent'));
        $this->assertSame([], (array) data_get($accion->metadata, 'tools_executed', []), 'se ejecutó una herramienta con la hora sin aclarar');
        $this->assertNotContains('critic_failed', (array) data_get($accion->metadata, 'risk_flags', []));
    }

    /**
     * La memoria comercial anterior no contamina la reprogramación, salga el
     * turno por donde salga: critic aprobado con un borrador que sólo vende
     * «bajar grasa», critic caído o rendición de n8n.
     */
    public function test_32b_la_memoria_de_bajar_grasa_no_contamina_por_ningun_camino(): void
    {
        $cita = $this->visitaConMemoriaDeBajarGrasa();
        $soloGrasa = array_merge(self::COMO_EL_CANARIO, [
            'reply_draft' => 'Para bajar grasa lo más importante es la constancia. ¿Ya vienes entrenando o arrancas desde cero?',
        ]);

        foreach ([
            'critic aprobado' => [$soloGrasa, []],
            'critic caído' => [self::COMO_EL_CANARIO, self::CRITIC_CAIDO],
            'rendición' => [self::COMO_EL_CANARIO, ['recovery' => ['reason' => 'promised_effect_without_authority', 'attempt' => 2]]],
        ] as $camino => [$propuesta, $extra]) {
            $this->turno('Mejor mañana a las 7', 'w.o2.contamina.'.Str::slug($camino), $propuesta, $extra)->assertOk();

            $sale = $this->ultimoSalienteLiteral();
            $this->assertSame(self::ACLARACION, $sale, "por el camino «{$camino}» no salió la aclaración");
            $this->assertStringNotContainsString('grasa', mb_strtolower($sale), "por el camino «{$camino}» volvió «bajar grasa»");
            $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita), "por el camino «{$camino}» se movió la cita");
        }
        $this->assertSame(1, $this->activas());
    }

    /** «Mejor a las 7» sin día: la pregunta es sobre el día de su visita. */
    public function test_32l_sin_dia_se_pregunta_sobre_el_dia_de_la_visita(): void
    {
        $cita = $this->pideLaVisita();

        $this->turno('mejor a las 7', 'w.o2.sindia', self::COMO_EL_CANARIO, self::CRITIC_CAIDO)->assertOk();

        $this->assertSame(self::ACLARACION, $this->ultimoSalienteLiteral());
        $this->assertSame('2026-09-22', data_get($this->conversation->fresh()->memory, 'active_goal.data.pending_hour.date'));
        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita));
    }

    /** Aunque el modelo SÍ pida la herramienta con una lectura (07:00), mientras la hora siga ambigua no se ejecuta nada. */
    public function test_32c_mientras_siga_ambigua_no_se_ejecuta_ninguna_herramienta(): void
    {
        $cita = $this->pideLaVisita();
        $version = MarketingAgendaVersion::current();

        $this->turno('Mejor mañana a las 7', 'w.o2.ambtool', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '07:00',
            'reply_draft' => 'Listo, te cambio la visita para mañana a las 7:00 a. m.',
        ])->assertOk()->assertJsonPath('applied.tools_executed', []);

        $this->assertSame(self::ACLARACION, $this->ultimoSalienteLiteral());
        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita));
        $this->assertSame($version, MarketingAgendaVersion::current());
        $this->assertSame(1, MarketingAppointment::count());
    }

    /**
     * Y la respuesta a la pregunta la resuelve Laravel: «7 p. m.» mueve la MISMA
     * cita a las 19:00 aunque el modelo vuelva a etiquetar `goal_fat_loss`, no
     * pida la herramienta y el critic vuelva a caer. ULTRON dice «7:00 p. m.»,
     * la agenda enseña las 19:00 (y su canal avisa) y no aparece otra cita.
     */
    public function test_32d_despues_7_pm_mueve_la_misma_cita_y_ultron_y_la_agenda_coinciden(): void
    {
        $cita = $this->visitaConMemoriaDeBajarGrasa();
        $this->turno('Mejor mañana a las 7', 'w.o2.canario2', self::COMO_EL_CANARIO, self::CRITIC_CAIDO)->assertOk();
        $version = MarketingAgendaVersion::current();

        $this->turno('7 p. m.', 'w.o2.7pm', array_merge(self::COMO_EL_CANARIO, [
            'reply_draft' => 'Perfecto. Para bajar grasa, ¿ya vienes entrenando o arrancas desde cero?',
        ]), self::CRITIC_CAIDO)->assertOk()->assertJsonPath('applied.tools_executed', [SalesIntents::TOOL_COURTESY_REQUEST]);

        $this->assertSame('2026-09-22 19:00', $this->horaLocal($cita), 'no se movió la misma cita');
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->fresh()->status);
        $this->assertSame(1, $this->activas(), 'apareció una segunda cita');
        $this->assertSame(1, MarketingAppointment::count());
        $this->assertGreaterThan($version, MarketingAgendaVersion::current(), 'el canal de la agenda no avisó');

        $sale = $this->ultimoSalienteLiteral();
        $this->assertSame('Dejé registrada tu solicitud de cortesía para el martes 22 de septiembre a las 7:00 p. m. El equipo de Iron Body la revisará para tener todo preparado.', $sale);
        $this->assertStringNotContainsString('grasa', mb_strtolower($sale));

        // Lo que dice ULTRON = lo que enseña la agenda del CRM.
        $fila = collect($this->getJson(self::AGENDA, $this->sesion())->assertOk()->json('data'))->firstWhere('id', $cita->id);
        $this->assertNotNull($fila, 'la agenda no enseña la cita');
        $enLaAgenda = Carbon::parse($fila['scheduled_at'])->setTimezone(BusinessClock::TZ);
        $this->assertSame('19:00', $enLaAgenda->format('H:i'));
        $this->assertStringContainsString(CourtesyRequestService::horaDicha($enLaAgenda), $sale);

        $objetivo = data_get($this->conversation->fresh()->memory, 'active_goal');
        $this->assertSame(['requested', '19:00'], [$objetivo['status'], $objetivo['data']['time']]);
        $this->assertArrayNotHasKey('pending_hour', $objetivo['data'], 'la aclaración quedó pendiente después de resolverse');
        $accion = MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
        $this->assertSame(['resolve', '19:00'], [data_get($accion->metadata, 'visit_hour.mode'), data_get($accion->metadata, 'visit_hour.chosen')]);
    }

    /** Sin pregunta suya pendiente, Laravel no ejecuta nada por su cuenta: «7 p. m.» a secas sigue el camino normal. */
    public function test_32e_sin_su_pregunta_pendiente_laravel_no_mueve_nada(): void
    {
        $cita = $this->pideLaVisita();

        $this->turno('7 p. m.', 'w.o2.sinpregunta', [
            'tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Cuéntame, ¿qué buscas lograr con tu entrenamiento?',
        ])->assertOk();

        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita));
        $accion = MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
        $this->assertNull(data_get($accion->metadata, 'visit_hour'));
    }

    /**
     * Laravel sólo resuelve la respuesta a SU pregunta: una de las lecturas que
     * ofreció, dicha sin dudas y a tiempo. Otra hora, una pregunta o una
     * respuesta del día siguiente siguen el camino normal.
     */
    public function test_32g_laravel_solo_resuelve_su_propia_pregunta(): void
    {
        $cita = $this->pideLaVisita();
        $sinHerramienta = ['tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null, 'reply_draft' => 'Perfecto.'];
        $this->turno('Mejor mañana a las 7', 'w.o2.propia', self::COMO_EL_CANARIO, self::CRITIC_CAIDO)->assertOk();

        // Repetir la hora ambigua vuelve a preguntar: no se elige una lectura por la persona.
        $this->turno('mañana a las 7', 'w.o2.repite', $sinHerramienta)->assertOk();
        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita), 'se eligió una lectura de una hora que seguía ambigua');
        $this->assertSame(self::ACLARACION, $this->ultimoSalienteLiteral());

        $this->turno('8 pm', 'w.o2.otrahora', $sinHerramienta)->assertOk();
        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita), 'Laravel movió la cita a una hora que no ofreció');

        $this->turno('¿y a las 7 pm sí hay cupo?', 'w.o2.pregunta', $sinHerramienta)->assertOk();
        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita), 'una pregunta se tomó como elección');

        $this->travel(25)->hours();
        $this->turno('7 p. m.', 'w.o2.tarde', $sinHerramienta)->assertOk();
        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita), 'se resolvió una aclaración caducada');
    }

    /** Sin el número no la resuelve Laravel, pero si el modelo pide la herramienta el día sale de la aclaración pendiente. */
    public function test_32h_el_modelo_puede_resolverla_con_el_dia_pendiente(): void
    {
        $cita = $this->pideLaVisita();
        $this->turno('Mejor mañana a las 7', 'w.o2.pend1', self::COMO_EL_CANARIO, self::CRITIC_CAIDO)->assertOk();

        $this->turno('la de la noche', 'w.o2.pend2', [
            'courtesy_date' => null, 'courtesy_time' => '19:00',
            'reply_draft' => 'Listo, tu visita de cortesía quedó solicitada para mañana a las 7:00 p. m.; el equipo la confirma.',
        ])->assertOk();

        $this->assertSame('2026-09-22 19:00', $this->horaLocal($cita));
        $this->assertSame(1, $this->activas());
    }

    /** Sin visita todavía: la pregunta de la herramienta también queda pendiente, y «6 pm» la resuelve Laravel. */
    public function test_32i_una_solicitud_nueva_ambigua_se_resuelve_con_la_respuesta(): void
    {
        $this->turno('Quiero ir mañana a las 6', 'w.o2.nueva1', [
            'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
            'reply_draft' => 'Perfecto, te registro la visita de cortesía para mañana a las 6:00 a. m.',
        ])->assertOk();
        $this->assertSame(0, MarketingAppointment::count());
        $this->assertSame(['2026-09-22', ['06:00', '18:00']], [
            data_get($this->conversation->fresh()->memory, 'active_goal.data.pending_hour.date'),
            data_get($this->conversation->fresh()->memory, 'active_goal.data.pending_hour.options'),
        ]);

        $this->turno('6 pm', 'w.o2.nueva2', ['tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null, 'reply_draft' => 'Perfecto.'])->assertOk();

        $cita = MarketingAppointment::query()->where('marketing_lead_id', $this->lead->id)->sole();
        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita));
        $this->assertStringContainsString('a las 6:00 p. m.', $this->ultimoSalienteLiteral());
    }

    /** La hora que se recogía antes no sobrevive a la aclaración: la hora es justo lo que se pregunta. */
    public function test_32j_la_hora_recogida_antes_no_sobrevive_a_la_aclaracion(): void
    {
        app(ConversationMemoryService::class)->recordCourtesyCollecting($this->conversation->fresh(), ['time' => '18:00']);

        $this->turno('mañana a las 7', 'w.o2.recog', ['tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null, 'reply_draft' => 'Perfecto.'])->assertOk();

        $data = (array) data_get($this->conversation->fresh()->memory, 'active_goal.data');
        $this->assertSame(['2026-09-22', null], [$data['date'] ?? null, $data['time'] ?? null]);
        $this->assertSame(['07:00', '19:00'], data_get($data, 'pending_hour.options'));
    }

    /** Con otro tema la pregunta la hace la guarda del camino normal, y la visita solicitada tampoco pierde su objetivo. */
    public function test_32k_la_guarda_del_camino_normal_tambien_conserva_el_objetivo(): void
    {
        $cita = $this->pideLaVisita();

        $this->turno('Mejor mañana a las 7, hasta qué hora abren?', 'w.o2.guardaobj', [
            'tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. Perfecto, cambio la visita para mañana a las 7:00 a. m.',
        ])->assertOk();

        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita));
        $objetivo = data_get($this->conversation->fresh()->memory, 'active_goal');
        $this->assertSame(['requested', $cita->id], [$objetivo['status'], $objetivo['data']['appointment_id'] ?? null]);
        $this->assertSame(['07:00', '19:00'], data_get($objetivo, 'data.pending_hour.options'));
    }

    /** Lo que no es la hora de la visita no se secuestra: otra pregunta, una queja o una derivación siguen su camino. */
    public function test_32f_otro_tema_o_el_opt_out_no_se_secuestran(): void
    {
        $cita = $this->pideLaVisita();

        foreach ([
            'precio' => 'Mejor mañana a las 7, y cuánto vale?',
            'precio en duda' => 'Mejor mañana a las 7, de verdad cuesta eso?',
            'horario' => 'Mejor mañana a las 7, hasta qué hora abren?',
            'planes' => 'Mejor mañana a las 7, qué planes tienen?',
            'un plan' => 'Mejor mañana a las 7, me interesa el Plan Mensual',
        ] as $tema => $texto) {
            $this->turno($texto, 'w.o2.otrotema.'.Str::slug($tema), [
                'tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
                'intent' => SalesIntents::GENERAL_INFO, 'reply_draft' => 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m.',
            ]);
            $accion = MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->first();
            $this->assertNull(data_get($accion?->metadata, 'visit_hour'), "se secuestró una pregunta de {$tema}");
        }

        $this->turno('Mejor mañana a las 7', 'w.o2.queja', [
            'tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
            'intent' => SalesIntents::COMPLAINT, 'reply_draft' => 'Lamento lo ocurrido. Lo dejo marcado para revisión del equipo.',
        ]);
        $accion = MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->first();
        $this->assertNull(data_get($accion?->metadata, 'visit_hour'), 'se secuestró una queja');

        // Una derivación propuesta va a su cerrojo, que la rechaza: el turno no es de Laravel.
        $this->turno('Mejor mañana a las 7', 'w.o2.humano', [
            'tools_requested' => [], 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
            'human_handoff_requested' => true, 'reply_draft' => 'Te paso con una persona del equipo.',
        ])->assertStatus(422)->assertJsonPath('code', 'unauthorized_handoff');

        $this->assertSame('2026-09-22 18:00', $this->horaLocal($cita));
    }

    /**
     * Pedir que no le escriban tampoco se secuestra, lo diga la etiqueta o lo
     * pida la herramienta: cada una basta, como en el resto del commit.
     *
     * @return array<string,array{0:string,1:array<int,string>}>
     */
    public static function optOuts(): array
    {
        return [
            'por la etiqueta' => [SalesIntents::DO_NOT_CONTACT_REQUEST, []],
            'por la herramienta' => [SalesIntents::GENERAL_INFO, [SalesIntents::TOOL_MARK_DNC]],
        ];
    }

    #[DataProvider('optOuts')]
    public function test_32m_el_opt_out_no_se_secuestra(string $intent, array $herramientas): void
    {
        $this->pideLaVisita();

        $this->turno('no me escriban más, mejor a las 7', 'w.o2.dnc', [
            'tools_requested' => $herramientas, 'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
            'intent' => $intent, 'reply_draft' => 'Entendido, no te escribiremos más.',
        ]);
        $accion = MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
        $this->assertNull(data_get($accion->metadata, 'visit_hour'), 'se secuestró una petición de no escribir');
    }

    /** Una visita heredada en estado «reprogramada» está en firme: ULTRON la mueve, no abre otra encima. */
    public function test_26_una_visita_heredada_reprogramada_cuenta_como_confirmada(): void
    {
        $vieja = MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
            'type' => 'visit', 'status' => MarketingAppointment::STATUS_RESCHEDULED, 'title' => 'Visita', 'scheduled_at' => '2026-09-22 23:00:00',
        ]);
        $this->turno('mejor el miércoles a las 6 de la tarde', 'w.o2.resch', ['courtesy_date' => '2026-09-23', 'courtesy_time' => '18:00'])->assertOk();

        $this->assertSame(1, $this->activas(), 'abrió otra solicitud encima de la visita reprogramada');
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $vieja->fresh()->status);
    }

    // ── 9 · SSE cuando crea ULTRON ───────────────────────────────────────────

    public function test_9_el_canal_de_la_agenda_avisa_cuando_ultron_crea_una_solicitud(): void
    {
        $antes = MarketingAgendaVersion::current();
        $this->pideLaVisita();

        $this->assertGreaterThan($antes, MarketingAgendaVersion::current(), 'la agenda abierta no se habría enterado');

        $r = $this->get(self::AGENDA.'/stream', $this->sesion());
        $r->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $r->headers->get('Content-Type'));
    }

    /** El canal va fuera del limitador anónimo por IP: reconectar no gasta el cubo de nadie. */
    public function test_9b_escuchar_la_agenda_no_gasta_el_cubo_del_limitador(): void
    {
        $s = $this->sesion();
        for ($i = 0; $i < 125; $i++) {
            $this->get(self::AGENDA.'/stream', $s)->assertOk();
        }
        $this->getJson(self::AGENDA, $s)->assertOk();
    }

    // ── 10 · SSE cuando otra sesión cambia ───────────────────────────────────

    public function test_10_el_canal_avisa_cuando_otra_sesion_confirma_o_cancela_y_no_por_un_doble_clic(): void
    {
        $cita = $this->pideLaVisita();
        $s = $this->sesion();

        $v0 = MarketingAgendaVersion::current();
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $s)->assertOk();
        $v1 = MarketingAgendaVersion::current();
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $s)->assertOk();
        $v2 = MarketingAgendaVersion::current();
        $this->postJson(self::AGENDA."/{$cita->id}/cancel", [], $s)->assertOk();

        $this->assertGreaterThan($v0, $v1, 'confirmar no movió la versión');
        $this->assertSame($v1, $v2, 'un doble clic sin efecto movió la versión');
        $this->assertGreaterThan($v2, MarketingAgendaVersion::current(), 'cancelar no movió la versión');
    }

    // ── 11 · agente pausado ──────────────────────────────────────────────────

    public function test_11_con_el_agente_pausado_no_nace_ninguna_cita_automatica(): void
    {
        $this->pausar();

        $this->turno('quiero ir mañana a las 6 de la tarde', 'w.o2.pausa', ['courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00']);

        $this->assertSame(1, MarketingMessage::query()->where('meta_message_id', 'w.o2.pausa')->count(), 'el mensaje tiene que quedar guardado');
        $this->assertSame(0, MarketingAppointment::count(), 'con el agente pausado nació una cita automática');

        // Una persona sí opera la agenda a mano.
        $this->postJson(self::AGENDA, [
            'type' => 'visit', 'title' => 'Visita', 'scheduled_at' => '2026-09-22T18:00:00-05:00', 'marketing_lead_id' => $this->lead->id,
        ], $this->sesion())->assertCreated()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.source', 'crm');
    }

    /** La pausa que llega entre las cabeceras del commit y la herramienta tampoco deja escribir. */
    public function test_11b_la_pausa_a_mitad_del_commit_tampoco_crea_la_cita(): void
    {
        MarketingAiAction::created(fn () => $this->pausar());

        $this->turno('quiero ir mañana a las 6 de la tarde', 'w.o2.mitad', ['courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00']);

        $this->assertSame(0, MarketingAppointment::count(), 'la pausa a mitad del commit dejó una cita automática');
    }

    public function test_11c_la_herramienta_del_motor_comercial_respeta_la_pausa_y_no_duplica_una_solicitud(): void
    {
        $herramienta = app(BookAppointmentTool::class);
        $contexto = new ToolContext(lead: $this->lead, requestedBy: 'engine', correlationId: 'o2-motor');
        $argumentos = ['type' => 'visit', 'scheduled_at' => '2026-09-23 18:00'];

        // Con una visita SOLICITADA viva, no agenda otra encima.
        $this->pideLaVisita();
        $r = $herramienta->execute($argumentos, $contexto);
        $this->assertSame('skipped', $r->status, 'la herramienta no vio la solicitud viva');
        $this->assertSame(1, $this->activas(), 'la herramienta agendó encima de una solicitud viva');

        // Y pausada no escribe nada.
        MarketingAppointment::query()->update(['status' => MarketingAppointment::STATUS_CANCELLED]);
        $this->pausar();
        $r = $herramienta->execute($argumentos, $contexto);
        $this->assertSame('skipped', $r->status);
        $this->assertSame('agent_paused', $r->data['reason'] ?? null, 'con el agente pausado la herramienta escribió en la agenda');
        $this->assertSame(0, $this->activas());
    }

    // ── 12 · permisos ────────────────────────────────────────────────────────

    public function test_12_quien_puede_ver_y_operar_la_agenda(): void
    {
        $cita = $this->pideLaVisita();

        // Administrador: todo.
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $this->sesion(Admin::ROLE_ADMINISTRADOR))->assertOk();

        // Recepción con su política por defecto: no entra a la agenda.
        $recepcion = $this->sesion(Admin::ROLE_RECEPCION);
        $this->getJson(self::AGENDA, $recepcion)->assertForbidden();
        $this->postJson(self::AGENDA."/{$cita->id}/cancel", [], $recepcion)->assertForbidden();
        $this->get(self::AGENDA.'/stream', $recepcion)->assertForbidden();

        // Administrativo, aunque le den mercadeo: no es un rol de la agenda.
        $administrativo = $this->sesion(Admin::ROLE_ADMINISTRATIVO, ['marketing.view', 'marketing.manage']);
        $this->getJson(self::AGENDA.'/capabilities', $administrativo)->assertOk()->assertJsonPath('data.can_confirm', false);
        $this->postJson(self::AGENDA."/{$cita->id}/cancel", [], $administrativo)->assertForbidden();
    }

    public function test_12b_las_capacidades_no_prometen_botones_que_darian_403(): void
    {
        // Un rol comercial con SOLO lectura: ve la agenda, pero no puede confirmar.
        $soloVer = $this->sesion('Asesor', ['marketing.view']);
        $caps = $this->getJson(self::AGENDA.'/capabilities', $soloVer)->assertOk()->json('data');
        $this->assertTrue($caps['can_view']);
        $this->assertFalse($caps['can_confirm'], 'se ofrecía confirmar a quien el middleware rechaza');
        $this->assertFalse($caps['can_cancel']);

        $cita = $this->pideLaVisita();
        $fila = collect($this->getJson(self::AGENDA, $soloVer)->assertOk()->json('data'))->firstWhere('id', $cita->id);
        $this->assertSame(['confirm' => false, 'cancel' => false, 'complete' => false, 'reschedule' => false], $fila['allowed_actions']);
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $soloVer)->assertForbidden();

        // Con escritura, opera lo suyo o lo sin asignar (la solicitud de ULTRON no tiene dueño)…
        $comercial = $this->sesion('Ventas', ['marketing.view', 'marketing.manage']);
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $comercial)->assertOk();

        // …pero no la cita de otro asesor.
        $otro = Admin::create(['name' => 'Otro', 'email' => 'o-'.Str::random(6).'@ironbody.test', 'password' => 'x', 'role' => 'Ventas', 'status' => 'active']);
        $cita->forceFill(['assigned_to_admin_id' => $otro->id])->save();
        $this->postJson(self::AGENDA."/{$cita->id}/cancel", [], $comercial)->assertForbidden()->assertJsonPath('code', 'appointments_forbidden');
    }

    // ── 13 · concurrencia ────────────────────────────────────────────────────

    public function test_13_las_transiciones_cruzadas_terminan_en_un_estado_coherente(): void
    {
        $cita = $this->pideLaVisita();
        $a = $this->sesion();
        $b = $this->sesion();

        // Una cancela; la otra intenta confirmar sobre lo que ya cambió: 409, no una cita resucitada.
        $this->postJson(self::AGENDA."/{$cita->id}/cancel", [], $a)->assertOk();
        $this->postJson(self::AGENDA."/{$cita->id}/confirm", [], $b)->assertStatus(409);
        $this->assertSame(MarketingAppointment::STATUS_CANCELLED, $cita->fresh()->status);

        // Confirmada por una sesión mientras ULTRON trae otro día: la misma cita, reabierta, una sola viva.
        $nueva = $this->pideLaVisita('w.o2.c2');
        $this->postJson(self::AGENDA."/{$nueva->id}/confirm", [], $a)->assertOk();
        $this->turno('mejor el miércoles a las 6 de la tarde', 'w.o2.c3', ['courtesy_date' => '2026-09-23', 'courtesy_time' => '18:00'])->assertOk();
        $this->assertSame(1, $this->activas());
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $nueva->fresh()->status);

        // PATCH no se salta la máquina de estados.
        $this->patchJson(self::AGENDA."/{$nueva->id}", ['status' => 'completed'], $a)->assertStatus(409)->assertJsonPath('code', 'appointment_not_confirmed');
    }

    /** La red de la base para dos reintentos que llegaran a la vez: una sola solicitud abierta de ULTRON por persona. */
    public function test_13b_la_base_no_admite_dos_solicitudes_abiertas_de_ultron_para_la_misma_persona(): void
    {
        $this->pideLaVisita();

        $this->expectException(QueryException::class);
        MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'type' => 'visit', 'status' => MarketingAppointment::STATUS_REQUESTED,
            'source' => MarketingAppointment::SOURCE_ULTRON, 'title' => 'Duplicada', 'scheduled_at' => now()->addDays(3),
        ]);
    }

    // ── 14 · hora de Bogotá ──────────────────────────────────────────────────

    public function test_14_la_hora_es_la_de_bogota_en_ultron_en_el_crm_y_en_los_filtros(): void
    {
        // ULTRON: «mañana a las 6 de la tarde» son las 23:00 UTC.
        $this->assertSame('2026-09-22 23:00:00', $this->pideLaVisita()->scheduled_at->copy()->utc()->toDateTimeString());

        $s = $this->sesion();
        // El CRM sin offset: hora del gimnasio. Antes se guardaba como UTC y la cita quedaba 5 h corrida.
        $sinOffset = $this->postJson(self::AGENDA, ['type' => 'call', 'title' => 'Llamada', 'scheduled_at' => '2026-09-25 18:00'], $s)->assertCreated()->json('data.id');
        $conOffset = $this->postJson(self::AGENDA, ['type' => 'call', 'title' => 'Llamada', 'scheduled_at' => '2026-09-25T18:00:00-05:00'], $s)->assertCreated()->json('data.id');
        $this->assertSame('2026-09-25 23:00:00', MarketingAppointment::find($sinOffset)->scheduled_at->copy()->utc()->toDateTimeString());
        $this->assertSame('2026-09-25 23:00:00', MarketingAppointment::find($conOffset)->scheduled_at->copy()->utc()->toDateTimeString());

        // «Desde el viernes 25» incluye las 18:00 del viernes en Neiva; «desde el sábado», no.
        $ids = fn (string $desde) => collect($this->getJson(self::AGENDA.'?type=call&date_from='.$desde, $s)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$sinOffset, $conOffset], $ids('2026-09-25'));
        $this->assertSame([], $ids('2026-09-26'));
        $this->assertSame('18:00', Carbon::parse($this->getJson(self::AGENDA."/{$sinOffset}", $s)->json('data.scheduled_at'))->setTimezone(BusinessClock::TZ)->format('H:i'));
    }

    // ── 15 · regresión y traslado de lo que ya había ─────────────────────────

    /**
     * Las solicitudes de ULTRON que estaban abiertas en el panel viejo pasan a la
     * agenda al migrar, una sola vez y sin perder lo que pidió la persona.
     */
    public function test_15_las_solicitudes_abiertas_del_panel_viejo_se_trasladan_a_la_agenda(): void
    {
        $vieja = MarketingAgentAction::create([
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
            'suggested_by' => 'ai', 'action_type' => MarketingAgentAction::TYPE_CREATE_APPOINTMENT,
            'status' => MarketingAgentAction::STATUS_SUGGESTED, 'requires_approval' => true, 'title' => 'Día de cortesía',
            'reason' => 'x', 'payload' => [
                'type' => 'visit', 'title' => 'Día de cortesía', 'scheduled_at' => '2026-09-26 15:00:00', 'duration_minutes' => 60,
                'requested_date' => '2026-09-26', 'requested_time' => '10:00', 'requested_at_local' => '2026-09-26 10:00:00', 'source' => 'ultron',
            ],
        ]);
        $migracion = require database_path('migrations/2026_10_03_000001_agenda_shares_courtesy_requests.php');
        $trasladar = fn () => $this->trasladarSolicitudesAbiertas();

        $trasladar->call($migracion);
        // Aunque la acción vieja vuelva a quedar abierta (alguien la reabrió a
        // mano, o se repite la migración), ya tiene su cita: no nace otra.
        $vieja->refresh()->forceFill(['status' => MarketingAgentAction::STATUS_SUGGESTED])->save();
        $this->assertSame(MarketingAgentAction::STATUS_SUGGESTED, $vieja->fresh()->status);
        $trasladar->call($migracion);

        $cita = MarketingAppointment::query()->where('source', 'ultron')->sole();
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $cita->status);
        $this->assertSame((int) $vieja->id, (int) data_get($cita->metadata, 'migrated_from_action_id'));
        $this->assertSame('10:00', data_get($cita->metadata, 'requested_time'));
        $this->assertSame(MarketingAgentAction::STATUS_CANCELLED, $vieja->fresh()->status);
        // Y ULTRON la sigue viendo como la solicitud de esta persona.
        $this->assertSame($cita->id, app(CourtesyRequestService::class)->openForLead($this->lead)?->id);
    }

    /**
     * Las cortesías que se cerraron antes del cambio (rechazadas, canceladas) NO
     * se convierten en citas —serían filas con fechas pasadas que cambiarían las
     * cifras de periodos cerrados—, pero siguen contando para la guarda: un «te
     * esperamos» sin fecha no sale a quien tuvo una cortesía que ya no está en pie.
     */
    public function test_15b_las_cortesias_cerradas_antes_del_cambio_siguen_contando_para_la_guarda(): void
    {
        $accion = fn (string $estado, array $extra = []) => MarketingAgentAction::create(array_merge([
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
            'suggested_by' => 'ai', 'action_type' => MarketingAgentAction::TYPE_CREATE_APPOINTMENT,
            'status' => $estado, 'requires_approval' => true, 'title' => 'Día de cortesía', 'reason' => 'x',
            'payload' => ['type' => 'visit', 'title' => 'Día de cortesía', 'scheduled_at' => '2026-09-26 15:00:00', 'duration_minutes' => 60, 'requested_date' => '2026-09-26', 'requested_time' => '10:00', 'source' => 'ultron'],
        ], $extra));
        $rechazada = $accion(MarketingAgentAction::STATUS_REJECTED, ['rejection_reason' => 'No hay cupo ese día']);
        $citaEjecutada = MarketingAppointment::create(['marketing_lead_id' => $this->lead->id, 'type' => 'visit', 'status' => 'completed', 'title' => 'Día de cortesía', 'scheduled_at' => '2026-09-01 15:00:00']);
        $accion(MarketingAgentAction::STATUS_EXECUTED, ['result' => ['appointment_id' => $citaEjecutada->id]]);
        $antes = MarketingAppointment::count();

        $migracion = require database_path('migrations/2026_10_03_000001_agenda_shares_courtesy_requests.php');
        $migracion->up();

        $this->assertSame($antes, MarketingAppointment::count(), 'se inventaron citas para cortesías cerradas');
        $this->assertSame(MarketingAgentAction::STATUS_REJECTED, $rechazada->fresh()->status, 'se tocó la acción vieja');
        $this->assertSame(MarketingAppointment::SOURCE_ULTRON, $citaEjecutada->fresh()->source);
        $this->assertTrue(app(CourtesyRequestService::class)->hadLegacyCourtesy($this->conversation));

        $this->soloTexto('Te esperamos, ya está todo listo.', 'w.o2.cerradas');
        $this->assertStringNotContainsString('te esperamos', $this->ultimoSaliente(), 'se prometió una visita a quien ya no la tiene');
    }

    /**
     * Desplegar, revertir y volver a desplegar. El rollback no borra el origen de
     * las citas, y lo que el código viejo anotó entretanto en el panel MUEVE la
     * solicitud abierta de esa persona: una sola abierta, la misma fila, y la
     * guarda la reconoce también desde la conversación del panel viejo.
     */
    public function test_15c_la_migracion_aguanta_un_rollback_y_un_nuevo_despliegue(): void
    {
        $migracion = require database_path('migrations/2026_10_03_000001_agenda_shares_courtesy_requests.php');
        $cita = $this->pideLaVisita();

        $migracion->down();
        $this->assertTrue(Schema::hasColumn('marketing_appointments', 'source'), 'el rollback borró el origen de las citas');

        // Durante el rollback, el código viejo anota en el panel desde otra conversación.
        $otra = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::DISCOVERY]);
        $vieja = MarketingAgentAction::create([
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $otra->id,
            'suggested_by' => 'ai', 'action_type' => MarketingAgentAction::TYPE_CREATE_APPOINTMENT,
            'status' => MarketingAgentAction::STATUS_SUGGESTED, 'requires_approval' => true, 'title' => 'Día de cortesía', 'reason' => 'x',
            'payload' => ['type' => 'visit', 'title' => 'Día de cortesía', 'scheduled_at' => '2026-09-24 23:00:00', 'duration_minutes' => 60, 'requested_date' => '2026-09-24', 'requested_time' => '18:00', 'source' => 'ultron'],
        ]);

        $migracion->up();

        $abiertas = MarketingAppointment::query()->where('marketing_lead_id', $this->lead->id)->where('status', MarketingAppointment::STATUS_REQUESTED)->get();
        $this->assertCount(1, $abiertas, 'quedaron dos solicitudes abiertas para la misma persona');
        $this->assertSame($cita->id, $abiertas->first()->id);
        $this->assertSame(MarketingAppointment::SOURCE_ULTRON, $abiertas->first()->source);
        $this->assertSame('2026-09-24 23:00:00', $abiertas->first()->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(MarketingAgentAction::STATUS_CANCELLED, $vieja->fresh()->status);
        $this->assertSame($cita->id, app(CourtesyRequestService::class)->latestFor($otra)?->id);
        $this->assertTrue(Schema::hasTable('marketing_agenda_state'));

        // Y el índice volvió: una segunda abierta de ULTRON para la persona no cabe.
        $this->expectException(QueryException::class);
        MarketingAppointment::create(['marketing_lead_id' => $this->lead->id, 'type' => 'visit', 'status' => 'requested', 'source' => 'ultron', 'title' => 'x', 'scheduled_at' => '2026-09-25 23:00:00']);
    }
}
