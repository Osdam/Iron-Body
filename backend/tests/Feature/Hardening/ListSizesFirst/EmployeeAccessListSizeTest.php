<?php

namespace Tests\Feature\Hardening\ListSizesFirst;

use App\Models\User;
use App\Services\Membership\PlanAccessRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M-2 fuera del Studio (Hito 7 de ULTRON STUDIO, B7-e): el acceso de empleado
 * (`PUT /api/users/{user}/employee-access`, ruta del CRM fuera de /api/admin)
 * valida primero el tamaño de `access_days` (7) y `access_windows`
 * (`PlanAccessRules::MAX_WINDOWS`), los topes de siempre, y después cada día y
 * cada franja. Antes, 10 000 franjas tardaban 15,6 s en llegar al mismo 422.
 */
class EmployeeAccessListSizeTest extends TestCase
{
    use RefreshDatabase;

    private function ruta(): string
    {
        $socia = User::create(['name' => 'Socia', 'email' => 'socia-acceso@example.com', 'password' => 'secret', 'document' => '10203040', 'phone' => '3001234567', 'status' => 'active']);

        return "/api/users/{$socia->id}/employee-access";
    }

    public function test_una_lista_por_encima_del_tope_es_422_solo_por_el_tope(): void
    {
        $h = $this->actingAsAdmin();
        $ruta = $this->ruta();
        $this->putJson($ruta, ['access_days' => 'x'], $h)->assertStatus(422);   // en frío, sin medir

        $casos = [
            'access_windows' => array_fill(0, 10_000, ['from' => '06:00', 'to' => '08:00']),
            'access_days' => array_fill(0, 10_000, 1),
        ];
        foreach ($casos as $lista => $valor) {
            $inicio = hrtime(true);
            $r = $this->putJson($ruta, [$lista => $valor], $h);
            $ms = (hrtime(true) - $inicio) / 1e6;

            $r->assertStatus(422);
            $this->assertSame([$lista], array_keys($r->json('errors')));
            $this->assertLessThan(1000, $ms, sprintf('%s: el 422 tardó %.1f ms (antes, 15,6 s con las franjas).', $lista, $ms));
        }
    }

    public function test_dentro_del_tope_la_validacion_es_la_de_siempre(): void
    {
        $franjas = array_fill(0, PlanAccessRules::MAX_WINDOWS - 1, ['from' => '06:00', 'to' => '08:00']);
        $franjas[] = ['from' => '06:00'];
        $r = $this->putJson($this->ruta(), ['access_days' => [1, 9], 'access_windows' => $franjas], $this->actingAsAdmin())->assertStatus(422);

        $this->assertSame(['access_days.1', 'access_windows.'.(PlanAccessRules::MAX_WINDOWS - 1).'.to'], array_keys($r->json('errors')));
    }
}
