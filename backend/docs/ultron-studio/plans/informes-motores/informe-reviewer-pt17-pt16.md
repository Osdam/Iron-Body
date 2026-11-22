# Revisión final · PT-17 (compilador 5) y PT-16 (cobro manual con IVA adicional)
reviewer · 22-11-2026 · solo lectura · [C] comprobado ejecutando · [L] leído · [S] supuesto. Sondas en `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/a82204b6-d7ea-4a13-965f-a9b5cc073526/scratchpad/` (`a/`, `b/`, `copia/`, `merge/`, `lab/`, `n8n/`). Los dos árboles quedan con `git status` limpio [C].

## A · PT-17 (`cierre/planes-c5` @ `e5a673c1`, frente a `f1fe6cac`)
**Veredicto: APROBADO para desplegar. Bloqueantes: 0.** Fiel al diseño, sin regresiones en Legacy, doradas regeneradas por sus generadores y sin fijaciones aflojadas. Sigue pendiente lo que el propio diseño deja fuera (que el modelo obedezca): eso se mide en el laboratorio (A45-10).

**No bloqueantes (no se corrigen en este cierre):**
- NB-A1 · No hay prueba que fije la forma del diseño (175 caracteres) en `ai/check` y en el commit, aunque §5(a) del diseño la pide como guarda y A45-12 la da por verificada: ninguna prueba de la rama contiene ese texto [C: grep]. Mi sonda confirma que hoy pasa (ver A2). Corrección futura: añadirla como caso al proveedor de `UltronA4NotasDelServidorTest`. → backend/tester.
- NB-A2 · Integración con el lote 5: además de `StudioLabEngineFlow::COMPILER` y de `StudioLabEngineFlowParityTest.php:150-152`, en el árbol unido cae también `UltronStudioLabEngineInvariantsTest.php:86` (`assertSame(4, UltronPromptCompiler::VERSION)`). El diseño solo nombra la `:148` [C]. → backend, al integrar.

**1. Los textos frente al diseño.**
- Los seis textos nuevos son LITERALES los de §Textos: 784, 158, 1 165, 165, 220 y 135 B. Los comparé por programa con el bloque del diseño [C, `a/textos.php`].
- `T_PLANS_CRITIC` es idéntico byte a byte al de `f1fe6cac` (mismo sha1) [C].
- `enginePatches()` (`UltronPromptCompiler.php:762-774`) sigue el orden del diseño, con papel, operación y ancla correctos [C].
- Cada ancla aparece exactamente una vez en su papel de la base, y ninguna constante `T_*` contiene un ancla [C].
- Compilé 918 combinaciones (306 × tres listas de frases): cero errores [C].
- Lo publicado (profesional · 200 · emojis sí · cita · sin frases) compilado con la clase real da `2eb9be6f…09c0`, 120 097 B y 21 parches [C]. Leí los cinco papeles y su diferencia frente a la base [C, `a/v3c5-*.txt`]:
  - el estratega lleva la inserción en la l.19, justo tras `QUE PLAN SE COTIZA`, y la l.24 sustituida;
  - el redactor lleva la lista en la línea siguiente a `FORMA:` y las tres frases sustituidas (:38, :52 y :53); el reintento, las dos suyas (:38 y :51);
  - los revisores no cambian;
  - no queda ningún resto de las frases sustituidas (las cinco anclas sustituidas no aparecen ni una vez en lo compilado).
- Lo que aún choca con la lista está subordinado explícitamente:
  - en el redactor, el «PORQUE» con «lo que mejor te encaja» (:4 y :33), `suggested_question` (:5), «El NOMBRE del plan se escribe…» (:53) y «Escribes dentro del objetivo de la estrategia» (:91) quedan bajo «manda sobre todo este prompt… y sobre la estrategia si habla de un solo plan»;
  - en el estratega, `suggested_question` y `should_recommend_now` (:56 y :58) quedan bajo «manda sobre… strategy_hints».
  - La única frase que la inserción no subordina por su nombre es «COMO DECIDIR» del estratega (:67: «que tienen» → `general_info`, presentar Iron Body). En la prueba real el intent fue `pricing_question`, y el redactor sí lo cubre (manda sobre su «INFORMACION GENERAL») [L/S]. No lo cambio: es solo algo a mirar en el laboratorio.

**2. Los textos frente a la evidencia (`164`).** Cada causa observada tiene una instrucción que la ataca [L]:
- un solo plan con su nombre: la sustitución del estratega (:24), la inserción con `recommend_plan`/`compare_plans`, la frase «Elige el plan…» condicional, «No escribas sus nombres» y la orden que manda sobre la estrategia;
- `PLAN_1` y `PLAN_3` con un `PLAN_PRICE` suelto: «Cualquier otro marcador…» condicional y «no uses PLAN_NAME, PLAN_PRICE…»; la regla 2 del redactor («DEBE llevar el marcador de precio») queda condicionada por la sustitución de «Elige…», y el reintento no tiene esa regla [C];
- la vuelta a un plan: el esqueleto viaja ahora en el prompt de sistema de los dos reintentos;
- la pregunta de descubrimiento: `information_needed []` («ni experiencia, ni objetivo») en el estratega y «nunca su experiencia ni su objetivo» en el redactor;
- 315, 276 y 211 caracteres: «unas 15 palabras fuera de los marcadores» y «dos o tres palabras» de beneficio.

Lo que queda sin cubrir no es de los textos: el tercer borrador sigue sin comprobarse (A43-3); el revisor no cambia y puede tumbar una lista válida (D1); la nota «nombre duplicado» sigue teniendo precedencia (D3); y el presupuesto es una pauta, no un dato del contexto (§2). La base ya prohibía «para orientarte mejor» y el modelo lo escribió igual: obedecer no está garantizado (A45-11).

Sonda real con el catálogo de producción (po = [3, 4, 8], beneficios de Y8, versión profesional · 200 firmada con el 5) [C, `a/ZzRevisorPt17SondaTest.php`]. La forma literal del diseño:
- `ai/check`: `pass=true`, `defects=[]`, `rewrite=false`;
- commit con el revisor en `pass`: sale tal cual, 175 caracteres, origen `model`, sin `policy_violations`, `plans_overview.valid=true`, `used=[3,4,8]`, trato `usted` sin tuteo.
- Las variantes con tildes (175 caracteres) y con «¿Sobre cuál le amplío la información?» (186 caracteres) dan lo mismo [C].

**3. Lo que NO cambió.**
- La línea base compila a la base y da la huella Legacy `3f6a5674…c902` con el 4 y con el 5 [C].
- Los revisores son iguales entre el 4 y el 5 en las 306 combinaciones [C].
- El diff de `backend/app` es un solo fichero, y `resources/ultron-prompts` y `UltronPromptBase` no tienen ni un byte de cambio [C]. Así que `decide`, `ai/check`, el commit, las notas y `verify()` no se tocan.
- Ningún nodo de n8n ni `UltronPromptStamp` leen `compiler` [C]. El único cambio visible en una respuesta Legacy es `prompts.compiler` 4→5 en `decide` (`UltronPromptOffer.php:256`); juzgarlo es cosa de seguridad.

**4. Doradas y fijaciones.**
- `compile-cases.json` regenerado en una copia con `derivar-compile-cases.cjs`: idéntico al confirmado (sha256 `267e7735…`), `--comprobar` OK, Legacy y v3 iguales que en PHP [C].
- `publication-cases-v2.json` regenerado con `derivar-publication-cases-v2.cjs`: idéntico [C].
- S1 y S2 de n8n regenerados con `php artisan ultron:prompts-n8n` (y `--rev=S2`) fuera del árbol: idénticos, ficheros y bytes [C]. El diff de `n8n/` solo toca `_generado.compiler` y los fixtures de `decide` [C].
- `e7755e87` (42 aserciones quitadas, 52 puestas) y `3763d992` (14 y 16) [L]: cada aserción quitada tiene su sustituta con el valor nuevo, y las huellas viejas se quedan como `assertNotSame`.
  - Una comprobación se endurece: `changedRoles` pasa de `assertEqualsCanonicalizing` a `assertSame(ROLES)`, y «cada valor cambia solo sus roles» se lee sobre `sinMotor()`.
  - Hay casos nuevos (compilador 4 → `compiler_changed`; huella del 4 con versión 5 → `bundle_mismatch`). No encontré nada aflojado.

**5. Al desplegar.**
- Está probado [C, dentro de las 876]: lo firmado con el 4 (`26d57565`) da `legacy · compiler_changed` con el sello `applied` (N3, `UltronPublishedConfigPhrasesTest`), y lo mismo firmado con el 5 se ofrece con `o=1`, `n=200` y `t=profesional`.
- No encontré ningún camino a medias [L]:
  - la oferta vuelve a compilar y exige compilador y huella iguales (`UltronPromptOffer.php:154-170`);
  - `sp` va firmado en el token, y `ai/check` y el commit lo leen de ahí (`UltronCommitService.php:414`, `:429`, `:5535`);
  - las reglas no cambian entre el 4 y el 5, así que cada turno es coherente aunque `decide` y el commit caigan en códigos distintos durante el despliegue;
  - promover no recompila: promover la v3 después del despliegue da Legacy, que es la caída segura.
- Vuelta atrás [C]: el compilador de `f1fe6cac`, cargado aparte, vuelve a dar `26d57565…1075` para la v3, que queda válida otra vez. El punto de no retorno es revertir la v3 (A45-8). `publication-check` sale 0 y no lo detecta (A45-7, documentado).

**6. Integración con el laboratorio.** La unión de `e5a673c1` con `bdb93199` no tiene conflictos de texto: solo comparten ficheros de `docs/` [C, `merge-tree`]. Sobre el árbol unido [C, `merge/`]:
- caen solo `StudioLabEngineFlowParityTest` (1 de 46, `:150`) y `UltronStudioLabEngineInvariantsTest` (1 de 9, `:86`, ver NB-A2);
- pasan en verde `StudioLabEnginePromptsParityTest` (11), `UltronStudioLabGateEngineBundleTest` (3), `StudioLabEngineContextParityTest` (13) y `StudioLabEngineCheckParityTest` (53);
- el laboratorio compila con `StudioPromptBundle::rolesFor()`, es decir con el compilador vigente: no hay textos que portar.

Al subir `COMPILER`, `labGatePromptVersion()` pasa a `motor-S2-c5-…`. Los veredictos con `motor-S2-c4` (la línea base P0 sobre la v3) dejan de contar para la puerta, y eso es lo deseado. Además, NB2 ata el veredicto a la huella.

**Pruebas ejecutadas (A):** `tests/Unit/Marketing/Ultron/Prompts` → `OK (876 tests, 37403 assertions)` · `UltronA4NotasDelServidorTest` → `OK (5 tests, 92 assertions)` · `UltronCheckCommitParityTest` → `OK (45 tests, 307 assertions)` · `UltronDecidePromptsTest` → `OK (138 tests, 2484 assertions)` · sonda de la forma del diseño → `OK (3 tests, 12 assertions)`.

**Lo que no verifiqué (A):** la batería completa; PostgreSQL; N1 a N4 en rojo con el compilador 4 (lo hizo el lead); la sonda Legacy «79 de 80» (es de seguridad); nada con un modelo real. `UltronStudioLabEngineRunnerTest` en el árbol unido falla igual sobre `bdb93199` solo con mi arnés (`no such column: engine`): es del arnés, no de PT-17 [C/S].

## B · PT-16 (`cierre/pagos-guarda-iva` @ `e1c2af3c`)
**Veredicto: APROBADO para desplegar. Bloqueantes: 0.**

**No bloqueantes:**
- NB-B1 · Ninguna prueba confirmada cubre «V2 encendido + plan legacy (o `tax_on_top` apagado, o emisor no responsable) + importe distinto → 422». Hoy lo hace la regla 2 sola, y mi sonda lo confirma [C]. → tester.
- NB-B2 · El comentario «con las mismas salidas» (`PaymentController.php:365-369`) no es exacto con V2 encendido: todo se sigue rechazando, pero no igual [L/C].
  - Cambia el texto del 422.
  - Los errores de `starts_on`, de `invoice_email` y el 403 por fecha anterior (`:607-609`) salen ahora antes que el del importe.
  - Un plan facturable sin tarifa devuelve 422 `{message}` (`PricingException`) en vez de `errors.amount`.
- NB-B3 · PREEXISTENTE, no lo introduce PT-16. Con V2 apagado (producción), `amount_override=true` sin `override_reason` acepta cualquier importe: 1 peso por un plan de 70 000, con 201 [C, matriz]. El docblock (`:361-363`) dice que esa vía exige justificación. → security, fuera de este cierre.

**1. Con V2 APAGADO, ¿es byte a byte lo de antes?** Sí. La única línea nueva es un `return` condicionado al flag (`:381-383`); con el flag en false sigue exactamente el código anterior [L]. La sonda recorre 2 240 casos, 1 120 con V2 apagado: los 4 planes de la sonda (legacy 19 %, `base_plus_tax` 19 %, sin tarifa, precio 0) y sin plan, 14 importes, 4 formas de `amount_override`/motivo, y `tax_on_top` y responsable de IVA encendidos y apagados. Hay 0 discrepancias con la regla 1 del padre, calculada aparte [C, `b/ZzRevisorPt16SondaTest.php`]. `MixedPaymentTest`, que corre con V2 apagado, da 11 en verde [C].

**2. Con V2 ENCENDIDO, ¿es al menos tan estricta en TODOS los casos?** Sí.
- La regla 2 cotiza un bruto mayor o igual que el precio, y es igual al precio salvo con `base_plus_tax` + `tax_on_top` + tarifa > 0 (`PricingService.php:172-178` y `:257-261`). La tolerancia es la misma (0,5), con las mismas salidas tempranas: sin plan, precio ≤ 0, `override`, que exige motivo (`:1368-1405`) [L].
- En la matriz, las únicas filas rechazadas antes y aceptadas ahora son 4: `base_plus_tax` 19 % con `tax_on_top` y emisor responsable, importe 95 200 o 95 200,4 sin `override`. Es justo el caso que se quería arreglar, y no en silencio: se guarda `amount = gross = 95 200` con su desglose [C].
- Legacy fuera de tolerancia aceptado sin motivo: ninguno. Sin plan y con precio 0: siempre 201, antes y ahora. Plan sin tarifa: se rechazaba y se sigue rechazando [C].
- Entre las dos comprobaciones no se escribe nada en la base: `assertStartsOn` y `extractInvoiceRequest` solo validan y tocan `$data` [L]. Además, 0 filas escritas en cualquier rechazo [C]. `update()` no llama a ninguna de las dos reglas [L: grep].

**3. ¿Vaciar `tests/baseline-failures.txt` rompe algo?** No. Solo lo lee `bin/suite` (`:20` y `:53-55`) [C: git grep sin `.md`]. Con el fichero vacío (blob `e69de29b`, que sigue confirmado), `is_file` da true y `file()` devuelve `[]` [C: php -r]: 0 tolerados y cualquier fallo es una regresión (sale 1). Es lo esperado.

**4. ¿Alguien depende del texto del 422 de la regla 1?** No: ni el código, ni las pruebas, ni `frontend/`, ni `n8n/`, ni `receipt-agent/` [C: git grep]. Solo lo citan documentos y evidencias históricas. Las pruebas comprueban el campo (`assertJsonValidationErrors('amount')`), no el texto.

**Pruebas ejecutadas (B):** `PricingV2SalesAndPaymentsTest` → `OK (13 tests, 50 assertions)` · `MixedPaymentTest` → `OK (11 tests, 60 assertions)` · `tests/Feature/Payments` → `OK (142 tests, 1235 assertions)` · sonda de la matriz → `OK (1 test, 1 assertion)`, 2 240 casos analizados.

**Lo que no verifiqué (B):** PostgreSQL; la batería completa; el camino del token de automatizaciones (es el mismo `store()` [L]).

Nota: `git merge-tree --write-tree` dejó objetos sueltos en la base de objetos de git, sin ninguna referencia y sin tocar los árboles de trabajo.
