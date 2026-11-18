# Auditoría backend — valoraciones físicas, citas, seguimiento y tiempo real

**Fecha:** 18-11-2026 (consultas a producción hacia las 18:10 UTC).
**Alcance:** solo lectura. No se editó ningún fichero del repositorio, no hubo commits y no se escribió en producción.

**Código auditado.** `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/subsanacion-20209/Iron-Body/backend`, HEAD `8a4b51d1`, árbol limpio. En adelante, las rutas son relativas a `backend/`.

**Producción.** Las consultas se hicieron con `aud/q.sh`, en una sesión con `default_transaction_read_only=on`; el SQL de cada consulta se reproduce en el apéndice A. Solo se leyeron recuentos, estados, ids y fechas agregadas. La única salida de texto son fragmentos del catálogo de planes, que es público: `GET /api/plans` no lleva `auth` (`routes/api.php`).

**El documento `CONTRATO_AGENDA_VALORACIONES.md` NO existe.** No aparece en el worktree, ni en `IRON_BODY/docs`, ni en `_handoff`. Solo lo citan comentarios (`database/migrations/2026_10_17_200001_create_valuation_agenda_tables.php:9-10` y `routes/api.php`), así que el contrato vigente se reconstruye desde el código y desde `docs/agenda-valoraciones/IRON_BODY_VALUATION_SCHEDULING_REPORT.md`.

Convención del informe: **[H]** = hecho verificado (fichero:línea o consulta); **[S]** = suposición o hipótesis no demostrada.

---

## 0. Conclusiones en una pantalla

1. **[H] Hay tres nociones de «valoración», y ninguna referencia a otra:**
   - **cita** (`valuation_appointments`, la agenda del CRM);
   - **valoración profesional** (`professional_assessments`, la app del entrenador);
   - **evaluación física** (`physical_evaluations`, historial de medidas).

   Lo que las une es una COPIA:
   - al enviar, la valoración profesional vuelca sus medidas en `physical_evaluations` sin guardar de dónde vienen;
   - la columna `valuation_appointments.professional_assessment_id` existe, pero nadie la escribe (0 de 49 filas en producción).
2. **[H] Hoy, que una valoración TERMINÓ lo demuestra `POST /api/trainer/assessments/{uuid}/submit`** (`ProfessionalAssessmentService::submit`, `app/Services/Trainer/ProfessionalAssessmentService.php:72-106`). En la práctica no hay borrador:
   - el paso de borrador a envío tarda una mediana de 0 min y como máximo 1 min (Q04);
   - en producción solo queda 1 borrador, del 06-10.
3. **[H] El envío NO toca la cita ni avisa a administración:**
   - escribe aviso al socio, señal SSE al socio y a los entrenadores asignados, auditoría del entrenador y la copia en `physical_evaluations`;
   - no escribe nada en `notifications` con `audience=admin`, ni en ningún stream que lea el CRM.
4. **[H] Efecto en producción:**
   - de 49 citas, **40 siguen `scheduled` con la hora ya pasada** y solo 1 está `completed` (la cerró Administrativo 13 días después);
   - de esas 40, **23 tienen una valoración enviada el mismo día de Bogotá** y del mismo entrenador;
   - la bandeja del CRM recibió **42 alarmas «sin atender»** de citas que en muchos casos sí se atendieron (Q02, Q03, Q07).
5. **[H] Los entrenadores no pueden cerrar citas:**
   - cerrarlas exige una sesión de **administrador del CRM** con `valuations.complete` (`app/Http/Controllers/Concerns/ValuationResponses.php:31-45`);
   - ningún entrenador tiene cuenta del CRM (`admins.trainer_id` = 0 filas);
   - el portal del entrenador no tiene ni una ruta de citas.
6. **[H] El canal al socio está ENCENDIDO:**
   - `valuation_settings.member_app_enabled = true`; lo activó un Super Admin el 17-10 a las 19:59 UTC (`audit_logs`);
   - se han aceptado 49 confirmaciones, 26 recordatorios de 24 h y 41 de 1 h (Q02, Q12);
   - el informe de la agenda dice que está apagado: está desactualizado.
7. **[H] Ya existen dos detectores de «valoración vencida», y ninguna configuración por plan o por socio:**
   - `evaluation.outdated`: 60 días fijos en código, unos 4.000 eventos al mes hacia n8n;
   - `valuation.outdated` de ULTRON: parámetro `days`.
8. **Propuesta mínima.** Vincular la valoración con la cita al enviarla, en el servidor y sin cambiar la app, reutilizando la columna existente. Completar la cita en la misma transacción. Avisar a administración por la bandeja existente después del commit. Esquema: un índice único parcial y ninguna columna nueva en la fase 1. Los intervalos de seguimiento quedan en una fase 2 aditiva.

---

## 1. Modelo de datos: qué es «una valoración» (pregunta 1)

### 1.1 Cita de valoración — `valuation_appointments` [H]

| Aspecto | Hecho | Fuente |
|---|---|---|
| Tablas | `valuation_settings`, `valuation_availability_rules`, `valuation_availability_blocks`, `valuation_appointments`, `valuation_appointment_events` (solo se añade), `valuation_reminders` (cola de salida de avisos) | `database/migrations/2026_10_17_200001_create_valuation_agenda_tables.php:35-189` |
| Socio | `member_id` FK a `members` ON DELETE **CASCADE** | ídem :106; Q01 |
| Entrenador | `trainer_id` FK a `trainers` ON DELETE **RESTRICT** | ídem :107; Q01 |
| Estados | `scheduled \| confirmed \| in_progress \| completed \| no_show \| cancelled`, con el CHECK `valuation_appointments_status_chk` | `2026_10_17_200002_add_valuation_overlap_guard.php:63-66`; Q01 |
| Solape | EXCLUDE gist `valuation_appointments_no_overlap` (trainer_id, tsrange) mientras la cita está activa | ídem :93-97; Q01 |
| Idempotencia | `uuid` UNIQUE y `client_request_id` UNIQUE | migración :96, :99; Q01 |
| Versión | `version int` (sube con hora, entrenador o estado) | migración :133 |
| Enlace a la valoración | `professional_assessment_id` unsignedBigInteger nullable, **sin FK y sin índice**: «Referencia futura… v1 no la rellena» | migración :128-129; `app/Models/ValuationAppointment.php:82` (solo `$fillable`) |
| Índices | `(trainer_id, starts_at)`, `(member_id, starts_at)`, `(status, starts_at)` | migración :138-140; Q01 |
| Avisos | `valuation_reminders` UNIQUE `(appointment_id, kind, channel, appointment_version)` | migración :187 |
| Tipos | `initial \| follow_up \| other` (en producción, todas son `initial`) | `app/Models/ValuationAppointment.php:26-38`; Q02 |

### 1.2 Valoración profesional — `professional_assessments` [H]

- **Ciclo de vida:** `draft → submitted` (inmutable) `→ amended` (la sustituye una versión nueva enlazada por `parent_id`) `→ voided`. Fuente: `database/migrations/2026_06_14_000010_create_professional_assessments_table.php:11-17`.
- **`voided` no tiene camino de escritura:**
  - ninguna ruta ni servicio la escribe (grep de `STATUS_VOIDED` sin usos fuera del modelo);
  - en producción hay 0 filas `voided`.
- **No tiene FK de motor**, a propósito: «Sin FK de motor (compatibilidad SQLite en tests)» (migración :17).
  - Índices: `member_id`, `trainer_id`, `parent_id`, `(member_id, status)`, `(trainer_id, status)` y `uuid` UNIQUE (Q01).
  - Consecuencia: al borrar un socio, sus valoraciones quedan huérfanas. En producción hay **4 huérfanas** (Q13).
- **Columnas:**
  - medidas tipadas, `observations`, `recommendations`;
  - `trainer_type` y `location` copiados del entrenador;
  - `submitted_at`, `acknowledged_at`, `voided_at`.
- **Quién escribe:** solo el portal del entrenador:
  - rutas `routes/api.php:284-298`, con `trainer.feature:professional_assessments_enabled`, `auth.trainer` y `trainer.can:assessments.*`;
  - en el controlador, asignación activa más autoría (`app/Http/Controllers/Api/Trainer/ProfessionalAssessmentController.php:137-146`; `app/Services/Trainer/TrainerMemberAccess.php:17-28`).
- **Quién lee:**
  - el socio lee las suyas (`routes/api.php:301-306`);
  - **el CRM no tiene ningún endpoint que lea `professional_assessments`** (grep en `Controllers/Api/Admin` y `Controllers/Crm`: 0 coincidencias). Solo ve el rastro en `GET /api/admin/trainers/{trainer}/audit` (`routes/api.php:1955`), que tiene que consultar él mismo.

### 1.3 Evaluación física — `physical_evaluations` [H]

- **Esquema** (`database/migrations/2026_05_31_000004_create_physical_evaluations_table.php:22-50`): `member_id` FK CASCADE, `trainer_id` FK SET NULL, 9 medidas, `injuries`, `trainer_notes`, índice `(member_id, created_at)`.
- **No tiene columna de origen ni de referencia** (ni a la valoración ni a la cita).
- **Tres caminos de escritura:**
  1. **Copia desde la valoración profesional.** La hace `syncToPhysicalEvaluation` FUERA de la transacción, como intento sin garantía: el `catch` registra un aviso y sigue (`ProfessionalAssessmentService.php:99-103, 200-248`). Solo copia si hay al menos una medida (:220).
  2. **CRM:** `POST/PUT/DELETE /api/admin/members/{member}/physical-evaluations` (`routes/api.php:1859-1863`; `app/Http/Controllers/Api/Admin/PhysicalEvaluationAdminController.php:68-93`).
  3. **App del socio:** `POST /api/app/physical-evaluations` está **bloqueado con 403** `evaluation_member_readonly` (`app/Http/Controllers/Api/PhysicalEvaluationController.php:93-100`).
- **Producción, origen deducido** (Q04): coincidencia de `created_at` con un `submitted_at` del mismo socio a ±120 s.
  - Desde septiembre, 97 de 97 filas vienen de la copia.
  - Hay 1 fila de junio sin entrenador.
  - El camino del CRM no aparece en uso.

### 1.4 Otras piezas con «valoración» en el nombre

- **[H] `marketing_appointments.type = 'assessment'`.** Es de prospectos y de ULTRON, otro dominio. Su migración dice «NO es agenda de… entrenadores» (`database/migrations/2026_06_30_000006_create_marketing_appointments_table.php:9,26`). En producción hay 3 filas, todas `visit` (Q16).
- **[H] Nociones derivadas de «última valoración»:**
  - `App\Services\Outreach\Facts\ValuationFacts` (ULTRON H9): `max(completed_at` de la cita completada, `physical_evaluations.created_at)` (`app/Services/Outreach/Facts/ValuationFacts.php:12-26`);
  - `ironbody:emit-automation-events --only=evaluation.outdated`: SOLO mira `physical_evaluations`, con un corte de 60 días (`app/Console/Commands/EmitAutomationEvents.php:131-152`, :134).

### 1.5 Relación entre ellas [H]

```
members ─┬─< valuation_appointments  (member CASCADE, trainer RESTRICT)
         │        └─ professional_assessment_id  (vacía, sin FK)
         ├─< professional_assessments (sin FK) ──copia de medidas──> physical_evaluations (sin referencia)
         └─< physical_evaluations     (member CASCADE, trainer SET NULL)
trainers ─ mismas tres; la cita con FK RESTRICT; la valoración, sin FK.
```

- **Entrenador y socio.** Para valorar, la app exige asignación activa (`TrainerMemberAccess.php:23-27`). La agenda no lo exige (informe §4.5).
- En producción, 7 de las 43 citas `scheduled` son con un entrenador NO asignado. En 3 de ellas el socio no tiene ningún entrenador (Q15). En esos casos el entrenador de la cita **no puede** registrar la valoración en su app.

---

## 2. Qué demuestra hoy que una valoración TERMINÓ (pregunta 2)

### 2.1 Endpoints y estados [H]

| Paso | Endpoint | Efecto |
|---|---|---|
| Crear borrador | `POST /api/trainer/members/{member}/assessments` | `status=draft`, versión 1, auditoría `assessment.draft_created` (`ProfessionalAssessmentService.php:35-55`) |
| Editar borrador | `PUT /api/trainer/assessments/{uuid}` | Solo si es borrador; si no, 409 (`:57-66`; `app/Exceptions/AssessmentException.php:19-22`) |
| **Enviar (final)** | `POST /api/trainer/assessments/{uuid}/submit` | Ver 2.2 |
| Corregir | `POST /api/trainer/assessments/{uuid}/amend` | Crea la versión N+1 `submitted`; la anterior pasa a `amended`; motivo obligatorio. Solo copia a `physical_evaluations` si cambian las medidas (`:112-164`) |

### 2.2 Qué hace `submit` [H]

En `ProfessionalAssessmentService.php:72-106` y `ProfessionalAssessmentController.php:89-112`:

1. `isDraft()` sobre la instancia que trajo el *route-model binding*, **FUERA de la transacción y sin cerrojo** (:74).
2. `DB::transaction`:
   - `status=submitted`, `submitted_at=now()` (:79-82);
   - aviso al socio (:84);
   - auditoría del entrenador (:86-90);
   - `TrainerRealtimeEvents::assessmentForMember` **DENTRO de la transacción** (:94).
3. Fuera de la transacción: `syncToPhysicalEvaluation` (:103).
4. En el controlador, tras volver: `RealtimeEvents::assessment($member)` y otra vez `TrainerRealtimeEvents::assessmentForMember` (`ProfessionalAssessmentController.php:104-105`).

**No toca `valuation_appointments`** [H]: grep de `ValuationAppointment` en `app/Services/Trainer/*` y `app/Http/Controllers/Api/Trainer/*` da 0 coincidencias.

### 2.3 Borradores, reintentos y guardados parciales

- **[H] Borrador.** No notifica, no copia, no emite SSE. En la práctica dura menos de 1 min: mediana 0,0 min, máximo 1 min en 109 envíos (Q04).
- **[H] Reintento de un envío ya hecho.** Responde **409** «Solo un borrador puede enviarse» (`AssessmentException.php:24-27`). No es idempotente para el cliente: un reintento tras perder la red recibe error aunque el envío se hiciera.
- **[S] Doble envío concurrente.** La comprobación de borrador no tiene cerrojo, así que dos peticiones casi simultáneas pueden pasar las dos:
  - el aviso al socio se deduplica por `event_key` (`assessment:{uuid}:v{version}`, :283), aunque con dos inserciones simultáneas la segunda puede chocar con el UNIQUE y responder 500;
  - la copia a `physical_evaluations` **no tiene clave**, y puede crear dos filas.
  - Indicio en producción: 1 par de evaluaciones físicas del mismo socio, separadas menos de 5 min, con medidas idénticas (Q05). No demostrado.
- **[H] Copia parcial.** Si `syncToPhysicalEvaluation` falla, la valoración queda enviada y la evaluación física no se crea; solo queda un `Log::warning` (:242-247). No hay reintento ni reconciliación.
- **[H] Varias valoraciones por sesión.** 16 pares del mismo socio y entrenador separados entre 1 y 10 min, casi todos con medidas distintas, y 14 grupos de socio, entrenador y día con más de una valoración (Q04, Q05). Cualquier vínculo con la cita tiene que admitir **N valoraciones por cita**, o elegir una de forma determinista.
- **[H] Envío antes de la hora de la cita.** En 12 de 29 pares cita–evaluación del mismo día, la evaluación es ANTERIOR al `starts_at` (hasta 116 min antes; mediana +5 min; máximo +123 min) (Q03). La regla actual de `complete` («desde la hora», `ValuationAgenda.php:451`) rechazaría el cierre en esos casos.

### 2.4 ¿Hay vínculo entre cita y evaluación? [H]

- Solo existe `valuation_appointments.professional_assessment_id`, que está vacía (0 de 49; Q02), sin FK y sin índice (Q01).
- `physical_evaluations` no tiene ninguna columna que apunte a valoración o cita (Q01: 16 columnas, las de la migración).

---

## 3. Máquina de estados de la cita (pregunta 3) [H]

**Transiciones** (`app/Services/Valuations/ValuationAgenda.php:66-80`, :430-500):

| Hacia | Desde | Condición temporal | Quién |
|---|---|---|---|
| confirmed | scheduled | antes de la hora | `valuations.complete` |
| in_progress | scheduled, confirmed | desde 15 min antes | `valuations.complete` |
| completed | scheduled, confirmed, in_progress | **desde la hora** (`now >= starts_at`) | `valuations.complete` |
| no_show | scheduled, confirmed | desde la hora | `valuations.complete` |
| cancelled | scheduled, confirmed | con motivo | `valuations.cancel` |
| reprogramar | scheduled, confirmed → scheduled | reglas de hora y disponibilidad | `valuations.update` |

- **Estados finales:** `completed`, `no_show` y `cancelled` (`ValuationAppointment.php:72`). No hay forma de reabrir una cita.
- **Concurrencia optimista:** `expected_version` obligatorio; si no coincide, 409 `appointment_changed` (`ValuationAgenda.php:556-561`).
  - Al repetir una transición al estado actual se responde 200 con `changed:false` (:438-439).
  - Editar notas o tipo NO sube la versión (:238-240).
- **Cerrojos:**
  - crear y reprogramar toman `FOR NO KEY UPDATE` de los entrenadores (ordenados por id) y después del socio (:569-591);
  - transiciones y cancelación solo toman `lockForUpdate()` de la cita (:595-604).
- **Idempotencia de creación:** por `client_request_id` UNIQUE más una firma de actor y datos (:612-632); un 23505 se reinterpreta (:187-193); el 23P01 de la EXCLUDE se traduce a `slot_taken` (:652-660).
- **Actor obligatorio.** Todas las rutas pasan por `conPersona`, que exige un `Admin` (`ValuationResponses.php:31-45`). `outcome_by_admin_id` es FK a `admins` (migración :127). `AuditTrail::record` no registra nada si no hay `Admin` (`app/Services/Audit/AuditTrail.php:46-51`).
- **Avisos al CRM** (`ValuationAgenda.php:200, 366, 418, 489`): solo al crear, reprogramar, cancelar y **confirmar**. **`complete`, `start` y `no_show` no generan aviso.**
- **Alarma «sin atender».**
  - Se planifica a +15 min (`ValuationReminders.php:63-65`).
  - Sale si la cita sigue `scheduled` o `confirmed` (`ValuationReminderProcessor.php:54-60, 247-249`), hasta 2 h tarde (:253-256).
  - En producción: 42 aceptadas, 8 canceladas y 3 pendientes (Q02).
- **Uso real en producción** (Q02, Q08):
  - 49 citas creadas: 47 por Recepción y 2 por Super Admin;
  - 1 completada, por Administrativo, 13 d 00:43 después de su hora;
  - 0 confirmadas, 0 iniciadas;
  - 10 con versión mayor que 1;
  - historial: 49 `created`, 4 `rescheduled`, 5 `cancelled`, 1 `completed`.

---

## 4. Por qué administración no se entera (pregunta 4) [H]

1. **Lo que escribe el envío.** El envío de la valoración solo escribe:
   - `notifications` con `audience=member` (`ProfessionalAssessmentService.php:273-284`; `NotificationService.php:1773-1785`);
   - `trainer_realtime_events` (`app/Services/Trainer/TrainerRealtimeEvents.php:27-75`) y `member_realtime_events` (`app/Services/RealtimeEvents.php:56-81, 244-247`);
   - `trainer_audit_logs` (vía `TrainerAuditService`);
   - `physical_evaluations` (la copia).
2. **Lo que leen los streams del CRM:**
   - `notifications` admin (`app/Http/Controllers/Crm/NotificationController.php:137-157`);
   - `audit_logs`, para membresías y cuentas (`MembershipRealtimeController.php:65-91`; `AdminUserRealtimeController.php:52-69`);
   - `class_events` (`ClassEnrollmentController.php:180-209`);
   - `moderation_audit_logs`;
   - firmas de ganancias y ejercicios;
   - marketing y Studio.

   **Ninguno lee `professional_assessments`, `physical_evaluations` ni `trainer_realtime_events`.**
3. **Nada actualiza la cita** (§2.2), así que la agenda sigue mostrándola «Programada». A los 15 min suena «sin atender» (§3), y cerrarla exige una persona del CRM con `valuations.complete`:
   - en producción, Super Admin (3 activos) y Administrativo (1 activo; permiso concedido el 21-10, Q07);
   - Recepción, que crea 47 de 49 citas, no lo tiene (`app/Support/Access/CrmPermission.php:363-393`).
4. **Prueba en producción:**
   - tipos de aviso admin desde el 17-10: `system` 653, `membership` 499, `valuation` 143, `security` 107, `trainer` 82, `class` 56, `routine` 22, `payment` 9; **ningún aviso de valoración enviada** (Q07);
   - los `valuation` admin se reparten en `created` 49, `internal_upcoming` 43, `internal_unattended` 42, `cancelled` 5, `rescheduled` 4; **ninguno `completed`**;
   - 40 citas pasadas siguen `scheduled`; 23 tienen una valoración enviada el mismo día (23 del mismo entrenador) y 11 no tienen ningún registro posterior (Q03, Q15).
5. **Rastros que existen pero no avisan:**
   - `GET /api/admin/trainers/{trainer}/audit`, con `assessment.submitted`;
   - la ficha del socio, con sus `physical_evaluations` (con `members.view`).

   Las dos hay que consultarlas a mano.

---

## 5. SSE existente (pregunta 5) [H]

**Transporte común.** `App\Support\SseStream` (`app/Support/SseStream.php:26-75`):
- conexión acotada de 20–25 s, `retry: 3000`, latido `: ping`;
- `emit()` con `id:` opcional y cabecera `X-Accel-Buffering: no`.

**Autenticación:**
- **CRM:** cabecera `Authorization` o vale `?ticket=` de **un solo uso, 60 s y con finalidad** (`app/Services/Admin/StreamTicketService.php`, TTL y `ROUTES` :84-96; `app/Http/Middleware/EnsureAdminAuth.php:59-91`);
- **socio:** `auth.member` (Bearer);
- **entrenador:** `auth.trainer`.

| Stream | Fuente | Reanudación | Lectura de commits tardíos |
|---|---|---|---|
| `GET /api/admin/notifications/stream` (SELF) | tabla `notifications` admin, filtrada por `required_permission` y `TYPE_PERMISSIONS` | `?after_id=` + evento `ready{cursor}` + `id:` por fila (`Crm/NotificationController.php:137-157, 251-274`) | **No**: `id > cursor`, sin ventana de relectura |
| `GET /api/admin/memberships/stream` | `audit_logs` (lista blanca de eventos) | `?after_id=` **o `Last-Event-ID`** (`MembershipRealtimeController.php:62-67`) | **Sí**: recuento bajo el cursor y `resync` (:71-77) |
| `GET /api/admin/classes/stream` | cola de salida `class_events`, escrita en la transacción | `?after_id=`, `resync` si se podó (`app/Services/Classes/ClassEventsFeed.php:37-50`) | **Sí**: `OVERLAP = 50` ids (:24, :63-85) |
| `GET /api/admin/users/stream`, `moderation/stream` | `audit_logs` / `moderation_audit_logs` | `?after_id=` | No |
| `earnings/stream`, `exercises/stream`, `marketing/*/stream` | firma o versión calculada | — | — |
| `GET /api/admin/ultron-studio/stream` | propio | `?after=` / `Last-Event-ID` (`UltronStudioStreamController.php:204-226`) | propio |
| `GET /api/member/realtime` | `member_realtime_events` + `catalog_events` | `after_id` y `after_catalog_id`; `id:` inicial en la apertura (`MemberRealtimeController.php:35-96`) | catálogo: repite 10 s si es una app 1.0.x (:107-117) |
| `GET /api/trainer/realtime` | `trainer_realtime_events` | `after_id` | No |
| `GET /api/notifications/stream` (socio) | `notifications` del socio | `after_id` | No |

- **`Last-Event-ID`** solo lo respetan `memberships/stream` y el Studio. El resto usa `?after_id=`.
- **Escritura después del commit:**
  - **[H] Patrón bueno:**
    - `ClassEventBus` escribe `class_events` en la transacción y las señales con `DB::afterCommit` (`app/Services/Classes/ClassEventBus.php:15-34, 87-99`);
    - `CatalogEvents` y `TrainerController` también usan `DB::afterCommit`;
    - `ValuationAgenda` avisa tras devolver la transacción.
  - **[H] Patrón débil:** `RealtimeEvents::emit` y `TrainerRealtimeEvents::emit` insertan directamente y tragan la excepción.
    - En `submit` se llama DENTRO de la transacción (`ProfessionalAssessmentService.php:94`).
    - **[S]** En PostgreSQL, una inserción fallida ahí aborta la transacción (25P02) aunque el `catch` la trague, y el envío fallaría. Es el patrón de la nota «observadores dentro de transacción»; no está demostrado aquí.
- **Cola de salida.** `member_realtime_events` y `trainer_realtime_events` **NO son colas de salida**: son señales efímeras que se podan a los 5 min (`RealtimeEvents.php:71-76`) y cada hora con `realtime:prune` (`routes/console.php:57-60`).
  - En producción las dos tablas estaban **vacías** en el momento de la consulta (Q08), así que no hay serie histórica por tipo.
  - Las colas de salida duraderas reales son `class_events`, `valuation_reminders` (procesador con `FOR UPDATE SKIP LOCKED`), `automation_events` (a n8n) y `notification_dispatches` (bitácora del motor discrecional).
  - En producción, `class_events` de los últimos 7 días: `class.updated` 53, `workday.updated` 16, `biometric.updated` 4, `booking.created` 2, `device.updated` 2 (Q08).
- **[S] Commit tardío en la bandeja del CRM.** Un aviso admin cuyo id se reserva antes que otro, pero que se confirma después, puede quedar por debajo del cursor y no viajar por el stream. Lo cubre el sondeo del CRM (cada 10 s según el informe de la agenda, §2). Con inserciones en *autocommit* tras el commit de negocio, la ventana es de microsegundos.

---

## 6. Seguimiento y próxima valoración (pregunta 6)

**Lo que NO existe** [H]:
- columnas de intervalo, fecha sugerida o recurrencia por socio, plan o gimnasio. Una búsqueda en `information_schema` de `follow_up|next_valuation|next_evaluation|*_interval|reassess` devuelve 0 columnas (Q09);
- `valuation_settings` no tiene ningún campo de seguimiento (migración :35-55);
- `Plan::defaultFeatures()` solo tiene `iron_ia, workouts, custom_routines, ranking, classes, progress, nutrition` (`app/Models/Plan.php:198-209`), y en producción las claves son esas mismas (Q09);
- en `plans.benefits` (texto libre) los planes 3 y 4 dicen «Valoración inicial», los planes 5–7 dicen «Historial parcial de evaluaciones» y el plan 32 (archivado) «Valoración física inicial». **Ninguno fija frecuencia ni cupo**, y nada en el código aplica esos textos (Q10);
- `type = follow_up` existe en la cita, pero no se usa: las 49 son `initial` (Q02).

**Lo que SÍ existe y se solapa** [H]:
1. **`evaluation.outdated`:**
   - corre los lunes a las 09:00 (`routes/console.php:121-123`);
   - marca a todo socio activo sin `physical_evaluations` en **60 días fijos** (`EmitAutomationEvents.php:134`), con idempotencia mensual por socio (:148);
   - envía a n8n y crea una tarea de entrenador «Evaluación física pendiente» (`app/Services/TrainerTaskService.php:53-57`; `app/Services/AutomationEventService.php:59-74`);
   - producción: 3.878, 4.003 y 4.075 eventos en sep, oct y nov, todos `sent`; 34 tareas `pending`, **ninguna vista ni completada** (Q09).
2. **`valuation.outdated` de ULTRON (Outreach):**
   - disparador periódico con parámetro `days` de 1 a 730 y clave de ciclo `val:{last_valuation_id}` (`app/Services/Outreach/Core/OutreachCatalog.php:509`);
   - hechos `valuation.days_since_last`, `has_upcoming` y `next_date` (:577-579; `ValuationFacts.php`).

---

## 7. Buscadores de socios (pregunta 7) [H]

| Endpoint | Permiso | Cómo busca | Límite | Qué devuelve |
|---|---|---|---|---|
| `GET /api/admin/valuations/members/search?q=` | `valuations.create` + persona (`AuthorizationMap.php:661`) | `LOWER(full_name) LIKE ? ESCAPE '!'` OR `LOWER(document_number) LIKE` sin separadores; `q` de 2 a 80 con `PlainText`; excluye anonimizados (`ValuationController.php:331-384`) | 20 | nombre, final del documento, membresía, entrenador asignado |
| `GET /api/admin/physical-evaluations/members?q=` | `members.view` (`AuthorizationMap.php:178`) | `ILIKE '%q%'` en `full_name` y `document_number` (`PhysicalEvaluationAdminController.php:26-47`) | 30 | **documento completo** |
| `GET /api/admin/reports/members/search` | `reports.view` | `ilike` en `users.name`, `document`, `email`, `phone` (`app/Services/Reports/MemberInsights.php:313-340`; `ReportSql.php:48-57`) | 50 | documento, plan |
| `GET /api/admin/trainers/{trainer}/members/search` | `trainers.view` | **`like`, que en PG distingue mayúsculas**, en nombre, documento, teléfono y correo (`TrainerAdminController.php:58-82`) | 15 | teléfono y correo |
| Listado de Miembros (`GET /api/users?search=`) | `members.view` | `ilike` en nombre, correo, documento y teléfono (`UserController.php:85-94`) | paginado | ficha |

- **Sin `unaccent`** en ninguno: «Jose» no encuentra «José» (grep de `unaccent` sin coincidencias; la extensión no está instalada, Q01).
- **`pg_trgm` 1.6 está instalada**, pero solo indexa tablas de marketing (`2026_08_07_000006_add_inbox_search_indexes.php:43-45`).
  - `members` no tiene índice sobre `full_name` (Q01).
  - Con 4.136 socios (Q13), un recorrido secuencial es asumible. **[S]** No se midió `EXPLAIN`.
- `Member` lleva el alcance global `TrainerMemberScope` (`app/Providers/AppServiceProvider.php:238`). Una cuenta de entrenador vinculada solo vería a sus socios; hoy no hay ninguna.

---

## 8. Avisos y push reutilizables (pregunta 8) [H]

**Canal operativo `NotificationService`** (`app/Services/NotificationService.php:1773-1853`):
- idempotencia por `notifications.event_key` UNIQUE (`create_notifications_table.php:47-48`; `persist` en :1809-1820);
- push FCM solo si `audience=member`, la notificación es nueva y `should_popup` (mientras `fcm.only_popup` sea true, que es el valor por defecto de `config/fcm.php`). Se ejecuta con `dispatch(...)->afterResponse()` (:1828-1853).
- **No aplica preferencias, horas de silencio ni topes:** `FcmService::sendToMember` solo mira tokens (`app/Services/Fcm/FcmService.php:28-58`).
- La agenda ya lo usa para el socio: confirmación, recordatorios de 24 h y 1 h, reprogramación y cancelación. Solo llevan `should_popup` los dos recordatorios y la cancelación (`ValuationNotifier.php:41-68`).
- **[H] En producción,** 5 avisos de valoración al socio salieron entre la 01:00 y las 06:59 de Bogotá. Uno corresponde a una cita a las **02:00 de Bogotá** (Q13, Q14), probablemente un error de captura **[S]**.

**Bandeja del CRM.** `createAdminNotification` con `required_permission` (columna de `2026_10_17_200003`). La agenda la usa con `valuations.view_all` y `action_type=valuation_detail` (`ValuationNotifier.php:31, 104-123`). Es el canal natural para «valoración completada»; el CRM solo hace sonar avisos con `action_type = valuation_detail` (informe §13).

**Motor discrecional `NotificationDispatcher`** (`app/Services/Notifications/NotificationDispatcher.php:43-160`):
- bitácora `notification_dispatches` con `idempotency_key`;
- elegibilidad, preferencias por categoría, ventana horaria del gimnasio, horas de silencio del socio y topes diarios y semanales.

Es el que corresponde a un aviso no operativo al socio, como «te toca tu próxima valoración». La familia `evaluation` ya cae en `workouts` (`app/Support/Notifications/NotificationCategory.php:105`). En producción, `member_notification_preferences` tiene 7 filas (1 con baja voluntaria) (Q09).

**Valoración enviada, aviso al socio.** Ya existe: `professional_assessment`, sin `should_popup` en las 111 filas, y por tanto solo dentro de la app **[S: depende de `FCM_ONLY_POPUP` en el .env, que no se leyó]** (Q07).

**«Cita confirmada al socio».** Hoy la «confirmación» al socio significa «quedó agendada»: se envía al crear. Pasar la cita a `confirmed` no avisa al socio, solo a la bandeja (`ValuationAgenda.php:489`).

---

## 9. Producción en cifras (pregunta 9) [H]

| Métrica | Valor | Consulta |
|---|---|---|
| Citas por estado | scheduled 43 · cancelled 5 · completed 1 | Q02 |
| Citas por mes (Bogotá, `starts_at`) | 2026-10: 11 scheduled, 4 cancelled · 2026-11: 32 scheduled, 1 cancelled, 1 completed | Q02 |
| Citas activas ya pasadas | 40 (3 futuras) | Q03 |
| …con valoración el mismo día | 23 (23 del mismo entrenador); 11 sin ningún registro posterior | Q03 |
| Citas completadas con evaluación el mismo día | 1 de 1 | Q03 |
| Desfase entre evaluación y hora de la cita | 29 pares; mediana +5 min; mínimo −116; máximo +123; 12 antes de la hora | Q03 |
| Valoraciones profesionales | 109 submitted (2 son enmiendas) · 2 amended · 1 draft; por mes: jun 5, sep 32, oct 43, nov 32 | Q04 |
| Evaluaciones físicas | 98: jun 1 (sin entrenador), sep 26, oct 40, nov 31; desde septiembre, el 100 % es copia de la valoración | Q04 |
| Autor | socio: 0 (bloqueado) · copia desde la app del entrenador: 97 · CRM: 0 detectadas | Q04 |
| Socios con más de una | evaluación física: 12 (6×2, 5×3, 1×9) · valoración enviada: 14 · cita: 4 | Q02, Q04 |
| Valoraciones sin cita (desde el 17-10 19:30 UTC) | 47 enviadas: 28 con cita el mismo día (27 del mismo entrenador), **19 sin cita ese día**; 13 de socios que nunca tuvieron cita | Q05 |
| Cuentas del CRM con `trainer_id` | **0** de 14 (Super Admin 5, Recepción 4, Administrativo 2, Administrador 1, QA 2) | Q06 |
| `member_app_enabled` | **true** (Super Admin, 17-10 19:59 UTC) | Q02, Q12 |
| Banderas de avisos | confirmación, 24 h, 1 h, aviso interno de 15 min y «sin atender»: todas activas; duración 60, franja 30, horizonte 90 | Q02 |
| Avisos al socio aceptados | confirmación 49 · 24 h 26 · 1 h 41 · reprogramación 4 · cancelación 5 | Q02 |
| Disponibilidad | 20 reglas, 5 entrenadores, 0 bloqueos | Q02 |
| Tiempo real | `member_realtime_events` y `trainer_realtime_events` vacías (efímeras); `class_events` en Q08 | Q08 |
| `evaluation.outdated` | ~4.000 al mes; 34 tareas de entrenador pendientes y nunca vistas | Q09 |
| Valoraciones huérfanas (socio borrado) | 4 | Q13 |

---

## 10. Permisos (pregunta 10) [H]

| Acción | Quién (por defecto y en producción) | Fuente |
|---|---|---|
| Ver todas las citas | `valuations.view_all`: Super Admin, Administrador, Administrativo, Recepción | `CrmPermission.php:363-423` |
| Crear, mover y cancelar citas | `create`, `update`, `cancel`: Recepción, Super Admin, Administrador. **Administrativo, por concesión del 21-10** | ídem; `role_permissions` (Q07) |
| Confirmar, iniciar, completar, no asistió | `valuations.complete`: Super Admin, Administrador, rol Entrenador del CRM (0 cuentas), **Administrativo por concesión**. Recepción NO | ídem :400-409; Q07 |
| Cerrar una cita desde la app del entrenador | **Imposible**: no hay ruta | `routes/api.php:284-298` |
| Crear y enviar una valoración profesional | El entrenador autenticado, con `assessments.create/submit`, asignación activa y autoría | `routes/api.php:284-298`; `TrainerMemberAccess.php:17-28` |
| Ver valoraciones profesionales en el CRM | **Nadie**: no existe la ruta | §1.2 |
| Ver evaluaciones físicas en el CRM | `members.view`; crear, `members.create`; editar, `members.edit`; borrar, `members.archive` (Recepción tiene `members.create` y, por concesión, `members.edit`) | `AuthorizationMap.php:178, 953-958`; Q07 |
| Token compartido | Nada de `valuations` (`CrmPermission.php:267`); **sí `members.view`**, y con él lee `physical_evaluations`, incluidas `injuries` (`CrmPermission.php:233-249`) **[H por código; no se probó por HTTP]** | |
| Avisos manuales | `POST /api/admin/notifications` exige `content.manage` y solo los tipos `system` y `promotion` (`AuthorizationMap.php:413-418`; `Crm/NotificationController.php:68`) | P-2 del informe: ya cerrado |

---

## 11. Lo que NO existe (explícito) [H]

- **Vínculos y cierre:**
  - ningún vínculo escrito entre cita y valoración profesional o evaluación física;
  - ninguna columna de origen en `physical_evaluations`.
- **Avisos y tiempo real:**
  - ningún aviso a administración cuando se envía una valoración ni cuando se completa una cita;
  - ningún stream del CRM sobre valoraciones o citas (la agenda refresca a petición; informe §3: «sin… SSE»).
- **Rutas:**
  - ninguna ruta de citas en el portal del entrenador;
  - ninguna ruta del CRM para leer `professional_assessments`;
  - ningún camino para anular (`voided`) ni para reabrir una cita en estado final.
- **Seguimiento:** ningún intervalo de seguimiento por gimnasio, plan o socio; ninguna regla comercial de derecho a valoración (cupos o frecuencia).
- **Búsqueda:** ni `unaccent` ni índice trigram en `members`.

---

## 12. Propuesta mínima

### 12.1 Opciones

| | A. Vincular en el servidor al enviar (recomendada) | B. Vínculo explícito desde la app del entrenador | C. Solo avisar y conciliar a mano |
|---|---|---|---|
| Qué es | En `submit`, buscar la cita activa del mismo socio el mismo día de Bogotá; si hay **exactamente una**, completarla y enlazarla. Siempre se avisa a la bandeja | Nuevas rutas `/trainer/valuations/today`; la app envía `valuation_appointment_uuid` al enviar | Aviso «valoración enviada» en la bandeja y una vista de «citas pasadas sin cerrar»; cierra una persona |
| Esfuerzo | Bajo: backend, 1 índice, 1 texto en `ValuationMessages`, tests | Alto: backend, Flutter, publicación o parche; el entrenador hoy no ve citas | Bajo en backend; medio en el CRM |
| Riesgo | Emparejar mal si hay ambigüedad; se mitiga absteniéndose cuando no hay exactamente una candidata | Bajo en datos; alto de calendario | Mínimo en datos, pero el problema sigue: alguien tiene que cerrar |
| Reversibilidad | Código: revertir. Datos: las citas completadas son finales (no hay reapertura) | Igual que A | Total |
| Cobertura con los datos de hoy | 23 de 40 citas pasadas se habrían cerrado solas | Depende de la adopción | 0 cerradas solas |

**→ Recomendación: A.**
- Cubre el caso real (mediana +5 min; el 96 % de las coincidencias del mismo día son del mismo entrenador) sin tocar la app ni el contrato con ella.
- Reutiliza la columna que se dejó para esto, la bandeja y su sonido, y el historial de la cita.
- B queda como mejora: el servicio de A acepta un `valuation_appointment_uuid` opcional, que se valida contra el socio, para cuando la app lo envíe.
- **Si el propietario no quiere que el sistema cierre citas solo**, se aplica C con la misma pieza de emparejamiento: vincular sin completar y avisar con un botón «Completar». Es la pregunta abierta n.º 1.

### 12.2 Esquema (fase 1)

- **Ninguna columna nueva.** Se reutiliza `valuation_appointments.professional_assessment_id` con este significado: la valoración RAÍZ (`parent_id IS NULL`) que cerró la cita. Las enmiendas se encadenan con `parent_id`.
- **Una migración:** `CREATE UNIQUE INDEX valuation_appointments_assessment_uq ON valuation_appointments (professional_assessment_id) WHERE professional_assessment_id IS NOT NULL;`. Así, una valoración no puede cerrar dos citas.
  - Son 49 filas.
  - Ejecutar con `lock_timeout=3s` y el rol dueño (procedimiento de `ironbody-migrate --path`).
  - Reversión: `DROP INDEX`.
  - Sin FK, a juego con la tabla `professional_assessments`, que no las usa. **[decisión de `database-specialist`]**
- **N valoraciones por cita.** Se resuelven así:
  - la primera valoración enviada cierra la cita y queda enlazada;
  - las siguientes del mismo día solo se registran en el historial de la cita (`metadata.assessment_uuid`) y no cambian el estado.

  Si más adelante hace falta navegar desde cada valoración, la opción es añadir `professional_assessments.valuation_appointment_id` (N:1). Hoy no es necesaria.

### 12.3 Lógica y evento

**En `ProfessionalAssessmentService::submit`,** dentro de su transacción:
1. **Bloquear** la valoración (`lockForUpdate`) y repetir la comprobación `isDraft()`. Esto cierra el doble envío de §2.3 y la fila duplicada en `physical_evaluations`. El 409 de hoy se mantiene para no cambiar el contrato con la app.
2. Llamar a un método nuevo, `ValuationAgenda::completeFromAssessment(ProfessionalAssessment, Trainer)`:
   - **Candidatas:**
     - mismo `member_id`;
     - `status IN (scheduled, confirmed, in_progress)`;
     - mismo día de Bogotá entre `starts_at` y el momento del envío (`ValuationTime::local`, `app/Services/Valuations/ValuationTime.php:110`), sin exigir `now >= starts_at`, porque 12 de 29 casos reales son anteriores a la hora.
   - **Si hay más de una:** se prefiere la del mismo `trainer_id`. Si sigue sin quedar exactamente una, **no se enlaza** (motivo: `ambiguous`).
   - **Con una candidata:** `SELECT … FOR UPDATE` de la cita y nueva comprobación:
     - **abierta** → `status=completed`, `completed_at=now`, `professional_assessment_id`, `version+1`; `ValuationReminders::cancelWaiting` (acaba con «sin atender»); evento `completed` con `actor_admin_id=NULL`, `actor_name` del entrenador y `metadata {source:'trainer_assessment', assessment_uuid, trainer_id, matched_by}`;
     - **ya `completed` sin enlace** → solo se rellena el enlace, sin subir la versión (no es cambio de hora, entrenador ni estado);
     - **final por otro motivo** → no se hace nada.
   - **Cerrojos:** valoración → cita. Ningún camino de la agenda toma cita → valoración, así que no hay ciclo. No hace falta el mutex `FOR NO KEY UPDATE` de entrenador y socio: pasar a un estado final libera el hueco y no puede crear un solape.
   - **Auditoría:** `AuditTrail` no registra sin un `Admin` (§3). La traza queda en `valuation_appointment_events` y en `trainer_audit_logs` (`assessment.submitted`, con `appointment_uuid` en `metadata`).
3. **Después del commit,** con `DB::afterCommit` o tras devolver la transacción, como hace `ValuationAgenda`:
   - **Con cita:** `ValuationNotifier::internalEvent($cita, 'completed')`. Requiere añadir el caso `completed` a `ValuationMessages::forInternalEvent` (`app/Services/Valuations/ValuationMessages.php:77-83`).
     - Clave `valuation:{uuid}:crm_completed:v{version}`, que es idempotente.
     - `required_permission = valuations.view_all`, `action_type = valuation_detail`.
     - Viaja por `admin/notifications/stream` sin crear un stream nuevo.
   - **Sin cita:** un aviso admin `type=valuation`, `source_event=valuation_assessment_unlinked`, clave `valuation:assessment:{assessment_uuid}:unlinked`, con el mismo permiso.
     - Necesita un `action_type` nuevo (p. ej. `member_valuations`, con `member_id`) que el CRM debe enrutar.
     - Prioridad `medium`, para que no suene.
4. **Mover también** el `TrainerRealtimeEvents::assessmentForMember` de :94 a después del commit. El controlador ya lo vuelve a emitir (:105). **[adyacente: hacerlo solo si `backend` lo confirma dentro del alcance]**

**Datos pasados (opcional, con autorización):** un comando `valuations:link-assessments` que use EL MISMO método.
- Simulación por defecto; `--apply` exige orden expresa del propietario.
- Afectaría a unas 23 citas.
- Antes, guardar la lista `(id, status, version)` para poder revertir a mano con SQL autorizado, porque el estado `completed` es final.

### 12.4 Intervalos de seguimiento (fase 2, aditiva)

**Precedencia:** gimnasio < plan < socio. El valor efectivo es `socio.interval_days ?? plan.valuation_follow_up_days ?? valuation_settings.follow_up_interval_days ?? NULL` (NULL = sin seguimiento).

| Nivel | Dónde | Por qué ahí |
|---|---|---|
| Gimnasio | `valuation_settings.follow_up_interval_days smallint NULL` | Es la fila única de ajustes de la agenda; ya tiene editor (`PUT /settings`, `manage_settings`) |
| Plan | `plans.valuation_follow_up_days smallint NULL` | **No** en `plans.features`: es un JSON de booleanos que se fusiona con los valores por defecto y viaja a la app (`Plan.php:198-216`; `Member::resolvedFeatures`, `app/Models/Member.php:392-415`). El plan del socio se resuelve **por nombre** (`users.plan` = `plans.name`): es un acoplamiento por significado que hay que respetar |
| Socio | Tabla nueva `member_valuation_follow_ups` (`member_id` UNIQUE, FK a members ON DELETE CASCADE; `interval_days` NULL; `due_on` date NULL, para fijar una fecha a mano; `updated_by_admin_id`; marcas de tiempo) | No toca `members`, cuya fila es el cerrojo de la agenda (`FOR NO KEY UPDATE`); se revierte con `DROP TABLE` |

- **Próxima fecha:** `due_on ?? (última valoración + intervalo efectivo)`, con «última valoración» con la MISMA semántica que `ValuationFacts` (§1.4). Se calcula al leer; no se guarda el resultado.
  - Se expone en el historial del socio (`GET /api/admin/valuations?member_id=`) como `follow_up {interval_days, source, last_at, next_due_on}`.
- **Avisos de seguimiento:**
  - **al CRM,** un resumen diario (clave `valuation:followups:{fecha}`) con permiso `valuations.view_all`;
  - **al socio,** SOLO por `NotificationDispatcher` (respeta ventana, silencio y topes), nunca por `NotificationService`.
- **Solape que hay que decidir:** cuál de estas es la fuente de verdad, para no acabar con tres:
  - `evaluation.outdated` (60 días fijos, unos 4.000 eventos al mes, tareas que nadie mira);
  - `valuation.outdated` de ULTRON.

### 12.5 Riesgos y migraciones

| Riesgo | Mitigación |
|---|---|
| Completar la cita equivocada | Abstenerse si no hay exactamente una candidata; `matched_by` en el historial; aviso con enlace a la cita; prueba de los 2 casos reales de socio con 2 citas el mismo día (Q05) |
| Estado final sin reapertura | Lo decide el propietario; si se quiere marcha atrás, hace falta un `reopen` administrado (fuera del alcance) |
| Interbloqueos en PG | Orden valoración → cita; probar en **PostgreSQL**, porque SQLite no ve los cerrojos (notas `sqlite-no-ve-postgres`, `observadores-dentro-de-transaccion-pg`) |
| Día de Bogotá frente a UTC | Envío a las 23:30 de Bogotá (04:30 UTC del día siguiente); relojes de prueba en UTC (nota `relojes-de-prueba-en-utc`) |
| Que el aviso rompa el envío | Solo después del commit; `internalEvent` ya traga y registra (`ValuationNotifier.php:83-96`) |
| ULTRON H9 y `ValuationFacts` | Al pasar citas a `completed`, cambia la «última valoración» de esos socios. Es coherente, porque la evaluación física del mismo día ya contaba, pero hay que avisar a quien opera H9 |
| Despliegue | Primero el índice, después el código, aunque el código no depende del índice; no hay rutas nuevas en la fase 1. Si la fase 2 añade rutas, `route:cache` en producción (nota `correcciones-administrativas`) |

---

## 13. Plan secuenciado

1. **[database-specialist]** Revisar y escribir la migración del índice único parcial (§12.2), con su `down()`.
   - Verificación: `migrate` y `rollback` en PG 14 desechable; `pg_indexes` muestra el índice; 0 duplicados en producción (hoy la columna está vacía).
2. **[backend]** `ValuationAgenda::completeFromAssessment` y el caso `completed` en `ValuationMessages`, más el aviso «sin cita» en `ValuationNotifier`. Sin rutas nuevas. *(Puede ir en paralelo con el paso 1.)*
   - Verificación: pruebas de servicio en PG.
3. **[backend]** En `ProfessionalAssessmentService::submit`: cerrojo y nueva comprobación del borrador; llamada al emparejamiento; aviso tras el commit. Opcionalmente, mover la señal del entrenador fuera de la transacción.
   - Verificación: `tests/Feature/ProfessionalAssessmentTest.php` y `tests/Feature/Valuations/*` en verde, en SQLite y en PG. **Primer punto en verde.**
4. **[tester]** Casos a cubrir:
   - exactamente una cita → completada, enlazada, avisos pendientes cancelados, 1 aviso a la bandeja;
   - cero o dos candidatas → sin cambios y aviso «sin cita»;
   - evaluación antes de la hora;
   - cita de otro entrenador;
   - cita ya completada por un admin → solo enlace, sin aviso duplicado;
   - cancelada o `no_show` → no es candidata;
   - `amend` → no vuelve a completar;
   - doble envío concurrente con barrera PG → 1 cierre, 1 aviso, 1 `physical_evaluation`;
   - borde del día de Bogotá;
   - fallo del aviso → el envío se confirma igual;
   - «sin atender» no sale tras el cierre;
   - el filtro de `required_permission` en lista, contador, stream y lectura (patrón de `ValuationNotificationsTest`).
5. **[security / api-security-specialist]** Revisar que:
   - una identidad de entrenador (`auth.trainer`) cambie el estado de un recurso administrativo solo a través del socio que posee y tiene asignado, y que se valide el `valuation_appointment_uuid` futuro (caso IDOR);
   - el contenido del aviso (nombre corto del socio) llegue solo a quien tenga `valuations.view_all`.
   - Fuera de esta tarea, pero señalados: el token compartido lee `injuries` y medidas vía `members.view`; Recepción crea y edita evaluaciones físicas; quedan 4 valoraciones huérfanas tras borrar socios; los push de valoración no respetan horas de silencio.
6. **[frontend]** Para el CRM:
   - enrutar `source_event = valuation_completed` y `valuation_assessment_unlinked`;
   - refrescar la agenda al llegar un aviso `valuation`;
   - mostrar «Completada por el entrenador desde su app» con el `actor_name`.
7. **[backend] (con autorización)** Comando de enlace retroactivo en simulación y, con orden expresa, `--apply` sobre las ~23 citas.
8. **Fase 2 (tras las preguntas 3 y 4).**
   - **[database-specialist]** Migraciones de §12.4.
   - **[backend]** Resolución del intervalo y su presentación.
   - **[tester]** Precedencia y nulos.

---

## 14. Preguntas abiertas (solo las que cambian el plan)

1. ¿El sistema **completa** la cita al recibir la valoración (opción A), o solo la **vincula y avisa**, y cierra una persona (opción C)?
2. ¿Se enlazan las ~23 citas pasadas con valoración el mismo día? Es una escritura en producción y necesita autorización.
3. ¿Qué intervalo por defecto tiene el gimnasio, y sustituye al `evaluation.outdated` de 60 días (n8n y tareas) o conviven?
4. Si valora un entrenador distinto del de la cita (1 caso real), ¿se acepta el cierre o queda «sin cita»?

---

## Apéndice A — Consultas a producción

Directorio: `backend/docs/agenda-valoraciones/auditoria-ciclo-2026-11-18/sql/`.

- `01_esquema.sql`: columnas, restricciones, índices, extensiones y lotes de migración.
- `02_agenda.sql`: ajustes, estados, meses, columnas de resultado, historial, avisos, disponibilidad y citas por socio.
- `03_cruce.sql`: cruce cita–evaluación–valoración por socio y día de Bogotá, y desfase.
- `04_evaluaciones.sql`: valoraciones y evaluaciones por mes, origen deducido y duplicados.
- `05_duplicados.sql`: pares por separación, sin medidas, valoraciones con y sin cita.
- `06_cuentas_permisos.sql`, `07_politicas_y_avisos.sql`: roles, `trainer_id`, `role_permissions`, avisos de valoración.
- `08_tiempo_real_y_seguimiento.sql`, `09_seguimiento.sql`: quién cerró, tiempo real, `evaluation.outdated`, tareas, planes y preferencias.
- `10_beneficios.sql`: fragmentos del catálogo público de planes.
- `11_auditoria.sql`, `12_ajustes_cambio.sql`: auditoría del módulo y cambio de `member_app_enabled`.
- `13_varios.sql`, `14_horas.sql`: socios, avisos nocturnos, huérfanas, entrenadores y asignaciones.
- `15_asignacion.sql`: citas con entrenador asignado o no.
- `16_marketing.sql`: agenda comercial.

Todas corrieron con `default_transaction_read_only=on` (01 lo comprueba: `ro = on`), solo con SELECT y sin objetos temporales.
