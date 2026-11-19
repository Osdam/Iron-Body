Eres el agente `backend` de ULTRON STUDIO (Iron Body: API Laravel 12 / PHP 8.3 / PostgreSQL 14). Implementas, con pruebas, las OLAS 4b y 6 de «regenerar antes de sustituir» en el motor de WhatsApp (ULTRON): (4b) que las reglas de política del servidor que el modelo puede corregir reescribiendo también vayan a reescritura guiada, y (6) el informe de quién escribió cada respuesta. Todo en español, con el estilo de la casa. Eres el único agente trabajando en este árbol.

## De qué va (para situarte)

El propietario decidió (19-11) que la IA sea el generador principal de respuestas: cuando una protección rechaza el borrador del modelo por algo corregible, el modelo reescribe con el defecto exacto, como mucho dos veces más, y el texto fijo queda como última red. Sus diez condiciones, literales: `docs/ultron-studio/MATRIZ_CIERRE_V2.md` §5. La 2 dice «el trato profesional, la longitud o ALGUNA REGLA CORREGIBLE»; la 5 pide métricas que distingan respuestas del modelo, regeneradas y respaldos, con el 90 % por IA como objetivo de calidad.

## El plan manda

`docs/ultron-studio/plans/informes-motores/plan-arquitecto-a4-regenerar.md`: §3 P-5 y P-7, §4.1, §4.4, §6 «Ola 6», §11, §12 y §13 (decisiones del lead, que mandan: L-3 es la ola 4b). Lo ya hecho: `docs/ultron-studio/plans/informes-motores/informe-backend-a4-regenerar.md` (olas 1 a 4). La puerta `POST /api/internal/marketing/ai/check` (`UltronDraftCheck`), `UltronDraftDefects`, `UltronRegenerationNotes` y la traza `metadata.regeneration` YA EXISTEN: amplíalas, no las dupliques.

Árbol: `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`. Mira `git log --oneline -5` y `git status`: árbol limpio, con las olas 1 a 4 confirmadas. Nada de esta rama está desplegado.

## Ola 4b · Las reglas de política corregibles

Hoy, cuando `CommercialTurnPolicy::violations()` marca el borrador, el commit lo sustituye por un texto del servidor (3 de 88 respuestas en la línea base). Con versión del Studio, la comprobación `ai/check` debe marcarlas ANTES, para que el modelo reescriba:

- La comprobación calcula la política del turno IGUAL que el commit (mismas funciones, mismas entradas; solo lecturas: la memoria se LEE, no se escribe) y pasa el borrador expandido por `CommercialTurnPolicy::violations()`.
- Códigos nuevos en `UltronRegenerationNotes`, uno por violación corregible, con su `issue` del vocabulario de `CriticContract::DIMENSIONS` y su nota fija. Las seis notas, LITERALES (son textos fijos que aprobará el propietario; van sin tildes porque cruzan al modelo; si alguna CONTRADICE lo que la regla exige de verdad en el código, no la arregles tú: dímelo con la línea y propón la corrección):

| Código (= el de la violación) | Nota |
|---|---|
| `policy_greeting_sells` | SALUDO QUE VENDE: la persona solo saludo. Saluda, da la bienvenida y pregunta en que le puede ayudar. No nombres planes, precios ni pagos y no preguntes todavia su objetivo. |
| `policy_active_goal_lost` | VISITA A MEDIAS: hay una visita de cortesia a medio concretar. Contesta en una frase lo que pregunto y retoma la visita pidiendo solo el dato que falta (el dia o la hora). No vendas planes ni precios en este turno. |
| `policy_wrong_first_plan` | PRIMER PLAN EQUIVOCADO: el primer plan que nombras tiene que ser el de la estrategia de este turno. Nombralo primero; los demas, solo despues y si aportan. |
| `policy_answer_without_fact` | FALTA EL DATO: la persona pidio informacion del gimnasio y el borrador no da ningun dato de knowledge_base. Responde primero con el dato pedido (direccion, horario o servicios) y deja como mucho una pregunta al final. |
| `policy_general_info_only_discovery` | SOLO PREGUNTAS: la persona pidio informacion general y el borrador solo pregunta. Da primero informacion concreta del gimnasio y deja como mucho una pregunta al final. |
| `policy_memory_turn_sells` | PREGUNTA DE MEMORIA: la persona pregunto por algo ya acordado (su visita). Responde solo eso, con el dato del compromiso. No vendas ni preguntes su objetivo en este turno. |

- NO entran (siguen como hoy, con su respaldo): `policy_greeting_repeated` (el commit ya lo arregla recortando la fórmula), la contradicción con el compromiso real de la visita (`VIOLACION_MEMORIA_DATO`: el dato lo pone la fila del CRM), el guard de contenido, las guardas de dinero y de membresía, el no contactar, el relevo y las herramientas.
- El orden de los defectos en la respuesta: primero los de §4.4 (lista, registro, longitud, beneficios), después los de política. `defects` sigue con un tope (súbelo lo justo y dilo) y `notes` sigue cortándose por notas enteras a 500 caracteres.
- El commit NO cambia: sigue sustituyendo cuando la violación llega en el último borrador.
- **Paridad (la prueba que decide si esta ola entra):** amplía `UltronCheckCommitParityTest`: sobre el mismo turno, la comprobación marca cada código de política SI Y SOLO SI el commit lo pone en `policy_violations` con ese borrador. Con casos por el endpoint de cada una de las seis reglas (el saludo que vende, la visita a medias, el primer plan equivocado, información sin dato, solo preguntas, memoria que vende) y sus negativos. **Si la paridad no sale limpia para alguna regla** (porque el commit calcula la política con algo que la comprobación no puede leer sin escribir, o porque depende de herramientas ya ejecutadas), NO la fuerces: deja esa regla FUERA, dime cuál y por qué, y sigue con las demás. Es preferible dejar fuera una regla que marcar reescrituras que el commit no habría pedido.

Invariantes: sin versión del Studio, nada cambia (las instantáneas de `tests/fixtures/ultron-a4/` siguen en verde SIN regenerarse); la puerta sigue siendo de solo lectura (las pruebas de la ola 4 que lo fijan siguen en verde: solo `select`, sin cerrojo, sin red, cero filas nuevas, sin texto de la persona en la respuesta ni en el log).

## Ola 6 · El informe de origen (plan §6, Ola 6, y §3 P-7)

- `UltronReplyOrigin` (pura): `classify(array $metadata)` → de dónde salió el texto de una respuesta: el modelo al primer intento; el modelo tras 1 o 2 reescrituras (y quién las pidió: el servidor o el revisor, con sus códigos); respaldo (y por qué: el revisor rechazó todos los intentos, una regla de política, la rendición…); recortada. Las reglas tienen que clasificar las filas HISTÓRICAS (sin `metadata.regeneration`) igual que la consulta de la línea base: `docs/ultron-studio/ops/comprobacion-produccion/origen-respuestas.sql` (71 del modelo, 14 respaldo del revisor, 3 por política, sobre 88).
- Comando de consola de SOLO LECTURA `ultron:origin-report` con las opciones y la salida de la «Ola 6» del plan: total; porcentaje escrito por la IA frente al objetivo del 90 % (y, aparte, qué parte al primer intento); regeneradas por quién y por qué código; respaldos por causa; recortadas; latencia p50, p95 y máxima (del entrante al saliente, con lo que ya hay en las tablas); llamadas al modelo por turno; tokens y costo estimado si existen; reintentos del job. Salida de texto y `--json`. Sin datos personales ni texto de mensajes. `ultron:canary-report` NO se toca.
- Pruebas: la clasificación de cada camino con metadata de fixture; los agregados y el JSON; que no salen datos personales; y que sobre filas equivalentes a las históricas reproduce el reparto de la línea base (71/14/3).

## Pruebas (desde `backend/`, UNA ejecución de PHPUnit cada vez; primero las nuevas en ROJO, guarda la salida)

`nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging <ficheros>`. Al final: `tests/Unit/Marketing/Ultron`, los A4, `UltronCheckEndpointTest`, `UltronCheckCommitParityTest`, `UltronDecideTest`, `UltronCommitTest`, las del informe; `tests/Feature/Marketing tests/Unit/Marketing` enteros; las nuevas en PostgreSQL (`UNIT_DB=ultron_studio_lead_ola5 nice -n 10 bash docs/ultron-studio/ops/pgunit.sh --no-logging <ficheros>`: el informe usa agregados y percentiles, y SQLite no ve lo que PostgreSQL rechaza); las guardas (una roja esperada: la puerta de diff del H9); `vendor/bin/pint` sobre lo tocado.

## Límites

No toques el compilador, los prompts base, `config/`, migraciones, rutas, `SalesConversationReplyService.php`, `SalesObjectionResponderService.php`, el guard, `CourtesyRequestService.php`, el CRM ni nada de n8n. Nada de git que escriba, nada de producción. No relajes ninguna prueba. Si algo no encaja, PARA y dímelo con la línea.

## Entregable

Código y pruebas en el árbol, SIN commit. Dos secciones nuevas en `docs/ultron-studio/plans/informes-motores/informe-backend-a4-regenerar.md` («5. Ola 4b» y «6. Ola 6»): ficheros tocados, qué reglas de política entraron y cuáles quedaron fuera y por qué, la tabla de paridad, un ejemplo real de la salida del informe (con datos de prueba), decisiones tuyas, aserciones cambiadas, los números y lo que no pudiste comprobar. Salidas de PHPUnit en `a4-evidencia/` con el número siguiente al último que haya. Respuesta final a mí en pocas líneas, con los números y los ficheros de producto tocados.
