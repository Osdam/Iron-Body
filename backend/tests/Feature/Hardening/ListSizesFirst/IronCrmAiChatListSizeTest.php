<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Validator as LaravelValidator;
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

        [$r, $atributos] = $this->conAtributosValidados(fn () => $this->postJson('/api/admin/iron-ai/chat', ['message' => 'hola', 'history' => array_fill(0, 10_000, ['role' => 'user', 'content' => 'x'])], $h));

        $r->assertStatus(422);
        $this->assertSame(['history'], array_keys($r->json('errors')));
        $this->assertLessThan(10, $atributos, "Se expandió la lista antes de mirar su tope ({$atributos} atributos).");
    }

    public function test_dentro_del_tope_la_validacion_es_la_de_siempre(): void
    {
        $r = $this->postJson('/api/admin/iron-ai/chat', ['message' => 'hola', 'history' => [
            ['role' => 'user', 'content' => 'hola'],
            ['role' => 'bot', 'content' => 'qué tal'],
        ]], $this->actingAsAdmin())->assertStatus(422);

        $this->assertSame(['history.1.role'], array_keys($r->json('errors')));
    }

    /**
     * La respuesta y el mayor número de atributos que llegó a validar un
     * validador de la petición. Con el comodín expandido sobre la lista, uno
     * (o más) por elemento; validando primero el tamaño, uno por lista. No
     * depende de la carga de la máquina, a diferencia del tiempo.
     *
     * @return array{0: TestResponse, 1: int}
     */
    private function conAtributosValidados(Closure $peticion): array
    {
        $maximo = 0;
        Validator::resolver(static function ($traductor, array $datos, array $reglas, array $mensajes, array $atributos) use (&$maximo): LaravelValidator {
            $validador = new LaravelValidator($traductor, $datos, $reglas, $mensajes, $atributos);
            $maximo = max($maximo, count($validador->getRules()));

            return $validador;
        });

        return [$peticion(), $maximo];
    }
}
