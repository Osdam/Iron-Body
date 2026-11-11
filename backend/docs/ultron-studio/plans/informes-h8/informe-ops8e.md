# Informe de OPS8-e (devops) · 10/11-11-2026

**Encargo (lead):** las brechas B-T1, B-T7, B-T13 y B-T14 de `ULTRON_STUDIO_AUDITORIA_9_HITOS.md` §4.1; la orden `contactos on|off` del mando de la voz para `VOICE_CONTACTS_ENABLED` (B-T4, de `h8-hardening`); el re-ensayo del banco con evidencia; la nota datada del runbook de la 4a; y, en una segunda tanda, **el punto 7: un mando para rotar el Auth Token maestro de Twilio del OTP**, B-4 con la lectura del lead y las 5 tareas de voz de B-T5.

**Entorno: solo local (Mac). No es producción.** No entré al servidor, no usé credenciales reales y no hice ninguna petición a ElevenLabs ni a Twilio. Todo corrió en `127.0.0.1`: tres bancos montados desde cero, `~/.cache/ironbody-banco-h8-ops8e{,2,3}`, con sus simuladores y la valla que corta la red de verdad. **Sin commit:** todo está en el árbol de trabajo. **Ningún fichero de la aplicación se tocó.** Para probar `contactos on|off`, en el banco 3 se copiaron un momento tres ficheros de B-T4 de `h8-hardening` y una línea de un cuarto; luego se restauraron. El banco de OPS8-d (`-ops8d`) no se tocó.

## Resumen con cifras

| Qué | Resultado |
|---|---|
| `ensayar-ops8d.sh`, la secuencia completa con la 4a desplegada por el guion (banco 3) | **127 OK · 0 FALLO** (pasadas 1 y 2: 99/3 y 89/1, cortadas por H-35 y H-36, dos artefactos del reloj del banco ya corregidos) |
| El despliegue 4a por el guion, en las tres pasadas | **COMPLETADO, 0 desvíos** las tres veces |
| `ensayar-ops8e.sh` (B-T13, B-T14, 14 workers, contactos, tareas de voz) | **55 OK · 0 FALLO**, tres veces. La última, con B-4 nuevo y las tareas leídas del código |
| `ensayar-otp-token.sh` (la rotación del token del OTP) | **49 OK · 0 FALLO** (antes, 31/18 por un fallo silencioso del mando y 48/1 por el arnés; los dos corregidos) |
| `node sim-pruebas.cjs` (los simuladores) | **93 OK · 0 FALLO** (eran 88; +5 de la cuenta maestra) |
| Pruebas aisladas | modo 4a 38/0 · `smoke-h8.py` 14/0 · B-4 (usuario efectivo) 11/0 |
| Regresión del modo normal del guion (1b) | COMPLETADO, 0 desvíos, en los tres bancos |
| Barrido de secretos de la evidencia (71 ficheros, 1,4 MB) | 0 a revisar; los 4 tokens del ensayo del OTP no aparecen en ninguno de los 1182 ficheros de `ops/` |

## Ficheros (lista exacta; ninguno es código de la aplicación)

| Fichero | Estado | Qué |
|---|---|---|
| `ops/deploy-h8-backend-fase1.sh` | modificado | B-T1: `PREVIO=a608450` y el modo `--fase=4a --autorizacion=<ref> [--sin-worker]`. B-T7: «11 tablas vacías» solo es estricto en el primer despliegue |
| `ops/smoke-h8.py` · `ops/smoke-run-h8.sh` | modificados | B-T7: «ningún perfil» solo es estricto con 0 perfiles antes del smoke; la fase 1 se reconoce por las 11 tablas del H8 (H-29). B-T4: `flags.contacts` y el 409. B-T5: 4 o 5 tareas, según el código |
| `ops/h8-voice-scope.sh` | modificado | `contactos on\|off`; B-T5 (±4 o ±5 tareas, según el código) |
| `ops/h7-monitor-scope.sh` | modificado | B-T13: `on` se niega sin expectativa confirmada. **B-4: el usuario EFECTIVO de `schedule:run`**. B-T14: 600 o 640. 13 o 14 workers |
| `ops/h6-integrations-scope.sh` | modificado | B-T14: 600 o 640. 13 o 14 workers |
| **`ops/h8-otp-token-rotate.sh`** | **nuevo** | Punto 7: la rotación del Auth Token maestro del OTP (`estado`, `rotar`, `vuelta`) |
| `ops/banco-h8/sim-twilio.cjs` · `sim-pruebas.cjs` | modificados | La cuenta maestra del simulador (sonda de la rotación, `/__maestro`, aviso de gasto del OTP) y sus 5 pruebas |
| **`ops/banco-h8/ensayar-otp-token.sh`** · **`ensayar-ops8e.sh`** | **nuevos** | Los ensayos de OPS8-e |
| `ops/banco-h8/ensayar-ops8d.sh` · `ensayar-ops8c.sh` · `ensayar.sh` · `preparar.sh` · `comun.sh` | modificados | La 4a por el guion y sus negativos; H-35 y H-36; las órdenes `h6\|h7\|otp`, `respaldo` y `--fase=4a`; las copias del banco de los mandos; `tareas_voz_banco` (sin «4» fijo) |
| `ops/README.md` · `ops/banco-h8/README.md` | modificados | Notas datadas y filas nuevas |
| `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` | una nota datada en «Fase 4a-primero» | El procedimiento de la 4a, la expectativa del H7 por etapa, B-4 comprobado y la referencia a B-6 |
| `../ops/ROTACION-CREDENCIALES.md` (`backend/docs/ops/`) | una nota datada al final | Twilio: el procedimiento de la rotación con los pasos del propietario |
| `plans/informes-h8/informe-ops8e.md` (este) · `informe-devops.md` (§OPS8-e, resumen y enlace) | nuevo · modificado | |
| `ops/evidencia-h8/2026-11-10/ops8e/` | nuevo, 71 ficheros | Con `00-README.md` y `SHA256SUMS` |

## Qué cambió, por brecha

### B-T1 🔒 · el commit `voice(calls-open)` ya tiene vía de despliegue

`deploy-h8-backend-fase1.sh` admite **`--fase=4a --autorizacion=<referencia escrita> [--sin-worker]`**. El modo normal no cambia: sigue exigiendo la constante a `false` en `origin/main`; lo ensayé y aborta con «la fase 1 no despliega la constante abierta». `PREVIO=a608450`.

- **Base del modo 4a.** Es el commit del respaldo de hoy (`$BK/api-commit.txt`). Producción tiene que estar en él, o ya en el de la 4a si se está reanudando, y tiene que **contener** `PREVIO`. Así vale con producción en `ce7c150` (H9).
- **El commit** (`comprueba_4a_git`, solo lectura) tiene que cumplir todo esto:
  - asunto que empieza por `voice(calls-open): ` y un solo padre;
  - el mensaje cita **literalmente** la referencia de la autorización;
  - un solo commit así en el tramo, sin merges;
  - el diff es exactamente **un fichero, una línea fuera y una dentro**: `= false;` → `= true;`, con la misma sangría.
- **Lo que puede acompañarlo.** Solo documentación, commit a commit, y cada uno aceptado con `--acepta-main`. Cualquier código en medio aborta.
- **Exige además:**
  - la fase 2 completa: 14 piezas por presencia, la telefonía en `ELEVENLABS_VERIFIED`, la expectativa de la fase 2, sin deriva y con las guardas del H8 en la base;
  - las llamadas cerradas;
  - el worker en RUNNING, o `--sin-worker` declarado.
- **Lo que hace y comprueba.** No escribe el fichero de entorno ni migra. Comprueba que **solo** cambió la constante: configuración efectiva, telefonía, rutas, programador, esquema, filas, instantáneas y fichero de entorno, con 0 ERROR. La referencia de la autorización queda en la cabecera y en el cierre del registro.
- **Vuelta atrás:**
  - nivel 0: `salientes off`;
  - nivel 4: el código anterior (checkout del commit del respaldo, cachés y `queue:restart`). Ensayada.

### B-T7 · las expectativas caducas tras la fase 1

- **Paso 4 del guion.** «Las 11 tablas vacías» solo es estricto en el primer despliegue. En los demás, las filas tienen que ser las mismas que en el paso 0.
- **Smoke de la fase 1.** «Ningún perfil de voz» solo es estricto con 0 perfiles antes del smoke (`SMOKE_VOICE_PROFILES_BEFORE`). Si hay alguno, es información. La regla «ninguna escritura» no cambia.
- **H-29** (nuevo): el lanzador exigía exactamente «18 y 7» tablas. Por las 7 `ultron_studio_*` del H9, **hoy abortaría el smoke del H8 en cualquier fase**. Ahora reconoce la fase 1 por las 11 tablas del H8, por nombre.

### B-T13 · el monitor del H7 y su expectativa, y B-4

- `h7-monitor-scope.sh on` **se niega** (código 2, sin tocar nada) si el monitor en seco de las integraciones no sale con 0. Apagar nunca depende de eso.
- Para fijar la expectativa: `h8-voice-scope.sh expectativa fase1|fase2|llamadas`. La tabla por etapa está en el plan de despliegue, «Fase 4a-primero».
- **B-4: no es una brecha.** El lead leyó producción (solo lectura, 11-11-2026): el crontab de root lleva `cd /var/www/api/backend && sudo -u www-data /usr/bin/php artisan schedule:run >> storage/logs/schedule.log`, `www-data` no tiene crontab y `/etc/cron.d` no tiene ninguna línea con `artisan`. El programador corre de verdad como `www-data`, y no hay nada que mover.
  - **Lo que cambia en el repo.** El mando ya no avisa por estar «en el crontab de root»: mira el **usuario efectivo** de cada línea activa. Root con `sudo -u www-data` o `runuser -u` cuenta como `www-data`, y en `/etc/crontab` y `/etc/cron.d` el usuario es el sexto campo. Solo avisa si algo corre de verdad como root.
  - Con la línea de producción, `estado` diría `www-data=1 · correcto: sí`.
  - Lo probé con 11 crontabs sintéticos: 11/0.
  - Lo que yo había escrito antes («tiene que estar en el crontab de `www-data`; si no, el paso 6b») está corregido en mi nota del plan, en `ops/README.md` y aquí. La H-1 de OPS7 del plan (una línea de root **sin** `sudo`) no la reescribí: mi nota la aclara.

### B-T14 (C-14) · el fichero de entorno en 640

`h6-integrations-scope.sh` y `h7-monitor-scope.sh` admiten exactamente lo mismo que `h8-voice-scope.sh`: `www-data` 600 o `root:www-data` 640, nunca un enlace, también al apagar. Con 644, otro dueño u otro grupo siguen abortando.

Añadí también la espera de 13 workers, o 14 con `ironbody-voice` instalado. Sin eso, los dos mandos darían un desvío falso desde la etapa 0.10, también al apagar.

### `contactos on|off` (B-T4)

`h8-voice-scope.sh contactos on|off` escribe `VOICE_CONTACTS_ENABLED` con la maquinaria común del mando, y `estado` la enseña.

- Se niega si el código desplegado no lee la bandera (hoy no la lee).
- `contactos on` avisa de que es de la fase 4b.
- **Nombres comprobados contra el código final de `h8-hardening`:** `VOICE_CONTACTS_ENABLED` → `voice.contacts.enabled` (`config/voice.php:276-277`), `flags.contacts` (`VoiceTelephonyStatus.php:52`) y el 409 `voice_contacts_disabled`.
- El smoke admite las 5 banderas, o las 5 más `contacts` (apagada), y el 409 cuando la bandera está apagada.

### B-T5 (de `h8-hardening`) · 5 tareas de voz, no 4

- El mando de la voz y el lanzador del smoke leen del código desplegado cuántas son (`artisan list`).
- **Confirmado con el árbol actual** (solo lectura, configuración fresca): `VOICE_MONITOR_ENABLED=true` programa exactamente 5 (27 → 32): `integrations-snapshot --verify-voice`, `voice:prune-rate-limits`, `voice:reconcile`, `voice:reconcile --numbers` y **`voice:webhook-health`**. Con ese código, `h8-voice-scope.sh` cuenta ±5.
- **Banco:** `comun.sh` trae `tareas_voz_banco`. `ensayar-ops8c.sh:219-220`, `ensayar-ops8d.sh:533-534` y `:652`, y `ensayar-ops8e.sh` (sección 6) ya no fijan 4. En el banco salen 4 (`a608450`).

### Punto 7 · la rotación del Auth Token maestro de Twilio del OTP (`ops/h8-otp-token-rotate.sh`, nuevo)

**Por qué.** ElevenLabs guarda ese token como secreto del workspace (`twilio_token_account_AC936142bc…`): el número …5483 se importó con él (A-1/H-14 de `ULTRON_STUDIO_PROVEEDORES_ESTADO_REAL.md`). Es la recomendación B-6 (P-4 del contrato).

**Órdenes:**
- `estado`: solo lectura.
- `rotar <fichero> [--solo-comprobar]`.
- `vuelta <copia previa> [--solo-comprobar]`.

Está calcado de `h8-voice-scope.sh` («fase 2 secreto»): mismo escritor, misma copia previa en `/root`, mismo `config:cache` como `www-data` y **mismo cerrojo**, porque los dos escriben el fichero de entorno.

- **Las dos claves y la sangría.** `otp.twilio.token` lee `TWILIO_TOKEN` y, si no está, `TWILIO_AUTH_TOKEN`. El mando cambia las que haya, cada una con su sangría y su `export`, y no añade ninguna. Una clave repetida (gana la última) se niega.
  - El `grep ^TWILIO_` del lead no las vio porque en ese servidor **227 asignaciones llevan dos espacios de sangría** (`docs/ops/ROTACION-CREDENCIALES.md`). El banco reproduce esa forma y `estado` la enseña (línea, sangría, `export`, comillas y longitud; nunca el valor).
  - Comprobé que phpdotenv 5.6.3 acepta la sangría, `export`, `CLAVE = valor`, las comillas y los comentarios, y que con una clave repetida gana la última.
- **Paso 0, de solo lectura.** Si algo falla: código 2 y no se toca nada.
  - El fichero del token: root, 600, una línea, 32 hex en minúscula, nunca por argumentos. Un token tecleado como argumento se niega antes de mirar nada más.
  - C-14 y el cerrojo.
  - El OTP con Twilio: `driver=twilio`, cuenta `AC`+32.
  - **La caché igual al fichero de entorno, bloque a bloque y el token incluido.** Se compara con HMAC de un solo uso y con la configuración fresca por `APP_CONFIG_CACHE`. Así se evita que el `config:cache` de la rotación publique otros cambios a medias o deje el OTP sin token.
  - El token nuevo distinto del actual.
  - **Un GET de solo lectura** a `/2010-04-01/Accounts/{sid}.json` con el token **nuevo** (tiene que dar 200, la misma cuenta y `active`) y con el **actual** (si da 401, aviso: la vuelta atrás ya no existe). El token va a curl por su entrada (`-K -`). El cuerpo, que trae el token principal, se lee en memoria y se descarta.
  - Los workers.
- **Después.** Copia previa → solo las dos líneas → el fichero es la copia salvo esas líneas → parsea → `config:cache` como `www-data` → la configuración efectiva tiene el token nuevo y **el resto igual, bloque a bloque** → la caché es igual al fichero → **GET con el token efectivo de Laravel** → `queue:restart` → workers → 401 → 0 ERROR. Con 0 desvíos dice «APLICADO» y destruye el fichero del token (`shred -u`).
- **`vuelta <copia>`.** Restaura solo el token de la copia, y solo si Twilio lo sigue aceptando. Al promocionar el nuevo en la consola, el viejo deja de valer y el mando se niega sin tocar nada. Por eso `vuelta … --solo-comprobar` sirve para confirmar la promoción.
- **Quién más lo nota** (el mando lo dice):
  - el aviso de gasto del OTP (`TwilioUsageTriggerController` valida con `otp.twilio.token`; Twilio firma con el token **principal**): entre escribir el nuevo y promocionarlo, ese aviso responde **403**;
  - la huella de Twilio del Studio (`authToken.fp`): pasa a «drift» hasta reconocerla;
  - ElevenLabs;
  - la voz, si usara la misma cuenta o este token como token de firma (hoy no: `TWILIO_VOICE_*` no existen).
- **La vista del proceso web.** El mando es CLI y no comparte el OPcache del FPM, y `opcache.validate_timestamps` del pool sigue sin comprobar (C-8). Por eso imprime la huella del Studio antes y después. El propietario comprueba en el CRM que el proceso web ve la nueva **antes de promocionar**. Si no la ve, recargar FPM con autorización y volver a mirar. El mando no recarga FPM ni supervisor.
- **Por qué no usa `lib-env.sh`** (`scripts/rotation/`, el kit que ya existe):
  - cumple sus tres reglas (nunca por argumentos, probar antes de escribir, copia y vuelta atrás);
  - pero su `env_set` no ve `export CLAVE=` ni `CLAVE =` y añade la clave que falta, lo que con dos alias cambiaría cuál gana;
  - y su `reload_consumers` reinicia `php8.3-fpm` y todo supervisor, incluido `ironbody-voice`.
- **El procedimiento con los pasos del propietario** (consola de Twilio, la vista web, la promoción, ElevenLabs) está en `docs/ops/ROTACION-CREDENCIALES.md`, en la nota datada de OPS8-e.

## Qué ensayé y con qué resultado

- **Sintaxis:**
  - `bash -n`, también con el bash 3.2 del Mac, de los 13 guiones tocados;
  - `node --check` de `sim-twilio.cjs` y `sim-pruebas.cjs`;
  - `py_compile` de `smoke-h8.py`, con el `.pyc` fuera del repo.
- **Pruebas aisladas:**
  - las expansiones del modo 4a: 38/0;
  - la lógica nueva de `smoke-h8.py`: 14/0;
  - B-4: 11/0;
  - H-29, en solo lectura sobre una base con el esquema del H9: la comprobación vieja da FALLO y la nueva, OK;
  - las tareas de voz: 4 en el banco y 5 en el árbol.
- **Tres pasadas de `ensayar-ops8d.sh`**, cada una en un banco montado desde cero.
  - **La final (banco 3): 127 OK · 0 FALLO.**
  - Punto de partida, B-T1 y B-T7:
    - el modo normal con la constante abierta aborta;
    - el modo 4a sin la fase 2 aborta;
    - el smoke nuevo con un perfil, sin FALLO funcional;
    - el smoke de antes en el mismo estado da exactamente el FALLO de E16-30.
  - Fases 2 y 2a.
  - **La 4a, con 14 negativos que no tocan nada y dos `--solo-comprobar` EN VERDE**, y el despliegue: `DESPLIEGUE H8 FASE 4a (voice(calls-open) 830eaf3, autorización «OPS8E-AUT-4A-20261111») COMPLETADO`, 0 desvíos. «Solo cambió la constante» y las instantáneas siguen 11 → 11.
  - La saliente de punta a punta: puerta 202 → `dialing` → llamada → post-llamada firmada `completed`, 42 s → coste conciliado 42 100 µUS$ → la segunda del día, `daily_cap_reached`.
  - **La vuelta atrás del código del runbook.**
  - Cierre con 0 violaciones y registros sin secretos.
  - Las pasadas 1 y 2 se cortaron por H-35 y H-36, pero las dos completaron la 4a por el guion con 0 desvíos.
- **`ensayar-ops8e.sh`: 55 OK · 0 FALLO**, tres veces. La primera, 51/2, destapó el `case` dentro de `$(…)` que rompe el bash 3.2 en `h7 estado`, y una copia de más del arnés. La última, con el B-4 nuevo y las tareas leídas del código. Cubre:
  - **B-T13:** deriva, expectativa del código y `expectativa fase2`;
  - **B-T14:** 640 aplica, 644 aborta, también al apagar;
  - 14 workers;
  - **contactos:** 409 → 422 → 409, con 0 filas;
  - **tareas de voz:** 4 en el banco.
- **`ensayar-otp-token.sh`: 49 OK · 0 FALLO** (2 min).
  - **12 negativos** sin tocar nada (fichero de entorno byte a byte): token por argumento, fichero del token en 644, 31 hex, mayúsculas, dos líneas, un token que Twilio no conoce (401), fichero de entorno en 644, `TWILIO_TOKEN` repetida, un cambio sin cachear en otro bloque (`APP_NAME`), el token del fichero distinto del de la caché, una «copia» que no es de este mando; y el token de ahora, «nada que escribir».
  - **Ciclo A:**
    - `--solo-comprobar` en verde: GET 200 con el nuevo y con el actual;
    - `rotar` APLICADO: solo las líneas 20 y 21, con sangría 2; la copia previa 600 byte a byte; el resto de la configuración igual; GET 200 con el token efectivo; 13 workers dos veces; 401; 0 ERROR;
    - **la huella que da la API del banco** (el proceso web) **es la que imprimió el mando**;
    - **la ventana**: sin promocionar, el aviso de gasto del OTP firmado con el principal da **403**;
    - `vuelta` antes de promocionar deja el fichero **byte a byte** y el aviso vuelve a 200.
  - **Ciclo B**, con Twilio reconocida:
    - `rotar` pasa el Studio de `authorized` a **`drift` en `authToken`**, y la vista web lo confirma;
    - al promocionar, el aviso da 200;
    - `vuelta` se **niega con 401** sin tocar nada (`--solo-comprobar` y de verdad).
  - **El banco como estaba:** byte a byte y `authorized`.
  - 0 violaciones nuevas en el simulador.
  - Ningún token en el registro, en los de Laravel, en el del simulador ni en el de la valla.
  - La **primera ejecución (31/18)** destapó **H-37**. La segunda (48/1) solo tuvo un supuesto del arnés.
- **`sim-pruebas.cjs`: 88 → 93 OK, 0 FALLO.** Nueva:
  - la sonda (curl, cuenta maestra): 200 sin violación; con otro token, 401 sin violación;
  - la ficha maestra sin curl o con la key de Laravel sigue siendo `account_sid_mismatch`;
  - el aviso del OTP firmado con el principal sobre la URL real;
  - la promoción: el viejo da 401 y la maestra contra la subcuenta sigue siendo violación.
- **La regresión del modo normal del guion (1b):** COMPLETADO, 0 desvíos, en los tres bancos.

## Hallazgos (siguen la numeración de OPS8-d)

- **H-29 · El smoke del H8 abortaría hoy en producción** («18 y 7» exactas frente a las 7 tablas nuevas del H9). Corregido. Cualquier `smoke-run-h8.sh` viejo en `/root` lo sigue teniendo: hay que copiar el nuevo.
- **H-30 · Producción no está en `PREVIO`.** Sirve `ce7c150` (L9-77) y `origin/main` es `d701b0c`, solo documentación. El modo 4a lo admite con `--acepta-main=d701b0c`. Está **por confirmar** con el comando de solo lectura de abajo.
- **H-31 · Los mandos del H6 y del H7 esperaban 13 workers fijos.** Corregido: 13 o 14.
- **H-32 · Los efectos de `h8-hardening`** (`flags.contacts`, el 409 y la 5.ª tarea) se resolvieron para las dos versiones del código. Queda su recomendación de los registros `channel`/`voice` en 0660, sin hacer.
- **H-33 · La vista de FPM.** El guion de la 4a comprueba la constante en el proceso CLI; la del proceso web la comprueba el smoke 4a por HTTP. Si FPM no viera el fichero nuevo, la puerta seguiría denegando: falla cerrado.
- **H-34 · Banco.** Con el Mac a carga 60–124, los presupuestos de rendimiento no se pueden medir. El veredicto separa los FALLO de tiempo. En producción el smoke no cambia.
- **H-35 · Banco.** El reloj de la costura se fijaba al empezar el ensayo y dejaba la marca de verificación «en el futuro». Ahora se fija al arrancar la segunda API.
- **H-36 · Banco.** Las sesiones de administración (12 h) caducaban para la segunda API. Costura del banco: `ADMIN_SESSION_TTL_MINUTES=4320`.
- **H-37 · El mando nuevo salía en silencio** con `COPIA="…$([ "$ORDEN" = vuelta ] && echo …)…"`: con `set -e`, la sustitución sale con 1 y el guion termina sin mensaje. Pasaba **antes de copiar ni escribir**: falla cerrado, pero mudo. El ensayo lo cazó a la primera. Corregido, y busqué el mismo patrón en todos los guiones de `ops/`: ningún otro lo tiene.
- **H-38 · La rotación del token maestro toca a cuatro lectores, no a uno:**
  - el OTP;
  - **el aviso de gasto del OTP** (403 hasta promocionar: la ventana tiene que ser de minutos);
  - **la huella de Twilio del Studio** («drift»: hay que reconocerla, o el monitor del H7 da `config_drift:twilio`);
  - **ElevenLabs** (A-1/H-14): hay que reimportar el número **con una credencial restringida** (D-2/D-3), no con el token maestro nuevo.
- **H-39 · El documento de proveedores dice que las credenciales del OTP «no están en el fichero de entorno del backend».** Con 227 asignaciones con sangría, lo más probable es que estén con dos espacios. Si de verdad no estuvieran, el próximo `config:cache` de **cualquier** mando o despliegue dejaría el OTP sin token y nadie podría entrar con OTP. `h8-otp-token-rotate.sh` se niega en ese caso («el token del fichero de entorno NO es el que tiene la caché»). Se confirma con el comando de solo lectura de abajo.

## Qué NO verifiqué, y por qué

- **Nada en el servidor ni en las cuentas reales** (fuera de mi alcance):
  - el HEAD real de producción (H-30);
  - la forma de las claves `TWILIO_*` del `.env` de producción (H-39);
  - **`opcache.validate_timestamps` del FPM (C-8 / N-4 a)**. Es decisivo para la rotación: si vale 0, el proceso web seguiría con el token viejo hasta recargar FPM, y al promocionar el OTP fallaría en todos los inicios de sesión. Por eso el paso 5 del procedimiento (la vista web) va **antes** de promocionar. En el banco no hay FPM ni OPcache: la API es `php artisan serve`.
- **El comportamiento real de la consola de Twilio.** Que el secundario autentica junto al principal, que Twilio firma con el principal y que, al promocionar, el viejo deja de valer está **modelado en el simulador según la documentación de Twilio**, no comprobado contra Twilio. Confirmarlo en la consola el día de la rotación.
- **ElevenLabs.** La reimportación del número y el borrado del secreto viejo son pasos del propietario; no se ensayaron.
- **La 4a sobre un esquema del H9 de punta a punta.** El banco corre `a608450`. El modo 4a no depende de recuentos fijos, y H-29 se probó en solo lectura sobre un esquema del H9.
- **El código de `h8-hardening` (sin commit).** B-T4 se probó copiado un momento al banco. B-T5, con la detección y con `schedule:list` del árbol (5 tareas), no con el monitor de voz encendido de verdad.
- **`ensayar-ops8c.sh` y `ensayar-ops8d.sh` no se volvieron a ejecutar** tras quitar el «4» fijo. El cambio es mecánico, y la misma construcción pasó en `ensayar-ops8e.sh` (55/0) con `tareas_voz_banco` = 4. Rehacerlos cuesta un banco nuevo y unos 45 min.
- **El veredicto de `security`** sobre B-T1 🔒 y sobre el mando de la rotación (credencial maestra): pendiente.

## Impacto y vuelta atrás

- **Hoy, ninguno.** No se ejecutó nada en el servidor ni en las cuentas. Los cambios están en el árbol, sin commit. Los bancos viven fuera del repo.
- **Cuando se usen**, cada uno con su autorización:
  - **la 4a** es una línea de código que no enciende ninguna llamada. Vuelta atrás: el nivel 0 o el código anterior, ensayados;
  - **los mandos del H6 y del H7**: su vuelta atrás sigue siendo la copia previa exacta que imprimen;
  - **la rotación del token**: interrumpe el OTP **solo** si el proceso web no ve la caché nueva y se promociona igual (por eso el paso 5). Hay una ventana de 403 en el aviso de gasto del OTP hasta promocionar. **Lo irreversible es la promoción en la consola:** antes de ella existe `vuelta` (ensayada, byte a byte); después, no.
- **Vuelta atrás de estos cambios en el repo:** `git diff` de los ficheros listados. Las copias de partida están fuera del repo.

## Acciones pendientes de autorización (comandos exactos; ninguno ejecutado)

**1. Solo lectura, en el servidor como root.** Ninguno escribe nada.

```bash
# H-30: qué sirve producción
ssh ironbody-vps 'echo "HEAD $(git -C /var/www/api rev-parse --short=7 HEAD) · origin/main $(git -C /var/www/api rev-parse --short=7 origin/main) · cambios $(git -C /var/www/api status --porcelain --untracked-files=no | wc -l)"'
# H-39: dónde y cómo están las claves de Twilio del OTP en el .env (el valor sale como <oculto>; cat -A enseña la sangría)
ssh ironbody-vps 'grep -nE "^[[:space:]]*(export[[:space:]]+)?TWILIO_(SID|ACCOUNT_SID|TOKEN|AUTH_TOKEN)[[:space:]]*=" /var/www/api/backend/.env | sed -E "s/=.*/=<oculto>/" | cat -A'
# C-8 / N-4 a (devops): OPcache del FPM (la configuración del binario FPM y lo que fijen los pools)
ssh ironbody-vps 'php-fpm8.3 -i 2>/dev/null | grep -E "^opcache\.(enable|validate_timestamps|revalidate_freq) "; grep -HnE "opcache\.(enable|validate_timestamps|revalidate_freq)" /etc/php/8.3/fpm/php.ini /etc/php/8.3/fpm/conf.d/*.ini /etc/php/8.3/fpm/pool.d/*.conf 2>/dev/null'
```

- Con `validate_timestamps = 1` y una `revalidate_freq` corta, el proceso web ve cada caché nueva en segundos, y queda cerrado C-8.
- Con 0, cada cambio de configuración (todos los mandos) necesita `systemctl reload php8.3-fpm`, que es una recarga productiva y necesita autorización. El paso 5 de la rotación se vuelve obligatorio.

**2. Copiar a `/root`** los guiones nuevos, cuando se vayan a usar, con el repo en el commit que los lleve y las huellas comparadas: `deploy-h8-backend-fase1.sh`, `smoke-run-h8.sh` + `smoke-h8.py`, `h8-voice-scope.sh`, `h6-integrations-scope.sh`, `h7-monitor-scope.sh` y **`h8-otp-token-rotate.sh`**. Los viejos de `/root` tienen H-29, C-14 y 13 workers.

**3. La rotación del token del OTP** (orden del propietario, con el token nuevo). Sigue la tabla de `docs/ops/ROTACION-CREDENCIALES.md` (nota OPS8-e): `estado` → secundario en la consola → fichero sin pantalla ni historial → `rotar … --solo-comprobar` → `rotar …` → **la vista web** → OTP → **promocionar** → `vuelta … --solo-comprobar`, que tiene que negarse con 401 → OTP → reconocer Twilio en el Studio → **ElevenLabs con una credencial restringida**.

```bash
bash /root/h8-otp-token-rotate.sh estado
( umask 077; read -rsp 'token: ' T; echo; printf '%s\n' "$T" > /root/otp-token-nuevo; unset T )
bash /root/h8-otp-token-rotate.sh rotar /root/otp-token-nuevo --solo-comprobar
bash /root/h8-otp-token-rotate.sh rotar /root/otp-token-nuevo
```

**4. La 4a** (con su autorización y el commit `voice(calls-open)`): el procedimiento de la nota del plan. Respaldo → `bash /root/deploy-h8-backend-fase1.sh <commit> "$BK" --fase=4a --autorizacion='<referencia>' --acepta-main=<commits de documentación> --solo-comprobar` → lo mismo sin `--solo-comprobar`.

**5. `h7-monitor-scope.sh on off`** (A-7): antes, `bash /root/h7-monitor-scope.sh estado` (expectativa confirmada; B-4 ya está bien) y, si hace falta, `bash /root/h8-voice-scope.sh expectativa <fase>`. Si la rotación del token ya se hizo, reconocer antes Twilio en el Studio.

**6. `h8-voice-scope.sh contactos on`:** es de la fase 4b. Solo con el código de B-T4 desplegado, D-12 y la supresión 1581 de la voz (B-T3).

## Riesgos residuales y deuda

- **`security` debe revisar dos cosas:**
  - B-T1: la única vía para abrir las llamadas en el código;
  - el mando de la rotación: maneja la credencial maestra; revisar el paso por tuberías, el cuerpo del GET que se descarta y las copias previas en `/root`, que guardan el token viejo, 600, hasta que se borren.
- **B-6 queda a medias.** El contrato pide además una **API key restringida para Verify y SMS** en lugar del token maestro. Eso es un cambio de código (`config/otp.php` y los dos remitentes) de `backend`. La rotación reduce el daño de la exposición (A-1/H-14), pero el OTP sigue usando una credencial maestra.
- **C-8 sin cerrar** afecta a todos los mandos, no solo a este. Comando arriba.
- **La documentación que acompaña a la 4a viaja con ella** (`--acepta-main`). Y `git checkout --detach` en su vuelta atrás deja `main` del servidor en el commit de la 4a: nadie debe hacer `git checkout main` allí hasta subir el commit que revierta la línea.
- **H-28** (el `409 config_changed` aislado al reconocer) sigue sin causa raíz; el arnés reintenta.
- **Pendientes de otros dueños:**
  - B-T3 (la supresión 1581 de la voz, `database-specialist`);
  - los registros `channel`/`voice` en 0660 (de `h8-hardening`);
  - el texto de `ULTRON_STUDIO_H8_PREPARACION_PLAN_PAGO.md` 4a.7 y de la activación 0.11, para que apunten al modo 4a (`documentation-specialist`);
  - `ULTRON_STUDIO_PROVEEDORES_ESTADO_REAL.md` (H-39), si la lectura de la acción 1 lo confirma.

## Dónde quedan los bancos

| Banco | Puertos | Base | Estado |
|---|---|---|---|
| `~/.cache/ironbody-banco-h8-ops8e` | 8441–8445 | `ultron_studio_banco_h8_ops8e` | parado |
| `~/.cache/ironbody-banco-h8-ops8e2` | 8451–8455 | `ultron_studio_banco_h8_ops8e2` | parado |
| `~/.cache/ironbody-banco-h8-ops8e3` | 8461–8465 | `ultron_studio_banco_h8_ops8e3` | parado al cerrar este informe |

- **Lo que deja el ensayo del OTP en el banco 3:** Twilio reconocida en el Studio, las copias `root/env-pre-otp-token-*` (600) y los tokens del ensayo en `tokens/otp-nuevo-*` (600, para el barrido).
- **Limpiarlos** (no lo he hecho): `bash ops/banco-h8/ensayar.sh <banco> parar`, borrar el directorio y `dropdb -h 127.0.0.1 -p 55432 -U ironbody <base>`. El PostgreSQL desechable (55432) es de otro agente.
- **Repetir:** los bloques de `ops/banco-h8/README.md`, §OPS8-e y «segunda tanda».
