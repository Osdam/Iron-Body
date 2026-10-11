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
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MEJORAR DE PLAN A MITAD DE PERIODO.
 *
 * Paga el Mensual el día 1 y el día 8 quiere el Total Access. Se le cobra la
 * diferencia de precio. La duración destino descuenta los días calendario ya
 * consumidos del periodo vigente. Ver {@see PlanUpgrades}.
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
        try {
            $resultado = DB::transaction(function () use ($request, $data, $user, $nuevo, $upgrades): array {
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

                app(AuditTrail::class)->record($request, [
                    'action' => 'create',
                    'module' => 'Pagos',
                    'entity' => 'mejora de plan',
                    'entity_id' => (string) $resultado['payment']->id,
                    'target_name' => $user->name,
                    'summary' => 'Mejoró el plan de '.$resultado['preview']['from']['name'].' a '.$resultado['preview']['to']['name'],
                    'metadata' => [
                        'event' => 'membership.plan.upgraded',
                        'user_id' => $user->id,
                        'plan_id' => $resultado['payment']->plan_id,
                        'changed' => ['membership', 'payments', 'history', 'features', 'plans'],
                        'from_plan' => $resultado['preview']['from']['name'],
                        'to_plan' => $resultado['preview']['to']['name'],
                        'amount' => $resultado['preview']['amount'],
                        'starts_on' => $resultado['preview']['starts_on'],
                        'previous_ends_on' => $resultado['preview']['previous_ends_on'],
                        'ends_on' => $resultado['preview']['ends_on'],
                        'days_consumed' => $resultado['preview']['days_consumed'],
                        'duration_days' => $resultado['preview']['duration_days'],
                    ],
                ]);

                return $resultado;
            });
        } catch (CashShiftException) {
            return response()->json([
                'ok' => false,
                'message' => 'No hay una caja del gimnasio abierta. Ábrela para cobrar la diferencia.',
            ], 422);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $user->refresh();

        return response()->json([
            'ok' => true,
            'payment_id' => $resultado['payment']->id,
            'amount' => $resultado['preview']['amount'],
            'preview' => $resultado['preview'],
            'user' => [
                'id' => $user->id,
                'plan' => $user->plan,
                'membershipStartDate' => $user->membershipStartDate,
                'membershipEndDate' => $user->membershipEndDate,
            ],
        ], 201);
    }
}
