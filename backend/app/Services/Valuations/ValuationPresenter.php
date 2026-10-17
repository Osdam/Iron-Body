<?php

namespace App\Services\Valuations;

use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\User;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use App\Models\ValuationReminder;
use App\Support\Access\TrainerMemberScope;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * La forma `appointment` del contrato §4, calculada para QUIEN MIRA.
 *
 * Mínimo dato del socio: nombre, los 4 últimos del documento y el estado de la
 * membresía. Ni teléfono, ni correo, ni documento entero. Las notas, solo con
 * `view_all` o si la cita es del propio entrenador.
 *
 * El socio y su cuenta se cargan SIN el alcance de `TrainerMemberScope`: ese
 * alcance limita a un entrenador a sus socios ASIGNADOS, y aquí la regla es
 * otra —la cita es suya aunque el socio no lo sea (contrato §0.6)—. El acceso a
 * la cita ya lo decidió {@see ValuationAccess}; sin esto, el entrenador vería
 * sus propias citas con el socio en blanco.
 */
final class ValuationPresenter
{
    /** Relaciones que necesita present(), en una consulta por relación. */
    public static function relations(): array
    {
        return [
            'member' => fn ($q) => $q->withoutGlobalScope(TrainerMemberScope::NAME)
                ->select(['id', 'user_id', 'full_name', 'document_number']),
            'member.user' => fn ($q) => $q->withoutGlobalScope(TrainerMemberScope::NAME)
                ->select(['id', 'membership_end_date']),
            'trainer:id,full_name,status,location',
        ];
    }

    /**
     * @param  Collection<int, ValuationAppointment>  $citas
     * @return list<array<string,mixed>>
     */
    public function many(Collection $citas, ValuationAccess $access): array
    {
        $citas = new EloquentCollection($citas->all());
        $citas->loadMissing(self::relations());
        $asignados = $this->assignmentMap($citas->pluck('member_id')->unique()->values()->all());

        return $citas->map(fn (ValuationAppointment $c) => $this->one($c, $access, $asignados))->values()->all();
    }

    /**
     * @param  array<int, list<int>>|null  $asignados  member_id → trainer_ids con asignación activa
     * @return array<string,mixed>
     */
    public function one(ValuationAppointment $c, ValuationAccess $access, ?array $asignados = null): array
    {
        $c->loadMissing(self::relations());
        $asignados ??= $this->assignmentMap([(int) $c->member_id]);
        [$estado, $etiqueta] = self::membership($c->member);
        $propia = $access->owns((int) $c->trainer_id);
        $local = ValuationTime::local($c->starts_at);

        return [
            'uuid' => $c->uuid,
            'type' => $c->type,
            'type_label' => ValuationAppointment::TYPE_LABELS[$c->type] ?? $c->type,
            'status' => $c->status,
            'status_label' => ValuationAppointment::STATUS_LABELS[$c->status] ?? $c->status,
            'starts_at' => $c->starts_at->copy()->utc()->toIso8601String(),
            'ends_at' => $c->ends_at->copy()->utc()->toIso8601String(),
            'duration_minutes' => (int) $c->duration_minutes,
            'local_date' => $local->toDateString(),
            'local_start' => ValuationTime::hm($c->starts_at),
            'local_end' => ValuationTime::hm($c->ends_at),
            'member' => [
                'id' => (int) $c->member_id,
                'name' => (string) ($c->member?->full_name ?? ''),
                'document_tail' => self::documentTail($c->member?->document_number),
                'membership_status' => $estado,
                'membership_label' => $etiqueta,
            ],
            'trainer' => [
                'id' => (int) $c->trainer_id,
                'name' => (string) ($c->trainer?->full_name ?? ''),
                'is_assigned_to_member' => in_array((int) $c->trainer_id, $asignados[(int) $c->member_id] ?? [], true),
            ],
            'notes' => ($access->viewsAll() || $propia) ? $c->notes : null,
            'cancellation_reason' => $c->cancellation_reason,
            'version' => (int) $c->version,
            'source' => $c->source,
            'created_by_name' => $c->created_by_name,
            'created_at' => $c->created_at?->copy()->utc()->toIso8601String(),
            'allowed_actions' => $this->allowedActions($c, $access),
        ];
    }

    /** @return array<string,mixed> appointment + history + reminders */
    public function detail(ValuationAppointment $c, ValuationAccess $access): array
    {
        $historial = ValuationAppointmentEvent::query()
            ->where('appointment_id', $c->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ValuationAppointmentEvent $e) => [
                'action' => $e->action,
                'label' => ValuationAppointmentEvent::ACTION_LABELS[$e->action] ?? $e->action,
                'actor_name' => $e->actor_name,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'from_starts_at' => $e->from_starts_at?->copy()->utc()->toIso8601String(),
                'to_starts_at' => $e->to_starts_at?->copy()->utc()->toIso8601String(),
                'reason' => $e->reason,
                'created_at' => $e->created_at?->copy()->utc()->toIso8601String(),
            ])
            ->all();

        // Sin contenido: el texto no se guarda en el outbox, y el de la
        // notificación es del socio.
        $avisos = ValuationReminder::query()
            ->where('appointment_id', $c->id)
            ->orderBy('due_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ValuationReminder $r) => [
                'kind' => $r->kind,
                'channel' => $r->channel,
                'status' => $r->status,
                'due_at' => $r->due_at?->copy()->utc()->toIso8601String(),
                'processed_at' => $r->processed_at?->copy()->utc()->toIso8601String(),
            ])
            ->all();

        return array_merge($this->one($c, $access), ['history' => $historial, 'reminders' => $avisos]);
    }

    /**
     * Qué puede hacer QUIEN MIRA con esta cita, ahora. El CRM pinta los botones
     * con esto y no replica la máquina de estados.
     *
     * @return list<string>
     */
    public function allowedActions(ValuationAppointment $c, ValuationAccess $access): array
    {
        if (! $access->covers($c)) {
            return [];
        }

        $ahora = Carbon::now()->utc();
        $inicio = $c->starts_at->copy()->utc();
        $estado = $c->status;
        $abierta = in_array($estado, ValuationAppointment::OPEN_STATUSES, true);
        $completa = $access->can(ValuationAccess::COMPLETE);
        $acciones = [];

        if ($completa && $estado === ValuationAppointment::STATUS_SCHEDULED && $ahora->lt($inicio)) {
            $acciones[] = 'confirm';
        }
        if ($completa && $abierta && $ahora->gte($inicio->copy()->subMinutes(ValuationAgenda::START_EARLY_MINUTES))) {
            $acciones[] = 'start';
        }
        if ($completa && in_array($estado, ValuationAppointment::ACTIVE_STATUSES, true) && $ahora->gte($inicio)) {
            $acciones[] = 'complete';
        }
        if ($completa && $abierta && $ahora->gte($inicio)) {
            $acciones[] = 'no_show';
        }
        if ($abierta && $access->can(ValuationAccess::CANCEL)) {
            $acciones[] = 'cancel';
        }
        if ($abierta && $access->can(ValuationAccess::UPDATE)) {
            $acciones[] = 'reschedule';
        }
        if ($access->can(ValuationAccess::UPDATE)) {
            $acciones[] = 'edit';
        }

        return $acciones;
    }

    /** @return array{0: string, 1: string} estado y etiqueta de la membresía */
    public static function membership(?Member $socio): array
    {
        $usuario = $socio?->user;
        $fin = $usuario instanceof User ? $usuario->membership_end_date : null;
        if ($fin === null) {
            return ['none', 'Sin membresía'];
        }

        return $fin < ValuationTime::today()->toDateString()
            ? ['expired', 'Vencida']
            : ['active', 'Activa'];
    }

    /** Los 4 últimos caracteres del documento, nunca el documento entero. */
    public static function documentTail(?string $documento): ?string
    {
        $limpio = preg_replace('/[^0-9A-Za-z]/', '', (string) $documento);
        if ($limpio === null || $limpio === '') {
            return null;
        }

        return mb_substr($limpio, -4);
    }

    /**
     * member_id → trainer_ids con asignación ACTIVA. Una consulta.
     *
     * @param  list<int>  $memberIds
     * @return array<int, list<int>>
     */
    public function assignmentMap(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $out = [];
        MemberTrainerAssignment::query()
            ->whereIn('member_id', $memberIds)
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->get(['member_id', 'trainer_id'])
            ->each(function ($a) use (&$out): void {
                $out[(int) $a->member_id][] = (int) $a->trainer_id;
            });

        return $out;
    }
}
