<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\Payments\NequiException;
use App\Services\Payments\NequiPushPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nequi DIRECTO (Pagos con notificación Push) — proveedor independiente de la
 * pasarela Wompi. push/status/reverse exigen sesión de miembro (auth.member);
 * confirmation/response son webhooks S2S públicos.
 *
 * La membresía SOLO se activa por estado `approved` (webhook/consulta), nunca
 * desde la app ni en createPushPayment. Monto autoritativo desde el plan.
 */
class NequiPaymentController extends Controller
{
    public function __construct(
        private NequiPushPaymentService $nequi,
        private MembershipService $memberships,
    ) {
    }

    /** POST /api/payments/nequi/push — inicia el cobro push (requiere member). */
    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id'         => 'required|integer|exists:plans,id',
            'phone'           => 'required|string|min:10|max:13',
            'idempotency_key' => 'nullable|string|max:120',
        ]);

        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        // Deshabilitado → respuesta controlada (sin crear transacción ni cobrar).
        if (! $this->nequi->isEnabled()) {
            return response()->json([
                'ok'       => false,
                'status'   => 'unavailable',
                'provider' => NequiPushPaymentService::PROVIDER,
                'method'   => NequiPushPaymentService::METHOD,
                'message'  => 'Nequi directo está en proceso de activación. '
                    . 'Usa PSE, tarjeta o DaviPlata.',
            ]);
        }

        $plan = Plan::find($data['plan_id']);
        if (! $plan) {
            return response()->json([
                'ok' => false, 'status' => 'failed',
                'provider' => NequiPushPaymentService::PROVIDER,
                'method'   => NequiPushPaymentService::METHOD,
                'message'  => 'El plan seleccionado no existe.',
            ], 422);
        }

        try {
            $tx = $this->nequi->createPushPayment(
                $member,
                $plan,
                $data['phone'],
                $data['idempotency_key'] ?? ''
            );
        } catch (NequiException $e) {
            return response()->json([
                'ok'       => false,
                'status'   => $e->unavailable ? 'unavailable' : 'failed',
                'provider' => NequiPushPaymentService::PROVIDER,
                'method'   => NequiPushPaymentService::METHOD,
                'message'  => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            Log::error('nequi.push.controlled_error', [
                'provider' => NequiPushPaymentService::PROVIDER,
                'detail'   => mb_substr($e->getMessage(), 0, 200),
            ]);
            return response()->json([
                'ok'       => false,
                'status'   => 'failed',
                'provider' => NequiPushPaymentService::PROVIDER,
                'method'   => NequiPushPaymentService::METHOD,
                'message'  => 'No pudimos iniciar el pago con Nequi. Intenta nuevamente.',
            ]);
        }

        return response()->json($this->txResponse($tx));
    }

    /** GET /api/payments/nequi/{reference}/status — estado real (requiere member). */
    public function status(Request $request, string $reference): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        $tx = PaymentTransaction::where('reference', $reference)
            ->where('provider', NequiPushPaymentService::PROVIDER)
            ->first();
        if (! $tx || ($member && $tx->member_id && $tx->member_id !== $member->id)) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        $tx = $this->nequi->getPaymentStatus($tx);

        return response()->json($this->txResponse($tx));
    }

    /** POST /api/payments/nequi/confirmation — webhook Nequi (público, idempotente). */
    public function confirmation(Request $request): JsonResponse
    {
        $this->nequi->handleWebhook($request->all(), $request->headers->all());
        return response()->json(['received' => true]);
    }

    /** GET /api/payments/nequi/response — retorno S2S informativo (público). */
    public function response(Request $request): JsonResponse
    {
        Log::info('nequi.response.hit', [
            'provider'  => NequiPushPaymentService::PROVIDER,
            'reference' => $request->query('reference'),
        ]);
        return response()->json(['ok' => true]);
    }

    /** POST /api/payments/nequi/{reference}/reverse — reverso (no revierte membresía). */
    public function reverse(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:160']);
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        $tx = PaymentTransaction::where('reference', $reference)
            ->where('provider', NequiPushPaymentService::PROVIDER)
            ->first();
        if (! $tx || ($member && $tx->member_id && $tx->member_id !== $member->id)) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        try {
            $tx = $this->nequi->reversePayment($tx, $data['reason'] ?? 'Solicitud del usuario');
        } catch (NequiException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        return response()->json($this->txResponse($tx));
    }

    /**
     * Contrato hacia la app: datos públicos de la transacción + acceso real al
     * Home (REGLA CENTRAL: solo membresía activa) + mensaje + expiración.
     */
    private function txResponse(PaymentTransaction $tx): array
    {
        $user = $tx->user_id
            ? User::find($tx->user_id)
            : ($tx->member_id ? optional(Member::find($tx->member_id))->user : null);
        $membershipActive = $user ? $this->memberships->isActive($user) : false;

        return array_merge($tx->toPublicArray(), [
            'ok'                => $tx->status !== PaymentTransaction::STATUS_FAILED,
            'provider'          => NequiPushPaymentService::PROVIDER,
            'method'            => NequiPushPaymentService::METHOD,
            'flow'              => 'nequi_push',
            'expires_at'        => $this->nequi->expiresAtFor($tx),
            'membership_active' => $membershipActive,
            'can_access_home'   => $membershipActive,
            'message'           => $this->nequi->statusMessage($tx->status),
        ]);
    }
}
