<?php

namespace App\Services;

use App\Models\AutomationEvent;
use App\Models\MemberTrainerAssignment;
use App\Models\TrainerTask;
use Illuminate\Support\Facades\Log;

/**
 * Genera y gestiona tareas/alertas para el ENTRENADOR HUMANO asignado.
 *
 * Complementa a IRON IA (que notifica al miembro vía app_notifications): cuando
 * una señal de automatización es relevante para el seguimiento y el miembro
 * tiene un entrenador ACTIVO, se crea una tarea accionable para ese entrenador.
 *
 * Reglas:
 *  - Sin entrenador activo → no se crea nada.
 *  - Idempotente por idempotency_key (no duplica por el mismo evento).
 *  - Anti-spam por tipo/miembro/entrenador (ventana 12h, máx 1 por tipo·día).
 *  - Nunca guarda datos sensibles (saneo del payload).
 *  - Trazable: enlaza el automation_event_id de origen.
 */
class TrainerTaskService
{
    /** Anti-spam (alineado con AppNotificationService). */
    private const WINDOW_MINUTES = 720; // 12h
    private const MAX_PER_TYPE_PER_DAY = 1;

    /**
     * Mapa de eventos que SÍ generan tarea para el entrenador → copy seguro.
     * Si un event_type no está aquí, no se crea tarea (defensa por defecto).
     *
     * @var array<string, array{title:string, priority:string, route:string}>
     */
    private const EVENT_MAP = [
        'workout.missed' => [
            'title'    => 'Alumno sin entrenar',
            'priority' => TrainerTask::PRIORITY_HIGH,
            'route'    => '/trainer/members/',
        ],
        'nutrition.missing' => [
            'title'    => 'Alumno sin registrar comidas',
            'priority' => TrainerTask::PRIORITY_NORMAL,
            'route'    => '/trainer/members/',
        ],
        'progress.stalled' => [
            'title'    => 'Progreso estancado',
            'priority' => TrainerTask::PRIORITY_NORMAL,
            'route'    => '/trainer/members/',
        ],
        'evaluation.outdated' => [
            'title'    => 'Evaluación física pendiente',
            'priority' => TrainerTask::PRIORITY_NORMAL,
            'route'    => '/trainer/members/',
        ],
        'membership.expiring' => [
            'title'    => 'Membresía por vencer (retención)',
            'priority' => TrainerTask::PRIORITY_HIGH,
            'route'    => '/trainer/members/',
        ],
        'iron_ai.weekly_summary_ready' => [
            'title'    => 'Resumen semanal disponible',
            'priority' => TrainerTask::PRIORITY_LOW,
            'route'    => '/trainer/members/',
        ],
    ];

    /** ¿Este tipo de evento debe alertar al entrenador? */
    public function isTrainerRelevant(string $eventType): bool
    {
        return array_key_exists($eventType, self::EVENT_MAP);
    }

    /** Id del entrenador ACTIVO del miembro (o null si no tiene). */
    public function activeTrainerIdForMember(int $memberId): ?int
    {
        return MemberTrainerAssignment::query()
            ->where('member_id', $memberId)
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->value('trainer_id');
    }

    /**
     * Crea (si corresponde) la tarea del entrenador a partir de un evento de
     * automatización. Idempotente y best-effort: nunca lanza.
     */
    public function createFromEvent(AutomationEvent $event): ?TrainerTask
    {
        try {
            if ($event->member_id === null || ! $this->isTrainerRelevant($event->event_type)) {
                return null;
            }
            $trainerId = $this->activeTrainerIdForMember($event->member_id);
            if ($trainerId === null) {
                return null; // el miembro no tiene entrenador asignado
            }

            $map = self::EVENT_MAP[$event->event_type];
            $memberName = $this->safeMemberName($event->payload_json ?? []);
            $body = $this->bodyFor($event->event_type, $memberName, $event->payload_json ?? []);

            $result = $this->create(
                trainerId: $trainerId,
                memberId: $event->member_id,
                type: $event->event_type,
                title: $map['title'],
                body: $body,
                priority: $map['priority'],
                actionRoute: $map['route'] . $event->member_id,
                metadata: ['origin' => 'automation_event'],
                automationEventId: $event->id,
                idempotencyKey: 'trainer_task:event:' . $event->id,
            );

            return $result['task'];
        } catch (\Throwable $e) {
            Log::warning('trainer_task.create_from_event.failed', [
                'event_id' => $event->id ?? null,
                'reason'   => class_basename($e),
            ]);
            return null;
        }
    }

    /**
     * Crea una tarea para un entrenador concreto (la usa el endpoint interno
     * notify-trainer disparado por n8n). Valida la asignación entrenador↔miembro.
     *
     * @return array{task: ?TrainerTask, status: string}
     *   status: created | skipped_no_assignment | skipped_duplicate | skipped_limit
     */
    public function createForTrainer(
        int $trainerId,
        int $memberId,
        string $type,
        string $title,
        string $body,
        string $priority = TrainerTask::PRIORITY_NORMAL,
        ?string $actionRoute = null,
        array $metadata = [],
        ?int $automationEventId = null,
        ?string $idempotencyKey = null,
    ): array {
        // El entrenador debe ser el entrenador ACTIVO del miembro.
        if ($this->activeTrainerIdForMember($memberId) !== $trainerId) {
            return ['task' => null, 'status' => 'skipped_no_assignment'];
        }

        return $this->create(
            trainerId: $trainerId,
            memberId: $memberId,
            type: $type,
            title: $title,
            body: $body,
            priority: $priority,
            actionRoute: $actionRoute,
            metadata: $metadata,
            automationEventId: $automationEventId,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Núcleo de creación: idempotencia + anti-spam + saneo.
     *
     * @return array{task: ?TrainerTask, status: string}
     */
    private function create(
        int $trainerId,
        int $memberId,
        string $type,
        string $title,
        string $body,
        string $priority,
        ?string $actionRoute,
        array $metadata,
        ?int $automationEventId,
        ?string $idempotencyKey,
    ): array {
        // 1) Idempotencia por key (mismo evento → misma tarea).
        if ($idempotencyKey !== null) {
            $existing = TrainerTask::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return ['task' => $existing, 'status' => 'skipped_duplicate'];
            }
        }

        // 2) Anti-spam: ya hubo una tarea de este tipo para este par recientemente.
        $recent = TrainerTask::query()
            ->where('trainer_id', $trainerId)
            ->where('member_id', $memberId)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->count();
        if ($recent >= self::MAX_PER_TYPE_PER_DAY) {
            return ['task' => null, 'status' => 'skipped_limit'];
        }

        $task = TrainerTask::create([
            'trainer_id'          => $trainerId,
            'member_id'           => $memberId,
            'automation_event_id' => $automationEventId,
            'type'                => $type,
            'title'               => $title,
            'body'                => $body,
            'priority'            => $priority,
            'status'              => TrainerTask::STATUS_PENDING,
            'action_route'        => $actionRoute,
            'metadata'            => $this->sanitize($metadata),
            'idempotency_key'     => $idempotencyKey,
        ]);

        Log::info('trainer_task.created', [
            'trainer_task_id' => $task->id,
            'trainer_id'      => $trainerId,
            'member_id'       => $memberId,
            'type'            => $type,
        ]);

        return ['task' => $task, 'status' => 'created'];
    }

    // ── Cambios de estado (CRM) ─────────────────────────────────────────────────

    public function markSeen(int $taskId, ?int $trainerId = null): ?TrainerTask
    {
        return $this->transition($taskId, $trainerId, function (TrainerTask $t) {
            if ($t->status === TrainerTask::STATUS_PENDING) {
                $t->update(['status' => TrainerTask::STATUS_SEEN, 'seen_at' => now()]);
            }
        });
    }

    public function markDone(int $taskId, ?int $trainerId = null): ?TrainerTask
    {
        return $this->transition($taskId, $trainerId, function (TrainerTask $t) {
            $t->update([
                'status'       => TrainerTask::STATUS_DONE,
                'completed_at' => now(),
                'seen_at'      => $t->seen_at ?? now(),
            ]);
        });
    }

    public function markDismissed(int $taskId, ?int $trainerId = null): ?TrainerTask
    {
        return $this->transition($taskId, $trainerId, function (TrainerTask $t) {
            $t->update(['status' => TrainerTask::STATUS_DISMISSED, 'seen_at' => $t->seen_at ?? now()]);
        });
    }

    private function transition(int $taskId, ?int $trainerId, callable $apply): ?TrainerTask
    {
        $query = TrainerTask::query()->where('id', $taskId);
        if ($trainerId !== null) {
            $query->where('trainer_id', $trainerId); // scope de seguridad
        }
        $task = $query->first();
        if ($task === null) {
            return null;
        }
        $apply($task);
        return $task->refresh();
    }

    public function pendingCountForTrainer(int $trainerId): int
    {
        return TrainerTask::query()
            ->where('trainer_id', $trainerId)
            ->whereIn('status', [TrainerTask::STATUS_PENDING, TrainerTask::STATUS_SEEN])
            ->count();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /** Nombre del miembro desde el payload saneado (seguro: ya viene saneado). */
    private function safeMemberName(array $payload): string
    {
        $name = $payload['member']['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : 'Tu alumno';
    }

    /** Copy seguro por tipo (sin datos sensibles, sin diagnósticos médicos). */
    private function bodyFor(string $type, string $name, array $payload): string
    {
        return match ($type) {
            'workout.missed' => sprintf(
                '%s no registra entrenamientos hace %s días. Considera un mensaje de seguimiento.',
                $name,
                (string) ($payload['workouts']['missed_days'] ?? 'varios'),
            ),
            'nutrition.missing' => sprintf(
                '%s lleva %s días sin registrar comidas. Revisa su adherencia nutricional.',
                $name,
                (string) ($payload['nutrition']['missing_days'] ?? 'varios'),
            ),
            'progress.stalled' => sprintf(
                'El progreso de %s parece estancado. Podrías ajustar su plan de entrenamiento.',
                $name,
            ),
            'evaluation.outdated' => sprintf(
                '%s no tiene una evaluación física reciente. Agenda una nueva medición.',
                $name,
            ),
            'membership.expiring' => sprintf(
                'La membresía de %s está por vencer (%s días). Apoya la retención.',
                $name,
                (string) ($payload['membership']['expires_in_days'] ?? 'pocos'),
            ),
            'iron_ai.weekly_summary_ready' => sprintf(
                'Ya está disponible el resumen semanal de %s. Revísalo para su seguimiento.',
                $name,
            ),
            default => sprintf('Novedad de seguimiento para %s.', $name),
        };
    }

    /** Saneo recursivo (reusa la lista de claves prohibidas de automation). */
    private function sanitize(array $payload): array
    {
        $forbidden = array_map('strtolower', (array) config('automation.forbidden_keys', []));
        $clean = function (array $data) use (&$clean, $forbidden): array {
            $out = [];
            foreach ($data as $key => $value) {
                $lower = is_string($key) ? strtolower($key) : $key;
                if (is_string($lower)) {
                    foreach ($forbidden as $bad) {
                        if (str_contains($lower, $bad)) {
                            continue 2;
                        }
                    }
                }
                $out[$key] = is_array($value) ? $clean($value) : $value;
            }
            return $out;
        };
        return $clean($payload);
    }
}
