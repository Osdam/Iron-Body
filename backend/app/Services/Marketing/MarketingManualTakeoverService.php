<?php

namespace App\Services\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use Illuminate\Support\Facades\DB;

/**
 * ÚNICO punto de escritura del takeover manual desde el CRM. Centraliza la
 * regla crítica: `human_takeover=true` SOLO nace aquí (CRM), siempre marcado
 * como 'manual' para que el router NO lo recupere. La IA jamás se apaga sola.
 *
 * - takeover(): pausa la IA (manual). Idempotente.
 * - release():  reactiva la IA. Idempotente.
 */
class MarketingManualTakeoverService
{
    /**
     * Motivos por los que una persona toma el control.
     *
     * Lista cerrada porque se cuentan: «cuántas veces tuvimos que entrar
     * porque el agente se equivocó» es una pregunta que hay que poder
     * responder, y con texto libre no se puede. `other` existe para no
     * obligar a mentir cuando no encaja ninguno.
     */
    public const REASONS = [
        'customer_asked' => 'El cliente pidió hablar con una persona',
        'conflict' => 'Conflicto o queja',
        'commercial_exception' => 'Excepción comercial',
        'payment' => 'Asunto de pago',
        'billing' => 'Facturación',
        'agent_error' => 'El agente se equivocó',
        'sensitive_case' => 'Caso sensible',
        'other' => 'Otro',
    ];

    /** Pausa la IA por acción manual de un asesor/administrador. */
    public function takeover(MarketingConversation $conversation, ?int $adminId, ?string $reason = null): MarketingConversation
    {
        $conversation->forceFill([
            'human_takeover' => true,
            'human_takeover_source' => 'manual',
            'ai_enabled' => false,
            'manual_takeover_at' => now(),
            'manual_takeover_by' => $adminId,
        ])->save();

        MarketingAiAction::create([
            'lead_id' => $conversation->lead_id,
            'conversation_id' => $conversation->id,
            'action_type' => 'human_takeover',
            'reason' => $reason,
            'status' => 'executed',
            'metadata' => ['source' => 'manual', 'admin_id' => $adminId],
        ]);

        return $conversation;
    }

    /**
     * Reactiva la IA, pero NO la deja seguir desde donde se quedó.
     *
     * Es la parte que no es obvia. Mientras la conversación estuvo en manos de
     * una persona pudieron pasar cosas —se prometió algo, se acordó un precio,
     * se resolvió una queja— y el agente no las vivió. Devolverle el control con
     * el contexto de antes hace que retome un hilo que ya no existe y repita
     * preguntas que un humano ya contestó, que es exactamente la experiencia
     * que hace que un cliente pida no volver a hablar con el bot.
     *
     * Se escribe un resumen del traspaso en `summary`, que es de donde el
     * constructor del prompt saca la memoria de la conversación. Así el agente
     * lo recibe sin que haya que tocar el prompt.
     */
    public function release(MarketingConversation $conversation, ?int $adminId): MarketingConversation
    {
        $handover = $this->handoverSummary($conversation);
        // Solo cierra la petición de humano la conversación que de verdad la tenía:
        // liberar una que nunca estuvo en manos de una persona no debe borrar un
        // needs_human puesto a mano desde el CRM.
        $estabaEnManosDeUnaPersona = (bool) $conversation->human_takeover;

        DB::transaction(function () use ($conversation, $handover, $estabaEnManosDeUnaPersona): void {
            $conversation->forceFill([
                'human_takeover' => false,
                'human_takeover_source' => null,
                'ai_enabled' => true,
                'summary' => $handover,
            ])->save();

            if ($estabaEnManosDeUnaPersona) {
                $this->closeNeedsHuman($conversation);
            }
        });

        MarketingAiAction::create([
            'lead_id' => $conversation->lead_id,
            'conversation_id' => $conversation->id,
            'action_type' => 'reactivate',
            'reason' => 'Devuelta a la IA con resumen del traspaso.',
            'status' => 'executed',
            'metadata' => ['source' => 'manual', 'admin_id' => $adminId, 'handover' => true],
        ]);

        return $conversation;
    }

    /**
     * Devolver la conversación a la IA cierra también la petición de humano del
     * lead. `lead.status = needs_human` es lo que decide y commit leen como
     * «derivar está autorizado»: si se quedara puesto, la puerta seguiría abierta
     * para siempre, sin que nadie lo hubiera pedido (en producción había dos leads
     * así, uno desde junio). Se restaura lo que era antes del escalado; sin ese
     * recuerdo, queda como interesado. La historia (`last_human_takeover_at`,
     * `human_takeover_reason`) se conserva: se sigue viendo que pasó por una persona.
     */
    private function closeNeedsHuman(MarketingConversation $conversation): void
    {
        $lead = $conversation->lead;
        if ($lead === null || (string) $lead->status !== MarketingLead::STATUS_NEEDS_HUMAN) {
            return;
        }
        // Si otra conversación del mismo lead sigue en manos de una persona, la petición sigue viva.
        if (MarketingConversation::query()->where('lead_id', $lead->id)->whereKeyNot($conversation->id)->where('human_takeover', true)->exists()) {
            return;
        }

        // El recuerdo se consume al usarlo: un escalado de hace meses no decide el estado de hoy.
        $meta = (array) ($lead->metadata ?? []);
        $antes = $meta['status_before_human'] ?? null;
        unset($meta['status_before_human']);
        $meta['needs_human_released_at'] = now()->toIso8601String();
        $restaurables = array_diff(MarketingLead::STATUSES, [MarketingLead::STATUS_NEEDS_HUMAN]);

        $lead->forceFill([
            'status' => in_array($antes, $restaurables, true) ? $antes : MarketingLead::STATUS_INTERESTED,
            'metadata' => $meta,
        ])->save();
    }

    /**
     * Qué pasó mientras la llevaba una persona.
     *
     * Hechos, no interpretaciones: cuántos mensajes escribió el equipo, qué
     * dijo lo último y en qué estado quedó la conversación. El agente decidirá
     * qué hacer con eso; aquí no se decide por él.
     */
    private function handoverSummary(MarketingConversation $conversation): string
    {
        $since = $conversation->manual_takeover_at;

        $humanMessages = $conversation->messages()
            ->where('sender_type', 'human')
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $lastCustomer = $conversation->messages()
            ->where('direction', 'inbound')
            ->orderByDesc('id')
            ->value('body');

        $parts = ['[Traspaso] Una persona del equipo atendió esta conversación y la devuelve al asistente.'];

        if ($humanMessages->isNotEmpty()) {
            $parts[] = sprintf(
                'El equipo escribió %d mensaje(s). Lo último que dijo: «%s».',
                $humanMessages->count(),
                mb_strimwidth((string) $humanMessages->first()->body, 0, 200, '…'),
            );
        } else {
            $parts[] = 'El equipo no llegó a escribir nada.';
        }

        if (filled($lastCustomer)) {
            $parts[] = sprintf('Lo último del cliente: «%s».', mb_strimwidth((string) $lastCustomer, 0, 200, '…'));
        }

        // Aviso explícito: lo que prometió una persona vale, y el agente no
        // puede contradecirlo ni volver a preguntarlo.
        $parts[] = 'No repitas lo que ya se habló ni contradigas lo que acordó el equipo. '
            .'Si algo quedó comprometido y no lo puedes verificar, pásalo a una persona.';

        return implode(' ', $parts);
    }
}
