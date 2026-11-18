# Contrato: ciclo de valoraciones (finalización automática, seguimiento y próxima cita)

**Versión 1 (18-11-2026).** Base: la auditoría de `auditoria-ciclo-2026-11-18/`.

**Principio:** se reutiliza la agenda existente (`valuation_*`), la bandeja de avisos del CRM con su SSE (`/api/admin/notifications/stream`) y el asistente de citas. No hay ninguna segunda agenda ni ningún stream nuevo.

---

## 1. Finalización automática (backend A)

**Disparador:** `ProfessionalAssessmentService::submit()`, cuando el entrenador pulsa «Enviar al miembro». Es lo único que demuestra que una valoración terminó. Ni el borrador, ni la enmienda (`amend`), ni generar un plan, ni un reintento.

**Dentro de la MISMA transacción del envío:**
1. `SELECT … FOR UPDATE` de la valoración y nueva comprobación de que sigue en borrador. Un doble envío concurrente da UN envío; el segundo recibe el 409 de siempre.
2. **Candidatas:**
   - citas con el mismo `member_id`;
   - `status IN (scheduled, confirmed, in_progress)`;
   - `professional_assessment_id IS NULL`;
   - **mismo día de America/Bogota** que `submitted_at`.

   Se acepta que el envío sea anterior a la hora de la cita.
3. **Regla de decisión (determinista):**

   | Caso | Resultado | Aviso (`source_event`) |
   |---|---|---|
   | Exactamente 1 candidata **y** su `trainer_id` = el entrenador que envía | Se completa y se enlaza | `valuation_completed` |
   | 0 candidatas | No se toca ninguna cita | `valuation_assessment_unlinked` |
   | ≥ 2 candidatas | No se toca ninguna cita (ambigua) | `valuation_assessment_review`, `reason=ambiguous` |
   | 1 candidata de **otro** entrenador | No se toca la cita | `valuation_assessment_review`, `reason=different_trainer` |

4. **Completar:**
   - bajo `FOR UPDATE` de la cita: `status=completed`, `completed_at = submitted_at` (la fecha REAL), `professional_assessment_id`, `version+1`;
   - se cancelan los recordatorios pendientes («sin atender» incluido);
   - evento `completed` en el historial de la cita, con `actor_admin_id=NULL`, `actor_name` del entrenador y `metadata {source:'trainer_assessment', assessment_uuid, trainer_id}`;
   - traza en `trainer_audit_logs` (`assessment.submitted` con `appointment_uuid`).
5. **Índice único parcial:** `valuation_appointments(professional_assessment_id) WHERE professional_assessment_id IS NOT NULL`. Una valoración nunca cierra dos citas.

**Después del commit** (`DB::afterCommit` o tras devolver la transacción), los avisos van a la bandeja del CRM (`createAdminNotification`):
- `type='valuation'`, `required_permission='valuations.view_all'`;
- **SIN** medidas ni datos físicos.

| `source_event` | `title` | `message` (ejemplo) | `action_type` | `action_payload` | `event_key` |
|---|---|---|---|---|---|
| `valuation_completed` | «Valoración completada» | «Carlos P. finalizó la valoración de María G. · 18 nov, 10:40 a. m.» | `valuation_detail` | `{appointment_uuid}` | `valuation:assessment:{assessment_uuid}:completed` |
| `valuation_assessment_unlinked` | «Valoración realizada sin cita» | «Carlos P. registró la valoración de María G. · 18 nov. No había cita ese día.» | `valuation_member` | `{member_id, assessment_uuid}` | `valuation:assessment:{assessment_uuid}:unlinked` |
| `valuation_assessment_review` | «Valoración por revisar» | «…la cita del día era con Ana R.» o «…había 2 citas ese día» | `valuation_member` | `{member_id, assessment_uuid, appointment_uuid?, reason}` | `valuation:assessment:{assessment_uuid}:review` |

- `metadata` repite `source_event`, `member_id`, `trainer_id`, `assessment_uuid` y `appointment_uuid` (si hay) y, en la revisión, `reason`.
- **Nombres:** nombre corto (nombre + inicial), como los avisos actuales de la agenda.
- `TrainerRealtimeEvents::assessmentForMember` pasa a emitirse DESPUÉS del commit.

## 2. Reconciliación histórica (backend A)

**Comando:** `valuations:link-assessments [--apply] [--json]`. Ensaya por defecto. Usa EXACTAMENTE la misma regla de decisión (§1.3) contra las valoraciones `submitted` cuya fecha de Bogotá coincide con una cita abierta del mismo socio.

**Al aplicar:**
- se enlaza y se completa con `completed_at = submitted_at` de la valoración (fecha real);
- evento `completed` con `metadata {source:'historical_reconciliation', assessment_uuid, reconciled_at:now}`;
- se cancelan los recordatorios pendientes;
- **ningún aviso** al CRM ni al socio;
- no crea valoraciones, rutinas ni medidas.

**Idempotente:** solo citas abiertas y sin enlace, más el índice único.

**Informe:** por candidata se da su categoría (`linked`, `ambiguous`, `different_trainer`, `already_closed`, `cancelled`), sin nombres.

## 3. Seguimiento (backend B)

**Precedencia del intervalo:**
1. **Socio:** `member_valuation_follow_ups.interval_days`.
2. **Plan:** NO se implementa. No existe ninguna regla comercial validada; los beneficios solo dicen «Valoración inicial».
3. **Gimnasio:** `valuation_settings.follow_up_interval_days`. Valor inicial 60, límites 1..365; NULL = sin seguimiento.

**Tabla nueva `member_valuation_follow_ups`:**
- `member_id` UNIQUE, FK `members` ON DELETE CASCADE;
- `interval_days` smallint NULL (NULL = hereda);
- `enabled` boolean, por defecto true;
- `due_on` date NULL (fecha puntual que sustituye al cálculo);
- `version` int;
- `updated_by_admin_id`;
- marcas de tiempo.

Los cambios se auditan con `AuditTrail` (antes y después).

**Última valoración:** la más reciente de estas dos fechas, en Bogotá:
- `professional_assessments.submitted_at` (`status` `submitted` o `amended`, raíz o enmienda: cuenta la fecha del envío original);
- `valuation_appointments.completed_at` (`status` `completed`).

Se alinea con `ValuationFacts` si su semántica es compatible; si no lo es, se documenta.

**Próximo control sugerido:**
- `due_on`, si existe;
- si no, `fecha de la última valoración + intervalo efectivo`, en días de calendario y en America/Bogota;
- NULL si no hay valoración, si el seguimiento está pausado o si el intervalo efectivo es NULL.

Se calcula al leer: no se guarda y nunca reescribe el histórico.

**Estado del seguimiento** (por orden de prioridad):

| Estado | Condición |
|---|---|
| `pausado` | `enabled=false` |
| `agendado` | Hay una cita abierta futura |
| `sin_valoracion` | No hay ninguna valoración |
| `vencido` | Faltan menos de 0 días |
| `proximo` | Faltan de 0 a 7 días |
| `al_dia` | Faltan más de 7 días |

**Endpoints:**
- `GET /api/admin/valuations/members/{member}/follow-up`. Permiso `valuations.view`, con el alcance de la agenda: `view_all`, o `view` más `own_trainer_id` / `TrainerMemberScope`. Fuera del alcance, 404.
- `PUT /api/admin/valuations/members/{member}/follow-up`. Permiso `valuations.update`.
  - Cuerpo: `{interval_days: int|null (1..365), enabled: bool, due_on: 'Y-m-d'|null, expected_version: int}`.
  - Responde igual que el GET. 409 `follow_up_changed` si la versión no coincide; 422 si no valida.
- Ajustes: `follow_up_interval_days` en el GET y el PUT existentes de `/api/admin/valuations/settings`.

**Respuesta de `follow-up`:**

```json
{ "data": {
  "member": {"id": 1, "name": "María G.", "document_tail": "…1234", "membership": {...}, "assigned_trainer": {"id": 3, "name": "…"}|null},
  "last_valuation": {"at": "ISO-8601 -05:00", "date": "2026-11-18", "trainer": {"id": 3, "name": "…"}|null,
                     "source": "assessment|appointment", "assessment_uuid": "…"|null, "appointment_uuid": "…"|null} | null,
  "interval": {"days": 30|null, "source": "member|gym|null", "enabled": true, "gym_default_days": 60},
  "due_on_override": "2026-12-03"|null,
  "next_suggested_on": "2026-12-18"|null,
  "days_until_due": 30|null,
  "next_appointment": {"uuid": "…", "starts_at": "ISO", "trainer": {"id":3,"name":"…"}, "status": "scheduled"} | null,
  "status": "sin_valoracion|al_dia|proximo|vencido|agendado|pausado",
  "version": 1,
  "can_edit": true,
  "history": [ {"kind": "appointment|assessment", "at": "ISO", "date": "Y-m-d", "status": "…",
                "trainer": {"name": "…"}|null, "appointment_uuid": "…"|null, "assessment_uuid": "…"|null,
                "linked": true, "origin": "agenda|app_entrenador|reconciliacion", "completed_at": "ISO"|null} ]
}}
```

- `history` va de la más reciente a la más antigua, con un máximo de 50.
- Una cita completada y su valoración enlazada salen en UNA sola fila (`linked=true`).

## 4. Próxima cita sin duplicados (backend A)

`POST /api/admin/valuations` (crear, sin cambios para los clientes actuales) acepta además `as_next_follow_up: true` (opcional).
- Bajo el cerrojo del socio que ya toma `create`: si el socio tiene una cita abierta FUTURA, responde 409 `member_has_future_appointment` con `existing: {uuid, starts_at, trainer:{id,name}, status}`.
- La idempotencia sigue siendo `client_request_id`.
- El CRM ofrece entonces «Conservar» o «Reprogramar», esta última con la reprogramación existente.

## 5. Buscador (backend A)

`GET /api/admin/valuations/members/search?q=`:
- **Sin mayúsculas ni tildes** («Mari» encuentra «María»; también la ñ). Funciona en PG y SQLite: cadena de `replace` o `translate` según el motor, y `q` normalizado en PHP.
- **Permiso:** pasa de `valuations.create` a `valuations.view`. El cambio en `AuthorizationMap` lo hace backend B.
- La forma de la respuesta no cambia.

## 6. Horario de silencio (backend A)

Afecta a los push al socio de citas (`ValuationReminderProcessor` → `ValuationNotifier::toMember`).
- Si en el momento del envío el socio está en sus horas de silencio (`MemberNotificationPreference::inQuietHours`) o fuera de la ventana del gimnasio (`SendingWindow`, 7–22 Bogotá):
  - **si el aviso aún sirve antes de la cita**, se pospone a la siguiente hora permitida;
  - **si no**, se registra en la app SIN push (`should_popup=false`).
- **Los avisos internos del CRM no cambian.**
- **No se toca** `NotificationDispatcher`, `CampaignService` ni las campañas push: la #4 está programada.

## 7. Detector de 60 días (backend B)

- **`evaluation.outdated`** (`EmitAutomationEvents` y `DetectEvaluationOutdated`) pasa a usar el seguimiento de §3:
  - solo socios con membresía vigente, seguimiento activo, `next_suggested_on <= hoy` y sin cita futura;
  - idempotencia por socio y fecha de vencimiento, en vez de una vez al mes;
  - el tipo y la forma del evento no cambian, para mantener la compatibilidad con el enrutador de n8n.
- **Tareas de entrenador:** como mucho una pendiente por socio y tipo; no se crea otra si ya hay una abierta.

## 8. CRM (frontend)

- **Avisos:** enruta `valuation_member` al panel de seguimiento del socio. `valuation_detail` ya existe.
- **Refresco:** la agenda y el panel abierto se refrescan al llegar un aviso `type=valuation`.
- **Vista «Seguimiento»** en el módulo de valoraciones:
  - buscador con debounce (300 ms) sobre `members/search`;
  - panel del socio (§3) con historial, intervalo (accesos rápidos 15/20/30/60/90 y «Personalizar intervalo»), pausar o reactivar, próximo control y próxima cita.
- **«Programar próxima valoración»** abre el asistente existente con el socio ya elegido, el entrenador sugerido (el de la última valoración o el asignado) y la fecha sugerida. Envía `as_next_follow_up: true` y `type=follow_up`.
  - Un 409 `member_has_future_appointment` enseña la cita existente con «Conservar» o «Reprogramar».
- **Selector de huecos:** si la fecha supera `max_horizon_days`, lo dice («fuera del horizonte de la agenda: N días»).
- **Estética:** negro con acentos amarillos, con los tokens `--ib-*`, `ib-dialog` y las clases `.va-*`.

---

## Apéndice: Detector (`evaluation.outdated`, backend B, 18-11-2026)

### Consumidores del evento (auditados en el código antes de cambiarlo)

| Consumidor | Qué lee | Compatibilidad |
|---|---|---|
| **Enrutador de n8n** (`n8n/workflows/ironbody_automation_router.json`, nodo «Map + Notify Laravel»; `docs/n8n/automation-router-production.md` §4) | `x-ironbody-event` o `body.event_type` y `data.member.id`. Lo traduce a `POST /api/internal/automation/notify-member` con el aviso al socio «Actualiza tu evaluacion» → `/evaluation`, prioridad `normal` | **Compatible.** Mismo tipo, misma forma (`member.{id,name}`, `evaluation.has_any`). No se tocó el workflow |
| **Tareas del entrenador** (`TrainerTaskService::EVENT_MAP`, desde `AutomationEventService::emit`) | `event_type`, `member_id`, `payload.member.name` | **Compatible.** Mismo título y texto. Cambia la deduplicación (abajo) |
| **Categoría del aviso al socio** (`NotificationCategory`: familia `evaluation` → `workouts`; fijado por `CoachEventMappingTest`) | El `type` del aviso que crea `notify-member` | Sin cambios |
| **App Flutter** (`iron_notification_models.dart`, `coach_notifications_screen.dart`) | El `type` `evaluation.outdated` del aviso: icono y ruta | Sin cambios |
| **Ningún otro** | `grep` de `evaluation.outdated` en `backend/`, `n8n/` y `docs/`, y en los repositorios del CRM y de la app | — |

`valuation.outdated` de ULTRON (`ValuationFacts`) es **otro** evento, con su propio disparador: no se tocó.

### Qué cambió

| | Antes | Ahora |
|---|---|---|
| Quién | Todo socio con `members.status = active` sin `physical_evaluations` en los últimos 60 días fijos, **incluido quien nunca se valoró** (~4.000 eventos al mes) | Solo socios con membresía vigente, seguimiento activo, `next_suggested_on ≤ hoy` (Bogotá) y sin cita viva futura. Usa el MISMO cálculo que el panel de §3 (`ValuationFollowUps::dueForOutreach`) |
| Membresía vigente | — (solo `members.status`) | Socio activo y no anonimizado, cuenta activa según `MembershipFilter` (deja fuera las congeladas) y `membership_end_date` conocida y ≥ hoy |
| «Última valoración» | `physical_evaluations.created_at` | La de §3: la raíz de la valoración profesional (`submitted_at`) o la cita `completed` (`completed_at`, o su inicio si no lo tiene) |
| Intervalo | 60 fijo en código | El del socio, si no el del gimnasio (`follow_up_interval_days`, 60 al migrar); NULL = sin seguimiento. `due_on` lo sustituye |
| Idempotencia | `evaluation.outdated:{member_id}:{AAAA-MM}` (uno al mes mientras siguiera vencido) | `evaluation.outdated:{member_id}:{next_suggested_on}`: uno por vencimiento. Si se valora o cambia la regla y vuelve a vencer, sale otro |
| Forma | `{member:{id,name}, evaluation:{has_any}}` | Igual. `has_any` es `false` solo si vence por una `due_on` sin ninguna valoración |
| Horario | Lunes 09:00 (`routes/console.php`) | Igual (no se tocó) |
| Tareas del entrenador | Una por evento (clave por evento) con ventana anti-spam de 12 h → una por mes y socio, sin cerrar (34 pendientes en producción) | Como mucho UNA abierta (`pending` o `seen`) por socio y tipo `evaluation.outdated`, sea del entrenador que sea. Si hay una, `skipped_duplicate` con la abierta. Los demás tipos no cambian |

**No se eliminó ningún comando ni servicio.** `ironbody:detect-evaluation-outdated` sigue delegando en `ironbody:emit-automation-events --only=evaluation.outdated`.

### Efectos que hay que saber

- **El volumen cae de golpe.** De ~4.000 al mes a los socios vencidos de verdad. Cota superior con los datos de la auditoría (§9): 109 valoraciones enviadas y 14 socios con más de una, o sea menos de 100 socios con alguna valoración. **No se midió** cuántos están vencidos hoy. Quien nunca se valoró deja de recibir el evento y su tarea.
- **Primera ejecución tras desplegar:** las claves nuevas no chocan con las mensuales (`…:2026-11`), así que un socio que ya recibió el evento de noviembre y sigue vencido recibe UNO más con la clave nueva.
- **Aviso al socio:** n8n lo convierte en «Actualiza tu evaluacion». En los últimos 30 días no se entregó ninguno a socios (medición de producción citada en el encargo); este cambio no lo arregla ni lo empeora: solo reduce y afina a quién se emite.
- **Las 34 tareas pendientes** que ya existen no se tocan (no hay escrituras en producción). Mientras siga abierta la de un socio, el detector no le crea otra.
