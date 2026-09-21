<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingAgentAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Carbon;

/**
 * LA SOLICITUD DE UN DÍA DE CORTESÍA. REGISTRAR NO ES AGENDAR.
 *
 * Iron Body invita a conocer el gimnasio un día a quien todavía está
 * decidiendo. Lo que ULTRON puede hacer es recoger el día y la hora y DEJARLO
 * ANOTADO; quien confirma es una persona del equipo. Esa frontera no es un
 * matiz de redacción: si el agente dijera «quedaste agendado», alguien se
 * presentaría en el gimnasio un día que nadie preparó.
 *
 * Por eso esto NO escribe en `marketing_appointments`. Esa tabla significa
 * «hay una cita», y su servicio fuerza `status = scheduled`, que la interfaz
 * rotula «Agendada». Escribir ahí sería afirmar lo que no ha pasado.
 *
 * Se escribe en `marketing_agent_actions`, que ya significa exactamente esto:
 * la IA propone, una persona aprueba y ejecuta, y queda quién y cuándo. Cuando
 * el equipo la ejecuta desde su panel, el camino que ya existe crea la cita de
 * verdad. O sea: solicitada → agendada → cumplida o cancelada, sin una tabla
 * nueva y sin una máquina de estados nueva.
 *
 * El vocabulario del encargo traducido al que ya existe:
 *   requested  = suggested   (anotada, sin confirmar)
 *   confirmed  = executed    (una persona la aprobó y nació la cita)
 *   cancelled  = cancelled
 * El estado vive en UN sitio, la fila; no se duplica en el payload para que no
 * puedan contradecirse.
 */
class CourtesyRequestService
{
    /** Lo que dura una visita de cortesía, para que el equipo vea una franja y no un punto. */
    private const MINUTOS = 60;

    private const TITULO = 'Día de cortesía';

    /**
     * Deja anotada la solicitud, o actualiza la que ya había.
     *
     * Idempotente por CONVERSACIÓN y no por fecha: «el sábado», «mejor el
     * domingo» y «no, el lunes» son la misma persona cambiando de idea, no
     * tres visitas. Tres filas abiertas obligarían al equipo a adivinar cuál
     * vale.
     *
     * @param  string  $cuando  ISO 8601 ya validado por {@see CourtesyAuthority}
     * @return array{action_id:int, status:string, nueva:bool, scheduled_at:string}
     */
    public function request(
        MarketingLead $lead,
        MarketingConversation $conversation,
        ?MarketingMessage $message,
        string $cuando,
        ?string $fecha = null,
        ?string $hora = null,
    ): array {
        $abierta = $this->openFor($conversation);

        $payload = [
            'type' => 'visit',
            'title' => self::TITULO,
            'scheduled_at' => Carbon::parse($cuando)->setTimezone(config('app.timezone'))->toDateTimeString(),
            'duration_minutes' => self::MINUTOS,
            'notes' => 'Solicitud de día de cortesía recogida por ULTRON en el chat. Pendiente de confirmar por el equipo.',
            // Lo que el encargo pide guardar, tal cual, además de lo que la
            // ejecución necesita: la fecha y la hora COMO LAS PIDIÓ la persona,
            // en hora de Neiva, que es lo que el equipo va a leer.
            'requested_date' => $fecha,
            'requested_time' => $hora,
            'requested_at_local' => Carbon::parse($cuando)->setTimezone(BusinessClock::TZ)->toDateTimeString(),
            'source' => 'ultron',
        ];

        if ($abierta !== null) {
            $antes = data_get($abierta->payload, 'requested_at_local');

            /*
             * CAMBIAR EL DÍA REABRE LA APROBACIÓN. SIEMPRE.
             *
             * `openFor()` devuelve las filas abiertas, y «abierta» incluye las
             * que un humano YA APROBÓ. Sin esto, ULTRON reescribía el día de
             * una fila aprobada y la dejaba aprobada: el panel puede ejecutar
             * desde `approved`, así que nacía una cita real un domingo que
             * nadie miró, y el acta seguía diciendo que la aprobó el admin que
             * había aprobado el sábado. Es la misma clase de fallo que
             * persigue la invariante de promesas —un registro que afirma algo
             * que no ocurrió— sólo que en el expediente en vez de en el texto.
             *
             * `marketing_agent_actions` significa «la IA propone, una persona
             * aprueba y ejecuta». Editar lo aprobado sin reabrirlo convierte
             * esa frase en mentira, así que el día nuevo vuelve a la cola:
             * `suggested`, y sin firma de nadie.
             */
            $reabre = $abierta->status !== MarketingAgentAction::STATUS_SUGGESTED;

            $abierta->forceFill(array_merge([
                'payload' => $payload,
                'marketing_message_id' => $message?->id ?? $abierta->marketing_message_id,
            ], $reabre ? [
                'status' => MarketingAgentAction::STATUS_SUGGESTED,
                'approved_by_admin_id' => null,
                'approved_at' => null,
            ] : []))->save();

            ChannelLog::info('ultron.courtesy.updated', [
                'conversation_id' => (int) $conversation->id,
                'action_id' => (int) $abierta->id,
                'antes' => $antes,
                'ahora' => $payload['requested_at_local'],
                'reabrio_aprobacion' => $reabre,
            ]);

            return ['action_id' => (int) $abierta->id, 'status' => (string) $abierta->status, 'nueva' => false, 'scheduled_at' => $payload['requested_at_local']];
        }

        $accion = MarketingAgentAction::create([
            'marketing_lead_id' => $lead->id,
            'marketing_conversation_id' => $conversation->id,
            'marketing_message_id' => $message?->id,
            'suggested_by' => 'ai',
            'action_type' => MarketingAgentAction::TYPE_CREATE_APPOINTMENT,
            'status' => MarketingAgentAction::STATUS_SUGGESTED,
            'requires_approval' => true,
            'title' => self::TITULO,
            'reason' => 'La persona pidió conocer el gimnasio antes de decidirse.',
            'payload' => $payload,
        ]);

        ChannelLog::info('ultron.courtesy.requested', [
            'conversation_id' => (int) $conversation->id,
            'action_id' => (int) $accion->id,
            'cuando' => $payload['requested_at_local'],
        ]);

        return ['action_id' => (int) $accion->id, 'status' => (string) $accion->status, 'nueva' => true, 'scheduled_at' => $payload['requested_at_local']];
    }

    /**
     * Cancela la solicitud abierta. Devuelve null si no había ninguna.
     *
     * Cancelar es un cambio de estado, nunca un borrado: si alguien pregunta
     * mañana por qué no vino, la fila tiene que seguir ahí para contarlo.
     */
    public function cancel(MarketingConversation $conversation, ?string $motivo = null): ?array
    {
        $abierta = $this->openFor($conversation);

        if ($abierta === null) {
            return null;
        }

        $abierta->forceFill([
            'status' => MarketingAgentAction::STATUS_CANCELLED,
            'rejection_reason' => $motivo ?? 'La persona canceló la visita por el chat.',
        ])->save();

        ChannelLog::info('ultron.courtesy.cancelled', [
            'conversation_id' => (int) $conversation->id,
            'action_id' => (int) $abierta->id,
        ]);

        return ['action_id' => (int) $abierta->id, 'status' => (string) $abierta->status];
    }

    /** La solicitud viva de esta conversación, si la hay. */
    public function openFor(MarketingConversation $conversation): ?MarketingAgentAction
    {
        return MarketingAgentAction::query()
            ->where('marketing_conversation_id', $conversation->id)
            ->where('action_type', MarketingAgentAction::TYPE_CREATE_APPOINTMENT)
            ->whereIn('status', MarketingAgentAction::OPEN_STATUSES)
            ->where('payload->source', 'ultron')
            ->latest('id')
            ->first();
    }

    /**
     * La ÚLTIMA solicitud de cortesía de la conversación, en cualquier estado.
     *
     * `openFor()` sólo ve las vivas, y eso no basta para decidir si una frase
     * miente: una solicitud cancelada o ya cumplida tampoco autoriza a decir
     * «te esperamos el 26». Lo que importa para callar una afirmación es que
     * esta conversación TENGA una cortesía de por medio; cuál es su estado
     * decide qué se dice en su lugar, no si se calla.
     */
    public function latestFor(MarketingConversation $conversation): ?MarketingAgentAction
    {
        return MarketingAgentAction::query()
            ->where('marketing_conversation_id', $conversation->id)
            ->where('action_type', MarketingAgentAction::TYPE_CREATE_APPOINTMENT)
            ->where('payload->source', 'ultron')
            ->latest('id')
            ->first();
    }

    /**
     * El acta de la solicitud, escrita por Laravel a partir de la FILA.
     *
     * Ésta es la frase que la persona lee sobre el estado de su visita, y no
     * la escribe el modelo: sale de `requested_at_local`, que
     * es lo que el equipo va a ver en su panel. Mientras la solicitud siga
     * abierta —nadie la ha confirmado— el acta dice exactamente eso y nada
     * más, así que no puede envejecer ni exagerar.
     *
     * Devuelve null cuando no hay nada que certificar: sin solicitud abierta,
     * o sin fecha legible en el payload.
     */
    public function actaDe(?MarketingAgentAction $accion): ?string
    {
        if ($accion === null || ! in_array($accion->status, MarketingAgentAction::OPEN_STATUSES, true)) {
            return null;
        }

        $cuando = data_get($accion->payload, 'requested_at_local');
        if (! is_string($cuando) || $cuando === '') {
            return null;
        }

        try {
            $d = Carbon::parse($cuando, BusinessClock::TZ);
        } catch (\Throwable) {
            return null;
        }

        /*
         * Una fecha que ya pasó no se anuncia como pendiente.
         *
         * Nada caduca las solicitudes `suggested`: si nadie la aprueba ni la
         * cancela, la fila sigue abierta para siempre. Sin esto, el día 30 se
         * le decía a la persona «tu solicitud para el 26 está pendiente», que
         * es un acta que envejeció sin que nadie la tocara. Se devuelve null y
         * el respaldo genérico dice lo mismo sin fecha.
         */
        if ($d->isPast()) {
            return null;
        }

        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $fecha = $dias[$d->dayOfWeek].' '.$d->day.' de '.$meses[$d->month - 1];

        return 'Dejé registrada tu solicitud de cortesía para el '.$fecha.' a las '.$d->format('H:i')
            .'. El equipo de Iron Body la revisará para tener todo preparado.';
    }
}
