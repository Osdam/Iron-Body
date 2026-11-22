# Informe de backend · Quejas que registra o descarta una persona (Q1-8) y la línea de baja en el texto

**22-11-2026 · backend · rama `cierre/quejas` (sale de `e219d637`), árbol `_worktrees/cierre-quejas`.** Commits: B `8e1db038`, A `d0443bae`; la enmienda del contrato y este informe, en el commit de documentación siguiente. Sin red, sin producción, sin push. Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** suposición.

## Diseño corto de A (antes de escribir)

1. **Registrar** (`POST commercial-automation/complaints`, llave `commercial_automation.complaints_record`): el mismo `complaint_created` que la IA, con `source = staff`, medio y categoría cerrados, `recorded_by` y `client_request_id`; sin texto, teléfono ni nombre. Clave `<member|lead>:<id>:complaint:staff:<Ymd Bogotá>`: una al día por persona (la canónica: el socio si el prospecto es suyo). Lo escribe un emisor nuevo de la lista cerrada, `app/Observers/Commercial/ComplaintCommercialEmitter.php`: el H9 no puede escribir `commercial_*` (D9-1) ni importar el registrador.
2. **Descartar** (la ruta de resolver del Inbox, campo opcional `complaint_category`): `not_a_complaint` registra un `complaint_dismissed` por cada queja de la IA de ESA conversación posterior a la resolución anterior de su revisión, con dedupe `complaint_dismissed:<clave guardada de la queja>`: referencia la queja por su clave, idempotente, no borra nada.
3. **Leer** (`EngineFacts::events()`, la única puerta de los eventos del H9): con `complaint_created` pedido, lee en la misma consulta los `complaint_dismissed` hasta «ahora» y no cuenta una queja si y solo si un descarte nombra su clave Y su carga dice, legible, que es de la IA. Así N6, el despacho, la bandeja asistida (que no puedo tocar) y las condiciones lo ven igual, sin cambiar la escalera ni sus fixtures.
4. **Queja nueva tras un descarte**: la clave del día queda «gastada», así que el observador de la IA abre `…:<Ymd>:2`, `:3`… si la del día está descartada (y lo vuelve a mirar tras un duplicado, por la carrera).
5. **Falla cerrado**: sin datos legibles, la queja cuenta; un descarte que no queda da 503 y la revisión sigue pendiente (transacción con la conversación bloqueada).
6. **Contradicción marcada (B):** «Solo comerciales» frente a la fila `whatsapp` de §5 (D9-6) y D9-15 («la línea de baja en cada plantilla»). Tomé la lectura restrictiva: la comprobación vale para las tres finalidades (ver B).

## A · Qué cambió (`fichero:línea`)

| Fichero | Qué |
|---|---|
| `app/Services/Commercial/CommercialVocabulary.php:222`, `:230-232`, `:259` | `EV_COMPLAINT_DISMISSED` (en `EVENTS`), `COMPLAINT_SOURCE_STAFF`, `COMPLAINT_SOURCES_DISMISSABLE = [sales_agent, ultron]` (autorizado) |
| `app/Observers/Commercial/ComplaintCommercialEmitter.php` (nuevo) | `recordStaffComplaint()` `:126` (comprueba que la fila quedó: `merged` o `RuntimeException` `:156`); `dismissAiComplaints()` `:167` (fuente, conversación, `since`, ventana 730; comprueba que quedaron `:207`); `isDismissed()` `:83`; listas cerradas `:44-53` |
| `app/Observers/Commercial/MarketingAiActionCommercialObserver.php:112-143` | la serie `…:<Ymd>:n` tras un descarte, con la comprobación posterior al duplicado (`:135`) y el tope 20 |
| `app/Services/Outreach/Facts/EngineFacts.php:126-147`, `:200-237` | los descartes en la misma consulta (`:128`, `:131`), no salen si no se piden (`:142`), y `dismissed()` (`:222`: clave + fuente legible de la IA) |
| `app/Services/Outreach/Core/OutreachCatalog.php:45-57`, `:189` | `complaint_dismissed => []`; `VERSION = outreach-catalog/2026-11-22.1` |
| `app/Http/Controllers/Crm/CommercialAutomation/OutreachComplaintController.php` (nuevo) | `ROUTES` `:46`, `store()` `:75`, `person()` `:134` (la llave de ver ANTES de leer), 409/409/503 `:79`, `:106`, `:118` |
| `routes/commercial-automation.php:3`, `:23-30`, `:37` | monta la ruta, mismo grupo y tope de 16 KB |
| `app/Services/Marketing/MarketingStaffReviewService.php:17`, `:59-105` | `resolveComplaint()`: transacción + `lockForUpdate` (`:69`), 422 `:74`, 409 `:79`, 503 `:86`; `resolve()` sin cambios |
| `app/Services/Marketing/StaffReviewComplaintException.php` (nuevo) | error con la forma del Inbox (`ok`, `code`, `message`) |
| `app/Http/Controllers/Api/Admin/MarketingInboxController.php:470-489` | `complaint_category` opcional; sin él, la misma llamada y respuesta de siempre |
| `app/Http/Controllers/Api/Admin/SupervisionController.php:781` | etiqueta de `complaint_dismissed` (la prueba de etiquetas la exige) |
| `app/Services/Outreach/Run/OutreachAccess.php:39-43`; `app/Support/Access/AuthorizationMap.php:903-905`; `CrmPermission.php:338-341`; `PermissionCatalog.php:188`, `:276`, `:381` | la llave, en líneas propias junto a las del consentimiento |

Pruebas tocadas: `OutreachCatalogTest` (huella `.22.1`, 42/28 eventos), `OutreachCatalogEmittersTest` (+emisor, 23 eventos emitidos), `FixturesSanityTest` (28), `OutreachRoutesMapGuardTest::H9_KEYS` (+1), `OutreachConsentsApiTest` (rutas del prefijo: 5 + 1), `SupervisionActivityLabelsTest` (28), `CommercialSuppression1581Test` (paso 7). Nuevas: `OutreachComplaintsApiTest`, `H9ComplaintDismissalTest`.

**La llave y los roles.** Como las vecinas (`consents_*`): Super Admin la tiene por construcción; está en la lista de exclusión del Administrador; Recepción, Administrativo y Entrenador no la tienen [C, `OutreachComplaintsApiTest`]. Fuera del token compartido (dominio excluido) [C].

## A · Contratos

**`POST /api/admin/commercial-automation/complaints`** `{clientRequestId: uuid, personType: member|lead, personId: int, channel: in_person|phone|other, category: attention|billing|classes|facilities|other}` → **201** `{ok, data: {personType, personId, recordsAs: {type, id}, channel, category, source: "staff", day: "YYYY-MM-DD", brake: {days, purposes}}, meta: {replayed: false}}`; **200** igual con `replayed: true`. Errores: 403 `person_required` / `forbidden` (+`required_permission`), 404 `person_not_found`, 422 `validation_failed` (`errors.<campo> = ["invalid"]`, sin valores), 409 `client_request_reused`, 409 `commercial_events_disabled`, 503 `complaint_not_recorded`. Campos de más se ignoran (no se guardan).

**`POST /api/admin/marketing/inbox/conversations/{id}/staff-review/resolve`** `{note?, complaint_category?: complaint_confirmed|not_a_complaint|other}`. Sin categoría: `{ok, staff_review_pending: false}` como hoy. Con categoría: `+ complaint: {category, dismissed: n}`. Errores nuevos: 422 `complaint_category_not_applicable` (la revisión no es de queja), 422 de validación, 409 `commercial_events_disabled`, 503 `complaint_dismissal_failed` (la revisión sigue pendiente).

## A · Casos y pruebas (SQLite y PostgreSQL 14, clases reales)

| Caso | Prueba |
|---|---|
| Registrar → frena comercial (planificador real: `exit` N6, 0 trabajos, `replay` same) y cobranza (foto real → `complaintRecently` → `PolicyLadder::decide`: deny N6 `exit_complaint`); servicio igual que sin la queja; día 30 dentro, 31 fuera | `OutreachComplaintsApiTest::test_tras_registrarla_…` |
| Dos el mismo día → una; 23:59:59 mismo día; 00:00 otro; misma forma de respuesta | `…::test_dos_el_mismo_dia_…` |
| Idempotencia (200 `replayed`, también otro día; 409 con otro medio, categoría, persona o cuenta) | `…::test_idempotente_…` |
| Sin la llave → 403; con la llave sin ver socios → 403 exista o no; prospecto de socio con solo `marketing.view` → 403; 404; nada escrito | `…::test_sin_la_llave_…`, `…::test_la_ruta_…` (token compartido 403) |
| Sin texto libre (8 entradas malas; un `note` de más no se guarda) · lo que escribe, sin teléfono ni nombre · apagado 409 · registro que no queda 503 | `…::test_sin_texto_libre_…`, `…::test_registra_el_mismo_hecho_…`, `…::test_con_el_registro_…`, `…::test_si_el_registro_no_queda_…` |
| Descartada → deja de frenar; constancia (`dismissed_by`, `complaint_event_id`); la queja intacta; planifica y `replay` same; repetir descarta 0 | `H9ComplaintDismissalTest::test_not_a_complaint_…` |
| Sin categoría todo como hoy · `complaint_confirmed`/`other` siguen frenando · 422 si la revisión no es de queja · apagado 409 | `…::test_sin_categoria_…`, `…::test_complaint_confirmed_…`, `…::test_la_categoria_…`, `…::test_con_el_registro_apagado_…` |
| Descartar una de `staff` → se niega (el emisor no la toma; un descarte forzado con su clave no la quita: fuente) | `…::test_una_queja_del_personal_nunca_se_descarta` |
| Ni otra conversación ni lo ya resuelto (confirmada ayer) | `…::test_ni_otra_conversacion_…` |
| Queja nueva tras un descarte → vuelve a frenar, también el mismo día (`:2`, `:3`); la carrera entre comprobar y registrar | `…::test_una_queja_nueva_…`, `…::test_la_carrera_…` |
| Todo o nada: 503 y revisión pendiente; el segundo descarte falla → 0 descartes | `…::test_si_un_descarte_no_queda_…`, `…::test_si_falla_el_segundo_…` |
| Predicado: descarte posterior a «ahora»; carga sin fuente, ilegible, de otra fuente o `staff` → frena; otra clave; solo los tipos pedidos; un descarte no quita otro hecho; fuente apagada → null | `…::test_el_predicado_del_lector_…` |
| Supresión 1581: queja del personal, de la IA y descarte, escritos por el emisor real, se borran con la persona (3 hechos, 2 freno) | `CommercialSuppression1581Test` paso 7 (solo PG) |

**Líneas finales.** SQLite: `OutreachComplaintsApiTest` OK 9/134 · `H9ComplaintDismissalTest` OK 12/99 · `ComplaintEventWiringTest` OK 11/36 · `H9ComplaintRecentTest` OK 9/79 · `tests/Unit/Outreach` OK 1016/53248 · `tests/Feature/Outreach` OK 770/44597 (172 omitidas «solo PostgreSQL») · `tests/Feature/Commercial` OK 241/1115 (14 omitidas) · `InboxTest` OK 27/132 · permisos (21 ficheros que enumeran llaves) OK. PostgreSQL 14.24 (`ultron_studio_cierre_quejas`): `tests/Feature/Outreach` por subdirectorios, todo OK: Run 187/3040, Facts 67/2146, Guards 19/12514, Schema 341/2838 (1 omitida), B9Cierre 34/313, V9 72/23890 (27 omitidas), W9 50/1728 (1 omitida): 770 pruebas · `ComplaintEventWiringTest` 11/36 · `SupervisionActivityLabelsTest` 6/469 · `InboxTest` 27/132 · `CommercialSuppression1581Test` 1/379. **Un rojo de carga, repetido:** la primera pasada de `Feature/Outreach` entera en PG murió al 38 % con «Maximum execution time of 0+2 seconds exceeded» en `OutreachRaceTest.php:131` (la carrera de los contadores de presupuesto con conexiones asíncronas; no toca quejas). Aislada: OK 1/29; con la carga de la máquina en 6,5 durante la pasada.

**Mutaciones [C]** (en el árbol, una a una, restauradas con sha256): 22 mutantes, **20 muertos, 2 equivalentes**. Muertos: el lector sin la fuente, sin leer los descartes, dejándolos salir como eventos, descontando otros hechos; el emisor sin fuente, sin conversación, sin `since`, sin comprobar que quedó, la del personal sin el día, un fallo contado como hecho; el observador sin ninguna comprobación y sin la posterior; la ruta sin comparar al repetir, leyendo la persona antes de la llave, sin mirar el apagado; el Inbox sin mirar el motivo, sin transacción y con `other` descartando. Equivalentes: quitar la comprobación PREVIA del observador (la posterior hace avanzar la serie igual; solo ahorra un INSERT) y quitar `event === complaint_dismissed` al recoger claves (el registrador siempre antepone el nombre del propio evento, así que ninguna otra clave empieza por `complaint_dismissed:`).

## A · Puerta de diff (`OutreachIsolationTest`)

Cae, como se autorizó, con exactamente: `app/Services/Commercial/CommercialVocabulary.php`, `app/Observers/Commercial/MarketingAiActionCommercialObserver.php` y `app/Observers/Commercial/ComplaintCommercialEmitter.php` (nuevo). Antes del commit la prueba listaba los dos primeros (el nuevo estaba sin seguimiento); tras `d0443bae`, el `git diff --name-only origin/main` de la puerta da los tres [C]. No la he editado. Guardas: 141, 1 fallo (esa) [C].

## A · Fixtures compartidos con el CRM

Regenerados con `build.py` (antes reproducía byte a byte los 11 vigentes [C]) y la herramienta de exportación: `tests/fixtures/outreach/catalog-snapshot.json`, `conditions-cases.json` (cabecera y DF-C11), `strategy-content-cases.json` (cabecera, DF-K4 y un caso: `complaint_dismissed` como disparador → `trigger_not_allowed`) y `tests/Unit/Outreach/Core/fixtures/trace-text-cases.json` (solo `catalogVersion`; `dictionaryHash` `178ff1f0…` sin cambios). `policy-ladder-cases.json` y los demás, iguales. **El CRM, en este orden:** (1) `sync-h9-fixtures.mjs` para traer esos cuatro; (2) la etiqueta de `complaint_dismissed` donde pinte eventos del catálogo («Se descartó una queja»), y que el constructor no lo ofrezca como disparador (`triggerable: false`); (3) sus pruebas del espejo. [S: no encontré el guion en los árboles locales; los nombres salen del informe de Q1-2.]

## A · Para el CRM: textos sugeridos

- **Botón** en la ficha (socio o prospecto), solo si la cuenta tiene `commercial_automation.complaints_record` y la llave de ver a esa persona: «Registrar queja». Diálogo: «¿Cómo llegó la queja?» (*En recepción* `in_person` · *Por teléfono* `phone` · *Otro medio* `other`) y «¿Sobre qué?» (*Atención* `attention` · *Cobros* `billing` · *Clases* `classes` · *Instalaciones* `facilities` · *Otra* `other`). Sin campo de texto. Ayuda: «Durante 30 días no se le enviará ningún mensaje automático comercial ni de cobranza. Los avisos de servicio siguen». Al guardar (201/200): «Queja registrada. El contacto automático queda en pausa hasta el {day + brake.days}». 409 `commercial_events_disabled`: «El registro de quejas está apagado en el servidor». 503: «No se pudo registrar. Inténtalo de nuevo».
- **Al resolver una revisión marcada como queja** (`staff_review_reason = complaint`): «¿Era una queja?» con *Sí, era una queja* `complaint_confirmed` · *No era una queja* `not_a_complaint` · *Otra cosa* `other`. Con *No era una queja*: «La automatización volverá a poder escribirle. Queda registrado que lo decidiste tú». Respuesta `dismissed = 0`: «No había ninguna queja de la IA pendiente que descartar». Sin elegir, resuelve como siempre (no enviar el campo).

## B · La línea de baja en el TEXTO (`8e1db038`)

- `StudioMessageTemplates.php:10` (`use WhatsappSignals`), `:205-213` (`OPT_OUT_VERBS`, `OPT_OUT_FILLERS`), `:933` (mensaje), `:1048-1053` (la comprobación), `:1065` (`hasOptOutInstruction()`). Con `optOutLine: true` y un cuerpo válido, el cuerpo o el pie tienen que llevar un verbo de respuesta (responde, responda, escribe, envía, contesta y sus formas), quizá «con la palabra», y justo después unas palabras que `WhatsappSignals::isOptOutText()` acepta enteras. Si no: 422 `content.bodyPreview: opt_out_text_missing`. Lee la lista del motor; no la amplía. Ninguna guarda impide que el Studio nombre `WhatsappSignals` [C: 141 guardas en verde tras B].
- **Pruebas** (`MetaTemplateOptOutTextTest`, 8/718): las cinco propuestas válidas con su línea y rechazadas sin ella (MALA 2c); cuerpo o pie; el botón de baja solo no basta; SALIR, CANCELAR, NO, ALTO, BAJAS, BAJA sin verbo, «contáctenos» y «llame a recepción» se rechazan; mayúsculas, tildes, comillas, «con la palabra», saltos de línea, STOP, «No más mensajes», «Darme de baja»; cada frase del motor tras cada verbo; UTF-8 inválido no cuenta; la casilla sigue obligatoria; por la API 422 sin escribir. `MetaTemplateContentTest`: tres casos «válidos» sin ninguna instrucción de baja la llevan ahora, con el mismo propósito (componentes, límites exactos, tamaño: el peor caso da 7 180 de 8 192 bytes). SQLite y PG: MetaTemplates 67/1725; `Feature/Outreach` 749 en SQLite; 4/4 mutaciones muertas.
- **Servicio y cobranza.** La fila `whatsapp` de §5 exige `optOutLine: true` «(el texto dice cómo dejar de recibir mensajes)» sin distinguir finalidad, y D9-15 dice «la línea de baja en cada plantilla». Lo apliqué a las tres (lectura restrictiva; las cinco propuestas son comerciales). Si el lead lo quiere solo comercial, es una condición de una línea en `:1050`. Las versiones guardadas no cambian (las reglas solo corren al crear).
- **Fixtures del CRM:** ninguno cambia (las huellas golden de las plantillas siguen iguales [C]).

## Lo que security debe mirar primero

1. **Descartar levanta un freno** y lo puede hacer quien tenga la capacidad de resolver revisiones del Inbox (los roles comerciales la tienen). No hay llave propia (decisión pendiente, enmienda Q1-8 (b)).
2. **El predicado del lector** (`EngineFacts.php:222`): la única forma de que una queja deje de contar. Fuente leída de la carga de la propia queja; cualquier ilegible cuenta.
3. **Oráculo:** la respuesta del registro es igual haya o no otra queja ese día; 403 antes de leer a la persona. `replayed` solo delata la propia petición.
4. `client_request_id` y `recorded_by` viven en la carga de `commercial_events` (se borran con la persona en la supresión).
5. La foto completa del historial (`commercial_automation.timeline_view`) enseña ahora también `complaint_dismissed` (clave y hora, sin carga), en el mismo contenedor que `complaint_created` [L: `FactSelection::full()` pide todos los eventos del catálogo].

## Qué NO pude verificar

- El CRM (no lo toqué y no encontré su guion de sincronización en los árboles locales).
- `tests/Feature/Commercial` en PostgreSQL entero: el informe de Q1-2 deja 31 errores ajenos (uuid) en ese directorio; pasé en PG solo los ficheros que dependen de esto.
- La carrera «confirmar y descartar a la vez»: se serializan por el bloqueo de la fila; un `complaint_confirmed` posterior no deshace un descarte anterior [L].

## Hallazgos fuera de alcance

- `pint --test` ya fallaba en HEAD en `MarketingInboxController.php`, `SupervisionController.php` y `FixturesSanityTest.php` (mismos fixers) [C]; no los reformateé.
- Las quejas de la IA sin conversación (`lead:<id>:complaint:<Ymd>`, ruta interna de n8n) no se pueden descartar desde el Inbox: siguen frenando su ventana.
- Una queja resuelta sin categoría (clientes de antes) ya no se puede descartar después: la ventana empieza en la resolución siguiente (falla cerrado).
- B: un falso positivo posible es «responda no más tarde del…» (empieza por una frase del motor). La revisión a cuatro ojos sigue siendo la red.
