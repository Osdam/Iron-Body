<?php

namespace App\Services\Meta;

use App\Models\MarketingAiAction;
use App\Models\MarketingAttribution;
use App\Models\MarketingCampaign;
use App\Models\MarketingConversation;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;

/**
 * Métricas agregadas del módulo Mercadeo. SOLO datos reales de las tablas
 * marketing_*; si no hay registros devuelve 0/null de forma segura (nunca
 * inventa ingresos ni ROAS). ROAS/CAC se calculan únicamente con datos reales.
 */
class MarketingMetricsService
{
    /** @return array<string,mixed> */
    public function overview(): array
    {
        $spendTotal = (float) MarketingCampaign::sum('spend');
        $leadsTotal = (int) MarketingLead::count();
        $convertedLeads = (int) MarketingLead::where('status', MarketingLead::STATUS_CONVERTED)->count();
        $revenueTotal = (float) MarketingAttribution::sum('sale_amount');

        // Clientes convertidos reales (miembros distintos atribuidos).
        $convertedCustomers = (int) MarketingAttribution::whereNotNull('member_id')
            ->distinct('member_id')
            ->count('member_id');

        return [
            'spend_total'          => round($spendTotal, 2),
            'leads_total'          => $leadsTotal,
            'conversations_total'  => (int) MarketingConversation::count(),
            'converted_leads'      => $convertedLeads,
            'revenue_total'        => round($revenueTotal, 2),
            'roas'                 => $spendTotal > 0 ? round($revenueTotal / $spendTotal, 2) : null,
            'cac'                  => $convertedCustomers > 0 ? round($spendTotal / $convertedCustomers, 2) : null,
            'conversion_rate'      => $leadsTotal > 0 ? round($convertedLeads / $leadsTotal, 4) : null,
            'hot_leads'            => (int) MarketingLead::where('temperature', 'hot')
                ->orWhere('status', MarketingLead::STATUS_HOT)->count(),
            'pending_followups'    => (int) MarketingFollowup::where('status', MarketingFollowup::STATUS_PENDING)->count(),
            'ai_actions_count'     => (int) MarketingAiAction::count(),
            'human_takeover_count' => (int) MarketingConversation::where('human_takeover', true)->count(),
        ];
    }
}
