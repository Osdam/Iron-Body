<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\TrainerRole;
use App\Services\Identity\IdentityLinkService;
use App\Services\Trainer\TrainerAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 3 — CRM de entrenadores. Verifica la administración profesional (roles,
 * sede, enlace de identidad, activación/desactivación, auditoría) y los
 * invariantes de desactivación (conserva miembro/identidad/evidencia).
 */
class TrainerAdminTest extends TestCase
{
    use RefreshDatabase;

    private function trainer(array $attrs = []): Trainer
    {
        return Trainer::create(array_merge([
            'full_name' => 'Pro Trainer',
            'document' => '100200300',
            'phone' => '+573009998877',
            'status' => 'active',
        ], $attrs));
    }

    public function test_show_returns_professional_view(): void
    {
        $trainer = $this->trainer();
        $trainer->syncRoles([TrainerRole::FUNCTIONAL]);

        $this->getJson("/api/admin/trainers/{$trainer->id}/professional")
            ->assertOk()
            ->assertJsonPath('data.roles', [TrainerRole::FUNCTIONAL])
            ->assertJsonFragment(['classes.manage']);
    }

    public function test_update_professional_sets_roles_and_location_and_audits(): void
    {
        $trainer = $this->trainer();

        $this->putJson("/api/admin/trainers/{$trainer->id}/professional", [
            'roles' => [TrainerRole::FLOOR, TrainerRole::FUNCTIONAL],
            'location' => 'Sede Norte',
            'admin_id' => 7,
        ])->assertOk()
            ->assertJsonPath('data.location', 'Sede Norte')
            ->assertJsonCount(2, 'data.roles');

        $this->assertSame('Sede Norte', $trainer->fresh()->location);
        $this->assertDatabaseHas('trainer_audit_logs', [
            'trainer_id' => $trainer->id,
            'event' => TrainerAuditLog::EVENT_ROLES_UPDATED,
            'actor_id' => 7,
        ]);
    }

    public function test_update_professional_rejects_invalid_role(): void
    {
        $trainer = $this->trainer();

        $this->putJson("/api/admin/trainers/{$trainer->id}/professional", [
            'roles' => ['superadmin'],
        ])->assertStatus(422);
    }

    public function test_link_identity_by_document_creates_and_links(): void
    {
        $trainer = $this->trainer(['document' => null]);

        $this->postJson("/api/admin/trainers/{$trainer->id}/identity/link", [
            'document' => '55.667.788',
            'admin_id' => 3,
        ])->assertOk();

        $trainer->refresh();
        $this->assertNotNull($trainer->identity_id);
        $this->assertSame('55667788', $trainer->identity->document_normalized);

        // La auditoría guarda el id de identidad, NUNCA el documento.
        $log = TrainerAuditLog::where('event', TrainerAuditLog::EVENT_IDENTITY_LINKED)->first();
        $this->assertNotNull($log);
        $this->assertSame($trainer->identity_id, $log->metadata['identity_id']);
        $this->assertArrayNotHasKey('document', $log->metadata ?? []);
    }

    public function test_link_identity_by_existing_identity_id(): void
    {
        $trainer = $this->trainer();
        $identity = app(IdentityLinkService::class)->ensureIdentity('900900900');

        $this->postJson("/api/admin/trainers/{$trainer->id}/identity/link", [
            'identity_id' => $identity->id,
        ])->assertOk();

        $this->assertSame($identity->id, $trainer->fresh()->identity_id);
    }

    public function test_deactivate_blocks_access_but_preserves_member_and_identity(): void
    {
        // Persona que es entrenador Y miembro (misma identidad).
        $trainer = $this->trainer(['document' => '123123123']);
        $trainer->syncRoles([TrainerRole::FLOOR]);
        $member = Member::create([
            'full_name' => 'Same Person',
            'document_number' => '123123123',
            'phone' => '+573001112233',
            'status' => Member::STATUS_ACTIVE,
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $member->refresh();
        $trainer->refresh();
        $this->assertSame($member->identity_id, $trainer->identity_id);

        $this->postJson("/api/admin/trainers/{$trainer->id}/deactivate", ['admin_id' => 9])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.permissions', []);

        $trainer->refresh();
        $member->refresh();
        $this->assertSame('inactive', $trainer->status);
        $this->assertFalse($trainer->fresh('roleAssignments')->hasPermission('trainer.portal.access'));
        // El miembro y la identidad se conservan intactos.
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertSame($trainer->identity_id, $member->identity_id);
        $this->assertDatabaseHas('trainer_audit_logs', [
            'trainer_id' => $trainer->id,
            'event' => TrainerAuditLog::EVENT_DEACTIVATED,
        ]);
    }

    public function test_activate_restores_access(): void
    {
        $trainer = $this->trainer(['status' => 'inactive']);
        $trainer->syncRoles([TrainerRole::FUNCTIONAL]);

        $this->postJson("/api/admin/trainers/{$trainer->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($trainer->fresh('roleAssignments')->hasPermission('classes.manage'));
    }

    public function test_audit_endpoint_returns_history_newest_first(): void
    {
        $trainer = $this->trainer();

        $this->postJson("/api/admin/trainers/{$trainer->id}/deactivate");
        $this->postJson("/api/admin/trainers/{$trainer->id}/activate");

        $response = $this->getJson("/api/admin/trainers/{$trainer->id}/audit")->assertOk();
        $events = collect($response->json('data'))->pluck('event')->all();

        $this->assertSame(TrainerAuditLog::EVENT_ACTIVATED, $events[0]);
        $this->assertContains(TrainerAuditLog::EVENT_DEACTIVATED, $events);
    }

    public function test_audit_service_strips_sensitive_keys(): void
    {
        $trainer = $this->trainer();

        app(TrainerAuditService::class)->record(
            'test.event',
            $trainer,
            metadata: ['otp' => '123456', 'token' => 'abc', 'document' => '999', 'safe' => 'ok'],
        );

        $log = TrainerAuditLog::where('event', 'test.event')->first();
        $this->assertSame(['safe' => 'ok'], $log->metadata);
    }
}
