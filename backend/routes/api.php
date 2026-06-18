<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\RoutineController;
use App\Http\Controllers\Api\TrainerController;
use App\Http\Controllers\Api\GymEquipmentController;
use App\Http\Controllers\Api\AppStoreController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\CajaController;
use App\Http\Controllers\Api\ExerciseController;
use App\Http\Controllers\Api\AppClassController;
use App\Http\Controllers\Api\AppExerciseController;
use App\Http\Controllers\Api\AppPaymentController;
use App\Http\Controllers\Api\WompiPaymentController;
use App\Http\Controllers\Api\WompiWebhookController;
use App\Http\Controllers\Api\AppRoutineController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberRegistrationController;
use App\Http\Controllers\Api\MemberContractController;
use App\Http\Controllers\Api\Admin\ContractAdminController;
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
use App\Http\Controllers\Api\StoriesController;
use App\Http\Controllers\Api\FirebaseAuthController;
use App\Http\Controllers\Api\WeeklyStreakController;
use App\Http\Controllers\Api\Admin\WeeklyStreakAdminController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\PhysicalEvaluationController;
use App\Http\Controllers\Api\Admin\PhysicalEvaluationAdminController;
use App\Http\Controllers\Api\NutritionController;
use App\Http\Controllers\Api\Admin\NutritionAdminController;
use App\Http\Controllers\Api\IronAiContextController;
use App\Http\Controllers\Api\AppNotificationController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Crm\NotificationController as AdminNotificationController;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Models\MyClass;

Route::bind('member', function (string $value): Member {
    // El parámetro {member} acepta id numérico o member_uuid (UUID). En
    // PostgreSQL la columna `member_uuid` es de tipo UUID y no acepta enteros:
    // hay que ramificar por formato antes de consultar para no provocar
    // SQLSTATE[22P02].
    if (\Illuminate\Support\Str::isUuid($value)) {
        return Member::where('member_uuid', $value)->firstOrFail();
    }
    if (ctype_digit($value)) {
        return Member::where('id', (int) $value)->firstOrFail();
    }
    abort(404);
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

// ── Acceso al portal profesional (entrenadores) — OTP por SMS ─────────────────
// Capa nueva que REUSA el motor OTP/Twilio. Tras el feature flag
// `trainer_auth_enabled` (404 si está apagado). `access` responde de forma
// uniforme para no filtrar qué documentos corresponden a entrenadores.
Route::middleware('trainer.feature:trainer_auth_enabled')->prefix('trainer/auth')->group(function (): void {
    Route::post('access', [\App\Http\Controllers\Api\TrainerAuthController::class, 'access'])->middleware('throttle:6,1');
    Route::post('verify', [\App\Http\Controllers\Api\TrainerAuthController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('resend', [\App\Http\Controllers\Api\TrainerAuthController::class, 'resend'])->middleware('throttle:6,1');
    // Acceso de PRUEBAS sin OTP (gated por TRAINER_OTP_DEV_BYPASS; 404 si off).
    Route::post('dev-login', [\App\Http\Controllers\Api\TrainerAuthController::class, 'devLogin'])->middleware('throttle:10,1');

    // Login facial en tablet (pre-sesión; gated por TRAINER_FACE_LOGIN_ENABLED).
    Route::get('face/roster', [\App\Http\Controllers\Api\TrainerAuthController::class, 'faceRoster'])->middleware('throttle:30,1');
    Route::post('face/login', [\App\Http\Controllers\Api\TrainerAuthController::class, 'faceLogin'])->middleware('throttle:20,1');

    // Requieren sesión profesional vigente.
    Route::middleware('auth.trainer')->group(function (): void {
        Route::get('me',                [\App\Http\Controllers\Api\TrainerAuthController::class, 'me']);
        Route::get('bootstrap',         [\App\Http\Controllers\Api\TrainerAuthController::class, 'bootstrap']);
        Route::post('biometric-unlock', [\App\Http\Controllers\Api\TrainerAuthController::class, 'biometricUnlock'])->middleware('throttle:20,1');
        // Enrolamiento del rostro del entrenador (calculado on-device).
        Route::post('face/enroll',      [\App\Http\Controllers\Api\TrainerAuthController::class, 'enrollFace'])->middleware('throttle:10,1');
        // Canal SSE del portal: empuja cambios de los clientes del entrenador.
        Route::get('realtime',          [\App\Http\Controllers\Api\Trainer\TrainerRealtimeController::class, 'stream']);
    });
});

// Espacios disponibles para el miembro autenticado (cuentas dobles). No revela
// nada al miembro normal (ver MemberWorkspaceController).
Route::middleware('auth.member')->get('member/workspaces', [\App\Http\Controllers\Api\MemberWorkspaceController::class, 'index']);

// ── Valoraciones profesionales — portal del entrenador ────────────────────────
// Autorización compuesta: feature flag + auth.trainer + permiso por acción +
// asignación/propiedad (en el controlador). Una valoración enviada es inmutable.
Route::middleware(['trainer.feature:professional_assessments_enabled', 'auth.trainer'])->prefix('trainer')->group(function (): void {
    $pa = \App\Http\Controllers\Api\Trainer\ProfessionalAssessmentController::class;
    // Miembros asignados al entrenador (home profesional).
    Route::get('members', [\App\Http\Controllers\Api\Trainer\TrainerMembersController::class, 'index'])
        ->middleware('trainer.can:members.view_assigned');
    // Detalle del miembro autorizado (perfil + última valoración).
    Route::get('members/{member}', [\App\Http\Controllers\Api\Trainer\TrainerMembersController::class, 'show'])
        ->whereNumber('member')->middleware('trainer.can:members.view_assigned');
    Route::get('members/{member}/assessments',  [$pa, 'index'])->middleware('trainer.can:assessments.view');
    Route::post('members/{member}/assessments', [$pa, 'store'])->middleware('trainer.can:assessments.create');
    Route::get('assessments/{assessment}',          [$pa, 'show'])->middleware('trainer.can:assessments.view');
    Route::put('assessments/{assessment}',          [$pa, 'update'])->middleware('trainer.can:assessments.update_draft');
    Route::post('assessments/{assessment}/submit',  [$pa, 'submit'])->middleware('trainer.can:assessments.submit');
    Route::post('assessments/{assessment}/amend',   [$pa, 'amend'])->middleware('trainer.can:assessments.amend');
});

// ── Valoraciones profesionales — vista de SOLO LECTURA del miembro ────────────
Route::middleware(['trainer.feature:professional_assessments_enabled', 'auth.member'])->group(function (): void {
    $ma = \App\Http\Controllers\Api\MemberAssessmentController::class;
    Route::get('member/assessments',                 [$ma, 'index']);
    Route::get('member/assessments/{uuid}',          [$ma, 'show']);
    Route::post('member/assessments/{uuid}/ack',     [$ma, 'acknowledge']);
});

// ── Clases y asistencia — entrenador funcional ────────────────────────────────
// Feature flag + auth.trainer + permiso por acción + propiedad de la clase
// (en el controlador). Un entrenador solo gestiona SUS clases.
Route::middleware(['trainer.feature:trainer_classes_enabled', 'auth.trainer'])->prefix('trainer')->group(function (): void {
    $tc = \App\Http\Controllers\Api\Trainer\TrainerClassController::class;
    Route::get('classes',                      [$tc, 'index'])->middleware('trainer.can:classes.view');
    Route::get('classes/{class}',              [$tc, 'show'])->middleware('trainer.can:classes.view');
    Route::post('classes/{class}/attendance',  [$tc, 'markAttendance'])->middleware('trainer.can:attendance.create');
    Route::put('classes/{class}/attendance',   [$tc, 'correctAttendance'])->middleware('trainer.can:attendance.update');
    // Inicio / fin REAL de la clase (con rostro). Mismo permiso de asistencia.
    Route::post('classes/{class}/start',       [$tc, 'startSession'])->middleware('trainer.can:attendance.create');
    Route::post('classes/{class}/end',         [$tc, 'endSession'])->middleware('trainer.can:attendance.create');
});

Route::middleware('member.registration.token')->group(function () {
    Route::get('members/incomplete', [MemberRegistrationController::class, 'incomplete']);
    // ── Login con verificación en dos pasos (OTP por SMS) ─────────────────────
    Route::post('members/login', [AuthController::class, 'login']);
    Route::post('members/login/verify', [AuthController::class, 'verifyOtp']);
    Route::post('members/login/resend', [AuthController::class, 'resendOtp']);
    // Tercer factor: reconocimiento facial del titular (match on-device).
    Route::post('members/login/face-reference', [AuthController::class, 'faceReference']);
    Route::post('members/login/face-verify', [AuthController::class, 'faceVerify']);
    // Re-enrolamiento biométrico cross-platform (gated por OTP + token de un solo uso).
    Route::post('members/login/face-reenroll/request', [AuthController::class, 'faceReenrollRequest'])
        ->middleware('throttle:6,1');
    Route::post('members/login/face-reenroll/confirm', [AuthController::class, 'faceReenrollConfirm'])
        ->middleware('throttle:10,1');
    Route::post('members/login/face-reenroll/complete', [AuthController::class, 'faceReenrollComplete'])
        ->middleware('throttle:6,1');
    Route::post('members/biometric-unlock', [AuthController::class, 'biometricUnlock']);
    // Login adaptativo (Bloque 3b): canje del ticket de desbloqueo local.
    Route::post('members/login/trusted-unlock', [AuthController::class, 'trustedUnlock'])
        ->middleware('throttle:10,1');
    Route::post('members/register', [MemberRegistrationController::class, 'register']);
    Route::post('members/{member}/identity', [MemberRegistrationController::class, 'identity']);
    Route::post('members/{member}/legal-consent', [MemberRegistrationController::class, 'legalConsent']);
    Route::post('members/{member}/signature', [MemberRegistrationController::class, 'signature']);
    Route::post('members/{member}/biometric', [MemberRegistrationController::class, 'biometric']);
    // Biometría OPCIONAL: el usuario puede omitirla al crear cuenta (Apple).
    Route::post('members/{member}/biometric-skip', [MemberRegistrationController::class, 'skipBiometric']);
    Route::delete('members/{member}', [MemberRegistrationController::class, 'destroy']);

});

// Plantilla de consentimiento (PÚBLICA, solo config estática: textos de
// checkboxes + URLs). La usa la creación de cuenta para mostrar el contrato
// real ANTES de que exista el miembro. No expone datos personales.
Route::get('contracts/consent-template', [MemberContractController::class, 'consentTemplate']);

// Páginas legales públicas servidas por el backend (HTML). La app las muestra
// en un visor interno (WebView), nunca abre un dominio externo muerto.
Route::get('legal/privacy', [\App\Http\Controllers\Api\LegalController::class, 'privacy']);
Route::get('legal/terms',   [\App\Http\Controllers\Api\LegalController::class, 'terms']);

// ── PASARELA: WOMPI (única pasarela activa) ───────────────────────────────────
// La integración ePayco y el Nequi-directo (Smart Checkout v2 / APIFY push) se
// RETIRARON como rutas activas en la migración a Wompi. Los registros históricos
// (`payments` con method=epayco/nequi) siguen siendo legibles desde el historial
// del miembro (GET /api/app/payments). Wompi vive en el grupo `payments/wompi/*`
// (más abajo) y el webhook público `POST /api/webhooks/wompi`.

// ── WOMPI (pasarela ACTIVA) — IN-APP, autenticado como miembro ─────────────────
// El sujeto se toma del miembro autenticado (no del body). El monto es
// autoritativo del backend. Tarjeta: la app envía SOLO el token (PCI). La
// membresía se activa por webhook/reconciliación, nunca desde la app.
Route::middleware('auth.member')->prefix('payments/wompi')->group(function (): void {
    Route::get('config',            [WompiPaymentController::class, 'config'])->middleware('throttle:30,1');
    Route::get('acceptance',        [WompiPaymentController::class, 'acceptance'])->middleware('throttle:30,1');
    Route::get('pse/institutions',  [WompiPaymentController::class, 'pseInstitutions'])->middleware('throttle:30,1');

    Route::post('card',      [WompiPaymentController::class, 'payCard'])->middleware('throttle:10,1');
    Route::post('pse',       [WompiPaymentController::class, 'payPse'])->middleware('throttle:10,1');
    Route::post('nequi',     [WompiPaymentController::class, 'payNequi'])->middleware('throttle:10,1');

    Route::post('daviplata/start', [WompiPaymentController::class, 'daviplataStart'])->middleware('throttle:10,1');
    Route::post('daviplata/{reference}/send-otp',     [WompiPaymentController::class, 'daviplataSendOtp'])
        ->where('reference', '[A-Za-z0-9_\-]+')->middleware('throttle:6,1');
    Route::post('daviplata/{reference}/validate-otp', [WompiPaymentController::class, 'daviplataValidateOtp'])
        ->where('reference', '[A-Za-z0-9_\-]+')->middleware('throttle:10,1');
    Route::post('daviplata/{reference}/resend-otp',   [WompiPaymentController::class, 'daviplataResendOtp'])
        ->where('reference', '[A-Za-z0-9_\-]+')->middleware('throttle:4,1');

    Route::get('history', [WompiPaymentController::class, 'history'])->middleware('throttle:30,1');
    // Estado real (refresca contra Wompi si sigue en vuelo). Path propio para no
    // colisionar con el legado público payments/{reference}/status (ePayco).
    Route::get('{reference}/status', [WompiPaymentController::class, 'status'])
        ->where('reference', '[A-Za-z0-9_\-]+')->middleware('throttle:60,1');
});

// ── Nutrición premium: búsqueda de alimentos / barcode / OCR / tracking ───────
// Módulo NUEVO (tablas nutrition_foods/entries/...) independiente del nutricional
// previo (app/nutrition/*). Flutter NUNCA llama a proveedores externos; todo pasa
// por el backend, que cachea y calcula los macros finales. Rate-limit por método.
Route::middleware('auth.member')->prefix('nutrition')->group(function (): void {
    $food = \App\Http\Controllers\Api\Nutrition\NutritionFoodController::class;
    $entry = \App\Http\Controllers\Api\Nutrition\NutritionEntryController::class;
    $summary = \App\Http\Controllers\Api\Nutrition\NutritionSummaryController::class;
    $ocr = \App\Http\Controllers\Api\Nutrition\NutritionOcrController::class;

    Route::get('foods/search', [$food, 'search'])->middleware('throttle:30,1');
    Route::get('foods/barcode/{barcode}', [$food, 'barcode'])
        ->where('barcode', '[0-9]+')->middleware('throttle:20,1');
    Route::get('favorites', [$food, 'favorites']);
    Route::get('recent', [$food, 'recent']);
    Route::post('foods', [$food, 'store'])->middleware('throttle:30,1');
    Route::get('foods/{uuid}', [$food, 'show']);
    Route::put('foods/{uuid}', [$food, 'update']);
    Route::delete('foods/{uuid}', [$food, 'destroy']);
    Route::post('foods/{uuid}/favorite', [$food, 'favorite']);
    Route::delete('foods/{uuid}/favorite', [$food, 'unfavorite']);
    Route::post('foods/{uuid}/report', [$food, 'report'])->middleware('throttle:20,1');

    Route::post('entries', [$entry, 'store'])->middleware('throttle:60,1');
    Route::get('entries', [$entry, 'index']);
    Route::delete('entries/{uuid}', [$entry, 'destroy']);

    Route::get('summary', [$summary, 'show']);
    Route::get('history', [$summary, 'history']);
    Route::get('stats', [$summary, 'stats']); // constancia/adherencia premium

    Route::post('ocr/scan', [$ocr, 'scan'])->middleware('throttle:10,1');
    Route::get('ocr/{uuid}', [$ocr, 'show']);
    Route::post('ocr/{uuid}/confirm-food', [$ocr, 'confirmFood']);

    // IA de Nutrición (asistencia OpenAI; key solo backend). Rate-limit estricto.
    $ai = \App\Http\Controllers\Api\Nutrition\NutritionAiController::class;
    Route::post('ai/label-image', [$ai, 'labelImage'])->middleware('throttle:15,1');
    Route::post('ai/parse-text',  [$ai, 'parseText'])->middleware('throttle:20,1');
    Route::post('ai/estimate',    [$ai, 'estimate'])->middleware('throttle:20,1');
    Route::get('ai/insights',     [$ai, 'insights'])->middleware('throttle:30,1');

    // Meta nutricional personalizada (BMR/TDEE/macros). Backend = autoridad.
    $goal = \App\Http\Controllers\Api\Nutrition\NutritionGoalController::class;
    Route::get('goal',              [$goal, 'show']);
    Route::post('goal/calculate',   [$goal, 'calculate'])->middleware('throttle:30,1');
    Route::post('goal',             [$goal, 'store'])->middleware('throttle:20,1');
    Route::post('goal/recalculate', [$goal, 'recalculate'])->middleware('throttle:20,1');
});

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
Route::get('attendances/stream', [AttendanceController::class, 'stream']); // SSE tiempo real
Route::get('attendances/face-references', [AttendanceController::class, 'faceReferences']);
Route::get('attendances/face-image/{userId}', [AttendanceController::class, 'faceImage'])
    ->where('userId', '[0-9]+');
// Enrolamiento facial desde el CRM (punto físico): miembros sin rostro y alta.
Route::get('attendances/face-enrollment/pending', [AttendanceController::class, 'faceEnrollmentPending']);
Route::post('attendances/face-enrollment/{member}', [AttendanceController::class, 'enrollFace'])
    ->where('member', '[0-9]+');

// ── Torniquete — relé HTTP (ESP32, Sonoff, Shelly, ZKTeco, Hikvision, etc.)
Route::get('turnstile', [TurnstileController::class, 'show']);
Route::put('turnstile', [TurnstileController::class, 'update']);
Route::post('turnstile/trigger', [TurnstileController::class, 'trigger']);
// Disparo directo de un webhook HTTP (Sonoff / ESP32 / Shelly).
Route::post('turnstile/webhook/fire', [TurnstileController::class, 'fireWebhook']);

// ── Webhook público de Meta (Instagram / Facebook / WhatsApp) ──────────────────
// Sin auth de sesión (lo llama Meta): GET verifica con verify_token; POST valida
// la firma X-Hub-Signature-256 y procesa en cola. Requiere dominio HTTPS público
// (no ngrok) en producción. Ver WebhookMetaController.
Route::get('webhooks/meta',  [\App\Http\Controllers\Api\WebhookMetaController::class, 'verify']);
Route::post('webhooks/meta', [\App\Http\Controllers\Api\WebhookMetaController::class, 'receive']);

// ── Webhook público de WOMPI (S2S) ────────────────────────────────────────────
// Sin auth de sesión: la autenticidad se valida por el CHECKSUM del evento. La
// activación de membresía ocurre AQUÍ (idempotente), nunca desde la app. URL a
// registrar en el dashboard Wompi (config('wompi.webhook_url')).
Route::post('webhooks/wompi', [WompiWebhookController::class, 'handle'])
    ->middleware('throttle:120,1');
// ZKTeco Eco — apertura directa (SDK standalone, TCP 4370).
Route::post('turnstile/zkteco/open', [TurnstileController::class, 'openZkteco']);
// Serial COM (replica NetGymValidator → USB-CH340 → RS485 → placa SATT).
Route::post('turnstile/serial/open', [TurnstileController::class, 'openSerial']);

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
    // ── Contratos / consentimiento informado / firma electrónica ──────────────
    // El miembro solo ve/firma/descarga SUS contratos. PDFs en disco privado.
    Route::get('member/contracts/status',              [MemberContractController::class, 'status']);
    Route::get('member/contracts',                     [MemberContractController::class, 'index']);
    Route::post('member/contracts/draft',              [MemberContractController::class, 'draft']);
    Route::get('member/contracts/{contract}/preview',  [MemberContractController::class, 'preview']);
    Route::post('member/contracts/{contract}/sign',    [MemberContractController::class, 'sign'])
        ->middleware('throttle:10,1');
    Route::get('member/contracts/{contract}/download', [MemberContractController::class, 'download'])
        ->middleware('throttle:30,1');

    // ── Estado de cuenta: fuente de verdad del gate de acceso (ActivationGate).
    // El Home solo es accesible con membresía activa O pago aprobado/verificado.
    Route::get('member/account/status', [\App\Http\Controllers\Api\MemberAccountController::class, 'status']);

    // ── Snapshot consolidado del estado del miembro (fuente de verdad para el
    // estado vivo de la app: membresía/días/pago/entreno/racha/seguridad).
    Route::get('member/app-state', [\App\Http\Controllers\Api\MemberAppStateController::class, 'show']);

    // ── Soporte: el miembro reporta un error / lo que pasó (→ bandeja del CRM) ──
    Route::post('member/support', [\App\Http\Controllers\Api\MemberSupportController::class, 'store'])
        ->middleware('throttle:8,1');

    // ── Canal real-time PRIVADO del miembro (SSE): empuja señales de cambio
    // (membresía/pago/perfil/staff/story/seguridad) para refrescar sin polling.
    Route::get('member/realtime', [\App\Http\Controllers\Api\MemberRealtimeController::class, 'stream']);

    // ── Membresía: renovación / cancelación (Bloque 3) ────────────────────────
    // cancel-request = vista previa; cancel-confirm = ejecuta (acción reversible:
    // conserva acceso hasta fin de periodo). reactivate la deshace.
    Route::get('member/membership/status', [\App\Http\Controllers\Api\MembershipController::class, 'status']);
    Route::middleware('throttle:20,1')->group(function (): void {
        Route::post('member/membership/cancel-request', [\App\Http\Controllers\Api\MembershipController::class, 'cancelRequest']);
        Route::post('member/membership/cancel-confirm', [\App\Http\Controllers\Api\MembershipController::class, 'cancelConfirm']);
        Route::post('member/membership/reactivate',     [\App\Http\Controllers\Api\MembershipController::class, 'reactivate']);
    });

    // ── Publicidad + Eventos del gimnasio (Bloque 4) ──────────────────────────
    Route::get('member/ads/active',     [\App\Http\Controllers\Api\AppAdController::class, 'active']);
    Route::post('member/ads/{ad}/seen', [\App\Http\Controllers\Api\AppAdController::class, 'seen']);
    Route::get('member/events',         [\App\Http\Controllers\Api\AppEventController::class, 'index']);
    Route::get('member/events/{event}', [\App\Http\Controllers\Api\AppEventController::class, 'show']);

    // ── Story Live / transmisiones en vivo (Bloque 5) ─────────────────────────
    // 'active' antes de {live}; {live} solo numérico (route-model binding).
    Route::get('member/live/active',            [\App\Http\Controllers\Api\LiveController::class, 'active']);
    Route::post('member/live/create',           [\App\Http\Controllers\Api\LiveController::class, 'create']);
    Route::get('member/live/{live}',            [\App\Http\Controllers\Api\LiveController::class, 'show'])->whereNumber('live');
    Route::post('member/live/{live}/start',     [\App\Http\Controllers\Api\LiveController::class, 'start'])->whereNumber('live');
    Route::post('member/live/{live}/end',       [\App\Http\Controllers\Api\LiveController::class, 'end'])->whereNumber('live');
    Route::post('member/live/{live}/join-token',[\App\Http\Controllers\Api\LiveController::class, 'joinToken'])->whereNumber('live');

    // ── Perfil editable + foto (subida a Firebase por el cliente, aquí se
    // guarda la URL/ruta tras validar ownership).
    Route::get('member/profile', [\App\Http\Controllers\Api\MemberProfileController::class, 'show']);
    Route::patch('member/profile', [\App\Http\Controllers\Api\MemberProfileController::class, 'update']);
    Route::post('member/profile/photo', [\App\Http\Controllers\Api\MemberProfileController::class, 'updatePhoto'])
        ->middleware('throttle:20,1');

    // ── Contexto saneado del miembro para el coach IRON IA (datos reales).
    Route::get('member/ai/context', [\App\Http\Controllers\Api\MemberAiContextController::class, 'show']);

    // ── Eliminación de cuenta/datos iniciada desde la app (App Store 5.1.1(v)).
    Route::get('member/account/deletion-status', [\App\Http\Controllers\Api\MemberAccountController::class, 'deletionStatus']);
    Route::post('member/account/delete-request', [\App\Http\Controllers\Api\MemberAccountController::class, 'deleteRequest'])
        ->middleware('throttle:10,1');
    Route::post('member/account/delete-confirm', [\App\Http\Controllers\Api\MemberAccountController::class, 'deleteConfirm'])
        ->middleware('throttle:6,1');

    // ── Seguridad: sesiones / dispositivos del miembro ────────────────────────
    Route::get('members/devices', [AuthController::class, 'devices']);
    Route::post('members/devices/{uuid}/revoke', [AuthController::class, 'revokeDevice']);
    // Cerrar todas las demás sesiones (conserva la actual). Dos alias.
    Route::post('member/devices/revoke-others', [AuthController::class, 'revokeOthers']);
    Route::post('member/sessions/logout-others', [AuthController::class, 'revokeOthers']);
    Route::post('members/logout', [AuthController::class, 'logout']);

    // Acciones sensibles de dispositivo con 2FA (Fase 6 / Fase 8): request dispara
    // el OTP, confirm lo valida antes de ejecutar. Throttle defensivo.
    Route::middleware('throttle:12,1')->group(function (): void {
        Route::post('members/devices/{uuid}/revoke-request',  [AuthController::class, 'revokeDeviceRequest']);
        Route::post('members/devices/{uuid}/revoke-confirm',  [AuthController::class, 'revokeDeviceConfirm']);
        Route::post('member/devices/revoke-others-request',   [AuthController::class, 'revokeOthersRequest']);
        Route::post('member/devices/revoke-others-confirm',   [AuthController::class, 'revokeOthersConfirm']);
        Route::post('members/logout/unbind-request',          [AuthController::class, 'logoutUnbindRequest']);
        Route::post('members/logout/unbind-confirm',          [AuthController::class, 'logoutUnbindConfirm']);
    });
    // Cambio de número de teléfono con 2FA (Fase 5): OTP al número NUEVO.
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('member/security/phone-change/request',         [\App\Http\Controllers\Api\MemberAccountController::class, 'phoneChangeRequest']);
        Route::post('member/security/phone-change/verify',          [\App\Http\Controllers\Api\MemberAccountController::class, 'phoneChangeVerify']);
        Route::post('member/security/phone-change/support-request', [\App\Http\Controllers\Api\MemberAccountController::class, 'phoneChangeSupportRequest']);
    });

    // Push nativo (FCM): registrar/baja del token del dispositivo.
    Route::post('members/push-token', [AuthController::class, 'registerPushToken']);
    Route::post('members/push-token/remove', [AuthController::class, 'removePushToken']);

    Route::get('app/classes', [AppClassController::class, 'index']);
    // "Organizar mi semana": planificación y reserva semanal en lote. DEBEN ir
    // antes de las rutas con {myClass} para que "weekly" no se enlace como clase.
    Route::get('app/classes/weekly', [AppClassController::class, 'weeklyPlan']);
    Route::post('app/classes/weekly/reserve', [AppClassController::class, 'reserveWeek']);
    Route::post('app/classes/{myClass}/reserve', [AppClassController::class, 'reserve']);
    Route::delete('app/classes/{myClass}/reserve', [AppClassController::class, 'cancel']);
    Route::post('app/classes/{myClass}/check-in', [AppClassController::class, 'checkIn']);
    // Rutas alias en /classes para compatibilidad con la app móvil
    Route::post('classes/{myClass}/reserve', [ClassController::class, 'reserve']);
    Route::post('classes/{myClass}/cancel',  [ClassController::class, 'cancel']);
    Route::post('classes/{myClass}/check-in', [ClassController::class, 'checkIn']);
    // Entrenador asignado al miembro autenticado (antes de trainers/{trainer}).
    Route::get('trainers/mine', [MemberTrainerController::class, 'mine']);
    // Calificación de entrenadores
    Route::post('trainers/{trainer}/rate', [TrainerController::class, 'rate']);
    // Rutinas para miembros
    // "Entrenamiento de hoy" del Home (rutina asignada del día, sin mock).
    Route::get('member/training/today',           [AppRoutineController::class, 'today']);
    Route::get('app/routines/assigned',           [AppRoutineController::class, 'assigned']);
    Route::get('app/routines/custom',             [AppRoutineController::class, 'custom']);
    // Catálogo de plantillas pre-hechas que el miembro explora y adopta.
    Route::get('app/routines/templates',          [AppRoutineController::class, 'templates']);
    Route::post('app/routines/templates/{routine}/adopt', [AppRoutineController::class, 'adopt']);
    Route::post('app/routines',                   [AppRoutineController::class, 'store']);
    Route::post('app/routines/{routine}/complete',[AppRoutineController::class, 'complete']);
    // Resumen nutricional diario (sincroniza desde la app → push al cumplir meta)
    Route::post('app/nutrition/day',              [AppNutritionController::class, 'store']);
    Route::delete('app/routines/{routine}',       [AppRoutineController::class, 'destroy']);
    Route::post('app/routines/{routine}/delete',  [AppRoutineController::class, 'destroy']);
    // ── Tienda (app) — lee el catálogo `products` (visible_in_app) del CRM ────
    // El checkout crea un pedido en product_sales (channel=app) que gestiona la
    // Caja del CRM. Ver AppStoreController y docs/STORE_CAJA_MODULE.md.
    Route::get('app/store/products',                 [AppStoreController::class, 'products']);
    Route::get('app/store/orders',                   [AppStoreController::class, 'orders']);
    Route::post('app/store/orders',                  [AppStoreController::class, 'createOrder']);
    Route::get('app/store/orders/{uuid}',            [AppStoreController::class, 'showOrder']);
    Route::post('app/store/orders/{uuid}/receipt',   [AppStoreController::class, 'attachReceipt']);

    // Historial de pagos del miembro autenticado (lee `payments` — la misma
    // tabla del CRM, una sola fuente de verdad).
    Route::get('app/payments', [AppPaymentController::class, 'index']);
    // Detalle de un pago del miembro. Refresca contra ePayco si está en vuelo
    // (pending/processing) reutilizando EpaycoPaymentService::statusFor. 404
    // tanto si la referencia no existe como si no pertenece al miembro.
    Route::get('app/payments/{reference}', [AppPaymentController::class, 'show'])
        ->where('reference', '[A-Za-z0-9_\-]+');

    // ── Stories tipo Instagram/WhatsApp (autenticadas como member) ────────────
    // ── Firebase Auth integration ─────────────────────────────────────────
    // Custom token firmado con el service-account.json. La app lo usa para
    // signInWithCustomToken y habilitar uploads seguros a Firebase Storage.
    Route::post('app/firebase/custom-token', [FirebaseAuthController::class, 'customToken']);

    Route::get('app/stories',              [StoriesController::class, 'indexAsMember']);
    Route::post('app/stories',             [StoriesController::class, 'storeAsMember']);
    // Story cuyo media ya se subió a Firebase Storage (metadata-only).
    Route::post('app/stories/firebase',    [StoriesController::class, 'storeAsMemberFirebase']);
    Route::post('app/stories/{id}/view',   [StoriesController::class, 'recordView']);
    Route::get('app/stories/{id}/viewers', [StoriesController::class, 'listViewers']);
    Route::post('app/stories/{id}/react',     [StoriesController::class, 'react']);
    Route::delete('app/stories/{id}/react',   [StoriesController::class, 'unreact']);
    Route::get('app/stories/{id}/reactions',  [StoriesController::class, 'listReactions']);
    Route::delete('app/stories/{id}',      [StoriesController::class, 'destroyAsMember']);

    // ── Racha semanal "Esta semana" ────────────────────────────────────────
    Route::post('app/weekly-streak/touch', [WeeklyStreakController::class, 'touch']);
    Route::get('app/weekly-streak',        [WeeklyStreakController::class, 'show']);

    // ── Nutrición ───────────────────────────────────────────────────────────
    Route::get('app/nutrition/today',  [NutritionController::class, 'today']);
    Route::get('app/nutrition/day',    [NutritionController::class, 'day']);
    Route::get('app/nutrition/goals',  [NutritionController::class, 'getGoals']);
    Route::post('app/nutrition/goals', [NutritionController::class, 'saveGoals']);
    Route::get('app/nutrition/history',[NutritionController::class, 'history']);
    Route::get('app/nutrition/foods',  [NutritionController::class, 'foods']);
    Route::post('app/nutrition/foods', [NutritionController::class, 'createFood']);
    Route::post('app/nutrition/meals/{mealType}/items', [NutritionController::class, 'addItem']);
    Route::delete('app/nutrition/meals/items/{id}',     [NutritionController::class, 'deleteItem'])
        ->where('id', '[0-9]+');
    // IRON IA coach nutricional (OpenAI desde Laravel).
    Route::post('app/nutrition/ai/recommendation', [NutritionController::class, 'aiRecommendation']);
    Route::get('app/nutrition/ai/last',            [NutritionController::class, 'aiLast']);

    // IRON IA — contexto seguro del usuario (debug/uso interno).
    Route::get('app/iron-ai/context-summary', [IronAiContextController::class, 'summary']);
    // IRON IA Coach contextual (plan del día, OpenAI desde Laravel).
    Route::post('app/iron-ai/coach', [IronAiContextController::class, 'coach']);

    // ── Centro de notificaciones del coach (app_notifications) ────────────────
    Route::get('app/notifications',                 [AppNotificationController::class, 'index']);
    Route::get('app/notifications/unread-count',     [AppNotificationController::class, 'unreadCount']);
    Route::post('app/notifications/read-all',        [AppNotificationController::class, 'readAll']);
    Route::post('app/notifications/{id}/read',       [AppNotificationController::class, 'markRead'])
        ->where('id', '[0-9]+');

    // ── Tokens FCM del dispositivo ────────────────────────────────────────────
    Route::post('app/device-tokens',                    [DeviceTokenController::class, 'store']);
    Route::post('app/device-tokens/deactivate-current', [DeviceTokenController::class, 'deactivateCurrent']);
    Route::delete('app/device-tokens/{id}',             [DeviceTokenController::class, 'destroy'])
        ->where('id', '[0-9]+');

    // ── Progreso ────────────────────────────────────────────────────────────
    Route::get('app/progress/summary',     [ProgressController::class, 'summary']);

    // ── Evaluación física (rutas estáticas ANTES de {id}) ───────────────────
    Route::get('app/physical-evaluations/latest', [PhysicalEvaluationController::class, 'latest']);
    Route::get('app/physical-evaluations',        [PhysicalEvaluationController::class, 'index']);
    Route::post('app/physical-evaluations',       [PhysicalEvaluationController::class, 'store']);
    Route::get('app/physical-evaluations/{id}',   [PhysicalEvaluationController::class, 'show'])
        ->where('id', '[0-9]+');
});

// ── Contratos firmados (CRM admin — patrón del resto del CRM) ──────────────
// PDFs servidos por streaming privado; cada descarga queda auditada.
Route::get('admin/members/{member}/contracts',     [ContractAdminController::class, 'forMember']);
Route::get('admin/users/{user}/contracts',          [ContractAdminController::class, 'forUser'])->whereNumber('user');
Route::get('admin/contracts/{contract}',           [ContractAdminController::class, 'show']);
Route::get('admin/contracts/{contract}/download',  [ContractAdminController::class, 'download']);
Route::post('admin/contracts/{contract}/void',     [ContractAdminController::class, 'void']);

// ── Seguridad: reporte de acceso/robo PÚBLICO desde el login (sin sesión) ──
// Rate-limit por IP; no revela si la cuenta existe (Fase 9).
Route::post('security/support-report', [\App\Http\Controllers\Api\SecuritySupportController::class, 'submit'])
    ->middleware('throttle:6,1');

// ── Recuperación SEGURA de número desde el login ("Ya no tengo este número") ──
// Sin sesión: el backend decide si el dispositivo es confiable (vínculo previo)
// y exige biometría local (app) + OTP al número NUEVO antes de actualizar.
Route::middleware('throttle:10,1')->group(function (): void {
    Route::post('member/phone-recovery/can-self-recover', [\App\Http\Controllers\Api\MemberPhoneRecoveryController::class, 'canSelfRecover']);
    Route::post('member/phone-recovery/start',            [\App\Http\Controllers\Api\MemberPhoneRecoveryController::class, 'start']);
    Route::post('member/phone-recovery/request',          [\App\Http\Controllers\Api\MemberPhoneRecoveryController::class, 'request']);
    Route::post('member/phone-recovery/verify',           [\App\Http\Controllers\Api\MemberPhoneRecoveryController::class, 'verify']);
});

// ── Auditoría general del CRM (append-only — patrón del resto del CRM) ─────
// Bitácora persistente que reemplaza el localStorage del navegador. El actor lo
// reporta el CRM (su sesión es de cliente) y solo sirve como traza, nunca para
// autorizar. `index` filtra por días/usuario/módulo/acción/búsqueda.
Route::get('admin/audit-logs',  [\App\Http\Controllers\Api\Admin\AuditLogController::class, 'index']);
Route::post('admin/audit-logs', [\App\Http\Controllers\Api\Admin\AuditLogController::class, 'store']);

// ── Supervisión de horarios de clases (CRM admin) ─────────────────────────
// Horario programado vs inicio/fin real (con rostro) por sesión de clase.
Route::get('admin/class-sessions', [\App\Http\Controllers\Api\Admin\ClassSupervisionController::class, 'index']);

// ── Seguridad: bandeja de reportes (CRM admin — patrón del resto del CRM) ──
Route::get('admin/security/reports',                       [\App\Http\Controllers\Api\SecuritySupportController::class, 'adminIndex']);
Route::get('admin/security/reports/{report}',              [\App\Http\Controllers\Api\SecuritySupportController::class, 'adminShow']);
Route::patch('admin/security/reports/{report}',            [\App\Http\Controllers\Api\SecuritySupportController::class, 'adminUpdate']);
Route::post('admin/security/reports/{report}/revoke-devices', [\App\Http\Controllers\Api\SecuritySupportController::class, 'adminRevokeDevices']);

// ── Seguridad: bloqueos/suspensiones (CRM admin) ───────────────────────────
Route::get('admin/security/locks',              [\App\Http\Controllers\Api\MemberRiskController::class, 'index']);
Route::post('admin/members/{member}/suspend',   [\App\Http\Controllers\Api\MemberRiskController::class, 'suspend']);
Route::post('admin/members/{member}/unlock',    [\App\Http\Controllers\Api\MemberRiskController::class, 'unlock']);

// ── Membresías: estado / cancelación / reactivación (CRM admin) ─────────────
// Patrón del CRM (sin auth a nivel de ruta). NUNCA borra datos del miembro.
Route::get('admin/memberships/{member}',             [\App\Http\Controllers\Api\Admin\MembershipController::class, 'show']);
Route::post('admin/memberships/{member}/cancel',     [\App\Http\Controllers\Api\Admin\MembershipController::class, 'cancel']);
Route::post('admin/memberships/{member}/reactivate', [\App\Http\Controllers\Api\Admin\MembershipController::class, 'reactivate']);

// ── Publicidad: campañas del Home (CRM admin) — Bloque 4 ────────────────────
// Patrón del CRM (sin auth a nivel de ruta). Para subir imagen por multipart en
// update, usar POST con _method=PATCH (method spoofing de Laravel).
Route::get('admin/ads',                 [\App\Http\Controllers\Api\Admin\AdController::class, 'index']);
Route::post('admin/ads',                [\App\Http\Controllers\Api\Admin\AdController::class, 'store']);
Route::match(['patch', 'post'], 'admin/ads/{ad}', [\App\Http\Controllers\Api\Admin\AdController::class, 'update']);
Route::delete('admin/ads/{ad}',         [\App\Http\Controllers\Api\Admin\AdController::class, 'destroy']);
Route::post('admin/ads/{ad}/activate',  [\App\Http\Controllers\Api\Admin\AdController::class, 'activate']);
Route::post('admin/ads/{ad}/deactivate',[\App\Http\Controllers\Api\Admin\AdController::class, 'deactivate']);

// ── Eventos del gimnasio (CRM admin) — Bloque 4 ─────────────────────────────
Route::get('admin/events',                  [\App\Http\Controllers\Api\Admin\EventController::class, 'index']);
Route::post('admin/events',                 [\App\Http\Controllers\Api\Admin\EventController::class, 'store']);
Route::match(['patch', 'post'], 'admin/events/{event}', [\App\Http\Controllers\Api\Admin\EventController::class, 'update']);
Route::delete('admin/events/{event}',       [\App\Http\Controllers\Api\Admin\EventController::class, 'destroy']);
Route::post('admin/events/{event}/activate',  [\App\Http\Controllers\Api\Admin\EventController::class, 'activate']);
Route::post('admin/events/{event}/deactivate',[\App\Http\Controllers\Api\Admin\EventController::class, 'deactivate']);
Route::post('admin/events/{event}/notify',    [\App\Http\Controllers\Api\Admin\EventController::class, 'notify']);

// ── Catálogo manual de ejercicios (CRM) — reemplaza el sync de proveedores ──
Route::get('admin/exercises',                  [\App\Http\Controllers\Api\Admin\ExerciseController::class, 'index']);
Route::post('admin/exercises/upload',          [\App\Http\Controllers\Api\Admin\ExerciseController::class, 'upload']);
Route::post('admin/exercises',                 [\App\Http\Controllers\Api\Admin\ExerciseController::class, 'store']);
Route::match(['put', 'patch'], 'admin/exercises/{exercise}', [\App\Http\Controllers\Api\Admin\ExerciseController::class, 'update']);
Route::delete('admin/exercises/{exercise}',    [\App\Http\Controllers\Api\Admin\ExerciseController::class, 'destroy']);

// ── Soporte (CRM): bandeja de reportes que envían los miembros desde la app ──
Route::get('admin/support/unread-count',       [\App\Http\Controllers\Api\Admin\SupportController::class, 'unreadCount']);
Route::get('admin/support',                    [\App\Http\Controllers\Api\Admin\SupportController::class, 'index']);
Route::get('admin/support/{ticket}',           [\App\Http\Controllers\Api\Admin\SupportController::class, 'show']);
Route::patch('admin/support/{ticket}',         [\App\Http\Controllers\Api\Admin\SupportController::class, 'update']);

// ── Story Live (CRM admin): historial + finalizar — Bloque 5 ────────────────
Route::get('admin/lives',             [\App\Http\Controllers\Api\Admin\LiveController::class, 'index']);
Route::post('admin/lives/{live}/end', [\App\Http\Controllers\Api\Admin\LiveController::class, 'end']);

// ── Acceso de staff a Story Live (CRM admin) — otorgar/quitar is_staff ───────
// Solo el CRM puede marcar a un miembro como staff (puede crear/transmitir lives).
Route::get('admin/members/{member}',               [\App\Http\Controllers\Api\Admin\MemberStaffController::class, 'show']);
Route::patch('admin/members/{member}/staff-access',[\App\Http\Controllers\Api\Admin\MemberStaffController::class, 'updateStaffAccess']);

// ── Stories CRM admin (sin auth — patrón del resto del CRM) ────────────────
Route::get('admin/stories',         [StoriesController::class, 'indexAsAdmin']);
Route::post('admin/stories',        [StoriesController::class, 'storeAsAdmin']);
Route::delete('admin/stories/{id}', [StoriesController::class, 'destroyAsAdmin']);
Route::apiResource('routines', RoutineController::class)->only(['index','show','store','update','destroy']);
Route::patch('routines/{routine}/assign', [RoutineController::class, 'assign']);
Route::post('trainers/{trainer}/reviews', [TrainerController::class, 'review']);
Route::apiResource('trainers', TrainerController::class)->only(['index','show','store','update','destroy']);

// ── Equipos del gimnasio ──────────────────────────────────────────────────────
// Inventario de máquinas físicas. Dos audiencias bien separadas:
//
//  • CRM admin (CRUD):       /api/admin/equipment*  → patrón /admin/* del CRM.
//  • IRON IA (solo lectura): GET /api/iron-ai/equipment-catalog
//
// 👉 PARA LA INTEGRACIÓN DE IA: consume SOLO el endpoint de catálogo. Devuelve
//    { generated_at, total, names[], by_category{}, items[] } (forma estable).
//    Úsalo como restricción dura para no recomendar ejercicios con máquinas
//    inexistentes. Ver App\Services\GymEquipmentContextService->promptConstraint().
Route::get('admin/equipment/stats', [GymEquipmentController::class, 'stats']);
Route::apiResource('admin/equipment', GymEquipmentController::class)
    ->parameters(['equipment' => 'equipment'])
    ->only(['index', 'show', 'store', 'update', 'destroy']);

Route::get('iron-ai/equipment-catalog', [GymEquipmentController::class, 'aiCatalog']);

// ── Inventario de productos (CRM) ─────────────────────────────────────────────
// Fuente única que también alimenta la Tienda de la app (visible_in_app).
Route::get('admin/products/stats', [ProductController::class, 'stats']);
Route::post('admin/products/{product}/stock', [ProductController::class, 'adjustStock']);
Route::apiResource('admin/products', ProductController::class)
    ->parameters(['products' => 'product'])
    ->only(['index', 'show', 'store', 'update', 'destroy']);

// ── Caja / Punto de venta (CRM) ───────────────────────────────────────────────
// POS en mostrador + gestión de pedidos que llegan de la app. (Luego se
// restringirá a ciertos usuarios.) Ver docs/STORE_CAJA_MODULE.md.
Route::get('admin/caja/stats',                  [CajaController::class, 'stats']);
Route::get('admin/caja/sales',                  [CajaController::class, 'index']);
Route::post('admin/caja/sales',                 [CajaController::class, 'store']);
Route::get('admin/caja/sales/{sale}',           [CajaController::class, 'show']);
Route::post('admin/caja/sales/{sale}/pay',      [CajaController::class, 'pay']);
Route::post('admin/caja/sales/{sale}/deliver',  [CajaController::class, 'deliver']);
Route::post('admin/caja/sales/{sale}/cancel',   [CajaController::class, 'cancel']);

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

// ── Módulo "Esta semana" — configuración y beneficios (CRM admin) ──────────────
Route::prefix('admin/weekly-streak')->group(function (): void {
    Route::get('members',                  [WeeklyStreakAdminController::class, 'members']);
    Route::get('configs',                  [WeeklyStreakAdminController::class, 'index']);
    Route::post('configs',                 [WeeklyStreakAdminController::class, 'storeConfig']);
    Route::match(['put', 'patch'], 'configs/{config}', [WeeklyStreakAdminController::class, 'updateConfig']);
    Route::delete('configs/{config}',      [WeeklyStreakAdminController::class, 'destroyConfig']);

    Route::post('rewards',                 [WeeklyStreakAdminController::class, 'storeReward']);
    Route::match(['put', 'patch'], 'rewards/{reward}', [WeeklyStreakAdminController::class, 'updateReward']);
    Route::delete('rewards/{reward}',      [WeeklyStreakAdminController::class, 'destroyReward']);

    Route::post('upload',                  [WeeklyStreakAdminController::class, 'uploadImage']);
});

// ── Nutrición: moderación comunitaria (CRM admin — patrón del resto del CRM) ──
Route::get('admin/nutrition/foods/pending',          [\App\Http\Controllers\Api\Nutrition\NutritionFoodAdminController::class, 'pending']);
Route::get('admin/nutrition/foods/{uuid}',           [\App\Http\Controllers\Api\Nutrition\NutritionFoodAdminController::class, 'show']);
Route::post('admin/nutrition/foods/{uuid}/verify',   [\App\Http\Controllers\Api\Nutrition\NutritionFoodAdminController::class, 'verify']);
Route::post('admin/nutrition/foods/{uuid}/reject',   [\App\Http\Controllers\Api\Nutrition\NutritionFoodAdminController::class, 'reject']);
Route::post('admin/nutrition/foods/{uuid}/merge',    [\App\Http\Controllers\Api\Nutrition\NutritionFoodAdminController::class, 'merge']);
Route::post('admin/nutrition/foods/{uuid}/ai-review', [\App\Http\Controllers\Api\Nutrition\NutritionFoodAdminController::class, 'aiReview']);

// ── Nutrición (CRM admin) ─────────────────────────────────────────────────────
Route::get('admin/members/{member}/nutrition',                 [NutritionAdminController::class, 'show']);
Route::post('admin/members/{member}/nutrition/goals',          [NutritionAdminController::class, 'saveGoals']);
Route::get('admin/members/{member}/nutrition/recommendations', [NutritionAdminController::class, 'recommendations']);

// ── Evaluaciones físicas (CRM admin) ──────────────────────────────────────────
Route::get('admin/physical-evaluations/members', [PhysicalEvaluationAdminController::class, 'members']);
Route::get('admin/members/{member}/physical-evaluations',  [PhysicalEvaluationAdminController::class, 'index']);
Route::post('admin/members/{member}/physical-evaluations', [PhysicalEvaluationAdminController::class, 'store']);
Route::match(['put', 'patch'], 'admin/physical-evaluations/{evaluation}', [PhysicalEvaluationAdminController::class, 'update']);
Route::delete('admin/physical-evaluations/{evaluation}',   [PhysicalEvaluationAdminController::class, 'destroy']);

// ── Entrenador ↔ miembro (CRM admin) ──────────────────────────────────────────
// Liberar el vínculo dispositivo↔cuenta (anti-uso-compartido) desde el CRM.
Route::post('admin/devices/{deviceId}/release', [AuthController::class, 'releaseDeviceBinding']);

Route::post('admin/members/{member}/assign-trainer',   [MemberTrainerController::class, 'assign']);
Route::post('admin/members/{member}/unassign-trainer', [MemberTrainerController::class, 'unassign']);
Route::get('admin/members/{member}/trainer',           [MemberTrainerController::class, 'showAdmin']);

Route::get('/reports/stats', function () {
    // 8 agregaciones pesadas que se piden cada vez que el CRM abre el dashboard.
    // Cachear 60s reduce esto a 1 hit DB por minuto sin perder utilidad.
    return response()->json(\Illuminate\Support\Facades\Cache::remember('reports.stats', 60, function () {
        $driver = \DB::connection()->getDriverName();
        // Expresión de "año-mes" según motor: SQLite, PostgreSQL y MySQL la
        // implementan distinto. Sin esto, Postgres devolvía 500.
        $monthExpr = match ($driver) {
            'sqlite' => "strftime('%Y-%m', paid_at)",
            'pgsql'  => "TO_CHAR(paid_at, 'YYYY-MM')",
            default  => "DATE_FORMAT(paid_at, '%Y-%m')",
        };

        return [
            'total_revenue'     => (float) Payment::where('status', 'paid')->sum('amount'),
            'pending_payments'  => Payment::where('status', 'pending')->count(),
            'active_members'    => User::where('status', 'active')->count(),
            'total_members'     => User::count(),
            'active_plans'      => Plan::where('active', true)->count(),
            'active_classes'    => MyClass::where('status', 'active')->count(),
            'revenue_by_month'  => Payment::where('status', 'paid')
                ->selectRaw("$monthExpr as month, SUM(amount) as total")
                ->groupBy('month')
                ->orderBy('month')
                ->get(),
            'payments_by_status'=> Payment::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get(),
        ];
    }));
});

// ── Automatización interna (disparada por n8n, firmada HMAC) ───────────────────
// n8n solo coordina: NO accede a PostgreSQL ni construye contexto. Laravel
// genera el resumen y emite iron_ai.weekly_summary_ready.
// throttle: defensa extra al HMAC (limita el abuso si el secreto se filtra).
Route::middleware(['automation.internal', 'throttle:120,1'])->prefix('internal/automation')->group(function (): void {
    Route::post('weekly-summary', [\App\Http\Controllers\Api\Internal\WeeklySummaryController::class, 'generate']);
    Route::post('notify-member',  [\App\Http\Controllers\Api\Internal\NotifyMemberController::class, 'notify']);
    // Coach humano: crea/enriquece la tarea del entrenador asignado.
    Route::post('notify-trainer', [\App\Http\Controllers\Api\Internal\NotifyTrainerController::class, 'notify']);
});

// ── Asesor comercial IA (F3) — disparado por n8n, firmado HMAC ────────────────
// Seguro con META_ENABLED=false (no envía mensajes vivos). Opera marketing_*.
Route::middleware(['automation.internal', 'throttle:120,1'])->prefix('internal/marketing')->group(function (): void {
    Route::post('ai-action',      [\App\Http\Controllers\Api\Internal\InternalMarketingController::class, 'aiAction']);
    Route::post('send-message',   [\App\Http\Controllers\Api\Internal\InternalMarketingController::class, 'sendMessage']);
    Route::post('human-takeover', [\App\Http\Controllers\Api\Internal\InternalMarketingController::class, 'humanTakeover']);
    Route::post('followups',      [\App\Http\Controllers\Api\Internal\InternalMarketingController::class, 'followups']);
    Route::get('context/{lead}',  [\App\Http\Controllers\Api\Internal\InternalMarketingController::class, 'context'])->where('lead', '[0-9]+');
});

// ── Coach humano — tareas del entrenador (CRM admin) ──────────────────────────
// Patrón del resto del CRM: rutas /admin/* sin auth propia (protegidas por la
// capa de red/front del CRM). Los entrenadores aún no tienen login en backend,
// por eso el consumo principal es el CRM/admin. Ver TrainerTaskController.
Route::get('admin/trainers/{trainer}/tasks',              [\App\Http\Controllers\Api\Admin\TrainerTaskController::class, 'index']);
Route::get('admin/trainers/{trainer}/tasks/unread-count', [\App\Http\Controllers\Api\Admin\TrainerTaskController::class, 'unreadCount']);
Route::post('admin/trainer-tasks/{id}/seen',     [\App\Http\Controllers\Api\Admin\TrainerTaskController::class, 'seen'])->where('id', '[0-9]+');
Route::post('admin/trainer-tasks/{id}/complete', [\App\Http\Controllers\Api\Admin\TrainerTaskController::class, 'complete'])->where('id', '[0-9]+');
Route::post('admin/trainer-tasks/{id}/dismiss',  [\App\Http\Controllers\Api\Admin\TrainerTaskController::class, 'dismiss'])->where('id', '[0-9]+');
Route::get('admin/members/{member}/coach-timeline', [\App\Http\Controllers\Api\Admin\TrainerTaskController::class, 'memberTimeline']);

// ── Portal profesional — administración de entrenadores (CRM admin) ───────────
// Patrón /admin/* del CRM (sin auth de ruta; el acceso se controla en el CRM).
// Aditivo al CRUD de TrainerController: roles, sede, enlace de identidad,
// activación/desactivación y auditoría del perfil profesional.
Route::get('admin/trainers/{trainer}/professional',        [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'show']);
Route::put('admin/trainers/{trainer}/professional',        [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'updateProfessional']);
Route::post('admin/trainers/{trainer}/identity/link',      [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'linkIdentity']);
Route::post('admin/trainers/{trainer}/activate',           [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'activate']);
Route::post('admin/trainers/{trainer}/deactivate',         [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'deactivate']);
Route::get('admin/trainers/{trainer}/devices',             [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'devices']);
Route::post('admin/trainers/{trainer}/devices/{uuid}/revoke', [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'revokeDevice']);
Route::post('admin/trainers/{trainer}/sessions/revoke-all', [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'revokeAllSessions']);
Route::delete('admin/trainers/{trainer}/face',              [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'deleteFace']);
Route::get('admin/trainers/{trainer}/audit',               [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'audit']);
// Miembros asignados al entrenador (CRM Angular) — reutiliza member_trainer_assignments.
Route::get('admin/trainers/{trainer}/members/search',       [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'searchMembers']);
Route::get('admin/trainers/{trainer}/members',             [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'members']);
Route::post('admin/trainers/{trainer}/members',            [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'assignMembers']);
Route::delete('admin/trainers/{trainer}/members/{member}', [\App\Http\Controllers\Api\Admin\TrainerAdminController::class, 'unassignMember']);

// ── Mercadeo digital (Meta) — datos reales de las tablas marketing_* (CRM admin) ─
// Patrón /admin/* del CRM. Sirven datos reales; si no hay registros → vacío/0/null.
Route::get('admin/marketing/overview',                       [\App\Http\Controllers\Api\Admin\MarketingController::class, 'overview']);
Route::get('admin/marketing/campaigns',                      [\App\Http\Controllers\Api\Admin\MarketingController::class, 'campaigns']);
Route::get('admin/marketing/leads',                          [\App\Http\Controllers\Api\Admin\MarketingController::class, 'leads']);
Route::get('admin/marketing/conversations',                  [\App\Http\Controllers\Api\Admin\MarketingController::class, 'conversations']);
Route::get('admin/marketing/conversations/{id}/messages',    [\App\Http\Controllers\Api\Admin\MarketingController::class, 'conversationMessages'])->where('id', '[0-9]+');
Route::get('admin/marketing/followups',                      [\App\Http\Controllers\Api\Admin\MarketingController::class, 'followups']);
Route::get('admin/marketing/ai-actions',                     [\App\Http\Controllers\Api\Admin\MarketingController::class, 'aiActions']);
Route::get('admin/marketing/attribution',                    [\App\Http\Controllers\Api\Admin\MarketingController::class, 'attribution']);
