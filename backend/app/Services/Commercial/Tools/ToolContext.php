<?php

namespace App\Services\Commercial\Tools;

use App\Models\Admin;
use App\Models\CommercialOpportunity;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;

/**
 * Quién pide la acción, sobre quién, y con qué respaldo.
 *
 * El contexto viaja aparte de los argumentos a propósito. El sujeto —a quién le
 * afecta esto— nunca puede venir del modelo: si el lead fuera un argumento más,
 * bastaría con que el modelo escribiera otro número para que una acción cayera
 * sobre la persona equivocada. Aquí lo fija quien invoca, que sabe de qué
 * conversación viene.
 */
final class ToolContext
{
    public function __construct(
        public readonly ?MarketingLead $lead = null,
        public readonly ?Member $member = null,
        public readonly ?MarketingConversation $conversation = null,
        public readonly ?CommercialOpportunity $opportunity = null,
        /** engine | human | test */
        public readonly string $requestedBy = 'engine',
        public readonly ?Admin $approvedBy = null,
        public readonly ?string $correlationId = null,
        /**
         * Identidad de la INTENCIÓN, no de la llamada. Dos intentos de generar
         * el enlace de pago del mismo plan para la misma persona comparten
         * clave; ahí está la protección contra el enlace duplicado.
         */
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function leadId(): ?int
    {
        return $this->lead?->id;
    }

    public function memberId(): ?int
    {
        return $this->member?->id ?? $this->lead?->member_id;
    }

    /** ¿Hay una persona respaldando esto, en lugar de solo el motor? */
    public function isHumanApproved(): bool
    {
        return $this->approvedBy !== null;
    }

    public function withIdempotencyKey(string $key): self
    {
        return new self(
            $this->lead, $this->member, $this->conversation, $this->opportunity,
            $this->requestedBy, $this->approvedBy, $this->correlationId, $key,
        );
    }
}
