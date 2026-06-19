<?php

namespace Tests\Feature;

use App\Models\IronAiConversation;
use App\Models\IronAiMessage;
use App\Models\Member;
use App\Models\NutritionAiRecommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * IRON IA — aislamiento por usuario (anti-suplantación).
 *
 * Verifica que la memoria, conversaciones, cuota y contexto IA SOLO se resuelven
 * desde el miembro autenticado (Bearer). Un usuario NO puede:
 *  - leer la conversación de otro,
 *  - forzar `member_id` de otro en /api/iron-ai/chat,
 *  - usar `document`/`email` de otro para cargar su contexto.
 * Y /api/app/iron-ai/coach sigue dependiendo de `auth_member`.
 */
class IronAiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function member(string $name): Member
    {
        $this->seq++;
        $user = User::create([
            'name' => $name, 'email' => "u{$this->seq}@example.com", 'password' => 'secret',
            'document' => '900' . $this->seq, 'phone' => '30055' . $this->seq, 'status' => 'active',
            'plan' => 'PLAN TOTAL', 'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => $name, 'email' => "u{$this->seq}@example.com",
            'document_number' => '900' . $this->seq, 'phone' => '30055' . $this->seq,
            'access_hash' => 'tok-' . uniqid(), 'status' => Member::STATUS_ACTIVE,
            'goal' => 'Ganar músculo',
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer ' . $m->access_hash];
    }

    private function enableAi(): void
    {
        config([
            'services.openai.enabled' => true,
            'services.openai.api_key' => 'sk-test',
            'services.openai.model' => 'gpt-test',
        ]);
    }

    private function fakeChat(string $reply = 'Respuesta de IRON.'): void
    {
        Http::fake(['*/v1/chat/completions' => Http::response([
            'model' => 'gpt-test',
            'choices' => [['message' => ['content' => $reply]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);
    }

    // ── Autenticación requerida ───────────────────────────────────────────────

    public function test_sensitive_routes_require_authentication(): void
    {
        $this->postJson('/api/iron-ai/chat', ['message' => 'hola'])->assertStatus(401);
        $this->getJson('/api/iron-ai/conversations')->assertStatus(401);
        $this->getJson('/api/iron-ai/access')->assertStatus(401);
        $this->getJson('/api/iron-ai/quota')->assertStatus(401);
        $this->postJson('/api/app/iron-ai/coach', ['focus' => 'today'])->assertStatus(401);
    }

    // ── A no puede leer la conversación de B ──────────────────────────────────

    public function test_user_cannot_read_other_users_conversation(): void
    {
        $a = $this->member('Alice');
        $b = $this->member('Bob');

        // B crea una conversación (le pertenece).
        $create = $this->postJson('/api/iron-ai/conversations', ['title' => 'Secreta de Bob'], $this->auth($b));
        $create->assertCreated();
        $uuid = $create->json('data.uuid');
        $this->assertNotEmpty($uuid);

        // A intenta leer los mensajes de la conversación de B → 403.
        $this->getJson("/api/iron-ai/conversations/{$uuid}/messages", $this->auth($a))
            ->assertStatus(403)
            ->assertJsonPath('code', 'CONVERSATION_NOT_FOUND');

        // B sí puede leerla.
        $this->getJson("/api/iron-ai/conversations/{$uuid}/messages", $this->auth($b))->assertOk();
    }

    // ── A no puede forzar member_id de B en /chat ─────────────────────────────

    public function test_user_cannot_spoof_member_id_in_chat(): void
    {
        $this->enableAi();
        $this->fakeChat();

        $a = $this->member('Alice');
        $b = $this->member('Bob');

        $this->postJson('/api/iron-ai/chat', [
            'message'   => 'Hola IRON',
            'member_id' => $b->id, // intento de suplantación
        ], $this->auth($a))->assertOk();

        // El chat se atribuyó a A, nunca a B.
        $this->assertDatabaseHas('iron_ai_messages', ['member_id' => $a->id]);
        $this->assertSame(0, IronAiMessage::where('member_id', $b->id)->count());
        $this->assertSame(0, IronAiConversation::where('member_id', $b->id)->count());
        $this->assertGreaterThan(0, IronAiConversation::where('member_id', $a->id)->count());

        // La cuota consumida se registró bajo A, no bajo B.
        $this->assertDatabaseHas('iron_ai_usage_logs', ['member_id' => $a->id]);
        $this->assertDatabaseMissing('iron_ai_usage_logs', ['member_id' => $b->id]);
    }

    // ── A no puede usar document/email de B para cargar contexto ──────────────

    public function test_user_cannot_load_other_users_context_via_document_or_email(): void
    {
        $this->enableAi();
        $this->fakeChat();

        $a = $this->member('Alice');
        $b = $this->member('Bob');

        $this->postJson('/api/iron-ai/chat', [
            'message'  => 'Analiza mi progreso',
            'document' => $b->document_number, // intento de suplantación por documento
            'email'    => $b->email,           // intento de suplantación por email
        ], $this->auth($a))->assertOk();

        // El contexto enviado a OpenAI corresponde a A (Alice), nunca a B (Bob).
        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return str_contains($body, 'Alice') && ! str_contains($body, 'Bob');
        });

        // Nada quedó atribuido a B.
        $this->assertSame(0, IronAiConversation::where('member_id', $b->id)->count());
        $this->assertSame(0, IronAiMessage::where('member_id', $b->id)->count());
    }

    // ── El coach contextual sigue usando auth_member ──────────────────────────

    public function test_coach_uses_authenticated_member_only(): void
    {
        $this->enableAi();
        config(['services.openai.coach_enabled' => true]);
        Http::fake(['*/v1/chat/completions' => Http::response([
            'model' => 'gpt-test',
            'choices' => [['message' => ['content' => json_encode([
                'title' => 'Plan de hoy', 'summary' => 'Vas bien.', 'priority' => 'training',
                'insights' => ['ok'], 'actions' => [['label' => 'Ir', 'type' => 'route', 'route' => '/workouts']],
            ])]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200)]);

        $a = $this->member('Alice');
        $b = $this->member('Bob');

        // A pide su coach e intenta forzar member_id=B en el body.
        $this->postJson('/api/app/iron-ai/coach', [
            'focus'     => 'today',
            'member_id' => $b->id,
        ], $this->auth($a))->assertOk()->assertJsonPath('success', true);

        // La recomendación generada pertenece a A (auth_member), no a B.
        $this->assertDatabaseHas('nutrition_ai_recommendations', ['member_id' => $a->id]);
        $this->assertSame(0, NutritionAiRecommendation::where('member_id', $b->id)->count());
    }
}
