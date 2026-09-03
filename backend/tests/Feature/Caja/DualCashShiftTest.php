<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\RolePermission;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operación doble: abrir o cerrar las dos cajas de una pulsación.
 *
 * Ergonomía, NO una caja combinada. Siguen siendo dos turnos, dos filas y dos
 * arqueos; lo único que se comparte es el clic. Lo que estos tests fijan es que
 * un fallo en una mitad no destruya la otra y que los permisos se exijan caja
 * por caja.
 */
class DualCashShiftTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = Admin::ROLE_ADMINISTRADOR, string $name = 'Ana'): Admin
    {
        return Admin::create([
            'name' => $name,
            'email' => 'dual-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /** Rol con permisos SOLO de la caja indicada. */
    private function soloPuedeCon(string $tipo): array
    {
        $rol = Admin::ROLE_ADMINISTRATIVO;
        foreach (["cash.{$tipo}.view", "cash.{$tipo}.operate"] as $permiso) {
            RolePermission::create(['role' => $rol, 'permission' => $permiso, 'granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        return $this->actingAsAdmin($this->admin($rol, 'Parcial'));
    }

    public function test_abre_las_dos_cajas_de_un_clic(): void
    {
        $h = $this->actingAsAdmin($this->admin());

        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products', 'also' => true], $h)
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('results.products.result', 'opened')
            ->assertJsonPath('results.gym.result', 'opened');

        // Dos turnos independientes, no uno combinado.
        $this->assertSame(2, CashShift::count());
        $this->assertNotNull(CashShift::currentOfType(CashShiftType::PRODUCTS));
        $this->assertNotNull(CashShift::currentOfType(CashShiftType::GYM));
    }

    public function test_cierra_las_dos_cajas_desde_gimnasio(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'gym', 'also' => true], $h)->assertStatus(201);

        $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym', 'also' => true], $h)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('results.gym.result', 'closed')
            ->assertJsonPath('results.products.result', 'closed');

        $this->assertSame(0, CashShift::query()->open()->count());
    }

    public function test_sin_la_casilla_solo_se_toca_una_caja(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products', 'also' => true], $h)->assertStatus(201);

        $res = $this->postJson('/api/admin/caja/shift/close', ['type' => 'products'], $h)->assertOk();

        $this->assertSame('closed', $res->json('results.products.result'));
        $this->assertArrayNotHasKey('gym', $res->json('results'), 'la otra caja ni se menciona');
        $this->assertTrue(CashShift::currentOfType(CashShiftType::GYM)->isOpen());
    }

    public function test_la_segunda_caja_exige_su_propio_permiso(): void
    {
        // Marcar la casilla no concede nada: quien solo puede con productos no
        // cierra el gimnasio por acompañamiento.
        $h = $this->soloPuedeCon('products');

        $res = $this->postJson('/api/admin/caja/shift/open', ['type' => 'products', 'also' => true], $h)
            ->assertStatus(207)
            ->assertJsonPath('ok', false);

        $this->assertSame('opened', $res->json('results.products.result'));
        $this->assertSame('forbidden', $res->json('results.gym.result'));

        // La mitad autorizada SÍ se ejecutó: negar la otra no la revierte.
        $this->assertNotNull(CashShift::currentOfType(CashShiftType::PRODUCTS));
        $this->assertNull(CashShift::currentOfType(CashShiftType::GYM));
    }

    public function test_un_fallo_parcial_no_revierte_la_operacion_valida(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        // Solo productos abierta: cerrar ambas debe cerrar una e informar de la otra.
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products'], $h)->assertStatus(201);

        $res = $this->postJson('/api/admin/caja/shift/close', ['type' => 'products', 'also' => true], $h)
            ->assertStatus(207)
            ->assertJsonPath('ok', false);

        $this->assertSame('closed', $res->json('results.products.result'));
        $this->assertSame('already_closed', $res->json('results.gym.result'));

        // El cierre válido se conserva: revertirlo por un problema ajeno sería
        // destruir un arqueo correcto.
        $this->assertSame('closed', CashShift::latest('id')->first()->status->value);
    }

    public function test_abrir_dos_veces_seguidas_no_duplica_turnos(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products', 'also' => true], $h)->assertStatus(201);

        // Doble clic: la segunda pulsación informa, no crea.
        $res = $this->postJson('/api/admin/caja/shift/open', ['type' => 'products', 'also' => true], $h)
            ->assertStatus(207);

        $this->assertSame('already_open', $res->json('results.products.result'));
        $this->assertSame('already_open', $res->json('results.gym.result'));
        $this->assertSame(2, CashShift::count(), 'siguen siendo dos, uno por caja');
    }

    public function test_el_historial_filtra_por_tipo(): void
    {
        $h = $this->actingAsAdmin($this->admin());
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products', 'also' => true], $h)->assertStatus(201);
        $this->postJson('/api/admin/caja/shift/close', ['type' => 'products', 'also' => true], $h)->assertOk();

        $res = $this->getJson('/api/admin/caja/shifts?type=gym', $h)->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('gym', $res->json('data.0.type'));
    }

    public function test_no_se_listan_turnos_de_una_caja_que_no_puedes_ver(): void
    {
        $admin = $this->actingAsAdmin($this->admin());
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products', 'also' => true], $admin)->assertStatus(201);

        $parcial = $this->soloPuedeCon('products');

        // Pedir explícitamente la otra caja se rechaza.
        $this->getJson('/api/admin/caja/shifts?type=gym', $parcial)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.view');

        // Y sin filtro, solo aparece lo que puede ver.
        $res = $this->getJson('/api/admin/caja/shifts', $parcial)->assertOk();
        foreach ($res->json('data') as $fila) {
            $this->assertSame('products', $fila['type']);
        }
    }

    public function test_el_permiso_legado_caja_sell_sigue_operando_productos(): void
    {
        // Compatibilidad: un rol al que se le concedió `caja.sell` hace meses
        // debe seguir abriendo la caja de productos sin tocar role_permissions.
        $rol = Admin::ROLE_ADMINISTRATIVO;
        foreach (['caja.view', 'caja.sell'] as $permiso) {
            RolePermission::create(['role' => $rol, 'permission' => $permiso, 'granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products'],
            $this->actingAsAdmin($this->admin($rol, 'Legado')))
            ->assertStatus(201)
            ->assertJsonPath('results.products.result', 'opened');
    }
}
