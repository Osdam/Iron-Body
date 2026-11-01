# ULTRON STUDIO · Hito 6 · Revisión de seguridad transversal (`security`)

**Fecha:** 01-11-2026.

**Revisado:**
- **Backend** `feat/ultron-studio-h6`: el código de `app/`, `config/`, `routes/` y `database/` es el de los paquetes DB, V, TW (commits anteriores) y S (`4e6f5fe`); OPS en `34ab7b3`. HEAD al cerrar: `f549c50`, que desde `34ab7b3` solo añade documentación y el banco local `ops/banco-h6/` (los scripts de despliegue, alcance y smoke no cambian). Diff de la rama: `e1b1d26..HEAD`, 83 ficheros fuera de `docs/` y del fixture, +16 857 líneas.
- **Cambios sin confirmar al cerrar:**
  - S, en curso, en 4 ficheros de `app/`: O-2 y Q-6 de la Enmienda 2 (texto del 422 del teléfono, `activeWhatsapp` con `unreadable`). Los leí y no cambian ningún hallazgo; `StudioCanaryPhone::message()` no repite el número.
  - Del tester: `UltronStudioIntegrations{Race,GuardsAdversarial,SentinelAdversarial,EnvSentinelAdversarial}Test.php` y `tests/Support/IntegrationRaceSessions.php`.
- **CRM** `feat/ultron-studio-h6`:
  - capa de datos confirmada en `ede716b`;
  - la página, sin confirmar y en escritura por FE mientras revisaba. Al cerrar ya estaban la página, la sesión y los 8 componentes (estado, campañas, diálogo de asociación, canarios, diálogo de registro, Twilio, voz y deriva): 2 751 líneas de TS. Les hice una pasada rápida (§3 #16 y §4).

**Modo:** solo lectura. Este informe es el único fichero que escribí. Sin commit, sin servidor y sin producción.
- PHPUnit en SQLite y en el PostgreSQL 14 desechable local (`UNIT_DB=ultron_studio_h6_sec`, creada para esto).
- Tres scripts mínimos en el scratchpad (`…/scratchpad/security/`). Cargan clases reales con `vendor/autoload.php` y usan claves y teléfonos sintéticos generados en el momento; no se imprime ninguno.
- Ningún hallazgo sale de los avisos automáticos de los hooks. Todos salen de leer el código y de las ejecuciones de abajo.

**Rutas:** relativas a `backend/`; `‹F›` = `Iron-Body_Front/src/app/modules/ultron-studio`; «contrato» = `docs/ultron-studio/ULTRON_STUDIO_H6_CONTRACT.md`.

**Líneas:** las de `app/` son las del código confirmado (HEAD). Los cambios en curso de S desplazan 5 líneas en `StudioCanaryCoherence.php` (docblock nuevo). Las del CRM son las del árbol al cerrar.

**Pruebas:** las de §3 corrieron sobre el `app/` confirmado. Justo antes de las carreras, `git status` solo mostraba los dos ficheros del tester. Los cambios de S que aparecieron después no tocan revocar ni el registro, que es donde están B-2 y N-1.

**Reparto:**
- `api-security-specialist` (`informe-api-security.md`, escrito en paralelo) cubre la superficie de la API: autorización ruta a ruta y por objeto, matriz de roles, orden de errores, `clientRequestId`, cupos y oráculos.
- Aquí va lo transversal: frontera y red, criptografía de las huellas, datos personales, secretos, logs, configuración, scripts de operación, base de datos, CRM y dependencias.
- Cierro sus notas **AS-4** y **AS-5**, que me dejó a mí (B-1 y N-2). Coincido con **AS-3** (oráculos) y con lo que da por heredado (cuatro ojos por cuenta, O-1), sin repetirlo como hallazgo propio.

---

## 0. Veredicto

**Sin bloqueantes. Altas: ninguna. Medias: ninguna.** Dos bajas y cinco notas. Ninguna exige parar la fase 1. B-1 (texto) y N-3 (la forma de `facts`) conviene cerrarlas **antes de la fase 2**: el reconocimiento de la configuración fija la huella de `facts`.

| # | Severidad | `ruta:línea` | Qué y escenario de explotación | Corrección propuesta → dueño |
|---|---|---|---|---|
| B-1 | **Baja** (cumplimiento, Ley 1581) | contrato:1212-1213; `app/Support/Privacy/PhoneFingerprint.php:11-12`; `database/migrations/2026_11_01_100002_…guards.php:148-149` y `:179` | Con la `APP_KEY`, la huella y los 4 dígitos de una fila devuelven el número completo en ≤ 0,2 s (≤ 10⁵ HMAC; PoC abajo). I13 afirma que «no se puede recuperar ni con la `APP_KEY`». Además, revocar no suprime: huella, 4 dígitos y alias son inmutables y la fila no se borra, también la de voz. **Explotación:** quien tenga a la vez la base y la clave (RCE como `www-data`; el directorio del respaldo, que guarda juntos `ironbody.dump` y `env.backup`) recupera todos los números canarios, revocados incluidos. **Impacto incremental bajo:** ese atacante ya lee en claro los teléfonos de leads y socios | Corregir I13 y el docblock ya (seudónimo, no anonimizado) → **lead/DOC**, **S**. Procedimiento de supresión de una fila revocada (huella, 4 dígitos, alias y motivo), con el rol dueño y auditado → **architect + DB**, decisión del propietario (Hito 7) |
| B-2 | **Baja** (integridad del libro) | `app/Services/UltronStudio/StudioCanaryNumbers.php:327` (estado leído en `:602`, fuera de la transacción) | Revocar anota en el libro el estado de origen leído **antes** de la transacción. Si alguien autoriza a la vez, el UPDATE espera la fila, la reevalúa en `authorized` y revoca, pero el evento dice `pending → revoked`. El libro (solo inserción) queda con una historia imposible. Lo caza la prueba de carreras del tester (sin confirmar), que falla en PG en las dos ejecuciones | Leer el estado dentro de la transacción con `SELECT … FOR UPDATE` antes del UPDATE condicionado, o tomarlo del propio UPDATE (`RETURNING`). La prueba ya existe → **S** |
| N-1 | Nota | `app/Services/UltronStudio/StudioCanaryNumbers.php:190-235` | Solo se atrapa el 23505. Cualquier otro error de la base en el INSERT del registro (40P01, 57014, conexión caída) acaba en `laravel.log` con el SQL y sus valores: huella, 4 dígitos, alias y cifrado de voz. Con B-1, eso es el número para quien tenga la clave, y el log sobrevive a la revocación | Atrapar `QueryException` (que no sea 23505), registrar solo la clase y el SQLSTATE y relanzar sin valores, como la coherencia (`StudioCanaryCoherence.php:177-183`) → **S**; caso centinela → **tester** |
| N-2 | Nota (cierra AS-5) | `app/Services/Twilio/TwilioReadOnlyClient.php:186`, `:210-226`; `app/Services/Voice/ElevenLabs/ElevenLabsConfig.php:115`, `:172-186`; `docs/ultron-studio/ops/deploy-h6-backend-fase1.sh:110`, `:179` | Fuera de `production`, la base de la sonda y la de ElevenLabs admiten cualquier host, también `http://` en claro. La credencial del OTP (Basic) o la clave `xi-api-key` irían allí. El caso creíble es un staging con el entorno copiado de producción. Además, todo el fijado de hosts depende de `app()->isProduction()`, y ningún script lo afirma | Fuera de `production`, base solo de bucle local (`127.0.0.1`, `::1`, `localhost`) → **TW**, **V**. `"env" => app()->environment()` en `leer_h6` y `"production"` en `ESPERADA_H6` (igual en `h6-integrations-scope.sh:49`) → **OPS** |
| N-3 | Nota | `app/Services/UltronStudio/StudioIntegrationConfig.php:600-606` | `apiBase`/`verifyBase` salen `official: true` con `http://api.twilio.com`, con un puerto distinto o con usuario: solo se mira el host. El panel existe para vigilar T-6 (el OTP manda la credencial en Basic a esa base) y no vería un esquema en claro | `official` = esquema `https`, host exacto, sin puerto (o 443) y sin usuario. Cambia la huella de `facts` de Twilio: hacerlo antes de reconocerla en la fase 2 → **S** |
| N-4 | Nota | `docs/ultron-studio/ops/h6-integrations-scope.sh:16-17`, `:133-143`; `smoke-run-h6.sh:109-114`; `deploy-h6-backend-fase1.sh:235`, `:245-248`, `:273-277` | (a) `writes off` (vuelta atrás de nivel 2) se da por «APLICADO» leyendo con tinker (CLI, sin el OPcache del FPM). El smoke, que sí mira el proceso web, no admite la combinación escrituras apagadas + sonda encendida. (b) El modo del `.env` se imprime pero no se exige. (c) Sigue `ironbody-migrate` como root sobre `bootstrap/cache/*.php` de `www-data` (D2-3, conocido y pendiente del propietario), y la caché de configuración que regenera nace con su umask antes del `chmod 600` (N-7 del H5) | (a) Comprobar una vez `opcache.validate_timestamps` del pool del FPM y anotarlo, y añadir al smoke la fase «1b» (escrituras apagadas, sonda encendida). (b) Abortar si el `.env` es legible por otros. (c) Sin cambio en H6 → **OPS**; (c) → **propietario** |
| N-5 | Nota | `app/Services/Voice/ElevenLabs/ElevenLabsConfig.php:28`; `app/Services/Voice/ElevenLabs/ElevenLabsVoiceProvider.php:126` | `AGENT_ID_PATTERN` va sin `D`, aunque E1.1 lo exige; no tiene efecto, porque se aplica `trim` (`:60`) y `rawurlencode` (`ElevenLabsVoiceProvider.php:87`). `secretFingerprints(string $key)` recibe la subclave de las huellas sin `#[\SensitiveParameter]`; hoy nada dentro puede lanzar con una clave no vacía | Añadir `D` y el atributo (defensa en profundidad) → **V** |

**Lo que está bien** (comprobado, no supuesto; detalle en §3):
- **Cero motor, cero red y sin dependencias:**
  - Puerta de diff vacía; catálogo `2026-10-19.1` sin cambios; `composer.*` y `package*.json` intactos en los dos repos.
  - Con las llaves por defecto, ninguna de las 18 rutas sale a la red.
  - Solo pueden salir la sonda (5 GET, hosts fijos en `production`, sin redirecciones ni reintentos, primera página) y la verificación de voz por consola.
  - Desde el CRM no hay ningún camino que escriba en Meta ni que llame: la única red alcanzable desde una ruta del hito es la sonda.
- **Huellas:**
  - Derivación idéntica a una segunda vía con `openssl`, tanto la del teléfono como la de la configuración.
  - Etiquetas propias y disjuntas de cualquier otro uso de la clave (cierro la segunda parte de AS-4 en §1).
  - Sin `APP_KEY`, lanza en vez de calcular con una clave vacía.
  - Las comparaciones en PHP usan `hash_equals`.
- **Datos personales:**
  - El número completo no sale nunca.
  - Cifrado solo en voz, y a NULL en la misma sentencia que revoca (con CHECK). Nada lo descifra.
  - El teléfono del lead canario solo existe en memoria (`unset`), y su log lleva solo la clase del error.
  - De `marketing_calls` no se lee ninguna columna personal (`reason` es la constante `followup`).
  - `facts` de la sonda: recuentos y 4 dígitos, y solo de los números de la cuenta.
- **Secretos:**
  - La centinela no encuentra ni secretos ni teléfonos en 18 rutas, el libro, `facts`, la consola y el log, en SQLite y en PostgreSQL.
  - Credencial de Twilio en `SensitiveParameterValue` y `auth_token` de la ficha de la cuenta eliminado.
  - Huellas de voz solo por `secretFingerprints()`.
  - Exportador de fixtures con configuración y `APP_KEY` sintéticas.
  - `.env.example` solo con defectos.
  - Ni paquetes de observabilidad ni escuchas globales del cliente HTTP.
- **Gobierno:**
  - Cuatro ojos por identidad en el servicio y en la base (CHECK probados con UPDATE crudo en PostgreSQL).
  - Llaves y defectos del Administrador como dice el contrato.
  - El `clientRequestId` de otra persona da 409 en las 4 escrituras.
  - El libro va en la misma transacción.
  - Banderas con `filter_var` (la lección M-1 del H5 está aplicada).
  - Topes bajo cerrojo consultivo después de READ COMMITTED: 32 de las 33 carreras del tester pasan, y la que falla es B-2.
- **Operación:**
  - `git` como root con `umask 022` y lectura por `www-data` comprobada fichero a fichero.
  - Respaldo en 700 y copias en 600 de root.
  - El `.env` se comprueba que parsea antes de tocar las cachés, y el salto final está asegurado.
  - La configuración efectiva se compara con la esperada.
  - Ni tinker ni el smoke imprimen valores.
- **CRM:**
  - Sin `innerHTML`, `bypassSecurityTrust` ni almacenamiento.
  - Solo llama a `/ultron-studio/integrations/*`.
  - Los 7 textos obligatorios son literales.
  - El lector de llamadas no copia `to_phone` ni `from_phone`.

---

## 1. Hallazgos

### B-1 (baja, cumplimiento) · La huella del teléfono con sus 4 dígitos es reversible con la `APP_KEY`, y el registro no puede suprimir

**Hecho.**
- I13 (contrato:1212) dice: «Solo huella HMAC y 4 dígitos; no se puede recuperar ni con la `APP_KEY`». El docblock de `PhoneFingerprint.php:11-12` lo repite: «tampoco con ella sale el número: solo se COMPARA».
- La huella es un HMAC-SHA256 con una subclave de la `APP_KEY` sobre un espacio pequeño: WhatsApp `^\+573[0-9]{9}$` son 10⁹ números.
- Además, la fila guarda `phone_last4`. Con esos 4 dígitos quedan 10⁵ candidatos (10⁴ en un fijo `+5760…`).

**Evidencia.** `huella-reversible.php`, con el `PhoneFingerprint::withKey()` real, una clave aleatoria de 32 bytes y un número aleatorio. El «atacante» solo usa `phone_hash`, `phone_last4` y la clave:

```
canal=whatsapp recuperado=sí igual_al_original=true intentos=78682 tiempo_ms=180.0
con otra clave: recuperado=no
canal=whatsapp recuperado=sí igual_al_original=true intentos=11129 tiempo_ms=25.5
canal=voice60 recuperado=sí igual_al_original=true intentos=6125 tiempo_ms=14.0
```

Sin los 4 dígitos, el mismo recorrido son 10⁹ HMAC. A unos 440 000 por segundo en PHP con un núcleo (medido), eso es menos de una hora, y en GPU, segundos. Quitar `phone_last4` encarece la recuperación, pero no la impide.

**Consecuencias:**
1. La huella es un **seudónimo**, no un dato anonimizado. Para quien tiene la clave (el responsable del tratamiento) sigue siendo dato personal. Frente a quien tiene a la vez la base y la clave, WhatsApp (huella) y voz (cifrado) protegen lo mismo.
2. «Borrado al revocar» (contrato:1213) no suprime el número de voz:
   - la fila revocada conserva `phone_hash` y `phone_last4`, que son inmutables (`CANARY_IMMUTABLE_COLUMNS`, migración 100002:148-149);
   - la fila no se puede borrar (`uscn_forbid_delete`, `:179`);
   - con la clave, el número sigue saliendo.
3. No hay vía de supresión (derecho de supresión, art. 8 de la Ley 1581) para la huella, los 4 dígitos ni el alias:
   - la única vía es el rol dueño desactivando disparadores, y no hay procedimiento escrito;
   - la redacción del Hito 7 que cita I13 (contrato:1219) solo habla de los nombres del personal;
   - el alias agrava el problema: O-1 sigue abierto (`300 123 45 67`, `Cel 30-01-23-45-67`, `Juan Pérez (dueño)` y `ana.perez gmail.com` pasan `aliasError()`, comprobado), el alias es inmutable y vuelve en el DTO a todo `ultron.view`. Un error al escribirlo no se corrige nunca. Es la misma clase que AS-1 de `api-security-specialist` para los motivos.

**Cómo se explota (vía creíble).** Hacen falta la base y la `APP_KEY` a la vez:
- un RCE como `www-data`, que lee `bootstrap/cache/config.php` y las credenciales de la base;
- o el directorio del respaldo de la fase 1, que guarda juntos `ironbody.dump` y `env.backup` (`deploy-h6-backend-fase1.sh:141-143`).

Con eso se recuperan todos los números canarios registrados, revocados incluidos.

**Impacto real: bajo.** Ese mismo atacante ya lee en claro los teléfonos de `marketing_leads` y de los socios, y los números canarios son de personas que dieron su consentimiento. Lo que pesa es otra cosa:
- I13, que es la base del cumplimiento del hito, afirma una garantía que no existe;
- revocar no equivale a suprimir.

**Cierro AS-4.** Primera parte: coincido, y la subo a baja por la supresión. Segunda parte («las subclaves comparten clave HMAC con `Workforce\Secrets::hash()`»): **no es explotable**.
- `Secrets::hash()` sí usa la misma clave cruda.
- Todas sus entradas llevan un prefijo con `|`: `kiosk|…`, `pairing|…`, `<purpose>|<id>|…`, `pin|…` (`KioskTokens.php:75`, `DeviceRegistry.php:47`, `AuthorizationCodeService.php:76`, `WorkforcePins.php:259`).
- Ninguna etiqueta lleva `|`: `ultron-canary-phone/1`, `ultron-studio-config-fingerprint/1`, `ultron-studio-publication`. Por construcción, ninguna entrada de `Secrets` puede ser una etiqueta.
- Las salidas de `Secrets` se guardan en la base y no salen al cliente.
- Los demás HMAC con la clave (`MetaAdEntity.php:84`, `Member.php:97`, `AuditSanitizer.php:124`, `MemberRegistrationController.php:453`, `UltronDecideToken.php:145`) usan como clave la cadena con el prefijo `base64:`, es decir, otra clave.

Queda como higiene para el Hito 7: que `Secrets` derive también su subclave con una etiqueta.

**Corrección propuesta:**
- **Ya, sin código:**
  - I13 y el docblock deben decir: «huella HMAC con la `APP_KEY`: protege frente a quien tiene solo la base (volcados, réplicas, logs) o solo la clave; con las dos y los 4 dígitos, el número se recupera en milisegundos».
  - El texto de ayuda del permiso (`PermissionCatalog.php:226`) puede quedarse: no promete irreversibilidad.
  - En el runbook: un respaldo con `env.backup` y volcado juntos es tan sensible como el servidor.
- **Hito 7, decisión del propietario:** procedimiento de supresión de una fila revocada, con el rol dueño y su evento en el libro:
  - huella sustituida por un marcador aleatorio de 64 hexadecimales;
  - `phone_last4` a `0000`;
  - alias y motivo a «[suprimido]».

  Si hace falta, con una migración aditiva que lo permita solo en filas `revoked`.

**Dueños:** lead/DOC (I13 y runbook), S (docblock), architect + DB (supresión).

### B-2 (baja, integridad del libro) · Revocar anota el estado de origen leído fuera de la transacción

**Hecho.**
- `revoke()` toma `$desde = (string) $numero->status` (`StudioCanaryNumbers.php:327`) del modelo que cargó `gate()` (`:602`) antes de abrir la transacción, y lo escribe como `fromStatus` del evento (`:344`).
- La transición es un `UPDATE … WHERE status IN ('pending','authorized','active')` (`StudioCanaryNumber.php:159`).
- Si otra petición autoriza entre la lectura y el UPDATE:
  1. el UPDATE espera el cerrojo de la fila;
  2. en READ COMMITTED reevalúa el WHERE con la fila nueva (`authorized`, que también admite) y revoca;
  3. el evento sale con `pending → revoked`.

**Evidencia.** `UltronStudioIntegrationsRaceTest::test_autorizar_frente_a_revocar_forzado_gana_quien_toma_la_fila`, conjunto «gana autorizar». Es la prueba de carreras del tester, aún sin confirmar. La ejecuté dos veces con `UNIT_DB=ultron_studio_h6_sec docs/ultron-studio/ops/pgunit.sh …RaceTest.php`, con el mismo resultado:

```
     2 => Array &3 [
         0 => 'canary_number.revoked',
-        1 => 'authorized',
+        1 => 'pending',
         2 => 'revoked',
FAILURES!
Tests: 33, Assertions: 953, Failures: 1.
```

**Impacto.** El libro de solo inserción, que es la evidencia del registro, cuenta una historia imposible: `pending → authorized` y después `pending → revoked`. No abre ningún acceso y el estado final es correcto. Las otras cuatro transiciones salen de un único estado (`ALLOWED_FROM`, `:65-71`) y `expectation` no anota estados, así que solo revocar tiene el problema. `cipherErased` (`:328`) sí es fiel: dos revocaciones a la vez no pasan las dos.

**Corrección.** Dentro de la transacción, `SELECT status … WHERE id = ? FOR UPDATE` antes del UPDATE condicionado, y anotar ese estado. O bien que la transición devuelva el estado anterior: `UPDATE … FROM (SELECT status FROM … WHERE id = ? FOR UPDATE) prev … RETURNING prev.status`. Ninguna de las dos toca el contrato.

**Dueño:** S (la prueba es del tester).

### N-1 · Un error de la base al registrar escribe en el log la huella, los 4 dígitos, el alias y el cifrado

**Hecho.** `registerGated()` (`StudioCanaryNumbers.php:190-235`) solo atrapa `UniqueConstraintViolationException` (`:227`). Cualquier otro fallo del INSERT sube al manejador de Laravel, que registra el mensaje de `QueryException` con los valores sustituidos en el SQL. El formato, comprobado con la clase real y valores sintéticos:

```
SQLSTATE[40P01]: Deadlock detected (Connection: pgsql, SQL: insert into "ultron_studio_canary_numbers" ("alias", "phone_hash", "phone_last4", "phone_encrypted") values (Movil de pruebas 1, abab…, 4821, eyJpdiI6...cifrado))
```

**Impacto.** Hace falta un fallo de infraestructura: deadlock, `statement_timeout` o conexión caída. Los errores de forma no llegan, porque los nombres se recortan en `snapshot()` y la forma ya está validada. Si ocurre, el log contiene lo que B-1 convierte en el número, para quien tenga la clave, y el cifrado de voz sobrevive en el log a la revocación hasta que el log rote. `UltronStudioIntegrationsSentinelTest` no lo cubre, porque no provoca ese fallo.

**Corrección:**
- atrapar `QueryException` que no sea 23505;
- `Log::error('ultron_studio.canary_register_failed', ['error_class' => …, 'sqlstate' => …])`;
- relanzar una excepción sin valores.

Es el patrón de `StudioCanaryCoherence.php:177-183`. La centinela puede forzar el fallo, por ejemplo con un disparador temporal en SQLite.

**Dueños:** S y tester.

### N-2 · Fuera de `production`, cualquier base; y nadie afirma que el entorno sea `production` (cierra AS-5)

**Hecho.** Lo señaló TW (`informe-twilio.md:240`) y la Enmienda 1 (E1.4) lo recoge sin cambio. `cleanBase()` (`TwilioReadOnlyClient.php:210-226`, `ElevenLabsConfig.php:172-186`) exige http(s), un host, y ni usuario ni consulta ni fragmento, pero:
- admite cualquier host;
- admite `http://` en claro.

Con `APP_ENV ≠ production` (`TwilioReadOnlyClient.php:186`, `ElevenLabsConfig.php:115`), la sonda manda en Basic el SID y el token del OTP a esa base, y la verificación de voz manda `xi-api-key`. Solo lo provoca quien escribe el entorno, que ya lee el token. El caso creíble es otro: un staging con el `.env` copiado de producción y la base del E2E apuntando a otra máquina.

**La dependencia que nadie comprueba.** El fijado de hosts entero cuelga de `app()->isProduction()`:
- `leer_h6` (`deploy-h6-backend-fase1.sh:110`, `h6-integrations-scope.sh:49`) no lee el entorno;
- el aviso del script, «en production se ignora» (`:167`, `:171`), lo da por hecho.

Hay un indicio indirecto de que es `production`: los scripts cuentan `production.ERROR` en `laravel.log` (`:378`), y ese prefijo lleva el nombre del entorno. No es una comprobación.

**Corrección:**
- **TW y V:** fuera de `production`, la base solo si el host es `127.0.0.1`, `::1` o `localhost`. El E2E y el banco ya usan `127.0.0.1`.
- **OPS:** `"env" => app()->environment()` en las dos lecturas, y `"env":"production"` en `ESPERADA_H6`.

### N-3 · `official` mira solo el host

**Hecho.** `base()` (`StudioIntegrationConfig.php:600-606`), ejecutado con la misma expresión:

```
http://api.twilio.com              host=api.twilio.com official=true
https://api.twilio.com:8443        host=api.twilio.com official=true
https://user:pw@api.twilio.com     host=api.twilio.com official=true
```

Según T-6, el OTP manda la credencial en Basic a `TWILIO_BASE` y a `TWILIO_VERIFY_BASE`. El panel diría «oficial» con un esquema en claro o con otro puerto. En `false` solo saldría el host, así que no hay fuga del usuario ni de la contraseña.

**Corrección:**
- `official: true` solo con esquema `https`, host exacto y sin puerto (o con 443) ni usuario;
- si no, `{official:false, host}` y, por ejemplo, `scheme`.

Cambia la huella de los `facts` de Twilio, así que conviene hacerlo antes de que la fase 2 los reconozca.

**Dueño:** S.

### N-4 · Operación

- **(a) La vuelta atrás `writes off` no se comprueba desde el proceso web.**
  - `h6-integrations-scope.sh` no recarga el FPM (`:16-17`, a propósito) y da el mando por «APLICADO» con la lectura de tinker (`:133-143`). Tinker es CLI y no comparte el OPcache del FPM.
  - DevOps deja sin verificar «FPM viendo la caché nueva sin recargar» (`informe-devops-h6.md:233`).
  - El smoke sí lee `flags` por HTTP, pero solo acepta escrituras/sonda en apagado/apagado (fase 1), encendido/apagado o encendido/encendido (fase 2) (`smoke-run-h6.sh:109-114`). Tras `writes off` con la sonda encendida, ningún paso mira lo que ve el proceso web.
  - Corrección: comprobar una vez en el servidor `opcache.validate_timestamps` del pool, anotarlo en el runbook, y añadir al smoke la combinación escrituras apagadas + sonda encendida.
- **(b) El modo del `.env` no se exige.** `deploy-h6-backend-fase1.sh:235` lo imprime y `h6-integrations-scope.sh:122` lo conserva, pero ninguno aborta si es legible por otros. Corrección: abortar si el último dígito de `stat -c %a` no es 0.
- **(c) Conocido y no introducido por el H6:**
  - `:245` vuelve a llamar a `ironbody-migrate` como root, que ejecuta `php artisan migrate` con `bootstrap/cache/*.php` de `www-data`. Un RCE como `www-data` pasaría a root en el siguiente despliegue (D2-3 del reviewer del H5; `ULTRON_STUDIO_MASTER_ROADMAP.md:56`; pendiente del propietario).
  - La caché de configuración que regenera nace con su umask antes del `chmod 600` de `:273-277` (N-7 del H5; el banco lo simula en 644).
  - `:248` imprime la salida entera del envoltorio, que no está en el repo: no se puede saber qué imprime cuando falla.

**Dueños:** OPS; (c), el propietario.

### N-5 · Voz: dos detalles de defensa en profundidad

- `ElevenLabsConfig::AGENT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/'` (`:28`) va sin `D`, aunque la Enmienda 1 (E1.1) pone el `agent_id` entre los patrones que lo llevan. No tiene efecto: `fromConfig()` hace `trim` (`:60`) y la URL lleva `rawurlencode` (`ElevenLabsVoiceProvider.php:87`).
- `ElevenLabsVoiceProvider::secretFingerprints(string $key)` (`:126`) recibe la subclave de las huellas de configuración sin `#[\SensitiveParameter]`. Con `zend.exception_ignore_args=Off` y una excepción dentro, la subclave saldría en una traza. Hoy nada ahí dentro puede lanzar con una clave no vacía.

**Dueño:** V.

---

## 2. Rotación de la `APP_KEY` (R8)

**Qué deja de valer** (leído en el código; lo del H5 está en `informe-h5/informe-security.md` §2, es el mismo evento):

| Pieza | Tras rotar |
|---|---|
| `phone_hash` (`PhoneFingerprint`) | Las huellas guardadas no casan con las nuevas. La coherencia pasa a `engine_canary_not_registered` aunque la fila siga activa: **el T1 se pierde**. Si se registra otra vez el mismo número, **no** choca con la fila vieja en `uscn_one_live` (es otra huella): habrá dos filas vivas del mismo teléfono hasta revocar la vieja y, al activar la nueva, `registry_has_extra_active`. Reintentar un registro anterior da 409 `client_request_reused` |
| `phone_encrypted` (voz) | En el H6 nada lo descifra: solo se escribe, se pone a NULL y revocar mira `getRawOriginal` (`grep phone_encrypted app/`). No hay `DecryptException`. Sin `APP_PREVIOUS_KEYS` queda indescifrable; con la clave vieja allí, sigue descifrable con ella |
| Ids opacos de Meta (`MetaAdEntity::opaqueId`) | Cambian todos. El CRM con ids viejos recibe 404 `campaign_not_found` o listas vacías: hay que recargar. Las asociaciones guardan el id de Meta y sobreviven. Los eventos `campaign_link.created` conservan el id opaco viejo en `diff.campaign`: se correlacionan por el uuid del sujeto |
| Huellas de valores (`fp`) | Cambian las del token de Meta y del token de Twilio (y las de voz, si hubiera secretos) **aunque el secreto sea el mismo**. `meta_ads` y `twilio` pasan a `drift`, que parece un cambio de credenciales y no lo es. `integrations-snapshot` anota instantáneas de sistema nuevas |
| Lo que no cambia | El enmascarado (sale de `phone_last4`), las asociaciones, el libro y la huella de la configuración de voz mientras no tenga secretos |

**Procedimiento.** Se suma al del H5 §2, en el mismo acto:
1. **Antes de rotar**, la foto:
   - `GET integrations/canaries?status=all` y `GET integrations/config` (no llevan secretos ni teléfonos);
   - anotar de cada número vivo canal, alias, 4 dígitos y estado, y el estado de los 3 proveedores.
2. **Revocar los números vivos**, con el motivo «Rotación de la `APP_KEY` (fecha, autorizada por …)». Borra los cifrados de voz, deja la evidencia en el libro y evita las filas duplicadas. Si la rotación es por compromiso de la clave, las filas revocadas siguen siendo recuperables con la clave vieja (B-1).
3. **Rotar** como en el H5 §2, paso 3: `key:generate --force` sin `--show`, `APP_PREVIOUS_KEYS`, `config:cache` con `umask 077` y los reinicios.
4. **`ultron-studio:integrations-snapshot`** → instantáneas nuevas de `meta_ads` y `twilio`.
5. **Un Super Admin vuelve a reconocer** los 3 proveedores, con la nota «Rotación de la `APP_KEY`: la deriva es solo de huellas».
6. **T1 de nuevo:**
   1. registra quien tiene el teléfono de la conversación canaria;
   2. otra persona autoriza y activa;
   3. el resultado tiene que ser `in_sync`.
7. **Recargar el CRM:** los ids opacos cambiaron.
8. **Si fue por compromiso,** la clave vieja no se queda en `APP_PREVIOUS_KEYS` más que lo imprescindible.

---

## 3. Lo verificado (comando y resultado)

| # | Comprobación | Comando o lugar | Resultado |
|---|---|---|---|
| 1 | Puerta de diff (I1 P1) | `git diff e1b1d26 --stat -- app/Services/Marketing app/Jobs app/Http/Controllers/Api app/Services/Sms app/Services/Otp routes/api.php routes/marketing.php routes/console.php config/marketing.php config/meta.php config/otp.php app/Services/UltronStudio/StudioCatalog.php` | Sin salida, exit 0. `StudioCatalog::VERSION = '2026-10-19.1'` y `StudioCatalogTest` sin cambios. La fusión `d2c04a0` solo trae 13 ficheros de `docs/` |
| 2 | Dependencias (P7) | `git diff --name-only e1b1d26..HEAD` (y desde `1bd0537`) con `grep -iE 'composer\.(json\|lock)\|package(-lock)?\.json'`; en el CRM, `ab78a6a..HEAD` con `package(-lock)?\.json\|yarn\.lock\|pnpm-lock` | Exit 1 en los tres: nada. `composer.json` sin telescope, pulse, nightwatch, sentry ni debugbar. En `app/`, `bootstrap/` y `config/`, ni `Http::globalRequestMiddleware` ni escuchas de `RequestSending`/`ResponseReceived`: las cabeceras Basic y `xi-api-key` no se registran |
| 3 | Cero red y frontera (P2, P3, I9) | `grep "Http::\|Guzzle\|curl_\|fsockopen"` en `app/Services/UltronStudio`, controladores y comandos del Studio; `grep verifyCredentials app/` | La única fachada HTTP es la del laboratorio del H4, tras sus banderas. `verifyCredentials()` solo se llama desde `voice:doctor` y `integrations-snapshot`. Leídos, sin red ni escrituras: `MetaAdsSync::statusAll()/status()`, `MetaAdsApiClient::configurationProblem()/accountIds()`, `OtpCostGuard::snapshot()` y `TwilioVerifyService::isActive()` |
| 4 | Aislamiento, cero red, centinela y matriz (SQLite) | `vendor/bin/phpunit` de `UltronEngineIsolationTest`, `UltronStudioEngineReadOnlyTest`, `UltronStudioIntegrations{NoNetwork,Sentinel,Permissions}Test`, `VoiceIsolationTest`, `TwilioReadOnlyClientTest` y `VoiceBoundaryTest` | **56 pruebas, 8088 aserciones, OK**. `ENGINE_READERS` se compara con los ficheros que nombran clases del motor (10, calculado). 56 rutas recorridas con las escrituras encendidas y la sonda contra `Http::fake`. Con las llaves por defecto, `Http::recorded()` queda vacío en las 18 |
| 5 | Todo el H6 (SQLite) | Las `UltronStudioIntegrations*Test`, la paridad, el exportador, el catálogo, `tests/{Unit,Feature}/Voice`, `tests/Unit/Twilio`, aislamiento, solo lectura y permisos | **818 pruebas, 21 363 aserciones, OK; 38 omitidas**: 33 de carreras y 5 de esquema, solo de PostgreSQL |
| 6 | Esquema, canarios, centinela y cero red (PostgreSQL) | `UNIT_DB=ultron_studio_h6_sec docs/ultron-studio/ops/pgunit.sh` sobre `UltronStudioIntegrations{Schema,Canary,Sentinel,NoNetwork}Test.php` | **37 pruebas, 777 aserciones, OK**. Incluye `uscn_four_eyes_chk` y `uscn_activation_four_eyes_chk` con UPDATE crudo, `uscn_revoke_chk`, `uscn_cipher_only_shrinks`, los 23505 de los parciales y el CHECK de 7 sujetos |
| 7 | Carreras (PostgreSQL; fichero del tester sin confirmar) | `…pgunit.sh tests/Feature/UltronStudio/UltronStudioIntegrationsRaceTest.php`, dos veces | 33 pruebas, 953 aserciones, **1 fallo (B-2)**, igual las dos veces. En verde: topes de vivos y de activos, tope de asociaciones, misma campaña, doble clic, reconocer y sonda |
| 8 | Derivación de las huellas | `openssl dgst -sha256 -mac HMAC` en dos pasos frente a `PhoneFingerprint::withKey()` y `StudioIntegrationConfig::valueFingerprint()`, con la clave sintética `00..1f` | Teléfono: `baf56356df282a5a…` en los dos, iguales. Configuración: `88f601bc` en los dos, iguales. Etiquetas propias; `hash_equals` en `StudioCanaryNumbers.php:681`, `StudioCanaryCoherence.php:131`, `StudioIntegrationConfig.php:280`, `:364` y `:440`. Sin clave, `RuntimeException` (`PhoneFingerprint.php:74-76`, `StudioIntegrationConfig.php:99-101`) |
| 9 | Reversibilidad, log y alias | `huella-reversible.php`, `QueryException` real y `aliasError()` | B-1, N-1 y alias (en B-1) |
| 10 | Datos personales (I13), leído en el código | DTO y modelo | Solo `masked` (`StudioCanaryNumbers.php:442`); `$hidden` = cifrado y huella (`StudioCanaryNumber.php:99`). El libro: `channel` y versión al registrar (`:222`); motivo y `cipherErased` al revocar; ni alias, ni dígitos, ni huella. El cifrado solo en voz (`:161`) y a NULL en la misma sentencia (`:334`, con `uscn_revoke_chk`). Nadie lo descifra (`grep phone_encrypted app/`). Teléfono del lead: `unset` (`StudioCanaryCoherence.php:86`, `:91`) y log con la clase (`:181`). `marketing_calls`: 8 columnas no personales (`StudioTwilioPanel.php:50`); `reason` = `'followup'`, su único escritor (`DispatchMarketingFollowups.php:136`). Sonda: `last4` solo de `incoming_phone_numbers` (`TwilioProbeSanitizer.php:189-208`), llamadas por estados cerrados y alertas por código (1–6 cifras) y nivel |
| 11 | Secretos | Centinela (#4 y #6) y lectura | 5 secretos y 3 teléfonos ausentes de respuestas, libro, `facts`, consola y log capturado. `SensitiveParameterValue` (`TwilioReadOnlyClient.php:94-96`, `:120-121`) y `unset($cuerpo['auth_token'])` (`:173`). `#[\SensitiveParameter]` en `PhoneFingerprint`, `StudioCanaryPhone::parse/message`, `register`, `registerGated`, `probeLocked`, `steps` y `valueFingerprint`. Exportador: `APP_KEY` y configuración sintéticas (`StudioIntegrationsExport.php:36`, `:78`, `:365-399`). Comandos: clasificaciones y 12 hexadecimales de la huella del conjunto. `.env.example`: defectos y comentarios sin valores. En las líneas añadidas del diff, la búsqueda de `sk-`, `AKIA`, `PRIVATE KEY`, `xox?-`, `EAA`, `ghp_`, `base64:` largo y `AC`/`SK`+32 hex solo da la clave sintética `00..1f` (`StudioIntegrationsExport.php:36`) |
| 12 | Defectos por rol | `CrmPermission::byRole()` con la app arrancada (solo nombres de permisos) | Super Admin: las 12 `ultron.*`. Administrador: `view`, `edit_draft`, `manage_rules`, `lab_manage`, `approve`, `campaigns_manage` y `canary_manage`, sin `canary_authorize` ni `integrations_manage`. Administrativo, Recepción y Entrenador: ninguna |
| 13 | Gobierno, leído | Servicios | Identidad antes que estado (`StudioCanaryNumbers.php:247-248`, `:272-273`). `registered_by_admin_id` es inmutable: no hay TOCTOU en los cuatro ojos. El reintento de otra persona da 409 en las 4 escrituras con `clientRequestId` (`:679`, `StudioCampaignLinks.php:492`, `StudioIntegrationConfig.php:552`, `StudioTwilioProbe.php:209`). El libro va dentro de la transacción en las 10 escrituras. `recordDenied` va fuera, porque la operación falla. Banderas: `filter_var(…, FILTER_VALIDATE_BOOLEAN)` y `=== true` (`StudioIntegrations.php:82-90`). `lock()`: READ COMMITTED y `pg_advisory_xact_lock(crc32)` como primera sentencia (`:127-133`) al registrar, activar y asociar. **Cuatro ojos por cuenta** (heredado; `api-security-specialist` §3.7): para el T1, el runbook debe comprobar **dos personas**, no dos cuentas (ninguna creada o elevada por la otra en los días previos) |
| 14 | Reconocer fuera de la bandera (decisión 11) | `StudioIntegrationConfig::acknowledge()` | Exige `integrations_manage`, que solo tiene el Super Admin. No escribe si la huella vista no es la efectiva (`:364`), y la instantánea y su evento van en la misma transacción. No cambia nada fuera del Studio. **Riesgo residual:** una sola persona puede «bendecir» una deriva. Lo acota que la deriva enseña las claves y el host (`{official:false, host}`) y queda en el libro. No es un hallazgo: no abre nada. En la fase 1 es la única escritura de persona posible |
| 15 | Scripts de operación | Lectura de `deploy-h6-backend-fase1.sh`, `h6-integrations-scope.sh`, `smoke-run-h6.sh`, `smoke-h6.py` y `h6-schema-check.sql` | `git` como root con `umask 022` (`:24`); `merge --ff-only` y `sudo -u www-data test -r` de cada fichero de `git diff --name-only PREVIO HEAD`, con recuento (`:211-227`). Respaldo en 700, volcado y `env.backup` de root en 600 (`:142-143`). `.env` igual al respaldo (`:147`). Salto final asegurado (`:149`, `:230`). Una clave del H6 que ya exista con otro valor o repetida, o una pieza de ElevenLabs presente, aborta (`:150-161`). El fichero parsea antes de migrar, imprimiendo solo la clase (`:237`). `route:cache` con `umask 077` y cachés en 600 (`:273-277`). Configuración efectiva exacta (`:289`). Tinker solo se imprime si casa con el patrón (`:105`, `:111`). `--solo-comprobar` sale tras el paso 0, y su único efecto es `git fetch` (`:121`, `:209`). El alcance hace la copia previa con `install -m 600 -o root` (`:112`) y la caché con `umask 077` (`:128`). El smoke: contraseña por entorno, hash por stdin (`:141-146`), tokens 0600 en un directorio 0700, y un trap que desactiva la cuenta, borra sesiones, comprueba el 401/403 del token y borra las copias de `/root` (`:73-101`). El detector solo imprime etiquetas. La sonda nunca se pulsa desde el smoke (`smoke-h6.py:786-791`). `h6-schema-check.sql`: sin DML ni DDL |
| 16 | CRM (capa de datos y página en curso) | `grep -rnE "innerHTML\|outerHTML\|insertAdjacentHTML\|bypassSecurityTrust\|DomSanitizer\|eval\(\|new Function\|document\.write\|window\.open\|location\.href"` en `‹F›` (sin specs), y además `localStorage\|sessionStorage\|console\.` en la página, la sesión y los 8 componentes | 0 apariciones. El servicio solo llama a `${adminApiBaseUrl}/ultron-studio/integrations/*` (`studio-integrations-api.service.ts:141`, `:382`). El lector de llamadas no copia `to_phone` ni `from_phone` (`studio-integrations-parse.ts:1008`). Los 7 textos obligatorios son literales (`studio-integrations-text.ts:54-72`) y los usan 7 componentes y la sesión. Los botones de los canarios salen de `actions[a].allowed` del servidor (`studio-canary-numbers.ts:159`). El teléfono del registro: campo `type="tel"` con `autocomplete="off"` (`studio-canary-register-dialog.ts:51`); se vacía al registrar y al cerrar (`:144`, `:163`) y se conserva si hay error, para corregirlo. En la sesión, solo en el `Map` en memoria de la página hasta una respuesta definitiva (`studio-integrations-session.ts:137`, `:465-475`, `:539`). Nada en almacenamiento |
| 17 | Arnés E2E | Lectura | Los simuladores escuchan en `127.0.0.1` (`mock-twilio.cjs:426`, `mock-elevenlabs.cjs:309`). `APP_ENV=local` (`run.sh:203`). Secretos aleatorios con `umask 077` (`run.sh:176`, `:185`). Semilla con números sintéticos. El banco `ops/banco-h6/` vive solo en local (`127.0.0.1`, `APP_ENV=local`, fuera del repo) |

Los scripts del scratchpad (`huella-reversible.php` y las órdenes de una línea copiadas arriba) se purgan con la sesión; las salidas relevantes están en este informe.

## 4. Lo que no pude verificar

- **Servidor.** No tengo acceso:
  - el `APP_ENV` real (N-2; solo el indicio de `production.ERROR`);
  - `opcache.validate_timestamps` del FPM (N-4a);
  - el dueño y el modo del `.env` y de `bootstrap/cache/*`;
  - el contenido, la umask y la salida de `/usr/local/bin/ironbody-migrate` (N-4c);
  - `zend.exception_ignore_args` (pesa en N-5).
- **CRM.** La página y sus 8 componentes aparecieron mientras revisaba, sin confirmar y aún en escritura. Solo les hice una pasada rápida (§3 #16): sumideros, almacenamiento, teléfono del formulario, textos y botones. No los ejecuté ni los vi en el navegador. Hace falta **una segunda pasada** sobre el commit de FE (contrato, «Orden», paso 5): que cada texto se vea junto a su acción, el botón de la sonda solo con `can.integrationsManage` y `flags.twilioProbeEnabled`, y nada nuevo que pinte HTML.
- **No los ejecuté yo:**
  - el E2E `h6-integrations` con la pila real;
  - las mutaciones del tester;
  - las suites de Marketing y OTP (S informa de sus cifras);
  - las pruebas adversariales nuevas del tester (sin confirmar).
- **La calificación jurídica de B-1** según la Ley 1581: doy el hecho técnico; la calificación es del propietario o de su asesor.

## 5. Diferido al Hito 7 (o a T2/T3)

- **Supresión** de filas revocadas: huella, 4 dígitos, alias y motivo (B-1), más los motivos y notas de AS-1, que van al libro.
- **Regla del alias** más fuerte (O-1): series de dígitos con separadores que sumen 7 o más (`(?:[0-9][\s.\-()]*){7,}`).
- **`ironbody-migrate` como root** y la umask de su `config:cache` (N-4c).
- **Cuando el motor lea el registro (T2):**
  - activar un número pasará a decidir a quién responde ULTRON;
  - hoy activar solo exige `canary_manage`, que el Administrador tiene por defecto, y ser otra persona que quien registró;
  - hay que reevaluar si activar exige `canary_authorize` o cuatro ojos por rol;
  - el oráculo de AS-3 pesa más.
- **Derivaciones con la `APP_KEY`:**
  - un identificador de clave o `app.previous_keys`, para que rotar no sea todo o nada;
  - un mínimo de 32 bytes decodificados en `PhoneFingerprint::appKey()` y `StudioIntegrationConfig::key()` (N-1 del H5);
  - que `Workforce\Secrets` use también una subclave con etiqueta.
- **Twilio:** T-1/T-2 (`trustProxies` y extraer el validador) y T-3 (persistir los avisos), ya en el contrato.
- **Programar `integrations-snapshot`**, ya en el contrato.

## 6. Para cada agente

- **S:** B-2; N-1; N-3 (antes de la fase 2); el docblock de B-1.
- **TW / V:** N-2 (solo bucle local fuera de `production`). **V:** N-5.
- **OPS:** N-2 (afirmar `production`); N-4 (a) y (b).
- **tester:** la prueba de B-2 ya existe y la caza. Pendiente: un caso centinela que fuerce un fallo de la base distinto del 23505 al registrar (N-1), y casos negativos de `official` (N-3).
- **FE:** avisar al confirmar la página para la segunda pasada de §4; mantener visible `TEXTO_ALIAS` junto al campo.
- **lead / DOC:** corregir I13 y el runbook (B-1, §2 de rotación); el T1 con dos personas (§3 #13).
- **architect + DB:** el diseño de la supresión (B-1), con decisión del propietario.
- **api-security-specialist:** AS-4 y AS-5 quedan cerrados aquí (B-1 y N-2), y su AS-3 alimenta la revisión de T2.
