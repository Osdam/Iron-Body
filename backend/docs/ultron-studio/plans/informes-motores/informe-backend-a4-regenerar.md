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
