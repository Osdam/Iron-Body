# Plan de retirada de los planes comerciales «de empleados»

Estado: **AUDITADO** (solo lectura, 16-10-2026) · **PREPARADO** · **NO EJECUTADO** — y **BLOQUEADO** hasta verificar el software local de ingreso (ver `INFORME_INTEGRACION_SOFTWARE_LOCAL.md`).

## 1. Qué hay en producción

| Nombre en `users.plan` | ¿Plan en catálogo? | Características | Personas que lo llevan hoy |
|---|---|---|---|
| «INGRESO EMPLEADOS» | sí, **id 16** | $1, 360 días, acceso ilimitado, activo, **no vendible**, visible en la app, facturable | 4 |
| «ACCESO EMPLEADOS» | **no** (nombre sin plan: reglas «sin restricción») | — | 4 |

No es el mismo registro: «ACCESO EMPLEADOS» es un nombre heredado sin fila en `plans`. «Comisión de personalizados» (id 20, $50.000, 81 pagos reales) **no** es un plan de empleados: es el cobro de comisión de entrenadores externos y queda fuera de esta retirada. «TOTAL ACCES ESPECIAL» (1 persona) es una promoción, tampoco.

Pagos del plan 16: 9 pagados (total $4), 4 anulados, 1 pendiente. El backend **no tiene ningún trato especial** para estos nombres (ni el id 16) en el código.

## 2. Personas afectadas (ids, sin datos personales)

| user_id | Nombre de plan | Vigencia | Perfil laboral | Entrenador | EmployeeAccess | Entra hoy por… |
|---|---|---|---|---|---|---|
| 584 | INGRESO EMPLEADOS | → 2028-05-06 | no | no | **no** | membresía |
| 670 | INGRESO EMPLEADOS | → 2027-09-17 | no | no | **no** | membresía (tiene además un cobro pendiente del plan 16) |
| 7702 (Yuri) | INGRESO EMPLEADOS | → 2027-09-18 | **sí** (Administrativo) | no | **sí** (manual, hasta 2026-12-14) | **empleado** (23 entradas faciales con `access_via=employee`) |
| 7742 | INGRESO EMPLEADOS | → 2027-09-25 | no | **sí** (#11, activo) | **no** | membresía |
| 1026 | ACCESO EMPLEADOS | → 2027-08-31 | no | **sí** (#6) | **no** | membresía |
| 1032 | ACCESO EMPLEADOS | → 2027-08-31 | no | **sí** (#7) | **no** | membresía |
| 7517 | ACCESO EMPLEADOS | → 2027-09-02 | no | no | **no** | membresía |
| 7521 | ACCESO EMPLEADOS | → 2028-09-02 | no | **sí** (#5) | **no** | membresía |

- **Dependen todavía del plan comercial: 7 de 8** (todos menos Yuri).
- **Duplicado**: Yuri tiene plan + `EmployeeAccess` (dos puertas; el backend usa la de empleado).
- Historial: todos tienen pagos (los de «ACCESO EMPLEADOS» sin `plan_id`, heredados); 6 tienen contrato firmado; ninguno tiene cuentas por cobrar abiertas ni facturas. **Nada de eso se toca.**
- `EmployeeAccess` en producción: 5, todos `manual`. Tres personas (users 7843, 7853, 6962) ya dependen **solo** de `EmployeeAccess` (sin membresía vigente): no registran entradas desde que se les concedió (14–15-10), así que todavía no hay prueba física de que el torniquete les abra.

## 3. Por qué NO se puede retirar todavía

1. **Software local de ingreso** (decide la puerta física): con evidencia de producción (106 entradas registradas en 30 días que el backend marcaba como no autorizadas: 102 «aún no empieza» y 4 «vencida»), el lector **no obedece la decisión del backend (`access.can_enter`)**: decide por su cuenta, muy probablemente por la fecha de vencimiento. Un trabajador sin membresía y solo con `EmployeeAccess` probablemente sería **rechazado en el torniquete**. Sin el código ni la configuración del lector no se puede confirmar.
2. **Terminal web del CRM («Punto físico»)**: exigía un pago confirmado e ignoraba la vía de empleado (el pago de $1 es justo lo que abría esa puerta). Corregido en este cierre (ver `INFORME_IMPLEMENTACION.md`).
3. **App del socio**: sin membresía activa la app no muestra el Home ni las funciones del plan. Decidir si los trabajadores sin plan deben tener la app completa (decisión de negocio).

## 4. Plan de retirada (transición aditiva, reversible)

| Paso | Qué | Quién autoriza | Verificación |
|---|---|---|---|
| 0 | **Verificar el software local** (código/configuración o piloto controlado): ¿abre a quien solo tiene `EmployeeAccess`? | Propietario + proveedor del lector | Una persona autorizada, sin membresía, con `EmployeeAccess`, entra físicamente y queda una asistencia `access_via=employee` |
| 1 | Conceder `EmployeeAccess` (Miembros → Acceso de empleado, `source=manual`, cargo y fecha fin) a los 7 dependientes **además** de su plan (es otra puerta: no quita nada) | Propietario (uno a uno, con motivo) | Ficha laboral / Miembros muestran el acceso; el backend da `via=employee` |
| 2 | Dar de alta el perfil laboral (Personal y biometría) de quien sea trabajador y no lo tenga (users 584, 670, 7517 — confirmar su cargo) | Propietario | Ficha laboral |
| 3 | Dejar que el plan **caduque solo**: no renovar, no anular, no cambiar fechas | Propietario | — |
| 4 | Retirar el plan 16 de la app (desmarcar «Visible en la app»). **No borrarlo** (lo referencian 14 pagos) | Propietario | Planes |
| 5 | Al irse un trabajador: desactivar su perfil laboral **y** retirar su `EmployeeAccess` (hoy la baja laboral no lo apaga sola) | Administración | Ficha laboral muestra el aviso |

**Prohibido en este plan:** borrar planes, pagos, contratos o facturas; anular membresías en masa; cambiar `users.plan` o fechas a mano; revocar un acceso antes de comprobar que su sustituto funciona en la puerta física.

## 5. Criterio de cierre

Retirada completa cuando: los 8 tienen `EmployeeAccess` vigente; el lector local abre a quien solo tiene `EmployeeAccess` (verificado); ninguna persona activa depende de los nombres «INGRESO EMPLEADOS»/«ACCESO EMPLEADOS» para entrar.
