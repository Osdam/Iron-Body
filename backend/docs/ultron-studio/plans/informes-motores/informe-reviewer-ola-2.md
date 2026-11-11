# Informe del reviewer · delta de la ola (menores M-2, M-3, M-4b, exportador y fuga del teléfono)

Revisado: backend `1c8f70bb..b1959a05` (`backend/app`, `backend/tests`) y CRM `89a24f9..3f01c85`. Revisión de **solo lectura**: no he tocado código, pruebas, fixtures ni mandos. Solo he escrito este informe. Las mutaciones las hice con copias de las clases en el scratchpad, precargadas con `--bootstrap`. El árbol no cambió (`git status` es el mismo que al empezar).

## Veredicto: **APROBADO CON OBSERVACIONES**

- **M-2 cerrado: SÍ.**
- **M-3 cerrado: SÍ.**
- **M-4b cerrado: SÍ.** Queda una aserción vacía en la prueba de M-2 (R2-1). El código es correcto; lo que falla es la guarda.
- **Fuga de teléfono cerrada: SÍ.** No encuentro otro sitio por el que el número o una clave de teléfono llegue a una respuesta del Studio.
- **¿Impide algo desplegar con todo apagado? NO.**
- **¿Impide algo encender la maestra y la sincronización de plantillas? NO.**
- **¿Impide algo encender el envío a Meta? NO.** Recomiendo arreglar R2-1 antes, porque son dos líneas, y tener en cuenta N-1 en el paso 4 del runbook de activación.

Bloqueantes: **ninguno**. Importantes: **ninguno**.

## Hallazgos

| Id | Severidad | Qué | Dónde | Estado | Dueño |
|---|---|---|---|---|---|
| R2-1 | menor | La aserción «el registro no lleva el mensaje de la excepción» de la prueba de M-2 está vacía. `registrosDe()` guarda `json_encode($m->context)`, que escapa la tilde (`guardián`), así que `str_contains($l, 'guardián')` no casa nunca. **Mutante F** (meter `$e->getMessage()` en el contexto de `payload_refused`): la prueba sigue en verde (OK, 16 aserciones). En la respuesta pasa lo mismo con `guardián` (:380), pero ahí la salva `components[2]` (:381); en el registro no hay control ASCII. Arreglo: buscar `components[2]` en `$registros`, o `JSON_UNESCAPED_UNICODE` en `registrosDe()`. | `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateSubmitTest.php:357`, `:396` | confirmado | tester (o backend) |
| R2-2 | menor (texto) | §5.5.7 del plan dice «si Meta falla… la pasada termina con 0», pero el código ya sale con 1 en `meta_failed`. La decisión está en L9-105 y en el docblock de la orden; el plan se contradice. | `docs/ultron-studio/plans/PLANTILLAS_META_PLAN.md:595` | confirmado | lead / documentation-specialist |
| N-1 | nota, anterior al delta | Con el envío encendido y la sincronización apagada (paso 4 del runbook, `PLANTILLAS_META_PLAN.md:1071-1072`), un SENDING huérfano no se recupera desde el CRM. Puede quedar así por un proceso muerto entre el POST y el cierre, o porque la transacción de cierre de M-2 falla. `refreshBlockerCode()` da `meta_check_in_progress` para cualquier SENDING sin mirar su edad, y el CRM oculta «Comprobar ahora» cuando `allowed` es false. El servidor sí lo recuperaría (`refresh()` → `staleSending()`). Se resuelve con el paso 5 (sincronización) o con una versión nueva. Arreglo posible: que `refreshBlockerCode()` permita un SENDING de ≥ `SENDING_STALE_SECONDS`. | `app/Services/UltronStudio/StudioMetaTemplates.php:788`, `:495-499`; CRM `ca-tpl-detail.ts:340` | por lectura, no ejecutado | backend |
| N-2 | nota, anterior al delta; **no es un teléfono** | `meta.submission.metaTemplateId` sale en claro en el detalle (un id numérico de Meta de ≥ 15 cifras). `find_leaks` lo marcaría `phone_or_id` si un smoke abriera el detalle de una versión ya enviada. Hoy el smoke del H9 no abre detalles reales. Queda por decidir si la regla de ids opacos de §I2, escrita para entidades de anuncios, se aplica también a las plantillas. | `StudioMetaTemplates.php:1383`; `ops/smoke-h6.py:205-208`; `ops/smoke-h9.py:395-399` | a confirmar | architect / lead |

## Respuestas

### 1. ¿Otro sitio por el que salga el número o una clave de teléfono con «Llamar» encendido? **No encontrado.**

Revisé todas las salidas por lectura. Además, las tres fugas corregidas tienen cada una su mutante, y los tres mueren (pregunta 5).

**Lo que devuelven las rutas:**

- **Respuestas de plantillas.** Todas pasan por `detailDto()`: `show`, crear, aprobar y rechazar (`UltronStudioStrategyTemplateController.php:55,74,88,100`), y `meta/submit` y `meta/refresh` (`UltronStudioMetaTemplateController.php:122,142`).
  - `content` va por `publicContent()` (`StudioMessageTemplates.php:862`).
  - `waPreview` ya no copia `phone` (`:575-584`).
  - `preview` es solo `bodyPreview` (`:349-358`).
  - `restrictions` son códigos.
- **La lista.** `summaryDto()` (`:822-838`) más `summaries()` (`StudioMetaTemplates.php:689-725`): sin contenido.
- **`detailBlock()`.**
  - La cronología selecciona solo `event, summary, actor_name, created_at` (`:651-652`). Los `summary` son textos fijos con número y clave (`:434, :824, :843-860, :1008, :1016, :1306`).
  - `submissionDto()` (`:1371-1398`): sin teléfono y sin `request_hash`.
- **`status()`** (`:265-304`) y la sonda (`:338-351`): sin teléfono ni WABA.
- **Errores 422.** `invalid()` (`StudioMessageTemplates.php:884-893`) devuelve mensajes fijos y `{key, code}`; nunca el valor tecleado.

**El libro de auditoría.** `GET audit` devuelve el `diff` (`StudioAudit.php:226`), pero ningún `diff` de plantillas lleva contenido:

- `message_template.*`: `key/number/channel/purpose` (`StudioMessageTemplates.php:723`), `note` (`:785`), `decision` (`:753`);
- `meta_template.*`: `metaName/language/category` (`StudioMetaTemplates.php:435`), `detail` (`:825, :1009, :1017, :1308`), estados y códigos de Meta (`:851, :861-862`), `source/matches/attestedBy` (`:1079`), `mismatch` (`:1248`), la sonda (`:353-356`);
- `ip_address` y `user_agent` siempre NULL (`StudioAudit.php:179-180`).

**Otras salidas:**

- **SSE.** `LedgerTopic` solo selecciona `id, event, subject_*, to_status, content_hash, created_at` (`Stream/LedgerTopic.php:65-79`). No hay tema de plantillas en `Stream/`.
- **`StudioTemplateMetaChecks`.** `latest()` (`:321-333`) no lleva teléfono, y ninguna ruta HTTP lo usa (sin referencias en `app/Http`).
- **`StudioStrategyValidator`.** El `content` crudo de `context()['template']` (`:111-125`) solo se usa para comprobar (`:237-266`). Lo que sale es `templates` = `{usesPaymentLink, violations}` y hallazgos `{code, key, level}` (`:264, :277`).
- **`export-fixtures`.** No toca las plantillas de mensajes. El `phone_number` de `StudioIntegrationsExport.php:438` es la entrada de un simulacro de Twilio y no llega al fixture: el `backend-integrations.testing.ts` del CRM no tiene `phone_number`.
- **Registro de la aplicación.**
  - `StudioMetaTemplates.php:578, :820, :833, :960`: clase y códigos.
  - `GraphWhatsappTemplateClient.php:461-480`: códigos y tiempos, sin cuerpo.
- **W9.** Compone con `metaName/language/category/bodyPreview/variables` y no lee los botones (`WhatsappTemplateChannel.php:276-290`).

**La clave `phone_number`** solo aparece en tres sitios, y ninguno llega a un cuerpo del Studio:

- en `metaPayload()` (`StudioMessageTemplates.php:418`), que va a Meta por `create()` y del que solo se guarda su huella, `request_hash` (`StudioMetaTemplates.php:424`), que no se devuelve;
- en la lectura de lo que devuelve Meta (`MetaTemplate.php:55`);
- en la huella de componentes (`StudioMessageTemplates.php:509`).

**Lo que no hice:** recorrer todas las rutas con el botón encendido. La conclusión sale de leer el código, más la prueba nueva y tus pasadas de `find_leaks`.

### 2. `publicContent()` y `last4()` (ejecutado, funciones puras, sin arrancar la aplicación)

**`last4()`:**

| Entrada | Resultado |
|---|---|
| `null`, entero, `true`, `[]`, `''`, `'1234567'`, `'abc'` | `null` |
| `'+57 (314) 345-5483'` | `'5483'` |
| `'12345678'` | `'5678'` |
| solo cifras arábigas | `null` (`\D` sin `/u` las quita) |

Siempre devuelve 4 cifras ASCII o `null`, que es lo que exige el lector del CRM.

**`publicContent()`:**

- **Devuelve el contenido idéntico (`===`)**:
  - sin botones;
  - con `buttons` igual a `null`, a un texto o a `[]`;
  - con botones que no son arrays;
  - con respuestas rápidas y URL.
- **Con la clave `phone`, siempre la quita.** Solo añade `phoneLast4` si hay ≥ 8 cifras; con un `phone` entero, `null`, corto o en array, la quita sin añadir nada.
- **Con `buttons` asociativo**, conserva las claves y también lo enmascara.
- **Caso inalcanzable:** un `phoneLast4` ya guardado ganaría al calculado (por el `+`). No puede pasar, porque `keys()` rechaza claves inesperadas al crear.
- **Lo guardado y su huella no cambian:** `integrity` y `contentHash` se calculan sobre `content_json` (`:869`).
- **El CRM no recalcula la huella de una plantilla:** `canonicalJson` solo se usa con estrategias y condiciones.

### 3. M-2

- **Transacción.** Es una sola transacción de primer nivel (`StudioMetaTemplates.php:821-826`).
  - La de creación (`:414-438`) ya se cerró antes de `createAtMeta()`, que corre bajo el cerrojo de la versión (`:398`).
  - El modelo y el libro usan la conexión por defecto.
  - Si falla el `INSERT` del libro, el envío vuelve a SENDING. El `Log::error` ya se escribió antes (`:820`), y lo recoge `staleSending()` desde la sincronización (`:562-567`) o desde `refresh()` (`:495-499`); con la sincronización apagada, ver N-1.
- **Sin cierre doble:**
  - la `UltronStudioException` sale del `catch` (PHP no la recoge en el `catch (Throwable)` hermano), así que `settleCreate()` (`:449`) no corre;
  - FAILED es terminal (`liveSubmission()`, `:1315`) y `staleSending()` solo actúa sobre SENDING;
  - **mutante E** (dos cierres): muere.
- **Texto de la excepción.** No sale:
  - el registro lleva solo la clase (`:820`);
  - el texto del 422 es fijo (`:828`) y no encadena `previous`;
  - el `summary` es fijo (`:824`);
  - el `diff` es `{detail: payload_refused}`.

  La guarda de prueba del registro está vacía (R2-1).

### 4. M-3

- **Programador.** `routes/console.php:566-571` llama a la orden sin argumentos.
  - Solo `meta_failed` sale con 1, y solo lo produce el fallo de la lectura de la lista (`StudioMetaTemplates.php:577-584`). Con `--template` nunca hay `meta_failed`.
  - Con un 1, `ScheduleRunCommand.php:205-212` lanza la excepción, la reporta (una línea ERROR por pasada fallida, como mucho 288 al día) y emite `ScheduledTaskFailed`.
  - En `app` nadie escucha ese evento: no hay alarma más allá del registro.
- **Plan.** §5.5.1, §5.5.2 y «0 apagada» (`PLANTILLAS_META_PLAN.md:575-576, :814, :828`) se cumplen. §5.5.7 (`:595`) queda desfasado (R2-2).
- **Quién leía el código anterior: nadie más.**
  - Las pruebas de apagado siguen en 0 (`MetaTemplateRoutesTest.php:251-252`).
  - Las guardas fijan el texto de la sentencia, no el código (`UltronEngineIsolationTest.php:300`, `spec-programador.php`).
  - `deploy-m1-backend.sh` solo lee `schedule:list` (`:310`).
  - Ningún guion de `ops/` ejecuta la orden.

### 5. M-4b: ¿pruebas que fallarían sin el arreglo? **Sí, salvo R2-1.**

| Mutante | Prueba | Resultado |
|---|---|---|
| A · el `Throwable` deja FAILED en vez de UNKNOWN | `…revienta_queda_unknown…` | **muere** |
| B · el mensaje en el registro de `create_crashed` | ídem | **muere** |
| C · sin evento de cierre (lo de `1c8f70bb`) | `…rechaza_la_plantilla…` | **muere** |
| D · el mensaje en el texto del 422 | ídem | **muere** (por `components[2]`) |
| E · dos eventos de cierre | ídem | **muere** |
| F · el mensaje en el registro de `payload_refused` | ídem | **SOBREVIVE** (R2-1) |
| G · la orden siempre sale con 0 | `…sale_con_1…` | **muere** |
| H · `partial` también sale con 1 | ídem | **muere** |
| I · `detailDto` con el contenido crudo | `…boton_llamar…` | **muere** (`crear`) |
| J · `waPreview` vuelve a copiar `phone` | ídem | **muere** (`crear`) |
| K · el catálogo vuelve a llevar `phoneNumber` | ídem | **muere** (`catálogo`) |

La prueba del `Throwable` ya pasaba con `HEAD`, como dice el informe de backend. No es una tautología: A y B la tumban.

### 6. CRM

- **El lector estricto es coherente con el resto del módulo.** `entero`, `booleano` y `textos` también tumban la lectura vía `fallo()` (`commercial-automation-parse.ts:92-104`), y la spec lo dice («lo que el contrato fija no se adivina»). El servidor solo puede emitir 4 cifras o `null`.
- **Compatibilidad en los dos órdenes de despliegue:**
  - CRM viejo con backend nuevo: `phoneNumber` y `phone` ausentes, el lector viejo (`textoONulo`) da `null`;
  - CRM nuevo con backend sin `phoneLast4` (`728f208` o `1c8f70bb`): `ultimas4ONulo(undefined)` da `null`.
- **Consumidores del campo viejo: ninguno.**
  - `git grep` en `3f01c85`: `phoneNumber` solo aparece como `phoneNumberId` (H6, ajeno) y `.phone` solo en los módulos de socios.
  - `ca-wa-preview` no pinta el teléfono.
  - La «versión corregida» siembra `type/text/role/url` (`ca-tpl-editor.ts:478-479`) y serializa «Llamar» como `{type, text}` (`:636-638`). Nunca reenvía `phoneLast4`, que el servidor rechazaría.

## Verificado (ejecutado por mí)

- PHPUnit (SQLite, uno cada vez, con `nice`):
  - `MetaTemplateSubmitTest`: OK, 11 pruebas y 173 aserciones;
  - `MetaTemplateSyncTest`: OK, 14 y 132;
  - `MetaTemplateContentTest`: OK, 16 y 393.
- Los 11 mutantes de la tabla, cada uno con su filtro. El control sin mutar (`orig`) da OK.
- El guion de casos límite de `last4()`/`publicContent()`/`waPreview()`.

## No verificado

- CRM: `ng test` y `tsc` (por tu instrucción; doy por buenas tus 577 pruebas y `tsc`).
- PostgreSQL.
- La batería completa, Pint y las guardas estáticas (las corriste tú; no las repetí).
- El programador real con la bandera encendida.

Las copias mutadas están en el scratchpad de esta sesión (`…/scratchpad/mut/`). No hay secretos en ellas.
