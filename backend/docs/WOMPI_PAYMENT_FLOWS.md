# Wompi — Flujos de pago

La app conserva la UI de Iron Body. Cada método reutiliza los formularios y
pantallas existentes; solo se reconectó la lógica a Wompi.

## Común a todos

1. La app pide `GET /payments/wompi/config` (llave pública, ambiente, métodos) y
   `GET /payments/wompi/acceptance` (enlaces de términos + tratamiento de datos).
2. El usuario marca los **dos consentimientos obligatorios** (sin ambos no se
   puede pagar).
3. La app envía un `client_request_id` (idempotencia ante doble toque / reintento).
4. El backend crea/reutiliza la transacción, recalcula el monto (plan), firma y
   envía a Wompi. La membresía se activa **solo** por webhook/reconciliación.

## Tarjeta (CARD) + 3D Secure

1. Flutter tokeniza con la **llave pública**: `POST {api_url}/tokens/cards`
   (PAN/CVC nunca van al backend) → `card_token`.
2. `POST /payments/wompi/card` con `card_token`, `installments`, consentimientos.
3. Backend crea la transacción `payment_method.type=CARD`.
4. Si el emisor exige **3DS**, la transacción queda `requires_action` con una URL
   de autenticación: la app la abre en **WebView interno** y luego consulta estado.
   No se aprueba por terminar el desafío: se espera el estado final del backend.

## PSE

1. Bancos reales: `GET /payments/wompi/pse/institutions` (cacheado; nunca hardcode).
2. `POST /payments/wompi/pse` con `financial_institution_code`, `user_type`
   (0 natural / 1 jurídica), `user_legal_id[_type]`, consentimientos.
3. Wompi devuelve la **URL oficial del banco** (`async_payment_url`) →
   `requires_action`. La app la abre en WebView interno.
4. Al volver, la app consulta `GET /payments/wompi/{ref}/status`. La confirmación
   real llega por webhook.

## Nequi

1. `POST /payments/wompi/nequi` con `phone` (10 dígitos colombianos).
2. Queda `pending`. La app muestra “Revisa la notificación en tu app Nequi y
   aprueba el pago” y hace polling con backoff + refresh manual.
3. Confirmación por webhook/reconciliación.

## DaviPlata (pendiente de habilitación comercial)

> DaviPlata está **desactivado por defecto** (`WOMPI_METHOD_DAVIPLATA=false`).
> Requiere habilitación comercial en la cuenta Wompi y validación del ciclo OTP
> en sandbox antes de producción.

1. `POST /payments/wompi/daviplata/start`.
2. OTP: `send-otp` → el usuario ingresa el código → `validate-otp` (no se guarda
   el OTP; hay límite de intentos/reenvíos y expiración) → `resend-otp`.
3. Estado final por consulta/webhook.

## Estados en la UI

- **Aprobado:** referencia, método, valor, fecha, membresía, últimos 4 y cuotas
  (si tarjeta).
- **Rechazado:** mensaje sanitizado + reintentar (nueva referencia; no se reusa
  un token vencido).
- **Pendiente:** mensaje por método, polling con backoff, refresco manual, opción
  de cerrar sin perder el pago.
- **Error:** mensaje controlado (sin stack/payload/secretos), reintento seguro.

## Reconciliación

`php artisan payments:wompi-reconcile` (agendado cada `WOMPI_RECONCILIATION_MINUTES`)
consulta los pagos en vuelo contra Wompi y resuelve los que no recibieron webhook
(app cerrada, red perdida). Activación idempotente si Wompi responde `APPROVED`;
expira por antigüedad o exceso de reintentos.

## Tienda

Mismo flujo con `purpose=store` y `amount=total` del carrito (sin plan). Tras la
aprobación se registra el pedido en la Caja del CRM y se vacía el carrito.
