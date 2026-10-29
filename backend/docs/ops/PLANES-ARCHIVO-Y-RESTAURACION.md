# Planes: archivar, reactivar, no borrar con historia y restaurar uno borrado

Origen: el 16-10-2026 se borró el plan 22 «TOTAL ACCESS - ELITE». `PlanController::destroy` no tenía guarda y
`payments_plan_id_foreign` era ON DELETE SET NULL, así que 31 cobros se quedaron «sin plan» y la transferencia de
membresía de sus socias dejó de encontrar el origen. Contrato de la transferencia:
[TRANSFERENCIA-DE-MEMBRESIAS.md](TRANSFERENCIA-DE-MEMBRESIAS.md) §8.4.

## 1. Qué es archivar

Archivar deja el plan para su historia (cobros, traslados, traspasos, socios que lo tienen) y lo saca de la venta
y de la app. Pone `active=false`, `sellable=false` y `visible_in_app=false`, y rellena `archived_at`,
`archived_by` (FK `admins`, nullOnDelete) y `archive_reason`. El historial completo está en `audit_logs`.

**Por qué se puede apagar `active`.** No afecta a quien ya tiene el plan:

| Qué | Dónde | Mira `active` |
|---|---|---|
| Acceso (puerta, app, CRM) | `MembershipAccess::plansByName()` (MembershipAccess.php:694) | no: carga todos los planes |
| Funciones de la app | `Member::resolvedFeatures()` (Member.php:404), `MemberPayload::featuresFor()` (MemberPayload.php:48) | no: busca por nombre |
| IRON IA | `IronAiMembershipAccessService` (IronAiMembershipAccessService.php:109) | no: busca por nombre |
| Renovación automática | `RecurringBillingService` (RecurringBillingService.php:145) | no: cobra el importe congelado |
| Ficha y membresía del socio | `users.*` | no |

Solo lo leen las vías que VENDEN o llevan a alguien a ese plan, que es justo lo que un archivado no debe hacer.

**Un plan archivado no se vende por ninguna vía:**

| Vía | Rechazo |
|---|---|
| Cobro en mostrador `POST /api/payments` | 422 `errors.plan_id` (PaymentController.php:497, `PlanNotArchived`) |
| Plan a plazos `POST /api/admin/receivables/plan` | 422 `errors.plan_id` (ReceivableController.php:797) |
| Mejora `GET/POST /api/users/{u}/plan-upgrade` | `can_upgrade:false`, «Ese plan está archivado…» / 422 (PlanUpgrades.php:303) |
| Checkout de la app (Wompi: tarjeta, PSE, Nequi, Daviplata) | 422 `errors.plan_id` al crear el cobro (WompiPaymentController.php:272). El webhook de uno ya pagado sigue activando. |
| Suscripción automática nueva | `invalid_plan` (MembershipSubscriptionService.php:83) |
| Asesor comercial, IA y links de pago | `Plan::sellable()`/`isSellable()` excluyen archivados (Plan.php:269, :291); guarda `plan_archived` (SalesPaymentGuardrailService.php:111); `CreatePaymentLinkTool`:100, `UltronCommitService`:2385 y `AttributionContextService`:101 exigen `active` |
| Catálogo de la app `GET /api/membership-plans` | fuera del listado; `/{id}` → 404 (MembershipPlanController.php:15) |
| Traslado HACIA un archivado | 422 `to_plan_archived` (PlanTransferEvaluator.php:107). DESDE uno archivado, sí. |
| Editarlo con `active`/`visible_in_app` a true | 422 `plan_archived` (PlanController.php:214): eso es «Reactivar» |

Las renovaciones automáticas vivas de un plan archivado **siguen cobrando** su importe congelado: no se cortan y
no se le rompe la vigencia a nadie. La respuesta de archivar dice cuántas hay (`live_subscriptions`). El módulo
recurrente está apagado por defecto (`WOMPI_RECURRING_ENABLED`). Un cobro `pending` creado antes de archivar se
puede seguir confirmando.

## 2. API (CRM)

Escrituras: `plans.manage`, con sesión de persona (el token compartido recibe 403).

**`POST /api/plans/{plan}/archive`**. Cuerpo: `{reason}` (5–500).
- 200 `{ok: true, already: bool, plan, members_with_plan, live_subscriptions}`.
- Idempotente: si ya estaba archivado, `already: true` y no escribe nada.
- Auditoría: `action: status`, `metadata.operation: archive`, `metadata.reason`, `metadata.before`.

**`POST /api/plans/{plan}/reactivate`**. Cuerpo: `{reason (5–500), visible_in_app?: bool}` (por defecto true).
- 200 `{ok, already, plan}`. Quita el archivo y pone `active=true`, `sellable=true` y `visible_in_app` según el cuerpo.
- `already: true` si ya estaba activo, vendible y sin archivar.
- 422 `{ok: false, code: "plan_not_reactivable", message, reasons: [{code, message}]}`. Códigos:
  `plan_price_invalid`, `plan_duration_invalid`, `plan_entries_invalid`, `plan_name_invalid`,
  `plan_name_too_long` (>100, no cabe en `users.plan`), `plan_name_conflict` (otro plan vendible con el mismo
  nombre normalizado).
- No toca membresías, ni vigentes ni vencidas. Auditoría: `metadata.operation: reactivate` y
  `metadata.previous_archive`.

**`GET /api/plans?archived=include|exclude|only`** (pública).
- Por defecto `include`: todos los planes, como siempre.
- Cada plan añade `is_archived` (bool), `archived_at` (ISO | null), `archived_by_name` y `archive_reason`.
- Las dos últimas solo con sesión de administrador con `plans.view`; sin ella llegan en null.
- `archived_by` (id) nunca sale. `GET /api/plans/{id}` usa la misma forma.

**`DELETE /api/plans/{plan}`**. Sin dependencias, 204 como siempre. Con ellas, 409:

```
{ ok: false, code: "plan_has_history", message, can_archive: true, is_archived,
  dependencies: { payments, members, plan_transfers, membership_transfers, gateway_transactions, subscriptions },
  archive: { method: "POST", path: "/api/plans/{id}/archive" } }
```

- `payments` cuenta cualquier estado, `plan_id` o `upgraded_from_plan_id`.
- `members` cuenta `users.plan` por nombre normalizado.
- `plan_transfers` cuenta lotes de origen o de destino.
- En PostgreSQL la llave es además ON DELETE RESTRICT (migración `2026_10_31_100001`). SQLite sigue en SET NULL:
  cambiarla reconstruiría `payments` y perdería el WHERE del índice parcial.

**Cobros:** el `plan` del cobro trae `active` en `GET /api/admin/users/{user}/payment-history` (`{id, name,
duration_days, active}`) y en `/api/payments` (`{id, name, active}`). `false` = histórico.

## 3. Restaurar un plan borrado: `plans:restore-deleted`

```
php artisan plans:restore-deleted --plan=22 --snapshot=<ruta>/plan-22-snapshot.json \
  --sha256=932802840bed680dcb05e93451675f3e33d7967ee8297e824b89d64b33b84021 --actor=<admin_id>          # en seco
php artisan plans:restore-deleted … --commit                                                       # confirma
```

**Antes.** Requiere las migraciones `2026_10_31_100002` (archivo) y `2026_10_31_100003` (plan comprado en los
traspasos).

**En seco por defecto.** Hace todo y termina en ROLLBACK. En PostgreSQL corre en REPEATABLE READ, bajo un
cerrojo consultivo, y reintenta ante 40001 o un interbloqueo. Volver a ejecutarlo no hace nada.

**Precondiciones, bajo bloqueo:**
- el id y el nombre del snapshot coinciden con `--plan`;
- existe una sola auditoría `plan/delete` de ese id, con ese nombre;
- el plan no existe, o ya está archivado con ese nombre;
- la tarifa de IVA existe;
- el `--actor` es un administrador activo.

**Qué cobros se enlazan.** Solo los que tienen `plan_id` nulo, se crearon antes del borrado y cuya traza
`pago/create` declara ese plan y ningún otro. Los `MIGR-*`, los que no tienen traza y los de otro plan se informan
y no se tocan.

**Escrituras, comprobadas con `DB::listen`:**
- 1 INSERT del plan (valores del snapshot, mismo id, archivado con «Plan eliminado el DD-MM-AAAA y restaurado como
  histórico para conservar la trazabilidad de sus cobros»);
- 1 UPDATE `payments SET plan_id` con el query builder;
- una auditoría por cobro y otra por el plan, con el SHA-256 del snapshot.

**Comprobación antes y después, tabla entera:** `payments` (salvo ese `plan_id`), `plans`, `receivables`,
`receivable_payments`, `electronic_invoices`, `users`, `plan_transfer_batches`, `plan_transfer_items` y
`membership_transfers`. No se revierte ningún traslado ni se cambia ninguna membresía.

**La secuencia de `plans` se informa y no se toca.**
