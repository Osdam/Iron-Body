<?php

namespace App\Services\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioDraft;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use stdClass;

/**
 * El libro del Studio (contrato §A6): quién hizo qué, sobre qué y cuándo.
 *
 * DÓNDE SE ESCRIBE. Los eventos de ÉXITO van en la MISMA transacción que el
 * cambio, como `MarketingAgentSwitch::set()`: si la traza no se puede escribir,
 * el cambio no se confirma. Los intentos DENEGADOS que el contrato audita
 * (cuatro ojos, publicar, revertir) se escriben aparte, porque la operación
 * falla y su transacción no existe.
 *
 * QUÉ NO SE AUDITA: las lecturas, la simulación de validación, los 4xx de
 * formato ni los conflictos de revisión (en ninguno cambió nada).
 *
 * QUÉ SE GUARDA. Los valores del `diff` son escalares de hasta 120 caracteres
 * (las listas y objetos solo agrupan escalares). El Studio no maneja datos de
 * prospectos. `ip_address` y `user_agent` quedan SIEMPRE NULL en esta fase
 * (libro de solo inserción sin política de retención: Ley 1581); la API no los
 * devuelve. INTENTOS DENEGADOS: una fila por persona, sujeto y evento cada diez
 * minutos, para que nadie inunde un libro que no se puede vaciar.
 *
 * QUIÉN VE QUÉ. Cualquiera con `ultron.view` ve el libro, salvo los eventos de
 * borrador ajenos: esos solo los ve su dueño o quien tenga `audit.view`.
 */
final class StudioAudit
{
    /** El actor de la importación de la línea base. */
    public const SYSTEM_IMPORT = 'ultron-studio:import-baseline';

    public const MAX_VALUE_LENGTH = 120;

    /** Tope defensivo de elementos por lista dentro de un diff. */
    public const MAX_LIST_ITEMS = 100;

    public const EVENTS = [
        'draft.saved',
        'draft.discarded',
        'version.created',
        'version.submitted',
        'version.approved',
        'version.rejected',
        'version.four_eyes_denied',
        'version.publish_refused',
        'version.rollback_refused',
        'rule.created',
        'rule.updated',
        'rule.archived',
        'rule.legacy_imported',
        'rule.legacy_synced',
        'system.baseline_imported',
    ];

    /** Ventana de deduplicación de los intentos denegados. */
    public const DENIED_WINDOW_MINUTES = 10;

    /**
     * Un intento denegado (cuatro ojos, publicar, revertir). Como el libro no
     * se puede vaciar, una misma persona solo deja UNA fila por sujeto, evento
     * y resumen cada {@see DENIED_WINDOW_MINUTES}: repetir el mismo intento no
     * inunda nada, pero aprobar y rechazar, o un motivo de rechazo distinto,
     * sí cuentan aparte. Devuelve null cuando ya hay una fila reciente.
     *
     * @param  array{fromStatus?:?string,toStatus?:?string,contentHash?:?string,diff?:array<string,mixed>|null,metadata?:array<string,mixed>|null}  $extra
     */
    public function recordDenied(?Request $request, ?Admin $actor, string $event, string $subjectType, ?string $subjectUuid, string $summary, array $extra = []): ?StudioAuditEvent
    {
        $reciente = StudioAuditEvent::query()
            ->where('event', $event)
            ->where('subject_type', $subjectType)
            ->where('subject_uuid', $subjectUuid)
            ->where('actor_admin_id', $actor?->id)
            ->where('summary', mb_substr($summary, 0, 255))
            ->where('created_at', '>=', now()->subMinutes(self::DENIED_WINDOW_MINUTES))
            ->exists();
        if ($reciente) {
            return null;
        }

        return $this->record($request, $actor, $event, $subjectType, $subjectUuid, $summary, $extra);
    }

    /**
     * Escribe un evento. Con `$actor` null el actor es el sistema (el comando
     * de importación), con el nombre `$systemName`.
     *
     * @param  array{fromStatus?:?string,toStatus?:?string,contentHash?:?string,diff?:array<string,mixed>|null,metadata?:array<string,mixed>|null}  $extra
     */
    public function record(
        ?Request $request,
        ?Admin $actor,
        string $event,
        string $subjectType,
        ?string $subjectUuid,
        string $summary,
        array $extra = [],
        string $systemName = self::SYSTEM_IMPORT,
    ): StudioAuditEvent {
        $diff = isset($extra['diff']) ? self::bounded($extra['diff']) : null;
        $metadata = isset($extra['metadata']) ? self::bounded($extra['metadata']) : null;

        return StudioAuditEvent::query()->forceCreate([
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_uuid' => $subjectUuid,
            'actor_type' => $actor !== null ? StudioAuditEvent::ACTOR_ADMIN : StudioAuditEvent::ACTOR_SYSTEM,
            'actor_admin_id' => $actor?->id,
            'actor_name' => mb_substr($actor !== null ? (string) $actor->name : $systemName, 0, 120),
            'actor_role' => $actor?->role !== null ? mb_substr((string) $actor->role, 0, 60) : null,
            'from_status' => $extra['fromStatus'] ?? null,
            'to_status' => $extra['toStatus'] ?? null,
            'content_hash' => $extra['contentHash'] ?? null,
            'summary' => mb_substr($summary, 0, 255),
            'diff' => $diff,
            'metadata' => $metadata,
            // Decisión de la fase F (hallazgo S2 del especialista de datos): la IP y
            // el user agent del personal NO se persisten en un libro de solo
            // inserción sin política de retención (Ley 1581). Las columnas existen
            // por si una fase posterior fija la retención; hoy quedan NULL.
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * Una página del libro, del más reciente al más antiguo, con lo que puede
     * ver quien mira.
     *
     * @param  array{subjectType?:?string,subjectUuid?:?string,event?:?string}  $filters
     */
    public function page(StudioAccess $viewer, array $filters, int $perPage): LengthAwarePaginator
    {
        $consulta = StudioAuditEvent::query()
            // Sin ip_address, user_agent ni metadata: no salen de aquí.
            ->select([
                'id', 'event', 'subject_type', 'subject_uuid', 'actor_type', 'actor_admin_id', 'actor_name',
                'actor_role', 'from_status', 'to_status', 'content_hash', 'summary', 'diff', 'created_at',
            ])
            ->when($filters['subjectType'] ?? null, fn ($q, string $tipo) => $q->where('subject_type', $tipo))
            ->when($filters['subjectUuid'] ?? null, fn ($q, string $uuid) => $q->where('subject_uuid', $uuid))
            ->when($filters['event'] ?? null, fn ($q, string $evento) => $q->where('event', $evento))
            ->orderByDesc('id');

        if (! $viewer->seesEveryDraftEvent()) {
            $consulta->where(fn ($q) => $q
                ->where('subject_type', '<>', StudioAuditEvent::SUBJECT_DRAFT)
                ->orWhereIn('subject_uuid', StudioDraft::query()->select('uuid')->where('admin_id', $viewer->id())));
        }

        return $consulta->paginate($perPage)->through(fn (StudioAuditEvent $e): array => self::present($e));
    }

    /** @return array<string, mixed> */
    public static function present(StudioAuditEvent $e): array
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
            'contentHash' => $e->content_hash,
            'diff' => is_array($e->diff) ? ($e->diff === [] ? new stdClass : $e->diff) : null,
            'createdAt' => $e->created_at?->toIso8601String(),
        ];
    }

    /**
     * Recorta un valor para el libro: cada texto a {@see MAX_VALUE_LENGTH}
     * caracteres y cada lista a {@see MAX_LIST_ITEMS} elementos. Los números,
     * booleanos y null pasan tal cual; cualquier otra cosa se convierte en texto.
     */
    public static function bounded(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_VALUE_LENGTH
                ? mb_substr($value, 0, self::MAX_VALUE_LENGTH - 1).'…'
                : $value;
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::bounded(...), array_slice($value, 0, self::MAX_LIST_ITEMS));
            }
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::bounded($v);
            }

            return $out;
        }

        return self::bounded(get_debug_type($value));
    }
}
