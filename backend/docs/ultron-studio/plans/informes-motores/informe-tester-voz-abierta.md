# Informe de tester · las pruebas de la constante con las llamadas abiertas (C-4, commit `T`)

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código o en los documentos · **[S]** supuesto.

## Resumen para el lead

1. **`T` listo, local y sin push:** `prep/voice-calls-open-tests` = `1ef1d72e` + `X'` (`e61ef7a2`, la simulación; nunca se sube) + **`T` = `3287769b`**. `git log --oneline 1ef1d72e..HEAD` da exactamente esas dos líneas; árbol limpio. `T` solo toca `backend/tests/` (29 ficheros) [C].
2. **Medición reproducida [C]:** con solo `X'` caen exactamente los **69 casos / 35 métodos / 21 ficheros** documentados (lista idéntica). En PostgreSQL, `Feature/{Telephony,Voice}`: los mismos 19; ninguna prueba «solo PostgreSQL» depende de la constante.
3. **`X' + T` en verde [C]:** 21 ficheros + 3 nuevos, 624/624; batería 1, 3 285 con 1 fallo (el MISMO que la línea base cerrada: la puerta de diff de `OutreachIsolationTest` frente a `origin/main`, ajena); batería 2, 3 118/3 118; PG 528/528; `pint` y el comprobador del fixture, OK.
4. **No tautológico [C]:** solo `T` sobre el árbol cerrado → caen las **5 guardas** y los 79 casos de `T` (todos), y nada fuera de `T` salvo el fallo de la línea base.
5. **Guardas:** 4 literales pasan a `true` con la misma forma (la de `VoiceIsolationTest`, con su expresión regular); la del desplegador (`:234`) no era literal sino de comportamiento y sigue siéndolo. Sin skips ni condicionales sobre la constante.
6. **«Última cerradura» → la cerradura que nombran**, con la lista EXACTA de motivos; las de «todo en regla» describen lo abierto: 202, fila sellada, reserva exacta (960 500 µUS$), evento y job; el job marca una vez con lo sellado; entrante autorizada; publicar asigna el número solo si el proveedor lo confirma.
7. **El estado cerrado no se pierde:** una **copia cerrada en memoria** de los 6 lectores de la constante que deciden (las 3 políticas, el job, el iniciador y el desplegador) mantiene probada la vuelta atrás. **Técnica nueva: pido veredicto del reviewer (H-2).**
8. **Mutaciones [C]: 17/17 muertas.** En la primera pasada sobrevivía el job sin su rama de la constante; reforcé `T` (y lo rehíce sin reescribir historia: la versión 1 queda en `prep/voice-calls-open-tests-v1`).
9. **Hallazgos de producto: ninguno BLOQUEANTE.** Destaco que el número «solo saliente» de la 4a sigue negando toda entrante con la constante abierta.
10. **Fixture del H6:** 18 casos regenerados con su generador (uno renombrado); el comprobador suma la variante cerrada. **El CRM no copia ese fixture** (la premisa no es exacta): al reexportar cambian 3 líneas de `examples.voice` en `backend-integrations.testing.ts`. Propongo enmiendas H6 y H8 (§4.3).
11. **El día:** tras «4a COMPLETADO», `git cherry-pick 3287769b` sobre X, `range-diff` (=), baterías y push con la orden. Si hay conflicto, `--abort` y rehacer `T`. Antes, repetir la medición sobre la punta real de `main`. **Además, `h8-e2e.sh` muere con X en la punta: enmienda de devops (H-1).**
12. **Sin verificar:** el E2E, las pruebas del CRM, el resto de la suite con `T` y la base real del día. **Pendiente de autorización:** borrar `tmp/solo-t-c4`, `tmp/solo-t2-c4` (el hook bloquea el borrado forzado) y, si se quiere, la `-v1`.

## 0. Entorno

- Worktree: `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-voz-abierta/Iron-Body`, rama local `prep/voice-calls-open-tests`, desde `1ef1d72e`. Su propio `vendor/` y `.env.testing` [C].
- PHP 8.3.33, PHPUnit 11.5.55 [C]. SQLite en memoria (`phpunit.xml`) salvo donde se dice PostgreSQL.
- Orden: `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging <rutas>`.

## 1. La medición con solo `X'` (antes de escribir `T`)

`X'` = `e61ef7a2` «voice(calls-open): SIMULACION LOCAL para preparar las pruebas (nunca se sube)»: una línea, `app/Services/Voice/VoiceCallPolicy.php:23`, `false` → `true` [C].

| Árbol | Los 21 ficheros | Resultado |
|---|---|---|
| `1ef1d72e` (cerrado) | 614 pruebas | **OK** (24 226 aserciones) [C] |
| `X'` (abierto) | 614 pruebas | **69 fallos** (21 939 aserciones) [C] |

La lista de los 69 que caen, por nombre y conjunto de datos, es **idéntica** (`diff` vacío) a la unión de `evidencia-h9/2026-11-11/telefonia-prep/04-c4-pruebas-con-la-constante-abierta.txt` (51) y de `05-c4-segunda-tanda.txt` (los 18 de `StudioIntegrationsParityTest`) [C]. Cuadra: **69 casos, 35 métodos, 21 ficheros**.

## 2. Cómo está hecho `T` (decisiones)

1. **Solo `tests/`.** 25 ficheros PHP (21 modificados, 4 nuevos: `ClosedPolicyCopy`, `VoiceCallPolicyClosedCopyTest`, `ElevenLabsClosedCopyTest`, `PlaceOutboundVoiceCallClosedCopyTest`) y 4 del fixture de paridad (`integrations-cases.json`, su generador `sec_voice.py`, su comprobador y su README). Nada de `app/`, `config/`, `routes/`, `database/` ni `docs/` [C: `git show --stat`].
2. **Las cinco guardas literales** pasan a `true` con la misma forma: `VoiceIsolationTest` conserva `assertTrue` + la expresión regular `/public const CALLS_ENABLED_IN_CODE = true;/` exactamente una vez + el recorrido de declaraciones y lectores; `VoiceCallPolicyTest` y `ElevenLabsCallStarterTest`, `assertTrue` sobre la constante (el segundo con su mensaje); `V9StrategyGateTest`, `assertTrue` con mensaje. **`ElevenLabsAgentDeployerTest.php:234` no tenía aserción literal sobre la constante** [L]: su guarda era de comportamiento («asignar → `not_attempted` `calls_disabled_in_code`, sin red») y en `T` sigue siéndolo (asignar → UN PATCH con ese agente, `assigned` solo si el proveedor lo confirma). Los métodos cuyo nombre decía «cerradas» se renombran (si cae a las 3 de la mañana, el nombre tiene que decir lo que se rompió); nada fuera de `tests/` los cita salvo la evidencia histórica `04-c4-…txt` [C].
3. **Ningún condicional sobre la constante, ningún `markTestSkipped`/`Incomplete` nuevo** [C: `grep` sobre el diff]. Los dos `if (class_exists(...))` que había en `VoiceBoundaryTest:384` pasan a ser una aserción (más fuerte).
4. **La «copia cerrada» en el mismo proceso** (nuevo: `tests/Unit/Voice/Support/ClosedPolicyCopy.php`). La constante no es un hecho de `VoiceCallContext`, así que **el código no permite** una prueba pura de `decide()` «con el contexto cerrado» sin tocar `app/` [L]. Para no perder el estado cerrado, el soporte hace lo mismo que el arnés del E2E pero en memoria, para **todo lo que lee la constante para decidir**: las tres políticas puras (`VoiceCallPolicy`, `InboundPolicy`, `OutboundPolicy`) y, tras la primera pasada de mutaciones (§8), el job (`PlaceOutboundVoiceCall`), el iniciador (`ElevenLabsCallStarter`) y el desplegador (`ElevenLabsAgentDeployer`). Lee el fichero real, sustituye `= true;` por `= false;` en la política y renombra cada clase (en las otras cinco, además, su `use` de `VoiceCallPolicy` apunta a la copia con un alias, sin tocar el cuerpo), exige que cada sustitución ocurra **exactamente una vez**, comprueba por líneas que no cambia nada más (2 líneas por fichero) y lo carga desde un temporal del sistema que borra (0 restos comprobados) [C]. Una prueba recorre `app/` con el tokenizador y exige que cada lector de la constante que decide tenga su copia (solo el doctor y el estado la leen para decirla). En un árbol cerrado no hay `= true;` y la copia no se hace: sus pruebas caen como guardas. Las clases copiadas viven en `App\Services\{Voice,Telephony}\*CopiaCerrada` solo en memoria; ninguna prueba del repo enumera clases declaradas (`get_declared_classes`: 0 usos) [C]. **Es una técnica nueva en la suite: la marco para el veredicto del reviewer.**
5. **Las pruebas que usaban la constante como última cerradura** ahora niegan por la cerradura que nombran, sin `calls_disabled_in_code` delante y casi siempre con la lista **exacta** de motivos (antes muchas solo miraban «contiene» o «el primero»). Las que decían «con todo en regla, solo cierra la constante» describen el estado abierto: 202, UNA fila `requested` con el sello de la parte y su agente, la huella y los 4 dígitos, la reserva exacta de un móvil (**960 500 µUS$**, la cifra del runbook, paso 10), que cuenta como comprometida en el día de Bogotá, el evento `outbound_requested` y el job para ESA fila (ayudante nuevo `VoiceFixtures::assertOutboundAccepted()`; en V9, `assertAceptada()` con el solicitante `strategy`). Cuando una prueba seguía mirando la puerta después de aceptar, la aceptada se cancela como la cancela el job sin petición (`releaseAccepted()`/`liberar()`), para que la siguiente mirada parta de cero (si no, concurrencia, frecuencia y cupo ensuciarían los motivos).
6. **Dos ajustes de escenario que no relajan nada:** en V9 «fuera del horario legal» y «festivo», el viaje en el tiempo dejaba vieja la conciliación del número (2 h) y la puerta añadía `number_unverified` (correcto); con la constante cerrada nadie lo veía porque solo se miraba «contiene» y «la constante, la primera». Ahora el reloj se mueve con el número vigilado otra vez (`aLaHora()`) y se exige **exactamente** `[motivo]`, que es lo que dice el docblock de la prueba («con el resto en regla»). En `VoiceInitiationWebhookTest:183` la entrante atendida «cuelga» (`active → completed`) antes de la siguiente iniciación, para que la concurrencia no se sume a `number_outbound_only`.

## 3. Los 35 métodos

Leyenda de «estado cerrado tras `T`»: **CC** = la copia cerrada en proceso, caso a caso (las tres políticas puras); **E2E-C** = `H8E2ECerradaTest` en una copia del árbol (necesita la enmienda del arnés, §6); **R** = solo vuelve con `git revert <T>` (la vuelta atrás del repo). Las líneas son las de `1ef1d72e`.

| # | Fichero:línea (método en `T`) | Afirmaba | Afirma en `T` | Otra cerradura / estado abierto | Estado cerrado tras `T` |
|---|---|---|---|---|---|
| 1 | `Unit/Voice/VoiceIsolationTest.php:330` (`test_las_llamadas_estan_abiertas_en_el_codigo`) | `false`; regex `= false;` ×1; declarada solo en la política | `true`; regex `= true;` ×1; lo mismo de declaraciones y lectores | guarda literal | R (la guarda cerrada literal no puede coexistir con la abierta) |
| 2 | `Unit/Voice/VoiceCallPolicyTest.php:77` (`test_la_constante_abre_las_llamadas_en_el_codigo`) | `assertFalse` | `assertTrue` | guarda literal | CC: la copia vale `false` (`VoiceCallPolicyClosedCopyTest`) |
| 3 | `Unit/Voice/VoiceCallPolicyTest.php:88` (`test_los_casos_del_contrato`) | 20 casos, todos negados, `calls_disabled_in_code` el primero | los 20 casos abiertos; `allowed` ⇔ sin motivos; ninguno lleva la constante; **exactamente 5 permitidos** (todo en verde, VERIFIED, cupo 1, 20 y 21) | cada cerradura del H6 por su nombre | CC: la tabla cerrada original, literal, y «cerrar = añadir el motivo delante» caso a caso |
| 4 | `Unit/Voice/ElevenLabsCallStarterTest.php:45` (`…abiertas_en_el_codigo_y_todo_configurado_sale_una_sola_peticion`, y nuevo `…sin_la_configuracion_completa_no_sale_nada`) | `false`; `not_attempted` `calls_disabled_in_code`; nada sale | `true`; `started` con los dos ids y 200; **UN** POST a `…/v1/convai/twilio/outbound-call`; `xi-api-key` en la cabecera y no en el cuerpo; cuerpo = `requestBody()`; el número no sale en el resultado | apagado, sin clave, sin agente o región inválida → `not_configured`, nada sale | **CC**: `ElevenLabsClosedCopyTest` (cerrado, con la misma configuración, `not_attempted` sin red) |
| 5 | `Unit/Voice/ElevenLabsAgentDeployerTest.php:234` (`…asignar_un_agente_esta_abierto_en_el_codigo_y_solo_vale_si_el_numero_queda_con_el`) | asignar → `not_attempted` `calls_disabled_in_code`, nada sale; quitar → PATCH | asignar → UN PATCH `{agent_id}` a la URL del número, con la clave; `assigned` solo si vuelve ESE agente; `null` u otro → `assignment_mismatch`; quitar, igual que antes | sin la bandera → `not_configured`, nada sale | **CC**: `ElevenLabsClosedCopyTest` (asignar no sale; quitar, un PATCH) |
| 6 | `Unit/Telephony/TelephonyFixtureParityTest.php:67` | `codeClosed` tal cual; `codeOpen` derivado quitando el motivo | `codeOpen` tal cual con la clase real (3 autorizadas); `codeClosed` tal cual con la copia | — | **CC, caso a caso (15)** |
| 7 | `Unit/Telephony/TelephonyFixtureParityTest.php:126` | ídem, 43 casos | ídem; 5 permitidas abiertas | — | **CC (43)** |
| 8 | `Unit/UltronStudio/StudioIntegrationsParityTest::test_voice_call_policy_levels_and_context` (18 casos) | el fixture cerrado | el fixture regenerado abierto (§4) | — | **CC**: los 18 con la constante delante y los niveles nunca disponibles |
| 9 | `Feature/Outreach/V9/V9StrategyGateTest.php:91` (`…todo_en_regla_la_puerta_acepta_la_llamada_de_la_estrategia_y_la_entrada_de_las_personas_la_ve`) | `false`; 409 `[calls_disabled_in_code]`; nada creado | `true`; 202; UNA fila `requested` `strategy`, sin cuenta ni rol, «Estrategia renovacion-voz», parte `rest` con su agente, huella, 4 dígitos, 960 500 µUS$ el 2026-11-17; evento de sistema con `requester`/`purposeKey`; job; la entrada de las personas ve esa fila: `[frequency_exceeded, concurrency_exceeded]` | estado abierto | R (CC solo a nivel de política) |
| 10 | `V9StrategyGateTest.php:140` (18 conjuntos) | 409; contiene el motivo; la constante, la primera | 409; **exactamente `[motivo]`**; mismo orden y mismos motivos que la entrada de las personas; nada creado | las 18 cerraduras | R (CC a nivel de política) |
| 11 | `V9StrategyGateTest.php:155` | `[calls_disabled_in_code, no_publication]` | 409 `[no_publication]`, como las personas, nada creado | la publicación | R |
| 12 | `V9StrategyGateTest.php:167` | listas con la constante; nada creado | sin contacto `[contact_not_authorized, no_voice_consent, rne_check_missing]`; sin consentimiento `[no_voice_consent]`; comercial **aceptada** a ESE contacto; cobranza y servicio `[no_voice_consent]`; al final 1 fila y 1 evento, 1 job | consentimiento por finalidad | R |
| 13 | `V9StrategyGateTest.php:190` | listas con la constante; nada creado | parte `rest` en alcance `canary` → `[not_an_active_voice_canary]`; parte canaria → **aceptada al canario** de ese número (parte `canary`, su agente); sin consentimiento → `[not_an_active_voice_canary]`; alcance `contacts` → aceptada al contacto (parte `rest`); 2 filas, 2 eventos | el canario solo con consentimiento | R |
| 14 | `Feature/Telephony/OutboundIdempotencyTest.php:92` | 409 y contiene la constante; no es duplicado; nada creado | 409 con la lista exacta `[outbound_disabled, twilio_voice_disabled, number_unverified, elevenlabs_not_configured, daily_cap_zero, budget_exhausted]`; no es duplicado; nada creado | las banderas de las pruebas (todo apagado) | E2E-C (`test_g`) / R |
| 15 | `…/PlaceOutboundVoiceCallRecheckTest.php:117` (`…todo_en_regla_no_hay_motivo_y_el_job_marca_una_sola_vez_con_lo_sellado`) | el job cancela por la constante, sin red | canario: `run()` → `dialing`, UNA petición con el agente sellado, el número importado, el `call_ref`, los 4 dígitos, buzón `hang_up` y variables (`si`, `saliente`, «canario · publicación 1»); fila con los dos ids y `started_at`; evento `outbound_started`; repetir no vuelve a marcar; contacto: lo mismo con el agente del resto («resto · publicación 2») | estado abierto | **CC**: `PlaceOutboundVoiceCallClosedCopyTest` (cerrado, con todo en regla, cancela por la constante sin petición) |
| 16 | `…/PlaceOutboundVoiceCallTest.php:57` (`test_con_las_salientes_apagadas_cancela_sin_peticion_y_libera_la_reserva`) | cancela `calls_disabled_in_code`, sin petición | cancela `outbound_disabled`, sin petición ni evento; **la reserva se libera, medida** (`committedMicros` 610 500 → 0), también con `handle()` y la fábrica real | `VOICE_OUTBOUND_ENABLED=false` | **CC**: `PlaceOutboundVoiceCallClosedCopyTest` (cerrado, la constante va ANTES que las banderas) |
| 17 | `…/VoiceCommandsTest.php:50` | «Constante  : cerrada», JSON `false` | «Constante  : ABIERTA (la de VoiceCallPolicy: las llamadas en el código)», JSON `true` | — | R (el texto cerrado del doctor queda sin prueba) |
| 18 | `…/VoiceCrmRoutesTest.php:92` | `callsEnabledInCode` `false` | `true` | — | R |
| 19 | `…/VoiceCrmRoutesTest.php:101` (`…sin_la_verificacion_responde_409_sin_crear_nada_y_verificada_acepta_202`) | 409 `[calls_disabled_in_code, elevenlabs_unverified]` | 409 `[elevenlabs_unverified]`, nada creado; con la verificación del Studio → 202 aceptada (fila, sello, reserva, evento, job) | la verificación de ElevenLabs | E2E-C (`test_g`) / R |
| 20 | `…/VoiceInitiationWebhookTest.php:104` (`…canario_activo_con_las_llamadas_abiertas_queda_active_autorizado_con_su_sello_huella_reserva_y_evento`) | `no`; `denied` `calls_disabled_in_code` | `si`; fila `active`, `caller_authorized` `true`, sin motivo, `started_at`; mismo sello, huella y 4 dígitos; reserva **1 244 500 µUS$** (no los 178 900 de una denegada) comprometida; evento `authorized` con `[]`; sin freno | estado abierto | E2E-C (`test_c`) / R; CC de `InboundPolicy` |
| 21 | `…/VoiceInitiationWebhookTest.php:183` | sin declarar `[constante]`; solo saliente `[number_outbound_only, constante]` | sin declarar → `si`, `[]`; **declarado solo saliente → `no`, `denied`, `[number_outbound_only]` y nada más**; declarado `true` → `si` | `VOICE_NUMBER_INBOUND_CAPABLE=false` | R; CC de `InboundPolicy` |
| 22 | `…/VoiceInitiationWebhookTest.php:213` | `[number_outbound_only, constante, seal_mismatch]` + freno | `[number_outbound_only, seal_mismatch]` + freno | solo saliente + sello | R |
| 23 | `…/VoiceNumberIdentityTest.php:168` | listas con la constante | `[number_unverified]`; fresca que cuadra → **aceptada** (2 veces, a 0 h y a 1 h 59); a 2 h 1 → `[number_unverified]`; otra → `[number_mismatch, voice_braked]` | la vigilancia del número | R |
| 24 | `…/VoiceNumberIdentityTest.php:197` | `[constante, voice_number_is_whatsapp, voice_braked]` | `[voice_number_is_whatsapp, voice_braked]` | número de la mensajería | R |
| 25 | `…/VoiceNumberIdentityTest.php:225` | listas con la constante | aceptada con el id observado; otro id → `[number_unverified]` (+ estado y doctor); el mismo id → aceptada; el job, igual | id del número importado | R |
| 26 | `…/VoiceProviderVerificationTest.php:97` | `[constante, elevenlabs_unverified]` → `[constante]` | `[elevenlabs_unverified]`, nada creado → tras verificar, **aceptada** (y un solo GET al proveedor) | la verificación | R |
| 27 | `…/VoiceWebhookHealthBrakeTest.php:179` | línea base `[constante]`; luego `[constante, voice_braked]` | línea base aceptada; con el freno, `[voice_braked]` y nada creado | el freno | R |
| 28 | `…/VoiceWebhookHealthBrakeTest.php:218` | `[constante]` («no ve ningún freno») | sin freno → aceptada | el freno (ausente) | R |
| 29 | `…/VoiceWebhookHealthBrakeTest.php:326` | `[constante, voice_braked]`/`[constante]` alternos | `[voice_braked]` ×2 y aceptada ×2 (suelto y arreglado) | el freno | R |
| 30 | `Feature/UltronStudio/UltronStudioIntegrationsStatusTest.php:179` | política con la constante; con canario, «la constante, la primera» | la lista exacta de 5 (y 3 en `integration_test`); con canario, la exacta de 4 | la configuración por defecto | CC a nivel de política / R |
| 31 | `…/UltronStudioVoiceIntegrationsTest.php:56` | «la constante, la primera» | `{allowed:false, reasons:[5 exactos]}` | ídem | ídem |
| 32 | `…/UltronStudioVoicePublishingTest.php:637` (`…adaptador_real_y_las_llamadas_abiertas_despliega_asigna_el_numero_al_agente_nuevo_y_publica`, y nuevo `…si_el_numero_no_queda_en_el_agente_nuevo_no_publica_y_lo_devuelve`) | 409 `voice_number_not_assigned` `not_attempted` `calls_disabled_in_code`; ningún PATCH; cadena vacía | 201; agente `deployed` verificado; **UN** PATCH del número al agente nuevo; la entrada 1 del canario, firmada y aceptada por el lector de la telefonía con ese agente; todo a `el.h8s.test`; ni la clave ni el id del proveedor en la respuesta | si el proveedor no deja el número en el agente nuevo: 409 `error` `assignment_mismatch`, el PATCH de vuelta a la cadena (sin agente), nada publicado | E2E-C (`test_b`) / R |
| 33 | `Feature/Voice/VoiceBoundaryTest.php:327` | un contexto verde, negado | el contexto de la configuración por defecto, negado con sus 5 motivos exactos; el verde, permitido; nada sale | la protección 1 | CC |
| 34 | `…/VoiceBoundaryTest.php:358` (`…como_en_produccion_el_iniciador_solo_sale_una_vez_al_host_oficial`) | política `[constante]`; el iniciador no marca | política permite el verde; el iniciador, a mano, **UN** POST a `https://api.elevenlabs.io/v1/convai/twilio/outbound-call` (hosts fijos) y `started`; el doctor sin `--verify`, nada | protección 3 | E2E-C / R |
| 35 | `…/VoiceDoctorCommandTest.php:113` | «Llamadas : cerradas…» | «Llamadas : ABIERTAS … sus banderas, sus cupos y el freno.» y no «cerradas» | — | R |

**Lo que el árbol abierto ya no puede probar, ni con la copia (hallazgo para el lead, no bloqueante de `T`):** todo lector de la constante que DECIDE tiene su copia cerrada y su prueba (las seis clases; §2.4 y §8). Queda sin prueba en el árbol abierto: (1) el ensamblaje cerrado de las puertas (`OutboundGate`/`InboundGate` y las rutas HTTP con la política cerrada: 409 con la constante delante, la entrante `denied` con `calls_disabled_in_code` en `policy_reason`), que solo componen lo que ya se prueba —la decisión cerrada (CC) y la rama «negada → no se crea nada», que recorre cualquier 409 de hoy—; (2) los textos cerrados del doctor y del estado (`cerradas en el código`, `callsEnabledInCode: false`). Los dos los cubre `H8E2ECerradaTest`, **cuyo arnés no corre sobre un commit abierto** (§6), y vuelven con `git revert <T>`. Producción, en la vuelta atrás, vuelve al respaldo `BK`, que no depende de esto.

## 4. El fixture de paridad del H6, el CRM y el contrato

### 4.1 Qué cambia en `integrations-cases.json` (con su generador, como dice su README)

- **Generador** (`tools/integrations-cases/sec_voice.py`, bloque `VoiceCallPolicy`): `VP()` calcula `allowed = not reasons` y la disponibilidad de cada nivel según le queden motivos; los 18 `VP(...)` sin `calls_disabled_in_code`. `python3 -I tools/integrations-cases/build.py` regenera el fichero (antes de tocarlo, comprobé que lo reproducía byte a byte: `git status` limpio) [C].
- **Resultado** [C, comparación con un script contra la copia previa]: cambian **solo** los 18 casos `voiceCallPolicy · decide` (754 929 bytes, antes 757 171); el resto del fichero, las otras 21 secciones, los 6 `context` y `closedSets.voiceCallPolicyReasons` (sigue empezando por `calls_disabled_in_code`, porque `REASONS` no cambia), idénticos. En cada uno de los 18: `reasons` = los de antes sin el primero; `allowed` = `reasons == []`; `integration_test` y `real_call` igual. Permitidos ahora: 3 («todo listo: permitida (la constante abierta)», «ElevenLabs verificado no añade nada», «cupo diario 1»). **Un caso renombrado:** «todo listo salvo la constante del código» → «todo listo: permitida (la constante abierta)» (el nombre viejo sería falso).
- **Comprobador independiente** (`tools/integrations-cases-check.py`): su derivación pasa a la constante abierta y suma la variante `voiceCallPolicy:closed_constant` (la política cerrada). Resultado: 704 casos rehechos, **0 discrepancias, 117 variantes cazadas** (la nueva, en los 18 `decide`; `first_only` baja de 15 a 3 casos, sigue cazada); con `--php`, 0 fallos; contra la copia previa (cerrada) del fichero, DISCREPANCIA en los 18 [C].
- **README**: nueva regla **PO-3** que dice todo lo anterior y dónde vive el estado cerrado.

### 4.2 El CRM (no lo he tocado)

**Corrección a la premisa del encargo** [C]: el CRM **no copia** `integrations-cases.json`. Ni `backend-integrations.testing.ts` ni ningún fichero de `Iron-Body_Front/src` contienen sus 18 casos (`git grep` de los nombres de caso: 0). Lo que sí arrastra la apertura es otra cosa:

- `testing/backend-integrations.testing.ts` lo genera `ultron-studio:export-fixtures` con `StudioIntegrationsExport::shapes()`, que pinta el ejemplo del panel de voz (`examples.voice`) con la política REAL (`StudioIntegrations::voicePanelFrom()`). Generado sobre el árbol abierto (a un directorio del scratchpad) y comparado con la copia del CRM (`feat/ultron-studio-h9`, `19b1d8c`): **3 líneas**, las tres `"calls_disabled_in_code",` que desaparecen de `policy.reasons`, `levels[integration_test].reasons` y `levels[real_call].reasons` (líneas 1224, 1243 y 1253 de la copia). Los otros 5 ficheros que genera el comando, idénticos [C]. Diff guardado en el scratchpad (`crm-backend-integrations.diff`).
- **Qué tendrá que hacer el CRM ese día** (frontend): volver a exportar ese fichero sobre el árbol abierto y copiarlo; su paridad (`studio-integrations-parity.spec.ts`) solo comprueba los ids de los niveles y que el lector estricto lo acepte [L], así que **no debería caer** [S: no ejecuté las pruebas del CRM]. A revisar por el dueño del CRM, sin bloquear la apertura: `studio-integrations-examples.testing.ts:882-911` (ejemplo escrito a mano con la constante delante), el comentario de `studio-integrations-api.types.ts:225` («En H6 la política nunca permite»), y `testing/backend-voice.testing.ts` (respuestas CAPTURADAS de `af6bd805`, con `callsEnabledInCode: false` y 79 menciones del motivo): con la constante abierta, el CRM recibirá respuestas que ese fichero no tiene (`callsEnabledInCode: true`, motivos sin la constante, 202 en la puerta). Si el CRM supone en algún sitio que el primer motivo es la constante, se verá entonces [S].

### 4.3 Enmiendas de contrato que hacen falta (texto propuesto; no he tocado contratos)

**H6, enmienda nueva («C-4: la política con la constante abierta»):**
> Desde el commit `voice(calls-open)` del H8 (`VoiceCallPolicy::CALLS_ENABLED_IN_CODE = true`), §I6.5 deja de decir «En H6, siempre `false`»: `decide()` permite exactamente cuando no hay motivos y `calls_disabled_in_code` ya no sale (sigue en el conjunto cerrado de §I2, el primero, para la vuelta atrás). El ejemplo de §I6.6 pierde su primer motivo. El fixture del contrato `integrations-cases.json` (Enmienda 2) cambia en sus 18 casos `voiceCallPolicy · decide` y solo en ellos, como dice su README (PO-3): los mismos motivos sin `calls_disabled_in_code`, `allowed` y la disponibilidad de los niveles según queden motivos, y «todo listo salvo la constante del código» pasa a «todo listo: permitida (la constante abierta)». El comprobador independiente suma la variante de la política cerrada. El estado cerrado se sigue comprobando caso a caso sobre una copia cerrada de la política (`tests/Unit/Voice/VoiceCallPolicyClosedCopyTest.php`). Vuelta atrás: `git revert` del commit de pruebas y del de la constante, en un solo push, devuelve el fixture y este texto a la Enmienda 2.

**H8, enmienda nueva (protección 2 y su guarda):**
> La protección 2 se cumple con el commit `voice(calls-open)` (autorizado por escrito, AUT-VOZ-1). Desde él, la guarda estática (`VoiceIsolationTest::test_las_llamadas_estan_abiertas_en_el_codigo`) exige `public const CALLS_ENABLED_IN_CODE = true;` literal y una sola vez, con las mismas reglas de declaración y lectura; el estado cerrado lo prueban la copia cerrada en proceso de las seis clases que leen la constante (las tres políticas puras, el job, el iniciador y el desplegador; `ClosedPolicyCopy`) y `H8E2ECerradaTest` en una copia cerrada del árbol (el arnés construye esa copia girando la línea al revés). «Nunca se gira en el árbol compartido para probar» sigue valiendo en el otro sentido: ninguna prueba cierra la constante en el árbol.

## 5. Las salidas

Todas con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`, una batería cada vez. La línea base cerrada corre en un segundo worktree (`_worktrees/ultron-voz-abierta/segundo`, `1ef1d72e`) con su `vendor/` CLONADO (`cp -cR`), no enlazado: con un enlace, `vendor/autoload.php` resuelve el árbol real y cargaría las clases del otro worktree; comprobado con `ReflectionClass::getFileName()` [C].

### 5.1 Solo `X'` (antes de `T`): ver §1 — los 21 ficheros, 614 pruebas, **69 fallos**, los mismos 69 de la medición de devops [C].

### 5.2 `X' + T` (el árbol abierto con las pruebas nuevas)

| Batería | Cerrado `1ef1d72e` (línea base) | `X' + T` |
|---|---|---|
| Los 21 ficheros (+ las 3 pruebas nuevas en el abierto) | OK (614 pruebas, 24 226 aserciones) | **OK (624 pruebas, 24 748 aserciones)** |
| `tests/Unit/{Voice,Telephony}` + `tests/Feature/{Voice,Telephony,Outreach,UltronStudio}` | 3 275 pruebas, **1 fallo**, 604 omitidas | **3 285 pruebas, 1 fallo**, 604 omitidas |
| `tests/Unit/{UltronStudio,Outreach,Twilio}` (`Twilio` porque también lee `integrations-cases.json`) | OK (3 118) | **OK (3 118)** |

(La primera versión de `T`, `803cd016`, dio lo mismo con 620 y 3 281 pruebas; la definitiva añade las 4 de la copia cerrada del job, el iniciador y el desplegador, §8.)

El único fallo es el mismo en los dos árboles (`comm -3` de las dos listas: vacío) y es de la línea base conocida [C]:
```
1) Tests\Feature\UltronStudio\OutreachIsolationTest::test_la_puerta_de_diff_de_legacy_n8n_otp_y_motor_comercial_esta_vacia_y_la_voz_no_gana_ficheros
La puerta de diff del H9 no está vacía frente a origin/main (ULTRON Legacy, n8n, OTP o motor comercial tocados):
backend/app/Observers/Commercial/MarketingAiActionCommercialObserver.php
```
(`1ef1d72e` difiere de `origin/main` en ese fichero; `T` no lo toca). `MetaTemplateRoutesTest::test_la_puerta_de_diff…` pasó en las dos. Las +10 pruebas del abierto: las 8 de la copia cerrada (4 de la política, 2 del iniciador y el desplegador, 2 del job) y los 2 métodos nuevos (`ElevenLabsCallStarterTest`, `UltronStudioVoicePublishingTest`).

`vendor/bin/pint --test` sobre los 25 PHP de `T`: `{"result":"passed"}` (tras dejar que pint quitase un `use RuntimeException;` sobrante) [C]. Comprobador del fixture: `RESULTADO: OK` (y `--php`: `fallos PHP: 0`) [C].

### 5.3 Solo `T` (sin `X'`): la prueba de que `T` no es tautológico

Segundo worktree, rama temporal desde `1ef1d72e` y `git cherry-pick` de `T` sin `X'` (limpio; `git range-diff` → `1: 3287769b = 1: c9d2edfe`, el mismo parche sobre otro padre: ensaya también la orden del día) [C]. La constante, cerrada (`= false;`).

**`T` definitivo** (`tmp/solo-t2-c4`), los 21 ficheros + las 3 pruebas nuevas: **`Tests: 624, Assertions: 21953, Errors: 10, Failures: 69.`** → **79 casos caen: todos los de `T`** (los 69 casos de los 35 métodos, los 2 métodos nuevos y las 8 de la copia cerrada); las 545 pruebas que `T` no toca pasan [C].

**Las dos baterías con «solo `T`»** (sobre la primera versión, `803cd016`, rama `tmp/solo-t-c4`; la definitiva solo añade las 4 pruebas de la copia cerrada, que caen como las otras): batería 1, `Tests: 3281, Assertions: 297128, Errors: 6, Failures: 52, Skipped: 604.` = los 57 casos de `T` en esas carpetas + **solo** el fallo conocido de la línea base (`comm` de las listas) [C]; batería 2, `Tests: 3118, Assertions: 75146, Failures: 18.` = exactamente los 18 de paridad [C]. **Fuera de las pruebas de `T`, no cae nada.**

Las cinco guardas literales, cada una por su aserción (salida real, recortada):
```
51) Tests\Unit\Voice\VoiceIsolationTest::test_las_llamadas_estan_abiertas_en_el_codigo
Failed asserting that false is true.                      … VoiceIsolationTest.php:333
49) Tests\Unit\Voice\VoiceCallPolicyTest::test_la_constante_abre_las_llamadas_en_el_codigo
Failed asserting that false is true.                      … VoiceCallPolicyTest.php:83
47) Tests\Unit\Voice\ElevenLabsCallStarterTest::test_con_las_llamadas_abiertas_en_el_codigo_y_todo_configurado_sale_una_sola_peticion
esta prueba describe el árbol abierto (voice(calls-open))
Failed asserting that false is true.                      … ElevenLabsCallStarterTest.php:60
46) Tests\Unit\Voice\ElevenLabsAgentDeployerTest::test_asignar_un_agente_esta_abierto_en_el_codigo_y_solo_vale_si_el_numero_queda_con_el
-    0 => 'assigned', 1 => 'agent_new_0001', 2 => null      (llega not_attempted · calls_disabled_in_code)
1) Tests\Feature\Outreach\V9\V9StrategyGateTest::test_con_todo_en_regla_la_puerta_acepta_la_llamada_de_la_estrategia_y_la_entrada_de_las_personas_la_ve
el árbol abierto: el commit voice(calls-open) del H8
Failed asserting that false is true.                      … V9StrategyGateTest.php:145
```
Por qué es lo correcto que caiga TODO lo demás de `T`:
- **Las que describen el estado abierto** (aceptaciones 202, el job que marca, la entrante autorizada, publicar con el número asignado, el iniciador que sale, el doctor «ABIERTA», `callsEnabledInCode: true`): en un árbol cerrado no pasan, por definición.
- **Las que niegan por otra cerradura con la lista exacta** (las 18 de V9, número, freno, verificación, solo saliente…): en el árbol cerrado la lista lleva `calls_disabled_in_code` delante y no casa. Que caigan demuestra que ya no aceptan la constante como motivo «gratis»: si alguien cerrara la constante sin revertir `T`, se vería.
- **La copia cerrada** (4 pruebas y la mitad `codeClosed` de la paridad de la telefonía): error explícito «sin la constante abierta en el árbol no hay copia cerrada» (6 errores) [C].
- **El fixture de paridad del H6** (18): describe la constante abierta; contra la clase cerrada, no casa.

### 5.4 PostgreSQL 14 (clúster local desechable, 127.0.0.1:55432, base `ultron_studio_voz_abierta`)

`UNIT_DB=ultron_studio_voz_abierta nice -n 10 bash docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/Telephony tests/Feature/Voice`:

| Árbol | Resultado |
|---|---|
| `1ef1d72e` (cerrado) | `Tests: 526, Assertions: 209310, Skipped: 27.` — 0 fallos [C] |
| solo `X'` (abierto, sin `T`) | `Tests: 526, Assertions: 209100, Failures: 19, Skipped: 27.` — **los mismos 19 casos** de `Feature/{Telephony,Voice}` que en SQLite (`diff` vacío con la lista de los 69); ninguna prueba «solo PostgreSQL» (`Feature/Telephony/Schema`) depende de la constante: no entra nada nuevo en `T` [C] |
| `X' + T` (definitivo) | `Tests: 528, Assertions: 209470, Skipped: 27.` — **0 fallos** (+2: el job cerrado) [C] |

## 6. Los E2E con arnés (no los he lanzado)

| Prueba | Qué supone sobre la constante [L] | Qué pasa con X en la punta | Qué hay que hacer ese día |
|---|---|---|---|
| `docs/ultron-studio/ops/h8-e2e.sh` (el arnés) | `:191` exige `public const CALLS_ENABLED_IN_CODE = false;` exactamente una vez en la copia y la gira con `sed` para `abierta` y `salientes`; `:197-202` exige que `cerrada` y las otras difieran solo en esa línea; `:326` escribe `callsEnabledInCode` por fase | **Muere en el paso 1** («la constante no está en false exactamente una vez»): ninguna de las tres fases corre | Enmienda de devops (no es de `tests/`): si la fuente trae `= true;` una vez, la copia `cerrada` se hace girando al revés (`true` → `false`) y `abierta`/`salientes` son el commit tal cual; las mismas comprobaciones de diff (una línea) en el otro sentido. Sin eso, el estado cerrado de lo que no es puro (E2E-C de la tabla) no tiene ninguna prueba ejecutable |
| `tests/Feature/Voice/E2E/H8E2ECerradaTest.php` | `test_0` exige `CALLS_ENABLED_IN_CODE === false` en su copia, y `test_b`, `test_c`, `test_g` esperan `calls_disabled_in_code` (publicar, entrante, saliente y el worker) | Correcta tal cual **sobre una copia cerrada**; su docblock dice «la copia del árbol es EXACTAMENTE el commit», que deja de ser verdad | Con el arnés enmendado, nada en la prueba; actualizar su docblock (la copia cerrada es el commit con la línea girada al revés) |
| `tests/Feature/Voice/E2E/H8E2EAbiertaTest.php` | `:42` exige `true` en su copia | Correcta (la copia es el commit) | Nada, salvo el texto «solo en la del banco» |
| `tests/Feature/Voice/E2E/H8E2ESalientesPrimeroTest.php` | `:59` exige `true` | Correcta | Nada, salvo el texto «el giro de una línea del banco» |
| `H8StackE2ETestCase.php` | sin el arnés, cada prueba se marca omitida (`:55-61`) | En la batería normal siguen omitidas (son parte de las 604) [C] | — |

No tocar ningún fichero de E2E en `T` fue deliberado: el arnés es de devops y las cuatro clases pasan o fallan según la copia que les dé el arnés, no según el árbol.

## 7. Procedimiento para el lead el día de la apertura

`T` = **`3287769b`** y, detrás, `T2` = **`216b0f1b`** (la condición I-1 de la revisión) y `T3` = **`33ba6299`** (sus menores; ver la nota del lead al final). Los tres están en la rama local `prep/voice-calls-open-tests`, worktree `_worktrees/ultron-voz-abierta/Iron-Body`, encima de `X'` = `e61ef7a2`, que NUNCA se sube. Los worktrees comparten el almacén de objetos: `3287769b`, `216b0f1b` y `33ba6299` son visibles desde cualquier árbol del mismo repo sin `fetch` [C: es el mismo `.git` común]. Copia en el repositorio, por si la rama local se pierde: `ops/apertura-llamadas/` (tres parches y su README). *(Corregido por el lead tras la revisión, I-3c: aquí se citaba por error `803cd016`, la primera versión de `T`, que no se usa.)*

**Antes del día** (sin tocar `main`):
1. Veredicto del reviewer sobre `T` (y de security si lo pide: la copia cerrada carga código de `app/` transformado en memoria).
2. **Rehacer la medición sobre la base real.** `T` está hecho sobre `1ef1d72e`. Si `main` avanza (el H9 sigue), puede haber pruebas NUEVAS que dependan de la constante y que `T` no cubre. El día antes, en el worktree de preparación: llevar `X'` y `T` encima de la punta de `main` (con autorización, porque mueve la rama local: `git rebase --onto <punta de main> 1ef1d72e prep/voice-calls-open-tests`; o, sin mover nada, una rama nueva desde la punta y `git cherry-pick e61ef7a2 3287769b`) y repetir §5.1–5.4. Si cae algo que no estaba, entra en `T` (y se revisa otra vez).

**El día** (paso 9 del runbook, después de «DESPLIEGUE H8 FASE 4a … COMPLETADO»):
```bash
# raíz Iron-Body/, en un árbol LIMPIO (no el compartido `_worktrees/ultron-h8`, que usan los agentes), tras `git fetch origin`.
# La rama local `main` de esta máquina está vieja (22-10): no usarla. Lo que cuenta es HEAD == origin/main == X.   (lead, I-3b)
git rev-parse HEAD origin/main                   # los dos, el mismo commit
git log -1 --format='%H %s'                      # tiene que ser X: «voice(calls-open): …»
git show --stat --format= HEAD                   # 1 fichero, backend/app/Services/Voice/VoiceCallPolicy.php, 1+ 1-
git cherry-pick 3287769b 216b0f1b 33ba6299       # T, T2 y T3, en orden (o los rehechos del paso 2)
git range-diff 3287769b^..33ba6299 HEAD~3..HEAD  # «=» en las tres líneas: los mismos parches
git diff --name-only HEAD~3 HEAD | grep -v '^backend/tests/' ; echo "fuera de tests/: $?"  # tiene que imprimir 1 (nada fuera)
git log -1 --format=%s -- backend/app/Services/Voice/VoiceCallPolicy.php                   # sigue siendo X (h8-voice-scope.sh lo exige)
cd backend
# Los 21 ficheros de §1 y las 3 pruebas de la copia cerrada, uno por línea, en `docs/ultron-studio/ops/apertura-llamadas/bateria-rapida.txt` (lead, I-3d).
# Con xargs, porque zsh no separa en palabras un `$(…)` sin comillas:
xargs nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging < docs/ultron-studio/ops/apertura-llamadas/bateria-rapida.txt   # OK (624 tests)
nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Unit/Voice tests/Unit/Telephony tests/Feature/Voice tests/Feature/Telephony tests/Feature/Outreach tests/Feature/UltronStudio
nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Unit/UltronStudio tests/Unit/Outreach tests/Unit/Twilio
python3 -I tests/fixtures/ultron-studio/tools/integrations-cases-check.py      # RESULTADO: OK, 117 variantes
# y SOLO con la orden del propietario: git push (T no hace falta en producción; viaja con el siguiente despliegue)
```
- **Esperado:** 0 fallos, salvo las dos puertas de diff que comparan con `origin/main` (`OutreachIsolationTest::test_la_puerta_de_diff…`, `MetaTemplateRoutesTest::test_la_puerta_de_diff…`) si `main` local y `origin/main` difieren en sus zonas; cualquier otro rojo: PARAR, no subir `T`, y avisar al tester.
- **Si `cherry-pick` da conflicto:** `git cherry-pick --abort` (deja `main` en X, sin tocar nada) y NO resolverlo a mano en `main`. Un conflicto significa que alguien cambió una de estas pruebas o el fixture después de `1ef1d72e`: se rehace `T` sobre la base nueva (paso 2 de «Antes») con su verificación completa, incluida la de «solo `T`» (§5.3). Mientras tanto `main` sigue en rojo con los 69: la salida rápida es la de siempre (revert de X).
- **Si la 4a aborta:** no se sube `T` (la 4a exige X en la punta). Ver C-4 §4.4.
- **Vuelta atrás en el repo:** `git revert <T'>` y `git revert <X>` en un solo push. El revert de `T'` devuelve byte a byte las pruebas cerradas, el fixture de paridad, su generador, su comprobador y su README [C: `T` solo añade/cambia esos ficheros; los dos nuevos se borran].
- **El E2E con arnés NO corre con X en la punta** (§6): enmendar `h8-e2e.sh` antes de volver a lanzarlo.

## 8. Mutaciones

En una copia aislada del backend con `X' + T` (`git archive` + `vendor` clonado, en el scratchpad de la sesión, fuera de los dos árboles). Cada mutación es UNA sustitución exacta en `app/`; se corren las pruebas que la tienen que matar; se restaura el fichero y se comprueba su sha256; al final, sha256 de los 1 484 PHP de `app/` idéntico antes y después [C].

**Primera pasada (con el `T` de la primera versión): 14 muertas, 1 VIVA** — M15, el job sin su rama de la constante: con la constante abierta esa rama no se ejecuta y ninguna prueba del árbol la veía. Es el hueco que la tabla de §3 marcaba (el job cerrado solo en el E2E). **Refuerzo** (el encargo pide reforzar si alguna sobrevive): la copia cerrada se extiende a los tres lectores de la constante que no son puros (el job, el iniciador y el desplegador; la misma técnica, 2 líneas por fichero) y entran dos pruebas: `Feature/Telephony/PlaceOutboundVoiceCallClosedCopyTest` (cerrado, el job cancela por la constante aunque todo lo demás esté en regla y antes que las banderas, sin petición y liberando la reserva) y `Unit/Voice/ElevenLabsClosedCopyTest` (cerrado, el iniciador no sale con la configuración completa —y el abierto sí, con la misma—; asignar un agente no sale y quitarlo sí). Además, `VoiceCallPolicyClosedCopyTest` recorre `app/` con el tokenizador y exige que **todo lector de la constante que decide tenga su copia** (si alguien añade otro, cae).

**Segunda pasada (el `T` definitivo): 17 de 17 muertas** [C]:

| # | Mutación (con la constante abierta) | La matan |
|---|---|---|
| M1 | `VoiceCallPolicy`: deja de mirar el cupo | `VoiceCallPolicyTest::test_los_casos_del_contrato`, `StudioIntegrationsParityTest` (5 casos) |
| M2 | `VoiceCallPolicy`: deja de mirar el canario | `VoiceCallPolicyTest`, `UltronStudioIntegrationsStatusTest` |
| M3 | `OutboundPolicy`: ignora la verificación del proveedor | `VoiceCrmRoutesTest` (la 409 → 202), `VoiceProviderVerificationTest` |
| M4 | `OutboundPolicy`: ignora el freno | `VoiceWebhookHealthBrakeTest` (2) |
| M5 | `OutboundGate::decide`: no toma la reserva (0) | `VoiceCrmRoutesTest` (960 500 exactos) |
| M6 | `OutboundGate::decideForStrategy`: no toma la reserva | `V9StrategyGateTest` (3) |
| M7 | `OutboundPolicy`: ignora la vigilancia del número | `VoiceNumberIdentityTest` (2) |
| M8 | el job no mira si las salientes están encendidas | `PlaceOutboundVoiceCallTest` (la de las salientes apagadas) |
| M9 | el job marca con el agente de verificación, no el sellado | `PlaceOutboundVoiceCallRecheckTest` |
| M10 | `InboundGate`: el número solo saliente atiende entrantes | `VoiceInitiationWebhookTest` (2) |
| M11 | el desplegador no exige que el proveedor confirme el agente | `ElevenLabsAgentDeployerTest`, `UltronStudioVoicePublishingTest` (el nuevo de la discrepancia) |
| M12 | el iniciador sale sin la configuración completa | `ElevenLabsCallStarterTest` (el nuevo de `not_configured`) |
| M13 | (cerrado) `VoiceCallPolicy` pierde su rama de la constante | `VoiceCallPolicyClosedCopyTest` (2) |
| M14 | (cerrado) `InboundPolicy` pierde su rama de la constante | `TelephonyFixtureParityTest` (`codeClosed`) |
| M15 | (cerrado) el job pierde su rama de la constante | `PlaceOutboundVoiceCallClosedCopyTest` (2) — **antes, VIVA** |
| M16 | (cerrado) el iniciador pierde su rama de la constante | `ElevenLabsClosedCopyTest` |
| M17 | (cerrado) el desplegador asigna sin mirar la constante | `ElevenLabsClosedCopyTest` |

M13–M17 son el argumento a favor de la copia cerrada: son cambios **invisibles** con la constante abierta (esas ramas no se ejecutan) que romperían la vuelta atrás, y sin la copia ninguna prueba del árbol los vería hasta el día en que se revirtiese.

## 9. Hallazgos

**De producto con la constante abierta: ningún BLOQUEANTE encontrado** [C, en lo ejercitado]. Lo que se recorrió abierto y se comportó como dice el contrato:
- la puerta de las personas y la de las estrategias aceptan solo con TODO en regla, y cada una de las 18 cerraduras de `OutboundPolicy` provocables basta sola para negar sin crear nada (409, la lista exacta, sin fila ni evento ni job);
- la fila aceptada nace `requested`, sellada con la parte que atiende el alcance (canario → `canary` y su agente; contacto en `contacts` → `rest`), con la reserva exacta (960 500 µUS$ a un móvil), que cuenta como comprometida; el solicitante `strategy` sin cuenta ni rol; la entrada de las personas VE la fila de la estrategia (`frequency_exceeded`, `concurrency_exceeded`);
- el job: con las salientes apagadas cancela sin petición y **libera la reserva** (610 500 → 0, medido); con todo en regla marca UNA vez con el agente sellado, el número importado y el `call_ref`, y no repite;
- **el número declarado solo saliente (`VOICE_NUMBER_INBOUND_CAPABLE=false`, el de la 4a) sigue negando TODA entrante**, ahora con `number_outbound_only` como único motivo (antes iba acompañado de la constante, que lo tapaba); con un agente viejo en el número, además `seal_mismatch` y freno;
- el canario de voz activo con las entrantes encendidas se autoriza (`active`, `si`, reserva de entrante 1 244 500 µUS$): **es el estado de la fase 3**, que la 4a no enciende (en la 4a lo impide el número solo saliente, comprobado arriba);
- publicar con el adaptador real asigna el número al agente nuevo y solo si el proveedor lo confirma; si no, 409 y el número vuelve a lo que diga la cadena;
- el iniciador, a mano y con la configuración de producción, sale UNA vez y solo al host oficial.

**Para el lead / backend / devops (no bloquean `T`):**
- **H-1 (devops, antes del siguiente E2E): `h8-e2e.sh` muere con X en la punta** (§6). Con la copia cerrada en proceso, los lectores de la constante siguen probados cerrados (M13–M17 muertas, §8); lo que queda solo para el E2E es el ensamblaje cerrado de las puertas y los textos del doctor y del estado (§3, último párrafo).
- **H-2 (tester/reviewer/security): la técnica de la copia cerrada en proceso es nueva.** Carga seis clases de `app/` transformadas (2 líneas cada una, comprobadas) desde un temporal del usuario (`tempnam`, que lo crea en 0600) que se borra al cargarlo; las clases viven en `App\…\*CopiaCerrada` solo en la memoria del proceso de pruebas. Pido veredicto explícito. Si no se acepta: quitar `ClosedPolicyCopy`, `VoiceCallPolicyClosedCopyTest`, `ElevenLabsClosedCopyTest`, `PlaceOutboundVoiceCallClosedCopyTest` y la mitad `codeClosed` de `TelephonyFixtureParityTest` (que volvería a derivar quitando el motivo); M13–M17 volverían a sobrevivir y el estado cerrado quedaría solo en el E2E y en el revert.
- **H-3 (backend, deuda de texto; no se puede arreglar con la constante abierta en un caso):** comentarios de `app/` que pasan a ser falsos: `VoiceCallPolicy.php:10` («EN ESTE HITO NUNCA PERMITE NADA»; no se puede tocar mientras esté abierta, regla de `h8-voice-scope.sh`), `OutboundGate.php:41-42` («toda petición es 409»), `StudioIntegrations.php:339` («nunca permite»), el docblock de `PlaceOutboundVoiceCall::recheckAfterCode()` («donde la constante está cerrada y `run()` nunca pasa de ella»). Ninguno cambia comportamiento.
- **H-4 (CRM):** la premisa de que el CRM copia `integrations-cases.json` no es exacta (§4.2); lo que arrastra la apertura es `examples.voice` de `backend-integrations.testing.ts` (3 líneas) y, a revisar, `backend-voice.testing.ts` (respuestas capturadas con la constante cerrada).
- **H-5 (tester, para la próxima):** dos escenarios de V9 («fuera del horario legal», «festivo») tenían un motivo extra escondido detrás de la constante (`number_unverified` por el viaje en el tiempo); ahora se fija y se exige la lista exacta. Es una señal de que las pruebas que miraban «contiene» + «la constante, la primera» podían esconder motivos de más.

## 10. Lo que NO pude verificar

- **El E2E con arnés** (las tres fases): no lo lancé (encargo) y, con X en la punta, el arnés no corre (§6) [L].
- **Las pruebas del CRM**: no las ejecuté ni toqué el CRM. Que `studio-integrations-parity.spec.ts` acepte el `backend-integrations.testing.ts` reexportado es [S] por lectura del lector; y lo que haga el CRM con respuestas reales abiertas (`callsEnabledInCode: true`, motivos sin la constante, 202) solo lo leí por encima (`studio-voice-page.ts:85`, `studio-voice-activation.ts:386/427` ya tratan el booleano en los dos sentidos) [L].
- **El resto de la suite** (`tests/Unit` y `tests/Feature` fuera de las dos baterías): no lo corrí con `T`. La medición de devops y del lead dice que con la constante abierta allí no cae nada más que los 18 de paridad [L]; `T` solo cambia lo que usan esas dos baterías: el rasgo `VoiceFixtures` lo usan `Feature/{Telephony,UltronStudio,Outreach}` y el fixture de paridad, `Unit/{UltronStudio,Twilio}` y la prueba nueva [C, `grep`], todas dentro.
- **PostgreSQL** solo para `tests/Feature/{Telephony,Voice}` (encargo); el resto de las baterías, en SQLite.
- **La base real del día**: todo está medido sobre `1ef1d72e`. Si `main` avanza antes de abrir, hay que repetir la medición (§7, «Antes», paso 2).
- **Red real, proveedores reales, producción**: nada (ni lo intenté). Todas las peticiones, a `Http::fake` con `preventStrayRequests()`.
- `Co-Authored-By` del commit `T`: el que pidió el lead (`Claude Fable 5.2`), no el que trae mi configuración por defecto; lo señalo por si el lead quiere otro antes de revisarlo (no he reescrito nada).

## 11. Ramas y restos que quedan (para el lead)

- `prep/voice-calls-open-tests` = `1ef1d72e` → `e61ef7a2` (`X'`) → **`3287769b` (`T`)**: `git log --oneline 1ef1d72e..prep/voice-calls-open-tests` da exactamente esas dos líneas; árbol limpio [C].
- **`prep/voice-calls-open-tests-v1` → `803cd016`**: la primera versión de `T`, antes del refuerzo de §8. No reescribí historia: el `T` definitivo lo creé con `git write-tree` + `git commit-tree -p e61ef7a2` y le di el nombre de la rama después de renombrar la anterior; `803cd016` sigue ahí, intacto. Se puede borrar cuando el reviewer haya visto el definitivo.
- **`tmp/solo-t-c4` (`4d73be3f`) y `tmp/solo-t2-c4` (`c9d2edfe`)**: las ramas de «solo `T`» (§5.3). El encargo decía borrarlas, pero el hook de esta máquina bloquea el borrado forzado de ramas («pide autorización explícita») y no lo he esquivado. Son cherry-picks de `803cd016` y `3287769b` (mismo parche, `range-diff` `=`): borrarlas no pierde nada. Las borra el lead con autorización (borrado forzado de las dos ramas `tmp/…`, y si quiere la `-v1`).
- El segundo worktree (`_worktrees/ultron-voz-abierta/segundo`) ya está retirado (`git worktree remove`, sin forzar) [C].
- La base PG `ultron_studio_voz_abierta` queda en el clúster local desechable (como las de las demás tandas).
- En el scratchpad de la sesión: la copia de mutaciones, las salidas completas de todas las baterías (`out/*.out`, `out/*.fails`), el diff del CRM y los guiones (`run.sh`, `runpg.sh`, `mutar.py`).

---

## Nota del lead (11-11-2026, tras la revisión de `informe-reviewer-voz-abierta.md`)

- **Veredicto del reviewer:** `T` aprobado con condiciones y la copia cerrada en proceso, aceptada con condiciones; ningún bloqueante, tampoco de producto.
- **I-1 cerrada por el lead** en la misma rama local, como un segundo commit: **`216b0f1b`** (`T2`). Las dos aceptaciones exigen exactamente UN job para la fila, en la conexión y la cola `voice`. Comprobado:
  - la mutación R6 del reviewer (la puerta encola dos veces) muere ahora en los dos caminos de la puerta (2 y 3 fallos, «UN job para esa fila»);
  - con la constante abierta, voz y telefonía 901 (también en orden aleatorio, semilla 4242) y V9, paridad y Studio 515, en verde; la batería rápida, `OK (624 tests)`;
  - con la constante cerrada, las pruebas de `T` siguen cayendo (29 de 51 en cuatro ficheros);
  - cada prueba de la copia cerrada, sola: 4, 2 y 2 en verde; 0 temporales.
  
  **Falta** que un reviewer vuelva a mirar ese diff, como pide su informe (§7, «Antes del día», 1).
- **M-1, también en `T2`:** `ClosedPolicyCopy::load()` exige las dos líneas antes de cargar cada copia.
- **I-3 (a–d), hecha en los documentos:** el paso 9 de `ops/RUNBOOK_PRIMERA_LLAMADA.md` lleva el procedimiento completo, y el §7 de este informe quedó corregido en su sitio (árbol limpio con `HEAD == origin/main`, los dos commits, y la lista de la batería rápida en `ops/apertura-llamadas/bateria-rapida.txt`, con la orden probada tal cual).
- **M-4, hecha:** la enmienda del H8 propuesta en §4.3 dice ya seis clases.
- **Copia en el repositorio:** `ops/apertura-llamadas/` guarda `T`, `T2` y `T3` como parches, por si la rama local se pierde. Comprobado con un índice temporal: aplicados sobre `e61ef7a2` dan el mismo árbol que `33ba6299`.
- **M-2, M-3 y M-5, hechas por el lead** en un tercer commit de la rama local: **`33ba6299`** (`T3`). El recorrido de lectores exige ahora la lista exacta de quienes llaman a `VoiceCallPolicy::decide(` (un llamador nuevo sin copia cerrada pone la prueba en rojo: comprobado con un fichero de prueba); tres docblocks y la línea del README del fixture dicen lo que hay; y el docblock de `UltronStudioVoiceOutboundOnlyTest` ya no dice «con la constante cerrada». La batería rápida sigue en `OK (624 tests)` y el comprobador del fixture en `RESULTADO: OK`.
- **I-2, H-1 e I-4, hechas por devops** (`informe-devops-apertura-arneses.md`): los dos arneses funcionan con la apertura en la punta y el camino de la reapertura está en la sección E del runbook.
- **El día de la apertura se aplican los tres, en orden:** `git cherry-pick 3287769b 216b0f1b 33ba6299`. `803cd016` es la primera versión de `T`: no se usa.
- **Sin hacer, anotado:** que un reviewer vuelva a mirar `T2` y `T3`; y, del informe de devops, las fechas fijas de las pruebas E2E con arnés, que ya son pasado (§2.4 de su informe).
- **Ramas locales que sobran** (su borrado necesita la autorización del propietario): `prep/voice-calls-open-tests-v1`, `tmp/solo-t-c4` y `tmp/solo-t2-c4`.
