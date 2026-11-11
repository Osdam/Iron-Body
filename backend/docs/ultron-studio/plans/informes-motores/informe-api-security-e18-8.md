Veredicto: ¿se puede desplegar? **SÍ.**

No hay ningún hallazgo bloqueante en el cambio.

- **Quien tenga el secreto de las herramientas** consigue lo que dice el autor y nada más: como mucho 20 respuestas por sesión, con información comercial pública, mientras la sesión esté abierta y vigente.
- **Ningún camino del laboratorio** escribe en `voice_*`, toca una llamada real ni sale a la red.
- **La negativa** es idéntica byte a byte en todos los casos.
- **En PostgreSQL** el cupo no deja pasar más de 20. Esto está razonado, no probado en PG.

Recomiendo tres cosas, ninguna bloqueante:

1. **A-1.** Endurecer la prueba del oyente en tiempo de ejecución. Es una línea y cierra un hueco de las guardas que he demostrado.
2. **A-2.** Registrar los 422 antes del primer ensayo real.
3. **P-1.** Abrir un ticket aparte para un problema que ya existía antes de este cambio. El `call_ref` de una llamada real se acepta aunque llegue con otra conversación, y en el laboratorio ese `call_ref` lo pone el navegador. Este cambio no lo introduce ni lo empeora.

Revisión: `api-security-specialist`, 2026-11-11. Árbol `feat/ultron-studio-h9` @ `e65e022c` más los cambios sin confirmar.

Ha sido de solo lectura: el árbol no ha cambiado. Las mutaciones y las medidas se hicieron sobre una copia clonada en el scratchpad (§4).

## Hallazgos

| id | severidad | qué | fichero:línea | estado | dueño |
|---|---|---|---|---|---|
| A-1 | Baja (defensa en profundidad) | Las guardas estáticas (§9, §9b y `onlyTheLabListener`) dejan pasar dos formas de registro. La primera es un oyente **comodín en forma de array** (`Event::listen(['*'], …)`) que contesta `counted`. La segunda es un oyente **directo** cuyo nombre sale de `constant(VoiceTools::class.'::LAB_TOOL_EVENT')`. La prueba en tiempo de ejecución usa `getRawListeners()`, que no ve los comodines. Hoy no hay ningún comodín registrado, y sin un commit malicioso no hay camino explotable. | `tests/Feature/Telephony/TelephonyIsolationTest.php:587-591` y `:636-663`; `tests/Feature/UltronStudio/UltronEngineIsolationTest.php:877`; `tests/Feature/Telephony/VoiceLabToolsTest.php:108-110` | **Confirmado** con dos mutaciones en una copia (§2 P4 y §4) | tester (las guardas las amplió el lead) |
| A-2 | Baja (observabilidad) | Un 422 `invalid_schema` de las herramientas no deja ninguna línea en nuestro registro. Si en el primer ensayo ElevenLabs manda `call_sid: ""` u otra forma, solo se verá en el detalle de la conversación en ElevenLabs. | `app/Services/Telephony/VoiceTools.php:88-91`; `app/Http/Controllers/Api/Voice/VoiceToolController.php:51-53` | **Confirmado** (leído; el autor lo dice también) | backend |
| P-1 | Baja. **Es anterior a este cambio y queda fuera del diff** | `find()` asocia un cuerpo a una llamada real **solo por su `call_ref`**, aunque esa llamada ya tenga otra `provider_conversation_id`. En el laboratorio, el `call_ref` lo fija el **navegador**: el servidor le entrega las `dynamicVariables` y el navegador se las pasa a ElevenLabs. Alguien con `ultron.voice_lab_run` que conozca el `call_ref` de una llamada viva puede cambiar esa variable. Así, el agente del laboratorio, que tiene las 4 herramientas, ejecutaría `no_volver_a_llamar` o `solicitar_humano` sobre la llamada real. Además, la post-llamada ataría la transcripción del laboratorio a esa llamada. | `app/Services/Telephony/VoiceTools.php:168-171`; `app/Services/Telephony/VoicePostCall.php:255-266`; `app/Services/UltronStudio/StudioVoiceLabSessions.php:1013`; `app/Services/Voice/ElevenLabs/ElevenLabsAgentMapper.php:198-230` | **Nuestro lado, confirmado** en la copia: `ok`, `tool_calls=1` y 1 exclusión creada. **Hipótesis:** que ElevenLabs acepte del cliente cualquier valor de variable. Es muy probable, porque E18-1 funciona con ese mismo mecanismo. | backend (arreglo puntual) / architect (opción estructural) |
| I-1 | Informativa | Oráculo de tiempos menor. Con el laboratorio apagado, la negativa tarda unos 0,8 ms menos; con una sesión del simulador, unos 0,2 ms más (una consulta más). Solo revela el estado de la bandera o el modo de un uuid que ya se conoce. No permite enumerar uuids, porque son v4. | `app/Services/UltronStudio/StudioVoiceLabSessions.php:574-577`, `:585-589` | **Medido** (SQLite, en proceso) | ninguno: aceptar |
| I-2 | Informativa | Quien tenga el secreto y el uuid de una sesión abierta puede agotar sus 20 consultas. Es una denegación de servicio sobre un ensayo. El autor ya lo documenta. | `app/Services/UltronStudio/StudioVoiceLabSessions.php:593-601` | **Confirmado** (leído y probado por el autor) | ninguno: aceptar |

No hay nada crítico, alto ni medio.

## 1. Alcance y modelo de amenazas

**Revisado**

- `POST /api/voice/tools/{tool}` (`routes/voice.php:49-51`). Es pública y se autentica con el Bearer de `VoiceToolAuth` (`VoiceToolController.php:32-41`).
- El camino nuevo: `VoiceTools::lab()` y `labAnswer()`.
- El oyente `StudioVoiceLabSessions::answerToolCall()`.
- `conversationVariables()` y lo que sale de ahí hacia el CRM.
- El registro del oyente en `AppServiceProvider` y las dos guardas ampliadas.
- Cómo interactúa todo con:
  - los otros escritores de `guard_codes`: el cierre, `recordProviderReport` y los veredictos;
  - la post-llamada (`VoicePostCall`), que ahora recibe un `call_ref` con forma de uuid.

**Fuera de alcance:** el CRM (frontend), la configuración del panel de ElevenLabs, la infraestructura, la E2E del H8 y producción. No he hecho ninguna petición real.

**Activos**

1. Las llamadas reales y lo que se hace en ellas: exclusiones (Ley 2300), transferencias y el cupo de herramientas.
2. La integridad de las sesiones del laboratorio: el cupo y los hallazgos.
3. La información comercial (planes y horario), que es pública.
4. Los identificadores: el `call_ref` de una llamada y el uuid de una sesión.

**Actores**

- **Quien tiene el secreto de las herramientas:** ElevenLabs, o quien lo robe.
- **La persona del Studio con `ultron.voice_lab_run`:** controla su navegador y, con él, las variables dinámicas de su conversación.
- **Quien tiene `ultron.view`:** ve el uuid de las sesiones (`StudioVoiceLabSessions.php:982`).
- **Un oyente de más en el código:** por un error o por un paquete.

**Fronteras de confianza**

- De ElevenLabs a Laravel: el cuerpo de la herramienta.
- Del navegador a ElevenLabs: las variables dinámicas.
- De la telefonía al Studio: el evento con nombre.

## 2. Respuestas a las 7 preguntas

### P1. ¿Qué puede hacer ahora quien tenga el secreto, que antes no podía?

**Coincide con lo que dice el autor, con tres precisiones.**

**Qué hace falta**

Además del secreto, la petición necesita tres cosas:

- un `conversation_id` que la telefonía no conozca;
- que no traiga `call_sid`;
- el uuid de una sesión que cumpla todo esto: abierta, sin veredicto, con la URL emitida, vigente, con un agente `lab` de ElevenLabs `deployed` y con el laboratorio encendido (`StudioVoiceLabSessions.php:574-589`).

**Qué consigue**

Con eso obtiene `consultar_planes` o `consultar_horarios`. Cada petición cuenta en el cupo de 20 de la sesión (`:593-601`).

El código que responde es el mismo que en una llamada (`VoiceTools.php:218-224`):

- `plans()` (`:244-256`): nombre, días de vigencia y precio de los planes `sellable()`, como mucho 20. El `id` se lee (`:246`), pero no se devuelve (`:251-255`).
- `hours()` (`:267-276`): la sede y el horario de la configuración.

No sale ningún dato de personas.

**La ventana**

Dura hasta `url_expires_at + max_seconds` o hasta que la sesión se cierre, lo que llegue antes. Son 900 s de URL (`app/Services/Voice/Dto/VoiceLabSession.php:45`) más como mucho 300 s de conversación: unos 20 minutos, como dice el autor.

*Precisión 1:* la sesión se cierra al primer informe del proveedor (`StudioVoiceLabSessions.php:540-542`) o cuando la termina quien prueba (`:454-463`). En la práctica, la ventana suele ser más corta.

**Qué escribe**

Solo `guard_codes.toolRequests` y `updated_at` de esa fila del Studio, y una línea `voice.tool` (`VoiceTools.php:221` y `:232`).

En `voice_*` no escribe nada: `refused()` solo registra un evento cuando hay `callId` (`:229-231`), y el laboratorio pasa `null`.

**Lo que no puede hacer**

- **No sale a la red ni gasta en el proveedor.** `plans()` y `hours()` solo leen de la base y de la configuración, y la prueba comprueba que `Http::recorded()` no crece (`VoiceLabToolsTest.php:139`).
- **No toca una llamada real por el camino del laboratorio:**
  - `lab()` solo se ejecuta si `find()` devolvió null (`VoiceTools.php:96`);
  - se niega si la conversación ya es conocida (`:203`);
  - se niega si la petición trae `call_sid` (`:200`).

*Precisión 2:* el cupo lo **comparte** con la conversación legítima de la sesión. Quien tenga el secreto puede agotarlo (I-2).

*Precisión 3:* la frase del autor «no toca una llamada real» es cierta para el **camino** del laboratorio. Pero la **conversación** del laboratorio sí puede llegar a una llamada real por el camino de las llamadas, si alguien altera su `call_ref`.

- Eso es P-1, y ya pasaba antes: con el literal `laboratorio` ocurría lo mismo si el navegador mandaba otro valor.
- Quien tiene el secreto ya podía hacerlo directamente, así que P-1 no le da nada nuevo.

**Coste nuevo**

Una petición con el secreto, una herramienta de lectura y un `call_ref` cualquiera hace ahora 2 o 3 consultas más: el `exists`, el freno y el `SELECT … FOR UPDATE` de la sesión.

Lo limita el techo global de 600 por minuto de la ruta (`VoiceRateLimits.php:78-83`). Es irrelevante.

### P2. Enumeración y oráculos

**Cuerpo y código HTTP: no se distinguen.**

Todos estos casos acaban en `default => null` (`VoiceTools.php:213`):

- sesión inexistente, terminada, fallida o caducada;
- sesión del simulador;
- laboratorio apagado;
- uuid ajeno del Studio;
- sin oyente.

De ahí van a `refused('call_not_found', null, $tool)` (`:96`). En todos sale el mismo 200, el mismo cuerpo y la misma línea de registro, sin `lab` (`:232`).

Lo prueban, byte a byte:

- `test_terminada_fallida_caducada_inexistente_o_de_otro_uuid_…` (`VoiceLabToolsTest.php:158`);
- las pruebas del simulador y del laboratorio apagado.

En el árbol: 12/12 en verde.

**Tiempos: medidos a grandes rasgos**

Medidos en la copia: SQLite en memoria, dentro del proceso, mediana de 15 peticiones.

| caso | mediana |
|---|---|
| inexistente | 1,75 ms |
| terminada | 1,79 ms |
| simulador | 1,98 ms |
| laboratorio apagado | 0,97 ms |
| abierta (`ok`) | 2,18 ms |

Qué se ve:

- **Inexistente y terminada** no se distinguen: hacen la misma consulta.
- **Laboratorio apagado:** se salta la transacción (`StudioVoiceLabSessions.php:575`).
- **Simulador:** hace una consulta más (`:585`).

En PostgreSQL y a través de internet, esas diferencias son de una ida y vuelta a la base, por debajo del ruido de red.

Con el techo de 600 por minuto harían falta muchas muestras, y solo para saber algo sin valor: si la bandera está encendida, o el modo de un uuid que ya se tiene. Los uuid v4 no se pueden enumerar. Es I-1, sin acción.

**«Freno echado» frente a «cupo agotado»**

Las dos respuestas solo salen para una sesión válida y abierta (`VoiceTools.php:210-211`). Revelan tres cosas:

1. **Que el uuid es una sesión abierta.** `ok` revela lo mismo, así que es inherente al diseño.
2. **El estado del freno, que es global.** Es un dato operativo, no personal, y quien tiene el secreto ya lo veía con cualquier llamada viva (`VoiceTools.php:105-107`).
3. **Que esa sesión agotó su cupo.**

Nada de eso es un dato personal ni un secreto. No importa.

*Fuera del diff:* con el secreto, el `call_ref` de una llamada **real** que no está activa sí se distingue: responde `call_not_active` en lugar de `call_not_found` (`VoiceTools.php:99-100`). Es anterior a este cambio y no tiene que ver con el laboratorio.

### P3. Confusión entre una llamada real y el laboratorio

**De una llamada real al laboratorio: no puede ocurrir.**

Lo comprobé leyendo el orden: primero se ejecuta `find()` (`VoiceTools.php:94`), y `lab()` solo se ejecuta cuando `find()` devuelve null (`:96`).

| cuerpo | qué pasa | dónde |
|---|---|---|
| `conversation_id` de una llamada + uuid de una sesión | 1. `find()` encuentra la llamada por la conversación. 2. El `call_ref` no casa, así que devuelve null. 3. `lab()` ve una conversación conocida y devuelve null. Resultado: `call_not_found`. Probado en `VoiceLabToolsTest.php:259-272`. | `:163`, `:174-175`, `:203` |
| `call_sid` de una llamada + uuid de una sesión | `find()` devuelve null. `lab()` también, porque trae `call_sid`. | `:166`, `:174-175`, `:200` |
| Una llamada real cuyo `call_ref` coincidiera con un uuid de sesión | Los dos son `Str::uuid()` independientes (`VoiceCall.php:114` y `StudioVoiceLabSession.php:118`). La probabilidad de coincidir es de unos 2^-122. Aun así, `find()` (`:171`) gana y se trata como llamada. | — |
| Cuerpo de una llamada real de Twilio | Siempre trae `call_sid`, así que `lab()` queda excluido. | `:200` |
| Tramos de transferencia | `find()` los excluye (`:161`), pero el `exists()` de `lab()` sí los cuenta (`:203`). `lab()` es más estricto, y está bien. | `:161`, `:203` |

**Del laboratorio a una llamada real: es P-1, y ya existía antes.**

Qué pasa en nuestro código:

- La conversación del laboratorio es una conversación web desconocida, sin `call_sid`.
- Si su `call_ref` es el de una llamada viva, `find()` (`VoiceTools.php:168-171`) la asocia a esa llamada, aunque la llamada ya tenga otra `provider_conversation_id`.

Lo reproduje en la copia con el cuerpo `{conversation_id: <desconocida>, call_ref: <call_ref de una saliente activa>}`:

- `consultar_planes` → `ok`, `tool_calls = 1` y 1 evento;
- `no_volver_a_llamar` → `ok`, `excluido: true` y 1 exclusión creada.

Por qué es alcanzable desde el laboratorio:

- **El servidor no sella el `call_ref`:** se lo entrega al navegador (`StudioVoiceLabSessions.php:1013`).
- **El agente del laboratorio tiene las mismas 4 herramientas,** contra la misma URL y con la misma referencia de secreto (`ElevenLabsAgentMapper.php:198-230`).
- **La post-llamada también busca por `call_ref`** (`VoicePostCall.php:255-266`). Ataría la transcripción del laboratorio a la llamada real: el evento `post_call_transcription`, el sello y la guarda.

Qué hace falta para explotarlo:

- Ser una persona interna con `ultron.voice_lab_run`.
- Conocer el `call_ref` de una llamada que esté en `dialing` o `active`. No sale en la API del CRM: busqué con grep en los presentadores y no aparece. Sí está en ElevenLabs: es el `user_id` de las salientes (`ElevenLabsCallStarter.php:111`) y va en sus variables.
- Acertar el momento.

*Hipótesis:* que ElevenLabs acepte del cliente cualquier valor de variable en una conversación con URL firmada. No lo he podido comprobar, pero E18-1 depende de ese mismo mecanismo.

**Este cambio no lo introduce:** con `laboratorio` pasaba igual si el navegador mandaba otro valor.

**Arreglo puntual (backend).** Mantiene el caso legítimo del comentario de `:169-170` («una saliente que el proveedor ya inició y cuyo job aún no guardó los ids»):

- En `find()`, el último recurso por `call_ref` solo debe valer para filas que aún no tienen ids del proveedor (`->whereNull('provider_conversation_id')`). Mejor aún: rechazar la fila si ya tiene una conversación distinta de la del cuerpo.
- Hacer lo mismo en `VoicePostCall::find()`.

**Opción estructural (architect):**

- Añadir `system__agent_id` al cuerpo de las herramientas.
- Exigir que ese agente sea el publicado, si es una llamada, o el de la sesión, si es el laboratorio.

Así el laboratorio no podría actuar sobre una llamada en ningún sentido. El coste: cambian `BODY_KEYS` y el mapper, y hay que volver a desplegar los agentes.

*Variante menor, dentro de este cambio:* alguien del Studio puede poner en su conversación el uuid de **otra** sesión abierta. Gastaría el cupo de esa sesión y recibiría información pública. Queda dentro de I-2.

### P4. El puente por evento

**En tiempo de ejecución está bien.**

- `event(…, halt: true)` devuelve la primera respuesta que no sea null, y los oyentes directos van antes que los comodín (`vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php:310-338` y `:400-405`, Laravel 12.58.0).
- `lab()` solo acepta las tres cadenas exactas, comparadas con `===` (`VoiceTools.php:209-214`). Si un oyente devuelve `true`, un array o cualquier otra cosa, el resultado es `call_not_found`.
- Para abrir el catálogo a cualquier cuerpo, otro oyente tendría que devolver **a propósito** la cadena `counted`. Además, solo se le consultaría si el oyente del Studio devolvió null.
- Con el freno echado, ni siquiera `counted` abre el catálogo: esa rama exige `! $frenado` (`:212`).

**Hoy no hay comodines registrados al arrancar, tampoco desde vendor.**

- Lo comprobé en la copia para los dos eventos: `Event::hasWildcardListeners()` da false y `getListeners()` da 1.
- Las dependencias son mínimas: no hay Telescope, Debugbar ni Sentry, y Pail solo escucha eventos de clase.

**Las guardas ampliadas tienen un hueco (A-1, demostrado en la copia).**

| mutación en `AppServiceProvider` | TelephonyIsolationTest | UltronEngineIsolationTest | prueba del oyente (`getRawListeners`) | comportamiento (VoiceLabToolsTest) |
|---|---|---|---|---|
| **M1:** `Event::listen(['*'], static fn ($n, $p) => str_ends_with($n, 'tool_call') ? 'counted' : null)` | **verde** (12/12) | **verde** (11/11) | verde: no ve los comodines | 5 fallos: lo atrapa porque contesta a todo |
| **M2:** `Event::listen(constant(VoiceTools::class.'::LAB_TOOL_EVENT'), static fn () => null)` | **verde** | **verde** | **roja**: lo atrapa | — |

Por qué pasan las guardas:

- **§9** solo mira `listen(` seguido de un literal (`TelephonyIsolationTest.php:587-591`). No ve la forma array (`listen([`) ni las concatenaciones (`'vo'.'ice.*'`).
- **§9b** cuenta el token `LAB_TOOL_EVENT`, pero dentro de `'::LAB_TOOL_EVENT'` eso es una cadena, no un token.
- **`onlyTheLabListener`** solo mira dónde aparece la clase del Studio.

Con la forma de M1, un comodín **selectivo**, que contestara `counted` solo para cierto uuid, pasaría las guardas y todas las pruebas. Eso ya es sabotaje en un commit, y una guarda estática no está para eso. Aun así, se cierra con una línea.

**Arreglo propuesto (para tester), validado en la copia.** En `test_el_oyente_lo_registra_la_aplicacion_una_sola_vez`, y en su gemelo del informe post-llamada, añadir:

```php
$this->assertFalse(Event::hasWildcardListeners(VoiceTools::LAB_TOOL_EVENT), 'ningún comodín escucha la consulta del laboratorio');
$this->assertCount(1, Event::getListeners(VoiceTools::LAB_TOOL_EVENT), 'un solo oyente, comodines incluidos');
// y lo mismo con VoicePostCall::LAB_EVENT
```

Resultado en la copia: rojo con M1, rojo con M2 y verde con el código del árbol. Ve cualquier forma de registro hecha al arrancar, también desde vendor.

*Opcional:* que §9 rechace también `listen([` cuando dentro haya un literal comodín o que empiece por `voice`.

**La «segunda entrega».** Otro sitio del código que disparara el evento gastaría cupo. También se puede esconder con `constant()`, y no hay una prueba sencilla en tiempo de ejecución que lo detecte. El impacto sería gastar el cupo de una sesión. Queda para la revisión de código.

### P5. Cupo y concurrencia (PostgreSQL)

*Está razonado, no probado: las pruebas de PG se omiten sin su arnés.*

**1. Aislamiento**

- `config/database.php` no fija `isolation_level`, así que PostgreSQL trabaja en READ COMMITTED.
- La transacción exterior es la de `handle()` (`VoiceTools.php:93`). La de `answerToolCall()` es un SAVEPOINT anidado dentro (`StudioVoiceLabSessions.php:579`).
- El cerrojo de fila de `SELECT … FOR UPDATE` (`:580`) dura hasta el COMMIT exterior, que incluye `labAnswer()`.

**2. Dos peticiones a la vez en el último hueco**

1. T2 se queda bloqueada en el `FOR UPDATE` hasta que T1 confirma.
2. En READ COMMITTED, PG vuelve a evaluar la fila y devuelve la versión ya confirmada, con `toolRequests = 20`.
3. T2 responde `quota_exceeded`.

**No pueden pasar más de 20.**

*Hipótesis:* si alguien configurara PG en REPEATABLE READ por defecto (no lo he visto), T2 fallaría con 40001 y daría un 500. Falla cerrado, y tampoco cuenta de más.

**3. Los otros escritores de `guard_codes`**

Todos leen, modifican y escriben bajo el **mismo** cerrojo de fila, y todos parten de `findingsOf()`, que ahora conserva `toolRequests` (`:736`):

- `end()`: `:439` y `:464`.
- `recordProviderReport()`: `:503` y `:544`. Bloquea por `agent_id`, que es la misma fila, porque cada sesión tiene un solo agente.

No se pierde ninguna escritura, en ningún sentido.

Los demás no tocan `guard_codes`:

- Los veredictos (`StudioVoiceLabVerdicts.php:47` y `:70`) bloquean la fila, pero no lo escriben.
- `expireStale()`, `fail()` y el `advance` de `open()` tampoco. Su UPDATE espera al cerrojo y vuelve a comprobar `status = open`.

Además, mientras la sesión está `open`, `provider` y `tester` son null: los dos escritores que los rellenan la cierran en la misma sentencia. No hay nada que pisar.

**4. Interbloqueos: ninguno**

- La transacción de la herramienta no bloquea filas de `voice_calls`, porque `find()` no encuentra ninguna. Después solo toma la fila de la sesión.
- Los demás escritores solo toman esa misma fila.
- El cerrojo consultivo de `open()` no lo toma la herramienta.

**5. Matices**

- El `FOR UPDATE` se toma **antes** de comprobar la vigencia. Una petición con el uuid de una sesión cerrada la bloquea unos milisegundos. Es despreciable.
- El cerrojo es la **única** protección. `advance()` no compara `guard_codes`, y en PG no se puede comparar una columna `json` (el tipo `json` no tiene `=`). Si un día alguien quita el `lockForUpdate`, vuelve la carrera.
- Por eso recomiendo una prueba de PG con dos conexiones (§3).

**6. SQLite**

No cuenta de más: la segunda escritura concurrente falla con `SQLITE_BUSY` o `BUSY_SNAPSHOT`. Está razonado, no probado.

Producción usa pgsql (`config/database.php:20`).

### P6. Exposición de datos

**La respuesta**

- De los planes vendibles: solo `nombre`, `condiciones` y `precio`.
- De la configuración: `sede` y `horario` (`VoiceTools.php:244-276`).
- Ni ids, ni uuid, ni personas.

**El registro**

- `voice.tool` lleva `tool`, `verdict` y `lab: true` (`VoiceTools.php:221` y `:232`).
- No lleva el uuid de la sesión, ni la conversación, ni el cuerpo.
- La contrapartida: el registro no dice qué sesión hizo la consulta. El rastro es el contador de la fila, y para un ensayo basta.

**El CRM**

- `present()` ya devolvía el uuid de la sesión a quien tiene `ultron.view` (`StudioVoiceLabSessions.php:982`).
- Ahora el uuid también va como `dynamicVariables.call_ref`, pero solo junto a la URL firmada (`:1013`): lo recibe solo quien abrió la sesión.
- `toolRequests` no se presenta. Lo comprueba `VoiceLabToolsTest.php:143`.

**El uuid en ElevenLabs**

Dónde acaba:

- Se queda guardado en las variables de la conversación de ElevenLabs.
- Vuelve con la post-llamada (`ElevenLabsEventVerifier.php:138`). Allí `VoicePostCall::find()` lo busca como `call_ref`, no encuentra nada y sigue el camino de siempre por `unknown()` y `labReport()` (`VoicePostCall.php:176-190`).
- Los textos del agente no interpolan `{{call_ref}}` (grep sin coincidencias en `app/` ni en `resources/`), así que el modelo no lo dice en voz alta.

Por qué no es un problema:

- No es un dato personal ni una credencial.
- Sin el secreto no da nada. Con el secreto, como mucho 20 respuestas públicas mientras la sesión siga viva.
- Es el mismo tipo de identificador que el `call_ref` de una llamada, que ya se envía a ElevenLabs como `user_id`.

### P7. `call_sid` vacío

**Hechos**

- Hoy, `""` hace que `isCallSid('')` dé false, y la respuesta es un 422 (`VoiceTools.php:150-151`).
- Ese 422 no se registra (A-2).
- `toolKey` ya ignora `""` (`VoiceRateLimits.php:199`).
- La post-llamada ya trata como ausente cualquier `call_sid` sin la forma correcta (`ElevenLabsEventVerifier.php:219-224`).

**Las dos opciones**

| opción | riesgo de seguridad | riesgo operativo |
|---|---|---|
| Tratar `""` como ausente. Solo en `call_sid`, solo la cadena vacía y en `body()`, para que `find()`, `lab()` y `toolKey` vean lo mismo. | **Nulo en capacidad.** Equivale a omitir la clave, y quien tiene el secreto ya puede hacerlo (`:148` y `:151`). `find()` no cambia: una llamada real se encuentra por conversación o por `call_ref`, igual que con null. El riesgo es de principio: se relaja el esquema estricto (DF-E8) sin evidencia, se puede tapar un cambio del proveedor y aparece la tentación de hacer lo mismo con `call_ref` o `conversation_id`, que deben seguir dando 422. | Solo cubre una de las formas desconocidas. Si ElevenLabs manda otra cosa (un literal, `"None"`, una plantilla sin rellenar), seguirá fallando. |
| Dejarlo estricto hasta el primer ensayo. | Ninguno: falla cerrado. | Si llega `""`, las herramientas del laboratorio vuelven a fallar en el ensayo, que es justo el problema de E18-8. Sin A-2, nuestro registro no lo ve. Habría que mirar el detalle de la conversación en ElevenLabs y hacer otra vuelta de despliegue. |

**Recomendación: dejarlo estricto, pero desplegar A-2.**

A-2 es una línea `voice.tool.invalid_schema` con los **nombres** de las claves recibidas y cuál falló, nunca los valores. Así el primer ensayo enseña exactamente qué llega, y la normalización se aplica después, con evidencia.

Si el lead no puede permitirse que el ensayo del 15-11 falle, tratar `""` como ausente (solo en `call_sid`) es aceptable desde el punto de vista de seguridad, con dos condiciones:

- una prueba que fije que `""` se comporta byte a byte como null, en una llamada real y en el laboratorio;
- que `call_ref: ""` siga dando 422.

## 3. Pruebas negativas que conviene añadir (para tester)

1. **A-1.** `hasWildcardListeners` da false y `getListeners` da 1, para `VoiceTools::LAB_TOOL_EVENT` y para `VoicePostCall::LAB_EVENT`.
2. **Cupo en PG** (con el arnés de PG):
   - Una sesión con `toolRequests = 19` y dos peticiones simultáneas desde dos conexiones. Debe salir exactamente un `ok` y un `tool_quota_exceeded`, y el contador debe quedar en 20.
   - Lo mismo intercalando `end()` y `recordProviderReport()`. El contador nunca se pierde.
3. **P-1, una vez arreglado:**
   - Un cuerpo con una conversación desconocida y el `call_ref` de una llamada `active` que ya tiene su `provider_conversation_id` debe dar `call_not_found`, sin cambiar `tool_calls` y con 0 exclusiones.
   - La saliente que aún no tiene ids (el caso legítimo del job) debe seguir encontrándose por `call_ref`.
4. **P-1 en la post-llamada:** una post-llamada de un agente de laboratorio que trae el `call_ref` de una llamada real no se ata a esa llamada.
5. **`call_sid: ""`:**
   - Fijar el comportamiento que se decida en P7. Hoy es un 422.
   - `call_ref: ""` y `conversation_id: ""` deben seguir dando 422.
6. **`call_ref` en mayúsculas,** con el uuid de una sesión abierta: debe dar 422, porque el esquema exige minúsculas (`VoiceIds.php:23`).
7. **Laboratorio con el freno echado y el cupo agotado:** debe dar `voice_braked`. Es el mismo orden que en una llamada: primero el freno y después el cupo.

## 4. Qué ejecuté y qué no

**En el árbol**

No lo cambié: `git status` está igual que al empezar.

| prueba | resultado |
|---|---|
| `VoiceLabToolsTest` | OK, 12 pruebas y 251 aserciones |
| `TelephonyIsolationTest` | OK, 12 pruebas |
| `UltronEngineIsolationTest` | OK, 11 pruebas |

**En una copia clonada**

La copia está en `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/9a436522-506e-4022-9a37-26115e2cf496/scratchpad/copia`. Se usó SQLite en memoria y sin red.

- **Mutación M1 (comodín en forma de array):** las dos guardas en verde y 5 fallos en VoiceLabToolsTest.
- **Mutación M2 (`constant()`):** las dos guardas en verde y la prueba del oyente en rojo.
- **La comprobación en tiempo de ejecución que propongo:** rojo con M1, rojo con M2 y verde con el código del árbol.
- **La reproducción de P-1** en el camino de las herramientas.
- **Las medidas de tiempos** de P2.

**Lo que no verifiqué, y por qué**

- **PostgreSQL** (cerrojos y aislamiento): no tengo el arnés de PG. Está razonado arriba.
- **ElevenLabs de verdad.** Hay dos cosas que no sé:
  - si acepta del cliente cualquier variable dinámica (P-1);
  - qué manda en `call_sid` en una conversación web (P7).

  No tengo red ni cuenta, y probarlo queda fuera de lo autorizado.
- **La parte de P-1 en la post-llamada:** la leí, pero no la ejecuté.
- **La configuración de producción:** no la leí.
- **El CRM y la E2E:** fuera de alcance.

## 5. Riesgos que quedan y decisiones del lead

1. **I-1 e I-2: aceptarlos.** I-2 es una denegación de servicio sobre un ensayo e I-1 es un oráculo de tiempos sin valor.
2. **P7: decidir.** Mi recomendación es dejarlo estricto y desplegar A-2.
3. **P-1: abrir un ticket aparte.** No bloquea este despliegue.
   - Tiene prioridad baja, pero puede afectar de verdad a una persona: una exclusión indebida, o la transcripción de un ensayo atada a una llamada real.
   - El arreglo puntual es pequeño y le corresponde a backend.
4. **Secretos que rotar: ninguno.** No he leído ningún fichero de entorno ni he impreso ningún secreto.
