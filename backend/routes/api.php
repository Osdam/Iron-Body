<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\RoutineController;
use App\Http\Controllers\Api\TrainerController;
use App\Http\Controllers\Api\EpaycoPaymentController;
use App\Http\Controllers\Api\ExerciseController;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Models\MyClass;

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
        'classes' => MyClass::where('status', 'active')->count(),
    ]);
});

Route::get('users', [UserController::class, 'index']);
Route::post('users', [UserController::class, 'store']);
Route::get('users/{user}', [UserController::class, 'show']);
Route::patch('users/{user}', [UserController::class, 'update']);
Route::put('users/{user}', [UserController::class, 'update']);
Route::delete('users/{user}', [UserController::class, 'destroy']);

// ── ePayco — pago 100% IN-APP por API (sin navegador/WebView) ───────────────
Route::post('payments/epayco/create', [EpaycoPaymentController::class, 'create']);
Route::post('payments/epayco/pay-card', [EpaycoPaymentController::class, 'payCard']);
Route::post('payments/epayco/pay-pse', [EpaycoPaymentController::class, 'payPse']);
Route::post('payments/epayco/pay-nequi', [EpaycoPaymentController::class, 'payNequi']);
Route::post('payments/epayco/pay-daviplata', [EpaycoPaymentController::class, 'payDaviplata']);
Route::post('payments/epayco/confirmation', [EpaycoPaymentController::class, 'confirmation']);
Route::get('payments/epayco/response', [EpaycoPaymentController::class, 'response']);
Route::get('payments/epayco/history', [EpaycoPaymentController::class, 'history']);
Route::get('payments/{reference}/status', [EpaycoPaymentController::class, 'status']);

Route::apiResource('plans', PlanController::class)->only(['index','show','store','update','destroy']);
Route::apiResource('payments', PaymentController::class)->only(['index','show','store','update']);
Route::apiResource('classes', ClassController::class)
    ->only(['index','show','store','update','destroy'])
    ->parameters(['classes' => 'myClass']);
Route::apiResource('routines', RoutineController::class)->only(['index','show','store','update','destroy']);
Route::patch('routines/{routine}/assign', [RoutineController::class, 'assign']);
Route::apiResource('trainers', TrainerController::class)->only(['index','show','store','update','destroy']);

// ── Ejercicios — referencias visuales (GIF) vía WorkoutX ────────────────────
// Rutas específicas ANTES de {id} para que no las capture el comodín.
Route::get('exercises/search', [ExerciseController::class, 'search']);
Route::get('exercises/debug-fitgif', [ExerciseController::class, 'debugFitgif']);
Route::get('exercises/by-muscle/{muscle}', [ExerciseController::class, 'byMuscle']);
Route::get('exercises/gif/{filename}', [ExerciseController::class, 'gif']);
Route::get('exercises/fitgif/gif/{id}', [ExerciseController::class, 'fitgifGif']);
Route::get('exercises/fitgif/video/{file}', [ExerciseController::class, 'fitgifVideo'])
    ->where('file', '[A-Za-z0-9_\-]+\.mp4');
Route::post('exercises/sync', [ExerciseController::class, 'sync']);
Route::get('exercises', [ExerciseController::class, 'index']);
Route::get('exercises/{id}', [ExerciseController::class, 'show']);

Route::get('/reports/stats', function () {
    $totalRevenue = (float) Payment::where('status', 'paid')->sum('amount');
    $pendingCount = Payment::where('status', 'pending')->count();
    $activeUsers  = User::where('status', 'active')->count();
    $totalUsers   = User::count();
    $activePlans  = Plan::where('active', true)->count();
    $activeClasses = MyClass::where('status', 'active')->count();

    $driver = \DB::connection()->getDriverName();
    $monthExpr = $driver === 'sqlite'
        ? "strftime('%Y-%m', paid_at)"
        : "DATE_FORMAT(paid_at, '%Y-%m')";

    $revenueByMonth = Payment::where('status', 'paid')
        ->selectRaw("$monthExpr as month, SUM(amount) as total")
        ->groupBy('month')
        ->orderBy('month')
        ->get();

    $paymentsByStatus = Payment::selectRaw('status, COUNT(*) as count')
        ->groupBy('status')
        ->get();

    return response()->json([
        'total_revenue'     => $totalRevenue,
        'pending_payments'  => $pendingCount,
        'active_members'    => $activeUsers,
        'total_members'     => $totalUsers,
        'active_plans'      => $activePlans,
        'active_classes'    => $activeClasses,
        'revenue_by_month'  => $revenueByMonth,
        'payments_by_status'=> $paymentsByStatus,
    ]);
});
