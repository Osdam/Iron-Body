<?php

use App\Http\Controllers\Crm\UltronStudio\UltronStudioController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioRuleController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioVersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ULTRON STUDIO (/api/admin/ultron-studio/*)
|--------------------------------------------------------------------------
| Contrato: docs/ultron-studio/ULTRON_STUDIO_F_CONTRACT.md §A3. Fichero propio
| para que `git diff -- routes/marketing.php routes/api.php` quede vacío: el
| Studio no toca el motor. Se monta desde bootstrap/app.php con el grupo `api`
| (prefijo /api + ProtectAdminPaths + EnforceAdminAuthorization), igual que
| routes/marketing.php.
|
| Cada ruta exige su permiso `ultron.*` en AuthorizationMap::OVERRIDES, una a
| una; los controladores NO están en CONTROLLERS, así que una ruta nueva sin
| clasificar se deniega. Además exigen una persona. NINGUNA ruta del Studio
| cuelga de `internal/*` (UltronEngineIsolationTest lo vigila).
|
| Dos cupos por credencial (AppServiceProvider::limitadoresDelStudio):
| lectura 120/min y escritura 30/min. `validate` es una simulación que no
| escribe: va con las lecturas. Sin route-model binding: el uuid es texto.
|
| Hito G (docs/ultron-studio/ULTRON_STUDIO_G_CONTRACT.md §A6): las lecturas del
| motor (`engine/readings`, un literal) y la comparación (`compare`, con los
| lados en la query string), las dos de solo lectura y con el cupo de lectura.
| No chocan con `versions/{uuid}`. Son 21 rutas.
*/

Route::prefix('admin/ultron-studio')->group(function (): void {
    Route::middleware('throttle:ultron-studio-read')->group(function (): void {
        Route::get('status', [UltronStudioController::class, 'status']);
        Route::get('catalog', [UltronStudioController::class, 'catalog']);
        Route::get('draft', [UltronStudioController::class, 'draft']);
        Route::get('versions', [UltronStudioVersionController::class, 'index']);
        Route::get('versions/{uuid}', [UltronStudioVersionController::class, 'show'])->whereUuid('uuid');
        Route::post('versions/{uuid}/validate', [UltronStudioVersionController::class, 'simulate'])->whereUuid('uuid');
        Route::get('rules', [UltronStudioRuleController::class, 'index']);
        Route::get('audit', [UltronStudioController::class, 'audit']);
        Route::get('engine/readings', [UltronStudioController::class, 'engineReadings']);
        Route::get('compare', [UltronStudioVersionController::class, 'compare']);
    });

    Route::middleware('throttle:ultron-studio-write')->group(function (): void {
        Route::put('draft', [UltronStudioController::class, 'saveDraft']);
        Route::post('draft/discard', [UltronStudioController::class, 'discardDraft']);
        Route::post('versions', [UltronStudioVersionController::class, 'store']);
        Route::post('versions/{uuid}/submit', [UltronStudioVersionController::class, 'submit'])->whereUuid('uuid');
        Route::post('versions/{uuid}/approve', [UltronStudioVersionController::class, 'approve'])->whereUuid('uuid');
        Route::post('versions/{uuid}/reject', [UltronStudioVersionController::class, 'reject'])->whereUuid('uuid');
        Route::post('versions/{uuid}/publish', [UltronStudioVersionController::class, 'publish'])->whereUuid('uuid');
        Route::post('versions/{uuid}/rollback', [UltronStudioVersionController::class, 'rollback'])->whereUuid('uuid');
        Route::post('rules', [UltronStudioRuleController::class, 'store']);
        Route::patch('rules/{uuid}', [UltronStudioRuleController::class, 'update'])->whereUuid('uuid');
        Route::post('rules/{uuid}/archive', [UltronStudioRuleController::class, 'archive'])->whereUuid('uuid');
    });
});
