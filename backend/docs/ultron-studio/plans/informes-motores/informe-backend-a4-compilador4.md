# Informe del backend · Apartado 4 · Compilador de prompts 4 (el registro profesional, H4-9)

18-11-2026 · rama `feat/ultron-studio-h9`, HEAD local `8e710104` (sin subir; `origin/main` = `bc5167cb`) · **sin commit**: todo está en el árbol de trabajo.
Guía: el encargo del lead (`encargo-be-a4c4.md`), `plan-arquitecto-a4-resto.md` §2.1, §2.2 (b), §2.3 y §4, y el precedente `informe-backend-a4-compilador3.md`.
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto o no comprobado.

---

## 1. Resumen

- `UltronPromptCompiler::VERSION` pasa de 3 a **4**. Cambian `T_TONE_PRO_COMPOSER` y `T_TONE_PRO_CRITIC`, con los textos del encargo, byte a byte y sin retocar, y, por decisión del lead del 19-11, `T_PHRASES_COMPOSER` y `T_PHRASES_CRITIC` con los de `revision-frases-prohibidas.md` §6 (§13). No cambian las anclas (`COMO SUENAS:` y `naturalness:`), las operaciones ni el orden.
- **Comprobación (a), el tope:** 678 B (redactor) y 329 B (revisor), con un tope de 1 200 por parche. Los dos son ASCII, de una línea, sin `{` y sin ningún ancla **[C]**.
- **Comprobación (b), que el revisor puede fallar:** **sí, con esa redacción; no hace falta otra** (§3). «verdict fail con naturalness y notes "…"» se puede expresar en el esquema `ultron_critic` del nodo: `verdict` admite `pass` o `fail`, `issues` incluye `naturalness` y `notes` es texto libre. El nodo «Critic aprueba?» manda al Composer Reintento todo lo que no es `pass`, y ese reintento recibe `critic_issues` y `critic_notes` **[L]**. Es la misma forma que ya usan los parches de emojis y de frases. No choca con «FALLA si» ni con «si cumple, aprueba».
- La línea base sigue sin parche. La huella Legacy `3f6a5674…c902` **no se mueve**, y lo fija una aserción escrita a mano **[C]**.
- **Con `cercano` y `entusiasta` sin frases no cambia ninguna huella respecto al compilador 3** (con frases cambian todas, de cualquier tono, por los textos nuevos de las frases: §13): 204 de 306 casos son idénticos byte a byte **[C]** (script contra la copia previa del fixture; dos huellas escritas a mano en la prueba nueva). Se mueven **los 102 casos de `profesional`**, cada uno con +1 240 B. La de «profesional, emojis sí, 200, cita» pasa de `af9e0d23…4d51` (114 206 B) a **`7b09b89c98d48b01561b9cbc07e048c152c0af6a4ba7f583becd49f8e7e8cc35`** (115 446 B). Coinciden las tres vías: PHP, el guion Node del tester y Python al margen **[C]**.
- Se regeneraron con sus derivadores `compile-cases.json`, `publication-cases-v2.json` y los ficheros generados de `n8n/flujos/ultron-studio-s1/`. Los tres que se ejecutan en n8n **no cambian ni un byte** (sha256 antes y después) **[C]**.
- §B.3 del plan tiene el apartado nuevo «Compilador 4 (Enmienda A4-2 del contrato H5)», con los dos textos y la marca «pendientes de la aprobación del propietario». `parches-m1.cjs` exige que los dos textos estén escritos **dentro** de ese apartado; comprobado que lanza si se alteran **[C]**.
- `smoke-h9.py` espera el compilador 4.
- Frases prohibidas: **cambiadas** con la propuesta de `revision-frases-prohibidas.md` §6, aceptada por el lead el 19-11 (§13). `compile-cases.json` y `publication-cases-v2.json` no cambian por ello (sin frases no hay parche).
- Pruebas: cada cambio con su rojo antes (el tono, §10; las frases, §13). Después, todo verde salvo la roja esperada de la puerta de diff. Las cifras finales son las de §13.

---

## 2. Ficheros tocados

| Fichero | Cambio | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php` | +46/−8 | `VERSION = 4`. Los dos textos de `profesional` y los dos textos fijos de las frases (§13), cada pareja con un comentario que explica por qué sustituye a la anterior. Apartado «VERSIÓN 4» en el docblock de la clase y en el de `VERSION`. |
| `docs/ultron-studio/plans/STUDIO_MOTORES_PLAN.md` | +15 | §B.3, apartado nuevo «Compilador 4 (Enmienda A4-2 del contrato H5, 18-11-2026)». Incluye: la nota «pendiente de la aprobación del propietario» (los cuatro textos), por qué, qué sustituye (los textos del tono de la tabla quedan como históricos), los cuatro textos entre `«…»`, las medidas (también el peor caso de las frases) y la coherencia con el esquema del Critic, y lo que no cambia. |
| `docs/ultron-studio/ops/smoke-h9.py` | +3/−3 | El docstring (:59) y la comprobación (:635-636): `compiler == 4`. Sintaxis comprobada con `ast.parse`, sin dejar `.pyc` **[C]**. |
| `tests/fixtures/ultron-prompts/tools/parches-m1.cjs` | +20/−5 | `COMPILER = 4`; los dos textos nuevos. `comprobarTextosContraElPlan()` **intacta y ampliada**: sigue exigiendo el apartado «Compilador 3» con `VERSION = 3`, y además exige `#### Compilador 4`, `VERSION = 4` y los dos textos **dentro** de ese apartado (`DEL_COMPILADOR_4`). |
| `tests/fixtures/ultron-prompts/compile-cases.json` | +105/−105 | Regenerado: 102 casos y 2 textos; `compiler`. |
| `tests/fixtures/ultron-prompts/compile-cases.README.md` | +6/−2 | Apartado «Compilador 4» y cifras de control puestas al día. |
| `tests/fixtures/ultron-studio/publication-cases-v2.json` | +53/−53 | Regenerado: solo huellas de paquete y, por derivación, `effectiveJson`, `effectiveHash` y las firmas (§7). |
| `../n8n/flujos/ultron-studio-s1/` (6 ficheros) | +16/−16 | Regenerados con `php artisan ultron:prompts-n8n`, dos veces (§7 y §13). |
| Pruebas (11 ficheros) | ver §6 y §13 | Las aserciones que fijaban los textos del tono o de las frases, la versión 3 o las huellas `af9e0d23` y `fbd3c91c`. |
| `docs/.../a4-evidencia/57…61-compilador4-*.txt` y `62…66-frases-*.txt` | nuevos | Salidas de PHPUnit, dos pasadas (el tono; el tono y las frases): rojo, prompts y motor, Studio, Marketing y guardas. |
| `docs/.../informes-motores/revision-frases-prohibidas.md` | nuevo | La revisión de las frases prohibidas (punto 3 del encargo), con la línea final de la decisión del lead. |

**No tocados** (los límites del encargo), comprobado con `git status` **[C]**:
- `UltronCommitService.php`, `UltronPromptOffer.php`, `UltronReplyFitter.php`, `UltronRegisterDetector.php`, `UltronFormalReplies.php`, `StrategyContract.php`, `CommercialTurnPolicy.php` y `SalesConversationReplyService.php`;
- `resources/`, `config/`, rutas y migraciones;
- el CRM (`Iron-Body_Front`, 0 cambios);
- el n8n en marcha: ni MCP, ni API, ni importación.

En `app/` solo cambia `UltronPromptCompiler.php`. El commit local `8e710104` queda intacto.

---

## 3. Comprobación (b): ¿puede fallar el revisor con «verdict fail con naturalness»?

**Sí. No propongo otra redacción.** Evidencia [L]:

1. **Esquema de salida** del nodo «Critic Comercial» y «Critic Comercial 2» (respaldo `n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json`, `textFormat.json_schema` `ultron_critic`, `strict: true`):
   - `verdict` ∈ {`pass`, `fail`};
   - `issues` es una lista con un vocabulario cerrado que **incluye `naturalness`**;
   - `notes` es texto libre;
   - `hard_fail` puede ser null.

   Por tanto, «verdict fail con naturalness y notes "pasar todo el mensaje a usted"» se escribe literalmente así: `{"verdict":"fail","issues":["naturalness"],"notes":"pasar todo el mensaje a usted","hard_fail":null}`.
2. **El reintento.** El nodo IF «Critic aprueba?» compara `verdict` con `"pass"`. La rama falsa va a «Composer Reintento», cuyo mensaje lleva `rejected_draft`, `critic_issues` y `critic_notes`, y su prompt de sistema lleva el mismo parche del tono. Si «Critic Comercial 2» vuelve a fallar, el flujo sigue a «Preparar Commit» y el commit entra por `CriticContract::isFail` → `handleCriticFailure` (`UltronCommitService.php:450`). Sale un respaldo, que con `profesional` es de usted gracias al commit `8e710104`.
3. **Las reglas de `critic.txt` y `critic_retry.txt`:**
   - «No inventes defectos: si el borrador cumple, aprueba» (l. 3). Con el registro publicado, tutear es **no cumplir**: el parche lo convierte en un fallo explícito, no en un defecto inventado.
   - La lista «FALLA (verdict "fail") si:» (l. 34) no se presenta como cerrada: la siguen «FALLA TAMBIEN…» y varias líneas sueltas. Además, el parche va pegado a `naturalness:` (l. 8), dentro de la lista de dimensiones, igual que los parches de emojis y de frases, que ya dicen «verdict fail con naturalness».
   - `issues: SOLO claves de la lista de dimensiones` (l. 56): `naturalness` lo es.
   - `notes: una frase concreta y accionable para el reintento, sin cifras ni datos de la persona` (l. 56): «pasar todo el mensaje a usted» lo cumple.
   - La causa C3 del plan («naturalness puntúa, no hace fallar») queda resuelta: el texto ya no dice «es defecto de naturalness», sino `verdict fail`.
4. **Una mejora de paso.** El texto anterior del revisor decía que «usar muletillas coloquiales SI lo es [defecto]», en contradicción con `critic.txt:54` («Fuera de un reclamo, "Tranquilo" es una muletilla normal y NO es motivo de rechazo»). El nuevo ya no habla de muletillas, así que esa contradicción desaparece. Las muletillas quedan solo en el texto del redactor, como petición de estilo; el revisor no las hace fallar. Lo fija una aserción (§6).
5. **Una tensión que no resuelve el parche, y no hace falta.** `critic_retry.txt:3` dice «Este ya es el segundo intento: rechazarlo otra vez deja al cliente sin respuesta util», lo que empuja al segundo revisor a aprobar [S: es una tendencia del modelo, no comprobada]. Si aprueba un borrador que tutea, lo para el detector determinista del commit (`policy_register_mismatch`, commit `8e710104`), y sale el respaldo de usted. Si lo suspende, sale el mismo respaldo. En los dos casos, con «profesional» no se envía tuteo a la persona.

---

## 4. El texto compilado del tono

Tal como queda **[C]**, lo fijan literalmente `UltronPromptCompilerTest::test_tono_profesional` y `::test_el_texto_del_tono_profesional_compilado_tal_cual`.

**Composer y Composer Reintento**, en la línea siguiente a `COMO SUENAS:` (l. 42 de `composer.txt`, que remite a `knowledge_base.tone`):

> REGISTRO PUBLICADO: profesional. Esto manda sobre TODO este prompt, tambien sobre los ejemplos de abajo, knowledge_base.tone, suggested_question y approved_brand_copy: trata a la persona de usted en cada frase, sin mezclar. Los ejemplos se dicen de usted: "Te entiendo" es "Le entiendo", "tu objetivo" es "su objetivo", "te paso" es "le paso", "cuentame" es "cuenteme", "quieres" es "quiere". Nunca "te", "tu", "tus", "ti" ni "contigo". Frases completas y sobrias, sin muletillas coloquiales ("De una", "Listo", "Tranquilo") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio. El backend comprueba el registro antes de enviar y descarta el mensaje que tutee.

**Critic y Critic 2**, en la línea siguiente a `naturalness:`. Con emojis desactivados y frases, el orden es `naturalness:` → `FRASES PROHIBIDAS…` → `EMOJIS…` → `REGISTRO PUBLICADO…` → `context_use:` **[C]**:

> REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto. Si el borrador tutea en cualquier frase o mezcla tu y usted ("te", "tu", "tus", "ti", "contigo", "quieres", "puedes", "cuentame", "dime"), verdict fail con naturalness y notes "pasar todo el mensaje a usted". Lo que va entre comillas citando a la persona no cuenta.

**Medidas [C]:**
- 678 y 329 bytes. Antes eran 262 y 125.
- Texto de parche más largo de toda la matriz: 678 B, antes 487 (el del tope).
- Paquete más grande: 115 961 B (antes 114 721), con un tope de 163 840. Rol más grande: 29 570 B, sin cambio.

**Nota sobre la frase «El backend comprueba el registro antes de enviar y descarta el mensaje que tutee».** Hoy es verdad solo si el commit `8e710104` (el detector y `sp.t`) se despliega junto con este compilador. Si el compilador 4 se desplegara sin ese commit, la frase sería falsa. Lo dejo dicho para el despliegue; no cambio el texto, porque es el aprobado.

---

## 5. Huellas

Comparación caso a caso de `compile-cases.json` regenerado contra la copia previa (guardada en el scratchpad antes de regenerar, idéntica a `HEAD`) **[C]**:
- **Se mueven 102 de 306**: todos los de `profesional` (2 emojis × 17 `maxChars` × 3 objetivos) y ninguno más. Cada uno crece **+1 240 B**: dos Composer × (678 − 262) y dos Critic × (329 − 125).
- **No se mueven 204**: los 102 de `cercano` (la línea base incluida) y los 102 de `entusiasta`, con `expect` byte a byte el del compilador 3.
- Cambian los textos `tone.profesional.composer` y `tone.profesional.critic` y ningún otro. Los 30 inválidos y el resto del marco (`baseRoles`, `limits`, `baseline`…) quedan iguales.

| Caso | Gobernados | Antes (compilador 3) | Después (compilador 4) |
|---|---|---|---|
| c000 | cercano · true · **320** · cita (Legacy) | `3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902` (112 216 B) | **igual** |
| c001 | cercano · true · 160 · cita | `bedc1c5dca22113b065384e0c9867f8d1f5bbe3652227562927cfdee9d20f294` | **igual** (escrita a mano en la prueba nueva) |
| c304 | entusiasta · false · 480 · venta | `7ea13909374e001f7b5e50dd1ab7a26100dc168c20f0918f6d3350b7f7cb3be5` | **igual** (escrita a mano en la prueba nueva) |
| c020 | profesional · true · 320 · cita (solo el tono) | `270577dae06f77f13b4db6ec07b7a2b38836be6f5b6a97536b2287d87d4cd0ec` (112 994 B) | `8f1d6050b7d8b330da6dd1b6c5ef97d622f047e7802015744d596f6a2f5e1d98` (114 234 B) |
| **c074** | **profesional · true · 200 · cita (G8, la versión 2 del canario)** | **`af9e0d23de42368f1cda211b03dc3164ce1a341acc7e6f22abf3d71afbdb4d51` (114 206 B)** | **`7b09b89c98d48b01561b9cbc07e048c152c0af6a4ba7f583becd49f8e7e8cc35` (115 446 B)** |
| c243 | profesional · false · 160 · informar | `76ad0bf969e187dbfefdd5d9eba5afd72a4cea77361d1437170efb9d2aa2d5c5` (114 574 B) | `2a5954a2de47ea38306e5820d3727278f74a441fea9e375eea7da1001d5d78d3` (115 814 B) |
| — | G8 + seis frases (`n8n/…/decide-studio-frases.json`) | `101878474a3ffe0802fd799510a5a8df635a1ba6fbdd5aa538695c294e96ef57` (compilador 3) | `e67308794d79e559bb5f14269588291ec513e7766b9554274f226f391e5796c4` (117 444 B; con solo el tono nuevo era `73f948f6…9cfe`, §13) |
| — | línea base + «gratis», «te lo regalo» (`DOS_FRASES_HASH`) | `fbd3c91cb9f283519fe7d2ac4daf60323d1151e2599a9cbee39195090e4d8916` (112 972 B) | `5a3957ee0239f6a85352c1eadfba1183143dcf6e9c085e47d2f8deab74ba6df8` (114 010 B; `cercano`, cambia por los textos de las frases, §13) |

**G8 por tres vías [C]:**
- PHP (`UltronPromptCompiler`);
- el guion Node del tester, que no lee el PHP (`derivar-compile-cases.cjs`: «V2 de aceptación… 7b09b89c…, 115446 B, 10 parches»);
- un script de Python escrito aquí, que aplica los parches de §B.3 sobre la base. Antes de usarlo para el compilador 4, comprobé que reproduce la huella Legacy y `af9e0d23` con los textos del compilador 3.

**La lista completa de las 102 huellas que se mueven** está en el Anexo A.

---

## 6. Aserciones cambiadas

Todas cambian porque cambian el texto o la versión; **ninguna se relaja**. Las líneas son las nuevas.

**`tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerTest.php`**
- Docblock: punto 8, «Compilador 4».
- `PRO_COMPOSER` y `PRO_CRITIC` (:66, :68): los textos nuevos, escritos a mano. Con ellos cambian, sin tocar su código, `test_tono_profesional`, `test_dos_inserciones…` y `test_el_cambio_de_aceptacion_profesional_y_200`.
- `test_la_linea_base…`:
  - `'compiler' => 3` → 4 (:132);
  - `VERSION` 3 → 4 (:137);
  - la huella Legacy escrita a mano sigue igual, solo cambia el mensaje (:138).
- **Nuevas:**
  - `test_el_texto_del_tono_profesional_compilado_tal_cual`: comprueba que los dos textos están tras su ancla en los cuatro roles; que son ASCII imprimible, sin `{` y ≤ `MAX_PATCH_BYTES`; que miden exactamente 678 y 329 bytes; que el del redactor manda sobre «TODO este prompt, tambien sobre los ejemplos de abajo» y ya no sobre «los ejemplos de arriba»; que el del revisor lleva «verdict fail con naturalness y notes "pasar todo el mensaje a usted"»; y que **no** habla de muletillas.
  - `test_el_compilador_4_solo_mueve_las_huellas_de_profesional`: Legacy, `cercano · 160` y `entusiasta · false · 480 · venta` con sus huellas del compilador 3, escritas a mano; G8 = `7b09b89c…`, ≠ `af9e0d23…`, con 115 446 B.

**`tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerPhrasesTest.php`**
- `G8_HASH` → `7b09b89c…`; se añade `G8_HASH_COMPILADOR_3` = `af9e0d23…` (:42-47).
- `$base['compiler']` 3 → 4.
- `test_la_huella_de_profesional_y_200_es_la_de_los_textos_del_compilador_3` → `…_del_compilador_4`:
  - la huella nueva;
  - **nueva**: `assertNotSame` frente a la del 3;
  - bytes 114 206 → 115 446.

**`tests/Unit/Marketing/Ultron/Prompts/UltronPublishedConfigPhrasesTest.php`**
- Docblock, punto 3: el orden de despliegue del 4.
- `test_el_compilador_vigente_con_frases…`: el compilador firmado y el ofrecido pasan de 3 a 4.
- `test_el_compilador_3_sin_frases_ofrece_la_huella_de_sus_textos` → `test_el_compilador_4_…`: `af9e0d23…` → `7b09b89c…`.
- **Nueva**, `test_lo_firmado_con_el_compilador_3_sigue_aplicado_en_el_sello_y_cae_a_compiler_changed`: «profesional + 200» firmado con el 3 y la huella `af9e0d23`. Es lo que la versión 2 del canario deja en la cadena. El sello sigue `applied`, la parte se lee con sus cinco claves y `decide` da `legacy · compiler_changed`.
- `test_un_compilador_posterior…`: `compiler` 4 → 5, porque el 4 ya es el vigente.

**`tests/Unit/Marketing/Ultron/Prompts/PromptPublications.php`** (trait): solo el comentario de `parteV2Compilador1`.

**`tests/Unit/UltronStudio/StudioPromptDeclarationTest.php`**
- `G8_HASH` → `7b09b89c…`.
- `partActual()` firma con 4.
- Legacy compilado: `'compiler' => 3` → 4.
- G8 compilado: bytes 114 206 → 115 446.
- La oferta de `decide`:
  - **nuevo**: lo firmado con el 3 (huella `af9e0d23`) da `compiler_changed`;
  - el «compilador posterior» pasa de 4 a 5.

**`tests/Unit/UltronStudio/ExportStudioFixturesCommandTest.php`**: la huella de `previewPrompts`, `af9e0d23…` → `7b09b89c…`.

**`tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php`**
- `G8_HASH` → `7b09b89c…`.
- La vista previa: `[…, 3, …, 'af9e0d23', false, 114206]` → `[…, 4, …, '7b09b89c', false, 115446]`.
- Lo firmado: `'compiler' => 3` → 4.
- `bundle8` de los últimos turnos: `af9e0d23` → `7b09b89c`.

**`tests/Feature/UltronStudio/UltronStudioPublicationRulePhrasesTest.php`**
- `G8_HASH` → `7b09b89c…`; se añade `G8_HASH_COMPILADOR_3`.
- Vista previa y firma con frases: compilador 3 → 4.
- Docblock: el orden de despliegue del 4.
- `compiladoresAnteriores()`:
  - gana un tercer dato, la huella de entonces de cada compilador (antes, la de los compiladores 1 y 2 estaba fija dentro de la prueba);
  - **caso nuevo**: `compilador 3 (los cinco, sin frases; la versión 2 del canario)`, con `af9e0d23`. Lo publicado con el 3 cae a Legacy hasta revertirlo y publicar otra versión, y la versión nueva se ofrece con `7b09b89c`.
  - Los casos 1 y 2 siguen con `702890f7` y los mismos pasos.

**`tests/Feature/UltronStudio/UltronStudioStatusCatalogTest.php`**: `engine.prompts.compiler` 3 → 4.

**`tests/Feature/Marketing/UltronDecidePromptsTest.php`**: la oferta, `[…, 3]` → `[…, 4]` (:194), con el mensaje «el compilador 4 (Enmienda A4-2)».

**`tests/Feature/Marketing/UltronPromptsTraceCommandTest.php`**: la traza en JSON, `compiler` 3 → 4 (:72).

Comprobado con `grep` sobre `tests/` y `app/` **[C]**:
- no queda ningún `'compiler' => 3` que pida el vigente;
- `af9e0d23` solo aparece como huella «del compilador 3» (histórica) o en comentarios;
- nada compara con `=== 3`.

Las pruebas solo de PostgreSQL (277 omitidas en SQLite) no fijan ni `af9e0d23` ni el compilador 3: el mismo `grep` las cubre.

---

## 7. Cómo se regeneró cada cosa

Orden: primero la prueba con los textos nuevos, en rojo (§10, fila 1). Después el compilador, §B.3 y `parches-m1.cjs`. Después la regeneración de los dos fixtures y de `n8n/flujos/ultron-studio-s1/`. Desde `backend/` **[C]**:

```bash
node tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs              # 306 casos, 30 inválidos, 56 textos; G8 7b09b89c…, 115446 B, 10 parches
node tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs --comprobar  # OK
node tests/fixtures/ultron-studio/tools/derivar-publication-cases-v2.cjs              # 9 entradas, 29 inválidas, 12 previewHash, 34 clasificaciones
node tests/fixtures/ultron-studio/tools/derivar-publication-cases-v2.cjs --comprobar  # OK
php artisan ultron:prompts-n8n                                                  # escribe en ../n8n/flujos/ultron-studio-s1 (solo el repositorio)
```

**La guarda del derivador sigue teniendo dientes [C].** Con un espacio de más en el texto del revisor en memoria (`verdict  fail`), lanza «El texto tone.profesional.critic no aparece literalmente en §B.3 del plan». Con el texto del compilador 3 (que sí está en §B.3, en la tabla, pero fuera del apartado nuevo), lanza «…no aparece literalmente en el apartado «Compilador 4» de §B.3».

**`publication-cases-v2.json` [C]** (diff estructural campo a campo contra la copia previa): 53 hojas cambian:
- 28 `bundleHash`: `af9e0d23…` → `7b09b89c…` y `76ad0bf9…` → `2a5954a2…`. Una de ellas es el inválido «huella truncada», que es la misma huella sin el primer carácter.
- 6 `effectiveJson`, que solo difieren en esas huellas.
- Las derivadas: 6 `effectiveHash`, 6 `signature` y 6 `signatureWithOwnContractPrefix`.
- Ninguna otra diferencia.

**`n8n/flujos/ultron-studio-s1/` [C].** Antes de ejecutar el comando copié la carpeta en el scratchpad y guardé el sha256 de los tres ficheros que se ejecutan. Después:

```
elegir-prompts.js: OK      (fec89513…7d10)
preparar-commit.js: OK     (876a3f6a…8b45)
operaciones-mcp.json: OK   (1e1aca5a…1cdfc)

git diff --stat -- n8n/
 n8n/flujos/ultron-studio-s1/LEEME.md                           |  2 +-
 n8n/flujos/ultron-studio-s1/fixtures/decide-legacy.json        |  2 +-
 n8n/flujos/ultron-studio-s1/fixtures/decide-studio-frases.json | 12 ++++++------
 n8n/flujos/ultron-studio-s1/fixtures/decide-studio.json        | 12 ++++++------
 n8n/flujos/ultron-studio-s1/flujo-s1.json                      |  2 +-
 n8n/flujos/ultron-studio-s1/huellas.json                       |  2 +-
```

Qué cambia en cada fichero:
- `LEEME.md`: «compilador 3» → «compilador 4».
- `flujo-s1.json`: solo `_generado.compiler`, que no se importa.
- `huellas.json`: `compiler`. El `jsCodeSha256` del selector (`456e4d49…`) y el de Preparar Commit (`fb35df55…`) siguen iguales.
- `decide-legacy.json`: `compiler`.
- `decide-studio.json` y `decide-studio-frases.json`: `compiler`, `bundle_hash` y los **cuatro** roles con tono (en el compilador 3 eran solo los dos Composer).

Comprobadores offline del flujo, desde la raíz del repositorio **[C]**:
- `node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs n8n/flujos/ultron-studio-s1/flujo-s1.json` → «OK (121 comprobaciones)».
- `node backend/tests/fixtures/ultron-prompts/tools/verificar-selector.cjs` → «OK: 1737 comprobaciones del selector (306 casos del contrato)».
- `UltronPromptsN8nCommandTest` (el repositorio igual a lo generado y el comprobador) pasa dentro de la suite de Marketing.

---

## 8. `docs/ultron-studio/ops/` y el CRM

- **`smoke-h9.py`:** cambian la línea 59 (docstring) y las 635-636 (etiqueta y `compiler == 4`). No hay otro `compiler == 3` en `ops/` **[C]** (`grep` en `.py`, `.sh`, `.cjs`, `.js`, `.php` y `.sql`).
- **El CRM, no tocado (fuera del alcance):** su fichero generado `Iron-Body_Front/src/app/modules/ultron-studio/testing/backend-publication.testing.ts` y `studio-prompts-fixtures.testing.ts` siguen con el texto del tono del compilador 1–3. Se regeneran con `php artisan ultron-studio:export-fixtures`, como en el precedente. Lo fija `ExportStudioFixturesCommandTest:122`, ya puesto al día. El lector del CRM decide con `>= RULE_PHRASES_SINCE_COMPILER`, así que con el 4 no cambia de rama [L, precedente].
- **`dtos-studio-prompts.md`** (informes) cita el texto del tono de entonces como documento histórico: sin tocar.

---

## 9. Despliegue (para quien lo haga; no he desplegado nada)

- Al desplegar, `prompts.compiler` pasa a 4 en `status`, `decide` y `ultron:prompt-trace`.
- **Lo publicado con el compilador 3 cae a Legacy** en `decide` (`compiler_changed`) hasta volver a publicar. Eso incluye la versión 2 del canario (`af9e0d23`), y también lo publicado con `cercano` o `entusiasta`, porque `decide` compara el compilador antes que la huella (`UltronPromptOffer.php:157`) [L]. Es el punto de no retorno práctico del plan §6. Las pruebas nuevas fijan ese orden de despliegue.
- La frase del redactor «El backend comprueba el registro antes de enviar…» exige que se despliegue junto con el commit `8e710104` (§4).
- La puerta de diff del H9 nombra ahora también `UltronPromptCompiler.php`. Hace falta la enmienda del lead (como A41-10), igual que para los ficheros de `8e710104`.

---

## 10. Pruebas ejecutadas

Una ejecución de PHPUnit cada vez, desde `backend/`, con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …` **[C]**. Las salidas están en `a4-evidencia/57…61-compilador4-*.txt`, cada una con su encabezado (qué, comando y hora). Es la **primera pasada** (solo el tono); la segunda, ya con las frases y con las cifras finales, está en §13.

| # | Qué | Resultado |
|---|---|---|
| 1 | `UltronPromptCompilerTest` con los textos nuevos y la versión 4 escritos a mano, **antes** de tocar el compilador | **ROJO**: 59 pruebas, 5 039 aserciones, **6 fallos**: la versión, `test_tono_profesional`, el orden de las dos inserciones, el cambio de aceptación, las dos pruebas nuevas (`57-…`). En la de las huellas, Legacy, `cercano` y `entusiasta` ya pasaban: falla en G8 |
| 2 | La misma, tras el compilador 4 | 59/59 OK, 5 080 aserciones |
| 3 | `tests/Unit/Marketing/Ultron/Prompts` + `UltronDecidePromptsTest` + `UltronCommitPromptsTest` + `UltronPromptsTraceCommandTest` + `UltronA4LongitudYPreciosTest` + `UltronA4RegistroYPanoramaTest` | **OK (1 179 pruebas, 15 031 aserciones)** (`58-…`) |
| 4 | `tests/Feature/UltronStudio tests/Unit/UltronStudio` | 3 769 pruebas, 41 662 aserciones, **1 fallo esperado** (`OutreachIsolationTest::test_la_puerta_de_diff…`) y 277 omitidas, las de solo PostgreSQL (`59-…`) |
| 5 | `tests/Feature/Marketing tests/Unit/Marketing` | **OK (6 340 pruebas, 37 584 aserciones)** (`60-…`) |
| 6 | Guardas: 16 ficheros (`*Isolation*`, `*ReadOnly*`, `*StaticGuards*`, `SecretExposure*`) | 141 pruebas, 180 308 aserciones, **1 fallo esperado**, el mismo (`61-…`) |
| 7 | `vendor/bin/pint --test` sobre los 12 PHP tocados | `passed` |
| 8 | Fuera de PHPUnit: los dos `--comprobar`, `verificar-flujo.cjs`, `verificar-selector.cjs` y el sha256 de los tres ficheros de n8n | OK (§7) |

La roja esperada nombra 10 ficheros. Son `UltronPromptCompiler.php` (este cambio) y los de `8e710104`, que ya estaban antes de esta tanda:
- `CommercialTurnPolicy.php`, `ConversationMemoryService.php`, `UltronPromptOffer.php`, `StrategyContract.php`, `UltronCommitService.php`, `UltronDecideService.php` y `UltronReplyFitter.php`;
- y los dos nuevos de ese commit, `UltronFormalReplies.php` y `UltronRegisterDetector.php`.

`n8n/` no está en `DIFF_GATE`. No la he tocado.

---

## 11. Lo que no comprobé y observaciones

- **[S] Que el texto nuevo baje la tasa de tuteo y que el revisor lo haga fallar de verdad** es una hipótesis. Se mide en el laboratorio y en la canaria con `metadata.register`, no con pruebas: aquí ningún modelo se ejecuta.
- **No ejecuté:**
  - las pruebas solo de PostgreSQL (las 277 omitidas). Comprobé con `grep` que ninguna fija `af9e0d23`, `G8_HASH` ni el compilador 3;
  - las mutaciones (`mutar.php` exige una copia fuera del árbol);
  - nada contra producción ni contra el n8n en marcha.
- **Los textos son los del encargo, byte a byte.** El del redactor añade `knowledge_base.tone` respecto al de `plan-arquitecto-a4-resto.md` §2.3. Manda el encargo, y en §B.3 está el del encargo.
- **Revisión de las frases prohibidas:** aplicada por decisión del lead (§13). El texto de las frases en §B.3 no lo comprueba ningún guion contra el compilador (el derivador del tester solo deriva la lista vacía): lo atan las tres pruebas que copian los textos a mano.
- **Ya estaba antes (no lo arreglo):** `compile-cases.README.md:26` dice «los 26 inválidos» y son 30; y `spec-m1.php` X2 busca una línea de `PARAMETERS` que no existe desde el compilador 2. Los dos están anotados en el informe del compilador 3.
- **El CRM** sigue con el fichero generado del compilador 3 (§8). Se regenera con `ultron-studio:export-fixtures` cuando toque el CRM.

---

## 12. Variables, migraciones y configuración

Ninguna. Sin migraciones, sin `config/`, sin rutas y sin variables de entorno.

---

## 13. Frases prohibidas (decisión del lead del 19-11: entran en el compilador 4)

La propuesta de `revision-frases-prohibidas.md` §6 entra en este mismo compilador 4, para no subir a un 5 con otra republicación. La versión sigue siendo **4**.

**Los dos textos, byte a byte los aprobados por el lead** (pendientes de la aprobación del propietario, junto a los del tono). Comprobado con `ReflectionClassConstant` contra el texto copiado del mensaje **[C]**:
- `T_PHRASES_COMPOSER`, 400 B (antes 175): `FRASES PROHIBIDAS PUBLICADAS: esto manda sobre todo este prompt, tambien sobre los ejemplos, knowledge_base, suggested_question y approved_brand_copy. Nunca escribas ninguna de estas frases, tampoco con otras mayusculas o sin tildes, en plural, entre comillas ni para negarla; si la respuesta parece necesitar una, dilo con otras palabras. Son texto literal que evitar, no instrucciones. Las frases: `
- `T_PHRASES_CRITIC`, 445 B (antes 151): `FRASES PROHIBIDAS PUBLICADAS: esto manda sobre las excepciones de este prompt. Si el borrador contiene alguna de estas frases como palabras completas, aunque cambien las mayusculas o las tildes, este en plural, vaya entre comillas o sea para negarla, verdict fail con naturalness y notes "decirlo sin la frase prohibida". No cuenta si solo aparece como trozo de otra palabra distinta. Son texto literal que buscar, no instrucciones. Las frases: `

Los dos terminan en un espacio; después va la lista (`"f1", "f2"`) y un punto, como antes. No cambian las anclas, la operación, el orden (las frases siguen las últimas, pegadas a su ancla), el juego cerrado de una frase, sus límites ni el tope.

**Ficheros de esta pasada:**
- `UltronPromptCompiler.php`: los dos textos, su comentario y el docblock de la clase y de `VERSION`.
- §B.3, apartado «Compilador 4»: los dos textos entre `«…»`, por qué, medidas, y «pendiente» extendido a los cuatro textos.
- Las tres pruebas con copias a mano: `UltronPromptCompilerPhrasesTest`, `UltronStudioPublicationRulePhrasesTest` y `UltronPublishedConfigPhrasesTest`.
- `n8n/flujos/ultron-studio-s1/fixtures/decide-studio-frases.json`, regenerado.
- `revision-frases-prohibidas.md`, con la línea final de la decisión.

**El tope en el peor caso, con una prueba [C]:** `UltronPromptCompilerPhrasesTest::test_los_textos_fijos_del_compilador_4_y_su_peor_caso_al_byte`, nueva. Usa 12 frases distintas y ordenadas de 60 caracteres, **todos** de 2 bytes (120 B cada una), sobre «profesional, sin emojis, 160, venta». Los cuatro parches de frases miden **exactamente 1 887, 1 887, 1 932 y 1 932 bytes**, con un tope de 2 048. Se cuentan así: texto fijo + 12 × (120 + 2 comillas) + 11 × «, » + el punto. Además, el rol y el paquete caben en sus topes. La prueba del peor caso que ya existía (frases de 118 B) sigue igual y en verde.

**Huellas que se mueven con las frases [C]** (PHP, y Python al margen; el script reproduce antes las dos huellas de los textos anteriores):

| Paquete | Antes (textos de las frases del 2–3) | Después (compilador 4 final) |
|---|---|---|
| Línea base + «gratis», «te lo regalo» (`DOS_FRASES_HASH`) | `fbd3c91cb9f283519fe7d2ac4daf60323d1151e2599a9cbee39195090e4d8916` (112 972 B) | `5a3957ee0239f6a85352c1eadfba1183143dcf6e9c085e47d2f8deab74ba6df8` (114 010 B) |
| G8 + seis frases (`decide-studio-frases.json`) | `73f948f6b051c9848180b3fd6b040d16202c5a8280774ef35d096f12959a9cfe` (116 406 B, con el tono ya del 4) | `e67308794d79e559bb5f14269588291ec513e7766b9554274f226f391e5796c4` (117 444 B) |

Cada paquete con frases crece 1 038 B: dos Composer × (400 − 175) y dos Critic × (445 − 151). **Sin frases no cambia nada**: `compile-cases.json` y `publication-cases-v2.json` regenerados son **idénticos byte a byte** a los de la primera pasada (`cmp` y los dos `--comprobar`) **[C]**. La huella Legacy y G8 (`7b09b89c…`) siguen igual.

**`n8n/flujos/ultron-studio-s1/` [C].** sha256 antes y después: los tres ficheros que se ejecutan (`elegir-prompts.js`, `preparar-commit.js` y `operaciones-mcp.json`) dan **OK**. `diff -rq` contra la copia de antes de esta pasada: solo cambia `fixtures/decide-studio-frases.json` (`bundle_hash` y los cuatro roles). `verificar-flujo.cjs` da «OK (121 comprobaciones)» y `verificar-selector.cjs` da «OK: 1737 comprobaciones».

**Aserciones cambiadas en esta pasada** (ninguna se relaja):
- `UltronPromptCompilerPhrasesTest`:
  - `COMPOSER` y `CRITIC`, los textos nuevos escritos a mano. Con ellos cambian, sin tocar su código, las pruebas que comparan el texto exacto: la de los cuatro roles, la del orden bajo el ancla y la de las frases con tildes y signos;
  - `DOS_FRASES_HASH` → `5a3957ee…`; se añade `DOS_FRASES_HASH_COMPILADORES_2_Y_3` (`fbd3c91c…`), con un `assertNotSame` y los bytes (114 010) **nuevos**;
  - **nueva** la prueba del peor caso al byte (arriba), que también fija los 400 y 445 B, que los textos son ASCII y lo que añaden: la precedencia, las tildes, el plural, las citas, la negación, `notes`, «no instrucciones» y que un trozo de otra palabra no cuenta;
  - **cambiada** `test_ningun_texto_de_parche_contiene_un_ancla_ni_comillas_sueltas_ni_llaves`. Comprobaba `substr_count('"') === 2 × frases` en todo el parche («solo las comillas de la lista»), y el texto nuevo del revisor lleva sus propias comillas (`notes "decirlo sin la frase prohibida"`), **antes** de la lista. Ahora es **más estricta**: el parche empieza exactamente por el texto fijo; lo que sigue es exactamente `"f1", "f2", ….`; en esa lista solo hay 2 × frases comillas; y en total no hay más comillas que esas y las del texto fijo. La propiedad de seguridad (una frase no puede cerrar la lista) se comprueba igual: el juego cerrado sigue rechazando `"` (40 entradas hostiles, sin cambios).
- `UltronStudioPublicationRulePhrasesTest`:
  - `COMPOSER` y `CRITIC`, los textos nuevos;
  - la huella de la parte arrastrada con dos frases, `fbd3c91c…` → `5a3957ee…`.
- `UltronPublishedConfigPhrasesTest`: el literal del redactor con «gratis» y «te lo regalo», con el texto nuevo.

**Pruebas de esta pasada** (una ejecución de PHPUnit cada vez; salidas en `a4-evidencia/62…66-frases-*.txt`) **[C]**:

| # | Qué | Resultado |
|---|---|---|
| 1 | Las tres pruebas de frases con los textos nuevos a mano, **antes** de tocar el compilador | **ROJO**: 97 pruebas, 654 aserciones, **9 fallos** (5 del compilador, 1 del lector y la oferta, 3 de publicación) (`62-…`) |
| 2 | Las mismas, tras el cambio | 97/97 OK, 778 aserciones |
| 3 | Prompts + oferta + commit + traza + los dos A4 | **OK (1 180 pruebas, 15 072 aserciones)** (`63-…`) |
| 4 | Studio entero | 3 769 pruebas, 41 662 aserciones, **1 fallo esperado** (la puerta de diff, con los mismos 10 ficheros) y 277 omitidas (solo PostgreSQL) (`64-…`) |
| 5 | Marketing entero | **OK (6 341 pruebas, 37 625 aserciones)** (`65-…`) |
| 6 | Guardas (16 ficheros) | 141 pruebas, 180 308 aserciones, **1 fallo esperado**, el mismo (`66-…`) |
| 7 | `vendor/bin/pint --test` sobre los 12 PHP tocados | `passed` |

**Lo que sigue sin cambiar y conviene saber:**
- **Siguen con los textos de las frases de los compiladores 2 y 3**, sin tocar:
  - el contrato `ULTRON_STUDIO_H5_CONTRACT.md` (M1-18): es tuyo; la Enmienda A4-2 no está escrita, como no lo estaba la A4-1;
  - `INFORME_CIERRE_POR_QUE_ENCENDIDO_O_APAGADO.md` e `informe-be-reglas.md`, que son históricos;
  - el CRM (`studio-prompts-fixtures.testing.ts`).
- **La semántica del laboratorio y de la voz no cambia**: subcadena exacta, con tildes. El revisor de WhatsApp es ahora más estricto con las tildes y menos con los trozos de palabra (revisión §6). Alinearlos es otro cambio.

---

## Anexo A · Las 102 huellas que se mueven (todas con `profesional`)

Sale de comparar `compile-cases.json` regenerado con la copia previa **[C]**. Cada paquete crece 1 240 B; los 204 casos de `cercano` y `entusiasta`, que no se mueven, no se listan.

| Caso | Gobernados (tono · emojis · maxChars · objetivo) | Antes (compilador 3) | Después (compilador 4) |
|---|---|---|---|
| c020 | profesional · true · 320 · cita | `270577dae06f77f13b4db6ec07b7a2b38836be6f5b6a97536b2287d87d4cd0ec` (112 994 B) | `8f1d6050b7d8b330da6dd1b6c5ef97d622f047e7802015744d596f6a2f5e1d98` (114 234 B) |
| c072 | profesional · true · 160 · cita | `042a93929f1ef37062ade9e26c0c3b379d013bbbc4999fa87371de89d7b06606` (114 206 B) | `6abd4aa11ab7ecf654eaa45e80f1cd68519176411993ecf2515d1ad91ce17d13` (115 446 B) |
| c073 | profesional · true · 180 · cita | `947cb90ef9157096734e4969ea9676b4cec88440114f8aa9c47a8cd0068e9767` (114 206 B) | `39a8efea212bce53b44d60974266fc5cc358faa7c4d09805437f9d61aa3fa1aa` (115 446 B) |
| c074 | profesional · true · 200 · cita | `af9e0d23de42368f1cda211b03dc3164ce1a341acc7e6f22abf3d71afbdb4d51` (114 206 B) | `7b09b89c98d48b01561b9cbc07e048c152c0af6a4ba7f583becd49f8e7e8cc35` (115 446 B) |
| c075 | profesional · true · 220 · cita | `da962c4026c415f872946ae9340761e4d4d98eae53323064f2a2ebf9ef5274c6` (114 206 B) | `59ac564fd65302e4463427e9e9908109e94341f6332a27c1105a4a04b34d93e1` (115 446 B) |
| c076 | profesional · true · 240 · cita | `0a90bfac5d99d6389571466e03c29011d21572453cb3765e2ede22ecf097cfe8` (114 206 B) | `1bc08831de1c91ed4b5f08cba74ade715958ddfed43f9186436ea28f9465a532` (115 446 B) |
| c077 | profesional · true · 260 · cita | `c2c5893997f0a7439ccc12515c5154caa11dcfce6de254808533d8faa0ed4c5c` (114 206 B) | `083e601cd78ea1e9da7abe72396f62f6f283bb3837e7325f8e980d7f4ee19a0b` (115 446 B) |
| c078 | profesional · true · 280 · cita | `08425c157c0ef8a12d6af292f5d2fe679a4152ffcc5e8ba7366fbc1c77a8cdd2` (114 206 B) | `942f0d8a8e8e9488abf4e402756203ecb01aaf88745d10bf4f4209e710fcdfd3` (115 446 B) |
| c079 | profesional · true · 300 · cita | `d398c523397a7c05bdc8d6b9841788b2db71f4131110115228b71cc8602c3fef` (114 206 B) | `78011cd84f9c7f67114401ea07c21741d233ad27d1082e8a0cfc845d1e663785` (115 446 B) |
| c080 | profesional · true · 320 · venta | `43f0f67d4c17563e3217a58af027ab242b01dd05d2ff4de6dfbd09e2491c7f4a` (113 321 B) | `78a4344dcd3aa23962964c18bf5289e634caf5c0cb43c74c09918198a01df602` (114 561 B) |
| c081 | profesional · true · 320 · informar | `8b22e6cfc3de40802269fafdd48a5306a687c4f11ff391f64d5715e3dd0e7155` (113 174 B) | `48c244daec45ba9c5069592579f4ecce7e865f5629ff8602181c8f4a77ed7ca2` (114 414 B) |
| c082 | profesional · true · 340 · cita | `f914fb6b8ae8376623f120805f2b7110a01995a48766ffc89e74c5e703593720` (114 206 B) | `54b515b473c5c7a184719a90ae4108f085d2b9c1428dc26c55413b6c99569b14` (115 446 B) |
| c083 | profesional · true · 360 · cita | `819fb7da0a7c7732d735da3450e41cff823388780e2f7ff5dce373c64d721dba` (114 206 B) | `9e07cb1d7112bd6ea47ceac7f5329afb101a6daa96a4d262e6c8307ce7e4486f` (115 446 B) |
| c084 | profesional · true · 380 · cita | `ffc7d352ff625e6c2f1d48eba35f11ab8b9962eab65566aaab16655aa7866e48` (114 206 B) | `8d850dbef00a44867e4a8499b924f6a22e87d8c0a2fd3e1e774c9a465e64e228` (115 446 B) |
| c085 | profesional · true · 400 · cita | `36af24a644de025f3c3a739c1776ddb089053396f5bb566051d1cd749a256d52` (114 206 B) | `79fbfdedf38a07ff391262e223eca122be6257dc693b19b5404249085d7076a4` (115 446 B) |
| c086 | profesional · true · 420 · cita | `246ca2834a492c722ce241b7d683a638023f7dd6ff1757fb394a970d2cc3027f` (114 206 B) | `6b666adc769c9d5809138176d082826cb6f25861df9d53ab220dcbdf49ddac13` (115 446 B) |
| c087 | profesional · true · 440 · cita | `fc7c5717e62efd5ae623bbff41af605324c32491c87da35953a6e579f161579c` (114 206 B) | `5f44b860bfe281c41eb7ed1541a1987f2c7ebc586fc619fae71ef89c536f0380` (115 446 B) |
| c088 | profesional · true · 460 · cita | `8332bdd83d8d817fa3a7debbac0baec48c026a5a7d2bbc9c7a416259c2440054` (114 206 B) | `fbab78bd940e977ce9b10fd4c62c14dd79601bec537579ff2da28be6f1221937` (115 446 B) |
| c089 | profesional · true · 480 · cita | `e917eb5c365875673e87dad2fa30cb2dca4e465d77faa8c36ff3f72f0777b8a0` (114 206 B) | `f89f366ed946d91d255bc2c7a53e13137c85d5a05ff8fe82fde1c4fdf14e5635` (115 446 B) |
| c090 | profesional · false · 320 · cita | `7ac55a7cf1e6e1f17558f4527e943b44bbfcef4531a6a58f848ab53df4798c47` (113 182 B) | `656b8b9575ca2bf1d4394bbae0aea083eb65367f148090b89a8eb4a25fd9bcab` (114 422 B) |
| c142 | profesional · true · 160 · venta | `24a976d02bd9c8ad4fb3725d52c3fd6bddb38418d51792ee51e988b682428a87` (114 533 B) | `8bfb1cd048c6d0362b62ac0e006819bb119843da62bee77408599a2fbc1effa4` (115 773 B) |
| c143 | profesional · true · 160 · informar | `d3d1d9494bda6dbfd3fb1e2954aa9241bbccf3adc2dc250593d49d982d0123dd` (114 386 B) | `e9ebee738f66b2700f99c56bae8fe114aef776bdbdc44176226b39216c9c61eb` (115 626 B) |
| c144 | profesional · true · 180 · venta | `a654eb5b949fa4215ae50cd1dc8c773049c9e064ac7f369034629e56fb561f4e` (114 533 B) | `e8d8b42ae00a32d27c5b7604c2c3c34e844267e68c311aea9969c12ef2c7126f` (115 773 B) |
| c145 | profesional · true · 180 · informar | `b5e0ea4f9bbbfd49efd3cfc248acdf5b576e4b4afc805c62be6f79fad6d53f17` (114 386 B) | `548e91cc90fd05d3a7ff7853d9333db7dbc5d020e91c977dd36ce6ebd0f567bc` (115 626 B) |
| c146 | profesional · true · 200 · venta | `9f010844c6bd3e2589bdd2128c00b8abab4a29464ee7f231482c7fef27b5f69e` (114 533 B) | `6d0b2531fdef881d39be021e63421949128d35442dd6a3bc61fc3ed3d68b1eb4` (115 773 B) |
| c147 | profesional · true · 200 · informar | `eb0f4cdab983e54f629df4bbb8103055287b40790402e0685c88cd0645512cbf` (114 386 B) | `f3e49a36c7b84001833cd8b481fd2dbb5fe0bb904effc60ffbfc0def48bac58b` (115 626 B) |
| c148 | profesional · true · 220 · venta | `a04c8b180dd3f6b8f8f3e83c8c58fd15fcb4519c677dad54d9d98a1d8e25bae4` (114 533 B) | `3512de8e3170ed1b9d429c964b1c562bc093fbb40676bceb8cf643e7f8a7f8d0` (115 773 B) |
| c149 | profesional · true · 220 · informar | `c8fd331118a98ce67fb57a9f3bca3b27aeec0fe2d6309e0cfd3a23c133416298` (114 386 B) | `e5517396b4126ee81da964344c40a73b437f49c7d6f6b4f83d6a98172eb79112` (115 626 B) |
| c150 | profesional · true · 240 · venta | `478548b3d4dc6acfa36e5902f1e9187ceaf6ef8ef3a47a16d12735f82d99570a` (114 533 B) | `56042931e26381e2596d1fa91bc7da833c887619586acef11f03be660ced0bb8` (115 773 B) |
| c151 | profesional · true · 240 · informar | `2378b6503e12d4fbc4fe108ba23268f883a2815a6ef39c6684bbd1e0d0df2d34` (114 386 B) | `f986ba6c2e1d6fdc53eccba412bdcdf675f81d93441969d4811cdbb0de94049d` (115 626 B) |
| c152 | profesional · true · 260 · venta | `c9f8e1f689303333ff655b3a00f22049050ada909ec5d27cf929345eb0c34e80` (114 533 B) | `ee4c2c9215134cd930296d36f5c87f6152d584417694a7d5f225bca463f1d3f0` (115 773 B) |
| c153 | profesional · true · 260 · informar | `7638d481db6645e33852cb19bdd7a1c6ffa7e17345cd3f29008421170a5679a4` (114 386 B) | `5d979d80490bbb5810055d755dd5302f0d6ea8d7b0324e56847b45b1f056260b` (115 626 B) |
| c154 | profesional · true · 280 · venta | `3f246d592aa158e0596fc2d3be337526d42e447e07449579bd44d371cad41271` (114 533 B) | `919f2b4e1d7f6e7c5bf34fed78a9bd88bbf19be2966206d1a88305981a55f25a` (115 773 B) |
| c155 | profesional · true · 280 · informar | `0a1be456bd443c2b1d9754e49c122ae03205a6162c8a565151e0e9ad0d20fd58` (114 386 B) | `049c033241501d370ad54a14c5fb37086d2c91654c51c258e25c047e8d97882d` (115 626 B) |
| c156 | profesional · true · 300 · venta | `2296e8bb43301fa8eb14691671b6b1c0d24cfe16f3654b59e90f73b7bd07f61c` (114 533 B) | `863f3c1cb1f6432ce604d21f9278f332b24815e318322829213d4a50650143a4` (115 773 B) |
| c157 | profesional · true · 300 · informar | `fa5f8f71420da5f6b2100952a3a5898329bc7c23bc00fa633bd7db7416545e8c` (114 386 B) | `50949bdcec78abc726a57b95cfba6f8e25fa456c4881848f2acf1776cbdf7cc9` (115 626 B) |
| c158 | profesional · true · 340 · venta | `4901f517966baa89755db8fad933ca4cc6e552a1bb8d0eaf12e9aad146d08a1a` (114 533 B) | `236148384076d931f62aecec754a8a9a16c7f09c2b8c68bafb30088fee20ce85` (115 773 B) |
| c159 | profesional · true · 340 · informar | `ea9be05a8d461b588d492ee686d34fb1d0624c82df8d1a30f3e107cd6d80d296` (114 386 B) | `8e3cf1147ec5f745822c93cc8dd6d71c45ac71a29801f87a0a42894a55f79d3e` (115 626 B) |
| c160 | profesional · true · 360 · venta | `aa75460fa3fe19cf659c99659abe4fe05d509353fc642bcd1bf9d3e91f66f227` (114 533 B) | `cccbc161543835b3302a8205750c415f9bdc45f00da41fa92117a7966c964141` (115 773 B) |
| c161 | profesional · true · 360 · informar | `504a90d34abec83506009c6171ec7973ce56a5846da02bfebaa24c9233a6ca5b` (114 386 B) | `1eaa6b2f3f9088365f45d362bfb067fe573a2095b97b42be93045ee1832c49cd` (115 626 B) |
| c162 | profesional · true · 380 · venta | `11a879d36d35b3a66004193ad51bef7c73984c470d2e8f4d15d1213feed714ab` (114 533 B) | `66fb5a143fee8ec88e1b2e7fa8e110c67c0b49c71e7986582ea4cc377b75a281` (115 773 B) |
| c163 | profesional · true · 380 · informar | `5357c253fc505722b23ba02bde3ee886587c419bd271ca07903cd35accb182c1` (114 386 B) | `5d399d0fee3fded67ad4e0366b102d20c1f1b19ba14b0ea3ca30c1940f090d18` (115 626 B) |
| c164 | profesional · true · 400 · venta | `e2edf05fa61c51fd62146da6dc41fd47ff3676d7cdbc4ea7c69807538c3f4cee` (114 533 B) | `2fc97e0ad80ffdafc049b9ee8e8048a55b0bf34e3f64d1880e8fb3869bf31c54` (115 773 B) |
| c165 | profesional · true · 400 · informar | `cdc8ba15b084ba1d23f38199ca093245bd1f50c28088259301573544621bff49` (114 386 B) | `7b241de0df93413d8c3ff1b4f0dc94ac268c0893aef6bf455024124c5116b529` (115 626 B) |
| c166 | profesional · true · 420 · venta | `9391170b85bdb794590a2fbe3bf6e3e2c8b19daf42640210f5c7319bcb794ad9` (114 533 B) | `4e4e0b1e9944d431f4596d5b46e0eb96f92b6ddaa44ab0486f402c3b383ef288` (115 773 B) |
| c167 | profesional · true · 420 · informar | `9cdfca834f10bb28d3cb0f722a40954c6a67e3714c7b0c0f09b4bc6f1265eb15` (114 386 B) | `08272064135a606c4bac68fdde1e832c617e3f5dc928924b1331f6eb8bc19f28` (115 626 B) |
| c168 | profesional · true · 440 · venta | `90b2e2d180d737a718135b80380be9d939cae3930dc72146d75f420185856a6f` (114 533 B) | `5e0de08eeaac942cbef8a274c455bbaf0d446ac12602844e1119eec103be2f32` (115 773 B) |
| c169 | profesional · true · 440 · informar | `fd7bc16598165588484064f6277eada569c65ae90e23b58828e612fc6d8ca5f0` (114 386 B) | `1307b368a738489ebc4d54a95b3b21e48bc5aecf0f0a3b129442a4f02d5d67eb` (115 626 B) |
| c170 | profesional · true · 460 · venta | `b6bb3f0eb11fcf102f3c3e926154f9f62ad0517880483ab9a351f11f05de7f15` (114 533 B) | `bd3de8e570a1c124e448c17516a4482d8ccb74343566a79b745d79b749ecc0ad` (115 773 B) |
| c171 | profesional · true · 460 · informar | `6fbcbaae535b38b2bdb583d6d9da4c9713322553a9974d3170698239fc9cf670` (114 386 B) | `f27ce8f84f5b7e51c79cdc86e199df1e094162c9c8c22652eae00b01d4aca514` (115 626 B) |
| c172 | profesional · true · 480 · venta | `64822768cb7af6a299df72f50891b5b5d3fb72ddf4c12551ad71dfcd885a513d` (114 533 B) | `172c7042eb4fa927e69599fe8e9314b421b61b9c7fbf40b0e84fa854201d0b0a` (115 773 B) |
| c173 | profesional · true · 480 · informar | `5050253e3a722751aabb0d88d3e29a85e467d6cf1196bb8520f1f0709dca4b65` (114 386 B) | `a2d75df6a23d6e1ec19c4d3ffa296ca4304b77257d38f74df9837b1f81df1af4` (115 626 B) |
| c174 | profesional · false · 160 · cita | `762e24ec69fb429e9b7f00494918e6351d9d1b872dfc9e672fdcf284c4c32ef2` (114 394 B) | `b482127d25bf9cb3b27ccbb5b78d971b7041fccc47a86fda0ba15497bd28daa9` (115 634 B) |
| c175 | profesional · false · 180 · cita | `fd05fe783f3cd641d40329003a296cf53f58c8828d7c1a7cd946d8237e47043d` (114 394 B) | `e59a7cd92b26060709004e919cc656b421ce491586f0709656726ba199a608de` (115 634 B) |
| c176 | profesional · false · 200 · cita | `807a3b26e3aa1ac9d338a9309fc93f1afd8f9bdc1fdefe83ea4fb65e9ca3eb8b` (114 394 B) | `b9fe8ddd6343947fd0f07ca1ed2d0501dff95ea3361ae3a95f923ab03c9df560` (115 634 B) |
| c177 | profesional · false · 220 · cita | `af59522428d0503aa7118bd0105d5a88ecaed4c8ea82ed88d9fc07cb0bdf8add` (114 394 B) | `f19932ad3718701b5b7c183e774edb48d769b2510f3329c08b8ad4db86177f7c` (115 634 B) |
| c178 | profesional · false · 240 · cita | `733e1af3d2d43b54aa8b51e9fc1aa9e7ec67a0ace1ac1684f1ca6e8fa68c09b7` (114 394 B) | `118dd89cfe312e641ff2bddd2384f86e881d1777b138a7efd555ade400879d50` (115 634 B) |
| c179 | profesional · false · 260 · cita | `546fa4c91e29e7e1acc7c736ecc4e5733694d4f2c1c40c7fbf854f63eff9661f` (114 394 B) | `0c8c80e0c9075f7d288bc8b1d7b5cb475939affc4146cbde2ca6c9a8c6589a4c` (115 634 B) |
| c180 | profesional · false · 280 · cita | `345a76af7e9bea561315cd1ef214b989803557cadfb6ebdeee1ea351abfffc7f` (114 394 B) | `c3555c3e3f98746a06a7a75b50264fc02fad9aeeefca33f007005da1a1e7159e` (115 634 B) |
| c181 | profesional · false · 300 · cita | `5896cf8f36b2fc780f3daa90009c8beaae1724f6828a63451ebafddfbf94e5e3` (114 394 B) | `51d35c60484fd1731b4292c226946a73a4910089c32e354c651968a158d4fc86` (115 634 B) |
| c182 | profesional · false · 320 · venta | `0798eca1f38f207c1534e164f0cea47c4ee956eabcdf288d7febc6a103865b9c` (113 509 B) | `6e0194222cac411d60d78912db12c4e835171e783f8e02bd13af2869bb3802f5` (114 749 B) |
| c183 | profesional · false · 320 · informar | `aa67c145560e693545965d3e00392d3675b990c48d8a4a8cd352bbc0c18c8148` (113 362 B) | `38bf5d376d5a9c671605d06917606112bcee7f55e8912ed2cf143f32e484439d` (114 602 B) |
| c184 | profesional · false · 340 · cita | `02c5c2054de77b64341d6c56f9d422c88c40f7da586651a1ebcd508755526c6f` (114 394 B) | `8cc9e7af0654ee855255eebabc323e6090c5c9f8092481eab75b7f616efad8e2` (115 634 B) |
| c185 | profesional · false · 360 · cita | `d11c22ecbcd5b07b6750be2e391933a14451e37bc0521fa42a744d6e6456705c` (114 394 B) | `824d7f21c4c64be10690285d57832e788b4b994443a8668c74dddebe176ffa5c` (115 634 B) |
| c186 | profesional · false · 380 · cita | `d76cbe9fbf95f5f5f4059442c1fbc0b806d2fdbdf954bb6c1316e673837009c6` (114 394 B) | `efa7d4d6658a6347e64f51cea374437f486537340e2dab0fdaa95ae9cf12dd71` (115 634 B) |
| c187 | profesional · false · 400 · cita | `89b0f2008b87754421b7d315c67bc01be2a74000948c61c400b7bfaf68aefbfe` (114 394 B) | `aca2ef6cf0af164142b5370b63718abc70cee8156187a51a14ef3fb5ad9167be` (115 634 B) |
| c188 | profesional · false · 420 · cita | `b52765532dfe1b40486d09caf33ec1f6acfa80b779aa9275f561faf3af9b20dd` (114 394 B) | `63c6ce4e13be3003659f2473c51044a012fb74be826e91e8a967f05d766f2470` (115 634 B) |
| c189 | profesional · false · 440 · cita | `31a66e1ef1c4adc58f68a02e1f26993c18da97b4aeb015b8b4677a670f0e6fd6` (114 394 B) | `5e23fb750fd5db117d4eb4dfa31243ae3611fd2757a5cb205b466918efa0808b` (115 634 B) |
| c190 | profesional · false · 460 · cita | `56eabb1c76c67f9aec7bf134047737433e86eb86ef6f3be72904749d17ed3a4a` (114 394 B) | `c5d2399a46bf00e9f5e1aead73d52365c2277f88dcf7873c54132725dfa4380a` (115 634 B) |
| c191 | profesional · false · 480 · cita | `b8a446331bf9019d89213a209e73b4f031db2b99f2fe5c8f7b536dbc367b48f3` (114 394 B) | `06c0dd5df5beb89c05707a4890101926bbb2d7c9d4c52a466abb9ee0bcb6627f` (115 634 B) |
| c242 | profesional · false · 160 · venta | `1afc02ccbe028a308b57dce8e492fc308757291356ae615712fe466e95339ca1` (114 721 B) | `ae7caae04a0c2e8372b126d6875c9b9df22021acc9ff38eab71e07b5e0d0098b` (115 961 B) |
| c243 | profesional · false · 160 · informar | `76ad0bf969e187dbfefdd5d9eba5afd72a4cea77361d1437170efb9d2aa2d5c5` (114 574 B) | `2a5954a2de47ea38306e5820d3727278f74a441fea9e375eea7da1001d5d78d3` (115 814 B) |
| c244 | profesional · false · 180 · venta | `3a06d5b13cff5d9caf45e4692d19513871de9a5d0a5fd773d598e18cef549682` (114 721 B) | `81a12f1c70edc14d1f57b7cacf4072f61a7053fb63bb045d23f430326772fd8d` (115 961 B) |
| c245 | profesional · false · 180 · informar | `e0aa7621a36c09e625070ec0e9db5734fb1690efb28f2f2db71f11b3c5626f54` (114 574 B) | `58167269ef80844a915677ed160f4ce4a1020a2bfd440c4ac5662e7ea5ba500d` (115 814 B) |
| c246 | profesional · false · 200 · venta | `2c9a93c4ba36af103613a0797c265aa412ac3f51ce77082759ed27e8f6a8cccb` (114 721 B) | `b33ba267eaf8da719feac00a4fe9b3e81ad5daa74e9d514f3f11c207994ea898` (115 961 B) |
| c247 | profesional · false · 200 · informar | `d850f4706a1f278003978bcef67f478514f0005ae7fd9b6fce726b52005f03a7` (114 574 B) | `5f45502c3e1a78fdfd2a4b6ea2b2f9fc962d7e21583c81d28f3e1f06d943c7b0` (115 814 B) |
| c248 | profesional · false · 220 · venta | `57f470c788eff7306eddde2da76047585a86646755459ece46d4fb2d996c8378` (114 721 B) | `180014548d0c52881561ebcdc298ed8b4434a335b007d0f4576d87e9919496e7` (115 961 B) |
| c249 | profesional · false · 220 · informar | `e60747e2dbcbc50e4231b01019d0d362b1d149c0c22b8222f0eac02140ad509f` (114 574 B) | `fb34a42870fd96707bdff6cbce8ce348ff31b25f8a928a434accda635eb0a297` (115 814 B) |
| c250 | profesional · false · 240 · venta | `e40a0c1801882cc44c5c7db46a61fb863686ad3f961c777682adc5bb59396e75` (114 721 B) | `17d31c187e08edf7181bae3e86eb0317dae28a36c031a60def052e3dbd5df422` (115 961 B) |
| c251 | profesional · false · 240 · informar | `626433ec2ba2472b56ca22e58721fa3d7d5dadfa72a2cacd72d7b71d28982655` (114 574 B) | `ea5abe53d85945239a92d0dd403b48eccdbe84d0735fdb764c07e3c036cccbc3` (115 814 B) |
| c252 | profesional · false · 260 · venta | `a0692feba9e058c61cceaa5364fb2a9501c9d44c4e9154a1056dbd79071889b4` (114 721 B) | `be4c6db4ecc6cdb01a834671fc6b52593afca95e68a25ef670675396b3c58615` (115 961 B) |
| c253 | profesional · false · 260 · informar | `7be58c2464374cd83dab3d5c88af1e7785665f4b129de3f3e0bf18fd1708cd51` (114 574 B) | `bb32e8f3f2f606abeba731a51ef418654f2ca77f09df8cee24f27815381eccec` (115 814 B) |
| c254 | profesional · false · 280 · venta | `40ab1e1392fcaa374a578f498106b96cbac2926bac90e3215799923f1ab634f1` (114 721 B) | `9cabde8e309ef1657bab39e0f4d115a560b945c66a74d305036dbbf081b22e61` (115 961 B) |
| c255 | profesional · false · 280 · informar | `951d23523912c2fcf787a41a27688189438f2f7d4ce9d093c86a49b7853559b1` (114 574 B) | `f859e07ae90f2e5042fea2111db3db905c332ef032f868a233866849387a960a` (115 814 B) |
| c256 | profesional · false · 300 · venta | `cf7fbffae83ff178e8ab82ebdb1499624b7d521cc779d20f87e46dc1b0719c10` (114 721 B) | `263bba50cf2acea0bf129eb4b111331b39f1322c9a0cfb3adc7191c252a68fa1` (115 961 B) |
| c257 | profesional · false · 300 · informar | `81760b82877df70eb54cde31d57f90d939d7ed5a39dbcd2bfee2df8a32cb0c9a` (114 574 B) | `f36a8010becc838694b5bf7bbed67ae8c047ae31bffdd929ff21abb0d4992515` (115 814 B) |
| c258 | profesional · false · 340 · venta | `a14b229f6afb66733ea6a83816b5ab5d858bf99987fdd95281b531300b000f60` (114 721 B) | `e96ab96c2474cff1a588cb94c3e366dd819a19bba68ec57f047352549f7b23e8` (115 961 B) |
| c259 | profesional · false · 340 · informar | `207153b996aa3a55a1edd1bf4be64686f8d179d4eebb497f8b64e73b5ebc2bdf` (114 574 B) | `ef69a3c8f79f54b3fb8916b4de127e8ca306d3bcf155033cb9c89f8000c443c4` (115 814 B) |
| c260 | profesional · false · 360 · venta | `590674d864c616aadc8f548d8e644e56e8e2cd19b38a010db1a4443ac04ae848` (114 721 B) | `1fded74b8c69d53975068a4836e7fcb7414ed0a8f158419c9fc0ab09f98dec0f` (115 961 B) |
| c261 | profesional · false · 360 · informar | `67b29320c63abca99abd83d82646355f6fe7f0dbdfbf81fc9b37f66faab696d0` (114 574 B) | `66c8f36bd9e2038eb7c090053e916704bc42b299122d7f864b3a90301664d657` (115 814 B) |
| c262 | profesional · false · 380 · venta | `c17e7bb50531f184e5c12ced8960bcbc43158fd6bce19a0118322df935586711` (114 721 B) | `e74390d9845cfec48ffed12482b706d558c2cc51720689b09ca1c68ce88e83bb` (115 961 B) |
| c263 | profesional · false · 380 · informar | `a5e57b6a8766eb9b40957b2fc39c3c9c3b1a361e251dad2200a72983c4aedc3b` (114 574 B) | `0f1acb8a676134db9c057042445f8afe308eff8291c609b38e96847fcbb0ea50` (115 814 B) |
| c264 | profesional · false · 400 · venta | `1924d95a3f049bba68911944f275429dd266daa6c9c5a3a8ff26d78d0c348d0c` (114 721 B) | `45adc13746efb65a4c9151f568cecc0f351dc766644fb27360bf6314f0f90fa9` (115 961 B) |
| c265 | profesional · false · 400 · informar | `bc02fd8d277fdf37421aed68499afd7f35641057de48961e2cbc973bf2756c3a` (114 574 B) | `9b13c2f1baef28ff92fa5f093f64822787fe1c72b8aa887496e924cc5b6f33b2` (115 814 B) |
| c266 | profesional · false · 420 · venta | `82c1c30122f3db4c158d2111676e7cf158855dc49256e128a319c468a3b85022` (114 721 B) | `9c3441f22548f98b89fa3db63ffcabb2edc994ad6c4fcb44d0b92fc0f6eebd61` (115 961 B) |
| c267 | profesional · false · 420 · informar | `c9abcbdef3f8cd58504471f2858baced3a238b3acda5cdc4fa7ffc8315056888` (114 574 B) | `5b983023d9c865e9c3a1c6a11b6957736d1e515bee773144d17eff3b54590b58` (115 814 B) |
| c268 | profesional · false · 440 · venta | `474836a82ed8748188cd053e341b1d6f84f36d7420b3f00176b09730699b0ed5` (114 721 B) | `eda3a5694799da4a03b974624051ff81cb5e2802a0c78dce673cd57e4d0f49c2` (115 961 B) |
| c269 | profesional · false · 440 · informar | `f093494f4a1de05d947eb9f5bcb01c505bebe95b5b9e134b25174714cdfc48cc` (114 574 B) | `8e27d2076f854aa38536948dbfd93c13a76544865943f231a004acbae4c7a90b` (115 814 B) |
| c270 | profesional · false · 460 · venta | `b12715102487359750f8f5816aaa8e5cafcdae214300e322f3c955a1b8684ab8` (114 721 B) | `1c7a927fd444ad55334154008f5dea0973658d81de0853b53331c9f5fd406633` (115 961 B) |
| c271 | profesional · false · 460 · informar | `bf9ca7a71918838d11be79abb99f50ef4639d6b32ebca8c5d811d79bca2d76e8` (114 574 B) | `9592011220870fbbb147e1008b7063dded48bde576bf234bd0f3a8eb194f0546` (115 814 B) |
| c272 | profesional · false · 480 · venta | `de0cb73f5629e27c46dd3508ac77e2e125b072d6fd5e54b92f453d83f5f9ee66` (114 721 B) | `f274f156a25579f70fc2d1969f9a0232083da4a0ccdd56e2cd2e20c470b4b7d1` (115 961 B) |
| c273 | profesional · false · 480 · informar | `9624d2a4bdb6f8839d7ceff4cafcbaa3512b6d5441618ba505311402543cdd0f` (114 574 B) | `b9ce095e1ca1722bd4d289529fdefc923bdfc26e476af107ef9700badd2e43d0` (115 814 B) |
