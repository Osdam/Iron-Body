<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index()
    {
        return Payment::query()
            ->with([
                'user:id,name,email',
                'plan:id,name',
            ])
            ->latest()
            ->paginate(20);
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
            'user_id' => 'required|exists:users,id',
            'plan_id' => 'nullable|exists:plans,id',
            'amount' => 'required|numeric|min:0',
            'method' => 'nullable|string|max:80',
            'reference' => 'nullable|string|max:120',
            'status' => 'nullable|string|in:pending,paid,failed,refunded',
            'paid_at' => 'nullable|date',
        ]);

        $payment = Payment::create($data);

        return response()->json($payment->load(['user:id,name,email', 'plan:id,name']), 201);
    }
}
