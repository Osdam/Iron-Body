<?php

namespace Tests\Feature\Billing;

use App\Enums\CashShiftType;
use App\Enums\InvoiceStatus;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\Admin;
use App\Models\ElectronicInvoice;
use App\Models\InvoiceBundle;
use App\Models\InvoiceBundleItem;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductSaleItem;
use App\Models\TaxRate;
use App\Models\Trainer;
use App\Models\TrainerCommissionAgreement;
use App\Models\TrainerCommissionCharge;
use App\Models\User;
use App\Services\Billing\InvoicingService;
use App\Services\Commissions\TrainerCommissions;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Facturación electrónica → Emitir manual MULTICONCEPTO.
 *
 * Factus no se llama nunca de verdad: los envíos se simulan con Http::fake y
 * la cola con Queue::fake. Lo que se comprueba es lo que decide el sistema:
 * qué se puede consolidar, qué sale en el comprobante y que una operación no
 * respalde dos facturas.
 */
class ManualInvoiceBundleTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.enabled' => true,
            'billing.env' => 'sandbox',
            'billing.auto_emit.memberships' => false,
            'billing.auto_emit.product_sales' => false,
            'billing.credentials' => ['username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's'],
            'billing.consumer_final' => ['document_type' => '3', 'document_number' => '222222222222', 'name' => 'CONSUMIDOR FINAL'],
            // La barrera previa al envío, ENCENDIDA como en producción (la suite
            // la apaga en su línea base): la factura multiconcepto tiene que
            // superarla, y una individual de una operación reservada no.
            'tax_policy.emission_guard_enabled' => true,
            'tax_policy.issuer_vat_responsibility' => '49',
            'tax_policy.issuer_is_vat_responsible' => false,
            'tax_policy.vat_collection_enabled' => false,
        ]);

        $this->admin = Admin::create([
            'name' => 'Ana Facturación', 'email' => 'facturacion-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->h = $this->actingAsAdmin($this->admin);
    }

    // ── Montaje ───────────────────────────────────────────────────────────

    private function member(string $name = 'Laura Daniela Borrero'): Member
    {
        $user = User::create([
            'name' => $name, 'email' => Str::slug($name).'-'.uniqid().'@correo.com',
            'password' => 'secret', 'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => $name, 'email' => $user->email,
            'document_number' => (string) random_int(10_000_000, 99_999_999), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function planPayment(Member $m, array $attrs = []): Payment
    {
        $plan = Plan::firstOrCreate(['name' => 'Plan mensual'], ['price' => 80000, 'duration_days' => 30, 'benefits' => '']);

        // Como el pago #19905: atado al usuario, sin member_id.
        return Payment::create(array_merge([
            'user_id' => $m->user_id, 'plan_id' => $plan->id, 'amount' => 80000,
            'method' => 'transfer', 'reference' => 'REC-'.random_int(1000, 9999),
            'status' => 'paid', 'paid_at' => now(),
        ], $attrs));
    }

    /** @param list<array{0: string, 1: int, 2: float}> $items  nombre, cantidad, unitario */
    private function sale(Member $m, array $items, string $method = 'cash', array $attrs = []): ProductSale
    {
        $total = array_sum(array_map(fn ($i) => $i[1] * $i[2], $items));
        $sale = ProductSale::create(array_merge([
            'channel' => 'pos', 'status' => 'paid', 'member_id' => $m->id, 'customer_name' => $m->full_name,
            'payment_method' => $method, 'payment_status' => 'paid', 'subtotal' => $total, 'discount' => 0,
            'total' => $total, 'paid_at' => now(),
        ], $attrs));
        foreach ($items as [$name, $qty, $unit]) {
            $product = Product::create(['name' => $name, 'sale_price' => $unit, 'cost_price' => 1, 'stock' => 10, 'active' => true]);
            ProductSaleItem::create([
                'product_sale_id' => $sale->id, 'product_id' => $product->id, 'name' => $name,
                'unit_price' => $unit, 'quantity' => $qty, 'subtotal' => $qty * $unit,
            ]);
        }

        return $sale;
    }

    /** Piso de entrenador cobrado por el servicio real (cuenta + abono en caja). */
    private function piso(Member $m, string $payer = 'member', ?string $method = 'transfer', float $amount = 50000): TrainerCommissionCharge
    {
        $trainer = Trainer::create([
            'full_name' => 'Juan Alejandro Horta', 'document' => (string) random_int(10_000_000, 99_999_999),
            'email' => 'juan-'.uniqid().'@ironbody.test', 'status' => 'active',
        ]);
        $agreement = TrainerCommissionAgreement::create([
            'trainer_id' => $trainer->id, 'member_id' => $m->id, 'amount' => $amount,
            'cycle_days' => 30, 'payer' => $payer, 'blocks_app' => false, 'active' => true,
        ]);
        if ($method !== null) {
            $this->openCashShift($this->admin, CashShiftType::GYM);
        }
        $mes = CarbonImmutable::parse('2026-10-01');
        app(TrainerCommissions::class)->charge($agreement, $mes, $method, $this->admin);

        return app(TrainerCommissions::class)->chargeFor($agreement, $mes);
    }

    private function preview(Member $m, array $keys, ?bool $finalConsumer = null)
    {
        return $this->postJson('/api/admin/electronic-invoices/bundles/preview', [
            'member_id' => $m->id, 'items' => $keys, 'final_consumer' => $finalConsumer,
        ], $this->h);
    }

    private function emit(Member $m, array $keys, ?string $hash = null, ?string $key = null, ?array $headers = null)
    {
        $hash ??= $this->preview($m, $keys)->assertOk()->json('data.preview_hash');

        return $this->postJson('/api/admin/electronic-invoices/bundles', [
            'member_id' => $m->id, 'items' => $keys, 'idempotency_key' => $key ?? (string) Str::uuid(),
            'preview_hash' => $hash, 'confirm' => true,
        ], $headers ?? $this->h);
    }

    // ── Casos ─────────────────────────────────────────────────────────────

    public function test_plan_unico_se_previsualiza_sin_escribir_y_se_emite_con_confirmacion(): void
    {
        Queue::fake();
        Http::fake();
        $m = $this->member();
        $p = $this->planPayment($m);

        $prev = $this->preview($m, ["payment:{$p->id}"])->assertOk();
        $prev->assertJsonPath('data.totals.total', '80000.00')
            ->assertJsonPath('data.payment_details.0.payment_method_code', '47');
        $this->assertSame(0, InvoiceBundle::count(), 'La vista previa no escribe nada.');
        Queue::assertNothingPushed();

        $res = $this->emit($m, ["payment:{$p->id}"])->assertCreated();
        $invoice = ElectronicInvoice::findOrFail($res->json('data.id'));
        $this->assertSame(InvoiceBundle::class, $invoice->source_type);
        $this->assertTrue($invoice->isManuallyAuthorized());
        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_laura_plan_y_piso_que_asume_la_clienta_salen_en_una_factura_de_130000(): void
    {
        Queue::fake();
        $m = $this->member();
        $p = $this->planPayment($m);
        $c = $this->piso($m);

        $data = $this->preview($m, ["payment:{$p->id}", "commission_charge:{$c->id}"])->assertOk()->json('data');

        $this->assertSame('130000.00', $data['totals']['total']);
        $this->assertSame('0.00', $data['totals']['tax_total']);
        $this->assertCount(2, $data['lines']);
        $lines = collect($data['lines'])->keyBy(fn ($l) => str_starts_with($l['code_reference'], 'PISO-') ? 'piso' : 'plan');
        $this->assertSame('Membresía Plan mensual - Iron Body', $lines['plan']['name']);
        $this->assertSame('80000.00', $lines['plan']['gross']);
        $this->assertStringContainsString('Juan Alejandro Horta', $lines['piso']['name']);
        $this->assertSame('50000.00', $lines['piso']['gross']);
        // Los dos se cobraron por transferencia (código Factus 47).
        $this->assertSame([['payment_form' => 1, 'payment_method_code' => '47', 'amount' => '130000.00']], $data['payment_details']);

        $this->emit($m, ["payment:{$p->id}", "commission_charge:{$c->id}"])->assertCreated();
        $invoice = ElectronicInvoice::where('source_type', InvoiceBundle::class)->sole();
        foreach ($invoice->payload_snapshot['items'] as $item) {
            $this->assertSame([['code' => '01', 'rate' => '0.00']], $item['taxes'], 'Emisor no responsable: exento al 0 %.');
        }
    }

    public function test_dos_pagos_del_mismo_cliente(): void
    {
        Queue::fake();
        $m = $this->member();
        $a = $this->planPayment($m);
        $b = $this->planPayment($m, ['method' => 'cash']);

        $data = $this->preview($m, ["payment:{$a->id}", "payment:{$b->id}"])->assertOk()->json('data');
        $this->assertSame('160000.00', $data['totals']['total']);
        $this->assertEqualsCanonicalizing(
            [['payment_form' => 1, 'payment_method_code' => '10', 'amount' => '80000.00'], ['payment_form' => 1, 'payment_method_code' => '47', 'amount' => '80000.00']],
            $data['payment_details'],
        );
    }

    public function test_plan_y_venta_de_cafeteria_con_varias_lineas_y_medios_distintos(): void
    {
        Queue::fake();
        $m = $this->member();
        $p = $this->planPayment($m, ['method' => 'cash']);
        $s = $this->sale($m, [['Agua', 2, 3000], ['Proteína', 1, 12000]], 'transfer');

        $data = $this->preview($m, ["payment:{$p->id}", "product_sale:{$s->id}"])->assertOk()->json('data');

        $this->assertCount(3, $data['lines']);
        $this->assertSame('98000.00', $data['totals']['total']);
        $this->assertEqualsCanonicalizing(
            [['payment_form' => 1, 'payment_method_code' => '10', 'amount' => '80000.00'], ['payment_form' => 1, 'payment_method_code' => '47', 'amount' => '18000.00']],
            $data['payment_details'],
        );
    }

    public function test_pago_mixto_declara_cada_medio_y_el_datafono_como_no_definido(): void
    {
        Queue::fake();
        $m = $this->member();
        $p = $this->planPayment($m, ['method' => 'mixed']);
        PaymentSplit::create(['payment_id' => $p->id, 'method' => 'cash', 'amount' => 30000]);
        PaymentSplit::create(['payment_id' => $p->id, 'method' => 'datafono', 'amount' => 50000]);

        $data = $this->preview($m, ["payment:{$p->id}"])->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            [['payment_form' => 1, 'payment_method_code' => '1', 'amount' => '50000.00'], ['payment_form' => 1, 'payment_method_code' => '10', 'amount' => '30000.00']],
            $data['payment_details'],
        );
    }

    public function test_una_tarifa_del_19_en_catalogo_no_se_cobra_con_emisor_no_responsable(): void
    {
        Queue::fake();
        $m = $this->member();
        $rate = TaxRate::create(['code' => 'IVA_19', 'name' => 'IVA 19', 'rate' => 19, 'price_includes_tax' => true, 'active' => true, 'factus_tribute_id' => '01']);
        $s = $this->sale($m, [['Bebida gravada', 1, 11900]]);
        Product::where('name', 'Bebida gravada')->update(['tax_rate_id' => $rate->id]);

        $data = $this->preview($m, ["product_sale:{$s->id}"])->assertOk()->json('data');
        $this->assertSame('11900.00', $data['totals']['total']);
        $this->assertSame('0.00', $data['totals']['tax_total']);
        $this->assertSame('11900.00', $data['lines'][0]['base']);
    }

    public function test_operacion_ya_facturada_no_se_puede_consolidar(): void
    {
        Queue::fake();
        $m = $this->member();
        $p = $this->planPayment($m);
        ElectronicInvoice::create([
            'source_type' => $p->getMorphClass(), 'source_id' => $p->id, 'type' => 'invoice',
            'status' => 'validated', 'full_number' => 'IBFE50', 'cufe' => 'cufe-x', 'total' => 80000,
        ]);

        $cand = collect($this->getJson("/api/admin/electronic-invoices/bundles/members/{$m->id}/candidates", $this->h)->assertOk()->json('data'))
            ->firstWhere('key', "payment:{$p->id}");
        $this->assertFalse($cand['selectable']);
        $this->assertSame('invoiced', $cand['fiscal']['state']);
        $this->assertStringContainsString('IBFE50', $cand['blocked_reason']);

        $this->preview($m, ["payment:{$p->id}"])->assertStatus(422);
        $this->assertSame(0, InvoiceBundle::count());
    }

    public function test_pendiente_automatico_se_sustituye_y_uno_autorizado_en_cola_bloquea(): void
    {
        Queue::fake();
        $m = $this->member();
        $p = $this->planPayment($m);
        // El pendiente automático del cobro (como el #990): nunca salió.
        $auto = app(InvoicingService::class)->enqueueForPayment($p);
        $this->assertSame(InvoiceStatus::PENDING, $auto->status);

        $data = $this->preview($m, ["payment:{$p->id}"])->assertOk()->json('data');
        $this->assertSame([$auto->id], $data['supersedes']);

        $bundleInvoiceId = $this->emit($m, ["payment:{$p->id}"])->assertCreated()->json('data.id');
        $auto->refresh();
        $this->assertSame(InvoiceStatus::CANCELLED, $auto->status);
        $this->assertFalse((bool) $auto->retry_allowed);
        $this->assertStringContainsString("#{$bundleInvoiceId}", $auto->cancellation_reason);

        // Otro cobro cuya solicitud individual ya está AUTORIZADA en cola: no se toca.
        $q = $this->planPayment($m);
        app(InvoicingService::class)->manualEmit('payment', $q->id, adminId: $this->admin->id);
        $this->preview($m, ["payment:{$q->id}"])->assertStatus(422)
            ->assertJsonFragment(['ok' => false]);
        $this->assertSame(InvoiceStatus::PENDING, ElectronicInvoice::where('source_id', $q->id)->where('source_type', $q->getMorphClass())->sole()->status);
    }

    public function test_doble_clic_con_la_misma_clave_no_duplica(): void
    {
        Queue::fake();
        $m = $this->member();
        $p = $this->planPayment($m);
        $keys = ["payment:{$p->id}"];
        $hash = $this->preview($m, $keys)->json('data.preview_hash');
        $key = (string) Str::uuid();

        $a = $this->emit($m, $keys, $hash, $key)->assertCreated()->json('data.id');
        $b = $this->emit($m, $keys, $hash, $key)->assertOk()->assertJsonPath('replay', true)->json('data.id');

        $this->assertSame($a, $b);
        $this->assertSame(1, InvoiceBundle::count());
        $this->assertSame(1, ElectronicInvoice::where('source_type', InvoiceBundle::class)->count());
        Queue::assertPushed(EmitElectronicInvoiceJob::class, 1);
    }

    public function test_dos_usuarios_con_la_misma_operacion_solo_uno_la_reserva(): void
    {
        Queue::fake();
        $m = $this->member();
        $p = $this->planPayment($m);
        $keys = ["payment:{$p->id}"];
        $hash = $this->preview($m, $keys)->json('data.preview_hash');

        $otro = Admin::create(['name' => 'Otro', 'email' => 'otro-'.uniqid().'@ironbody.test', 'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $this->emit($m, $keys, $hash)->assertCreated();
        $this->emit($m, $keys, $hash, null, $this->actingAsAdmin($otro))->assertStatus(422);

        $this->assertSame(1, InvoiceBundle::count());

        // Y si dos llegaran a escribir a la vez, el índice parcial lo impide.
        $this->expectException(UniqueConstraintViolationException::class);
        InvoiceBundleItem::create([
            'invoice_bundle_id' => InvoiceBundle::first()->id, 'origin_type' => 'payment', 'origin_id' => $p->id,
            'description' => 'carrera', 'amount' => 80000, 'active' => true,
        ]);
    }

    public function test_operaciones_de_otro_cliente_se_rechazan(): void
    {
        Queue::fake();
        $laura = $this->member();
        $otra = $this->member('Otra Persona');
        $ajeno = $this->planPayment($otra);

        $this->preview($laura, ["payment:{$ajeno->id}"])->assertStatus(422)
            ->assertJsonFragment(['ok' => false]);
        $this->assertStringContainsString('no pertenece', $this->preview($laura, ["payment:{$ajeno->id}"])->json('message'));
    }

    public function test_piso_que_paga_el_entrenador_o_sin_pagar_no_se_factura_a_la_socia(): void
    {
        Queue::fake();
        $m = $this->member();
        $delEntrenador = $this->piso($m, 'trainer');
        $this->assertStringContainsString('lo paga el entrenador',
            $this->preview($m, ["commission_charge:{$delEntrenador->id}"])->assertStatus(422)->json('message'));

        $m2 = $this->member('Socia Debe');
        $sinPagar = $this->piso($m2, 'member', null); // deuda abierta, sin cobrar
        $this->assertStringContainsString('pagada por completo',
            $this->preview($m2, ["commission_charge:{$sinPagar->id}"])->assertStatus(422)->json('message'));
    }

    public function test_datos_que_cambian_tras_la_vista_previa_no_se_emiten(): void
    {
        Queue::fake();
        $m = $this->member();
        $p = $this->planPayment($m);
        $hash = $this->preview($m, ["payment:{$p->id}"])->json('data.preview_hash');

        $p->update(['amount' => 90000]);

        $this->emit($m, ["payment:{$p->id}"], $hash)->assertStatus(409);
        $this->assertSame(0, InvoiceBundle::count());
    }

    public function test_emision_exige_persona_y_permiso(): void
    {
        Queue::fake();
        $m = $this->member();
        $p = $this->planPayment($m);

        $recepcion = Admin::create(['name' => 'Recepción', 'email' => 'rec-'.uniqid().'@ironbody.test', 'password' => 'secret-password', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active']);
        $this->postJson('/api/admin/electronic-invoices/bundles/preview', ['member_id' => $m->id, 'items' => ["payment:{$p->id}"]], $this->actingAsAdmin($recepcion))
            ->assertStatus(403);

        config(['admin.api_token' => 'token-de-automatizacion-para-pruebas']);
        $this->postJson('/api/admin/electronic-invoices/bundles/preview', ['member_id' => $m->id, 'items' => ["payment:{$p->id}"]], ['Authorization' => 'Bearer token-de-automatizacion-para-pruebas'])
            ->assertStatus(403);
        $this->assertSame(0, InvoiceBundle::count());
    }

    public function test_las_lecturas_del_flujo_no_las_ve_el_token_ni_quien_no_factura(): void
    {
        $m = $this->member();
        config(['admin.api_token' => 'token-de-automatizacion-para-pruebas']);
        $token = ['Authorization' => 'Bearer token-de-automatizacion-para-pruebas'];
        $recepcion = $this->actingAsAdmin(Admin::create(['name' => 'Recepción', 'email' => 'rec2-'.uniqid().'@ironbody.test', 'password' => 'secret-password', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active']));

        foreach ([$token, $recepcion] as $h) {
            $this->getJson("/api/admin/electronic-invoices/bundles/members/{$m->id}/candidates", $h)->assertStatus(403);
            $this->getJson('/api/admin/electronic-invoices/bundles/customers?q=Laura', $h)->assertStatus(403);
        }

        // Un comodín no lista a todo el mundo.
        $this->member('Otra Persona');
        $this->assertSame([], $this->getJson('/api/admin/electronic-invoices/bundles/customers?q=%25%25%25', $this->h)->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/admin/electronic-invoices/bundles/customers?q=Laura', $this->h)->assertOk()->json('data'));
    }

    public function test_emision_exitosa_y_error_de_factus_sin_estados_inconsistentes(): void
    {
        Queue::fake();
        $m = $this->member();
        $p = $this->planPayment($m);
        $c = $this->piso($m);

        // Primer envío: el proveedor falla. Segundo: valida.
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v2/bills/validate' => Http::sequence()
                ->push(['message' => 'boom'], 500)
                ->push(['data' => ['bill' => [
                    'id' => 'F900', 'number' => 'SETP990000777', 'prefix' => 'SETP', 'cufe' => 'cufe-multi-1', 'status' => 'Validada',
                ]]], 201),
            '*download-pdf' => Http::response(['pdf_base_64' => base64_encode('%PDF demo')]),
            '*download-xml' => Http::response(['xml_base_64' => base64_encode('<Invoice/>')]),
            '*' => Http::response([], 200),
        ]);
        $id = $this->emit($m, ["payment:{$p->id}", "commission_charge:{$c->id}"])->assertCreated()->json('data.id');
        try {
            app()->call([new EmitElectronicInvoiceJob($id), 'handle']);
        } catch (\Throwable) {
            // El job relanza para que la cola reintente; el estado ya quedó escrito.
        }
        $invoice = ElectronicInvoice::findOrFail($id);
        $this->assertSame(InvoiceStatus::ERROR, $invoice->status);
        $this->assertNull($invoice->cufe);
        $this->assertSame(2, InvoiceBundleItem::where('active', true)->count());
        $this->preview($m, ["payment:{$p->id}"])->assertStatus(422); // sigue reservada

        // Reintento con el proveedor bien: validada con CUFE, una sola vez.
        app()->call([new EmitElectronicInvoiceJob($id), 'handle']);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::VALIDATED, $invoice->status);
        $this->assertSame('cufe-multi-1', $invoice->cufe);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'bills/validate')
            && count($req['items']) === 2 && $req['reference_code'] === $invoice->uuid);

        // El detalle muestra las operaciones vinculadas.
        $show = $this->getJson("/api/admin/electronic-invoices/{$id}", $this->h)->assertOk()->json();
        $this->assertCount(2, data_get($show, 'data.source.items'));
    }

    public function test_la_barrera_previa_al_envio_para_una_individual_de_una_operacion_reservada(): void
    {
        Queue::fake();
        Http::fake(['*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]), '*' => Http::response([], 200)]);
        $m = $this->member();
        $p = $this->planPayment($m);

        // Una individual ya autorizada (como si viniera de antes)…
        $individual = app(InvoicingService::class)->manualEmit('payment', $p->id, adminId: $this->admin->id);
        // …y un lote que reserva la misma operación (escrito a mano: el CRM no lo permitiría).
        $bundle = InvoiceBundle::create(['member_id' => $m->id, 'idempotency_key' => (string) Str::uuid(), 'total' => 80000, 'preview_hash' => str_repeat('a', 64)]);
        InvoiceBundleItem::create(['invoice_bundle_id' => $bundle->id, 'origin_type' => 'payment', 'origin_id' => $p->id, 'description' => 'x', 'amount' => 80000, 'active' => true]);

        try {
            app()->call([new EmitElectronicInvoiceJob($individual->id), 'handle']);
        } catch (\Throwable) {
        }

        $individual->refresh();
        $this->assertNull($individual->cufe);
        $this->assertNotSame(InvoiceStatus::VALIDATED, $individual->status);
        $this->assertStringContainsString('multiconcepto', (string) $individual->failure_reason);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'bills/validate'));
    }

    public function test_regresion_la_emision_individual_respeta_el_lote(): void
    {
        Queue::fake();
        Http::fake();
        $m = $this->member();
        $p = $this->planPayment($m);
        $otro = $this->planPayment($m);

        $this->emit($m, ["payment:{$p->id}"])->assertCreated();

        // La vía individual ya no puede facturar la operación reservada…
        $this->postJson('/api/admin/electronic-invoices/manual-emit', ['source_type' => 'payment', 'source_id' => $p->id], $this->h)
            ->assertStatus(409);
        // …pero sigue funcionando igual con cualquier otra.
        $this->postJson('/api/admin/electronic-invoices/manual-emit', ['source_type' => 'payment', 'source_id' => $otro->id], $this->h)
            ->assertSuccessful();

        // Y el cobro automático sigue creando su pendiente sin emitir.
        $nuevo = $this->planPayment($m);
        $auto = app(InvoicingService::class)->enqueueForPayment($nuevo);
        $this->assertSame(InvoiceStatus::PENDING, $auto->status);
        Http::assertNothingSent();
    }
}
