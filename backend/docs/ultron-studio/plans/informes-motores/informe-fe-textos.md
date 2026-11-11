# Informe del frontend · Textos del CRM según la llave de prompts (T1–T10, INC-6)

**Fecha:** 11-11-2026. **Autor:** agente `frontend`. **Árbol:** `Iron-Body_Front` (worktree `ultron-h8`), rama `feat/ultron-studio-h9`, sobre HEAD `5f2a454`. **Sin commit, push, `build:prod` ni despliegue.** El backend y el catálogo (`StudioCatalog.php`) no se tocaron.

## Resumen para el lead

- **Hecho:** T1, T2, T3, T4, T5, T6 (lo que le toca al CRM), T7, T8, T9 (`:213`), T10 e INC-6. Todos siguen el patrón de tres variantes que ya usa `studio-publication-text.ts`: llave en `canary`, el texto nuevo; llave en `off` o un backend sin la clave `prompts`, el texto de siempre, literal; llave ilegible (`promptsUnreadable`), un texto que no afirma ni lo uno ni lo otro. T6, T7, T8, T9, T10 e INC-6 no dependen de la llave, así que dicen lo mismo con las dos.
- **Sin hacer, a propósito: T9 en `studio-integrations-text.ts:145`.** Es la ayuda del permiso `ultron.canary_manage`: una copia de paridad del literal del servidor (`PermissionCatalog.php:248`, contrato del H6 §I7.1, fixture `integrations-cases.json` y `UltronStudioIntegrationsPermissionsTest`). La compara `studio-integrations-parity.spec.ts:200` con el fixture exportado del backend, y **el CRM no la pinta en ningún sitio**: `studio-integrations-status.ts:114` solo usa `label` y `concedido`. Cambiarla solo en el CRM rompería la paridad sin cambiar nada de lo que se ve. La corrección es del backend (literal, fixture, prueba y enmienda del contrato del H6) y el CRM la recibe al volver a exportar el fixture.
- **Un fichero fuera de la lista:** `studio-integrations-page.spec.ts:105`. Es una sola línea: la etiqueta pintada de la clasificación `registry`, que cambia por T9. Si no se cambia, esa spec se pone en rojo. Si prefieres que no lo toque, se revierte esa línea y la spec queda en rojo hasta que la toque su dueño.
- **Datos que el servidor no manda y por eso no se afirman:** (1) T5: `status` no dice si la puerta del laboratorio está en `required` o en `waived`, así que el texto dice «si la puerta del laboratorio es obligatoria». (2) T9: el CRM sí recibe que las llamadas y los envíos automáticos están cerrados (`callsEnabledInCode` en la API de voz, `sendsEnabledInCode` en la de automatización comercial), pero no en la pantalla de Integraciones. Usarlo exigiría tocar ficheros que no son míos, así que el texto no dice si hoy están abiertos o cerrados.
- **Pruebas:** 11 specs de lo tocado, **144/144 verdes**. Las 47 specs que dependen de los ficheros tocados, **560/560 verdes**. `tsc` de la app y de las specs: 0 errores. `check-literals`: correcto (417 ficheros).
- **Textos adyacentes que siguen diciendo lo mismo de antes** (no los he arreglado; detalle en §5): la cabecera del panel de reglas (`studio-rules-panel.ts:75`), el aviso tras crear una regla (`:503`), las insignias «El motor no la aplica» (`studio-rules-panel.ts:103`, `studio-inspector.ts:243`), la nota de la pestaña Reglas del inspector (`:235`), «Nada de esta capa se lee en vivo: está fijo en código o en n8n» en «Personalidad» y la etiqueta «Prompts» del lanzador del laboratorio (`studio-lab-launcher.ts:117`).

## 1. Textos finales

«—» en la columna `off` = el texto de antes, literal, sin tocar.

| # | Dónde se ve | Antes | Llave en `canary` | Llave en `off` (o sin la clave) | Ilegible |
|---|---|---|---|---|---|
| T1 · efecto | Inspector → Configuración, fila de Tono, Usa emojis, Longitud máxima y Objetivo | el efecto del catálogo («Sin mando en el motor: …», «Sin interruptor…», «Sin límite…») | «Gobierna los prompts de ULTRON en la conversación canaria cuando una versión con este valor está publicada en canario. Fuera de esa conversación siguen los prompts de siempre.» | — (el del catálogo) | «Con la llave de prompts del motor encendida, la versión publicada en canario fija este valor en los prompts de ULTRON en la conversación canaria; apagada, no cambia nada. No se pudo leer cómo está.» |
| T1 · quién lo cambia | ídem | «Lo cambia de verdad: Prompts de n8n…» | «Lo cambia de verdad: publicar una versión en canario desde el Studio.» | — | «Lo cambia de verdad: publicar una versión en canario desde el Studio, si la llave de prompts del motor está encendida.» |
| T1 · propuesta | ídem | «Propuesta del borrador: el motor no la lee.» | «Propuesta del borrador: no llega a ULTRON hasta que una versión con este valor se publica en canario.» | — | «Propuesta del borrador: el motor no lee el borrador.» |
| T2 | ídem, línea «Motor (solo lectura)» | «Sin equivalente en el motor» | «Motor: lo fija la versión publicada en canario, solo en la conversación canaria. Rige ahora: «Tono profesional (propuesta)» (huella 702890f7).» Sin nada publicado: «… Rige ahora: Legacy, los prompts de siempre (no hay ninguna publicación).» Sale de `status.engine.prompts.current` (y del nombre en `engine.publication`), con `enginePromptsView()`, la misma función de la barra | — | «Motor: no se pudo leer la llave de prompts» |
| T3 | Inspector, cabecera de la capa (visible en todas las pestañas) de Personalidad, Estrategia, Construcción de respuesta y Control | (solo las notas del catálogo) | Nota «Motor»: «En la conversación canaria, los prompts de ULTRON los decide el Studio: con una versión publicada en canario, los de esa versión; sin ninguna, los de siempre. Cada turno anota la versión, la huella de los prompts y la revisión del flujo (se ven en «Turnos de la canaria»).» | sin nota | «Si en la conversación canaria los prompts de ULTRON los decide el Studio depende de la llave de prompts del motor, que no se pudo leer.» |
| T4 | Diálogo «Aprobar», descripción (y `aria-describedby`) | «Aprobar no publica nada: el motor de ULTRON no lee versiones del Studio.» | «Aprobar no publica nada. Solo una versión publicada en canario cambia lo que ULTRON dice, y solo en la conversación canaria.» | — | «Aprobar no publica nada.» |
| T5 | Aviso tras aprobar | «Aprobada. No cambia al agente: el motor de ULTRON no lee versiones.» | «Aprobada. Todavía no cambia al agente: para publicarla en canario hace falta un veredicto «Aprobada» del laboratorio sobre esta versión, dado por alguien que no la creó (si la puerta del laboratorio es obligatoria), y que la publique alguien que no la creó.» | — | «Aprobada. Aprobar no cambia al agente.» |
| T6 · franja | Laboratorio: franja de la página y de la ficha de la ejecución | el literal del servidor (lo cambia otro agente) | Copia de pruebas actualizada con el literal exacto (354 caracteres) | igual | igual |
| T6 · etiqueta | Ficha de la ejecución | «Prompts» | «Instrucciones del laboratorio» | igual | igual |
| T7 | Veredicto: junto a «Registrar el veredicto» y en el veredicto registrado | «No cambia la versión ni el motor.» | «No cambia la versión ni el motor por sí solo: un veredicto «Aprobada» es la condición de la puerta del laboratorio para poder publicar la versión en canario.» | igual | igual |
| T8 | Diálogo «Nueva regla del Studio» / «Editar», descripción | «Las reglas del Studio son propuestas: se guardan en el servidor, pero el motor de ULTRON no las aplica.» | «Las reglas del Studio son propuestas: se guardan en el servidor. ULTRON por WhatsApp no las aplica. En la voz, las frases prohibidas de una regla sí entran en el agente compilado.» | igual | igual |
| T9 (`:59`, ahora `:65`) | Integraciones → Números canarios: aviso de la pestaña y descripción del diálogo de registro (`TEXTO_CANARIOS`) | «Este registro no cambia a quién responde ULTRON: el motor sigue atendiendo solo la conversación canaria configurada en el servidor.» | «ULTRON por WhatsApp no lee este registro: a quién responde lo fija la conversación canaria del servidor. La automatización comercial sí: solo los números activos aquí entran en el alcance canario de una estrategia. En la voz, una saliente canaria solo puede llamar a un número de voz activo aquí.» (texto base del lead, literal) | igual | igual |
| T9 (`:213`, ahora `:222`) | Integraciones → Estado → Áreas → Números canarios, clasificación | «Registro del Studio (sin efecto en el motor)» | «Registro del Studio (lo leen la automatización comercial y la voz; ULTRON por WhatsApp, no)» | igual | igual |
| T9 (`:145`) | (no se pinta; paridad con el servidor) | — | **sin cambiar**: ver el resumen | — | — |
| T10 | Números canarios, versión que rige (motivo `no_voice_engine`) | «La voz no tiene motor en este hito: no rige ninguna versión.» | «La voz se rige por su propia cadena de publicación (Voz → Publicación), no por las versiones de WhatsApp.» | igual | igual |
| INC-6 | Inspector → Configuración, fila «Si no entiende» | efecto y quién lo cambia del catálogo; «Sin equivalente en el motor»; «Propuesta del borrador: el motor no la lee.» | Efecto: «En WhatsApp no aplica: qué hacer cuando no entiende lo decide el flujo (ULTRON pregunta, y el traspaso a una persona exige una causa corroborada, regla R-07). En la voz sí aplica: es lo que el agente responde a lo que no entiende.» · «Lo cambia de verdad: en WhatsApp, el flujo de ULTRON, no el Studio; en la voz, la versión del Studio con la que se compila el agente.» · Línea: «Sin equivalente en el motor de WhatsApp» · «Propuesta del borrador: en WhatsApp no tiene efecto aunque se publique.» | igual | igual |

Decisiones:

- **T3 va en la cabecera del inspector.** Así se ve en todas las pestañas y junto a `responsibility`, donde está la frase falsa de Control («No hay versiones publicables ni versión por turno»). Las otras notas del catálogo viven en «Configurable hoy» y «Fijo en código». No hay estilos nuevos: se reutilizan `in-nota` e `in-plano is-motor`, con `role="note"`.
- **T9 va en la etiqueta del área.** Primero lo puse en el hueco «motivo» (`providerReasonText`), pero lo quité: ese hueco se pinta en ámbar (`.st-motivo`), como un problema. La frase larga por canal no cabe en ningún sitio de los ficheros que puedo tocar. Propuesta para quien lleve `studio-integrations-status.ts` o `studio-canary-numbers.ts`: «En WhatsApp, este registro no decide a quién responde ULTRON: eso lo fija la conversación canaria configurada en el servidor. En la voz, decide a qué número puede llamar una llamada saliente canaria. En las campañas automáticas, decide a quién alcanza una estrategia en canario.» Está comprobado en `VoiceCanaryDirectory.php:13-67` y en `CanaryFacts.php:10-21`.
- **T7 nombra «la puerta del laboratorio»**, no un «siempre obligatorio». El servidor puede dispensarla (`ULTRON_STUDIO_PUBLISH_LAB_GATE=waived`, `config/ultron_studio.php:119`).
- **INC-6 no depende de la llave:** «Si no entiende» no la gobierna nunca, y el compilador de voz la usa siempre (`StudioVoiceCompiler.php:245`, `:415`).

## 2. Ficheros y líneas (todos en `src/app/modules/ultron-studio/`)

| Fichero | Qué |
|---|---|
| `studio-engine-text.ts:1-18` (imports), `:94-200` | `EngineStatusForPrompts`, `promptsKeyReading()` (`:113`), `ParamEngineText`, `rigeAhora()` (`:133`, sobre `enginePromptsView`), `FALLBACK_PARAM_TEXT` (`:145`), `paramEngineText()` (`:160`), `layerPromptsNote()` (`:192`). `engineLineText()` sin cambios |
| `studio-param-row.ts:3`, `:119-122`, `:168-185` | Entrada `engineStatus` y `textos` computado; la plantilla elige el texto del CRM o el del catálogo |
| `studio-inspector.ts:10`, `:96-99`, `:206`, `:410-415` | Nota de capa en la cabecera; pasa `session.status()?.engine` a la fila |
| `studio-versions-panel.ts:30-75`, `:307`, `:395`, `:565` | `approveDialogText()` y `approvedNoticeText()` con sus tres variantes; descripción del diálogo y aviso |
| `studio-lab-text.ts:58-66` | `LAB_VERDICT_EFFECT_TEXT` |
| `studio-lab-verdict.ts:6`, `:39`, `:85`, `:101` | T7 en los dos sitios |
| `studio-lab-run-view.ts:64` | «Instrucciones del laboratorio» |
| `studio-lab-fixtures.testing.ts:42-44` | `LAB_FIDELIDAD`, el literal nuevo |
| `studio-rules-panel.ts:138` | T8 |
| `studio-integrations-text.ts:58-66`, `:220-222`, `:426` | T9 (`TEXTO_CANARIOS` y etiqueta) y T10 |
| Specs | `studio-engine-text.spec.ts`, `studio-param-row.spec.ts`, `studio-inspector.spec.ts`, `studio-versions-panel.spec.ts`, `studio-lab-verdict.spec.ts`, `studio-lab-run-view.spec.ts`, `studio-lab-page.spec.ts`, `studio-rules-panel.spec.ts`, `studio-integrations-text.spec.ts` y, fuera de la lista, `studio-integrations-page.spec.ts:105` |

`studio-lab-page.ts` no hizo falta tocarlo. El lector del laboratorio no recorta ni rechaza la franja: `studio-lab-parse.ts:289` y `:712` la leen con `texto()` sin `max` (`studio-parse.ts:321`). Las pruebas lo confirman con el literal entero, a través del lector real.

Hay ficheros modificados en el árbol que **no son míos** y no he tocado: `commercial-automation/*`, `activation-state.ts/.spec.ts`, `studio-voice-activation.ts`, `studio-voice-status.ts` y `studio-voice-text.ts`.

## 3. Pruebas y comprobaciones (salida real)

Las pruebas usan los componentes y funciones de producción. Cada `status` pasa por el lector real (`readStatus` o la sesión con `HttpTestingController`), con los fixtures de M-1: `estadoPromptsDto`, `motorPromptsDto` y `motorPromptsApagadoDto`. El caso ilegible es `key: 'all'`, que el lector convierte en `promptsUnreadable`.

| Comando | Resultado |
|---|---|
| `ng test` con las 11 specs de lo tocado (más la de paridad y la de la página de integraciones) | **Test Files 11 passed (11) · Tests 144 passed (144)** |
| `ng test` con las 47 specs que importan los ficheros tocados o montan sus componentes (incluidas `ultron-studio-page`, `studio-hostile-text*`, `studio-lab-*`, `studio-integrations-*`, `studio-publication-bar-prompts`, `studio-versions-panel-publication` y `studio-voice-guards/panel`) | **Test Files 47 passed (47) · Tests 560 passed (560)** |
| `npx tsc -p tsconfig.app.json --noEmit` | exit 0 |
| `npx tsc -p tsconfig.spec.json --noEmit` | exit 0 |
| `node scripts/check-literals.mjs` | «417 ficheros revisados · literales correctos» |
| Avisos del compilador en `ultron-studio/` | 0. Los 8 NG8107 son de `components/`, `marketing-inbox-v2/` y `reports/` |

Lo que cubren las pruebas nuevas:

- **`studio-engine-text.spec.ts`:** la llave en sus tres estados. Los cuatro gobernados con `canary` (V2 con nombre, Legacy con motivo y versión sin nombre), con `off`, sin clave y con `null`. Ilegible. INC-6 con las cinco variantes. Que el resto de parámetros no cambia. T3 por capa.
- **`studio-param-row.spec.ts`:** la fila real con `canary`, `off`, sin clave e ilegible, «Si no entiende» con `canary` y con `off`, y que el conductor y el Critic no cambian.
- **`studio-inspector.spec.ts`:** la nota en las cuatro capas y en otra pestaña; que no sale en las otras seis capas; sin nota con `off` y sin clave; ilegible; la fila recibe la llave de la sesión.
- **`studio-versions-panel.spec.ts`:** aprobar de verdad, con petición y relectura, con `canary`, `off` e ilegible. Comprueba la descripción, el `aria-describedby` y el aviso, más las funciones puras sin clave.
- **Laboratorio:** T7 en el formulario (y la descripción del botón) y en un veredicto registrado. T6 entero en la ficha y en la página.
- **Reglas:** T8 con `canary` y con `off`.
- **Integraciones:** T9 y T10 con el motivo `no_voice_engine` del fixture real del H6.

## 4. Lo que no pude verificar

- **No he visto ninguna pantalla en un navegador** (ni local ni producción), solo jsdom. Falta comprobar a ojo cómo envuelve la etiqueta de T9 en móvil y cómo queda la nota de T3 en la cabecera.
- **El texto real que pinta el servidor en T6** depende del cambio del otro agente. Aquí solo se comprobó la copia de pruebas y que el CRM pinta entero cualquier texto de esa longitud.
- **No releí producción.** Que la llave sea `canary` hoy sale del encargo y de la auditoría.
- **No verifiqué dónde pinta Configuración → Roles la ayuda de `ultron.canary_manage`** (si lo hace, sale del servidor).
- No lancé la batería completa, por orden del lead.

## 5. Adyacente, visto y no tocado

1. `studio-rules-panel.ts:75`, cabecera del panel: «Las del Studio son propuestas: el motor de ULTRON no las aplica.» Es la misma afirmación que T8. La destapó la prueba de T8, que acoté al diálogo.
2. `studio-rules-panel.ts:503`, aviso tras crear: «Regla S-NNN creada en borrador. El motor no la aplica.»
3. Insignias «El motor no la aplica» (`studio-rules-panel.ts:103`, `studio-inspector.ts:243`) y nota de la pestaña Reglas del inspector (`studio-inspector.ts:235`, «las del Studio son propuestas que el motor no lee»). Son ciertas para WhatsApp; en la voz, las frases prohibidas sí entran.
4. Inspector → Resumen → «Motor ahora» de Personalidad: «Nada de esta capa se lee en vivo: está fijo en código o en n8n.» Con `canary`, tono, emojis y longitud los fija la versión publicada. La nota de T3 lo matiza, pero la frase sigue.
5. `studio-lab-launcher.ts:117`: la misma etiqueta «Prompts» para la versión de las instrucciones del laboratorio. Tiene la misma confusión que T6 y queda fuera de mi lista.
6. `studio-engine-text.ts:177`: el efecto de la lectura `governance.studioPublication` con la llave apagada («En este hito no cambia lo que ULTRON dice ni hace»). Ya es correcto según la llave (`studioPublicationReading()`); lo cito solo como precedente.

## 6. Segunda tanda: precisión del lead para T9 (11-11-2026)

**El encargo:** el registro SÍ lo lee la automatización comercial (H9); `:59`, `:145` y `:213` son T9, con el texto base del lead.

**Hecho:**

- `:59` (`TEXTO_CANARIOS`, ahora `studio-integrations-text.ts:58-66`): el texto base, literal. Lo pinta `studio-canary-numbers.ts:220` en el aviso de la pestaña (`[data-texto-canarios]`) y en la descripción del diálogo de registro.
- `:213` (ahora `:222`): la etiqueta, alineada con el texto base: «Registro del Studio (lo leen la automatización comercial y la voz; ULTRON por WhatsApp, no)».
- Dato de la consigna, corregido: «El motor no los lee en este hito» no está en `:59` sino en `:145`. `:59` decía «Este registro no cambia a quién responde ULTRON: el motor sigue atendiendo solo la conversación canaria configurada en el servidor.».

**`:145`, sin cambiar otra vez, por la misma razón.** Es la ayuda del permiso `ultron.canary_manage` y el CRM no la pinta (`studio-integrations-status.ts:114`). Es la copia del literal del servidor: `app/Support/Access/PermissionCatalog.php:248`, `tests/fixtures/ultron-studio/integrations-cases.json`, `tests/fixtures/ultron-studio/tools/integrations-cases/sec_api.py`, `UltronStudioIntegrationsPermissionsTest.php:196` y el contrato del H6. `studio-integrations-parity.spec.ts:200` la compara con `testing/backend-integrations.testing.ts`, que lleva «GENERADO — no editar a mano» y lo produce `ultron-studio:export-fixtures`. En el backend nadie la está cambiando ahora (`git status` limpio en esos ficheros). Cambiarla solo en el CRM rompe esa paridad, y tocar el fixture a mano falsearía lo que dice el servidor. Para corregirla: el backend cambia el literal (propuesta: sustituir «El motor no los lee en este hito: ULTRON sigue atendiendo solo la conversación canaria configurada en el servidor.» por el texto base), su fixture, su prueba y el contrato, y vuelve a exportar los fixtures. Después el CRM copia el literal en `:145`, y la prueba de paridad lo confirma.

**Lo que rompe fuera de mis ficheros:** `e2e/ultron-studio/specs/h6-integrations.cjs:48` copia el literal antiguo de §I10 «del CONTRATO (no del código del CRM)». Lo comprueban `E8` (`:718`) y `E6` (`:1174`), que fallarán la próxima vez que se lance ese E2E. Necesita la enmienda de §I10 del contrato del H6 y que su dueño actualice el E2E. No lo he tocado.

**Pruebas, una spec cada vez:**

| Spec | Resultado |
|---|---|
| `studio-integrations-text.spec.ts` | 14/14 |
| `studio-integrations-page.spec.ts` | 9/9 |
| `studio-canary-numbers.spec.ts` (pinta `TEXTO_CANARIOS` en la pestaña y en el diálogo) | 10/10 |
| `studio-integrations-parity.spec.ts` (`:145` sigue igual al servidor) | 18/18 |
| `studio-integrations-hostile-text.spec.ts` | 5/5 |

`tsc` de la app y de las specs: exit 0. `check-literals`: correcto (417 ficheros). No he abierto los ficheros de `fe-estados` (`commercial-automation/*`, `activation-state.ts`, `studio-voice-activation.ts`, `studio-voice-text.ts`, `studio-voice-status.ts`).

## 7. Tercera tanda: los adyacentes de reglas, inspector y laboratorio (11-11-2026)

Las reglas del Studio dicen lo mismo con cualquier llave: ULTRON por WhatsApp nunca aplica una S-NNN, y el agente de voz compilado sí lleva sus frases prohibidas. «Motor ahora» de Personalidad sí depende de la llave.

| # | Dónde (fichero:línea final) | Antes | Después con `canary` | Después con `off` (o sin la clave) | Ilegible |
|---|---|---|---|---|---|
| 1a | Panel de reglas, cabecera (`studio-rules-panel.ts:76`, `data-reglas-intro`) | «Las de **Código** las aplica el motor y aquí solo se leen. Las del **Studio** son propuestas: el motor de ULTRON no las aplica.» | «Las de **Código** las aplica el motor y aquí solo se leen. Las del **Studio** son propuestas: ULTRON por WhatsApp no las aplica; en la voz, sus frases prohibidas sí entran en el agente compilado.» | igual | igual |
| 1b | Insignia de una regla del Studio (`studio-rules-panel.ts:104-108`; `studio-inspector.ts:254-258`) | «El motor no la aplica» | A la vista: «WhatsApp no la aplica». En `title`: «ULTRON por WhatsApp no la aplica. En la voz, sus frases prohibidas sí entran en el agente compilado.» Para el lector de pantalla, un `visually-hidden` tras la insignia: «. En la voz, sus frases prohibidas sí entran en el agente compilado.» Es el patrón de la marca «≠ motor» de la fila de parámetro. `data-regla-aplica="none"` | igual | igual |
| 1c | Insignia de una regla del código | «La aplica el motor» | sin cambiar (`data-regla-aplica="code"`, sin `title`) | igual | igual |
| 1d | Aviso tras crear una regla (`studio-rules-panel.ts:512`) | «Regla S-NNN creada en borrador. El motor no la aplica.» | «Regla S-NNN creada en borrador. ULTRON por WhatsApp no la aplica.» Lo de la voz no se dice aquí porque una regla recién creada solo entra en el agente de voz cuando se compila una versión que la lleve | igual | igual |
| 1e | Inspector → Reglas, nota (`studio-inspector.ts:246`, `data-reglas-nota`) | «Reglas del servidor. Las de «Código» las aplica el motor; las del Studio son propuestas que el motor no lee.» | «Reglas del servidor. Las de «Código» las aplica el motor; las del Studio son propuestas: ULTRON por WhatsApp no las aplica y, en la voz, sus frases prohibidas sí entran en el agente compilado.» | igual | igual |
| 2 | Inspector → Resumen → «Motor ahora» de Personalidad (`studio-inspector.ts:127`; texto en `studio-engine-text.ts:184-206`, `noLiveReadingsText()`) | «Nada de esta capa se lee en vivo: está fijo en código o en n8n.» | «Nada de esta capa se lee en vivo. El tono, los emojis y la longitud máxima los fija la versión publicada en canario, solo en la conversación canaria; fuera de ella siguen los prompts de siempre.» | — (el de siempre; las demás capas sin lecturas en vivo, también) | «Nada de esta capa se lee en vivo. Si el tono, los emojis y la longitud máxima los fija la versión publicada en canario depende de la llave de prompts del motor, que no se pudo leer.» |
| 3 | Lanzador del laboratorio, desglose de la estimación (`studio-lab-launcher.ts:117`) | «Prompts» | «Instrucciones del laboratorio» (`data-lab-estimacion-instrucciones`) | igual | igual |
| 4 | E2E `e2e/ultron-studio/specs/h6-integrations.cjs` | `TXT.canarios` (`:48`) con el literal antiguo de §I10 | Comentario en `:48` y el literal nuevo en **`:49`**, idéntico a `TEXTO_CANARIOS` (296 caracteres, comprobado con `node`). Lo usan `E8` (**`:719`**) y `E6` (**`:1175`**). `node --check`: sintaxis correcta. **No lo he lanzado** | — | — |

Las tres insignias, el `title` y el texto oculto salen de unas constantes en `studio-engine-text.ts:208-216` (`STUDIO_RULE_BADGE`, `STUDIO_RULE_BADGE_TITLE` y `STUDIO_RULE_BADGE_HIDDEN`), que comparten el panel de reglas y el inspector. `.visually-hidden` es la de Bootstrap, global (`src/styles.css:1`). No hay estilos nuevos: la insignia del código conserva `is-motor`, ahora como clase fija dentro de su rama.

**Pruebas, una spec cada vez (salida real):**

| Spec | Resultado | Qué se añadió o cambió |
|---|---|---|
| `studio-engine-text.spec.ts` | 17/17 | `noLiveReadingsText` con `canary` (V2 y Legacy), `off`, sin clave, `null` e ilegible, y las otras cuatro capas sin lecturas; las constantes de la insignia |
| `studio-inspector.spec.ts` | 22/22 | Insignia (texto, `visually-hidden`, `title`, `data-regla-aplica`), nota de Reglas y R-02 sin `title`. «Motor ahora» de Personalidad con `canary` (y Contexto igual), con `off` y con la llave ilegible. Reglas con `canary` |
| `studio-rules-panel.spec.ts` | 17/17 | Cabecera, insignia de S-001 y R-02 y aviso tras crear (`:191`, `:331` actualizadas). Cabecera e insignia con `canary` y con `off` |
| `studio-lab-launcher.spec.ts` | 12/12 | «Instrucciones del laboratorio» en el desglose; «Prompts» ya no |
| `studio-lab-run-view.spec.ts` | 10/10 | (regresión) |
| `ultron-studio-page.spec.ts` | 24/24 | (regresión: monta el inspector) |
| `studio-hostile-text.spec.ts` | 12/12 | (regresión) |
| `studio-lab-page.spec.ts` | 9/9 | (regresión: monta el lanzador) |
| `studio-lab-dom.spec.ts` | 2/2 | (regresión) |
| `studio-param-row.spec.ts` | 14/14 | (regresión) |

`npx tsc -p tsconfig.app.json --noEmit`: exit 0. `npx tsc -p tsconfig.spec.json --noEmit`: exit 0. `node scripts/check-literals.mjs`: «417 ficheros revisados · literales correctos».

**No verificado:** no he visto las insignias ni la nota en un navegador. Falta mirar a ojo el `title` y que la insignia más larga no desborde en móvil. El E2E `h6-integrations` no se lanzó, por orden del lead.

**Adyacente, visto y no tocado (fuera de mi lista):**

1. `studio-rules-coherence.ts:1243-1244`, el aviso de validación `reglas-sin-aplicacion`: título «Reglas que el motor no aplica» y detalle «…son propuestas del Studio; aprobar no las hace cumplir.». Es la misma clase de afirmación que el punto 1, y sale en las listas de hallazgos y en la validación. Comprueba antes si tiene paridad con `StudioRuleCoherence` del backend (`studio-rules-coherence.parity.spec.ts`).
2. «Motor ahora», encabezado (`studio-inspector.ts:125`): «Lo que lee hoy el servidor. El Studio no lo cambia.» Es cierto para las lecturas (banderas, planes, base de conocimiento). Pero en Personalidad, con `canary`, convive con la frase nueva, que dice que la versión publicada fija tono, emojis y longitud. Se puede leer como contradicción.
3. «Motor ahora» de Construcción de respuesta: sigue «está fijo en código o en n8n». Con `canary`, los prompts de Composer y Critic de la canaria llevan los parches de tono, emojis y longitud. La nota de T3 en la cabecera lo dice, pero la frase sigue. No lo cambié porque el encargo nombraba solo Personalidad.
4. `ultron-studio-page.ts:261` (rama sin `readsVersions`) y `:898` («Borrador guardado… El motor de ULTRON no lo lee.»). Esta última es cierta: el borrador no lo lee nadie. Los dos ficheros son de otra lista.

