<?php

namespace Tests\Unit\Moderation;

use App\Models\Admin;
use App\Support\Moderation\ActionType;
use App\Support\Moderation\AuditSanitizer;
use App\Support\Moderation\ModerationPermission;
use App\Support\Moderation\ModerationScope;
use App\Support\Moderation\ReportReason;
use App\Support\Moderation\ReportStatus;
use Tests\TestCase;

/**
 * Reglas de dominio puras: catálogo, máquina de estados, jerarquía de alcances,
 * mapa de permisos y saneador de auditoría. Sin base de datos ni HTTP.
 */
class ModerationDomainTest extends TestCase
{
    // ── Catálogo de motivos ───────────────────────────────────────────────

    public function test_el_catalogo_cubre_los_motivos_exigidos(): void
    {
        $required = [
            'nudity_or_sexual_content', 'violence_or_graphic_content',
            'harassment_or_bullying', 'hate_speech', 'spam_or_scam',
            'dangerous_activity', 'self_harm', 'child_safety',
            'impersonation', 'privacy_violation', 'intellectual_property',
            'illegal_content', 'other',
        ];

        foreach ($required as $code) {
            $this->assertTrue(ReportReason::isValid($code), "Falta el motivo {$code}");
        }
    }

    public function test_los_motivos_criticos_tienen_maxima_prioridad(): void
    {
        $this->assertSame('critical', ReportReason::severityFor(ReportReason::CHILD_SAFETY));
        $this->assertSame('critical', ReportReason::severityFor(ReportReason::SELF_HARM));

        $this->assertGreaterThan(
            ReportReason::priorityFor(ReportReason::SPAM),
            ReportReason::priorityFor(ReportReason::CHILD_SAFETY),
        );
    }

    public function test_los_motivos_criticos_exigen_revision_humana(): void
    {
        $this->assertTrue(ReportReason::requiresHumanReview(ReportReason::CHILD_SAFETY));
        $this->assertTrue(ReportReason::requiresHumanReview(ReportReason::SELF_HARM));
        $this->assertFalse(ReportReason::requiresHumanReview(ReportReason::SPAM));
    }

    public function test_el_catalogo_para_el_cliente_no_filtra_datos_internos(): void
    {
        foreach (ReportReason::forClient() as $entry) {
            $this->assertSame(['code', 'label', 'description'], array_keys($entry));
        }
    }

    // ── Máquina de estados ────────────────────────────────────────────────

    public function test_transiciones_validas(): void
    {
        $this->assertTrue(ReportStatus::canTransition(
            ReportStatus::SUBMITTED, ReportStatus::TRIAGED));
        $this->assertTrue(ReportStatus::canTransition(
            ReportStatus::TRIAGED, ReportStatus::UNDER_REVIEW));
        $this->assertTrue(ReportStatus::canTransition(
            ReportStatus::UNDER_REVIEW, ReportStatus::ACTIONED));
        $this->assertTrue(ReportStatus::canTransition(
            ReportStatus::UNDER_REVIEW, ReportStatus::DISMISSED));
        $this->assertTrue(ReportStatus::canTransition(
            ReportStatus::ACTIONED, ReportStatus::APPEALED));
        $this->assertTrue(ReportStatus::canTransition(
            ReportStatus::APPEALED, ReportStatus::CLOSED));
        $this->assertTrue(ReportStatus::canTransition(
            ReportStatus::DISMISSED, ReportStatus::CLOSED));
    }

    public function test_transiciones_arbitrarias_estan_prohibidas(): void
    {
        $this->assertFalse(ReportStatus::canTransition(
            ReportStatus::SUBMITTED, ReportStatus::CLOSED));
        $this->assertFalse(ReportStatus::canTransition(
            ReportStatus::SUBMITTED, ReportStatus::ACTIONED));
        $this->assertFalse(ReportStatus::canTransition(
            ReportStatus::DISMISSED, ReportStatus::ACTIONED));
    }

    public function test_cerrado_es_terminal(): void
    {
        $this->assertSame([], ReportStatus::transitions()[ReportStatus::CLOSED]);
        $this->assertTrue(ReportStatus::isTerminal(ReportStatus::CLOSED));

        foreach (ReportStatus::all() as $status) {
            $this->assertFalse(
                ReportStatus::canTransition(ReportStatus::CLOSED, $status),
                "Un caso cerrado no debe poder pasar a {$status}",
            );
        }
    }

    public function test_los_estados_abiertos_no_incluyen_los_resueltos(): void
    {
        $this->assertNotContains(ReportStatus::CLOSED, ReportStatus::open());
        $this->assertNotContains(ReportStatus::DISMISSED, ReportStatus::open());
        $this->assertNotContains(ReportStatus::ACTIONED, ReportStatus::open());
        $this->assertContains(ReportStatus::SUBMITTED, ReportStatus::open());
    }

    // ── Jerarquía de alcances ─────────────────────────────────────────────

    public function test_full_app_access_implica_todos_los_alcances(): void
    {
        $implied = ModerationScope::implies(ModerationScope::FULL_APP_ACCESS);

        $this->assertContains(ModerationScope::SOCIAL_FEATURES, $implied);
        $this->assertContains(ModerationScope::STORY_POSTING, $implied);
        $this->assertContains(ModerationScope::STORY_INTERACTION, $implied);
    }

    public function test_story_posting_no_implica_interaccion(): void
    {
        $implied = ModerationScope::implies(ModerationScope::STORY_POSTING);

        $this->assertSame([ModerationScope::STORY_POSTING], $implied);
        $this->assertNotContains(ModerationScope::STORY_INTERACTION, $implied);
    }

    public function test_blocked_by_es_el_inverso_de_implies(): void
    {
        $blocking = ModerationScope::blockedBy(ModerationScope::STORY_POSTING);

        $this->assertContains(ModerationScope::STORY_POSTING, $blocking);
        $this->assertContains(ModerationScope::SOCIAL_FEATURES, $blocking);
        $this->assertContains(ModerationScope::FULL_APP_ACCESS, $blocking);
        $this->assertNotContains(ModerationScope::STORY_INTERACTION, $blocking);
    }

    public function test_la_explicacion_al_miembro_aclara_que_no_afecta_al_gimnasio(): void
    {
        foreach ([
            ModerationScope::STORY_POSTING,
            ModerationScope::SOCIAL_FEATURES,
            ModerationScope::FULL_APP_ACCESS,
        ] as $scope) {
            $text = strtolower(ModerationScope::memberExplanation($scope));
            $this->assertStringContainsString('membresía', $text);
        }
    }

    // ── Permisos ──────────────────────────────────────────────────────────

    public function test_ningun_rol_salvo_super_admin_tiene_permisos_elevados(): void
    {
        foreach (ModerationPermission::elevated() as $permission) {
            foreach ([
                Admin::ROLE_ADMINISTRADOR,
                Admin::ROLE_ADMINISTRATIVO,
                Admin::ROLE_RECEPCION,
            ] as $role) {
                $admin = new Admin(['role' => $role]);
                $this->assertFalse(
                    ModerationPermission::allows($admin, $permission),
                    "{$role} no debería tener {$permission}",
                );
            }
        }
    }

    public function test_el_token_de_automatizacion_solo_tiene_lectura(): void
    {
        $this->assertSame(
            [ModerationPermission::VIEW],
            ModerationPermission::forAdmin(null),
        );
    }

    public function test_recepcion_solo_ve(): void
    {
        $admin = new Admin(['role' => Admin::ROLE_RECEPCION]);

        $this->assertTrue(ModerationPermission::allows($admin, ModerationPermission::VIEW));
        $this->assertFalse(ModerationPermission::allows($admin, ModerationPermission::REVIEW));
        $this->assertFalse(ModerationPermission::allows($admin, ModerationPermission::SUSPEND_SOCIAL));
    }

    public function test_cada_accion_declara_un_permiso_conocido(): void
    {
        foreach (ActionType::all() as $action) {
            $this->assertContains(
                ActionType::requiredPermission($action),
                ModerationPermission::all(),
                "La acción {$action} declara un permiso inexistente",
            );
        }
    }

    public function test_eliminar_contenido_y_bloquear_la_app_son_elevados(): void
    {
        $this->assertTrue(ModerationPermission::isElevated(
            ActionType::requiredPermission(ActionType::REMOVE_CONTENT)));
        $this->assertTrue(ModerationPermission::isElevated(
            ActionType::requiredPermission(ActionType::SUSPEND_FULL)));
    }

    public function test_solo_las_acciones_de_sancion_crean_suspension(): void
    {
        $this->assertTrue(ActionType::createsSuspension(ActionType::RESTRICT_POSTING));
        $this->assertTrue(ActionType::createsSuspension(ActionType::SUSPEND_SOCIAL));
        $this->assertTrue(ActionType::createsSuspension(ActionType::SUSPEND_FULL));

        $this->assertFalse(ActionType::createsSuspension(ActionType::WARN));
        $this->assertFalse(ActionType::createsSuspension(ActionType::HIDE_CONTENT));
        $this->assertFalse(ActionType::createsSuspension(ActionType::DISMISS));
    }

    // ── Saneador de auditoría ─────────────────────────────────────────────

    public function test_el_saneador_elimina_secretos_y_pii(): void
    {
        $clean = AuditSanitizer::clean([
            'action' => 'warn',
            'token' => 'super-secreto',
            'access_token' => 'otro',
            'authorization' => 'Bearer x',
            'password' => '1234',
            'download_url' => 'https://firebase/x?token=y',
            'document' => '1010101010',
            'phone' => '3001234567',
            'email' => 'a@b.c',
            'ip' => '127.0.0.1',
            'nested' => ['api_key' => 'k', 'ok' => 'visible'],
        ]);

        $encoded = json_encode($clean);

        foreach (['super-secreto', 'Bearer', '1234', 'firebase', '1010101010',
            '3001234567', 'a@b.c', '127.0.0.1'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }

        $this->assertSame('warn', $clean['action']);
        $this->assertSame('visible', $clean['nested']['ok']);
    }

    public function test_el_saneador_neutraliza_html_y_recorta(): void
    {
        $clean = AuditSanitizer::clean([
            'note' => '<script>alert(1)</script>Texto real',
            'long' => str_repeat('x', 1000),
        ]);

        $this->assertStringNotContainsString('<script>', $clean['note']);
        $this->assertStringContainsString('Texto real', $clean['note']);
        $this->assertLessThanOrEqual(301, mb_strlen($clean['long']));
    }

    public function test_la_ip_se_guarda_hasheada_no_en_claro(): void
    {
        $hash = AuditSanitizer::hashIp('190.10.20.30');

        $this->assertNotSame('190.10.20.30', $hash);
        $this->assertSame(64, strlen((string) $hash));
        // Determinista: la misma IP produce el mismo hash (correlación posible
        // sin almacenar la dirección).
        $this->assertSame($hash, AuditSanitizer::hashIp('190.10.20.30'));
        $this->assertNull(AuditSanitizer::hashIp(null));
    }

    public function test_el_user_agent_se_resume(): void
    {
        $summary = AuditSanitizer::summarizeUserAgent(
            'Mozilla/5.0 (Linux; Android 14; SM-G991B) AppleWebKit/537.36 Chrome/120.0.0.0'
        );

        $this->assertSame('android/chrome', $summary);
        $this->assertNull(AuditSanitizer::summarizeUserAgent(''));
    }

    public function test_el_saneador_corta_la_profundidad(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => 'demasiado hondo']]]]];

        $encoded = json_encode(AuditSanitizer::clean($deep));

        $this->assertStringNotContainsString('demasiado hondo', $encoded);
        $this->assertStringContainsString('_truncated', $encoded);
    }
}
