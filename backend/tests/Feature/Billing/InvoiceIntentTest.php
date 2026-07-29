<?php

namespace Tests\Feature\Billing;

use App\Models\ElectronicInvoice;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\User;
use App\Services\Billing\InvoiceEmail;
use App\Services\Billing\PaymentOriginInspector;
use App\Services\Payments\PaymentMembershipActivator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La INTENCIÓN de facturar vive en el hecho económico.
 *
 * Antes vivía sólo en `payment_transactions.metadata`, y eso tenía una
 * consecuencia que nadie había medido: un pago en efectivo o una venta de
 * mostrador NO CREAN transacción, así que `wants_invoice` era false pasara lo
 * que pasara. Ninguna venta de caja podía facturarse, aunque el cliente la
 * pidiera. En producción el resultado fue que de 488 pagos cobrados, CERO
 * pasaban la barrera de emisión.
 *
 * Estas pruebas fijan el contrato nuevo: la solicitud se guarda en `payments` /
 * `product_sales`, nunca se activa por defecto, y la compatibilidad con los
 * pagos de Wompi anteriores se conserva.
 */
class InvoiceIntentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        config([
            'billing.enabled' => false, // ninguna prueba de aquí llama a Factus
            'billing.env' => 'production',
            'billing.base_url' => 'https://api.factus.com.co',
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
        ]);
    }

    // ── Utilidades ────────────────────────────────────────────────────────

    private function payment(array $attrs = []): Payment
    {
        // Correo único por usuario: `users.email` es único y varias pruebas
        // crean más de un pago.
        $user = User::factory()->create(['email' => 'cliente.'.Str::random(8).'@correo.com']);

        return Payment::create(array_merge([
            'user_id' => $user->id,
            'amount' => '80000.00',
            'status' => 'paid',
            'method' => 'efectivo',
        ], $attrs));
    }

    private function sale(array $attrs = []): ProductSale
    {
        return ProductSale::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'code' => 'V-'.Str::random(6),
            'channel' => 'pos',
            'status' => 'paid',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal' => '80000.00',
            'discount' => '0.00',
            'total' => '80000.00',
        ], $attrs));
    }

    /**
     * Una sola solicitud por origen: la tabla tiene un índice único
     * (source_type, source_id, type) que es justo la garantía de no duplicar
     * facturas. Se reutiliza para poder inspeccionar el mismo origen varias
     * veces dentro de una prueba.
     */
    private function invoiceFor(Payment|ProductSale $source): ElectronicInvoice
    {
        return ElectronicInvoice::firstOrCreate(
            [
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->id,
                'type' => 'invoice',
            ],
            [
                'uuid' => (string) Str::uuid(),
                'status' => 'pending',
                'currency' => 'COP',
                'subtotal' => '80000.00',
                'discount' => '0.00',
                'tax_total' => '0.00',
                'total' => '80000.00',
            ],
        );
    }

    private function inspect(Payment|ProductSale $source): array
    {
        return app(PaymentOriginInspector::class)->inspect($this->invoiceFor($source));
    }

    // ── 1-4. La solicitud es explícita y nunca se infiere ─────────────────

    public function test_solicitud_marcada_guarda_invoice_requested_true(): void
    {
        $p = $this->payment();
        $p->marcarFacturaSolicitada('cliente.real@correo.com');

        $p->refresh();
        $this->assertTrue($p->invoice_requested);
        $this->assertSame('cliente.real@correo.com', $p->invoice_email);
        $this->assertNotNull($p->invoice_requested_at);
    }

    public function test_pago_nuevo_nace_sin_solicitud(): void
    {
        // Sin llamar a nada: el default de la columna manda.
        $this->assertFalse($this->payment()->fresh()->invoice_requested);
        $this->assertNull($this->payment()->fresh()->invoice_requested_at);
    }

    public function test_venta_nueva_nace_sin_solicitud(): void
    {
        $this->assertFalse($this->sale()->fresh()->invoice_requested);
    }

    public function test_la_solicitud_no_se_infiere_de_otros_campos(): void
    {
        // Un pago cobrado, con correo real, plan y referencia de pasarela NO
        // implica que el cliente quiera factura. Sólo la marca explícita cuenta.
        $plan = Plan::create(['name' => 'Mensual QA', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $p = $this->payment([
            'plan_id' => $plan->id,
            'reference' => 'IRON-20260729-ABCDEF-1',
            'method' => 'card',
            'paid_at' => now(),
        ]);

        $this->assertFalse($p->fresh()->invoice_requested);
        $this->assertFalse($this->inspect($p)['wants_invoice']);
    }

    // ── 5-6. Wompi: ambiente y copia al hecho económico ───────────────────

    public function test_wompi_en_produccion_guarda_environment_production(): void
    {
        config(['wompi.env' => 'production']);

        $tx = PaymentTransaction::create([
            'reference' => 'IRON-QA-ENV-1',
            'idempotency_key' => (string) Str::uuid(),
            'amount' => '80000.00', 'currency' => 'COP',
            'status' => 'approved', 'provider' => 'wompi',
            'environment' => config('wompi.env'),
        ]);

        $this->assertSame('production', $tx->fresh()->environment);
    }

    public function test_wompi_aprobado_copia_la_solicitud_al_pago(): void
    {
        $p = $this->payment(['reference' => 'IRON-QA-COPY-1', 'method' => 'card']);
        $tx = PaymentTransaction::create([
            'reference' => 'IRON-QA-COPY-1',
            'idempotency_key' => (string) Str::uuid(),
            'amount' => '80000.00', 'currency' => 'COP',
            'status' => 'approved', 'provider' => 'wompi',
            'environment' => 'production',
            'metadata' => ['wants_invoice' => true, 'invoice_email' => 'pide.factura@correo.com'],
        ]);

        $this->invocarCopia($p, $tx);

        $p->refresh();
        $this->assertTrue($p->invoice_requested);
        $this->assertSame('pide.factura@correo.com', $p->invoice_email);
        // La metadata original se conserva por compatibilidad.
        $this->assertTrue((bool) $tx->fresh()->metadata['wants_invoice']);
    }

    public function test_callback_repetido_no_crea_una_segunda_solicitud(): void
    {
        $p = $this->payment(['reference' => 'IRON-QA-DUP-1', 'method' => 'card']);
        $tx = PaymentTransaction::create([
            'reference' => 'IRON-QA-DUP-1',
            'idempotency_key' => (string) Str::uuid(),
            'amount' => '80000.00', 'currency' => 'COP',
            'status' => 'approved', 'provider' => 'wompi',
            'environment' => 'production',
            'metadata' => ['wants_invoice' => true, 'invoice_email' => 'dup@correo.com'],
        ]);

        $primera = $this->invocarCopia($p, $tx);
        $fecha = $p->fresh()->invoice_requested_at;

        // Segundo y tercer webhook del mismo pago.
        $this->travel(5)->minutes();
        $this->invocarCopia($p->fresh(), $tx);
        $this->invocarCopia($p->fresh(), $tx);

        $this->assertTrue($primera);
        // La fecha de la PRIMERA solicitud no se mueve: es la que justifica la
        // emisión ante una revisión.
        $this->assertEquals(
            $fecha->timestamp,
            $p->fresh()->invoice_requested_at->timestamp,
            'un callback repetido no debe mover invoice_requested_at',
        );
    }

    /** Ejercita el copiado real del activador (método privado, por diseño). */
    private function invocarCopia(Payment $p, PaymentTransaction $tx): bool
    {
        $m = new \ReflectionMethod(PaymentMembershipActivator::class, 'persistInvoiceRequest');
        $m->setAccessible(true);

        return (bool) $m->invoke(null, $p, $tx);
    }

    // ── 8-9. Pagos manuales sin transacción de pasarela ───────────────────

    public function test_pago_manual_con_solicitud_pasa_la_inspeccion(): void
    {
        // El caso que era IMPOSIBLE antes: efectivo, sin transacción.
        $p = $this->payment(['method' => 'efectivo', 'reference' => null]);
        $p->marcarFacturaSolicitada('mostrador@correo.com');

        $o = $this->inspect($p->fresh());

        $this->assertTrue($o['wants_invoice'], 'un pago en efectivo debe poder facturarse');
        $this->assertTrue($o['has_verifiable_reference']);
        $this->assertFalse($o['is_sandbox']);
        $this->assertSame('paid', $o['payment_status']);
    }

    public function test_pago_manual_sin_solicitud_es_rechazado(): void
    {
        $p = $this->payment(['method' => 'efectivo', 'reference' => null]);

        $this->assertFalse($this->inspect($p)['wants_invoice']);
    }

    // ── 10-11. Ventas de producto ─────────────────────────────────────────

    public function test_venta_de_producto_con_solicitud_pasa(): void
    {
        $s = $this->sale();
        $s->marcarFacturaSolicitada('tienda@correo.com');

        $o = $this->inspect($s->fresh());

        $this->assertTrue($o['wants_invoice']);
        $this->assertTrue($o['has_verifiable_reference']);
        $this->assertSame('paid', $o['payment_status']);
    }

    public function test_venta_entregada_pero_no_cobrada_es_rechazada(): void
    {
        // El defecto: `statusOf()` leía `status` (ciclo de entrega) creyendo que
        // era el estado del cobro. Una venta ENTREGADA y NO PAGADA se declaraba
        // «paid» y habría llegado a la DIAN sin que el dinero existiera.
        $s = $this->sale(['status' => 'delivered', 'payment_status' => 'pending']);
        $s->marcarFacturaSolicitada('tienda@correo.com');

        $this->assertSame('pending', $this->inspect($s->fresh())['payment_status']);
    }

    public function test_venta_pagada_y_entregada_puede_facturarse(): void
    {
        $s = $this->sale(['status' => 'delivered', 'payment_status' => 'paid']);
        $s->marcarFacturaSolicitada('tienda@correo.com');

        $this->assertSame('paid', $this->inspect($s->fresh())['payment_status']);
        $this->assertTrue($this->inspect($s->fresh())['wants_invoice']);
    }

    // ── 12-13. Correos sintéticos ─────────────────────────────────────────

    public function test_ironbody_local_es_rechazado(): void
    {
        $this->assertFalse(InvoiceEmail::esEntregable('socio-1033751057@ironbody.local'));
        $this->assertTrue(InvoiceEmail::esSintetico('socio-1033751057@ironbody.local'));
        $this->assertNull(InvoiceEmail::normalizar('socio-1033751057@ironbody.local'));
    }

    /** @dataProvider dominiosReservados */
    public function test_dominios_reservados_son_rechazados(string $email): void
    {
        $this->assertFalse(InvoiceEmail::esEntregable($email), "{$email} no debe aceptarse");
    }

    public static function dominiosReservados(): array
    {
        return [
            ['cliente@algo.local'],
            ['cliente@algo.invalid'],
            ['cliente@algo.test'],
            ['cliente@algo.example'],
            ['cliente@localhost'],
            ['socio-999@ironbody.local'],
        ];
    }

    public function test_un_correo_real_si_se_acepta(): void
    {
        foreach (['cliente@gmail.com', 'facturacion@ironbodyneiva.cloud', 'a.b+c@dominio.com.co'] as $ok) {
            $this->assertTrue(InvoiceEmail::esEntregable($ok), "{$ok} debe aceptarse");
        }
    }

    public function test_no_se_sustituye_en_silencio_un_correo_inservible(): void
    {
        $p = $this->payment();
        $p->marcarFacturaSolicitada('socio-1@ironbody.local');

        // Se registra la solicitud pero SIN correo: no se inventa uno.
        $this->assertTrue($p->fresh()->invoice_requested);
        $this->assertNull($p->fresh()->invoice_email);
    }

    public function test_primero_entregable_salta_los_sinteticos(): void
    {
        $this->assertSame(
            'real@correo.com',
            InvoiceEmail::primeroEntregable('socio-1@ironbody.local', null, 'real@correo.com'),
        );
        $this->assertNull(InvoiceEmail::primeroEntregable('a@b.local', 'c@d.invalid'));
    }

    // ── 14-15. Histórico intacto ──────────────────────────────────────────

    public function test_referencia_migrada_no_fabrica_verificabilidad(): void
    {
        // `MIGR-*` imita una referencia de pasarela sin serlo: no hay
        // transacción que confirmar y aceptarla sería inventar trazabilidad.
        $p = $this->payment(['reference' => 'MIGR-8637', 'method' => 'efectivo']);
        $p->marcarFacturaSolicitada('real@correo.com');

        $this->assertFalse(
            $this->inspect($p->fresh())['has_verifiable_reference'],
            'MIGR-* no es una referencia de pasarela verificable',
        );
    }

    public function test_los_pagos_migrados_conservan_invoice_requested_false(): void
    {
        $p = $this->payment(['reference' => 'MIGR-1234']);

        // La migración es aditiva con default false: nada los marca solos.
        $this->assertFalse($p->fresh()->invoice_requested);
        $this->assertNull($p->fresh()->invoice_email);
        $this->assertNull($p->fresh()->invoice_requested_at);
    }

    public function test_environment_null_no_se_considera_produccion(): void
    {
        // Las 21 transacciones huérfanas de ePayco tienen environment NULL. No
        // son sandbox (no se bloquean por eso), pero tampoco se pueden declarar
        // producción: lo que decide es la solicitud expresa.
        $p = $this->payment(['reference' => 'IRON-20260612-NCV72N-23119', 'method' => 'card']);
        PaymentTransaction::create([
            'reference' => 'IRON-20260612-NCV72N-23119',
            'idempotency_key' => (string) Str::uuid(),
            'amount' => '80000.00', 'currency' => 'COP',
            'status' => 'approved', 'provider' => 'epayco',
            'environment' => null,
        ]);

        $o = $this->inspect($p);

        $this->assertNull($o['environment']);
        $this->assertFalse($o['is_sandbox'], 'NULL no es sandbox');
        $this->assertFalse($o['wants_invoice'], 'sin solicitud expresa no se factura');
    }

    // ── 16-17. Idempotencia de la solicitud ───────────────────────────────

    public function test_marcar_dos_veces_no_duplica_la_solicitud(): void
    {
        $p = $this->payment();

        $primera = $p->marcarFacturaSolicitada('uno@correo.com');
        $fecha = $p->fresh()->invoice_requested_at;

        $this->travel(10)->minutes();
        $segunda = $p->fresh()->marcarFacturaSolicitada('dos@correo.com');

        $this->assertTrue($primera);
        $this->assertFalse($segunda, 'la segunda marca no debe contar como nueva solicitud');
        $this->assertEquals($fecha->timestamp, $p->fresh()->invoice_requested_at->timestamp);
    }

    public function test_venta_marcada_dos_veces_no_duplica_la_solicitud(): void
    {
        $s = $this->sale();

        $this->assertTrue($s->marcarFacturaSolicitada('uno@correo.com'));
        $fecha = $s->fresh()->invoice_requested_at;

        $this->travel(10)->minutes();
        $this->assertFalse($s->fresh()->marcarFacturaSolicitada('dos@correo.com'));
        $this->assertEquals($fecha->timestamp, $s->fresh()->invoice_requested_at->timestamp);
    }

    public function test_una_solicitud_ya_marcada_completa_el_correo_si_faltaba(): void
    {
        $p = $this->payment();
        // Solicitud sin correo entregable: queda registrada, sin email.
        $p->marcarFacturaSolicitada('nope@algo.local');
        $this->assertNull($p->fresh()->invoice_email);

        // Un segundo intento con correo válido SÍ completa el dato que faltaba,
        // sin contar como nueva solicitud ni mover la fecha.
        $fecha = $p->fresh()->invoice_requested_at;
        $this->assertFalse($p->fresh()->marcarFacturaSolicitada('bueno@correo.com'));
        $this->assertSame('bueno@correo.com', $p->fresh()->invoice_email);
        $this->assertEquals($fecha->timestamp, $p->fresh()->invoice_requested_at->timestamp);
    }

    // ── 23. Los históricos no se tocan ────────────────────────────────────

    public function test_marcar_una_solicitud_no_altera_importes_ni_estado(): void
    {
        $plan = Plan::create(['name' => 'Mensual QA2', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $p = $this->payment(['plan_id' => $plan->id, 'amount' => '80000.00', 'paid_at' => now()]);

        // Se comparan valores escalares: `assertSame` sobre objetos Carbon
        // compararía identidad de instancia, no la fecha.
        $instantanea = fn (Payment $p) => [
            'amount' => (string) $p->amount,
            'status' => $p->status,
            'method' => $p->method,
            'reference' => $p->reference,
            'plan_id' => $p->plan_id,
            'paid_at' => $p->paid_at?->toIso8601String(),
        ];
        $antes = $instantanea($p);

        $p->marcarFacturaSolicitada('real@correo.com');

        $this->assertSame($antes, $instantanea($p->fresh()));
    }

    public function test_un_miembro_con_correo_sintetico_no_recibe_factura_por_correo(): void
    {
        $user = User::factory()->create(['email' => 'socio-1033751057@ironbody.local']);
        $member = Member::create([
            'user_id' => $user->id,
            'full_name' => 'Socio Sin Correo',
            'document_number' => '1033751057',
            'phone' => '3000000000',
            'status' => Member::STATUS_ACTIVE,
        ]);
        $p = Payment::create([
            'user_id' => $user->id, 'member_id' => $member->id,
            'amount' => '80000.00', 'status' => 'paid', 'method' => 'efectivo',
        ]);

        $perfil = app(\App\Services\Billing\FiscalProfileResolver::class)->resolveForPayment($p);

        $this->assertNull($perfil['email'], 'un correo sintético no debe llegar al comprobante');
        $this->assertFalse(\App\Services\Billing\InvoiceDtoBuilder::hasValidEmail($user->email));
    }

    /**
     * El caso completo de la decisión tributaria, desde el pago hasta el
     * payload: una membresía de $80.000 factura base 80.000, IVA 0 y total
     * 80.000, con el tributo declarado como EXENTO (01 al 0 %).
     */
    public function test_una_membresia_de_80000_factura_80000_sin_iva(): void
    {
        $plan = Plan::create([
            'name' => 'Mensual Exento', 'price' => 80000,
            'duration_days' => 30, 'active' => true,
        ]);
        $p = $this->payment(['plan_id' => $plan->id, 'amount' => '80000.00', 'paid_at' => now()]);
        $p->marcarFacturaSolicitada('cliente.real@correo.com');

        $construido = app(\App\Services\Billing\InvoiceDtoBuilder::class)->forPayment(
            $p->fresh(),
            app(\App\Services\Billing\FiscalProfileResolver::class)->resolveForPayment($p->fresh()),
        );

        $snap = $construido['snapshot'];
        $this->assertSame('80000.00', (string) $snap['subtotal']);
        $this->assertSame('0.00', (string) $snap['tax_total']);
        $this->assertSame('80000.00', (string) $snap['total']);

        // Y el tributo que sale hacia el proveedor: exento, nunca excluido, y
        // sin rastro del 19 % ni del valor que produciría extraerlo.
        $m = new \ReflectionMethod(\App\Services\Billing\Factus\FactusClient::class, 'normalizeTaxes');
        $m->setAccessible(true);
        $final = $m->invoke(app(\App\Services\Billing\Factus\FactusClient::class), $construido['payload']);

        $this->assertSame([['code' => '01', 'rate' => '0.00']], $final['items'][0]['taxes']);
        $this->assertSame('80000.00', $final['items'][0]['price']);
        $json = json_encode($final);
        $this->assertStringNotContainsString('is_excluded', $json);
        $this->assertStringNotContainsString('19.00', $json);
        $this->assertStringNotContainsString('67226.89', $json);
    }

    public function test_producto_activo_conserva_su_configuracion(): void
    {
        // Guarda de no-regresión: la migración de solicitud es aditiva y no toca
        // el catálogo ni su tratamiento tributario.
        $product = Product::create([
            'name' => 'Proteína QA', 'sale_price' => 119000, 'active' => true,
        ]);

        $this->assertTrue((bool) $product->fresh()->active);
        $this->assertSame('119000.00', (string) $product->fresh()->sale_price);
    }
}
