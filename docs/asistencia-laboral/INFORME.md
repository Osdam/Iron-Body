# Asistencia laboral de empleados — informe técnico

Fecha: 2026-10-10/11 (Bogotá). Rama `feat/employee-attendance` en los tres repositorios,
**sin commit, sin push, sin despliegue**. Diseño: [DISENO.md](DISENO.md). Contrato:
[CONTRATO.md](CONTRATO.md).

## 1. Arquitectura encontrada

Ver DISENO.md §1. Lo esencial: identidad canónica `identities`; roles de entrenador
`trainer_floor`/`trainer_functional`; recepción solo como cuentas del CRM; biometría de
entrenadores 1:1 en servidor con embeddings MobileFaceNet calculados en el teléfono;
outbox `class_events` + SSE del CRM con cursor; sin registro de dispositivos; sin jornada
laboral. `attendances` (puerta de socios) y `employee_accesses` (pase gratuito) son otra
cosa y no se tocaron.

## 2. Riesgos detectados (producción, verificados en solo lectura)

| # | Riesgo | Estado |
|---|---|---|
| R1 | Login facial público aceptaba un vector casi nulo → sesión de cualquier entrenador con rostro (4) | **Mitigado en producción** (autorizado): `TRAINER_FACE_LOGIN_ENABLED=false` el 2026-10-11 02:35 UTC. Corregido en código (`FaceEmbeddingNormTest`). |
| R2 | `TRAINER_OTP_DEV_BYPASS=true`: 134/158 logins de entrenador sin segundo factor | Abierto. La app nueva no depende de él; apagarlo es un paso del despliegue (requiere autorización). |
| R3 | `face_verified` de iniciar/finalizar clase lo declaraba el cliente; la app solo comprobaba que la cámara devolvió un archivo | Corregido: rostro comparado en servidor; la app publicada queda como `legacy_claim` hasta apagar la transición. |
| R4 | El autoenrolamiento sobrescribía la plantilla; las 4 plantillas actuales se registraron desde sesiones `dev-login` sin consentimiento | Corregido: no se sobrescribe; portal sin autoenrolamiento por defecto; las 4 quedan «pendiente de renovación» (decisión del negocio). |
| R5 | `device_id` declarado por el cliente usado como «dispositivo de confianza» (`trainer/auth/access`) | Preexistente y transversal (I7 de la revisión): **escalar**. Las sesiones de kiosko ya no generan confianza. |
| R6 | En PostgreSQL, `PublishedTrainerAppAttendanceTest::test_dos_peticiones_a_la_vez…` falla en la base con «current transaction is aborted»: posible 500 real al marcar asistencia de clase en carrera | Preexistente, fuera de alcance: **revisar aparte**. |

## 3–5. Arquitectura final, modelos y migraciones

DISENO.md §2–§3. Migraciones nuevas (todas aditivas, reversibles, probadas en SQLite y en
PostgreSQL 14.24, subida/bajada/subida y con datos heredados):
`2026_10_11_700001`–`700008` (personal, plantilla por identidad cifrada, dispositivos,
códigos/tokens, jornadas con índice único parcial, dispositivo y método en
`class_sessions`, `subject`/`audience` en `class_events`, `expires_at` en sesiones del
portal). Las tablas nuevas llevan 15 claves foráneas `restrict` entre sí y hacia
`identities` (nada de eso se borra: se revoca o se desactiva); sin FK hacia las cuentas
del CRM, que sí se pueden borrar (su nombre va copiado en la fila).

## 6. Endpoints

Nuevos: `kiosk/*` (11, firmados salvo `device/register` y `device/challenge`),
`admin/workforce/*` (9), `admin/biometrics/identities/*` (3), `admin/devices/*` (5),
`trainer/auth/logout`. Reutilizados y ampliados de forma compatible:
`trainer/classes/{id}/start|end`, `trainer/auth/face/enroll|login`,
`admin/trainers/{id}/face`, `admin/class-sessions`, `admin/classes/stream`. Detalle en
CONTRATO.md.

## 7–12. Reglas, flujos biométrico y de jornada, SSE, seguridad de dispositivos y permisos

DISENO.md §4–§8.

## 13. Cambios en la app Flutter

- «Acceso para entrenadores» → **«Acceso para empleados»** con selección de actividad
  (Entrenadores / Jornada laboral).
- Kiosko de jornada como máquina de estados (`KioskController`): identificar (documento o
  lista), verificar (rostro o código de respaldo), una sola acción permitida,
  confirmación con hora del servidor y vuelta sola a neutral; enrolamiento con código,
  consentimiento y 3 capturas, y entrada automática en el mismo flujo.
- Vinculación del equipo; canal nativo `ironbody/device_key` (Android Keystore/StrongBox
  con atestación; iOS Secure Enclave), firma canónica de cada petición, reintentos con el
  mismo `operation_id`.
- Tablet compartida: inactividad, segundo plano y atrás vuelven a neutral; velo de
  privacidad en `inactive`; portal del entrenador en equipo compartido con cierre por
  inactividad y logout en servidor; `session_expired` manejado.
- Inicio/fin de clase con rostro real (embedding) y respaldo con código.
- Sin dependencias nuevas.

## 14. Cambios en el CRM

- **«Supervisión de empleados y clases»** (`/staff-supervision`, la ruta vieja redirige),
  pestañas Clases · Empleados · Personal y biometría · Dispositivos según permisos.
- Clases: columna Dispositivo (verificado / declarado / «No registrado») y método.
- Empleados: tabla, indicadores, filtros, paginación, detalle con línea de tiempo,
  resolución de incidencias con motivo.
- Personal y biometría: alta enlazando identidad, cargos, código de respaldo, estado,
  autorizar enrolamiento y revocar (con motivo; borra la plantilla).
- Dispositivos: vincular (código), renombrar, revocar, intentos rechazados.
- Selector 12 h/24 h persistente (12 h por defecto), siempre en hora de Bogotá.
- Tiempo real por el MISMO stream y la misma conexión SSE.

## 15–17. Pruebas, resultados y evidencias

| Ámbito | Tipo | Resultado |
|---|---|---|
| Backend, dominio nuevo + Clases afectadas | Automatizada (SQLite) | 175/175 |
| Backend, dominio nuevo + Clases afectadas | Automatizada (PostgreSQL 14.24 local) | 175/175 (incluye carreras reales e integridad referencial); migraciones subida → bajada de 8 → subida, y relleno con datos heredados |
| Backend, suite completa | Automatizada | 8549 pruebas con el código y el esquema finales; los 56 fallos son EXACTAMENTE los de la línea base del mismo día (facturación/contratos); 0 nuevos |
| Backend, carrera real de dos entradas | E2E HTTP multiproceso sobre PostgreSQL | 1×201 + 1×409, una jornada, 0 errores |
| Backend por HTTP real | E2E (tablet simulada con clave EC real) | vinculación, enrolamiento, entrada/salida, SSE filtrado por permiso |
| CRM | Unitarias (vitest) | 1491 pasan; 16 fallos preexistentes idénticos a la base; `check:literals` y build correctos |
| CRM | E2E en Chrome (Puppeteer) contra backend real | **31/31**, repetida con el código y el esquema finales (SSE en vivo ~1,4 s, dispositivo, 12/24 h, vinculación por UI, revocación, Recepción, móvil 390 px, sin solapes 1024–1440 px) |
| App | Unitarias y de widget | 854/854 (+1 E2E saltada por defecto) |
| App | E2E de cliente Dart real contra backend real (`test/workforce/kiosk_real_backend_e2e_test.dart`) | OK, repetida con el esquema final (clave OpenSSL en lugar del Keystore; cámara sintética) |
| App en simulador iPad | — | **No ejecutable**: ML Kit (`MLImage`) no trae porción arm64-simulador (preexistente). No se forzó la vía x86_64/Rosetta: el simulador no tiene cámara ni Secure Enclave, así que no desbloquearía nada que no cubran ya las pruebas de widget y la E2E de cliente |
| Dispositivo físico | — | **Pendiente** (ver §18) |
| Computer Use (CUA) | — | No disponible en esta sesión: sustituido por Chrome real dirigido por Puppeteer contra el backend real (capturas en el scratchpad) |
| Revisión de seguridad | Agente `api-security-specialist` | F1–F8 + I2, I3, I6 corregidos con pruebas; I7 escalado |
| Revisión final | Agente `reviewer` (independiente) | 1.ª pasada: APROBADO CON OBSERVACIONES (0 bloqueantes, 12 puntos). 2.ª pasada tras corregirlos: **APROBADO**, con sus pruebas temporales repetidas (409 y traza=1 donde antes daba 200 y 0) |


### Los 50 escenarios obligatorios

Leyenda: **B** backend (PHPUnit, SQLite y PostgreSQL) · **F** app (pruebas de controlador y
de widget) · **C** CRM (vitest) · **E2E** extremo a extremo real · ⚠ falta el dispositivo físico.

| # | Escenario | Evidencia | Estado |
|---|---|---|---|
| 01 | Recepcionista sin biometría | B `recepcionista_sin_biometria_se_enrola_con_codigo_y_entra_en_el_mismo_flujo`; F «no enrolado…»; E2E de la app | ✅ |
| 02 | Enrolamiento autorizado | B `el_documento_solo_no_enrola…`, `el_codigo_de_enrolamiento_admite_pocos_intentos`, `sin_consentimiento…`, `capturas_incoherentes…`; E2E CRM «Entrenador enrolado con código» | ✅ ⚠ cámara real |
| 03 | Entrada automática tras enrolar | B (mismo flujo, método `enrollment`); F «…3 capturas → entrada automática»; E2E de la app | ✅ |
| 04 | Con biometría existente | F «enrolado: identifica → verifica con rostro»; B `una_plantilla_heredada_sin_consentimiento_queda_pendiente_de_renovacion` (decisión: revalidar una vez) | ✅ |
| 05 | Entrada correcta | B `entrada_y_salida_con_rostro_registran_horas_del_servidor_y_duracion`; E2E CRM | ✅ |
| 06 | Salida correcta | Ídem; E2E de la app (salida con rostro) | ✅ |
| 07 | Entrada duplicada | B `una_entrada_repetida_no_crea_otra_jornada`; F «entrada duplicada» | ✅ |
| 08 | Salida duplicada | B `una_salida_repetida_o_sin_entrada_se_rechaza` | ✅ |
| 09 | Salida sin entrada | Ídem; F «salida sin entrada» | ✅ |
| 10 | Dos empleados, misma tablet | B `dos_empleados_seguidos_en_la_misma_tablet_no_se_mezclan`; F «dos empleados seguidos no se mezclan (y una respuesta tardía se descarta)» | ✅ |
| 11 | Limpieza tras cada operación | F atrás, inactividad 45 s, vuelta sola a neutral, segundo plano, velo en `inactive`; B `una_verificacion_sirve_una_sola_vez…` | ✅ |
| 12 | Funcional inicia clase | B `iniciar_y_finalizar_con_rostro_y_tablet_autorizada`; E2E CRM «Clase iniciada con rostro + tablet firmada» | ✅ ⚠ |
| 13 | Funcional finaliza clase | Ídem | ✅ ⚠ |
| 14 | Planta registra jornada | B `entrenador_de_planta_registra_jornada` | ✅ |
| 15 | Planta imparte clase | B `la_clase_y_la_jornada_no_comparten_estado` | ✅ |
| 16 | Varios cargos | B `una_persona_con_varios_cargos_es_una_sola_identidad_con_una_sola_jornada`, `quien_conserva_un_cargo_con_jornada…` | ✅ |
| 17 | Jornada y clase sin interferencia | B `la_clase_y_la_jornada_no_comparten_estado` | ✅ |
| 18 | Medianoche | B `una_jornada_nocturna_es_del_dia_en_que_empezo` | ✅ |
| 19 | Conexión interrumpida | F «red: reintenta con el MISMO operation_id y nonce nuevo → replayed», «red agotada…» | ✅ |
| 20 | Reintento | B `un_reintento_con_el_mismo_operation_id…`, `reintentar_un_registro_completado_devuelve_el_mismo_dispositivo` | ✅ |
| 21 | Respuestas duplicadas | B `el_mismo_operation_id_no_vale_para_otra_persona`; F «replayed» | ✅ |
| 22 | SSE con reconexión | C «al reconectar manda el último id visto como after_id», «resync:true…»; B `ClassEventsFeedTest` (cursor) | ✅ |
| 23 | SSE evento duplicado | C «los repetidos tras reconectar se descartan por id» | ✅ |
| 24 | CRM se actualiza solo | E2E CRM: KPI y fila sin recargar, lista de dispositivos sola | ✅ |
| 25 | Evento sin permiso | B `WorkforceStreamAudienceTest` (5); E2E CRM «Recepción solo ve Clases» | ✅ |
| 26 | Dispositivo autorizado | B `un_dispositivo_vinculado_con_codigo…`; E2E CRM vinculación por UI | ✅ ⚠ Keystore real |
| 27 | Dispositivo desconocido | B `un_device_id_inventado_no_entra`, `sin_cabeceras_de_firma_no_hay_operacion` | ✅ |
| 28 | Dispositivo revocado | B `un_dispositivo_revocado_desde_el_crm…`, `un_nonce_pedido_antes_de_revocar…`; F «revocado no permite firmar» | ✅ |
| 29 | APK en otra tablet | B `la_apk_copiada_a_otra_tablet_no_tiene_la_clave`; F «id guardado sin clave = sin vincular» | ✅ ⚠ no exportabilidad real |
| 30 | Falsificar device_id | B `un_device_id_inventado…`, `el_nonce_de_otro_dispositivo…`, `un_id_declarado_no_puede_hacerse_pasar…` | ✅ |
| 31 | Reutilizar un desafío | B `un_desafio_firmado_no_se_puede_reutilizar`, `un_desafio_caducado_no_sirve` | ✅ |
| 32 | Revocar biometría desde el CRM | B `revocar_exige_motivo_borra_la_plantilla…`; E2E CRM | ✅ |
| 33 | Usar biometría revocada | B `una_biometria_revocada_no_verifica`, `el_login_facial_antiguo_no_acepta_una_plantilla_revocada` | ✅ |
| 34 | Enrolar sobre identidad ajena | B `no_se_enrola_un_rostro_que_ya_es_de_otra_persona` (con traza), `el_autoenrolamiento_tampoco_acepta_un_rostro_ajeno`, `la_referencia_de_empleado_no_viaja_a_otra_tablet` | ✅ |
| 35 | Identificación por documento | B `el_documento_identifica_pero_no_registra_nada` | ✅ |
| 36 | Identidad documental ≠ verificación | Ídem + `el_documento_solo_no_enrola…` | ✅ |
| 37 | 12 h | C `time-format`; E2E CRM «12 h por defecto»; F «UTC−5 y formato 12 h es-CO» | ✅ |
| 38 | 24 h | E2E CRM «24 h» y «sobrevive a recargar» | ✅ |
| 39 | Cambio de día | B jornada nocturna; C ventana de Clases en días del gimnasio; `test:agenda-tz` con TZ=Asia/Tokyo | ✅ |
| 40 | Indicadores automáticos | B `los_indicadores_cuentan_lo_que_pasa`; E2E CRM «En jornada ahora» pasa a 1 | ✅ |
| 41 | Historiales | B `el_detalle_muestra_la_linea_de_tiempo…`, `el_listado_trae…paginacion…`, `el_historial_de_jornada_es_inmutable` | ✅ |
| 42 | Registros existentes intactos | Migraciones con datos heredados (SQLite y PostgreSQL); B `una_sesion_historica_sin_dato_sale_como_no_registrada`, `la_app_publicada_sigue_iniciando…`; suite completa sin fallos nuevos | ✅ |
| 43 | Sin overflow en Flutter | F todos los pasos en 6 tamaños, sin errores de layout | ✅ ⚠ |
| 44 | Sin overflow en el CRM | E2E CRM 390 px por pestaña + 1024/1280/1440 por celda | ✅ |
| 45 | Tablet vertical | F 800×1280 y 1600×2560 | ✅ ⚠ |
| 46 | Tablet horizontal | F 1280×800 y 2560×1600 (kiosko); la captura facial fuerza vertical | ⚠ |
| 47 | Accesibilidad | F `androidTapTargetGuideline` y `labeledTapTargetGuideline` en cada paso; C foco de diálogos | ✅ |
| 48 | Concurrencia | B `la_carrera_real_la_decide_el_indice_unico…`, `la_base_impide_dos_jornadas…`, `dos_altas_simultaneas…`; E2E HTTP multiproceso en PostgreSQL (1×201 + 1×409) | ✅ |
| 49 | Integridad referencial | B `la_base_exige_integridad_referencial` (15 FK; falla sin ellas); 15 FK creadas en PostgreSQL | ✅ |
| 50 | Pagos, caja, planes y membresías intactos | Los diffs de los tres repos no tocan esos dominios; suite completa del backend y del CRM sin fallos nuevos | ✅ |

Mutaciones deliberadas para comprobar que las pruebas detectan fallos: jornada obsoleta,
norma del vector, orden del cursor, velo de privacidad, solapes de tabla (todas fallan
sin el código y pasan con él). Capturas de la E2E del CRM en el scratchpad de la sesión.

## 17 bis. Observaciones de la revisión final y su cierre

| # | Observación | Cierre |
|---|---|---|
| 1 | Una plantilla «pendiente de renovación» por un código vivo seguía verificando | Solo verifica el estado `active`; prueba que falla con el código anterior |
| 2 | En el kiosko, el rechazo «rostro de otra persona» perdía su traza (rollback) | Comprobación 1:N antes de la transacción (tras validar el token); prueba con mutación |
| 3 | Con la app nueva, cada clase pediría código hasta que el entrenador se enrole | Paso 4b del despliegue (DISENO §10): enrolar a los 11 antes de operar clases |
| 4 | Runbook: `--step=7`, falta la 700008, defecto de autoenrolamiento | Corregido (`--step=8` comprobado en SQLite: esquema vuelve completo) |
| 5 | La reversión de la 700002 se bloqueaba por una revocada sin entrenador | Cuenta solo las que conservan plantilla; comprobado |
| 6 | Comentario obsoleto en `deleteFace` | Borrado |
| 7 | `operation_id` del enrolamiento obligatorio y sin uso | Opcional y documentado (el reintento lo reconoce el token) |
| 8 | La prueba de carrera no llegaba al `catch` del índice único | Prueba nueva que fuerza la violación dentro de la transacción; sin el `catch` da 500 |
| 9 | Alta simultánea de la misma persona: 500 en PostgreSQL | Identidad fuera de la transacción + 409 `staff_profile_exists`; dos pruebas con mutación |
| 10 | Desactivar con la jornada abierta la dejaba «en jornada» hasta 16 h | Pasa a incidencia «Desactivado con la jornada abierta», sin hora inventada (misma regla que la salida olvidada) |
| 11 | Estilo (pint) | Pruebas formateadas; provider y bootstrap con `use`. Los 54 PHP nuevos pasan pint; los 25 modificados dan EXACTAMENTE los mismos avisos que en la base (sin reformatear lo ajeno) |
| 12 | CRM: con solo `biometrics.view` se ofrecía una entrada a una página vacía | Menú y ruta piden los permisos que abren alguna pestaña; dos pruebas leen la ruta y el `app.html` real (fallan con el permiso de vuelta) |
| 13 | Revisión automática del commit: el login facial público compartía el contador de intentos con el kiosko (cualquiera podía bloquear el rostro de los entrenadores en la tablet, con el login encendido) | Contador por vía. El público cuenta por persona y sin la IP (una segunda revisión señaló que por IP se esquivaba rotando direcciones): 5 intentos por persona cada 15 min venga de donde venga, y bloquearlo no afecta a la tablet. Prueba que falla con el contador compartido y con la IP en la clave. Dos barridos nuevos sobre la tabla de rutas: las rutas de administración nuevas responden 401 sin sesión, y todas las del kiosko salvo vincular y pedir desafío exigen firma |

## 18. Limitaciones conocidas

- **Sin validar en dispositivo físico:** clave en Android Keystore/StrongBox y su
  atestación real, Secure Enclave en iPad, cámara y vectores reales de MobileFaceNet
  frente a los umbrales del servidor, captura facial con la tablet en horizontal
  (`FaceCaptureScreen` fuerza vertical).
- La atestación no consulta la lista de revocación de Google ni valida el estado de
  arranque verificado (RootOfTrust); «verificada» = cadena de Google + desafío + clave.
- Sin Play Integrity / App Attest (requieren credenciales del canal de distribución).
- Sin prueba de vida activa: la defensa es combinada (dispositivo autorizado, código de
  administrador para enrolar, 1:N, intentos limitados, auditoría).
- La columna Personas de Clases no se refresca en vivo con reservas (mejora decidida).

## 19. Riesgos residuales

R2, R5 y R6 (§2). Un dispositivo vinculado y robado firma igual hasta que se revoque.
Las sesiones del portal abiertas por DOCUMENTO (OTP) en una tablet compartida siguen sin
caducar en el servidor (comportamiento anterior; las abiertas desde el kiosko sí caducan:
12 h y 15 min de inactividad). Con `TRAINER_OTP_DEV_BYPASS=true` en producción, ese camino
no tiene segundo factor: apagarlo (R2) es la mitigación.
Embeddings calculados en el cliente: un embedding de la persona obtenido fuera del
sistema sigue siendo útil para quien tenga además una tablet vinculada.

## 20. Guía de pruebas manuales (aceptación)

1. CRM → Dispositivos → «Vincular dispositivo» → en la tablet: Acceso para empleados →
   vincular con el código. Ver en el CRM el equipo y su atestación.
2. CRM → Personal → alta de una recepcionista → Biometría → «Autorizar enrolamiento».
3. Tablet → Jornada laboral → documento → código → consentimiento → 3 capturas → la
   entrada se registra sola. Ver en CRM → Empleados la fila y «En jornada ahora» = 1 sin
   recargar.
4. Repetir con la misma persona → salida; probar entrada repetida, salida sin entrada,
   cambio rápido de persona, atrás, dejar la tablet quieta 45 s.
5. Entrenador funcional: iniciar/finalizar clase con rostro desde la tablet; ver en
   Clases el dispositivo. Probar el código de respaldo de clase.
6. Revocar biometría y revocar el dispositivo desde el CRM; comprobar el rechazo en la
   tablet. Formato 12/24 h.

## 21–22. Despliegue y reversión

DISENO.md §10–§11. Ningún paso ejecutado. Requiere autorización explícita.

## 23. Estado Git

| Repo | Worktree (rama `feat/employee-attendance`) | Base | Cambios sin commit |
|---|---|---|---|
| Backend | `_worktrees/employee-attendance/Iron-Body` | `89db408` | 57 entradas de `git status` (25 ficheros modificados; el resto, nuevos) |
| CRM | `_worktrees/employee-attendance/Iron-Body_Front` | `7ee4835` | 21 entradas |
| App | `_worktrees/employee-attendance/Iron_Body_App` | `3a4b4d3` | 16 entradas |

Sin commit, sin push, sin merge, sin despliegue. Hay un worktree auxiliar de solo
lectura, `_worktrees/employee-attendance-base` (detached en `89db408`), que sirvió para la
línea base en PostgreSQL.

## 24. Aislamiento

- Los checkouts principales no se han tocado: backend `Iron_Body_Crm/Iron-Body` (`main`,
  sin cambios), CRM `Iron_Body_Crm/Iron-Body_Front` (`main`, 1 ruta ajena) y app
  `Iron_Body_App` (`fix/ios-tflite-symbols`, 32 rutas ajenas). No se hizo ningún
  checkout, stash, reset ni clean, y no se movió ninguna rama (solo `fetch`).
- **Al integrar:** 3 ficheros de la app están a la vez en mi cambio y en el trabajo sin
  commit de la otra sesión: `trainer_class_detail_screen.dart`,
  `trainer_class_service.dart` y `trainer_realtime_service.dart`. Mis ediciones ahí son
  mínimas (prueba facial real en inicio/fin de clase y aviso de `session_expired`), pero
  habrá que mezclarlas a mano. En backend y CRM no hay solapamiento.
- `flutter test` y `pub get` reescriben `analysis_options.yaml`, `macos/*`,
  `ios/Podfile.lock` y `android/gradle.properties`. Se revirtieron en el worktree y no
  forman parte del cambio.

## Decisiones pendientes en producción (cada una requiere autorización)

1. Desplegar (orden backend → CRM → app, DISENO §10), con copia de la base antes.
2. Enrolar a los 11 entrenadores antes de usar la app nueva en clases (paso 4b).
3. `TRAINER_OTP_DEV_BYPASS=false` (R2).
4. Volver a encender `TRAINER_FACE_LOGIN_ENABLED` tras el despliegue, o dejarlo apagado.
5. Cerrar la transición cuando todas las tablets tengan la app nueva:
   `WORKFORCE_DEVICE_POLICY_CLASSES=enforce`, `WORKFORCE_LEGACY_FACE_CLAIM=false`.

## Hallazgos adyacentes (preexistentes, no tocados)

- I7: `device_id` declarado como dispositivo de confianza en `trainer/auth/access` (R5).
- R6: carrera en PostgreSQL al marcar asistencia de clase (posible 500).
- El KPI «a tiempo» de Clases cuenta como puntuales los adelantos de más de 2 minutos que
  la fila marca «Antes».
- `trainer-modal` se rompe si se crea ya abierto (`ngOnChanges` antes de `ngOnInit`).
- En móvil, el botón flotante del asistente tapa campos de búsqueda.

## Criterios de aceptación

| Criterio | Estado |
|---|---|
| Las clases siguen funcionando | ✅ app publicada (`legacy_claim`) y app nueva; suite de Clases sin fallos nuevos |
| Las jornadas funcionan | ✅ |
| La biometría existente puede reutilizarse | ✅ con una revalidación (decisión del negocio): las 4 heredadas sin consentimiento pasan a «pendiente de renovación»; las enroladas en el sistema nuevo se reutilizan en jornada, clases y portal |
| El enrolamiento es seguro | ✅ código de administrador, consentimiento, 3 capturas coherentes, 1:N; sin prueba de vida (§18) |
| La revocación desde el CRM funciona | ✅ |
| La tablet compartida no mezcla identidades | ✅ |
| El registro de dispositivos funciona | ✅ por HTTP real con clave EC; ⚠ falta el Keystore/Secure Enclave real |
| Se identifica el dispositivo real autorizado | ⚠ criptografía y atestación probadas con claves y cadenas de prueba; **falta un equipo físico** |
| Registros auditables | ✅ historial inmutable, trazas de biometría y dispositivos |
| SSE actualiza el CRM | ✅ |
| Controles de concurrencia | ✅ |
| Interfaces responsive | ✅ CRM en navegador real; app en pruebas de widget (⚠ equipo físico) |
| Sin errores críticos de ejecución | ✅ |
| Pruebas disponibles superadas | ✅ (fallos preexistentes idénticos a la línea base) |
| Otros procesos sin alterar | ✅ |
| Sin interferir con la otra sesión | ✅ |

## Estado final

**`EMPLOYEE_ATTENDANCE_BLOCKED`**: no se ha validado en dispositivo físico. Todo lo
demás está implementado, revisado (APROBADO) y probado. Pero dos criterios dependen de
hardware que no hay en esta sesión, y declarar `E2E_READY` sin ellos sería inventar
evidencia:

- que la clave se cree NO exportable en Android Keystore/StrongBox, y que su atestación
  real verifique, y lo mismo con el Secure Enclave del iPad;
- que la cámara y MobileFaceNet reales den vectores dentro de los umbrales del servidor
  (norma 0,9–1,1; distancia ≤ 1,0; coherencia ≤ 0,9) con la tablet en vertical y en
  horizontal.

**Qué hace falta para desbloquear:** una tablet Android (mejor con StrongBox) y un iPad con
la compilación de depuración, contra un backend local o de pruebas (nunca producción). En
ellos, ejecutar `integration_test/workforce_kiosk_e2e_test.dart` e
`integration_test/device_key_channel_test.dart` y recorrer la guía del §20 con dos
personas reales que consientan. Si los vectores reales caen fuera de los umbrales, se
ajustan sin cambiar código: distancia con `TRAINER_FACE_MATCH_THRESHOLD`
(`config/trainer.php`, la misma que ya usaba el portal), coherencia con
`WORKFORCE_FACE_ENROLLMENT_CONSISTENCY`; la norma está en `config/workforce.php`.
