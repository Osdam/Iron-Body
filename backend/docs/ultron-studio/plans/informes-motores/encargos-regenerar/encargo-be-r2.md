Eres el agente `backend` de ULTRON STUDIO (Iron Body: API Laravel 12 / PHP 8.3 / PostgreSQL 14). Implementas, con pruebas, la OLA 3 de «regenerar antes de sustituir» en el motor de WhatsApp (ULTRON): la lista de planes y precios la redacta el modelo con marcadores, y Laravel resuelve y verifica. Todo en español, con el estilo de la casa. Eres el único agente trabajando en este árbol.

## De qué va (para situarte)

Hoy, a «¿Qué planes tienen y cuánto cuestan?» el motor contesta con una lista fija armada por el servidor, porque un borrador del modelo solo puede cotizar UN precio (un único marcador `{{PLAN_PRICE}}` resuelto contra un plan). El propietario decidió (19-11) que la IA sea el generador principal: la frase la escribe el modelo y los datos los pone el servidor. Sus diez condiciones, literales: `docs/ultron-studio/MATRIZ_CIERRE_V2.md` §5 (la 3 es la tuya).

## El plan manda

`docs/ultron-studio/plans/informes-motores/plan-arquitecto-a4-regenerar.md`. Lee ENTEROS: §1 (hechos H7, H9, H10, H11, H14), §3 P-3 (los siete cambios) y P-4, §4.2, §4.3 (solo la parte de la traza `metadata.plans_overview` y `metadata.benefits`), §4.4, §6 «Ola 3», §7.3 y §13 (decisiones del lead, que mandan). Lo que hizo el agente anterior (olas 1 y 2: las clases puras `UltronPlansOverview`, `UltronBenefitAttribution`, `UltronRegenerationNotes`, `UltronDraftDefects`, `UltronDecideToken::peek()`, y el parche del motor del compilador 4 con `sp.o`): `docs/ultron-studio/plans/informes-motores/informe-backend-a4-regenerar.md`, secciones 1 y 2. ÚSALAS: no dupliques su lógica.

Árbol: `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`. Mira `git log --oneline -3` y `git status`: árbol limpio, con las olas 1 y 2 ya confirmadas. Nada de esta rama está desplegado.

## Qué haces (plan §6, Ola 3)

1. **`decide`** (`UltronDecideService.php`), solo con versión del Studio y `sp.o = 1`: cuando la persona pide los planes o los precios en general (`CommercialTurnPolicy::pideElPanorama()` con la política de `decide`) y `UltronPlansOverview::select()` da al menos dos planes, añade `context.commercial_turn_policy.plans_overview` (§4.2: los marcadores, `plan_id`, nombre y duración; SIN precio, como siempre) y firma `po` (los id, en orden) en la carga del token.
2. **Marcadores** (`UltronDraftPlaceholders.php`): `PLAN_1`…`PLAN_n` admitidos solo cuando el turno trae lista (`n > 0`); la llamada de hoy, idéntica. Sin lista (o en un turno de cobro) son marcadores desconocidos, como cualquier marcador inventado hoy.
3. **`commit`** (`UltronCommitService.php`): la expansión en el punto exacto que fija el plan (P-3, cambio 4), una sola vez, con nombre y precio leídos de `Plan` EN ESE MOMENTO; «lista válida» según P-3 (al menos dos marcadores distintos, sin mezclar con `PLAN_NAME`/`PLAN_PRICE`/`PLAN_DURATION`, sin repetir, sin el nombre escrito a mano y con todos los planes todavía vendibles); la regla del panorama: con lista válida, `policy_plans_overview` NO salta aunque se pregunte el precio, y si no es válida salta como hoy y sale la lista fija; nunca lista en un turno de cobro (P-3, cambio 5); la memoria anota el precio entregado de cada plan con lo que ya existe (P-3, cambio 6); la violación nueva `policy_benefit_misattributed` (P-4) solo con versión del Studio, por la rama de la política y con el respaldo de la intención; la traza aditiva `metadata.plans_overview` y `metadata.benefits`.

Invariantes:
- Sin versión del Studio no cambia NI UN BYTE. Las dos instantáneas (`tests/fixtures/ultron-a4/legacy-a4.json` y `legacy-usted.json`) siguen en verde SIN regenerarse.
- Con versión del Studio pero sin lista en el turno, todo sale como hoy (las pruebas existentes de `UltronA4LongitudYPreciosTest` y `UltronA4RegistroYPanoramaTest` no cambian; si alguna cambia, PARA y dímelo antes de tocarla).
- Ningún precio escrito a mano en el código ni en las pruebas más allá de los catálogos de prueba: `UltronCatalogStaticGuardsTest` sigue en verde.
- La longitud y el registro se miden sobre el texto YA EXPANDIDO (sale solo si expandes donde dice el plan).

## Pruebas

Nuevas, por el endpoint (`decide` → `commit`), con clases reales y primero ROJAS (guarda la salida): las de la «Ola 3» del plan, en un fichero nuevo `tests/Feature/Marketing/UltronA4ListaDelModeloTest.php` (usa el andamio de `UltronA4RegistroYPanoramaTest`): la lista del modelo con dos y con tres marcadores a «¿Qué planes tienen y cuánto cuesta la mensualidad?» (sin `policy_plans_overview`, con nombres y precios de `Plan` en ese momento, la longitud medida expandida, la memoria con los precios entregados de cada plan); el precio cambiado entre `decide` y `commit` (sale el nuevo); un plan que deja de ser vendible entre los dos (lista fija); `PLAN_4` con tres ofrecidos y `PLAN_1` sin lista (marcador desconocido); un turno Legacy con `PLAN_1` (422 como hoy); nombre escrito a mano, mezcla y repetido (lista fija); un turno de cobro; un beneficio de otro plan (rama de la política); y los tres tonos (`cercano`, `entusiasta`, `profesional`: con «profesional», una lista del modelo que tutea se descarta como cualquier borrador).

Desde `backend/`, UNA ejecución de PHPUnit cada vez: `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging <ficheros>`. Al final: `tests/Unit/Marketing/Ultron`, los A4 (los dos de antes y el nuevo), `UltronDecideTest`, `UltronDecidePromptsTest`, `UltronCommitTest`, `UltronCommitPromptsTest`; `tests/Feature/Marketing tests/Unit/Marketing` enteros; los A4 en PostgreSQL (`UNIT_DB=ultron_studio_lead_ola5 nice -n 10 bash docs/ultron-studio/ops/pgunit.sh --no-logging <ficheros>`); las guardas (se espera UNA roja: la puerta de diff del H9, hasta subir); `vendor/bin/pint` sobre lo tocado.

## Límites

- NO toques: `UltronController.php`, rutas, `config/`, migraciones, el compilador, `SalesConversationReplyService.php`, `SalesObjectionResponderService.php`, el guard, `CourtesyRequestService.php`, los prompts base, el CRM ni el n8n (ni sus ficheros generados: si un fixture generado de `n8n/flujos/ultron-studio-s1/` cambia por tu cambio en `decide`, regenéralo con `php artisan ultron:prompts-n8n` y dímelo; los tres ficheros que se ejecutan en n8n no pueden cambiar).
- El punto de comprobación `ai/check`, el presupuesto de reescrituras y la traza `metadata.regeneration` son de la ola siguiente: no los hagas.
- Nada de git que escriba, nada de producción. No relajes ninguna prueba. Si algo del plan no encaja con el código, PARA y dímelo con la línea.

## Entregable

Código y pruebas en el árbol, SIN commit. Una sección nueva en `docs/ultron-studio/plans/informes-motores/informe-backend-a4-regenerar.md` («3. Ola 3»): ficheros tocados, dónde quedó la expansión y por qué, decisiones tuyas, aserciones cambiadas, los números (rojo antes, verde después, Marketing, PostgreSQL, guardas) y lo que no pudiste comprobar. Salidas de PHPUnit en `a4-evidencia/` con el número siguiente al último que haya. Respuesta final a mí en pocas líneas, con los números y los ficheros de producto tocados.
