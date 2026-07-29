<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignTaxRateRequest;
use App\Http\Requests\Admin\UpdatePricingModeRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use App\Services\Billing\PricingException;
use App\Services\Billing\PricingMode;
use App\Services\Billing\PricingService;
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
            'plans' => $plans,
            'products' => $products,
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

    // PUT /api/admin/billing/plans/{plan}/pricing-mode  { pricing_mode, billing_enabled? }
    public function updatePlanPricing(UpdatePricingModeRequest $request, Plan $plan): JsonResponse
    {
        $error = $this->applyPricing($plan, $request->validated());
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json(['data' => $this->itemArray($plan->fresh('taxRate'), $plan->price, (bool) $plan->active)]);
    }

    // PUT /api/admin/billing/products/{product}/pricing-mode
    public function updateProductPricing(UpdatePricingModeRequest $request, Product $product): JsonResponse
    {
        $error = $this->applyPricing($product, $request->validated());
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json(['data' => $this->itemArray($product->fresh('taxRate'), (float) $product->sale_price, true)]);
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Cambia la semántica del precio (y opcionalmente si se factura).
     *
     * Pasar a base_plus_tax SUBE el total que paga el cliente, así que exige una
     * tarifa fiscal válida: sin ella el "IVA adicional" sería 0 y el cambio no
     * tendría efecto, dejando al administrador con la falsa impresión de haberlo
     * aplicado. Los registros gratuitos o no facturables quedan exentos de ese
     * requisito.
     *
     * @param  array<string,mixed>  $data
     * @return string|null Mensaje de error, o null si se aplicó.
     */
    private function applyPricing(Plan|Product $model, array $data): ?string
    {
        $mode = PricingMode::fromValue($data['pricing_mode'] ?? null);
        $billingEnabled = array_key_exists('billing_enabled', $data)
            ? (bool) $data['billing_enabled']
            : (bool) $model->billing_enabled;

        $price = $model instanceof Plan ? (float) $model->price : (float) $model->sale_price;
        $requiresRate = $mode === PricingMode::BASE_PLUS_TAX && $billingEnabled && $price > 0;

        if ($requiresRate && $model->tax_rate_id === null) {
            return 'Para usar «IVA adicional» primero asigna una tarifa fiscal con tasa mayor que cero.';
        }
        if ($requiresRate && (float) ($model->taxRate?->rate ?? 0) <= 0) {
            return 'La tarifa asignada tiene tasa 0%. «IVA adicional» no cambiaría el total; revisa el tratamiento tributario.';
        }

        $model->forceFill([
            'pricing_mode' => $mode->value,
            'billing_enabled' => $billingEnabled,
        ])->save();

        return null;
    }

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
            'id' => $r->id,
            'code' => $r->code,
            'name' => $r->name,
            'rate' => (float) $r->rate,
            'price_includes_tax' => $r->price_includes_tax,
            'factus_tribute_id' => $r->factus_tribute_id,
        ];
    }

    private function itemArray(Plan|Product $m, float $price, bool $active): array
    {
        $rate = $m->taxRate;
        $billingEnabled = (bool) $m->billing_enabled;
        $billable = $billingEnabled && $price > 0;

        // Vista previa del desglose con la configuración vigente. Si la
        // configuración fiscal está incompleta, se devuelve null en vez de
        // inventar un total.
        $quote = null;
        try {
            $quote = $m instanceof Plan
                ? app(PricingService::class)->quoteForPlan($m)
                : app(PricingService::class)->quoteForProduct($m);
        } catch (PricingException) {
            $quote = null;
        }

        return [
            'id' => $m->id,
            'name' => $m->name,
            'price' => $price,
            'active' => $active,
            'tax_rate_id' => $m->tax_rate_id,
            'tax_rate_name' => $rate?->name,
            'tax_rate' => $rate !== null ? (float) $rate->rate : null,
            'price_includes_tax' => (bool) $m->price_includes_tax,
            // Pricing V2.
            'pricing_mode' => $m->pricingMode()->value,
            'billing_enabled' => $billingEnabled,
            'base_price' => $quote?->baseAmount->toFloat(),
            'tax_amount' => $quote?->taxAmount->toFloat(),
            'final_price' => $quote?->grossAmount->toFloat() ?? $price,
            // Solo es "pendiente" lo que realmente se factura: un plan gratuito
            // o no facturable no queda marcado como configuración incompleta.
            'pending' => $billable && $m->tax_rate_id === null,
        ];
    }
}
