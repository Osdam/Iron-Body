# ULTRON STUDIO · Hito 6 · E2E con la pila real: `h6-integrations` (paso 4 del contrato, parte E2E)

**Fecha:** 01-11-2026 (Bogotá). **Autor:** `tester` (E2E). **Repos:** CRM `_worktrees/ultron-h6/Iron-Body_Front` (rama
`feat/ultron-studio-h6`, HEAD `f38699d`) y backend `_worktrees/ultron-h6/Iron-Body` (HEAD `7d2c6d8`: el código es el de
`e3a0fdd`; `7d2c6d8` solo añade documentación). **Tocado:** solo `Iron-Body_Front/e2e/ultron-studio/**` y este informe.
**Sin commit.** Nada del backend ni del módulo del CRM. Nada de producción ni de red real: la valla `fence.cjs`, vacía.

## 0. Resultado (estado al cortar la sesión por límite de uso)

| Pasada | Órdenes | Resultado |
|---|---|---|
| Desarrollo, pila limpia (backend `7d2c6d8`, CRM `f38699d`, solo `h6-integrations`) | `E2E_HOLD=1 bash e2e/ultron-studio/run.sh 7d2c6d8 f38699d h6-integrations` | **243/249** en 1042 s (máquina con carga 48–61 por suites de otros compañeros). Los 6 rojos: **1 defecto real del CRM (H-1)** y 5 de mi spec (la carrera de la sonda hostil, 4 comprobaciones, y un `TypeError` en el barrido de tablas), **ya corregidos** y sin volver a ejecutar en una pasada entera |
| `cierre` justo después, sobre esa pila | `node e2e/ultron-studio/specs/cierre.cjs <run>/context.json` | **12/12**: 17 tablas del motor idénticas (esa pila no corrió `ultron-turn`: compara el principio y el final de `h6-integrations`), 0 5xx en 1019 peticiones, 0 ERROR, colas vacías, 4 simuladores con 0 violaciones, valla vacía, 0 de 12 secretos en 22 registros y 12 tablas |
| Regresión completa (F, G, H4, turno, H5, h6, cierre) | `bash e2e/ultron-studio/run.sh 7d2c6d8 f38699d` | **EN MARCHA al cortar** (`~/.cache/ironbody-e2e/ultron-studio/run-20261101T194617Z-7d2c6d8-f38699d/`): sin cifras verificadas. Resumen: `node e2e/ultron-studio/specs/lib.cjs --summary <run>/context.json` |
| E13 (backend del H5) | `bash e2e/ultron-studio/run.sh 7ba0ce8 f38699d h6-integrations cierre` | **NO EJECUTADO** (el modo H5 de la spec está escrito; `composer.lock` idéntico entre `7ba0ce8` y HEAD, comprobado) |
| Mutaciones dirigidas y la pasada completa repetida | `H6_HASTA=<bloque>` + guion de mutaciones | **NO EJECUTADAS** |

Por escenario en la pasada de desarrollo: E1 16/16 · E2 15/16 (H-1) · E3 19/19 · E4 8/8 · E5 4/4 · E6 18/18 · E7 13/13 ·
E8 10/10 · E9 24/24 · E10 25/25 · E11 24/28 (los 4 de la sonda hostil, carrera de la spec corregida) · E12 13/13 ·
E14 5/5 (+3 que no corrieron por el `TypeError`) · E15 10/10 · E13 sin ejecutar.


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
(`e2e/ultron-studio/specs/h6-integrations.cjs`) y en el resultado de cada pasada. Lo esencial de cada uno, ya verde en la
pasada de desarrollo salvo lo indicado: E1 clasificación de la semilla (`degraded/stale` → `connected_read` tras la
pasada de la cuenta vieja) y `integrations-doctor --expect` 0/1; E2 12 campañas, orden, `possiblyDeleted`, `stale`,
antigüedad ±2 min, filtros, AS-2 y el nombre hostil inerte (H-1 en rojo); E3 asociar (CRM, una petición 201),
`replayed`, `client_request_reused`, `campaign_already_linked`, sustituir por X, `campaign_link_moved`, UPDATE crudo
parado, 404/409 de destinos, desactivar con motivo (CRM), `invalid_transition`, AS-1 422, libro sin ids ni nombres;
E4 47–54 → 409 sin libro ni filas y la página lo explica; E5 el proceso web ve la bandera y las mismas escrituras pasan;
E6 registro desde el CRM con alias NFD → NFC, consentimiento local, huella = oráculo, 422 con los textos fijos,
cuatro ojos por permiso y por identidad (evento deduplicado); E7 activar ≠ quien registró, tope 3, desactivar (CRM),
revocar desde los tres estados, terminal, voz cifrada y borrada (CRM, `cipherErased: true`); E8 la secuencia de
veredictos completa; E9 `none`, `not_applicable`, `mismatch` (off), `match` (X en canario), `mismatch` (Y, desde el
CRM), `stale` tras revertir; E10 never_authorized → reconocer (CRM y API), `config_changed`, deriva `probe` y
`probe.credential`; E11 409 en fase 1, H-1, una pulsación = 5 GET con 0 violaciones y hechos exactos, `reused`,
`replayed`, Q-11, Administrador 403, 401 → `auth_failed` con 1 GET, el 429 con `CACHE_STORE=database` y la cuenta
atrás del CRM; E12 voz y política en orden, 0 peticiones a :8096 por la web y `voice:doctor --verify` (5 modos, 1 GET
cada uno); E14 huellas del motor y P3 idénticas, detector con autoprueba, 875 respuestas y libro limpios; E15 matriz
de I7.1 (401, lectora, Administrador) y sin botones.


## 3. Hallazgos

### H-1 · CRM · el lector estricto mide en UTF-16 y descarta nombres de 255 caracteres con emoji (baja, no bloqueante) — **ROJO**

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
- **Arreglo sugerido (frontend, no lo he tocado).** Medir con `codePointLength` (ya importado en
  `studio-integrations-parse.ts:92`) en `texto()` o al menos en `nombre()`, y un caso de paridad con un nombre de 255
  puntos de código con emoji. Revisar si otros `max` de textos libres del módulo (motivos de 500, alias de 40) tienen
  el mismo desfase.

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
- **Carreras, CHECK y disparadores del H6** más allá de los tres UPDATE crudos de la spec (`uscl_replacement_once`,
  `uscn_revoked_is_final` y el cifrado borrado en la misma sentencia): son de `ops/pgunit.sh` y de las carreras del
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
bash e2e/ultron-studio/run.sh 7d2c6d8 f38699d                              # regresión completa + h6 + cierre
bash e2e/ultron-studio/run.sh 7ba0ce8 f38699d h6-integrations cierre       # E13: CRM del H6 contra el backend del H5
E2E_HOLD=1 bash e2e/ultron-studio/run.sh 7d2c6d8 f38699d none              # pila en pie para mutaciones (parar: SIGTERM al run.sh real, trampa 34)
H6_HASTA=E6 node e2e/ultron-studio/specs/h6-integrations.cjs <run>/context.json   # cortar tras un bloque
```

Ficheros tocados: `e2e/ultron-studio/specs/h6-integrations.cjs` (reescrita), `specs/h6-lib.cjs` (Super Admin con
nombre, oráculos, `writeEnvKeys`), `specs/h6-seed.php` (arreglo de `marketing-calls`), `README.md` (fila de la spec,
trampas 35–40) y este informe.

