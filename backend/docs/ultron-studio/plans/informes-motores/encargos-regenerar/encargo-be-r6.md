Eres el agente `backend` de ULTRON STUDIO (Iron Body: API Laravel 12 / PHP 8.3 / PostgreSQL 14; n8n). Haces, con pruebas, la RONDA ÚNICA DE CORRECCIONES de «regenerar antes de sustituir» en el motor de WhatsApp (ULTRON), con lo que encontraron las dos revisiones. No hay bloqueantes; son cuatro hallazgos importantes y varios menores. Todo en español, con el estilo de la casa. Eres el único agente trabajando en este árbol.

## Contexto (lee primero, y solo esto)

Árbol: `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body/backend` (y `n8n/` en la raíz), rama `feat/ultron-studio-h9`. Mira `git log --oneline -4` y `git status`. Nada de esta rama está desplegado.

1. `docs/ultron-studio/plans/informes-motores/informe-reviewer-a4-regenerar.md`: §4 «Hallazgos» (I-1 a I-4 y m-1 a m-10) y §5 (lo que opina de R-1, R-4 y R-8).
2. `docs/ultron-studio/plans/informes-motores/informe-security-a4-regenerar.md`: §4 (R-1, R-4 y R-8, con sus parches propuestos en §5).
3. `docs/ultron-studio/plans/informes-motores/informe-backend-a4-regenerar.md`: lo implementado (secciones 3, 4, 5 y 6; la 6.9 trae dos correcciones del lead).
4. `docs/ultron-studio/MATRIZ_CIERRE_V2.md` §5: las diez condiciones del propietario, que son el criterio de aceptación.

## Decisiones del lead (cierran los hallazgos; no las cambies. Si una no se puede cumplir, PARA y dímelo con la línea)

**C1 · R-1 + I-1: ningún marcador puede salir por WhatsApp.**
- El nombre de un plan pierde las llaves `{` y `}` al expandirse (en `UltronPlansOverview::item()` y en el `NAME` de la resolución de `UltronDraftPlaceholders`), para que un nombre con llaves en el CRM no se reexpanda ni salga como marcador.
- Una red en `antesDeEnviar()`, SOLO con versión del Studio en el turno: si lo que va a salir lleva algo con forma de marcador (`{{` o `}}`; `{ PLAN_`; `PLAN_1`, `PLAN_2`… sueltos; `PLAN_PRICE`, `PLAN_NAME`, `PLAN_DURATION` o `APP_LINKS` sueltos), no sale: va el último recurso que corresponda, con un aviso en el log (sin el texto) y con constancia en la metadata, para que el informe de origen lo cuente como texto del servidor (C4).
- Sin versión del Studio no cambia nada: lo que hoy sale en Legacy, sale igual.
- Pruebas por el endpoint: las dos reproducciones de I-1 (`PLAN_1` sin llaves y `{PLAN_1}`, con `app_links_send`), con y sin herramientas, y un plan cuyo nombre lleva llaves en el catálogo. En lo que sale no aparece `PLAN_` ni una llave.

**C2 · I-2: la nota que corresponde al borrador típico.** En `UltronPlansOverview::verify()`: si el borrador no lleva NINGÚN marcador de la lista, el defecto es `missing` (nota «FALTA LA LISTA…»), antes que `name_written`. Prueba: el borrador del 18-11 («Claro. El Plan Valera cuesta {{PLAN_PRICE}} al mes e incluye acceso total al gimnasio. ¿Le gustaría conocer más?») da `missing`.

**C3 · I-3: la nota del primer plan remite a un dato que el modelo sí recibe.** Texto nuevo, literal (lo aprobará el propietario): `PRIMER PLAN EQUIVOCADO: el primer plan que nombres tiene que ser el de commercial_turn_policy.required_plan_id, y nombra solo planes de commercial_turn_policy.allowed_plan_ids. Los demas permitidos, solo despues y si aportan.` Antes de ponerlo, comprueba en `UltronDecideService` que esas dos claves viajan al redactor con ESOS nombres; si se llaman de otra forma, usa el nombre real y dímelo.

**C4 · I-4: el porcentaje frente al 90 % cuenta solo lo que escribió el modelo.**
- (a) Las respuestas `*_recovered` enviadas entran en el reparto, como respaldo (último recurso). La cifra con la regla de la línea base queda en su línea aparte, como ya se hizo con la hora de la visita.
- (b) Las sustituciones del servidor dentro del camino del modelo (el precio realineado con respaldo, el desbordamiento de longitud en el punto de control, la excusa de precio vaciada, la red de C1 y las demás que señala I-4 b) se cuentan como texto del servidor. Si para alguna hace falta una marca nueva en la metadata donde ocurre, añádela (aditiva y solo con versión del Studio).
- Prueba: 9 del modelo y 1 `repeated_reply_recovered` → 90 %, no 100 %; y un caso por cada sustitución de (b).

**C5 · R-4: la traza, solo con la oferta firmada.** `metadata.regeneration` se guarda solo si el token de `decide` de ese turno lleva la oferta del Studio (`sp.m = studio`, que es cuando `decide` ofreció `regeneration`), aunque la versión se haya revertido entre `decide` y `commit`. Si llega el bloque sin esa oferta, no se guarda y se deja un aviso en el log. Un `critic.attempt` de 3 en un turno sin la oferta se guarda tal cual, con un aviso: es una anomalía de n8n que conviene ver.

**C6 · R-8: una nota no tumba la puerta.** Si un parámetro de una nota queda vacío al sanearlo, se usa el marcador (o «ese plan»); y si `note()` lanza, ese defecto va sin nota en vez de responder 500.

**C7 · Menores que entran:** m-3 (los tres comentarios desfasados), m-7 (la lista de excepciones de la paridad en su docblock: son nueve, y con C1 y lo demás las que queden), m-8 (una segunda prueba que defienda el tope de 25 s) y m-10 (el nombre de la prueba que no dice lo que compara).

**C8 · El documento de los textos fijos nuevos.** Una prueba que GENERA y FIJA `docs/ultron-studio/plans/informes-motores/a4-textos-regenerar.md` (el mismo mecanismo que ya genera `a4-textos-usted.md`): las dos instrucciones del parche del motor (`T_PLANS_COMPOSER`, `T_PLANS_CRITIC`), todas las notas de reescritura con un ejemplo de cada una ya rellena (el trato, la longitud, las seis de la lista, la de beneficios y las seis de política) y el formato de la expansión con tres ejemplos del catálogo de prueba («Nombre ($65.000 COP)», uno con duración y «1 día»). Es lo que aprobará el propietario: que se lea de corrido, con una frase que diga cuándo sale cada texto.

Quedan FUERA (pendientes anotados; no los toques): m-1 (el tercer borrador puede arrancar pasados los 25 s), m-2 (la rendición sin traza), m-4, m-5, m-6, m-9; R-2 (un cubo de peticiones propio para la comprobación: condición antes de ampliar), R-3, R-5, R-6 y R-7.

## Invariantes

- Sin versión del Studio no cambia NI UN BYTE de lo que sale: las dos instantáneas (`tests/fixtures/ultron-a4/`) siguen en verde SIN regenerarse.
- La puerta `ai/check` sigue siendo de solo lectura (sus pruebas siguen en verde).
- El flujo de n8n NO se toca en esta ronda: `n8n/flujos/ultron-studio-s1/` y `-s2/` quedan idénticos (si un fixture generado cambia por C3, regenéralo con la orden y dímelo; los `.js` que se ejecutan no pueden cambiar).
- Si C3 cambia una nota, `a4-textos-regenerar.md` la lleva ya corregida.

## Pruebas (primero las nuevas en ROJO; guarda la salida)

Desde `backend/`, UNA ejecución de PHPUnit cada vez: `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging <ficheros>`. Al final: `tests/Unit/Marketing/Ultron`, los A4 (los tres), `UltronCheckEndpointTest`, `UltronCheckCommitParityTest`, `UltronRegenerationCommitTest`, `UltronOriginReportCommandTest`, `UltronDecideTest`, `UltronDecidePromptsTest`, `UltronCommitTest`, `UltronCommitPromptsTest`, `UltronPromptsN8nCommandTest`, `UltronPromptsN8nS2CommandTest`; `tests/Feature/Marketing tests/Unit/Marketing` enteros; las tocadas en PostgreSQL (`UNIT_DB=ultron_studio_lead_ola5 nice -n 10 bash docs/ultron-studio/ops/pgunit.sh --no-logging <ficheros>`); las guardas (una roja esperada: la puerta de diff del H9, hasta subir); `vendor/bin/pint` sobre lo tocado. Ninguna expresión regular nueva lleva `\p{`.

## Límites

No toques: el compilador (salvo que C3 obligue a regenerar un fixture), los prompts base, `config/`, migraciones, rutas, `SalesConversationReplyService.php`, `SalesObjectionResponderService.php`, el guard, `CourtesyRequestService.php`, el CRM ni el n8n en marcha. Nada de git que escriba, nada de producción. No relajes ninguna prueba: cada aserción existente que cambie, al informe con su porqué. Si ves algo adyacente, anótalo y no lo arregles.

## Entregable

Código y pruebas en el árbol, SIN commit. Una sección nueva al final de `informe-backend-a4-regenerar.md` («8. Correcciones tras las revisiones»): la tabla hallazgo → corrección → prueba (C1 a C8), las aserciones cambiadas, los números (rojo antes, verde después, Marketing, PostgreSQL, guardas) y lo que no pudiste comprobar. Salidas de PHPUnit en `a4-evidencia/` con los números 133 en adelante. Respuesta final a mí en pocas líneas, con los números y los ficheros de producto tocados.
