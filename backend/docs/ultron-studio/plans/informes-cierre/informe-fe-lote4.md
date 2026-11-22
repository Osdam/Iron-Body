# Informe de frontend · Lote 4 del cierre (autorizaciones de contacto, quejas y los cuatro detalles)

22-11-2026 · frontend · árbol `_worktrees/cierre-crm/Iron-Body_Front`, rama `cierre/crm-lote4` (sale de `a9e7e7a`, lo que sirve producción). Sin red, sin producción, sin push. Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** suposición. Contratos leídos contra el árbol `lote-4` (`integracion/consent`) en `14cad6a9` y otra vez en `f7f00fdc` (la última tanda): la forma de las rutas no cambió; fixtures y catálogo de permisos, byte a byte iguales [C].

## Commits (uno por punto, más la base de las llaves)
`abfe86c` p4 catálogo y etiqueta · `a3a5cc9` p5 los cuatro detalles · `b0efe51` las cuatro llaves · `dd8db74` p1 autorizaciones · `f6b7442` p2 registrar queja · `9cdf999` p3 «¿Era una queja?».

**Alcance congelado (orden del lead, después de los commits):** quedan los puntos 1 a 4. El punto 5 ya estaba confirmado en `a3a5cc9` cuando llegó la orden; **se deja como está, no bloqueante, documentado**. No lo revierto (sin reset ni rebase): si el lead lo quiere fuera, es un `git revert a3a5cc9` aparte, solo con su orden. Tras la orden no añadí nada.

## Llaves (`b0efe51`)
`backend-permission-catalog.ts` regenerado con `php artisan permissions:export --path=…` desde `lote-4` (+4 en el catálogo y en Super Admin, nada más) [C]. Enum, exclusión del Administrador (como `CrmPermission`) y `CA_PERMISSIONS`. Solo deciden qué se ofrece.

## 1 · «Autorizaciones de contacto» — pestaña nueva de `marketing/automatizaciones` (entre «Historial por persona» y «Vínculos»)
- **Solo aparece con `consents_record` o `consents_confirm`** en la sesión; sin ellas, las nueve pestañas de hoy y `?pestana=autorizaciones` abre el Panel [C]. Abrirla no pide nada hasta elegir persona.
- **Buscar:** socio con el buscador de socios del CRM (`app-member-picker`, `GET /api/users`, exige `members.view`); prospecto por su número («Lead #…») o desde el Inbox con `?tipo=lead&id=` (validado `^[1-9][0-9]{0,11}$`). **No existe una búsqueda de prospectos en la API**: por eso el enlace del Inbox (punto 2).
- **Un carril por finalidad** (Comercial · Cobranza · Servicio) con estado, «Desde el {fecha}» (último movimiento de ese tipo entre los 200), pendientes (quién y rol, cuándo, cómo, evidencia, versión del texto legal) y «Últimos movimientos» plegado.
- **Estados (falla cerrado, `consentLaneView`):** «Concedida» (único verde) = `state granted` + `effective === true` + `numberRevoked === false` + `numberCurrent === true` + WhatsApp. Si no: «Concedida para un número anterior» · «Concedida, sin WhatsApp identificado» · «Concedida, sin comprobar el número» (`numberRevoked` ausente o no booleano) · «Concedida, sin efecto hoy» · «Retirada» · «Retirada para este número» · «Sin autorización» · «No se pudo leer» (estado desconocido, finalidad ilegible, o `none` con `effective: true`). Movimiento desconocido: «Movimiento que este CRM no reconoce (x)». `ownRequest`/`superseded` ilegibles = `true` (no se ofrece confirmar). `can` solo con `true` literal.
- **Pedir** (`can.record` + llave): enseña el texto legal vigente y su versión, exige «La persona leyó o escuchó este texto y lo aceptó», «Cómo la dio» (solo `sources` del servidor) y evidencia 3–300 validada antes de enviar con la regla del servidor (sin `<>`, sin 7 cifras ni series, sin correos; «escribe «22 de noviembre»»). Manda `{clientRequestId, personType, personId, purpose, source, evidence, legalVersion, legalHash}`. **Sin texto legal** (o a medias): «Todavía no hay un texto legal aprobado para esta finalidad: lo fija el propietario con su asesoría. Mientras tanto no se puede pedir.» y no hay botón. Sin número: no se ofrece y se dice. Con una pendiente: «Hay una petición pendiente: confírmala o recházala antes de pedir otra.»
- **Confirmar** (`can.confirm` + llave): no se ofrece y se dice por qué si es propia («Tú pediste esta autorización: tiene que confirmarla otra persona, con otra cuenta.»), retirada después, otro número u otro texto legal; manda `{clientRequestId}`. **Rechazar**: `{clientRequestId, evidence}`. **Retirar** (cualquiera de las dos llaves): aviso «Vale desde ahora y también para cualquier otra ficha que tenga el número de hoy de esta persona…», fuente de `revokeSources` y evidencia; manda las seis claves.
- **Códigos** (`CONSENT_ERROR_TEXTS`): 403 `forbidden` → «Tu cuenta no tiene permiso para esto. Hace falta el permiso «x».»; `four_eyes_required`, 404 `person_not_found`/`consent_not_found`, 409 `consent_legal_text_missing`/`_changed`/`no_phone`/`request_pending`/`_resolved`/`_superseded`/`number_changed`/`client_request_reused`, 413 y 429, cada uno con su texto. `consent_legal_text_changed` al pedir enseña el texto nuevo del 409, quita la conformidad y conserva lo escrito; los que dejan el formulario sin sentido lo cierran, avisan y releen. 422 `errors.<campo>` junto al campo, sin repetir valores. Respuesta con otra forma → «no se enseña para no dar por buena una autorización que no lo es».
- **Idempotencia:** misma clave al reintentar sin respuesta clara (red, 5xx, 429, ilegible); otra tras un 4xx definitivo o un 2xx (la regla del H5 de la sesión).
- **`numberRevoked` (para security):** solo con `true`: chip «Retirada para este número» y «Hay una retirada vigente para uno de los números de esta persona, registrada en otra ficha (desde aquí no se ve cuál). Mientras siga vigente, la automatización no le escribe por WhatsApp para esta finalidad.» Nada más: ni cuál, ni de quién, ni el número. Nunca se pinta un número ni un seudónimo (prueba: ninguna serie de 7 cifras ni 64 hex en pantalla [C]). Sí se ven la evidencia y nombre y rol del personal (lo manda el contrato), con interpolación de Angular (sin `innerHTML`).

## 2 · «Registrar queja» — `shared/components/complaint-record` (`ib-complaint-record`)
- **Dónde:** ficha del socio, en el pie a la izquierda de «Cerrar»/«Editar»; Inbox V2, panel del prospecto, pestaña «Cliente» › «Identidad», bajo el aviso de «no contactar», junto al enlace «Autorizaciones de contacto» (con las llaves de esa pestaña, `ultron.view` y la bandera del Studio).
- **Visible solo con** `complaints_record` Y `members.view` (socio) / `marketing.view` (prospecto, + `members.view` si es de un socio). Sin llaves no se pinta ni el contenedor (el panel queda como hoy) [C].
- **Diálogo:** «Registrar queja» · «Durante 30 días no se le enviará ningún mensaje automático comercial ni de cobranza. Los avisos de servicio siguen.» · «¿Cómo llegó la queja?» (En recepción · Por teléfono · Otro medio) · «¿Sobre qué?» (Atención · Cobros · Clases · Instalaciones · Otra). **Sin campo de texto** [C]. Cuerpo exacto `{clientRequestId, personType, personId, channel, category}`.
- **Al guardar:** «Queja registrada. El contacto automático queda en pausa hasta el {day + brake.days}.» **Igual en 201 y en 200 `replayed`** (nada delata otra queja); sin ventana legible, solo «Queja registrada.».
- **Códigos:** 409 `commercial_events_disabled` «El registro de quejas está apagado en el servidor. No se guardó nada.» · 429 `complaint_daily_limit_reached` «Ya registraste {limit} quejas hoy, el tope diario de una cuenta. Si hace falta registrar más, pídeselo a un Super Admin.» · 429 del cupo · 503 «No se pudo registrar. Inténtalo de nuevo.» · 403 con la llave · 404 · 404 sin código «Este servidor todavía no permite registrar quejas.» · 409 `client_request_reused` · 422 · red. Misma clave en red/5xx/429/ilegible.

## 3 · «¿Era una queja?» — `marketing-inbox-v2/staff-review-complaint.ts`
- **Discrepancia importante:** el Inbox V2 (el de producción) **no tenía «Resolver revisión»**; solo el V1, que no se sirve. Añadí «Resolver revisión» en la cabecera de la conversación **solo** con revisión pendiente de motivo `complaint` y `can_resolve_review`; el resto de revisiones siguen sin botón, como hoy. **Decisión del lead:** ¿también para las demás? El V1 no se tocó.
- Opciones: «Sin indicar» (por defecto, manda `{}` como hoy) · «Sí, era una queja» · «No era una queja» (**solo con `complaints_dismiss`**: «La automatización volverá a poder escribirle. Alcanza a TODAS las quejas que marcó la IA en esta conversación desde la última revisión, no solo a la última. Queda registrado que lo decidiste tú.») · «Otra cosa».
- `last_seen_ai_action_id` = el mayor `ai_actions[].id` **al abrir el diálogo** (sin ids, un servidor anterior, no viaja); solo con `not_a_complaint`.
- Resultado: «se descartaron N quejas…» / «No había ninguna queja de la IA pendiente que descartar.» / confirmada; sin `complaint` (o con otra categoría o sin número) → «este servidor no guardó la respuesta…»: **nunca «descartada»**.
- 403 `forbidden` «…hace falta «commercial_automation.complaints_dismiss». La revisión sigue pendiente.» · 409 `staff_review_not_pending` · 409 `complaint_newer_than_seen` «La IA marcó otra queja mientras decidías. Vuelve a abrir la conversación antes de decidir…» con «Recargar la conversación» · 409 apagado · 422 (`complaint_category_not_applicable` y el de Laravel sin código) · 503 · red.

## 4 · Catálogo (`abfe86c`)
`sync-h9-fixtures.mjs` (el guion del módulo; el informe del backend no lo encontró) desde `lote-4`: cambian exactamente `catalog-snapshot`, `conditions-cases`, `strategy-content-cases`, `trace-text-cases` y el manifiesto. Selectores de eventos (condiciones: evento y recuento; simulación): «complaint_dismissed · Se descartó una queja» y «complaint_created · La persona presentó una queja»; se guarda la clave. El constructor no lo ofrece (`triggerable: false`) [C]. Fijaciones de versión/huella del catálogo a `2026-11-22.1` (42 eventos).

## 5 · Los cuatro detalles (`a3a5cc9`): fuera del alcance congelado, ya confirmado antes de la orden; se deja (no bloqueante, documentado)
1 «…si la regla prohíbe frases y su «si» se cumple en esta versión, esas frases entran en el agente compilado.» (`studio-publication-text.ts:276,:284`) · 2 la aserción vacía ahora mira «Sin mando en el motor» · 3 los tres comentarios, de vuelta sobre su símbolo · 4 fuera la línea inalcanzable de `ca-panel.ts`.

## Compatibilidad con el servidor de hoy
Autorizaciones: 404 sin código → «Este servidor todavía no tiene las autorizaciones de contacto. El resto de la sección funciona igual.» [C]; la pestaña ni aparece sin las llaves, que hoy nadie trae. Queja y «No era una queja»: sin la llave no existen. «Sin indicar» manda el cuerpo de siempre. El 413 nuevo se trata solo en la superficie nueva (el gobierno y la ejecución, como antes [C]).

## Pruebas (componentes reales, sesión y servicios reales; solo se finge el servidor y la sesión)
- `commercial-automation/**` (con `IRON_BODY_BACKEND_DIR`): `Test Files 22 passed (22) · Tests 722 passed (722)`.
- `ultron-studio/**`: `Test Files 90 passed (90) · Tests 1419 passed (1419)`.
- Inbox V2 + `shared/components` + `models` + `access-control*` + ficha del socio + entrenadores: `Test Files 1 failed | 15 passed (16) · Tests 6 failed | 278 passed (284)`. **El rojo es `access-control.moderation.spec.ts` y es previo:** en un worktree temporal de `a9e7e7a`, `Tests 6 failed | 8 passed (14)`, los mismos 6 [C] (worktree quitado).
- Nuevas: `ca-consents.spec` (27), `commercial-automation-consents.spec` (13), `complaint-record.spec` (17), `staff-review-complaint.spec` (18), página (+3), editor de condiciones y catálogo (+2), permisos (+1).
- **8 mutaciones, 8 muertas** (sin la retirada por el número; `effective` no literal; olvidar siempre la clave, en autorizaciones y en quejas; prospecto de socio sin `members.view`; «No era una queja» sin llave; `last_seen` leído al enviar; «descartada» sin `complaint`), cada fichero restaurado con `cmp`.
- `node scripts/check-literals.mjs`: `425 ficheros revisados · literales correctos`. `ng build --configuration=production --output-path=<scratchpad>`: salida 0, «Application bundle generation complete»; 38 avisos de presupuesto/NG8107 previos (el de `member-details-modal` ya pasaba del tope; añadí ~50 bytes [S]).

## Lo que NO pude verificar
Ningún navegador contra un servidor real (ni el de hoy ni el del lote 4): ni la vista a 390/1 440 px, ni el foco con lector de pantalla, ni el buscador de socios dentro de la pestaña con datos reales. El 413 real del prefijo: si llega con el código y el texto de la imagen del OCR (render global de `bootstrap/app.php`) [S], el CRM pone el suyo.

## Lo que añadí para que los puntos funcionen (para que el lead lo valide)
- Punto 3: «Resolver revisión» en el Inbox V2, que no lo tenía (solo en revisiones de queja; ver arriba).
- Punto 1: el enlace «Autorizaciones de contacto» del panel del prospecto del Inbox (con las llaves), porque la API no tiene búsqueda de prospectos y sin él solo se llega escribiendo su número.

## Discrepancias documentos ↔ código y cosas para otros
- **Pedir tras retirar:** `record()` cuenta como pendiente toda petición sin resolver, también la `superseded`; hay que RECHAZARLA antes de pedir otra (409 `consent_request_pending`). La pantalla lo resuelve (ofrece «Rechazar» y dice por qué no se puede pedir), pero §4 no lo dice. **backend/lead.**
- El 422 de validación de la ruta del Inbox es el de Laravel (sin `code`); el 403 de B1 en el servicio no trae `required_permission` (solo si se llama fuera de la ruta).

## Hallazgos fuera de alcance (sin tocar)
- `access-control.moderation.spec.ts`: 6 rojos previos (los mismos en `a9e7e7a`).
- El banner del Inbox V2 dice «Necesita revisión: complaint» (el código crudo).
- `member-details-modal.ts` ya pasaba del presupuesto de estilos de 8 kB.
- **security:** el enlace del Inbox lleva el id del prospecto en la URL (como «Lead #id» de la bandeja asistida); `numberRevoked`, arriba; evidencia y nombres del personal en pantalla para quien tiene las llaves. **tester:** los flujos pedir → confirmar con otra cuenta, retirar con número compartido, registrar queja y descartar con una queja nueva en vuelo (NB2) contra el backend real.
