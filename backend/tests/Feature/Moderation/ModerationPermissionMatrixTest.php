<?php

namespace Tests\Feature\Moderation;

use App\Models\Admin;
use App\Models\ContentReport;
use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Support\Moderation\ModerationPermission;

/**
 * Matriz rol → endpoint de moderación, verificada contra la API real.
 *
 * El CRM decide qué botones pinta, pero la autoridad es el backend. Estas
 * pruebas recorren los cuatro roles reales (`Admin::ROLES`) contra cada
 * endpoint y comprueban que nadie obtiene un permiso de más — incluido el rol
 * `Administrativo`, que el front no contemplaba y que motivó la corrección.
 */
class ModerationPermissionMatrixTest extends ModerationTestCase
{
    private function makeReport(): ContentReport
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report",
            ['reason_code' => 'harassment_or_bullying'], $this->asMember($reporter))
            ->assertCreated();

        return ContentReport::firstOrFail();
    }

    // ── Lectura ───────────────────────────────────────────────────────────

    public function test_los_roles_con_moderacion_ven_la_cola(): void
    {
        $this->makeReport();

        // Recepción ya no: dejó de moderar por decisión de negocio. Los demás
        // roles del sistema conservan su acceso exactamente igual.
        foreach ([Admin::ROLE_ADMINISTRATIVO, Admin::ROLE_ADMINISTRADOR, Admin::ROLE_SUPER_ADMIN] as $role) {
            $this->getJson('/api/admin/moderation/reports', $this->asAdmin($this->makeAdmin($role)))
                ->assertOk();
        }

        $this->getJson('/api/admin/moderation/reports',
            $this->asAdmin($this->makeAdmin(Admin::ROLE_RECEPCION)))
            ->assertStatus(403);
    }

    public function test_el_dashboard_devuelve_los_permisos_efectivos_del_rol(): void
    {
        // El CRM usa esta lista como autoridad para pintar los botones.
        $expected = [
            // Recepción ya no aparece: no entra en moderación, así que el
            // dashboard le responde 403 y no una lista vacía. Lo comprueba
            // test_recepcion_ya_no_entra_en_moderacion().
            Admin::ROLE_ADMINISTRATIVO => [
                ModerationPermission::VIEW,
                ModerationPermission::REVIEW,
                ModerationPermission::ASSIGN,
            ],
        ];

        foreach ($expected as $role => $permissions) {
            $res = $this->getJson('/api/admin/moderation/dashboard',
                $this->asAdmin($this->makeAdmin($role)))->assertOk();

            $this->assertEqualsCanonicalizing(
                $permissions,
                $res->json('data.permissions'),
                "Permisos inesperados para {$role}",
            );
        }
    }

    public function test_super_admin_recibe_los_once_permisos(): void
    {
        $res = $this->getJson('/api/admin/moderation/dashboard',
            $this->asAdmin($this->makeAdmin(Admin::ROLE_SUPER_ADMIN)))->assertOk();

        $this->assertEqualsCanonicalizing(
            ModerationPermission::all(),
            $res->json('data.permissions'),
        );
        $this->assertCount(11, $res->json('data.permissions'));
    }

    // ── Recepción: solo lectura ───────────────────────────────────────────

    public function test_recepcion_ya_no_entra_en_moderacion(): void
    {
        // Cambio de política deliberado: antes veía la cola en solo lectura.
        $this->getJson('/api/admin/moderation/reports',
            $this->asAdmin($this->makeAdmin(Admin::ROLE_RECEPCION)))
            ->assertStatus(403);
    }

    public function test_recepcion_no_puede_revisar_asignar_ni_sancionar(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin(Admin::ROLE_RECEPCION));

        // Transición de estado (review).
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/transition",
            ['status' => 'triaged'], $headers)
            ->assertStatus(403)
            // Ahora deniega la puerta EXTERIOR, que es más gruesa y llega
            // antes. El permiso fino de moderación sigue existiendo detrás como
            // defensa en profundidad; simplemente ya no hace falta llegar a él.
            ->assertJsonPath('required_permission', 'moderation.manage');

        // Asignación.
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/assign",
            ['assign_to_self' => true], $headers)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'moderation.manage');

        // Sanción social.
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision",
            ['action_type' => 'suspend_social', 'duration_minutes' => 60, 'public_reason' => 'x'],
            $headers)->assertStatus(403);

        // Resolver apelaciones.
        $this->postJson('/api/admin/moderation/appeals/'
            .'00000000-0000-0000-0000-000000000000/resolve',
            // 403 y no 404: la puerta deniega antes de buscar la apelación, así
            // que Recepción tampoco puede averiguar qué ids existen.
            ['status' => 'granted'], $headers)->assertStatus(403);

        // Evidencia sensible.
        $this->getJson("/api/admin/moderation/reports/{$report->public_id}/evidence", $headers)
            ->assertStatus(403)
            // Deniega la puerta exterior, que llega antes.
            ->assertJsonPath('required_permission', 'moderation.view');

        // Nada quedó aplicado.
        $this->assertDatabaseCount('moderation_actions', 0);
        $this->assertSame('submitted', $report->fresh()->status);
        $this->assertNull($report->fresh()->assigned_admin_id);
    }

    public function test_recepcion_no_puede_suspender_directamente_a_un_miembro(): void
    {
        $member = $this->makeMember('Objetivo');
        $headers = $this->asAdmin($this->makeAdmin(Admin::ROLE_RECEPCION));

        $this->postJson("/api/admin/moderation/members/{$member->id}/suspensions", [
            'scope' => 'story_posting',
            'duration_minutes' => 60,
            'public_reason' => 'Intento no autorizado',
        ], $headers)->assertStatus(403);

        $this->assertDatabaseCount('member_suspensions', 0);
    }

    // ── Administrativo: revisar y asignar, nada más ───────────────────────

    public function test_administrativo_puede_revisar_y_asignar(): void
    {
        $report = $this->makeReport();
        $admin = $this->makeAdmin(Admin::ROLE_ADMINISTRATIVO);
        $headers = $this->asAdmin($admin);

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/assign",
            ['assign_to_self' => true], $headers)->assertOk();

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/transition",
            ['status' => 'triaged'], $headers)->assertOk();

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/transition",
            ['status' => 'under_review'], $headers)->assertOk();

        $fresh = $report->fresh();
        $this->assertSame($admin->id, (int) $fresh->assigned_admin_id);
        $this->assertSame('under_review', $fresh->status);
    }

    public function test_administrativo_no_puede_sancionar_ni_tocar_contenido(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin(Admin::ROLE_ADMINISTRATIVO));

        $denied = [
            'warn' => ModerationPermission::WARN_MEMBER,
            'hide_content' => ModerationPermission::HIDE_CONTENT,
            'remove_content' => ModerationPermission::REMOVE_CONTENT,
            'restrict_posting' => ModerationPermission::SUSPEND_SOCIAL,
            'suspend_social' => ModerationPermission::SUSPEND_SOCIAL,
            'suspend_full' => ModerationPermission::SUSPEND_FULL_ACCESS,
        ];

        foreach ($denied as $action => $permission) {
            $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision", [
                'action_type' => $action,
                'duration_minutes' => 60,
                'public_reason' => 'Intento no autorizado',
            ], $headers)
                ->assertStatus(403)
                ->assertJsonPath('required_permission', $permission);
        }

        $this->assertDatabaseCount('moderation_actions', 0);
        $this->assertDatabaseCount('member_suspensions', 0);
    }

    public function test_administrativo_no_puede_resolver_apelaciones_ni_ver_evidencia(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin(Admin::ROLE_ADMINISTRATIVO));

        $this->getJson("/api/admin/moderation/reports/{$report->public_id}/evidence", $headers)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', ModerationPermission::VIEW_SENSITIVE_EVIDENCE);

        // Se crea una apelación real para comprobar el 403 (no un 404).
        $suspension = ModerationAction::create([
            'target_member_id' => $report->reported_member_id,
            'action_type' => 'restrict_posting',
            'scope' => 'story_posting',
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'reason' => 'Prueba',
        ]);
        $appeal = ModerationAppeal::create([
            'moderation_action_id' => $suspension->id,
            'member_id' => $report->reported_member_id,
            'appeal_text' => 'Solicito revisión de la medida aplicada.',
            'status' => ModerationAppeal::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->postJson("/api/admin/moderation/appeals/{$appeal->public_id}/resolve",
            ['status' => 'granted'], $headers)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', ModerationPermission::RESOLVE_APPEALS);

        $this->assertSame(
            ModerationAppeal::STATUS_SUBMITTED,
            $appeal->fresh()->status,
        );
    }

    // ── Administrador vs Super Admin ──────────────────────────────────────

    public function test_administrador_modera_pero_no_ejecuta_acciones_elevadas(): void
    {
        $report = $this->makeReport();
        $headers = $this->asAdmin($this->makeAdmin(Admin::ROLE_ADMINISTRADOR));

        // Permitido.
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision",
            ['action_type' => 'warn', 'public_reason' => 'Aviso'], $headers)->assertCreated();

        // Elevado: denegado.
        foreach ([
            ['remove_content', ModerationPermission::REMOVE_CONTENT],
            ['suspend_full', ModerationPermission::SUSPEND_FULL_ACCESS],
        ] as [$action, $permission]) {
            $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision",
                ['action_type' => $action, 'duration_minutes' => 60, 'public_reason' => 'x'],
                $headers)
                ->assertStatus(403)
                ->assertJsonPath('required_permission', $permission);
        }
    }

    public function test_solo_super_admin_ve_evidencia_sensible(): void
    {
        $report = $this->makeReport();

        foreach ([
            Admin::ROLE_RECEPCION,
            Admin::ROLE_ADMINISTRATIVO,
            Admin::ROLE_ADMINISTRADOR,
        ] as $role) {
            $this->getJson("/api/admin/moderation/reports/{$report->public_id}/evidence",
                $this->asAdmin($this->makeAdmin($role)))->assertStatus(403);
        }

        // Super Admin pasa el control de permisos (la evidencia en sí depende
        // de que Storage responda, lo cubre EvidenceRetentionTest).
        $this->getJson("/api/admin/moderation/reports/{$report->public_id}/evidence",
            $this->asAdmin($this->makeAdmin(Admin::ROLE_SUPER_ADMIN)))
            ->assertStatus(404)
            ->assertJsonPath('code', 'evidence_unavailable');
    }

    // ── Coherencia de la matriz ───────────────────────────────────────────

    public function test_la_jerarquia_de_roles_es_monotona(): void
    {
        $recepcion = ModerationPermission::byRole()[Admin::ROLE_RECEPCION];
        $administrativo = ModerationPermission::byRole()[Admin::ROLE_ADMINISTRATIVO];
        $administrador = ModerationPermission::byRole()[Admin::ROLE_ADMINISTRADOR];
        $superAdmin = ModerationPermission::byRole()[Admin::ROLE_SUPER_ADMIN];

        // Recepción queda vacío por política; la monotonía se mantiene igual.
        foreach ($recepcion as $p) {
            $this->assertContains($p, $administrativo);
        }
        foreach ($administrativo as $p) {
            $this->assertContains($p, $administrador);
        }
        foreach ($administrador as $p) {
            $this->assertContains($p, $superAdmin);
        }

        $this->assertLessThan(count($administrativo), count($recepcion));
        $this->assertLessThan(count($administrador), count($administrativo));
        $this->assertLessThan(count($superAdmin), count($administrador));
    }

    public function test_ningun_rol_intermedio_tiene_permisos_elevados(): void
    {
        foreach ([
            Admin::ROLE_RECEPCION,
            Admin::ROLE_ADMINISTRATIVO,
            Admin::ROLE_ADMINISTRADOR,
        ] as $role) {
            $admin = new Admin(['role' => $role]);

            foreach (ModerationPermission::elevated() as $permission) {
                $this->assertFalse(
                    ModerationPermission::allows($admin, $permission),
                    "{$role} no debería tener {$permission}",
                );
            }
        }
    }

    public function test_un_admin_deshabilitado_no_pasa_el_guard(): void
    {
        $admin = $this->makeAdmin(Admin::ROLE_SUPER_ADMIN);
        $headers = $this->asAdmin($admin);

        $this->getJson('/api/admin/moderation/reports', $headers)->assertOk();

        $admin->forceFill(['status' => 'disabled'])->save();

        $this->getJson('/api/admin/moderation/reports', $headers)->assertStatus(403);
    }
}
