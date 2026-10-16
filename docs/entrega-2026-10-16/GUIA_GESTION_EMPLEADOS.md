# Guía de gestión de empleados — CRM Iron Body

Para administración. Todo se hace desde el CRM; el servidor comprueba los permisos aunque un botón se vea.

## Tres cosas distintas (no mezclarlas)

| Concepto | Qué es | Dónde se gestiona |
|---|---|---|
| **Perfil laboral** | La persona trabaja aquí: cargo, jornada, biometría laboral | Supervisión de empleados y clases → **Personal y biometría** |
| **Acceso gratuito** | Puede entrar al gimnasio sin pagar (`EmployeeAccess`) | **Miembros → Acceso de empleado** (y desde la ficha laboral cuando la ficha de socio está vinculada) |
| **Cuenta del CRM** | Puede entrar al CRM con un rol (Recepción, Administrativo…) | Configuración → Usuarios y roles |

Ser trabajador **no** da permisos en el CRM ni acceso gratuito: cada cosa se concede por separado. Un trabajador **no necesita** un plan comercial (como «INGRESO EMPLEADOS») para registrar su jornada.

## 1. Registrar a un empleado

1. Personal y biometría → **Agregar personal** → escribir el documento → **Comprobar**.
2. El CRM dice si la persona ya existe (como entrenador, personal o socia). Si ya existe, marcar **«Es la misma persona»**. Si cambias el documento, hay que comprobar otra vez.
3. Elegir el **cargo**: Entrenador de planta, Entrenador funcional, Recepción, Administrativo, Aseo y mantenimiento.
4. Guardar. Se crea el perfil laboral. **No** se toca su ficha de socio, su plan, sus pagos ni su acceso gratuito.

## 2. Ficha laboral

En la lista de Personal, botón **Ficha** (o clic en el nombre). Muestra:
- Cargo, si registra jornada, si dicta clases, estado (activo/inactivo).
- Jornada: si está trabajando ahora, la última jornada, incidencias pendientes.
- Biometría laboral: estado (sin registrar, pendiente de renovación, registrada).
- Membresía pagada y acceso gratuito, **por separado** (solo si la ficha de socio está vinculada).
- Story Live y cuenta del CRM (solo lectura).

**Administrativo sin horario fijo**: registra entrada y salida con la hora del servidor; se calcula la duración; **no se calcula impuntualidad**.

## 3. Biometría laboral (tablet de recepción)

1. Ficha → **Abrir biometría** → **Autorizar enrolamiento** (genera un código de un solo uso).
2. La persona, **presente y con su consentimiento**, va a la tablet → Jornada laboral → código → acepta el consentimiento → 3 capturas.
3. Nunca registrar el rostro de otra persona bajo la identidad de un empleado.

Esta biometría es **solo para la jornada laboral**. No es la misma que usa el software de ingreso al gimnasio (ver `INFORME_INTEGRACION_SOFTWARE_LOCAL.md`).

## 4. Registrar y consultar jornadas

- En la tablet: Jornada laboral → reconocimiento facial (o **código de respaldo**) → Entrada / Salida.
- **Código de respaldo**: Ficha → **Código de respaldo** (motivo obligatorio). Sirve una vez, para esa persona y esa operación.
- Consultar: Supervisión de empleados y clases → pestaña **Empleados** (jornadas, con filtros por persona y fecha y el detalle de cada jornada).
- Salida olvidada: tras 16 h la jornada queda como **incidencia** (sin inventar la hora); un administrador la resuelve con motivo.

## 5. Acceso gratuito al gimnasio

- **Miembros** → buscar a la persona → **Acceso de empleado** → activar, cargo, fecha de inicio y fin (opcional: días y franjas).
- Para entrenadores con comisiones, el acceso puede venir de las comisiones («Por comisiones de entrenador»); el acceso «Dado a mano» no se pierde por cambios en las comisiones.
- Hoy la persona necesita **ficha de socio** (Member + User) para tener acceso gratuito.
- **Importante**: mientras no se verifique el software local de ingreso, **no quitar** el plan «INGRESO EMPLEADOS» a nadie: el torniquete físico probablemente decide por la membresía (ver `PLAN_RETIRO_INGRESO_EMPLEADOS.md`).

## 6. Relación con Miembros (vínculo de la ficha de socio)

- La ficha laboral muestra si la persona **coincide por documento** con una ficha de socio. Coincidir por documento **no prueba** que sea la misma persona.
- El vínculo verificado por SMS está **desactivado** en producción mientras se completa su revisión de seguridad; el acceso gratuito se sigue gestionando desde **Miembros → Acceso de empleado**.

## 7. Entrenadores

- Se gestionan en Entrenadores (perfil, clases, comisiones) y aparecen en Personal con su cargo.
- Entrenador de planta: registra jornada y da clases. Entrenador funcional: solo clases.
- Las reglas de comisiones no cambian con el perfil laboral.

## 8. Dar de baja a un trabajador

1. Ficha → **Editar** → estado **Inactivo** (o desactivar en Entrenadores).
2. Efecto automático: se borra su plantilla facial laboral (queda la traza) y, si tenía una jornada abierta, queda como incidencia.
3. **No** se retira solo el acceso gratuito: retirarlo a mano en **Miembros → Acceso de empleado** (la ficha avisa si sigue activo).
4. Si tenía cuenta del CRM, desactivarla en Usuarios y roles.

## 9. Permisos

| Acción | Permiso |
|---|---|
| Ver Personal y fichas | `workforce.view` |
| Alta, edición, baja, códigos de respaldo | `workforce.manage` |
| Biometría (ver / autorizar o revocar) | `biometrics.view` / `biometrics.manage` |
| Ver el acceso gratuito en la ficha | `workforce.view` + `members.view` |
| Dar o quitar acceso gratuito | `members.employee` |
| Trasladar socios entre planes | `plans.transfer` |

Recepción no gestiona personal ni biometría; el servidor lo impide aunque se intente.

## 10. Traslados de planes

Ver `GUIA_MIGRACION_PLANES.md`.
