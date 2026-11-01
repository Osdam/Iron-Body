<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M-2 fuera del Studio (Hito 7 de ULTRON STUDIO, B7-e): `POST
 * /api/admin/iron-ai/chat` valida primero el tamaño de `history` (tope 50, el
 * de siempre) y después cada mensaje. Antes, 10 000 mensajes tardaban 11,5 s
 * en llegar al mismo 422.
 */
class IronCrmAiChatListSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_historial_por_encima_del_tope_es_422_solo_por_el_tope_y_sin_recorrerlo(): void
    {
        $h = $this->actingAsAdmin();
        $this->postJson('/api/admin/iron-ai/chat', [], $h)->assertStatus(422);   // en frío, sin medir

        $inicio = hrtime(true);
        $r = $this->postJson('/api/admin/iron-ai/chat', ['message' => 'hola', 'history' => array_fill(0, 10_000, ['role' => 'user', 'content' => 'x'])], $h);
        $ms = (hrtime(true) - $inicio) / 1e6;

        $r->assertStatus(422);
        $this->assertSame(['history'], array_keys($r->json('errors')));
        $this->assertLessThan(1000, $ms, sprintf('El 422 tardó %.1f ms (antes, 11,5 s).', $ms));
    }

    public function test_dentro_del_tope_la_validacion_es_la_de_siempre(): void
    {
        $r = $this->postJson('/api/admin/iron-ai/chat', ['message' => 'hola', 'history' => [
            ['role' => 'user', 'content' => 'hola'],
            ['role' => 'bot', 'content' => 'qué tal'],
        ]], $this->actingAsAdmin())->assertStatus(422);

        $this->assertSame(['history.1.role'], array_keys($r->json('errors')));
    }
}
