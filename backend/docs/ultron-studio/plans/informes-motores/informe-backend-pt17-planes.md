# Informe de backend · PT-17: el compilador 5, las instrucciones firmadas del turno de planes

**22-11-2026 · rama `cierre/planes-c5` (desde `feat/ultron-studio-h9` `f83368c9`; su código de producto es el de producción, `f1fe6cac`) · SIN desplegar, SIN publicar, sin push.** Diseño: `diseno-arq-pt17-planes.md`. Enmienda: A4-5 de `ULTRON_STUDIO_H5_CONTRACT.md`. **[C]** comprobado ejecutando · **[L]** leído.

**Quién hizo qué.** El agente de backend (`be-planes`) escribió los seis commits de código, pruebas y doradas y la enmienda A4-5. Se quedó bloqueado más de una hora esperando una aprobación de permisos para confirmar la enmienda (la orden de commit con el mensaje por la entrada estándar), sin llegar a escribir este informe. El lead lo cerró, confirmó la enmienda tal como la dejó, **repitió él mismo toda la verificación final** y escribió este informe. Lo que la enmienda dice de la sonda de Legacy («79 de 80 salidas idénticas») es del agente y el lead NO lo ha repetido: queda para seguridad (abajo).

## Commits
| Commit | Qué |
|---|---|
| `298ff261` | N1 a N4 (`UltronPromptCompiler5Test.php` y un caso en `UltronPublishedConfigPhrasesTest.php`), en rojo con el compilador 4 |
| `4ef5d4cf` | El compilador 5: `UltronPromptCompiler::VERSION = 5`, las cinco anclas nuevas en `ANCHORS`, los seis textos y `enginePatches()` con once entradas. **Único fichero de producto** |
| `25afd240` | `STUDIO_MOTORES_PLAN.md` §B.3 «Compilador 5», la transcripción independiente de Node (`parches-m1.cjs`) y `compile-cases.json` regenerado con `derivar-compile-cases.cjs` |
| `e7755e87`, `3763d992` | Las fijaciones del compilador 4 y de la huella `26d57565…` pasan al 5 y a `2eb9be6f…`, con los valores de los generadores |
| `56518135` | Los flujos versionados S1 y S2 regenerados con `php artisan ultron:prompts-n8n` (y `--rev=S2`): solo cambia `_generado.compiler` y dos fixtures de `decide` |
| (este) | La enmienda A4-5 y este informe |

Total frente a `f83368c9`: 30 ficheros de código, pruebas, doradas y flujos, +1 202 / −550. De producto, uno: `app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php`.

## Verificación del lead (22-11, 19:59–20:02 UTC; SQLite)
- `tests/Unit/Marketing/Ultron/Prompts`: `OK (876 tests, 37403 assertions)` (antes de PT-17: 871) [C].
- Las 15 clases que fijan el compilador, la huella o los flujos (`grep` de `UltronPromptCompiler::VERSION`, `26d57565`, `2eb9be6f`, `compile-cases`, `prompts-n8n`, `compiler_changed`, `_generado`): `OK (1164 tests, 28211 assertions)` [C].
- **N1 a N4 caen con el compilador 4** [C]: el commit `298ff261` (las pruebas, con `VERSION = 4`), en otro árbol: `Tests: 29, Assertions: 124, Failures: 4` (N1, N2 y N4 en `UltronPromptCompiler5Test` y N3 en `UltronPublishedConfigPhrasesTest`). Con el compilador 5, en verde dentro de las 876.
- **Huella de la versión publicada (profesional · 200) con el compilador 5:** `2eb9be6f5d3e6b509b3410457b291c7d582aa73be08f0c9dd00f42229bb909c0`, 120 097 B, 21 parches, **la misma en PHP y en el generador de Node** (`derivar-compile-cases.cjs`: 306 casos, 30 inválidos, 63 textos) y la que simuló el arquitecto [C]. Huella Legacy, sin cambios: `3f6a5674…c902` [C].
- **La base firmada:** `git diff f83368c9..HEAD -- backend/resources/ultron-prompts` vacío [C].
- **Los flujos de n8n:** `verificar-flujo.cjs` sobre S1, `OK (121 comprobaciones)`; con `--rev=S2` sobre S2, `OK (226 comprobaciones)`. Comparados nodo a nodo con los de `f83368c9`: 0 nodos distintos, las mismas conexiones; solo cambia la clave `_generado` [C]. **n8n no se reimporta.**
- **Guardas** (149 pruebas): una sola en rojo, la puerta de diff de `OutreachIsolationTest`, con `Prompts/UltronPromptCompiler.php` (lo previsto en A45-9; pasa sola al subir) [C].
- El árbol, limpio tras regenerar con Node (la dorada regenerada es idéntica a la confirmada) [C].

## Bytes de los textos (del diseño; el tope por texto es 1 200)
Estratega: 784 y 158 · redactores: 1 165, 165, 220 y 135 · revisores: 451 (sin cambios).

## Lo que NO se ha verificado
- La batería completa (la lanza el lead sobre la punta de esta rama).
- La sonda de aislamiento de Legacy que cita la enmienda (79 de 80 salidas idénticas; la diferencia, el valor `studio_prompts.compiler` 4 → 5 en la respuesta de `decide` a quien negocia prompts): el lead no la repitió.
- El borrador de 175 caracteres del diseño por `ai/check` y por el commit: lo afirma la enmienda (A45-12); cae dentro de las 1 164 pruebas solo si el agente lo dejó como prueba: que lo confirme el revisor.
- Nada con un modelo real: estas pruebas demuestran que el paquete lleva lo pedido y que Legacy no se mueve, no que el modelo obedezca. Eso se mide en el laboratorio del motor (A45-10).
- PostgreSQL: el cambio no toca la base de datos; no se repitió allí.

## Para seguridad (una pasada)
1. Que ningún texto nuevo lleva un dato del catálogo ni ensancha la excepción C1 (el presupuesto va en PALABRAS, fijo).
2. Que Legacy no cambia: repetir la sonda de `decide`, `ai/check` y el commit en una conversación que no es del Studio, y decidir sobre el valor `studio_prompts.compiler` que `decide` devuelve (4 → 5).
3. La superficie de las frases prohibidas con las cinco anclas nuevas (una frase del personal no puede contener un ancla).

## Para el revisor final
1. Los seis textos frente a la evidencia `a4-evidencia/164` y al diseño (literales).
2. Que cada dorada se regeneró con su generador y ninguna aserción se aflojó (los dos commits de fijaciones).
3. Que el revisor del motor (`T_PLANS_CRITIC`) queda byte a byte y que la línea base sigue dando la huella Legacy.
4. El orden de las once entradas de `enginePatches()` y que cada ancla aparece una sola vez en su papel.

## Lo que viene después (no es de esta rama)
Integrar con el lote 5: `StudioLabEngineFlow::COMPILER = 5` y su prueba de paridad (punto 10 del diseño). Desplegar, publicar una versión nueva y medir: HUMAN GATE.
