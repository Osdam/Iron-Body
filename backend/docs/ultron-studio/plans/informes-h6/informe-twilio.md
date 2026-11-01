# Hito 6 · Paquete TW (cliente de solo lectura de Twilio y saneador de la sonda): informe del backend

**Fecha:** 31-10-2026. **Rama:** `feat/ultron-studio-h6` (worktree `_worktrees/ultron-h6`). El núcleo y las primeras 25 pruebas quedaron en el WIP `b647a8e` del lead. Desde entonces el código de `app/` **no ha cambiado**. En el árbol, **sin commit**, quedan:
- 2 pruebas retocadas: las comprobaciones estáticas leen el fichero de la clase cargada, y una línea en blanco que quitó Pint;
- la prueba de la cadena completa;
- el README de los fixtures;
- este informe.

**Estado:** paquete completo y en verde: **31 pruebas y 5782 aserciones**, **22 de 22 mutaciones cazadas**, Pint limpio y cero red.

**Pendiente:** la paridad con la sección `twilioProbeSanitize` de `tests/fixtures/ultron-studio/integrations-cases.json`. El fixture parcial del tester solo trae `phone`, `alias` y `fingerprint`, así que hoy no hay discrepancia que resolver. Las convenciones que el contrato no fijaba están abajo (D-10), para congelarlas.

## Ficheros

| Fichero | Líneas | Qué es |
|---|---|---|
| `app/Services/Twilio/TwilioReadOnlyClient.php` | 233 | Cliente: 5 GET fijos, hosts oficiales en `production`, primera página, 10 s/5 s, sin redirecciones ni reintentos, nunca lanza por la red. La única llamada `Http::` está en `:145` |
| `app/Services/Twilio/TwilioProbeSanitizer.php` | 285 | Saneador puro con lista blanca: de las respuestas crudas a `{outcome, facts}` (§I5.4) |
| `tests/Unit/Twilio/TwilioReadOnlyClientTest.php` | 634 | 18 pruebas (rutas, hosts, solo GET, SID, tabla de fallos, credencial, estáticas, neutralidad) |
| `tests/Unit/Twilio/TwilioProbeSanitizerTest.php` | 382 | 7 pruebas puras (casos del fixture, centinelas, forma cerrada, páginas llenas, reglas del `outcome`, pureza) |
| `tests/Unit/Twilio/TwilioProbePipelineTest.php` | 226 | 6 pruebas: cliente real + saneador en `production`, contra el fixture (C01, C03, C05, C09, C10 y C11) |
| `tests/Unit/Twilio/fixtures/twilio-api-responses.json` | 428 | Respuestas escritas a mano con la forma pública de la API; datos de ficción y textos hostiles |
| `tests/Unit/Twilio/fixtures/sanitize-cases.json` | 436 | 17 casos con resultado exacto y 66 centinelas |
| `tests/Unit/Twilio/fixtures/README.md` | 58 | Procedencia, convenciones y lo que el contrato no fijaba |

No he tocado nada más:
- ni `.env.example`, `config/*`, el OTP, `app/Services/Sms`, `TwilioUsageTriggerController` ni las rutas;
- ni nada del Studio o del motor;
- ni he ejecutado `composer`; no hay dependencias nuevas.

## Firmas finales

Las de §I5.4 **sin cambios**. Solo se añaden **constantes públicas**, ningún método:

```php
namespace App\Services\Twilio;

final class TwilioReadOnlyClient                       // métodos públicos: exactamente __construct y get
{
    public const PRODUCTION_API_BASE = 'https://api.twilio.com';
    public const PRODUCTION_MONITOR_BASE = 'https://monitor.twilio.com';
    public const STEPS = ['account', 'numbers', 'caller_ids', 'calls', 'alerts'];
    public const PAGE_SIZES = ['numbers' => 20, 'caller_ids' => 20, 'calls' => 50, 'alerts' => 50];   // + añadida
    public const ACCOUNT_SID_PATTERN = '/^AC[0-9a-f]{32}$/D';                                        // + añadida (D-1)
    public const MAX_TIMEOUT_SECONDS = 10;                                                            // + añadida
    public const CONNECT_TIMEOUT_SECONDS = 5;                                                         // + añadida
    public const ERROR_AUTH_FAILED = 'auth_failed';                                                   // + añadidas
    public const ERROR_UNREACHABLE = 'unreachable';
    public const ERROR_OTHER = 'error';

    public function __construct(#[SensitiveParameter] string $accountSid, #[SensitiveParameter] string $username,
        #[SensitiveParameter] string $secret, ?string $nonProductionBase = null, int $timeoutSeconds = 10);
    /** @return array{ok: bool, status: ?int, body: ?array<string,mixed>, error: null|'auth_failed'|'unreachable'|'error'} */
    public function get(string $step, \DateTimeInterface $now): array;
}

final class TwilioProbeSanitizer                       // método público: exactamente sanitize; puro
{
    public const CREDENTIALS = ['otp', 'restricted_key'];                                            // + añadidas (conjuntos de I2 y I5.4)
    public const OUTCOMES = ['ok', 'degraded', 'auth_failed', 'unreachable', 'error'];
    public const STEP_OUTCOMES = ['auth_failed', 'unreachable', 'error', 'skipped'];
    public const ACCOUNT_STATUSES = ['active', 'suspended', 'closed'];
    public const ACCOUNT_TYPES = ['Trial', 'Full'];
    public const CALL_STATUSES = ['queued', 'ringing', 'in-progress', 'completed', 'busy', 'failed', 'no-answer', 'canceled'];
    public const ALERT_LEVELS = ['error', 'warning', 'notice', 'debug'];
    public const OTHER = 'other';
    public const FACT_KEYS = ['account' => 'account', 'numbers' => 'numbers', 'caller_ids' => 'callerIds', 'calls' => 'calls7d', 'alerts' => 'alerts24h'];

    /** @return array{outcome: 'ok'|'degraded'|'auth_failed'|'unreachable'|'error', facts: array<string,mixed>} */
    public static function sanitize(array $responses, string $credential): array;
}
```

**Rutas exactas**, verificadas con `Http::fake` y con la documentación oficial del 31-10-2026. Con `{sid}` = `otp.twilio.sid`:

| Paso | URL |
|---|---|
| `account` | `{api}/2010-04-01/Accounts/{sid}.json` |
| `numbers` | `{api}/2010-04-01/Accounts/{sid}/IncomingPhoneNumbers.json?PageSize=20` |
| `caller_ids` | `{api}/2010-04-01/Accounts/{sid}/OutgoingCallerIds.json?PageSize=20` |
| `calls` | `{api}/2010-04-01/Accounts/{sid}/Calls.json?PageSize=50&StartTime%3E=<hoy−7 días en UTC, YYYY-MM-DD>` |
| `alerts` | `{monitor}/v1/Alerts?PageSize=50&StartDate=<ahora−24 h, YYYY-MM-DDThh:mm:ssZ>` |

En `calls`, el `>` es parte del **nombre** del parámetro (`StartTime>`, «started on or after this date»). La doc lo escribe `StartTime>=2009-07-06`, que es exactamente lo que codifica `http_build_query`.

## Cómo lo usa S (ruta 56, `StudioTwilioProbe`)

```php
$sid = (string) config('otp.twilio.sid');
$keySid = config('ultron_studio.integrations.twilio_probe.key_sid');
$keySecret = config('ultron_studio.integrations.twilio_probe.key_secret');
$restringida = filled($keySid) && filled($keySecret);
// Antes de construir: 409 twilio_not_configured.
//  - missing_account_sid: si $sid está vacío o NO casa con TwilioReadOnlyClient::ACCOUNT_SID_PATTERN.
//  - missing_credential: si falta el usuario o el secreto elegidos.
// Si no se comprueba, el constructor lanza InvalidArgumentException y saldría un 500.
$cliente = new TwilioReadOnlyClient($sid, $restringida ? $keySid : $sid,
    $restringida ? $keySecret : (string) config('otp.twilio.token'),
    config('ultron_studio.integrations.twilio_probe.base'));   // el cliente la ignora en production
$credential = $restringida ? 'restricted_key' : 'otp';
```

- **Orquestación.** Es de S; el cliente hace una petición por llamada.
  - Los pasos van en el orden de `STEPS`.
  - Si `account` da `auth_failed` o `unreachable`, el resto va como `['skipped' => true]` (o se omite: un paso ausente cuenta como saltado).
  - Pasados 30 s, también `skipped`.
  - `TwilioProbePipelineTest::sondear()` es la orquestación mínima de referencia.
- **Presupuesto de 30 s.** Con 10 s por paso, el peor caso pasa de 30 s si el último paso empieza a los 29 s. Si S quiere un tope duro, puede construir el cliente de cada paso con `max(1, min(10, 30 − transcurrido))`. El cliente recorta a 1–10.
- **Los cuerpos crudos llevan datos personales:** `to` y `from` de las llamadas, y caller ID que pueden ser el móvil de una persona.
  - Nunca se registran ni se guardan.
  - Solo se guarda `facts`, y los cuerpos se descartan con `unset` tras sanear (como el teléfono del lead en la coherencia).
  - El `auth_token` ya sale quitado del cliente.
- **`facts`:**
  - solo admite null, booleanos, enteros, cadenas y arrays, así que `StudioContentHasher::canonicalJson()` lo acepta tal cual;
  - los recuentos van ordenados;
  - un recuento vacío es `[]`, también en el JSON de la API y del CRM, porque el hasher no admite objetos;
  - las claves de `byCode` son cadenas numéricas (`"60200"`).

## Variables para S (`.env.example` y `config/ultron_studio.php`, §I12)

| Variable | Clave | Defecto seguro | Nota |
|---|---|---|---|
| `ULTRON_STUDIO_TWILIO_PROBE_ENABLED` | `ultron_studio.integrations.twilio_probe.enabled` | `false` | `filter_var(…, FILTER_VALIDATE_BOOLEAN)` |
| `ULTRON_STUDIO_TWILIO_PROBE_KEY_SID` | `…twilio_probe.key_sid` | vacío (`null`) | SID de la API key restringida (`SK…`). Manda solo si está con su secreto |
| `ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET` | `…twilio_probe.key_secret` | vacío (`null`) | Secreto. Nunca en logs ni respuestas |
| `ULTRON_STUDIO_TWILIO_PROBE_BASE` | `…twilio_probe.base` | vacío (`null`) | Solo fuera de `production` (E2E: `http://127.0.0.1:8095`). En `production` el cliente la ignora; inválida, también |
| — | `…twilio_probe.cooldown_seconds` | `300` | Fijo en el código, sin variable |

- El paquete TW **no lee configuración ni entorno**: una prueba estática lo vigila. Todo llega por el constructor.
- `TWILIO_BASE` y `TWILIO_VERIFY_BASE` no intervienen nunca en la sonda.

## Para el tester

- **`mock-twilio.cjs` (:8095).** Con `APP_ENV` ≠ `production`, los 5 pasos van a la **misma** base.
  - Peticiones: `GET /2010-04-01/Accounts/{sid}.json`, `…/IncomingPhoneNumbers.json?PageSize=20`, `…/OutgoingCallerIds.json?PageSize=20`, `…/Calls.json?PageSize=50&StartTime%3E=YYYY-MM-DD` y `/v1/Alerts?PageSize=50&StartDate=YYYY-MM-DDThh%3Amm%3AssZ`.
  - Llegan con Basic y `Accept: application/json`.
  - Las redirecciones no se siguen.
  - El modo 401 basta en `account`: la sonda se para ahí.
- **Sección `twilioProbeSanitize`:** las convenciones de D-10 están también en `tests/Unit/Twilio/fixtures/README.md`. Mis 17 casos pueden servir de semilla.
- **Vector oficial de la firma de Twilio:**
  - recibido: `RSOYDt4T1cUTdK1PDd93/VVr8B8=` (formulario), `a9nBmqA0ju/hNViExpshrM61xv4=` (JSON con `bodySHA256`) e `IK+Dwps556ElfBT0I3Rgjkr1wJU=` (claves repetidas);
  - **no lo uso**: es del validador del webhook (T-1), fuera de este paquete;
  - no lo he verificado y no está en mis fixtures. Va a `twilioSignatureOfficial`.

## Salidas reales (31-10-2026, en el worktree)

```
$ php -d memory_limit=2G vendor/bin/phpunit tests/Unit/Twilio
OK (31 tests, 5782 assertions)

$ vendor/bin/pint --test app/Services/Twilio tests/Unit/Twilio
{"tool":"pint","result":"passed"}        # antes: 1 fallo en TwilioReadOnlyClientTest (una línea en blanco doble), corregido con pint sobre ese fichero

$ grep -rn "Http::" app/Services/Twilio
app/Services/Twilio/TwilioReadOnlyClient.php:145: $respuesta = Http::withBasicAuth(…)->acceptJson()->withoutRedirecting()->connectTimeout(…)->timeout(…)->get($url, $query);

$ grep -rn -i -w -E "post|put|patch|delete|send|asForm" app/Services/Twilio
(ninguna)
```

**Red.** Cero red en las pruebas:
- `TwilioReadOnlyClientTest` y `TwilioProbePipelineTest` llevan `Http::preventStrayRequests()` en `setUp()` y en cada fábrica nueva. Una petición que se escape lanza `StrayRequestException`, que el cliente **relanza** (prueba propia).
- `TwilioProbeSanitizerTest` no arranca Laravel ni usa el cliente HTTP. Su prueba de pureza lo comprueba.

**Mutaciones.** Cada mutante es una copia en el scratchpad, cargada con un bootstrap propio antes que el autoloader, con `--no-logging` y `--do-not-cache-result`. No se tocó el árbol compartido. La línea base por el mismo arnés da `OK (31 tests, 5782 assertions)`. **Las 22 quedan cazadas:**

| # | Mutación | Pruebas que caen (ejemplos) |
|---|---|---|
| C01 | Ignorar `production` y usar la base | `test_en_produccion_siempre_los_hosts_oficiales…` y las 6 de la cadena |
| C02 | `get` → `post` | `test_solo_get_recorriendo_los_metodos_publicos`, la estática y 10 más |
| C03 | Añadir `retry(2)` | `test_tiempos_cortos_sin_redirecciones_ni_reintentos`, la tabla, la estática y 4 de la cadena |
| C04 | Quitar `withoutRedirecting()` | `test_una_redireccion_no_se_sigue…`, tiempos, estática |
| C05 | Seguir `next_page_uri` | `test_un_next_page_uri_en_la_respuesta_no_se_pide` y la estática |
| C06 | Añadir `Page=1` | Las cinco rutas, hosts y la base del simulador |
| C07 | El 401 deja de ser `auth_failed` | La tabla y `…401_en_la_cuenta…_c03` |
| C08 | Quitar el `catch` de `ConnectionException` | La tabla, C05 y C10 de la cadena |
| C09 | No quitar el `auth_token` | `test_la_ficha_de_la_cuenta_sale_sin_su_auth_token…` |
| C10 | SID sin el modificador `D` | `test_un_sid_mal_formado_no_llega_a_la_ruta` (el salto de línea final) |
| C11 | Secreto en una propiedad en claro | `test_la_credencial_no_sale_en_trazas_volcados_ni_serializaciones` |
| C12 | Sin techo de tiempo | `test_tiempos_cortos…` |
| S01 | Copiar `friendly_name` | Casos del fixture, centinelas, páginas, `outcome` y 3 de la cadena |
| S02 | `truncated` por `next_page_uri` | `test_las_paginas_llenas…` |
| S03 | Estado de llamada tal cual, sin `other` | Casos y centinelas |
| S04 | Cualquier serie de dígitos es un código | Casos y centinelas (el `3055550199`) |
| S05 | `last4` sin exigir E.164 | Casos (C15) |
| S06 | La cuenta con error degrada | Casos y reglas del `outcome` |
| S07 | Paso saltado con bloque `[]` | Casos, reglas y C03/C05 de la cadena |
| S08 | Sin recorte a la página | `test_las_paginas_llenas…` |
| S09 | Aceptar la ficha sin SID válido | Casos (C07) y reglas |
| S10 | Tolerar una entrada que no es un objeto | Casos (C12) |

**Comprobación extra fuera de mi paquete.** `UltronEngineIsolationTest::test_fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` (filtrada) **falla**. Solo señala las dos migraciones del paquete DB (`2026_11_01_100001_…` y `…100002_…`), que según §I9.1 S tiene que añadir a la lista blanca. De `Twilio`, **0** apariciones.

## Decisiones fuera del contrato

- **D-1 · `ACCOUNT_SID_PATTERN` con `D`.** El patrón literal `^AC[0-9a-f]{32}$` **acepta un salto de línea final** en PCRE. Lo comprobé en el PHP 8.3 local: `preg_match` da 1 sin `D` y 0 con `D`. Con `D`, `$` es el final real. Lo mismo vale para el E.164 del saneador y el código de alerta.
- **D-2 · El cliente quita `auth_token`.** La doc oficial del recurso Account confirma que `GET Accounts/{sid}.json` devuelve el `auth_token` («sensitive»). Se quita antes de devolver el cuerpo (`:173`). El saneador no lo copiaría de todos modos.
- **D-3 · Credencial envuelta en `SensitiveParameterValue` y parámetros con `#[SensitiveParameter]`.** Comprobado en local:
  - `__debugInfo` **no** basta: `dump()`/`dd()` de Symfony enseñan las propiedades privadas igualmente.
  - Envuelta, no sale en `var_dump`, `print_r`, `var_export`, `json_encode` ni `dump()`.
  - `serialize()` del cliente falla, así que no puede viajar a una cola ni a una caché.
  - La traza de una excepción del constructor lleva `Object(SensitiveParameterValue)`.
  - Coste: ninguno. No hay método público nuevo.
- **D-4 · También lanzan `InvalidArgumentException`, sin red ni eco del valor:**
  - una credencial vacía (en blanco);
  - un paso desconocido, en `get()` y en `sanitize()`;
  - una credencial del saneador fuera de `CREDENTIALS`;
  - una respuesta que no es un array, o `null` explícito.

  Son errores de quien llama, nunca de Twilio.
- **D-5 · Tiempos.** `timeout` se recorta a 1–10 s y `connectTimeout` = mín(5, timeout).
- **D-6 · Sin redirecciones** (`withoutRedirecting()`). Un 3xx es `error`: seguirlo llevaría la credencial a otro host.
- **D-7 · Cuerpos que no son un objeto JSON.** HTML, texto, número, `null`, vacío, JSON roto, más de 32 niveles de profundidad, o un 204 sin cuerpo: todos dan `error` con su estado.
- **D-8 · Otro `Throwable` del cliente HTTP** (no de conexión) → `error`, con un `Log::warning` que lleva **solo** el paso y la clase. El mensaje puede llevar la URL con el SID.
- **D-9 · Formatos de fecha.**
  - `StartTime>` va como `YYYY-MM-DD` UTC: es la forma que describe la doc. La doc también muestra ejemplos con fecha y hora.
  - `StartDate` va como `YYYY-MM-DDThh:mm:ssZ`, con `Z`, no `+00:00`: la doc solo admite esa forma o la fecha sola.
- **D-10 · Convenciones del saneador que el contrato no fijaba:**
  1. Un paso que falla, se salta o trae otra forma deja su bloque en **`null`** y una entrada en `errors`. El ejemplo del contrato pinta `alerts24h` con datos y a la vez un error de `alerts`; lo leo como ilustración de las dos formas: «sin dato» no es «cero».
  2. Un paso ausente cuenta como `skipped`.
  3. Lo que no casa con su conjunto cuenta como `other` en `byStatus`, `byCode` y `byLevel`, y queda en `null` en `account.status` y `account.type`.
  4. `byCode` solo admite de 1 a 6 cifras sin cero inicial: un teléfono no puede ser una clave.
  5. `last4` solo sale de un E.164 estricto. Si no, `null`.
  6. Cuerpo inesperado:
     - la ficha sin un `sid` `AC…` válido (un cuerpo de error de Twilio con 200 no pasa por cuenta);
     - una lista que falta, que no es una lista o que tiene una entrada que no es un objeto.

     Da `error` con el estado que vino.
  7. Con más filas de las pedidas, `truncated = true` y se recorta a la página. `truncated` sigue siendo «página llena», como dice el contrato, y no `next_page_uri`.
  8. `capabilities.voice` y `capabilities.sms` cuentan solo con `=== true`.
- **D-11 · Constantes públicas añadidas** (ver «Firmas»), para que S, T y el exportador usen los mismos conjuntos. No se añade ningún método.

## Datos y dudas para el lead

1. **Congelar D-10** en una enmienda antes de que el tester escriba `twilioProbeSanitize`, o decirme qué cambiar. Si el fixture discrepa, corrijo el saneador.
2. **`[]` frente a `{}`** en los recuentos vacíos. Lo impone `StudioContentHasher::canonicalize()`, que no admite objetos. El CRM tiene que aceptar `[]` como mapa vacío (paquete FE).
3. **Presupuesto de 30 s** frente a 10 s por paso: lo resuelve S (sugerencia en «Cómo lo usa S»).
4. **`UltronEngineIsolationTest` en rojo** por las migraciones del paquete DB: lo cierra S con la lista blanca de §I9.1.
5. **API key restringida.** Si el propietario la crea y no le da lectura de Monitor, `alerts` sale `auth_failed` y la sonda queda `degraded` (caso C08), sin que eso sea un fallo. Necesita lectura de los cinco recursos.
6. **Para `security` / `api-security-specialist`.** Fuera de `production`, la base del simulador no se limita a `127.0.0.1`: es la paridad con `StudioLabOpenAiClient` y con §I12. En un servidor con `APP_ENV` ≠ `production`, el token real y una base ajena, la credencial iría a esa base. Solo puede ponerla quien controla el entorno del servidor, que ya puede leer el token. Lo dejo señalado, sin cambiarlo.
