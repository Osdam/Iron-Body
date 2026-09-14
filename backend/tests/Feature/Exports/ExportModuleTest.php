<?php

namespace Tests\Feature\Exports;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\Plan;
use App\Models\User;
use App\Support\Caja\PaymentMethodKind;
use App\Support\Members\MembershipFilter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * Módulo de exportación.
 *
 * Lo que fijan estas pruebas, por orden de lo que costaría equivocarse:
 *  1. Quién puede descargar. Es la vía más directa para sacar datos personales.
 *  2. Que no se pueda pedir una columna que el dataset no declara.
 *  3. Que cada descarga deje traza, y que la traza no contenga los datos.
 *  4. Que lo descargado diga la verdad: situación de membresía, desglose mixto.
 *  5. Que el fichero se pueda abrir: CSV legible por Excel y .xlsx válido.
 */
class ExportModuleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = Admin::ROLE_ADMINISTRADOR): Admin
    {
        return Admin::create([
            'name' => 'Exportadora',
            'email' => 'exp-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function socio(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Socio '.uniqid(),
            'email' => 'socio-'.uniqid().'@example.com',
            'password' => 'secret',
            'document' => (string) random_int(10000000, 99999999),
            'phone' => '3001234567',
            'status' => 'active',
        ], $attrs));
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Bogota')->startOfDay();
    }

    /** Descarga y devuelve el CSV como filas asociativas (encabezado => valor). */
    private function csv(TestResponse $res): array
    {
        $contenido = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido, 'sin BOM Excel rompe las tildes');

        $lineas = array_values(array_filter(preg_split('/\r?\n/', substr($contenido, 3))));
        $encabezado = str_getcsv(array_shift($lineas), ',', '"', '');

        return array_map(fn ($l) => array_combine($encabezado, str_getcsv($l, ',', '"', '')), $lineas);
    }

    private function exportar(array $h, string $dataset, array $params): TestResponse
    {
        return $this->get('/api/admin/exports/'.$dataset.'?'.http_build_query($params), $h);
    }

    // ── 1. Quién puede ──────────────────────────────────────────────────────

    public function test_el_catalogo_solo_ofrece_lo_que_la_sesion_puede_descargar(): void
    {
        $admin = $this->getJson('/api/admin/exports', $this->actingAsAdmin($this->admin()))->assertOk();
        $this->assertEqualsCanonicalizing(['members', 'payments'], array_column($admin->json('datasets'), 'key'));
        $this->assertContains('xlsx', array_column($admin->json('formats'), 'key'));

        // Recepción ve y cobra, pero no se lleva la base de socios.
        $recepcion = $this->getJson('/api/admin/exports', $this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION)))->assertOk();
        $this->assertSame([], $recepcion->json('datasets'));
    }

    public function test_recepcion_no_puede_descargar_socios_aunque_fuerce_la_url(): void
    {
        $this->socio();

        $this->exportar($this->actingAsAdmin($this->admin(Admin::ROLE_RECEPCION)), 'members', [
            'format' => 'csv', 'columns' => ['name', 'document'],
        ])->assertStatus(403);
    }

    public function test_exportar_es_un_permiso_distinto_de_ver(): void
    {
        $this->socio();

        // Puede VER miembros pero no exportarlos.
        $h = $this->adminWithPermissions(['members.view']);
        $this->exportar($h, 'members', ['format' => 'csv', 'columns' => ['name']])->assertStatus(403);

        // Con el permiso de exportar, sí.
        $h2 = $this->adminWithPermissions(['members.view', 'members.export']);
        $this->exportar($h2, 'members', ['format' => 'csv', 'columns' => ['name']])->assertOk();
    }

    public function test_sin_sesion_no_hay_descarga(): void
    {
        $this->get('/api/admin/exports/members?format=csv&columns[]=name')->assertStatus(401);
    }

    // ── 2. Lista blanca de columnas ─────────────────────────────────────────

    public function test_no_se_puede_pedir_una_columna_que_el_dataset_no_declara(): void
    {
        $h = $this->actingAsAdmin($this->admin());

        // `password` existe en la tabla: precisamente por eso no puede colarse.
        $this->exportar($h, 'members', ['format' => 'csv', 'columns' => ['name', 'password']])
            ->assertStatus(422);

        $this->exportar($h, 'members', ['format' => 'csv', 'columns' => []])->assertStatus(422);
        $this->exportar($h, 'members', ['format' => 'pdf', 'columns' => ['name']])->assertStatus(422);
    }

    public function test_solo_salen_las_columnas_elegidas_y_en_orden_estable(): void
    {
        $this->socio(['name' => 'Ana Pérez', 'document' => '1075000111']);
        $h = $this->actingAsAdmin($this->admin());

        // Se piden en desorden; el fichero respeta el orden del dataset.
        $filas = $this->csv($this->exportar($h, 'members', [
            'format' => 'csv', 'columns' => ['document', 'name'],
        ])->assertOk());

        $this->assertSame(['Nombre', 'Documento'], array_keys($filas[0]));
        $this->assertSame('Ana Pérez', $filas[0]['Nombre'], 'las tildes llegan intactas');
        $this->assertArrayNotHasKey('Correo', $filas[0]);
    }

    // ── 3. Auditoría ────────────────────────────────────────────────────────

    public function test_cada_descarga_queda_auditada_sin_los_datos_exportados(): void
    {
        $this->socio(['document' => '1075999888', 'phone' => '3159998877']);
        $h = $this->actingAsAdmin($this->admin());

        $this->exportar($h, 'members', [
            'format' => 'csv', 'columns' => ['name', 'document', 'phone'], 'membership' => 'all',
        ])->assertOk()->streamedContent();

        $traza = AuditLog::query()->where('action', 'export')->first();
        $this->assertNotNull($traza, 'una exportación sin traza es un agujero');
        $this->assertSame('members', $traza->entity);
        $this->assertSame(['document', 'phone'], $traza->metadata['personal_columns']);
        $this->assertSame(1, $traza->metadata['rows']);

        // La traza dice QUÉ se pidió, nunca los valores.
        $crudo = json_encode($traza->toArray());
        $this->assertStringNotContainsString('1075999888', $crudo);
        $this->assertStringNotContainsString('3159998877', $crudo);
    }

    // ── 4. Lo que dice el fichero ───────────────────────────────────────────

    public function test_la_situacion_de_la_membresia_se_calcula_por_fechas(): void
    {
        $hoy = $this->hoy();
        $this->socio(['name' => 'Vencida', 'membership_end_date' => $hoy->subDays(3)->toDateString()]);
        $this->socio(['name' => 'PorVencer', 'membership_end_date' => $hoy->addDays(5)->toDateString()]);
        $this->socio(['name' => 'Activa', 'membership_end_date' => $hoy->addDays(40)->toDateString()]);
        $this->socio(['name' => 'Nada', 'membership_end_date' => null]);

        $filas = collect($this->csv($this->exportar($this->actingAsAdmin($this->admin()), 'members', [
            'format' => 'csv', 'columns' => ['name', 'membership_state', 'days_left'],
        ])))->keyBy('Nombre');

        $this->assertSame('Vencida', $filas['Vencida']['Situación']);
        $this->assertSame('-3', $filas['Vencida']['Días restantes']);
        $this->assertSame('Por vencer', $filas['PorVencer']['Situación']);
        $this->assertSame('Activa', $filas['Activa']['Situación']);
        $this->assertSame('Sin membresía', $filas['Nada']['Situación']);
        $this->assertSame('', $filas['Nada']['Días restantes']);
    }

    public function test_se_pueden_exportar_los_que_estan_cerca_de_vencer(): void
    {
        $hoy = $this->hoy();
        $this->socio(['name' => 'EnTresDias', 'membership_end_date' => $hoy->addDays(3)->toDateString()]);
        $this->socio(['name' => 'EnVeinteDias', 'membership_end_date' => $hoy->addDays(20)->toDateString()]);
        $this->socio(['name' => 'EnCienDias', 'membership_end_date' => $hoy->addDays(100)->toDateString()]);
        $this->socio(['name' => 'YaVencida', 'membership_end_date' => $hoy->subDay()->toDateString()]);

        $h = $this->actingAsAdmin($this->admin());

        $semana = $this->csv($this->exportar($h, 'members', [
            'format' => 'csv', 'columns' => ['name'], 'membership' => 'expiring_7',
        ]));
        $this->assertSame(['EnTresDias'], array_column($semana, 'Nombre'));

        $mes = $this->csv($this->exportar($h, 'members', [
            'format' => 'csv', 'columns' => ['name'], 'membership' => 'expiring_30',
        ]));
        $this->assertEqualsCanonicalizing(['EnTresDias', 'EnVeinteDias'], array_column($mes, 'Nombre'));
    }

    public function test_se_pueden_exportar_las_vencidas_hace_poco_para_recuperarlas(): void
    {
        $hoy = $this->hoy();
        $this->socio(['name' => 'VencioAyer', 'membership_end_date' => $hoy->subDay()->toDateString()]);
        $this->socio(['name' => 'VencioHaceUnAno', 'membership_end_date' => $hoy->subDays(365)->toDateString()]);

        $filas = $this->csv($this->exportar($this->actingAsAdmin($this->admin()), 'members', [
            'format' => 'csv', 'columns' => ['name'], 'membership' => 'expired_recent',
        ]));

        $this->assertSame(['VencioAyer'], array_column($filas, 'Nombre'));
    }

    public function test_el_rango_de_vencimiento_permite_acotar_a_mano(): void
    {
        $hoy = $this->hoy();
        $this->socio(['name' => 'Dentro', 'membership_end_date' => $hoy->addDays(10)->toDateString()]);
        $this->socio(['name' => 'Fuera', 'membership_end_date' => $hoy->addDays(40)->toDateString()]);

        $filas = $this->csv($this->exportar($this->actingAsAdmin($this->admin()), 'members', [
            'format' => 'csv',
            'columns' => ['name'],
            'end_from' => $hoy->toDateString(),
            'end_to' => $hoy->addDays(15)->toDateString(),
        ]));

        $this->assertSame(['Dentro'], array_column($filas, 'Nombre'));
    }

    public function test_la_situacion_usa_el_mismo_umbral_que_el_filtro(): void
    {
        // Si los dos umbrales divergieran, una exportación de «vencen en 7
        // días» podría traer filas marcadas como «Activa».
        $hoy = $this->hoy();
        $this->socio(['name' => 'Justo', 'membership_end_date' => $hoy->addDays(MembershipFilter::EXPIRING_SOON_DAYS)->toDateString()]);

        $filas = $this->csv($this->exportar($this->actingAsAdmin($this->admin()), 'members', [
            'format' => 'csv', 'columns' => ['name', 'membership_state'],
            'membership' => 'expiring_'.MembershipFilter::EXPIRING_SOON_DAYS,
        ]));

        $this->assertSame('Por vencer', $filas[0]['Situación']);
    }

    public function test_el_filtro_de_vencidas_trae_solo_vencidas(): void
    {
        $hoy = $this->hoy();
        $this->socio(['name' => 'Vencida', 'membership_end_date' => $hoy->subDay()->toDateString()]);
        $this->socio(['name' => 'Activa', 'membership_end_date' => $hoy->addDays(60)->toDateString()]);

        $filas = $this->csv($this->exportar($this->actingAsAdmin($this->admin()), 'members', [
            'format' => 'csv', 'columns' => ['name'], 'membership' => 'expired',
        ]));

        $this->assertSame(['Vencida'], array_column($filas, 'Nombre'));
    }

    public function test_el_ultimo_pago_ignora_cobros_pendientes_posteriores(): void
    {
        $u = $this->socio(['name' => 'Pagador']);
        Payment::create(['user_id' => $u->id, 'amount' => 70000, 'method' => 'cash', 'status' => 'paid', 'paid_at' => now()->subDays(10)]);
        // Un cobro posterior que no se completó no puede pasar por «último pago».
        Payment::create(['user_id' => $u->id, 'amount' => 99000, 'method' => 'card', 'status' => 'pending']);

        $filas = $this->csv($this->exportar($this->actingAsAdmin($this->admin()), 'members', [
            'format' => 'csv', 'columns' => ['name', 'last_payment_amount', 'last_payment_method'],
        ]));

        $this->assertSame('70000', $filas[0]['Último pago: monto']);
        $this->assertSame('Efectivo', $filas[0]['Último pago: método']);
    }

    public function test_los_pagos_mixtos_salen_desglosados_y_reparten_por_medio(): void
    {
        $u = $this->socio(['name' => 'Mixto']);
        $pago = Payment::create(['user_id' => $u->id, 'amount' => 60000, 'method' => Payment::MIXED_METHOD, 'status' => 'paid', 'paid_at' => now()]);
        PaymentSplit::create(['payment_id' => $pago->id, 'method' => 'cash', 'amount' => 20000]);
        PaymentSplit::create(['payment_id' => $pago->id, 'method' => 'nequi', 'amount' => 40000]);

        $filas = $this->csv($this->exportar($this->actingAsAdmin($this->admin()), 'payments', [
            'format' => 'csv',
            'columns' => ['amount', 'method', 'breakdown', 'by_cash', 'by_transfer', 'by_card'],
        ]));

        $this->assertSame('60000', $filas[0]['Monto']);
        $this->assertSame('Mixto', $filas[0]['Método']);
        $this->assertSame('Efectivo 20.000 · Nequi / Daviplata 40.000', $filas[0]['Desglose']);
        // Columnas para sumar en Excel: 20.000 + 40.000, y nada en tarjeta.
        $this->assertSame('20000', $filas[0]['En efectivo']);
        $this->assertSame('40000', $filas[0]['En transferencia']);
        $this->assertSame('0', $filas[0]['En tarjeta']);
    }

    public function test_filtrar_por_un_medio_incluye_los_mixtos_que_lo_usan(): void
    {
        $u = $this->socio();
        Payment::create(['user_id' => $u->id, 'amount' => 50000, 'method' => 'card', 'status' => 'paid', 'paid_at' => now(), 'reference' => 'SOLO-TARJETA']);
        Payment::create(['user_id' => $u->id, 'amount' => 30000, 'method' => 'cash', 'status' => 'paid', 'paid_at' => now(), 'reference' => 'SOLO-EFECTIVO']);
        $mixto = Payment::create(['user_id' => $u->id, 'amount' => 45000, 'method' => Payment::MIXED_METHOD, 'status' => 'paid', 'paid_at' => now(), 'reference' => 'MIXTO']);
        PaymentSplit::create(['payment_id' => $mixto->id, 'method' => 'cash', 'amount' => 40000]);
        PaymentSplit::create(['payment_id' => $mixto->id, 'method' => 'datafono', 'amount' => 5000]);

        $filas = $this->csv($this->exportar($this->actingAsAdmin($this->admin()), 'payments', [
            'format' => 'csv', 'columns' => ['reference'], 'method' => 'card',
        ]));

        $this->assertEqualsCanonicalizing(['SOLO-TARJETA', 'MIXTO'], array_column($filas, 'Referencia'));
    }

    public function test_el_rango_de_fechas_de_pagos_se_respeta(): void
    {
        $u = $this->socio();
        Payment::create(['user_id' => $u->id, 'amount' => 1000, 'method' => 'cash', 'status' => 'paid', 'paid_at' => '2026-08-15 10:00:00', 'reference' => 'AGOSTO']);
        Payment::create(['user_id' => $u->id, 'amount' => 2000, 'method' => 'cash', 'status' => 'paid', 'paid_at' => '2026-09-05 10:00:00', 'reference' => 'SEPTIEMBRE']);

        $filas = $this->csv($this->exportar($this->actingAsAdmin($this->admin()), 'payments', [
            'format' => 'csv', 'columns' => ['reference'], 'from' => '2026-09-01', 'to' => '2026-09-30',
        ]));

        $this->assertSame(['SEPTIEMBRE'], array_column($filas, 'Referencia'));
    }

    // ── 5. Que el fichero se pueda abrir ────────────────────────────────────

    public function test_un_nombre_con_formula_no_se_ejecuta_al_abrir_el_csv(): void
    {
        $this->socio(['name' => '=HYPERLINK("http://malo","clic")']);

        $filas = $this->csv($this->exportar($this->actingAsAdmin($this->admin()), 'members', [
            'format' => 'csv', 'columns' => ['name'],
        ]));

        $this->assertStringStartsWith("'=", $filas[0]['Nombre']);
    }

    public function test_el_excel_es_un_xlsx_valido_con_los_datos(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 70000, 'duration_days' => 30, 'benefits' => '', 'active' => true]);
        $this->socio(['name' => 'Ana & <Luis>', 'plan' => $plan->name, 'membership_end_date' => $this->hoy()->addDays(20)->toDateString()]);

        $res = $this->exportar($this->actingAsAdmin($this->admin()), 'members', [
            'format' => 'xlsx', 'columns' => ['name', 'plan', 'membership_end', 'days_left'],
        ])->assertOk();

        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('iron-body-miembros-', $res->headers->get('Content-Disposition'));

        $ruta = $res->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true, 'el .xlsx tiene que ser un zip válido');

        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $parte) {
            $this->assertNotFalse($zip->locateName($parte), "falta {$parte}");
        }

        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        // XML bien formado: un «&» o un «<» sin escapar lo corrompería entero.
        $this->assertNotFalse(simplexml_load_string($hoja), 'la hoja no es XML válido');
        $this->assertStringContainsString('Ana &amp; &lt;Luis&gt;', $hoja);
        $this->assertStringContainsString('Mensual', $hoja);
        // Los días restantes van como número, no como texto.
        $this->assertMatchesRegularExpression('/<c r="D2" s="2"><v>20<\/v><\/c>/', $hoja);
    }

    public function test_los_permisos_de_exportar_salen_como_seccion_propia_en_roles(): void
    {
        // Metidos al final de Miembros y de Pagos no los encontraba nadie en la
        // matriz de Configuración → Usuarios y roles.
        $filas = collect(\App\Support\Access\PermissionCatalog::rows())->keyBy('key');

        foreach (['members.export' => 'Miembros', 'payments.export' => 'Pagos'] as $clave => $etiqueta) {
            $this->assertSame('exports', $filas[$clave]['domain'], "{$clave} fuera de Exportaciones");
            $this->assertSame('Exportaciones', $filas[$clave]['domain_label']);
            $this->assertSame($etiqueta, $filas[$clave]['label']);
        }

        // Y la clave NO cambia: lo que exige la ruta sigue siendo lo mismo.
        $this->assertSame('members', $filas['members.view']['domain']);
        $this->assertContains('members.export', \App\Support\Access\PermissionCatalog::all());
    }

    public function test_cada_alias_de_medio_normaliza_a_su_medio(): void
    {
        // El filtro SQL usa aliases(); el arqueo usa normalize(). Si divergen,
        // el filtro deja fuera cobros sin avisar.
        foreach (PaymentMethodKind::cases() as $medio) {
            foreach ($medio->aliases() as $alias) {
                $this->assertSame($medio, PaymentMethodKind::normalize($alias), "«{$alias}»");
            }
        }
    }
}
