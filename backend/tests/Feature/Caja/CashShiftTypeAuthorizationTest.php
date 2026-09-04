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
 * Las dos cajas se autorizan por separado también para OPERAR.
 *
 * Consultar el turno, abrirlo y cerrarlo dependían de una puerta fija que
 * exigía `cash.products.view` para todo el módulo. Fallaba cerrado, pero era
 * incorrecto: quien tuviera solo gimnasio chocaba antes de que nadie mirase de
 * qué caja hablaba. Ahora el permiso se resuelve por el `type` pedido.
 *
 * Lo que estas pruebas protegen, además del contrato:
 *
 *  · Que `also=true` NO sea una vía de escalada. Marcar la casilla no concede
 *    nada: cada caja exige su propio `operate` y la que no se autoriza se
 *    deniega sin revertir la que sí.
 *  · Que negar una caja no destruya el trabajo hecho en la otra. Revertir un
 *    cierre válido por un problema ajeno sería destruir un arqueo bueno.
 */
class CashShiftTypeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Un admin cuyo rol tiene EXACTAMENTE estos permisos de caja. */
    private function con(array $permisos): array
    {
        $rol = 'QA-Op-'.substr(md5(implode('|', $permisos)), 0, 8);
        foreach ($permisos as $permiso) {
            RolePermission::create(['role' => $rol, 'permission' => $permiso, 'granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        return $this->actingAsAdmin(Admin::create([
            'name' => 'QA '.$rol,
            'email' => 'qa-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
        ]));
    }

    /** Abre un turno con ESTAS credenciales, por el flujo real. */
    private function abrirCon(array $h, CashShiftType $type): void
    {
        $this->postJson('/api/admin/caja/shift/open', ['type' => $type->value], $h)->assertStatus(201);
    }

    /**
     * Abre un turno a nombre de OTRA persona.
     *
     * Importa para el cierre: cerrar el turno ajeno exige además `manage`, que
     * es una regla anterior a esta tanda y que aquí no se toca. Por eso cada
     * prueba abre con quien luego va a cerrar, salvo cuando lo que se quiere
     * comprobar es justo que la otra caja queda intacta.
     */
    private function abrirComoSuperAdmin(CashShiftType $type): void
    {
        $h = $this->actingAsAdmin(Admin::create([
            'name' => 'Root', 'email' => 'root-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]));
        $this->postJson('/api/admin/caja/shift/open', ['type' => $type->value], $h)->assertStatus(201);
    }

    // ── FASE 2 · estado del turno actual ────────────────────────────────────

    public function test_solo_productos_consulta_productos_y_no_gimnasio(): void
    {
        $h = $this->con(['cash.products.view']);

        $this->getJson('/api/admin/caja/shift?type=products', $h)->assertOk();
        $this->getJson('/api/admin/caja/shift?type=gym', $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.view');
    }

    public function test_solo_gimnasio_consulta_gimnasio_y_no_productos(): void
    {
        // El caso que antes era imposible: la puerta fija lo frenaba de entrada.
        $h = $this->con(['cash.gym.view']);

        $this->getJson('/api/admin/caja/shift?type=gym', $h)->assertOk();
        $this->getJson('/api/admin/caja/shift?type=products', $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.products.view');
    }

    public function test_con_ambos_permisos_consulta_las_dos(): void
    {
        $h = $this->con(['cash.products.view', 'cash.gym.view']);

        $this->getJson('/api/admin/caja/shift?type=products', $h)->assertOk();
        $this->getJson('/api/admin/caja/shift?type=gym', $h)->assertOk();
    }

    public function test_sin_ningun_permiso_no_consulta_ninguna(): void
    {
        $h = $this->con([]);

        $this->getJson('/api/admin/caja/shift?type=products', $h)->assertStatus(403);
        $this->getJson('/api/admin/caja/shift?type=gym', $h)->assertStatus(403);
    }

    public function test_un_type_invalido_no_abre_la_caja_ajena(): void
    {
        // `type()` normaliza cualquier valor desconocido a productos, que es la
        // compatibilidad con los clientes anteriores a las dos cajas. Lo que
        // importa aquí: escribir cualquier cosa NO sirve para colarse en el
        // gimnasio, porque lo que se autoriza es la caja resultante.
        $this->abrirComoSuperAdmin(CashShiftType::GYM);
        $h = $this->con(['cash.gym.view']);

        $this->getJson('/api/admin/caja/shift?type=gimnasio', $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.products.view');
    }

    // ── FASE 3 · abrir ──────────────────────────────────────────────────────

    public function test_solo_productos_abre_productos_y_no_gimnasio(): void
    {
        $h = $this->con(['cash.products.view', 'cash.products.operate']);

        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products'], $h)->assertStatus(201);
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'gym'], $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.operate');

        $this->assertNotNull(CashShift::currentOfType(CashShiftType::PRODUCTS));
        $this->assertNull(CashShift::currentOfType(CashShiftType::GYM));
    }

    public function test_solo_gimnasio_abre_gimnasio_y_no_productos(): void
    {
        $h = $this->con(['cash.gym.view', 'cash.gym.operate']);

        $this->postJson('/api/admin/caja/shift/open', ['type' => 'gym'], $h)->assertStatus(201);
        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products'], $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.products.operate');

        $this->assertNotNull(CashShift::currentOfType(CashShiftType::GYM));
        $this->assertNull(CashShift::currentOfType(CashShiftType::PRODUCTS));
    }

    public function test_ver_una_caja_no_basta_para_abrirla(): void
    {
        $h = $this->con(['cash.products.view', 'cash.gym.view']);

        $this->postJson('/api/admin/caja/shift/open', ['type' => 'products'], $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.products.operate');

        $this->assertSame(0, CashShift::count());
    }

    // ── FASE 4 · cerrar ─────────────────────────────────────────────────────

    public function test_solo_productos_cierra_productos_y_no_gimnasio(): void
    {
        $h = $this->con(['cash.products.view', 'cash.products.operate']);
        $this->abrirCon($h, CashShiftType::PRODUCTS);
        $this->abrirComoSuperAdmin(CashShiftType::GYM);

        $this->postJson('/api/admin/caja/shift/close', ['type' => 'products'], $h)->assertOk();
        $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.operate');

        $this->assertNull(CashShift::currentOfType(CashShiftType::PRODUCTS));
        // El turno del gimnasio sigue ABIERTO: nadie lo tocó.
        $this->assertNotNull(CashShift::currentOfType(CashShiftType::GYM));
    }

    public function test_solo_gimnasio_cierra_gimnasio_y_no_productos(): void
    {
        $h = $this->con(['cash.gym.view', 'cash.gym.operate']);
        $this->abrirCon($h, CashShiftType::GYM);
        $this->abrirComoSuperAdmin(CashShiftType::PRODUCTS);

        $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $h)->assertOk();
        $this->postJson('/api/admin/caja/shift/close', ['type' => 'products'], $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.products.operate');

        $this->assertNull(CashShift::currentOfType(CashShiftType::GYM));
        $this->assertNotNull(CashShift::currentOfType(CashShiftType::PRODUCTS));
    }

    public function test_el_cierre_conserva_responsable_y_totales_congelados(): void
    {
        // La puerta cambió; la contabilidad no.
        $h = $this->con(['cash.gym.view', 'cash.gym.operate']);
        $this->abrirCon($h, CashShiftType::GYM);

        $res = $this->postJson('/api/admin/caja/shift/close', ['type' => 'gym'], $h)->assertOk();

        $cerrado = CashShift::query()->where('type', 'gym')->latest('id')->first();
        $this->assertSame('closed', $cerrado->status->value);
        $this->assertNotNull($cerrado->closed_by);
        $this->assertNotNull($cerrado->closed_by_name);
        $this->assertNotNull($cerrado->closed_at);
        $this->assertNotNull($cerrado->expected_amount);
        $this->assertSame('0.00', (string) $cerrado->opening_amount);
        $this->assertSame('closed', $res->json('results.gym.result'));
    }

    // ── FASE 5 · `also` no es una vía de escalada ───────────────────────────

    public function test_also_no_abre_la_caja_que_no_puedes_operar(): void
    {
        $h = $this->con(['cash.products.view', 'cash.products.operate']);

        $res = $this->postJson('/api/admin/caja/shift/open',
            ['type' => 'products', 'also' => true], $h)->assertStatus(207);

        $this->assertSame('opened', $res->json('results.products.result'));
        $this->assertSame('forbidden', $res->json('results.gym.result'));
        $this->assertNotNull(CashShift::currentOfType(CashShiftType::PRODUCTS));
        $this->assertNull(CashShift::currentOfType(CashShiftType::GYM));
    }

    public function test_also_al_reves_tampoco_abre_productos(): void
    {
        $h = $this->con(['cash.gym.view', 'cash.gym.operate']);

        $res = $this->postJson('/api/admin/caja/shift/open',
            ['type' => 'gym', 'also' => true], $h)->assertStatus(207);

        $this->assertSame('opened', $res->json('results.gym.result'));
        $this->assertSame('forbidden', $res->json('results.products.result'));
        $this->assertNotNull(CashShift::currentOfType(CashShiftType::GYM));
        $this->assertNull(CashShift::currentOfType(CashShiftType::PRODUCTS));
    }

    public function test_also_no_cierra_la_caja_que_no_puedes_operar(): void
    {
        $h = $this->con(['cash.gym.view', 'cash.gym.operate']);
        $this->abrirCon($h, CashShiftType::GYM);
        $this->abrirComoSuperAdmin(CashShiftType::PRODUCTS);

        $res = $this->postJson('/api/admin/caja/shift/close',
            ['type' => 'gym', 'also' => true], $h)->assertStatus(207);

        $this->assertSame('closed', $res->json('results.gym.result'));
        $this->assertSame('forbidden', $res->json('results.products.result'));
        // Y sobre todo: el turno de productos NO se cerró ni se alteró.
        $this->assertNotNull(CashShift::currentOfType(CashShiftType::PRODUCTS));
    }

    public function test_also_sin_poder_operar_ninguna_no_toca_nada(): void
    {
        $h = $this->con(['cash.products.view', 'cash.gym.view']);

        $this->postJson('/api/admin/caja/shift/open',
            ['type' => 'products', 'also' => true], $h)->assertStatus(403);

        $this->assertSame(0, CashShift::count());
    }

    public function test_con_ambos_operate_also_sigue_abriendo_las_dos(): void
    {
        // Sin regresión para quien sí puede con las dos: es el caso de Ana.
        $h = $this->con(['cash.products.view', 'cash.products.operate',
            'cash.gym.view', 'cash.gym.operate']);

        $this->postJson('/api/admin/caja/shift/open',
            ['type' => 'products', 'also' => true], $h)->assertStatus(201);

        $this->assertNotNull(CashShift::currentOfType(CashShiftType::PRODUCTS));
        $this->assertNotNull(CashShift::currentOfType(CashShiftType::GYM));
    }
}
