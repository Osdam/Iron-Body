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
