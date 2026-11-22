# Backend · A4, turno 2 de la tercera prueba real: correcciones (1) y (1b) del debugger

**Base:** árbol `cierre-a4n`, rama `cierre/a4-notas`, sale de `320caa3d` (lo que sirve producción). **Sin desplegar**, sin red, sin ssh, sin modelos. Rutas relativas a `backend/`. **Marcas:** [C] comprobado ejecutando · [L] leído · [H] hipótesis. Causa raíz: `informe-debugger-a4-turno2.md` (no se reinvestiga).

## Qué cambió
- **(1) Notas con el esqueleto y el presupuesto** (`71b10fff`):
  - `UltronRegenerationNotes.php:169-180`: tres variantes nuevas (N-P1, N-P2 y N-L) en `CON_PRESUPUESTO`; `budgetNote()` :260, el relleno común `rellena()` :281 y `budgetEntries()` :349. Las 15 notas de siempre, **byte a byte** (incluida la N-L sin lista, que es la del fixture `n8n/…/check-reescribe.json`).
  - `UltronDraftDefects.php:103-110`: con la lista **y** el tope firmados, `of()` pide la variante (:127 y :145); sin una de las dos, las de hoy. El nuevo parámetro `$listTexts` es opcional (por defecto `[]`).
  - **El único sitio de la cifra:** `UltronDraftDefects::listBudget()` :255.
  - `UltronDraftCheck.php:296`: `textosDeLaLista()` lee en **una** consulta los `item()` de toda la lista ofrecida, para expandir y para el presupuesto. Antes leía solo los planes nombrados. El texto expandido es el mismo.
- **(1b) La causa determinista en el respaldo** (`fbaee580`):
  - `UltronDraftCheck::inspect()` :177: lo que `check()` le encuentra a un borrador, con el `sp` y la `po` firmados. `check()` la usa (:128) sin cambiar nada.
  - `UltronCommitService.php:194`: nueva dependencia inyectada. En `handleCriticFailure`, la fila lleva `metadata.discarded_draft_check = {pass, codes, length}`, solo con versión del Studio y borrador no vacío (:2229, `causaDeterminista()` :2258).
    - Es traza: no cambia ni el texto que sale ni el camino. Si la lectura falla, la fila sale sin la clave y queda el aviso `ultron.commit.discarded_draft_check_failed`. El commit no abre transacción de BD (usa el cerrojo de caché): la trampa de PostgreSQL no aplica [L].
  - `UltronReplyOrigin.php:99` y :194: añade `server_verdict` (`server_would_accept`, `server_would_reject` o `unknown`) y `server_codes`, solo del vocabulario cerrado (`comprobacionDelDescartado()` :295). Las filas antiguas dan `unknown`.
  - `UltronOriginReport.php:254`: añade `fallbacks.critic_server` al JSON. En el texto (:469, :521), «el revisor rechazó el último borrador: N (el servidor lo habría aceptado: a · el servidor tampoco lo habría aceptado: b (códigos) · sin dato: c)». **Cambia el rótulo** «rechazó todos los intentos», que era falso con S2.
- `de0513ed`: la prueba nueva compara la metadata sin depender del orden de claves (`jsonb` las reordena en PostgreSQL). Es solo de prueba.
- Sin migraciones ni variables nuevas. `ai/check`: mismos códigos, la misma forma y el mismo número de intentos.

## Las notas, antes y después (texto literal; van al MODELO, nunca al cliente)
- **N-P1** `plans_overview/missing`.
  - Antes (306): «FALTA LA LISTA DE PLANES: la persona pidio los planes o los precios en general. Nombra al menos dos planes de commercial_turn_policy.plans_overview, en su orden, escribiendo solo sus marcadores PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves; el backend pone el nombre y el precio. Sin PLAN_NAME ni PLAN_PRICE.»
  - Después, con lista y tope (257 con los datos de la prueba): «FALTA LA LISTA DE PLANES: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME ni PLAN_PRICE. Ya expandidos ocupan 71 caracteres: quedan 89 de 160. Si no caben, dos; una pregunta corta o ninguna.»
- **N-P2** `plans_overview/mixed`.
  - Antes (165): «MARCADORES MEZCLADOS: con la lista de planes usa solo PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves, cada uno una sola vez, sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION.»
  - Después (228): «MARCADOR REPETIDO O MEZCLADO: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. Ya expandidos ocupan 71 caracteres: quedan 89 de 160.»
- **N-L** `length_over_cap`.
  - Antes (262 con L = 251): «DEMASIADO LARGO: el borrador mide 251 caracteres y el limite publicado es 200. Reescribelo en unos 160: primero la respuesta a lo que pregunto la persona, sin relleno, y como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye.»
  - Después, con lista (233): «DEMASIADO LARGO: mide 251 caracteres y el limite publicado es 200. Reescribelo en unos 160: primero la respuesta, sin relleno. Los marcadores de la lista ya ocupan 71: quedan 89. Si no caben, dos planes; una pregunta corta o ninguna.»
- **Plantillas:** `{K}` (planes de la lista) en lugar de «3»; `{X}`, `{R}` y `{M}` en lugar de 71, 89 y 160. Sin tope o sin lista, las de «antes». «Sin volver a nombrarlo» va como «cada uno una vez», y «si no caben tres, dos» como «si no caben, dos» (con K = 2 no hay tres).
- **Juntas, las de la prueba** (N-P2 + N-L): 462. Hoy medían 428 y N-P1 + N-L, 569, así que hoy se perdía la N-L entera [C].
- **El peor caso:** L de 6 cifras, X 999, M y R 380, N 480 y K 2 o 3.
  - N-P1 + N-L = **498** y N-P2 + N-L = **469**: las 22 combinaciones de la lista con la longitud caben **enteras** [C].
  - En las 2.735 combinaciones (un superconjunto de las reales), **ninguna** pierde una nota de más prioridad que hoy entraba.
  - Las que pierden alguna nota bajan de 2.628 a 2.623: eso ya ocurre hoy con N-R, N-B o la política.
  - Todo ASCII: el corte a 500 de n8n mide lo mismo.
- El documento que aprueba el propietario, `a4-textos-regenerar.md` §2 bis, queda regenerado por su prueba.

## Qué le revela {X} al modelo (para `security`)
- **Lo que ya ve:** los nombres y la duración de la lista, sin precios.
- **Lo que revela {X} exacto:** al restar el formato conocido «Nombre ($… COP)», le queda cuánto miden **juntas** las cifras de los precios: el orden de magnitud **agregado**. Con la prueba, 71 − 28 (nombres) − 9 (paréntesis) − 15 («$» y « COP») = 19 = «65.000» + «80.000» + «180.000». No revela ninguna cifra ni la magnitud de cada plan.
- **{R}** sale de X y de M, que el modelo ya conoce: no añade nada.
- **Cruzar listas distintas** (al nombrar un plan, la lista cambia) daría la magnitud de cada plan. Hace falta recordar X entre turnos, y la nota solo viaja en la llamada de reescritura [H].
- **Para redondear** (por ejemplo, a la decena superior) se cambia solo la línea `$x` de `listBudget()`. R se recalcula con la cifra redondeada.

## Huellas: idénticas antes y después [C]
- **Base** `3f6a5674…902` (recalculada igual) · **compilador** 4, `UltronPromptCompiler.php` con sha256 `fae2651e…7c5` · **paquetes** «profesional»/200 `26d57565…075` (el de la versión publicada) y sin emojis `561a5e64…cb7`.
- **Índice de git** sobre `resources/ultron-prompts`, `n8n` y `UltronPromptCompiler`/`Base`/`Offer`: `8f296c97…474`; `git diff 320caa3d HEAD` sobre esas rutas, vacío; `UltronPromptsN8nS2CommandTest`, verde.

## Pruebas de regresión (clases reales; cada una cae con el código de `320caa3d`, puesto con `git show` y restaurado)
1. **`UltronA4NotasDelServidorTest`, borrador 3 por el commit** (`test_el_borrador_3_sale_igual…`).
   - Con el revisor aprobando: `PRECIOS_200`, `["policy_length_exceeded","policy_plans_overview"]`, `mixed` y 251.
   - Rechazando (S2, intento 3): el mismo texto, byte a byte, y `discarded_draft_check = {false, [plans_overview/mixed, length_over_cap], 251}`.
   - El caso de «aceptaba» da `{true, [], 120}`; Legacy no lleva la clave.
   - Con el código de antes: `Undefined array key "discarded_draft_check"`, que salta **después** de comprobar los dos textos (el comportamiento de hoy queda fijado).
2. **`UltronRegenerationBudgetNotesTest`** (10 pruebas): literales, 71/89/160, `listBudget()`, el peor caso y las 2.735 combinaciones.
   - SQLite `OK (10 tests, 8350 assertions)`.
   - Con el código de antes: `Errors: 7, Failures: 2`. La que pasa también antes es la de «sin tope o sin lista, las de hoy»: es de preservación.
3. **`…NotasDelServidorTest`, esqueleto** (proveedor: la referencia con tres, 120 expandido; dos con «para quién es», 154).
   - `ai/check` del borrador 3 devuelve las dos notas nuevas enteras.
   - La referencia pasa sin códigos y cabe en R; sale por el commit como del modelo: `valid=true`, `used` = [3,4,8] o [3,8], ≤ 200 y `found=[]`.
   - Con el código de antes: `Failures: 2` (las notas).
   - El fichero entero: SQLite y PostgreSQL `OK (4 tests, 78 assertions)`.
4. **La paridad** (`UltronCheckCommitParityTest`) y el resto de `ai/check` siguen en verde con los mismos códigos.
   - Se actualizan por el texto nuevo, como cambio pedido: los literales de `UltronCheckEndpointTest` (6), `UltronDraftDefectsTest` (2) y `UltronRegenerarTextosFijosTest`.
5. **El informe de origen** (`test_el_rechazo_del_revisor_separa…`, más los casos de `UltronReplyOriginTest`): las filas de los tres tipos, una forma inválida y nada fuera del vocabulario.
   - Con el código de antes: `Undefined constant …SERVER_WOULD_ACCEPT`.
- **Lote A4** (A4*, Regeneration, Check, Paridad, Origen y las unitarias de notas, defectos y origen).
  - PostgreSQL 14: `OK (513 tests, 13256 assertions)`.
  - SQLite: `Tests: 439 … Skipped: 1`; la omitida es la consulta `jsonb`, que solo corre en PostgreSQL.
- **`tests/Unit/Marketing`:** `OK (4080 tests, 47708 assertions)`.
- **`tests/Feature/Marketing`** (una sola vez, SQLite): `OK, but some tests were skipped! Tests: 2789, Assertions: 20797, Skipped: 1` (la de `jsonb`, solo PostgreSQL).

## Guardas (16 ficheros, 141 pruebas): cae 1, la esperada
- `OutreachIsolationTest::test_la_puerta_de_diff_de_legacy_n8n_otp_y_motor_comercial_esta_vacia…`, solo por los cinco del motor frente a `origin/main`: `UltronCommitService.php`, `UltronDraftCheck.php`, `UltronDraftDefects.php`, `UltronRegenerationNotes.php` y `UltronReplyOrigin.php`. Las otras 140 pasan; no he editado ninguna guarda.

## Ficheros de producto (para el despliegue: solo backend)
- `app/Services/Marketing/Ultron/UltronRegenerationNotes.php`, `…/UltronDraftDefects.php`, `…/UltronDraftCheck.php`, `…/UltronCommitService.php` y `…/UltronReplyOrigin.php`.
- `app/Console/Commands/Marketing/UltronOriginReport.php`.
- El constructor del commit cambia: hay que recargar PHP-FPM y opcache.

## Lo que NO pude verificar (solo lo dirá un modelo real)
- Si el redactor sigue el esqueleto y respeta la R, y cuántas listas válidas salen en el 1.º, 2.º y 3.er borrador.
- Si el revisor de la v3 rechaza la referencia: su falso rechazo no se toca.
- La latencia real. No hay llamadas nuevas al modelo; `ai/check` hace una consulta más cuando hay lista y el borrador no nombra ninguno de sus marcadores, y el respaldo del revisor, en el Studio, hace las lecturas de `ai/check`.

## Qué deben mirar primero
- **`security`:** `listBudget()` :255 (lo que revela {X} y si se redondea), `causaDeterminista()` :2258 (solo códigos y números) y `comprobacionDelDescartado()`.
- **El revisor:** la paridad de `inspect()` con el `check()` de antes, la elección de la variante en `of()` :103-150 y el rótulo nuevo del informe. **El propietario:** los tres textos (gate).

## Fuera de alcance (no tocado)
- Con N-R, N-B o la política, la mezcla sigue soltando notas enteras, como hoy.
- La N-P2 sin tope dice «PLAN_1, PLAN_2 y PLAN_3» también con dos planes.
- Las correcciones (2) a (6) del debugger.
