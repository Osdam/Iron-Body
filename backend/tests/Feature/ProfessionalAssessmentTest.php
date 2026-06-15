<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\DeviceSessionService;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 7 (slice) — Valoraciones profesionales. Cubre el ciclo draft→submitted→
 * amended, la inmutabilidad, que llegan al miembro (solo lectura + notificación),
 * y la autorización (asignación, propiedad, permisos, aislamiento entre miembros).
 */
class ProfessionalAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private Trainer $trainer;

    private string $trainerToken;

    private Member $member;

    private string $memberToken;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
        ]);

        $this->trainer = Trainer::create([
            'full_name' => 'Coach', 'document' => '100', 'phone' => '+573009998877',
            'status' => 'active', 'location' => 'Sede Norte',
        ]);
        $this->member = Member::create([
            'full_name' => 'Member', 'document_number' => '200', 'phone' => '+573001112233',
            'status' => Member::STATUS_ACTIVE,
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $this->trainer->refresh();
        $this->trainer->syncRoles([TrainerRole::FLOOR]);
        MemberTrainerAssignment::create([
            'member_id' => $this->member->id, 'trainer_id' => $this->trainer->id, 'status' => 'active',
        ]);

        $this->trainerToken = $this->loginTrainer('100');
        $this->memberToken = app(DeviceSessionService::class)
            ->issueSession($this->member, ['device_id' => 'm1'])['token'];
    }

    private function loginTrainer(string $document): string
    {
        $access = $this->postJson('/api/trainer/auth/access', ['document' => $document, 'device_id' => 't1'])->assertOk();

        return $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $access->json('challenge_id'),
            'code' => $access->json('dev_code'),
            'device_id' => 't1',
        ])->assertOk()->json('token');
    }

    private function asTrainer(): array
    {
        return ['Authorization' => "Bearer {$this->trainerToken}"];
    }

    private function asMember(): array
    {
        return ['Authorization' => "Bearer {$this->memberToken}"];
    }

    private function createDraft(array $data = []): string
    {
        return $this->postJson("/api/trainer/members/{$this->member->id}/assessments", array_merge([
            'weight_kg' => 80.5, 'observations' => 'Buen progreso',
        ], $data), $this->asTrainer())->assertCreated()->json('data.uuid');
    }

    public function test_trainer_creates_submits_and_member_receives(): void
    {
        $uuid = $this->createDraft();

        // Borrador NO visible para el miembro todavía.
        $this->getJson('/api/member/assessments', $this->asMember())
            ->assertOk()->assertJsonCount(0, 'data');

        $this->postJson("/api/trainer/assessments/{$uuid}/submit", [], $this->asTrainer())
            ->assertOk()->assertJsonPath('data.status', 'submitted');

        // Ahora el miembro la ve (solo lectura) y le llegó notificación.
        $this->getJson('/api/member/assessments', $this->asMember())
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $uuid)
            ->assertJsonPath('data.0.is_editable', false);

        $this->assertDatabaseHas('notifications', [
            'member_id' => $this->member->id, 'type' => 'professional_assessment',
        ]);
    }

    public function test_member_notification_carries_deeplink_payload(): void
    {
        $uuid = $this->createDraft();
        $this->postJson("/api/trainer/assessments/{$uuid}/submit", [], $this->asTrainer())->assertOk();

        // El miembro lo recibe por su canal real con el payload del deep link.
        $this->getJson('/api/notifications', $this->asMember())
            ->assertOk()
            ->assertJsonFragment(['type' => 'professional_assessment'])
            ->assertJsonFragment(['assessment_uuid' => $uuid]);
    }

    public function test_submitted_assessment_is_immutable(): void
    {
        $uuid = $this->createDraft();
        $this->postJson("/api/trainer/assessments/{$uuid}/submit", [], $this->asTrainer())->assertOk();

        // Editar una enviada: 409. Reenviar: 409 (idempotente por estado).
        $this->putJson("/api/trainer/assessments/{$uuid}", ['weight_kg' => 99], $this->asTrainer())
            ->assertStatus(409);
        $this->postJson("/api/trainer/assessments/{$uuid}/submit", [], $this->asTrainer())
            ->assertStatus(409);

        $this->assertEquals('80.50', ProfessionalAssessment::where('uuid', $uuid)->value('weight_kg'));
    }

    public function test_amendment_creates_new_version_and_supersedes(): void
    {
        $uuid = $this->createDraft();
        $this->postJson("/api/trainer/assessments/{$uuid}/submit", [], $this->asTrainer())->assertOk();

        $amend = $this->postJson("/api/trainer/assessments/{$uuid}/amend", [
            'weight_kg' => 78.0, 'amendment_reason' => 'Corrige peso mal digitado',
        ], $this->asTrainer())->assertCreated();

        $this->assertSame(2, $amend->json('data.version'));
        $this->assertSame($uuid, $amend->json('data.parent_uuid'));
        // La original queda como histórico (amended), la nueva es submitted.
        $this->assertSame('amended', ProfessionalAssessment::where('uuid', $uuid)->value('status'));

        // El miembro ve ambas versiones (enviada + corrección).
        $this->getJson('/api/member/assessments', $this->asMember())
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_amendment_requires_reason(): void
    {
        $uuid = $this->createDraft();
        $this->postJson("/api/trainer/assessments/{$uuid}/submit", [], $this->asTrainer())->assertOk();

        $this->postJson("/api/trainer/assessments/{$uuid}/amend", ['weight_kg' => 70], $this->asTrainer())
            ->assertStatus(422);
    }

    public function test_member_can_acknowledge_but_not_edit(): void
    {
        $uuid = $this->createDraft();
        $this->postJson("/api/trainer/assessments/{$uuid}/submit", [], $this->asTrainer())->assertOk();

        $this->postJson("/api/member/assessments/{$uuid}/ack", [], $this->asMember())->assertOk();
        $this->assertNotNull(ProfessionalAssessment::where('uuid', $uuid)->value('acknowledged_at'));

        // El miembro NO tiene endpoint para editar medidas (la ruta no existe).
        $this->putJson("/api/trainer/assessments/{$uuid}", ['weight_kg' => 1], $this->asMember())
            ->assertStatus(401);
    }

    public function test_trainer_cannot_access_unassigned_member(): void
    {
        $other = Member::create([
            'full_name' => 'Other', 'document_number' => '999', 'phone' => '+573000000000',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $this->postJson("/api/trainer/members/{$other->id}/assessments", ['weight_kg' => 70], $this->asTrainer())
            ->assertStatus(403);
    }

    public function test_member_cannot_see_other_members_assessment(): void
    {
        $uuid = $this->createDraft();
        $this->postJson("/api/trainer/assessments/{$uuid}/submit", [], $this->asTrainer())->assertOk();

        $other = Member::create([
            'full_name' => 'Other', 'document_number' => '999', 'phone' => '+573000000000',
            'status' => Member::STATUS_ACTIVE,
        ]);
        $otherToken = app(DeviceSessionService::class)->issueSession($other, ['device_id' => 'o1'])['token'];

        $this->getJson("/api/member/assessments/{$uuid}", ['Authorization' => "Bearer {$otherToken}"])
            ->assertStatus(404);
    }

    public function test_trainer_without_permission_cannot_create(): void
    {
        // Entrenador asignado pero SIN roles ⇒ sin permiso assessments.create.
        $this->trainer->syncRoles([]);

        $this->postJson("/api/trainer/members/{$this->member->id}/assessments", ['weight_kg' => 70], $this->asTrainer())
            ->assertStatus(403);
    }

    public function test_trainer_lists_only_assigned_members(): void
    {
        // Otro miembro NO asignado a este entrenador.
        Member::create([
            'full_name' => 'Unassigned', 'document_number' => '888', 'phone' => '+573000000001',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $this->getJson('/api/trainer/members', $this->asTrainer())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->member->id);
    }

    public function test_routes_hidden_when_feature_off(): void
    {
        config(['trainer.flags.professional_assessments_enabled' => false, 'trainer.pilot_identities' => []]);

        $this->postJson("/api/trainer/members/{$this->member->id}/assessments", ['weight_kg' => 70], $this->asTrainer())
            ->assertStatus(404);
        $this->getJson('/api/member/assessments', $this->asMember())->assertStatus(404);
    }
}
