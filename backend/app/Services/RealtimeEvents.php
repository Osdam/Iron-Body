<?php

namespace App\Services;

use App\Models\MemberRealtimeEvent;
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
    public const PAYMENT    = 'payment.updated';
    public const PROFILE    = 'profile.updated';
    public const PHONE      = 'phone.updated';
    public const LIVE_PERMS = 'live.permissions.updated';
    public const STORY_NEW  = 'story.created';
    public const STORY_DEL  = 'story.deleted';
    public const SECURITY   = 'device.security.updated';
    public const APP_STATE  = 'app_state.updated';

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
                'member_id'  => $memberId,
                'type'       => $type,
                'changed'    => $changed,
                // Versión monotónica (ms) para que el cliente ignore duplicados.
                'version'    => (int) (microtime(true) * 1000),
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
}
