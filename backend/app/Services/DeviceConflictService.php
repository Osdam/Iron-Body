<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberDeviceBinding;
use App\Models\MemberDeviceSession;
use App\Models\MemberSecurityEvent;
use Illuminate\Support\Facades\DB;

/**
 * Conflictos de vínculo dispositivo ↔ cuenta.
 *
 * El guard del login (`AuthController::deviceBindingDenied`) busca el binding
 * GLOBALMENTE por `device_id` y deniega si pertenece a otro miembro. Las
 * acciones de soporte, en cambio, buscaban por `member_id`: cuando el vínculo
 * que bloquea es de OTRO titular, "Restablecer confianza" encontraba 0 filas y
 * el socio seguía sin poder entrar.
 *
 * Este servicio cierra esa asimetría: identifica el vínculo que realmente está
 * bloqueando a un miembro y permite liberarlo de forma acotada y auditada.
 *
 * El device_id no lo aporta el ticket (la app no lo envía al reportar), sino la
 * propia denegación: `member_security_events` de tipo `device_account_mismatch`
 * guarda el `device_id` exacto que fue rechazado y, en metadata, a qué miembro
 * se le denegó. Se parte de ese hecho registrado, no de una suposición.
 */
class DeviceConflictService
{
    /** Ventana de búsqueda del intento denegado. Fuera de ella no hay conflicto vigente. */
    public const LOOKBACK_DAYS = 30;

    public function __construct(private DeviceSessionService $sessions) {}

    /**
     * Vínculo que está impidiendo entrar a $member, o null si no hay ninguno.
     *
     * Devuelve el binding vigente del último dispositivo que se le denegó,
     * SIEMPRE que siga perteneciendo a otro miembro. Si el vínculo ya se liberó
     * o ya es suyo, no hay conflicto.
     */
    public function findConflict(Member $member): ?MemberDeviceBinding
    {
        $deviceIds = MemberSecurityEvent::query()
            ->where('type', MemberSecurityEvent::TYPE_DEVICE_MISMATCH)
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->whereNotNull('device_id')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['device_id', 'metadata'])
            ->filter(fn (MemberSecurityEvent $e) => $this->attemptedMember($e) === $member->id)
            ->pluck('device_id')
            ->unique();

        foreach ($deviceIds as $deviceId) {
            $binding = MemberDeviceBinding::forDevice($deviceId);
            if ($binding && $binding->member_id !== $member->id) {
                return $binding;
            }
        }

        return null;
    }

    /**
     * Resumen del conflicto para el CRM. NUNCA incluye nombre, documento ni
     * teléfono del titular actual del equipo: el staff necesita saber que el
     * equipo está tomado, no por quién.
     */
    public function describe(MemberDeviceBinding $binding): array
    {
        return [
            'device_id_masked' => $this->mask($binding->device_id),
            'device_name' => $binding->device_name,
            'platform' => $binding->platform,
            'bound_at' => optional($binding->bound_at)->toIso8601String(),
            'owner_member_id' => $binding->member_id,
        ];
    }

    /**
     * Libera el vínculo conflictivo y cierra las sesiones de ESE equipo.
     *
     * Acotado a un único `device_id`: no toca otros equipos del titular anterior
     * ni ningún otro miembro. Devuelve el detalle de lo liberado para auditoría.
     *
     * @return array{device_id:string, previous_member_id:int|null, revoked_sessions:int}
     */
    public function release(MemberDeviceBinding $binding, string $reason): array
    {
        $deviceId = $binding->device_id;
        $previousMemberId = $binding->member_id;

        return DB::transaction(function () use ($binding, $deviceId, $previousMemberId, $reason): array {
            // Solo las sesiones vivas de ESTE equipo: si el titular anterior
            // sigue conectado desde otro teléfono, esa sesión no se toca.
            $revoked = 0;
            $sessions = MemberDeviceSession::query()
                ->where('device_id', $deviceId)
                ->whereNull('revoked_at')
                ->get();
            foreach ($sessions as $session) {
                $this->sessions->revoke($session, $reason);
                $revoked++;
            }

            $binding->delete();

            return [
                'device_id' => $deviceId,
                'previous_member_id' => $previousMemberId,
                'revoked_sessions' => $revoked,
            ];
        });
    }

    /** `dev_60109d…2d61` — suficiente para cotejar sin exponer el identificador. */
    public function mask(?string $deviceId): ?string
    {
        if ($deviceId === null || strlen($deviceId) < 12) {
            return $deviceId === null ? null : '***';
        }

        return substr($deviceId, 0, 10).'…'.substr($deviceId, -4);
    }

    /** `metadata.attempted_member` del evento, tolerante al formato de guardado. */
    private function attemptedMember(MemberSecurityEvent $event): ?int
    {
        $meta = $event->metadata;
        if (! is_array($meta)) {
            $meta = (array) json_decode((string) $meta, true);
        }

        return isset($meta['attempted_member']) ? (int) $meta['attempted_member'] : null;
    }
}
