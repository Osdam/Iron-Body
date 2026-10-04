<?php

namespace App\Services\Marketing\Ultron;

use App\Exceptions\AppointmentException;
use App\Models\MarketingAgentAction;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\MarketingAppointmentService;
use App\Services\Observability\ChannelLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LA SOLICITUD DE UN DÍA DE CORTESÍA. REGISTRAR NO ES AGENDAR.
 *
 * Iron Body invita a conocer el gimnasio un día a quien todavía está
 * decidiendo. Lo que ULTRON puede hacer es recoger el día y la hora y DEJARLO
 * ANOTADO; quien confirma es una persona del equipo. Esa frontera no es un
 * matiz de redacción: si el agente dijera «quedaste agendado», alguien se
 * presentaría en el gimnasio un día que nadie preparó.
 *
 * La solicitud vive en la AGENDA COMERCIAL (`marketing_appointments`), la misma
 * que mira el equipo, como una cita en estado `requested`: solicitada y SIN
 * confirmar. Confirmarla es pasarla a `scheduled` desde la agenda, que es lo
 * que ya significaba «confirmada» para la guarda de promesas. Antes vivía en
 * `marketing_agent_actions` y solo se convertía en cita cuando alguien la
 * ejecutaba desde un panel aparte: el equipo no la veía en su agenda.
 *
 *   requested  = la cita solicitada (anotada, sin confirmar)
 *   confirmed  = la cita `scheduled` (una persona la confirmó)
 *   cancelled  = la cita `cancelled` (sin borrar nada)
 *
 * Las transiciones las decide {@see MarketingAppointmentService}, que es quien
 * manda sobre la agenda; aquí solo se decide QUÉ cita es la de esta persona.
 */
class CourtesyRequestService
{
    /** Lo que dura una visita de cortesía, para que el equipo vea una franja y no un punto. */
    private const MINUTOS = 60;

    private const TITULO = 'Día de cortesía';

    /** Cuántos días atrás sigue siendo un compromiso que se puede recordar. */
    public const DIAS_DE_MEMORIA = 7;

    public function __construct(private ?MarketingAppointmentService $agenda = null) {}

    private function agenda(): MarketingAppointmentService
    {
        return $this->agenda ??= app(MarketingAppointmentService::class);
    }

    /**
     * Deja anotada la solicitud, o mueve la que ya había.
     *
     * Idempotente por PERSONA y no por fecha: «el sábado», «mejor el domingo» y
     * «no, el lunes» son la misma persona cambiando de idea, no tres visitas.
     * Y si su visita ya estaba CONFIRMADA, cambiar de día mueve esa misma cita y
     * la devuelve a solicitada: nunca quedan dos activas, y el día nuevo lo
     * vuelve a confirmar una persona.
     *
     * @param  string  $cuando  ISO 8601 ya validado por {@see CourtesyAuthority}
     * @return array{appointment_id:int, status:string, nueva:bool, changed:bool, scheduled_at:string}
     */
    public function request(
        MarketingLead $lead,
        MarketingConversation $conversation,
        ?MarketingMessage $message,
        string $cuando,
        ?string $fecha = null,
        ?string $hora = null,
    ): array {
        $momento = Carbon::parse($cuando)->utc();
        $local = $momento->copy()->setTimezone(BusinessClock::TZ)->toDateTimeString();
        // Lo que el encargo pide guardar, tal cual: la fecha y la hora COMO LAS
        // PIDIÓ la persona, en hora de Neiva, que es lo que el equipo va a leer.
        $pedido = [
            'requested_date' => $fecha,
            'requested_time' => $hora,
            'requested_at_local' => $local,
            'message_id' => $message?->id,
        ];

        return DB::transaction(function () use ($lead, $conversation, $momento, $local, $pedido): array {
            // Una persona a la vez: dos reintentos que lleguen juntos se ordenan
            // aquí, y el segundo encuentra la solicitud que dejó el primero.
            MarketingLead::query()->whereKey($lead->id)->lockForUpdate()->first();

            // La candidata se elige CON cerrojo: así decide y escribe sobre la
            // misma fila, y una transición del CRM a la vez espera a que termine.
            $suya = $this->openFor($conversation, true) ?? $this->openForLead($lead, true) ?? $this->confirmedVisitsFor($lead, null, true)->first();

            if ($suya === null) {
                return $this->nuevaSolicitud($lead, $conversation, $momento, $local, $pedido);
            }

            $antes = $suya->scheduled_at?->copy()->setTimezone(BusinessClock::TZ)->toDateTimeString();
            if ($antes === $local) {
                // La misma hora otra vez: ni se mueve ni se des-confirma una visita
                // confirmada. Pero queda anotado que esta conversación la gestionó:
                // si el equipo la cancela después, la guarda tiene que saberlo.
                $this->anotarConversacion($suya, $conversation);

                return ['appointment_id' => (int) $suya->id, 'status' => (string) $suya->status, 'nueva' => false, 'changed' => false, 'scheduled_at' => $local];
            }

            try {
                $cita = $this->agenda()->reschedule($suya, $momento, null, null, reabrir: true, by: MarketingAppointment::SOURCE_ULTRON)['appointment'];
            } catch (AppointmentException $e) {
                if ($e->errorCode !== 'appointment_closed') {
                    throw $e;
                }

                // El equipo la cerró (canceló o cumplió) justo entre la búsqueda
                // y la escritura: el día nuevo que pide la persona es entonces
                // una solicitud nueva, no un turno perdido.
                return $this->nuevaSolicitud($lead, $conversation, $momento, $local, $pedido);
            }
            $cita->forceFill($this->gestionadaPorUltron($cita, $conversation, array_merge($cita->metadata ?? [], $pedido)))->save();

            ChannelLog::info('ultron.courtesy.updated', [
                'conversation_id' => (int) $conversation->id,
                'appointment_id' => (int) $cita->id,
                'antes' => $antes,
                'ahora' => $local,
            ]);

            return ['appointment_id' => (int) $cita->id, 'status' => (string) $cita->status, 'nueva' => false, 'changed' => true, 'scheduled_at' => $local];
        });
    }

    /**
     * @param  array<string,mixed>  $pedido
     * @return array{appointment_id:int, status:string, nueva:bool, changed:bool, scheduled_at:string}
     */
    private function nuevaSolicitud(MarketingLead $lead, MarketingConversation $conversation, Carbon $momento, string $local, array $pedido): array
    {
        $cita = $this->agenda()->createRequest(
            $lead, $conversation, $momento, self::MINUTOS, self::TITULO,
            'Solicitud de día de cortesía recogida por ULTRON en el chat. Pendiente de confirmar por el equipo.',
            $pedido + ['ultron_conversation_ids' => [(int) $conversation->id]], MarketingAppointment::SOURCE_ULTRON,
        );

        ChannelLog::info('ultron.courtesy.requested', [
            'conversation_id' => (int) $conversation->id,
            'appointment_id' => (int) $cita->id,
            'cuando' => $local,
        ]);

        return ['appointment_id' => (int) $cita->id, 'status' => (string) $cita->status, 'nueva' => true, 'changed' => true, 'scheduled_at' => $local];
    }

    /**
     * Lo que se escribe en una cita que ULTRON acaba de tocar desde esta
     * conversación —moverla, repetir su hora o cancelarla—: su metadata con la
     * conversación anotada en `ultron_conversation_ids` y, si la cita no tenía
     * conversación (el equipo la agendó sin chat), esta.
     *
     * La guarda de promesas pregunta POR CONVERSACIÓN si hubo una cortesía
     * ({@see latestFor()}): una visita del equipo que ULTRON movió desde otra
     * conversación, o cuya hora solo repitió, también cuenta.
     *
     * @param  array<string,mixed>  $metadata  la metadata ya completa
     * @return array<string,mixed>
     */
    private function gestionadaPorUltron(MarketingAppointment $cita, MarketingConversation $conversation, array $metadata): array
    {
        $ids = array_map('intval', (array) ($metadata['ultron_conversation_ids'] ?? []));
        if (! in_array((int) $conversation->id, $ids, true)) {
            $ids[] = (int) $conversation->id;
        }

        return array_filter([
            'metadata' => array_merge($metadata, ['ultron_conversation_ids' => array_values($ids)]),
            'marketing_conversation_id' => $cita->marketing_conversation_id === null ? $conversation->id : null,
        ], fn ($v) => $v !== null);
    }

    /** Anota la conversación en la cita si todavía no lo estaba; no toca nada más. */
    private function anotarConversacion(MarketingAppointment $cita, MarketingConversation $conversation): void
    {
        $anotadas = array_map('intval', (array) data_get($cita->metadata, 'ultron_conversation_ids', []));
        if (! in_array((int) $conversation->id, $anotadas, true) || $cita->marketing_conversation_id === null) {
            $cita->forceFill($this->gestionadaPorUltron($cita, $conversation, $cita->metadata ?? []))->save();
        }
    }

    /**
     * Cancela la solicitud SIN confirmar de esta persona. Devuelve null si no
     * había ninguna.
     *
     * Una visita ya CONFIRMADA no la cancela ULTRON: la invariante de promesas
     * del commit solo autoriza decir «cancelé tu visita» cuando hay una
     * solicitud abierta, y la confirmada la cancela el equipo desde la agenda.
     *
     * Cancelar es un cambio de estado, nunca un borrado: si alguien pregunta
     * mañana por qué no vino, la fila tiene que seguir ahí para contarlo.
     *
     * @return array{appointment_id:int, status:string}|null
     */
    public function cancel(MarketingConversation $conversation, ?string $motivo = null): ?array
    {
        $lead = $conversation->lead;

        return DB::transaction(function () use ($conversation, $lead, $motivo): ?array {
            if ($lead !== null) {
                MarketingLead::query()->whereKey($lead->id)->lockForUpdate()->first();
            }

            $suya = $this->openFor($conversation, true) ?? ($lead !== null ? $this->openForLead($lead, true) : null);

            if ($suya === null) {
                return null;
            }

            $cita = $this->agenda()->cancel($suya, $motivo ?? 'La persona canceló la visita por el chat.', null, MarketingAppointment::SOURCE_ULTRON)['appointment'];
            $this->anotarConversacion($cita, $conversation);

            ChannelLog::info('ultron.courtesy.cancelled', [
                'conversation_id' => (int) $conversation->id,
                'appointment_id' => (int) $cita->id,
            ]);

            return ['appointment_id' => (int) $cita->id, 'status' => (string) $cita->status];
        });
    }

    /** La solicitud viva (sin confirmar) de esta conversación, si la hay. */
    public function openFor(MarketingConversation $conversation, bool $bloquear = false): ?MarketingAppointment
    {
        return $this->solicitudes()
            ->where('marketing_conversation_id', $conversation->id)
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->latest('id')
            ->first();
    }

    /**
     * La solicitud de cortesía ABIERTA del lead, venga de la conversación que
     * venga. La guarda de cortesía decide con ella si una frase de «quedaste
     * agendado» miente: si el lead tiene una solicitud sin confirmar en otra
     * conversación, esa frase habla de ella igual.
     */
    public function openForLead(MarketingLead $lead, bool $bloquear = false): ?MarketingAppointment
    {
        return $this->solicitudes()
            ->where('marketing_lead_id', $lead->id)
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->latest('id')
            ->first();
    }

    /** «miércoles 30 de septiembre a las 14:00»: el día y la hora como se le dicen a la persona. */
    public function humanDe(Carbon $d): string
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $d = $d->copy()->setTimezone(BusinessClock::TZ);

        return $dias[$d->dayOfWeek].' '.$d->day.' de '.$meses[$d->month - 1].' a las '.$d->format('H:i');
    }

    /**
     * Las citas de VISITA confirmadas del lead que aún no han pasado: sólo
     * una visita hace verdadera una frase sobre la visita. Una llamada o una
     * valoración agendada por el equipo no es la visita de cortesía, y
     * contarla le decía a alguien «te esperamos» por una llamada telefónica.
     *
     * @return Collection<int,MarketingAppointment>
     */
    public function confirmedVisitsFor(MarketingLead $lead, ?Carbon $desde = null, bool $bloquear = false)
    {
        return MarketingAppointment::query()
            ->where('marketing_lead_id', $lead->id)
            ->where('type', MarketingAppointment::TYPE_VISIT)
            ->whereIn('status', MarketingAppointment::CONFIRMED_STATUSES)
            ->where('scheduled_at', '>=', ($desde ?? Carbon::now())->copy()->setTimezone('UTC'))
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
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
     * `status` es lo que se le puede DECIR a la persona, con el mismo criterio
     * que la guarda de cortesía: `confirmed` sólo cuando la cita de VISITA está
     * confirmada (`scheduled`); `requested` mientras siga solicitada. `past` es
     * true cuando su hora ya pasó: sigue siendo lo que la persona pidió, pero ya
     * no se le puede decir «te esperamos».
     *
     * @return array<int,array{kind:string,status:string,past:bool,scheduled_at:string,date:string,time:string,weekday:string,human:string,conversation_id:int}>
     */
    public function commitmentsForLead(MarketingLead $lead): array
    {
        $hoy = Carbon::now(BusinessClock::TZ)->startOfDay();
        $ahora = Carbon::now(BusinessClock::TZ);
        // Hasta una semana atrás: «¿a qué hora era mi visita?» también se pregunta al día siguiente.
        $desde = $hoy->copy()->subDays(self::DIAS_DE_MEMORIA);
        $salida = [];

        foreach ($this->confirmedVisitsFor($lead, $desde) as $cita) {
            $salida[] = $this->compromiso($cita, 'confirmed', $ahora);
        }

        // Sin límite corto antes de filtrar por fecha: con tres solicitudes ya
        // pasadas, una futura más antigua se perdía. Abiertas hay pocas.
        $solicitudes = $this->solicitudes()
            ->where('marketing_lead_id', $lead->id)
            ->latest('id')
            ->limit(50)
            ->get();

        foreach ($solicitudes as $cita) {
            $d = Carbon::parse($cita->scheduled_at)->setTimezone(BusinessClock::TZ);
            // Más de una semana atrás ya no es algo que se recuerde por aquí.
            if ($d->isBefore($desde)) {
                continue;
            }
            $salida[] = $this->compromiso($cita, 'requested', $ahora);
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
    public function latestFor(MarketingConversation $conversation): ?MarketingAppointment
    {
        // La que ULTRON creó en esta conversación, o cualquiera que gestionó
        // DESDE ella: una del equipo que movió o canceló, o una suya que la
        // persona retomó desde una conversación nueva.
        return MarketingAppointment::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('marketing_conversation_id', $conversation->id)->where('source', MarketingAppointment::SOURCE_ULTRON))
                ->orWhereJsonContains('metadata->ultron_conversation_ids', (int) $conversation->id))
            ->latest('id')
            ->first();
    }

    /**
     * Si esta conversación tuvo una cortesía ANTES de que viviera en la agenda.
     * Las de entonces se quedaron en `marketing_agent_actions`: las abiertas se
     * trasladaron a la agenda, las cerradas no (no se inventan citas con fechas
     * pasadas). Para la guarda de promesas cuentan igual: hubo una de por medio.
     */
    public function hadLegacyCourtesy(MarketingConversation $conversation): bool
    {
        return MarketingAgentAction::query()
            ->where('marketing_conversation_id', $conversation->id)
            ->where('action_type', MarketingAgentAction::TYPE_CREATE_APPOINTMENT)
            ->where('payload->source', MarketingAppointment::SOURCE_ULTRON)
            ->exists();
    }

    /**
     * El acta de la solicitud, escrita por Laravel a partir de la FILA.
     *
     * Ésta es la frase que la persona lee sobre el estado de su visita, y no
     * la escribe el modelo: sale de la cita, que es lo que el equipo va a ver
     * en su agenda. Mientras siga solicitada —nadie la ha confirmado— el acta
     * dice exactamente eso y nada más, así que no puede envejecer ni exagerar.
     *
     * Devuelve null cuando no hay nada que certificar: sin solicitud abierta.
     */
    public function actaDe(?MarketingAppointment $cita): ?string
    {
        if ($cita === null || $cita->status !== MarketingAppointment::STATUS_REQUESTED || $cita->scheduled_at === null) {
            return null;
        }

        $d = Carbon::parse($cita->scheduled_at)->setTimezone(BusinessClock::TZ);

        /*
         * Una fecha que ya pasó no se anuncia como pendiente.
         *
         * Nada caduca las solicitudes: si nadie la confirma ni la cancela, la
         * fila sigue abierta para siempre. Sin esto, el día 30 se le decía a la
         * persona «tu solicitud para el 26 está pendiente», que es un acta que
         * envejeció sin que nadie la tocara. Se devuelve null y el respaldo
         * genérico dice lo mismo sin fecha.
         */
        if ($d->isPast()) {
            return null;
        }

        // El punto de «p. m.» cierra la oración: sin otro detrás («p. m.. El»).
        return 'Dejé registrada tu solicitud de cortesía para el '.self::fechaDicha($d).' a las '.self::horaDicha($d)
            .' El equipo de Iron Body la revisará para tener todo preparado.';
    }

    /**
     * El acta de la cita que la herramienta ACABA de tocar, según cómo quedó la
     * fila: solicitada, confirmada o cancelada. Es lo que la persona lee sobre
     * su visita cuando el borrador del modelo nombra otro día u otra hora: el
     * borrador se escribe antes de que la herramienta se ejecute, y la verdad
     * es la fila. Devuelve null si no hay nada que contar.
     */
    public function actaDeLaFila(MarketingAppointment $cita): ?string
    {
        if ($cita->scheduled_at === null) {
            return null;
        }
        $d = Carbon::parse($cita->scheduled_at)->setTimezone(BusinessClock::TZ);
        $cuando = 'el '.self::fechaDicha($d).' a las '.self::horaDicha($d);

        return match (true) {
            $cita->status === MarketingAppointment::STATUS_REQUESTED => $this->actaDe($cita),
            in_array($cita->status, MarketingAppointment::CONFIRMED_STATUSES, true) => 'Tu visita está confirmada para '.$cuando,
            $cita->status === MarketingAppointment::STATUS_CANCELLED => 'Cancelé tu solicitud de visita para '.$cuando,
            default => null,
        };
    }

    /** «lunes 5 de octubre»: el día como se le dice a la persona. */
    public static function fechaDicha(CarbonInterface $d): string
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $d = Carbon::instance($d)->setTimezone(BusinessClock::TZ);

        return $dias[$d->dayOfWeek].' '.$d->day.' de '.$meses[$d->month - 1];
    }

    /**
     * «6:00 p. m.»: la hora como se dice en Colombia, sin ambigüedad. Las 18:00
     * se dicen «6:00 p. m.» y las 06:00 «6:00 a. m.»: con el reloj de 24 horas
     * o con un «6» suelto, la persona puede leer la mañana donde era la tarde.
     */
    public static function horaDicha(CarbonInterface $d): string
    {
        $d = Carbon::instance($d)->setTimezone(BusinessClock::TZ);
        $h = $d->hour % 12 === 0 ? 12 : $d->hour % 12;

        return $h.':'.$d->format('i').' '.($d->hour < 12 ? 'a. m.' : 'p. m.');
    }

    /**
     * Las visitas solicitadas que siguen sin confirmar, vengan de donde vengan.
     *
     * No se filtra por origen: `requested` solo lo producen los caminos de
     * ULTRON (el CRM agenda en firme), y una visita CONFIRMADA desde el CRM que
     * la persona mueve por el chat vuelve a solicitada conservando su origen.
     * Filtrar por `source = ultron` no la vería en el turno siguiente y abriría
     * una segunda: dos activas para la misma persona.
     */
    private function solicitudes()
    {
        return MarketingAppointment::query()
            ->where('type', MarketingAppointment::TYPE_VISIT)
            ->where('status', MarketingAppointment::STATUS_REQUESTED);
    }

    /** @return array{kind:string,status:string,past:bool,scheduled_at:string,date:string,time:string,weekday:string,human:string,conversation_id:int} */
    private function compromiso(MarketingAppointment $cita, string $estado, Carbon $ahora): array
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $d = Carbon::parse($cita->scheduled_at)->setTimezone(BusinessClock::TZ);

        return [
            'kind' => 'courtesy_visit',
            'status' => $estado,
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
}
