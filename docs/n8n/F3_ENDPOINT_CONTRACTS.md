# F3 — Contratos de endpoints internos (Laravel) · Iron Body

Endpoints que consume n8n para el asesor comercial IA. **Todos firmados HMAC**
(middleware `automation.internal`): `Authorization: Bearer <AUTOMATION_INTERNAL_SECRET>`
y, opcionalmente, `X-IronBody-Signature: HMAC-SHA256(rawBody, secret)`.
**Rate limit:** `throttle:120,1` (120/min) en el grupo. **Idempotencia** donde aplica.

> Implementados en F3 (seguros con `META_ENABLED=false`). `send-message` NO envía
> nada en vivo mientras Meta esté deshabilitado: responde `status: disabled`.

Base: `POST/GET {{LARAVEL_BASE_URL}}/api/internal/marketing/...`

## POST /ai-action
Registra la decisión del asesor en `marketing_ai_actions` y refleja `temperature` en el lead.
```json
// request
{ "lead_id": 123, "conversation_id": 456, "action_type": "reply",
  "reason": "...", "confidence": 0.82, "status": "executed",
  "intent": "pricing", "objection": "price", "temperature": "hot" }
// response
{ "ok": true, "ai_action_id": 1 }
```

## POST /send-message
Envío saliente. **Gated:** con `META_ENABLED=false` no contacta a Meta.
```json
// request
{ "conversation_id": 456, "body": "Hola, con gusto te cuento..." }
// response (META_ENABLED=false)
{ "ok": true, "status": "disabled", "sent": false, "message": "META_ENABLED=false: ..." }
// response (en vivo, WhatsApp)
{ "ok": true, "sent": true, "meta_message_id": "wamid...." }
```
Solo registra el mensaje saliente (`marketing_messages`) cuando realmente se envía.

## POST /human-takeover
Escala a humano: `conversation.human_takeover=true`, `ai_enabled=false` + registra acción.
```json
{ "conversation_id": 456, "reason": "lead caliente" }
// → { "ok": true, "conversation_id": 456, "human_takeover": true }
```

## POST /followups
Crea un follow-up **idempotente** (no duplica pendiente del mismo lead/tipo/vencimiento).
```json
{ "lead_id": 123, "due_at": "2026-06-03T15:00:00Z", "type": "message", "message_template": "..." }
// → { "ok": true, "followup_id": 7, "created": true }
```

## GET /context/{lead}
Contexto mínimo **saneado** para la IA (sin datos sensibles). Incluye **planes reales**
(para no inventar precios).
```json
{ "ok": true, "data": {
  "lead": { "id": 123, "name": "...", "channel": "whatsapp", "status": "new", "temperature": "cold", "objective": null, "instagram_username": null },
  "conversation": { "id": 456, "channel": "whatsapp", "human_takeover": false, "ai_enabled": true },
  "last_messages": [ { "direction": "inbound", "sender_type": "lead", "body": "...", "created_at": "..." } ],
  "campaign": { "id": 1, "name": "...", "objective": "..." },
  "membership_plans": [ { "id": 1, "name": "Mensual", "price": 80000, "duration_days": 30 } ],
  "business_info": { "name": "Iron Body Neiva", "whatsapp": "+57..." }
}}
```

## Endpoints de lectura del CRM (F5, sin auth propia, patrón /admin/*)
Usados por los workflows de follow-up y resumen:
- `GET {{LARAVEL_BASE_URL}}/api/admin/marketing/overview`
- `GET {{LARAVEL_BASE_URL}}/api/admin/marketing/followups?status=pending`

## Seguridad
- HMAC obligatorio (Bearer); firma opcional `X-IronBody-Signature` validada si llega.
- Rate limit 120/min. Sin tokens en logs. Sin payloads crudos de webhook expuestos.
- `send-message` nunca envía con `META_ENABLED=false`.
