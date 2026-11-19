Eres el agente `backend` de ULTRON STUDIO (Iron Body: API Laravel 12 / PHP 8.3 / PostgreSQL 14). Implementas, con pruebas, las OLAS 1 y 2 de «regenerar antes de sustituir» en el motor de WhatsApp (ULTRON). Todo en español, con el estilo de la casa. Eres el único agente trabajando en este árbol.

## De qué va (para situarte)

Hoy, cuando una protección del servidor rechaza el borrador del modelo, sale un texto fijo. El propietario decidió (19-11) que antes se le pida al modelo una reescritura con el defecto exacto (como mucho dos veces más) y que la respuesta de planes y precios la redacte el modelo con marcadores que Laravel resuelve desde el catálogo. Sus diez condiciones, literales: `docs/ultron-studio/MATRIZ_CIERRE_V2.md` §5.

## El plan manda

`docs/ultron-studio/plans/informes-motores/plan-arquitecto-a4-regenerar.md`. Lee ENTEROS: §0, §1 (los hechos H1 a H18), §3 (P-1 a P-5), §4 (el contrato), §6 «Ola 1» y «Ola 2», §7 (los textos fijos) y §13 (las decisiones del lead, que mandan sobre lo anterior). Tú haces SOLO las olas 1 y 2; las siguientes las hace otro agente después.

Árbol: `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `97b59195`, árbol limpio. Nada de esta rama está desplegado.

## Ola 1 · Piezas puras, sin cablear (plan §6, Ola 1)

Clases nuevas en `app/Services/Marketing/Ultron/`, todas PURAS (sin base de datos, config, reloj ni log; lo que necesiten se lo pasa quien llama) y sin `\p{…}` en ninguna expresión regular (el PCRE de producción es el 10.39):

- `UltronPlansOverview`: `select()` (la selección de planes EXTRAÍDA tal cual de `UltronCommitService::respaldoDePrecios()`, que pasa a llamarla SIN cambiar su comportamiento ni una letra de lo que sale), los marcadores `{{PLAN_1}}` a `{{PLAN_3}}`, la expansión a «Nombre ($65.000 COP)» (y «Nombre (7 días, $45.000 COP)» si el plan no es de 28 a 31 días; «1 día» en singular; el precio con el formateador que ya usa la lista fija), y `verify()` con los seis detalles de §4.4 (`missing`, `mixed`, `name_written`, `unknown_marker`, `no_list`, `price_written`).
- `UltronBenefitAttribution` (P-4): la regla literal y por frase del plan §3 P-4, con sus límites declarados.
- `UltronRegenerationNotes`: los códigos cerrados (`register_tuteo`, `length_over_cap`, `plans_overview` con sus detalles, `benefit_misattributed`), el `issue` de cada uno dentro del vocabulario de `CriticContract::DIMENSIONS`, las notas LITERALES de §7.2 (N-R, N-L, N-P1 a N-P6, N-B; son textos fijos que aprobará el propietario: no los retoques) y la mezcla a 500 caracteres por notas enteras. Deja el mecanismo genérico (código → issue + nota): otro agente añadirá después cinco códigos de política; no los añadas tú.
- `UltronDraftDefects`: dado el borrador sin expandir, el expandido, el `sp` firmado, la lista `po`, el catálogo y el entrante, la lista ordenada de defectos (orden de §4.1, paso 4).
- `UltronDecideToken::peek()`: firma, caducidad y que conversación y mensaje coincidan; devuelve la carga firmada y el instante de emisión; no recalcula fase, transiciones ni conocimiento (plan §3 P-1, cambio 1). `verify()` no cambia.

Pruebas nuevas (unitarias, primero rojas): las de la «Ola 1» del plan, incluida la PARIDAD de `select()` con la selección de hoy sobre una matriz de catálogos, y la prueba de que ninguna nota contiene `{{` ni expresión nueva alguna lleva `\p{`.

Ninguna prueba existente puede cambiar en esta ola: `UltronA4LongitudYPreciosTest`, `UltronA4RegistroYPanoramaTest` y los dos documentos generados (`a4-textos-ajustados.md`, `a4-textos-usted.md`) quedan idénticos.

## Ola 2 · Compilador 4: el parche del motor y `sp.o` (plan §6, Ola 2)

- `Prompts/UltronPromptCompiler.php`: el parche del MOTOR para la lista de planes (`T_PLANS_COMPOSER` y `T_PLANS_CRITIC`, literales de §7.1), añadido al FINAL y solo si la versión ya tiene algún parche gobernado (con la línea base `compile()` sigue devolviendo la base byte a byte y la huella Legacy `3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902` no se mueve). `VERSION` sigue en 4: el compilador 4 no está desplegado ni publicado en ningún sitio. Amplía su docblock.
- `Prompts/UltronPromptOffer.php`: `sp.o = 1` cuando el paquete ofrecido lleva ese parche (junto a `n` y `t`, con su mismo criterio: solo en `studio`).
- `StudioPromptBundle.php` NO se toca: la vista previa del Studio sigue enseñando solo lo gobernado; fíjalo con una prueba.
- Pon al día, como se hizo al añadir los textos del tono (precedente exacto: `docs/ultron-studio/plans/informes-motores/informe-backend-a4-compilador4.md`): pruebas, `compile-cases.json` y `publication-cases-v2.json` REGENERADOS con sus derivadores, `parches-m1.cjs` y §B.3 de `docs/ultron-studio/plans/STUDIO_MOTORES_PLAN.md` (apartado «Compilador 4»: añade los dos textos, marcados pendientes de la aprobación del propietario), y los ficheros generados de `n8n/flujos/ultron-studio-s1/` con `php artisan ultron:prompts-n8n`. Los tres ficheros que se ejecutan en n8n (`elegir-prompts.js`, `preparar-commit.js`, `operaciones-mcp.json`) NO pueden cambiar ni un byte: sha256 antes y después. Di qué huellas se mueven (el plan prevé: todas las no base).
- Comprueba los topes: cada parche ≤ el máximo por parche, cada rol ≤ 40 960 y el paquete ≤ 163 840 bytes, en el peor caso (con el tono, el tope, los emojis, la cita y 12 frases prohibidas). Si no cabe, PARA y dímelo.

## Límites

- NO toques en estas olas: `UltronCommitService.php` salvo la extracción de `select()` (sin cambio de comportamiento), `UltronDecideService.php`, `UltronController.php`, rutas, `config/`, migraciones, el CRM, los prompts base de `resources/`, `SalesConversationReplyService.php`, `SalesObjectionResponderService.php`, el guard, ni el n8n en marcha (nada de MCP ni API de n8n).
- Nada de git que escriba, nada de producción. No relajes ninguna prueba: cada aserción existente que cambie, al informe con su porqué.
- Si algo del plan no encaja con el código, PARA y dímelo con la línea: no lo resuelvas por tu cuenta.

## Pruebas (desde `backend/`, UNA ejecución de PHPUnit cada vez)

`nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging <ficheros>`. Primero las nuevas en rojo (guarda la salida). Al final: `tests/Unit/Marketing/Ultron`, los dos A4, `UltronDecidePromptsTest`, `UltronCommitTest`, `UltronCommitPromptsTest`, `UltronPromptsTraceCommandTest`; `tests/Feature/UltronStudio tests/Unit/UltronStudio` enteros; `tests/Feature/Marketing tests/Unit/Marketing` enteros; las guardas (`bash -c 'G=$(find tests -name "*Isolation*Test.php" -o -name "*ReadOnly*Test.php" -o -name "*StaticGuards*Test.php" -o -name "SecretExposure*Test.php" | sort | tr "\n" " "); nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging $G'`: se espera UNA roja, la puerta de diff del H9, hasta subir); `vendor/bin/pint` sobre lo tocado. Las dos instantáneas de Legacy (`tests/fixtures/ultron-a4/`) siguen en verde SIN regenerarse.

## Entregable

Código y pruebas en el árbol, SIN commit. Un informe nuevo: `docs/ultron-studio/plans/informes-motores/informe-backend-a4-regenerar.md`, con una sección por ola («1. Ola 1» y «2. Ola 2»): ficheros tocados, qué hace cada pieza, decisiones tuyas, aserciones cambiadas, las huellas que se mueven, los números (rojo antes, verde después) y lo que no pudiste comprobar. Salidas de PHPUnit en `docs/ultron-studio/plans/informes-motores/a4-evidencia/` con los números 88 en adelante. Respuesta final a mí en pocas líneas, con los números y la lista de ficheros de producto tocados.
