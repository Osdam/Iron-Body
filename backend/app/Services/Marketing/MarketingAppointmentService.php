<?php

namespace App\Services\Marketing;

use App\Exceptions\AppointmentException;
use App\Models\Admin;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Services\Audit\AuditTrail;
use App\Services\Marketing\Ultron\BusinessClock;
use App\Support\Access\AdminActor;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de la Agenda comercial: consulta filtrada, creación con datos del lead,
 * y transiciones de estado. Sin borrado físico: cancelar es un cambio de estado.
 *
 * LAS TRANSICIONES LAS DECIDE ESTE SERVICIO, Y SOLO ÉL. Cada una:
 *  - corre en una transacción y relee la fila con `lockForUpdate`, así que dos
 *    personas (o una persona y ULTRON) a la vez se ordenan y la segunda ve lo
 *    que dejó la primera;
 *  - es idempotente: pedir el estado en que ya está devuelve `changed=false`
 *    sin tocar nada (doble clic, reintento);
 *  - rechaza lo imposible (completar lo que nadie confirmó, reabrir lo
 *    cerrado, mover al pasado) con {@see AppointmentException};
 *  - deja constancia: en `audit_logs` con el actor si viene del CRM, y siempre
 *    en `metadata.history` (también lo que hace ULTRON, que no es una persona).
 *
 * `requested` (solicitada) NO es `scheduled` (confirmada): reprogramar conserva
 * el estado, y solo `confirm()` convierte una solicitud en cita en firme.
 */
class MarketingAppointmentService
{
    /** Desde → hacia. Lo que no está aquí es una transición imposible. */
    private const TRANSITIONS = [
        MarketingAppointment::STATUS_REQUESTED => [MarketingAppointment::STATUS_SCHEDULED, MarketingAppointment::STATUS_CANCELLED],
        MarketingAppointment::STATUS_SCHEDULED => [MarketingAppointment::STATUS_COMPLETED, MarketingAppointment::STATUS_CANCELLED, MarketingAppointment::STATUS_NO_SHOW],
        // Heredado: nadie lo asigna ya; cuenta como confirmada.
        MarketingAppointment::STATUS_RESCHEDULED => [MarketingAppointment::STATUS_SCHEDULED, MarketingAppointment::STATUS_COMPLETED, MarketingAppointment::STATUS_CANCELLED, MarketingAppointment::STATUS_NO_SHOW],
    ];

    /** Las palabras que lee el equipo en la auditoría. */
    private const VERBOS = [
        MarketingAppointment::STATUS_SCHEDULED => 'Confirmó la cita',
        MarketingAppointment::STATUS_COMPLETED => 'Marcó la cita como cumplida',
        MarketingAppointment::STATUS_CANCELLED => 'Canceló la cita',
        MarketingAppointment::STATUS_NO_SHOW => 'Marcó que no asistió',
    ];

    public function __construct(private readonly MarketingAppointmentAuthorizationService $authz) {}

    /**
     * Una hora que llega del CRM. Con offset (ISO 8601) se respeta; sin offset
     * es la hora del gimnasio (Bogotá), que es la que ve quien la escribe. Antes
     * se guardaba tal cual como si fuera UTC y la cita quedaba cinco horas
     * desplazada.
     */
    public static function fromClientTime(string $valor): Carbon
    {
        $valor = trim($valor);
        $conZona = preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $valor) === 1;
        $utc = ($conZona ? Carbon::parse($valor) : Carbon::parse($valor, BusinessClock::TZ))->utc();

        // Una agenda no cita a nadie fuera de este siglo. Además, una fecha del
        // 31-12-9999 en Bogotá es el año 10000 en UTC: una fila que después ni
        // la lista ni el detalle podrían leer.
        if ($utc->year < 2000 || $utc->year > 2100) {
            throw AppointmentException::outOfRange();
        }

        return $utc;
    }

    /** Consulta paginada con filtros + scoping por rol (comercial: propias/sin asignar). */
    public function list(Request $request, ?Admin $viewer): LengthAwarePaginator
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);

        $query = MarketingAppointment::query()
            ->with(['lead:id,name,phone,channel', 'assignedAdmin:id,name', 'conversation:id,lead_id'])
            ->orderBy('scheduled_at')
            ->orderBy('id');

        $this->applyFilters($query, $request);
        $this->applyScope($query, $viewer);

        return $query->paginate($perPage);
    }

    /**
     * Cuántas citas hay en cada estado con los mismos filtros y el mismo alcance
     * que la lista, pero sin el filtro de estado ni la paginación: los contadores
     * de la agenda («Por confirmar», «Completadas»…) son exactos aunque solo se
     * hayan cargado las primeras 25, y no cambian al filtrar por un estado.
     *
     * @return array<string,int>
     */
    public function countsByStatus(Request $request, ?Admin $viewer): array
    {
        $query = MarketingAppointment::query();
        $this->applyFilters($query, $request, conEstado: false);
        $this->applyScope($query, $viewer);

        $porEstado = $query->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(MarketingAppointment::STATUSES)
            ->mapWithKeys(fn (string $estado) => [$estado => (int) ($porEstado[$estado] ?? 0)])
            ->all();
    }

    private function applyFilters(Builder $query, Request $request, bool $conEstado = true): void
    {
        if ($conEstado && ($status = $request->query('status'))) {
            $query->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if ($from = $request->query('date_from')) {
            $query->where('scheduled_at', '>=', self::fromClientTime((string) $from));
        }
        if ($to = $request->query('date_to')) {
            $query->where('scheduled_at', '<=', self::fromClientTime((string) $to));
        }
        if (($assignee = $request->query('assigned_to_admin_id')) !== null && $assignee !== '') {
            $query->where('assigned_to_admin_id', (int) $assignee);
        }
        if (($leadId = $request->query('marketing_lead_id')) !== null && $leadId !== '') {
            $query->where('marketing_lead_id', (int) $leadId);
        }
        if (($convId = $request->query('marketing_conversation_id')) !== null && $convId !== '') {
            $query->where('marketing_conversation_id', (int) $convId);
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(function (Builder $outer) use ($like): void {
                $outer->where('title', 'like', $like)
                    ->orWhere('contact_name', 'like', $like)
                    ->orWhere('contact_phone', 'like', $like)
                    ->orWhereHas('lead', fn (Builder $l) => $l->where('name', 'like', $like)->orWhere('phone', 'like', $like));
            });
        }
    }

    /** Comercial: solo ve citas propias o sin asignar. FULL: ve todas. */
    private function applyScope(Builder $query, ?Admin $viewer): void
    {
        if ($this->authz->isFull($viewer) || ! $viewer instanceof Admin) {
            return;
        }
        $query->where(function (Builder $q) use ($viewer): void {
            $q->whereNull('assigned_to_admin_id')
                ->orWhere('assigned_to_admin_id', $viewer->id);
        });
    }

    /**
     * Crea una cita CONFIRMADA (la crea una persona del equipo o una herramienta
     * que agenda en firme), precargando contacto desde el lead si no viene.
     * Las solicitudes sin confirmar nacen por {@see createRequest()}.
     */
    public function create(array $data, ?int $createdBy, ?string $source = null, ?Request $request = null): MarketingAppointment
    {
        $lead = isset($data['marketing_lead_id']) ? MarketingLead::find($data['marketing_lead_id']) : null;

        // Si llega la conversación pero no el lead, se hereda el lead de la conversación.
        if ($lead === null && ! empty($data['marketing_conversation_id'])) {
            $conv = MarketingConversation::find($data['marketing_conversation_id']);
            if ($conv) {
                $data['marketing_lead_id'] = $conv->lead_id;
                $lead = $conv->lead;
            }
        }

        return DB::transaction(function () use ($data, $createdBy, $source, $lead, $request): MarketingAppointment {
            $cita = MarketingAppointment::create([
                'marketing_lead_id' => $data['marketing_lead_id'] ?? null,
                'marketing_conversation_id' => $data['marketing_conversation_id'] ?? null,
                'assigned_to_admin_id' => $data['assigned_to_admin_id'] ?? $createdBy,
                'created_by_admin_id' => $createdBy,
                'type' => $data['type'],
                'status' => MarketingAppointment::STATUS_SCHEDULED,
                'source' => $source,
                'title' => $data['title'],
                'notes' => $data['notes'] ?? null,
                'scheduled_at' => $data['scheduled_at'],
                'duration_minutes' => $data['duration_minutes'] ?? 30,
                'location' => $data['location'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? $lead?->phone,
                'contact_name' => $data['contact_name'] ?? $lead?->name,
                'reminder_at' => $data['reminder_at'] ?? null,
                'metadata' => ['history' => [$this->huella('created', $request, $source)]],
            ]);

            if ($request !== null) {
                app(AuditTrail::class)->record($request, [
                    'action' => 'create', 'module' => 'Mercadeo', 'entity' => 'cita',
                    'entity_id' => $cita->id, 'target_name' => $cita->title,
                    'summary' => 'Agendó una cita confirmada',
                    'metadata' => ['source' => $source, 'type' => $cita->type],
                ]);
            }

            return $cita;
        });
    }

    /**
     * Una SOLICITUD sin confirmar (la cortesía que recoge ULTRON). No precarga
     * el teléfono en la fila: ya está en el lead, y no se duplica un dato
     * personal para nada.
     *
     * @param  array<string,mixed>  $metadata
     */
    public function createRequest(MarketingLead $lead, ?MarketingConversation $conversation, CarbonInterface $cuando, int $minutos, string $titulo, ?string $notas, array $metadata, string $source): MarketingAppointment
    {
        return MarketingAppointment::create([
            'marketing_lead_id' => $lead->id,
            'marketing_conversation_id' => $conversation?->id,
            'type' => MarketingAppointment::TYPE_VISIT,
            'status' => MarketingAppointment::STATUS_REQUESTED,
            'source' => $source,
            'title' => $titulo,
            'notes' => $notas,
            'scheduled_at' => Carbon::instance($cuando)->utc(),
            'duration_minutes' => $minutos,
            'metadata' => array_merge($metadata, ['history' => [$this->huella('requested', null, $source)]]),
        ]);
    }

    /**
     * Edición general. El estado y la fecha NO se cambian aquí por la puerta de
     * atrás: pasan por las mismas reglas que los botones.
     *
     * @return array{appointment:MarketingAppointment, changed:bool}
     */
    public function update(MarketingAppointment $appointment, array $data, ?Request $request = null, ?CarbonInterface $vista = null): array
    {
        try {
            // Todo el PATCH o nada: las transiciones de dentro se anidan como savepoints.
            return DB::transaction(function () use ($appointment, $data, $request, $vista): array {
                if ($vista !== null) {
                    $this->exigirFechaVista(MarketingAppointment::query()->lockForUpdate()->findOrFail($appointment->id), $vista, 'guardar los cambios');
                }

                return $this->editar($appointment, $data, $request);
            });
        } catch (UniqueConstraintViolationException) {
            // La única unicidad de la agenda: una solicitud de visita abierta de
            // ULTRON por persona. Volver a «visita» una que se pasó a llamada,
            // cuando ya nació otra, chocaría con ella: es un conflicto, no un 500.
            throw AppointmentException::duplicateRequest();
        }
    }

    /** @return array{appointment:MarketingAppointment, changed:bool} */
    private function editar(MarketingAppointment $appointment, array $data, ?Request $request): array
    {
        $cambio = false;

        if (! empty($data['scheduled_at'])) {
            $cambio = $this->reschedule($appointment, $data['scheduled_at'], $data['duration_minutes'] ?? null, $request)['changed'] || $cambio;
        }
        if (! empty($data['status'])) {
            $cambio = $this->transition($appointment, (string) $data['status'], $request)['changed'] || $cambio;
        }

        $resultado = DB::transaction(function () use ($appointment, $data): array {
            $fila = MarketingAppointment::query()->lockForUpdate()->findOrFail($appointment->id);
            $fila->fill(array_filter([
                // Sin fecha nueva, la duración se edita como un campo más.
                'duration_minutes' => empty($data['scheduled_at']) ? ($data['duration_minutes'] ?? null) : null,
                'type' => $data['type'] ?? null,
                'title' => $data['title'] ?? null,
                'notes' => $data['notes'] ?? null,
                'location' => $data['location'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'assigned_to_admin_id' => $data['assigned_to_admin_id'] ?? null,
                'reminder_at' => $data['reminder_at'] ?? null,
            ], fn ($v) => $v !== null));
            $sucio = $fila->isDirty();
            if ($sucio) {
                $fila->save();
            }

            return ['appointment' => $fila, 'changed' => $sucio];
        });

        return ['appointment' => $resultado['appointment'], 'changed' => $cambio || $resultado['changed']];
    }

    /**
     * Confirmar es comprometerse con una FECHA. Si quien confirma dice cuál vio
     * (`$vista`) y la cita ya no está ahí —otra sesión o ULTRON la movió entre
     * tanto—, no se confirma un día que nadie revisó: 409 `appointment_changed`.
     *
     * @return array{appointment:MarketingAppointment, changed:bool}
     */
    public function confirm(MarketingAppointment $appointment, ?Request $request = null, ?CarbonInterface $vista = null): array
    {
        return $this->transition($appointment, MarketingAppointment::STATUS_SCHEDULED, $request, esperada: $vista);
    }

    /** @return array{appointment:MarketingAppointment, changed:bool} */
    public function complete(MarketingAppointment $appointment, ?string $note = null, ?Request $request = null): array
    {
        return $this->transition($appointment, MarketingAppointment::STATUS_COMPLETED, $request, note: $note);
    }

    /** @return array{appointment:MarketingAppointment, changed:bool} */
    public function cancel(MarketingAppointment $appointment, ?string $reason = null, ?Request $request = null, ?string $by = null): array
    {
        return $this->transition($appointment, MarketingAppointment::STATUS_CANCELLED, $request, reason: $reason, by: $by);
    }

    /**
     * Mueve la MISMA cita: nunca crea otra, así que reprogramar no puede dejar
     * dos activas. Conserva el estado —una solicitada sigue solicitada—, salvo
     * que se pida reabrirla (`$reabrir`): ULTRON mueve una visita confirmada a
     * otro día que nadie ha confirmado todavía.
     *
     * @return array{appointment:MarketingAppointment, changed:bool}
     */
    public function reschedule(MarketingAppointment $appointment, string|CarbonInterface $cuando, ?int $durationMinutes = null, ?Request $request = null, bool $reabrir = false, ?string $by = null, ?CarbonInterface $vista = null): array
    {
        $nueva = $cuando instanceof CarbonInterface ? Carbon::instance($cuando)->utc() : self::fromClientTime($cuando);

        return DB::transaction(function () use ($appointment, $nueva, $durationMinutes, $request, $reabrir, $by, $vista): array {
            $fila = MarketingAppointment::query()->lockForUpdate()->findOrFail($appointment->id);
            if ($vista !== null) {
                $this->exigirFechaVista($fila, $vista, 'moverla');
            }

            $antes = $fila->scheduled_at?->copy()->utc();
            $estadoAntes = $fila->status;
            $mismaHora = $antes !== null && $antes->equalTo($nueva);
            $reabre = $reabrir && $estadoAntes !== MarketingAppointment::STATUS_REQUESTED;

            // Reenviar la fecha que ya tiene no es moverla, ni siquiera en una cita
            // pasada o cerrada (un PATCH con el objeto entero): a lo sumo cambia
            // la duración, que se edita sin las reglas de mover.
            if ($mismaHora && ! $reabre) {
                if ($durationMinutes === null || $durationMinutes === (int) $fila->duration_minutes) {
                    return ['appointment' => $fila, 'changed' => false];
                }
                $fila->forceFill(['duration_minutes' => $durationMinutes])->save();

                return ['appointment' => $fila, 'changed' => true];
            }
            if (! in_array($fila->status, MarketingAppointment::ACTIVE_STATUSES, true)) {
                throw AppointmentException::closed();
            }
            if ($nueva->isPast()) {
                throw AppointmentException::inThePast();
            }

            $meta = $fila->metadata ?? [];
            $meta['reschedules'][] = ['from' => $antes?->toIso8601String(), 'at' => now()->toIso8601String()];
            $meta['history'][] = $this->huella($reabre ? 'reopened' : 'rescheduled', $request, $by, [
                'from' => $antes?->toIso8601String(), 'to' => $nueva->toIso8601String(),
            ]);

            $fila->forceFill(array_merge([
                'scheduled_at' => $nueva,
                'duration_minutes' => $durationMinutes ?? $fila->duration_minutes,
                'metadata' => $meta,
            ], $reabre ? ['status' => MarketingAppointment::STATUS_REQUESTED] : []))->save();

            if ($request !== null) {
                app(AuditTrail::class)->record($request, [
                    'action' => 'update', 'module' => 'Mercadeo', 'entity' => 'cita',
                    'entity_id' => $fila->id, 'target_name' => $fila->title,
                    'summary' => 'Reprogramó la cita',
                    'changes' => [['field' => 'fecha', 'from' => $antes?->toIso8601String(), 'to' => $nueva->toIso8601String()]],
                    'metadata' => ['source' => $fila->source],
                ]);
            }

            return ['appointment' => $fila, 'changed' => true];
        });
    }

    /**
     * El cambio de estado, con sus reglas. Pedir el estado actual no es un error:
     * es un doble clic o un reintento, y no toca nada.
     *
     * @return array{appointment:MarketingAppointment, changed:bool}
     */
    public function transition(MarketingAppointment $appointment, string $hacia, ?Request $request = null, ?string $note = null, ?string $reason = null, ?string $by = null, ?CarbonInterface $esperada = null): array
    {
        return DB::transaction(function () use ($appointment, $hacia, $request, $note, $reason, $by, $esperada): array {
            $fila = MarketingAppointment::query()->lockForUpdate()->findOrFail($appointment->id);
            $desde = (string) $fila->status;

            // La precondición, ya con la fila bloqueada: lo que se ve en pantalla
            // tiene que ser lo que se confirma.
            if ($esperada !== null) {
                $this->exigirFechaVista($fila, $esperada, 'confirmarla');
            }

            if ($desde === $hacia) {
                return ['appointment' => $fila, 'changed' => false];
            }
            if (! in_array($hacia, self::TRANSITIONS[$desde] ?? [], true)) {
                if (in_array($desde, MarketingAppointment::CLOSED_STATUSES, true)) {
                    throw AppointmentException::closed();
                }
                if ($desde === MarketingAppointment::STATUS_REQUESTED && in_array($hacia, [MarketingAppointment::STATUS_COMPLETED, MarketingAppointment::STATUS_NO_SHOW], true)) {
                    throw AppointmentException::notConfirmed();
                }
                throw AppointmentException::invalidTransition($desde, $hacia);
            }

            // Completar es decir que la visita OCURRIÓ: antes de su hora no puede
            // haber ocurrido. El reloj es el del gimnasio (Bogotá) y se comparan
            // instantes, así que el cambio de día en UTC no engaña. Va con la fila
            // bloqueada y después del «ya estaba así», que sigue sin tocar nada.
            if ($hacia === MarketingAppointment::STATUS_COMPLETED && $fila->scheduled_at !== null
                && Carbon::now(BusinessClock::TZ)->lt($fila->scheduled_at)) {
                throw AppointmentException::notDue();
            }

            $cambios = ['status' => $hacia];
            if ($hacia === MarketingAppointment::STATUS_COMPLETED) {
                $cambios['completed_at'] = now();
                if ($note !== null && trim($note) !== '') {
                    $cambios['notes'] = trim(($fila->notes ? $fila->notes."\n" : '').'[completada] '.trim($note));
                }
            }
            if ($hacia === MarketingAppointment::STATUS_CANCELLED) {
                $cambios['cancelled_at'] = now();
                // La columna guarda 255 caracteres: un motivo más largo, venga de donde venga, no tumba la cancelación.
                $cambios['cancellation_reason'] = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null;
            }

            $meta = $fila->metadata ?? [];
            $meta['history'][] = $this->huella($hacia, $request, $by, ['from' => $desde]);
            $cambios['metadata'] = $meta;

            $fila->forceFill($cambios)->save();

            if ($request !== null) {
                app(AuditTrail::class)->record($request, [
                    'action' => 'status', 'module' => 'Mercadeo', 'entity' => 'cita',
                    'entity_id' => $fila->id, 'target_name' => $fila->title,
                    'summary' => self::VERBOS[$hacia] ?? 'Cambió el estado de la cita',
                    'changes' => [['field' => 'estado', 'from' => $desde, 'to' => $hacia]],
                    'metadata' => ['source' => $fila->source],
                ]);
            }

            return ['appointment' => $fila, 'changed' => true];
        });
    }

    /**
     * Toda escritura que depende de la FECHA (confirmar, mover, editarla) puede
     * decir qué fecha vio quien la hace. Si la cita ya no está ahí —otra sesión
     * o ULTRON la movió entre tanto—, no se escribe sobre un día que nadie
     * revisó: 409 `appointment_changed`. Se mira con la fila ya bloqueada.
     */
    private function exigirFechaVista(MarketingAppointment $fila, CarbonInterface $vista, string $antesDe): void
    {
        if (! ($fila->scheduled_at !== null && $fila->scheduled_at->copy()->utc()->equalTo(Carbon::instance($vista)->utc()))) {
            throw AppointmentException::changed($antesDe);
        }
    }

    /**
     * Qué puede hacer QUIEN MIRA con esta cita: estado + permisos + propiedad.
     * El CRM pinta los botones con esto y no replica la máquina de estados.
     *
     * @return array{confirm:bool, cancel:bool, complete:bool, reschedule:bool}
     */
    public function allowedActions(MarketingAppointment $a, ?Admin $viewer): array
    {
        $propia = $this->authz->ownsOrUnassigned($viewer, $a);
        $puede = fn (string $cap): bool => $propia && $this->authz->can($viewer, $cap);
        $activa = in_array($a->status, MarketingAppointment::ACTIVE_STATUSES, true);

        return [
            'confirm' => $a->status === MarketingAppointment::STATUS_REQUESTED && $puede(MarketingAppointmentAuthorizationService::CAP_CONFIRM),
            'cancel' => $activa && $puede(MarketingAppointmentAuthorizationService::CAP_CANCEL),
            'complete' => in_array($a->status, [MarketingAppointment::STATUS_SCHEDULED, MarketingAppointment::STATUS_RESCHEDULED], true)
                && $puede(MarketingAppointmentAuthorizationService::CAP_COMPLETE),
            'reschedule' => $activa && $puede(MarketingAppointmentAuthorizationService::CAP_RESCHEDULE),
        ];
    }

    /** @return array<string,mixed> */
    public function present(MarketingAppointment $a, ?Admin $viewer = null): array
    {
        $meta = $a->metadata ?? [];

        return [
            'id' => $a->id,
            'uuid' => $a->uuid,
            'marketing_lead_id' => $a->marketing_lead_id,
            'marketing_conversation_id' => $a->marketing_conversation_id,
            'type' => $a->type,
            'status' => $a->status,
            'source' => $a->source,
            'title' => $a->title,
            'notes' => $a->notes,
            'scheduled_at' => $a->scheduled_at?->toIso8601String(),
            'duration_minutes' => (int) $a->duration_minutes,
            'location' => $a->location,
            'contact_name' => $a->contact_name,
            'contact_phone' => $a->contact_phone,
            // Lo que pidió la persona, en hora del gimnasio, tal como lo dijo.
            'requested' => isset($meta['requested_date'], $meta['requested_time'])
                ? ['date' => (string) $meta['requested_date'], 'time' => (string) $meta['requested_time']]
                : null,
            'completed_at' => $a->completed_at?->toIso8601String(),
            'cancelled_at' => $a->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $a->cancellation_reason,
            'assigned_to' => $a->assignedAdmin ? ['id' => $a->assignedAdmin->id, 'name' => $a->assignedAdmin->name] : null,
            'lead' => $a->lead ? ['id' => $a->lead->id, 'name' => $a->lead->name, 'phone' => $a->lead->phone] : null,
            'allowed_actions' => $this->allowedActions($a, $viewer),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * Una entrada del historial de la cita: qué pasó, cuándo y quién.
     *
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function huella(string $evento, ?Request $request, ?string $by, array $extra = []): array
    {
        $actor = $request !== null ? AdminActor::from($request) : null;

        return array_merge([
            'event' => $evento,
            'at' => now()->toIso8601String(),
            'by' => $actor !== null ? 'admin:'.$actor->id : ($by ?? 'system'),
        ], $extra);
    }
}
