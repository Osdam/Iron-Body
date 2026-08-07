<?php

use App\Http\Controllers\Api\Admin\IronGuardController;
use App\Http\Controllers\Api\Admin\MarketingAgentActionController;
use App\Http\Controllers\Api\Admin\MarketingAppointmentController;
use App\Http\Controllers\Api\Admin\MarketingAnalyticsController;
use App\Http\Controllers\Api\Admin\MarketingAttachmentController;
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
        // Catalogo de etiquetas para autocompletar y filtrar en el inbox.
        Route::get('tags',                               [MarketingInboxController::class, 'tagCatalog']);
        Route::get('conversations/{id}',                 [MarketingInboxController::class, 'show']);
        // Panel derecho del Inbox V2: contexto agregado y de SOLO LECTURA.
        Route::get('conversations/{id}/context',         [MarketingInboxController::class, 'context']);
        // Historial paginado por cursor (hacia atras).
        Route::get('conversations/{id}/messages',        [MarketingInboxController::class, 'messages']);

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

        // Citas de la conversación (panel "Citas del lead" del Inbox).
        Route::get('conversations/{id}/appointments', [MarketingAppointmentController::class, 'forConversation']);

        // Acciones del agente para la conversación (panel "Acciones sugeridas").
        Route::get('conversations/{id}/agent-actions', [MarketingAgentActionController::class, 'forConversation']);
        Route::post('conversations/{id}/agent-actions/recommend', [MarketingAgentActionController::class, 'recommendForConversation']);

        // Paso 1 de los adjuntos: pedir una URL firmada de vida corta. Exige
        // sesión de administrador y permiso de Inbox como cualquier otra acción.
        Route::get('attachments/{id}/link', [MarketingAttachmentController::class, 'link']);

        // Subida de un archivo para adjuntar a una respuesta. Throttle propio y
        // más estrecho que el del grupo: cada petición mueve megabytes y escribe
        // en disco, así que no puede compartir cupo con las lecturas del inbox.
        Route::post('attachments', [MarketingInboxController::class, 'uploadAttachment'])
            ->middleware('throttle:40,1');
    });

// ── Analitica comercial de pautas (D.2.4) ─────────────────────────────────────
// SOLO LECTURA y detras del blindaje global de /api/admin/*. Endpoints
// pequeños a proposito: uno gigante obligaria a calcularlo todo aunque la
// pantalla enseñe solo el resumen, y cuando fuera lento nadie sabria que parte
// lo es. Exigen permiso de METRICAS, no el de atender: ver la facturacion por
// campaña y contestar conversaciones son cosas distintas.
Route::middleware('throttle:60,1')
    ->prefix('admin/marketing/analytics')
    ->group(function (): void {
        Route::get('summary',                [MarketingAnalyticsController::class, 'summary']);
        Route::get('funnel',                 [MarketingAnalyticsController::class, 'funnel']);
        Route::get('quality',                [MarketingAnalyticsController::class, 'quality']);
        Route::get('insights',               [MarketingAnalyticsController::class, 'insights']);
        Route::get('campaigns/{campaign}',   [MarketingAnalyticsController::class, 'campaign'])
            ->where('campaign', '.*');
        Route::get('breakdown/{dimension}',  [MarketingAnalyticsController::class, 'breakdown']);
    });

// ── Paso 2 de los adjuntos: el binario ────────────────────────────────────────
// FUERA de /api/admin/* a propósito: la firma temporal ES la autorización, y así
// el navegador puede pedirla desde un <img> o un <audio> sin cabeceras. La URL
// solo se obtiene con sesión válida (paso 1) y caduca sola en minutos, de modo
// que copiarla fuera del CRM no da acceso duradero a nada.
Route::middleware(['signed', 'throttle:120,1'])
    ->get('marketing/attachments/{id}/download', [MarketingAttachmentController::class, 'download'])
    ->where('id', '[0-9]+')
    ->name('marketing.attachment.download');

// ── Acciones CRM del agente comercial (Fase 4C) ───────────────────────────────
// Human-in-the-loop: recomendar / aprobar / rechazar / ejecutar / cancelar.
// Misma protección global de /api/admin/* + autorización fina por rol/estado.
Route::middleware('throttle:120,1')
    ->prefix('admin/marketing/agent-actions')
    ->where(['id' => '[0-9]+'])
    ->group(function (): void {
        Route::get('/',              [MarketingAgentActionController::class, 'index']);
        Route::get('capabilities',   [MarketingAgentActionController::class, 'capabilities']);
        Route::post('recommend',     [MarketingAgentActionController::class, 'recommend']);
        Route::get('{id}',           [MarketingAgentActionController::class, 'show']);
        Route::post('{id}/approve',  [MarketingAgentActionController::class, 'approve']);
        Route::post('{id}/reject',   [MarketingAgentActionController::class, 'reject']);
        Route::post('{id}/execute',  [MarketingAgentActionController::class, 'execute']);
        Route::post('{id}/cancel',   [MarketingAgentActionController::class, 'cancel']);
    });

// ── Agenda comercial / citas para leads (Fase 4B) ─────────────────────────────
// Misma protección global de /api/admin/*. Autorización fina por rol/estado +
// ownership en MarketingAppointmentAuthorizationService.
Route::middleware('throttle:120,1')
    ->prefix('admin/marketing/appointments')
    ->where(['id' => '[0-9]+'])
    ->group(function (): void {
        Route::get('/',               [MarketingAppointmentController::class, 'index']);
        Route::post('/',              [MarketingAppointmentController::class, 'store']);
        Route::get('capabilities',    [MarketingAppointmentController::class, 'capabilities']);
        Route::get('{id}',            [MarketingAppointmentController::class, 'show']);
        Route::patch('{id}',          [MarketingAppointmentController::class, 'update']);
        Route::post('{id}/complete',  [MarketingAppointmentController::class, 'complete']);
        Route::post('{id}/cancel',    [MarketingAppointmentController::class, 'cancel']);
        Route::post('{id}/reschedule', [MarketingAppointmentController::class, 'reschedule']);
    });

// ── IRON GUARD — panel de incidentes del canal ────────────────────────────────
// Protegido por el blindaje global de /api/admin/* y, además, restringido a
// administradores plenos dentro del propio controlador: aquí se ven ids
// internos, códigos de error e infraestructura, que no es información para un
// asesor comercial.
Route::middleware('throttle:120,1')
    ->prefix('admin/iron-guard')
    ->where(['id' => '[0-9]+'])
    ->group(function (): void {
        Route::get('overview',        [IronGuardController::class, 'overview']);
        Route::get('incidents',       [IronGuardController::class, 'index']);
        Route::get('incidents/{id}',  [IronGuardController::class, 'show']);
        Route::patch('incidents/{id}/status', [IronGuardController::class, 'updateStatus']);

        // Acciones que tocan el sistema: throttle más estricto.
        Route::post('scan', [IronGuardController::class, 'scan'])->middleware('throttle:10,1');
        Route::post('incidents/{id}/remediate', [IronGuardController::class, 'remediate'])
            ->middleware('throttle:20,1');
    });
