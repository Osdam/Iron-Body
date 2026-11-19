Eres el agente `architect` de ULTRON STUDIO (Iron Body: API Laravel 12 / PHP 8.3 / PostgreSQL 14; n8n; WhatsApp). NO implementas: entregas un plan de implementación verificable contra el código real, con rutas y líneas. Todo en español, con el estilo de la casa. Una sola pasada.

## El encargo, en una frase

Diseñar «REGENERAR ANTES DE SUSTITUIR» para el motor de WhatsApp (ULTRON): cuando una protección del servidor o el revisor rechazan el borrador del modelo por algo corregible, el modelo recibe el defecto exacto y reescribe (como mucho DOS reintentos adicionales); el texto fijo del servidor queda como última red. Y que la respuesta de planes y precios la redacte el modelo con datos estructurados del catálogo que Laravel resuelve y verifica.

## Quién decide y con qué condiciones

El propietario lo decidió el 19-11-2026 con diez condiciones que son el criterio de aceptación. Están literales en `backend/docs/ultron-studio/MATRIZ_CIERRE_V2.md`, §5: LÉELAS PRIMERO. Las que más te atan: la IA es el generador principal; máximo dos reintentos adicionales; respaldo solo si fallan o una restricción lo exige; el backend es la fuente de verdad de planes, precios y beneficios (nada inventado ni escrito a mano); los 106 textos fijos se conservan como respaldo con su variante de usted; métricas que distingan modelo, regeneradas y respaldos (objetivo 90 % por IA, y además exactitud, latencia, costo y seguridad); sin reintentos ilimitados ni costos o latencia descontrolados; pruebas y regresiones; **limitar la intervención al problema identificado: no reconstruir WhatsApp ni abrir frentes**; no desplegar todavía.

Línea base medida en producción (`backend/docs/ultron-studio/ops/evidencia-h9/2026-11-18/canario-a4/07-origen-de-las-respuestas.txt`): de 88 respuestas, 81 % del modelo, 16 % respaldo curado tras dos rechazos del revisor (una sola reescritura, que rescató 11 de 23), 3 % texto del servidor por la política.

## Dónde estás

Repositorio `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body` (código en `backend/`), rama `feat/ultron-studio-h9`, HEAD `a6e2ccdf`. La rama va OCHO commits por delante de `origin/main` (`bc5167cb`) y NO está en producción (producción sirve `e861e2c8`, compilador 3). Lo que ya hay en la rama y se CONSERVA como última red (no lo rediseñes):
- `8e710104`, `3eca6206`, `a6e2005b`: el tono firmado en el token (`sp.t`), `UltronRegisterDetector` (detecta el tuteo), `UltronFormalReplies` (los textos de usted), la regla del commit «con profesional, el borrador que tutea se descarta» (salvo con enlaces o cobro), la lista de planes del catálogo (`policy_plans_overview`, `respaldoDePrecios()`), el texto curado de la intención tras un descarte por registro.
- `adb25f6d`: compilador 4 (`UltronPromptCompiler::VERSION = 4`; textos del tono y de las frases prohibidas). NO está desplegado ni publicado en ningún sitio: los textos nuevos que necesites pueden entrar EN la versión 4, sin subir a la 5.
- Texto normativo de lo anterior: «Enmienda A4-1» y «Enmienda A4-2» al final de `backend/docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md`. Informes: `backend/docs/ultron-studio/plans/informes-motores/` (`informe-backend-a4-tono-y-panorama.md` §14, `informe-backend-a4-compilador4.md`, `informe-reviewer-a4b.md` con sus dos apéndices, `informe-security-a4b.md`, `plan-arquitecto-a4-resto.md`, que es tu plan anterior).

## Hechos que ya comprobé (no los reinvestigues; contrástalos si algo no te cuadra)

1. El flujo de n8n de ULTRON solo recibe la conversación canaria: `UltronEventEmitter.php:84-87` (`not_canary_conversation`). Los clientes reales los atiende el agente Legacy de Laravel (`SalesConversationReplyService`), que NO se toca.
2. El flujo hoy es lineal y desenrollado: Normalizar → Pedir Contexto al CRM (`POST /api/internal/marketing/ai/decide`) → Elegir Prompts → Strategist → Validar Estrategia → Composer → Critic Comercial → «Critic aprueba?» → (no) Composer Reintento → Critic Comercial 2 → Preparar Commit → Ejecutar en el CRM (`POST …/ai/commit`) → «Commit OK?» → Clasificar Fallo → Rendición. UNA sola reescritura, y solo la dispara el revisor (un modelo).
3. El flujo se genera desde el repositorio: `php artisan ultron:prompts-n8n`, `app/Services/Marketing/Ultron/Prompts/UltronPromptsN8nFlow.php`, y los artefactos de `n8n/flujos/ultron-studio-s1/` (`elegir-prompts.js`, `preparar-commit.js`, `operaciones-mcp.json`, `flujo-s1.json`, `huellas.json`, `LEEME.md`, `verificar-flujo.cjs` si existe). Mira también `backend/docs/ultron-studio/ops/motores/n8n-copia-s1/LEEME.md`.
4. El modelo redacta con marcadores (`{{PLAN_NAME}}`, `{{PLAN_PRICE}}`, `{{PLAN_DURATION}}`) que el commit resuelve contra UN plan (`UltronDraftPlaceholders`): por eso un borrador solo puede citar un precio. `decide` le da el catálogo sin precio (`plansWithoutPrice()`: id, name, duration_days, benefits, tier…) y `default_plan_id`.
5. El commit comprueba, en este orden: longitud (`UltronReplyFitter`) → registro (`UltronRegisterDetector`) → `CommercialTurnPolicy::violations()` → panorama → rama de la política → añadidos de Laravel → `antesDeEnviar()`. Las herramientas (cortesía, enlaces de la app, cobro) se ejecutan ANTES de esas comprobaciones (`UltronCommitService.php`, hacia :1300-1460). Un commit rechazado por campos inesperados responde 422 (`unexpected_fields`).
6. El guion de despliegue de solo código (`backend/docs/ultron-studio/ops/hotfix-backend.sh`) rechaza cambios en rutas, `config/`, migraciones, composer y bootstrap; producción cachea rutas (`route:cache`).
7. La puerta de diff del H9 (`tests/Feature/UltronStudio/OutreachIsolationTest.php`) está en rojo mientras `app/Services/Marketing/Ultron`, `app/Http/Controllers/Api/Internal`, `routes/marketing.php` o `config/marketing.php` difieran de `origin/main`: es lo esperado hasta subir.

## Mi propuesta de partida (valídala contra el código; mejórala o tumba lo que no se sostenga, diciendo por qué)

**P-1 · El bucle vive en n8n y lo gobierna el servidor.** Entre el Composer y el Critic, n8n pregunta al servidor si aceptaría el borrador: un punto de entrada nuevo, de SOLO LECTURA y sin efectos (propongo `POST /api/internal/marketing/ai/check`), que aplica al borrador las MISMAS clases puras que el commit (longitud, registro, estructura de la respuesta de planes; ver P-3) con el MISMO token firmado de `decide`, y contesta `{ok, defects: [{code, note}], …}`. Si no pasa y queda presupuesto, n8n va al Composer de reescritura con la nota del servidor en los mismos campos que hoy usa la nota del revisor (`critic_issues` / `critic_notes`), sin gastar la llamada al revisor en un borrador ya rechazado. Alternativas que debes valorar y descartar o no: (a) un commit en dos fases que devuelva «reescribe» (me preocupa: las herramientas se ejecutan antes de las comprobaciones y el commit es idempotente por mensaje); (b) la comprobación dentro de n8n en JS con una lista que mande `decide` (duplicaría lógica). El commit sigue comprobándolo TODO como última red, sin cambios de comportamiento.

**P-2 · Presupuesto cerrado por construcción.** Como mucho 3 redacciones por turno (la original y dos reescrituras), compartidas entre el servidor y el revisor. Prefiero el flujo DESENROLLADO (nodos repetidos generados, sin bucle con contador) porque el límite queda en la estructura; dime si el generador lo aguanta o si un bucle con contador y tope en el servidor es mejor. Además: un tope de tiempo por turno (si ya se pasó, no se reescribe más) y que el servidor se niegue a pedir otra reescritura cuando el intento que le declaran ya es el último. Peor caso de llamadas al modelo por turno y de latencia: cuantifícalo frente a hoy.

**P-3 · Planes y precios: el modelo redacta, el servidor pone los datos.** Cuando la persona pide los planes o los precios en general (la regla ya existe: `CommercialTurnPolicy::pideElPanorama()`), `decide` entrega al modelo, como DATOS, los planes que el servidor elige (la misma selección autoritativa de `respaldoDePrecios()`: el que nombró primero, los de un mes por orden del catálogo, como mucho tres, nunca los rechazados) con un marcador por plan (propongo `{{PLAN:<id>}}`, que el commit expande a «Nombre, $precio COP» desde la fila del CRM: nombre y precio juntos, para que no se puedan emparejar mal). El modelo escribe la frase; Laravel resuelve y verifica: marcadores solo de planes vendibles y ofrecidos, ninguna cifra de precio escrita por el modelo, al menos dos planes si se pidió el panorama, y la longitud medida CON los marcadores expandidos. Si el borrador cumple, la violación `policy_plans_overview` no salta aunque se pregunte el precio; la lista fija queda como respaldo. La instrucción al modelo va en un parche COMPILADO y firmado (compilador 4), no en los datos: los prompts base no se tocan.

**P-4 · Beneficios.** La condición 3 pide que Laravel verifique los beneficios autorizados. Hoy los redacta el modelo desde `active_plans[].benefits` y solo existe la guarda de servicios inventados (`sinServiciosInventados`). Propón una verificación determinista, derivada del catálogo y sin texto escrito a mano, que NO convierta la respuesta en un formulario: por ejemplo, que a un plan no se le atribuya un beneficio que el catálogo solo da a otros planes. Si no hay una forma sólida y acotada, dilo con claridad y propón qué se deja como límite declarado.

**P-5 · Qué es «corregible» en esta intervención.** Solo tres causas van a reescritura guiada por el servidor: el registro (tuteo con «profesional»), la longitud (hoy se recorta la pregunta final o se descarta) y la estructura de la respuesta de planes. Más lo que ya rechaza el revisor. El mecanismo debe ser genérico (código de defecto → nota), pero NO amplíes la lista: el resto de violaciones de la política, el guard de contenido, el no contactar, el relevo y las herramientas siguen exactamente como están.

**P-6 · Sin versión del Studio, nada cambia.** Con los prompts Legacy (sin `sp` de `studio`) el flujo hace lo de hoy: una reescritura del revisor y ninguna pregunta al servidor (o una que contesta «pasa» sin mirar; elige y justifica). Las dos instantáneas de Legacy (`tests/fixtures/ultron-a4/`) no se regeneran.

**P-7 · Métricas.** Cada turno deja en su traza (`marketing_ai_actions.metadata`, aditivo) quién escribió lo que salió: `modelo al primer intento`, `modelo tras 1 o 2 reescrituras` (y quién las pidió: servidor o revisor, con el código del defecto) o `respaldo` (y por qué). Más los intentos, la latencia del turno y, si n8n lo tiene, los tokens. Un comando de consola de solo lectura que lo resuma por periodo y conversación (porcentaje por IA frente al objetivo del 90 %, reescrituras por causa, respaldos por causa, latencia media y alta, costo estimado), y cómo encaja con `ultron:canary-report`. Sin tocar el CRM.

**P-8 · Seguridad.** El punto de entrada nuevo usa la misma autenticación interna y el mismo token firmado que `decide`/`commit`; no ejecuta herramientas, no escribe, no devuelve datos de personas. Dime qué superficie abre y qué pruebas la cierran.

## Lo que tienes que entregar

Un documento: `backend/docs/ultron-studio/plans/informes-motores/plan-arquitecto-a4-regenerar.md` (es lo ÚNICO que escribes), con:

1. **Veredicto sobre cada propuesta P-1 a P-8**: se sostiene / se sostiene con cambios / no, con la evidencia del código (fichero:línea).
2. **El contrato exacto**: petición y respuesta del punto de entrada nuevo; qué añade `decide` al contexto; qué añade el commit a su entrada (campos nuevos y cómo evita el 422 de `unexpected_fields`) y a su traza; los códigos de defecto y sus notas (textos que cruzan al modelo: son TEXTOS FIJOS que aprobará el propietario; propónlos literales, sin tildes si van al modelo como los demás).
3. **El flujo de n8n**: diagrama de nodos antes y después, qué genera el generador, cómo se comprueba sin tocar el n8n en marcha, y cómo se vuelve atrás.
4. **Cambios por fichero**, en olas que se puedan implementar y probar por separado y EN SECUENCIA por un solo agente cada vez (no se trabaja en paralelo sobre el mismo árbol): qué ficheros, qué funciones, qué pruebas nuevas (rojas antes), qué pruebas existentes cambian y por qué.
5. **Los textos fijos nuevos** que hay que aprobar (instrucciones al modelo del parche, notas de reescritura), literales.
6. **Costo y latencia**: llamadas al modelo por turno hoy y después (caso típico y peor caso), y los topes.
7. **Despliegue y vuelta atrás** (solo el plan; NO se despliega): qué necesita el despliegue por la ruta nueva, en qué orden (servidor antes que n8n, o al revés, y por qué), y qué queda inerte hasta publicar una versión.
8. **Riesgos y lo que NO cubre**, y qué partes recomiendas dejar fuera para respetar la condición 8.
9. **Criterios de aceptación medibles** para la tercera prueba real y para el informe de métricas.

## Reglas

- Solo lectura: puedes leer, buscar y ejecutar órdenes que no escriban (git log/diff/show, grep, php -l, `php artisan route:list` si no escribe). NO ejecutes PHPUnit ni baterías. Nada de git que escriba, nada de producción, nada de n8n (ni MCP ni API).
- No toques el diseño de lo que ya está en la rama salvo donde esta intervención lo exija (di exactamente dónde).
- Si una condición del propietario no se puede cumplir tal cual, dilo en el punto 8 con la alternativa: no la rebajes en silencio.
- Separa lo comprobado en el código [C] de lo supuesto [S].

Respuesta final a mí: el veredicto en diez líneas y la lista de olas.
