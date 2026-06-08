# Nutrición — Capa de IA (OpenAI)

IA de **asistencia** para el módulo Nutrición. Extrae, estructura, normaliza,
estima (marcado) y genera insights/sugerencias. **OpenAI NO es la fuente
certificada de verdad**: nunca marca datos como verificados ni sobreescribe lo
verificado por Iron Body.

## Arquitectura

`Flutter → Laravel → OpenAI`. La API key vive SOLO en el backend
(`OPENAI_API_KEY`, reutilizada de IRON IA vía `config('services.openai')`). La
capa es independiente de IA Live (no la toca).

Servicios (`app/Services/Nutrition/Ai`):

- `NutritionAiClient` — HTTP a OpenAI chat-completions (JSON mode), timeout,
  mapeo de errores (`ai_unavailable`/`timeout`/`rate_limited`/`http_error`).
- `NutritionAIEnrichmentService` — motor: interruptor → cost guard → caché por
  hash → llamada → auditoría (`nutrition_ai_runs`).
- `NutritionAiResponseValidator` — valida/normaliza contra schema (anti-corrupción).
- `NutritionAiHashCache` — caché por hash de entrada (no re-llama con lo mismo).
- `NutritionAiCostGuard` — tope diario global + por usuario.
- `NutritionAiPrompts` — prompts versionados (label/text/estimate/insights/admin).
- `NutritionDataConfidenceService` — etiqueta confianza (alta/media/baja) + umbrales.
- Flujos: `NutritionAIVisionLabelExtractor`, `NutritionAITextParser`,
  `NutritionAIEstimator`, `NutritionAIInsightService`, `NutritionAIAdminReviewService`.

## Cuándo se usa OpenAI / cuándo NO

Se usa cuando: el usuario sube foto de etiqueta (visión), pide estructurar texto
OCR, pide estimar un plato sin etiqueta, o ve insights de constancia; y staff
pide revisar un alimento.

NO se usa cuando: `nutrition.ai.enabled=false`; se supera el cost guard /
rate-limit; hay un resultado en caché por hash; o no hay registros suficientes
para insights (devuelve mensaje educativo, sin llamar a la IA).

## Reglas anti-alucinación

- Prompts exigen: **faltante = null, nunca 0**; no inventar; distinguir
  total/saturada/trans y azúcares totales/añadidos.
- El **validador** rechaza JSON inválido, negativos y valores físicamente
  imposibles (p.ej. proteína > 100 g/100 g) → `validation_failed` (no se guarda).
- Incoherencia calórica (4·prot+4·carb+9·grasa vs kcal) → warning.
- El usuario **siempre confirma/edita** antes de guardar (la IA no persiste
  alimentos; solo devuelve borradores).

## Fuentes de verdad y badges

Prioridad: Iron Body verified → externos confiables → comunidad confirmada →
Open Food Facts Colombia → OFF general → USDA genéricos → incompletos.

Badges: `Verificado Iron Body`, `Aportado por la comunidad`, `Colombia`,
`Open Food Facts`, `USDA`, `Extraído por IA`, `Estimado por IA · No verificado`,
`Datos incompletos`. La IA produce SIEMPRE `verification_status: unverified`.

## Confidence score

`confidence_score` 0..1 por respuesta + `field_confidence` por campo. Umbrales:
`min_confidence_autofill` (autorrellenar) y `min_confidence_estimate`
(estimación aceptable). `confidence_label`: alta ≥0.80, media ≥0.60, baja.

## Flujos

1. **label-image** (`POST /api/nutrition/ai/label-image`): imagen comprimida +
   barcode opcional → JSON nutricional estructurado (per_100g/per_serving,
   porción, porciones por envase, extras). `source: ai_label_extraction`.
2. **parse-text** (`POST /api/nutrition/ai/parse-text`): texto OCR → JSON.
   `source: ai_text_extraction`.
3. **estimate** (`POST /api/nutrition/ai/estimate`): descripción + cantidad →
   estimación `ai_estimated`, privada, no verificada, sin barcode, editable.
4. **enriquecimiento**: sugerencias para productos incompletos/comunidad (vía
   admin-review y/o extracción) — siempre como sugerencia.
5. **insights** (`GET /api/nutrition/ai/insights?range=week|month`): coach
   fitness (no médico) sobre métricas reales; degrada a insights deterministas
   si la IA no está; mensaje educativo si no hay datos.
6. **admin review** (`POST /api/admin/nutrition/foods/{uuid}/ai-review`):
   sugiere duplicado/estado/categoría/calidad. Staff decide; sin merge/verify
   automático.

## Privacidad

- Solo se envían métricas/textos nutricionales necesarios. **No** se envía
  nombre completo, teléfono, correo ni datos sensibles.
- Las imágenes se envían inline (base64) para el análisis y **no se persisten**.
- `nutrition_ai_runs` guarda hash de entrada + JSON estructurado + estado; **no**
  guarda imágenes ni prompts gigantes. Logs sin secretos (`nutrition:ai:*`).

## Costos / rate limits

- `daily_cost_guard` (tope global de llamadas/día) y `rate_limit_per_user`
  (tope por usuario/día) — proxy de costo, configurable.
- Throttle de rutas (Laravel) además del guard.
- Caché por hash evita re-llamadas con la misma imagen/texto.

## Variables .env

```env
NUTRITION_AI_ENABLED=false
NUTRITION_AI_PROVIDER=openai
NUTRITION_AI_MODEL_LABEL_IMAGE=
NUTRITION_AI_MODEL_TEXT=
NUTRITION_AI_MODEL_ESTIMATE=
NUTRITION_AI_MODEL_INSIGHTS=
NUTRITION_AI_MODEL_ADMIN=
NUTRITION_AI_TIMEOUT_SECONDS=30
NUTRITION_AI_MAX_IMAGE_MB=6
NUTRITION_AI_RATE_LIMIT_PER_USER=20
NUTRITION_AI_DAILY_COST_GUARD=1000
NUTRITION_AI_CACHE_ENABLED=true
NUTRITION_AI_MIN_CONFIDENCE_AUTOFILL=0.70
NUTRITION_AI_MIN_CONFIDENCE_ESTIMATE=0.60
NUTRITION_AI_PROMPT_VERSION=v1
```

La key real (`OPENAI_API_KEY`) y modelos por defecto se configuran aparte; si
los `MODEL_*` quedan vacíos, se usa `services.openai.vision_model`/`model`.
Modelos sin diagnóstico médico ni promesas de salud (lo impone el prompt).
