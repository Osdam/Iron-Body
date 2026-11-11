# Informe frontend · ola 4: frases de las reglas en los prompts (compilador 2) y cierre del H9 en el CRM

**11-11-2026 · `frontend` · árbol `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h9`.** Empecé sobre `fd02a4c` con el árbol limpio. No he hecho commit, push, `build:prod` ni despliegue. Del backend solo he leído código y he ejecutado los dos comandos de exportación. Las referencias son `ruta:línea` del árbol de trabajo del CRM (`src/app/modules/…`).

## Resumen para el lead

1. **Parte A (frases de las reglas): hecha entera.** Los seis puntos de §11 de `informe-be-reglas.md` y los tres textos de la tanda anterior.
   - El lector de la vista previa sigue siendo estricto y ahora conoce el compilador 2: el cambio `rules.forbiddenPhrases` (lista de 1 a 12 frases de ≤ 60 caracteres, igual a `phrases.list`, siempre la última), `prompts.phrases`, el error `prompts_rule_phrases_invalid` (con su `problems`) y la regla «cada error bloquea con su mismo código».
   - El diálogo de publicar enseña la fila «Frases prohibidas de las reglas» con la lista exacta y las reglas S-NNN que la aportan. Si alguna frase no cabe, bloquea y explica cada problema en llano. El 409 se explica igual.
2. **Cómo sabe el CRM que el servidor trae el compilador 2:**
   - Textos que dependen del estado: hace falta `status.engine.prompts.key === 'canary'` **y** `status.engine.prompts.compiler >= 2`. Ese bloque llega siempre, con la llave en `off` o en `canary` (`StudioPublications::promptsTrace()`).
   - Textos de la vista previa: que traiga `prompts.phrases`. El lector solo la admite con el compilador 2 o posterior, y con ese compilador la exige.
   - Con un servidor del compilador 1, con la llave en `off` o sin la clave, se dicen los textos de antes, literales. Con la llave ilegible, no se afirma nada.
3. **Punto 6:**
   - «Reglas que el motor no aplica» **no lo he tocado**: tiene paridad con `StudioRuleCoherence.php:471`, y el fixture generado `backend-coherence-cases.testing.ts` lo fija en 16 casos.
   - «Motor ahora» (nota del inspector) y «Motor ahora» de Construcción de respuesta ya dependen de la llave y del compilador.
4. **Parte B (H9):** el informe del H9 ya traía las cuatro tareas, así que la hice entera.
   - Fixtures sincronizados con su script; las huellas son las del informe del backend.
   - Versión y huella del catálogo actualizadas en dos specs.
   - El aviso de sombra `canary_only_declared` se comprueba con los 5 casos del fixture de la escalera: en sombra sale como «Aviso: …»; en vivo, como motivo de la negativa.
   - `status.personBindings` se lee (estricto si llega; si no llega, la clave no aparece). La fila «Ejecución en sombra» cuenta con `persons` aunque la cuenta no pueda leer la lista de vínculos, y deja de declararlo como dato que falta.
5. **Verificación (tras la vuelta 2, §7):**
   - `tsc` de la app y de las specs, sin errores (salida 0).
   - `check-literals`: «417 ficheros revisados · literales correctos».
   - Los dos módulos enteros: **110 ficheros, 1 979 pruebas en verde** (antes 1 908: hay 71 nuevas; 1 959 al cerrar la primera vuelta). La comparación byte a byte con el árbol del backend se ejecutó.
6. **No verificado:** la pantalla en un navegador, en estrecho y en ancho (no levanté la app), `build:prod` y un servidor real. Todo está probado contra los fixtures que exporta el backend y contra respuestas escritas a mano con la forma del código del backend.
7. **Adyacentes (sin arreglar):**
   - El fixture exportado `backend-publication.testing.ts` no lista `prompts_rule_phrases_invalid` en su mapa `errors`. Es cosa del backend.
   - La fila «Canal WhatsApp» contaba personas con la lista de vínculos (que cuenta vínculos, no personas). **Hecho en la vuelta 2** (§7.5).
   - Detalle completo en §5.

---

## 1 · Parte A: punto por punto (antes → después)

### A.1 · Tipos y lector de la vista previa de publicar

**`ultron-studio/studio-api.types.ts`**

- **Antes:** `PromptChangeDto` solo admitía los cuatro parámetros con un valor escalar; `error` solo podía ser `prompts_compile_failed`.
- **Después:**
  - `PROMPT_PHRASES_PARAM = 'rules.forbiddenPhrases'` (:865).
  - `RULE_PHRASES_SINCE_COMPILER = 2` (:868).
  - Los topes `PROMPT_PHRASES_MAX = 12` y `PROMPT_PHRASE_MAX_CHARS = 60`.
  - `PromptChangeDto` pasa a ser una unión discriminada (:923): `PromptParamChangeDto` o `PromptPhrasesChangeDto` (:914), esta con `value: string[]`.
  - `PromptPhrasesDto` (:944): `{list, rules, max, problems}`, con `PromptPhraseProblemDto` y los cinco motivos.
  - `PublicationPromptsDto.phrases?` (:986): opcional, porque un servidor del compilador 1 no la manda.
  - `PublicationPromptsError` y `PUBLICATION_PROMPTS_ERRORS` (:990).
  - El código 409 `prompts_rule_phrases_invalid` en `StudioErrorCode` (:1217) y en su registro (:1266).

**`ultron-studio/studio-parse.ts`**

- `PARCHE_FRASES_MAX = 2048` (:289): el tope propio del parche de frases. Los demás parches siguen en 1 200.
- `leerCambio` (:694) acepta `rules.forbiddenPhrases` con estas condiciones:
  - una lista de textos, de 1 a 12, sin repetir y de ≤ 60 caracteres;
  - sus parches con el tope de 2 048.
  - Cualquier otro `param` sigue rechazándose.
- `leerProblemaDeFrase` (:726):
  - el motivo es uno de los cinco;
  - `too_many` va con `rule` y `phrase` a `null`;
  - los demás motivos llevan un código `S-NNN` y la frase. Se admite la frase vacía, porque puede ser justo el problema tras canonicalizar.
- `leerFrases` (:744):
  - reglas `S-NNN` sin repetir;
  - hay frases si y solo si hay reglas;
  - `max` tiene que ser 12;
  - cada problema nombra una regla que aporta;
  - `too_many` va si y solo si la lista pasa del tope, y una sola vez.
- `leerPromptsDeLaVista` (:776), estricto:
  - `phrases` llega si y solo si el compilador es ≥ 2;
  - `prompts_rule_phrases_invalid` va si y solo si hay problemas, y entonces no hay paquete;
  - con paquete, el cambio de frases está si y solo si hay frases, y su valor es **exactamente** `phrases.list` (mismo orden);
  - un cambio de frases sin su bloque `phrases` se rechaza;
  - el orden de aplicación es el de los cuatro y, al final, las frases.
- `efectoDeLaVista` (:1073):
  - la comparación con los `params` de clase `prompt` deja fuera `rules.forbiddenPhrases`;
  - la regla «error ⇔ `blocking`» se comprueba para **cada** código (:1090): el error del paquete bloquea con su mismo código y solo con él; con `declaration` no bloquea ninguno de los dos;
  - una regla que aporta frases no puede ir en `noEffect` (:1095).
- `errorRulePhrasesProblems` (:2525): lee los `problems` del 409 y salta los ilegibles, igual que los otros lectores de errores.

### A.2 · `ultron-studio/studio-prompts-changes.ts`

- **Antes:** el cambio de frases no habría llegado al componente (el lector lo rechazaba), y el bloqueo solo conocía «no compila».
- **Después:**
  - La fila «Frases prohibidas de las reglas» (:149) enseña la lista **exacta** como `<li>` con «frase» (:84). Debajo dice «La aporta la regla S-004.» o «Las aportan las reglas …» (:87). Siguen los roles y el texto de cada parche, como en las demás filas.
  - El bloqueo lleva `data-prompts-error-codigo` (:40) y, con `prompts_rule_phrases_invalid`, la lista de problemas (:44).
  - Todo es interpolación de texto: nada de `innerHTML`.
  - Estilos mínimos en `studio-prompts-changes.scss`: `.pc-frases`, `.pc-problemas` y `.pc-valor .pc-sub`. Usan los tokens del módulo, sin tocar la maqueta.

### A.3 · Los textos, con la llave en `canary` y en `off`

El criterio común vive en `ultron-studio/studio-engine-text.ts`. `rulePhrasesReading(engine)` (:264) da:
- `prompts`: llave en `canary` y compilador ≥ 2;
- `unreadable`: la llave no se pudo leer;
- `none`: el resto.

`studioRuleTexts(engine)` (:306) devuelve juntos la insignia, su `title`, el texto oculto, la cabecera, la descripción del diálogo, el aviso tras crear y la nota del inspector.

| Dónde | Antes (también hoy con la llave en `off` o el compilador 1) | Llave en `canary` y compilador 2 |
|---|---|---|
| `studio-publication-text.ts:49` y `:70`, `:81` (descripción del diálogo y ayuda junto al botón) | «… y el objetivo comercial de la versión entran en sus prompts. … «Si no entiende» y las reglas S-NNN siguen sin efecto en WhatsApp.» | `TEXTO_PUBLICAR_PROMPTS_FRASES`: «…, y también las frases que prohíben sus reglas S-NNN vivas cuyo «si» se cumple (se le piden al modelo y el Critic las vigila; no es un filtro). … «Si no entiende» sigue sin efecto en WhatsApp.» La vista previa decide con `previewHasRulePhrases` (:61); el estado, con `rulePhrasesReading`. |
| `studio-publication-text.ts:252` (`noEffectText`, que era :214-216) | regla: «Las reglas del Studio no tienen efecto en WhatsApp.» | «Esta regla no aporta frases a WhatsApp: está archivada, no prohíbe frases o su «si» no se cumple en esta versión.» `help` dice «… y las reglas del Studio que copió y no aportan frases a los prompts». |
| `ultron-studio-page.ts:258` (aviso del motor; antes :257) | «… «Si no entiende» y las reglas S-NNN siguen sin efecto en WhatsApp.» | `enginePromptsNoticeText` (`studio-publication-text.ts:92`): «…, y también las frases que prohíben sus reglas S-NNN vivas cuyo «si» se cumple (se le piden al modelo y el Critic las vigila; no es un filtro); … «Si no entiende» sigue sin efecto en WhatsApp.» |
| `studio-rules-panel.ts:80` (cabecera), `:111` (insignia), `:147` (diálogo), `:518` (aviso tras crear) y el comentario de la clase | «ULTRON por WhatsApp no las aplica; …» / «WhatsApp no la aplica» / «… creada en borrador. ULTRON por WhatsApp no la aplica.» | «ULTRON por WhatsApp no las aplica como reglas; con la llave de prompts encendida, las frases que prohíbe una regla viva cuyo «si» se cumple entran en los prompts de la conversación canaria cuando se publica en canario una versión que la copia: se le piden al modelo y el Critic las vigila; no es un filtro. En la voz, también entran en el agente compilado.» Insignia: «WhatsApp no la aplica como regla», con el detalle en `title` y para el lector de pantalla. Aviso tras crear: «… no la aplica como regla; si prohíbe frases, entran en los prompts de la conversación canaria cuando se publique en canario una versión que la copie y su «si» se cumpla.» |
| `studio-inspector.ts:245` (nota de reglas) y `:256` (insignia) | «… ULTRON por WhatsApp no las aplica y, en la voz, …» | La misma frase que la cabecera, desde `studioRuleTexts`. |

- **Llave ilegible:** ninguno de estos textos afirma ni que entran ni que no. Dicen: «no se pudo leer la llave de prompts del motor, así que no se sabe si sus frases prohibidas entran en los prompts de la conversación canaria».
- **Un texto más, fuera de la lista:** en el diálogo, la línea «Nada que reconocer: … y no copia reglas del Studio.» (`studio-publication-dialog.ts:168`) pasaba a ser **falsa** con el compilador 2. Si todas las reglas copiadas aportan frases, `noEffect` queda vacío aunque la versión sí copie reglas. Ahora sale de `noEffectText(...).none`. Con el compilador 2 dice «…, y las reglas del Studio que copió, si las hay, aportan frases a los prompts.»; los otros dos casos dicen lo de antes, literal. Lo señalo porque no estaba en la lista.

### A.4 · El bloqueo `prompts_rule_phrases_invalid` en el diálogo

- `blockingText` (`studio-publication-text.ts:480`) y `DONDE_SE_VE` (:421): el bloqueo lo explica el bloque de los prompts. No sale como «bloqueo desconocido».
- `TEXTO_FRASES_NO_CABEN` (:429) es el mensaje del servidor, literal. `promptsErrorText` está en :433.
- `phraseProblemText` (:454) da una línea por problema: «S-004: la frase «…» lleva un carácter que no se admite: solo letras, cifras, espacios y , . ¿ ? ¡ ! -.». Los cinco motivos llevan los textos de §11:
  - `length`: «tiene menos de 2 o más de 60 caracteres»;
  - `charset`: «lleva un carácter que no se admite: …»;
  - `spacing`: «tiene espacios de más»;
  - `anchor`: «repite una marca interna de los prompts»;
  - `too_many`: «Entre todas las reglas hay más de 12 frases distintas.», sin regla ni frase.
- El 409 al publicar (`studio-publication-dialog.ts:401`) da el mensaje del servidor y, debajo, una línea por problema.

### A.5 · Fixtures de paridad

- `ExportStudioFixturesCommand` escribe por defecto en `base_path('../../Iron-Body_Front/…/ultron-studio/testing')`, que es exactamente este árbol (`realpath` comprobado). Admite `--path`.
- Lo ejecuté primero a un directorio temporal y comparé: de los seis ficheros, solo cambia `backend-publication.testing.ts`. El diff es:
  - `compiler: 2`;
  - `engineMessagesPrompts` (los textos nuevos con las frases);
  - `previewHash`;
  - `prompts.phrases` vacío.
- Después lo ejecuté en el árbol: «Catálogo 2026-10-19.1, 51 casos de paridad, 123 de coherencia, 11 escenarios del laboratorio (2026-10-20.1) …».
- **El fixture escrito a mano** (`studio-prompts-fixtures.testing.ts`) se pone al día, porque la paridad lo exige:
  - `compiler: 2` y `phrases` vacío por defecto;
  - `promptsVistaCompilador1Dto` (un servidor anterior);
  - `promptsVistaConFrasesDto`, con el texto exacto de los parches sacado de `StudioPromptBundle::compile` real (huella `c418fda2…`, 114 428 bytes);
  - `promptsVistaFrasesNoCabenDto` y sus vistas previas.

### A.6 · La tanda anterior

- `studio-rules-coherence.ts:1243-1244`, «Reglas que el motor no aplica»: **sin cambios**. Tiene paridad con `StudioRuleCoherence.php:471` (mismo id, nivel, título y detalle), y el fixture generado lo fija en 16 casos.
- `studio-inspector.ts:124`, nota de «Motor ahora». La da `engineNowNote` (`studio-engine-text.ts:232`):
  - con la llave en `off`, la de siempre (`ENGINE_NOW_NOTE`, :224);
  - con `canary`: «Lo que lee hoy el servidor; el Studio no cambia estas lecturas. Con la llave de prompts encendida, la versión publicada en canario sí cambia los prompts de ULTRON en la conversación canaria.»;
  - ilegible: depende de la llave, sin afirmar nada.
- «Motor ahora» de Construcción de respuesta (`composerNoLiveReadingsText`, `studio-engine-text.ts:210`). Antes de afirmarlo comprobé en `UltronPromptCompiler::patchesOf()` que tono, emojis, longitud y frases van a Composer y Critic (y a sus reintentos), y el objetivo al Strategist.
  - Con `canary` y el compilador 2: «… los prompts de Composer y Critic llevan los parches de tono, emojis y longitud de la versión publicada en canario, y las frases que prohíben sus reglas S-NNN vivas cuyo «si» se cumple; el resto está fijo en código o en n8n …».
  - Con el compilador 1: lo mismo, sin las frases.
  - Ilegible: depende de la llave.
  - Apagada: lo de siempre.

## 2 · Parte B: H9 (punto por punto)

### B.1 · Sincronización de fixtures

- `node src/app/modules/commercial-automation/testing/sync-h9-fixtures.mjs <backend>` dio:

  | Fichero | sha256 (inicio) | Coincide con el informe del backend |
  |---|---|---|
  | `catalog-snapshot.json` | `c397b1c2…` | sí |
  | `conditions-cases.json` | `4e2b63d5…` | sí |
  | `strategy-content-cases.json` | `ae0eae27…` | sí |
  | `policy-ladder-cases.json` | `7ece0bf9…` (544 001 bytes) | sí |
  | `outcome-transitions.json` | `f9e38189…` | sin cambios |
  | `trace-text-cases.json` | `c8695df6…` | sí |

  No he editado ninguna copia a mano.
- `h9-fixtures.spec.ts:18,23,28`: `outreach-catalog/2026-11-11.1` y la huella `079930a2a391f318ee7812a9b6e6fcce893458987ecbf5a14ec37d0232a86e11`. `dictionaryHash` no cambia.
- `commercial-automation-api.service.spec.ts:90`: la versión nueva.
- **Código del CRM:** no se rompió nada. `CatalogIndex.triggerableEvents()` (`commercial-automation-catalog.ts:231`) ya filtraba `triggerable && available`. Lo fija una prueba nueva en `commercial-automation-content.spec.ts`: los seis eventos sin emisor no se ofrecen como disparador y siguen legibles en condiciones. El caso del fixture `customer_satisfied → trigger_not_allowed` ya pasaba con el lector del contenido.

### B.2 · `canary_only_declared` en sombra: aviso, no negativa

- El CRM no tiene escalera propia. La traza que ve la persona son las frases que compone el servidor (`DecisionDto.text`, pintadas en `ca-panel.ts:527-531`), y su espejo exacto es `explainDecision`, con paridad por `trace-text-cases.json`.
- Prueba nueva en `commercial-automation-trace-text.spec.ts`, con los 5 casos del fixture de la escalera que nombran `canary_only_declared` (exige que sean 3 en sombra y 2 en vivo):
  - **En sombra:** el código no está en `reasons` y sí en `warnings`. La traza lleva «Aviso: La versión comercial limita la estrategia a su alcance canario.» y nunca el texto pelado. La cabecera es «Se permite el contacto.», salvo en el caso en que niega OTRO motivo de N3 (la referencia del Studio), con el aviso detrás.
  - **En vivo:** exactamente `['No se contacta (N3 · Versión comercial aprobada).', 'La versión comercial limita la estrategia a su alcance canario.']`, sin aviso.

### B.3 · `personBindings` en `GET outreach/status`

- El backend de este árbol lo manda: `OutreachReadModels.php:73` → `{verified, persons}` (`OutreachPublishedStrategies.php:231-249`).
- **Tipo:** `OutreachStatusDto.personBindings?` (`commercial-automation-api.types.ts`), opcional.
- **Lector:** `recuentoDeVinculos` en `commercial-automation-parse.ts`. Es aditivo: sin la clave no aparece nada. Si llega, es estricto:
  - dos enteros no negativos;
  - nunca `persons > verified`;
  - `null` o una lista se rechazan.
- **Fila «Ejecución en sombra»** (`ca-activation.ts`, `personasDelEstado` y `shadowActivation`):
  - Usa `persons` (identidades distintas, lo que cuentan los cuatro ojos), como pide el informe del H9.
  - Sombra sin estrategias: «… dos personas vinculadas y verificadas (hoy N) …». Desaparece el dato que falta `personBindings.verified` y la fuente pasa a ser `GET admin/ultron-studio/outreach/status (personBindings.persons)`.
  - Fase 1 (apagada): «… dos personas vinculadas y verificadas (hoy N; cuatro ojos; …)», y el hueco ya no dice «cuántas personas vinculadas hay». Con dos personas o más, «las dos personas vinculadas ya están».
  - Si llegan a la vez el recuento del estado y la lista de vínculos, manda el del estado. Una persona con dos cuentas verificadas no son «las dos personas»: la lista diría dos y el estado dice una.
  - Con un servidor anterior, lo de siempre (probado).

## 3 · Pruebas y comprobaciones (salida real)

Un proceso pesado cada vez, siempre con `nice -n 10`.

- `npx tsc -p tsconfig.app.json --noEmit` → salida 0, sin errores.
- `npx tsc -p tsconfig.spec.json --noEmit` → salida 0, sin errores.
- `node scripts/check-literals.mjs` → «417 ficheros revisados · literales correctos».
- `IRON_BODY_BACKEND_DIR=<backend> npx ng test --watch=false --include='src/app/modules/ultron-studio/**/*.spec.ts' --include='src/app/modules/commercial-automation/**/*.spec.ts'` → **Test Files 110 passed (110) · Tests 1959 passed (1959)**, en 22,46 s.
  - Antes eran 1 908; hay 51 nuevas.
  - `h9-fixtures` comparó contra el backend: no salió el aviso «sin árbol del backend».
- La primera pasada dirigida tras regenerar dio **13 fallos esperados**: pruebas que fijaban el compilador 1 con la llave en `canary` (`compilador 1` en la traza, `TEXTO_PUBLICAR_PROMPTS` y «las reglas S-NNN siguen sin efecto»).
  - Cada una se ajustó al compilador 2, que es el servidor de hoy, **y** ganó un caso hermano explícito con el compilador 1, que conserva el texto de antes.
  - Una prueba nueva mía falló por reutilizar un objeto entre mutaciones; la corregí en la prueba, no en el código.

**Pruebas nuevas o ampliadas**, todas sobre funciones y componentes reales:

- **`studio-prompts-parse.spec.ts`**, bloque «compilador 2». Cubre:
  - compilador 1 sin `phrases`; compilador 2 vacío y con frases; frases que no caben;
  - `too_many`;
  - 12 rechazos de forma (falta, sobra, lista distinta o desordenada, cambio sin bloque, reglas, tope, regla en `noEffect`);
  - el cambio mal formado (vacío, 13, repetidas, 61 caracteres, parche de 2 048 frente a 2 049, orden);
  - el «mismo código»;
  - los problemas mal formados;
  - el 409.
- **`studio-prompts-text.spec.ts`**: las dos variantes del texto de publicar y de la ayuda, el aviso del motor, `noEffectText` con y sin frases, el bloqueo, los cinco motivos y la frase vacía.
- **`studio-engine-text.spec.ts`**: `rulePhrasesReading` en sus 8 estados; `studioRuleTexts` en `prompts`, `none` e `unreadable`; la nota de «Motor ahora»; Construcción de respuesta.
- **`studio-publication-dialog-prompts.spec.ts`**:
  - compilador 1;
  - la fila de frases (lista, reglas, parches, huella, qué se reconoce) y con varias reglas;
  - el texto hostil (`<img onerror>` en la lista y en un problema, sin HTML);
  - el bloqueo con sus problemas y `too_many`;
  - el 409 con la frase tal cual llegó;
  - «nada que reconocer».
- **`studio-rules-panel.spec.ts`**, **`studio-inspector.spec.ts`**, **`ultron-studio-page.spec.ts`** y **`studio-versions-panel-publication.spec.ts`**: cada texto en `off`, en `canary` con el compilador 1, en `canary` con el compilador 2 y con la llave ilegible, según el componente.
- **`commercial-automation-trace-text.spec.ts`** (6 nuevas), **`ca-activation.spec.ts`** (7 nuevas) y **`commercial-automation-content.spec.ts`** (2 nuevas).

## 4 · Ficheros tocados (35)

- **ultron-studio, código:**
  - `studio-api.types.ts`, `studio-parse.ts`;
  - `studio-engine-text.ts`, `studio-publication-text.ts`;
  - `studio-prompts-changes.ts` y `.scss`;
  - `studio-publication-dialog.ts`, `studio-rules-panel.ts`, `studio-inspector.ts`, `ultron-studio-page.ts`.
- **ultron-studio, pruebas:**
  - `studio-prompts-fixtures.testing.ts`;
  - `testing/backend-publication.testing.ts` (GENERADO por el comando, no a mano);
  - `studio-prompts-parse.spec.ts`, `studio-prompts-text.spec.ts`, `studio-prompts-trace.spec.ts`, `studio-engine-text.spec.ts`;
  - `studio-publication-dialog-prompts.spec.ts`, `studio-rules-panel.spec.ts`, `studio-inspector.spec.ts`;
  - `ultron-studio-page.spec.ts`, `studio-versions-panel-publication.spec.ts`.
- **commercial-automation, código:** `commercial-automation-api.types.ts`, `commercial-automation-parse.ts`, `ca-activation.ts`.
- **commercial-automation, pruebas:**
  - `h9-fixtures.spec.ts`, `commercial-automation-api.service.spec.ts`;
  - `commercial-automation-trace-text.spec.ts`, `commercial-automation-content.spec.ts`, `ca-activation.spec.ts`;
  - las 6 copias de `testing/fixtures/` (5 JSON y `manifest.json`), escritas por el script.

## 5 · Lo que quedó sin hacer o adyacente (no lo he arreglado)

1. **Fixture del backend.** `BACKEND_PUBLICATION.errors` (lo genera `ExportStudioFixturesCommand`) no lista `prompts_rule_phrases_invalid: 409`.
   - El CRM lo conoce y la paridad no se rompe, pero no puede fijar su estado HTTP como hace con `prompts_compile_failed`.
   - **Para `backend`.**
2. **La fila «Canal WhatsApp»** (`plantillasDeMeta`, en `ca-activation.ts`) sigue contando con la lista de vínculos: «personas vinculadas: tu cuenta no puede contarlas».
   - El lead pidió el recuento solo para «Ejecución en sombra», así que en el mismo panel una fila puede decir «hoy 1» y la otra «no puede contarlas».
   - Además, `dosPersonasVinculadas` y `personasVerificadas` cuentan **vínculos** verificados, no personas: una persona con dos cuentas se cuenta dos. Con el servidor nuevo, la sombra ya no lo usa.
3. **Ejemplos escritos a mano.** `estadoEjecucion()` (`testing/ca-examples.testing.ts`) sigue con la forma anterior, sin `personBindings`; las pruebas nuevas lo añaden explícito.
   - Siguen con el catálogo viejo `2026-11-10.2`: `commercial-automation-api.service.spec.ts:307`, `testing/ca-examples.testing.ts:139,154,608` y `e2e/h9-ui/capture-h9.mjs`. No leen el fixture y no rompen; quedan desfasados, como ya dice el informe del H9.
4. **Nota de «paquete idéntico»** en `studio-prompts-changes.ts`: «Los cuatro valores que gobiernan los prompts son los de la línea base». Con el compilador 2 se queda corta (tampoco hay frases), pero no es falsa. No la toqué.
5. **El gap `huecoPersonas`** dice «propuesta: personBindings.verified». Con el servidor nuevo no sale, y para uno anterior sigue siendo verdad. No lo toqué.
6. **Hallazgo comercial del H9:** `complaint_created`, `objection_raised` y `offer_rejected` se siguen ofreciendo en condiciones, porque el CRM filtra solo por `conditionUsable`. Es una decisión de producto, tal como la plantea el informe del H9.
7. **Para `security`:**
   - Las frases son texto del personal y se pintan en el diálogo, en la fila de cambios y en la lista de problemas, siempre por interpolación (prueba con `<img onerror>`).
   - El CRM no manda nada nuevo al servidor: solo lee.
   - Sigue pendiente la revisión de `security` / `api-security-specialist` que pide el informe del backend (texto del personal en prompts de producción).
8. **Para `tester`, los flujos críticos:**
   - abrir «Publicar en canario» con una versión que copia una regla con frases (fila de frases, reglas que las aportan, reconocimientos sin esa regla);
   - una versión con frases que no caben (bloqueo, problemas, botón deshabilitado, «Recalcular»);
   - el 409 `prompts_rule_phrases_invalid`;
   - la fila «Ejecución en sombra» con una cuenta sin `ultron.person_bindings_manage`.

## 6 · Lo que no pude verificar

- **La pantalla de verdad.** No levanté la app ni miré la fila de frases ni la lista de problemas en un navegador, ni en estrecho ni en ancho. El contenedor ya tiene `overflow-x: auto` y `overflow-wrap: anywhere`, pero no lo he visto.
- **`build:prod`.** No lo ejecuté, por instrucción.
- **Un servidor real.** Todo está probado contra los ficheros que exporta el backend de este árbol, contra el compilador real vía `tinker` para el texto exacto de los parches y contra respuestas escritas a mano con la forma del código PHP, no contra HTTP. La forma de `personBindings` la leí en el código del backend; no hay fixture exportado de `outreach/status`.
- **El despliegue.** Si el CRM sale antes que el backend del compilador 2, no hay problema: con el compilador 1 se dicen los textos de antes. Si sale el backend primero con versiones con frases, el CRM de producción falla cerrado (§4 del informe del backend). No lo he probado contra producción.

## 7 · Vuelta 2 (11-11, tarde)

El contrato final es el de `informe-be-reglas.md`, «Vuelta 2», §2 y §9. El lector cumple sus tres invariantes:
- `prompts.phrases.problems ≠ []` ⇒ `error = prompts_rule_phrases_invalid`;
- `carried.problems` presente ⇒ `error ≠ null`;
- `error = prompts_rule_phrases_invalid` ⇒ problemas en la versión o en lo arrastrado.

`carried.phrases` es opcional para un servidor anterior; si llega, es estricto.

### 7.1 · La parte arrastrada y `no_text` (B-1, B-3 y E-1 de `informe-security-reglas.md`, con la forma final del lead)

**Lo que dice el backend.** Lo leí en el código en curso del árbol, sin tocarlo (`StudioPublications::promptsDto()`, `promptFacts()`, `promptedPart()`; `UltronStudioException::promptsRulePhrasesInvalid()`):
- `carried` lleva siempre `phrases` (`[]` si ninguna).
- Si la parte arrastrada no compila por sus frases: `bundleHash`, `shortHash` y `baseline` van a `null`, y vienen `problems` y `phrases` (que puede traer las que no caben).
- El error es `prompts_rule_phrases_invalid` por la parte arrastrada solo si la versión no tenía ya otro. Si lo tenía, se conserva el de la versión y `carried.problems` sigue viniendo. Así que la versión puede compilar (con huella y cambios) y el paquete bloquear igual por lo arrastrado.
- El 409 lleva `part: 'version' | 'carried'`, con dos mensajes distintos.
- El mínimo de una frase pasa a 3 caracteres, y aparece el motivo `no_text`.

**Tipos** (`studio-api.types.ts`):
- `PromptPhraseProblemReason` gana `no_text`.
- `PromptCarriedDto` gana `phrases?` y `problems?`; `bundleHash`, `shortHash` y `baseline` pasan a admitir `null`.

**Lector** (`studio-parse.ts`), estricto cuando llega y opcional para un servidor anterior. `leerArrastrada` comprueba:
- **La parte que compila:** huella, principio de la huella y `baseline` ⇔ huella Legacy. Si trae `phrases`: ≤ 12, cada una de 1 a 60 caracteres, sin repetir. Con alguna frase no puede decir `baseline: true`.
- **La parte que no compila:** huella, huella corta y `baseline` exactamente a `null`. `problems` con al menos uno y los motivos conocidos (`too_many` una sola vez). `phrases` es opcional y laxa, porque puede traer las que no caben, pero `too_many` va si y solo si pasan de 12.
- `phrases` y `problems` solo desde el compilador 2.

En `leerPromptsDeLaVista`, las reglas del error pasan a ser:
- con problemas en la versión, el error es el de las frases y no hay paquete;
- el error de las frases exige problemas en la versión o en lo arrastrado;
- con problemas en lo arrastrado, tiene que haber algún error.

`errorRulePhrasesPart` lee la `part` del 409.

**Textos** (`studio-publication-text.ts`):
- `TEXTO_FRASES_NO_CABEN` es el mensaje nuevo del servidor: «de 3 a 60 caracteres, con al menos una letra o una cifra».
- `carriedPhrasesErrorText(label)` usa las palabras del servidor para la parte `carried`, nombrando la versión: «… la causa no está en esta versión: está en «label», la versión que hoy rige el resto … La salida: «Volver a Legacy» antes de publicar; si quieres conservar esas reglas, corrígelas y publica otra versión.»
- `promptsErrorPart` y `promptsErrorText(error, carriedLabel)`.
- `TEXTO_FRASES_ARRASTRADAS`: «La parte del resto que se arrastra lleva estas frases prohibidas de sus reglas:».
- Motivos: `length` dice «tiene menos de 3 o más de 60 caracteres»; `no_text`, «no tiene ninguna letra ni cifra».

**Diálogo** (`studio-prompts-changes.ts`, `studio-publication-dialog.ts`):
- En «Si después se revierte», las frases de la parte arrastrada salen tal cual, debajo de su rótulo.
- Si no caben:
  - la fila dice ««label», la versión que hoy rige el resto, no se puede arrastrar: …» y lista sus problemas;
  - el bloqueo (`data-prompts-error-parte="carried"`) da el texto de la causa y la salida, y no repite los problemas como si fueran de la versión;
  - la tabla de cambios de la versión, que sí compila, se sigue enseñando, y el envío queda deshabilitado.
- El 409 con `part: carried` añade «La versión que hoy rige el resto es «label».» y los problemas.

**E-1.** Prueba nueva con el peor caso, 12 frases de 60 caracteres: los cuatro parches de `rules.forbiddenPhrases` se pintan completos (texto idéntico byte a byte, más de 800 caracteres, terminado en la lista entera), igual que la lista. Revisé que ningún estilo recorte: no hay `line-clamp`, `text-overflow`, `max-height` ni `nowrap` en los parches.

**Fixtures.**
- Tras su cambio, el agente del servidor regeneró `testing/backend-publication.testing.ts` en este árbol a las 17:34 (el comando escribe aquí por defecto). Respecto a mi exportación anterior, lo único nuevo es `carried.phrases: []`. Mi exportación de prueba a un temporal, a las 17:40, sale idéntica.
- Puse al día el fixture escrito a mano:
  - `promptsVistaDto` lleva `carried.phrases: []`;
  - nuevos: `promptsVistaSinVuelta2Dto`, `promptsVistaArrastradaConFrasesDto`, `promptsVistaArrastradaNoCabeDto` y `vistaPreviaArrastradaNoCabeDto`;
  - el del compilador 1 quita también `carried.phrases`.
- Ese fixture del servidor sigue sin un ejemplo de lo arrastrado que no cabe, ni `prompts_rule_phrases_invalid` en `errors`: esos casos solo los cubren los fixtures escritos a mano.

### 7.2 · `personBindings` con solo `verified` (versión intermedia)

- **Lector:** `verified` es obligatorio (entero ≥ 0); `persons`, opcional (entero ≥ 0 y ≤ `verified`).
- **Con `persons`:** lo de §2 B.3.
- **Con solo `verified` y sin poder leer la lista**, la fila «Ejecución en sombra» no habla de personas. Dice: «(tu cuenta no puede contarlas; el servidor cuenta N vínculos verificados, que pueden ser de menos personas)». Pasa en la sombra sin estrategias y en las condiciones de la fase 2.
  - Nunca dice «las dos personas ya están».
  - El número de personas sigue siendo un dato que falta (el hueco se mantiene).
  - La fuente es `… (personBindings.verified)`.
- **Con la lista legible**, manda la lista, como antes.

### 7.3 · Pruebas de esta vuelta (17 nuevas)

- `studio-prompts-parse.spec.ts` (9):
  - un servidor sin la vuelta 2;
  - la parte arrastrada con frases y vacía;
  - 8 rechazos de las frases arrastradas;
  - la parte arrastrada que no cabe, con la versión compilada y con la versión rota;
  - 8 rechazos de lo arrastrado que no cabe;
  - `no_text`;
  - la `part` del 409.
- `studio-prompts-text.spec.ts` (2 nuevas y una ampliada): `promptsErrorPart`, el texto de lo arrastrado y los motivos.
- `studio-publication-dialog-prompts.spec.ts` (5): E-1 en el peor caso, las frases arrastradas, sin frases (hoy y con un servidor anterior), lo arrastrado que no cabe y el 409 `carried`.
- `ca-activation.spec.ts` (2 nuevas y una ajustada): solo `verified`, en el lector y en la fila.

### 7.4 · Fixtures

- El lead exportó los fixtures de paridad con el servidor ya cerrado. Solo cambia `testing/backend-publication.testing.ts` (sello de las 17:34): respecto a la primera vuelta, lo único nuevo es `carried.phrases: []`.
- **No he vuelto a ejecutar `php artisan`** en el árbol del backend, por indicación del lead (batería completa en paralelo).
- Las specs de publicación (paridad incluida) pasan con ese fichero: van dentro de los dos módulos enteros (§7.6).

### 7.5 · «Canal WhatsApp» cuenta personas igual que «Ejecución en sombra» (adyacente 2)

- `ca-activation.ts`: nueva `recuentoDelPanel(i, s)`, la única fuente del recuento para las dos filas. La prioridad es:
  1. `status.personBindings.persons`: personas distintas, aunque la cuenta no pueda leer la lista;
  2. la lista de vínculos, si se puede leer (como antes);
  3. solo `verified`: se dice como vínculos y no como personas, y las personas siguen sin saberse.
- `plantillasDeMeta` (la frase «… (personas vinculadas: hoy N)») y las dos ramas de `shadowActivation` la usan; desaparece la lógica duplicada.
- La fila de WhatsApp cita `GET …/outreach/status (personBindings.persons)` (o `.verified`) cuando su frase usa ese recuento. Con la lista, como antes (sin fuente añadida).
- **Pruebas nuevas** en `ca-activation.spec.ts` (3):
  - con `persons` = 1, sin la lista y con Meta leído o sin leer, las dos filas dicen «hoy 1» y WhatsApp cita la fuente;
  - con el recuento y la lista a la vez (2 vínculos de 1 persona), las dos filas dicen «hoy 1»;
  - con solo `verified`, las dos dicen «… el servidor cuenta 2 vínculos verificados, que pueden ser de menos personas».
- Las pruebas anteriores de las dos filas siguen verdes sin cambios: el refactor conserva el comportamiento con la lista y con un servidor anterior.

### 7.6 · Comprobaciones finales (salida real)

- `npx tsc -p tsconfig.app.json --noEmit` → salida 0.
- `npx tsc -p tsconfig.spec.json --noEmit` → salida 0.
- `node scripts/check-literals.mjs` → «417 ficheros revisados · literales correctos».
- `IRON_BODY_BACKEND_DIR=<backend> npx ng test --watch=false --include='src/app/modules/ultron-studio/**/*.spec.ts' --include='src/app/modules/commercial-automation/**/*.spec.ts'` → **Test Files 110 passed (110) · Tests 1979 passed (1979)**. `h9-fixtures` comparó contra el backend.

### 7.7 · Lista final de ficheros (35; sin commit, HEAD `fd02a4c`)

`src/app/modules/ultron-studio/`:

- **Código:**
  - `studio-api.types.ts`, `studio-parse.ts`;
  - `studio-engine-text.ts`, `studio-publication-text.ts`;
  - `studio-prompts-changes.ts`, `studio-prompts-changes.scss`;
  - `studio-publication-dialog.ts`, `studio-rules-panel.ts`, `studio-inspector.ts`, `ultron-studio-page.ts`.
- **Pruebas y fixtures:**
  - `studio-prompts-fixtures.testing.ts`;
  - `testing/backend-publication.testing.ts` (GENERADO; la última exportación es la del lead);
  - `studio-prompts-parse.spec.ts`, `studio-prompts-text.spec.ts`, `studio-prompts-trace.spec.ts`;
  - `studio-publication-dialog-prompts.spec.ts`, `studio-engine-text.spec.ts`, `studio-rules-panel.spec.ts`, `studio-inspector.spec.ts`;
  - `ultron-studio-page.spec.ts`, `studio-versions-panel-publication.spec.ts`.

`src/app/modules/commercial-automation/`:

- **Código:** `commercial-automation-api.types.ts`, `commercial-automation-parse.ts`, `ca-activation.ts`.
- **Pruebas:**
  - `ca-activation.spec.ts`, `commercial-automation-trace-text.spec.ts`, `commercial-automation-content.spec.ts`;
  - `h9-fixtures.spec.ts`, `commercial-automation-api.service.spec.ts`.
- **Copias GENERADAS por `sync-h9-fixtures.mjs`:**
  - `testing/fixtures/catalog-snapshot.json`, `conditions-cases.json`, `strategy-content-cases.json`;
  - `policy-ladder-cases.json`, `trace-text-cases.json`, `manifest.json`.

### 7.8 · Lo que no pude verificar

- Las filas nuevas (lo arrastrado, sus frases y su bloqueo; las dos filas del panel de activación) no las he visto en un navegador.
- Un 409 `carried` contra un servidor real.
- `build:prod`, que no se ejecuta por instrucción.
