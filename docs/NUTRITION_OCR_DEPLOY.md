# OCR Nutrición — Despliegue en VPS (Tesseract + límites de subida)

OCR real de etiquetas nutricionales con **Tesseract**. Este documento cubre la
instalación del motor y, sobre todo, **cómo evitar el error 413 (Request Entity
Too Large)** que devuelve nginx cuando la imagen subida supera su límite.

---

## 1. Causa del 413 y defensa en 3 capas

El `413 Request Entity Too Large` lo devuelve **nginx ANTES de llegar a Laravel**
cuando el cuerpo del request (la imagen OCR) supera `client_max_body_size`. Por
eso aparecía HTML de nginx en vez de JSON.

Se corrige en tres capas:

1. **Flutter (cliente):** comprime la imagen antes de subir → JPEG, máx 1600 px
   de ancho, calidad ~75%, objetivo < 1.5 MB, **límite duro 6 MB**. Si tras
   comprimir sigue pesada, no sube y pide una foto más cercana. (Ver
   `lib/features/nutrition/services/nutrition_image_compressor.dart`.)
2. **Laravel (app):** valida el tamaño contra `NUTRITION_OCR_MAX_IMAGE_MB` y, si
   se supera, responde **JSON 422** `{ok:false, code:"ocr_image_too_large"}`.
   Además, `PostTooLargeException` se renderiza como **JSON** en rutas `api/*`
   (nunca HTML ni stack trace). (Ver `bootstrap/app.php`.)
3. **VPS (nginx + PHP):** sube los límites para que un JPEG comprimido normal
   (< 6 MB) nunca sea rechazado por infraestructura.

> El límite de nginx debe ser **mayor** que el límite de la app, para que el
> rechazo lo haga Laravel (JSON controlado) y no nginx (HTML 413).

---

## 2. nginx

En el `server { ... }` del sitio (p.ej. `/etc/nginx/sites-available/ironbody`):

```nginx
client_max_body_size 16M;
```

Aplicar:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

---

## 3. PHP

En el `php.ini` que use PHP-FPM (verifícalo con `php --ini`):

```ini
upload_max_filesize = 16M
post_max_size = 16M
```

Reiniciar PHP-FPM y verificar:

```bash
sudo systemctl reload php8.3-fpm   # ajusta la versión
php -i | grep -E "upload_max_filesize|post_max_size"
```

---

## 4. Laravel (.env)

```env
NUTRITION_OCR_ENABLED=true
NUTRITION_OCR_PROVIDER=tesseract
NUTRITION_OCR_TESSERACT_BIN=/usr/bin/tesseract
NUTRITION_OCR_LANG=spa+eng
NUTRITION_OCR_TIMEOUT_SECONDS=20
NUTRITION_OCR_MAX_IMAGE_MB=8
NUTRITION_OCR_STORE_ORIGINAL=false
NUTRITION_OCR_REQUIRE_USER_CONFIRMATION=true
```

`NUTRITION_OCR_MAX_IMAGE_MB` (8) debe ser **menor** que los 16M de nginx/PHP.

Aplicar configuración:

```bash
php artisan config:clear && php artisan config:cache
```

---

## 5. Instalar Tesseract (si no está)

```bash
sudo apt update
sudo apt install -y tesseract-ocr tesseract-ocr-spa tesseract-ocr-eng imagemagick
which tesseract && tesseract --version && tesseract --list-langs
```

---

## 6. Verificación rápida

- Subir una foto grande desde la app → debe comprimirse y subir sin 413.
- Forzar una imagen > 8 MB (sin comprimir) → la app muestra
  “La imagen es muy pesada…”, y si llegara al backend, responde JSON
  `{code:"ocr_image_too_large"}` (422) o, si supera nginx, JSON 413 — nunca HTML.
- Logs del cliente: `nutrition:ocr:image:original`, `…:compressed`,
  `nutrition:ocr:upload:start|success|error`.
