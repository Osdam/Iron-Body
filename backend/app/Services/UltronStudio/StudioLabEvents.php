<?php

namespace App\Services\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

/**
 * El libro del laboratorio (contrato H4 §A8), con las MISMAS políticas que el
 * de F/G ({@see StudioAudit}) y en su propia tabla:
 *
 *  - Los éxitos se escriben en la MISMA transacción que el cambio: si la traza
 *    no se puede escribir, el cambio no se confirma.
 *  - Los intentos denegados que se auditan (el veredicto sobre la versión
 *    propia) se escriben APARTE, porque la operación falla, y deduplicados con
 *    un cerrojo: una fila por persona, sujeto, evento y resumen cada
 *    {@see StudioAudit::DENIED_WINDOW_MINUTES} minutos.
 *  - Los valores del `diff` se acotan con {@see StudioAudit::bounded()} a 500
 *    caracteres, y cada lista a 100 elementos.
 *  - SIN IP ni user agent: la tabla ni siquiera tiene esas columnas.
 *
 * QUÉ NO SE AUDITA: las lecturas, las estimaciones, los 4xx ni el progreso de
 * cada caso (las filas de los casos ya son el registro).
 *
 * QUIÉN VE QUÉ. Cualquiera con `ultron.view`, salvo los eventos de las
 * ejecuciones sobre el borrador de otra persona: siguen la visibilidad de la
 * ejecución (su dueño o quien tenga `audit.view`).
 */
final class StudioLabEvents
{
    /** El actor de la importación del catálogo de escenarios. */
    public const SYSTEM_IMPORT = 'ultron-studio:lab-import-scenarios';

    /** El actor de lo que cierra el proceso de la cola (fin, parada o caducidad). */
    public const SYSTEM_RUNNER = 'ultron-studio:lab-runner';

    /** La cota de cada texto del `diff` (contrato §A8). */
    public const MAX_VALUE_LENGTH = 500;

    public const EVENTS = [
        'scenario.created',
        'scenario.updated',
        'scenario.archived',
        'scenario.catalog_imported',
        'scenario.catalog_synced',
        'scenario.catalog_retired',
        'system.lab_catalog_imported',
        'run.created',
        'run.cancel_requested',
        'run.finished',
        'run.verdict_recorded',
        'run.verdict_denied',
    ];

    /**
     * Escribe un evento. Con `$actor` null el actor es el sistema, con el
     * nombre `$systemName`.
     *
     * @param  array{fromStatus?:?string,toStatus?:?string,diff?:array<string,mixed>|null}  $extra
     */
    public function record(?Admin $actor, string $event, string $subjectType, ?string $subjectUuid, string $summary, array $extra = [], string $systemName = self::SYSTEM_IMPORT): StudioLabEvent
    {
        if (! in_array($event, self::EVENTS, true) || ! in_array($subjectType, StudioLabEvent::SUBJECT_TYPES, true)) {
            throw new LogicException("ultron_studio_lab: evento desconocido ({$event} sobre {$subjectType}).");
        }

        return StudioLabEvent::query()->forceCreate([
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_uuid' => $subjectUuid,
            'actor_type' => $actor !== null ? StudioLabEvent::ACTOR_ADMIN : StudioLabEvent::ACTOR_SYSTEM,
            'actor_admin_id' => $actor?->id,
            'actor_name' => mb_substr($actor !== null ? (string) $actor->name : $systemName, 0, 120),
            'actor_role' => $actor?->role !== null ? mb_substr((string) $actor->role, 0, 60) : null,
            'from_status' => $extra['fromStatus'] ?? null,
            'to_status' => $extra['toStatus'] ?? null,
            'summary' => mb_substr($summary, 0, 255),
            'diff' => isset($extra['diff']) ? StudioAudit::bounded($extra['diff'], self::MAX_VALUE_LENGTH) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Un intento denegado. Como el libro no se puede vaciar, una misma persona
     * deja UNA fila por sujeto, evento y resumen cada
     * {@see StudioAudit::DENIED_WINDOW_MINUTES} minutos. Devuelve null cuando ya
     * hay una fila reciente.
     *
     * @param  array{fromStatus?:?string,toStatus?:?string,diff?:array<string,mixed>|null}  $extra
     */
    public function recordDenied(?Admin $actor, string $event, string $subjectType, ?string $subjectUuid, string $summary, array $extra = []): ?StudioLabEvent
    {
        // Comprobar y escribir dentro de una transacción con un cerrojo
        // consultivo (PostgreSQL), como F: sin él, varios intentos simultáneos
        // pasarían todos el `exists()`. SQLite escribe en serie por sí mismo.
        return DB::transaction(function () use ($actor, $event, $subjectType, $subjectUuid, $summary, $extra): ?StudioLabEvent {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32("ultron_studio_lab_events:{$event}:{$subjectUuid}:{$actor?->id}")]);
            }

            $reciente = StudioLabEvent::query()
                ->where('event', $event)
                ->where('subject_type', $subjectType)
                ->where('subject_uuid', $subjectUuid)
                ->where('actor_admin_id', $actor?->id)
                ->where('summary', mb_substr($summary, 0, 255))
                ->where('created_at', '>=', now()->subMinutes(StudioAudit::DENIED_WINDOW_MINUTES))
                ->exists();
            if ($reciente) {
                return null;
            }

            return $this->record($actor, $event, $subjectType, $subjectUuid, $summary, $extra);
        });
    }

    /**
     * Una página del libro, del más reciente al más antiguo, con lo que puede
     * ver quien mira.
     *
     * @param  array{subjectType?:?string,subjectUuid?:?string,event?:?string}  $filters
     */
    public function page(StudioAccess $viewer, array $filters, int $perPage): LengthAwarePaginator
    {
        $consulta = StudioLabEvent::query()
            ->select([
                'id', 'event', 'subject_type', 'subject_uuid', 'actor_type', 'actor_admin_id', 'actor_name',
                'actor_role', 'from_status', 'to_status', 'summary', 'diff', 'created_at',
            ])
            ->when($filters['subjectType'] ?? null, fn ($q, string $tipo) => $q->where('subject_type', $tipo))
            ->when($filters['subjectUuid'] ?? null, fn ($q, string $uuid) => $q->where('subject_uuid', $uuid))
            ->when($filters['event'] ?? null, fn ($q, string $evento) => $q->where('event', $evento))
            ->orderByDesc('id');

        if (! $viewer->seesEveryDraftEvent()) {
            $consulta->where(fn ($q) => $q
                ->where('subject_type', '<>', StudioLabEvent::SUBJECT_RUN)
                ->orWhereIn('subject_uuid', StudioLabRuns::visibleTo(StudioLabRun::query(), $viewer)->select('uuid')));
        }

        return $consulta->paginate($perPage)->through(fn (StudioLabEvent $e): array => self::present($e));
    }

    /**
     * LabEventDto (Enmienda 2, §9): el `AuditEventDto` de G sin `contentHash`.
     *
     * @return array<string, mixed>
     */
    public static function present(StudioLabEvent $e): array
    {
        return [
            'id' => (int) $e->id,
            'event' => $e->event,
            'summary' => $e->summary,
            'actor' => ['type' => $e->actor_type, 'name' => $e->actor_name, 'role' => $e->actor_role],
            'subjectType' => $e->subject_type,
            'subjectUuid' => $e->subject_uuid,
            'fromStatus' => $e->from_status,
            'toStatus' => $e->to_status,
            'diff' => is_array($e->diff) ? ($e->diff === [] ? new stdClass : $e->diff) : null,
            'createdAt' => $e->created_at?->toIso8601String(),
        ];
    }
}
