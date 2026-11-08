<?php

use App\Http\Controllers\Crm\UltronStudio\UltronStudioCampaignLinkController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioCanaryController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioIntegrationController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioLabController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioLabScenarioController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioPublicationController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioRuleController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioVersionController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioVoiceController;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioVoiceLabController;
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
| su propio limitador `ultron-studio-lab-run` (10/h por persona, 30/h por
| red). El cuerpo más grande del laboratorio (un escenario) cabe de sobra en los
| 64 KB: su contenido codificado no pasa de 16 KB.
|
| Hito 5 (docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md §A7): publicar,
| promover, revertir y volver a Legacy DE VERDAD sobre la cadena de
| publicaciones, más la vista previa, la evidencia del canario y el historial:
| 5 rutas más (38 en total). Cerraduras: la puerta (`ultron.view`,
| `ultron.publish` o `ultron.rollback`, una a una en AuthorizationMap) → una
| persona → la llave → las llaves de entorno (solo publicar y promover). La
| vista previa y el historial no escriben: van con las lecturas. La evidencia
| es la lectura más cara (cuenta los turnos del canario y ejecuta el acta):
| además del cupo de lectura, su propio limitador `ultron-studio-evidence`
| (10/min por persona, 30/min por red).
|
| Hito 6 (docs/ultron-studio/ULTRON_STUDIO_H6_CONTRACT.md §I7.1): las
| INTEGRACIONES COMERCIALES, `integrations/*`, 18 rutas más (56 en total):
| Meta Ads leído del espejo local, el registro de números canarios, Twilio, la
| voz y la configuración autorizada. Es REGISTRO del Studio: nada cambia lo que
| ULTRON dice o hace. Cerraduras: la puerta (`ultron.view`,
| `ultron.campaigns_manage`, `ultron.canary_manage`, `ultron.canary_authorize` o
| `ultron.integrations_manage`, una a una en AuthorizationMap) → una persona →
| la llave → la bandera de escrituras (409 `integrations_writes_disabled` en
| 47–54; reconocer la configuración no depende de ella). CERO RED POR DEFECTO:
| la única que puede salir es la sonda GET de Twilio, con su bandera
| (`ULTRON_STUDIO_TWILIO_PROBE_ENABLED`), además del cupo de escritura, su
| propio limitador `ultron-studio-probe` (6/h por persona, 20/h por red) y un
| enfriamiento de 300 s.
|
| Hito 8 (docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md, «API»): el
| GOBIERNO DE LA VOZ, `voice/*`, 15 rutas, y el LABORATORIO HABLADO,
| `voice-lab/*`, 6 rutas del paquete LAB8 (77 en total). La lista con su
| permiso, una a una, es `UltronStudioVoiceController::ROUTES` y `::LAB_ROUTES`
| (E2-6: las guardas leen el recuento de ahí, no de un literal). Cerraduras:
| la puerta (`ultron.view`, `ultron.voice_manage`, `ultron.approve`,
| `ultron.voice_publish` o `ultron.voice_lab_run`, una a una en
| AuthorizationMap) → una persona → la llave → la bandera (publicar y promover:
| 409 `voice_publishing_disabled`; el laboratorio hablado: 409
| `voice_lab_disabled`, salvo `GET voice-lab/status`). CERO RED POR DEFECTO: las
| lecturas y `POST voice/compile-preview` (con las lecturas: no escribe) nunca
| salen a la red. Publicar y promover despliegan y miden la evidencia: además
| del cupo de escritura, el limitador de la evidencia (`ultron-studio-evidence`,
| 10/min por persona), como promover en el H5. Abrir una sesión del laboratorio
| hablado gasta: además del cupo de escritura, `ultron-studio-lab-run` (10/h
| por persona, 30/h por red), el del laboratorio de texto del H4.
|
| Tope de CUERPO de 64 KB (65 536 bytes) en las 77 rutas: por encima, 413
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

        // Hito 5: la vista previa, la evidencia del canario (con su limitador) y el historial.
        Route::get('versions/{uuid}/publication-preview', [UltronStudioVersionController::class, 'preview'])->whereUuid('uuid');
        Route::get('versions/{uuid}/canary-evidence', [UltronStudioVersionController::class, 'evidence'])->whereUuid('uuid')->middleware('throttle:ultron-studio-evidence');
        Route::get('publications', [UltronStudioPublicationController::class, 'index']);

        // Hito 6: las integraciones comerciales (lecturas, sin red).
        Route::get('integrations/status', [UltronStudioIntegrationController::class, 'status']);
        Route::get('integrations/meta/campaigns', [UltronStudioCampaignLinkController::class, 'campaigns']);
        Route::get('integrations/meta/links', [UltronStudioCampaignLinkController::class, 'links']);
        Route::get('integrations/canaries', [UltronStudioCanaryController::class, 'index']);
        Route::get('integrations/twilio', [UltronStudioIntegrationController::class, 'twilio']);
        Route::get('integrations/twilio/calls', [UltronStudioIntegrationController::class, 'calls']);
        Route::get('integrations/voice', [UltronStudioIntegrationController::class, 'voice']);
        Route::get('integrations/config', [UltronStudioIntegrationController::class, 'config']);

        // Hito 8: el gobierno de la voz (lecturas, sin red; la previsualización
        // compila sin escribir) y el laboratorio hablado (lecturas).
        Route::get('voice/profiles', [UltronStudioVoiceController::class, 'profiles']);
        Route::get('voice/profiles/{uuid}', [UltronStudioVoiceController::class, 'profile'])->whereUuid('uuid');
        Route::post('voice/compile-preview', [UltronStudioVoiceController::class, 'preview']);
        Route::get('voice/publications', [UltronStudioVoiceController::class, 'publications']);
        Route::get('voice/canary-evidence', [UltronStudioVoiceController::class, 'evidence'])->middleware('throttle:ultron-studio-evidence');
        Route::get('voice/brakes', [UltronStudioVoiceController::class, 'brakes']);
        Route::get('voice-lab/status', [UltronStudioVoiceLabController::class, 'status']);
        Route::get('voice-lab/sessions', [UltronStudioVoiceLabController::class, 'index']);
        Route::get('voice-lab/sessions/{uuid}', [UltronStudioVoiceLabController::class, 'show'])->whereUuid('uuid');
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

        // Hito 5: promover y volver a Legacy (publicar y revertir, arriba). Promover calcula la evidencia entera
        // (acta incluida), así que comparte el cubo de la evidencia: 10/min por persona (AS-2 de la revisión de la API).
        Route::post('versions/{uuid}/promote', [UltronStudioVersionController::class, 'promote'])->whereUuid('uuid')->middleware('throttle:ultron-studio-evidence');
        Route::post('publications/legacy', [UltronStudioPublicationController::class, 'legacy']);

        // Hito 6: asociaciones de campaña, números canarios, configuración
        // autorizada y la sonda de solo lectura de Twilio (con su limitador).
        Route::post('integrations/meta/links', [UltronStudioCampaignLinkController::class, 'store']);
        Route::post('integrations/meta/links/{uuid}/deactivate', [UltronStudioCampaignLinkController::class, 'deactivate'])->whereUuid('uuid');
        Route::post('integrations/canaries', [UltronStudioCanaryController::class, 'store']);
        Route::post('integrations/canaries/{uuid}/authorize', [UltronStudioCanaryController::class, 'authorize'])->whereUuid('uuid');
        Route::post('integrations/canaries/{uuid}/activate', [UltronStudioCanaryController::class, 'activate'])->whereUuid('uuid');
        Route::post('integrations/canaries/{uuid}/deactivate', [UltronStudioCanaryController::class, 'deactivate'])->whereUuid('uuid');
        Route::post('integrations/canaries/{uuid}/revoke', [UltronStudioCanaryController::class, 'revoke'])->whereUuid('uuid');
        Route::post('integrations/canaries/{uuid}/expectation', [UltronStudioCanaryController::class, 'expectation'])->whereUuid('uuid');
        Route::post('integrations/config/acknowledge', [UltronStudioIntegrationController::class, 'acknowledge']);
        Route::post('integrations/twilio/probe', [UltronStudioIntegrationController::class, 'probe'])->middleware('throttle:ultron-studio-probe');

        // Hito 8: perfiles de voz, la cadena de voz, el freno y el laboratorio
        // hablado. Publicar y promover despliegan y miden: con el limitador de
        // la evidencia. Abrir una sesión del laboratorio gasta: con el suyo.
        Route::post('voice/profiles', [UltronStudioVoiceController::class, 'storeProfile']);
        Route::post('voice/profiles/{uuid}/submit', [UltronStudioVoiceController::class, 'submitProfile'])->whereUuid('uuid');
        Route::post('voice/profiles/{uuid}/approve', [UltronStudioVoiceController::class, 'approveProfile'])->whereUuid('uuid');
        Route::post('voice/profiles/{uuid}/reject', [UltronStudioVoiceController::class, 'rejectProfile'])->whereUuid('uuid');
        Route::post('voice/publish', [UltronStudioVoiceController::class, 'publish'])->middleware('throttle:ultron-studio-evidence');
        Route::post('voice/promote', [UltronStudioVoiceController::class, 'promote'])->middleware('throttle:ultron-studio-evidence');
        Route::post('voice/rollback', [UltronStudioVoiceController::class, 'rollback']);
        Route::post('voice/off', [UltronStudioVoiceController::class, 'off']);
        Route::post('voice/brakes/{uuid}/release', [UltronStudioVoiceController::class, 'releaseBrake'])->whereUuid('uuid');
        Route::post('voice-lab/sessions', [UltronStudioVoiceLabController::class, 'store'])->middleware('throttle:ultron-studio-lab-run');
        Route::post('voice-lab/sessions/{uuid}/end', [UltronStudioVoiceLabController::class, 'end'])->whereUuid('uuid');
        Route::post('voice-lab/sessions/{uuid}/verdict', [UltronStudioVoiceLabController::class, 'verdict'])->whereUuid('uuid');
    });
});
