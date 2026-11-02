# ULTRON STUDIO · Hito 6 · E2E con la pila real: `h6-integrations` (paso 4 del contrato, parte E2E)

**Fecha:** 01-11-2026 (Bogotá; las últimas pasadas, ya 02-11 UTC). **Autor:** `tester` (E2E). **Repos:** CRM
`_worktrees/ultron-h6/Iron-Body_Front` y backend `_worktrees/ultron-h6/Iron-Body`, rama `feat/ultron-studio-h6`.
**Refs de la pasada final:** backend **`2ac7f54`** (el código es el de `d730c52`/`7d2c6d8`: lo posterior es solo
documentación; el HEAD de después, `8da7b7a`, también es solo documentación: `git diff 2ac7f54 8da7b7a` fuera de `docs/` vacío) y CRM **`8536c0e`** (la corrección de H-1 del lead sobre `9973b5b` = `3bac1e2` + el WIP de la spec).
**Tocado:** solo `Iron-Body_Front/e2e/ultron-studio/**` y este informe. **Sin commit** de lo de esta tanda (el lead
confirmó el WIP anterior en `9973b5b` y `2ac7f54`). Nada del backend ni del módulo del CRM; nada de producción ni de
red real: la valla `fence.cjs`, vacía.

## 0. Resultado

| Pasada | Refs | Resultado |
|---|---|---|
| **Final** (`bash e2e/ultron-studio/run.sh 2ac7f54 8536c0e`, inicio 2026-11-02 00:33 UTC) | backend `2ac7f54`, CRM `8536c0e` | **660/660** en **1548 s** (F 40/40 (26 s), G 25/25 (8 s), H4 27/27 (17 s), turno 30/30 (6 s), H5 276/276 (160 s), h6 250/250 (1253 s), cierre 12/12 (3 s)). **Ningún fallo** |

Por escenario de `h6-integrations` (pasada final): E1 16/16 · E2 16/16 · E3 19/19 · E4 8/8 · E5 4/4 · E6 18/18 · E7 13/13 · E8 10/10 · E9 24/24 · E10 25/25 · E11 28/28 · E12 13/13 · E14 7/7 · E15 10/10 · arnés, reinicios y sesiones 39/39. **E13** se cubre en su pasada con el backend del H5
(`run.sh 7ba0ce8 9973b5b h6-integrations cierre`: **35/35**, §0b): con un backend del H6 no aplica.
Las tres sondas reales: #1 ok/otp → ok (5 GET, crm); #2 unauthorized/otp → auth_failed (1 GET, api); #3 hostile/restricted_key → ok (5 GET, crm). Simuladores al final: Twilio 11 peticiones y ElevenLabs 5, 0 violaciones. Clics tapados registrados por `clicar`: ninguno.

## 0b. Historia de las pasadas (de la primera a la final)

| Pasada | Órdenes | Resultado |
|---|---|---|
| Desarrollo, pila limpia (backend `7d2c6d8`, CRM `f38699d`) | `E2E_HOLD=1 bash e2e/ultron-studio/run.sh 7d2c6d8 f38699d h6-integrations` | 243/249 en 1042 s (carga 48–61 por suites de otros compañeros): H-1 (real) y 5 rojos de mi spec (carrera de la sonda hostil, `TypeError` del barrido) corregidos; el `cierre` justo después, 12/12 |
| Regresión completa nº 1 | `bash e2e/ultron-studio/run.sh 7d2c6d8 f38699d` | 555/557 en 395 s: F 40/40, G 25/25, H4 27/27, turno 30/30, H5 276/276, cierre 12/12; h6 145/147: H-1 y un `TimeoutError` en E3 (el diálogo de «Desactivar» no se abrió; causa probable: la recarga en vivo de `ng serve`, trampa 41) que cortó la spec |
| E13 (CRM del H6 contra el backend del H5) | `bash e2e/ultron-studio/run.sh 7ba0ce8 9973b5b h6-integrations cierre` | **35/35 en 109 s** (h6 23/23: arnés 7, E13 10, E14 4, sesión 2; cierre 12/12). La primera vuelta, 34/35 por mi comprobación del *preflight* CORS, corregida |
| Mutaciones dirigidas | `mutar-h6.cjs` sobre `H6_HASTA=inicio E2E_HOLD=1 run.sh 2ac7f54 9973b5b h6-integrations` | **3/3 cazadas** (§2b): cuatro ojos del servicio, enfriamiento de la sonda y NFC del alias en el CRM |
| Pasada completa con los refs finales, 1.ª vuelta | `bash e2e/ultron-studio/run.sh 2ac7f54 8536c0e` | 659/660 en 1064 s: F 40/40, G 25/25, H4 27/27, turno 30/30, H5 276/276, cierre 12/12; h6 249/250, con H-1 ya en verde (12 de 12) y E3 completo sin *timeout*. El rojo, **mío**: el barrido de las 3 tablas marcaba los ids de Meta que I3.1 guarda **a propósito** en `ad_account_id`/`campaign_id`; corregido (solo esas columnas y `phone_hash` fuera del barrido; el resto de cada fila, entero) y comprobado contra esa misma base (22 filas, 0 hallazgos) |
| **Pasada completa final** | `bash e2e/ultron-studio/run.sh 2ac7f54 8536c0e` | **660/660 en 1548 s** (F 40/40 (26 s), G 25/25 (8 s), H4 27/27 (17 s), turno 30/30 (6 s), H5 276/276 (160 s), h6 250/250 (1253 s), cierre 12/12 (3 s)), ningún fallo |

## 1. Qué es `h6-integrations` ahora

Sustituye al esqueleto del 31-10 (que registraba un fallo explícito en cuanto el backend traía el H6). Es una spec del
arnés existente (`run.sh` la añade sola antes de `cierre` cuando la instantánea trae la migración del H6) y recorre el
contrato con la pila real (artisan serve con 8 workers, workers de cola reales, PostgreSQL 14, `ng serve`, simuladores
de Twilio :8095 y ElevenLabs :8096, valla :8099), desde el CRM en Chrome con login por el formulario y por la API cuando
el escenario lo pide.

**Fases** (las llaves del `.env` de la instantánea se cambian con `h5-stack.sh set …`, que reinicia servidor y workers:
el proceso web de `artisan serve --no-reload` no ve un `.env` nuevo sin reiniciar, el hallazgo de devops del 01-11):

| Fase | Llaves | Qué se prueba |
|---|---|---|
| F1 | la fase 1 de producción (el `.env` con el que arranca la pila) | E1, E2, E4, E8 (antes), E10 (never_authorized → reconocer), E11 (409), E12, E15 |
| F2 | `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=true` | E5 |
| F3 | + `ULTRON_STUDIO_TWILIO_PROBE_ENABLED=true` | E10 (deriva, `config_changed`), E11 (H-1, una pulsación, `reused`, `replayed`, Administrador, 401), E3, E6, E7, E8, E9 (sin cadena), capturas |
| F4 | + publicar en canario (`ULTRON_STUDIO_PUBLISHING_ENABLED=true`, `MARKETING_ULTRON_STUDIO_CONFIG=canary`, puerta `waived`, sin ventana) | E9 `match`, `mismatch`, `stale` |
| F5 | + la clave restringida de la sonda (`…PROBE_KEY_SID/_SECRET`, escritas en el `.env` sin pasar por la línea de órdenes) | E10 (deriva de `probe.credential`), E11 hostil |
| F6 | + `CACHE_STORE=database` (el de producción) | E11 el 429 del cupo y la cuenta atrás del CRM |
| F7 | `h5-stack.sh restore` | vuelta a la fase 1; E14; huellas finales |

Cuentas: las cinco del arnés (`administrador` es el rol del sistema, con `campaigns_manage` y `canary_manage` y sin
`canary_authorize` ni `integrations_manage`), y dos **Super Admin** propias de la spec (`superadmin`,
`superadmin2`: los cuatro ojos entre dos personas con `canary_authorize` no se pueden probar con una sola). Contraseñas
aleatorias por la entrada estándar, solo en memoria.

**Oráculos independientes** (Node, con la `APP_KEY` de la instantánea; no con el código del Studio), validados contra
la pila antes de usarlos: la huella del teléfono (I4.3: `HMAC(HMAC(APP_KEY,'ultron-canary-phone/1'), e164)`), las
huellas de 8 de los tokens (I5.3, `ultron-studio-config-fingerprint/1`), la huella del conjunto (sha256 del JSON
canónico con claves ordenadas por bytes) y el oráculo de la semilla para `possiblyDeleted`, `stale` y la clasificación.

## 2. Escenarios

Cada comprobación lleva el prefijo de su escenario (`E1 · …`) en `results.jsonl`; el detalle nombrado está en la spec
(`e2e/ultron-studio/specs/h6-integrations.cjs`), en el anexo (pasada final) y en el `results.jsonl` de cada pasada. Lo
esencial de cada uno: E1 clasificación de la semilla (`degraded/stale` → `connected_read` tras la
pasada de la cuenta vieja) y `integrations-doctor --expect` 0/1; E2 12 campañas, orden, `possiblyDeleted`, `stale`,
antigüedad ±2 min, filtros, AS-2, el nombre hostil inerte y las 12 en el CRM (la de H-1 incluida, ya con `8536c0e`); E3 asociar (CRM, una petición 201),
`replayed`, `client_request_reused`, `campaign_already_linked`, sustituir por X, `campaign_link_moved`, UPDATE crudo
parado, 404/409 de destinos, desactivar con motivo (CRM), `invalid_transition`, AS-1 422, libro sin ids ni nombres;
E4 47–54 → 409 sin libro ni filas y la página lo explica; E5 el proceso web ve la bandera y las mismas escrituras pasan;
E6 registro desde el CRM con alias NFD → NFC, consentimiento local, huella = oráculo, 422 con los textos fijos,
cuatro ojos por permiso y por identidad (evento deduplicado); E7 activar ≠ quien registró, tope 3, desactivar (CRM),
revocar desde los tres estados, terminal, voz cifrada y borrada (CRM, `cipherErased: true`); E8 la secuencia de
veredictos completa; E9 `none`, `not_applicable`, `mismatch` (off), `match` (X en canario), `mismatch` (Y, desde el
CRM), `stale` tras revertir; E10 never_authorized → reconocer (CRM y API), `config_changed`, deriva `probe` y
`probe.credential`; E11 409 en fase 1, H-1, una pulsación = 5 GET con 0 violaciones y hechos exactos, `reused`,
`replayed`, Q-11, Administrador 403, 401 → `auth_failed` con 1 GET, el modo hostil con `restricted_key` saneado y sin
seguir páginas, el 429 con `CACHE_STORE=database` y la cuenta atrás del CRM; E12 voz y política en orden, 0 peticiones a :8096 por la web y `voice:doctor --verify` (5 modos, 1 GET
cada uno); E14 huellas del motor (17) y de P3 (7) idénticas, detector con autoprueba y todas las respuestas (spec, CRM y CLI), el
libro, las 3 tablas y `laravel.log` limpios; E15 matriz
de I7.1 (401, lectora, Administrador) y sin botones.


## 2b. Mutaciones dirigidas (la spec caza lo que dice cubrir)

Sobre una pila retenida y sembrada (`H6_HASTA=inicio E2E_HOLD=1 run.sh 2ac7f54 9973b5b h6-integrations`), el conductor
`mutar-h6.cjs` (del scratchpad del tester) muta la **instantánea**, nunca los repos, y reinicia servidor y workers
(backend) o espera a que `ng serve` recompile (CRM). Después corre la spec hasta el bloque objetivo (`H6_HASTA`),
restaura, reinicia y comprueba que no queda ningún `E2E-MUTANTE`. El control es la regresión completa (mismo código sin
mutar), donde esas comprobaciones pasan.

| # | Mutación (instantánea) | Debe caer | Resultado |
|---|---|---|---|
| MB-1 | `StudioCanaryNumbers::assertFourEyes`: `if (! $access->isCanaryRegistrant($numero))` → `if (true)` (el servicio deja de negar) | E6 «quien registró autoriza → 403 four_eyes_required…» y E7 «quien registró tampoco lo activa» | **CAZADA**: la API responde **500** y no hay evento: el `CHECK uscn_four_eyes_chk` de PostgreSQL para la escritura (defensa en profundidad). El botón del CRM sigue oculto porque `actions` se calcula aparte |
| MB-2 | `StudioTwilioProbe`: el enfriamiento `< self::cooldownSeconds()` → `< 0` | E11 «enfriamiento: otra pulsación → 200 (reused)…» y «otra clave dentro del enfriamiento → 200 reused» | **CAZADA**: la segunda pulsación da 201 con una sonda nueva (5 GET más al simulador); caen también «ni los reintentos ni el reused salen a la red» y el «sin red» del Administrador |
| MC-1 | CRM `studio-integrations-session.ts`: sin `.normalize('NFC')` en el alias | E6 «el CRM envía el alias en NFC» | **CAZADA**: la validación local del CRM rechaza el alias tecleado en NFD («Usa una función de 3 a 40 caracteres…») y no envía nada; la spec lo dice con su comprobación con nombre y para con un `must` (160 comprobaciones, 4 fallidas: la esperada, el `must`, su excepción y H-1 de `9973b5b`). Esa tanda recorrió E3 entero sin ningún *timeout* (ya sin recarga en vivo, trampa 41) |

La primera tanda de MC-1 (con la recarga en vivo de `ng serve` aún encendida) murió antes, en E3: Vite mandó «Page reload sent to client(s)» segundos DESPUÉS de «bundle generation complete» y la recarga se llevó el diálogo de «Asociar». De ahí la trampa 41 y el cambio en `run.sh`.

MB-1 y MB-2 se aplicaron a la vez (ficheros y comportamientos distintos, cada comprobación solo puede caer por la
suya): 195 comprobaciones, 7 fallidas, las 4 esperadas cazadas, H-1 y 2 en cascada de MB-2. Restaurado sin rastro.

## 3. Hallazgos

### H-1 · CRM · el lector estricto mide en UTF-16 y descarta nombres de 255 caracteres con emoji (baja, no bloqueante) — corregido por el lead en `8536c0e`

- **Qué pasa.** El servidor y la base cuentan **puntos de código** (`meta_ad_entities.name` es `varchar(255)` en
  PostgreSQL, que cuenta caracteres; PHP usa `mb_*`). El lector estricto del CRM mide con `v.length`, que en JS son
  **unidades UTF-16**: `Iron-Body_Front/src/app/modules/ultron-studio/studio-parse.ts:260`
  (`if (max !== undefined && v.length > max) fallo(…)`). `nombre()` (`studio-integrations-parse.ts:180-182`) le pone
  `max: 255` y se usa para el nombre de la campaña (`:552`), el de la cuenta (`:448`) y el `currentName` de una
  asociación (`:505`).
- **Evidencia (pila real).** La campaña `a7` de la semilla tiene **255 puntos de código y 263 unidades UTF-16** (8 × 💪,
  fuera del plano básico). La API la devuelve (12 de 12); el CRM pinta **11** y dice «1 de los campañas llegó ilegible
  y no se enseña.». Comprobación roja: `E2 · CRM: «Campañas de Meta» lista las 12…` con
  `{"filas":11,"faltanEnCrm":[{"key":"a7","codePoints":255,"utf16":263}],"descartadas":"1 de los campañas llegó ilegible y no se enseña."}`.
- **Impacto.** Una campaña cuyo nombre roce los 255 caracteres y lleve emoji (frecuentes en nombres de pauta) desaparece
  de la pestaña sin más señal que el aviso de «ilegible»: no se ve ni se asocia desde el CRM (la API sí). Si ya
  estuviera asociada, el `currentName` de su asociación haría ilegible también su fila del historial. Probabilidad baja
  (hace falta un nombre de ~248+ caracteres con emoji); sin efecto en seguridad, en el motor ni en los datos.
- **Reproducir.** `bash e2e/ultron-studio/run.sh 7d2c6d8 f38699d h6-integrations` → pestaña «Campañas de Meta» como
  Administrador; o `GET integrations/meta/campaigns` y medir `name` de `a7` con `[...name].length` frente a `name.length`.
- **Corrección.** El lead la hizo en `8536c0e`: `studio-parse.ts` mide con `Array.from(v).length` (puntos de código) en `texto()` y en `nombreCorto()`, así que vale para todos los lectores del módulo, no solo para las integraciones. Las dos pasadas con los refs finales la verifican con la misma comprobación, sin relajarla: `{"filas":12,"faltanEnCrm":[],"descartadas":null}`.
- **Pendiente (frontend, no bloquea).** `8536c0e` no añade prueba unitaria: hoy el caso solo lo cubre este E2E. Conviene
  un caso en la spec del lector (`studio-parse`) o en la paridad con un nombre de 255 puntos de código con emoji, que es
  mucho más barato que la pila real.

### Observaciones (no bloquean)

- **O-1 (CRM, texto).** `discardedText` (`studio-integrations-text.ts:225`) concuerda en masculino con sustantivos
  femeninos: «1 de los campañas llegó ilegible…» (también con «asociaciones» y «llamadas»).
- **O-2 (CRM, 390 px).** La tira de pestañas se desplaza dentro de sí misma (no desborda el documento: comprobado en las
  10 capturas), pero al abrir `?pestana=twilio` o `?pestana=voz` la pestaña activa queda fuera de la vista
  (`evidence/h6-capturas/movil-twilio.png`).
- **O-3 (contrato, ejemplo).** El ejemplo de I3.4 «Pauta octubre ***4821» sugiere que se enmascaran 7 dígitos;
  `maskDigits` (regla de producción `LeadSourceClassifier::DIGIT_RUN`) enmascara series de **8 o más**, así que
  «Pauta octubre 3004821» llega intacto. La implementación sigue la regla; el ejemplo es ilustrativo. La spec afirma la
  regla real (8+) y que ningún nombre lleve una serie de 8+.
- **O-4 (Meta, lectura de la conexión).** `connection.lastSuccessAt`/`minutesSinceSuccess` (rutas 39 y 40) es el éxito
  **más antiguo** entre las cuentas (363 min con la cuenta vieja; 28 min con las tres al día), coherente con «el estado
  peor». El CRM lo rotula «Última sincronización correcta: hace 28 min», que puede leerse como la más reciente. El
  contrato no lo fija; lo anoto para frontend y documentación.

### Fallos del arnés y de mi spec encontrados y corregidos hoy (no son del producto)

| Dónde | Qué | Arreglo |
|---|---|---|
| comprobación del esqueleto | exigía `ELEVENLABS_REGION` vacía; el `.env.example` del H6 trae `default` (el defecto válido de I6.2) | acepta ausente o `default` |
| `specs/h6-seed.php marketing-calls` | nunca se había ejercitado: `42601 VALUES lists must all be the same length` (INSERT de varias filas con columnas distintas) | rellena con `null` (trampa 38) |
| expectativas de la spec | «Pauta octubre ***4821» (ver O-3); `touched: 3` (la cuenta 3 no tiene fila de cuenta: son 2) | la regla real y 2 |
| lectura del DOM | `textContent` incluye el nombre del icono de Material Symbols («info», «lock») | `textoDe` sin iconos (trampa 35) |
| JavaScript de la spec | `const` usadas antes de inicializarse (TDZ) | declaraciones de función |
| modo H5 (E13) | el prefijo `integrations/` duplicado daba 404 por la ruta: un verde falso | rutas bien formadas y un testigo del H5 en 200 (trampa 39) |
| barrido de tablas | una columna `t` tapaba el alias `t` de `L.sql` (`json_agg(t)`) | columnas `tabla`/`fila` |
| sonda hostil | con una comprobación ya en pantalla, las esperas se resolvían al instante; la spec reiniciaba la pila con la petición en vuelo (proxy: `499 clientClosed`) y la sonda salía con el simulador ya en `ok` | `esperarSondaNueva`: fila nueva en la base + respuesta entera en el proxy + la página pintándola |
| E3 en la regresión nº 1 y MC-1 (1.ª vuelta) | el diálogo de «Desactivar»/«Asociar» no se abría tras el clic: la recarga en vivo de `ng serve` (Vite) recargaba las páginas abiertas (en MC-1, demostrado: «Page reload sent» tras recompilar el mutante; en la regresión nº 1, causa **probable, no demostrada**: no hay aviso en el registro y el cliente de Vite también recarga solo si pierde su websocket; el asistente flotante tapando el botón queda descartado allí porque `scrollIntoView` lo centraba) | `run.sh` arranca `ng serve` con `--live-reload=false --hmr=false` (`ws: false`; comprobado: la página ya no abre ningún websocket ni escribe mensajes de Vite); `clicar` comprueba además con `elementFromPoint` que nada tapa el control (el asistente flotante, `z-index` 1200, cae sobre la última fila visible) y anota en la evidencia quién tapa |
| E13 | la comprobación «no pide nada más» contaba el *preflight* CORS (`OPTIONS 204`) que precede al `GET` (404) | se excluyen los `OPTIONS` |
| E14, barrido de las 3 tablas | marcaba como fuga los ids de Meta que la asociación guarda por diseño (I3.1, `ad_account_id`/`campaign_id`) | fuera del barrido solo esas dos columnas y `phone_hash`; el resto de cada fila se barre entero (también la huella del teléfono en otras columnas) |


## 4. Qué sigue sin cubrir y por qué

- **La pila de producción:** nginx + PHP-FPM, `config:cache`/`route:cache` y el build de producción del CRM. Aquí las
  llaves se cambian reiniciando `artisan serve` y los workers (`h5-stack.sh`); el camino `config:cache` +
  `h6-integrations-scope.sh` lo ensayó devops contra este mismo arnés (`informe-devops-h6.md` §8).
- **ELEVENLABS_UNVERIFIED y ELEVENLABS_VERIFIED en el proceso web.** La spec deja la voz del servidor en
  `ELEVENLABS_NOT_CONFIGURED` (el estado de producción del H6). `voice:doctor --verify` corre con piezas de prueba
  **solo en el entorno de ese proceso**; `ultron-studio:integrations-snapshot --verify-voice` no se lanza porque, con las
  piezas del proceso, anotaría además una «observada» de voz que el proceso web no tiene (ensuciaría E10). Ese camino
  y `VERIFIED` (instantánea `ok` de menos de 30 días) los cubren las pruebas de S y la paridad (`voiceDoctor · studioState`).
- **Modos de la sonda no ejercitados:** `full` (páginas llenas, «N+»), `monitor_forbidden`, `server_error`, `garbage` y
  `slow` (el presupuesto de 30 s y los pasos `skipped`). Cada sonda real exige 300 s de enfriamiento fijo en el código:
  la spec hace tres (ok, 401 y hostil con la clave restringida). Los demás los cubren `twilioProbeSanitize` (28 casos)
  y `TwilioReadOnlyClientTest`.
- **La firma del webhook post-llamada (I6.4) contra el verificador real.** El emisor de 21 variantes del simulador y
  `h6-seed.php verify-signature` están listos, pero no es un escenario del encargo (E1–E15); lo cubre la paridad
  (`elevenlabsSignature`, 52 casos).
- **Topes altos:** `campaign_links_limit` (50 activas) y `canary_live_limit` (10 vivos por canal) no se alcanzan
  (sí `canary_active_limit`: el cuarto activo con tope 3). Los cubren las Feature de S.
- **Carreras, CHECK y disparadores del H6** más allá de lo que toca la spec (dos UPDATE crudos parados por
  `uscl_replacement_once`/`uscl_inactive_is_final` y `uscn_revoked_is_final`, el cifrado borrado leído en la base y, en
  la mutación MB-1, el `CHECK uscn_four_eyes_chk` frenando la escritura): son de `ops/pgunit.sh` y de las carreras del
  tester de backend.
- **El CRM ante dos personas a la vez** (el `campaign_already_linked` dentro del diálogo, «Sustituir la activa»): por la
  API sí; en el diálogo, no.
- **Foco atrapado y lectores de pantalla:** las pestañas con teclado (flechas, Inicio, Fin) sí; el foco dentro de los
  diálogos lo comprobó frontend en Chrome contra su simulador, no aquí. Safari y Firefox, no.
- **El 413** (64 KB) y los demás 429 del Studio fuera del cupo de la sonda: con `CACHE_STORE=database` solo se pide la
  sonda; los cupos de lectura y escritura (120 y 30 por minuto) no se rozan.


## 5. Comandos exactos

```bash
cd /Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h6/Iron-Body_Front
for p in 8080 8092 8093 8094 8095 8096 4300 8099; do lsof -nP -iTCP:$p -sTCP:LISTEN; done   # libres antes de nada
bash e2e/ultron-studio/run.sh 2ac7f54 8536c0e                              # pasada final: regresión + h6 + cierre
bash e2e/ultron-studio/run.sh 7ba0ce8 9973b5b h6-integrations cierre       # E13: CRM del H6 contra el backend del H5
node e2e/ultron-studio/specs/lib.cjs --summary <run>/context.json          # resumen de una ejecución
# mutaciones: pila retenida y sembrada, sin filas del H6; luego el conductor (muta la instantánea, nunca los repos)
H6_HASTA=inicio E2E_HOLD=1 bash e2e/ultron-studio/run.sh 2ac7f54 9973b5b h6-integrations
node <scratchpad>/mutar-h6.cjs <run> MB-cuatro-ojos-y-enfriamiento E6 <scratchpad>/mut-backend.json
node <scratchpad>/mutar-h6.cjs <run> MC-alias-sin-nfc E6 <scratchpad>/mut-crm.json
# parar una pila retenida: SIGTERM al proceso cuya orden EMPIEZA por «bash e2e/ultron-studio/run.sh» (trampa 34)
```

`H6_HASTA=<bloque>` corta la spec tras un bloque (`inicio`, `F1`, `F2`, `sonda1`, `E3`, `E6`, `E9`, `sonda2`, `F4`, `F5`,
`F6`); el `finally` restaura el `.env` igual. El conductor de mutaciones y sus JSON están en el scratchpad del tester
(`mutar-h6.cjs`, `mut-backend.json`, `mut-crm.json`), no en el repo.

Ficheros tocados (todos en `Iron-Body_Front/e2e/ultron-studio/`): `specs/h6-integrations.cjs` (reescrita; en esta tanda:
`clicar`, `esperarSondaNueva`, el *preflight* de E13, `H6_HASTA`, capturas al caer), `specs/h6-lib.cjs` (Super Admin con
nombre, oráculos, `writeEnvKeys`), `specs/h6-seed.php` (`marketing-calls`), `run.sh` (`ng serve --live-reload=false
--hmr=false`, trampa 41) y `README.md` (fila de la spec, trampas 35–41, «Qué no cubre»); y este informe.

## Anexo · comprobaciones de `h6-integrations` en la pasada final (nombre exacto y resultado)

### E1 — 16/16

- ✓ GET integrations/status: los cinco bloques en su orden (meta_ads, twilio, twilio_voice, canaries, elevenlabs) y todos disponibles
- ✓ Meta Ads = el oráculo de la semilla (degraded/stale/stale): 3 cuentas, sincronización encendida, vieja a los 180 min, minutos desde el éxito ≥ 180
- ✓ Twilio = connected_otp_only (ok, sin motivo, driver twilio, Verify activo, envío encendido, 0 OTP verificados en el E2E)
- ✓ Twilio Voice = not_configured (bandera apagada, sin número)
- ✓ canarios = registry con el veredicto engine_canary_not_registered y 0 activos, pendientes y autorizados
- ✓ ElevenLabs = not_configured con el código ELEVENLABS_NOT_CONFIGURED y missing [api_key, agent_id, webhook_secret] (región por defecto válida)
- ✓ banderas del proceso web apagadas (writesEnabled y twilioProbeEnabled) con la nota fija del servidor
- ✓ `can`: Administrador {campaignsManage, canaryManage}; Super Admin las cuatro
- ✓ ultron-studio:integrations-doctor --expect=<la clasificación de la semilla> sale 0, y con una expectativa falsa sale 1
- ✓ CRM: «Integraciones» desde el tablero abre la página en «ready», con la cabecera de registro siempre a la vista
- ✓ CRM: «Estado» pinta cada área con la clasificación del servidor (data-clasificacion) y su texto, y el código de ElevenLabs
- ✓ CRM: «Lo que puede hacer tu cuenta» del Administrador: sí a campañas y canarios; no a autorizar canarios ni a la configuración y la sonda
- ✓ CRM: la nota de las banderas es la del servidor
- ✓ CRM: las pestañas se recorren con el teclado (flecha derecha → Campañas, Fin → Voz, Inicio → Estado)
- ✓ tras una pasada con éxito de la cuenta vieja (h6-seed sync-run --touch), Meta Ads pasa a connected_read (ok, sin motivo, minutos desde el éxito < 180)
- ✓ integrations-doctor con la expectativa del smoke de la fase 1 (meta_ads:connected_read, twilio:connected_otp_only, twilio_voice:not_configured, elevenlabs:ELEVENLABS_NOT_CONFIGURED) sale 0

### E2 — 16/16

- ✓ GET meta/campaigns: exactamente las 12 campañas de las cuentas conectadas (ni el conjunto, ni el anuncio, ni la de la cuenta no conectada)
- ✓ orden de I3.3: primero las ACTIVE (4), después por nombre sin distinguir mayúsculas
- ✓ possiblyDeleted = el oráculo de la semilla (a5 por fecha, a6 sin synced_at; el relleno más reciente no cuenta)
- ✓ stale = el oráculo (c1 y c2, de la cuenta vieja) y la conexión stale/stale con la cuenta 3 en stale y las otras dos en ok
- ✓ antigüedad: ageMinutes = minutos desde el synced_at de la semilla (±2) y null en la que nunca se vio
- ✓ enmascarado (maskDigits: series de 8+ dígitos → ***NNNN): ref ***NNNN, «Leads WhatsApp ***0001», la cuenta «IronBody E2E Dos ***4567»; «Pauta octubre 3004821» (7 dígitos) intacto; ningún nombre con una serie de 8+
- ✓ estados de Meta: el desconocido (PENDING_REVIEW) tal cual y el nulo como null; la cuenta sin fila de cuenta con nombre null
- ✓ ninguna campaña asociada todavía (ultron.link null en las 12)
- ✓ filtros: ACTIVE 4, unknown 1, linked=yes 0 / no 12, la cuenta vieja 2, una cuenta no conectada → lista vacía 200; un estado fuera del conjunto → 422; page 100 001 → 422 y la 100 000 vacía (AS-2)
- ✓ CRM: «Campañas de Meta» lista las 12 con las dos insignias separadas: «En Meta…» y «En ULTRON Studio…» (sin asociación)
- ✓ CRM: la antigüedad del servidor («hace N min») en a1; «Posiblemente eliminada» en a5 y a6; «Dato viejo» en c1 y c2
- ✓ CRM: el nombre hostil es texto inerte (ningún <img> en la lista, el texto literal a la vista y ningún diálogo del navegador)
- ✓ CRM: el texto obligatorio de campañas, literal (§I10)
- ✓ CRM: el espejo de Meta en «stale» con sus 3 cuentas enmascaradas (***NNNN)
- ✓ las 2 campañas de la cuenta refrescada dejan de estar stale y no son posiblemente eliminadas
- ✓ capturas a 1440 y 390 px de las 5 pestañas con datos reales (10 en evidence/h6-capturas) sin desplazamiento horizontal

### E3 — 19/19

- ✓ CRM: el diálogo de asociar lleva el texto obligatorio de campañas y la línea base elegida por defecto
- ✓ CRM (Administrador): «Asociar» a1 a la línea base → UNA petición 201 y la fila pasa a «asociación activa»
- ✓ el DTO: destino baseline con la etiqueta LEGACY_BASELINE_V1, campaña por su id opaco y ***1001, nameAtLink con maskDigits, connected, quién (el Administrador) y sin desactivar
- ✓ CRM: el carril «En ULTRON Studio» dice el destino (línea base) y la región viva avisa
- ✓ reintento idéntico (misma clientRequestId, persona y cuerpo) → 200 replayed con la misma asociación
- ✓ la misma clientRequestId con otro cuerpo, o de otra persona → 409 client_request_reused
- ✓ asociarla otra vez sin replaces → 409 campaign_already_linked {current: la activa}
- ✓ CRM: el diálogo de sustituir enseña la activa y ofrece las versiones asociables (X e Y aprobadas, no Z)
- ✓ CRM: «Sustituir» por la versión X → la nueva activa apunta a X (versionUuid, etiqueta, APPROVED «Aprobada», íntegra)
- ✓ la sustituida queda inactive con «Sustituida por otra asociación», por el Administrador y replacedBy = la nueva
- ✓ sustituir UNA sola vez: replaces = la ya sustituida → 409 campaign_link_moved {current: la activa de ahora}
- ✓ en la base, la sustituida no se reescribe: un UPDATE crudo de replaced_by_link_id lo para su disparador (uscl_replacement_once / inactive_is_final, P0001)
- ✓ destinos inválidos: versión inexistente → 404 version_not_found; versión sin aprobar (Z) → 409 version_not_linkable {currentStatus}; campaña de una cuenta no conectada → 404 campaign_not_found
- ✓ la lectora (sin campaigns_manage) → 403 forbidden
- ✓ CRM: «Desactivar» con el aviso de datos personales y un motivo → la campaña queda «Sin asociación» y el historial la enseña desactivada con su motivo
- ✓ desactivar otra vez → 409 invalid_transition {currentStatus: inactive, allowedFrom: [active]}; un motivo con un teléfono → 422 antes (AS-1, la forma va antes que el estado)
- ✓ libro: 2 campaign_link.created ({campaign opaco, accountRef, target, replaces}) y 2 campaign_link.deactivated ({reason, replacedBy}: la sustituida y la manual), sin ids de Meta en claro ni nombres
- ✓ GET meta/links (41): el historial de la campaña por id descendente, las dos inactive
- ✓ asociar, sustituir y desactivar: las 17 tablas del motor y las colas no cambian

### E4 — 8/8

- ✓ fase 1: las 8 escrituras 47–54 con un cuerpo bien formado → 409 integrations_writes_disabled (la bandera va antes que el recurso: 404 nunca)
- ✓ el 409 lleva el cuerpo de error del Studio (ok:false, code, message legible)
- ✓ los rechazos por bandera no se auditan (criterio D-6 del H5) ni dejan filas en las 3 tablas del H6
- ✓ los 409 de la fase 1: las 17 tablas del motor y las colas no cambian
- ✓ CRM (Administrador): campañas sin «Asociar», «Sustituir» ni «Desactivar»; el motivo dice que las escrituras están apagadas (ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED)
- ✓ CRM (Administrador): canarios sin «Registrar un número» y con el motivo de la bandera
- ✓ final: con el .env original, el proceso web vuelve a la fase 1 (escrituras y sonda apagadas)
- ✓ final: el .env de la instantánea es byte a byte el original (sin la clave restringida)

### E5 — 4/4

- ✓ writes on (reinicio con h5-stack.sh): el PROCESO WEB ve la bandera (status.flags.writesEnabled y canaries.limits.writesEnabled true; la sonda sigue apagada)
- ✓ las mismas escrituras que daban 409 en la fase 1 ahora pasan: 47 → 201, 48 → 200, 49 → 201, 53 → 200
- ✓ las escrituras de writes on: las 17 tablas del motor y las colas no cambian
- ✓ CRM (Administrador): ahora sí «Asociar» y «Registrar un número», y ya no se dice que las escrituras estén apagadas

### E6 — 18/18

- ✓ CRM: el diálogo de registro lleva literales el consentimiento, el texto del alias y el de canarios (§I10)
- ✓ CRM: el alias se escribió en NFD (con la tilde combinante) y la vista previa solo enseña la forma enmascarada (+57 ••• ••• 0001)
- ✓ CRM: sin marcar el consentimiento, el diálogo no envía nada y lo dice junto a la casilla
- ✓ CRM (Administrador): registrar → UNA petición 201, el número queda pending con la etiqueta del servidor y enmascarado (+57 ••• ••• 0001)
- ✓ el CRM envía el alias en NFC (O-3) y el servidor lo guarda en NFC; canal whatsapp y consentimiento true
- ✓ el teléfono completo no queda en la página tras registrar (ni E.164, ni nacional, ni con espacios)
- ✓ base: huella = HMAC con la APP_KEY (oráculo independiente en Node), 4 dígitos 0001, país 57, sin cifrado (WhatsApp), consentimiento y quién; el número en ninguna columna
- ✓ libro: canary_number.registered (— → pending) con {channel, expectedVersionUuid} y nada del número ni el alias
- ✓ CRM (Administrador): en su número no hay «Autorizar» ni «Activar»; el motivo dice que le falta «Autorizar números canarios de otra persona»
- ✓ CRM (Super Admin, otra persona): «Autorizar» → authorized («Autorizado (registro)», autorizado por el Super Admin)
- ✓ reintento del registro: misma clave, persona y cuerpo → 200 replayed con el mismo número; otro alias → 409 client_request_reused
- ✓ el mismo teléfono vivo en el canal → 409 canary_number_exists (uscn_one_live)
- ✓ 422 con los textos fijos de §I4.9 y la Enmienda 2/4: formato, país no permitido, un fijo en WhatsApp (con la pista del móvil), un móvil corto (texto general), alias con dígitos, correo, dato de contacto en grupos o en NFD, y sin consentimiento
- ✓ el Super Admin registra un segundo número (+57 ••• ••• 0002) → 201 pending
- ✓ actions para quien lo registró (Super Admin): authorize {allowed:false, reason: four_eyes_required}; activate invalid_transition
- ✓ CRM (el Super Admin que registró): sin «Autorizar»; el motivo dice que nadie autoriza un número que registró
- ✓ API: quien registró autoriza → 403 four_eyes_required {action: authorize} dos veces, con UN solo evento four_eyes_denied (deduplicado 10 min)
- ✓ otra persona (el segundo Super Admin) lo autoriza → 200 authorized

### E7 — 13/13

- ✓ CRM (Super Admin ≠ quien registró): «Activar» → active («Activo (registro)», activado por el Super Admin)
- ✓ quien registró tampoco lo activa: 403 four_eyes_required {action: activate} y su evento
- ✓ el Administrador (canary_manage, ≠ quien registró) lo activa → 200 active
- ✓ tope de activos: con 3 WhatsApp activos, activar el cuarto → 409 canary_active_limit {max: 3, channel: whatsapp}; su actions.activate dice active_limit
- ✓ CRM: el cuarto sin «Activar» y con el motivo del tope («Ya está el máximo de números activos en este canal.»)
- ✓ CRM: «Desactivar» con motivo → vuelve a authorized; libro canary_number.deactivated (active → authorized) con {reason}
- ✓ con el hueco libre, el cuarto se activa (Super Admin ≠ quien registró) → 200
- ✓ revocar desde pending, authorized y active → 200 revoked («Revocado», con quién y el motivo); libro revoked con el estado real de partida y {reason, cipherErased: false}
- ✓ revocado es terminal: revocar otra vez → 409 invalid_transition {currentStatus: revoked}; autorizarlo → 409 invalid_transition
- ✓ en la base, una fila revocada no vuelve: un UPDATE crudo lo para uscn_revoked_is_final (P0001)
- ✓ voz: un fijo 60X se registra (201) con su número CIFRADO (phone_encrypted no nulo, sin el número en claro) y 4 dígitos 5678; no es el canario del motor (isEngineCanary null en voz)
- ✓ CRM: revocar el de voz dice que su número cifrado se borra (con el aviso de datos personales) → revoked con phone_encrypted NULL en la misma sentencia y cipherErased: true en el libro
- ✓ registrar, autorizar, activar, desactivar y revocar: las 17 tablas del motor y las colas no cambian

### E8 — 10/10

- ✓ antes de registrar: el CRM dice engine_canary_not_registered (data-veredicto), con el texto obligatorio literal y 0 WhatsApp activos
- ✓ antes de registrar (API): coherence {engineCanaryConfigured: true, verdict: engine_canary_not_registered}, topes 3/10, país 57 y writesEnabled false
- ✓ antes de registrar (writes on): engine_canary_not_registered y 0 WhatsApp activos
- ✓ registrado y pendiente → engine_canary_registered_inactive; la fila es el canario del motor (isEngineCanary true)
- ✓ autorizado → sigue engine_canary_registered_inactive
- ✓ activo → in_sync (1 WhatsApp activo); el CRM lo dice en el banner y marca la fila como el teléfono de la conversación canaria del motor
- ✓ GET status: el bloque canaries cuenta 1 WhatsApp activo y dice in_sync
- ✓ un segundo WhatsApp activo → registry_has_extra_active (API y banner del CRM); el segundo no es el canario del motor
- ✓ con el canario del motor y el segundo activos sigue registry_has_extra_active (2 activos)
- ✓ revocar el segundo activo → vuelve in_sync con 1 WhatsApp activo; la fila revocada deja de ser canario del motor (Q-6: false aunque su huella coincidiera)

### E9 — 24/24

- ✓ el catálogo trae las configuraciones de la línea base y el borrador de la autora (o la línea base si no tiene) se lee
- ✓ versión «E2E H6 X 00:38:14» creada por la autora
- ✓ «E2E H6 X 00:38:14» enviada y aprobada por la revisora (cuatro ojos del H5)
- ✓ versión «E2E H6 Y 00:38:14» creada por la autora
- ✓ «E2E H6 Y 00:38:14» enviada y aprobada por la revisora (cuatro ojos del H5)
- ✓ versión «E2E H6 Z 00:38:14» creada por la autora
- ✓ sin versión esperada: expectation none (Q-7) y expectedVersion null
- ✓ esperar X en el canario del motor con la llave del motor en off → la rige la cadena en off (publication_chain/off) y la expectativa es mismatch; X no es vieja
- ✓ en un número que no es el canario del motor → la rige nadie (none/not_engine_canary) y la expectativa es not_applicable
- ✓ esperar una versión sin aprobar → 409 version_not_linkable; en un número revocado → 409 invalid_transition
- ✓ libro: canary_number.expectation_set {versionUuid}
- ✓ CRM: la fila del canario del motor enseña la esperada (X), la que rige (la cadena en off) y data-expectativa="mismatch"
- ✓ F4: la vista previa de X es publicable (puerta waived, sin ventana, canario del motor configurado, llave canary)
- ✓ publicar X en canario (publicadora ≠ autora) → 201 y X en CANARY
- ✓ el canario del motor con X esperada → match: la rige la cadena (applied, parte canary, X con su etiqueta)
- ✓ CRM: data-expectativa="match" y «la que rige» nombra a X
- ✓ CRM: esperar Y (aprobada, no publicada) → mismatch, con X rigiendo
- ✓ volver a esperar X → match
- ✓ revertir X → 201 y X en ROLLED_BACK
- ✓ tras revertir: la esperada (X) queda vieja (stale: true, ROLLED_BACK), la cadena ya no declara nada (legacy) y la expectativa es mismatch; nada cambió solo
- ✓ CRM: la esperada se marca «vieja» (data-canario-esperada-vieja)
- ✓ quitar la expectativa (versionUuid null) → expectation none
- ✓ publicar y revertir en canario (cadena del H5) y fijar expectativas: las 17 tablas del motor y las colas no cambian
- ✓ final: el borrador de la autora vuelve al contenido con el que empezó la spec (o a la línea base si no tenía)

### E10 — 25/25

- ✓ ultron-studio:integrations-snapshot (el de cada despliegue) sale 0 y anota las 3 observadas de sistema
- ✓ repetirlo sin cambios no anota nada (solo cuando la huella efectiva cambia)
- ✓ GET config: meta_ads, twilio y voice, en ese orden
- ✓ los tres en never_authorized, sin autorizada, con su observada = la efectiva y sin deriva
- ✓ oráculo independiente: effectiveFingerprint = sha256 del JSON canónico de effective, en los tres
- ✓ Twilio: credenciales sin valores (AC…NNNN, huella de 8 del token con la APP_KEY, VA…NNNN), alias {sid:true, token:true}, bases oficiales, número del gimnasio ***0006, importes en texto y probe {enabled:false, credential:otp}
- ✓ Meta Ads: token por su huella (oráculo) y las 3 cuentas por ***NNNN; voz: todo {present:false}, región default, cupo 0
- ✓ GET twilio (43): connected_otp_only, Verify y envío activos, coste del día, credenciales sin valores (las mismas refs), el webhook usage_trigger fijo y la voz not_configured
- ✓ GET twilio: llamadas de la semilla en recuentos (3, una con id del proveedor; por estado y dirección) y probe {enabled:false, credential:otp, 300 s, last:null}
- ✓ GET twilio/calls (44): las 3 llamadas con SOLO las 9 claves del contrato, sin teléfonos, transcripción, resumen, metadatos ni el centinela
- ✓ las lecturas de Twilio no salen a la red (0 peticiones nuevas al simulador)
- ✓ CRM (Super Admin): la configuración de Twilio con el texto obligatorio literal y la huella vigente corta (12 de la entera, que va en el título)
- ✓ CRM: Twilio en never_authorized («Nunca: nadie la ha reconocido todavía»)
- ✓ CRM: «Reconocer como autorizada» (Super Admin, fase 1) → UNA petición 201 y Twilio pasa a authorized con la huella que se vio, quién y la nota
- ✓ meta_ads: reconocer → 201; el mismo cuerpo otra vez → 200 replayed con la misma instantánea; la misma clave con otra nota → 409 client_request_reused
- ✓ voice: reconocer → 201; el mismo cuerpo otra vez → 200 replayed con la misma instantánea; la misma clave con otra nota → 409 client_request_reused
- ✓ reconocer una huella que no es la efectiva → 409 config_changed {provider, effectiveFingerprint, drift}
- ✓ libro: 3 integration.config_acknowledged (sujeto integration) con {provider, fingerprint, driftKeys: []} y nada más; los reintentos y los 409 no añaden
- ✓ los 3 proveedores en authorized y sin deriva
- ✓ cambiar una variable (ULTRON_STUDIO_TWILIO_PROBE_ENABLED) deja Twilio en drift con la clave probe: autorizada {enabled:false} frente a efectiva {enabled:true}; Meta Ads y voz siguen authorized
- ✓ reconocer con la huella de antes del cambio → 409 config_changed {provider: twilio, effectiveFingerprint: la nueva, drift: [probe]}
- ✓ el comando del despliegue anota la observada nueva de Twilio (su huella cambió) y ninguna de los otros dos
- ✓ CRM: Twilio en «drift» con «Qué cambió desde que se autorizó» = probe
- ✓ con ULTRON_STUDIO_TWILIO_PROBE_KEY_SID/_SECRET (las dos piezas) la sonda leería con restricted_key; Twilio en drift por probe (credential otp → restricted_key)
- ✓ reconocer la configuración nueva → 201 y authorized; ni el SID de la clave ni su secreto en los hechos

### E11 — 28/28

- ✓ fase 1: la sonda → 409 probe_disabled para el Super Admin y 403 forbidden para el Administrador; 0 peticiones al simulador
- ✓ el 409 de la sonda tampoco se audita
- ✓ probe on (reinicio): el proceso web ve las dos banderas encendidas
- ✓ H-1: reconocer Twilio DESPUÉS de probe on (CRM) → authorized, sin deriva, con probe {enabled:true} en lo reconocido y driftKeys [probe] en el libro
- ✓ CRM (Super Admin, probe on): «Comprobar Twilio ahora» a la vista, con el texto obligatorio literal y la credencial (OTP) en llano
- ✓ una pulsación → UNA petición 201 y la última comprobación «ok» en la página
- ✓ el simulador :8095 recibe exactamente 5 GET en el orden de I5.4 (account, numbers, caller_ids, calls, alerts), con la credencial del OTP y 0 violaciones
- ✓ hechos saneados = lo que sirve el simulador en «ok»: Full activa; 1 número ***0006 solo SMS; 1 caller ID; 2 llamadas (completed, no-answer); 3 alertas (60200×2, 11200×1; 2 avisos y 1 error); sin errores
- ✓ nada crudo en la instantánea: ni auth_token (que el simulador devuelve como la API real), ni friendly_name, phone_number, to/from, alert_text, request_url/variables, response_body, páginas siguientes, el token ni el SID completo
- ✓ instantánea probe/twilio/ok de una persona (actor admin, el Super Admin) y su evento integration.probe_run {provider: twilio, outcome: ok}
- ✓ GET twilio: probe.last es esa instantánea (uuid, outcome ok, por quién) y el CRM pinta los hechos en llano
- ✓ enfriamiento: otra pulsación → 200 (reused) con la misma instantánea, sin red, sin evento ni fila nueva; la página lo avisa
- ✓ API: la misma clientRequestId → 200 replayed con la misma instantánea; de otra persona → 409 client_request_reused (Q-11); otra clave dentro del enfriamiento → 200 reused
- ✓ ni los reintentos ni el reused salen a la red ni escriben en el libro
- ✓ Administrador con probe on: 403 forbidden {required_permission: ultron.integrations_manage}, sin red
- ✓ la sonda: las 17 tablas del motor y las colas no cambian
- ✓ modo 401 del simulador: la sonda responde 201 (un fallo del proveedor nunca es un 5xx) con outcome auth_failed
- ✓ con la cuenta en auth_failed se para: UN solo GET (account) al simulador, 0 violaciones; los otros 4 pasos skipped en los hechos
- ✓ el libro anota integration.probe_run {provider: twilio, outcome: auth_failed}
- ✓ modo hostil con la clave restringida (CRM): una petición 201 y 5 GET con credential restricted_key, sin seguir next_page_uri/next_page_url ni ninguna URL de la respuesta; 0 violaciones
- ✓ hechos del modo hostil saneados: sin el centinela, nombres, correos, <script>/<svg>, teléfonos completos ni textos de alertas; credential restricted_key
- ✓ lo que no está en su conjunto cerrado cuenta como other (estado de llamada inventado, nivel de alerta raro) y los códigos no numéricos no salen como clave
- ✓ números del gimnasio por sus 4 dígitos (0006 y 0110), solo E.164
- ✓ CRM: la última comprobación se pinta sin nada del modo hostil (ni el centinela ni <script>) y sin diálogos del navegador
- ✓ con la caché de producción (database): las 6 de la hora dentro del enfriamiento → 200 reused, y la séptima → 429 too_many_requests con retry_after, sin red
- ✓ CRM: tras el 429, el botón queda deshabilitado con la cuenta atrás («Comprobar (en N s)») y no pide antes de tiempo (una sola petición, 429)
- ✓ el cupo es por persona: el Administrador sigue recibiendo su 403 (no un 429)
- ✓ simuladores al final: Twilio 11 peticiones y ElevenLabs 5, 0 violaciones en los dos

### E12 — 13/13

- ✓ GET voice: ELEVENLABS_NOT_CONFIGURED, bandera apagada, missing [api_key, agent_id, webhook_secret], región default y sin verificación
- ✓ política: allowed=false con los motivos en el orden de VoiceCallPolicy::REASONS, calls_disabled_in_code el primero (6: sin voz, sin número, sin ElevenLabs, sin canario de voz activo, cupo 0)
- ✓ niveles: texto disponible sin coste en el laboratorio; integración y llamada real no disponibles con los motivos de la política (la integración sin canario ni cupo)
- ✓ CRM: «Voz» con el estado en grande y su código, el texto obligatorio literal, las piezas que faltan por nombre, «No permite llamar» con los motivos en el orden del servidor y los tres niveles
- ✓ CRM: en la pestaña Voz no hay ningún botón (fuera de la configuración autorizada)
- ✓ hasta aquí, 0 peticiones al simulador de ElevenLabs (:8096): ni el estado, ni el panel, ni la configuración salen a la red
- ✓ voice:doctor --json (el .env de la fase 1): ELEVENLABS_NOT_CONFIGURED con las piezas por nombre, sale 0 y sin red
- ✓ voice:doctor --verify sin piezas: no llama a nada y sale 1
- ✓ con las piezas y la bandera apagada (ELEVENLABS_DISABLED), --verify no llama y sale 1
- ✓ voice:doctor --verify con piezas de prueba y bandera encendida SOLO en el proceso, contra el simulador: UN GET por verificación; ok → credentials_ok (μ-law sí), pcm → no, sin campos → null, 401 → invalid_credentials, 404 → agent_not_found (sale 0 solo con credentials_ok)
- ✓ el simulador de ElevenLabs: 0 violaciones (solo GET /v1/convai/agents/{id} con xi-api-key y Accept JSON; ninguna llamada)
- ✓ el proceso web no vio las piezas de prueba: sigue ELEVENLABS_NOT_CONFIGURED y sin verificación guardada (voice:doctor no guarda)
- ✓ con un canario de voz VIVO pero no activo, la política sigue sin permitir (not_an_active_voice_canary sigue) y nada cambia en la voz

### E14 — 7/7

- ✓ huella del motor: las 17 entradas (15 tablas + colas) idénticas al principio y al final de la spec (todas las escrituras del Studio y la sonda)
- ✓ P3: las 7 tablas que el Studio lee y no escribe (espejo de Meta, llamadas, retos del OTP, conversación y lead) idénticas
- ✓ el detector caza las 6 fugas sembradas a propósito (secreto, SID, teléfono, huella, id de Meta, centinela)
- ✓ ninguna de las 948 respuestas (spec 225, CRM 709, CLI 14) lleva un teléfono completo, una huella de teléfono, un secreto, un SID completo, ids de Meta en claro ni los centinelas
- ✓ el libro (44 eventos de los 3 sujetos nuevos): sin teléfonos, huellas, alias, ids de Meta, nombres de campaña, valores ni huellas de valores de configuración
- ✓ las 3 tablas del H6 (22 filas): ningún teléfono completo, huella, secreto, SID completo, id de Meta ni centinela fuera de las columnas que el contrato guarda a propósito (ids de Meta de la asociación en ad_account_id/campaign_id: 3 filas; huella en phone_hash)
- ✓ laravel.log (8170 bytes): sin teléfonos completos, huellas de teléfono, secretos, SID completos ni centinelas

### E15 — 10/10

- ✓ el segundo Super Admin entra por la API con las 4 llaves del H6
- ✓ el Administrador tiene campaigns_manage y canary_manage y NO canary_authorize ni integrations_manage (CrmPermission::byRole)
- ✓ CRM (Administrador): Twilio sin «Comprobar Twilio ahora»; el motivo dice que le falta la llave (integrations_manage)
- ✓ CRM (Administrador): la configuración de Twilio sin «Reconocer como autorizada»; el motivo es la llave
- ✓ Administrador: reconocer la configuración (55) → 403 forbidden {required_permission: ultron.integrations_manage}
- ✓ sin sesión: 401 en las 18 rutas
- ✓ lectora (solo ultron.view): 200 en las 8 lecturas y 403 forbidden con la llave de la ruta en las 10 escrituras, aunque el cuerpo venga vacío (la puerta va antes que la forma: Enmienda 4)
- ✓ Administrador: 200 en las 8 lecturas; 403 forbidden en 50 (canary_authorize) y 56 (integrations_manage) antes de buscar el recurso (55 ya se probó)
- ✓ CRM (Administrador, probe on): sin el botón de la sonda y con el motivo (le falta la llave); la última comprobación sí se ve
- ✓ Administrador: autorizar (50) → 403 forbidden {required_permission: ultron.canary_authorize}

### Arnés, reinicios y sesiones del CRM — 39/39

- ✓ arnés H6 · simuladores de Twilio y ElevenLabs en pie y sin una sola petición ni violación hasta aquí (P2: cero red con las llaves por defecto en toda la regresión anterior)
- ✓ arnés H6 · la fase 1 está escrita explícita en el .env (escrituras, sonda y voz apagadas, tope 3, cupo 0) y apunta a los simuladores
- ✓ arnés H6 · ElevenLabs sin piezas en el .env (ELEVENLABS_NOT_CONFIGURED): ni clave, ni agente, ni secreto, ni herramientas; la región ausente o «default» (§I6.2)
- ✓ arnés H6 · Twilio OTP y Meta Ads sintéticos y coherentes (los dos pares de alias iguales, bases oficiales, 3 cuentas; sin valores en el detalle)
- ✓ arnés H6 · espejo de Meta Ads sembrado: 12 campañas en las 3 cuentas conectadas, pasadas en las 3 y el oráculo en h6-seed.json (cuenta 3 vieja → degraded/stale)
- ✓ arnés H6 · el lead de la conversación canaria tiene teléfono y es el wa_id de la semilla (coherencia posible: engine_canary_not_registered, no phone_unusable)
- ✓ arnés H6 · la cuenta Super Admin de la spec entra por la API y tiene todas las llaves ultron.* (y las del H6 que ya estén en el catálogo)
- ✓ F1 · administrador (sesión 1): el Studio carga en «ready»
- ✓ F1 · administrador (sesión 1): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F1 · administrador (sesión 1): sin respuestas 5xx
- ✓ F1 · superadmin (sesión 2): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F1 · superadmin (sesión 2): sin respuestas 5xx
- ✓ F2: servidor y workers reiniciados con las llaves de la fase (8 workers de php -S)
- ✓ F2 · administrador (sesión 3): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F2 · administrador (sesión 3): sin respuestas 5xx
- ✓ F3: servidor y workers reiniciados con las llaves de la fase (8 workers de php -S)
- ✓ F3 · superadmin (sesión 4): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F3 · superadmin (sesión 4): sin respuestas 5xx
- ✓ F3 · administrador (sesión 5): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F3 · administrador (sesión 5): sin respuestas 5xx
- ✓ F3 · administrador (sesión 6): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F3 · administrador (sesión 6): sin respuestas 5xx
- ✓ F3 · administrador (sesión 7): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F3 · administrador (sesión 7): sin respuestas 5xx
- ✓ F3 · superadmin (sesión 8): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F3 · superadmin (sesión 8): sin respuestas 5xx
- ✓ F3 · superadmin (sesión 9): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F3 · superadmin (sesión 9): sin respuestas 5xx
- ✓ F4: servidor y workers reiniciados con las llaves de la fase (8 workers de php -S)
- ✓ F4 · superadmin (sesión 10): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F4 · superadmin (sesión 10): sin respuestas 5xx
- ✓ F5: servidor y workers reiniciados con las llaves de la fase (8 workers de php -S)
- ✓ F5 · superadmin (sesión 11): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F5 · superadmin (sesión 11): sin respuestas 5xx
- ✓ F6: servidor y workers reiniciados con las llaves de la fase (8 workers de php -S)
- ✓ F6: final: el registro queda sin números vivos (1)
- ✓ F6 · superadmin (sesión 12): sin errores de JavaScript ni diálogos del navegador (alert/confirm)
- ✓ F6 · superadmin (sesión 12): sin respuestas 5xx
- ✓ F7: servidor y workers reiniciados con las llaves de la fase (8 workers de php -S)
