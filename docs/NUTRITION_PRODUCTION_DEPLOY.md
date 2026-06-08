# Nutrición — Despliegue en producción (VPS)

Checklist y referencia para desplegar el módulo Nutrición. No toca pagos,
membresías, auth, ePayco, Nequi ni IA Live. **Nunca commitear `.env` ni secretos.**

## 1. Variables .env necesarias (sin valores secretos)

```env
# Búsqueda externa
NUTRITION_EXTERNAL_SEARCH_ENABLED=true
NUTRITION_OPENFOODFACTS_ENABLED=true
NUTRITION_OFF_USER_AGENT="IronBodyNeiva/1.0 (soporte@ironbodyneiva.cloud)"
NUTRITION_OFF_COUNTRY=colombia
NUTRITION_OFF_LANGUAGE=es

# USDA (key SOLO backend; no exponer en Flutter)
NUTRITION_USDA_ENABLED=true
NUTRITION_USDA_API_KEY=__defínela_en_el_servidor__

# Cobertura Colombia
NUTRITION_OFF_COLOMBIA_ENABLED=true
NUTRITION_OFF_IMPORT_COLOMBIA_PRIORITY=true
# (retailers / brand_seeds / barcode_prefixes tienen defaults; ver COLOMBIA_COVERAGE)

# OCR (Tesseract en el VPS)
NUTRITION_OCR_ENABLED=true
NUTRITION_OCR_PROVIDER=tesseract
NUTRITION_OCR_TESSERACT_BIN=/usr/bin/tesseract
NUTRITION_OCR_LANG=spa+eng
NUTRITION_OCR_MAX_IMAGE_MB=8

# Comunidad
NUTRITION_COMMUNITY_REPORTS_HIDE_THRESHOLD=3
NUTRITION_COMMUNITY_IDEMPOTENCY_WINDOW=15

# Metas (adherencia/constancia) — aún no hay meta por usuario
NUTRITION_GOAL_CALORIES=2200
NUTRITION_GOAL_PROTEIN=150
NUTRITION_GOAL_CARBS=250
NUTRITION_GOAL_FAT=70
NUTRITION_GOAL_TOLERANCE=0.10

# IA de Nutrición (OpenAI) — la key es OPENAI_API_KEY (ya existente), no nueva
NUTRITION_AI_ENABLED=false           # poner true para activar la asistencia IA
NUTRITION_AI_PROVIDER=openai
NUTRITION_AI_TIMEOUT_SECONDS=30
NUTRITION_AI_MAX_IMAGE_MB=6
NUTRITION_AI_RATE_LIMIT_PER_USER=20
NUTRITION_AI_DAILY_COST_GUARD=1000
NUTRITION_AI_CACHE_ENABLED=true
NUTRITION_AI_PROMPT_VERSION=v1
```

### IA de Nutrición

- Activar/desactivar: `NUTRITION_AI_ENABLED` (true/false) + `php artisan config:cache`.
  Con `false`, todos los endpoints IA responden `ai_unavailable` controlado (la
  app degrada a manual). No requiere key nueva: reutiliza `OPENAI_API_KEY`.
- Verificar conectividad OpenAI desde el VPS:
  `curl -s -o /dev/null -w "%{http_code}\n" https://api.openai.com/v1/models -H "Authorization: Bearer $OPENAI_API_KEY"`
- Logs: `nutrition:ai:ok|rate_limited|timeout|http_error|cache_hit`.
- Fallos: si OpenAI cae (timeout/429/500), el flujo NO rompe — devuelve estado
  controlado y la app ofrece completar manualmente. Cost guard y rate-limit
  protegen el gasto.
- Migración nueva: `2026_06_08_000004_create_nutrition_ai_runs_table` (auditoría).
- Rollback IA: `NUTRITION_AI_ENABLED=false` (apaga sin desplegar) y/o
  `php artisan migrate:rollback --step=1` para revertir la tabla de auditoría.
- Privacidad: no se envían datos personales a OpenAI; no se persisten imágenes.
  Detalle en `NUTRITION_AI.md`.

## 2. Migraciones

Nuevas (idempotentes, reversibles, sin perder datos):

- `2026_06_08_000001_add_provider_barcode_to_nutrition_ocr_scans`
- `2026_06_08_000002_add_colombia_fields_to_nutrition_foods`
- `2026_06_08_000003_add_community_fields_to_nutrition_foods`

Verifica antes de aplicar:

```bash
php artisan migrate --pretend
php artisan migrate --force
```

## 3. nginx y PHP (evitar 413 al subir fotos OCR)

nginx (`server { ... }`):

```nginx
client_max_body_size 16M;
```

PHP (`php.ini` de PHP-FPM):

```ini
upload_max_filesize = 16M
post_max_size = 16M
```

Aplicar:

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl reload php8.3-fpm   # ajusta versión
php -i | grep -E "upload_max_filesize|post_max_size"
```

Detalle en `NUTRITION_OCR_DEPLOY.md`.

## 4. Tesseract (OCR)

```bash
sudo apt update
sudo apt install -y tesseract-ocr tesseract-ocr-spa tesseract-ocr-eng imagemagick
tesseract --version && tesseract --list-langs
```

## 5. Checklist de despliegue

```bash
# Backend
git pull                       # rama de producción
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan queue:restart      # si se usan colas

# Infra
# nginx client_max_body_size 16M + PHP 16M (ver arriba) → reload nginx/php-fpm

# Datos Colombia (ver NUTRITION_COLOMBIA_COVERAGE.md)
php artisan nutrition:off-import --stats --country=colombia
php artisan nutrition:off-import --file=/ruta/colombia.jsonl --country=colombia --limit=50000
```

### Queues / scheduler

El importador corre por CLI (no en request web). Si se programa, usar el
scheduler de Laravel o un cron dedicado en horario de baja carga. No requiere
colas para funcionar; las soporta si el proyecto las usa.

### Storage / permisos

OCR no persiste imágenes salvo `NUTRITION_OCR_STORE_ORIGINAL=true` (disco
privado). Si se activa, asegurar permisos de `storage/app`.

## 6. Verificación post-deploy

- Buscar por nombre: `doria`, `florhuila`, `alpina`, `colanta`, `d1`, `ara`, `éxito`.
- Escanear un barcode real colombiano (resuelve o permite crear con barcode).
- OCR de una etiqueta real (compresión + lectura o error controlado).
- Abrir el dashboard de **Constancia** (`/api/nutrition/stats`).
- Revisar logs: `nutrition:barcode:*`, `nutrition:ocr:*`, `nutrition:import:off`,
  `nutrition:stats:summary`, `nutrition.food.created`.

## 7. Rollback básico

```bash
git checkout <commit_anterior>
composer install --no-dev --optimize-autoloader
php artisan migrate:rollback --step=3   # revierte las 3 migraciones nuevas
php artisan config:cache && php artisan route:cache
```

Las migraciones son reversibles y no borran alimentos creados por usuarios
(las columnas nuevas son aditivas y nullable).
