# Informe de backend: arreglos PM-1, PM-2 y la ayuda de `ultron.view`

Fecha: 2026-11-11. Rama `feat/ultron-studio-h9`. Trabajo en local: sin commit, sin push, sin producción y sin red real.
Origen:

- `informe-api-security-ola.md` §3.2, hallazgos PM-1 y PM-2;
- `informe-bs.md` §5, punto 2.

## Resumen para el lead

**Qué cambió:**

1. **PM-1: el token real ya no viaja al simulador.** Fichero: `app/Services/Meta/Templates/GraphWhatsappTemplateClient.php`.
   - Constantes nuevas:
     - `:93`: `SIMULATOR_BEARER = 'simulator'`;
     - `:90`: `SIMULATOR_LOCAL_HOSTS = ['127.0.0.1', 'localhost']`.
   - `request()`, `:318-319`:
     - fuera de producción, el portador es **siempre** `simulator`;
     - `MetaAuthService::accessToken()` solo se usa como portador si `app()->isProduction()`.
   - `:349`: lo que se tacha de las respuestas es el token que viajó. Con el simulador es `null`. Los textos con forma de token (`EAA…`, `Bearer …`, `access_token=…`) se siguen tachando igual.
   - `simulatorBase()`, `:533-534`: la base del simulador solo vale si es `https://`, o `http://` a `127.0.0.1` o `localhost`. Otra cosa da `not_configured` con `no_simulator`, sin red (falla cerrado).
   - **Producción no cambia:** siempre `https://graph.facebook.com` y el token real.
   - Docblock de la clase, reglas 2 y 3 (`:28-45`), al día.
2. **PM-2: la sonda de Meta tiene su propio limitador, `ultron-studio-meta-probe`.**
   - `app/Providers/AppServiceProvider.php:671-679`:
     - los mismos topes que la de Twilio: 6 por hora y persona, 20 por hora y red;
     - su propio cubo: Laravel mete el nombre del limitador en la clave (`ThrottleRequests.php:134`);
     - su propio texto: «Demasiadas comprobaciones de Meta seguidas. Espera antes de lanzar otra.».
   - `UltronStudioMetaTemplateController.php:61` usa ese limitador (docblock en `:47-53`).
   - El de Twilio (`ultron-studio-probe`) queda igual.
3. **Ayuda de `ultron.view`.** Fichero: `app/Support/Access/PermissionCatalog.php:238`. Texto nuevo: «… Verlo no cambia lo que ULTRON dice ni hace. Con la llave de prompts del servidor apagada, el motor solo anota en cada turno la versión publicada; con ella encendida, la versión publicada en canario gobierna el tono, los emojis, la longitud y el objetivo de los mensajes de la conversación canaria.»
   - He regenerado el catálogo del CRM con `permissions:export`. El resultado es **idéntico byte a byte** al que había, porque la exportación no lleva las ayudas, solo las llaves y los roles.
4. **Comentarios de configuración**, para que digan la regla nueva del simulador: `config/ultron_studio.php:385-388` y `.env.example:439-443`. Solo cambian comentarios: no hay variables nuevas.

**Pruebas que adapté o añadí:**

- **Unitarias, `tests/Unit/Meta/Templates/GraphWhatsappTemplateClientTest.php`:**
  - `sinToken()` (`:128`) ahora exige que, fuera de producción, cada petición lleve `Bearer simulator`. Así lo comprueban todas las pruebas que lo usan.
  - En las pruebas de crear y de `permissions`, la centinela que pedía `Bearer <token>` ahora pide `Bearer simulator`.
  - La prueba de producción (`:268`) ahora exige el token real hacia Graph y que nunca se mande `simulator`.
  - Bases rechazadas (`:288`): seis nuevas, de `http://` a hosts que no son locales.
    - una IP privada;
    - un nombre interno;
    - `127.0.0.1.nip.io`;
    - `localhost.evil.example`;
    - `[::1]`;
    - `10.0.0.5`.
  - Bases admitidas (`:301`).
  - **Prueba negativa nueva** (`:307`, `test_pm1_…`):
    - con `APP_ENV=testing`, las 5 operaciones contra 3 bases de simulador (15 peticiones);
    - ninguna lleva el token en `Authorization`, en la URL, en el cuerpo ni en el resultado;
    - incluye un 401 cuyo texto trae el token.
- **De la API, `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateRoutesTest.php`:**
  - La centinela (`:318-330`) ahora exige que ninguna petición registrada lleve, en `Authorization`, en la URL ni en el cuerpo, el token de la conexión del CRM ni el de `META_ACCESS_TOKEN`. Todas llevan `Bearer simulator`.
  - **PM-2, prueba nueva** (`:351`, `test_pm2_…`):
    - 6 sondas de Twilio, y la séptima de Twilio da 429 (cubo agotado);
    - después, 6 sondas de Meta de la misma persona sin 429;
    - la séptima de Meta da 429 `too_many_requests` con `Retry-After`;
    - su texto empieza por «Demasiadas comprobaciones de Meta seguidas» y la respuesta no contiene «Twilio»;
    - otra persona sigue teniendo su propio cubo.
  - **Guarda de rutas** (`:96-98`): el valor de la ruta `POST strategies/templates/meta/probe` pasa de `throttle:ultron-studio-probe` a `throttle:ultron-studio-meta-probe`.
    - Es un reemplazo, no una suma: esa ruta ya no lleva el limitador de Twilio, y lo afirmo con un `assertNotContains` nuevo.
    - Ninguna otra prueba ni guarda fija la lista de limitadores (lo busqué en `tests/`). La de Twilio (`UltronStudioIntegrationsPermissionsTest.php:93`) no cambia.
- **Permisos, `tests/Feature/UltronStudio/UltronStudioPermissionsTest.php:301-303`:** fija el texto nuevo de `ultron.view` y que la frase vieja ya no está. Antes ninguna prueba fijaba esa ayuda.

**Cifras, todas en verde, un proceso cada vez:**

| Ficheros | Resultado |
|---|---|
| `tests/Unit/Meta/Templates` | 64 pruebas, 1294 aserciones |
| `tests/Feature/UltronStudio/MetaTemplates` | 55 pruebas, 825 aserciones |
| `UltronStudioPermissionsTest` | 13 pruebas, 1665 aserciones |
| `UltronStudioIntegrationsPermissionsTest` (limitador de Twilio) | 9 pruebas, 524 aserciones |

- Guardas estáticas que leen los ficheros tocados, todas en verde:

  | Guarda | Resultado |
  |---|---|
  | `VoiceIsolationTest` | 15 pruebas |
  | `TelephonyIsolationTest` | 11 pruebas |
  | `UltronEngineIsolationTest` | 11 pruebas |
  | `OutreachStaticGuardsTest` | 6 pruebas |

- Mutaciones: las pruebas nuevas **fallan sin el arreglo**. Después devolví cada fichero a su estado, comprobado con `cmp`.
  - Forzar el token real en `request()` hace fallar `test_pm1_…` y la de crear.
  - Volver a `throttle:ultron-studio-probe` hace fallar `test_pm2_…`. La primera sonda de Meta da 429 con «…de Twilio…»: es el fallo original. También falla la guarda de rutas.
- `php artisan auth:coverage --unmapped` no saca nada (salida 0; `UNMAPPED=0`).
- `vendor/bin/pint --test`:
  - limpio en `GraphWhatsappTemplateClient.php`, el controlador, `PermissionCatalog.php`, `config/ultron_studio.php` y `UltronStudioPermissionsTest.php`;
  - en los otros tres, lo único que falla ya estaba antes y **no está en mis líneas** (detalle en §4).

**Lo que NO verifiqué:**

- La suite completa y PostgreSQL. Todo ha corrido en SQLite con `CACHE_STORE=array`, que es lo de `phpunit.xml`.
- Un simulador real escuchando, con red de verdad.
- Que la precedencia «conexión del CRM antes que el fichero de entorno» siga yendo bien en producción con la ruta HTTP completa:
  - la centinela de la API ya no puede verla fuera de producción, porque ahora no viaja ningún token;
  - el código de `MetaAuthService` no ha cambiado;
  - en producción, la prueba unitaria fija que viaja lo que devuelve `accessToken()`;
  - no he ejecutado `WhatsappCredentialPrecedenceTest`.
- Que `php artisan auth:coverage` **sin** `--unmapped` funcione: revienta en `countBy()` (§6). No he comprobado si ya pasaba en HEAD.

## 1. Qué cambió y por qué (por fichero)

### `app/Services/Meta/Templates/GraphWhatsappTemplateClient.php`

- **Portador.**
  - Antes, `request()` mandaba siempre `Http::withToken(accessToken())`. Con `APP_ENV` distinto de `production` y una base de simulador, eso sacaba el token real hacia un host que no es de Meta, quizá por `http://`.
  - Ahora, `$aGraph = app()->isProduction()`:
    - en producción, el token de `MetaAuthService` como antes;
    - fuera, `SIMULATOR_BEARER`.
  - Sigue habiendo una sola construcción `Http::`. Lo exige la guarda estática de la propia prueba.
- **Lo que se tacha.**
  - `interpret()` ahora recibe `?string $token`: el que viajó en producción, `null` con el simulador.
  - Si pasara `'simulator'`, la palabra «simulator» de un texto del simulador saldría como `[token]`.
  - El tachado general de `TemplateApiErrors::sanitize()` (`EAA…`, `Bearer …`, `access_token=…`) se aplica igual. Lo prueba el 401 de `test_pm1_…`, que trae el token centinela en `error_user_msg`.
- **Base del simulador.** Además de lo que ya pedía `simulatorBase()`:
  - URL válida, sin usuario, clave, consulta ni fragmento;
  - que no sea de Meta;

  ahora el esquema tiene que ser `https`, o `http` con el host exactamente `127.0.0.1` o `localhost` (sin distinguir mayúsculas).
  - `[::1]`, `127.0.0.1.nip.io` y `localhost.` con punto final se rechazan: el encargo nombra solo esos dos hosts.
  - Con una base rechazada, `configurationProblem()` devuelve `no_simulator`. Es el motivo que ya existía: no hay detalle nuevo en el vocabulario cerrado de `TemplateApiResult`, ni cambia nada en el CRM.

### `app/Providers/AppServiceProvider.php` (solo `limitadoresDelStudio()`)

- Limitador nuevo, `ultron-studio-meta-probe`, con los mismos `$persona` y `ClientNetwork` que el de Twilio, y su propio texto.
- Lo que no toqué:
  - `ultron-studio-probe` (Twilio);
  - `ultron-studio-meta` (enviar y comprobar), que ya estaba sin commit de otro paquete.

### `app/Http/Controllers/Crm/UltronStudio/UltronStudioMetaTemplateController.php`

- La sonda pasa a `throttle:ultron-studio-meta-probe`. También lleva `throttle:ultron-studio-write`, sin cambios.
- Docblock de `routes()` al día.

### `app/Support/Access/PermissionCatalog.php`

- Ayuda de `ultron.view`, en el texto del resumen. La frase vieja, «ver o cambiar cosas aquí no cambia lo que dice ni hace», era falsa con la llave encendida, porque publicar sí cambia lo que dice. La nueva distingue los dos casos, como ya hacen `ultron.publish` y `ultron.rollback`.

### `config/ultron_studio.php` y `.env.example`

- Solo comentarios: la regla `https` o `127.0.0.1`/`localhost`, y el portador fijo.

### Las tres pruebas

- Cambios descritos en el resumen.

## 2. Contratos nuevos o modificados

- **HTTP, `POST strategies/templates/meta/probe` (ruta 122).** Sin cambio de forma.
  - Su 429 sigue siendo `{ok: false, code: 'too_many_requests', message, retry_after}` con `Retry-After`.
  - Cambia el `message`: «Demasiadas comprobaciones de Meta seguidas. Espera antes de lanzar otra.».
  - Su cupo ya no lo gasta la sonda de Twilio, ni al revés.
  - El CRM no fija ese texto: lo busqué en `Iron-Body_Front/src` y `e2e`. Solo el e2e de H6 nombra `ultron-studio-probe`, y es el de Twilio.
- **`ULTRON_STUDIO_META_TEMPLATES_GRAPH_BASE` es más estricta** (solo fuera de producción). Una base `http://` a un host que no sea `127.0.0.1` ni `localhost` ahora da `no_simulator` en `status.configurationProblem` y en la sonda (409 `meta_not_configured`).
  - Hoy no hay simulador de plantillas en el repositorio. La auditoría lo buscó y yo no vi ninguna base configurada en el código ni en las pruebas, salvo `http://127.0.0.1:8097`, que sigue valiendo.
  - Si algún entorno no productivo usa otro host por `http://`, tiene que pasar a `https://` o a `127.0.0.1`/`localhost`.
- **Lo que recibe un simulador:** `Authorization: Bearer simulator`, siempre. Si algún día se escribe uno, no debe esperar ni validar un token de Meta.
- **`configurationProblem()` se mantiene igual en los dos entornos.** Fuera de producción se sigue exigiendo una credencial presente y con forma (`missing_token` o `malformed_token`), aunque ya no viaje. Es a propósito:
  - el simulador ensaya la misma puerta que producción;
  - `status` no cambia de significado entre entornos;
  - la credencial se lee en local y nunca sale.

## 3. Migraciones, entorno y despliegue

- Sin migraciones.
- Sin variables nuevas. Cambia el significado de `ULTRON_STUDIO_META_TEMPLATES_GRAPH_BASE`, como se explica en §2.
- **Producción:** el cliente se comporta igual; el limitador nuevo nace vacío.
- **Al desplegar**, los contadores que la sonda de Meta llevaba en el cubo compartido no pasan al nuevo: durante una hora, quien ya hubiera gastado ese cubo tiene hasta 6 sondas de Meta más. No tiene impacto.
- **Para volver atrás**, basta con revertir los cuatro ficheros de `app/`.

## 4. Pint: lo que ya fallaba antes

Ninguno de estos fallos está en una línea mía:

| Fichero | Qué falla | ¿Ya estaba? |
|---|---|---|
| `app/Providers/AppServiceProvider.php` | importaciones y FQCN en `:488` y `:493` | Sí: Pint sobre `git show HEAD:…` da **el mismo diff** |
| `tests/Unit/Meta/Templates/GraphWhatsappTemplateClientTest.php` | `phpdoc_align` en el docblock del proveedor `erroresDeCrear` (`:187-188`) | Sí: no lo toqué |
| `tests/Feature/UltronStudio/MetaTemplates/MetaTemplateRoutesTest.php` | FQCN `\App\Services\UltronStudio\StudioMetaTemplates` en la línea del `sync` de la centinela | Sí: no lo toqué |

- En la prueba unitaria, el JSON de Pint nombra además otras reglas (`class_definition`, `unary_operator_spaces`, `not_operator_with_successor_space`…). Al aplicar Pint a una copia en el scratchpad, el único cambio real es esa línea del docblock: las demás reglas se anulan entre sí, y la copia corregida da `passed`.
- No lo arreglé, como dice el plan del H6: «no se toca el Pint que ya falla en HEAD».

## 5. Para `tester` y para `api-security-specialist`

- **Casos límite desde dentro:**
  - **El entorno se decide en cada llamada.** Un cliente construido fuera de producción que luego llama con `env = production` va a Graph con el token real (lo cubre `test_en_produccion_…`). Al revés, manda `simulator`.
  - **Bases** que conviene probar si se quiere más dureza:
    - `http://LOCALHOST` vale;
    - `http://localhost.` con punto final no vale;
    - `https://` a una IP pública vale. El encargo admite cualquier `https://` que no sea de Meta: el TLS protege el tránsito, pero el token tampoco viaja.
  - **Carrera de cupos:** `ultron-studio-meta-probe` y `ultron-studio-probe` usan la misma clave por persona (`ultron-studio:admin:{id}`), pero cubos distintos, porque el nombre del limitador entra en el hash. Con `CACHE_STORE=array` los limitadores solo funcionan dentro de un proceso (trampa 10 del arnés E2E).
- **Para `api-security-specialist`:**
  - doy por cerrados PM-1 (las dos variantes del arreglo mínimo, juntas) y PM-2;
  - las pruebas negativas que pedía la auditoría (§ tabla, `:478-479`) están escritas y en verde.

## 6. Fuera de mi alcance (no lo he arreglado)

1. **`php artisan auth:coverage` sin `--unmapped`:**
   - imprime `UNMAPPED=0` y `CONFLICTS=3`, en `receivables/…/settle` y en dos de `electronic-invoices/bundles`;
   - después revienta en `AuthorizationCoverageCommand.php:93` (`collect($mapeadas)->countBy()`);
   - no tiene que ver con estos cambios: no he tocado rutas ni `AuthorizationMap`;
   - no he comprobado si ya pasaba en HEAD;
   - con `--unmapped`, que es lo que se pedía, sale limpio.
2. **Documentos que siguen diciendo que la sonda de Meta usa `ultron-studio-probe`.** No los he tocado, porque son de otros paquetes:
   - `docs/ultron-studio/plans/PLANTILLAS_META_PLAN.md:691`;
   - `docs/ultron-studio/plans/informes-plantillas/dtos-para-frontend.md:20`;
   - `docs/ultron-studio/plans/informes-plantillas/informe-backend.md:131` y `:231` (este último, el pendiente que este cambio cierra).
3. **La ayuda nueva de `ultron.view` no llega por el catálogo generado del CRM**, porque `permissions:export` no exporta ayudas. No he comprobado de dónde saca el CRM ese texto para pintarlo (si es de la API en tiempo de ejecución, ya está).
