# Auditoría del ciclo de valoraciones físicas: diagnóstico y propuesta

**Fecha:** 18-11-2026. **Alcance:** solo lectura. No se hicieron migraciones, despliegues ni escrituras en producción.

**Base auditada:**

| Pieza | Commit |
|---|---|
| Backend | `8a4b51d1` (= producción `45f18683` + documentación) |
| CRM | `ce695d7` |
| App | `release/2.0.10-ota-iron-ia` `2812117` (2.0.10+31, código de los parches 17/18 en stable) |

**Detalle con evidencia (fichero:línea y consultas):**
- `01-backend-y-postgresql.md` (con las consultas en `sql/01–16`);
- `03-app-flutter.md`;
- `02-crm.md`;
- todo en esta misma carpeta.

**Verificado por el líder en producción:**
- 49 citas: **0 con valoración enlazada**, 40 «Programada» con la hora ya pasada y 1 completada;
- de esas 40, **23** tienen la valoración del socio enviada el mismo día (Bogotá);
- `member_app_enabled = true` desde el 17-10 a las 19:59 UTC.

---

## Diagnóstico en una pantalla

Hoy conviven **tres «valoraciones» que no se hablan:**

| Pieza | Tabla | Quién la crea |
|---|---|---|
| **Cita** de la agenda | `valuation_appointments` | Recepción, en el CRM |
| **Valoración profesional** del entrenador | `professional_assessments` | Entrenador, desde la app |
| **Evaluación física** | `physical_evaluations` | Copia de medidas; también la escribe el socio o recepción |

- **Qué demuestra que terminó:** el envío final del entrenador, `POST /api/trainer/assessments/{uuid}/submit`.
- **Lo que hace ese envío:** avisa al socio y a los entrenadores y copia las medidas. **No toca la cita y no avisa al CRM.**
- **Por qué no se cierra la cita:** cerrarla exige una sesión del CRM con `valuations.complete`, y **ningún entrenador tiene cuenta en el CRM**.
- **La columna para enlazar ya existe** (`valuation_appointments.professional_assessment_id`), **pero ningún código la escribe**.
- **Resultado:** las citas se quedan «Programada»; la bandeja recibió 42 alarmas de «sin atender», muchas de citas que sí se atendieron.

**No existen** intervalos de seguimiento (por gimnasio, plan ni socio), ni «próxima valoración», ni reglas comerciales de derecho a valoración. En los planes solo hay el texto «Valoración inicial» en los beneficios.

**Sí existen dos detectores de «valoración vencida» que se solapan:**
- `evaluation.outdated`, con 60 días fijos: unos 4.000 eventos al mes a n8n y 34 tareas de entrenador que nadie abrió;
- `valuation.outdated` de ULTRON.

---

## Las 25 respuestas

### 1. ¿Cómo funciona hoy la valoración?
- **El entrenador**, en la app, crea un borrador («Guardar borrador») o lo envía («Enviar al miembro», que encadena crear, actualizar y enviar).
- **Al enviar:**
  - la valoración pasa a final;
  - sus medidas se copian a `physical_evaluations`;
  - se avisa al socio y a los entrenadores por su canal en tiempo real.
- **Iron IA no se dispara al enviar:** «Generar plan» es un botón aparte que deja borradores.
- **El socio** también puede registrar sus propias medidas en la app; según los permisos, también recepción. Ambas acaban en `physical_evaluations`, sin indicar de dónde vienen.

### 2. ¿Cómo funciona el agendamiento?
Agenda completa en el CRM, desplegada el 17-10:
- **Estados:** `scheduled → confirmed → in_progress → completed`, más `no_show` y `cancelled`. Los tres últimos son finales, sin reapertura.
- **Concurrencia e idempotencia:**
  - versión optimista (409 `appointment_changed`);
  - `client_request_id` único al crear;
  - EXCLUDE de solape por entrenador y cerrojos por fila.
- **Disponibilidad:** `/slots`.
- **Avisos a la bandeja:** al crear, reprogramar, cancelar y confirmar. **Completar, iniciar y «no asistió» no avisan.**
- **Al socio:** confirmación, recordatorios de 24 h y 1 h, reprogramación y cancelación; el canal está **encendido**.
- **La app no tiene ninguna pantalla de citas**, ni para el socio ni para el entrenador.

### 3. ¿Qué registros e historial existen?
**La persistencia existe; lo que falla es la conexión y la visualización:**
- **Citas:** `valuation_appointments` más su historial de eventos (49 citas, con eventos `created`, `rescheduled`, `cancelled` y `completed`).
- **Valoraciones del entrenador:** `professional_assessments`, con borradores, enmiendas (`parent_id`) y 111 avisos al socio.
- **Medidas:** `physical_evaluations`, de las que la app del socio lista todas.

**En el CRM:**
- el historial del socio **solo muestra citas**;
- las evaluaciones físicas están en otra pantalla, sin enlace;
- **las valoraciones del entrenador no tienen ninguna vista**.

### 4. ¿Por qué administración no recibe la novedad?
Hay tres motivos:
- el envío de la valoración no escribe ningún aviso del CRM;
- ningún canal en tiempo real del CRM lee `professional_assessments`;
- completar una cita (la única vía que avisaría) solo se puede hacer desde el CRM con `valuations.complete`, y además completar no genera aviso.

### 5. ¿Qué operación demuestra que una valoración terminó?
`ProfessionalAssessmentService::submit` (el `POST …/submit`). Es la única condición explícita y verificable: estado final, autor entrenador y socio identificados.
- **No sirven:** crear la cita, abrir el formulario, guardar un borrador, generar o ver una rutina, ni reintentar.
- **Dato real:** borrador y envío ocurren casi siempre en el mismo minuto (mediana 0 min).

### 6. ¿Es posible completar automáticamente la cita?
**Sí, cuando la relación es inequívoca:** dentro de la misma transacción del envío se busca la cita abierta del **mismo socio el mismo día de Bogotá**.
- Debe aceptarse un envío **anterior** a la hora de la cita: ocurre en 12 de 29 casos reales.
- **Con exactamente una candidata:** se completa y se enlaza.
- **Con varias:** se prefiere la del mismo entrenador; si sigue habiendo varias, **no se completa ninguna**.
- **Con los datos de hoy:** se habrían cerrado solas 23 de las 40 citas pendientes.

### 7. ¿Qué pasa si no había cita previa?
La valoración se registra igual (ya ocurre hoy) y, después del commit, se deja en la bandeja un aviso «Valoración realizada sin cita», que abre el historial del socio. No se inventa ninguna cita.

### 8. ¿Cómo conectarlo con SSE?
Con el canal que ya existe, `GET /api/admin/notifications/stream`:
- filtra por permiso (`required_permission = valuations.view_all`);
- emite `id:` por fila y reanuda con `after_id`;
- el CRM ya enruta `action_type = valuation_detail` a la cita y lo hace sonar.

El aviso se escribe **después del commit**, con clave idempotente: `valuation:{uuid}:crm_completed:v{versión}`. **No hace falta otro stream, WebSocket ni cola nueva.**

### 9. ¿Qué modelos y tablas necesitan cambios?
**Fase 1:** ninguna columna nueva.
- **Un índice único parcial** sobre `valuation_appointments.professional_assessment_id`, para que una valoración no cierre dos citas.
- **Cambios de código en:**
  - `ProfessionalAssessmentService::submit`: cerrojo de la valoración y emparejamiento;
  - `ValuationAgenda`: nuevo `completeFromAssessment`;
  - `ValuationMessages`: textos «completada» y «sin cita»;
  - `ValuationNotifier`.

**Fase 2 (intervalos):**
- `valuation_settings.follow_up_interval_days`;
- `plans.valuation_follow_up_days`;
- una tabla `member_valuation_follow_ups`;
- su historial de cambios.

### 10. ¿Cómo implementar los intervalos personalizados?
- **Accesos rápidos** de 15, 20, 30, 60 y 90 días, más «Personalizar intervalo» (un entero, p. ej. de 1 a 365).
- **Desactivar la recurrencia:** dejar el intervalo vacío.
- **Fecha puntual:** `due_on` fija una fecha concreta sin cambiar el intervalo.
- **Cada cambio queda registrado:** valor anterior, valor nuevo, quién, cuándo y desde dónde.
- **Permiso propio** para modificarlos: entrenador o administración.

### 11. ¿Cómo calcular la próxima valoración?
Próxima fecha = día de Bogotá de la última valoración completada + N **días de calendario**.
- **Ejemplo con el 18-11-2026:** 15 días → 03-12; 20 → 08-12; 30 → 18-12; 60 → 17-01-2027; 90 → 16-02-2027.
- Fin de mes, cambio de año y años bisiestos se resuelven solos con aritmética de fechas sobre `CarbonImmutable` en America/Bogota.
- **Es una fecha sugerida, no una cita:**
  - no se guarda, se calcula al leerla, así que cambiar el intervalo no reescribe el histórico;
  - una cita ya agendada **no se mueve** si cambia el intervalo: se muestra «la próxima cita no coincide con el nuevo intervalo» y se ofrece reprogramar.

### 12. ¿Cómo evitar citas duplicadas?
- **Al programar la próxima:** si el socio ya tiene una cita futura abierta, se muestra con «Conservar» o «Editar», y no se crea otra.
- **Ya existe:** `client_request_id` único y la EXCLUDE de solape por entrenador.
- **El hueco que queda:** el doble agendamiento del **mismo socio** a la vez (está anotado como riesgo abierto de la agenda). Se cierra con el cerrojo del socio que ya se toma al crear y con la comprobación «ya tiene una futura» bajo ese cerrojo.

### 13. ¿Cómo gestionar la concurrencia?
**Orden de cerrojos:** valoración → cita. Ningún camino de la agenda va al revés, así que no hay interbloqueos.
- Las transiciones usan la versión optimista que ya existe.
- Los cambios de intervalo, un `UPDATE` con versión.
- **Dos operadores reprogramando a la vez:** el segundo recibe un 409 `appointment_changed` y recarga. El resultado es determinista.
- **Se prueba en PostgreSQL con barreras:** SQLite no ve los cerrojos.

### 14. ¿Cómo garantizar la idempotencia?
- **Envío de la valoración:** se bloquea la fila y se vuelve a comprobar que sigue en borrador. Un doble envío da un único cierre, un único aviso y una única copia de medidas.
- **Aviso al CRM:** `event_key` UNIQUE.
- **Enlace cita↔valoración:** el índice único.
- **Cita ya completada por un admin:** solo se rellena el enlace, sin aviso duplicado.
- **App** (mejora): enviar una clave de idempotencia en crear y en enviar. Hoy no hay ninguna, y un *timeout* de 20 s puede duplicar el borrador.

### 15. ¿Cómo implementar el buscador rápido?
Reutilizar el del asistente de citas (`/api/admin/valuations/members/search`):
- devuelve el id correcto, la membresía y el entrenador;
- excluye a los anonimizados;
- ya tiene límite de 20.

**Tres cambios:**
- **Ignorar tildes en el servidor.** Hoy «Mari» no encuentra «María».
  - Sin extensión nueva: comparar contra una forma normalizada (`translate()` de las vocales acentuadas y la ñ, o una columna generada).
  - O instalar `unaccent`, que es una decisión de `database-specialist`; `pg_trgm` ya está instalada.
- **Debounce** en el CRM.
- **Revisar el permiso:** hoy exige `valuations.create`.

Con 4.136 socios, un recorrido secuencial es asumible; un índice trigram sería solo una mejora.

### 16. ¿Qué se reutiliza del CRM?
- **Agenda completa:** calendario, detalle y transiciones.
- **`va-slot-picker`** para los huecos.
- **El asistente de 4 pasos.** Hay que añadirle una entrada «socio ya elegido», porque hoy solo recibe entrenador, día y hora.
- **La bandeja con SSE**, sonido y enlace a la cita.
- **El componente de buscador.**
- **`ib-dialog` / `ib-reason-dialog`** y los tokens `--ib-*`.
- **Las píldoras de tipo** del asistente, como patrón para 15/20/30/60/90.

**Arreglo previo:** el selector de huecos se queda en «…» si la fecha pasa del horizonte (90 días). Afecta a los accesos de 60 y 90 días.

### 17. ¿Qué se reutiliza de Flutter?
- El editor de valoraciones (borrador y envío).
- El seguimiento del entrenador: lista, ficha con la última valoración y hoja de detalle.
- El SSE y app-state del entrenador.

**Para la fase 1, la app no necesita cambios:** el emparejamiento lo hace el servidor. Mostrar «próxima valoración» o una clave de idempotencia sería solo Dart, compatible con un parche Shorebird. Pasarían por tienda los recordatorios locales, el calendario del sistema y los canales o assets nuevos.

### 18. ¿Qué mecanismos SSE existen ya?
**`SseStream`:** conexiones de 20–25 s, `retry`, latido e `id:`.

**Streams del CRM:**

| Stream | Reanudación | Relectura de commits tardíos |
|---|---|---|
| `notifications` | `after_id` | no |
| `memberships` | `after_id` o `Last-Event-ID` | sí, con `resync` |
| `classes` | cola de salida `class_events` | sí, solapamiento de 50 |
| `users`, `moderation`, `earnings`, `exercises`, `marketing`, `ultron-studio` | — | — |

**Socio y entrenador:**
- `member/realtime` y `trainer/realtime` son señales efímeras que se podan a los 5 min; no son una cola de salida;
- `notifications/stream` del socio.

Patrón bueno ya existente: `ClassEventBus` (cola de salida y `afterCommit`).

**Riesgo:** `TrainerRealtimeEvents::emit` se llama DENTRO de la transacción del envío. En PostgreSQL un fallo ahí aborta la transacción (25P02); conviene moverlo después del commit.

### 19. ¿Qué riesgos hay con los datos históricos?
- **40 citas «Programada» ya pasadas;** 23 se pueden enlazar de forma inequívoca. Es una escritura en producción: necesita tu autorización y una simulación previa. `completed` es un estado final.
- **1 caso** con entrenador distinto al de la cita.
- **4 valoraciones** de socios ya borrados.
- **Al pasar citas a completadas** cambia la «última valoración» que leen ULTRON y los detectores de vencidos. Es coherente, pero hay que tenerlo en cuenta.
- **No se toca** la fecha ni el responsable de ninguna valoración pasada.

### 20. ¿Qué migraciones serían necesarias?
**Fase 1:** el índice único parcial. Se aplica con el rol dueño y `lock_timeout`, y se revierte con `DROP INDEX`.

**Fase 2:** son aditivas; se revierten con `DROP` y el código viejo las ignora.
- columna de intervalo en `valuation_settings`;
- columna en `plans`;
- tabla de seguimiento por socio más su historial.

Todas con el procedimiento `ironbody-migrate --path`.

### 21. ¿Cómo funcionará la configuración por socio?
Una fila por socio en `member_valuation_follow_ups`:
- `interval_days` (NULL = hereda);
- `due_on` (fecha puntual);
- `paused`;
- autor y versión.

**Se edita** desde «Programar próxima» al completar y desde la ficha del socio. **Precedencia:** socio > plan > gimnasio > sin seguimiento.

### 22. ¿Cómo se relacionan los planes y los intervalos?
**Un plan solo puede proponer un intervalo** (`plans.valuation_follow_up_days`), que el socio o el entrenador pueden sustituir. **El derecho comercial a valoración** (si el plan la incluye y cuántas) es otro concepto:
- hoy no existe ninguna regla;
- si se crea, irá separada y nunca se deducirá del intervalo.

**No se bloquea una valoración por llevar menos de N días:** no hay ninguna regla de negocio que lo diga.

### 23. ¿Cómo probar el flujo completo?
**Pruebas de integración en PostgreSQL:**
- una cita → completada, enlazada, avisos pendientes cancelados y un único aviso;
- 0 o 2 candidatas → sin cambios y aviso «sin cita»;
- envío antes de la hora;
- otro entrenador;
- cita ya completada por un admin;
- `no_show` o cancelada (no son candidatas);
- enmienda (no vuelve a completar);
- doble envío concurrente con barrera → 1/1/1;
- borde del día de Bogotá (23:30);
- fallo del aviso (el envío se confirma igual);
- permisos del aviso en lista, contador, stream y lectura.

**Intervalos:** 15/20/30/60/90, personalizado, fin de mes, cambio de año, bisiesto, pausa, cambio con cita ya agendada.

**CRM:** reconexión SSE, deduplicación por `event_key` y CRM desconectado (el aviso sigue en la bandeja).

**Después, prueba real** con una valoración de prueba en un teléfono de la casa.

### 24. ¿Cómo desplegarlo con seguridad?
**Por fases:**
1. índice;
2. backend de la fase 1 (sin rutas nuevas);
3. CRM (enrutar el aviso «sin cita» y refrescar la agenda).

**Antes de cada una,** respaldo y ensayo sobre una copia enmascarada, como en la subsanación. **El enlace retroactivo** va aparte, con simulación y orden expresa. **La fase 2** se despliega cuando decidas los intervalos. La app no hace falta en la fase 1.

### 25. ¿Cuál es la implementación de menor complejidad que cumple el objetivo?
**Opción A, en el servidor:**
- **qué hace:** al enviar la valoración, completar y enlazar la cita si es inequívoco y, si no, avisar «sin cita», siempre con un aviso a la bandeja después del commit;
- **qué se toca:** sin cambios en la app y con un solo índice;
- **qué resuelve:** que administración se entere sola y que las citas dejen de quedar «Programada» con alarmas falsas.

**Encima**, como fase 2:
- intervalos por socio, plan y gimnasio;
- «Programar próxima» en el CRM, reutilizando el asistente con el socio ya elegido;
- buscador sin tildes.

**Alternativas descartadas:**
- **B (la app envía el id de la cita):** necesita citas en la app, que hoy no existen.
- **C (solo avisar y cerrar a mano):** no elimina el trabajo doble.

---

## Cambios por prioridad

**Obligatorios (fase 1):**
- emparejamiento en `submit` con cerrojo;
- aviso «completada» y «sin cita» después del commit;
- índice único;
- CRM: enrutar el aviso y refrescar la agenda;
- señal del entrenador fuera de la transacción.

**Recomendados (fase 2):**
- intervalos y «próximo control sugerido» en el historial;
- «Programar próxima» reutilizando el asistente;
- buscador sin tildes y con debounce;
- historial unificado en la ficha (citas, valoraciones del entrenador y medidas);
- arreglar el horizonte del selector de huecos.

**Opcionales:**
- clave de idempotencia en la app (parche OTA);
- el socio ve su próxima cita en la app;
- recordatorio de seguimiento al socio por `NotificationDispatcher`, que respeta preferencias y horarios;
- unificar los detectores de vencidos.

## Hallazgos adyacentes (fuera del alcance, para decidir)

1. **El canal al socio está encendido** y sus avisos **no respetan las horas de silencio:** 5 salieron entre la 01:00 y las 07:00. Uno era de una cita a las 02:00, probablemente mal capturada.
2. **El token compartido de automatizaciones** puede leer medidas y lesiones de los socios (`members.view`). Recepción puede crear y editar evaluaciones físicas. Quedan 4 valoraciones huérfanas. Para `security`.
3. **El buscador de evaluaciones físicas del CRM** devuelve el documento completo y socios anonimizados.
4. **App:**
   - no se puede corregir una valoración enviada;
   - vaciar un campo de un borrador no lo borra en el servidor;
   - la «Valoración Integral» sigue diciendo «Falta: Peso» tras registrarlo;
   - «Valoraciones por revisar» cuenta en realidad las no abiertas por el socio.
5. **`evaluation.outdated`** genera unos 4.000 eventos al mes y 34 tareas que nadie mira.
6. **El canal de notificaciones del CRM** puede quedar muerto si se inicia sesión en la pestaña sin recargar. Es una hipótesis: hay que comprobarla en DevTools.

## Decisiones que necesito de ti

1. Al enviar la valoración, ¿el sistema **completa la cita solo** (A) o **la enlaza y avisa**, y la cierra una persona (C)?
2. ¿Enlazamos las **23 citas pasadas** con su valoración? Es una escritura en producción, con simulación previa.
3. ¿**Intervalo por defecto** del gimnasio? ¿Sustituye al detector de 60 días o conviven?
4. Si valora **otro entrenador** distinto al de la cita, ¿se cierra igual o queda «sin cita»?
5. ¿El intervalo cuenta desde la **fecha de la valoración** (recomendado) o desde hoy?
6. ¿Quién puede cambiar intervalos y buscar socios en la vista de seguimiento: entrenadores sin `view_all`, recepción…?
