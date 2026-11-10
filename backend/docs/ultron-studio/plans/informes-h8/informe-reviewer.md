# REV8 · Revisión final independiente del Hito 8 (voz y telefonía)

**Fecha:** 09-11-2026, 04:00–05:00 UTC. **Paquete:** REV8 (`reviewer`), puerta 4 del contrato. **Alcance:** el contrato `ULTRON_STUDIO_H8_CONTRACT.md` con las Enmiendas 1–12; el backend `feat/ultron-studio-h8-voice` (base `origin/main` = `7c84cb5`; HEAD `82fd514` al empezar y **`61597336` al terminar**, con B8-d confirmado en `bff58d8b` durante la revisión); el CRM `45dd73a` (base `c9eef6d`); los guiones y documentos de operación del H8. **Cómo:** sobre el diff real y copias aisladas del árbol (`git archive` de cada HEAD en el scratchpad, `vendor/` clonado), nunca en el árbol compartido; una base PG desechable propia (`ultron_studio_h8_rev`, `ultron_studio_h8_rev_mig`). No he escrito nada salvo este fichero. Sin commit.

## Veredicto

**APROBADO CON CONDICIONES para la fase 1.** 0 bloqueantes · 3 mayores · 5 menores.

Lo que protege la fase 1 está en su sitio y lo he comprobado yo: la constante cerrada, todas las banderas apagadas por defecto, las puertas de diff del OTP, del motor, de Meta y de Legacy vacías, el catálogo congelado, las guardas de SEC8 sin excepciones fuera de contrato (salvo una pendiente de ratificar, m-3), y las suites del H8 en verde en SQLite y PG sobre el HEAD final. Con todo apagado y sin el secreto de iniciación ni la subcuenta de Twilio en el servidor, ninguna ruta pública nueva puede escribir ni llamar.

**Condiciones para ejecutar la fase 1:**
- **C-1 (M-1):** repetir el E2E (`ops/h8-e2e.sh`) y el `--solo-comprobar` del banco sobre el commit que se despliegue. La evidencia de la puerta 3 y el ensayo son de un código anterior a B8-d.
- **C-2 (M-2):** dejar escrito en el contrato el estado real de la reescritura de la rama y del respaldo remoto, y decidir qué se hace con la rama remota antes de cualquier push de la rama.

**Antes de la fase 3 (promover):** M-3, que es de diseño (escalado a `architect`). **Antes de la fase 2:** m-1 y m-5.

## Verificado (ejecutado por mí, con su salida)

| Qué | Cómo | Resultado |
|---|---|---|
| Rama y base | `git log origin/main..HEAD`, `git merge-base origin/main HEAD` | 54 commits del H8; base `7c84cb52…`; `7c84cb5` es ancestro de HEAD |
| Puerta de diff (protecciones 4, 5, 7 y 11) | `git diff --stat origin/main...HEAD --` `app/Services/{Sms,Otp,Marketing}`, `config/{otp,marketing}.php`, `app/Http/Controllers/Api/{TwilioUsageTriggerController.php,Internal}`, `routes/{api,marketing}.php`, `app/Jobs` (solo M/D/R), migraciones `*marketing*`, `UltronPublishedConfig`, `StudioCatalog.php`, `VoiceCallPolicy.php` | **Vacía**, también contra el árbol de trabajo con el WIP de B8-d |
| Constante (protección 2) | `grep CALLS_ENABLED_IN_CODE` | `public const CALLS_ENABLED_IN_CODE = false;` una vez, `app/Services/Voice/VoiceCallPolicy.php:23`; el resto son lecturas `VoiceCallPolicy::…` |
| Todo apagado (protección 1, E11-2) | lectura de `config/voice.php`, del bloque `voice` de `config/ultron_studio.php` y del bloque de voz del fichero de ejemplo de entorno (líneas 405–521) | Banderas `false` con `FILTER_VALIDATE_BOOLEAN`, presupuestos y cupos diarios en `0`, laboratorio `fake`/`0`; solo techos de limitadores con defecto no nulo, lo que admite E11-2 |
| Reescritura (E8-8) | `git diff refs/backup/h8-voice-antes-de-rehacer HEAD -- backend/tests/Unit/Twilio/`, y los 35 commits del original frente a los 35 de la cadena actual, uno a uno (asunto, autor, fecha, número de padres y ficheros distintos) | Solo cambia `TwilioVoiceCarrierTest.php:34` (`'SK0123…cdef'` → `'SK' . '0123456789abcdef' . '0123456789abcdef'`, mismo valor); metadatos iguales; el merge `f6ed7b8a` vuelve a tener `7c84cb52` de segundo padre. Hubo **dos** reescrituras: ver M-2 |
| Suites del H8 sobre `82fd514` | phpunit en la copia aislada | Voz y telefonía SQLite **761** / 196 430 aserciones / 0 fallos / 146 omitidas; Studio de voz **588** / 6 464 / 0 / 73; puerta PG **1 349** / 204 046 / 0 / 15. Coinciden con SEC8-b (2) |
| Suites del H8 sobre `61597336` (final) | ídem | Voz y telefonía SQLite **786** / 202 001 / 0 / 146; Studio de voz **588** / 6 464 / 0 / 73; puerta PG **1 374** / 209 617 / 0 / 15; **Studio completo 3 091** / 32 406 / 0 / 179. Coinciden con E12-9 y E10-1 |
| Lo omitido | conteo por clase en los JUnit | PG: solo los 15 E2E que exigen el arnés (`H8E2EAbiertaTest` 9, `H8E2ECerradaTest` 6). SQLite: además las de esquema que solo corren en PG (`VoiceCallTransitionsTest` 72, `VoiceLedgerSchemaTest` 55, `VoiceSchemaTest` 4). Ningún `markTestSkipped` fuera de «solo PG» y «solo con el arnés» en todo el diff |
| Pruebas relajadas | aserciones quitadas frente a añadidas en las 16 pruebas anteriores al H8 que el diff modifica | Lo quitado es lo que el contrato manda reescribir (H6: «ninguna ruta ni comando de voz», «`console.php` no se toca», «una sola llamada `Http::`», el recuento 56 → `STUDIO_ROUTE_COUNT`, la lista de `app/Services/Twilio`, el texto del doctor) y está sustituido; la frontera del H6 «sin `UltronStudio`, `App\Services\Marketing` ni `App\Models\`» se conserva y se amplía (`tests/Unit/Voice/VoiceIsolationTest.php:655-660`) |
| `auth:coverage --unmapped` | en las copias de `82fd514` y de `61597336` | Vacío, salida 0, en las dos |
| Rutas | `route:list --json` | 21 del Studio de voz, 13 del CRM de la telefonía y las 4 públicas, cada una con su tope (8 KB, 8 KB, 1 MB, 64 KB) |
| Esquema E3-5 | `php artisan migrate` de `82fd514` en una base PG vacía y la consulta `Q_ESQUEMA` de `deploy-h8-backend-fase1.sh:243` | **`68 84 6 18 27 51 3 7`**. B8-d no toca `database/` ni `routes/` (`git diff --stat 82fd514 61597336 -- database routes` vacío) |
| E2E (evidencia) | el hash de `h8-e2e.sh:430-432` recalculado sobre `ops/evidencia-h8/2026-11-08/20261108T224030Z-8ed09240/*/checks-*.jsonl` | **395 comprobaciones, 0 fallos, `6ee260efa9e55e48031588781aab7b8baf55c885fbe929bb8b77941eb4ce3beb`**; el sha de `tests/Feature/Voice/E2E/*.php` del HEAD (`11d85036bd429495`) es el del resumen; `red-fuera-de-127.txt` vacío; el giro de la constante, solo en la copia (`giro-constante.diff`) |
| CRM | `ng test --watch=false --include='src/app/modules/ultron-studio/studio-voice*.spec.ts'` | **13 ficheros, 93 pruebas en verde**. Los 8 avisos NG8107 del build son de ficheros ajenos al H8 (facturas, cartera, bandeja, informes) |
| Cliente de ElevenLabs | `package.json`, `package-lock.json`, `node_modules` | `@elevenlabs/client` **`1.30.0` exacto**; la sesión se abre con `connectionType: 'websocket'` sobre la URL firmada (`studio-voice-lab-conversation.ts:108-122`), así que la CSP con `connect-src wss://api.elevenlabs.io` basta; los worklets se sirven del propio build (`angular.json`) y `libsampleratePath` apunta a una ruta propia |

**Lo que revisé leyendo el código (sin hallazgos):**
- **B8-d completo**, comparado con E10-4..E10-9 y E12-1..E12-6:
  - el tope de violaciones (`VoicePostCall::verdictCodes`, cota en compacto con holgura bajo los 4 096 B de `vce_codes_chk`, columna `json`);
  - el rearme del freno (`VoiceBrake::nextOpening/openOrRearm/observedAfter`);
  - lo comprometido (`VoiceCostGuard::contribution` y su SQL);
  - la conciliación en seco (sin transacción, sin cerrojos, sin `Cache::put`; estados y precios simulados para el techo);
  - «gana el corte» en los dos órdenes (`PlaceOutboundVoiceCall::record`, `VoiceHangup::hangup/startedAfterCancel`, `VoiceReconciler::canceledStartFor`);
  - el punto 7 (`VoiceCallsQuery::eventCodes`: lista blanca, saneado a `[a-z0-9_]`, sin ids del proveedor; `caller` es el estado del canario, no un número).
- **Las 4 rutas públicas:** credencial primero; los fallos, en su cubo `ruta|IP|fallo`; después, el cupo por llamada y el techo global (E5-4, E6-4, E9-3). `VoiceTools` toma la fila con `lockForUpdate()`, así que el cupo de 20 no se salta en paralelo.
- **Los cupos con nombre del CRM** (`AppServiceProvider::limitadoresDeLaTelefonia`): por persona con sesión; por ruta y red sin ella.
- **El oyente único** del laboratorio (`Event::listen(VoicePostCall::LAB_EVENT, …)`), fijado token a token por dos guardas.
- **`ExpiredCachePruner`**: solo claves caducadas con el prefijo, `LIKE … ESCAPE '!'` (el patrón que sí funciona en PDO-pgsql) y tandas.
- **S8-c:**
  - `expectedSpecHash` se comprueba antes de la puerta del laboratorio y de desplegar (`StudioVoicePublishing.php:249`) y en `promote` antes de medir la evidencia (`:344`);
  - el bloque `can` de la ruta 45 son las 7 llaves de D8-22 resueltas para la sesión;
  - el reparto por defecto (`CrmPermission::byRole`): el Administrador tiene `voice_manage`, `telephony.view` y `approve`, y no `voice_publish`, `voice_lab_run`, `call_place` ni `contacts_manage` (D8-22, E6-7, E11-3); el dominio `telephony`, en `SHARED_TOKEN_EXCLUDED_DOMAINS`.
- **Las guardas de SEC8** (`VoiceIsolationTest`, `TelephonyIsolationTest`, `UltronEngineIsolationTest`, `VoiceBoundaryTest`):
  - lista cerrada, verbos por método, una petición por método, hosts oficiales;
  - lectores enumerados, cero red fuera de los adaptadores, una sola puerta de salientes;
  - el lector de la cadena con exactamente sus dos tablas, y el arranque con exactamente su oyente.
  - Única excepción sin ratificar: m-3. Una más ancha de lo necesario: m-2.
- **`deploy-h8-backend-fase1.sh`:**
  - `PREVIO` fijo y la detección de ajenos por parche (`--acepta-main`);
  - la puerta de diff con lista de permitidos; las migraciones pendientes, que tienen que ser exactamente las del H8;
  - la constante antes del merge (E10-7) y otra vez en ejecución (paso 10, `callsEnabledInCode` de `voice:doctor --telefonia`);
  - escribe solo 9 claves, todas `false`/`0`/`fake`; aborta si hay piezas de la fase 2; sin `composer` ni el worker de voz.
- **`deploy-h8-crm-fase1.sh`:** `rsync` sin `--delete`, el sello del build comprobado y `npm ci` solo si cambió el lock.

## Bloqueantes

Ninguno.

## Mayores

### M-1 · La evidencia de la puerta 3 y el ensayo de la fase 1 no son del código que se va a desplegar → **lead + `tester` (E2E) + `devops` (banco)**

- **Qué:**
  - El E2E final se corrió sobre `8ed0924`: `ops/evidencia-h8/2026-11-08/20261108T224030Z-8ed09240/resumen.txt`, «backend 8ed09240».
  - El ensayo de la fase 1 se hizo sobre `8d3c41f` (E10-3; `plans/informes-h8/informe-devops.md:281-292`).
  - La regresión global se hizo sobre `e112586` (E10-2).
  - Después, `bff58d8b` (B8-d) cambia **10 ficheros de `app/` y `config/`**, +650/−74: `VoicePostCall`, `VoiceReconciler`, `VoiceBrake`, `VoiceCostGuard`, `VoiceHangup`, `VoiceCallsQuery`, `PlaceOutboundVoiceCall`, `VoiceReconcileCommand`, `VoiceDoctorCommand` y `config/voice.php`. Comando: `git diff --stat 8ed09240 HEAD -- app routes database config bootstrap`, cuyo único commit es `bff58d8b`.
  - Son justo los caminos que recorre la fase abierta del E2E: el job real, las cancelaciones de `recheck()`, el freno por `seal_mismatch`, la conciliación y la deriva del número, revertir y apagar.
- **Escenario de fallo:** una interacción de B8-d con la pila real (el worker de la cola `voice`, los simuladores o el `--dry-run` contra el carrier HTTP) que las pruebas Feature con `Http::fake` no ven quedaría desplegada con una puerta 3 «en verde» que no la probó. Ejemplo: una regresión en el `one()` de la conciliación con el simulador de Twilio paginando.
- **Mitigación que ya existe** (por eso no lo bloqueo):
  - las 25 pruebas nuevas de B8-d y las suites del H8, en verde en SQLite y PG sobre el HEAD final (tabla de arriba);
  - en la fase 1 esos caminos están inertes: monitor apagado, sin secreto de iniciación ni subcuenta, constante cerrada.
- **Corrección:**
  - `bash docs/ultron-studio/ops/h8-e2e.sh` sobre el commit final (99 s), con su hash en `ops/evidencia-h8/`;
  - el `--solo-comprobar` del banco con `ESPERADO` = el commit que se despliegue;
  - recomendable: `suite-compare.sh` del mismo día, porque B8-d cambió una prueba del H6 (`VoiceDoctorCommandTest`).

### M-2 · La rama se reescribió dos veces, el contrato registra una y el único respaldo remoto es la reescritura defectuosa → **lead (decisión del propietario para cualquier push forzado)**

- **Qué dice el contrato:** E8-8 (`ULTRON_STUDIO_H8_CONTRACT.md:849`) dice «HEAD antiguo `e753649` en `refs/backup/h8-voice-antes-de-rehacer`; nuevo `c01124c`, subido a GitHub».
- **Qué pasó (reflog de la rama):**
  1. `c01124cd` (16:34:19 -05) y `bb239b53`, la Enmienda 8 sobre él (16:37:29).
  2. **`f7ae3a3c` (16:59:26, sin mensaje: una segunda reescritura).** La cadena actual desciende de ahí, no de `c01124c` (`git merge-base --is-ancestor c01124c HEAD` → NO).
- **La primera reescritura es la defectuosa** (H-10 de OPS8-b): su merge `ae6aa156` tiene de segundo padre `1279016a`, una **copia reescrita** de `7c84cb5`, y arrastra otras 7 copias de commits de `main` (`a63ef9df` … `f2105d53`).
- **La cadena actual es correcta.** Lo verifiqué par a par contra `e753649`: solo cambia el literal autorizado, y el merge vuelve a apuntar a `7c84cb52`. El arreglo consta en `informe-devops.md:230-235` y en el plan, pero no en el contrato.
- **El remoto:**
  - `origin/feat/ultron-studio-h8-voice` = **`bb239b53`** (el mismo commit que `refs/backup/h8-voice-rehecho-1`), que no es ancestro de HEAD;
  - **54 commits de HEAD no tienen copia remota** (todo lo hecho desde OPS8-b hasta B8-d y la Enmienda 12);
  - el remoto tiene 33 que HEAD no tiene, entre ellos las 8 copias.
- **Escenario de fallo:**
  - quien parta de la rama remota (otro clon, un agente nuevo, un `git pull` en la rama) trae las 8 copias de `main` y pierde 54 commits;
  - el registro del contrato dice que el respaldo es otro;
  - un `git push` de la rama ya no es avance rápido y exige `--force-with-lease`, que la política global prohíbe sin orden expresa del propietario.
  - (La fase 1 sube `main`, que sí avanza rápido desde `7c84cb5`: no la bloquea.)
- **Corrección:**
  - una enmienda que registre la segunda reescritura, el ref `refs/backup/h8-voice-rehecho-1` y que el remoto actual es la cadena defectuosa;
  - subir la rama con **otro nombre** (sin forzar) o forzar solo con la autorización expresa;
  - no usar la rama remota actual como base de nada.

### M-3 · La evidencia para promover cuenta llamadas cuya guarda posterior nunca corrió → **`architect` (diseño) y después `backend` (B8/S8)**

- **Qué:**
  - `calls` = llamadas selladas, sin tramos, con estado fuera de `denied`, `requested`, `canceled` y `failed_to_start` (`app/Services/Telephony/VoiceEvidence.php:33` y `:76`). Cuentan también `active`, `ambiguous`, `busy`, `no_answer` y `failed`, y cualquier llamada sin post-llamada.
  - `StudioVoiceEvidence::evaluate()` (`app/Services/UltronStudio/StudioVoiceEvidence.php:150-159`) solo exige `calls ≥ minCalls` y **ceros** de eventos (`guardViolations`, `sealMismatches`…). No exige que cada llamada tenga su `guard_verdict` ni `seal_verified = true`; `summary()` sí cuenta las verificadas, pero no entra en la evaluación.
- **Escenario de fallo (fase 3):**
  1. Se rota la clave con `el-verificar.sh`, que regenera también el secreto del webhook (E11-6), y no se vuelve a registrar en ElevenLabs.
  2. Cada post-llamada da 401 y ElevenLabs desactiva el webhook tras 10 fallos (D8-16).
  3. En 24 h llaman 3 canarios. La iniciación sella las tres llamadas (`active`) y la conciliación las pasa a `completed` desde Twilio.
  4. Ninguna lleva `guard_verdict` ni sello verificado: `guardViolations = 0` y `sealMismatches = 0`.
  5. Con el veredicto del laboratorio y el freno suelto, `evaluate()` da `passes = true` y se puede **promover a `rest`** sin una sola transcripción inspeccionada.
  - Igual con 3 salientes `no_answer`.
- **Por qué no es un fallo de código:** es fiel a la letra de D8-19, E4-2 y E6-2 («N llamadas **selladas**»). Por eso va a `architect`: la regla confunde «sin pruebas de un fallo» con «pruebas de que no hubo fallo».
- **Corrección propuesta:**
  - que `calls` cuente solo `completed` con `seal_verified = true` y su `guard_verdict`, o un criterio nuevo «llamadas sin veredicto = 0»;
  - antes de la fase 3, junto con la salud automatizada del webhook (decisión (f), pendiente).
- **Verificado:** leyendo el código; no ejecutado.

## Menores

### m-1 · Los documentos de operación dicen que B8-d está «en curso» y describen el comportamiento anterior → **`documentation-specialist` / lead, antes de la fase 2**

- **Riesgos:** `ULTRON_STUDIO_H8_RIESGOS.md:30, 36, 37, 38, 44` (R8-13, R8-19, R8-20, R8-21 y R8-27) siguen en «Corrección en curso (B8-d)», y la base declarada (`:9`) es `8d3c41f`.
- **Guía de administración:** `ULTRON_STUDIO_GUIA_ADMINISTRACION.md:405` y `:472` dicen que con presupuesto 0 la primera llamada del día echa el freno de gasto. Desde E12-3 eso **no pasa** con una fila de coste 0, como la de la prueba O-2.
- **Manual de la voz:** `ULTRON_STUDIO_H8_VOZ.md`:
  - `:279` usa `would_open_brake` (E12-4 lo fijó como `wouldOpenBrake`);
  - `:358`, la nota de DOC8 que cita `VoiceReconciler.php:326-336` y la regla vieja;
  - `:391` y `:406`, «B8-d, corrección en curso».
- **Plan de despliegue:** `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`:
  - `:1671` manda diagnosticar con `voice:reconcile --json`, que escribe, en vez de `--dry-run`;
  - `:1678`, el aviso del `cost_cap` con presupuesto 0;
  - `:1381` cita «las enmiendas 1–6».
- **Escenario de fallo:** en la fase 2, quien sigue el plan diagnostica un freno con la orden que escribe (y puede abrir otra apertura) y espera un `cost_cap` que ya no llega.
- **Corrección:** actualizarlos a E12, con su fecha y su fuente.

### m-2 · La lista blanca del motor admite migraciones de voz por comodín → **`security`**

- **Qué:** `tests/Feature/UltronStudio/UltronEngineIsolationTest.php:97`, `ALLOWED_H8_PATTERNS = ['database/migrations/2026_11_1*voice*.php']`. El comentario dice que es «hasta que DB8 fije los nombres»; ya están fijos: las dos de `deploy-h8-backend-fase1.sh:78-79`.
- **Escenario de fallo:** una migración futura del 10 al 19-11 con «voice» en el nombre (por ejemplo, la columna `events_watermark_id` de E9-6) puede nombrar `ultron_studio_publications`, la cadena del motor, sin que la guarda lo vea.
- **Corrección:** las dos rutas exactas en `ALLOWED`.

### m-3 · La telefonía puede nombrar `StudioCanaryPhone` sin que ninguna enmienda lo haya ratificado → **lead (+ `security`)**

- **Qué:** D8-2 admite del Studio solo `StudioCanaryNumber`. `tests/Feature/Telephony/TelephonyIsolationTest.php:102-108` admite además `StudioCanaryPhone`, y su comentario dice «No lo nombra D8-2: **decisión pendiente del lead**» (lo mismo en `UltronEngineIsolationTest`, `TELEPHONY_STUDIO_REFERENCES`). No aparece en las Enmiendas 1–12.
- **Por qué es menor:** el riesgo es bajo, porque es puro y está acotado a `parse`, `masked`, `message` y constantes, pero es una excepción de guarda fuera del contrato.
- **Corrección:** ratificarla en una enmienda o retirarla.

### m-4 · La comprobación de E10-7 no está anclada → **`devops`**

- **Qué:** `deploy-h8-backend-fase1.sh:330` cuenta líneas con `CALLS_ENABLED_IN_CODE\s*=\s*false\s*;` en cualquier sitio del fichero.
- **Escenario de fallo:** el giro de la fase 3 escrito como `public const CALLS_ENABLED_IN_CODE = true; // antes: CALLS_ENABLED_IN_CODE = false;` da una línea con coincidencia y el paso 0 lo deja pasar. El paso 10 lo detecta en ejecución (`callsEnabledInCode`), pero ya desplegado.
- **Corrección:** anclar la expresión a la declaración (`^\s*public const CALLS_ENABLED_IN_CODE = false;\s*$`) y exigir además 0 coincidencias de `= true`.

### m-5 · `el-verificar.sh` termina con éxito y destruye el fichero de la clave aunque la verificación falle → **`devops`, antes de la rotación de la fase 2 (E2-2)**

- **Qué:**
  - `ops/el-verificar.sh:11` es `set -uo pipefail`, sin `-e`;
  - en `:62` se guarda `RC=${PIPESTATUS[0]}` del `doctor --verify`, pero no se usa;
  - `:68-70` borran el fichero de la clave e imprimen «ELEVENLABS VERIFICACIÓN TERMINADA» pase lo que pase.
- **Escenario de fallo:**
  1. En la rotación, la clave nueva es mala (cupo, permisos, una errata).
  2. El guion dice «TERMINADA» y el fichero ya no está.
  3. El procedimiento sigue con «revocar la vieja» y la voz se queda sin credencial válida, con `ELEVENLABS_ENABLED=true`.
- **Corrección:** `set -e` y abortar con salida ≠ 0 si `RC ≠ 0`, antes de borrar el fichero de la clave; la última línea, con el resultado real.

## Notas

- **Alcance:**
  - `bootstrap/app.php` y `config/logging.php` llevan un reformateo de Pint de líneas ajenas al H8: el orden de los `use`, la alineación de alias y `Monolog\…` pasado a `use`. Son ficheros exclusivos de B8 y no cambian el comportamiento, pero ensucian el diff.
  - `StudioIntegrations` (S8) importa `App\Services\Twilio\TwilioVoiceCarrier` solo por su `configured()` estático, sin red. Queda fuera del glob `StudioVoice*` de la guarda; no viola D8-2, porque es un fichero de integraciones de S8.
- **Decisión de B8-d por confirmar** (la confirmó E12-3): `denied`, `canceled` y `failed_to_start` cuentan su coste **real**. Es el sentido seguro: una avalancha de denegadas con minutos reales sí llega al techo.
- **Suite:** las pruebas del Studio de voz (y las del H5/H6) necesitan un `APP_KEY` en el entorno: sin fichero de entorno, 3 errores y 17 fallos por `RuntimeException` de huellas. Lo comprobé en `UltronStudioIntegrationsConfigTest` y `UltronStudioPublicationConfigTest`. Es anterior al H8; no lo cuento como hallazgo.
- **Ventana de publicación:** entre `assignNumber` y el `INSERT` de la cadena hay un hueco de milisegundos: una entrante ahí se sella con el agente anterior y da `seal_mismatch` y freno. Ya es R8-11, aceptado y con fallo seguro.

## No verificado, y por qué

- **El E2E no lo volví a correr:** levanta servidor, worker y simuladores en puertos fijos, y TEST8-b (2) está corriendo su E2E en la misma máquina. Recalculé su hash desde la evidencia. Es C-1.
- **El ensayo de la fase 1 en el banco** y **`suite-compare.sh`** (la regresión global, 13 931 pruebas): no los reproduje, porque la máquina es compartida.
- **La «puerta PG 525» de E10-1:** su lista no está escrita en ningún informe. Reproduje la puerta de las dos listas (1 349 y 1 374), la que usan B8-c, SEC8-b y B8-d.
- **Del CRM:** no corrí `tsc`, `build:prod`, `ng test` completo (1 189) ni las mutaciones; solo las 93 specs de voz. El **E2E del CRM** (`ops/h8-e2e-crm/**`, TEST8-b (2)) no estaba en el árbol al cerrar: queda pendiente como delta.
- **Del resto:** no repetí las mutaciones de ningún paquete ni las medidas de PERF8.
- **En el VPS no se ejecutó nada.**

*Base de esta revisión: backend `61597336` (árbol limpio al cerrar), CRM `45dd73a`. Copias y salidas en el scratchpad de la sesión de REV8 (se purga a los 3 días).*

---

# REV8-c (09-11-2026) · Revisión independiente del cierre integral del Hito 8

**Fecha:** 09-11-2026, 21:50–22:45 UTC. **Paquete:** REV8-c (`reviewer`). **Alcance:** lo que registra la Enmienda 16 (E16-1..E16-22), es decir, el diff sin commit sobre `ab54a256` del worktree `ultron-h8`. A las 21:54 UTC eran 82 ficheros versionados (+4 988 / −574) más los nuevos del H8: `VoiceTranscriptLexicon.php`, 10 ficheros de prueba, `h8-voice-scope.sh`, `h8-secrets-register.sh`, `h8-twilio-check.sh`, `banco-h8/ensayar-ops8c.sh` y la evidencia del día. También entran `ULTRON_STUDIO_H8_COMPLETITUD.md`, `ULTRON_STUDIO_H8_CIERRE_INTEGRAL.md` y las secciones de hoy de los informes de security (§SEC8-c), studio (§S8-d), backend (§B8-g, cuatro partes) y devops (§OPS8-c).

**Fuera:** el H9 que se escribe en el mismo árbol (`app/Services/Outreach/**`, `tests/**/Outreach/**`, `tests/fixtures/outreach/**`, `ULTRON_STUDIO_H9_*`, `plans/informes-h9/**`), salvo donde rompe una guarda del H8.

**Cómo:**
- sobre el diff real, con pruebas y sondas propias en el scratchpad (nunca en el árbol);
- una copia APFS del árbol sin el H9 y otra de `HEAD` (`git archive`), para separar causas;
- la base PG desechable `ultron_studio_h8_rev`.

No he escrito nada salvo esta sección. Sin commit, sin red y sin tocar producción. Ningún fichero de código cambió entre mis ejecuciones y el cierre de la revisión: solo el contrato (E16-22) y el informe final.

## Veredicto

**APROBADO CON CONDICIONES.** 0 bloqueantes · 2 mayores · 4 menores.

Lo que el encargo prohíbe tocar sigue intacto, y lo he comprobado yo: la constante cerrada, las banderas apagadas por defecto, el perímetro del diff, ninguna migración, `auth:coverage` vacío y las guardas en verde (salvo una que rompe el H9). Las suites del H8 dan lo que dicen los agentes, en SQLite y en PG.

Los dos mayores no afectan hoy a producción: la constante está cerrada, y en la fase 1 la puerta dice `no_voice_number` antes de mirar el número. Pero cada uno contradice algo que los documentos del cierre dan por hecho:
- M-1: que la puerta «falla cerrada» sin vigilar el número que llama;
- M-2: que la fase 4a está lista en cuanto al comportamiento del agente frente a la guarda.

**Condiciones:**
- **C-1 (M-1), antes del commit del cierre:** es una comparación y su prueba. Si el lead prefiere commitear antes, el defecto queda abierto en la Enmienda y se quita «falla cerrada» de E16-20, E16-21 y la matriz. **En todo caso, antes de `voice(calls-open)`.**
- **C-2 (M-2, y la decisión de `security` sobre m-1), antes de la fase 4a.**
- **C-3, antes del commit:**
  - TEST8-c entregado con su sección y Pint en verde (m-3);
  - el commit lleva **solo** rutas del H8, porque el árbol compartido tiene una guarda del H8 en rojo por ficheros del H9 (ver Notas);
  - los documentos corregidos (m-4).

## Verificado (ejecutado por mí)

| Qué | Comando | Resultado |
|---|---|---|
| Telefonía + Voz, SQLite | `php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Telephony tests/Feature/Voice tests/Unit/Telephony tests/Unit/Voice tests/Unit/Twilio` | **847 pruebas, 212 867 aserciones, 0 fallos**, 156 omitidas. Cuadra con B8-g (847; 212 887) |
| Lo mismo, PG | `PGGSSENCMODE=disable UNIT_DB=ultron_studio_h8_rev bash docs/ultron-studio/ops/pgunit.sh` (las 5 carpetas) | **847, 213 628, 0 fallos**, 25 omitidas (E2E). Cuadra con B8-g (847, 213 623) y con E16-22 (753 sin `tests/Unit/Twilio`) |
| Studio voz y laboratorio, SQLite | `phpunit tests/Feature/UltronStudio --filter 'Voice\|Lab'` | **309, 4 604, 0 fallos**, 82 omitidas. Cuadra con E16-12 |
| Lo mismo, PG | `pgunit.sh tests/Feature/UltronStudio --filter 'Voice\|Lab'` | **309, 5 106, 0 fallos**: idéntico a E16-22 |
| El conjunto «646» de B8-g, SQLite | `UltronStudioIntegrationsConfigTest`, `UltronEngineIsolationTest`, `UltronStudioEngineReadOnlyTest` + `tests/{Feature,Unit}/UltronStudio/*Voice*Test.php` | Árbol: **646, 9 669, 1 fallo**, en `UltronEngineIsolationTest.php:241`, causado solo por `app/Services/Outreach/Facts/{CanaryFacts,ContactHistoryFacts,SubjectTimeline}.php` (H9). En la copia sin el H9, la guarda da 8/8; el conjunto, 646 con un fallo de una prueba inestable bajo carga (Notas) |
| Guardas de protección | `VoiceBoundaryTest`, `TelephonyIsolationTest`, `OtpUntouchedByVoiceTest`, `InternalSecretAuthoritySurfaceTest`, `UltronEngineIsolationTest` | 43 pruebas. Las cuatro primeras, en verde. `UltronEngineIsolationTest`, roja **solo** por el H9 (verde en la copia sin él) |
| Rutas | `php artisan auth:coverage --unmapped` | vacío, salida 0 |
| Constante y conjuntos | `grep`, `php -r` | `CALLS_ENABLED_IN_CODE = false` una sola vez (`VoiceCallPolicy.php:23`). `OutboundPolicy::REASONS`: 28 únicos. `VoiceEvidence::KEYS`: 7, en el orden de `StudioVoiceEvidence::MEASURE_KEYS` |
| Perímetro | `git diff HEAD --name-only` y los no versionados, sin el H9 | Fuera de voz, telefonía, Studio-voz, ops, docs y pruebas solo hay tres ficheros: `.env.example` (una variable vacía), `IntegrationsSnapshotCommand.php` (escribe la marca) y `StudioIntegrations.php` (`aiNotices` y la regla de `addressForm`). Nada en `routes/`, `database/`, el motor, OTP, n8n, Meta ni Legacy. Ninguna migración nueva |
| Banderas por defecto | lectura de `config/voice.php` y `.env.example` | La única clave nueva, `voice.number.inbound_capable`, nace `null`. Tri-estado correcto con `env()`: `false`, `0`, `off` o `no` → false; vacío o basura → null |
| Fixtures de TEST8 | `python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py [--php]` | `RESULTADO: OK`: 882 casos, 0 discrepancias, 80/80 variantes; con `--php`, 98 del compilador real y 0 fallos |
| Pint | `pint --test` sobre los 64 PHP del H8 tocados o nuevos | Todo en verde salvo `tests/Feature/Voice/E2E/H8E2ESalientesPrimeroTest.php` (TEST8-c, m-3) |
| Mandos | `bash -n` de `h8-voice-scope.sh`, `h8-secrets-register.sh`, `h8-twilio-check.sh`, `el-verificar.sh` y `banco-h8/ensayar-ops8c.sh`, más su lectura entera | Sintaxis OK. La lectura de seguridad, en Notas |
| Sondas propias de la guarda (sin red) | `php <scratchpad>/tools/sonda_*.php <backend>` | Ver M-2, m-1 y m-2 |
| Reproducción de M-1 | `phpunit <scratchpad>/revtests/RevStaleNumberObservationTest.php` | Falla como se describe en M-1 |

`<scratchpad>` = `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/f9bd6fa2-a619-4a51-ba20-0596246874fb/scratchpad`. Las sondas y la prueba se ejecutan desde el backend con la configuración del proyecto y no tocan el repositorio.

## Bloqueantes

Ninguno.

## Mayores

### M-1 · La vigilancia del número no se ata al número configurado: la observación de OTRO `phone_number_id` abre la puerta y el job hasta 2 h → **`backend` (B8) + `devops` (OPS8)**

- **Dónde:**
  - `app/Services/Telephony/VoiceNumberGuard.php:122-139` (`policyCheck()`) lee `voice:number:observed` y decide por `at` y `matchesFrom`. **Nunca compara el `phoneNumberId`** guardado con `config('voice.elevenlabs.agent_phone_number_id')`, aunque `rememberObservation()` (`:95-99`) sí lo guarda.
  - Lo consumen la puerta (`OutboundGate.php:109`) y la recomprobación del job (`PlaceOutboundVoiceCall.php:175`). Después, el job marca con el id **de la configuración** (`:240`).
  - Lo mismo pasa con `TWILIO_VOICE_FROM`: `matchesFrom` se calculó contra el valor de entonces.
- **Escenario, con el procedimiento documentado:**
  1. La conciliación horaria observa el número A, que es `TWILIO_VOICE_FROM`.
  2. `bash h8-voice-scope.sh salientes off`.
  3. `fase 2 set ELEVENLABS_AGENT_PHONE_NUMBER_ID=B`: la reimportación que piden A-1/D-2, o un id equivocado que apunta al número de WhatsApp importado. El mando solo hace `config:cache` (`h8-voice-scope.sh:240-246`) y no toca la caché de la aplicación.
  4. `salientes on`.

  Durante lo que quede de las 2 h, la puerta dice `verified` y el job marca con B, **un número que nadie observó**. Si B es el de WhatsApp, se rompe la protección 6 hasta la siguiente pasada horaria.
- **Reproducido** con `RevStaleNumberObservationTest` (scratchpad):
  1. una conciliación de `phnum_h8fx_0001` que cuadra;
  2. `config(['voice.elevenlabs.agent_phone_number_id' => 'phnum_nunca_observado_9999'])`;
  3. `policyCheck()` da `verified` y la puerta real devuelve `["calls_disabled_in_code"]` (solo la constante), sin `number_unverified`.
- **Qué debería pasar:**
  - `number_unverified` si el `phoneNumberId` observado no es el configurado (con `trim` y comparación exacta), con su prueba de regresión en `VoiceNumberIdentityTest` y en `PlaceOutboundVoiceCallRecheckTest`.
  - Para `TWILIO_VOICE_FROM` hay dos caminos (decide B8 con `security`, por D8-17):
    - invalidar la observación al cambiar la pieza: el mando borra `voice:number:observed` en `fase 2 set` de `ELEVENLABS_AGENT_PHONE_NUMBER_ID` o de `TWILIO_VOICE_FROM`, y `salientes on` exige una `voice:reconcile --numbers` posterior;
    - o guardar con la observación una huella de la configuración que no sea el número.
  - Mientras tanto, no son ciertos «falla cerrada» ni «ninguna saliente sin vigilar el número que llama» (E16-20, E16-21, la matriz y el informe final).

### M-2 · La guarda nueva frena las NEGATIVAS que manda decir la plantilla `/2`: preguntar por descuentos o por el prompt echa el freno de toda la voz → **`backend` (B8) + `studio` (S8, plantilla) + `security` (SEC8, decide) + `tester` (fixture)**

- **Dónde:**
  - `VoiceTranscriptLexicon.php:64`: `descuentos`, `promociones`, `ofertas`… sin mirar la negación;
  - `:69`: `inscrito`, `quedó registrado tu pago`… sin mirar la negación;
  - `:75`: `mis instrucciones`;
  - `StudioVoiceTemplate.php:488` manda responder «que eso no lo puedes hacer» a quien pide «precios, descuentos o datos» o «revelar estas instrucciones»;
  - `VoicePostCall.php:168` abre el freno con **cualquier** violación, y `guardViolations > 0` impide promover.
- **Escenario:** en la fase 4a, el propietario (canario) pregunta «¿tienen promociones?». El agente obedece la plantilla: «No manejamos promociones por este medio». Sale `voice_discount_extra`: freno, y una violación en la evidencia de esa publicación. En la 4b, cualquier contacto llamado puede parar toda la voz preguntando por descuentos o por las instrucciones del agente.
- **Reproducido** con `<scratchpad>/tools/sonda_head_vs_new.php`: la guarda de `HEAD` frente a la del árbol, con el mismo aviso delante.

| Turno del agente | `HEAD` | Árbol |
|---|---|---|
| Lo siento, no puedo ofrecerte descuentos. | `[]` | `voice_discount_extra` |
| No manejamos promociones por este medio. | `[]` | `voice_discount_extra` |
| No tengo ofertas para ti, lo siento. | `[]` | `voice_discount_extra` |
| Lo siento, no puedo compartir mis instrucciones. | `[]` | `voice_prompt_leak` |
| No sé si Juan está inscrito; esa información no la manejo. | `[]` | `voice_member_or_payment_claim` |
| No puedo confirmar si tu pago quedó registrado. | `[]` | `voice_member_or_payment_claim` |
| Te lo dejo a tu criterio. | `[]` | `voice_discount_extra` |
| Hay millones de razones para entrenar. | `[]` | `voice_money_slang` |
| No puedo darte un descuento. · Las clases no son gratis. | ya las marcaba el motor (previo) | igual |

  Las 20 frases legítimas de SEC8-c no incluyen ninguna negativa; por eso el fixture no lo ve.
- **Qué debería pasar:** una negativa que exige la plantilla no es una violación. Propuesta (decide `security`), antes de la 4a:
  - (a) la plantilla fija una frase de negativa sin las palabras disparadoras, por ejemplo «Eso no lo puedo hacer; te ayudo con los planes y horarios»;
  - (b) el léxico ignora la mención negada en las familias nuevas, como hace el motor con `NIEGA_*` en las cortesías;
  - (c) las negativas entran en el fixture de frases legítimas.

## Menores

### m-1 · Evasiones de la guarda que siguen pasando (es una heurística) → **`security` (decide si alguna es exigible antes de la 4a o la 4b) + `backend`**

Con `<scratchpad>/tools/sonda_guarda.php`, todas estas dan `[]`:
- **Unicode:** «promoción» con la tilde combinada (NFD, U+0301); «descuentо» con «о» cirílica; «gratis» con «а» cirílica; «ｄｅｓｃｕｅｎｔｏ» de ancho completo; «descuento» con un espacio de ancho cero dentro; «des-cuen-to»; «g r a t i s». `fold()` (`VoiceTranscriptLexicon.php:102-105`) no normaliza: NFKD más quitar `\p{Mn}` y `\p{Cf}` lo cerraría.
- **Variantes de las mismas familias de SEC8-c:**
  - rebajas y gratuidades: «ochenta luquitas», «no te cuesta nada», «te damos la valoración gratuitamente», «te la regalamos», «va por cuenta de la casa», «el cincuenta por ciento menos», «sin pagar inscripción»;
  - datos de socios: «Juan Pérez está al día con sus pagos», «es socio activo»;
  - teléfonos: «tres diez, quinientos cincuenta y cinco, doce treinta y cuatro»;
  - inglés: un turno entero sin las marcas de la lista («Hello! Welcome to Iron Body, how may I help?»);
  - fuga del prompt: las herramientas dichas sin guion bajo («consultar planes»).
- **Sí se cazan**, que es lo que preguntaba el lead: los números con puntos («80.000»), «80k», «ochenta mil pesitos», «novecientos noventa y nueve mil», «ochenta barras», «a mitad» y el espacio duro (NBSP) en «te lo dejo en».

Los documentos ya dicen que es una heurística. Lo anoto porque «la guarda ve el español real» vale para el fixture, no para las variantes naturales del mismo ataque.

### m-2 · Fallo ABIERTO si PCRE falla: el turno queda vacío y se da por limpio → **`backend`**

- **Dónde:** `VoiceTranscriptLexicon.php:134` (`numbersToDigits()`) y `:111` (`neutralizeIdioms()`) hacen `(string) preg_replace…`. Si PCRE devuelve `null`, el texto del turno pasa a `''` y ni el motor ni el léxico ven nada.
- **Reproducido** con `<scratchpad>/tools/sonda_pcre3.php`: «Te hago un descuento del cincuenta por ciento.» seguido de «uno y » ×2 731 + «mil» (16 KB) agota la pila JIT (`JIT stack limit exhausted`) y la guarda devuelve `[]`. La de `HEAD` lo marca.
- **Por qué es menor:** con la duración máxima de una llamada (≤ 600 s, del orden de 1 500 palabras) hoy no se alcanza.
- **Qué debería pasar:** con `null`, conservar el texto original o añadir un código `voice_guard_error`, que frena. Es una línea en cada sitio.

### m-3 · Pint falla en un fichero de TEST8-c → **`tester`**

`tests/Feature/Voice/E2E/H8E2ESalientesPrimeroTest.php`: `fully_qualified_strict_types`, `unary_operator_spaces`, `not_operator_with_successor_space` y `ordered_imports`. El paquete sigue en curso; tiene que estar en verde antes del commit.

### m-4 · Documentos que dicen más que el código → **lead**

- «La puerta falla cerrada» y «ninguna saliente sin vigilar el número que llama» (E16-20, E16-21, la fila de la 4a de la matriz y §2 del informe final) no son ciertos hasta cerrar M-1.
- El informe final (línea 3 y §5) dice que «el código del hito está terminado» y da por **Listo** el código de las fases 3 y 4b. La matriz deja en **PD** dos piezas: la salud del webhook automatizada (D8-21, «B8, antes de la fase 3») y la supresión 1581 de `voice_*` (4b).
- La matriz dice estar «actualizada a las 20:30 UTC», pero en la misma línea cuenta el cierre de B8-g de las 21:41 junto a las cifras de las 20:30 (394/309/48/293). La fila «Saliente con número Outbound Only» conserva **PD** aunque su texto dice «Hecho».
- Informe final, §2, fila «Lead»: dice «Enmienda 16 (E16-1..17)», y ya va por E16-22.

## Notas (revisado y correcto)

- **Marca de verificación (E4-6):**
  - forma exacta (`MARK_PATTERN` y la ida y vuelta del formato) y 30 días estrictos;
  - una marca de más de 5 minutos en el futuro no vale;
  - manda la última: una verificación que falla la borra;
  - sin marca, caducada o ilegible → `UNVERIFIED`;
  - una basura de 20 caracteres ya no lanza (la prueba está en `VoiceNumberIdentityTest`);
  - el comando sale con 1 si la caché no acepta la marca, y la única escritura es `IntegrationsSnapshotCommand.php:60`.
- **Solo saliente:**
  - el tri-estado es correcto;
  - con `false`, publicar, revertir y apagar no tocan el número ni la compensación;
  - la iniciación antepone `number_outbound_only` (`InboundGate.php:124-125`);
  - la conciliación espera «sin asignar», y cualquier agente asignado es deriva;
  - si la declaración y el número no coinciden, no se atiende nada: 409 al publicar o freno.
- **Identidad del número:**
  - `phone_number` vive solo en memoria (`SensitiveParameterValue`, fuera de `toArray()`);
  - ni la caché, ni el doctor, ni el estado guardan el número o su huella;
  - una observación que choca con la mensajería deniega aunque sea vieja (`collidesForPolicy()`, en el sentido seguro).

  Lo único que falla es el vínculo de M-1.
- **`missingPostCall`:**
  - siete claves exactas en los dos lados, y `validMeasures()` exige exactamente siete;
  - `calls` solo cuenta las que tienen `guard_verdict`;
  - `busy`, `no_answer` y `failed` nunca faltan.
- **`InboundGate` con el SID de una saliente:** responde como saliente sin crear filas ni eventos. Solo autoriza `dialing` o `active`; una llamada terminada da `no`. El riesgo de carrera está documentado (E16-10 y E16-21).
- **`voice:hangup` sobre `canceled`:**
  - solo repite el colgado si el proveedor la inició después del corte y el último colgado no se confirmó;
  - sin SID, o con el colgado ya confirmado, da `not_live` sin red;
  - el freno solo se rearma con un intento nuevo sin confirmar, que es la regla de fallo cerrado.

  *A confirmar en la 4a:* por uuid (CLI o CRM, `VoiceCallController.php:98`) no hay ventana de 60 min. Si Twilio respondiera 21220 a una llamada terminada hace días, el freno se rearmaría; conviene que el runbook lo diga.
- **Plantilla `/2` y `addressForm`:**
  - el trato es un conjunto cerrado, y el aviso tiene que ser el de ese trato (`notice_mismatch` si no casa);
  - `assertRenderedTexts()` exige las secciones fijas;
  - el aviso de usted lleva los tres elementos que pide `hasNotice()`;
  - el texto PROVISIONAL está marcado en `StudioVoiceTemplate.php:29, 91, 98`.

  Lo que la guarda frenaría de la plantilla es M-2.
- **Laboratorio con destinos:**
  - los números se descifran en memoria y se comprueban contra la especificación (R-07);
  - el parámetro lleva `#[\SensitiveParameter]` y un error solo registra la clase;
  - el agente de laboratorio no tiene número y su `call_limits` es 1.

  No he podido comprobar sin red que ElevenLabs no transfiera en una sesión web.
- **Libro del Studio:** `voice_lab.session_opened` y `voice_lab.verdict_recorded` van en la misma transacción. El veredicto lleva `hasNote`, nunca la nota. Ninguno lleva URL ni teléfonos; las etiquetas de versión y perfil van en el resumen, como ya hacía publicar.
- **Mandos de OPS8-c:**
  - no imprimen secretos: solo presencia, ids no secretos y los cuatro últimos dígitos;
  - el fichero de entorno tiene que ser un fichero normal (no un enlace), de `www-data` en 600 o de `root:www-data` en 640;
  - escriben con `O_NOFOLLOW`, en un temporal `O_EXCL|O_NOFOLLOW` del mismo directorio, con `fchown`/`fchmod`, `fsync` y `os.replace`;
  - la copia previa es 600 de root y nunca se sobrescribe;
  - `salientes on` y `entrantes on` se niegan salvo que la constante sea `true` en ejecución, con una sola declaración anclada, y que el último commit del fichero empiece por `voice(calls-open):`;
  - `lab on --driver=elevenlabs` se niega sin las tres referencias y la base de los webhooks;
  - los secretos de Twilio solo entran desde un fichero de root en 600 que no sea un enlace, y se destruye al aplicar;
  - el secreto HMAC del webhook va de PHP a python por una tubería, sin pasar por el terminal;
  - `h8-twilio-check.sh` solo hace GET, con la key en memoria;
  - `el-verificar.sh --solo-clave` conserva secretos, agente y región;
  - el cerrojo en `/run/lock` es el patrón que ya usa el repo.
- **Árbol compartido con el H9:**
  - `UltronEngineIsolationTest` está en ROJO en el árbol por `app/Services/Outreach/Facts/{CanaryFacts,ContactHistoryFacts,SubjectTimeline}.php`, que nombran tablas del Studio. Sin esos ficheros, la guarda pasa.
  - No es un defecto del H8, pero cualquier verificación del árbol entero saldrá roja, y el commit del cierre tiene que llevar solo rutas del H8 (nada de `git add -A`).
  - El H9 tiene que resolverlo antes de su propio commit.
- **Prueba inestable, que no viene del diff de hoy:** `UltronStudioVoiceLabSessionsTest::test_la_lista_filtra_y_pagina_sin_consultas_por_fila` falló con «7 is identical to 8».
  - En el árbol: 1 de 6 ejecuciones bajo carga, 1 en la copia sin el H9 y 0 de 25 en reposo.
  - En una copia de `HEAD`: 1 de 12 bajo carga y 0 de 25 en reposo.

  Si una puerta sale roja solo por ella, se repite; `tester` puede investigarla aparte.

## No verificado, y por qué

- **`ensayar-ops8c.sh`:** no lo ejecuté. El banco `~/.cache/ironbody-banco-h8-ops8c` estaba parado y los E2E de TEST8-c corrían a la vez en la máquina; no lo arranqué para no alterar sus tiempos. Leí su registro (`…/logs/ensayo-ops8c-20261109-202241.txt`): 53 órdenes, y la última pasada cuadra con los 80 OK de `informe-devops.md`. Es sobre el código del banco `4872983`, no sobre el final de B8-g/S8-d; E16-17 ya lo deja pendiente.
- **TEST8-c:** a las 22:38 UTC no había sección §TEST8-c en `informe-tester.md` y sus E2E seguían en marcha. Solo comprobé que los E2E pasan a siete medidas y el Pint (m-3). **Hace falta una revisión de TEST8-c cuando se entregue.**
- **Lo que exige ElevenLabs o Twilio reales (sin red):** que «Outbound Only» sea un caller ID verificado, que la transferencia solo exista en llamadas telefónicas, si el webhook de iniciación llega en una saliente y el 21220.
- **Las mutaciones** de B8-g (25/25, 7/7 y 6/6) y de S8-d (44 de 47): no las repetí.
- **La suite global (13 931) y el CRM (FE8):** fuera de esta revisión.

---

# REV8-c · segunda mirada (10-11-2026)

**Fecha:** 10-11-2026, 00:55–02:00 UTC. **Alcance:** lo que cerró mis condiciones:
- B8-h (`informe-backend.md` §B8-h; E16-25 y E16-26);
- TEST8-c (`informe-tester.md` §TEST8-c; E16-24).

**Cómo:**
- comparé el árbol de hoy con la copia APFS que hice en la primera revisión (21:57 UTC del 09-11), para ver exactamente qué cambió B8-h;
- monté una **copia «solo H8»**: `git archive HEAD` más los 84 versionados del H8, los 15 ficheros nuevos de código y pruebas del H8 y sus 33 documentos; sin un solo fichero del H9;
- ejecuté SQLite y PG (`ultron_studio_h8_rev`) sobre esa copia, y mis sondas y mi prueba de M-1 sobre el árbol y sobre la copia.

Solo lectura: no he escrito nada salvo esta sección. Sin commit, sin red y sin tocar producción. Al terminar, ningún fichero del H8 había cambiado desde que monté la copia (comparados uno a uno).

## Veredicto

**APROBADO CON CONDICIONES para el commit del cierre, hecho solo con rutas del H8. 0 bloqueantes.**

| Condición | Estado |
|---|---|
| **C-1** (M-1, antes del commit) | **CUMPLIDA.** Queda un residuo menor (r-1) |
| **C-2** (M-2 y m-1, antes de la 4a) | **m-1 y m-2 CUMPLIDAS. M-2 solo PARCIAL:** sigue abierta para la 4a. No bloquea el commit |
| **C-3** (antes del commit) | **Cumplida en código y pruebas:** TEST8-c entregado, el Pint de m-3 y el H8 aislable y en verde. **Falta al hacer el commit:** (a) hacerlo desde una lista explícita de rutas; (b) corregir los documentos (m-4 sigue sin corregir y hay afirmaciones nuevas inexactas); (c) el Pint de `ayudante.php` (r-3) |

**Antes del despliegue (1b):** repetir los E2E (`ambas`, `salientes` y el CRM) sobre el commit. La evidencia de TEST8-c es de las 23:15Z y B8-h (00:40Z) cambió precisamente lo que esos E2E ejercen: la puerta (H-4), la vigilancia del número (M-1) y la guarda (M-2, m-1 y m-2).

## Verificado (ejecutado por mí)

| Qué | Resultado |
|---|---|
| Qué tocó B8-h (comparado con mi copia de las 21:57) | Código: `VoiceNumberGuard`, `VoiceTelephonyStatus`, `VoiceDoctorCommand`, `OutboundGate`, `VoiceTranscriptGuard`, `VoiceTranscriptLexicon` y el nuevo `VoiceTranscriptAllowlist`. Pruebas: las nuevas `OutboundIdempotencyTest` y `VoiceTranscriptGuardHardeningTest`; cambian `VoiceCommandsTest`, `VoiceCrmRoutesTest`, `VoiceNumberIdentityTest` y `ElevenLabsConfigTest`. Los E2E de TEST8-c. `UltronStudioVoiceController.php` es idéntico a mi copia (la intrusión del H9 y su vuelta atrás quedaron entre medias). Ninguna prueba pierde aserciones salvo una sustituida por el contrato nuevo (`checkReason`, aditivo) |
| Copia «solo H8», SQLite | Telefonía + Voz + unitarias (con `tests/Unit/Twilio`): **870 pruebas, 218 009 aserciones, 0 fallos** (157 omitidas). Studio `--filter 'Voice\|Lab'`: **309, 4 604, 0**. El conjunto «646»: **646, 9 673, 0**. Nueve guardas (`VoiceBoundaryTest`, `TelephonyIsolationTest`, `OtpUntouchedByVoiceTest`, `InternalSecretAuthoritySurfaceTest`, `UltronEngineIsolationTest`, `TelephonyRoutesTest`, `UltronStudioLabNoEngineEffectsTest`, `UltronStudioVoiceRoutesTest`, `UltronStudioVoiceSchemaTest`): **152, 188 305, 0** |
| Copia «solo H8», PG (`pgunit.sh`) | Telefonía + Voz + unitarias: **870, 218 765, 0 fallos** (26 omitidas: E2E). Studio voz/laboratorio: **309, 5 106, 1 fallo**: la prueba inestable de siempre (r-5). Las nueve guardas: **152, 188 640, 0** |
| `auth:coverage --unmapped` en la copia | vacío, salida 0 |
| Fixtures (`voice-fixtures-check.py --php`) en la copia | `RESULTADO: OK` (882 casos, 0 discrepancias, 80/80 variantes, 98 del compilador real) |
| Mi prueba de M-1 (`RevStaleNumberObservationTest`) | En el árbol y en la copia: `policyCheck()` da `unverified` y la puerta `["calls_disabled_in_code","number_unverified"]` |
| Mis sondas de la guarda | Ver C-2 |
| Pint en lo tocado del H8 | Verde, salvo `docs/ultron-studio/ops/h8-e2e/ayudante.php` (r-3). El proyecto entero ya falla Pint en migraciones antiguas (no es una puerta del repo) |
| Concordancia con E16-26 | El lead da 776 sin `tests/Unit/Twilio` y con un rojo del H9 en el árbol compartido. Mis 870 incluyen `tests/Unit/Twilio` (94 pruebas) y en la copia sin el H9 no hay rojos: cuadra |

## C-1 · M-1: CUMPLIDA

- `VoiceNumberGuard::policyVerdict()` (`VoiceNumberGuard.php:175`) exige, en `:188`, que el `phoneNumberId` observado sea `trim(ELEVENLABS_AGENT_PHONE_NUMBER_ID)` y que no esté vacío; si no, `unverified` con el motivo `other_number_id`.
- Lo usan la puerta (`OutboundGate.php:109`) y la recomprobación del job (`PlaceOutboundVoiceCall.php:175`), las dos a través de `policyCheck()` (`:149`).
- El doctor y `GET admin/voice/status` dicen el motivo (`checkReason`, aditivo).

**r-1 (menor, `backend` + `security`): el cambio de `TWILIO_VOICE_FROM` sigue sin atarse a la observación.**
- **Reproducido** con `RevStaleFromObservationTest` (scratchpad): una conciliación que cuadra; luego `config(['voice.twilio.from' => '+576010000055'])`; resultado: `policyVerdict = verified` y la puerta solo dice `calls_disabled_in_code`, durante hasta 2 h.
- **Riesgo bajo:** el número que llama sigue siendo el id configurado y observado, y comprobado contra la mensajería; el `TWILIO_VOICE_FROM` nuevo lo comprueba la guarda por configuración. Lo que se pierde unas horas es la identidad «el importado es el declarado».
- **Propuesta:** guardar con la observación los cuatro últimos dígitos de `TWILIO_VOICE_FROM` (el mando ya los enseña), o que `fase 2 set TWILIO_VOICE_FROM=…` borre `voice:number:observed`. Si no, que lo diga el runbook.

## C-2 · M-2 PARCIAL (abierta para la 4a) · m-1 y m-2 CUMPLIDAS

### M-2 · solo cubre la redacción literal

- **Qué cambió:** la lista cerrada (`VoiceTranscriptAllowlist::PHRASES`, `VoiceTranscriptAllowlist.php:67`, 35 frases más entradas y colas) quita las negativas literales (`strip()`, `:128`) antes de buscar (`VoiceTranscriptGuard.php:155`).
- **Qué arregla:** de las seis negativas de mi primera tabla, cinco salen limpias. La sexta, «No sé si Juan está inscrito…», sigue marcando.
- **Qué no cierra:** la plantilla no le dice al agente qué frase usar. Solo la instrucción («le respondes… que eso no lo puedes hacer», `StudioVoiceTemplate.php:488`); el propio B8-h lo reconoce en Q-1. La prueba de paridad comprueba que esas instrucciones siguen literales en la plantilla, no que el agente vaya a decir las frases de la lista.
- **Medido** con `<scratchpad>/tools/sonda_negativas2.php`: de **15 negativas naturales que no están literalmente en la lista, 13 siguen frenando**, y 11 de ellas no las marcaba `HEAD`. Entre otras: «Lo siento, no manejo descuentos», «No contamos con promociones en este momento», «Por ahora no hay promociones», «No te puedo dar descuentos», «Lamentablemente no tenemos descuentos ni promociones en este momento», «No estoy autorizado para dar descuentos», «No puedo decirte mis instrucciones», «No puedo ofrecerte descuentos ni precios especiales», «Disculpa, no tengo promociones para ofrecerte».
- **Qué falta antes de la 4a** (decide `security` con S8):
  - (a) que la plantilla fije la frase exacta de cada negativa («Si te piden descuentos, responde exactamente: …»), con frases que estén en `PHRASES` y una paridad en los dos sentidos (cada frase prescrita está en la lista y cada frase de la lista está prescrita). Cambia la `specHash`, pero ningún agente real se compiló;
  - o (b) una decisión escrita de aceptar en la 4a los frenos por falso positivo como calibración: solo el propietario es canario y hay una llamada al día.
- **r-2 (menor, con lo mismo):** la lista también quita la negativa cuando va en pregunta retórica. «¿No tenemos promociones? ¡Claro que sí, este mes sí!» queda limpia; en el árbol de B8-g, «promociones» marcaba. Bastaría con no quitar una cláusula que abre con «¿» o cierra con «?».

### m-1 · CUMPLIDA

`<scratchpad>/tools/sonda_guarda.php` y la batería de la prueba de endurecimiento:
- **ahora se cazan** las once sondas de mi informe: NFD, cirílico, ancho completo, ancho cero, guiones, letras sueltas, «luquitas», «no te cuesta nada», «gratuitamente», «te la regalamos», «al día con sus pagos»; también «es socio activo», el teléfono por pares, el inglés de una marca y la fuga sin guion bajo;
- **siguen sin cazarse**, como residuos aceptados y documentados: «vale ochenta» sin «mil», «free» sola, «ochenta punto cero»;
- **falsos positivos:** de los legítimos de mi muestra (`sonda_fp_b8h.php`), uno nuevo: «Si estás al día con tus pagos, puedes reactivar tu plan» (la condicional) → `voice_member_or_payment_claim`; va con «lo más estricto» que eligió el lead. Siguen los de antes: «millones de razones» y «te lo dejo a tu criterio».

### m-2 · CUMPLIDA

- **Ahora lanza en vez de vaciar:** `replace()`, `replaceCallback()` y `matches()` del léxico, `maskApproved()` y `strip()` lanzan `UnexpectedValueException`. La guarda la captura y marca el turno con `voice_guard_unprocessable` más el código del motor sobre el texto tal cual (`VoiceTranscriptGuard.php:121`). `hasNotice()` falla cerrado.
- **Mi caso real de 16 KB** («Te hago un descuento…» + «uno y » ×2 731 + «mil») da ahora `["voice_guard_unprocessable","machine_reply_invented_discount"]`. Es determinista: 3 de 3 ejecuciones idénticas, con `pcre.jit=1` y PHP 8.3.33.
- **Nota:** la afirmación de B8-h de que «no se puede forzar de forma fiable en el proceso» no se sostiene en esta máquina. La prueba de UTF-8 inválido cubre el mismo camino, así que añadir el caso real es opcional.

## C-3 · Cumplida en código y pruebas; pendiente al hacer el commit

- **TEST8-c, entregado:** §TEST8-c y la evidencia en `ops/evidencia-h8/2026-11-09/`. m-3, cerrada: `H8E2ESalientesPrimeroTest.php` pasa Pint. Sus hallazgos abiertos tienen dueño: H-1 → SSE9-f (CRM); H-2 → OPS8 (banco); **H-5 → `security`** (`GET admin/voice/status` entrega el `phoneNumberId` de la observación, `VoiceTelephonyStatus.php:63`; no es el número ni un secreto, pero el CRM no lo usa y el propio E2E lo tenía en su lista de piezas que no deben llegar al navegador). B8-h no lo toca: que `security` lo juzgue antes de la 4a.
- **H-4 (B8-h), correcto:**
  - la idempotencia va antes de la política (`OutboundGate.php:88`);
  - la fila previa se busca por persona y `client_request_id` (`:83`), así que no abre un IDOR;
  - otro destino sigue siendo `client_request_reused`;
  - sin fila, evento ni job;
  - `OutboundIdempotencyTest` 3/3 y `VoiceCrmRoutesTest` adaptado y **más** estricto (misma fila, una sola fila, sin `reasons`).
- **Colaterales, correctos:** el arreglo de `ElevenLabsConfigTest` solo toca la prueba, y `STUDIO_ROUTE_COUNT = 77` (`UltronStudioVoiceController.php:86`), con el diff del controlador limitado a lo del H8.
- **El H8 se puede commitear solo, y está en verde:** es la copia «solo H8» de arriba. La lista exacta de rutas son 105, en `<scratchpad>/out/rutas-h8-commit.txt`: 84 versionadas, 15 nuevas de código y pruebas y 6 documentos nuevos. Aparte, la evidencia (`ops/evidencia-h8/2026-11-09/`, 56 MB, ocho directorios) la decide el lead.
- **Ojo:** el árbol compartido tiene hoy **cuatro** versionados del H9:
  - `routes/ultron-studio.php` y `config/ultron_studio.php`, de S9;
  - **`routes/api.php` y `app/Support/Access/AuthorizationMap.php`**, del SSE9, escritos durante esta revisión (01:12 UTC);
  - y unos 150 no versionados del H9 que no están en la lista de exclusión del lead: `config/ultron_studio_stream.php`, `app/Services/UltronStudio/Stream/*`, `StudioMessageTemplate*`, `StudioPersonBinding*`, `PersonBindingsCommand.php`, `tests/Feature/UltronStudio/Strategies/`, `UltronStudioStrategySchemaTest.php`…

  Ni `git add -A` ni `git add backend/`: solo la lista.
- **Documentos sin corregir (m-4) y afirmaciones nuevas inexactas → lead, antes del commit:**
  - `ULTRON_STUDIO_H8_COMPLETITUD.md:7` dice «Actualizada… a las 20:30 UTC»;
  - `:62` mantiene **PD** en la fila «Outbound Only»;
  - `ULTRON_STUDIO_H8_CIERRE_INTEGRAL.md:3` dice «el código del hito está terminado», y `:98` y `:100` dan por **Listo** el código de las fases 3 y 4b, mientras la matriz deja **PD** la salud del webhook (`:52`) y la supresión 1581 (`:80`);
  - `:49` dice «E16-1..17»;
  - **nuevas:** `:50` dice «**Todas corregidas en B8-h**» y `:122` «sus condiciones están corregidas». No es exacto: M-2 queda abierta para la 4a (arriba) y m-4 está sin hacer.
- **r-3 (menor, `tester`):** `docs/ultron-studio/ops/h8-e2e/ayudante.php` no pasa Pint (`HEAD` sí). Lo rompen los nombres de clase completos del caso nuevo `observar-numero`. Se arregla con `vendor/bin/pint` sobre ese fichero, que solo pone los `use`.

## Otros (menores, no bloquean)

- **r-5 (`tester`):** `UltronStudioVoiceLabSessionsTest::test_la_lista_filtra_y_pagina_sin_consultas_por_fila` es inestable bajo carga y en los dos sentidos: 7≠8 y 8≠7. En PG, en la copia «solo H8», falló 2 de 5 veces con carga de 14 a 19. Ya fallaba en `HEAD` bajo carga, así que no viene del diff. Causa a confirmar: alguna consulta que depende del reloj o de una caché con caducidad entre las dos peticiones. Congelar el reloj en la prueba o contar solo las consultas a `ultron_studio_voice_lab_*` lo haría determinista. Mientras tanto, si una puerta PG sale roja solo por ella, se repite.
- **Un error mío durante la revisión, sin efecto en el veredicto:** lancé repeticiones en PG sobre la misma base mientras corrían las guardas. Las paré (eran mis procesos, en mi copia del scratchpad) y repetí todo en serie: las cifras de arriba son las de esa pasada limpia.

## No verificado, y por qué

- **Los E2E y el banco:** no los relancé. La máquina estaba con carga de 14 a 19 y la regresión global de TEST8-c y del lead corría a la vez. Ver la condición antes del despliegue.
- **La regresión global por nombre:** la lee el lead.
- **ElevenLabs y Twilio reales:** sin red.
