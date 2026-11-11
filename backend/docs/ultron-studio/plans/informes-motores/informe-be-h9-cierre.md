# Informe backend · cierre técnico del H9 (I-1, I-5, I-4 y el recuento de vínculos)

**11-11-2026 · backend · rama `feat/ultron-studio-h9`, árbol `_worktrees/ultron-h8`.** Sin commit ni push. No he tocado producción, el CRM ni los contratos. Las referencias son `ruta:línea` del árbol de trabajo.

Marcas: **[C]** comprobado ejecutando · **[P]** hecho de producción que da el lead · **[S]** suposición.

## Resumen para el lead

1. **I-1 (se puede desplegar sola).** En sombra, `canary_only_declared` de N3 es un aviso y la escalera sigue hasta N7; en vivo y en el asistido niega igual que antes (`PolicyLadder.php:94-97`, `:187`, `:322-345`).
   - Fixture regenerado con su herramienta (116 → 123 casos). 7 de 7 mutaciones muertas.
   - `security` lo aprobó, y su condición (0 decisiones en producción) ya está comprobada.
   - Sus dos recomendaciones están hechas:
     - **R1**: la prueba de regresión `H9ShadowNeverRunsLiveTest` (2 pruebas): un trabajo o una inscripción de sombra nunca se reclama ni se planifica en vivo. Pasa en SQLite y en PostgreSQL.
     - **R2**: el comentario de `OutreachIsolationTest.php:552-553`.
2. **I-5.** Los seis eventos sin emisor quedan con `available` y `triggerable` a `false`; siguen legibles en condiciones. El catálogo sube a `outreach-catalog/2026-11-11.1`, huella `079930a2…`.
   - Nueva guarda `OutreachCatalogEmittersTest`. 8 de 8 mutaciones muertas.
   - **Hallazgo de seguridad comercial:** `complaint_created` no detiene nada y nadie lo emite (§2).
3. **I-4, sin migración.** La posición del barrido (keyset; la de clases, compuesta) va en `counts` de la última pasada `sweep` completada, por estrategia, disparador y modo.
   - No sale ni por la API ni por la orden.
   - Una pasada que falla no la mueve.
   - 8 de 8 mutaciones muertas.
4. **Recuento.** `GET outreach/status` → `data.personBindings = {verified, persons}`:
   - `verified`: vínculos verificados y vivos;
   - `persons`: identidades **distintas** con al menos uno (dos cuentas de una misma persona → `verified` +1, `persons` igual).
   
   Es aditivo y se lee a través del puente (la guarda L9-51 sigue verde). Corregido también el comentario de `VoiceStrategyChannel`.
5. **Ficheros para el commit.** Todos son míos; ninguno está en `app/Services/Marketing/Ultron`.
   - **App:**
     - `app/Services/Outreach/Core/PolicyLadder.php` (1)
     - `app/Services/Outreach/Core/OutreachCatalog.php` (2)
     - `app/Services/Outreach/Run/TriggerPrefilters.php` (3)
     - `app/Services/Outreach/Run/OutreachRuns.php` (3)
     - `app/Services/Outreach/Run/OutreachPlanner.php` (3)
     - `app/Services/Outreach/Run/OutreachReadModels.php` (3, 4)
     - `app/Services/Outreach/Run/OutreachPublishedStrategies.php` (4)
     - `app/Services/Outreach/Run/Channels/VoiceStrategyChannel.php` (4, solo el comentario)
   - **Pruebas y fixtures:**
     - `tests/Unit/Outreach/Core/H9ShadowCanaryOnlyLadderTest.php` (nuevo, 1)
     - `tests/Feature/Outreach/Run/H9ShadowCanaryOnlyTest.php` (nuevo, 1)
     - `tests/Feature/Outreach/Run/H9ShadowNeverRunsLiveTest.php` (nuevo, R1)
     - `tests/Feature/UltronStudio/OutreachIsolationTest.php` (1 y R2)
     - `tests/fixtures/outreach/tools/build/sec_ladder.py` (1)
     - `tests/fixtures/outreach/policy-ladder-cases.json` (1)
     - `tests/fixtures/outreach/README.md` (1 y 2)
     - `tests/fixtures/outreach/tools/build/sec_catalog.py` (2)
     - `tests/fixtures/outreach/tools/build/sec_strategy.py` (2)
     - `tests/fixtures/outreach/catalog-snapshot.json` (2)
     - `tests/fixtures/outreach/conditions-cases.json` (2)
     - `tests/fixtures/outreach/strategy-content-cases.json` (2)
     - `tests/Unit/Outreach/Core/fixtures/trace-text-cases.json` (2)
     - `tests/Unit/Outreach/Core/OutreachCatalogTest.php` (2)
     - `tests/Unit/Outreach/Core/OutreachCatalogEmittersTest.php` (nuevo, 2)
     - `tests/Feature/Outreach/Run/H9SweepCursorTest.php` (nuevo, 3)
     - `tests/Feature/Outreach/Run/OutreachApiTest.php` (3 y 4)
   - **Informe:** `docs/ultron-studio/plans/informes-motores/informe-be-h9-cierre.md`.
   - Los 11 JSON salen byte a byte de `build.py`.
6. **Pruebas en SQLite [C]:**
   - `tests/Unit/Outreach`: OK, 980 pruebas / 52 910 aserciones.
   - `tests/Feature/Outreach` completa: OK, 628 pruebas, 164 omitidas por diseño (solo PostgreSQL, o la copia de V9 abierta). La pasé antes de R1 y de `persons`.
   - Después de esos dos añadidos volví a pasar `tests/Feature/Outreach/Run`: OK, 85 pruebas, 2 omitidas por diseño.
   - Strategies del Studio: OK, 43 pruebas.
7. **Pruebas en PostgreSQL [C]** (`ultron_studio_h9fix_unit`, clúster desechable):
   - `tests/Feature/Outreach/Run`, con R1 y `persons`: OK, 85 pruebas / 1 395 aserciones;
   - Strategies del Studio: OK, 43 pruebas / 727 aserciones;
   - `OutreachIsolationTest`: 5 pruebas, la misma única roja ajena.
8. **Guardas (15 ficheros): 138 pruebas, 1 roja ajena.** Es `OutreachIsolationTest::test_la_puerta_de_diff…`, que ve los cambios sin commit del otro agente en `app/Services/Marketing/Ultron/`: `Prompts/UltronPromptCompiler.php`, `Prompts/UltronPromptException.php`, `Prompts/UltronPromptsN8nFlow.php` y `UltronPublishedConfig.php`.
   - **Confirmado: mis cambios no tocan esa guarda** (ninguno está en `DIFF_GATE`). No la he relajado.
   - Las otras 137 pasan, entre ellas la lista cerrada de lectores del Studio.
9. **Lo que tiene que hacer el CRM:**
   - ejecutar `node src/app/modules/commercial-automation/testing/sync-h9-fixtures.mjs <backend>`;
   - en `h9-fixtures.spec.ts:18,23,27` y `commercial-automation-api.service.spec.ts:90`, la versión `outreach-catalog/2026-11-11.1` y la huella `079930a2a391f318ee7812a9b6e6fcce893458987ecbf5a14ec37d0232a86e11` (`dictionaryHash` no cambia);
   - leer `status.personBindings.{verified, persons}` para el mensaje «hacen falta dos personas vinculadas (hay N)», usando `persons`.
   
   El parser actual no se rompe: ignora la clave nueva. No hay escalera en TypeScript.
10. **Despliegue de la tarea 1 sola:** `tests/fixtures/outreach/README.md` mezcla las tareas 1 y 2. Con `git add -p`; los números están en la §1.
11. **No verificado:** nada en producción ni en el CRM, la batería completa del backend ni el volumen real del barrido.
12. Sin commit ni push.

---

## 1 · I-1: en sombra, «solo canario» avisa en lugar de negar — **SE PUEDE DESPLEGAR SOLA**

### Qué cambia (y nada más)

`app/Services/Outreach/Core/PolicyLadder.php`:
- `:36-39` · la cabecera de la clase dice que, en sombra, `canary_only_declared` es aviso.
- `:94-97` · `SHADOW_WARNINGS` gana `canary_only_declared`, en orden alfabético, igual que `shadowWarnings` del fixture.
- `:187` · N3 recibe `$sombra`: `self::n3($in, $sombra)`. `$sombra` es exactamente `flags.mode === 'shadow'` (`:171-173`). Un modo desconocido es `off` y lo niega N2 `mode_off`, como antes.
- `:322`, `:326` · `n3(array $in, bool $sombra)`, con su lista `$avisos`.
- `:340-342` · la condición no cambia: `canaryOnly && inCanaryScope !== true`. En sombra va a `$avisos`; si no, a `$motivos`. Sigue el mismo patrón que N5 con `canary_scope_mismatch` (`:437`) y N2 con `legacy_autonomy_on` (`:309`).
- `:345` · N3 devuelve sus avisos.

Lo que **no** cambia:
- **Fuera de la sombra.** En `live` (el asistido es `live`) y en `off`, la escalera niega exactamente como antes: `deny N3 [canary_only_declared]`, sin `retryAt` y sin avisos. Con `off`, decide antes N2 `mode_off`.
- **El resto de la escalera.** Siguen igual el orden de motivos, `TEMPORAL`, los demás motivos de N3 (`studio_reference_invalid`, `catalog_incompatible`, `payment_links_disabled` y `template_violates_restriction` siguen negando en sombra) y la precedencia: N1 y N2 deciden antes, así que N3 ni se evalúa y no hay aviso.
- **En sombra, N4–N7 se evalúan de verdad.** Pueden negar o aplazar con el aviso de N3 delante.

### El fixture (con la herramienta del repositorio)

- **Generador** `tests/fixtures/outreach/tools/build/sec_ladder.py`:
  - `:4` · la cabecera nombra N3 y N5.
  - `:28-29` · `SHADOW_WARN` gana `canary_only_declared`. La referencia propia (`ladder_ref`) ya filtraba los avisos de sombra en cualquier nivel, así que no cambia.
  - `:327` · DF-L7 recoge el canario de N3 y N5 en sombra (D9-11.6 e I-1).
  - `contractSections` nombra D9-11.6.
  - **Casos nuevos** (7):
    - 1 en `n3`, `:447-449`: el asistido de voz con el alcance canario desconocido (`null`) niega en vivo.
    - 6 en `shadow`, `:613-632`:
      - «solo canario» en la parte canaria → `allow` con los avisos N3 y N5;
      - la plantilla de WhatsApp con el alcance desconocido → `allow` con el aviso de N3 y los de N4;
      - la persona canaria → sin aviso;
      - N1 sigue negando antes;
      - N2 sigue reteniendo antes;
      - la referencia del Studio que no cuadra sigue negando N3, sin el canario entre los motivos.
  - **El caso de `:610`** («en sombra el canario no limita…») lo he revisado y se queda igual. Prueba N5 por separado, con `canaryOnly = false`. El caso realista, el de cualquier versión del Studio, que lleva `canaryOnly = true`, es el nuevo de `:613`.
- **Regenerado** `tests/fixtures/outreach/policy-ladder-cases.json` con `python3 -I tests/fixtures/outreach/tools/build/build.py` **[C]**:
  - Antes de tocar nada comprobé que el generador reproduce byte a byte los 11 JSON del árbol.
  - Después solo cambia `policy-ladder-cases.json`: 116 → 123 casos, `n3` 7 → 8, `shadow` 7 → 13. Los otros 10 ficheros salen idénticos, `channel-selection-cases.json` incluido.
  - Nueva huella: sha256 `7ece0bf94f7c5b30cffd0125b5bb0d5820c9b84fbfe7206e34105e9bcef83490`, 544 001 bytes. Dos ejecuciones dan los mismos bytes.
- **`tests/fixtures/outreach/README.md`:**
  - Corregidos la fila del índice (123), el total (810) y la nota de DF-L, y añadida la nota de regeneración.
  - **De paso:** el recuento «por base» del índice ya no cuadraba **antes** de este cambio. Decía `decision-fixture` 379 y `supuesto-lead` 22; los ficheros de `HEAD` dan 378 y 23 **[C]**. Ahora dice lo que dan los ficheros.

### Las pruebas (sobre las clases reales)

- **`tests/Unit/Outreach/Core/H9ShadowCanaryOnlyLadderTest.php`** (nuevo, 5 pruebas):
  - **En sombra**, en los cuatro canales y con el alcance `false` o `null`:
    - sale `allow`, los 7 niveles en `pass` y el aviso de N3 el primero;
    - con la plantilla de WhatsApp en la parte canaria, los avisos exactos son N3, N4 `sends_disabled_in_code`, N4 `budget_zero` y N5.
  - **En vivo**, la salida **entera** es la de antes, en los cuatro canales; `off` lo niega en N2.
  - **Sin aviso:** la persona canaria, y la versión sin «solo canario».
  - **Precedencia:**
    - N1 y N2 deciden antes, sin aviso;
    - los otros cuatro motivos de N3 siguen negando en sombra;
    - N4, N5, N6 y N7 siguen negando o aplazando con el aviso delante.
  - **Constantes:** está en `SHADOW_WARNINGS` y en el orden de N3, y no es temporal.
- **`tests/Feature/Outreach/Run/H9ShadowCanaryOnlyTest.php`** (nuevo, 4 pruebas; planificador, despacho y `replay` reales, con la cadena firmada de `RunWorld` y la línea base del Studio, que declara «solo canario»):
  - **En sombra**, un socio que califica pero no es canario:
    - el plan da `allow` con los avisos N3 y N5, los 7 niveles en `pass` y el trabajo `simulated`;
    - el despacho repite la escalera y lo simula (`claimed 1`, `simulated 1`);
    - `OutreachReplay` da `same` con la misma huella.
  - **En vivo (asistido):** `deny N3 [canary_only_declared]`, ningún trabajo y su `blocked` con el motivo.
  - **En sombra, la persona canaria:** sin aviso.
  - **En sombra, sin autorización:** decide N1 y no hay aviso.
- **`tests/Feature/UltronStudio/OutreachIsolationTest.php:570-575`.** Esta prueba **no es de mi territorio, pero no queda otra**: daba por hecho el comportamiento viejo.
  - Antes: en sombra, N3 niega al socio 0.
  - Ahora, en sombra, N3 solo avisa y al socio 0 lo niega su cartera vencida en **N6** (`collections_overdue_blocks_commercial`). En vivo sigue siendo N3.
  - La aserción sigue siendo exacta en cada modo: nivel, motivos y avisos (N3 y N5 en sombra, ninguno en vivo). No es una relajación.
  - Los recuentos del recorrido (`[3, 1, 2]`, el despacho y la bandeja) no cambian.

**Resultados [C]** (`nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`, uno cada vez):

| Batería | Motor | Resultado |
|---|---|---|
| `tests/Unit/Outreach` (paridad, `FixturesSanityTest`, textos de la traza, prueba nueva) | — (puro) | **OK, 976 pruebas, 52 852 aserciones** |
| `tests/Feature/Outreach` (Run, Facts, W9, V9, B9Cierre, Schema, Guards) | SQLite | **OK, 618 pruebas, 39 774 aserciones, 164 omitidas**. Todas las omisiones son de diseño: «Solo PostgreSQL» (CHECK, carreras, roles) o la copia con V9 abierta |
| `tests/Feature/Outreach/Run` | PostgreSQL 14 (`ultron_studio_h9fix_unit`, creada en el clúster desechable) | **OK, 73 pruebas, 1 290 aserciones** |
| `H9ShadowCanaryOnlyTest` | PostgreSQL | **OK, 4 pruebas, 28 aserciones** |
| Simulación, ciclo de vida y validación de estrategias (`tests/Feature/UltronStudio/Strategies/…{Simulation,Lifecycle,Validation}Test`) | SQLite | **OK, 17 pruebas, 202 aserciones** |
| Guardas estáticas (la orden del encargo, 15 ficheros) | SQLite | **138 pruebas, 1 fallo, que no es de I-1** (abajo) |
| `OutreachIsolationTest` | PostgreSQL | 5 pruebas, el mismo único fallo |

**El fallo de las guardas no es mío.** Es `OutreachIsolationTest::test_la_puerta_de_diff_de_legacy_n8n_otp_y_motor_comercial_esta_vacia…`.
- La puerta de diff frente a `origin/main` ve tres ficheros de la zona protegida `app/Services/Marketing/Ultron`: `Prompts/UltronPromptCompiler.php`, `Prompts/UltronPromptException.php` y `UltronPublishedConfig.php`.
- Son los cambios **sin commit** del agente de prompts y publicaciones (`git diff --stat -- app/Services/Marketing/Ultron`: 3 ficheros, +251 −39).
- Ninguno de mis ficheros está en `DIFF_GATE`.
- Las otras 137 pruebas de las guardas pasan, también la parte de `OutreachIsolationTest` que recorre la ejecución en sombra y en vivo.
- **Lo tiene que decidir el lead:** esa guarda va a seguir roja mientras esos cambios toquen ULTRON Legacy.

**Mutaciones [C]:**
- Se hicieron sobre un clon APFS del backend fuera de cualquier árbol de git (`scratchpad/mut/backend`). Cada mutante pasa `php -l` y, al final, se restaura el fichero y se comprueba su sha256.
- Las 7 mueren con la prueba unitaria nueva y también con la de paridad.

| Mutante | Prueba unitaria nueva | Paridad del fixture | Integración (planificador) |
|---|---|---|---|
| M1 · en sombra vuelve a negar (lo de antes) | muerto | muerto | muerto |
| M2 · avisa también en vivo | muerto | muerto | muerto |
| M3 · `inCanaryScope === false` (el desconocido pasaría por canario) | muerto | muerto | vivo (allí el alcance es `false`) |
| M4 · ignora `canaryOnly` | muerto | muerto | vivo (la línea base siempre lo declara) |
| M5 · `n3($in, false)` | muerto | muerto | muerto |
| M6 · fuera de `SHADOW_WARNINGS` | muerto | muerto | vivo (constante) |
| M7 · el aviso se pierde (N3 devuelve `warnings: []`) | muerto | muerto | muerto |

`vendor/bin/pint --test` sobre los cuatro PHP tocados: `passed`.

### Quién más repite la escalera en sombra, y qué cambia en cada uno

- **`OutreachPlanner::plan()`** (`OutreachPlanner.php:466`; etapa 4). En sombra, quien no es canario ya no se niega en N3 y llega a N4–N7.
  - Si pasa, se planifica un trabajo `simulated` (canal simulado, sin reserva) con los avisos en la traza.
  - Efecto previsible: en sombra hay más `planned`/`simulated` y menos `deny N3`.
  - **Ojo [S]:** `strategy.usedToday` cuenta los trabajos de sombra (`LadderInputBuilder.php:124`, `strategyJobsOn(…, $mode, …)`). El tope diario de la estrategia (`contactsPerDay`, N5 `strategy_daily_cap`) se gastará ahora con toda la audiencia. Es justo lo que haría en vivo sin canario, que es lo que la sombra quiere medir. El volumen está sin medir.
- **`OutreachDispatcher::process()`** (`OutreachDispatcher.php:309`; etapa 6). Repite la escalera con el **modo del trabajo**: en sombra, la misma regla, así que el trabajo se simula.
  - **Un trabajo de sombra no puede acabar en un contacto en vivo.** El despacho solo reclama trabajos del modo actual (`:174`, `j.mode = $modo`). Al procesar, si el modo cambió, lo retiene en N2 (`:224-225`). Las inscripciones y las ocurrencias son por modo.
  - En vivo, la inscripción nueva pasa por N3 y lo niega.
- **`StudioStrategySimulator::ladderInput()`** (`StudioStrategySimulator.php:161`, con `mode` = `shadow` en `:227`; simulaciones `synthetic` y `sample`, siempre en `shadow`). Quien no es canario ya no sale `deny N3`: se ve lo que harían N4–N7 con la audiencia entera (D9-12).
  - Los hallazgos **bloqueantes** no cambian: «todos negados por consentimiento» mira N1, que va antes.
  - El aviso `shadow_warnings` aparecerá casi siempre, y su texto (`:82`, «En vivo, el canal o el presupuesto negarían el contacto…») no nombra el canario. **Lo tiene que hacer el dueño de `UltronStudio`**, no yo: ampliar ese texto («…o el alcance canario…»).
- **`OutreachReplay`** (`outreach:replay`). Reevalúa con las entradas guardadas y el núcleo de ahora. Una decisión de sombra **anterior** a este cambio, de una persona no canaria con «solo canario», daría `different`.
  - **Supuesto [P], que no he comprobado yo:** en producción hay 0 estrategias y 0 decisiones (`outreach_decisions` vacía, auditoría B §4.1 y el lead). No hay historia que contradecir.
  - Hay que **comprobarlo antes de desplegar**: `SELECT count(*) FROM outreach_decisions;` tiene que dar 0. Si no, las decisiones de sombra con `canary_only_declared` dejarían de reproducirse.
  - Las decisiones en vivo no cambian.

### Qué tiene que hacer el CRM (no lo he hecho)

- **No hay escalera en TypeScript.** En `5f2a454` el CRM solo **copia** `policy-ladder-cases.json` y comprueba sus bytes (`h9-fixtures.spec.ts`; el comentario de `:22` dice que la escalera no declara catálogo). El texto `canary_only_declared` ya existe en `commercial-automation-trace-text.ts:107`, y el aviso se pinta como «Aviso: …».
- **El paso:** con el backend de esta rama al lado, `node src/app/modules/commercial-automation/testing/sync-h9-fixtures.mjs <ruta-al-backend>`.
  - Reescribe `testing/fixtures/policy-ladder-cases.json` y `manifest.json`: sha256 `7ece0bf9…3490`, 544 001 bytes, `counts.total` 123.
  - Los otros 5 ficheros salen iguales (`trace-text-cases.json` no cambia: `dictionaryHash` `e83bce27…`).
  - Después, `h9-fixtures.spec.ts` con `IRON_BODY_BACKEND_DIR`.

### Ficheros de la tarea 1 (para desplegarla sola)

- App:
  - `app/Services/Outreach/Core/PolicyLadder.php`
- Pruebas y fixture:
  - `tests/fixtures/outreach/tools/build/sec_ladder.py`
  - `tests/fixtures/outreach/policy-ladder-cases.json`
  - `tests/fixtures/outreach/README.md`. **Ojo: este fichero tiene cambios de las tareas 1 y 2.** Para llevar la 1 sola hay que añadir solo sus partes (`git add -p`), porque `FixturesSanityTest` comprueba que el README cuadre con los ficheros. Los números de la tarea 1 sola son:
    - la fila de `policy-ladder-cases.json` con `n3` 8, `shadow` 13 y 123;
    - «**Total: 810 casos.** Por base: `contrato` 383, `decision-fixture` 378, `supuesto-lead` 23, `ley` 21, `codigo-existente` 5»;
    - las filas de `catalog-snapshot.json` (21) y `strategy-content-cases.json` (58), sin tocar;
    - la nota de DF-L7 y la «Regeneración del 11-11-2026 (I-1…)».
    
    La nota de I-5 y la frase «la que fijaba … hasta el 11-11» son de la tarea 2.
  - `tests/Unit/Outreach/Core/H9ShadowCanaryOnlyLadderTest.php` (nuevo)
  - `tests/Feature/Outreach/Run/H9ShadowCanaryOnlyTest.php` (nuevo)
  - `tests/Feature/UltronStudio/OutreachIsolationTest.php` (solo `:570-575`)
- Sin migraciones, sin variables de entorno y sin cambio de contrato HTTP.
- Operación: ningún mando ni smoke de `docs/ultron-studio/ops/` depende de N3. Lo busqué en `smoke-h9.py`, `h9-e2e/` (todas sus personas son canarias), `banco-h9/` y los `deploy-h9-*`.

### Para `security` (cambio en la escalera de permisos)

- Las líneas exactas están en «Qué cambia».
- **El razonamiento:** en sombra no sale nada hacia una persona:
  - el canal del trabajo es `simulated`;
  - no hay reserva;
  - el despacho filtra por modo.
  - Pasar de `deny` a aviso no abre ningún contacto; solo hace que la sombra mida la audiencia entera.
- **Casos a revisar** (los fijan la prueba unitaria nueva, el fixture y las mutaciones):
  1. vivo y asistido niegan igual que antes, con la salida entera;
  2. un `mode` desconocido sigue siendo `off`, que niega N2;
  3. alcance `null` = fuera (M3);
  4. sin `canaryOnly` no hay aviso (M4);
  5. N1 y N2 deciden antes;
  6. los otros motivos de N3 siguen negando en sombra;
  7. un trabajo de sombra nunca se despacha en vivo (`OutreachDispatcher.php:174`, `:224-225`).

### Hallazgos adyacentes (no los he tocado)

- **`OutreachReplay::replay()['ladder']` siempre da `false` por canal** (`OutreachReplay.php:110`). Compara con `===` la salida recién calculada con la guardada. La guardada sale de `CanonicalJson` con las claves ordenadas, así que el orden nunca coincide. Lo comprobé con `CanonicalJson::encode(['verdict'=>…,'allowed'=>…])` y `json_decode(...) === $o` → `false`.
  - El veredicto (`result`) no se ve afectado: compara la huella.
  - Pero la orden `outreach:replay`, cuando sale `DISTINTA`, diría «distinta» en todos los canales (`OutreachReplayCommand.php:58`).
  - Arreglo pequeño: comparar las salidas por `CanonicalJson::hash`.
- **La referencia del fixture y PHP no coinciden con `legacy_autonomy_on` en modo `off`.** La referencia lo quita de los motivos (`sec_ladder.py`, rama `elif lv == "N2" …`); PHP lo pone (`PolicyLadder.php:309`). Ningún caso lo ejercita: en `off` decide N2 `mode_off` igual, pero la lista de motivos sería distinta. Habría que decidir cuál vale.

---

## 2 · I-5: el constructor ofrecía disparadores que nadie emite

### Comprobación, uno por uno [C]

- **La puerta.** La única escritura de `commercial_events` en `app/` es `CommercialEventRecorder::record()` (`app/Services/Commercial/CommercialEventRecorder.php:112`).
  - Ningún otro sitio hace `CommercialEvent::create`, `insert` ni `DB::table('commercial_events')->insert…`. `SupervisionController.php:180`, `InboxContextService` y `CampaignAnalyticsService` solo leen.
  - Además, el registrador descarta todo evento que no esté en el vocabulario (`:78-84`).
- **Quién usa esa puerta.** Ocho observadores de `app/Observers/Commercial/` y `app/Console/Commands/ScanCommercialFacts.php` (`commercial:scan`). Las constantes `EV_*` que nombra cada uno:

| Emisor | Eventos |
|---|---|
| `AttendanceCommercialObserver` | `first_checkin`, `attendance_milestone` |
| `ElectronicInvoiceCommercialObserver` | `invoice_requested`, `invoice_validated`, `invoice_rejected` |
| `MarketingAppointmentCommercialObserver` | `appointment_created`, `appointment_completed` |
| `MarketingConversationCommercialObserver` | `human_requested` |
| `MarketingLeadCommercialObserver` | `lead_created`, `lead_qualified`, `human_requested` |
| `MemberCommercialObserver` | `app_linked` |
| `MembershipCommercialObserver` | `membership_activated`, `membership_renewed` |
| `PaymentTransactionCommercialObserver` | `payment_link_created`, `payment_approved`, `payment_pending`, `payment_failed`, `payment_expired` |
| `ScanCommercialFacts` | `inactivity_detected`, `renewal_window_opened`, `membership_expired`, `payment_pending` |

  Son 21 de 27, lo mismo que dice la auditoría.
- **n8n.** Los flujos (`n8n/workflows/*.json`, `n8n/flujos/**`) llaman a `api/internal/marketing/{ai-action, context, followups, human-takeover, send-message, ai/decide, ai/commit}` y a `internal/automation/wellness-run`. Ninguna de esas rutas registra un evento comercial por nombre.
  - `human-takeover` solo produce `human_requested`, a través del observador de la conversación.
  - Las «objeciones» de n8n se guardan en `marketing_ai_actions.metadata.objection` (`InternalMarketingController.php:129-143`), no en `commercial_events`.
- **Sin emisor, los seis:** `customer_satisfied`, `objection_raised`, `offer_presented`, `offer_rejected`, `referral_opportunity` y `complaint_created`.
  - `EV_OBJECTION_RAISED` solo aparece como etiqueta (`SupervisionController.php:614`) y en una lectura de analítica (`CampaignAnalyticsService.php:537`).

### Qué cambia

`app/Services/Outreach/Core/OutreachCatalog.php`:
- `:44-49` · `VERSION` = `outreach-catalog/2026-11-11.1` (antes `2026-11-10.2`).
- `:188-202` · `EVENTS_WITHOUT_EMITTER`, los seis, con el porqué.
- `:482`, `:486` · esos seis salen con `available: false` **y** `triggerable: false`. Conservan `purposes` y `conditionUsable`.

**Por qué también `triggerable: false`:**
- `FixturesSanityTest.php:244-246` fija una invariante del fixture: «un disparador tiene que estar disponible». Solo `available: false` la viola.
- No la he relajado. He seguido el precedente que ya existía: los tres `integration.*` van con los dos flags a `false`.
- Para el constructor y el validador el resultado es el mismo:
  - el CRM filtra `triggerable && available` (`commercial-automation-catalog.ts:232`);
  - `StrategyContent.php:245` rechaza con `trigger_not_allowed` si falta cualquiera de los dos.
- `compatibility()` no mira ninguno de los dos. Una versión hecha con uno de esos disparadores sigue «compatible» (caso nuevo del fixture). Hoy no hay ninguna [P].

**Huella:**
- Antes: `ce5fcdab…a13039`.
- Ahora: `079930a2a391f318ee7812a9b6e6fcce893458987ecbf5a14ec37d0232a86e11`, 28 358 bytes canónicos.
- Solo cambian esos seis eventos: el resto del catálogo es idéntico (comprobado al comparar las dos exportaciones).

### Fixtures y lo que cuelga del catálogo (con las herramientas del repositorio)

- **Generador:**
  - `sec_catalog.py:9` · `CATALOG_VERSION`.
  - `:92` · `NO_EMITTER`.
  - `:97-99` · los dos flags.
  - Las notas de los seis en `annotations` (fuera de la huella).
  - `:345` · DF-K4 recoge la disponibilidad.
  - `:400-402` · un caso de `compatibility`: `available` no cuenta.
- **`sec_strategy.py:504`** · un caso `invalid`: el disparador `customer_satisfied` → `trigger_not_allowed`.
- **`build.py`** reescribe 3 ficheros y las otras 8 salen idénticas (reproducible: dos ejecuciones dan los mismos bytes):

| Fichero | Bytes | sha256 (inicio) | Qué cambia |
|---|---|---|---|
| `catalog-snapshot.json` | 89 075 | `c397b1c2…` | `catalogVersion`, `catalogHash`, los seis eventos, DF-K4, las anotaciones; `compatibility` 6 → 7 |
| `conditions-cases.json` | 414 324 | `4e2b63d5…` | solo `catalogVersion` y `catalogHash` de la cabecera |
| `strategy-content-cases.json` | 606 197 | `ae0eae27…` | la cabecera, DF-K4; `invalid` 40 → 41 |

- **`tests/Unit/Outreach/Core/fixtures/trace-text-cases.json`**, exportado con `php tests/Unit/Outreach/Core/tools/export-trace-text-cases.php`: cambia solo `catalogVersion`. Huella `c8695df6…`, 46 927 bytes. El `dictionaryHash` **no cambia**: `e83bce27…`.
- **`tests/fixtures/outreach/README.md`:** índice (22 y 59), total 812, recuento por base (`decision-fixture` 380) y la nota de regeneración.
- **Pruebas:**
  - `tests/Unit/Outreach/Core/OutreachCatalogTest.php:29-33`: la huella nueva en `HUELLAS`.
  - `:65-80`: el conjunto exacto de no disparables (2 → 7) y de no disponibles (3 de integración + 6). Además, 20 + 8 disparadores ofrecidos, y los seis siguen legibles en condiciones.
- **Nueva: `tests/Unit/Outreach/Core/OutreachCatalogEmittersTest.php`** (2 pruebas, estáticas y sin base). Ata `available` a la realidad:
  1. solo `CommercialEventRecorder` escribe `commercial_events`;
  2. la lista de quienes lo usan es cerrada (los 9 de arriba);
  3. un evento del vocabulario está disponible ⇔ uno de esos emisores nombra su `EV_*` en el código (sin comentarios).
  
  Si alguien le da emisor a uno de los seis, esta prueba obliga a abrirlo en el catálogo y subir la versión.

### Qué fijaba la versión fuera del código (lo busqué antes de subirla)

| Dónde | Qué fija | Qué hice |
|---|---|---|
| `tests/Unit/Outreach/Core/OutreachCatalogTest.php:29-33` | versión → huella | añadida la nueva |
| `tests/Unit/Outreach/Core/LoadsOutreachFixtures.php:19,28` | `L9_29.toVersion = 2026-11-10.2` (solo para fixtures de antes de L9-29) | nada: con la versión nueva la rama no se activa |
| `tests/fixtures/outreach/{catalog-snapshot,conditions-cases,strategy-content-cases}.json` y `tests/Unit/Outreach/Core/fixtures/trace-text-cases.json` | `catalogVersion` y `catalogHash` | regenerados con sus herramientas |
| `tests/fixtures/outreach/README.md` | versión y huella de L9-29 | nota nueva |
| Mandos y smokes (`docs/ultron-studio/ops/*.sh`, `smoke-h9.py`, `h9-e2e/`, `banco-h9/`, `deploy-h9-*`) | **nada** | — (el e2e usa disparadores que siguen disponibles) |
| Evidencias (`docs/ultron-studio/ops/evidencia-h9/2026-11-11/e2e/*`, 18 ficheros) | la versión vieja en las filas copiadas | nada: son evidencia histórica |
| Documentos: `ULTRON_STUDIO_H9_CONTRACT.md`, `ULTRON_STUDIO_AUDITORIA_9_HITOS.md`, `ULTRON_STUDIO_H9_ACEPTACION.md`, `ULTRON_STUDIO_H9_COMPLETITUD.md` y `plans/informes-h9/informe-{backend,db,frontend,tester}.md` | citan `2026-11-10.2` y/o `ce5fcdab…` | **no los toco** (contratos e informes cerrados): que los actualice documentación si el lead quiere |
| **El CRM** (en `5f2a454`) | ver abajo | no lo toco |

En ejecución nada compara la versión guardada con la vigente: la compatibilidad va por hechos y eventos (`OutreachCatalog.php:338-361`). En producción no hay versiones de estrategia [P].

### Qué tiene que hacer el CRM (no lo he hecho)

1. `node src/app/modules/commercial-automation/testing/sync-h9-fixtures.mjs <ruta-al-backend>`. Copia `catalog-snapshot.json`, `conditions-cases.json`, `strategy-content-cases.json` y `trace-text-cases.json` (y `policy-ladder-cases.json`, de la tarea 1) y reescribe `manifest.json`.
2. `src/app/modules/commercial-automation/h9-fixtures.spec.ts`:
   - `:18` y `:23`: `'outreach-catalog/2026-11-10.2'` → `'outreach-catalog/2026-11-11.1'`;
   - `:27`: `catalogHash` → `'079930a2a391f318ee7812a9b6e6fcce893458987ecbf5a14ec37d0232a86e11'`;
   - `:24-25` (`dictionaryHash`) se queda igual.
3. `src/app/modules/commercial-automation/commercial-automation-api.service.spec.ts:90`. `catalogoDto()` lee la copia del fixture, así que `'outreach-catalog/2026-11-10.2'` → `'outreach-catalog/2026-11-11.1'`.
   - Las demás menciones de `2026-11-10.2` son datos de ejemplo fijos que no leen el fixture y no rompen, pero quedan viejos: `:307`, `testing/ca-examples.testing.ts:139,154,608` y `e2e/h9-ui/capture-h9.mjs:58,114,142`.
4. Nada en el constructor: `triggerableEvents()` ya filtra `triggerable && available`, así que los cinco dejan de salir solos. Ningún spec cuenta los disparadores ofrecidos (lo busqué).

### `complaint_created`: para qué se usa, y el hallazgo de seguridad comercial

- **Lo que es hoy.** Un evento del vocabulario que no es disparador (DF-K4) y solo se puede **leer en condiciones** (audiencia, exclusiones, éxito, guardas).
  - **No detiene nada:** ningún nivel de la escalera lo mira. Lo que sí detiene es `human_requested`: N6 lo mira con `humanRequestedRecently`, `LadderInputBuilder.php:152-165`.
  - Como nadie lo emite, una condición sobre él vale siempre «no ocurrió».
- **El riesgo (para `security` y para el propietario).** Una autora puede escribir la exclusión «se quejó en los últimos 30 días» (`complaint_created exists within 30d`). El validador la acepta, la simulación no avisa y **nunca excluye a nadie**: una falsa sensación de seguridad.
  - Lo mismo pasa con `objection_raised` y `offer_rejected` en exclusiones.
  - Con este cambio dejan de ser disparadores, pero **siguen ofreciéndose en condiciones**. El CRM filtra las condiciones solo por `conditionUsable` (`commercial-automation-catalog.ts:237`).
  - **No lo he cambiado:** no estaba en el encargo y cambia la gramática de condiciones. Propuesta: que el validador dé un **aviso** (o un bloqueante en exclusiones) cuando una condición nombre un evento con `available: false`. Lo decide el lead.
- **Dónde se registran hoy las quejas [C]:**
  1. **WhatsApp con el agente de ventas o ULTRON.** La intención `complaint` (`SalesIntents.php:61`), o palabras como «queja», «reclamo» o «demanda» (`SalesEscalationService.php:17-24`, `:50-53`), marcan la conversación:
     - `marketing_conversations.staff_review_pending = true`, `staff_review_reason = 'complaint'` (`SalesAgentOrchestratorService.php:231-236`);
     - una fila de `marketing_ai_actions` (`action_type = staff_review`, `:375-395`).
     
     ULTRON también lo afirma desde el backend (`UltronController.php:248-256`). La IA no se apaga.
  2. **El H9 sí lo ve, pero de otra forma.** `LeadFacts.php:217` lee `staff_review_pending` (o `human_takeover`) de una conversación no cerrada como `conversation.human_in_control = true`. Eso hace que N2 niegue `human_in_control` y el trabajo **espere**. Así que una queja dentro de una conversación abierta de WhatsApp **sí para** la secuencia de esa persona hoy.
  3. **Lo que no queda cubierto (el hallazgo):**
     - (a) cuando el personal resuelve la revisión o la conversación se cierra, el freno desaparece al instante: no hay memoria de «se quejó hace 3 días»;
     - (b) una queja por otro medio no queda en ningún sitio que lea el H9: presencial, por teléfono, por la voz del H8 o un socio sin prospecto ni conversación;
     - (c) cualquier exclusión basada en `complaint_created` es papel mojado (arriba).
     
     No he inventado ningún emisor. La decisión (un emisor de `complaint_created` desde la marca de revisión, un periodo de enfriamiento tras una queja, o un registro de quejas presenciales) es de producto, del lead y del propietario, y de `security`.

### Pruebas [C]

| Batería | Motor | Resultado |
|---|---|---|
| `tests/Unit/Outreach` | puro | **OK, 980 pruebas, 52 910 aserciones** |
| `tests/Feature/Outreach` | SQLite | **OK, 618 pruebas, 39 783 aserciones, 164 omitidas** (las de diseño) |
| `tests/Feature/UltronStudio/Strategies` (catálogo por la API, validador, simulación) | SQLite / PostgreSQL | **OK, 43 / 727** en PostgreSQL; **43 / 733** en SQLite |
| `MetaTemplateContentTest` (lee el catálogo) | SQLite | **OK, 16 pruebas, 393 aserciones** |
| Guardas estáticas (15 ficheros) | SQLite | 138 pruebas, **1 fallo, el mismo de la tarea 1** (la puerta de diff que rompen los cambios de prompts de otro agente) |

**Mutaciones** (clon aislado; cada mutante se restaura y se comprueba su sha256): **8 de 8 muertas** con `OutreachCatalogEmittersTest` + `OutreachCatalogTest`.

| Mutante | Lo mata |
|---|---|
| M1 · reabre `customer_satisfied` | las dos |
| M2 · cierra `lead_created`, que tiene emisor | las dos |
| M3 · solo `available` (deja `triggerable`) | `OutreachCatalogTest` (export frente al fixture) |
| M4 · solo `triggerable` (deja `available`) | las dos |
| M5 · cambia los datos sin subir la versión | `OutreachCatalogTest` (versión ↔ huella) |
| M6 · un observador emite otro evento (`EV_APP_LINKED` → `EV_CUSTOMER_SATISFIED`) | la de emisores |
| M7 · un emisor nuevo fuera de la lista | la de emisores |
| M8 · una escritura directa en `commercial_events` | la de emisores |

`pint --test` sobre los tres PHP tocados: `passed`.

### Hallazgo adyacente

- **`CampaignAnalyticsService.php:536-537`** cuenta `objection_raised` en `commercial_events`: esa métrica vale siempre 0. No lo toco.

---

## 3 · I-4: el barrido recorre la audiencia entera, sin cambiar el esquema

### Por qué no `outreach_cursors`

- `last_id` solo puede crecer: lo impone el disparador `ocur_last_id_forward` (`database/migrations/2026_11_12_100002_create_outreach_tables.php:661`).
- Las filas no se borran ni se truncan (`:659`, `:378`).
- «Volver a empezar» exigiría bajar `last_id`, que es justo lo que la base prohíbe y no voy a forzar, o crear una fila nueva por cada vuelta, que crecería sin fin.
- La posición de las clases es compuesta (fecha, clase y persona) y no cabe en un entero.
- **No hace falta migración**, así que `database-specialist` no entra.

### Diseño (con lo que ya guarda `outreach_runs`)

- **Dónde vive la posición.** En los recuentos (`counts`, JSON) de la **última pasada `sweep` completada** de esa estrategia, disparador y modo:
  - `cursorFrom`: desde dónde empezó la pasada;
  - `cursorNext`: desde dónde empieza la siguiente. `''` significa que se acabó la vuelta y la siguiente empieza otra.
  
  Las pasadas solo crecen y se cierran una vez (`orun_closed_is_final`), así que la historia de posiciones queda auditable.
- **Las posiciones son keyset y las produce `TriggerPrefilters::pageFrom()`:**
  - en los 7 disparadores por id, el último id de la página, el mismo por el que cada adaptador filtra con `> after` y ordena;
  - en `class.upcoming_with_capacity`, `<session_date>:<class_id>:<users.id>`. Las ocurrencias ya venían ordenadas por (fecha, clase) en `ClassFacts::upcomingWithCapacity()`, y `candidatesForOccurrence()` ya admitía `afterUserId`.
- **Una posición ilegible** (estropeada, de otra forma de disparador o de una pasada anterior a este cambio) **empieza por el principio**: se repasa a alguien, nunca se salta a nadie.
- **Una pasada `failed`, `aborted` o aún `running` no mueve la posición.** Se escribe solo en `complete()`. Si la pasada muere a mitad, la siguiente repite su página y la unicidad de la ocurrencia evita inscribir dos veces.
- **Si la página siguiente sale vacía** (la última se llenó justo, o lo que quedaba salió de la ventana), esa misma pasada empieza la vuelta nueva y no se pierde.
- **La posición es por modo:** sombra y vivo no comparten vuelta.
- **El freno de la estrategia y la cadencia** no abren pasada, así que no mueven nada. `--force` solo se salta la cadencia: cada pasada forzada avanza una página.
- **La posición lleva ids internos** (socios, cartera, pagos, seguimientos). Se guarda, pero **no sale**:
  - ni en `GET outreach/status` (`latestRuns.*.counts`), que pasa por `OutreachRuns::publicCounts()`;
  - ni en lo que devuelve `sweep()`, que es lo que imprime `outreach:sweep --json`.
- **Concurrencia.** El programador lleva `withoutOverlapping(10)->onOneServer()` (`routes/console.php:509-512`). Un `--force` a mano a la vez que el programado leería la misma posición y evaluaría la misma página: la UNIQUE de la ocurrencia lo hace inocuo (`already_enrolled`) y los dos escriben el mismo `cursorNext`. No se salta a nadie.
- **Coste [S].** Una consulta más por estrategia y pasada (`kind`, `strategy_id`, `trigger_key`, `mode`, `status`, `ORDER BY id DESC LIMIT 1`). Usa `orun_strategy_idx (strategy_id, id)` de atrás hacia delante y se para en la primera completada. No lo he medido con volumen real; hoy hay 0 estrategias [P].

### Qué cambia (ruta:línea)

- **`app/Services/Outreach/Run/TriggerPrefilters.php`:**
  - `:36-39` cabecera;
  - `:50-54` las dos formas de posición;
  - `:126-139` `pageFrom()`;
  - `:145-149` `page()` conserva su forma (en clases, `next` = 1 si quedan más);
  - `:151-192` `classCandidates()` con la posición compuesta.
  
  Antes, la de clases repetía siempre las primeras 500 de la primera ocurrencia: el mismo fallo que I-4, en otra forma.
- **`app/Services/Outreach/Run/OutreachRuns.php`:**
  - `:23-37` las claves `cursorFrom` y `cursorNext` y `PRIVATE_COUNT_KEYS`;
  - `:93-120` `publicCounts()` y `sweepPosition()`.
- **`app/Services/Outreach/Run/OutreachPlanner.php`:**
  - `:35-37` cabecera;
  - `:139-150` el barrido lee la posición, pide `pageFrom()` y vuelve a empezar en la misma pasada si hace falta. Guarda la posición solo al completar.
- **`app/Services/Outreach/Run/OutreachReadModels.php:45-46`:** los recuentos del estado, sin la posición.
- **`more`** conserva su sentido («quedan más en esta vuelta»).

### Pruebas sobre las clases reales

- **`tests/Feature/Outreach/Run/H9SweepCursorTest.php`** (nuevo, 8 pruebas, páginas de 2; SQLite **OK 8 / 67**, PostgreSQL **OK 8 / 67**):
  1. Cinco socios; los dos de id más bajo quedan fuera de la audiencia (lo que antes bloqueaba todo).
     - 3 pasadas recorren la vuelta: `candidates` 2, 2 y 1; `enrolled` 0, 2 y 1.
     - Ninguno se repite en la vuelta, y al acabarla están los tres que califican.
     - La 4.ª empieza otra vuelta.
     - `cursorFrom` y `cursorNext` exactos en cada pasada; ni la posición ni el resumen salen.
  2. La última página llena: la pasada que encuentra vacío el final empieza ya la vuelta nueva.
  3. Sin `--force`, la cadencia no abre pasada ni mueve la posición; a los 61 min sigue por la página 2.
  4. El freno de la estrategia no abre pasada ni mueve la posición; soltado (a cuatro ojos), sigue por la página 2.
  5. Una **excepción a mitad**:
     - un disparador temporal en la base (`RAISE(ABORT)` en SQLite, `plpgsql` en PostgreSQL) hace fallar la inscripción del 4.º;
     - la pasada queda `failed` sin posición y la posición sigue en la página 1;
     - al arreglarlo, la página 2 se repite (el 3.º sale `already_enrolled`, el 4.º se inscribe) y acaban inscritos los 5, cada uno una vez.
  6. La posición es por modo.
  7. **Clases**, dos ocurrencias y tres socios:
     - los 6 pares (ocurrencia, persona) salen en orden y sin repetirse, y las posiciones son exactas;
     - el barrido sigue las mismas posiciones y la 4.ª pasada vuelve a empezar.
  8. Posiciones ilegibles → desde el principio. Los `crm_fact` siguen sin prefiltro.
- **`tests/Feature/Outreach/Run/OutreachApiTest.php:140-163`:** el estado no enseña `cursorFrom` ni `cursorNext`, pero sí el resto de los recuentos.

**Mutaciones** (clon aislado): las 8 de esta tarea, muertas.

| Mutante | Qué rompe |
|---|---|
| M1 | el barrido ignora la posición (el fallo de antes) |
| M2 | lee también las pasadas fallidas o en curso |
| M3 | la posición sin modo |
| M4 | guarda como siguiente la de inicio |
| M5 | sin vuelta inmediata |
| M6 | las clases ignoran el usuario |
| M7 | las clases se saltan la ocurrencia del cursor |
| M8 | la API enseña la posición |

---

## 4 · El recuento de vínculos en el estado y un comentario falso

### Contrato (aditivo)

`GET api/admin/ultron-studio/outreach/status` → `data.personBindings = { "verified": <int>, "persons": <int> }`. `persons` lo añadí a petición del lead, después de la primera entrega.
- **Qué cuenta:** los vínculos verificados (`verified_at` no nulo) y vivos (`revoked_at` nulo). Es lo mismo que `STATUS_VERIFIED` de `StudioPersonBindings.php:359`.
- **Qué no lleva:** cuentas, identidades ni nombres.
- **Permisos:** lo ve quien ya puede leer el estado (`commercial_automation.view`; `OutreachAccess::VIEW`). Sin esa llave, 403 como siempre.
- **Lo demás no cambia:** las claves de `data` siguen en su sitio y `personBindings` va entre `live` y `can`.
- **El parser del CRM** (`readOutreachStatus`, `commercial-automation-parse.ts:1074-1102` en `5f2a454`) lee solo las claves que conoce y guarda el resto en `raw`, así que no rompe.
- **Fixtures de paridad:** ningún fixture fija la forma de `status`, así que no hay nada que regenerar.
- **`persons`:** identidades **distintas** con al menos un vínculo verificado y vivo (`count(distinct identity_id)`). Es lo que cuentan los cuatro ojos.
- **Matiz que resolvió `persons`:** `verified` cuenta **vínculos**, no personas. Una persona con dos cuentas (L9-7, «Dani» en las pruebas) cuenta dos. Si el CRM quiere decir con exactitud «hacen falta dos *personas*», haría falta además `persons`: identidades distintas con un vínculo verificado y vivo, también sin datos personales. **No lo he añadido:** no estaba pedido. Es una línea más en el mismo método.

### Qué cambia

- **`app/Services/Outreach/Run/OutreachPublishedStrategies.php:231-242`** · `verifiedBindingsCount()`.
  - Va en el puente, porque `OutreachIsolationTest` (L9-51) fija que `PERSON_BINDINGS` solo lo lea `OutreachPublishedStrategies`. La guarda sigue verde y la lista no cambia.
- **`app/Services/Outreach/Run/OutreachReadModels.php:72-73`** · `'personBindings' => ['verified' => …]`.
- **`app/Services/Outreach/Run/Channels/VoiceStrategyChannel.php:28-32`** · el comentario decía «V9 no lo declara». Ya está declarada en `OutreachSendGate.php:46` (L9-81). Ahora dice que declarada es implementada, no abierta, y qué la cierra: la constante del paso 3, la de la voz del H8 (`VoiceCallPolicy::CALLS_ENABLED_IN_CODE`), el interruptor del paso 2 y los cupos y presupuestos. Solo cambia el comentario.

### Pruebas

`tests/Feature/Outreach/Run/OutreachApiTest.php:112-138`:
- recuento exacto;
- un vínculo pendiente no cuenta; uno revocado deja de contar;
- las claves de `data`, en su orden;
- sin la llave, 403.

Resultados: `OutreachApiTest` OK 7 / 124 en SQLite; la batería `tests/Feature/Outreach/Run` en PostgreSQL, OK 83 / 1 373. **Mutaciones:** 3 de 3 muertas (cuenta revocados, cuenta pendientes, el estado sin el recuento).

---

## Ficheros de operación que convendría actualizar (no los toco)

- **`docs/ultron-studio/ops/RUNBOOK_H9.md`**, que en este árbol está editando otro agente:
  - en «Cada día» (`:238`): que el barrido ya no se queda en la primera página. `more: true` en la pasada `sweep` quiere decir que la vuelta sigue en la siguiente pasada.
  - para la puerta de cuatro ojos: que el panel tiene `personBindings.verified`.
- **`docs/ultron-studio/ops/h9-e2e/recorridos-h9.cjs:591-636`** copia `outreach_runs.counts` tal cual a la evidencia, así que ahora llevará `cursorFrom` y `cursorNext`. En el banco son ids de personas sintéticas. Si alguna vez se ejecuta contra datos reales, conviene filtrarlos con `OutreachRuns::PRIVATE_COUNT_KEYS`.
- **Documentos que citan la versión del catálogo** (`2026-11-10.2` y `ce5fcdab…`): el contrato H9, la auditoría, la aceptación, la completitud y los informes del H9. Lo decide documentación o el lead; los contratos no los he tocado.

## Lo que NO he podido verificar

- **Nada contra producción:**
  - que `outreach_decisions` esté vacía (supuesto [P] de la tarea 1: comprobarlo antes de desplegar);
  - el volumen real de las audiencias (I-4);
  - el coste de la consulta de la posición con historia real.
- **El CRM:** no he ejecutado sus pruebas. Lo que tiene que cambiar está en las secciones 1 y 2.
- **La batería completa del backend:** no la he lanzado, por la regla de la máquina. Solo `tests/Unit/Outreach`, `tests/Feature/Outreach`, las Strategies del Studio, `MetaTemplateContentTest` y las 15 guardas.
- **La guarda de la puerta de diff:** sigue roja por los cambios sin commit de prompts y publicaciones (`app/Services/Marketing/Ultron/{Prompts/UltronPromptCompiler.php, Prompts/UltronPromptException.php, UltronPublishedConfig.php}`), no por estas tareas.

---

## Añadidos tras la revisión de `security` y del lead

- **R1:** `tests/Feature/Outreach/Run/H9ShadowNeverRunsLiveTest.php`, nuevo, a partir del borrador de `security` y con el estilo del repositorio.
  - Primera prueba: pasar de `shadow` a `live` con un trabajo de sombra `planned` y vencido.
    - el despacho reclama 0, porque solo reclama trabajos del modo actual;
    - la inscripción de sombra no se planifica;
    - la pasada en vivo crea su propia inscripción, que N3 niega con `canary_only_declared`;
    - no hay ningún trabajo en vivo ni ningún mensaje.
  - Segunda prueba: un trabajo reclamado en sombra por la cola y procesado ya en vivo se cancela con `mode_off` (N2), sin llegar al canal.
  - Resultados: SQLite OK, 2 pruebas / 19 aserciones; PostgreSQL, dentro de `tests/Feature/Outreach/Run`: OK, 85 / 1 395.
- **R2:** `tests/Feature/UltronStudio/OutreachIsolationTest.php:552-553`. El comentario dice ahora que N3 niega al socio 0 en vivo, y que en sombra lo niega su cartera (N6).
- **`persons`:**
  - `OutreachPublishedStrategies::verifiedBindingsCount()` (`:231-249`) devuelve `{verified, persons}`, y `OutreachReadModels.php:73` lo expone tal cual.
  - La prueba (`OutreachApiTest.php:112-152`) cubre:
    - Ana, Beto y Caro → `persons` 3;
    - una segunda cuenta de Ana → `verified` +1 y `persons` igual;
    - un vínculo pendiente no cuenta;
    - uno revocado resta.
  - `pint --test`: `passed`.
