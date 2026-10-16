# Informe — Integración con el software local de control de ingreso

Estado: **PARCIALMENTE VERIFICADO** con evidencia del servidor · **validación del software local BLOQUEADA**: su código fuente no está disponible (el directorio `IRON_BODY/software local` está vacío) y no hay acceso al equipo de recepción.

Etiquetas: **[V]** verificado (código o datos de producción) · **[I]** inferido con evidencia indirecta · **[D]** desconocido.

## 1. Qué es y cómo habla con el servidor

- **Qué es** [I]: aplicación local en C# (documentación interna `.claude/agents/Auditor-backend.md`): cámara, reconocimiento facial propio, relés/ESP32 del torniquete, base de datos local.
- **Cómo se identifica** [V, producción]: desde la IP pública de la sede, **sin user-agent**, con HTTP/1.1, usando una **sesión de un administrador de rol «Recepción»** (admin 9; la sesión se renueva cada ~12 h). No usa el token compartido de automatizaciones.
- **Qué consulta** [V, logs de nginx, ~2 ficheros recientes]:

| Llamada | Veces | Para qué |
|---|---|---|
| `GET /api/users?page=N` | ~105.800 | Sincroniza **todos** los socios página a página. Cada fila trae `plan`, fechas y el bloque `access` (`can_enter`, `via`, `reason`, reglas de empleado) |
| `POST /api/attendances` | 747 | Registra entradas y salidas (`source=facial`; `auto-close` al cerrar el día) |
| `GET /api/attendances/face-references` | 476 | Descarga el catálogo de rostros **de acceso** (tabla `member_biometrics`) |
| `GET /api/attendances/face-image/{id}` | 11 | Descarga imágenes faciales concretas |
| `GET /api/admin/payments/latest-per-member` | 0 | — (no decide por pagos) |
| `/api/turnstile*` | 0 | — (no usa los endpoints de torniquete del servidor) |

- **Base de datos directa** [D]: nada lo indica ni lo descarta.
- **Modo sin conexión** [I]: decide con lo sincronizado y reintenta las subidas; descarta los 4xx.

## 2. ¿Respeta `EmployeeAccess`?

- **Backend** [V]: sí. `MembershipAccess` evalúa primero la puerta de empleado (`EmployeeAccess` activo y dentro de `starts_on`/`ends_on`, con días y franjas) y después la de membresía. Las entradas de Yuri se registran con `access_via=employee` y rol «Administrador» (23 entradas faciales desde el 09-10).
- **Software local** [I, evidencia fuerte de que NO]: en 30 días hay **106 entradas que el lector dejó pasar y el backend marcó como no autorizadas** (`over_limit`): 102 con motivo «la membresía aún no empieza» y 4 «vencida». Si obedeciera `access.can_enter` no existirían. Conclusión: decide con su propia lógica, muy probablemente solo por la fecha de vencimiento.
- **Consecuencia**: una persona con `EmployeeAccess` y **sin** membresía probablemente **no** pasaría el torniquete. Las tres personas que hoy solo tienen `EmployeeAccess` no han registrado ninguna entrada desde que se les concedió (14–15-10), así que no hay prueba física en ningún sentido.
- **Terminal web del CRM («Punto físico»)** [V]: rechazaba a los empleados sin pago confirmado (exigía el último pago «pagado»; el pago de $1 de «INGRESO EMPLEADOS» era lo que abría esa puerta). Corregido y desplegado en este cierre: acepta la decisión del servidor cuando es de empleado.

## 3. Matriz de casos (backend)

| Caso | Backend | Software local |
|---|---|---|
| EmployeeAccess activo, sin membresía | entra (`via=employee`) | [D] probablemente no |
| EmployeeAccess vencido o revocado, sin membresía | no entra | [D] |
| EmployeeAccess fuera de su día/franja | no entra (mensaje de empleado) | [D] |
| Trabajador dado de baja con EmployeeAccess manual activo | **entra** (la baja laboral no apaga el acceso) | [D] |
| Con otro plan vigente | entra (empleado o membresía) | entra si la fecha está vigente [I] |
| Membresía que aún no empieza | no entra | **entra** [V: 102 casos] |
| Sin conexión / reconexión | la hora registrada es la de subida, no la de entrada [V] | [D] |

## 4. Enrolamiento facial de acceso

- Se guarda en `member_biometrics` como imagen en disco privado del servidor [V]; entra por el registro en la app, por el CRM («Punto físico») o por el reenrolamiento del login.
- **No exige membresía** [V]: un empleado con ficha de socio (Member + User) puede quedar enrolado.
- El lector descarga esas imágenes del servidor [V, logs]. Que reconozca a un empleado sin membresía depende de si filtra por fechas [D].

## 5. Cambios desplegados en este cierre (16-10-2026, backend `43e6206`, CRM `6a1566c`)

- **Rostros y torniquete exigen persona** — DESPLEGADO y VERIFICADO: el token compartido de automatizaciones recibe 403 en `face-references` y `turnstile` (antes 200: podía descargar todas las imágenes faciales y leer las credenciales del torniquete); `webhook/fire` deja de ser público. La sesión de Recepción del lector sigue recibiendo 200 (718 rostros) y el lector siguió sincronizando con normalidad tras el despliegue (`GET /api/users` 200 ×400, 0 errores 5xx).
- **Entradas de empleado no gastan cupo** de un plan por entradas — DESPLEGADO (probado en local; en producción solo Yuri tiene entradas como empleado y su plan es ilimitado, así que no cambia el recuento de nadie hoy).
- **Terminal web del CRM («Punto físico»)** acepta la decisión del servidor cuando es de empleado — DESPLEGADO; la prueba física con una persona sin membresía queda PENDIENTE.

## 6. Evidencia que falta para cerrar la verificación (bloqueo explícito)

1. Código fuente o binario del software local, y su versión instalada.
2. Su configuración: URL base, cuenta usada, intervalo de sincronización, política sin conexión.
3. Qué campos guarda de `GET /api/users` (la pregunta decisiva: ¿usa `access.can_enter`/`via` o `membership_end_date`/`plan`?).
4. Sus registros locales: denegaciones y reintentos.
5. Prueba física autorizada: una persona sin membresía y con `EmployeeAccess` frente al lector.

**Quién**: propietario + quien mantiene el software local. **Cómo verificar después**: la prueba 5 debe producir una asistencia con `access_via=employee` y apertura física; si el lector la rechaza, hay que cambiarlo para que use `access.can_enter` (el backend ya lo calcula y lo envía en cada página).

## 7. Riesgos

- **Pérdida silenciosa de asistencias**: si caduca o se cambia la sesión de Recepción que usa el lector, o se le retira `classes.manage` a ese rol, las subidas fallan con 401/403 y el lector las descarta.
- Entradas de membresías «que aún no empiezan» y «vencidas» se están permitiendo físicamente (106 en 30 días).
- La baja laboral no retira el `EmployeeAccess` manual ni la imagen facial de acceso.
