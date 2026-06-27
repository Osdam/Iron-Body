<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\SalesGuardrailException;
use App\Services\Marketing\SalesPaymentGuardrailService;
use App\Services\Marketing\WompiPaymentLinkService;
use App\Services\Meta\MetaAuthService;
use App\Services\Meta\MetaMessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints INTERNOS del asesor comercial IA (F3), disparados por n8n y
 * firmados HMAC (middleware automation.internal). Laravel es la fuente de
 * verdad: registra decisiones, gestiona follow-ups y el escalado a humano.
 *
 * SEGURO con META_ENABLED=false: NO se envían mensajes vivos a Meta; el envío
 * queda en dry_run (mensaje preparado y registrado, pero no entregado). Todo lo
 * demás (registrar acción IA, follow-up, human takeover, contexto) opera sobre
 * las tablas marketing_* locales. Nunca activa membresías ni toca facturación.
 */
class InternalMarketingController extends Controller
{
    public function __construct(
        private readonly MetaMessagingService $messaging,
        private readonly MetaAuthService $metaAuth,
    ) {
    }

    /** POST /api/internal/marketing/ai-action — registra la decisión del asesor IA. */
    public function aiAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_id'         => 'required|integer|exists:marketing_leads,id',
            'conversation_id' => 'nullable|integer|exists:marketing_conversations,id',
            'action_type'     => 'required|string|max:60',
            'reason'          => 'nullable|string|max:1000',
            'confidence'      => 'nullable|numeric|min:0|max:1',
            'status'          => 'nullable|string|in:proposed,executed,skipped,failed',
            'intent'          => 'nullable|string|max:40',
            'objection'       => 'nullable|string|max:40',
            'temperature'     => 'nullable|string|in:hot,warm,cold,unqualified',
        ]);

        $action = MarketingAiAction::create([
            'lead_id'         => $data['lead_id'],
            'conversation_id' => $data['conversation_id'] ?? null,
            'action_type'     => $data['action_type'],
            'reason'          => $data['reason'] ?? null,
            'confidence'      => $data['confidence'] ?? null,
            'status'          => $data['status'] ?? 'proposed',
            'metadata'        => array_filter([
                'intent'      => $data['intent'] ?? null,
                'objection'   => $data['objection'] ?? null,
                'temperature' => $data['temperature'] ?? null,
            ]),
        ]);

        // Refleja intención/temperatura en el lead (alimenta Mercadeo CRM).
        if (! empty($data['temperature'])) {
            $lead = MarketingLead::find($data['lead_id']);
            $lead?->forceFill(['temperature' => $data['temperature']])->save();
        }

        return response()->json(['ok' => true, 'ai_action_id' => $action->id]);
    }

    /**
     * POST /api/internal/marketing/send-message — envío saliente controlado.
     *
     * Recibe `marketing_lead_id` + `body` (+ canal, payment_*). Respeta
     * do_not_contact y exige teléfono para WhatsApp. Con META deshabilitado/sin
     * credenciales → dry_run (registra el mensaje pero NO lo entrega). Nunca 500
     * por falta de configuración Meta. No activa membresías.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'marketing_lead_id'      => 'required_without:conversation_id|integer|exists:marketing_leads,id',
            'conversation_id'        => 'nullable|integer|exists:marketing_conversations,id',
            'channel'                => 'nullable|string|in:whatsapp',
            'body'                   => 'required|string|max:4000',
            'payment_transaction_id' => 'nullable|integer|exists:payment_transactions,id',
            'payment_url'            => 'nullable|url|max:2048',
        ]);

        $lead = $this->resolveLeadForSend($data);
        if ($lead === null) {
            return response()->json(['ok' => false, 'reason' => 'lead_not_found', 'sent' => false, 'safe_to_send' => false], 404);
        }

        $channel = $data['channel'] ?? 'whatsapp';
        $result = $this->dispatchWhatsapp($lead, $channel, $data['body'], array_filter([
            'kind'                   => isset($data['payment_url']) ? 'payment_link' : 'text',
            'payment_transaction_id' => $data['payment_transaction_id'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json($result);
    }

    /**
     * POST /api/internal/marketing/payment-links/send — flujo completo:
     * genera/reutiliza el link de pago y lo envía por WhatsApp en un mensaje
     * humano corto. Con META deshabilitado devuelve el mensaje PREPARADO y
     * dry_run=true. Nunca activa membresía ni marca pago aprobado.
     */
    public function paymentLinksSend(
        Request $request,
        SalesPaymentGuardrailService $guardrail,
    ): JsonResponse {
        $data = $request->validate([
            'marketing_lead_id' => 'required|integer|exists:marketing_leads,id',
            'plan_id'           => 'required|integer|exists:plans,id',
            'channel'           => 'nullable|string|in:whatsapp',
            'wants_invoice'     => 'nullable|boolean',
            'invoice_email'     => 'nullable|email|max:160',
        ]);

        $lead = MarketingLead::findOrFail($data['marketing_lead_id']);
        $plan = Plan::findOrFail($data['plan_id']);
        $channel = $data['channel'] ?? 'whatsapp';

        // Guardrails de pago (do_not_contact, monto prohibido, plan activo/precio).
        try {
            $guardrail->assertCanGeneratePaymentLink($lead, $plan, $request->all());
        } catch (SalesGuardrailException $e) {
            return response()->json([
                'ok'       => false,
                'code'     => $e->errorCode,
                'message'  => $e->getMessage(),
                'escalate' => $e->escalate,
                'sent'     => false,
            ], $e->httpStatus);
        }

        $link = WompiPaymentLinkService::make()->generateForLead($lead, $plan, [
            'channel'       => $channel,
            'wants_invoice' => (bool) ($data['wants_invoice'] ?? false),
            'invoice_email' => $data['invoice_email'] ?? null,
        ]);

        // Falta config Wompi Web Checkout → 503 controlado (no 500, sin enviar).
        if (($link['configured'] ?? false) === false) {
            return response()->json([
                'ok'      => false,
                'code'    => $link['error'] ?? 'wompi_checkout_not_configured',
                'message' => $link['message'] ?? 'Link de pago no disponible.',
                'missing' => $link['missing'] ?? [],
                'sent'    => false,
            ], 503);
        }

        $linkSafe = ($link['already_paid'] ?? false) === false && ! empty($link['payment_url']);

        // Trazabilidad de la generación (igual que /payment-links).
        $this->recordPaymentLinkTrace($lead, null, $link, $linkSafe);

        // Si no es seguro mandar el link (ya pagó / sin URL), no se envía.
        if (! $linkSafe) {
            return response()->json([
                'ok'           => true,
                'lead_id'      => $lead->id,
                'payment_url'  => $link['payment_url'] ?? null,
                'reference'    => $link['reference'] ?? null,
                'already_paid' => (bool) ($link['already_paid'] ?? false),
                'sent'         => false,
                'dry_run'      => false,
                'safe_to_send' => false,
                'reason'       => ($link['already_paid'] ?? false) ? 'already_paid' : 'link_not_safe_to_send',
            ]);
        }

        // Mensaje humano corto (precio REAL del backend; nunca inventado).
        $body = $this->buildPaymentLinkMessage($plan, (float) $link['amount'], $link['payment_url']);

        $send = $this->dispatchWhatsapp($lead, $channel, $body, [
            'kind'      => 'payment_link',
            'reference' => $link['reference'] ?? null,
        ]);

        return response()->json(array_merge($send, [
            'lead_id'       => $lead->id,
            'payment_url'   => $link['payment_url'],
            'reference'     => $link['reference'] ?? null,
            'amount'        => $link['amount'] ?? null,
            'currency'      => $link['currency'] ?? null,
            'prepared_body' => $body,
        ]));
    }

    /** Resuelve el lead por marketing_lead_id o, retrocompat, por conversation_id. */
    private function resolveLeadForSend(array $data): ?MarketingLead
    {
        if (! empty($data['marketing_lead_id'])) {
            return MarketingLead::find($data['marketing_lead_id']);
        }
        if (! empty($data['conversation_id'])) {
            $conversation = MarketingConversation::find($data['conversation_id']);
            return $conversation ? MarketingLead::find($conversation->lead_id) : null;
        }
        return null;
    }

    // ── Envío WhatsApp (compartido) ───────────────────────────────────────────

    /**
     * Despacho WhatsApp con guardrails. Devuelve un resultado uniforme y NUNCA
     * lanza por falta de config Meta. Registra siempre el MarketingMessage
     * saliente (status: sent | failed | dry_run) salvo cuando se bloquea por
     * guardrail (do_not_contact / sin teléfono / canal no soportado).
     *
     * @return array{ok:bool,sent:bool,dry_run:bool,safe_to_send:bool,message_id:?int,provider_message_id:?string,reason:?string,conversation_id:?int}
     */
    private function dispatchWhatsapp(MarketingLead $lead, string $channel, string $body, array $metadata = []): array
    {
        $base = [
            'ok' => true, 'sent' => false, 'dry_run' => false, 'safe_to_send' => false,
            'message_id' => null, 'provider_message_id' => null, 'reason' => null, 'conversation_id' => null,
        ];

        // Guardrail: do_not_contact.
        if (! $lead->isContactable()) {
            return array_merge($base, ['reason' => 'do_not_contact']);
        }

        // Solo WhatsApp implementado hoy (IG/FB en fase viva).
        if ($channel !== 'whatsapp') {
            return array_merge($base, ['reason' => 'channel_not_supported']);
        }

        // Guardrail: WhatsApp exige teléfono del lead.
        $to = $this->normalizePhone($lead->phone);
        if ($to === null) {
            return array_merge($base, ['reason' => 'lead_without_phone']);
        }

        // A partir de aquí ES seguro intentar el envío.
        $conversation = MarketingConversation::firstOrCreate(
            ['lead_id' => $lead->id, 'channel' => $channel],
            ['status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'last_message_at' => now()],
        );

        // META deshabilitado o sin credenciales → dry_run (prepara, no entrega).
        if (! $this->metaAuth->isConfigured()) {
            $message = $this->recordOutbound($conversation, $body, 'dry_run', null, $metadata);
            return array_merge($base, [
                'dry_run'         => true,
                'safe_to_send'    => true,
                'message_id'      => $message->id,
                'conversation_id' => $conversation->id,
                'reason'          => 'meta_disabled_or_unconfigured',
            ]);
        }

        // Envío real (best-effort; sendWhatsappText nunca lanza ni loguea secretos).
        $providerId = $this->messaging->sendWhatsappText($to, $body);
        $message = $this->recordOutbound(
            $conversation,
            $body,
            $providerId !== null ? 'sent' : 'failed',
            $providerId,
            $metadata,
        );

        return array_merge($base, [
            'sent'                => $providerId !== null,
            'safe_to_send'        => true,
            'message_id'          => $message->id,
            'provider_message_id' => $providerId,
            'conversation_id'     => $conversation->id,
            'reason'              => $providerId !== null ? null : 'provider_send_failed',
        ]);
    }

    /** Registra el mensaje saliente y avanza last_message_at. */
    private function recordOutbound(
        MarketingConversation $conversation,
        string $body,
        string $status,
        ?string $providerId,
        array $metadata,
    ): MarketingMessage {
        $message = MarketingMessage::create([
            'conversation_id' => $conversation->id,
            'direction'       => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type'     => MarketingMessage::SENDER_AI,
            'body'            => $body,
            'meta_message_id' => $providerId,
            'status'          => $status,
            'metadata'        => $metadata ?: null,
        ]);
        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /** Normaliza un teléfono a dígitos (recipiente WhatsApp). null si vacío. */
    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        return ($digits === null || $digits === '') ? null : $digits;
    }

    /** Mensaje humano corto con el link (precio REAL; nunca inventado). */
    private function buildPaymentLinkMessage(Plan $plan, float $amount, string $url): string
    {
        $price = '$'.number_format($amount, 0, ',', '.').' COP';

        return "¡Hola! 💪 Aquí tienes tu link para activar tu membresía {$plan->name} ({$price}) en Iron Body Neiva. "
            ."Pagas seguro desde acá y tu acceso queda listo al confirmarse el pago: {$url}";
    }

    /** POST /api/internal/marketing/human-takeover — escala a humano. */
    public function humanTakeover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => 'required|integer|exists:marketing_conversations,id',
            'reason'          => 'nullable|string|max:500',
        ]);

        $conversation = MarketingConversation::findOrFail($data['conversation_id']);
        $conversation->update(['human_takeover' => true, 'ai_enabled' => false]);

        // Trazabilidad: queda registrado como acción IA.
        MarketingAiAction::create([
            'lead_id'         => $conversation->lead_id,
            'conversation_id' => $conversation->id,
            'action_type'     => 'human_takeover',
            'reason'          => $data['reason'] ?? null,
            'status'          => 'executed',
        ]);

        return response()->json(['ok' => true, 'conversation_id' => $conversation->id, 'human_takeover' => true]);
    }

    /** POST /api/internal/marketing/followups — crea seguimiento idempotente. */
    public function followups(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_id'          => 'required|integer|exists:marketing_leads,id',
            'due_at'           => 'nullable|date',
            'type'             => 'nullable|string|in:message,call,task',
            'message_template' => 'nullable|string|max:2000',
        ]);

        // Idempotencia: no duplica un follow-up pendiente del mismo lead/tipo/vencimiento.
        $followup = MarketingFollowup::firstOrCreate(
            [
                'lead_id' => $data['lead_id'],
                'type'    => $data['type'] ?? 'message',
                'due_at'  => $data['due_at'] ?? null,
                'status'  => MarketingFollowup::STATUS_PENDING,
            ],
            ['message_template' => $data['message_template'] ?? null],
        );

        return response()->json([
            'ok'          => true,
            'followup_id' => $followup->id,
            'created'     => $followup->wasRecentlyCreated,
        ]);
    }

    /**
     * POST /api/internal/marketing/payment-links — genera un link de pago Wompi
     * para enviar por WhatsApp/Meta cuando el lead no quiere pagar desde la app.
     *
     * SEGURO: el monto es autoritativo del backend (Plan::price); el cliente/n8n
     * NUNCA lo envía. Generar el link NO activa membresía: la activación sigue
     * siendo exclusiva del webhook Wompi aprobado. Respeta do_not_contact.
     */
    public function paymentLinks(
        Request $request,
        SalesPaymentGuardrailService $guardrail,
    ): JsonResponse {
        $data = $request->validate([
            'marketing_lead_id' => 'required|integer|exists:marketing_leads,id',
            'plan_id'           => 'required|integer|exists:plans,id',
            'channel'           => 'nullable|string|max:40',
            'conversation_id'   => 'nullable|integer|exists:marketing_conversations,id',
            'wants_invoice'     => 'nullable|boolean',
            'invoice_email'     => 'nullable|email|max:160',
        ]);

        $lead = MarketingLead::findOrFail($data['marketing_lead_id']);
        $plan = Plan::findOrFail($data['plan_id']);

        // Guardrails de pago (do_not_contact, monto prohibido en payload, plan
        // activo y con precio válido). Violación → JSON controlado (sin crear datos).
        try {
            $guardrail->assertCanGeneratePaymentLink($lead, $plan, $request->all());
        } catch (SalesGuardrailException $e) {
            return response()->json([
                'ok'       => false,
                'code'     => $e->errorCode,
                'message'  => $e->getMessage(),
                'escalate' => $e->escalate,
            ], $e->httpStatus);
        }

        $result = WompiPaymentLinkService::make()->generateForLead($lead, $plan, [
            'conversation_id' => $data['conversation_id'] ?? null,
            'channel'         => $data['channel'] ?? null,
            'wants_invoice'   => (bool) ($data['wants_invoice'] ?? false),
            'invoice_email'   => $data['invoice_email'] ?? null,
        ]);

        // Falta configuración Wompi Web Checkout → 503 controlado, sin link falso.
        if (($result['configured'] ?? false) === false) {
            return response()->json([
                'ok'      => false,
                'code'    => $result['error'] ?? 'wompi_checkout_not_configured',
                'message' => $result['message'] ?? 'Link de pago no disponible.',
                'missing' => $result['missing'] ?? [],
            ], 503);
        }

        $safeToSend = ($result['already_paid'] ?? false) === false
            && ! empty($result['payment_url']);

        // Trazabilidad: registra la generación como acción IA y, si hay
        // conversación, un mensaje saliente (sender=system) con el link.
        $this->recordPaymentLinkTrace($lead, $data['conversation_id'] ?? null, $result, $safeToSend);

        return response()->json([
            'ok'             => true,
            'lead_id'        => $lead->id,
            'payment_url'    => $result['payment_url'] ?? null,
            'reference'      => $result['reference'] ?? null,
            'amount'         => $result['amount'] ?? null,
            'currency'       => $result['currency'] ?? null,
            'expires_at'     => $result['expires_at'] ?? null,
            'transaction_id' => $result['transaction_id'] ?? null,
            'already_paid'   => (bool) ($result['already_paid'] ?? false),
            'safe_to_send'   => $safeToSend,
        ]);
    }

    /** Registra trazabilidad del link generado (no envía nada a Meta aquí). */
    private function recordPaymentLinkTrace(MarketingLead $lead, ?int $conversationId, array $result, bool $safeToSend): void
    {
        MarketingAiAction::create([
            'lead_id'         => $lead->id,
            'conversation_id' => $conversationId,
            'action_type'     => 'payment_link_generated',
            'reason'          => $result['already_paid'] ?? false ? 'already_paid' : null,
            'status'          => 'executed',
            'metadata'        => array_filter([
                'reference'      => $result['reference'] ?? null,
                'transaction_id' => $result['transaction_id'] ?? null,
                'amount'         => $result['amount'] ?? null,
                'safe_to_send'   => $safeToSend,
            ], fn ($v) => $v !== null),
        ]);

        if ($conversationId !== null && $safeToSend) {
            MarketingMessage::create([
                'conversation_id' => $conversationId,
                'direction'       => MarketingMessage::DIRECTION_OUTBOUND,
                'sender_type'     => MarketingMessage::SENDER_SYSTEM,
                'body'            => 'Link de pago: '.$result['payment_url'],
                'status'          => 'generated',
                'metadata'        => array_filter([
                    'kind'      => 'payment_link',
                    'reference' => $result['reference'] ?? null,
                ], fn ($v) => $v !== null),
            ]);
        }
    }

    /** GET /api/internal/marketing/context/{lead} — contexto mínimo saneado para la IA. */
    public function context(int $lead): JsonResponse
    {
        $model = MarketingLead::with(['campaign:id,name,objective'])->find($lead);
        if ($model === null) {
            return response()->json(['ok' => false, 'message' => 'Lead no encontrado.'], 404);
        }

        $conversation = MarketingConversation::where('lead_id', $lead)->latest('last_message_at')->first();
        $lastMessages = $conversation
            ? MarketingMessage::where('conversation_id', $conversation->id)
                ->latest('created_at')->limit(10)->get()
                ->map(fn (MarketingMessage $m) => [
                    'direction'   => $m->direction,
                    'sender_type' => $m->sender_type,
                    'body'        => $m->body,
                    'created_at'  => $m->created_at?->toIso8601String(),
                ])->reverse()->values()
            : [];

        // Planes reales (evita que la IA invente precios).
        $plans = Plan::where('active', true)->get(['id', 'name', 'price', 'duration_days'])
            ->map(fn (Plan $p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'price'         => (float) $p->price,
                'duration_days' => $p->duration_days,
            ]);

        return response()->json([
            'ok'   => true,
            'data' => [
                'lead' => [
                    'id'                 => $model->id,
                    'name'               => $model->name,
                    'channel'            => $model->channel,
                    'status'             => $model->status,
                    'temperature'        => $model->temperature,
                    'objective'          => $model->objective,
                    'instagram_username' => $model->instagram_username,
                ],
                'conversation' => $conversation ? [
                    'id'             => $conversation->id,
                    'channel'        => $conversation->channel,
                    'human_takeover' => (bool) $conversation->human_takeover,
                    'ai_enabled'     => (bool) $conversation->ai_enabled,
                ] : null,
                'last_messages'    => $lastMessages,
                'campaign'         => $model->campaign ? [
                    'id'        => $model->campaign->id,
                    'name'      => $model->campaign->name,
                    'objective' => $model->campaign->objective,
                ] : null,
                'membership_plans' => $plans,
                'business_info'    => [
                    'name'     => 'Iron Body Neiva',
                    'whatsapp' => config('meta.whatsapp_display_phone'),
                ],
            ],
        ]);
    }
}
