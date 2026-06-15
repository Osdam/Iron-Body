<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\Trainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoints admin JSON de miembros asignados a un entrenador, que consume el CRM
 * Angular. Reutilizan member_trainer_assignments (sin tabla/CRUD paralelo).
 */
class AdminTrainerMembersApiTest extends TestCase
{
    use RefreshDatabase;

    private function trainer(): Trainer
    {
        return Trainer::create([
            'full_name' => 'Coach', 'document' => '100',
            'phone' => '+573009998877', 'status' => 'active',
        ]);
    }

    private function member(string $name, string $doc, string $email = 'm@x.co'): Member
    {
        return Member::create([
            'full_name' => $name, 'document_number' => $doc, 'email' => $email,
            'phone' => '+573001112233', 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    public function test_empty_assigned_list(): void
    {
        $trainer = $this->trainer();

        $this->getJson("/api/admin/trainers/{$trainer->id}/members")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_assign_and_list_members(): void
    {
        $trainer = $this->trainer();
        $m1 = $this->member('Ana Gomez', '201', 'ana@x.co');
        $m2 = $this->member('Beto Ruiz', '202', 'beto@x.co');

        $this->postJson("/api/admin/trainers/{$trainer->id}/members", [
            'member_ids' => [$m1->id, $m2->id],
        ])->assertOk()
            ->assertJsonPath('assigned', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['full_name' => 'Ana Gomez', 'email' => 'ana@x.co']);

        $this->getJson("/api/admin/trainers/{$trainer->id}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Reflejado en member_trainer_assignments (lo que lee /trainer/members).
        $this->assertSame(2, MemberTrainerAssignment::where('trainer_id', $trainer->id)
            ->where('status', 'active')->count());
    }

    public function test_assign_is_idempotent(): void
    {
        $trainer = $this->trainer();
        $member = $this->member('Ana', '201');

        $this->postJson("/api/admin/trainers/{$trainer->id}/members", ['member_ids' => [$member->id]])->assertOk();
        $this->postJson("/api/admin/trainers/{$trainer->id}/members", ['member_ids' => [$member->id]])
            ->assertOk()
            ->assertJsonPath('assigned', 0)
            ->assertJsonCount(1, 'data');
    }

    public function test_unassign_member(): void
    {
        $trainer = $this->trainer();
        $member = $this->member('Ana', '201');
        $this->postJson("/api/admin/trainers/{$trainer->id}/members", ['member_ids' => [$member->id]])->assertOk();

        $this->deleteJson("/api/admin/trainers/{$trainer->id}/members/{$member->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame('inactive', MemberTrainerAssignment::where('member_id', $member->id)->value('status'));
    }

    public function test_search_matches_fields_and_excludes_assigned(): void
    {
        $trainer = $this->trainer();
        $carlos = $this->member('Carlos Pérez', '987654', 'carlos@mail.co');
        $this->member('Otro', '111', 'otro@mail.co');

        // Por nombre, documento, teléfono y correo.
        foreach (['Carlos', '987654', 'carlos@mail'] as $term) {
            $this->getJson("/api/admin/trainers/{$trainer->id}/members/search?q=".urlencode($term))
                ->assertOk()
                ->assertJsonFragment(['id' => $carlos->id]);
        }

        // Una vez asignado, deja de aparecer en la búsqueda.
        $this->postJson("/api/admin/trainers/{$trainer->id}/members", ['member_ids' => [$carlos->id]])->assertOk();
        $this->getJson("/api/admin/trainers/{$trainer->id}/members/search?q=Carlos")
            ->assertOk()
            ->assertJsonMissing(['id' => $carlos->id]);
    }

    public function test_search_empty_query_returns_empty(): void
    {
        $trainer = $this->trainer();
        $this->member('Ana', '201');

        $this->getJson("/api/admin/trainers/{$trainer->id}/members/search?q=")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
