# Revisión de seguridad de API · PT-19 (la puerta del secreto interno antes que el limitador)

**22-11-2026 · api-security-specialist (revisión independiente, una pasada) · commit `5cd201eb` (rama `cierre/puertas-internas`), base `f1fe6cac` · árbol `_worktrees/cierre-pagos` · sin tocar producción.**
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código (ruta de ejecución completa).

## Veredicto
**APTO CON CONDICIONES · 0 bloqueantes · 5 no bloqueantes.** El defecto queda cerrado en las 21 rutas, no se ha encontrado otra forma de gastar los cubos de n8n sin el secreto y el cambio no toca ninguna otra ruta. Las condiciones son solo de despliegue (punto 5): el guion versionado aborta con este diff, y hay que comprobar después de desplegar que el código nuevo está sirviendo.

## Alcance
- Revisado: el diff de producto (`backend/bootstrap/app.php`, +16), la puerta `VerifyInternalAutomationSignature`, todos los limitadores con nombre y los usos manuales de `RateLimiter`, las 1 046 rutas en su orden efectivo, `routes/voice.php` y `VoiceRateLimits`, los webhooks públicos (Meta, Wompi, Twilio), el kiosko (`device.signed`), las pruebas nuevas y la parte del guion de despliegue que afecta a este fichero.
- Fuera: PT-15 y PT-14 (su cubo propio de `ai/check` no está en este árbol, donde `ai/check` usa `internal-marketing-ai`), la regla de nginx, la batería completa (la corre el lead) y producción.

## Modelo de amenazas (resumido)
- **Activo:** que el asesor de WhatsApp (`internal/marketing/*`) y las notificaciones a socios (`internal/automation/*`) estén disponibles, más el propio secreto interno.
- **Actores:** el anónimo de internet (las rutas son públicas: evidencia [3] del lead); quien prueba secretos al azar; quien tiene el secreto (n8n, o un secreto filtrado).
- **Frontera:** internet ↔ API (`/api/internal/*`, sin restricción de origen en nginx); API ↔ caché del limitador (base de datos en producción).

---

## 1. ¿Queda cerrado el defecto? Sí [C]

**El mecanismo [L].** `prependToPriorityList(before: ThrottleRequests, prepend: Verify…)` (`bootstrap/app.php:80-83`) inserta la puerta en la lista de prioridad justo delante de `ThrottleRequests` (`ApplicationBuilder.php:299-303` → `Kernel.php:485-510`). `SortedMiddleware::sortMiddleware` (`SortedMiddleware.php:33-64`) repite el movimiento hasta que la subsecuencia con prioridad queda ordenada, así que **el orden declarado da igual**. Las clases se comparan por nombre, por interfaces y por padres, así que también cubre `ThrottleRequestsWithRedis` y cualquier subclase. El orden se calcula en cada petición (`Router.php:816`).

**Sonda de orden efectivo** (script propio, solo lectura; prioridad real del núcleo, comparada con la misma lista sin la puerta) [C]:
```
PRIORIDAD: … AuthenticatesRequests > VerifyInternalAutomationSignature > ThrottleRequests > ThrottleRequestsWithRedis > … > SubstituteBindings > Authorize
TOTAL rutas: 1046
CAMBIAN de orden: 21        de ellas fuera de api/internal/: 0
Rutas con la puerta interna: 21   (todas: Verify > ThrottleRequests:… > SubstituteBindings > ProtectAdminPaths > EnforceAdminAuthorization > EnforceTrainerMemberScope)
Limitador ANTES de la puerta (después del cambio): 0
api/internal SIN la puerta: 0
Puerta fuera de api/internal: 0
Cubos de n8n en rutas SIN la puerta: 0
```
Coincide con lo que midió el lead (1 046 rutas, 21 cambios, todos bajo `api/internal/`).

**Otras formas de gastar el cubo sin el secreto, descartadas una a una:**
- **Otras rutas con los mismos limitadores con nombre:** no hay ninguna. `throttle:internal-automation` solo está en `routes/api.php:1934` y `throttle:internal-marketing-ai` solo en `routes/marketing.php:44`, y los dos grupos llevan la puerta [L][C].
- **Que otra clave choque con la del cubo:** la clave es `md5(nombre.clave)` (`internal-automationinternal-automation`, `AppServiceProvider.php:893-899`). Todos los demás limitadores con nombre empiezan por otro nombre. Los 8 usos manuales de `RateLimiter::hit/tooManyAttempts` llevan prefijo fijo (`iron-ai-coach:`, `nutrition-ai:`, `meta-dashboard-sync:`, `workforce-*`, `integral-plan:`, `voice-rl:`…), así que ninguno da con un md5 hexadecimal. No hay `shouldHashKeys(false)` [L].
- **Un `throttle` en el grupo `api` o en la pila global:** no lo hay. El grupo `api` es `SubstituteBindings` más los tres añadidos (`bootstrap/app.php:91-106`), y no hay `throttleApi()` ni `throttleWithRedis()` [L][C: el orden efectivo de las 21 no tiene otro limitador].
- **`ThrottleRequestsWithRedis`:** no se usa (la caché es la base de datos), y aunque se activara va detrás de la puerta en la prioridad (índice 8 frente a 6) [C].
- **Las 5 rutas de `routes/api.php:1948` (`internal/marketing/ai-action`, `send-message`, `human-takeover`, `followups`, `context/{lead}`)** usan el limitador anónimo `throttle:120,1`, con clave `sha1(dominio|IP)`. Ahora también van detrás de la puerta [C]. Ese contador no era global, sino de la IP de n8n. Es anterior al cambio y no lo explota nadie que no salga por esa IP; solo lo anoto.

**Otras puertas de máquina con el patrón «limitador global antes de autenticar»: ninguna.** Los dos únicos limitadores con clave constante (global) son los dos internos, ya corregidos.
- **Voz pública** (`routes/voice.php:43-54`): no tiene `throttle` de ruta. Limita después de autenticar y cuenta los fallos en su cubo `ruta|IP|fallo` (`VoiceRateLimits::authenticated/failure`). Es el patrón correcto [L].
- **Voz CRM** (`voice-admin-*`): la sesión y la red anónima van en cubos separados (`voice-admin:anon:…`). Un anónimo no gasta el cupo de una persona [L].
- **Webhooks:** Meta no tiene `throttle`. Wompi (`throttle:120,1`) y Twilio (`throttle:60,1`) cuentan por IP, y nadie puede salir por la IP de Wompi o de Twilio [L].
- El patrón parecido, aunque no global, que sí aparece es el del kiosko: **NB-5**.

## 2. ¿Introduce algo nuevo?

**(a) Los intentos con un secreto equivocado ya no los limita la aplicación → NB-2, no hace falta un tope.**
- Fuerza bruta: el secreto tiene 64 caracteres (≥ 256 bits si es hexadecimal). Se compara con `hash_equals` (`VerifyInternalAutomationSignature.php:33`), que solo filtra la longitud, y el 401 es idéntico se acierte o no: no hay oráculo. Con el tope de nginx (30 por segundo por IP, ráfaga de 60), incluso un millón de IP daría unos 1,8·10⁹ intentos por minuto, frente a 2²⁵⁶. Es inviable.
- El tope global de 120 por minuto que había antes no era una defensa: era justamente el defecto. Limitaba la adivinación de rebote a costa de dejar mudo a n8n.
- Coste: el 401 ya no pasa por el limitador, así que se ahorra sus lecturas y escrituras en la caché, que en producción es la base de datos [L: `ThrottleRequests::handleRequest` → `tooManyAttempts` + `hit`]. Ese ahorro no lo pude medir (ver «Qué no se verificó»).
- Lo que se pierde es visibilidad: la aplicación no registra los 401 de esta puerta, así que detectar un barrido depende de los registros de nginx. Endurecimiento opcional, sin otra ronda: un contador de fallos por IP solo para avisos, como `VoiceRateLimits::failure` (que nunca frena una credencial buena), o la regla B de nginx ya prevista.

**(b) La puerta corre ahora antes de `SubstituteBindings`, `ProtectAdminPaths`, `EnforceAdminAuthorization` y `EnforceTrainerMemberScope`: con el secreto no cambia nada observable; sin él, solo se pierde lo que debía perderse [L][C].**
- El orden relativo de esas cuatro y del limitador no cambia (antes: `Throttle > SB > PAP > EAA > ETMS > Verify`; ahora: `Verify > Throttle > SB > PAP > EAA > ETMS`). La puerta solo lee la configuración, el `bearerToken()`, una cabecera y `getContent()`, nada de lo que preparan las otras. Con el secreto válido, el recorrido es idéntico.
- `SubstituteBindings`: ninguna ruta interna vincula un modelo. `context/{lead}` recibe un `int` (`InternalMarketingController.php:614`) y el único `Route::bind` (`member`, `routes/api.php:71`) no aparece en estas rutas. Antes tampoco había oráculo 404/401, y no se pierde ni se crea ninguno.
- `ProtectAdminPaths` y `EnforceAdminAuthorization` no hacen nada aquí: `AuthorizationMap::isAdministrative` solo mira `api/admin`, `api/payments` y `auth.admin` (`AuthorizationMap.php:1107-1124`). `EnforceTrainerMemberScope` está inerte sin sesión de entrenador (`EnforceTrainerMemberScope.php:44-47`).
- **Qué cambia sin el secreto (querido):** el 401 ya no lleva `X-RateLimit-Limit/Remaining`. Tras una inundación se recibe 401, no `429` con `Retry-After`. El 403 de firma mala y el 503 de secreto sin configurar ya no cuentan ni llevan esas cabeceras [C: sondas A y E].
- **Con el secreto:** el mismo tope y el mismo 429 con `Retry-After` [C: las dos pruebas de regresión].

**(c) Rutas no internas: ningún efecto [C].** 0 de las 1 025 cambian de orden. La prioridad funciona por clase, y ninguna ruta fuera de `api/internal/` lleva la puerta. Contraste: `GET /api/admin/voice/status` sin sesión sigue respondiendo `401` con `X-RateLimit-Limit: 60`, como antes (sonda F). El núcleo de consola no se ve afectado (`ApplicationBuilder.php:306-310`).

## 3. La firma HMAC opcional: 403 antes de contar → NB-3, no es un problema [C]
Sonda A: con el secreto válido y `X-IronBody-Signature` inválida, 12 de 12 respondieron `403`, ninguna con `X-RateLimit-*`, y después n8n recibió lo mismo que antes (`422` en `decide`, con el cuerpo de prueba).
- Para llegar ahí hace falta el secreto. Quien lo tiene ya puede gastar el cupo con llamadas válidas, que además sí tienen efecto. El camino del 403 no hace nada y no da más capacidad que el secreto mismo.
- Coste: un `hash_hmac` sobre el cuerpo, acotado por el tamaño máximo de petición. La pila global (TrimStrings y ConvertEmptyStringsToNull) ya recorre ese cuerpo antes del enrutado, así que el coste es del mismo orden que el de cualquier petición.
- La justificación del cubo («limita el abuso si el secreto se filtra», `routes/api.php:1933`) sigue valiendo para lo que importa: las llamadas válidas, que siguen contando.

## 4. Las pruebas
**Miden lo que dicen [C].**
- Con la corrección: `OK (13 tests, 207 assertions)` (las 10 nuevas más `LimitadoresInternosTest`).
- **Mutación propia sin tocar el producto:** una subclase temporal que en `setUp` quita la puerta de la prioridad del núcleo, es decir, el estado de `f1fe6cac`. Resultado: `Tests: 10, Assertions: 86, Failures: 8`. Caen las 3+3 de inundación (`Failed asserting that 429 is identical to 401`), la de cabeceras y la guarda. Pasan las dos de regresión del tope legítimo, como debe ser.

**La guarda cae si se quita la prioridad: sí [C].** Si se declara una ruta interna nueva con otro orden (`['throttle:…', 'automation.internal']`), la prioridad la corrige y la guarda la ve. Sonda B: orden efectivo `Verify > Throttle > …` y 12 de 12 respondieron `401` sin gastar el cubo.

**Dónde no llega → NB-1.** La guarda elige las rutas por la puerta **declarada** (`PuertasInternasSecretoAntesDelCupoTest.php:178-182`):
- **Sonda D (la que importa):** una ruta que use `throttle:internal-marketing-ai` **sin** la puerta vuelve a abrir el defecto. Doce anónimas recibieron `{"200":7,"429":5}`, y después n8n recibió **`429`** en `decide`. La guarda **no la ve** (`guarda_la_ve=false`). Hoy no existe ninguna ruta así (sonda de orden: 0).
- **Sonda C:** un alias nuevo de la misma clase sigue con el orden bien (por la prioridad) y devuelve 401 en 12 de 12, pero la guarda no lo ve. Es inocuo hoy.
- Una ruta nueva bajo `api/internal/` sin la puerta tampoco la ve. Eso ya no es este defecto, sino falta de autenticación. No he encontrado ninguna otra prueba que lo fije.

**Pruebas negativas que propongo para *tester*** (ninguna es obligatoria para cerrar PT-19):
1. La guarda, ampliada: en toda ruta cuyo orden efectivo incluya `ThrottleRequests:internal-automation` o `ThrottleRequests:internal-marketing-ai` (y, al integrar PT-15, el cubo de `ai/check`), la puerta existe y va delante. Además, toda ruta `api/internal/*` lleva la puerta en su orden efectivo. Seleccionar por clase resuelta (`gatherRouteMiddleware`), no por alias declarado.
2. Secreto válido con firma HMAC mala: 403, sin `X-RateLimit-*`, y lo que recibe n8n no cambia (fija la decisión del punto 3).

## 5. Despliegue
- **Cachés de Laravel: no lo anulan [L].** La prioridad se fija en cada petición, al resolver el núcleo HTTP desde `bootstrap/app.php`. El orden se calcula al despachar (`Router.php:816` → `sortMiddleware`). `route:cache` guarda las rutas con sus middlewares **declarados**, y `config:cache` no contiene middlewares, así que no hay que regenerar ninguna caché.
- **OPcache: es el único riesgo de que siga sirviendo el código viejo.** El guion versionado aborta si el FPM tiene `validate_timestamps` apagado (`deploy-cierre-lote.sh:897-900`). Con la revalidación encendida, el fichero nuevo entra en cuanto pasa `revalidate_freq`, sin recargar. Recargar PHP-FPM no es imprescindible; el propio guion la trata como «otra orden» que necesita autorización, y corta las conexiones SSE largas.
- **La prueba de que está activo es el marcador:** una sola petición sin credenciales debe dar `401` **sin** `x-ratelimit-*`. Si las cabeceras siguen apareciendo pasado `revalidate_freq`, el FPM sigue sirviendo el código viejo, y entonces sí hay que recargarlo (con autorización).
- **NB-4 (condición operativa):** `deploy-cierre-lote.sh:855-875` **aborta** con este diff. Solo admite `bootstrap/app.php` con `--rutas` y si lo único añadido es el montaje `Route::middleware('api')->prefix('api')->group(base_path('routes/<nuevo>'))`. La línea `$middleware->prependToPriorityList(` dispara «añade código que no es el montaje de un fichero de rutas». El guion falla cerrado, que es lo correcto, pero hace falta otra vía de despliegue autorizada o una enmienda del guion. No es un riesgo en silencio.

## Hallazgos
| ID | Sev. | Estado | Dónde | Qué | Acción |
|---|---|---|---|---|---|
| NB-1 | Baja | Confirmado (sondas C y D) | `tests/Feature/Marketing/PuertasInternasSecretoAntesDelCupoTest.php:178-182` | La guarda no ve una ruta que comparta los cubos de n8n sin la puerta (en ese caso el defecto vuelve: `decide` responde 429), ni alias nuevos, ni rutas `api/internal/*` sin puerta. Hoy hay 0. API4 y API8 | Ampliar la guarda (prueba 1) |
| NB-2 | Informativa | Leído y analizado | `VerifyInternalAutomationSignature.php:33` | Los fallos de secreto ya no los limita la aplicación. La fuerza bruta es inviable y no hay oráculo; solo se pierde la visibilidad en la aplicación. API2 y API4 | Ninguna. Opcional: contador de fallos por IP solo para avisos, o la regla nginx B |
| NB-3 | Informativa | Confirmado (sonda A) | `VerifyInternalAutomationSignature.php:38-43` | El 403 por firma mala con secreto válido no cuenta. No da más capacidad que el secreto | Ninguna. Opcional: prueba 2 |
| NB-4 | Operativa | Leído | `docs/ultron-studio/ops/deploy-cierre-lote.sh:855-875` | El guion aborta con este diff (falla cerrado) | Vía de despliegue autorizada + el marcador «401 sin x-ratelimit-*» |
| NB-5 | Baja, fuera del cambio, preexistente | Hipótesis sobre si se puede explotar | `AppServiceProvider.php:382-397`; `routes/api.php:258-264` | `throttle:kiosk` y `kiosk-identify` cuentan por el `device_id` **declarado** (cuerpo o `X-Device-Id`) antes de `device.signed`. Quien conozca el id de una tablet puede agotar su cupo de 60/min (o 20/min) y dejarla en 429. No es global (afecta a una tablet) y ya se valoró en la revisión F4 (2026-10-11). Depende de si los `device_id` se pueden averiguar, cosa que no verifiqué. API4 | Solo se documenta; no amplía PT-19 |

## Qué se verificó ejecutando
- Sonda de orden efectivo sobre las 1 046 rutas (script en mi carpeta temporal; arranca la aplicación con el entorno de pruebas, sin `.env`).
- PHPUnit, siempre de uno en uno: (1) las 10 nuevas + `LimitadoresInternosTest`: OK 13/207; (2) la mutación sin la prioridad: 8 de 10 caen; (3) sondas A–F: OK 6/8 (salidas citadas arriba).
- Las tres pruebas temporales de `backend/tests` se borraron al acabar: `git status --porcelain` vacío, HEAD `5cd201eb`.

## Qué NO se verificó, y por qué
- **Nada en producción** (por regla): ni el marcador después de desplegar, ni el alfabeto del secreto (solo sé su longitud, por el lead), ni `revalidate_freq`.
- **El ahorro de la base de datos en el 401:** la sonda no salió limpia (el 500 venía del montaje de la caché `database` en la prueba, no del código revisado). Queda como lectura [L].
- **La integración con PT-15** (el cubo propio de `ai/check`): no está en este árbol. Por lectura, la prioridad actúa por clase y lo cubrirá, pero conviene que la guarda ampliada (prueba 1) lo nombre.
- **La batería completa:** es del lead.
- **Si los `device_id` del kiosko se pueden averiguar** (NB-5).

## Riesgos residuales y decisiones del propietario
- El tope por IP para los fallos: recomiendo no añadirlo (NB-2). Decide el propietario.
- La regla de nginx para `/api/internal/` sigue como defensa añadida aparte.
- **No hay secretos que rotar** por esta revisión: no se ha visto ni impreso ninguno.
