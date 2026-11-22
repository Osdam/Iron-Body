<?php

namespace App\Observers\Commercial;

use App\Models\CommercialEvent;
use App\Models\Member;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Observability\ChannelLog;
use Illuminate\Support\Carbon;

/**
 * Las quejas que decide UNA PERSONA del gimnasio (Q1-8 del plan de quejas;
 * Enmienda Q1-8 del contrato H9). No es un observador de Eloquent: lo llaman
 * la ruta de registrar una queja (`OutreachComplaintController`) y la
 * resolución de una revisión del Inbox (`MarketingStaffReviewService`). Vive
 * con los emisores porque escribe `commercial_events`, y solo por
 * `CommercialEventRecorder` (la lista cerrada de `OutreachCatalogEmittersTest`).
 *
 * REGISTRAR ({@see recordStaffComplaint()}): la queja presencial, por teléfono
 * u otra, con el MISMO evento que la de la IA (`complaint_created`), para que
 * N6 (`complaint_recent`) la lea igual. `source = staff`, el medio y una
 * categoría de listas cerradas, quién la registró y el `clientRequestId`. Sin
 * texto, sin teléfono y sin nombre. Una al día de Bogotá por persona: la
 * ventana cuenta desde la última. Nunca se descarta: caduca por la ventana.
 *
 * DESCARTAR ({@see dismissAiComplaints()}): `complaint_dismissed`, una fila por
 * queja de la IA, con la clave de la queja dentro de la suya
 * (`complaint_dismissed:<clave de la queja>`) y quién lo decidió. No borra ni
 * cambia nada: la queja se queda y el lector del H9 (`EngineFacts::events()`)
 * deja de contarla. Solo las de la IA ({@see V::COMPLAINT_SOURCES_DISMISSABLE})
 * de ESA conversación; nunca las de otra fuente ni las de otra conversación.
 *
 * Las dos FALLAN EN VOZ ALTA: el registrador se traga sus fallos (los
 * observadores no pueden romper a quien escribe), pero aquí quien llama es una
 * persona que tiene que saber si quedó hecho. Después de registrar se comprueba
 * que la fila está; si no, `RuntimeException` (la ruta responde 503 y no dice
 * que lo hizo).
 */
class ComplaintCommercialEmitter
{
    /** Por dónde llegó la queja que registra el personal. */
    public const CHANNELS = ['in_person', 'other', 'phone'];

    /** De qué se quejó (cerrada y corta: atención, cobro, clases, instalaciones u otra). */
    public const CATEGORIES = ['attention', 'billing', 'classes', 'facilities', 'other'];

    /** La categoría al resolver una revisión que descarta las quejas de la IA. */
    public const NOT_A_COMPLAINT = 'not_a_complaint';

    /** Las categorías al resolver una revisión marcada como queja (`complaint_confirmed` y `other` siguen frenando). */
    public const REVIEW_CATEGORIES = ['complaint_confirmed', self::NOT_A_COMPLAINT, 'other'];

    /**
     * Cuántas veces puede volver a abrirse la queja de la IA de una conversación
     * el mismo día después de descartarla (`…:<Ymd>:2`, `…:3`…). Pasado el tope,
     * una por fila de la IA: el lado seguro (frena).
     */
    public const MAX_REOPENED_PER_DAY = 20;

    /** Hasta dónde atrás se descarta: la ventana máxima que lee el H9 (`OutreachCatalog::MAX_WINDOW_DAYS`; una prueba los ata). */
    public const DISMISS_WINDOW_DAYS = 730;

    public function __construct(
        private readonly CommercialEventRecorder $recorder,
        private readonly CommercialSubjectResolver $resolver,
    ) {}

    /** ¿Registra el motor eventos? Como el resto de los emisores. */
    public static function enabled(): bool
    {
        return (bool) config('commercial.events_enabled', false);
    }

    /** La clave guardada de la queja de la IA de una conversación ese día (la primera de la serie). */
    public static function aiComplaintKey(string $scope, string $day): string
    {
        return "{$scope}:complaint:{$day}";
    }

    /** ¿Descartó alguien la queja con esta clave (sin el prefijo del evento, como la pasa el emisor)? */
    public static function isDismissed(string $complaintKey): bool
    {
        return CommercialEvent::query()->where('dedupe_key', self::dismissalKeyOf(V::EV_COMPLAINT_CREATED.':'.$complaintKey))->exists();
    }

    /** La clave GUARDADA del descarte de una queja, a partir de la clave GUARDADA de la queja. */
    public static function dismissalKeyOf(string $storedComplaintKey): string
    {
        return V::EV_COMPLAINT_DISMISSED.':'.$storedComplaintKey;
    }

    /**
     * La queja del personal ya registrada con ese `clientRequestId`, si la hay.
     *
     * @return array{member_id: ?int, marketing_lead_id: ?int, payload: array<string, mixed>, occurred_at: Carbon}|null
     */
    public function staffComplaintByRequest(string $clientRequestId): ?array
    {
        $f = CommercialEvent::query()
            ->where('event', V::EV_COMPLAINT_CREATED)
            ->where('payload->source', V::COMPLAINT_SOURCE_STAFF)
            ->where('payload->client_request_id', $clientRequestId)
            ->orderBy('id')
            ->first(['member_id', 'marketing_lead_id', 'payload', 'occurred_at']);

        return $f === null ? null : [
            'member_id' => $f->member_id !== null ? (int) $f->member_id : null,
            'marketing_lead_id' => $f->marketing_lead_id !== null ? (int) $f->marketing_lead_id : null,
            'payload' => is_array($f->payload) ? $f->payload : [],
            'occurred_at' => Carbon::parse($f->occurred_at),
        ];
    }

    /**
     * NB3 (Q1-8): cuántas quejas del personal registró ESTA cuenta en el día de
     * Bogotá de `$at` (filas nuevas: una fusionada con la del día de esa persona
     * no deja fila). La carga se compara en PHP para no depender de cómo cada
     * motor compara un número dentro del JSON; son las quejas del personal de
     * un solo día (decenas).
     */
    public function staffComplaintsRecordedBy(int $adminId, Carbon $at): int
    {
        $desde = $at->copy()->setTimezone(Member::BUSINESS_TZ)->startOfDay()->utc();

        return CommercialEvent::query()
            ->where('event', V::EV_COMPLAINT_CREATED)
            ->where('payload->source', V::COMPLAINT_SOURCE_STAFF)
            ->where('occurred_at', '>=', $desde)
            ->where('occurred_at', '<', $desde->copy()->addDay())
            ->get(['payload'])
            ->filter(static fn (CommercialEvent $f): bool => is_array($f->payload) && ($f->payload['recorded_by'] ?? null) === $adminId)
            ->count();
    }

    /**
     * Registra la queja que trae una persona del gimnasio. `$personType` y
     * `$personId` son la persona CANÓNICA (el socio si el prospecto es de un
     * socio). Devuelve `recorded` (fila nueva) o `merged` (ya había una queja
     * del personal de esa persona hoy: la ventana no cambia).
     *
     * @return 'recorded'|'merged'
     *
     * @throws \RuntimeException si no quedó registrada
     */
    public function recordStaffComplaint(string $personType, int $personId, string $channel, string $category, int $adminId, string $clientRequestId, Carbon $at): string
    {
        if (! in_array($channel, self::CHANNELS, true) || ! in_array($category, self::CATEGORIES, true) || ! in_array($personType, ['member', 'lead'], true)) {
            throw new \InvalidArgumentException('queja del personal: medio, categoría o persona fuera de la lista cerrada');
        }
        $dia = $at->copy()->setTimezone(Member::BUSINESS_TZ)->format('Ymd');
        $clave = "{$personType}:{$personId}:complaint:staff:{$dia}";
        $sujeto = $personType === 'member' ? $this->resolver->fromMember($personId) : $this->resolver->fromLead($personId);

        $fila = $this->recorder->record(
            event: V::EV_COMPLAINT_CREATED,
            subject: $sujeto + ['opportunity_id' => null],
            payload: [
                'source' => V::COMPLAINT_SOURCE_STAFF,
                'channel' => $channel,
                'category' => $category,
                'recorded_by' => $adminId,
                'client_request_id' => $clientRequestId,
            ],
            dedupeKey: $clave,
            occurredAt: $at,
        );
        if ($fila !== null) {
            return 'recorded';
        }
        // null: duplicado del día (otra queja del personal hoy) o un fallo que el registrador contuvo.
        if (CommercialEvent::query()->where('dedupe_key', V::EV_COMPLAINT_CREATED.':'.$clave)->exists()) {
            return 'merged';
        }

        throw new \RuntimeException('queja del personal: el registro no quedó guardado');
    }

    /**
     * Descarta las quejas que marcó la IA en una conversación: las de esa
     * conversación (su clave y su `conversation_id`), de la IA, posteriores a
     * `$since` (la resolución anterior de la revisión: lo ya resuelto no se toca)
     * y hasta `$at`. Devuelve cuántas quedaron descartadas (0 si no había).
     *
     * @throws \RuntimeException si alguna no quedó descartada
     */
    public function dismissAiComplaints(int $conversationId, int $leadId, ?Carbon $since, Carbon $at, int $adminId): int
    {
        $prefijo = V::EV_COMPLAINT_CREATED.':'.self::aiComplaintKey("conversation:{$conversationId}", '');
        $filas = CommercialEvent::query()
            ->where('event', V::EV_COMPLAINT_CREATED)
            ->where('marketing_lead_id', $leadId)
            ->where('occurred_at', '>=', $at->copy()->subDays(self::DISMISS_WINDOW_DAYS))
            ->where('occurred_at', '<=', $at)
            ->when($since !== null, fn ($q) => $q->where('occurred_at', '>', $since))
            ->orderBy('id')
            ->get(['id', 'member_id', 'marketing_lead_id', 'dedupe_key', 'payload']);

        $esperadas = [];
        foreach ($filas as $f) {
            $payload = is_array($f->payload) ? $f->payload : [];
            if (! is_string($f->dedupe_key) || ! str_starts_with($f->dedupe_key, $prefijo)
                || ($payload['conversation_id'] ?? null) !== $conversationId
                || ! in_array($payload['source'] ?? null, V::COMPLAINT_SOURCES_DISMISSABLE, true)) {
                continue;
            }
            $this->recorder->record(
                event: V::EV_COMPLAINT_DISMISSED,
                subject: ['lead_id' => $f->marketing_lead_id, 'member_id' => $f->member_id, 'opportunity_id' => null],
                payload: [
                    'complaint_event_id' => (int) $f->id,
                    'conversation_id' => $conversationId,
                    'category' => self::NOT_A_COMPLAINT,
                    'dismissed_by' => $adminId,
                ],
                // El registrador antepone el evento: `complaint_dismissed:<clave guardada de la queja>`.
                dedupeKey: (string) $f->dedupe_key,
                occurredAt: $at,
            );
            $esperadas[] = self::dismissalKeyOf((string) $f->dedupe_key);
        }
        if ($esperadas === []) {
            return 0;
        }
        $hechas = CommercialEvent::query()->whereIn('dedupe_key', $esperadas)->count();
        if ($hechas !== count($esperadas)) {
            throw new \RuntimeException('descarte de quejas: no quedaron todas registradas');
        }
        ChannelLog::info('commercial.complaint.dismissed', ['conversation_id' => $conversationId, 'complaints' => count($esperadas), 'by' => $adminId]);

        return count($esperadas);
    }
}
