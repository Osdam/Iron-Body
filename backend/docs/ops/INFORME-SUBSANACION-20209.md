# Informe final: subsanación del cobro #20209 y vencimientos de personalizados

**Fecha:** 17-11-2026. **Estado:** aplicado y verificado en producción.

- **Procedimiento:** `docs/ops/RECLASIFICACION-DE-COBROS.md`.
- **Seguimiento:** `docs/ops/VENCIMIENTOS-PERSONALIZADOS.md`.
- **Identificación:** sin nombres. La socia es la ficha 862 / usuario 863, el entrenador la ficha 7516 / usuario 7517.

## Parte A · Subsanación financiera

1. **Causa raíz.** El 06-11-2026 a las 20:43 (Bogotá), recepción cobró $50.000 en efectivo por el piso de noviembre del entrenador externo de la socia. Lo registró como venta del **plan 20 «Comisión de personalizados»**, que es un plan de membresía de 30 días. Consecuencias:
   - la vigencia de la socia se alargó 30 días;
   - el dinero no apareció en Control de personalizados, que solo ve acuerdos y cobros de piso;
   - el entrenador no existía en `trainers`, solo como socio con el plan ACCESO EMPLEADOS.

   Lo permitieron tres fallos del sistema:
   - el plan seguía cobrándose en recepción, porque no había regla de canal de venta;
   - el panel mensual ignoraba la vigencia de los acuerdos;
   - «REC-…» era un contador por navegador: «REC-1001» está en 1.225 cobros.

2. **Estado original del cobro 20209.**
   - $50.000 en efectivo, pagado, el 06-11 a las 20:43 Bogotá (07-11 01:43 UTC).
   - Turno 237 del gimnasio, cerrado el 07-11 a las 14:42 UTC, registrado por el administrador 9.
   - Plan 20, periodo 2026-11-09 → 2026-12-09.
   - Recibo RG-000402 y borrador de factura 1399 pendiente, sin número ni envío. Referencia REC-1001.

3. **Solución técnica.**
   - **Procedimiento auditado `payments:reclassify-to-commission`.** Ensaya por defecto y confirma con `--apply`. Tiene 26 invariantes y lista blanca de escrituras comprobada sentencia a sentencia, más cerrojo consultivo; es idempotente. Deja una constancia inmutable (`payment_reclassifications`) con la fecha económica y la fecha real de la corrección.
   - **Canales de venta por plan** (`counter_sellable`).
   - **Vigencia en el panel mensual.**
   - **Analíticas y numeración** sin «REC-» inventado.
   - **Seguimiento de vencimientos** (parte B).
   - **Endurecimiento** tras dos revisiones independientes (§13 del procedimiento).

4. **Movimientos.**
   - Anulación oficial del cobro 20209: no se borra nada, y la vigencia se recalcula con el cálculo del sistema.
   - Cobro del piso de noviembre #8 del acuerdo #7, con su deuda #256.
   - Abono #247 que salda la deuda en el **mismo turno 237**, con la fecha económica del 07-11 01:43:08 UTC y firmado por el administrador 9.
   - Sin recibo nuevo: vale RG-000402.
   - Borrador de factura 1399 cancelado con motivo.
   - Constancia #2.

5. **Identificadores nuevos.**

   | Registro | Id |
   |---|---|
   | Entrenador | #21 |
   | Acuerdo | #7 |
   | Cobro del mes | #8 |
   | Deuda | #256 |
   | Abono | #247 |
   | Constancia | #2 |

   Los números saltan uno porque el ensayo en seco consumió valores de las secuencias, y PostgreSQL no los devuelve con ROLLBACK.

6. **Caja antes y después.**

   | Medida | Antes | Después |
   |---|---|---|
   | Turno 237 (congelado = en vivo) | $1.749.002 | $1.749.002 |
   | Efectivo del turno 237 | 15 cobros = $1.259.001 | 14 cobros ($1.209.001) + 1 abono ($50.000) = $1.259.001 |
   | Dinero entrado el 06-11 (Bogotá) | $1.749.002 + $3.000 = $1.752.002 | $1.699.002 + $53.000 = $1.752.002 |
   | «Recaudado» del libro del día | $1.931.001 | $1.931.001 |

   En el turno, el efectivo es $1.259.001 y la transferencia $490.001, en 23 operaciones.

7. **Conciliación del turno 237.** Cuadra: el congelado coincide con el cálculo en vivo antes y después. El cierre firmado no cambió (`cash_shifts` intacto).

8. **Acuerdo.** Entrenador #21 (contrato Independiente, **inactivo** y sin roles) → socia. $50.000 cada 30 días, paga la socia, desde el 2026-11-06. No bloquea la app.

9. **Cobro de noviembre.** El #8 cubre del 2026-11-06 al 2026-12-05; la deuda #256 está pagada. Control de personalizados lo muestra «al día» hasta el 05-12.

10. **Vencimiento de la anualidad.** Pasa de 2027-12-09 a **2027-11-09**: 30 días menos, por el cálculo oficial e igual a la vista previa.

11. **Plan 20.** Lo archivó el administrador 26 el 17-11 a las 18:27 UTC, con el motivo «No se manejará el plan». Está inactivo, no visible en la app y sin cobro en recepción. El procedimiento no lo tocó. El usuario 5190 lo tiene vigente hasta el 2027-09-19 y **conserva el acceso** (`can_enter = true`).

12. **Controles de venta.**
    - **Mostrador:** activo, no archivado y «Se cobra en recepción».
    - **App y suscripciones:** vendible y visible.
    - **Compra de tienda por Wompi:** no puede llevar plan. Con plan, el importe sale siempre del servidor.

13. **Panel mensual.**
    - Una vigencia sin fechas es una vigencia sin límite.
    - Las filas son los acuerdos en vigor más los que tienen un cobro no anulado en el mes.
    - Acordado = cobrado + pendiente + sin registrar.

14. **Analíticas.**
    - Los ingresos del panel salen de la caja.
    - «Lo que no entró» no cuenta el reclasificado y lo enseña aparte.
    - El total de cobros no suma fallidos ni anulados.
    - El historial y la app lo muestran como el piso pagado.

15. **Estado fiscal.** El borrador 1399 nunca se emitió; se canceló con motivo y traza. No se modificó ningún documento emitido. Facturar el piso es otra decisión y no se ha tomado.

16. **Idempotencia.** La segunda ejecución en producción respondió `already_applied` y no escribió nada. Hay pruebas automáticas del ensayo sin escrituras, de la segunda ejecución y de una sola constancia por cobro.

17. **Concurrencia.** Carreras reales en PostgreSQL:
    - dos `--apply` a la vez → una aplicada y otra `already_applied`, con una constancia y un abono;
    - cobro doble del mismo mes → 201 y 422.

18. **Commits.** Backend `d41d55d4`, `a152c020`, `45f18683`. CRM `9f0a4c8`, `6069acd`, `ce695d7`.

19. **Push.** Backend `7f9219d5..45f18683` y CRM `eeb4b79..ce695d7`, los dos a `main` por avance rápido.

20. **Despliegues.**
    - **Backend `45f18683`** (antes `5267b0ab`): migraciones 100001 y 100002, ~21:28 UTC.
    - **CRM `ce695d7`**: 21:31 UTC.
    - **Corrección de datos**: 21:33:06 UTC.

21. **Respaldos** en `/var/backups/ironbody/`:

    | Respaldo | Contenido |
    |---|---|
    | `subsanacion-reclasificacion-20261117-212738` | Volcado completo con sha256, commit previo y cachés |
    | `crm-pre-ce695d7-20261117-213121` | `index.html` y `version.json` previos |
    | `subsanacion-datos-20209-20261117-213254` | Volcado propio justo antes de aplicar |

22. **Salud.**
    - **Backend:** 14 de 14 workers en marcha; 0 errores nuevos y 0 respuestas 5xx; API pública 200.
    - **CRM:** publicado `ce695d7`, con el `main` nuevo y el anterior respondiendo 200.
    - **Base:** la aplicación lee e inserta la constancia, pero no puede modificarla, borrarla ni vaciarla.

23. **Riesgos residuales:** ver el final de este informe.

24. **Otros usuarios.**
    - Las seis huellas salen idénticas antes y después: pagos, usuarios, deudas, acuerdos 1–5, facturas y planes ajenos.
    - En la ventana de la corrección solo cambiaron las filas del procedimiento: cobros 20209 y 20210, usuario 863, deuda 256, abono 247, entrenador 21, acuerdo 7, cobro 8, factura 1399 y constancia 2.
    - No hubo ningún recibo nuevo y no se tocó ningún turno ni ningún plan.

## Parte B · Vencimientos de personalizados

1. **Cálculo.** La obligación es mensual: un cobro vigente por acuerdo y mes, y cada tramo dura `cycle_days` días.
   - **Vencimiento:** el último día cubierto por los tramos pagados (unión de `covers_to`).
   - **Días que faltan:** se cuentan desde hoy en America/Bogota.
   - **Pagos fuera de fecha:** uno adelantado o atrasado no desplaza los vencimientos siguientes.
   - **Sin efectos:** no genera cobros, entradas de caja ni mensajes.

2. **Periodicidad.** Ciclos de `cycle_days` días (30 en los cinco acuerdos actuales), con un cobro por mes. No hay periodicidad «calendario» distinta.

3. **Último pago y próximo vencimiento.**
   - Cada fila trae el último pago (fecha en Bogotá), `covered_until` y `days_remaining`.
   - La deuda registrada va aparte (`registered_debt`): una deuda registrada no equivale a «vencido».

4. **Alertas.**
   - Estados: al día / próximo / vence hoy / vencido / sin registrar / inactivo.
   - La ventana es configurable de 1 a 30 días (7 por defecto) y hay filtros por estado.
   - `needs_review` marca lo que hay que mirar.

5. **Histórico.** Cada periodo muestra plazo (solo «confirmado» si la deuda tiene `due_at`), pagos y fecha de pago en Bogotá, días de retraso y reclasificaciones.

6. **La socia y el entrenador.** Acuerdo #7 «al día», cubierto hasta el 2026-12-05, con 18 días por delante el 17-11. El periodo pagado lleva la fecha económica original, sin deuda ficticia y sin otro pago.

7. **Sin duplicados.**
   - Un solo cobro vigente por acuerdo y mes; el cobro bloquea la fila, y la carrera da 201 y 422.
   - Una sola constancia por cobro.
   - No hay ingresos dobles: los totales del turno y del día son idénticos.

8. **Compatibilidad.** Los acuerdos 1–5 no se tocaron: sus huellas son idénticas. Los acuerdos 2–5 aparecen «sin registrar» en noviembre, porque no tienen cobro de noviembre; eso no se interpreta como impago.

9. **Pruebas.** Backend, pasada final sobre las zonas tocadas (1.788 pruebas):

   | Motor | Resultado |
   |---|---|
   | SQLite | 0 fallos |
   | PostgreSQL | 7 errores `25P02` previos (webhook duplicado) y 1 fallo de temporización de `ReceivablePaginationTest::test_17`, que aislado pasa 3 de 3 |

   - Las pruebas nuevas fallan sobre el commit anterior y pasan con el arreglo.
   - Ensayo «como producción», con rol sin UPDATE/DELETE en la constancia y caché en base de datos: en verde, también con el plan 20 archivado.
   - CRM: build y literales correctos. La suite solo tiene los 16 fallos que ya estaban en `main`; los tiempos agotados bajo carga pasan aislados.

10. **Commits, push y despliegue.** Ver los puntos 18–20 de la parte A.

## Riesgos residuales y decisiones pendientes

- **«Corregir medio».** El abono reclasificado lo admite: es una operación auditada que mueve dinero entre medios del arqueo, sin crearlo ni destruirlo.
- **Pestañas abiertas antes del despliegue.** Conservan el código anterior hasta recargar:
  - en Cuentas por cobrar ofrecerían «Anular», que el servidor rechaza con 422;
  - en Pagos seguirían inventando «REC-…».
- **Diciembre.** El piso de noviembre cubre hasta el 05-12, así que el de diciembre debería cubrir desde el 06-12. Cobrarlo desde el día 1 solaparía cinco días; es una decisión de negocio.
- **Acuerdos 2–5.** No tienen cobro de noviembre registrado: hay que confirmar si pagaron.
- **Notas del acuerdo.** Editar un acuerdo borra sus notas; ya pasaba antes.
- **Total de cobros e informes.** `stats.total_amount` sigue contando los pendientes, y `/api/reports/stats` no lo usa nadie.
- **Categoría de los pisos.** Salen en «Abonos»; no hay una categoría propia.
- **Wompi** (para seguridad de API):
  - `reference` y `order_id` del cuerpo llegan al servicio;
  - `order_id` no se filtra por socio, aunque hoy nadie lo fija;
  - el importe de la tienda lo pone el cliente, por diseño previo.
- **Anomalía por revisar.** Tres transacciones «Evolution» de $50.000 (plan de $1,8 M) del 06-11.
- **Borrado de entrenadores.** No deja rastro en AuditTrail.
- **CRM.** Hay un cambio ajeno sin commit en `member-details-modal.ts` del checkout principal.
- **Caso de Keyla.** Sigue pendiente; no se tocó.
