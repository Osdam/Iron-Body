<?php

namespace Tests\Feature\Moderation;

use App\Models\Admin;
use App\Models\ContentReport;
use App\Models\Member;
use App\Models\MemberSuspension;
use App\Models\ModerationAction;
use App\Models\ModerationAppeal;

/**
 * Apelaciones: elegibilidad, unicidad, anti-IDOR, efecto real de aceptarlas y
 * confidencialidad de las notas internas.
 */
class AppealTest extends ModerationTestCase
{
    /**
     * Crea un caso real y aplica una sanción, devolviendo el miembro
     * sancionado y el `public_id` de la acción.
     *
     * @return array{member: Member, action_id: string}
     */
    private function sanction(int $durationMinutes = 1440): array
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report",
            ['reason_code' => 'harassment_or_bullying'], $this->asMember($reporter))
            ->assertCreated();

        $report = ContentReport::firstOrFail();
        $headers = $this->asAdmin($this->makeAdmin());

        $actionId = $this->postJson(
            "/api/admin/moderation/reports/{$report->public_id}/decision",
            [
                'action_type' => 'restrict_posting',
                'duration_minutes' => $durationMinutes,
                'public_reason' => 'Contenido contrario a los lineamientos.',
                'internal_notes' => 'NOTA INTERNA: reportado por Reportante',
            ],
            $headers,
        )->assertCreated()->json('data.action_id');

        return ['member' => $author->fresh(), 'action_id' => $actionId];
    }

    public function test_apelacion_valida_se_registra(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();

        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Creo que hubo un malentendido con el video que publiqué.',
        ], $this->asMember($member))
            ->assertCreated()
            ->assertJsonPath('data.status', ModerationAppeal::STATUS_SUBMITTED);

        $this->assertDatabaseHas('moderation_appeals', [
            'member_id' => $member->id,
            'status' => ModerationAppeal::STATUS_SUBMITTED,
        ]);
    }

    public function test_doble_apelacion_abierta_es_rechazada(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();

        $body = ['appeal_text' => 'Quiero que revisen mi caso otra vez, por favor.'];

        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", $body,
            $this->asMember($member))->assertCreated();

        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", $body,
            $this->asMember($member))
            ->assertStatus(409)
            ->assertJsonPath('code', 'appeal_already_open');

        $this->assertDatabaseCount('moderation_appeals', 1);
    }

    public function test_no_se_puede_apelar_la_sancion_de_otra_persona(): void
    {
        ['action_id' => $actionId] = $this->sanction();
        $intruso = $this->makeMember('Intruso');

        // 404 (no 403): no confirmamos siquiera que la acción exista.
        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Quiero apelar una sanción que no es mía.',
        ], $this->asMember($intruso))
            ->assertStatus(404)
            ->assertJsonPath('code', 'action_not_found');

        $this->assertDatabaseCount('moderation_appeals', 0);
    }

    public function test_texto_demasiado_corto_o_largo_es_422(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();

        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal",
            ['appeal_text' => 'no'], $this->asMember($member))
            ->assertStatus(422)
            ->assertJsonValidationErrors('appeal_text');

        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal",
            ['appeal_text' => str_repeat('a', 5000)], $this->asMember($member))
            ->assertStatus(422)
            ->assertJsonValidationErrors('appeal_text');
    }

    public function test_aceptar_la_apelacion_revoca_la_sancion_de_verdad(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();

        $appealId = $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'El contenido no infringía los lineamientos, por favor revisen.',
        ], $this->asMember($member))->assertCreated()->json('data.appeal_id');

        // Antes: no puede publicar.
        $this->getJson('/api/app/moderation/status', $this->asMember($member))
            ->assertJsonPath('data.can_post_stories', false);

        $this->postJson("/api/admin/moderation/appeals/{$appealId}/resolve", [
            'status' => ModerationAppeal::STATUS_GRANTED,
            'public_resolution' => 'Revisamos tu caso y retiramos la restricción.',
            'internal_notes' => 'Falso positivo del moderador anterior.',
        ], $this->asAdmin($this->makeAdmin()))->assertOk();

        // Después: la restricción desapareció de verdad.
        $this->getJson('/api/app/moderation/status', $this->asMember($member))
            ->assertJsonPath('data.can_post_stories', true);

        $this->assertDatabaseHas('member_suspensions', [
            'member_id' => $member->id,
            'status' => MemberSuspension::STATUS_REVOKED,
        ]);
    }

    public function test_mantener_la_sancion_no_la_levanta(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();

        $appealId = $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Por favor reconsideren la decisión sobre mi estado.',
        ], $this->asMember($member))->json('data.appeal_id');

        $this->postJson("/api/admin/moderation/appeals/{$appealId}/resolve", [
            'status' => ModerationAppeal::STATUS_UPHELD,
            'public_resolution' => 'Mantenemos la decisión.',
        ], $this->asAdmin($this->makeAdmin()))->assertOk();

        $this->getJson('/api/app/moderation/status', $this->asMember($member))
            ->assertJsonPath('data.can_post_stories', false);
    }

    public function test_dos_moderadores_no_resuelven_la_misma_apelacion_dos_veces(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();

        $appealId = $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Solicito la revisión de la medida aplicada a mi cuenta.',
        ], $this->asMember($member))->json('data.appeal_id');

        $headers = $this->asAdmin($this->makeAdmin());

        $this->postJson("/api/admin/moderation/appeals/{$appealId}/resolve",
            ['status' => ModerationAppeal::STATUS_UPHELD], $headers)->assertOk();

        $this->postJson("/api/admin/moderation/appeals/{$appealId}/resolve",
            ['status' => ModerationAppeal::STATUS_GRANTED], $headers)
            ->assertStatus(409)
            ->assertJsonPath('code', 'appeal_already_resolved');

        // La segunda resolución no revocó nada.
        $this->getJson('/api/app/moderation/status', $this->asMember($member))
            ->assertJsonPath('data.can_post_stories', false);
    }

    public function test_resolver_apelaciones_exige_permiso(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();

        $appealId = $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Solicito la revisión de la medida aplicada a mi cuenta.',
        ], $this->asMember($member))->json('data.appeal_id');

        $this->postJson("/api/admin/moderation/appeals/{$appealId}/resolve",
            ['status' => ModerationAppeal::STATUS_GRANTED],
            $this->asAdmin($this->makeAdmin(Admin::ROLE_RECEPCION)))
            ->assertStatus(403)
            // La puerta exterior deniega antes de llegar al permiso fino.
            ->assertJsonPath('required_permission', 'moderation.manage');
    }

    public function test_la_app_nunca_ve_notas_internas_ni_al_reportante(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();

        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Solicito la revisión de la medida aplicada a mi cuenta.',
        ], $this->asMember($member))->assertCreated();

        $appeal = ModerationAppeal::firstOrFail();
        $this->postJson("/api/admin/moderation/appeals/{$appeal->public_id}/resolve", [
            'status' => ModerationAppeal::STATUS_UPHELD,
            'internal_notes' => 'SOLO INTERNO: el reportante fue Reportante',
            'public_resolution' => 'Mantenemos la decisión.',
        ], $this->asAdmin($this->makeAdmin()))->assertOk();

        $res = $this->getJson('/api/app/moderation/actions', $this->asMember($member))
            ->assertOk();

        $body = json_encode($res->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SOLO INTERNO', $body);
        $this->assertStringNotContainsString('NOTA INTERNA', $body);
        $this->assertStringNotContainsString('Reportante', $body);
        $this->assertStringNotContainsString('resolution_notes', $body);
        // Sí llega el mensaje PÚBLICO de la resolución.
        $this->assertStringContainsString('Mantenemos la decisión.', $body);
        // Y la medida deja de ser apelable: la decisión es definitiva.
        $this->assertFalse($res->json('data.0.can_appeal'));
    }

    public function test_no_se_puede_apelar_dos_veces_la_misma_medida(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();

        $appealId = $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Solicito la revisión de la medida aplicada a mi cuenta.',
        ], $this->asMember($member))->json('data.appeal_id');

        $this->postJson("/api/admin/moderation/appeals/{$appealId}/resolve",
            ['status' => ModerationAppeal::STATUS_UPHELD],
            $this->asAdmin($this->makeAdmin()))->assertOk();

        // Reabrir la misma medida no es posible (anti-spam de apelaciones).
        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Insisto en que revisen mi caso una vez más.',
        ], $this->asMember($member))
            ->assertStatus(409)
            ->assertJsonPath('code', 'appeal_already_resolved');

        $this->assertDatabaseCount('moderation_appeals', 1);
    }

    public function test_una_sancion_caducada_ya_no_es_apelable(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction(1);

        // La restricción termina.
        ModerationAction::where('public_id', $actionId)
            ->update(['ends_at' => now()->subMinute()]);

        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Quiero apelar una restricción que ya terminó.',
        ], $this->asMember($member))
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_appealable');
    }

    public function test_apelaciones_desactivadas_responden_503(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();
        config(['ugc.appeals_enabled' => false]);

        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Solicito la revisión de la medida aplicada a mi cuenta.',
        ], $this->asMember($member))
            ->assertStatus(503)
            ->assertJsonPath('code', 'appeals_disabled');
    }

    public function test_apelacion_queda_en_auditoria(): void
    {
        ['member' => $member, 'action_id' => $actionId] = $this->sanction();

        $this->postJson("/api/app/moderation/actions/{$actionId}/appeal", [
            'appeal_text' => 'Solicito la revisión de la medida aplicada a mi cuenta.',
        ], $this->asMember($member))->assertCreated();

        $this->assertDatabaseHas('moderation_audit_logs', [
            'actor_type' => 'member',
            'actor_id' => $member->id,
            'action' => 'appeal_submitted',
        ]);
    }
}
