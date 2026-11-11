# Informe E18-8 · Las herramientas de consulta en el laboratorio hablado

**Autor:** `backend`. **Fecha:** 11-11-2026. **Entorno:** local (árbol `_worktrees/ultron-h8`, rama
`feat/ultron-studio-h9`, HEAD `0ee08686`). Sin commit, sin push, sin producción, sin red hacia los proveedores (solo
consulté la documentación pública de ElevenLabs). He tocado `backend/app/**`, `backend/tests/**` y este informe.

---

## Resumen para el lead

**Estado: implementado y probado en local, pero NO ACTIVO. Falta una línea en el arranque que choca con una guarda
de lista cerrada de SEC8; la decisión es tuya.** Sin esa línea, el laboratorio sigue sin poder consultar (falla
cerrado), así que **para el 15-11 hace falta tu decisión** (y, si la apruebas, la de SEC8 sobre su guarda).

### El bloqueo (comprobado)

La telefonía no puede nombrar el Studio (`TelephonyIsolationTest` §1), el Studio solo puede usar de la telefonía
`VoicePublishedConfig::signatureFor` (§8) y `VoiceAdapters` no admite contenedor («sin contenedor ni proveedores de
servicio»). El único puente aprobado es **un** oyente en `AppServiceProvider`, fijado token a token por
`UltronEngineIsolationTest::onlyTheLabListener()`: el nombre corto `StudioVoiceLabSessions` aparece **una sola vez**,
dentro de `Event::listen(VoicePostCall::LAB_EVENT, [StudioVoiceLabSessions::class, 'recordProviderReport']);`.

Lo probé: añadí **temporalmente** la línea de registro, ejecuté las dos guardas y restauré el fichero (0 líneas de
diferencia después).

- `UltronEngineIsolationTest::test_fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` falla con
  `app/Providers/AppServiceProvider.php: T_NAME_QUALIFIED App\Services\UltronStudio\StudioVoiceLabSessions (línea 42)`.
- `TelephonyIsolationTest` pasa: la constante nueva no se llama `LAB_EVENT`.
- Total: 22 pruebas, 1 fallo.

No he ampliado la lista. Todo lo demás está hecho y probado; las pruebas conectan el oyente igual que iría en el
arranque.

**Lo que hace falta para activarlo (opción A, la que recomiendo)**, aplicable en cuanto lo aprobéis:

```php
// app/Providers/AppServiceProvider.php (junto al `use` de VoicePostCall y dentro de oyenteDelLaboratorioHablado())
use App\Services\Telephony\VoiceTools;
…
        Event::listen(VoicePostCall::LAB_EVENT, [StudioVoiceLabSessions::class, 'recordProviderReport']);
        Event::listen(VoiceTools::LAB_TOOL_EVENT, [StudioVoiceLabSessions::class, 'answerToolCall']);
```

Además, la enmienda de SEC8 a sus guardas (no la he escrito):

- `UltronEngineIsolationTest`: que `onlyTheLabListener()` admita exactamente **dos** secuencias. Son la de hoy y
  `['Event','::','listen','(','VoiceTools','::','LAB_TOOL_EVENT',',','[','StudioVoiceLabSessions','::','class',',',"'answerToolCall'",']',')',';']`,
  con el nombre corto exactamente dos veces, una dentro de cada una.
- Recomendado: en `TelephonyIsolationTest` §9, fijar también el evento nuevo, con una entrega (`VoiceTools::lab()`)
  y un oyente.
- Después, en `VoiceLabToolsTest`, añadir «el oyente lo registra la aplicación una vez»
  (`Event::getRawListeners()['voice.lab.tool_call'] === [[StudioVoiceLabSessions::class, 'answerToolCall']]`).
  El `forget` + `listen` del `setUp` puede quedarse: sustituye, no duplica.

**Opción B:** no activarlo. El código se queda dormido y la conversación del laboratorio recibe la negativa neutra.

**Alternativas que descarté** (cumplen la letra de las guardas pero no su intención, o no funcionan):

- Un canal por la caché con una clave compartida: es un puente nuevo sin revisar.
- Reutilizar `VoicePostCall::LAB_EVENT`: sobrecarga el informe post-llamada y rompe la §9.
- Leer la sesión desde la telefonía: lo prohíbe la §1.
- Leer el agente por `VoicePublishedConfig`, que tiene permitida la tabla de agentes: la fila del agente sigue en
  `deployed` cuando la sesión termina, así que no sabe si la sesión está abierta, y además cambiaría el propósito de
  E4-3.

### Qué cambió (fichero:línea)

- `app/Services/Telephony/VoiceTools.php`
  - `:59` `LAB_TOOLS` (solo `consultar_planes` y `consultar_horarios`).
  - `:71` `LAB_TOOL_EVENT = 'voice.lab.tool_call'`.
  - `:74-80` las tres respuestas cerradas del laboratorio (`counted`, `open`, `quota_exceeded`).
  - `:96` la única línea que cambia en `handle()`: sin llamada, `self::lab(...) ?? self::refused('call_not_found', …)`.
  - `:198` `lab()` y `:218` `labAnswer()`.
  - `:227` `refused()` con un `$lab` opcional que solo añade `lab: true` al registro. Por defecto es `false`, así que
    el camino de las llamadas es idéntico.
- `app/Services/UltronStudio/StudioVoiceLabSessions.php`
  - `:572` `answerToolCall()`, el oyente.
  - `:728-736` `findingsOf()` conserva `toolRequests`, solo si es > 0.
  - `:944-948` `conversationVariables(string $sessionUuid)`: `call_ref` = uuid de la sesión.
  - `:1013` la llamada desde `present()`.
  - Se quita la constante `CONVERSATION_CALL_REF = 'laboratorio'`, que nadie más usaba (lo comprobé con grep en
    `app` y `tests`).
- `tests/Feature/Telephony/VoiceLabToolsTest.php` (nuevo, 11 pruebas).
- `tests/Feature/UltronStudio/UltronStudioVoiceLabSessionsTest.php:277`: la aserción de E18-1 pasa de
  `'call_ref' => 'laboratorio'` a `'call_ref' => $d['uuid']`. Es el cambio de contrato deliberado.

### Diseño elegido

Es el que propusiste, con el puente obligado por las guardas:

1. **`call_ref` de la conversación del laboratorio = uuid de la sesión.** Pasa el esquema estricto y no es el de
   ninguna llamada.
2. En `VoiceTools::handle()`, **solo si no se encontró llamada**, se intenta `lab()`. Pregunta al laboratorio
   únicamente si se cumplen las cuatro condiciones:
   - la herramienta es de `LAB_TOOLS`;
   - el cuerpo trae `call_ref`;
   - **no** trae `call_sid` (una conversación web, como exige `VoicePostCall::labReport`);
   - la telefonía **no conoce** ese `conversation_id` (`exists()` sobre un índice `unique`). Así, el uuid de una
     sesión pegado a la conversación de una llamada real no se convierte en una consulta del laboratorio.
3. El orden es el de una llamada: **sesión vigente → freno → cupo**. Se pregunta con
   `event(LAB_TOOL_EVENT, [call_ref, !freno, VoiceCall::MAX_TOOL_CALLS], halt: true)`. La primera respuesta corta
   (un oyente de más no contaría dos veces) y solo se aceptan las tres respuestas cerradas:
   - `counted` → 200 `ok` con **los mismos datos** que en una llamada (`plans()`/`hours()`, el mismo código);
   - `quota_exceeded` → `tool_quota_exceeded`;
   - `open` con el freno echado → `voice_braked`;
   - cualquier otra cosa, incluido «no hay oyente» → `call_not_found`, **byte a byte la negativa de siempre**.
4. **El oyente (`answerToolCall`)** solo cuenta una sesión como suya si:
   - el laboratorio está **encendido**;
   - la sesión existe (por `uuid`, `unique`), está `open` y sin veredicto;
   - tiene la conexión emitida y está **vigente**: `url_expires_at + max_seconds ≥ ahora`, porque la URL es lo último
     con lo que se puede empezar y la conversación dura como mucho `max_seconds`;
   - su agente es `lab`, de **ElevenLabs** y está `deployed` (una sesión del simulador no cuenta).

   La fila se lee con `lockForUpdate`. Con el freno echado solo contesta. Si no, cuenta en
   `guard_codes.toolRequests` con `advance()`, el UPDATE condicionado `status = open` y sin veredicto, que es el patrón
   de `recordProviderReport`.

**Cupo y rastro (requisitos 2 y 3).**

- Cupo: 20 por sesión, el mismo `VoiceCall::MAX_TOOL_CALLS`, que se pasa en la carga; no hay otra fuente. Lo lleva
  `guard_codes.toolRequests` (un número), bajo el cerrojo de la fila. Lo negado y lo frenado no cuenta.
- Límites por petición: los mismos de siempre (`VoiceRateLimits::toolKey`, por conversación, 60/min, y el techo
  global).
- Rastro: ese contador y una línea `voice.tool` en el canal `voice` con `lab: true`, sin uuid ni ningún otro dato de
  la sesión.
- Ni filas ni eventos en `voice_*`, comprobado en las pruebas.
- El CRM no ve el contador: `present()` no cambia, porque los lectores del CRM son estrictos.

**Sin migraciones, sin rutas, sin claves de configuración, sin dependencias.**

### Ficheros para el commit

1. `backend/app/Services/Telephony/VoiceTools.php`
2. `backend/app/Services/UltronStudio/StudioVoiceLabSessions.php`
3. `backend/tests/Feature/Telephony/VoiceLabToolsTest.php` (nuevo)
4. `backend/tests/Feature/UltronStudio/UltronStudioVoiceLabSessionsTest.php`
5. `backend/docs/ultron-studio/plans/informes-motores/informe-e18-8.md` (este informe)

Si apruebas la opción A, se añaden `backend/app/Providers/AppServiceProvider.php` y las guardas de SEC8
(`tests/Feature/UltronStudio/UltronEngineIsolationTest.php` y, recomendado, `tests/Feature/Telephony/TelephonyIsolationTest.php`).

El árbol ya traía sin seguir las 5 carpetas `capturas/` de `docs/ultron-studio/ops/evidencia-h8|h9/`. No son mías ni
las he tocado.

### Pruebas ejecutadas (con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`)

| Qué | Resultado |
|---|---|
| **Rojo**: `VoiceLabToolsTest` + `UltronStudioVoiceLabSessionsTest` con `VoiceTools.php` y `StudioVoiceLabSessions.php` de **HEAD** (puestos temporalmente con `git show HEAD:…` y restaurados desde una copia; `git diff --stat` comprobado antes y después) | 32 pruebas: **10 fallos + 1 error**. De las 11 pruebas nuevas, 10 están en rojo: 9 fallan por comportamiento (hoy un uuid de sesión recibe `call_not_found`) y 1 da error porque la constante no existe. La undécima, `test_sin_oyente…`, pasa por diseño. El décimo fallo es la aserción modificada de E18-1 (`call_ref` vale `laboratorio`). Las otras 20 pasan |
| **Verde**: las mismas, con el código nuevo | **OK, 32 pruebas y 745 aserciones** |
| Regresión de la telefonía: `VoiceToolsTest` (con **todos** los casos del fixture `elevenlabs-webhook-bodies.json` §tools), `VoiceLabPostCallTest`, `VoiceLabPostCallWiringTest`, `VoicePublicRateLimitsTest`, `TelephonyRoutesTest` | **OK, 44 pruebas y 1 184 aserciones** |
| Resto del laboratorio: `UltronStudioVoiceLab{BudgetLock,NoNetwork,Trace,Transfer,Verdict}Test` | **OK, 21 pruebas y 261 aserciones; 4 saltadas** (las de `BudgetLockTest`, «solo PostgreSQL»: ya lo estaban) |
| **Guardas**: la orden exacta del encargo (15 ficheros) | **OK, 137 pruebas y 177 965 aserciones (24,7 s)** |
| Sonda del registro (temporal y revertida): `UltronEngineIsolationTest` + `TelephonyIsolationTest` con la línea en el arranque | 22 pruebas, **1 fallo**, el de arriba |
| `vendor/bin/pint --test` sobre los 4 ficheros de código y pruebas | `passed` |
| `php artisan ultron-studio:export-fixtures --path=<scratchpad>` con el código de HEAD y con el nuevo, y después `diff -r` | **idénticas** |
| `VoiceTools::body()` directo | `call_ref:"laboratorio"` → `null` (**422**); `call_ref:<uuid>`, `call_sid:null` → acepta; `call_sid:""` → `null` (**422**) |

**Prueba que no puede estar en rojo hoy, por diseño:** `test_sin_oyente_el_laboratorio_recibe_la_negativa_de_siempre`
comprueba el fallo cerrado, que hoy ya se cumple. La regresión «una llamada real se comporta igual» la cubren la
suite entera de `VoiceToolsTest` (en verde) y dos pruebas nuevas (`…los_mismos_que_recibe_una_llamada_real` y
`…que_la_telefonia_conoce_o_con_llamada_de_twilio…`). Esas dos sí están en rojo hoy, porque empiezan comprobando que
la sesión responde.

No ejecuté la batería completa ni nada de `tests/Feature/Marketing`, como pediste.

### Lo que NO verifiqué

- **ElevenLabs de verdad.** No sé qué manda en `call_sid` (`system__call_sid`) en una conversación **web**. La
  documentación oficial lo da como `string | null`, «Twilio Call SID (Twilio calls only)», pero no dice el valor fuera
  de Twilio.
  - Con `null` o sin la clave, funciona (está probado con las dos).
  - Si manda `""`, el esquema estricto responde **422**. No lo he relajado, por el requisito 1.
  - El controlador no registra los 422, así que **en el registro de producción no se verá**. Se ve en el detalle de la
    conversación en ElevenLabs: las peticiones de las herramientas y su respuesta.
  - Hay que mirarlo en el primer ensayo.
- **PostgreSQL.** Las pruebas corren en SQLite, donde `lockForUpdate` no hace nada. El cerrojo del cupo con dos
  peticiones simultáneas en el último hueco no está probado en PG. Es el mismo patrón que `recordProviderReport`.
- **La E2E del H8** (`tests/Feature/Voice/E2E`): solo corre con el arnés de `ops/` y se salta sin él. No la ejecuté.
- **El CRM:** no lo toqué ni ejecuté sus pruebas (ver «CRM» abajo).
- **Producción (supuesto, no comprobado).** Para que el 15-11 el agente informe de verdad, hace falta, **además del
  código**:
  - **un perfil de voz con `consultar_planes` y `consultar_horarios`**: según la fila E18-8, «Sofia Medellin» rev. 2
    solo tiene `no_volver_a_llamar`, así que su agente compilado no tendrá esas herramientas y hace falta una revisión
    del perfil enviada o aprobada;
  - `voice.facts.site` y `voice.facts.hours` configurados: si faltan, `consultar_horarios` responde
    `disponible: false`;
  - planes vendibles en el catálogo.

### Punto 8: qué gana quien tenga el secreto de las herramientas

**Antes**, con el secreto solo se obtenían planes u horario dando los ids de una llamada **real** activa
(`conversation_id`, `call_sid` o `call_ref`).

**Ahora, además**, con el **uuid de una sesión del laboratorio abierta, vigente y de ElevenLabs**, obtiene hasta 20
respuestas por sesión con:

- los **planes vendibles**: nombre, «N días de vigencia» y precio;
- la **sede** y el **horario**.

La ventana es de unos 20 minutos como máximo desde que se emite la URL (15 de URL más la conversación, de 5 como
mucho). **Es información comercial pública**, la misma que el agente da a cualquiera que llame.

**No gana:**

- escribir en `voice_*`;
- excluir números o transferir: `solicitar_humano` y `no_volver_a_llamar` se niegan;
- tocar una llamada real: una conversación conocida o un `call_sid` excluyen el camino del laboratorio;
- gastar en el proveedor: no sale a la red;
- **enumerar sesiones**: la respuesta es idéntica byte a byte para una sesión inexistente, terminada, fallida,
  caducada, del simulador, con el laboratorio apagado, sin oyente o con un uuid ajeno (probado).

Para obtener datos necesita el secreto **y** un uuid de sesión: 122 bits aleatorios, visibles en el CRM para quien
tiene `ultron.view`, en el navegador de quien prueba y, ahora, en la conversación guardada en ElevenLabs.

**Efecto lateral que sí puede causar:** agotar el cupo de esa sesión, es decir, como mucho 20 UPDATE del contador en
una fila del Studio. Con eso deja sin herramientas al ensayo en curso; es una denegación de servicio sobre una sesión
de prueba. Sigue sin acceso a datos personales ni a escrituras fuera de ese contador.

Que un endpoint público escriba, aunque sea un contador, en una tabla del Studio es parte de lo que SEC8 debería
aprobar junto con el puente.

---

## Detalle

### Contratos nuevos o modificados

| Qué | Antes | Ahora |
|---|---|---|
| E18-1 `data.connection.dynamicVariables.call_ref` (201 de `POST voice-lab/sessions`) | `"laboratorio"` | **el uuid de la sesión** (el mismo que `data.uuid`). Las otras tres variables no cambian |
| E18-3 «herramientas en el laboratorio» | El contrato dice «`call_not_found`», pero **en realidad era 422 `invalid_schema`** (comprobado: `laboratorio` no es un uuid) | Las dos de lectura responden `ok` con la sesión abierta y vigente. Lo demás, 200 `call_not_found` con el mensaje neutro |
| Evento interno `voice.lab.tool_call` (telefonía → Studio) | — | Carga `(string callRef, bool count, int quota)`, entrega con parada en la primera respuesta, respuestas `counted` / `open` / `quota_exceeded` / null |
| `guard_codes` de una sesión | `{format, provider, tester}` | Además `toolRequests: int`, **solo si es > 0**. No viaja al CRM |
| Respuestas de `POST /api/voice/tools/{tool}` para llamadas | — | **Sin cambios**: el camino de la llamada no se toca |

Las filas E18-1, E18-3 y E18-8 del contrato hay que actualizarlas. No las toco: el contrato es tuyo.

**Efecto si se despliega sin la línea del arranque.** La conversación del laboratorio deja de recibir el 422 de hoy
(un error de herramienta para ElevenLabs) y recibe el 200 neutro, cuyo mensaje dice que no puede consultar y que
ofrezca que la llamen o que escriban al gimnasio. En los dos casos el agente no da el dato.

### CRM (requisito 6)

- `ultron-studio:export-fixtures` **no cambia**: no exporta sesiones del laboratorio (comprobado con `diff -r`).
- El lector `variablesDeConversacion()` acepta un uuid (de 1 a 120 caracteres): **el CRM no necesita cambios** y el
  orden de despliegue es solo el backend.
- **Deriva, sin rotura:** `Iron-Body_Front/src/app/modules/ultron-studio/testing/backend-voice.testing.ts:4396` (lo
  genera `gen-backend-voice-3.py`, FE8-3, no `export-fixtures`) conserva `"call_ref": "laboratorio"` en el ejemplo de
  la sesión abierta. Lo afirman `studio-voice-parse.spec.ts:246` y `studio-voice-session.spec.ts:283`. Esas pruebas no
  se rompen, porque prueban su propio ejemplo, pero el ejemplo ya no es la respuesta real. Conviene regenerarlo cuando
  toque; no lo he hecho.

### Para `tester` (casos límite que conozco desde dentro)

- En **PostgreSQL**: dos peticiones simultáneas con `toolRequests = 19`. Solo una puede llevarse el 20 y la otra debe
  recibir `tool_quota_exceeded` (lo protege el `FOR UPDATE` sobre la fila de la sesión).
- La sesión se cierra (por quien la abrió o por el informe) mientras llega una herramienta. `advance()` devuelve
  `false` y la respuesta es `call_not_found`.
- El borde de la vigencia: `url_expires_at + max_seconds` todavía responde; un segundo después, no (probado con ±1 s).
- El freno se echa entre que se consulta y se responde: se lee una vez, antes de preguntar al laboratorio.
- Que ElevenLabs mande `call_sid: ""` en web (422, ver arriba).
- Que una herramienta del laboratorio llegue con el `conversation_id` de una llamada real: `call_not_found` y la
  llamada no gasta cupo (probado).

### Pendiente antes de cerrar

- **Tu decisión** sobre la opción A y **la de SEC8** sobre la enmienda de sus guardas: es lo que bloquea.
- La **revisión de `reviewer`** y, por la superficie pública y la escritura en una tabla del Studio desde un endpoint
  con secreto, la de `api-security-specialist` / SEC8.
- **La revisión del perfil de voz** con las dos herramientas: es contenido del Studio, no código.
