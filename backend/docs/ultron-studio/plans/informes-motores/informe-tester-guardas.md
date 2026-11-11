# Informe del tester (`tester-guardas`): enmiendas de guardas y M-1

> **Ninguna mutación revela un defecto del selector «Elegir Prompts»: no hay que regenerar ni reimportar S1.** El selector generado pasa las 1 737 comprobaciones de mi verificador independiente, incluidos los 306 paquetes del contrato. El comprobador del flujo da OK (111). Quitarle el `try` lo tumban el comprobador, mi verificador y dos pruebas PHP.

**Fecha:** 11-11-2026. **Árbol:** `feat/ultron-studio-h9`, HEAD `120a8b23`, en local. **Sin commit, sin push, sin producción.**
Solo he tocado pruebas, fixtures, la línea de `.env.example` y este informe. El código de la aplicación no lo he tocado. Todas las mutaciones se hicieron en una COPIA del backend (con `vendor` copiado, no enlazado), nunca en el árbol. La huella de la copia salió idéntica antes y después de cada tanda.

## Resumen para el lead

**Tarea 3 (declaración `/2` del Studio): hecha y en verde en SQLite y en PG.** El Studio real escribe exactamente lo que sale de los contratos, sin ninguna discrepancia. Detalle en §3.

| Qué | SQLite | PG 14 |
|---|---|---|
| `StudioPublicationV2ParityTest` (nueva, pura): el Studio real frente a `publication-cases-v2.json` | 94/94 | 94/94 |
| `UltronStudioPublicationV2Test` (nueva, HTTP): `previewHash` con paquete y con arrastre, tabla de cambios, **(b2)** promover sobre una cabeza `/1` → `/1` (y su control `/2` → `/2`) | 5/5 | 5/5 |
| `UltronStudioPublicationV2RaceTest` (nueva, sesiones reales del H5): **(b1)** la parte del resto cambia entre `facts()` y la transacción → 409 `publication_moved`, más su control | omitida (solo PG) | 2/2 |
| `tests/Unit/UltronStudio` entero | 2006/2006 | 2006/2006 |
| `UltronStudioPublication*`, sin `UltronStudioPublicationRaceTest` (H5) | 211 (46 omitidas: solo PG) | 173/173 · ver la nota 1 |
| `UltronStudioPublicationRaceTest` (H5, 27 carreras de hasta 20 sesiones) | omitida | ver §3.5 |
| Mutaciones en la copia contra lo nuevo | **10/10 caen**. Tres solo las cazan las pruebas nuevas: `previewHash` sin el arrastre, sin `publication_moved` y promover escribiendo `/2` sobre partes `/1` | |

- **Qué cubre el fixture `/2`** (`tests/fixtures/ultron-studio/publication-cases-v2.json`, derivado con un guion Node propio **sin leer `StudioPublicationDeclaration.php`**):
  - **9 entradas que sí se escriben**, con bytes, huella y firma real del motor:
    - en `/2`: canaria sola, canaria + resto, resto arrastrado (M1-12), promover, revertir, `{null, X}` y `issuedAt` de noche;
    - en `/1`: Legacy y llave apagada.
  - **29 que no se escriben ni se firman**:
    - Legacy en `/2`, contrato desconocido y contratos cruzados;
    - `governed` y `prompts` con claves o tipos fuera de contrato;
    - `bundleHash` mal formado.
  - **12 `previewHash` sin paquete**, con la fórmula Q-4 byte a byte.
  - **34 clasificaciones con la llave de prompts**: los cuatro gobernados salen de `noEffect` y lo demás no cambia.
  - **Cómo me fío de la derivación:** antes de generar, el guion reproduce los 11 `effectiveJson` y las 5 firmas `/1` del fixture congelado del H5. Ese fichero y su prueba no los he tocado: solo lleva la enmienda `/2` → `/3` de la tarea 1.
- **Las dos carreras de §6 de B-S:**
  - **(b1)** La sesión que publica se para dentro de su transacción, ya pasadas las puertas; otra persona vuelve a Legacy, y al soltarla da 409 `publication_moved` sin escribir nada. El control, la misma pausa sin mover la cabeza, publica `/2` con el arrastre que enseñó la vista.
  - **(b2)** No es una carrera: es HTTP, y está en verde en SQLite y en PG.
  - Ninguna necesitó infraestructura nueva: es el rasgo `PublicationRaceSessions` tal cual.
- **Lo que hay que saber:**
  1. **Un rojo intermitente que no es del código.** La primera pasada en PG de `UltronStudioPublication*` dio 10 errores en el `setUp` de `UltronStudioPublicationVerifiedTest`, la última clase, con 2 420 aserciones. Las dos pasadas siguientes, idénticas, dieron 173/173 (2 577). El indicio: `bootstrap/cache/services.php` se reescribió a las 07:55:34, dentro de la ventana de esa pasada, mientras tu batería completa corría en el mismo árbol. **Es una hipótesis, no una causa demostrada**: no guardé la traza de esa pasada.
  2. **`previewHash` CON paquete no tiene fórmula en ningún contrato.** Solo consta qué entra (M1-12 y los DTOs de B-S). Lo pruebo por relación, no byte a byte. Si quieres congelarla, primero hay que escribirla en el contrato.
  3. **Ni el lector ni el escritor fijan que el `bundleHash` vaya en minúscula** (el plan dice «64 hex»): no hay caso con mayúsculas.
- **Lo que NO he verificado:** la suite de carreras del H5 completa en PG (§3.5); E14 (en espera de tu aviso); el CRM; la suite completa; y que el escritor rechace VALORES gobernados fuera del catálogo (no es contrato del escritor: lo hacen `prompts_compile_failed` y `decide`).

**Tarea 1 (aceptada): sigue en verde, y el hueco del monitor está cerrado.**
- La guarda del monitor (H7) exige ahora que la bandera sea la condición ENTERA del `if`, como la de Meta: constante `SCHEDULER_CONDITION` (`UltronEngineIsolationTest.php:283`) y su `assertSame` (`:428`).
- R2 (`|| true`) y R3 (condición negada) **caen ahora**; R1 sigue cayendo.
- `UltronEngineIsolationTest`: 11/11 en SQLite y en PG.
- `Meta\Templates` y «ni el motor las nombra»: **no lo prohíbo**, como decidiste. Queda anotado (§1.c).

**Tarea 2 (M-1): hecha.**

| Qué | Resultado |
|---|---|
| `tests/fixtures/ultron-prompts/compile-cases.json`, derivado del contrato (§B.3, M1-3) con un guion Node propio y **sin leer `UltronPromptCompiler.php`**. Todos los valores válidos (306 = 3 tonos × 2 emojis × 17 `maxChars` × 3 objetivos: la línea base, los 21 sueltos y todas las combinaciones) + 26 inválidos | Huella Legacy `3f6a5674…` y V2 `702890f7…` (113 672 B, 10 parches), **las mismas que publicó B-M**: tres cálculos independientes coinciden |
| `tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompileCasesTest.php`: el compilador real frente al fixture, rol a rol (sha256 y bytes, también de los roles que no cambian), huella, vista previa (`patches()`), orden de claves, inválidos, fixture al día (Node) y selector (Node) | **643/643** en SQLite (4 692 aserciones). El directorio `Prompts` entero, 765/765 en PG |
| `tools/verificar-selector.cjs`: el selector generado en `vm`, contra los 306 paquetes del contrato y entradas hostiles | **OK, 1 737 comprobaciones**, sobre `elegir-prompts.js` y sobre el `jsCode` de `flujo-s1.json` |
| `node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs n8n/flujos/ultron-studio-s1/flujo-s1.json` | **OK (111 comprobaciones)**, salida 0 |
| Mutaciones (5b), una a una en la copia | **Las 5 del plan caen, con todas sus variantes (16/16)**, y también 3 extra que miden la prueba nueva (§2.4) |
| «Quitar el try del selector» contra el comprobador del flujo | **FALLO: 1 de 111** («✗ el selector se ejecuta sin lanzar», getter hostil `boom`), salida 1 |

Lo que tienes que saber (nada bloqueante):

1. **Lectura del contrato que conviene que el propietario conozca.** Dos inserciones tras la misma línea quedan en orden INVERSO al de aplicación: en el Critic, con tono y sin emojis, sale `naturalness:` → `EMOJIS:` → `REGISTRO PUBLICADO:`. Es la lectura literal de §B.3 («inserta una línea nueva tras la línea del ancla», aplicada en el orden de los parámetros), y mi derivación independiente da lo mismo que el compilador. Si la intención era el orden textual de los parámetros, es un cambio de contrato (`VERSION` 2), no un fallo.
2. **Selector, observación menor (no es defecto, no hay que regenerar).** Si leer `$input` lanza, el selector cae a Legacy sin lanzar, pero con motivo `absent`, no `selector_error`: tiene su propio `try` que deja `ctx = {}`. El contrato no fija ese motivo (su esqueleto ni siquiera protege esa lectura). Solo afecta a la etiqueta de la traza en un caso que en n8n no debería darse.
3. **Cobertura delgada, pero suficiente:**
   - quitar el paso 7 de la oferta (la comparación de huella) lo detecta **un solo** caso de `UltronDecidePromptsTest` (`huella del paquete que …`);
   - quitar la unicidad del ancla solo lo detecta la prueba de B-M que llama por reflexión a `apply()` (privado). Con la base fijada por sha256 no se alcanza por la API pública, y mi fixture no puede verlo.
4. `publication-cases-v2.json` está hecho en la tarea 3 (§3). El E2E E14 sigue sin hacer, en espera de tu aviso.

---

## 1. Tarea 1: enmiendas de guardas

Qué apliqué (fichero:línea, tras la edición):

| | Qué | Dónde |
|---|---|---|
| a | `/2` → `/3` como «contrato desconocido» (diff de B-M tal cual, aplicó limpio) | `tests/fixtures/ultron-studio/publication-cases.json:6195,6204,6235` (scope/34 y scope/35) · `tests/Unit/Marketing/UltronPublishedConfigTest.php:439` · `tests/Feature/Marketing/UltronCommitStudioStampTest.php:443` |
| a | La nota del README: ahora dice que `/3` no entra en PG porque el CHECK admite `/1` y `/2` desde M-1 | `tests/fixtures/ultron-studio/publication-cases.README.md:134` |
| b | `UltronPromptOffer` en `ENGINE_READER_NAMERS` y la prueba `test_la_llave_de_los_prompts_vale_off_por_defecto` (diff de B-M tal cual, aplicó limpio) | `tests/Feature/UltronStudio/UltronEngineIsolationTest.php:329`, `:634`, `:642` |
| c | La bandera del programador de Meta: `SCHEDULER_META_FLAG` y `SCHEDULER_REFERENCES` con las dos banderas en el orden del fichero | `UltronEngineIsolationTest.php:291`, `:294` |
| c | Su propia aserción: `test_la_bandera_de_las_plantillas_de_meta_solo_programa_su_sincronizacion` | `UltronEngineIsolationTest.php:445` (constantes `:297` y `:300`) |
| c | La lógica del `if` sale a un ayudante, `schedulerFlagBlock()`, que comparten el monitor y Meta | `UltronEngineIsolationTest.php:423`, `:470` |
| c (lead) | El monitor, con su condición exacta (`SCHEDULER_CONDITION`), como Meta | `UltronEngineIsolationTest.php:283`, `:428` |
| c | `app/Services/Meta/Templates` en `NEUTRAL_ZONES` | `UltronEngineIsolationTest.php:313` |
| c | Los 3 ficheros del Studio sin red directa y el modelo, en `H9`; el modelo, también en `MODELS` | `tests/Feature/Outreach/Guards/OutreachStaticGuardsTest.php:52-53`, `:120` |
| d | Las cuatro líneas de `MARKETING_ULTRON_STUDIO_PROMPTS=off`, justo debajo de `MARKETING_ULTRON_STUDIO_CONFIG` | `.env.example:295-298` |

Verificación (e):

| Motor | Pruebas | Resultado |
|---|---|---|
| SQLite | `UltronEngineIsolationTest` + `OutreachStaticGuardsTest` | 17/17 (tras cerrar el hueco del monitor, `UltronEngineIsolationTest` 11/11) |
| SQLite | `UltronPublishedConfigTest` + `UltronCommitStudioStampTest` + `StudioPublicationParityTest` + `Unit/Marketing/Ultron/Prompts` + los 4 `Feature/Marketing/Ultron*Prompts*Test` + `UltronStudioPermissionsTest` | 674/674 |
| PG 14 (`ultron_studio_tg`) | Las 11 de arriba, en una pasada | 691/691 (y `UltronEngineIsolationTest` 11/11 tras el cierre del monitor) |
| — | `php artisan auth:coverage --unmapped` | vacío, salida 0 |
| — | `php artisan route:list --path=api/admin/ultron-studio` | «Showing [125] routes» (= `StudioRouteCount::TOTAL`) |
| — | `vendor/bin/pint --test` sobre todo lo tocado | passed |

### 1.a Contrato `/2` → `/3`

Siguen en el fixture y en las pruebas los dos casos `/2` que no son «contrato desconocido», y siguen siendo válidos:

- `publication-cases.json:6722`: `"contract":"ultron-engine-config/2"` dentro del texto firmado y la columna en `/1` → `malformed`;
- `UltronPublishedConfigTest.php:539`.

Los dos prueban que el contrato del JSON tiene que ser igual al de la columna. En PG, el CHECK desechable quedó en `/1|/2`; `/3` solo entra con `sinElCheckDelContrato()`, como antes `/2`.

### 1.b La oferta nombra al lector (M1-6)

El diff aplicó limpio. Además, la prueba cae si otro fichero del motor nombra al lector (G10).

### 1.c Programador, zona neutra y listas del H9

**Guarda de Meta:**
1. la bandera, una sola vez;
2. la condición ENTERA de un `if` con llaves y sin `else`, token a token: `if((bool)config('ultron_studio.meta_templates.sync_enabled',false))`;
3. el bloque con exactamente `Schedule::command('ultron-studio:meta-templates-sync')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer()`.

La del monitor exige ahora lo mismo con su condición, y conserva su regla de «solo órdenes del Studio» y sus ≥ 2 sentencias.

**`FORBIDDEN`, comprobado por mí sin fiarme del informe** (guion que replica `codeOnly()`, y también sobre el fichero entero):

- los 4 ficheros nuevos dan 0 coincidencias;
- los `Log::` de `StudioMetaTemplates` usan el canal `ultron_studio.meta_templates.*` y claves sin dato personal;
- el modelo lleva `$guarded = ['*']`.

**Decisión mía:** el modelo va también en `H9`, como los demás modelos de `MODELS`.

**Anotado, no prohibido (decisión del lead):** «ni el motor las nombra» no se aplica a `Meta\Templates`. La lista de nombres del motor es la fija del H6. Hoy ningún fichero del motor lo nombra (grep).

**Contexto:** `UltronEngineIsolationTest.php` ya traía un cambio de otro agente (la migración `2026_11_16_100001_…` en `ALLOWED`); lo he conservado.

### 1.d `.env.example`

Lo edité con la herramienta de edición (el hook bloquea la consola). Las cuatro líneas de informe-bm §6, tal cual.

### 1.e Mutaciones de las guardas

| Id | Mutación | Resultado | Prueba que cae |
|---|---|---|---|
| S1–S11 | Meta: un trabajo del motor; otra orden del Studio; `--dry-run`; `else`; `\|\| true`; condición negada; `everyMinute()`; sin `onOneServer()`; la bandera repetida; `if` sin llaves; `Schedule::job` | **CAEN las 11** | `…plantillas_de_meta…` (S9, además el rastreo global) |
| R1 | Monitor: un trabajo del motor en su bloque | CAE | `test_la_bandera_del_monitor…` |
| R2 | Monitor: condición `\|\| true` | **CAE desde el cierre** (antes sobrevivía) | `test_la_bandera_del_monitor…` |
| R3 | Monitor: condición negada | CAE | `test_la_bandera_del_monitor…` |
| G1–G4 | `use …\Facades\Http;` en cada uno de los 4 ficheros nuevos del H9 | CAEN las 4 | `test_el_h9_no_nombra_proveedores…` |
| G5 | Modelo de envíos con `$guarded = []` | CAE | `test_los_modelos_del_gobierno_van_blindados…` |
| G6 | `Log::` con la clave `phone` | CAE | `test_ningun_log_del_h9…` |
| G7 / G7b | `MetaTemplate` nombra `/api/admin/ultron-studio/…` / lo mismo sin la entrada nueva de `NEUTRAL_ZONES` | CAE / SOBREVIVE (la entrada aporta cobertura real) | `test_el_motor_no_nombra_las_clases_neutras…` |
| G8 | La llave de prompts nace en `canary` | CAE | `test_la_llave_de_los_prompts_vale_off_por_defecto` |
| G9 | El lector acepta también `/3` | CAE | 4 pruebas (`UltronPublishedConfigTest`, fixture scope/34 y scope/35, `UltronCommitStudioStampTest`) |
| G10 | `UltronDecideService` nombra al lector | CAE | `test_la_unica_lectura_del_motor…` |

---

## 2. M-1: fixture independiente y mutaciones

### 2.1 Cómo se derivó, sin mirar el compilador

`tests/fixtures/ultron-prompts/tools/` (detalle en `compile-cases.README.md`):

- **`parches-m1.cjs`** (puro): los textos de §B.3 transcritos a mano, las anclas, `insert_after_line` y `replace`, el orden por parámetro, los límites y la fórmula de la huella. Antes de generar exige cuatro cosas:
  1. que cada texto aparezca **literalmente** en §B.3 del plan;
  2. que la base sea, byte a byte, el flujo vivo 29de5119 (el respaldo exportado) y `nuevo4.json`;
  3. que las anclas «a principio de línea» den la misma línea leídas como subcadena y como principio de línea;
  4. que la fórmula de la huella sea la del plan.
- **`derivar-compile-cases.cjs`**: escribe el fixture (550 KB, un caso por línea); con `--comprobar` sale 1 si no está al día.
- **`verificar-selector.cjs`** (E3): ejecuta el selector de n8n en `vm`, como el nodo Code.

**Para saber cómo llamar al compilador leí `api-compilador.md`**, que es la API pública, y `UltronPromptException.php`. **`UltronPromptCompiler.php` lo abrí solo después de cerrar el fixture**, para las mutaciones.

Los guardianes del guion también caen. En una mini-copia aparte:

| Cambio | Resultado |
|---|---|
| Un byte del fixture | `--comprobar` sale 1 |
| Un texto del plan («frio.» → «frio!») | «transcripción equivocada» |
| Un byte de `composer.txt` | «≠ responses.values[0].content del flujo 29de5119» |
| El orden de la fórmula en el plan | «La fórmula … no es la que este guion implementa» |

### 2.2 La prueba PHP (`UltronPromptCompileCasesTest`, 643 casos)

1. El marco del fixture = las constantes publicadas (`CONTRACT`, `ID`, `VERSION`, `ROLES`, `BASELINE`, `LEGACY_BUNDLE_HASH`, sha256 y bytes de la base, topes), y el fixture cubre exactamente los 306 valores.
2. Por cada caso (306), `compile()` da:
   - sha256 y bytes de **los cinco** roles: los que no cambian, iguales a la base;
   - la huella y el total de bytes;
   - `changedRoles()` e `isBaseline()`.
3. Por cada caso (306), `patches()` da, rol a rol y en orden, los parches del contrato con sus textos, y es la misma lista que `compile()['patches']`. El orden ENTRE roles no es contrato y no se afirma.
4. El orden de las claves no cambia la huella (invertido y barajado con semilla fija), en los 306.
5. Los 26 inválidos dan `invalid_governed` en `compile()`, `patches()` y `validate()`; `maxChars = 200.0` (decimal) no compila.
6. `--comprobar` (Node): el fixture es el que sale hoy del contrato y de la base.
7. El verificador del selector (Node) sobre `n8n/flujos/ultron-studio-s1/elegir-prompts.js`.

Resultado: **643/643 en SQLite** (4 692 aserciones, ~4 s). Es una prueba pura, sin base de datos. El directorio `tests/Unit/Marketing/Ultron/Prompts` entero da 765/765 en PG y en SQLite.

### 2.3 Selector (E3) y comprobador del flujo

`verificar-selector.cjs` pasa **1 737 comprobaciones** sobre `elegir-prompts.js` y sobre el `jsCode` de `flujo-s1.json`:

- **sin oferta:** Legacy, roles = la base, huella Legacy y `decide` intacto;
- **los 306 paquetes del contrato como oferta `studio`:** los mismos roles, y la huella JS = la de Node = la del fixture;
- **ofertas inutilizables → Legacy con su motivo:**
  - un carácter alterado, la huella de otra versión, la huella en mayúsculas o sin huella → `hash_mismatch`;
  - un rol de menos o de más, roles como lista o nulos → `shape`;
  - un rol vacío, que no es texto o de 40 961 caracteres → `size`;
  - otro contrato → `contract_unknown`;
  - oferta legacy → `offered_<motivo>`;
- **entradas hostiles:** getters que lanzan → `selector_error`. Con tipos raros en `studio_prompts` o motivos con espacios, acentos, 300 caracteres o un objeto: Legacy, y **el motivo siempre cabe en la regla del commit** (`^[A-Za-z0-9_.:-]{1,64}$`), así que nunca provoca un 422 ni una rendición;
- `$input` que lanza: ver la observación 2 del resumen.

`verificar-flujo.cjs` sobre `flujo-s1.json`: **OK (111 comprobaciones)**, salida 0.

### 2.4 Mutaciones (5b), en la copia, una a una

Baselines sin mutar, todas en verde:

| Prueba | Resultado |
|---|---|
| `UltronDecidePromptsTest` | 138/138 |
| `Unit/Marketing/Ultron/Prompts` | 765/765 |
| `UltronCommitPromptsTest` | 35/35 |
| `UltronPromptsN8nCommandTest` | 8/8 |
| `UltronPromptCompileCasesTest` | 643/643 |
| Pruebas del Studio (`StudioPromptDeclarationTest`, `UltronStudioPublicationPromptsTest`, `UltronStudioPublicationRollbackTest`) | 95/95 |
| `verificar-flujo.cjs` | OK |
| `verificar-selector.cjs` (sobre `.js` y sobre el flujo) | OK |

| Id | Mutación | Resultado | Lo que cae (casos) |
|---|---|---|---|
| M1 | Oferta: sin la comparación de huella (paso 7) | CAE | `UltronDecidePromptsTest::test_nunca_lanza_y_cae_a_legacy_con_su_motivo` («huella del paquete que …»): **1 caso** |
| M1b | Oferta: el paso 7 solo mira que la huella firmada sea texto | CAE | el mismo caso |
| M2 | Commit: sin `'prompts'` en `unexpectedKeys` | CAE | `UltronCommitPromptsTest::test_un_bloque_fuera_de_contrato_es_un_422_y_no_escribe` (2) |
| M3a | Selector sin su `try`, solo en `elegir-prompts.js` | CAE | `verificar-selector` (3 getters hostiles); mi `test_el_selector_generado…`; `UltronPromptsN8nCommandTest::test_lo_que_hay_en_el_repositorio_es_lo_que_genera_hoy` |
| M3b | Selector sin su `try`, solo en el `jsCode` de `flujo-s1.json` | CAE | **`verificar-flujo.cjs`: «FALLO: 1 de 111», «✗ el selector se ejecuta sin lanzar»**; `verificar-selector` sobre el flujo; la prueba de deriva de B-M |
| M3c | Lo mismo en el `.js` Y en el flujo, coherentes entre sí: solo lo caza el comportamiento | CAE | `verificar-flujo.cjs` (1 de 111), `verificar-selector`, mi prueba y la de deriva de B-M |
| M3d | Sin el `try` en el GENERADOR (`UltronPromptsN8nFlow.php`) | CAE | `UltronPromptsN8nCommandTest`: el comprobador sobre lo generado + la deriva. Mi prueba no lo ve, porque lee el artefacto confirmado; la deriva de B-M cubre ese hueco |
| M4 | Compilador: el ancla solo tiene que existir (sin unicidad) | CAE | `UltronPromptCompilerTest::test_un_ancla_ausente_o_repetida_lanza`, por reflexión sobre `apply()` |
| M4b | Compilador: sin ninguna comprobación del ancla | CAE | la misma (2 casos) |
| M5a | Studio: «volver a Legacy» pide `/2` (con la guarda R10) | CAE | 7 casos de `UltronStudioPublicationRollbackTest`/`PromptsTest` (Legacy por HTTP, idempotencia, «deja de declarar las dos»…) |
| M5b | Studio: «volver a Legacy» ESCRIBE `/2` (sin la guarda R10) | CAE | `StudioPromptDeclarationTest::test_lo_que_el_motor_no_leeria_no_se_escribe`; `UltronStudioPublicationPromptsTest::test_la_parte_que_se_arrastra…_en_v2` |
| M5c | Studio: solo sin la guarda R10 de `effectiveJson()` | CAE | `StudioPromptDeclarationTest::test_lo_que_el_motor_no_leeria_no_se_escribe` |
| M5d | Studio: revertir la última canaria (sin partes) escribe `/2` sin la guarda | CAE | 3 pruebas (`…revertir_con_la_llave_encendida_sobre_una_cabeza_v1_escribe_v1`, …) |
| X1 | Compilador: un texto de parche sin su punto final | CAE | **mi prueba: 102 + 102 casos** (bytes y vista previa); B-M: 2 |
| X2 | Compilador: `emojis` se aplica antes que `tone` | CAE | **mi prueba: 102 + 102**; B-M: 1 (`…orden_inverso_al_de_aplicacion`) |
| X3 | Compilador: `maxChars` escribe N − 20 | CAE | **mi prueba: 288 + 288**; B-M: 17 |

**No sobrevive ninguna**, así que no he tenido que escribir pruebas nuevas para matarlas. Lo delgado está en el resumen (punto 3). Las de `bs-studio` (M5) se hicieron sobre la instantánea de sus ficheros de las ~07:00; esos ficheros siguen cambiando.

### 2.5 Reproducir

- **Fixture y selector:** los comandos de `tests/fixtures/ultron-prompts/compile-cases.README.md`.
- **Mutaciones:** `tests/fixtures/ultron-prompts/tools/mutaciones/` contiene `mutar.php`, `spec-m1.php`, `spec-guardas.php`, `spec-programador.php` y `LEEME.md` con los pasos para crear la copia. No son pruebas de la suite: PHPUnit solo recoge `tests/Unit` y `tests/Feature`. Lo guardo en el repositorio porque el scratchpad se purga.

### 2.6 Qué NO cubre esto

- `publication-cases-v2.json` quedó fuera de la tarea 2; se hizo en la tarea 3 (§3).
- **E14 (E2E con el doble de n8n y el selector generado)**: no hecho.
- **La oferta (`decide`) contra el fixture**: el paso 7 lo detecta un solo caso de B-M. Un `decide` negociado con 2 o 3 casos del fixture (p. ej. el V2 y el de 15 parches) lo haría más robusto; no lo he escrito.
- **PG en las mutaciones:** se ejecutaron en SQLite. Las pruebas en verde se pasaron también en PG.
- **n8n real**: el runner de n8n no lo he tocado (lo ensayó el lead). El selector se ejecutó en `vm` de Node.
- **La suite completa** (la corres tú).

---

## 3. Tarea 3: la declaración `/2` del Studio

### 3.1 El fixture, derivado de los contratos

**Ficheros:** `tests/fixtures/ultron-studio/publication-cases-v2.json` (126 KB), `publication-cases-v2.README.md` y `tools/derivar-publication-cases-v2.cjs` (con modo `--comprobar`). Escrito **sin leer `StudioPublicationDeclaration.php`**. De su API pública solo tomé las firmas de los métodos (grep), para poder llamarlos desde la prueba.

**Fuentes:**
- H5: A4.1, A4.2, Q-1, Q-4, M1-5 y M1-12;
- E-1, E-2 y S-1 a S-4 (README del fixture del H5);
- contrato F §307 (`StudioContentHasher`);
- plan §B.4;
- las huellas de paquete de mi `compile-cases.json`.

**Antes de generar, el guion se comprueba:**
1. **Cita literalmente los contratos que implementa:** la fórmula de la firma, Q-1, Q-4, la forma de `governed` y de `prompts`, R10 y la clase `prompt`.
2. **Reproduce el fixture congelado del H5:** los 11 `effectiveJson` y las 5 firmas `/1`.

**Lecturas que fija** (detalle en su README):
- la firma de `/2` lleva el prefijo `/1` (§B.4 literal; lo acordaron B-M y B-S). Se escribe además la firma con el prefijo `/2`, para que se vea que NO es la buena;
- al escribir, solo se exige la FORMA de `governed` y `prompts`, no sus valores;
- `previewHash` con paquete no tiene fórmula contractual (ver §3.2).

### 3.2 Las pruebas

**`tests/Unit/UltronStudio/StudioPublicationV2ParityTest.php`** (94 casos). Extiende `Tests\TestCase` solo por `config('app.key')`; no usa base de datos.
1. Las 9 entradas:
   - `effectiveJson()` da los bytes y la huella;
   - `declaration()`, con `UltronPublishedConfig::signatureFor` inyectada, da `{contract, effectiveJson, effectiveHash, signature}` exactos;
   - la firma no es la del prefijo `/2`;
   - `entry()` lee de vuelta el mismo contrato y las mismas partes.
2. Las 29 inválidas: `effectiveJson()` y `declaration()` lanzan `InvalidArgumentException`, y **el firmante no llega a llamarse**.
3. Partes: `isPartV2`, `partV1(p)` = `p` sin las dos claves y `partV2(partV1(p), governed, prompts)` = `p`.
4. Los 12 `previewHash` sin paquete = Q-4 (también con `prompts: null` explícito).
5. Las 34 clasificaciones con `promptsGoverned = true`:
   - `mustMatch`, `matches`, `noEffect` (sin los gobernados), `mismatches` y `engineReadingUnavailable` exactos, y `requiredAcknowledgements` igual;
   - los gobernados que salen de `noEffect` siguen en la clasificación;
   - sin la llave, la del fixture congelado.
6. `--comprobar` del guion (Node).

**`tests/Feature/UltronStudio/UltronStudioPublicationV2Test.php`** (5, HTTP). Las huellas esperadas salen de `compile-cases.json`, no del Studio.
- **`previewHash` ata el paquete:** para la línea base, con la clasificación y las filas idénticas con y sin llave, la huella cambia; y es estable.
- **La tabla de cambios** de G8 son, rol a rol, los parches del contrato con sus textos; huella y bytes los de mi fixture.
- **`previewHash` ata la parte arrastrada (M1-12):**
  - misma versión V, la cabeza pasa de P1 (entusiasta) a P2 (sin emojis): la huella cambia con la llave y no cambia sin ella (Q-4);
  - el `carried` de cada vista lleva la huella de mi fixture;
  - publicar con la huella de P1 da **409 `preview_stale`**.
- **(b2) Promover con la llave encendida sobre una cabeza `/1` → `/1`:** las dos partes en `/1`, V `PUBLISHED`, la cadena coherente y la oferta real de `decide` `legacy/declaration_v1`. **Control:** sobre una `/2`, sigue en `/2` con la MISMA parte (Q-3), y la oferta es `studio`.

**`tests/Feature/UltronStudio/UltronStudioPublicationV2RaceTest.php`** (2, solo PG). Usa el rasgo `PublicationRaceSessions` tal cual y la pausa `FIRST_CHAIN_STATEMENT` del H5.
- **(b1)** Cabeza `/1` `{P, P}`, con P entusiasta; la vista de V enseña el arrastre de P.
  - La sesión que publica V se para justo antes de su primera sentencia sobre la cadena dentro de la transacción (ya pasó `facts()` y las puertas). La prueba vuelve a Legacy, que es otra persona por HTTP, y la suelta.
  - **Resultado: 409 `publication_moved`.** La entrada de V no existe; solo hay la de Legacy (`/1`); V sigue `APPROVED`; la cadena verifica para el motor; la oferta es `part_legacy`; nada a medias en el libro.
- **Control:** la misma pausa sin mover la cabeza da 201 `/2` `{V, P}`, con el arrastre que enseñó la vista. Así se ve que el 409 no lo causa la pausa.

### 3.3 Mutaciones (en la copia; `tests/fixtures/ultron-prompts/tools/mutaciones/spec-v2.php`)

Líneas base sin mutar, todas en verde:

| Prueba | Resultado |
|---|---|
| Paridad | 94 |
| HTTP | 5 |
| Carrera (PG) | 2 |
| B-S: `StudioPromptDeclarationTest` + `UltronStudioPublicationPromptsTest` | 73 |
| B-S: `UltronStudioPublicationPromptsRaceTest` (PG) | 3 |

Huella de la copia idéntica antes y después.

| Id | Mutación | Las nuevas | Las de B-S |
|---|---|---|---|
| V1 | Sin la guarda R10 (Legacy en `/2`) | CAE (paridad) | cae |
| V2 | Sin la comprobación de tipos de `governed`/`prompts` | CAE (6 casos) | cae (4) |
| V3 | `governed`/`prompts` sin claves exactas | CAE (8 casos) | cae (3) |
| V4 | `bundleHash` sin el formato de 64 hex | CAE (2) | cae (2) |
| V5 | El texto de una entrada `/2` dice `/1` | CAE (7) | cae (6) |
| V6 | Con la llave, los gobernados siguen en `noEffect` | CAE (paridad, 3) | cae |
| V7 | `previewHash` no ata el paquete | CAE (HTTP, 2) | cae (1) |
| **V8** | **`previewHash` no ata la parte arrastrada** | **CAE (HTTP)** | **SOBREVIVE** |
| **V9** | **Publicar sin comprobar el arrastre en la transacción** | **CAE (carrera PG)** | **SOBREVIVE** (también la carrera `/2` de B-S) |
| **V10** | **Promover/revertir sin mirar si las partes ya eran `/2`** | **CAE (HTTP, b2)** | **SOBREVIVE** |

### 3.4 Verificación (c), un proceso cada vez, `nice -n 10`

| Qué | SQLite | PG 14 (`ultron_studio_tg`) |
|---|---|---|
| Las tres pruebas nuevas | 101 (2 omitidas: la carrera) | **101/101** (626 aserciones) |
| `tests/Unit/UltronStudio` | **2006/2006** (15 892) | **2006/2006** (15 892) |
| `UltronStudioPublication*` sin la carrera del H5 | **211** (2 254; 46 omitidas: solo PG) | **173/173** (2 577) en dos pasadas seguidas. Antes, una pasada con 10 errores en el `setUp` de `UltronStudioPublicationVerifiedTest`: ver la nota 1 del resumen |
| `pint --test` sobre lo nuevo | passed | — |

No he ejecutado nada de `tests/Feature/Marketing` ni la suite entera mientras corría tu batería.

### 3.5 La suite de carreras del H5 en PG

*(pendiente: ver abajo)*

### 3.6 Lo que NO cubre

- Que el escritor rechace **valores** gobernados fuera del catálogo, o `base`/`compiler` de otra versión. No es contrato del escritor: lo cubren `prompts_compile_failed` al publicar y la oferta de `decide`.
- `bundleHash` en mayúsculas (el plan dice «64 hex»).
- `previewHash` con paquete, byte a byte (no hay fórmula contractual).
- La carrera «la llave cambia entre las puertas y la transacción» (§6 de B-S): la transacción usa la decisión de las puertas, en la misma petición. No la he escrito.
- Revertir la `PUBLISHED` con la llave encendida cuando la parte restaurada viene de una entrada `/1` (§6 de B-S): no la he escrito como caso propio. V10 la tocaría por la misma regla.
- E14, el CRM y la suite completa.
