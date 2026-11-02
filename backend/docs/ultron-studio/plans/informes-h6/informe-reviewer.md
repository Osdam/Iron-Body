# ULTRON STUDIO · Hito 6 · Revisión final del `reviewer`

**Fecha:** 01-11-2026 (Bogotá). **Autor:** `reviewer`. **Qué se revisó:** backend `feat/ultron-studio-h6` en `2ac7f54` (diff `origin/main...HEAD`, base `2660173`; el código es el de `4e2e8cf`: de ahí a HEAD solo hay documentación, comprobado) y CRM `feat/ultron-studio-h6` en `8536c0e` (base `ab78a6a`). Solo lectura: este fichero es lo único que he escrito, sin commit; nada del VPS; no he tocado `e2e/ultron-studio/**` ni `informe-tester-e2e.md` (los dos árboles tienen cambios sin confirmar del tester E2E en esos ficheros, y solo en esos). Los informes de los demás agentes los he tratado como afirmaciones: lo que doy por verificado lo he ejecutado o leído en el código.

## Veredicto

**APROBADO CON OBSERVACIONES.** Ningún bloqueante ni mayor en el código, las migraciones ni los scripts de la fase 1. Seis menores (m-1…m-6); ninguno cambia lo que la fase 1 deja en producción.

**Condición del veredicto (no es un hallazgo del código: es la puerta del contrato, «Puerta antes de cada fase»).** Esta revisión no abre la fase 1 por sí sola. Para el commit que se desplegaría quedan abiertos, y ya figuran como [PENDIENTE] en los documentos:
1. **E2E `h6-integrations` + regresión del H5 con la valla vacía.** La última pasada medida es la de desarrollo, 243/249 sobre CRM `f38699d` (informe parcial confirmado en `2ac7f54`); el CRM `8536c0e` (el arreglo de H-1) no ha pasado aún por el E2E; E13 (CRM del H6 contra el backend del H5) y las mutaciones, sin ejecutar. Dueño: tester.
2. **Build de producción del CRM sobre el commit que se despliega** (el último es de `f38699d`; `ULTRON_STUDIO_DEPLOYMENT_PLAN.md:831`). Dueño: frontend / lead, paso 7 del runbook.
3. **Línea base por nombre contra `origin/main`** (`ULTRON_STUDIO_FINAL_REPORT.md:577`). Dueño: tester / lead.

Antes del push: que el tester confirme o descarte sus cambios en `e2e/ultron-studio/**` y en su informe (el build del CRM exige `dirty false`). Hoy los dos push serían avance rápido: `origin/main` es la base de cada rama.

## Bloqueantes

Ninguno.

## Mayores

Ninguno.

## Menores

**m-1 · frontend · `Iron-Body_Front/src/app/modules/ultron-studio/studio-parse.ts:336-340`.** Queda una comparación en unidades UTF-16 contra topes que el servidor cuenta en puntos de código (lo que se pidió comprobar tras `8536c0e`). `valorAdmitido()` acepta `text` ≤ 160, `textarea` ≤ 600 y etiquetas ≤ 60 con `v.length`; el servidor los cuenta con `mb_strlen` (`backend/app/Services/UltronStudio/StudioDraftNormalizer.php:250` y `:307`). Si no admite el valor, `valorDelParametro()` (`:1059-1060`) y los lectores de `:957` y `:1044` lanzan `fallo()` y la respuesta entera queda ilegible.
- **Escenario:** un borrador o una versión con un parámetro `text` de 160 caracteres que incluyan un emoji (161 unidades), que el servidor aceptó → el CRM no puede leer ese borrador o esa versión.
- **Alcance:** hoy no lo produce el propio CRM, porque `studio-store.ts:489` y `:502` (`valorSeguro`) recortan en UTF-16 antes de enviar y `studio-param-row.ts:97,102` limita con `maxlength` (UTF-16); solo lo produce contenido escrito por otra vía. Código anterior al hito (`038b2f5`/`227e9d2`); no afecta a la fase 1. [A confirmar: `slice(0, 60)` y `slice(0, 160)` pueden partir un par sustituto y dejar uno suelto en el cuerpo que se envía.]
- **Corrección:** contar puntos de código en `valorAdmitido` y en `valorSeguro`, sin partir pares, con una spec. Puede ir al Hito 7.
- Las validaciones de formulario `studio-rules-panel.ts:311` y `ultron-studio-page.ts:782` también cuentan en UTF-16, pero son coherentes con su `maxlength` y solo son más estrictas que el servidor: no rompen lecturas.

**m-2 · frontend · `8536c0e` sin prueba de regresión rápida.** El commit solo toca `studio-parse.ts` (`git show --stat 8536c0e`). No encuentro ninguna spec del módulo que lea un texto de 255 puntos de código con caracteres fuera del plano básico (búsqueda por emoji, `codePoint` y «puntos de código»: solo salen el motivo, `studio-integrations-parse.spec.ts:563`, y las pruebas de `codePointLength` en `studio-rules-coherence.spec.ts`). La única regresión es la E2 del E2E (`e2e/ultron-studio/specs/h6-integrations.cjs`, semilla `specs/h6-seed.php:108`), de unos 1000 s y fuera de la suite del módulo.
- **Escenario:** alguien devuelve `texto()` a `v.length` y las 1075 specs del módulo siguen en verde. [A confirmar con la mutación: no la he hecho para no tocar el árbol del CRM, donde está trabajando el tester.]
- **Corrección:** el caso que proponía el propio H-1, en `studio-integrations-parse.spec.ts`: 255 puntos de código (263 unidades) se acepta; 256, no.

**m-3 · frontend · `studio-integrations-text.ts:225`** (O-1 del tester E2E; sigue en HEAD). `discardedText()` escribe «1 de los campañas…», «1 de los llamadas…» (concordancia). Lo llaman `studio-meta-campaigns.ts:314`, `studio-twilio-panel.ts:297` y `studio-canary-numbers.ts:284`. Texto visible para el personal, sin efecto funcional. **Corrección:** pasar el artículo con el sustantivo.

**m-4 · frontend (y DOC) · `studio-meta-campaigns.ts:63` y `studio-integrations-text.ts:607`** (O-4 del tester E2E; sigue en HEAD). El rótulo «Última sincronización correcta» enseña el éxito **más antiguo** entre las cuentas: es la semántica de `MetaAdsSync::statusAll()` que copia `StudioMetaCampaigns::connectionFrom()`.
- **Escenario:** con una cuenta atrasada y dos al día, el CRM dice «hace 6 h» (363 min en la semilla del E2E) y quien lo lee concluye que toda la sincronización de Meta está parada.
- **Corrección:** «Cuenta más atrasada: …» o el dato por cuenta. El contrato no lo fija.

**m-5 · DOC · estados desfasados en los documentos maestros.**
- (a) El CRM del H6 aparece como `f38699d` en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md:778`, `ULTRON_STUDIO_FINAL_REPORT.md:514` (y `:561`), `ULTRON_STUDIO_MASTER_ROADMAP.md:12`, `ULTRON_STUDIO_ROLLBACK.md:170` y `ULTRON_STUDIO_SECURITY_REPORT.md:367`. La rama está en `8536c0e` (`3bac1e2`, fixture reexportado; `8536c0e`, arreglo de H-1), que es lo que se construiría; `DEPLOYMENT_PLAN.md:831` ya lo dice.
- (b) El E2E figura como «esqueleto que falla a propósito» en `DEPLOYMENT_PLAN.md:829` y `FINAL_REPORT.md:610`; lo sustituyen los 15 escenarios (`9973b5b`) y su informe parcial (`2ac7f54`).
- (c) `SECURITY_REPORT.md:435` dice «[PENDIENTE: la segunda pasada de `security` sobre la página…]», y `:396` (y `FINAL_REPORT.md:598`) la dan por hecha en `8c3eb7e`.
- **Escenario:** quien sigue el runbook anota un commit del CRM que no es el que va a construir, o cree que el E2E no ha empezado. **Corrección:** actualizarlos al cerrar el E2E.

**m-6 · devops · `docs/ultron-studio/ops/deploy-h6-backend-fase1.sh:136-140`.** En una reanudación, el paso 0 acepta un `api-commit.txt` que empiece por el commit nuevo (`"$ESPERADO"*` con `REANUDA=1`); el del H5 solo aceptaba `PREVIO` (`deploy-h5-backend-fase1.sh:58`). La vuelta atrás del código lee ese fichero (`ULTRON_STUDIO_ROLLBACK.md:208`).
- **Escenario:** la fase 1 cae tras el `merge` (por ejemplo, tres 55P03), se relanza pasadas 2 h, se rehace el respaldo con el bloque del paso 1 tal cual (`DEPLOYMENT_PLAN.md:846`: `rev-parse HEAD`, que ya es el commit nuevo) → el script lo acepta → una vuelta atrás de nivel 3 haría `checkout` del propio commit del H6, sin volver a nada. Los documentos lo avisan (`DEPLOYMENT_PLAN.md:949`, `ROLLBACK.md:218`), pero solo en texto y en el camino de una incidencia.
- **Corrección:** exigir siempre `PREVIO`, como el H5, y que el mensaje de la reanudación diga cómo escribirlo (`git rev-parse <PREVIO> > "$BK/api-commit.txt"`). No cambia una fase 1 que salga bien.

## Verificado (comando → resultado)

| # | Qué | Comando (desde cada worktree) | Resultado |
|---|---|---|---|
| 1 | Puerta de diff del contrato | `git diff origin/main...HEAD --stat -- app/Services/Marketing app/Jobs app/Http/Controllers/Api app/Services/Sms app/Services/Otp routes/api.php routes/marketing.php routes/console.php config/marketing.php config/meta.php config/otp.php app/Services/UltronStudio/StudioCatalog.php`, y lo mismo con `git diff 7ba0ce8 HEAD` | Vacía las dos. `7ba0ce8` es ancestro de HEAD y `7ba0ce8..origin/main` solo toca `backend/docs/`. `composer.*` y `package*.json` sin cambios. `StudioCatalog::VERSION = '2026-10-19.1'` |
| 2 | El código no cambió tras la puerta del lead | `git diff --stat 4e2e8cf HEAD -- . ':(exclude)backend/docs'` | Vacío |
| 3 | Rutas | `php artisan route:list --path=admin/ultron-studio --json` (SQLite en memoria) | 56; 18 de integraciones (8 GET, 10 POST); cupos `ultron-studio-read`, `-write` y `-probe` |
| 4 | Cobertura | `php artisan auth:coverage --unmapped` | Sin salida, código 0 |
| 5 | Suites acotadas en SQLite | `phpunit -c <xml propio>`: `tests/Unit/{Voice,Twilio}`, `tests/Feature/Voice`, `StudioIntegrationsParityTest`, `ExportStudioFixturesCommandTest` y las 20 de `tests/Feature/UltronStudio/` de integraciones, aislamiento, solo lectura, permisos, laboratorio y esquema | **OK: 1156 pruebas, 21 216 aserciones, 49 omitidas** (las de solo PostgreSQL), 1:57 |
| 6 | Las mismas en PostgreSQL 14.24 | `UNIT_DB=ultron_studio_h6_rev docs/ultron-studio/ops/pgunit.sh -c <xml> --display-skipped` (127.0.0.1:55432, usuario `ironbody`) | **OK: 1156 pruebas, 22 772 aserciones, 0 omitidas**: corrieron las carreras y las guardas. 19:07 con la máquina cargada. La base, borrada al terminar |
| 7 | Oráculo | `python3 -I tests/fixtures/ultron-studio/tools/integrations-cases-check.py --php --tw --impl` | `RESULTADO: OK`. Estructura OK; 722 casos en 22 secciones; 704 rehechos, 0 discrepancias; 116 variantes equivocadas cazadas; PHP, 0 fallos; TW, 17 casos y 0 discrepancias; clases reales: `twilioProbeSanitize` 28/28, `elevenlabsSignature` 52/52, `elevenlabsEnvelope` 13/13 |
| 8 | Copia del fixture en el CRM | `php artisan ultron-studio:export-fixtures --path=<scratchpad>` y `shasum -a 256` frente a `Iron-Body_Front/src/app/modules/ultron-studio/testing/` | Los 6 ficheros idénticos byte a byte; `backend-integrations.testing.ts` = `564cef3c09933804…` en los dos. La exportación usa una clave sintética fija: es determinista |
| 9 | Catálogo de permisos | `php artisan permissions:export --path=<scratchpad>` frente a `src/app/models/backend-permission-catalog.ts` | 105 permisos, idéntico (`3740edf4ec925724…`) |
| 10 | Banderas en el servidor | `tinker` con `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED` y `ULTRON_STUDIO_TWILIO_PROBE_ENABLED` = ausente, `false`, `0`, `off`, `no`, vacío, `garbage`, `TRUE`, `1`, `on`, `yes` | Solo `TRUE`, `1`, `on` y `yes` encienden; lo demás deja cerradas escrituras y sonda |
| 11 | Voz | `php artisan voice:doctor --json` y `--verify`, con los defectos | `provider: none`, `ELEVENLABS_NOT_CONFIGURED`, faltan las tres piezas, región `default`, código 0. `--verify`: código 1 y «No se ha hecho ninguna petición» |
| 12 | Red en el código del hito | búsqueda de `Http::`, Guzzle, `curl_`, `file_get_contents(`, `->post(`, `dispatch`, `Queue::`, `Mail::`… en las líneas añadidas de `backend/{app,config,routes,database}` | Dos `Http::`, los dos GET: `TwilioReadOnlyClient.php:145` y `ElevenLabsVoiceProvider.php:82` (este, solo desde los comandos de consola). Ningún encolado ni correo |
| 13 | Scripts | `bash -n` (fase 1, alcance y lanzador del smoke); `python3 -m py_compile` (smoke y detector); `python3 -I test_find_leaks_h6.py smoke-h6.py` | Sintaxis OK; detector de fugas **54/54** |
| 14 | Función de F | cuerpo de `ultron_studio_forbid()` en `2026_10_18_100002` frente a `2026_11_01_100002` | Idéntico byte a byte |
| 15 | Pint | `vendor/bin/pint --test` sobre los 84 PHP añadidos o cambiados | Solo `AppServiceProvider.php`, que falla igual en `origin/main` con los mismos 4 fixers: previo (I7.1 dice que no se toca) |
| 16 | Fichero de entorno de ejemplo del backend | leído con Read y `git diff --numstat` | +62/−0. Las variables de I12 e I6.2 con su defecto seguro (líneas 358–404); Meta Ads (525–531) y los alias de Twilio (715–721), comentados y sin valor; la línea de Factus, intacta |
| 17 | CRM, specs del módulo | `node_modules/.bin/ng test --watch=false --include='src/app/modules/ultron-studio/**/*.spec.ts'` | **69/69 ficheros, 1075/1075**. Los avisos NG8107 son de ficheros fuera del módulo |
| 18 | CRM, tipos | `node_modules/.bin/tsc -p tsconfig.app.json --noEmit` | Código 0, sin salida (11 min de reloj por la carga de la máquina) |
| 19 | CRM, literales | `node scripts/check-literals.mjs` | «350 ficheros revisados · literales correctos» |
| 20 | CRM, estático | búsquedas en `src/app/modules/ultron-studio/` | Sin `innerHTML`, `bypassSecurityTrust` ni `insertAdjacentHTML` en el módulo; sin almacenamiento del navegador, `console.` ni parámetros de URL en los 17 ficheros de la página; los 7 textos obligatorios de I10, literales; las 10 hojas nuevas por debajo de 8 kB (la mayor, 4826 B); `ultron-studio.scss` y `studio-inspector.scss`, sin tocar |

## Revisado en el código y correcto (por la prioridad del encargo)

**1 · Seguridad de la fase 1.**
- **Escrituras 47–54 cerradas en el servidor, no solo en el CRM.** `StudioIntegrations::writesEnabled()` (`StudioIntegrations.php:82-85`, `=== true` sobre `filter_var`) se comprueba en el servicio después de la llave y antes de leer el recurso, en las ocho: `StudioCampaignLinks.php:179-182` y `:326-329`; `StudioCanaryNumbers.php:152-155` y `gate()` en `:613-616`. Lo prueban `UltronStudioIntegrationsPermissionsTest::test_el_super_admin_entra_en_todas` y `::test_el_administrador_lee_y_escribe_salvo_autorizar_reconocer_y_la_sonda` (409 con los defectos), en verde en mis dos corridas. Reconocer (55) no depende de la bandera, como fija la decisión 11.
- **Sonda cerrada y de solo GET.** `StudioTwilioProbe.php:74-77`. El cliente usa solo GET con `withoutRedirecting()`, los hosts oficiales en `production` aunque haya base (`TwilioReadOnlyClient.php:186-188`), la primera página y el SID validado antes de la ruta (`:111`); la credencial va en `SensitiveParameterValue`. El saneador copia por lista blanca y `last4` sale solo de un E.164 de 8 a 15 cifras (`TwilioProbeSanitizer.php:64`). La prueba de solo lectura del motor exige exactamente los 5 GET a los hosts oficiales (`UltronStudioEngineReadOnlyTest.php:397-399`), en lugar del `assertNothingSent()` del H5.
- **Voz.** `VoiceCallPolicy.php:23` (`CALLS_ENABLED_IN_CODE = false`). `VoiceProviders::current()` (`VoiceProviders.php:26`) devuelve el proveedor nulo salvo bandera y piezas completas, sin enlace en el contenedor (el `AppServiceProvider` solo gana el limitador). `verifyCredentials()` solo lo llaman `VoiceDoctorCommand.php:50` e `IntegrationsSnapshotCommand.php:49`.
- **Meta, solo lectura.** El Studio solo llama a `configurationProblem`, `accountIds` y `statusAll` (`StudioMetaCampaigns.php`; `StudioIntegrationConfig.php:139`), y `MetaAdsSync::status()` es de lectura (`MetaAdsSync.php:312` y siguientes). Del OTP, solo `OtpCostGuard::snapshot()` (un `Cache::get`) y `TwilioVerifyService::isActive()` (configuración).
- **Motor y Legacy intactos.** Puerta de diff vacía; nadie lee el registro de canarios; la coherencia lee el id de conversación del entorno y dos columnas, y hace `unset` del teléfono (`StudioCanaryCoherence.php`). El canario sigue siendo el del entorno.

**2 · Autorización.**
- Una entrada por ruta en `AuthorizationMap.php:767-784`. `CrmPermission.php:285-286` saca `ultron.canary_authorize` y `ultron.integrations_manage` del Administrador; el dominio `ultron` sigue fuera de la credencial compartida de automatización.
- Cuatro ojos por identidad en el servicio (`StudioCanaryNumbers.php:630-642`, `StudioAccess::isCanaryRegistrant()`) y en la base (`2026_11_01_100002_add_ultron_studio_integration_guards.php:231-232`).
- Orden llave → forma (Enmienda 4): la llave la exige `EnforceAdminAuthorization` antes del controlador; el controlador valida y el servicio vuelve a exigirla (`$access->require(…)`).

**3 · Privacidad del teléfono.**
- Huella (`PhoneFingerprint.php`, subclave con etiqueta propia, E.164 estricto con `D`), 4 dígitos y, solo en voz, el cifrado, que revocar borra en la misma sentencia (`StudioCanaryNumbers.php:348-357`; `uscn_revoke_chk`).
- `#[\SensitiveParameter]` en `PhoneFingerprint::of/withKey`, `StudioCanaryPhone::parse/message`, `StudioCanaryNumbers::register/registerGated`, `StudioCanaryCoherence::normalize`, el constructor del cliente de Twilio y `StudioTwilioProbe::probeLocked/steps`.
- Un fallo de la base en el alta se relanza solo con la clase y el SQLSTATE (`StudioCanaryNumbers.php:238-246`, N-1); la coherencia ilegible registra solo la clase.
- El libro no guarda IP ni agente de usuario (`StudioAudit.php`, `ip_address` y `user_agent` a null) y sus `diff` siguen I8. El modelo oculta `phone_encrypted` y `phone_hash` al serializar (`StudioCanaryNumber.php:99`) y prohíbe `save()` y `delete()` (`:125-126`). La puerta 403 registra ruta, admin e IP, nunca el cuerpo (`EnforceAdminAuthorization.php:110-116`); ninguna capa registra cuerpos de petición (`bootstrap/app.php`).
- Exportaciones: la de fixtures usa datos y clave sintéticos; ninguna de las 56 rutas exporta el libro.
- CRM: el teléfono solo viaja en el cuerpo de la 49 y vive en memoria, en la firma de la clave idempotente, hasta una respuesta definitiva (`studio-integrations-session.ts:465-479`); no se pinta ni va a la URL, al almacenamiento o a la consola.

**4 · Migraciones `2026_11_01_100001` y `100002`.**
- Aditivas: 3 tablas nuevas; el libro solo cambia su CHECK de sujetos en PG, en una sentencia DROP + ADD. Transacción en PG y transacción propia en SQLite; `up()` exige cada columna clasificada; FKs RESTRICT que solo referencian `ultron_studio_versions`.
- 13 disparadores (10 de fila en los dos motores y 3 de TRUNCATE en PG) y 22 CHECK: 43/49 en PG y 30 disparadores en SQLite, como fijan `UltronStudioPublicationSchemaTest.php:170-182`.
- `down()` toma los cerrojos antes de contar y se niega con filas o eventos del H6.
- `ops/h6-schema-check.sql` y la compuerta del script (`5 5 3 4 12 6 2 true iron,iron,iron 0 0 0 0`, `deploy-h6-backend-fase1.sh:262`) cuadran con las migraciones.

**5 · Scripts de operación.**
- `deploy-h6-backend-fase1.sh`: `PREVIO=7ba0ce8` (`:37`, el HEAD de producción que da el lead); git como root con `umask 022` (`:24`); lectura por `www-data` de cada fichero del merge (`:223`), que cubre también los directorios nuevos (`test -r` exige atravesarlos); respaldo de menos de 2 h, en 700/600, con su `.sha256` y al menos 223 tablas (`:141-147`); el fichero de entorno parsea antes de migrar (`:237`); migraciones solo con `sudo /usr/local/bin/ironbody-migrate`, `lock_timeout` 5 s y hasta 3 intentos (`:245-258`); la compuerta del esquema antes de `route:cache` (`:250`, `:262`, `:273`); cachés de `www-data` en 600 (`:276`); 13/0 workers antes y dos veces después; tinker filtrado por patrón, sin imprimir valores; la primera instantánea, solo con 0 desvíos. Escribe las claves con el mismo formato que los scripts del H5 que ya corrieron en producción (`printf '  %s=%s'`).
- `h6-integrations-scope.sh`: exige el modo 600 (`:110`); `probe on` exige que el entorno efectivo sea `production` (`:123-126`); copia previa en 600 y la vuelta exacta impresa; `APLICADO` solo sin desvíos.
- Smoke: no puede escribir. Usa recursos inventados; en la fase 1, el registro válido solo se envía con la bandera vista apagada y con una versión esperada inventada, que da 404 antes del INSERT (`StudioCanaryNumbers.php:190`); en la fase 2, cuerpos que dan 422; el Super Admin no llama nunca a la 55; y el lanzador compara el recuento exacto de las 14 tablas antes y después.
- `ULTRON_STUDIO_ROLLBACK.md`, «Hito 6»: los niveles 1–4 del contrato, nunca `down()` en producción y la vuelta del código solo si producción sigue en el commit de la fase 1 (con la salvedad de m-6).

**7 · CRM, página «Integraciones».** Los botones salen solo de `can`, `flags` y `actions` (por ejemplo `studio-canary-numbers.ts:159`, `registerBlock()` y `acknowledgeBlock()`); la deriva se pinta clave a clave (`studio-config-drift.ts:65-69`); los diálogos usan el `ib-dialog` compartido (`role="dialog"`, `aria-modal`, Tab no sale del diálogo y el foco vuelve a quien lo abrió); `clientRequestId` estable por acción y cuerpo en 47, 49, 55 y 56, y las transiciones sin clave, como fija el contrato; el lector del H6 cuenta puntos de código (`codePointLength`) y su `texto()` es el corregido en `8536c0e` (`studio-parse.ts:257-266`); la ruta `marketing/ultron-studio/integraciones`, con `AuthGuard` y `ultron.view`, va tras la bandera de vista previa (`app.routes.ts`).

## No verificado (y por qué)

- **Producción y el servidor** (fuera de alcance): el `--solo-comprobar`, GNU `stat`/`grep`, `sudo` real, el envoltorio `ironbody-migrate`, los dueños reales y FPM. No he ejecutado el banco `ops/banco-h6/` ni `deploy-crm.sh`.
- **Pendientes de devops antes de la fase 2:** `opcache.validate_timestamps` del pool de FPM (N-4 a) y READ COMMITTED en el rol de la aplicación (O-2 del tester).
- **E2E, regresión del H5 y build de producción del CRM:** los lleva el tester y el paso 7 (ver «Condición del veredicto»).
- **La página en un navegador:** 390 px y el foco atrapado los he leído en el código, no los he ejecutado en Chrome. La O-2 del tester (la pestaña activa queda fuera de la vista a 390 px) no la he comprobado.
- **Suites completas del repositorio:** no las he lanzado, por indicación del lead. Las acotadas cubren todo lo que toca el hito y la puerta completa del lead (`FINAL_REPORT.md:566`) es sobre el mismo código. No pido otra; sí la línea base por nombre, que es de la puerta (condición 3).
- **H-3 de DevOps** («el cupo de la sonda corre antes de autenticar»): no lo he medido. El limitador identifica a la persona en los dos casos (`AppServiceProvider.php:627-635`), y la afirmación solo afecta a cuántos smokes caben por hora, en el sentido prudente.
- **Conocidos y diferidos al Hito 7** (no los reabro): H-2 del tester (la huella en el DETAIL del 23505 del log del PostgreSQL de producción), AS-3 (oráculo de pertenencia de un número), la supresión (B-1), T-1 del motor e `ironbody-migrate` como root.

## Discrepancias entre lo afirmado y lo observado

1. **Encargo del lead:** el comprobador está en `backend/tests/fixtures/ultron-studio/tools/integrations-cases-check.py`, no en `backend/tools/`. Los documentos lo citan bien (Enmienda 2).
2. **Encargo del lead:** «`probe on` se niega en producción» es al revés: se niega **fuera** de `production` (AS-5), `h6-integrations-scope.sh:123-126`. `ops/README.md:32` lo dice bien y el código es el correcto.
3. **Encargo del lead:** `ULTRON_STUDIO_BACKEND.md` no existe; es `ULTRON_STUDIO_BACKEND_REPORT.md` (sección del H6 en `:844`, coherente con lo medido).
4. **Documentos:** los estados desfasados de m-5.
5. **Coincide con lo medido:** 56 rutas; 4 llaves (2 al Administrador); 3 tablas (14 en total); 13 disparadores y 22 CHECK nuevos (43/49 en PG, 30 disparadores en SQLite) y 4 índices parciales; 722 casos en 22 secciones; 8 variables en la fase 1; detector 54/54; cero sumideros de HTML; catálogo de 105 llaves idéntico; puerta de diff vacía; `PREVIO = 7ba0ce8`; 13 workers.
