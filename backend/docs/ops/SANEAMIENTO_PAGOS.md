# Plan de saneamiento de Pagos

**Estado: NO EJECUTADO. Documento de decisión, no procedimiento aprobado.**

Nada de lo que sigue se ha ejecutado. Este documento existe para que la decisión
de borrar se tome sobre datos y no sobre una impresión.

## Punto de partida (producción, 1 de septiembre de 2026)

| | Pagos | Monto |
|---|--:|--:|
| Total | 498 | — |
| Con factura electrónica | 23 | $3.098.000 |
| Monto 0 | 16 | $0 |
| Sostienen una membresía vigente | 32 | $13.490.000 |
| Histórico sin factura | 428 | $44.534.000 |

Rango: 12 de junio a 1 de septiembre de 2026. **Ninguna referencia contiene
«test», «demo» ni «prueba»**: no hay una marca que distinga los pagos de ensayo
de los reales, y ese es el problema central de este saneamiento.

## Qué ata cada pago

`payments` tiene claves salientes (`user_id`, `plan_id`, `member_id`) y
**ninguna tabla apunta a `payments` con clave foránea**. Borrar una fila no
arrastraría nada por cascada — y justamente por eso es peligroso: lo que quedaría
roto no lo impediría la base de datos.

Ataduras reales, todas fuera del alcance de una FK:

1. **`electronic_invoices`** enlaza de forma polimórfica (`source_type` +
   `source_id`) sin restricción. Ya existen en producción **6 facturas
   huérfanas** de pagos borrados en el pasado; el modelo `Payment` bloquea desde
   entonces el borrado de un pago con comprobante, y ese guard sigue vigente.
2. **`users.membership_end_date`** la extiende `applyMembershipExtension()` al
   confirmar el pago. Borrar el pago **no revierte la vigencia**: el miembro
   conserva el acceso y desaparece la explicación de por qué lo tiene.
3. **Ganancias e informes** (`EarningsController`, `ReportsOverviewController`)
   suman sobre `payments`. Borrar cambia cifras históricas ya reportadas.
4. **`audit_logs`** referencia pagos por `entity_id`, en texto y sin FK.

## Clasificación

| Clase | Pagos | Decisión |
|---|--:|---|
| **NO BORRABLE** — tiene factura electrónica | 23 | La vía para revertir un comprobante fiscal es la nota crédito, no borrar su origen. El modelo ya lo impide. |
| **CONSERVAR** — sostiene una membresía vigente | 32 | Borrarlo dejaría a un miembro con acceso sin justificación. |
| **REVISAR** — monto 0 | 16 | Candidatos probables a ensayo, pero **no demostrado**. Hay que mirarlos uno a uno antes de tocarlos. |
| **ARCHIVABLE** — histórico sin factura ni membresía viva | 428 | Solo si se decide que el histórico anterior a una fecha no debe conservarse. |

## Lo que falta antes de poder ejecutar nada

1. **Un criterio del negocio, no técnico.** Nadie puede distinguir hoy un pago
   de prueba de uno real: no hay marca, ni referencia, ni rango de fechas que los
   separe. Sin ese criterio, cualquier borrado es adivinar.
2. **`payments` no tiene borrado lógico.** Añadir `SoftDeletes` convertiría el
   saneamiento en reversible y haría innecesaria buena parte de este documento.
   Es un cambio pequeño y es la recomendación.
3. **Decidir qué pasa con la vigencia.** Si se borra un pago que extendió una
   membresía, ¿se recorta la vigencia o se deja? La respuesta la da el negocio.

## Procedimiento, cuando haya criterio

```
1. Respaldo verificado    → /usr/local/sbin/ironbody-db-backup.sh
                            (comprueba sha256 y recuento de objetos)
2. Conteo previo          → total, por clase, suma por clase
3. Selección explícita    → lista de IDs, guardada en fichero, revisada
4. Dry-run                → SELECT que muestre exactamente qué se tocaría
5. Impacto declarado      → cuántas membresías, cuánto monto, qué informes
6. Ejecución en transacción, con el conteo posterior dentro
7. Comprobación           → facturas huérfanas = 0, membresías vigentes intactas
```

**Rollback:** restaurar el volcado. No hay vuelta atrás fila a fila mientras
`payments` no tenga borrado lógico.

## Recomendación

No borrar. Si el objetivo es entregar el sistema «limpio», **archivar** es
suficiente y no destruye nada: añadir `SoftDeletes` a `payments` y excluir los
archivados de los informes deja los números presentables conservando la
trazabilidad. Borrar 428 pagos por $44.534.000 para que una pantalla se vea
vacía es un precio alto por algo que se consigue filtrando.
