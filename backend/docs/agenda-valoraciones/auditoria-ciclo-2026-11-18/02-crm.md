# Auditoría del CRM: agenda de valoraciones, historial, buscador de socios, avisos y SSE

- **Tipo:** solo lectura. No se editó, compiló ni desplegó nada.
- **Código auditado:** worktree `_worktrees/subsanacion-20209/Iron-Body_Front`, commit `ce695d7` (el que sirve producción). Árbol limpio.
- **Contrato de la API:** el backend del mismo worktree, `Iron-Body/backend`, commit `8a4b51d1`.
- **Fecha:** 18-11-2026.

**Convención de rutas:**
- `F:` = `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/subsanacion-20209/Iron-Body_Front/src/app/`
- `B:` = `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/subsanacion-20209/Iron-Body/backend/`

Lo marcado **[H]** es hipótesis: está deducido del código, pero no se ha reproducido en ejecución. Todo lo demás está leído en el código, con su fichero y su línea.

---

## Resumen ejecutivo

1. **El módulo de agenda está completo.** Tiene vistas de mes, semana, día y lista; un asistente de 4 pasos; un panel de detalle con transiciones; disponibilidad y ajustes. Todas las acciones salen de `allowed_actions`, que calcula el servidor, y los conflictos se resuelven con `expected_version` (409).
2. **`va-slot-picker` es la pieza reutilizable clave.** Es una tira de 7 días alimentada por `/slots`. Para «Programar próxima valoración» basta con un `diaInicial`.
3. **El asistente NO admite socio precargado.** `BookingPreset` solo trae `trainerId`, `dia` y `hhmm` (`F:modules/valuations/va-booking-wizard.ts:41-45`). Es el cambio mínimo imprescindible para «Programar próxima».
4. **El historial del socio está en su ficha** (`member-details-modal`), con la sección `app-member-valuations`. El del entrenador está en su drawer (`app-trainer-valuations`). **Solo muestra citas.** Las evaluaciones físicas viven en otra pantalla (`/physical-evaluations`) sin enlace con las citas. Las valoraciones profesionales (`ProfessionalAssessment`) no tienen ninguna vista en el CRM. La columna `professional_assessment_id` existe, pero «v1 no la rellena».
5. **Ningún buscador de socios ignora tildes en el servidor.** Todos usan `LIKE`, `LOWER LIKE` o `ilike`. Escribir «Mari» **no** encuentra «María» [H: deducido de la SQL; no ejecutado contra PG]. El único que ignora tildes es el filtro **local** del calendario, y solo filtra las citas ya cargadas.
6. **Buscador que conviene reutilizar:** el del asistente, `ValuationsService.searchMembers` → `/admin/valuations/members/search`. Devuelve `members.id`, la cola del documento, la membresía y el entrenador asignado, y excluye a los anonimizados. Hay que hacer dos cosas: extraerlo a un componente y conseguir que el backend lo haga insensible a tildes. Además exige `valuations.create`.
7. **SSE: la agenda no tiene canal propio.** Escucha `notifications/stream` (vía `NotificationsService.received$`) filtrando `type=valuation`, con un sondeo de 120 s como respaldo. Los avisos de valoración solo llegan a quien tiene `valuations.view_all`. Un entrenador con `view` + `own_trainer_id` **solo** se refresca por sondeo.
8. **Iniciar, completar y marcar «no asistió» no generan aviso.** Solo lo hacen crear, reprogramar, cancelar y confirmar, además de los dos recordatorios internos. Por eso **no existe un aviso «valoración completada»**, y la agenda de otra persona tarda hasta 120 s en reflejar un «completada».
9. **[H] Riesgo del canal de notificaciones.** Si la persona entra o sale de sesión en la misma pestaña sin recargar, el SSE de notificaciones no se abre, o queda muerto. Las consecuencias:
   - La agenda pierde el refresco por evento.
   - Las alertas generales pierden el sonido, porque dependen solo del canal.
   - Las alertas de valoraciones bajan a la relectura de 120 s.
10. **Para la vista de seguimiento por socio, el backend no da todavía «próximo control sugerido» ni «completed_at».** Una ficha por socio se puede montar en el cliente con lo que hay. Una lista de «controles vencidos» de todos los socios **necesita un endpoint nuevo**, porque el listado exige un rango de 62 días como máximo o un único socio.

---

## 1. Módulo de agenda de valoraciones

### 1.1 Pantallas y componentes

| Pieza | Fichero | Qué es |
|---|---|---|
| `module-trainers-page` | `F:modules/trainers-page.ts` | Ruta `/trainers` con pestañas `trainers` y `citas`, cada una con su permiso (`:34-37`). Se cargan en diferido por un `InjectionToken` (`:49-55`). `?cita=<uuid>` fuerza la pestaña `citas` (`:167-170`). La ruta admite `trainers.view` o `valuations.view` (`F:app.routes.ts:166-170`; menú en `F:app.html:116`). |
| `va-valuations-tab` | `F:modules/valuations/valuations-tab.ts` | El calendario. Cabecera (navegación, vista, «Agendar valoración»), filtros, leyenda y superficie, en `:106-364`. |
| `va-time-grid` | `va-time-grid.ts` | Rejilla de semana o día, por columnas (día o entrenador). Inputs en `:333-347`. |
| `va-month-view` | `va-month-view.ts` | Mes con «+N más» (`:136-144`). |
| `va-list-view` | `va-list-view.ts` | Lista agrupada por día (`:106-111`). |
| `va-detail` | `va-detail.ts` | Detalle en `ib-dialog variant="drawer"` (`:93-99`). Tiene los modos ver, reprogramar y notas (`:55`). |
| `va-booking-wizard` | `va-booking-wizard.ts` | Asistente de 4 pasos: socio, entrenador, fecha y hora, confirmar (`:49-54`). Usa `ib-dialog size="lg"`. |
| `va-slot-picker` | `va-slot-picker.ts` | Selector de fecha y hora contra `/slots`. |
| `va-availability` | `va-availability.ts` | Franjas semanales por entrenador, con vigencia y bloqueos de los próximos 60 días (`:134-141`, `:253`). |
| `va-settings` | `va-settings.ts` | Ajustes de la agenda: duración, margen, antelación, horizonte (1–365 días) y avisos (`:66-78`). |
| `va-alert-settings` y `app-valuation-alert` | `valuation-alert.ts` | Preferencias de sonido y tarjeta flotante de aviso urgente. |
| `app-member-valuations` y `app-trainer-valuations` | `valuation-histories.ts` | Secciones de historial dentro de las fichas (§2). |

**Vista inicial:** semana, o día si el ancho es ≤ 640 px (`valuations-tab.ts:652-658`).

### 1.2 Estados que muestra

**Calendario** (`valuations-tab.ts`):
- Cargando capacidades, con esqueleto (`:109-114`).
- Error de capacidades con «Reintentar» (`:115-123`).
- Sin permiso «Ver la agenda» (`:125-131`).
- `view` sin entrenador vinculado (`:132-138`).
- Primera carga con esqueleto (`:271-272`).
- Error sin datos (`:273-279`).
- Error de refresco **conservando lo cargado**, con un banner (`:256-262`).
- Truncado: más de 1.000 citas (`:263-268`).
- Vacío, distinguiendo «con filtros» de «sin citas» (`:296-303`, `:322-328`).
- Aviso tipo *toast* de 6 s (`:250-255`, `:863-867`).
- Pista «elige un entrenador» en la semana con «Todos» (`:329-334`).

**Detalle** (`va-detail.ts`):
- Cargando (`:102-106`).
- Error con reintento (`:107-114`).
- Banners ok, warn y error (`:129-134`).
- «Tu cuenta solo puede ver esta cita» (`:187-189`).
- Confirmación en dos pasos para completar o marcar «no asistió» (`:191-206`).
- Historial (`:208-229`).
- Avisos y su estado (`:231-252`).

**Asistente** (`va-booking-wizard.ts`):
- Búsqueda: buscando, error, «al menos 2», sin resultados y N resultados (`:133-139`).
- Sin entrenadores (`:162-167`).
- Aviso «no es el asignado» (`:190-195`).
- Modo pasado (`:221-243`).
- Error con acción de recuperación: otra hora, otro entrenador, otro socio o empezar de nuevo (`:294-308`).
- Éxito con «Agendar otra» o «Ver la cita» (`:79-89`).

**Selector de huecos** (`va-slot-picker.ts`):
- Cargando, error con reintento, «sin horario este día» frente a «no quedan huecos», y chips «Ocupado» (`:79-129`).

### 1.3 Ciclo de vida de una cita (UI → API → regla)

En todas las mutaciones se envía `expected_version`. Un 409 `appointment_changed` pinta la versión actual y explica qué pasó (`va-detail.ts:730-740`). Las reglas de cada transición están en `B:app/Services/Valuations/ValuationAgenda.php:446-452`.

| Acción | Dónde en la UI | Endpoint (`ValuationsService`) | Regla del servidor |
|---|---|---|---|
| **Crear** | Botón «Agendar valoración» (`valuations-tab.ts:162-166`) o clic en un hueco libre de la rejilla (`:830-832`). El asistente envía en `confirmar()` (`va-booking-wizard.ts:655-712`). | `POST /admin/valuations` (`valuations.service.ts:802-804`). Lleva un `client_request_id` idempotente por cada apertura (`va-booking-wizard.ts:422`, `:715-716`). | Duración 10–240 en múltiplos de 5 y notas ≤ 1000 (`B:app/Http/Controllers/Api/Admin/ValuationController.php:185-189`). Con `manage_settings` se puede registrar una cita pasada, con motivo (`va-booking-wizard.ts:255-259`). |
| **Confirmar** | `va-detail` → `pulsar` → `ejecutar` (`:571-620`) | `POST /{uuid}/confirm` (`valuations.service.ts:824-835`) | Solo `scheduled` y antes de la hora. Permiso `complete` (`B:app/Services/Valuations/ValuationPresenter.php:158-160`). |
| **Iniciar** | Igual | `POST /{uuid}/start` | Desde 15 min antes (`ValuationPresenter.php:161-163`). |
| **Completar** | Segundo clic con nota opcional (`va-detail.ts:191-206`, `:595-598`) | `POST /{uuid}/complete` con `note` | Estado activo y `now >= starts_at` (`ValuationPresenter.php:164-166`). |
| **No asistió** | Igual que completar | `POST /{uuid}/no-show` | Estado abierto y `now >= starts_at` (`ValuationPresenter.php:167-169`). |
| **Cancelar** | `ib-reason-dialog`, con motivo de 5 a 500 caracteres (`va-detail.ts:331-345`, `:622-647`) | `POST /{uuid}/cancel` | Estado abierto y permiso `cancel` (`ValuationPresenter.php:170-172`). |
| **Reprogramar** | Modo `reprogramar`: entrenador, duración y `va-slot-picker` con `exclude=uuid` (`va-detail.ts:255-297`, `:649-690`) | `POST /{uuid}/reschedule` | Estado abierto y permiso `update` (`ValuationPresenter.php:173-175`). |
| **Editar tipo y notas** | Modo `notas` (`va-detail.ts:299-324`, `:692-720`) | `PATCH /{uuid}` | Permiso `update` (`ValuationPresenter.php:176-178`). |

- Los botones son exactamente los de `allowed_actions`, en el orden de `ACTION_ORDER` (`va-detail.ts:441-445`; `valuation-labels.ts:116-124`).
- La acción «principal», pintada en dorado, es iniciar, completar o confirmar (`va-detail.ts:448`).
- Después de cada cambio, el detalle emite `cambio`, el calendario relee en silencio (`valuations-tab.ts:854-856`) y el propio detalle relee historial y avisos (`va-detail.ts:723-728`).

### 1.4 Componentes de calendario y disponibilidad reutilizables

**`VaSlotPickerComponent`** (`va-slot-picker.ts:169-349`): la pieza más reutilizable.
- Inputs: `trainerId`, `duracion`, `exclude`, `hoy`, `horizonte`, `diaInicial`, `elegidoInicio` y `horaPreferida`.
- Output: `elegido: PickedSlot | null` (`:173-184`).
- Pide el día de la tira con `/slots`, que es caché por día; un error se reintenta.
- Agrupa en mañana, tarde y noche.
- Lo usan el asistente (`va-booking-wizard.ts:244-253`) y el modo reprogramar del detalle (`va-detail.ts:276-285`).
- **Limitación:** si `diaInicial` cae más allá de `hoy + horizonte`, esos días **no se piden** (`:311`) y la tira queda en «…», sin mensaje ni huecos (`:255-257`, `:79`). Afecta directamente a los accesos rápidos de 60 y 90 días (§6.2).

**`VaTimeGridComponent`** (`va-time-grid.ts:333-347`): rejilla genérica.
- Recibe columnas (día o entrenador), eventos, sombras (`closed` o `busy`) y rango horario.
- Emite `abrir(uuid)` y `agendar({trainerId, dia, hhmm})`.

**`VaMonthViewComponent` y `VaListViewComponent`:** reciben `citas` y `diaDe`, y emiten `abrir`. Sirven para cualquier lista de `ValuationAppointment`.

**Patrón de sombreado desde `/slots`:** `cargarOcupado` (`valuations-tab.ts:745-767`). Lanza un `forkJoin` por cada par entrenador×día, con un tope de 12×7.

**Utilidades** (`calendar-math.ts`):
- `visibleRange`, `queryRange`, `moveCursor`, `longDay`, `bogotaIso`, `daySegments`, `initials`, `monthGrid`, `toMinutes`.
- `sumarDias` y `diasEntre` (`F:modules/components/class-dates.ts:72-77`).
- `diaEnBogota`, `hoyEnBogota` y `diaDeBogota` (`F:modules/marketing-appointments/agenda-time.ts:27-34`, `:124`).

**Servicio** (`F:services/valuations.service.ts`):
- Normalizadores de «confianza cero» (`:391-441`).
- Caché de capacidades por token (`:745-753`).
- Topes: 100 por página, rango de 62 días y 10 páginas (`:367-373`).

---

## 2. Historial de valoraciones del socio en el CRM

### Dónde se ve
**En los dos sitios:**
- **Ficha del socio:** `app-member-valuations [userId]="member.id"` dentro de `app-member-details-modal` (`F:modules/components/member-details-modal.ts:3`, `:49`, `:191-192`).
- **Ficha del entrenador:** `app-trainer-valuations`, solo en el modo `detail` del drawer (`F:modules/components/trainer-modal.ts:351-355`).

### Ficha del socio (`MemberValuationsComponent`, `F:modules/valuations/valuation-histories.ts:179-322`)
- Traduce `users.id` → `members.id` con `GET /admin/valuations/members/by-user/{userId}` (`:278-301`; `valuations.service.ts:861-871`). Un 404 se pinta como «sin ficha de socio».
- Pide `GET /admin/valuations?member_id=…&status=<todos>&per_page=100`, en modo historial y sin rango (`:307-321`).
- Muestra:
  - un bloque con la «Próxima valoración» o «Valoración en curso», con fecha, entrenador, tipo, estado y el enlace «Ver en la agenda» (`:208-218`);
  - tres chips de resumen: completadas, sin asistir y canceladas (`:222-226`);
  - la lista de las pasadas, de 5 en 5, con enlace profundo `/trainers?tab=citas&cita=uuid` (`:227-244`; `params` en `:168-170`).
- Está oculta si la cuenta no tiene `valuations.view` (`:78`), si no tiene alcance (`:107-114`) o si recibe un 403/404 (`:117-125`).
- **No se refresca sola:** se carga una vez por `userId` (`:262-267`).

### Ficha del entrenador (`TrainerValuationsComponent`, `:329-440`)
- Hace dos peticiones de 60 días (futuro y pasado), con `per_page=100` y **solo la primera página** (`:424-439`).
- Muestra un resumen, las próximas y el historial de 60 días.

### ¿Evaluaciones físicas o *assessments*, o solo citas?
**Solo citas** (`ValuationAppointment`). En concreto:
- **Evaluaciones físicas** (peso, medidas, IMC, notas). Están en una pantalla aparte, `/physical-evaluations`:
  - Permiso `members.view` (`F:app.routes.ts:299-303`; menú en `F:app.html:143`).
  - Componente `F:modules/physical-evaluations.ts`; servicio `F:shared/services/physical-evaluation.service.ts`.
  - Endpoints: `GET|POST /admin/members/{member}/physical-evaluations`, `PUT|DELETE /admin/physical-evaluations/{id}` (`B:routes/api.php:1859-1863`).
  - No está enlazada desde la ficha del socio: en `member-details-modal.ts` no aparece «physical».
  - No acepta enlace profundo: la pantalla no lee `ActivatedRoute` y el socio se elige solo buscando.
- **Valoraciones profesionales** (`ProfessionalAssessment`, con el ciclo draft → submitted → amended → voided; `B:app/Models/ProfessionalAssessment.php:11-25`):
  - Solo las consume la app de entrenadores (`trainer/members/{member}/assessments`, con `auth.trainer`) y la app del socio (`B:routes/api.php:284-305`).
  - **El CRM no tiene ninguna vista:** `grep -i "professional\|assessments"` en `F:` no da resultados.
- **Vínculo cita ↔ valoración:** la columna `valuation_appointments.professional_assessment_id` existe con el comentario «Referencia futura… Sin FK; v1 no la rellena» (`B:database/migrations/2026_10_17_200001_create_valuation_agenda_tables.php:128-129`). El *presenter* no la expone (`B:app/Services/Valuations/ValuationPresenter.php:60-101`). Tampoco expone `completed_at`, `started_at` ni `confirmed_at`.

---

## 3. Buscadores de socios existentes

| # | Componente | Endpoint | Permiso | Debounce y mínimo | Mayúsculas | Tildes | Límite | Devuelve |
|---|---|---|---|---|---|---|---|---|
| 1 | Paso 1 del asistente, **en línea** y no extraído (`va-booking-wizard.ts:103-157`, `:506-510`, `:547-575`) | `GET /admin/valuations/members/search?q=` (`valuations.service.ts:853-858`) | **`valuations.create`** (`ValuationController.php:334`) | 300 ms, 2 caracteres, descarta respuestas viejas (`:564`) | Sí: `LOWER(full_name) LIKE` (`ValuationController.php:338`, `:351-352`) | **No** | 20 (`:356`) | `members.id`, nombre, 4 últimos dígitos, membresía y entrenador asignado. Excluye `anonymized_at` (`:350`). Comodines escapados con `!`. |
| 2 | `app-member-picker` (`F:modules/components/member-picker.ts`), usado en inscripción a clase, rutinas y traspaso de membresía | `GET /users?search=` (`api.service.ts:3362-3371`) | `members.view` | 300 ms + `distinctUntilChanged` + `switchMap`; sin mínimo | Sí: `ilike` (`B:app/Http/Controllers/Api/UserController.php:85-95`) | **No** | 25 por página, con «más» (`member-picker.ts:218`) | **`users.id`** (no `members.id`). Busca en nombre, correo, documento y teléfono. Ordena por `created_at desc`, no por relevancia. |
| 3 | `/physical-evaluations` (`physical-evaluations.ts:276-279`) y `nutrition.ts:265` | `GET /admin/physical-evaluations/members?q=` | `members.view` (`B:app/Support/Access/AuthorizationMap.php:178`) | 300 ms con `setTimeout`, sin descartar respuestas viejas; sin mínimo (con `q` vacío devuelve 30) | Sí: `ilike` | **No** | 30 | `members.id` y el **documento completo**. No escapa `%` ni `_`. **No excluye a los anonimizados** (`B:app/Http/Controllers/Api/Admin/PhysicalEvaluationAdminController.php:26-47`). |
| 4 | Comisiones (`F:modules/commissions.ts:2380-2398`) | `GET /admin/commissions/members?q=` | `commissions` | 250 ms, 2 caracteres | Sí: `ilike` | **No** | 25 | `members.id` y documento (`B:.../TrainerCommissionController.php:437-467`) |
| 5 | Informes (`F:modules/reports/sections/members-section.ts:462-469`) | `GET /admin/reports/members/search` | `reports` | `setTimeout`, 2 caracteres | Sí: `ReportSql::likeOperator` | **No** | 20 | `users.id` (`B:app/Services/Reports/MemberInsights.php:313-340`) |
| 6 | Asignar socios a un entrenador (`F:modules/components/trainer-assigned-members.ts:290-300`) | `GET /admin/trainers/{id}/members/search?q=` | `trainers` | Sin debounce (se lanza al buscar) | **[H] NO en PG:** usa `like` sin `LOWER` (`B:.../TrainerAdminController.php:66-75`) | **No** | 15 | Solo socios activos y no asignados |
| 7 | Filtro «Socio» del calendario (`valuations-tab.ts:201-206`, `:470-482`) | **Local**, sin petición | — | Sin debounce, pero sin red | Sí | **Sí:** NFD y quita U+0300–U+036F (`:74-79`) | Solo las citas del rango cargado | Por documento solo encuentra los **4 últimos dígitos** (`document_tail`), aunque el *placeholder* dice «Nombre o documento» |

**¿Cuál reutilizar para «escribir Mari y encontrar»?**
- **El nº 1 (asistente).** Es el único que:
  - devuelve `members.id`, el espacio de ids de la agenda y de las evaluaciones físicas;
  - lleva membresía y entrenador asignado;
  - minimiza datos (4 últimos dígitos, sin teléfono ni correo);
  - excluye a los anonimizados;
  - ya tiene debounce, mínimo de 2 y descarte de respuestas viejas.
- Le faltan tres cosas:
  1. **Extraerlo** a un componente (por ejemplo `va-member-search`). Hoy son unas 50 líneas de plantilla y lógica dentro del asistente.
  2. **Ignorar tildes en el servidor.** Hoy `LOWER(full_name) LIKE '%mari%'` no encuentra «María», porque `í ≠ i` [H: deducido de la consulta; no se ejecutó contra PG]. Es decisión del backend y de `database-specialist`, por ejemplo con la extensión `unaccent` o una columna normalizada con índice `pg_trgm`. Ya existe una migración que instala `pg_trgm` (`B:database/migrations/2026_08_07_000006_add_inbox_search_indexes.php:51`). Como la búsqueda pasa por SQLite en las pruebas, la solución tiene que funcionar en los dos motores (memoria «SQLite no ve lo que PostgreSQL rechaza»).
  3. **Permiso.** Exige `valuations.create`. Para una vista de seguimiento que puedan usar perfiles con solo `view` haría falta relajarlo a «`view` con alcance» o crear un endpoint gemelo de lectura.
- [H] Tampoco se sabe si `LOWER()` pasa a minúsculas «MARÍA» en producción. Depende del `LC_CTYPE` de la base; no se ha comprobado.

---

## 4. SSE en el CRM

### 4.1 Canales que hay

| Servicio o pantalla | Endpoint | Conexión | Reconexión | Deduplicación | Refresco |
|---|---|---|---|---|---|
| `NotificationsService` (bandeja; `F:shared/services/notifications.service.ts`) | `admin/notifications/stream` | Una por pestaña. La abre `startPolling()` desde el `ngOnInit` del *popover*, que vive siempre en el shell (`notifications-popover.component.ts:156-161`; `F:app.html:305-308`). | Con `openStream`, y además el cursor `after_id` si el último evento tiene menos de 120 s (`:127-128`, `:288-300`). El servidor envía `ready` con su cursor (`B:app/Http/Controllers/Crm/NotificationController.php:144-147`) y cierra cada 20 s, sondeando cada 1,5 s (`:149-156`). **No usa `onOpen`** para resincronizar. | **No deduplica por uuid.** Confía en el cursor monotónico. | Agrupa en 200 ms y hace `refresh()` + `refreshUnreadCount()` (`:330-338`). Cada evento se publica en `received$` (`:136-137`, `:315-327`). Sondeo del contador cada 10 s. |
| `MembershipStreamService` (`F:services/membership-stream.service.ts`) | `admin/memberships/stream` | Una, compartida y contada por referencias | `openStream` + cursor `after_id` + `ready`, con `resync`; `onOpen` relee si no hubo `ready` (`:46-71`) | **Sí:** `seen` por `event_id`, hasta 500 (`:28`, `:73-80`) | Agrupa en 350 ms y avisa con `userIds`, `planIds` y `changed`. La ficha del socio lo escucha (`member-details-modal.ts:1308`, `:1334-1340`). |
| `FinancialStreamService` | `admin/earnings/stream` | Una, compartida | `openStream`; con `onOpen` y reconexión emite `reconnect`, que obliga a releerlo todo (`financial-stream.service.ts:76-83`) | No (es una señal, no un dato) | Agrupa en 350 ms |
| `ClassesStreamService` | `admin/classes/stream` | Una, compartida | Cursor y `ready`; `resincronizar` en `onOpen` (`classes-stream.service.ts:310-314`) | **Sí:** `vistos` (`:188`, `:424-429`) | Agrupado, con `classIds` |
| `MarketingAppointmentsService` (agenda comercial) | `admin/marketing/appointments/stream` | Una | `openStream` + `onOpen` + un vigilante que reconecta sin perder la versión (`marketing-appointments.service.ts:342-375`) | Por versión | **Precedente de una agenda con canal propio** |
| `StudioStreamService` | `admin/ultron-studio/stream` | `EventSource` propio, con vale (`studio-stream.service.ts:297-308`) | Propia, con un vector de cursores | Por cursor | — |
| `ModerationRealtimeService` | `admin/moderation/stream` | `EventSource` propio (`moderation-realtime.service.ts:104-122`) | Propia | **Sí:** `seen` (`:52`, `:158-170`) | — |
| **Segunda conexión a `notifications/stream`, abierta por cada pantalla** | `users-list.ts:2053/2234`, `modules/trainers.ts:515/612`, `weekly-streak.ts:532/576`, `face-point.ts:409/705`, `support.ts:432/642`, `attendance.ts:2345/3163` | **Una más por pantalla, sin cursor** | Solo `openStream` | No | Filtran por `action_type` (por ejemplo `trainer_detail` en `trainers.ts:624-632`) |

**Infraestructura común:** `openStream` (`F:services/auth.service.ts:152-220`).
- Pide un vale de un solo uso **en cada conexión** (`:178`; `requestStreamTicket` en `:77-95`).
- Reintenta con espera exponencial de 1 s a 30 s (`:165-166`, `:198-204`).
- `onOpen(esReconexion)` es opcional.
- Si no hay token, **no reintenta y no avisa**: hace `return` sin programar otro intento (`:179`).

### 4.2 ¿Se refresca sola la agenda de valoraciones?

**Parcialmente.** Tiene tres mecanismos (`valuations-tab.ts:608-650`):
1. **Por evento:** `received$` filtrado por `type === 'valuation'`, con debounce de 600 ms, ejecuta `cargar(true)` en silencio y sin parpadeo (`:611-614`). Se recorren todas las páginas del rango y se deduplica por uuid (`:715-728`). Una secuencia descarta las respuestas viejas (`:701`, `:721`, `:737`).
2. **Sondeo** cada 120 s, solo con la pestaña visible y si no está cargando ya (`:63`, `:618-619`, `:769-773`).
3. **Al volver a la pestaña** si han pasado ≥ 120 s (`:622-627`).

Además relee tras cada acción propia (`:854-861`) y cuando cambia el rango o un filtro de servidor (`:640-647`).

**Huecos verificados:**
- **Solo `view_all` recibe eventos.** Los avisos de valoración llevan `required_permission = valuations.view_all` (`B:app/Services/Valuations/ValuationNotifier.php:31`, `:103-113`) y el stream filtra por ese permiso (`B:.../Crm/NotificationController.php:25-31`, `:267-273`). Un entrenador con `view` + su entrenador vinculado **solo se refresca por sondeo**.
- **Iniciar, completar y «no asistió» no emiten aviso.** Solo se emiten `created` (`ValuationAgenda.php:200`), `rescheduled` (`:366`), `cancelled` (`:418`) y `confirmed` (`:488-490`). A eso se suman los recordatorios `internal_upcoming` e `internal_unattended` (`ValuationNotifier.php:71-76`). Editar las notas tampoco emite nada. Esos cambios tardan hasta 120 s en verse en otro puesto.
- **El panel de detalle abierto nunca se refresca por cambios ajenos.** `va-detail` no escucha nada; solo relee tras sus propias acciones o ante un 409.
- **Las secciones de historial de las fichas no se refrescan solas.**
- **[H] Hueco del SSE:** si la desconexión dura más de 120 s, el cursor caduca y lo ocurrido en ese hueco no llega por el canal. El sondeo de 120 s lo cubre.

---

## 5. Avisos administrativos

### 5.1 Cómo llega y se pinta hoy un aviso

**Origen (backend):**
- `ValuationNotifier::admin()` crea una notificación de `audience=admin`, `type=valuation`, `action_type=valuation_detail`, con `action_payload.appointment_uuid`, `required_permission=valuations.view_all` y un `event_key` idempotente (`B:app/Services/Valuations/ValuationNotifier.php:103-123`).
- Prioridad `medium` para los eventos (`:80-95`) y `high` para los recordatorios internos (`:71-76`).
- Los textos están en `B:app/Services/Valuations/ValuationMessages.php:24-84`.

**Bandeja (campanita):**
- `NotificationsPoperoverComponent` tiene la pestaña «Valoraciones», que mapea a `category=valuations` (`notifications-popover.component.ts:153`; `notifications.service.ts:68-69`; backend en `Crm/NotificationController.php:57-59`).
- Usa el icono `event_available` (`:236`) y la marca «Prioridad alta» (`:106`).
- Al hacer clic marca el aviso como leído y navega por `valuationTarget` (`:203-219`).

**Página `/notifications`:** el mismo destino (`F:modules/notifications.ts:364-378`).

**Enlace profundo:** `valuationTarget()` lleva a `/trainers?tab=citas&cita=<uuid>` (`F:modules/valuations/valuation-notifications.ts:20-31`). `TrainersPage` abre la pestaña (`trainers-page.ts:167-170`) y `ValuationsTab` abre el detalle (`valuations-tab.ts:609`).

**Tarjeta flotante con sonido:** `app-valuation-alert` se monta en diferido al iniciar sesión (`F:app.ts:119-126`).
- `ValuationAlertService` solo atiende avisos `type=valuation`, `action_type=valuation_detail`, `priority=high` y `unread` (`valuation-alert.service.ts:247-258`). Es decir, «en 15 min» y «sin iniciar».
- **Es opcional por persona** («Probar sonido») y requiere `view_all` (`:197-199`).
- Deduplicación:
  - por uuid entre los pendientes (`:267`);
  - por uuid en `localStorage` **antes** de sonar (`:288-292`);
  - si el aviso tiene más de 30 min, no suena (`:91`, `:296`).
- Relee los no leídos cada 120 s (`:93`, `:419-434`).
- Suena con WebAudio y frases fijas, a través del coordinador de audio, para que no se pisen dos sonidos.
- Botones: «Ver cita», «Marcar como atendida» (lo marca leído), silenciar y X (oculta sin marcarlo leído) (`valuation-alert.ts:22-50`).

**Alertas generales:** `GeneralAlertService` **excluye** de forma explícita `type=valuation` y `action_type=valuation_detail` (`F:shared/audio/general-alerts.service.ts:198`). Un evento `medium` de valoración (creada, reprogramada…) **no suena en ningún sitio**: solo aparece en la bandeja.

### 5.2 ¿Hay algún aviso «valoración completada»?
**No.**
- `forInternalEvent` solo conoce `created`, `rescheduled`, `cancelled` y `confirmed`; cualquier otro valor lanza una excepción (`ValuationMessages.php:77-83`).
- La transición solo notifica `confirm` (`ValuationAgenda.php:488-490`).
- «Completada» solo queda en el historial de la cita (`HISTORY_LABELS.completed`, `valuation-labels.ts:132`) y en la auditoría.

### 5.3 Qué haría falta para abrir el registro desde el aviso
- **Si «registro» es la cita:**
  - **CRM:** nada nuevo. `valuationTarget` ya abre la cita por uuid.
  - **Backend:**
    - en `ValuationAgenda::transicion`, llamar a `internalEvent(..., 'completed')` cuando `$accion === 'complete'`;
    - añadir el caso `'completed'` en `ValuationMessages::forInternalEvent`;
    - usar prioridad `medium` para que no suene.
  - Con eso, además, la agenda de los `view_all` se refrescaría por evento al completar.
- **Si «registro» es la evaluación física o la valoración profesional:**
  - **Backend:** hoy no hay enlace. Haría falta rellenar `professional_assessment_id` (o un `physical_evaluation_id`), exponerlo en el *presenter* y meterlo en el `action_payload`.
  - **CRM:**
    - `/physical-evaluations` tendría que aceptar `?member=<id>` y cargar el socio por id. El servicio no guarda el `member` que ya devuelve `GET /members/{id}/physical-evaluations` (`physical-evaluation.service.ts:66-72`).
    - Para `ProfessionalAssessment` no existe ninguna vista ni endpoint admin. Sería un trabajo nuevo, no una pieza para reutilizar.
- **Audiencia:** los entrenadores sin `view_all` no reciben ningún aviso de valoraciones. Si deben recibir «completada», cambia la política de `required_permission`, y eso lo deciden el propietario y el backend.

---

## 6. Dónde encajaría (cambio mínimo, reutilizando)

### 6.1 «Programar próxima valoración» tras completar
- **Punto de inserción:** `va-detail`, justo después del banner de éxito (`va-detail.ts:129-134`).
  - Se muestra cuando `cita().status === 'completed'` y `caps().can.create`.
  - Debe verse tanto al terminar `ejecutar('complete')` (`:603-620`) como al abrir más tarde una cita completada.
- **Cambios mínimos:**
  1. **`BookingPreset`** (`va-booking-wizard.ts:41-45`): añadir `socio?: ValuationMemberResult` y `tipo?: string`. En `aplicarPreset` (`:516-525`), si viene socio, rellenar `socio`, poner el tipo `follow_up` y arrancar en el paso 2 o 3.
     - La conversión desde la cita es casi directa: `ValuationMemberRef` (`valuations.service.ts:68-75`) solo **no tiene `assigned_trainer`**. Se puede aproximar con `a.trainer.is_assigned_to_member ? {id, name} : null`.
  2. **`va-detail`:** nuevo output, por ejemplo `programarSiguiente`, con `{socio, trainerId, dia}`.
  3. **`ValuationsTab`:** manejar ese output con `this.asistente.set({...})` (`:346-356`), cerrando antes el detalle o apilándolo. `ib-dialog` admite diálogos apilados (`ib-dialog.ts:35-36`).
- **Gratis:** `client_request_id` idempotente, errores de hueco con «Elegir otra hora» y propuesta del entrenador asignado. El servidor vuelve a validar el horizonte, la antelación y los solapes.

### 6.2 Accesos rápidos 15/20/30/60/90 días y «Personalizar intervalo»
- **Cálculo:** `sumarDias(diaBase, N)` (`class-dates.ts:77`). El día base sale de `diaEnBogota(cita.starts_at)` (`agenda-time.ts:27`); `longDay` sirve para la etiqueta.
- **UI:**
  - Un `fieldset` con radios en forma de píldora, **copiando `.va-wz-type`** (`va-booking-wizard.ts:210-219`, estilos en `:363-371`), que ya es accesible (radio nativo y `:focus-within`).
  - «Personalizar» puede ser un número de días o un `<input type="date" class="va-input">` como el del modo pasado (`:229-231`), con `min=hoy` y `max=hoy+max_horizon_days`. Así no hace falta traer `app-date-wheel-picker`, que usa otro sistema visual.
- **El día elegido va como `preset.dia`** al asistente, que lo pasa a `va-slot-picker` como `diaInicial` (`va-booking-wizard.ts:249`).
- **Obligatorio al hacerlo:**
  - Acotar o avisar cuando `diaBase + N > hoy + max_horizon_days`. Por defecto el horizonte es de 90 días (`valuations.service.ts:521`) y se puede configurar entre 1 y 365 (`va-settings.ts:70`). Si no se hace, el selector queda en blanco (§1.4).
  - Desactivar las píldoras que caigan fuera del horizonte, con la explicación.
- **Decisión pendiente:** ¿el intervalo cuenta desde la fecha de la valoración o desde hoy? Coinciden si se completa el mismo día, pero no cuando se completa tarde o se registra una cita pasada.

### 6.3 Vista de seguimiento por socio

| Dato | ¿Existe hoy? | Fuente |
|---|---|---|
| Última valoración | Sí, en el cliente | `pasadas().find(s => s.status === 'completed')` (`valuation-histories.ts:134-139`). No hay `completed_at`: se usa `starts_at`. |
| Entrenador | Sí | `a.trainer` |
| Estado | Sí | `status`/`status_label` + `statusMeta` |
| Próxima cita | Sí | `proximas()[0]` (`:127-132`), ya pintada en `:208-218` |
| Próximo control sugerido | **No** | No hay dato en el backend: ni un ajuste de intervalo de seguimiento (`ValuationSettings`, `valuations.service.ts:323-339`) ni un campo `next_due`. |

- **Por socio, el cambio mínimo** es extender `MemberValuationsComponent`: un bloque «Seguimiento» con la última completada, el entrenador y el estado, más la próxima cita, que ya se pinta. El «próximo control sugerido» puede salir de dos sitios:
  - (a) una regla en el cliente: última completada + N días, con N de un nuevo ajuste del servidor;
  - (b) un campo que el backend guarde al completar o al usar los accesos rápidos de §6.2.
  - Lo recomendable es (b), para que la app y el CRM digan lo mismo.
- **Ojo con el alcance:** con `view` + entrenador vinculado, el historial del socio solo trae **sus** citas (`ValuationController.php:104`, `:139`). La «próxima cita» podría ser con otro entrenador y no aparecer.
- **Lista de todos los socios** («quién tiene el control vencido»): **necesita un endpoint nuevo**. `GET /admin/valuations` exige `from`/`to` (máximo 62 días) o un `member_id`/`trainer_id` concreto (`ValuationController.php:105-122`). Agregar la última completada de unos 3.900 socios desde el cliente no es viable.
  - Encaje en el CRM: una tercera pestaña en `TRAINERS_TABS` (`trainers-page.ts:34-37`) con su propio cargador en `TRAINERS_TAB_LOADERS` (`:49-55`), que es el patrón ya probado, o una quinta vista en `CALENDAR_VIEWS`.
  - Para pintarla se reutilizan `va-list-view` (o una tabla nueva), `.va-chip` por estado, `va-plate` del entrenador y el enlace profundo `?tab=citas&cita=`.

---

## 7. Identidad visual y componentes compartidos que hay que usar

- **Diálogos:**
  - `ib-dialog` (`F:shared/components/ib-dialog/ib-dialog.ts`). Inputs: `titulo`, `descripcion`, `variant` (`modal` o `drawer`), `size` (`sm`, `md`, `lg`) y `bloqueado` (`:144-151`). Pie con `[ibd-footer]` (`:81`) y foco inicial en `[data-autofocus]` (`:237`). Atrapa el foco, Escape no cierra si hay algo en vuelo y admite diálogos apilados (`:26-37`).
  - `ib-reason-dialog` para motivos (`F:shared/components/reason-dialog/reason-dialog.ts:124-148`), con `validarMotivo` (`F:shared/reason-validation.ts`).
- **Primitivas `.va-*`** (`F:modules/valuations/va-shared.css`). Usan prefijo propio porque las reglas globales con `!important` capturan `.badge`, `.btn-*`, `.card`, `p` y `small` (`:4-5`). Hay que montarlas dentro de `.va-scope`, con `ViewEncapsulation.None` y `styleUrls: ['./va-shared.css']`, como hacen los historiales. Incluyen:
  - botones `.va-btn` con `.is-primary`, `.is-sm`, `.is-icon`, `.is-link`, `.is-danger` e `.is-ghost`;
  - `.va-chip` con `.is-ok`, `.is-warn`, `.is-bad`, `.is-muted` e `.is-none` (`:64-84`), y los tonos de estado `va-st-*`, cada uno con borde e icono propios (`valuation-labels.ts:29-36`);
  - `.va-banner` (`.is-error`, `.is-warn`…), `.va-state`, `.va-skel`;
  - campos `.va-field`, `.va-input`, `.va-select` y `.va-textarea`;
  - `.va-hint`, `.va-err`, `.va-plate` (monograma), `.va-num` (cifras tabulares) y `.va-sr`.
- **Tokens globales** (`/…/Iron-Body_Front/src/styles.css:4-38`): `--ib-bg`, `--ib-surface`, `--ib-panel*`, `--ib-line*`, `--ib-gold` (#f5c518), `--ib-gold-soft`, `--ib-gold-muted`, `--ib-text`, `--ib-muted`, `--ib-soft-text`, `--ib-danger`, `--ib-success`, `--ib-info`, `--ib-warning`, `--ib-gutter` y la escala `--ib-fs-*`.
- **Tipografía e iconos:** Lexend en títulos, Inter en el cuerpo e iconos Material Symbols Outlined. La ficha del socio usa Space Grotesk en sus `h3` (`valuation-histories.ts:188-191`).
- **Vocabulario:** `valuation-labels.ts`, con `STATUS_META`, `TYPE_LABELS`, `ACTION_*`, `HISTORY_LABELS` y `valuationErrorMessage`. Los textos nuevos deberían ir ahí.
- **No conviene tomar como referencia `/physical-evaluations`.** Tiene su propia paleta (`--y: #facc15`, `--bg`…; `physical-evaluations.ts:163-174`), usa `confirm()` nativo para borrar (`:324`) y no comparte primitivas. Si se integra en el seguimiento, que sea con `.va-*`.

---

## 8. Hipótesis y riesgos no verificados en ejecución

- **[H-1] El SSE de notificaciones no se abre tras iniciar sesión en la misma pestaña, y queda muerto tras cerrar sesión y volver a entrar.**
  - **Evidencia:**
    - El *popover* vive siempre en el shell y llama a `startPolling()` **una sola vez**, en `ngOnInit` (`notifications-popover.component.ts:156-161`).
    - Sin token, `startRealtime()` sale sin crear el *handle* (`notifications.service.ts:268-272`). Aun así, `pollHandle` queda puesto y nada vuelve a llamar a `startRealtime()`.
    - El inicio de sesión navega con `router.navigate(['/'])`, sin recargar (`F:auth/login/login.component.ts:67-73`; `auth.service.ts:514-520`). El cierre de sesión hace lo mismo hacia `/login` (`auth.service.ts:312-322`).
    - Tras el cierre de sesión, la reconexión de `openStream` encuentra el token vacío y termina sin programar otro intento (`auth.service.ts:178-179`). El `streamHandle` sigue sin ser nulo, así que el guardia de `startRealtime` impide reabrirlo (`notifications.service.ts:268`).
  - **Impacto:**
    - La agenda solo se refresca por sondeo.
    - `ValuationAlertService` baja a 120 s.
    - **`GeneralAlertService` no suena nunca**, porque solo atiende lo que llega por el canal (`general-alerts.service.ts:254` y su comentario).
    - Las pantallas que abren su propio `notifications/stream` en su `ngOnInit` (§4.1) no se ven afectadas.
  - **Cómo verificarlo:** en DevTools › Network › EventStream, cargar `/login` sin sesión, entrar y comprobar si aparece `admin/notifications/stream`. Repetir con salir y entrar. No hay ninguna prueba que lo cubra (`grep startPolling` en los `*.spec.ts`: ninguna).
  - **Dueño:** `frontend` o `debugger`.
- **[H-2]** «Mari» no encuentra «María» en ningún buscador del servidor. Está deducido de la SQL y no se ha ejecutado contra PostgreSQL.
- **[H-3]** `TrainerAdminController::searchMembers` usa `like` en PostgreSQL, que distingue mayúsculas: «mari» no encuentra «Maria» (`B:.../TrainerAdminController.php:72-75`).
- **[H-4]** Que `LOWER()` pase a minúsculas los caracteres no ASCII depende del `LC_CTYPE` de la base de producción, que no se ha comprobado.

## 9. Hallazgos adyacentes (fuera del alcance; solo se señalan)

1. **`MemberValuationsComponent` etiqueta mal la paginación.** El servidor ordena por `starts_at` **ascendente** (`ValuationController.php:148`), así que la página 1 trae las más antiguas, y el botón «Cargar más antiguas» (`valuation-histories.ts:241-243`) carga en realidad las **más nuevas**. Con más de 100 citas de un mismo socio, la «Próxima» podría faltar. El impacto es bajo por volumen.
2. **`TrainerValuationsComponent` corta en silencio.** Solo lee la primera página de 100 por rango (`valuation-histories.ts:431`); con más de 100 citas en 60 días, el resto no aparece y no avisa.
3. **El filtro local «Socio» no busca por el documento completo.** Solo compara con `document_tail`, los 4 últimos dígitos (`valuations-tab.ts:480`), aunque el *placeholder* promete documento (`:204`).
4. **El buscador de evaluaciones físicas tiene tres problemas** (`PhysicalEvaluationAdminController.php:26-47`):
   - devuelve socios anonimizados;
   - devuelve el documento completo;
   - no escapa los comodines.
   Sin `q` lista 30 socios. Merece revisión de `security`.
5. **Varias pantallas abren su propia conexión a `notifications/stream`**, sin cursor y además de la del `NotificationsService`: `users-list`, `trainers`, `weekly-streak`, `face-point`, `support` y `attendance` (§4.1). Cada una gasta un vale y una conexión.
6. **`modules/trainers.ts` enseña entrenadores falsos si falla el backend** (`:640`, `:916`).
7. **La campanita no se puede usar con teclado.** Los elementos del *popover* son `div (click)` (`notifications-popover.component.ts:91-96`), y la página `/notifications` usa `tr (click)` (`notifications.ts:128-133`).

## 10. Decisiones pendientes del propietario

1. Para los accesos rápidos, ¿desde qué fecha cuenta el intervalo: la de la valoración completada o hoy?
2. ¿«Próximo control sugerido» es un dato del servidor, guardado al completar, o una regla del cliente? Si es una regla, ¿qué intervalo por defecto?
3. ¿Deben recibir el aviso «valoración completada» los entrenadores sin `view_all`? Eso cambia la política de `required_permission`.
4. ¿Qué perfiles deben poder buscar socios en la vista de seguimiento? Hoy la búsqueda de la agenda exige `valuations.create`.
5. ¿El horizonte de 90 días por defecto es compatible con el acceso de 90 días? En el límite justo cabe, pero no si la valoración se completó otro día.

## 11. Qué se verificó y qué no

- **Verificado leyendo el código**, en el commit `ce695d7` del CRM y `8a4b51d1` del backend: todo lo que lleva fichero y línea.
- **No ejecutado:** build, typecheck, lint, pruebas unitarias (vitest) y la app en marcha. Tampoco se lanzó ninguna consulta contra PostgreSQL. Las afirmaciones sobre el comportamiento en ejecución están marcadas [H].
- **Pruebas existentes de interés para `tester`:**
  - `valuations-tab.spec.ts`: el refresco por `type=valuation` en `:339`, el sondeo en `:352` y el enlace profundo en `:384`;
  - `va-detail.spec.ts`: el doble clic para completar en `:162` y el 409 en `:209`;
  - `va-booking-wizard.spec.ts`.
  - Ninguna cubre el SSE tras iniciar sesión (H-1) ni la búsqueda con tildes.
