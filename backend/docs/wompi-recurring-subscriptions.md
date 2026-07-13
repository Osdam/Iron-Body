# Pago automático de membresías (Wompi payment sources) — Iron Body

Suscripción recurrente tipo Netflix para membresías, sobre Wompi. **Todo el módulo
está APAGADO por defecto** (`WOMPI_RECURRING_ENABLED=false`) y no afecta el flujo
de pago único existente. Este documento es la guía para pruebas en sandbox y el
checklist previo a producción.

> Estado: implementado y cubierto por tests; **no activado en producción**.
> Pendientes externos: activación **3DS/3RI con Wompi** y verificación de infra
> (queue worker + cron) en el VPS.

---

## 1. Arquitectura del flujo

Un cobro recurrente es una `PaymentTransaction` normal (provider=wompi, method=card,
con `payment_source_id`), por lo que **reutiliza todo el flujo Wompi existente**:
máquina de estados, webhook, reconciliación y activación de membresía.

**Entidades nuevas:**
- `wompi_payment_sources` — fuente tokenizada (solo referencias seguras: marca,
  últimos 4, expiración; **nunca PAN/CVC/token completo**).
- `membership_subscriptions` — autorización recurrente + `price_snapshot`
  (precio autoritativo congelado) + `next_charge_at` + reintentos.
- `subscription_events` — bitácora de auditoría (append-only).
- `payment_transactions` += `subscription_id`, `billing_period`, `is_recurring`,
  `wompi_payment_source_id` (unique `subscription_id + billing_period` = anti doble cobro).

**Servicios:**
- `WompiClient::{createPaymentSource,getPaymentSource,chargeWithPaymentSource}` —
  INERTES si el flag está apagado (no tocan la red).
- `WompiPaymentSourceService` — crea/consulta fuentes; mapea estados; solo tarjeta.
- `MembershipSubscriptionService` — crea suscripción + primer cobro; `markChargeApproved`
  y `markChargeFailed` (cierre central); `cancel`; `replacePaymentSource`.
- `RecurringBillingService` — cobro de vencidas (`chargeDue`), reintentos, `retryNow`.

**Punto central de cierre** (`WompiTransactionService::transitionTo`, aditivo):
- APPROVED + `subscription_id` → `markChargeApproved` (renueva + `next_charge_at`).
- DECLINED/ERROR/VOIDED/EXPIRED + `subscription_id` → `markChargeFailed`
  (escalera de reintentos → `past_due`).
Cubre webhook, reconciliación y cobro directo con un solo camino idempotente. En el
pago único `subscription_id` es null → no hace nada.

**Flujo de activación (primer cobro):**
1. App tokeniza la tarjeta con la llave PÚBLICA (`/v1/tokens/cards`) → `tok_...`.
2. `POST /memberships/subscriptions` con token + consentimientos.
3. Backend crea `payment_source`, la suscripción `pending_first_payment`, y cobra.
4. Solo si APPROVED → suscripción `active`, membresía extendida, `next_charge_at`
   = fecha de vencimiento real.

**Flujo de renovación:** `subscriptions:charge-due` (scheduler diario) selecciona
`active/past_due` con `next_charge_at <= now`, cobra con `payment_source_id`, y solo
APPROVED extiende. DECLINED/ERROR → reintenta +1/+3 días → `past_due`.

---

## 2. Feature flags (`config/wompi.php` → `recurring`)

| Flag | Default | Efecto |
|---|---|---|
| `WOMPI_RECURRING_ENABLED` | `false` | Apaga TODO el módulo (cliente inerte, sin scheduler, endpoints 503). |
| `WOMPI_RECURRING_SANDBOX` | `true` | Marca ambiente de pruebas. |
| `WOMPI_3DS_ENABLED` | `false` | 3D Secure para crear fuentes (requiere activación Wompi). |
| `WOMPI_3RI_ENABLED` | `false` | Transacciones automáticas autenticadas (solo Mastercard, activación Wompi). |
| `WOMPI_RECURRING_MAX_RETRIES` | `3` | Máx. reintentos antes de `past_due`. |
| `WOMPI_RECURRING_GRACE_DAYS` | `0` | Días de gracia de acceso tras fallo. |
| `WOMPI_RECURRING_RETRY_DAYS` | `1,3` | Escalera de reintentos (días). |
| `WOMPI_RECURRING_METHOD_CARD` | `true` | Tarjeta habilitada. |
| `WOMPI_RECURRING_METHOD_NEQUI` | `false` | Nequi modelado pero apagado. |

---

## 3. Variables .env (añadir en el `.env` de cada entorno)

```
WOMPI_RECURRING_ENABLED=false
WOMPI_RECURRING_SANDBOX=true
WOMPI_3DS_ENABLED=false
WOMPI_3RI_ENABLED=false
WOMPI_RECURRING_MAX_RETRIES=3
WOMPI_RECURRING_GRACE_DAYS=0
WOMPI_RECURRING_RETRY_DAYS=1,3
WOMPI_RECURRING_METHOD_CARD=true
WOMPI_RECURRING_METHOD_NEQUI=false
```

Las llaves Wompi (`WOMPI_PUBLIC_KEY`, `WOMPI_PRIVATE_KEY`, `WOMPI_INTEGRITY_SECRET`,
`WOMPI_EVENTS_SECRET`) ya existen para el pago único; el recurrente las reutiliza.

---

## 4. Migraciones pendientes (aplicar en el VPS al activar)

```
2026_07_12_000001_create_wompi_payment_sources_table
2026_07_12_000002_create_membership_subscriptions_table
2026_07_12_000003_create_subscription_events_table
2026_07_12_000004_add_subscription_fields_to_payment_transactions
```

Todas ADITIVAS y reversibles. La 000002 crea un índice único PARCIAL de Postgres
(`membership_subscriptions_one_live_per_member`) — requiere Postgres real.

```bash
php artisan migrate:status                 # ver pendientes
php artisan migrate --pretend              # simular (no escribe)
php artisan migrate                        # aplicar (solo cuando se autorice)
```

---

## 5. Endpoints

**Miembro (`auth.member`):**
- `POST /api/memberships/subscriptions/authorize` — datos para activar (no cobra).
- `POST /api/memberships/subscriptions` — crea + primer cobro.
- `GET  /api/memberships/subscriptions/current` — estado actual.
- `POST /api/memberships/subscriptions/{id}/cancel` — cancela renovación.
- `POST /api/memberships/subscriptions/{id}/payment-source` — reemplaza tarjeta
  (revoca la anterior; retry controlado si estaba `past_due`).

**Admin (`auth.admin`):**
- `GET  /api/admin/subscriptions` — listado (filtros status/member_id/past_due).
- `GET  /api/admin/subscriptions/{id}` — detalle (cobros + eventos).
- `POST /api/admin/subscriptions/{id}/retry` — reintento manual idempotente.
- `POST /api/admin/subscriptions/{id}/cancel` — cancelación auditada.

---

## 6. Pruebas en sandbox

```bash
# 1) En un .env de PRUEBAS (nunca prod): activar el módulo + llaves *_test_*
WOMPI_RECURRING_ENABLED=true
WOMPI_ENV=sandbox

# 2) Migrar y correr la suite
php artisan migrate
php artisan test --filter=Subscriptions

# 3) Forzar el scheduler de cobro manualmente
php artisan subscriptions:charge-due          # respeta el flag; resumen sin secretos

# 4) Tarjetas de prueba Wompi sandbox (tokenización desde la app):
#    Aprobada:  4242 4242 4242 4242
#    Declinada: 4111 1111 1111 1111  (ver docs.wompi.co)
#    3DS challenge (cuando se active): 2303 7799 5100 0446
```

**Nota 3DS/WebView:** con `WOMPI_3DS_ENABLED=false` (default) el primer cobro NO
usa challenge. Cuando Wompi active 3DS/3RI para el comercio, la creación de fuente
puede devolver `three_ds` (estado PENDING + `three_ds_method_data`); `wompi_payment_sources.three_ds_status`
ya persiste ese estado. La app deberá abrir el challenge en WebView SOLO si Wompi
lo requiere — flujo a implementar tras la activación (ver Bloque 8). No se implementa
un flujo inventado sin la activación oficial.

---

## 7. Verificación en el VPS (infra requerida para el cobro automático)

```bash
# Cron del scheduler Laravel (debe existir):
crontab -l | grep "schedule:run"
# Esperado: * * * * * cd /ruta && php artisan schedule:run >> /dev/null 2>&1

# Worker de la cola activo (supervisor/systemd):
systemctl status laravel-queue        # o: supervisorctl status
php artisan queue:failed              # revisar trabajos fallidos

# Confirmar que el comando quedó agendado (solo con el flag on):
php artisan schedule:list | grep subscriptions:charge-due
```

Sin cron + worker, el cobro recurrente NO corre. El webhook y la reconciliación
sí funcionan sin worker si están síncronos, pero el scheduler es imprescindible.

---

## 8. Qué pedir a Wompi (bloqueante para producción con tarjeta)

- **Activación de 3DS** para fuentes de pago (Visa/Mastercard) con el equipo de
  gestión de fraude.
- **Activación de 3RI** para transacciones automáticas (MIT sin presencia) —
  **solo Mastercard**.
- Confirmar límites/franquicias habilitadas para el comercio.

Sin esto, un cobro recurrente de tarjeta puede ser declinado por el emisor.

---

## 9. Métodos NO habilitados para pago automático

- **PSE, DaviPlata, Bancolombia**: no soportan cobro desatendido → rechazados.
- **Nequi**: modelado pero apagado por flag (`WOMPI_RECURRING_METHOD_NEQUI=false`).

Solo **tarjeta** está habilitada.

---

## 10. Checklist antes de producción

- [ ] 3DS/3RI activados con Wompi (fraude) — confirmado por escrito.
- [ ] `.env` de prod con las variables `WOMPI_RECURRING_*` (aún con `ENABLED=false`).
- [ ] `php artisan migrate` aplicado en el VPS (4 migraciones).
- [ ] Cron `schedule:run` activo.
- [ ] Queue worker activo (supervisor/systemd) + monitoreo.
- [ ] Pruebas end-to-end en sandbox: activar, cobrar, declinar, reintentar, past_due,
      actualizar tarjeta, cancelar.
- [ ] Revisar que el pago único sigue intacto (suite verde).
- [ ] Activar `WOMPI_RECURRING_ENABLED=true` de forma gradual (feature flag).
- [ ] Monitorear `subscription_events` y logs `subscriptions.*` los primeros días.

---

## 11. Rollback

El módulo es reversible y aislado:

1. **Apagado inmediato:** `WOMPI_RECURRING_ENABLED=false` → cliente inerte, sin
   scheduler, endpoints 503. El pago único no se ve afectado. No requiere deploy de
   código si solo se cambia el `.env` (+ `php artisan config:clear`).
2. **Datos:** las suscripciones dejan de cobrarse; la membresía vigente corre hasta
   su vencimiento (no se corta acceso).
3. **Esquema (si fuese necesario):** `php artisan migrate:rollback` revierte las 4
   migraciones (drop tablas + columnas aditivas). No borra datos del pago único.
4. **Código:** revertir la rama `feat/wompi-recurring-subscriptions` sin tocar el
   flujo de pago único (los cambios en `WompiTransactionService`/`WompiClient` son
   aditivos y guardados por `subscription_id`/flag).
