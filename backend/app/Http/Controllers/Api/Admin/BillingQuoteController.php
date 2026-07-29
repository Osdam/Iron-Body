<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BillingQuoteRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Services\Billing\Money;
use App\Services\Billing\PricingException;
use App\Services\Billing\PricingService;
use Illuminate\Http\JsonResponse;

/**
 * Cotización oficial del CRM: POST /api/admin/billing/quote
 *
 * Existe para que el frontend NUNCA calcule dinero. La UI muestra base, IVA y
 * total, pero los tres valores vienen de aquí — del mismo PricingService que
 * después cobra y factura. Si el frontend calculara su propio total, volvería a
 * abrirse la puerta a que lo mostrado y lo cobrado divergieran.
 *
 * Bajo /api/admin/* → blindado por auth.admin + ProtectAdminPaths.
 */
class BillingQuoteController extends Controller
{
    public function __construct(private PricingService $pricing) {}

    public function __invoke(BillingQuoteRequest $request): JsonResponse
    {
        $data = $request->validated();
        $quantity = (int) ($data['quantity'] ?? 1);
        $discount = isset($data['discount']) ? Money::fromAmount($data['discount']) : null;

        try {
            $quote = $data['source_type'] === 'plan'
                ? $this->pricing->quoteForPlan($this->plan((int) $data['source_id']), $quantity)
                : $this->pricing->quoteForProduct($this->product((int) $data['source_id']), $quantity, $discount);
        } catch (PricingException $e) {
            // Configuración fiscal incompleta o entrada inválida: 422 con el
            // motivo exacto, para que el CRM lo muestre en vez de inventar un total.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($quote->toArray());
    }

    private function plan(int $id): Plan
    {
        /** @var Plan $plan */
        $plan = Plan::with('taxRate')->findOrFail($id);

        return $plan;
    }

    private function product(int $id): Product
    {
        /** @var Product $product */
        $product = Product::with('taxRate')->findOrFail($id);

        return $product;
    }
}
