# Wompi — Arquitectura de pagos (Iron Body)

Wompi (Bancolombia) es la **única pasarela activa**. La integración ePayco y el
Nequi-directo fueron retirados como rutas activas (ver `EPAYCO_REMOVAL_REPORT.md`);
sus registros históricos siguen siendo legibles.

## Principios

- **Laravel es la fuente de verdad.** El precio se recalcula desde `Plan::price`
  (membresía); Flutter nunca define el precio definitivo de una membresía.
- **PCI:** el número de tarjeta y el CVC se tokenizan **directamente en Flutter**
  con la **llave pública** (`POST {api_url}/tokens/cards`). Laravel **solo**
  recibe el `card_token`. El PAN/CVC jamás tocan el backend.
- **La membresía se activa solo por webhook/reconciliación**, nunca desde la app
  ni por una pantalla de retorno.
- **Idempotencia** en creación (referencia/`idempotency_key` únicos + `lockForUpdate`)
  y en webhook (dedupe por `payload_hash`).

## Componentes backend (`app/Services/Wompi/`)

| Clase | Responsabilidad |
|---|---|
| `WompiConfigValidator` | Valida en arranque que no se mezclen ambientes (sandbox `*_test_*` vs producción `*_prod_*`). |
| `PaymentStateMachine` | Estados internos y transiciones (lógica pura). |
| `WompiSignatureService` | Firma de integridad (crear tx) + verificación del checksum de webhook. |
| `WompiClient` | Único cliente HTTP a Wompi (timeouts, backoff solo GET, logs saneados, correlation-id). |
| `WompiAcceptanceService` | Tokens de aceptación (términos + tratamiento de datos), cache corto. |
| `WompiTransactionService` | Crear/reutilizar tx, transición con lock, activar membresía (idempotente). |
| `AbstractWompiPaymentService` + `Card/Pse/Nequi/Daviplata` | Cobro por método (token tarjeta, banco PSE, push Nequi, OTP DaviPlata). |
| `WompiWebhookService` | Valida checksum, dedupe, procesa `transaction.updated` en tx DB. |
| `WompiReconciliationService` | Respaldo del webhook: consulta `GET /transactions/{id}`. |

Activación de membresía: se reutiliza el **activador compartido**
`App\Services\Payments\PaymentMembershipActivator` (idempotente por
`payments.reference`), el mismo que ya existía.

## Estados internos (máquina de estados)

```
created → tokenizing → pending → requires_action → approved (T)
                                              ↘ declined (T)
                                              ↘ voided   (T)
                                              ↘ error    (T)
                                              ↘ expired  (T)
```

- Solo `approved` activa membresía.
- `approved` es absorbente; un terminal nunca regresa a un estado en vuelo.
- Mapeo Wompi → interno: `APPROVED→approved`, `DECLINED→declined`,
  `VOIDED→voided`, `ERROR→error`, `PENDING→pending` (o `requires_action` si la
  transacción trae URL de autenticación externa).

## Modelo de datos (migración aditiva)

`payment_transactions` se extiende (todo nullable; los registros ePayco
históricos siguen legibles): `uuid`, `environment`, `wompi_transaction_id`
(unique), `status_message`, `processor_response_code`, `customer_email/phone`,
`customer_legal_id[_type]`, `external_auth_url`, `redirect_url`, `*_at`
(approved/declined/voided/failed/expires), `last_reconciled_at`, `retry_count`,
`card_brand`, `card_last_four`, `installments`, `metadata`.

Tablas nuevas:
- `payment_webhook_events` — idempotencia de webhooks (único `(provider, payload_hash)`).
- `payment_consents` — auditoría de aceptación (términos + datos), ip, user agent.

`provider` distingue `wompi` de `epayco` (legado). `payments` (historial CRM)
no cambia: el activador escribe ahí `method=wompi`.

## Endpoints

Autenticados (`auth.member`, prefijo `payments/wompi`):
`config`, `acceptance`, `pse/institutions`, `card`, `pse`, `nequi`,
`daviplata/start`, `daviplata/{ref}/{send-otp,validate-otp,resend-otp}`,
`history`, `{reference}/status`.

Público: `POST /api/webhooks/wompi` (validado por checksum del evento).
