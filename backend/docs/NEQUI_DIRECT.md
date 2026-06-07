# Nequi DIRECTO — Pagos con notificación Push

Proveedor de pago **independiente de ePayco** para cobrar membresías con Nequi
mediante "Pagos con notificación Push" (Nequi Negocios / Nequi Conecta).

> Estado: **adapter listo y probado, deshabilitado por defecto**. Sin las
> credenciales finales de Nequi el endpoint responde `unavailable` (cero cobros,
> cero aprobaciones falsas). El Smart Checkout de ePayco deja de ser el flujo
> principal de Nequi.

## Flujo Nequi Push

1. El comercio inicia el pago por API (`createPushPayment`): crea una
   `PaymentTransaction` **PENDING** (`provider=nequi`, `method=nequi_push`) con el
   monto **autoritativo del plan** y dispara el push a Nequi.
2. El cliente recibe una **notificación en su app Nequi** y aprueba o cancela.
3. El backend confirma el resultado por **webhook** (`/confirmation`) y/o por
   **consulta de estado** (`/status`).
4. Al quedar `approved`, `PaymentMembershipActivator` **activa la membresía una
   sola vez** (idempotente por `payments.reference`) y emite eventos realtime.
5. La app refresca `app-state` y solo entra al Home si
   `membership.active == true` / `can_access_home == true`.

Estados → interno: `approved`→approved · `pending/processing`→pending ·
`rejected/declined/failed`→failed · `expired/timeout`→expired ·
`cancelled/abandoned/reversed`→cancelled.

## Variables de entorno (`.env`)

```env
NEQUI_DIRECT_ENABLED=false          # true para activar el cobro real
NEQUI_ENV=sandbox                   # sandbox | production
NEQUI_API_BASE=                     # base REST de Nequi
NEQUI_AUTH_URL=                     # endpoint OAuth client_credentials
NEQUI_CLIENT_ID=
NEQUI_CLIENT_SECRET=
NEQUI_API_KEY=                      # x-api-key si aplica
NEQUI_MERCHANT_ID=
NEQUI_WEBHOOK_SECRET=               # HMAC-SHA256 del webhook (prod: obligatorio)
NEQUI_CONFIRMATION_URL=https://api.ironbodyneiva.cloud/api/payments/nequi/confirmation
NEQUI_RESPONSE_URL=https://api.ironbodyneiva.cloud/api/payments/nequi/response
NEQUI_PAYMENT_TTL_MINUTES=15
PAYMENT_NEQUI_PROVIDER=disabled     # direct | epayco_smart_checkout | disabled
```

`config/services.php` → `services.nequi.*` y `services.payments.nequi_provider`.

## Endpoints

| Método | Ruta | Auth | Descripción |
|---|---|---|---|
| POST | `/api/payments/nequi/push` | member | Inicia el cobro push. |
| GET  | `/api/payments/nequi/{reference}/status` | member | Estado real. |
| POST | `/api/payments/nequi/confirmation` | pública (S2S) | Webhook Nequi (idempotente). |
| GET  | `/api/payments/nequi/response` | pública (S2S) | Retorno informativo. |
| POST | `/api/payments/nequi/{reference}/reverse` | member | Reverso (no revierte membresía). |

`push` deshabilitado responde:

```json
{ "ok": false, "status": "unavailable", "provider": "nequi",
  "method": "nequi_push",
  "message": "Nequi directo está en proceso de activación. Usa PSE, tarjeta o DaviPlata." }
```

`push` habilitado + pendiente:

```json
{ "ok": true, "provider": "nequi", "method": "nequi_push", "status": "pending",
  "reference": "NEQUI-...", "amount": 80000, "expires_at": "...",
  "can_access_home": false, "message": "Revisa tu app Nequi y aprueba el pago." }
```

## Seguridad

- **Monto siempre desde el plan en BD**; se ignora cualquier `amount` de la app.
- Teléfono colombiano validado (10 dígitos, empieza por 3).
- `createPushPayment` **nunca** activa membresía; solo `approved` lo hace.
- Activación **idempotente** (una fila `payments` por referencia) → webhook
  duplicado no duplica membresía.
- Webhook con **firma HMAC-SHA256** (`NEQUI_WEBHOOK_SECRET`). En `production` sin
  secreto se **rechaza**; en `sandbox` se acepta para pruebas.
- `rejected/expired/failed/abandoned` **no** activan.
- El **reverso no** revierte la membresía automáticamente (requiere flujo admin).
- **Nunca** se loguean llaves ni tokens. Logs: `reference`, `provider`,
  `method`, `status`.

## Qué falta por parte de Nequi

- Credenciales **finales de producción** (client id/secret, api key, merchant id,
  webhook secret).
- **Rutas/paths exactos** del API push (inicio, consulta, reverso): el adapter usa
  rutas documentadas (`/payments/unregistered/*`) que se confirman con la doc
  final; al cambiar solo se ajusta el adapter HTTP, no la lógica de negocio.
- Formato real del **payload del webhook** y de la **cabecera de firma**.

## Pruebas que Nequi suele exigir (certificación)

- Pago **aprobado**, **rechazado**, **expirado**.
- Número **sin Nequi** / inválido.
- **Consulta de estado**.
- **Reverso**.

## Cómo activar

```env
NEQUI_DIRECT_ENABLED=true
PAYMENT_NEQUI_PROVIDER=direct
NEQUI_ENV=production
# + credenciales reales
```

La app (Flutter) ya enruta el método Nequi a `/payments/nequi/push` con la UI
propia del checkout (celular + documento → "Revisa tu app Nequi" → "Verificar
pago"). Si el backend responde `unavailable`, ofrece otro método. **DaviPlata**
sigue por ePayco (Smart Checkout); **tarjeta/PSE** intactos por ePayco.

## Pruebas automatizadas

`tests/Feature/NequiPushFlowTest.php`: disabled→unavailable, teléfono inválido,
push pending no activa, monto autoritativo, webhook approved activa una sola vez
(idempotente), rechazado/expirado no activan, `can_access_home` solo con
membresía activa.
