<?php

namespace App\Services;

use App\Models\CatalogEvent;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Support\Access\TrainerMemberScope;
use Illuminate\Support\Facades\Log;

/**
 * Bus de eventos real-time por miembro (Bloque 2). `emit()` inserta una señal de
 * cambio que el stream SSE privado entrega al instante. Es BEST-EFFORT: nunca
 * lanza ni bloquea la mutación de negocio (pago/story/perfil). No incluye datos
 * sensibles (tokens/OTP/montos), solo el tipo y los módulos afectados.
 */
class RealtimeEvents
{
    /** Tipos de evento (espejo de los handlers del cliente). */
    public const MEMBERSHIP = 'membership.updated';

    public const PAYMENT = 'payment.updated';

    public const PROFILE = 'profile.updated';

    public const PHONE = 'phone.updated';

    public const LIVE_PERMS = 'live.permissions.updated';

    public const STORY_NEW = 'story.created';

    public const STORY_DEL = 'story.deleted';

    public const SECURITY = 'device.security.updated';

    public const APP_STATE = 'app_state.updated';

    public const ASSESSMENT = 'assessment.updated';

    public const ROUTINE = 'routine.updated';

    public const CLASS_EVENT = 'class.updated';

    public const NUTRITION = 'nutrition.updated';

    public const RANKING = 'ranking.updated';

    public const BIOMETRIC = 'biometric.enrolled';

    /**
     * Emite una señal de cambio para un miembro. [$changed] son los módulos que
     * el cliente debe refrescar (p.ej. ['membership']). Nunca rompe el flujo.
     */
    public static function emit(?int $memberId, string $type, array $changed = []): void
    {
        if ($memberId === null || $memberId <= 0) {
            return;
        }
        try {
            MemberRealtimeEvent::create([
                'member_id' => $memberId,
                'type' => $type,
                'changed' => $changed,
                // Versión monotónica (ms) para que el cliente ignore duplicados.
                'version' => (int) (microtime(true) * 1000),
                'created_at' => now(),
            ]);

            // Poda: las señales son efímeras (no histórico). Borra las del miembro
            // con más de 5 min para que la tabla no crezca.
            MemberRealtimeEvent::query()
                ->where('member_id', $memberId)
                ->where('created_at', '<', now()->subMinutes(5))
                ->delete();
        } catch (\Throwable $e) {
            // El real-time es aditivo: si falla, el fallback (resume/TTL) cubre.
            Log::warning('realtime.emit_failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    // Atajos semánticos para los call-sites.
    public static function membership(?int $memberId): void
    {
        self::emit($memberId, self::MEMBERSHIP, ['membership']);
    }

    public static function payment(?int $memberId): void
    {
        self::emit($memberId, self::PAYMENT, ['membership', 'payment']);
    }

    public static function profile(?int $memberId): void
    {
        self::emit($memberId, self::PROFILE, ['profile']);
    }

    public static function phone(?int $memberId): void
    {
        self::emit($memberId, self::PHONE, ['profile']);
    }

    public static function livePermissions(?int $memberId): void
    {
        self::emit($memberId, self::LIVE_PERMS, ['live']);
    }

    public static function security(?int $memberId): void
    {
        self::emit($memberId, self::SECURITY, ['security']);
    }

    /**
     * Cambió la configuración del plan (features/capacidades IA) desde el CRM.
     * El cliente refresca app-state y reevalúa el gating de módulos al instante.
     */
    public static function features(?int $memberId): void
    {
        self::emit($memberId, self::APP_STATE, ['features', 'membership']);
    }

    /** El entrenador asignó/editó una rutina del miembro (CRM). */
    /** Una valoración nueva cambia su progreso y sus datos corporales. */
    public static function assessment(?int $memberId): void
    {
        self::emit($memberId, self::ASSESSMENT, ['assessments', 'progress']);
    }

    public static function routine(?int $memberId): void
    {
        self::emit($memberId, self::ROUTINE, ['routines']);
    }

    /** Cambió el plan/metas de nutrición del miembro (CRM/IA o el propio app). */
    public static function nutrition(?int $memberId): void
    {
        self::emit($memberId, self::NUTRITION, ['nutrition']);
    }

    /** El miembro registró/actualizó su rostro (biometría facial). */
    public static function biometric(?int $memberId): void
    {
        self::emit($memberId, self::BIOMETRIC, ['biometric', 'profile']);
    }

    /**
     * Cambio GLOBAL que afecta a todos los miembros (p.ej. horario/cupo de una
     * clase, o el ranking de entrenadores). Inserta una señal por cada miembro
     * activo en bloque (sin N+1) y poda lo viejo. BEST-EFFORT: nunca rompe el
     * flujo de negocio.
     *
     * Nota de escala: es 1 fila por miembro activo. Para un gimnasio (cientos de
     * miembros) es trivial; las señales se podan a los 5 min.
     */
    public static function broadcastToActiveMembers(string $type, array $changed = []): void
    {
        try {
            $now = now();
            $version = (int) (microtime(true) * 1000);
            $changedJson = json_encode($changed);

            // Sin el alcance del entrenador: esto no lee datos de nadie, solo
            // reparte una señal. Si quien dispara el cambio es una cuenta de
            // entrenador, el scope global dejaría el aviso solo a SUS socios y
            // el resto seguiría viendo el cupo viejo.
            $ids = Member::withoutGlobalScope(TrainerMemberScope::NAME)
                ->where('status', Member::STATUS_ACTIVE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            foreach ($ids->chunk(500) as $chunk) {
                MemberRealtimeEvent::insert(
                    $chunk->map(fn ($id) => [
                        'member_id' => (int) $id,
                        'type' => $type,
                        'changed' => $changedJson,
                        'version' => $version,
                        'created_at' => $now,
                    ])->all()
                );
            }

            // Poda global de señales efímeras (>5 min).
            MemberRealtimeEvent::query()
                ->where('created_at', '<', $now->copy()->subMinutes(5))
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('realtime.broadcast_failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cambió una clase o sus cupos/reservas (afecta a todos).
     *
     * UNA fila en el canal global, no una por socio. Antes cada reserva
     * escribía unas 3.900 filas en `member_realtime_events` (una por socio
     * activo) dentro de la petición. El canal global (`catalog_events`, cursor
     * `c` del mismo stream) lo leen todas las versiones de la app en uso, y
     * todas enrutan por `type`: `class.*` y `reservation.*` refrescan Clases.
     */
    public static function classesChanged(?int $classId = null, ?string $sessionDate = null, string $type = self::CLASS_EVENT): void
    {
        self::global($type, $classId, $sessionDate, ['classes']);
    }

    /**
     * Señal para TODOS los socios, en una sola fila del canal global.
     *
     * Best-effort como el resto: un aviso que no se puede escribir no tumba la
     * operación de negocio. Quien llama decide si va después del commit.
     */
    public static function global(string $type, ?int $classId, ?string $sessionDate, array $changed = []): void
    {
        try {
            CatalogEvent::create([
                'type' => $type,
                'product_id' => null,
                'class_id' => $classId,
                'session_date' => $sessionDate,
                'changed' => $changed,
                'version' => (int) (microtime(true) * 1000),
                'created_at' => now(),
            ]);

            // Poda: las señales de clases son efímeras, como lo eran las de
            // cada socio (>5 min). Solo hacen falta para cubrir una reconexión;
            // al volver de segundo plano la app recarga. Sin poda, la app que
            // volvía tras horas recibía todas las señales de la pausa. Las de
            // producto no se tocan (las poda `realtime:prune`).
            CatalogEvent::query()
                ->where(fn ($q) => $q->where('type', 'like', 'class.%')->orWhere('type', 'like', 'reservation.%'))
                ->where('created_at', '<', now()->subMinutes(5))
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('realtime.global_failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /** Cambió el ranking/datos de entrenadores (afecta a todos). */
    public static function rankingChanged(): void
    {
        self::broadcastToActiveMembers(self::RANKING, ['ranking']);
    }

    /** Snapshot global del miembro cambió (membresía/acceso/días/perfil). */
    public static function appState(?int $memberId): void
    {
        self::emit($memberId, self::APP_STATE, ['membership', 'payment', 'profile']);
    }
}
