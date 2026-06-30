<?php

use App\Http\Controllers\Api\Admin\MarketingController;
use App\Http\Controllers\Api\Admin\MarketingInboxController;
use App\Http\Controllers\Api\Internal\InternalMarketingController;
use App\Http\Controllers\Api\Internal\InternalMarketingKnowledgeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del Agente Comercial IA (módulo Mercadeo) — Fase 0/1
|--------------------------------------------------------------------------
| Archivo SEPARADO para no seguir inflando routes/api.php. Se monta con el
| grupo `api` (prefijo /api + middleware del grupo, incluido ProtectAdminPaths)
| desde bootstrap/app.php. NO cambia rutas existentes ni los webhooks públicos.
|
| - internal/marketing/*  → lo dispara n8n/el agente, firmado HMAC
|   (automation.internal). Seguro con META_ENABLED=false.
| - admin/marketing/*      → CRM Angular. Protegido por ProtectAdminPaths global
|   (igual que el resto de /api/admin/*).
*/

// ── Agente comercial — endpoints internos (n8n / agente), firmados HMAC ───────
Route::middleware(['automation.internal', 'throttle:120,1'])
    ->prefix('internal/marketing')
    ->group(function (): void {
        // Genera un link de pago Wompi para enviar por WhatsApp/Meta. El monto es
        // autoritativo del backend; generar el link NO activa membresía.
        Route::post('payment-links', [InternalMarketingController::class, 'paymentLinks']);

        // Flujo completo: genera el link y lo envía por WhatsApp (dry_run si META
        // está deshabilitado/sin credenciales). Nunca activa membresía.
        // (send-message vive en routes/api.php; aquí solo lo nuevo de Fase 1.5.)
        Route::post('payment-links/send', [InternalMarketingController::class, 'paymentLinksSend']);

        // Readiness de Meta/WhatsApp (sin secretos) para n8n/operación (Fase 1.6).
        Route::get('meta/doctor', [InternalMarketingController::class, 'metaDoctor']);

        // Cerebro comercial IA (Fase 2): clasifica intención y decide acciones.
        // auto_execute solo dispara acciones seguras (link en dry_run si Meta off).
        Route::post('ai/analyze-message', [InternalMarketingController::class, 'analyzeMessage']);

        // Readiness del cerebro IA (driver/OpenAI/responder efectivo) sin secretos.
        Route::get('ai/doctor', [InternalMarketingController::class, 'aiDoctor']);

        // Base de conocimiento comercial (Fase 3.5). Solo interno (HMAC).
        Route::get('knowledge/doctor', [InternalMarketingKnowledgeController::class, 'doctor']);
        Route::get('knowledge',        [InternalMarketingKnowledgeController::class, 'index']);
        Route::post('knowledge',       [InternalMarketingKnowledgeController::class, 'upsert']);
    });

// ── Agente comercial — endpoint admin (CRM): generar link desde el panel ──────
// Protegido por ProtectAdminPaths (blindaje global de /api/admin/*). No envía el
// mensaje automáticamente: solo devuelve el link para que un humano lo comparta.
Route::post('admin/marketing/leads/{lead}/payment-link', [MarketingController::class, 'paymentLink'])
    ->where('lead', '[0-9]+');

// ── Inbox CRM de WhatsApp (Fase 2A) ───────────────────────────────────────────
// Bandeja para operar WhatsApp Cloud API desde el CRM. Protegido por el blindaje
// global de /api/admin/* (ProtectAdminPaths reutiliza EnsureAdminAuth::challenge,
// que deja el admin en `auth_admin`). Throttle adicional al grupo `api`.
Route::middleware('throttle:120,1')
    ->prefix('admin/marketing/inbox')
    ->where(['id' => '[0-9]+'])
    ->group(function (): void {
        Route::get('conversations',                      [MarketingInboxController::class, 'index']);
        Route::get('metrics',                            [MarketingInboxController::class, 'metrics']);
        Route::get('capabilities',                       [MarketingInboxController::class, 'capabilities']);
        Route::get('conversations/{id}',                 [MarketingInboxController::class, 'show']);

        // Envío manual (throttle más estricto para frenar spam de salida).
        Route::post('conversations/{id}/messages', [MarketingInboxController::class, 'sendMessage'])
            ->middleware('throttle:30,1');

        Route::post('conversations/{id}/takeover',            [MarketingInboxController::class, 'takeover']);
        Route::post('conversations/{id}/release',             [MarketingInboxController::class, 'release']);
        Route::post('conversations/{id}/assign',              [MarketingInboxController::class, 'assign']);
        Route::post('conversations/{id}/notes',               [MarketingInboxController::class, 'addNote']);
        Route::post('conversations/{id}/tags',                [MarketingInboxController::class, 'tags']);
        Route::patch('conversations/{id}/status',             [MarketingInboxController::class, 'status']);
        Route::post('conversations/{id}/staff-review/resolve', [MarketingInboxController::class, 'resolveStaffReview']);
    });
