<?php

namespace Tests\Feature\Moderation;

use App\Models\Admin;
use App\Models\ContentReport;
use App\Models\Member;
use App\Models\MemberSuspension;
use App\Models\ModerationAction;
use App\Models\ModerationAuditLog;
use App\Models\Story;
use App\Support\Moderation\ReportStatus;

/**
 * Superficie administrativa (CRM): permisos, máquina de estados, concurrencia,
 * idempotencia, evidencia y anonimato del reportante.
 */
class AdminModerationTest extends ModerationTestCase
{
    /** Crea un caso real pasando por el endpoint de la app. */
    private function makeReport(string $reason = 'harassment_or_bullying'): ContentReport
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante Secreto');
        $story = $this->makeStory($author, ['caption' => 'contenido']);

        $this->postJson("/api/app/stories/{$story->id}/report",
            ['reason_code' => $reason], $this->asMember($reporter))->assertCreated();

        return ContentReport::firstOrFail();
    }

    // ── Autenticación y permisos ──────────────────────────────────────────

    public function test_api_administrativa_requiere_autenticacion(): void
    {
        $this->getJson('/api/admin/moderation/reports')->assertStatus(401);
        $this->getJson('/api/admin/moderation/dashboard')->assertStatus(401);
    }

    public function test_token_invalido_es_rechazado(): void
    {
        $this->getJson('/api/admin/moderation/reports', [
            'Authorization' => 'Bearer token-falso',
        ])->assertStatus(403);
    }

    public function test_recepcion_puede_ver_pero_no_sancionar(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin(Admin::ROLE_RECEPCION));

        $this->getJson('/api/admin/moderation/reports', $headers)->assertOk();

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'suspend_social',
            'duration_minutes' => 60,
            'public_reason' => 'Prueba',
        ], $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('required_permission', 'moderation.suspend_social');

        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_administrador_no_puede_eliminar_contenido_ni_bloquear_la_app(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin(Admin::ROLE_ADMINISTRADOR));

        // Acciones permanentes/irreversibles: permiso ELEVADO.
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'remove_content',
            'public_reason' => 'Prueba',
        ], $headers)->assertStatus(403);

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'suspend_full',
            'duration_minutes' => 60,
            'public_reason' => 'Prueba',
        ], $headers)->assertStatus(403);

        // Pero sí puede ocultar y suspender funciones sociales.
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'hide_content',
            'public_reason' => 'Contenido en revisión',
        ], $headers)->assertCreated();
    }

    public function test_sancion_permanente_exige_permiso_elevado(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin(Admin::ROLE_ADMINISTRADOR));

        // Sin `duration_minutes` = permanente → exige permiso elevado aunque
        // el tipo de acción por sí solo no lo exigiera.
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'suspend_social',
            'public_reason' => 'Para siempre',
        ], $headers)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'moderation.suspend_full_access');

        // Super Admin sí puede.
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'suspend_social',
            'public_reason' => 'Para siempre',
        ], $this->asAdmin($this->makeAdmin(Admin::ROLE_SUPER_ADMIN)))
            ->assertCreated()
            ->assertJsonPath('data.is_permanent', true);
    }

    public function test_el_token_compartido_de_automatizaciones_solo_lee(): void
    {
        config(['admin.api_token' => 'secreto-n8n']);
        $report = $this->makeReport();
        $headers = ['Authorization' => 'Bearer secreto-n8n'];

        $this->getJson('/api/admin/moderation/reports', $headers)->assertOk();

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'warn',
            'public_reason' => 'x',
        ], $headers)->assertStatus(403);
    }

    // ── Anonimato ─────────────────────────────────────────────────────────

    public function test_el_crm_nunca_recibe_la_identidad_del_reportante(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $list = $this->getJson('/api/admin/moderation/reports', $headers)->assertOk();
        $detail = $this->getJson("/api/admin/moderation/reports/{$report->public_id}", $headers)
            ->assertOk();

        foreach ([$list, $detail] as $res) {
            $body = json_encode($res->json());
            $this->assertStringNotContainsString('Reportante Secreto', $body);
            $this->assertStringNotContainsString('reporter_member_id', $body);
        }

        $detail->assertJsonPath('data.reporter.anonymous', true);
        // Sí se expone el agregado (cuántas personas distintas reportaron).
        $this->assertSame(1, $detail->json('data.unique_reporters'));
    }

    public function test_el_listado_usa_identificadores_publicos_no_secuenciales(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $res = $this->getJson('/api/admin/moderation/reports', $headers)->assertOk();

        $this->assertSame($report->public_id, $res->json('data.0.id'));
        $this->assertNotSame((string) $report->id, (string) $res->json('data.0.id'));
    }

    public function test_reporte_inexistente_devuelve_404_sin_filtrar_informacion(): void
    {
        $headers = $this->asAdmin($this->makeAdmin());

        $this->getJson('/api/admin/moderation/reports/'
            .'00000000-0000-0000-0000-000000000000', $headers)->assertStatus(404);
    }

    // ── Máquina de estados ────────────────────────────────────────────────

    public function test_transicion_valida_avanza_el_caso(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/transition",
            ['status' => ReportStatus::TRIAGED], $headers)->assertOk();

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/transition",
            ['status' => ReportStatus::UNDER_REVIEW], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', ReportStatus::UNDER_REVIEW);

        $this->assertNotNull($report->fresh()->reviewed_at);
    }

    public function test_transicion_arbitraria_es_rechazada(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        // submitted → closed no está en el grafo.
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/transition",
            ['status' => ReportStatus::CLOSED], $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_transition');

        $this->assertSame(ReportStatus::SUBMITTED, $report->fresh()->status);
    }

    public function test_estado_desconocido_es_422(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/transition",
            ['status' => 'lo_que_sea'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_dos_moderadores_concurrentes_no_duplican_acciones(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $version = (int) $report->lock_version;

        // El primero decide con la versión que vio en pantalla.
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'warn',
            'public_reason' => 'Primera decisión',
            'expected_version' => $version,
        ], $headers)->assertCreated();

        // El segundo llega con la MISMA versión obsoleta.
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'suspend_social',
            'duration_minutes' => 60,
            'public_reason' => 'Segunda decisión',
            'expected_version' => $version,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('code', 'concurrent_modification');

        $this->assertDatabaseCount('moderation_actions', 1);
    }

    public function test_idempotency_key_evita_doble_sancion(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $payload = [
            'action_type' => 'restrict_posting',
            'duration_minutes' => 1440,
            'public_reason' => 'Restricción 24 h',
            'idempotency_key' => 'decision-abc-123',
        ];

        $first = $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision",
            $payload, $headers)->assertCreated();

        // Reintento por red inestable: misma clave.
        $second = $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision",
            $payload, $headers)->assertCreated();

        $this->assertSame($first->json('data.action_id'), $second->json('data.action_id'));
        $this->assertDatabaseCount('moderation_actions', 1);
        $this->assertDatabaseCount('member_suspensions', 1);
    }

    // ── Efectos de las decisiones ─────────────────────────────────────────

    public function test_restringir_publicacion_crea_sancion_y_notifica(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());
        $author = Member::find($report->reported_member_id);

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'restrict_posting',
            'duration_minutes' => 1440,
            'public_reason' => 'Contenido contrario a los lineamientos.',
            'internal_notes' => 'NOTA INTERNA que no debe salir',
        ], $headers)->assertCreated();

        $this->assertDatabaseHas('member_suspensions', [
            'member_id' => $author->id,
            'scope' => 'story_posting',
            'status' => MemberSuspension::STATUS_ACTIVE,
        ]);

        $this->assertSame(ReportStatus::ACTIONED, $report->fresh()->status);

        // El sancionado ve motivo público, no la nota interna.
        $status = $this->getJson('/api/app/moderation/status', $this->asMember($author))
            ->assertOk();
        $this->assertFalse($status->json('data.can_post_stories'));
        $this->assertStringNotContainsString('NOTA INTERNA', json_encode($status->json()));

        // Y la membresía sigue intacta.
        $this->assertSame(Member::STATUS_ACTIVE, $author->fresh()->status);
    }

    public function test_ocultar_contenido_no_borra_la_fila_ni_la_evidencia(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'hide_content',
            'public_reason' => 'En revisión',
        ], $headers)->assertCreated();

        $story = Story::withTrashed()->find($report->content_id);
        $this->assertSame(Story::MODERATION_QUARANTINED, $story->moderation_state);
        $this->assertNull($story->deleted_at);

        $this->assertDatabaseHas('report_content_snapshots', [
            'original_story_id' => $report->content_id,
            'media_purged_at' => null,
        ]);
    }

    public function test_revocar_restaura_acceso_y_contenido(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());
        $author = Member::find($report->reported_member_id);

        $res = $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'suspend_social',
            'duration_minutes' => 1440,
            'public_reason' => 'Suspensión temporal',
        ], $headers)->assertCreated();

        $actionId = $res->json('data.action_id');

        $this->getJson('/api/app/moderation/status', $this->asMember($author))
            ->assertJsonPath('data.can_use_social', false);

        $this->postJson("/api/admin/moderation/actions/{$actionId}/revoke",
            ['reason' => 'Error de criterio'], $headers)->assertOk();

        $this->getJson('/api/app/moderation/status', $this->asMember($author))
            ->assertJsonPath('data.can_use_social', true);

        $this->assertDatabaseHas('member_suspensions', [
            'member_id' => $author->id,
            'status' => MemberSuspension::STATUS_REVOKED,
        ]);
    }

    public function test_revocacion_duplicada_es_idempotente(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $actionId = $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'warn',
            'public_reason' => 'Aviso',
        ], $headers)->json('data.action_id');

        $first = $this->postJson("/api/admin/moderation/actions/{$actionId}/revoke", [], $headers)
            ->assertOk();
        $second = $this->postJson("/api/admin/moderation/actions/{$actionId}/revoke", [], $headers)
            ->assertOk();

        $this->assertSame($first->json('data.revoked_at'), $second->json('data.revoked_at'));
        $this->assertSame(1, ModerationAction::whereNotNull('revoked_at')->count());
    }

    public function test_desestimar_cierra_el_caso_sin_sancionar(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'dismiss',
            'public_reason' => 'Sin infracción',
        ], $headers)->assertCreated();

        $this->assertSame(ReportStatus::DISMISSED, $report->fresh()->status);
        $this->assertDatabaseCount('member_suspensions', 0);
    }

    public function test_no_se_puede_decidir_sobre_un_caso_cerrado(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision",
            ['action_type' => 'dismiss'], $headers)->assertCreated();
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/transition",
            ['status' => ReportStatus::CLOSED], $headers)->assertOk();

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision",
            ['action_type' => 'warn', 'public_reason' => 'tarde'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'report_closed');
    }

    // ── Asignación, evidencia y auditoría ─────────────────────────────────

    public function test_asignacion_de_moderador(): void
    {
        $report = $this->makeReport();
        $admin = $this->makeAdmin();
        $headers = $this->asAdmin($admin);

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/assign",
            ['assign_to_self' => true], $headers)->assertOk();

        $this->assertSame($admin->id, (int) $report->fresh()->assigned_admin_id);
    }

    public function test_evidencia_exige_permiso_elevado_y_no_devuelve_url_permanente(): void
    {
        $report = $this->makeReport();

        // Administrador NO tiene `view_sensitive_evidence`.
        $this->getJson("/api/admin/moderation/reports/{$report->public_id}/evidence",
            $this->asAdmin($this->makeAdmin(Admin::ROLE_ADMINISTRADOR)))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'moderation.view_sensitive_evidence');

        // El detalle del caso NUNCA incluye la ruta ni una URL del medio.
        $detail = $this->getJson("/api/admin/moderation/reports/{$report->public_id}",
            $this->asAdmin($this->makeAdmin()))->assertOk();

        $body = json_encode($detail->json());
        $this->assertStringNotContainsString('media_storage_path', $body);
        $this->assertStringNotContainsString('firebasestorage.example', $body);
        $this->assertStringNotContainsString('download_url', $body);
    }

    public function test_evidencia_purgada_responde_404_controlado(): void
    {
        $report = $this->makeReport();
        $report->snapshot->forceFill([
            'media_purged_at' => now(),
            'media_storage_path' => null,
        ])->save();

        $this->getJson("/api/admin/moderation/reports/{$report->public_id}/evidence",
            $this->asAdmin($this->makeAdmin()))
            ->assertStatus(404)
            ->assertJsonPath('code', 'evidence_unavailable');
    }

    public function test_toda_decision_queda_en_auditoria(): void
    {
        $report = $this->makeReport();
        $admin = $this->makeAdmin();
        $headers = $this->asAdmin($admin);

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
            'action_type' => 'warn',
            'public_reason' => 'Aviso',
        ], $headers)->assertCreated();

        $this->assertDatabaseHas('moderation_audit_logs', [
            'actor_type' => 'admin',
            'actor_id' => $admin->id,
            'action' => 'moderation_action_applied',
        ]);
    }

    public function test_la_auditoria_es_append_only(): void
    {
        $this->makeReport();
        $log = ModerationAuditLog::firstOrFail();

        $this->expectException(\RuntimeException::class);
        $log->update(['action' => 'manipulado']);
    }

    public function test_la_auditoria_no_guarda_secretos_ni_pii(): void
    {
        $this->makeReport();

        foreach (ModerationAuditLog::all() as $log) {
            $payload = strtolower(json_encode([$log->before_data, $log->after_data]));

            foreach (['token', 'password', 'authorization', 'download_url', 'document', 'phone'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $payload);
            }
            // La IP nunca se guarda en claro: solo su HMAC.
            $this->assertNotSame('127.0.0.1', $log->ip_hash);
        }
    }

    // ── Dashboard y filtros ───────────────────────────────────────────────

    public function test_dashboard_devuelve_metricas_y_permisos(): void
    {
        $this->makeReport('child_safety');
        $headers = $this->asAdmin($this->makeAdmin());

        $res = $this->getJson('/api/admin/moderation/dashboard', $headers)->assertOk();

        $this->assertSame(1, $res->json('data.new_reports'));
        $this->assertSame(1, $res->json('data.critical_open'));
        $this->assertContains('moderation.view', $res->json('data.permissions'));
    }

    public function test_filtros_y_ordenamiento_solo_aceptan_valores_permitidos(): void
    {
        $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin());

        $this->getJson('/api/admin/moderation/reports?open_only=1&severity=high', $headers)
            ->assertOk();

        // Columna arbitraria de ordenamiento: rechazada.
        $this->getJson('/api/admin/moderation/reports?sort=reporter_member_id', $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }

    public function test_sancion_directa_sin_caso_previo(): void
    {
        $member = $this->makeMember('Objetivo');
        $headers = $this->asAdmin($this->makeAdmin());

        $this->postJson("/api/admin/moderation/members/{$member->id}/suspensions", [
            'scope' => 'story_posting',
            'duration_minutes' => 60,
            'public_reason' => 'Incumplimiento reiterado',
        ], $headers)->assertCreated();

        $this->assertDatabaseHas('member_suspensions', [
            'member_id' => $member->id,
            'scope' => 'story_posting',
        ]);
        // Se materializa también como acción → es apelable y auditable.
        $this->assertDatabaseCount('moderation_actions', 1);

        // La membresía no se toca.
        $this->assertSame(Member::STATUS_ACTIVE, $member->fresh()->status);
    }
}
