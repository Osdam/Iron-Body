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
 * El módulo CRM Blade existente (/crm/trainers, Crm\TrainerController) integrado
 * con el portal: crear/editar/desactivar vincula identidad, roles y corta acceso,
 * sobre el MISMO registro y tabla, sin CRUD paralelo.
 */
class CrmTrainerBladeTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_create_links_identity_roles_and_maps_portal_fields(): void
    {
        $this->post(route('crm.trainers.store'), [
            'name' => 'Coach', 'specialty' => 'Funcional',
            'document' => '12.345.678', 'phone' => '+573009998877',
            'location' => 'Sede Norte', 'roles' => ['trainer_functional'], 'is_active' => '1',
        ])->assertRedirect(route('crm.trainers.index'));

        $trainer = Trainer::firstOrFail()->fresh('roleAssignments');
        $this->assertSame('12.345.678', $trainer->document);
        $this->assertSame('+573009998877', $trainer->phone);
        $this->assertSame('Sede Norte', $trainer->location);
        $this->assertNotNull($trainer->identity_id);
        $this->assertSame('12345678', $trainer->identity->document_normalized);
        $this->assertSame([TrainerRole::FUNCTIONAL], $trainer->roleNames());
    }

    public function test_crm_create_reuses_member_identity(): void
    {
        $member = Member::create([
            'full_name' => 'Same', 'document_number' => '777',
            'phone' => '+573001112233', 'status' => Member::STATUS_ACTIVE,
        ]);
        app(IdentityLinkService::class)->backfillExisting();

        $this->post(route('crm.trainers.store'), [
            'name' => 'Same', 'specialty' => 'Planta', 'document' => '777',
        ])->assertRedirect();

        $this->assertSame(1, Identity::count());
        $this->assertSame($member->fresh()->identity_id, Trainer::first()->identity_id);
        $this->assertSame(Member::STATUS_ACTIVE, $member->fresh()->status);
    }

    public function test_crm_edit_preserves_identity_and_updates_roles(): void
    {
        $this->post(route('crm.trainers.store'), [
            'name' => 'Coach', 'specialty' => 'X', 'document' => '333',
            'phone' => '+573009998877', 'roles' => ['trainer_floor'],
        ])->assertRedirect();
        $trainer = Trainer::firstOrFail();
        $identityId = $trainer->identity_id;

        $this->put(route('crm.trainers.update', $trainer), [
            'name' => 'Coach', 'specialty' => 'X',
            'roles' => ['trainer_functional'], 'is_active' => '1',
        ])->assertRedirect();

        $trainer->refresh()->load('roleAssignments');
        $this->assertSame($identityId, $trainer->identity_id);
        $this->assertSame([TrainerRole::FUNCTIONAL], $trainer->roleNames());
    }

    public function test_crm_deactivate_revokes_sessions_keeps_member(): void
    {
        $member = Member::create([
            'full_name' => 'Dual', 'document_number' => '444',
            'phone' => '+573001112233', 'status' => Member::STATUS_ACTIVE,
        ]);
        app(IdentityLinkService::class)->backfillExisting();

        $this->post(route('crm.trainers.store'), [
            'name' => 'Dual', 'specialty' => 'X', 'document' => '444',
            'phone' => '+573001112233', 'roles' => ['trainer_floor'],
        ])->assertRedirect();
        $trainer = Trainer::firstOrFail();
        $session = TrainerDeviceSession::create([
            'trainer_id' => $trainer->id, 'device_id' => 'd1', 'token_hash' => hash('sha256', 't'),
        ]);

        $this->delete(route('crm.trainers.destroy', $trainer))->assertRedirect();

        $this->assertSame('inactive', $trainer->fresh()->status);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertSame(Member::STATUS_ACTIVE, $member->fresh()->status);
    }

    public function test_crm_index_renders_with_portal_column(): void
    {
        $this->post(route('crm.trainers.store'), [
            'name' => 'Coach', 'specialty' => 'X', 'document' => '555',
            'phone' => '+573009998877', 'roles' => ['trainer_functional'],
        ])->assertRedirect();

        $this->get(route('crm.trainers.index'))
            ->assertOk()
            ->assertSee('Portal')
            ->assertSee('Funcional');
    }

    public function test_crm_module_still_works_without_portal_fields(): void
    {
        // Compatibilidad: crear sin documento/rol sigue funcionando (como hoy).
        $this->post(route('crm.trainers.store'), [
            'name' => 'Legacy', 'specialty' => 'Spinning', 'is_active' => '1',
        ])->assertRedirect(route('crm.trainers.index'));

        $this->assertDatabaseHas('trainers', ['full_name' => 'Legacy', 'main_specialty' => 'Spinning']);
    }
}
