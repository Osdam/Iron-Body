<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentReclassification;
use App\Services\Payments\PaymentReclassifier;
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
 *
 * UN COBRO RECLASIFICADO, TAL COMO LO PINTA LA APP PUBLICADA. La app (2.0.x) no
 * se toca, y con su código un cobro `cancelled` sale en rojo, «Cancelado», con
 * el comprobante «Pago no realizado — El pago no se completó». Para alguien que
 * pagó de verdad eso es falso y alarma. Ver {@see presentar()}: se le enseña
 * como lo que es —un pago confirmado del piso de su entrenador—, que la app
 * pinta en verde sin cambiar una línea. Detalle en
 * docs/ops/RECLASIFICACION-DE-COBROS.md.
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
            'data' => $payments->map(fn (Payment $p) => $this->presentar($p))->values(),
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

        return response()->json($this->presentar($payment));
    }

    /**
     * La forma pública del cobro, con la reclasificación dicha como la entiende
     * la app.
     *
     * La app solo distingue `approved` (verde, «Pago confirmado», se puede
     * compartir), `failed`/`cancelled`/`expired` (rojo, «Pago no realizado») y
     * todo lo demás (ámbar, «Pago en verificación», con un botón para
     * actualizar que no cambiaría nada). El dinero de un cobro reclasificado SÍ
     * entró: lo único cierto que la app sabe pintar es `approved`, con el
     * concepto verdadero —el piso del entrenador— en lugar del plan, sin motivo
     * de rechazo y sin vencimiento de membresía, porque el piso no la da.
     * Esconder la fila haría desaparecer de su historial un dinero que pagó.
     *
     * `reclassified` y `reclassification` son ADITIVOS: la app publicada los
     * ignora, y una versión futura puede usarlos. Sin ids internos.
     *
     * @return array<string, mixed>
     */
    private function presentar(Payment $payment): array
    {
        $publico = $payment->toPublicArray();
        $reclasificacion = PaymentReclassifier::inEffect($payment);

        if ($reclasificacion === null) {
            return $publico + ['reclassified' => false, 'reclassification' => null];
        }

        $concepto = self::concepto($reclasificacion);

        return array_merge($publico, [
            'status' => 'approved',
            'description' => $concepto,
            'product' => $concepto,
            'reason' => null,
            'membership_expiry' => null,
            'reclassified' => true,
            'reclassification' => [
                'kind' => $reclasificacion->kind,
                'label' => $reclasificacion->label(),
                'period_label' => $reclasificacion->charge?->periodLabel(),
                'economic_at' => optional($reclasificacion->economic_at)->toIso8601String(),
            ],
        ]);
    }

    /** «Piso de entrenador personalizado · noviembre de 2026». */
    private static function concepto(PaymentReclassification $r): string
    {
        $periodo = $r->charge?->periodLabel();

        return 'Piso de entrenador personalizado'.($periodo ? ' · '.$periodo : '');
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
            // Si el cobro se reclasificó, lo que la app enseña en su lugar.
            'reclassification.charge:id,period',
        ];
    }
}
