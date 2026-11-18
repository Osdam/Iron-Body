# Auditoría de la app Flutter: valoraciones, portal del entrenador y seguimiento

Fecha: 18-11-2026. Solo lectura. No se editó nada, no se compiló, no se lanzaron pruebas ni se tocó ningún dispositivo. No hay commits ni parches.

Convención de las etiquetas:
- **[HECHO]**: leído en el código con fichero:línea. En el backend, leído con `git show origin/main:…`.
- **[DEDUCCIÓN]**: se sigue del código de los dos lados, pero no se reprodujo.
- **[HIPÓTESIS]**: plausible, sin evidencia suficiente.

Salvo que se diga lo contrario, las rutas `lib/…` son relativas a la base auditada (§0.2).

---

## 0. Base auditada y método

### 0.1 Checkout principal: NO es lo publicado

- Ruta: `/Users/alejodev/Documents/Dev/IRON_BODY/Iron_Body_App`.
- Rama `fix/ios-tflite-symbols`, HEAD `3a4b4d3` (10-09-2026), `pubspec` en `2.0.4+15`.
- `git status`: 19 ficheros modificados y 13 sin seguimiento. Es WIP ajeno de clases y tiempo real: `classes_screen`, `trainer_class_detail_screen`, `realtime_service`, `trainer_realtime_service`, `Podfile.lock`, `project.pbxproj`… **No se tocó.**
- `3a4b4d3` es ancestro de `origin/main`.
- Comparado con `2812117`:
  - `realtime_service.dart` y `classes_screen.dart` del WIP son idénticos a `main`;
  - `trainer_realtime_service.dart` difiere en 5 líneas;
  - `trainer_class_detail_screen.dart` difiere en 87 líneas.
- **No sirve de base para ningún trabajo nuevo.**

### 0.2 Base auditada (= código de producción)

- Worktree de la release: `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/iron-ia-contexto/Iron_Body_App`.
- Rama `release/2.0.10-ota-iron-ia`, HEAD `2812117` (14-11-2026), versión `2.0.10+31`.
- `git ls-remote origin` confirma que el `main` remoto es `2812117`. No se hizo `fetch`.
- `2812117` solo añade un documento sobre `a58f955`, el código de los parches 17 (iOS) y 18 (Android) en stable.
- Sin commit en ese worktree hay 5 ficheros de configuración: `analysis_options.yaml`, `android/gradle.properties` y 3 de `macos/`. No afectan a lo auditado.

**Qué cambió entre la release `f393184` y `a58f955` (`git diff --stat`):**
- **Sin cambios:**
  - el editor de valoraciones (`trainer_assessment_editor_screen.dart`);
  - `professional_assessment_service.dart` y `member_assessment_service.dart`;
  - la ficha del socio (`trainer_member_detail_screen.dart`);
  - todo `lib/features/progress`;
  - `push_messaging_service.dart`, `api_client.dart` y los dos servicios de tiempo real.
- **Cambiados por parche:**
  - `integral_assessment_screen.dart`, `integral_plan_service.dart`, `follow_up_sheet.dart` e `integral_plan_panel.dart`;
  - `coach_notification_router.dart`, `notifications_screen.dart` e `in_app_push_overlay.dart`.

Los dispositivos en 2.0.9 o anterior llevan código más viejo y nunca reciben parches (memoria `shorebird-ota`).

### 0.3 Stack detectado

- **Versiones:** Dart `sdk: ^3.11.4` (`pubspec.yaml:22`). Flutter `3.47.0` para las releases (`docs/OTA_SHOREBIRD.md:24`; no ejecuté `flutter --version`).
- **Estado:** no hay gestor de estado de paquete. Se usan singletons con `ValueNotifier`/`ChangeNotifier` y `setState` (p. ej. `ProgressService`, `TrainerRealtimeService`, `RealtimeSignals`).
- **Red:** `package:http` a través de `ApiClient.instance`, con un único timeout de 20 s y sin reintentos ni cabeceras extra (`lib/core/network/api_client.dart:53`, `106-136`).
- **Navegación:** Navigator 1.0 (`MaterialPageRoute`) más `ShellNavigation` para las pestañas del socio, con bloqueo por plan (`lib/core/navigation/shell_navigation.dart:13-41`).
- **Arquitectura:** por funciones (`lib/features/<x>/{models,screens,services,widgets}`), sin capas domain/data.
- **Resto:** Firebase Messaging; `shorebird_code_push` 2.0.7 fijado.

### 0.4 Backend consultado (solo lectura)

- Para confirmar contratos que la app sola no puede demostrar, se leyó el monorepo con `git show origin/main:…`.
- Repositorio `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/iron-ia-contexto/Iron-Body`, `origin/main` = `8a4b51d1` (17-11-2026).
- **No verifiqué que producción sirva exactamente ese commit.**
- La copia en `Iron_Body_Crm/Iron-Body` (`main` `70e1b175`, 22-10) está desfasada: no la usé.

### 0.5 Qué NO se ejecutó, y por qué

- **`flutter analyze` y `flutter test`:**
  - la auditoría es de solo lectura;
  - ejecutarlos escribe en `.dart_tool/` y `build/` del worktree de la release, que guarda el archive (memoria `shorebird-ota`);
  - la Mac de 8 GB va justa.
- **Prueba en dispositivo:** prohibida por el encargo.
- **Consecuencia:** nada de lo marcado [DEDUCCIÓN] está reproducido.
- **Pruebas existentes relacionadas, sin ejecutar:** `test/trainer/*` (15 ficheros), `test/progress/*` (6) y `test/notifications/notification_preferences_test.dart`.
  - **No hay ninguna prueba del editor `TrainerAssessmentEditorScreen`** (crear, guardar ni enviar): `grep` sin resultados en `test/`.

---

## 1. Portal del entrenador: crear, editar, guardar y finalizar una valoración

### 1.1 Dos flujos con nombre parecido [HECHO]

| | Valoración profesional | «Valoración Integral» |
|---|---|---|
| Qué es | Registro clínico versionado (`ProfessionalAssessment`) | Pantalla contenedora + plan de Iron IA |
| Estados | `draft` → `submitted` → (`amended` al corregir) / `voided` (`lib/features/trainer/models/professional_assessment.dart:6`) | `idle`/`generating`/`draft`/`published`/`failed` del **plan**, no de la valoración (`lib/features/trainer/models/integral_plan.dart:12-27`) |
| Pantalla | `TrainerAssessmentEditorScreen` | `IntegralAssessmentScreen` |
| Endpoint | `/trainer/members/{id}/assessments`, `/trainer/assessments/{uuid}[/submit|/amend]` | `/trainer/members/{id}/integral-plan/generate`, `/trainer/routines/{id}/…` |

### 1.2 Cómo se llega [HECHO]

1. **Inicio del entrenador** (`trainer_home_screen.dart`): lista de socios asignados → `TrainerMemberDetailScreen`.
2. **La ficha** (`trainer_member_detail_screen.dart`) ofrece:
   - el botón flotante «Nueva valoración» si el entrenador tiene `assessments.create` (`:227-238`);
   - la tarjeta «Valoración Integral» → `IntegralAssessmentScreen` (`:311-378`). El comentario de `:263-267` dice que es «UNA sola entrada profesional».
3. **Dentro de la Integral**, la tarjeta «Valoración y seguimiento»:
   - «Registrar primera/nueva valoración» llama a `onNewAssessment`, que es el mismo `_openEditor(create)` de la ficha (`integral_assessment_screen.dart:955-959`, `trainer_member_detail_screen.dart:367`);
   - «Ver seguimiento» abre `FollowUpSheet` (`integral_assessment_screen.dart:961-965`, `972-1000`).
4. **Una fila del seguimiento:**
   - un `draft` abre el editor en modo `editDraft`;
   - cualquier otro estado abre `AssessmentDetailScreen`, que es de solo lectura (`trainer_member_detail_screen.dart:198-207`).

### 1.3 Borrador frente a final [HECHO]

- Existe la diferencia. El editor tiene dos botones: «Guardar borrador» y «Enviar al miembro» (`trainer_assessment_editor_screen.dart:205-233`).
- **Guardar borrador** (`:138-154`):
  - si no hay `_draftUuid`, hace `POST /trainer/members/{id}/assessments`;
  - si lo hay, hace `PUT /trainer/assessments/{uuid}`;
  - muestra «Borrador guardado.» y se queda en el editor.
- **Enviar al miembro** (`:156-177`), en modo crear o editar borrador, hace tres llamadas en serie:
  1. `createDraft` si aún no hay uuid;
  2. `updateDraft` con el mismo payload (redundante si se acaba de crear);
  3. `POST /trainer/assessments/{uuid}/submit` con `{}`.

  Después cierra con `pop(true)`.
- **Al enviar, el backend** (`ProfessionalAssessmentService::submit`, `origin/main`, líneas 72-106):
  - deja la valoración inmutable;
  - notifica al socio;
  - audita y emite la señal en tiempo real;
  - y, **fuera de la transacción y sin garantía**, vuelca las medidas a `physical_evaluations` (`syncToPhysicalEvaluation`, 200-248). Solo crea fila si hay al menos una medida.
- **Corregir (`amend`):** `POST /trainer/assessments/{uuid}/amend` con el payload más `amendment_reason` (`trainer_assessment_editor_screen.dart:157-166`). Ver 1.6-H3: hoy no se llega desde ninguna pantalla.

### 1.4 Payload [HECHO]

`_payload()` (`trainer_assessment_editor_screen.dart:102-118`):
- **Medidas:** `weight_kg`, `height_cm`, `body_fat_pct`, `muscle_mass_pct`, `waist_cm`, `hip_cm`, `chest_cm`, `arm_cm`, `leg_cm` (`:41-51`), como `double`.
  - La coma se convierte en punto.
  - **Se omiten los campos vacíos y los que no se pueden leer**: un «7.2.5» desaparece sin aviso, porque el filtro admite `[0-9.,]` (`:441-443`).
- **Texto:** `observations` y `recommendations`, solo si no están vacíos.
- **Fecha de la valoración, de la próxima o intervalo:** no existen.
- Validación del backend: `StoreProfessionalAssessmentRequest`, con todo `nullable` (`origin/main`).

### 1.5 Idempotencia y doble toque

- **[HECHO] No hay clave de idempotencia en ningún punto del flujo de valoraciones.**
  - `ApiClient` solo envía `Content-Type`, `Accept`, `ngrok-skip-browser-warning` y `Authorization` (`api_client.dart:117-122`, `149-154`).
  - `grep -i idempot` en `lib/` solo aparece en entrenos (`client_session_id`, `features/workouts/models/workout_session_draft.dart:27-31`, `features/workouts/services/workout_session_service.dart:131`), en Wompi (`client_request_id`, `features/payments/services/wompi_payment_service.dart:66`) y en el quiosco de personal.
- **[HECHO] Doble toque:** el botón se desactiva con `_busy` (`trainer_assessment_editor_screen.dart:208`, `231`), y `_run` pone `_busy = true` antes del primer `await` (`:120-124`).
  - `_run` no tiene una guarda propia de reentrada (`if (_busy) return;`), a diferencia de `_generate` de la Integral (`integral_assessment_screen.dart:149`).
  - El riesgo práctico por toque es bajo; no hay prueba que lo cubra.
- **[HECHO] Reintento de red:** el cliente no reintenta solo. Corta a 20 s y lanza `ApiException('Tiempo de espera agotado…')` (`api_client.dart:127-129`).
- **[DEDUCCIÓN] Respuesta perdida en `createDraft`:**
  - si el servidor creó el borrador pero la respuesta no llega (timeout o red), `_draftUuid` sigue en null (`trainer_assessment_editor_screen.dart:145`, `168-172`);
  - el siguiente toque crea **otro borrador** para el mismo socio, porque el backend crea sin deduplicar (`ProfessionalAssessmentService::createDraft`, 35-55).
- **[DEDUCCIÓN] Respuesta perdida en `submit`** (el servidor sí lo confirmó):
  - el reintento hace primero `updateDraft` y recibe **409**: «Esta valoración ya fue enviada y no se puede editar. Crea una corrección.» (`AssessmentException.php:19-21`);
  - el entrenador ve un error por algo que salió bien, el editor no se cierra y la «corrección» no existe en la UI (H3).
- **[HECHO] El backend es idempotente por estado:** solo un `draft` se envía (`submit` 74-76) y responde 409 a un segundo envío. Eso evita la doble notificación, pero no el error confuso.

### 1.6 Hallazgos del flujo del entrenador

- **H3 [HECHO] No se puede corregir una valoración enviada desde la app.**
  - `AssessmentEditorMode.amend` no se instancia en ningún sitio de `lib/`: solo `create` y `editDraft` (`trainer_member_detail_screen.dart:200`, `231`, `367`).
  - El acceso «Corregir» (`canAmend` / `onAmend`) se quitó en `8e8a437` (09-09-2026, «valoración y seguimiento del socio»); se ve en `git show 8e8a437`.
  - La ruta del backend y el permiso `assessments.amend` siguen vivos (`routes/api.php:297`).
  - `AssessmentDetailScreen` no ofrece ningún control (`assessment_detail_screen.dart:8-10`).

- **H5 [DEDUCCIÓN] Vaciar un campo de un borrador no lo borra en el servidor.**
  - La app omite los vacíos (`trainer_assessment_editor_screen.dart:104-116`).
  - El backend hace `fill(Arr::only($data, CONTENT_FIELDS))` con `$request->validated()`, que solo trae las claves presentes (`ProfessionalAssessmentService.php:63`, `ProfessionalAssessmentController::update`).
  - Resultado: un peso mal tecleado que se borra sigue en el borrador, se envía y acaba en `physical_evaluations`.

- **H7 [DEDUCCIÓN] Un borrador guardado no aparece hasta recargar a mano.**
  - «Guardar borrador» no cierra el editor; al salir con «atrás», `saved` es `null` y la ficha no recarga (`trainer_member_detail_screen.dart:195`).
  - `createDraft` no emite señal en tiempo real; solo `submit` llama a `TrainerRealtimeEvents::assessmentForMember` (backend 92-94).
  - Riesgo: el entrenador pulsa otra vez «Nueva valoración» y deja borradores duplicados.

- **H6 [DEDUCCIÓN] La Valoración Integral se queda con los datos de cuando se abrió.**
  - Recibe `memberContext: perfil.context` y `assessments: _items` al hacer el push (`trainer_member_detail_screen.dart:355-367`). `_contexto` es `late final` sobre ese contexto (`integral_assessment_screen.dart:83`).
  - `_onRealtime` solo recarga guías y rutinas, no las valoraciones (`:113-117`).
  - **Efecto:** con un socio sin valoración previa, el entrenador:
    1. pulsa «Registrar primera valoración»;
    2. la envía;
    3. vuelve a la Integral.

    Ahí sigue viendo «Aún no hay una valoración registrada» y «Falta por confirmar: Peso», y «Generar plan» se bloquea en cliente por `missingFor` (`plan_context.dart:83-91`, `integral_assessment_screen.dart:151-160`). Hay que salir a la ficha y volver a entrar.
  - La pestaña «Valoraciones (N)» del seguimiento tampoco incluye la nueva.

- **H9 [HECHO] Detalles de presentación:**
  - el seguimiento enseña el estado en crudo, en inglés (`estado: a.status`: `submitted`, `draft`, `amended`), en `follow_up_sheet.dart:128`;
  - el título «Valoración v${version}» se repite como «v1» en todas (`:126`);
  - `AssessmentDetailScreen` no muestra la fecha de la valoración (`assessment_detail_screen.dart:28-119`; `submittedAt` no se usa).

- **H10 [HECHO] Dos «Última valoración» con fuentes distintas en el mismo flujo:**
  - **Ficha** (`trainer_member_detail_screen.dart:473-478`): `last_assessment` solo de **este** entrenador, `submitted`/`amended` (`TrainerMembersController.php:82-87`).
  - **Integral** (`integral_assessment_screen.dart:912`, `941-943`): `context.sources.assessment_at`, la última valoración **visible al socio de cualquier entrenador** (`NutritionGuideService::lastAssessmentFor` 286-294, `MemberContextAssembler::forAssessment` 70/90).
  - El historial de la ficha también se filtra por entrenador (`ProfessionalAssessmentController::index` 33-39).
  - Las evaluaciones físicas creadas desde el CRM (`admin/members/{member}/physical-evaluations`) no cuentan en ninguna de las dos.

- **H13 [HECHO] Código muerto con trampa de OTA.**
  - `IntegralAssessmentForm` e `IntegralAssessmentData` (`lib/features/trainer/widgets/integral_assessment_form.dart`) solo se usan en `test/trainer/integral_assessment_test.dart`.
  - Usan iconos que **no** están en la fuente de la release (`test/ota/iconos_sin_glifo_en_release.txt`: `Icons.edit_note`, `Icons.health_and_safety_outlined`, `Icons.straighten`).
  - Si un parche lo hace accesible, Shorebird lo rechaza («asset changes»).

---

## 2. Iron IA: ¿guardar una valoración genera un entrenamiento?

**Respuesta: no.**

- **[HECHO] Enviar una valoración no llama a Iron IA en la app.**
  - El editor solo hace create, update y submit, y luego `pop(true)` (`trainer_assessment_editor_screen.dart:156-177`).
  - La ficha solo recarga (`trainer_member_detail_screen.dart:195`).
- **[HECHO] Ni en el backend.**
  - `submit` notifica, audita, emite la señal y sincroniza `physical_evaluations` (72-106).
  - No hay observadores, listeners ni jobs sobre `ProfessionalAssessment` en `app/Observers`, `app/Listeners`, `app/Jobs` ni `app/Providers` (`git grep` vacío).

### 2.1 Orden real en la Valoración Integral [HECHO]

Es manual y en pasos separados; el orden de la pantalla está en `integral_assessment_screen.dart:707-739`.

1. «Tu información», precargada del servidor.
2. «Valoración y seguimiento», donde se registra la valoración con el editor de §1.
3. «Contexto para el plan» (`PlanContextForm`).
4. Botón «Generar plan con Iron IA» (`integral_plan_panel.dart:69-72`), que hace `POST /trainer/members/{id}/integral-plan/generate`.
   - El cuerpo es `PlanContext.toJson()`: `objective`, `training_level`, `days_per_week`, `session_minutes`, `injuries`, `restrictions`, `observations`, solo los que no estén vacíos (`plan_context.dart:97-105`, `integral_plan_service.dart:44-58`).
   - Exige en el cliente peso conocido, objetivo y nivel (`plan_context.dart:83-91`).
5. Respuesta: dos **borradores**, guía y rutina (`integral_plan.dart:103-119`). «GENERAR NO ES PUBLICAR» (`integral_assessment_screen.dart:37-38`; panel `:132-137`).
6. Publicar se hace aparte:
   - rutina: `POST /trainer/routines/{id}/publish` (`integral_plan_service.dart:162-172`), o con confirmación si es una propuesta de recuperación (`:181-195`);
   - guía: `_guides.publish` (`integral_assessment_screen.dart:627-653`).
7. «Regenerar» vuelve a llamar a `generate`; no borra lo anterior (`integral_plan_panel.dart:188-199`).

### 2.2 ¿Se puede confundir generar con «valoración terminada»?

- **[HECHO] Hay base para la confusión:**
  - la pantalla se titula «Valoración Integral» (`integral_assessment_screen.dart:682`);
  - el panel dice «Con los datos de esta valoración, Iron IA prepara…» (`integral_plan_panel.dart:63-66`);
  - y aun así generar **no crea, edita ni envía** ninguna `ProfessionalAssessment`.
- **[HECHO] Se puede generar sin una valoración nueva.** Basta un peso de cualquier valoración anterior, aunque tenga meses. El único indicio de antigüedad es «De la valoración del dd/mm» (`integral_assessment_screen.dart:808-814`).
- **[HECHO] El enlace con la valoración queda en el backend:** guía y rutina se guardan con `source_assessment_id` (`IntegralPlanController.php:79`, `345`). La app lo lee (`integral_plan.dart:117`) pero no lo muestra.
- **[HECHO] Para la agenda:** `valuation_appointments` tiene `professional_assessment_id` (migración `2026_10_17_200001…:129`, `ValuationAppointment.php:82`).
- **[HIPÓTESIS]** Si «completar cita» se apoya en algo distinto de una valoración **enviada**, por ejemplo en un plan generado o publicado, o en una evaluación física cualquiera, se podrían dar por hechas citas sin medición nueva.
  - Recomendación: completar solo con `professional_assessment_id` de una valoración `submitted`.

### 2.3 Doble toque y reintento al generar

- **[HECHO] Protegido contra el doble toque:** `_busy` se comprueba al entrar (`integral_assessment_screen.dart:149`) y hay prueba (`test/trainer/integral_reachability_test.dart:359`, «dos toques seguidos NO piden dos planes»).
- **[DEDUCCIÓN] Pero no contra el reintento tras un timeout:**
  - `generate` usa `ApiClient`, que corta a 20 s;
  - el nivel 1 tarda 11–16 s (memoria `iron-ia-contexto-entrenador`; el timeout no se tocó por decisión del propietario);
  - un timeout acaba en «El servidor no pudo completar la operación. Tiempo de espera agotado…», porque `_clasificar` decide por subcadenas del mensaje (`:206-215`);
  - el reintento puede generar y cobrar **dos** planes si el primero terminó en el servidor. Los dos quedarían como borradores en el seguimiento.
  - Solo la regeneración de recuperación tiene un plazo de 100 s y un código `client_timeout` (`integral_plan_service.dart:31-34`, `244-249`).

---

## 3. Seguimiento de socios por el entrenador

| Pantalla | Qué muestra | Fuente |
|---|---|---|
| Inicio (`trainer_home_screen.dart`) | Lista de asignados (nombre, foto, objetivo, nivel); indicador por socio y total «Valoraciones por revisar» (`:231-232`, `296-297`) | `GET /trainer/auth/bootstrap` y, con `members.view_assigned`, `GET /trainer/members` (`:90-92` del `_load`, `professional_assessment_service.dart:15-21`) |
| Ficha (`trainer_member_detail_screen.dart`) | Perfil, lesiones, teléfono, «Última valoración: X kg · fecha» (`:473-478`, solo de este entrenador); en la tarjeta Integral, «N valoración(es)» y «Nutrición publicada» (`:329-345`) | `GET /trainer/members/{id}` y `GET /trainer/members/{id}/assessments` en paralelo (`:93-99`); guías con `GET /trainer/members/{id}/nutrition-guides`, que se ocultan si dan 404/403 (`:129-141`) |
| Valoración Integral, «Valoración y seguimiento» | «Última valoración: dd/mm» o «Aún no hay…» (`:939-949`); botón para registrar | `context.sources.assessment_at` del perfil (ver H10) |
| Seguimiento (`follow_up_sheet.dart`) | Tres pestañas: Valoraciones (todas las de este entrenador, borradores incluidos), Nutrición, Rutinas (activa, borrador, retirada; menú publicar, descartar o retirar) | Lista de la ficha (instantánea, H6); `nutrition-guides`; `GET /trainer/members/{id}/routines` (`integral_plan_service.dart:138-145`) |

- **[HECHO] «Próxima valoración» no existe** en ningún modelo, pantalla ni payload.
  - `grep -iE "next_|pr[oó]xima valoraci|interval|valuation|appointment"` sobre `lib/` solo encuentra textos de clases y la animación `agenda.json`.
- **[HECHO] El indicador «Valoraciones por revisar» engaña.** `pending_ack` cuenta las valoraciones `submitted` de este entrenador que el **socio** no ha abierto (`TrainerMembersController.php:41-48`). No es trabajo pendiente del entrenador.
- **[DEDUCCIÓN] Ese contador tiende a crecer.** El socio solo marca como leída desde la bandeja de notificaciones (§5, §6).

---

## 4. Agendamiento y citas en la app

**[HECHO] No existe nada de citas de valoración, ni para el socio ni para el entrenador.**

- No hay modelo, servicio, pantalla, ruta, tipo de evento SSE ni tipo de notificación que lo trate:
  - `notifications_screen.dart:327-394` no tiene un caso `valuation`;
  - `in_app_push_overlay.dart:125-147` lo manda a la bandeja por defecto;
  - `realtime_service.dart` (despacho a partir de la línea 163 del fichero) no tiene un tipo `valuation.*`, que caería en `AppSyncService.forceRefresh()`.
- Lo único de «agenda» en la app son **clases**:
  - socio: `/app/classes/weekly`, reservar y cancelar;
  - entrenador: «Mis clases — Agenda y asistencia de hoy» (`trainer_home_screen.dart`, bloque de accesos).
- **No hay bandera en la app porque no hay funcionalidad.**
- **[HECHO] En el backend, la agenda es solo del CRM:** `admin/valuations/*` (`routes/api.php:781` y siguientes).
  - Expone `slots`, `availability`, `store`, `reschedule`, `cancel`, `confirm`, `start`, `complete` y `no-show`.
  - No hay rutas `member/…` ni `trainer/…` de citas.
  - `valuation_settings.member_app_enabled` nace en `false` (`ValuationSetting.php:40`; migración `200001…:52`).
- **El socio no ve sus citas de valoración en la app.**
- **[HIPÓTESIS]** Si el backend empezara a mandar avisos de cita al socio (`member_app_enabled=true`), llegarían a la bandeja como una notificación genérica, sin botón de acción. Al tocar el popup se abriría la bandeja (`in_app_push_overlay.dart:145-146`). No rompería nada, pero tampoco llevaría a ningún sitio útil.

---

## 5. Historial de valoraciones del socio

Hay dos registros distintos.

### 5.1 Evaluaciones físicas: sí se listan todas [HECHO]

- **Pantalla:** Progreso → «Evaluación».
  - Accesos: `progress_screen.dart:113-117` y `67-74`, Perfil → «Evaluación física» (`profile_screen.dart:128-137`), Iron IA y `/evaluation` (`coach_notification_router.dart:36-42`).
  - Todos abren `PhysicalEvaluationScreen` con la última (`GET /app/physical-evaluations/latest`, `physical_evaluation_service.dart:24-31`).
- **Solo lectura para el socio:** `_memberReadOnly = true`, con el texto «La evaluación física profesional es realizada por tu entrenador» (`physical_evaluation_screen.dart:62-65`, `290-291`, `391`, `406`).
- **Historial:** el botón «Historial» (`:379`) y el acceso desde Progreso (`progress_screen.dart:144-145`) abren `EvaluationHistorySheet`.
  - Hace `GET /app/physical-evaluations`, todas y en orden descendente (`physical_evaluation_service.dart:33-46`).
  - Al tocar una fila se abre **esa** evaluación: `PhysicalEvaluationScreen(evaluation: e)`, con el título «Valoración del dd/mm» (`evaluation_history_sheet.dart:130-136`, `physical_evaluation_screen.dart:372`).
  - **Sí se puede ver una anterior.** Hay pruebas: `test/progress/evaluation_history_tap_test.dart` y `evaluation_detail_test.dart`.
- **[HECHO, backend] Qué entra ahí:**
  - cada valoración profesional **enviada** que traiga al menos una medida, con observaciones y recomendaciones en `trainer_notes` («Notas clínicas», `physical_evaluation_screen.dart:494`);
  - las correcciones solo si cambian alguna medida (`ProfessionalAssessmentService.php:159-161`, `200-248`);
  - **una valoración solo cualitativa no aparece en Progreso.**

### 5.2 Valoraciones profesionales con su documento: solo una a una, desde la notificación

- **[HECHO]** `MemberAssessmentsScreen` («Mis valoraciones», `GET /member/assessments`) **no tiene ninguna entrada en `lib/`**:
  - solo la instancian las pruebas (`test/trainer/trainer_slice_test.dart:122-143`);
  - nunca tuvo una entrada en producción (`git log -S` solo la encuentra en `ffca472`, que la creó).
- **[HECHO]** El socio solo ve una valoración profesional por esta vía:
  1. bandeja de Notificaciones;
  2. detalle;
  3. «Ver valoración»;
  4. `AssessmentDeepLinkScreen(uuid)`, que hace `GET /member/assessments/{uuid}` y `POST …/ack`, y luego `AssessmentDetailScreen` (`notifications_screen.dart:381-389`, `assessment_deep_link_screen.dart:40-55`).
- Si borra o pierde la notificación, no hay otra forma de abrirla.
- `AssessmentDetailScreen` no enseña la fecha (H9).

---

## 6. Estado y tiempo real

### 6.1 Socio

- **[HECHO] SSE `GET /member/realtime`**, con cursor `id` y reconexión (`realtime_service.dart:16-29`, `76`…).
  - Se arranca y se para con el ciclo de vida del shell: `start` en `resumed`, `stop` en `paused`/`inactive` (`app_shell.dart:196-224`).
- **[HECHO] Estado de la app:** `GET /member/app-state` vía `AppSyncService.forceRefresh()`.
  - Hay un sondeo de respaldo cada 60 s solo mientras el SSE está caído (`features/auth/services/app_sync_service.dart:33`, `43-53`, `123-131`).
- **[HECHO] Despacho del SSE:**
  - `notification.created` → contador, revisión de la bandeja y `InAppPushController.checkNow()`;
  - `routine.*`, `class.*`, `nutrition.*` → `RealtimeSignals`;
  - el resto → `AppSyncService.forceRefresh()`.
- **[HECHO] Progreso NO escucha el SSE.** Se refresca:
  - al mostrar su pestaña (`ProgressTabVisibility`, `app_shell.dart:87`);
  - al volver del segundo plano;
  - y al volver de `PhysicalEvaluationScreen` (`progress_screen.dart:40-74`).
  
  Una valoración enviada con Progreso a la vista no aparece hasta cambiar de pestaña o salir y volver.

### 6.2 Entrenador

- **[HECHO] SSE `GET /trainer/auth/realtime`** (`trainer_realtime_service.dart:74-123`).
  - Lo arranca el `initState` del inicio del entrenador y lo para su `dispose` o el cierre de sesión (`trainer_home_screen.dart:57-66`, `131`).
  - Los eventos `assessment*` incrementan `assessmentsRevision` (`:155-157`), que escuchan:
    - el inicio (`trainer_home_screen.dart:59`);
    - la ficha (recarga entera, `trainer_member_detail_screen.dart:74-79`);
    - la Integral, que solo recarga guías y rutinas (H6).
- **[HECHO] No mira el ciclo de vida de la app:** no hay `didChangeAppLifecycleState` en `features/trainer`.
- **[HIPÓTESIS]** En Android sigue reconectando en segundo plano hasta que el sistema mata el proceso.

### 6.3 Notificaciones al tocarlas

- **[HECHO] Push del sistema** (segundo plano o app cerrada):
  - `_onOpened` solo navega si `data['action_route']` existe (`push_messaging_service.dart:308-313`);
  - `CoachNotificationRouter` reconoce `/iron-ai`, `/membership`, `/progress`, `/nutrition`, `/evaluation`, `/workouts` y `/classes`, pero **no `/assessment`** (`coach_notification_router.dart:19-60`).
- **[HECHO, backend] El `data` del push** (`FcmService.php:120-126`) lleva `uuid`, `type`, `category`, `action_type` y `priority`; **no lleva `action_route`.**
  - Al tocar el push, la app se abre donde estaba y no navega a ningún sitio.
- **[HECHO, backend] El push de valoración probablemente ni sale:**
  - `notifyMember` (`ProfessionalAssessmentService.php:266-285`) no fija `should_popup`, cuyo valor por defecto es `false` (migración `2026_05_25_000002…:18`);
  - `maybePush` no envía si `fcm.only_popup` (por defecto `true`, `config/fcm.php:40`) y `!should_popup` (`NotificationService.php:1836-1838`).
  - **No verifiqué el valor de `FCM_ONLY_POPUP` en producción.**
  - Por el mismo motivo, tampoco hay popup interno, que lee `popup-pending`.
- **[HECHO] Push en primer plano:** sale el óvalo con `actionRoute` nulo (`push_messaging_service.dart:284-306`).
- **[HECHO] Popup interno al tocarlo:** `class`, `routine` y `trainer` abren su pestaña; `payment`, `membership`, `iron_ai`, `nutrition` y `security` su pantalla; el resto, la bandeja (`in_app_push_overlay.dart:104-147`).
- **[HECHO] Bandeja al tocar:** marca como leída y abre el detalle con una acción por tipo (`notifications_screen.dart:288-326`). `professional_assessment` y `professional_assessment_amended` tienen «Ver valoración», como se describe en §5.2.

---

## 7. Para una implementación futura

### 7.1 Qué cabe en un parche de Shorebird (solo Dart)

Reglas: `docs/OTA_SHOREBIRD.md:93-104` y `207-216`, más la guarda de iconos `test/ota/release_icons_test.dart`.

**Compatible:**
- Pantallas, modelos y servicios nuevos o cambiados que usen `http`/`ApiClient`, `ValueNotifier`, `showDatePicker`/`showTimePicker` de Material e `intl` (ya está).
- Paquetes **puramente Dart** que no traigan assets: aceptables en teoría, pero no hacen falta.
- Iconos **solo** de los 330 glifos de la release (`test/ota/iconos_release.txt`). Para calendario ya están:
  - `calendar_month_rounded`, `calendar_today_rounded`, `event_rounded`, `event_available_*`, `event_busy_*`, `event_note_rounded`;
  - `schedule_rounded`, `today_rounded`, `date_range_rounded`, `access_time_rounded`, `history_rounded`, `repeat_rounded`, `notifications_*`, `assignment_*`.
- Assets que ya trae la release: `assets/animations/agenda.json` está en `f393184` (`AppAssets.lottieAgenda`).
- Arreglos concretos de esta auditoría, todos en Dart:
  - dar entrada a `MemberAssessmentsScreen`, cuyos iconos están en la release (comprobado: `assignment_outlined`, `chevron_right_rounded`…);
  - recuperar «Corregir» (modo `amend`);
  - enviar vacíos como `null` (H5);
  - recargar la Integral tras registrar (H6);
  - una clave de idempotencia en el cuerpo (copiar el patrón de `client_session_id`);
  - tratar `/assessment` y una ruta futura `/valuation` en `CoachNotificationRouter` y en `_actionFor`/`_screenFor`;
  - traducir los estados (H9);
  - mostrar «Próxima valoración» en Progreso, la ficha y la Integral a partir de un campo del servidor.
- Reutilizar los canales de Android existentes (`iron_body_high`, `iron_body_classes`, `iron_body_general`). El backend elige el canal (`PushChannel`).

**Condiciones para cualquier parche:**
- Partir del código de la release, rama `release/2.0.10-ota-iron-ia` (último `a58f955`/`2812117`); **nunca** del checkout principal.
- Misma versión de Flutter (3.47.0).
- Dos parches (Android e iOS), primero en staging y con prueba física; a stable solo con autorización expresa.
- Firma con la clave privada de `SECURE_KEYS`.
- **Backend antes que el parche**, y la app tolerante al 404 del backend viejo, como ya hace con las guías (`trainer_member_detail_screen.dart:124-141`).
- **Alcance:** solo los dispositivos en 2.0.10+31. Según la memoria del 12–14-11, sin verificar hoy, los entrenadores 6 y 7 y la tablet seguían en 2.0.9, y «los entrenadores usan sobre todo la tablet». **Sin la tablet en 2.0.10, el portal del entrenador no recibe nada por OTA.**

### 7.2 Qué exigiría publicar en tiendas

- **Recordatorios locales o programados en el dispositivo:** `flutter_local_notifications` o equivalente, con plugin nativo, permisos y, en iOS, la configuración de notificaciones.
- **Añadir la cita al calendario del teléfono:** plugins de calendario nativos, permisos de calendario y `NSCalendarsUsageDescription`.
- **Un canal de Android propio de «Valoraciones»:** los canales se crean en Kotlin, en `android/app/src/main/kotlin/com/ironbodyneiva/app/IronBodyApplication.kt:35-46`.
- **Deep links o enlaces universales** (por ejemplo `/valuation/{uuid}` desde WhatsApp): `AndroidManifest`/`Info.plist` y `assetlinks`/AASA en el dominio.
- **Sonidos, Lottie o imágenes nuevos**, o iconos de Material que no estén en la fuente recortada.
- **Subir la versión de Flutter o del motor.**
- **Cualquier cosa que deban recibir los dispositivos en 2.0.9 o anterior.**

### 7.3 Dónde encaja «programar próxima valoración» sin duplicar trabajo

**[Recomendación, no implementada]**

1. **Se captura en un único momento: al enviar la valoración profesional.**
   - Es cuando el entrenador acaba de medir.
   - En el editor (`trainer_assessment_editor_screen.dart`) iría un bloque opcional «Próxima valoración»: en 4/6/8/12 semanas o en una fecha, junto a «Enviar al miembro».
   - Viajaría en el mismo `submit`, o en una llamada idempotente inmediatamente después.
   - **No** en la Integral ni al generar el plan: generar no es valorar (§2.2) y eso duplicaría el dato.
2. **Contrato que habría que pedir al backend antes de escribir el parsing** (coordinación con `backend`; la agenda es de su dominio):
   - **Opción A, mínima:** `submit` acepta `next_valuation: {interval_weeks | due_date}` y el backend crea en `valuation_appointments` una **solicitud sin hora** («pendiente de agendar»).
     - La recepción la convierte en cita con la disponibilidad real (slots, solapes y EXCLUDE ya están en el CRM).
     - El entrenador no ve ni gestiona huecos en la app.
   - **Opción B, completa:** rutas `trainer/valuations/slots|store|reschedule|cancel`.
     - Duplica en la app la lógica de disponibilidad que ya resuelve el CRM.
     - Requiere un modelo de permisos para el entrenador.
     - **No la recomiendo** como primer paso.
   - **En los dos casos:**
     - la cita queda enlazada a `professional_assessment_id` al completarse;
     - una clave de idempotencia en el cuerpo, porque hoy no hay ninguna (§1.5);
     - `GET /trainer/members` y `/trainer/members/{id}` devuelven `next_valuation` calculado en el servidor;
     - `GET /app/progress/summary` lo devuelve para el socio.
3. **Mostrarlo, solo lectura y en Dart:**
   - **Inicio del entrenador:** distintivo «Valoración vencida/próxima» en la tarjeta del socio (`trainer_home_screen.dart:640-751`). Sustituiría o complementaría el engañoso «Valoraciones por revisar».
   - **Ficha:** debajo de «Última valoración» (`trainer_member_detail_screen.dart:473-478`).
   - **Integral:** debajo de «Última valoración: dd/mm» (`integral_assessment_screen.dart:931-952`).
   - **Socio:** en Progreso, junto a «Última valoración · fecha» (`weight_evolution_section.dart:271`).
   - **Una única fuente:** el campo del servidor, nunca calculado en el cliente.
4. **Avisos al socio:** solo cuando el backend active `member_app_enabled`.
   - La app debería tratar el tipo nuevo en `_actionFor` y `_screenFor`, y el backend incluir `action_route` en el `data` de FCM para que tocar el push lleve a algún sitio.
   - El push del sistema seguirá sin navegar mientras FCM no lleve `action_route` (§6.3). Esto es del backend.

---

## 8. Resumen de hallazgos por prioridad

| Id | Prioridad | Tipo | Hallazgo | Dónde |
|---|---|---|---|---|
| H1 | Alta | HECHO | Push de valoración: probablemente no se envía (`should_popup` falso) y, si se envía, tocarlo no navega (sin `action_route`; el router no conoce `/assessment`) | `push_messaging_service.dart:308-313`, `coach_notification_router.dart:19-60`; backend `FcmService.php:120-126`, `NotificationService.php:1836-1838` |
| H2 | Alta | HECHO | El socio no tiene «Mis valoraciones»: `MemberAssessmentsScreen` es inalcanzable; solo se abre una desde la notificación | `member_assessments_screen.dart`; `notifications_screen.dart:381-389` |
| H3 | Alta | HECHO | No se puede corregir (`amend`) desde la app desde `8e8a437` | `trainer_member_detail_screen.dart:198-207`; `trainer_assessment_editor_screen.dart:157-166` |
| H4 | Media-alta | DEDUCCIÓN | Sin idempotencia: un timeout al crear duplica borradores; un timeout al enviar da un 409 confuso | `trainer_assessment_editor_screen.dart:138-177`; `api_client.dart:53` |
| H5 | Media | DEDUCCIÓN | Vaciar un campo de un borrador no lo borra en el servidor | `trainer_assessment_editor_screen.dart:102-118`; backend `updateDraft` |
| H6 | Media | DEDUCCIÓN | La Integral queda congelada tras registrar; «Falta: Peso» bloquea el primer plan | `integral_assessment_screen.dart:83`, `113-117`, `151-160`; `trainer_member_detail_screen.dart:355-367` |
| H7 | Media | DEDUCCIÓN | Un borrador guardado no refresca la ficha y hay riesgo de duplicados | `trainer_member_detail_screen.dart:195` |
| H8 | Media | HECHO | «Valoraciones por revisar» = no leídas por el socio | `trainer_home_screen.dart:296-297`; `TrainerMembersController.php:41-48` |
| H9 | Baja | HECHO | Estado en inglés en el seguimiento; «v1» repetido; el detalle sin fecha | `follow_up_sheet.dart:126-128`; `assessment_detail_screen.dart` |
| H10 | Baja | HECHO | Dos «Última valoración» con fuentes distintas | ver §1.6 |
| H11 | Baja | HECHO | «Completar evaluación» abre una pantalla de solo lectura | `weight_evolution_section.dart:55`; `physical_evaluation_screen.dart:62-65` |
| H12 | Baja | DEDUCCIÓN | Iron IA: un timeout a los 20 s y su reintento pueden cobrar dos planes | `integral_plan_service.dart:44-58`; `integral_assessment_screen.dart:206-215` |
| H13 | Baja | HECHO | `IntegralAssessmentForm` es código muerto con iconos sin glifo (trampa de OTA) | `integral_assessment_form.dart`; `test/ota/iconos_sin_glifo_en_release.txt` |
| H14 | Baja | HIPÓTESIS | El SSE del entrenador no se pausa en segundo plano | `trainer_realtime_service.dart`; `trainer_home_screen.dart:57` |

## 9. Decisiones pendientes y coordinación

- **Backend:**
  - el contrato de «próxima valoración» (opción A o B, §7.3);
  - `action_route` en el `data` de FCM;
  - `should_popup` en las notificaciones de valoración;
  - el valor real de `FCM_ONLY_POPUP` en producción.
- **Producto:** si «Valoración Integral» debe seguir llamándose así, visto el riesgo de confusión de §2.2, y qué cuenta como «cita completada».
- **Propietario:** el estado de la tablet y de los entrenadores en 2.0.9. Sin la 2.0.10 no hay OTA.
- **`tester`:** las pruebas de regresión que faltan:
  - el editor (crear, guardar, enviar, timeout, 409);
  - la Integral tras registrar la primera valoración;
  - el push al tocarlo con la app cerrada, en segundo plano y en primer plano;
  - la lista «Mis valoraciones» cuando tenga entrada.
- **`security`/`api-security-specialist`:**
  - el token de registro va embebido con un valor por defecto en el binario (`lib/core/config/app_config.dart:48-51`). Ya se conoce (memoria `registro-publico-token-apk`) y no está en el alcance;
  - el deep link de valoración ya resuelve el uuid contra el backend (`assessment_deep_link_screen.dart:9-11`).
