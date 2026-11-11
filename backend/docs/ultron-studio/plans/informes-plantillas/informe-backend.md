# Plantillas de WhatsApp de Meta desde el CRM · informe de `backend` (pasos 1b, 1c, 2 y 3)

> **Autor:** `backend` (be-tpl), 2026-11-11. Reanuda el trabajo de los dos agentes que murieron al cerrarse la terminal (1b/2/3 y 1c), que estaba en disco sin commit; lo existente se leyó, se probó y se continuó, sin rehacerlo.
> **Base:** rama `feat/ultron-studio-h9`, HEAD `da80942b` (producción). **Sin commit ni push.** Todo en local: ni red real ni producción.
> **Plan:** `plans/PLANTILLAS_META_PLAN.md` (§5 y §6); decisión del lead en L9-96 (Q1 URL estática solo hacia `ironbodyneiva.cloud`; Q2 «Llamar» con el número del gimnasio; Q3 sin cuatro ojos adicionales; Q4 Graph `v25.0`; Q6 fase 2).
> **Contrato para el CRM:** `informes-plantillas/dtos-para-frontend.md`, con JSON reales. **Petición a db-esquema:** `informes-plantillas/peticion-backend-a-db-esquema.md`.

---

## 1. Resumen

- **1b (lo puro): estaba completo y en verde.** Mantengo una enmienda: la huella de componentes calculada sobre Meta (`metaComponentsFingerprint`) no casa nunca si Meta trae claves que el código no sabe comparar.
- **1c (el cliente neutro): estaba escrito y sin pruebas.** Añado:
  - las dos piezas que su interfaz ya nombraba y no existían: `WhatsappTemplateClientFactory` y `WhatsappTemplateProbe`;
  - 63 pruebas unitarias.
- **Paso 2 (W9):** la huella de componentes en `OutreachPublishedStrategies::metaApproved()`. Es la única línea tocada en `app/Services/Outreach/**`. Va además en `StudioTemplateMetaChecks::compare()` (`components_mismatch`), y la orden manual se niega en los dos casos nuevos.
- **Paso 3, piezas nuevas:**
  - servicio `StudioMetaTemplates`, controlador con 4 rutas (de 121 a 125), modelo del envío y orden `ultron-studio:meta-templates-sync`;
  - llave `ultron.meta_templates_submit` (solo Super Admin) y limitador `ultron-studio-meta`;
  - bloque propio del programador, DTOs aditivos y catálogo del CRM regenerado.
- **Pruebas:** 117 pruebas propias en verde en **SQLite y PG 14**; 154 y 67 de los vecinos afectados, también en verde. 3 mutaciones cazadas.
- **Una guarda cae, y es la que el plan nombra:** el programador en `UltronEngineIsolationTest`. Ese fichero es de db-esquema, así que su enmienda está pedida por escrito (§6).

## 2. Qué cambió y por qué, por fichero

### Nuevos

| Fichero | Qué |
|---|---|
| `app/Services/Meta/Templates/WhatsappTemplateClientFactory.php` | Elige el doble o Graph. **En producción siempre Graph**, aunque la configuración diga `fake`. El doble solo en `local` y `testing`; en cualquier otro entorno, Graph contra el simulador, y sin él `not_configured`. Neutra: recibe la configuración, no la lee |
| `app/Services/Meta/Templates/WhatsappTemplateProbe.php` | La sonda de §5.9: `GET me/permissions` y `GET {waba}/message_templates?limit=1&fields=id`, con un veredicto de una lista cerrada |
| `app/Services/UltronStudio/StudioMetaTemplates.php` | Configuración, estado, sonda, enviar, «Comprobar ahora», sincronización y los bloques `meta` de los DTO (§3) |
| `app/Models/UltronStudio/StudioMetaTemplateSubmission.php` | `$guarded = ['*']`. Solo cambian las columnas mutables de la migración; el estado solo avanza, FAILED es definitivo, el id de Meta se fija una vez y no se borra |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioMetaTemplateController.php` | Las 4 rutas, con `ROUTES`, `ROUTE_COUNT = 4`, `routes()` (su bloque) y `authorizationMap()`, con el patrón de `UltronStudioOutreachController` |
| `app/Console/Commands/UltronStudio/MetaTemplatesSyncCommand.php` | `ultron-studio:meta-templates-sync [--dry-run] [--json] [--template=<uuid>]`. Sale con 0, salvo un `--template` que no es un uuid (sale con 2) y, desde el cierre del M-3 del reviewer (`informe-be-fixes-2.md`), una pasada en la que Meta falla entera (`meta_failed`: sale con 1; `partial` sale con 0 y dice cuántas quedaron sin comprobar, `unresolved` en `--json`) |
| `tests/Unit/Meta/Templates/GraphWhatsappTemplateClientTest.php` | 43 pruebas (§7) |
| `tests/Unit/Meta/Templates/FakeWhatsappTemplateClientTest.php` | 20 pruebas: R7, escenarios, fábrica y sonda |
| `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateW9AmendmentTest.php` | 7 pruebas del paso 2 |
| `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateFixtures.php` | Ayudas del paso 3 (sobre `StrategyFixtures`) |
| `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateSubmitTest.php` | 9 pruebas: enviar |
| `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateSyncTest.php` | 13 pruebas: lo que dice Meta |
| `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateRoutesTest.php` | 10 pruebas: la API, la centinela, la red y el diff |

### Modificados (todos aditivos)

| Fichero | Qué y por qué |
|---|---|
| `app/Services/Meta/Templates/FakeWhatsappTemplateClient.php` | Quito un método privado muerto (`pageFull`) |
| `app/Services/UltronStudio/StudioMessageTemplates.php` | (1b, ya en disco) más tres cambios: `metaComponentsFingerprint()` tiene en cuenta las `extraKeys` (`:501`, `:510`); `page()` añade `meta` a cada fila, en 3 consultas por página (`:809`); `detailDto()` añade `meta` (`:859`) |
| `app/Services/UltronStudio/StudioTemplateMetaChecks.php` | `compare()` mira los componentes (`:88`, `:273`). Los dos rechazos de la orden manual (`:168`, `:171`). `latest()` devuelve `source` (`:331`, aditivo) |
| `app/Console/Commands/UltronStudio/TemplateMetaCheckCommand.php` | Cabecera y «· componentes casa/NO casa» al final de la línea de comparación (`:167`) |
| `app/Services/Outreach/Run/OutreachPublishedStrategies.php` | **Enmienda de W9**: `meta_components_hash` en la lectura (`:367`), la condición (`:384`) y `componentsMatch()` (`:395`), que nunca lanza. Sus referencias al Studio son las mismas (la guarda del puente sigue en verde) |
| `app/Services/UltronStudio/StudioAccess.php` | `META_TEMPLATES_SUBMIT` (`:73`) |
| `app/Http/Controllers/Crm/UltronStudio/StudioRouteCount.php` | `+ UltronStudioMetaTemplateController::ROUTE_COUNT` (`:17`): 121 → 125 |
| `routes/ultron-studio.php` | `UltronStudioMetaTemplateController::routes();` dentro del grupo del Studio (`:279`) |
| `app/Support/Access/AuthorizationMap.php` | Las 4 entradas (`:859-862`) |
| `app/Support/Access/PermissionCatalog.php` | Etiqueta (`:177`), ayuda (`:262`) y su lugar en el orden: `'meta_templates_submit' => 8.69`, detrás de las del H9, sin mover ninguna (`:373`). No cambio ningún texto existente |
| `app/Support/Access/CrmPermission.php` | La llave, fuera del Administrador (`:336`) |
| `app/Providers/AppServiceProvider.php` | Limitador `ultron-studio-meta`: 20/h por persona y 60/h por red (`:676`). No nombra el Studio |
| `routes/console.php` | Bloque propio al final (`:566`) |
| `config/ultron_studio.php`, `.env.example` | (1b) Solo el comentario del driver: en producción, siempre `graph` |
| `tests/Feature/UltronStudio/UltronStudioPermissionsTest.php` | **Enmienda mínima de guarda** (§5) |
| `../Iron-Body_Front/src/app/models/backend-permission-catalog.ts` | Regenerado con `php artisan permissions:export`: +2 líneas, la llave en el catálogo y en los valores por defecto de Super Admin |

**Diff vacío, comprobado con una prueba** (`MetaTemplateRoutesTest::test_la_puerta_de_diff_del_whatsapp_de_hoy_esta_vacia`, frente a HEAD):

- el webhook, el job y la ingesta;
- `MetaMessagingService`, `MetaAuthService`, `WhatsappIntegrationRegistry`;
- el outbox y `MarketingMessageDispatcher`;
- `OutreachTemplateMessage` y `WhatsappTemplateChannel`;
- `routes/api.php`, `routes/marketing.php` y `config/meta.php`.

`app/Services/Marketing/Ultron/**` queda fuera de esa prueba porque lo está cambiando `bm-motor` en esta ola. **Yo no lo toco.**

## 3. La interfaz del cliente (1c)

```php
interface WhatsappTemplateClient {
    const FIELDS, SYNC_FIELDS, MAX_PAGE_SIZE = 100;
    public function configurationProblem(): ?string;                 // missing_token | malformed_token | no_simulator | null (sin red)
    public function create(string $wabaId, array $payload): TemplateApiResult;            // UN POST, nunca se repite
    public function find(string $templateId, array $fields): TemplateApiResult;           // GET nodo
    public function findByName(string $wabaId, string $name, ?string $language, array $fields): TemplateApiResult; // ≤ 5 páginas
    public function list(string $wabaId, array $fields, ?string $after = null, int $limit = 100): TemplateApiResult;
    public function permissions(): TemplateApiResult;                // GET me/permissions
}
```

**Resultado.** Tiene tres desenlaces: `ok`, `failed` y `unknown`. `unknown` solo existe en `create`.

**Errores.** Llegan normalizados en las clases cerradas de `TemplateApiErrors`: `auth`, `permission`, `rate_limited`, `duplicate_name`, `validation`, `not_found`, `rejected`, `server`, `transport`, `invalid_response` y `not_configured`.

**Lo que se conserva de un error de Meta:** el código, el subcódigo, el título y el mensaje para el usuario (saneados) y el `fbtrace_id`. **Nunca** el cuerpo ni `message`.

**Reglas de Graph:**
- base fija `https://graph.facebook.com` en producción;
- fuera de producción, solo el simulador, y se niega a cualquier host de Meta;
- versión `v25.0` propia;
- `connectTimeout(5)`, `timeout(15)`, sin redirecciones y sin `retry()`;
- el token, leído en el momento de llamar y solo en `Authorization`;
- un registro por operación con códigos y tiempos.

**La fábrica:** `WhatsappTemplateClientFactory::make($driver, $graphVersion, $simulatorBase)`.

**La sonda:** `WhatsappTemplateProbe::run($client, $waba)` devuelve `{outcome, scopes, templatesReadable, templatesError, requests, durationMs}`.

## 4. Decisiones (las que no dicta el plan, con su porqué)

1. **En producción, el driver es SIEMPRE `graph`**, aunque `ULTRON_STUDIO_META_TEMPLATES_DRIVER` diga `fake`, que es su valor por defecto.
   - El plan dice «en production solo graph (el doble no se construye)».
   - Si se negara en lugar de usar Graph, la configuración por defecto rompería `status` en producción.
   - Con las banderas apagadas no sale nada: lo prueba la prueba de cero red.
2. **Con el doble (solo `local` y `testing`), una cuenta ficticia `100000000000001` si no hay una configurada.** Así el banco y el E2E no necesitan una WABA. Con `graph`, sin cuenta es 409 `meta_not_configured`.
3. **Qué es una fila «positiva»:** Meta dice `APPROVED` y casan nombre, idioma, categoría, cuerpo y componentes.
   - Exige que alguien pueda dar fe ahora. Lo mira `canAttest`: activa, con la llave y `ultron.view`, y con su persona verificada.
   - Todo lo demás es negativo y se escribe siempre. En la sincronización va a nombre de quien dio fe en la fila anterior o, si no la hay, de quien envió. En «Comprobar ahora» va a nombre de quien la pide.
4. **Cuándo se escribe una fila nueva.** Solo si cambia lo que compara el canal: estado, nombre, idioma, categoría, huella del cuerpo, huella de componentes e id.
   - Una fila manual sin huella de componentes vale lo que una plantilla sin componentes, que es la regla del puente. Así una plantilla registrada a mano y que sigue igual en Meta no genera filas.
   - El id solo cuenta si las dos filas lo tienen.
   - El `client_request_id` es un UUIDv5 de la versión, lo observado y la fila anterior: dos pasadas a la vez chocan en el UNIQUE.
5. **`NOT_FOUND`.** Se escribe con huellas que nunca casan y conserva el último id conocido. Una pasada siguiente igual no escribe nada. Antes de concluirlo se hace una búsqueda suelta (`find` por id o `findByName`), no basta con no salir en la lista.
6. **Un `SENDING` de más de 120 s pasa a `UNKNOWN`** (`stale_sending`). Un proceso que muere a mitad no deja la versión bloqueada para siempre, y después la reconciliación la busca por nombre. El plan no fijaba qué pasa con un SENDING huérfano.
7. **V-2.** Si pedir `rejected_reason` da 100, se repite la lectura sin ese campo, una sola vez y solo en lecturas, y queda en el registro (`ultron_studio.meta_templates.field_dropped`). No hay que tocar el código si Meta no lo conoce.
8. **Lo que no se sabe comparar no casa.** Meta puede traer claves que `MetaTemplate` anota en `extraKeys` (por ejemplo, un `example` en un botón de URL dinámica). Con ellas, `metaComponentsFingerprint` no casa nunca: falla cerrado.
9. **Códigos nuevos que el plan no tenía:**
   - `meta_not_tracked`: «Comprobar ahora» de una versión que nadie envió ni registró;
   - `meta_check_in_progress`: se está enviando o comprobando ahora;
   - `meta_payload_invalid` (422): el cliente rechazaría la versión guardada antes de la red; no sale nada.
   - Evento nuevo `meta_template.check_requested`: «Comprobar ahora» es un acto de una persona.
10. **La sonda no lleva cuerpo** y su idempotencia es el enfriamiento de 300 s (`reused`).
    - Usa el limitador `ultron-studio-probe`, como dice el plan, que **comparte cubo con la sonda de Twilio**.
    - El mensaje de ese 429 habla de Twilio. No cambio un texto existente; lo dejo anotado (§9).
11. **«Comprobar ahora» devuelve 200 aunque Meta falle** (`check.outcome = failed`, `check.error`): es el criterio de §5.4. El historial (`timeline`) va del más reciente al más antiguo.
12. **La autora puede enviar** (Q3: sin cuatro ojos adicionales).
    - El `four_eyes_required` defensivo no se puede alcanzar con datos: la base (DB9, `usmt_review_once` y la guarda de cuatro ojos) ya lo impide.
    - La prueba fija esa primera línea, sin `skip`.

## 5. Enmiendas de guardas (fichero:línea)

- **`tests/Feature/UltronStudio/UltronStudioPermissionsTest.php:293-296`.** La cuenta de llaves del dominio `ultron` pasa de 19 a 20 y se fija que la nueva va la última.
  - Motivo: es la llave nueva que el plan nombra (§5.10, D9-13 crece).
  - No cambia ninguna otra aserción.
- **Pedidas por escrito y NO aplicadas** (son de db-esquema o del tester; detalle en `peticion-backend-a-db-esquema.md`):
  - `UltronEngineIsolationTest::SCHEDULER_REFERENCES`, con su propia aserción (§5.12). **Hasta que se aplique, `test_fuera_del_studio_nadie_nombra…` cae por `routes/console.php:566`.**
  - `UltronEngineIsolationTest::NEUTRAL_ZONES` + `app/Services/Meta/Templates`.
  - `OutreachStaticGuardsTest`: los ficheros nuevos del Studio en `H9` y el modelo en `MODELS`. He comprobado a mano que pasarían.
- **W9 y B9 sin cambiar ni una aserción.** En verde: `TemplateMetaCheckCommandTest`, `WhatsappTemplateChannelTest` y `WhatsappW9IsolationTest`.

## 6. Variables de entorno, configuración y migraciones

| Variable | Defecto | Notas |
|---|---|---|
| `ULTRON_STUDIO_META_TEMPLATES_ENABLED` | `false` | La maestra |
| `ULTRON_STUDIO_META_TEMPLATES_SUBMIT_ENABLED` | `false` | Lo único que escribe en Meta; además exige `META_ENABLED=true` |
| `ULTRON_STUDIO_META_TEMPLATES_SYNC_ENABLED` | `false` | Programa la orden cada 5 min |
| `ULTRON_STUDIO_META_TEMPLATES_DRIVER` | `fake` | En producción se ignora: siempre `graph` |
| `ULTRON_STUDIO_META_TEMPLATES_GRAPH_VERSION` | `v25.0` | Mal escrita, `v25.0` |
| `ULTRON_STUDIO_META_TEMPLATES_GRAPH_BASE` | vacía | Solo fuera de producción (el simulador) |
| `ULTRON_STUDIO_META_TEMPLATES_URL_HOSTS` | vacía | Q1: el valor decidido es `ironbodyneiva.cloud` |
| `ULTRON_STUDIO_META_TEMPLATES_PHONE_BUTTON` | `false` | Q2: usa `legal.privacy_phone` |

Todas van en `.env.example` con su comentario.

**Secretos nuevos: ninguno.** La credencial es la de siempre: la conexión del CRM o `META_ACCESS_TOKEN`, la WABA de `MetaAuthService`.

**Migraciones:** las dos de db-esquema, `2026_11_15_100001` y `2026_11_15_100002`, en ese orden y **antes** del código. El puente nuevo lee `meta_components_hash`; sin la columna, el canal falla cerrado y lo deja en el registro.

**Programador:** con la bandera encendida, +1 tarea; verificado con `schedule:list`: 26 → 27, `*/5`.

## 7. Qué ejecuté, con su resultado

Todo con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`, un proceso cada vez. Para PG: `PGGSSENCMODE=disable UNIT_DB=ultron_studio_tpl_be bash docs/ultron-studio/ops/pgunit.sh --no-logging …`, contra PG 14 desechable en `127.0.0.1:55432`, base `ultron_studio_tpl_be`.

| Qué | SQLite | PG 14 |
|---|---|---|
| Mis pruebas: `tests/Feature/UltronStudio/MetaTemplates` + `tests/Unit/Meta/Templates` (contenido 15, cliente 43, doble/fábrica/sonda 20, W9 7, enviar 9, sincronizar 13, API 10) | **117/117, 1 503 aserciones** | **117/117, 1 503 aserciones** |
| Vecinos afectados: los de arriba + `UltronStudioStrategyLifecycleTest`, `UltronStudioStrategyValidationTest`, `TemplateMetaCheckCommandTest`, `WhatsappTemplateChannelTest` y `WhatsappW9IsolationTest` | 154/154 | — |
| Rutas y permisos: `UltronStudioStrategyRoutesTest`, `UltronStudioPermissionsTest`, `UltronStudioIntegrationsPermissionsTest`, `UltronStudioVoiceRoutesTest`, `UltronStudioEarlyBodyCapTest` y `OutreachRoutesMapGuardTest` | 39/39 | — |
| W9, B9, plantillas, rutas y permisos (9 ficheros) | — | 67/67 |
| `OutreachStaticGuardsTest` | 6/6 | — |
| `UltronEngineIsolationTest` (solo ejecutado, no editado) | 7/9: cae el programador (§5, pedido) y una lectura del motor desde `app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php`, **de bm-motor, no mía** | — |
| `php artisan auth:coverage --unmapped` | vacío (sale con 0) | — |
| `php artisan route:list --path=ultron-studio/strategies/templates` | las 4 rutas nuevas | — |
| `schedule:list` (caché `array`), bandera apagada y encendida | 26 / 27 tareas | — |
| `php artisan permissions:export --path=…/backend-permission-catalog.ts` | «122 permisos exportados», +2 líneas | — |

**Mutaciones cazadas** (restauradas desde una copia en el scratchpad; comprobado con grep):

1. quitar `componentsMatch` del puente → 3 fallos en `MetaTemplateW9AmendmentTest`;
2. `FakeWhatsappTemplateClient::allowedHere()` siempre `true` → 3 fallos en las pruebas R7;
3. escribir el APPROVED sin nadie que dé fe → falla `…no_se_escribe_el_approved_hasta_que_otra_persona_comprueba`.

**Lo que cubren las pruebas del plan:**

- **El camino feliz:** enviar → PENDING → APPROVED → el canal acepta por el puente → PAUSED → no acepta → APPROVED → acepta.
- **Errores:** cada 409 y 422.
- **Idempotencia:** la misma petición repetida y la reutilizada.
- **Concurrencia:** un solo POST, con el cerrojo y con el índice único.
- **Envíos sin respuesta:** timeout → UNKNOWN; `caer_` se encuentra; `perder_` pasa a FAILED a los 15 min.
- **Cambios en Meta:**
  - la edición en el administrador → `components_mismatch`;
  - la recategorización de cobranza → `category_mismatch`;
  - borrada → `NOT_FOUND` una vez;
  - rechazada, y en revisión durante varias pasadas.
- **Quién da fe:** si quien envió ya no puede, no se escribe el APPROVED, y «Comprobar ahora» de Beto lo escribe a su nombre.
- **Sin cambios:** las pasadas iguales no escriben filas ni eventos.
- **Registradas a mano:** se vigilan; la pausada escribe, la igual no.
- **La orden:** en seco no escribe; apagada, sin configurar o sin nada que vigilar sale con 0 y sin red.
- **Robustez:** SENDING huérfano; Meta falla → nada inventado; V-2.
- **La centinela:** el token de la conexión del CRM y el del `.env`, en respuestas, tablas, libro, registros, URLs y cuerpos. El token viaja solo en `Authorization`.
- **Cero red** con los valores por defecto, aunque el driver sea `graph`.
- **Diff vacío** del WhatsApp de hoy.
- **El cupo** de 20/h.
- **Del cliente:** el cuerpo exacto, las cabeceras, `v25.0`, la paginación y su tope, cada error normalizado, «desconocido», un solo POST, los ids que no son dígitos, el doble imposible en producción (R7).

## 8. Lo que NO verifiqué

- **Nada contra Meta de verdad** (prohibido en esta tarea). Quedan sin confirmar:
  - **V-1:** que Meta devuelva los textos, las URL y el teléfono sin retocarlos. Si los retoca, la plantilla no casa y no se usa (falla cerrado). Se confirma con el primer envío real.
  - **V-2:** si `v25.0` conoce `rejected_reason`. Hay respaldo automático.
  - **V-3:** si `/me/permissions` contesta con el token de producción. Si no, manda la lectura.
- **La orden contra la base local del `.env`** (PostgreSQL en 5432 apagado): la orden la ejecutan las pruebas con `Artisan::call`.
- **La regresión completa**, por la restricción de memoria: solo mis ficheros y los afectados. La completa la corre el lead.
- **Seguridad:** `security` y `api-security-specialist` no han revisado. Hay que señalarles la autorización por ruta, la persona, el token, los errores y la red (§5.14).
- **El CRM:** solo regenero el catálogo de permisos; no compilo ni pruebo el frontend.
- **Concurrencia real entre procesos** (dos PHP a la vez): está probada con el cerrojo tomado a mano y con la fila de otro proceso ya confirmada, no con dos procesos de verdad.

## 9. Pendientes y riesgos para el lead

1. **Aplicar las enmiendas de guardas pedidas** (§5). Hasta entonces `UltronEngineIsolationTest` cae por el programador. Además cae por un fichero de bm-motor, que no tiene relación con esto.
2. **El 429 de la sonda de Meta dice «Demasiadas comprobaciones de Twilio…»**, porque comparte el limitador `ultron-studio-probe`, tal como dice el plan. Hay dos arreglos posibles, a decidir:
   - un texto neutro;
   - un limitador propio.
3. **El plan pide en la lista un filtro por estado en Meta**, y no hay filtro de servidor. Hoy se filtra en el cliente sobre la página.
4. **`StudioMetaTemplates.php` es grande** (1 456 líneas con la documentación). Si el revisor lo pide, la sincronización y su lógica de observación se pueden separar en otra clase, sin cambiar el contrato.
5. **Aviso a `crm-fe`.** No tengo herramienta de mensajes en esta sesión: el contrato está en `dtos-para-frontend.md`. **Hay que avisarle.**
