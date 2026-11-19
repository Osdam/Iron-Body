# Plan del arquitecto · Cierre V2, apartado 4: «regenerar antes de sustituir»

**Fecha:** 19-11-2026. **Autor:** `architect`, solo lectura: no he tocado código, pruebas, producción ni n8n; este informe es el único fichero escrito. **Base:** `feat/ultron-studio-h9` = `a6e2ccdf`, árbol rastreado limpio (solo hay evidencias sin rastrear) [C]. Rutas relativas a `backend/` salvo `n8n/…`, que cuelga de la raíz.
**Criterio de aceptación:** las diez condiciones del propietario, `MATRIZ_CIERRE_V2.md` §5 [L].
**Marcas:** **[C]** comprobado en el código o ejecutando (grep, lectura de la línea citada, recuento) · **[L]** leído en un documento del repositorio · **[S]** supuesto o estimado, sin verificar.

---

## 0. Veredicto en diez líneas

1. **P-1 se sostiene con cambios:** una puerta nueva de solo lectura (`POST ai/check`) que reutiliza las clases puras del commit. El commit en dos fases (a) no sirve: escribe la fila y ejecuta herramientas antes de esas comprobaciones. La validación en JS (b) tampoco: n8n tendría que ver los precios.
2. **P-2 se sostiene:** el flujo va desenrollado (S2, generado desde S1), con tres redacciones como máximo por construcción. El techo real de tiempo **no** es el token de 120 s: es el **job de 45 s** (`config/marketing.php:70`). El servidor deja de conceder reescrituras pasados **25 s** desde `decide` [S, a calibrar].
3. **P-3 se sostiene con cambios.** El marcador `{{PLAN:<id>}}` **no vale**: escapa al patrón de marcadores y en un turno Legacy saldría literal por WhatsApp. Va `{{PLAN_1}}`…`{{PLAN_3}}`, posicional sobre una lista firmada en el token (`po`).
4. **P-3 (sigue).** La instrucción va en un parche del compilador 4 aplicado a toda versión no base, y la lista viaja en `commercial_turn_policy.plans_overview`. Es el único campo que ya llega al redactor sin tocar n8n, así que P-3 funciona incluso con S1.
5. **P-4 se sostiene recortado:** una comprobación literal y por frase de que el beneficio escrito pertenece al plan que esa frase nombra. Las paráfrasis y los pronombres quedan como límite declarado.
6. **P-5 se sostiene:** solo se reescriben el registro, la longitud y la estructura de la lista (con los beneficios), además de lo que pida el revisor. El resto, igual. La condición 2 dice «alguna regla corregible»: las violaciones de política quedan fuera **por decisión que tiene que tomar el propietario** (§11).
7. **P-6 se sostiene:** sin versión del Studio, n8n no llama al servidor. Las llamadas al modelo y lo que se les manda son idénticos a hoy, y el servidor contesta «legacy» si se le llama igual.
8. **P-7 se sostiene con cambios:** el origen de cada respuesta se deriva con una clase pura, la latencia sale de marcas que ya existen y los tokens solo si n8n los entrega [S]. El informe es `ultron:origin-report` y `canary-report` no se toca.
9. **P-8 se sostiene:** misma autenticación y mismo token, ni escrituras ni cerrojo, y las notas no llevan texto de la persona. Comparte el cubo de 120 peticiones por minuto, lo que solo importa antes de ampliar.
10. **Siete olas en secuencia,** verde desde la primera. Peor caso: **7 llamadas al modelo frente a 5 hoy**. El 90 % no está garantizado [S ≈ 85–90 %]: la causa mayor de doble rechazo (H4-4) vive en los prompts base, que esta intervención no toca.

---

## 1. Contexto: lo que hace hoy el sistema (hechos verificados)

| # | Hecho | Dónde |
|---|---|---|
| H1 | n8n solo atiende la canaria; los clientes los atiende Legacy de Laravel, que no se toca | `UltronEventEmitter.php:84-87` (dado por el lead) [L] |
| H2 | El flujo vivo es la **copia S1** `QSklqHlsDIxucRfk`, con 25 nodos (los 24 de `29de5119` más «Elegir Prompts»). El de siempre, `YspRwsXnqt33NouP`, está despublicado e intacto | `ULTRON_STUDIO_CIERRE_9_HITOS.md:20` [L]; `n8n/flujos/ultron-studio-s1/flujo-s1.json` (25 nodos, recontados) [C] |
| H3 | Composer → Critic Comercial → «Critic aprueba?» → (no) Composer Reintento → Critic Comercial 2 → Preparar Commit. Hay **una** reescritura y solo la pide el revisor | conexiones de `flujo-s1.json` [C] |
| H4 | Los `values[1]` (contenido de usuario) leen el borrador de `$json.output[0].content[0].text…`, es decir, del nodo inmediatamente anterior. El redactor y su reintento reciben `commercial_turn_policy` **entero**; los dos Critic solo reciben un subconjunto con campos explícitos | `flujo-s1.json`, nodos Composer, Composer Reintento, Critic Comercial y Critic Comercial 2 [C] |
| H5 | `composer_retry.txt:88`: «Corrige EXACTAMENTE lo senalado en critic_issues y critic_notes». La nota del servidor cabe en esos campos sin tocar los prompts base | [C] |
| H6 | `Preparar Commit` elige el borrador mirando si corrió «Composer Reintento» (`intento` 1 o 2) | `n8n/flujos/ultron-studio-s1/preparar-commit.js:23-31` [C] |
| H7 | El commit toma el cerrojo de la conversación y es idempotente por clave (UNIQUE): `UltronCommitService.php:189-209` y `persist()`. Escribe la fila (`:1285`) y ejecuta las herramientas (`:1320`) **antes** de los enlaces (`:1408`), el cobro (`:1461`), los servicios inventados (`:1576`), la longitud (`:1593`), el registro (`:1643`), `violations()` (`:1667`) y el panorama (`:1726-1730`) | [C] |
| H8 | `critic.attempt` admite como mucho 2 (`UltronController.php:260`) y `CriticContract::forMetadata()` lo recorta a 2 (`CriticContract.php:107`). Un tercer intento hoy muere en un 422 de validación, y `Clasificar Fallo` lo trata como «contrato» y lo manda a la rendición | [C] |
| H9 | Los bloques que acepta el commit están en una lista cerrada. Una clave de más devuelve un 422 `unexpected_fields`, y la lista de bloques está en `UltronController.php:435` (`proposal`, `critic`, `recovery`, `prompts`) | [C] |
| H10 | El patrón de marcadores es `/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/` (`UltronDraftPlaceholders.php:60`) y la lista cerrada está en `:57`. **`{{PLAN:12}}` no casa con el patrón** (por los dos puntos): no es «desconocido», no da 422 y saldría literal. Ningún guard mira `{{` | grep en `OutboundContentGuard`, en el validador y en el commit [C] |
| H11 | `decide` manda los planes **sin precio** (`UltronDecideService.php:38-41`, `:648-670`); el precio lo pone el commit leyendo `Plan` | [C] |
| H12 | El token dura 120 s (`UltronDecideToken.php:27`) y lleva `i` (emitido en, `:51`) y carga firmada (`x`). `verify()` comprueba además la fase, las transiciones y la versión del conocimiento | [C] |
| H13 | **El techo de tiempo de verdad:** el job que llama al webhook espera al final del flujo (`responseMode: responseNode`) con un tiempo máximo de 45 s (`config/marketing.php:70`, `MARKETING_ULTRON_TIMEOUT`; comentario `:54-69`: «los turnos reales miden entre 7 y 13 s»; «45 s cubre el peor camino medido»). Si se pasa, reintenta (`SendUltronEventToN8n.php:30-33`, `:98`): otra ejecución completa que acaba en 409 | [C] |
| H14 | La versión del conocimiento solo mira los elementos de la base de conocimiento, no los planes (`MarketingKnowledgeBaseService.php:196-202`). Un plan puede cambiar entre `decide` y `commit`, y el commit relee `Plan` | [C] |
| H15 | Los parches del compilador cuelgan de un parámetro gobernado (`patches()`, `UltronPromptCompiler.php:348`). Con la línea base no hay ninguno y `compile()` devuelve la base byte a byte (docblock e `isBaseline()`, `:506`). La vista previa del Studio agrupa solo por `UltronPromptCompiler::PARAMETERS` (`StudioPromptBundle.php:362`) | [C] |
| H16 | Línea base: 88 respuestas; 81 % del modelo, 16 % respaldo curado tras dos rechazos (la reescritura rescató 11 de 23) y 3 % de la política | `ops/evidencia-h9/2026-11-18/canario-a4/07-origen-de-las-respuestas.txt` [L] |
| H17 | Latencia observada en la segunda prueba real: unos 8–11 s por turno con un solo intento (de 22:44:11 a 22:44:22, de 22:46:15 a 22:46:23 y de 22:47:13 a 22:47:23) | `…/canario-a4/04-mensajes-y-dictamen.txt:18-76` [C, aproximado: se toma la fila `inbound_skipped` como llegada] |
| H18 | Todo el grupo `internal/marketing/*` comparte **un** cubo de 120 peticiones por minuto | `routes/marketing.php:44`; `AppServiceProvider.php:888-890` [C] |

---

## 2. Opciones y recomendación

| Opción | Qué es | Por qué sí / por qué no |
|---|---|---|
| **A. Comprobación del servidor + flujo S2 desenrollado** | n8n pregunta a `ai/check` (solo lectura) entre el redactor y el revisor; si hay defecto y queda presupuesto, reescribe con la nota del servidor. El commit sigue comprobándolo todo | Usa las MISMAS clases puras; ni efectos ni cerrojo; el límite está en la forma del grafo; se vuelve atrás publicando S1. Cuesta una ruta nueva, que obliga a refrescar la caché de rutas al desplegar |
| B. Commit en dos fases («reescribe») | El commit devuelve «reescribe» en vez de sustituir | **No.** Las comprobaciones corregibles van detrás de `persist()` (`:1285`) y de `runTools()` (`:1320`) [C]: la fila, la fase, la memoria y las herramientas ya ocurrieron. Moverlas delante sería rediseñar el orden «no negociable» de la cabecera (`:47-63`), y un segundo commit con la misma clave da 409 |
| C. Comprobación en JS dentro de n8n | `decide` manda reglas y n8n las aplica | **No.** Duplica el detector del tuteo, el troceo en frases y `plansNamedIn`. Y para medir la longitud de la lista **n8n tendría que ver los precios**, que es justo lo que `plansWithoutPrice()` impide (H11) |
| D. Laravel llama al modelo para reescribir | El commit reescribe | **No.** Traslada el redactor a Laravel (credenciales, prompts, latencia dentro de un cerrojo de 60 s): es una reconstrucción y choca con la condición 8 |

**→ Recomendación: A.** Es la única que deja intactos el orden del commit, el principio de «el modelo nunca ve una cifra» y la vuelta atrás de n8n por publicación.

---

## 3. Veredicto sobre P-1 a P-8

### P-1 · El bucle vive en n8n y lo gobierna el servidor → **se sostiene, con cambios**

- **Cambio 1: no se usa `verify()` del token, sino `peek()` (nuevo).** `peek()` comprueba la firma, la caducidad y que `c`/`m` coincidan, y devuelve `x` (la carga firmada) y `i`. No recalcula la fase, las transiciones ni el conocimiento: si el mundo cambió, lo decide el commit (`stale_decision`), como hoy. Así la comprobación no repite el trabajo de `phaseContext()` ni lee la cadena del Studio (`UltronEngineIsolationTest.php:620` exige que solo el commit y la oferta usen `UltronPublishedConfig` [C]).
- **Cambio 2: ni cerrojo ni escrituras.** No toma `Cache::lock` (el de la conversación lo comparten la ingesta y el job), no crea filas, no toca la memoria ni la caché. Solo lee el mensaje (`body`, para «¿pregunta precio?»), `Plan`, `activePlans()` y `defaultMonthlyPlan()`.
- **Cambio 3:** la comprobación es un **aviso**. El commit no lee su resultado: vuelve a comprobarlo todo con las mismas funciones sobre su texto final, y ese texto puede diferir (H7: el commit pega enlaces o el cobro y retira frases). Lo cubre una prueba de paridad (§6, ola 4).
- (a) y (b): descartadas por lo dicho en §2 B y C.

### P-2 · Presupuesto cerrado por construcción → **se sostiene (desenrollado)**

- El generador lo aguanta. `UltronPromptsN8nFlow::build()` es una función pura que añade nodos y conexiones con identificadores fijos (`:191-263`) [C]. S2 = `build(S1)` más 14 nodos, sin MCP: se importa como copia, como S1 (`ops/motores/n8n-copia-s1/LEEME.md`). **Se descarta el bucle con contador:** n8n reejecuta el mismo nodo con varios `runIndex`, y `$("Composer Reintento").first()` (`preparar-commit.js:27`) dejaría de significar «el último borrador». El límite quedaría en un contador y no en la forma del grafo.
- **El tope de 3 redacciones lo pone la estructura:** en ningún camino hay más de tres nodos de redactor. Lo comprueba el comprobador offline (§5.4, I).
- **El servidor se niega:** con `attempt` ≥ 3, `may_rewrite = false`.
- **El tope de tiempo lo calcula el servidor**, con el `i` firmado del token y sin reloj de n8n: no concede una reescritura si han pasado más de **25 s** desde `decide` [S: calibrar con la p95 de la ola 6].
  - Razón: el techo real es el job de 45 s (H13), no el TTL de 120 s.
  - Con una concesión a los 25 s, lo que queda por hacer (redactor, revisor y commit; o redactor, comprobación, revisor y commit) suma unos 9–12 s sin reintentos de nodo y unos 20 s con uno [S]. Se termina antes de 45 s.
- **El tercer borrador no se comprueba:** ya no hay presupuesto y la red es el commit. Ahorra una llamada.
- **El revisor del tercer borrador sí se llama siempre**, como hoy se llama a Critic Comercial 2. Sin revisor, un texto que el commit solo recorta (la pregunta final) saldría sin juzgar.
- **Cambio obligatorio:** `critic.attempt` ≤ 3 (`UltronController.php:260`) y el recorte a 3 de `CriticContract::forMetadata()` (`:107`). Un n8n S1 nunca manda 3, así que Legacy queda igual.
- Llamadas y latencia, cuantificadas en §8.

### P-3 · Planes y precios: el modelo redacta, el servidor pone los datos → **se sostiene, con siete cambios**

1. **El marcador, posicional y casable:** `{{PLAN_1}}`, `{{PLAN_2}}` y `{{PLAN_3}}` en lugar de `{{PLAN:<id>}}`.
   - El de dos puntos no casa con `UltronDraftPlaceholders::PATTERN` (`:60`, H10) y saldría literal en un turno sin lista.
   - El posicional sí casa. Sin lista firmada es «desconocido» (422 → rendición, como cualquier marcador inventado hoy). Con lista firmada de `n` planes, solo valen los de 1 a `n`.
   - «Solo planes ofrecidos» se cumple así por construcción: el número apunta a la lista que firmó `decide` (`po`), no a un id que escriba el modelo.
2. **Por dónde viajan los datos:** `context.commercial_turn_policy.plans_overview`.
   - Es el único campo que ya llega **entero** al Composer y al Composer Reintento en S1 (H4).
   - Si viajara en un campo nuevo habría que tocar sus `values[1]`. Así P-3 funciona ya con S1 y se puede probar sin S2.
   - El campo se añade **fuera** de la lista blanca de la política (`UltronDecideService.php:261-273`), en `decide()` y solo con versión del Studio.
3. **La instrucción, compilada, pero no en «todo paquete del Studio».**
   - Con la línea base no hay parches y `compile()` es la base (H15). Meter el parche ahí rompe la propiedad central de M-1 («línea base = como hoy») y la huella Legacy.
   - Solución: parche **del motor** (`engine.plansOverview`) en el compilador 4, que se añade al final **solo si la versión ya tiene algún parche gobernado**. `UltronPromptOffer` firma `sp.o = 1` cuando el paquete ofrecido lo lleva, y `decide` solo ofrece la lista con `sp.o = 1`.
   - Una versión del Studio con todos los valores por defecto sigue como hoy: la lista fija como respaldo.
   - Va también al **revisor** (Critic y Critic 2): sin él, `critic.txt:44` («si le preguntaron el PRECIO y el borrador NO lleva el marcador de precio») tumbaría la lista. El revisor no recibe `plans_overview` (H4) y no se toca su `values[1]`.
4. **Dónde se expande:** inmediatamente después de comprobar que el validador no tiró el texto (`:607-621`) y antes de `resolvePlan()` (`:624`), sobre `$sanitized['reply']`.
   - Hay tres sitios que vuelven a resolver desde `$sanitized['reply']` (`:625`, `:804` y `:899`). Expandir ahí, una vez, evita que una realineación pierda la lista.
   - Todo lo que viene detrás (estilo, novedad, política, registro, longitud, guardas) ve el texto final. Con dos o más nombres y sin `PLAN_PRICE`, la realineación de `:871` no se activa [C, leyendo la condición].
5. **Turno de cobro:** con `payment_link_send` pedida o con una intención de cobro (`$turnoDeCobro`, `:869-870`), la lista no vale. Los marcadores pasan a desconocidos y salta el 422 → rendición. El dinero nunca va pegado a una lista de tres precios.
6. **La memoria:** cuando sale la lista del modelo, se anota el precio entregado de cada plan con el mismo `conPreciosEntregados()` (`:5599`; el commit lo llama en `:2021`). El mapa `{id => texto expandido}` sirve porque la función busca cada texto dentro de lo que salió.
7. **La regla del panorama** (`:1726-1730`): si la lista es válida, la violación `policy_plans_overview` **no salta** aunque se pregunte el precio. Si no lo es, salta como hoy y sale la lista fija.
- **Lista válida** = al menos dos marcadores distintos de la lista; sin `PLAN_NAME`, `PLAN_PRICE` ni `PLAN_DURATION`; sin marcadores repetidos; sin el nombre de un plan de la lista escrito a mano; y todos los planes siguen vendibles **ahora** (H14).
- La longitud se mide sobre el texto expandido: sale gratis, porque la medida (`:1593`) va después de expandir.
- **Selección:** la de `respaldoDePrecios()` (`:5449-5466`): el que nombró la persona y después los de un mes por el orden del catálogo, como mucho tres y nunca los rechazados. Se extrae a `UltronPlansOverview::select()` y la usan los dos. Es el pendiente m-8 de H4-12(f), pero solo la selección.

### P-4 · Beneficios → **se sostiene recortado** (comprobación literal y por frase)

- **Regla (pura, `UltronBenefitAttribution`):**
  - En cada frase del texto expandido (`UltronReplyFitter::sentences()`) que nombra **exactamente un** plan (por nombre o por la expansión de su marcador)…
  - …si aparece, por palabras completas, sin tildes y en minúsculas, un beneficio del catálogo de **otro** plan de al menos dos palabras…
  - …y ningún beneficio del plan nombrado lo contiene ni está contenido en él…
  - …entonces es un beneficio mal atribuido.
  - Las frases con negación (`no`, `sin`, `ni`, `excepto`, `salvo`) no se miran: «El Plan Valera no incluye clases grupales» es una afirmación correcta.
- **Qué hace:** en la comprobación, un defecto `benefit_misattributed` (reescritura). En el commit, con versión del Studio, la violación nueva `policy_benefit_misattributed`: la rama de la política y el respaldo de la intención.
- **Límite declarado (no se rebaja en silencio):**
  - No ve paráfrasis («acceso a todas las máquinas» frente a «Acceso ilimitado al gimnasio»).
  - No ve un beneficio atribuido con un pronombre en la frase siguiente («Además incluye…»).
  - No ve frases que nombran dos planes.
  - No ve beneficios inventados que no están en ningún plan (eso es `sinServiciosInventados`, `:1576`, que no cambia).
  - La comprobación estructurada de `access_classes` queda fuera: con negaciones da falsos positivos.
  - **La condición 3 («Laravel verifica… beneficios autorizados») queda cubierta en parte;** §10.

### P-5 · Qué es corregible → **se sostiene**

- Códigos cerrados en `UltronRegenerationNotes::CODES`, con su prueba: `register_tuteo`, `length_over_cap`, `plans_overview` (seis detalles, §4.4) y `benefit_misattributed` (P-4, de la misma familia de «planes»). Más lo que rechace el revisor.
- **Igual que hoy:** `violations()` (saludo que vende, plan equivocado, objetivo perdido, respuesta sin hecho…), el guard de contenido, las guardas de pago y de membresía, el no contactar, el relevo y las herramientas.
- El mecanismo es genérico (código → *issue* + nota), así que ampliarlo después es una constante, una nota aprobada y una prueba.

### P-6 · Sin versión del Studio, nada cambia → **se sostiene (opción «no preguntar»)**

- `decide` solo devuelve `regeneration` con `sp.m = studio`.
- Los IF de S2 miran ese bloque. Sin él, el recorrido es Composer → Critic → (Composer Reintento → Critic 2) → Preparar Commit, con **las mismas llamadas y los mismos `values[1]`**: los 25 nodos de S1 no cambian, salvo el `jsCode` de Preparar Commit y el `FLOW_REV` del selector.
- Defensa en profundidad: si `ai/check` recibe un token sin `studio`, contesta `mode: legacy` y `may_rewrite: false`.
- Las dos instantáneas `tests/fixtures/ultron-a4/legacy-a4.json` y `legacy-usted.json` se ejecutan sin regenerar [C: existen y las usan `UltronA4LongitudYPreciosTest` y `UltronA4RegistroYPanoramaTest`].
- Se descarta «llamar y contestar que pasa sin mirar»: es una llamada HTTP más por turno Legacy a cambio de nada.

### P-7 · Métricas → **se sostiene, con cambios**

- **En la fila:** bloque aditivo `metadata.regeneration`, escrito en `persist()` (el único punto por el que pasan las cuatro creaciones de fila: `:1285`, `:2377`, `:2623`, `:4294` [C]). Lleva redacciones, revisores ejecutados, comprobaciones, quién pidió cada reescritura con sus códigos y los tokens si existen.
- **Sin campo de origen almacenado.** El origen se **deriva** con `UltronReplyOrigin::classify()`, una función pura, con las mismas reglas que la consulta de la línea base:
  - `fallback_mode` → respaldo del revisor o de la rendición;
  - `policy_discarded_draft` → respaldo de la política;
  - si no, modelo: primer intento si `drafts = 1`; regenerado si `drafts ≥ 2`, y por quién.
  - Así las 88 filas históricas se clasifican igual y el porcentaje se puede comparar.
- **Latencia, sin campos nuevos:** de `marketing_messages.created_at` del entrante al `created_at` del saliente, más los reintentos del job (`marketing_automation_events.attempts > 1`) como alarma.
- **Tokens:** `Preparar Commit` suma `usage.input_tokens/output_tokens` de los nodos de OpenAI que corrieron, si el nodo 2.3 los devuelve [S: confirmarlo en el ensayo de n8n con datos fijados]. Si no, `null`.
- **Costo:** llamadas × tokens, con el precio por millón como opción del comando, sin `config/`.
- **`ultron:canary-report` no se toca.** Los contadores mecánicos de exactitud (cifras fuera del catálogo, planes no vendibles, enlaces, datos personales) siguen saliendo de ahí, y el criterio de aceptación usa los dos comandos (§12).

### P-8 · Seguridad → **se sostiene; superficie y pruebas**

| Superficie que abre | Cierre | Prueba |
|---|---|---|
| Una ruta más tras el secreto compartido | Mismo grupo `automation.internal` y mismo limitador (`routes/marketing.php:44`) | 401 sin secreto; aparece en `InternalSecretAuthoritySurfaceTest` como «tercera puerta, solo lectura» |
| Consultar una conversación ajena (IDOR) | El token HMAC ata `c` y `m`; sin token válido para ese par, 422 `invalid_token` sin detalle | Token de otra conversación, de otro mensaje, caducado, con la firma alterada o mal formado: todos dan 422 |
| Efectos | Ni escritura, ni cerrojo, ni red, ni despachador | Escuchador de consultas (solo `select`), `Cache::lock` simulado sin llamadas, `Http::fake()` sin peticiones, cero filas nuevas en las tablas de Marketing |
| Datos personales en la respuesta o en el log | Solo códigos, números (tope, medida), marcas de una lista cerrada y beneficios del catálogo | Entrante con un teléfono y un marcador únicos: no aparecen en la respuesta ni en `ChannelLog`; lista blanca de las claves de la respuesta; nunca el `decide_token` |
| Forma de la petición | Lista blanca y `unexpected_fields`; `reply_draft` ≤ 4 000 | Clave de más en la raíz o en `draft` → 422 |
| Usarla como oráculo para esquivar guardas | Solo informa de registro, longitud y lista: no del guard de contenido ni de las guardas de dinero, que siguen solo en el commit | Prueba: un borrador con URL o datos de tarjeta pasa la comprobación y el commit lo tumba igual |
| Expresiones regulares nuevas en el PCRE 10.39 de producción | Sin `\p{…}`; se añaden a la sonda de producción | Prueba de la ausencia de `\p{` y sonda (la ejecuta el lead con orden) |
| Capacidad | Dos peticiones más por turno del Studio en un cubo compartido de 120/min (H18) | Solo canaria: irrelevante. **Condición antes de ampliar:** recalcular el cubo (§10, R4) |
| Bloque `regeneration` del commit | Es traza: nada se ramifica por él, como `prompts` | Mismo commit con el bloque, sin él y con un bloque «mentiroso»: el mismo texto y los mismos efectos; solo cambia la fila |
| Marcadores de la lista | Del `po` firmado y comprobados contra `Plan` en el commit; nunca en un turno de cobro | Un plan pasa a no vendible entre `decide` y `commit` → la lista no vale y sale la lista fija del catálogo de ahora |

**Para `api-security-specialist`/`security`:** las filas 2, 3 y 10, y las notas al modelo (§7), que son texto fijo más parámetros de vocabulario cerrado.

---

## 4. El contrato exacto

Nombre del contrato: **`ultron-regen/1`**. La ruta es la de los otros nodos: `https://api.ironbodyneiva.cloud/api/internal/marketing/ai/check`.

### 4.1 `POST /api/internal/marketing/ai/check` (nuevo, solo lectura)

**Petición** (lista blanca; cualquier otra clave en la raíz o en `draft` → 422 `unexpected_fields`):

```json
{
  "conversation_id": 882,
  "message_id": 123456,
  "decide_token": "<el de decide, tal cual>",
  "attempt": 1,
  "draft": {
    "reply_draft": "Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?",
    "intent": "pricing_question",
    "recommended_plan_id": 3,
    "tools_requested": []
  }
}
```

| Campo | Regla |
|---|---|
| `conversation_id`, `message_id` | `required|integer|min:1` (sin `exists`: el token ya ata el par) |
| `decide_token` | `required|string|max:2048` |
| `attempt` | `required|integer|min:1|max:3` |
| `draft.reply_draft` | `required|string|max:4000` |
| `draft.intent` | `required|in:SalesAgentDecisionSchema::INTENTS` |
| `draft.recommended_plan_id` | `nullable|integer|min:1` |
| `draft.tools_requested` / `.*` | `nullable|array|max:5` / `in:UltronDecideService::TOOL_VOCABULARY` |

**Proceso (`UltronDraftCheck`, servicio de solo lectura).** Usa las mismas funciones puras que el commit:

1. `UltronDecideToken::peek()`. Si falla → 422 `{ok:false, code:"invalid_token"}`, sin detalle.
2. Sin `sp.m = studio`: respuesta «legacy» y fin.
3. **Texto expandido** (solo para medir):
   - `{{PLAN_k}}` con `po` (si no es turno de cobro) → «Nombre ($precio COP)»;
   - `PLAN_PRICE/NAME/DURATION` con `recommended_plan_id` si es vendible; si no, `defaultMonthlyPlan()`, la misma regla que `Preparar Commit`;
   - `{{APP_LINKS}}` → `MobileAppCatalog::linksInline()`; si `tools_requested` trae `app_links_send` sin marcador, se añaden al final, como `conEnlaces()` [S: aproximación de `:1408-1417`].
4. **Defectos, en este orden:**
   - (a) `plans_overview`, con `UltronPlansOverview::verify()`: solo si hay `po`, no hay herramientas y se cumple la condición de `:1727-1729` (intención de precio, `preguntaPorPrecio(body)` o menos de dos planes nombrados). Además, `no_list` si aparece un `PLAN_k` sin `po`.
   - (b) `register_tuteo`: `UltronRegisterDetector::tuteo()` sobre el texto expandido, solo con `sp.t = profesional`.
   - (c) `length_over_cap`: `UltronReplyFitter::measure()` del texto expandido > `sp.n`.
   - (d) `benefit_misattributed`: `UltronBenefitAttribution`.
5. **Presupuesto:** `may_rewrite = attempt < 3 && (ahora − i) ≤ 25 s`; `rewrite = defects ≠ [] && may_rewrite`.

**Respuesta 200 (Studio):**

```json
{
  "ok": true,
  "contract": "ultron-regen/1",
  "mode": "studio",
  "attempt": 1,
  "pass": false,
  "defects": [
    {"code": "register_tuteo", "detail": null, "issue": "naturalness"},
    {"code": "length_over_cap", "detail": null, "issue": "progressive_disclosure"}
  ],
  "issues": ["naturalness", "progressive_disclosure"],
  "notes": "REESCRIBE DE USTED: … DEMASIADO LARGO: …",
  "may_rewrite": true,
  "rewrite": true,
  "budget": {"max_drafts": 3, "elapsed_s": 9, "reason": null}
}
```

**Detalles de la respuesta:**

- `budget.reason` ∈ {`null`, `last_attempt`, `time_budget`, `legacy`}.
- `defects` lleva como mucho 4 entradas, una por código.
- `notes` es la concatenación, en orden, de las notas fijas (§7.2), cortada por notas enteras a 500 caracteres (`CriticContract::MAX_NOTES`).
- `issues` sale del vocabulario de `CriticContract::DIMENSIONS`.

**Respuesta 200 (sin Studio):**

```json
{"ok": true, "contract": "ultron-regen/1", "mode": "legacy", "attempt": 1, "pass": true, "defects": [], "issues": [], "notes": "", "may_rewrite": false, "rewrite": false, "budget": {"max_drafts": 2, "elapsed_s": null, "reason": "legacy"}}
```

**Errores:**

- 401: el middleware.
- 429: el limitador.
- 422 de validación: la forma estándar de Laravel.
- 422 `unexpected_fields`.
- 422 `invalid_token`.

n8n trata **cualquier** respuesta que no sea un 200 bien formado como «no reescribir» (§5.2), y sigue el camino de hoy.

### 4.2 Lo que añade `decide` (solo con `sp.m = studio`)

| Dónde | Qué | Cuándo |
|---|---|---|
| Raíz de la respuesta | `"regeneration": {"contract": "ultron-regen/1", "max_drafts": 3}` | Siempre que el turno sea del Studio |
| `context.commercial_turn_policy.plans_overview` | `{"plans": [{"marker": "{{PLAN_1}}", "plan_id": 3, "name": "…", "duration_days": 30}, …], "price_asked": true}` | `sp.o = 1`, `pideElPanorama()` con la política de `decide` y al menos dos planes en `UltronPlansOverview::select()` |
| Token, carga firmada `x` | `po: [3, 4, 8]` (los id, en orden) | Lo mismo que la fila anterior |
| `sp` firmado | `o: 1` | El paquete ofrecido lleva el parche del motor (lo calcula `UltronPromptOffer`, junto a `n` y `t`, `:200` y `:212`) |

- Sin precio, como siempre: el nombre ya está en `active_plans` y no hay exposición nueva.
- Que n8n no reevalúa `{{…}}` dentro de una cadena devuelta por una expresión está comprobado (`ops/motores/n8n-copia-s1/LEEME.md`, punto 5) [L].

### 4.3 Lo que añade el commit

**Entrada** (`UltronController::commit()`):

```php
'critic.attempt'                  => ['nullable','integer','min:1','max:3'],   // era max:2 (:260)
'regeneration'                    => ['nullable','array'],
'regeneration.contract'           => ['required_with:regeneration','string', Rule::in(['ultron-regen/1'])],
'regeneration.drafts'             => ['required_with:regeneration','integer','min:1','max:3'],
'regeneration.critics'            => ['nullable','integer','min:0','max:3'],
'regeneration.checks'             => ['nullable','integer','min:0','max:2'],
'regeneration.check_errors'       => ['nullable','integer','min:0','max:2'],
'regeneration.rewrites'           => ['nullable','array','max:2', /* lista, no objeto */],
'regeneration.rewrites.*.by'      => ['required','string', Rule::in(['server','critic'])],
'regeneration.rewrites.*.codes'   => ['nullable','array','max:4'],
'regeneration.rewrites.*.codes.*' => ['string', Rule::in(UltronRegenerationNotes::CODES)],
'regeneration.rewrites.*.issues'  => ['nullable','array','max:8'],
'regeneration.rewrites.*.issues.*'=> ['string', Rule::in(CriticContract::DIMENSIONS)],
'regeneration.tokens'             => ['nullable','array'],
'regeneration.tokens.input'       => ['nullable','integer','min:0','max:2000000'],
'regeneration.tokens.output'      => ['nullable','integer','min:0','max:2000000'],
```

Además, `'regeneration'` entra en la lista de bloques de `unexpectedKeys()` (`:435`).

**Cómo se evita el 422** (el mismo patrón que `prompts`):

- `Preparar Commit` solo añade el bloque si `decide` devolvió `regeneration.contract = "ultron-regen/1"`. Un backend sin este cambio no lo ofrece y nunca lo recibe.
- Y `attempt = 3` solo puede ocurrir con el bloque ofrecido.

**Traza** (aditiva; sin bloque o sin `po`, la fila queda como hoy):

| Clave | Contenido | Quién |
|---|---|---|
| `metadata.regeneration` | `{drafts, critics, checks, check_errors, rewrites:[{by, codes, issues}], tokens|null, consistent}`. `consistent = (drafts === critic.attempt)` si hay revisor: un dato que no cuadra solo se marca, no cambia nada | `persist()` |
| `metadata.plans_overview` | `{offered:[id], used:[id], valid:bool, detail:null|código}` | Camino normal, si el token trae `po` |
| `metadata.benefits` | `{misattributed:[{plan_id, benefit_index}]}` (índice en `benefits` y no el texto) | Si salta P-4 |
| `metadata.policy_violations` | Puede llevar `policy_benefit_misattributed` (nuevo) | Rama de la política, sin cambios |

### 4.4 Los códigos de defecto

| Código / detalle | Cuándo (comprobación) | `issue` | Nota | En el commit, si llega en el último borrador |
|---|---|---|---|---|
| `plans_overview` / `missing` | Lista ofrecida y requerida (§4.1, 4a) con menos de dos marcadores distintos | `relevance` | N-P1 | `policy_plans_overview` → lista fija (hoy) |
| `plans_overview` / `mixed` | Marcadores de la lista con `PLAN_NAME`, `PLAN_PRICE` o `PLAN_DURATION`, o uno repetido | `relevance` | N-P2 | Lo mismo |
| `plans_overview` / `name_written` | Nombre de un plan de la lista escrito a mano (`plansNamedIn` sobre el borrador sin expandir) | `relevance` | N-P3 | Lo mismo |
| `plans_overview` / `unknown_marker` | `PLAN_k` con `k > n` | `relevance` | N-P4 | `unknown_placeholder` 422 → rendición (hoy) |
| `plans_overview` / `no_list` | `PLAN_k` sin lista en el turno o en un turno de cobro | `relevance` | N-P5 | Lo mismo |
| `plans_overview` / `price_written` | Con lista, `SalesAgentDecisionSchema::containsPrice()` en el borrador | `factuality` | N-P6 | `draft_rejected` 422 → rendición (hoy) |
| `register_tuteo` | `sp.t = profesional` y tuteo en el texto expandido | `naturalness` | N-R | `policy_register_mismatch`, recorte o descarte de A42-4, **sin cambios** |
| `length_over_cap` | `sp.n` y `measure(expandido) > n` (cualquier exceso, también si bastaría quitar la pregunta final) | `progressive_disclosure` | N-L | Recorte F1 o `policy_length_exceeded` (A41), **sin cambios** |
| `benefit_misattributed` | Con versión del Studio, P-4 | `factuality` | N-B | `policy_benefit_misattributed` (nueva) → respaldo de la intención |

**Por qué `length_over_cap` salta aunque el commit pudiera recortar.** La condición 2 pide reescribir cuando se incumple la longitud. Al quitar la pregunta final se pierde el paso siguiente (C-02 c3). En el último borrador el recorte sigue siendo la red.

---

## 5. El flujo de n8n (S2)

### 5.1 Antes (S1, publicado) y después (S2)

```
ANTES (S1)
Validar Estrategia → Composer → Critic Comercial → Critic aprueba? ─sí→ Preparar Commit → Ejecutar en el CRM → Commit OK? …
                                                         └no→ Composer Reintento → Critic Comercial 2 ──────→ Preparar Commit

DESPUÉS (S2)  [*] = nodo nuevo
Validar Estrategia → Composer → ¿Comprobar 1?[*] ─no (Legacy)────────────────────────────────────────→ Critic Comercial
                                   └sí→ Comprobar Borrador 1[*] → Leer Comprobación 1[*] → ¿Reescribir 1?[*] ─no→ Critic Comercial
                                                                                                    └sí→ Nota del Servidor 1[*] → Composer Reintento
Critic Comercial → Critic aprueba? ─sí→ Preparar Commit
                                   └no→ Composer Reintento                                  (sin cambios)
Composer Reintento → ¿Comprobar 2?[*] ─no (Legacy)─────────────────────────────────────────→ Critic Comercial 2
                       └sí→ Comprobar Borrador 2[*] → Leer Comprobación 2[*] → ¿Reescribir 2?[*] ─no→ Critic Comercial 2
                                                                                         └sí→ Nota del Servidor 2[*] → Composer Reintento 2[*]
Critic Comercial 2 → Decidir Intento 3[*] → ¿Intento 3?[*] ─no→ Preparar Commit
                                                       └sí→ Composer Reintento 2[*]
Composer Reintento 2[*] → Critic Comercial 3[*] → Preparar Commit
(Preparar Commit → Ejecutar en el CRM → … → rendición: SIN CAMBIOS)
```

### 5.2 Los 14 nodos nuevos

| Nodo | Tipo | Qué hace |
|---|---|---|
| ¿Comprobar 1? / ¿Comprobar 2? | IF 2.3 | `={{ $('Pedir Contexto al CRM').first().json.regeneration ? $('Pedir Contexto al CRM').first().json.regeneration.contract === 'ultron-regen/1' : false }}`. Solo nombra un nodo que siempre corrió. El IF deja pasar su entrada, así que el Critic sigue leyendo el borrador en `$json.output…` |
| Comprobar Borrador 1 / 2 | HTTP 4.4 | `POST …/ai/check` con la credencial de siempre («IRON BODY Internal API», por referencia), `timeout: 3000`, **sin reintentos**, `fullResponse` + `neverError` y `onError: continueRegularOutput`. Cuerpo: §4.1, con `attempt` fijo (1 o 2) y el borrador de `$('Composer')` o `$('Composer Reintento')` |
| Leer Comprobación 1 / 2 | Code | Devuelve **el mismo objeto del redactor** (`$('Composer'…).first().json`) más `ultron_check = {rewrite, may_rewrite, error, codes, issues, notes}`. `rewrite` solo es `true` con un 200, `ok`, el contrato correcto, `rewrite === true`, defectos y notas. Ante cualquier otra cosa: `rewrite = may_rewrite = false` y `error = true` (camino de hoy) |
| ¿Reescribir 1? / ¿Reescribir 2? | IF 2.3 | `$json.ultron_check.rewrite` (booleano calculado arriba, como `Rendirse?` con `$json.rendirse`) |
| Nota del Servidor 1 / 2 | Code | Emite la forma del revisor: `{output:[{content:[{text:{verdict:"fail", score:null, issues, notes, hard_fail:null, by:"server", codes}}]}]}`. Así el `values[1]` de Composer Reintento (`critic_issues`, `critic_notes`) **no se toca** |
| Decidir Intento 3 | Code | Entrada = Critic 2. Devuelve lo mismo más `ultron_intento3 = (fail || hard_fail) && may_rewrite`, con `try { $('Leer Comprobación 2')… } catch → false` (en Legacy no corrió) |
| ¿Intento 3? | IF 2.3 | `$json.ultron_intento3` |
| Composer Reintento 2 | OpenAI 2.3 | Copia exacta de Composer Reintento (modelo, opciones, reintentos, credencial). Sistema: `ultron_prompts.roles.composer_retry` (**ningún rol nuevo:** el paquete y su huella no cambian). `values[1]` = el de Composer Reintento con `rejected_draft` leído de `$("Composer Reintento")` |
| Critic Comercial 3 | OpenAI 2.3 | Copia exacta de Critic Comercial 2 (rol `critic_retry`). Su `values[1]` es idéntico, porque lee de `$json`, que aquí es Composer Reintento 2 |

**Preparar Commit, dos anclas nuevas** (el resto es el de S1):

- **(i) El borrador:** se prueban «Composer Reintento» (intento 2) y «Composer Reintento 2» (intento 3), cada uno en su propio `try`, sin volver a 1 si falla el segundo.
- **(ii) El bloque `regeneration`:** solo si `ctx.regeneration.contract === "ultron-regen/1"`, dentro de un `try` (si falla, sale sin bloque, nunca sin respuesta). Lleva:
  - quién pidió cada reescritura: `server` si «Leer Comprobación k» corrió con `rewrite`; si no, `critic`, con los `issues` del revisor;
  - los revisores ejecutados, las comprobaciones y sus errores;
  - y `usage` si existe.
- **Selector:** «Elegir Prompts» es el de S1 con `FLOW_REV = "S2"`. `selectorCode()` gana un parámetro con valor por defecto, así que S1 sale byte a byte igual.

**Recorridos (todos acaban en Preparar Commit con la entrada del último revisor):**

| Caso | Nodos de modelo | Comprobaciones |
|---|---|---|
| Legacy, pasa | Strategist, Composer y Critic | 0 |
| Legacy, el revisor falla | + Composer Reintento y Critic 2 (**igual que hoy**) | 0 |
| Studio, pasa | Strategist, Composer y Critic | 1 |
| Studio, el servidor pide 1 | Strategist, Composer, Composer Reintento y Critic 2 | 2 |
| Studio, el revisor pide 1 | Strategist, Composer, Critic, Composer Reintento y Critic 2 | 2 |
| Studio, dos reescrituras | hasta Strategist, 3 Composer y 3 Critic | 2 |

### 5.3 Qué genera el generador

- `UltronRegenN8nFlow::build(array $s1)`: puro, toma la salida de `UltronPromptsN8nFlow::build()` (la que se verifica hoy) y devuelve el flujo S2, las huellas, los `.js` de los Code y los fixtures.
- Ids y posiciones fijos, y `_generado = {from: S1, flowRev: S2, …}`.
- La orden `ultron:prompts-n8n --rev=S2 --out=n8n/flujos/ultron-studio-s2` escribe:
  - `flujo-s2.json` (solo para el comprobador);
  - `preparar-commit.js`, `leer-comprobacion.js`, `nota-del-servidor.js` y `decidir-intento-3.js`;
  - `huellas.json`;
  - `fixtures/` (respuestas de `decide` Studio/Legacy y respuestas de `ai/check`: pasa, reescribe, legacy, 404, 422, error de red, mal formada);
  - `LEEME.md`.
- No hay `operaciones-mcp.json`: S2 entra como copia nueva, con el importador oficial.

### 5.4 Cómo se comprueba sin tocar el n8n en marcha

1. **Comprobador offline** (`ops/motores/verificar-flujo.cjs --rev=S2`, o un `verificar-flujo-s2.cjs` hermano):
   - **A.** La base: lo de hoy.
   - **B.** Nodos = los 25 de S1 más los 14 de §5.2, ni uno más.
   - **C.** Los 25 de S1, **idénticos a S1** salvo el `jsCode` de Preparar Commit y el `FLOW_REV` del selector. Por tanto, todos sus `values[1]` y opciones están intactos.
   - **D.** Los parámetros de los nuevos, exactos; las dos copias de OpenAI, iguales a sus originales salvo nombre, id, posición y `rejected_draft`.
   - **E.** Las conexiones de §5.1.
   - **F.** El selector, con `flowRev S2`.
   - **G.** Preparar Commit en `vm`, con los mismos datos que el de S1. Sin oferta, **la misma salida** y sin bloque. Con oferta, un bloque válido para §4.3 en los seis recorridos.
   - **H.** Los Code nuevos en `vm`, con los fixtures de `ai/check`.
   - **I.** El grafo: fuera del ciclo de la rendición, todo camino de Composer a Preparar Commit pasa por ≤ 3 redactores y ≤ 3 revisores; con «no ofrecido», el recorrido es exactamente el de hoy.
   - **J.** El cuerpo del HTTP, con solo las claves de §4.1.
   - El comprobador lo ejecuta una prueba PHP, como hoy (`UltronPromptsN8nCommandTest.php:150`).
2. **Copia inactiva** «… (Studio S2)», importada con `importar-s2.sh`. Se deriva de `importar-s1.sh`:
   - fotografía del flujo vivo antes y después;
   - 39 nodos, 12 con credencial;
   - se exporta de la base de n8n y se pasa el comprobador.
3. **Ensayo dentro de n8n con datos fijados.** Se fijan el disparador, los dos HTTP del CRM, los dos de `ai/check` y los siete de OpenAI, y se hace una ejecución por recorrido de §5.2: cero llamadas a Laravel y a OpenAI. Lo hace el lead o `devops` con el MCP, en su momento.
4. **Cambio:** `unpublish(S1 copia) → publish(S2 copia)`, con orden del propietario (§9).

### 5.5 Vuelta atrás

- `unpublish(S2) → publish(QSklqHlsDIxucRfk)`: la copia S1 queda intacta como reserva, el mismo mecanismo que S1 frente a `YspRwsXnqt33NouP`.
- Verificación: el siguiente turno canario sale con `flowRev S1` en `ultron:prompt-trace`.
- **Nunca reimportando un JSON** (no lleva los secretos de las credenciales).

---

## 6. Cambios por fichero, en olas (en secuencia, un agente cada vez)

Cada ola deja la batería verde, salvo las dos rojas esperadas: `PricingV2SalesAndPaymentsTest::test_manual_payment_uses_backend_quote` y la puerta de diff del H9 hasta subir. **El primer punto verde es el final de la ola 1.** En cada ola, las pruebas nuevas se escriben primero, rojas.

### Ola 1 · [backend] Piezas puras, sin cablear

- **Nuevos:**
  - `app/Services/Marketing/Ultron/UltronPlansOverview.php`: `select(array $policy, array $activePlans): list<int>`, extraída tal cual de `respaldoDePrecios()` `:5449-5466`; `markers(int $n)`; `expand(string $draft, array $textos /*id=>«Nombre (…)»*/, array $po): array{text, used}`; `verify(string $raw, array $po, array $activePlans): ?string` (el detalle o `null`); `items()`.
  - `UltronBenefitAttribution.php` (P-4).
  - `UltronRegenerationNotes.php`: `CODES`, `DETAILS`, código → `issue`, los textos fijos de §7.2, `merge()` ≤ 500.
  - `UltronDraftDefects.php`: dados el texto sin expandir, el expandido, `sp`, `po`, el catálogo y el entrante, devuelve la lista ordenada de defectos.
- **Cambian:**
  - `UltronDecideToken.php`: `peek()`.
  - `UltronCommitService::respaldoDePrecios()`: llama a `UltronPlansOverview::select()`, sin cambio de comportamiento.
- **Pruebas nuevas:**
  - `tests/Unit/Marketing/Ultron/UltronPlansOverviewTest.php`: la paridad de `select()` con la selección de hoy sobre una matriz de catálogos (nombrado, rechazados, sin planes de un mes, más de tres); la expansión, con duración y «1 día»; cada detalle de `verify()`.
  - `UltronBenefitAttributionTest.php`: propio, de otro plan, dos planes, negación, paráfrasis (no marca: límite), tildes y mayúsculas.
  - `UltronRegenerationNotesTest.php`: cada código da un `issue` del vocabulario y su nota literal; los parámetros; la mezcla; **ninguna nota contiene `{{`**.
  - `UltronDecideTokenPeekTest.php`.
  - Una prueba de que ninguna expresión nueva lleva `\p{`.
- **Existentes:** no cambia ninguna. `UltronA4LongitudYPreciosTest`, `UltronA4RegistroYPanoramaTest` y `a4-textos-*.md` siguen igual.

### Ola 2 · [backend] Compilador 4: el parche del motor y `sp.o`

- **Cambian:**
  - `Prompts/UltronPromptCompiler.php`: `ENGINE = 'engine.plansOverview'`, `T_PLANS_COMPOSER` y `T_PLANS_CRITIC` (§7.1). `patches()` los añade al **final** solo si ya hay algún parche gobernado. Para composer y composer_retry, `insert_after_line` de `FORMA:` (una vez en cada uno [C, `grep -c`]). Para critic y critic_retry, `progressive_disclosure:` (una vez en cada uno [C]). Se amplía el docblock de la versión 4 (**sin subir a 5:** el 4 no está publicado en ningún sitio).
  - `Prompts/UltronPromptOffer.php`: `sp['o'] = 1` si el paquete compilado trae parches de `ENGINE`.
  - **No se toca** `StudioPromptBundle.php`: `changes()` itera `PARAMETERS` (`:362`), así que la vista previa del Studio sigue enseñando solo lo gobernado. El parche del motor es «del motor», como la base, y lo cubre la huella. Una prueba lo fija.
- **Pruebas nuevas:**
  - `compile(BASELINE)` sigue siendo la base, con la huella Legacy.
  - Con cualquier valor no base, los cuatro parches del motor, al final, con anclas únicas, ≤ 1 200 B, roles ≤ 40 960 y paquete ≤ 163 840.
  - `sp.o` solo con Studio y parche.
  - La vista previa sin el parámetro del motor.
- **Existentes que cambian, y por qué:**
  - `UltronPromptCompileCasesTest` (**todas** las huellas no base: A42-12 decía «solo profesional»).
  - Los recuentos de `UltronPromptCompilerTest`.
  - Los fixtures de S1 regenerados con `php artisan ultron:prompts-n8n`: cambian `fixtures/decide-studio*.json`; el selector y Preparar Commit de S1 no. Con eso `UltronPromptsN8nCommandTest::test_lo_que_hay_en_el_repositorio_es_lo_que_genera_hoy` vuelve a verde.
  - Las pruebas de `UltronPublishedConfig*` si fijan huellas [S].

### Ola 3 · [backend] La lista la redacta el modelo (P-3 y P-4); funciona ya con S1

- **Cambian:**
  - `UltronDecideService.php`: `plans_overview` (§4.2) y `po` en la carga del token (`:412-425`).
  - `UltronDraftPlaceholders.php`: `unknown(string $draft, int $planesDeLaLista = 0)`, con `PLAN_1..n` admitidos solo si `n > 0`; la llamada de hoy, idéntica.
  - `UltronCommitService.php`:
    - `unknown()` con `count(po)`, o 0 si es turno de cobro (`:507`);
    - la expansión tras `:621`;
    - `$listaValida` y la regla de `:1726-1730`;
    - `VIOLACION_BENEFICIO` (con versión del Studio) junto a las de longitud y registro (`:1693-1700`);
    - `$precios = ['planes' => …]` para `conPreciosEntregados()` cuando sale la lista del modelo;
    - `metadata.plans_overview` y `metadata.benefits`.
- **Pruebas nuevas** (`tests/Feature/Marketing/UltronA4ListaDelModeloTest.php`, por el endpoint):
  - «¿Qué planes tienen y cuánto cuesta la mensualidad?» con un borrador de dos o tres marcadores: sin `policy_plans_overview`, con nombres y precios de `Plan` en ese momento, la longitud medida expandida y la memoria con los precios entregados de cada plan;
  - el precio cambiado entre `decide` y `commit`: sale el nuevo;
  - un plan que deja de ser vendible: lista fija;
  - `PLAN_4` con tres ofrecidos y `PLAN_1` sin lista: `unknown_placeholder`;
  - un turno Legacy con `PLAN_1`: 422 como hoy;
  - el nombre escrito a mano, mezcla, repetido: la lista fija;
  - un turno de cobro: la lista no vale;
  - un beneficio de otro plan: la rama de la política;
  - `cercano`, `entusiasta` y `profesional`.
- **Existentes:**
  - `UltronDecideTest` y `UltronDecidePromptsTest` solo si fijan las claves exactas de `commercial_turn_policy` en un turno del Studio con panorama [S].
  - **Legacy: las dos instantáneas se ejecutan sin regenerar.**

### Ola 4 · [backend] El punto de comprobación, el presupuesto y la traza (P-1, P-2, P-7 en el servidor, P-8)

- **Cambian:**
  - `routes/marketing.php`: `Route::post('ai/check', [UltronController::class, 'check'])` dentro del grupo de `:44`, y el comentario de las puertas (`:65-93`).
  - `app/Http/Controllers/Api/Internal/UltronController.php`: `check()` (§4.1) y las reglas del commit (§4.3, `:260` y `:435`).
  - Nuevo `UltronDraftCheck.php`: servicio de solo lectura, `TIME_BUDGET_SECONDS = 25`.
  - `UltronDecideService.php`: el bloque `regeneration`.
  - `CriticContract.php:107`: el recorte a 3 y su comentario.
  - `UltronCommitService::persist()`: `metadata.regeneration`.
  - Nuevo `UltronRegenerationTrace.php`: bloque → metadata.
- **Pruebas nuevas:**
  - `tests/Feature/Marketing/UltronCheckEndpointTest.php`:
    - las filas de §3 P-8;
    - cada código con su nota literal;
    - `attempt` 3 → `last_attempt`;
    - 26 s → `time_budget`, con `Carbon::setTestNow` **en UTC** (trampa conocida de la suite);
    - el token sin Studio → `legacy`.
  - `UltronCheckCommitParityTest.php`: una matriz de borradores sobre el mismo turno. La comprobación marca `register_tuteo`, `length_over_cap`, `plans_overview` o `benefit_misattributed` **si y solo si** el commit descarta, recorta o viola por esa causa. Lleva excepciones documentadas cuando el commit pega o retira texto (H7).
  - Commit con el bloque (la fila lo lleva), sin el bloque (fila idéntica a la de hoy) y con un bloque incoherente (solo `consistent: false`); `attempt` 3 aceptado.
  - `InternalSecretAuthoritySurfaceTest`: la tercera puerta.
- **Existentes:**
  - `UltronCriticContractTest`, si fija el tope 2 [S; el grep no encontró ninguna que lo afirme].
  - El docblock de `InternalSecretAuthoritySurfaceTest.php:20` («dos puertas»).

### Ola 5 · [backend; `devops` para el importador] El flujo S2, generado y comprobado

- **Cambian:**
  - `Prompts/UltronPromptsN8nFlow.php`: `selectorCode(string $flowRev = self::FLOW_REV)`. S1, igual.
  - Nuevo `Prompts/UltronRegenN8nFlow.php`.
  - `app/Console/Commands/Marketing/UltronPromptsN8n.php`: `--rev=S1|S2`.
  - Los artefactos de `n8n/flujos/ultron-studio-s2/`.
  - `ops/motores/verificar-flujo.cjs` (modo S2: A a J).
  - `ops/motores/n8n-copia-s2/LEEME.md` e `importar-s2.sh` (`devops`, sin ejecutar).
- **Pruebas nuevas:**
  - `UltronPromptsN8nS2CommandTest.php`:
    - es determinista;
    - **S1 sale byte a byte igual**;
    - el comprobador pasa sobre lo generado y falla con cada mutación: un redactor de más, un IF que salta la comprobación, el `values[1]` de un nodo de S1 tocado, una clave de más en el cuerpo del check, Preparar Commit sin el `try` del bloque.
  - `UltronCatalogStaticGuardsTest`: ampliada a `n8n/flujos/ultron-studio-s2/*.js` (hoy mira `ultron-studio-s1`, `:15` y `:96-97` [C]).

### Ola 6 · [backend] El informe de origen (P-7)

- **Nuevos:**
  - `UltronReplyOrigin.php` (puro): `classify(array $metadata): array{origin, drafts, rewrites, fallback_reason, trimmed}`.
  - `app/Console/Commands/Marketing/UltronOriginReport.php`: `ultron:origin-report {--conversation=*} {--since=} {--until=} {--json} {--precio-entrada=} {--precio-salida=}`.
- **Salida:**
  - total;
  - **porcentaje escrito por la IA frente al 90 %**;
  - primer intento, regeneradas (por servidor o revisor y por código) y respaldos (por causa);
  - recortadas;
  - latencia p50, p95 y máxima;
  - llamadas por turno (1 + `drafts` + `critics`);
  - tokens y costo, si existen;
  - reintentos del job.
- El universo es el de la consulta de la línea base: `reply` y `executed`; el resto, en una línea aparte.
- **Pruebas:**
  - la clasificación de cada camino con metadata de fixture (normal, recortado, política, revisor caído, rendición, la hora de la visita, repetida recuperada);
  - los agregados y el JSON;
  - sin datos personales;
  - **reproduce el reparto de la línea base** (71/14/3) sobre filas equivalentes sin bloque.

### Ola 7 · [tester] Regresión

- Las dirigidas del motor; las A4; las dos instantáneas Legacy; el compilador y los prompts; Marketing entero.
- **La batería completa en el árbol limpio:** solo las dos rojas esperadas.
- Los comprobadores de S1 y de S2.
- La sonda de PCRE con las expresiones nuevas: se prepara el fichero; **la ejecuta el lead en el servidor con orden**.
- Casos límite que tienen que quedar cubiertos:
  - el tercer borrador sin comprobar que el commit recorta;
  - el servidor caído (la comprobación devuelve 404 o 5xx: camino de hoy y sin tercer borrador);
  - el presupuesto agotado justo entre la comprobación 2 y el revisor 2;
  - la lista con un plan que se archiva entre `decide` y `commit`;
  - el tuteo solo en una cita de la persona (no marca);
  - un entrante con el teléfono (no sale en las notas).

**Después, una pasada de cada uno:** [`security`/`api-security-specialist`], sobre P-8 y el dinero (lista en un turno de cobro, marcadores, notas); y [`reviewer`]. Luego los documentos: la **Enmienda A4-3** del H5, que lista los ficheros que ceden ante las protecciones 5 a 8 y la puerta de diff, como A42-14 (aquí se suman `app/Http/Controllers/Api/Internal/UltronController.php` y `routes/marketing.php`), y `plans/informes-motores/a4-textos-regenerar.md`, generado y fijado por una prueba, para el gate 4-H.

**Paralelismo:** ninguno sobre el árbol (regla del encargo). Solo `security` puede **leer** las olas 3 y 4 mientras se hace la 5.

---

## 7. Los textos fijos nuevos (para el gate 4-H, literales)

### 7.1 Instrucciones al modelo (parche del motor del compilador 4; sin tildes, como el resto)

**`T_PLANS_COMPOSER`** (composer y composer_retry, `insert_after_line` de `FORMA:`):
> LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales mostrar. Esto manda sobre las reglas de precios y marcadores de este prompt: nombra al menos dos de esos planes, en el orden en que vienen, escribiendo por cada uno SOLO su marcador (PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves), que el backend sustituye por el nombre y el precio reales. No escribas sus nombres ni cifras, no uses PLAN_NAME, PLAN_PRICE ni PLAN_DURATION y no repitas un marcador. Para decir para quien es cada uno usa solo los benefits de su plan_id en active_plans. Si plans_overview no viene, nada de esto aplica.

**`T_PLANS_CRITIC`** (critic y critic_retry, `insert_after_line` de `progressive_disclosure:`):
> LISTA DE PLANES DEL BACKEND: los marcadores PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves los sustituye el backend por el nombre y el precio reales de planes que el mismo eligio. Cuentan como nombre y como marcador de precio de ese plan: un borrador que los usa para dar varios planes con su precio NO esquiva el precio, no es invented_plan ni invented_price, y nombrar dos o tres no es defecto de progressive_disclosure. Lo demas se juzga como siempre.

### 7.2 Notas de reescritura (cruzan al modelo en `critic_notes`)

| Id | Texto (literal; `{…}` = parámetro) | Parámetros |
|---|---|---|
| N-R | REESCRIBE DE USTED: la version publicada trata a la persona de usted y el borrador tutea ({MARCAS}). Pasa todo el mensaje a usted sin mezclar (le, su, usted, cuenteme, quiere) y conserva lo que decia. | `MARCAS`: hasta 5 de la lista cerrada de `UltronRegisterDetector`, separadas por coma |
| N-L | DEMASIADO LARGO: el borrador mide {L} caracteres y el limite publicado es {N}. Reescribelo en unos {M}: primero la respuesta a lo que pregunto la persona, sin relleno, y como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye. | `L` medida expandida; `N` = `sp.n`; `M` = `composerTargetChars(N)` |
| N-P1 | FALTA LA LISTA DE PLANES: la persona pidio los planes o los precios en general. Nombra al menos dos planes de commercial_turn_policy.plans_overview, en su orden, escribiendo solo sus marcadores PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves; el backend pone el nombre y el precio. Sin PLAN_NAME ni PLAN_PRICE. | — |
| N-P2 | MARCADORES MEZCLADOS: con la lista de planes usa solo PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves, cada uno una sola vez, sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. | — |
| N-P3 | NOMBRE DUPLICADO: no escribas el nombre de un plan de la lista; su marcador ya pone el nombre y el precio. | — |
| N-P4 | MARCADOR INEXISTENTE: este turno solo tiene PLAN_1 a PLAN_{K}. Usa solo esos, entre dobles llaves. | `K` = `count(po)` |
| N-P5 | SIN LISTA DE PLANES: en este turno no uses PLAN_1, PLAN_2 ni PLAN_3. Para un precio usa PLAN_PRICE con el plan de la estrategia. | — |
| N-P6 | SIN CIFRAS: no escribas precios ni cifras de dinero; el backend los pone con los marcadores. | — |
| N-B | BENEFICIO DE OTRO PLAN: el borrador atribuye a {PLAN} un beneficio que en active_plans es de otro plan ("{BENEFICIO}"). A cada plan, solo los benefits de su plan_id. | `PLAN`: el marcador (`PLAN_2`) o el nombre del catálogo; `BENEFICIO`: el del catálogo, ≤ 60 caracteres y sin llaves |

### 7.3 Texto para el cliente (formato de la expansión)

- **Recomendado:** «`Nombre ($65.000 COP)`»; si el plan no es de 28 a 31 días, «`Nombre (7 días, $45.000 COP)`», con «1 día» en singular (la regla F5 de `respaldoDePrecios()`).
- **Alternativa** (la del lead): «`Nombre, $65.000 COP`», el formato de la lista fija. Dentro de una frase choca con las comas de la enumeración.
- El precio sale de `formatCop()` (`SalesConversationReplyService.php:334`).
- **Lo decide el propietario.**

Quedan pendientes del gate 4-G y siguen igual: las cuatro instrucciones del compilador 4, los 106 textos de usted, los dos encabezados y el modo «descartar el que tutea».

---

## 8. Costo y latencia

| Camino | Hoy (S1) | Después: Studio (S2) | Después: Legacy |
|---|---|---|---|
| Pasa a la primera | 3 llamadas al modelo | 3 + 1 comprobación | 3 |
| El servidor pide 1 reescritura | — (hoy el commit recortaba o descartaba) | 4 + 2 comprobaciones (se ahorra el revisor del borrador rechazado) | — |
| El revisor pide 1 reescritura | 5 | 5 + 2 comprobaciones | 5 |
| Dos reescrituras (cualquier combinación) | imposible | 5 a 7 + 2 comprobaciones | imposible |
| **Peor caso** | **5** | **7** (+40 %) | **5** |

- **Latencia** [S, de H17 y del comentario de `config/marketing.php:59`]:
  - unos 2,5–3,5 s por llamada al modelo y unos 0,1–0,3 s por comprobación;
  - el peor camino típico pasa de unos 15–18 s hoy a unos 22–27 s;
  - **topes:** sin reescrituras nuevas pasados 25 s desde `decide`, timeout de 3 s y sin reintentos en la comprobación; ningún camino supera los 3 borradores;
  - en el peor caso con un reintento de nodo de OpenAI, unos 40 s, por debajo de los 45 s del job (que **no se toca**: es configuración de producción).
- **Tokens:** cada llamada de redactor o revisor lleva entre 15 y 27 KB de sistema (los tamaños de `LEEME.md` de S1 [C]) más el contexto, unos 6.000–12.000 tokens de entrada [S]. El informe lo mide si n8n entrega `usage`.
- **Efecto esperado sobre la línea base** [S]:
  - de los 12 turnos rechazados dos veces, un tercer borrador rescataría la mitad si rinde como el segundo (11 de 23): unos 6;
  - la IA pasaría de 71/88 a ≈ 77/88 ≈ 87–88 %;
  - el registro, la longitud y la lista ya no pasarían a respaldo, sino a reescritura; sin esta intervención, el compilador 4 los **descartaría**.
  - **El 90 % no está garantizado** (§10, R10).

---

## 9. Despliegue y vuelta atrás (solo el plan; NO se despliega)

**Orden: primero el servidor y después n8n.** Las tres razones:

- (1) S2 con el backend de hoy se comporta como S1, porque `decide` no ofrece `regeneration` (el bloque y el tercer intento van condicionados a esa oferta). Pero S2 con el backend de hoy nunca usaría la comprobación.
- (2) El backend nuevo con S1 funciona: S1 no manda el bloque ni `attempt = 3`, no llama a `ai/check` e ignora `regeneration`. **La lista de P-3 funciona ya con S1.**
- (3) Por tanto, cualquier orden es seguro. El de servidor primero deja probar P-3 sola y vuelve atrás por partes.

| Paso | Qué | Quién y con qué orden | Qué queda inerte |
|---|---|---|---|
| 1 | **Backend:** código y la ruta nueva. **`hotfix-backend.sh` no sirve** (rechaza `backend/routes/*`, `:77` [C]): hace falta un guion derivado que, tras el `merge --ff-only`, ejecute `route:cache` como www-data con umask 077 (el patrón de `deploy-h9-backend-cierre.sh:691`) y compruebe con `route:list` que `internal/marketing/ai/{decide,commit,check,ultron/incidents}` existen; sin migraciones ni `config/` | `devops` prepara; el lead ejecuta con autorización del gate 4-H | **Punto de no retorno ya conocido (A4-2):** el compilador 4 cambia las huellas y la versión 2 cae a Legacy (`compiler_changed`). Sin versión del Studio no hay `regeneration`, ni lista, ni comprobación |
| 2 | **n8n:** copia S2 importada inactiva → comprobador → ensayo con datos fijados → `unpublish(S1) → publish(S2)` | Lead o `devops` con el MCP, con orden | Con Legacy, S2 = S1 (lo prueba el comprobador, en los apartados G e I). Primer turno canario: `legacy`, `flowRev S2` |
| 3 | **Versión 3** aprobada con su veredicto y publicada en canario (07:00–19:00) | El propietario (HUMAN GATE) | Solo entonces se activan la comprobación, la reescritura y la lista, y solo en la canaria |
| 4 | Tercera prueba real con una conversación canaria limpia | El propietario | — |

**Vuelta atrás, por capas, sin migraciones:**

- **(a) La versión:** «Revertir» desde el Studio → Legacy.
- **(b) n8n:** `unpublish(S2) → publish(S1)`.
- **(c) El backend:** `git checkout --detach <PREVIO>`, `route:cache` y `queue:restart` (el guion lo imprime). Con S2 publicado y el backend anterior, S2 se comporta como S1.
- Ninguna fila nueva lo impide: todo es metadata aditiva.

---

## 10. Riesgos, lo que NO cubre y cómo se cumplen las diez condiciones

| # | Riesgo | Mitigación |
|---|---|---|
| R1 | Un turno de más de 45 s hace que el job reintente (otra ejecución entera y un 409) | Presupuesto de 25 s, comprobación sin reintentos y de 3 s; el informe cuenta `attempts > 1`; criterio de §12 |
| R2 | El token caduca (120 s) → `stale_decision` → rendición | Cubierto por R1: el techo de 45 s llega antes |
| R3 | La comprobación y el commit divergen: el commit pega o retira texto (H7) | Prueba de paridad; el commit sigue siendo la red; el informe cuenta las respuestas del último borrador que el commit descartó |
| R4 | Cubo compartido de 120/min (H18): con dos comprobaciones por turno, la capacidad de turnos baja a la mitad | Irrelevante con la canaria; **condición antes de ampliar** el alcance de ULTRON |
| R5 | El modelo no sigue la instrucción de los marcadores, o el revisor tumba la lista pese al parche | Las reescrituras con N-P1 a N-P6; si no basta, la lista fija; se mide con `metadata.plans_overview` y `policy_plans_overview` |
| R6 | Falsos positivos de P-4, que llevan a más respaldos | Regla literal y conservadora; se mide `policy_benefit_misattributed`; si aparece uno en real, se revisa su prueba |
| R7 | El compilador 4 mueve **todas** las huellas no base (antes, solo «profesional») | El 4 no está publicado; la versión 2 ya caía a Legacy al desplegar el 4. Coste nuevo: cero |
| R8 | Si el negocio marca como recomendado un plan que no es de un mes, el primero de la lista no es el obligatorio → `policy_wrong_first_plan` | Hoy ningún plan está marcado (H4-8 de la matriz [L]); queda anotado; si pasa, la rama de la política (igual que hoy con la lista fija) |
| R9 | Errores de n8n por referencias a nodos que no corrieron | Las decisiones que miran nodos opcionales van en Code con `try`; los IF solo miran nodos que siempre corrieron o booleanos ya calculados; comprobador (apartados H e I) y ensayo |
| R10 | **No llegar al 90 %** | La causa mayor de doble rechazo es H4-4: en la fase de cierre los revisores se contradicen (7 de 17), y eso está en `critic.txt` y `critic_retry.txt`, **fuera de esta intervención**. Se informa con el porcentaje real, sin redondear |
| R11 | La puerta de diff del H9 en rojo hasta subir | Esperado (hecho 7 del encargo); Enmienda A4-3 |

**Lo que NO cubre:**

- Las violaciones de política (§11).
- El guard de contenido y los 422 de dinero, de membresía, de URL o de tarjeta, que siguen yendo a la rendición.
- Las paráfrasis de beneficios.
- Las preguntas compuestas de A42-16.
- H4-4, H4-6 y H4-11 (C-1 sigue siendo condición antes de ampliar).
- Los enlaces de la app en la medida de la comprobación, que es una aproximación.
- Legacy, que atiende a los clientes y no cambia.

**Lo que recomiendo dejar fuera** (condición 8):

- Tocar los prompts base, el `MARKETING_ULTRON_TIMEOUT`, `canary-report`, el CRM y el mapeo de etapas del flujo de errores (los nodos nuevos entran como `unknown`, y eso está previsto en `UltronController.php:58-62`).
- La comprobación estructurada de `access_classes`.
- Extraer más piezas del commit.
- Las violaciones de política.

| Condición del propietario | Cómo se cumple | ¿Del todo? |
|---|---|---|
| 1 · La IA genera | El texto fijo solo sale cuando fallan los tres borradores o lo exige una regla | Sí |
| 2 · Reescritura guiada, como mucho dos más | Por la estructura (S2) y por el servidor (`attempt`, tiempo) | **En parte:** «alguna regla corregible» se limita a registro, longitud, lista y beneficios, más el revisor; la política, fuera (§11) |
| 3 · El backend es la fuente: nombres, precios y beneficios | Nombre y precio por marcador firmado, de `Plan` en el commit; beneficios con P-4 | **En parte** en los beneficios (límite declarado de P-4) |
| 4 · Los 106 textos como respaldo, de usted | No se tocan | Sí |
| 5 · Métricas y objetivo del 90 % | `metadata.regeneration` + `ultron:origin-report` + `canary-report` | Se mide; el 90 % no está garantizado (R10) |
| 6 · Sin reintentos ilimitados; los controles se mantienen | Tope estructural de 3, 25 s, comprobación sin reintentos; el commit, intacto como red | Sí |
| 7 · Pruebas y regresión | Olas 1 a 7; instantáneas Legacy sin regenerar | Sí |
| 8 · Limitar la intervención | Lo de arriba | Sí |
| 9 · No desplegar todavía | Este plan no despliega | Sí |
| 10 · HUMAN GATE | Los pasos 1 a 4 de §9 pasan por el gate 4-H | Sí |

---

## 11. Preguntas abiertas (solo las que cambian el plan)

1. **¿Entran las violaciones de política en la reescritura guiada?** Son `policy_greeting_sells`, `policy_active_goal_lost`, `policy_wrong_first_plan`, `policy_answer_without_fact` y `policy_general_info_only_discovery`.
   - La condición 2 dice «alguna regla corregible» y en la línea base pesan un 3 % (3 de 88).
   - **Mi recomendación es dejarlas fuera en esta intervención:** su respaldo está escrito para cumplir la regla, y el encargo pide no ampliar la lista.
   - Si el propietario dice que sí, el plan no cambia de forma: una ola corta más (los códigos, cinco notas para aprobar y su paridad). La comprobación tendría que calcular la política del turno con la memoria (lecturas, sin escrituras).

El formato de la expansión (§7.3) y los textos de §7 se aprueban en el gate 4-H, pero no cambian el plan.

---

## 12. Criterios de aceptación medibles

**Antes de la tercera prueba real** (técnicos, en el árbol):

- Batería completa con solo las dos rojas esperadas.
- Paridad de la comprobación con el commit en verde.
- Comprobadores de S1 y de S2: `OK`.
- `compile(BASELINE)` = base con la huella `3f6a5674…c902`.
- Instantáneas Legacy sin regenerar y en verde.
- Sonda de PCRE con las expresiones nuevas: la misma huella en local y en producción.

**Tercera prueba real** (canaria limpia, versión 3 «profesional, 200», S2 publicado; los mismos tres mensajes de 4-E para poder comparar):

| # | Criterio | Cómo se lee |
|---|---|---|
| A1 | Los tres turnos `applied`, con `flowRev S2` y la huella de la versión 3 | `ultron:prompt-trace`, `metadata.studio_prompts` |
| A2 | Cero en todos los contadores mecánicos (cifras fuera del catálogo, planes no vendibles, enlaces no oficiales, datos personales) | `ultron:canary-report --since=` |
| A3 | Cada respuesta ≤ 200 (`metadata.length.to`) y **sin tuteo** en lo que salió (`metadata.register.found = []`, sin `fallback_mismatch`) | Fila |
| A4 | El turno 2 lo escribe **el modelo**, con **al menos dos planes** con nombre y precio de `Plan` en ese momento: `metadata.plans_overview.valid = true`, `used` ≥ 2, sin `policy_plans_overview`, y la memoria con los precios entregados de esos planes | Fila y memoria |
| A5 | En los tres: `metadata.regeneration` presente, `drafts` ≤ 3, `consistent = true` y `critic.attempt = drafts` | Fila |
| A6 | Cada turno, menos de 30 s del entrante al saliente y **cero reintentos del job** (`attempts = 1`) | `ultron:origin-report`, `marketing_automation_events` |
| A7 | Si hubo respaldo, con su causa en el informe y sin «sin dato» | `ultron:origin-report` |
| A8 | La valoración del propietario (naturalidad, trato, utilidad) | Él (HUMAN GATE) |

**Informe de métricas** (a partir de la tercera prueba; el 90 % se juzga con una muestra de **al menos 30 turnos del Studio**, no con tres):

- Escrito por la IA (primer intento + regenerados) ≥ 90 %, y además, por separado, qué parte fue al primer intento.
- Reescrituras por causa y respaldos por causa.
- Ningún turno con más de 3 borradores (estructural).
- Latencia p95 ≤ 30 s y máxima < 45 s.
- Una media de llamadas al modelo por turno ≤ 4 y una máxima de 7.
- Cero `stale_decision` y cero reintentos del job por tiempo.
- Contadores mecánicos a cero.
- Sobre las filas históricas, el informe reproduce la línea base (71/14/3 de 88).

---

## 13. Decisiones del lead sobre este plan (19-11-2026)

Añadido por el lead tras leer el plan; lo de arriba es del arquitecto y no se ha tocado.

| # | Decisión |
|---|---|
| L-1 | **Se adopta la opción A** (§2): `POST ai/check` de solo lectura y el flujo S2 desenrollado. El commit sigue siendo la última red, sin cambios de orden |
| L-2 | **Marcadores `{{PLAN_1}}` a `{{PLAN_3}}`** sobre la lista firmada `po` (P-3, cambio 1). El formato de la expansión es el recomendado, «Nombre ($65.000 COP)», y lo aprueba el propietario en el gate 4-H; si prefiere el de la lista fija, es una constante |
| L-3 | **La pregunta abierta de §11 se resuelve con las palabras del propietario.** Su condición 2 dice «el trato profesional, la longitud o alguna regla corregible»: las violaciones de política del servidor que el modelo puede corregir reescribiendo (`policy_greeting_sells`, `policy_active_goal_lost`, `policy_wrong_first_plan`, `policy_answer_without_fact` y `policy_general_info_only_discovery`) **entran** en la reescritura guiada. Van en una **ola 4b**, después de la 4 y separada, para que se pueda recortar si la paridad con el commit no sale limpia; en ese caso se declara en el gate. Sus cinco notas las redacta el lead y las aprueba el propietario |
| L-4 | **H4-4 queda fuera** (los dos revisores se contradicen en fase de cierre con los enlaces de pago apagados): vive en los prompts base. El 90 % es el objetivo de calidad que pide el propietario, se mide y se informa sin redondear; no se promete |
| L-5 | **P-4 (beneficios) con su límite declarado:** la condición 3 se cumple del todo en nombres y precios y solo en parte en beneficios. Se le dice al propietario tal cual en el gate |
| L-6 | **El n8n en marcha no se toca antes del gate 4-H.** De la ola 5 se entrega el flujo S2 generado, su comprobador y el guion de importación sin ejecutar. La copia inactiva, el ensayo con datos fijados y el cambio de S1 a S2 son pasos del despliegue, con su orden |
| L-7 | **Agrupación para implementar, en secuencia y con un solo agente cada vez:** (A) olas 1 y 2; (B) ola 3; (C) olas 4, 4b y 6; (D) ola 5. Después, regresión, `security` y `reviewer` (una pasada cada uno, con el criterio de parada de siempre: bloqueante → se corrige; lo demás, a pendientes), la Enmienda A4-3 y el gate 4-H |

