# api-security · Repaso final del lote 4 (libro de autorizaciones C9 + quejas Q1-8) · un pase

22-11-2026 · api-security-specialist · `_worktrees/lote-4`, rama `integracion/consent`, punta `f7f00fdc`; lo que llega a producción: `f1fe6cac..HEAD` (35 ficheros de `app/ routes/ config/ database/ bootstrap/`). Solo lectura: `git status` limpio al terminar. **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## Veredictos
**(1) Desplegar el lote en producción: APTO CON CONDICIONES.** Las condiciones son operativas y ya se conocían, no hay ninguna nueva. Hay que migrar como dueño con `iron_app` ya creado (C-B1: si falta, la migración aborta y no queda registrada) y ejecutar `php artisan queue:restart` después (C-B2/C-5). `COMMERCIAL_EVENTS_ENABLED` se deja como esté (la auditoría A9 la da en `false` [L, sin verificar]): el lote es inerte sin tocarla.
**(2) Que el personal empiece a usarlo: APTO CON CONDICIONES.** (a) `COMMERCIAL_EVENTS_ENABLED=true` (decisión P9-24: sin ella, registrar y descartar quejas dan 409 y no escriben) y el texto legal configurado por finalidad. (b) C-4 aceptado por escrito por el propietario (sigue abierto; la decisión es suya). (c) `complaints_record`, `complaints_dismiss` y `consents_*` solo a cuentas con nombre, **nunca al rol Entrenador** (NB-1). (d) Las pantallas: envían `last_seen_ai_action_id` al descartar (sin él NB2 no actúa); enseñan `numberRevoked` («retirada para este número»); pintan la evidencia como texto (M-7); y el texto de «No era una queja» dice que alcanza a todas las quejas de la IA de esa conversación desde la última revisión.
**Bloqueantes: 0.**

## Condiciones y bloqueantes anteriores → ¿cerrados?
| Anterior | Estado | Evidencia |
|---|---|---|
| C-1 · la bandeja respeta la retirada | CERRADA | `AssistedQueue.php:613`. [C] sonda: P `granted` → retirada por HTTP → `optedOut` = true para P y para el suelto Q con su número (antes false/false); el servicio sigue en false. Autor `H9AssistedEligibilityTest` PG OK (33, 492) |
| C-2 · vale para el número; otro número no la levanta | CERRADA | `OutreachConsentFacts.php:388-398` (`g.phone_ref = v.phone_ref AND r.id > v.id`). [C] suelto Q: `granted` → retirada de P → `denied`; P autoriza OTRO número → `denied`; Q se autoriza X él mismo → `denied`; P vuelve a autorizar X → `granted` |
| C-3 · alcance del entrenador | CERRADA (autorizaciones) | `OutreachConsents.php:483, :523, :536`. [C] Entrenador sin asignaciones: ficha 404; GET 404 (por el socio y por su prospecto); retirar 404. Las quejas no lo aplican: NB-1 |
| C-5 · no recuerda «no hay libro» | CERRADA | `OutreachConsentFacts.php:307`. [C] con la tabla renombrada da false; restaurada, en el mismo proceso y sin reiniciar, da true |
| M-1 | CERRADA | `OutreachConsents.php:481`. [C] con `consents_record`+`marketing.view`: prospecto de un socio 404 y prospecto inexistente 404, con cuerpos idénticos byte a byte |
| M-2 | CERRADA | `:206`. [C] mismo `clientRequestId` con otra `legalHash` → 409 `client_request_reused`; idéntico → 200 `replayed` |
| M-3 | CERRADA | `ValidatePostSizeWithStudioCap.php:60`. [C autor, PG] `test_un_cuerpo_enorme_sin_sesion…` dentro de `OutreachConsentsApiTest` OK (24, 750); [C] `route:list`: `LimitRequestBodySize:16384` en las 6 rutas |
| M-7 | CERRADA | `OutreachConsents.php:108` + CHECK `ocon_evidence_chk`. [C] `<b>` → 422 `invalid`, 0 filas |
| B1 · controlador | CERRADA | `MarketingInboxController.php:484` (antes de `findConversation`) → 403 `:509-522`. [C] Administrador y «Ventas»+`marketing.manage`: 403 con `required_permission`, con el mismo cuerpo exista o no la conversación (sin oráculo); token compartido 403; 0 `complaint_dismissed` |
| B1 · servicio | CERRADA | `MarketingStaffReviewService.php:82-85`. [C] llamando directamente al servicio: Administrador, Recepción+`marketing.manage`+`release`, Super Admin INACTIVO e id inexistente → 403 `forbidden`; la revisión sigue pendiente y la queja frenando. Super Admin activo → descarta 1 |
| NB1 | CERRADA | `:100`. [C] con la revisión ya resuelta → 409 `staff_review_not_pending` |
| NB2 | CERRADA (si el CRM envía el campo) | `:106`, `aiComplaintAfter` `:144`. [C] queja de la IA posterior a lo visto → 409 `complaint_newer_than_seen`, 0 descartes; `abc` → 422; con el último id → 200 `dismissed: 1` |
| NB3 | CERRADA | `OutreachComplaintController.php:122-129`. [C] con tope 2: A llega a 429; B sigue en 201; repetir un `clientRequestId` ya hecho → 200 |
| NB6 | CERRADA | `StudioMessageTemplates.php:225, :1094`. [C] «No/Nunca/Jamás (nos/le) responda/escriba BAJA/STOP» → sin instrucción; «Si no desea…, responda BAJA» y «Responda BAJA para no…» → válidas |

## Lo nuevo que trajeron las correcciones
- **La llave `complaints_dismiss`** [C]. Está en `OutreachAccess::PERMISSIONS` (:46). En el catálogo, con etiqueta y ayuda (`PermissionCatalog.php:189, :278`); entra en `all()` por `AuthorizationMap.php:1065` (`CONTROLLER_ENFORCED`). Y en la exclusión del Administrador (`CrmPermission.php:344`).
  - Por defecto, las 4 llaves nuevas solo las tiene un Super Admin ACTIVO; ni Administrador, Recepción, Administrativo ni Entrenador.
  - El token compartido no la alcanza: `commercial_automation` está fuera de `readOnly()`, `allows(null, …)` da false y los dos chequeos exigen un `Admin`.
- **`numberRevoked`: SE QUEDA.** Solo lo ve quien ya tiene una llave `consents_*` y la de ver a esa persona y su número. Es un booleano sin ficha ni autor, y la ficha hermana se encuentra igual buscando el número en Mercadeo.
  - Si se quita, la lista muestra «concedida» con `effective: false` y sin motivo. El personal repetiría la petición o le escribiría por otra vía a un número que pidió no ser contactado: ese riesgo es mayor que el dato.
- **`ai_actions[].id` en el detalle del Inbox** (`MarketingInboxService.php:269`): no expone nada nuevo.
  - `GET admin/marketing/ai-actions` (`MarketingController.php:201`, puerta `marketing.view` [C]) ya da el `id` de TODAS las acciones.
  - El id solo se usa como marca `id > X` dentro de la propia conversación; nunca carga un objeto, así que no hay BOLA [L]. Claves del detalle: `id, action_type, status, reason, created_at` [C].
- **El 429 del tope diario** [C]: no sirve para contar las quejas de otra cuenta.
  - Solo cuenta las filas con `recorded_by` = la propia cuenta (`ComplaintCommercialEmitter.php:123-135`).
  - El cuerpo es `{ok, code, message, limit}` y `limit` es la configuración. Matiz en NB-2.
- **I-1 en la cobranza** [L+C]: no puede dejar de retener nada que antes retuviera.
  - `optedOut()` (`AssistedQueue.php:597-625`) da `true` si alguna señal es `true`; si no, `null` si alguna es `null`.
  - El lote solo convierte un `false` en `null` (`:610`) y añade una señal que solo puede valer `true` (`:613`). `AssistedQueueOptedOutTest` PG OK (65, 66).

## Las rutas del lote
- **Montaje** [L]: `bootstrap/app.php:57-59` monta `routes/commercial-automation.php` con `middleware('api')->prefix('api')`, igual que `marketing.php`, `ultron-studio.php` y `voice.php` (:36-52). Hereda así `ProtectAdminPaths` y `EnforceAdminAuthorization` del grupo `api`.
- **Las 6 rutas** [C `route:list`]: 5 de autorizaciones + `POST complaints`.
  - Cada una con `LimitRequestBodySize:16384` y `throttle:ultron-studio-read|write`, y con su llave en `AuthorizationMap.php:898-905`. Las de `{uuid}`, con `whereUuid`.
- **Registrar una queja** [L, C sondas]:
  - Orden de las comprobaciones: llave → 409 `commercial_events_disabled` (antes de validar y de leer a la persona) → 403 si no puede verla → 404 si no existe.
  - Medios y categorías de listas cerradas, sin texto libre; ids de hasta 12 cifras.
  - Ningún 4xx nuevo delata existencia, salvo NB4 (ya aceptado) y NB-2.

## Lo que no puede tocar, y el despliegue inerte
- **El lote no toca** [C `git diff --name-only`]: `UltronDecide`/`Commit`/`DraftCheck`, `Api/Internal/*`, el envío del Inbox, `routes/api.php`, `routes/marketing.php`, el OTP ni pagos.
  - De los middlewares solo cambia `ValidatePostSizeWithStudioCap` (un prefijo más). En `MarketingInboxController`, solo `resolveStaffReview` y sus `use`.
- **Resolver SIN el campo nuevo, igual que en producción** [C+L]. Probado con Recepción/«Ventas» y Administrador.
  - Con `{note}`, `{}` y `{complaint_category: null}` → 200 `{"ok":true,"staff_review_pending":false}` byte a byte.
  - Misma fila (`pending` false, quién y cuándo resolvió) y misma nota. Es la misma llamada a `resolve()` que antes (lo dice el diff). `InboxTest` OK (27, 132).
- **Con `COMMERCIAL_EVENTS_ENABLED=false` no cambia nada de esta parte** [L].
  - El observador de la IA sale en su primera línea (`MarketingAiActionCommercialObserver.php:66`).
  - Registrar y descartar dan 409 sin escribir.
  - No hay ningún `complaint_dismissed` que `EngineFacts` descuente.
- **Con el libro vacío**, `consentFor()` es la regla de antes (972 casos del autor, ya revisados).
- **Lo que sí cambia, a propósito y solo hacia lo estricto:**
  - (i) La bandeja retiene con `policy_unknown` dos tipos de trabajo, con o sin libro (I-1):
    - los de WhatsApp comercial cuyo consentimiento no está ni concedido ni denegado;
    - TODOS los de WhatsApp de cobranza.
    - La escalera ya los niega en N1, así que no debería haber ninguno vivo [S].
  - (ii) Al CREAR una plantilla, el Studio rechaza (`opt_out_text_missing`) la que marca la casilla de baja sin que el texto la pida, o con el texto negándola. Las ya creadas no cambian.
  - (iii) El detalle del Inbox gana `ai_actions[].id` y la Supervisión, una etiqueta nueva (aditivos).

## BLOQUEANTES
Ninguno.

## NO BLOQUEANTES (documentados; no se corrigen en este cierre)
- **NB-1 · La ruta de quejas no aplica el alcance del entrenador** [C, SQLite y PG].
  - Escenario: un Entrenador con `complaints_record`+`members.view` y sin asignaciones. `POST complaints` sobre un socio ajeno da 201 (y por su prospecto, 201), cuando la ficha de ese socio le da 404.
  - Causa: `OutreachComplaintController.php:154-167` no consulta `TrainerMemberScope`, a diferencia de C-3. La premisa de la revisión anterior («`members.view` es global») no es cierta.
  - No pasa nada mientras la llave no llegue al rol Entrenador: condición (2)(c).
- **NB-2 · El contador del tope delata «ya tenía una queja del personal hoy»** [C]. Una queja fusionada no cuenta.
  - Secuencia: B registra a P; A registra a X, a P y a Y → 201, 201, 201; a Z → 429.
  - Para enterarse hay que registrar una queja sobre esa persona, y queda `recorded_by`. Impacto bajo.
- **NB-3 · Si la migración aborta**, el motor sigue como antes (`hasLedger` false), pero las 5 rutas de autorizaciones responderían 500 a Super Admin [S, no ejecutado]. Lo evita C-B1.
- Siguen como estaban, sin reabrir: C-4, NB4, NB5, NB7-NB9 y M-4 a M-6.

## Sondas ejecutadas (propias; copiadas a `backend/tests/` solo mientras corrían y borradas)
- `ApisecL4ConsentProbeTest` (llaves, C-2, C-1 + I-1, C-3 + quejas, C-5, M-1/M-2/M-7): SQLite `OK (6 tests, 62 assertions)` · PG 14.24 `OK (6 tests, 62 assertions)`, con las mismas salidas.
- `ApisecL4ComplaintProbeTest` (B1 en el servicio; B1 en la ruta + sin el campo + token compartido; NB1/NB2/detalle; NB3 y su oráculo; NB6): SQLite `OK (5 tests, 18 assertions)` · PG `OK (5 tests, 18 assertions)`.
- Pruebas del autor, en PG (`ultron_studio_cierre_apiseclote4`, creada para esto y sin borrar):
  - `H9ComplaintDismissAuthorityTest` OK (6, 250) · `OutreachComplaintsApiTest` OK (13, 192) · `OutreachConsentsApiTest` OK (24, 750)
  - `H9ComplaintDismissalTest` OK (15, 148) · `H9AssistedEligibilityTest` OK (33, 492) · `AssistedQueueOptedOutTest` OK (65, 66)
  - Y en SQLite: `InboxTest` OK (27, 132).
- Fuentes y salidas: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/5d121b3e-fabc-4b0a-9d45-9f45d0f1592e/scratchpad/probes/`.

## Lo que NO pude verificar
- Producción: el valor de `COMMERCIAL_EVENTS_ENABLED`; si existe `iron_app`; `role_permissions` (si algún rol propio ya tiene llaves `commercial_automation.*`); y si hay trabajos de WhatsApp de cobranza vivos en la bandeja.
- El CRM, que aún no tiene las pantallas: si envía `last_seen_ai_action_id`, y cómo pinta `numberRevoked` y la evidencia.
- Las carreras de NB2 y NB3 con dos procesos reales (solo leídas). Las del libro, por las pruebas del autor en PG. La batería completa la corre el lead.
