# Hito 8 · OPS8-a (paso 1) · informe de devops: simuladores, valla, banco y worker de voz

**Fecha:** 03-11-2026.
**Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`.
**Entrega técnica:** confirmada por el lead en `fa5be95f`. Este informe y el README del banco van **sin commit**.
**Entorno:** **solo local (Mac). No es producción.** No se tocó ningún servidor, ninguna credencial real ni la red. Todo corrió en `127.0.0.1` y en el PostgreSQL desechable (`127.0.0.1:55432`).

## 1. Qué entrego

| Fichero | Qué es |
|---|---|
| `docs/ultron-studio/ops/banco-h8/sim-elevenlabs.cjs` | Simulador de ElevenLabs. Lado proveedor: **13 rutas** con `xi-api-key`. Lado emisor hacia Laravel: iniciación, herramientas con Bearer y post-llamada firmada (21 variantes). 59 códigos de violación, cada uno con su cláusula |
| `docs/ultron-studio/ops/banco-h8/sim-twilio.cjs` | Simulador de la subcuenta de voz de Twilio: **10 rutas** con Basic, credenciales por clase con la matriz de permisos de D8-5, y el emisor de avisos de gasto (`X-Twilio-Signature` sobre la base canónica, 13 variantes). 23 códigos de violación |
| `docs/ultron-studio/ops/banco-h8/sim-comun.cjs` | Común a los dos: registro JSONL sin secretos, fallos inyectables por ruta, emisión solo a `127.0.0.1` y firmas |
| `docs/ultron-studio/ops/banco-h8/valla.cjs` | La valla de red: un proxy que bloquea y anota el host. Es la del E2E del CRM, traída al backend |
| `docs/ultron-studio/ops/banco-h8/sim-pruebas.cjs` | Pruebas propias: 73 casos con oráculos independientes y el contraste con los fixtures de TEST8-a |
| `docs/ultron-studio/ops/banco-h8/{comun,montar,sembrar,ensayar}.sh` | El banco, calcado del H7: «producción» en `cfcd0d1`, base `ultron_studio_banco_h8_*` y puertos 8401–8404 |
| `docs/ops/supervisor/ironbody-voice.conf` | **Borrador** del worker `ironbody-voice` (carril `voice`, `tries=1`, `numprocs=1`, `timeout=60`, `stopwaitsecs=90`). **Sin instalar** |
| `docs/ultron-studio/ops/banco-h8/README.md` | **(este paso)** Cómo se monta, siembra y ensaya; los puertos; la superficie simulada 13 + 10 y los emisores; la correspondencia pieza → variable |
| `docs/ultron-studio/plans/informes-h8/informe-devops.md` | **(este paso)** Este informe (nombre de E1-11) |

## 2. Verificado (comandos ejecutados el 03-11-2026, con su salida)

### Pruebas propias (`node sim-pruebas.cjs`)

**73 OK · 0 FALLO · 0 OMITIDO**, en la última ejecución, con los simuladores ya alineados con B8-a y TEST8-a. Además, las pruebas provocan violaciones a propósito y las cuentan una a una: ElevenLabs 46 y Twilio 22.

**Oráculos independientes:**
- **Firma de ElevenLabs.** Las 21 variantes del emisor las verifica la clase **real** del H6, `ElevenLabsWebhookSignature::verify`, y el resultado sale como el esperado en las 21.
- **Firma de Twilio.**
  - 3 vectores de la documentación y los SDK de Twilio, comprobados recomputando el HMAC: `0/KCTR6DLpKmkAf8muzZqo1nDgQ=`, `RSOYDt4T1cUTdK1PDd93/VVr8B8=` y el de `bodySHA256` `a9nBmqA0ju/hNViExpshrM61xv4=`.
  - Un diferencial de 8 casos contra una segunda implementación en Python, con claves repetidas, duplicados y UTF-8.

**Contraste con los fixtures de TEST8-a** (`tests/fixtures/voice/`):
- `twilio-signature-vectors.json`, vectores de cálculo: **15/15**, los 6 oficiales de `twilio-python` incluidos.
- Vectores de validación: **25/25** con la tolerancia de puerto de DF-T2. Esto se midió con un arnés aparte; **no está en la suite**.
- `elevenlabs-webhook-vectors.json`: el firmador da **la misma cabecera** en los **8** válidos canónicos (los otros 3 «ok» son formas no canónicas).
- `elevenlabs-webhook-bodies.json` y `twilio-webhook-bodies.json`: coinciden **clave por clave**:
  - el cuerpo de la post-llamada (`data` y `metadata`);
  - la cabecera `X-Voice-Initiation-Secret` y los 5 campos de la iniciación;
  - el cuerpo `{conversation_id, call_sid}` de la herramienta;
  - los 10 parámetros del aviso de gasto, en su orden.
- En la suite quedan **3 casos permanentes** de contraste. Si los fixtures no existen, quedan OMITIDO.

### Mutaciones

**11 de 11 cazadas.** Rompí una regla en una copia (en el scratchpad, sin tocar el repo) y comprobé que alguna prueba se ponía roja:

| Mutación | Resultado |
|---|---|
| m1 · firma de ElevenLabs sin el punto | 65 OK · 2 FALLO |
| m2 · Twilio sin ordenar las claves | 66 · 1 |
| m3 · sin `unknown_route` | 66 · 1 |
| m4 · colgar sin vigilar | 66 · 1 |
| m5 · fuga del Bearer al registro | 66 · 1 |
| m6 · la valla no anota el CONNECT | 66 · 1 |
| m7 · iniciación sin la revisión del override | 66 · 1 |
| m8 · firma sobre la URL real | 65 · 2 |
| m9 · grabación sin vigilar | 66 · 1 |
| m10 · sin la regla de los 7 días | 66 · 1 |
| m11 · la API del banco arrancada sin la valla | el ensayo `valla` da «0 de 5» → 6 OK · 1 FALLO |

Las m1–m10 corrieron sobre la suite de **67** casos, **antes** de alinearla con B8-a y TEST8-a. **No las he repetido sobre la de 73.** Las reglas que tocan no cambiaron, pero eso es una hipótesis hasta repetirlas.

### El banco (`~/.cache/ironbody-banco-h8`, base `ultron_studio_banco_h8_ops`)

- **`montar.sh`: 12 s.** Salida:
  - 56 rutas del Studio y 3 instantáneas de sistema;
  - `voice:doctor` = `ELEVENLABS_NOT_CONFIGURED`;
  - 227 tablas con datos en el respaldo.
- **`ensayar.sh valla`: 7/7.**
  - https a fuera → `ConnectionException`; http → 403 de la valla; las dos sondas quedan anotadas.
  - PHP alcanza los dos simuladores **sin** la valla (200 200), y no entró tráfico local por ella.
  - Los 5 procesos de la API llevan `https_proxy`. El control negativo es válido: 0 coincidencias en un proceso sin `RED` y 1 en la API.
- **`ensayar.sh doctor`: 7/7.** Lo que se probó es el **adaptador real del H6** (el de `cfcd0d1`) contra el simulador:
  - fase 1 sin red: `NOT_CONFIGURED`, 0 peticiones;
  - con las piezas en el entorno de **un** proceso, `voice:doctor --verify` da `credentials_ok`, código 0 y `ulaw_8000`, con **una** petición (`GET /v1/convai/agents/{agent_id}`), 0 violaciones y la valla sin cambios.
- **`ensayar.sh worker revisar`: 13/13** propiedades del borrador.
- **`ensayar.sh worker comprobar`: PENDIENTE** (código 3). El error literal es `InvalidArgumentException: The [voice] queue connection has not been configured.` Pasa en `cfcd0d1` y en el origen `57bab8a`, y ahora es de **B8-b** (E1-10).
- **`ensayar.sh worker instalar` / `retirar`:** `supervisorctl status` pasa de 13 a **14** RUNNING y vuelve a **13**.
- **`ensayar.sh cierre`: 4/4.** 0 violaciones en los dos simuladores y la valla solo con sus 2 sondas.
- **Emisores contra la API real del banco:** la post-llamada y el aviso de gasto dan **404**, como se espera: esas rutas son de B8-b. La valla siguió en 2.
- **`arrancar`/`parar`:** idempotentes, con los puertos 8401–8404 libres al parar.

## 3. Impacto, vuelta atrás y autorizaciones

- **Impacto en producción: ninguno.** Son ficheros de `docs/` y `ops/`; el worker es un borrador que nadie instala sin la orden del propietario (fase 1, OPS8-b).
- **Vuelta atrás:**
  - de la entrega: revertir `fa5be95f` (solo documentación y herramientas del banco, sin efecto en la aplicación);
  - de este paso: borrar los dos ficheros nuevos;
  - del banco: `bash ops/banco-h8/ensayar.sh $B parar; rm -rf $B; dropdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_banco_h8_ops`. Solo afecta al banco desechable; el prefijo `ultron_studio_banco_h8_` impide recrear otra base.
- **Pendiente de autorización: nada** de OPS8-a. Instalar `ironbody-voice` en el servidor es de OPS8-b, en la fase 1, con la orden del propietario. Los pasos de instalar y retirar están en la cabecera del borrador.

## 4. Lo que cambió en el contraste con B8-a y TEST8-a (ya en `fa5be95f`)

- **API de herramientas.** B8-a crea las herramientas con `POST /v1/convai/tools` y las referencia en `prompt.tool_ids`; las de sistema van en `built_in_tools`. El simulador añadió `tools_create` y `tools_get`, con su almacén, revisa cada herramienta **una vez** (al crearla) y marca `tool_id_unknown` para un id que no emitió.
- **Sin `PATCH` ni `DELETE` de agentes.** B8-a no tiene ninguno (D8-7), así que ahora son violación.
- **Detección de contestador.** `twilio_machine_detection` llega como objeto en B8-a y pasa a Twilio como `MachineDetection=Enable`.
- **Patrones.** El uuid usa el patrón de B8-a, sin exigir versión.
- **Números sintéticos** con la convención de TEST8-a: +57 601 000 00xx y +57 300 000 00xx.
- **Tramo de transferencia** como llamada hija con `parent_call_sid` (DF-W4).
- **Lo que no he hecho:** **no he ejecutado los adaptadores de B8-a contra el simulador.** Eso es de B8-b y del E2E de TEST8. El contraste fue de lectura: rutas, verbos, cabeceras y formas.

## 5. Para OPS8-b, B8 y TEST8: hallazgos, riesgos y deuda

1. **E1-12 cambia el estado de partida del banco.** `montar.sh` modela la producción de antes de las 22:55 UTC: `ELEVENLABS_NOT_CONFIGURED`, sin piezas. OPS8-b tiene que montar el estado real antes de ensayar `deploy-h8-backend-fase1.sh`:
   - las 4 piezas del H6, el secreto de las herramientas y `ELEVENLABS_ENABLED=true`, tomados de `sim/piezas.conf`;
   - un `integrations-snapshot --verify-voice` contra el simulador.

   Así se ensaya que el despliegue **no aborta** al encontrarlas y que el smoke y el monitor esperan `ELEVENLABS_VERIFIED`, que es lo que pide E1-12. El caso «fase 1: NOT_CONFIGURED» de `ensayar.sh doctor` describe el banco, no la producción de hoy.
2. **El aviso del simulador es el de antes de E1-3.** La constante `AVISO` de `sim-elevenlabs.cjs` es el primer turno del agente en los guiones y lleva el texto de P-9 de la congelación. Si la guarda posterior compara con el texto de E1-3, el guion `limpio` saldría «sin aviso». Es una línea, pero no la cambio aquí porque este paso es solo documentación. Lo hace quien abra el siguiente cambio de los simuladores, a la vez que TEST8-a reexporta `world.p9Notice`.
3. **Los ensayos del banco no se han repetido** tras los últimos cambios de los simuladores (API de herramientas, sin `PATCH`, contestador, uuid, números). Las pruebas propias sí (73/73). Hay que repetirlos al empezar OPS8-b: `ensayar.sh $B arrancar`, `valla`, `doctor`, `cierre`.
4. **El `supervisorctl` del banco no modela el FATAL** del worker de voz. Sin la conexión `voice`, el worker real quedaría en FATAL tras `startretries`, y el sustituto lo enseña RUNNING. Lo ve `worker comprobar` (PENDIENTE), no `status`. El despliegue de OPS8-b tiene que comprobar la conexión **antes** de copiar el fichero, y su ensayo debería simular el FATAL, como `sup-backoff` en el H6.
5. **Rutas y formas por confirmar en la referencia oficial (D8-3):**
   - 6 de las 13 rutas de ElevenLabs: listar agentes, números y conversaciones, leer número y conversación, y borrar conversación;
   - la forma de la respuesta de `POST /v1/convai/tools` (el simulador devuelve `{id, tool_config, access_info, usage_stats}` y B8-a solo lee `id`);
   - el 429 de concurrencia de ElevenLabs;
   - el código de una key restringida de Twilio sin permiso (el simulador da 401 con el código 20003);
   - la respuesta al colgar una llamada ya terminada;
   - el formato de `DateFired` y de `IdempotencyToken`;
   - las unidades de `metadata.cost`.

   Están marcadas en el código y en `GET /__surface`.
6. **La valla solo ve HTTP.** Por eso el banco pone `MAIL_MAILER=log`, y en el banco no hay Redis. Un socket crudo no pasaría por ella. Es un límite declarado, igual que el del E2E.
7. **Secretos.** Ninguno real en ningún sitio:
   - las piezas son aleatorias, en 600, fuera del repo, y no viajan por la línea de órdenes ni por el entorno de los simuladores (se leen de `--config`);
   - el barrido de higiene comprueba que los registros no llevan ninguna.

   Lo que sí queda abierto es de E1-12: **la clave de ElevenLabs de producción viajó por el chat y hay que rotarla antes de la fase 3.** La rotación es del propietario y exige su autorización.
8. **Estado del banco.** Está montado y **parado**, en `~/.cache/ironbody-banco-h8` y con la base `ultron_studio_banco_h8_ops`, para que OPS8-b lo reutilice. Cómo limpiarlo, en el §3.

---

# §OPS8-b (paso 3) · despliegue de la fase 1, smoke, monitor de voz, simulador y runbook — 08-11-2026

**Entorno:** el banco local (Mac) y **dos lecturas de producción autorizadas por el lead**, por SSH y como `www-data`:
- `git rev-parse HEAD`;
- `migrate:status | tail`.

Nada más en el VPS. **No es un despliegue: nada del H8 está en producción.** Sin commit.

## Ficheros (lista exacta)

| Fichero | Estado |
|---|---|
| `docs/ultron-studio/ops/deploy-h8-backend-fase1.sh` (nuevo) | `PREVIO=32a2560`. Detecta por parche los commits ajenos y **solo sigue con `--acepta-main=<commits>`** (decisión del lead). **Ensayado en el banco** |
| `docs/ultron-studio/ops/h8-schema-check.sql` (nuevo) | Solo lectura (recuentos de E3-5, privilegios y cerrojos). Las mismas consultas van dentro del script |
| `docs/ultron-studio/ops/deploy-h8-crm-fase1.sh` (nuevo) | Con `--solo-comprobar`. Ver «CRM», abajo |
| `docs/ultron-studio/ops/smoke-h8.py` y `smoke-run-h8.sh` (nuevos) | **Ensayados en el banco** |
| ~~`docs/ultron-studio/ops/presupuestos-h8.json`~~ | **Retirado** al llegar `ops/perf-h8/presupuestos-h8.json` de PERF8 (11 rutas, con medidas), que cubre todas las mías. Mis `maxBytes` se quedaban cortos con datos reales (aviso de PERF8). El smoke, el banco y el runbook usan el de PERF8 |
| `routes/console.php` | El bloque de voz: +42/−0 |
| `tests/Feature/Voice/VoiceMonitorScheduleTest.php` (nuevo) | 4 pruebas |
| `docs/ultron-studio/ops/banco-h8/{sim-elevenlabs.cjs,sim-pruebas.cjs,comun.sh,montar.sh,ensayar.sh,README.md}` y `banco-h8/preparar.sh` (nuevo) | El simulador (E1-5 y la post-llamada web) y el ensayo de OPS8-b |
| `docs/ultron-studio/ULTRON_STUDIO_DEPLOYMENT_PLAN.md` | La sección «Hito 8» (fases 1 a 4b, respaldos E3-7, rotación, secretos) |

## Verificado, con su salida

- **Producción (solo lectura):**
  - HEAD `32a2560202383a8b79122802b288a3b69eca1344`;
  - `2026_11_06_000001_add_benefit_only_to_plans_table`: `[133] Ran`.
- **Simulador:** `node sim-pruebas.cjs` da **78 OK · 0 FALLO · 0 OMITIDO** (73 de antes y 5 nuevas). Las violaciones provocadas a propósito son 50 de ElevenLabs y 22 de Twilio.
- **Programador:** `VoiceMonitorScheduleTest`, 4/4. Antes, 55 pruebas del programador y de las guardas (`UltronStudioMonitorTest`, `UltronEngineIsolationTest`, `VoiceBoundaryTest` y otras): 0 fallos.
- **Banco** (`~/.cache/ironbody-banco-h8-ops8b`: producción de juguete en `7c84cb5`, origen `13fed36` = HEAD `bb239b5` + mi `console.php` + la unión de `7c84cb52`):
  - **`e112` 4/4:**
    - verificación `credentials_ok` con un GET y nada por la valla;
    - el Studio dice `ELEVENLABS_VERIFIED` y el proveedor neutro `ELEVENLABS_UNVERIFIED`;
    - respaldo rehecho.
  - **`desplegar --solo-comprobar`: `COMPROBACIONES PREVIAS EN VERDE`**:
    - 227 tablas;
    - puerta: los 4 controladores públicos, el job y `console.php`;
    - esquema `44 50 4 14 0 0 0 0`;
    - programador 27/0/0;
    - VERIFIED con 719 h por delante en el banco.
    El primer intento abortó por mi propio `e112` (la huella del volcado con ruta relativa): corregido.
  - **`desplegar`: `DESPLIEGUE H8 FASE 1 (BACKEND) COMPLETADO`**, 0 desvíos:
    - esquema `68 84 6 18 27 51 3 7`;
    - 4 funciones del dueño y ninguna DEFINER;
    - `ra---s` en las 6 tablas de solo inserción y `rawd-s` en las 5 con estados;
    - rutas `77 18 8 10 15 6 13 5 8 4` y `auth:coverage` vacío;
    - la configuración efectiva toda apagada;
    - el motor, el H4–H7 y la voz iguales que antes;
    - 13 workers;
    - todos los 401, 413 y 403 de la sanidad;
    - el monitor con el mismo veredicto;
    - `voice:doctor --telefonia` el de la fase 1;
    - instantáneas 6 → 6 (la huella de voz no cambia, S8-b 9);
    - 0 ERROR.
  - **Smoke** (`smoke-run-h8.sh` del repo, con la valla como prueba estricta de cero red):
    - con los presupuestos de **PERF8**: **49 OK · 0 FALLO · 1 OMITIDO**, código 3, el esperado; lo único omitido es `GET voice-lab/sessions` (409 `voice_lab_disabled`, el laboratorio apagado), y los presupuestos dan 10 OK y 1 OMITIDO;
    - con los provisionales, antes de retirarlos: 49 OK · 0 FALLO, código 0;
    - en los dos, el lanzador da en verde: voz apagada, programador 27/0/0, `voice:doctor --telefonia` el de la fase 1, monitor sin deriva, 25 tablas idénticas antes y después, en el canal `voice` solo los 4 rechazos esperados, 0 ERROR, valla 0 → 0 y la cuenta en reposo;
    - **INFO:** una post-llamada de exactamente 1 048 576 bytes llega a Laravel (401). En el banco no hay nginx: en el servidor, el smoke dirá quién responde;
    - las dos primeras pasadas fallaron y destaparon H-12: el login daba 429 tras las peticiones anónimas a la telefonía, y las POST anónimas daban 429 tras ~38 con sesión. Corregido en el smoke: el login va primero y la telefonía tiene su cubo de 25/min.
  - **`--acepta-main`** (decisión (2) del lead), con el código real del script en una copia del banco con `PREVIO=32a2560`:
    - `--solo-comprobar` sin la bandera lista «PENDIENTE DE DECISIÓN: 7c84cb5 · 1279016» (el original y su copia) y sigue;
    - el despliegue sin la bandera aborta con la lista;
    - `--acepta-main=abcdef1` aborta («no está entre los ajenos»);
    - `--acepta-main=7c84cb5,1279016` los anota como ACEPTADOS y continúa;
    - además, con los refs reales, de 42 commits con parche solo `1279016` no está en producción (las otras 7 copias se reconocen por parche).

## Hallazgos (para el lead y para B8)

- **H-10 · Bloqueante. La rama perdió la ascendencia de producción** con la reescritura del 08-11-2026.
  - `32a2560` (producción) y `7c84cb52` (`origin/main`) **no son ancestros** del HEAD; las copias tienen el mismo árbol y otros hashes, y la base común con `origin/main` es `d828bf7`.
  - Así, el push a `main` no es de avance rápido y el paso 0 aborta («no es avance rápido»).
  - **Arreglo, del lead:** unir `origin/main` en la rama con un merge normal, sin `--force`. En el clon del banco salió limpio.
- **H-11 · Decidido por el lead. `7c84cb5` no está en producción** (`MembershipRebuilder`, sin migración) y viajaría con el H8. El paso 0 lo detecta por parche, aunque esté copiado, y solo sigue si el propietario pasa `--acepta-main=7c84cb5` al desplegar (ensayado: las 4 ramas). El lead corrige la ascendencia (H-10); después de su arreglo debería salir solo `7c84cb5`, sin la copia.
- **H-12 · Para B8, antes de la fase 1. Las 13 rutas del CRM de la telefonía usan `throttle:120,1` y `throttle:30,1`.** El throttle corre antes de que el controlador autentique (`VoiceActor::require`), así que el contador es **el de la IP, con sesión o sin ella**, y es el mismo cubo que el del login del CRM (`throttle:8,1`).
  - **Medido en el banco:** tras 9 GET anónimos a `admin/voice/status`, el login da **429**; tras 9 a una ruta del Studio (limitador con nombre), el login da 401 normal.
  - En el smoke, tras ~38 peticiones a la telefonía en un minuto (con sesión), las POST anónimas dieron 429 (cupo 30).
  - **Impacto:** personas en la misma red (la oficina o el Wi-Fi del gimnasio) comparten el cupo de la telefonía y gastan el del login. Basta una pestaña de «Voz» abierta para poder dejar a la oficina sin login un minuto.
  - **Arreglo:** limitadores con nombre, por credencial, como `marketing-agenda` o `ultron-studio-read`. El smoke ya marca el ritmo en ese cubo (25/min) y hace el login primero.
- Siguen en pie H-2 a H-9. Las decisiones (a) a (f) del lead ya están en el runbook:
  - (b) cada estado de ElevenLabs en su sitio;
  - (c) la verificación antes del 03-12-2026 a las 22:55 UTC: **el monitor del Studio solo avisa, no renueva**; renuevan el `--verify-voice` a mano o el monitor de voz;
  - (d) la expectativa del monitor del H7, en el entorno y con su procedimiento, sin cambiar el defecto;
  - (e) sin `composer install` ni el worker en la fase 1;
  - (f) la salud del webhook, para B8 antes de la fase 3.

## CRM

`bash -n` OK. **`--solo-comprobar` ensayado en un clon aislado** del CRM (scratchpad, `node_modules` por clonación APFS; el árbol de FE8 no se tocó), contra la API del banco: `BUILD VERIFICADO`.
- El backend del H8 responde 401, no 404.
- El árbol, limpio en `26ef142` = su `origin/main`, contiene `c9eef6d`.
- Sin cambios de dependencias: `npm ls` OK.
- `build:prod` en 42,6 s, con el sello `26ef142` limpio.
- El chunk de voz es `chunk-TIMQWAQ4.js` (**264 343 B, 71 136 B con gzip -9**, como midió FE8) y el del tablero `chunk-27WCULYR.js`, con `data-voz-enlace` y la ruta; los importa el main del build.
- La publicación (respaldo, `rsync` sin `--delete` y verificación pública) **no se ensayó**: necesita el VPS.

## Paso 0 repetido tras la ascendencia corregida (HEAD `2b3c293` = `f7ae3a3` + OPS8-b)

El banco se rehízo con producción en **`32a2560`** (la real), `BANCO_WIP=0` (solo lo confirmado; B8-c tiene cambios sin confirmar) y sin `BANCO_UNIR`, porque la rama ya contiene a `32a2560` y a `7c84cb5`. Estado de E1-12: 4/4.
- **`--solo-comprobar`, sin la bandera:** «PENDIENTE DE DECISIÓN: … `7c84cb5` «Un cobro que no da vigencia no es la membresía de nadie»». Es el **único** ajeno: sin copias. Termina en «COMPROBACIONES PREVIAS LEÍDAS, FALTA DECIDIR los commits ajenos [7c84cb5]», con **código 3**.
- **`--solo-comprobar --acepta-main=7c84cb5`:** «commits ajenos ACEPTADOS … 7c84cb5» y **`COMPROBACIONES PREVIAS EN VERDE`**, con **código 0**.
- En los dos casos: `HEAD 32a2560 limpio`, las 2 migraciones del H8 pendientes y la puerta con solo los ficheros del contrato.

**A la espera de B8-c** (avisará el lead): `voice:prune-rate-limits` cada hora en el bloque de voz de `routes/console.php`, su caso en `VoiceMonitorScheduleTest` y otro `--solo-comprobar` del banco sobre el HEAD final.

## F-6 y O-2 (encargo de TEST8-b vía el lead)

- **F-6, simulador** (`ops/banco-h8/sim-comun.cjs`, `pareceDatoPersonal`): la cola de un uuid aleatorio (`…-eb3443320330`) se tomaba por un móvil, porque se quitaban todos los guiones y el borde admitía letras pegadas.
  - **Corregido sin aflojar la detección:**
    - los uuid se quitan enteros antes de buscar;
    - solo se unen dígitos separados por separadores de teléfono;
    - un número colombiano (3 + 9 o 60 + 8) no admite letras ni dígitos pegados;
    - el E.164 se busca tras unir.
  - **Medido** sobre 20 000 uuid aleatorios: la versión del HEAD daba **57 falsos teléfonos (0,28 % por uuid)**, la corregida **0**. El ejemplo de TEST8 pasa de `true` a `false`.
  - **2 pruebas nuevas:** los uuid con colas de 10 dígitos y los 20 000 aleatorios dan 0; los teléfonos de prueba (`+573000000011`, `+57 300 000 0011`, `300-000-0011`, `(601) 000 0011`, `601.000.0011`…) y un correo se siguen detectando.
  - `node sim-pruebas.cjs`: **80 OK · 0 FALLO**, con las mismas violaciones provocadas (50 y 22).
- **O-2, runbook:** en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, Hito 8, fases 2 → 4b, la comprobación del secreto de iniciación **antes de asignar el número**.
  - La iniciación de prueba se hace con la herramienta de ElevenLabs o desde el servidor, sin imprimir el valor, y deja una fila `denied` sintética como evidencia.
  - Para el diagnóstico: `voice.inbound.unauthorized` con `has_header`, sin fila en `voice_calls`, `unknown` en la conciliación, freno e incidentes.
  - El remedio y quién suelta el freno.

## Pendientes de la fase 2 en adelante (listados, no hechos ahora; con su dueño)

| Pendiente | Antes de | Dueño |
|---|---|---|
| El mando `h8-voice-scope.sh` (mueve las banderas y las piezas de las fases 2–4b en el mismo fichero, con copia previa, `config:cache` como `www-data`, 600/640 por C-14 y la expectativa del monitor por fase) | Fase 2 | OPS8 |
| nginx: la lista de IP de salida de ElevenLabs (P-26, refuerzo) y que la post-llamada de 1 MB pase (el INFO del smoke en el servidor) | Fase 3 (la lista, al decidir P-26) | OPS8, con autorización (`nginx -t` y `reload`) |
| La sección de voz de `ops/supresion-1581.sql` (huellas, últimos dígitos, cifrados, evidencias y notas; `voice_forbid()` y `ultron_studio_forbid()`) | Fase 3 (primeros datos personales) | OPS8 + `database-specialist` |
| Limitadores con nombre por credencial en las 13 rutas del CRM de la telefonía (H-12) | Fase 1 (recomendado) | B8-c (ya avisado) |
| La salud del webhook automatizada (decisión (f)) | Fase 3 | B8 |
| El lector de la última verificación en la telefonía (E4-6) | Fase 3 | B8 + SEC8 |
| Instalar el worker `ironbody-voice` (borrador en `docs/ops/supervisor/ironbody-voice.conf`; antes, comprobar la conexión `voice`) | Fase 4a | OPS8, con autorización |
| Rotar la clave de ElevenLabs (E2-2) y registrar los dos secretos propios sin imprimirlos (procedimiento en el plan) | Fase 2 | Propietario, con OPS8 |
| La regresión de los smokes del H6 y del H7 adaptada al H8 (cuentan 14 tablas y `TAREAS`) | Fase 2 | TEST8 / OPS8 |

## Notas

- **`voice:prune-rate-limits` (cada hora)** al bloque de voz de `routes/console.php`, cuando B8-c lo entregue: el lead avisará. Va en el bloque bajo `VOICE_MONITOR_ENABLED` (o en el general, si B8-c lo quiere siempre) y lleva su prueba en `VoiceMonitorScheduleTest`.
- **Cambios en curso de otros agentes** (sin confirmar mientras ensayaba): B8-c añade el índice NO parcial `vc_publication_idx` en la `100002`. Según su comentario, los recuentos de E3-5 no cambian, y el script solo cuenta índices parciales. También toca `config/voice.php`, `AppServiceProvider` (el oyente de la post-llamada del laboratorio) y servicios de Telephony. Mi ensayo es sobre `bb239b5` + mi `console.php`: **hay que repetir el `--solo-comprobar` del banco con el HEAD final**, tras el arreglo de la ascendencia (H-10).
- El banco `~/.cache/ironbody-banco-h8-ops8b` (base `ultron_studio_banco_h8_ops8b`, puertos 8411–8414) queda montado y **parado**. Para limpiarlo: `rm -rf` del directorio y `dropdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_banco_h8_ops8b`. El PostgreSQL desechable lo arrancó otro agente: no lo toqué.

## No verificado, y por qué

- En el servidor no se ensayó nada: solo las dos lecturas. El `--solo-comprobar` de producción es el paso 4 del runbook, ya con H-10 y H-11 resueltos.
- La regresión de los smokes del H6 y del H7 tras el H8 no se ensayó: cuentan 14 tablas y `TAREAS`, y no aplican sin adaptar.
- El mando `h8-voice-scope.sh` (fases 2–4b), nginx (P-26 y el tope de 1 MB en el servidor real) y la supresión 1581 de voz quedan pendientes de OPS8.

## Cierre del lead (08-11-2026 22:46 UTC): ensayo de la fase 1 sobre el HEAD final `8d3c41f`

Banco rehecho desde cero (`montar.sh … HEAD`, `BANCO_PREVIO=32a2560`, `BANCO_WIP=0`; ESPERADO=`8d3c41f`), «producción» del banco en el estado de E1-12 y sembrada:

| Paso | Resultado |
|---|---|
| Paso 0 sin bandera | código 3: «FALTA DECIDIR los commits ajenos [7c84cb5]», sin tocar nada |
| Paso 0 con `--acepta-main=7c84cb5` | `COMPROBACIONES PREVIAS EN VERDE`; HEAD `32a2560` limpio; 2 migraciones del H8 pendientes |
| Despliegue completo (banco) | `DESPLIEGUE H8 FASE 1 (BACKEND) COMPLETADO` |
| Smoke (presupuestos de PERF8) | 49 OK · 0 FALLO · 1 OMITIDO (`voice-lab/sessions`, 409 `voice_lab_disabled`: lo esperado en la fase 1); 10 presupuestos OK |

Con esto, el guion de la fase 1 queda ensayado sobre el código que se desplegará. En el VPS no se ha ejecutado nada.

