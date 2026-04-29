<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;

Route::get('/health', function () {
    return response()->json([
        'message' => 'Backend Laravel conectado',
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::get('/dashboard', function () {
    return response()->json([
        'users' => User::count(),
        'active_plans' => Plan::where('active', true)->count(),
        'payments' => Payment::count(),
        'revenue' => (float) Payment::where('status', 'paid')->sum('amount'),
    ]);
});

Route::get('users', [UserController::class, 'index']);
Route::get('users/{user}', [UserController::class, 'show']);

Route::apiResource('plans', PlanController::class)->only(['index','show','store','update']);
Route::apiResource('payments', PaymentController::class)->only(['index','show','store']);
