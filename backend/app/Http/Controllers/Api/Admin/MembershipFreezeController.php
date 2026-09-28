<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Membership\MembershipFreeze;
use App\Support\Access\AdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Congelar y reanudar la membresía de un socio.
 *
 * Exige `members.freeze`, que es un permiso PROPIO y no parte de «editar
 * miembros»: congelar mueve la fecha de vencimiento y con ella el dinero que el
 * socio ya pagó, así que no es lo mismo que corregirle el teléfono. Recepción no
 * lo tiene de fábrica; se concede desde Usuarios y roles a quien deba tenerlo.
 *
 * Las dos operaciones quedan AUDITADAS con quién, cuántos días y por qué: una
 * pausa que nadie firma es una vigencia que se alarga sin explicación.
 */
class MembershipFreezeController extends Controller
{
    public function freeze(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'days' => 'required|integer|min:'.MembershipFreeze::MIN_DAYS.'|max:'.MembershipFreeze::MAX_DAYS,
            'reason' => 'nullable|string|max:255',
        ]);

        $servicio = app(MembershipFreeze::class);

        try {
            $estado = $servicio->freeze(
                $user,
                (int) $data['days'],
                $data['reason'] ?? null,
                AdminActor::from($request),
            );
        } catch (RuntimeException $e) {
            // El motivo viaja tal cual: lo escribe el servicio pensando en quien
            // está en el mostrador, no en quien lee un log.
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        app(AuditTrail::class)->record($request, [
            'action' => 'status',
            'module' => 'Miembros',
            'entity' => 'membresía',
            'entity_id' => (string) $user->id,
            'target_name' => $user->name,
            'summary' => 'Congeló la membresía '.$data['days'].' días',
            'metadata' => [
                'days' => (int) $data['days'],
                'until' => $estado['until'] ?? null,
                'days_left' => $estado['days_left'] ?? null,
                'reason' => $data['reason'] ?? null,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'freeze' => $estado,
            'user' => $this->membershipOf($user->refresh()),
        ], 201);
    }

    public function resume(Request $request, User $user): JsonResponse
    {
        $servicio = app(MembershipFreeze::class);

        try {
            $resultado = $servicio->resume($user, AdminActor::from($request), 'manual');
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        app(AuditTrail::class)->record($request, [
            'action' => 'status',
            'module' => 'Miembros',
            'entity' => 'membresía',
            'entity_id' => (string) $user->id,
            'target_name' => $user->name,
            'summary' => 'Reanudó la membresía y le devolvió '.$resultado['days_returned'].' días',
            'metadata' => [
                'days_returned' => $resultado['days_returned'],
                'membership_end_date' => $user->refresh()->membership_end_date,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'days_returned' => $resultado['days_returned'],
            'user' => $this->membershipOf($user->refresh()),
        ]);
    }

    /** Lo que el CRM necesita repintar del socio tras congelar o reanudar. */
    private function membershipOf(User $user): array
    {
        return [
            'id' => $user->id,
            'status' => $user->status,
            'plan' => $user->plan,
            'membershipStartDate' => $user->membershipStartDate,
            'membershipEndDate' => $user->membershipEndDate,
            'freeze' => app(MembershipFreeze::class)->state($user),
        ];
    }
}
