<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\SseStream;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Canal en tiempo real de las cuentas del CRM (SSE).
 *
 * Existe para que el CRM vea CONFIRMADO que una contraseña quedó guardada, y
 * no solo que su petición salió. Es el mismo diseño que el canal de moderación
 * ({@see ModerationRealtimeController}):
 *
 * **No hay tabla de eventos.** Restablecer una contraseña escribe su fila en
 * `audit_logs` dentro de la MISMA transacción que la contraseña
 * ({@see AdminUserController::resetPassword()}). Esa traza ES el evento: si la
 * contraseña no se guardó, la fila no existe y no hay nada que emitir, así que
 * el canal no puede anunciar un cambio que no ocurrió. Una tabla aparte serían
 * dos fuentes que se pueden desincronizar.
 *
 * **Reutiliza la infraestructura existente**: {@see SseStream}, el vale de
 * `?ticket=` y el permiso del mapa central, que aquí es `users.manage`. El
 * canal genérico de notificaciones no sirve para esto: llega a TODAS las
 * sesiones del CRM —recepción y entrenadores incluidos— y deja la fila en la
 * campana de cada uno.
 *
 * **El payload no lleva secretos**: ni la contraseña, ni su hash, ni nombres.
 * Lo justo para correlacionar: qué cuenta, qué operación, en qué modo y cuándo.
 */
class AdminUserRealtimeController extends Controller
{
    /** Nombre público del evento, con la convención `entidad.accion` de los canales del CRM. */
    public const PASSWORD_UPDATED = 'admin_user.password.updated';

    /** Hechos de cuentas que viajan por el canal. */
    private const EVENTOS = [self::PASSWORD_UPDATED];

    /**
     * GET /api/admin/users/stream
     *
     * `?after_id=` reanuda sin perder eventos; sin él se parte del último id y
     * solo llega lo nuevo. El `id` de cada evento SSE es el de la fila de
     * auditoría: el CRM que acaba de restablecer una contraseña recibe ese id en
     * la respuesta y abre el canal desde justo antes para esperarlo.
     */
    public function stream(Request $request): SymfonyResponse
    {
        $cursor = $request->filled('after_id')
            ? (int) $request->query('after_id')
            : (int) (AuditLog::max('id') ?? 0);

        return SseStream::response(function () use (&$cursor): void {
            $filas = AuditLog::query()
                ->where('id', '>', $cursor)
                ->where('module', 'Usuarios')
                ->where('entity', 'usuario')
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach ($filas as $fila) {
                $cursor = (int) $fila->id;
                $evento = self::eventoDe($fila);
                if ($evento !== null) {
                    SseStream::emit('users', $evento, $fila->id);
                }
            }
        }, 25, 1500); // ~25 s por conexión; el cliente reconecta solo.
    }

    /**
     * El payload público de una fila de auditoría, o null si no es un evento
     * del canal.
     *
     * Lista blanca de campos, construida a mano: la traza puede crecer con
     * datos que no deben salir de la base, y lo que no está aquí no viaja.
     *
     * @return array<string, mixed>|null
     */
    public static function eventoDe(AuditLog $fila): ?array
    {
        $meta = is_array($fila->metadata) ? $fila->metadata : [];
        $tipo = $meta['event'] ?? null;
        if (! in_array($tipo, self::EVENTOS, true)) {
            return null;
        }

        $modo = $meta['mode'] ?? null;

        return [
            'type' => $tipo,
            'user_id' => (int) $fila->entity_id,
            'operation_id' => is_string($meta['operation_id'] ?? null) ? $meta['operation_id'] : null,
            'mode' => in_array($modo, ['generated', 'custom'], true) ? $modo : null,
            'status' => 'updated',
            // Quién lo hizo, para no confundir la operación propia con la de otra
            // pestaña o persona. El nombre no viaja: se consulta por la API.
            'actor_admin_id' => $fila->actor_id,
            // Hora del SERVIDOR: el cliente no impone su reloj.
            'timestamp' => $fila->created_at?->toIso8601String(),
        ];
    }
}
