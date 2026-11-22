# Informe de devops · enmienda 20: los mandos de la voz y el runbook para el número empresarial como origen (22-11-2026)

**Por decisión expresa del propietario del 22-11-2026** (literal: `MATRIZ_CIERRE_V2.md` §9). Diseño: `diseno-arq-telefonia.md` (opción C); backend: `informe-be-telefonia.md` (§6, §8, §11). Rama `integracion/voz` sobre `16948226`. **Entorno: solo el Mac (banco local). Nada en producción: ni `ssh`, ni Twilio, ni ElevenLabs, ni red** (la valla no vio ningún host de fuera). `CALLS_ENABLED_IN_CODE` sigue en `false`. Solo toqué `backend/docs/ultron-studio/ops/` y este informe.
Commits: `54878b7e` (mando de voz) · `a64561a4` (comprobación de Twilio) · `45a83309` (banco) · `562f9e57` (runbook e índice) · el de este informe.

## 1. Qué cambió (líneas sobre `562f9e57`)
**`ops/h8-voice-scope.sh`** (`231e4a8a…` → **`6bd98ae2…`**):
- `:75-83` cabecera «ENMIENDA 20»; `:8-27` uso (`uso()` `:120`). `:141` `leer_voz` gana tres hechos de CONFIGURACIÓN: `shared_known`, `shared_declared` y `from_is_business` (con `method_exists`).
- `:148` `compartida()`: el veredicto del servidor, `VoiceNumberGuard::sharedIdentity()`, fuera de `leer_voz` (depende del reloj y de la pasada horaria; `leer_voz` se compara byte a byte). `:157` `lee_compartida()`: los ocho motivos en español; **solo** `{declared: true, admitted: true, reason: null}` cuenta como admitida (lo demás, también un motivo desconocido o tinker caído, NO).
- `:201` resumen del doctor: `whatsapp=COMPARTIDO|CHOCA|no`. `:412` y `:417` `estado`: declarada (sí/no/el código no la lee), si `TWILIO_VOICE_FROM` es el visible, y el veredicto con su motivo; nunca el número (cuatro cifras como mucho).
- `:782` `fase 2 set` rechaza la variable (solo por su orden). `:791` el `INBOUND_CAPABLE` del entorno resultante; `true` con la declaración, nunca. `:801` el número de WhatsApp: `visible` solo con la declaración y «Outbound Only»; otro protegido, nunca; código sin `sameNumber()`, se niega.
- `:817` `fase 2 secreto --terminal` (`read -rs`, exige `[ -t 0 ]`, valores solo en memoria y por la entrada estándar); `:1048` su escritura.
- `:888` `salientes on`: con el número de WhatsApp, solo con la admisión del servidor. `:913` `entrantes on`: con la declaración, nunca. `:957` `numero-compartido on|off`. `:1022` `monitor on` dice `compartida=` de la conciliación en seco. `:1095` y `:1105` el antes → después. `:1127` la comprobación posterior. `:1195-1199` avisos.
**`ops/h8-twilio-check.sh`** (`e06a9e71…` → **`db842269…`**): `:23` cabecera; `:84` lo declarado en el fichero (booleanos; `sameNumber` con `method_exists`); `:197` el visible pasa de FALLO a INFO solo con la declaración, «Outbound Only», las entrantes apagadas y como caller ID; `:263-265` lo que NO comprueba; `:273` las condiciones de la cuenta principal; `:285` **«VEREDICTO: APTA · cuenta principal»** (código 0); `:295` si falta algo, código 3 y qué falta.
**Banco** (`ops/banco-h8/`): `ensayar-ops8f.sh` (nuevo); `sim-elevenlabs.cjs` control `/__numero` (`phone`, `supports_inbound`, `agente`); `sim-twilio.cjs` `numero=` y `compartido=`; `sim-pruebas.cjs` sección M; `README.md` «OPS8-f». Sin el control, los simuladores responden como antes (los usa el E2E del H8). **Runbook** reescrito en su parte de Twilio y ElevenLabs (marcas «(E20)») e índice `ops/README.md`.

## 2. Protecciones
**Cambiadas** (solo la restricción de compartir el número, cada una con su línea del diseño):
1. `fase 2 set` (`:801`; diseño §7.3 «`:721-726`»): el VISIBLE se escribe solo con la declaración ya puesta y «Outbound Only» resultante. La pieza se escribe; la admisión no.
2. `salientes on` (`:888`; diseño «`:778`»): `collision=false` **o** `admitted=true` leído del servidor (obliga a conciliar antes).
3. Comprobación posterior (`:1127`; diseño «`:968`»): admitida → OK; declarada y solo pendiente de conciliar (`observation_unverified`, `observed_not_business_number`) → **AVISO**, no desvío (la política sigue negando); tras `numero-compartido off` → lo esperado; cualquier otro motivo → desvío, como hoy.
4. `h8-twilio-check.sh` (`:197` y `:285`; diseño «`:177`» y §6): el visible como INFO con sus condiciones, y la cuenta principal como APTA con las suyas.
**Nuevas negativas:** `entrantes on` con la declaración (diseño «`:797`»), `INBOUND_CAPABLE=true` con ella, la variable por `fase 2 set`, `--terminal` sin terminal, y las de `numero-compartido on`.
**NO tocadas:** la constante y su commit `voice(calls-open)`; el worker `ironbody-voice`; las piezas que exige `salientes on`; la reserva de la puerta (C-1); cupo 1–5 y presupuesto 0,01–20; alcance `canary`; C-14; los cerrojos; copia previa 600; escritura atómica; `config:cache` como `www-data` en 600 (lo escribe su dueño, así que no hace falta `chown`: el mando comprueba dueño y modo); `queue:restart`; workers en dos lecturas; 401; 0 ERROR; `monitor on` en seco; el bloque `hechos`; el rechazo del Account SID, de la key del OTP y de una key con mensajería. Consentimiento, canarios, horario, frecuencia y freno los decide el servidor y no cambian.

## 3. Órdenes nuevas
- `numero-compartido on --autorizacion=<ref A0>` → «== AUTORIZACIÓN ESCRITA DEL PROPIETARIO (referencia citada): «…»», «shared_declared: false → true», «AVISO (enmienda 20): la declaración SOLA no admite nada…», «MANDO numero-compartido-on APLICADO (…; autorización citada: «…»)». Se niega (código 2) sin la cita, con las llamadas encendidas, sin el código o sin «Outbound Only».
- `numero-compartido off` → «la declaración está RETIRADA: la política deniega…», el AVISO del freno `number_is_messaging` con el monitor encendido, «MANDO numero-compartido-off APLICADO». No espera a nadie.
- `fase 2 secreto --terminal` → dos preguntas sin eco, «claves escritas (sin valores): TWILIO_VOICE_API_KEY_SECRET TWILIO_VOICE_API_KEY_SID», «MANDO fase2-secreto APLICADO». **En el servidor hace falta una terminal real (`ssh -t`).**
- Ninguna abre llamadas ni habilita entrantes.

## 4. Banco (local, sin red)
`bash ops/banco-h8/ensayar-ops8f.sh ~/.cache/ironbody-banco-h8-ops8f montar|arrancar|ensayar`: copia de la «producción» del banco de telefonía, base propia por plantilla, código en `16948226` con sus 10 migraciones como `h5_owner`, valla y los dos simuladores. Cada pasada rehace la base. Tres pasadas seguidas iguales (14:36, 14:49 UTC y la de antes sin la sección del código anterior: 77/77). Última, sobre el árbol limpio en `562f9e57`:
```
OK      numero-compartido on sin --autorizacion → se niega  [código 2]
OK      fase 2 set del visible de WhatsApp SIN la declaración → se niega (protección 6)  [código 2]
OK      fase 2 secreto --terminal: ni el SID ni el secreto aparecen en la terminal (sin eco)
OK      fase 2 set del visible con la declaración y «Outbound Only» → APLICADO, PENDIENTE de conciliar (aviso, no desvío)  [código 0]
OK      estado: declarada sin observar → observation_unverified; whatsapp=CHOCA  [código 0]
OK      twilio · cuenta principal, key restringida, caller ID = el visible declarado → APTA · cuenta principal (código 0)  [código 0]
OK      twilio · con TWILIO_VOICE_SIGNING_TOKEN en la cuenta principal → no apta (código 3)  [código 3]
OK      monitor on · importado con un agente asignado → se niega (observed_agent)  [código 2]
OK      monitor on · importado que no es el visible → se niega (number_mismatch, observation_unverified)  [código 2]
OK      salientes on · observada como entrante → se niega (observed_inbound)  [código 2]
OK      salientes on --cupo=1 --presupuesto=2.00 con la identidad ADMITIDA → APLICADO (todo lo de antes se sigue exigiendo)  [código 0]
OK      entrantes on · con la identidad compartida declarada → se niega por su nombre  [código 2]
OK      la vuelta atrás EXACTA que imprime «off» deja el fichero byte a byte como su copia previa
OK      código anterior · numero-compartido on → se niega (el código no la lee)  [código 2]
OK      cierre · el registro del ensayo no lleva ningún teléfono completo (solo las cuatro últimas cifras)  [0 líneas]
87 OK · 0 FALLO · registro ~/.cache/ironbody-banco-h8-ops8f/logs/ensayo-ops8f-20261122-144952.txt
```
`node ops/banco-h8/sim-pruebas.cjs`: **95 OK · 0 FALLO · 0 OMITIDO** (93 + 2). `bash -n` de los tres guiones y `php -l` del PHP de la comprobación: limpios.

## 5. Secuencia para el lead (el día del despliegue y de la primera llamada)
| # | Qué (runbook) | Cuándo | Autorización |
|---|---|---|---|
| 1 | Lote 3 con `deploy-cierre-lote.sh --orden=migracion-primero` (paso −1, C-0) | Primero | La específica del propietario para el lote 3 |
| 2 | Instalar los dos mandos y comprobar las huellas; R1–R6 y `estado` (paso 0) | Tras 1 | A0 |
| 3 | P en Twilio: saldo, caller ID …5483 verificado, permisos geográficos de voz solo Colombia, key L y key E (pasos 1–5a) | Tras 2 | A0 |
| 4 | `monitor off`; `fase 2 secreto --terminal` con P pegando la key L (6.1) | Con la ventana de la key abierta | A0 |
| 5 | P en ElevenLabs: borrar la importación antigua (token maestro) y reimportar con la key E, solo saliente y sin agente (5b, 5c) | Tras 4 | A0 |
| 6 | `fase 2 set VOICE_NUMBER_INBOUND_CAPABLE=false TWILIO_VOICE_ACCOUNT_SID=… ELEVENLABS_AGENT_PHONE_NUMBER_ID=<nuevo>` (6.2) | Tras 5 | A0 |
| 7 | `numero-compartido on --autorizacion=<ref A0>` (6.3) | Tras 6 | A0 (citada) |
| 8 | `fase 2 set TWILIO_VOICE_FROM=+57…5483` (6.4); B reconoce en el CRM; `estado` (6.5) | Tras 7 | A0 |
| 9 | `h8-twilio-check.sh` → «APTA · cuenta principal» (paso 7) | Tras 8 | A0 |
| 10 | `expectativa fase2`; `monitor on` → `compartida=admitida haria_freno=no`; pasada de :00 → `estado` «ADMITIDA» (paso 8) | Tras 9; < 30 min con 8 | A0 |
| 11 | `voice(calls-open)`, despliegue 4a y pruebas de la apertura (paso 9) | Tras 10 | A1 |
| 12 | `salientes on --cupo=1 --presupuesto=2.00` (paso 10) | Tras 11 | A2 |
| 13 | Laboratorio, publicar en canario, canario …6026 a cuatro ojos (11–13) | Horario legal | Las de cada paso |
| 14 | La llamada; qué número ve el …6026; C-2; si no contesta, no se reintenta ese día (paso 14); `salientes off` | L–V 07–19, S 08–15 | A3 |
| 15 | Rotar el Auth Token maestro con `h8-otp-token-rotate.sh` (paso R2) | Antes de 4 o después de 14, nunca entre 11 y 14 | **La suya propia** |

## 6. Lo que NO pude ensayar en local
- Nada del proveedor real: los permisos de las dos keys restringidas (los de la key E están «por confirmar», E16-17), la importación de un caller ID con API key, si `GET /v1/convai/phone-numbers/{id}` sigue trayendo `supports_inbound` (obsoleto: sin él la admisión falla cerrado), los permisos geográficos y qué ve el …6026 (Twilio no lo garantiza en Colombia).
- `sudo -u www-data`, supervisor, FPM y `ssh -t` de verdad: el banco los sustituye (el `flock` sí es real; la terminal sin eco, en una pty de Python).
- No repetí `ensayar-ops8c.sh` (su banco es del 09-11). Su negativa `:151` sigue valiendo: las tres ramas nuevas dicen «WhatsApp» con código 2; y la sección «código anterior» de OPS8-f recorre esos caminos con un código sin la enmienda.
- La pasada programada de :00: el banco concilia el número con `VoiceReconciler::numbers()` (el ayudante de OPS8-d), no con `schedule:run`.

## 7. Lo que security debe mirar primero
1. `lee_compartida()` (`:157`) y sus tres llamadores (`:417`, `:888`, `:1127`): que nada que no sea exactamente la forma admitida abra `salientes on`.
2. El AVISO en vez de desvío de `:1127` para los dos motivos de «pendiente de conciliar».
3. `--terminal` (`:817`): `printf` es interno de bash (los valores no van a `argv`), el código Python no los lleva, se borran con `unset`; queda la memoria del proceso del mando durante la orden.
4. `:801` decide con el fichero (`WHATSAPP_*`, leído por Dotenv, como antes) y con la configuración efectiva más la orden (la declaración y «Outbound Only»); la autoridad final sigue siendo el veredicto del servidor en `salientes on` y en la puerta.
5. `--autorizacion` es una cita, no una prueba (como en `deploy-h8-backend-fase1.sh`).

## 8. Huellas, riesgos y deuda
- Huellas: solo las citaba el runbook (paso 0), ya actualizado: `h8-voice-scope.sh` **`6bd98ae2…`** y `h8-twilio-check.sh` **`db842269…`**; `el-verificar.sh` y `h7-monitor-scope.sh` no cambian. Los informes de `plans/` guardan las huellas de su fecha.
- **Para decidir el lead:** en A0 y A3 solo cambié la cláusula del origen (decía «no es el …5483», que la decisión invierte), y A0 nombra la declaración; referencias, alcance y condiciones, sin tocar. Si A0 tiene que quedar literal, `numero-compartido on` necesita otra autorización citada.
- Lote 3: `DEPLOY_CIERRE_LOTES.md` §4 ya declara `voice.number.shared_with_messaging=false` y `migracion-primero`: **no hace falta otra declaración**. Su «voice:doctor como antes» tendrá una clave más (`number.sharedWithMessaging`).
- Mientras el CRM no lea `sharedWithMessaging` (diseño, punto 2), con la identidad admitida enseñará el error del número.
- `numero-compartido off` con el monitor encendido y el visible puesto: la siguiente pasada echa el freno `number_is_messaging` (el lado seguro). Lo suelta una persona con `ultron.voice_publish`; en la base del banco, el Super Admin no tiene ese permiso (lo reparte Roles), por eso el ensayo rehace la base.
- Previo, sin tocar: `estado` dice «0,6105 US$ a un móvil sin transferencia», que no es la reserva de la puerta (0,9605); `salientes on` usa la de la puerta (C-1).
- Ningún secreto quedó en ficheros ni salidas. Las piezas del banco son sintéticas y viven en `~/.cache/ironbody-banco-h8-ops8f` (600); `bash ensayar-ops8f.sh <banco> borrar` lo quita todo.
