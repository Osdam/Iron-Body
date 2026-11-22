# Informe del backend · Apartado 4 · «Regenerar antes de sustituir», olas 1 y 2

19-11-2026 · rama `feat/ultron-studio-h9`, HEAD `97b59195` · **sin commit**: todo está en el árbol de trabajo, nada desplegado, n8n en marcha sin tocar (ni MCP ni API).
Guía: el encargo del lead (`encargo-be-r1.md`) y `plan-arquitecto-a4-regenerar.md` §0, §1, §3, §4, §6 (olas 1 y 2), §7 y §13. Precedente de forma: `informe-backend-a4-compilador4.md`.
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto o no comprobado.

---

## 0. Resumen

- **Ola 1 (piezas puras, sin cablear):** cuatro clases nuevas en `app/Services/Marketing/Ultron/` (`UltronPlansOverview`, `UltronBenefitAttribution`, `UltronRegenerationNotes`, `UltronDraftDefects`), `UltronDecideToken::peek()` y la extracción de la selección de `respaldoDePrecios()` a `UltronPlansOverview::select()` sin cambio de comportamiento. Nadie llama todavía a las piezas nuevas (salvo `select()`, que llama la lista fija). **Ninguna prueba existente cambia** en esta ola; las dos A4, sus documentos generados y las dos instantáneas Legacy, idénticos **[C]**.
- **Ola 2 (compilador 4):** el parche del **motor** de la lista de planes (`engine.plansOverview`, los dos textos de §7.1 literales), al final y solo si la versión ya tiene algún parche gobernado; `sp.o = 1` en la oferta. `VERSION` sigue en 4. La línea base sigue siendo la base byte a byte con la huella Legacy `3f6a5674…c902` **[C]**. Se mueven **todas** las huellas no base: **305 de 306** casos de la rejilla, cada uno con +2 300 B **[C]**. Los tres ficheros que se ejecutan en n8n **no cambian ni un byte** (sha256 antes y después) **[C]**.
- **Topes, en el peor caso** (profesional, sin emojis, un tope, `venta` y 12 frases de 60 caracteres de 2 bytes): paquete **125 903 B** (tope 163 840), rol más grande **30 876 B** (`composer`; tope 40 960), textos del motor **697 y 451 B** (tope 1 200). Cabe; calculado por dos vías (PHP y Node sin PHP), iguales al byte **[C]**.
- **Pruebas:** rojo antes de cada ola (88: 47 pruebas, 43 errores y 4 fallos; 90: 11 pruebas, 3 errores y 5 fallos) y verde después: motor 1 614 OK (92), Studio 3 769 con solo la roja esperada de la puerta de diff (93), Marketing 6 566 OK (94), guardas 141 con la misma roja (95). Aserciones existentes cambiadas: ninguna en la ola 1; 55 en 9 ficheros de prueba y una en `verificar-flujo.cjs` en la ola 2 (§2.5), ninguna relajada. Detalle en §1.6 y §2.7.

---

## 1. Ola 1

### 1.1 Ficheros tocados

| Fichero | Cambio | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/UltronPlansOverview.php` | nuevo, 340 líneas | Selección, marcadores, lista sin precio, texto de cada plan, expansión y `verify()` |
| `app/Services/Marketing/Ultron/UltronBenefitAttribution.php` | nuevo, 158 | P-4, la regla literal y por frase |
| `app/Services/Marketing/Ultron/UltronRegenerationNotes.php` | nuevo, 255 | Códigos cerrados, `issue`, las notas literales de §7.2 y la mezcla a 500 |
| `app/Services/Marketing/Ultron/UltronDraftDefects.php` | nuevo, 141 | La lista ordenada de defectos de un borrador |
| `app/Services/Marketing/Ultron/UltronDecideToken.php` | +57/−0 | `peek()`; `verify()` sin una línea cambiada (`git diff` sin líneas quitadas) **[C]** |
| `app/Services/Marketing/Ultron/UltronCommitService.php` | +4/−14 | `respaldoDePrecios()` llama a `UltronPlansOverview::candidates()` y `::select()`; nada más del fichero |
| 6 pruebas nuevas en `tests/Unit/Marketing/Ultron/` | nuevas | §1.5 |

### 1.2 Qué hace cada pieza

**`UltronPlansOverview`** (pura; el catálogo y los `Plan` los pasa quien llama):
- `candidates($policy, $activePlans)`: el catálogo sin los rechazados (`forbidden_plan_ids`) salvo el nombrado (`hard_required_plan`). Es el `$catalogo` de antes; la lista fija lo sigue usando para «También hay…» y los encabezados.
- `select($policy, $activePlans): list<int>`: la selección de `respaldoDePrecios()` (`97b59195`, :5449-5466), **extraída tal cual**: el nombrado; después los de un mes en el orden del catálogo; como mucho tres; sin ninguno de un mes, los tres primeros. La paridad la fija un oráculo congelado en la prueba (§1.5).
- `marker(k)` / `markers(n)`: `{{PLAN_1}}`…`{{PLAN_3}}`. Casan con el patrón de `UltronDraftPlaceholders` (la prueba compara la constante privada por reflexión y comprueba que `found()` los ve; el de dos puntos, no).
- `items($po, $activePlans)`: lo que verá el modelo (`plans_overview.plans` de la ola 3): `{marker, plan_id, name, duration_days}`, **sin precio**, en el orden de `po`. Un id que no está en el catálogo lanza (es un error de quien llama).
- `item(Plan)`: «Nombre ($65.000 COP)»; si no es de 28 a 31 días, «Nombre (7 días, $45.000 COP)», «1 día» en singular. Precio con `formatCop()` (el de la lista fija). Lee los atributos del `Plan` que se le pasa; no consulta.
- `expand($draft, $textos, $po): {text, used}`: sustituye cada `{{PLAN_k}}` de la lista (también con espacios dentro de las llaves); lo demás queda literal; `used` = los planes sustituidos por orden de aparición, sin repetir.
- `verify($raw, $po, $activePlans): ?string`: uno de los seis detalles de §4.4 (`missing`, `mixed`, `name_written`, `unknown_marker`, `no_list`, `price_written`) o `null`. Sobre el borrador **sin expandir**. Que los planes sigan vendibles ahora (H14) no es de aquí: no lo arregla una reescritura; lo decidirá el commit (ola 3).

**`UltronBenefitAttribution::misattributed($texto, $activePlans)`** (P-4): en cada frase del texto expandido (`UltronReplyFitter::sentences()`) que nombra exactamente un plan (`plansNamedIn()`; la expansión empieza por el nombre), sin negación (`no`, `sin`, `ni`, `excepto`, `salvo`, por palabra completa), un beneficio de **otro** plan de al menos dos palabras, por palabras completas sin tildes ni mayúsculas, que ningún beneficio del plan nombrado contiene ni está contenido en él. Devuelve `{plan_id, benefit_plan_id, benefit_index, benefit}`, sin repetir. Límites declarados en el docblock y fijados como «no marca» en la prueba: paráfrasis, pronombre o elipsis en la frase siguiente, frases con dos planes, beneficios inventados, beneficios de una palabra.

**`UltronRegenerationNotes`**: `CODES` (`register_tuteo`, `length_over_cap`, `plans_overview`, `benefit_misattributed`), `DETAILS` (los seis), `issue()`, `note()`, `merge()` y `entries()`. **El mecanismo es una tabla** (`código` o `código/detalle` → `[issue, texto, parámetros con su tipo]`): los cinco códigos de política de la ola 4b son cinco entradas más; no los he añadido. Los textos son los de §7.2 **literales** (extraídos del plan por guion, no tecleados; la prueba los tiene copiados a mano). `issue`: tuteo → `naturalness`; longitud → `progressive_disclosure`; panorama → `relevance` (`price_written` → `factuality`); beneficio → `factuality`.

**`UltronDraftDefects::of($draft, $expanded, $sp, $po, $catalog, $inbound)`**: la lista ordenada (a) `plans_overview` → (b) `register_tuteo` → (c) `length_over_cap` → (d) `benefit_misattributed`, uno por código, cada uno `{code, detail, issue, note}`. Con las mismas funciones que el commit: (a) solo con `po`, sin herramientas y con la condición de `:1727-1729` (intención de precio, `preguntaPorPrecio()` o menos de dos planes nombrados en el expandido); sin `po`, `no_list` si aparece un `PLAN_k` (también con herramientas); (b) `UltronFormalReplies::aplica(sp.t)` y `UltronRegisterDetector::tuteo(expandido)`; (c) `UltronReplyFitter::cap(sp.n)` y `measure(expandido)`, con `M = composerTargetChars(N)`; (d) la primera atribución. Sin `sp.m = studio`, `[]`.

**`UltronDecideToken::peek($token, $c, $m)`**: lo mismo que `verify()` hasta el mensaje, en el mismo orden y con los mismos motivos (que venga, forma, firma, caducidad, conversación, mensaje), y devuelve `{valid, reason, extra, issued_at}`. No recibe ni mira fase, transiciones ni conocimiento (lo fija una prueba estática sobre su cuerpo). Registra el motivo del rechazo como `verify()` (el mismo `fail()`), nunca el token.

### 1.3 Decisiones mías (el plan no las fija)

1. **Precedencia de `verify()`** (devuelve uno): primero lo que en el commit acaba en 422 y rendición, `unknown_marker` y `price_written`; después la estructura de la lista en el orden de §4.4: `mixed`, `name_written`, `missing`. Sin lista, solo `no_list`.
2. **Qué es «un `PLAN_k`»:** cualquier marcador `PLAN_` + cifras, sin distinguir mayúsculas. Con lista, `PLAN_0`, `PLAN_01`, `plan_1` y `PLAN_k` con `k > n` son `unknown_marker` (el validador de la ola 3 solo admitirá `PLAN_1..n` exactos). Sin lista, `no_list`.
3. **Palabras de la duración en `item()`:** las de la lista fija (`UltronDraftPlaceholders`: «tres meses», «seis meses», «un año», «N días» para el resto, «1 día» por F5), no siempre «N días». El encargo pone «7 días» como ejemplo y el plan remite a «la regla F5 de `respaldoDePrecios()`». Si el propietario prefiere siempre «N días» o el formato de la lista fija («Nombre, $65.000 COP»), es `item()`. La duración va **por plan** (la lista fija la pone a todos o a ninguno).
4. **`item()` recibe un `Plan`** y lee sus atributos; no consulta. Reutiliza `formatCop()` y la duración de `UltronDraftPlaceholders`, en lugar de duplicarlas.
5. **Notas:** la mezcla une con un espacio y, si una nota no cabe en 500, se deja fuera entera y se prueba la siguiente. `MARCAS` solo admite las de la lista cerrada del detector, sin repetir, como mucho 5, separadas por «, ». Los textos del catálogo (`PLAN`, `BENEFICIO`) van sin llaves, sin control ni espacios dobles, con `"` cambiada por `'` (para no cerrar la cita de N-B) y a 60 caracteres. Un parámetro que falta, sobra o no es del tipo lanza `InvalidArgumentException` (error de quien llama, nunca dato de la persona).
6. **N-B:** `PLAN` es el marcador sin llaves (`PLAN_1`) si la frase nombra el plan por su marcador en el borrador; si no, el nombre del catálogo.
7. **`peek()`:** un token firmado sin `i` entero es `decide_token_malformed` (no lo emite nadie hoy; no se adivina un instante).
8. **La prueba de pureza** prohíbe en las cuatro clases nuevas, sobre el código sin comentarios: fachadas (`DB::`, `Log::`, `Cache::`…), `Carbon`, `ChannelLog`, consultas y escrituras de Eloquent, `mt_rand`, y las funciones globales `now()`, `config()`, `app()`, `env()`, `time()`, `date()`, etc.

### 1.4 Aserciones existentes cambiadas en la ola 1

**Ninguna.** `UltronA4LongitudYPreciosTest` y `UltronA4RegistroYPanoramaTest` pasan sin tocarlas, y comparan contra `a4-textos-ajustados.md` y `a4-textos-usted.md`, que siguen idénticos; `tests/fixtures/ultron-a4/legacy-a4.json` y `legacy-usted.json`, sin regenerar (`git status` limpio en esas rutas) **[C]**.

### 1.5 Pruebas nuevas

| Fichero | Pruebas | Qué fija |
|---|---|---|
| `UltronPlansOverviewTest` | 10 | **Paridad** de `select()` y `candidates()` con un oráculo (la selección de `97b59195` copiada letra a letra) sobre 9 casos con nombre y una matriz de **4 000 catálogos** (0 a 7 planes, duraciones de 1 a 365, nombrado dentro, fuera, rechazado o 0, ids como texto, rechazados como escalar; semilla fija; la prueba exige que la matriz cubra «sin planes de un mes», «más de tres de un mes», «nombrado y rechazado» y «vacía»); que `respaldoDePrecios()` llama a `select()` y no tiene otra selección; marcadores y patrón; `items()` sin precio; `item()` con duración, «1 día» y `formatCop()`; `expand()`; cada detalle de `verify()` y su precedencia |
| `UltronBenefitAttributionTest` | 9 | Propio, de otro plan (dueño e índice), tildes y mayúsculas, palabra completa, por la expansión, compartido o contenido, negación (las cinco y «NO»), los límites declarados, varias frases sin repetir, catálogo raro |
| `UltronRegenerationNotesTest` | 9 | Listas cerradas; cada código da un `issue` de `DIMENSIONS`; **las nueve notas literales** (copiadas a mano de §7.2); marcas cerradas; texto sin llaves y a 60; parámetros exactos; **ninguna nota contiene `{{` ni llave** con parámetros hostiles; la mezcla a 500 por notas enteras; cada entrada declara exactamente los huecos de su texto |
| `UltronDraftDefectsTest` | 10 | Sin defectos; sin Studio, ninguno; el orden de los cuatro; cuándo se mira la lista; cada detalle con su nota y `K = count(po)`; `no_list` con y sin herramientas; el tuteo solo con `profesional` (la cita de la persona no cuenta); la longitud sobre el expandido con `L`, `N` y `M`; el beneficio con marcador o con nombre; un entrante con nombre y teléfono que no sale en ninguna nota |
| `UltronDecideTokenPeekTest` | 6 | Carga e instante; carga vacía; no mira fase, transiciones ni conocimiento (y `verify()` sí, igual que antes); los 8 rechazos con su motivo y sin carga; caducidad a los 120 s justos; token firmado sin `i` |
| `UltronRegenerarPiezasPurasTest` | 3 | **Ninguna expresión nueva lleva `\p{…}`, `\P{…}`, `\pL` ni `\X`** (las cuatro clases y el token); las cuatro clases son puras; `peek()` no mira el mundo |

**Mutaciones a mano** (cada una deshecha y comprobada con `cmp`) **[C]**: `select()` con `<=` en el tope de tres → 2 fallos; quitar `no` de las negaciones → 1 fallo; una mayúscula en N-P3 → 1 fallo.

### 1.6 Números

| # | Qué | Resultado |
|---|---|---|
| 88 | Las seis pruebas nuevas **antes** de escribir las clases (`app/` = HEAD) | **ROJO:** 47 pruebas, 43 errores, 4 fallos |
| — | Las mismas, después | 47/47, 8 418 aserciones |
| 89 | Motor al final de la ola 1: `tests/Unit/Marketing/Ultron`, las dos A4, `UltronDecidePromptsTest`, `UltronCommitTest`, `UltronCommitPromptsTest`, `UltronPromptsTraceCommandTest` | **OK (1 603 pruebas, 25 108 aserciones)** |

### 1.7 Lo que no comprobé (ola 1)

- **Una laguna del plan, sin resolver (la sigo al pie de la letra):** §4.1 (a) solo mira la lista con la condición del panorama. Si la persona pide el panorama sin precio y el borrador lleva `{{PLAN_1}}`, `{{PLAN_2}}` y `{{PLAN_4}}`, nombra dos planes en el expandido, la condición no se cumple y la comprobación no dice nada; el commit dará `unknown_placeholder` (422 → rendición). Con `po` y un `PLAN_k` inválido, convendría `unknown_marker` siempre, como `no_list` sin lista. Lo decide quien haga la ola 4.
- Las piezas no están cableadas: su efecto real (en el commit y en `ai/check`) es de las olas 3 y 4.
- La sonda de PCRE de producción no la he ejecutado (la ejecuta el lead). Las expresiones nuevas, para la sonda: `/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/` (la de siempre), `/\APLAN_[0-9]+\z/i`, `/\APLAN_([1-9][0-9]*)\z/`, `/\{\{\s*PLAN_k\s*\}\}/` (k de 1 a 3), `/[a-z0-9]+/` y `/[\x00-\x1F\x7F\s]+/u`.

---

## 2. Ola 2

### 2.1 Ficheros tocados

| Fichero | Cambio | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php` | +80/−3 | `ENGINE = 'engine.plansOverview'`; `T_PLANS_COMPOSER` y `T_PLANS_CRITIC` (§7.1, extraídos del plan por guion, byte a byte); `enginePatches()`; `patches()` los añade **al final** y **solo si `$parches !== []`**, con el mismo control de tamaño y saltos; docblock de la clase («EL PARCHE DEL MOTOR», el orden de aplicación), de `VERSION` (sin subir a 5) y de `patches()` (la vista previa enseña los gobernados, no los del motor) |
| `app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php` | +16/−2 | `sp['o'] = 1` si el paquete compilado trae parches de `ENGINE`, tras `n` y `t`, solo en `studio`; docblocks de `forConversation()` y `sp()` |
| `app/Services/UltronStudio/StudioPromptBundle.php` | **sin tocar** | `changes()` sigue agrupando por `PARAMETERS`: la vista previa no enseña el del motor; lo fija una prueba |
| `docs/ultron-studio/plans/STUDIO_MOTORES_PLAN.md` | +9/−1 | §B.3, apartado «Compilador 4»: viñeta nueva «El parche del motor: la lista de planes» con los dos textos entre `«…»`, **pendientes de la aprobación del propietario (gate 4-H)**, cuándo, dónde, medidas y lo que mueve. Y una precisión en «Lo que no cambia», que decía que las huellas de `cercano` y `entusiasta` sin frases eran las del compilador 3: ya no |
| `tests/fixtures/ultron-prompts/tools/parches-m1.cjs` | +20/−2 | `ENGINE`, los dos textos (`engine.plansOverview.composer` y `.critic`), su aplicación al final si hay alguno, y `DEL_COMPILADOR_4` los exige **dentro** del apartado «Compilador 4» |
| `tests/fixtures/ultron-prompts/compile-cases.json` | regenerado | §2.4 |
| `tests/fixtures/ultron-prompts/compile-cases.README.md` | +6/−2 | Apartado «Compilador 4, el parche del motor» y cifras de control |
| `tests/fixtures/ultron-studio/publication-cases-v2.json` | regenerado | 55 hojas, todas por la huella (§2.4) |
| `../n8n/flujos/ultron-studio-s1/fixtures/decide-studio.json` y `decide-studio-frases.json` | regenerados | `bundle_hash` y los cuatro roles con el motor; nada más |
| `docs/ultron-studio/ops/motores/verificar-flujo.cjs` | +6/−3 | Una aserción de F (§2.5) y su docblock |
| Pruebas: 2 nuevas y 9 puestas al día | | §2.5 y §2.6 |

### 2.2 El texto compilado

Tal como queda **[C]**: los cuatro parches del motor van los **últimos**, así que cada uno queda pegado a su ancla. Con tono, sin emojis, tope y frases:
- **Composer y Composer Reintento:** `FORMA: …` → `LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview …` → `FRASES PROHIBIDAS PUBLICADAS: …` → `TOPE DE LONGITUD PUBLICADO: …`.
- **Critic y Critic 2:** `progressive_disclosure: …` → `LISTA DE PLANES DEL BACKEND: los marcadores PLAN_1, PLAN_2 y PLAN_3 …` → `TOPE PUBLICADO: …`. Las frases siguen pegadas a `naturalness:`.
- Un cambio solo del objetivo (`venta`, `informar`) también lleva los cuatro del motor: hoy tocaba solo el Strategist.

Los dos textos son ASCII imprimible, de una línea, sin llaves, sin comillas y sin ningún ancla **[C]**.

### 2.3 Los topes

| Medida | Valor | Tope |
|---|---|---|
| `T_PLANS_COMPOSER` / `T_PLANS_CRITIC` | 697 / 451 B | 1 200 por parche |
| Peor caso (profesional, sin emojis, 480, `venta`, 12 frases de 60 × 2 B): por rol | strategist 29 570 · composer **30 876** · critic 18 519 · composer_retry 28 767 · critic_retry 18 171 | 40 960 |
| Peor caso: paquete | **125 903 B** | 163 840 |
| Peor caso: parches | 23 (tono 4, emojis 4, tope 6, objetivo 1, frases 4, motor 4); los de frases, 1 887 y 1 932 B (tope 2 048, sin cambio) | — |
| Rejilla de 306 sin frases | paquete máximo 118 261 B; rol máximo 29 570 B; hasta 19 parches | — |

El peor caso lo calculé además en Node, sin PHP (la base, `parches-m1.cjs`, los textos fijos de las frases de §B.3 y los dos de §7.1): los mismos bytes por rol y en total **[C]**. **Cabe; no hubo que parar.**

### 2.4 Las huellas que se mueven

**Todas las no base, como prevé el plan (R7):** 305 de los 306 casos de `compile-cases.json`; c000 (Legacy) igual **[C]** (comparación caso a caso contra la copia de HEAD, guardada antes de regenerar). Cada caso que se mueve crece **2 300 B** (dos Composer × (697 + 1) y dos Critic × (451 + 1)) y 4 parches. En 2 cambia además `changedRoles` (`goal=venta` y `goal=informar` solos). Cambian solo los textos nuevos `engine.plansOverview.*`; los 30 inválidos y el marco, iguales. La lista completa: `a4-evidencia/96-regenerar-huellas-que-se-mueven.txt`.

| Caso | Gobernados | Antes (compilador 4 sin el motor) | Después |
|---|---|---|---|
| c000 | cercano · true · 320 · cita (Legacy) | `3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902` (112 216 B) | **igual** |
| c001 | cercano · true · 160 · cita | `bedc1c5d…f294` (113 428 B) | `028bc1eda8fa852ff9cd0a268cd30e7be1529088d993259215fe77bfd8b4e585` (115 728 B) |
| c020 | profesional · true · 320 · cita | `8f1d6050…1d98` (114 234 B) | `e94c9b8ee8e233f8a208e00ee418d3c81194039a1209eb69289f8642ba6bd124` (116 534 B) |
| **c074** | **profesional · true · 200 · cita (G8)** | **`7b09b89c98d48b01561b9cbc07e048c152c0af6a4ba7f583becd49f8e7e8cc35` (115 446 B, 10 parches)** | **`26d57565894df0fb9885d73be4c4aba818f16529bfb0fe4ae2e4418c5e1b1075` (117 746 B, 14 parches)** |
| c243 | profesional · false · 160 · informar | `2a5954a2…78d3` (115 814 B) | `239e26612c3252c834ac1b0752d8946a7781338b7c98d962a2939526f33a702f` (118 114 B) |
| c304 | entusiasta · false · 480 · venta | `7ea13909…3be5` (114 555 B) | `10417eaa561c9efcad29a2d96d9e6d7daf42dcbf77e803454d3a2521cdf4a21e` (116 855 B) |
| — | línea base + «gratis», «te lo regalo» | `5a3957ee…6df8` (114 010 B) | `705b65c9932fa650db57d110669cf29542dbd7003302631c2bc8eda07f3fbe2e` (116 310 B; calculada también en Node) |
| — | G8 + seis frases (`decide-studio-frases.json`) | `e6730879…96c4` | `f890e65dcc19e5599e23b62fe9aebea6f9c046b13c05fe8c76aa48e6f30e4101` (119 744 B) |

**`publication-cases-v2.json` [C]** (diff hoja a hoja contra la copia previa): 55 hojas, 30 `bundleHash` (`7b09b89c`→`26d57565`, `2a5954a2`→`239e2661`, `7ea13909`→`10417eaa` y el inválido «huella truncada»), 1 dentro del inválido «prompts como lista», y por derivación 6 `effectiveJson`, 6 `effectiveHash`, 6 `signature` y 6 `signatureWithOwnContractPrefix`. Nada más.

**n8n S1 [C].** Con `php artisan ultron:prompts-n8n` (solo escribe en el repositorio):

```
elegir-prompts.js: OK      (fec89513…7d10)   ← sha256 antes = después
preparar-commit.js: OK     (876a3f6a…8b45)
operaciones-mcp.json: OK   (1e1aca5a…1cdfc)

git diff --stat -- n8n/
 n8n/flujos/ultron-studio-s1/fixtures/decide-studio-frases.json | 10 +++++-----
 n8n/flujos/ultron-studio-s1/fixtures/decide-studio.json        | 10 +++++-----
```

`LEEME.md`, `flujo-s1.json` y `huellas.json` no cambian (el compilador sigue en 4; el `jsCodeSha256` del selector, `456e4d49…`, y el de Preparar Commit, `fb35df55…`, iguales). En los dos fixtures solo cambian `bundle_hash` y los cuatro roles con el motor; el resto de la respuesta, igual (comprobado por script).

Comprobadores offline **[C]**: `verificar-flujo.cjs` → «OK (121 comprobaciones)»; `verificar-selector.cjs` → «OK: 1737 comprobaciones del selector (306 casos del contrato)»; los dos `--comprobar` de los derivadores → OK.

**La guarda del derivador sigue teniendo dientes [C]:** con un espacio de más en el texto del revisor del motor, o «solo» por «SOLO» en el del redactor, lanza «… no aparece literalmente en §B.3 del plan».

### 2.5 Aserciones existentes cambiadas, y por qué

Todas por el parche del motor: huellas, recuentos de parches, la línea que queda pegada a `FORMA:` y `progressive_disclosure:`, o `sp.o`. **Ninguna se relaja:** las que miraban «la línea tras el ancla» pasan a fijar las dos líneas en su orden exacto; las que quitaban una línea y comparaban con la base quitan además la del motor y siguen comparando byte a byte; las huellas nuevas van escritas a mano y las viejas quedan como `assertNotSame`. La salida con las 54 rojas antes de ponerlas al día: `a4-evidencia/91-…`; una más salió en la batería entera del Studio (93a), al final de la lista.

**`tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerTest.php`** (33 de las 54, por proveedores de datos):
- Docblock, punto 9; constantes `MOTOR_COMPOSER` y `MOTOR_CRITIC` (a mano, §7.1); helper `sinMotor()`.
- `valoresSueltos`: `goal=venta` e `informar` tocan ahora el Strategist **y los cuatro**; `test_cada_valor_cambia_solo_sus_roles` comprueba el parámetro en todos los parches menos los cuatro últimos, que deben ser del motor.
- `test_tono_profesional` (+2 líneas, no +1), `test_tono_entusiasta`, `test_sin_emojis` (+1 línea, no 0): la base se recupera quitando también la línea del motor.
- `test_los_17_valores_de_max_chars` y `test_el_texto_del_tope_del_redactor_compilado_tal_cual`: tras `FORMA:` y `progressive_disclosure:` va el motor y, justo detrás, el texto del tope.
- `test_el_cambio_de_aceptacion_profesional_y_200`: 10 → 14 parches, los cuatro últimos del motor.
- `test_el_compilador_4_solo_mueve_las_huellas_de_profesional` → **`test_las_huellas_del_compilador_4_con_el_parche_del_motor`**: Legacy igual; c001, c304 y G8 con sus huellas nuevas escritas a mano y `assertNotSame` frente a las de antes; G8 115 446 → 117 746 B.
- `test_cada_ancla_aparece_exactamente_una_vez…`: 20 → 44 parches vistos (+4 en cada uno de los seis paquetes).
- `test_la_forma_de_un_parche`: el de `venta` y, detrás, los cuatro del motor con su forma completa.

**`UltronPromptCompilerPhrasesTest.php`** (5): `G8_HASH` → `26d57565…`, con `G8_HASH_COMPILADOR_4_SIN_MOTOR`; `DOS_FRASES_HASH` → `705b65c9…` (calculada en Node), con `DOS_FRASES_HASH_SIN_MOTOR`; bytes 115 446 → 117 746 y 114 010 → 116 310; las cuatro de frases y, detrás, las cuatro del motor (8); en los Composer el motor queda pegado a `FORMA:` y las frases en la línea siguiente (en los Critic, sin cambio); el texto fijo del motor no lleva comillas y es ASCII; en el peor caso, el último parche es el del motor y el de frases sigue necesitando su tope de 2 048.

**`UltronPublishedConfigPhrasesTest.php`** (1): la huella ofrecida de «profesional + 200», `7b09b89c…` → `26d57565…`.

**`tests/Feature/Marketing/UltronDecidePromptsTest.php`** (2, el proveedor): el `sp` de un turno `studio` lleva `'o' => 1` tras `n` y `t`; Legacy, sin la clave.

**`tests/Unit/UltronStudio/StudioPromptDeclarationTest.php`** (2): `G8_HASH` → `26d57565…` y `G8_HASH_SIN_MOTOR`; bytes 117 746; **la vista previa ya no enseña los mismos parches que el compilador**: se comparan con los del compilador menos los cuatro del motor, y se afirma que esos cuatro son exactamente los últimos y del motor; nuevo: lo firmado con el compilador 4 sin el motor da `bundle_mismatch`.

**`tests/Unit/UltronStudio/ExportStudioFixturesCommandTest.php`** (1): la huella de `previewPrompts`.

**`tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php`** (3): `G8_HASH`, la vista previa `[…, '26d57565', false, 117746]` y `bundle8` de los últimos turnos.

**`tests/Feature/UltronStudio/UltronStudioPublicationRulePhrasesTest.php`** (7): `G8_HASH` (seis pruebas que la usan) y la huella de la línea base con dos frases.

**`tests/Feature/UltronStudio/UltronStudioPublicationV2Test.php`** (1, salió en la batería del Studio, 93a): `test_la_tabla_de_cambios_de_la_vista_previa_son_los_parches_del_contrato` comparaba la tabla de cambios de la vista previa con **todos** los parches del caso de `compile-cases.json`. Ahora afirma que los cuatro últimos del caso son del motor (`parameter = engine.plansOverview`, leído del fixture) y compara la tabla con el resto, byte a byte; la huella y los bytes de la vista previa siguen siendo los del caso entero.

**`docs/ultron-studio/ops/motores/verificar-flujo.cjs`**, apartado F (no estaba en la lista del plan; lo ejecuta `UltronPromptsN8nCommandTest`): «fixture PHP con frases: va pegada a su ancla» exigía la línea de frases justo tras `FORMA:` en los Composer. Por diseño (el motor va el último), ahora entre las dos está la del motor: la aserción pasa a exigir `FORMA:` → `LISTA DE PLANES DEL BACKEND: ` → `FRASES PROHIBIDAS PUBLICADAS: ` (más estricta: fija las dos). En los Critic, sin cambio. Comprobado que falla con los fixtures de antes (`--generado` a la copia previa) y pasa con los de ahora **[C]**.

### 2.6 Pruebas nuevas

| Fichero | Pruebas | Qué fija |
|---|---|---|
| `tests/Unit/Marketing/Ultron/Prompts/UltronPromptEnginePatchTest.php` | 6 | La línea base sin parches y con la huella Legacy a mano; `VERSION = 4`; `ENGINE` fuera de `PARAMETERS`. **Los 305 valores no base más dos con frases:** los cuatro del motor, los últimos, con sus textos de §7.1 (a mano), anclas únicas en la base y en lo compilado, pegados a su ancla, roles ≤ 40 960 y paquete ≤ 163 840. Los textos al byte (697 y 451), ASCII, sin llaves ni anclas. El orden tras la misma ancla con tope y frases. **El peor caso al byte.** **La vista previa del Studio sin el parche del motor**, con la huella entera |
| `tests/Unit/Marketing/Ultron/Prompts/UltronPromptOfferEngineTest.php` | 5 | Sobre entradas firmadas reales: `o = 1` junto a `n` y `t` (la última clave) y no en la respuesta a n8n; un cambio solo del estratega lo lleva; **una versión con los valores por defecto no** (sigue como hoy); Legacy nunca; una huella que no cuadra cae a `bundle_mismatch` sin `o` |

### 2.7 Números

| # | Qué | Resultado |
|---|---|---|
| 90 | Las dos pruebas nuevas **antes** de tocar el compilador y la oferta | **ROJO:** 11 pruebas, 3 errores, 5 fallos (pasan las 3 que afirman que algo **no** está) |
| — | Las mismas, después | 11/11, 8 673 aserciones |
| 91 | Las existentes afectadas, con el compilador cambiado y antes de ponerlas al día | 1 107 pruebas, **54 fallos** (§2.5) |
| 92 | Motor: `tests/Unit/Marketing/Ultron`, las dos A4, `UltronDecidePromptsTest`, `UltronCommitTest`, `UltronCommitPromptsTest`, `UltronPromptsTraceCommandTest` | **OK (1 614 pruebas, 35 549 aserciones)** |
| 93a | `tests/Feature/UltronStudio tests/Unit/UltronStudio`, primera pasada | 3 769 pruebas, 2 fallos: la puerta de diff (esperada) y `UltronStudioPublicationV2Test::test_la_tabla_de_cambios_de_la_vista_previa_son_los_parches_del_contrato`, la que no estaba en mi lista dirigida (§2.5); puesta al día |
| 93 | La misma, final | 3 769 pruebas, 41 689 aserciones, **1 fallo esperado** (`OutreachIsolationTest::test_la_puerta_de_diff…`) y 277 omitidas (solo PostgreSQL) |
| 94 | `tests/Feature/Marketing tests/Unit/Marketing` | **OK (6 566 pruebas, 57 315 aserciones)** |
| 95 | Guardas: 16 ficheros (`*Isolation*`, `*ReadOnly*`, `*StaticGuards*`, `SecretExposure*`) | 141 pruebas, 180 938 aserciones, **1 fallo esperado**, el mismo |
| — | `vendor/bin/pint --test` sobre los 25 PHP tocados | `passed` |
| — | Fuera de PHPUnit: los dos `--comprobar`, `verificar-flujo.cjs`, `verificar-selector.cjs` y el sha256 de los tres ficheros de n8n | OK (§2.4) |

### 2.8 Lo que no comprobé y observaciones (ola 2)

- **[S] Que el modelo siga la instrucción de los marcadores y que el revisor no tumbe la lista** es una hipótesis (R5 del plan): aquí no se ejecuta ningún modelo. Se medirá con la ola 3 y la tercera prueba real.
- **Hasta la ola 3, el parche del motor dice algo que no pasa:** `decide` aún no manda `plans_overview` (y el texto dice «si plans_overview no viene, nada de esto aplica»), así que el modelo no debería usar los marcadores; pero si los usara, hoy el commit daría `unknown_placeholder`. Nada de esta rama está desplegado; **el compilador 4 no debe desplegarse sin la ola 3**.
- **Despliegue (para quien lo haga):** lo publicado con el compilador 3 ya caía a Legacy con el 4 (`compiler_changed`); no cambia. Una versión firmada con el 4 antes de este cambio no existe en ningún sitio; si existiera, daría `bundle_mismatch` (lo fija una prueba).
- **El CRM, no tocado:** sus fixtures de pruebas usan la huella del compilador 1–2 (`702890f7…`), ya vieja desde el compilador 3 (el precedente lo dejó dicho); se regeneran con `php artisan ultron-studio:export-fixtures` cuando toque el CRM.
- **No ejecuté** las pruebas solo de PostgreSQL (omitidas en SQLite), ni nada contra producción o el n8n en marcha. Comprobé con `grep` de cadenas fijas que ninguna de las 306 huellas viejas no base queda en `app/`, `tests/` (salvo como «antes» en tres pruebas y en el README), `resources/`, `n8n/flujos/` ni en los `.py`, `.sh`, `.cjs`, `.js` y `.php` de `ops/` **[C]**.
- **La puerta de diff del H9** (93 y 95) nombra ahora **11** ficheros: los 10 de antes y `UltronDecideToken.php`. Su segunda aserción (ficheros nuevos sin seguimiento en zonas protegidas) nombrará además las cuatro clases nuevas (`UltronPlansOverview`, `UltronBenefitAttribution`, `UltronRegenerationNotes` y `UltronDraftDefects`), hoy sin seguimiento y, al subir, en el diff. Van a la Enmienda A4-3 que prevé el plan (§6, tras la ola 7).

### 2.9 Variables, migraciones y configuración

Ninguna. Sin migraciones, sin `config/`, sin rutas, sin variables de entorno.

---

## 3. Ola 3

19-11-2026 · rama `feat/ultron-studio-h9`, HEAD `2d3d02ba` (olas 1 y 2 confirmadas) · **sin commit**: todo está en el árbol de trabajo, nada desplegado, sin producción y sin n8n (ni MCP ni API).
Guía: el encargo del lead (`encargo-be-r2.md`) y `plan-arquitecto-a4-regenerar.md` §1 (H7, H9, H10, H11, H14), §3 P-3 y P-4, §4.2, §4.3 (traza), §4.4, §6 «Ola 3», §7.3 y §13.

### 3.0 Resumen

- **`decide`** (solo con versión del Studio y `sp.o = 1`): si la persona pide el panorama (`pideElPanorama()` con la política de `decide`) y `UltronPlansOverview::select()` da al menos dos planes, añade `context.commercial_turn_policy.plans_overview` (marcadores, `plan_id`, nombre y duración, **sin precio**) y firma `po` en la carga del token **[C]**.
- **Marcadores:** `unknown($draft, $n)` admite `PLAN_1`…`PLAN_n` solo con `n > 0`. La llamada de hoy (`n = 0`) es idéntica **[C]**.
- **`commit`:**
  - expande la lista **una sola vez**, justo tras el validador y antes de `resolvePlan()`, con `Plan` leído en ese momento;
  - con una lista válida, `policy_plans_overview` no salta aunque se pregunte el precio; si no vale, salta como hoy y sale la lista fija;
  - en un turno de cobro la lista no vale (422);
  - la memoria anota el precio entregado de cada plan con `conPreciosEntregados()`;
  - con versión del Studio, la violación nueva `policy_benefit_misattributed` entra por la rama de la política;
  - traza aditiva en `metadata.plans_overview` y `metadata.benefits`.
- **Invariantes [C]:**
  - las dos instantáneas Legacy (`legacy-a4.json` y `legacy-usted.json`), en verde y **sin regenerar**;
  - `UltronA4LongitudYPreciosTest` y `UltronA4RegistroYPanoramaTest`, **sin tocar ni una línea**;
  - `UltronCatalogStaticGuardsTest`, en verde;
  - ningún fixture de n8n cambia (lo comprueba `UltronPromptsN8nCommandTest`).
- **Números:**
  - rojo antes (97): 31 pruebas, 2 errores y 25 fallos;
  - verde después: motor 1 673 (98), Marketing 6 597 (99), las A4 en PostgreSQL 257 (100);
  - guardas: 141, con **una** roja, la esperada (101);
  - `pint`: `passed`;
  - siete mutaciones a mano, todas cazadas.

### 3.1 Ficheros tocados

| Fichero | Cambio | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/UltronDecideService.php` | +82/−25 | La política entera se calcula **una vez** en `decide()`: `politicaDelTurno()` pasa a recibirla y solo recorta, igual que antes. `listaDelPanorama()` (nuevo, privado). `po` en la carga del token y `plans_overview` fuera de la lista blanca de la política |
| `app/Services/Marketing/Ultron/UltronDraftPlaceholders.php` | +25/−2 | `unknown(string $draft, int $planesDeLaLista = 0)` y `allowed(int $n = 0)` (nuevo, público): la lista blanca de siempre y, con lista, `PLAN_1..n`, como mucho 3 |
| `app/Services/Marketing/Ultron/UltronCommitService.php` | +210/−4 | `VIOLACION_BENEFICIO` (pública) y las constantes de traza `not_sellable` y `money_turn` (privadas). Paso 8.ter: la lista firmada y el turno de cobro antes de `unknown()`. Paso 9.bis: la expansión. La traza en `persist()`. P-4 junto a la longitud y el registro. La regla del panorama. La red del «no vendible». `planQueSalio` y la segunda llamada a `conPreciosEntregados()`. `listaFirmada()` y `listaDelModelo()` (nuevos, privados) |
| `tests/Feature/Marketing/UltronA4ListaDelModeloTest.php` | nuevo, 773 líneas | 21 métodos, 31 pruebas con los proveedores (§3.6) |
| `docs/…/a4-evidencia/97` a `101` (y `100a`) | nuevos | Salidas de PHPUnit (§3.7) |

**Sin tocar:**
- `UltronController.php`, las rutas, `config/` y las migraciones;
- el compilador, `SalesConversationReplyService`, `SalesObjectionResponderService`, el guard y `CourtesyRequestService`;
- los prompts base, el CRM y n8n (los tres ficheros que se ejecutan allí y sus fixtures: `git status` limpio en `n8n/`).
- Las clases puras de las olas 1 y 2 se usan tal cual: `select`, `items`, `item`, `expand`, `verify`, `misattributed`, `MIN_MARKERS` y `MAX_PLANS`, **sin cambiar ni una línea**.

### 3.2 El contrato

**`decide` (respuesta a n8n).** Solo con `sp.m = studio`, `sp.o = 1`, `pideElPanorama()` cierto y al menos 2 planes:

```json
"commercial_turn_policy": {
  "intent": "pricing_question", "…": "las 17 claves de siempre, en su orden",
  "plans_overview": {
    "plans": [
      {"marker": "{{PLAN_1}}", "plan_id": 3, "name": "Plan Valera", "duration_days": 30},
      {"marker": "{{PLAN_2}}", "plan_id": 4, "name": "Plan Mensual", "duration_days": 30},
      {"marker": "{{PLAN_3}}", "plan_id": 8, "name": "Élite", "duration_days": 30}
    ],
    "price_asked": true
  }
}
```

- Sin precio: la prueba exige que no haya `$` ni cifras de dinero en toda la política.
- Fuera de esos casos, la clave **no aparece** (no viaja con valor `null`).
- Token, carga firmada `x`: `po: [3, 4, 8]`, detrás de `sp`.

**`commit` (sin cambios en la entrada).**

| Qué | Cuándo | Forma |
|---|---|---|
| 422 `unknown_placeholder` | Igual que hoy. Con lista, `PLAN_1..n` ya no son desconocidos | `detail.allowed`: sin lista, **exactamente** `ALLOWED` (lo fijan las pruebas). Con lista, `ALLOWED` + `PLAN_1..n` |
| `metadata.plans_overview` | Camino normal, si el token trae `po` (y solo con Studio) | `{offered:[id], used:[id], valid:bool, detail:null\|missing\|mixed\|name_written\|not_sellable\|money_turn}` |
| `metadata.benefits` | Si salta P-4 | `{misattributed:[{plan_id, benefit_plan_id, benefit_index}]}`: el índice, nunca el texto |
| `metadata.policy_violations` | Rama de la política | Puede llevar `policy_benefit_misattributed` |
| `price_source` | Si sale la lista del modelo y no nombra el plan del modelo | `null` (decisión 8) |

### 3.3 Dónde quedó la expansión, y por qué

`UltronCommitService.php:664-683` (paso 9.bis): justo después de `if ($sanitized['reply'] === null) throw draft_rejected` y antes de `// 10) … resolvePlan()`, sobre `$sanitized['reply']`. Es el sitio del plan (P-3, cambio 4) y tiene tres razones:

1. **Después del validador:** el validador tira cualquier cifra. El borrador se valida sin precios y la cifra la escribe Laravel, el mismo orden que con `{{PLAN_PRICE}}`.
2. **Antes de los tres sitios que vuelven a resolver el texto desde `$sanitized['reply']`:** el plan que impone la política, la realineación y la frase del precio. Expandir después de cualquiera de ellos perdería la lista.
   - Lo fija `test_el_plan_que_fuerza_la_politica_no_pierde_la_lista`: la política fuerza el Élite nombrado y la lista sigue entera.
   - La mutación M2 (no reasignar `$sanitized['reply']`) tumba 10 pruebas.
3. **Todo lo de detrás ve el texto final:** estilo, novedad, `violations()`, longitud, registro, P-4 y guardas. La longitud se mide expandida sin código adicional: `metadata.length.from` = la medida del texto expandido, que la prueba compara con `UltronReplyFitter::measure()` y con la del borrador sin expandir.

**Con dos o más nombres y sin `PLAN_PRICE`, la realineación no se activa** **[C]**. Lo afirman dos pruebas: la de tres marcadores sale sin `plan_price_realigned`, y con un plan del modelo fuera de la lista (el Trimestre) tampoco hay realineación y `recommended_plan_id` sigue siendo el suyo.

**El turno de cobro (paso 8.ter, `:528-547`).** Se lee de la **propuesta** (`payment_link_send` en `tools_requested`, o una intención `high_intent_close` o `payment_link_request`), porque `unknown()` va antes del validador. Es seguro: el validador solo puede quitarle a la intención lo de cobro (la cambia a `unknown` o a `greeting`), nunca dárselo.

### 3.4 Decisiones mías (el plan no las fija)

1. **`price_asked`** = intención del turno `pricing_question` **o** `preguntaPorPrecio(entrante)`: dos de las tres condiciones con las que el commit exige la lista (la tercera mira el borrador). **[C]** El clasificador local etiqueta `pricing_question` toda pregunta por «planes» («¿Qué planes tienen?», «Quiero información de los planes»…), así que en la práctica es casi siempre `true`. Sale `false`, por ejemplo, con «¿Qué membresías tienen?» (`unknown`), y lo fija la prueba.
2. **«Todos los planes siguen vendibles ahora»** lo entiendo como **los que el borrador nombra con su marcador**, no los tres de `po`. Si el modelo usa `PLAN_1` y `PLAN_2` y el tercero deja de venderse, lo que sale es verdad y la IA sigue siendo la autora (condición 1). Si el lead prefiere la lectura literal («todos los de `po`»), es una línea en `listaDelModelo()`.
3. **Un plan que ya no se vende no se escribe nunca:**
   - su marcador **no se sustituye**: ni nombre ni precio, tampoco en `policy_discarded_draft`, y lo fija la prueba;
   - la lista no vale (`not_sellable`) y la violación del panorama se añade **haya panorama o no**. Es la única desviación de «salta como hoy», y la motiva el caso con una herramienta en el turno (prueba propia): sin ella, la regla de hoy no saltaría y el marcador saldría literal;
   - si la rama de la política no encontrara alternativa (hoy no pasa: `respaldoPorIntencion()` acaba en `ultimoRecurso()`), se fuerza `ultimoRecurso()`.
   - **Efecto lateral declarado:** con una cortesía ejecutada en ese mismo turno, sale la lista fija en vez de su constancia. Es un caso borde de un caso borde: el plan se archiva en los ~10 s entre `decide` y `commit`, en un turno de panorama con herramienta.
4. **Detalles de traza nuevos** (`not_sellable`, `money_turn`): son solo de traza, constantes privadas del commit y **no** códigos de reescritura de `UltronRegenerationNotes`, porque no los arregla una reescritura. Sin marcadores, la traza de un turno de cobro dice `valid: false` y `detail: money_turn`. Con marcadores no hay fila: es el 422.
5. **`used`** = los planes que el borrador nombra con sus marcadores, por orden de aparición y sin repetir, **también el que ya no se vende**. La traza dice qué escribió el modelo.
6. **`metadata.benefits` lleva además `benefit_plan_id`.** El plan pone `{plan_id, benefit_index}`, pero sin el dueño el índice es ambiguo (es el índice en los `benefits` del *otro* plan). Sigue sin llevar texto.
7. **P-4 se aplica a todo turno del Studio, con lista o sin ella.** Es lo que dicen el plan (§4.4, §6) y el encargo («solo con versión del Studio»). **Tensión que señalo:** el invariante «con versión del Studio pero sin lista, todo sale como hoy» deja de ser literal cuando el borrador cuelga a un plan el beneficio de otro. Las pruebas existentes no cambian porque su catálogo da a todos los planes el mismo beneficio. Si el lead quiere P-4 solo en turnos con lista, es añadir `&& $lista !== null` en una condición.
8. **La memoria con la lista del modelo (F3).**
   - `recordSentTurn()` da por entregado el precio del plan del modelo si ve una cifra en el texto. Con la lista, si el plan del modelo no está en ella (un Trimestre recomendado y tres planes de un mes), anotaba un precio que no salió: el mismo fallo F3 que la lista fija ya corregía.
   - Por eso la condición de `$planQueSalio` cuenta también «salió la lista del modelo», y lo fija la prueba.
   - Los precios de la lista los anota una **segunda** llamada a `conPreciosEntregados()`, con `{id → «Nombre ($precio COP)»}`. No mezclo los dos mapas: los dos pueden tener el mismo id con textos distintos, y la función solo anota lo que está de verdad en el texto. Sin lista, la segunda llamada vuelve sin escribir: Legacy, idéntico.
9. **`po` en el commit:** solo vale con la forma que emite `decide` (lista de 2 a 3 enteros positivos y distintos). Otra forma se ignora con `ultron.commit.plans_overview_invalid`, con el tipo y sin el valor: el turno sale sin lista, igual que un tope o un tono firmados que no valen.
10. **Una sola consulta** por turno con lista: `Plan::sellable()->whereIn('id', <los que nombra el borrador>)`, con la misma regla que `activePlans()` y `isSellable()`. Ninguna si el borrador no usa marcadores.
11. **`decide`:** `CommercialTurnPolicy::decide()` se llamaba dentro de `politicaDelTurno()`. Ahora se llama una vez, antes del token, con los mismos argumentos (la memoria no cambia entre el sitio viejo y el nuevo) y el mismo número de lecturas de `activePlans()`. Las instantáneas Legacy, byte a byte, lo confirman **[C]**.

### 3.5 Aserciones existentes cambiadas

**Ninguna.** Tampoco hay ningún fixture regenerado:
- `tests/fixtures/ultron-a4/*.json`, `a4-textos-*.md` y `n8n/flujos/ultron-studio-s1/**`: `git status` limpio **[C]**;
- `UltronDecideTest` y `UltronDecidePromptsTest`, sin cambios: sus turnos con política no piden el panorama con `sp.o = 1`.

### 3.6 Pruebas nuevas (`UltronA4ListaDelModeloTest`, por el endpoint, clases reales)

| Prueba | Qué fija |
|---|---|
| `decide_ofrece_la_lista_firmada_y_sin_precio_al_panorama` | `plans_overview` exacto, `po = [3,4,8]` firmado, sin cifras, las claves de la política en su orden con `plans_overview` al final; `price_asked` verdadero y falso; el plan nombrado primero (`[8,3,4]`) |
| `decide_no_ofrece_la_lista_fuera_del_panorama_ni_sin_el_parche_ni_en_legacy` | Saludo, plan a secas, versión con los valores por defecto (sin `o`), Legacy sin negociar y con los prompts apagados, menos de dos planes |
| `la_lista_del_modelo_con_tres_marcadores…` y `…con_dos_marcadores` | La pregunta real del 18-11: texto expandido con `Plan`, sin `policy_violations`, traza, longitud medida expandida, registro `ok`, memoria con `prices_delivered` = los planes que salieron (sin el Élite cuando no sale) |
| `el_plan_del_modelo_fuera_de_la_lista_no_cuenta_como_entregado` | Decisión 8: fuera de la lista, `price_source` null y sin precio en memoria; dentro de la lista, se conserva |
| `el_precio_cambiado_entre_decide_y_commit_sale_el_nuevo` | Precio y nombre cambiados entre los dos: salen los nuevos |
| `un_plan_que_deja_de_venderse…_da_la_lista_fija_de_ahora` (×3) | No vendible, archivado o precio a cero: la lista fija del catálogo de AHORA (sin el Mensual, con PROMO X4), `not_sellable`, la evidencia sin su precio |
| `un_plan_que_deja_de_venderse_no_sale_ni_con_herramientas_en_el_turno` | Decisión 3: sin panorama en el commit, igual no sale (ni el nombre, ni `{{`, ni `PLAN_`) |
| `plan_4_con_tres_ofrecidos…` / `plan_1_sin_lista…` | `unknown_placeholder` con `['PLAN_4']` y sin fila; con dos ofrecidos, `PLAN_3`; sin lista, `PLAN_1` y `allowed` = `ALLOWED` |
| `un_turno_legacy_con_plan_1_da_422_como_hoy` (×2) | Sin negociar y con los prompts apagados |
| `una_lista_que_no_vale_da_la_lista_fija` (×5) | Nombre a mano, mezcla con `PLAN_NAME` y con `PLAN_PRICE`, repetido, un solo plan: `policy_plans_overview`, la lista fija y su detalle |
| `sin_panorama_en_el_commit…` | Con una herramienta en el turno (G1.2) la lista del modelo sale expandida |
| `en_un_turno_de_cobro_la_lista_no_vale` (×3) / `un_turno_de_cobro_sin_marcadores_deja_la_traza` | Pago, cierre y `payment_link_send`: 422 con `allowed` = `ALLOWED`; sin marcadores, `money_turn` |
| `el_plan_que_fuerza_la_politica_no_pierde_la_lista` | §3.3, razón 2 |
| `un_beneficio_de_otro_plan_va_por_la_rama_de_la_politica` | Lista válida y beneficio del Élite colgado al Valera: **solo** `policy_benefit_misattributed`, la lista fija (respaldo de la intención), la traza con índices y sin texto; el mismo beneficio en su plan sale |
| `el_beneficio_de_otro_plan_con_studio_y_sin_lista_y_en_legacy_nada` | Decisión 7, y Legacy (dos modos) sin violación ni traza |
| `cercano_y_entusiasta_la_lista_que_tutea_sale` (×2) / `profesional_la_lista_que_tutea_se_descarta…` | Los tres tonos. Con «profesional», la lista que tutea **vale** pero el registro la descarta (`[policy_register_mismatch]`, `found: ['te']`) y sale la lista fija de usted; la de usted sale |
| `los_marcadores_de_la_lista_solo_valen_con_lista` | `unknown()` con 0, 2, 3 y 9; `PLAN_0`, `PLAN_01` y `plan_1` siempre desconocidos; `allowed()`; `needsPlan()` no cambia |

**Mutaciones a mano** (una por una, deshechas, `cmp` idéntico al final) **[C]**:

| # | Mutación | Pruebas que fallan |
|---|---|---|
| M1 | La lista nunca vale | 9 |
| M2 | No se expande en su sitio | 10 |
| M3 | El «no vendible» no se fuerza | 1 |
| M4 | F3 sin la lista | 1 |
| M5 | Sin la memoria de la lista | 5 |
| M6 | La lista también vale en el cobro | 4 |
| M7 | `decide` no mira `sp.o` | 1 |

### 3.7 Números

| # | Qué | Resultado |
|---|---|---|
| 97 | La prueba nueva (versión final) con `app/` = HEAD `2d3d02ba` (los tres ficheros guardados, sustituidos por los de HEAD con `git show`, restaurados y comparados con `cmp`) | **ROJO:** 31 pruebas, 2 errores, 25 fallos (pasan las 4 que afirman que algo **no** ocurre) |
| 98 | Motor: `tests/Unit/Marketing/Ultron`, las tres A4, `UltronDecideTest`, `UltronDecidePromptsTest`, `UltronCommitTest`, `UltronCommitPromptsTest`, `UltronPromptsN8nCommandTest` | **OK (1 673 pruebas, 36 012 aserciones)** |
| 99 | `tests/Feature/Marketing tests/Unit/Marketing` | **OK (6 597 pruebas, 57 578 aserciones)**: las 6 566 de la ola 2 + las 31 nuevas |
| 100a | Las tres A4 en PostgreSQL, primera pasada | 257 pruebas, **8 fallos, todos de la prueba nueva y todos por el orden de las claves**: `jsonb` las reordena y yo comparaba la traza con `assertSame`. Corregido en la prueba con `assertMismaTraza()` (`assertSame` tras ordenar las claves de los objetos; las listas, en su orden): no se relaja ni un tipo ni un valor |
| 100 | Las tres A4 en PostgreSQL (`UNIT_DB=ultron_studio_lead_ola5`, local y desechable) | **OK (257 pruebas, 2 950 aserciones)** |
| 101 | Guardas: los 16 ficheros de 95 | 141 pruebas, **1 fallo esperado** (`OutreachIsolationTest::test_la_puerta_de_diff…`) |
| — | `vendor/bin/pint` sobre los 4 PHP tocados | `passed`, sin cambios |

**La puerta de diff del H9** nombra ahora **16** ficheros. HEAD (con las olas 1 y 2 confirmadas) ya tenía 15; esta ola añade **uno**, `UltronDraftPlaceholders.php`; `UltronCommitService.php` y `UltronDecideService.php` ya estaban. Va a la Enmienda A4-3.

### 3.8 Lo que no comprobé

- **[S] Que el modelo use los marcadores y que el revisor no tumbe la lista** (R5): aquí no corre ningún modelo. Que n8n S1 pase `plans_overview` entero al redactor lo dice el plan (H4); no lo he ejecutado en n8n.
- **La comprobación `ai/check`** (ola 4) **no está hecha**, como pide el encargo. Para su paridad con el commit hay que saber dos cosas:
  - `UltronDraftDefects` no conoce `not_sellable` (no lo arregla una reescritura): la prueba de paridad tiene que tratarlo como excepción declarada;
  - la laguna de §1.7 sigue abierta.
- **La sonda de PCRE:** esta ola no añade ninguna expresión regular (`grep preg_` en el diff: ninguna nueva).
- Nada contra producción ni contra el n8n en marcha. Las pruebas solo de PostgreSQL fuera de las A4 no las ejecuté.
- **Para `security` / `api-security-specialist` (filas 2, 3 y 10 de P-8, y el dinero):**
  - la lista en un turno de cobro (422);
  - `po` firmado y validado en forma;
  - el marcador de un plan no vendible, que nunca se escribe;
  - la traza sin textos.
- **Casos límite para `tester`:**
  - un plan que se borra de la tabla (no solo que se archiva) entre `decide` y `commit` cae en `not_sellable` por la misma consulta, pero no tiene prueba propia;
  - un `po` firmado con forma rara solo se puede fabricar re-firmando el token, y no tiene prueba propia (el aviso está en el código).

### 3.9 Variables, migraciones y configuración

Ninguna. Sin migraciones, sin `config/`, sin rutas y sin variables de entorno.

---

## 4. Ola 4

19-11-2026 · rama `feat/ultron-studio-h9`, HEAD `ee8e0df0` (olas 1 a 3 confirmadas) · **sin commit**: todo está en el árbol de trabajo, nada desplegado, sin producción y sin n8n (ni MCP ni API).
Guía: el encargo del lead (`encargo-be-r3.md`) y `plan-arquitecto-a4-regenerar.md` §1 (H7, H8, H9, H12, H13, H18), §3 P-1, P-2, P-5 a P-8, §4.1 a §4.4, §6 «Ola 4», §12 y §13; las condiciones 2, 5, 6 y 7 de `MATRIZ_CIERRE_V2.md` §5.

### 4.0 Resumen

- **La puerta nueva, de SOLO LECTURA:** `POST /api/internal/marketing/ai/check` (contrato `ultron-regen/1`), en el grupo de las otras (mismo secreto, mismo cubo `internal-marketing-ai`). Servicio nuevo `UltronDraftCheck`: `peek()` del token, sin cerrojo, sin escrituras, sin red y sin despachador; usa `UltronDraftDefects` y el resto de piezas puras tal cual. Sin versión del Studio contesta `legacy` sin leer nada **[C]**.
- **El presupuesto:** `may_rewrite = attempt < 3 && (ahora − i) ≤ 25 s`, con `TIME_BUDGET_SECONDS = 25` como constante; `budget.reason` ∈ {`null`, `last_attempt`, `time_budget`, `legacy`} **[C]**.
- **`decide`:** `regeneration: {contract: "ultron-regen/1", max_drafts: 3}` en la raíz, solo con versión del Studio **[C]**.
- **`commit`:** acepta el bloque `regeneration` (§4.3, es TRAZA) y `critic.attempt` hasta 3. La fila lleva `metadata.regeneration`, escrito en `persist()` y con `consistent`; clase pura nueva `UltronRegenerationTrace` **[C]**.
- **Decisión del lead (laguna de §4.1, paso 4a):** un `PLAN_k` que no es de la lista se señala SIEMPRE (`unknown_marker`), y en un turno sin lista o de cobro, `no_list` **[C]**.
- **Invariantes [C]:**
  - las dos instantáneas Legacy, en verde y sin regenerar (`git status` limpio en `tests/fixtures`);
  - un commit sin el bloque deja la fila sin la clave, e idéntica en lo demás a la del mismo turno con el bloque;
  - n8n intacto (los tres ficheros que se ejecutan allí, con el mismo sha256 que en HEAD, y sus fixtures sin regenerar: `decide` real no los genera).
- **Números:**
  - rojo antes: 280 pruebas, 8 errores y 101 fallos (102 y 103);
  - en verde: las nuevas y las tocadas, 280 (104); el motor, 1 781 (105); Marketing, 6 701 (106); en PostgreSQL, 354 (107);
  - las guardas: 141, con **una** roja, la esperada (108);
  - `pint`: `passed`; seis mutaciones a mano, todas cazadas (110).

### 4.1 Ficheros tocados

| Fichero | Cambio | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/UltronDraftCheck.php` | nuevo, 232 líneas | El servicio de la comprobación: `peek()`, la lista del turno, el texto expandido para medir, los defectos, el presupuesto, la respuesta con su lista blanca y una línea de log con códigos y números |
| `app/Services/Marketing/Ultron/UltronRegenerationTrace.php` | nuevo, 118 | Pura: bloque `regeneration` → `metadata.regeneration`, forma cerrada y defensiva, `consistent` |
| `app/Http/Controllers/Api/Internal/UltronController.php` | +115/−6 | `check()` (validación de §4.1, `unexpected_fields`, 422 `invalid_token` sin detalle); en el commit, `critic.attempt` ≤ `CriticContract::MAX_ATTEMPTS` y las reglas de `regeneration` (§4.3); `unexpectedKeys()` con los bloques como parámetro, `regeneration` en la lista y un nivel más dentro de la traza (`tokens` y cada `rewrites.*`); `lista()` (regla «lista, no objeto»); docblock «tres puertas» |
| `routes/marketing.php` | +9/−4 | `Route::post('ai/check', …)` entre `decide` y `commit`, dentro del grupo `automation.internal` + `throttle:internal-marketing-ai`; el comentario de las puertas |
| `app/Services/Marketing/Ultron/UltronDecideService.php` | +11/−1 | El bloque `regeneration` en la raíz, solo con `sp.m = studio` |
| `app/Services/Marketing/Ultron/UltronCommitService.php` | +19/−10 | `persist()`: `metadata.regeneration`; el paso 8.ter y `listaFirmada()` llaman a las dos piezas extraídas (sin cambio de comportamiento); un comentario de `handleCriticFailure()` (el fallo puede llegar tras el tercer revisor) |
| `app/Services/Marketing/Ultron/CriticContract.php` | +10/−2 | `MAX_ATTEMPTS = 3` y el recorte de `forMetadata()` a 3 |
| `app/Services/Marketing/Ultron/UltronPlansOverview.php` | +39 | `signedList()` y `isMoneyTurn()`, extraídas tal cual del commit (decisión 2) |
| `app/Services/Marketing/Ultron/UltronDraftDefects.php` | +10/−2 | La decisión del lead: con lista y fuera de la condición del panorama, `unknown_marker` igual; docblock |
| Pruebas: 4 ficheros nuevos y 6 tocados | | §4.5 y §4.6 |
| `docs/…/a4-evidencia/102` a `111` | nuevos | Salidas (§4.7) |

**Sin tocar:**
- el compilador, los prompts base, `config/`, las migraciones, `SalesConversationReplyService`, `SalesObjectionResponderService`, el guard y `CourtesyRequestService`;
- el CRM y n8n, con sus fixtures;
- `UltronDecideToken` (`peek()` tal cual), `UltronRegenerationNotes`, `UltronBenefitAttribution`, `UltronDraftPlaceholders` y `UltronReplyFitter`.

### 4.2 El contrato tal como quedó (`ultron-regen/1`)

**Petición** (lista blanca; cualquier otra clave en la raíz o en `draft` → 422 `unexpected_fields` con `fields`):

| Campo | Regla |
|---|---|
| `conversation_id`, `message_id` | `required, integer, min:1` (sin `exists`: el token ata el par) |
| `decide_token` | `required, string, max:2048` |
| `attempt` | `required, integer, min:1, max:3` |
| `draft` | `required, array` |
| `draft.reply_draft` | `required, string, max:4000` |
| `draft.intent` | `required, string, in: SalesAgentDecisionSchema::INTENTS` |
| `draft.recommended_plan_id` | `nullable, integer, min:1` |
| `draft.tools_requested` / `.*` | `nullable, array, max: count(TOOL_VOCABULARY)` (= 5) / `in: TOOL_VOCABULARY` |

**Tres casos reales** (volcados por el endpoint con el escenario de las pruebas: la canaria «profesional, 200», los 13 planes de producción y «¿Qué planes tienen y cuánto cuesta la mensualidad?»; `po = [3, 4, 8]`; `decide` a las 22:47:00 UTC; el JSON completo, en `a4-evidencia/111-…json`). La petición es la misma en los tres salvo `attempt` y `reply_draft`:

```json
{"conversation_id": 1, "message_id": 1, "decide_token": "<el de decide>", "attempt": 1,
 "draft": {"reply_draft": "…", "intent": "pricing_question", "recommended_plan_id": null, "tools_requested": []}}
```

1. **Pasa** (a los 7 s), `reply_draft` = «Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?»:

```json
{"ok": true, "contract": "ultron-regen/1", "mode": "studio", "attempt": 1, "pass": true, "defects": [], "issues": [], "notes": "",
 "may_rewrite": true, "rewrite": false, "budget": {"max_drafts": 3, "elapsed_s": 7, "reason": null}}
```

2. **Pide reescritura** (a los 9 s), la lista que tutea y que, expandida, mide 241 (sin expandir cabría en 200):

```json
{"ok": true, "contract": "ultron-regen/1", "mode": "studio", "attempt": 1, "pass": false,
 "defects": [{"code": "register_tuteo", "detail": null, "issue": "naturalness"},
             {"code": "length_over_cap", "detail": null, "issue": "progressive_disclosure"}],
 "issues": ["naturalness", "progressive_disclosure"],
 "notes": "REESCRIBE DE USTED: la version publicada trata a la persona de usted y el borrador tutea (te). Pasa todo el mensaje a usted sin mezclar (le, su, usted, cuenteme, quiere) y conserva lo que decia. DEMASIADO LARGO: el borrador mide 241 caracteres y el limite publicado es 200. Reescribelo en unos 160: primero la respuesta a lo que pregunto la persona, sin relleno, y como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye.",
 "may_rewrite": true, "rewrite": true, "budget": {"max_drafts": 3, "elapsed_s": 9, "reason": null}}
```

3. **Sin presupuesto** (`attempt` 2, a los 26 s), la lista que tutea:

```json
{"ok": true, "contract": "ultron-regen/1", "mode": "studio", "attempt": 2, "pass": false,
 "defects": [{"code": "register_tuteo", "detail": null, "issue": "naturalness"}], "issues": ["naturalness"],
 "notes": "REESCRIBE DE USTED: la version publicada trata a la persona de usted y el borrador tutea (te). Pasa todo el mensaje a usted sin mezclar (le, su, usted, cuenteme, quiere) y conserva lo que decia.",
 "may_rewrite": false, "rewrite": false, "budget": {"max_drafts": 3, "elapsed_s": 26, "reason": "time_budget"}}
```

Con `attempt: 3`, lo mismo con `reason: "last_attempt"` (manda sobre `time_budget` si coinciden).

**Sin versión del Studio** (token sin `sp`, o con `sp.m = legacy`), sin leer el mensaje ni los planes (cero sentencias, fijado por la prueba):

```json
{"ok": true, "contract": "ultron-regen/1", "mode": "legacy", "attempt": 2, "pass": true, "defects": [], "issues": [], "notes": "",
 "may_rewrite": false, "rewrite": false, "budget": {"max_drafts": 2, "elapsed_s": null, "reason": "legacy"}}
```

**Errores:**
- 401: el middleware.
- 429: el limitador.
- 422 de validación: la forma estándar de Laravel.
- 422 `{"ok": false, "code": "unexpected_fields", "message": "…", "fields": ["draft.price", …]}`.
- 422 `{"ok": false, "code": "invalid_token"}`, exactamente eso: sin motivo, sin ids y sin `message`. El motivo queda en el log de `peek()` (`ultron.decide_token.rejected`, sin el token).

**`decide` (§4.2):** `"regeneration": {"contract": "ultron-regen/1", "max_drafts": 3}`, detrás de `studio_prompts` y antes de `superseded`, solo con `sp.m = studio`. Sin negociar o con los prompts del Studio apagados, la clave no aparece.

**`commit` (§4.3).** Entrada:
- `critic.attempt` ≤ 3;
- `regeneration` con las reglas del plan, más:
  - `regeneration.rewrites.*` → `array`;
  - `rewrites`, `codes` e `issues`: lista, no objeto;
  - topes contados: `codes` ≤ `count(CODES)` = 4 e `issues` ≤ `CriticContract::MAX_ISSUES` = 8;
- una clave de más dentro de `regeneration`, de `regeneration.tokens` o de una reescritura → `unexpected_fields`.

Traza en la fila, con la forma de §4.3:

```json
"regeneration": {"drafts": 3, "critics": 2, "checks": 2, "check_errors": 0,
  "rewrites": [{"by": "server", "codes": ["register_tuteo", "length_over_cap"], "issues": ["naturalness", "progressive_disclosure"]},
               {"by": "critic", "codes": [], "issues": ["relevance"]}],
  "tokens": {"input": 41000, "output": 900}, "consistent": true}
```

### 4.3 La paridad (`UltronCheckCommitParityTest`)

Sobre el MISMO turno (la misma conversación y el mismo token) se pasan primero la comprobación y después el commit, con el revisor aprobando. La comprobación marca cada código **si y solo si** el commit actúa por esa causa:

| Código | En el commit |
|---|---|
| `register_tuteo` | `metadata.register.action` ≠ `ok` |
| `length_over_cap` | `metadata.length.from` > `cap`. **No** `action` ≠ `fit`: la acción pasa a `replaced` cuando la política sustituye el texto por otra causa; lo descubrí en la primera pasada verde (§4.5) |
| `plans_overview` | `policy_plans_overview`, o un 422 `unknown_placeholder` con un `PLAN_k`, o, con la lista, el 422 de la cifra escrita |
| `benefit_misattributed` | `policy_benefit_misattributed` |

**Matriz (23 borradores, todos en verde):**

| Turno | Borradores | Resultado esperado y obtenido en los dos |
|---|---|---|
| Panorama con precio, lista firmada | usted · tutea · solo se pasa expandida · tutea y se pasa · falta la lista · mezcla con `PLAN_PRICE` · repetido · nombre a mano · `PLAN_4` · cifra · beneficio de otro plan · beneficio en su plan · beneficio y tuteo | `[]` · `register` · `length` · `register+length` · `plans` (×6) · `benefit` · `[]` · `register+benefit` |
| Decisión del lead | `PLAN_4` con `app_links_send` · `PLAN_4` sin pedir el precio («¿Qué membresías tienen?») | `plans` · `plans` |
| Cobro con la lista ofrecida | `payment_link_request` con los tres marcadores | `plans` (`no_list` / 422) |
| Horario, sin lista | usted · tutea en la pregunta · largo · `PLAN_1` · beneficio de otro plan nombrado a mano | `[]` · `register` · `length` · `plans` · `benefit` |
| Horario con `app_links_send` | no cabe con los enlaces (174 + enlaces = 204) · cabe | `length` · `[]` |

**Las excepciones, una a una, documentadas en el docblock de la prueba.** Las tres primeras tienen además una prueba propia que fija la divergencia exacta:

| # | Excepción | Comprobación | Commit | Prueba |
|---|---|---|---|---|
| E1 | Un plan de la lista deja de venderse entre los dos (H14) | `[]` | `plans_overview` (`not_sellable`): no lo arregla una reescritura | sí |
| E2 | El commit retira frases antes de medir (`sinServiciosInventados()`: «clases de pilates y yoga») | `length_over_cap` | cabe (`fit`, `invented_service_dropped`) | sí |
| E3 | Los enlaces de la app salieron hace menos de 60 min (memoria) | los cuenta → `length_over_cap` | no los pega → cabe | sí |
| E4 | La línea del cobro con el enlace (`conCheckout()`) | no la pega (§4.1 solo habla de la app) | la pega antes de medir | no: la bandera del cobro está apagada aquí (estado de producción) |
| E5 | La realineación del precio (`price_sentence`) o el plan que impone la política | mide con el plan propuesto | reescribe la frase del precio | no |
| E6 | El panorama: intención saneada, política del momento, herramientas ejecutadas o relevo | intención del borrador y `po` | lo de ese instante | no |
| E7 | Lo que el commit tumba antes de medir (URL, tarjeta, pago o membresía inventados, guard, presión, transición, efecto sin autoridad) | nada | 422 por otra causa | `test_no_es_un_oraculo_de_las_guardas` |

Misma familia que E2, sin prueba propia: `sinConfirmarUnaCortesia…()`, `sinCambioDeVisitaQueNoSeHizo()`, `sinConstanciaDeUnaCortesia…()`, `sinNegarUnCobroDisponible()` y `sinExcusasDePrecio()`.

**Sentido de la divergencia:** en E2, E3 y E5 la comprobación es MÁS estricta que el commit. Pide una reescritura que el commit no habría necesitado: cuesta una llamada al modelo, pero no deja pasar nada. En E1, E4 y E6 es más laxa, y la red sigue siendo el commit.

### 4.4 Decisiones mías (el plan no las fija)

1. **La decisión del lead vive en `UltronDraftDefects`, no en la puerta:**
   - con lista y fuera de la condición del panorama, `verify() === unknown_marker` se señala igual;
   - lo demás de la lista (falta, mezcla, nombre) sigue sin mirarse fuera de esa condición, como en el commit;
   - en un turno de cobro, quien llama pasa la lista vacía y sale `no_list`.

   Así la pieza pura sigue siendo la única que decide los defectos.
2. **Dos piezas del commit, extraídas a `UltronPlansOverview` para no duplicarlas:** `signedList()` (la forma de `po`) e `isMoneyTurn()` (el cobro del paso 8.ter).
   - El commit las llama con el mismo resultado: las pruebas de la ola 3 y las instantáneas siguen en verde, y la mutación M2 lo caza en la puerta.
   - El `$turnoDeCobro` de la realineación (sobre la intención saneada) **no** se toca: es otra regla.
3. **Respuesta:**
   - `defects` sin la nota (el ejemplo del plan); las notas, unidas en `notes` por `merge()`;
   - `issues` con `CriticContract::issues()`, sin repetir y del vocabulario;
   - con presupuesto agotado, los defectos se dicen igual (`pass: false`, `rewrite: false`), para que la traza de n8n sepa por qué.
4. **Presupuesto:**
   - a los 25 s justos todavía se concede; a los 26 s, no;
   - `last_attempt` manda sobre `time_budget`;
   - `elapsed_s` no baja de 0.
5. **Lecturas:**
   - el cuerpo del mensaje, con `whereKey(m)->where('conversation_id', c)` (además del token);
   - el catálogo, con `activePlans()`;
   - con marcadores de la lista, una consulta `Plan::sellable()->whereIn(…)`, la misma del commit;
   - si cotiza, el plan propuesto si se vende (el id leído como el commit, `is_numeric`) y, si no, `defaultMonthlyPlan()`.
6. **Los enlaces de la app se pegan siempre que se piden**, sin mirar la memoria (el plan dice que no se lee): es la aproximación declarada (E3).
7. **El log:** una línea `ultron.check.result` por comprobación del Studio, con el id de la conversación, el intento, los códigos (`plans_overview/missing`…), `may_rewrite`, `rewrite`, `reason` y `elapsed_s`. Un token malo deja solo la línea de `peek()` de siempre, sin el token.
8. **La puerta no comprueba el interruptor de ULTRON, el freno ni el canario:** es de solo lectura y el plan no lo pide. El commit sigue exigiéndolos. Lo señalo para `api-security-specialist`.
9. **La traza se escribe en `persist()`, así que la lleva TODA fila con el bloque** (también la rendición, el relevo y la hora de la visita).
   - `consistent` compara `drafts` con el intento del revisor **tal como queda en esa fila**: `forMetadata()`, que en el camino del fallo del revisor vale 2 si no viene.
   - Sin revisor en la fila, `null`: no hay con qué cuadrar.
   - Un bloque con otro contrato o con `drafts` fuera de 1 a 3 no deja traza (por HTTP es un 422 antes).
   - Ningún texto viaja a la fila.
10. **`unexpectedKeys()` baja un nivel más en la traza** (`tokens` y cada `rewrites.*`). El plan solo pedía añadir `regeneration` a la lista. Sin esto, un `text` dentro de una reescritura no fallaría: `validated()` lo descartaría en silencio.
11. **`CriticContract::MAX_ATTEMPTS = 3`**, usado en `forMetadata()`, en `critic.attempt` y en `regeneration.critics`: el tope se cuenta, no se escribe.
12. **Sin `config/` y sin caché de rutas en el árbol.** La ruta nueva exige `route:cache` al desplegar (§9 del plan: `hotfix-backend.sh` no sirve).

### 4.5 Aserciones existentes cambiadas, y por qué

| Fichero | Cambio | Por qué |
|---|---|---|
| `tests/Unit/Marketing/CriticContractTest.php` | `test_there_is_only_one_retry_so_attempt_never_exceeds_two` → `test_at_most_two_rewrites_so_attempt_never_exceeds_three`. `forMetadata(['attempt' => 7])` daba 2 y ahora da **3**. Se añaden `MAX_ATTEMPTS = 3`, el 3 se queda en 3, el 2 en 2 y el vacío en 1 | Es el cambio obligatorio de P-2 (H8). El plan lo daba por no fijado [S]; sí lo estaba. **No se relaja:** el tope sigue fijado, ahora en 3 |
| `tests/Feature/Marketing/UltronDecidePromptsTest.php` (`test_la_matriz_de_la_oferta`) | Las claves de la respuesta llevan `regeneration` tras `studio_prompts` **solo** cuando el motivo es `studio`; se añade la aserción del valor exacto (o `null` en Legacy) | El bloque nuevo de §4.2; más estricta que antes |
| `tests/Unit/Marketing/Ultron/UltronRegenerarPiezasPurasTest.php` | `UltronRegenerationTrace` entra en la lista de piezas puras. `UltronDraftCheck` entra, con el token, en la de «sin `\p{…}`» (`SOLO_PCRE`) | Ampliada, no relajada |
| `tests/Feature/Marketing/InternalSecretAuthoritySurfaceTest.php` | El docblock «dos puertas» pasa a «tres puertas», y una prueba nueva (§4.6) | El plan lo pedía |
| `UltronDraftDefectsTest`, `UltronPlansOverviewTest` | Una prueba nueva en cada uno; ninguna existente cambia | — |

**Mis propias pruebas, corregidas antes de darlas por buenas** (la versión final es la del rojo 103, igual que el 102 en números):
- (a) La causa de longitud en la paridad, de `action ≠ fit` a `from > cap`, por el `replaced` descrito en §4.3.
- (b) El borrador «enlaces de la app que no caben» medía 169 + 30 = 199 y cabía; se le añadió «aquí» (204).
- (c) La comparación de filas de `UltronRegenerationCommitTest` quita además `outbound.message_id` (el id del saliente, distinto en cada conversación).

Ninguna aserción se debilitó.

### 4.6 Pruebas nuevas

| Fichero | Pruebas | Qué fija |
|---|---|---|
| `tests/Feature/Marketing/UltronCheckEndpointTest.php` | 47 (18 métodos) | Los tres casos y la respuesta exacta; `attempt` 3 → `last_attempt`; 25 s todavía y 26 s → `time_budget`, con `setTestNow` en UTC. **Cada código con su nota LITERAL** (copiadas a mano de §7.2): tuteo, los seis detalles de la lista (también el repetido), la longitud con su medida y el beneficio. La decisión del lead (con herramientas, sin pedir el precio, cobro ×3). Legacy, sin negociar y con los prompts apagados: **cero sentencias**. `decide` ofrece el contrato solo con Studio. **P-8:** 401 sin secreto o con otro; 8 tokens que no valen → exactamente `{ok:false, code:invalid_token}`; `unexpected_fields` en la raíz y en `draft`; 16 formas inválidas; **solo `select`**, `Cache::lock` nunca, `Http::assertNothingSent()`, cola y bus vacíos, las filas de las tablas `marketing_*`, `plans`, `payment_transactions` e `incidents` iguales, y la conversación (memoria incluida) igual. Un entrante con nombre, conjunto y teléfono únicos que no salen ni en la respuesta ni en el log (`MessageLogged`), ni el token ni su firma, con la lista blanca de claves. **No es un oráculo:** URL y tarjeta pasan la comprobación y el commit las tumba. Una guarda estática: el servicio no nombra cerrojo, caché, red, despachador, escrituras, `UltronPublishedConfig`, el commit ni `verify()`, y sí `peek()` |
| `tests/Feature/Marketing/UltronCheckCommitParityTest.php` | 26 | §4.3: la matriz de 23 y E1 a E3 |
| `tests/Feature/Marketing/UltronRegenerationCommitTest.php` | 24 | Con el bloque, la fila lo lleva. Sin él, sin la clave y con lo demás idéntico: el texto, el saliente, la fase y la metadata salvo `critic` y los ids. Incoherente: solo `consistent: false`. `critic.attempt` 3 aceptado en el camino normal y en el del fallo del revisor (respaldo con la traza); la rendición, con `consistent: null`. 20 formas inválidas (el cuarto intento del revisor y 19 del bloque) → 422 sin fila. Tres claves de más → `unexpected_fields` exacto |
| `tests/Unit/Marketing/Ultron/UltronRegenerationTraceTest.php` | 4 | Sin bloque; la forma cerrada; `consistent` (true, false y null); la defensa en profundidad (contrato, `drafts`, rangos, códigos e issues fuera del vocabulario, autor ajeno, más de dos, ningún texto) |
| `UltronDraftDefectsTest::test_un_marcador_que_no_es_de_la_lista_se_senala_siempre` | 1 | La decisión del lead en la pieza pura |
| `UltronPlansOverviewTest::test_la_lista_firmada_y_el_turno_de_cobro` | 1 | Las dos piezas extraídas, con 11 formas de `po` que no valen |
| `InternalSecretAuthoritySurfaceTest::test_the_third_ultron_door_only_reads` | 1 | La tercera puerta: 401, `invalid_token`, `legacy` y ni una fila |

**Mutaciones a mano** (110; cada una deshecha y comprobada con `cmp`; además, al final, `cmp` de los nueve ficheros de producto contra el respaldo) **[C]**:

| # | Mutación | Pruebas que fallan |
|---|---|---|
| M1 | El presupuesto con `>=` (a los 25 s ya no vale) | 1 |
| M2 | La lista también vale en el cobro, en la puerta | 2 |
| M3 | Sin la decisión del lead | 4 |
| M4 | `persist()` sin la traza | 3 |
| M5 | Sin la lista blanca de cada reescritura | 1 |
| M6 | Los enlaces de la app no se cuentan | 2 |

### 4.7 Números

| # | Qué | Resultado |
|---|---|---|
| 102 | Las pruebas nuevas y las tocadas (primera versión) con `app/` y `routes/` = HEAD | **ROJO:** 280 pruebas, 8 errores, 101 fallos |
| 103 | Las mismas, en su versión FINAL. `app/` y `routes/` puestos a HEAD con `git show` (las dos clases nuevas, apartadas), restaurados y comparados con `cmp` | **ROJO:** 280 pruebas, 8 errores, 101 fallos, 1 aviso (solo en rojo; en verde no hay ninguno con `--display-warnings`) |
| 104 | Las mismas, en verde | **OK (280 pruebas, 11 530 aserciones)** |
| 105 | Motor: `tests/Unit/Marketing/Ultron`, las tres A4, `UltronDecideTest`, `UltronDecidePromptsTest`, `UltronCommitTest`, `UltronCommitPromptsTest`, `UltronPromptsN8nCommandTest`, `UltronPromptsTraceCommandTest` y las tres nuevas de Feature | **OK (1 781 pruebas, 36 897 aserciones)** |
| 106 | `tests/Feature/Marketing tests/Unit/Marketing` | **OK (6 701 pruebas, 58 444 aserciones)**: las 6 597 de la ola 3 + 104 nuevas |
| 107 | En PostgreSQL (`UNIT_DB=ultron_studio_lead_ola5`, local y desechable): las tres nuevas de Feature y las tres A4 | **OK (354 pruebas, 3 598 aserciones)**: 257 A4 + 97 nuevas |
| 108 | Guardas: los 16 ficheros | 141 pruebas, **1 fallo esperado** (`OutreachIsolationTest::test_la_puerta_de_diff…`); ninguna otra guarda roja por la ruta o el controlador |
| 109 | `php artisan route:list --path=internal/marketing/ai -v` | `POST api/internal/marketing/ai/check → UltronController@check`, con `api`, `automation.internal` y `throttle:internal-marketing-ai`, como `decide` y `commit` |
| 110 | Mutaciones | 6 de 6 cazadas |
| 111 | Volcado de los tres casos del contrato | §4.2 |
| — | `vendor/bin/pint --test` sobre los 19 PHP tocados o nuevos | `passed`, sin cambios |
| — | n8n | `git status` limpio en `n8n/`. Los sha256 de `elegir-prompts.js`, `preparar-commit.js` y `operaciones-mcp.json`, iguales a los de HEAD (`fec89513…`, `876a3f6a…`, `1e1aca5a…`). No hubo que regenerar nada: los fixtures `decide-*.json` son sintéticos y no salen de `decide` |

Salida de la ruta (109):

```
POST  api/internal/marketing/ai/check ....... Api\Internal\UltronController@check
      ⇂ api  ⇂ automation.internal  ⇂ throttle:internal-marketing-ai
```

**La puerta de diff del H9** nombra ahora **19** ficheros, y esta ola añade **3**: `UltronController.php`, `CriticContract.php` y `routes/marketing.php`. Los otros dos que toca esta ola (`UltronCommitService.php` y `UltronDecideService.php`) ya estaban, y `UltronDraftDefects.php` y `UltronPlansOverview.php` también, por las olas 1 a 3.

Su segunda aserción, la de los ficheros nuevos sin seguimiento en zonas protegidas, no llega a ejecutarse porque falla la primera. Al subir nombrará `UltronDraftCheck.php` y `UltronRegenerationTrace.php`. Todo va a la Enmienda A4-3, donde el plan ya prevé el controlador y las rutas.

### 4.8 Lo que no comprobé

- **n8n:** el flujo S2 no existe todavía (ola 5). Que n8n trate cualquier respuesta que no sea un 200 bien formado como «no reescribir» es del flujo, no de aquí. Aquí solo se fija que la puerta contesta 401, 422 o 200 con su forma.
- **La latencia real de la comprobación** (el plan supone de 0,1 a 0,3 s) no la medí: en las pruebas, con SQLite en memoria, no significa nada. Lee 3 o 4 sentencias `select` (el mensaje, el catálogo y, si hay marcadores, los planes de la lista y el que cotiza). El plan propone un tiempo máximo de 3 s en el nodo; eso es de la ola 5.
- **E4 a E6** quedan declaradas sin prueba propia (§4.3).
- **El cubo compartido de 120 por minuto (R4):** sin cambios. Dos comprobaciones más por turno del Studio; con la canaria es irrelevante y es condición antes de ampliar.
- **La sonda de PCRE:** esta ola no añade ninguna expresión regular (grep de `preg_` y `regex:` en el diff: ninguna).
- **Las pruebas solo de PostgreSQL** fuera de las nuevas y de las A4 no las ejecuté.
- **Para `security` / `api-security-specialist`:**
  - la decisión 8 (la puerta no mira el interruptor, el freno ni el canario);
  - el 422 `invalid_token` sin detalle;
  - la línea de log `ultron.check.result`;
  - que la puerta revela, a quien tenga el secreto y un token válido, los nombres y precios de la lista firmada **solo** como medida (nunca en la respuesta: la nota N-L da la longitud, no el texto);
  - el bloque `regeneration` como traza sin textos.
- **Casos límite para `tester`:**
  - un token emitido justo antes de un cambio de reloj del servidor (`elapsed_s` se acota a 0);
  - un `po` con forma rara solo se fabrica re-firmando;
  - la comprobación con un mensaje borrado (cuerpo vacío; sigue contestando);
  - los casos de §6 «Ola 7» del plan que dependen de S2: el tercer borrador sin comprobar que el commit recorta, el servidor caído y el presupuesto agotado entre la comprobación 2 y el revisor 2.

### 4.9 Variables, migraciones y configuración

- Ninguna migración, ningún `config/` y ninguna variable de entorno.
- **Sí hay una ruta nueva:** al desplegar hace falta `route:cache` y comprobar con `route:list` que existen `internal/marketing/ai/{decide,check,commit,ultron/incidents}`. Lo dice el plan, §9, paso 1; `hotfix-backend.sh` rechaza `backend/routes/*`.

---

## 5. Ola 4b

19 y 21-11-2026 · rama `feat/ultron-studio-h9`, HEAD `05f7109c` (olas 1 a 4 confirmadas; los tres commits del lead posteriores a `d63fbd50` solo tocan `docs/` y `ops/`) · **sin commit**: todo está en el árbol de trabajo, nada desplegado, sin producción y sin n8n (ni MCP ni API).
Guía: el encargo del lead (`encargos-regenerar/encargo-be-r4.md`, idéntico al que seguí) y `plan-arquitecto-a4-regenerar.md` §3 P-5, §4.1, §4.4, §11 y §13 (L-3).

### 5.0 Resumen

- **Entran las seis reglas** de política del encargo: `policy_greeting_sells`, `policy_active_goal_lost`, `policy_wrong_first_plan`, `policy_answer_without_fact`, `policy_general_info_only_discovery` y `policy_memory_turn_sells`. **No hubo que dejar ninguna fuera:** la paridad sale limpia para las seis **[C]**.
- **La política se calcula igual que en el commit** y sin escribir: un lector nuevo de solo lectura, `UltronTurnPolicyReader`, con las mismas funciones y entradas del commit. La política que lee la comprobación es **la misma, clave a clave, que guarda el commit en `metadata.policy`** en cada fila que la política sustituyó. Se probó en SQLite y en PostgreSQL **[C]**.
- **El texto que se mira es el del commit:**
  - el plan que impone la política pone `{{PLAN_NAME}}`/`{{PLAN_PRICE}}`;
  - el modo memoria quita los enlaces de la app;
  - el tope y el registro quitan la pregunta final (F1 y G2-bis);
  - el saludo repetido se recorta.
  Cada uno está fijado con una prueba o una mutación (§5.4 y §5.9).
- **El orden:** primero los cuatro de §4.4 y después los de política, en el orden del encargo. **El tope de `defects` sube de 4 a 8** (`UltronDraftDefects::MAX_DEFECTS`): 4 + 4, porque de la política no pueden coincidir más de cuatro (§5.5, decisión 3). Al mismo 8 baja el tope de `regeneration.rewrites.*.codes` del commit, que si no habría crecido solo a 10.
- **El commit no cambia de comportamiento.** Sigue sustituyendo cuando la violación llega en el último borrador. Su único cambio es una extracción sin efecto: `hechosDelGimnasio()` llama a `CommercialTurnPolicy::knownFacts()`.
- **Invariantes [C]:**
  - las dos instantáneas Legacy, en verde y **sin regenerar** (`git status` limpio en `tests/fixtures` y en `n8n/`);
  - sin versión del Studio, la puerta contesta `legacy` sin una sola sentencia, también con un borrador que vende al saludar;
  - las pruebas de solo lectura de la ola 4, en verde, y una nueva con la memoria en juego.
- **Tres de las seis notas no cuadran del todo con lo que exige la regla** (§5.3). Las he puesto LITERALES, como pide el encargo, y propongo la corrección con la línea.

### 5.1 Ficheros tocados

| Fichero | Cambio | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/UltronRegenerationNotes.php` | +60/−2 | `POLICY_*` (el código = la constante de la violación en `CommercialTurnPolicy`), `POLICY_CODES` y `CODES` = los 4 de §4.4 + los 6 de la política. Las seis notas LITERALES, **extraídas del encargo por guion**: cada una aparece tal cual en el encargo, comprobado con `grep -F`. Sus seis entradas en la tabla (sin detalle ni parámetros), con su `issue` y su porqué (decisión 2). Docblock |
| `app/Services/Marketing/Ultron/UltronDraftDefects.php` | +85 | `MAX_DEFECTS = 8`, con la cuenta en el docblock. `ofPolicy()`: pura; los dos recortes del commit, `violations()` y el saludo repetido recortado; las seis en el orden de `POLICY_CODES` |
| `app/Services/Marketing/Ultron/UltronTurnPolicyReader.php` | **nuevo**, 82 | La política del turno leída como en el commit: la intención con el saludo puro como saludo, la fase normalizada, `load()` de la memoria, el resolutor sobre `sellablePlansForMemory()`, `CommercialTurnPolicy::decide()` y los hechos del gimnasio solo si la política los exige. Del servicio de la memoria, solo `load()` y `sellablePlansForMemory()` |
| `app/Services/Marketing/Ultron/UltronDraftCheck.php` | +60/−15 | Lee la conversación y la política (con el lector). La expansión ve la política: el plan impuesto y el modo memoria. Concatena `of()` + `ofPolicy()` y corta a `MAX_DEFECTS`. Docblock |
| `app/Services/Marketing/Ultron/CommercialTurnPolicy.php` | +32 | `knownFacts(iterable $items, array $classes)`: pura, **extraída tal cual** de `hechosDelGimnasio()` del commit |
| `app/Services/Marketing/Ultron/UltronCommitService.php` | +2/−13 | `hechosDelGimnasio()` llama a `knownFacts()`. **Nada más del commit** |
| `app/Http/Controllers/Api/Internal/UltronController.php` | +3/−1 | `regeneration.rewrites.*.codes`: `max:` pasa de `count(CODES)` a `UltronDraftDefects::MAX_DEFECTS` (decisión 3) |
| Pruebas: 6 tocadas | | §5.6 y §5.7 |
| `docs/…/a4-evidencia/112` a `120` (y `118a`) | nuevos | Salidas (§5.9 y §6.7) |

**Sin tocar:**
- el compilador y los prompts base;
- `config/`, las migraciones y las rutas;
- `SalesConversationReplyService`, `SalesObjectionResponderService`, el guard y `CourtesyRequestService`;
- el CRM y n8n;
- `ConversationMemoryService` y `ReferenceResolver`: se usan, no se tocan.

### 5.2 Cómo calcula la comprobación la política «igual que el commit»

| Entrada | En el commit | En la comprobación |
|---|---|---|
| La intención | `sanitize()` + «un saludo puro es un saludo» (`UltronCommitService.php:640-645`) | La del borrador (la puerta ya exige una del catálogo, y `sanitize()` solo cambia la intención fuera de él) con el mismo «saludo puro» (`intentForPolicy()`) |
| La fase | `UltronDecideService::currentPhase()` = `CommercialPhaseMachine::normalize()` | `normalize()`, la misma. No influye en `violations()` |
| La memoria | `memoryService->load($conversation)` (`:729`), la de ANTES del turno. Los rechazos que anota el commit van después de `decide()` y no la cambian | `load()`, que LEE: la pregunta sin resolver y el objetivo caducado se derivan al leer y no se guardan |
| El referente | `references->resolve(body, memoria, sellablePlansForMemory())` | Lo mismo |
| El catálogo y el entrante | `activePlans()` y `message->body` | Lo mismo (el catálogo ya lo leía la puerta) |
| Los hechos | `hechosDelGimnasio()` | `knownFacts()`, la misma función, solo si `required_facts` lleva `gym_identity`: `violations()` no los mira en otro caso, así que el resultado es idéntico y se ahorran las consultas |
| El tono | `turnoDelStudio()`: `sp.t` si es de `UltronPromptCompiler::TONES` | Lo mismo |

**El texto que se pasa a `violations()`, como el del commit:**
1. **El plan impuesto.** Con `required_plan_id` de la prioridad comercial o nombrado por la persona, y distinto del propuesto, `{{PLAN_NAME}}`/`{{PLAN_PRICE}}` se expanden con él, como en «LA POLÍTICA PISA EL PLAN DEL MODELO» (`:839-868`). Antes era la mitad de E5.
2. **El modo memoria** quita el marcador y no pega los enlaces de la app (`:798-806`).
3. **El tope:** si el texto se pasa y quitándole la pregunta final cabe (F1, `UltronReplyFitter::draft()`), se mira sin ella (`:1661-1673`).
4. **El registro:** con «profesional», un bloque de Laravel (aquí, `app_links_send` pedida) y el tuteo SOLO en la pregunta final, se mira sin ella (`:1702-1716`).
5. **El saludo repetido:** si salta, se recorta y se vuelve a mirar (`:1741-1744`).

**Qué no se puede leer sin efectos, y por qué no hizo falta dejar ninguna regla fuera.** La política la decide `decide()` ANTES de las herramientas, con entradas que se leen sin escribir. Lo que depende de herramientas ya ejecutadas no es la política, sino el TEXTO. El commit lo retoca después de ejecutarlas: las actas de la cortesía, el cobro pegado. Es la familia de E2/E4 de la ola 4 (E8 abajo) y no cambia qué regla se aplica. Ninguno de los turnos de las seis reglas lleva herramientas.

### 5.3 Las notas: tres no cuadran del todo con lo que exige la regla (no las he tocado)

| Nota | Qué exige de verdad el código | Por qué no cuadra | Corrección que propongo (sin tildes) |
|---|---|---|---|
| `policy_active_goal_lost` | Salta solo con `goal_takes_turn` = visita a medias **y la persona NO pregunta nada ni saca los planes** (`CommercialTurnPolicy.php:435`). Basta con nombrar un plan o una cifra (`:703`) | «Contesta en una frase lo que pregunto»: cuando la regla salta, por construcción no hubo pregunta. Si la hubo, el turno es `resume_goal_after_answer` y la regla no salta. «No vendas» se queda corto: nombrar un plan sin vender también salta | `VISITA A MEDIAS: hay una visita de cortesia a medio concretar y la persona esta dando sus datos. Retoma la visita pidiendo solo el dato que falta (el dia o la hora). No nombres planes ni precios en este turno.` |
| `policy_wrong_first_plan` | (a) El primer plan nombrado tiene que ser `commercial_turn_policy.required_plan_id` (`:740-747`). (b) **El mismo código salta si se nombra, en cualquier sitio, un plan de `forbidden_plan_ids`**, uno que la persona rechazó (`:749-755`) | «El de la estrategia de este turno»: el obligatorio es el de la política, no el del estratega (el commit lo impone sobre el del estratega, `UltronCommitService.php:839`; el redactor lo conoce como «required_plan_id es el plan de este turno», `composer.txt:94`). Y en (b), «los demas, solo despues y si aportan» autoriza justo lo que tumba el turno: nombrar después el plan rechazado | `PRIMER PLAN EQUIVOCADO: el primer plan que nombres tiene que ser el de commercial_turn_policy.required_plan_id, y no nombres ninguno de forbidden_plan_ids (la persona los rechazo). Los demas permitidos, solo despues y si aportan.` |
| `policy_general_info_only_discovery` | Salta con `answer_first` y solo preguntas (`:731`). `answer_first` vale para `general_info`, `pricing_question`, `location_question`, `schedule_question`, `greeting` (no puro), la duda de precio y el modo memoria (`:525`, `:585`) | «La persona pidio informacion general» y «Da primero informacion concreta del gimnasio»: a quien preguntó el horario o el precio, lo que hay que darle es eso. La prueba lo ejercita (`el horario que solo pregunta`) | `SOLO PREGUNTAS: la persona pidio informacion y el borrador solo pregunta. Responde primero con lo que pidio, con un dato concreto, y deja como mucho una pregunta al final.` |

**Dos observaciones menores, sin contradicción:**
- `policy_answer_without_fact` se cumple solo si el texto lleva, tal cual, los primeros 25 caracteres normalizados de un hecho del CRM o el nombre de una clase (`factPresent()`, `:953`). Una paráfrasis del horario no cuenta. Si el propietario quiere, la nota podría decir «copia el dato de knowledge_base tal cual».
- `policy_greeting_sells` dice «Saluda». Si `greet_required` es falso, el commit recorta el saludo de más y no sustituye, así que no se contradice.

### 5.4 La paridad (`UltronCheckCommitParityTest`)

Sobre el MISMO turno (misma conversación, mismo token y la memoria escrita ANTES de `decide`), la comprobación marca cada código de política **si y solo si** el commit lo pone en `policy_violations`. Las causas del commit son las de la ola 4 más `policy_violations ∩ POLICY_CODES`. Además, si la política sustituyó el borrador, **la política leída por la comprobación es `metadata.policy` del commit**, comparada con las claves ordenadas (`jsonb` las reordena).

| # | Regla | Turno (memoria) | Borrador | Las dos dicen |
|---|---|---|---|---|
| 1 | saludo | «Hola buenas tardes», `greeting` | «… ¿Ya ha entrenado antes?» | `policy_greeting_sells` |
| 2 | saludo | «Hola», **`pricing_question`** (el saludo puro manda) | «… El Plan Mensual es el más pedido. …» | `policy_greeting_sells` |
| 3 | saludo (neg.) | «Hola buenas tardes» | «… Cuénteme, ¿en qué le podemos ayudar hoy?» | — |
| 4 | saludo + tope | «Hola», 216 caracteres que acaban en «¿Cuál es su objetivo?» | F1 quita la pregunta | solo `length_over_cap` (el objetivo no llega a la política) |
| 5 | visita | «en la mañana» (visita a medias) | «… el Plan Mensual le sirve para empezar.» | `policy_active_goal_lost` |
| 6 | visita (neg.) | «en la mañana» (visita a medias) | «… ¿A qué hora le queda mejor venir?» | — |
| 7 | plan | «¿Cuánto cuesta el Plan Mensual?» | «El Plan Valera cuesta {{PLAN_PRICE}}…» (plan 3) | `policy_wrong_first_plan` |
| 8 | plan (neg.) | lo mismo | «El {{PLAN_NAME}} cuesta {{PLAN_PRICE}}…» (plan 3: la política impone el 4) | — |
| 9 | plan (neg.) | lo mismo | «El Plan Mensual cuesta…» (plan 4) | — |
| 10 | plan | horario (rechazó el Valera) | «… Con el Plan Valera puede venir…» | `policy_wrong_first_plan` |
| 11 | plan (neg.) | horario (rechazó el Valera) | «… Con el Plan Mensual…» | — |
| 12 | dato | «Quiero información del gimnasio» | sin un hecho del CRM | `policy_answer_without_fact` |
| 13 | dato (neg.) | lo mismo | con «lunes a viernes de 5:00 a. m. …» | — |
| 14 | dato + preguntas | lo mismo | «¿Qué le gustaría saber del gimnasio?» | `policy_answer_without_fact`, `policy_general_info_only_discovery` |
| 15 | preguntas | horario | «¿Para qué día … le gustaría saber el horario?» | `policy_general_info_only_discovery` |
| 16 | memoria | «¿A qué hora era mi visita?» | «… Si quiere, le cuento sobre los planes.» | `policy_memory_turn_sells` |
| 17 | memoria (neg.) | lo mismo | «… ¿Qué día le gustaría venir?» | — |
| 18-19 | memoria + enlaces | lo mismo, con `app_links_send` y con `{{APP_LINKS}}` | 196 caracteres (226 con los enlaces) | — (los dos miden sin los enlaces) |

**La matriz de la ola 4 (23 borradores) sigue en verde** con las causas ampliadas: ninguno de sus turnos incumple una de las seis. Las excepciones E1 a E7 del docblock siguen, con dos cambios y tres añadidos:
- **E5 se recorta:** el plan que impone la política ya no es excepción (fila 8; mutación M2). Queda la realineación del precio.
- **E7 se amplía:** un `PLAN_k` desconocido o una cifra son un 422 antes de `violations()`, y la respuesta repetida o la reventa sustituyen el texto antes de mirar la política. La comprobación puede decir además una regla de política, pero en esos turnos pide la reescritura de todos modos.
- **E8 (nueva):** los textos que el commit reescribe tras ejecutar herramientas (las actas de la cortesía) pueden quitar una frase que nombraba un plan. No tiene prueba propia; los turnos de las seis reglas no llevan herramientas.
- **E9 (nueva):** la memoria se lee unos segundos antes que en el commit. Un objetivo que caduca justo entre los dos (tras horas de antigüedad) lo verían distinto. No tiene prueba propia.
- **E1, la aserción final** (§5.7): volver a comprobar DESPUÉS del commit ya lee la memoria que ese commit escribió.

### 5.5 Decisiones mías (el encargo no las fija)

1. **Un lector aparte, en lugar de leer la memoria desde `UltronDraftCheck`.** La guarda estática de la ola 4 (`test_el_servicio_no_nombra_nada_que_escriba`) prohíbe que la puerta nombre `MemoryService`; relajarla está fuera. El lector tiene su **propia guarda, más estricta**: nada que escriba, ni `record`, `forget` o `remember`, y del servicio de la memoria solo `load` y `sellablePlansForMemory`. Además debe llamar a `CommercialTurnPolicy::decide(`.
2. **El `issue` de cada regla**, según la definición de `critic.txt`:
   - saludo y memoria → `customer_fit` (ahí define el revisor el modo recepción y el modo memoria, `:18`);
   - visita y plan → `phase_alignment` («coherente con la fase y el objetivo de la estrategia», `:15`);
   - dato y solo preguntas → `relevance` («responde lo que la persona pregunto», `:6`).
3. **El tope, 8 y no 10.** Los cuatro de §4.4 pueden coincidir. De la política, como mucho cuatro:
   - el saludo que vende exige el modo recepción, que apaga `answer_first` y excluye el modo memoria;
   - «sin el dato» no salta en modo memoria;
   - así que el máximo es la visita, el plan y dos de {sin el dato, solo preguntas, memoria}.

   La prueba unitaria construye una de cuatro (visita, plan, preguntas, memoria) y la de solo lectura otra (visita, plan, dato, preguntas). El corte es defensivo: por construcción no actúa. El mismo 8 vale para `codes` en el commit: son los códigos de UNA comprobación.
4. **El orden dentro de la política** es el del encargo (`POLICY_CODES`), no el de `violations()`, que pone la memoria antes que la visita. Con el corte a 500 por notas enteras, tras N-R y N-L (unos 460 caracteres) ya no cabe ninguna de política. Eso está previsto («`notes` sigue cortándose»): el `issue` y el código siguen en `defects`.
5. **Los hechos, perezosos:** solo se leen si la política los exige. El resultado es idéntico, porque `violations()` no los mira en otro caso.
6. **El recorte del saludo repetido se hace aunque hoy no cambie nada** (mutación equivalente, M1): ninguna de las seis mira la fórmula del principio. Se mantiene para que la paridad no dependa de eso.
7. **La conversación borrada** entre `decide` y la comprobación (el token la ata, así que es raro): sin política, solo los de §4.4.

### 5.6 Pruebas nuevas

| Fichero | Pruebas | Qué fija |
|---|---|---|
| `UltronCheckCommitParityTest` | +19 (3 métodos; uno con 17 casos) | §5.4: la matriz de las seis con sus negativos y la política del commit; el modo memoria con los enlaces (dos formas); leer la política no cambia la memoria ni crea filas |
| `UltronCheckEndpointTest` | +10 (5 métodos; uno con 6 casos) | Cada regla con su nota LITERAL (copiadas a mano del encargo), su `issue` y `rewrite`. El orden (tuteo y saludo: `[register_tuteo, policy_greeting_sells]`) y las notas en ese orden. `MAX_DEFECTS = 8`. Sin Studio, ni la política: cero sentencias. **Solo lectura con la memoria en juego** (visita a medias y plan rechazado): solo `select`, sin cerrojo, sin cola ni bus, sin HTTP, las filas iguales y la conversación (memoria incluida) igual. La guarda estática del lector |
| `UltronDraftDefectsTest` | +17 (5 métodos; uno con 13 casos) | `ofPolicy()`: cada regla y su negativo, lo mismo que `violations()`; sin Studio, nada; el orden; el saludo repetido; los dos recortes (con 200 y con 480; con y sin bloque; con y sin «profesional») |
| `UltronRegenerationNotesTest` | +2 (métodos) | Las seis con su `issue` y su nota LITERAL, ASCII imprimible; el código es la constante de la violación; las que no entran siguen fuera (saludo repetido, panorama, la contradicción con el compromiso, longitud, registro y beneficio) |
| `UltronRegenerationTraceTest` | +1 | Los códigos de política pasan a la traza; `policy_greeting_repeated`, no |

### 5.7 Aserciones existentes cambiadas, y por qué

Todas, porque las seis reglas pasan a ser códigos. **Ninguna se relaja**:

| Fichero | Cambio | Por qué |
|---|---|---|
| `UltronRegenerationNotesTest` | `CODES`: 4 → 10. Entradas: 9 → 15 (dos recuentos). El «código desconocido» pasa de `policy_greeting_sells` a `policy_greeting_repeated` (en la lista y en `issue()`). Se añaden dos casos malos: la política con detalle y con parámetros | `policy_greeting_sells` ya es un código. El sustituto es la regla que NO entra, así que la prueba fija además la exclusión |
| `UltronRegenerationCommitTest` (`bloquesQueNoValen`) | «código inventado»: `policy_greeting_sells` → `policy_greeting_repeated`. «cinco códigos» → «nueve códigos» (los 9 válidos y distintos, con el tope en 8) | Lo mismo, y el tope nuevo: sigue habiendo una lista que no cabe |
| `UltronCheckCommitParityTest::test_e1_…` | La comprobación de DESPUÉS del commit: `[]` → `['policy_wrong_first_plan']`, y se afirma por qué. La política leída ahora obliga al plan 14 (`resolved_reference`): el commit saludó y ofreció la lista fija, y el resolutor da por referida su PROMO X4 en «la mensualidad» | Es la memoria que ese commit acaba de escribir: en la vida real la comprobación va antes. Lo que la aserción fijaba (la lista `not_sellable` no es un código corregible) sigue: no sale `plans_overview`. Es más estricta: fija el valor exacto y su causa |
| `UltronRegenerarPiezasPurasTest` | `UltronReplyOrigin` entra en las puras; el lector y el comando, en «sin `\p{…}`» | Ampliada |

**Mis propias pruebas, corregidas antes de darlas por buenas:**
- (a) La aserción «subconjunto ordenado» de `test_el_orden_de_la_politica` estaba mal escrita: comparaba con un `array_unique` de la concatenación.
- (b) El orden de las claves del esperado en `UltronReplyOriginTest`.
- (c) La consulta de la línea base por PDO (118a, §6.7).
- (d) El docblock de la prueba del saludo repetido, tras ver que M1 es equivalente.

### 5.8 Lo que no comprobé

- **[S] Que el modelo corrija con estas notas.** Aquí no corre ningún modelo, y las tres de §5.3 lo ponen más difícil.
- **E8 y E9**, sin prueba propia (§5.4).
- **La latencia de la puerta:** ahora lee además la conversación, la memoria (los seis últimos mensajes), los planes para la memoria y, si la política los exige, los hechos del gimnasio (la base de conocimiento, las clases y sus ocupaciones, y los entrenadores: `forPrompt()` entero). Son unas 6 a 12 sentencias `select` en lugar de 3 o 4. En SQLite en memoria no significa nada; el plan supone de 0,1 a 0,3 s y el nodo tiene 3 s.
- **La sonda de PCRE:** esta ola no añade expresiones a la política (usa las de siempre). Las nuevas son `/\A[a-z][a-z0-9_]{0,63}\z/`, `/\A[A-Za-z0-9_.:-]{1,64}\z/` y `/\A[1-9][0-9]{0,18}\z/` (ola 6, sin `\p{}`).
- **Para `security` / `api-security-specialist`:**
  - la puerta lee ahora la memoria de la conversación, con su lector y su guarda (§5.5, decisión 1);
  - la respuesta sigue sin texto de la persona: códigos, `issue` y notas fijas;
  - las notas de política no llevan parámetros.
- **Casos límite para `tester`:**
  - la memoria con un objetivo que caduca entre la comprobación y el commit (E9);
  - una regla de política en un turno con herramientas de cortesía (E8);
  - la conversación borrada entre `decide` y la comprobación.

---

## 6. Ola 6

21-11-2026 · misma rama y mismo HEAD · **sin commit**. Guía: el encargo de la ola 4b (es el mismo encargo) y el plan §3 P-7, §6 «Ola 6» y §12.

### 6.0 Resumen

- **`UltronReplyOrigin::classify($metadata, $actionType)`**, pura, aplica las MISMAS reglas que la consulta C de la línea base y en su orden:
  1. `fallback_mode` → respaldo (del revisor o de la rendición);
  2. si no, la política con su borrador descartado → respaldo de la política;
  3. si no, el modelo, recortado si quitó su pregunta final.

  Del modelo dice además si fue al primer intento, regenerado (y quién lo pidió, con qué códigos) o sin dato. Con la traza de la ola 4 lo lee de ella; sin traza, del intento del revisor (en S1 solo el revisor reescribía). **La hora de la visita se marca aparte:** es texto del servidor que la consulta cuenta como del modelo.
- **`php artisan ultron:origin-report`**, de SOLO LECTURA (ninguna sentencia de escritura, fijado por prueba), con `--conversation=*`, `--since`, `--until`, `--json`, `--precio-entrada` y `--precio-salida`, según la «Ola 6» del plan.
- **Reproduce la línea base [C]:** sobre 88 filas equivalentes a las históricas, 71 del modelo (80,7 %), 14 respaldos del revisor o de la rendición y 3 de la política. **En PostgreSQL, la consulta C del `.sql`, ejecutada tal cual sobre las mismas filas, da lo mismo**: 70 + 1 recortada, 3 y 14.
- **`ultron:canary-report` no se toca** (`git status`: sin cambios).

### 6.1 Ficheros

| Fichero | Cambio | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/UltronReplyOrigin.php` | **nuevo**, 224 | La clasificación y `modelCalls()` |
| `app/Console/Commands/Marketing/UltronOriginReport.php` | **nuevo**, 477 | El comando (se descubre solo, como los demás de `app/Console/Commands`) |
| `tests/Unit/Marketing/Ultron/UltronReplyOriginTest.php` | **nuevo** | 21 pruebas (5 métodos) |
| `tests/Feature/Marketing/UltronOriginReportCommandTest.php` | **nuevo** | 7 pruebas; una solo en PostgreSQL |

### 6.2 La clasificación

`classify()` devuelve, con forma cerrada:
- `origin`: `model` o `fallback`.
- `attempt`: `first`, `regenerated`, `unknown` o null en un respaldo.
- `drafts`, `critics` y `checks`.
- `rewrites`: `[{by, codes, issues}]`.
- `fallback_reason`: `critic_rejected`, `recovery`, `policy`, `last_resort` o `unknown`.
- `policy_codes` y `recovery_reason`.
- `trimmed`, `server_text` (`visit_hour`), `traced` y `tokens`.

| Camino (metadata de fixture) | Origen |
|---|---|
| El revisor aprueba al primer intento | modelo, `first`, 3 llamadas |
| Recortado por el tope o por el registro | modelo, `trimmed` |
| Histórica: `critic.attempt` 2 aprobada | modelo, `regenerated`, una reescritura del revisor, 5 llamadas |
| Con traza: la pidió el servidor | modelo, `regenerated`, `by: server` con sus códigos, 4 llamadas, tokens |
| Con traza: dos reescrituras | modelo, `drafts` 3 |
| `policy_violations` + `policy_discarded_draft` | respaldo `policy`, con los códigos |
| `fallback_mode` + revisor `fail` | respaldo `critic_rejected` (también con 3 borradores y traza) |
| `fallback_mode` + `recovery` | respaldo `recovery`, con su etiqueta (`critic_unavailable`) |
| `fallback_mode` solo | respaldo `unknown` |
| `visit_hour` | modelo (como la consulta), `attempt unknown`, `server_text: visit_hour` |
| `repeated_reply_recovered` / `resell_blocked_recovered` | respaldo `last_resort` (fuera del reparto en el informe) |
| Sin nada | modelo, `unknown` |

**Nada fuera de vocabulario sale:**
- de la política, solo códigos con forma `^[a-z][a-z0-9_]{0,63}$`;
- de la rendición, etiquetas con la forma que ya admite el commit;
- de las reescrituras, solo `CODES` y `DIMENSIONS`;
- los números, solo en su rango.

### 6.3 El comando

**El universo** es el de la consulta: `marketing_ai_actions` con `source_type = inbound_message`, `action_type = reply` y `status = executed`. Lo demás del motor va en «Fuera del reparto». Las filas sin `source_type` (Legacy, el enrutador: `inbound_skipped`, `unsupported_message`) no son turnos de ULTRON y no cuentan.

**Lo que dice:**
- **El reparto:** el total y los turnos con traza. El porcentaje de la IA frente al 90 % (`sí`/`NO`) y, aparte, al primer intento, regeneradas y sin dato. Los recortes. La hora de la visita y el porcentaje sin ella.
- **Las reescrituras**, por autor y por código.
- **Los respaldos**, por causa, con los códigos de la política y las etiquetas de la rendición.
- **La latencia** (s) del `created_at` del entrante al del saliente (`metadata.outbound.message_id`): p50, p95 y máximo, por rango más cercano.
- **Las llamadas al modelo por turno** (1 + redacciones + revisores) y **las comprobaciones del servidor**: media y máximo.
- **Los tokens y el costo**, solo con los dos precios: Σ entrada/10⁶ × precio + Σ salida/10⁶ × precio, sin moneda impuesta.
- **Los reintentos del job:** turnos del reparto con su evento, con `attempts > 1` y el máximo.
- **El aviso:** el 90 % se juzga con al menos 30 turnos con traza (§12).

**Sin datos personales:**
- de los mensajes solo se leen `id` y `created_at`;
- de la metadata solo salen códigos y números;
- no hay ids de conversación (la ventana dice cuántas, no cuáles).

**Ejemplo real con datos de prueba** (una canaria inventada con S2: diez turnos con traza y un bloqueo, volcados con el comando de verdad sobre SQLite; evidencia 121):

```
ULTRON · ¿quién escribió cada respuesta? (solo lectura)
  Ventana: desde el principio · hasta ahora · conversaciones: todas
  Respuestas enviadas: 10 (con traza de regeneración: 10)
  Escrito por la IA: 8 de 10 (80,0 %) · objetivo 90 %: NO
    al primer intento: 4 (40,0 %) · regeneradas: 4 (40,0 %) · intento sin dato: 0 (0,0 %)
    Recortadas (sin su pregunta final): 1
    La hora de la visita (texto del servidor que la línea base cuenta como del modelo): 0 · sin ella: 80,0 %
  Reescrituras: 7 (servidor: 4, revisor: 3)
    por código: register_tuteo: 2, length_over_cap: 1, policy_greeting_sells: 1
  Respaldos: 2 de 10 (20,0 %)
    el revisor rechazó todos los intentos: 1
    la política del commit: 1 (memory_turn_wrong_commitment: 1)
  Latencia del entrante al saliente: p50 10 s · p95 27 s · máx 27 s (n = 10)
  Llamadas al modelo por turno: media 4,1 · máx 7 (n = 10) · comprobaciones del servidor por turno: media 1,4 · máx 2 (n = 10)
  Tokens: entrada 325900 · salida 6610 (n = 10) · costo estimado: 0,8809 (con los precios dados, por millón de tokens)
  Reintentos del job: 1 de 10 turnos con evento (máx. intentos 2)
  Fuera del reparto: no_reply/executed: 1
  Aviso: el 90 % se juzga con al menos 30 turnos con traza; aquí hay 10.
```

### 6.4 Decisiones mías

1. **La hora de la visita, del lado de la consulta.** La regla del encargo es «clasificar las filas históricas igual que la consulta de la línea base», y la consulta la cuenta como del modelo. Pero el texto lo escribe Laravel desde la fila del CRM. No la pinto como respaldo para no romper la comparación con la línea base; la marco y el informe da el porcentaje con y sin ella. **Si el lead prefiere contarla como respaldo**, es una línea en `classify()`, y la comparación con la línea base cambiaría en esas filas (hoy no sé cuántas hay entre las 88: son parte de las 6 «sin dato» del revisor, que la consulta F no desglosa).
2. **«Recortadas»** cuenta también el recorte por registro (H4-9, posterior a la línea base), que no cambia el origen.
3. **Las reescrituras se cuentan en todos los turnos del reparto**, también en los respaldos (el revisor que pidió una reescritura y luego volvió a rechazar). «Regeneradas» son solo las del modelo.
4. **Las llamadas al modelo sin dato** (la rendición, la hora de la visita, filas viejas) no entran en la media.
5. **`--until` sin incluirla**, y las fechas sin zona en UTC (la de la aplicación).
6. **Los órdenes** son los de los vocabularios (autores, causas, `CODES`), y lo demás por frecuencia, para que la salida no dependa del orden de las filas.

### 6.5 Aserciones existentes cambiadas

Ninguna.

### 6.6 Lo que no comprobé

- **Los tokens reales:** dependen de que n8n entregue `usage` (plan P-7 [S]). Aquí solo se prueban con fixtures.
- **La latencia** se mide con `created_at` de segundo entero, sin milisegundos.
- **Contra producción no ejecuté nada.** El reparto real lo da el comando en el servidor con orden, o la consulta del `.sql`.

### 6.7 Números (olas 4b y 6)

| # | Qué | Resultado |
|---|---|---|
| 112 | Las pruebas nuevas y tocadas con `app/` = HEAD (19-11) | **ROJO:** 183 pruebas, 68 errores, 15 fallos, 1 aviso y 1 omitida. El aviso es el proveedor de `UltronReplyOriginTest` sin su clase: sus 17 casos no se contaron (183 + 17 = 200) |
| 113 | Las mismas, en verde (con `--display-warnings`) | **OK (200 pruebas, 1 656 aserciones)**, 1 omitida (solo PostgreSQL) |
| 114 | Motor: `tests/Unit/Marketing/Ultron`, las tres A4, `UltronCheckEndpointTest`, `UltronCheckCommitParityTest`, `UltronDecideTest`, `UltronDecidePromptsTest`, `UltronCommitTest`, `UltronCommitPromptsTest`, `UltronRegenerationCommitTest`, `UltronPromptsN8nCommandTest`, `UltronPromptsTraceCommandTest` y el informe | **OK (1 857 pruebas, 37 567 aserciones)**, 1 omitida |
| 115 | `tests/Feature/Marketing tests/Unit/Marketing` (19-11) | **OK (6 777 pruebas, 59 114 aserciones)**, 1 omitida: las 6 701 de la ola 4 + 76 (las 77 nuevas, menos la del modo memoria con enlaces, añadida después) |
| 116 | El motor otra vez, tras la pausa (21-11), con la prueba del modo memoria con enlaces | **OK (1 858 pruebas, 37 586 aserciones)**, 1 omitida |
| 117 | Mutaciones a mano, una cada vez, restaurado cada fichero byte a byte (y sha256 de los seis al final) | **11 de 12 cazadas**. M1 (sin el recorte del saludo repetido) sobrevive: es equivalente (§5.5, decisión 6) |
| 118a | PostgreSQL, primera pasada | 390 pruebas, **1 error, de la prueba**: la consulta de la línea base por PDO toma el `?` de `jsonb` por un parámetro. Corregido en la prueba con el escape de PDO (`??`) solo en esos dos operadores, contados; el `.sql` no cambia |
| 118 | PostgreSQL (`UNIT_DB=ultron_studio_lead_ola5`, local y desechable): las cuatro de Feature y las tres A4 | **OK (390 pruebas, 4 055 aserciones)**, **ninguna omitida**: la consulta C real coincide con el informe |
| 119 | Guardas: los 16 ficheros | 141 pruebas, **1 fallo esperado** (`OutreachIsolationTest::test_la_puerta_de_diff…`) |
| 120 | `tests/Feature/Marketing tests/Unit/Marketing`, final | **OK (6 778 pruebas, 59 133 aserciones)**, 1 omitida (solo PostgreSQL): las 6 701 de la ola 4 + 77 nuevas |
| 121 | El ejemplo del informe (§6.3) | Volcado |
| — | `vendor/bin/pint` sobre los 18 PHP tocados o nuevos | `passed` |

**La puerta de diff del H9** nombra **21** ficheros, y **esta ola no añade ninguno**: los seis de producto que toca ya estaban en el diff de HEAD frente a `origin/main` (comprobado con `comm`). Su segunda aserción, la de los ficheros nuevos sin seguimiento en zonas protegidas, no llega a ejecutarse porque falla la primera. Al subir nombrará `UltronTurnPolicyReader.php` y `UltronReplyOrigin.php`. Van a la Enmienda A4-3.

**Las mutaciones** (117):

| # | Mutación | Pruebas que fallan |
|---|---|---|
| M1 | Sin el recorte del saludo repetido | **ninguna (equivalente)** |
| M2 | La puerta no pone el plan que impone la política | 1 (fila 8 de la paridad) |
| M3 | En modo memoria cuenta los enlaces de la app | 1 |
| M4 | El lector no vuelve saludo un saludo puro | 1 (fila 2) |
| M5 | Sin el recorte por el tope | 2 |
| M6 | Sin el recorte por el registro | 1 |
| M7 | El lector no lee los hechos del gimnasio | 4 |
| M8 | El origen mira la política antes que `fallback_mode` | 1 |
| M9 | El informe mete en el reparto lo que no es `reply`/`executed` | 3 |
| M10 | El percentil con `floor` | 1 |
| M11 | Una tilde en una nota de política | 3 |
| M12 | La puerta no marca la política (el commit sí) | 11 |

### 6.8 Variables, migraciones y configuración

Ninguna: ni migraciones, ni `config/`, ni rutas, ni variables de entorno. El comando nuevo no necesita registro.

### 6.9 Añadido del lead tras la entrega (21-11)

1. **Cuatro notas corregidas** (§5.3 tenía razón: tres no cuadraban con lo que exige su regla, y una cuarta dejaba pasar una paráfrasis que la regla no admite). Quedan así, y son las que aprobará el propietario:
   - `policy_active_goal_lost`: «VISITA A MEDIAS: hay una visita de cortesia a medio concretar y la persona esta dando sus datos. Retoma la visita pidiendo solo el dato que falta (el dia o la hora). No nombres planes ni precios en este turno.»
   - `policy_wrong_first_plan`: «PRIMER PLAN EQUIVOCADO: el primer plan que nombres tiene que ser el de commercial_turn_policy.required_plan_id, y no nombres ninguno de forbidden_plan_ids (la persona los rechazo). Los demas permitidos, solo despues y si aportan.»
   - `policy_answer_without_fact`: «FALTA EL DATO: la persona pidio informacion del gimnasio y el borrador no da ningun dato de knowledge_base. Responde primero con el dato pedido, copiado tal cual de knowledge_base (direccion, horario o servicios), y deja como mucho una pregunta al final.»
   - `policy_general_info_only_discovery`: «SOLO PREGUNTAS: la persona pidio informacion y el borrador solo pregunta. Responde primero con lo que pidio, con un dato concreto, y deja como mucho una pregunta al final.»
   - Cambiadas en `UltronRegenerationNotes` y en las dos pruebas que las fijan literales. Las otras dos (`policy_greeting_sells`, `policy_memory_turn_sells`) no cambian.
2. **El objetivo del 90 % se mide con lo que de verdad escribió el modelo** (decisión pendiente de §6.4, resuelta). La hora de la visita la escribe el servidor desde la fila del CRM: contarla como «escrita por la IA» inflaba la cifra. `classify()` no cambia (la sigue marcando aparte, `server_text = visit_hour`); cambia el informe: el titular «Escrito por la IA» y `meets_target` usan `written_count` y `written_pct` (sin la hora de la visita), y la regla de la línea base queda en una línea aparte para poder comparar. Prueba nueva: `test_la_hora_de_la_visita_no_cuenta_para_el_objetivo` (ocho del modelo y una hora de la visita: 100 % con la regla antigua, 88,9 % de verdad, objetivo NO cumplido). Roja antes, verde después.
3. **La línea base, corregida.** Leído en producción el 21-11 (solo conteos): de las 71 respuestas que la consulta contó como del modelo, 4 eran la hora de la visita. Lo que escribió el modelo fueron **67 de 88 (76,1 %)**, no 71 (80,7 %). El fixture de la línea base de `UltronOriginReportCommandTest` ya lo refleja.
4. Tras estos cambios: dirigidas del motor, de la puerta y del informe, **1 862 en verde** (1 omitida, la que solo corre en PostgreSQL); `pint`, passed.


---

## 7. Ola 5

21-11-2026 · rama `feat/ultron-studio-h9`, HEAD `ab64134a` (olas 1 a 4b y 6 confirmadas) · **sin commit**: todo está en el árbol de trabajo, nada desplegado, sin producción y **sin n8n** (ni MCP, ni API, ni su base, ni importar, ni publicar).
Guía: el encargo del lead (`encargos-regenerar/encargo-be-r5.md`) y `plan-arquitecto-a4-regenerar.md` §1 (H2 a H6, H8, H13), §3 P-2 y P-6, §4, §5 entero, §6 «Ola 5», §8 y §13 (L-6). El contrato es el implementado (§4 y §5 de este informe): `defects` hasta 8, diez códigos (los cuatro de §4.4 y los seis de la política) y `regeneration.rewrites.*.codes` hasta 8. **Donde el plan y lo implementado difieren, manda lo implementado** (y no hubo que tocar el servidor: el contrato cuadra).

### 7.0 Resumen

- **S2 generado desde el repositorio:** `UltronRegenN8nFlow::build(S1)` (pura) da **39 nodos** (los 25 de S1 + los 14 de §5.2), **12 con credencial**, **33 orígenes y 45 enlaces** (S1: 25 nodos, 8 con credencial, 19 orígenes y 26 enlaces). Desenrollado: **ningún bucle** fuera del ciclo de la rendición de S1. El límite de tres redacciones está en la forma del grafo: en los **17 caminos** de Composer a Preparar Commit hay como mucho 3 redactores, 3 revisores y 2 comprobaciones **[C]**.
- **S1, byte a byte igual** **[C]**: los nueve ficheros de `n8n/flujos/ultron-studio-s1/`, con la orden sin `--rev` y con `--rev=S1`, tienen los sha256 de HEAD (escritos a mano en la prueba). `git status` limpio en esa carpeta.
- **Los 25 de S1 en S2, idénticos** salvo el `jsCode` de Preparar Commit (dos anclas) y `FLOW_REV = "S2"` en el selector: posiciones, credenciales, `values[1]`, opciones y reintentos incluidos **[C]**.
- **Sin la oferta** (`decide` sin `regeneration` de `ultron-regen/1`: Legacy, un servidor anterior o un contrato desconocido), el recorrido ejecutado nodo a nodo da **las mismas llamadas al modelo, los mismos datos enviados (`values[0]` y `values[1]` evaluados), ninguna llamada a `ai/check` y la misma salida de Preparar Commit que S1**, sin bloque; solo cambia `prompts.flow_rev` (S1 → S2) **[C, simulado]**.
- **El comprobador** `verificar-flujo.cjs --rev=S2` (apartados A a J): **OK, 226 comprobaciones** sobre lo generado (127); el modo S1, sin cambios: **OK, 121** (126). Falla en modo S2 sobre S1, y con las **10 mutaciones** de la prueba y las **7 a mano** (128).
- **Números:** rojo antes (122): 25 pruebas, 20 errores y 3 fallos; dirigidas en verde (123): **905 pruebas**; Marketing entero (124): **6 802 en verde** (1 omitida, solo PostgreSQL); guardas (125): **141, con la roja esperada** (la puerta de diff del H9); `pint`: `passed` (129).
- **El procedimiento de importación** (`ops/motores/n8n-copia-s2/LEEME.md` e `importar-s2.sh`): **ESCRITO Y SIN EJECUTAR**. Solo se ha comprobado su sintaxis (`bash -n`, que no ejecuta) y, en local con Node, el control del fichero que el guion hace dentro del contenedor.

### 7.1 Ficheros tocados

| Fichero | Cambio | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/Prompts/UltronRegenN8nFlow.php` | **nuevo** | El generador S2, puro: `build(S1)` → `{flow, hashes, code, fixtures}`; `commitCode()` (las dos anclas), `checkBody(k)`, `readCode(k)`, `noteCode()`, `thirdCode()`, `fixtures()`; ids y posiciones fijos; el vocabulario del servidor (`UltronRegenerationNotes::CODES`, `CriticContract::DIMENSIONS`, los topes) embebido desde las constantes PHP. Se niega (`RuntimeException`) si S1 no es el que se genera hoy |
| `app/Services/Marketing/Ultron/Prompts/UltronPromptsN8nFlow.php` | +12/−2 | `selectorCode(string $flowRev = self::FLOW_REV)`: el valor por defecto deja S1 igual; una revisión que no tiene la forma que admite el commit (`[A-Za-z0-9_.:-]`, ≤ 32) lanza |
| `app/Console/Commands/Marketing/UltronPromptsN8n.php` | +191/−13 | `--rev=S1\|S2` (por defecto S1) y `--out` según la revisión; `generaS1()` es el código de siempre, sin una línea cambiada salvo la escritura (extraída a `escribe()`); `generaS2()` y `leemeS2()`. Una revisión desconocida no escribe nada |
| `n8n/flujos/ultron-studio-s2/` (17 ficheros) | **nuevos, generados** | `flujo-s2.json`, `preparar-commit.js`, `leer-comprobacion-1.js`, `leer-comprobacion-2.js`, `nota-del-servidor.js`, `decidir-intento-3.js`, `huellas.json`, `LEEME.md` y `fixtures/`: `decide-studio.json`, `decide-legacy.json`, `check-pasa.json`, `check-reescribe.json`, `check-legacy.json`, `check-404.json`, `check-422.json`, `check-error-de-red.json` y `check-mal-formada.json` |
| `docs/ultron-studio/ops/motores/verificar-flujo-s2.cjs` | **nuevo** | El comprobador S2 (§7.4), con un simulador de recorridos |
| `docs/ultron-studio/ops/motores/verificar-flujo.cjs` | +16 | `--rev=S2` delega en el hermano; `--rev=S1` o sin `--rev`, lo de siempre; otra revisión, código 2 |
| `docs/ultron-studio/ops/motores/n8n-copia-s2/LEEME.md` e `importar-s2.sh` | **nuevos** | El procedimiento: preparar el fichero, importar la copia INACTIVA, comprobador, ensayo con datos fijados, cambio de S1 a S2 y vuelta atrás. **Sin ejecutar** |
| `tests/Feature/Marketing/UltronPromptsN8nS2CommandTest.php` | **nuevo** | 14 métodos, 23 pruebas con el proveedor de las 10 mutaciones (§7.5) |
| `tests/Unit/Marketing/Ultron/UltronCatalogStaticGuardsTest.php` | +10/−4 | Mira también `n8n/flujos/ultron-studio-s2/*.js` y exige los cinco `.js` de S2 en la lista. Sin excepciones nuevas: ningún `.js` de S2 nombra un plan, un precio o un beneficio |
| `docs/…/a4-evidencia/122a`, `122` a `129`, `123a` y `124a` | nuevos | Salidas (§7.7) |

**Sin tocar:** el servidor de las olas anteriores (`UltronDraftCheck`, el controlador, el commit, `decide`, las rutas), `config/`, las migraciones, los prompts base, el compilador, el CRM, los ficheros de `n8n/flujos/ultron-studio-s1/` (regenerados idénticos) y `ultron:canary-report`.

### 7.2 El flujo

```
Composer → ¿Comprobar 1? ─no (sin oferta)→ Critic Comercial
             └sí→ Comprobar Borrador 1 → Leer Comprobación 1 → ¿Reescribir 1? ─no→ Critic Comercial
                                                                 └sí→ Nota del Servidor 1 → Composer Reintento
Critic Comercial → Critic aprueba? ─sí→ Preparar Commit · ─no→ Composer Reintento        (S1)
Composer Reintento → ¿Comprobar 2? ─no (sin oferta)→ Critic Comercial 2
             └sí→ Comprobar Borrador 2 → Leer Comprobación 2 → ¿Reescribir 2? ─no→ Critic Comercial 2
                                                                 └sí→ Nota del Servidor 2 → Composer Reintento 2
Critic Comercial 2 → Decidir Intento 3 → ¿Intento 3? ─no→ Preparar Commit
                                           └sí→ Composer Reintento 2 → Critic Comercial 3 → Preparar Commit
```

| Nodo | Tipo | Lo que hace (tal como quedó) |
|---|---|---|
| ¿Comprobar 1? / 2? | IF 2.3 (la forma de `Rendirse?`) | La expresión de §5.2, literal: solo nombra «Pedir Contexto al CRM» |
| Comprobar Borrador 1 / 2 | HTTP 4.4 | Los parámetros y la credencial de `decide` (**por referencia**: id y nombre de «IRON BODY Internal API»), URL `…/ai/check`, `timeout: 3000`, **sin** `retryOnFail` ni `maxTries`, `fullResponse` + `neverError`, `onError: continueRegularOutput`. Cuerpo: `conversation_id` y `message_id` de «Normalizar Evento», `decide_token` de `decide`, `attempt` 1 o 2, y `draft` = el borrador del redactor k más `intent`, `recommended_plan_id` (`undefined` → `null`, como Preparar Commit) y `tools_requested` de la estrategia |
| Leer Comprobación 1 / 2 | Code | El objeto del redactor k + `ultron_check = {rewrite, may_rewrite, error, codes, issues, notes}`. **Solo un 200 con la forma entera del contrato y el `attempt` k** cuenta; reescribir exige además `mode: studio`, `may_rewrite`, `defects ≠ []` y una nota. Lo demás (404, 422, 5xx, red, otra forma, un `rewrite: true` incoherente) es `error: true` y no reescribir. Códigos e issues, filtrados al vocabulario del servidor y sin repetir; la nota, a 500 |
| ¿Reescribir 1? / 2? | IF 2.3 | `$json.ultron_check ? $json.ultron_check.rewrite === true : false` |
| Nota del Servidor 1 / 2 | Code (el mismo) | La forma del revisor: `{output:[{content:[{text:{verdict:"fail", score:null, issues, notes, hard_fail:null, by:"server", codes}}]}]}` |
| Decidir Intento 3 | Code | La entrada de Critic 2 tal cual + `ultron_intento3 = (verdict === "fail" \|\| hard_fail) && Leer 2 corrió con may_rewrite y sin error`; nunca lanza |
| ¿Intento 3? | IF 2.3 | `$json.ultron_intento3 === true` |
| Composer Reintento 2 | OpenAI 2.3 | Copia exacta de Composer Reintento; `rejected_draft` de «Composer Reintento». Sistema: `roles.composer_retry` (ningún rol nuevo) |
| Critic Comercial 3 | OpenAI 2.3 | Copia exacta de Critic Comercial 2 |

**Preparar Commit**, dos anclas (el resto es el de S1, comprobado revirtiéndolas):
- (i) el tercer borrador, en su propio `try`, detrás del de S1: si «Composer Reintento 2» no corrió o no trae texto, se queda el que había (nunca vuelve al primero);
- (ii) el bloque `regeneration`, solo con `ctx.regeneration.contract === "ultron-regen/1"`, dentro de un `try`: `{contract, drafts, critics, checks, check_errors, rewrites:[{by, codes, issues}], tokens}`. Solo se manda si cuadra (rangos y `rewrites = drafts − 1`): fuera de rango el commit daría un 422 y la traza llevaría el turno a la rendición.

### 7.3 Los recorridos y sus llamadas

Ejecutados con el simulador del comprobador (§7.4 I) con los IF y los Code tal como están en el flujo; el Strategist corre antes en todos. Comprobaciones = llamadas a `ai/check`.

| Caso | Nodos de modelo (además del Strategist) | Llamadas al modelo | Comprobaciones | Preparar Commit |
|---|---|---|---|---|
| Legacy, pasa | Composer, Critic | 3 | 0 | `attempt` 1, sin bloque, = S1 |
| Legacy, el revisor falla | Composer, Critic, Composer Reintento, Critic 2 | 5 | 0 | `attempt` 2, sin bloque, = S1 (también si Critic 2 vuelve a fallar: no hay tercero) |
| Studio, pasa | Composer, Critic | 3 | 1 | `drafts` 1, `critics` 1, `checks` 1 |
| Studio, el servidor pide 1 | Composer, Composer Reintento, Critic 2 | 4 | 2 | `drafts` 2, `critics` 1, `rewrites` [server: `register_tuteo`, `length_over_cap`] |
| Studio, el revisor pide 1 | Composer, Critic, Composer Reintento, Critic 2 | 5 | 2 | `drafts` 2, `critics` 2, `rewrites` [critic, con sus issues] |
| Studio, dos reescrituras | servidor + servidor: Composer, CR, CR 2, Critic 3 (5) · revisor + servidor: + Critic (6) · servidor + revisor: Composer, CR, Critic 2, CR 2, Critic 3 (6) · **revisor + revisor: Composer, Critic, CR, Critic 2, CR 2, Critic 3 (7, el peor caso)** | 5 a 7 | 2 | `drafts` 3, `attempt` 3 y el texto del tercer borrador; si Critic 3 también falla, no hay cuarto |
| Studio, el servidor caído (404, 422, red, mal formada) en la comprobación 1 | Composer, Critic | 3 | 1 | `check_errors` 1, el camino de hoy |
| Studio, caído en las dos, el revisor falla dos veces | Composer, Critic, CR, Critic 2 | 5 | 2 | `check_errors` 2, **sin tercer borrador** |
| Studio, la comprobación contesta `legacy` · sin presupuesto en la 2 · el servidor pide 1 y la 2 cae | como «el revisor falla» o «el servidor pide 1» | 4 o 5 | 2 | **sin tercer borrador** |

En los casos con reescritura, el simulador comprueba además **lo que recibe el redactor**: `rejected_draft` = el borrador anterior, y `critic_issues`/`critic_notes` = los del servidor (su nota literal) o los del revisor que la pidió. Cada recorrido se ejecuta dos veces: con lo que no corrió **lanzando** al leerlo y **devolviendo `undefined`** (§7.6).

### 7.4 El comprobador (`verificar-flujo.cjs --rev=S2`, en `verificar-flujo-s2.cjs`)

Sin red y sin tocar n8n; 0 si todo cuadra, 1 ante una diferencia, 2 si no lee las entradas. Recibe el export (`flujo-s2.json` o lo que exporte n8n), `--s1` (por defecto el del repositorio) y `--generado` (los `.js` y los fixtures). Lo ejecuta la prueba PHP, como el de S1.

| Apartado | Qué comprueba | Comprobaciones (127) |
|---|---|---|
| A | La base (sus cinco sha256 y la huella Legacy, como hoy); el S1 de referencia, con sus 25 nodos y las huellas de S1; **el vocabulario del servidor leído del PHP** (`CODES`, `DIMENSIONS` y los topes, resolviendo `self::`, `CommercialTurnPolicy::` y `...self::`), no copiado | 9 |
| B | Los 25 de S1 + los 14, ni uno más; sin repetidos; 39 nodos y 12 con credencial; ninguno desactivado | 4 |
| C | Cada nodo de S1, igual a S1 salvo el `jsCode` de Preparar Commit y `FLOW_REV` (una sola constante, `"S2"`); la posición no cuenta, como en el comprobador de S1 (la prueba PHP sí exige la misma posición en lo generado) | 26 |
| D | Los IF: la forma exacta del de S1 con su expresión. La comprobación: POST a `…/ai/check`, la credencial de `decide` por referencia, sin cabeceras ni parámetros propios, **sin reintentos**, **3 s**, `fullResponse` + `neverError`, `continueRegularOutput` y el cuerpo exacto. Los Code: v2, sin credenciales, = los `.js` generados. Las dos copias de OpenAI = sus originales salvo nombre, id, posición y `rejected_draft`. Preparar Commit = el de S1 con sus dos anclas (revirtiéndolas sale el de S1) y = el generado. El vocabulario de los Code = el del PHP. Ningún secreto ni `$env` en los nodos nuevos | 59 |
| E | Las conexiones: las de S1 con sus tres cambios (Composer, Composer Reintento y Critic Comercial 2) y las de los 14 | 1 |
| F | El selector en `vm`: sin oferta, la base y la huella Legacy con `flowRev S2`; con `decide-studio.json`, `studio` con la huella del fixture; con `decide-legacy.json`, `offered_prompts_off`; lo mismo que el de S1 salvo `flow_rev` | 5 |
| G | Preparar Commit en `vm` **junto al de S1**: sin oferta (tres contextos × sin reintento, con reintento aprobado y con reintento que vuelve a fallar × lanzando o `undefined`), la MISMA salida y sin bloque (18 casos); con oferta, la de S1 + un bloque válido; **una lectura que lanza dentro del bloque → sin bloque y la misma salida**; el tercer borrador y el tercero sin texto (se queda el segundo); los tokens (sin el `usage` de un nodo que corrió, fuera de rango o negativos → `null`) | 23 |
| H | Los Code en `vm` con los siete fixtures y doce variantes, para los dos «Leer Comprobación»: solo un 200 bien formado reescribe; el intento ajeno, el `200` en texto, un HTML, un 500, la incoherencia, otro contrato o `legacy` con `rewrite: true` no; el vocabulario filtrado y la nota a 500; sin ítem de entrada no lanza. Las dos «Nota del Servidor» (la forma del revisor; vacía con una entrada rara). «Decidir Intento 3» en ocho casos y dos modos. Preparar Commit con la salida de «Decidir Intento 3» = con la del revisor (S1) | 62 |
| I | Sin bucles fuera del ciclo de la rendición (que es el de S1); en los 17 caminos, ≤ 3 redactores y ≤ 3 revisores, ≤ 2 comprobaciones, el último borrador lo juzga un revisor, el tercero no se comprueba, la salida «sí» de ¿Comprobar k? pasa por Comprobar Borrador k y la «no» va directa al revisor de S1; los 14 alcanzables. Y **22 recorridos** ejecutados nodo a nodo (§7.3), con el bloque esperado, el cuerpo de cada comprobación, lo que recibe cada redactor y, sin oferta, la comparación entera con S1 | 35 |
| J | El cuerpo de cada comprobación, evaluado: exactamente `conversation_id`, `message_id`, `decide_token`, `attempt`, `draft{reply_draft, intent, recommended_plan_id, tools_requested}`, con los valores de los nodos que tocan (y el plan `undefined` → `null`) | 2 |

**Las mutaciones** (cada una, sobre una copia de `flujo-s2.json`; el comprobador sale con 1):

| Mutación | En la prueba | La mata |
|---|---|---|
| Un redactor de más (Composer Reintento 3 antes del 2) | sí | I (4 redactores), B |
| Un IF que salta la comprobación (¿Comprobar 1? sí → Critic) | sí | I (la salida «sí» no pasa por la comprobación), E, recorridos |
| El `values[1]` de Critic Comercial tocado | sí | C, y los recorridos sin oferta (datos distintos de S1) |
| Una clave de más en el cuerpo de la comprobación (`phone`) | sí | J, D |
| Preparar Commit sin el `try` del bloque | sí | G (la lectura que lanza), D |
| Un bucle (Critic 3 → Composer Reintento 2) | sí | I (bucle), recorridos (un nodo dos veces) |
| La comprobación con reintentos | sí | D |
| Un 404 que pide reescribir (el «fallback silencioso») | sí | H |
| El tercer borrador sin revisor | sí | I |
| Legacy que pregunta al servidor (¿Comprobar 1? = `true`) | sí | recorrido «Legacy, pasa» (llamada a `ai/check` no prevista), D |
| Quién pidió: `server` ↔ `critic` | a mano (128) | recorridos (6 ✗) |
| Decidir Intento 3 siempre sí | a mano | H, recorridos (23 ✗) |
| La nota del servidor sin texto | a mano | H, recorridos |
| ¿Reescribir 1? con las salidas cambiadas | a mano | E, recorridos (18 ✗) |
| Sin tiempo máximo | a mano | D |
| Composer Reintento 2 con el `rejected_draft` del primero | a mano | D, recorridos |
| El tercer borrador vuelve al primero si falla | a mano | D, G (sin oferta ya no es S1: 19 ✗) |

### 7.5 Las pruebas (`UltronPromptsN8nS2CommandTest`)

| Prueba | Qué fija |
|---|---|
| `genera_los_ficheros_de_s2_de_forma_determinista` | Los 17 ficheros, dos veces, el mismo sha256 |
| `lo_que_hay_en_el_repositorio_es_lo_que_genera_hoy` | `n8n/flujos/ultron-studio-s2/` = lo generado, ni uno de más ni de menos |
| `s1_sale_byte_a_byte_igual_que_antes` | Sin `--rev`, con `--rev=S1` y en el repositorio: los nueve sha256 de HEAD, a mano; `selectorCode()` = `selectorCode('S1')`; el de S2 solo cambia la constante |
| `una_revision_del_selector_que_no_es_un_codigo_corto_no_se_genera` | Vacía, con espacio, con comillas, de 33 |
| `s2_son_los_25_de_s1_mas_los_14_con_su_forma` | En PHP: el orden de los nodos, sus tipos y versiones, 12 credenciales, los 25 idénticos (posición incluida), la comprobación (URL, credencial de `decide`, 3 s, sin reintentos, `continueRegularOutput`, cuerpo), las dos copias de OpenAI y sus roles, los `{}` y `id`/`active`/`versionId` = `null`/`false`/`null` |
| `los_fixtures_de_la_comprobacion_son_los_del_endpoint_real` | `check-pasa` y `check-reescribe` = **byte a byte** las respuestas reales del endpoint de la ola 4 (evidencia 111); las claves de los demás; `decide` con y sin `regeneration` |
| `sin_secretos_en_lo_generado` | Ni `Bearer`, ni `Authorization`, ni `sk-`, ni `password`, ni `$env`, ni `require(`; credenciales solo como `{id, name}`; sin cabeceras a mano; el token de los fixtures es sintético |
| `el_comprobador_pasa_sobre_lo_generado_en_los_dos_modos` | S1 → OK; S2 → OK sin ✗, con los apartados A a J y los recorridos del plan; también sobre el repositorio |
| `el_comprobador_en_modo_s2_falla_sobre_s1` | `✗ son los 25 de S1 + los 14 nuevos` |
| `una_revision_desconocida_del_comprobador_no_comprueba_nada` | `--rev=S3` → código 2 |
| `el_comprobador_mata_cada_mutacion` (×10) | Las diez de la tabla de §7.4, cada una con su ✗ |
| `un_s1_que_no_es_el_generado_no_genera_s2` | Ocho S1 que no valen (sin selector, selector ya S2, sin el bloque `prompts`, sin el ancla del borrador, con un nodo de S2, el Composer que no va al revisor, `decide` sin credencial o con otra URL) |
| `un_respaldo_que_no_es_29de5119_no_genera_s2` y `una_revision_desconocida_no_genera_nada` | La orden falla y no escribe nada |

### 7.6 Decisiones mías (el plan o el encargo no las fijan)

1. **Dos ficheros para «Leer Comprobación»** (`leer-comprobacion-1.js` y `-2.js`) en lugar del `leer-comprobacion.js` del encargo: el código lleva su `K` y su redactor como constantes. La alternativa (un solo código que deduce K de qué nodos corrieron, como hace Preparar Commit con «Composer Reintento») depende justo de lo que el ensayo con datos fijados puede falsear (§7.8). «Nota del Servidor» sí es un solo código.
2. **`drafts` = redactores que corrieron**, no el intento elegido. Coinciden siempre salvo si un redactor no trae texto: entonces `consistent` sale `false` en el servidor, que es la señal correcta.
3. **`tokens`: solo si TODOS los nodos de OpenAI que corrieron (con el Strategist) entregan `usage.input_tokens/output_tokens` enteros**; si falta uno, `null` (una suma a medias diría menos de lo que costó); por encima de 2 000 000, `null`.
4. **El vocabulario del servidor se embebe al generar** (`CODES`, `DIMENSIONS`, topes): un código o un issue que no son de él no viajan, porque el commit rechazaría la traza con un 422 y mandaría el turno a la rendición. Si el servidor amplía su vocabulario, hay que regenerar (lo dice `lo_que_hay_en_el_repositorio…`); mientras tanto, lo nuevo se filtra, no rompe.
5. **El bloque solo sale si cuadra** (rangos y `rewrites = drafts − 1`); si no, sin bloque. Es traza.
6. **«Leer Comprobación» trata un `rewrite: true` incoherente** (sin presupuesto, sin defectos, sin nota, en `legacy`) **como error**, no como «pasa»; y exige `attempt === K`.
7. **«Decidir Intento 3» pide `verdict === "fail"`** (o un fallo duro), no `!== "pass"`: un revisor sin veredicto no abre un tercer borrador; sigue el camino de hoy (Preparar Commit lo trata como en S1).
8. **¿Reescribir k? defensivo** (`$json.ultron_check ? … : false`), en vez de `$json.ultron_check.rewrite` a secas.
9. **Los IF nuevos copian la forma de `Rendirse?`** y la comprobación copia los parámetros y la credencial de `decide` (las dos URL, constantes; S2 no se genera si `decide` no es la de siempre).
10. **`flujo-s2.json` sin `id` ni `versionId` y con `active: false`**: importarlo tal cual no pisa ningún flujo. (`flujo-s1.json` lleva el id del flujo de siempre: el lead lo cambió a mano al importar S1; aquí ya no hace falta.)
11. **Los fixtures de `decide` de S2** son los de S1 más `source` y un `context` mínimo (lo que leen Preparar Commit y los `values[1]` en el ensayo) y, en el de Studio, `regeneration`. Los de `ai/check` son exactamente la lista del encargo; «sin presupuesto» y el resto de variantes viven en el comprobador.
12. **El comprobador S2 es un hermano** (`verificar-flujo-s2.cjs`) y `verificar-flujo.cjs --rev=S2` lo llama: el modo S1 no cambia ni una comprobación. Usa `process.exitCode` y no `process.exit()`: en macOS la escritura a una tubería es asíncrona y `process.exit()` puede cortar la salida (el de S1 no lo necesita: su salida es corta).
13. **Con el servidor caído se pregunta igual en la comprobación 2** (otros 3 s como mucho): es el diseño del plan (¿Comprobar 2? solo mira la oferta). Saltarla exigiría que un IF leyera un nodo que en Legacy no corre. Peor caso: +6 s.
14. **El presupuesto de tiempo es del servidor**: si la comprobación 2 concede a los 24 s y el revisor 2 tarda, el tercer borrador sale pasados los 25 s. El tope estructural (3) se mantiene; es un caso de la ola 7 (§6 del plan).

### 7.7 Números

| # | Qué | Resultado |
|---|---|---|
| 122a | Primera versión de las pruebas, con `app/` = HEAD | 25 pruebas, 20 errores, 2 fallos, **y una que pasaba sin poder fallar**: `fail()` dentro de un `try` que captura `RuntimeException` (la excepción de PHPUnit lo es). Corregido antes de escribir nada (helper `lanza()`, sin `fail()` dentro) |
| 122 | Las pruebas nuevas (versión final) y la guarda ampliada, con `app/` = HEAD | **ROJO:** 25 pruebas, 20 errores, 3 fallos (pasan las dos de la guarda que no dependen de S2) |
| 123a | Las nuevas, la guarda, `UltronPromptsN8nCommandTest` y `tests/Unit/Marketing/Ultron/Prompts`, antes de añadir `los_fixtures_de_la_comprobacion_son_los_del_endpoint_real` | OK (904 pruebas, 21 553 aserciones) |
| 123 | Las mismas, con el árbol final (esa prueba incluida; antes, comprobado que muere si el fixture se aparta un segundo del real) | **OK (905 pruebas, 21 565 aserciones)**, sin avisos |
| 124a | `tests/Feature/Marketing tests/Unit/Marketing`, lanzada antes de añadir esa prueba | OK (6 801 pruebas, 59 645 aserciones), 1 omitida (solo PostgreSQL) |
| 124 | La misma, con el árbol final | **OK (6 802 pruebas, 59 657 aserciones)**, 1 omitida (solo PostgreSQL): las 6 779 de antes + las 23 nuevas |
| 125 | Guardas: los 16 ficheros (`*Isolation*`, `*ReadOnly*`, `*StaticGuards*`, `SecretExposure*`) | 141 pruebas, 182 818 aserciones, **1 fallo esperado**: `OutreachIsolationTest::test_la_puerta_de_diff…` |
| 126 | `verificar-flujo.cjs` (S1) sobre el S1 del repositorio | **OK (121 comprobaciones)**, las mismas que antes |
| 127 | `verificar-flujo.cjs --rev=S2` sobre el S2 del repositorio | **OK (226 comprobaciones)** |
| 128 | Siete mutaciones a mano | 7 de 7 mueren |
| 129 | `vendor/bin/pint --test` sobre los 5 PHP tocados o nuevos | `passed` (antes, `pint` solo cambió dos `use`) |

**La puerta de diff del H9** nombra ahora **24** ficheros: los **23** que ya nombraba con HEAD frente a `origin/main` y uno de esta ola, `app/Services/Marketing/Ultron/Prompts/UltronPromptsN8nFlow.php` (`selectorCode()`). Su segunda aserción (ficheros nuevos sin seguimiento en las zonas protegidas), que no llega a ejecutarse porque falla la primera, nombrará además `UltronRegenN8nFlow.php`. La orden (`app/Console/Commands/Marketing/`), el comprobador y `n8n/` no son zonas de la puerta. Todo va a la Enmienda A4-3.

### 7.8 Lo que NO se pudo comprobar sin un n8n real, y cómo se comprobará

- **[S] Si el nodo de OpenAI 2.3 entrega `usage`** y dónde (`json.usage.input_tokens/output_tokens`, que es lo que lee Preparar Commit). Con datos fijados no se sabe (los fija uno). **Se verá en el primer turno canario real con S2 y la versión 3**: `metadata.regeneration.tokens` con números o `null` (`ultron:origin-report`, línea «Tokens»). Si sale `null` siempre, el informe de costo queda sin dato, pero nada se rompe.
- **[S] Leer un nodo que no corrió.** En S1 ya se usa que `$('Nodo').first()` lanza (lo dice el comentario de «Clasificar Fallo del Commit»). Todo lo nuevo que mira un nodo que puede no haber corrido es Code con `try` y funciona igual si devuelve `undefined`: **el simulador ejecuta cada recorrido de las dos maneras**. Los IF solo miran «Pedir Contexto al CRM» (siempre corrió) o el `$json` que acaba de calcular un Code. **En el ensayo:** el recorrido «Legacy, el revisor falla» hace correr «Decidir Intento 3» sin «Leer Comprobación 2»: tiene que dar `false`.
- **[S] La trampa de los datos fijados:** en una ejecución manual, n8n puede devolver los datos fijados de un nodo que no corrió (`getPinDataIfManualExecution`). Afectaría al ensayo (no a producción) y también a S1. **Regla del ensayo** (`n8n-copia-s2/LEEME.md`, paso 4): fijar solo los nodos de OpenAI que el recorrido ejecuta, y una ejecución que lo comprueba.
- **[S] La forma de lo que deja el nodo HTTP** con `fullResponse` (`{body, headers, statusCode, statusMessage}`, `statusCode` como número) y ante un tiempo agotado con `continueRegularOutput` (`{error:{…}}`, `fixtures/check-error-de-red.json`). Cualquier otra forma acaba en «no reescribir»: el riesgo es perder una reescritura, no romper un turno. El ensayo no lo puede ver (las comprobaciones se fijan siempre: cero llamadas a Laravel). **Se verá en el primer turno canario:** con el servidor sano, `check_errors = 0` y, si el servidor pidió reescribir, `rewrites` con `by: server`; un `check_errors` distinto de 0 con el servidor sano diría que la forma real no es la supuesta.
- **[S] Un error al evaluar el cuerpo** (si `text` no viniera parseado) se trataría como error del nodo y `continueRegularOutput` lo pasaría a «no reescribir»; no lo he visto en n8n. El mismo acceso sin protección está ya en el `values[1]` de Composer Reintento de S1.
- **Los 3 s frente a la latencia real** de `ai/check` (6 a 12 `select`, §5.8): `ultron.check.result` en el log y la latencia de `ultron:origin-report`.
- **Que el importador conserve los 39 nodos** (con nombres con «¿» y «ó») y sus referencias: lo dicen los pasos 2 y 3 del procedimiento (el guion y el comprobador sobre lo exportado de la base de n8n).
- **El ensayo con datos fijados**, recorrido a recorrido, con lo que tiene que dar Preparar Commit en cada uno: la tabla del paso 4 de `n8n-copia-s2/LEEME.md`. Lo hace el lead o `devops` con el MCP, en su momento.

### 7.9 Para los siguientes

- **`security` / `api-security-specialist`:** la credencial de la comprobación es la de `decide`, por referencia; el cuerpo lleva el borrador y los ids, nada de la persona más allá del borrador; la nota del servidor viaja al modelo tal cual (textos fijos, ≤ 500); `flujo-s2.json` no lleva secretos ni `$env` (prueba y apartado D).
- **`tester` (casos límite que conozco desde dentro):** el presupuesto agotado entre la comprobación 2 y el revisor 2 (decisión 14); el servidor caído en las dos comprobaciones (+6 s, simulado); un redactor sin texto (`consistent: false`); un nodo de OpenAI sin `usage` (tokens `null`); la trampa de los datos fijados en el ensayo.
- **`devops` / el lead:** `importar-s2.sh` está sin ejecutar; necesita `docker`, el contenedor `n8n-n8n-1` con `node` y `n8n-postgres-1`, como el de S1. Aborta antes de importar si el fichero no es el S2 preparado (id, inactivo, 39 nodos, 12 credenciales, selector y Preparar Commit con los sha256 de `huellas.json`, comprobación a 3 s sin reintentos).

### 7.10 Variables, migraciones y configuración

Ninguna: ni migraciones, ni `config/`, ni rutas, ni variables de entorno. La orden gana `--rev` (por defecto S1, como hoy).
