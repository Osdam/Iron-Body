<?php

namespace App\Services\Trainer;

use App\Models\MemberTrainerAssignment;
use App\Models\TrainerRealtimeEvent;
use Illuminate\Support\Facades\Log;

/**
 * Bus de eventos real-time por entrenador. `emit()` inserta una señal de cambio
 * que el stream SSE del portal (GET /trainer/realtime) entrega al instante.
 * BEST-EFFORT: nunca lanza ni bloquea la mutación de negocio. No incluye datos
 * sensibles, solo el tipo y los módulos a refrescar. Espejo de {@see \App\Services\RealtimeEvents}.
 */
class TrainerRealtimeEvents
{
    public const MEMBERS    = 'members.updated';
    public const ASSESSMENT = 'assessment.updated';
    public const ATTENDANCE = 'attendance.updated';
    public const CLASS_EVENT = 'class.updated';

    /** Emite una señal de cambio para un entrenador concreto. */
    public static function emit(?int $trainerId, string $type, array $changed = []): void
    {
        if ($trainerId === null || $trainerId <= 0) {
            return;
        }
        try {
            TrainerRealtimeEvent::create([
                'trainer_id' => $trainerId,
                'type'       => $type,
                'changed'    => $changed,
                'version'    => (int) (microtime(true) * 1000),
                'created_at' => now(),
            ]);

            // Poda: las señales son efímeras (>5 min se borran).
            TrainerRealtimeEvent::query()
                ->where('trainer_id', $trainerId)
                ->where('created_at', '<', now()->subMinutes(5))
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('trainer.realtime.emit_failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Emite a TODOS los entrenadores con una asignación ACTIVA sobre ese miembro
     * (un cliente puede tener entrenador de planta + funcional). Útil cuando
     * cambia algo del cliente (valoración, asistencia, membresía) y hay que
     * refrescar el portal de quien lo atiende.
     */
    public static function forMember(?int $memberId, string $type, array $changed = []): void
    {
        if ($memberId === null || $memberId <= 0) {
            return;
        }
        try {
            $trainerIds = MemberTrainerAssignment::query()
                ->where('member_id', $memberId)
                ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
                ->pluck('trainer_id')
                ->unique();

            foreach ($trainerIds as $trainerId) {
                self::emit((int) $trainerId, $type, $changed);
            }
        } catch (\Throwable $e) {
            Log::warning('trainer.realtime.for_member_failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    // Atajos semánticos para los call-sites.

    /** El CRM asignó/quitó un miembro a/de un entrenador. */
    public static function membersChanged(?int $trainerId): void
    {
        self::emit($trainerId, self::MEMBERS, ['members']);
    }

    /** Cambió/llegó una valoración de un cliente del entrenador. */
    public static function assessmentForMember(?int $memberId): void
    {
        self::forMember($memberId, self::ASSESSMENT, ['assessments', 'members']);
    }
}
