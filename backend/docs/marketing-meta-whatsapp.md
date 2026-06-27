# Iron Body — Activar Meta / WhatsApp Cloud API (Agente Comercial)

Guía operativa para encender el envío real de mensajes por WhatsApp cuando el
negocio termine la gestión de la cuenta con Meta. **Por defecto todo queda en
modo seguro (`dry_run`): el backend prepara y registra el mensaje pero NO lo
entrega a Meta.** Generar/enviar un link de pago **nunca** activa una membresía:
eso es exclusivo del webhook Wompi aprobado.

> ⚠️ No se incluyen credenciales en este repositorio. Los tokens viven SOLO en
> el `.env` del servidor; nunca en Angular/Flutter.

---

## 1. Variables requeridas (`.env` del servidor)

| Variable | Para qué | Dónde se obtiene en Meta |
|---|---|---|
| `META_ENABLED` | Interruptor general (debe ser `true` para envío real) | — (decisión de operación) |
| `META_APP_ID` | App de Meta | Meta for Developers → tu App → *App settings → Basic* |
| `META_APP_SECRET` | Firma del webhook (si no se define `META_WEBHOOK_SECRET`) | *App settings → Basic → App Secret* |
| `META_VERIFY_TOKEN` | Verificación del webhook (GET `hub.verify_token`) | Lo inventas tú (cadena larga) y lo registras en Meta |
| `META_WEBHOOK_SECRET` | HMAC `X-Hub-Signature-256` de los POST | Normalmente = App Secret |
| `META_GRAPH_VERSION` | Versión de Graph API (`v21.0`) | — |
| `META_ACCESS_TOKEN` | Token de WhatsApp Cloud API (System User / larga duración) | *WhatsApp → API Setup* o *Business Settings → Users → System Users* |
| `META_WHATSAPP_BUSINESS_ACCOUNT_ID` | WABA | *WhatsApp → API Setup* (WhatsApp Business Account ID) |
| `META_WHATSAPP_PHONE_NUMBER_ID` | **ID del número** en Cloud API (NO el teléfono visible) | *WhatsApp → API Setup* (Phone number ID) |
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
