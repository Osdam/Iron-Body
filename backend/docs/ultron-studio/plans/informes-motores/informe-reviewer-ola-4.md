# Informe reviewer · ola 4 (reglas S-NNN en WhatsApp y cierre técnico del H9)

**11-11-2026 · reviewer · árbol `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `19c81b7a` + dos paquetes sin commit.** Solo lectura: no he editado código, no he hecho commit y no he tocado producción ni n8n. El único fichero que escribo es este informe.

## Veredicto

| Paquete | Veredicto |
|---|---|
| 1 · Frases prohibidas de las S-NNN en los prompts de WhatsApp (compilador 2) | **APROBADO CON OBSERVACIONES** (1 importante, no bloqueante) |
| 2 · Huecos de la automatización en sombra (I-1, I-4, I-5, `personBindings`) | **APROBADO** (1 precisión menor del informe) |

No hay bloqueantes.

## Verificado (ejecutado por mí, SQLite, uno cada vez)

- `tests/Feature/Outreach/Run/H9SweepCursorTest.php`: OK, 8 pruebas y 67 aserciones.
- `tests/Unit/UltronStudio/StudioRulePhrasesTest.php`: OK, 21 pruebas y 63 aserciones. Incluye el `specHash` de la voz.
- `tests/Feature/UltronStudio/UltronStudioPublicationRulePhrasesTest.php`: OK, 6 pruebas y 133 aserciones.
- `tests/Feature/Outreach/Run/OutreachApiTest.php`: OK, 7 pruebas y 127 aserciones.
- `tests/Feature/UltronStudio/UltronStudioEngineReadOnlyTest.php`: OK, 6 pruebas y 2 594 aserciones.
- `tests/Feature/UltronStudio/UltronEngineIsolationTest.php`: OK, 11 pruebas y 1 609 aserciones.
- `tests/Feature/UltronStudio/OutreachIsolationTest.php`: 5 pruebas y 1 fallo, el esperado. Es `test_la_puerta_de_diff…`, que lista exactamente `UltronPromptCompiler.php`, `UltronPromptException.php`, `UltronPromptsN8nFlow.php` y `UltronPublishedConfig.php` frente a `origin/main`. Los recorridos en sombra y en vivo de esa misma clase pasan.
- Sonda de solo lectura (arranca la app y no escribe nada) sobre `StudioPromptBundle::compile(governedFrom($content))`:
  - sin reglas → huella Legacy `3f6a5674…`, compilador 2;
  - una S-NNN con «100% gratis» → `InvalidArgumentException` (`invalid_phrases`);
  - 13 frases repartidas entre 2 reglas → `invalid_phrases`;
  - en los dos últimos casos, `phrases()['problems']` dice `charset` y `too_many`.
- `compile-cases.json`, antes y después:
  - los 306 casos son idénticos salvo la quinta clave vacía (huellas, bytes y parches iguales);
  - `limits` no cambia;
  - en la cabecera solo cambian `compiler` y `baseline`;
  - hay 4 casos inválidos nuevos.
- `n8n/flujos/ultron-studio-s1`: solo cambian `compiler` 1 → 2 en los metadatos, el LEEME y el fixture nuevo. `nodes` y `connections` no cambian.

No he lanzado ninguna batería completa, como pedía el encargo.

## Paquete 1: hallazgos

### Importante (no bloqueante)

**1. `app/Services/UltronStudio/StudioPublications.php:1627` (`promptedPart`), con efecto en `:1570-1572` y `:1664-1666`.** La parte del resto que se arrastra desde una cabeza `/1` ahora se compila con el contenido entero de su versión, frases de sus reglas S-NNN incluidas. Antes se compilaba solo con `configs` y siempre compilaba.

- **Cuándo falla:** si esa versión tiene una S-NNN viva cuyo «si» se cumple y alguna frase fuera del juego cerrado, o si entre sus reglas suman más de 12 frases distintas. G admite 10 por regla y texto plano de 2 a 60 caracteres, así que valen ejemplos como «100% gratis», «precio: $50.000» o una frase con comillas o apóstrofo.
- **Qué ve el propietario:** la vista previa de CUALQUIER versión nueva en canario sale bloqueada con el genérico `prompts_compile_failed`, sin `problems` y sin decir que la causa son las reglas de la versión arrastrada. Publicar da 409 `prompts_compile_failed`. Solo el log dice `part: carried, reason: invalid_phrases`.
- **Escenario:**
  1. Con la llave de prompts en `off`, se publica V0, que tiene la S-001 «100% gratis», y se promueve al resto. La cabeza queda en `/1` con `rest` = V0.
  2. Se enciende la llave.
  3. Se previsualiza V1, aunque no tenga reglas → `blocking: [prompts_compile_failed]`.
  4. Antes de este cambio, V1 se publicaba.
- **Nada se recorta:** falla cerrado, no relaja ningún control. Pero es una regresión de disponibilidad con un mensaje que no permite diagnosticar. La salida es «Volver a Legacy», y nada en la pantalla la sugiere.
- **Comprobado:** el camino en el código y la excepción con la sonda. **No** lo he ejecutado de punta a punta por HTTP. Ninguna prueba cubre «resto `/1` arrastrado con frases».
- **No sé si hoy se da en producción:** depende de que la cadena tenga una cabeza `/1` con resto. No lo he comprobado.
- **Corrección mínima:** si `promptedPart` falla con `invalid_phrases`, devolver `prompts_rule_phrases_invalid` con los `problems` de la versión arrastrada (`StudioPromptBundle::phrases($version->content)`, marcados como del resto), tanto en la vista previa como en el 409. Más una prueba HTTP del escenario. Si se prefiere arrastrar un `/1` SIN compilar sus frases, es una decisión de diseño y la tiene que tomar `architect`.
- **Asignado a:** `backend`.

### Comprobado y correcto

- **Compatibilidad con cero frases:**
  - `patchesOf(PHRASES, [])` no da ningún parche y la huella Legacy y la de «profesional + 200» no cambian (probado);
  - `isBaseline` sigue siendo exacto: `validate()` devuelve el orden de `BASELINE`;
  - solo cambian `compiler: 2` y la quinta clave vacía en lo firmado, la vista previa (`phrases` es aditivo) y `previewHash`.
- **Lo firmado con el compilador 1:**
  - el lector lo valida con su forma, cuatro claves (`UltronPublishedConfig.php:614-633`);
  - la oferta da `compiler_changed` → Legacy, sin lanzar;
  - una cadena mixta (canario con el 2, resto con el 1) se lee: cada parte se valida con su compilador, también en `StudioPublicationDeclaration::assertPrompted`;
  - promover y revertir copian las partes tal cual (`promptedForMove`), así que una parte del 1 sigue en `compiler_changed`. Está documentado.
- **Código viejo leyendo el compilador 2:**
  - el lector viejo exige exactamente cuatro claves → `parse()` null → `malformed` → la oferta da `publication_malformed`, que es Legacy;
  - el monitor M1-A solo alerta con `mismatch` o `legacy_drift`, que no se producen.
- **Nada se publica con frases descartadas:**
  - publicar pasa por `promptedForPublish`, que lanza;
  - promover y revertir no recompilan;
  - la parte arrastrada `/1` se compila entera o falla (es el hallazgo 1);
  - `phrases()['problems']` no está vacío exactamente cuando `compile()` fallaría por las frases: el orden siempre se cumple, porque `sorted()` usa `SORT_STRING` y el compilador `strcmp`, y los dos comparan por bytes.
- **Voz:** `forbiddenPhrases()` sigue siendo equivalente.
  - Mismo filtro, misma canonización, mismo deduplicado y orden, y el mismo `rule_phrase_invalid` en la misma regla.
  - `StudioLabChecks::check` es pura y no lanza, así que el cambio del orden de evaluación no altera el resultado.
  - La prueba del `specHash` pasa.
- **Guardas:** de los paquetes, solo se ha modificado `OutreachIsolationTest.php` (paquete 2, abajo); ninguna `*Isolation*`, `*ReadOnly*` ni `*StaticGuards*` más. Las del motor pasan.
- **Pruebas antiguas:**
  - las aserciones ajustadas siguen comparando bloques enteros: la clave nueva va dentro del `assertSame` del bloque;
  - el caso «compilador cambiado» pasa a usar una parte real del compilador 1, y el 3 queda cubierto en la oferta pura;
  - no he visto ninguna aserción debilitada.
- **`PermissionCatalog.php:240`:** el texto ya no es falso y ningún fixture lo fija (grep en el repositorio, CRM incluido).

### Notas para el lead y el propietario (no son defectos)

- **Cambio de publicabilidad, por diseño (opción A):** con la llave de prompts encendida, una versión con más de 12 frases distintas o con una frase con `% $ ' : " /`, un emoji, etc. deja de poderse publicar (409 visible). Antes se publicaba con la regla en `noEffect`. Es lo aprobado («nada se recorta»), pero conviene decírselo al propietario así: no solo hay un efecto nuevo, también una restricción nueva.
- **Vuelta atrás:** el orden importa, como dice el autor: primero «Volver a Legacy» y después el código. **No verificado:** si el código viejo se despliega antes, su Studio no lee una cabeza con cinco claves (`assertPrompted` exige cuatro) y no sé si puede escribir «Volver a Legacy» sobre ella. El motor cae a Legacy en cualquier caso.
- `smoke-h9.py` ya exige `compiler == 2` en el árbol: falla contra producción hasta desplegar este backend.

## Paquete 2: hallazgos

### Menor

**2. Precisión del informe del autor (§2, «compatibility() no mira ninguno de los dos… En ejecución nada compara…»).**

- Es cierto para `compatibility()`. Pero en ejecución, `OutreachPublishedStrategies.php:515` relee cada versión con `StrategyContent::fromStored()`, que vuelve a validar el disparador (`StrategyContent.php:245-246`, `trigger_not_allowed`).
- Así que una versión ya guardada con uno de los seis eventos como disparador se lee como `catalog_incompatible`: N3 la niega.
- Hoy no tiene efecto: hay 0 estrategias [P], los eventos no se emiten y falla cerrado. Pero la frase «una versión hecha con uno de esos disparadores sigue compatible» induce a error.
- **Corrección:** solo documental, en el informe o el runbook. No hace falta cambiar código.
- **Asignado a:** `backend`.

### Comprobado y correcto

- **I-1:**
  - la condición de N3 no cambia; solo cambia su destino según `$sombra`, que es exactamente `mode === 'shadow'`;
  - comprobé con `php -r` que la asignación dentro del ternario de PHP hace lo que parece;
  - fuera de la sombra la salida es idéntica (las pruebas comparan la salida entera en los cuatro canales y las mutaciones M1 y M2 mueren);
  - el cambio en `OutreachIsolationTest.php:552-576` es legítimo: la aserción sigue siendo exacta por modo (nivel, motivos y avisos) y añade aserciones, no las quita.
- **I-4:**
  - la posición solo se escribe en `complete()`; las pasadas `failed`, `aborted` y `running` no cuentan;
  - va por estrategia, disparador y modo;
  - una posición ilegible empieza de nuevo: se repasa, nunca se salta;
  - la página vacía al final de una vuelta empieza la siguiente en la misma pasada, sin evaluar a nadie dos veces dentro de una vuelta;
  - dos barridos a la vez evalúan la misma página, y la UNIQUE de la ocurrencia lo hace inocuo;
  - en las clases, `(session_date, class_id)` es único por ocurrencia (una por clase y fecha), el `<=>` de PHP sobre `['Y-m-d', int]` coincide con el `usort` de `ClassFacts:184` y los socios van por `users.id`: el orden es total y estable;
  - la posición no sale por `status` (`publicCounts`), por `sweep()` ni `--json`, ni por el stream (`OutreachRunsTopic` no selecciona `counts`). Solo la copia `h9-e2e/recorridos-h9.cjs`, que el autor ya señaló.
- **I-5:**
  - los seis pasan a `available` y `triggerable` en `false`, y siguen con `conditionUsable`;
  - la huella está atada a la versión en `OutreachCatalogTest`;
  - el validador y el constructor siguen siendo coherentes (`StrategyContent.php:245` exige los dos).
- **`personBindings`:**
  - son dos `count` agregados, sin datos personales, detrás de `commercial_automation.view`, y el puente sigue siendo el único lector (la guarda L9-51 está verde);
  - dos consultas más por petición de estado es aceptable: el estado ya hace bastantes más.
- **Entre los dos paquetes:** no comparten ficheros (`OutreachIsolationTest.php` solo lo toca el 2, `PermissionCatalog.php` solo el 1 y los fixtures son disjuntos). La única interacción es la puerta de diff roja, que es la esperada.

### Notas (residuales, no son regresiones)

- **Disparadores con ventana** (`membership.expiring_window`, etc.): quien entra en la ventana con un id por debajo de la posición espera a la vuelta siguiente. Si una vuelta dura más que la ventana, se lo puede perder en esa ventana. Antes ni se llegaba a él, así que mejora, pero el volumen está sin medir.
- **`OutreachRuns::boundedCounts` (`:146`):** si los recuentos pasaran de 4 KB después de colapsar las listas, el último recorte descartaría `cursorFrom` y `cursorNext`, y esa estrategia volvería a empezar la vuelta. Con las claves escalares de hoy no se alcanza; no lo he forzado.

## Lo que NO he podido verificar

- El hallazgo 1 de punta a punta por HTTP: solo el camino en el código y la sonda del compilador. Tampoco si la cadena de producción tiene hoy una cabeza `/1` con resto.
- Nada contra producción, n8n ni el CRM. Lo que el lead comprobó en producción (comprobador OK 121 y tablas del H9 vacías) lo tomo como [P].
- Las pruebas en PostgreSQL, las mutaciones de los autores y las baterías completas.
- El comportamiento real del modelo con las frases: es probabilístico.
