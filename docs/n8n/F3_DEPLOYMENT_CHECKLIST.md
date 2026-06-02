# F3 — Checklist de despliegue · Iron Body

Pasos para activar el asesor comercial IA cuando exista infraestructura real.
**Nada de esto se activa todavía** (no hay VPS/dominio; `META_ENABLED=false`).

## 1. Infraestructura (bloqueante)
- [ ] VPS con HTTPS + dominio fijo (p. ej. `https://api.tu-dominio.com`). **No ngrok.**
- [ ] Worker de cola permanente: `php artisan queue:work --tries=3 --max-time=3600` (supervisor).
- [ ] Cron del scheduler: `* * * * * php artisan schedule:run`.
- [ ] Instancia n8n accesible (webhook público + acceso saliente a Laravel).

## 2. Variables (.env del servidor — NO versionar)
- [ ] `AUTOMATION_INTERNAL_SECRET=` (largo y aleatorio) → mismo valor en n8n como `INTERNAL_HMAC_SECRET`.
- [ ] `META_*` IDs reales + secretos (`META_APP_SECRET`, `META_ACCESS_TOKEN`, `META_VERIFY_TOKEN`, `META_WEBHOOK_SECRET`).
- [ ] `META_AD_ACCOUNT_ID` **sin** prefijo `act_`.
- [ ] `META_ENABLED=true` (solo cuando todo lo anterior esté listo y probado).

## 3. n8n
- [ ] Importar los 3 workflows de `n8n/workflows/`.
- [ ] Definir variables de entorno en n8n: `LARAVEL_BASE_URL`, `INTERNAL_HMAC_SECRET`.
- [ ] Crear credencial OpenAI y asignarla al nodo "OpenAI Clasificar+Responder".
- [ ] Publicar el webhook del asesor y copiar su URL pública (`{{N8N_WEBHOOK_URL}}`).
- [ ] Configurar en Laravel/n8n el disparo: cuando entra un lead/mensaje (webhook Meta → evento) se llama al webhook del asesor.

## 4. Meta
- [ ] Configurar webhook de producción: `https://api.<dominio>/api/webhooks/meta` con `META_VERIFY_TOKEN`.
- [ ] Suscribir campos: mensajes IG/FB/WhatsApp + estados.
- [ ] App Review aprobada: `whatsapp_business_messaging`, `instagram_manage_messages`, `pages_messaging`, `ads_read`, `leads_retrieval`.
- [ ] Plantillas de WhatsApp aprobadas para mensajes fuera de la ventana de 24h.

## 5. Pruebas de extremo a extremo
- [ ] Webhook Meta verifica (GET challenge) y recibe (POST firma válida → 200).
- [ ] Llega un mensaje real → se crea lead + conversación + mensaje (idempotente).
- [ ] n8n recibe el evento → OpenAI responde JSON válido.
- [ ] `reply` → `send-message` envía por WhatsApp; se registra el saliente.
- [ ] `human_takeover` → conversación marcada; aparece en CRM.
- [ ] `create_followup` → follow-up creado; el cron lo procesa al vencer.
- [ ] Decisión registrada en `marketing_ai_actions`; visible en Mercadeo.

## 6. Seguridad / cumplimiento
- [ ] HMAC verificado en todos los endpoints internos.
- [ ] Rate limit activo (120/min).
- [ ] Logs sin tokens ni cuerpos de mensajes/datos personales.
- [ ] Opt-out respetado (no recontactar leads que lo piden).
- [ ] Sin secretos en el repo ni en los workflows exportados.
