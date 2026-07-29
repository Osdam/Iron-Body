# Incidente fiscal — IVA discriminado por un emisor no responsable

**Fecha de apertura:** 2026-07-29
**Estado:** ABIERTO — emisión bloqueada, a la espera de Factus/Halltec y del contador
**Alcance:** facturación electrónica (Factus/DIAN). No afecta pagos, membresías ni contratos.

---

## 1. Resumen

Iron Body figura en el RUT con **responsabilidad 49 — No responsable de IVA**, confirmado por su
contador. Pese a ello, los ocho documentos emitidos y validados ante la DIAN (IBFE1–IBFE8)
**discriminan IVA del 19 %**.

La causa técnica fue que la tarifa se resolvía desde `plans.tax_rate_id` («IVA 19 % incluido») y el
precio comercial se partía en base + IVA: $80.000 → $67.226,89 + $12.773,11.

Durante la investigación aparecieron **dos hallazgos adicionales e independientes** (secciones 4 y 5)
que amplían el incidente más allá del IVA.

## 2. Cifra afectada

| Documento | Base gravable (DIAN) | IVA (DIAN) | Total |
|---|---|---|---|
| IBFE1 | 67.226,89 | 12.773,11 | 80.000,00 |
| IBFE2 | 1.428.571,43 | 271.428,57 | 1.700.000,00 |
| IBFE3 | 67.226,89 | 12.773,11 | 80.000,00 |
| IBFE4 | 67.226,89 | 12.773,11 | 80.000,00 |
| IBFE5 | 67.226,89 | 12.773,11 | 80.000,00 |
| IBFE6 | 67.226,89 | 12.773,11 | 80.000,00 |
| IBFE7 | 67.226,89 | 12.773,11 | 80.000,00 |
| IBFE8 | 67.226,89 | 12.773,11 | 80.000,00 |
| **Total IVA** | | **360.840,34** | |

**8 de 8** documentos validados discriminan IVA.

## 3. La base local no era fuente de verdad

El informe inicial dijo «7 facturas, $348.067,23». Era incorrecto: se auditó contra
`electronic_invoices.tax_total`, y la **IBFE1 figura localmente con IVA 0,00** mientras el documento
validado ante la DIAN discrimina 12.773,11.

```
IBFE1   local: subtotal 80000.00 / IVA 0.00      (registro id 2)
IBFE1   DIAN : taxable_amount 67226.89 / tax_amount 12773.11 / rate 19.00
```

Causa de la desincronización: el registro local conserva el snapshot fiscal calculado **después** de
la corrección de la política tributaria, mientras que el documento ante la DIAN quedó congelado con
los valores del momento de la emisión. Es decir, la corrección del cálculo actualizó la copia local
pero —correctamente— no pudo alterar el documento ya validado. El defecto no fue la
desincronización en sí, sino **auditar contra la copia**.

Corregido: `billing:tax-audit --audit` consulta ahora el documento del proveedor por `GET` y trata
al proveedor como autoridad para todo documento validado. Ver
`app/Services/Billing/FiscalReconciliationService.php`.

## 4. Hallazgo adicional — documentos fiscales emitidos por pagos de SANDBOX

`IBFE7` e `IBFE8` provienen de transacciones Wompi con `environment = sandbox` y tarjeta de prueba
`4242`. Son documentos fiscales **reales ante la DIAN** originados en pagos que nunca movieron
dinero.

Las **siete solicitudes pendientes (ID 10–16) son igualmente de sandbox** y no deben emitirse jamás.
Ver sección 6.

## 5. Hallazgo adicional — pagos origen inexistentes

Cinco documentos validados apuntan a un pago que ya no existe en la base:

| Documento | Pago origen | Estado del pago |
|---|---|---|
| IBFE1 | 48 | **ausente** |
| IBFE3 | 49 | **ausente** |
| IBFE4 | 50 | **ausente** |
| IBFE5 | 51 | **ausente** |
| IBFE6 | 52 | **ausente** |
| IBFE2 | 43 | existe — efectivo, $1.700.000 |
| IBFE7 | 53 | existe — Wompi **sandbox** |
| IBFE8 | 1012 | existe — Wompi **sandbox** |

Queda como pregunta abierta para el contador y el propietario: solo `IBFE2` corresponde con certeza
a una operación real y trazable.

## 6. Solicitudes pendientes ID 10–16

Las siete pertenecen al **mismo socio**, todas por $80.000, todas `environment = sandbox` con tarjeta
de prueba `4242`. Cinco se crearon en 47 minutos y llevan `subscription_id` 1 a 5: son ensayos del
flujo de suscripción recurrente.

| ID | Pago | Creada | Referencia | Recurrente | Entorno |
|---|---|---|---|---|---|
| 10 | 1005 | 2026-07-11 21:54 | IRON-…WT9YJ4 | no | sandbox |
| 11 | 1006 | 2026-07-13 01:10 | IRON-…GTRDLM | no | sandbox |
| 12 | 1007 | 2026-07-13 01:21 | IRON-SUB-…X13B | sí (sub 1) | sandbox |
| 13 | 1008 | 2026-07-13 01:54 | IRON-SUB-…OVYU | sí (sub 2) | sandbox |
| 14 | 1009 | 2026-07-13 01:55 | IRON-SUB-…MKD3 | sí (sub 3) | sandbox |
| 15 | 1010 | 2026-07-13 01:56 | IRON-SUB-…CMBX | sí (sub 4) | sandbox |
| 16 | 1011 | 2026-07-13 01:57 | IRON-SUB-…ZRTZ | sí (sub 5) | sandbox |

**Conclusión: no son siete cobros distintos, son pruebas.** No deben facturarse. No se cancelan ni
se eliminan por ahora, según instrucción.

## 7. Bloqueo vigente

`FactusClient::assertEmissionAllowed()` aborta **cualquier POST** a Factus mientras
`FACTUS_TAX_DECISION_CONFIRMED=false`. Se sitúa en el despachador HTTP genérico, no en
`createInvoice()`, porque:

- `WompiTransactionService` y `PaymentMembershipActivator` fuerzan la emisión **sin consultar**
  `auto_emit`, de modo que apagar ese flag no habría bastado;
- un job encolado puede reintentar por su cuenta;
- ocultar botones en el CRM no bloquea nada.

Los `GET` siguen permitidos: la reconciliación fiscal necesita leer, y leer no crea documentos ni
consume consecutivos.

Verificado por `tests/Feature/Billing/EmissionBlockedTest.php` (8 pruebas), que afirma la ausencia de
tráfico HTTP, no solo la excepción.

## 8. Bloqueo del contrato con el proveedor

La API V2 exige `items[].taxes` y solo admite dos formas:

| Forma | Significado ante la DIAN | ¿Aplicable? |
|---|---|---|
| `[{code:'01', rate:'0.00'}]` | bien **exento** (gravado a tarifa 0 %) | No: el servicio no es exento |
| `[{is_excluded:true}]` | bien **excluido** por naturaleza | No: el servicio no es excluido |
| omitir el bloque | — | Rechazado: `HTTP 422` |
| `[{is_excluded:false}]` sin `code`/`rate` | — | Rechazado: `HTTP 422` |

El IVA cero proviene de la **condición del emisor**, no de la naturaleza del producto. Ninguna de las
dos formas admitidas describe eso.

Consulta enviada a Halltec: ver `docs/factus/CONSULTA_HALLTEC_R99PN.md`. Dato relevante hallado
durante la investigación: el payload de `POST /v2/bills/validate` **no contiene bloque fiscal del
emisor** (solo `customer` e `items`), y la respuesta `GET` tampoco expone la responsabilidad
tributaria dentro de `company`. Todo indica que la responsabilidad se configura en la cuenta.

## 9. Qué NO se ha hecho, deliberadamente

- No se modificó ningún documento IBFE1–IBFE8.
- No se emitieron notas crédito.
- No se reemitió ningún documento.
- No se alteraron consecutivos, CUFE, PDF, XML ni estados DIAN.
- No se corrigieron los importes contables locales: es decisión del contador.
- No se procesaron las solicitudes ID 9–16.
- La solicitud ID 9 sigue en `rejected`, sin número ni CUFE. Los `422` son prevalidación, así que
  **no se consumió consecutivo alguno**.

## 10. Decisiones pendientes

| Decisión | Responsable |
|---|---|
| Representación tributaria admitida para emisor no responsable | Halltec / Factus |
| Regularización de los $360.840,34 ya discriminados | Contador |
| Qué hacer con IBFE7/IBFE8, emitidas por pagos de sandbox | Contador / propietario |
| Documentos con pago origen ausente (IBFE1, 3, 4, 5, 6) | Contador / propietario |
| Destino de las solicitudes ID 10–16 (pruebas) | Propietario |
