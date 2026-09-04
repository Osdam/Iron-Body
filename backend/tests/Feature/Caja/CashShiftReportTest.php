<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\RolePermission;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Caja\CashShiftReport;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Consultar un cierre de caja después de haberlo cerrado.
 *
 * Hasta ahora el cierre congelaba un informe correcto en la base de datos y no
 * había forma de mirarlo: el procedimiento real era cerrar la caja y hacer una
 * captura de pantalla. Una captura no se archiva, no se busca y desaparece con
 * el teléfono.
 *
 * Lo que estas pruebas protegen, además de que el informe exista:
 *
 *  · Que los totales sean los CONGELADOS y no un recálculo. Un informe que se
 *    recalcula al abrirlo cambiaría un arqueo ya firmado si alguien anula una
 *    venta meses después.
 *  · Que una caja no filtre a la otra, ni por permisos ni por contenido.
 *  · Que cambiar el id en la URL no salte la autorización.
 */
class CashShiftReportTest extends TestCase
{
    use RefreshDatabase;

    private ?Admin $responsable = null;

    private function admin(string $rol = Admin::ROLE_ADMINISTRADOR): Admin
    {
        return Admin::create([
            'name' => 'Prueba '.$rol,
            'email' => 'a-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
        ]);
    }

    /** Un turno cerrado con totales congelados, como los deja el cierre real. */
    private function turnoCerrado(CashShiftType $type, array $over = []): CashShift
    {
        $responsable = $this->responsable ??= $this->admin(Admin::ROLE_RECEPCION);

        return CashShift::create(array_merge([
            'type' => $type->value,
            'status' => 'closed',
            'opened_by' => $responsable->id,
            'opened_by_name' => 'Recepción Mañana',
            'opened_at' => now()->subHours(8),
            'opening_amount' => 0,
            'closed_by' => $responsable->id,
            'closed_by_name' => 'Recepción Mañana',
            'closed_at' => now()->subHour(),
            'sales_total' => 12000,
            'cash_sales_total' => 9000,
            'transfer_total' => 3000,
            'card_total' => 0,
            'wompi_total' => 0,
            'other_total' => 0,
            'operations_count' => 4,
            'expected_amount' => 9000,
        ], $over));
    }

    /**
     * Cabeceras de un admin que SOLO puede con la caja indicada.
     *
     * Sirve para comprobar que la autorización mira el tipo REAL del turno y no
     * el que diga quien llama: sin eso, bastaría cambiar el id en la URL para
     * leer el cierre de la otra caja.
     */
    private function soloPuedeCon(string $tipo): array
    {
        $rol = Admin::ROLE_ADMINISTRATIVO;
        foreach (["cash.{$tipo}.view", "cash.{$tipo}.operate"] as $permiso) {
            RolePermission::create(['role' => $rol, 'permission' => $permiso, 'granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        return $this->actingAsAdmin($this->admin($rol));
    }

    /**
     * Cabeceras de un admin cuyo rol tiene EXACTAMENTE estos permisos de caja.
     *
     * Se parte de Administrativo, que no trae ninguno por defecto, y se
     * conceden solo los pedidos. Así se puede probar el caso que antes era
     * imposible: alguien que solo ve el gimnasio.
     */
    private function conPermisosDeCaja(array $permisos): array
    {
        $rol = 'QA-Caja-'.substr(md5(implode('|', $permisos)), 0, 8);
        foreach ($permisos as $permiso) {
            RolePermission::create(['role' => $rol, 'permission' => $permiso, 'granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        return $this->actingAsAdmin($this->admin($rol));
    }

    private function producto(): Product
    {
        $rate = TaxRate::firstOrCreate(
            ['code' => 'EXEMPT'],
            ['name' => 'Exento', 'rate' => 0, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => 'Agua 600 ml', 'category' => 'Cafetería', 'sale_price' => 3000,
            'cost_price' => 1200, 'stock' => 50, 'min_stock' => 1, 'active' => true,
            'visible_in_app' => true, 'tax_rate_id' => $rate->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    /** Una venta real, hecha por el flujo de caja y no insertada a mano. */
    private function venderEn(CashShift $turno, Product $p, array $headers): void
    {
        $turno->update(['status' => 'open']);
        $this->postJson('/api/admin/caja/sales', [
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'paid' => true,
        ], $headers)->assertStatus(201);
        $turno->update(['status' => 'closed']);
    }

    // ── El informe existe y respeta lo congelado ────────────────────────────

    public function test_un_turno_cerrado_se_puede_consultar(): void
    {
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS);

        $res = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->actingAsAdmin($this->admin()))
            ->assertOk();

        $this->assertSame($turno->id, $res->json('shift.id'));
        $this->assertSame('products', $res->json('shift.type'));
        $this->assertSame('Recepción Mañana', $res->json('shift.opened_by_name'));
        $this->assertSame('Recepción Mañana', $res->json('shift.closed_by_name'));
        $this->assertSame(4, $res->json('shift.operations_count'));
    }

    public function test_los_totales_son_los_congelados_no_un_recalculo(): void
    {
        // El turno dice 12.000 y no tiene ni una venta vinculada. El informe
        // debe seguir diciendo 12.000: es lo que se firmó al cerrar.
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS);

        $res = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->actingAsAdmin($this->admin()))
            ->assertOk();

        $this->assertEqualsWithDelta(12000.0, $res->json('shift.gross_total'), 0.01);
        $this->assertEqualsWithDelta(9000.0, $res->json('shift.cash_total'), 0.01);
        $this->assertEqualsWithDelta(3000.0, $res->json('shift.transfer_total'), 0.01);
        $this->assertSame([], $res->json('transactions'));
    }

    public function test_el_desglose_por_metodo_llega_completo(): void
    {
        $turno = $this->turnoCerrado(CashShiftType::GYM, [
            'cash_sales_total' => 100, 'transfer_total' => 200,
            'card_total' => 300, 'wompi_total' => 400, 'other_total' => 500,
            'sales_total' => 1500,
        ]);

        $res = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->adminHeaders())->assertOk();

        foreach (['cash_total' => 100.0, 'transfer_total' => 200.0, 'card_total' => 300.0,
            'wompi_total' => 400.0, 'other_total' => 500.0] as $campo => $esperado) {
            $this->assertEqualsWithDelta($esperado, $res->json("shift.{$campo}"), 0.01, $campo);
        }
    }

    public function test_una_diferencia_entre_congelado_y_detalle_se_informa_sin_corregirla(): void
    {
        // Puede ser legítima: una venta anulada después del cierre sigue
        // vinculada al turno pero ya no suma. Ajustar el total para que encaje
        // sería falsear el arqueo.
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS, ['sales_total' => 99999]);

        $res = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->adminHeaders())->assertOk();

        $this->assertFalse($res->json('consistency.matches'));
        $this->assertEqualsWithDelta(99999.0, $res->json('consistency.frozen_total'), 0.01);
        $this->assertEqualsWithDelta(99999, $turno->fresh()->sales_total, 0.01, 'el turno no se toca');
    }

    // ── Detalle por tipo, sin mezclar ───────────────────────────────────────

    public function test_productos_lista_sus_ventas_con_lineas(): void
    {
        $admin = $this->admin(Admin::ROLE_SUPER_ADMIN);
        $headers = $this->actingAsAdmin($admin);
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $this->venderEn($turno, $this->producto(), $headers);

        $res = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $headers)->assertOk();

        $this->assertCount(1, $res->json('transactions'));
        $this->assertNotNull($res->json('transactions.0.code'));
        $this->assertSame('Agua 600 ml', $res->json('transactions.0.lines.0.name'));
        $this->assertSame(1, $res->json('transactions.0.lines.0.quantity'));
    }

    public function test_gimnasio_lista_sus_cobros_con_socio_y_plan(): void
    {
        $turno = $this->turnoCerrado(CashShiftType::GYM);
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'benefits' => '', 'active' => true]);
        Payment::create([
            'user_id' => User::factory()->create(['name' => 'Olga Pinzón'])->id,
            'plan_id' => $plan->id, 'amount' => 80000, 'method' => 'cash',
            'status' => 'paid', 'cash_shift_id' => $turno->id,
        ]);

        $res = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->adminHeaders())->assertOk();

        $this->assertCount(1, $res->json('transactions'));
        $this->assertSame('Olga Pinzón', $res->json('transactions.0.member'));
        $this->assertSame('Mensual', $res->json('transactions.0.plan'));
        $this->assertEqualsWithDelta(80000.0, $res->json('transactions.0.total'), 0.01);
    }

    public function test_un_turno_de_gimnasio_no_trae_ventas_de_productos(): void
    {
        $headers = $this->adminHeaders();
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $this->venderEn($productos, $this->producto(), $headers);
        $gym = $this->turnoCerrado(CashShiftType::GYM);

        $res = $this->getJson("/api/admin/caja/shifts/{$gym->id}", $headers)->assertOk();

        $this->assertSame([], $res->json('transactions'));
    }

    public function test_el_detalle_de_gimnasio_no_expone_datos_personales(): void
    {
        // El informe se imprime y circula por correo: no es sitio para el
        // documento, el teléfono ni el correo de un socio.
        $turno = $this->turnoCerrado(CashShiftType::GYM);
        $user = User::factory()->create(['name' => 'Socio', 'document' => '1122334455', 'email' => 'privado@x.com']);
        Payment::create([
            'user_id' => $user->id, 'amount' => 50000, 'method' => 'cash',
            'status' => 'paid', 'cash_shift_id' => $turno->id,
        ]);

        $res = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->adminHeaders())->assertOk();
        $json = json_encode($res->json('transactions'));

        $this->assertStringNotContainsString('1122334455', $json);
        $this->assertStringNotContainsString('privado@x.com', $json);
    }

    // ── Autorización, también cambiando el id en la URL ─────────────────────

    public function test_sin_permiso_de_esa_caja_no_se_consulta(): void
    {
        // Recepción por defecto ve las dos cajas; se usa Administrativo, que no
        // tiene ninguna, para comprobar el cierre de la puerta.
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS);

        $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->actingAsAdmin($this->admin(Admin::ROLE_ADMINISTRATIVO)))
            ->assertStatus(403);
    }

    public function test_un_turno_inexistente_da_404(): void
    {
        $this->getJson('/api/admin/caja/shifts/999999', $this->adminHeaders())->assertStatus(404);
    }

    public function test_el_pdf_exige_el_mismo_permiso_que_la_pantalla(): void
    {
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS);

        $this->get("/api/admin/caja/shifts/{$turno->id}/pdf", $this->actingAsAdmin($this->admin(Admin::ROLE_ADMINISTRATIVO)))
            ->assertStatus(403);
    }

    // ── El PDF ──────────────────────────────────────────────────────────────

    public function test_el_pdf_se_descarga_con_nombre_determinista(): void
    {
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS, ['closed_at' => '2026-09-03 21:59:00']);

        $res = $this->get("/api/admin/caja/shifts/{$turno->id}/pdf", $this->adminHeaders())->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString(
            "iron-body-cierre-products-2026-09-03-turno-{$turno->id}.pdf",
            (string) $res->headers->get('content-disposition'),
        );
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_el_pdf_de_un_turno_no_contiene_operaciones_de_otro(): void
    {
        $headers = $this->adminHeaders();
        $conVentas = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $this->venderEn($conVentas, $this->producto(), $headers);
        $vacio = $this->turnoCerrado(CashShiftType::PRODUCTS);

        $pdf = $this->get("/api/admin/caja/shifts/{$vacio->id}/pdf", $headers)->assertOk()->getContent();

        // El PDF comprime su contenido, así que se comprueba sobre el informe
        // que lo alimenta: es la misma composición.
        $informe = app(CashShiftReport::class)->for($vacio->fresh());
        $this->assertSame([], $informe['transactions']);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    // ── Arqueo ──────────────────────────────────────────────────────────────

    public function test_el_arqueo_exige_manage_no_basta_con_ver(): void
    {
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $recepcion = $this->admin(Admin::ROLE_RECEPCION);

        $this->postJson("/api/admin/caja/shifts/{$turno->id}/difference",
            ['counted_amount' => 8500, 'reason' => 'Conteo de cierre'],
            $this->actingAsAdmin($recepcion))->assertStatus(403);

        $this->assertNull($turno->fresh()->counted_amount);
    }

    public function test_un_arqueo_valido_calcula_la_diferencia_y_deja_traza(): void
    {
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS, ['expected_amount' => 9000]);

        $this->postJson("/api/admin/caja/shifts/{$turno->id}/difference",
            ['counted_amount' => 8500, 'reason' => 'Faltaron billetes en el conteo'],
            $this->adminHeaders())->assertOk();

        $fresco = $turno->fresh();
        $this->assertSame('8500.00', (string) $fresco->counted_amount);
        $this->assertSame('-500.00', (string) $fresco->difference, 'negativo = falta dinero');

        $log = AuditLog::query()->where('entity', 'arqueo')->sole();
        $this->assertNotNull($log->actor_id);
        $this->assertSame((string) $turno->id, $log->entity_id);
        $this->assertSame('9000.00', $log->metadata['expected_amount']);
        $this->assertSame('8500.00', $log->metadata['counted_amount']);
    }

    public function test_el_arqueo_aparece_despues_en_el_informe(): void
    {
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS, ['expected_amount' => 9000]);
        $this->postJson("/api/admin/caja/shifts/{$turno->id}/difference",
            ['counted_amount' => 9000, 'reason' => 'Cuadró exacto'], $this->adminHeaders())->assertOk();

        $res = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->adminHeaders())->assertOk();

        $this->assertEqualsWithDelta(9000.0, $res->json('shift.counted_amount'), 0.01);
        $this->assertEqualsWithDelta(0.0, $res->json('shift.difference'), 0.01);
    }

    // ── El historial sigue funcionando ──────────────────────────────────────

    public function test_el_historial_separa_las_dos_cajas(): void
    {
        $this->turnoCerrado(CashShiftType::PRODUCTS);
        $this->turnoCerrado(CashShiftType::GYM);

        $soloProductos = $this->getJson('/api/admin/caja/shifts?type=products', $this->adminHeaders())->assertOk();
        $this->assertCount(1, $soloProductos->json('data'));
        $this->assertSame('products', $soloProductos->json('data.0.type'));

        $todos = $this->getJson('/api/admin/caja/shifts', $this->adminHeaders())->assertOk();
        $this->assertCount(2, $todos->json('data'));
    }

    public function test_el_historial_pagina(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->turnoCerrado(CashShiftType::PRODUCTS);
        }

        $res = $this->getJson('/api/admin/caja/shifts?per_page=2', $this->adminHeaders())->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(5, $res->json('meta.total'));
        $this->assertSame(3, $res->json('meta.last_page'));
    }

    // ── Autorización por el tipo REAL del turno ─────────────────────────────

    public function test_quien_solo_ve_productos_no_abre_el_cierre_del_gimnasio(): void
    {
        // Cambiar el id en la URL es lo primero que intenta cualquiera. La
        // ruta no sabe de qué caja es el {shift}, así que quien lo comprueba es
        // el controlador contra el tipo real del turno.
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM);
        $parcial = $this->soloPuedeCon('products');

        $this->getJson("/api/admin/caja/shifts/{$productos->id}", $parcial)->assertOk();

        $this->getJson("/api/admin/caja/shifts/{$gimnasio->id}", $parcial)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.view');
    }

    public function test_el_pdf_cierra_la_misma_puerta_que_la_pantalla(): void
    {
        // Comprobar solo una de las dos dejaría la otra abierta cambiando la URL.
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM);
        $parcial = $this->soloPuedeCon('products');

        $this->get("/api/admin/caja/shifts/{$gimnasio->id}/pdf", $parcial)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.view');
    }

    public function test_el_arqueo_de_una_caja_no_lo_autoriza_el_manage_de_la_otra(): void
    {
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM, ['expected_amount' => 5000]);
        $rol = Admin::ROLE_ADMINISTRATIVO;
        foreach (['cash.products.view', 'cash.products.manage', 'cash.gym.view'] as $permiso) {
            RolePermission::create(['role' => $rol, 'permission' => $permiso, 'granted' => true]);
        }
        app(RolePermissionPolicy::class)->flush();

        $this->postJson("/api/admin/caja/shifts/{$gimnasio->id}/difference",
            ['counted_amount' => 4000, 'reason' => 'Conteo cruzado'],
            $this->actingAsAdmin($this->admin($rol)))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.manage');

        $this->assertNull($gimnasio->fresh()->counted_amount);
    }

    // ── Filtros del historial ───────────────────────────────────────────────

    public function test_el_historial_filtra_por_estado(): void
    {
        $this->turnoCerrado(CashShiftType::PRODUCTS);
        $this->turnoCerrado(CashShiftType::PRODUCTS, ['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

        $cerrados = $this->getJson('/api/admin/caja/shifts?status=closed', $this->adminHeaders())->assertOk();

        $this->assertNotEmpty($cerrados->json('data'));
        foreach ($cerrados->json('data') as $fila) {
            $this->assertSame('closed', $fila['status']);
        }
    }

    public function test_el_historial_filtra_por_rango_de_fechas(): void
    {
        $viejo = $this->turnoCerrado(CashShiftType::PRODUCTS, ['opened_at' => now()->subDays(40)]);
        $reciente = $this->turnoCerrado(CashShiftType::PRODUCTS, ['opened_at' => now()->subDay()]);

        $res = $this->getJson(
            '/api/admin/caja/shifts?from='.now()->subDays(7)->toDateString(),
            $this->adminHeaders(),
        )->assertOk();

        $ids = array_column($res->json('data'), 'id');
        $this->assertContains($reciente->id, $ids);
        $this->assertNotContains($viejo->id, $ids);
    }

    public function test_el_historial_filtra_por_responsable(): void
    {
        $otro = $this->admin(Admin::ROLE_RECEPCION);
        $mio = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $ajeno = $this->turnoCerrado(CashShiftType::PRODUCTS, ['opened_by' => $otro->id, 'opened_by_name' => 'Otro']);

        $res = $this->getJson("/api/admin/caja/shifts?opened_by={$otro->id}", $this->adminHeaders())->assertOk();

        $ids = array_column($res->json('data'), 'id');
        $this->assertSame([$ajeno->id], $ids);
        $this->assertNotContains($mio->id, $ids);
    }

    public function test_el_historial_sin_ninguna_caja_visible_da_403(): void
    {
        $this->turnoCerrado(CashShiftType::PRODUCTS);

        $this->getJson('/api/admin/caja/shifts', $this->actingAsAdmin($this->admin(Admin::ROLE_ADMINISTRATIVO)))
            ->assertStatus(403);
    }

    // ── Consultar no modifica ───────────────────────────────────────────────

    public function test_abrir_el_informe_y_el_pdf_no_toca_el_turno(): void
    {
        // Un cierre es un documento contable: consultarlo o imprimirlo no puede
        // cambiar ni un campo, ni siquiera `updated_at`.
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $antes = $turno->fresh()->getAttributes();

        $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->adminHeaders())->assertOk();
        $this->get("/api/admin/caja/shifts/{$turno->id}/pdf", $this->adminHeaders())->assertOk();
        $this->getJson('/api/admin/caja/shifts', $this->adminHeaders())->assertOk();

        $this->assertSame($antes, $turno->fresh()->getAttributes());
    }

    public function test_consultar_un_cierre_no_deja_traza_de_auditoria(): void
    {
        // La auditoría registra lo que CAMBIA. Anotar cada consulta la llenaría
        // de ruido y enterraría los cambios reales.
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $antes = AuditLog::count();

        $this->getJson("/api/admin/caja/shifts/{$turno->id}", $this->adminHeaders())->assertOk();
        $this->get("/api/admin/caja/shifts/{$turno->id}/pdf", $this->adminHeaders())->assertOk();

        $this->assertSame($antes, AuditLog::count());
    }

    public function test_si_la_auditoria_falla_el_arqueo_no_queda_aplicado(): void
    {
        // El arqueo y su traza van en la MISMA transacción a propósito: un
        // descuadre aplicado sin constancia de quién lo declaró sería un
        // agujero contable firmado por nadie. Si la traza no se puede escribir,
        // el arqueo tampoco se aplica.
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS, ['expected_amount' => 9000]);

        $this->app->bind(AuditTrail::class, fn () => new class extends AuditTrail
        {
            public function record(Request $request, array $evento): void
            {
                throw new \RuntimeException('auditoría caída');
            }
        });

        try {
            $this->postJson("/api/admin/caja/shifts/{$turno->id}/difference",
                ['counted_amount' => 8500, 'reason' => 'Conteo con la auditoría caída'],
                $this->adminHeaders());
        } catch (\Throwable $e) {
            $this->assertStringContainsString('auditoría caída', $e->getMessage());
        }

        $fresco = $turno->fresh();
        $this->assertNull($fresco->counted_amount, 'el arqueo se aplicó sin dejar traza');
        $this->assertNull($fresco->difference);
    }

    // ── Rendimiento ─────────────────────────────────────────────────────────

    public function test_el_informe_no_dispara_una_consulta_por_operacion(): void
    {
        // Sin eager loading, un turno de cien ventas con líneas dispara
        // doscientas consultas al abrir el informe. El coste debe depender del
        // NÚMERO DE RELACIONES, no del número de operaciones.
        $headers = $this->adminHeaders();
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $producto = $this->producto();
        for ($i = 0; $i < 8; $i++) {
            $this->venderEn($turno, $producto, $headers);
        }

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $res = $this->getJson("/api/admin/caja/shifts/{$turno->id}", $headers)->assertOk();

        $this->assertCount(8, $res->json('transactions'));
        // Turno + ventas + líneas, más lo que cuesta autenticar la sesión. Muy
        // por debajo de una consulta por venta.
        $this->assertLessThan(
            12,
            $consultas,
            "El informe hizo {$consultas} consultas para 8 ventas: parece un N+1.",
        );
    }

    // ── Autorización dinámica por TIPO de caja ──────────────────────────────
    //
    // El contrato: `cash.products.view` consulta Productos y `cash.gym.view`
    // consulta Gimnasio, cada uno por su cuenta. Antes había una puerta previa
    // fija que exigía `cash.products.view` para TODO el módulo, así que quien
    // solo tuviera gimnasio chocaba antes de que nadie mirase el tipo del
    // turno. Fallaba cerrado, pero era incorrecto.

    public function test_historial_solo_productos_devuelve_solo_productos(): void
    {
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM);

        $res = $this->getJson('/api/admin/caja/shifts',
            $this->conPermisosDeCaja(['cash.products.view']))->assertOk();

        $ids = array_column($res->json('data'), 'id');
        $this->assertContains($productos->id, $ids);
        $this->assertNotContains($gimnasio->id, $ids);
        foreach ($res->json('data') as $fila) {
            $this->assertSame('products', $fila['type']);
        }
    }

    public function test_historial_solo_gimnasio_devuelve_solo_gimnasio(): void
    {
        // Este es el caso que antes daba 403 por la puerta previa.
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM);

        $res = $this->getJson('/api/admin/caja/shifts',
            $this->conPermisosDeCaja(['cash.gym.view']))->assertOk();

        $ids = array_column($res->json('data'), 'id');
        $this->assertContains($gimnasio->id, $ids);
        $this->assertNotContains($productos->id, $ids);
    }

    public function test_historial_con_ambos_permisos_ve_las_dos_cajas(): void
    {
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM);

        $res = $this->getJson('/api/admin/caja/shifts',
            $this->conPermisosDeCaja(['cash.products.view', 'cash.gym.view']))->assertOk();

        $ids = array_column($res->json('data'), 'id');
        $this->assertContains($productos->id, $ids);
        $this->assertContains($gimnasio->id, $ids);
    }

    public function test_historial_sin_ningun_permiso_de_caja_da_403(): void
    {
        $this->turnoCerrado(CashShiftType::PRODUCTS);

        $this->getJson('/api/admin/caja/shifts', $this->conPermisosDeCaja([]))
            ->assertStatus(403);
    }

    public function test_pedir_el_tipo_prohibido_en_la_query_no_expone_nada(): void
    {
        // Manipular ?type=gym teniendo solo productos se rechaza; no se
        // devuelve un conjunto parcial que invite a seguir probando.
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM);

        $res = $this->getJson('/api/admin/caja/shifts?type=gym',
            $this->conPermisosDeCaja(['cash.products.view']))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.view');

        $this->assertStringNotContainsString((string) $gimnasio->id, $res->getContent());
    }

    // ── Detalle ─────────────────────────────────────────────────────────────

    public function test_detalle_solo_productos_abre_productos_y_no_gimnasio(): void
    {
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM);
        $h = $this->conPermisosDeCaja(['cash.products.view']);

        $this->getJson("/api/admin/caja/shifts/{$productos->id}", $h)->assertOk();
        $this->getJson("/api/admin/caja/shifts/{$gimnasio->id}", $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.view');
    }

    public function test_detalle_solo_gimnasio_abre_gimnasio_y_no_productos(): void
    {
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM);
        $h = $this->conPermisosDeCaja(['cash.gym.view']);

        $this->getJson("/api/admin/caja/shifts/{$gimnasio->id}", $h)->assertOk();
        $this->getJson("/api/admin/caja/shifts/{$productos->id}", $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.products.view');
    }

    public function test_un_turno_inexistente_no_dice_nada_de_mas(): void
    {
        $res = $this->getJson('/api/admin/caja/shifts/999999',
            $this->conPermisosDeCaja(['cash.products.view']))->assertStatus(404);

        // Ni tipo, ni responsable, ni importes: un 404 no es sitio para datos.
        foreach (['opened_by_name', 'sales_total', 'expected_cash', 'type_label'] as $campo) {
            $this->assertStringNotContainsString($campo, $res->getContent());
        }
    }

    // ── PDF: exactamente la misma puerta que el detalle ─────────────────────

    public function test_pdf_solo_productos_descarga_productos_y_no_gimnasio(): void
    {
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM);
        $h = $this->conPermisosDeCaja(['cash.products.view']);

        $ok = $this->get("/api/admin/caja/shifts/{$productos->id}/pdf", $h)->assertOk();
        $this->assertStringStartsWith('%PDF', $ok->getContent());

        $this->get("/api/admin/caja/shifts/{$gimnasio->id}/pdf", $h)->assertStatus(403);
    }

    public function test_pdf_solo_gimnasio_descarga_gimnasio_y_no_productos(): void
    {
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS);
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM);
        $h = $this->conPermisosDeCaja(['cash.gym.view']);

        $ok = $this->get("/api/admin/caja/shifts/{$gimnasio->id}/pdf", $h)->assertOk();
        $this->assertStringStartsWith('%PDF', $ok->getContent());

        $this->get("/api/admin/caja/shifts/{$productos->id}/pdf", $h)->assertStatus(403);
    }

    // ── Arqueo: exige SUPERVISIÓN de esa caja ───────────────────────────────

    public function test_arqueo_con_products_manage_solo_alcanza_productos(): void
    {
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS, ['expected_amount' => 9000]);
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM, ['expected_amount' => 5000]);
        $h = $this->conPermisosDeCaja(['cash.products.view', 'cash.products.manage', 'cash.gym.view']);

        $this->postJson("/api/admin/caja/shifts/{$productos->id}/difference",
            ['counted_amount' => 8500, 'reason' => 'Conteo de productos'], $h)->assertOk();
        $this->assertSame('-500.00', (string) $productos->fresh()->difference);

        $this->postJson("/api/admin/caja/shifts/{$gimnasio->id}/difference",
            ['counted_amount' => 4000, 'reason' => 'Conteo del gimnasio'], $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.gym.manage');
        $this->assertNull($gimnasio->fresh()->counted_amount);
    }

    public function test_arqueo_con_gym_manage_solo_alcanza_gimnasio(): void
    {
        $productos = $this->turnoCerrado(CashShiftType::PRODUCTS, ['expected_amount' => 9000]);
        $gimnasio = $this->turnoCerrado(CashShiftType::GYM, ['expected_amount' => 5000]);
        $h = $this->conPermisosDeCaja(['cash.gym.view', 'cash.gym.manage', 'cash.products.view']);

        $this->postJson("/api/admin/caja/shifts/{$gimnasio->id}/difference",
            ['counted_amount' => 5000, 'reason' => 'Cuadró el gimnasio'], $h)->assertOk();
        $this->assertSame('0.00', (string) $gimnasio->fresh()->difference);

        $this->postJson("/api/admin/caja/shifts/{$productos->id}/difference",
            ['counted_amount' => 1, 'reason' => 'Intento cruzado'], $h)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.products.manage');
        $this->assertNull($productos->fresh()->counted_amount);
    }

    public function test_arqueo_ver_no_es_supervisar(): void
    {
        $turno = $this->turnoCerrado(CashShiftType::PRODUCTS, ['expected_amount' => 9000]);

        $this->postJson("/api/admin/caja/shifts/{$turno->id}/difference",
            ['counted_amount' => 8500, 'reason' => 'Solo tengo lectura'],
            $this->conPermisosDeCaja(['cash.products.view']))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'cash.products.manage');

        $this->assertNull($turno->fresh()->counted_amount);
    }
}
