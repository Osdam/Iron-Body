# Histórico IBFE1–IBFE8 — situación y acción recomendada

**Fecha:** 2026-07-29
**Fuente de los importes:** documento validado en Factus/DIAN (`GET /v2/bills/{number}`),
no las columnas locales.
**Estado de la emisión:** BLOQUEADA. Ninguna de estas acciones se ha ejecutado.

---

## 1. Tabla definitiva

| Doc | Pago origen | ¿Real o prueba? | Total | IVA ante la DIAN | Tarifa | CUFE | Ambiente | Acción recomendada |
|---|---|---|---|---|---|---|---|---|
| **IBFE1** | 48 — **inexistente** | prueba (sin trazabilidad) | 80.000,00 | 12.773,11 | 19 % | sí | no determinable | **Anulación total** por nota crédito |
| **IBFE2** | 43 — existe, **efectivo** | **real** | 1.700.000,00 | 271.428,57 | 19 % | sí | no aplica (caja) | Corrección definida por el contador; posible nota crédito y **reexpedición** |
| **IBFE3** | 49 — **inexistente** | prueba (sin trazabilidad) | 80.000,00 | 12.773,11 | 19 % | sí | no determinable | **Anulación total** por nota crédito |
| **IBFE4** | 50 — **inexistente** | prueba (sin trazabilidad) | 80.000,00 | 12.773,11 | 19 % | sí | no determinable | **Anulación total** por nota crédito |
| **IBFE5** | 51 — **inexistente** | prueba (sin trazabilidad) | 80.000,00 | 12.773,11 | 19 % | sí | no determinable | **Anulación total** por nota crédito |
| **IBFE6** | 52 — **inexistente** | prueba (sin trazabilidad) | 80.000,00 | 12.773,11 | 19 % | sí | no determinable | **Anulación total** por nota crédito |
| **IBFE7** | 53 — existe, Wompi | **prueba** (tarjeta 4242) | 80.000,00 | 12.773,11 | 19 % | sí | **sandbox** | **Anulación total** por nota crédito |
| **IBFE8** | 1012 — existe, Wompi | **prueba** (tarjeta 4242) | 80.000,00 | 12.773,11 | 19 % | sí | **sandbox** | **Anulación total** por nota crédito |

**IVA total discriminado: 360.840,34** en 8 de 8 documentos.

Todas las notas crédito quedan **pendientes de autorización**. No se ha emitido ninguna.

## 2. Los dos defectos son independientes

Conviene no confundirlos, porque la corrección de uno no resuelve el otro:

1. **Tributario.** Los ocho discriminan 19 % de IVA siendo el emisor responsabilidad 49
   (no responsable). Afecta también a IBFE2, que sí es una venta real.
2. **De origen.** Siete de los ocho no corresponden a ventas reales: cinco apuntan a un pago
   que ya no existe y dos provienen de transacciones de Wompi en **sandbox** con tarjeta de
   prueba `4242`. Aquí el problema no es cuánto IVA lleva el documento, sino que el documento
   no debió existir.

Solo **IBFE2** es una operación real y trazable: pago 43, efectivo, $1.700.000. Es el único caso
en que tiene sentido hablar de *reexpedición*; en los otros siete no hay nada que reexpedir.

## 3. Por qué «no determinable» en cinco filas

IBFE1 y IBFE3–IBFE6 apuntan a los pagos 48–52, que **ya no existen** en la base. Sin el pago no
hay transacción asociada, y sin transacción no se puede afirmar el ambiente ni el medio de pago.
Se clasifican como prueba por descarte —ningún registro los respalda— y no por evidencia directa.

Esa ausencia es en sí misma un hallazgo: hay documentos fiscales ante la DIAN cuyo respaldo
documental local desapareció.

## 4. Estado de las solicitudes no emitidas

| ID | Estado | Motivo | Reintento |
|---|---|---|---|
| 9 | `rejected` | HTTP 422 de prevalidación: no consumió consecutivo | Solo con autorización explícita |
| 10–16 | `cancelled` | `sandbox_test` | **Deshabilitado** (`retry_allowed = false`) |

Las siete canceladas conservan su fila como evidencia; sus pagos y transacciones no se tocaron.

## 5. Lo que impide que esto se repita

`InvoiceEmissionGuard`, en el embudo HTTP, rechaza antes de cualquier POST:

- pagos con `environment` distinto de `production`;
- tarjetas de prueba conocidas;
- pagos cuyo estado no sea `paid`;
- facturas que el cliente no solicitó;
- pagos sin referencia verificable contra la pasarela;
- solicitudes canceladas o con reintentos deshabilitados;
- solicitudes que ya tienen número o CUFE;
- un segundo documento para un pago ya facturado;
- endpoint incoherente con el ambiente declarado, en ambos sentidos.

La unicidad por pago la garantiza además el índice
`electronic_invoices_source_type_unique (source_type, source_id, type)`.
