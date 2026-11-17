# Vencimientos de los personalizados: seguimiento, histórico y cobro del mes

Estado: **implementado en la rama `fix/subsanacion-pago-20209`, sin desplegar.**
Código: `app/Services/Commissions/TrainerCommissions.php` (`followUp()`, `history()`,
`charge()`), `app/Http/Controllers/Api/Admin/TrainerCommissionController.php`,
`routes/api.php`. Relacionado: `RECLASIFICACION-DE-COBROS.md` (panel del mes por
vigencia y `reclassified_from`).

## 1. Para qué

Recepción necesita saber, sin abrir mes por mes, **hasta cuándo tiene pagado cada
acuerdo de piso y qué hay que reclamar hoy**; y, en el histórico de un acuerdo,
**si cada mes se pagó a tiempo**. Además se cierra un fallo de concurrencia que
permitía cobrar dos veces el mismo mes (§5).

## 2. Lo que hay en producción (auditado en solo lectura, 17-11-2026)

- 5 acuerdos, todos con `cycle_days = 30` y `starts_on`/`ends_on` NULL. El 1 está inactivo.
- Cobros por mes calendario (`period` = día 1) con tramos de 30 días: 01-10 → 30-10,
  y uno 09-10 → 07-11 porque recepción ajustó el inicio.
- Ninguna deuda real tiene `due_at` (solo el cobro de prueba).
- No hay accesos de puerta escritos por un piso (`employee_accesses.source = 'commission'`).

Consecuencia al desplegar: sin plazos puestos, ninguna deuda sale como «vencida»;
el vencimiento lo marca la cobertura pagada (§3), y el histórico dice «inicio del
tramo, no confirmado» en vez de inventar un plazo (§6).

## 3. El modelo de cálculo (decisión del líder; no se cambia sin decirlo)

1. **La obligación es mensual**: un cobro vigente por acuerdo y mes. Lo que dura
   cada cobro es su tramo exacto `[covers_from, covers_to]` (por defecto
   `cycle_days` días).
2. **Vencimiento = último día cubierto por pisos PAGADOS.** Coincide con el acceso
   del entrenador, que vale hasta `covers_to` (`TrainerCommissionAccess::paidThrough()`),
   mientras la cobertura no tenga huecos (ver §9).
3. **La fecha del pago NUNCA mueve la cobertura.** Pagar por adelantado o tarde no
   desplaza vencimientos. Solo la mueven el tramo del cobro y `moveCoverage()`
   (auditado).
4. **Hoy = día del gimnasio en America/Bogota.** Todas las fechas de pago se pasan
   a Bogotá: un abono a las 01:43 UTC es del día anterior.

Detalles:

- **Pagado** = el estado `paid` de `stateOf()`: deuda viva y saldo cero. Los
  cobros reclasificados cuentan (su abono los salda). **Un pago parcial no cubre
  nada**: es deuda registrada.
- Los tramos pagados se **fusionan** cuando se solapan o se tocan
  (30-10 y 31-10 son un bloque).
- Un cobro sin tramo guardado (anterior a que existiera) cubre desde el día 1 de
  su mes `cycle_days` días, y la fila sale con `confirmed = false`.
- **Bloque de referencia**: el que contiene hoy; si no, el último que terminó
  antes de hoy (hay un hueco: hoy no está cubierto); si no, el primero futuro
  (prepago).
- `days_remaining = covered_until − hoy` (negativo = atraso);
  `days_overdue = max(0, −days_remaining)`.
- **Estado** (`window` de 1 a 30, 7 por defecto), por este orden:
  - `inactivo`: `!active` o `ends_on < hoy`;
  - `sin_registrar`: ningún tramo pagado;
  - `al_dia`: `days_remaining > window`;
  - `proximo`: de 1 a `window`;
  - `vence_hoy`: 0;
  - `vencido`: < 0.
- **Deuda registrada ≠ vencido.** `registered_debt` es una cuenta por cobrar
  ABIERTA del acuerdo (saldo > 0, no anulada): la vencida que vence antes; si
  ninguna lo está, la del mes más antiguo. `overdue` solo si tiene `due_at` y ya
  pasó (el día del plazo todavía vale, como `Receivable::isOverdue()`).
- `needs_review` = `vencido`, `vence_hoy`, deuda registrada vencida, o
  `sin_registrar` con el acuerdo vigente HOY (activo, ya empezado y sin terminar).

## 4. Seguimiento: contrato

### `TrainerCommissions::followUp(?CarbonImmutable $hoy = null, int $ventana = 7): array`

Solo lectura. `$hoy` es un **instante**: cuenta su día en Bogotá (sin valor, ahora).
La ventana se acota a 1..30. Un número FIJO de consultas (siete; ocho si hay
reclasificaciones) para todo el gimnasio, sea cual sea el número de acuerdos y
meses: acuerdos, entrenadores, clientes, cobros, sus deudas con la suma de abonos
aplicados, los abonos y las constancias de reclasificación con su recibo. Devuelve `['summary' => …, 'rows' => …]`. Filas = **todos** los
acuerdos, de lo más urgente a lo menos (`vencido`, `vence_hoy`, `proximo`,
`sin_registrar`, `al_dia`, `inactivo`; dentro de cada estado, menos días
restantes primero).

### `GET /api/admin/commissions/follow-up?window=7&filter=all`

Permiso: `commissions.view` (el del panel). `window` entero 1..30; `filter` uno de
`all | upcoming | overdue | paid | unregistered | inactive` (otro valor: 422).

| filtro | estados |
|---|---|
| `all` | todos |
| `upcoming` | `proximo`, `vence_hoy` |
| `overdue` | `vencido` |
| `paid` | `al_dia` |
| `unregistered` | `sin_registrar` |
| `inactive` | `inactivo` |

El resumen describe SIEMPRE todos los acuerdos; el filtro solo recorta `rows`.

```jsonc
{
  "filter": "all",
  "summary": {
    "total": 5,
    "by_state": { "vencido": 1, "vence_hoy": 0, "proximo": 1, "sin_registrar": 1, "al_dia": 1, "inactivo": 1 },
    "upcoming": 1,              // proximo + vence_hoy
    "overdue": 1,               // vencido
    "needs_review": 2,
    "amount_upcoming": 50000,   // suma de lo PACTADO (amount del acuerdo)
    "amount_overdue": 50000,
    "window_days": 7,
    "today": "2026-11-17"       // día del gimnasio
  },
  "rows": [{
    "agreement_id": 3,
    "trainer": { "id": 4, "name": "…" },
    "member":  { "id": 862, "name": "…" },
    "amount": 50000, "payer": "member", "payer_label": "El cliente", "cycle_days": 30,
    "starts_on": "2026-11-06",  // o null
    "ends_on": null,
    "active": true,
    "last_paid": {               // el mes pagado más reciente, o null
      "charge_id": 9, "period": "2026-11-01", "period_label": "noviembre de 2026",
      "covers_from": "2026-11-06", "covers_to": "2026-12-05", "amount": 50000,
      "reclassified_from": { "reclassification_id": 1, "payment_id": 20209, "receipt_number": "RG-000402", "performed_at": "…" }  // o null
    },
    "last_payment": {            // el último abono aplicado de sus meses no anulados, o null
      "receivable_payment_id": 12, "reference": "RP-12",
      "paid_at": "2026-11-06T20:43:00-05:00",   // ISO en Bogotá
      "amount": 50000, "method": "cash", "method_label": "Efectivo", "cash_shift_id": 237, "by": "…"
    },
    "covered_until": "2026-12-05", // o null (sin_registrar)
    "next_due": "2026-12-05",      // = covered_until
    "days_remaining": 18,          // o null
    "days_overdue": 0,             // o null
    "due_state": "al_dia",
    "confirmed": true,             // false si algún tramo del bloque se dedujo, o si no hay tramos
    "registered_debt": null,       // o { receivable_id, charge_id, period_label, balance, due_at, overdue }
    "needs_review": false
  }]
}
```

## 5. Concurrencia del cobro del mes (fallo que ya existía)

La migración `2026_10_09_000005` quitó el índice único `(agreement_id, period)`
para poder volver a cobrar un mes anulado, y la invariante «un cobro vigente por
acuerdo y mes» quedó solo en `charge()`, que la miraba **sin cerrojo**: dos
«cobrar» a la vez (doble clic, dos recepcionistas) dejaban dos cobros vigentes
del mismo mes y, con medio de pago, dos abonos.

Ahora `charge()`, **dentro de la transacción**, bloquea la fila del acuerdo
(`lockForUpdate`) y vuelve a comprobar, ya bloqueada, la vigencia y `chargeFor()`.
La segunda petición espera, ve el mes cobrado y recibe 422 «Ese mes ya está
cobrado para este acuerdo…». La comprobación rápida de antes de la transacción se
conserva (mismo mensaje). Sin índice nuevo ni migración.

Prueba en PostgreSQL real: `tests/Concurrency/CobroDelPiso/` (procesos separados,
barrera con `pg_blocking_pids`). Medido el 17-11-2026 con solapamiento
`max_blocked_seen = 2`:

- base `7f9219d5`: dos 201, 2 cobros vigentes y 2 abonos (100.000 por un mes de 50.000);
- con la corrección: un 201 y un 422, 1 cobro y 1 abono (cuatro de cuatro corridas).

## 6. Histórico ampliado: contrato

`GET /api/admin/commissions/agreements/{id}/history` → `{ agreement, charges[] }`,
una entrada por cobro (los anulados incluidos), del mes más reciente al más
antiguo (y dentro del mes, el último cobro primero), hasta 24. Cada entrada es el
cobro de siempre (`id, period, period_label, covers_from, covers_to, cycle_days,
amount, paid, balance, payer, state, receivable_id, due_at, by, created_at,
payments[], cancelled_at, cancel_reason, concept, reclassified_from`) más:

| campo | qué es |
|---|---|
| `due_date` | `receivable.due_at` si existe; si no, null |
| `due_date_confirmed` | true solo si hay `due_at` |
| `expected_due_date` | sin plazo: `covers_from`; con plazo: null |
| `expected_due_date_label` | `"inicio del tramo, no confirmado"` cuando hay `expected_due_date` |
| `paid_at` | el último abono aplicado, ISO en Bogotá; null si no hay |
| `days_late` | días de `due_date` a la fecha de pago (en Bogotá), solo con plazo confirmado y si es > 0; si no, null |
| `cancelled` | `{at, reason, by}` si el mes se anuló; si no, null |
| `adjustments` | movimientos de tramo auditados: `[{at, by, covers_from, covers_to, reason}]` |

`payments[]` (aquí y en todas las respuestas de un cobro: panel, cobrar, correr
tramo, anular) mantiene sus claves de siempre (`id, amount, method, method_label,
at, by, reversed`) y añade `receivable_payment_id`, `reference` (`"RP-{id}"`, el
número interno del abono), `receipt_number` (el recibo térmico si se emitió, o
null) y `cash_shift_id`. `at` sigue en ISO UTC, como antes; la fecha de pago en
Bogotá es `paid_at`.

`adjustments`: desde este cambio la traza de `moveCoverage()` lleva `charge_id` y
se atribuye a ese cobro; las trazas anteriores (sin `charge_id`) se atribuyen por
su mes.

## 7. Compatibilidad

- Todo lo nuevo es aditivo. Lo único que cambia de comportamiento es lo que se
  pidió: el panel por vigencia, el 422 al cobrar fuera de vigencia o un mes ya
  cobrado en carrera, y el tramo por defecto desde `starts_on`.
- Con `starts_on`/`ends_on` NULL (todos los acuerdos de hoy) el panel y el cobro
  se comportan como antes.
- Rendimiento: el saldo de cada deuda sale de una suma ya traída
  (`withSum('appliedPayments')`) y los abonos de la relación cargada. Antes el
  panel y el histórico hacían varias consultas por cada mes listado.

## 8. Verificación

- `tests/Feature/Commissions/TrainerCommissionFollowUpTest.php` (14): tramos de 30
  y de 15 días, pago adelantado y atrasado, vence hoy, próximo dentro y fuera de
  la ventana, vencido, sin cobros, inactivo y finalizado, reclasificado, pago
  parcial, cobro sin tramo, zona horaria (01:43 UTC y 23:30 en Bogotá), fechas
  NULL, histórico con plazo confirmado y sin confirmar, que seguimiento e
  histórico no escriben nada y repiten la misma respuesta, consultas constantes,
  y el endpoint (filtros, validación, permisos).
- Las 55 pruebas previas de `tests/Feature/Commissions` sin tocar.
- `tests/Concurrency/CobroDelPiso/run.sh` (§5).

## 9. Abierto

- Un acuerdo terminado (`DELETE`) no guarda `ends_on`; su estado es `inactivo` por
  `active = false`. Reabrirlo sin mandar fechas conserva las que tuviera.
- `registered_debt` enseña UNA deuda (la más urgente); si hubiera varias abiertas,
  el resto se ve en el histórico.
- El prepago sin el mes en curso pagado sale `al_dia` (regla del bloque futuro).
  Es lo pedido; si se quisiera marcar el hueco de hoy, es un cambio de regla.
- Con un HUECO (p. ej. octubre y diciembre pagados, noviembre no), el seguimiento
  dice `vencido` (el bloque de referencia es el que terminó antes de hoy) pero el
  acceso de puerta, que calcula `TrainerCommissionAccess::paidThrough()` con el
  tramo pagado más lejano, sigue abierto hasta diciembre. Hoy no hay accesos por
  piso en producción (§2); alinear la puerta es tocar `TrainerCommissionAccess`,
  fuera de este cambio.
