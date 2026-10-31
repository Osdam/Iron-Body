# Correcciones administrativas (Caja de Gimnasio, Créditos, Ventas y Caja de Cafetería)

Contrato y reglas de las correcciones que un administrador con permiso hace desde las
pantallas existentes del CRM, sin tocar la base de datos ni el servidor. **No hay módulo
nuevo**: cada acción vive en el detalle o el menú de la pantalla que ya existe.

Complementa a `CAJA-CORRECCIONES-DE-DEUDA.md` (anular/reabrir deuda, plazos, abonos).

## 1. Dos operaciones distintas: anular y devolver

| | **Anular** | **Devolver** |
|---|---|---|
| Qué significa | La operación no debió existir: error, duplicado, dinero que nunca entró | El dinero sí entró y se le devuelve al cliente |
| Estado final | `cancelled` | Cobro: `refunded`. Venta: `refunded` (total) o igual con líneas devueltas (parcial) |
| Arqueo del turno original | Deja de contar (si el turno está abierto). Si está cerrado, el cierre firmado no cambia y la corrección aparece en el informe como «posterior al cierre» | Sigue contando: el dinero entró en ese turno |
| Dinero que sale hoy | Ninguno (no hay egreso ficticio) | Un egreso de devolución en el turno ABIERTO del mismo tipo de caja (`cash_refunds`) |
| Membresía | Se quitan los días que dio ese cobro | Igual |
| Inventario | Reingreso opcional, lo decide quien corrige | Igual (por línea en la parcial) |
| Pasarela (Wompi) | **Prohibido** (409): no se puede anular dinero real de la pasarela | Solo registrando la devolución hecha en el panel de la pasarela, con su referencia. No crea egreso de caja |

Nunca se borra un registro, nunca se edita un saldo a mano y nunca se reescribe un cierre.

## 2. Reglas comunes a toda corrección

- **Motivo obligatorio** (`reason`, 5–500 caracteres) y responsable = el administrador de la sesión.
- **Atomicidad**: una transacción (`DB::transaction(..., 3)`, reintento ante deadlock). Los
  efectos externos (tiempo real, factura, avisos, recibos) se disparan **después del commit**.
- **Orden de bloqueos** (siempre el mismo, para no producir interbloqueos):
  - Antes que nada, el **turno** afectado con bloqueo COMPARTIDO (`FOR SHARE`); el cierre lo
    toma en exclusiva, así que espera a la corrección y la cuenta, o ya cerró y la corrección
    es posterior al cierre. *(Añadido en la implementación.)*
  - Gimnasio: turno → socio → cobro → deuda → abonos.
  - Cafetería: turno → venta → deuda → productos ordenados por id.
  - Estado y precondiciones se **revalidan bajo bloqueo**; la vista previa nunca se da por buena.
- **Idempotencia**:
  - Anular o cancelar algo ya anulado devuelve 200 con `already: true`, sin nueva auditoría ni efectos.
    Excepción ya desplegada que se conserva: anular un **abono** ya anulado sigue siendo 422
    `already_reversed`.
  - Altas (cobros, ventas, devoluciones) aceptan `client_request_id` (único en base de datos).
    Repetir la petición devuelve el recurso ya creado con `idempotent_replay: true`.
  - El CRM genera un `client_request_id` por intento y lo reutiliza si reintenta tras un fallo de red.
- **Turno cerrado**: corregir una operación de un turno cerrado exige además `cash.{gym|products}.manage`.
  El cierre firmado no cambia; el informe de ese turno lista «Correcciones posteriores al cierre»
  (desde `audit_logs`, con `metadata.cash_shift_id`).
- **Factura electrónica**:
  - `validated` sin nota crédito validada → 409 `invoice_validated` con `invoice_id`.
    Procedimiento: emitir la nota crédito desde el detalle de la factura y repetir la corrección.
  - En proceso → 409 `invoice_processing`.
  - Pendiente, en error o rechazada → la anulación sigue adelante y el guardián de emisión no la factura.
  - Devolución parcial con factura validada → 409: la nota crédito actual es solo total.
- **Deuda viva**: anular un cobro o una venta con deuda no anulada se rechaza mientras la deuda
  tenga abonos aplicados (anularlos primero en Créditos). Venta fiada sin abonos aplicados: su
  deuda se anula en la misma transacción, con el mismo motivo.
- **Auditoría**: `FinancialAudit` registra tipo, estado y medio/importe antes y después, motivo,
  responsable, operaciones vinculadas y `cash_shift_id` (con `shift_closed`).

## 3. Permisos (Configuración → Usuarios y roles)

Se reutilizan los existentes; solo se añaden los que cubren capacidades nuevas. Los nuevos
**no** se conceden por defecto al Administrador ni a ningún rol no privilegiado: el propietario
los activa por rol en la matriz.

| Acción | Permiso |
|---|---|
| Anular cobro / reconfirmar pendiente | `payments.cancel` (existente) |
| Corregir medio o importe de un cobro | `payments.correct` (**nuevo**) |
| Devolver dinero de un cobro | `payments.refund` (**nuevo**) |
| Anular abono, anular/reabrir deuda, quitar plazo, corregir medio de un abono | `receivables.manage` (existente) |
| Cambiar vencimiento | `receivables.operate` (existente) |
| Cancelar pedido o anular venta de cafetería, corregir su medio | `cash.products.manage` (existente) |
| Devolver una venta (total o parcial) | `cash.products.refund` (**nuevo**) |
| Corregir operaciones de un turno cerrado | `cash.gym.manage` / `cash.products.manage` (existentes) |
| Nota crédito | `billing.manage` (existente; se quita el filtro de rol escrito en código) |

## 4. Endpoints

(Respuesta de error: `{message, code, ...}`. 403 permiso, 409 estado, 422 validación.)

### Caja de Gimnasio (`/api/payments`)

- `GET /payments/{id}/correction-preview?action=cancel|refund|method` (`payments.view`):
  efectos y bloqueos sin escribir nada:
  `{action, allowed, blockers:[{code,message,link?}], effects:{cash_shift:{id,status,closed_at}, membership:{current_end, end_after, days_removed}|null, receivable:{id,status,balance,applied_count}|null, invoice:{id,status,number}|null, receipts:[{id,number}], gateway:bool, refund:{shift_id,method}|null}}`.
- `PATCH /payments/{id}` con `status: cancelled` exige `reason`. Bloquea socio → cobro.
  Es idempotente y devuelve los días dentro de la transacción. Rechaza:
  - cobro de pasarela: 409 `gateway_payment`;
  - factura validada: 409 `invoice_validated`;
  - deuda viva: 422 existente;
  - turno cerrado sin `cash.gym.manage`: 403 `closed_shift_requires_manage`.
- `PATCH /payments/{id}` con `status: paid` (reconfirmar pendiente) bloquea y es idempotente:
  aplica el periodo una sola vez.
- `PATCH /payments/{id}` con `status: refunded` → 422: se usa la devolución.
- `PATCH /payments/{id}` que cambie `method`, `amount` o `splits` → exige `payments.correct` y `reason`.
- `POST /payments/{id}/method` (`payments.correct`) `{method, splits?, reason}`:
  - si pasa de `mixed` a un medio simple, borra las partes en la misma transacción;
  - cambiar el importe de un cobro con partes exige partes que cuadren;
  - nunca entra ni sale de `receivable`, y la pasarela no se reetiqueta.
- `POST /payments/{id}/refund` (`payments.refund`) `{reason, method?, external_reference?, client_request_id?}`:
  - el cobro pasa a `refunded` y se devuelven los días;
  - registra el egreso en el turno de gimnasio abierto (409 `no_open_shift` si no hay ninguno);
  - pasarela: exige `external_reference` y no crea egreso;
  - el devengo de un plan a plazos (`receivable`) → 409 `use_receivable_flow`.
- `POST /payments` acepta `client_request_id`.
- `GET /payments/{id}` añade `links`: deuda vinculada, turno, factura, recibos, devoluciones y
  `possible_duplicates`. Un posible duplicado es otro cobro no anulado del mismo socio, mismo
  importe y mismo concepto, a menos de 10 minutos.
- `GET /payments/{id}/history` (`payments.view`): las filas de `audit_logs` de ese cobro.

### Créditos (`/api/admin/receivables`)

- El abono expone `reversed_by_name`, `reversal_reason`, `cash_shift {id,status}` y `receipt_number`.
- La deuda expone `source {type,id,label}`.
- `can_reopen` también es falso si el cobro de origen no está pagado.
- `POST /admin/receivables/payments/{id}/method` (`receivables.manage`) `{method, reason}`: solo abonos
  `applied`; con turno cerrado exige además `cash.{type}.manage`.
- `GET /admin/receivables/{id}/history`.

### Ventas y Caja de Cafetería (`/api/admin/caja`)

- `GET /sales/{id}/correction-preview?action=cancel|refund` → mismo formato, con
  `effects.stock:[{product_id,name,quantity,restockable}]` y `effects.refund_amount`.
- `POST /sales/{id}/cancel` `{reason, restock?:bool}` (`cash.products.manage`): pendiente, cobrada,
  fiada o entregada.
  - Idempotente.
  - Reingreso opcional y una sola vez por venta.
  - Por defecto `restock=true` salvo que la venta esté `delivered`. Una venta pendiente no movió stock y no reingresa.
- `POST /sales/{id}/refund` `{reason, items?:[{item_id,quantity}], restock?:bool, method?, client_request_id?}`
  (`cash.products.refund`):
  - total (sin `items`) o parcial;
  - egreso en el turno de productos abierto;
  - las líneas guardan la cantidad devuelta, y nunca se puede devolver más de lo vendido.
- `POST /sales/{id}/method` `{method, splits?, reason}` (`cash.products.manage`).
- `POST /sales/{id}/pay` exige turno de productos abierto y asigna ese turno (409 `no_open_shift`).
- `POST /sales/{id}/deliver` solo desde `paid` o `credit`, con bloqueo. Desde `credit` responde 200
  y la venta **sigue** `credit` (ya se entregó al fiarla; pasarla a `delivered` la contaría como
  dinero en el arqueo). Desde `pending`/`cancelled`/`refunded` → 409 `sale_not_deliverable`.
- `POST /sales` acepta `client_request_id` y es una sola transacción.
- El informe del turno lista egresos de devolución, correcciones posteriores al cierre y (cafetería)
  los abonos que ya sumaba en sus totales.

## 5. Procedimientos que no se automatizan

- **Devolución por Wompi**: se hace en el panel de Wompi y luego se registra aquí con su referencia.
- **Factura validada**: primero la nota crédito, desde el detalle de la factura.
- **Devolución de un plan a plazos**: se hace por abonos (anular abono / anular deuda), no por el cobro de devengo.

## 6. Implementación backend (27-10-2026, rama `feat/correcciones-administrativas`)

### Diferencias respecto a lo escrito arriba (decididas al implementar)

1. **Motivo**: además de `status: cancelled`, lo exige cualquier paso de un cobro pagado a
   `pending`/`failed` (también quita cobertura) y cualquier cambio de `method`, `amount`, `splits`
   o `paid_at` (ya fechado). Se pide DESPUÉS de las reglas de negocio: si la corrección no se puede
   hacer (deuda viva, pasarela, factura…), se responde eso primero.
2. **Devolver no exige `manage`** aunque el turno del cobro/venta esté cerrado: el dinero sale del
   turno ABIERTO y el cierre firmado no cambia; el informe del turno original la lista como
   corrección posterior al cierre (acción `refund`).
3. **Lo devuelto no se anula** (409 `already_refunded`) y una venta con devoluciones parciales no se
   anula (409 `sale_has_refunds`): contaría dos veces el mismo dinero.
4. **Venta fiada**: devolverla → 409 `use_receivable_flow` (su dinero entró por abonos, como el
   devengo de un plan a plazos). Su deuda, al anular la venta sin abonos, se anula con el motivo
   «Venta V-… anulada: <motivo>».
5. **Deuda de una venta anulada/devuelta**: `reopen` → 422 `receivable_source_cancelled` y
   `can_reopen: false` (además del caso del cobro no pagado).
6. **Factura y medio**: cambiar el `amount` de un cobro con factura validada → 409; cambiar solo el
   medio se permite (el importe facturado no cambia). Las solicitudes de factura pendientes, en
   error o rechazadas NO se anulan: el guardián de emisión ya rechaza un origen que no está pagado
   (`payments.status` ≠ paid; `product_sales.payment_status` pasa a `cancelled`/`refunded`).
7. **Medio de la devolución**: por defecto el del cobro/venta; si era `mixed`, `method` es obligatorio.
8. **Matriz de permisos**: «Pagos» → «Caja de Gimnasio», «Caja de productos» → «Caja de Cafetería»,
   y para no tener dos dominios con el mismo nombre «Caja del gimnasio» (`cash.gym`, los turnos) →
   «Turnos de la Caja de Gimnasio». Claves, rutas y `module` de `audit_logs` sin cambios.
9. **Informe de cafetería (H9)**: lista sus abonos; `GET /caja/shifts/{id}/receipts` devuelve ahora
   `in_report: true` también para ellos.
10. **Alta de venta atómica**: si falta stock al cobrar, no queda NADA (antes quedaba la venta
    `pending`); el 422 `insufficient_stock` ya no trae `sale`. Los códigos son `V-{id}` (antes
    `max(id)+1`, que repetía número en altas simultáneas).
11. **Tras la revisión de seguridad (27-10)**:
    - Un cobro `refunded` no cambia de importe, medio, desglose ni fecha (PATCH y `/method`) → 409
      `already_refunded`; la vista previa `action=method` lo da como bloqueo.
    - Anular un ABONO de un turno cerrado exige además `cash.{gym|products}.manage` → 403
      `closed_shift_requires_manage` (igual que corregir su medio).
    - `client_request_id` repetido con OTRA operación → 422 `client_request_id_conflict`, sin datos
      del recurso ajeno. Cobro: mismo socio, plan e importe. Venta: mismas líneas (producto y
      cantidad), mismo descuento —que fijan el total—, mismo socio/cliente y fiada o no.
    - Historiales (`/payments/{id}/history`, `/receivables/{id}/history`) y vistas previas (cobro y
      venta) exigen una persona: el token compartido recibe 403 `person_required`.
    - Vista previa: una mejora de plan que no se puede deshacer aparece como bloqueo
      `membership_blocked` (con el mensaje que daría la corrección) en vez de 422, y la simulación
      no escribe avisos en el log.
12. **Devoluciones en indicadores e informes**:
    - Base de CAJA (Ganancias `/admin/earnings`, panel `/admin/reports/overview`, «Ingresos hoy» de
      `/admin/caja/stats`): la operación devuelta cuenta en la fecha en que entró el dinero y la
      devolución resta en la fecha de `cash_refunds` (por su caja y su medio; Ganancias añade en
      `by_method` filas negativas de cafetería). La utilidad de cafetería solo cuenta las unidades
      no devueltas. Un día puede salir en negativo.
    - Informe de dinero (`MoneyLedger`: `/admin/reports/summary`, exportaciones y PDF): ya descontaba
      lo devuelto en la fecha de la operación (lo `refunded` queda fuera por su estado); las
      devoluciones parciales se descuentan igual (`total − refunded_total`, columna nueva de
      `product_sales`). No se le aplica la base de caja porque sus filtros por persona, canal o
      socio no tienen un dueño claro para una devolución (¿quien cobró o quien devolvió?).
      `reversals()` añade `refunds: {amount, count}` por la fecha en que salió el dinero.
    - Sin cambios: KPIs de `/api/admin/payments/stats` (los cobros solo se devuelven enteros y ya
      salen de «pagados» por su estado).
13. **Ronda final del reviewer (27-10)**:
    - `POST /payments` con `status: refunded` → 422 `use_refund_endpoint` (como PATCH): `refunded`
      solo lo escribe la devolución, que siempre deja su fila en `cash_refunds`. Producción no tenía
      cobros `refunded` (solo paid, pending y cancelled), así que los informes no cambian hacia atrás.
    - Medio de una devolución (cobro o venta) y corrección del medio de un abono: cualquier medio
      de mostrador que la caja sabe contar (efectivo, transferencia, tarjeta, Nequi, Daviplata, PSE,
      `other`), nunca pasarela, `receivable` ni `mixed`, con mensaje en español (`PaymentMethodInput`).
      Se guarda en minúsculas; en el abono, normalizado como al abonar (Nequi → `transfer`).
    - **Factura y devolución parcial** (regla elegida): la factura PROPIA de la venta aún no emitida
      (`pending`, `error` o `rejected` sin CUFE) se **recalcula** en la misma transacción con las
      cantidades netas (vendido − devuelto): líneas, importes y medio de pago; el adquiriente y el
      resto del payload se conservan. El importe de conciliación de la venta pasa a ser
      `total − refunded_total`. Devolución total: la factura pendiente no se emite (el guardián
      rechaza lo que no está pagado). Bloqueos (409): factura validada → `invoice_validated`
      (nota crédito solo total); en proceso → `invoice_processing`; venta en un **lote** con factura
      sin anular → `invoice_in_bundle`; venta sin importes por línea (anterior a Pricing V2) con
      factura sin anular → `invoice_not_recalculable`. Una venta con devoluciones no entra en lotes
      manuales: se factura sola, por lo que quedó.
    - `GET /api/admin/caja/sales/{id}/history` (`caja.view`, persona → 403 `person_required`):
      filas de la venta y, si fue fiada, de su deuda y sus abonos; mismo formato que el del cobro.
    - Tirilla de una operación `refunded`: «*** OPERACIÓN DEVUELTA ***» (las anuladas siguen
      «ANULADA»). Ojo CRM: `receipt-snapshot.ts` debe distinguirlo igual.
    - Comisiones: anular un mes cuyo abono es de un turno cerrado sin `cash.gym.manage` → 403
      `closed_shift_requires_manage` (antes 422 sin código); la anulación es atómica.
    - Devolución repetida con la misma `client_request_id` que espera el bloqueo detrás de su propia
      petición → 200 `idempotent_replay` (se vuelve a mirar la llave con la operación bloqueada).
14. **Aplazado**: quitar líneas de un pedido pendiente (`POST /sales/{id}/items/{item}/remove`). El
    descuento de mostrador se reparte entre TODAS las líneas al crear la venta (`buildSale`):
    quitar una exige recotizar las demás, y `buildSale` solo sabe hacerlo creando la venta. Hoy:
    cancelar el pedido y crear otro.

### Referencia para el CRM

Errores: `{ok:false, code, message, ...}`; 403 permiso (`code: forbidden`, `required_permission`),
409 estado, 422 validación (`errors`).

**Caja de Gimnasio**

| Endpoint | Permiso | Cuerpo | Respuesta |
|---|---|---|---|
| `GET /api/payments/{id}` | payments.view | — | cobro + `links:{receivable:{id,status,balance,applied_count}\|null, cash_shift:{id,status,closed_at}\|null, invoice:{id,status,number}\|null, receipts:[{id,number}], refunds:[CashRefund], possible_duplicates:[{id,amount,status,method,reference,created_at}]}` |
| `GET /api/payments/{id}/correction-preview?action=cancel\|refund\|method` | payments.view | — | `{action, allowed, blockers:[{code,message,link?,required_permission?,invoice_id?}], effects:{cash_shift, membership:{current_end,end_after,days_removed,blocked?}\|null, receivable, invoice, receipts, gateway, refund:{shift_id,method,amount,requires_external_reference,requires_method}\|null}}` |
| `GET /api/payments/{id}/history` | payments.view | — | `{ok, data:[{id,at,action,entity,entity_id,summary,actor_name,actor_role,changes,reason,metadata}]}` |
| `PATCH /api/payments/{id}` | payments.cancel (+ payments.correct si cambia dinero) | `{status?, reason?, method?, amount?, splits?, …}` | el cobro; `already: true` solo si ya estaba anulado |
| `POST /api/payments/{id}/method` | payments.correct | `{method, splits?, reason}` | `{ok, already, data: cobro}` |
| `POST /api/payments/{id}/refund` | payments.refund | `{reason, method?, external_reference?, client_request_id?}` | 201 `{ok, already:false, idempotent_replay:false, data, refund}`; 200 si `already` o `idempotent_replay` |
| `POST /api/payments` | cash.gym.operate | + `client_request_id?` | 201; repetido → 200 con `idempotent_replay: true` |

Códigos: `gateway_payment` 409, `invoice_validated` 409 (+`invoice_id`), `invoice_processing` 409,
`closed_shift_requires_manage` 403 (+`cash_shift_id`, `required_permission`), `no_open_shift` 409,
`use_receivable_flow` 409 (refund) / 422 (PATCH `refunded` de un devengo con deuda; trae `errors.status`),
`use_refund_endpoint` 422, `payment_not_paid` 409, `already_refunded` 409,
`client_request_id_conflict` 422, `person_required` 403 (historial y vista previa con el token
compartido), bloqueo `membership_blocked` en la vista previa; deuda viva y mejora dependiente: 422
`errors.status` (existentes).

**Créditos**

| Endpoint | Permiso | Notas |
|---|---|---|
| `GET /api/admin/receivables/{id}` | receivables.view | deuda + `source:{type:'payment'\|'product_sale',id,label,status}`; abonos con `reversed_by_name`, `reversal_reason`, `cash_shift:{id,status}\|null`, `receipt_number` |
| `POST /api/admin/receivables/payments/{id}/method` | receivables.manage (+ cash.{type}.manage si el turno cerró) | `{method: cash\|transfer\|card\|other, reason}` → `{ok, already, data: abono, receivable}`; 409 `receivable_payment_not_applied` |
| `GET /api/admin/receivables/{id}/history` | receivables.view | mismas filas que el historial del cobro (deuda + sus abonos) |

**Ventas y Caja de Cafetería**

| Endpoint | Permiso | Cuerpo | Respuesta |
|---|---|---|---|
| `GET /api/admin/caja/sales/{id}/correction-preview?action=cancel\|refund` | caja.view | — | como el de cobros + `effects.stock:[{item_id,product_id,name,quantity,restockable, refunded_quantity?, refundable_quantity?, refundable_amount?}]`, `effects.refund_amount`, `effects.restock_default` |
| `POST /api/admin/caja/sales/{id}/cancel` | cash.products.manage | `{reason, restock?}` | `{ok, already, restocked, receivable_id, data: venta}` |
| `POST /api/admin/caja/sales/{id}/refund` | cash.products.refund | `{reason, items?:[{item_id,quantity}], restock?, method?, client_request_id?}` | 201 `{ok, already, idempotent_replay, data, refund}` |
| `POST /api/admin/caja/sales/{id}/method` | cash.products.manage | `{method, splits?, reason}` | `{ok, already, data}` |
| `POST /api/admin/caja/sales/{id}/pay` | cash.products.operate | igual que antes | 409 `no_open_shift`, 409 `sale_not_payable` (anulada/fiada/devuelta) |
| `POST /api/admin/caja/sales` | cash.products.operate | + `client_request_id?` | repetido → 200 `idempotent_replay` |
| `GET /api/admin/caja/sales/{id}/history` | caja.view (persona) | — | `{ok, data:[{id,at,action,entity,entity_id,summary,actor_name,actor_role,changes,reason,metadata}]}` |

La venta serializada añade `cancelled_at`, `refund_lines:[{item_id,product_id,name,quantity,refunded_quantity,refunded_amount}]`
y `refunded_total` (columna de `product_sales`); estado nuevo `refunded`. Códigos: `refund_exceeds_sold` 422, `nothing_to_refund` 422,
`sale_has_refunds` 409, `invoice_in_bundle` 409, `invoice_not_recalculable` 409, `sale_not_paid` 409, `sale_not_cancellable` 409, `method_not_correctable` 409,
`receivable_has_payments` 422 (+`receivable_id`), `sale_not_deliverable` 409.

**Informe del turno** (`GET /api/admin/caja/shifts/{id}` y PDF): añade `refunds:[CashRefund]`,
`post_close_corrections:[{id,at,action,entity,entity_id,summary,actor_name,reason,changes}]`,
`shift.refunds_total` y `consistency.refunds_total`. Los totales por medio y `expected_cash` son
entradas − devoluciones del turno; `refunded` cuenta como entrada en su turno original.
`CashRefund`: `{id, cash_type, cash_shift_id, refundable_type, refundable_id, amount, method, method_label, reason, items, restocked, external_reference, created_by_name, created_at}`.

**Auditoría**: acción nueva `refund`; `changes` con antes/después de `status`, `method`, `amount`,
`paid_at`, `splits`; `metadata` con `reason`, `cash_shift_id`, `shift_closed`, `membership`.

### Migración

`2026_10_28_100001_create_cash_refunds_and_correction_keys` (reversible): tabla `cash_refunds`;
`client_request_id` único en `payments` y `product_sales`; `refunded_total` en `product_sales`;
`refunded_quantity`/`refunded_amount` en `product_sale_items`. Sin cambios de datos.

### Pruebas de concurrencia

`tests/Concurrency/CorreccionesAdministrativas/` (ver su README): doble anulación de un cobro, doble
cobro de un pedido, cobrar-contra-anular y dos devoluciones parciales que juntas superan lo vendido,
en procesos separados contra PostgreSQL.
