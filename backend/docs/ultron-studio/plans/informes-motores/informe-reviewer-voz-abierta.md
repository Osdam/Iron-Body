# Informe del reviewer · `T` = `3287769b` (las pruebas con las llamadas abiertas, C-4) y la copia cerrada en proceso

Fecha: 2026-11-11. Revisor independiente; no he tocado código, pruebas, ramas ni producción. Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código o los documentos · **[S]** supuesto o a confirmar.

## Veredicto

- **`T`: APROBADO CON CONDICIONES.** Ninguna prueba se afloja: cada método cambiado sigue negando por la cerradura que nombra, casi siempre con la lista EXACTA de motivos (antes muchas solo miraban «contiene» + «la constante, la primera»). Las de «todo en regla → se permite» fijan la fila, el sello, la parte y el agente, la huella y los 4 dígitos, la reserva exacta, el evento y el job. `T` no es tautológico: con la constante cerrada caen los 79 casos de `T`, y nada más. **Condición:** antes del `cherry-pick`, cerrar I-1 (el job tiene que salir encolado UNA sola vez). Las demás condiciones (I-2 a I-4) son del procedimiento y de los arneses, no del contenido de `T`.
- **Copia cerrada en proceso (`ClosedPolicyCopy`): ACEPTADA CON CONDICIONES (menores: M-1, M-2, M-4).** Es correcta y segura. Prueba el código real con dos líneas cambiadas, comprobadas. Falla de forma ruidosa si el fichero cambia de forma, no contamina el proceso, no deja temporales y no ejecuta nada que no venga del repositorio. No la escalo a security: no encuentro superficie nueva (detalle en §3).
- **Bloqueantes: ninguno**, ni en `T` ni de producto para la apertura (§6).

## 1. Lo que ejecuté [C]

Todo con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`, una batería cada vez. El worktree aislado sigue en `prep/voice-calls-open-tests` = `3287769b`, con `git status --porcelain` vacío antes y después. La copia es un `cp -c -R` del `backend/` del worktree aislado al scratchpad de la sesión. Comprobé que se resuelve a sí misma (`ReflectionClass::getFileName()` apunta a la copia).

| # | Árbol | Rutas | Resultado |
|---|---|---|---|
| E1 | `X' + T` (worktree aislado, tal cual) | `tests/Unit/Voice tests/Unit/Telephony tests/Feature/Voice tests/Feature/Telephony` | **OK**: 901 pruebas, 234 262 aserciones, 163 omitidas (E2E y PG) |
| E2 | ídem | `tests/Feature/Outreach/V9 tests/Unit/UltronStudio/StudioIntegrationsParityTest.php` | **OK**: 483, 23 986 aserciones, 29 omitidas |
| E3 | ídem | los 3 de `Feature/UltronStudio` que toca `T` (`…IntegrationsStatusTest`, `…VoiceIntegrationsTest`, `…VoicePublishingTest`) | **OK**: 32, 701 aserciones |
| E4 | copia, abierta (control de la copia) | rutas de E1 | OK: 901, igual que E1 |
| E5 | **solo `T`** (copia con `VoiceCallPolicy.php` de `1ef1d72e`: `diff` = la línea 23) | rutas de E1 | **10 errores + 25 fallos** |
| E6 | ídem | rutas de E2 | **40 fallos** (18 de paridad del H6 + 22 de V9) |
| E7 | ídem | los 3 de E3 | **4 fallos** |
| E8 | `X' + T`, orden aleatorio (`--order-by=random --random-order-seed=4242`) | rutas de E1 | OK: 901 |
| E9 | `X' + T`, cada prueba de la copia cerrada **sola** (`VoiceCallPolicyClosedCopyTest`, `ElevenLabsClosedCopyTest`, `PlaceOutboundVoiceCallClosedCopyTest`) | — | OK (4, 2 y 2 pruebas) |
| E10 | `X' + T` con `-d opcache.enable_cli=1` | `VoiceCallPolicyClosedCopyTest` + `TelephonyFixtureParityTest` | OK: 19 pruebas |
| E11 | restos de la copia en `$TMPDIR` (`ib-copia-cerrada-*`) antes y después de E9/E10 | — | 0 y 0 |
| E12 | fixture del H6 en la copia: `python3 -I …/integrations-cases/build.py` | — | **reproduce `integrations-cases.json` byte a byte** (sha256 `426452ca…` antes y después; 754 929 bytes) |
| E13 | `python3 -I tests/fixtures/ultron-studio/tools/integrations-cases-check.py` | — | `RESULTADO: OK`: 704 casos rehechos, 0 discrepancias, **117 variantes cazadas**, `voiceCallPolicy:closed_constant` en 18 casos |
| E14 | fixture `e61ef7a2` frente a `3287769b`, comparado con un script | — | Solo cambia la sección `voiceCallPolicy`, y en ella solo los **18 casos `decide`**. En cada uno: `reasons` = los de antes sin `calls_disabled_in_code` (que iba el primero); `allowed` = sin motivos; el contexto, idéntico; `integration_test` y `real_call`, coherentes. Un caso renombrado. Permitidos: 3 |
| E15 | `git merge-tree` (modo trivial, no escribe nada) de `T` sobre `origin/main` = `8c1f092e` y sobre la punta de `feat/ultron-studio-h9` = `d1d6e2b3` | — | **0 conflictos** en los dos |

**Solo `T` (E5–E7) = 75 + 4 = 79 casos**, el mismo número del informe del tester. Agrupados por método, son exactamente los 37 métodos que `T` cambia o crea más las 8 pruebas de la copia cerrada; no cae nada fuera de `T`. Las 5 guardas caen por su propia aserción:
- `VoiceIsolationTest.php:333`, `VoiceCallPolicyTest`, `ElevenLabsCallStarterTest`, `V9StrategyGateTest`: «false is true»;
- el desplegador: llega `not_attempted · calls_disabled_in_code`.

La copia cerrada falla con su error explícito («exige `= true;` exactamente una vez y está 0»). Es lo esperable: en un árbol cerrado, todo lo de `T` describe otro estado o exige la lista exacta sin la constante delante.

**No ejecuté:** la batería completa (lo prohibía el encargo), PostgreSQL, los E2E con arnés, las pruebas del CRM, ni nada con red real.

## 2. Mutaciones [C]

Cada una es UNA sustitución exacta del código de producción, en la copia y con la constante abierta, comprobada con `count == 1`. Se lanzaron las rutas de E1 y E2. Después se restauró el fichero y se comprobó su sha256; el sha256 de todo `app/` es idéntico antes y después. Guion: `…/scratchpad/tools/mutar.py`; salidas en `…/scratchpad/out/mut-*`.

| # | Mutación | Resultado | La matan |
|---|---|---|---|
| R1 | `OutboundPolicy::targetReasons()` deja de mirar el consentimiento (`voiceConsent`) | **muerta** | `V9StrategyGateTest::test_el_destino…` (de `T`), `PlaceOutboundVoiceCallRecheckTest` (2), `TelephonyFixtureParityTest` (de `T`) |
| R2 | `OutboundPolicy::targetReasons()` deja de mirar el horario legal (`outsideHours`) | **muerta** | `V9StrategyGateTest::test_cada_motivo…` (de `T`), `PlaceOutboundVoiceCallRecheckTest::…fuera_de_la_ventana…`, `TelephonyFixtureParityTest` |
| R3 | `OutboundGate::request()` acepta y guarda `reserved_micros = 0` (sin reserva) | **muerta** (7) | `VoiceCrmRoutesTest`, `VoiceNumberIdentityTest` (2), `VoiceProviderVerificationTest`, `VoiceWebhookHealthBrakeTest` (3): todas por `assertOutboundAccepted` de `T` |
| R4 | el job marca a otro número (`toNumber` = el propio `TWILIO_VOICE_FROM`) | **muerta** | `PlaceOutboundVoiceCallRecheckTest::…marca_una_sola_vez_con_lo_sellado` (de `T`, por `toLast4`), `PlaceOutboundVoiceCallTest::test_el_cuerpo…` |
| R5 | el job ya no exige que el destino sea el sellado (se quita el `hash_equals` de la huella) | **muerta** | `PlaceOutboundVoiceCallTest::test_el_cuerpo…` (anterior a `T`) |
| R6 | `OutboundGate::request()` encola el job **dos veces** para la misma fila | **VIVA** | nadie, ni en E1/E2 ni en `tests/Feature/Outreach` + `tests/Feature/UltronStudio` enteros (2 384 pruebas: solo caen las dos puertas de diff contra git, que en una copia sin `.git` caen siempre) → **I-1** |

## 3. La copia cerrada en proceso (H-2): análisis [L, salvo donde digo C]

- **Cada sustitución ocurre exactamente una vez** (`ClosedPolicyCopy::source()`, `:124-130`, `substr_count === 1`, si no lanza). Todas van ancladas a una línea completa (`"\n…\n"`): solo pueden cambiar una línea cada una.
- **Que no cambia nada más** lo exige `VoiceCallPolicyClosedCopyTest::test_la_copia_es…`:
  - el mismo número de líneas;
  - **2 líneas** cambiadas por fichero;
  - en la política, cuáles: la clase y la constante.
  
  `load()` no lo vuelve a exigir (M-1).
- **Si el fichero real cambia de forma** (`final readonly class`, `use` agrupado, la constante con otra sangría…), la sustitución da 0 → `RuntimeException` → las pruebas de la copia dan error. **Falla ruidosa, nunca silenciosa.** El caso delicado sería un lector que lea la constante por el nombre completo (`\App\Services\Voice\VoiceCallPolicy::…`): la copia leería la constante real (abierta). Las pruebas de la copia, que esperan el comportamiento cerrado, caerían igualmente. También ruidoso.
- **Contaminación.** Las copias son clases NUEVAS (`…CopiaCerrada`); no sustituyen ni redefinen las reales. Se cargan una vez por proceso (`class_exists(…, false)`). Ninguna prueba enumera clases cargadas (sin `get_declared_classes`, `class_implements` ni recorridos del classmap en `tests/`). Comprobado ejecutando:
  - verde en orden aleatorio (E8);
  - verde cada prueba sola (E9);
  - verde con opcache (E10).
  
  La copia del job no se encola nunca (las pruebas llaman a `run()`).
- **El temporal.** `tempnam(sys_get_temp_dir(), …)` lo crea atómicamente, con un nombre único y en 0600. En macOS, además, `$TMPDIR` es del usuario. Se escribe, se carga con `require` y se borra en un `finally`. Restos: 0 (E11). Si la escritura fallara, el `require` de un fichero vacío no declara la clase y la comprobación de `:177` lanza. Para atacar la ventana entre la escritura y la carga hace falta el mismo usuario, que ya puede ejecutar lo que quiera.
- **Código ajeno.** No lo hay: lee `app/` del mismo árbol que ya ejecuta la suite y le aplica sustituciones literales fijas, sin entrada externa.
- **¿Código real o reescritura?** Real: el cuerpo es byte a byte el del árbol; solo cambian la constante (o el `use` con alias) y el nombre. Lo que NO prueba es el ensamblaje cerrado (`OutboundGate`/`InboundGate` y HTTP con la política cerrada). Eso solo lo prueba `H8E2ECerradaTest`, cuyo arnés no corre con `X` en la punta (H-1/I-2). En producción, la vuelta atrás es el respaldo `BK`, que ya se probó cerrado; el riesgo residual es bajo.
- **Alternativas** (si no se aceptara): hacer de la constante un hecho inyectable de `VoiceCallContext` (toca `app/` y la protección 2: no procede) o depender solo del E2E y del revert. Las dos pierden M13–M17 del tester. **La técnica es mejor que sus alternativas; la acepto.**

## 4. Hallazgos

### Bloqueantes

Ninguno.

### Importantes

**I-1 · `tests/Feature/Telephony/Support/VoiceFixtures.php:314` y `tests/Feature/Outreach/V9/V9StrategyGateTest.php:124` · las aceptaciones no fijan «UN job».** `Queue::assertPushed(PlaceOutboundVoiceCall::class, fn …)` pasa con uno o más jobs para esa fila. El docblock (`VoiceFixtures.php:297`) dice además «el job en la cola `voice`», y ni la conexión ni la cola se comprueban.
- **Escenario de fallo** (R6, comprobado): una regresión en `OutboundGate::request()` que encole dos veces deja en verde todas las pruebas de «todo en regla».
- **Por qué importa:** `PlaceOutboundVoiceCall::run()` no toma cerrojo entre leer `requested` y llamar a `startOutbound()`. Hoy el worker `ironbody-voice` tiene `numprocs=1` [L, `informe-devops.md:18` del H8]: el segundo job llega con la fila ya en `dialing` y no marca. Con dos procesos en el carril `voice`, serían **dos llamadas a la misma persona**.
- **Arreglo** (tester, en `T`; una línea por ayudante): `$this->assertCount(1, Queue::pushed(PlaceOutboundVoiceCall::class, fn ($j) => $j->callId === (int) $fila->id && $j->connection === 'voice' && $j->queue === 'voice'));`. Después, volver a lanzar R6 (tiene que morir) y la de «solo `T`» de los ficheros afectados.
- Fuera de `T`, para backend/architect: valorar un `ShouldBeUnique` o una transición `requested → dialing` atómica antes de marcar, si algún día se escala el worker.

**I-2 · `docs/ultron-studio/ops/v9-abierta.sh:42` · H-1 está incompleto (devops).** Además de `h8-e2e.sh:191`, `v9-abierta.sh` exige `CALLS_ENABLED_IN_CODE = false` exactamente una vez y **muere con `X` en la punta** [L]. Es el único arnés que ejecuta `V9OpenGateTest`: la vía abierta estrategia → puerta → job, la única que hoy exige `assertPushed(…, 1)`.
- **Escenario:** después de la apertura, nadie puede volver a lanzar la vía abierta del H9 sin enmendarlo.
- **Arreglo:** la misma enmienda que para `h8-e2e.sh`: si la fuente trae `= true;` una vez, la copia no gira esa línea y solo gira `SENDS_ENABLED_IN_CODE`.
- **[S]** Los guiones del banco asumen la constante cerrada en su clon (`banco-h8/ensayar.sh:349`, `banco-h8/ensayar-ops8e.sh:113`, `banco-h9/telefonia-prep.sh:298`). Devops tiene que decir cuáles se volverán a usar sobre una punta abierta.

**I-3 · El procedimiento del día está incompleto o es ambiguo en cuatro puntos (tester + documentation-specialist).**
- (a) `RUNBOOK_PRIMERA_LLAMADA.md:195` (paso 9.4) dice solo «push del commit de pruebas». Le faltan el `cherry-pick`, el `range-diff`, la comprobación de «nada fuera de `tests/`», las baterías y el comprobador del fixture (§7 del informe del tester). Hay que copiarlos o enlazarlos literalmente.
- (b) En este repositorio la rama local `main` está **vieja**: `70e1b175`, del 2026-10-22. `origin/main` es `8c1f092e` [C]. El §7 del tester dice «en el árbol donde está `main` con X en la punta». Tiene que decir: `git fetch`, en un árbol limpio, con `HEAD == origin/main ==` X (`git rev-parse HEAD origin/main`). El árbol `_worktrees/ultron-h8` es compartido con otros agentes y hoy tiene cambios sin confirmar.
- (c) El §7 del informe del tester (`informe-tester-voz-abierta.md:193`) cita `803cd016` (la versión 1, sin el refuerzo de M15) en la frase sobre el almacén de objetos. Riesgo: hacer `cherry-pick` de la `T` equivocada. Hay que citar solo `3287769b` y, con autorización, borrar `prep/voice-calls-open-tests-v1` y las dos `tmp/solo-t*` tras este veredicto.
- (d) «<los 21 ficheros de §1>» es un marcador: hay que escribir la lista.

**I-4 · [S, a confirmar por devops] Después de `T`, el modo 4a ya no se puede repetir, y hay precondiciones en el tramo.**
- `comprueba_4a_git` (`deploy-h8-backend-fase1.sh:373-410`) exige tres cosas: que la punta de `origin/main` sea `voice(calls-open)`, que haya un solo commit así desde `BASE` y que todo lo demás del tramo sea documentación.
- Con `T'` encima, una vuelta atrás de nivel 4 (`checkout` de `BK`) seguida de una **reapertura** no tiene camino guionizado:
  - la 4a aborta, porque la punta ya no es X;
  - la fase 1 aborta con la constante abierta (`:521`);
  - y, como ya se sabe, también abortan `deploy-h9-backend-cierre.sh:520` y `deploy-m1-backend.sh`.
- Además, `BASE..origin/main` solo puede llevar documentación y X. Hoy `origin/main` tiene, desde `1ef1d72e`, commits que tocan `app/` y `tests/` (los arreglos comerciales `181075b3…9a6e3b1c`). La rama del H9 suma `d1d6e2b3`, que toca `tests/`. Todo eso tiene que estar desplegado antes de la 4a, o la 4a aborta (`:406-410`).
- No es de `T`, pero decide si el día sale.

### Menores

- **M-1 · `tests/Unit/Voice/Support/ClosedPolicyCopy.php:159-181`.** `load()` no comprueba `changedLines() === 2`; solo lo hace una prueba. Si se lanza `ElevenLabsClosedCopyTest` sola tras un cambio de forma, el invariante «solo dos líneas» no se mira. El riesgo es bajo, porque las sustituciones son de una línea y exactamente una vez. Arreglo: llamar a `changedLines()` dentro de `load()` y lanzar si no son 2.
- **M-2 · `tests/Unit/Voice/VoiceCallPolicyClosedCopyTest.php:114-128`.** El recorrido «todo lector que decide tiene su copia» solo detecta el literal `VoiceCallPolicy::CALLS_ENABLED_IN_CODE`. Un decisor nuevo que lea la constante a través de `VoiceCallPolicy::decide(` (como ya hacen `OutboundPolicy`, copiada, y `StudioIntegrations.php:503`, que solo la muestra) no quedaría obligado a tener copia. Arreglo: listar también los que llaman a `decide(`, con su lista permitida explícita.
- **M-3 · Docblocks de `T` que se quedaron en la primera versión:**
  - `ElevenLabsCallStarterTest.php:30-32`, `ElevenLabsAgentDeployerTest.php:33-34` y `PlaceOutboundVoiceCallTest.php:30-32` dicen que el estado cerrado «solo» lo recorre la copia del E2E, y ahora lo prueban `ElevenLabsClosedCopyTest` y `PlaceOutboundVoiceCallClosedCopyTest`;
  - `integrations-cases.README.md:352` dice «la única línea que cambia» y son dos (la constante y el nombre).
- **M-4 · La enmienda H8 propuesta (`informe-tester-voz-abierta.md`, §4.3, línea 112)** dice «la copia cerrada en proceso de las tres políticas puras». Son seis clases (las tres políticas, el job, el iniciador y el desplegador). Hay que corregirlo antes de congelar la enmienda.
- **M-5 · Fuera de `T`: `tests/Feature/UltronStudio/UltronStudioVoiceOutboundOnlyTest.php:224`** dice «y la constante de llamadas cerrada». Pasa igual con la constante abierta, porque `StudioVoicePublishing::pointNumber()` corta antes del desplegador (`:761-763`). Con X, su nombre miente, aunque prueba justo lo que importa en la 4a: solo saliente + abierta → ningún PATCH al número. Se puede renombrar después.

## 5. Recorrido de `T` método a método [L, contrastado con E5–E7 y R1–R6]

- **Solo `tests/`:** los 29 ficheros, nada fuera [C: `git show --name-only | grep -v '^backend/tests/'` vacío].
- **Ningún `markTestSkipped`/`Incomplete` nuevo** y ningún condicional sobre la constante. Los `if (class_exists(...))` de `VoiceBoundaryTest` pasan a ser una aserción.
- **Las únicas aserciones nuevas «débiles»:**
  - los dos `assertContains` de `VoiceCallPolicyClosedCopyTest:105-106`, que acompañan a `assertCount(2)` (exactas en conjunto);
  - `assertNotContains('calls_disabled_in_code')` de `VoiceCallPolicyTest`, que acompaña a `assertSame` de la lista exacta;
  - `assertGreaterThanOrEqual` sobre lo comprometido (`VoiceFixtures.php:311`), que acompaña a `reserved_micros` exacto.
  
  Ninguna afloja nada.
- **Las 89 líneas de aserción quitadas:** cada una tiene sustituto igual o más fuerte. Los dos casos con menos que antes no pierden nada:
  - `VoiceProviderVerificationTest` pierde `Queue::assertNothingPushed()` tras la primera 409, cubierto por el recuento de filas = 0;
  - `V9StrategyGateTest::test_nunca_un_canario…` cambia `assertNadaCreado()` por el recuento exacto de filas y eventos (2/2), sin recuento de jobs (I-1).
- **Las «todo en regla»:**
  - `assertOutboundAccepted`/`assertAceptada` fijan: 202; UNA fila nueva (recuento + 1 y `sole()` por uuid); `requested`, el origen y el destino (el canario o el contacto); el sello `applied` con la parte y el agente; la huella y los 4 dígitos; la reserva exacta (960 500) y el día de Bogotá; el evento único; el job para esa fila (al menos uno: I-1); y la respuesta sin el número.
  - La entrante: `sole()`, `active`, reserva de 1 244 500 y evento `authorized` con `[]`.
  - El job: un solo `startOutbound` con el agente sellado, el número importado, el `call_ref` y los 4 dígitos del destino; repetir no marca.
  - El iniciador: `assertSentCount(1)` y solo el host oficial.
  - Publicar: un solo PATCH, al agente nuevo, y nada publicado si el proveedor no lo confirma.
  
  Ninguna aceptaría otro número (R4, R5), otro agente (sello y agente fijados; M9 del tester), una reserva distinta (R3) ni una petición de más al proveedor. **Solo el job duplicado (I-1).**
- **Ajustes de escenario** (`aLaHora`, colgar la entrante, `releaseAccepted`): preparan el estado; ninguno adapta la expectativa a un comportamiento dudoso. El del horario saca a la luz un `number_unverified` que la constante tapaba (H-5): es una mejora.
- **`V9StrategyGateTest::test_nunca_un_canario…`** fija que, en el alcance `contacts`, la parte canaria de la estrategia llama con la parte `rest` y su agente. Es lo que dice `VoicePublishedConfig::partForScope()` (D8-19) [L]: coherente.

## 6. Los hallazgos de producto del autor (§9): ¿algo que debería negarse y se permite?

Comparto que **no hay ningún bloqueante de producto** en lo ejercitado:
- cada cerradura provocable basta sola para negar (las 18 de V9, con la lista exacta, comprobadas también con R1/R2);
- el número declarado solo saliente niega toda entrante, con `number_outbound_only` como único motivo;
- el canario autorizado en entrante es el estado de la fase 3, que la 4a no enciende;
- `integration_test` pasa a «disponible» sin canario ni cupo, pero solo se muestra (`StudioIntegrations::voiceLevels()`, sin ninguna acción en `app/` que lo consuma) [L].

El único «de más» que encontré es I-1: robustez de las pruebas, mitigada en producción por `numprocs=1`.

## 7. El día de la apertura

**`cherry-pick` [C].** Ninguno de los 29 ficheros de `T` ha cambiado entre `1ef1d72e` y `9a6e3b1c`, `origin/main` (`8c1f092e`) ni `d1d6e2b3`. Los cambios de `tests/` en ese tramo son 5 de `tests/Feature/Commercial`, que no tocan la voz. **No encuentro cambios en `tests/Feature/Outreach/Run` en ese tramo**, contra lo que decía el encargo. `merge-tree`: 0 conflictos. Lo que puede ir mal si `main` sigue avanzando:
- (1) un conflicto, porque alguien toque uno de los 29 ficheros o el fixture del H6: el procedimiento del tester (`--abort` y rehacer `T`) es correcto;
- (2) **sin conflicto, pero con una prueba NUEVA que dependa de la constante**: el `cherry-pick` no lo detecta, lo detecta la batería. Por eso es obligatorio repetir la medición de «solo `X'`» sobre la punta real el día anterior (§7 «Antes», paso 2 del tester), y no solo el `cherry-pick`.

**Antes del día (sin esto, no):**
1. I-1 cerrado en `T` (con R6 muerta y la de «solo `T`» repetida en los ficheros tocados) y `T` vuelto a mirar por mí; basta el diff de dos líneas.
2. I-3 a-d en el runbook y en el §7, con la referencia exacta de la `T` que se va a aplicar y el árbol limpio en `origin/main`.
3. I-4: devops confirma que el tramo `BASE..origin/main` será solo documentación + X (todo lo demás, desplegado antes) y deja escrito cómo se reabre tras una vuelta atrás de nivel 4.
4. La medición de «solo `X'`» repetida sobre la punta real (paso 2 del tester), y la de «solo `T`» si `T` cambia.
5. Las enmiendas H6 (PO-3) y H8 (protección 2), con el texto corregido (M-4), aprobadas por el lead. El fixture del contrato cambia con `T`.

**Pueden ir después** (no cambian lo que se permite):
- H-1 + I-2: los arneses `h8-e2e.sh` y `v9-abierta.sh`, antes del siguiente E2E;
- H-3: los comentarios de `app/` que quedan falsos, nunca en `VoiceCallPolicy.php` mientras esté abierta;
- H-4: el CRM reexporta `backend-integrations.testing.ts` (3 líneas). El CRM ya trata `callsEnabledInCode` en los dos sentidos (`studio-voice-session.ts:271`, `studio-voice-activation.ts:386/427`) [L];
- M-1 a M-3 y M-5.

## 8. Lo que no pude revisar

- PostgreSQL (el tester dice 528/528 con `X' + T`; no lo repetí).
- Los E2E con arnés y las pruebas del CRM.
- La batería completa.
- El comportamiento real con `numprocs` > 1.
- `hotfix-backend.sh` (no está en el repo).

Restos míos: la copia (`…/scratchpad/copia/backend`), las salidas (`…/scratchpad/out/`) y los guiones (`…/scratchpad/tools/`), todo en el scratchpad de la sesión `49910c23…`; nada en los árboles.
