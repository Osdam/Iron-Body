<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use App\Support\Workforce\Positions;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Validator as LaravelValidator;
use Tests\TestCase;

/**
 * M-2 fuera del Studio (Hito 7 de ULTRON STUDIO, B7-e): el alta y la edición
 * de personal (`POST /api/admin/workforce/staff` y `PATCH
 * /api/admin/workforce/staff/{identity}`) no tenían tope en `positions`: 10 000
 * cargos tardaban 61 s en llegar al 422. Tope nuevo: el catálogo de cargos de
 * personal. No cambia nada legítimo: el CRM (`staff-form-dialog.ts`) marca
 * casillas de ese catálogo, sin repetir, así que nunca envía más.
 */
class WorkforceStaffPositionsListSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_lista_por_encima_del_catalogo_es_422_solo_por_el_tope(): void
    {
        $h = $this->actingAsAdmin();
        $this->postJson('/api/admin/workforce/staff', [], $h)->assertStatus(422);   // en frío, sin medir

        foreach (['POST' => '/api/admin/workforce/staff', 'PATCH' => '/api/admin/workforce/staff/'.fake()->uuid()] as $metodo => $ruta) {
            [$r, $atributos] = $this->conAtributosValidados(fn () => $this->json($metodo, $ruta, ['full_name' => 'Ana Uno', 'document' => '12345678', 'positions' => array_fill(0, 10_000, 'x')], $h));

            $r->assertStatus(422);
            $this->assertSame(['positions'], array_keys($r->json('errors')), $metodo);
            $this->assertLessThan(10, $atributos, "Se expandió la lista antes de mirar su tope ({$atributos} atributos).");
        }
    }

    public function test_todo_el_catalogo_cabe_y_lo_demas_valida_como_siempre(): void
    {
        $h = $this->actingAsAdmin();

        // El máximo legítimo: todos los cargos de personal, una vez cada uno.
        $todos = Positions::staffKeys();
        $this->postJson('/api/admin/workforce/staff', ['full_name' => 'Marta Todos', 'document' => '1075000999', 'positions' => $todos], $h)->assertCreated();

        // Dentro del tope, el error de siempre.
        $r = $this->postJson('/api/admin/workforce/staff', ['full_name' => 'Nora Nueva', 'document' => '1075000888', 'positions' => [$todos[0], 'no-existe']], $h)->assertStatus(422);
        $this->assertSame(['positions.1'], array_keys($r->json('errors')));
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
