# Hito 9 · TEST9-a (paso 1) · informe del tester: los fixtures desde el contrato

**Fecha:** 09-11-2026.
**Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, sobre `ab54a256`.
**Contrato:** `ULTRON_STUDIO_H9_CONTRACT.md` (sin seguimiento), congelado por el lead el 09-11-2026 a las 20:30 UTC, con §13.2 renumerado T1–T23.
**Alcance tocado:** solo `tests/fixtures/outreach/**`, `tests/Unit/Outreach/FixturesSanityTest.php` y este informe.
**Sin commit** y sin tocar el índice. **Producción:** nada. **Red:** ninguna.

## §L9 · Cierre: las decisiones del lead aplicadas (09-11-2026, L9-8 a L9-25)

El lead aceptó tal cual las Q-1 a Q-18 de §4, salvo **tres ajustes** que cambian el fixture. Quedan en el contrato como L9-15, L9-20 y L9-21 y en el README como decisiones. **No queda ninguna pregunta abierta de TEST9-a.**

### Qué cambió

- **L9-15 · el horario propio pasa a N5** con su código, `calendar_outside_own_hours`, distinto de `calendar_outside_legal_hours` (N1). Así la traza distingue la ley de la política propia.
  - `calendar-cases.json` §window: cada caso da `n1` (la ley) y `n5` (el horario propio), en lugar de una sola lista `reasons`. N5 solo se mira si la ley se cumple.
  - `contact-budget-cases.json`: `ContactBudget` ya no recibe `ownHours`. Es solo ley y frecuencia, y su `retryAt` es el primer hueco legal. El caso de las 08:30 con horario propio pasa a «permitido».
  - `policy-ladder-cases.json` N5: `calendar_outside_own_hours` va entre `canary_scope_mismatch` y `outside_strategy_schedule`, y aplaza al primer hueco del horario propio. Un horario mal configurado niega sin `retryAt` (DF-L11). Fuera de la ley decide N1, pero su `retryAt` ya respeta el horario propio (DF-L3).
- **L9-20 · la cartera vencida entra en N6**, después de `human_requested_recently`, como `collections_overdue_blocks_commercial` (DF-L12).
  - Es permanente y solo con finalidad `commercial`.
  - Bloquea si `receivable.overdueDaysMax` supera el umbral («más de N días»: a los N justos no) o si la fuente de cartera no se pudo leer (`receivable.known = false`, el lado seguro).
  - Umbral = `outreach.collections.commercial_block_days` (15 por defecto). La estrategia solo lo baja, con `limits.commercialBlockOverdueDays` (opcional, 0–365, solo en `commercial`; en `collections` o `service` es `unexpected_key`), y el efectivo es el menor de los dos.
  - Entradas nuevas de la escalera: `receivable {known, overdueDaysMax}` y `collectionsBlock {serverDays, strategyDays}`. Están en `policyInputs` de `catalog-snapshot.json`, fuera de la huella.
- **L9-21 · `catalog_incompatible` pasa a N3**, después de `studio_reference_invalid` (DF-L13). La escalera recibe `studio.catalogCompatible` y `catalog-snapshot.json` §compatibility da `level: "N3"`.
- **Ratificaciones.** Los textos de las DF ratificadas citan su L9-n: DF-B4, DF-B5, DF-K1, DF-K6, DF-T1, DF-T2, DF-T5, DF-L5, DF-L9, DF-L10, DF-G5, DF-O1, DF-O3, DF-E2 y DF-V3. Los casos que dependen de los tres ajustes van como `supuesto-lead`.
- **O-3, O-4 y O-5 (L9-25).** Los textos de traza los exporta B9-core (`trace-text-cases.json`); los vectores de `subject_ref`, B9-facts; y la tabla de consecuencias de la escalera, B9-run. No son de este paquete.
- **Lo que NO cambió.**
  - La parte hasheada del catálogo: `catalogHash` sigue en `4580c447…`, el que cita L9-14.
  - *(Lead, 23:10 UTC: tras L9-29/L9-33 el catálogo pasa a `2026-11-10.2` con huella `ce5fcdab…`; regenerado por el lead con el generador, 803 casos; ver el README.)*
  - `conditions-cases.json`, salvo los textos de sus decisiones.
  - La estructura de los demás ficheros.

### Recuento final

| Fichero | Casos | Antes |
|---|---|---|
| `catalog-snapshot.json` | 21 | 21 |
| `conditions-cases.json` | 226 | 226 |
| `calendar-cases.json` | 48 | 48 |
| `contact-budget-cases.json` | 85 | 85 |
| `policy-ladder-cases.json` | **116**: `n1` 24, `n2` 17, `n3` 7, `n4` 13, `n5` 16, `n6` 17, `n7` 6, `precedence` 9, `shadow` 7 | 99 |
| `channel-selection-cases.json` | 18 | 18 |
| `priority-cases.json` | 14 | 14 |
| `outcome-transitions.json` | 149 | 149 |
| `followup-cases.json` | 22 | 22 |
| `strategy-content-cases.json` | **58**: `valid` 13, `invalid` 40, `versions` 5 | 55 |
| `strategy-chain-vectors.json` | 46 | 46 |
| **Total** | **803** | 783 |

**Por base:** `contrato` 376, `decision-fixture` 379, `supuesto-lead` 22, `ley` 21 y `codigo-existente` 5. **Decisiones:** 89 DF-… en las cabeceras (nuevas: DF-W5, DF-L11, DF-L12 y DF-L13).

### Casos nuevos

- **Escalera (+17):**
  - N3: catálogo incompatible, solo y junto a la referencia inválida.
  - N5: horario propio el martes a las 08:30 y el sábado a las 13:30, horario mal configurado y dentro del horario.
  - N6: 16 días bloquean y 15 no; la estrategia endurece a 7, intenta aflojar a 30 y el servidor endurece a 5; sin cartera; fuente ilegible; `collections` y `service` exentas.
  - Precedencia: a las 07:30 decide la ley y no el horario propio; cartera más persona pedida, con efecto `handoff`.
- **Contenido de estrategia (+3):** `commercialBlockOverdueDays` válido en `commercial`, en `collections` (`unexpected_key`) y negativo.
- **`FixturesSanityTest`:** comprueba `n1`/`n5` del calendario (N5 solo si N1 pasa), que cada motivo nuevo vaya en su nivel (N3, N5 y N6), que el bloqueo por cartera solo aparezca con finalidad `commercial`, el `level` N3 de la compatibilidad y la única excepción a «defer ⇔ todos temporales» (horario propio inválido).

### Verificación (salida real, sobre los ficheros finales)

```
$ python3 -I tests/fixtures/outreach/tools/build/build.py
… policy-ladder-cases.json: {"n1": 24, "n2": 17, "n3": 7, "n4": 13, "n5": 16, "n6": 17, "n7": 6, "precedence": 9, "shadow": 7, "total": 116}
… strategy-content-cases.json: {"valid": 13, "invalid": 40, "versions": 5, "total": 58}
total: 803

$ php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Unit/Outreach/FixturesSanityTest.php
OK (78 tests, 13403 assertions)

mutaciones: 27 · cazadas 27 · vivas 0      (las 23 de antes + m_own_level, m_window_n5, m_collections_service, m_compat_level)
```

- **Las cuatro mutaciones nuevas** ponen el horario propio en N1 (en la escalera y en el calendario), el bloqueo por cartera en una estrategia `service` (con `input` y `patch` coherentes, para que la caza sea de la regla y no del merge) y la compatibilidad en N5.
- **Todos los literales nuevos pasan contra la referencia del generador a la primera.**
- **Reproducible:** dos pasadas en el scratchpad dan los mismos bytes que el repo (1 970 890 bytes en total) y no queda `__pycache__`.

| Fichero | sha256 (16) |
|---|---|
| `calendar-cases.json` | `a420c800890a55c0` |
| `catalog-snapshot.json` | `a30f280a537ee375` |
| `channel-selection-cases.json` | `16984335713c8d9c` |
| `conditions-cases.json` | `0e71d32ac5ecd48a` |
| `contact-budget-cases.json` | `f11d8d30ea4b5687` |
| `followup-cases.json` | `f7e40832ccd146c5` |
| `outcome-transitions.json` | `f9e38189b8e1a1f9` |
| `policy-ladder-cases.json` | `354ba6bd9b18d626` |
| `priority-cases.json` | `d6224044035fa891` |
| `strategy-chain-vectors.json` | `19f5ef9dd71f1a10` |
| `strategy-content-cases.json` | `eec27df26074c56a` |

**La suite Unit completa, dos pasadas tras regenerar:**

- **Primera pasada: `Tests: 6192, Assertions: 87976, Failures: 47`.** Los 47 son de las pruebas de paridad de B9-core (`tests/Unit/Outreach/Core`) y corresponden exactamente a lo que cambiaron L9-15, L9-20 y L9-21. Fuera de ese directorio no falló ninguna.
  - 27 en `test_calendario`: la forma `reasons` pasó a `n1`/`n5`.
  - 11 en `test_escalera`: los 11 casos nuevos que niegan o aplazan; los 6 nuevos que permiten pasaban ya.
  - 1 en `test_los_conjuntos_del_fixture_son_los_del_codigo`: los tres códigos nuevos.
  - 6 en `test_compatibilidad_de_una_version`: el campo `level`.
  - 2 en `test_contenido`: `commercialBlockOverdueDays`, válido y negativo.

  Ningún caso anterior a las L9 falló.
- **B9-core aplicó las L9 mientras corría la suite** (no toqué su código ni sus pruebas).
- **Después, `tests/Unit/Outreach`: `OK (926 tests, 36619 assertions)`.**
- **Segunda pasada:**

```
$ php -d memory_limit=2G vendor/bin/phpunit --no-logging --testsuite Unit
Time: 03:13.064, Memory: 337.00 MB
OK (6192 tests, 88006 assertions)
```

*Lo que sigue es la entrega del paso 1 tal como se mandó al lead, antes de sus decisiones L9-8 a L9-25; las cifras finales están arriba.*

## §TEST9-a

### 0. Resumen

- **Entregado:** 11 fixtures JSON, su `README.md`, el generador (`tools/build/`, 12 ficheros Python) y `FixturesSanityTest`.
- **Volumen:** 783 casos y 1 852 480 bytes de JSON.
- **Base de los casos:**

  | Base | Casos |
  |---|---|
  | `contrato` | 376 |
  | `decision-fixture` | 380 |
  | `ley` | 21 |
  | `codigo-existente` | 5 |
  | `supuesto-lead` | 1 |

  Lo que el contrato no fija va como `decision-fixture` con su DF: son **85 decisiones** escritas en las cabeceras, que **tiene que congelar el lead**.
- **Verificación (salida real en §3):**
  - `FixturesSanityTest`: **78 pruebas, 12 985 aserciones, en verde**;
  - **23 de 23** fixtures estropeados a propósito la hacen fallar;
  - el generador es reproducible byte a byte (dos pasadas, los mismos sha256);
  - cada veredicto escrito a mano se contrasta con una referencia propia, que cazó **tres literales míos equivocados** durante la escritura (§3.3);
  - las huellas, el JSON canónico, las trazas, la huella del catálogo y las firmas de la cadena coinciden byte a byte entre Python y PHP (`StudioContentHasher`, `hash_hmac`);
  - los festivos coinciden fecha a fecha con `holidays-co-2026-2028.json` del H8 (19 por año).
- **Orden de entrega (petición del lead):** `catalog-snapshot.json` y `conditions-cases.json` quedaron listos primero (v1) y después solo se les añadieron casos y referencias a decisiones: su estructura y `catalogHash` (`4580c447…`) no cambiaron.
- **Lo que más pesa para el lead (§4):** Q-2 (dos límites de D9-7 sin código), Q-3 y Q-5 (códigos de §13.1 que no están en los conjuntos cerrados), Q-7 (tipos de los hechos) y Q-14 (la cartera vencida > 15 días sin código).

### 1. Ficheros (lista exacta)

| Fichero | Bytes | Casos | Secciones |
|---|---|---|---|
| `tests/fixtures/outreach/catalog-snapshot.json` | 85 239 | **21** | `compatibility` 6, `cycleKeys` 15; más `catalog` (41 eventos, 42 hechos), `catalogHash`, `annotations`, `policyInputs` |
| `tests/fixtures/outreach/conditions-cases.json` | 414 192 | **226** | `canonicalize` 49, `equivalence` 10, `reject` 79, `evaluate` 80, `bogotaDays` 8 |
| `tests/fixtures/outreach/calendar-cases.json` | 29 561 | **48** | `holidays` 3, `window` 27, `nextSlot` 18 |
| `tests/fixtures/outreach/contact-budget-cases.json` | 87 826 | **85** | `effectiveLimits` 9, `check` 26, `parity` 50 |
| `tests/fixtures/outreach/policy-ladder-cases.json` | 413 890 | **99** | `n1` 24, `n2` 17, `n3` 5, `n4` 13, `n5` 12, `n6` 8, `n7` 6, `precedence` 7, `shadow` 7 |
| `tests/fixtures/outreach/channel-selection-cases.json` | 50 082 | **18** | `choose` 18 |
| `tests/fixtures/outreach/priority-cases.json` | 18 111 | **14** | `arbitrate` 14 |
| `tests/fixtures/outreach/outcome-transitions.json` | 70 824 | **149** | `matrix` 108, `resolve` 41 |
| `tests/fixtures/outreach/followup-cases.json` | 35 934 | **22** | `apply` 22 |
| `tests/fixtures/outreach/strategy-content-cases.json` | 582 764 | **55** | `valid` 12, `invalid` 38, `versions` 5 |
| `tests/fixtures/outreach/strategy-chain-vectors.json` | 64 057 | **46** | `chain` 7, `subkey` 4, `verify` 35 |

Además:
- `tests/fixtures/outreach/README.md`;
- `tests/fixtures/outreach/tools/build/build.py` y `sec_catalog.py`, `sec_conditions.py`, `sec_calendar.py`, `sec_budget.py`, `sec_ladder.py`, `sec_channel.py`, `sec_priority.py`, `sec_outcomes.py`, `sec_followup.py`, `sec_strategy.py`, `sec_chain.py`;
- `tests/Unit/Outreach/FixturesSanityTest.php`;
- este informe.

**Nombres (Q-1).** El encargo nombraba `outcome-cases.json` y `chain-vectors.json`. El contrato los llama `outcome-transitions.json` (D9-10 y §11) y `strategy-chain-vectors.json` (§11). Los entregué con el nombre del contrato, porque B9-core lee el contrato. `followup-cases.json` sale del encargo y `calendar-cases.json` del contrato (§11). Los dos nombres que B9-core ya cita en su código (`conditions-cases.json` y `catalog-snapshot.json`) coinciden: lo comprobé con un `grep` de nombres de fichero, sin leer su código.

### 2. Cómo se escribió

- **Contrato primero.** Cada veredicto es un literal decidido leyendo el contrato. Donde calla, el caso lo dice (`decision-fixture` y su DF) y no se esconde como contrato.
- **Lo que se copió del árbol, con su cita:**
  - los conjuntos cerrados de `CommercialVocabulary`, `CommercialPhaseMachine`, `SalesAgentDecisionSchema::TEMPERATURES` y `MarketingLead::CONSENT_STATUSES`;
  - la ventana legal semiabierta y el horario propio que falla cerrado de `OutboundSchedule`;
  - las horas de silencio, el «≥ now − 7 d» y el «< 48 h» de `ContactPolicy` (con Carbon 3.11.4, `diffInHours` devuelve decimales);
  - `PER_DAY` y la cota de `per_week` de `VoiceFrequency`;
  - la derivación de subclave de `UltronPublishedConfig::subkey()`;
  - `StudioContentHasher::canonicalJson`.
- **No leí el código de los paquetes paralelos.** En `app/Services/Outreach/` hay 50 ficheros de B9-core y B9-facts. Solo busqué qué nombres de fixture citan.
- **Referencias propias en el generador.** Cada pieza tiene la suya:
  - validador y evaluador de la gramática;
  - calendario y presupuesto;
  - modelo de `ContactPolicy`;
  - escalera, selector y árbitro;
  - tabla de resultados y máquina de seguimientos;
  - lector de estrategias y verificador de la cadena.

  Sirven **solo** para contrastar los literales. Si una discrepa de un literal, el generador se para. La traza completa, las huellas y las firmas las escribe la referencia; el valor y el resultado de cada hoja, el autor.
- **Paridad T9.** 10 historiales escritos a mano en los bordes (47:59/48:00, 7 d justos/7 d + 1 s, 20:59/21:00, 07:59/08:00, domingo) y 40 generados con semilla fija (`20261110`). 26 de los 40 generados tienen una negativa de `ContactPolicy`: 10 por silencio, 11 por 48 h y 5 por tope semanal. En los 50, lo que niega `ContactPolicy` lo niega `ContactBudget`.
- **Datos sintéticos.** La `APP_KEY` es la del H6/H8 (bytes 00..1f). Personas por `subject_key`. Ni teléfonos, ni nombres, ni importes, ni invisibles literales.

### 3. Verificación (salida real)

#### 3.1 Generador y prueba

```
$ python3 -I tests/fixtures/outreach/tools/build/build.py
catalog-snapshot.json: {"compatibility": 6, "cycleKeys": 15, "total": 21}
conditions-cases.json: {"canonicalize": 49, "equivalence": 10, "reject": 79, "evaluate": 80, "bogotaDays": 8, "total": 226}
calendar-cases.json: {"holidays": 3, "window": 27, "nextSlot": 18, "total": 48}
contact-budget-cases.json: {"effectiveLimits": 9, "check": 26, "parity": 50, "total": 85}
policy-ladder-cases.json: {"n1": 24, "n2": 17, "n3": 5, "n4": 13, "n5": 12, "n6": 8, "n7": 6, "precedence": 7, "shadow": 7, "total": 99}
channel-selection-cases.json: {"choose": 18, "total": 18}
priority-cases.json: {"arbitrate": 14, "total": 14}
outcome-transitions.json: {"matrix": 108, "resolve": 41, "total": 149}
followup-cases.json: {"apply": 22, "total": 22}
strategy-content-cases.json: {"valid": 12, "invalid": 38, "versions": 5, "total": 55}
strategy-chain-vectors.json: {"chain": 7, "subkey": 4, "verify": 35, "total": 46}
total: 783

$ php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Unit/Outreach/FixturesSanityTest.php
OK (78 tests, 12985 assertions)
```

`--no-logging` evita reescribir `storage/test-results/junit.xml`, que comparte el árbol.

**Suite Unit completa** (con la prueba nueva dentro), sobre el árbol compartido tal como estaba (con el trabajo en curso del H8 y de B9-core/B9-facts):

```
$ php -d memory_limit=2G vendor/bin/phpunit --no-logging --testsuite Unit
Time: 01:59.468, Memory: 317.00 MB
OK (5344 tests, 64372 assertions)
```

No lancé la suite Feature: mis cambios son datos bajo `tests/fixtures/outreach/` y una prueba Unit, y ninguna de las guardas que recorren el árbol mira `tests/`: `UltronEngineIsolationTest` recorre `app`, `routes`, `config`, `database` y `bootstrap`, y las de voz, telefonía, OTP, laboratorio y caos recorren subdirectorios de `app/` y ficheros de `routes` y `config` (lo comprobé leyendo sus raíces).

**Qué comprueba `FixturesSanityTest`** (sin una línea de `App\Services\Outreach`):
- **Forma.** Están los 11 ficheros y ninguno de más; la cabecera completa; las fuentes con sus cuatro campos; los recuentos cuadran; los nombres son únicos y llevan su sección; la base es válida; toda decisión usada está escrita y no sobra ninguna; no hay invisibles literales; hay salto de línea final; el README nombra cada fichero con su recuento.
- **Huellas en PHP.** Las rehace `StudioContentHasher`:
  - la del catálogo;
  - las 49 formas canónicas de las condiciones y las 12 de las estrategias;
  - cada `traceHash`, con la raíz igual al valor y las hojas iguales a `leafResults`.
- **Cadena.** La subclave (la misma expresión que `UltronPublishedConfig.php:462-472`), distinta de la del H5 y de la de voz; cada mensaje canónico, su huella y su firma con `hash_hmac`; los `prevHash` encadenados globalmente; las `ok` verifican y las `signature_mismatch` no.
- **Cruces.**
  - Condiciones y estrategias citan la huella del catálogo.
  - Los festivos son los del fixture del H8.
  - La hora de Bogotá de cada instante coincide con `DateTimeZone`.
  - Ningún hueco cae en domingo ni en festivo.
  - Los motivos del presupuesto son de N1 y van en su orden.
  - La tabla de resultados coincide con los 18 del catálogo.
  - Escalera: el `input` es `baseInput` + `patch` (merge rehecho en PHP), el primer nivel que niega decide y `defer` ⇔ todos los motivos temporales.
  - El selector elige el primer permitido.
  - La matriz 18 × 6 coincide con la tabla.
  - La IA solo frena o deriva.
  - `opted_out` siempre acaba en `end_exit`.
  - Ningún `notBefore` cae antes de `now` ni después de la ventana.

#### 3.2 La prueba no se dobla (mutaciones)

Copias del directorio en el scratchpad, una mutación cada vez, con `OUTREACH_FIXTURES_DIR`:

```
CAZADA  m_bogota        CAZADA  m_canon_hash     CAZADA  m_catalog_max   CAZADA  m_chain_prev
CAZADA  m_chain_sig     CAZADA  m_classifier     CAZADA  m_content_hash  CAZADA  m_counts
CAZADA  m_drop_decision CAZADA  m_dup_name       CAZADA  m_extra_file    CAZADA  m_followup
CAZADA  m_holiday       CAZADA  m_invisible      CAZADA  m_ladder_input  CAZADA  m_ladder_order
CAZADA  m_ladder_verdict CAZADA m_optout         CAZADA  m_parity        CAZADA  m_readme
CAZADA  m_reject_codes  CAZADA  m_selector       CAZADA  m_trace_hash
mutaciones: 23 · cazadas 23 · vivas 0
```

Qué estropea cada una:
- **Catálogo:** un máximo de un hecho.
- **Condiciones:** una huella canónica, un `observed` de una traza y un código de error inventado.
- **Escalera:** el orden de los motivos, un `verdict` y un `input` que no casa con su `patch`.
- **Cadena:** una firma y un `prevHash`.
- **Paridad:** un presupuesto que permite lo que `ContactPolicy` niega.
- **Calendario:** la hora de Bogotá y un festivo cambiado.
- **Selector:** que elija un canal posterior.
- **Resultados:** `opted_out` → `next_step` y una IA que reintenta.
- **Seguimientos:** un `notBefore` en el pasado.
- **Estrategia:** un `canonical` cambiado.
- **Forma:** un nombre repetido, una decisión borrada, un U+200B literal, un caso de menos, un fichero de más y el total del README.

#### 3.3 Defectos de mis propios literales que cazó la referencia (corregidos)

1. **«Nodo de expresión regular».** Esperaba `missing_key` en `op` y en `value`. Mi propia regla DF-G3 dice que sin operador no se juzga el valor: queda solo `op`. Lo mismo en «sin op ni value».
2. **«51 nodos y un hijo inválido».** Contaba 50 nodos, porque una cadena no es un nodo. Añadí una hoja.
3. **El árbol de 8 192 bytes exactos.** El primer intento colapsaba un `any` de un hijo. La búsqueda ahora es aritmética.

#### 3.4 Reproducible y limpio

Dos ejecuciones del generador en directorios del scratchpad dan los mismos sha256 que los ficheros del repo:

| Fichero | sha256 (16) |
|---|---|
| `calendar-cases.json` | `1adc6bb4a49e6ecd` |
| `catalog-snapshot.json` | `fd7e988f2c7256cb` |
| `channel-selection-cases.json` | `9039004cf92b61e1` |
| `conditions-cases.json` | `585008c76732eb72` |
| `contact-budget-cases.json` | `fd5aac81e0c09f2a` |
| `followup-cases.json` | `8af8055c549d5ee5` |
| `outcome-transitions.json` | `0f12231d814c5cac` |
| `policy-ladder-cases.json` | `bc909c04477f7cee` |
| `priority-cases.json` | `d6224044035fa891` |
| `strategy-chain-vectors.json` | `20a66ba7aad53921` |
| `strategy-content-cases.json` | `3307afc9e347aa19` |

Sin `__pycache__`: el generador ejecuta las secciones con `exec` y se lanza con `-I`.

### 4. Preguntas para el lead (Q-n)

Donde el contrato era ambiguo elegí lo más estricto y seguí.

| Q | Dónde | Qué pasa | Qué hace el fixture |
|---|---|---|---|
| **Q-1** | §11 frente al encargo | Dos nombres de fichero distintos | Los del contrato; `followup-cases.json` aparte |
| **Q-2** | D9-7 | El presupuesto trae «≤ 2 a la semana» y «48 h entre contactos», pero N1 no tiene código para ellos; sin ellos se rompe la paridad T9 | Dos códigos nuevos en N1, tras `daily_contact_limit`: `weekly_contact_limit` y `min_hours_between_contacts` (DF-B4) |
| **Q-3** | #11 frente a D9-7 | #11 dice `cross_channel_cooldown`; el conjunto cerrado de N1 dice `cross_channel_week_rule` | `cross_channel_week_rule`; hay que corregir el texto de #11 |
| **Q-4** | #2 frente a D9-3 | #2 usa `daysBefore: 0`; D9-3 escribe `{daysBefore: 1..60}` | `[0, 60]`, y la ventana es `[0, daysBefore]`, no el día exacto (DF-K1) |
| **Q-5** | #18, #21 y #22 frente a D9-10 | `provider_error` y `needs_review` no están entre los 18 resultados (ni en el CHECK de `outreach_outcomes`) | `provider_error` = `failed` (D9-10: «canal, 4xx definitivo»). `needs_review` no es un resultado, sino un **veredicto** de `OutcomeTable` que para la secuencia y abre una tarea asistida (`handoff_human`) (DF-O3). Si el propietario lo quiere como resultado, cambian el conjunto cerrado y DB9 |
| **Q-6** | D9-4 | `membership.plan_id` y `class.attended_type_last_60d` son «enum» de dominios que viven en la base (`plans`, `classes.type` es texto libre); el contrato exige dominios cerrados | El catálogo los declara `{"dynamic": …}` y el validador recibe una instantánea (DF-T5). ¿Quién la da (B9-facts o S9)? ¿Qué pasa con un valor que desaparece? Lo más estricto sería congelarlos en el catálogo y subir la versión con cada plan nuevo |
| **Q-7** | D9-4 y D9-5 | D9-4 no da el tipo de cada hecho; `duration` no tiene operadores en D9-5; ningún hecho de D9-4 es `date_relative`, y el ejemplo de D9-6 (`membership.days_to_expiry gt 30`) obliga a que ese sea entero | Tipos fijados (DF-T1): `duration` con los operadores enteros y tres hechos `date_relative` nuevos (DF-T2) |
| **Q-8** | D9-7 N1 y P9-17 | El horario propio global no tiene código | Usa `calendar_outside_legal_hours` (DF-W2), como el H8 juntaba legal y propio. Alternativa: `outside_own_hours` |
| **Q-9** | D9-8 frente a D9-9.4 | En sombra, D9-8 solo convierte en aviso tres motivos de N4 y los presupuestos; pero D9-9.4 dice que las estrategias de voz «se simulan» hasta V9 | `channel_not_live` también es aviso en sombra (DF-L5) |
| **Q-10** | D9-15 | «Un lead sin fecha queda desconocido y no se infiere»; el contrato no dice nada de un **socio** sin fecha | No se infiere tampoco (DF-L10). Lo estricto sería negar (`subject_minor`); lo decide la asesoría |
| **Q-11** | D9-10 | Umbral bajo y alto del clasificador sin cifra | 50 y 90 sobre 100 (DF-O3), solo para AI9 |
| **Q-12** | D9-6 | `limits.newEnrollmentsPerDay ≤ «un tope del servidor»` | 500, el de la pasada de D9-8 (DF-E2) |
| **Q-13** | T10 frente a D9-5 | «La misma condición en otro orden da la misma huella» frente a «los hijos no se reordenan» | «Otro orden» = orden de las claves y de los valores de `in`; con los hijos en otro orden, otra huella y el mismo valor (`equivalence`) |
| **Q-14** | P9-7 | «Sin contacto comercial con cartera vencida > 15 días» no tiene código en N3 ni en N6, y los hechos `receivable.*` son solo de cobranza para quien escribe estrategias | **No está en la escalera** del fixture. Propuesta: N6 `collections_overdue_blocks_commercial`, con el hecho como entrada de la política (está en `policyInputs`) |
| **Q-15** | D9-3 | `catalog_incompatible` no está en la lista cerrada de N5 | El fixture lo usa como resultado de `compatibility`; falta decidir su nivel |
| **Q-16** | D9-15 y P9-8 | «Contacto directo» (Ley 2300, art. 3) | Cualquier contacto contado (también un intento sin respuesta) activa la regla de un canal (DF-B5), la lectura más estricta. La asesoría puede acotarlo a contactos efectivos |
| **Q-17** | D9-11.3 | El ejemplo del mensaje firmado escribe `action` antes que `actedByPerson`; «canónico» ordena por bytes | Firma el ordenado (los vectores lo fijan; con el orden del ejemplo, `not_canonical`). Además, `approvedByPerson` es null en pausa, reanudación, reversión y retiro, y `status ∈ {active, paused, off}` (DF-V3) |
| **Q-18** | D9-7 N7 | `enrollment_window_over` es de N5, pero D9-7 lo pone como salida de N7 | Nivel N7 con ese motivo (DF-L9) |

### 5. Observaciones para otros paquetes (no son del fixture)

- **O-1 (`ContactPolicy`, motor comercial: no se toca, protección 6).** Su `retry_at` del tope semanal es `oldest + 7 d`. En ese instante exacto, su propia consulta (`created_at >= now − 7 d`) todavía cuenta el mensaje, así que volvería a negar. El H9 suelta 1 s después (DF-B3).
- **O-2 (V9).** La semana de `VoiceFrequency` es `(now − 7 d, now]`, abierta por abajo. La del H9 es cerrada, como `ContactPolicy`. Cuando V9 una las dos puertas, la de voz quedará 1 s más laxa en el borde.
- **O-3 (B9-core).** El encargo de B9-core incluye `DecisionTraceText` (los textos que espeja el CRM). No hay fixture: el contrato no fija los textos. Si FE9 ha de espejarlos, hace falta uno; puede salir de las trazas de `conditions-cases.json` §evaluate.
- **O-4 (B9-facts).** `subject_ref` (`outreach-subject/1`): el contrato no fija la forma (¿64 hex completos o truncados?), así que no hay vectores.
- **O-5 (B9-run y S9).** La escalera devuelve `waitsForRelease` (N2 retiene) y `effect` (N6). D9-8 pide esas consecuencias en la etapa 6; la tabla de qué trabajo se cancela y cuál espera es de B9-run.

### 6. Lo que queda sin cubrir, y por qué

- **Lo que no es puro:**
  - esquema, CHECK, disparadores y `REVOKE` (DB9, T7);
  - carreras en PostgreSQL (#5, #13, #16, #24): un trabajo vivo, reserva atómica, idempotencia por ocurrencia, duplicados de resultados (T14);
  - el planificador y el despachador (#4, #15, #23, #25);
  - RBAC, BOLA y datos personales (T5, T6);
  - los adaptadores de hechos;
  - el E2E (#26).

  Son de B9-run, DB9, SEC9 y TEST9-b/c.
- **Errores de proveedores (#18–#20).** Fuera del 15-11 (W9/V9). La tabla los cubre como `failed`, `ambiguous` y `sent`/`delivered`/`read`.
- **Q-14.** La regla de la cartera vencida no está en la escalera hasta que el lead le dé un código.
- **Textos (`DecisionTraceText`) y `subject_ref`:** O-3 y O-4.
- **La ley:**
  - la lectura de la Ley 2300 es del `architect`, no de asesoría;
  - la Ley 2578 sigue «por confirmar» (demandada);
  - la Ley 51 de 1983 no se abrió hoy (sin red): los festivos salen de la regla y del fixture del H8.

### 7. Estado del árbol

- Solo añadí ficheros en `tests/fixtures/outreach/`, `tests/Unit/Outreach/FixturesSanityTest.php` y este informe. No toqué nada más.
- Los cambios ajenos que se ven en `git status` (H8 y B9-core/B9-facts en `app/Services/Outreach/`) no son míos y no los leí.
- Los temporales (copias estropeadas, reproducción, registro de la suite) están en el scratchpad de la sesión, fuera del repo.
