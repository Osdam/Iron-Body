# Hito 9 · informe del `backend`

## §B9-core · el núcleo puro del motor de autonomía comercial

**Fecha:** 09-11-2026, entrega 23:02 UTC (L9-29, L9-27/L9-30, L9-25 y el texto de `calendar_outside_legal_hours` incluidos). **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, HEAD `ab54a256`. El árbol ya traía cambios sin confirmar del H8 y de otros paquetes del H9; no son míos y no los he tocado. **Sin commit. Sin red. Sin base de datos. Producción: nada.**

**Estado: entregado para revisión del lead.**

- **Suite:** 884 pruebas y 39 161 aserciones en verde en `tests/Unit/Outreach/Core`.
- **Paridad:** caso a caso con los 803 casos de los 11 fixtures de TEST9-a, regenerados con las ratificaciones L9. El catálogo lleva además L9-29 (§3.7): **TEST9-a tiene que regenerar `catalog-snapshot.json`** (Q-13).
- **Pruebas del propietario:** las 5 de §13.1 que nombran a B9-core, con su fichero, más la parte pura de #6.
- **Calidad:** Pint limpio; **97 mutantes por muestreo: 94 cazados y 3 equivalentes** (§7).
- **Regresión:** sin cambios en lo que usa el núcleo. `tests/Unit/Outreach` da 962 pruebas y 52 564 aserciones; `tests/Feature/Outreach/Facts`, de B9-facts, da 50 y 915.

### 1. Qué entrega, por fichero

**Código: `app/Services/Outreach/Core/`, 21 ficheros.** Todo es puro: sin base de datos, red, log, caché, `config()` ni reloj (`now` entra siempre como argumento).

| Fichero | Qué es | Contrato / fixture |
|---|---|---|
| `OutreachCatalog.php` | Catálogo versionado (`outreach-catalog/2026-11-10.2` por L9-29, huella `ce5fcdab…`; la `.1` era `4580c447…`): 42 hechos, 41 eventos, operadores por tipo, finalidades, medios, canales, objetivos y dominios. Incluye `export()` (byte a byte el del fixture), `compatibility()` (ahora con `level: N3`, L9-21), `cycleKey()` (las clases, `cls:{class_id}:{session_date}`) y los tramos de cartera. | D9-3, D9-4, L9-29 · `catalog-snapshot.json` |
| `CanonicalJson.php` | JSON canónico, los mismos bytes que `StudioContentHasher` (claves `SORT_STRING`, sin decimales). Normaliza los números (3.0 → 3; un decimal → `value_not_integer`). | DF-T4 |
| `RelativeTime.php` | Instantes UTC estrictos, días de Bogotá y ventanas relativas (DF-C3). | D9-5 |
| `ConditionCanonicalizer.php` | Gramática `outreach-conditions/1`: 25 códigos de error como conjunto `{key, code}` con raíz `conditions`; profundidad ≤ 5 y ≤ 50 nodos sobre el árbol recibido; ≤ 8 192 bytes; dominios dinámicos como instantánea. | D9-5 · `conditions-cases.json` |
| `CanonicalCondition.php` | Condición validada, con constructor privado (T11). `parse()` lanza `ValidationException` (422 con todas las claves). También `tryParse()`, `uses()` y `rootConjunction()`. | T11 |
| `FactSnapshot.php` | Foto de hechos `{facts, contacts, events, transitions}`: una lista null es «sin datos»; rechaza lo que parezca un dato personal. | D9-5 |
| `ConditionEvaluator.php` / `ConditionResult.php` | Tres valores, sin cortocircuito, traza en preorden, `traceHash`. | D9-5, T10, T12 |
| `EnrollmentGate.php` | Puerta de inscripción: audiencia, exclusiones y éxito. | D9-8 etapa 2 |
| `ContactCalendar.php` | La ley ∩ el silencio (N1), el horario propio (N5, L9-15) y el horario de la estrategia; `nextSlot()`. `window()` devuelve ahora `{inside, n1, n5}`. | D9-7, P9-17 · `calendar-cases.json` |
| `ContactBudget.php` / `BudgetVerdict.php` | 1 contacto al día, 2 en 7 días móviles cerrados, 48 h entre contactos y la regla de un medio por semana. `retryAt` es el primer hueco **legal** (DF-B6). **Nuevo:** el tope diario también por teléfono (L9-27, L9-30). | P9-8 · `contact-budget-cases.json` |
| `PolicyLadder.php` | La escalera N1–N7: decide el primer nivel que niega, con todos sus motivos; `defer` solo si todos son temporales; `waitsForRelease` en N2; efectos de N6; avisos en sombra. | D9-7 · `policy-ladder-cases.json` |
| `ChannelSelector.php` | Selección de canal determinista y explicable, con preferencia y anotaciones. | D9-9 · `channel-selection-cases.json` |
| `PriorityArbiter.php` | Dos estrategias, la misma persona y el mismo día: finalidad, prioridad, objetivo y uuid. | D9-8 etapa 3 · `priority-cases.json` |
| `OutcomeTable.php` | 18 resultados, 8 fuentes, transiciones permitidas y por defecto, umbrales del clasificador (50 y 90), `needs_review` y la recaída de DF-O4. | D9-10 · `outcome-transitions.json` |
| `FollowUpMachine.php` | Siguiente paso, reintento o espera, sin acortar nunca un intervalo, con la ventana semiabierta. | D9-8 · `followup-cases.json` |
| `StrategyContent.php` | Lector estricto de `strategy-content/1` (raíz `content`). **Nuevo:** `limits.commercialBlockOverdueDays`, opcional y solo comercial (L9-20). | D9-6 · `strategy-content-cases.json` |
| `StrategyChainSignature.php` | Cadena de publicaciones firmada: subclave `ultron-studio-strategy-chain`, `prevHash`, verificación en orden. | D9-11.3 · `strategy-chain-vectors.json` |
| `DecisionTrace.php` | Traza `outreach-trace/1`: ≤ 16 KB con recorte determinista, sin datos personales, `traceHash` reproducible. | T12 |
| `DecisionTraceText.php` | Textos en español de cada código cerrado. **Nuevo:** `export()`, `TEMPLATES` y `FALLBACKS` (L9-25). | §8 |

**Pruebas: `tests/Unit/Outreach/Core/`, 20 ficheros.** Las cifras son pruebas por clase, sumando los conjuntos de datos.

| Fichero | Pruebas | Qué cubre |
|---|---|---|
| `OutreachCatalogTest.php` | 28 | El export byte a byte (con L9-29), la huella fijada, la compatibilidad y las claves de ciclo, incluida la de las clases. |
| `ConditionsParityTest.php` | 227 | Canonicalización, rechazo, equivalencia, evaluación y días de Bogotá. |
| `CalendarAndBudgetParityTest.php` | 138 | Festivos, ventana, hueco y presupuesto. Incluye la paridad T9 y el tope por teléfono. |
| `PolicyAndChannelParityTest.php` | 159 | Escalera, selector y prioridad. Comprueba que un nivel inferior no levanta una negativa, la llamada pedida en el fin de la ventana, el teléfono en N1, la cartera desconocida y el horario propio frente al de la estrategia. |
| `OutcomesAndFollowUpParityTest.php` | 177 | Tabla, matriz, resolución y seguimientos. Comprueba que el clasificador nunca avanza, que `opted_out` siempre sale con `end_exit`, que nunca se acorta un intervalo y el fin de la ventana. |
| `StrategyContentAndChainParityTest.php` | 111 | Contenido, versiones, bytes guardados, cadena y firma. Incluye la franja que solo toca el borde legal, la aprobación (DF-V3) y el umbral de cartera de 0 a 365. |
| `CorePrimitivesTest.php` | 10 | JSON canónico frente al Studio, números, tiempo, foto de hechos, T11, puerta, `is_known` y el empate de DF-C8. |
| `DecisionTraceAndPurityTest.php` | 7 | Traza reproducible, datos personales y recorte; textos de cada código; escaneo de pureza; **`trace-text-cases.json` = export vigente**. |
| `CoreLaravelBoundaryTest.php` | 3 | El 422 real; `ContactPolicy::quietHoursDenial` real; suelos atados a `config/`. |
| `H9PolicyLadderN1Test.php` | 5 | §13.1 #8, y la parte pura de #6. |
| `H9ChannelSelectorTest.php` | 2 | §13.1 #9: las 24 permutaciones × 3 preferencias. |
| `H9ConflictingStrategiesTest.php` | 3 | §13.1 #12. |
| `H9ScheduleTest.php` | 11 | §13.1 #17. «Primer hueco» comprobado minuto a minuto. |
| `H9OutcomeTableTest.php` | 3 | §13.1 #21. |

Ficheros de apoyo, sin pruebas propias:

- `LoadsOutreachFixtures.php` y `H9Scenario.php` (traits).
- `TraceTextCases.php` (genera el export).
- `tools/export-trace-text-cases.php` (lo escribe).
- `tools/mutate-core.py` (las mutaciones de §7).
- `fixtures/trace-text-cases.json`, de 40 296 bytes, con sha256 `b94019ba…` y `dictionaryHash` `e58ae106…`.

### 2. Contratos que publica el núcleo

Firmas y formas: las que ya conocen S9, B9-facts y B9-run, más estos cambios de hoy.

**`ContactCalendar::window(at, ownHours)`**
- Devuelve `{inside, n1: list, n5: list}`; antes era `{inside, reasons}`.
- El horario propio sale en `n5` como `calendar_outside_own_hours`, y solo si la ley se cumple (L9-15, DF-W5).
- No lo usa ningún fichero fuera de `Core` (comprobado con `grep`).

**`ContactBudget::check(history, now, purpose, medium, limits, ?phone = null)`**
- Ya no recibe `ownHours`: solo ley y frecuencia, y `retryAt` legal.
- **Nuevo sexto argumento opcional:** `phone = {refs: list<64 hex>, contacts: list<{at, phoneRef}>}`.
  - Niega con `daily_contact_limit` si alguno de los `refs` tuvo un contacto HOY (día de Bogotá y hasta `now`), de cualquier persona.
  - Con `refs = []` (teléfono desconocido) o `phone = null` no se cruza.
  - Un contacto a un teléfono que no está en `refs` no cuenta.
  - Ni la semana ni las 48 h se cruzan por teléfono: son de la persona.
  - Una forma mal construida lanza `InvalidArgumentException`.
  - `counts` sigue siendo solo de la persona.
- Compatible hacia atrás: el sexto argumento es opcional. Fuera de `Core` nadie llama a `check()`; B9-facts solo usa sus constantes (comprobado con `grep`).

**`PolicyLadder::decide(input)`**
- Nuevas claves obligatorias en `SPEC`, todas en la `baseInput` del fixture:
  - `studio.catalogCompatible` (bool);
  - `receivable {known: ?bool, overdueDaysMax: ?int}`;
  - `collectionsBlock {serverDays: int, strategyDays: ?int}`.
- **Clave opcional `phone`:** la misma forma que arriba, o null o ausente. Va fuera de `SPEC`, listada en la nueva constante pública `PolicyLadder::OPTIONAL`.
- Motivos nuevos:
  - `catalog_incompatible`, en N3 justo después de `studio_reference_invalid`;
  - `calendar_outside_own_hours`, en N5 entre `canary_scope_mismatch` y `outside_strategy_schedule`; es temporal, y con `'invalid'` niega sin `retryAt`;
  - `collections_overdue_blocks_commercial`, en N6 después de `human_requested_recently`. Es permanente y solo comercial. Bloquea si `receivable.known !== true` o si `overdueDaysMax` supera el umbral. Con N días justos no bloquea. El umbral es `min(serverDays, strategyDays)`.
- `outside_strategy_schedule` mira ya solo el horario de la estrategia: el propio tiene su código.

**`PolicyLadder::overdueBlocks(receivable, collectionsBlock)`**: nuevo, público y puro. Es la regla de P9-7.

**`OutcomeTable::resolve()`**: añade la recaída de DF-O4. Con el clasificador, si la transición que queda no es segura, sale con `end_exit`.

**`StrategyContent`**
- Admite `limits.commercialBlockOverdueDays` (0–365), opcional y solo en `commercial`.
- En `collections` y `service` es `unexpected_key`.
- Constante pública `LIMITS_OPTIONAL`.

**`DecisionTraceText`**
- Nuevos: `FORMAT = 'outreach-trace-text/1'`, `VOICE_POLICY_PREFIX`, `TEMPLATES`, `FALLBACKS` y `export()`.
- `explain()` da las mismas frases que antes; ahora salen de las plantillas.
- Tres textos nuevos: `calendar_outside_own_hours`, `catalog_incompatible` y `collections_overdue_blocks_commercial`.
- Dos retocados, para que el lead los revise:
  - `calendar_outside_legal_hours`, con el texto que pidió el lead tras comprobar la Ley 2300 (art. 3: de lunes a viernes de 07:00 a 19:00 y sábados de 08:00 a 15:00): «Fuera del horario permitido: de lunes a viernes de 08:00 a 19:00 y sábados de 08:00 a 15:00 (la Ley 2300 permite desde las 07:00 entre semana; las horas de silencio del gimnasio terminan a las 08:00).» Cambié una palabra de su texto, «empiezan» por «terminan»: en `ContactPolicy` el silencio va de 21:00 a 08:00 (`config/commercial.php:80-81`). El PHPDoc de `ContactCalendar` dice ya lo mismo;
  - `daily_contact_limit` menciona «o con su teléfono».

**`OutreachCatalog::compatibility()`**: añade la clave `level` (`'N3'` si es incompatible y null si no).

### 3. Qué cambió hoy y por qué

1. **TEST9-a regeneró los fixtures con las ratificaciones del lead.** Al regenerarse, 47 de 848 pruebas fallaban: todas por expectativas nuevas, no por regresiones. El núcleo se adaptó así:
   - L9-15 (horario propio en N5): `ContactCalendar`, `ContactBudget` y `PolicyLadder`;
   - L9-20 (cartera vencida en N6): `PolicyLadder` y `StrategyContent`;
   - L9-21 (`catalog_incompatible` en N3): `PolicyLadder` y `OutreachCatalog`;
   - DF-B6 (`retryAt` legal del presupuesto): `ContactBudget`;
   - DF-O4: `OutcomeTable`.

   Además actualicé dos pruebas propias que asumían el horario propio en N1.
2. **Un fallo silencioso.** `PolicyLadder::n1` seguía pasando `$in['ownHours']` como sexto argumento a `ContactBudget::check()`, que ya tenía cinco. PHP lo ignora sin avisar, así que ninguna prueba cayó. Quedó corregido al añadir el sexto argumento real, `phone`: si no, el horario propio se habría leído como teléfono.
3. **L9-27 y L9-30.** Tope diario también por teléfono, en `ContactBudget` y como entrada opcional de la escalera.
4. **L9-25.** El núcleo exporta `trace-text-cases.json` desde la implementación: §4.
5. **§13.1.** Las pruebas literales del propietario que nombran a B9-core no existían con su fichero: las escribí (§5).
6. **Mutaciones.** Cada mutante sobreviviente con una regla del fixture sin caso recibió su prueba:
   - `is_known` con un dato que falta;
   - el empate de DF-C8;
   - el fin de la ventana en N7 (DF-L9) y en los seguimientos (DF-F3);
   - la franja que solo toca el borde legal (DF-E7);
   - `approvedByPerson` (DF-V3);
   - el umbral 0 de `commercialBlockOverdueDays`;
   - la cartera `known = null`;
   - el horario de la estrategia frente al propio.
7. **L9-29 (clave de ciclo de las clases), por encargo del lead.**
   - La plantilla de `class.upcoming_with_capacity` pasa de `cls:{class_session_id}` a `cls:{class_id}:{session_date}`. Antes, como la fila de la sesión solo nace al iniciar la clase, la foto de B9-facts no daba clave y no se inscribía nadie.
   - `session_date` se valida como fecha `Y-m-d` real; `class_id`, como entero ≥ 1. `class_session_id` puede venir (B9-facts lo manda, a veces null) y no cuenta.
   - Cambió la forma de la clave, así que el evento sube a **`keyVersion` 2**: una versión de estrategia hecha con la 1 sale `catalog_incompatible` en N3 y no se reinterpreta (Q-14).
   - El catálogo es otro: **`outreach-catalog/2026-11-10.2`, huella `ce5fcdab1e1265b70a1e7133b3eeccbd62bb2ab690acfa11a700ad6779a13039`**. La recalculé por separado con `StudioContentHasher` sobre el `catalog` del fixture con solo este cambio, y coincide.
   - Los fixtures de TEST9-a siguen en la `.1`. Hasta que los regenere, la paridad compara con el fixture más EXACTAMENTE este cambio (`LoadsOutreachFixtures::L9_29`): el export, la huella, la longitud, el caso de clases de `cycleKeys` (la pieza antigua sola ya no forma clave) y los metadatos de `conditions-cases.json` y `strategy-content-cases.json`.
   - Con los fixtures regenerados, esa comparación no hace nada. Ante cualquier otra diferencia, falla.
   - Ningún contenido de estrategia cambia de huella: su disparador canónico es `{event, params}`.
8. **API congelada (mensaje del lead).** Después de la congelación, la API pública de `Outreach\Core` solo cambió en estos puntos:
   - las dos entradas autorizadas: el teléfono y la clave de las clases;
   - lo que trajeron los fixtures regenerados con L9-15, L9-20 y L9-21: `window()`, `SPEC`, `level` en `compatibility()` y `LIMITS_OPTIONAL`;
   - añadidos que no rompen nada: `PolicyLadder::OPTIONAL`, `PolicyLadder::overdueBlocks()` y `DecisionTraceText::export()`, con sus constantes.

   `FactsCoreUnionTest` y el resto de B9-facts siguen en verde: 50 pruebas y 915 aserciones.

### 4. `trace-text-cases.json` (L9-25)

**Ruta:** `backend/tests/Unit/Outreach/Core/fixtures/trace-text-cases.json`. Está en mi carpeta porque el README de TEST9-a dice que no va en la suya (ver Q-2).

**Qué contiene:**
- `dictionary`: lo que da `DecisionTraceText::export()`, es decir niveles, veredictos, los 116 textos ordenados, plantillas, reservas, zona y prefijo de la política de voz. Lleva su `dictionaryHash`.
- `text`: los casos raros, como `voice_policy:<motivo>` y un código desconocido, que da null.
- `explain`: 15 casos de `explain(ladder, channel)`.
  - 13 son decisiones reales de `PolicyLadder`: una por nivel y veredicto, el tope por teléfono, la sombra con avisos, N6 con efecto y N7.
  - 1 es una elección real de `ChannelSelector`, con preferencia ignorada y tres canales.
  - 1 es sintético, para las reservas.
  - La entrada de la escalera es propia y sintética, y no depende del fixture de TEST9-a.

**Cómo se mantiene al día:**
- Se regenera con `php tests/Unit/Outreach/Core/tools/export-trace-text-cases.php`.
- `DecisionTraceAndPurityTest::test_trace_text_cases_es_el_export_vigente` falla si el fichero no es, byte a byte, el export vigente.

Ejemplo de frases que FE9 debe reproducir (N5, horario propio):
- «Se aplaza el contacto (N5 · Estrategia aprobada).»
- «Fuera del horario propio del gimnasio (más estricto que la ley).»
- «Se vuelve a intentar el 10-11-2026 a las 09:00 (Bogotá).»

### 5. Pruebas de §13 que son de B9-core

**§13.1, literales del propietario:**

| # | Prueba | Fichero | Lo que se comprueba |
|---|---|---|---|
| 8 | Persona sin consentimiento para llamadas | `H9PolicyLadderN1Test.php` | Voz (y asistido de voz) negada en N1 con `no_consent_for_purpose_channel`, N2–N7 `not_evaluated`, aunque haya una llamada pedida. Si hay WhatsApp, el selector lo toma. Si la plantilla no está aprobada, sale el asistido. Sin ningún medio, `deny` con todos los motivos. |
| 6 (parte pura) | Prospecto sin solicitud expresa | `H9PolicyLadderN1Test.php` | Sin autorización expresa, N1 niega (Q-9). |
| 9 | WhatsApp sí, voz no | `H9ChannelSelectorTest.php` | En las 24 permutaciones de cuatro canales × 3 preferencias, siempre WhatsApp (el primero del orden declarado) y nunca voz. La traza lo explica en frases y en `DecisionTrace`, reproducible. |
| 12 | Reglas contradictorias | `H9ConflictingStrategiesTest.php` | Ver detalle debajo de la tabla. |
| 17 | Horario o día no permitido | `H9ScheduleTest.php` | Ver detalle debajo de la tabla. |
| 21 | Respuesta ambigua | `H9OutcomeTableTest.php` | `ambiguous` del clasificador da `needs_review` con cualquier confianza y cualquier `on`. Por debajo del umbral, también. La secuencia para (`handed_off`, sin `nextJob`) y abre la tarea asistida. |

Detalle de **#12, reglas contradictorias**:
- con reglas opuestas, solo una inscribe y la traza nombra el hecho, el operador y el valor;
- el mismo día gana la prioridad: la otra aplaza en N6 y, tras el contacto de la ganadora, ningún instante de ese día la deja salir (N1);
- la escalera manda sobre la prioridad (N3);
- el servicio va antes que lo comercial.

Detalle de **#17, horario o día no permitido**: 10 casos.
- Domingo seguido de lunes festivo.
- Lunes festivo trasladado.
- Ley 2578: el 13-07-2026.
- 07:59, las 19:00 justas y el sábado a las 15:00.
- Tres del horario propio.
- Fuera de la ley y del propio a la vez.

En todos: `defer` y nunca contacto. El hueco está permitido y no cae en domingo ni festivo; ningún minuto entre «ahora» y el hueco está permitido.

**§13.2, transversales de B9-core:**
- **T9:** paridad con `ContactPolicy` (50 casos del fixture más `CoreLaravelBoundaryTest`).
- **T10:** gramática, en `ConditionsParityTest`.
- **T11:** nada sin tipar: 422 real y constructor privado.
- **T12:** reproducible y sin datos personales.
- **T13:** el selector.
- **T14:** resultados.
- **T15:** PARADA GLOBAL en N2.
- **T16–T22:** los 116 casos de la escalera, la propiedad «un nivel inferior nunca levanta» y las pruebas de §13.1.
- **T23:** seguimientos y «nunca acorta».

### 6. Paridad con TEST9-a, caso a caso

| Fixture | Casos | Secciones |
|---|---|---|
| `catalog-snapshot.json` | 21 | `compatibility` 6, `cycleKeys` 15; más el export entero, byte a byte. Con L9-29 aplicado mientras el fixture siga en la `.1` (§3.7) |
| `conditions-cases.json` | 226 | `canonicalize` 49, `equivalence` 10, `reject` 79, `evaluate` 80, `bogotaDays` 8 |
| `calendar-cases.json` | 48 | `holidays` 3, `window` 27, `nextSlot` 18 |
| `contact-budget-cases.json` | 85 | `effectiveLimits` 9, `check` 26, `parity` 50 |
| `policy-ladder-cases.json` | 116 | n1 24, n2 17, n3 7, n4 13, n5 16, n6 17, n7 6, precedencia 9, sombra 7 |
| `channel-selection-cases.json` | 18 | contrastados además con la escalera real por canal |
| `priority-cases.json` | 14 | más el orden inverso de cada uno |
| `outcome-transitions.json` | 149 | `matrix` 108, `resolve` 41 |
| `followup-cases.json` | 22 | — |
| `strategy-content-cases.json` | 58 | `valid` 13, `invalid` 40, `versions` 5; cada válido se relee de sus bytes |
| `strategy-chain-vectors.json` | 46 | `chain` 7, `subkey` 4, `verify` 35; más la cadena entera y su manipulación |
| **Total** | **803** | |

### 7. Mutaciones por muestreo

**Método:**
- Se trabajó sobre una copia aislada: un clon APFS del backend con su propio `vendor/`, sincronizada y comprobada con `diff -rq` antes de cada pasada.
- Por cada mutante:
  - el texto original aparece exactamente una vez;
  - se aplica la mutación y se pasa `php -l`;
  - se ejecuta la suite `tests/Unit/Outreach/Core`;
  - se restaura el fichero y se compara su sha256.
- El script queda en `tests/Unit/Outreach/Core/tools/mutate-core.py`, junto a la copia de trabajo, porque el scratchpad se purga. Se niega a correr dentro de un árbol de git.

**Resultado:** 97 mutantes, los 94 de antes y 3 de L9-29, en 18 de los 21 ficheros del núcleo. Sin mutantes propios quedan `BudgetVerdict.php` y `ConditionResult.php`, que son objetos de valor, y `CanonicalCondition.php`. Este último envuelve a `ConditionCanonicalizer`; su `uses()` y su `rootConjunction()` se prueban en `CorePrimitivesTest`, pero no se mutaron. **94 cazados, 3 supervivientes, 0 inválidos.** Los 3 supervivientes son equivalentes.

Pasadas:
1. 79 mutantes sobre la suite de 848. Sobrevivieron 8: cinco por reglas del fixture sin caso y tres equivalentes.
2. Tras escribir esas pruebas, 15 mutantes: los 8 anteriores y 7 nuevos sobre L9-15, L9-20 y DF-O4.
3. Pasada completa de 94 sobre la suite de 856: 88 cazados y 6 supervivientes.
4. Los 6 supervivientes otra vez, sobre la suite final de 883:
   - `ladder-overdue-known`, `ladder-schedule-with-own` y `sc-limit-min` quedan cazados por sus pruebas nuevas;
   - siguen vivos los tres equivalentes.

Qué cubren los 94: todos los ficheros con lógica.
- La gramática (bordes de 5/50/730/8 192, colapso, orden de `in`, desconocidos, unidades) y la evaluación de tres valores (Y, O, NO, `is_known`, bordes de `more_than_ago`/`within_next`, empate, fuente sin datos, `other`).
- El tiempo de Bogotá.
- El calendario: franjas, festivos, bordes, N1 frente a N5 y el horario propio que estrecha.
- El presupuesto: 7 d, 48 h, la regla de un medio, cobranza, horas del H9, respuestas que no cuentan y las 5 del teléfono.
- La escalera: menor, ULTRON por medio, `waitsForRelease`, sombra, temporal, cartera (umbral, estrategia, desconocida, finalidad), fin de ventana en N7, catálogo, horario propio, `retryAt` con los horarios y el teléfono.
- Selector, prioridad, resultados y seguimientos.
- Contenido: finalidad, hechos de cartera, plantilla, `waitFor`, borde legal, umbral, `params`.
- Cadena: etiqueta, génesis, bytes canónicos, aprobación.
- JSON canónico, guarda de datos personales, catálogo, foto, puerta y los textos exportados (L9-25).

**Los 3 equivalentes**, en `OutcomeTable::resolve()`, son defensa en profundidad que la tabla actual vuelve inalcanzable. La tabla es la del fixture y la ata `test_la_tabla_y_los_conjuntos_son_los_del_fixture`.

| Mutante | Por qué no cambia nada hoy |
|---|---|
| `out-optout` (quitar la guarda `outcome !== opted_out`) | `opted_out` solo admite `end_exit`, que es su defecto: el resultado y el incidente son idénticos. La guarda deja la regla #10 escrita sin depender de la tabla. |
| `out-classifier-safe` (no filtrar las permitidas por las seguras) | Los cinco resultados que produce el clasificador solo admiten transiciones seguras (`end_exit`, `wait`, `handoff_human`). |
| `out-df-o4` (quitar la recaída a `end_exit`) | Por lo mismo, ni lo pedido ni el defecto pueden ser inseguros con el clasificador (Q-6). |

Si la tabla cambiara para admitir una transición insegura, la prueba de la tabla caería antes, y estas guardas empezarían a decidir.

Pasadas posteriores:

5. Después añadí `PolicyLadder::OPTIONAL` (una indirección en la lectura de `phone`). Repetí los 12 mutantes de la escalera y del teléfono: **12/12 cazados**.
6. L9-29: 3 mutantes nuevos (la fecha de la sesión sin validar, `keyVersion` sin subir y la plantilla antigua), junto con los 3 que ya había sobre el catálogo: **6/6 cazados**.
7. La pasada completa de 97 sobre el código de L9-29 se paró a petición del lead (la máquina se comparte con ocho agentes y los E2E del H8) tras 93 mutantes: **90 cazados y los 3 equivalentes**. Los 4 que no alcanzó ya estaban cazados: los 3 de L9-29, en la pasada 6 sobre el mismo código, y `text-own-hours`, en la 3. Al cortarla restauré la copia aislada desde el árbol (`rsync` y `diff -rq` limpios).

El último cambio, el texto de `calendar_outside_legal_hours` y el PHPDoc de `ContactCalendar`, no toca la lógica: las cifras siguen valiendo y no se repitió ninguna pasada (decisión del lead).

### 8. Qué verifiqué, con comandos y resultados

Todo ejecutado desde `backend/`, con `php -d memory_limit=2G` y `--no-logging`, que no pisa el `junit.xml` compartido.

| Comando | Resultado |
|---|---|
| `vendor/bin/phpunit --no-logging tests/Unit/Outreach/Core` | `OK (884 tests, 39161 assertions)` |
| `vendor/bin/phpunit --no-logging tests/Unit/Outreach` (con `FixturesSanityTest` de TEST9-a) | `OK (962 tests, 52564 assertions)` |
| `vendor/bin/phpunit --no-logging tests/Feature/Outreach/Facts` (B9-facts, SQLite en memoria, usa `PolicyLadder` y `ContactBudget` reales) | `OK (50 tests, 915 assertions)` |
| `vendor/bin/phpunit --no-logging tests/Unit/UltronStudio tests/Unit/Telephony` | `OK (1876 tests, 16120 assertions)` |
| `vendor/bin/pint --test app/Services/Outreach/Core tests/Unit/Outreach/Core` | `passed` |
| `php tests/Unit/Outreach/Core/tools/export-trace-text-cases.php` | 40 296 bytes, sha256 `b94019ba09ee1379d2b869be1be8e94cb14facb4d20918e5f9e66b3deccdb157`; `dictionaryHash` `e58ae10699e96899a07a27f5bc4b9f359fe0c9c61e428c2d27b206a32172f1c5` |
| Huella del catálogo `.2` recalculada aparte (`StudioContentHasher` sobre el fixture con L9-29) | `ce5fcdab…`, la misma que `OutreachCatalog::hash()` |
| `grep -rn 'ContactBudget::check\|PolicyLadder::decide\|ContactCalendar::window' app tests` (fuera de `Core`) | Solo B9-facts (`CoreProjection`, `SubjectAndSnapshotTest`), compatible |

La pasada de `tests/Unit/UltronStudio tests/Unit/Telephony` es de antes de los cambios de L9-25, L9-27/L9-30 y L9-29. Esas suites no importan `Outreach\Core`: fuera de mis carpetas nadie nombra `OutreachCatalog::VERSION` ni su huella (comprobado con `grep`). No la repetí.

**No verificado:**
- PostgreSQL: el núcleo no toca la base.
- La paridad de frecuencia T9 contra el `ContactPolicy` real con base de datos: la cubren los 50 casos del fixture; contra la base queda para B9-run y TEST9.
- La integración del tope por teléfono entre pasadas, que es de B9-run.

### 9. Q-n para el lead

En cada caso elegí la opción más estricta, salvo donde digo lo contrario.

| Q | Qué | Qué hice |
|---|---|---|
| Q-1 | Ruta del informe: el contrato dice `plans/informes-h9/informe-core.md` y el encargo `informe-backend.md` (§B9-core). | Seguí el encargo. Es este fichero, con mi sección delimitada. |
| Q-2 | Dónde vive `trace-text-cases.json` (L9-25). El README de TEST9-a dice que no va en `tests/fixtures/outreach/`. | `tests/Unit/Outreach/Core/fixtures/`, mi carpeta, atado a la implementación por una prueba. Si FE9 lo quiere en otro sitio, se mueve con una línea (`TraceTextCases::PATH`). |
| Q-3 | **`phone` es OPCIONAL en la entrada de la escalera** (ausente = no se cruza). Hacerlo obligatorio rompería `SubjectAndSnapshotTest` (B9-facts llama a `decide()` con la `baseInput` y su `policyInput()`) y los 116 casos de TEST9-a. Además, `FactsCoreUnionTest` exige que cada clave de `policyInput()` esté en `SPEC`: si B9-facts añade `phone`, tendrá que mirar también `PolicyLadder::OPTIONAL`. | Opcional, anunciado. **B9-run debe pasarlo siempre** con `phone_refs` (`CoreProjection::phoneKeys()`; si es null, `refs = []`) y los contactos de hoy por `phone_ref`. Propongo volverlo obligatorio cuando B9-run aterrice. El literal `unknown` de L9-27 no se acepta en `refs` (lanza): B9-facts representa el desconocido como `phone_ref` null y `phone_refs` `[]` (L9-30). |
| Q-4 | Qué contactos cuentan para el teléfono: los de `outreach_jobs` por `phone_ref`, pero también los de fuera del H9 (proactivos de la IA o llamadas de voz a ese número de OTRA persona). | El núcleo cuenta lo que recibe. Que la lista esté completa es cosa de B9-run y B9-facts. |
| Q-5 | `receivable.known` admite null. DF-L12 solo habla de `false`. | null = no se sabe = bloquea (el lado seguro). Lo cubre una prueba. |
| Q-6 | DF-O4 con una transición permitida por la tabla pero no segura para el clasificador. | `incident = true` (estricto). Con la tabla actual es inalcanzable: ningún resultado del clasificador admite una transición insegura. Por eso tres mutantes son equivalentes (§7). |
| Q-7 | El selector admite cualquier número de canales sin repetir. El tope de 3 por paso lo pone `StrategyContent`, y el fixture del selector trae un caso de 4. | Así. |
| Q-8 | `ContactCalendar::SEARCH_DAYS = 400`: más allá no hay hueco, y se da `deny` sin `retryAt`. | Así. |
| Q-9 | #6 de §13.1 es «B9-facts + B9-core · `H9LeadFollowupTest.php`». | Cubrí la parte pura (N1 sin autorización) en `H9PolicyLadderN1Test`. El fichero `H9LeadFollowupTest.php` necesita el adaptador de prospectos y no lo creé. ¿Lo escribe B9-facts o B9-run? |
| Q-10 | La guarda de datos personales de `FactSnapshot` y `DecisionTrace` es por forma de token: `[A-Za-z_][A-Za-z0-9_.:-/]`. | Rechaza teléfonos, correos, frases y espacios. No puede reconocer un nombre propio de una sola palabra escrito como token. Lo mitiga que todo valor de la foto venga del catálogo (dominios cerrados). |
| Q-11 | Textos que el lead revisa (L9-25). | `calendar_outside_legal_hours` con el texto del lead y «terminan» en vez de «empiezan» (el silencio de `ContactPolicy` acaba a las 08:00; si se prefiere el literal, es una línea y regenerar); `daily_contact_limit` con «o con su teléfono»; los tres nuevos. |
| Q-12 | `DecisionTrace` (traza guardada) y `DecisionTraceText` (frases): el contrato nombra la segunda y el encargo la primera. | Entregué las dos. |
| Q-13 | **L9-29 cambia el catálogo después de los fixtures.** TEST9-a tiene que regenerar `catalog-snapshot.json`: versión `.2`, plantilla `cls:{class_id}:{session_date}` con `keyVersion` 2, huella `ce5fcdab…` y el caso de clases de `cycleKeys` con `{class_id, session_date}`. También los metadatos `catalogVersion`/`catalogHash` de `conditions-cases.json` y `strategy-content-cases.json`. | Hasta entonces, la comparación de transición de §3.7. Cuando regeneren, se puede borrar `L9_29` del trait; mientras tanto no hace daño. |
| Q-14 | ¿Subir `keyVersion` de la clase a 2? | Sí (estricto): cambió la identidad de la inscripción (`UNIQUE strategy, subject, cycle, mode`). Con la 1, una estrategia publicada antes del cambio podría reinscribir a la misma persona en la misma clase con una clave nueva. Hoy no hay ninguna publicada. |

Quedan cerradas por L9: `cross_channel_week_rule` (L9-10), `provider_error` → `failed` (L9-12) y `enrollment_window_over` en N7 (L9-24).

### 10. Para los demás paquetes

**B9-run:**
- La entrada `phone` de la escalera (Q-3, Q-4).
- `retryAt` de la escalera, que ya incluye la ley, el horario propio y el de la estrategia.
- `waitsForRelease` en N2.
- `effect` en N6.
- `OutreachCatalog::cycleKey()`; la de las clases se arma ya con los `cycleParts` que da B9-facts (`class_id`, `session_date`).
- `DecisionTrace::build()` con `kind ∈ {enroll, exclude, deny, defer, plan, cancel, exit, handoff}` y partes `policy|channel|priority|followUp|gate`.
- `ChannelSelector::choose()` con la decisión de cada canal.
- Atención: `ContactBudget::check()` ya no recibe `ownHours`. Un sexto argumento se lee como `phone`.

**S9:** `StrategyContent::parse()`/`fromStored()` y el nuevo `LIMITS_OPTIONAL`; `StrategyChainSignature`; `CanonicalCondition::parse()` (422).

**FE9:** `trace-text-cases.json` (§4); las plantillas son literales.

**TEST9, casos límite que conozco desde dentro:**
- el sexto argumento sobrante de PHP (§3.2);
- el fin de ventana semiabierto;
- el empate de instantes en el último contacto;
- 00:00 de Bogotá frente a 23:59:59 en el tope por teléfono;
- `refs = []` frente a `phone = null`;
- los domingos seguidos de lunes festivo;
- el horario propio `'invalid'`, que niega sin `retryAt`.

**SEC9:**
- El escaneo de pureza de `DecisionTraceAndPurityTest` admite solo `ColombianHolidays`, `VoiceFrequency`, `ValidationException`, `Closure`, las clases `DateTime*`, `InvalidArgumentException` y `JsonException`.
- La única derivación de `APP_KEY` es la subclave `ultron-studio-strategy-chain`.
- Q-10.
