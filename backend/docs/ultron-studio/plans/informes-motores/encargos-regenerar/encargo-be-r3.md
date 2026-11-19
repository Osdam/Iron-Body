Eres el agente `backend` de ULTRON STUDIO (Iron Body: API Laravel 12 / PHP 8.3 / PostgreSQL 14). Implementas, con pruebas, la OLA 4 de «regenerar antes de sustituir» en el motor de WhatsApp (ULTRON): el punto de comprobación del servidor, el presupuesto de reescrituras y la traza. Todo en español, con el estilo de la casa. Eres el único agente trabajando en este árbol.

## De qué va (para situarte)

Hoy, cuando el servidor rechaza el borrador del modelo (porque tutea con una versión «profesional», porque se pasa de longitud…), sale un texto fijo. El propietario decidió (19-11) que antes el modelo reescriba con el defecto exacto, como mucho dos veces más. El modelo vive en n8n; por eso n8n le va a PREGUNTAR al servidor, entre el redactor y el revisor, si aceptaría el borrador. Tú construyes esa pregunta en el servidor. Sus diez condiciones, literales: `docs/ultron-studio/MATRIZ_CIERRE_V2.md` §5 (las tuyas: 2, 5, 6 y 7).

## El plan manda

`docs/ultron-studio/plans/informes-motores/plan-arquitecto-a4-regenerar.md`. Lee ENTEROS: §1 (H7, H8, H9, H12, H13, H18), §3 P-1, P-2, P-5, P-6, P-7 y P-8, §4 (el contrato `ultron-regen/1`: §4.1, §4.2, §4.3 y §4.4), §6 «Ola 4», §12 y §13 (decisiones del lead, que mandan). Lo que hicieron los agentes anteriores: `docs/ultron-studio/plans/informes-motores/informe-backend-a4-regenerar.md` (olas 1, 2 y 3). Las clases puras ya existen (`UltronDraftDefects`, `UltronRegenerationNotes`, `UltronPlansOverview`, `UltronBenefitAttribution`, `UltronDecideToken::peek()`): ÚSALAS, no dupliques su lógica.

Árbol: `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`. Mira `git log --oneline -4` y `git status`: árbol limpio, con las olas 1 a 3 ya confirmadas. Nada de esta rama está desplegado.

## Qué haces (plan §6, Ola 4)

1. **La puerta nueva, de SOLO LECTURA:** `POST /api/internal/marketing/ai/check` (ruta en `routes/marketing.php`, dentro del grupo de las otras; `UltronController::check()`; servicio nuevo `UltronDraftCheck`). Petición, proceso, respuestas y errores EXACTOS de §4.1. No toma cerrojo, no escribe, no despacha nada, no llama a la red; solo lee. Del token usa `peek()`. Sin versión del Studio contesta `mode: legacy`.
2. **El presupuesto:** `may_rewrite = attempt < 3 && (ahora − emisión del token) ≤ 25 s` (`TIME_BUDGET_SECONDS = 25`, una constante, sin `config/`); `budget.reason` según §4.1.
3. **`decide`:** el bloque `regeneration` en la raíz de la respuesta, solo con versión del Studio (§4.2).
4. **`commit`:** acepta el bloque `regeneration` (reglas de §4.3; es TRAZA: nada se ramifica por él) y `critic.attempt` hasta 3 (`UltronController.php` y `CriticContract::forMetadata()`); la fila lleva `metadata.regeneration` (escrito en `persist()`, con `consistent`); clase nueva `UltronRegenerationTrace` (bloque → metadata).

## Una decisión del lead que corrige una laguna del plan (§4.1, paso 4a)

El agente de la ola 1 la encontró: un marcador `{{PLAN_k}}` con `k` mayor que el número de planes de la lista (o cualquier `PLAN_k` en un turno sin lista o de cobro) hace que el commit rechace el turno con 422. La comprobación lo señala SIEMPRE que aparezca (`plans_overview/unknown_marker` o `plans_overview/no_list`), se pida o no el panorama y haya o no otros marcadores válidos: es justo el tipo de defecto que una reescritura arregla.

## Invariantes

- Sin versión del Studio no cambia NI UN BYTE de lo que sale ni de la fila: las dos instantáneas (`tests/fixtures/ultron-a4/`) siguen en verde SIN regenerarse, y un commit sin el bloque deja la fila idéntica a la de hoy.
- El commit NO lee el resultado de la comprobación: vuelve a comprobarlo todo, como hoy. La comprobación es un aviso.
- Ni la respuesta de la puerta ni el log llevan texto de la persona, el token ni datos personales: solo códigos, números, marcas de la lista cerrada del detector y beneficios del catálogo.

## Pruebas (primero ROJAS; guarda la salida)

Las de la «Ola 4» del plan, en particular:
- `tests/Feature/Marketing/UltronCheckEndpointTest.php`: cada fila de la tabla de seguridad de §3 P-8 (401 sin secreto; token de otra conversación, de otro mensaje, caducado, con la firma alterada o mal formado → 422 `invalid_token` sin detalle; clave de más → 422 `unexpected_fields`; solo `select` en la base, ningún cerrojo, ninguna petición HTTP, cero filas nuevas; un entrante con un teléfono y un marcador únicos que no aparecen ni en la respuesta ni en el log); cada código con su nota literal; `attempt` 3 → `last_attempt`; 26 s → `time_budget` (ojo: `Carbon::setTestNow` EN UTC, trampa conocida de la suite); token sin Studio → `legacy`.
- `tests/Feature/Marketing/UltronCheckCommitParityTest.php`: sobre el mismo turno y una matriz de borradores, la comprobación marca `register_tuteo`, `length_over_cap`, `plans_overview` o `benefit_misattributed` SI Y SOLO SI el commit descarta, recorta o viola por esa causa. Las excepciones (el commit pega enlaces o el cobro, o retira frases, antes de medir: hecho H7 del plan) van documentadas una a una en la propia prueba.
- Commit con el bloque (la fila lo lleva), sin él (fila idéntica a la de hoy) y con un bloque incoherente (solo `consistent: false`: mismo texto y mismos efectos); `critic.attempt` 3 aceptado.
- `InternalSecretAuthoritySurfaceTest`: la tercera puerta, de solo lectura.

Desde `backend/`, UNA ejecución de PHPUnit cada vez: `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging <ficheros>`. Al final: `tests/Unit/Marketing/Ultron`, los A4, `UltronDecideTest`, `UltronDecidePromptsTest`, `UltronCommitTest`, `UltronCommitPromptsTest`, las dos nuevas; `tests/Feature/Marketing tests/Unit/Marketing` enteros; las nuevas y los A4 en PostgreSQL (`UNIT_DB=ultron_studio_lead_ola5 nice -n 10 bash docs/ultron-studio/ops/pgunit.sh --no-logging <ficheros>`); las guardas (`bash -c 'G=$(find tests -name "*Isolation*Test.php" -o -name "*ReadOnly*Test.php" -o -name "*StaticGuards*Test.php" -o -name "SecretExposure*Test.php" | sort | tr "\n" " "); nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging $G'`: se espera UNA roja, la puerta de diff del H9, hasta subir; si aparece OTRA guarda en rojo por la ruta o el controlador nuevos, PARA y dímelo: no la relajes); `php artisan route:list --path=internal/marketing/ai` (pega la salida en el informe); `vendor/bin/pint` sobre lo tocado.

## Límites

- NO hagas: las violaciones de política como defecto corregible (es la ola 4b, de otro agente), el informe de origen (ola 6), ni nada de n8n (ola 5). No toques el compilador, los prompts base, `config/`, migraciones, `SalesConversationReplyService.php`, `SalesObjectionResponderService.php`, el guard, `CourtesyRequestService.php` ni el CRM.
- Si un fixture generado de `n8n/flujos/ultron-studio-s1/` cambia porque `decide` ahora devuelve `regeneration`, regenéralo con `php artisan ultron:prompts-n8n` y dímelo; los tres ficheros que se ejecutan en n8n (`elegir-prompts.js`, `preparar-commit.js`, `operaciones-mcp.json`) no pueden cambiar ni un byte.
- Nada de git que escriba, nada de producción. No relajes ninguna prueba: cada aserción existente que cambie, al informe con su porqué. Si algo del plan no encaja con el código, PARA y dímelo con la línea.

## Entregable

Código y pruebas en el árbol, SIN commit. Una sección nueva en `docs/ultron-studio/plans/informes-motores/informe-backend-a4-regenerar.md` («4. Ola 4»): ficheros tocados, el contrato tal como quedó (petición y respuesta reales de un caso que pasa, uno que pide reescritura y uno sin presupuesto), la tabla de paridad con sus excepciones, decisiones tuyas, aserciones cambiadas, los números y lo que no pudiste comprobar. Salidas de PHPUnit en `a4-evidencia/` con el número siguiente al último que haya. Respuesta final a mí en pocas líneas, con los números y los ficheros de producto tocados.
