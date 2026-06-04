# Membresía: renovación y cancelación (Bloque 3)

Modelo productivo (sin mocks) del ciclo de vida de la membresía. La verdad vive
en `users` (`plan`, `membership_start_date`, `membership_end_date`) y se amplía
con cuatro columnas:

| Columna | Significado |
|---------|-------------|
| `membership_auto_renew` | El miembro quiere renovar (intención). `false` tras cancelar. |
| `membership_cancellation_requested_at` | Cuándo se pidió cancelar. No borra datos. |
| `membership_cancellation_effective_at` | Hasta cuándo conserva acceso (= fin de periodo vigente). |
| `payment_provider_subscription_id` | Id de suscripción del proveedor de cobro recurrente (hook reservado). |

## Estados (derivados, sin columna de status → sin drift)

`MembershipService::status()`:

| Estado | Condición |
|--------|-----------|
| `none` | Sin plan. |
| `active` | Plan + dentro de periodo + sin cancelación. |
| `cancel_requested` | Plan + dentro de periodo + cancelación solicitada (conserva acceso). |
| `cancelled` | Cancelación solicitada + periodo expirado. |
| `expired` | Plan + periodo expirado sin cancelación. |

`can_access_home` (gate del Home) sigue activo mientras el periodo siga vigente,
**aunque** se haya solicitado la cancelación. Al expirar la fecha, deja de dar
acceso. Cancelar **nunca** borra la cuenta ni los datos.

## Endpoints

### Miembro (auth.member)
- `GET  /api/member/membership/status` — snapshot del ciclo de vida.
- `POST /api/member/membership/cancel-request` — vista previa (no muta): hasta
  cuándo conservaría el acceso.
- `POST /api/member/membership/cancel-confirm` — ejecuta la cancelación.
- `POST /api/member/membership/reactivate` — deshace la cancelación.

La cancelación **no exige OTP**: es reversible y no corta el acceso de inmediato
(a diferencia de borrar cuenta / desvincular dispositivo, que sí llevan 2FA).

### CRM admin (patrón del CRM, sin auth a nivel de ruta)
- `GET  /api/admin/memberships/{member}` — estado.
- `POST /api/admin/memberships/{member}/cancel` — `immediate=false` (default)
  programa el fin al término del periodo; `immediate=true` corta el acceso hoy.
- `POST /api/admin/memberships/{member}/reactivate` — reactiva la renovación.

## Cobro recurrente automático real (pendiente de proveedor)

El ePayco actual es **pago único**. El cobro recurrente verdadero (cargar la
tarjeta cada periodo sin intervención) requiere un proveedor de suscripciones
(**ePayco Suscripciones** o **Stripe Billing**). La arquitectura ya está lista
para enchufarlo sin reescribir el modelo:

1. Al crear la suscripción en el proveedor, guardar su id en
   `users.payment_provider_subscription_id`.
2. El webhook del proveedor (cobro de cada periodo) debe invocar
   `MembershipService::applyProviderRenewal($user, $durationDays, $subscriptionId)`,
   que extiende `membership_end_date` y limpia cualquier cancelación pendiente.
3. El evento de cancelación del proveedor debe llamar a `adminCancel($user, false)`.

**Qué falta para producción recurrente (intervención manual con credenciales):**
- Crear el producto/plan recurrente en el proveedor y sus precios.
- Variables de entorno del proveedor (claves) en el `.env` del servidor (no repo).
- Un controlador de webhook que valide la firma del proveedor y enrute a
  `applyProviderRenewal` / `adminCancel`.

Mientras tanto, la renovación ocurre por el **flujo de pago existente** (la app
o el CRM registran un pago aprobado y `EpaycoPaymentService::extendMembership`
extiende el periodo, acumulando sobre el saldo vigente).

## Flutter

`app-state` expone `membership` con `status`, `auto_renew`, `access_until`,
`days_remaining`, `has_recurring_subscription`. La app muestra el estado y
permite cancelar/reactivar la renovación con refresh de AppSync tras la acción.
No se corta el acceso hasta que expire el periodo.
