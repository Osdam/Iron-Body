# api-security · Q1-8 (registrar y descartar quejas) y línea de baja en el texto · revisión de un pase

22-11-2026 · api-security-specialist · árbol `_worktrees/cierre-quejas`, rama `cierre/quejas`, diff `e219d637..f247d1bd` (3 commits; solo este diff). Solo lectura: `git status` limpio al terminar. Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## Veredictos

**(1) Desplegar en producción: APTO CON CONDICIONES.**
1. `COMMERCIAL_EVENTS_ENABLED` sigue **apagada** en producción hasta cerrar B1. Con ella apagada, registrar y descartar responden 409 y no escriben (`OutreachComplaintController.php:78`, `MarketingStaffReviewService.php:78`) [L; C por las pruebas del autor en PG]. Si está encendida, o se enciende en este cierre, B1 se cierra ANTES de desplegar. No vi el valor de producción [S: el código dice «apagada hoy, R9-8»].
2. El CRM no publica el selector «¿Era una queja?» (ni envía `complaint_category`) antes de B1.
3. `commercial_automation.complaints_record` se queda solo en Super Admin (como llega) hasta las condiciones de (2).

**(2) Que el personal empiece a usarlo: NO APTO mientras B1 siga abierto. Con B1 cerrado: APTO CON CONDICIONES.**
1. B1 cerrado, con sus pruebas negativas (abajo) en verde en SQLite y PG.
2. La llave de registrar se da a cuentas con nombre, no a un rol entero como Recepción, hasta que exista NB3 (un tope diario por cuenta y una vista de Super Admin por `recorded_by`).
3. El texto del CRM al descartar dice que alcanza a TODAS las quejas que marcó la IA en esa conversación desde la última revisión (NB2).
4. El propietario decide si la línea de baja aplica a servicio y cobranza (pregunta 7).

## Bloqueante (1)

**B1 · Descartar (soltar el freno) llega a más gente que registrar (echarlo), sin llave propia ni nota · BFLA (OWASP API5) · [C]**
- **Dónde.** `MarketingInboxController.php:465` solo exige `CAP_RESOLVE_REVIEW`, y luego `:487` → `MarketingStaffReviewService.php:59-82`. La puerta de la ruta es `marketing.manage` (`AuthorizationMap.php:245` y el valor por defecto `{dominio}.manage`, `:1105`). La capacidad del Inbox va por nombre de rol (`MarketingInboxAuthorizationService.php:59-64`: super admin, administrador, admin, asesor comercial, asesor, ventas, recepción).
- **Quién puede hoy** [C, sonda A, SQLite y PG]:
  - 200 (descarta 1): Super Admin; **Administrador**; **Recepción + `marketing.manage`**; un rol propio «Ventas» + `marketing.manage`.
  - 403: Recepción, Administrativo y Entrenador por defecto (`marketing.manage`); «Mercadeo» + `marketing.manage` (`inbox_forbidden`); el token compartido.
  - Toda escritura del Inbox (enviar, tomar, resolver) ya exige `marketing.manage` en la puerta. Por eso **todo el que opera el Inbox en producción puede descartar** [L].
- **Contradice la política del propio código.** `CrmPermission.php:324-332` dice «soltar frenos: solo Super Admin por defecto» (deja `commercial_automation.release` fuera del Administrador) y «echar frenos sí llegan: son la dirección segura». Aquí es al revés: echar el freno (registrar) es solo de Super Admin, y soltarlo (descartar) llega al Administrador [C: `admin_tiene_release=false`] y a quien opere el Inbox. Sin nota, sin cuatro ojos y sin una llave visible en Configuración → Roles.
- **Escenario.** Un asesor con acceso al Inbox ve que la IA marcó como queja a un socio moroso o enfadado y la descarta (por error, por presión o a propósito). Vuelven los mensajes automáticos comerciales y de cobranza durante los 30 días en que la queja los paraba. Queda `dismissed_by` en la carga, pero el CRM no lo enseña.
- **Corrección mínima (backend).** Con `complaint_category = not_a_complaint`, exigir una llave de `commercial_automation` ANTES de abrir la transacción: 403 `forbidden` + `required_permission`, nada escrito y la revisión sigue pendiente.
  - Recomiendo una llave nueva, `commercial_automation.complaints_dismiss`: en los tres catálogos y en la exclusión del Administrador (solo Super Admin por defecto).
  - También vale reutilizar `commercial_automation.release`: ya existe y significa «soltar un freno».
  - Sin el campo, y con `complaint_confirmed` u `other`: sin cambios.
  - Las pruebas negativas, a **tester**. Qué llave usar lo decide el **propietario**.

## No bloqueantes (ninguno abre ronda)

- **NB1 · Se descarta sin revisión pendiente** [C, sonda C]. Con la revisión ya resuelta (motivo `complaint`, `pending=false`), `not_a_complaint` descarta la queja que la IA registró después: 200 `dismissed: 1`. `MarketingStaffReviewService.php:73` mira el motivo, no `staff_review_pending`. Arreglo de una línea: exigir revisión pendiente para `not_a_complaint` (409 `staff_review_not_pending`). Backend.
- **NB2 · El día se comprueba antes de usarse (TOCTOU)** [L]. Una segunda queja de la IA del mismo día y conversación se FUSIONA con la clave del día (`MarketingAiActionCommercialObserver.php:112-143`). Si llega antes del descarte, o dentro de su transacción tras la comprobación posterior, se descarta con ella, y un `complaint_confirmed` posterior no devuelve el freno. La ventana es corta y viene del diseño. Recomiendo que el CRM envíe la última acción de la IA que vio, y 409 si hay una queja más nueva. Backend + CRM.
- **NB3 · Abuso del registro** [L+C].
  - Los únicos topes son el cupo `ultron-studio-write` (30/min por cuenta, 120/min por red, `AppServiceProvider.php:617-620`) y una queja al día por persona. La ventana se puede renovar cada día.
  - `recorded_by` solo vive en la carga de `commercial_events` (el CRM no lo enseña), y el log `commercial.complaint.staff_recorded` no lleva a la persona.
  - Recomiendo un tope diario por cuenta (p. ej. 20 → 429) y una vista o consulta de Super Admin por `recorded_by`. Backend; el número lo pone el propietario.
- **NB4 · Oráculo prospecto↔socio** [C, sonda B]. Con la llave y `marketing.view`, sin `members.view`: prospecto enlazado → 403 `members.view`; inexistente → 404; suelto → 201, y escribe. La rama 201 obliga a escribir una queja, y es el mismo patrón de C9-3, ya revisado. Aceptable; si no, 403 uniforme sin `members.view`.
- **NB5 · Idempotencia de una petición FUSIONADA** [C, sonda E]. La segunda del día (con otro `clientRequestId`) responde 201 sin guardar su id. Repetida al día siguiente, crea una queja nueva (201, `replayed:false`, 2 filas) y alarga el freno un día. Además, el mismo `clientRequestId` enviado a la vez para dos personas deja dos filas: no hay índice único sobre `payload->client_request_id` [L]. Impacto bajo.
- **NB6 · Burla semántica de la línea de baja** [C, sonda F]. Pasan «No responda BAJA», «Nunca escriba STOP», «Si responde BAJA perderá todos sus beneficios» y «Responda STOP para recibir más ofertas». Recomiendo rechazar una negación (no, nunca, jamás) en las dos palabras anteriores al verbo; el resto lo para la revisión a cuatro ojos. Backend (Studio).
- **NB7 · La nota del Inbox se añade DESPUÉS del commit** (`MarketingStaffReviewService.php:101`): si falla, da 500 con el descarte ya hecho [L]. Igual que `resolve()` hoy.
- **NB8 · Volver a resolver pisa `staff_review_resolved_by/at`** [L], igual que `resolve()`. La constancia del descarte (`dismissed_by`) no se pierde.
- **NB9 · Logs tras la supresión 1581.** `commercial.complaint.dismissed` conserva `conversation_id` y `by` [L]. Hay que revisar la retención de los logs [S].

## Las ocho preguntas

1. **Autorización de la ruta nueva.**
   - **Llave:** `commercial_automation.complaints_record`, solo Super Admin por defecto [C, sonda B: Super Admin 201; Administrador, Recepción, Administrativo y Entrenador 403].
   - **Nivel de objeto:** la llave de ver a la persona se comprueba ANTES de leerla (`OutreachComplaintController.php:134-144`), y la de socios si el prospecto es de un socio [C]. Sin llave de ver: 403 exista o no [C, prueba del autor].
   - **Entrenador:** en este CRM `members.view` es global (no existe «socio de un entrenador»). Con la llave dada a mano registra sobre cualquier socio (201); sobre un prospecto le pide `marketing.view` (403) [C].
   - **Oráculos:** NB4. «Ya tenía queja ese día» no se delata por código ni por cuerpo (los dos dan el mismo 201) [C, autor]; por tiempo, la diferencia es un INSERT [S].
   - **Credenciales:** el token compartido recibe 403 [C, autor y sonda A]. `/api/admin/*` exige credencial administrativa (`ProtectAdminPaths`) [L].
2. **Abuso del freno.**
   - Sí se puede callar a propósito la cobranza de alguien: por defecto la queja frena comercial y cobranza (`OutreachConfig.php:253-261`), se renueva a diario y se puede hacer en masa a 30/min.
   - Lo acotan la llave solo de Super Admin, una queja al día por persona, el cupo, y `recorded_by` + `client_request_id` en la fila [C, sonda B: la carga].
   - La fila es inmutable desde la aplicación: ninguna ruta la edita ni la borra, salvo la supresión 1581 [L]. En la base no hay disparador que lo impida [S].
   - Recomendación: NB3 y la condición (2).2.
3. **Descartar.**
   - **Quién:** B1.
   - **Una queja del personal:** no se puede [C, `test_una_queja_del_personal_nunca_se_descarta` en PG; L `ComplaintCommercialEmitter.php:182-184`: clave `conversation:<id>:complaint:` + fuente de la IA].
   - **Otra conversación:** no (prefijo terminado en `:` + `payload.conversation_id` + `marketing_lead_id`) [C autor, L].
   - **Una ya confirmada:** no, porque `since` es la resolución anterior [C, sonda C: 2 quejas, 1 descarte].
   - **Teléfono compartido:** el descarte copia el sujeto de la propia queja y no alcanza a otra persona [L].
   - **Forjar un descarte:** solo la ruta del Inbox con sesión de persona llega a `dismissAiComplaints` y `resolveComplaint` (con `adminId` nulo, 403 `person_required`). Ninguna ruta ni comando acepta el nombre del evento: `ScanCommercialFacts` emite eventos fijos, y la ruta interna de n8n solo crea `MarketingAiAction` [L].
   - **El predicado** (`EngineFacts.php:200-232`): la queja cuenta salvo que su clave esté descartada Y su carga sea JSON legible con `source` en {`sales_agent`,`ultron`}, comparado estricto. Ilegible, sin fuente, `staff` u otra fuente: cuenta (falla cerrado) [L + C: 20/22 mutaciones del autor y su prueba del predicado en PG].
4. **Carreras.**
   - Dos registros a la vez: el índice único de `dedupe_key` (migración `:26`) deja una sola fila [L].
   - Descartar y confirmar a la vez: se serializan por `lockForUpdate` (`:69`) [L].
   - Queja nueva durante el descarte: NB2.
   - Mismo `clientRequestId` con otro contenido: 409 [C autor]; ver NB5.
   - Freno levantado sin constancia, o revisión resuelta sin descarte: ninguno de los dos es posible por la aplicación. El descarte se comprueba dentro de la transacción; si falta uno, 503 y vuelta atrás con la revisión pendiente [L + C autor `test_si_falla_el_segundo_…`].
5. **Datos personales.**
   - Sin texto, teléfono ni nombre. Los campos de más se ignoran: con `source`, `recorded_by`, `note`, `phone` y `conversation_id` en el cuerpo, la carga solo lleva `source=staff, channel, category, recorded_by, client_request_id` [C, sonda B].
   - Tipos erróneos → 422 sin repetir valores [C]. Tope de cuerpo de 16 KB [L].
   - Línea de tiempo: `complaint_dismissed` con clave y hora, sin carga, para quien tenga `timeline_view` [S, informe del autor].
   - Supresión 1581: la queja del personal, la de la IA y su descarte se borran con la persona [C, `CommercialSuppression1581Test` PG 1/379]. Ver NB9.
6. **La ruta del Inbox sin el campo.**
   - Misma respuesta (`{"ok":true,"staff_review_pending":false}`) y mismas escrituras: es la misma llamada a `resolve()`, que el diff no toca [C, sonda D; L].
   - `""` y `null` van por el camino de siempre [C].
   - Solo cambia con un valor que el CRM de hoy no envía (`"foo"` → 422) [C]. No bloqueante.
7. **Línea de baja.**
   - **Invisibles y bidi:** rechazados por `PlainText` estricto (`plain_text`) [C].
   - **Homoglifos cirílicos:** rechazados (falla cerrado) [C].
   - **Negrita matemática, ancho completo y letras sueltas:** pasan, pero se ven «BAJA» y el normalizador del motor los lee igual [C].
   - **Solo el botón:** rechazado [C, autor].
   - **Burla semántica:** NB6.
   - **Servicio y cobranza:** servicio sin la línea → `opt_out_text_missing` [C]; cobranza va por el mismo camino [L]. Es correcto y no excesivo: la casilla `optOutLine: true` ya era obligatoria para las tres finalidades, y esto solo exige que el texto diga lo que la casilla afirma. Si una «BAJA» a una plantilla de cobranza debe bajar a la persona de cobranza lo decide el propietario (W9) [S].
   - **Plantillas guardadas o ya enviadas a Meta:** no cambian. La validación solo corre al crear (`StudioMessageTemplates.php:701`), y `StudioMetaTemplates::content()` solo decodifica el JSON (`:1363`) [L].
8. **Lo que no puede romper.**
   - El diff no toca `UltronDecideService`, `UltronCommitService`, `Api/Internal/*`, el envío del Inbox, `routes/api.php`, `routes/marketing.php`, el OTP, los pagos ni ningún middleware [C, `git diff --name-only`].
   - En `MarketingInboxController` solo cambian `resolveStaffReview` y un `use`.
   - El cambio del observador de la IA corre en `afterCommit` dentro de su `try/catch` (no puede romper el commit de ULTRON) y añade una lectura por queja [L].
   - `EngineFacts` solo lo leen los hechos del H9 (`BatchSnapshotBuilder`, `MembershipFacts`), no el motor de WhatsApp [C, grep].

## Sondas ejecutadas (propias; copiadas a `backend/tests/` solo mientras corrían y borradas; `git status` limpio)

- `SecQuejasProbeTest`: A (quién descarta), B (quién registra, oráculo, campos de más y tipos), C (descartar sin revisión pendiente), D (Inbox sin el campo) y E (idempotencia de una petición fusionada). SQLite: `OK (5 tests, 7 assertions)`. PostgreSQL 14 (`ultron_studio_cierre_secquejas`, creada para esto y sin borrar): `OK (5 tests, 7 assertions)`, con las mismas salidas.
- `SecOptOutProbeTest`: F (14 frases, más servicio y cobranza). SQLite: `OK (1 test, 1 assertion)`.
- Pruebas del autor, en PG: `OutreachComplaintsApiTest` `OK (9 tests, 134 assertions)` · `H9ComplaintDismissalTest` `OK (12 tests, 99 assertions)` · `CommercialSuppression1581Test` `OK (1 test, 379 assertions)`.
- Fuentes y salidas: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/050e9589-ca66-4b1b-ae08-2df875813c5b/scratchpad/probes/` (`SecQuejasProbeTest.php`, `SecOptOutProbeTest.php`, `run1.txt`, `run2.txt`, `run3-pg.txt`).

## Lo que NO pude verificar

- `COMMERCIAL_EVENTS_ENABLED` y los `role_permissions` de producción (quién tiene `marketing.manage` y con qué rol), porque no tengo acceso a producción. Lo dice `SELECT role FROM role_permissions WHERE permission='marketing.manage' AND granted`, cruzado con los roles de `admins` activos.
- La carrera de NB2 con dos procesos reales: solo la analicé leyendo el código.
- El CRM, que todavía no tiene ni el botón ni el selector. Tampoco la línea de tiempo (`FactSelection`): no encontré su `full()` y lo tomo del informe del autor.
- Qué hace W9 con una «BAJA» recibida tras una plantilla de cobranza o de servicio (fuera de este diff).

## Decisiones del propietario

1. B1: una llave nueva `complaints_dismiss` (la recomiendo) o reutilizar `commercial_automation.release`; y si el descarte exige nota.
2. A quién se da `complaints_record` y con qué tope diario por cuenta (NB3).
3. Si una queja registrada por el personal debe frenar también la cobranza (hoy sí, `OutreachConfig::complaintPurposes()`).
4. Línea de baja en servicio y cobranza: en las tres finalidades (como hoy) o solo en la comercial (una condición en `StudioMessageTemplates.php:1050`).
5. Si se acepta NB4 (el oráculo prospecto↔socio, igual que en C9-3).

## Pruebas negativas para tester

- **B1, sin la llave de descarte** (Administrador, Recepción + `marketing.manage` y un rol «Ventas» + `marketing.manage`): `not_a_complaint` → 403 con `required_permission`, 0 `complaint_dismissed`, la revisión sigue pendiente y la queja sigue frenando. Con la llave → 200. `complaint_confirmed`, `other` y sin el campo → igual que hoy para todos ellos.
- **Token compartido y admin inactivo:** 403/401 sin escribir nada.
- **NB1:** `not_a_complaint` con `staff_review_pending=false` → 409.
- **NB6:** una plantilla con «No responda BAJA» → `opt_out_text_missing`.
