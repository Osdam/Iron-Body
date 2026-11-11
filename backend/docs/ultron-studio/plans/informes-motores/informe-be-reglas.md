# Informe backend · Reglas S-NNN con efecto en WhatsApp (opción A de la auditoría de cierre §3.2)

**Fecha:** 11-11-2026. **Agente:** `backend`. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9` (empecé sobre `e48be7ea`; durante el trabajo el lead subió `2d81221b` y `72f7980f`, sin solape con mis ficheros, comprobado con `git diff --name-only e48be7ea 72f7980f`). **Sin commit, sin push, sin tocar producción, n8n, el CRM ni los documentos de contrato.**

## Resumen para el lead

1. Hecho: las frases `forbid text contains` de las S-NNN vivas cuyo «si» se cumple (la semántica exacta de la voz, extraída a `StudioRulePhrases`) entran en Composer ×2 tras `FORMA:` y en Critic ×2 tras `naturalness:` con texto fijo; compilador `VERSION` 1 → 2, quinta clave `rules.forbiddenPhrases` en `governed`.
2. Legacy idéntico: lista vacía = ningún parche; `LEGACY_BUNDLE_HASH` `3f6a5674…c902` y «profesional + 200» `702890f7…311c` NO cambian (la huella es la de los cinco textos; el compilador va firmado aparte en `prompts.compiler`). Los 306 casos del fixture del tester dan los mismos bytes.
3. Juego cerrado (letras con tilde, ñ, ü, cifras, espacio, `, . ¿ ? ¡ ! -`), 2–60 caracteres, ≤ 12, ordenadas y sin repetir: nada se recorta; si no cabe, la vista previa bloquea con `prompts_rule_phrases_invalid` + `prompts.phrases.problems` y publicar es 409 con el mismo código. 40 entradas hostiles probadas.
4. Lector del motor estricto: con el compilador ≥ 2, cinco claves y la quinta una lista de textos; con el 1, las cuatro de siempre; otra forma = `malformed` → Legacy, nunca lanza.
5. Clasificación: con la llave de prompts, las S-NNN que aportan frases pasan a `prompt` (no se reconocen); las demás siguen en `noEffect`. Sin la llave, idéntico a hoy (fixture C-1 intacto).
6. Voz intacta: `specHash` de una versión con 7 reglas = el de antes de extraer (`53003869…7006`, fijado con el código sin tocar); 285 pruebas de la voz verdes.
7. Requisito 6 confirmado con pruebas: lo publicado con el compilador 1 sigue `applied` en el sello del commit pero `decide` da `compiler_changed` (Legacy) hasta revertirlo y publicar OTRA versión (ROLLED_BACK es terminal). Desplegar antes de E4 o con V2 revertida.
8. n8n: el flujo NO cambia y no hay que publicar nada en n8n (detalle abajo, «Pregunta del lead sobre n8n»). En el repositorio solo cambian metadatos generados (`compiler: 2`) y un fixture nuevo con frases. Comprobador: OK 121 (antes 111).
9. Pruebas: 108 nuevas (59 compilador, 22 lector/oferta, 21 Studio, 6 HTTP); SQLite: publicación 660, motor/prompts 1167, voz 703, todo verde; PostgreSQL: 477 verdes, 0 omitidas. 6 mutaciones, las 6 muertas.
10. Guardas: 137/138; la roja es SOLO la puerta de diff del H9 (`OutreachIsolationTest`) frente a `origin/main`, con exactamente mis 4 ficheros del motor: es la misma situación que M1-15 y necesita tu autorización explícita para esta enmienda; no la relajé.
11. Antes de desplegar hay que cambiar a la vez `smoke-h9.py:635-636` (exige `compiler == 1`) y añadir mis 13 ficheros a la lista cerrada de un script de despliegue; el CRM de hoy rechaza (falla cerrado) la vista previa de una versión CON frases hasta que aprenda el cambio nuevo (§4 y §11).
12. No verificado: el efecto real en el modelo (la obediencia es probabilística: Critic, no guarda de Laravel), la pantalla del CRM, n8n en producción, la revisión de `security`/`api-security-specialist` (pendiente, hay texto del personal en prompts de producción).

### Pregunta del lead sobre n8n (11-11, tarde)

**(1) Qué cambia en el flujo generado y por qué.** Comparado con la copia previa a regenerar (`scratchpad/s1-antes`): `nodes` y `connections` de `flujo-s1.json` **idénticos**; la única clave distinta es `_generado.compiler` 1 → 2, un metadato de primer nivel que el propio fichero marca «Solo para el comprobador. NUNCA se importa en n8n». En `huellas.json` solo cambia `compiler`; `selector.jsCodeSha256` sigue siendo `456e4d495115cf4b126277773b6f4998530a78635f850eac6b8b7ec4e7350d0f`. `elegir-prompts.js`, `preparar-commit.js` y `operaciones-mcp.json`: idénticos (`cmp`). El selector **no fija la versión del compilador ni las anclas**: la palabra `compiler` no aparece en `elegir-prompts.js` (0 apariciones) ni en «Preparar Commit»; solo comprueba el contrato `ultron-prompts/1`, el modo, los cinco roles, su tamaño (≤ 40 960 caracteres) y que el SHA-256 de los roles sea `bundle_hash`. **No es imprescindible para n8n; lo es para el repositorio:** el generador escribe `UltronPromptCompiler::VERSION` en esos metadatos y `UltronPromptsN8nCommandTest::test_lo_que_hay_en_el_repositorio_es_lo_que_genera_hoy` exige que el repositorio sea lo que genera el código. El cambio en `UltronPromptsN8nFlow.php`/`UltronPromptsN8n.php` solo añade el fixture con frases que pediste para el comprobador.

**(2) Producción con la copia publicada (`QSklqHlsDIxucRfk`@`01fecf7a`) y el backend nuevo: no se rompe nada.** El selector de esa copia es el que comprobó `importar-s1.sh` en la base de n8n (sha `456e4d49…`, L9-107 y `n8n-copia-s1/LEEME.md`), el mismo que el comprobador acaba de ejecutar con los paquetes del compilador 2. Un turno canario con una oferta del compilador 2: el selector recalcula la huella de los cinco roles; si cuadra (siempre, salvo alteración en tránsito) usa los roles → `studio`, «Preparar Commit» manda la huella observada = la ofrecida → sello **`applied`**. No hay `engine_fallback` ni turno mudo. Sin frases, los roles del compilador 2 son byte a byte los del 1. Con frases, el rol más grande en el peor caso (12 × 60 caracteres) queda muy por debajo del tope de 40 960. Si algo no cuadrara, el selector cae a sus constantes Legacy (`hash_mismatch`), como hoy. **[No verificado contra producción: no toqué n8n.]**

**(3) No hay que publicar ninguna revisión nueva del flujo.** Orden: solo backend (con el CRM antes, si va a haber reglas con frases; y `smoke-h9.py:635-636` cambiado en el mismo despliegue). Sonda sin efectos, antes o después del backend: exportar de la base de n8n la versión ACTIVA de `QSklqHlsDIxucRfk` (`workflow_history`, `01fecf7a`; solo lectura, como en E1) y pasar `node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs <export>` con este repositorio. Con OK (121), el selector publicado acepta los paquetes del compilador 2, también con frases. **No uses `importar-s1.sh --solo-comprobar` como sonda: sale en su línea 25, antes de mirar el selector (línea 58).** Tras desplegar, `php artisan ultron:prompt-trace --last=5` → `compiler 2` y la oferta de ahora. El primer turno canario debe salir `legacy · no_publication` (o `compiler_changed` si algo quedó publicado con el 1), con la huella observada Legacy y `flowRev S1`. Vuelta atrás: solo el backend (primero «Volver a Legacy» en el Studio, después el código, §7); n8n no se toca.

**¿Se puede evitar tocar esos ficheros con un diseño igual de seguro?** Sí, en parte: el fixture con frases puede vivir en `backend/tests/fixtures/ultron-prompts/` y escribirlo una prueba de PHP en vez del generador. Así `UltronPromptsN8nFlow.php` y `UltronPromptsN8n.php` vuelven a como estaban y la puerta de diff del H9 baja de 4 a 3 ficheros. Lo que no se puede evitar sin tocar el generador es `compiler: 2` en los metadatos generados de `n8n/flujos/…`: son documentación del repositorio, no el flujo. No lo cambio sin tu visto bueno.

---

## 1. Diseño final y por qué

- **Motor (`UltronPromptCompiler`, versión 2).** Un quinto parámetro, `rules.forbiddenPhrases`, el ÚLTIMO en el orden de aplicación. Con la lista vacía `patchesOf()` no devuelve nada, así que todo lo que compilaba la versión 1 da los mismos bytes (propiedad central conservada). Con frases, un parche `insert_after_line` por rol: Composer y Composer Reintento tras la línea de `FORMA:`, Critic y Critic 2 tras la de `naturalness:` (anclas ya únicas y vigiladas). La lista va al final del texto fijo, cada frase entre comillas dobles y separada por `, `, construida por **concatenación** (nunca `str_replace` sobre texto del personal).
- **Validación en el compilador, estricta y sin arreglar nada:** `validate()` exige las cinco claves; la quinta, una lista de textos (si no, `invalid_governed`); su contenido (`phrasesViolation()`): ≤ 12; cada frase 2–60 **caracteres** (no bytes), juego cerrado `PHRASE_PATTERN`, sin espacios al borde ni dobles (`spacing`), sin contener un ancla (`anchor`, para conservar la invariante «ningún texto de parche contiene un ancla»), y ordenada por bytes sin repetidos (`order`). Cualquier fallo → `UltronPromptException::INVALID_PHRASES`. El mensaje de la excepción nunca repite la frase.
- **Tope del parche de frases propio (2 048 B):** 12 frases de 60 caracteres de 2 bytes con comillas y texto fijo ocupan 1 638 B; subir el tope general de 1 200 habría cambiado el contrato congelado de §B.3 (`limits` del fixture del tester). Los demás parches siguen con 1 200.
- **Lector (`UltronPublishedConfig`)**: la forma de `governed` va ligada a `prompts.compiler` (`>= 2` → cinco claves; si no, cuatro). Elegí `>= 2` y no `=== 2` para que un compilador futuro que conserve la forma se lea y caiga a `compiler_changed` (sello `applied`) en vez de `malformed`. Nunca lanza (el camino de siempre).
- **Studio:** `StudioRulePhrases` (pura) es `forbiddenPhrases()` de la voz tal cual: qué reglas (S-NNN, vivas, con `forbid text contains`, «si» cierto preguntado al laboratorio con la sonda) y qué frases (canónicas). **No valida ni descarta nada**: cada motor valida con su regla (la voz con `rule_phrase_invalid`, WhatsApp con el juego cerrado). `StudioPromptBundle::governedFrom()` recibe el contenido entero; `StudioPromptBundle::phrases()` da lista, reglas que aportan, tope y problemas regla a regla. `StudioPublicationDeclaration` gana `GOVERNED_PHRASES` y `PHRASES_SINCE_COMPILER` (atados por reflexión al lector del motor) y `classify(..., $promptRules)`.
- **Por qué no B ni C** (auditoría §3.2): B toca el camino caliente del commit; C mete texto libre. A no toca n8n, ni migración, ni `StudioCatalog::VERSION` (sigue `2026-10-19.1`, sin romper la congelación de la voz y del H9).

## 2. Ficheros y líneas

Motor:
- `app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php`: `VERSION = 2` (:84); `PHRASES` (:102); `PARAMETERS`/`BASELINE` con la quinta clave (:105-117); topes, juego cerrado y códigos (:131-160); `MAX_PHRASES_PATCH_BYTES` (:168); textos fijos (:217-221); `validate()` (:316); `phraseViolation()` (:370); `phrasesViolation()` (:404); el caso `PHRASES` de `patchesOf()` (:510); tope por parámetro en `patches()`.
- `app/Services/Marketing/Ultron/Prompts/UltronPromptException.php`: `INVALID_PHRASES` (:35).
- `app/Services/Marketing/Ultron/UltronPublishedConfig.php`: `GOVERNED_PHRASES`, `PHRASES_SINCE_COMPILER` (:154-156); `parteValida()` valida `prompts` primero y llama a `gobernadoValido()` (:614).
- `app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php`: **sin cambios** (lo firmado con el 1 cae solo por su paso 5).
- `app/Services/Marketing/Ultron/Prompts/UltronPromptsN8nFlow.php`: `FIXTURE_PHRASES` (:271) y el fixture `studioPhrases` (:313). `app/Console/Commands/Marketing/UltronPromptsN8n.php`: escribe `fixtures/decide-studio-frases.json` (:64) y lo nombra en el LEEME (:138).

Studio:
- `app/Services/UltronStudio/StudioRulePhrases.php` (**nuevo**, 126 líneas): `byRule()`, `sorted()`, `codes()`, `WHEN_PROBE`.
- `app/Services/UltronStudio/StudioVoiceCompiler.php`: `forbiddenPhrases()` (:771) llama a la clase nueva; quitada su `WHEN_PROBE` privada.
- `app/Services/UltronStudio/StudioPromptBundle.php`: `CODE_PHRASES_INVALID` (:56); `parameters()` = los cuatro del catálogo (:114); `phrasesParameter()`, `maxPhrases()` (:120-130); `governedFrom(array $content, ?array $phrases)` (:155); `phrases()` (:189).
- `app/Services/UltronStudio/StudioPublicationDeclaration.php`: `GOVERNED_PHRASES`, `PHRASES_SINCE_COMPILER` (:106-108); `classify(..., array $promptRules = [])` con las reglas a `prompt` (:289); `assertPrompted()` ligado al compilador (:1072) y `assertTypedObject()` (:1096).
- `app/Services/UltronStudio/StudioPublications.php`: `ENGINE_MESSAGES_PROMPTS` (:137); bloqueo con el código del error (:977); `phrases` en `promptsDto()` (:1037); puerta del 409 (:1111); en `facts()`, las frases una vez y las reglas a `classify` (:1244, :1255); `promptFacts()` (:1549); arrastrada compilada desde el contenido (:1627); defensa en `promptedForPublish()` (:1654).
- `app/Services/UltronStudio/StudioPublicationExport.php`: `governedFrom(['configs' => …])` (:129-130).
- `app/Exceptions/UltronStudioException.php`: `promptsRulePhrasesInvalid()` (:422).
- `app/Support/Access/PermissionCatalog.php:240`: `ultron.manage_rules` decía «el motor no las aplica», falso con la llave encendida. **No arrastra fixtures de paridad** (grep en backend y CRM: el texto solo vive en ese fichero; ninguna prueba lo fija). Los textos de `ultron.view` y `ultron.publish` quedan incompletos (no nombran las frases) pero no falsos; `ultron.publish` lo fija `UltronStudioPermissionsTest:314`, por eso no lo toqué.

Comprobador y fixtures:
- `docs/ultron-studio/ops/motores/verificar-flujo.cjs`: caso nuevo en F (fixture con frases → `studio` con la huella PHP; línea pegada a su ancla en los cuatro roles; resto de bytes = «profesional + 200»; una frase alterada en tránsito → `hash_mismatch`).
- `../n8n/flujos/ultron-studio-s1/` (**fuera de `backend/`, generado por `php artisan ultron:prompts-n8n`**): `compiler: 1 → 2` en `flujo-s1.json` (`_generado`), `huellas.json`, `LEEME.md`, `fixtures/decide-studio.json`, `fixtures/decide-legacy.json`; nuevo `fixtures/decide-studio-frases.json`. `elegir-prompts.js`, `preparar-commit.js` y `operaciones-mcp.json`: **idénticos** (cmp).
- Herramientas del tester (`tests/fixtures/ultron-prompts/tools/parches-m1.cjs`, `derivar-compile-cases.cjs`) y `compile-cases.json` regenerado (+ README): quinta clave vacía en los 306 casos (expect 306/306 iguales al compilador 1) y 4 inválidos nuevos. El guion solo deriva la lista vacía porque el texto del parche de frases no está en §B.3; **que lo revise `tester`**. `publication-cases-v2.json` NO cambia (`--comprobar` OK).

Pruebas existentes ajustadas (fijaban el compilador 1 o la forma de cuatro claves): `UltronPromptCompilerTest`, `StudioPromptDeclarationTest` (+ `partActual()`, el compilador 1 ahora prueba `compiler_changed`), `UltronDecidePromptsTest` (caso «compilador cambiado» con una parte real del 1), `UltronPromptsTraceCommandTest`, `UltronStudioStatusCatalogTest`, `UltronStudioPublicationPromptsTest`, `UltronStudioPublicationV2Test`, `UltronStudioPublicationPromptsRaceTest`, `UltronPromptsN8nCommandTest` (fichero nuevo en la lista), `PromptPublications` (+ `parteV2Compilador1()`).

## 3. El texto fijo exacto en cada ancla

Con la lista `L` = cada frase entre `"` y separadas por `, `, y un punto al final:

- **Composer y Composer Reintento**, nueva línea justo tras la de `FORMA:` (por delante de «TOPE DE LONGITUD PUBLICADO» si lo hay):
  `FRASES PROHIBIDAS PUBLICADAS: nunca escribas ninguna de estas frases, tampoco con otras mayusculas; si la respuesta parece necesitar una, dilo con otras palabras. Las frases: L.`
- **Critic y Critic 2**, nueva línea justo tras la de `naturalness:` (por delante de «EMOJIS:» y «REGISTRO PUBLICADO:»):
  `FRASES PROHIBIDAS PUBLICADAS: si el borrador contiene alguna de estas frases, aunque cambien las mayusculas, verdict fail con naturalness. Las frases: L.`
- **Strategist:** nada.

Ejemplo real (base + `gratis`, `te lo regalo`): `… Las frases: "gratis", "te lo regalo".` El texto fijo es ASCII sin tildes (estilo de la base) y no contiene ningún ancla ni llaves (probado). **Es texto nuevo de prompts de producción: necesita la aprobación del propietario como los de M1-3.**

## 4. Contratos nuevos o modificados

- **`ultron-engine-config/2`** (sin migración; el CHECK ya admite `/2`): con `prompts.compiler >= 2`, `governed` = `{persona.emojis, persona.maxChars, persona.tone, rules.forbiddenPhrases: [texto…], strategy.goal}`; con `1`, las cuatro de siempre. Legacy sigue en `/1`.
- **`decide`**: `studio_prompts.compiler` pasa a `2` (n8n no lo mira). Nada más cambia.
- **Vista previa** (`GET versions/{uuid}/publication-preview`, aditivo): `prompts.phrases = {list: string[], rules: string[], max: 12, problems: [{rule: string|null, reason: "length"|"charset"|"spacing"|"anchor"|"too_many", phrase: string|null}]}`; `prompts.changes[]` puede llevar, el último, `{param: "rules.forbiddenPhrases", value: string[], roles: [composer, critic, composer_retry, critic_retry], patches: […]}`; `prompts.error` y `blocking` pueden valer `prompts_rule_phrases_invalid`; las S-NNN que aportan frases ya no salen en `noEffect`; `previewHash` las incluye (lista `prompt`) y cambia con la huella.
- **`POST versions/{uuid}/publish`**: 409 `prompts_rule_phrases_invalid` con `problems` (la misma lista), en el sitio de `prompts_compile_failed` (tras el laboratorio, antes de los pasos 8 y 9).
- `status.engine.message` con la llave de prompts: texto nuevo de `ENGINE_MESSAGES_PROMPTS` (dice que entran las frases y que no es un filtro).

**Ruptura con el CRM de producción (falla cerrado):** versiones SIN frases: la vista previa sale igual salvo `compiler: 2` y el bloque `phrases` (el parser del CRM ignora claves de más). Versiones CON frases: el parser estricto del CRM rechaza el cambio `rules.forbiddenPhrases` (valor lista, `param` fuera de los cuatro) y el error nuevo → el diálogo no puede publicarlas hasta que el CRM se actualice. Es lo deseable (nadie publica un paquete que no vio), pero hay que desplegar el CRM antes de que alguien cree reglas con frases.

## 5. Códigos nuevos

- `prompts_rule_phrases_invalid` (vista previa y 409). Mensaje: «Las frases que prohíben las reglas del Studio de esta versión no caben en los prompts de WhatsApp: como mucho 12 frases, de 2 a 60 caracteres, solo con letras, cifras, espacios y , . ¿ ? ¡ ! -. No se publica nada: corrige las reglas y crea una versión nueva.»
- Motivos de `problems`: `length`, `charset`, `spacing`, `anchor`, `too_many`.
- `UltronPromptException::INVALID_PHRASES` (`invalid_phrases`), solo para logs; en el motor cae a la oferta `error` (Legacy). Log del Studio: `ultron_studio.prompts_compile_failed` con `reason: invalid_phrases` y los motivos, nunca las frases.

## 6. Huellas

| Paquete | Huella | Bytes |
|---|---|---|
| Legacy (línea base, sin frases) | `3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902` (sin cambios) | 112 216 |
| «profesional + 200» sin reglas, compilador 2 | `702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c` (sin cambios: la huella cubre los cinco textos, no la versión del compilador) | 113 672 |
| Línea base + `gratis`, `te lo regalo` | `fbd3c91cb9f283519fe7d2ac4daf60323d1151e2599a9cbee39195090e4d8916` | 112 972 |
| Fixture de n8n: «profesional + 200» + `2x1`, `gratis`, `te lo regalo`, `¿qué esperas?`, `ñapa`, `último día` | `9a3eae9be1877a2a6c0958a67debcba887e6191fcd3d3c46e934a4ce51465dc0` | — |

Lo que SÍ cambia con «profesional + 200» sin reglas: el `previewHash` (lleva `compiler: 2`) y lo firmado (`compiler: 2` y la quinta clave vacía). Fijan el `2`: `UltronPromptCompilerTest`, `StudioPromptDeclarationTest`, `UltronDecidePromptsTest`, `UltronPromptsTraceCommandTest`, `UltronStudioStatusCatalogTest`, `UltronStudioPublicationPromptsTest`, `compile-cases.json`, los ficheros generados de `n8n/flujos/ultron-studio-s1` y `smoke-h9.py:635-636` (sin actualizar, §10).

## 7. Orden de despliegue (para el runbook)

- Al subir este código, toda parte `/2` firmada con el compilador 1 sigue **declarada** (`metadata.studio` = `applied`), pero `decide` deja de ofrecerla: `studio_prompts` = `legacy · compiler_changed` (lo enseñan `ultron:prompt-trace` y «Turnos de la canaria»). Probado en `UltronPublishedConfigPhrasesTest::test_lo_firmado_con_el_compilador_1_…`, `UltronDecidePromptsTest` (fila «compilador cambiado»), `StudioPromptDeclarationTest::test_la_oferta_…` y, por HTTP, `UltronStudioPublicationRulePhrasesTest::test_lo_publicado_con_el_compilador_1_cae_a_legacy_hasta_revertirlo_y_publicar_otra_version`.
- **Para recuperarla:** revertirla (queda `ROLLED_BACK`, terminal) y publicar una versión NUEVA con el mismo contenido (cuatro ojos, veredicto del laboratorio si la puerta es `required`, ventana). Promover NO la recompila: copia la parte tal cual y seguiría en `compiler_changed`.
- **Recomendación:** desplegar antes de E4 o con V2 ya revertida. Hoy hay 0 publicaciones según el recuento de las 18:42Z (no lo releí).
- **Vuelta atrás del backend** (R10): el código anterior lee una parte del compilador 2 (cinco claves) como `malformed` (sello `fallback`, oferta Legacy, sin turnos mudos) y el Studio anterior no podría componer sobre esa cabeza. Procedimiento: primero «Volver a Legacy» en el Studio (escribe `/1`), después devolver el código.
- La llave de prompts y las demás no cambian; ninguna variable de entorno nueva; **sin migración**.

## 8. Pruebas ejecutadas (salida real)

SQLite (`nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`):
- Nuevas: `UltronPromptCompilerPhrasesTest` 59 OK; `UltronPublishedConfigPhrasesTest` 22 OK; `StudioRulePhrasesTest` 21 OK; `UltronStudioPublicationRulePhrasesTest` 6 OK (133 aserciones).
- Publicación (21 ficheros: `UltronStudioPublication*`, `StudioPublication*`, `StudioPromptDeclarationTest`, `ExportStudioFixturesCommandTest`, `UltronStudioStatusCatalogTest`, `UltronStudioPermissionsTest`): **OK 660** (5 927 aserciones, 46 omitidas de PostgreSQL).
- Motor y prompts (`tests/Unit/Marketing/Ultron/Prompts`, `UltronCommit{Prompts,StudioStamp,}Test`, `UltronDecide{Prompts,}Test`, `UltronFixturesTest`, `UltronPrompts{N8nCommand,TraceCommand}Test`): **OK 1 167** (13 715).
- Voz (`StudioVoice*`, `UltronStudioVoice*`, `StudioRulePhrasesTest`, `VoiceAgentSpecTest`): **OK 703** (73 omitidas). Una primera pasada dio 64 errores `Target class [files] does not exist`: `bootstrap/cache/services.php` se reescribió a las 16:27:28, en mitad de la pasada, y en este mismo árbol trabaja otro agente (`app/Services/Outreach`, `OutreachIsolationTest`). Repetida la orden exacta: 703 verdes; por separado, 505 + 198 verdes.
- Guardas (la orden del encargo, 15 ficheros): **138 pruebas, 137 OK, 1 fallo**: `OutreachIsolationTest::test_la_puerta_de_diff_…` lista exactamente `UltronPromptCompiler.php`, `UltronPromptException.php`, `UltronPromptsN8nFlow.php` y `UltronPublishedConfig.php` frente a `origin/main`. `UltronEngineIsolationTest` y `UltronStudioEngineReadOnlyTest` verdes (el Studio solo nombra del motor las tres clases puras; `StudioRulePhrases` no nombra el motor).
- Pasada final tras `pint`: OK 949.

PostgreSQL 14 local (`UNIT_DB=ultron_studio_reglas_unit nice -n 10 bash docs/ultron-studio/ops/pgunit.sh --no-logging …`):
- `UltronStudioPublicationRulePhrasesTest`, `UltronPublishedConfigPhrasesTest`, `UltronPublishedConfigV2Test`, `UltronStudioPublicationPromptsTest`, `UltronStudioPublicationV2Test`, `UltronStudioPublicationPromptsRaceTest`, `UltronStudioPublicationV2RaceTest`, `UltronDecidePromptsTest`, `UltronCommitPromptsTest`, `UltronCommitStudioStampTest`: **OK 283** (3 762), 0 omitidas (las de carrera corrieron).
- `UltronStudioPublication{Lifecycle,Rollback,Schema,Race,Verified,Canary,Access,Config}Test`, `UltronStudioStatusCatalogTest`: **OK 194** (4 751).

Otros: `node docs/ultron-studio/ops/motores/verificar-flujo.cjs ../n8n/flujos/ultron-studio-s1/flujo-s1.json` → **OK (121 comprobaciones)**; `derivar-compile-cases.cjs --comprobar` OK (306 casos, 30 inválidos); `derivar-publication-cases-v2.cjs --comprobar` OK; `vendor/bin/pint --test` sobre los 27 PHP tocados → passed.

## 9. Mutaciones (aplicadas una a una, con las pruebas que deben matarlas, restauradas con comprobación de huella)

| # | Mutación | Resultado | La matan |
|---|---|---|---|
| M1 | La lista vacía aplica parche | MUERTA | `…sin_frases_no_hay_parche…`, `…la_huella_de_profesional_y_200_no_cambia…` |
| M2 | El juego admite `"` y `:` | MUERTA | `test_una_frase_hostil_no_entra_en_un_prompt` |
| M3 | El lector acepta cuatro claves con cualquier compilador | MUERTA | `UltronPublishedConfigPhrasesTest` (quinta clave fuera de forma, parte del resto) |
| M4 | Las S-NNN con frases siguen en `noEffect` | MUERTA | 2 de HTTP + `StudioRulePhrasesTest` |
| M5 | Recortar a 12 en silencio | MUERTA | `test_mas_de_doce_frases_no_se_recortan` (HTTP y unidad) |
| M6 | No se pregunta el «si» de la regla | MUERTA | `StudioRulePhrasesTest` (incl. el `specHash` de la voz) y `StudioVoiceCompilerTest` |

Guion: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/c5ff3ac0-57fb-436f-a64e-17e70a6fe541/scratchpad/mut/mutar.py`.

## 10. Lo que NO pude verificar

- Que el modelo obedezca: el efecto es probabilístico (Composer + Critic); no hay guarda determinista en Laravel (opción B, descartada) y los textos curados de rendición de Laravel no conocen las frases (riesgo 3 de la auditoría). Se mide en la canaria.
- El CRM (no lo toqué) ni n8n en producción (no hace falta tocarlo: el flujo es el mismo).
- La batería completa (no se lanzó, por la regla de carga de la máquina).
- Revisión de `security`/`api-security-specialist`: **pendiente y recomendada**. Riesgo residual: una frase con forma de instrucción («ignora todo lo anterior») pasa el juego cerrado y entra entre comillas como «frase prohibida»; la mitigan el marco fijo, los cuatro ojos y la vista previa con el texto exacto, no la sintaxis.
- El recuento de publicaciones de producción de hoy (dato de la auditoría, 18:42Z).

## 11. Qué tiene que cambiar el CRM

- `studio-api.types.ts:853`: los cambios de prompts admiten `param: 'rules.forbiddenPhrases'` con `value: string[]`; `PublicationPromptsDto` gana `phrases` (opcional, para un backend anterior) y `error` admite `'prompts_rule_phrases_invalid'`.
- `studio-parse.ts`: `leerCambio` (:662-668) acepta ese `param` con una lista de textos (≤ 12, cada uno ≤ 60); `PARCHE_MAX` (:277) a 2 048 para ese cambio; `leerPromptsDeLaVista` admite el error nuevo; la regla «error ⇔ `blocking` incluye `prompts_compile_failed`» (:974) pasa a «error ⇔ `blocking` incluye el mismo código»; `efectoDeLaVista` (:968-973) compara los cambios con los `params` de clase `prompt` SIN `rules.forbiddenPhrases` (las reglas no son filas de `params`).
- `studio-prompts-changes.ts`: etiqueta «Frases prohibidas de las reglas», pintar la lista y `phrases.rules` (qué S-NNN aportan).
- `studio-publication-text.ts:38` y `ultron-studio-page.ts:257`: quitar «y las reglas S-NNN siguen sin efecto en WhatsApp»; añadir «y también las frases que prohíben sus reglas S-NNN vivas cuyo «si» se cumple (se le piden al modelo y el Critic las vigila; no es un filtro)». `studio-publication-text.ts:214-216`: «Las reglas del Studio no tienen efecto en WhatsApp» → para las que siguen en `noEffect`: «Esta regla no aporta frases a WhatsApp: está archivada, no prohíbe frases o su «si» no se cumple en esta versión». Texto nuevo para el bloqueo `prompts_rule_phrases_invalid` con los cinco motivos (`length`: «tiene menos de 2 o más de 60 caracteres»; `charset`: «lleva un carácter que no se admite: solo letras, cifras, espacios y , . ¿ ? ¡ ! -»; `spacing`: «tiene espacios de más»; `anchor`: «repite una marca interna de los prompts»; `too_many`: «entre todas las reglas hay más de 12 frases distintas»).
- `studio-rules-panel.ts:138` (y el comentario :53): «el motor de ULTRON no las aplica» → «ULTRON por WhatsApp no las aplica como reglas; con la llave de prompts encendida, las frases que prohíbe una regla viva entran en los prompts de la conversación canaria cuando se publica en canario una versión que la copia. En la voz, también entran en el agente compilado.»
- Fixtures: regenerar con `php artisan ultron-studio:export-fixtures` (`testing/backend-publication.testing.ts`: `compiler: 2`, `engineMessagesPrompts`, `prompts.phrases`).

## 12. Ficheros de operación a actualizar (no los toqué)

- `docs/ultron-studio/ops/smoke-h9.py:59` y `:635-636`: exige `compiler == 1` en el `status` de producción → **cambiar a 2 en el mismo despliegue** (antes, fallaría contra producción; después, sin cambiarlo, falla).
- Script de despliegue: uno nuevo (patrón `deploy-m1-backend.sh`, lista cerrada `:161-171`) con: `UltronPromptCompiler.php`, `UltronPromptException.php`, `UltronPromptsN8nFlow.php`, `UltronPublishedConfig.php`, `Console/Commands/Marketing/UltronPromptsN8n.php`, `Exceptions/UltronStudioException.php`, `StudioPromptBundle.php`, `StudioPublicationDeclaration.php`, `StudioPublicationExport.php`, `StudioPublications.php`, `StudioVoiceCompiler.php`, `StudioRulePhrases.php` (nuevo), `Support/Access/PermissionCatalog.php`. Sin migraciones.
- `docs/ultron-studio/ops/RUNBOOK_M1.md` (junto a E4, :294): la nota de §7 (compilador 1 → `compiler_changed`; revertir y publicar otra versión).
- `docs/ultron-studio/ops/motores/README.md:12` (`VERSION = 1` → 2) y una fila para las frases; `:42` sigue valiendo (`702890f7…`).
- `motores-prompts-scope.sh` y los demás mandos de llaves: sin cambios.
- n8n: ninguna operación. La copia viva S1 sirve tal cual; los ficheros generados del repositorio ya están regenerados.

## 13. Para el lead, `tester` y `security`

- **Autorización pendiente:** la puerta de diff del H9 (protecciones 5–8) quedará roja hasta que esto llegue a `origin/main`; M1-15 cedió «solo para esta enmienda», así que este cambio necesita su propia autorización escrita (los 4 ficheros están dentro de la lista que cedió M1-15).
- **Textos fijos nuevos en prompts de producción** (§3): aprobación del propietario, como M1-3.
- **`tester`:** revisar el ajuste de sus guiones (`parches-m1.cjs`, `derivar-compile-cases.cjs`) y, cuando el texto se congele en el contrato, derivar también listas no vacías; casos límite que conozco desde dentro: orden por bytes con `¿`/`ñ`/`ú` (C2 BF < C3 B1 < C3 BA), frases que solo difieren en mayúsculas (G las canonicaliza a minúsculas, así que no llegan), una regla cuyo «si» depende de una arista, 12 frases exactas frente a 13 entre varias reglas, una cabeza mixta (canaria del compilador 2, resto del 1).
- **`security`/`api-security-specialist`:** texto del personal en prompts de producción (§10); el bloque `problems` devuelve frases de la propia versión al personal del Studio (ya validadas como texto plano por G al crear la regla).

---

## Vuelta 2: correcciones de las revisiones (11-11-2026, `backend`)

Cierra B-1 y B-3 de `informe-security-reglas.md`, la prueba que `security` pedía a `tester` y el hallazgo 1 del paquete 1 de `informe-reviewer-ola-4.md`. **Sin commit, sin push, sin tocar producción, n8n, el CRM ni los documentos de contrato.** No he tocado el paquete del H9 (`app/Services/Outreach/**`, sus pruebas y fixtures, `OutreachIsolationTest.php`) ni `smoke-h9.py` ni `motores/README.md`.

### Resumen

1. **La parte del resto que se arrastra se ve.** `prompts.carried` lleva siempre `phrases`: las frases que esa parte mete en los prompts.
2. **Si no compila por sus frases, el fallo se puede diagnosticar.**
   - La vista previa da el código propio `prompts_rule_phrases_invalid` (nunca el genérico) y `carried.problems`.
   - Publicar da 409 con `part: "carried"` y un mensaje que dice que la causa es la versión que hoy rige el resto, y cuál es la salida.
   - Nada se recorta ni se arrastra a medias.
3. **Frases mínimas (B-3).** En el compilador de WhatsApp el mínimo pasa de 2 a 3 caracteres, y una frase necesita al menos una letra o una cifra. Si no, el motivo nuevo es `no_text`.
   - No cambian la gramática G, la voz ni las huellas fijas.
   - Ni `compile-cases.json` ni el fixture de n8n tenían que cambiar.
4. **Pruebas.**
   - SQLite: todo verde.
   - PostgreSQL: 287 + 194 verdes, 0 omitidas.
   - Guardas: igual que antes (138, con el fallo esperado y ajeno).
   - Mutaciones: 6 de 6 muertas.

### 1 · Qué cambió (ruta:línea)

- `app/Services/UltronStudio/StudioPublications.php`
  - Cabecera de la clase (:82-88).
  - Documentación de la vista previa.
  - `promptsDto()`: `carried` con `phrases` siempre; con `problems` solo si no compila por sus frases (:1043-1050). `shortHash` admite null.
  - `publishRefusal()` (:1128) delega en un `phrasesRefusal()` nuevo (:1168), que elige `problems` y `part` según `errorPart`.
  - `promptFacts()` (:1586):
    - Nuevo `errorPart`.
    - La rama de lo arrastrado que no cabe (:1612) identifica la versión y pone `bundleHash` y `baseline` a null, con `phrases` y `problems`.
    - El error de la versión que se publica tiene prioridad: el arrastrado solo pone el suyo si no había otro.
  - `promptedPart()` (:1676) devuelve `{part, label, phrases, problems}`.
    - Parte `/2`: las frases salen de su `governed` firmado (`[]` si es del compilador 1).
    - Parte `/1`: primero `StudioPromptBundle::phrases()`. Si hay problemas, `part` es null con sus `problems` y el log `ultron_studio.prompts_compile_failed {part: carried, reason: invalid_phrases, problems: [códigos]}` (sin frases, :1695). Si no, compila con esa misma lista.
  - `promptedForPublish()`: `phrasesRefusal` (:1730). Además, dentro de la transacción (:1743), si la cabeza se movió y la parte del resto de ahora trae frases que no caben, da 409 `carried`.
    - Sin esa rama, la parte saldría null y, con una vista previa que no arrastraba nada, se firmaría la entrada **sin** el resto. La prueba y M10 lo cubren.
- `app/Exceptions/UltronStudioException.php`
  - `promptsRulePhrasesInvalid(array $problems, string $part = 'version')` (:430). La carga útil es `{problems, part}`, con dos mensajes (abajo).
  - Importa `StudioPromptBundle` para las dos constantes. Ya importaba `StudioLabConfig`.
- `app/Services/UltronStudio/StudioPromptBundle.php`
  - `PHRASES_PART_VERSION` y `PHRASES_PART_CARRIED` (:65-67).
  - Documentación de `CODE_PHRASES_INVALID` y de `phrases()`: 3–60 caracteres y `no_text`.
- `app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php`
  - `PHRASE_MIN_CHARS = 3` (:142).
  - `PHRASE_TEXT_PATTERN = '/[A-Za-z0-9áéíóúÁÉÍÓÚñÑüÜ]/u'` (:154).
  - `PHRASE_NO_TEXT = 'no_text'` (:161).
  - `phraseViolation()` comprueba `no_text` después de `charset` (:396-397).
  - Documentación (:23, :132-140, :376).
  - Fuera de eso, ningún otro cambio (el lector del motor, `UltronPromptOffer`, el selector de n8n).

Pruebas:

- `tests/Feature/UltronStudio/UltronStudioPublicationRulePhrasesTest.php`: 4 pruebas nuevas (:308, :357, :385, :421).
  - El 409 de la versión exige ahora `part: version` (:278).
  - Ayudantes nuevos: `publicadaYPromovidaSinLaLlave()` (por la API, con la llave en `off`, 3 turnos y 25 h), `archivar()`, `beforeNthHeadRead()` y `tearDown()` del reloj.
- `tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerPhrasesTest.php`
  - `…de_3_a_60…` (:164): `ab`, `no`, `de`, `sí`, `..` y `¿?` pasan a `length`; `abc`, `2x1` y `ñañ` siguen valiendo.
  - `…es_no_text` (:176): 7 frases sin letras ni cifras y 6 con una sola letra o cifra.
- `tests/Unit/UltronStudio/StudioRulePhrasesTest.php`: `test_las_frases_cortas_o_sin_texto_no_cambian_la_voz_y_whatsapp_las_rechaza` (:174).
  - El `specHash` de la voz para una versión con `no`, `..`, `¿?`, `...` y `- -` es `871753c69032fe61074c26ce5b1a4315dde831da491de9edf1d32911b1385927`. Lo fijé ejecutando la prueba con el compilador SIN tocar, antes de B-3.
  - La voz acepta esas frases; WhatsApp las rechaza con `length` o `no_text`.
  - La huella `5300386942b6…7006` de la primera vuelta sigue igual.
- `tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php:261`: la aserción de forma exacta de `carried` gana `'phrases' => []`. Es la clave aditiva; no se relaja nada.

### 2 · Contrato final (aditivo)

**Vista previa**, `GET versions/{uuid}/publication-preview`, bloque `prompts.carried`, con la llave de prompts encendida y una cabeza con resto:

```
// Compila (resto `/2` copiado, o `/1` compilado ENTERO):
carried = { versionUuid, label, bundleHash: string, shortHash: string, baseline: bool,
            phrases: string[] }                       // ordenadas por bytes; [] si no trae (o si la parte /2 es del compilador 1)
// NO compila por sus frases (más de 12, o alguna fuera del juego, de 3–60 o sin letra ni cifra):
carried = { versionUuid, label, bundleHash: null, shortHash: null, baseline: null,
            phrases: string[],                        // la lista ENTERA calculada (como prompts.phrases.list), aunque no quepa
            problems: [{rule: string|null, reason: "length"|"charset"|"no_text"|"spacing"|"anchor"|"too_many", phrase: string|null}] }
```

- `problems` solo aparece en el segundo caso. Tiene la misma forma, el mismo orden y los mismos motivos que `prompts.phrases.problems`.
- Sin cabeza o sin resto, `carried` es null, como antes.
- En el segundo caso, `prompts.error` es `prompts_rule_phrases_invalid` y `blocking` lo incluye (nunca `prompts_compile_failed`), **salvo** que la versión que se publica ya tenga su propio error. Su error tiene prioridad, y entonces `carried.problems` sale igualmente.
- Invariantes para el parser del CRM:
  - `prompts.phrases.problems ≠ []` ⇒ `error = prompts_rule_phrases_invalid`.
  - `carried.problems` presente ⇒ `error ≠ null`.
  - `error = prompts_rule_phrases_invalid` ⇒ `prompts.phrases.problems ≠ []` o `carried.problems` presente.
- Un `carried` que no se puede leer por otra causa (versión que falta o no es íntegra, otro fallo del compilador) sigue como hoy: `carried` null y `prompts_compile_failed`.

**409 al publicar** (`POST versions/{uuid}/publish`), en el mismo sitio de siempre: después del laboratorio y antes de `preview_stale`.

```
{ ok: false, code: "prompts_rule_phrases_invalid", message, problems: [...], part: "version" | "carried" }
```

- `part: "version"`: las frases de la versión que se publica. `problems` = `prompts.phrases.problems`. Mensaje:
  > Las frases que prohíben las reglas del Studio de esta versión no caben en los prompts de WhatsApp: como mucho 12 frases, de 3 a 60 caracteres, con al menos una letra o una cifra, solo con letras, cifras, espacios y , . ¿ ? ¡ ! -. No se publica nada: corrige las reglas y crea una versión nueva.
- `part: "carried"`: las de la versión que rige el resto. `problems` = `prompts.carried.problems`. Mensaje:
  > No se publica nada, y la causa no está en esta versión: está en la versión que hoy rige el resto de las conversaciones. Esta publicación llevaría su parte a los prompts de WhatsApp con las frases que prohíben sus reglas del Studio, y esas frases no caben: como mucho 12 frases, de 3 a 60 caracteres, con al menos una letra o una cifra, solo con letras, cifras, espacios y , . ¿ ? ¡ ! -. La salida: «Volver a Legacy» antes de publicar; si quieres conservar esas reglas, corrígelas y publica otra versión.
- **Por qué ese orden en la salida:** mientras el resto siga siendo esa versión `/1` y la llave esté encendida, publicar CUALQUIER otra versión, también una con las reglas corregidas, vuelve a arrastrarla y vuelve a dar este 409.
  - Corregir y publicar solo funciona después de «Volver a Legacy», o con la llave de prompts apagada, que es una operación de servidor y no del Studio.
  - Revertir esa versión PUBLISHED también la saca del resto, pero devuelve el resto anterior a su promoción, que podría ser otra `/1` con frases. Por eso no está en el mensaje.
  - Lo probé por HTTP: después de «Volver a Legacy», `carried` es null y la versión se publica.
- Ninguno de los dos mensajes lleva frases, etiquetas ni datos internos.

**`previewHash`: no cambia en ningún caso publicable.**
- Ya ataba la parte arrastrada como `prompts.carried = {bundleHash, version}`, y las frases están dentro de `bundleHash`:
  - el compilador mete la lista literal en cuatro roles;
  - la representación es inyectiva, porque una frase no puede llevar `"`;
  - `[]` no aplica ningún parche.
  - La prueba (a) comprueba que lo arrastrado con `gratis` y `te lo regalo` tiene `fbd3c91c…8916` y no la Legacy. `UltronStudioPublicationV2Test::test_la_huella_de_la_vista_previa_ata_la_parte_del_resto_que_se_arrastra` comprueba que otra huella arrastrada da otro `previewHash`.
  - No añadí `phrases` a la huella: habría cambiado el `previewHash` de toda vista previa con resto sin atar nada nuevo.
- **Sí cambia en un solo caso, que no se puede publicar:** el resto que no compila por sus frases. Antes entraba `carried: null`; ahora entra `{bundleHash: null, version}`. Esa vista previa bloquea, y la puerta de las frases va antes de `preview_stale`.

### 3 · Frases demasiado cortas o sin texto (B-3)

- **Compilador de WhatsApp.** Una frase necesita de 3 a 60 caracteres (no bytes) y al menos una letra o una cifra del juego cerrado. Los motivos se comprueban en este orden: `charset` (UTF-8 inválido), `length`, `charset`, **`no_text`**, `spacing`, `anchor`; y para la lista, `too_many` y `order`.
  - Ejemplos: `no`, `..` y `¿?` dan `length`; `...`, `¿¡?`, `- -` y `" . "` dan `no_text`; `¿y?`, `a..` y `1 - 2` valen.
  - El mensaje de la excepción sigue sin repetir la frase (probado con `(no_text)`).
- **Sin cambios:**
  - G sigue en 2 a 60 caracteres (`StudioRuleCoherence`, `ULTRON_STUDIO_G_CONTRACT.md:151`).
  - La voz sigue con su propia validación (`StudioVoiceTemplate::textViolation`), que acepta esas frases. Su `specHash` se conserva (`871753c6…5927` con frases cortas; `53003869…7006` el de la primera vuelta).
  - **Consecuencia nueva:** una S-NNN con una frase de 2 caracteres (por ejemplo «ya»), válida en G y en la voz, impide publicar en WhatsApp con la llave encendida (409 visible, motivo `length`). Va en la misma línea que la nota del reviewer sobre la publicabilidad.
- **`compile-cases.json` y sus guiones: no cambian.** El guion solo deriva la lista vacía, que no tiene mínimo. `node tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs --comprobar` da `OK: compile-cases.json es el que sale del contrato (306 casos, 30 inválidos, Legacy 3f6a5674…c902)`.
- **Fixture de n8n con frases: no cambia.** Las 6 frases de `FIXTURE_PHRASES` siguen siendo válidas con el compilador nuevo (`phraseViolation` = null en las 6). No hizo falta regenerar.
  - `UltronPromptsN8nCommandTest::test_lo_que_hay_en_el_repositorio_es_lo_que_genera_hoy` en verde.
  - `node docs/ultron-studio/ops/motores/verificar-flujo.cjs ../n8n/flujos/ultron-studio-s1/flujo-s1.json` da **OK (121 comprobaciones)**.
  - Huella del paquete del fixture: `9a3eae9be1877a2a6c0958a67debcba887e6191fcd3d3c46e934a4ce51465dc0` (la misma). SHA-256 del fichero: `ea30e96b…00af`.
- **Huellas que no podían cambiar, comprobadas en ejecución:**
  - Legacy: `3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902`.
  - «Profesional + 200»: `702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c`.

### 4 · Pruebas HTTP nuevas (sobre las clases reales; SQLite y PostgreSQL)

- **(b) `test_una_parte_del_resto_v1_cuyas_frases_no_caben_bloquea_con_su_codigo_y_part_carried`.**
  - Preparación:
    - Una S-NNN con «100% gratis» (G la admite).
    - V0 se aprueba, se publica y se promueve por la API con la llave en `off`. La cabeza queda `/1 {V0, V0}`.
    - Se archiva la regla, se enciende la llave y V1 no trae reglas.
  - Vista previa de V1:
    - `blocking = [prompts_rule_phrases_invalid]`, `error` el mismo y `bundleHash` = G8, porque V1 compila.
    - `prompts.phrases` vacío.
    - `carried = {versionUuid: V0, label, bundleHash: null, shortHash: null, baseline: null, phrases: ["100% gratis"], problems: [{rule: S-NNN, reason: "charset", phrase: "100% gratis"}]}`.
  - Publicar:
    - 409 con el código, `part: carried`, esos `problems` y los textos del mensaje.
    - La cabeza no se mueve; V1 sigue APPROVED y V0 PUBLISHED.
  - Salida: «Volver a Legacy» deja `carried` null, la vista previa se puede publicar, publicar da 201 y la oferta es `studio · V1 · G8`.
- **(a) `test_la_vista_previa_ensena_las_frases_de_la_parte_que_se_arrastra`.**
  - V0 trae `gratis` y `te lo regalo`.
  - V1 se puede publicar, y `carried = {…, bundleHash: fbd3c91c…8916, shortHash: "fbd3c91c", baseline: false, phrases: ["gratis", "te lo regalo"]}`, sin `problems`.
- **Seguridad, `test_publicar_con_la_llave_y_revertir_lleva_a_la_canaria_las_frases_de_la_parte_arrastrada`.**
  - Antes de publicar, la oferta es `legacy · declaration_v1`.
  - La vista previa de W enseña `carried.phrases`.
  - Publicar W firma el resto como `/2` con `rules.forbiddenPhrases = ["gratis", "te lo regalo"]` y la huella `fbd3c91c…`. Mientras W está en canario, la oferta es `studio · W · G8`.
  - **Al revertir W, la oferta del motor para la canaria es `{mode: studio, version: V0, bundleHash: fbd3c91c…8916, reason: null}`.** Los cuatro roles (Composer, Composer Reintento, Critic y Critic 2) llevan, en una línea propia, el texto fijo + `"gratis", "te lo regalo".`, y el lector da `governed.rules.forbiddenPhrases = ["gratis", "te lo regalo"]`.
  - Es el comportamiento acordado (se arrastra entero) y ahora lo enseñó la vista previa.
- **Extra, `test_si_la_cabeza_se_mueve_dentro_de_la_transaccion_a_un_resto_cuyas_frases_no_caben_es_409_carried`.**
  - Las puertas ven la cadena vacía. Justo antes de la segunda lectura de la cabeza (la de la transacción), un gancho de la conexión deja en el resto una versión `/1` con «100% gratis».
  - Resultado: 409 `carried`.
  - Lo sembrado se deshace con la transacción (`chainHead()` null), lo que prueba que el rechazo salió de la transacción y no de las puertas.

### 5 · Pruebas ejecutadas (salida real)

SQLite (`nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`):
- Las de frases (`UltronPromptCompilerPhrasesTest`, `UltronPublishedConfigPhrasesTest`, `StudioRulePhrasesTest`, `UltronStudioPublicationRulePhrasesTest`): **OK (114 tests, 762 assertions)**.
- Publicación (18 ficheros: `UltronStudioPublication*`, `StudioPublication*`, `StudioPromptDeclarationTest`): **OK, but some tests were skipped! Tests: 644, Assertions: 4220, Skipped: 46** (las 46 son de PostgreSQL).
- Motor y prompts (14 ficheros: `tests/Unit/Marketing/Ultron/Prompts/*Test.php`, `UltronDecide*`, `UltronCommit*`, `UltronPrompts*`): **OK (1140 tests, 13647 assertions)**. Incluye `UltronPromptCompileCasesTest`, que ejecuta los `--comprobar`.
- Voz (24 ficheros: `StudioVoice*`, `UltronStudioVoice*`, `StudioRulePhrasesTest`): **OK, Tests: 645, Assertions: 5886, Skipped: 73**.
- Además, `ExportStudioFixturesCommandTest` verde en la pasada intermedia (89 pruebas).
- Guardas (la orden del encargo): **Tests: 138, Assertions: 178687, Failures: 1**. El fallo es solo `OutreachIsolationTest::test_la_puerta_de_diff…`, con exactamente `UltronPromptCompiler.php`, `UltronPromptException.php`, `UltronPromptsN8nFlow.php` y `UltronPublishedConfig.php`, igual que antes.
- `vendor/bin/pint` sobre los 8 PHP tocados en esta vuelta: `passed`.

PostgreSQL 14 local (`UNIT_DB=ultron_studio_reglas_unit nice -n 10 bash docs/ultron-studio/ops/pgunit.sh --no-logging …`):
- `UltronStudioPublicationRulePhrasesTest`: **OK (10 tests, 265 assertions)**. Incluye el gancho dentro de la transacción.
- `UltronPublishedConfigPhrasesTest`, `UltronPublishedConfigV2Test`, `UltronStudioPublicationPromptsTest`, `UltronStudioPublicationV2Test`, `UltronStudioPublicationPromptsRaceTest`, `UltronStudioPublicationV2RaceTest`, `UltronDecidePromptsTest`, `UltronCommitPromptsTest`, `UltronCommitStudioStampTest`: **OK (277 tests, 3629 assertions)**, 0 omitidas (las de carrera corrieron).
- `UltronStudioPublication{Lifecycle,Rollback,Schema,Race,Verified,Canary,Access,Config}Test` y `UltronStudioStatusCatalogTest`: **OK (194 tests, 4751 assertions)**.

### 6 · Mutaciones

Aplicadas una a una con `mutar2.py` (en el scratchpad de esta sesión, `…/99489ce7-…/scratchpad/mut/`). Cada fichero se restauró y se comprobó su SHA-256.

| # | Mutación | Resultado | La matan |
|---|---|---|---|
| M7 | `PHRASE_MIN_CHARS` vuelve a 2 | MUERTA | `…de_3_a_60…`, `test_las_frases_cortas_o_sin_texto…` |
| M8 | Sin la comprobación `no_text` | MUERTA | `test_una_frase_sin_ninguna_letra_ni_cifra_es_no_text`, `test_las_frases_cortas_o_sin_texto…` |
| M9 | Lo arrastrado que no cabe vuelve al genérico `prompts_compile_failed` | MUERTA | (b) |
| M10 | Dentro de la transacción se ignora un resto cuyas frases no caben | MUERTA | la del gancho (sin la rama se firmaría la entrada sin resto) |
| M11 | El 409 dice siempre `part: version` | MUERTA | (b) |
| M12 | `carried` sin `phrases` | MUERTA | (a), (b), la de seguridad y `UltronStudioPublicationPromptsTest` |

### 7 · Lista COMPLETA de ficheros de este paquete (para el commit)

Código (13, la misma lista de despliegue de §12; esta vuelta no añade ninguno):
- `app/Console/Commands/Marketing/UltronPromptsN8n.php`
- `app/Exceptions/UltronStudioException.php`
- `app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php`
- `app/Services/Marketing/Ultron/Prompts/UltronPromptException.php`
- `app/Services/Marketing/Ultron/Prompts/UltronPromptsN8nFlow.php`
- `app/Services/Marketing/Ultron/UltronPublishedConfig.php`
- `app/Services/UltronStudio/StudioPromptBundle.php`
- `app/Services/UltronStudio/StudioPublicationDeclaration.php`
- `app/Services/UltronStudio/StudioPublicationExport.php`
- `app/Services/UltronStudio/StudioPublications.php`
- `app/Services/UltronStudio/StudioRulePhrases.php` (nuevo)
- `app/Services/UltronStudio/StudioVoiceCompiler.php`
- `app/Support/Access/PermissionCatalog.php`

Pruebas, nuevas:
- `tests/Feature/UltronStudio/UltronStudioPublicationRulePhrasesTest.php`
- `tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerPhrasesTest.php`
- `tests/Unit/Marketing/Ultron/Prompts/UltronPublishedConfigPhrasesTest.php`
- `tests/Unit/UltronStudio/StudioRulePhrasesTest.php`

Pruebas, modificadas:
- `tests/Feature/Marketing/UltronDecidePromptsTest.php`
- `tests/Feature/Marketing/UltronPromptsN8nCommandTest.php`
- `tests/Feature/Marketing/UltronPromptsTraceCommandTest.php`
- `tests/Feature/UltronStudio/UltronStudioPublicationPromptsRaceTest.php`
- `tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php`
- `tests/Feature/UltronStudio/UltronStudioPublicationV2Test.php`
- `tests/Feature/UltronStudio/UltronStudioStatusCatalogTest.php`
- `tests/Unit/Marketing/Ultron/Prompts/PromptPublications.php`
- `tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerTest.php`
- `tests/Unit/UltronStudio/StudioPromptDeclarationTest.php`

Fixtures y herramientas de `tester`:
- `tests/fixtures/ultron-prompts/compile-cases.json`
- `tests/fixtures/ultron-prompts/compile-cases.README.md`
- `tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs`
- `tests/fixtures/ultron-prompts/tools/parches-m1.cjs`

Operación y documentación:
- `docs/ultron-studio/ops/motores/verificar-flujo.cjs`
- `docs/ultron-studio/ops/motores/README.md` (lo cambió el lead)
- `docs/ultron-studio/ops/smoke-h9.py` (lo cambió el lead)
- `docs/ultron-studio/plans/informes-motores/informe-be-reglas.md` (nuevo)
- `docs/ultron-studio/plans/informes-motores/informe-security-reglas.md` (nuevo)
- `docs/ultron-studio/plans/informes-motores/informe-reviewer-ola-4.md` (nuevo; cubre los dos paquetes: el lead decide en cuál va)

Fuera de `backend/`, generados por `php artisan ultron:prompts-n8n` (no cambian en esta vuelta):
- `../n8n/flujos/ultron-studio-s1/LEEME.md`
- `../n8n/flujos/ultron-studio-s1/flujo-s1.json`
- `../n8n/flujos/ultron-studio-s1/huellas.json`
- `../n8n/flujos/ultron-studio-s1/fixtures/decide-legacy.json`
- `../n8n/flujos/ultron-studio-s1/fixtures/decide-studio.json`
- `../n8n/flujos/ultron-studio-s1/fixtures/decide-studio-frases.json` (nuevo)

**NO son de este paquete:**
- `app/Services/Outreach/**`, `tests/**/Outreach/**` (incluidos `H9*Test.php`), `tests/fixtures/outreach/**` y `tests/Feature/UltronStudio/OutreachIsolationTest.php`.
- `docs/ultron-studio/plans/informes-motores/informe-be-h9-cierre.md`.
- Los directorios `capturas/` de `docs/ultron-studio/ops/evidencia-h8/2026-11-11/` y `evidencia-h9/2026-11-11/`.

### 8 · Ficheros de operación

- Mis cambios **no obligan a tocar ningún otro fichero de operación**. Ningún guion de `docs/ultron-studio/ops/` lee `carried`, el 409 de frases ni el mínimo (comprobado con grep). La lista del script de despliegue sigue con los mismos 13 ficheros.
- **Recomendado, no obligatorio:** en `RUNBOOK_M1.md`, junto a la condición E-3 de `security`, que la comprobación «¿la cabeza tiene un resto `/1` con frases?» ya se ve en la vista previa (`carried.phrases` y `carried.problems`) y que la salida es «Volver a Legacy».
- Documentos de contrato o de auditoría que siguen diciendo «2–60» y que no toqué por el encargo:
  - `plans/cierre/auditoria-a-studio-whatsapp.md:122` (habla del compilador de WhatsApp).
  - `ULTRON_STUDIO_G_CONTRACT.md:151` (es G, y sigue siendo verdad).

### 9 · Lo que tiene que saber el CRM

Lo leí en `_worktrees/ultron-h8/Iron-Body_Front` sin tocarlo. Su `studio-parse.ts` de hoy rechazaría la forma nueva en estos puntos:
- `MOTIVOS_DE_FRASE` (:296) no tiene `no_text`. Texto sugerido: «no tiene ninguna letra ni cifra». Además, `length` pasa a «tiene menos de 3 o más de 60 caracteres».
- `carried.bundleHash` se lee como SHA-256 obligatorio (:813-821). Tiene que admitir null, junto con `shortHash` y `baseline`, cuando venga `problems`. También tiene que leer `phrases` (lista de textos) y `problems` (opcional).
- La regla «`error === prompts_rule_phrases_invalid` ⇔ `phrases.problems` no vacío» (:798) tiene que pasar a las tres invariantes de §2.
- El 409 trae `part`.
- `php artisan ultron-studio:export-fixtures` añade ahora `carried.phrases: []` al fixture de prompts del CRM, que hay que regenerar.

### 10 · Lo que NO pude verificar

- El CRM (no lo toqué), n8n en producción y el estado real de la cadena de producción: si hay hoy una cabeza `/1` con resto y con S-NNN.
- La batería completa (la lanza el lead).
- Que «Volver a Legacy» se pueda escribir en producción con la cabeza que haya. Lo probé solo en pruebas.
- La carrera real entre sesiones de PostgreSQL para el 409 `carried` dentro de la transacción. La cubrí con un gancho en la misma conexión, en SQLite y en PostgreSQL, no con `PublicationRaceSessions`.
- Que `security` o `api-security-specialist` revisen los mensajes nuevos. No llevan frases ni datos internos, y `problems` expone las mismas frases que ya enseñaba la ficha de la versión.
