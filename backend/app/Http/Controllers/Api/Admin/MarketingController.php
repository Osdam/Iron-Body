<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingAiAction;
use App\Models\MarketingAttribution;
use App\Models\MarketingCampaign;
use App\Models\MarketingConversation;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\SalesGuardrailException;
use App\Services\Marketing\SalesPaymentGuardrailService;
use App\Services\Marketing\WompiPaymentLinkService;
use App\Services\Meta\MarketingMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints admin del módulo Mercadeo (CRM Angular). Sirven datos REALES de las
 * tablas marketing_*; si no hay registros, devuelven listas vacías / 0 / null
 * (empty states reales, sin inventar). Siguen el patrón /admin/* del CRM (sin
 * auth propia; protegido por la capa de red/front). NO exponen payloads crudos
 * de webhook, tokens ni metadata sensible: solo campos públicos explícitos.
 */
class MarketingController extends Controller
{
    public function __construct(private readonly MarketingMetricsService $metrics)
    {
    }

    /** GET /api/admin/marketing/overview */
    public function overview(): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->metrics->overview()]);
    }

    /** GET /api/admin/marketing/campaigns */
    public function campaigns(Request $request): JsonResponse
    {
        $page = MarketingCampaign::query()
            ->withSum('attributions as revenue_attributed', 'sale_amount')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('updated_at')
            ->paginate($this->perPage($request));

        return $this->paginated($page, function (MarketingCampaign $c) {
            $revenue = (float) ($c->revenue_attributed ?? 0);
            $spend = (float) $c->spend;
            return [
                'id'                 => $c->id,
                'meta_campaign_id'   => $c->meta_campaign_id,
                'name'               => $c->name,
                'status'             => $c->status,
                'objective'          => $c->objective,
                'spend'              => $spend,
                'impressions'        => (int) $c->impressions,
                'reach'              => (int) $c->reach,
                'clicks'             => (int) $c->clicks,
                'ctr'                => $c->ctr,
                'cpc'                => $c->cpc,
                'cpm'                => $c->cpm,
                'leads'              => (int) $c->leads,
                'conversations'      => (int) $c->conversations,
                'revenue_attributed' => round($revenue, 2),
                'roas'               => $spend > 0 ? round($revenue / $spend, 2) : null,
                'date_range'         => ['start' => $c->date_start?->toDateString(), 'stop' => $c->date_stop?->toDateString()],
                'created_at'         => $c->created_at?->toIso8601String(),
                'updated_at'         => $c->updated_at?->toIso8601String(),
            ];
        });
    }

    /** GET /api/admin/marketing/leads */
    public function leads(Request $request): JsonResponse
    {
        $page = MarketingLead::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('temperature'), fn ($q, $v) => $q->where('temperature', $v))
            ->when($request->query('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->when($request->query('campaign_id'), fn ($q, $v) => $q->where('campaign_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('created_at')
            ->paginate($this->perPage($request));

        return $this->paginated($page, fn (MarketingLead $l) => [
            'id'                 => $l->id,
            'channel'            => $l->channel,
            'source'             => $l->source,
            'name'               => $l->name,
            'phone'              => $l->phone,
            'instagram_username' => $l->instagram_username,
            'status'             => $l->status,
            'temperature'        => $l->temperature,
            'objective'          => $l->objective,
            'campaign_id'        => $l->campaign_id,
            'first_message_at'   => $l->first_message_at?->toIso8601String(),
            'last_message_at'    => $l->last_message_at?->toIso8601String(),
            'converted_at'       => $l->converted_at?->toIso8601String(),
            'created_at'         => $l->created_at?->toIso8601String(),
        ]);
    }

    /** GET /api/admin/marketing/conversations */
    public function conversations(Request $request): JsonResponse
    {
        $page = MarketingConversation::query()
            ->with('lead:id,name,channel,status,temperature')
            ->withCount('messages')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->latest('last_message_at')
            ->paginate($this->perPage($request));

        return $this->paginated($page, fn (MarketingConversation $c) => [
            'id'              => $c->id,
            'channel'         => $c->channel,
            'status'          => $c->status,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'human_takeover'  => (bool) $c->human_takeover,
            'ai_enabled'      => (bool) $c->ai_enabled,
            'message_count'   => (int) $c->messages_count,
            'lead'            => $c->lead ? [
                'id'          => $c->lead->id,
                'name'        => $c->lead->name,
                'channel'     => $c->lead->channel,
                'status'      => $c->lead->status,
                'temperature' => $c->lead->temperature,
            ] : null,
        ]);
    }

    /** GET /api/admin/marketing/conversations/{id}/messages */
    public function conversationMessages(Request $request, int $id): JsonResponse
    {
        $exists = MarketingConversation::whereKey($id)->exists();
        if (! $exists) {
            return response()->json(['ok' => false, 'message' => 'Conversación no encontrada.'], 404);
        }

        $page = MarketingMessage::query()
            ->where('conversation_id', $id)
            ->orderBy('created_at')
            ->paginate($this->perPage($request));

        // No se expone metadata cruda (puede traer datos del proveedor).
        return $this->paginated($page, fn (MarketingMessage $m) => [
            'id'          => $m->id,
            'direction'   => $m->direction,
            'sender_type' => $m->sender_type,
            'body'        => $m->body,
            'status'      => $m->status,
            'created_at'  => $m->created_at?->toIso8601String(),
        ]);
    }

    /** GET /api/admin/marketing/followups */
    public function followups(Request $request): JsonResponse
    {
        $page = MarketingFollowup::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderBy('due_at')
            ->paginate($this->perPage($request));

        return $this->paginated($page, fn (MarketingFollowup $f) => [
            'id'               => $f->id,
            'lead_id'          => $f->lead_id,
            'due_at'           => $f->due_at?->toIso8601String(),
            'type'             => $f->type,
            'status'           => $f->status,
            'message_template' => $f->message_template,
            'created_at'       => $f->created_at?->toIso8601String(),
        ]);
    }

    /** GET /api/admin/marketing/ai-actions */
    public function aiActions(Request $request): JsonResponse
    {
        $page = MarketingAiAction::query()
            ->when($request->query('lead_id'), fn ($q, $v) => $q->where('lead_id', $v))
            ->latest('created_at')
            ->paginate($this->perPage($request));

        return $this->paginated($page, fn (MarketingAiAction $a) => [
            'id'              => $a->id,
            'lead_id'         => $a->lead_id,
            'conversation_id' => $a->conversation_id,
            'action_type'     => $a->action_type,
            'reason'          => $a->reason,
            'confidence'      => $a->confidence,
            'status'          => $a->status,
            'created_at'      => $a->created_at?->toIso8601String(),
        ]);
    }

    /** GET /api/admin/marketing/attribution */
    public function attribution(Request $request): JsonResponse
    {
        $page = MarketingAttribution::query()
            ->when($request->query('campaign_id'), fn ($q, $v) => $q->where('campaign_id', $v))
            ->latest('converted_at')
            ->paginate($this->perPage($request));

        return $this->paginated($page, fn (MarketingAttribution $a) => [
            'id'            => $a->id,
            'lead_id'       => $a->lead_id,
            'member_id'     => $a->member_id,
            'campaign_id'   => $a->campaign_id,
            'sale_amount'   => (float) $a->sale_amount,
            'membership_id' => $a->membership_id,
            'converted_at'  => $a->converted_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /api/admin/marketing/leads/{lead}/payment-link — un humano del CRM
     * genera un link de pago Wompi para el lead. NO envía el mensaje
     * automáticamente: solo devuelve el link para copiar/compartir. Generar el
     * link NO activa membresía (eso es exclusivo del webhook Wompi aprobado).
     */
    public function paymentLink(
        Request $request,
        int $lead,
        SalesPaymentGuardrailService $guardrail,
    ): JsonResponse {
        $data = $request->validate([
            'plan_id'       => 'required|integer|exists:plans,id',
            'wants_invoice' => 'nullable|boolean',
            'invoice_email' => 'nullable|email|max:160',
        ]);

        $leadModel = MarketingLead::findOrFail($lead);
        $plan = Plan::findOrFail($data['plan_id']);

        try {
            $guardrail->assertCanGeneratePaymentLink($leadModel, $plan, $request->all());
        } catch (SalesGuardrailException $e) {
            return response()->json([
                'ok'       => false,
                'code'     => $e->errorCode,
                'message'  => $e->getMessage(),
                'escalate' => $e->escalate,
            ], $e->httpStatus);
        }

        $result = WompiPaymentLinkService::make()->generateForLead($leadModel, $plan, [
            'channel'       => $leadModel->channel,
            'wants_invoice' => (bool) ($data['wants_invoice'] ?? false),
            'invoice_email' => $data['invoice_email'] ?? null,
        ]);

        if (($result['configured'] ?? false) === false) {
            return response()->json([
                'ok'      => false,
                'code'    => $result['error'] ?? 'wompi_checkout_not_configured',
                'message' => $result['message'] ?? 'Link de pago no disponible.',
                'missing' => $result['missing'] ?? [],
            ], 503);
        }

        return response()->json([
            'ok'             => true,
            'lead_id'        => $leadModel->id,
            'payment_url'    => $result['payment_url'] ?? null,
            'reference'      => $result['reference'] ?? null,
            'amount'         => $result['amount'] ?? null,
            'currency'       => $result['currency'] ?? null,
            'expires_at'     => $result['expires_at'] ?? null,
            'transaction_id' => $result['transaction_id'] ?? null,
            'already_paid'   => (bool) ($result['already_paid'] ?? false),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->query('per_page', 20), 100));
    }

    /** Serializa un paginador con un mapper de items (campos públicos). */
    private function paginated($page, callable $map): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => collect($page->items())->map($map)->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }
}
