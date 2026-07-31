<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\MembershipSubscription;
use App\Models\PaymentTransaction;
use App\Models\SubscriptionEvent;
use App\Services\Subscriptions\MembershipSubscriptionService;
use App\Services\Subscriptions\RecurringBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administración de suscripciones de pago automático (auth.admin). Solo lectura +
 * acciones idempotentes (retry manual, cancelación con auditoría). NUNCA expone
 * tokens, llaves ni payloads sensibles: el método siempre va enmascarado.
 */
class AdminSubscriptionController extends Controller
{
    /** GET /admin/subscriptions — listado con filtros básicos. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|string|max:30',
            'member_id' => 'nullable|integer',
            'past_due' => 'nullable|boolean',
            'next_charge_before' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $q = MembershipSubscription::query()->with(['plan', 'paymentSource']);

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        if ($request->boolean('past_due')) {
            $q->where('status', MembershipSubscription::STATUS_PAST_DUE);
        }
        if ($request->filled('member_id')) {
            $q->where('member_id', $request->integer('member_id'));
        }
        if ($request->filled('next_charge_before')) {
            $q->whereNotNull('next_charge_at')
                ->where('next_charge_at', '<=', $request->date('next_charge_before'));
        }

        $page = $q->orderByRaw('next_charge_at is null, next_charge_at asc')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => SubscriptionResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /** GET /admin/subscriptions/{id} — detalle: suscripción, cobros y eventos. */
    public function show(int $id): JsonResponse
    {
        $sub = MembershipSubscription::query()
            ->with(['plan', 'paymentSource', 'member'])
            ->findOrFail($id);

        $charges = PaymentTransaction::query()
            ->where('subscription_id', $sub->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (PaymentTransaction $t) => $t->toWompiPublicArray())
            ->values();

        $events = SubscriptionEvent::query()
            ->where('subscription_id', $sub->id)
            ->latest('id')
            ->limit(100)
            ->get(['id', 'type', 'actor', 'reference', 'amount', 'message', 'created_at']);

        return response()->json([
            'subscription' => new SubscriptionResource($sub),
            'member' => $sub->member ? [
                'id' => $sub->member->id,
                'full_name' => $sub->member->full_name,
                'email' => $sub->member->email,
            ] : null,
            'charges' => $charges,
            'events' => $events,
        ]);
    }

    /** POST /admin/subscriptions/{id}/retry — reintento manual idempotente. */
    public function retry(int $id): JsonResponse
    {
        $sub = MembershipSubscription::findOrFail($id);

        $result = RecurringBillingService::make()->retryNow($sub);

        return response()->json([
            'ok' => in_array($result, ['approved', 'pending'], true),
            'result' => $result,
            'subscription' => new SubscriptionResource($sub->fresh()->load(['plan', 'paymentSource'])),
        ]);
    }

    /** POST /admin/subscriptions/{id}/cancel — cancelación por admin (auditada). */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:200']);
        $sub = MembershipSubscription::findOrFail($id);

        $updated = MembershipSubscriptionService::make()
            ->cancel($sub, SubscriptionEvent::ACTOR_ADMIN, $request->input('reason'));

        return response()->json([
            'ok' => true,
            'subscription' => new SubscriptionResource($updated->load(['plan', 'paymentSource'])),
        ]);
    }
}
