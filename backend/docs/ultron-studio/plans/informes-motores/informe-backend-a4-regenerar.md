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
