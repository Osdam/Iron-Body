<?php

namespace Tests\Feature;

use App\Models\Identity;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\TrainerDeviceSession;
use App\Models\TrainerRole;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Consolidación: el módulo CRM /trainers existente (TrainerController) es la
 * ÚNICA fuente administrativa. Al crear/editar desde ahí, el entrenador queda
 * vinculado a su identidad, roles y acceso al portal, sin CRUD ni tabla paralela
 * y sin duplicar identidades por documento.
 */
class TrainerCrmIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_trainer_links_an_identity(): void
    {
        $res = $this->postJson('/api/trainers', [
            'fullName' => 'Nuevo Coach',
            'document' => '12.345.678',
            'phone' => '+573009998877',
        ])->assertStatus(201);

        $trainer = Trainer::firstOrFail();
        $this->assertNotNull($trainer->identity_id);
        $this->assertSame('12345678', $trainer->identity->document_normalized);
        $this->assertSame($trainer->identity_id, $res->json('identityId'));
        $this->assertSame(1, Identity::count());
    }

    public function test_trainer_who_is_already_a_member_reuses_identity(): void
    {
        $member = Member::create([
            'full_name' => 'Same Person', 'document_number' => '777',
            'phone' => '+573001112233', 'status' => Member::STATUS_ACTIVE,
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $member->refresh();

        $this->postJson('/api/trainers', [
            'fullName' => 'Same Person', 'document' => '777', 'phone' => '+573001112233',
        ])->assertStatus(201);

        $trainer = Trainer::firstOrFail();
        // MISMA identidad; el miembro y su estado intactos.
        $this->assertSame($member->identity_id, $trainer->identity_id);
        $this->assertSame(1, Identity::count());
        $this->assertSame(Member::STATUS_ACTIVE, $member->fresh()->status);
    }

    public function test_no_duplicate_identity_for_same_document(): void
    {
        $this->postJson('/api/trainers', ['fullName' => 'A', 'document' => '900'])->assertStatus(201);
        $this->postJson('/api/trainers', ['fullName' => 'B', 'document' => '900'])->assertStatus(201);

        // Dos registros de entrenador, UNA sola identidad.
        $this->assertSame(2, Trainer::count());
        $this->assertSame(1, Identity::count());
        $ids = Trainer::pluck('identity_id')->unique();
        $this->assertCount(1, $ids);
    }

    public function test_creating_with_roles_enables_portal_access(): void
    {
        $res = $this->postJson('/api/trainers', [
            'fullName' => 'Coach', 'document' => '111', 'phone' => '+573009998877',
            'roles' => [TrainerRole::FLOOR, TrainerRole::FUNCTIONAL],
        ])->assertStatus(201);

        $this->assertEqualsCanonicalizing(
            [TrainerRole::FLOOR, TrainerRole::FUNCTIONAL],
            $res->json('roles'),
        );
        $this->assertTrue($res->json('portalAccess'));
        $this->assertContains('classes.manage', $res->json('permissions'));
    }

    public function test_creating_without_roles_has_no_portal_access(): void
    {
        $res = $this->postJson('/api/trainers', [
            'fullName' => 'Coach', 'document' => '222', 'phone' => '+573009998877',
        ])->assertStatus(201);

        $this->assertSame([], $res->json('roles'));
        $this->assertFalse($res->json('portalAccess'));
    }

    public function test_editing_preserves_identity_and_updates_roles(): void
    {
        $create = $this->postJson('/api/trainers', [
            'fullName' => 'Coach', 'document' => '333', 'phone' => '+573009998877',
            'roles' => [TrainerRole::FLOOR],
        ])->assertStatus(201);
        $id = (int) $create->json('id');
        $identityId = $create->json('identityId');

        $res = $this->putJson("/api/trainers/{$id}", [
            'roles' => [TrainerRole::FUNCTIONAL],
            'location' => 'Sede Norte',
        ])->assertOk();

        $this->assertSame($identityId, $res->json('identityId'), 'la identidad se conserva');
        $this->assertSame([TrainerRole::FUNCTIONAL], $res->json('roles'));
        $this->assertSame('Sede Norte', $res->json('location'));
    }

    public function test_deactivating_via_edit_revokes_sessions_keeps_member(): void
    {
        $member = Member::create([
            'full_name' => 'Dual', 'document_number' => '444',
            'phone' => '+573001112233', 'status' => Member::STATUS_ACTIVE,
        ]);
        app(IdentityLinkService::class)->backfillExisting();

        $create = $this->postJson('/api/trainers', [
            'fullName' => 'Dual', 'document' => '444', 'phone' => '+573001112233',
            'roles' => [TrainerRole::FLOOR],
        ])->assertStatus(201);
        $id = (int) $create->json('id');

        // Sesión profesional viva.
        $session = TrainerDeviceSession::create([
            'trainer_id' => $id, 'device_id' => 'd1', 'token_hash' => hash('sha256', 'tok'),
        ]);

        $this->putJson("/api/trainers/{$id}", ['status' => 'inactive'])->assertOk();

        // Acceso profesional cortado…
        $this->assertNotNull($session->fresh()->revoked_at);
        // …pero la cuenta de miembro y su membresía intactas.
        $this->assertSame(Member::STATUS_ACTIVE, $member->fresh()->status);
        $this->assertSame($member->fresh()->identity_id, Trainer::find($id)->identity_id);
    }

    public function test_admin_list_exposes_professional_fields(): void
    {
        $this->postJson('/api/trainers', [
            'fullName' => 'Coach', 'document' => '555', 'phone' => '+573009998877',
            'roles' => [TrainerRole::FLOOR],
        ])->assertStatus(201);

        $this->getJson('/api/trainers?admin=1')
            ->assertOk()
            ->assertJsonFragment(['roles' => [TrainerRole::FLOOR]])
            ->assertJsonFragment(['portalAccess' => true]);
    }

    public function test_existing_mobile_listing_still_works(): void
    {
        $this->postJson('/api/trainers', [
            'fullName' => 'Coach', 'document' => '666', 'phone' => '+573009998877',
            'status' => 'active',
        ])->assertStatus(201);

        // El endpoint público (app) sigue respondiendo igual (contrato intacto).
        $this->getJson('/api/trainers')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }
}
