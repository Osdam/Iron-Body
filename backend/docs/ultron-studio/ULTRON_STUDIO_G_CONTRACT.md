# ULTRON STUDIO · Hito G (control funcional de las diez capas): contrato congelado

**Fecha:** 18-10-2026. **Base:** backend `9acddc3` (docs en `29ffa05`) y CRM `020db86`, ambos = producción con el Hito F desplegado. **Rama de trabajo:** `feat/ultron-studio-g` en los dos repos. **Origen:** plan del `architect` (`scratchpad/hito-g/plan-architect.md`, secciones «Contexto», «Impacto», «Opciones», B y C).

## Decisiones del lead

| # | Pregunta | Decisión |
|---|---|---|
| 1 | ¿Incumplir una regla del Studio es aviso o bloqueo? | **Aviso** (`regla-<code>` nivel `warning`): las reglas S-NNN son propuestas que el motor no aplica; el aviso obliga a que otra persona lo reconozca al aprobar |
| 2 | ¿Quién ve las lecturas del motor (`GET engine/readings`)? | **Todo `ultron.view`** (Super Admin y Administrador): estados booleanos, driver efectivo, recuentos y nombres de planes; sin ids ni secretos. Mercadeo ya enseña interruptor, alcance y freno a su dominio |
| 3 | ¿Puede una regla del Studio proponer encender el cobro? | **Sí, como aviso**, igual que el borrador (`cobro-aprobacion`); la aprobación de cuatro ojos lo reconoce |
| — | Por defecto (aceptado) | Afirmaciones de texto mínimas (se guardan, no se evalúan); aristas de las R-xx según la auditoría §5; sin tablas nuevas (solo la columna `conditions`); `format: 2` dentro de `content`; fixture de coherencia aparte del de F (`coherence-cases.json`, escrito por el tester desde este contrato); origen de datos `engine_data`; opciones G1-A, G2-A, G3-A, G4-A, G5-A, G6-A |

**Protecciones intocables:** el motor no lee nada del Studio (ninguna ruta de G escribe en tablas, configuración o ficheros del motor; prueba de comportamiento que lo vigila); publicar y revertir responden 409 siempre; sin llamadas, Meta, ElevenLabs ni consumo pagado; nada se despliega sin su ciclo completo (pruebas, seguridad, regresión, respaldo, verificación).

### A. Diseño a congelar

El lead lo convierte en `‹B›/docs/ultron-studio/ULTRON_STUDIO_G_CONTRACT.md`.

#### A1. Cuatro planos que no se mezclan

| Plano | Dónde vive | Qué lo cambia | Efecto en el agente |
|---|---|---|---|
| Posición visual | `content.layout`, fuera de toda huella (`StudioContentHasher.php:23-27`) | Arrastrar en el lienzo | Ninguno |
| Configuración funcional | `content.definition` y `content.configs` del borrador; una copia en cada versión | El inspector | Ninguno: el motor no lee el Studio |
| Reglas del agente | `ultron_studio_rules`: las R-xx (copia de lo que aplica el código) y las S-NNN (propuestas, `enforcement = none`) | El panel de reglas (`ultron.manage_rules`) | Las R-xx describen el motor; las S-NNN no hacen nada |
| Ejecución real | Entorno, `marketing_agent_settings`, `ultron_aborts`, base de conocimiento, planes, código y n8n | Despliegue, Mercadeo, consola y otros módulos del CRM. **Nunca el Studio** | Es el agente |

En la interfaz cada plano lleva su insignia: «Disposición (no cambia nada)», «Borrador», «Regla» y «Motor (solo lectura)».

#### A2. Configuración real por capa

**Esquema.** Es estático, va dentro de `GET catalog` y lo cubre la huella:

```ts
type ParameterOrigin = 'env_flag' | 'engine_setting' | 'engine_data' | 'proposal' | 'fixed';
interface ParameterBinding {
  origin: ParameterOrigin;
  valueSource: 'live' | 'declared' | 'none'; // live = GET engine/readings; declared = declaredValue; none = sin valor único o ilegible
  declaredValue?: ConfigValue;               // solo con 'declared'; tiene que pasar el normalizador del tipo
  engineOption?: ConfigValue;                // valor del borrador que significa «lo que tenga el motor» (solo intent.driver = 'entorno')
  comparable: boolean;                       // ¿tiene sentido marcar «≠ motor»?
  effect: string;                            // ≤ 240 caracteres, en llano
  changeVia: string;                         // ≤ 120 caracteres, en llano: quién lo cambia DE VERDAD
}
interface EngineReadingDescriptor { id: string; layer: UltronLayerId; label: string; origin: ParameterOrigin; effect: string; changeVia: string }
// En el catálogo: bindings: Record<UltronLayerId, Record<string, ParameterBinding>>; readings: EngineReadingDescriptor[]
```

- `engine_data` sustituye al `knowledge` que sugería el encargo: cubre la base de conocimiento **y** los planes, que son de la misma naturaleza (datos de negocio que el motor lee de tablas).
- `StudioCatalogTest` comprueba estas reglas del esquema:
  - Cada parámetro tiene exactamente un `binding`, y no hay `binding` sin parámetro.
  - `live` solo aparece en los parámetros que lee `StudioEngineReadings`.
  - Ningún texto contiene rutas, nombres de variables de entorno ni comandos. Se usa la lista prohibida de F más `MARKETING_`, `ULTRON_STUDIO_`, `.env` y `config(`.
- **Único cambio de textos de F:** `intent.driver` ya no puede decir «no verificado». La pista pasa a «En producción lo elige el entorno (hermes → openai → reglas); el inspector enseña el valor efectivo que lee el servidor», y la opción `entorno` pasa a llamarse «Según el entorno». La prueba de transcripción admite exactamente esas dos diferencias (A7).

**Tabla de los 21 parámetros**

| # | Capa · parámetro | Origen · valor | Qué enseña el Studio como valor real | Lectura | Quién lo cambia de verdad | Evidencia |
|---|---|---|---|---|---|---|
| 1 | knowledge · sources | fixed · none | «Fuentes fijas en código» | — | Código del motor | `StudioCatalog.php:181-187` |
| 2 | knowledge · strictFacts | fixed · declarado `true` | sí | — | Código (no configurable) | R-01 (`StudioCatalog.php:121`), restricción `hechos` (:441) |
| 3 | persona · tone | proposal · none | «Sin mando: sale de los prompts y de los ítems de tono aprobados» | lectura extra `knowledge.base` (categorías `tone` y `brand_copy`) | Prompts de n8n y base de conocimiento | auditoría §3, fila 2 |
| 4 | persona · emojis | proposal · none | «Sin interruptor: la guardia solo anota más de 2» | — | Prompts de n8n | `ComposerStyleGuard.php:357,452-453` |
| 5 | persona · maxChars | proposal · none | «Sin límite en el motor: la guardia solo anota más de 900» | — | Prompts de n8n | `ComposerStyleGuard.php:359,455-456` |
| 6 | intent · driver | env_flag · live, comparable, `engineOption: 'entorno'` | `openai`, `rules` (cuando el motor dice `fake`) o `hermes` | `SalesAiConfig::effectiveDriver()` | Entorno (despliegue) | `SalesAiConfig.php:17-24` |
| 7 | intent · fallback | proposal · none | — | — | Prompt del Strategist (n8n) | auditoría §3, fila 3 |
| 8 | context · windowTurns | fixed · declarado `10` | 10 | — | Código | `UltronDecideService.php:604-607` |
| 9 | memory · useLeadProfile | fixed · declarado `true` | sí | — | Código | auditoría §3, fila 5 |
| 10 | strategy · goal | proposal · none | — | — | Prompt del Strategist (n8n) | auditoría §3, fila 6 |
| 11 | strategy · offerPriority | engine_data · live, **no comparable** | Planes vendibles: la insignia primero y luego por `sort_order`, solo nombres | `Plan::sellable()` | Módulo de Planes del CRM | `Plan.php:227`; auditoría §3, fila 6 |
| 12 | strategy · discountsAllowed | fixed · declarado `false` | no | — | Código | R-02 (:122), restricción `descuentos` (:442) |
| 13 | rules · handoffOnlyCorroborated | fixed · declarado `true` | sí | — | Código | R-07 (:127), restricción `derivacion` (:444) |
| 14 | rules · paymentLinksEnabled | env_flag · live, comparable | sí / no | `config('marketing.ultron.payment_links_enabled')` | Entorno, con aprobación expresa del propietario | `config/marketing.php:36` |
| 15 | composer · critic | fixed · none | «Vive en n8n: no se lee desde el CRM» | — | Workflow de n8n | auditoría §2, paso 5 |
| 16 | composer · retry | fixed · none | Lo mismo que el anterior | — | Workflow de n8n | auditoría §2, paso 5 |
| 17 | integrations · channel | fixed · declarado `whatsapp` | WhatsApp | — | Código | auditoría §2 |
| 18 | integrations · appointments | fixed · declarado `true` | sí (la cortesía es una solicitud que confirma una persona) | — | Código | `UltronDecideService.php:49-56` |
| 19 | governance · enabled | env_flag · live, comparable | encendido / apagado | `config('marketing.ultron.enabled')` | Entorno; encenderlo exige el procedimiento de activación | `config/marketing.php:35` |
| 20 | governance · canaryOnly | env_flag · live, comparable | sí / no (**nunca el id**) | `config('marketing.ultron.canary_conversation_id') !== null` | Entorno | `config/marketing.php:83-86`; mismo criterio en `MarketingAgentSwitch.php:229` |
| 21 | governance · autoBrake | fixed · declarado `true` | sí, y solo con canario | — | Código | `UltronTurnWatchdog.php:166-188` |

Recuento: 4 `env_flag` (filas 6, 14, 19 y 20), 1 `engine_data` (fila 11), 11 `fixed` y 5 `proposal`.

**Lecturas extra (`readings`; no son parámetros)**

| id | Origen | Valor | Lectura | Quién lo cambia |
|---|---|---|---|---|
| `governance.paused` | engine_setting | sí / no | `MarketingAgentSwitch::current()?->paused` | Pausa desde Mercadeo (CRM) |
| `governance.brake` | engine_setting | sí / no | `UltronAbortLatch::engaged()` | Freno de emergencia (consola); se suelta con firma |
| `rules.paymentCanary` | env_flag | configurado: sí / no | `(int) config('marketing.ultron.payment_canary_conversation_id') > 0` | Entorno |
| `knowledge.base` | engine_data | `{version, activeByCategory, pendingReview}`; `activeByCategory` lleva las 14 categorías, también las que están a 0 | `MarketingKnowledgeItem::query()->activeNow()`, `->pendingReview()` y `MarketingKnowledgeBaseService::version()` | Consola o API interna (propone), con aprobación |
| `strategy.plans` | engine_data | `[{name, recommended}]`, 20 como mucho | `Plan::sellable()->orderBy('sort_order')` | Módulo de Planes |

El CRM calcula el estado efectivo del gobierno: `enabled ∧ ¬paused ∧ ¬brake`, combinado con el canario, da «Atiende: no», «solo la conversación canaria» o «todo el tráfico».

**`GET engine/readings`** (permiso `ultron.view`, cupo de lectura):

```jsonc
{"ok":true,"data":{
  "readAt":"2026-10-19T15:00:00+00:00",
  "params":{
    "intent.driver":{"available":true,"value":"openai"},
    "rules.paymentLinksEnabled":{"available":true,"value":false},
    "governance.enabled":{"available":true,"value":true},
    "governance.canaryOnly":{"available":true,"value":true},
    "strategy.offerPriority":{"available":true,"value":["Mensualidad","Trimestral"]}},
  "extras":{
    "governance.paused":{"available":true,"value":false},
    "governance.brake":{"available":true,"value":false},
    "rules.paymentCanary":{"available":true,"value":false},
    "knowledge.base":{"available":true,"value":{"version":"42:1729212345","activeByCategory":{"tone":3,"brand_copy":2},"pendingReview":0}},
    "strategy.plans":{"available":true,"value":[{"name":"Mensualidad","recommended":true}]}},
  "note":"Lectura del proceso web. Tras cambiar el entorno, los workers lo ven cuando se reinician."}}
```

- **Fallos parciales.** Una fuente que falla da `{"available":false}`, sin mensaje técnico, y deja una línea en el log sin datos; las demás siguen. Nunca hay 500 por una lectura parcial.
- **Nunca sale:** la URL del webhook, secretos, claves, ids de conversación (ni el del canario ni el del canario del cobro), teléfonos, precios, ids de planes ni el contenido de la base de conocimiento.
- **Frontera del motor.** `StudioEngineReadings` es la **única** clase nueva de `app/Services/UltronStudio/` que nombra clases del motor; además de ella solo lo hace `StudioRules`, que ya lee `MarketingKnowledgeItem`. Solo llama a métodos de lectura: `current`, `engaged`, `effectiveDriver`, `version`, scopes y `config()`. Una prueba por tokens lo vigila (A9).
- **Matices honestos:**
  - `UltronAbortLatch::engaged()` devuelve `false` si su tabla no existe (y registra un aviso). En producción la tabla existe.
  - Las banderas son las del proceso web (caché de configuración). Un worker sin reiniciar puede ir por detrás (memoria `config-cache-y-los-workers`).

#### A3. Reglas con estructura verificable

**Almacenamiento.** Columna nueva `ultron_studio_rules.conditions json NULL`. `NULL` significa regla documental (sin condiciones); todas las de F quedan así.

**Gramática.** Es la forma canónica que guarda el servidor y que adopta el CRM.

```jsonc
{ "when": [ /* Predicado: de 0 a 3, en conjunción (Y) */ ],
  "then": [ /* Afirmación: de 1 a 3, todas deben cumplirse */ ] }
// Predicados
{"target":"config","layer":"strategy","key":"goal","op":"eq","value":"venta"}
{"target":"config","layer":"persona","key":"tone","op":"in","values":["cercano","profesional"]}
{"target":"edge","edge":"E16","op":"present"}
// Afirmación = predicado + kind (require | forbid | limit)
{"kind":"limit","target":"config","layer":"persona","key":"maxChars","op":"lte","value":300}
// Afirmación de texto: solo en `then`; el Studio no la evalúa
{"kind":"forbid","target":"text","op":"contains","phrases":["gratis","2x1","precio especial"]}
```

| Tipo | `op` admitidos | Valor |
|---|---|---|
| toggle | `eq` | booleano estricto |
| select | `eq` · `in` / `not_in` | una opción del catálogo · `values`: de 1 a n opciones distintas |
| range | `eq` · `lte` / `gte` | entero en la rejilla `min + k·step` · entero en `[min, max]` |
| tags | `contains` / `not_contains` | texto plano de 1 a 60 caracteres tras recortar |
| text, textarea | ninguno (422) | — |
| arista | `present` / `absent` | — |
| texto (solo en `then`) | `contains` | `phrases`: de 1 a 10 frases de 2 a 60 caracteres, texto plano de una línea |

- **Claves exactas** por combinación: no puede sobrar ni faltar ninguna. `kind: limit` solo vale con `lte`/`gte` sobre un `range`.
- **Sin repeticiones:** ni en `when` ni en `then` puede haber dos elementos sobre la misma variable. En el texto, la variable es cada frase.
- **Capas:**
  - `layers` de la regla tiene que incluir la capa de cada objetivo de configuración.
  - Para una arista basta con una de sus dos capas.
  - El texto no exige capa.
  - Se valida, no se rellena solo (422 en `layers`).
- **Tamaño:** `conditions` codificado ocupa 4 KB como mucho.
- **Canonicalización:**
  - `values` va en el orden de las opciones del catálogo y sin repetidos.
  - Etiquetas y frases se recortan con el mismo conjunto de espacios que `String.prototype.trim()` (`StudioDraftNormalizer::JS_TRIM`, :58).
  - Las frases, además, colapsan los espacios internos a uno y pasan a minúsculas solo en A–Z y ÁÉÍÓÚÜÑ. No se usa `ext-intl`: producción no la tiene (memoria `recibos-termicos-caja`).
  - Se descartan las frases repetidas, conservando la primera.
  - No hay expresiones regulares de usuario en ningún sitio.

**Semántica (idéntica en PHP y en TS).** Cada predicado es una *variable* con un *conjunto permitido* dentro de su dominio.

| Variable | Dominio | Conjunto permitido |
|---|---|---|
| `config:capa.clave` (toggle) | `[true, false]` | `eq v` → `{v}` |
| `config:capa.clave` (select) | Las opciones, en su orden | `eq v` → `{v}`; `in S` → `S`; `not_in S` → dominio − `S` |
| `config:capa.clave` (range) | `min, min+step, …, ≤ max` | `eq v` → `{v}`; `lte v` → `{x ≤ v}`; `gte v` → `{x ≥ v}` |
| `config:capa.clave#etiqueta` (tags) | `[true, false]` | `contains` → `{true}`; `not_contains` → `{false}` |
| `edge:Exx` | `[present, absent]`; **si es obligatoria, solo `[present]`** | `present` / `absent` |
| `text#frase` | `[true, false]` | `require` → `{true}`; `forbid` → `{false}` |

`require P` = el conjunto permitido de P; `forbid P` = el dominio menos ese conjunto; `limit` equivale a `require`.

**Controles del motor.** Son pseudorreglas y siempre salen del código, así que no dependen de que haya corrido la importación.
1. **Restricciones de nivel `blocking` del catálogo:**
   - `forbidden v` se traduce en `forbid eq v`.
   - `when w` con `requires [E…]` se traduce en `when eq w` ⇒ `require E present`, una por arista.
   - `forbids` se traduce en `forbid E present`.
   - Las de nivel `warning` (`apagado`, `cobro-aprobacion`, `cobro-sin-bandera`) NO son controles: son avisos.
2. **Aristas obligatorias:** su dominio es solo `[present]`.
3. **Las condiciones de las reglas del código.** El catálogo las publica como `legacyConditions`, para que el TS y el PHP usen el mismo conjunto de controles.

**Algoritmos.** Son deterministas y se aplican en este orden.
- **Imposible** (422): una afirmación cuyo conjunto permitido está vacío. Ejemplos: `maxChars gte 500` con un máximo de 480; `absent` sobre una arista obligatoria.
- **Nunca se aplicaría** (422): un `when` cuyo conjunto está vacío.
- **Se contradice a sí misma** (422): una afirmación sobre una variable que también está en su propio `when`, con intersección vacía.
- **Vacía** (se guarda; `analysis` la marca como `regla-vacia`): una afirmación cuyo conjunto permitido es todo el dominio.
- **Contradicción entre X e Y** (por pares). Hay contradicción si se cumplen las dos condiciones:
  - para toda variable común a los dos `when`, las intersecciones no son vacías;
  - hay una afirmación de X y otra de Y sobre la misma variable cuya intersección es vacía.
  - **Limitación declarada:** no encadena reglas. Si X implica v, v implica w y w implica no-X, no se detecta.
- **Incompatible con el catálogo:** lo que `canonicalize` rechaza con el catálogo actual (capa, parámetro, opción o arista que ya no existe), más los `refs.configs` y `refs.edges` desconocidos. Al escribir es un 422; después de un cambio de catálogo se detecta al analizar y al validar.

**Al escribir** (`POST rules` y `PATCH rules/{uuid}` de una S-NNN):
- **422 estándar de Laravel**, con claves `conditions.*` o `layers`, para la forma, lo imposible, lo que nunca se aplicaría, la autocontradicción y la **contradicción con un control**. Mensajes, solo en el servidor:
  - `Contradice R-02 (la aplica el código): «Puede ofrecer descuentos» tiene que ser «no».`
  - `Contradice un control del motor («Hechos sin verificar»).`
  - `«borrador, veredicto y token → commit» es obligatoria en el motor: no se puede exigir que falte.`
- **409 `rule_contradiction`** si contradice otra S-NNN viva: `{ok:false, code, message, conflicts:[{code, uuid, revision, target, label}]}`. No se audita, igual que los demás conflictos de F.
- **Concurrencia.** `create` y `update` toman el MISMO cerrojo de transacción que hoy reparte los códigos (`pg_advisory_xact_lock(crc32('ultron_studio_rules:code'))`, `StudioRules.php:559-564`) antes de leer las reglas vivas, y `importLegacyBaseline` también. Sin ese cerrojo, dos escrituras simultáneas y contradictorias entre sí pasarían las dos. SQLite ya escribe en serie.
- Las R-xx siguen respondiendo 409 `legacy_rule_read_only`.
- Archivar no necesita cerrojo: quitar una regla no crea contradicciones.

**Análisis.** Va en cada `RuleDto` como `analysis`; lo calcula el servidor sobre las reglas vivas y los controles.

```jsonc
"analysis":{"verification":"studio","issues":[{"id":"regla-contradice-regla","level":"blocking","with":["S-002"],"target":"config:persona.maxChars"}]}
```

- **`verification`:**
  - `studio`: al menos una afirmación de configuración o de arista.
  - `lab`: solo texto; se podrá comprobar en el laboratorio (Hito 4).
  - `none`: sin condiciones.
- **`issues[].id`:** `regla-incompatible` (blocking), `regla-contradice-control` (blocking), `regla-contradice-regla` (blocking), `regla-inaplicable` (warning) y `regla-vacia` (warning). Con la prevención al escribir, las tres primeras solo aparecen si cambió el catálogo o el código.
- **`with`:** códigos de regla, `control:<id de restricción>` o `edge:<id>`.
- **Paridad TS↔PHP:** sobre `id`, `level`, `with` y `target`. Los textos los pone el CRM.

**Evaluación contra un contenido** (un borrador o una versión de formato 2). Los hallazgos se AÑADEN al final de los de F, en este orden. Los textos son literales y los fija el fixture para los dos lados.

| id | Nivel | Título | Detalle | `layer` |
|---|---|---|---|---|
| `regla-incompatible-<code>` | blocking | `La regla <code> no encaja con el catálogo` | `Cita lo que el catálogo <versión> no tiene: <capa.clave o Exx, separados por ", ">.` | — |
| `reglas-contradictorias` | blocking | `Reglas que se contradicen` | Pares `<A> ↔ <B> (<etiqueta>)` separados por ` · `; `<B>` es un código o `control «<título>»` | — |
| `regla-<code>` | **warning** | `<code> no se cumple` | `<descripción> (<etiqueta>: <valor actual>[; …])` | La capa del primer objetivo incumplido; si es una arista, la de su puerto destino |
| `reglas` | ok | `Las reglas del Studio se cumplen` | `Comprobadas: <códigos separados por ", ">.` | — |
| `reglas-sin-aplicacion` | warning | `Reglas que el motor no aplica` | `<códigos separados por ", ">: son propuestas del Studio; aprobar no las hace cumplir.` | — |

- **Qué se evalúa:**
  - Solo las S-NNN vivas.
  - Las R-xx no generan `regla-<code>`: sus comprobaciones ya son las restricciones `config-*` del catálogo, enlazadas por la clave `rule`.
  - Una regla cuyo `when` no se cumple no da hallazgo.
  - Una afirmación de texto nunca se evalúa, porque en el Studio no hay texto.
  - Una variable que el contenido no tiene (una versión de un catálogo viejo) no se evalúa.
- **Cuándo salen `reglas` y `reglas-sin-aplicacion`:**
  - `reglas` sale si no hay ningún `regla-<code>` ni ningún hallazgo de reglas de nivel bloqueo, y se comprobó al menos una S-NNN con afirmaciones evaluables.
  - `reglas-sin-aplicacion` sale si el conjunto tiene al menos una S-NNN, con condiciones o sin ellas.
- **Formato de los valores:**
  - toggle: `sí` / `no`.
  - select: la `label` de la opción; si la opción no existe, el valor tal cual.
  - range: el entero.
  - tags: unidas por `, `, y `(vacío)` si no hay ninguna.
  - arista: `presente` / `ausente`.
  - La etiqueta es la `label` del parámetro, o `«<label de la arista>»`.
- **Con qué reglas se evalúa:**
  - El borrador, con las reglas vivas (no archivadas): las mismas que copiará la versión.
  - Una versión, con SUS reglas copiadas (`content.rules`), nunca con las vivas. Así, revalidar en `approve` sigue dependiendo solo del catálogo.
  - Las versiones de formato 1 (F) se validan exactamente como en F, sin hallazgos de reglas: su conjunto de avisos no cambia bajo los pies de quien aprueba.
- **Consecuencia de producto:** toda versión con alguna S-NNN queda en `REVIEW_REQUIRED` por `reglas-sin-aplicacion`. Ya ocurría con `opcional-E14/E16` en cualquier versión realista.

**Transcripción de las reglas del código, sin inventar.** Va en `StudioCatalog::legacyRules()`; las que tienen condiciones se publican también como `legacyConditions`.

| Regla | `conditions` | `refs.edges` nuevas | Base |
|---|---|---|---|
| R-01 | `then: require knowledge.strictFacts eq true` | E07, E19 | La referencia ya existente (:121) equivale a la restricción `hechos`. Auditoría §5: «planes → plan y precio» en el commit y marcadores al redactor |
| R-02 | `then: require strategy.discountsAllowed eq false` + `forbid text contains ["gratis","2x1","precio especial"]` | — | La referencia existente (:122) equivale a `descuentos`. Las tres frases están en su descripción. El código aplica más patrones, así que la condición de texto es parcial y documental |
| R-07 | `then: require rules.handoffOnlyCorroborated eq true` | — | La referencia existente (:127) equivale a `derivacion` |
| R-12 | `then: require governance.canaryOnly eq true` | E12, E13 | La referencia existente (:132) equivale a `canario`. Son las aristas que llevan el canario al commit y a las puertas |
| R-04, R-05, R-09 | `null` | E18 | Auditoría §5: pago, membresía y perfil → commit (la etiqueta de E18 lo dice tal cual) |
| R-10 | `null` | E08, E19 | Auditoría §5: `brand_copy` → `ComposerStyleGuard`. E08 lleva `brand_copy` al redactor |
| R-03, R-06, R-08, R-11 | `null` | — | Se aplican sobre el texto o en el despacho: el Studio no puede comprobarlas |

- **Restricciones con `rule`:** `hechos`→R-01, `descuentos`→R-02, `derivacion`→R-07 y `canario`→R-12. El resto no tiene una regla R-xx.
- **Pruebas:**
  - El conjunto permitido de cada afirmación R-xx es igual al de su restricción.
  - Las 12 reglas del código no tienen contradicciones ni incompatibilidades.
- **Revisión:** el reviewer valida la tabla de aristas contra la auditoría §5.

#### A4. Historial y comparación

- **Borrador.**
  - `DraftDto` gana `uuid` (null si no hay fila).
  - El CRM pide `GET audit?subjectType=draft&subjectUuid=<uuid>&per_page=100`; esa ruta ya existe y ya filtra por dueño (`StudioAudit.php:164-168`).
  - No se pierde nada: los valores de configuración caben en la cota (etiquetas de 60 caracteres como mucho y 20 elementos como mucho, `StudioDraftNormalizer.php:42-44`), las aristas son 19 como mucho y la disposición va como booleano (no cambia el comportamiento).
- **Regla.**
  - El CRM pide `GET audit?subjectType=rule&subjectUuid=<uuid>`.
  - `StudioAudit::record()` gana un parámetro de cota (120 por defecto); los eventos `rule.*` pasan 500, que es la longitud de `description` y de `archive_reason`.
  - `rule.created` y `rule.updated` incluyen `conditions`.
  - Lo anterior a G queda como está (recortado a 120), y la vista lo dice.
- **Versión:** sus eventos `version.*`, que ya se pueden filtrar.
- **Render legible** (CRM, `studio-diff-text.ts`, puro). Ejemplos:
  - «Personalidad y tono · Longitud máxima: 320 → 400».
  - «Conexión añadida: enlace de pago como mensaje aparte».
  - «Condiciones: antes «…» · ahora «…»».
  - «Se movieron nodos (no cambia nada)».
  - Las etiquetas salen del catálogo; lo que el catálogo actual no conozca, se enseña en crudo.
- **Por capa.**
  - El inspector filtra los 100 eventos más recientes del borrador por `configChanges[].layer` y por las aristas que tocan la capa, y lo dice («en los 100 cambios más recientes»).
  - No hay filtro JSON en SQL: en PG `json` no tiene operadores y F lo evita a propósito.
- **Por qué no hay tabla nueva.** El historial del borrador ya no pierde nada, y el de las reglas tampoco perderá con la cota de 500; las versiones copian todo. Una tabla de revisiones solo añadiría instantáneas de disposición, que no cambian el comportamiento. Se mantiene la decisión F1.

**`GET compare?from=<baseline|draft|uuid>&to=<baseline|draft|uuid>`**
- Permiso `ultron.view`, cupo de lectura, y `from ≠ to`.
- Qué es cada lado:
  - `baseline`: el contenido de `LEGACY_BASELINE_V1` más las reglas de `StudioCatalog::legacyRules()`.
  - `draft`: el borrador DE QUIEN PIDE (o la línea base si no tiene) más las reglas vivas.
  - `uuid`: la versión y sus reglas copiadas. Las versiones ya son visibles para todo `ultron.view`.
- Errores: 422 con parámetros inválidos; 404 `version_not_found`.

```jsonc
{"ok":true,"data":{
 "from":{"kind":"version","uuid":"…","label":"Cobro con enlaces","catalogVersion":"2026-10-18.1","format":1},
 "to":{"kind":"draft","label":"Mi borrador","catalogVersion":"2026-10-19.1","format":2},
 "same":false,
 "edges":{"added":[{"id":"E14","label":"enlace de pago como mensaje aparte"}],"removed":[]},
 "configs":[{"layer":"persona","key":"maxChars","label":"Longitud máxima","from":320,"to":400}],
 "rules":{"added":[{"code":"S-004","description":"…"}],"removed":[],
          "changed":[{"code":"R-02","fromRevision":1,"toRevision":2,"fields":["refs","conditions"]}]},
 "warnings":{"added":["regla-S-004"],"removed":[]},
 "catalogChanged":true}}
```

- `same`: no hay diferencias estructurales (aristas, configuración, reglas). No compara huellas entre catálogos distintos.
- `warnings`: los avisos de cada lado, validado contra el catálogo ACTUAL (cada versión según su formato).
- La función es pura y se prueba en Unit: `StudioContentDiff::compare(array $a, array $b, array $catalog)`.

#### A5. Inspector funcional y CRM, sin rehacer el tablero

**Presupuesto de estilos (restricción dura).**
- `ultron-studio.scss` está en 32.718 bytes de fuente y 31,04 kB compilados, con un tope de 32 kB (`ULTRON_STUDIO_FINAL_REPORT.md:73`). G no le añade **ni un byte**.
- Todo lo visual nuevo va en componentes con su propia hoja: aviso a 8 kB y error a 32 kB (`angular.json:80-82`).
- Nada de animar `transform` en los nodos Foblex.

**Pestañas del inspector de capa.** Usan el patrón de tablist que ya existe (`studio-inspector.ts:253-258,371-379`): Resumen · Configuración · Reglas · Dependencias · Historial.

1. **Resumen.** Lo de hoy, más un bloque «Motor ahora» con las lecturas de la capa:
   - Gobierno: interruptor, pausa, freno, canario y el estado efectivo.
   - Conocimiento: versión, activos por categoría y pendientes.
   - Estrategia: planes, con la insignia.
   - Intención: el driver.
   - Reglas: la bandera y el canario del cobro.
   - Una capa sin lecturas dice «Nada de esta capa se lee en vivo: está fijo en código o en n8n».
2. **Configuración.** Una fila por parámetro (`studio-param-row.ts`), con:
   - El control del borrador (el de hoy).
   - Lo que dice el motor: «Motor: <valor>», «Fijo en código: <valor>», «Vive en n8n» o «Sin equivalente en el motor».
   - La insignia de origen, el efecto y `changeVia`.
   - La marca «≠ motor», cuando el parámetro es `comparable`, los valores difieren y el borrador no tiene `engineOption`.
   - Chips de las reglas que lo citan (código o Studio; cumple o no cumple).
   - Acciones:
     - «Usar el valor del motor», solo con `live`/`declared` y `comparable`. Va al borrador, con deshacer, mediante `store.setConfig`.
     - «Volver a la línea base».
   - Un aviso fijo arriba: «Borrador: el motor no lo lee».
3. **Reglas.** Las que tocan la capa, por `layers` o por los objetivos de sus condiciones. De cada una:
   - Las condiciones en frase (`studio-conditions-text.ts`).
   - Las insignias «La aplica el motor» o «El motor no la aplica».
   - La verificación (`studio`, `lab` o `none`).
   - Su estado en la configuración abierta: cumple, no cumple, no aplica (porque el «si» no se da) o no comprobable.
   - Los `issues` del análisis.
4. **Dependencias.** Lo de hoy, más:
   - Por arista: «exigida por» (obligatoria, restricción con su condición, reglas) y «prohibida por».
   - Por parámetro: qué restricciones y reglas lo usan y qué aristas activa. Lo calcula `ruleRelations` (pura).
5. **Historial.**
   - Borrador: los eventos del borrador, filtrados por la capa.
   - Versión: los eventos de la versión.
   - Línea base: «La línea base no tiene historial: es el código».

**Prevención en la edición.**
- Al proponer un valor (`propose`, `studio-inspector.ts:342-350`), el inspector calcula los hallazgos de F más los de reglas con el valor nuevo.
- Si aparece un bloqueo o un `regla-*` nuevo, pide confirmación y lista qué se incumple. Usa el mismo cuadro que los parámetros importantes.
- En el lienzo, conectar o quitar una arista opcional avisa si cambia el cumplimiento de alguna regla, sin tocar `checkConnection`.

**Inspector de arista:** «Exigida por / Prohibida por» y su estado actual.

**Panel de reglas** (`studio-rules-panel.ts`):
- Editor de condiciones (`studio-conditions-editor.ts`):
  - «Si…»: de 0 a 3 filas; «entonces…»: de 1 a 3 filas.
  - Selector de parámetro por capa, operadores filtrados por tipo, valor según el tipo y frases para el texto.
- Menciones opcionales: categorías y claves de la base de conocimiento.
- Vista previa en frase.
- Contradicciones calculadas en vivo con `conflictsFor` antes de enviar.
- Manejo del 409 `rule_contradiction`: lista de conflictos, con enlace a cada regla.
- Insignias de `analysis` en cada regla.
- «Historial» por regla (`studio-history-list.ts`).

**Panel de versiones:** «Comparar con…» (la línea base, mi borrador u otra versión) abre `studio-compare-view.ts`.

**Panel de auditoría:** el `<pre>` se sustituye por `studio-history-list.ts`, legible, y se añade el filtro «Mi borrador».

**Página** (`ultron-studio-page.ts`, solo TypeScript y plantilla):
- Pasa al inspector y al panel de validación la lista combinada (hallazgos de F más los de reglas).
- Arranca la lectura del motor sin bloquear la carga.

**Sesión** (`studio-session.ts`):
- Señal `engine` y `refreshEngine()`. Se pide cuando la sesión queda `ready`; si falla, el Studio sigue y el inspector dice «No se pudo leer el motor», con «Reintentar».
- Señales calculadas `ruleFindings` y `liveFindings`:
  - Borrador: con las reglas vivas.
  - Versión de formato 2: con sus reglas copiadas.
  - Formato 1 y línea base: sin reglas.
- `loadHistory(subject, page)` y `compare(from, to)`.
- Crear y editar reglas con `conditions`.
- La comprobación «el servidor manda si discrepan» pasa a hacerse sobre la lista combinada.

**Datos** (`studio-model.ts`, `studio-api.types.ts`, `studio-parse.ts`, `studio-api.service.ts`):
- Los tipos de A2, A3, A4 y A6.
- `readCatalog` exige `bindings`, `readings` y `legacyConditions`: un catálogo ilegible lleva a `error`, como en F.
- `readEngineReadings` falla suave: las entradas inválidas se descartan y se cuentan.
- `StudioErrorCode` gana `rule_contradiction`.

#### A6. API (`/api/admin/ultron-studio`)

| # | Ruta | Cambio | Permiso | Cupo |
|---|---|---|---|---|
| 2 | `GET catalog` | Versión nueva; `bindings`, `readings`, `legacyConditions` y `rule` en las restricciones | view | lectura |
| 3–5 | `GET draft`, `PUT draft`, `POST draft/discard` | `uuid`; hallazgos de reglas | view / edit_draft | sin cambios |
| 7 | `POST versions` | `content.format = 2`; reglas con `conditions`; 422 `validation_blocked` también con hallazgos de reglas de nivel bloqueo | edit_draft | sin cambios |
| 8–12 | `GET versions/{uuid}`, `validate`, `submit`, `approve`, `reject` | Hallazgos según el formato | sin cambios | sin cambios |
| 15 | `GET rules` | `conditions` y `analysis` | view | sin cambios |
| 16–17 | `POST rules`, `PATCH rules/{uuid}` | `conditions`; 422 nuevos; 409 `rule_contradiction` | manage_rules | sin cambios |
| 20 | **`GET engine/readings`** | Nueva | **view** | lectura |
| 21 | **`GET compare`** | Nueva | **view** | lectura |

- **Permisos.**
  - `AuthorizationMap::OVERRIDES` gana `'GET api/admin/ultron-studio/engine/readings' => 'ultron.view'` y `'GET api/admin/ultron-studio/compare' => 'ultron.view'`, junto a `AuthorizationMap.php:662-684`.
  - `PermissionCatalog` no cambia, porque no hay llaves nuevas; `permissions:export` debería salir idéntico (hay que verificarlo).
  - `ultron.manage_settings` sigue pospuesto: G no escribe ajustes.
- **Rutas.** Las dos van en el grupo `throttle:ultron-studio-read`. `engine/readings` es un literal y `compare` usa la query string, así que no chocan con `versions/{uuid}`.
- **Pruebas que se actualizan en el mismo commit:** `UltronEngineIsolationTest` (ALLOWED más la migración nueva; de 19 a 21 rutas) y `UltronStudioPermissionsTest` (ROUTES con 21).
- **Validación de `conditions`.** El controlador solo valida la FORMA (`array:when,then`, listas y tamaños). La semántica la hace `StudioRuleCoherence::canonicalize`, que devuelve un 422 con todas las claves a la vez, como el normalizador de F.
- **Errores nuevos:** solo el 409 `rule_contradiction`.

#### A7. Datos, huellas, formato y compatibilidad

**Migración `2026_10_19_100001_add_conditions_to_ultron_studio_rules.php`** (database-specialist):
- `Schema::table('ultron_studio_rules', fn ($t) => $t->json('conditions')->nullable())`. En SQLite es un `ALTER TABLE … ADD COLUMN`, sin reconstruir la tabla. En PG es instantánea; en el despliegue va con `lock_timeout`.
- Comprobar en los dos motores que los disparadores siguen ahí (`usr_forbid_delete`, y en PG también `usr_forbid_truncate` y los CHECK): 7 disparadores en SQLite y 11 en PG. La cabecera de `2026_10_18_100002` avisa de que una reconstrucción en SQLite se los lleva.
- `down()` quita la columna. Si en SQLite eso reconstruye la tabla, la misma migración vuelve a crear `usr_forbid_delete`. Se comprueba con `migrate:rollback` seguido de `migrate`, contando los disparadores.
- Sin CHECK nuevo (la forma la garantiza el servicio), sin `jsonb` y sin índices.
- Hay que añadirla a `UltronEngineIsolationTest::ALLOWED`.

**Modelo:** `StudioRule` gana el cast `conditions => array`. `conditionalWrite` (`StudioRules.php:523-550`) admite `conditions` en las S-NNN, porque no es identidad ni estado.

**Huellas (`StudioContentHasher`):**
- `ruleHash` añade `conditions` SOLO cuando no es null. Así, las reglas de F conservan su huella, y la de las R-xx cambia al sincronizarse, que es lo esperado.
- `versionHash` depende del formato:
  - Formato 1: exactamente lo de hoy (`VERSION_RULE_FIELDS`, :34).
  - Formato 2: además, `conditions` (null incluido) en cada regla y `format` dentro del comportamiento.
  - `integrityOk` (`StudioVersions.php:409-423`) elige con `content.format ?? 1`.
- El borrador no cambia de forma; su huella cambia porque cambia `catalogVersion`.

**Catálogo:**
- `VERSION` sube (propuesta: `2026-10-19.1`, la fecha del día) y su huella se añade a `StudioCatalogTest::HUELLAS`.
- Los datos de `graph()` no cambian; lo único nuevo en las restricciones es la clave `rule`, que el validador ignora. `parameters()` solo cambia en los dos textos de `intent.driver` de A2.
- Por tanto, ningún `expected` de `validation-cases.json` debe cambiar: solo su `catalogVersion`. **Si cambia un solo `expected`, G tocó el grafo y se para.**
- La prueba de transcripción (`StudioCatalogTest.php:334-360`) pasa a «la transcripción de F solo cambia donde G lo declara»: quita `rule`, `conditions` y `refs`, y admite exactamente las dos diferencias de textos de `intent.driver`.

**Compatibilidad**

| Situación | Resultado |
|---|---|
| Backend de G con el CRM de F (ventana de despliegue) | Funciona: el parser de F ignora las claves nuevas y los hallazgos de reglas del servidor mandan sobre la lista local |
| CRM de G con el backend de F | Lo impide el orden de despliegue: `readCatalog` exige `bindings` → fase `error` (falla cerrado, como en F) |
| Versiones creadas en F (formato 1) | Se leen, validan, envían y aprueban como en F: sin hallazgos de reglas y con la integridad de las reglas de F |
| Volver el backend a F después de usar G | Con el código de F, las versiones de formato 2 darían `integrityOk = false` porque su huella incluye `conditions`: se leen, pero no se envían ni se aprueban. La columna se queda (F la ignora). Las R-xx se resincronizarían a F con `import-baseline` |

#### A8. Auditoría

- **Eventos:** no hay eventos nuevos.
  - `rule.created` lleva `conditions` en su `diff`.
  - `rule.updated` lista `conditions` como un campo más en `changes`.
  - `rule.legacy_synced` muestra el cambio de `conditions` y `refs` de las R-xx al importar.
- **Cota de valores:** 500 para `rule.*` y 120 para el resto, sin cambios.
- **No se auditan:** `GET engine/readings`, `GET compare`, el análisis, los 422 ni el 409 `rule_contradiction`. En ninguno cambió nada (política de F, `StudioAudit.php:22-23`).
- **Datos:** la auditoría sigue sin IP ni user agent y sin datos de prospectos.

#### A9. Pruebas

**Paridad nueva: `tests/fixtures/ultron-studio/coherence-cases.json` (versión 1).** **Lo escribe el tester a partir de este contrato**; no lo genera ninguna de las dos implementaciones.

```jsonc
{"version":1,"catalogVersion":"2026-10-19.1",
 "canonicalize":[{"name":"…","input":{},"expected":{"conditions":null,"errorKeys":["conditions.then.0.value"]}}],
 "analyze":[{"name":"…","rules":[/* RuleInput */],"expected":{"S-001":{"verification":"studio","issues":[{"id":"…","level":"…","with":[],"target":"…"}]}}}],
 "conflicts":[{"name":"…","candidate":{/* RuleInput */},"live":[/* RuleInput */],"expected":{"controls":[],"rules":[]}}],
 "evaluate":[{"name":"…","rules":[/* RuleInput */],"definition":{},"configs":{},"expected":[/* Finding */]}]}
// RuleInput = {code, source, enforcement, status, description, layers, conditions, refs}; catálogo: siempre el real.
```

- **Al menos 45 casos:**
  - Cada tipo con cada `op`.
  - Imposible, nunca aplicable, autocontradicción y vacía.
  - Contradicción entre reglas del Studio en toggle, select, range (rejilla con `step`), tags, arista y frase.
  - `when` incompatibles que la evitan (`goal = venta` frente a `goal = cita`).
  - Contra cada control de nivel bloqueo: `hechos`, `descuentos`, `critic`, `derivacion`, `hermes`, `canario`, `cobro-sin-camino`, arista obligatoria y frases de R-02.
  - Las 12 R-xx sin `issues`.
  - Incompatibles: parámetro, opción y arista inexistentes.
  - Evaluación: con `when` cumplido y sin cumplir, etiquetas, aristas, texto no evaluado, orden de los hallazgos, `reglas` y `reglas-sin-aplicacion`, cada formato de valor y `(vacío)`.
- **Quién lo comprueba:** `StudioCoherenceParityTest` (PHP) y `studio-rules-coherence.parity.spec.ts` (TS) exigen el `expected` exacto. Si una implementación discrepa, se corrige ella; el fixture solo cambia con una enmienda del contrato.
- **Exportación:** `ultron-studio:export-fixtures` exporta además `testing/backend-coherence-cases.testing.ts`.

**Backend, Unit:**
- `StudioRuleCoherenceTest`: cada algoritmo, y su limitación declarada como prueba explícita.
- `StudioContentDiffTest`.
- `StudioContentHasherTest`:
  - el formato 1, idéntico a hoy, con un vector conocido de F;
  - el formato 2, con `conditions`;
  - una regla sin condiciones conserva la huella de F.
- `StudioCatalogTest`:
  - la huella nueva;
  - `bindings` completos y válidos, y las lecturas descritas;
  - cada R-xx equivale a su restricción y las 12 son coherentes;
  - textos sin rutas ni variables de entorno;
  - la transcripción de F solo cambia donde G lo declara.
- Deriva de lo declarado:
  - `UltronDecideService::V1_ALLOWED_TOOLS` contiene la cortesía (constante);
  - `recentMessages` sigue con `limit(10)` (por tokens).
- `ExportStudioFixturesCommandTest`: tres ficheros.

**Backend, Feature:**
- **`UltronStudioRuleConditionsTest`:**
  - crear y editar con condiciones, y que vuelva la forma canónica;
  - un 422 por cada regla de forma y de semántica, y un 422 contra cada control;
  - 409 `rule_contradiction` con `conflicts`;
  - las R-xx responden 409;
  - capas que incluyen las de los objetivos;
  - auditoría con `conditions` y con la descripción de 500 caracteres sin recortar;
  - `analysis` en `GET rules`.
- **`UltronStudioEngineReadingsTest`:**
  - cada lectura, con la configuración y las filas sembradas (pausa, freno, conocimiento y planes);
  - `fake` se lee como `rules`;
  - una fuente caída da `available:false` y la respuesta sigue siendo 200;
  - **la respuesta no contiene** la URL del webhook, el id del canario ni el del canario del cobro (se siembran valores reconocibles), `Bearer`, `sk-` ni precios.
- **`UltronStudioCompareTest`:**
  - las combinaciones de `baseline`, `draft` y `uuid`;
  - `draft` es siempre el de quien pide: el borrador de otra persona no se filtra;
  - 404 y 422.
- **`UltronStudioVersionFormatTest`:**
  - una versión de formato 1 sembrada con el código de F tiene integridad, se valida y se aprueba como en F;
  - una de formato 2 tiene hallazgos de reglas y copia las reglas con `conditions`, y los avisos que hay que reconocer incluyen `regla-*` y `reglas-sin-aplicacion`.
- **`UltronStudioDraftTest` (ampliado):** el `uuid` y los hallazgos calculados con las reglas vivas.
- **`UltronStudioEngineReadOnlyTest` (aislamiento ampliado):**
  1. *Estático, por tokens.* En `app/Services/UltronStudio/` solo `StudioEngineReadings.php` y `StudioRules.php` nombran clases del motor (`App\Services\Marketing\…`, `App\Models\Marketing*`, `App\Models\Plan`, `UltronAbort*`, `SalesAiConfig`). En esos ficheros no aparece ninguna llamada de escritura (`set`, `engage`, `release`, `save`, `update`, `delete`, `create`, `forceCreate`, `forceFill`, `fill`, `insert`, `upsert`, `increment`, `decrement`, `approve`, `reject`, `truncate`, `DB::statement`), salvo las de `StudioRules` sobre sus propios modelos `UltronStudio\*`.
  2. *De comportamiento.*
     - Se siembran la fila de la pausa, un freno abierto, ítems de conocimiento y planes.
     - Se toma una huella de esas tablas, de `config('marketing')` y del recuento de colas.
     - Con `Queue::fake()` y `Http::fake()`, se recorren TODAS las rutas del Studio con escrituras reales: cada parámetro a un valor distinto de la línea base, reglas con condiciones, versión, envío, aprobación por otra persona, rechazo, publicar y revertir (409), lecturas y comparación.
     - Al final, las huellas son idénticas, no se encoló nada y no salió ninguna petición.
  3. `UltronEngineIsolationTest` sigue en verde (el motor no nombra el Studio) y `git diff origin/main` sobre las zonas del motor sale vacío.
- **Sin cambios de comportamiento:** `UltronStudioPermissionsTest` (21 rutas × perfiles), `UltronStudioHardeningTest`, `AuthorizationCoverageTest` y `RolePolicyTest`. `auth:coverage --unmapped` sale vacío.

**PostgreSQL 14 desechable** (memoria `postgres-local-para-pruebas`):
- Los grupos UltronStudio y Unit, en PG.
- La migración, con un rol sin superusuario.
- Nada de `DISTINCT` ni `GROUP BY` sobre columnas `json`: PG no tiene igualdad para `json`.

**Concurrencia** (tester, con la pila real: `artisan serve --no-reload` y PG; barrera y escalón de 40 ms como en F, memoria `artisan-serve-workers-no-reload`):
- Dos `POST rules` contradictorias entre sí, a la vez: un 201 y un 409 `rule_contradiction`, nunca dos 201.
- Dos `PATCH` que se contradicen entre sí: lo mismo.
- `PATCH` contra `import-baseline`.
- `POST rules` con el tope de 100.
- Las carreras de borrador de F, como regresión: dos `PUT` con la misma revisión dan un 200 y un 409.
- 20 repeticiones, naturales y forzadas.
- Mutación: quitar el cerrojo de `update` tiene que hacer fallar la prueba.

**CRM (vitest):**
- Núcleo puro: `studio-rules-coherence.spec.ts` y su paridad, `studio-conditions-text.spec.ts` y `studio-diff-text.spec.ts`.
- `studio-parse.spec.ts`: `bindings`, `readings`, `legacyConditions`, `conditions`, `analysis`, `uuid` y `compare`; un catálogo sin `bindings` lleva a error.
- `studio-api.service.spec.ts`: las 2 rutas nuevas y `conditions` en camelCase.
- `studio-session.spec.ts`: una lectura del motor que falla no tumba el Studio; `liveFindings` según la selección y el formato.
- Componentes: specs de `studio-param-row`, `studio-conditions-editor`, `studio-history-list` y `studio-compare-view`.
- Inspector: 5 pestañas, teclado y confirmación preventiva.
- Panel de reglas: el 409 con sus conflictos.
- `ultron-studio-page.spec.ts`, sin regresiones.

**E2E** (pila real, dos cuentas):
1. El inspector enseña los valores del motor (entorno de prueba con el interruptor y el canario puestos) y la marca «≠ motor».
2. Se crea S-001, «Longitud máxima ≤ 300».
3. El borrador a 400 da un aviso en vivo, el mismo que devuelve el servidor al guardar.
4. La versión queda en `REVIEW_REQUIRED` con `regla-S-001` y `reglas-sin-aplicacion`, y la otra cuenta la aprueba reconociéndolos.
5. Una regla «≥ 320» da 409, con enlace a S-001.
6. Una regla «descuentos = sí» da 422 por R-02.
7. Historial legible del borrador y de la regla, con la descripción completa.
8. Comparación de una versión con la línea base.
9. Recepción no ve el menú y recibe 403.
10. Teclado en las pestañas nuevas; escritorio, tableta y móvil.
11. Al terminar, la huella de las tablas del motor es igual que al empezar.

**Regresión:**
- Suite completa del backend contra una línea base sacada **el mismo día, en HEAD limpio** (memoria `suite-backend-depende-del-calendario`).
- CRM: `npm run test:all` con sus 16 fallos conocidos y ninguno nuevo.
- `check:literals` y `test:agenda-tz`.
- `ng build --configuration=production` sin error de presupuesto: la página igual y cada hoja nueva por debajo de 8 kB.
- El chunk del Studio, sin nombres del motor ni textos operativos.


## Enmienda 1 (18-10-2026): lo que fija el fixture de coherencia

`tests/fixtures/ultron-studio/coherence-cases.json` (123 casos escritos a mano desde este contrato, en las cuatro secciones de A9: `canonicalize`, `analyze`, `conflicts`, `evaluate`) y su `coherence-cases.README.md` forman parte del contrato: **todo lo que el README declara que «el contrato no fijaba y fija este fichero» queda congelado** (esquema de claves de los 422 y su orden canónico; tamaño en bytes UTF-8 del JSON compacto, 4096; longitudes en puntos de código sobre el valor canónico; `when` obligatorio aunque vacío; `layers` cuenta también los objetivos de `when`; `lte`/`gte` admiten cualquier entero de `[min, max]` y un valor fuera de rango es error de valor, no «imposible»; lista y orden de los controles —restricciones bloqueantes, aristas obligatorias como `edge:<id>`, `legacyConditions`—; contradicción por pares al pie de la letra, incluidas sus consecuencias: dos reglas con `when` sin variables comunes se contradicen, y exigir una arista de cobro ausente sin `when` choca con `cobro-sin-camino`; las R-xx nunca reciben issues de contradicción; qué cuenta como «Comprobadas»; `evaluate.expected` solo con hallazgos de reglas). Si PHP o TS discrepan del fixture, se corrige la implementación, nunca el fixture. Las reglas del código sin condiciones llevan `conditions: null` explícito. El diff de A4 no tiene paridad: lo calcula solo el servidor (`GET compare`) y el CRM lo pinta. Decisiones del lead sobre las preguntas del tester: forma en cuatro secciones; algoritmo por pares literal; valor fuera de rango = error de valor; sin sección de diff.
