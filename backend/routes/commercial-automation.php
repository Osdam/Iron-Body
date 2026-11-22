<?php

use App\Http\Controllers\Crm\CommercialAutomation\OutreachConsentController;
use App\Http\Middleware\LimitRequestBodySize;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AUTOMATIZACIÓN COMERCIAL (/api/admin/commercial-automation/*)
|--------------------------------------------------------------------------
| Contrato: docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md §7 («fichero propio
| routes/commercial-automation.php, montado en bootstrap/app.php como
| routes/voice.php») y la Enmienda C9. La ejecución del H9 (`outreach/**`) se
| quedó en routes/ultron-studio.php (L9-43); aquí van las AUTORIZACIONES DE
| CONTACTO de C9-3: 5 rutas (OutreachConsentController::ROUTES). Se monta con
| el grupo `api` (prefijo /api + ProtectAdminPaths + EnforceAdminAuthorization).
|
| Cada ruta exige su llave `commercial_automation.consents_*` en
| AuthorizationMap::OVERRIDES, una a una (el controlador no está en
| CONTROLLERS: una ruta nueva sin clasificar se deniega), una persona y, en el
| servicio, la llave de VER a la persona. Los cupos son los de la API de la
| ejecución (`ultron-studio-read`, 120/min, y `ultron-studio-write`, 30/min,
| por persona). Tope de cuerpo de 16 KB: el más grande no llega a 2 KB.
*/

Route::prefix('admin/commercial-automation')
    ->middleware(LimitRequestBodySize::class.':'.OutreachConsentController::BODY_LIMIT)
    ->group(function (): void {
        OutreachConsentController::routes();
    });
