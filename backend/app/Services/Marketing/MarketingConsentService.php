<?php

namespace App\Services\Marketing;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingLead;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Facades\DB;

/**
 * El ÚNICO sitio del producto por donde se reabre un contacto cerrado.
 *
 * No hay otro. Ni endpoint suelto, ni `forceFill` en un controlador, ni el
 * UPDATE a mano que hasta hoy era la única salida. Que sea uno solo es la
 * mitad del diseño: un estado que se puede cambiar desde cinco sitios no tiene
 * expediente, tiene rumores.
 *
 * Tres propiedades que se sostienen juntas:
 *
 *  - **Nunca automático.** Reabrir exige una vía de {@see ConsentAuthority}:
 *    o lo pidió la persona con sus palabras, o lo firmó alguien del equipo.
 *    Ningún otro camino del sistema llama aquí «de paso».
 *  - **Idempotente.** Reabrir un contacto que ya estaba abierto no escribe
 *    nada: devuelve `already_open` y no deja fila. Repetir la misma orden no
 *    duplica el expediente ni mueve una fecha.
 *  - **Auditado o nada.** El cambio del lead y la fila del expediente van en
 *    la MISMA transacción. Si no se puede escribir el acta, no se reabre: un
 *    contacto reabierto sin constancia es peor que uno que sigue cerrado.
 */
class MarketingConsentService
{
    public function __construct(
        private readonly ConsentAuthority $authority = new ConsentAuthority,
    ) {}

    /**
     * Reabre el contacto de un lead, o explica por qué no.
     *
     * @param  string  $source  {@see ConsentAuthority::SOURCES}
     * @param  ?int  $actorAdminId  obligatorio en la vía administrativa
     * @param  ?string  $texto  lo que la persona escribió, en su vía
     * @return array{status:string, reason:?string, event_id:?int}
     */
    public function reopen(
        MarketingLead $lead,
        string $source,
        ?string $reason,
        ?int $actorAdminId = null,
        ?string $texto = null,
        ?int $messageId = null,
    ): array {
        $veredicto = $this->authority->decideReopen($source, $reason, $texto);

        if (! $veredicto['allowed']) {
            return ['status' => 'refused', 'reason' => $veredicto['refusal'], 'event_id' => null];
        }

        /*
         * La vía administrativa sin persona detrás no es una vía
         * administrativa: es un UPDATE con otro nombre. El actor es lo único
         * que la distingue, así que se exige aquí y no en la autoridad, que no
         * sabe de sesiones.
         */
        if ($source === ConsentAuthority::SOURCE_ADMIN && $actorAdminId === null) {
            return ['status' => 'refused', 'reason' => 'admin_actor_required', 'event_id' => null];
        }

        return DB::transaction(function () use ($lead, $source, $reason, $actorAdminId, $veredicto, $messageId) {
            // Dentro de la transacción y con bloqueo: dos reaperturas a la vez
            // escribirían dos actas del mismo cambio.
            $fresco = MarketingLead::query()->lockForUpdate()->find($lead->id);
            if ($fresco === null) {
                return ['status' => 'refused', 'reason' => 'lead_not_found', 'event_id' => null];
            }

            if (! $fresco->do_not_contact) {
                // Ya estaba abierto. No se toca nada y no se inventa un acta de
                // un cambio que no ocurrió.
                return ['status' => 'already_open', 'reason' => null, 'event_id' => null];
            }

            $antesDnc = (bool) $fresco->do_not_contact;
            $antesConsent = $fresco->consent_status;

            $fresco->forceFill([
                'do_not_contact' => false,
                'consent_status' => MarketingLead::CONSENT_GRANTED,
                'consent_source' => $source,
                'consent_at' => now(),
            ])->save();

            $evento = MarketingConsentEvent::create([
                'marketing_lead_id' => $fresco->id,
                'from_do_not_contact' => $antesDnc,
                'to_do_not_contact' => false,
                'from_consent_status' => $antesConsent,
                'to_consent_status' => MarketingLead::CONSENT_GRANTED,
                'source' => $source,
                'reason' => mb_substr(trim((string) $reason), 0, 300),
                'actor_admin_id' => $actorAdminId,
                'evidence' => $veredicto['evidence'],
                'marketing_message_id' => $messageId,
            ]);

            ChannelLog::warning('marketing.consent.reopened', [
                'marketing_lead_id' => (int) $fresco->id,
                'source' => $source,
                'actor_admin_id' => $actorAdminId,
                'consent_event_id' => (int) $evento->id,
            ]);

            $lead->refresh();

            return ['status' => 'reopened', 'reason' => null, 'event_id' => (int) $evento->id];
        });
    }
}
