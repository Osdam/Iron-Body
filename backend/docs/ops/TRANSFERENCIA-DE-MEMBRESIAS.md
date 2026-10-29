# Transferir membresía

Un socio (la **titular**, A) cede el tiempo que le queda de su membresía a otra persona (el
**beneficiario**, B), que tiene su propia identidad. Se hace desde **Miembros → Ver detalles →
Membresía → Transferir membresía**. No hay módulo nuevo.

**Se transfiere el derecho de uso, no la identidad, ni el cobro, ni la factura.**

## 1. Qué cambia y qué no

| | Titular (A) | Beneficiario (B) |
|---|---|---|
| `users.membership_end_date` | D−1 (ayer): pierde el acceso en el acto | E (el fin que tenía A) |
| `users.membership_start_date` | D−1 solo si era posterior | D (hoy) |
| `users.plan` | sin cambios | nombre exacto del `Plan` del cobro de origen |
| `users.status` | sin cambios | `active` (igual que `MembershipPeriod::apply()`) |
| Cobros, facturas, abonos, recibos, asistencias, rostro | intactos | sin cobro nuevo; registra su rostro con el flujo habitual |

- D = hoy en America/Bogota y E = fin actual de A. Días transferidos = E − D + 1.
- No se regalan días, no se reinicia el plan y nadie tiene la membresía activa a la vez.
- Que A tenga fin = ayer es lo que hace que la deniegue **el torniquete local**
  (`local_gym`, caché por sondeo cada 120 s, válida si fin ≥ hoy), además de la app
  (`MembershipService::isActive`) y del CRM (`MembershipAccess`).
- La fila `membership_transfers` guarda la traza y los valores previos de ambos. La
  leen el rebuild, las guardas de anulación y devolución, la ficha del socio y la reversión.

## 2. Cuándo se puede (si no, bloqueo con su motivo)

Se bloquea todo lo que no tiene política comercial definida:

- **A congelada:** `membership_frozen_at` no nulo.
- **A vencida, sin vigencia hoy o con el inicio en el futuro.**
- **El fin de A no lo explica un único cobro:** debe haber un cobro `paid` cuyo periodo cubra hoy y
  cuyo `period_end` sea el fin de A. Esto deja fuera ajustes manuales, cortesías, `MIGR-*`,
  renovaciones encadenadas y cobros anulados.
- **El cobro de origen es una mejora de plan** (`upgraded_from_plan_id`).
- **`users.plan` de A no coincide con el plan del cobro**, salvo que lo explique un traslado de plan vivo
  (§8.4): entonces B recibe el plan actual de A.
- **El plan del cobro se borró del catálogo** (`source_plan_deleted`): hay que restaurarlo como histórico
  antes de ceder (`plans:restore-deleted`).
- **Plan por entradas.**
- **Cancelación solicitada, suscripción activa o cobro pendiente o en curso de A.** Se reutilizan los
  hechos de `PlanTransferEvaluator`.
- **Deuda:** la cuenta por cobrar del cobro de origen no está pagada, o A está en mora.
- **Ya transferida:** el cobro de origen ya se cedió y no se revirtió, o A recibió su vigencia por un traspaso.
- **B:**
  - es A (mismo id o mismo `identity_id`);
  - está suspendido, eliminado o sin ficha de socio válida;
  - tiene una membresía vigente, programada o congelada.

Las reservas futuras de clase de A se avisan o se bloquean según lo que haga hoy el sistema de clases
con un socio sin vigencia (ver implementación).

## 3. Autorización y evidencia

- **Permiso nuevo:** `members.transfer_membership` («Transferir membresías»). Por defecto solo Super Admin;
  el propietario lo asigna por rol en Configuración → Usuarios y roles. El backend lo exige siempre.
- **La petición exige:**
  - `holder_consent: true` (autorización expresa de A);
  - `beneficiary_acceptance: true`;
  - `reason` (5–500 caracteres, **sin diagnósticos médicos**: el CRM lo advierte);
  - `evidence` opcional (≤ 255, p. ej. «formato firmado n.º 123»);
  - `client_request_id`.

## 4. Garantías

- **Transacción con reintento.** Bloqueos en orden fijo:
  1. los dos socios, por id ascendente;
  2. el cobro de origen;
  3. sus deudas.
- **Revalidación bajo bloqueo:** la vista previa nunca se da por buena.
- **Unicidad:** índice único parcial de un traspaso vivo por cobro de origen (`reverted_at IS NULL`) y
  `client_request_id` único. Repetir la petición devuelve el traspaso ya hecho (`idempotent_replay`).
- **Efectos tras el commit:** `RealtimeEvents::membership` y `features` a los dos socios (la app
  rehace `/member/app-state`) y aviso a las fichas abiertas del CRM.
- **Auditoría:** una fila por socio (`AuditTrail`, módulo Miembros) con titular, beneficiario, cobro, plan,
  vigencias antes/después, motivo, evidencia y responsable.
- **Guardas nuevas:**
  - `memberships:rebuild` y `members:future-dates --fix` rechazan a quien tenga traspasos;
  - anular o devolver el cobro de origen de un traspaso vivo → 409 `membership_transferred`.

## 5. Corregir un beneficiario equivocado

`POST /membership-transfers/{id}/revert` (`members.transfer_membership`, `reason` obligatorio).

**Condiciones** (si no se cumplen, bloqueo con su motivo):
- B no ha entrado desde el traspaso (sin asistencias);
- A y B siguen exactamente con los valores que dejó el traspaso: ni cobros nuevos, ni congelación, ni ajustes.

**Efecto:**
- restaura los valores previos guardados de los dos;
- marca el traspaso `reverted_at/by/reason`, sin borrar nada;
- emite los mismos eventos.

Después se puede transferir al beneficiario correcto.

## 6. API

| Endpoint | Permiso | Notas |
|---|---|---|
| `GET /api/users/{user}/membership-transfer/preview?beneficiary_id=` | members.transfer_membership | `{allowed, blockers:[{code,message}], warnings:[…], holder:{…plan, end, days}, beneficiary:{…}\|null, effects:{…}}`. Sin `beneficiary_id`, solo la parte de A |
| `POST /api/users/{user}/membership-transfer` | members.transfer_membership | `{beneficiary_id, reason, holder_consent, beneficiary_acceptance, evidence?, client_request_id}` → 201 `{ok, transfer, holder, beneficiary}`; 200 `idempotent_replay` |
| `POST /api/membership-transfers/{transfer}/revert` | members.transfer_membership | `{reason}` → `{ok, already, transfer}` |
| `GET /api/users/{user}/membership-transfers` | members.view | traspasos cedidos y recibidos, para la ficha |

## 7. Límites conocidos (fuera de este cambio; `local_gym` no se modifica)

- **Ventana del torniquete:**
  - con conexión, hasta unos 2–3 min (sondeo de 120 s más la subida) en que A aún entra por la caché de un terminal;
  - sin conexión, hasta que reconecta.
- **Rostro:** el 1:N de `local_gym` no tiene segundo factor y el rostro de A se conserva (no se borra a propósito).
  Si A y B se parecen mucho, A podría ser reconocida como B.
- **Informes:** los que leen `payments.period_end` (p. ej. `MemberInsights`) siguen viendo a A cubierta hasta E.
- **Contrato:** B no hereda el contrato firmado por A.

## 8. Implementación (backend, 27-10-2026)

Rama `feat/transferir-membresia` sobre `d964b9e`. Servicio `App\Services\Membership\MembershipTransfers`,
modelo `MembershipTransfer`, controlador `Api\Admin\MembershipTransferController`, migración
`2026_10_29_100001_create_membership_transfers_table` (reversible; `down()` borra la tabla y su historial:
respaldarla antes de revertir en producción).

### 8.1 Ajustes al contrato (decididos al implementar)

- **«Un único cobro»**, exacto: entre los cobros `paid` de plan de A con periodo congelado y `period_end ≥ D`
  tiene que haber **uno solo**, con `period_start ≤ D` y `period_end` = fin de A. Si alguno de esos es una
  mejora → `source_is_upgrade` (antes que la unicidad, porque una mejora deja dos cobros «cubriendo» hoy).
  Referencia `MIGR-*` → `source_migrated`.
- **Pendientes, pagos en vuelo y suscripciones de A: de cualquier plan**, no solo del cobro. Se reutiliza
  `PlanTransferEvaluator::blockingFacts()`, ahora público con `$planId` opcional; el traslado de planes sigue
  pasando su plan y su comportamiento no cambia (suites de `Plans` en verde en SQLite y PG).
- **Deuda:** bloquea cualquier cuenta por cobrar del cobro de origen que no esté `paid` (también una anulada
  con el cobro aún en pie).
- **B sin ficha `members` válida → bloqueo** (`beneficiary_no_member_profile`), como dice §2: sin esa ficha no
  aparece en «rostros pendientes» (`AttendanceController::faceEnrollmentPending`) ni entra en la app, y los
  días cedidos no le servirían. Válida = `active`, `pending_registration` o `incomplete`. Suspendida
  (`isSuspended()`) → `beneficiary_suspended`; eliminada/anonimizada → `beneficiary_deleted`.
  **Sin rostro → aviso** (`beneficiary_no_face`). Nunca se copia ni se borra un rostro.
- **Reservas de clase de A → bloqueo** (`holder_upcoming_reservations`). Comprobado en el código: el sistema de
  clases **no** libera las reservas de quien pierde la vigencia (solo al borrar la cuenta,
  `ClassBookingService::releaseUpcomingFor`), siguen ocupando cupo y el entrenador le puede marcar asistencia
  (`ClassAttendanceService::assertMarkable` solo exige la reserva). Cuentan las sesiones que aún no han
  empezado (`ClassBookingService::upcomingFor`); las ya empezadas o pasadas no bloquean. Se quitan en
  Clases → inscritos (`classes.enroll`).
- **Bloqueos añadidos** (sin política definida): `holder_account_inactive` (`users.status` inactive/expired/
  inactivo/vencido: el torniquete ya la niega), `source_plan_missing`, `plan_name_too_long` (`users.plan` es
  varchar(100)), `member_not_found`.
- **A:** `status` sin cambios; el inicio pasa a D−1 solo si era posterior (si empezó hoy queda `[D−1, D−1]`).
- **Reversión, condiciones exactas:** B sin asistencias desde el traspaso (`attendances.captured_at ≥ creación`
  o `class_attendances.created_at ≥ creación`); A y B exactamente como los dejó (plan, inicio, fin, estado, sin
  congelar); sin cobros ni ajustes de A o B creados después. Revertir algo ya revertido → 200 `already: true`.
- **Columna extra:** `reverted_by_name` (instantánea del nombre, como `created_by_name`).
- **Lote final (27-10, tras revisión y seguridad):**
  - **Mejora de plan bloqueada** para la titular y el beneficiario de un traspaso vivo
    (`PlanUpgrades::blocker`, vía `MembershipTransfers::involvedInLive`): B no tiene cobro, su periodo empezaba
    en D y la mejora le daba la duración entera del plan nuevo (sonda: fin 2027-10-15 frente al 2027-09-01 de A,
    44 días gratis). La vista previa de la mejora devuelve `can_upgrade: false` con el motivo; ejecutarla, 422.
  - **Congelar, reanudar y ajustar días bloquean y releen al socio** (`User::lockForUpdate()`) dentro de su
    transacción y calculan sobre esa fila. Reglas y valores iguales; antes calculaban con el socio que cargó la
    ruta y, en carrera con un traspaso, guardaban o sumaban sobre el fin ya cedido (seguridad M2; autorizado
    tocar la congelación solo para esto).
  - **A con la ficha suspendida o eliminada** → `holder_suspended` / `holder_deleted`.
  - **Reversión bloqueada si B tiene reservas de clase por delante** (`beneficiary_upcoming_reservations`), con
    el criterio de A.
  - **Parecidos (`LookalikeRegistry`)**: no se añade aviso. Los pares solo nacen de plantillas de PERSONAL
    (`TrainerFaceReference`, kiosco de jornadas, `FaceReferenceService`); los rostros de socios
    (`member_biometrics`, 1:N de `local_gym`) no se emparejan, así que no hay par A–B que consultar.

### 8.2 Contrato final para el CRM

Todas exigen sesión de **persona** (el token compartido recibe 403; además no tiene el permiso).

**`GET /api/users/{user}/membership-transfer/preview?beneficiary_id=`** — `members.transfer_membership`
```
{ allowed, today,
  blockers: [{code, message}], warnings: [{code, severity: warning|info, message}],
  holder: {id, name, plan, start, end, status, frozen, days, has_member_profile},
  beneficiary: {id, name, plan, start, end, status, frozen, has_member_profile, member_status, has_face} | null,
  effects: { effective_date, end_date, days,
             source_payment: {id, plan_id, plan_name, plan_active, plan_transfer_item_id,
                              period_start, period_end, paid_at} | null,
             holder: {end_before, end_after, start_after, plan_after, status_after} | null,
             beneficiary: {plan_after, start_after, end_after, status_after} | null,
             creates_payment: false, creates_invoice: false } }
```
Sin `beneficiary_id`, `allowed` se refiere solo a A y `beneficiary` es null. `beneficiary_id` inexistente → 422.

**`POST /api/users/{user}/membership-transfer`** — `members.transfer_membership`
Cuerpo: `{beneficiary_id, reason (5–500), holder_consent: true, beneficiary_acceptance: true, evidence? (≤255), client_request_id (≤100)}`.
- 201 `{ok: true, idempotent_replay: false, transfer, holder, beneficiary}`
- 200 igual con `idempotent_replay: true` (misma llave, mismos socios)
- 409 `{ok: false, code: "transfer_blocked", message, blockers: [...]}` (revalidación bajo bloqueo)
- 422 validación (`errors`) o `{code: "client_request_id_conflict"}` (llave usada con otros socios)
- `holder`/`beneficiary`: `{id, name, status, plan, membershipStartDate, membershipEndDate}` (las claves de congelar).

`transfer` (también en el historial): `{id, status: active|reverted, holder: {id, name}, beneficiary: {id, name},
source_payment_id, plan: {id, name}, effective_date, end_date, days, holder_before: {plan, start, end, status},
beneficiary_before: {…}, reason, evidence, holder_consent_at, beneficiary_acceptance_at, created_by_name,
created_at, reverted_at, reverted_by_name, revert_reason}`.

**`POST /api/membership-transfers/{transfer}/revert`** — `members.transfer_membership`. Cuerpo `{reason (5–500)}`.
200 `{ok, already, transfer, holder, beneficiary}`; 409 `{code: "revert_blocked", message, blockers}`.

**`GET /api/users/{user}/membership-transfers`** — `members.view` y **persona** (el token compartido recibe 403
`person_required`). `{ok, data: [transfer + role: holder|beneficiary, revertible, revert_blockers]}`, lo último
primero (máx. 50). Si quien consulta es un **entrenador** y la otra persona del traspaso no es suya, llegan
vacíos (`null`) su `{id, name}`, sus valores previos, `source_payment_id` (si el cobro es de ella), `reason`,
`evidence` y `revert_reason`.

**Códigos de bloqueo.** A: `holder_suspended`, `holder_deleted`, `holder_frozen`, `holder_no_membership`, `holder_not_started`, `holder_expired`,
`holder_account_inactive`, `holder_received_by_transfer`, `no_source_payment`, `source_plan_deleted`, `source_not_unique`,
`source_mismatch`, `source_migrated`, `source_is_upgrade`, `source_plan_missing`, `plan_mismatch`, `entries_plan`,
`plan_name_too_long`, `source_already_transferred`, `source_receivable_unpaid`, `holder_cancellation_requested`,
`holder_pending_payment`, `holder_live_subscription`, `holder_overdue`, `holder_upcoming_reservations`.
B: `same_person`, `same_identity`, `beneficiary_no_member_profile`, `beneficiary_deleted`,
`beneficiary_suspended`, `beneficiary_frozen`, `beneficiary_active`, `beneficiary_scheduled`. Ejecución:
`member_not_found`. Reversión: `already_reverted`, `member_not_found`, `beneficiary_entered`,
`beneficiary_upcoming_reservations`, `holder_changed`,
`beneficiary_changed`, `paid_after`, `adjusted_after`.
**Avisos:** `beneficiary_no_face`, `beneficiary_overdue`, `beneficiary_cancellation_requested` (warning);
`holder_employee_access`, `beneficiary_employee_access` (info).

**Guardas nuevas en Caja:** anular o quitar cobertura (`PATCH /api/payments/{id}` a cancelled/pending/failed) y
devolver (`POST /api/payments/{id}/refund`) el cobro de origen de un traspaso vivo → 409
`{code: "membership_transferred", transfer_id, beneficiary_user_id}`; la vista previa de corrección
(`?action=cancel|refund`) lo lista en `blockers`.

**Canal del CRM** (`/api/admin/memberships/stream`): eventos `membership.transferred` y
`membership.transfer.reverted`, uno por socio, con `user_id`, `plan_id` (el del cobro) y
`changed: [membership, features, history]`. Si el plan se borró del catálogo, la reversión toma el `plan_id`
del cobro de origen y, si tampoco lo tiene, el evento viaja con `plan_id: null` (solo estos dos eventos; el
resto sigue exigiendo plan).

**Mejora de plan** (`GET/POST /api/users/{user}/plan-upgrade`): con un traspaso vivo, `can_upgrade: false` y
`reason` que nombra el traspaso; el POST responde 422 `{ok: false, message}`.

### 8.3 Límites detectados (fuera de este cambio)

- **Mejora de plan con traspaso vivo:** bloqueada (§8.1) mientras no haya política. Si el propietario quiere
  permitirla, hay que decidir cómo se cuenta el periodo de B (desde D, sin cobro propio) antes de abrirla.
- **Anular un abono** de la deuda ya saldada del cobro de origen después del traspaso reabre la deuda de A (y
  su mora) sin afectar a B. No se bloquea.

### 8.4 Plan del cobro borrado o cambiado por un traslado (28-10-2026)

- **`source_plan_deleted`**: A está cubierta por un cobro pagado con `plan_id` nulo cuya traza de creación
  (`audit_logs` `pago/create`, `metadata.plan_id`) apunta a un plan que ya no existe. Va antes que el resto
  de comprobaciones del cobro. `no_source_payment` queda para cuando de verdad no hay cobro.
- **Traslado vivo**: si el plan del cobro ≠ `users.plan`, se acepta solo con un `plan_transfer_items` de A,
  de un lote `transfer`, `applied`, sin revertir, con `before.plan_id` = plan del cobro, `after.plan_id` y
  `after.plan` = su plan actual, y lote ejecutado DESPUÉS de crear el cobro. Sin él, `plan_mismatch`.
  El plan del cobro archivado (`active=false`) no bloquea.
- Con traslado, B recibe el plan ACTUAL de A: `membership_transfers.plan_id/plan_name` y el `plan_id` de la
  traza y del canal son ese plan. La traza añade `source_plan_id` (el del cobro) y `plan_transfer_item_id`.
- Vista previa: `source_payment.plan_name` es el plan DEL COBRO; `plan_active` (false = histórico) y
  `plan_transfer_item_id` (null sin traslado). Lo que recibe B sigue en `effects.beneficiary.plan_after`.
- Los demás bloqueos (deuda, congelación, reservas…) no cambian.
