<?php

use App\Http\Controllers\Crm\UltronStudio\UltronStudioController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioLabController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioLabScenarioController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioRuleController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioVersionController;
use App\Http\Middleware\LimitRequestBodySize;
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
|
| Hito 4 (docs/ultron-studio/ULTRON_STUDIO_H4_CONTRACT.md §A7): el LABORATORIO
| de IA, `lab/*`, 12 rutas más (33 en total). Es una SIMULACIÓN DEL STUDIO, no
| ULTRON. Cerraduras: la puerta (`ultron.view`, `ultron.lab_manage`,
| `ultron.lab_run` o `ultron.approve`, una a una en AuthorizationMap) → una
| persona → la llave → la bandera (409 `lab_disabled`, salvo `GET lab/status`,
| que responde 200 también apagado). `POST lab/estimate` no escribe: va con las
| lecturas. `POST lab/runs` gasta presupuesto: además del cupo de escritura,
| su propio limitador `ultron-studio-lab-run` (10/h por credencial, 30/h por
| red). El cuerpo más grande del laboratorio (un escenario) cabe de sobra en los
| 64 KB: su contenido codificado no pasa de 16 KB.
|
| Tope de CUERPO de 64 KB (65 536 bytes) en las 33 rutas: por encima, 413
| `payload_too_large` antes del controlador (revisión de seguridad de la API
| del Hito G, M-2). Es defensa en profundidad: la validación en dos fases de
| los controladores ya rechaza una lista enorme sin recorrerla. Por qué 64 KB:
| es el tope del contenido del borrador (`StudioDraftNormalizer::MAX_BYTES`),
| el cuerpo más grande del Studio. El borrador más grande que admite el
| normalizador (20 etiquetas de 60 caracteres de 4 bytes en cada lista,
| todas las aristas, coordenadas y vista extremas) ocupa 11 061 bytes tal como
| lo envía el CRM (UTF-8 sin escapar) y 30 261 con todo el Unicode escapado.
| Con caracteres de 4 bytes en todos los textos, el CRM envía como mucho
| 38 052 bytes en `POST rules` (condiciones de 4 KB, descripción entera, todas
| las referencias y 30 claves de 255 caracteres) y 33 436 en `approve` (100
| avisos de 80). Producción admite hasta 8 MB (`post_max_size`).
*/

Route::prefix('admin/ultron-studio')->middleware(LimitRequestBodySize::class.':65536')->group(function (): void {
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

        // Hito 4: el laboratorio (lecturas y la estimación, que no escribe).
        Route::get('lab/status', [UltronStudioLabController::class, 'status']);
        Route::get('lab/scenarios', [UltronStudioLabScenarioController::class, 'index']);
        Route::get('lab/runs', [UltronStudioLabController::class, 'runs']);
        Route::get('lab/runs/{uuid}', [UltronStudioLabController::class, 'show'])->whereUuid('uuid');
        Route::post('lab/estimate', [UltronStudioLabController::class, 'estimate']);
        Route::get('lab/events', [UltronStudioLabController::class, 'events']);
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

        // Hito 4: el laboratorio (escenarios, ejecuciones y veredicto).
        Route::post('lab/scenarios', [UltronStudioLabScenarioController::class, 'store']);
        Route::patch('lab/scenarios/{uuid}', [UltronStudioLabScenarioController::class, 'update'])->whereUuid('uuid');
        Route::post('lab/scenarios/{uuid}/archive', [UltronStudioLabScenarioController::class, 'archive'])->whereUuid('uuid');
        Route::post('lab/runs', [UltronStudioLabController::class, 'store'])->middleware('throttle:ultron-studio-lab-run');
        Route::post('lab/runs/{uuid}/cancel', [UltronStudioLabController::class, 'cancel'])->whereUuid('uuid');
        Route::post('lab/runs/{uuid}/verdict', [UltronStudioLabController::class, 'verdict'])->whereUuid('uuid');
    });
});
