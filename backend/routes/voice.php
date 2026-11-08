<?php

use App\Http\Controllers\Api\Voice\ElevenLabsInitiationController;
use App\Http\Controllers\Api\Voice\ElevenLabsPostCallController;
use App\Http\Controllers\Api\Voice\TwilioVoiceUsageTriggerController;
use App\Http\Controllers\Api\Voice\VoiceToolController;
use App\Http\Controllers\Crm\Voice\VoiceCallController;
use App\Http\Controllers\Crm\Voice\VoiceContactController;
use App\Http\Middleware\LimitRequestBodySize;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| VOZ Y TELEFONÍA (Hito 8)
|--------------------------------------------------------------------------
| Contrato: docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md, D8-4, D8-16,
| D8-22 y «API». Fichero propio (D8-4): `routes/api.php` (el del OTP) no se
| toca. Se monta desde bootstrap/app.php con el grupo `api` (prefijo /api +
| ProtectAdminPaths + EnforceAdminAuthorization), como el Studio.
|
| PÚBLICAS (las cuatro de D8-16, solo POST, sin sesión y fuera de
| /api/admin): cada una con su autenticación propia en el controlador (firma
| de ElevenLabs, secreto de iniciación, Bearer de herramientas o firma de
| Twilio) y su TOPE DE CUERPO antes de leer nada. SIN límite por IP antes de
| autenticar (Enmienda 5, E5-4: ElevenLabs sale por dos IP que comparten todos
| sus clientes y el `throttle:N,1` contaba en un cubo `sha1(dominio|IP)` común a
| las cuatro): el controlador limita DESPUÉS de autenticar, por ruta y por
| llamada, y cuenta los fallos en su propio cubo `ruta|IP|fallo`
| (App\Services\Telephony\VoiceRateLimits). Están clasificadas en
| AuthorizationMap::PUBLIC_VOICE_ROUTES con su razón.
|
| CRM (/api/admin/voice/*): cada ruta con su llave `telephony.*` en
| AuthorizationMap::OVERRIDES, una a una (los controladores no están en
| CONTROLLERS: una ruta nueva sin clasificar se deniega), y además una
| persona. Tope de 16 KB (el cuerpo más grande, un contacto, no llega a 1 KB).
| Dos cupos CON NOMBRE (B8-c; PERF8 P8-5 y el hallazgo de OPS8-b): lectura
| 120/min y escritura 30/min POR PERSONA, y sin sesión un cubo propio por ruta
| y red (60 y 30/min), en `AppServiceProvider::limitadoresDeLaTelefonia()`.
| Nunca `throttle:N,M` sin nombre: sin usuario cuenta por IP en el mismo cubo
| que el login del panel.
*/

Route::middleware(LimitRequestBodySize::class.':1048576')
    ->post('webhooks/voice/elevenlabs/post-call', ElevenLabsPostCallController::class);

Route::middleware(LimitRequestBodySize::class.':8192')
    ->post('webhooks/voice/elevenlabs/initiation', ElevenLabsInitiationController::class);

Route::middleware(LimitRequestBodySize::class.':8192')
    ->post('voice/tools/{tool}', VoiceToolController::class)
    ->where('tool', '[a-z_]{1,48}');

Route::middleware(LimitRequestBodySize::class.':65536')
    ->post('webhooks/voice/twilio/usage-trigger', TwilioVoiceUsageTriggerController::class);

Route::prefix('admin/voice')->middleware(LimitRequestBodySize::class.':16384')->group(function (): void {
    Route::middleware('throttle:voice-admin-read')->group(function (): void {
        Route::get('status', [VoiceCallController::class, 'status']);
        Route::get('calls', [VoiceCallController::class, 'index']);
        Route::get('calls/{uuid}', [VoiceCallController::class, 'show'])->whereUuid('uuid');
        Route::get('metrics', [VoiceCallController::class, 'metrics']);
        Route::get('contacts', [VoiceContactController::class, 'index']);
    });

    Route::middleware('throttle:voice-admin-write')->group(function (): void {
        Route::post('calls', [VoiceCallController::class, 'store']);
        Route::post('calls/{uuid}/hangup', [VoiceCallController::class, 'hangup'])->whereUuid('uuid');
        Route::post('contacts', [VoiceContactController::class, 'store']);
        Route::post('contacts/{uuid}/consents', [VoiceContactController::class, 'requestConsent'])->whereUuid('uuid');
        Route::post('contacts/{uuid}/consents/confirm', [VoiceContactController::class, 'confirmConsent'])->whereUuid('uuid');
        Route::post('contacts/{uuid}/revoke', [VoiceContactController::class, 'revoke'])->whereUuid('uuid');
        Route::post('exclusions', [VoiceContactController::class, 'exclude']);
        Route::post('rne-checks', [VoiceContactController::class, 'rneCheck']);
    });
});
