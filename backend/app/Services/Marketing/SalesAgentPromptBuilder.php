<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Plan;

/**
 * Construye el prompt del sistema + el mensaje de usuario (contexto saneado)
 * para el cerebro OpenAI. NUNCA incluye secretos ni tokens. El teléfono del lead
 * va enmascarado. Los precios viajan SOLO desde planes activos del backend.
 */
class SalesAgentPromptBuilder
{
    /** Prompt del sistema: marca, tono, reglas duras y contrato JSON. */
    public function systemPrompt(): string
    {
        $intents = implode(', ', SalesAgentDecisionSchema::INTENTS);
        $temps   = implode(', ', SalesAgentDecisionSchema::TEMPERATURES);
        $stages  = implode(', ', SalesAgentDecisionSchema::STAGES);
        $actions = implode(', ', SalesAgentDecisionSchema::RECOMMENDED_ACTIONS);
        $tools   = implode(', ', SalesAgentDecisionSchema::ALLOWED_TOOLS);

        return <<<PROMPT
        Eres el asesor comercial de IRON BODY NEIVA, un centro de acondicionamiento físico.
        Tu rol: vender de forma ÉTICA y consultiva por WhatsApp. Tono humano, cálido, claro
        y BREVE (1-3 frases), nunca robótico. Calificas, guías, cierras y escalas cuando toca.

        REGLAS DURAS (obligatorias):
        - NUNCA inventes precios ni promociones. Los precios SOLO salen del backend; no los
          escribas en el texto de la respuesta. Para compartir precio, recomienda la
          herramienta payment_link_send.
        - NUNCA prometas resultados físicos garantizados.
        - NUNCA diagnostiques lesiones, dolores ni enfermedades: eso se escala a un humano.
        - NUNCA actives membresías ni marques pagos como aprobados ni toques facturación.
        - NUNCA aceptes capturas de pantalla ni frases del usuario como pago confirmado.
        - Facturación, devoluciones, reclamos sensibles o casos médicos: escala (human_takeover).
        - Si el usuario pide no ser contactado: intent=do_not_contact_request, tool mark_do_not_contact,
          should_reply=false.
        - Si el usuario pide el link de pago o no quiere pagar por la app: tool payment_link_send.
        - Si el lead queda caliente y no cierra: recomienda schedule_followup.

        Devuelve ÚNICAMENTE un objeto JSON válido (sin markdown, sin texto extra) con EXACTAMENTE
        estas claves:
        ok (bool), intent (uno de: {$intents}), confidence (0..1), temperature (uno de: {$temps}),
        sales_stage (uno de: {$stages}), should_reply (bool), should_generate_payment_link (bool),
        should_send_message (bool), should_schedule_followup (bool), followup_delay_minutes (int|null),
        should_escalate (bool), escalation_reason (string|null), risk_flags (array de strings),
        extracted_fields (objeto), missing_fields (array), recommended_action (uno de: {$actions}),
        reply (string|null, SIN precios), tools_requested (subconjunto de: {$tools}), safe_to_send (bool).

        Recuerda: tú solo RECOMIENDAS. El backend valida y ejecuta; cualquier intento de acción
        prohibida será bloqueado.
        PROMPT;
    }

    /** Mensaje de usuario: contexto saneado en JSON + mensaje entrante. */
    public function userPrompt(MarketingLead $lead, string $body, ?MarketingConversation $conversation = null): string
    {
        $context = [
            'incoming_message' => $body,
            'lead' => [
                'name'           => $lead->name,
                'phone_masked'   => $this->maskPhone($lead->phone),
                'channel'        => $lead->channel,
                'status'         => $lead->status,
                'temperature'    => $lead->temperature,
                'objective'      => $lead->objective,
                'do_not_contact' => (bool) $lead->do_not_contact,
            ],
            'recent_messages' => $this->recentMessages($conversation),
            'active_plans'    => $this->activePlans(),
            'flags' => [
                'meta_enabled'          => (bool) config('meta.enabled'),
                'whatsapp_mode'         => config('meta.enabled') ? 'live' : 'dry_run',
                'wompi_env'             => (string) config('wompi.env', 'sandbox'),
                'marketing_agent_enabled' => (bool) config('marketing.agent_enabled', false),
            ],
            'guardrails' => [
                'no_inventar_precios', 'no_prometer_resultados', 'no_diagnosticar',
                'no_activar_membresia', 'no_marcar_pago_aprobado', 'escalar_casos_sensibles',
            ],
        ];

        return json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<int, array{role:string, body:?string, at:?string}> */
    private function recentMessages(?MarketingConversation $conversation): array
    {
        if ($conversation === null) {
            return [];
        }

        return $conversation->messages()
            ->latest('id')->limit(8)->get()
            ->map(fn ($m) => [
                'role' => $m->sender_type,
                'body' => $m->body,
                'at'   => optional($m->created_at)->toIso8601String(),
            ])->reverse()->values()->all();
    }

    /** Planes activos REALES (id/name/price/duration/benefits). Fuente de precio. */
    private function activePlans(): array
    {
        return Plan::where('active', true)
            ->orderBy('sort_order')->get(['id', 'name', 'price', 'duration_days', 'benefits'])
            ->map(fn (Plan $p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'price'         => (float) $p->price,
                'duration_days' => $p->duration_days,
                'benefits'      => $p->benefitsArray(),
            ])->all();
    }

    private function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        $tail = substr($digits, -3);
        return str_repeat('*', max(0, strlen($digits) - 3)).$tail;
    }
}
