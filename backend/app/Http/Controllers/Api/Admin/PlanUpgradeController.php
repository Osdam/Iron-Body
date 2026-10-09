<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CashShiftType;
use App\Exceptions\CashShiftException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Caja\CashShiftService;
use App\Services\Payments\PlanUpgrades;
use App\Support\Access\AdminActor;
use App\Support\Caja\PaymentMethodKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * MEJORAR DE PLAN A MITAD DE PERIODO.
 *
 * Paga el Mensual el día 1 y el día 8 quiere el Total Access. Se le cobra la
 * diferencia de precio y el vencimiento NO se mueve: se mejora lo que le queda
 * del periodo que ya compró. Ver {@see PlanUpgrades} para el porqué de las dos
 * reglas.
 *
 * EL IMPORTE NO LLEGA DEL CLIENTE. Se calcula aquí a partir de los dos planes;
 * si viajara en el cuerpo, el precio de una mejora sería lo que diga el
 * navegador.
 */
class PlanUpgradeController extends Controller
{
    /**
     * GET /api/users/{user}/plan-upgrade?plan_id=3 — qué pasaría.
     *
     * Devuelve también por qué NO se puede, cuando no se puede: el mostrador
     * necesita decirle algo a quien tiene delante, no encontrarse un botón
     * apagado sin explicación.
     */
    public function preview(Request $request, User $user, PlanUpgrades $upgrades): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        return response()->json($upgrades->preview($user, Plan::findOrFail($data['plan_id'])));
    }

    /**
     * POST /api/users/{user}/plan-upgrade — cobra la diferencia y cambia el plan.
     */
    public function store(Request $request, User $user, PlanUpgrades $upgrades): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'method' => ['required', 'string', 'max:30'],
        ]);

        $nuevo = Plan::findOrFail($data['plan_id']);
        $anterior = $upgrades->currentPlan($user);

        try {
            // El turno se exige ANTES de escribir nada: si no hay caja abierta
            // no hay dónde meter el dinero, y conviene fallar antes de cambiar
            // el plan del socio.
            $turno = app(CashShiftService::class)->requireOpen(CashShiftType::GYM);

            $resultado = $upgrades->upgrade(
                $user,
                $nuevo,
                PaymentMethodKind::normalize($data['method'])->value,
                AdminActor::from($request),
                $turno->id,
            );
        } catch (CashShiftException) {
            return response()->json([
                'ok' => false,
                'message' => 'No hay una caja del gimnasio abierta. Ábrela para cobrar la diferencia.',
            ], 422);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        app(AuditTrail::class)->record($request, [
            'action' => 'create',
            'module' => 'Pagos',
            'entity' => 'mejora de plan',
            'entity_id' => (string) $resultado['payment']->id,
            'target_name' => $user->name,
            'summary' => 'Mejoró el plan de '.($anterior?->name ?? 'sin plan').' a '.$nuevo->name,
            'metadata' => [
                'from_plan' => $anterior?->name,
                'to_plan' => $nuevo->name,
                'amount' => $resultado['preview']['amount'],
                // Se deja constancia de que el vencimiento NO se movió: es la
                // pregunta que llega después.
                'ends_on' => $resultado['preview']['ends_on'],
            ],
        ]);

        $user->refresh();

        return response()->json([
            'ok' => true,
            'payment_id' => $resultado['payment']->id,
            'amount' => $resultado['preview']['amount'],
            'user' => [
                'id' => $user->id,
                'plan' => $user->plan,
                'membershipStartDate' => $user->membershipStartDate,
                'membershipEndDate' => $user->membershipEndDate,
            ],
        ], 201);
    }
}
