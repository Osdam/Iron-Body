# CONTRATO — Servicio local de impresión de recibos (ESC/POS) · v1

> Versión implementada el 17-10-2026 (monorepo `6738bfa` + `b001909`, CRM `c09c58c`). Las notas «tras la revisión» reflejan cambios hechos durante la implementación.

Fuente única de verdad para los tres implementadores (servicio Windows .NET, Laravel, Angular).
Nada de lo que está aquí se cambia sin avisar al lead: los otros dos lo consumen.

## 0. Por qué y qué

La impresión actual (CRM → `window.print()` en un iframe → Chrome → driver POS-58 → raster) falló en
la prueba física: la DIG-58IIK empezó, se cortó, pitó y la cola de Windows quedó vacía. Esa ruta
rasteriza la página entera (58×3276 mm a 203 ppp ≈ 1,25 MB de raster) contra una impresora de
**32 KB de búfer**. La ruta nueva no rasteriza nada: manda **texto + comandos ESC/POS** (≈1–3 KB por
recibo) en modo RAW al spooler de Windows.

Datos del fabricante (ficha oficial Digital POS DIG-58IIK): térmica directa, papel 57/58 mm, 48 mm
imprimibles, **384 puntos/línea**, 90 mm/s, USB + RJ11 (cajón), emulación ESC/POS, **Fuente A 12×24 →
32 col., Fuente B 9×17 → 42 col.**, CHN 24×24 → 16 col. (tiene modo chino: hay que cancelarlo),
búfer de entrada 32 KB, interlineado 3,75 mm, fuente 12 V / 2,6 A. **Sin cortador** en la ficha
(barra de corte manual). No publica tabla de páginas de código ni comandos de estado.

```
CRM (Chrome, https://ironbodyneiva.cloud)
  1. cobra (endpoints de cobro SIN CAMBIOS)
  2. POST /api/admin/receipts                 → recibo (existente)
  3. POST /api/admin/receipts/{uuid}/prints   → intento + agent_ticket (NUEVO campo)
  4. POST http://127.0.0.1:17888/v1/tickets  {ticket}
        SERVICIO LOCAL (Windows, 127.0.0.1 solamente)
        5. POST https://api…/api/receipt-agent/redeem  {ticket, station_key, agent}
              ← líneas del recibo YA MAQUETADAS por el backend
        6. ESC/POS → spooler RAW → USB → DIG-58IIK; sigue el trabajo en la cola
        7. POST https://api…/api/receipt-agent/report  {ticket, outcome}
     ← {state, error_code, message, reported}
  8. si reported=false → PATCH /api/admin/receipts/{uuid}/prints/{attempt} (existente)
```

Principios:
- **El backend decide qué se imprime** (maqueta las líneas desde el `snapshot` inmutable). El navegador
  NO manda texto a imprimir; el servicio NO acepta texto ni bytes de nadie salvo del backend, por TLS.
- **El ticket es la autorización**: 32 bytes aleatorios, un solo uso, caduca, ligado a una estación
  registrada y (en impresión) a un intento `started`. El backend guarda solo su SHA-256.
- **Ninguna pieza toca dinero.** Ni cobros, ni ventas, ni abonos, ni inventario, ni turnos, ni Factus.
- **Aceptado ≠ impreso.** El spooler puede aceptar y la impresora no sacar papel. Se dice
  «Aceptado por el sistema», nunca «Impreso».

## 1. Constantes

| Constante | Valor |
|---|---|
| Puerto del servicio | `17888` (configurable en el servicio; el CRM usa la constante) |
| Base del servicio (CRM) | `http://127.0.0.1:17888` |
| Protocolo | `1` (servicio lo publica; backend exige `>= caja.receipts.agent.min_protocol` = 1; CRM soporta `[1]`) |
| Versión del servicio | `1.0.0` |
| Origen permitido por defecto | `https://ironbodyneiva.cloud` |
| API por defecto | `https://api.ironbodyneiva.cloud` |
| Ticket | 32 bytes aleatorios → base64url sin relleno (43 caracteres `[A-Za-z0-9_-]`) |
| TTL ticket | print 120 s · test 300 s · configure 300 s |
| Ventana de report | 24 h desde el redeem |
| Columnas admitidas | 32 (Fuente A) · 42 (Fuente B) |
| Páginas de código | `cp850` (defecto) · `cp437` · `ascii` |
| Avance final | `feed_lines` 0..8, defecto 4 |
| Corte | `cut` siempre `false` en v1 (la DIG-58IIK no declara cortador) |

## 2. Documento de impresión (lo que el backend entrega al servicio)

```json
{ "receipt_number": "RG-000123" | null,
  "copy_label": "REIMPRESIÓN" | "REINTENTO 2" | null,
  "kind": "original" | "retry" | "reprint" | "test",
  "columns": 32,
  "feed_lines": 4,
  "cut": false,
  "lines": [ { "text": "IRON BODY WORKOUT", "style": "title" },
             { "text": "Recibo              RG-000123", "style": "bold" },
             { "text": "--------------------------------", "style": "normal" } ] }
```

Reglas (el backend las GARANTIZA y el servicio las VERIFICA; si no se cumplen el servicio rechaza
con `invalid_job` — nunca recorta ni "arregla" en silencio):
- `lines`: 1..400 elementos. `style` ∈ `normal | bold | title`.
- `text`: NFC, longitud en puntos de código ≤ `columns`, sin caracteres de control
  (U+0000–U+001F, U+007F–U+009F). Puede ser cadena vacía (línea en blanco).
- `normal` y `bold`: el backend ya alineó con espacios (columnas, importes a la derecha, centrados).
- `title`: el servicio lo CENTRA con la impresora (ESC a 1), doble ALTO, negrita; `text` sin espacios
  de relleno. Ancho máximo = `columns` (doble alto no estrecha la línea).

### 2.1 ESC/POS que genera el servicio (y solo estos bytes de control)

```
Cabecera:  1B 40          ESC @   inicializar
           1C 2E          FS .    cancelar modo chino/kanji (la DIG-58IIK lo trae)
           1B 74 n        ESC t n página de código: cp850→2, cp437→0, ascii→0
           1B 4D f        ESC M f fuente: 32 col→0 (A), 42 col→1 (B)
           1B 32          ESC 2   interlineado por defecto
Por línea: normal: 1B 61 00 · 1B 45 00 · 1D 21 00 · texto · 0A
           bold:   1B 61 00 · 1B 45 01 · 1D 21 00 · texto · 0A
           title:  1B 61 01 · 1B 45 01 · 1D 21 01 · texto · 0A   (luego vuelve a normal)
Final:     1B 61 00 · 1B 45 00 · 1D 21 00 · 1B 64 n (ESC d n = avanzar feed_lines líneas)
           (corte GS V solo si cut=true; en v1 nunca)
```
Nada de raster, nada de imágenes, nada de comandos de página: la longitud del papel es exactamente
la de las líneas + `feed_lines`.

### 2.2 Codificación de texto (servicio)

NFC → por carácter:
- ASCII imprimible 0x20–0x7E: tal cual.
- `cp850`: tabla CP850 (á A0, é 82, í A1, ó A2, ú A3, ñ A4, Ñ A5, ¿ A8, ¡ AD, Á B5, É 90, Í D6, Ó E0,
  Ú E9, ü 81, Ü 9A, · FA, ° F8, etc.).
- `cp437`: tabla CP437; mayúsculas acentuadas que no existen (Á Í Ó Ú) → letra sin tilde.
- `ascii`: quitar diacríticos (NFD), ñ→n, Ñ→N, ¿→?, ¡→!.
- Tipográficos: ‘ ’ → `'`; “ ” → `"`; – — → `-`; … → `...`; NBSP → espacio.
- Cualquier otro → `?`. **Nunca** se emite un byte < 0x20 desde el texto: el texto no puede
  producir comandos.

## 3. API del servicio local (Windows) — `http://127.0.0.1:17888`

### 3.1 Guardas (todas las peticiones, en este orden)
1. Kestrel escucha SOLO `127.0.0.1:<puerto>` (no 0.0.0.0, no IPv6 público). Primera guarda: si la IP
   remota no es loopback → 403 `{ok:false, code:"not_loopback"}` (añadida tras la revisión de seguridad;
   una sección `Kestrel` en la configuración impide arrancar).
2. `Host` debe ser exactamente `127.0.0.1:<puerto>` o `localhost:<puerto>` → si no, 403
   `{ok:false, code:"bad_host"}` (anti DNS-rebinding).
3. Rutas `/v1/*`: `Origin` OBLIGATORIO y EXACTAMENTE igual a uno de `AllowedOrigins` → si falta o no
   coincide, 403 `{ok:false, code:"origin_not_allowed"}` SIN cabeceras CORS. (No se confía solo en
   CORS: la petición se rechaza antes de hacer nada.)
4. `OPTIONS` (preflight) de origen permitido → 204 con `Access-Control-Allow-Origin: <origin>`,
   `Access-Control-Allow-Methods: GET, POST`, `Access-Control-Allow-Headers: Content-Type, X-IronBody-Client`,
   `Access-Control-Max-Age: 600`, `Vary: Origin` y, si viene `Access-Control-Request-Private-Network: true`,
   `Access-Control-Allow-Private-Network: true`. Nunca `Allow-Credentials`.
5. `POST`: `Content-Type: application/json` (si no, 415 `unsupported_media_type`) y cabecera
   `X-IronBody-Client: crm` (si no, 400 `missing_client_header`; obliga a preflight).
6. Cuerpo ≤ 16 KB → si no, 413 `payload_too_large`.
7. Límite: 30 POST/min y 120 GET/min (ventana fija global) → 429 `rate_limited`.
8. Toda respuesta: `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, sin cabecera `Server`.
   Las respuestas a un origen permitido llevan `Access-Control-Allow-Origin: <origin>` y `Vary: Origin`.

### 3.2 Endpoints

`GET /` (sin Origin): `text/plain` — `Iron Body · Servicio de impresión de recibos 1.0.0 · en funcionamiento`.
Sin datos de la estación.

`GET /v1/status` → 200
```json
{ "ok": true, "service": "ironbody-receipt-printer", "version": "1.0.0", "protocol": 1,
  "station_key": "<uuid v4 generado en el primer arranque, estable>",
  "station_name": "Recepción" | null,
  "api_base": "https://api.ironbodyneiva.cloud",
  "printer": { "name": "POS-58" | null, "found": true, "status": "ready|offline|error|paper_out|unknown|not_configured|not_found",
               "driver": "POS-58" | null, "port": "USB001" | null },
  "settings": { "columns": 32, "codepage": "cp850", "feed_lines": 4, "cut": false },
  "queue": { "pending": 0 },
  "last_job": { "at": "ISO-8601", "purpose": "print|test", "receipt_number": "RG-000123" | null,
                "state": "accepted|error", "error_code": null } | null,
  "started_at": "ISO-8601", "now": "ISO-8601" }
```

`GET /v1/printers` → 200 `{ "ok": true, "printers": [ { "name", "driver", "port", "is_default": bool,
"status": "ready|offline|error|paper_out|unknown", "looks_thermal": bool } ] }`
(`looks_thermal`: nombre o driver casa con `/pos|58|thermal|receipt|xp-|dig/i`).

`POST /v1/tickets` body `{ "ticket": "<43 base64url>" }` → **siempre 200** si pasó las guardas y el JSON
es válido (si no: 400 `invalid_body`). El resultado va en el cuerpo:
```json
{ "ok": true | false,
  "purpose": "print" | "test" | "configure" | null,
  "state": "accepted" | "applied" | "error",
  "error_code": null | "<código §6>",
  "message": "texto en español para mostrar al cajero",
  "receipt_number": "RG-000123" | null,
  "copy_label": "REIMPRESIÓN" | null,
  "spool_job_id": 12 | null,
  "printer_name": "POS-58" | null,
  "printed_bytes": 1432 | null,
  "reported": true | false,
  "settings": { … } | null }
```
- `accepted`: el spooler aceptó el trabajo y lo entregó al puerto (salió de la cola sin error).
  NO prueba que el papel saliera.
- `applied`: (configure) ajustes aplicados y guardados.
- `error`: no se imprimió (o no se pudo comprobar). `error_code` dice por qué.
- `reported`: el servicio ya contó el resultado al backend. Si es `false`, el CRM debe cerrar el
  intento con el PATCH existente (solo aplica a `purpose=print`).
- Dos `POST` con el mismo ticket a la vez → el segundo recibe el mismo resultado (sin segunda impresión).
- Cola de UN trabajador; máximo 20 en espera → `queue_full`.

`GET /v1/diagnostics` → 200 `{ "ok": true, "events": [ { "at", "level": "info|warn|error", "event",
"purpose", "receipt_number", "error_code", "message" } ], "log_dir", "settings_path" }`
(últimos 100 eventos; **sin nombres de clientes, sin tickets, sin importes**).

### 3.3 Impresión en Windows (servicio)
1. Impresora = `settings.printer_name`; null → `printer_not_configured`; no existe → `printer_not_found`.
2. `OpenPrinterW` → `StartDocPrinterW` (DOC_INFO_1: nombre «Recibo RG-000123» / «Iron Body prueba»,
   datatype **"RAW"**) → `StartPagePrinter` → `WritePrinter` (todo escrito) → `EndPagePrinter` → `EndDocPrinter`.
   Fallo → `spooler_error` (si es ERROR_INVALID_DATATYPE, mensaje: instalar el driver
   «Generic / Text Only» en el mismo puerto USB).
3. Seguimiento del trabajo (`GetJobW`, cada 250 ms, hasta 12 s):
   - desaparece de la cola o `JOB_STATUS_PRINTED|COMPLETE` → `accepted`;
   - `ERROR|OFFLINE|PAPEROUT|BLOCKED_DEVQ|USER_INTERVENTION` → **se cancela** (`SetJobW` DELETE) →
     `printer_offline` / `paper_out` / `printer_error`;
   - sigue en cola a los 12 s → **se cancela** → `job_timeout`.
   Se cancela para que un trabajo atascado no salga horas después y duplique una reimpresión.
4. Estado de impresora (`GetPrinterW` nivel 2) solo informativo (`/v1/status`, `/v1/printers`).

### 3.4 Servicio Windows
- .NET 8, self-contained win-x64, servicio `IronBodyReceiptPrinter`, inicio automático (NO retrasado: acorta la ventana en que otro proceso podría ocupar el puerto),
  depende de `Spooler`, reinicio en fallo, cuenta `NT AUTHORITY\LocalService` (parámetro para LocalSystem).
- Ajustes: `%ProgramData%\IronBody\ReceiptPrinter\settings.json` (station_key, station_name,
  printer_name, columns, codepage, feed_lines). Escritura atómica. Sin secretos.
- Config de instalación: `appsettings.json` junto al exe (Port, ApiBaseUrl, AllowedOrigins).
  `ApiBaseUrl` debe ser `https://` salvo host `localhost`/`127.0.0.1` (pruebas).
- Logs: `%ProgramData%\IronBody\ReceiptPrinter\logs\` (JSON por línea, 14 días) + Visor de eventos.

## 4. API del backend para el servicio — `/api/receipt-agent/*` (SIN sesión; autentica el ticket)

Throttle 60/min por IP. Respuestas de error uniformes `{ok:false, code, message}`.

`POST /api/receipt-agent/redeem`
```json
{ "ticket": "<43>", "station_key": "<uuid>",
  "agent": { "version": "1.0.0", "protocol": 1, "printer_name": "POS-58" | null,
             "columns": 32, "codepage": "cp850" } }
```
En UNA transacción con `lockForUpdate` sobre el ticket:
- no existe (por `sha256(ticket)`) → 404 `ticket_invalid` · revocado → 404 `ticket_invalid`
- caducado → 410 `ticket_expired` · ya canjeado → 409 `ticket_used`
- `station_key` ≠ la del ticket → 403 `station_mismatch` (NO consume) · estación inactiva → 403 `station_inactive`
- `agent.protocol` < mínimo → 426 `protocol_unsupported` · kill switch → 503 `agent_disabled`
- print con intento ya cerrado (≠ `started`) → 409 `attempt_closed`
- OK → `redeemed_at`, ip, versión, columnas; estación: `last_seen_at`, `agent_version`,
  `agent_protocol`, `last_printer_name`, `last_ip`; print: `receipt_prints.dispatched_at = now()`.

Respuestas 200:
```json
{ "ok": true, "purpose": "print", "ticket_id": "<uuid>", "job": { …documento §2… } }
{ "ok": true, "purpose": "test", "ticket_id": "<uuid>", "job": { …documento §2, kind "test"… } }
{ "ok": true, "purpose": "configure", "ticket_id": "<uuid>",
  "settings": { "station_name": "Recepción", "printer_name": "POS-58", "columns": 32,
                "codepage": "cp850", "feed_lines": 4 } }
```
El job de print lleva `columns` = las del agente (32|42; otra cosa → 422) y `feed_lines` de la estación.
El backend guarda `content_sha256` = sha256 del JSON de `lines` (qué se mandó, para auditoría).

`POST /api/receipt-agent/report`
```json
{ "ticket": "<43>", "station_key": "<uuid>", "outcome": "accepted" | "error" | "applied",
  "error_code": "printer_offline" | null, "message": "…" | null, "spool_job_id": 12 | null,
  "printer_name": "POS-58" | null }
```
- ticket no canjeado → 409 `not_redeemed` · ya reportado → 409 `already_reported` ·
  fuera de 24 h → 410 `report_window_closed` · estación distinta → 403 `station_mismatch`.
- print: cierra el `receipt_prints` `started → sent` (accepted) o `→ error` (error), con
  `error_message` = message recortado. Sin chequeo de actor (el ticket lo prueba). Si el intento ya
  estaba cerrado (el CRM lo cerró antes) → 200 `{ok:true, recorded:true, already_finished:true}` y
  solo se anota en el ticket.
- `outcome` coherente con el propósito: `applied` solo en configure; `accepted|error` en print/test.
- 200 `{ok:true, recorded:true}`.

## 5. API admin del backend (con sesión de administrador)

### 5.1 Cambio en `POST /api/admin/receipts/{uuid}/prints` (existente)
- `print_mode` admite además `"agent"`.
- `station_key` (uuid) obligatorio si `print_mode=agent`.
- Respuesta `data.agent_ticket: { "token": "<43>", "expires_at": "ISO" }` cuando es agent.
  En una repetición idempotente (mismo `client_attempt_id`, 200): si el intento sigue `started` y sin
  `dispatched_at` → se revocan sus tickets previos y se emite uno nuevo; si no → `agent_ticket: null`.
- Errores nuevos: 404 `station_not_registered`, 403 `station_inactive`, 503 `agent_disabled`.
- Permisos: los existentes (operar/supervisar la caja del recibo). Nada nuevo.

### 5.2 Recibos pendientes
`GET /api/admin/receipts/pending?hours=48` (1..168) → recibos de las cajas que el actor puede
operar/supervisar, emitidos en la ventana, con `print_state` ∈ `pending|sending|error`; máximo 50,
más recientes primero:
```json
{ "ok": true, "data": [ { "uuid", "number", "cash_type", "issued_at", "customer_name", "total",
  "print_state": "pending|sending|error",
  "last_attempt": { "kind", "status", "print_mode", "error_message", "started_at", "dispatched_at" } | null } ] }
```

### 5.3 Estaciones — `ReceiptStationController`
Puerta del mapa central: `RECEIPT_CASH_PERMISSIONS` (cualquier permiso de caja). En el controlador:
persona obligatoria; escribir exige `cash.gym.manage` **o** `cash.products.manage`. Ningún permiso nuevo.

| Método y ruta | Quién | Qué |
|---|---|---|
| `GET /api/admin/receipt-stations` | cualquier permiso de caja | `{ok, data:[station], agent:{enabled, min_protocol, protocol:1}}` |
| `POST /api/admin/receipt-stations` `{station_key, name}` | manage | 201 station · 409 `station_exists` (+ `data`) |
| `PATCH /api/admin/receipt-stations/{station_key}` `{name?, active?, printer_name?, columns?, codepage?, feed_lines?}` | manage | station · auditado |
| `POST /api/admin/receipt-stations/{station_key}/tickets` `{purpose:"test"\|"configure", variant?: ver §5.4}` | manage | 201 `{ok, data:{token, expires_at, purpose}}` |
| `GET /api/admin/receipt-stations/{station_key}/events?limit=30` | cualquier permiso de caja | últimos tickets: `{purpose, issued_at, issued_by_name, redeemed_at, reported_at, outcome, error_code, error_message, receipt_number, agent_version, printer_name}` |

`station`: `{ station_key, name, active, printer_name, columns, codepage, feed_lines, agent_version,
agent_protocol, last_printer_name, last_seen_at, created_at, created_by_name }`.
Validación: name 3..60; printer_name ≤120 sin controles; columns ∈ {32,42}; codepage ∈ {cp850,cp437,ascii};
feed_lines 0..8.

### 5.4 Páginas de prueba (las maqueta el backend)
`variant` ∈ `minimal | full | sample_1 | sample_5 | sample_20 | sample_membership` (defecto `minimal`).
- `minimal` (PRUEBA 1 del piloto): `title "IRON BODY"`, `"PRUEBA POS"` centrado, `"OK"` centrado en negrita.
- `full`: título `PRUEBA DE IMPRESORA`, regla `1234567890…` de `columns`, `|----|`, Izquierda/Derecha
  a los bordes, Centro, `á é í ó ú ñ Ñ ¿? ¡!`, `Á É Í Ó Ú ü Ü`, `$ 1.234.567`, TOTAL en negrita,
  fecha/hora de Bogotá, nombre de estación, `32 col · cp850`, «La regla debe caber en una sola línea».
- `sample_1` / `sample_5` / `sample_20`: recibo de productos SINTÉTICO de 1, 5 y 20 artículos (con un
  descuento y dos medios de pago en el de 5; nombres largos con tildes en el de 20), maquetado por el
  MISMO ReceiptTextLayout que los reales, a partir de un snapshot inventado EN MEMORIA (nada se
  guarda en receipts, ni en ventas, ni en inventario).
- `sample_membership`: pago de membresía SINTÉTICO (plan, vigencia dd/mm/aaaa, saldo pendiente).
- Los `sample_*` llevan número `PRUEBA`, cliente `CLIENTE DE PRUEBA` y `copy_label`
  `PRUEBA · SIN VALOR` (sale arriba y abajo como `*** PRUEBA · SIN VALOR ***`), para que el papel no
  pueda pasar por un recibo real. Sirven para las pruebas físicas 2–5 sin crear ventas reales.

## 6. Códigos de error (`error_code`) que ve el CRM

Fase ticket (no se imprimió nada; el intento sigue `started`; `reported=false`):
`ticket_invalid · ticket_expired · ticket_used · station_mismatch · station_inactive · agent_disabled ·
protocol_unsupported · attempt_closed · api_unreachable · api_error · incompatible_job · rate_limited`
(el backend puede responder además `invalid_request`, `invalid_outcome`, `job_too_long`, `layout_error`,
`internal_error` y `unsupported_media_type`; el servicio los traduce: `job_too_long` → `invalid_job`, el resto → `api_error`).
Fase impresión (el servicio reporta al backend):
`printer_not_configured · printer_not_found · printer_offline · paper_out · printer_error ·
job_timeout · spooler_error · invalid_job · queue_full`

## 7. Estados que muestra el CRM

| Estado | Cuándo |
|---|---|
| PENDIENTE | sin intentos, o último intento `started` sin `dispatched_at` |
| ENVIANDO | último intento `started` con `dispatched_at` (el servicio lo tomó, sin resultado aún) |
| ACEPTADO POR EL SISTEMA | `sent` con `print_mode=agent` (Windows aceptó y entregó al USB) |
| ENVIADO | `sent` con otro modo (navegador; comportamiento anterior) |
| ERROR | último intento `error` |
| REINTENTO N / REIMPRESIÓN | `kind` retry / reprint (rótulo en el papel, lo decide el servidor) |
| OMITIDO | `skipped` (solo con `receipts.print_optional`) |
| CONFIRMACIÓN FÍSICA | **no disponible** por USB+spooler: la DIG-58IIK no devuelve estado por esta vía. Se documenta. |

`Receipt::printState()` añade `sending`. El informe de turno expone por fila el último intento
(`kind, status, print_mode, dispatched_at, error_message`).

## 8. Tablas (migración nueva, SOLO aditiva)

`receipt_stations`: id · `station_key` uuid único · `name` string(60) · `active` bool def true ·
`printer_name` string(120) null · `columns` smallint def 32 · `codepage` string(16) def 'cp850' ·
`feed_lines` smallint def 4 · `agent_version` string(32) null · `agent_protocol` smallint null ·
`last_printer_name` string(120) null · `last_seen_at` ts null · `last_ip` string(45) null ·
`created_by_admin_id` FK admins nullOnDelete null · `created_by_name` string null · timestamps.

`receipt_agent_tickets`: id · `uuid` único (ticket_id público) · `token_hash` char(64) único ·
`purpose` string(16) · `receipt_station_id` FK · `receipt_print_id` FK receipt_prints null ·
`variant` string(32) null · `payload` json null · `issued_by_admin_id` FK admins nullOnDelete null ·
`issued_by_name` string null · `issued_at` · `expires_at` · `revoked_at` null · `redeemed_at` null ·
`redeemed_ip` string(45) null · `agent_version` string(32) null · `columns` smallint null ·
`content_sha256` char(64) null · `reported_at` null · `outcome` string(16) null ·
`error_code` string(40) null · `error_message` string(255) null · `spool_job_id` bigint null ·
`printer_name` string(120) null · timestamps. Índices: (receipt_station_id, id), receipt_print_id.

`receipt_prints` (existente) + `station_id` FK receipt_stations null nullOnDelete + `dispatched_at` ts null.
`ReceiptPrint::booted()` admite además UNA transición «despacho»: estado original `started`,
`dispatched_at` original null, cambia SOLO `dispatched_at`. El cierre sigue igual.
`ReceiptPrint::PRINT_MODES` += `agent`.

## 9. Kill switch
`config/caja.php` → `receipts.agent.enabled` (`RECEIPT_AGENT_ENABLED`, defecto `true`),
`min_protocol` 1, `ticket_ttl` {print 120, test 300, configure 300}, `report_window_hours` 24.
Desactivado → emitir ticket y canjear devuelven 503 `agent_disabled`.

## 10. Activación (no global)
Nada cambia para nadie tras desplegar: el modo `agent` es POR EQUIPO (localStorage del perfil de
Chrome, como hoy `silent|dialog`) y exige una estación registrada por un administrador con
`cash.*.manage`. Los equipos sin configurar siguen como estaban.
