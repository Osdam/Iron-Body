<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use App\Support\Workforce\Positions;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            $inicio = hrtime(true);
            $r = $this->json($metodo, $ruta, ['full_name' => 'Ana Uno', 'document' => '12345678', 'positions' => array_fill(0, 10_000, 'x')], $h);
            $ms = (hrtime(true) - $inicio) / 1e6;

            $r->assertStatus(422);
            $this->assertSame(['positions'], array_keys($r->json('errors')), $metodo);
            $this->assertLessThan(1000, $ms, sprintf('%s: el 422 tardó %.1f ms (antes, 61 s).', $metodo, $ms));
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
}
