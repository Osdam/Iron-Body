# `publication-cases.json`: publicar, firmar, alcanzar y promover (Hito 5)

**Procedencia.** Lo escribió el tester **a mano**, desde `docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md`: §A1 (clasificación), §A4 (contrato `ultron-engine-config/1`), §A5.1–A5.4 (puertas) y §A6 (evidencia). Ningún `expected` sale de ejecutar el Studio ni el motor: cuando se escribió, no existían. Las huellas (sha256) y las firmas (HMAC-SHA256) no se pueden calcular a mano. Se calcularon con `hashlib`/`hmac` de Python sobre los bytes escritos a mano. Hubo cuatro comprobaciones de apoyo, y ninguna es fuente de verdad:

- **Una segunda derivación independiente** de los 231 casos, escrita aparte, que rehace cada `expected` desde la entrada con las reglas de abajo. Coincide en los 231.
- **openssl 3.6.4** rehace todas las huellas y firmas. Coinciden.
- **Las primitivas reales de PHP 8.3** las rehacen también: `hash`, `hash_hmac`, `hash_equals` y `base64_decode`, junto con el `StudioContentHasher::canonicalJson` y el `StudioCatalog` del repo (`2026-10-19.1`):
  - `canonicalJson`, alimentado con la entrada **tal cual** (sin ordenarla a mano), da los bytes de los 11 casos `effectiveJson` y de las 18 evidencias;
  - el catálogo transcrito en `catalog` es `StudioCatalog::bindings()`, con su orden, y `baseline()['configs']`.
- **29 implementaciones equivocadas simuladas**, y cada una tumba al menos un caso. Son errores plausibles:
  - la APP_KEY sin decodificar o la subclave al revés;
  - no traducir `fake`, o dar por bueno `entorno` sin lectura;
  - listas comparadas como conjuntos, o la prioridad de ofertas comparada con el motor;
  - el orden del texto de A1 en vez del del catálogo;
  - redondear o no convertir `issuedAt`, escapar la barra, no ordenar las claves;
  - comparar firmas sin distinguir mayúsculas, un lector laxo, o recanonicalizar antes de la huella;
  - la firma antes que la huella, o el canario sin configurar tratado como «todos»;
  - la ventana en UTC o con el final incluido;
  - otro orden en el paso 8, o el reconocimiento como lista y no como conjunto;
  - revertir que exige las llaves, o Legacy que se admite sin ninguna fila;
  - el `entry`/`seq` del sello sacados del texto, o un `fallback` sin ellos;
  - horas redondeadas, las horas dentro de la huella, o cero turnos que también suspenden `stamped`.

**Quién lo consume** (los nombres son los del plan, §3.1):

| Sección | Clase | Prueba |
|---|---|---|
| `classify` y `effectiveJson` | `StudioPublicationDeclaration` | `tests/Unit/UltronStudio/StudioPublicationDeclarationTest.php` |
| `signature` y `scope` | `UltronPublishedConfig` | `tests/Unit/Marketing/UltronPublishedConfigTest.php` |
| `gates` | Las puertas de `StudioPublications` | Unit si se separan en un evaluador puro; Feature si no |
| `evidence` | La parte pura de `StudioCanaryEvidence` | — |

Tienen que reproducir cada `expected` **exactamente**. Si PHP discrepa, se corrige PHP. Este fichero solo cambia con una enmienda del contrato.

**Recuento:** 231 casos.

| Sección | Casos | Desglose |
|---|---|---|
| `classify` | 34 | — |
| `effectiveJson` | 21 | 11 `effectiveJson` (bytes) + 10 `compose` (qué versión va en cada parte) |
| `signature` | 24 | 7 `sign` + 17 `verify` |
| `scope` | 63 | 24 de la matriz `canary`/`all` × 3 conversaciones × 4 formas de partes; 5 `off`; 3 sin fila; 2 fallos simulados; 29 de verificación (contrato, huella, firma, `malformed` y bytes no canónicos) |
| `gates` | 71 | 45 `publish` + 13 `promote` + 9 `rollback` + 4 `legacy` |
| `evidence` | 18 | — |

**Dónde vive.** En `tests/fixtures/ultron-studio/`, junto a los de F, G y H4. El plan (§3.1 y §7) decía `tests/Fixtures/UltronStudio/`, pero eso no es la convención de la suite. Además, en el disco de macOS, que no distingue mayúsculas, esa ruta caería en `tests/fixtures/UltronStudio/`, y en Linux sería un tercer directorio.

## Forma

```jsonc
{ "version": 1, "catalogVersion": "2026-10-19.1", "contract": "ultron-engine-config/1", "source": "…",
  "catalog": { "parameters": [ {"param", "origin", "valueSource", "declaredValue"?, "engineOption"?} ×21 ], "baseline": {capa: {clave: valor}} },
  "classify": [
    { "name", "kind": "classify", "configs": {capa: {clave: valor}}, "rules": [{"code", "source", "enforcement", "status"}],
      "engine": {"<id>": {"available": true, "value": …} | {"available": false}},
      "expected": { "mustMatch": [{"param", "versionValue", "engineValue", "ok"}] ×4, "matches": ["capa.clave"], "noEffect": ["capa.clave" | "rule:S-NNN"],
                    "mismatches": [{"param", "versionValue", "engineValue"}], "engineReadingUnavailable": ["capa.clave"] } } ],
  "effectiveJson": [
    { "name", "kind": "effectiveJson", "input": {"entry", "seq", "issuedAt", "canary": Part | null, "rest": Part | null},
      "expected": {"effectiveJson": "<bytes>", "effectiveHash": "<sha256 hex>"} },
    { "name", "kind": "compose", "input": {"action", "version", "versionStatus", "head": {"canary", "rest"} | null, "restores"},
      "expected": {"canary": "<uuid>" | null, "rest": "<uuid>" | null} } ],
  "signature": [
    { "name", "kind": "sign", "appKey", "effectiveJson", "expected": {"subkeyHex", "signature"} },
    { "name", "kind": "verify", "appKey", "effectiveJson", "signature", "expected": {"valid"} } ],
  "scope": [
    { "name", "kind": "scope", "appKey", "env", "canaryConversationId", "conversationId",
      "head": {"uuid", "seq", "engineContract", "effectiveJson", "effectiveHash", "signature"} | null, "failure": null | "table_missing" | "read_failed",
      "expected": {"stamp": Stamp | null, "queries": 0 | 1 | null} } ],
  "gates": [ { "name", "kind": "publish" | "promote" | "rollback" | "legacy", "facts": {…}, "expected": {"status", "code", "payload"?} } ],
  "evidence": [
    { "name", "kind": "evidence", "now", "thresholds": {"minHours", "minTurns"},
      "measures": {"since", "turns", "stamped", "byState", "brakes": {"count", "engaged"}, "acta": {"exitCode", "total", "counts"}, "recoveries", "drift", "signatureOk"},
      "expected": {"hours", "passes", "failing", "evidenceJson", "evidenceHash"} } ] }
```

- **Secciones y tipos.**
  - Las seis secciones son las que pidió el lead para A12 («Núcleo»).
  - `kind` dice qué función se prueba, así que la lista se puede aplanar sin perder nada.
  - `name` es único en todo el fichero y empieza por su sección; en las de varios tipos, por `<sección> · <tipo>`.
- **`Part`** es la parte de A4.2: `{approvedBy, catalogVersion, contentHash, declared: {los 4 must_match}, publishedBy, version}`.
- **`Stamp`** es el sello de A4.5: `{v, env, state, entry, seq, part, version, contentHash, reason}`.
- **Claves en camelCase.** Hay tres excepciones, que van tal cual:
  - `required_permission`, la clave que ya devuelve el 403 `forbidden` (`UltronStudioException::forbidden`);
  - los siete contadores del acta, que son las claves que imprime `ultron:canary-report --json` (`UltronCanaryReport.php:421-428`);
  - las claves de `byState`, que son los estados y motivos del sello de A4.4.
- **No hay ningún `{}`.** PHP decodifica `{}` como `[]`. Los nulos van explícitos.
- **Identidades sintéticas.**
  - Versiones A `1111…`, B `2222…`, C `3333…` y Z `9999…`, con huellas de contenido `a…a`, `b…b` y `c…c`.
  - Entradas `eeeeeeee-0000-4000-8000-0000000000NN`, en minúsculas como las devuelve el tipo `uuid` de PostgreSQL.
  - Conversación canaria **501** y otra **777**. Ninguna es la del canario de producción.
- **`catalog`** es la transcripción del catálogo real y forma parte del fixture.
  - El arnés tiene que comprobar dos cosas: que `catalogVersion === StudioCatalog::VERSION`, y que `catalog` es igual a `StudioCatalog::bindings()` (proyectado a esas claves, en orden de capa y parámetro) y a `baseline()['configs']`. Si alguna falla, la prueba falla.
  - Es lo contrario que en H4: aquí la clasificación depende del catálogo. Un catálogo nuevo obliga a enmendar el fixture, y no a probar en silencio contra otro.

### `classify`

- **`configs`** es el `configs` de la versión, siempre completo (el normalizador de F rellena lo que falta con la línea base).
- **`rules`** son las reglas copiadas en la versión. Solo cuenta el `code`; los otros tres campos van coherentes con él (`R-xx`: `legacy_baseline · code · active`; `S-NNN`: `studio · none · draft`).
- **`engine`** son los `params` de `StudioEngineReadings::read()`, con sus cinco ids. Una lectura caída es `{"available": false}`, sin `value`, como la da la clase.
- **`expected`**: ver las decisiones C-1 a C-10.

### `effectiveJson`

- **`effectiveJson`.**
  - `input` lleva lo que construye el texto: `contract` es siempre `ultron-engine-config/1` y no es entrada.
  - `issuedAt` llega como texto ISO-8601 con desfase, que el arnés convierte en el instante que pase a la función.
  - `expected.effectiveJson` son los bytes exactos, como cadena JSON. `effectiveHash` es su sha256 en hexadecimal en minúsculas.
- **`compose`.** Qué versión va en cada parte de la entrada nueva según la acción (A4.2, A5.2–A5.4).
  - `head` es la cabeza vigente, por el uuid de la versión de cada parte, o `null` si no hay ninguna fila.
  - `restores` es la versión de la parte resto que vuelve al revertir una `PUBLISHED`; la busca el servicio.
  - Solo se compara el `version` de cada parte (ver E-4).

### `signature`

- **`appKey`** es el valor de `config('app.key')` tal cual (con su `base64:`). El arnés lo pone con `config(['app.key' => …])`.
- **`sign`.**
  - `expected.signature` es `UltronPublishedConfig::signatureFor($effectiveJson)`.
  - `subkeyHex` es **informativo**: la subclave en hexadecimal, para ver en qué paso diverge una implementación. No hace falta que la clase la exponga.
- **`verify`.** `valid` es `hash_equals(UltronPublishedConfig::signatureFor($effectiveJson), $signature)`, la comprobación del paso 4 de A4.3.

### `scope`

- **`env`.**
  - Es `config('marketing.ultron.studio_config')` tal cual llega al lector.
  - Incluye `"on"` y `null`, que no son valores válidos: el lector los trata como `off`, aunque `config/marketing.php` ya normalice.
- **`canaryConversationId`.** `config('marketing.ultron.canary_conversation_id')`: un entero o `null`, que significa sin canario configurado.
- **`head`.** La fila con `max(seq)` de `ultron_studio_publications`, con el nombre de cada columna en camelCase.
  - Las demás columnas `NOT NULL` (`client_request_id`, `action`, `catalog_version`, `lab_gate`, `actor_*`, `created_at`) las rellena el arnés como quiera: el lector no las mira.
  - `null` es que la tabla está vacía.
- **Cómo se insertan las filas:** una por caso y en la transacción del caso (`DatabaseTransactions` o `RefreshDatabase`). Hay dos motivos:
  - los disparadores de solo inserción impiden limpiarlas con `DELETE`;
  - los `uuid` y `seq` se repiten entre casos (son UNIQUE).
- **`engineContract = "ultron-engine-config/3"`** (2 casos, `scope/34` y `scope/35`) no entra en PostgreSQL: el CHECK de dominio (`uspub_engine_contract_chk`) solo admite `/1` y, desde la Enmienda M-1 del H5 (`2026_11_16_100001_…`), `/2`. Esos dos casos van en SQLite o por un resolutor puro. Hasta la Enmienda M-1, el contrato desconocido de estos casos era `/2`; pasó a `/3` porque `/2` ya es un contrato válido (huellas y firmas intactas: van sobre `effectiveJson`, no sobre la columna).
- **`failure`.** El arnés simula el fallo como quiera:
  - `table_missing`: SQLSTATE 42P01 o «no such table»;
  - `read_failed`: cualquier otra excepción de la consulta.
- **`expected.stamp`** es lo que devuelve `forConversation($conversationId)`: `null` (llave en `off`) o el sello.
- **`expected.queries`** son las sentencias `SELECT` sobre `ultron_studio_publications` que llegan a ejecutarse, vistas con `DB::listen`. Vale `0` con la llave en `off` y `1` en el resto. Con `failure` es `null`, porque no se afirma: una consulta que falla no llega a `QueryExecuted`.
- **Lo que el commit no deja ver.** Con el canario configurado, el commit lanza 403 para otra conversación **antes** de leer (`UltronCommitService.php:290-302`). La fila «otra conversación» con canario configurado fija el algoritmo del lector por sí mismo, no algo que el commit alcance hoy.

### `gates`

- **`facts`** describe el estado que mira cada puerta, ya calculado. No es una petición HTTP.
  - `classification` es la salida de `classify`.
  - `labGate.latest` es el `latest` del 409 de A5.1 (paso 7).
  - `revalidation` es `ok`, `blocked` o `changed`.
  - `clientRequest` es `new`, `replay` (misma persona y mismo cuerpo), `reusedOtherBody` o `reusedOtherActor`.
  - `previewHash` y `evidenceHash` son `{sent, current}`.
  - `head`, en Legacy, es `declaring`, `legacy` o `none` (ninguna fila).
  - `now` va siempre en UTC.
- **`expected`.**
  - `{status, code}` de la **primera** puerta que rechaza.
  - `{status: null, code: null}` es que ninguna rechaza y sigue la transacción (paso 12).
  - `{status: 200, code: "replayed"}` es el reintento idéntico.
  - `payload` solo aparece cuando el contrato fija su contenido. Si falta, el arnés no lo mira (ver P-3).
- **El reloj.** Se congela en `now` **en UTC** (`CarbonImmutable::parse($now)`, sin zona de Bogotá), según la memoria «Relojes de prueba en UTC». La puerta pasa a Bogotá por su cuenta.

### `evidence`

- **`measures`** son los recuentos ya hechos de la tabla de A6 (sin ids ni textos), más `since`, la marca de inicio.
- **`now`** es el instante de la lectura. **`thresholds`** son los umbrales en vigor.
- **`expected.hours`** es el valor de horas que enseña el DTO.
- **`expected.failing`**, `passes`, los bytes del objeto que se firma (`evidenceJson`) y su huella (`evidenceHash`): ver V-1 a V-10.

## Cómo se compara

- Igualdad de valores JSON. Las listas cuentan en orden, salvo `acknowledgedNoEffect`, que es un conjunto (P-4).
- `classify` y `gates`. El orden de las claves es el del fixture. Con `assertSame`, la implementación lo devuelve en ese orden o el arnés lo normaliza. Con `assertEquals` sobre arrays asociativos, el orden de las claves no cuenta.
- El sello. Mejor construirlo en el orden de A4.5. `metadata` es JSON, así que el arnés puede compararlo sin orden.
- `effectiveJson`, `evidenceJson` y las firmas. Igualdad **de bytes** (`assertSame` de cadenas).
- Ningún caso depende del reloj real, de la red ni de otro caso.

## Lo que el contrato no fijaba y fija este fichero

Cada punto cita los casos que lo fijan. **El lead tiene que congelarlos** con una enmienda del contrato o con una nota de lo que acepta. Los que piden una decisión expresa van además en «Preguntas para el lead».

### Clasificación (`classify`)

**C-1. Forma de la salida.**
- Cinco listas: `mustMatch`, `matches`, `noEffect`, `mismatches` y `engineReadingUnavailable`.
- Los parámetros se nombran `capa.clave`, que son los ids de `StudioEngineReadings` y de `declared`. Las reglas, `rule:S-NNN`, como `noEffect` en `PublicationPreviewDto` (A7).
- `mismatches` es la lista del 409 de A1 (`{param, versionValue, engineValue}`).

**C-2. El orden es el del catálogo:** el de las capas (`layerIds()`) y, dentro de cada una, el de sus parámetros.
- `mustMatch` lleva **siempre** las 4 entradas en ese orden: `intent.driver`, `rules.paymentLinksEnabled`, `governance.enabled` y `governance.canaryOnly`.
- No vale el orden en que A1 las enumera (`enabled`, `canaryOnly`, `paymentLinksEnabled`, `driver`), ni el de `declared`, que va ordenado por bytes.
- Casos: «dos mismatches (governance.enabled e intent.driver)…» y «los cuatro must_match distintos…».

**C-3. `noEffect`.**
- Primero los parámetros, en el orden del catálogo y **sin agruparlos por clase**: un `fixed` de `knowledge` va antes que un `proposal` de `persona`.
- Después las `S-NNN`, por código en orden de bytes (el de `StudioContentHasher::versionHash`), sea cual sea el orden de la entrada.
- Casos: «parámetros de varias clases y reglas a la vez…» y «S-NNN desordenadas…».

**C-4. Un `must_match` nunca va a `matches` ni a `noEffect`**, aunque su valor difiera de la línea base. Casos: «driver openai explícito frente a openai efectivo…» y «governance.enabled false con ULTRON apagado…».

**C-5. El driver.**
- `entorno` coincide con cualquier lectura **disponible**.
- El clasificador también traduce `fake` → `rules`. `StudioEngineReadings` ya lo hace, y repetirlo no cambia nada.
- `engineValue` sale traducido.
- Casos: los tres «… lectura cruda fake …».

**C-6. Una lectura no disponible nunca coincide, tampoco con `entorno`.**
- Sale `engineValue: null` y `ok: false`.
- Va a `engineReadingUnavailable`, en el orden del catálogo, y **nunca** a `mismatches`.
- Solo cuentan las 4 lecturas `must_match`: la de `strategy.offerPriority` no cambia nada.
- La otra lectura posible («`entorno` coincide siempre, incluso sin lectura») está descartada a propósito (falla cerrado), y la caza su caso.
- Casos: «lectura del driver no disponible con la versión en entorno…», «lectura de offerPriority no disponible…», «una lectura no disponible y otra distinta…» y «las cuatro lecturas no disponibles…».

**C-7. «Difiere de la línea base» es desigualdad estricta del valor JSON**, en tipo y en valor.
- En una lista, el orden cuenta: unas fuentes reordenadas son otro valor, igual que para la huella de contenido.
- Caso: «las fuentes en otro orden…».

**C-8. `engine_data` se compara con la línea base, nunca con la lectura del motor.** Casos: «offerPriority distinta de la línea base, aunque sea justo la que leen los planes…» y «offerPriority igual a la línea base aunque los planes leídos sean otros…».

**C-9. Las reglas.**
- `S-NNN` o `R-xx` se distingue por el prefijo del código.
- Cuenta toda `S-NNN` presente en la versión, sea cual sea su `status`. Por construcción solo se copian las no archivadas (`StudioRules::snapshotForVersion`).
- Una `R-xx` no tiene nunca clase.
- Caso: «dos S-NNN…».

**C-10. `matches`** son los `fixed` con `declaredValue` y ese mismo valor. Con otro valor pasan a `noEffect` y salen de `matches`. Casos: «fixed con valor y otro valor…» y «el resto de fixed con valor cambiados…».

### El texto firmado (`effectiveJson`)

**E-1. Los bytes son `StudioContentHasher::canonicalJson` de `{canary, contract, entry, issuedAt, rest, seq}`.**
- Claves ordenadas por bytes en los tres niveles (raíz, parte y `declared`).
- Sin espacios.
- `JSON_UNESCAPED_SLASHES`: «ultron-engine-config/1» con su barra tal cual. Un `json_encode` sin esa opción escribe `\/`.
- Se comprobó con el `canonicalJson` real, alimentado con la entrada sin ordenar.
- Casos: todos, y en particular «claves de la entrada en cualquier orden…» y «Legacy…».

**E-2. `issuedAt`** se escribe en UTC como `YYYY-MM-DDTHH:MM:SSZ`.
- Sin fracciones: se **truncan**, no se redondean.
- Sin `+00:00`.
- Cualquier desfase de entrada se convierte. De noche en Bogotá, el día UTC ya es el siguiente.
- Casos: los tres «issuedAt …».

**E-3. `seq`** es un entero JSON, sin comillas ni relleno. Caso «seq de dos cifras…».

**E-4. `compose`** aplica A4.2 y A5.2–A5.4 tal cual:

| Acción | Entrada nueva |
|---|---|
| Publicar B | `{canary: B, rest: <resto de la cabeza> o null}` |
| Promover B | `{B, B}` |
| Revertir la `CANARY` | `{rest, rest}` de la cabeza |
| Revertir la `PUBLISHED`, **sin otra versión en `CANARY`** | `{R, R}` con la `R` que se restaura, o `{null, null}` |
| Legacy | `{null, null}` |

- Solo se compara qué **versión** va en cada parte. Las identidades que acompañan a la parte quedan fuera, por la pregunta Q-3.

**E-5. La forma `{canary: null, rest: X}` no la escribe hoy ninguna transición**, pero es un texto válido y el lector la resuelve (A4.3, paso 5). Casos: «solo resto…» (`effectiveJson`) y los «{canary: null, rest: A}» de `scope`.

### La firma (`signature`)

**S-1. La APP_KEY.**
- Con el prefijo `base64:`, se usa `base64_decode` del resto: la decisión del lead, como `EncryptionServiceProvider::parseKey` de Laravel y `App\Support\Workforce\Secrets::key()`.
- Sin prefijo, se usan los bytes UTF-8 de la cadena tal cual.
- `UltronDecideToken::sign` usa la cadena **sin decodificar**: copiar ese patrón da una firma inválida. La caza el caso «… «base64:…» sin decodificar…».
- La clave de prueba son 32 bytes a cero: `base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=`.

**S-2. La subclave** es `k = HMAC-SHA256(clave = APP_KEY, mensaje = "ultron-studio-publication")`, en sus **32 bytes crudos**.
- En PHP: `hash_hmac('sha256', 'ultron-studio-publication', $appKey, true)`.
- Es la lectura de la fórmula de A4.1 en el orden de argumentos de `hash_hmac`. Es la derivación habitual (el secreto es la clave del PRF) y la de todos los `hash_hmac` del código, que pasan `app.key` como clave.
- Si `HMAC-SHA256("ultron-studio-publication", APP_KEY)` se lee en el mismo orden (clave, mensaje) que la fórmula de la firma, sale lo contrario: la etiqueta como clave. Esa lectura la caza «subclave con los argumentos al revés…» (Q-1).
- También son inválidas la subclave en hexadecimal y la APP_KEY directa sin subclave.

**S-3. El mensaje** es `"ultron-engine-config/1"`, un salto de línea LF (0x0A) y los bytes de `effective_json`, nada más.
- Sin CR.
- Sin nada al final: un salto de línea de más ya es otro texto.
- Un texto vacío firma solo la línea y su salto.
- Se firman los **bytes UTF-8**: el caso con ñ y un carácter de 4 bytes lo fija.

**S-4. La firma** son 64 caracteres hexadecimales en minúsculas. Se comprueba con `hash_equals` sobre la cadena exacta: en mayúsculas, recortada o vacía, es inválida.

### El lector (`scope`)

**R-1. La llave del motor.** Cualquier valor que no sea `canary` ni `all`, incluido `null`, el lector lo trata como `off`: devuelve `null` y no hace ninguna consulta. Casos: «llave con un valor que no es off, canary ni all («on»)…» y «llave sin valor (null)…».

**R-2. Qué lleva el sello en cada estado.**

| `state` | `entry`, `seq` | `part` | `version`, `contentHash` | `reason` |
|---|---|---|---|---|
| `applied` | los de la fila | `canary` o `rest` | los de la parte aplicada | `null` |
| `legacy` con fila | los de la fila | `null` | `null` | `null` |
| `legacy` sin fila | `null` | `null` | `null` | `null` |
| `out_of_scope` | los de la fila | `null` | `null` | `null` |
| `fallback` con fila | los de la fila | `null` | `null` | el motivo |
| `fallback/table_missing` y `fallback/read_failed` | `null` | `null` | `null` | el motivo |

**R-3. `entry` y `seq` del sello son las columnas `uuid` y `seq` de la fila, no las del texto**, siempre que se leyó una fila, también en `fallback`. Así un `malformed` dice qué fila está mal. Casos: «seq del texto (1) distinto del de la fila (2)…» y «entry del texto distinto del uuid de la fila…».

**R-4. Consecuencias del orden literal de A4.3**, que se fijan a propósito:
- sin fila → `legacy`, aunque la conversación quede fuera de alcance («canary · otra conversación · sin ninguna fila…»);
- con la cabeza corrupta → `fallback`, aunque la conversación quede fuera de alcance («otra conversación con env canary y la huella mala…»);
- con `env canary`, otra conversación es `out_of_scope` aunque las dos partes sean `null` («canary · otra conversación · {canary: null, rest: null}…»).

**R-5. Qué es «decodificación estricta».**
- El texto tiene que ser JSON y un objeto.
- **Claves exactas en los tres niveles**:
  - en la raíz, `canary`, `contract`, `entry`, `issuedAt`, `rest` y `seq`;
  - en la parte, `approvedBy`, `catalogVersion`, `contentHash`, `declared`, `publishedBy` y `version`;
  - en `declared`, las 4 de `must_match`.
- **Tipos:**

| Campo | Tipo |
|---|---|
| `canary`, `rest` | objeto o `null`, nunca `false` ni una lista |
| `contract` | texto **igual a la columna `engine_contract`** |
| `entry` | texto igual a la columna `uuid` |
| `issuedAt` | texto (su formato no se valida) |
| `seq` | entero igual a la columna (`"1"`, `1.0` y `true` no valen) |
| `approvedBy`, `publishedBy` | enteros |
| `catalogVersion`, `contentHash`, `version` | texto |
| `governance.canaryOnly`, `governance.enabled`, `rules.paymentLinksEnabled` | booleanos |
| `intent.driver` | texto |

- Los **valores** (el dominio del driver, el formato del uuid o de la huella) no se validan.
- Casos: los 16 «… → fallback/malformed».

**R-6. El lector no recanonicaliza.** Si unos bytes casan con su huella y su firma y se decodifican en estricto, se aplican, aunque no estén en forma canónica: con sangría, o con las claves en otro orden. Casos: «el mismo contenido con sangría…» y «… con las claves en otro orden…».

**R-7. Las comparaciones de la huella y de la firma son exactas.** Una huella en mayúsculas es `hash_mismatch`. Caso: «huella correcta pero en mayúsculas…».

### Las puertas (`gates`)

**P-1. Dentro de un paso manda el orden del texto.** El lead lo confirmó para el paso 2:

| Paso de A5.1 | Orden |
|---|---|
| 1 | persona → `ultron.view` → `ultron.publish` (como `StudioAccess::require`) |
| 2 | `publishing_disabled` → `engine_not_reading_versions` |
| 4 | `version_not_found` → `four_eyes_required` |
| 5 | estado → integridad |
| 6 | `revalidation_blocked` → `revalidation_changed` |
| 8 | `engine_value_mismatch` → `engine_reading_unavailable` |
| 9 | reconocimiento → `preview_stale` |
| 10 | `engine_has_no_canary` → `engine_braked` → `canary_in_progress` |

Caso del paso 8: «mismatch y lectura no disponible a la vez…».

**P-2. El reintento idéntico (paso 3)** da `200 replayed` antes que la versión, su estado y la ventana, pero **después** de las llaves. Casos: los dos «reintento idéntico…».

**P-3. Los payloads que se afirman.**

| Código | Payload |
|---|---|
| `forbidden` | `{required_permission}` |
| `invalid_transition` | `{currentStatus, allowedFrom}` (los dos como hoy); en publicar `allowedFrom: [APPROVED]` y en promover `[CANARY]` |
| `engine_value_mismatch` | `{mismatches}` |
| `engine_reading_unavailable` | `{engineReadingUnavailable}` |
| `no_effect_not_acknowledged` | `{noEffect}`, el conjunto esperado en el orden de C-3 |
| `lab_verdict_required` | `{latest}` |

- Los demás no se afirman: el de `preview_stale` es la vista previa nueva, el de `canary_evidence_insufficient` la evidencia, y los `findings` de la revalidación.

**P-4. El reconocimiento es un conjunto**, como `acknowledgedWarnings` al aprobar (`StudioVersions::asSet`): el orden no cuenta. Los repetidos son un 422 (`distinct`), no un caso de aquí. Caso: «reconocimiento con los mismos elementos en otro orden…».

**P-5. `previewHash` y `evidenceHash` se comparan exactos.**

**P-6. La ventana.**
- Se mira en `America/Bogota` (UTC−5, sin horario de verano) y es **[inicio, fin)**: el inicio entra y el fin no.
- Se lee por minutos, `HH:MM-HH:MM`.
- Vacía es sin ventana.
- Que un valor inválido caiga en el defecto es de la configuración (A13), no de este fichero.
- Casos: 06:59, 06:59:59, 07:00, 18:59, 18:59:59, 19:00 y 03:00 en Bogotá; la ventana vacía; y `08:30-17:15` en sus bordes.

**P-7. Promover.**
- `scope_not_allowed` va después del paso 4 y antes del estado.
- Con el motor en `off` sale primero `engine_not_reading_versions`.
- Con el freno echado **ahora**, la evidencia ya falla en `brakes`: sale `canary_evidence_insufficient`, y `engine_braked` queda tapado mientras la evidencia incluya el freno.
- Casos: «motor en off…», «el autor promueve con el motor en canary…» y «freno echado ahora…».

**P-8. Revertir.**
- Va persona → `ultron.view` → `ultron.rollback` y no mira ninguna llave, los cuatro ojos ni la ventana.
- Una versión que no existe es `404 version_not_found`.
- Todo lo que no sea la `CANARY` o la `PUBLISHED` vigente es `not_active_publication`, también `APPROVED` y `RETIRED`.
- `restore_integrity_mismatch` solo sale en una `PUBLISHED` cuya anterior está alterada. Sin anterior, pasa y queda Legacy.

**P-9. Volver a Legacy sin ninguna publicación es `already_legacy`**: sin filas, el motor ya lee Legacy (A4.3, paso 3). Caso: «sin ninguna publicación…».

**P-10. Los 403 del fixture son los del dominio** (`StudioAccess`). La puerta HTTP (`AuthorizationMap`) puede responder antes con su propio cuerpo. Las pruebas Feature miran el 403; el código, donde la ruta llegue al dominio.

### La evidencia (`evidence`)

**V-1. `hours = floor(segundos transcurridos / 3600)`**, y la puerta es `hours ≥ minHours`, que equivale a «han pasado `minHours` horas enteras». Es el único entero coherente con la puerta: 23 h 59 min 59 s no pasa. Caso: «un segundo antes de las 24 h…».

**V-2. `stamped = turns`.** Con 0 turnos solo falla `turns`. Caso: «cero turnos…».

**V-3. `brakes`** es `{count, engaged}`, y pasa con `count = 0` y `engaged = false`. Un freno ya suelto desde la entrada también falla. Caso: «un freno desde la entrada, ya suelto…».

**V-4. El acta.**
- `acta` es `{exitCode, total, counts}`, y `counts` lleva siempre los 7 contadores.
- Pasa con `total = 0` y `exitCode = 0`.
- Un acta que no llegó a dar JSON (la conversación no existe, o un `--since` ilegible) es `{exitCode: 1, total: null, counts: null}`, y falla.
- Caso: «acta que no llegó a dar JSON…».

**V-5. `byState`.**
- Tiene **siempre las 10 claves**: `applied`, los seis `fallback/<motivo>`, `legacy`, `out_of_scope` y `unstamped` (sin sello).
- Reparte los turnos, así que su suma es `turns`.
- `applied` cuenta cualquier versión. `stamped` es la parte de `applied` con **esta** versión.
- Así nunca está vacío, y nunca cae en la trampa `{}`/`[]` de PHP.
- Caso: «3 turnos applied, uno con otra versión…».

**V-6. `drift`** son entradas `{param, declared, engine}` (el `PublicationStateDto` de A7). Pasa vacía.

**V-7. `failing`** sigue el orden de la tabla de A6, sin las filas informativas (`byState` y `recoveries`): `hours`, `turns`, `stamped`, `brakes`, `acta`, `drift` y `signatureOk`. `passes` es `failing` vacío. Caso: «todo falla a la vez…».

**V-8. `evidenceHash` es `StudioContentHasher::hash` del objeto `{since, turns, stamped, byState, brakes, acta, recoveries, drift, signatureOk, thresholds}`.** Son los recuentos, los umbrales y la marca de inicio.
- **No** entran `now` ni `hours` (se derivan del reloj), ni `passes` ni `failing` (se derivan del resto).
- Por eso la huella no cambia con el paso del tiempo: la de 24 h, la de 23 h 59 min 59 s y la de 30 h es la misma.
- Sí cambia con cualquier recuento, incluido el informativo `recoveries`, y con los umbrales.
- Quien promueve tiene que haber visto todo lo que no es el reloj.
- Casos: «30 h con los mismos recuentos…», «recoveries 2…» y «… otro umbral de turnos…».

**V-9. `since`** es el `created_at` de la entrada que puso la versión en `CANARY`, en UTC y en el formato de E-2.

**V-10. `recoveries` y `byState` no deciden nunca**: son informativos (A6).

## Lo que no cubre, y por qué

- **`previewHash`.** A12 pide que sea estable, pero el contrato no dice qué entra en la huella de la vista previa. Las puertas solo comparan `sent` y `current` (P-5). Ver Q-4.
- **Cómo se calcula `drift`.**
  - La evidencia lo recibe ya hecho.
  - Queda abierto qué pasa con una lectura caída en la deriva (Q-5).
  - La comparación es la de `must_match` (C-5 y C-6) contra los `declared` de la parte aplicada: se puede probar con los casos de `classify`.
- **Revertir la `PUBLISHED` con otra versión en `CANARY`.** A5.3 no lo resuelve (Q-2) y el fixture no lo afirma.
- **Quién figura en `publishedBy` al promover.** `compose` no compara las identidades (Q-3).
- **`describe()` (A4.9).** Se puede añadir con una enmienda. Su forma está fijada, pero no los valores de `parseOk` con una cabeza que falla antes.
- **Lo que no es núcleo puro.** Va en las Feature, en PostgreSQL y en el E2E de A12:
  - la validación 422 (tamaños, `reason` de 5 a 500, `acknowledgedNoEffect` ≤ 64, `distinct`);
  - el registro de `clientRequestId`;
  - el libro de auditoría;
  - las transacciones y las carreras (`publication_moved`, el `invalid_transition` de una carrera);
  - los disparadores, CHECK e índices parciales;
  - el sello en todas las filas de un commit y que dos commits no lo compartan;
  - que el lector nunca lance en PG;
  - el registro en `ChannelLog`;
  - cómo cuenta la evidencia en la base (`source_type`, el `--since` del acta, `Artisan::call`);
  - cómo se calculan `latest` del laboratorio y la revalidación;
  - leer la ventana desde el entorno.
- **Ambigüedades evitadas a propósito**, para no fijar una decisión sin base:
  - claves repetidas en `effective_json`. `json_decode` se queda con la última, y detectarlas exige un analizador propio. Solo puede escribirlas quien tenga la APP_KEY;
  - el formato de `issuedAt` y los dominios de los valores de `declared`, en el lector;
  - ids de conversación como texto y un canario configurado como `0`. El commit compara con `(int)`, y el lector debería hacer lo mismo;
  - una ventana que cruza la medianoche (`22:00-06:00`) o con el inicio igual al fin;
  - un `now` anterior a `since`;
  - la parte `canary` de una entrada cuya versión ya no está en `CANARY`, un estado imposible si se cumple el invariante de A2.

## Preguntas para el lead (las decisiones que más pesan)

- **Q-1. El orden de los argumentos de la subclave (S-2).** El fixture fija `hash_hmac('sha256', 'ultron-studio-publication', $appKey, true)`. Si el lead prefiere la lectura literal en el orden (clave, mensaje), cambian `subkeyHex` y **todas** las firmas del fichero, y el caso «… argumentos al revés …» pasa a ser el válido.
- **Q-2. Revertir la `PUBLISHED` mientras otra versión está en `CANARY`.**
  - A5.3 escribe `{R, R}` o `{null, null}`: eso borra la parte `canary` mientras esa versión sigue en `CANARY` y rompe el invariante de A2 («la cabeza de la cadena coincide con ellas»).
  - Opciones: (a) conservar la parte canaria, `{canary: B, rest: R}`; (b) rechazar hasta revertir el canario; (c) revertir también el canario.
  - Recomendación del tester: **(a)**, porque deshacer no debería depender de otro estado.
- **Q-3. Al promover, ¿las partes conservan el `publishedBy` de la publicación o llevan el de quien promueve?**
  - A4.1 pide las identidades de quien publica dentro del texto firmado, pero `{canary: B, rest: B}` sugiere la misma parte.
  - Las dos lecturas dan bytes distintos.
- **Q-4. Qué entra en `previewHash`.** Propuesta:
  - `StudioContentHasher::hash({versionUuid, contentHash, catalogVersion, mustMatch, matches, noEffect, labGate: {mode, latest}})`;
  - sin la ventana ni la hora, que ya tienen su puerta.
  - Con esa decisión, se añaden casos en una enmienda.
- **Q-5. Una lectura caída en la deriva.** Propuesta: cuenta como deriva, con `engine: null` (falla cerrado y bloquea la promoción), igual que C-6 al publicar.
