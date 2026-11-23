# PT-17b · Diseño: el turno de planes a la primera (compilador 6)
Arquitecto · 23-11-2026 · solo lectura sobre `_worktrees/lote-2` (rama `cierre/planes-c6`, `cabc4138`, lo que sirve producción). Marcas: [C] comprobado o ejecutado en local · [L] leído en el código · [H] hipótesis.
Sondas, fuera del repo (`/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/5d9fa4cb-4ed2-47ec-9d7c-58096da6a9ee/scratchpad/`): `casos.txt` (los 24 casos con detalle, borrador a borrador), `dump.php` (compila la v4 con el compilador 5: huella `2eb9be6f…`, la MISMA que midió el laboratorio [C]), `medir.php` (la plantilla por `verify()`, `measure()` y el detector de tuteo), `sim_c6.php` (el compilador 6 simulado en las 305 combinaciones) y `textos_c6.json` (los textos de §2, byte a byte).

## Resumen (una pantalla)
- **El primer borrador no se enteró del compilador 5.** Con el 4 y con el 5, el redactor escribe en su primer intento la misma mediana de palabras fuera de los marcadores (46 frente a 46,5; el 5 pedía «unas 15») y cierra con una pregunta de descubrimiento en 19 de 19 y en 23 de 24 casos (el 5 decía «nunca su experiencia ni su objetivo») [C]. El texto del 5 está a mitad del prompt (línea 46 de 106), le siguen unas 60 líneas de la base que piden lo contrario y el mensaje de usuario termina con `suggested_question` [L]. Lo que sí cambió la conducta fue la nota del servidor, que va al final: en el borrador 2 el nombre escrito a mano baja de 14 a 0 [C]. Las cifras de caracteres no gobiernan al modelo: con «quedan 89 de 160» en la nota, 23 de los 24 terceros borradores siguen pasando de 200 [C].
- **El revisor nunca vio un borrador válido para el servidor.** Solo se le llama cuando el servidor no pide reescribir, y el tercer borrador no se comprueba antes del revisor (`StudioLabEngineFlow.php:129-151`) [L]. En los 24 casos solo juzgó el tercero [C]. Sus rechazos falsos vienen de tres textos: el del tono `profesional` (tutear «en cualquier frase», sin dar ejemplos de usted), el del motor («Lo demas se juzga como siempre») y su posición, delante del tope y de las reglas FALLA/TECNICA que lo contradicen [L]. El tuteo falso ya estaba en la línea base del compilador 4: 18 de 18 rechazos [C].
- **Propuesta (opción A):** compilador 6, solo textos y solo el parche del motor (`engine.plansOverview`): pasa de 11 a 15 entradas. (1) El texto del redactor se va al FINAL de los dos redactores, como plantilla literal de piezas («Claro, tenemos PLAN_1, B1; PLAN_2, B2; y PLAN_3, B3. Q.»), con dos palabras por beneficio y la pregunta fija «¿Cuál revisamos?». (2) Dos frases de la base pasan a ser condicionales en su sitio: la de `suggested_question` (nueva) y la del precio (se reescribe la del 5). (3) El revisor recibe al FINAL un bloque del turno de planes, acotado a borradores con `PLAN_1`–`PLAN_3`, y una línea de registro, también acotada, con una lista cerrada de formas de tú y otra de usted. El estratega, el tono, el tope, las frases, la base, `decide`, `ai/check`, el commit, n8n y los intentos no se tocan.
- **Medido en local [C]:** la plantilla expandida con el catálogo real ocupa 175 caracteres de media y 189 en el peor caso con dos palabras por beneficio; `verify()` da null y el detector no encuentra tuteo. Las 307 combinaciones compilan con anclas únicas: rol máximo de 31 822 B y paquete máximo de 130 988 B. La línea base no recibe ningún parche. Huella simulada de la v4 con el compilador 6: `753cb80e72c1122a…dcc8` (122 831 B, 25 parches).
- **No se puede medir antes de desplegar** (§8): los textos del motor son constantes del compilador y lo que gobierna el Studio no admite texto libre. Se mide igual que con el 5: se despliega solo el servidor y se mide en el laboratorio ANTES de publicar. La canaria ya está en Legacy (`compiler_changed`) desde el despliegue del 5.

## Contexto: qué hace hoy el sistema (hechos)
- **El recorrido** (`StudioLabEngineFlow.php:129-151`) [L]: Composer → comprobación 1 → si el servidor pide reescribir, Nota del Servidor → Composer Reintento (el revisor 1 no corre); si no, Critic. Lo mismo en el intento 2. El tercer borrador va de Composer Reintento 2 a Critic Comercial 3 y al commit, SIN comprobación. Los 24 casos hicieron `strategist, composer, check1, composer_retry, check2, composer_retry_2, critic_3` [C].
- **El servidor mide el texto expandido y no descuenta el recorte:** `UltronDraftDefects.php:144-148` da `length_over_cap` si `measure(expandido) > tope` [L]. El commit (`StudioLabEngineCheck::predict()` `:177-184`) quita la pregunta final si así cabe [L]. Los 16 últimos borradores «sin defecto» salieron TODOS recortados (`final.trimmed = true`), y 23 de los 24 pasaban de 200 antes del recorte [C].
- **Lo que ve el redactor** (`StudioLabEngineFlow.php:173`) [L]: el mensaje de usuario termina con `commercial_turn_policy` (que trae `plans_overview` dentro, `UltronDecideService.php:617-618`) y con `suggested_question`, en ese orden. `plans_overview.plans[]` lleva `marker`, `plan_id`, `name`, `duration_days` y `benefits`, sin precio (`UltronPlansOverview.php:232-247`) [L]. El marcador se expande a «Nombre ($precio COP)» (`:274`): `PLAN_1`=25, `PLAN_2`=26 y `PLAN_3`=20 caracteres, 71 los tres (catálogo 3/4/8) [C, derivado de los borradores y de `UltronRegenerationBudgetNotesTest.php:125`]. Para una persona nueva, `suggested_question` es «seria su primera vez entrenando o ya tiene experiencia?» o «que busca lograr al empezar…» (`StrategyContract.php:143-149`, `:276-279`) [L].
- **Lo que ve el revisor** (`:179`, `:154`) [L]: `reply_draft` SIN expandir (con `{{PLAN_k}}`), la estrategia y de la política solo seis claves. NO recibe `plans_overview`: solo sabe que es un turno de lista porque ve los marcadores.
- **El campo `tuteo` de cada borrador** es `UltronRegisterDetector::tuteo()` sobre el texto expandido (y recortado si toca), solo con tono formal (`StudioLabEngineCheck.php:185`, `:222` → `StudioLabEngineCase.php:196`) [L]. Usa una lista cerrada (`UltronRegisterDetector.php:59-76`): pronombres de tú, imperativos con enclítico y verbos de tú. Nunca marca «su», «usted», «ha» ni «busca». En los 72 borradores medidos dio `[]` [C].
- **Lo medido es lo compilado:** `compile(profesional · 200 · emojis sí · cita · sin frases)` da la huella `2eb9be6f…`, la del `engine_target` de las 25 muestras [C].

## 1. Causa raíz por patrón (con casos y líneas)
Casos con el formato `ejecución/caso/escenario` (ejecuciones: 32=`31280c23`, 34=`99acd159`, 35=`49693c72`, 36=`5570e8fb`, 37=`fc2aa163`).

**P1 · Longitud del primer borrador (22 de 24 `length_over_cap`; mediana 347 caracteres según `drafts[0].chars`, no 350; máximo 525).**
- *Ejemplo:* 34/151/E-003 (525): «Le cuento que tenemos varios planes para diferentes necesidades. Por ejemplo, el Plan Valera ofrece acceso ilimitado…, con un precio mensual de {{PLAN_1}}. … ¿Me cuenta si ya ha entrenado antes…?».
- *Causa 1, la posición:* `T_PLANS_COMPOSER` (`UltronPromptCompiler.php:378`) va tras `FORMA:` (línea 46 de 106 del redactor compilado). Detrás vienen las reglas de la base que empujan hacia un texto largo: `composer.txt:3` (ORDEN: RECONOCE + RESUELVE + AVANZA), `:4` y `:33` (recomendar con su PORQUE), `:6` (arranques «Te explico…», «Por lo que me comentas…»), `:32` (validar + aportar valor + preguntar) y `:88` (escribir dentro del objetivo de la estrategia). Al final del mensaje de usuario está `suggested_question`. La prueba es que el primer borrador del 4 y el del 5 son iguales: 46 frente a 46,5 palabras fuera de los marcadores, y 22 de 24 arrancan con «Le comento/Le cuento/Le explico/Por lo que me pregunta» (las formas de usted de `:6`) [C].
- *Causa 2, dos presupuestos que se contradicen:* `T_MAX_COMPOSER` (`:333`, línea 47) pide «unas 25 palabras… como mucho 3 frases» y `T_PLANS_COMPOSER` pide «unas 15 palabras fuera de los marcadores». En los borradores 2 y 3, el modelo se queda en ~25–30 palabras fuera de los marcadores (medianas 29,5 y 27) [C]: sigue las 25 y suma encima los marcadores.
- *Causa 3, contar es imposible:* «Cuenta cada marcador como el texto que lo sustituye» (`:333`) pide medir algo que el modelo no ve (no tiene el precio). La nota del servidor le da la cifra exacta y aun así 23 de 24 terceros borradores pasan de 200 [C]. **H1 se confirma a medias:** la instrucción SÍ llega en la primera llamada, pero llega en mal sitio, la contradicen y se expresa en una unidad (caracteres) que el modelo no sabe contar.

**P2 · Nombre escrito a mano junto a su marcador (14 de 24 `plans_overview/name_written`).**
- *Ejemplos:* 34/150/E-002: «el Plan Valera ofrece acceso ilimitado… por {{PLAN_1}}. El Plan Mensual … por {{PLAN_2}}»; 37/167/E-004: «el Plan Valera … por {{PLAN_1}}, el Plan Mensual … por {{PLAN_2}}…»; 35/158/E-005: «el Plan Valera {{PLAN_1}}…».
- *Causa:* el modelo usa el marcador como si fuera solo el precio. Tres textos lo empujan: (a) el sustituto del compilador 5 en `composer.txt:38`, «el de cada plan de esa lista con su marcador» (`T_PLANS_COMPOSER_PRICE`, `:384`), que habla del PRECIO con su marcador; (b) la base de esa misma línea, «resumen util (nombre + para quien es)»; (c) `plans_overview.plans[].name`, que va justo al lado de cada marcador. Del 4 al 5, `mixed` pasa de 3 a 0 y `name_written` de 8 a 14 [C]: la frase (a) convirtió los `PLAN_PRICE` del 4 en «nombre + PLAN_k». En cambio, la nota concreta del servidor («una frase con PLAN_1 a PLAN_3…») deja `name_written` en 0 en el borrador 2 [C].

**P3 · Sin la lista (6 de 24 `plans_overview/missing`).**
- *Ejemplos:* 36/161/E-003: «Por lo que me pregunta, le recomiendo el Plan Élite: …» (sin marcadores); 32/133/E-001: tres nombres a mano, sin marcador.
- *Causa:* recomendar un solo plan (`composer.txt:4`, `:33`) más un `recommended_plan_id` que nunca es null (lo exige `T_PLANS_STRATEGIST`, `:374`) [L], y la misma posición que en P1.

**P4 · Pregunta de descubrimiento (23 de 24 en el primer borrador y 24 de 24 en los últimos; el 08 cuenta sus formas).** Vale para el redactor y para el revisor.
- *Causa:* `composer.txt:5` («Si vas a preguntar, usa suggested_question cuando venga») y el `suggested_question` del final del mensaje de usuario, que con «profesional» llega ya de usted (`StrategyContract.php:168`). «¿Ha entrenado antes?» y «¿Qué busca lograr con su entrenamiento?» son esas dos preguntas. Además `T_TONE_PRO_COMPOSER` (`:315`) nombra `suggested_question`, lo que la refuerza [L]. Le cuesta ~35 caracteres a cada borrador y provoca la mayoría de `question_discipline`, `sales_judgment` y `customer_fit` del revisor.

**R1 · El revisor ve tuteo donde no lo hay (20 de 21 notas; el detector del servidor no encuentra ninguno en esos 20).**
- *Ejemplos:* 32/137/E-005: «'¿Qué objetivo busca con su entrenamiento?'… tuteo»; 36/161/E-003: «'¿Qué busca lograr?', que es tuteo implícito»; 32/136/E-004: «usa tuteo ('me pregunta', 'ha entrenado')»; 32/135/E-003: «('¿Ha entrenado antes?')». En la línea base del 4: 18 de 18 [C].
- *Causa:* `T_TONE_PRO_CRITIC` (`:317`, línea 9 del revisor compilado) dice «si el borrador tutea en cualquier frase o mezcla tu y usted (…, "quieres", "puedes", …), verdict fail» y no da ni un ejemplo de lo que ES usted. «busca» es también imperativo de tú, y «ha» se parece a «has». **H3 se refuta en parte:** el revisor YA cita una forma, pero cita formas de usted. Exigirle que cite no basta: necesita una lista cerrada de formas de tú y otra de formas de usted que NO son tuteo.

**R2 · `progressive_disclosure` en 21 de 21.**
- *Ejemplos:* 32/133, 32/136, 34/152: «menciona tres planes sin que la persona haya pedido todos»; 36/156: «presenta dos planes con detalle, lo que es demasiado para esta fase».
- *Causa:* `T_PLANS_CRITIC` (`:386`, línea 21) solo dice que «nombrar dos o tres no es defecto» y cierra con «Lo demas se juzga como siempre». Le siguen el tope (`T_MAX_CRITIC`, línea 22: «de mas de ~200 caracteres falla por progressive_disclosure aunque se haya pedido detalle»), el FALLA de `critic.txt:34` («es demasiado largo»), el PROGRESSIVE_DISCLOSURE de `:40` («folleto largo») y TECNICA (`:59`, «suelta caracteristicas antes de saber para que»). El revisor ve los marcadores sin expandir y no puede medir. **Matiz honesto:** 23 de los 24 últimos borradores SÍ pasaban de 200 una vez expandidos, así que en esos casos «demasiado largo» acertaba en el fondo.

**R3 · `question_discipline` 16, `sales_judgment` 12, `next_step` 6, `customer_fit` 4 (fase).**
- *Ejemplos:* 35/158 y 37/165: «hace una pregunta adicional cuando ya se pidió solo información de planes»; 36/159: «presenta tres planes cuando la estrategia indica recomendar solo el Plan Valera»; 37/166: «sin explicar precios» (no lee el marcador como precio). Rechaza y acepta textos casi idénticos: 37/167 (fail) frente a 37/168 (pass).
- *Causa:* `critic.txt:10` («entiende antes de recomendar»), `:15` (`phase_alignment` con la estrategia), `:18` (`customer_fit`), `:34` («vende antes de entender… hace mas de una pregunta») y `:59`. El texto del motor no las desactiva para la lista, y además va antes que ellas. **H2 se confirma.**

## 2. Textos nuevos o cambiados (ASCII, sin tildes ni llaves, una línea; byte a byte en `textos_c6.json`)
Convención de la casa [L, `UltronPromptCompiler5Test.php:281-297`]: ASCII imprimible, sin `{`/`}`, ≤ 1 200 B. Ningún texto contiene un ancla. Cada sustituto empieza por su ancla sin el último signo y no la contiene [C].

**Anclas nuevas** (las tres, una vez en su rol de la base [C]; van a `ANCHORS`):
- `A_SUGERIDA` = `Si vas a preguntar, usa suggested_question cuando venga:` (`composer.txt:5`, `composer_retry.txt:5`).
- `A_FIN_COMPOSER` = `price_verification true significa` (la ÚLTIMA línea de los dos redactores; `insert_after_line` sobre la última línea añade el texto al final, `apply()` `:797-799`).
- `A_FIN_CRITIC` = `Si el borrador explica POR QUE la persona vio otro precio` (la última línea de los dos revisores; 57 caracteres del juego de las frases, así que una frase prohibida PODRÍA contenerla: por eso entra en `ANCHORS`).

**La tabla de `enginePatches()` del compilador 6 (15 entradas, en este orden):**
| # | rol | operación | ancla | texto | frente al 5 |
|---|---|---|---|---|---|
| 1 | strategist | insert_after_line | `QUE PLAN SE COTIZA` | `T_PLANS_STRATEGIST` | igual |
| 2 | strategist | replace | `Presentalo con dos o tres…` | `T_PLANS_STRATEGIST_PRESENT` | igual |
| 3 | composer | replace | `A_SUGERIDA` | **`T_PLANS_COMPOSER_ASK`** | nueva |
| 4 | composer | replace | `Cualquier otro marcador…` | `T_PLANS_COMPOSER_MARKER` | igual |
| 5 | composer | replace | `Elige el plan que mejor encaje…` | `T_PLANS_COMPOSER_CHOOSE` | igual |
| 6 | composer | replace | `el precio solo del que se cotiza.` | **`T_PLANS_COMPOSER_PRICE`** | texto nuevo |
| 7 | composer | insert_after_line | **`A_FIN_COMPOSER`** | **`T_PLANS_COMPOSER`** | texto y ancla nuevos (antes, `FORMA:`) |
| 8 | composer_retry | replace | `A_SUGERIDA` | `T_PLANS_COMPOSER_ASK` | nueva |
| 9 | composer_retry | replace | `Cualquier otro marcador…` | `T_PLANS_COMPOSER_MARKER` | igual |
| 10 | composer_retry | replace | `el precio solo del que se cotiza.` | `T_PLANS_COMPOSER_PRICE` | texto nuevo |
| 11 | composer_retry | insert_after_line | `A_FIN_COMPOSER` | `T_PLANS_COMPOSER` | texto y ancla nuevos |
| 12 | critic | insert_after_line | **`A_FIN_CRITIC`** | **`T_PLANS_CRITIC`** | texto y ancla nuevos (antes, `progressive_disclosure:`) |
| 13 | critic | insert_after_line | `A_FIN_CRITIC` | **`T_PLANS_CRITIC_REGISTER`** | nueva |
| 14 | critic_retry | insert_after_line | `A_FIN_CRITIC` | `T_PLANS_CRITIC` | como 12 |
| 15 | critic_retry | insert_after_line | `A_FIN_CRITIC` | `T_PLANS_CRITIC_REGISTER` | como 13 |
Las entradas 12 y 13 (y 14 y 15) son dos inserciones tras la misma línea, así que quedan en orden inverso (`apply()`; la cabecera `:105-110` lo explica): el revisor lee REGISTRO y después LISTA, y LISTA es lo último del prompt [C, `c6-critic_retry.txt`]. `T_PLANS_COMPOSER_MARKER`, `T_PLANS_COMPOSER_CHOOSE`, `T_PLANS_STRATEGIST` y `T_PLANS_STRATEGIST_PRESENT` no cambian.

**`T_PLANS_COMPOSER_ASK`** (146 B). Composer y composer_retry · `replace` de `A_SUGERIDA`. Sustituye a «Si vas a preguntar, usa suggested_question cuando venga:»; la línea sigue con « es la pregunta que toca, ya adaptada…».
```
Si vas a preguntar, usa suggested_question cuando venga, nunca si commercial_turn_policy.plans_overview trae planes (LISTA DE PLANES DEL BACKEND):
```

**`T_PLANS_COMPOSER_PRICE`** (184 B; SUSTITUYE al del 5, `:384`). Composer y composer_retry · `replace` de `el precio solo del que se cotiza.` (`composer.txt:38`).
```
el precio solo del que se cotiza o, si commercial_turn_policy.plans_overview trae planes, ni nombres ni precios a mano: los pone el marcador de cada plan (LISTA DE PLANES DEL BACKEND).
```

**`T_PLANS_COMPOSER`** (1179 B; SUSTITUYE al del 5, `:378`, y cambia de ancla). Composer y composer_retry · `insert_after_line` tras `A_FIN_COMPOSER` (queda el último del prompt).
```
LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios y el backend ya eligio cuales mostrar. Entonces reply_draft es SOLO esta frase, en este orden: Claro, tenemos PLAN_1, B1; PLAN_2, B2; y PLAN_3, B3. Q. Cada PLAN_k va entre dobles llaves, es el marcador de esa posicion en plans_overview.plans y sale una vez: el backend lo cambia por el nombre y el precio, asi que no escribes ningun nombre de plan, ni la palabra plan, ni cifras, ni PLAN_NAME ni PLAN_PRICE. Cada B son DOS palabras tomadas de uno de los benefits de ese mismo marcador, sin con ni incluye delante. Q es exactamente Cual revisamos, entre signos de interrogacion. Nada mas: sin saludo, sin reconocer lo que dijo, sin otra frase, sin recomendar uno ni decir por que, y sin suggested_question aunque venga (ni experiencia, ni objetivo, ni primera vez). Si plans_overview trae dos planes, o el tope publicado es de menos de 200 caracteres, quita la pieza de PLAN_3. Esto manda sobre todo lo anterior: ORDEN DE CADA MENSAJE, arranques, FORMA, TOPE DE LONGITUD PUBLICADO, PRECIOS y la estrategia. Si plans_overview no viene, nada de esto aplica.
```

**`T_PLANS_CRITIC`** (1119 B; SUSTITUYE al del 4 y 5, `:386`, y cambia de ancla). Critic y critic_retry · `insert_after_line` tras `A_FIN_CRITIC` (queda el último del prompt).
```
LISTA DE PLANES DEL BACKEND (va al final porque manda sobre todo lo anterior): si reply_draft lleva PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves, la persona pidio los planes o los precios y el backend eligio esos planes; cada marcador pone el nombre y el precio reales. En ese borrador NO son defecto: dar dos o tres planes en una frase con un beneficio corto de cada uno, que es la forma pedida y no plantilla ni folleto (naturalness, progressive_disclosure); darlos sin preguntar antes objetivo, experiencia u horario, aunque la fase sea NEW_LEAD o DISCOVERY o la estrategia traiga recommended_plan_id (sales_judgment, phase_alignment, TECNICA, ni customer_fit por la fase); cerrar con una pregunta corta para elegir, con una sola pregunta aunque lleve una o, o sin pregunta (next_step, question_discipline); su longitud, que el backend mide con los marcadores ya sustituidos. No es invented_plan ni invented_price ni esquiva el precio. Todo lo demas falla como siempre: un nombre o una cifra escritos a mano, un beneficio que no esta en active_plans, mas de una pregunta, y cada regla de FALLA TAMBIEN y de HARD_FAIL.
```

**`T_PLANS_CRITIC_REGISTER`** (545 B; nueva). Critic y critic_retry · `insert_after_line` tras `A_FIN_CRITIC` (queda justo encima de `T_PLANS_CRITIC`).
```
REGISTRO EN LA LISTA DE PLANES: si reply_draft lleva PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves y hay REGISTRO PUBLICADO profesional, solo es tuteo una palabra de tu que puedas copiar del borrador: te, tu, tus, ti, contigo, quieres, puedes, tienes, buscas, has, cuentame o dime. Son de usted, y no son tuteo: le, lo, su, sus, usted, busca, tiene, quiere, puede, ha, le cuento, le comento, me pregunta, que busca lograr y ha entrenado. Si no puedes copiar esa palabra, no falles ni pongas naturalness por el registro, y no lo menciones en notes.
```
La lista de tú de este texto está contenida en `UltronRegisterDetector::MARCAS` y ninguna palabra de la lista de usted está en `MARCAS` [C]. Así el revisor nunca puede tumbar por registro algo que el detector del servidor dé por bueno.

## 3. Cada patrón medido: la frase que lo corrige y por qué a la primera
| Patrón (recuento) | Frase que lo corrige | Por qué debería bastar a la primera | ¿Se garantiza con texto? |
|---|---|---|---|
| P1 longitud (22/24) | Plantilla de `T_PLANS_COMPOSER`: «SOLO esta frase… Claro, tenemos PLAN_1, B1; …», «Cada B son DOS palabras…», «Q es exactamente Cual revisamos», «Nada mas…», «manda sobre… FORMA, TOPE DE LONGITUD PUBLICADO» | Ya no hay que contar nada: las únicas piezas libres son los beneficios de dos palabras. Medido [C]: 175 de media y 189 en el peor caso. El texto es lo último que lee el modelo, detrás de todo lo que lo contradecía. Lo que sí movió la conducta en los datos fue la instrucción concreta en última posición (la nota) | No del todo. Con «con» delante de los tres beneficios sale 201 [C]; con tres palabras en cada hueco, ~197–210. Si se pasa, la red es la de hoy: el servidor pide reescribir y el commit recorta la pregunta |
| P2 nombre a mano (14/24) | «el backend lo cambia por el nombre y el precio, asi que no escribes ningun nombre de plan, ni la palabra plan…» + `T_PLANS_COMPOSER_PRICE` («ni nombres ni precios a mano») | Quita la frase del 5 que hacía del marcador un precio y da la forma exacta. La nota del servidor, que es igual de concreta, lo bajó a 0 en el borrador 2 [C] | Alta probabilidad; el servidor lo sigue parando (`name_written`) |
| P3 sin lista (6/24) | Plantilla («SOLO esta frase») + «sin recomendar uno ni decir por que» + «manda sobre… PRECIOS y la estrategia» | La plantilla no deja sitio a un plan único | Alta; el servidor lo para (`missing`) |
| P4 pregunta de descubrimiento (23/24) | `T_PLANS_COMPOSER_ASK` en `composer.txt:5` + «sin suggested_question aunque venga (ni experiencia, ni objetivo, ni primera vez)» + Q fija | Se ataca en los dos sitios que la producen: la regla de la base, en su sitio, y el texto final, que nombra el campo que viene al final del mensaje | Alta, pero NO total: `suggested_question` sigue viajando (cambiarlo es `decide`, fuera de alcance) |
| R1 tuteo falso (20/21) | `T_PLANS_CRITIC_REGISTER`: lista cerrada de formas de tú; «Son de usted…: busca, tiene, ha, le cuento, le comento, me pregunta, que busca lograr, ha entrenado»; «Si no puedes copiar esa palabra, no falles…» | Nombra EXACTAMENTE las formas que el revisor confundió en los 38 rechazos del 4 y el 5 | Alta en el turno de planes. Fuera de él sigue el texto del tono tal cual (ver opción B y la pregunta abierta 1) |
| R2 `progressive_disclosure` (21/21) | `T_PLANS_CRITIC`: «dar dos o tres planes… no plantilla ni folleto (naturalness, progressive_disclosure)» y «su longitud, que el backend mide con los marcadores ya sustituidos» | Va al final, detrás de `T_MAX_CRITIC`, FALLA y PROGRESSIVE_DISCLOSURE. El servidor mide la longitud de verdad (`ai/check` en los intentos 1 y 2, el commit en todos) | Alta |
| R3 `question_discipline`, `sales_judgment`, `next_step`, `customer_fit` y `phase_alignment` por la fase | `T_PLANS_CRITIC`: «sin preguntar antes… aunque la fase sea NEW_LEAD o DISCOVERY o la estrategia traiga recommended_plan_id (sales_judgment, phase_alignment, TECNICA, ni customer_fit por la fase)»; «cerrar con una pregunta corta para elegir, con una sola pregunta aunque lleve una o, o sin pregunta (next_step, question_discipline)»; «No es… esquiva el precio» | Cada objeción medida tiene su frase, en la última posición | Alta |
| Pérdida por timeout (1/25) | — | Fuera de alcance (proveedor) | No |
**«Definitiva», con honestidad:** con texto solo se pueden quitar la ambigüedad y las contradicciones; que el modelo obedezca hay que medirlo. Lo que el texto NO puede garantizar es la longitud en el peor caso (beneficios largos o con relleno). Si el laboratorio muestra que eso es lo que queda, la palanca siguiente es de código y está fuera de esta corrección: el presupuesto en `decide` (diseño del 5, §2), comprobar el tercer borrador o dar `plans_overview` al revisor.

## 4. Lo que NO cambia, y por qué sigue protegido
- **Precios solo del backend:** el modelo nunca ve un precio (`UltronPlansOverview::items()` `:232`). `verify()` sigue parando `price_written` y `unknown_marker`, y el validador sigue tirando cualquier cifra. Ningún texto nuevo lleva una cifra del catálogo (el «200» es el tope, como en el 5) [L].
- **Marcadores `{{PLAN_1..3}}`:** la misma lista firmada (`po`) y la misma expansión. Con dos planes, la plantilla quita la pieza de PLAN_3: así se evita `unknown_marker`.
- **Tope de 200, número de intentos (3 borradores y 2 reescrituras), comprobaciones del servidor y commit:** no se toca `StudioLabEngineFlow` (salvo `COMPILER`), ni `ai/check`, ni `UltronDraftDefects`, ni el commit, ni n8n.
- **Base, gobernados y Legacy:** `T_TONE_*`, `T_EMOJIS_*`, `T_MAX_*`, `T_GOAL_*` y `T_PHRASES_*` iguales. Con la línea base no hay ningún parche (`patches()` `:474-489` no cambia) y la huella Legacy `3f6a5674…` sigue igual [C, simulación].
- **El revisor solo recibe inserciones, nunca sustituciones.** Ninguna de sus reglas desaparece. Las relajaciones solo valen «si reply_draft lleva PLAN_1, PLAN_2 o PLAN_3», y fuera del turno de lista un marcador así ya es defecto del servidor: `verify()` da `no_list` con `po` vacía (`:345`) y es fatal en el commit (`predict()` `:198-206`) [L]. **Protecciones del revisor que siguen intactas** (de `critic.txt`):
  - las 16 claves de HARD_FAIL (`:32`): invented_price, invented_plan, non_sellable_plan, invented_class, invented_payment_status, invented_url, unauthorized_handoff, request_card_data, contradiction, major_reference_failure, low_novelty, customer_misfit, pressure, progressive_disclosure (más de tres planes o más de cuatro viñetas), identity_lie e invented_membership_fact;
  - factuality (`:24`), payment_safety con la tarjeta, el CVV y el OTP (`:27`), url_safety (`:28`), app_factuality (`:29`) y membership_factuality (`:30`);
  - MAJOR_REFERENCE_FAILURE (`:37`), LOW_NOVELTY (`:38`), PRESSURE y la superioridad sin aprobar (`:39`, `:60`), CUSTOMER_MISFIT de READY y de PAID/ACTIVE_MEMBER (`:41`; solo se neutraliza «por la fase»), HANDOFF_LEAK (`:42`) y la identidad (`:43`);
  - esquivar el precio (`:44`), el link de pago sin permiso (`:45`, `:47`, `:50`, `:51`), URL, dominio o cuenta (`:46`), la revisión del equipo sin `staff_review` (`:48`), las promesas a futuro (`:49`), los horarios y beneficios inventados (`:52`), lo médico y los resultados (`:53`), el reclamo (`:54`), la visita a medias (`:62`) y la causa inventada de otro precio (`:63`);
  - «mas de una pregunta» sigue fallando (lo dice el texto).

## 5. Plan de pruebas
**Que se actualizan** (las del diff del 5, `git diff --name-only 9c2325a1 cabc4138`) [C]:
- *Pruebas de la app:*
  - `tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompiler5Test.php`: se convierte en `UltronPromptCompiler6Test.php`; los textos del 5 pasan a ser históricos en §B.3;
  - `UltronPromptCompilerTest.php`, `UltronPromptCompilerPhrasesTest.php`, `UltronPromptEnginePatchTest.php` y `UltronPublishedConfigPhrasesTest.php`;
  - `tests/Unit/UltronStudio/StudioLabEngineFlowParityTest.php` (`motor-S2-c6-…`), `StudioPromptDeclarationTest.php` y `ExportStudioFixturesCommandTest.php`;
  - `tests/Feature/Marketing/UltronDecidePromptsTest.php`, `UltronPromptsN8nS2CommandTest.php` y `UltronPromptsTraceCommandTest.php`;
  - `tests/Feature/UltronStudio/UltronStudioLabEngineInvariantsTest.php`, `UltronStudioPublicationPromptsTest.php`, `UltronStudioPublicationRulePhrasesTest.php`, `UltronStudioPublicationV2Test.php` y `UltronStudioStatusCatalogTest.php`.
- *Doradas, siempre con su generador:*
  - `tests/fixtures/ultron-prompts/{compile-cases.json, compile-cases.README.md, tools/parches-m1.cjs}` (transcripción independiente y luego `derivar-compile-cases.cjs`);
  - `tests/fixtures/ultron-studio/publication-cases-v2.json` (`ultron-studio:export-fixtures`);
  - `n8n/flujos/ultron-studio-s1/{LEEME.md, flujo-s1.json, huellas.json, fixtures/decide-*.json}` y `ultron-studio-s2/{flujo-s2.json, fixtures/decide-*.json}` (`ultron:prompts-n8n` y `--rev=S2`; solo `_generado` y los prompts de las fixtures: no se importa n8n).
- *Al ejecutar, buscar con grep* `UltronPromptOfferEngineTest` y cualquier fijación de `2eb9be6f` o `compiler.*5`.

**Nuevas (`UltronPromptCompiler6Test` y una de fixtures del laboratorio):**
- **N1 · `compile(v4)`:**
  - 25 parches: tono 4, tope 6 y motor 15, en el orden de la tabla;
  - la última línea de los dos redactores es `T_PLANS_COMPOSER`, y la siguiente a `FORMA:` es `T_MAX_COMPOSER`;
  - `composer.txt:5` lleva `T_PLANS_COMPOSER_ASK` y `:38` el nuevo PRICE;
  - las dos últimas líneas de los dos revisores son REGISTER y LISTA, y la siguiente a `progressive_disclosure:` es `T_MAX_CRITIC`;
  - **en los revisores, quitando las líneas insertadas queda exactamente la base con los parches gobernados** (el motor no sustituye nada en un revisor);
  - huella fijada con la del generador de Node;
  - `VERSION === 6`.
- **N2 · Frases prohibidas:** `phraseViolation(A_FIN_CRITIC)` = `anchor`, también dentro de una frase más larga. Las otras dos anclas ya caen por `charset`.
- **N3 · Las 305 combinaciones + listas de frases** (E2/N4 ampliada): cada ancla, una vez al aplicarse, y ningún texto contiene un ancla (las 15). El motor usa en cada rol exactamente sus anclas. Rol ≤ 40 960 B y paquete ≤ 163 840 B. Replica `sim_c6.php`.
- **N4 · Los textos al byte:** (146, 184, 1179, 1119 y 545); ASCII, sin llaves y ≤ 1 200. Los sustitutos empiezan por su ancla sin el último signo y no la contienen.
- **N5 · Paridad del registro:** las palabras de tú de `T_PLANS_CRITIC_REGISTER` ⊂ `UltronRegisterDetector::MARCAS`, y las de usted ∩ `MARCAS` = ∅.
- **N6 · Línea base y Legacy:** `compile(BASELINE)` es la base y su huella es `3f6a5674…`; `patches(BASELINE) === []`.
- **N7 · Laboratorio ↔ motor:** `StudioLabEngineFlow::COMPILER === UltronPromptCompiler::VERSION` y `promptVersion()` = `motor-S2-c6-2026-11-22.1`.
- **F1 · Fixture «la plantilla pasa el servidor»**, con `StudioLabEngineCheck::check()` + `predict()`, el catálogo 3/4/8, `profesional · 200` y la frase E-001:
  - los rellenos T1 (175) y T2 (189) y la versión de dos planes a 160 → `pass`, sin defectos, `verify` null, `tuteo` [] y, con revisor pass, `origin model` sin recorte;
  - T3 («con» ×3, 201) → `length_over_cap` en `ai/check` y `origin model` con recorte en el commit. Documenta el borde.
- **F2 · Fixture «lo que el revisor tumbó»:** los 13 últimos borradores rechazados sin defecto final (32/135, 32/137, 34/150, 34/153, 35/154, 35/156, 35/157, 36/162, 36/163, 37/164, 37/165, 37/166 y 37/167; textos en `casos.txt`):
  - `predict()` → `codes []` y `trimmed true`, y `measure > 200` antes de recortar;
  - `UltronRegisterDetector::tuteo() === []` y ninguna palabra de la lista de tú de REGISTER.
  - Lo que demuestra: que con el compilador 6 el registro no puede tumbarlos, y que salen sin su pregunta.
- **F3 · Fixture de alcance:** el mismo texto T1 con `po = []` (turno sin lista) y revisor pass → `origin fallback` por `plans_overview` (`no_list`). La relajación del revisor no puede sacar un texto fuera del turno de lista.

## 6. Riesgos y regresiones en OTROS turnos
1. **Que el revisor relaje fuera del turno de planes.** Acotado por la condición «si reply_draft lleva PLAN_1, PLAN_2 o PLAN_3»: esos marcadores solo valen cuando `decide` ofreció la lista (`listaDelPanorama()` `:309`, tres condiciones); en cualquier otro turno son `no_list` en `ai/check` y en el commit (F3). En el revisor solo se inserta (N1). El tercer borrador no se comprueba antes del revisor, pero el commit sí lo mide.
2. **El redactor en otros turnos:** el texto final y las dos sustituciones empiezan por la condición «si commercial_turn_policy.plans_overview trae planes / nunca si…». En el resto, `composer.txt:5` dice lo mismo que hoy más una cláusula que no aplica. Riesgo residual [H]: la plantilla, al ser lo último del prompt, podría filtrarse a un turno de precio de UN plan (sin `plans_overview`). Se vigila en la simulación del Studio (C-02 «¿Cuánto vale la mensualidad?» no dispara la lista) y con `missing`/`no_list` del servidor.
3. **Rigidez:** la misma frase para todos y sin saludo en el primer contacto. El revisor podría llamarlo «plantilla»: el bloque lo cubre (naturalness). Si la persona vuelve a pedir los planes, `novelty`/`repetition` pueden fallar como hoy; ese comportamiento no es nuevo.
4. **El borde de longitud** (§3) y el catálogo: con nombres más largos que los de hoy (71 caracteres los tres), la plantilla puede pasar de 200. F1 fija el catálogo de hoy; si el catálogo cambia, se vuelve a medir.
5. **«¿Cuál revisamos?»** no es verbo de oferta (`ConversationMemoryService.php:65` y `:76`): no se registra ninguna oferta viva [C, regex].
6. **Margen de bytes:** `T_PLANS_COMPOSER` ocupa 1179 de 1 200 B.
7. **Posición final [H, guía de OpenAI para GPT-4.1, no verificada en esta sesión, sin red]:** ante instrucciones que chocan, la familia 4.1 tiende a seguir la que está más cerca del final. Los datos encajan con eso (la nota, que va la última, sí cambió la conducta; el texto de mitad de prompt, no), pero no lo demuestran.

## 7. Reversión
- **Por código:** revertir el commit del compilador 6 (vuelve el 5, con la misma huella `2eb9be6f` para la v4) y `php artisan queue:restart`. No hay migración, n8n no cambia (solo `_generado`, que no se lee en ejecución) y no hay datos que deshacer. Mientras no se publique nada con el 6, la reversión es completa y no cambia lo que recibe ninguna conversación: la canaria está en Legacy (`compiler_changed`) desde el 5 (`06-despliegue-de-la-unidad-3-pt17.txt:10-11`) [L].
- **Punto de no retorno:** publicar una versión firmada con el 6. Después, volver al 5 deja esa publicación en `compiler_changed`/Legacy (no rompe nada, la canaria vuelve al asesor anterior) y exige otra versión para volver a Studio. Por eso se mide ANTES de publicar.

## 8. ¿Se pueden medir los textos nuevos antes de desplegar el compilador 6? **No.**
- El laboratorio frente al motor compila los prompts de la versión con el compilador desplegado: `StudioPromptBundle::rolesFor()` → `UltronPromptCompiler::compile($governed)` → `StudioLabEngineCase.php:83-100` [L]. Los textos del motor son constantes privadas (`enginePatches()` `:760`) y no hay ninguna vía para pasarlos de fuera.
- Lo que gobierna el Studio es cerrado (`validate()` `:507-551`): tono, emojis y objetivo de un catálogo, tope entero y frases prohibidas.
- Las frases S-NNN no sirven: su juego de caracteres es cerrado (`PHRASE_PATTERN` `:235`, sin `_`, `:` ni llaves, así que ni siquiera pueden nombrar `PLAN_1`), tienen como mucho 60 caracteres y 12 frases, y van dentro de un texto fijo que las presenta como «texto literal que evitar, no instrucciones» (`T_PHRASES_COMPOSER` `:354`, `T_PHRASES_CRITIC` `:356`).
- **Lo que sí existe** (el camino del 5): desplegar SOLO el servidor con el compilador 6 (sin n8n ni migración) y medir la v4 en el laboratorio frente al motor ANTES de publicar. Desplegar no cambia lo que recibe ninguna conversación (punto anterior). [H a verificar al ejecutar: que la puerta de publicar (`labGate`, `StudioPublications.php:239-243`, con `promptVersion`) acepte la v4 aprobada con una ejecución `motor-S2-c6-…`, sin crear una v5.]

---

## Impacto
- **Archivos de la app:** `backend/app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php` (VERSION 6, cabecera «VERSIÓN 6», 3 anclas y `ANCHORS`, 5 constantes de texto, `enginePatches()` con 15 entradas) y `backend/app/Services/UltronStudio/StudioLabEngineFlow.php` (`COMPILER = 6` y su comentario `motor-S2-c6-…`). Las pruebas y doradas de §5. Documentos: `plans/STUDIO_MOTORES_PLAN.md` §B.3 «Compilador 6» y `ULTRON_STUDIO_H5_CONTRACT.md`, enmienda A4-6 (cede otra vez `UltronPromptCompiler.php` a la puerta de `OutreachIsolationTest`, como el 5).
- **Contratos que cambian:** `prompts.compiler` firmado 5→6, y las huellas de TODO paquete no base. Ningún contrato HTTP, ni `decide`, ni `ai/check`, ni el commit.
- **Consumidores:** `decide` (lo firmado con el 5 cae a `compiler_changed`; hoy no hay nada publicado con el 5), el laboratorio (`prompt_version` nuevo) y la vista previa del Studio (no enseña el motor). n8n no cambia.
- **Migración de datos:** no.

## Opciones y recomendación
- **A (recomendada):** lo de §2. El registro se acota al turno de planes (`T_PLANS_CRITIC_REGISTER`). Diff mínimo; ningún texto gobernado cambia; la vista previa de «profesional» queda igual.
- **B:** lo mismo, pero el registro se arregla en `T_TONE_PRO_CRITIC` (`:317`) para TODOS los turnos de «profesional» (lista cerrada de tú + ejemplos de usted). Arregla la causa también donde no se ha medido, pero cambia un texto gobernado (lo enseña la vista previa del Studio) y amplía el radio sin evidencia fuera de la lista. Mejor como seguimiento, con una medición de otros turnos.
- **C:** darle al revisor `plans_overview` o el presupuesto en `decide`, o comprobar el tercer borrador. Más robusto para la longitud, pero es código de `decide`/n8n: el propietario lo excluye.
→ **A**, porque ataca cada patrón medido con el menor radio y sin tocar textos gobernados; B queda como recomendación aparte.

## Plan (secuenciado)
1. [backend] N1–N7 y F1–F3 en rojo (`UltronPromptCompiler6Test`; F1–F3 en `tests/Unit/UltronStudio/`). Verificación: fallan por VERSION, por las anclas y por los textos.
2. [backend] Compilador 6 en `UltronPromptCompiler.php` + `StudioLabEngineFlow::COMPILER = 6`. Verificación: N1–N7 y F1–F3 en verde.
3. [backend] §B.3 + `parches-m1.cjs` (transcripción) → `compile-cases.json` y cruce de huella Node/PHP (la v4 debe dar `753cb80e…` si los textos quedan como en `textos_c6.json`).
4. [backend] Doradas y fijaciones de §5 (en paralelo con 5).
5. [backend] n8n regenerado (`_generado`) y los dos comprobadores. **Primer punto en verde: después del paso 5.**
6. [backend] Enmienda A4-6 del H5 (qué cambia, qué no, punto de no retorno, umbral).
7. [tester] Suites de prompts, oferta, `decide`, publicación y laboratorio, de una en una (Mac de 8 GB). Casos límite: dos planes, tope 160/180/480, cercano/entusiasta, con frases, línea base, PLAN_k sin lista.
8. [security] Una pasada: no viaja ningún dato nuevo al modelo, la superficie de frases con `A_FIN_CRITIC`, textos fijos sin entrada del personal, el revisor solo con inserciones, Legacy intacto.
9. [reviewer] Textos frente a §1 y la evidencia, doradas generadas con su generador, huella Legacy.
10. Propietario, una cosa cada vez: aprobar los 5 textos → autorizar el despliegue SOLO del servidor → laboratorio de la v4 (25 recorridos del motor y la simulación de la puerta) contra los umbrales → publicar solo si los cumple.

## Preguntas abiertas
1. **¿A o B para el registro?** Recomiendo A (acotado) y medir otros turnos antes de B.
2. **Saludo en el primer contacto:** la plantilla no saluda. Con tres planes en 200 no cabe saludo y lista; para saludar habría que bajar a dos planes. Recomiendo sin saludo, que es lo que ya hacía el modelo en los 72 borradores.

## Enmienda del lead (23-11-2026)
Una sola frase de `T_PLANS_COMPOSER` cambia frente a §2. El propietario pidió conservar los límites existentes, y el
texto no debe decirle al redactor que su instrucción manda sobre el tope publicado (el servidor lo sigue midiendo, pero
el prompt no tiene por qué ponerlo en duda; siguiendo la frase pedida, el mensaje queda por debajo del tope).
- Antes: `Esto manda sobre todo lo anterior: ORDEN DE CADA MENSAJE, arranques, FORMA, TOPE DE LONGITUD PUBLICADO, PRECIOS y la estrategia.`
- Ahora: `Esto manda sobre todo lo anterior: ORDEN DE CADA MENSAJE, arranques, FORMA, PRECIOS y la estrategia; el tope publicado sigue valiendo.`
El texto queda en 1185 B. Los textos que se implementan son los de `textos_c6.json` YA ENMENDADO (copia del
lead); la huella de la versión 4 con el compilador 6 que anticipa §5 (`753cb80e…`) deja de valer y la fijan las pruebas.

## Anexo: decisiones del propietario (23-11-2026), el contrato de la implementación
Mandan sobre §2, §3, §5 y la enmienda del lead donde chocan. Todo lo demás del diseño (causa raíz, anclas, posición al final, sustituciones ASK y PRICE, inserciones del revisor, huella Legacy intacta) sigue valiendo.

**A. Prompts (compilador 6, mismo mecanismo de parches; solo paquetes del Studio, nunca la línea base Legacy).**
1. Redactor: anclas al FINAL y las dos sustituciones ASK y PRICE de §2. `T_PLANS_COMPOSER` deja de ser una frase fija y pasa a ser una GUÍA con libertad de redacción (texto literal en `plans/STUDIO_MOTORES_PLAN.md` §B.3, «Compilador 6»), con `<L>` = max(tope publicado, 320) y `<W>` = (L − 110) / 6 de cinco en cinco por abajo (320 → 35, 480 → 60), calculadas del tope gobernado. Prohibida cualquier frase modelo («Claro, tenemos…») o pregunta de cierre fija; una prueba lo impide.
2. Revisor: el texto de lista de §2 (`T_PLANS_CRITIC`, al final, acotado a borradores con `PLAN_1` a `PLAN_3`) y, en lugar de `T_PLANS_CRITIC_REGISTER` (la lista cerrada de registro), una frase corta acotada igual: el registro (tu/usted) y la longitud de un borrador con la lista ya los comprueba el backend; no fallar, no bajar naturalness ni mencionarlos en notes por ellos. Solo inserciones: HARD_FAIL, «FALLA TAMBIEN» y el resto de reglas, byte a byte.

**B. Laravel: regla «tope de lista».**
1. Tope aplicable = max(tope publicado, 320) SOLO si (i) el servidor ofreció la lista en ese turno y (ii) la respuesta lleva al menos DOS marcadores de plan válidos. En cualquier otro caso, el tope publicado, como hoy (160 → 320, 200 → 320, 320 → 320, 480 → 480; sin lista, el original).
2. Se mide sobre el mensaje final ya expandido.
3. En respuestas de lista no se recorta la pregunta final: si excede, `length_over_cap` con la nota de reescritura (cifras del tope de lista) dentro de los intentos existentes; si al final no hay respuesta válida, el respaldo seguro de hoy con su causa (`policy_length_exceeded`) y el tope aplicado en la metadata.
4. Restringida al flujo del Studio con el compilador 6: Legacy y cualquier turno fuera del piloto, exactamente como hoy (demostrado con pruebas de la lógica PHP).
5. Sitios: `UltronReplyFitter`, `UltronDraftDefects::of()`/`ofPolicy()`, `UltronCommitService` y la predicción del laboratorio (paridad).
6. No se toca: Studio/CRM, n8n en ejecución, BD, contrato de `decide`, número de intentos, marcadores, precios, textos curados ni el tope de los demás turnos.

**C.** Se descartan la frase fija y sus pruebas (la de plantilla del laboratorio); las sustituyen las de la regla nueva.

**Cómo se implementó (backend, decisiones propias que el lead debe validar).**
- **El sello del compilador:** `sp` no llevaba la versión del compilador; para que la regla solo valga con el 6 (y un token del 5 o de antes del despliegue se mida como hoy), `decide` firma `sp.k` = la versión, solo en `studio` y con el parche del motor (junto a `sp.o`). La respuesta a n8n no cambia.
- **«Marcadores válidos»:** se leen como marcadores DISTINTOS de la lista ofrecida (`PLAN_k` con k ≤ planes de la lista), con la misma lectura que la expansión; la lista no tiene que ser válida entera (un borrador «mixto» con dos marcadores buenos ya se mide contra el tope de lista, y su nota de lista lleva esas cifras).
- **Los huecos del texto:** `<L>` y `<W>`, sin llaves, como todo texto del motor (la constante tampoco puede formar un marcador).
- **La traza:** `metadata.length.list_cap` (aditiva); `cap` sigue siendo el tope publicado.

**Corrección del bloqueante B1 de la revisión final (23-11-2026).** La fila «320 → 320» de B.1 no se cumplía: con «profesional · 320», `decide` (y `rolesFor()` en el laboratorio) no firman `sp.n` porque es el tope de la línea base, `plansListCap()` exigía `sp.n` y daba null, y ni `ai/check` ni el commit medían la lista, aunque al redactor se le dice «no pasa de 320» y al revisor que la longitud la comprueba el backend.
- **La regla:** sin `sp.n`, el tope publicado es el de la línea base (`BASELINE[maxChars]` = 320) y el tope de lista sale de él; un `sp.n` firmado que no vale sigue sin dar tope de lista. Lo demás de B.1 (Studio, `sp.k` ≥ 6, lista ofrecida, dos marcadores) no cambia, ni la firma de `decide` y del laboratorio.
- **El commit:** el paso de longitud y `antesDeEnviar()` corren también sin tope publicado cuando se aplicó el de la lista: `metadata.length` lleva `cap` null y `list_cap` 320, y el respaldo no se mide (como hoy en la línea base). Dos consecuencias para que se comporte como con los demás topes: la frase que retoma una visita a medias (13.ter) se añade a la respuesta con la lista solo si cabe en su tope de lista (sin esto, la lista válida se iba al último recurso en el punto de control), y el acta tras un descarte por longitud entra también con el tope de la lista.
- **Sin cambios:** los turnos sin lista con la línea base (sin medir), Legacy y un sello del compilador 5.
- **No se hizo (no bloqueante N3):** en una lista `missing` (0 o 1 marcador), las cifras de la nota de reescritura siguen siendo las del tope publicado («quedan 89 de 160»). No es trivial: la nota de la lista y la de la longitud comparten el presupuesto de `of()`, y en ese borrador la longitud se mide de verdad contra el publicado; separar los dos presupuestos cambia notas que fijan otras pruebas. Queda para otra tanda.
