<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * IRON — copiloto administrativo del CRM. Verifica el blindaje admin, el
 * happy-path de chat (con OpenAI simulado), el flujo de "function calling"
 * (herramienta de solo lectura) y la validación de imágenes.
 *
 * OpenAI SIEMPRE se simula con Http::fake — el test nunca sale a la red.
 */
class IronCrmAiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-admin-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'admin.api_token' => self::SECRET,
            'iron_crm.enabled' => true,
            'iron_crm.api_key' => 'test-openai-key',
            'iron_crm.base_url' => 'https://api.openai.com',
            'iron_crm.model' => 'gpt-4.1',
        ]);
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        return $this->adminHeaders();
    }

    public function test_chat_sin_token_devuelve_401(): void
    {
        $this->postJson('/api/admin/iron-ai/chat', ['message' => 'hola'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'admin_token_required');
    }

    public function test_chat_responde_texto_del_modelo(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-4.1',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Hola, soy IRON. ¿En qué te ayudo?'],
                ]],
            ], 200),
        ]);

        $this->postJson('/api/admin/iron-ai/chat', ['message' => 'hola'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'Hola, soy IRON. ¿En qué te ayudo?');
    }

    public function test_chat_ejecuta_herramienta_de_solo_lectura(): void
    {
        Plan::create(['name' => 'Plan Pro', 'price' => 99000, 'duration_days' => 30, 'active' => true]);

        // 1ª respuesta: el modelo pide la herramienta list_plans.
        // 2ª respuesta: el modelo redacta el texto final con el resultado.
        Http::fakeSequence()
            ->push([
                'model' => 'gpt-4.1',
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'list_plans', 'arguments' => '{"active_only":true}'],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'model' => 'gpt-4.1',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'El plan activo es Plan Pro.'],
                ]],
            ], 200);

        $this->postJson('/api/admin/iron-ai/chat', ['message' => '¿Qué planes activos hay?'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('reply', 'El plan activo es Plan Pro.');
    }

    public function test_chat_rechaza_imagen_no_valida(): void
    {
        $this->postJson('/api/admin/iron-ai/chat', [
            'message' => 'analiza esto',
            'image' => 'data:text/plain;base64,aGVsbG8=',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_mensaje_vacio_es_rechazado(): void
    {
        $this->postJson('/api/admin/iron-ai/chat', ['message' => ''], $this->authHeaders())
            ->assertStatus(422);
    }

    public function test_history_es_local_y_vacio(): void
    {
        $this->getJson('/api/admin/iron-ai/history', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('storage', 'local')
            ->assertJsonPath('data', []);
    }
}
