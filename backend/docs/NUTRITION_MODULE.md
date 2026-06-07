# Módulo Nutricional Premium (búsqueda / barcode / OCR / tracking)

Sistema de tracking nutricional tipo MyFitnessPal/Yazio con diseño Iron Body.
Es un módulo **NUEVO e independiente** del nutricional previo (`app/nutrition/*`,
tablas `nutrition_food_items`/`nutrition_meal_*`): no se tocó nada de aquello.

## Arquitectura

```
Flutter (NutritionScreen → FoodTrackingScreen)
   │  (bearer del miembro; NUNCA llama a proveedores externos)
   ▼
Laravel  /api/nutrition/*  (auth.member, rate-limit)
   ├─ NutritionFoodSearchService     → BD local + proveedores (cachea)
   ├─ NutritionBarcodeService        → BD local + Open Food Facts (cachea)
   ├─ NutritionMacroCalculator       → macros FINALES por cantidad/unidad
   ├─ NutritionEntryService          → entradas + resumen diario
   ├─ NutritionOcrService            → OCR seguro (draft → confirmación)
   └─ NutritionFoodNormalizer        → normaliza/cachea proveedores
          ├─ OpenFoodFactsNutritionProvider (sin key)
          ├─ UsdaFoodDataProvider           (key solo backend)
          └─ NutritionixProvider            (adapter comercial futuro)
```

**Regla central:** el backend calcula SIEMPRE los macros finales; Flutter solo
presenta (y estima un preview local). La app nunca ve API keys externas.

## Tablas (migraciones `2026_06_07_*`)

- `nutrition_foods` — catálogo (local/externo cacheado/usuario/OCR), macros
  por 100g y por porción, `source`, `barcode`, `confidence_score`, softDeletes.
- `nutrition_entries` — alimento agregado a una comida/fecha (macros finales).
- `nutrition_favorites` — favoritos del miembro (único member+food).
- `nutrition_recent_foods` — recientes (use_count, last_used_at).
- `nutrition_daily_summaries` — totales por día (único member+fecha).
- `nutrition_ocr_scans` — escaneos OCR (pending/processed/failed, draft).

Modelos: `NutritionFood`, `NutritionEntry`, `NutritionFavorite`,
`NutritionRecentFood`, `NutritionDailySummary`, `NutritionOcrScan`.
(Nota: "food" es incontable en el inflector → tablas fijadas con `$table`.)

## Endpoints (todos bajo `auth.member`)

| Método | Ruta | Rate | Descripción |
|---|---|---|---|
| GET | `/api/nutrition/foods/search?q=` | 30/min | Buscar por nombre/marca. |
| GET | `/api/nutrition/foods/barcode/{barcode}` | 20/min | EAN/UPC (8-14 díg). |
| GET | `/api/nutrition/foods/{uuid}` | — | Detalle. |
| POST | `/api/nutrition/foods` | 30/min | Crear manual (privado). |
| PUT/DELETE | `/api/nutrition/foods/{uuid}` | — | Editar/borrar (propios). |
| POST/DELETE | `/api/nutrition/foods/{uuid}/favorite` | — | Favorito. |
| GET | `/api/nutrition/favorites` · `/recent` | — | Listas. |
| POST | `/api/nutrition/entries` | 60/min | Agregar a comida. |
| GET | `/api/nutrition/entries?date=` | — | Entradas del día. |
| DELETE | `/api/nutrition/entries/{uuid}` | — | Eliminar entrada. |
| GET | `/api/nutrition/summary?date=` | — | Totales + comidas. |
| POST | `/api/nutrition/ocr/scan` | 10/min | OCR etiqueta (seguro). |
| GET | `/api/nutrition/ocr/{uuid}` | — | Estado del escaneo. |
| POST | `/api/nutrition/ocr/{uuid}/confirm-food` | — | Guardar revisado. |

## Búsqueda

1. BD local primero: alimentos del usuario (prioridad), públicos Iron Body y
   caché externo (match por `normalized_name`/nombre/marca).
2. Si hay pocos resultados (<6) y `external_search_enabled`: Open Food Facts y
   USDA (si habilitado) → normaliza → **cachea en `nutrition_foods`** → combina
   sin duplicados. Errores/timeouts/429 → resultado controlado (no rompe).

## Barcode

Valida 8-14 dígitos → caché local por `barcode` → si no, Open Food Facts
(`/api/v2/product/{barcode}.json`) → normaliza y cachea. Estados: `found` |
`not_found` | `invalid` | `error` (todos JSON 200 controlado).

## Crear alimento

`POST /foods` valida nombre + porción>0 + macros≥0. Se guarda **privado**
(`is_public=false`, `source=user`, `created_by_member_id`). El backend deriva
per_100g desde la porción.

## OCR (modo seguro)

- `NUTRITION_OCR_ENABLED=false` → `{ok:false,status:"unavailable",message}`; la
  app ofrece creación manual.
- Habilitado: guarda imagen en disco privado, crea scan `pending`, extrae texto
  (adapter; sin motor server-side → `failed` controlado, **no inventa**). Si el
  cliente envía `text` (OCR ML Kit), el backend lo parsea → `draft` para revisión.
- `confirm-food` crea el alimento SOLO tras revisión del usuario.

## Macros

`NutritionMacroCalculator` soporta `g/ml`, `serving`, `unit`, `tbsp/tsp/cup` (por
equivalencia en gramos). Nunca negativos, redondeo a 1 decimal. El backend es la
única autoridad: ignora cualquier macro enviado por Flutter.

## Caché de proveedores

Los alimentos externos se persisten en `nutrition_foods` (idempotente por
`source+external_id` o `barcode`), con `last_synced_at`. TTL sugerido
`NUTRITION_CACHE_TTL_DAYS` (re-sync futuro). Evita rate-limits.

## Variables de entorno

```env
NUTRITION_EXTERNAL_SEARCH_ENABLED=true
NUTRITION_OPENFOODFACTS_ENABLED=true
NUTRITION_OPENFOODFACTS_BASE_URL=https://world.openfoodfacts.org
NUTRITION_USDA_ENABLED=false
NUTRITION_USDA_BASE_URL=https://api.nal.usda.gov/fdc/v1
NUTRITION_USDA_API_KEY=
NUTRITION_NUTRITIONIX_ENABLED=false
NUTRITION_NUTRITIONIX_APP_ID=
NUTRITION_NUTRITIONIX_APP_KEY=
NUTRITION_OCR_ENABLED=false
NUTRITION_OCR_PROVIDER=local
NUTRITION_CACHE_TTL_DAYS=90
NUTRITION_SEARCH_TIMEOUT_SECONDS=8
NUTRITION_BARCODE_TIMEOUT_SECONDS=8
```

## Cómo probar

```bash
php artisan test --filter=Nutrition   # 19 tests
php artisan test                      # suite completa
```
App: Nutrición → "Buscar y registrar alimentos" → buscar/escanear/crear →
agregar a comida → ver resumen.

## Seguridad

- `auth.member` en todo; rate-limit por método; timeouts 8s; sin secretos en
  logs (`nutrition.search/barcode.lookup/food.created/entry.created/ocr.scan`).
- API keys SOLO en backend (USDA/Nutritionix). Verificado por test
  `no_external_api_key_exposed`.
- No bloquea si un proveedor externo cae (degradación controlada).

## Limitaciones actuales

- OCR server-side sin motor (adapter listo); usa texto del cliente o queda
  `unavailable`/`failed` controlado.
- Nutritionix/FatSecret: adapter preparado, sin credenciales.
- Sin metas nutricionales por plan todavía (estructura lista).

## Roadmap

1. **Fase 1 (hecha):** búsqueda + barcode + creación manual + tracking + resumen.
2. **Fase 2:** OCR real (Tesseract/visión) server-side.
3. **Fase 3:** metas nutricionales por plan/usuario.
4. **Fase 4:** recomendaciones IA según objetivo (reusa IRON IA).
