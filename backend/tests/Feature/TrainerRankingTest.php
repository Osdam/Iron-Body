<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainerRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_trainers_index_returns_active_trainers_ranked_by_reviews(): void
    {
        $memberA = $this->member('1001', 'Ana Perez');
        $memberB = $this->member('1002', 'Luis Gomez');

        $trainerA = Trainer::create([
            'full_name' => 'Carlos Perez',
            'main_specialty' => 'Hipertrofia',
            'bio' => 'Fuerza e hipertrofia.',
            'experience_years' => 5,
            'certifications' => json_encode(['Entrenamiento funcional']),
            'status' => 'active',
        ]);
        $trainerB = Trainer::create([
            'full_name' => 'Beatriz Ruiz',
            'main_specialty' => 'Funcional',
            'experience_years' => 6,
            'status' => 'active',
        ]);
        Trainer::create([
            'full_name' => 'Inactivo',
            'main_specialty' => 'Hipertrofia',
            'status' => 'inactive',
        ]);

        $trainerA->reviews()->create(['member_id' => $memberA->id, 'rating' => 5]);
        $trainerA->reviews()->create(['member_id' => $memberB->id, 'rating' => 5]);
        $trainerB->reviews()->create(['member_id' => $memberA->id, 'rating' => 4]);

        $response = $this->getJson('/api/trainers');

        // Índice público: solo activos, ordenados por rating promedio desc.
        // El recurso expone id (string), name, average_rating y rating_count.
        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', (string) $trainerA->id)
            ->assertJsonPath('data.0.name', 'Carlos Perez')
            ->assertJsonPath('data.0.rating_count', 2)
            ->assertJsonPath('data.1.id', (string) $trainerB->id);
    }

    public function test_trainer_review_is_upserted_per_member_and_updates_rating(): void
    {
        $member = $this->member('1001', 'Oscar Mancipe');
        $trainer = Trainer::create([
            'full_name' => 'Carlos Perez',
            'main_specialty' => 'Hipertrofia',
            'status' => 'active',
        ]);

        $this->postJson("/api/trainers/{$trainer->id}/reviews", [
            'member_id' => $member->id,
            'rating' => 4,
            'comment' => 'Buen entrenamiento.',
        ])->assertOk()
            ->assertJsonPath('data.trainer_rating', 4)
            ->assertJsonPath('data.reviews_count', 1);

        $this->postJson("/api/trainers/{$trainer->id}/reviews", [
            'member_id' => $member->id,
            'rating' => 5,
            'comment' => 'Excelente entrenador.',
        ])->assertOk()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.trainer_rating', 5)
            ->assertJsonPath('data.reviews_count', 1);

        $this->assertDatabaseCount('trainer_reviews', 1);
    }

    public function test_inactive_trainer_cannot_be_reviewed(): void
    {
        $member = $this->member('1001', 'Oscar Mancipe');
        $trainer = Trainer::create([
            'full_name' => 'Carlos Perez',
            'status' => 'inactive',
        ]);

        $this->postJson("/api/trainers/{$trainer->id}/reviews", [
            'member_id' => $member->id,
            'rating' => 5,
        ])->assertUnprocessable();
    }

    private function member(string $document, string $name): Member
    {
        $user = User::create([
            'name' => $name,
            'email' => "{$document}@example.com",
            'password' => 'secret',
            'document' => $document,
            'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'email' => $user->email,
            'document_number' => $document,
            'status' => Member::STATUS_ACTIVE,
        ]);
    }
}
