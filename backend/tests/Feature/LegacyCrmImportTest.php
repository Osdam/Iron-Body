<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Services\Billing\PaymentOriginInspector;
use App\Support\LegacyPlanMap;
use Database\Seeders\LegacyPlansSeeder;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Ensayo de la importación de los export NATIVOS del sistema anterior.
 *
 * A diferencia de {@see LegacyMigrationCsvTest}, que necesita el CSV real en
 * storage/, aquí los export se fabrican en el propio test con las mismas
 * cabeceras, los mismos textos («Si»/«No», «N/A», «$ 80.000 (Efectivo)») y los
 * mismos casos raros que trae el archivo de verdad. Así lo que se comprueba es
 * el comportamiento, no la presencia de un archivo que no se versiona.
 */
class LegacyCrmImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/legacy-import');
        File::ensureDirectoryExists($this->dir);
        $this->seed(PlansSeeder::class);
        $this->seed(LegacyPlansSeeder::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ── Fabricación de los export ───────────────────────────────────────────

    private const CAB_CLIENTES = 'ID del cliente;Identificación del cliente;Nombre completo del cliente;'
        .'Celular del cliente;Correo electrónico del cliente;Dirección del cliente;'
        .'Fecha de nacimiento del cliente;Observaciones del cliente;'
        .'Nombre completo del contacto de respaldo;Celular del contacto de respaldo';

    private const CAB_MEMBRESIAS = 'ID del cliente;Identificación del cliente;Nombre del plan;'
        .'ID de la membrecía;Fecha y hora de compra de la membrecía;Fecha de inicio de la membrecía;'
        .'Fecha de finalización de la membrecía;Fecha de finalización de la membrecía + Prorroga;'
        .'La membrecía está anulada?;La membrecía está totalmente paga?;Total pagado;'
        .'Valor con descuento de la membrecía;Pagos realizados';

    private function clientes(array $filas): string
    {
        // Con BOM, como lo entrega el sistema anterior.
        $ruta = $this->dir.'/clientes.csv';
        File::put($ruta, "\xEF\xBB\xBF".self::CAB_CLIENTES."\n".implode("\n", $filas)."\n");

        return $ruta;
    }

    private function membresias(array $filas): string
    {
        $ruta = $this->dir.'/membresias.csv';
        File::put($ruta, "\xEF\xBB\xBF".self::CAB_MEMBRESIAS."\n".implode("\n", $filas)."\n");

        return $ruta;
    }

    private function importar(string $clientes, string $membresias, array $extra = []): void
    {
        $this->artisan('iron:import-legacy-crm', array_merge([
            '--clientes' => $clientes,
            '--membresias' => $membresias,
        ], $extra))->assertSuccessful();
    }

    // ── Casos ───────────────────────────────────────────────────────────────

    public function test_importa_socio_con_su_plan_vigencia_y_pago(): void
    {
        $this->importar(
            $this->clientes(['1;1119211883;Yureini Parra;+57 3204466993;yu@example.com;Calle 1;1995-04-22;;Ana Parra;3001112233']),
            $this->membresias(['1;1119211883;MENSUALIDAD;9927;2026-08-31 20:54:00;2026-08-31;2026-09-29;2026-09-29;No;Si;80000;80000;$ 80.000 (Efectivo)']),
        );

        $user = User::where('document', '1119211883')->firstOrFail();
        $this->assertSame('Yureini Parra', $user->name);
        $this->assertSame('3204466993', $user->phone);
        $this->assertSame('Ana Parra - 3001112233', $user->emergency_contact);
        // MENSUALIDAD es el nombre del export; en el catálogo se llama distinto.
        $this->assertSame('Plan Mensual', $user->plan);
        $this->assertSame('2026-09-29', $user->membershipEndDate);

        $member = Member::where('document_number', '1119211883')->firstOrFail();
        $this->assertSame($user->id, $member->user_id);
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
        // La huella del sistema viejo no sirve aquí: el rostro se captura después.
        $this->assertSame(Member::BIOMETRIC_PENDING, $member->biometric_status);

        $pago = Payment::where('reference', 'MIGR-9927')->firstOrFail();
        $this->assertSame(80000.0, $pago->amount);
        $this->assertSame('paid', $pago->status);
        $this->assertSame('efectivo', $pago->method);
        $this->assertSame($member->id, $pago->member_id);
        $this->assertSame(Plan::where('name', 'Plan Mensual')->value('id'), $pago->plan_id);
    }

    public function test_trae_el_historial_completo_de_pagos_no_solo_el_ultimo(): void
    {
        $this->importar(
            $this->clientes(['1;1119211883;Yureini Parra;+57 3204466993;;;1995-04-22;;;']),
            $this->membresias([
                '1;1119211883;MENSUALIDAD;100;2025-01-05 10:00:00;2025-01-05;2025-02-03;2025-02-03;No;Si;80000;80000;$ 80.000 (Efectivo)',
                '1;1119211883;MENSUALIDAD;200;2025-02-05 10:00:00;2025-02-05;2025-03-06;2025-03-06;No;Si;80000;80000;$ 80.000 (Transferencia)',
                '1;1119211883;TRIMESTRE;300;2026-08-01 10:00:00;2026-08-01;2026-10-30;2026-10-30;No;Si;210000;210000;$ 210.000 (Pago por datáfono o tarjeta)',
            ]),
        );

        // Una fila de pago por membresía: el histórico entero, no sólo la última.
        $this->assertSame(3, Payment::count());
        $this->assertSame('transferencia', Payment::where('reference', 'MIGR-200')->value('method'));
        $this->assertSame('datafono', Payment::where('reference', 'MIGR-300')->value('method'));

        // La vigencia la marca la membresía que termina más tarde, no la última
        // fila leída: el export no viene ordenado.
        $user = User::where('document', '1119211883')->firstOrFail();
        $this->assertSame('Trimestre', $user->plan);
        $this->assertSame('2026-10-30', $user->membershipEndDate);
    }

    public function test_el_datafono_cuenta_como_cobro_de_mostrador(): void
    {
        // Si `datafono` no estuviera entre los métodos manuales, la facturación
        // electrónica le exigiría una transacción de pasarela que nunca existió.
        $this->assertContains('datafono', PaymentOriginInspector::MANUAL_PAYMENT_METHODS);
    }

    public function test_reimportar_no_duplica_socios_ni_pagos(): void
    {
        $clientes = $this->clientes(['1;1119211883;Yureini Parra;+57 3204466993;;;1995-04-22;;;']);
        $membresias = $this->membresias([
            '1;1119211883;MENSUALIDAD;9927;2026-08-31 20:54:00;2026-08-31;2026-09-29;2026-09-29;No;Si;80000;80000;$ 80.000 (Efectivo)',
        ]);

        $this->importar($clientes, $membresias);
        $this->importar($clientes, $membresias);

        $this->assertSame(1, User::count());
        $this->assertSame(1, Member::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_no_acorta_una_membresia_mas_larga_ya_existente(): void
    {
        $user = User::create([
            'name' => 'Yureini Parra', 'email' => 'ya@example.com', 'password' => 'secret',
            'document' => '1119211883', 'phone' => '3001112233', 'status' => 'active',
            'plan' => 'Anualidad', 'membership_end_date' => '2027-12-31',
        ]);

        $this->importar(
            $this->clientes(['1;1119211883;Yureini Parra;+57 3204466993;;;1995-04-22;;;']),
            $this->membresias(['1;1119211883;MENSUALIDAD;9927;2026-08-31 20:54:00;2026-08-31;2026-09-29;2026-09-29;No;Si;80000;80000;$ 80.000 (Efectivo)']),
        );

        // Quien renovó dentro de Iron Body no puede perder días por reimportar.
        $user->refresh();
        $this->assertSame('2027-12-31', $user->membershipEndDate);
        $this->assertSame('Anualidad', $user->plan);
        // El pago histórico sí entra: es un hecho económico que ocurrió.
        $this->assertNotNull(Payment::where('reference', 'MIGR-9927')->first());
    }

    public function test_no_pisa_datos_ya_corregidos_en_iron_body(): void
    {
        User::create([
            'name' => 'Yureini Parra', 'email' => 'ya@example.com', 'password' => 'secret',
            'document' => '1119211883', 'phone' => '3009998877', 'address' => 'Dirección corregida',
            'status' => 'active',
        ]);

        $this->importar(
            $this->clientes(['1;1119211883;Yureini Parra;+57 3204466993;;Calle vieja;1995-04-22;;;']),
            $this->membresias([]),
        );

        $user = User::where('document', '1119211883')->firstOrFail();
        $this->assertSame('3009998877', $user->phone);
        $this->assertSame('Dirección corregida', $user->address);
        // El hueco sí se rellena.
        $this->assertSame('1995-04-22', $user->birth_date->toDateString());
    }

    public function test_una_cuenta_cerrada_no_revive_por_aparecer_en_el_export(): void
    {
        $user = User::create([
            'name' => 'Quien Se Fue', 'email' => 'fue@example.com', 'password' => 'secret',
            'document' => '1119211883', 'status' => 'active',
        ]);
        Member::create([
            'user_id' => $user->id, 'full_name' => 'Quien Se Fue',
            'document_number' => '1119211883', 'status' => Member::STATUS_SUSPENDED,
        ]);

        $this->importar(
            $this->clientes(['1;1119211883;Quien Se Fue;+57 3204466993;;;1995-04-22;;;']),
            $this->membresias([]),
        );

        $this->assertSame(
            Member::STATUS_SUSPENDED,
            Member::where('document_number', '1119211883')->value('status'),
        );
    }

    public function test_omite_las_fichas_basura_y_limpia_los_documentos_sucios(): void
    {
        $this->importar(
            $this->clientes([
                '46;repetido;repetido;+57 ;;;;;;',
                '91;repetido;repetido;+57 000;;;;;;',
                // Pipe pegado por el sistema viejo: sin limpiarlo el socio nunca
                // podría entrar, porque el login normaliza sólo dígitos.
                '3023;1075270160|;María del Mar Cabrera;+57 3142164119;;;2000-01-01;;;',
                // Un PPT venezolano es un documento válido: las letras se conservan.
                '2515;5125938ppt;Josue Natanael Loyo;+57 3043939150;;;1990-05-05;;;',
            ]),
            $this->membresias([]),
        );

        // Las 49 fichas «repetido» del export comparten clave: entrarían todas
        // como una sola persona y se pisarían entre sí.
        $this->assertSame(2, User::count());
        $this->assertNotNull(User::where('document', '1075270160')->first());
        $this->assertNotNull(User::where('document', '5125938ppt')->first());
    }

    public function test_la_membresia_anulada_queda_como_traza_no_como_ingreso(): void
    {
        $this->importar(
            $this->clientes(['1;1119211883;Yureini Parra;+57 3204466993;;;1995-04-22;;;']),
            $this->membresias([
                '1;1119211883;MENSUALIDAD;500;2026-08-31 20:54:00;2026-08-31;2026-09-29;2026-09-29;Si;Si;80000;80000;$ 80.000 (Efectivo)',
            ]),
        );

        $pago = Payment::where('reference', 'MIGR-500')->firstOrFail();
        $this->assertSame('cancelled', $pago->status);

        // Y no manda la vigencia: una membresía anulada no da acceso.
        $this->assertNull(User::where('document', '1119211883')->value('membership_end_date'));
    }

    public function test_una_membresia_sin_pagar_queda_pendiente_por_su_valor(): void
    {
        $this->importar(
            $this->clientes(['1;1119211883;Yureini Parra;+57 3204466993;;;1995-04-22;;;']),
            $this->membresias([
                '1;1119211883;MENSUALIDAD;600;2026-08-31 20:54:00;2026-08-31;2026-09-29;2026-09-29;No;No;0;80000;',
            ]),
        );

        $pago = Payment::where('reference', 'MIGR-600')->firstOrFail();
        $this->assertSame('pending', $pago->status);
        $this->assertSame(80000.0, $pago->amount);
        $this->assertNull($pago->paid_at);
    }

    public function test_el_cliente_sin_ninguna_membresia_tambien_entra(): void
    {
        // 177 personas del export nunca compraron. Son contactos reales del
        // gimnasio y perderlos sería perder la base comercial.
        $this->importar(
            $this->clientes(['1;1119211883;Sin Membresia;+57 3204466993;;;1995-04-22;;;']),
            $this->membresias([]),
        );

        $user = User::where('document', '1119211883')->firstOrFail();
        $this->assertNull($user->plan);
        $this->assertNull($user->membership_end_date);
        $this->assertNotNull(Member::where('document_number', '1119211883')->first());
    }

    public function test_dos_socios_con_el_mismo_correo_no_chocan(): void
    {
        // `users.email` es único y el export repite correos entre familiares.
        $this->importar(
            $this->clientes([
                '1;1111111111;Hermano Uno;+57 3204466993;casa@example.com;;1990-01-01;;;',
                '2;2222222222;Hermano Dos;+57 3204466994;casa@example.com;;1992-01-01;;;',
            ]),
            $this->membresias([]),
        );

        $this->assertSame(2, User::count());
        $this->assertSame('casa@example.com', User::where('document', '1111111111')->value('email'));
        $this->assertSame('socio-2222222222@ironbody.local', User::where('document', '2222222222')->value('email'));
    }

    public function test_una_fila_que_falla_no_se_lleva_por_delante_a_las_demas(): void
    {
        // Enredo heredado que sí existe: una ficha de app enlazada a una cuenta
        // cuyo documento es otro. Al importar ese documento, la ficha nueva
        // reclama el mismo `user_id` y choca contra el índice único.
        $ocupado = User::create([
            'name' => 'Cuenta Ocupada', 'email' => 'ocupada@example.com', 'password' => 'secret',
            'document' => '1111111111', 'status' => 'active',
        ]);
        Member::create([
            'user_id' => $ocupado->id, 'full_name' => 'Otra Ficha',
            'document_number' => '9999999999', 'status' => Member::STATUS_ACTIVE,
        ]);

        $this->importar(
            $this->clientes([
                '1;1111111111;Cuenta Ocupada;+57 3204466993;;;1990-01-01;;;',
                '2;2222222222;Socio Sano;+57 3204466994;;;1991-01-01;;;',
            ]),
            $this->membresias([
                '2;2222222222;MENSUALIDAD;700;2026-08-31 20:54:00;2026-08-31;2026-09-29;2026-09-29;No;Si;80000;80000;$ 80.000 (Efectivo)',
            ]),
        );

        // En PostgreSQL, sin un punto de guardado por fila, el choque abortaría
        // la transacción y NADA de lo que viene después llegaría a escribirse.
        $this->assertNotNull(User::where('document', '2222222222')->first());
        $this->assertNotNull(Member::where('document_number', '2222222222')->first());
        $this->assertNotNull(Payment::where('reference', 'MIGR-700')->first());
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $this->importar(
            $this->clientes(['1;1119211883;Yureini Parra;+57 3204466993;;;1995-04-22;;;']),
            $this->membresias(['1;1119211883;MENSUALIDAD;9927;2026-08-31 20:54:00;2026-08-31;2026-09-29;2026-09-29;No;Si;80000;80000;$ 80.000 (Efectivo)']),
            ['--dry-run' => true],
        );

        $this->assertSame(0, User::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_falla_si_al_export_le_falta_una_columna(): void
    {
        $ruta = $this->dir.'/clientes.csv';
        File::put($ruta, "ID del cliente;Nombre completo del cliente\n1;Alguien\n");

        // Importar «lo que se pueda» dejaría media base cargada a medias sin
        // que nadie se entere: mejor parar y decir qué falta.
        $this->artisan('iron:import-legacy-crm', [
            '--clientes' => $ruta,
            '--membresias' => $this->membresias([]),
        ])->assertFailed();
    }

    public function test_todos_los_planes_del_export_existen_en_el_catalogo(): void
    {
        // Un plan sin fila en `plans` deja al socio con TODOS los módulos
        // bloqueados aunque su membresía esté vigente.
        $delExport = [
            'MENSUALIDAD', 'TRIMESTRE', 'TOTAL ACCESS XMES', 'VALERA 2', 'PROMO X4', 'VALERA',
            'SEMANA', 'Comisión de personalizados', 'ANUALIDAD', 'TOTAL ACCESS ESPECIAL',
            'SEMESTRE', 'Entrada 1 dia', 'INGRESO EMPLEADOS', 'MERRY CRHYSTMAS',
            'EVOLUCION IRONBODY X20', 'PLAN FAMILIAR', 'ACTIVACIÓN FUNCIONAL X15',
        ];

        $catalogo = Plan::pluck('name')->all();
        $faltan = [];
        foreach ($delExport as $legacy) {
            $resuelto = LegacyPlanMap::resolve($legacy);
            if (! in_array($resuelto, $catalogo, true)) {
                $faltan[] = "{$legacy} → {$resuelto}";
            }
        }

        $this->assertSame([], $faltan, 'planes del export sin fila en `plans`');
    }

    // ── Productos / inventario ──────────────────────────────────────────────

    private const CAB_PRODUCTOS = 'ID del producto;Stock Ilimitado;Estado;Nombre del producto;Proveedor(es);'
        .'Descripción del producto;Stock actual del producto;Úlitmo precio de compra del producto;'
        .'Precio de compra promedio del producto;Precio de venta sin IVA del producto;'
        .'IVA del producto;Precio de venta con IVA del producto';

    private function productos(array $filas): string
    {
        $ruta = $this->dir.'/productos.csv';
        File::put($ruta, "\xEF\xBB\xBF".self::CAB_PRODUCTOS."\n".implode("\n", $filas)."\n");

        return $ruta;
    }

    public function test_importa_productos_con_su_carga_inicial_trazada(): void
    {
        // Quien mueve existencias es personal del CRM (`admins`), no un socio de
        // la app: el movimiento tiene que quedar atribuido a una cuenta real.
        $admin = Admin::create([
            'name' => 'Recepción Uno', 'email' => 'recepcion@ironbody.test',
            'password' => 'secret', 'role' => Admin::ROLE_RECEPCION,
        ]);
        $jefe = Admin::create([
            'name' => 'Jefa Total', 'email' => 'jefa@ironbody.test',
            'password' => 'secret', 'role' => Admin::ROLE_SUPER_ADMIN,
        ]);

        $this->artisan('iron:import-legacy-products', [
            '--archivo' => $this->productos([
                '43;No;Activo;AGUA;ESTANCO JOSE LOZANO;AGUA CRISTAL;329 Unds;1583;1583;3000;0%;3000',
            ]),
        ])->assertSuccessful();

        $producto = Product::where('sku', 'LEG-43')->firstOrFail();
        $this->assertSame('AGUA', $producto->name);
        $this->assertSame('3000.00', $producto->sale_price);
        $this->assertSame('1583.00', $producto->cost_price);
        $this->assertSame(329, $producto->stock);
        // La cafetería no se publica en la tienda de la app por importar.
        $this->assertFalse($producto->visible_in_app);

        // El stock no es un número suelto: nace con su movimiento.
        $movimiento = $producto->inventoryMovements()->sole();
        $this->assertSame(329, $movimiento->quantity);
        $this->assertSame(0, $movimiento->stock_before);
        $this->assertSame(329, $movimiento->stock_after);
        $this->assertSame('initial_stock', $movimiento->origin->value);

        // Atribuido a la cuenta con más mando, no a la primera que exista.
        $this->assertSame($jefe->id, $movimiento->admin_id);
        $this->assertSame('Jefa Total', $movimiento->user_name);
        $this->assertNotSame($admin->id, $movimiento->admin_id);
    }

    public function test_los_productos_entran_aunque_no_haya_ningun_administrador(): void
    {
        // Un gimnasio recién montado puede no tener cuentas de CRM todavía. El
        // catálogo no se queda fuera por eso: el movimiento admite no tener autor.
        $this->assertSame(0, Admin::count());

        $this->artisan('iron:import-legacy-products', [
            '--archivo' => $this->productos([
                '43;No;Activo;AGUA;ESTANCO JOSE LOZANO;AGUA CRISTAL;329 Unds;1583;1583;3000;0%;3000',
            ]),
        ])->assertSuccessful();

        $producto = Product::where('sku', 'LEG-43')->firstOrFail();
        $this->assertSame(329, $producto->stock);
        $this->assertNull($producto->inventoryMovements()->sole()->admin_id);
    }

    public function test_reimportar_productos_no_vuelve_a_cargar_existencias(): void
    {
        $archivo = $this->productos([
            '43;No;Activo;AGUA;ESTANCO JOSE LOZANO;AGUA CRISTAL;329 Unds;1583;1583;3000;0%;3500',
        ]);

        $this->artisan('iron:import-legacy-products', ['--archivo' => $archivo])->assertSuccessful();
        $this->artisan('iron:import-legacy-products', ['--archivo' => $archivo])->assertSuccessful();

        $producto = Product::where('sku', 'LEG-43')->firstOrFail();
        // Repetir la carga inicial doblaría el stock sin que entrara mercancía.
        $this->assertSame(329, $producto->stock);
        $this->assertSame(1, $producto->inventoryMovements()->count());
        $this->assertSame(1, Product::where('sku', 'LEG-43')->count());
    }

    public function test_el_resumen_cuenta_lo_que_de_verdad_entro(): void
    {
        // El resumen es lo único que ve quien corre esto en el servidor. Si
        // dijera «0 nuevos» con el catálogo ya cargado, lo natural sería volver
        // a lanzarlo — y esa segunda pasada es la que dobla las existencias.
        $this->artisan('iron:import-legacy-products', [
            '--archivo' => $this->productos([
                '43;No;Activo;AGUA;ESTANCO;AGUA CRISTAL;329 Unds;1583;1583;3000;0%;3000',
                '44;No;Activo;GATORADE;ESTANCO;GATORADE;100 Unds;2916;2916;5000;0%;5000',
                '41;No;Activo;AMINOX;MONO;AMINOACIDO;0 Unds;0;0;120000;0%;120000',
            ]),
        ])
            ->expectsOutputToContain('nuevos')
            ->assertSuccessful();

        $this->assertSame(3, Product::where('sku', 'like', 'LEG-%')->count());
        // Dos con existencias; el de stock cero no genera movimiento.
        $this->assertSame(429, (int) Product::where('sku', 'like', 'LEG-%')->sum('stock'));
    }

    public function test_no_revive_un_producto_retirado_del_catalogo(): void
    {
        $archivo = $this->productos([
            '43;No;Activo;AGUA;ESTANCO JOSE LOZANO;AGUA CRISTAL;329 Unds;1583;1583;3000;0%;3000',
        ]);

        $this->artisan('iron:import-legacy-products', ['--archivo' => $archivo])->assertSuccessful();
        Product::where('sku', 'LEG-43')->firstOrFail()->delete();

        $this->artisan('iron:import-legacy-products', ['--archivo' => $archivo])->assertSuccessful();

        // Retirarlo fue una decisión; deshacerla en silencio la anularía.
        $this->assertNull(Product::where('sku', 'LEG-43')->first());
        $this->assertNotNull(Product::withTrashed()->where('sku', 'LEG-43')->first());
    }

    public function test_no_pisa_la_decision_local_de_desactivar_un_producto(): void
    {
        $archivo = $this->productos([
            '43;No;Activo;AGUA;ESTANCO JOSE LOZANO;AGUA CRISTAL;329 Unds;1583;1583;3000;0%;3500',
        ]);

        $this->artisan('iron:import-legacy-products', ['--archivo' => $archivo])->assertSuccessful();
        Product::where('sku', 'LEG-43')->firstOrFail()->update(['active' => false, 'visible_in_app' => true]);

        $this->artisan('iron:import-legacy-products', ['--archivo' => $archivo])->assertSuccessful();

        $producto = Product::where('sku', 'LEG-43')->firstOrFail();
        $this->assertFalse($producto->active);
        $this->assertTrue($producto->visible_in_app);
        // El precio sí se refresca: eso lo mantiene al día el sistema anterior.
        $this->assertSame('3500.00', $producto->sale_price);
    }

    public function test_el_producto_sin_existencias_no_genera_movimiento(): void
    {
        $this->artisan('iron:import-legacy-products', [
            '--archivo' => $this->productos([
                '41;No;Activo;AMINOX;MONO PROVEEDOR;AMINOACIDO;0 Unds;0;0;120000;0%;120000',
            ]),
        ])->assertSuccessful();

        $producto = Product::where('sku', 'LEG-41')->firstOrFail();
        $this->assertSame(0, $producto->stock);
        $this->assertSame(0, $producto->inventoryMovements()->count());
        // Sin precio de compra en el export, el costo queda sin inventar.
        $this->assertNull($producto->cost_price);
    }
}
