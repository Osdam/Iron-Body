# Corregir una deuda mal hecha · runbook

Qué hacer cuando una cuenta por cobrar está mal y hay que arreglarla sin romper
la contabilidad. Todo lo que se afirma aquí está cubierto por
`tests/Feature/Caja/ReceivableCancellationTest.php`.

Origen: cierre de Caja del 14 de septiembre de 2026. Antes de esto el estado
`cancelled` existía en el modelo y **no lo escribía nadie**: la única salida para
una deuda equivocada era borrar la fila —y con ella el rastro de un dinero que sí
entró— o dejarla ahí reteniendo a un socio que no debía nada.

---

## 1. Las cuatro correcciones, y cuál usar

| Lo que pasó | Operación | Permiso |
|---|---|---|
| La persona no puede pagar el día pactado | **Cambiar plazo** | `receivables.operate` |
| El caso está en revisión y no debe correr el plazo | **Quitar plazo** | `receivables.manage` |
| La deuda no debió existir, o se decide no cobrarla | **Anular** | `receivables.manage` |
| Se anuló por equivocación | **Reabrir** | `receivables.manage` |
| Se cobró de más, o se digitó mal un abono | **Anular el abono** (flujo existente) | `receivables.manage` |

Recepción solo tiene `operate`: puede pactar un plazo nuevo —esa es su
conversación con el socio— y nada más. Decidir que una deuda deja de cobrarse, o
dejarla sin fecha (que es levantar la retención sin que se note), es supervisión.

---

## 2. Lo que anular NO es

Confundir estas cuatro cosas es lo que cuesta dinero real:

- **Anular ≠ devolver dinero.** Los abonos cobrados siguen cobrados y siguen en
  el arqueo del turno donde entraron. Ese cierre ya lo contó una persona.
  Para devolver dinero hay que anular **cada abono** en su historial.
- **Anular ≠ devolver el producto.** El inventario tiene su propio flujo.
- **Anular ≠ cancelar la membresía.** Que la deuda se perdone no dice que el
  socio dejara de entrenar: su plan y su fecha de fin no se tocan.
- **Anular ≠ poner `total_amount = pagado`.** Eso reescribiría la historia y
  haría figurar como cobrado lo que se perdonó. El total no se toca nunca.

Lo único que deja de existir al anular es la **exigencia del saldo**.

---

## 3. Qué admite cada cuenta

El servidor lo dice en `can_cancel`, `can_reopen` y `can_edit_due_date`, y el CRM
los cruza con el permiso de quien mira. No se recalculan en el navegador:
ofrecer un botón que la API va a rechazar es peor que no ofrecerlo.

| Estado | Anular | Reabrir | Tocar el plazo |
|---|---|---|---|
| Pendiente / Abonada | sí | — | sí |
| **Pagada** | **no** (`receivable_already_paid`, 422) | — | no |
| **Anulada** | ya lo está | sí | no (`receivable_cancelled`, 422) |

Una obligación **pagada** es historia financiera cerrada: para corregirla hay que
revertir sus abonos primero; si entonces vuelve a quedar abierta, se podrá anular.

---

## 4. Efectos inmediatos

La mora se **deriva** del saldo y de la fecha, así que ninguna corrección espera
a un proceso nocturno:

- **Anular** una deuda de gimnasio vencida levanta la retención del socio en la
  siguiente lectura, cierra su alerta comercial con resolución `cancelled` —no
  `paid`: nadie pagó— y empuja el refresco de la app.
- **Reabrir** una deuda cuyo plazo ya pasó vuelve a retener **en el acto** y
  vuelve a abrir la alerta. El CRM lo avisa antes de confirmar.
- **Mover el plazo al futuro** levanta la retención y cierra la alerta como
  `rescheduled`, que vuelve a abrirse sola si el plazo nuevo también se pasa.
- **Corregir la fecha a otro día ya pasado** no calla nada: la deuda sigue
  vencida y su alerta sigue abierta.
- **Quitar el plazo** no perdona un peso: la deuda sigue viva y con saldo, solo
  deja de tener vencimiento y de retener.

El refresco de la app (`app_state.updated`) se emite **solo si la retención
cambió de verdad**: un aviso que no corresponde a ningún cambio enseña a la app a
recargar por nada.

---

## 5. Qué queda registrado

Cada corrección escribe en `audit_logs`, dentro de la misma transacción que la
aplica: o existen las dos cosas o no existe ninguna.

| Operación | `action` | Qué guarda |
|---|---|---|
| Anular | `status` | estado anterior → `cancelled`, total, pagado, **perdonado**, plazo, motivo |
| Reabrir | `status` | `cancelled` → estado recalculado, saldo, si queda vencida, motivo |
| Cambiar/quitar plazo | `update` | fecha anterior → nueva (o `null`), saldo, si queda vencida, motivo |

El **motivo es obligatorio** (mínimo 10 caracteres) y lo exigen las dos capas: el
controlador y el propio servicio, porque a `ReceivableService` también se llega
desde comandos y desde tinker.

La fila conserva además `cancelled_at`, `cancelled_by` y `cancellation_reason`
para poder pintar «anulada por Ana — cobrada por error» en un listado de veinte
sin una consulta por fila. **Se limpian al reabrir**, porque describen el estado
ACTUAL; lo que pasó queda en la auditoría, que es append-only y no se toca.

---

## 6. Dónde mirar

- Pestaña **«Anuladas»** de Cuentas por cobrar (`scope=cancelled`): qué se dejó
  de cobrar, quién lo decidió y por qué. Revisarla de vez en cuando es lo único
  que impide que anular se vuelva la salida fácil para cuadrar una caja.
- El saldo anulado **sale** del pendiente de cobro y del estado de cuenta del
  socio: si siguiera sumando, el pendiente del mes diría que hay dinero por
  recuperar que nadie va a reclamar.

---

## 7. Deuda técnica consciente

**No existe «castigar cartera» (write-off) como operación separada.** Hoy no hay
requisito contable que la pida: anular ya cubre la necesidad operativa, y sin un
modelo de informes que distinga «anulada por error» de «pérdida reconocida»,
añadirla serían dos nombres para la misma fila. Cuando exista ese requisito, el
sitio natural es un `cancellation_kind` sobre esta misma fila —nunca tocar
`total_amount`—.
