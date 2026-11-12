# Informe de devops · preparación de la primera llamada (C-1, C-3, C-4, C-5/C-6 y `--check=prompts`)

Fecha: 11-11-2026 · Autor: devops · Rama `feat/ultron-studio-h9` · Árbol `_worktrees/ultron-h8/Iron-Body/backend`. Empecé con HEAD en `3161d3a3`; al terminar está en `0f824a38`, por dos commits ajenos de solo documentación que no tocan ni código ni mis ficheros.

## Resumen para el lead

1. **No es producción, ni hay commit.** Todo se hizo en local: un banco propio (`~/.cache/ironbody-banco-telefonia`) y copias en el scratchpad. **No toqué producción** (ni `ssh`). **Cero red**: la valla dio 0 peticiones en todos los ensayos, y ElevenLabs fue su simulador local. Sin commit ni push.
2. **C-1** (`ops/h8-voice-scope.sh:45-48, 231-252, 779-792`). `salientes on` ya no compara con la reserva sin transferencia, sino con la de la **puerta en el peor caso**:
   - las cifras se leen del código desplegado con sus funciones: **0,9605** a un móvil y **1,1220** en otro caso, así que el mínimo es **1.13**;
   - el mando se niega si la fórmula de `OutboundGate` (sus dos caminos, `:131-134` y `:458-461`) deja de ser la conocida.

   También `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md:52`. Banco: **54 OK · 0 FALLO**. El mando de HEAD acepta 0.70; el del árbol niega de 0.61 a 1.12 y aplica 1.13 y 2.00.
3. **C-3** (`ops/el-verificar.sh`):
   - toma los **tres cerrojos** comunes;
   - ante cualquier fallo después de escribir, **restaura la copia previa, regenera la caché** (de www-data, 600, legible) y hace `queue:restart`;
   - **comprueba** el resultado: fichero idéntico y configuración efectiva igual. Ya no apaga ElevenLabs.

   Banco: **86 OK · 0 FALLO**, con un fallo en cada etapa, una señal SIGTERM, los tres cerrojos ocupados y la restauración que falla. El de HEAD escribe con el cerrojo tomado, deja puesta la clave que no verificó, y su vuelta propuesta deja la voz en `ELEVENLABS_DISABLED`.
4. **`--check=prompts`** (`ops/h7-monitor-scope.sh:292-295`). Banco: **12 OK · 0 FALLO**. El registro del monitor en seco trae `publication`, `integrations` y `prompts`. El comentario falso de `routes/console.php:459-464` (C-2) queda anotado y **sin tocar**.
5. **C-4, medido.** Con la constante girada en una copia **no caen 2 guardas**: caen **51 casos (34 métodos en 20 ficheros)**. Con la cerrada no cae ninguno nuevo. Además, `deploy-h9-backend-cierre.sh:520` y `deploy-m1-backend.sh:609` **se niegan** con la constante abierta en `origin/main`.
6. **C-4, recomendación (opción A):** commit de pruebas `T` preparado y revisado **antes**, en una rama aparte → `voice(calls-open)` → despliegue 4a COMPLETADO → push de `T`. `main` queda en rojo **solo entre los dos push**: lo que dura la 4a. No hay CI en el repo.
7. **Runbook nuevo** `ops/RUNBOOK_PRIMERA_LLAMADA.md`. Recoge los 14 pasos con la orden, lo que tiene que imprimir y la vuelta atrás de cada uno, más las 3 autorizaciones escritas (y la de la fase 2, si falta). Lleva también C-5 (`VOICE_NUMBER_INBOUND_CAPABLE=false`) y C-6 (el saldo de Twilio es el del OTP).
8. **Huellas nuevas:**
   - `h8-voice-scope.sh` `231e4a8a…`, sustituye a `d8a18960…`;
   - `el-verificar.sh` `cd7a5a7a…`, sustituye a `7cc6100f…`;
   - `h7-monitor-scope.sh` `be6c3c47…`, sustituye a `c70537b7…`.

   Órdenes de instalación en §7 (las ejecuta el lead, con autorización).
9. **Sin verificar** (no se puede sin producción): usuarios reales `root`/`www-data`, Bash 5 y GNU, el `tinker` de producción, la consola de Twilio y de ElevenLabs, y la duración real de la 4a.
10. **El árbol tiene cambios ajenos sin confirmar**, de otro agente: `app/Console/Commands/ScanCommercialFacts.php`, 5 pruebas nuevas e `informe-tester-i6.md`. **No son míos ni los toqué**; el commit de este trabajo tiene que dejarlos fuera (§2).

---

## 1. Entorno

| | |
|---|---|
| Dónde | Mac del propietario; solo ficheros de `docs/ultron-studio/ops/` y documentos |
| Banco | `~/.cache/ironbody-banco-telefonia`, montado por `ops/banco-h9/telefonia-prep.sh` desde la «producción» del banco M-1. Base propia `ultron_studio_banco_telefonia` (`CREATE DATABASE … TEMPLATE`); el banco M-1 no se toca. Código en HEAD (`3161d3a3`). ElevenLabs simulado (`banco-h8/sim-elevenlabs.cjs`) en `127.0.0.1:8572`, con piezas sintéticas. Valla en `:8574` y API en `:8571`. `flock` de verdad |
| Pruebas de C-4 | Dos copias del backend de HEAD en el scratchpad (`git archive` + `vendor` + `.env.testing`), idénticas salvo `VoiceCallPolicy.php:23` |
| **No es producción** | Ni lecturas contra el VPS, ni `ssh`, ni Twilio, ni ElevenLabs real |

## 2. Qué cambió

| Fichero | Líneas | Qué |
|---|---|---|
| `ops/h8-voice-scope.sh` | 45–48 | Cabecera: qué niega `salientes on` por el presupuesto (C-1) |
| | 231–252 | `reserva_puerta`: (1) ancla la fórmula de la puerta; (2) lee las reservas con `VoiceCostGuard::prices()`, `reserveMicros()`, `budgetMicros()` y `fits()` del código desplegado, por `tinker` como www-data, sin red ni escritura |
| | 779–792 | `salientes on`: niega por debajo del peor caso con las cifras («… 0.9605 US$ a un móvil colombiano (+573…) · 1.1220 US$ a cualquier otro destino · peor caso 1.1220 US$ (presupuesto mínimo: 1.13)») y lo dice también cuando aplica |
| `ops/el-verificar.sh` | 12–23 | Cabecera C-3 |
| | 29–39 | `WWW`, `CERROJOS` y los **tres cerrojos** `flock -n`, antes de la copia |
| | 43–55 | `cache()`, `parsea_el()` y `huella_voz()`. `cache()` exige: fichero normal de www-data, en 600 y legible por www-data; el chmod lo hace www-data, no root |
| | 57–99 | Huella de la configuración antes de empezar; copia previa comprobada con `cmp`; `VUELTA`; `restaurar()`; trampa `EXIT` por etapas |
| | 104–167 | Las escrituras y las cachés con `\|\| falla`; los escritores python borran su temporal si fallan; el fallo de `doctor --verify` destruye la clave (REV8 m-5) y la trampa restaura |
| | 168–178 | Etapa 2 (verificado), sin cambios de fondo. La vuelta que imprime pasa a ser la copia previa, **no** `ELEVENLABS_ENABLED=false` |
| `ops/h7-monitor-scope.sh` | 292–295 | `--check=prompts` en el ensayo informativo y su texto |
| `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md` | 52 | Las cifras de la puerta, el mínimo 1.13 y el enlace al runbook |
| `ops/RUNBOOK_PRIMERA_LLAMADA.md` | nuevo | La lista de comprobación de la primera llamada |
| `ops/banco-h9/telefonia-prep.sh` | nuevo | El banco (montar, arrancar, `voz`, `el`, `monitor`, parar y borrar) |
| `ops/banco-h9/README.md` | sección nueva | Cómo se usa |
| `ops/evidencia-h9/2026-11-11/telefonia-prep/` | 01–03 | Salida íntegra de la pasada final, sin rutas del Mac ni valores |

**Diseño, en una línea por punto:**

- **C-1 · De dónde salen las cifras.** Del servidor, con las mismas funciones que la puerta; el comportamiento de las demás órdenes no cambia.
- **C-1 · Por qué un ancla.** `reserveMicros` no sabe que la puerta pasa `KIND_LANDLINE` como transferencia. Por eso, si cambia cualquier línea de `OutboundGate.php` que asigne `$precios` o `$maxima` o llame a `reserveMicros(` o a `fits(`, el mando se niega en vez de adivinar.
- **C-1 · Por qué el peor caso.** Se exige el peor caso, 1.1220, y no el del móvil, porque así lo pidió el encargo.
- **C-1 · Lo que no cambia.** `estado` sigue mostrando la reserva sin transferencia (0.6105) como dato informativo: es otra orden (§8).
- **C-3 · La restauración.** Es la de `hechos` (calcada), así que se comprueba igual.
- **C-3 · El fichero de la clave.** Solo se destruye tras un `doctor --verify` fallido o al terminar bien. Con los demás fallos se queda para repetir, como en `fase 2 secreto`.

## 3. Ensayo (el «dry-run» de este trabajo)

| Ensayo | Casos | Resultado | Valla |
|---|---|---|---|
| `telefonia-prep.sh … voz` (C-1) | 54 | **54 OK · 0 FALLO** | 0 → 0 |
| `telefonia-prep.sh … el` (C-3) | 86 | **86 OK · 0 FALLO** | 0 → 0 |
| `telefonia-prep.sh … monitor` (tarea 5) | 12 | **12 OK · 0 FALLO** | 0 → 0 |

**C-1 (`voz`):**
- Piezas de la fase 2 puestas con el propio mando, `VOICE_NUMBER_INBOUND_CAPABLE=false` incluida. La constante se abre con un commit `voice(calls-open):` **solo en el clon del banco**, y con `ironbody-voice` en RUNNING.
- **HEAD**: `--presupuesto=0.70` → **APLICADO**, y `fits()` de la puerta dice que no cubre a un móvil. Es el defecto.
- **Árbol**: 0.61, 0.70, 0.96, 0.97, 1.00 y 1.12 → código 2, con las cifras; el fichero no cambia y no hay copia. Las cifras, recalculadas a mano con los precios del 03-11, coinciden: 960 500 y 1 122 000 µUS$.
- **Árbol con la fórmula cambiada** (la reserva sin transferencia en uno de los dos caminos) → código 2: «la reserva de la puerta ya no es la que conoce este mando».
- 1.13 → APLICADO, y `fits()` leído aparte cubre el peor caso. 2.00 → APLICADO; repetir → «nada que escribir».
- Fallo forzado tras escribir (Dotenv) y tras la caché → ABORTA con la vuelta exacta, que **se ejecutó**: fichero idéntico, 600 y caché en 600.
- Cerrojo ocupado → ABORTA sin tocar nada. Al final, la constante se cerró con el checkout del runbook.

**C-3 (`el`):**
- Rechazos sin copia y sin tocar nada: clave vacía, `agent_id` inválido, entorno en 640, y **cada uno de los tres cerrojos** tomado por otro proceso (el fichero de la clave se queda).
- **HEAD**:
  - con `ironbody-env.lock` tomado, escribe igual;
  - con una clave que el simulador rechaza, «FALLIDA», deja puesta la clave mala con `ELEVENLABS_ENABLED=true`;
  - su vuelta propuesta, aplicada, deja la telefonía en **`ELEVENLABS_DISABLED`**.
- **Árbol**, fallo en las etapas Dotenv, caché 1, bandera, caché 2, **SIGTERM** y `doctor --verify` con 401 real. En las seis:
  - «restaurado y COMPROBADO» y «ElevenLabs NO se apaga»;
  - fichero idéntico, 600; caché del usuario de www-data en 600;
  - **la configuración efectiva idéntica**: bandera, huella de la clave, huella de `config('voice')`, estado de la telefonía, Studio y **marca de 30 días**, leídos de forma independiente;
  - una copia nueva en 600 y ningún `.env.tmp-*`.
- Restauración que falla → «restauración INCOMPLETA: vuelta a mano: …». La orden impresa **se ejecutó** y dejó el fichero y la configuración idénticos.
- `--solo-clave` → TERMINADA:
  - la clave es la nueva (por su huella); agente, región, tres secretos y base, iguales;
  - telefonía y Studio `ELEVENLABS_VERIFIED`; la marca se **renovó**;
  - la vuelta impresa, ejecutada, devuelve el fichero idéntico.
- Modo completo → TERMINADA.
- Ninguna clave en la salida ni en el registro.
- **Monitor**: preparación con `expectativa fase1` (lo que tiene producción). HEAD y árbol `on off` / `off on` APLICADO e ida y vuelta idéntica. El árbol dice «(publicación, integraciones y prompts): 0», y el registro `ultron_studio.monitor` trae `{"publication":"ok","integrations":"ok","prompts":"ok"}`. HEAD no trae `prompts`.

Nada se destruye ni se reemplaza en producción con estos cambios: son tres guiones en `/root`, y las órdenes solo escriben claves del fichero de entorno, con copia.

## 4. C-4 · Análisis y procedimiento (sin tocar código ni pruebas)

### 4.1 Lo medido

Dos copias del backend de HEAD que solo difieren en `VoiceCallPolicy.php:23` (`false`/`true`), con `phpunit` sin red:

| Batería | Cerrada | Abierta | Solo con la abierta |
|---|---|---|---|
| `tests/Unit/{Voice,Telephony}` + `tests/Feature/{Voice,Telephony,Outreach,UltronStudio}` (3 250 pruebas, 604 omitidas: E2E y PG) | 2 fallos | 53 fallos | **51** |
| El resto de `tests/Unit` y `tests/Feature` | sin medir | sin medir | **sin medir** |

> **Nota del lead (11-11-2026).** La segunda tanda no llegó a terminar antes de cerrar al agente: la fila de arriba quedó con sus marcadores sin rellenar y aquí se dice. Los 51 casos son, por tanto, un mínimo: quien prepare `T` (§4.4, paso 1) mide el resto de la batería sobre las dos copias antes de dar la lista por cerrada.

Los 2 fallos comunes son las dos puertas de diff (`MetaTemplateRoutesTest::test_la_puerta_de_diff_del_whatsapp…` y `OutreachIsolationTest::test_la_puerta_de_diff_de_legacy…`). Fallan igual en las dos copias, que no tienen historia de git [H]. La batería completa en un solo proceso se quedó sin memoria (1 GB) en las dos copias; por eso va en dos tandas.

**Los 51 casos que caen con la constante abierta:** 34 métodos en 20 ficheros (lista íntegra: `evidencia-h9/2026-11-11/telefonia-prep/04-c4-pruebas-con-la-constante-abierta.txt`). Son de dos clases:

- **(a) Guardas literales** de «el árbol compartido tiene la constante cerrada». Son 5:
  - `Unit/Voice/VoiceIsolationTest.php:330`;
  - `Unit/Voice/VoiceCallPolicyTest.php:77`;
  - `Unit/Voice/ElevenLabsCallStarterTest.php:45`;
  - `Unit/Voice/ElevenLabsAgentDeployerTest.php:234`;
  - `Feature/Outreach/V9/V9StrategyGateTest.php:91`.

  La auditoría citaba `tests/Feature/.../VoiceIsolationTest.php`, pero el fichero está en `tests/Unit/Voice/`.
- **(b) Comportamiento con la constante cerrada.** Son 29 métodos y 46 casos. Esperan `calls_disabled_in_code` entre los motivos, o `decide()->allowed=false`, o un 409 donde ahora sale 202, o que nada salga:
  - el fixture de 43 casos de la puerta (`TelephonyFixtureParityTest.php:67, 126`);
  - `VoiceCallPolicyTest.php:88`;
  - los 18 conjuntos de `V9StrategyGateTest.php:140` y 3 métodos más;
  - `VoiceBoundaryTest.php:327, 358`;
  - el freno por webhook (`VoiceWebhookHealthBrakeTest.php:179, 218, 326`);
  - la identidad del número (`VoiceNumberIdentityTest.php:168, 197, 225`);
  - la iniciación (`VoiceInitiationWebhookTest.php:104, 183, 213`);
  - y otros 10.

**Qué significa:** no basta con «cambiar `false` por `true`» en dos sitios. La clase (b) usa la constante como la última cerradura de muchas pruebas de otras cerraduras. Con la constante abierta, esas pruebas tienen que demostrar con **otra** cerradura que la llamada se deniega (la publicación, el canario, el horario, el freno…), o pasar a describir el estado abierto con la misma precisión. Ese diseño es de **tester** y **backend**, con **security** y **reviewer**. Yo no edito pruebas.

### 4.2 Restricciones que fijan el orden

Lo que sigue lo comprobé leyendo el código:

1. El commit `voice(calls-open)` es **una línea** y nada más (`deploy-h8-backend-fase1.sh:384-395`).
2. En el modo 4a, la **punta de `origin/main` tiene que ser ese commit** (`:375-377`), y lo demás del tramo **solo puede ser documentación** (`:396-405`). Las pruebas no son documentación. Por eso el commit de pruebas no puede ir ni en el mismo push ni antes, en el tramo.
3. `h8-voice-scope.sh` considera la constante «abierta» solo si el **último commit que tocó `VoiceCallPolicy.php`** empieza por `voice(calls-open):` (`:217-227`). **Ningún commit posterior puede tocar ese fichero**: ni siquiera el comentario de `:10` («EN ESTE HITO NUNCA PERMITE NADA»), que queda falso mientras esté abierta.
4. Con la constante abierta en `origin/main`, **se niegan** `deploy-h9-backend-cierre.sh:520-522` y `deploy-m1-backend.sh:609-611`. El siguiente despliegue de ola necesitará su enmienda (o cerrar antes). `hotfix-backend.sh` no está en el repo: no lo he podido comprobar.

### 4.3 Opciones

| | Qué | Rojo en `main` | Cambia un procedimiento protegido | Riesgo |
|---|---|---|---|---|
| **A (recomendada)** | `T` preparado y revisado antes, en una rama aparte; push de `voice(calls-open)` → 4a COMPLETADO → push de `T` | Solo entre los dos push: lo que dure la 4a (paso 0 + despliegue + comprobaciones; minutos [H]) más ~3 min de la batería | No | Si la 4a aborta, el rojo dura hasta arreglarla o revertir. Alguien que tire de `main` en esa ventana ve 51 rojos |
| B | `T` antes de abrir, con pruebas que siguen el valor de la constante (`if (CALLS_ENABLED_IN_CODE) … else …`) | 0 | No | **Relaja**: la guarda de que el árbol no se abre por accidente pasa a ser una tautología. **Descartada** |
| C | Enmendar el modo 4a de `deploy-h8-backend-fase1.sh` para admitir, detrás de `voice(calls-open)`, un único `test(voice):` que solo toque una lista cerrada de ficheros de `tests/`; push conjunto | 0 | **Sí** (protección 2) | Cambio del despliegue protegido, con su banco (OPS8-e), security y reviewer. Más trabajo y más superficie |

**Recomiendo A.** No toca el procedimiento protegido, no relaja nada y el rojo es corto, predecible y anunciable. El repo no tiene CI (no hay `.github/workflows`), así que ningún consumidor automático lee `main` en esa ventana. C solo merece la pena si `main` tiene que estar siempre en verde (por ejemplo, si se añade CI).

### 4.4 Procedimiento A, exacto

**Antes** (días antes, sin tocar `main`; tester y backend, con security y reviewer):

1. **Preparar `T`** en una rama aparte, encima de un commit local que simula la línea abierta (nunca se sube).
   - Asunto: `test(voice): las pruebas de la constante describen el árbol con las llamadas abiertas (C-4)`.
   - Toca **solo** los 20 ficheros de 4.1 (y lo que salga de la segunda tanda), **nunca `VoiceCallPolicy.php`**.
   - Las guardas (a) pasan a afirmar `true`, literal y una sola vez, con la misma forma.
   - Las (b) prueban la denegación con otra cerradura, o el estado abierto con la misma precisión.
   - Lo que describe el estado **cerrado** (el destino de la vuelta atrás) se sigue probando sobre una **copia cerrada**, como `H8E2ECerradaTest`.
   - Prohibido: `skip`, `markTestIncomplete`, quitar aserciones o meter condicionales sobre la constante.
2. **Validar `T` como en 4.1:**
   - sobre la copia **abierta**, 0 fallos nuevos;
   - sobre la copia **cerrada**, las guardas (a) de `T` **tienen que caer**: es la prueba de que no son tautológicas, como una mutación;
   - y lo mismo con la batería entera.
3. Reviewer: veredicto sobre `T` **antes** de la apertura.

**El día** (con A1, paso 9 del runbook):

4. L: respaldo `BK` nuevo, con `api-commit.txt` = el commit que sirve producción.
5. L: commit `X` = `voice(calls-open): …`. Una línea, `VoiceCallPolicy.php:23`, un padre, y en el mensaje la cita literal de `AUT-VOZ-1`. Push a `main` con la orden del propietario. **Empieza el rojo** (51 casos o más).
6. L, en el servidor:
   ```bash
   bash /root/deploy-h8-backend-fase1.sh <X> $BK --fase=4a --autorizacion=AUT-VOZ-1 [--acepta-main=…] --solo-comprobar
   bash /root/deploy-h8-backend-fase1.sh <X> $BK --fase=4a --autorizacion=AUT-VOZ-1 [--acepta-main=…]
   ```
   Tiene que salir «DESPLIEGUE H8 FASE 4a … COMPLETADO», y en `estado`, la constante «abierta».
7. L, en el repo: `git cherry-pick <T>` encima de `X`. Comprobar que el diff es el revisado (`git range-diff`). Ejecutar los 20 ficheros (o la batería entera, en dos tandas) y hacer **push**. **Termina el rojo.** `T` no hace falta en producción (allí no corren las pruebas); viajará con el siguiente despliegue.

**Si la 4a aborta** en el paso 6: **no** se sube `T`, porque la 4a exige que la punta sea `X`. Hay dos salidas:
- arreglar la causa (casi siempre la fase 2) y repetir;
- un commit nuevo que vuelva a poner `false` («revert» de `X`, sin reescribir la historia). Así `main` vuelve a verde y la constante a cerrada.

**Vuelta atrás, una vez abierta:**
- **Nivel 0, sin código:** `bash /root/h8-voice-scope.sh salientes off`.
- **Nivel 4, en producción:** `( cd /var/www/api && umask 022 && git checkout -q --detach $(cat $BK/api-commit.txt) )`, `config:cache` y `route:cache` como www-data, y `queue:restart`.
- **En el repo:** `git revert <T>` y `git revert <X>`, en **un solo push**: `main` cerrado y las pruebas de antes, sin rojo.
- **Datos:** la 4a no cambia datos; no hay nada que restaurar de la base.

## 5. Impacto

- **Hoy, ninguno.** Son guiones y documentos. Solo tienen efecto cuando el lead los instale en `/root` (§7).
- **`h8-voice-scope.sh`:**
  - Solo cambia `salientes on`. Con el presupuesto del runbook (2.00) el resultado es el mismo que antes, más una línea con las cifras.
  - **Se niega** con menos de 1.13, o si la fórmula de `OutboundGate` cambia: es lo buscado.
  - Las demás órdenes, sin cambios: lo ensayaron `estado`, `fase 2 set/secreto`, `expectativa` y `salientes off`.
- **`el-verificar.sh`:**
  - Con otro mando en curso, ahora **aborta** (antes escribía a la vez).
  - En un fallo, ElevenLabs vuelve a lo de antes **en lugar de apagarse**.
  - El camino feliz es el mismo, con dos lecturas más (`tinker`) y la caché comprobada.
  - Interrupción: la misma que antes (unos segundos con `ELEVENLABS_ENABLED=false` entre los pasos 1 y 3, del diseño original).
- **`h7-monitor-scope.sh`:** una comprobación informativa más (`prompts`, en seco), que solo lee. Su veredicto no cambia.
- **Irreversible:** nada.

## 6. Vuelta atrás

| De qué | Cómo | Límite |
|---|---|---|
| Los mandos | `install -m 700 /root/<mando>.pre-telefonia-<T> /root/<mando>` | Ninguno |
| Lo que escriba `salientes on` | `salientes off`, o la «vuelta atrás exacta» que imprime | — |
| Una rotación de clave terminada | La «vuelta atrás» que imprime | Solo hasta revocar la vieja |
| La 4a | §4.4 | — |

Todas las vueltas de los guiones se ejecutaron en el banco, salvo la de los mandos, que es un `install`. Es una vuelta de configuración y de código, no de datos.

## 7. Pendiente de autorización (las ejecuta el lead)

`C` = el commit que confirme estos ficheros (solo los míos: §2).

```bash
# Mac, raíz Iron-Body/
O=backend/docs/ultron-studio/ops
ssh ironbody-vps 'sha256sum /root/h8-voice-scope.sh /root/el-verificar.sh /root/h7-monitor-scope.sh'
#   esperado hoy: d8a1896047f1… (L9-110) · 7cc6100f05e3… (el de HEAD; si no casa, PARAR y comparar) · c70537b7da81… (L9-110)
ssh ironbody-vps 'T=$(date -u +%Y%m%d-%H%M%S); for n in h8-voice-scope.sh el-verificar.sh h7-monitor-scope.sh; do cp -p /root/$n /root/$n.pre-telefonia-$T; done; ls -l /root/*.pre-telefonia-*'
git show "$C:$O/h8-voice-scope.sh"   | ssh ironbody-vps 'umask 077; cat > /root/h8-voice-scope.sh   && chmod 700 /root/h8-voice-scope.sh   && sha256sum /root/h8-voice-scope.sh'
git show "$C:$O/el-verificar.sh"     | ssh ironbody-vps 'umask 077; cat > /root/el-verificar.sh     && chmod 700 /root/el-verificar.sh     && sha256sum /root/el-verificar.sh'
git show "$C:$O/h7-monitor-scope.sh" | ssh ironbody-vps 'umask 077; cat > /root/h7-monitor-scope.sh && chmod 700 /root/h7-monitor-scope.sh && sha256sum /root/h7-monitor-scope.sh'
# después, solo lectura: bash /root/h8-voice-scope.sh estado  y  bash /root/h7-monitor-scope.sh estado
```

| Fichero | sha256 nueva | Bytes | Sustituye a |
|---|---|---|---|
| `ops/h8-voice-scope.sh` | `231e4a8a9b260f2302157466cad53254d93b1a71007d248e59edff9a700972a1` | 84 987 | `d8a1896047f104789387020abcc44be05cc35f15e9c6450a76093e99f35c5d63` |
| `ops/el-verificar.sh` | `cd7a5a7aab66f4dc077c58024b9a5538db4b386dcdad4cd88e936c1a93ab2c99` | 14 483 | `7cc6100f05e3f68919f9055ef0fd56b9f99ae0f8d80029776afbd96b86495e6e` |
| `ops/h7-monitor-scope.sh` | `be6c3c47e22cdbd05b7ae987571837061c938480a4c566caa5820085ff22413d` | 24 350 | `c70537b7da81d4401deee6421142def07a84524971e6b84da5e13652a9f151ab` |
| `ops/banco-h9/telefonia-prep.sh` (no se instala) | `623f5744b6bc14a86014fa29d57e1f05aeefc97fe53193283abd2f9fa6a3552c` | 48 125 | nuevo |

**Avisos de la instalación:**
- Sin ningún mando en curso. `cat >` reescribe el fichero en su sitio.
- La instalación es del **paso 0** del runbook. El resto de órdenes de producción (pasos 6–14) están en `ops/RUNBOOK_PRIMERA_LLAMADA.md`, cada una con su autorización.
- **C-2, para el siguiente despliegue normal:** el comentario de `routes/console.php:459-464` dice que `voice:webhook-health` «no echa el freno». Desde SC-3 **sí** lo echa (`VoiceWebhookHealth.php:46-58`). Es código: no lo toqué.

## 8. Riesgos residuales, deriva y deuda

1. **La marca de verificación es por tiempo, no por clave.** Visto en el banco: tras el fallo del guion de HEAD con una clave mala puesta, `VoiceProviderState::current()` y el Studio siguen diciendo `ELEVENLABS_VERIFIED` (marca de 30 días). Un fallo así queda **silencioso** hasta la primera petición a ElevenLabs. El guion nuevo lo evita restaurando; el código no lo detecta (backend, si se quiere).
2. **`estado` sigue imprimiendo** «reserva por saliente (VoiceCostGuard): 0.6105 US$ a un móvil sin transferencia · 1.1220 US$ a un fijo con transferencia». Es cierto, pero no es lo que reserva la puerta a un móvil (0.9605). No lo cambié porque es otra orden; propongo cambiarlo en la siguiente tanda.
3. **Documentos con la cifra o el número viejos, sin corregir** (fuera del alcance pedido):
   - `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, tabla de la 4a-primero, fila 8: «la reserva de una llamada a un móvil es 0,6105 US$»;
   - `ACTIVACION_POR_ETAPAS.md:20` (paso 0.6 con `TWILIO_VOICE_FROM=+57…5483`, que el runbook nuevo anula);
   - `ACTIVACION_POR_ETAPAS.md:16` (0.2 con `<agent_id>` en vez de `--solo-clave` tras el registro).
4. **`salientes on` no mira el gasto ya comprometido hoy.** Solo exige que el presupuesto cubra una llamada con 0 comprometido. El día de la primera llamada es 0; con llamadas previas el mismo día, la puerta podría negar `budget_exhausted` con el mando en verde.
5. **`ops/banco-h8/ensayar-ops8d.sh` (`prepara_el_verificar`)** fallará si se vuelve a ejecutar: el guion cambió, y sus reglas lo detectan a propósito. No lo actualicé (ensayo histórico de `a608450`).
6. **`el-verificar.sh` sigue exigiendo el entorno en `www-data 600`** (no admite el `root:www-data 640` de C-14, que sí admiten los demás mandos). Producción está en 600 [P, de los informes previos], así que no bloquea.
7. **Paso 4 de `el-verificar.sh` (`integrations-snapshot --verify-voice`): su código de salida no se mira.** Si fallara justo después de un `--verify` correcto, se borraría la marca y el guion diría «TERMINADA». El runbook pide comprobar `estado` después.
8. **El camino general de `h8-voice-scope.sh`** (todo salvo `hechos`) sigue tomando **solo su cerrojo**, y `h7-monitor-scope.sh` y `h6-integrations-scope.sh`, ninguno. Desde hoy, `el-verificar.sh` toma los tres: excluye a los que toman alguno, no a esos dos.
9. **Copias previas en `/root`.** Cada orden deja una con todos los secretos: `shred -u` al validar.
10. **Deriva:** ninguna. No hice ningún cambio manual en ningún entorno. **Secretos a rotar:** ninguno nuevo. No leí ni imprimí ningún valor real. El banco usa piezas sintéticas.

## 9. Qué no pude verificar sin producción

- `root`, `www-data`, `sudo` real (el del banco ignora `-u` y conserva el entorno), FPM real, supervisor real, Bash 5 y GNU `stat`. El banco corre con el bash 3.2 de macOS y un `stat` sustituto.
- Análisis estático: solo `bash -n` en los cuatro guiones (pasa). `shellcheck` no está instalado en el Mac.
- Que `sudo -u www-data test -r` y `tinker` se comporten en el VPS como en el banco.
- La duración real de la 4a (la ventana de rojo de C-4).
- Que `hotfix-backend.sh` (no está en el repo) admita o no un `origin/main` con la constante abierta.
- La consola de Twilio (verificación del identificador, permisos de la key restringida, si la llamada de verificación aparece en el registro de llamadas) y la de ElevenLabs (importación con el token de la subcuenta).
- Una llamada real: el ensayo llega hasta `salientes on`. La puerta completa con publicación, canario y horario ya la ensayó OPS8-d/e.
