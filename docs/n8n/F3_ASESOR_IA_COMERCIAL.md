# F3 — Asesor IA Comercial (n8n) · Iron Body

Asesor comercial automatizado que atiende leads de WhatsApp/Instagram/Facebook,
los clasifica, detecta objeciones, decide la siguiente mejor acción y la ejecuta
vía endpoints internos de Laravel (firmados HMAC). **Laravel es la fuente de
verdad**; n8n solo orquesta. **Sin secretos en este repo** — todo va con
placeholders.

> Estado: **preparado, NO activo**. Requiere VPS + dominio HTTPS + tokens reales
> y `META_ENABLED=true` para operar en vivo. Mientras tanto, `send-message`
> responde en modo `disabled` (no envía nada).

## Workflows incluidos (`n8n/workflows/`)
| Archivo | Nombre n8n | Trigger |
|---|---|---|
| `ironbody_f3_asesor_ia_comercial.json` | Iron Body - F3 - Asesor IA Comercial | Webhook `POST /webhook/ironbody/marketing-lead-event` |
| `ironbody_f3_followups.json` | Iron Body - F3 - Follow-up Comercial | Cron 30 min (o webhook) |
| `ironbody_f3_resumen_diario_mercadeo.json` | Iron Body - F3 - Resumen Diario Mercadeo | Cron diario 08:00 |

### Placeholders a reemplazar al importar
- `{{LARAVEL_BASE_URL}}` — base del backend (p. ej. `https://api.tu-dominio.com`). Los nodos usan `$env.LARAVEL_BASE_URL` si está definido.
- `{{INTERNAL_HMAC_SECRET}}` — = `AUTOMATION_INTERNAL_SECRET` del backend (Bearer de los endpoints internos). Usar `$env.INTERNAL_HMAC_SECRET`.
- `{{OPENAI_CREDENTIAL}}` — credencial OpenAI configurada en n8n (no se versiona).
- `{{N8N_WEBHOOK_URL}}` — URL pública del webhook de n8n (la que Laravel invoca).
- `{{META_ENABLED}}` — informativo; el gating real lo aplica Laravel.

## Flujo del workflow principal
1. **Webhook** recibe el evento (`marketing.lead.created` | `marketing.message.received` | `marketing.followup.due`).
2. **Validar payload** mínimo (`lead_id`, `event_type`).
3. **Obtener contexto CRM** → `GET /api/internal/marketing/context/{lead}` (lead, últimos mensajes, campaña, **planes reales**, business_info).
4. **Prompt Asesor** arma `system`+`user` con el contexto real.
5. **OpenAI** (`response_format: json_object`) clasifica y responde.
6. **Parsear decisión** → JSON estructurado.
7. **Decidir acción** (Switch sobre `recommended_action`): `reply` → send-message · `human_takeover` → escalar · `create_followup` → follow-up · `do_nothing` → solo registrar.
8. **Registrar decisión** → `POST /api/internal/marketing/ai-action` (`marketing_ai_actions`).
9. **Responder 200**.

## Entrada esperada (webhook)
```json
{
  "event_id": "...",
  "event_type": "marketing.lead.created|marketing.message.received|marketing.followup.due",
  "lead_id": 123,
  "conversation_id": 456,
  "channel": "whatsapp|instagram|facebook",
  "message": "...",
  "lead": { "name": "...", "phone": "...", "instagram_username": "...", "status": "...", "temperature": "..." },
  "context": { "last_messages": [], "campaign": {}, "membership_plans": [], "business_info": {} }
}
```

## Prompt del sistema (resumen)
**Identidad:** asesor comercial IA de Iron Body Neiva; estratégico, natural y útil; no agresivo.
**Tono:** español colombiano, cercano, profesional, directo, no robótico, no acosador.
**Reglas duras:** no inventar precios/promociones/horarios (usar solo `membership_plans` del contexto; si falta, decir que un asesor confirma); sin diagnóstico médico; sin comentarios ofensivos sobre el cuerpo; respetar ventana/políticas de WhatsApp; escalar a humano si el lead está muy caliente, pide persona, o pregunta por pagos/contratos/casos sensibles.

### Salida JSON obligatoria del modelo
```json
{
  "intent": "pricing|location|schedule|plans|trial|objection|general|human_request|unknown",
  "objection": "price|time|location|trust|already_member|no_money|just_looking|none",
  "temperature": "hot|warm|cold|unqualified",
  "recommended_action": "reply|ask_question|create_followup|human_takeover|do_nothing",
  "reply_text": "...",
  "followup_delay_minutes": 0,
  "human_reason": null,
  "confidence": 0.0
}
```

## Cómo clasifica / detecta objeciones / decide
- **Clasificación e intención + temperatura + objeción:** las produce el modelo en el JSON de salida; Laravel persiste `intent`/`objection`/`temperature` en `metadata` de `marketing_ai_actions` y refleja `temperature` en el lead (alimenta Mercadeo CRM).
- **Follow-up:** si `recommended_action=create_followup`, n8n llama a `followups` con `followup_delay_minutes` → el seguimiento queda pendiente y el workflow de follow-ups lo procesa al vencer.
- **Escalado a humano:** si `recommended_action=human_takeover` → `human-takeover` marca `conversation.human_takeover=true`, `ai_enabled=false` y registra la acción.

## Anti-spam / WhatsApp templates / opt-out
- **Anti-spam:** follow-ups idempotentes (1 pendiente por lead/tipo/vencimiento); el workflow de follow-ups envía máximo 1 por lead por corrida.
- **Ventana 24h WhatsApp:** fuera de la ventana de servicio, solo se permiten **plantillas aprobadas**. El asesor NO debe enviar texto libre fuera de ventana; usar plantilla. (El gating de envío vive en Laravel + Meta.)
- **Opt-out:** si el lead pide no recibir mensajes, registrar acción `do_nothing` + marcar lead `discarded`/`unqualified` y no volver a contactar.

## Conexión con el sistema
- **`marketing_ai_actions`:** cada decisión queda registrada (trazabilidad obligatoria).
- **Mercadeo CRM (F5):** el panel "Mercadeo digital (Meta)" lee `overview`/`leads`/`ai-actions`; las acciones y temperaturas que escribe el asesor aparecen ahí.

## Pendiente hasta VPS/dominio
- Webhook Meta de producción (`https://api.<dominio>/api/webhooks/meta`) verificado.
- Tokens reales en `.env` del servidor + `META_ENABLED=true`.
- Credencial OpenAI en n8n + App Review de permisos de mensajería.
