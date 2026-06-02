<?php

namespace App\Services\Meta;

use App\Models\MarketingAttribution;
use App\Models\MarketingCampaign;
use App\Models\MarketingLead;

/**
 * Atribución comercial y métricas. El ingreso proviene SIEMPRE de ventas reales
 * (sale_amount provisto por el caller desde un pago real). Si no hay atribución
 * exacta, no se registra (queda organic/unknown). No inventa ingresos.
 */
class MarketingAttributionService
{
    /**
     * Atribuye una venta a un lead (y su campaña/miembro). Marca el lead como
     * convertido. Idempotente por (lead_id, payment_id) cuando hay payment.
     */
    public function attributeSale(
        MarketingLead $lead,
        float $saleAmount,
        ?int $memberId = null,
        ?int $paymentId = null,
        ?int $membershipId = null,
    ): MarketingAttribution {
        $attribution = MarketingAttribution::firstOrCreate(
            [
                'lead_id'    => $lead->id,
                'payment_id' => $paymentId,
            ],
            [
                'member_id'     => $memberId ?? $lead->member_id,
                'campaign_id'   => $lead->campaign_id,
                'sale_amount'   => $saleAmount,
                'membership_id' => $membershipId,
                'converted_at'  => now(),
            ],
        );

        $lead->forceFill([
            'status'       => MarketingLead::STATUS_CONVERTED,
            'member_id'    => $memberId ?? $lead->member_id,
            'converted_at' => $lead->converted_at ?? now(),
        ])->save();

        return $attribution;
    }

    /** ROAS = ingreso atribuido / gasto. Null si no hay gasto. */
    public function roas(float $revenue, float $spend): ?float
    {
        return $spend > 0 ? round($revenue / $spend, 2) : null;
    }

    /** CAC = gasto / clientes convertidos. Null si no hay conversiones. */
    public function cac(float $spend, int $convertedCustomers): ?float
    {
        return $convertedCustomers > 0 ? round($spend / $convertedCustomers, 2) : null;
    }

    /** Tasa de conversión = leads convertidos / total leads. */
    public function conversionRate(int $convertedLeads, int $totalLeads): ?float
    {
        return $totalLeads > 0 ? round($convertedLeads / $totalLeads, 4) : null;
    }

    /** Ingreso total atribuido a una campaña (desde ventas reales). */
    public function campaignRevenue(MarketingCampaign $campaign): float
    {
        return (float) $campaign->attributions()->sum('sale_amount');
    }
}
