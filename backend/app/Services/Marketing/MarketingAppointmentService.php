<?php

namespace App\Services\Marketing;

use App\Models\Admin;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Lógica de la Agenda comercial: consulta filtrada, creación con datos del lead,
 * y transiciones de estado (completar / cancelar / reprogramar). Sin borrado
 * físico: cancelar es un cambio de estado.
 */
class MarketingAppointmentService
{
    public function __construct(private readonly MarketingAppointmentAuthorizationService $authz)
    {
    }

    /** Consulta paginada con filtros + scoping por rol (comercial: propias/sin asignar). */
    public function list(Request $request, ?Admin $viewer): LengthAwarePaginator
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);

        $query = MarketingAppointment::query()
            ->with(['lead:id,name,phone,channel', 'assignedAdmin:id,name', 'conversation:id,lead_id'])
            ->orderBy('scheduled_at');

        $this->applyFilters($query, $request);
        $this->applyScope($query, $viewer);

        return $query->paginate($perPage);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($from = $request->query('date_from')) {
            $query->where('scheduled_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->where('scheduled_at', '<=', $to);
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

    /** Crea una cita, precargando contacto desde el lead si no viene en los datos. */
    public function create(array $data, ?int $createdBy): MarketingAppointment
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

        return MarketingAppointment::create([
            'marketing_lead_id'         => $data['marketing_lead_id'] ?? null,
            'marketing_conversation_id' => $data['marketing_conversation_id'] ?? null,
            'assigned_to_admin_id'      => $data['assigned_to_admin_id'] ?? $createdBy,
            'created_by_admin_id'       => $createdBy,
            'type'                      => $data['type'],
            'status'                    => MarketingAppointment::STATUS_SCHEDULED,
            'title'                     => $data['title'],
            'notes'                     => $data['notes'] ?? null,
            'scheduled_at'              => $data['scheduled_at'],
            'duration_minutes'          => $data['duration_minutes'] ?? 30,
            'location'                  => $data['location'] ?? null,
            'contact_phone'             => $data['contact_phone'] ?? $lead?->phone,
            'contact_name'              => $data['contact_name'] ?? $lead?->name,
            'reminder_at'               => $data['reminder_at'] ?? null,
        ]);
    }

    /** Edición general (no cambia los timestamps de transición). */
    public function update(MarketingAppointment $appointment, array $data): MarketingAppointment
    {
        $appointment->fill(array_filter([
            'type'                 => $data['type'] ?? null,
            'title'                => $data['title'] ?? null,
            'notes'                => $data['notes'] ?? null,
            'scheduled_at'         => $data['scheduled_at'] ?? null,
            'duration_minutes'     => $data['duration_minutes'] ?? null,
            'location'             => $data['location'] ?? null,
            'contact_phone'        => $data['contact_phone'] ?? null,
            'contact_name'         => $data['contact_name'] ?? null,
            'assigned_to_admin_id' => $data['assigned_to_admin_id'] ?? null,
            'reminder_at'          => $data['reminder_at'] ?? null,
        ], fn ($v) => $v !== null));

        // status explícito (p. ej. no_show) si viene y es válido.
        if (! empty($data['status']) && in_array($data['status'], MarketingAppointment::STATUSES, true)) {
            $appointment->status = $data['status'];
        }

        $appointment->save();

        return $appointment;
    }

    public function complete(MarketingAppointment $appointment, ?string $note = null): MarketingAppointment
    {
        $appointment->forceFill([
            'status'       => MarketingAppointment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        if ($note !== null && trim($note) !== '') {
            $appointment->notes = trim(($appointment->notes ? $appointment->notes."\n" : '').'[completada] '.trim($note));
        }
        $appointment->save();

        return $appointment;
    }

    public function cancel(MarketingAppointment $appointment, ?string $reason = null): MarketingAppointment
    {
        $appointment->forceFill([
            'status'              => MarketingAppointment::STATUS_CANCELLED,
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
        ])->save();

        return $appointment;
    }

    /**
     * Reprograma: actualiza la fecha y vuelve a quedar 'scheduled'. Guarda la
     * fecha anterior en metadata para trazabilidad.
     */
    public function reschedule(MarketingAppointment $appointment, string $scheduledAt, ?int $durationMinutes = null): MarketingAppointment
    {
        $history = $appointment->metadata['reschedules'] ?? [];
        $history[] = ['from' => $appointment->scheduled_at?->toIso8601String(), 'at' => now()->toIso8601String()];

        $appointment->forceFill([
            'scheduled_at'     => $scheduledAt,
            'duration_minutes' => $durationMinutes ?? $appointment->duration_minutes,
            'status'           => MarketingAppointment::STATUS_SCHEDULED,
            'cancelled_at'     => null,
            'completed_at'     => null,
            'metadata'         => array_merge($appointment->metadata ?? [], ['reschedules' => $history]),
        ])->save();

        return $appointment;
    }

    /** @return array<string,mixed> */
    public function present(MarketingAppointment $a): array
    {
        return [
            'id'                        => $a->id,
            'uuid'                      => $a->uuid,
            'marketing_lead_id'         => $a->marketing_lead_id,
            'marketing_conversation_id' => $a->marketing_conversation_id,
            'type'                      => $a->type,
            'status'                    => $a->status,
            'title'                     => $a->title,
            'notes'                     => $a->notes,
            'scheduled_at'              => $a->scheduled_at?->toIso8601String(),
            'duration_minutes'          => (int) $a->duration_minutes,
            'location'                  => $a->location,
            'contact_name'              => $a->contact_name,
            'contact_phone'             => $a->contact_phone,
            'completed_at'              => $a->completed_at?->toIso8601String(),
            'cancelled_at'              => $a->cancelled_at?->toIso8601String(),
            'cancellation_reason'       => $a->cancellation_reason,
            'assigned_to'               => $a->assignedAdmin ? ['id' => $a->assignedAdmin->id, 'name' => $a->assignedAdmin->name] : null,
            'lead'                      => $a->lead ? ['id' => $a->lead->id, 'name' => $a->lead->name, 'phone' => $a->lead->phone] : null,
            'created_at'                => $a->created_at?->toIso8601String(),
        ];
    }
}
