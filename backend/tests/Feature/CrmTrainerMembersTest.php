<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\Trainer;
use App\Models\TrainerRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asignación de miembros a entrenadores DESDE el módulo CRM /trainers (Blade),
 * y su reflejo en el portal (`/trainer/members`). El backend es la autoridad;
 * la app solo consume los asignados.
 */
class CrmTrainerMembersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.professional_assessments_enabled' => true,
        ]);
    }

    private function trainerViaCrm(string $document = '100'): Trainer
    {
        $this->post(route('crm.trainers.store'), [
            'name' => 'Coach', 'specialty' => 'Funcional',
            'document' => $document, 'phone' => '+5730099988'.substr($document, -2),
            'roles' => [TrainerRole::FLOOR], 'is_active' => '1',
        ])->assertRedirect();

        return Trainer::where('document', $document)->firstOrFail();
    }

    private function member(string $name, string $doc, string $phone = '+573001112233'): Member
    {
        return Member::create([
            'full_name' => $name, 'document_number' => $doc,
            'phone' => $phone, 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function trainerToken(string $document): string
    {
        $access = $this->postJson('/api/trainer/auth/access', ['document' => $document, 'device_id' => 'd'])->assertOk();

        return $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $access->json('challenge_id'),
            'code' => $access->json('dev_code'),
            'device_id' => 'd',
        ])->assertOk()->json('token');
    }

    public function test_assigning_members_makes_them_appear_in_the_portal(): void
    {
        $trainer = $this->trainerViaCrm();
        $m1 = $this->member('Ana Gomez', '201');
        $m2 = $this->member('Beto Ruiz', '202', '+573004445566');

        $this->post(route('crm.trainers.members.assign', $trainer), [
            'member_ids' => [$m1->id, $m2->id],
        ])->assertRedirect();

        $token = $this->trainerToken('100');
        $this->getJson('/api/trainer/members', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['full_name' => 'Ana Gomez'])
            ->assertJsonFragment(['full_name' => 'Beto Ruiz']);
    }

    public function test_trainer_without_members_gets_empty_list(): void
    {
        $this->trainerViaCrm();
        $token = $this->trainerToken('100');

        $this->getJson('/api/trainer/members', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_assignment_is_not_duplicated(): void
    {
        $trainer = $this->trainerViaCrm();
        $member = $this->member('Ana', '201');

        $this->post(route('crm.trainers.members.assign', $trainer), ['member_ids' => [$member->id]])->assertRedirect();
        $this->post(route('crm.trainers.members.assign', $trainer), ['member_ids' => [$member->id]])->assertRedirect();

        $active = MemberTrainerAssignment::where('trainer_id', $trainer->id)
            ->where('member_id', $member->id)
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->count();
        $this->assertSame(1, $active);
    }

    public function test_unassigning_removes_from_portal(): void
    {
        $trainer = $this->trainerViaCrm();
        $member = $this->member('Ana', '201');
        $this->post(route('crm.trainers.members.assign', $trainer), ['member_ids' => [$member->id]])->assertRedirect();

        $this->delete(route('crm.trainers.members.unassign', ['trainer' => $trainer, 'member' => $member]))
            ->assertRedirect();

        $token = $this->trainerToken('100');
        $this->getJson('/api/trainer/members', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame('inactive', MemberTrainerAssignment::where('member_id', $member->id)->value('status'));
    }

    public function test_search_finds_active_members_by_name_document_or_phone(): void
    {
        $trainer = $this->trainerViaCrm();
        $this->member('Carlos Pérez', '987654', '+573009990000');

        foreach (['Carlos', '987654', '9990000'] as $term) {
            $this->get(route('crm.trainers.edit', ['trainer' => $trainer, 'member_q' => $term]))
                ->assertOk()
                ->assertSee('Carlos Pérez');
        }
    }

    public function test_edit_page_renders_assigned_members_section(): void
    {
        $trainer = $this->trainerViaCrm();

        $this->get(route('crm.trainers.edit', $trainer))
            ->assertOk()
            ->assertSee('Miembros asignados')
            ->assertSee('Asignar miembros');
    }
}
