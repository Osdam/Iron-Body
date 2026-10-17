# IRON_BODY_VALUATION_SCHEDULING_REPORT — Agenda de valoraciones (Entrenadores › Calendario de citas)

**Fecha:** 17-10-2026.

**Estado: READY_FOR_PRODUCTION_REVIEW.**

El módulo interno está implementado, probado y revisado:
- backend Laravel y CRM Angular;
- pruebas de extremo a extremo reales contra PostgreSQL y en Chrome.

**No se ha hecho push, despliegue ni migración en producción**, como pediste. El canal al socio (app y
push) queda **apagado** hasta verificarlo. WhatsApp no está disponible porque no hay plantilla
aprobada.

| Repositorio | Rama | Base | Commits locales |
|---|---|---|---|
| Monorepo `Iron-Body` | `feat/valoraciones` | `f9638e2`, lo que corre en producción | `b86d3c6` (código y pruebas) + el commit de esta documentación |
| CRM `Iron-Body_Front` | `feat/valoraciones` | `c09c58c`, lo que corre en producción | `6fb16c1` |

---

## 1. Estado de la implementación

| Pieza | Estado |
|---|---|
| Agenda, reglas, estados, conflictos y concurrencia (backend) | Implementado y probado en SQLite y PostgreSQL 14, con carrera real. |
| Disponibilidad: franjas por día, vigencia, bloqueos y descansos | Implementado. |
| API admin (22 rutas) y permisos dinámicos `valuations.*` | Implementado; revisión de seguridad sin hallazgos bloqueantes ni importantes. |
| Calendario en el CRM: mes, semana, día, lista, filtros, detalle y asistente | Implementado y probado contra el backend real en Chrome. |
| Historiales del socio y del entrenador | Implementado. |
| Notificaciones internas filtradas por permiso + alerta sonora opcional | Implementado. El sonido aún hay que oírlo en el PC de recepción. |
| Recordatorios automáticos (scheduler, sin colas, idempotentes) | Implementado; probado con dos pasadas y con tres procesadores a la vez. |
| Avisos al socio (app y push) | Implementado, **desactivado por defecto**. Pendiente de verificar FCM en producción. |
| WhatsApp | **No implementado**: no hay plantilla aprobada (§16). |
| Importación desde Google Calendar | Importador CSV con simulación por defecto + guía de transición. |

## 2. Arquitectura encontrada

- **Agenda comercial (`marketing_appointments`):** es de **prospectos**. La usan ULTRON por WhatsApp
  (cortesías), la analítica de campañas y los eventos comerciales. Su migración dice «NO es agenda de
  entrenadores». Tiene un tipo `assessment` para leads. No detecta conflictos y nadie lee `reminder_at`.
- **Valoraciones como dato:**
  - `professional_assessments`, creadas desde la app del entrenador, con las medidas copiadas a
    `physical_evaluations`;
  - no había ninguna agenda socio↔entrenador.
- **Entrenadores:**
  - `trainers.availability` es un JSON sin validar, con una franja por día, que el CRM rellena con
    08:00–18:00;
  - las **clases activas sí ocupan** al entrenador;
  - **8 entrenadores activos, 0 cuentas CRM vinculadas** (`admins.trainer_id`).
- **RBAC:** mapa central por ruta y catálogo derivado. Tiene tres trampas:
  - el Administrador hereda todo permiso nuevo;
  - el token compartido hereda `*.view`;
  - Super Admin lo tiene todo.
- **Notificaciones del CRM:** bandeja propia (`notifications`), **global y sin filtro por permiso**,
  con SSE y sondeo cada 10 s. No había sonido.
- **Al socio:** notificación en la app + push FCM (Firebase configurado). WhatsApp tiene código de
  plantillas, pero nadie lo llama y no hay plantillas. SMS cerrado por coste.
- **VPS:**
  - un solo `schedule:run` por minuto (root);
  - workers solo para las conexiones `agent`, `commercial`, `media` y `whatsapp`; la conexión por
    defecto no tiene worker;
  - `app.timezone = UTC`;
  - `btree_gist` disponible.

## 3. Arquitectura propuesta e implementada

```
CRM Angular  ──HTTP──▶  /api/admin/valuations/*  ──▶  App\Services\Valuations\*  ──▶ PostgreSQL
 (Entrenadores ›                (controladores finos)        (reglas, cerrojos,          valuation_*
  Calendario de citas)                                        estados, avisos)            + EXCLUDE gist
                                                                     │
       scheduler (cada minuto) ── valuations:process-reminders ──────┤──▶ notifications (bandeja CRM,
                                                                     │       required_permission)
                                                                     └──▶ NotificationService → app/FCM
                                                                             (apagado por defecto)
```

- **Dominio nuevo `valuations`.** No toca la agenda comercial, ULTRON, pagos, caja, Factus, biometría,
  EmployeeAccess, jornadas, clases ni planes.
- **Sin microservicios, colas, SSE ni librerías nuevas.** Toda la lógica de agenda está en Laravel; el
  CRM solo muestra lo que el servidor calcula (`allowed_actions`, huecos, ocupados).

## 4. Decisiones técnicas

1. **Dominio aparte de la agenda comercial.** Las valoraciones de prospectos siguen en Mercadeo y
   ULTRON; esta agenda es de **socios**.
2. **Ubicación pedida por el propietario:** *Entrenadores › Calendario de citas*, como pestaña
   `?tab=citas`.
   - La ruta admite `trainers.view` **o** `valuations.view`.
   - Recepción, que no tiene `trainers.view`, entra directa al calendario.
   - Cada pestaña se descarga aparte.
3. **Disponibilidad propia** (reglas + bloqueos). El horario de la ficha se ofrece solo como
   borrador.
4. **Las clases activas del entrenador bloquean la hora.** Es configurable (`block_on_class_conflict`).
5. **Sin exigencias que el negocio no confirmó:** ni membresía activa ni que el entrenador sea el
   asignado. Si no lo es, el CRM lo avisa.
6. **Sin datos clínicos.** Notas administrativas de ≤ 1000 caracteres; sin medidas. Referencia
   nullable `professional_assessment_id`, sin uso en v1.
7. **Fechas en UTC.** Entrada ISO estricta; sin desfase = Bogotá. Las reglas semanales son hora de pared
   de Bogotá. Producción no tiene ext-intl, así que los textos en español se formatean a mano.
8. **Recordatorios por comando del scheduler**, sin colas: no dependen de los workers.
9. **Socio eliminado:** sus valoraciones se eliminan con él (FK `cascade`), igual que sus evaluaciones
   físicas. Así no se rompe la baja de socios, que tiene efectos previos irreversibles.
10. **Entrenador con valoraciones:** su agenda es historial (FK `restrict`). Borrarlo responde **409**
    «desactívalo en lugar de eliminarlo», antes de cualquier aviso.

**Valores por defecto** (todos se cambian en Ajustes):
- duración 60 min, franjas cada 30 min, margen entre citas 0, antelación mínima 0, horizonte 90 días;
- recordatorios al socio 24 h y 1 h antes (con el canal apagado);
- aviso interno 15 min antes y «sin atender» a los 15 min de la hora.

## 5. Modelos y migraciones

Tres migraciones **aditivas y reversibles**:

| Migración | Contenido |
|---|---|
| `2026_10_17_200001_create_valuation_agenda_tables` | `valuation_appointments`, `valuation_availability_rules`, `valuation_availability_blocks`, `valuation_appointment_events` (append-only), `valuation_reminders` (outbox con clave única por cita, tipo, canal y versión), `valuation_settings` (una fila). |
| `2026_10_17_200002_add_valuation_overlap_guard` | Solo PostgreSQL. `CREATE EXTENSION btree_gist` + `EXCLUDE USING gist (trainer_id WITH =, tsrange(starts_at, ends_at,'[)') WITH &&) WHERE status activo` y CHECK de estados. Tolera **solo** la falta de permiso o de extensión; otro error (p. ej. `lock_timeout`) la hace fallar para reintentar. |
| `2026_10_17_200003_add_required_permission_to_notifications` | Columna nullable en la bandeja existente. |

**Modelos:** `ValuationAppointment`, `ValuationAvailabilityRule`, `ValuationAvailabilityBlock`,
`ValuationAppointmentEvent` (sin `update` ni `delete`), `ValuationReminder`, `ValuationSetting`.
Además, `Notification` gana `required_permission`.

## 6. Endpoints (`/api/admin/valuations`, throttle 120/min por credencial)

| Método y ruta | Permiso |
|---|---|
| `GET /capabilities` | Cualquiera de `valuations.*`. |
| `GET /` (rango ≤ 62 días o historial paginado) · `GET /{uuid}` | `view` / `view_all`, con alcance. |
| `POST /` | `create`. Idempotente por `client_request_id`. |
| `PATCH /{uuid}` · `POST /{uuid}/reschedule` | `update`. |
| `POST /{uuid}/cancel` | `cancel`, con motivo. |
| `POST /{uuid}/confirm` · `/start` · `/complete` · `/no-show` | `complete`. |
| `GET /slots` · `GET /trainers` · `GET /members/by-user/{id}` | `view` / `view_all`. |
| `GET /members/search` | `create`. Solo devuelve el final del documento, sin teléfono ni correo. |
| `GET\|PUT /trainers/{id}/availability` · `GET\|POST\|DELETE /trainers/{id}/blocks` | `manage_availability`, o el propio entrenador para leer. |
| `GET\|PUT /settings` | Leer con `view`; cambiar con `manage_settings`. |

**Códigos estables:**
- **Reserva:** `slot_taken`, `member_overlap`, `class_conflict`, `outside_availability`, `blocked`,
  `in_past`, `too_soon`, `beyond_horizon`.
- **Estado y concurrencia:** `appointment_changed` (versión), `request_conflict`, `invalid_transition`,
  `not_due`, `too_late`.
- **Validación y límites:** `range_too_wide`, `rules_overlap`, `block_overlaps_appointments`,
  `validation_failed`, `too_many_requests`.
- **Acceso y existencia:** `not_found`, `forbidden`, `trainer_inactive`, `member_not_found`.

## 7. Componentes Angular

| Pieza | Qué es |
|---|---|
| `modules/trainers-page.ts` | Pestañas accesibles. `trainers.ts` se monta **sin cambiar ni una línea**. |
| `modules/valuations/valuations-tab.ts` | Contenedor: capacidades, rango visible, filtros, refresco y enlace profundo. |
| `va-time-grid.ts` | Semana y día. Franjas de 30 min, columnas por entrenador en Día con «Todos», citas en carriles paralelos, ocupados moteados sin nombres y línea de «ahora» en Bogotá. |
| `va-month-view.ts`, `va-list-view.ts` | Mes y lista. |
| `va-detail.ts` | Panel lateral con acciones según `allowed_actions`, historial y estado de los avisos. |
| `va-booking-wizard.ts` | Asistente de 4 pasos: socio → entrenador → fecha y hora → confirmar. |
| `va-slot-picker.ts` | Selector de día y hora a partir de los huecos del servidor. |
| `va-availability.ts`, `va-settings.ts` | Editor de disponibilidad y Ajustes. |
| `valuation-histories.ts` | Historial del socio (ficha de Miembros) y del entrenador (ficha de Entrenadores). |
| `valuation-alert.service.ts`, `valuation-alert.ts` | Alerta sonora (WebAudio) e indicador. |
| `valuation-notifications.ts` | Enlace de los avisos de valoraciones a su cita. |
| `services/valuations.service.ts` | Cliente HTTP del dominio, fuera de `api.service.ts`. |

**Diseño:**
- Identidad Iron Body: negro, acento amarillo `#f5c518`, Lexend para el periodo e Inter con cifras
  tabulares, prefijo CSS propio `.va-`.
- **El estado nunca va solo en color:** icono + texto + estilo de borde.
- Responsive con vista Día por defecto en móvil; foco visible y navegación por teclado.
- Ningún componente se acerca al límite de error de 32 kB de CSS. El contenedor `valuations-tab` carga unos 14 kB (por encima del aviso de 8 kB, como otras pantallas grandes del CRM).

## 8. Integración con miembros

- **Búsqueda de socios:** propia (`/members/search`), con nombre, final del documento, estado de
  membresía y entrenador asignado.
- La cita se vincula a `members.id` existente: **no crea socios ni usuarios**.
- **Ficha del socio** (Miembros › detalle): sección «Valoraciones» con la próxima cita y el historial
  paginado. Diff de 3 líneas en el modal, compatible con el trabajo sin commit que hay en el repo
  principal.
- No toca membresías, pagos, contratos, Factus ni biometría.

## 9. Integración con entrenadores

- Reutiliza `trainers`: **no hay otro directorio**.
- Cada entrenador tiene:
  - agenda individual (filtro o vista Día);
  - reglas y bloqueos propios;
  - historial en su ficha: próximas, completadas, canceladas y ausencias.
- **Entrenador con cuenta CRM vinculada** (`admins.trainer_id`) **solo ve y opera sus citas**, por
  permisos y no por nombre de rol. Hoy no hay ninguna cuenta vinculada.
- No toca clases, asignaciones, jornadas, EmployeeAccess, rostro ni códigos de acceso.

## 10. Calendarios y disponibilidad

**Huecos que calcula el servidor:**
1. Se parte de las franjas de la regla del día de la semana, dentro de su vigencia.
2. Se restan los bloqueos (descanso, ausencia, día libre, manual), las clases activas, las citas
   activas ± el margen y lo que esté fuera de la antelación o del horizonte.

**Lo que ve el CRM:**
- `/slots` del día: franjas (`windows`), horas libres (`slots`) y ocupados sin nombres (`busy`).
- Sin disponibilidad configurada **no hay huecos**.

## 11. Prevención de conflictos

- **Reglas en Laravel:** las reglas 1–10 del contrato, comprobadas DENTRO de una transacción con
  cerrojo.
- **Cerrojos:**
  - `FOR NO KEY UPDATE` de los entrenadores implicados, en orden de id, y después del socio;
  - con `FOR UPDATE` se interbloqueaba con inserciones de otros módulos (revisión de BD).
- **Red de seguridad en PostgreSQL:** restricción `EXCLUDE` (gist) sobre la hora del entrenador; su
  23P01 se traduce a `slot_taken`.
- **Doble clic y reintentos:** `client_request_id` único.
- **Cambios concurrentes:** `expected_version` → 409 `appointment_changed`.

**Carrera real en PostgreSQL 14** (servidor con 8 workers):
- de 10 reservas a la misma hora entra **1** y las otras 9 reciben `slot_taken`;
- 10 dobles clics dejan **1** cita;
- reprogramar contra cancelar a la vez queda siempre coherente;
- 3 procesadores de avisos a la vez **no duplican** ninguno.

Repetido en mi E2E: 8 recepciones simultáneas → 1 reserva.

## 12. Roles y permisos (`valuations.*`, asignables por rol en Configuración › Usuarios y roles)

| Permiso | Super Admin | Administrador | Administrativo | Recepción | Entrenador |
|---|:-:|:-:|:-:|:-:|:-:|
| `view` | ✓ | ✓ | ✓ | ✓ | ✓ (solo las suyas) |
| `view_all` | ✓ | ✓ | ✓ | ✓ | — |
| `create` | ✓ | ✓ | — | ✓ | — |
| `update` | ✓ | ✓ | — | ✓ | — |
| `cancel` | ✓ | ✓ | — | ✓ | — |
| `complete` (confirmar, iniciar, completar, no asistió) | ✓ | ✓ | — | — | ✓ |
| `manage_availability` | ✓ | ✓ | — | — | — |
| `manage_settings` | ✓ | — | — | — | — |

- **Token compartido de automatizaciones:** nada de `valuations`.
- **Recepción y «Confirmar»:** por defecto Recepción **no** puede confirmar, iniciar ni completar
  (`complete`). Si queréis que confirme cuando el socio llama, se le asigna ese permiso en Roles.

## 13. Notificaciones internas

- **En la bandeja EXISTENTE.**
  - Nueva columna `required_permission`: las de valoraciones exigen `valuations.view_all` y el
    servidor las oculta (lista, contador, stream, leer y leer todo) a quien no lo tiene.
  - Las notificaciones existentes se comportan igual.
- **Eventos:** creada, reprogramada, cancelada, confirmada, próxima (15 min, prioridad alta) y sin
  atender (+15 min).
- **En el CRM:** pestaña «Valoraciones» en el popover y en Notificaciones; el clic abre la cita
  (`/trainers?tab=citas&cita=<uuid>`).
- **Leída y no leída:** sigue siendo global, como el resto de la bandeja (limitación existente, §34).
- **Avisos manuales:** la creación manual de avisos ya **no** acepta el tipo `valuation` (422), para que
  nadie fabrique una alerta falsa (revisión de seguridad V-1). Además, el CRM solo hace sonar avisos con
  `action_type = valuation_detail`.

## 14. Alertas sonoras

- **Activación:** voluntaria por persona, con «Probar sonido», que desbloquea el audio del navegador.
- **Cuándo suena:** WebAudio **una vez** por aviso `valuation` de prioridad alta y no leído, solo si
  tiene menos de 30 min (al abrir el CRM no suenan avisos viejos).
- **Acciones:** indicador visual, «Marcar como atendida» y silenciar.
- **Sin CRM abierto:** no suena; el aviso queda en la bandeja.
- **Pendiente:** oírlo en el PC de recepción, porque en las pruebas se usó un `AudioContext` simulado.

## 15. Firebase

Configurado en el servidor (credenciales en `storage/app/firebase`). El backend envía por
`NotificationService`: notificación en la app + push si `should_popup`.

**No se envió nada real.** Antes de activar `member_app_enabled` hay que verificar en producción que
un socio de prueba (de la casa) recibe el push (§36).

## 16. Meta / WhatsApp

- Credenciales de Meta y outbox de WhatsApp existen, pero **no hay ninguna plantilla aprobada** para
  valoraciones.
- `sendWhatsappTemplate` no tiene llamadores, y fuera de la ventana de 24 h Meta rechaza los mensajes
  libres.
- **No se implementó.** El CRM lo muestra como «no disponible».

## 17. Canales realmente disponibles

| Canal | Estado |
|---|---|
| Bandeja del CRM (personal) | Operativo |
| App del socio + push FCM | Operativo en código, **apagado** hasta verificar |
| WhatsApp | No disponible |
| SMS | Cerrado a propósito por coste |

## 18. Limitaciones de los proveedores

- **FCM:** «aceptado» no es «entregado», y el estado se guarda como `accepted`. No hay acuse de lectura.
- **WhatsApp:** requiere plantilla aprobada y consentimiento, y tiene coste por conversación.
- **Socio sin app o sin token:** solo tiene la notificación dentro de la app cuando la abra.

## 19. Programación de recordatorios

- **Plan por cita y versión** (`valuation_reminders`):
  - al socio: confirmación, 24 h y 1 h;
  - internos: aviso 15 min antes y «sin atender».
- **Procesador:** `valuations:process-reminders` cada minuto (`withoutOverlapping()->onOneServer()`).
  1. Reclama con `FOR UPDATE SKIP LOCKED`.
  2. Comprueba que la cita, su versión y su estado sigan valiendo, y que el aviso no haya caducado.
  3. Mira si el canal está activo **en ese momento**.
  4. Envía. Reintenta a los 5 y a los 15 min; al 3.er fallo, `failed`.
  5. Recupera los `processing` huérfanos.
- **Caducidad:** confirmación, recordatorios y aviso de 15 min no salen si la cita ya empezó; «sin
  atender» no sale pasadas 2 h de la hora. Tras una caída del scheduler no se dispara nada de golpe.
- **Reprogramar** anula los avisos de la versión vieja. **Cancelar** anula los pendientes y envía la
  cancelación.
- **Estados reales:** `pending`, `processing`, `accepted` (no «entregado»), `skipped`, `error`,
  `failed`, `cancelled`.
- **Nunca** se notifica a un socio nulo (sería un push a todos).

## 20. Estados de valoración

| Desde | A | Condición |
|---|---|---|
| Programada | Confirmada | Hasta la hora. |
| Programada \| Confirmada | En curso | Desde 15 min antes. |
| Programada \| Confirmada \| En curso | Completada | Desde la hora. |
| Programada \| Confirmada | No asistió | Desde la hora. |
| Programada \| Confirmada | Cancelada | Con motivo. |
| Programada \| Confirmada | Reprogramada | Vuelve a Programada con versión nueva. |

- Completada, No asistió y Cancelada son finales.
- **Nada se completa solo.**
- Repetir una transición ya hecha es idempotente.

## 21. Auditoría

- **Historial append-only por cita** (`valuation_appointment_events`): acción, actor, estado y hora de
  antes y después, entrenador, motivo.
- **`AuditTrail` del CRM** para cada escritura, incluidas disponibilidad y ajustes.
- **Logs:** nunca contienen el texto de los mensajes al socio.

## 22. Pruebas unitarias y de servicio (backend)

**117 pruebas del módulo** en `tests/Feature/Valuations`, todas en verde:
- en SQLite: `OK (117 tests, 1965 assertions)`;
- en **PostgreSQL 14**, con el rol `iron` dueño y **no superusuario** (como producción), junto con la
  prueba de cobertura de `AuthorizationMap`: `OK (127 tests, 1995 assertions)`.

| Fichero | Pruebas | Qué fija |
|---|---|---|
| `ValuationSchedulingRulesTest` | 24 | huecos, descansos, clases activas, ausencias, antelación, horizonte, bordes |
| `ValuationAppointmentFlowTest` | 23 | crear, reprogramar, confirmar, iniciar, completar, cancelar, no asistió, versión vieja (409), idempotencia |
| `ValuationAccessTest` | 18 | permisos por acción, alcance «solo mis citas», IDOR entre entrenadores, token compartido |
| `ValuationRemindersTest` | 16 | planificación en UTC, dos pasadas sin duplicar, reintentos, huérfanos, caducidad, canal apagado, jamás socio nulo |
| `ValuationImportAndIntegrityTest` | 8 | importador CSV (simulación, duplicados, pasado) e integridad |
| `ValuationInputValidationTest` | 8 | texto con controles, UTF-8 inválido, ISO estricto, años absurdos |
| `ValuationNotificationsTest` | 7 | bandeja filtrada por permiso en todas sus vías; aviso manual `valuation` → 422 |
| `ValuationContractTest` | 4 | forma de las respuestas |
| `ValuationOverlapGuardMigrationTest` | 4 | `btree_gist`, EXCLUDE y CHECK con rol dueño no superusuario; qué errores se toleran |
| `ValuationMemberDeletionTest` | 3 | borrar un socio con citas: 204 y se llevan sus citas |
| `ValuationTrainerDeletionTest` | 2 | entrenador con valoraciones → 409 sin aviso; sin valoraciones, 204 como antes |

**Pruebas de mutación** (se rompe el código a propósito y alguna prueba debe fallar): **25 de 25
detectadas**, en tres tandas, siempre con los ficheros restaurados y comprobados por sha256:
- **5 de la implementación:** cerrojo, solape, alcance del entrenador, filtro de la bandeja y socio nulo.
- **19 de las correcciones**, según el informe del corrector. Ahí se describen una a una 17:
  - importador (4);
  - borrado del socio (3);
  - `FOR NO KEY UPDATE` (2);
  - tolerancia de la 200002 y CHECK de estado (2);
  - aviso manual `valuation` (2);
  - texto con controles (1);
  - fechas ISO estrictas y tope de años (2);
  - consola del importador (1).
- **1 final:** caducidad de «sin atender».

Además, la carrera real en PostgreSQL con el cerrojo quitado falla donde debe (socio reservado dos
veces, 5 de 5).

## 23. Pruebas de integración

| Prueba | Resultado |
|---|---|
| **API real** (`e2e_api.py`): Laravel con PostgreSQL 14 desechable, roles reales, comando de avisos | **39/39** (`RESUMEN: 39/39 PASS`), sobre una base recién creada con las migraciones finales. |
| **Navegador real** (`browser_e2e.mjs`): Chrome por DevTools Protocol con el CRM en desarrollo contra ese backend | **13/13** (`RESUMEN: 13/13 PASS`), en la misma corrida. |

**La API real cubre:**
- capacidades por rol;
- huecos con descanso, clase y ausencia;
- los 6 conflictos;
- bordes que se tocan y doble clic;
- carrera de 8;
- IDOR entre entrenadores;
- Recepción sin ajustes ni reglas ajenas;
- reprogramar, versión vieja, confirmar, completar antes de tiempo y cancelar;
- historial;
- bandeja filtrada;
- nada al socio con el canal apagado;
- sin duplicados tras dos pasadas.

**El navegador real cubre:**
- login real como Super Admin;
- asistente completo (socio, entrenador, día y hora reales);
- que la cita queda en PostgreSQL a la hora elegida;
- enlace profundo al detalle;
- vistas mes, día y lista;
- que Recepción entra directa al calendario.

**Hallazgo de esta prueba:** detectó un error de compilación que las pruebas unitarias no veían. Se
corrigió y el build se verificó de nuevo.

## 24. Pruebas de concurrencia

§11: carrera real en PostgreSQL. 10 reservas → 1. 10 dobles clics → 1. Reprogramar contra cancelar,
coherente. 3 procesadores, sin duplicados. Además, la mutación sin cerrojo hace fallar la carrera
(socio reservado dos veces, 5/5).

## 25. Pruebas frontend

- **Módulo de valoraciones** (`src/app/modules/valuations`, 8 specs): 138/138.
- **Con el servicio y la página de Entrenadores:** 171/171.
- **Las specs montan las clases reales**, no copias:
  - el calendario con sus vistas, el asistente y el detalle;
  - los historiales, la alerta sonora (sesión, logout, cambio de usuario, `action_type`) y las
    notificaciones;
  - la disponibilidad, los ajustes y el servicio HTTP.
- **Reloj en Tokio** (`npm run test:agenda-tz`, `TZ=Asia/Tokyo`): 394/394. Fija que los días y las
  horas son los de Bogotá aunque el PC esté en otra zona.
- **`npm run check:literals`:** correcto.
- **Build de producción:** sin errores. Mismos avisos que antes, ninguno de valoraciones. El bundle
  inicial no crece (813 kB): el calendario se carga perezoso.

## 26. Pruebas de regresión

- **Backend, suite completa:** 9110 pruebas. Los **mismos 58 fallos** que la línea base (`f9638e2`, mismo
  día), comparados por nombre: **0 nuevos** y ninguno desaparecido. Son preexistentes (Reports, que
  depende del calendario, y pruebas que en SQLite no ven lo que PostgreSQL rechaza).
- **CRM, suite completa:** 1943 pruebas. Los **mismos 16 fallos** que la línea base (`c09c58c`),
  comparados por nombre: 0 nuevos.
  - Una corrida bajo carga (a la vez que Chrome) dio un 17.º, un *timeout* de 5 s en
    `plan-transfers.spec.ts`, módulo no tocado.
  - Aislado pasó 3 de 3, y la suite repetida sin carga volvió a los 16.
- **Pantallas existentes que se tocaron:**
  - Entrenadores: `trainers.ts` intacto; la página nueva lo envuelve en una pestaña.
  - Fichas del socio y del entrenador: una sección añadida.
  - Bandeja de notificaciones: filtro y categoría nuevos.

  Sus specs siguen en verde.
- **Catálogo de permisos del CRM:** idéntico byte a byte al que genera `php artisan permissions:export`.

## 27. Resultados de seguridad

| Revisión | Bloqueantes | Importantes | Menores | Estado |
|---|---|---|---|---|
| API y RBAC (`api-security-specialist`) | 0 | 0 nuevos (2 **preexistentes**, §34) | 6 | V-1 a V-4 y V-6 corregidos con prueba. V-5 resuelto con el cambio de cerrojo (I1). |
| Base de datos y concurrencia (`database-specialist`) | 1 (borrar un socio con citas → 500) | 4 | 8 | B1 corregido (FK del socio en `cascade`, probado por el endpoint real: 204 y se llevan sus citas). I1 corregido (`FOR NO KEY UPDATE`). I2 e I4 resueltos en el procedimiento (§31, §32). I3 corregido (200002 solo tolera 42501/58P01/0A000). M1 y M3 corregidos. M2, M5, M6 y M8, documentados. |
| Revisión final (`reviewer`) | 0 | 1 | 6 | **Aprobado con reservas.** Importante: cuentas con `view` sin `view_all` ni vínculo veían errores; corregido con un estado informativo (hoy no hay ninguna cuenta Entrenador en producción). Menores: alerta que no se limpiaba al salir y caducidad de «sin atender» y confirmación, corregidos; las afirmaciones del informe, corregidas. |

**Verificado atacando:**
- un entrenador recibe 404 o 403 sobre citas, filtros, huecos, bloqueos y avisos de otro;
- una cuenta sin vínculo, 403;
- el token compartido, 403 en todo;
- la búsqueda sin `create`, 403;
- ninguna ruta PUBLIC/SELF nueva;
- campos privilegiados ignorados;
- sin socio nulo ni avisos con el canal apagado.

## 28. Archivos modificados

**Backend:**
- **Nuevos:**
  - `app/Services/Valuations/*` (12 servicios);
  - `app/Http/Controllers/Api/Admin/Valuation{,Availability,Settings}Controller.php`;
  - `app/Http/Controllers/Concerns/ValuationResponses.php`;
  - `app/Models/Valuation*.php` (6);
  - `app/Exceptions/ValuationException.php`;
  - `app/Console/Commands/{ProcessValuationReminders,ImportValuationsCsv}.php`;
  - 3 migraciones;
  - `tests/Feature/Valuations/*`;
  - `docs/agenda-valoraciones/*`.
- **Modificados** (integración mínima):
  - `NotificationController` (filtro y tipo reservado);
  - `Notification` (`fillable`);
  - `AuthorizationMap`, `PermissionCatalog`, `CrmPermission`;
  - `AppServiceProvider` (limitador);
  - `routes/api.php`, `routes/console.php`;
  - `TrainerController::destroy` (guarda 409);
  - `app/Rules/PlainText.php` y `app/Support/Caja/UntrustedText.php`: opción **aditiva** `multiline` para notas y motivos. Por defecto se comportan igual que antes; los recibos térmicos que las usan no cambian, y sus pruebas siguen en verde.

**CRM:**
- **Nuevos:**
  - `modules/trainers-page.ts`;
  - `modules/valuations/*`;
  - `services/valuations.service.ts`;
  - sus specs.
- **Modificados** (13 ficheros, unas 170 líneas):
  - `permissions.enum.ts`, `backend-permission-catalog.ts` (idéntico al generado por
    `permissions:export`), `access-control.service.ts`;
  - `app.routes.ts`, `app.html`, `app.ts`;
  - `notifications.service.ts`, el popover y `modules/notifications.ts`;
  - `member-details-modal.ts` y `trainer-modal.ts` (una sección cada uno);
  - `package.json` (`test:agenda-tz`).

## 29. Commits generados

Todos **locales**, en `feat/valoraciones`, **sin push**:

| Repositorio | Commit | Contenido |
|---|---|---|
| Monorepo `Iron-Body` | `b86d3c6` | `feat(valoraciones)`: agenda, disponibilidad, conflictos, recordatorios, bandeja filtrada, permisos, importador, 3 migraciones y 117 pruebas. |
| Monorepo `Iron-Body` | el siguiente a `b86d3c6` | `docs(valoraciones)`: este informe y la guía de transición desde Google Calendar. |
| CRM `Iron-Body_Front` | `6fb16c1` | `feat(valoraciones)`: Calendario de citas en Entrenadores, historiales en las fichas, bandeja y alerta, permisos. |

Ninguno toca `main`. Para desplegar hay que integrarlos en `main` (§31), con tu aprobación.

## 30. Dependencias nuevas y justificación

**Ninguna** en `composer.json` ni en `package.json`. El calendario está hecho con CSS grid y los
helpers de zona horaria del propio CRM.

En PostgreSQL, la extensión **`btree_gist`** (estándar, «trusted», versión 1.6 ya disponible en el
servidor) permite la restricción `EXCLUDE`, la red de seguridad contra solapes. Si no se pudiera
instalar, la migración lo avisa y la protección por cerrojo sigue valiendo.

## 31. Procedimiento de despliegue (cuando se autorice)

**Orden obligatorio: migraciones ANTES que el código.** Con el código viejo y el esquema nuevo todo
funciona; con el código nuevo y el esquema viejo, la bandeja del CRM falla (revisión de BD I2).

1. **Aprobación expresa** y respaldo de la base, con el procedimiento habitual (`pg_dump` vía la
   configuración de Laravel).
2. **Comprobación previa** (solo lectura):
   - rol `iron` dueño y no superusuario;
   - `btree_gist` 1.6 disponible;
   - `to_regclass('public.valuation_appointments')` es NULL;
   - ninguna transacción de más de 5 s.

   El SQL está en el informe del backend, en «Correcciones tras revisión».
3. **Migraciones con el código viejo todavía sirviendo:**

   ```bash
   cd /var/www/api/backend
   D=storage/app/deploy/valoraciones-mig     # fuera de public/, legible por www-data
   mkdir -p $D && git -C /var/www/api fetch -q origin
   for f in 2026_10_17_200001_create_valuation_agenda_tables 2026_10_17_200002_add_valuation_overlap_guard 2026_10_17_200003_add_required_permission_to_notifications; do
     git -C /var/www/api show origin/main:backend/database/migrations/$f.php > $D/$f.php; done
   chown -R www-data:www-data $D
   sudo -u www-data env PGOPTIONS='-c lock_timeout=3s' php artisan migrate --path="$PWD/$D" --realpath --force
   ```

   - Si sale `55P03` (lock timeout), la migración no deja restos: repetir el mismo comando.
   - Cualquier otro error detiene el despliegue.
4. **Comprobación posterior obligatoria:**
   - las 3 migraciones registradas;
   - `btree_gist` instalada;
   - restricciones `valuation_appointments_no_overlap` (x) y los CHECK de estado, canal y tipo;
   - FK del socio en `cascade` y del entrenador en `restrict`;
   - `valuation_settings` con 1 fila y `member_app_enabled = false`;
   - `notifications.required_permission` varchar(64);
   - ningún índice inválido.
5. **Código del backend:** `git merge --ff-only` en `/var/www/api` y `chown www-data` de lo nuevo, como
   en los despliegues anteriores.
   - Producción **no usa `config:cache` ni `route:cache`**: no introducirlos.
   - `migrate:status` debe mostrar las 3 como `Ran`.
6. **CRM:** `npm run build:prod` desde el commit limpio, `rsync` **sin `--delete`** y
   `chown -R root:root /var/www/app`.
7. **Humo sin escribir:**
   - `GET /api/admin/valuations/capabilities` con Recepción → 200, `member_app.enabled:false`;
   - con el token compartido → 403;
   - el cron `schedule:run` sigue corriendo (`schedule:list` muestra `valuations:process-reminders`
     cada minuto);
   - 0 errores 5xx.
8. **Configuración inicial en el CRM:** disponibilidad de los entrenadores, ajustes y permisos (§36).

## 32. Rollback

**Revisar solo el código** (los datos quedan inertes; es lo recomendado):

1. Antes, contar las notificaciones que se verían en toda la bandeja:
   `SELECT count(*) FROM notifications WHERE required_permission IS NOT NULL;`
   - El código viejo no filtra esa columna, así que mostraría a todas las cuentas los avisos de
     valoraciones, con nombres de socios.
   - Copiarlas (`\copy`) y retirarlas con
     `DELETE … WHERE type = 'valuation' AND audience = 'admin'`.
   - Es **destructivo: requiere autorización**.
2. Volver al commit anterior del backend (`git revert` + `merge --ff-only`) y republicar el CRM anterior
   (`c09c58c`), con `rsync` sin `--delete`.

**Completa** (borra la agenda):

1. Todo lo anterior.
2. `pg_dump -Fc -t 'public.valuation_*'` a un sitio seguro (datos personales).
3. Revertir con la copia de las migraciones, porque el código viejo ya no tiene los ficheros:

   ```bash
   cd /var/www/api/backend
   sudo -u www-data env PGOPTIONS='-c lock_timeout=3s' php artisan migrate:rollback --path="$PWD/storage/app/deploy/valoraciones-mig" --realpath --step=3 --force
   ```

   - `down()` bloquea `admins`, `members` y `trainers` durante milisegundos.
   - Con `55P03` no deja nada a medias.
   - `btree_gist` se queda (es inocua).

**Requiere autorización.** Es más delicado que desplegar: mejor en hora de poco tráfico.

## 33. Migración desde Google Calendar

Ver `TRANSICION_GOOGLE_CALENDAR.md`:
- qué averiguar del calendario actual;
- fecha de corte recomendada: desde el día C, solo el CRM;
- CSV;
- `php artisan valuations:import-csv` en simulación, y después `--apply` con aprobación;
- sin borrar ni tocar Google, sin sincronización.

**Comportamiento del importador:**
- las citas importadas no reenvían confirmación al socio;
- las pasadas se rechazan salvo `--include-past`;
- los duplicados no se escriben.

## 34. Riesgos pendientes

1. **Preexistente (IMPORTANTE, no de esta rama), P-1:** la bandeja del CRM es **global**. Cualquier
   sesión, incluida la de un entrenador, ve los avisos de **pagos** con nombre, monto, referencia y
   documento.
   - Arreglo propuesto: marcarlos con `required_permission = payments.view`, usando la columna nueva.
   - Requiere un UPDATE en producción y tu decisión.
2. **Preexistente (IMPORTANTE), P-2:** `POST /api/admin/notifications` está abierto a cualquier sesión.
   Un entrenador podría enviar texto libre a la app de todos los socios (sin push).
   - Arreglo: exigir un permiso real y limitar los tipos.
   - Aquí solo se cerró el tipo `valuation`.
3. **Leída o no leída es global** en la bandeja (limitación existente).
4. **Notas administrativas:** las ve quien tiene `view_all`, incluida Recepción. Se avisa en la UI que
   no son clínicas, pero alguien podría escribir datos de salud.
5. **Push real sin verificar en producción:** canal apagado.
6. **Sonido sin oír en el PC de recepción.**
7. **Sin cuentas CRM de entrenador vinculadas:** la vista «solo mis citas» está implementada y probada,
   pero nadie la usa hoy. Hoy producción tiene 3 Super Admin, 2 Recepción y 1 Administrativo; ninguna cuenta
   tiene entrenador vinculado. Una cuenta con `valuations.view` sin `view_all` ni vínculo ve un aviso
   informativo, no un error.
8. **Reversión del código:** si se revierte el código y se deja la columna, los avisos de valoraciones
   serían visibles en toda la bandeja (ver §32).

## 35. Funcionalidades no implementadas y motivos

| Funcionalidad | Motivo |
|---|---|
| WhatsApp | No hay plantilla aprobada ni llamador; requiere aprobación de Meta y tiene coste. |
| Pantalla de citas en la app Flutter | Fuera del alcance acordado. El socio solo recibe la notificación si se activa el canal. |
| Autoagendado del socio | No se pidió. |
| Sincronización con Google | No es necesaria; la transición es por corte + importación. |
| Avisos dirigidos a cuentas de entrenador | No hay cuentas vinculadas. |
| Lectura por persona en la bandeja | Cambio del mecanismo existente. |
| Enlace a la valoración profesional | La crea la app del entrenador; la columna queda preparada. |
| Confirmación del socio por enlace | No se pidió; evita un endpoint público. |

## 36. Requisitos para activar producción

1. **Tu aprobación** de este informe y de los dos preexistentes (P-1, P-2) si se quieren resolver.
2. **Despliegue** según §31: migraciones primero, con `lock_timeout`; después el código; después el
   CRM.
3. **Configurar en el CRM:**
   - disponibilidad de los 8 entrenadores;
   - ajustes (duración, márgenes);
   - permisos por rol (¿Recepción confirma?).
4. **Probar en producción sin socios reales:**
   - una cita con un socio de prueba de la casa;
   - comprobar el aviso interno y el sonido en recepción.
5. **Activar `member_app_enabled`** solo tras recibir un push de prueba en el teléfono de un socio de
   la casa.
6. **Día de corte** de Google Calendar e importación revisada (§33).
7. Solo entonces se puede hablar de **PRODUCTION_VERIFIED**.
