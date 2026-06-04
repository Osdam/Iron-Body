# Publicidad y Eventos — API (Bloque 4)

Módulo gestionado desde el CRM (cuando exista el front Angular). Mientras tanto,
estos endpoints son la fuente de verdad. Las imágenes viven en **Firebase
Storage** (`IRONBODYADS/{uuid}/image.jpg`, `IRONBODYEVENTS/{uuid}/image.jpg`) o,
como fallback, en el disco público del backend.

> Imagen: el CRM puede subir el archivo directo a Firebase y enviar
> `image_url` (URL pública) + `image_path` (ruta del objeto, para poder borrarlo),
> **o** mandar el archivo `image` (multipart) y el backend lo guarda en el disco
> público (requiere `php artisan storage:link`).

Las rutas `admin/*` siguen el patrón del CRM (sin auth a nivel de ruta; el acceso
se controla en el CRM). Las rutas de miembro usan el bearer del dispositivo.

## Publicidad (campañas del Home)

### Miembro
- `GET /api/member/ads/active` — anuncios vigentes que toca mostrar (el backend
  aplica ventana de fechas y frecuencia por miembro). La app muestra el de mayor
  `priority` como modal premium.
- `POST /api/member/ads/{id}/seen` — marca el anuncio como visto.

### Admin (CRM)
- `GET    /api/admin/ads`
- `POST   /api/admin/ads`
- `PATCH  /api/admin/ads/{id}` (o `POST` con `_method=PATCH` para multipart)
- `DELETE /api/admin/ads/{id}`
- `POST   /api/admin/ads/{id}/activate`
- `POST   /api/admin/ads/{id}/deactivate`

Campos: `title`*, `description`, `image_url`* (o `image` archivo), `image_path`,
`target_url`, `placement` (default `home`), `frequency_rule` (`once`|`daily`|`always`,
default `once`), `priority` (int), `starts_at`, `ends_at`, `is_active`, `created_by`.

```bash
# Crear con imagen ya subida a Firebase
curl -X POST https://API/api/admin/ads \
  -H "Content-Type: application/json" \
  -d '{"title":"Black Friday","image_url":"https://.../IRONBODYADS/uuid/image.jpg",
       "image_path":"IRONBODYADS/uuid/image.jpg","frequency_rule":"daily",
       "target_url":"https://ironbody.co/promo","priority":10,
       "starts_at":"2026-06-10 00:00:00","ends_at":"2026-06-15 23:59:59"}'

# Crear subiendo archivo (fallback disco público)
curl -X POST https://API/api/admin/ads -F "title=Promo" -F "image=@/ruta/banner.jpg"

# Desactivar
curl -X POST https://API/api/admin/ads/5/deactivate
```

## Eventos

### Miembro
- `GET /api/member/events` — eventos vigentes (activos y no terminados).
- `GET /api/member/events/{id}` — detalle (404 si está inactivo).

### Admin (CRM)
- `GET/POST /api/admin/events`, `PATCH/DELETE /api/admin/events/{id}`,
  `POST /api/admin/events/{id}/activate|deactivate`.

Campos: `title`*, `description`, `image_url` (o `image` archivo), `image_path`,
`starts_at`, `ends_at`, `location`, `cta_label`, `cta_url`, `is_active`, `created_by`.

```bash
curl -X POST https://API/api/admin/events \
  -H "Content-Type: application/json" \
  -d '{"title":"Clase abierta de boxeo","location":"Sede Norte",
       "starts_at":"2026-06-20 18:00:00","cta_label":"Reservar",
       "cta_url":"https://ironbody.co/eventos/box","image_url":"https://.../e.jpg"}'
```

## Notas
- Las reglas de Firebase Storage deben permitir lectura de `IRONBODYADS/**` e
  `IRONBODYEVENTS/**` a usuarios autenticados (o lectura pública si las URLs son
  públicas). El borrado server-side usa el service account (FirebaseStorageService).
- Para el fallback de disco público: `php artisan storage:link` una vez.
