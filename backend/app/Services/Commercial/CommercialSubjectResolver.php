<?php

namespace App\Services\Commercial;

use App\Models\MarketingLead;
use Illuminate\Support\Facades\Schema;

/**
 * De un identificador suelto a la persona.
 *
 * Los hechos llegan con la referencia que tenía a mano quien los produjo: el
 * pago sabe de `member_id`, la conversación sabe de `lead_id`, la factura sabe
 * de un `source_id`. El motor, en cambio, necesita siempre a la PERSONA, que
 * puede tener las dos caras a la vez —un prospecto que ya se hizo socio sigue
 * teniendo su ficha de lead con la conversación de WhatsApp.
 *
 * Perder ese vínculo es caro en las dos direcciones: si un pago no encuentra el
 * lead, el sistema no puede responder por WhatsApp a quien acaba de pagar; si
 * un mensaje no encuentra al miembro, el agente habla como si el cliente no
 * existiera en el gimnasio.
 */
class CommercialSubjectResolver
{
    /**
     * @return array{lead_id:?int, member_id:?int}
     */
    public function fromMember(?int $memberId): array
    {
        if ($memberId === null) {
            return ['lead_id' => null, 'member_id' => null];
        }

        return [
            'lead_id' => $this->leadIdForMember($memberId),
            'member_id' => $memberId,
        ];
    }

    /**
     * @return array{lead_id:?int, member_id:?int}
     */
    public function fromLead(?int $leadId): array
    {
        if ($leadId === null) {
            return ['lead_id' => null, 'member_id' => null];
        }

        $lead = MarketingLead::query()->find($leadId);

        return [
            'lead_id' => $leadId,
            'member_id' => $lead?->member_id,
        ];
    }

    /**
     * La membresía se guarda en `users`, no en `members`: quien cambia es el
     * usuario y hay que llegar desde ahí a la ficha comercial.
     *
     * @return array{lead_id:?int, member_id:?int}
     */
    public function fromUser(?int $userId): array
    {
        if ($userId === null || ! Schema::hasTable('members')) {
            return ['lead_id' => null, 'member_id' => null];
        }

        $memberId = \App\Models\Member::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->value('id');

        return $this->fromMember($memberId !== null ? (int) $memberId : null);
    }

    /**
     * El lead de un miembro, si alguna vez llegó por mercadeo.
     *
     * Muchos socios se inscribieron en recepción y nunca tuvieron lead: eso no
     * es un error, solo significa que no hay conversación por la que hablarles.
     */
    private function leadIdForMember(int $memberId): ?int
    {
        if (! Schema::hasTable('marketing_leads')) {
            return null;
        }

        return MarketingLead::query()
            ->where('member_id', $memberId)
            // El más reciente: si alguien escribió dos veces a lo largo de los
            // años, la conversación viva es la última.
            ->orderByDesc('id')
            ->value('id');
    }
}
