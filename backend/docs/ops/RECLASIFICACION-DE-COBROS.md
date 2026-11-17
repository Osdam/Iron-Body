# Reclasificación de cobros: contrato de implementación

Estado: **en implementación** (rama `fix/subsanacion-pago-20209`, base `7f9219d5` = producción `5267b0ab` + docs).

## 1. El problema que resuelve

Un dinero que **sí entró** se registró con el concepto equivocado. El caso que lo motivó:
- **Qué se registró.** El cobro de mostrador `payments.id 20209` (50.000 COP, efectivo, 06-11-2026 20:43 en Bogotá, turno de gimnasio 237 ya cerrado, registrado por el admin 9) se registró como venta del plan 20 «Comisión de personalizados».
- **Qué era.** El piso mensual del personalizado que un entrenador externo presta a la socia `members.id 862` / `users.id 863`.
- **Lo que provocó:**
  - 30 días de membresía indebidos: la Anualidad 20210 quedó encadenada detrás;
  - un borrador de factura «Membresía Comisión de personalizados» (`electronic_invoices.id 1399`, `pending`, nunca enviado);
  - un ingreso clasificado como venta de plan;
  - y el pago no aparece en Control de personalizados.
- **Otros datos del caso:**
  - El entrenador NO existe en `trainers`: es socio (`members.id 7516` / `users.id 7517`, plan «ACCESO EMPLEADOS», sin identidad).
  - El recibo térmico original es RG-000402 (`receipts.id 615`), inmutable.

## 2. Decisión: reversión documentada + asiento compensatorio en la fecha y el turno originales

El modelo actual no permite traspasar la clasificación dentro del mismo movimiento: un `payments` es una venta de plan, y un piso es deuda + abono. Por eso, en UNA transacción:

1. **Revertir.** Se anula el cobro original por la **vía oficial** (`PaymentController::update` → `applyUpdate`, estado `cancelled`):
   - devuelve la membresía con `MembershipPeriod::revert`, que recalcula los periodos posteriores con la regla del sistema;
   - escribe la auditoría financiera y la corrección posterior al cierre.
2. **Registrar lo correcto:**
   - entrenador (alta oficial a partir de los datos de su ficha de socio);
   - acuerdo (piso, paga la socia, 50.000, 30 días, `starts_on` = día económico del cobro);
   - cobro del mes por la vía oficial `TrainerCommissions::charge()` (abre la deuda sin cobrar);
   - **abono histórico que salda la deuda en el MISMO turno (237), con la fecha económica original y atribuido a quien recibió el dinero (admin 9)**.

   El abono no se registra con `settle()` porque exige turno abierto: se inserta replicando exactamente lo que escribe `settle()`.
3. **Constancia** en `payment_reclassifications`:
   - inmutable, una por cobro;
   - fechas separadas: `economic_at` (cuándo entró el dinero) y `performed_at` (cuándo se corrigió);
   - enlaces: pago original, acuerdo, cobro, deuda, abono, recibo original y factura cancelada;
   - vigencia antes y después;
   - cifras de antes y después.
4. **Factura.** Se cancela el borrador `pending` del concepto equivocado con la lógica de `billing:cancel-test-requests` (sin CUFE ni número, `invoice_requested = false`). Nunca se emitió.

**El abono histórico usa como `created_at` la fecha económica.** Todos los lectores de dinero fechan los abonos por `created_at` (arqueo por turno, día de negocio en Bogotá, MoneyLedger). Ponerle la fecha de hoy movería el ingreso del 06-11 a hoy, cosa prohibida. La fecha real de la corrección queda en `payment_reclassifications.performed_at`, en `audit_logs` y en el `updated_at` de las filas tocadas.

## 3. Invariantes que el procedimiento comprueba ANTES de confirmar

Si alguno falla: ROLLBACK, código de salida ≠ 0 y nada escrito.

- **Turno 237.** `CashShiftTotalsService` en vivo coincide con el snapshot congelado: `sales_total` 1.749.002, `cash_sales_total` 1.259.001, `transfer_total` 490.001, `operations_count` 23. Y `CashShiftReport.consistency.matches = true`.
- **Día económico** (06-11, Bogotá). Recaudado total y por medio (CashReceipts / MoneyLedger) iguales antes y después.
- **Pagos.**
  - El pago original queda `cancelled` y sin periodo.
  - Los pagos posteriores de esa persona quedan recalculados por `MembershipPeriod::revert`: el 20210 debe quedar del 2026-11-09 al 2027-11-09, y `users.membership_end_date` igual a lo que diga `PaymentCorrections::preview` antes de aplicar.
  - Los pagos anteriores, intactos.
- **Deuda.** Queda saldada (saldo 0) y no hay ninguna otra deuda abierta nueva.
- **Sin efectos colaterales:**
  - 0 `receipts` nuevos, 0 `cash_refunds`, 0 `payments` nuevos;
  - ninguna fila de otros usuarios ni de otros turnos tocada;
  - escrituras solo en una lista blanca de tablas, comprobada con `DB::listen`.
- **Idempotencia.** Si ya existe `payment_reclassifications.payment_id`, el procedimiento informa «ya aplicada» y no escribe. La clave única de la base lo garantiza también con dos ejecuciones a la vez.

## 4. Prevención

- **Planes por canal.**
  - **Mostrador** (cobro, plan a plazos, mejora): activo ∧ no archivado ∧ `counter_sellable` (bandera nueva, «Se cobra en recepción», que nace en `true`; los archivados en `false`).
  - **App** (catálogo, Wompi, suscripciones): `isSellable()` ∧ `isVisibleInApp()`.
  - **Comercial** (ULTRON, links): sin cambio (`isSellable()`).
  - El plan 20 se archiva (`PlanArchive`) solo si no deja sin acceso a nadie. Si no es seguro (en producción hay un registro antiguo con ese plan vigente hasta 2027), se queda ACTIVO pero con «Se cobra en recepción» = no, por la edición oficial y auditada del plan: no se puede volver a vender por ninguna vía y nadie pierde acceso.
- **Panel de personalizados.** Respeta la vigencia (`starts_on`/`ends_on`, NULL = sin límite) y conserva el histórico. No permite cobrar fuera de vigencia. Totales: pactado = cobrado + pendiente + importe sin registrar.
- **REC-1001.** El CRM deja de autogenerar la referencia: ese campo es solo para la referencia externa (número de transferencia o voucher). El número interno único es el del recibo (RG-…) y `payments.id`. No se renumera nada histórico.

## 5. Contrato de API (backend ↔ CRM)

- **Panel** `GET /api/admin/commissions/board`:
  - `rows[]` añade `in_force` (bool), `starts_on`, `ends_on`.
  - `rows[].charge.reclassified_from` = `{reclassification_id, payment_id, receipt_number, performed_at}` | null.
  - `totals` añade `unregistered_amount`.
  - Cobrar fuera de vigencia: 422 con mensaje.
- **Planes** `GET /api/plans`:
  - añade `counter_sellable` y `sellable_at_counter` (bool calculado);
  - `PUT/PATCH /api/plans/{id}` acepta `sellable` y `counter_sellable` (permiso `plans.manage`, auditado).
- **Pagos:**
  - las filas de los listados y del historial añaden `reclassified` (bool) y `reclassification` = `{id, label, kind, performed_at, economic_at, trainer_name, period_label, charge_id, receivable_payment_id}` | null;
  - `/admin/payments/stats.total_amount` deja de sumar estados de cobro fallido o anulado.
- **Portada** `/dashboard.revenue`: se calcula con `CashReceipts` (pagos pagados que no son devengo + abonos aplicados).
- **Turno** `GET /api/admin/caja/shifts/{id}`:
  - `post_close_corrections[]` incluye entradas `type: 'reclassification'` (`payment_id`, `receivable_payment_id`, `amount`, `economic_at`, `performed_at`, `performed_by_name`, `reason`);
  - las filas de cobro añaden `reclassified`; las de abono, `reclassified_from_payment_id` y el deudor como socio, no el cajero.
  - El PDF imprime el estado de los cobros no pagados (ANULADO / RECLASIFICADO).
- **Recibos.** Un abono de reclasificación no es imprimible: motivo `reclassified`, con mensaje que cita el recibo original.

## 6. Reparto de archivos (nadie toca los de otro)

- **BE-A, personalizados + canales + KPIs:**
  - `app/Services/Commissions/TrainerCommissions.php`
  - `app/Http/Controllers/Api/Admin/TrainerCommissionController.php`
  - `app/Models/TrainerCommissionAgreement.php`
  - `app/Models/Plan.php`
  - `app/Rules/PlanSellableAtCounter.php`
  - `app/Http/Controllers/Api/PaymentController.php` (validación de `store`, `stats` y bandera `reclassified` en listados; **no** `update`/`applyUpdate`)
  - `app/Http/Controllers/Api/Admin/ReceivableController.php`
  - `app/Services/Payments/PlanUpgrades.php`
  - `app/Http/Controllers/Api/WompiPaymentController.php`
  - `app/Services/Subscriptions/MembershipSubscriptionService.php`
  - `app/Http/Controllers/Api/MembershipPlanController.php`
  - `app/Http/Controllers/Api/PlanController.php`
  - `app/Services/Membership/PlanArchive.php`
  - `app/Services/Membership/DeletedPlanRestorer.php`
  - `routes/api.php` (`/dashboard`)
  - migración `2026_11_18_100002_add_counter_sellable_to_plans_table.php`
  - sus pruebas.
- **BE-B, reclasificación + informes:**
  - `app/Services/Payments/PaymentReclassifier.php` (nuevo)
  - `app/Console/Commands/ReclassifyPaymentToCommissionCommand.php` (nuevo)
  - `app/Console/Commands/BillingCancelTestRequestsCommand.php` (y el servicio que se extraiga)
  - `app/Services/Caja/ReceiptSourceResolver.php`
  - `app/Services/Caja/CashShiftReport.php`
  - `app/Services/Caja/CashShiftPdfService.php`
  - `app/Services/Reports/MoneyLedger.php`
  - `app/Http/Controllers/Api/MemberPaymentHistoryController.php`
  - `app/Http/Controllers/Api/AppPaymentController.php`
  - este documento;
  - sus pruebas.
- **FE, CRM:** `src/app/modules/commissions.ts`, `src/app/services/api.service.ts`, `src/app/modules/payments.ts`, `src/app/modules/components/plan-upgrade-modal.ts`, `edit-plan-modal.ts`, `create-plan-modal.ts`, `plan-card.ts`, `settings-payments.ts`, `src/app/modules/settings.ts`, `member-details-modal.ts`, `cash-shift-report.ts` y `correction-model.ts`.
- **Ya escritos por el líder:** la migración `2026_11_18_100001_create_payment_reclassifications_table.php`, el modelo `PaymentReclassification` y las relaciones `reclassification()` en `Payment`, `ReceivablePayment` y `TrainerCommissionCharge`.

Firma que BE-A expone para BE-B:
```php
TrainerCommissions::createAgreement(Trainer $trainer, Member $member, array $data, ?Admin $actor = null): TrainerCommissionAgreement
// $data: amount, payer ('member'|'trainer'), cycle_days, blocks_app, starts_on?, ends_on?, notes?
// Las mismas reglas que POST /api/admin/commissions/agreements: activo duplicado → RuntimeException; inactivo → se reabre.
```

## 7. El procedimiento: `payments:reclassify-to-commission`

Código: `app/Services/Payments/PaymentReclassifier.php` (la lógica) y `app/Console/Commands/ReclassifyPaymentToCommissionCommand.php` (el borde: valida las opciones y pinta el informe). Es genérico: nada del caso 20209 está escrito en el código.

### 7.1 Uso

```bash
# Ensayo (por defecto): hace TODO dentro de una transacción y termina en ROLLBACK.
php artisan payments:reclassify-to-commission \
  --payment=20209 --member=862 --trainer-from-member=7516 \
  --actor=<id del admin que firma> \
  --reason="Era el piso mensual del entrenador de la socia, no la venta del plan Comisión"

# Confirmar: lo mismo con --apply. Solo hace COMMIT si TODAS las invariantes se cumplen.
php artisan payments:reclassify-to-commission … --apply

# Informe para máquinas (sin datos personales): --json
```

| Opción | Obligatoria | Por defecto | Qué es |
|---|---|---|---|
| `--payment` | sí | — | el cobro (`payments.id`) |
| `--member` | sí | — | ficha `members` de la clienta del acuerdo; tiene que ser la titular del `user_id` del cobro |
| `--trainer` / `--trainer-from-member` | una de las dos | — | el entrenador existente, o la ficha de socio de la que sale (ver §7.3) |
| `--actor` | sí | — | `admins.id` activo que firma |
| `--received-by` | no | `payments.registered_by` | quién recibió el dinero; firma el abono. Si el cobro dice quién lo registró, tiene que ser esa persona (`received_by_mismatch`) |
| `--starts-on` | no | día de negocio (Bogotá) de `paid_at` | inicio del acuerdo |
| `--cycle-days` | no | 30 | días del piso (1-366) |
| `--payer` | no | `member` | `member` \| `trainer` |
| `--blocks-app` | no | 0 | 1 si la deuda vencida le retira la app a la socia |
| `--reason` | sí | — | 10-500 caracteres; queda en la anulación, la constancia y las trazas |
| `--archive-plan` | no | no | archiva el plan del cobro solo si es seguro; si no, bloquea su cobro en recepción (§7.3, paso 9) |
| `--apply` | no | no | confirma |
| `--json` | no | no | informe JSON |

**Permisos del actor.** Por consola no corre el middleware, así que el procedimiento los comprueba él mismo con `CrmPermission`: `payments.cancel`, `cash.gym.manage` (el turno está cerrado), `commissions.manage` y `commissions.charge`; además `trainers.manage` si tiene que dar de alta al entrenador, y `plans.archive` y `plans.manage` con `--archive-plan` (el segundo, por si archivar no es seguro y hay que bloquear su cobro en recepción con la edición oficial). Un actor inactivo se rechaza. La constancia guarda además quién ejecutó la orden en el servidor (`after_snapshot.executed_by`: usuario del sistema, `SUDO_USER` y equipo), no solo el actor que la firma.

**Requisitos en producción.** La migración `2026_11_18_100001` aplicada (con el rol dueño, ver la nota de migraciones). El procedimiento solo hace DML: corre con el usuario de la aplicación.

### 7.2 Salida

| Código | `status` | Qué pasó | ¿Escribió? |
|---|---|---|---|
| 0 | `dry_run` | ensayo correcto, todas las invariantes cumplidas | no (ROLLBACK) |
| 0 | `applied` | aplicada | sí (COMMIT) |
| 0 | `already_applied` | el cobro ya tenía su constancia | no |
| 1 | `rejected` | opción inválida (`invalid_options`) o precondición no cumplida (`problem.code`, p. ej. `shift_open`, `invoice_not_pending`, `membership_chain_broken`, `actor_forbidden`, `trainer_ambiguous`) | no |
| 2 | `invariant_failed` | alguna invariante falló (`invariants[].ok = false`) | no (ROLLBACK) |
| 3 | `error` | error inesperado; el detalle va al log, no al informe | no (ROLLBACK) |

El informe trae: ids creados (`created`), la vigencia antes/después y lo que dijo la vista previa (`membership`), la factura, el plan, las fotos `before`/`after` (turno: congelado y en vivo; día económico: total, por fuente, por medio y «lo que no entró»; cobros de la socia; deudas abiertas; recibos; devoluciones), la lista de invariantes y las escrituras contadas por tabla. Sin nombres, documentos ni teléfonos.

Salida real del ensayo sobre el caso sintético (base PG desechable, cobro a las 20:43 de Bogotá = 01:43 UTC del día siguiente, turno cerrado a las 21:30):

```
RECLASIFICACIÓN DE COBRO · payments:reclassify-to-commission@1 · ENSAYO (ROLLBACK)
Cobro #2 → piso de entrenador
Resultado: dry_run (salida 0). Ensayo correcto: todas las invariantes se cumplen. Se hizo ROLLBACK: nada quedó escrito. …
Turno #2 (closed, cerrado 2026-11-07T02:30:00+00:00) · día económico 2026-11-06 (Bogotá)
| sales_total      | 650000.00 | 650000.00 | 650000.00 |   (congelado | en vivo antes | en vivo después)
| cash_sales_total | 50000.00  | 50000.00  | 50000.00  |
| operations_count | 2         | 2         | 2         |
| Recaudado          | 650000 | 650000 |
| Fuente: membership | 650000 | 600000 |
| Fuente: installment| 0      | 50000  |
| Lo que no entró (anulados) | 0 | 0 |
| Reclasificado (sí entró)   | 0 | 50000 |
Vigencia de la socia: 2027-12-09 → 2027-11-09 (30 días menos; la vista previa decía 2027-11-09 y 30 días).
Entrenador #1 (alta nueva, sin roles) · acuerdo #1 · cobro del mes #1 (2026-11-01, cubre 2026-11-06 → 2026-12-05) · deuda #1 · abono #1 en el turno #2 · recibo original RG-000001 (sin recibo nuevo).
Factura #2: pending → cancelled.
(26 invariantes, todas OK)
```

### 7.3 Qué escribe, exactamente

En UNA transacción, en este orden. En PostgreSQL toma primero `pg_advisory_xact_lock` (dos reclasificaciones nunca corren a la vez) y después los cerrojos de `PaymentController::applyUpdate`: turno (compartido) → socio → cobro.

1. **Idempotencia.** Si ya existe `payment_reclassifications.payment_id`: «ya aplicada», nada escrito. Se comprueba antes de abrir la transacción y otra vez bajo cerrojo; la clave única es la última red.
2. **Precondiciones** (si falla una: código 1 y nada escrito): cobro `paid`, de mostrador, no devengo, no pasarela, no mejora, con importe, fecha y socio; turno del gimnasio CERRADO, con totales congelados y cuadrando con su cierre y con su informe; partes replicables; ninguna factura que no sea un borrador `pending`/`cancelled` sin CUFE ni número, ninguna nota crédito, ningún lote manual, `invoice_requested = false`; sin mejora dependiente, traspaso vivo, deuda viva ni congelación; sin ajustes de días posteriores al cobro; la ficha `--member` es la titular; `PaymentCorrections::preview(…, 'cancel', …)` permitido y, si el cobro tenía periodo, devolviendo días (si devuelve 0, la cadena no explica el vencimiento: `membership_chain_broken`).
3. **Entrenador.** `--trainer`, o el que ya existe con el MISMO documento normalizado (por su ficha o por su identidad); con dos o más, se rechaza (`trainer_ambiguous`). Si no hay ninguno: alta oficial (`trainers`: nombre, documento y teléfono de la ficha, contrato «Independiente», **inactivo**) y `TrainerProfileService::linkIdentityAndRoles($t, null, 'payments_reclassify')`: identidad por documento (`identities`) y traza (`trainer_audit_logs`), SIN roles. La ficha de socio NO se enlaza a la identidad (decisión del 15-10, `MemberOwnershipLink`). **Inactivo a propósito:** es un entrenador EXTERNO; su acuerdo y sus pisos funcionan igual (el módulo no exige que esté activo), pero no sale en el listado público de entrenadores (`GET /api/trainers`, sin sesión) ni puede abrir sesión en el portal (el login exige un entrenador activo). Si el gimnasio quiere mostrarlo o darle portal, se activa en Entrenadores y se le asignan roles allí. El aviso «Nuevo entrenador registrado» (`notifications`) se escribe DESPUÉS del commit, como en `POST /api/trainers`.
4. **Acuerdo.** `TrainerCommissions::createAgreement()`: importe del cobro, `payer`, `cycle_days`, `blocks_app`, `starts_on` y una nota que cita el cobro y su recibo; su traza la escribe el servicio.
5. **Anulación oficial.** `PaymentController::update()` con `status=cancelled`, el motivo, `REMOTE_ADDR` 127.0.0.1, el User-Agent del procedimiento y el actor: `payments` (estado y periodo), la cadena posterior recalculada por `MembershipPeriod::revert`, `users` (vencimiento), dos trazas (`pago`/`status` y `membership`) y las señales de tiempo real de la socia (`member_realtime_events`, se entregan al confirmar).
6. **Cobro del mes.** `TrainerCommissions::charge(acuerdo, mes económico, method: null, …, desde: coverageStartFor)`: abre la deuda (`receivables`, con su traza) y el cobro (`trainer_commission_charges`). Después se fecha `created_at` de los dos con `paid_at` original; su `updated_at` es la hora real. Se escribe la traza que dejaría el controlador al abrir la deuda.
7. **Abono histórico** (`receivable_payments`, y `receivable_payment_splits` si el cobro era mixto): replica `ReceivableService::settle()` —deuda bloqueada, saldo antes/después, medio como lo normaliza `settle()`, estado de la deuda recalculado (`paid`) y la traza `receivablePaid`— en el TURNO ORIGINAL, con `created_at = paid_at`, firmado por `--received-by` y con `client_request_id = reclass:payment:{id}`.
8. **Factura.** El borrador `pending` se cancela con `PendingInvoiceCancellation` (la regla de `billing:cancel-test-requests`): `status = cancelled`, `retry_allowed = false`, motivo «El cobro #… se reclasificó a piso de entrenador…» y su línea en `electronic_invoice_logs`.
9. **Plan** (`--archive-plan`). Solo si ningún cobro pagado con ese plan tiene periodo vigente o futuro Y archivarlo no le cambia el estado de `MembershipAccess` a nadie que lo tenga como `users.plan` con vencimiento futuro (se calcula antes y después, dentro de un punto de guardado; si cambia, se deshace solo el archivo). Lo archiva `PlanArchive`. Si no es seguro, NO se archiva y se pone «Se cobra en recepción» = no con la edición oficial (`PlanController::update`: valida, bloquea la fila y audita el antes y el después); el informe dice por qué y `plan_archive.counter_blocked = true`.
10. **Constancia** (`payment_reclassifications`) con todos los enlaces, `economic_at = paid_at`, `performed_at = ahora`, vigencia antes/después, días, actor, `procedure = payments:reclassify-to-commission@1` y las fotos antes/después sin datos personales; y su traza (`audit_logs`, `pago`/`create`, `metadata.event = payment.reclassified`).
11. **Invariantes** (ver el informe) y COMMIT o ROLLBACK.

**Lista blanca** (comprobada con `DB::listen`, sentencia a sentencia): `payments`, `users` (UPDATE, solo del cobro y de la socia), `audit_logs`, `member_realtime_events` (solo de la socia), `trainers`, `identities`, `trainer_audit_logs`, `trainer_commission_agreements`, `receivables`, `trainer_commission_charges`, `receivable_payments`, `receivable_payment_splits`, `employee_accesses` (solo del entrenador, ver §9), `electronic_invoices` (solo el borrador), `electronic_invoice_logs`, `plans` (solo con `--archive-plan` y solo ese plan) y `payment_reclassifications`. Las tablas de caché (`cache`, `cache_locks`) no cuentan: con `CACHE_STORE=database` la caché de permisos puede renovarse a mitad del procedimiento y no es un dato de negocio. Cualquier otra escritura, o un UPDATE/DELETE de una fila ajena, rompe la invariante `escrituras.lista_blanca`. Nunca escribe en `receipts`, `cash_refunds`, `cash_shifts` ni crea `payments`.

Escrituras medidas en el caso sintético: `3× delete member_realtime_events, 7× insert audit_logs, 1× insert electronic_invoice_logs, 1× insert identities, 3× insert member_realtime_events, 1× insert payment_reclassifications, 1× insert receivable_payments, 1× insert receivables, 1× insert trainer_audit_logs, 1× insert trainer_commission_agreements, 1× insert trainer_commission_charges, 1× insert trainers, 1× update electronic_invoices, 3× update payments, 2× update receivables, 1× update trainer_commission_charges, 1× update trainers, 1× update users`.

## 8. Cómo verificar

**Antes:** el ensayo, con `--json`, tiene que salir con 0, `status: dry_run` y todas las invariantes `ok`. **Después de `--apply`** (lecturas, nada escribe):

```sql
SELECT id, status, period_start, period_end FROM payments WHERE user_id = 863 ORDER BY id;
-- 20209 cancelled y sin periodo; 20210 del 2026-11-09 al 2027-11-09; los anteriores, como estaban.
SELECT membership_end_date FROM users WHERE id = 863;            -- lo que dijo la vista previa
SELECT * FROM payment_reclassifications WHERE payment_id = 20209; -- una fila, con todos los enlaces
SELECT id, cash_shift_id, amount, method, status, balance_after, created_at, created_by, client_request_id
  FROM receivable_payments WHERE client_request_id = 'reclass:payment:20209'; -- turno 237, 06-11 20:43 Bogotá, admin 9
SELECT id, status FROM receivables WHERE id = (SELECT receivable_id FROM payment_reclassifications WHERE payment_id = 20209); -- paid
SELECT id, status, retry_allowed FROM electronic_invoices WHERE id = 1399;   -- cancelled, false
SELECT sales_total, cash_sales_total, transfer_total, operations_count FROM cash_shifts WHERE id = 237; -- sin cambios
```

Y por la API: `GET /api/admin/caja/shifts/237` (`consistency.matches = true`; el cobro con `reclassified: true`; el abono con `reclassified_from_payment_id` y la socia como deudora; `post_close_corrections` con la entrada `type: 'reclassification'`); el PDF del turno (el cobro sale «RECLASIFICADO · no suma…» y el abono «Reclasificación del cobro #…»); `POST /api/admin/receipts` con el abono responde 422 `not_printable`/`reclassified`; `GET /api/admin/users/863/payment-history` (`reclassified: true`); Informes → «Lo que no entró» del 06-11 sin ese cobro y con `reclassified_payments`. Una segunda ejecución sale con 0 y `already_applied`.

## 9. Informes y vistas: qué cambia y por qué

- **Turno** (`CashShiftReport`): las filas de abono dicen el DEUDOR (socio por su ficha; entrenador o personal por `DebtorDirectory::resolveMany`, sin consulta por fila), ya no el cajero; las de cobro llevan `reclassified`; las de abono `reclassified_from_payment_id`; `post_close_corrections` añade las entradas `type: 'reclassification'` (con `payment_id`, `receivable_payment_id`, `amount`, `economic_at`, `performed_at`, `performed_by_name`, `reason`, y además `at`, `summary`, `actor_name` para quien no conozca el tipo), ordenadas por fecha junto a la anulación del cobro, que sigue saliendo con su motivo. La traza propia de la reclasificación es `create`, así que no se lista dos veces.
- **PDF** (`CashShiftPdfService`): debajo de cada cobro o abono que no suma, su estado: «RECLASIFICADO · no suma: su dinero sigue en este turno como abono», «ANULADO · no suma», «DEVUELTO · entró aquí y salió por una devolución», «FALLIDO», «PENDIENTE»; y el abono de una reclasificación, «Reclasificación del cobro #…: el mismo dinero, no un cobro nuevo».
- **Recibos** (`ReceiptSourceResolver`): el abono de una reclasificación no es imprimible: motivo `reclassified`, «Este dinero ya tiene comprobante: RG-… (cobro #… reclasificado)». Las reclasificaciones se leen una vez por turno (la vista de recibos del turno no paga una consulta por abono). El recibo original sigue siendo EL recibo de ese dinero.
- **Dinero** (`MoneyLedger`): «Lo que no entró» (`reversals().cancelled_payments`) excluye los cobros reclasificados —ese dinero sí entró— y los enseña aparte en `reversals().reclassified_payments`. El listado de abonos lleva `reclassified_from_payment_id`. Totales, medios, serie y equipo no cambian: el dinero pasa de «Membresías» a «Abonos» el mismo día y con el mismo cajero.
- **Historial del socio** (`MemberPaymentHistoryController`, ruta real `app/Http/Controllers/Api/Admin/`): el cobro sale con `reclassified: true`, `status_group: 'reclassified'` y `reclassification` (contrato §5). **Decisión: cuenta en `total_paid`** (lo pagó la socia y sigue en la caja; el abono no es un cobro de `payments`, así que no se cuenta dos veces) y no en `payments_count` (cobros de membresía). `summary` añade `reclassified_count` y `reclassified_amount`. El piso se ve en la fila: entrenador, mes, cobro y abono.
- **App** (`AppPaymentController`, la app 2.0.x no se toca): la app solo distingue `approved` (verde, «Pago confirmado»), `failed`/`cancelled`/`expired` (rojo, «Pago no realizado — El pago no se completó») y lo demás (ámbar, «Pago en verificación» con un botón que no cambiaría nada). **Decisión:** el cobro reclasificado se le enseña como `approved`, con `product` y `description` = «Piso de entrenador personalizado · noviembre de 2026», `reason = null` y `membership_expiry = null` (el piso no da membresía); se añaden `reclassified` y `reclassification` (`kind`, `label`, `period_label`, `economic_at`), que la app publicada ignora. Esconder la fila haría desaparecer un dinero que la socia pagó; dejarla `cancelled` le diría que no pagó.
- **En vigor.** Todas estas marcas dependen de que el cobro siga ANULADO (`PaymentReclassifier::inEffect()`): si se compensara (§10) y el cobro volviera a `paid`, se pinta como la venta de plan que vuelve a ser. La constancia y la entrada de `post_close_corrections` se quedan: son historia.
- **`CommissionAccessObserver`.** El abono lo dispara igual que `settle()`: recalcula el acceso de empleado del entrenador. Para un entrenador SIN ficha de socio enlazada a su identidad (el caso: socio sin identidad) no escribe nada; si la tuviera, abre `employee_accesses` (`source = commission`) hasta el fin del tramo pagado (`covers_to`), solo para su usuario.

**Otros lectores que cuentan anulados, revisados (no son de BE-B y no se tocan):** `PaymentController::crmStats` (BE-A: `failed_count` lo cuenta como anulado, que lo es, y `total_amount` deja de sumar fallidos); `ReportsOverviewController::paymentsByStatus` (cuenta estados, no dinero: el cobro está anulado); `PaymentsExport` (la columna de estado dice «Anulado»); `IronCrmToolService::listPayments` (filtro `failed`); `SalesInsights`/`MembershipInsights` (ventas de planes: correcto que ya no cuente como venta del plan); y `GET /api/reports/stats` (`routes/api.php`, caché `reports.stats` de 60 s), que suma solo `payments` pagados y nunca sumó abonos: allí el ingreso baja 50.000. Es una limitación anterior de ese indicador, no de la reclasificación.

**Proyecciones y datos guardados.** Ninguno que haya que recalcular: `report_content_snapshots` es evidencia de moderación (historias), no de cobros; los totales congelados del turno no se tocan por diseño y siguen cuadrando; `receipts.snapshot` es inmutable (el RG original sigue valiendo); el borrador de factura guarda su foto y queda cancelado; la única caché de informes es `reports.stats` (60 s, caduca sola); el resto (informes, exportaciones, `MembershipAccess`, la puerta local) se calcula al leer.

## 10. Compensación: deshacer una reclasificación

Una reclasificación equivocada NO se borra ni se edita (la constancia es inmutable y sus claves son `restrict`). Se compensa con las vías oficiales, cada una con su permiso y su traza (probado en `PaymentReclassificationTest::test_la_compensacion_documentada_deshace_sin_borrar_la_constancia`):

1. `POST /api/admin/commissions/charges/{charge_id}/cancel` con el motivo **y `reclassification_compensation: true`**. El abono tampoco se puede anular desde Cuentas por cobrar (`POST /api/admin/receivables/payments/{id}/reverse` → 422 `reclassified_payment`): la única vía es esta (sin ese indicador el servidor lo rechaza con 422, para que nadie anule por descuido un mes reclasificado; el CRM nunca lo envía) (`commissions.cancel`; como el turno está cerrado, además `cash.gym.manage`): revierte el abono —sale del turno original— y anula la deuda.
2. DESPUÉS del paso 1 (con el abono todavía aplicado se rechaza con 422 `reclassified_payment_reconfirm`, porque el dinero contaría dos veces): `PATCH /api/payments/{payment_id}` con `status=paid` y el motivo (`payments.cancel` + `cash.gym.manage`): el cobro vuelve a contar en su turno con su `paid_at`, y `MembershipPeriod::apply` le devuelve sus días encadenándolos detrás de la vigencia actual (el fin vuelve a ser el de antes; el orden de los periodos puede no ser el original). El borrador de factura sigue cancelado: facturar sería otra decisión.
3. `DELETE /api/admin/commissions/agreements/{agreement_id}` (`commissions.manage`): termina el acuerdo (no se borra).
4. Si el entrenador se dio de alta solo para esto: desactivarlo (`PUT /api/trainers/{id}` con estado inactivo). **No borrarlo**: su acuerdo cuelga en cascada y la constancia lo impide.
5. Si se archivó el plan: `POST /api/plans/{plan}/reactivate` (`plans.archive`).

Hacer 1 y 2 seguidos: entre los dos, el turno no cuadra con su cierre. Al terminar, verificar como en §8: el turno vuelve a cuadrar, el día recauda lo mismo, «reclasificado» vuelve a 0 y las vistas lo pintan como cobro de plan. La constancia, el abono revertido, la deuda anulada y las trazas cuentan toda la historia.

## 11. Concurrencia

Dos ejecuciones a la vez sobre el mismo cobro: una aplica y la otra informa «ya aplicada» (salida 0), sin filas dobles. Lo garantizan el cerrojo consultivo, los cerrojos de fila en el orden de `applyUpdate`, la relectura de la constancia bajo cerrojo y la clave única. Arnés real (procesos separados + barrera PG): `tests/Concurrency/ReclasificacionDeCobros/` (resultado y variantes en su README). Un interbloqueo con otra operación de caja se reintenta (3 intentos) solo en la transacción raíz.

## 12. Límites conocidos

- **El ensayo consume números de secuencia en PostgreSQL** (el ROLLBACK no los devuelve): tras un ensayo, los ids del `--apply` saltan. No deja filas.
- En el ensayo, `PaymentController` registra en el log «Membresía recortada al anular un cobro» (nivel `info`; producción corre con `LOG_LEVEL=warning`, así que no se ve).
- Las señales de tiempo real de la socia se insertan dentro de la transacción y se entregan al confirmar; si la app leyera otra señal posterior antes del commit podría saltarse esta (lo cubren su refresco al volver a primer plano y el sondeo).
- `MembershipPeriod::revert` no restaura `users.plan` (comportamiento previo, ver «Anular un cobro no restaura el plan»): si el cobro reclasificado fuera el último de la socia, su ficha seguiría diciendo el plan anulado.
- Las invariantes de turno y día se calculan en lectura confirmada: una corrección simultánea sobre el mismo turno o el mismo día haría fallar la comprobación y deshacer la reclasificación (falla cerrado; basta repetirla).
- El entrenador dado de alta no tiene correo: lo completa quien gestione su ficha.

## 13. Endurecimiento tras la revisión independiente (17-11-2026)

Revisiones de seguridad y de calidad: APROBADO CON NOTAS, sin bloqueantes. Antes del `--apply` real se cerró:

- **El abono no se anula por descuido** (calidad 1): `ReceivableService::reverse()` rechaza el abono de una reclasificación EN VIGOR (`reclassified_payment`), venga de Cuentas por cobrar o de anular el mes. Solo `reverseReclassificationCompensation()` lo anula, y solo lo llama `TrainerCommissions::cancelCharge()` con `reclassification_compensation: true` (§10, paso 1). La firma pública de `reverse()` no cambia. El CRM no ofrece el botón (`reclassified_from_payment_id` en cada abono).
- **El cobro no se reconfirma con el abono aplicado** (seguridad H-2, calidad 2): 422 `reclassified_payment_reconfirm`.
- **El entrenador se da de alta inactivo** (seguridad H-3, calidad 5): ni listado público ni portal.
- **El token de automatización no recibe el nombre del entrenador** (seguridad H-4): `PaymentReclassifier::summaryFor()`.
- **La constancia es inmutable también en PostgreSQL** (seguridad H-5): la migración retira al rol de la aplicación UPDATE, DELETE y TRUNCATE sobre `payment_reclassifications`. El script de despliegue lo comprueba.
- **Autoría** (seguridad H-6): `--received-by` queda atado a quien registró el cobro, y la constancia guarda el usuario del sistema que ejecutó la orden.
- **Caché** (calidad 6): las escrituras en `cache`/`cache_locks` no cuentan para la lista blanca.
- **Plan del cobro** (calidad 4): archivar si es seguro; si no, bloquear su cobro en recepción (decisión del propietario: «archivado o bloqueado de manera segura»).
- **Borrado de un entrenador con reclasificación** (calidad 3): 409 en lugar de 500, sin aviso falso.
- **Compra de tienda de la app con plan** (seguridad H-1, ya existía en producción): una compra de tienda no puede llevar ni activar un plan, y con plan el importe sale siempre del servidor.

