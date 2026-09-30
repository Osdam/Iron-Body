<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingAgentAction;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
        // La solicitud abierta del LEAD, venga de la conversación que venga:
        // quien vuelve y pide otro día mueve SU solicitud, no abre una segunda.
        $abierta = $this->openFor($conversation) ?? $this->openForLead($lead);

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
        // Igual que al pedir: se cancela la solicitud abierta del lead, también la de otra conversación.
        $abierta = $this->openFor($conversation) ?? ($conversation->lead !== null ? $this->openForLead($conversation->lead) : null);

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
     * La solicitud de cortesía ABIERTA del lead, venga de la conversación que
     * venga. La guarda de cortesía decide con ella si una frase de «quedaste
     * agendado» miente: si el lead tiene una solicitud sin confirmar en otra
     * conversación, esa frase habla de ella igual.
     */
    /** «miércoles 30 de septiembre a las 14:00»: el día y la hora como se le dicen a la persona. */
    public function humanDe(Carbon $d): string
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $d = $d->copy()->setTimezone(BusinessClock::TZ);

        return $dias[$d->dayOfWeek].' '.$d->day.' de '.$meses[$d->month - 1].' a las '.$d->format('H:i');
    }

    /** Cuántos días atrás sigue siendo un compromiso que se puede recordar. */
    public const DIAS_DE_MEMORIA = 7;

    public function openForLead(MarketingLead $lead): ?MarketingAgentAction
    {
        return MarketingAgentAction::query()
            ->where('marketing_lead_id', $lead->id)
            ->where('action_type', MarketingAgentAction::TYPE_CREATE_APPOINTMENT)
            ->whereIn('status', MarketingAgentAction::OPEN_STATUSES)
            ->where('payload->source', 'ultron')
            ->latest('id')
            ->first();
    }

    /**
     * Las citas de VISITA confirmadas del lead que aún no han pasado: sólo
     * una visita hace verdadera una frase sobre la visita. Una llamada o una
     * valoración agendada por el equipo no es la visita de cortesía, y
     * contarla le decía a alguien «te esperamos» por una llamada telefónica.
     *
     * @return Collection<int,MarketingAppointment>
     */
    public function confirmedVisitsFor(MarketingLead $lead, ?Carbon $desde = null)
    {
        return MarketingAppointment::query()
            ->where('marketing_lead_id', $lead->id)
            ->where('type', MarketingAppointment::TYPE_VISIT)
            ->where('status', MarketingAppointment::STATUS_SCHEDULED)
            ->where('scheduled_at', '>=', ($desde ?? Carbon::now())->copy()->setTimezone('UTC'))
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get();
    }

    /**
     * Los compromisos del LEAD, vengan de la conversación que vengan: la
     * visita de cortesía de la última semana en adelante. Es lo que permite
     * contestar «¿a qué hora era mi visita?» en una conversación nueva, y lo
     * que el modelo recibe como hecho en customer.commitments. Van primero los
     * que aún no pasaron (del más próximo al más lejano) y después los
     * pasados (del más reciente al más viejo): el primero es el que importa.
     *
     * `status` es lo que se le puede DECIR a la persona, con el mismo
     * criterio que la guarda de cortesía: `confirmed` sólo cuando existe la
     * cita real de VISITA (el equipo ejecutó la solicitud); `requested`
     * mientras no, también si alguien del equipo ya la aprobó en el panel pero
     * la cita todavía no existe. `past` es true cuando su hora ya pasó: sigue
     * siendo lo que la persona pidió, pero ya no se le puede decir «te
     * esperamos».
     *
     * @return array<int,array{kind:string,status:string,past:bool,scheduled_at:string,date:string,time:string,weekday:string,human:string,conversation_id:int}>
     */
    public function commitmentsForLead(MarketingLead $lead): array
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $hoy = Carbon::now(BusinessClock::TZ)->startOfDay();
        $salida = [];

        /*
         * Primero la cita REAL: cuando el equipo ejecuta la solicitud desde el
         * panel, la acción pasa a `executed` y nace la cita en
         * marketing_appointments. Sin mirar aquí, a quien SÍ tiene visita
         * confirmada se le decía «no tengo nada registrado».
         */
        $ahora = Carbon::now(BusinessClock::TZ);
        // Hasta una semana atrás: «¿a qué hora era mi visita?» también se pregunta al día siguiente.
        $desde = $hoy->copy()->subDays(self::DIAS_DE_MEMORIA);
        foreach ($this->confirmedVisitsFor($lead, $desde) as $cita) {
            $d = Carbon::parse($cita->scheduled_at)->setTimezone(BusinessClock::TZ);
            $salida[] = [
                'kind' => 'courtesy_visit',
                'status' => 'confirmed',
                // La hora ya pasó: no se le dice «te esperamos».
                'past' => $d->isBefore($ahora),
                'scheduled_at' => $d->toIso8601String(),
                'date' => $d->toDateString(),
                'time' => $d->format('H:i'),
                'weekday' => $dias[$d->dayOfWeek],
                'human' => $dias[$d->dayOfWeek].' '.$d->day.' de '.$meses[$d->month - 1].' a las '.$d->format('H:i'),
                'conversation_id' => (int) $cita->marketing_conversation_id,
            ];
        }

        // Sin límite antes de filtrar por fecha: con tres solicitudes ya
        // pasadas, una futura más antigua se perdía. Abiertas hay pocas.
        $acciones = MarketingAgentAction::query()
            ->where('marketing_lead_id', $lead->id)
            ->where('action_type', MarketingAgentAction::TYPE_CREATE_APPOINTMENT)
            ->where('payload->source', 'ultron')
            ->whereIn('status', MarketingAgentAction::OPEN_STATUSES)
            ->latest('id')
            ->limit(50)
            ->get();

        foreach ($acciones as $accion) {
            $cuando = data_get($accion->payload, 'requested_at_local');
            if (! is_string($cuando) || $cuando === '') {
                continue;
            }
            try {
                $d = Carbon::parse($cuando, BusinessClock::TZ);
            } catch (\Throwable) {
                continue;
            }
            // Más de una semana atrás ya no es algo que se recuerde por aquí.
            if ($d->isBefore($desde)) {
                continue;
            }
            $salida[] = [
                'kind' => 'courtesy_visit',
                'status' => 'requested',
                'past' => $d->isBefore($ahora),
                'scheduled_at' => $d->toIso8601String(),
                'date' => $d->toDateString(),
                'time' => $d->format('H:i'),
                'weekday' => $dias[$d->dayOfWeek],
                'human' => $dias[$d->dayOfWeek].' '.$d->day.' de '.$meses[$d->month - 1].' a las '.$d->format('H:i'),
                'conversation_id' => (int) $accion->marketing_conversation_id,
            ];
        }

        // Primero las que aún no pasaron, de la más próxima a la más lejana;
        // después las pasadas, de la más reciente a la más vieja. Así el
        // primero es siempre el que importa.
        usort($salida, fn (array $a, array $b) => $a['past'] !== $b['past']
            ? ($a['past'] ? 1 : -1)
            : ($a['past'] ? strcmp($b['scheduled_at'], $a['scheduled_at']) : strcmp($a['scheduled_at'], $b['scheduled_at'])));

        return array_slice($salida, 0, 3);
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
