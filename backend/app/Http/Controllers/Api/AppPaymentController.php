<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historial de pagos del miembro autenticado (SOLO lectura).
 *
 * Lee de la tabla `payments` (fuente de verdad del CRM): cualquier pago que vea
 * el admin en su panel — efectivo registrado a mano, aprobaciones de Wompi
 * volcadas por PaymentMembershipActivator, o registros de pagos HISTÓRICOS —
 * se devuelve aquí. No toca pasarelas ni cobros; solo expone datos públicos vía
 * toPublicArray(). Los registros antiguos siguen siendo legibles desde aquí.
 */
class AppPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        $limit = min(max((int) $request->query('limit', 50), 1), 100);

        $payments = $this->scopedQuery($member)
            ->with($this->eagerLoads())
            ->latest('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $payments->map->toPublicArray()->values(),
        ]);
    }

    /**
     * GET /api/app/payments/{reference} — detalle de un pago del miembro.
     *
     * 404 cuando la referencia no existe O no pertenece al miembro (mismo
     * código para no filtrar la existencia de referencias ajenas). Es SOLO
     * lectura: la `payments` guarda pagos ya liquidados; el estado en vuelo de
     * Wompi se consulta por GET /api/payments/wompi/{reference}/status.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        $payment = $this->scopedQuery($member)
            ->where('reference', $reference)
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'Pago no encontrado.'], 404);
        }

        $payment->load($this->eagerLoads());

        return response()->json($payment->toPublicArray());
    }

    /**
     * Scope común: pagos asociados al miembro (member_id directo o, como
     * fallback, user_id enlazado para pagos legados creados por el admin
     * antes de existir member_id).
     */
    private function scopedQuery(Member $member)
    {
        return Payment::query()->where(function ($q) use ($member) {
            $q->where('member_id', $member->id);

            if ($member->user_id) {
                $q->orWhere('user_id', $member->user_id);
            }
        });
    }

    private function eagerLoads(): array
    {
        return [
            'plan:id,name,duration_days',
            'member:id,full_name,document_number,email,phone',
            'user:id,name,email,document,phone,membership_end_date',
            'transaction' => fn ($q) => $q->select([
                'id', 'reference', 'currency', 'provider', 'method', 'provider_ref',
                'description', 'failure_reason', 'customer',
            ]),
        ];
    }
}
