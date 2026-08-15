<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Visibilidad en el CRM de los comentarios que los miembros dejan desde la app.
 *
 * Cubre los tres puntos que impedían leerlos: el listado truncaba a 3, el
 * detalle no cargaba la relación (siempre vacío) y una calificación nueva no
 * avisaba al CRM, así que el módulo no se recargaba.
 */
class TrainerReviewVisibilityTest extends TestCase
{
    use RefreshDatabase;

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

    private function trainer(): Trainer
    {
        return Trainer::create([
            'full_name' => 'Oscar Mancipe',
            'main_specialty' => 'Musculación',
            'experience_years' => 5,
            'status' => 'active',
        ]);
    }

    public function test_admin_list_returns_every_review_not_just_three(): void
    {
        $trainer = $this->trainer();

        // Cinco miembros distintos: la restricción única es (trainer, member).
        foreach (range(1, 5) as $i) {
            $trainer->reviews()->create([
                'member_id' => $this->member("200{$i}", "Miembro {$i}")->id,
                'rating' => 5,
                'comment' => "Comentario {$i}",
            ]);
        }

        $response = $this->adminGetJson('/api/trainers?admin=1')->assertOk();

        $payload = collect($response->json())->firstWhere('id', (string) $trainer->id);
        $this->assertCount(5, $payload['recentReviews'], 'el listado sigue truncando las reseñas');
        $this->assertSame(5, $payload['reviewsWithCommentCount']);
    }

    public function test_trainer_detail_includes_the_reviews(): void
    {
        $trainer = $this->trainer();
        $trainer->reviews()->create([
            'member_id' => $this->member('3001', 'Catalina Ortega')->id,
            'rating' => 5,
            'comment' => 'Excelente acompañamiento.',
        ]);

        $payload = $this->adminGetJson("/api/trainers/{$trainer->id}?admin=1")
            ->assertOk()
            ->json();

        // Antes llegaba [] porque loadProfessional() no cargaba la relación.
        $this->assertCount(1, $payload['recentReviews']);
        $this->assertSame('Catalina Ortega', $payload['recentReviews'][0]['memberName']);
        $this->assertSame('Excelente acompañamiento.', $payload['recentReviews'][0]['comment']);
    }

    public function test_rating_without_text_is_reported_as_having_no_comment(): void
    {
        $trainer = $this->trainer();
        $trainer->reviews()->create([
            'member_id' => $this->member('4001', 'Iron Body Review')->id,
            'rating' => 5,
            'comment' => null,
        ]);

        $payload = $this->adminGetJson("/api/trainers/{$trainer->id}?admin=1")->assertOk()->json();

        // Hay reseña, pero ningún comentario: la tarjeta lo dice en vez de
        // dejar un hueco que parece un fallo.
        $this->assertCount(1, $payload['recentReviews']);
        $this->assertSame('', $payload['recentReviews'][0]['comment']);
        $this->assertSame(0, $payload['reviewsWithCommentCount']);
    }

    public function test_a_new_review_notifies_the_crm_so_the_module_reloads(): void
    {
        $trainer = $this->trainer();
        $member = $this->member('5001', 'Catalina Ortega');

        $this->adminPostJson("/api/trainers/{$trainer->id}/reviews", [
            'member_id' => $member->id,
            'rating' => 5,
            'comment' => 'Muy buen entrenador.',
        ])->assertOk();

        // El módulo de Entrenadores del CRM solo recarga con este action_type.
        $notification = Notification::query()
            ->where('action_type', 'trainer_detail')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification, 'no se emitió aviso al CRM: el módulo no se recargaría');
        $this->assertStringContainsString('Catalina Ortega', (string) $notification->message);
        $this->assertStringContainsString('dejó un comentario', (string) $notification->message);
    }

    public function test_a_rating_without_comment_says_so_in_the_crm_notice(): void
    {
        $trainer = $this->trainer();
        $member = $this->member('6001', 'Iron Body Review');

        $this->adminPostJson("/api/trainers/{$trainer->id}/reviews", [
            'member_id' => $member->id,
            'rating' => 4,
        ])->assertOk();

        $notification = Notification::query()
            ->where('action_type', 'trainer_detail')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('sin comentario', (string) $notification->message);
    }
}
