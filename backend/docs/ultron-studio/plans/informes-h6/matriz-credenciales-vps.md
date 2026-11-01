# ULTRON STUDIO · Matriz de credenciales del VPS (auditoría de solo lectura, 01-11-2026 01:18 UTC)

Leída por el lead por SSH (`ironbody-vps`, root) sobre el fichero de entorno de `/var/www/api/backend`, **sin imprimir ningún valor**: de cada clave solo se anotó si existe, si está vacía y, para las que no son interruptores, la longitud del valor. Las comprobaciones de «validada» salen de la base de datos de producción y del código, no de llamar a los proveedores (ninguna petición saliente se hizo para esta auditoría).

## Leyenda

- **Disponible:** la clave existe en el entorno con un valor no vacío.
- **Validada:** hay evidencia en producción de que el proveedor la acepta (una pasada de sincronización con éxito, un flujo que la usa a diario, etc.).
- **Pendiente de validación:** existe, pero nada en producción demuestra hoy que funcione para el uso que ULTRON STUDIO le daría.
- **Faltante:** no existe; la pone el propietario en el servidor, nunca por chat ni por el CRM.

## Matriz

| Área | Clave | Estado | Evidencia (01-11-2026) | Quién la usa / para qué | Acción |
|---|---|---|---|---|---|
| **OpenAI (motor)** | `OPENAI_API_KEY` | Disponible · validada | Longitud 164; ULTRON encendido (`MARKETING_ULTRON_ENABLED=true`) con 700 filas en `marketing_ai_actions` en 7 días | Motor de ULTRON (vía n8n) y capa de nutrición | Ninguna. **No** se usa para el laboratorio: una clave compartida con el motor es uno de los cuatro cerrojos del Hito 4 |
| **OpenAI (laboratorio, Hito 4 fase 3)** | `ULTRON_STUDIO_LAB_OPENAI_API_KEY` | **Faltante** | Ausente; `ULTRON_STUDIO_LAB_ENABLED=true`, `ULTRON_STUDIO_LAB_DRIVER=fake`, `ULTRON_STUDIO_LAB_DAILY_BUDGET_USD=0` | Laboratorio de IA del Studio (`store: false`) | **Propietario:** proyecto dedicado en OpenAI con presupuesto propio, clave nueva distinta de la del motor y de la de n8n, escrita en el servidor; después `ULTRON_STUDIO_LAB_DRIVER=openai` y presupuesto > 0 (fase 3 del Hito 4). Es la vía (a) para la primera publicación con la puerta del laboratorio en `required` |
| | `ULTRON_STUDIO_LAB_OPENAI_MODEL`, `_TEMPERATURE`, `_REASONING_EFFORT`, `_BASE_URL`, `_PRICE_*` | Faltantes (con defecto seguro en el código) | — | Laboratorio | Opcionales; decidir modelo y precios oficiales con la clave |
| **Hito 5 (gobierno)** | `MARKETING_ULTRON_STUDIO_CONFIG` | Disponible = `off` | Escrita en la fase 1 | Alcance de lectura del motor | Fase 2: `canary` con `h5-engine-scope.sh` tras 24 h estables |
| | `ULTRON_STUDIO_PUBLISHING_ENABLED` | Disponible = `false` | Fase 1 | Llave de publicar/promover | La abre `h5-engine-scope.sh` en fase 2 |
| | `ULTRON_STUDIO_PUBLISH_LAB_GATE` | Disponible = `required` | Fase 1; decisión del propietario del 01-11: se respeta | Puerta del laboratorio al publicar | Solo el propietario puede escribir `waived` (vía (b)) |
| **Meta Ads (lectura)** | `META_ADS_ACCESS_TOKEN` | Disponible · **validada** | Longitud 206; `meta_sync_runs`: 504 pasadas `ok` en 7 días, última 01:15 UTC de hoy | Sincronización horaria de campañas, conjuntos y anuncios (`meta_ad_entities`) | Ninguna. Solo `ads_read`; el Studio no llama a Graph (lee el espejo) |
| | `META_ADS_GRAPH_VERSION` | Disponible | Longitud 5 (p. ej. `vNN.0`) | Cliente Graph | — |
| | `META_ADS_SYNC_ENABLED` | Disponible = `true` | — | Sincronización | — |
| | `META_AD_ACCOUNT_IDS` | Disponible | 3 cuentas (longitud 49) | Cuentas conectadas | — |
| **Meta WhatsApp (motor)** | `META_ENABLED`, `META_APP_SECRET`, `META_VERIFY_TOKEN` | Disponibles · validadas | `true`; longitudes 32 y 48; el webhook firmado de WhatsApp alimenta ULTRON a diario | Webhook de WhatsApp Cloud API | Fuera del alcance del Hito 6 (no se toca) |
| | `META_WHATSAPP_TOKEN`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID` | Ausentes **con esos nombres** | El envío de WhatsApp usa otras claves del propio módulo de Meta (no auditadas aquí porque el Studio no las necesita) | — | Ninguna para el Studio |
| **Twilio (OTP)** | `TWILIO_ACCOUNT_SID` = `TWILIO_SID` | Disponible · **validada solo para OTP** | Longitud 34; huellas iguales (misma credencial con dos nombres); `OTP_DRIVER=twilio` y el login de socios por SMS funciona a diario | Verify por SMS y el código de vínculo socio↔personal | Ninguna. La sonda GET del Hito 6 la reutiliza salvo que el propietario cree una API key restringida |
| | `TWILIO_AUTH_TOKEN` = `TWILIO_TOKEN` | Disponible · validada (OTP) | Longitud 32; huellas iguales | Ídem | Ídem |
| | `TWILIO_VERIFY_SERVICE_SID` | Disponible · validada | Longitud 34 | Verify | — |
| | `TWILIO_BASE`, `TWILIO_VERIFY_BASE` | Disponibles | Longitudes 22 y 25 (URL de los hosts oficiales) | Cliente OTP | Nota de seguridad T-6 del plan H6: son URL configurables; la sonda del Studio fija sus hosts en `production` y no las lee |
| | `TWILIO_FROM` | Presente **vacía** | — | Messages (código de vínculo) | Confirmar con el propietario si el envío por Messages está en uso; irrelevante para la sonda |
| | `TWILIO_SEND_ENABLED` | Ausente (defecto del código) | — | Palanca de envío | Documentada en `.env.example` del Hito 6 |
| **Twilio (voz)** | `TWILIO_VOICE_ENABLED` | **Faltante (apagada por defecto)** | Ausente; no hay número de voz ni caller ID verificado conocido | Nada en el código puede llamar | Solo con autorización expresa, presupuesto y controles (fuera del Hito 6) |
| | `ULTRON_STUDIO_TWILIO_PROBE_ENABLED`, `_KEY_SID`, `_KEY_SECRET` | Faltantes (apagada por defecto) | — | Sonda GET de solo lectura del Hito 6 | **Propietario (opcional):** API key restringida de solo lectura para la sonda; si no, se usa la del OTP |
| **ElevenLabs** | `ELEVENLABS_API_KEY`, `ELEVENLABS_ENABLED`, `ELEVENLABS_AGENT_ID`, `ELEVENLABS_WEBHOOK_SECRET` (y `_VOICE_ID`, `_REGION`, `_TOOL_SECRET`) | **Faltantes** | Ninguna referencia en el entorno; `voice:doctor` responde `ELEVENLABS_NOT_CONFIGURED` | Adaptador de voz preparado sin activar | Estado final previsto del Hito 6: `ELEVENLABS_NOT_CONFIGURED`. Activar exige, además de la clave: `agent_id`, `voice_id`, secreto del webhook, región (`default` o `eu`), número de voz colombiano o caller ID verificado en Twilio, y autorización expresa |
| **n8n ↔ backend** | `AUTOMATION_INTERNAL_SECRET`, `N8N_WEBHOOK_SECRET`, `MARKETING_ULTRON_WEBHOOK_URL` | Disponibles · validadas | Longitudes 64/64/56; ULTRON responde a diario por ese puente | Motor (decide/commit, webhook del workflow) | Fuera del alcance del Studio (frontera) |
| **Pagos y facturación** | `WOMPI_PRIVATE_KEY`, `WOMPI_EVENTS_SECRET`, `FACTUS_CLIENT_ID`, `FACTUS_CLIENT_SECRET` | Disponibles · validadas por sus módulos | Longitudes 41/44/36/40 | Caja, enlaces de pago (hoy apagados para ULTRON), facturación | Fuera del alcance (protección absoluta de Iron Body) |
| **Aplicación** | `APP_KEY` | Disponible · validada | Longitud 51 (`base64:`); distinta de cualquier fichero del repo (N-2 comprobado el 31-10) | Firma de la cadena de publicaciones (subclave HMAC), cifrado | Rotarla es un evento de todo el sistema (runbook del Hito 5 §8) |

## Resumen para el propietario

- **Conectadas y validadas:** Meta Ads en lectura (504 pasadas `ok` en 7 días), Twilio solo para OTP, OpenAI del motor, puente n8n, pagos y facturación.
- **Faltantes que bloquean cierres del Studio:** la clave de OpenAI **del laboratorio** (fase 3 del Hito 4 y vía (a) para la primera publicación del Hito 5).
- **Faltantes que NO bloquean nada** (se mantienen apagadas por diseño): Twilio Voice, la sonda de Twilio y todo ElevenLabs (`ELEVENLABS_NOT_CONFIGURED`).
- **Pendientes de validación:** ninguna clave existente está sin uso real salvo `TWILIO_FROM`, que está vacía (confirmar si Messages se usa).
- **Nada de lo anterior se validó llamando a los proveedores**: la evidencia es la actividad de producción. La primera verificación activa de Twilio (sonda GET) y de ElevenLabs (`voice:doctor --verify`) llega con el Hito 6 y solo con sus banderas encendidas por el propietario.
