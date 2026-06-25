<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignTaxRateRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use Illuminate\Http\JsonResponse;

/**
 * Configuración fiscal de planes y productos (Fase 9). Bajo /api/admin/* →
 * blindado por ProtectAdminPaths.
 *
 * Asignar una tarifa sincroniza price_includes_tax del plan/producto desde la
 * tarifa (IVA incluido vs no incluido), de modo que el InvoiceDtoBuilder calcule
 * base/IVA correctamente sin más cambios. NO se asume IVA en membresías: se
 * dejan sin asignar hasta la decisión del contador.
 */
class BillingTaxController extends Controller
{
    // GET /api/admin/billing/tax-rates
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => TaxRate::query()->where('active', true)->orderBy('id')
                ->get()
                ->map(fn (TaxRate $r) => $this->rateArray($r)),
        ]);
    }

    // GET /api/admin/billing/fiscal-assignments
    public function assignments(): JsonResponse
    {
        $plans = Plan::query()->with('taxRate')->orderBy('name')->get()
            ->map(fn (Plan $p) => $this->itemArray($p, $p->price, (bool) $p->active));

        $products = Product::query()->with('taxRate')->where('active', true)->orderBy('name')->get()
            ->map(fn (Product $p) => $this->itemArray($p, (float) $p->sale_price, true));

        return response()->json([
            'tax_rates' => TaxRate::query()->where('active', true)->orderBy('id')->get()
                ->map(fn (TaxRate $r) => $this->rateArray($r)),
            'plans'     => $plans,
            'products'  => $products,
        ]);
    }

    // PUT /api/admin/billing/plans/{plan}/tax-rate
    public function assignPlan(AssignTaxRateRequest $request, Plan $plan): JsonResponse
    {
        $this->applyRate($plan, $request->validated()['tax_rate_id'] ?? null);

        return response()->json(['data' => $this->itemArray($plan->fresh('taxRate'), $plan->price, (bool) $plan->active)]);
    }

    // PUT /api/admin/billing/products/{product}/tax-rate
    public function assignProduct(AssignTaxRateRequest $request, Product $product): JsonResponse
    {
        $this->applyRate($product, $request->validated()['tax_rate_id'] ?? null);

        return response()->json(['data' => $this->itemArray($product->fresh('taxRate'), (float) $product->sale_price, true)]);
    }

    // POST /api/admin/billing/products/bulk-tax  { tax_rate_id }
    public function bulkProducts(AssignTaxRateRequest $request): JsonResponse
    {
        $taxRateId = $request->validated()['tax_rate_id'] ?? null;
        $count = 0;

        Product::query()->where('active', true)->get()->each(function (Product $p) use ($taxRateId, &$count): void {
            $this->applyRate($p, $taxRateId);
            $count++;
        });

        return response()->json(['ok' => true, 'updated' => $count]);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /** Asigna la tarifa y sincroniza price_includes_tax desde la tarifa. */
    private function applyRate(Plan|Product $model, ?int $taxRateId): void
    {
        $rate = $taxRateId ? TaxRate::find($taxRateId) : null;

        $attrs = ['tax_rate_id' => $rate?->id];
        if ($rate !== null && $rate->price_includes_tax !== null) {
            $attrs['price_includes_tax'] = (bool) $rate->price_includes_tax;
        }

        $model->forceFill($attrs)->save();
    }

    private function rateArray(TaxRate $r): array
    {
        return [
            'id'                 => $r->id,
            'code'               => $r->code,
            'name'               => $r->name,
            'rate'               => (float) $r->rate,
            'price_includes_tax' => $r->price_includes_tax,
            'factus_tribute_id'  => $r->factus_tribute_id,
        ];
    }

    private function itemArray(Plan|Product $m, float $price, bool $active): array
    {
        $rate = $m->taxRate;

        return [
            'id'                 => $m->id,
            'name'               => $m->name,
            'price'              => $price,
            'active'             => $active,
            'tax_rate_id'        => $m->tax_rate_id,
            'tax_rate_name'      => $rate?->name,
            'price_includes_tax' => (bool) $m->price_includes_tax,
            'pending'            => $m->tax_rate_id === null,
        ];
    }
}
