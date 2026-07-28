<?php

namespace App\Services\Moderation;

use App\Models\Admin;
use App\Models\ModerationAuditLog;
use App\Support\Moderation\AuditSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Escritor único de la bitácora de moderación.
 *
 * Dos salidas por cada hecho:
 *  1. Fila append-only en `moderation_audit_logs` (traza legal, consultable).
 *  2. Log estructurado en el canal de la app (observabilidad / alertas).
 *
 * Es BEST-EFFORT hacia arriba: si la bitácora falla, se registra el fallo pero
 * NUNCA se rompe la operación de moderación en curso. La alternativa —abortar
 * una suspensión porque no se pudo escribir un log— sería peor.
 *
 * Todo payload pasa por {@see AuditSanitizer}: nunca entran tokens, URLs
 * firmadas, documentos ni teléfonos.
 */
class ModerationAudit
{
    public const ACTOR_MEMBER = 'member';

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_SYSTEM = 'system';

    /**
     * Registra un hecho de moderación.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $actorType,
        ?int $actorId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null,
    ): void {
        $cleanBefore = AuditSanitizer::clean($before);
        $cleanAfter = AuditSanitizer::clean($after);

        try {
            ModerationAuditLog::create([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'before_data' => $cleanBefore,
                'after_data' => $cleanAfter,
                'ip_hash' => AuditSanitizer::hashIp($request?->ip()),
                'user_agent' => AuditSanitizer::summarizeUserAgent(
                    $request?->headers->get('User-Agent')
                ),
                'request_id' => AuditSanitizer::requestId($request),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('moderation.audit_write_failed', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }

        // Log estructurado paralelo — el equipo puede alertar sobre estos
        // eventos sin consultar la base de datos.
        Log::info("moderation.{$action}", array_filter([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'after' => $cleanAfter,
        ], fn ($v) => $v !== null && $v !== []));
    }

    /** Atajo: el actor es un miembro de la app. */
    public function member(
        int $memberId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $after = null,
        ?Request $request = null,
    ): void {
        $this->record(self::ACTOR_MEMBER, $memberId, $action, $entityType, $entityId, null, $after, $request);
    }

    /** Atajo: el actor es un administrador del CRM. */
    public function admin(
        ?Admin $admin,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null,
    ): void {
        $this->record(
            $admin instanceof Admin ? self::ACTOR_ADMIN : self::ACTOR_SYSTEM,
            $admin?->id,
            $action,
            $entityType,
            $entityId,
            $before,
            $after,
            $request,
        );
    }

    /** Atajo: lo hizo el propio sistema (reglas defensivas, jobs de limpieza). */
    public function system(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $after = null,
    ): void {
        $this->record(self::ACTOR_SYSTEM, null, $action, $entityType, $entityId, null, $after, null);
    }
}
