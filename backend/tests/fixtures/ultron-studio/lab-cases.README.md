# `lab-cases.json`: el laboratorio de IA en casos (Hito 4)

**Procedencia.** Lo escribió el tester **a mano**, a partir de `docs/ultron-studio/ULTRON_STUDIO_H4_CONTRACT.md` (§A2, §A3, §A4, §A5 y §A10). Ningún `expected` sale de ejecutar el laboratorio: cuando se escribió, no existía. Hubo dos comprobaciones de apoyo, y ninguna es fuente de verdad:
- **Una segunda derivación independiente** de las cuentas y las etiquetas, hecha por el tester con aritmética racional exacta y sin coma flotante. Coincide en los 206 casos.
- **El realismo de la salida enlatada.** La de cada caso de `checks` es la que dan las guardas reales del motor (`e651dd9`) para ese `reply`, con los argumentos de §A4. Se comprobó el 19-10-2026. Lo que se ajustó fueron los textos de ejemplo, nunca un `expected`.

**Quién lo consume:** las pruebas Unit del núcleo puro:
- `StudioLabOutcome`;
- `StudioLabPersonalData`;
- `StudioLabCost`;
- las pruebas deterministas, con `StudioLabEngineChecks`;
- la clasificación de fallos de `StudioLabOpenAiClient`.

Tienen que reproducir cada `expected` **exactamente**. Si PHP discrepa, se corrige PHP. Este fichero solo cambia con una enmienda del contrato.

**Recuento:** 206 casos.

| Sección | Casos | Desglose |
|---|---|---|
| `outcome` | 41 | 26 `outcome` + 15 `proposal` |
| `personalData` | 51 | 33 se rechazan + 18 pasan |
| `cost` | 41 | 13 `price`, 8 `tokenCost`, 9 `inputTokens`, 4 `estimate`, 7 `settle` |
| `checks` | 38 | — |
| `providerErrors` | 35 | — |

## Forma

```jsonc
{ "version": 1, "catalogVersion": "2026-10-19.1", "promptVersion": "2026-10-19.1", "source": "…",
  "outcome": [
    { "name", "kind": "outcome", "criteria": [{"id", "kind"}], "checks": [Finding], "judge": Judge | null, "expected": {"outcome"} },
    { "name", "kind": "proposal", "run": {"status", "stopReason", "mode"}, "cases": [{"status", "outcome"}], "expected": {"aiRecommendation"} } ],
  "personalData": [
    { "name", "kind": "personalData", "text", "expected": {"rejected"} } ],
  "cost": [
    { "name", "kind": "price", "usd", "expected": {"microsPerMillion"} },
    { "name", "kind": "tokenCost", "tokens", "microsPerMillion", "expected": {"micros"} },
    { "name", "kind": "inputTokens", "role": "actor|judge", "bytes", "actorMaxOutputTokens"?, "expected": {"tokens"} },
    { "name", "kind": "estimate", "priceInMicrosPerMillion", "priceOutMicrosPerMillion", "actorMaxOutputTokens", "judgeMaxOutputTokens",
      "cases": [{"actorInputBytes", "judgeInputBytes"}], "expected": {"caseReservedMicros", "maxMicros", "expectedMicros"} },
    { "name", "kind": "settle", "priceInMicrosPerMillion", "priceOutMicrosPerMillion", "reservedMicros",
      "calls": [{"role", "usage"}], "expected": {"callCostMicros", "costMicros", "overrunMicros"} } ],
  "checks": [
    { "name", "kind": "checks", "reply", "persona": {"maxChars", "emojis"}, "rules": [{"code", "conditions"}],
      "facts": {"paymentState", "handoffRequested"}, "engine": Canned, "throws": [guarda], "expected": [Finding] } ],
  "providerErrors": [
    { "name", "kind": "providerErrors", "role": "actor|judge", "judgeExpects"?: {"criteria", "rules"}, "streakBefore",
      "response": {"status", "body"} | {"transport"}, "expected": {"class", "costMode", "runEffect"} } ] }
```

- **Secciones y tipos.**
  - Las cinco secciones son las de §A10.
  - `kind` dice qué función se prueba, así que la lista se puede aplanar sin perder nada. En `outcome` y en `cost` conviven varias funciones porque §A10 las agrupa.
  - `name` es único en todo el fichero y empieza por su tipo; en `cost`, por `cost · <tipo>`.
- **`Finding`** = `{id, source, level}`. Es la proyección de la forma de §A4: `title`, `detail` y `quote` son textos del servidor y no entran.
- **Claves en camelCase.** Las únicas en snake_case son las del proveedor, que van tal cual las devuelve OpenAI:
  - `usage`: `input_tokens`, `input_tokens_details.cached_tokens`, `output_tokens`, `output_tokens_details.reasoning_tokens` y `total_tokens`;
  - los `body` simulados.
- **No hay ningún `{}`.** PHP decodifica `{}` como `[]`. Los nulos van explícitos.
- **`catalogVersion` y `promptVersion`** dicen contra qué versiones se escribió. Ningún caso depende del catálogo de escenarios ni de los prompts. Por eso el arnés **no** los compara con las constantes, y subirlas no obliga a tocar el fichero.
- **Caracteres invisibles.**
  - Van como escapes JSON de cuatro cifras hexadecimales. Los de fuera del plano básico van como pares sustitutos: U+E0041 son dos escapes.
  - `json_decode` los convierte en el carácter real (comprobado: U+E0041 llega como `f3a08181`).
  - No hay ningún invisible literal en el fichero, y conviene que siga así: hay editores y herramientas que los borran sin avisar.
  - Las tildes, la ñ, ™, © y los emojis visibles van literales.

### `outcome`

- **`criteria`:** los del escenario, con su `kind`. El juez no trae el `kind`: solo `id`, `verdict` y sus textos.
- **`judge`:** la salida del juez ya validada por el servidor, con la forma de §A4:
  - `criteria[]{id, verdict, evidence, reason}`;
  - `rules[]{code, verdict, reason}`;
  - `risks[]` y `summary`.

  `null` significa que el juez falló: no hay opinión de la IA.
- **R-xx o S-NNN:** una regla es una cosa u otra por su prefijo, `R-` o `S-`.
- **`proposal`:**
  - `run` lleva `status`, `stopReason` y `mode`;
  - `cases` lleva el `status` de cada caso y su `outcome`, que solo existe en `done`.

### `personalData`

- `text` es lo que recibe `StudioLabPersonalData`, sin recortar ni normalizar.
- `expected.rejected`, y nada más. El mensaje del 422 es del servidor: §A2 lo fija y lo prueban las Feature. Aquí solo cuenta si el texto pasa o no.

### `cost`

- **Importes** en micro-USD, enteros.
- **Precios.** En `tokenCost`, `estimate` y `settle` se dan ya en micro-USD por millón, para que cada tipo pruebe una sola cosa. La conversión desde el texto del entorno es `price`.
- **`price.expected.microsPerMillion`** es `null` cuando el texto no es un precio (`^\d+(\.\d{1,6})?$`). Si la implementación lanza en vez de devolver null, el arnés lo traduce a null.
- **`inputTokens`:**
  - `bytes` es la suma de los bytes UTF-8 de instrucciones, entrada y esquema;
  - con `role: judge`, el arnés pasa además `actorMaxOutputTokens`, porque la respuesta del actor cuenta con su máximo, a 4 bytes por token.
- **`estimate`:**
  - `cases[]` da, por caso, los bytes de la entrada del actor y los del juez, estos **sin** la respuesta;
  - `caseReservedMicros` es el `reserved_micros` de cada caso, en orden;
  - `maxMicros` es su suma.
- **`settle`:**
  - `calls[]` son las llamadas del caso, con su `usage` tal cual;
  - `callCostMicros` es el `costMicros` de cada llamada, en orden.

### `checks`: la salida enlatada

- **`reply`:** el texto del actor. Solo lo leen las pruebas del Studio (`lab-*`). Las del motor salen de `engine`.
- **`persona`:** `maxChars` (rejilla 160–480, paso 20) y `emojis`.
- **`rules`:** las reglas del objetivo, `{code, conditions}`.
  - Las condiciones van en la forma canónica de G, con las frases ya canónicas.
  - El orden de la lista es el de código.
- **`facts`:** los hechos sintéticos del escenario. Con la salida enlatada no cambian nada. Van para que el arnés pueda llamar al adaptador con su firma real y, si quiere, comprobar que pasa a cada guarda los argumentos de §A4.
- **`engine`:** lo que devuelve cada guarda. Siempre lleva las siete claves.
- **`throws`:** las guardas que lanzan una excepción en vez de devolver. Su valor en `engine` es `null` y no se mira.

| Clave | Llamada de §A4 | Valor enlatado → retorno real que simula el arnés |
|---|---|---|
| `inspect` | `OutboundContentGuard::inspect($reply, 'ai', facts.handoffRequested)` | `null` → `{safe: true, code: null, signal: null, risk_flag: null}`. `"<code>"` → `{safe: false, code: "<code>", signal: …, risk_flag: …}` |
| `paymentFact` | `PaymentFactGuard::contradiction($reply, facts.paymentState)` | `null` → `null`. `"<reason>"` → `{code: "machine_reply_payment_fact", reason: "<reason>", detail: …}` |
| `membershipFact` | `MembershipFactGuard::contradiction($reply, [])` | `null` → `null`. `"<reason>"` → `{code: "machine_reply_membership_fact", reason: "<reason>", detail: …}` |
| `promise` | `PromiseAuthority::detectar($reply)` | `null` → `{kind: null, quote: null}`. `"<kind>"` → `{kind: "<kind>", quote: …}` |
| `courtesyConfirmation` | `OutboundContentGuard::courtesyConfirmationIn($reply)` | La frase o `null`, tal cual |
| `courtesyRegistration` | `OutboundContentGuard::courtesyRegistrationIn($reply)` | La frase o `null`, tal cual |
| `style` | `ComposerStyleGuard::inspect($reply, [], false, false, [])` | `{hard, soft}` → `{hard, soft, plans_mentioned: 0}` |

**Aviso para el arnés.** `PaymentFactGuard`, `MembershipFactGuard`, `PromiseAuthority` y `ComposerStyleGuard` son `final`, así que PHPUnit no puede hacer dobles de ellas. El adaptador necesita una costura para que la salida enlatada llegue a la traducción: por ejemplo, inyectar cierres, o separar «llamar a las guardas» de «traducir lo que devuelven». Cuál, lo decide el backend.

### `providerErrors`

- **`response`:** lo que ve el cliente.
  - `{status, body}` es una respuesta HTTP.
  - `{transport: "timeout"}` es superar el `timeout()` (cURL 28).
  - `{transport: "connection"}` es no llegar a conectar (DNS, rechazo, TLS).
  - Cómo se simula cada uno con `Http::fake` lo decide el backend.
- **`body`:** un objeto, cuando el proveedor respondió JSON (el arnés lo envía con `Content-Type: application/json`), o una cadena, cuando el cuerpo no es JSON, tal cual.
- **`role`:** dice qué esquema se valida en un 200: `{"reply": string}` en el actor y la salida de §A4 en el juez. En `judge`, `judgeExpects` da los `criteria[].id` del escenario y los `rules[].code` enviados.
- **`streakBefore`:** cuántas llamadas inmediatamente anteriores de la misma ejecución acabaron en `timeout`, `connection` o `server`. Solo vale 0 o 1.
- **`expected`:**
  - `class`: la clase de §A5, o `null` si la respuesta es buena;
  - `costMode`: `zero` (la llamada no cuesta), `usage` (cuesta su `usage`) o `reserve` (no se sabe si se cobró, así que se cobra la reserva);
  - `runEffect`: `continue`, `delay` (el siguiente paso sale 30 s más tarde) o `stop:<stopReason>`.

## Cómo se compara

- Igualdad de valores JSON.
- Las listas cuentan en orden: `checks.expected`, `caseReservedMicros` y `callCostMicros`.
- En `checks`, el arnés proyecta cada hallazgo de la implementación a `{id, source, level}` y compara la lista entera.
- Ningún caso depende del reloj, de la red ni de otro caso.

## Lo que el contrato no fijaba y fija este fichero

Cada punto cita los casos que lo fijan. **El lead tiene que congelarlos** con una enmienda del contrato o con una nota de lo que acepta.

### Pruebas deterministas (`checks`)

1. **Orden de los hallazgos:** el de la tabla de §A4.
   - Primero, los del Studio: `lab-vacia`, `lab-longitud`, `lab-emojis` y `lab-regla-*`, estas en el orden de `rules`.
   - Después, los del motor: `motor-<code>`, `motor-pago`, `motor-membresia`, `motor-promesa`, `motor-cortesia`, `motor-estilo-*` (los `hard` en su orden y luego los `soft` en el suyo) y `motor-no-disponible`, siempre el último.
   - Hace falta un orden fijo porque `results_hash` incluye los hallazgos.
   - Casos: «orden de los hallazgos…», «Ú e Í…» y «culpa y testimonio…».
2. **Un `id` sale como mucho una vez por caso.**
   - `lab-regla-<S-NNN>`: uno por regla, aunque casen varias de sus frases.
   - `motor-cortesia`: uno, aunque devuelvan frase la confirmación y el registro.
   - `motor-no-disponible`: uno, aunque lancen varias guardas. El detalle puede nombrarlas.
   - Así, `data-lab-hallazgo="<id>"` (§A9) señala un solo elemento.
   - Casos: «un hallazgo por regla…», «confirmación y registro a la vez…» y «dos guardas que lanzan…».
3. **Una guarda que lanza no impide las demás.** Su hallazgo no sale, y se añade `motor-no-disponible` (info). Casos: «una guarda que lanza (inspect)…», «dos guardas que lanzan…» y «orden de los hallazgos…».
4. **`lab-regla` aplica a la respuesta la canonicalización de G:**
   - los espacios de `JS_TRIM`, incluidos el salto de línea y el NBSP, colapsan a uno;
   - solo A–Z y ÁÉÍÓÚÜÑ pasan a minúsculas;
   - **no se pliegan tildes**: «ultimo dia» no casa con «ÚLTIMO DÍA»;
   - «contiene» es **subcadena**, sin límites de palabra: «regalo» casa en «regalos»;
   - solo cuentan las S-NNN: la frase de texto de R-02 no da `lab-regla-R-02`.

   Casos: los siete que empiezan por «lab-regla».
5. **`lab-longitud` cuenta puntos de código**, la regla de G, y salta con estrictamente más de `maxChars`. Caso «exactamente maxChars…»: 160 puntos de código, que son 161 unidades UTF-16 y 167 bytes.
6. **`lab-emojis` usa los rangos de `ComposerStyleGuard.php:29`.** Por eso ™ y © no son emoji: el propio código explica que son prosa. Caso «™ y ©…».
7. **El nivel de `motor-estilo-*` se decide por código**, no por la lista `hard`/`soft` de la guarda. `unapproved_superiority_claim` llega en `hard` y es aviso. Caso «superioridad sin frase aprobada…».

### Agregación (`outcome`)

8. **Solo cuentan los niveles `blocking` y `warning`.** Un `info` (estilo blando o `motor-no-disponible`) no mueve el resultado. Caso «los hallazgos info no cuentan…».
9. **Los `risks` del juez no cuentan, ni siquiera uno `high`.** La lista de §A4 es cerrada. Caso «los riesgos del juez no cuentan…».
10. **`not_applicable` no cuenta.** Caso «not_applicable en las reglas…».

### Coste (`cost`)

11. **Se redondea hacia arriba en cada término.** «El coste de T tokens» se aplica por separado a la entrada y a la salida de cada llamada, y luego se suma. No se redondea por llamada, ni por caso, ni por ejecución. Casos: «hacia arriba en cada término…» (`estimate`) y «hacia arriba por término…» (`settle`).
12. **La reserva y la estimación no pueden divergir.** El `reserved_micros` de un caso es la suma de sus dos llamadas a su máximo, y `maxMicros` es la suma de las reservas. Casos de `estimate`.
13. **La conversión del precio es exacta en decimal**, sin coma flotante. «8.2» y «2.007» delatan truncar o redondear hacia arriba un `float × 10⁶`.
14. **Un precio que no casa con la expresión de §A1 es `null`.** Entran «0,40», «.40», «1e-6», «-0.40», el vacío y siete decimales.
15. **`overrunMicros` es la diferencia si el real supera la reserva, y 0 si no.** Igual a la reserva no es exceso.
16. **`output_tokens` ya incluye el razonamiento, y la caché se cobra como entrada normal.** No se suma `reasoning_tokens` ni se descuenta `cached_tokens`.

### Fallos del proveedor (`providerErrors`)

17. **Una respuesta buena es `class: null`, con `costMode: usage`.** Un `reply` vacío también es una respuesta buena: lo marca `lab-vacia`, no el cliente.
18. **`status: incomplete` manda en un 200.** Aunque el texto sea JSON a medias, la clase es `incomplete` y no `invalid_output`.
19. **El mensaje se busca por tipo en `output[]`**, no en `output[0]`: los modelos de razonamiento ponen antes un item `reasoning`.
20. **Validación del juez:**
    - los `id` y los `code` tienen que ser **la misma lista, sin repetidos**: un criterio repetido es `invalid_output`;
    - `not_applicable` solo vale en reglas;
    - un texto por encima de su cota se recorta y no invalida la respuesta.
21. **429.**
    - Es `quota` si el error trae `insufficient_quota`, en `code` o en `type` (OpenAI pone las dos).
    - Cualquier otro 429 es `rate_limited`, también uno sin JSON.
22. **5xx:** cualquiera de 500 a 599, con JSON o sin él.
23. **La racha de §A1.**
    - La comparten `timeout`, `connection` y `server`: un error de esas clases después de otro de cualquiera de ellas para con `provider_errors`.
    - Un error que tiene su propia parada para con la suya aunque venga en racha: un 401 tras un 5xx es `provider_auth`.
    - Casos: «segundo timeout seguido…», «segundo 5xx seguido…», «sin conexión tras un 5xx…» y «401 tras un 5xx…».

### Datos personales (`personalData`)

24. **Los separadores pueden ir en racha** («300 - 123 - 4567»), y **el NBSP cuenta como espacio**. El umbral son 7 cifras, aunque haya varios separadores entre ellas.
25. **Consecuencias del texto literal de §A2.** Se fijan a propósito para que se vean:
    - un precio de 7 cifras («$1.200.000») se rechaza: el filtro no distingue un precio de un teléfono;
    - una fecha con guiones («15-10-2026») se rechaza, porque son 8 cifras separadas; con barras («15/10/2026») pasa, porque la barra no es separador;
    - un emoji compuesto con ZWJ (persona levantando pesas) se rechaza por el U+200D. Los emojis simples, con selector de variación U+FE0F o con modificador de tono, pasan: esos no son `Cf`.
26. **Lo que no es `Cf` ni U+2028/2029 pasa:** el salto de línea, el NBSP suelto, U+FE0F y los modificadores de tono.
27. **«57» sin el + pasa.**

## Lo que no cubre, y por qué

- **Textos del servidor:** `title`, `detail` y `quote` de los hallazgos, el mensaje del 422 y los mensajes de error.
- **Medir bytes.** `inputTokens` recibe los bytes ya contados, porque los prompts los escribe el backend. Que se cuenten bytes UTF-8 y no caracteres lo tiene que probar una Unit con los prompts reales y un texto con ñ y un emoji.
- **«La mitad de la salida» con un máximo impar.** Con 401 caben tres lecturas (200, 200,5 o 201 tokens) y el contrato no elige. Todos los casos usan máximos pares.
- **Cuánto es «la reserva» de un timeout:** la del caso entero o la parte de la llamada que falló. `costMode: reserve` dice el modo, no la cantidad.
- **Lo comprometido del día y la admisión** (`lab_run_budget_exceeded`, `estimate_changed`, `lab_daily_budget_exceeded`, «alcanza el tope»). Dependen de la base y del cerrojo: van en las Feature y en la concurrencia de §A10.
- **La racha más allá de 0 o 1:**
  - si cuentan también `rate_limited`, `refusal`, `incomplete` e `invalid_output`;
  - qué la reinicia;
  - si se cuenta por llamada o por caso.
- **El estado del caso cuando falla el juez.** §A4 dice que el caso llega como mucho a `review`, es decir, `done` sin juez. La tabla de §A5 pone `error` en todas sus filas. El fixture no afirma ninguna de las dos.
- **Ambigüedades evitadas a propósito:**
  - una respuesta que solo tiene NBSP u otros espacios Unicode: ¿está vacía?;
  - la longitud de una respuesta con espacios al principio o al final;
  - una bandera (indicadores regionales) con `emojis=false`. La línea 29 la excluye para **contar** emojis; «lleva algún emoji» es otra pregunta;
  - una S-NNN de texto con `when` no vacío (¿solo cuenta si se cumple?) y `require text contains`;
  - el juez con los criterios en otro orden, con claves de más o con más de 5 riesgos;
  - un 200 cuyo cuerpo no es JSON, que no tiene `usage` que cobrar, y los estados que la tabla no nombra: 408, 409, 413 y 3xx;
  - un código de `inspect` o de estilo que hoy no existe;
  - dígitos de ancho completo u otros sistemas de numeración, cifras separadas por comas, barras o saltos de línea, y «+ 57» con espacio.
- **Firmas cambiadas** (`motor-no-disponible` por reflexión): Unit.
- **Concurrencia, cerrojos y disparadores:** pila real (§A10).

## Guía de textos del servidor (no es paridad)

- **422 de datos personales**, literal de §A2: «Parece un dato personal (correo, teléfono o documento) o un carácter invisible: los escenarios son sintéticos y se envían a un proveedor externo».
- **Hallazgo con fuente `engine`**, de §A4: «El filtro real del motor marcaría este texto (<motivo>)». Nunca «el motor lo rechazaría».
