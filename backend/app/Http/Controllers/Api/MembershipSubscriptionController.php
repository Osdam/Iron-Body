<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SubscriptionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\CreateSubscriptionRequest;
use App\Http\Requests\Subscriptions\ReplacePaymentSourceRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Member;
use App\Models\MembershipSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Models\WompiPaymentSource;
use App\Services\Subscriptions\MembershipSubscriptionService;
use App\Services\Subscriptions\RecurringBillingService;
use App\Models\SubscriptionEvent;
use App\Services\Wompi\WompiAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Pago automático de membresías para el MIEMBRO autenticado (auth.member). El
 * sujeto se toma del miembro (no del body). El monto/duración son autoritativos
 * del backend. TODO queda detrás de WOMPI_RECURRING_ENABLED: si está apagado,
 * `authorize` informa `recurring_enabled=false` y `store` responde 503 controlado.
 * Nunca devuelve secretos ni tokens sensibles.
 */
class MembershipSubscriptionController extends Controller
{
    /**
     * POST /memberships/subscriptions/authorize — datos para iniciar la
     * autorización/consentimiento/tokenización. NO cobra, NO activa membresía.
     */
    public function authorize(Request $request): JsonResponse
    {
        $request->validate(['plan_id' => 'nullable|integer|exists:plans,id']);
        $cfg = (array) config('wompi');
        $enabled = (bool) ($cfg['recurring']['enabled'] ?? false);

        $plan = $request->filled('plan_id') ? Plan::find($request->integer('plan_id')) : null;

        return response()->json([
            'recurring_enabled' => $enabled,
            'methods'           => $cfg['recurring']['methods'] ?? ['card' => true, 'nequi' => false],
            'environment'       => $cfg['env'] ?? 'sandbox',
            'public_key'        => $cfg['public_key'] ?? null, // pública, NO secreta
            'currency'          => $cfg['currency'] ?? 'COP',
            // Con el flag apagado NO se consulta a Wompi (evita tráfico innecesario).
            'acceptance'        => $enabled ? WompiAcceptanceService::make()->publicForApp() : ['available' => false],
            'plan'              => $plan ? [
                'id'            => $plan->id,
                'name'          => $plan->name,
                'price'         => (float) $plan->price,
                'duration_days' => (int) $plan->duration_days,
            ] : null,
        ]);
    }

    /**
     * POST /memberships/subscriptions — crea la fuente (si aplica), la suscripción
     * (pending_first_payment) y ejecuta el primer cobro. Idempotente (1 viva por
     * miembro + billing_period único). Estado: active|pending_first_payment|pending|
     * failed|payment_source_unavailable|already_subscribed.
     */
    public function store(CreateSubscriptionRequest $request): JsonResponse
    {
        $member = $this->member($request);
        $user   = $this->resolveUser($member);

        $data = array_merge($request->safe()->except(['accepted_terms', 'accepted_personal_data']), [
            // El sujeto SIEMPRE del miembro autenticado (nunca del body).
            'member_id'      => $member->id,
            'user_id'        => $user->id,
            'customer_email' => $member->email ?: $user->email,
            'customer'       => [
                'email'      => $member->email ?: $user->email,
                'name'       => $member->full_name,
                'phone'      => $member->phone,
                'doc_number' => $member->document_number,
            ],
        ]);

        try {
            $out = MembershipSubscriptionService::make()
                ->subscribeWithFirstCharge($data, $request->ip(), $request->userAgent());
        } catch (SubscriptionException $e) {
            return response()->json([
                'ok'         => false,
                'error_code' => $e->errorCode,
                'message'    => $e->getMessage(),
            ], $e->status);
        }

        $status = $this->responseStatus($out);

        return response()->json([
            'ok'           => ! in_array($status, ['failed', 'payment_source_unavailable'], true),
            'status'       => $status,
            'message'      => $this->statusMessage($status),
            'subscription' => new SubscriptionResource($out['subscription']->load(['plan', 'paymentSource'])),
        ]);
    }

    /**
     * Estado CLARO de la suscripción para la app, derivado del estado real de la
     * suscripción (autoritativo) y del desenlace del cobro:
     * active | pending_first_payment | past_due | failed | payment_source_unavailable.
     */
    private function responseStatus(array $out): string
    {
        $sub = $out['subscription'];

        if ($out['status'] === 'payment_source_unavailable') {
            return 'payment_source_unavailable';
        }
        if ($sub->status === MembershipSubscription::STATUS_ACTIVE) {
            return 'active';
        }
        if ($sub->status === MembershipSubscription::STATUS_PAST_DUE) {
            return 'past_due';
        }
        // pending_first_payment: distinguir cobro fallido de pendiente de confirmación.
        $chargeStatus = $out['charge']?->status;
        if (in_array($chargeStatus, ['declined', 'voided', 'error'], true)) {
            return 'failed';
        }
        return 'pending_first_payment';
    }

    /** GET /memberships/subscriptions/current — suscripción vigente del miembro. */
    public function current(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $sub = MembershipSubscription::query()
            ->where('member_id', $member->id)
            ->with(['plan', 'paymentSource'])
            ->orderByRaw("CASE WHEN status IN ('active','past_due','pending_first_payment','paused') THEN 0 ELSE 1 END")
            ->latest('id')
            ->first();

        return response()->json(['data' => $sub ? new SubscriptionResource($sub) : null]);
    }

    /** POST /memberships/subscriptions/{id}/cancel — cancela la renovación automática. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $member = $this->member($request);
        $request->validate(['reason' => 'nullable|string|max:200']);

        $sub = MembershipSubscription::query()
            ->where('id', $id)
            ->where('member_id', $member->id)
            ->first();
        // Mismo 404 si no existe o es de otro miembro (no filtra ids ajenos).
        abort_if(! $sub, 404, 'Suscripción no encontrada.');

        $updated = MembershipSubscriptionService::make()
            ->cancel($sub, SubscriptionEvent::ACTOR_MEMBER, $request->input('reason'));

        return response()->json([
            'ok'      => true,
            'message' => 'Renovación automática cancelada. Tu membresía sigue activa hasta su vencimiento.',
            'data'    => new SubscriptionResource($updated->load(['plan', 'paymentSource'])),
        ]);
    }

    /**
     * POST /memberships/subscriptions/{id}/payment-source — reemplaza la tarjeta.
     * Crea una fuente nueva, revoca la anterior (histórico conservado) y, si la
     * suscripción estaba past_due, ejecuta UN retry controlado con la nueva fuente.
     */
    public function replacePaymentSource(ReplacePaymentSourceRequest $request, int $id): JsonResponse
    {
        $member = $this->member($request);

        $sub = MembershipSubscription::query()
            ->where('id', $id)
            ->where('member_id', $member->id)
            ->first();
        abort_if(! $sub, 404, 'Suscripción no encontrada.');

        $data = array_merge($request->safe()->except(['accepted_terms', 'accepted_personal_data']), [
            'customer_email' => $member->email ?: optional($member->user)->email,
        ]);

        try {
            $source = MembershipSubscriptionService::make()->replacePaymentSource($sub, $data);
        } catch (SubscriptionException $e) {
            return response()->json([
                'ok' => false, 'error_code' => $e->errorCode, 'message' => $e->getMessage(),
            ], $e->status);
        }

        if ($source->status !== WompiPaymentSource::STATUS_AVAILABLE) {
            return response()->json([
                'ok'      => false,
                'status'  => 'payment_source_unavailable',
                'message' => 'No pudimos validar la nueva tarjeta. Intenta con otra.',
            ], 422);
        }

        // Retry CONTROLADO solo si estaba past_due (idempotente por billing_period).
        $retry = null;
        if ($sub->fresh()->status === MembershipSubscription::STATUS_PAST_DUE) {
            $retry = RecurringBillingService::make()->retryNow($sub->fresh());
        }

        $fresh = $sub->fresh()->load(['plan', 'paymentSource']);

        return response()->json([
            'ok'           => true,
            'retry_result' => $retry, // approved|declined|pending|past_due|skipped|null
            'message'      => $retry === 'approved'
                ? 'Tarjeta actualizada y tu pago quedó al día.'
                : 'Tarjeta actualizada correctamente.',
            'subscription' => new SubscriptionResource($fresh),
        ]);
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    private function member(Request $request): Member
    {
        /** @var Member|null $member */
        $member = $request->attributes->get('auth_member');
        abort_if(! $member, 401, 'Sesión no válida.');

        return $member;
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'active'                     => 'Pago automático activado. Tu membresía se renovará automáticamente.',
            'pending_first_payment'      => 'Estamos confirmando tu primer pago. Te avisaremos al aprobarse.',
            'past_due'                   => 'Tu suscripción quedó pendiente de pago. Actualiza tu método de pago.',
            'payment_source_unavailable' => 'No pudimos validar tu método de pago. Intenta con otra tarjeta.',
            default                      => 'No pudimos activar el pago automático. No se realizó ningún cobro.',
        };
    }

    /** Resuelve (o crea) el User enlazado al miembro (helper compartido). */
    private function resolveUser(Member $member): User
    {
        return app(\App\Services\Members\MemberUserResolver::class)->resolve($member);
    }
}
