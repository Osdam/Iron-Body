<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::query()->with(['user:id,name,email', 'plan:id,name'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        return $query->paginate(20);
    }

    public function show(Payment $payment)
    {
        return $payment->load([
            'user:id,name,email',
            'plan:id,name',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id'   => 'required|exists:users,id',
            'plan_id'   => 'nullable|exists:plans,id',
            'amount'    => 'required|numeric|min:0',
            'method'    => 'nullable|string|max:80',
            'reference' => 'nullable|string|max:120',
            'status'    => 'nullable|string|in:pending,paid,failed,refunded,cancelled',
            'paid_at'   => 'nullable|date',
        ]);

        if (($data['status'] ?? 'pending') === 'paid' && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        $payment = Payment::create($data);

        if ($payment->status === 'paid') {
            $this->applyMembershipExtension($payment);
        }

        return response()->json($payment->load(['user:id,name,email', 'plan:id,name']), 201);
    }

    public function update(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'status'    => 'nullable|string|in:pending,paid,failed,refunded,cancelled',
            'paid_at'   => 'nullable|date',
            'method'    => 'nullable|string|max:80',
            'reference' => 'nullable|string|max:120',
            'amount'    => 'nullable|numeric|min:0',
        ]);

        $wasPaid = $payment->status === 'paid';

        if (isset($data['status']) && $data['status'] === 'paid' && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        $payment->update($data);

        if (!$wasPaid && $payment->status === 'paid') {
            $this->applyMembershipExtension($payment);
        }

        return response()->json($payment->load(['user:id,name,email', 'plan:id,name']));
    }

    private function applyMembershipExtension(Payment $payment): void
    {
        if (!$payment->plan_id) {
            return;
        }

        /** @var User|null $user */
        $user = User::find($payment->user_id);
        /** @var Plan|null $plan */
        $plan = Plan::find($payment->plan_id);

        if (!$user || !$plan || (int) $plan->duration_days <= 0) {
            return;
        }

        $paidDate = $payment->paid_at
            ? Carbon::parse($payment->paid_at)->startOfDay()
            : Carbon::today();
        $currentEnd = $user->membership_end_date
            ? Carbon::parse($user->membership_end_date)->startOfDay()
            : null;

        $baseDate = $currentEnd && $currentEnd->greaterThan($paidDate)
            ? $currentEnd
            : $paidDate;

        if (!$currentEnd || $currentEnd->lessThan($paidDate) || !$user->membership_start_date) {
            $user->membership_start_date = $paidDate->toDateString();
        }

        $user->membership_end_date = $baseDate->copy()->addDays((int) $plan->duration_days)->toDateString();
        $user->plan = $plan->name;
        $user->status = 'active';
        $user->save();
    }
}
