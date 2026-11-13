# Informe del backend · Apartado 4 · Compilador de prompts 3 (margen del tope para el redactor)

12-11-2026 · rama `feat/ultron-studio-h9`, HEAD local `daedbbd8` (sin subir; `origin/main` = `7aa46911`) · **sin commit**: todo está en el árbol de trabajo.
Guía: `plan-arquitecto-a4-bloqueantes.md` §3.2 (b2) y §5.9, con el encargo del lead y sus dos decisiones sobre los bloqueos (opción (a) para el texto en §B.3; regenerar `n8n/flujos/ultron-studio-s1/` con el comando).
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto o no comprobado.

---

## 1. Resumen

- `UltronPromptCompiler::VERSION` pasa de 2 a **3**. Solo cambia `T_MAX_COMPOSER`, el texto del tope para Composer y Composer Reintento. Es el texto definitivo del lead (el de la revisión final, §13: se quita la pregunta final), sin retocar, con `{N}`, `{M}`, `{P}` y `{F}`.
- `M`, `P` y `F` salen de tres funciones públicas y puras del compilador, en enteros y sin coma flotante:
  - `composerTargetChars()` = `intdiv(N * 8, 100) * 10`;
  - `composerTargetWords()` = `intdiv(M, 30) * 5`;
  - `composerMaxSentences()` = `max(2, intdiv(M + 30, 60))`.
  
  Las prueba una tabla N → M, P, F escrita a mano **[C]**.
- No cambian `T_MAX_CRITIC`, `T_MAX_CRITIC_900`, las anclas, las operaciones ni el orden.
- La línea base sigue sin parche: `compile(línea base)` es la base byte a byte. La huella Legacy `3f6a5674…c902` **no se mueve**; hay una aserción explícita, escrita a mano, en `UltronPromptCompilerTest` **[C]**.
- Se mueven las huellas de los **288** paquetes con `maxChars` ≠ 320 y ninguna otra **[C]** (§5). La de «profesional + 200» pasa de `702890f7…311c` a `af9e0d23…4d51`.
- Se regeneraron con sus derivadores los dos fixtures del tester y los artefactos generados de `n8n/flujos/ultron-studio-s1/`. Los tres ficheros que se ejecutan en n8n **no cambian ni un byte** **[C]** (§7).
- `ENGINE_MESSAGES_PROMPTS` (`canary` y `all`) lleva la frase de la longitud medida, igual en los dos, y una prueba la fija ahora (§8).
- `smoke-h9.py` espera el compilador 3.
- Pruebas: rojo guardado antes de tocar el compilador; después, todo verde salvo la roja esperada de la puerta de diff (§10).

---

## 2. Ficheros tocados

| Fichero | Cambio | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php` | +52/−5 | `VERSION = 3`; `T_MAX_COMPOSER` nuevo; `composerTargetChars/Words/MaxSentences`; sustitución de `{M}`, `{P}` y `{F}` en el parche del redactor; docblocks. La cadena de la mutación X3 de `spec-m1.php` (`str_replace('{N}', (string) $valor, $plantilla)`) sigue apareciendo una sola vez **[C]** (`grep -cF` = 1). |
| `app/Services/UltronStudio/StudioPublications.php` | +2/−2 | La frase nueva en los dos mensajes de `ENGINE_MESSAGES_PROMPTS`. |
| `docs/ultron-studio/plans/STUDIO_MOTORES_PLAN.md` | +31 | §B.3, apartado nuevo «Compilador 3 (Enmienda A4-1 del contrato H5, 12-11-2026)»: la nota del HUMAN GATE 4-A (literal), por qué, qué sustituye (el texto del compilador 1–2 se queda en la tabla como histórico), el texto con N, M, P y F, las tres fórmulas, la tabla de los 16 topes y lo que no cambia (`T_MAX_CRITIC` incluido). |
| `docs/ultron-studio/ops/smoke-h9.py` | +3/−3 | Línea 59 (docstring) y 635-636 (etiqueta y `compiler == 3`). Compila con `python3 -m py_compile` **[C]**. |
| `tests/fixtures/ultron-prompts/tools/parches-m1.cjs` | +40/−6 | `COMPILER = 3`; texto nuevo; `margen(n)` calculado por su cuenta (`Math.floor`); `sustituir()`; `EN_EL_PLAN` con las cuatro letras; `comprobarTextosContraElPlan()` **intacta y ampliada**: además exige el apartado «Compilador 3», las tres fórmulas literales y las 16 filas de la tabla. |
| `tests/fixtures/ultron-prompts/compile-cases.json` | +305/−305 | Regenerado (288 casos, 16 textos y `compiler`). |
| `tests/fixtures/ultron-prompts/compile-cases.README.md` | +6/−2 | Apartado «Compilador 3» y cifras de control puestas al día. |
| `tests/fixtures/ultron-studio/publication-cases-v2.json` | +55/−55 | Regenerado: solo huellas de paquete y, por derivación, `effectiveJson`, `effectiveHash` y firmas. |
| `n8n/flujos/ultron-studio-s1/` (6 ficheros) | +12/−12 | Regenerados con `php artisan ultron:prompts-n8n` (§7). |
| Pruebas (11 ficheros) | ver §6 | Las aserciones que fijaban el texto, la versión 2 o la huella `702890f7`. |
| `docs/.../a4-evidencia/12…16-compilador3-*.txt` | nuevos | Salidas de PHPUnit: rojo, prompts y motor, Studio, Marketing y guardas. |

**No tocados** (lista del encargo): `UltronCommitService.php`, `UltronReplyFitter.php`, `UltronPromptOffer.php`, `SalesConversationReplyService.php`, `resources/`, `config/`, rutas, migraciones, el n8n en marcha (ni importación, ni MCP, ni API) y el CRM **[C]** (`git status`).

---

## 3. El texto compilado

Tal como queda en Composer y Composer Reintento, en la línea siguiente a `FORMA:` **[C]**. Lo fija literalmente `UltronPromptCompilerTest::test_el_texto_del_tope_del_redactor_compilado_tal_cual`.

- **160:** `TOPE DE LONGITUD PUBLICADO: el limite es 160 caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos 120 caracteres, que son unas 20 palabras: como mucho 2 frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.`
- **200:** `TOPE DE LONGITUD PUBLICADO: el limite es 200 caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos 160 caracteres, que son unas 25 palabras: como mucho 3 frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.`
- **480:** `TOPE DE LONGITUD PUBLICADO: el limite es 480 caracteres por mensaje y el backend lo mide antes de enviar: al mensaje que se pase se le quita la pregunta final y, si aun asi no cabe, se descarta entero. Escribe unos 380 caracteres, que son unas 60 palabras: como mucho 6 frases cortas, con una sola pregunta. Cuenta cada marcador como el texto que lo sustituye. Tambien la informacion general respeta el tope: da primero lo esencial de lo que se pregunto y, si hace falta, ofrece ampliar.`

Medidas **[C]**:
- 487 bytes en los 16 topes (ASCII, una sola línea, sin `{`; la plantilla tiene 490). Antes eran 220, y el tope por parche es de 1 200.
- Paquete más grande: 114 721 B (antes 114 187), con tope de 163 840. Rol más grande: 29 570 B, sin cambio.

**Tabla N → M, P, F.** Calculada por tres vías que coinciden: PHP, el guion Node y Python al margen **[C]**. Está escrita a mano en la prueba y en §B.3.

| N | 160 | 180 | 200 | 220 | 240 | 260 | 280 | 300 | 340 | 360 | 380 | 400 | 420 | 440 | 460 | 480 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| M | 120 | 140 | 160 | 170 | 190 | 200 | 220 | 240 | 270 | 280 | 300 | 320 | 330 | 350 | 360 | 380 |
| P | 20 | 20 | 25 | 25 | 30 | 30 | 35 | 40 | 45 | 45 | 50 | 50 | 55 | 55 | 60 | 60 |
| F | 2 | 2 | 3 | 3 | 3 | 3 | 4 | 4 | 5 | 5 | 5 | 5 | 6 | 6 | 6 | 6 |

---

## 4. La guarda del derivador (bloqueo 1, opción (a))

`comprobarTextosContraElPlan()` no se ha quitado ni debilitado. Ahora busca el texto nuevo, con `N`, `M`, `P` y `F` en lugar de los marcadores, entre `«…»` en §B.3. Además exige el encabezado `#### Compilador 3`, `` `UltronPromptCompiler::VERSION = 3` ``, las tres fórmulas literales y las 16 filas `| N | M | P | F |` que salen de su propio `margen()`.

Prueba de que sigue teniendo dientes **[C]**: con un espacio de más en el texto en memoria (`unos {M} ` en lugar de `unos {M}`), lanza `El texto maxChars.composer no aparece literalmente en §B.3 del plan: transcripción equivocada`.

---

## 5. Huellas que se mueven

En total se mueven **288 de 306** casos de `compile-cases.json`: los 16 topes × 18 combinaciones de tono, emojis y objetivo.
- Ningún caso con `maxChars = 320` cambia: los 18 son byte a byte los del compilador 2.
- Los únicos textos que cambian son los 16 `maxChars.composer@N`.
- Los 30 inválidos quedan iguales.

Todo esto lo comprobé con un script contra `git show HEAD:…/compile-cases.json` **[C]**. Abajo, las de un solo parámetro, las que usan las pruebas y los fixtures, y las que no se mueven.

| Caso | Gobernados | Antes (compilador 2) | Después (compilador 3) |
|---|---|---|---|
| c000 | cercano · true · **320** · cita (Legacy) | `3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902` (112 216 B) | **igual** |
| c001 | cercano · true · 160 · cita | `a590ab13e7ace027cfe7c4975dc04a8ff0fb99d9c5e7e0c9dfe592b5b66390df` (112 894 B) | `bedc1c5dca22113b065384e0c9867f8d1f5bbe3652227562927cfdee9d20f294` (113 428 B) |
| c002 | … 180 … | `cbf05b4e5df3213f23604ab026f778ab8287565e038b80eaf5e617a1c3b87b87` | `b614b843736b711fe315f9576f3b269eafe4023251e0f068d886b66c402bf4d2` |
| c003 | … 200 … | `f401b597ab0efbcd93a15fe2e9bb82bc356bd468b40f583b8f2ad36c3e981483` | `3168a504ab1bda65ee9918f2f99fd52ed381a523832400f219b730142cc99897` |
| c004 | … 220 … | `07828863f855fed2752b4e213a00b3130b78a5c2801982a586f045ce27cbff82` | `1ed34cc676fa2ad3f1b1ed55dd7e34d5a28fbcdbb4971fe9baac36ab1d65ae59` |
| c005 | … 240 … | `ec8a8be7b5fe5f9f82ca953602e71d729c090d81e5a71cc8470e7c326e3e1029` | `cdf722b17ec2f5afda640dc2b383bd19bcf254babf7c42764869263d9cea9c19` |
| c006 | … 260 … | `99d45e1fd3d80e29be1623e3a6b1cf3dc615faaeb20bb972e9b7a73505b356ed` | `c6522c1413852f7feaf64418341052b1b250af32185c83c94fa84638c8bed5ae` |
| c007 | … 280 … | `b95a5ca5da21c6d8e425587535a352542329d7054e1c41f4bf38af6e4a232160` | `394f953da15324d16a5831f5fc90a270d96f8eed7862870b2ca508b55e0940ad` |
| c008 | … 300 … | `b075b8531997ee2519c0fe089c2728396b8f563b76335ca0df9767b1d69d2869` | `7f4ae002cd3395d26cb730e0e730a44faa81cff41de12ed4f8fbe7302a44aa18` |
| c011 | … 340 … | `939e29775cc0e9d08464c90f00b4716d466f5df46e43477851bf1da890dc9238` | `a4f35438333473a93e48f0c3d4bb49b076548973669ece5eabfcc7dbb48a95c3` |
| c012 | … 360 … | `171d0172a4013776a10df8c8194b4df247fca8d3798f7ed8fb61bf9fb65c7ea0` | `86ec3527430057b1d75fec2e875625b3a1c25882fa42559d2ea7bfb15c9e00e2` |
| c013 | … 380 … | `cf723cb89eaaa62d9fb8ce692f7d649efd009c19480de7270e77973538a1f2c6` | `148c32fc21d71e8f1626e4e69240ada2407d62da56ac93948241251aa0fa9ad6` |
| c014 | … 400 … | `d55b57467e2c236c56c3ef04d92c469509d8b60c27b54bcde04ebc3e3a4a2b54` | `9b38a62129ad81df4558cc687605cc09384c8fa1888cbf99ae8117fde4deaa00` |
| c015 | … 420 … | `af57cb8ac54c20874a6418ab285283538a91b2852b06b332eaec18d0a30b2875` | `79c4f8de6a4919f506357d13d4a539fe3e05609f277a6bc12f09ec2785ee4ef6` |
| c016 | … 440 … | `23382e779414344c43220c05503090139aeb7afcbd53f1930cbd9e05f8771f91` | `6095fff5a8607e9a8c03a1b4a425067622a3b82e3e06453a93abe3e5a26c7761` |
| c017 | … 460 … | `acf270eda4311a7eeeb69d7499e8fc9a5fcc2c72f4f422c70c5f6e09ce3797cc` | `ba8ea2aab847143eab032e09d2ea0fc926c9c4cb8c693ac95902f6fb4a26fb71` |
| c018 | … 480 … | `fa7e24163ad475ee00365e9def42b8544c11886e1fa1c178ee8d70c9fbf72664` | `53bee830a7ff8337b27d204e9defddf12ea8ab47317cb3ed3803f4327a1ab7fc` |
| **c074** | **profesional · true · 200 · cita (G8)** | **`702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c` (113 672 B)** | **`af9e0d23de42368f1cda211b03dc3164ce1a341acc7e6f22abf3d71afbdb4d51` (114 206 B)** |
| c243 | profesional · false · 160 · informar | `46306ce925739eae5bfbe3110f04429ccc680c55fa197cc218a2a4d286311967` (114 040 B) | `76ad0bf969e187dbfefdd5d9eba5afd72a4cea77361d1437170efb9d2aa2d5c5` (114 574 B) |
| c304 | entusiasta · false · 480 · venta | `67566f4e95f53e46c1141cc8d79a7848322dc78faa63b3b16d02887cb17e7093` (114 021 B) | `7ea13909374e001f7b5e50dd1ab7a26100dc168c20f0918f6d3350b7f7cb3be5` (114 555 B) |
| — | G8 + seis frases (`n8n/…/decide-studio-frases.json`) | `9a3eae9be1877a2a6c0958a67debcba887e6191fcd3d3c46e934a4ce51465dc0` | `101878474a3ffe0802fd799510a5a8df635a1ba6fbdd5aa538695c294e96ef57` |
| — | línea base + «gratis», «te lo regalo» (`DOS_FRASES_HASH`) | `fbd3c91cb9f283519fe7d2ac4daf60323d1151e2599a9cbee39195090e4d8916` | **igual** (320) |

Con el mismo `maxChars`, cada paquete crece 534 B: dos Composer × (487 − 220). Los cuatro casos que escribí a mano lo cumplen. La lista completa sale de comparar el fixture del disco con `git show HEAD:backend/tests/fixtures/ultron-prompts/compile-cases.json`.

---

## 6. Aserciones cambiadas

Todas cambian porque cambian el texto o la versión; ninguna se relaja. Las líneas son las nuevas.

**`tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerTest.php`**
- Nuevo `MARGEN` (:39): la tabla N → [M, P, F] a mano. `maxComposer()` (:81) compone el texto nuevo con esa tabla.
- `test_la_linea_base…`:
  - `'compiler' => 2` → 3 (:125);
  - `VERSION` 2 → 3 (:130);
  - **nueva**: la huella Legacy escrita a mano (:131).
- **Nuevas**:
  - `test_el_margen_del_redactor_sale_de_la_tabla_n_m_p_f`: 16 filas, M < N, M es una decena y los ejemplos del encargo;
  - `test_el_texto_del_tope_del_redactor_compilado_tal_cual`: 160, 200 y 480 literales. Comprueba ASCII, que no queda ningún `{`, el tope de bytes y que el Critic no cambia.
- `test_los_17_valores_de_max_chars` y `test_el_cambio_de_aceptacion…` no cambian de código: usan `maxComposer()`.

**`tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerPhrasesTest.php`**
- `G8_HASH` → `af9e0d23…`, y se añade `G8_HASH_COMPILADOR_2` = `702890f7…` (:38).
- `$base['compiler']` 2 → 3 (:82).
- `test_la_huella_de_profesional_y_200_no_cambia_con_el_compilador_2` → `…es_la_de_los_textos_del_compilador_3` (:85):
  - la huella nueva;
  - **nueva**: `assertNotSame` frente a la del 2;
  - bytes 113 672 → 114 206.

**`tests/Unit/Marketing/Ultron/Prompts/UltronPublishedConfigPhrasesTest.php`**
- `test_compilador_2_con_frases…` → `test_el_compilador_vigente_con_frases…`: compilador firmado y ofrecido 2 → 3 (:63 y :66).
- `test_compilador_2_sin_frases_ofrece_la_misma_huella_que_el_1` → `test_el_compilador_3_sin_frases_ofrece_la_huella_de_sus_textos`: `702890f7…` → `af9e0d23…` (:77).
- **Nueva**, `test_lo_firmado_con_el_compilador_2_sigue_aplicado_en_el_sello_y_cae_a_compiler_changed`: «profesional + 200» firmado con el 2 y la huella `702890f7`. El sello sigue `applied`, la parte se lee con sus cinco claves y `decide` da `legacy · compiler_changed`. Es el orden de despliegue del compilador 3.
- `test_un_compilador_posterior…`: `compiler` 3 → 4 (:110), porque el 3 ya es el vigente.

**`tests/Unit/Marketing/Ultron/Prompts/PromptPublications.php`** (trait): solo el comentario de `parteV2Compilador1` (:65).

**`tests/Unit/UltronStudio/StudioPromptDeclarationTest.php`**
- `G8_HASH` → `af9e0d23…` (:57). Sigue siendo un hexadecimal válido en las pruebas de forma con compilador 1.
- `partActual()` firma con 3 (:81).
- Legacy compilado: `'compiler' => 2` → 3 (:317).
- G8 compilado: bytes 113 672 → 114 206 (:324).
- La oferta de `decide`:
  - **nuevo**: lo firmado con el 2 (cinco claves y huella `702890f7`) da `compiler_changed` (:398-400);
  - el «compilador posterior» pasa de 3 a 4 (:402).

**`tests/Unit/UltronStudio/ExportStudioFixturesCommandTest.php`**: la huella de `previewPrompts` `702890f7…` → `af9e0d23…` (:122).

**`tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php`**
- `G8_HASH` → `af9e0d23…` (:47).
- La vista previa: `[…, 2, …, '702890f7', false, 113672]` → `[…, 3, …, 'af9e0d23', false, 114206]` (:143).
- Lo firmado: `'compiler' => 2` → 3 (:157).
- `bundle8` de los últimos turnos: `702890f7` → `af9e0d23` (:374-375). Sale de `G8_HASH`.

**`tests/Feature/UltronStudio/UltronStudioPublicationRulePhrasesTest.php`**
- `G8_HASH` → `af9e0d23…`, y se añaden `G8_HASH_COMPILADORES_1_Y_2` y `LONGITUD_MEDIDA` (:61-68).
- Vista previa y firma con frases: compilador 2 → 3 (:193 y :209).
- **Nueva** (:221-224): en `canary` y en `all`, el mensaje termina en `' '.LONGITUD_MEDIDA` (la frase justo antes de «Si no entiende») y «se mide antes de enviar» aparece una sola vez.
- `test_lo_publicado_con_el_compilador_1_cae…` → `test_lo_publicado_con_un_compilador_anterior_cae…`, con proveedor de datos `compilador 1 (los cuatro gobernados)` y `compilador 2 (los cinco, sin frases)`:
  - la parte se siembra con su huella real de entonces (`702890f7`);
  - los pasos y las aserciones del caso 1 son los mismos de antes;
  - el caso 2 es nuevo.

**`tests/Feature/UltronStudio/UltronStudioStatusCatalogTest.php`**: `engine.prompts.compiler` 2 → 3 (:42).

**`tests/Feature/Marketing/UltronDecidePromptsTest.php`**: la oferta, `[…, 2]` → `[…, 3]` (:194). Esta sola línea tumbaba 126 casos de la matriz.

**`tests/Feature/Marketing/UltronPromptsTraceCommandTest.php`**: la traza en JSON, `compiler` 2 → 3 (:72). No salía en el `grep` previo; la encontró la suite de Marketing.

Comprobado que nada compara con `=== 2`. `UltronPublishedConfig` (`:616`, `< PHRASES_SINCE_COMPILER`) y `StudioPublicationDeclaration` (`:1080`, `>= PHRASES_SINCE_COMPILER`) siguen admitiendo frases desde el 2. `StudioRulePhrasesTest:377` («compilador 2 con cuatro claves» es `malformed`) sigue verde sin tocarla **[C]**.

---

## 7. Cómo se regeneró cada cosa

Desde `backend/` **[C]**:

```bash
node tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs              # 306 casos, 30 inválidos, 56 textos; G8 af9e0d23…, 114206 B
node tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs --comprobar  # OK
node tests/fixtures/ultron-studio/tools/derivar-publication-cases-v2.cjs              # 9 entradas, 29 inválidas, 12 previewHash, 34 clasificaciones
node tests/fixtures/ultron-studio/tools/derivar-publication-cases-v2.cjs --comprobar  # OK
php artisan ultron:prompts-n8n                                                  # escribe en ../n8n/flujos/ultron-studio-s1 (solo el repositorio)
```

Antes de ejecutar el comando de n8n copié la carpeta en el scratchpad y guardé el sha256 de los tres ficheros que se ejecutan. Después **[C]**:

```
../n8n/flujos/ultron-studio-s1/elegir-prompts.js: OK      (fec89513…7d10)
../n8n/flujos/ultron-studio-s1/preparar-commit.js: OK     (876a3f6a…8b45)
../n8n/flujos/ultron-studio-s1/operaciones-mcp.json: OK   (1e1aca5a…1cdfc)

git diff --stat -- ../n8n/
 n8n/flujos/ultron-studio-s1/LEEME.md                           | 2 +-
 n8n/flujos/ultron-studio-s1/fixtures/decide-legacy.json        | 2 +-
 n8n/flujos/ultron-studio-s1/fixtures/decide-studio-frases.json | 8 ++++----
 n8n/flujos/ultron-studio-s1/fixtures/decide-studio.json        | 8 ++++----
 n8n/flujos/ultron-studio-s1/flujo-s1.json                      | 2 +-
 n8n/flujos/ultron-studio-s1/huellas.json                       | 2 +-
 6 files changed, 12 insertions(+), 12 deletions(-)
```

Qué cambia en cada fichero:
- `LEEME.md`: «compilador 2» → «compilador 3».
- `flujo-s1.json`: solo `_generado.compiler`, que nunca se importa.
- `huellas.json`: `compiler`. El `jsCodeSha256` del selector (`456e4d49…`) y el de Preparar Commit (`fb35df55…`) siguen iguales.
- `decide-legacy.json`: `compiler`.
- `decide-studio.json` y `decide-studio-frases.json`: `compiler`, `bundle_hash` y los dos Composer.

`UltronPromptsN8nCommandTest` (el repositorio igual a lo generado y el comprobador offline) pasa dentro de la suite de Marketing **[C]**.

---

## 8. El mensaje del motor (`ENGINE_MESSAGES_PROMPTS`)

En los dos (`canary` y `all`), justo antes de «Si no entiende» sigue sin efecto en WhatsApp.», se añade: `La longitud, además, se mide antes de enviar: a un mensaje que pasa del tope se le quita la pregunta final y, si aun así no cabe, se sustituye por un texto que cabe (con el tope de la línea base, 320, no se mide).`

Ninguna prueba fijaba el literal: `UltronStudioPublicationPromptsTest:169` y `UltronStudioPublicationRulePhrasesTest:219` comparan `data.engine.message` con la constante **[L]**. Por eso añadí la aserción de §6 con la frase exacta y su posición. El CRM muestra su propio texto (`studio-publication-text.ts`), no este mensaje: no lo he tocado.

---

## 9. `docs/ultron-studio/ops/`: el literal del compilador

- `smoke-h9.py`: cambiado (líneas 59 y 635-636).
- No hay otro `compiler == 2` en `ops/` **[C]** (`grep` en `.py`, `.sh`, `.cjs`, `.js`, `.php` y `.sql`). Lo único parecido:
  - `motores/verificar-flujo.cjs:228`: `compiler: 1` en una oferta sintética. El selector no mira el compilador. Sin tocar.
  - `deploy-m1-backend.sh:165`: solo nombra el fichero del compilador en la lista cerrada del despliegue M-1, un hito cerrado. Sin tocar.
  - `comprobacion-produccion/vista-previa.cjs:15`: solo nombres de clave.
- Solo aparecen como texto histórico, sin tocar:
  - `RUNBOOK_M1.md:294` y `motores/README.md:42`: la huella `702890f7…` como criterio de aceptación del M-1;
  - `motores/README.md:12`: «desde la versión 2».

---

## 10. Pruebas ejecutadas

Una ejecución de PHPUnit cada vez, desde `backend/`, con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …` **[C]**. Las salidas están en `a4-evidencia/12…16-compilador3-*.txt`.

| # | Qué | Resultado |
|---|---|---|
| 1 | `UltronPromptCompilerTest` con la prueba nueva, **antes** de tocar el compilador (primer texto) | **ROJO**: 57 pruebas, 1 error (`composerTargetChars` no existe) y 21 fallos (texto y versión). Esa salida se sustituyó por la del 1b, porque reproducía el primer texto |
| 1b | La misma, con el texto definitivo en las pruebas y el compilador todavía con el primero (§13) | **ROJO**: 57 pruebas, 20 fallos (los 16 topes, los 3 literales y G8) (`12-compilador3-rojo.txt`, solo la lista de fallos: sin los diffs) |
| 2 | El mismo, tras el compilador 3 con el texto definitivo | 57/57 OK (dentro del 3) |
| 3 | `tests/Unit/Marketing/Ultron/Prompts` + `UltronDecidePromptsTest` + `UltronCommitPromptsTest` + `UltronA4LongitudYPreciosTest` | **OK (1 109 pruebas, 13 943 aserciones)** (`13-…`) |
| 4 | `tests/Feature/UltronStudio tests/Unit/UltronStudio` | 3 768 pruebas, 41 616 aserciones, **1 fallo esperado** (`OutreachIsolationTest::test_la_puerta_de_diff…`) y 277 omitidas, que son las de solo PostgreSQL (`14-…`) |
| 5 | `tests/Feature/Marketing tests/Unit/Marketing` | **OK (6 138 pruebas, 36 233 aserciones)** (`15-…`) |
| 6 | Guardas: 15 ficheros (`*Isolation*`, `*ReadOnly*`, `*StaticGuards*`, `SecretExposure*`) | 138 pruebas, 179 252 aserciones, **1 fallo esperado**, el mismo (`16-…`) |
| 7 | `vendor/bin/pint --test` sobre los 13 PHP tocados | `passed` |

La roja esperada nombra exactamente `UltronPromptCompiler.php` (este cambio) y los tres de `daedbbd8`: `UltronPromptOffer.php`, `UltronCommitService.php` y `UltronReplyFitter.php`. `n8n/` no está en `DIFF_GATE`. No la he tocado.

---

## 11. Lo que no pude comprobar y observaciones

- **[S]** Que el margen baje la tasa de borradores largos (4 de 5 el 12-11) es una hipótesis. Se mide en el laboratorio y en la canaria, no con pruebas.
- **No ejecuté**:
  - las pruebas solo de PostgreSQL (las 277 omitidas). Comprobé con `grep` que ninguna fija `702890f7`, `G8_HASH` ni `compiler` 2;
  - las mutaciones (`mutar.php` exige una copia fuera del árbol). Solo comprobé que la cadena de X3 sigue apareciendo una vez.
- **`spec-v2.php`** (citado en el plan §5.9) no contiene nada del compilador ni del texto: no hacía falta cambiarlo. La que depende del código del compilador es `spec-m1.php` X3, y sigue valiendo.
- **Ya estaba antes (no lo arreglo):**
  - `spec-m1.php` X2 busca `public const PARAMETERS = [self::TONE, self::EMOJIS, self::MAX_CHARS, self::GOAL];`, que no existe desde el compilador 2 (ahora lleva `self::PHRASES`). Esa mutación saldría con 3, «cadena no encontrada».
  - `compile-cases.README.md:26` dice «los 26 inválidos»; son 30 desde el compilador 2.
- **El CRM** (no tocado, como pediste):
  - el fixture lo genera `php artisan ultron-studio:export-fixtures` desde `backend/`;
  - por defecto escribe los seis `.ts` en `../../Iron-Body_Front/src/app/modules/ultron-studio/testing` (en este árbol, `_worktrees/ultron-h8/Iron-Body_Front/…`); con `--path=<dir>`, en otro sitio;
  - el que cambia es `backend-publication.testing.ts`, con `compiler: 2` y `702890f7` en varias formas de ejemplo;
  - en el backend lo fija `tests/Unit/UltronStudio/ExportStudioFixturesCommandTest.php:122` (huella de `examples.previewPrompts.prompts.bundleHash`);
  - el lector del CRM decide con `>= RULE_PHRASES_SINCE_COMPILER` (`studio-engine-text.ts:267`), así que con 3 no cambia de rama **[L]**.
- `python3 -m py_compile` dejó un `.pyc` de `smoke-h9.py` en `ops/__pycache__/`, ignorado por git, y lo borré. La carpeta quedó vacía; no sé si antes había otro `.pyc` de ese guion (es caché).
- **Contrato:** la Enmienda A4-1 del H5 no está escrita. El texto del compilador 3 vive en §B.3 del plan, con la nota del HUMAN GATE 4-A. Si el propietario lo cambia, hay que corregir §B.3, `T_MAX_COMPOSER`, `parches-m1.cjs` y las dos pruebas que lo escriben a mano (`UltronPromptCompilerTest::maxComposer()` y `textosDelTopeCompilados()`), y regenerar los dos fixtures y `n8n/flujos/ultron-studio-s1/` con los comandos de §7.

---

## 12. Variables, migraciones y configuración

Ninguna. Sin migraciones, sin `config/`, sin rutas y sin variables de entorno.

Al desplegar, `prompts.compiler` pasa a 3 en `status`, `decide` y `ultron:prompt-trace`. Lo publicado con el compilador 2 cae a `legacy · compiler_changed` hasta publicar una versión nueva; según el plan, hoy no hay nada publicado en la canaria, y no lo he comprobado.

---

## 13. Cambio de texto (decisión del lead tras la revisión final)

El recorte del borrador se limita a quitar la pregunta final. Cambian dos textos y nada más: las fórmulas de M, P y F siguen iguales.

- `T_MAX_COMPOSER` es ahora el texto definitivo del lead, igual byte a byte en el compilador, en §B.3 del plan y en `parches-m1.cjs`. Lo comprobé con `ReflectionClassConstant` y Node contra el texto copiado del mensaje, y con `comprobarTextosContraElPlan()`: OK **[C]**. Tiene 490 caracteres ASCII.
- El «por qué» del apartado «Compilador 3» de §B.3 describe ahora lo que hace el commit con esa decisión: quitar la pregunta final y, si aun así no cabe, descartar.
- La frase de `ENGINE_MESSAGES_PROMPTS`, en los dos mensajes, y su copia en `UltronStudioPublicationRulePhrasesTest::LONGITUD_MEDIDA`, es la del lead.
- Orden: primero las pruebas con el texto nuevo, en rojo (fila 1b de §10); después el compilador, el plan, el guion y los mensajes; después la regeneración de los dos fixtures y de `n8n/flujos/ultron-studio-s1/`, con los comandos de §7. Los tres ficheros que se ejecutan en n8n volvieron a dar el mismo sha256 **[C]**.
- Las huellas volvieron a cambiar. Las tablas de §5 son ya las del texto definitivo frente al compilador 2. G8 queda en `af9e0d23de42368f1cda211b03dc3164ce1a341acc7e6f22abf3d71afbdb4d51` (114 206 B). El cambio de huellas y bytes en las pruebas es el mismo de §6, con estos valores. Siguen moviéndose 288 de 306 casos, 18 iguales, 16 textos y 30 inválidos iguales.
- Las cifras de §10 son las de las ejecuciones con el texto definitivo: `a4-evidencia/13…16` se reescribieron con ellas.
- **Ni una copia del texto anterior** en `app/`, `tests/`, `docs/` y `n8n/` **[C]**. Hice `grep -rnF` de las dos frases que citó el lead (la del redactor y la del mensaje del motor) sobre `app tests docs ../n8n`, desde `backend/`, y no hay resultados. Este informe no las reproduce, para que el `grep` siga vacío. La evidencia del primer rojo, que sí las contenía en sus diffs, se sustituyó (fila 1b de §10).
