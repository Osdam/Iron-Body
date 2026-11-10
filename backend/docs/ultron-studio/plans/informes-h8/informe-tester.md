# Hito 8 · TEST8-a (paso 1) · informe del tester: los fixtures desde el contrato

**Fecha:** 03-11-2026.
**Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, sobre `57bab8a`.
**Contrato:** `ULTRON_STUDIO_H8_CONTRACT.md`, congelado el 03-11-2026, y su auditoría `ULTRON_STUDIO_H8_VOICE_AUDIT.md` (§d.1 y §d.2).
**Alcance tocado:** solo `tests/fixtures/voice/**` y este informe.
**Sin commit** y sin tocar el índice. **Producción:** nada tocado. **Red:** ninguna. **Suites del repo:** no lancé ninguna (máquina compartida).

## §TEST8-c (cierre del Hito 8, E14-5): E2E del CRM ejecutado, «salientes primero» demostrado y bloqueos de seguridad contra la pila — con 2 hallazgos reales abiertos

**Fecha:** 09-11-2026, 19:16–23:05 UTC.
**Base:**
- validación sobre HEAD `ab54a256` (la fase 1 desplegada);
- pasadas FINALES sobre el **árbol de trabajo** encima de HEAD (`H8_E2E_ARBOL=1`): S8-d completo y B8-g a medias (E13-3 `missingPostCall` y E4-6, el lector de la verificación), sin confirmar, como pidió el lead a las 20:31Z. Cada resumen lleva la huella del árbol que probó;
- CRM `45dd73a`, sin cambios.

**Sin commit.** Nada de producción, proveedores reales ni credenciales: todo en 127.0.0.1 (la valla y `lsof` del arnés, y `--host-resolver-rules` en Chrome).

### Resultado (salida real)

| Pasada | Backend | Pruebas | Comprobaciones | Hash de resultados | Evidencia (`ops/evidencia-h8/2026-11-09/`) |
|---|---|---|---|---|---|
| **`todas` FINAL, con la regla del número** (23:15–23:20Z, 286 s) | árbol `de13b6ea9120` (con `number_unverified` y `number_mismatch`) | cerrada 6/6 · abierta 8/9 · salientes 11/11 | 113/113 · 298/300 · 247/247 | `e89902bd0461fe16…` | `20261109T231516Z-ab54a256-arbol-de13b6ea9120` |
| **CRM FINAL, con la regla del número** (23:20–23:55Z, 2 091 s) | árbol `7d049021efba` + CRM `45dd73a` | — | 281 (278 OK, 3 FALLO: H-1, H-5 y el 429 por carga) | `11a41779290e77d1…` | `crm-20261109T232017Z-ab54a256-arbol-7d049021efba-45dd73a` (sha256 `3b471f58…`) |
| `ambas`, final anterior (20:49–20:51Z, 104 s) | árbol `9e9afe8f1829` | cerrada 6/6 · abierta 8/9 | 113/113 · 281/283 | `3c872c8d20a882d2…` | `20261109T204949Z-ab54a256-arbol-9e9afe8f1829` |
| `salientes`, final anterior (20:51–20:53Z, 89 s) | árbol `ace6becff7a1` | 10/10 | 205/205 | `8f75effb55b32f67…` | `20261109T205148Z-ab54a256-arbol-ace6becff7a1` |
| **CRM, la última** (`abierta recorridos`, 22:31–22:58Z, 1 633 s) | copia `ace6becff7a1` (`H8_E2E_FUENTE`) + CRM `45dd73a` | — | 264 (262 OK, 2 FALLO) | `a376900b283434d5…` | `crm-20261109T223141Z-ab54a256-arbol-ace6becff7a1-45dd73a` (sha256 `ebaf3252…`) |
| CRM, primera final (20:53–21:05Z, 739 s) | árbol `3e5e371a92dd` | — | 282 (280 OK, 2 FALLO) | `daa5aa386efb561d…` | `crm-20261109T205328Z-ab54a256-arbol-3e5e371a92dd-45dd73a` (13 MB, sha256 `9fd34a0b…`) |
| CRM, repetición PARADA (21:06–21:38Z) | árbol `bb1781c15ef7` (ya con `number_unverified`) | — | 247/257 al pararla | — | ninguna: el lanzador solo copia la evidencia al terminar |
| CRM sobre la copia congelada (21:59–22:17Z, 1 061 s) | copia `ace6becff7a1` (`H8_E2E_FUENTE`) | — | 278 (276 OK, 2 FALLO) | `3f444631581db495…` | `crm-20261109T215924Z-ab54a256-arbol-ace6becff7a1-45dd73a` (sha256 `7d93b982…`) |
| CRM sobre la copia congelada, degradada (22:17–22:31Z, carga ~80) | copia `ace6becff7a1` | — | 183 (178 OK, 5 FALLO) | `2a43437feba816ab…` | `crm-20261109T221726Z-ab54a256-arbol-ace6becff7a1-45dd73a` (sha256 `34091224…`) |
| `ambas` sobre HEAD (validación, 19:20Z) | `ab54a256` | 6/6 · 8/9 | 112/112 · 281/283 | `f285af3c0fcdf940…` | sin evidencia (rojo de H-3, del E2E) |
| `salientes` sobre HEAD (19:50Z) | `ab54a256` | 10/10 | 192/192 | `691eb04e7eac46d2…` | sin evidencia |
| CRM sobre HEAD (19:51Z; la de las 19:35Z, con el guion aún sin corregir: 277, 269 OK) | `ab54a256` + `45dd73a` | — | 278 (276 OK, 2 FALLO: H-1 y un selector mío) | `8806b39104d3bf0f…` | sin evidencia |

**Los rojos de producto de las pasadas finales son hallazgos reales:**
- **H-4**, en la abierta. Según el lead, lo corrigió él (E16-25) después de estas pasadas; no lo he vuelto a ejercer;
- **H-1**, en el CRM: falla igual en las 8 pasadas del CRM de hoy que llegaron al laboratorio. Lo corrige el frontend;
- **H-5**, en el CRM sobre el árbol actual: nuevo con B8-g, ver Hallazgos.

**El 429 del CRM sobre el árbol actual** volvió a caer por carga (35): las lecturas de Carla van directas a la API, y 8 tandas de 20 no cupieron en la ventana de 60 s. El limitador no cambió (`AppServiceProvider.php:725`, 120/min por persona). El paso lanza ahora tandas de 40.

**El CRM no tiene una pasada sin otros rojos.** Las pasadas del CRM coincidieron con suites de otros agentes: carga de la máquina de 40 a 105, y la API de la pila hasta 25 s de mediana. Pero entre las pasadas completas, cada comprobación del recorrido pasó al menos una vez, con el guion corregido. En la última:
- en verde, todo hasta las transversales: perfil, previa, laboratorio salvo H-1, publicar, cadena, llamadas, costes, contactos, freno, `salientes` por la puerta real, `429` y «Firefox»;
- las de red y navegador, también en verde;
- el rojo de entorno: Chrome perdió la página de Carla (`TargetCloseError: Session closed`) en sus últimas 5 pestañas, que pasaron en las pasadas anteriores.

**Las otras pasadas finales del CRM y por qué se repitieron** (quedan las 4 evidencias del CRM, unos 42 MB en total, sobre todo capturas: que el lead decida cuáles confirmar):
- **La primera** (`3e5e371a92dd`) tuvo un rojo **mío**: la respuesta 202 de `POST admin/voice/calls`, alcanzable desde E4-6, lleva la misma fila de llamada con el id del agente entero que ya admitía en los GET (O-3). Corregí la lista de excepciones.
- **La de la copia congelada** (21:59Z) tuvo un rojo **de entorno**. Con la máquina a carga 70, 140 lecturas secuenciales de Carla ya no cupieron en la ventana de 60 s del limitador, así que no hubo 429 y el paso se cortó. El paso lanza ahora las lecturas en tandas paralelas de 20.

**El cierre, en verde en las cinco fases:**
- 0 violaciones del contrato en los dos simuladores;
- la valla, sin hosts;
- `lsof`, 0 líneas fuera de 127.0.0.1;
- 0 `ERROR`;
- `failed_jobs` y colas vacíos;
- barrido limpio de registros, base, respuestas y evidencia;
- en el CRM, los 6 puertos libres al parar.

**Los simuladores son los del commit** (`9c878356…`), no los del árbol: OPS8-c los está cambiando ahí (`3791625a…`, el registro de secretos y webhooks).

**sha256 de los directorios de evidencia del backend** (todos sus ficheros, ordenados, como el lanzador del CRM):
- `ambas`: `0c15da75…`;
- `salientes`: `5bbff5c3…`.

### El árbol se movió durante la tanda (B8-g a medias): qué es evidencia y qué no

- **Huellas.** Cada pasada final congela el árbol en su `fuente/`. Entre las 20:49 y las 20:53Z las tres (`9e9afe8f1829`, `ace6becff7a1` y `3e5e371a92dd`) tienen lo que el lead describió a las 20:31Z:
  - E13-3: siete claves y `calls` con `guard_verdict`;
  - E4-6: el lector de la verificación;
  - `VOICE_NUMBER_INBOUND_CAPABLE`.
- **Lo nuevo de B8-g, después de las 20:53Z.** La puerta y el job exigen una conciliación del número importado de menos de 2 h que cuadre (`number_unverified` y `number_mismatch`; `VoiceNumberGuard::policyCheck()`).
- **Lo que rompe en el banco, aunque el código no esté mal:**
  - en la repetición del CRM (`bb1781c15ef7`), la saliente pedida desde el CRM dentro del horario dio 409 `["number_unverified"]` (`voice.outbound.denied` en el registro de la pila);
  - los workers de UN trabajo del banco corren con el reloj desplazado a un martes (`reloj.php`), unas 19 h por delante. El job mide la edad de la observación con ese `now()`: toda saliente del banco se cancelaría por `number_unverified`.
- **Adaptación: HECHA** después del aviso del lead, ver §0. La pasada final de las 23:15Z ya la lleva.
- **La repetición del CRM la paré yo a las 21:38Z.** Además del árbol nuevo:
  - la máquina estaba a carga 70–105 por las suites de otros agentes;
  - la API tardaba unos 25 s de mediana (también `/api/health`), con los 4 workers de `php -S` copados por los SSE de notificaciones, y 2 respuestas 5xx;
  - las pestañas dejaban de pintarse.
  No es evidencia.
- **Para repetir sobre el código exacto de una evidencia** cuando el árbol ya cambió: `H8_E2E_FUENTE=<run>/fuente` (lo usan los mutantes del árbol y la pasada final del CRM).

### 0. La regla del número de B8-g (21:05Z): adaptado, y sus dos motivos demostrados

B8-g añade que la puerta y el job fallen cerrados sin una conciliación del número importado de menos de 2 h que cuadre con `TWILIO_VOICE_FROM` (`number_unverified` y `number_mismatch`).

**Adaptación:**
- **Fase abierta:** el paso g corre `voice:reconcile --numbers` con el reloj real antes de las salientes.
- **Workers de un trabajo:** observan el número con SU reloj desplazado justo antes del job y con el real justo después. Usan `ayudante.php observar-numero`, que ejecuta `VoiceReconciler::numbers()`, el mismo método de `--numbers`, sin la parte de llamadas: la conciliación completa con el reloj desplazado pasaría a `ambiguous` las filas `requested`. Lo hace `workerDeUnTrabajo()`, en todos los sitios que lo usan.
- **CRM:** el paso `salientes` corre `voice:reconcile --numbers` antes de pedir la saliente y observa el número alrededor del worker desplazado de la costura `dialing`.

**Los dos motivos, contra la pila (fase `salientes`):**
- **`number_unverified`:** sin ninguna conciliación, la puerta falla cerrado; tras `voice:reconcile --numbers` (`matchesFrom: true`) desaparece. Paso 0.
- **`number_mismatch`:** una observación con `TWILIO_VOICE_FROM` cambiado SOLO en ese proceso (el número importado del simulador ya no cuadra) echa el freno; la puerta dice `number_mismatch` y `voice_braked`; restaurada y suelta, desaparece. Va en su propio paso, g2, después de promover: su freno, abierto antes de publicar, caería en la ventana que la costura de tiempo retrasa 25 h y contaría como `drift_events` (visto en la primera validación de las 23:06Z).

Los casos de `decide` del fixture los actualizó B8-g (`outbound-gate-cases.json`, `sec_gate.py`); no los toqué.

**Pint** (condición m-3 de REV8-c): `H8E2ESalientesPrimeroTest.php` fallaba por una importación (`fully_qualified_strict_types`, `ordered_imports`). Corregido: `pint --test tests/Feature/Voice/E2E/` → `passed`. En la suite normal, el fichero da 11 omitidas (sin el arnés no hay pila: no es un verde); con el arnés, la fase `salientes` final (23:15Z) pasa con ese fichero ya formateado.

### 1. `esperarEstado` a 30 s, y el rojo que escondía el horario (H-3, del E2E, corregido)

- `H8StackE2ETestCase::esperarEstado()`: de 15 a 30 s (E14-2; el job tiene `--timeout=60`).
- **H-3.** La primera pasada de hoy (14:20 de Bogotá) fue la primera DENTRO del horario legal. Todas las anteriores fueron de noche o en domingo.
  - El worker residente marcó de verdad su saliente, así que hubo dos `outbound-call`.
  - El paso h esperaba siempre una.
  - Ahora cuenta las que marcó el job (`salientesMarcadas`).
- **E4-6 sobre el árbol.** El `--verify-voice` del arranque deja la marca y el proveedor queda `ELEVENLABS_VERIFIED`. El paso g de la abierta usa ahora la **puerta real** con el reloj real:
  - **fuera del horario:** 409 con EXACTAMENTE el calendario;
  - **dentro:** 202 → la fila sellada → el worker residente marca → `completed`, más el reintento del mismo `clientRequestId` (H-4).
- La cerrada y la abierta comprueban la marca vigente en el paso 0. La cerrada ya no espera `elevenlabs_unverified` acumulado.
- **E13-3 (B8-g).** El paso d de la abierta espera las **siete** claves: `calls` 3 y `missingPostCall` 0.

### 2. El E2E del CRM, terminado y ejecutado contra la pila del arnés

`ops/h8-e2e-crm/`: 17 pasos y hasta 282 comprobaciones.

**Cuatro ojos con las dos cuentas.**
- **Perfil:** Ana lo crea, sin «Aprobar» en lo suyo, y Beto lo aprueba.
- **Previsualizar:** la huella del CRM es la de `compile-preview`, sin red.
- **Laboratorio** (ver abajo).
- **Publicar** con `expectedSpecHash`:
  - el 409 `spec_changed` es real, provocado alterando la huella en el navegador (CDP);
  - un solo POST, sin reintento;
  - el botón se desactiva hasta volver a previsualizar;
  - después, 201.
- **Publicación (Carla):** el id del agente enmascarado y sin acciones.
- **Llamadas:**
  - 3 entrantes del simulador, con solo los 4 dígitos;
  - el detalle con TODOS los eventos de la API (0 descartados);
  - códigos en lista plana.
- **Costes.**
- **Contactos:**
  - Ana carga el consentimiento y no puede confirmarlo (el servidor lo niega y el CRM lo dice);
  - Beto lo confirma;
  - exclusión.
- **Freno:** lo abre el `seal_mismatch` del simulador. Carla lo ve y no lo suelta; Ana lo suelta con nota.
- **Salientes:**
  - el diálogo enseña alias + 4 dígitos;
  - **dentro del horario**, pedir desde el CRM → la puerta real (202) → el job del worker residente → `dialing` → **cortarla desde el CRM** (`hung_up`, UNA petición a Twilio). Fuera del horario, el CRM enseña EXACTAMENTE el calendario;
  - además, cortar una `requested` (sin tocar Twilio) y una `dialing` (UNA petición).
- **429 con `retry_after`:**
  - cuerpo uniforme y `Retry-After` iguales;
  - el CRM enseña el mensaje del servidor;
  - el navegador recibe el 429;
  - sin reintentos;
  - pasado `retry_after`, 200.
- **«Firefox»** (sin `sampleRate`): ni sesión, ni micrófono pedido, ni gasto.

**Transversales:**
- las 7 pestañas × 3 cuentas a 390 y 1 440 px, sin desplazamiento horizontal;
- sin teléfonos, secretos, URL firmada ni id completo del agente en el DOM, el almacenamiento, la URL ni la consola de la página;
- ninguna escritura de `innerHTML`;
- foco atrapado en los 9 diálogos;
- botones = `can` para las 3 cuentas.

**En la evidencia:** capturas (62) y registro de red con estado y `Retry-After`.

**Laboratorio (micrófono falso con permiso):**
- **La sesión 1** usa la URL firmada del simulador tal cual. Es `ws://`: el banco no tiene TLS.
  - El CRM se niega («la respuesta del servidor no tiene la forma esperada») y no abre ningún WebSocket.
  - Su lector solo acepta `wss://` (`studio-voice-parse.ts:1110`). Correcto.
  - Ana cuenta lo que pasó: `tester_ended`.
- **La sesión 2** reescribe en el navegador el esquema de la respuesta 201 a `wss://` (costura CDP, como `spec_changed`).
  - El cliente oficial abre el WebSocket con la URL firmada del simulador (`wss://127.0.0.1:8402/…agent_id=…&conversation_signature=…`).
  - El TLS falla: el simulador no lo modela.
  - El CRM lo enseña sin reintentar. La URL firmada no queda en ninguna parte.
  - La post-llamada web del emisor del simulador la cierra (`provider_report`, 64 s, coste prorrateado).
  - Beto da el veredicto sobre la huella ENTERA.
  - **Ana no ve su sesión: H-1.**

**El cuelgue «Esperando el micrófono…» de la pasada a medias del 09-11 05:02Z no se reprodujo.** `getUserMedia` resolvió en Chrome 156 headless con los mismos argumentos, aislado y en el CRM.

### 3. Los bloqueos de seguridad, contra la pila (nunca `Http::fake`)

Fase nueva **`salientes`** (`H8E2ESalientesPrimeroTest`, 10 pasos), salvo donde se dice.

| Bloqueo | Cómo se demuestra | Dónde |
|---|---|---|
| Credencial mala en las 4 públicas | 18 variantes de los emisores de los simuladores → 401 en todas, y ni llamadas, ni eventos, ni frenos, ni exclusiones:<br>· iniciación: `bad_secret`, `no_secret`<br>· herramientas: `bad_bearer`, `no_auth`, `basic_scheme`<br>· post-llamada: `mismatch_secret`, `mismatch_body`, `expired`, `from_future`, `missing`, `no_v0`<br>· aviso de Twilio: `wrong_token`, `master_token`, `tampered_param`, `extra_param`, `missing_header`, `scheme_flip`, `signed_over_real_url` | `salientes` h |
| Cupo por ruta | Cubo de fallos `ruta\|IP\|fallo`:<br>· 401 hasta 30 por minuto (contando los 3 anteriores) y entonces 429 con `Retry-After`<br>· una credencial BUENA sigue pasando<br>· el cubo es por ruta: la iniciación sigue en 401<br><br>Techo GLOBAL de la ruta del aviso de Twilio (60/min): con avisos distintos, 429 en el 51 (más los 10 del cupo por llamada) | `salientes` h |
| Cupo por llamada | · iniciación: 10/min por `call_sid` → la 11 en 429, UNA fila<br>· post-llamada: 10/min por evento (`post_call_audio`, que se descarta: UN `post_call_audio_discarded`) → la 11 en 429, y el webhook sigue activo<br>· aviso de Twilio: 10/min por `IdempotencyToken`, persistido UNA vez<br>· herramientas: 20 `ok` (la base), luego `tool_quota_exceeded`, y la 61 del minuto en 429 | `salientes` h y g |
| Consentimiento sin confirmar | Segunda API con alcance `contacts` (parte 4b):<br>· la puerta da EXACTAMENTE `no_voice_consent` + `rne_check_missing` (+ calendario)<br>· Ana no confirma lo que cargó (403 `four_eyes_required`); Beto sí<br>· con consentimiento y RNE, ningún motivo del destino (con el freno echado para no crear la llamada: solo `voice_braked`)<br>· **TOCTOU:** el consentimiento revocado DESPUÉS de encolar → el job la cancela con `no_voice_consent`, sin petición | `salientes` f |
| Exclusión | · el contacto excluido → la puerta dice `excluded`<br>· la exclusión manual tras encolar → el job cancela | `salientes` f; abierta g |
| `no_volver_a_llamar` | En una SALIENTE viva → excluye el número SELLADO (…0011): la puerta dice `excluded` y el job la cancela sin petición. En una entrante | `salientes` g; abierta c |
| El freno cierra herramientas | · con el freno echado por una llamada que solo está en Twilio, la herramienta de una saliente viva responde `voice_braked` (200, mensaje neutro)<br>· en una entrante | `salientes` g; abierta e |
| Cuatro ojos | · veredicto: Ana 403, otra huella 409, definitivo<br>· publicar: Ana 403 `four_eyes_required` y Carla 403<br>· promover: Ana 403<br>· consentimiento: Ana 403<br>· en el CRM, los botones = `can` | las tres fases y el CRM |
| E4-6 falla cerrado (nuevo, B8-g) | · tras `--verify-voice`, `VERIFIED` con marca vigente<br>· una verificación con la clave MALA (401 del simulador) borra la marca → `UNVERIFIED` y la puerta vuelve a decir `elevenlabs_unverified`<br>· la buena la escribe otra vez | `salientes` 0 |

### 4. «Salientes primero» (la 4a sin la 3): no depende de nada de las entrantes

**La fase `salientes`:**
- la copia del banco con la constante abierta (el mismo giro de UNA línea, comprobado con `diff`);
- `VOICE_INBOUND_ENABLED=false` y salientes con alcance `canary`.

**El servidor deduce la fase 4a.** El recorrido completo:
- **publicar en canario:** el número se asigna; ElevenLabs lo usa como origen;
- **una entrante del canario oye «no»** (`inbound_disabled`, con su fila);
- **tres salientes al canario por el JOB real** contra los dos simuladores:
  - ElevenLabs crea la llamada en Twilio con SU credencial;
  - marcar → contestar → herramienta → post-llamada firmada → `completed` + `seal_verified`, y su precio;
  - ninguna pasa por el webhook de iniciación;
- **la evidencia (E13-3)** con la tercera saliente viva y sin post-llamada:
  - `calls` 2, `missingPostCall` 1;
  - fallan `too_soon`, `too_few_calls` y `missing_post_call`;
  - promover → 409;
- **con la costura de tiempo** (la entrada canaria 25 h atrás):
  - siguen `too_few_calls` y `missing_post_call`;
  - promover → 409 por `missing_post_call`;
- **llega la post-llamada tardía:** `calls` 3, `missingPostCall` 0, la evidencia PASA;
- **promover:**
  - Ana 403;
  - Beto con la huella vieja → `evidence_changed`;
  - Beto → 201: entrada 2 `promote` `rest`, el agente sirve al resto y la cadena verifica;
  - promover solo LEE el agente en ElevenLabs.

**Dependencias de PLAN, no de código,** si el propietario elige la 4a antes de la 3:
- el contrato pone «la fase 3 estable 24 h» como criterio de la 4a;
- el giro de la constante (`voice(calls-open)`) figura en la fase 3;
- E4-6 estaba previsto «antes de la fase 3» (E14-5). Ya está en el árbol (B8-g): sin él, toda saliente es 409 `elevenlabs_unverified`.

### Mutantes (cada uno en la COPIA de una pila retenida, nunca en el árbol)

**Sobre HEAD: 8 de 8 cazados (fase `salientes`).**

| Mutante | Fichero | Rojas | La primera |
|---|---|---|---|
| M1 sin `no_voice_consent` | `OutboundPolicy.php` | 4 | la puerta 4b; y el JOB marcó al contacto revocado |
| M2 cupo de herramientas 60 → 600 | `VoiceRateLimits.php` | 1 | el 429 en la 61 |
| M3 sin el cubo de fallos | `VoiceRateLimits.php` | 1 | el 429 tras 30 fallos |
| M4 sin el tope de 20 | `VoiceTools.php` | 1 | `tool_quota_exceeded` desde la 21 |
| M5 la evidencia solo cuenta entrantes | `VoiceEvidence.php` | 10 | los recuentos de las salientes |
| M6 sin la bandera de entrantes | `InboundPolicy.php` | 6 | la entrante del canario oye «sí» |
| M7 `excluded` siempre falso | `OutboundGate.php` | 4 | la puerta y el job marcan al excluido |
| M8 sin la firma del aviso | `TwilioVoiceUsageTriggerController.php` | 5 | credencial mala aceptada |

**Sobre el árbol: 11 de 11 cazados, con la línea base sin mutar en verde.**

- **Base:** la MISMA copia de la evidencia final de `salientes` (`H8_E2E_FUENTE=…run-20261109T205148Z-ab54a256-arbol-ace6becff7a1/fuente`).
- **Hora:** 21:41–21:59Z.
- **Carga de la máquina:** la columna «carga», en el momento de cada uno.

| Mutante | Fichero | Rojas | De | Carga | La primera |
|---|---|---|---|---|---|
| M0 línea base, sin mutar | — | 0 | 195 | 15,4 | — |
| M1 sin `no_voice_consent` | `OutboundPolicy.php` | 4 | 195 | 8,8 | la puerta 4b, y el job marcó al contacto revocado |
| M2 cupo de herramientas 60 → 600 | `VoiceRateLimits.php` | 1 | 195 | 6,1 | el 429 en la 61 |
| M3 sin el cubo de fallos | `VoiceRateLimits.php` | 1 | 195 | 4,2 | el 429 tras 30 fallos |
| M4 sin el tope de 20 | `VoiceTools.php` | 1 | 195 | 4,3 | `tool_quota_exceeded` desde la 21 |
| M5 la evidencia solo cuenta entrantes | `VoiceEvidence.php` | 13 | 183 | 5,2 | los recuentos E13-3 |
| **M9 `missingPostCall` siempre 0** (E13-3) | `VoiceEvidence.php` | 5 | 195 | 7,1 | `calls` 2 y `missingPostCall` 1 |
| **M10 `calls` sin exigir la post-llamada** (E13-3) | `VoiceEvidence.php` | 4 | 195 | 10,2 | ídem |
| **M11 la marca de E4-6 vale siempre** | `VoiceProviderState.php` | 1 | 195 | 8,9 | la verificación mala no vuelve a `UNVERIFIED` |
| M6 sin la bandera de entrantes | `InboundPolicy.php` | 13 | 183 | 15,5 | la entrante del canario oye «sí» |
| M7 `excluded` siempre falso | `OutboundGate.php` | 4 | 195 | 8,5 | la puerta y el job marcan al excluido |
| M8 sin la firma del aviso | `TwilioVoiceUsageTriggerController.php` | 5 | 195 | 6,5 | credencial mala aceptada |

**La regla del número (B8-g), sobre la copia de la pasada final `de13b6ea9120`, sin la ventana de publicar (00:00Z):**

| Mutante | Fichero | Rojas | De | La primera |
|---|---|---|---|---|
| M0 línea base, sin mutar | — | 0 | 237 | — |
| **M12 `policyCheck()` siempre «verificado»** | `VoiceNumberGuard.php` | 2 | 237 | sin conciliación no dice `number_unverified` (paso 0) y con otro número no dice `number_mismatch` (g2) |

El modo retenido no ejecuta el cierre del arnés (las 10 comprobaciones de la valla, `lsof`, el registro, las colas y el barrido). Por eso salen 195 y no 205.

M5 y M6 cortan pasos enteros: 183.

### Hallazgos (con dueño)

#### H-1 (real, frontend/FE8; LAB8 si el lead cambia el contrato)

**El CRM esconde a su dueña la sesión del laboratorio que cierra el proveedor.**

- **Dónde:** `Iron-Body_Front/src/app/modules/ultron-studio/studio-voice-parse.ts:1035`. El lector rechaza `actions.end.allowed && status !== 'open'` como incoherente.
- **Qué manda el servidor (LAB8 §2.4, `StudioVoiceLabSessions.php:913-920`):** permite a la dueña contar lo que pasó en una sesión `ended` sin veredicto ni lo observado («fallida o con veredicto: 409»).
- **Petición:** `GET /api/admin/ultron-studio/voice-lab/sessions?page=1&per_page=20`, como Ana, tras la post-llamada del proveedor.
- **Respuesta:** `status: "ended", endReason: "provider_report", actions.end: {allowed: true}, actions.verdict: {allowed: false, code: "four_eyes_required"}, metrics.reported: {provider: true, tester: false}, verdict: null`.
- **Efecto:**
  - en el CRM de Ana, «1 sesión llegó ilegible y no se enseña»; no puede «Contar lo que pasó»;
  - Beto la ve (para él `end.allowed` es false) y da el veredicto: **no bloquea publicar**;
  - en la fase 2 real, TODA conversación del laboratorio acaba con la post-llamada del proveedor, así que su dueña siempre la pierde de la lista.
- **Arreglo propuesto:** admitir `end.allowed` en una sesión `ended` sin veredicto ni `reported.tester`, y un ejemplo de ese estado en `backend-voice.testing.ts`, que hoy no lo tiene.
- Lo demuestran tres pasadas del CRM.

#### H-4 (real, backend/B8)

**La idempotencia de la puerta de salientes no devuelve la misma fila.**

- **Dónde:** `OutboundGate::decide()` (`app/Services/Telephony/OutboundGate.php:82-123`).
  - Calcula los hechos CON la propia llamada original: la frecuencia de 1 al día por número, la concurrencia y `placedToday`.
  - Solo devuelve 200 si el ÚNICO motivo es `duplicate_request`.
  - Así, el reintento idéntico de una petición ACEPTADA nunca recibe «200 con la fila existente» (DF-G5 y el docblock de `OutboundGate`). Con la frecuencia legal por defecto, ese camino es inalcanzable.
- **Petición:** `POST /api/admin/voice/calls` dos veces, con el mismo `clientRequestId`, la misma persona y el mismo canario, dentro del horario y con el proveedor verificado.
- **Respuesta:**
  - la primera, 202 `{call: {status: requested}}`;
  - la segunda, **409** `outbound_denied` con `reasons: ["frequency_exceeded","duplicate_request"]`, en el registro `voice.outbound.denied` de la pasada.
- **Efecto:** no se coloca una segunda llamada (la seguridad se mantiene). Pero el reintento del CRM tras un 429 o un corte (I-1 reutiliza la clave) enseñaría «La política de salientes no permite esta llamada» con la llamada en curso.
- **Por qué no lo vio la suite:** el fixture del contrato (`outbound-gate-cases.json` `decide[28]`, de TEST8-a) modela el reintento con `frequencyExceeded: false` y `activeOrDialing: 0`, sin el efecto de la original. La política pura está bien; lo que falla es cómo `OutboundGate` le calcula los hechos.
- **Arreglo propuesto (B8):** con `$previa` aceptada, devolver su fila (o calcular los hechos sin ella). La comprobación del E2E (abierta, paso g, rama «dentro del horario») queda en rojo como regresión.

#### H-5 (B8-g, minimización; que lo juzgue security)

**`GET admin/voice/status` entrega al navegador el id del número importado en ElevenLabs.**

- **Dónde:** `VoiceTelephonyStatus::snapshot()`, `'observed' => VoiceNumberGuard::lastObservation()`.
- **Qué entrega:** devuelve la observación de la caché ENTERA: `{at, phoneNumberId, matchesFrom, collides}`. `phoneNumberId` es el `phnum_…` de ElevenLabs (la pieza `EL_PHONE_NUMBER_ID` del banco).
- **Es nuevo con B8-g:** en `HEAD` el estado no lleva `observed`.
- **Peso:** no es el número ni un secreto, y el CRM no lo enseña (el barrido del DOM pasa). Pero el E2E lo tenía en la lista de piezas que no deben llegar al navegador, como los SID de Twilio, y el CRM no lo usa.
- **Petición y respuesta:** `GET /api/admin/voice/status` (Ana), 200, con la etiqueta `EL_PHONE_NUMBER_ID` en el cuerpo (registro de red de la evidencia del CRM final).
- **Propuesta:** quitar `phoneNumberId` del DTO, o enmascararlo como el id del agente en el Studio.

#### H-2 (banco, OPS8)

- El simulador firma la URL del laboratorio con `ws://` y el CRM exige `wss://`.
- En el banco, el CRM no puede abrir una conversación sin la costura del navegador.
- **Propuesta:** un oyente TLS con certificado propio en el simulador (y `--ignore-certificate-errors` solo en el Chrome del E2E).
- No bloquea: el CRM hace bien en negarse.

#### H-3 (del E2E, corregido)

El paso h de la abierta, ver §1.

### Observaciones (no bloquean)

**O-3 (B8/LAB8, minimización).** El id del agente viaja ENTERO en:
- la telefonía: `GET admin/voice/status`, `calls` y `calls/{uuid}`, y el 202 de `POST calls`;
- el `SessionDto` del laboratorio.

El Studio (`voice/publications`) ya lo manda enmascarado, y el CRM lo enmascara al leer (D-F13). No es un secreto ni dato personal. El E2E lo admite solo en esas respuestas y exige que nunca llegue al DOM (no llega).

**O-4 (S8/B8; que lo juzgue security).** La evidencia toma `since` de la columna `created_at` de la entrada canaria (`StudioVoiceEvidence.php:103`):
- la firma no la cubre;
- `rowMatches` (`StudioVoicePublications.php:569`) no la compara con el `published_at` del JSON firmado.

Quien tenga el superusuario o el dueño de la base puede retrasarla y saltarse las 24 h de P-16 con la cadena verificando. Lo demuestra la costura de tiempo de la fase `salientes`. La aplicación no puede (`REVOKE UPDATE`).

**Propuesta:** usar `published_at` firmado, o compararlo.

**O-5 (FE, UX).** El 429 de la telefonía cumple E9-4: el mensaje del servidor, sin reintentos y sin gastar la clave. Pero el CRM no dice los segundos y «Reintentar» sigue activo durante la espera. El panel del H6 sí espera `retry_after`.

**O-6 (FE, compatibilidad con B8-g).** El CRM `45dd73a` (en producción) lee la evidencia sin romper con la séptima clave: la ignora. Enseña `missing_post_call` como «No basta (motivo: missing_post_call)». Faltan su texto y el recuento.

**O-7 (OPS8-c).** `VOICE_NUMBER_INBOUND_CAPABLE` (B8-g):
- el simulador no modela un número solo saliente;
- el E2E deja la variable sin declarar (`null`);
- el deployer lee `supports_inbound` si viene; el simulador del commit no lo manda.

No se bloquea (instrucción del lead).

**O-8 (banco).** Lo que el arnés NO puede ejercer con la segunda API: una saliente a un CONTACTO que pase la puerta y la marque el worker residente. Ese worker es del alcance canario del banco y la cancelaría `not_an_active_voice_canary`, que es un artefacto. La parte 4b llega hasta «la puerta ya no tiene motivos del destino» y la TOCTOU por el job con alcance `contacts`.

### Lo que no se pudo probar sin servicios reales (o sigue sin cubrir)

**Sin servicios reales:**
- la voz real: audio, micrófono físico, el WebSocket con TLS contra ElevenLabs y su latencia;
- la verificación real de ElevenLabs (la de E4-6 es contra el simulador);
- los precios y `metadata.cost` reales;
- la desactivación real del webhook;
- el p95 < 300 ms de la iniciación en producción;
- la CSP en el servidor;
- el número con su expediente regulatorio, la importación del número (C-A1) y los destinos de transferencia (E10-10);
- el RNE real;
- lectores de pantalla y móviles reales (solo el ancho de 390 px);
- Firefox real (simulado quitando `sampleRate`).

**Sin cubrir, aunque el banco lo permite** (fuera de esta tanda):
- buzón, comunicando, sin respuesta y duplicada de las salientes (`/__fault`, `/__call`);
- `frequency_exceeded` de un contacto en un día distinto;
- `daily_cap_reached`, `concurrency_exceeded` y `budget_exhausted`;
- promover desde el CRM: necesita 24 h o la costura de tiempo, que solo usa el backend;
- la parte 4b con el número solo saliente.

**La rama «fuera del horario legal» de la puerta real** (el paso g de la abierta y el paso `salientes` del CRM, tras E4-6) **no se ejerció hoy:** todas las pasadas finales cayeron entre las 14:20 y las 17:58 de Bogotá, un lunes no festivo. La tiene que cubrir una pasada de noche o en domingo; la anterior a E4-6 sí la cubría.

**La pasada sobre el árbol prueba trabajo sin confirmar** (B8-g a 6/9). Cuando B8-g se confirme, hay que repetir `ambas`, `salientes` y el CRM sobre ese commit (el arnés admite `H8_E2E_REF`; sin `H8_E2E_ARBOL`).

### Ficheros (sin commit)

**Modificados:**
- `tests/Feature/Voice/E2E/H8StackE2ETestCase.php`
- `tests/Feature/Voice/E2E/H8E2EAbiertaTest.php`
- `tests/Feature/Voice/E2E/H8E2ECerradaTest.php`
- `docs/ultron-studio/ops/h8-e2e.sh`
- `docs/ultron-studio/ops/h8-e2e/{README.md, ayudante.php, barrido.py, entorno-banco.ejemplo}`
- `docs/ultron-studio/ops/h8-e2e-crm/{h8-e2e-crm.sh, lib.cjs, recorridos.cjs, README.md}`
- este informe

**Nuevo:** `tests/Feature/Voice/E2E/H8E2ESalientesPrimeroTest.php`.

**Evidencia:** los directorios de la tabla, en `ops/evidencia-h8/2026-11-09/`.

**Sin tocar:** `app/`, `config/`, `routes/` ni el CRM.

## §TEST8-b (paso 3, parte 1: E2E con simuladores) — LISTO; falta la pasada final tras B8-c (F-1)

**Fecha:** 08-11-2026 (21:40–22:15 UTC). **Base:** `2b3c2936` (HEAD: commit «OPS8-b», con el simulador de ElevenLabs al
día), copias por `git archive`: lo que no está confirmado (el oyente de B8-c, hoy en el árbol de trabajo) no entra.
**Sin commit.**
**Estado:** el arnés y las dos fases están escritos y validados de punta a punta. La pasada FINAL con la evidencia en
`ops/evidencia-h8/<fecha>/` y su hash se hace cuando B8-c (el oyente del laboratorio) esté confirmado: hasta entonces
`evidencia-h8/` sigue vacío a propósito (una evidencia con F-1 abierto no es la del cierre).

**Cambios tras el commit de OPS8-b:** el laboratorio se cierra con la post-llamada web del EMISOR del simulador
(`/__emit/post-call?web=1&agent_id=`, la conversación que registró la URL firmada) y se juzga por el estado de la
sesión en la base; el emisor de biblioteca `lab-post-call.cjs` se retiró. La «deriva conocida» del simulador se quitó:
con F-2 corregido, cualquier violación del contrato cuenta (solo se restan, uno a uno, los falsos positivos de F-6
verificados por el oráculo propio).

### Cómo se ejecuta

`bash backend/docs/ultron-studio/ops/h8-e2e.sh ambas` (README en `ops/h8-e2e/README.md`). Por fase: copia del commit
(cerrada) o del banco con el giro de UNA línea (abierta, comprobado con `diff`), PG desechable con roles de producción
(`h8e2e_owner`/`h8e2e_app`, el REVOKE de DB8), 330 migraciones, comprobación previa, valla + simuladores de OPS8 +
`artisan serve --no-reload` + worker real `queue:work voice`, sondas de la valla, 3 cuentas (Ana y Beto Super Admin,
Carla Administrador), `--verify-voice` contra el simulador, PHPUnit en la copia contra la pila, cierre, resumen y hash.

### Resultado real de la última pasada de validación (`H8_E2E_EVIDENCIA=0`, sobre `2b3c2936`, 22:08–22:10 UTC, 101 s)

| Fase | Pruebas | Comprobaciones | Rojo |
|---|---|---|---|
| cerrada (18 s) | 6: 5 OK, 1 FALLO | 113: 111 OK, 2 FALLO | F-1 y «PHPUnit salió con 0» |
| abierta (74 s) | 9: 7 OK, 2 FALLO | 285: 282 OK, 3 FALLO | F-1 (los dos laboratorios) y «PHPUnit salió con 0» |

Hash de resultados: `fe8d4dcec973b6e97e58174525d778a877c953d705a317a96cefc7c7faf07aca`. Pasadas anteriores del mismo
día: `5f4ece14…` (`f7ae3a3c`, con el emisor de biblioteca y la deriva conocida), `645a50da…` (`bb239b53`, antes del
arreglo de O-1) y `a342a715…` (solo la abierta). Cierre de las dos fases en verde: **0 violaciones del contrato en
los dos simuladores sin ninguna exención** (ElevenLabs 24 y 113 peticiones, Twilio 0 y 10; F-2 corregido también al
ejecutar), la valla solo con sus 2 sondas, `lsof` sin conexiones fuera de 127.0.0.1, 0 `ERROR`, `failed_jobs` y colas
vacíos, barrido sin hallazgos. Esperas por los limitadores reales: 1 (33 s, `end` del laboratorio, en la vía alterna
de F-1). Falsos positivos de F-6 en esta pasada: 0. Simuladores: `d2635349…` (los de `2b3c2936`).

**Lo que queda cubierto (con HTTP real y SQL):**
- **a:** versión del Studio a cuatro ojos; perfil (Ana) → enviar → aprobar (Beto; la propia, 403); compile-preview
  sin red y sin escribir; laboratorio con el adaptador real (4 herramientas, agente, lectura, URL firmada ≤ 15 min, la
  repetición SIN URL); Carla 403; veredicto de otra identidad sobre la MISMA huella (Ana 403, otra huella 409,
  definitivo).
- **b:** `spec_changed` (409, sin red ni filas); Ana y Carla 403; publicar 201: entrada 1 génesis, huella = sha256 de
  los bytes, firma, número asignado en el simulador, proyección observada = compilada, destinos `[]`; `GET
  voice/publications` verificada y sin el id del proveedor; repetición `replayed`; UPDATE/DELETE de la aplicación 42501.
- **c:** 3 entrantes del canario selladas (entrada, parte, specHash, agente, versión, compilador, 4 dígitos), las 4
  herramientas con lo del catálogo (`$ 89.000` de la base, sede, sin humano, exclusión oral del número SELLADO),
  post-llamada → `completed` + `seal_verified`, guarda `clean`, sin texto de la transcripción en la base; el no
  canario `denied` con su fila y `call_not_active`; conciliación con 4 conocidas y el precio real de Twilio en
  `carrier_cost_micros` (con `cost_recorded` y `reconciled`); registro, detalle y métricas sin teléfonos.
- **d:** evidencia `{calls 3, 0, 0, 0, 0, 0}`, `failing = [too_soon]`, cadena verificada, veredicto approved; promover:
  `spec_changed`, cuatro ojos y 409 `canary_evidence_insufficient`, sin escribir ni red.
- **e:** `seal_mismatch` → freno → herramientas `voice_braked` → entrante `denied`; Carla 403, sin nota 422, suelta de
  Ana (una vez; la segunda 409); llamada solo en Twilio → `unknown_provider_call` + freno; iniciación con el secreto
  mal puesto → 401 sin fila y el agente con su defecto `no` (C-A2) → la conciliación la marca desconocida y frena (O-2);
  deriva del agente (panel) → freno, y vuelta a `ok`; deriva del número → `agent_drift` con `trigger: number_drift`
  (E6-1), y vuelta a `ok`; el corte: Carla 403, Ana `hung_up` con UN `calls_update` en Twilio, y la post-llamada
  posterior no cambia un final.
- **g:** la puerta, 409 con EXACTAMENTE `elevenlabs_unverified` + el calendario de ahora (oráculo con el fixture de
  festivos); el worker RESIDENTE con el reloj real (domingo → `outside_hours`); la saliente COMPLETA con el job en el
  martes 10:00: una `outbound-call` sin violaciones, la llamada creada en Twilio por ElevenLabs con SU credencial,
  `dialing` → `active` con la primera herramienta → `completed` + `seal_verified`, y su precio por la conciliación;
  canceladas sin petición ni evento: domingo (`outside_hours`), festivo 16/11 (`holiday`) y exclusión tras encolar
  (`excluded`).
- **f:** perfil 2 con su laboratorio y su veredicto → entrada 2 y el número al agente 2; revertir (Ana, sin cuatro ojos)
  → entrada 3 que restaura la 1 y el número al agente 1; apagar → entrada 4 sin agente y el número sin agente (el
  simulador ya no llama a Laravel); apagar otra vez 409; la cadena 1..4 encadenada, las entradas anteriores byte a byte
  iguales, todas verificadas, y UPDATE/DELETE/TRUNCATE de la aplicación 42501.
- **cerrada:** con todas las banderas encendidas, la constante cierra sola: publicar despliega un agente inerte y se
  detiene sin PATCH (`not_attempted · calls_disabled_in_code`); la entrante (número asignado a mano en el panel) `no`;
  la puerta 409 con `calls_disabled_in_code` el primero; la base rechaza una saliente sin sello (`vc_stamp_chk`, F-5);
  con la entrada de la cadena puesta a mano, la fila sellada la cancela el worker real sin petición; 0 peticiones a
  Twilio en toda la fase.

**El E2E muerde (mutantes en la copia, pila retenida):** M1 (`VoicePostCall`: el sello siempre verificado) → 7
comprobaciones rojas en e; M2 (`InboundPolicy` sin `not_an_active_voice_canary`) → 3 en c y los recuentos de d; M3
(`ElevenLabsAgentDeployer::assignNumber` sin la constante, en la copia cerrada) → 6 en b y lo que depende de él en c y
g. Y el propio E2E cazó F-1, que la suite tenía en verde.

### Hallazgos

- **F-1 (real; B8-c lo corrige):** nadie escucha `voice.lab.post_call` (`VoicePostCall.php:42` y `:194` frente a
  `StudioVoiceLabSessions.php:445`); solo las pruebas lo registran (`VoiceLabPostCallTest.php:107`). La post-llamada web
  del laboratorio (la del emisor del simulador) deja `unknown_provider_call` y echa el freno; la sesión sigue `open`.
  El E2E lo anota en rojo y sigue por la vía alterna (cierre por quien abrió la sesión y suelta del freno).
- **F-2 (simulador; OPS8-b) — CERRADO en `2b3c2936`:** las variables E1-5 (`call_direction`, `version_label`). Sin
  exención en el E2E: 0 violaciones en las dos fases.
- **F-3 (simulador; OPS8-b) — CERRADO en `2b3c2936`:** el emisor hace la post-llamada web del laboratorio; el E2E la usa.
- **F-4 (por diseño, E4-6):** ninguna saliente pasa la puerta (`elevenlabs_unverified`); el job se ejerce con la
  costura de la fila sellada.
- **F-5 (bien):** `vc_stamp_chk` no admite una saliente sin sello aplicado.
- **F-6 (simulador, OPS8, nuevo):** `pareceDatoPersonal` (`sim-comun.cjs:110`) quita los guiones y la cola de un
  `call_ref` aleatorio casa con `3\d{9}`: visto con `call_ref = 643e8716-4972-42a9-bcd6-eb3443320330` («3443320330»)
  → `dynamic_variables_personal_data`. Pasa en ~1–2 % de los uuid y también puede tocar a
  `initiation_response_personal_data`. El E2E lo verifica con un oráculo propio sobre las variables que llegaron y lo
  cuenta aparte (nunca lo esconde: si hubiera un teléfono, la comprobación falla).
- **O-1 (B8, baja):** la evidencia del canario cuenta sucesos con `created_at >= publicación` a granularidad de segundo
  (`VoiceEvidence.php:80`): un suceso anterior del mismo segundo cuenta contra la publicación (sentido seguro). El E2E
  deja pasar 1,1 s antes de publicar.
- **O-2 (operación, DOC8):** con el secreto de iniciación mal puesto, cada entrante es 401 sin fila y la conciliación
  la marca desconocida y frena: fallo cerrado, pero ruidoso.

### Lo que sigue sin cubrir, y por qué

- El consentimiento revocado tras encolar: el alcance `contacts` lee la parte `rest`, que exige promover (≥ 1 h de
  evidencia). Lo cubre `PlaceOutboundVoiceCallRecheckTest`. Tampoco los contactos 4b por la API.
- Transferencias: el laboratorio no admite perfiles con destinos (`StudioVoiceLabErrors::transfersNotSupported`) y la
  puerta `required` exige su veredicto.
- Buzón, comunicando, sin respuesta, ambigua y duplicada de las salientes: los simuladores lo permiten
  (`/__call`, `/__fault`) y no están en esta tanda.
- Lo que el banco no modela: audio, WebSocket del laboratorio, tiempos reales de los proveedores.

### Ficheros (sin commit)

`docs/ultron-studio/ops/h8-e2e.sh`; `docs/ultron-studio/ops/h8-e2e/{README.md, entorno-banco.ejemplo, ayudante.php,
reloj.php, barrido.py}`; `tests/Feature/Voice/E2E/{H8StackE2ETestCase.php, H8E2ECerradaTest.php,
H8E2EAbiertaTest.php}`; este informe.

## §E2. Enmienda 2 aplicada (E2-1; 03-11-2026, sobre `7ffd61c3`)

**Qué cambia.** La Virgen de Chiquinquirá (Ley 2578 del 01-06-2026): el 9 de julio, trasladable al lunes como los de la Ley 51 de 1983, desde 2026. Es la misma regla que `ColombianHolidays::CHIQUINQUIRA` de B8-a.
- **Fechas:** 13-07-2026 (de jueves), 12-07-2027 (de viernes) y 10-07-2028 (de domingo). Ahora hay **19 festivos por año**.
- **Marca:** sus filas llevan `pendingConfirmation` «por confirmar (Ley 2578/2026, demandada ante la Corte Constitucional; E2-1)».
- **La regla, por dos vías:** la lista escrita a mano y la del generador incluyen la fecha; el comprobador la deriva por otra vía (Gauss y su propia fórmula del lunes).

**Recuento: 978 → 988.**
- `holidays-co-2026-2028.json`: 77 → 85. Hay 8 casos nuevos: los tres lunes, sus tres fechas de origen y dos instantes en el borde de medianoche de Bogotá.
- `policy-tables.json`: 134 → 136. Hay 2 casos nuevos de `scheduleLegal`: el lunes 13-07-2026 a las 10:00 es festivo y el jueves 09-07-2026 no. **Ningún caso anterior caía en esos días**: ningún fixture tenía fechas de julio, así que no hubo que corregir nada.
- El resto, igual.
- Por base: `ley` 105, `contrato` 443, `decision-fixture` 392, `oficial` 32, `supuesto-lead` 16.

**Salida real:**

```
$ python3 -I tests/fixtures/voice/tools/build/build.py      # reproducible: los mismos sha256 en dos ejecuciones
holidays-co-2026-2028.json: {"isHoliday": 85, "total": 85}
policy-tables.json: {"scheduleLegal": 22, …, "total": 136}
total: 988
$ python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py --php       # sale con 0
ficheros: 10 · total 988
forma: OK
derivación independiente: 882 casos rehechos · discrepancias: 0
cruces entre ficheros: 221 comprobaciones
variantes equivocadas: 80 · cazadas 80                       (nueva: ho_no_ley_2578, cazada en 4 casos)
php: {…, "compiladorReal": 98, "festivosB8": 88, "fallos": 0}
RESULTADO: OK
```

**Cruce con B8.** `--php` carga ahora también `app/Services/Telephony/ColombianHolidays.php`, que es pura, y compara:
- `forYear()` de 2026, 2027 y 2028 con `years`;
- `isHolidayDate()` en los 76 casos de fecha;
- `isHoliday()` en los 9 de instante.

Son 88 cruces, **0 fallos**. Es una prueba de paridad, no del oráculo: el fixture sigue siendo la referencia.

**E2-7.** Q-17 y Q-18 se cierran tal como estaban los vectores de la cadena: sin prefijo, y `prev_hash` = sha256 del mensaje canónico anterior. No hay cambios en `voice-chain-vectors.json`.

**Ficheros de E2 (sin commit):**
- `tests/fixtures/voice/holidays-co-2026-2028.json` y `policy-tables.json`;
- `tools/build/sec_holidays.py` y `sec_policy.py`;
- `tools/voice-fixtures-check.py`;
- `README.md`;
- este informe.

## §E1. Enmienda 1 aplicada (03-11-2026, sobre `75a6384`; la entrega anterior está confirmada en `356113d1`)

### Resultado (salida real)

```
$ php tests/fixtures/voice/tools/compiler-export.php > tests/fixtures/voice/tools/build/compiler-export.json
$ python3 -I tests/fixtures/voice/tools/build/build.py
compiler-cases: instantánea del compilador real incrustada (83 casos de compilación, 15 relaciones)
twilio-signature-vectors.json: {"compute": 15, "validate": 25, "urlBuild": 5, "total": 45}
elevenlabs-webhook-bodies.json: {"postCall": 16, "guard": 9, "initiation": 11, "tools": 21, "routeRules": 11, "total": 68}
elevenlabs-webhook-vectors.json: {"verify": 27, "total": 27}
twilio-webhook-bodies.json: {"usageTrigger": 7, "callStatus": 8, "callsPage": 1, "total": 16}
holidays-co-2026-2028.json: {"isHoliday": 77, "total": 77}
policy-tables.json: {"scheduleLegal": 20, "scheduleOwn": 11, "frequency": 19, "consent": 13, "exclusions": 16, "inboundPolicy": 15, "costReservation": 17, "brake": 16, "phoneHash": 7, "total": 134}
state-transitions.json: {"allowed": 25, "matrix": 288, "insert": 24, "sequences": 19, "columns": 25, "eventDedupe": 5, "total": 386}
compiler-cases.json: {"compile": 83, "capClamp": 11, "hashRelations": 15, "canonicalHash": 9, "total": 118}
outbound-gate-cases.json: {"decide": 43, "whatsappGuard": 7, "job": 22, "total": 72}
voice-chain-vectors.json: {"chain": 5, "subkey": 3, "verify": 27, "total": 35}
total: 978

$ python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py --php                    # sale con 0
ficheros: 10 · total 978
forma: OK
derivación independiente: 872 casos rehechos · discrepancias: 0
cruces entre ficheros: 221 comprobaciones
variantes equivocadas: 79 · cazadas 79
php: {"canonicalHash+perfiles": 92, "elevenlabsVerify": 27, "envelope": 16, "huellasTelefono": 6, "firmasTwilio": 15, "pascua": 3, "cadenaVoz": 8, "compiladorReal": 98, "fallos": 0, "php": "PHP 8.3.33 (cli) (built: Jul 28 2026 17:56:10) (NTS)"}
sdk: no ejecutado (sin --sdk-python): sdkConstructEvent sigue «por confirmar con el SDK»
RESULTADO: OK
```

- **Recuento: 868 → 978 casos.** Por base:
  - `contrato`: 443;
  - `decision-fixture`: 392 (163 son de `state-transitions.json`, 98 de ellos de la matriz de 12 × 12 por dirección);
  - `ley`: 95;
  - `oficial`: 32;
  - `supuesto-lead`: 16.
- **Reproducible:** dos ejecuciones del generador dan los mismos sha256.
- **Cambios en los recuentos:**
  - `voice-chain-vectors.json`: nuevo, con 35 casos;
  - `compiler-cases.json`: 107 → 118;
  - `state-transitions.json`: 329 → 386 (estado `denied`, `dialing → active` y salidas de `active` en las salientes);
  - `elevenlabs-webhook-bodies.json`: 63 → 68;
  - `policy-tables.json`: 132 → 134.
  - Los demás, igual. `elevenlabs-webhook-vectors.json` cambia de bytes (no de casos) porque los cuerpos llevan el aviso nuevo y se vuelven a firmar.
- **No se dobla.** En una copia estropeé tres cosas:
  - una firma de la cadena;
  - la huella real de la compilación base;
  - `dialing → active` en la matriz.

  El comprobador falla en las tres:

  ```
  derivación independiente: 872 casos rehechos · discrepancias: 2
    state-transitions.json: DISCREPA «matrix · outbound · outbound: dialing → active»
    voice-chain-vectors.json: DISCREPA «chain · entry · seq 3 · publicar en canario la versión 13 con otro agente»
    --php: la cadena de voz «chain · entry · seq 3 …» no cuadra con StudioContentHasher + hash_hmac
    --php: el compilador real ya no da lo del fixture en «compile · valid · la base: versión APPROVED y perfil aprobado por otra persona»
  RESULTADO: FALLA (4)
  ```

  La huella real solo la caza `--php`: no se puede derivar sin la plantilla, y está dicho así (DF-C12).
- **Dos fallos que el comprobador me cazó a mí, ya corregidos:**
  1. Su referencia de frecuencia no contaba `active` (E1-1).
  2. El caso «dos campos» no distinguía el orden por campo del orden por motivo, porque la URL va antes que el dinero en la plantilla. Ahora el primer campo lleva una cifra y el segundo una URL, y la variante `cm_reason_major` cae.

### Qué apliqué, punto por punto

| Id | Ficheros | Qué cambia |
|---|---|---|
| **E1-9** | `compiler-cases.json` | Alineado con `app/Services/UltronStudio/StudioVoiceCompiler.php` (S8-a). Lo detalla la tabla siguiente |
| **E1-1** | `state-transitions.json`, `policy-tables.json`, `elevenlabs-webhook-bodies.json` | `dialing → active` en las salientes, y `active → completed`, `hung_up` y `failed` también en salientes; matriz de 12 × 12. La frecuencia cuenta `active`. Las herramientas valen en `dialing` o `active` y la primera hace la transición. El caso con herramientas parte de `active` y se añade una saliente sin herramientas |
| **E1-2** | `state-transitions.json`, `elevenlabs-webhook-bodies.json`, `twilio-webhook-bodies.json`, `policy-tables.json` | Estado `denied` (final, inicial de las entrantes). Las iniciaciones denegadas nacen en `denied`; con el caller ID oculto, sin huella (DF-E17). Hay una post-llamada sobre una `denied` (no cambia ni frena) y una herramienta sobre una `denied` → `call_not_active`. En la conciliación, la denegada es conocida por su fila y el tramo se enlaza con la madre (los dos pasan a contrato); solo la llamada sin fila abre el freno. La denegada no cuenta como intento |
| **E1-3** | `elevenlabs-webhook-bodies.json` (`world.aiNotice` y transcripciones), `elevenlabs-webhook-vectors.json` (refirmados), `compiler-cases.json` | El aviso nuevo, literal. El comprobador lo compara con el texto copiado del contrato. El aviso antiguo de P-9 es ahora una negativa |
| **E1-4** | **`voice-chain-vectors.json`** (nuevo) | Ver abajo |

**E1-9 en `compiler-cases.json`:**

| Qué pide E1-9 | Cómo queda |
|---|---|
| Emoji rechazado | El caso válido del emoji pasa a negativa `slot_characters`. Hay negativas nuevas de llaves (`{{system__caller_id}}`), comillas angulares y un símbolo (E1-8) |
| `leave_message` no existe | Negativa `voicemail_not_allowed`; `closedSets.voicemailPolicies = ["hang_up"]` |
| Transferir exige `solicitar_humano` | Negativa `transfer_requires_human_tool`, más una válida nueva: «dos herramientas sin transferir» |
| `no_volver_a_llamar` no se quita | Negativa `opt_out_tool_missing`. La relación «una herramienta menos» quita `consultar_planes` |
| El primer motivo es el del primer campo declarado | Orden del compilador: versión → perfil (estado, cuatro ojos, huella, campos cerrados, idioma, aviso, textos campo a campo con el orden de la plantilla, duración, herramientas, destinos, privacidad, buzón). Los tres casos solapados de antes (DF-C11, `reasonOneOf`) tienen ya motivo exacto. El caso «dos campos» distingue los dos órdenes |
| Los uuids entran en `specHash` | La relación de los uuids da `sameSpecHash: false` (contrato); DF-C7 cambia de texto |
| E1-6 y E1-5 | Invariantes `firstMessageEquals` (solo el aviso), `systemPromptContainsGreetings` y `dynamicVariables` |
| E1-7 | Las versiones que compilan pasan de decisión a contrato |
| Recalcular los hashes con el compilador real | `expected.realCompiler` en los 83 casos de compilación y las 15 relaciones (método abajo) |

### Cómo se recalcularon los hashes (E1-9: «documenta el método»)

1. **`tests/fixtures/voice/tools/compiler-export.php`** carga el autoload de Composer, sin Laravel ni base. Pasa cada caso de `compiler-cases.json` por `StudioVoiceCompiler::compile()` con la traducción de `StudioVoiceCompilerParityTest` de S8-a, copiada sin cambios:
   - los nombres de campo;
   - la huella `voice-phone` de los destinos;
   - la versión real del catálogo congelado, con `maxChars = 160 + 20 × variante` por cada huella de versión distinta en orden de aparición;
   - el tope recortado.

   Escribe la instantánea `tools/build/compiler-export.json`, con el sha256 de cada entrada.
2. **`build.py`** incrusta `expected.realCompiler` en cada caso:
   - si compila: `specHash` y `voiceProfileHash`;
   - si no: el `reason` y el `field` del compilador, con sus nombres;
   - en las relaciones: `specHashA` y `specHashB`.

   Para incrustarlos **exige** que cuadren con la expectativa neutra: el motivo y el campo traducidos, que compile, y la igualdad de huellas de cada relación. Si una entrada cambió, no usa la huella vieja (compara el sha256 de la entrada) y avisa.
3. **`voice-fixtures-check.py --php`** vuelve a lanzar el exportador sobre el fichero que se comprueba y compara los 98 resultados.

**Qué son estos valores.** Una **instantánea de regresión** del compilador de hoy, no una derivación independiente (DF-C12). Datos de la instantánea:
- compilador `62c25a98…`;
- plantilla `b584cd90…`, que es la del árbol de trabajo, con el aviso de E1-3 sin commit;
- `templateHash` `6914e2e1…`.

Si S8 cambia la plantilla a propósito, se reexporta y se revisa el cambio de huellas.

**Discrepancia declarada (única).** «La versión nombra un destino (Gerencia) que el perfil no tiene», de DF-C9. El compilador no lee `handoffAliases`, porque R-07 se aplica al desplegar con `assertDestinationsMatch`. Queda en `realCompiler.knownMismatch`, igual que en el `NOT_APPLICABLE` de S8. E1-9 no lo nombra; si el lead lo quiere fuera, se borra el caso.

### E1-4: `voice-chain-vectors.json`

**Derivación, copiada del H5** (`UltronPublishedConfig::subkey()` y `signatureFor()`, que usa `StudioPublications`):
- `k = HMAC-SHA256(clave = APP_KEY cruda (base64: decodificado), mensaje = 'ultron-studio-voice-chain')`;
- `firma = hex(HMAC-SHA256(k, mensaje))`;
- `mensaje` = el JSON canónico (`StudioContentHasher::canonicalJson`) de las 10 claves de E1-4;
- `prev_hash` = sha256 del mensaje anterior, o 64 ceros en la génesis.

**Secciones:**
- **`chain` (5).** Una cadena de cinco entradas:
  - publicar en canario (génesis);
  - promover a `rest`;
  - publicar otra versión con otro agente;
  - revertir al agente anterior (D8-9);
  - apagar la voz, una entrada `off` sin artefactos (D8-20 nivel 2).

  Cada una con su mensaje, su huella y su firma.
- **`subkey` (3).** La subclave con la APP_KEY `base64:` y con una cruda, y la del H5, que es distinta.
- **`verify` (27).** Las 5 buenas y las negativas, con su motivo:
  - `signature_mismatch`: subclave del H5, prefijo de contrato, APP_KEY rotada, campo alterado;
  - `not_canonical`: con sangría o claves desordenadas aunque la firma cuadre;
  - `signature_malformed`: firma en mayúsculas o de 63 caracteres;
  - `seq_gap`;
  - `chain_broken`: `prev_hash` ajeno, `prev_hash` = la firma anterior, génesis sin ceros;
  - `malformed`: clave de más o de menos, milisegundos, desfase en vez de Z, `seq` de texto o 0, `scope` desconocido, `off` con agente, `canary` sin `spec_hash`.

**Verificación:** openssl en el comprobador y PHP real con `--php`. Seis variantes equivocadas caen:
- etiqueta del H5;
- prefijo de contrato;
- `prev_hash` = firma;
- sin recanonicalizar;
- mayúsculas aceptadas;
- sin mirar `seq`.

**Decisiones propias (DF-V1 a DF-V6):** la forma de `prev_hash`, la fecha al segundo en UTC, los tres `scope`, los bytes canónicos, la firma en minúsculas y el orden del lector.

**Dos preguntas nuevas:**
- **Q-17.** E1-4 define el mensaje sin el prefijo `CONTRACT + "\n"` que usa el H5, y así van los vectores. Si S8-b quiere el prefijo, hay que cambiar E1-4.
- **Q-18.** `prev_hash` es la huella del mensaje anterior, no su firma.

### Lo que tiene que hacer S8 en su paridad (es su fichero; no lo toqué)

`StudioVoiceCompilerParityTest` **va a fallar** contra este fixture, como estaba previsto: sus 6 `CONFLICTS` ya no existen o han cambiado de expectativa. Lo digo leyendo su código; no la ejecuté (máquina compartida: no lanzo suites del repo). Los cambios exactos están en el README del directorio, en «Para la paridad de S8»:
- vaciar `CONFLICTS`;
- añadir las 4 traducciones nuevas de motivo: `text_characters`, `opt_out_tool_required`, `transfer_tools_mismatch` y `profile_field_invalid` en `voicemail`;
- actualizar las invariantes renombradas.

El exportador ya aplica esa misma traducción y da 0 discrepancias con el compilador real, así que, actualizada la tabla, la paridad tiene que quedar en verde.

### Ficheros de esta entrega (E1)

- **Modificados** (en `tests/fixtures/voice/`):
  - `compiler-cases.json`, `elevenlabs-webhook-bodies.json`, `elevenlabs-webhook-vectors.json`, `policy-tables.json`, `state-transitions.json`, `twilio-webhook-bodies.json` y `README.md`;
  - `tools/voice-fixtures-check.py`;
  - `tools/build/build.py`, `sec_compiler.py`, `sec_eleven_bodies.py`, `sec_policy.py`, `sec_states.py` y `sec_twilio_bodies.py`;
  - este informe.
- **Nuevos:**
  - `tests/fixtures/voice/voice-chain-vectors.json`;
  - `tests/fixtures/voice/tools/compiler-export.php`;
  - `tests/fixtures/voice/tools/build/compiler-export.json` (la instantánea);
  - `tests/fixtures/voice/tools/build/sec_chain.py`.
- **Sin cambios:** `holidays-co-2026-2028.json`, `outbound-gate-cases.json`, `twilio-signature-vectors.json`.

No toqué nada fuera de `tests/fixtures/voice/**` y este informe. No hay commit.

---

*Lo que sigue es la entrega del paso 1 tal como se confirmó en `356113d1`, antes de la Enmienda.*

## 0. Resumen

- **Entregado:** 9 fixtures, su README, el generador (`tools/build/`) y el comprobador independiente (`tools/voice-fixtures-check.py`).
- **Volumen:** 868 casos y 988 381 bytes.
- **Base de los casos:**

  | Base | Casos |
  |---|---|
  | `contrato` | 350 |
  | `ley` | 95 |
  | `oficial` | 32 |
  | `supuesto-lead` | 16 |
  | `decision-fixture` | 375 (152 son la matriz de estados de 11 × 11 por dirección) |

  Lo que el contrato no fija va como `decision-fixture`, en 69 decisiones DF-… que usan los casos (y 3 más, DF-E7, DF-G4 y DF-G6, definidas en el generador sin caso propio) que **tiene que congelar el lead**.
- **Verificación, salida real (§2):**
  - una segunda derivación independiente, con openssl, el algoritmo de Gauss y zoneinfo, rehace 767 casos con **0 discrepancias**;
  - 90 cruces entre ficheros;
  - **66 de 66** implementaciones equivocadas caen;
  - con `--php`, **0 fallos** frente a las clases reales que el H8 envuelve sin cambios: `StudioContentHasher`, `ElevenLabsWebhookSignature::verify` (27 de 27) y `ElevenLabsWebhookEnvelope::type` (14 de 14), más `hash_hmac` y `easter_days`;
  - con cinco `expected` estropeados a propósito, el comprobador **falla en los cinco**;
  - el generador es reproducible byte a byte.
- **Sin el SDK de ElevenLabs.** No hay red ni copia local (busqué en pip, npm, uv, pnpm y cachés). Los vectores salen del algoritmo documentado y llevan **`sdkVerified: false` («por confirmar con el SDK»)**. El modo `--sdk-python` queda listo y probado con una imitación (§2.5). Los 6 vectores oficiales de Twilio salen de la descarga del H6 del 31-10-2026, con el sha256 del fichero.
- **Lo que más pesa para el lead (§3):**
  - **Q-3:** leídas al pie de la letra, las herramientas no responderían en ninguna saliente;
  - **Q-6:** cualquier desconocido que llame podría abrir el freno automático;
  - **Q-1:** el aviso provisional de P-9 no dice cómo pedir no recibir más llamadas, que D8-17 y la Ley 2300 exigen;
  - **Q-14:** falta el formato de firma de la cadena de voz.

## §fixtures. Qué se entrega, recuento y fuente de cada formato

### Recuento por fichero (salida de `build.py`)

| Fichero | Bytes | Casos | Secciones |
|---|---|---|---|
| `twilio-signature-vectors.json` | 37 533 | **45** | `compute` 15 (los **6 oficiales** + 9 derivados: sin consulta, consulta en su orden, codificada, valor vacío, UTF-8, «10/9», mayúsculas, `+`, JSON con ñ), `validate` 25, `urlBuild` 5 |
| `elevenlabs-webhook-vectors.json` | 100 844 | **27** | `verify` 27: 6 válidos (uno por cuerpo), 5 de tiempo (bordes de 1800/1801 s y de +300/+301 s, uno de hace 1 día), 6 de cuerpo alterado, 3 de secreto equivocado, rotación y 6 de forma |
| `elevenlabs-webhook-bodies.json` | 111 795 | **63** | `postCall` 14, `guard` 9, `initiation` 11, `tools` 18, `routeRules` 11 |
| `twilio-webhook-bodies.json` | 48 400 | **16** | `usageTrigger` 7, `callStatus` 8, `callsPage` 1 (9 llamadas: conocidas, ambiguas resueltas, desconocida, tramo de transferencia, entrante denegada, sin tarificar) |
| `holidays-co-2026-2028.json` | 40 593 | **77** | `years` (18 festivos × 3 años, con regla, fecha de origen y día) e `isHoliday` 77 (trasladados y su fecha de origen, fijos en sábado, Semana Santa, bordes de medianoche de Bogotá en UTC) |
| `policy-tables.json` | 82 592 | **132** | `scheduleLegal` 20, `scheduleOwn` 11, `frequency` 17, `consent` 13, `exclusions` 16, `inboundPolicy` 15, `costReservation` 17, `brake` 16, `phoneHash` 7 |
| `state-transitions.json` | 125 341 | **329** | `allowed` 21, `matrix` 242, `insert` 22, `sequences` 14, `columns` 25, `eventDedupe` 5 |
| `compiler-cases.json` | 308 476 | **107** | `compile` 73 (13 válidas + 60 negativas; los 23 motivos tienen caso), `capClamp` 11, `hashRelations` 14, `canonicalHash` 9 |
| `outbound-gate-cases.json` | 126 608 | **72** | `decide` 43 (los 26 motivos de D8-12 aislados, bordes, orden y acumulación; cada uno con `codeOpen` y `codeClosed`), `whatsappGuard` 7, `job` 22 |

Cada caso dice de qué sección del contrato sale (`contractRef`) y con qué base (`basis`). Si depende de una decisión, lleva su `decision`. Cada fichero lleva en la cabecera sus fuentes y las decisiones que usa. El README del directorio explica la forma, cómo se consume cada fichero, las decisiones, las preguntas y lo que no cubre.

### Fuente oficial de cada formato

«Leída» la abrió el architect el 03-11-2026 (auditoría §d). Yo **no abrí ninguna URL hoy**, porque no hay red.

| Formato | Fuente | Fecha | Cómo entra |
|---|---|---|---|
| `X-Twilio-Signature` (HMAC-SHA1, URL exacta, `bodySHA256`, sin IP) | https://www.twilio.com/docs/usage/webhooks/webhooks-security | 03-11-2026 (auditoría §d.2, leída) | Algoritmo |
| Los 6 vectores oficiales de Twilio y la semántica de `validate()` (puerto) | https://github.com/twilio/twilio-python/blob/main/tests/unit/test_request_validator.py | 31-10-2026 (descarga del H6, sha256 del fichero `4522667e1aef9fcc1e598dad33f84315913feac4a7cc2cfe02b3f34ef5bc4509`, `informe-fixture.md` §2.5) | Literales; HMAC recalculado por openssl y PHP |
| `ElevenLabs-Signature` (`t=…,v0=…`, HMAC-SHA256 de `t.cuerpo`, 30 min) | https://elevenlabs.io/docs/agents-platform/workflows/post-call-webhooks | 03-11-2026 (auditoría §d.1: la página de hoy **no** detalla el algoritmo; lo fijó el H6 desde la investigación del 17-10) | `ElevenLabsWebhookSignature` del H6 (D8-16 «sin cambios»); el SDK, **por confirmar** |
| Cuerpos post-llamada (`post_call_transcription`, `post_call_audio`, `call_initiation_failure`; `branch_id`, `version_id`, `metadata`…) | La misma página | 03-11-2026 (auditoría §d.1, leída) | Los campos de la auditoría; `tool_calls`/`tool_results`, `metadata.phone_call` y `metadata.body` del fallo de inicio, **[por confirmar]** |
| Iniciación de entrantes y su respuesta | https://elevenlabs.io/docs/eleven-agents/phone-numbers/twilio-integration/customising-calls.md | 03-11-2026 (auditoría §d.1, leída) | Campos de la auditoría |
| Server tools (cuerpo propio, Bearer, sin HMAC) | https://elevenlabs.io/docs/eleven-agents/customization/tools/server-tools | 03-11-2026 (auditoría §d.1, leída) | El cuerpo lo define Laravel (DF-E8); `system__*` sin el LLM es C-A3 |
| `POST /v1/convai/twilio/outbound-call` | https://elevenlabs.io/docs/api-reference/twilio/outbound-call | 03-11-2026 (auditoría §d.1, leída) | Aserciones del cuerpo del job; el tipo de `twilio_machine_detection`, **[por confirmar]** |
| Usage Trigger (callback) | https://www.twilio.com/docs/usage/api/usage-trigger | **No consultada** (sin red) | La lista que conozco más la que lee el controlador del OTP; `DateFired` e `IdempotencyToken`, **[por confirmar]** |
| Recurso Call y lista Calls | https://www.twilio.com/docs/voice/api/call-resource | 03-11-2026 (auditoría §d.2, leída) y 31-10-2026 (`tests/Unit/Twilio/fixtures/twilio-api-responses.json`) | La forma de `calls_week` del H6 |
| Ley 2300 de 2023 (horario, canales, una vez al día) | https://normograma.dian.gov.co/dian/compilacion/docs/ley_2300_2023.htm | 03-11-2026 (auditoría §d.3, leída) | `scheduleLegal`, `frequency` y `consent` |
| Ley 51 de 1983 (festivos y traslado al lunes) | http://www.secretariasenado.gov.co/senado/basedoc/ley_0051_1983.html | **No consultada** (sin red) | Texto conocido de la ley; `approvedByOwner: false`, **lo aprueba el propietario** (D8-15) |
| Precios de la reserva | https://elevenlabs.io/pricing/api · https://www.twilio.com/en-us/voice/pricing/co | 03-11-2026 (auditoría §d.1 y §d.2, leídas) | Micro-US$ en la cabecera de `policy-tables.json` |
| JSON canónico y su sha256 | `app/Services/UltronStudio/StudioContentHasher.php` (H5, sin cambios) | 03-11-2026, en este árbol | Vectores exactos, cruzados con PHP |

### Ficheros (lista exacta)

```
tests/fixtures/voice/README.md
tests/fixtures/voice/twilio-signature-vectors.json
tests/fixtures/voice/elevenlabs-webhook-vectors.json
tests/fixtures/voice/elevenlabs-webhook-bodies.json
tests/fixtures/voice/twilio-webhook-bodies.json
tests/fixtures/voice/holidays-co-2026-2028.json
tests/fixtures/voice/policy-tables.json
tests/fixtures/voice/state-transitions.json
tests/fixtures/voice/compiler-cases.json
tests/fixtures/voice/outbound-gate-cases.json
tests/fixtures/voice/tools/voice-fixtures-check.py
tests/fixtures/voice/tools/build/build.py
tests/fixtures/voice/tools/build/sec_twilio_sig.py
tests/fixtures/voice/tools/build/sec_eleven_bodies.py
tests/fixtures/voice/tools/build/sec_eleven_sig.py
tests/fixtures/voice/tools/build/sec_twilio_bodies.py
tests/fixtures/voice/tools/build/sec_holidays.py
tests/fixtures/voice/tools/build/sec_policy.py
tests/fixtures/voice/tools/build/sec_states.py
tests/fixtures/voice/tools/build/sec_compiler.py
tests/fixtures/voice/tools/build/sec_gate.py
docs/ultron-studio/plans/informes-h8/informe-tester.md
```

## 1. Cómo se escribió

- **Contrato primero.**
  - Cada `expected` es un literal decidido leyendo el contrato, la ley o la documentación.
  - Donde el contrato calla, el caso lo dice (`decision-fixture` y su DF) y no se esconde como si fuera contrato.
  - El generador contrasta los literales con una referencia propia: por ejemplo, la lista de festivos está **escrita a mano** y se compara con la regla.
- **No leí el código de los paquetes paralelos**, para no contaminar el oráculo. En `git status` se ven `StudioVoiceTemplate.php` de S8 y los adaptadores nuevos de ElevenLabs de B8. Solo leí código ya en `HEAD` y en producción:
  - las clases que el H8 envuelve **sin cambios**: `ElevenLabsWebhookSignature`, `ElevenLabsWebhookEnvelope` y `StudioContentHasher`;
  - las primitivas que el contrato cita: `PhoneFingerprint`, `UltronPublishedConfig::subkey`, `StudioCanaryPhone`, `InvisibleCharacters`, `PlainText` y los patrones del motor;
  - `LimitRequestBodySize`, `TwilioUsageTriggerController` y `config/meta.php` (lista de protegidos).
- **Claves sintéticas.**
  - La `APP_KEY` es la del H6 (bytes 00..1f), para poder cruzar huellas.
  - Los secretos, tokens, SIDs e ids son inventados; los teléfonos, de ficción.
  - Ningún fichero lleva invisibles literales: el comprobador lo vigila, y también en las fuentes Python.
- **La forma de los DTO es neutra.** El contrato dice de las firmas: «B8 las fija al congelar y van al fixture». Cada fichero describe sus campos y la prueba traduce a las clases de B8 y S8: el caso no cambia.

## 2. Verificación (salida real)

### 2.1 Generador y comprobador

```
$ python3 -I tests/fixtures/voice/tools/build/build.py
twilio-signature-vectors.json: {"compute": 15, "validate": 25, "urlBuild": 5, "total": 45}
elevenlabs-webhook-bodies.json: {"postCall": 14, "guard": 9, "initiation": 11, "tools": 18, "routeRules": 11, "total": 63}
elevenlabs-webhook-vectors.json: {"verify": 27, "total": 27}
twilio-webhook-bodies.json: {"usageTrigger": 7, "callStatus": 8, "callsPage": 1, "total": 16}
holidays-co-2026-2028.json: {"isHoliday": 77, "total": 77}
policy-tables.json: {"scheduleLegal": 20, "scheduleOwn": 11, "frequency": 17, "consent": 13, "exclusions": 16, "inboundPolicy": 15, "costReservation": 17, "brake": 16, "phoneHash": 7, "total": 132}
state-transitions.json: {"allowed": 21, "matrix": 242, "insert": 22, "sequences": 14, "columns": 25, "eventDedupe": 5, "total": 329}
compiler-cases.json: {"compile": 73, "capClamp": 11, "hashRelations": 14, "canonicalHash": 9, "total": 107}
outbound-gate-cases.json: {"decide": 43, "whatsappGuard": 7, "job": 22, "total": 72}
total: 868

$ python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py --php            # sale con 0
ficheros: 9 · casos: {…} · total 868
openssl: OpenSSL 3.6.4 25 Aug 2026 (Library: OpenSSL 3.6.4 25 Aug 2026)
forma: OK
derivación independiente: 767 casos rehechos · discrepancias: 0
cruces entre ficheros: 90 comprobaciones
variantes equivocadas: 66 · cazadas 66
php: {"canonicalHash+perfiles": 82, "elevenlabsVerify": 27, "envelope": 14, "huellasTelefono": 6, "firmasTwilio": 15, "pascua": 3, "fallos": 0, "php": "PHP 8.3.33 (cli) (built: Jul 28 2026 17:56:10) (NTS)"}
sdk: no ejecutado (sin --sdk-python): sdkConstructEvent sigue «por confirmar con el SDK»
RESULTADO: OK
```

**Qué comprueba la «forma»:**
- la cabecera completa y las fuentes con sus cuatro campos;
- los recuentos y los nombres únicos con su prefijo;
- la base válida, y que un `decision-fixture` lleve su DF;
- que toda decisión usada esté definida en la cabecera, y que no haya ninguna definida sin usar;
- ningún invisible literal y el salto de línea final.

**Lo que la derivación no rehace** son 101 casos: los cuerpos de ElevenLabs (63), los de Twilio (16) y el job (22). Los validan los cruces, que comprueban:
- que cada transición citada está permitida en `state-transitions.json` y parte del estado de la fila;
- que los eventos pertenecen al conjunto cerrado;
- que cada centinela de `mustNotPersist` está de verdad en el cuerpo;
- que `envelopeType` casa con la regla del H6;
- que la clave de deduplicación es `data.conversation_id`;
- que los turnos marcados por la guarda son del agente;
- que la firma de cada aviso de gasto casa con su HTTP esperado y que `rawBody` es el formulario de `params`;
- la conversión del precio a micro-US$;
- que el estado final de cada job está permitido desde `requested`.

**Reproducible.** Dos ejecuciones del generador dan los mismos sha256 (comparados con `shasum`). Los sha256 de hoy:
- `compiler-cases.json` `436c0fe4…`;
- `outbound-gate-cases.json` `5b938e06…`;
- `state-transitions.json` `68150559…`;
- `policy-tables.json` `96210529…`;
- `holidays-co-2026-2028.json` `37a12519…`;
- `elevenlabs-webhook-vectors.json` `24d36cc9…`;
- `elevenlabs-webhook-bodies.json` `8c572b4c…`;
- `twilio-signature-vectors.json` `932fb5ea…`;
- `twilio-webhook-bodies.json` `386e28dd…`.

### 2.2 `--php`: frente a las clases reales

Carga solo tres ficheros puros, sin Laravel: `StudioContentHasher.php`, `ElevenLabsWebhookSignature.php` y `ElevenLabsWebhookEnvelope.php`. Antes de cada ejecución, `git diff --quiet` confirma que siguen **sin cambios** frente a `57bab8a`.

- **JSON canónico.** Los 9 vectores de `canonicalHash` y las 73 huellas `storedHash` de los perfiles de `compile` coinciden byte a byte con `StudioContentHasher`. Entran también las rarezas de PHP (DF-C10):
  - un objeto vacío sale `[]`;
  - unas claves `0` y `1` salen como lista;
  - U+2028 sale escapado;
  - un decimal lanza.
- **ElevenLabs.** `ElevenLabsWebhookSignature::verify` da **27 de 27** resultados iguales a los del fixture y `ElevenLabsWebhookEnvelope::type`, **14 de 14**. El fixture es, por tanto, lo que la clase que D8-16 manda usar «sin cambios» ya hace.
- **Resto.** Las firmas de Twilio con `hash_hmac('sha1')` (15), las huellas de teléfono `voice-phone` y de canario (6) y la Pascua con `easter_days()` (3).

### 2.3 El comprobador no se dobla

En una copia del scratchpad estropeé cinco `expected`:
- el T-1 de Twilio, que pasó a válido;
- el borde de 1800 s, que pasó a caducado;
- el Corpus Christi de 2027, que dejó de ser festivo;
- el orden de dos motivos de la puerta;
- el motivo de «falta end_call».

```
derivación independiente: 767 casos rehechos · discrepancias: 5
  twilio-signature-vectors.json: DISCREPA «validate · form · T-1: la firma oficial contra la URL en http (fullUrl() tras el proxy) no vale»
  elevenlabs-webhook-vectors.json: DISCREPA «verify · postCall · borde: t = now − 1800 todavía vale»
  holidays-co-2026-2028.json: DISCREPA «isHoliday · date · Corpus Christi 2027: se celebra el lunes 2027-05-31»
  compiler-cases.json: DISCREPA «compile · negative · falta end_call»
  outbound-gate-cases.json: DISCREPA «decide · canary · freno y fuera de horario: los dos, en orden»
RESULTADO: FALLA (7)
```

Los otros 2 fallos son de forma, y los cazó sin que yo los provocara a propósito: mi script de corrupción reescribió dos ficheros sin escapar los invisibles (U+0301, U+00AD, U+200B, U+200D, U+2028, U+202E), y el comprobador lo detectó.

### 2.4 Defectos del propio fixture que cazó el comprobador (ya corregidos)

1. **Tres negativas del compilador con un motivo que depende del detector.** Un teléfono «300 000 0031» también es una «cifra» para la regla de precios del motor (`\d{4,}`, `\d{1,3}\s\d{3}`). Un correo lleva un dominio que un detector de URL marca. El contrato no fija los detectores, así que esos tres casos pasan a `reasonOneOf` (DF-C11): lo que se exige es que **no compile**. Añadí un teléfono sin ambigüedad («3 0 0 0 0 0 0 0 3 1»).
2. **Un caso con un motivo anterior oculto.** Con cuatro destinos sin «Recepción», `transfer_destination_not_in_profile` saldría antes que `too_many_transfer_destinations`. Lo corregí y añadí un caso que fija el orden «el motivo antes que el campo».
3. **Una variante equivalente.** «La Ascensión en E+40» no cambia nada, porque E+39 (jueves) y E+40 (viernes) se trasladan al mismo lunes. La sustituí por «Semana Santa desplazada un día».

### 2.5 `--sdk-python`: el arnés probado, el SDK no

Monté en el scratchpad un entorno virtual sin red (`python3 -m venv --without-pip`) con una **imitación** del paquete `elevenlabs`, que implementa el algoritmo documentado. Con ella:
- el arnés da `{"vectores": 27, "discrepancias": 0}`;
- si cambio su tolerancia a 60 min, da 1 discrepancia (`caducada: t = now − 1801`). Eso prueba que el reloj se congela en `now` y que la comparación funciona.

**Esto no verifica el SDK.** El comando para hacerlo está en la cabecera de `elevenlabs-webhook-vectors.json` (`sdkVerification`):
1. instalar `elevenlabs` con la versión fijada, en una máquina con red y fuera del repo;
2. ejecutar `voice-fixtures-check.py --sdk-python <python del venv>`.

### 2.6 Variantes equivocadas que caen (66)

Entre paréntesis, los casos que la cazan.

| Área | Variantes |
|---|---|
| Twilio | sin ordenar (20), sin quitar repetidos (2), `sort()` numérico (1), claves sin mayúsculas (10), consulta reordenada como `fullUrl()` (7), `bodySHA256` sin comprobar (3), sin tolerancia de puerto (3), SHA-256 (24), base sin `rtrim` (1) |
| ElevenLabs | sin el prefijo `t.` (12), `≤` al caducar (1), sin el futuro (1), `v0` sensible a mayúsculas (1), solo el primer `v0` (1), cuerpo vuelto a serializar (3), tolerancia de 300 s (1) |
| Festivos | sin Emiliani (56), lunes que también se traslada (3), los de Pascua sin trasladar (18), Semana Santa desplazada un día (1), Pascua una semana tarde (8) |
| Horario | hora UTC (13), intervalo cerrado (3), sábado hasta las 19 (2), domingo permitido (1), sin festivos (3), horario propio mal escrito que abre (2) |
| Frecuencia | cuenta las canceladas (2), cuenta las entrantes (1), día UTC (1), 7 días móviles como semana (3), semana desde el domingo (1) |
| Consentimiento | sin cuatro ojos (2), grabada con la grabación apagada (1), empate por el id menor (1), el primer evento (4), el consentimiento del lead del motor (1) |
| Exclusiones y RNE | canarios sin exclusiones (1), `denied` ignorado (1), vigencia con el borde fuera (1) |
| Entrantes | la constante antes que la bandera (3), concurrencia con `>` (2), solo el primer motivo (12) |
| Coste | minutos por defecto (2), sin Media Streams (7), sin el tramo transferido (1), presupuesto con `<` (1) |
| Estados | finales mutables (144), hacia atrás (2), `dialing` → `canceled` (1), insertar en cualquier estado (9) |
| Compilador | autoaprobación (1), solo `APPROVED` (2), quitar invisibles antes (5), solo las frases del catálogo (2), `end_call` opcional (2), sin recortar el tope (7), herramientas con orden en la huella (1), uuid en la huella (1) |
| Puerta | solo el primer motivo (38), `daily_cap_reached` también con cupo 0 (3), `voice_contacts` prohibido (7), canarios sin exclusiones (1), concurrencia con `>` (2), WhatsApp sin el 57 nacional (1), WhatsApp literal (2) |

## 3. Preguntas para el lead (el detalle, en el README)

**Las cuatro que más pesan:**
- **Q-3 (D8-10 frente a D8-13).** Las herramientas exigen la llamada `active`, pero `active` es solo de entrantes: una saliente contestada sigue en `dialing`. Leído al pie de la letra, **ninguna herramienta respondería en una saliente**. El fixture admite `dialing` o `active` (DF-E9).
- **Q-6 (D8-19 y D8-21).** La conciliación marcaría como `unknown_provider_call`, y abriría el freno automático:
  - los **tramos de transferencia**, que son llamadas de Twilio sin fila propia;
  - **cualquier entrante denegada**, si la iniciación no deja fila.

  Así, cualquier desconocido que llame al número pararía la voz. El fixture reconoce los dos casos (DF-W4 y DF-W5). Hay que decidir si una entrante denegada crea fila: hoy no hay ningún estado para ella.
- **Q-1 (P-9 frente a D8-17, legal).** El aviso provisional no dice **la finalidad** ni **cómo pedir no recibir más llamadas**, que D8-17 exige. Es la revocación de la Ley 2300. El fixture usa el texto de P-9 literal.
- **Q-14 (D8-8).** Falta el mensaje exacto que firma la cadena de voz: ¿el prefijo `ultron-voice-config/1\n`, como el H5? Sin eso **no hay vectores de la cadena** (§5).

**El resto:**
- **Q-2.** `duplicate_request`: ¿corta la evaluación o se acumula?
- **Q-4.** Exclusiones en el alcance `canary`.
- **Q-5.** El `kind` de los avisos de gasto (`usage_alert`, fuera del conjunto cerrado).
- **Q-7.** Un `version_id` no nulo con P-2, ¿es deriva?
- **Q-8.** El horario, ¿al pedir o al marcar?
- **Q-9.** Los reintentos de ElevenLabs con el `t` original.
- **Q-10.** El tramo transferido no lo acota `max_duration_seconds`.
- **Q-11.** Los defectos 300 y 1 frente a la protección 1 («`VOICE_*` a 0»).
- **Q-12.** El aviso, ¿en el perfil o en la plantilla?
- **Q-13.** ¿`voice-phone` o `voice-phone/1`?
- **Q-15.** La frecuencia de 1 al día también para los canarios de la fase 4a.
- **Q-16.** Otros puntos sin fijar:
  - el tope de cuerpo de `usage-trigger`;
  - la longitud de los huecos;
  - NFC en los textos del perfil;
  - `version_label` en las salientes.

**Las 69 decisiones DF-… que usan los casos** (más DF-E7, DF-G4 y DF-G6, que solo están en el generador) quedan para que el lead las congele, por ejemplo en una enmienda, como E1 del H6. Las que más tocan el código:
- DF-G1 y DF-P8: las políticas **acumulan** los motivos, como `VoiceCallPolicy::decide`;
- DF-T2: tolerancia de puerto como el SDK de Twilio;
- DF-T3: `SORT_STRING`;
- DF-C1: los nombres y el orden de los 23 motivos del compilador;
- DF-S2 y DF-S3: las transiciones que el contrato no escribe;
- DF-P1: las 19:00 ya están fuera.

## 4. Observaciones para otros paquetes (no son del fixture)

- **O-1 (SEC8, S8).** `specHash` y la huella del perfil son sha256 sin clave sobre contenido que incluye el E.164 **en claro** de los destinos de transferencia. Con la base y el código se pueden probar números por fuerza bruta. Son números del personal (riesgo bajo).
- **O-2 (B8).** `OutboundContentGuard::inspect()` marca cualquier cifra. Con P-3, el adaptador de voz tiene que descontar las que devolvió `consultar_planes`. Las cifras dichas con letras quedan sin cubrir.
- **O-3 (B8).** Con el `sort()` por defecto de PHP, los valores numéricos repetidos se ordenan como números: hay que usar `SORT_STRING`. El vector «10/9» lo caza.
- **O-4 (OPS8).** Los vectores y los cuerpos firmados sirven tal cual a los simuladores, con las claves sintéticas de `keys`.

## 5. Lo que queda sin cubrir, y por qué

- **El valor de `specHash` y `payloadHash` de una compilación real.** Depende de la plantilla de S8, que está en el código (P-6) y que no leí, a propósito. Quedan fijados:
  - el **algoritmo** de la huella (vectores exactos y cruzados con PHP);
  - las **60 negativas**;
  - las **14 relaciones** entre huellas: qué cambia y qué no cambia `specHash`.
- **La carga y la proyección de ElevenLabs** (crear, leer y asignar agentes) y los casos de deriva. Sus rutas y campos están **[por confirmar]** en el contrato (D8-3) y dependen del mapeador de B8.
- **La cadena de voz** (D8-8): Q-14.
- **Lo que no es puro:**
  - los disparadores, CHECK y `REVOKE` en PostgreSQL. `state-transitions.json` da la tabla y DB8 la prueba en su puerta PG;
  - RBAC y `AuthorizationMap` (D8-22);
  - el laboratorio hablado (D8-23) y la evidencia y la promoción (D8-19, P-16);
  - el conector del RNE (P-14);
  - los DTO del CRM;
  - el E2E con simuladores. Es mi paso 3, que espera a la puerta 2.
- **El SDK de ElevenLabs.** Sin verificar (§2.5).
- **Fuentes que no abrí hoy:**
  - la Ley 51 de 1983 y la página de Usage Trigger;
  - el fichero oficial de Twilio, que sale de la descarga del H6.

  El fixture de festivos queda con `approvedByOwner: false` hasta que lo apruebe el propietario (D8-15).

## 6. Estado del árbol

- Solo añadí ficheros en `tests/fixtures/voice/` y este informe. No toqué nada más.
- Los cambios ajenos que se ven en `git status` (S8 y B8) no son míos y no los leí.
- No hay `__pycache__`: el generador ejecuta las secciones con `exec` y los scripts se lanzan con `-I`.
- Los temporales (la copia estropeada, la imitación del SDK y las salidas) están en el scratchpad de la sesión, fuera del repo.

## Cierre del lead (08-11-2026 22:42 UTC): pasada final del E2E sobre `8ed0924`

Ejecutada por el lead tras cerrar al agente (`bash backend/docs/ultron-studio/ops/h8-e2e.sh ambas`, 99 s), con el oyente del laboratorio de B8-c (E8-1/E9-1) y el simulador de OPS8-b (F-6) ya confirmados:

| Fase | Pruebas | Comprobaciones |
|---|---|---|
| Cerrada (17 s) | 6 / 6 OK | 112 / 112 OK |
| Abierta (74 s) | 9 / 9 OK | 283 / 283 OK |

- 0 violaciones en los dos simuladores (113 peticiones a ElevenLabs, 10 a Twilio), 0 falsos positivos de F-6; la valla solo con sus 2 sondas; `lsof` sin conexiones fuera de 127.0.0.1 (47 muestras); 0 ERROR; colas y `failed_jobs` vacíos; barrido limpio (31 valores en 22 ficheros).
- Hash de resultados: `6ee260efa9e55e48031588781aab7b8baf55c885fbe929bb8b77941eb4ce3beb`.
- Evidencia: `backend/docs/ultron-studio/ops/evidencia-h8/2026-11-08/20261108T224030Z-8ed09240/`.
- F-1 cerrado (B8-c), F-2 y F-6 cerrados (OPS8-b); O-1 diferido (E9-6); O-2 en el runbook.

## Cierre del lead (09-11-2026 11:55 UTC): C-1 de REV8 sobre `7824cce`

Tres pasadas el mismo día, todas con evidencia en `ops/evidencia-h8/2026-11-09/`:

| Pasada | Resultado | Causa |
|---|---|---|
| 05:59 UTC (`…T055957Z-7661b02c`) | cerrada 4/6, abierta 2/9 | 01:00 de Bogotá: publicar → 409 `outside_publication_window` y cascada. No es del código |
| 11:11 UTC (`…T111106Z-7824cce7`), con `H8_E2E_VENTANA=` | cerrada 5/6, abierta 8/9 | Máquina recién despertada: el job de salientes tardó más de los 15 s de `esperarEstado` en el paso g de las dos fases (la fila acabó `canceled` con el motivo correcto). No es del código |
| 11:53 y 11:54 UTC (`…T115302Z` y `…T115426Z`, `7824cce7`), en caliente | **cerrada 6/6 (112), abierta 9/9 (283)**; hashes `a49db7d0…` y `20bc13a1…` | — |

Pendiente para TEST8 antes de la fase 2: `esperarEstado` a 30 s (Enmienda 14, E14-2).

## Cierre del lead (10-11-2026 02:05 UTC): regresión global de TEST8-c

El proceso de la regresión sobrevivió al corte del agente y terminó: **14 061 pruebas, 311 928 aserciones, 59 rojos, 362 omitidas** (copia del árbol sin las rutas del H9). Contra `tests/baseline-failures.txt`: 55 conocidos, 0 causados por el H9, **0 nuevos del H8**; los 4 restantes explicados en la Enmienda 16, E16-29 (dos por la contaminación de entorno ya corregida, dos artefactos de la copia: PDF no versionado y copia sin `.git`). El lado «línea base» no se completa: la lista de la base ya existe y el commit no depende de él.

