# Informe del revisor · Lote 4 (`integracion/consent` @ `f7f00fdc`, frente a producción `f1fe6cac`)

**22-11-2026 · `reviewer` · un solo pase, solo lectura.** No he editado ni confirmado nada: el árbol sigue limpio y en `f7f00fdc`. Mis sondas corren en copias de mi scratchpad: `base` (`git archive f1fe6cac`) y `mix` (el `app/`, `config/`, `database/`, `routes/` y `bootstrap/` de HEAD con las pruebas de `f1fe6cac`). La base de PG de la sonda de migración ya está borrada. Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## Veredicto
**APROBADO CON CONDICIONES para desplegar en producción con todo como está hoy. Bloqueantes: 0.**
- Condición única, operativa: desplegar con las declaraciones del guion corregido (`e7ef173c`, en `feat/ultron-studio-h9`), no con las que lleva este árbol (NB-1).

## Bloqueantes
Ninguno. Con el libro vacío y sin tocar banderas, lo que sirve hoy producción no cambia; lo único que cambia es lo decidido (I-1, solo con el asistido encendido). Lo comprobé con sondas diferenciales (abajo).

## No bloqueantes (no se corrigen en este cierre)
- **NB-1 · La condición.** `docs/ultron-studio/ops/cierre-lotes/lote-4/rutas.txt:4-8` (en `f7f00fdc`) declara 5 rutas, y `config.txt` no trae `outreach.complaints.per_account_per_day`. El diff añade 6 [C: `route:list` 1046 → 1052]. Si el servidor usara esa copia, el paso 4 vería 6 diferencias frente a 5 declaradas y volvería atrás solo.
  - Las de `e7ef173c` coinciden exactamente con el diff: 6 rutas y la clave `=null`. `informe-ops-lotes-2` ensayó con ellas, 1046 → 1052.
  - `f7f00fdc` no contiene `a7ffccc8`/`e0f81cda` (solo docs y ops).
- **NB-2 · Falsos negativos de la línea de baja** (`StudioMessageTemplates.php:1078`). Rechaza «No te escribiremos más si respondes BAJA», «Puedes darte de baja respondiendo BAJA» y «Si no quiere, responda BAJA» [C, sonda de 15 textos].
  - Falla del lado seguro, solo al crear (`:713`, `:1064`), y en producción hay 0 plantillas. La línea de las propuestas pasa.
- **NB-3 · Vuelta atrás del esquema con trabajadores del lote vivos.** `OutreachConsentFacts::hasLedger()` (`:307-319`) recuerda el «sí» por proceso. Si se borra la tabla sin `queue:restart`, toda foto con política falla (falla cerrado) hasta reiniciar.
  - Hoy, en sombra y sin estrategias, ninguna foto lee el libro [C, sonda P-D]: sin efecto.

## Las siete preguntas
**1. Compatibilidad con producción hoy** (libro vacío, banderas de hoy).
- **(a) La bandeja** [C]. Las pruebas de `f1fe6cac` sobre el código del lote (`tests/Feature/Outreach/Run`, 180) dan 1 error y 9 fallos. Les pongo SOLO la siembra de I-1 (`RunWorld::canaryMember()`, un prospecto `granted` con conversación) y queda 1 fallo: `test_m1_…`.
  - Ese fallo es la sentencia del libro, una por página, cuyo `VALUES` crece con el lote. El normalizador de HEAD la colapsa y sigue exigiendo el mismo número de ejecuciones.
  - Los 9 son exactamente I-1: un asistido por WhatsApp comercial o de cobranza sin consentimiento `granted` se retiene con `policy_unknown` (`AssistedQueue.php:143`, `:610`). C-1 está en `:613`.
  - Con el asistido APAGADO, F-1 retiene todo como `brake` antes de construir la foto: las pruebas F-1 de `f1fe6cac` pasan sobre el lote. Hoy es idéntico.
- **(b) Resolver una revisión del Inbox sin el campo nuevo** [C, sonda de 192 casos: eventos sí/no × 4 roles × motivo `complaint`/otro/null × pendiente sí/no × cuerpo `{}`, `{note}`, `{note:null}`, `{note:"  "}`]. Son idénticos el estado, el JSON, la fila de la conversación, las notas, los hechos comerciales escritos y las sentencias de escritura.
  - Sin categoría se usa el `resolve()` de siempre (`MarketingInboxController.php:493`).
  - El CRM local (`Iron-Body_Front@43b5397`) envía `{ note }` (`marketing-inbox.service.ts:510`) [L].
- **(c) El detalle de una conversación** [C, misma sonda]. Es idéntico salvo `ai_actions[].id` (`MarketingInboxService.php:269`). El CRM lo tipa sin validarlo en tiempo de ejecución (`marketing-inbox.service.ts:180`) [L].
- **(d) El programador** [C, sonda P-D: sombra, sin estrategias, eventos, programador y monitor encendidos]. `outreach:sweep`, `dispatch --inline`, `monitor` y `whatsapp-outcomes`, dos veces cada una: la misma secuencia de SQL (11-13 sentencias), la misma salida y el mismo código, 0 sentencias sobre `outreach_consents` y los mismos recuentos de las 8 tablas.
  - Con estrategias, el lector añade 1 sentencia por lote (por tramo de ≤ 1000 claves): la foto completa pasa de 39 a 40 [C, `SubjectAndSnapshotTest`]. Suma además 1 `hasTable` por proceso después de migrar, o 1 por lote antes de migrar (`OutreachConsentFacts.php:104`, `BatchSnapshotBuilder.php:104`).
- **Fuera de la bandeja.** El observador de quejas de la IA (el Inbox de cada día) escribe las mismas filas en `commercial_events` [C, sonda]. Suma 1 lectura por queja nueva y 2 por la repetida del día (`isDismissed`, sobre el índice único de `dedupe_key`).
  - El diff no toca el clasificador ni el aviso de pagos [C, `--stat`].

**2. La migración** [C, PG 14 desechable].
- Migré la base hasta `f1fe6cac` (`…allow_classifier_budget_scope` en el lote [1]). Con el lote, la única pendiente es `…create_outreach_consents_table`, que entra en el lote [2]. Los dos `100001` conviven: nombres distintos y orden alfabético.
- No altera ninguna tabla existente: su única FK es a sí misma (`:152`) y el REVOKE es solo sobre ella (`:311`).
- `down()` con 1 fila se niega y no toca nada. Vacía, borra la tabla y sus 3 funciones. Se vuelve a aplicar bien.
- Antes de migrar, el lector da todo en false sin fallar (`OutreachConsentFactsTest::test_sin_la_tabla…`, `H9AssistedEligibilityTest:534`) y no recuerda el «no» (C-5, `OutreachConsentFactsTest:307`).

**3. Las rutas** [C].
- `route:list --json`, base frente al lote: +6, −0 y 0 cambiadas (método, URI, acción y middlewares).
- Las nuevas llevan `api` + `LimitRequestBodySize:16384` + `throttle:ultron-studio-read|write`, como las del Studio. Se montan igual que `voice.php` (`bootstrap/app.php:54-59`).
- `auth:coverage --unmapped` sale vacío (código 0). `config:cache` y `route:cache` dan OK.
- `ValidatePostSizeWithStudioCap.php:60` solo añade un prefijo que no tenía rutas.

**4. La resolución de los conflictos** [C].
- **`OutreachSchemaTest`.** Son 10 pruebas del lote más 1 de main = 11, y el diff frente a cada padre solo añade.
  - La lista cerrada `LATER_EXECUTION_MIGRATIONS` (`:278-284`) y su prueba (`:646-669`) siguen enteras, y la ida y vuelta aplica las posteriores (`:617-619`).
  - Los recuentos 51/75/46 quedan sobre `EXECUTION_TABLES`. La familia `outreach_%` sigue cerrada: 9 tablas y 13+3 funciones (`:349-352`, `:371-374`). La migración C9 no casa con `TOUCHES_EXECUTION`.
  - PG: OK (11, 413).
- **El contrato.** Se conservan las 94 líneas que añadió main. De main solo faltan 3, y las reescribió el propio lote (el punto 9 de `policy_unknown`, la línea de `outreach_consents` y la fila B9-run), comprobado frente a la base de fusión `272faaa2`.
- **`.env.example`.** Están los dos lados.

**5. Lógica y casos límite** [L; C por las pruebas].
- **El lector.** Un `granted` cuenta en la posición de su petición y la retirada gana. La regla de los hermanos va en la misma sentencia. Una confirmación solo vale para el número de destino de hoy. Con las claves de C9 ausentes o en false, `consentFor()` da lo de antes (`CoreProjection.php:210-225`).
  - Las carreras con dos conexiones pasan en PG (`OutreachConsentsApiTest`, 24/750).
- **Registrar una queja.** Una por persona y día de Bogotá (clave de deduplicación), idempotente por `clientRequestId`, y tope por cuenta con `admins FOR UPDATE` y 429 (`OutreachComplaintController.php:123-124`).
- **Descartar una queja.**
  - La llave se exige en el controlador (`:484`) y en el servicio (`MarketingStaffReviewService.php:83`); NB1 está en `:100` y NB2 en `:106`.
  - Descarta solo las quejas de la IA de esa conversación y posteriores a la resolución anterior (`ComplaintCommercialEmitter.php:203-205`).
  - La serie `:2`…`:21` y después `:a<id>` está en `MarketingAiActionCommercialObserver.php:131-142`.
- **La línea de baja.** Solo se comprueba al crear: la lectura de Meta no revalida lo guardado. Con 0 plantillas, no rechaza nada que exista hoy (y NB-2).

**6. Las pruebas** [C/L]. Usan clases reales (controladores, servicios, lector y observador) y PG con dos conexiones.
- `AssistedQueueOptedOutTest` cambia de false a null por I-1, decisión escrita (`informe-reviewer-bandeja` I-1 y el contrato), y fija que el servicio no cambia.
- Demostré que la siembra de `canaryMember()` es el ÚNICO cambio que necesitan las pruebas de producción para pasar sobre el lote (9 de 9): no tapa otro fallo.
- Las guardas de producción solo caen por las listas cerradas que el lote amplía: mapa de rutas, hechos 27 → 28, disparadores, supresión 1581 y `test_m1`.
- Nada se ha aflojado.

**7. El despliegue.**
- Exige la migración con el rol dueño y el rol de la aplicación presente; en producción, si no, falla y no se registra (`:322-326`). `lock_timeout` de 5 s y sin cerrojos sobre tablas existentes.
- Exige `config:cache`: 7 claves nuevas `outreach_consents.*` y `outreach.complaints.per_account_per_day = null` [C, volcado base frente al lote], `route:cache` (6 rutas) y `queue:restart` después de migrar.
- **La vuelta atrás: basta el código.** La tabla se queda y el código viejo no la lee. Tolera `complaint_dismissed` (`factLabel` con `default` [L]), y lo descartado vuelve a frenar (el lado seguro).
- **Contradice al guion:** solo NB-1.

## Pruebas ejecutadas (línea final)
| Qué | Resultado |
|---|---|
| PG 14 (`ultron_studio_cierre_revlote4`) | OutreachSchemaTest OK (11, 413) · OutreachConsentsSchemaTest OK (21, 378) · Suppression1581 OK (1, 409) · ConsentsApi OK (24, 750) · ComplaintsApi OK (13, 192) · ComplaintDismissal OK (15, 148) · DismissAuthority OK (6, 250) · AssistedEligibility OK (33, 492) · ConsentFacts OK (20, 1185) · Planner OK (8, 89) · Dispatcher OK (9, 110) · Policy OK (6, 50) · W9 OK (50, 1728, 1 saltada) |
| SQLite, HEAD | ConsentsApi OK (24, 399, 6 saltadas) · ComplaintsApi OK (13, 189, 1 saltada) · Dismissal OK (15, 148) · DismissAuthority OK (6, 250) · Eligibility OK (33, 492) · ConsentFacts OK (20, 1185) · OptedOut OK (65, 66) · LadderParity OK (4, 138) · OptOutText OK (9, 740) · MetaTemplateContent OK (16, 393) · InboxTest OK (27, 132) · RoutesMapGuard OK (3, 213) · `tests/Unit/Outreach` OK (1118, 53720) · `tests/Feature/Commercial` OK (241, 1115, 14 saltadas) · OutreachIsolationTest 5 pruebas, 1 fallo: la puerta de diff, con solo los 3 ficheros autorizados |
| Pruebas de `f1fe6cac` sobre el lote | Run (180): 1 error y 9 fallos → con la siembra de I-1: 1 fallo (m1) · `Feature/Outreach` (812): 4 fallos (listas cerradas y m1) · Inbox ×4 OK (27, 13, 15, 17) · Commercial (241): 1 fallo (27 → 28 hechos) |
| Sondas propias | Inbox: 192 casos idénticos y el detalle solo con `id` · observador: mismas filas · programador: mismo SQL · configuración: solo las claves nuevas · `route:list` +6 · migración, `down()` y nueva subida en PG |

## Lo que no pude verificar
- **Producción** (sin ssh): las banderas reales, las 0 plantillas, que no haya asistidos ni estrategias, y el valor de `COMMERCIAL_EVENTS_ENABLED` [S, según el encargo].
- **El CRM desplegado:** solo leí la copia local `43b5397`, que puede no ser la que se sirve.
- **El REVOKE con `iron_app` en mi sonda:** el clúster local no tiene ese rol; lo cubren las pruebas de esquema en PG, que lo crean.
- **El guion en sí:** no lo revisé, por encargo; solo crucé sus declaraciones con el diff.
- **No relancé la batería completa ni las mutaciones del autor.**
