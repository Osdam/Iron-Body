# Guía — Traslado de socios entre planes

Estado: **DESPLEGADO** (backend `b1ce780`, CRM `5886982`, 16-10-2026) · simulación en producción **PROBADA** · **lote real «TOTAL ACCESS - ELITE» → «Élite» NO EJECUTADO** (requiere autorización independiente).

## 1. Qué hace y qué no hace

Trasladar = cambiar **solo** `users.plan` al nombre exacto del plan destino. No cambia: fechas de inicio y vencimiento, días restantes, entradas usadas (se cuentan de la asistencia), pagos, facturas, cuentas por cobrar, contratos, accesos de empleado. No crea cobros, facturas ni notificaciones a socios (solo una señal silenciosa a la app para que refresque sus funciones).

Quién puede: permiso `plans.transfer` (Super Admin y Administrador). Recepción, Entrenador, Administrativo y el token de automatizaciones reciben 403, también en las consultas.

## 2. Procedimiento en el CRM

1. **Planes → «Trasladar socios»**. Elegir plan de origen y de destino.
2. **Candidatos**: la lista marca los elegibles; los bloqueados no se pueden seleccionar y dicen por qué. Los documentos aparecen enmascarados.
3. **Simular** (no escribe nada): diferencias entre los planes (precio, duración, acceso, funciones, IRON IA, beneficios), y por socio: fechas antes/después (iguales), entradas usadas, advertencias y excluidos.
4. Si hay advertencias, marcar **«Revisé las N advertencias»**.
5. **Confirmar** escribiendo el motivo (5–500 caracteres). El servidor vuelve a calcular todo con los socios bloqueados: si algo cambió desde la simulación, responde «simulación obsoleta» y muestra la nueva; no se aplica nada.
6. **Resultado**: detalle del lote. Repetir el mismo envío no duplica (responde «ya aplicado»).
7. **Historial → detalle → «Solicitar reversión»**: se simula también; solo se revierten los socios que no cambiaron después (pago, ajuste, cambio de plan). La reversión es un lote nuevo; nada se borra.

## 3. Simulación vigente del caso real (regenerada el 16-10-2026, 07:26 UTC, sin escribir nada)

| | Origen | Destino |
|---|---|---|
| Plan | id 22 «TOTAL ACCESS - ELITE» | id 8 «Élite» (con tilde) |
| Precio / duración | 180.000 / 30 días | 180.000 / 30 días |
| Acceso | ilimitado | ilimitado |
| Nivel comercial | lite | premium |
| IVA / modo de precio | tarifa 4 / legacy_inclusive | tarifa 4 / legacy_inclusive |
| Vendible / visible en la app | **sí / sí** | sí / sí |

Candidatos: **26** a las 07:26 UTC (todos elegibles; 24 vigentes y 2 con inicio futuro; 0 bloqueados) y **27** a las 12:38 UTC (25 vigentes y 2 futuros). **La cifra cambia porque el plan 22 se sigue vendiendo en recepción**: 4 cobros nuevos del plan 22 entre el 15-10 23:52 y el 16-10 11:42 UTC (users 7855, 7857, 7858 y 593). Hasta retirar el plan 22 de la venta, cada día puede haber socios nuevos en el origen; por eso la simulación debe regenerarse el mismo día de la ejecución.

Diferencias:

| Concepto | Origen → Destino | Tipo |
|---|---|---|
| Mensajes de IRON IA al día | 5 → 25 | ganancia |
| Mensajes de IRON IA al mes | 20 → 150 | ganancia |
| Audios de IRON IA al mes | 10 → 50 | ganancia |
| Imágenes de IRON IA al mes | 10 → 30 | ganancia |
| Duración máxima de audio | 60 → 90 s | ganancia |
| Contexto de IRON IA | personalized → full | cambio |
| Beneficios que ve el socio | lista corta «plan heredado» → lista completa de Élite | cambio |
| Segmento comercial | lite → premium | cambio |

Advertencias de plan: `ai_differs` (IRON IA cambia) y **`from_plan_still_sellable`** (el plan 22 se sigue vendiendo y se ve en la app).

Advertencias por socio (6 socios, por id de usuario):

| user_id | Situación | Vigencia | Advertencias |
|---|---|---|---|
| 7677 | vigente | 2026-09-19 → 2026-11-18 | periodo del plan origen ya pagado por adelantado |
| 7678 | vigente | 2026-09-19 → 2026-11-18 | periodo prepagado |
| 7804 | inicio futuro | 2026-10-19 → 2026-11-18 | inicio futuro; periodo prepagado |
| 7855 | inicio futuro | 2026-10-19 → 2026-11-18 | inicio futuro; periodo prepagado; cuenta por cobrar abierta |
| 7733 | vigente | 2026-09-30 → 2026-10-30 | cuenta por cobrar abierta; beneficios retenidos por deuda |
| 7734 | vigente | 2026-09-30 → 2026-10-30 | cuenta por cobrar abierta; beneficios retenidos por deuda |

Comprobado además en solo lectura (15-10): 0 cobros pendientes del plan 22, 0 suscripciones recurrentes, 0 mejoras de plan en el historial, 0 congelados, 0 con acceso de empleado.

## 4. Antes de ejecutar el lote real (requiere autorización del propietario)

1. **Retirar el plan 22 de la venta** (decisión comercial): en Planes, editar «TOTAL ACCESS - ELITE» → desmarcar **Vendible** y **Visible en la app**. NO borrar el plan (32 pagos lo referencian; borrarlo los dejaría sin plan). Sin este paso, cualquier venta nueva del plan 22 devolverá al socio al plan 22.
2. Regenerar la simulación el mismo día y revisar que siguen siendo los mismos socios.
3. Ejecutar con motivo («Unificación TOTAL ACCESS - ELITE en Élite autorizada por …»).
4. Verificar en el detalle: 26 ítems aplicados, fechas iguales, ningún cobro ni factura nuevos.

Riesgos conocidos: anular más adelante un cobro del plan 22 no restaura el plan (fallo conocido de la anulación, fuera de este trabajo); informes: los suscriptores pasan a «Élite», las ventas históricas siguen en el plan 22 (correcto).

## 5. Reversión

Desde el detalle del lote. Excluye automáticamente a quien cambió después (motivos `plan_changed_after`, `membership_changed_after`, `paid_after`). Lo que no se puede deshacer y la herramienta lo dice: entradas y reservas hechas con el plan destino, uso de IRON IA, señales ya entregadas.
