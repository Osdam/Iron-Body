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
use App\Http\Controllers\Api\AppClassController;
use App\Http\Controllers\Api\AppExerciseController;
use App\Http\Controllers\Api\AppPaymentController;
use App\Http\Controllers\Api\AppRoutineController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberRegistrationController;
use App\Http\Controllers\Api\MemberRoutineController;
use App\Http\Controllers\Api\MemberTrainerController;
use App\Http\Controllers\Api\AppNutritionController;
use App\Http\Controllers\Api\MembershipPlanController;
use App\Http\Controllers\Api\IronAiController;
use App\Http\Controllers\Api\IronAiConversationController;
use App\Http\Controllers\Api\IronAiMediaController;
use App\Http\Controllers\Api\IronAiRealtimeController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\TurnstileController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Crm\NotificationController as AdminNotificationController;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Models\MyClass;

Route::bind('member', function (string $value): Member {
    return Member::query()
        ->where('id', $value)
        ->orWhere('member_uuid', $value)
        ->firstOrFail();
});

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
Route::get('users/{user}/plan-features', [UserController::class, 'planFeatures']);
Route::patch('users/{user}', [UserController::class, 'update']);
Route::put('users/{user}', [UserController::class, 'update']);
Route::delete('users/{user}', [UserController::class, 'destroy']);

Route::middleware('member.registration.token')->group(function () {
    Route::get('members/incomplete', [MemberRegistrationController::class, 'incomplete']);
    // ── Login con verificación en dos pasos (OTP por SMS) ─────────────────────
    Route::post('members/login', [AuthController::class, 'login']);
    Route::post('members/login/verify', [AuthController::class, 'verifyOtp']);
    Route::post('members/login/resend', [AuthController::class, 'resendOtp']);
    // Tercer factor: reconocimiento facial del titular (match on-device).
    Route::post('members/login/face-reference', [AuthController::class, 'faceReference']);
    Route::post('members/login/face-verify', [AuthController::class, 'faceVerify']);
    Route::post('members/biometric-unlock', [AuthController::class, 'biometricUnlock']);
    Route::post('members/register', [MemberRegistrationController::class, 'register']);
    Route::post('members/{member}/identity', [MemberRegistrationController::class, 'identity']);
    Route::post('members/{member}/legal-consent', [MemberRegistrationController::class, 'legalConsent']);
    Route::post('members/{member}/signature', [MemberRegistrationController::class, 'signature']);
    Route::post('members/{member}/biometric', [MemberRegistrationController::class, 'biometric']);
    Route::delete('members/{member}', [MemberRegistrationController::class, 'destroy']);

});

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

// ── IRON IA — asistente con OpenAI (Flutter → Laravel → OpenAI) ──────────────
// El usuario se resuelve de forma flexible dentro del servicio (Bearer
// access_hash de Member, member_id, documento o email). Sin identificación,
// IRON responde igual pero sin contexto personal. La API key vive solo aquí.
Route::get('iron-ai/access', [IronAiController::class, 'access']);
Route::get('iron-ai/quota', [IronAiController::class, 'quota']);
Route::post('iron-ai/chat', [IronAiController::class, 'chat']);
Route::get('iron-ai/recommendations', [IronAiController::class, 'recommendations']);

// ── IRON IA multimodal — voz (transcripción) e imagen (visión) ───────────────
// Multipart. Consumen cuota IA (kind=audio|image) y dependen del plan: si la
// función está bloqueada o se agotó la cuota, NO se llama a OpenAI.
Route::post('iron-ai/audio-chat', [IronAiMediaController::class, 'audioChat']);
Route::post('iron-ai/image-chat', [IronAiMediaController::class, 'imageChat']);

// ── IRON IA — conversación de voz EN VIVO (OpenAI Realtime / WebRTC) ──────────
// session: acuña token efímero (gated por plan; consume cuota realtime).
// transcript: persiste turnos (no llama a OpenAI ni consume cuota de chat).
Route::post('iron-ai/realtime/session', [IronAiRealtimeController::class, 'session']);
Route::post('iron-ai/realtime/transcript', [IronAiRealtimeController::class, 'transcript']);

// ── IRON IA — centro de conversaciones (CRUD; no consume OpenAI/cuota) ────────
Route::get('iron-ai/conversations', [IronAiConversationController::class, 'index']);
Route::post('iron-ai/conversations', [IronAiConversationController::class, 'store']);
Route::get('iron-ai/conversations/{uuid}/messages', [IronAiConversationController::class, 'messages']);
Route::post('iron-ai/conversations/{uuid}/archive', [IronAiConversationController::class, 'archive']);
Route::post('iron-ai/conversations/{uuid}/clear', [IronAiConversationController::class, 'clear']);
Route::delete('iron-ai/conversations/{uuid}', [IronAiConversationController::class, 'destroy']);

// ── Asistencias — registro facial/manual (CRM web) ───────────────────────────
// El reconocimiento facial corre 100% en el navegador del CRM con face-api.js.
// El backend solo persiste, sirve el catálogo de rostros y la imagen.
Route::get('attendances', [AttendanceController::class, 'index']);
Route::post('attendances', [AttendanceController::class, 'store']);
Route::get('attendances/face-references', [AttendanceController::class, 'faceReferences']);
Route::get('attendances/face-image/{userId}', [AttendanceController::class, 'faceImage'])
    ->where('userId', '[0-9]+');

// ── Torniquete — relé HTTP (ESP32, Sonoff, Shelly, ZKTeco, Hikvision, etc.)
Route::get('turnstile', [TurnstileController::class, 'show']);
Route::put('turnstile', [TurnstileController::class, 'update']);
Route::post('turnstile/trigger', [TurnstileController::class, 'trigger']);

Route::get('plans/features', [PlanController::class, 'allFeatures']);
Route::put('plans/{plan}/features', [PlanController::class, 'updateFeatures']);
// IRON IA — capacidades detalladas por plan (CRM ↔ membership_ai_capabilities).
Route::get('plans/{plan}/ai-capabilities', [PlanController::class, 'aiCapabilities']);
Route::put('plans/{plan}/ai-capabilities', [PlanController::class, 'updateAiCapabilities']);
Route::apiResource('plans', PlanController::class)->only(['index','show','store','update','destroy']);
Route::get('membership-plans', [MembershipPlanController::class, 'index']);
Route::get('membership-plans/{plan}', [MembershipPlanController::class, 'show']);
Route::apiResource('payments', PaymentController::class)->only(['index','show','store','update']);
Route::apiResource('classes', ClassController::class)
    ->only(['index','show','store','update','destroy'])
    ->parameters(['classes' => 'myClass']);
Route::get('classes/{myClass}/reservations', [ClassController::class, 'reservations']);

// ── Catálogo de ejercicios (público para la app; CRUD sin auth para el CRM) ──
Route::get('app/exercises', [AppExerciseController::class, 'index']);
Route::get('exercises/catalog', [AppExerciseController::class, 'index']);

// ── Rutinas por miembro (CRM) ────────────────────────────────────────────────
Route::get('members/{member}/routines',                [MemberRoutineController::class, 'index']);
Route::post('members/{member}/routines',               [MemberRoutineController::class, 'store']);
Route::put('members/{member}/routines/{routine}',      [MemberRoutineController::class, 'update']);
Route::patch('members/{member}/routines/{routine}',    [MemberRoutineController::class, 'update']);
Route::delete('members/{member}/routines/{routine}',   [MemberRoutineController::class, 'destroy']);

// ── App: clases y entrenadores para miembros (autenticación por access_hash) ──
Route::middleware('auth.member')->group(function (): void {
    // ── Seguridad: sesiones / dispositivos del miembro ────────────────────────
    Route::get('members/devices', [AuthController::class, 'devices']);
    Route::post('members/devices/{uuid}/revoke', [AuthController::class, 'revokeDevice']);
    Route::post('members/logout', [AuthController::class, 'logout']);
    // Push nativo (FCM): registrar/baja del token del dispositivo.
    Route::post('members/push-token', [AuthController::class, 'registerPushToken']);
    Route::post('members/push-token/remove', [AuthController::class, 'removePushToken']);

    Route::get('app/classes', [AppClassController::class, 'index']);
    Route::post('app/classes/{myClass}/reserve', [AppClassController::class, 'reserve']);
    Route::delete('app/classes/{myClass}/reserve', [AppClassController::class, 'cancel']);
    // Rutas alias en /classes para compatibilidad con la app móvil
    Route::post('classes/{myClass}/reserve', [ClassController::class, 'reserve']);
    Route::post('classes/{myClass}/cancel',  [ClassController::class, 'cancel']);
    // Entrenador asignado al miembro autenticado (antes de trainers/{trainer}).
    Route::get('trainers/mine', [MemberTrainerController::class, 'mine']);
    // Calificación de entrenadores
    Route::post('trainers/{trainer}/rate', [TrainerController::class, 'rate']);
    // Rutinas para miembros
    Route::get('app/routines/assigned',           [AppRoutineController::class, 'assigned']);
    Route::get('app/routines/custom',             [AppRoutineController::class, 'custom']);
    Route::post('app/routines',                   [AppRoutineController::class, 'store']);
    Route::post('app/routines/{routine}/complete',[AppRoutineController::class, 'complete']);
    // Resumen nutricional diario (sincroniza desde la app → push al cumplir meta)
    Route::post('app/nutrition/day',              [AppNutritionController::class, 'store']);
    Route::delete('app/routines/{routine}',       [AppRoutineController::class, 'destroy']);
    Route::post('app/routines/{routine}/delete',  [AppRoutineController::class, 'destroy']);
    // Historial de pagos del miembro autenticado (lee `payments` — la misma
    // tabla del CRM, una sola fuente de verdad).
    Route::get('app/payments', [AppPaymentController::class, 'index']);
    // Detalle de un pago del miembro. Refresca contra ePayco si está en vuelo
    // (pending/processing) reutilizando EpaycoPaymentService::statusFor. 404
    // tanto si la referencia no existe como si no pertenece al miembro.
    Route::get('app/payments/{reference}', [AppPaymentController::class, 'show'])
        ->where('reference', '[A-Za-z0-9_\-]+');
});
Route::apiResource('routines', RoutineController::class)->only(['index','show','store','update','destroy']);
Route::patch('routines/{routine}/assign', [RoutineController::class, 'assign']);
Route::post('trainers/{trainer}/reviews', [TrainerController::class, 'review']);
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

// ── Notificaciones — APP Flutter (audience=member; por documento o access_hash) ─
// Rutas estáticas ANTES de las que llevan {uuid} para evitar colisiones.
Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
Route::get('notifications/stream', [NotificationController::class, 'stream']); // SSE tiempo real
Route::get('notifications/popup-pending', [NotificationController::class, 'popupPending']);
Route::post('notifications/read-all',    [NotificationController::class, 'readAll']);
Route::get('notifications',              [NotificationController::class, 'index']);
Route::post('notifications/{uuid}/popup-shown', [NotificationController::class, 'popupShown']);
Route::post('notifications/{uuid}/read', [NotificationController::class, 'markRead']);
Route::delete('notifications/{uuid}',    [NotificationController::class, 'destroy']);

// ── Notificaciones — CRM Angular (audience=admin) ─────────────────────────────
Route::prefix('admin/notifications')->group(function (): void {
    Route::get('unread-count',   [AdminNotificationController::class, 'unreadCount']);
    Route::get('stream',         [AdminNotificationController::class, 'stream']); // SSE tiempo real

    Route::post('read-all',      [AdminNotificationController::class, 'readAll']);
    Route::get('/',              [AdminNotificationController::class, 'index']);
    Route::post('/',             [AdminNotificationController::class, 'store']);
    Route::post('{uuid}/read',   [AdminNotificationController::class, 'markRead']);
});

// ── Entrenador ↔ miembro (CRM admin) ──────────────────────────────────────────
// Liberar el vínculo dispositivo↔cuenta (anti-uso-compartido) desde el CRM.
Route::post('admin/devices/{deviceId}/release', [AuthController::class, 'releaseDeviceBinding']);

Route::post('admin/members/{member}/assign-trainer',   [MemberTrainerController::class, 'assign']);
Route::post('admin/members/{member}/unassign-trainer', [MemberTrainerController::class, 'unassign']);
Route::get('admin/members/{member}/trainer',           [MemberTrainerController::class, 'showAdmin']);

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
