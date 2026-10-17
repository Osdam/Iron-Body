<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ReceivablePayment;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Payments\MembershipPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Operaciones de caja REALES para probar sus recibos.
 *
 * Nada se inserta a mano cuando existe el flujo: el cobro entra por
 * POST /api/payments, la venta por la caja, el abono por cuentas por cobrar. Un
 * recibo que cuadra con una fila fabricada a mano no demuestra que cuadre con la
 * que deja el mostrador. Solo los orígenes que el CRM no puede crear —pasarela,
 * automatización, migración— se insertan directamente, como los crea su código.
 */
trait BuildsCashReceipts
{
    protected Admin $cajero;

    /** @var array<string, string> */
    protected array $h;

    /** 19:30 en Neiva son las 00:30 UTC del día SIGUIENTE. Reloj en UTC. */
    protected function relojDelMostrador(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 19:30:00', 'America/Bogota')->utc());
    }

    protected function empresa(): void
    {
        config([
            'billing.company.name' => 'IRON BODY NEIVA S.A.S.',
            'billing.company.nit' => '900123456',
            'billing.company.dv' => '7',
            'billing.company.address' => 'Calle 10 # 5-20, Neiva',
            'billing.company.phone' => '3001234567',
        ]);
    }

    protected function recepcion(string $nombre = 'Ana Recepción'): Admin
    {
        return Admin::create([
            'name' => $nombre,
            'email' => 'recibos-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]);
    }

    protected function abrir(CashShiftType $tipo): CashShift
    {
        return CashShift::currentOfType($tipo) ?? app(CashShiftService::class)->open($this->cajero, $tipo);
    }

    protected function socio(string $nombre = 'Laura Gómez'): Member
    {
        $user = User::create([
            'name' => $nombre,
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'status' => 'active',
            'document' => (string) random_int(10_000_000, 99_999_999),
            'phone' => '3109998877',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => $nombre,
            'document_number' => $user->document,
            'phone' => '3109998877',
            'is_staff' => false,
        ]);
    }

    protected function plan(string $nombre = 'Premium', float $precio = 120000): Plan
    {
        return Plan::create(['name' => $nombre, 'price' => $precio, 'duration_days' => 30, 'active' => true]);
    }

    /** Cobro de membresía en el mostrador, por el endpoint real. */
    protected function cobrar(Member $socio, Plan $plan, array $extra = []): Payment
    {
        $this->abrir(CashShiftType::GYM);

        $res = $this->postJson('/api/payments', array_merge([
            'user_id' => $socio->user_id,
            'plan_id' => $plan->id,
            'amount' => (float) $plan->price,
            'method' => 'cash',
            'status' => 'paid',
        ], $extra), $this->h)->assertStatus(201);

        return Payment::findOrFail($res->json('id'));
    }

    protected function producto(string $nombre = 'Agua 600 ml', float $precio = 3000, ?TaxRate $tarifa = null): Product
    {
        $tarifa ??= TaxRate::firstOrCreate(
            ['code' => 'EXEMPT'],
            ['name' => 'Exento', 'rate' => 0, 'active' => true, 'price_includes_tax' => true],
        );

        return Product::create([
            'name' => $nombre, 'category' => 'Cafetería', 'sale_price' => $precio,
            'cost_price' => $precio / 2, 'stock' => 100, 'min_stock' => 0, 'active' => true,
            'visible_in_app' => true, 'tax_rate_id' => $tarifa->id, 'pricing_mode' => 'legacy_inclusive',
        ]);
    }

    /** Venta de mostrador por el endpoint real. */
    protected function vender(array $items, array $extra = []): ProductSale
    {
        $this->abrir(CashShiftType::PRODUCTS);

        $res = $this->postJson('/api/admin/caja/sales', array_merge([
            'items' => $items,
            'payment_method' => 'cash',
        ], $extra), $this->h)->assertStatus(201);

        return ProductSale::findOrFail($res->json('data.id'));
    }

    /** Fiar productos a un socio: entrega el producto y abre la deuda. */
    protected function fiar(Member $socio, Product $p, int $cantidad = 1): ProductSale
    {
        return $this->vender([['product_id' => $p->id, 'quantity' => $cantidad]], [
            'credit' => true,
            'payment_method' => null,
            'member_id' => $socio->id,
        ]);
    }

    /** Plan a plazos: un Payment de devengo y el primer abono en caja. */
    protected function venderAPlazos(Member $socio, Plan $plan, float $primero): array
    {
        $this->abrir(CashShiftType::GYM);

        $res = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->user_id,
            'plan_id' => $plan->id,
            'first_payment' => $primero,
            'method' => 'cash',
        ], $this->h)->assertStatus(201);

        return [
            Payment::findOrFail($res->json('payment_id')),
            ReceivablePayment::findOrFail($res->json('first_payment.id')),
            (int) $res->json('data.id'),
        ];
    }

    /** Socio con Mensual vigente, listo para mejorar a Total Access. */
    protected function socioConMensual(): array
    {
        $mensual = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $total = Plan::create(['name' => 'Total Access', 'price' => 150000, 'duration_days' => 30, 'active' => true]);
        $inicio = MembershipPeriod::today()->subDays(7);

        $user = User::create([
            'name' => 'Socio Mensual',
            'email' => 'mejora-'.uniqid().'@ironbody.test',
            'password' => 'secret',
            'status' => 'active',
            'plan' => $mensual->name,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $inicio->addDays(30)->toDateString(),
        ]);

        return [$user, $mensual, $total];
    }

    protected function emitir(string $tipo, int|array $ids, ?array $h = null): TestResponse
    {
        $cuerpo = is_array($ids)
            ? ['source_type' => $tipo, 'source_ids' => $ids]
            : ['source_type' => $tipo, 'source_id' => $ids];

        return $this->postJson('/api/admin/receipts', $cuerpo, $h ?? $this->h);
    }

    /**
     * Las tablas de dinero, inventario y facturación. Ninguna llamada de recibos
     * puede mover una sola fila de aquí.
     *
     * @return array<string, int>
     */
    protected function conteosDeNegocio(): array
    {
        $tablas = ['payments', 'payment_splits', 'product_sales', 'product_sale_items', 'product_sale_splits',
            'receivables', 'receivable_payments', 'cash_shifts', 'inventory_movements', 'electronic_invoices'];

        $out = [];
        foreach ($tablas as $t) {
            $out[$t] = DB::table($t)->count();
        }

        // Y que nada se haya REESCRITO: la huella de las filas de dinero y de los
        // turnos tampoco cambia. Contar no basta si una llamada actualizara un
        // estado o un total.
        $out['firma'] = crc32(json_encode([
            DB::table('payments')->orderBy('id')->get(['id', 'status', 'amount', 'cash_shift_id', 'updated_at']),
            DB::table('product_sales')->orderBy('id')->get(['id', 'status', 'total', 'cash_shift_id', 'updated_at']),
            DB::table('receivable_payments')->orderBy('id')->get(['id', 'status', 'amount', 'updated_at']),
            DB::table('receivables')->orderBy('id')->get(['id', 'status', 'updated_at']),
            DB::table('cash_shifts')->orderBy('id')->get(['id', 'status', 'sales_total', 'updated_at']),
            DB::table('products')->orderBy('id')->get(['id', 'stock']),
        ]));

        return $out;
    }
}
