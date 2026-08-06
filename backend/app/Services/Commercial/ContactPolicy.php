<?php

namespace App\Services\Commercial;

use App\Models\CommercialOpportunity;
use App\Models\MarketingMessage;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se le puede escribir a alguien por iniciativa nuestra.
 *
 * Esta clase es lo único que separa un sistema comercial de una máquina de
 * molestar. El motor de oportunidades es bueno encontrando razones para
 * escribir; sin un freno explícito, una persona con tres oportunidades abiertas
 * —renovación, app sin vincular y referidos— recibiría tres mensajes el mismo
 * martes y bloquearía el número.
 *
 * Las cuatro barreras, en orden de importancia:
 *
 *  1. **Opt-out**: si pidió que no le escriban, no se le escribe. Punto.
 *  2. **Horario**: nadie quiere una oferta del gimnasio a las 23:40. Se respeta
 *     la hora de Neiva, no la del servidor.
 *  3. **Frecuencia**: un máximo semanal y un mínimo de horas entre mensajes,
 *     contados sobre TODAS las oportunidades juntas. Contarlos por oportunidad
 *     sería justo el agujero por el que se cuela el acoso.
 *  4. **Ventana de WhatsApp**: pasadas 24 h desde el último mensaje del
 *     cliente, Meta exige plantilla aprobada. Un texto libre ahí no llega y
 *     además cuenta como error de entrega.
 *
 * Todo lo que se rechaza deja constancia del motivo: un mensaje que no sale
 * tiene que poder explicarse igual que uno que sí.
 */
class ContactPolicy
{
    /**
     * ¿Se puede contactar AHORA por esta oportunidad?
     *
     * @return array{allowed:bool, reason:?string, retry_at:?Carbon, requires_template:bool}
     */
    public function check(CommercialOpportunity $opportunity, CommercialSubject $subject): array
    {
        // 1. Opt-out. No admite excepciones ni por valor de la oportunidad.
        if (! $subject->isContactable()) {
            return $this->deny('do_not_contact', null);
        }

        // Una conversación que lleva una persona no recibe nada automático.
        if ($subject->needsHuman) {
            return $this->deny('human_in_control', null);
        }

        if (! $opportunity->isActionable()) {
            return $this->deny('opportunity_not_actionable', $opportunity->act_after);
        }

        // 2. Frecuencia: primero el mínimo entre mensajes, después el techo
        //    semanal. Se cuenta sobre la persona, no sobre la oportunidad.
        if ($frequency = $this->frequencyDenial($subject)) {
            return $frequency;
        }

        // 3. Horario local. Se comprueba al final porque solo aplaza: si algo
        //    de lo anterior ya lo prohibió, el horario es irrelevante.
        if ($quiet = $this->quietHoursDenial()) {
            return $quiet;
        }

        return [
            'allowed' => true,
            'reason' => null,
            'retry_at' => null,
            // 4. Ventana de WhatsApp: no impide contactar, pero obliga a
            //    plantilla. Quien envía tiene que saberlo antes de redactar.
            'requires_template' => $this->requiresTemplate($subject),
        ];
    }

    /**
     * Techo de mensajes proactivos. Cuenta los salientes de la IA en la ventana
     * correspondiente; los mensajes de un asesor humano NO cuentan, porque una
     * conversación viva no es una campaña.
     */
    private function frequencyDenial(CommercialSubject $subject): ?array
    {
        $conversationId = $this->conversationIdFor($subject);

        if ($conversationId === null) {
            return null; // sin conversación previa no hay nada que limitar
        }

        $limits = (array) config('commercial.contact_limits');
        $minHours = (int) ($limits['min_hours_between'] ?? 48);
        $maxWeek = (int) ($limits['max_proactive_per_week'] ?? 2);

        $proactive = MarketingMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->where('sender_type', MarketingMessage::SENDER_AI)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at')
            ->get(['created_at', 'metadata']);

        // Solo cuentan los PROACTIVOS. Una respuesta a algo que preguntó el
        // cliente no consume cuota: responder no es perseguir.
        $proactive = $proactive->filter(
            fn ($m) => (data_get($m->metadata, 'kind') ?? '') !== 'reply',
        );

        if ($proactive->isEmpty()) {
            return null;
        }

        $last = $proactive->first()->created_at;
        if ($last !== null && $last->diffInHours(now()) < $minHours) {
            return $this->deny(
                'too_soon_since_last_contact',
                $last->copy()->addHours($minHours),
            );
        }

        if ($proactive->count() >= $maxWeek) {
            $oldest = $proactive->last()->created_at;

            return $this->deny(
                'weekly_contact_limit_reached',
                $oldest?->copy()->addDays(7) ?? now()->addDays(7),
            );
        }

        return null;
    }

    /**
     * Horas de silencio en la zona horaria de Neiva. El servidor puede estar en
     * UTC; usar su hora mandaría mensajes de madrugada sin que nadie lo note.
     */
    private function quietHoursDenial(): ?array
    {
        $limits = (array) config('commercial.contact_limits');
        $timezone = (string) ($limits['timezone'] ?? 'America/Bogota');
        $start = (int) ($limits['quiet_hours_start'] ?? 21);
        $end = (int) ($limits['quiet_hours_end'] ?? 8);

        $localNow = now()->setTimezone($timezone);
        $hour = (int) $localNow->format('G');

        // La franja cruza la medianoche (21:00 → 08:00), así que la condición
        // es una disyunción y no un intervalo.
        $isQuiet = $start > $end
            ? ($hour >= $start || $hour < $end)
            : ($hour >= $start && $hour < $end);

        if (! $isQuiet) {
            return null;
        }

        $retryAt = $localNow->copy()->setTime($end, 0);
        if ($retryAt->lessThanOrEqualTo($localNow)) {
            $retryAt->addDay();
        }

        return $this->deny('quiet_hours', $retryAt->setTimezone(config('app.timezone')));
    }

    /**
     * ¿Hace falta plantilla aprobada?
     *
     * Meta cierra la ventana de servicio 24 h después del último mensaje del
     * cliente. Pasado ese punto, un texto libre no se entrega y devuelve el
     * error 131047.
     */
    public function requiresTemplate(CommercialSubject $subject): bool
    {
        if ($subject->lastInboundAt === null) {
            return true; // nunca escribió: solo cabe plantilla
        }

        return $subject->lastInboundAt->diffInHours(now()) >= 24;
    }

    private function conversationIdFor(CommercialSubject $subject): ?int
    {
        if ($subject->lead === null || ! Schema::hasTable('marketing_conversations')) {
            return null;
        }

        $row = DB::table('marketing_conversations')
            ->where('lead_id', $subject->lead->id)
            ->orderByDesc('id')
            ->first(['id']);

        return $row->id ?? null;
    }

    /** @return array{allowed:bool, reason:string, retry_at:?Carbon, requires_template:bool} */
    private function deny(string $reason, ?Carbon $retryAt): array
    {
        ChannelLog::info('commercial.contact.denied', [
            'reason' => $reason,
            'retry_at' => $retryAt?->toIso8601String(),
        ]);

        return [
            'allowed' => false,
            'reason' => $reason,
            'retry_at' => $retryAt,
            'requires_template' => false,
        ];
    }
}
