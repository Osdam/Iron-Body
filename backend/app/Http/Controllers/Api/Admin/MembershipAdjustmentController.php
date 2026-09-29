<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipAdjustment;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Membership\MembershipAccess;
use App\Services\Membership\MembershipAdjustments;
use App\Support\Access\AdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * El acceso de un socio, y los días o entradas que se le suman a mano.
 *
 * Son dos permisos distintos a propósito:
 *
 *   `members.view`   → CONSULTAR si puede entrar y cuántas entradas le quedan.
 *                      Lo necesita cualquiera que esté en el mostrador.
 *   `members.adjust` → REGALARLE días o entradas. Eso es dinero del gimnasio, y
 *                      no lo reparte quien corrige un teléfono. De fábrica solo
 *                      lo tienen los administradores; desde Usuarios y roles se
 *                      le puede dar a quien el gimnasio decida.
 */
class MembershipAdjustmentController extends Controller
{
    /**
     * GET /api/users/{user}/access — puede entrar o no, y por qué; más los
     * ajustes que alguien le hizo.
     */
    public function show(User $user): JsonResponse
    {
        $servicio = app(MembershipAdjustments::class);

        return response()->json([
            'member' => ['id' => $user->id, 'name' => $user->name, 'plan' => $user->plan],
            'access' => app(MembershipAccess::class)->state($user),
            'adjustments' => $servicio->history($user)
                ->map(fn (MembershipAdjustment $a) => $servicio->toArray($a))
                ->values(),
            'presets' => [
                'days' => MembershipAdjustments::DAY_PRESETS,
                'entries' => MembershipAdjustments::ENTRY_PRESETS,
            ],
        ]);
    }

    /**
     * POST /api/users/{user}/adjustments — suma (o resta) días o entradas.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', MembershipAdjustment::KINDS)],
            // Con signo: negativo corrige un ajuste anterior. El tope de cada
            // clase lo pone el servicio, que es quien conoce su unidad.
            'amount' => ['required', 'integer', 'not_in:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $servicio = app(MembershipAdjustments::class);
        $esDias = $data['kind'] === MembershipAdjustment::KIND_DAYS;
        $cantidad = (int) $data['amount'];
        $motivo = $data['reason'] ?? null;
        $actor = AdminActor::from($request);

        try {
            $resultado = $esDias
                ? $servicio->addDays($user, $cantidad, $motivo, $actor)
                : $servicio->addEntries($user, $cantidad, $motivo, $actor);
        } catch (RuntimeException $e) {
            // El motivo viaja tal cual: está escrito para quien está en el
            // mostrador, no para un log.
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        /** @var MembershipAdjustment $ajuste */
        $ajuste = $resultado['adjustment'];

        app(AuditTrail::class)->record($request, [
            'action' => 'update',
            'module' => 'Miembros',
            'entity' => 'membresía',
            'entity_id' => (string) $user->id,
            'target_name' => $user->name,
            'summary' => ($cantidad < 0 ? 'Quitó ' : 'Sumó ').$ajuste->label().' a la membresía',
            'metadata' => array_filter([
                'kind' => $ajuste->kind,
                'amount' => $cantidad,
                'reason' => $motivo,
                'previous_end_date' => $ajuste->previous_end_date?->toDateString(),
                'new_end_date' => $ajuste->new_end_date?->toDateString(),
            ], fn ($v) => $v !== null),
        ]);

        $user->refresh();

        return response()->json([
            'ok' => true,
            'adjustment' => $servicio->toArray($ajuste),
            'access' => app(MembershipAccess::class)->state($user),
            // Lo que el CRM tiene que repintar del socio sin recargar la lista.
            'user' => [
                'id' => $user->id,
                'status' => $user->status,
                'plan' => $user->plan,
                'membershipStartDate' => $user->membershipStartDate,
                'membershipEndDate' => $user->membershipEndDate,
            ],
        ], 201);
    }
}
