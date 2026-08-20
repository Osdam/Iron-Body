# Iron Body — Activar Meta / WhatsApp Cloud API (Agente Comercial)

Guía operativa para encender el envío real de mensajes por WhatsApp cuando el
negocio termine la gestión de la cuenta con Meta. **Por defecto todo queda en
modo seguro (`dry_run`): el backend prepara y registra el mensaje pero NO lo
entrega a Meta.** Generar/enviar un link de pago **nunca** activa una membresía:
eso es exclusivo del webhook Wompi aprobado.

> ⚠️ No se incluyen credenciales en este repositorio. Los tokens viven SOLO en
> el `.env` del servidor; nunca en Angular/Flutter.

---

## 0. Estado actual — leer antes de tocar nada (2026-08-03)

**El número `+573143455483` NO está conectado a Cloud API.** Vive únicamente en
la app WhatsApp Business, que es donde lo usa el personal.

Verificado contra Graph API el 2026-08-03:

| Comprobación | Resultado |
|---|---|
| `GET /{WABA}/phone_numbers` | `{"data": []}` — el WABA no tiene números |
| `GET /1221649421024405` | error 100/33: el objeto **no existe** |
| `GET /{WABA}` | `account_review_status: APPROVED`, `status: ACTIVE` — el WABA está sano |
| `GET /{BUSINESS}` | `verification_status: not_verified` (error 141010) |
| `GET /{APP}/subscriptions` | webhook `whatsapp_business_account` activo, campo `messages` |

**Qué pasó:** el número se desvinculó de Cloud API el 2026-06-30 a las 08:25
(`.env.BEFORE-DISABLE-META-CLOUD-20260630_082533`,
`ironbody-before-unlink-meta-cloud-20260630_082546.dump`), casi con seguridad
para devolvérselo al personal. Un intento de re-vincular el 2026-07-01 no
prosperó. Por eso `META_WHATSAPP_PHONE_NUMBER_ID` del `.env` del servidor apunta
a un ID muerto y el canal lleva un mes inactivo.

### ⛔ Advertencia crítica: no repetir el registro tradicional

Registrar el número en Cloud API por el flujo tradicional (secciones 1-5 de esta
guía) **lo saca de la app WhatsApp Business y el personal pierde WhatsApp Web**.
Eso fue exactamente lo que provocó la desvinculación de junio. Repetirlo
reproduce el mismo problema.

La única vía que conserva ambos usos es **Coexistence** (sección 8), que exige
Embedded Signup y un Solution Partner o Tech Provider. Esta app
(`906146885861728`) es `app_type: 0`, sin permisos aprobados por App Review y
sin ninguna configuración de Facebook Login for Business
(`/business_login_configs` → *Unknown path components*), así que **no puede
ejecutar Embedded Signup por sí sola hoy**.

---

## 1. Variables requeridas (`.env` del servidor)

| Variable | Para qué | Dónde se obtiene en Meta |
|---|---|---|
| `META_ENABLED` | Interruptor general (debe ser `true` para envío real) | — (decisión de operación) |
| `META_APP_ID` | App de Meta | Meta for Developers → tu App → *App settings → Basic* |
| `META_APP_SECRET` | Solo se comprueba su **presencia** (`MetaAuthService.php:22`); su valor no se usa para firmar | *App settings → Basic → App Secret* |
| `META_VERIFY_TOKEN` | Verificación del webhook (GET `hub.verify_token`) | Lo inventas tú (cadena larga) y lo registras en Meta |
| `META_WEBHOOK_SECRET` | HMAC `X-Hub-Signature-256` de los POST. **Debe ser el App Secret real** | *App settings → Basic → App Secret* |

> ⚠️ **Ambas variables deben contener el mismo App Secret.** En el `.env` del
> servidor (2026-08-03) `META_APP_SECRET` tiene un valor obsoleto que Graph
> rechaza con *"Invalid OAuth access token signature"*, mientras que
> `META_WEBHOOK_SECRET` sí contiene el secreto válido de la app
> `906146885861728`. Hoy no rompe nada —`config/meta.php:25` hace que la firma
> use `META_WEBHOOK_SECRET`, y `app_secret` solo se comprueba por presencia—
> pero hay que sincronizarlas antes de reactivar el canal. **Requiere entrar a
> Meta a copiar el App Secret; no se corrige desde el repositorio.**
| `META_GRAPH_VERSION` | Versión de Graph API (`v21.0`) | — |
| `META_ACCESS_TOKEN` | Token de WhatsApp Cloud API (System User / larga duración) | *WhatsApp → API Setup* o *Business Settings → Users → System Users* |
| `META_WHATSAPP_BUSINESS_ACCOUNT_ID` | WABA | *WhatsApp → API Setup* (WhatsApp Business Account ID) |
| `META_WHATSAPP_PHONE_NUMBER_ID` | **ID del número** en Cloud API (NO el teléfono visible). **Hoy contiene `1221649421024405`, un ID eliminado**: se reemplaza por el que devuelva el onboarding de coexistencia | *WhatsApp → API Setup* (Phone number ID) |
| `WHATSAPP_DISPLAY_PHONE` | Número visible (solo informativo) | El número real de tu línea |
| `META_PAGE_ID`, `META_INSTAGRAM_ACCOUNT_ID`, `META_AD_ACCOUNT_ID`, `META_BUSINESS_ID` | Otros activos (fases siguientes) | Business Settings |
| `META_API_TIMEOUT` | Timeout HTTP (segundos) | — |

---

## 2. Webhook a registrar en Meta

```
https://api.ironbodyneiva.cloud/api/webhooks/meta
```

- **GET** (verificación): Meta envía `hub.mode=subscribe`, `hub.verify_token`,
  `hub.challenge`. El backend responde el `challenge` en texto plano si el
  `verify_token` coincide con `META_VERIFY_TOKEN`.
- **POST** (eventos): se valida la firma `X-Hub-Signature-256` con
  `META_WEBHOOK_SECRET`/`META_APP_SECRET`, se responde **200 rápido** y el
  procesamiento va a cola (`ProcessMetaWebhookEvent`), idempotente por
  `meta_message_id`.

Requiere dominio HTTPS público y verificado (no ngrok para producción).

---

## 3. Diagnóstico (sin secretos)

**Comando:**
```bash
php artisan meta:doctor
```
**Endpoint (n8n/operación), protegido por `automation.internal`:**
```bash
curl -s https://api.ironbodyneiva.cloud/api/internal/marketing/meta/doctor \
  -H "Authorization: Bearer $AUTOMATION_INTERNAL_SECRET"
```
Ambos muestran `SET/MISSING` por variable, `auth_configured`, `send_mode`
(`real`/`dry_run`), la URL de webhook esperada y sugerencias. **Nunca** imprimen
valores de tokens.

---

## 4. Probar en modo seguro (dry_run)

Endpoint a probar (flujo completo: genera/reutiliza link + arma mensaje):
```bash
curl -X POST https://api.ironbodyneiva.cloud/api/internal/marketing/payment-links/send \
  -H "Authorization: Bearer $AUTOMATION_INTERNAL_SECRET" \
  -H "Content-Type: application/json" \
  -d '{"marketing_lead_id": 2, "plan_id": 4, "channel": "whatsapp"}'
```
Con `META_ENABLED=false` (o credenciales incompletas) la respuesta es:
```json
{ "ok": true, "sent": false, "dry_run": true,
  "reason": "meta_disabled_or_unconfigured", "safe_to_send": true,
  "payment_url": "https://checkout.wompi.co/p/?...", "prepared_body": "¡Hola! 💪 ..." }
```
El `marketing_message` queda registrado con `status=dry_run` (no se entregó nada).

---

## 5. Activar envío real controlado

> ⛔ **Bloqueado hoy.** No hay `phone_number_id` válido (ver sección 0). Poner
> `META_ENABLED=true` sin número no envía nada y solo enmascara el diagnóstico.
> Este paso solo aplica **después** de completar la coexistencia (sección 8).

1. Completar en el `.env` del servidor: `META_ENABLED=true`, `META_ACCESS_TOKEN`,
   `META_APP_SECRET`, `META_WHATSAPP_PHONE_NUMBER_ID` (mínimo), y el resto.
2. Limpiar cache de config:
   ```bash
   php artisan config:clear
   ```
3. Verificar readiness:
   ```bash
   php artisan meta:doctor   # send_mode debe decir "real"
   ```
4. Probar **con un lead interno propio** (tu número):
   ```bash
   curl -X POST https://api.ironbodyneiva.cloud/api/internal/marketing/payment-links/send \
     -H "Authorization: Bearer $AUTOMATION_INTERNAL_SECRET" \
     -H "Content-Type: application/json" \
     -d '{"marketing_lead_id": <TU_LEAD>, "plan_id": <PLAN>, "channel": "whatsapp"}'
   ```
   Respuesta esperada con envío real: `"sent": true`, `"dry_run": false`,
   `"provider_message_id": "wamid...."`.

---

## 6. Volver a apagar (rollback)

```
META_ENABLED=false
php artisan config:clear
```
Vuelve a `dry_run` de inmediato. No afecta el flujo Wompi in-app ni la
facturación.

---

## 7. Advertencias

- **No enviar masivo.** En esta fase, solo pruebas con un lead interno.
- **Ventana de 24 h de WhatsApp.** Fuera de la ventana de conversación, Meta
  exige **plantillas (templates) aprobadas**; un texto libre será rechazado.
  El backend no fuerza plantillas: el envío libre solo funciona dentro de la
  ventana.
- **`do_not_contact`** y **sin teléfono válido** bloquean el envío (guardrails).
- **Teléfono Colombia:** un celular local de 10 dígitos que empieza por `3` se
  normaliza a `57XXXXXXXXXX` para Meta (el teléfono guardado del lead no se
  altera; el recipiente normalizado se guarda en `marketing_messages.metadata`).

---

## 8. Coexistence — la única vía que conserva la app y WhatsApp Web

**Coexistence** permite que el mismo número esté a la vez en la app WhatsApp
Business (personal, incluido WhatsApp Web) y en Cloud API (Laravel y el agente).
Meta sincroniza el historial en ambos sentidos.

> **Actualización 2026-08-20.** La entrada al Embedded Signup ya existe dentro
> del CRM: `Configuración → Integraciones → WhatsApp Business`, con endpoints,
> persistencia cifrada y precedencia sobre el `.env`. Lo único que falta es
> crear la configuración de Facebook Login for Business en el panel de Meta
> (`META_EMBEDDED_SIGNUP_CONFIG_ID`). Ver **`docs/whatsapp/EMBEDDED-SIGNUP.md`**,
> que además trae el guion para grabar la evidencia de la App Review.

### Requisitos de Meta

| Requisito | Estado en Iron Body |
|---|---|
| El onboarding lo ejecuta un **Solution Partner o Tech Provider** | ❌ no lo somos |
| **Embedded Signup** con *session logging* (v3/v4; v2 se retira el 2026-10-15) | ⚠️ construido en el CRM; falta el `config_id` en el panel de Meta |
| Número activo en la app WhatsApp Business, versión ≥ 2.24.17 | ✅ confirmado |
| Sincronizar el historial en las 24 h siguientes al onboarding | pendiente |

Verificación del negocio: para cuentas de coexistencia la verificación estándar
**no** es el camino; se usa **Partner-Led Business Verification (PLBV)**, que
inicia el partner al final del Embedded Signup. El `not_verified` actual **no
impide arrancar** el onboarding.

### Límites propios de coexistencia

- Throughput fijo de **20 mensajes/segundo**.
- Sin **Official Business Account** (insignia verde); alternativa: Meta Verified.
- No soporta grupos, mensajes temporales ni listas de difusión.

### Impacto en este backend

1. `META_WHATSAPP_PHONE_NUMBER_ID` cambia por el ID nuevo.
2. **Hay que suscribirse al campo `smb_message_echoes`**, además de `messages`.
   Hoy la app solo está suscrita a `messages`
   (`GET /{APP}/subscriptions`), así que los mensajes que el personal envíe
   desde la app **no llegarían** a Laravel. `MetaWebhookService::parseEvents()`
   necesitará una rama para ese campo.
3. `MetaMessagingService` sigue sirviendo tal cual **si** el proveedor deja
   hablar con Graph API directamente contra nuestro WABA.
4. El `phone_number_id` y el token ya NO tienen que pegarse a mano en el `.env`:
   los guarda el onboarding desde el CRM y tienen precedencia sobre el fichero
   (`WhatsappIntegrationRegistry`). El `.env` sigue siendo el respaldo.

### Qué exigir al proveedor

- Que el WABA siga siendo **`1355229980038956`** (propiedad `SELF`), sin crear otro.
- Que el webhook apunte a `https://api.ironbodyneiva.cloud/api/webhooks/meta`,
  no a un inbox del proveedor.
- Acceso directo a Graph API con nuestro token, no una API propietaria.
- Que su papel se limite al **onboarding** y a la PLBV.
