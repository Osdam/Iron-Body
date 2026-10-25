# Hito 5 · paso 1c · informe del paquete B-M (motor): el lector, la llave, el sello y la frontera

**Fecha:** 24-10-2026 noche (Bogotá) · 25-10 UTC. **Autor:** `backend` (paquete B-M). **Árbol:** `_worktrees/thermal-receipts/Iron-Body/backend`, rama `feat/ultron-studio-h5` (al empezar `0366009`; durante el trabajo el lead añadió `3735b58`, solo docs, y `f4d64b8`, las migraciones del especialista de datos). **Contrato:** `ULTRON_STUDIO_H5_CONTRACT.md` §A4, §A9 (pruebas 1 y 2) y §A13; plan §2.3–§2.5, §3.1 «Motor» y §6 paso 1c; y el fixture del contrato del tester (`tests/fixtures/ultron-studio/publication-cases.json`, paso 1b), que mi prueba consume como oráculo. **Sin commit:** todo queda en el árbol de trabajo. **Producción: nada tocado.**

## 0. Resumen

- **El lector existe y no puede romper un turno.** `UltronPublishedConfig` lee la cabeza de `ultron_studio_publications` con UNA sentencia, verifica contrato → huella → firma → decodificación estricta (claves y tipos), resuelve la parte por conversación y devuelve el sello; cualquier `Throwable` acaba en `fallback/read_failed` (o `table_missing`). Probado en SQLite y en **PostgreSQL 14.24** dentro de una transacción (la lectura va en un punto de guardado: la transacción no queda abortada) y fuera de ella.
- **El commit sella TODAS sus filas** (ejecutada, bloqueada por inelegible y por relevo, crítico fallido, rendición y la hora de la visita), con una sola lectura por commit, tras las puertas de interruptor, freno y canario y antes de la elegibilidad, sin ninguna transacción del commit abierta. El sello viaja como argumento obligatorio de `persist()`: dos commits seguidos —incluso con la MISMA instancia del servicio— no lo comparten.
- **Con la llave en `off` (el defecto) el motor es Legacy exacto:** cero sentencias que nombren `ultron_studio_` y ninguna clave `studio` en las filas (probado sentencia a sentencia con `DB::listen`). La respuesta a n8n es idéntica con `off`, `canary` y `all` en los cinco caminos, y la entrada del commit no cambió (`UltronController` intacto: `unexpected_fields` igual).
- **El fixture del contrato pasa entero:** los 24 casos de `signature` y los 63 de `scope`, en SQLite y en PG. Para eso alineé el lector con tres puntos que el contrato no fijaba y el fixture sí (§5, D-1 a D-3). **La Enmienda 1 del contrato (`02d1cf5`) los congeló después, Q-1 de la subclave incluida, tal como están implementados.**
- **Diff del motor contra `origin/main`:** solo `UltronPublishedConfig.php` (nuevo), `UltronCommitService.php` y `config/marketing.php`. `auth:coverage --unmapped` vacío; 33 rutas del Studio; `ENGINE_READS_VERSIONS` sigue en `false` (es del paso 3).
- **Suites sobre el código final:** ULTRON (`tests/Feature/Marketing` + `tests/Unit/Marketing`, 4977) **en verde con la llave en `off` y con `all`**, sin omitidas; la unitaria del lector 217/217 en SQLite y en PG con igualdad estricta frente al fixture (87/87). Los únicos rojos de Studio, Access, Chaos y Marketing en PG son **idénticos en la base `f4d64b8` sin mi código** (§4).
- **Mutaciones:** las cuatro pedidas (quitar el `try`, sellar solo las ejecutadas, leer con `off`, recanonicalizar antes de firmar) y ocho más: **las 12 cazadas**, restauración verificada por sha256 en cada una (§4).

## 1. Qué cambió y por qué (fichero:línea)

| Fichero | Cambio | Por qué |
|---|---|---|
| `app/Services/Marketing/Ultron/UltronPublishedConfig.php` (NUEVO, 500 líneas) | El lector. `forConversation()` `:144`, `describe()` `:187`, `signatureFor()` `:241`; la cabeza en `head()` `:258` (una sentencia `:263`; punto de guardado si hay transacción abierta `:270`); la escalera `verified()` `:279`; huella `:296` y firma `:305` sobre los bytes guardados; decodificación estricta `parse()` `:323` / `parteValida()` `:359`; alcance `resolve()` `:390`; el sello `stamp()` `:418`; el `fallback` con su log `:436`; la subclave `subkey()` `:461` (`:471`); tabla inexistente `:495` (mismo criterio que `MarketingAgentSwitch::tablaInexistente`). La tabla del Studio se nombra una sola vez: `TABLE` `:103` | §A4.1–§A4.9. Única clase del motor que nombra una tabla del Studio; no importa nada de `App\Services\UltronStudio` ni `App\Models\UltronStudio`; sin la subcadena `UltronStudio` en el nombre (§A9) |
| `app/Services/Marketing/Ultron/UltronCommitService.php` (+58/−9) | Constructor `:162` (dependencia con valor por defecto, como el freno). **Una lectura en `execute()` `:324`** (comentario `:305-323`): después del interruptor `:238`, el freno `:262` y el canario `:291-303`, y antes de la elegibilidad `:327`. El sello viaja como argumento: `blocked()` `:328` y `:334`, hora de la visita `:380`, rendición `:397`, crítico fallido `:402`, fila ejecutada `:1236`; parámetros nuevos en `handleCriticFailure()` `:1766`/`:1768`, `handleRecovery()` `:1810`/`:1819`, `safeFallback()` `:1835-1844`/`:2037`, `contestarLaHoraDeLaVisita()` `:2239`/`:2259`, `blocked()` `:3848`/`:3863`; `persist()` `:2440-2453` (parámetro **obligatorio**) y la clave `studio` junto a `knowledge_version` `:2472-2474` | §A4.6. Nada más: ni puertas, ni 403, ni cambios en la respuesta ni en la entrada |
| `config/marketing.php:87-104` | `ultron.studio_config` ← `MARKETING_ULTRON_STUDIO_CONFIG`: `canary` o `all` (sin distinguir mayúsculas ni espacios alrededor); todo lo demás, `off` | §A13 |
| `.env.example:286-294`, `:297-298` | La variable documentada y en `off`; la primera línea del bloque del Studio («el motor NO lee nada del Studio») matizada, porque con la llave encendida deja de ser cierta | §A13 |
| `tests/Unit/Marketing/UltronPublishedConfigTest.php` (NUEVO, 217 casos) | El fixture del contrato (24 de firma + 63 de alcance); vectores propios calculados en Python; llave (13 valores) y variable de entorno (11); matriz de alcance propia (36); escalera y su orden; 37 casos `malformed` y 7 de «los valores no se validan»; bytes sin recanonicalizar; declaración enorme; nunca lanza dentro y FUERA de transacción; `describe()` | §A12 «Motor» |
| `tests/Feature/Marketing/UltronCommitStudioStampTest.php` (NUEVO, 30 casos) | Sello ausente con `off` y presente en los 5 caminos + la hora de la visita; `out_of_scope`; dos commits con la misma instancia; respuesta idéntica en los 5 caminos × 3 llaves; 6 averías que no tumban el turno; frontera A9.2 (3 llaves × 2 caminos), con el nivel de transacción en el momento de la lectura | §A12 «Motor» y §A9 prueba 2 |
| `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | Lista blanca + las dos migraciones de H5; aserción propia del lector (sus referencias al Studio son exactamente `'ultron_studio_publications'`, una vez, sin llamadas de escritura); zonas del motor limpias salvo el lector; fuera del Studio solo `UltronCommitService` nombra al lector; `off` por defecto evaluando `config/marketing.php` sin la variable. **No** gira `ENGINE_READS_VERSIONS` ni cambia las 33 rutas | §A9 prueba 1, paso 1c |

`tests/Feature/UltronStudio/UltronStudioEngineReadOnlyTest.php` **no se tocó**: la prueba A9.2 vive en `UltronCommitStudioStampTest` (ver §5, D-10).

**Dependencia entre commits:** `UltronPublishedConfigTest` lee `tests/fixtures/ultron-studio/publication-cases.json` (paquete T, aún sin seguimiento). Tiene que entrar en el mismo commit que este paquete o antes; sin él, sus dos proveedores fallan con «Falta el fixture del contrato».

## 2. Contratos

- **Entrada y respuesta de `POST ai/commit`:** sin cambios (prueba de instantánea, §4). n8n no ve nada nuevo.
- **`marketing_ai_actions.metadata.studio`:** clave NUEVA y aditiva, solo con la llave distinta de `off` (§3.4). Con `off` la fila es idéntica a la de hoy (`array_filter` de nulos de `persist()`).
- **Configuración:** `MARKETING_ULTRON_STUDIO_CONFIG` → `marketing.ultron.studio_config`, `off` por defecto. Sin migraciones propias (las de la cadena son del especialista de datos, `f4d64b8`).

## 3. Firmas exactas para el paquete del Studio (B-S)

### 3.1 La clase

```php
namespace App\Services\Marketing\Ultron;

final class UltronPublishedConfig
{
    public const OFF = 'off';  public const CANARY = 'canary';  public const ALL = 'all';
    public const CONTRACT = 'ultron-engine-config/1';           // engine_contract y prefijo firmado
    public const STAMP_VERSION = 1;
    public const APPLIED = 'applied';  public const LEGACY = 'legacy';
    public const OUT_OF_SCOPE = 'out_of_scope';  public const FALLBACK = 'fallback';
    public const CONTRACT_UNKNOWN = 'contract_unknown';  public const HASH_MISMATCH = 'hash_mismatch';
    public const SIGNATURE_INVALID = 'signature_invalid';  public const MALFORMED = 'malformed';
    public const TABLE_MISSING = 'table_missing';  public const READ_FAILED = 'read_failed';
    public const PART_CANARY = 'canary';  public const PART_REST = 'rest';
    public const MAX_EFFECTIVE_JSON = 16384;                    // caracteres

    /** Solo el motor (UltronCommitService). El sello, o null con la llave en off. Nunca lanza. */
    public function forConversation(int $conversationId): ?array;

    /** §A4.9. Para StudioEngineReadings (extra governance.studioPublication) y la evidencia. PROPAGA los fallos de lectura. */
    public function describe(): array;

    /** §A4.1. Pura respecto de la base (solo lee la APP_KEY). Lanza RuntimeException sin APP_KEY. */
    public static function signatureFor(string $effectiveJson): string;
}
```

Se resuelve por el contenedor sin argumentos (`app(UltronPublishedConfig::class)`); `signatureFor` es estática (también se puede llamar sobre una instancia).

**La firma, tal como está implementada** (igual que el fixture, decisiones S-1 a S-4):

```php
$appKey = config('app.key');                                   // 'base64:…' → base64_decode(substr($appKey, 7)), como Laravel
$k      = hash_hmac('sha256', 'ultron-studio-publication', $appKey, true);   // k = HMAC(clave = APP_KEY, mensaje = etiqueta), 32 bytes
$firma  = hash_hmac('sha256', "ultron-engine-config/1\n".$effectiveJson, $k); // 64 hex en minúscula
```

**Congelado por la Enmienda 1 (Q-1):** §A4.1 escribe `k = HMAC-SHA256("ultron-studio-publication", APP_KEY)`. Con la notación de la fórmula de la firma de esa misma línea, `HMAC(clave, mensaje)`, saldría la etiqueta como clave; el fixture y la implementación usan la otra lectura (la `APP_KEY` como clave del HMAC), que es la derivación convencional y la de todos los `hash_hmac` del código. Las dos son seguras con una `APP_KEY` aleatoria de 32 bytes; lo que importa es que haya una sola. Mi primera versión implementó la lectura literal; la cambié al ver el fixture, y la Enmienda 1 decidió esta (la `APP_KEY` es la clave y la etiqueta el dato). Los vectores de la lectura descartada siguen en §3.3 como «lo que NO es».

### 3.2 Lo que el Studio tiene que escribir para que el motor lo aplique

| Columna | Valor exacto |
|---|---|
| `engine_contract` | `UltronPublishedConfig::CONTRACT` |
| `effective_json` | `StudioContentHasher::canonicalJson($entrada)`, **≤ 16 384 caracteres** (una real ocupa ~600). El motor lee como mucho 16 385: una más larga sale `hash_mismatch` |
| `effective_hash` | `hash('sha256', $effective_json)` (hex en minúscula; se compara exacto: en mayúsculas es `hash_mismatch`) |
| `signature` | `UltronPublishedConfig::signatureFor($effective_json)` (hex en minúscula; exacto) |
| `uuid` / `seq` | Los MISMOS que `entry` / `seq` dentro de `effective_json`, y `engine_contract` igual a `contract` (si no: `malformed`; impide copiar una entrada firmada a otra fila). `entry` se compara sin distinguir mayúsculas (PostgreSQL devuelve el `uuid` en minúscula) |

Forma de `$entrada`: claves exactas en los tres niveles (raíz `canary, contract, entry, issuedAt, rest, seq`; parte `approvedBy, catalogVersion, contentHash, declared, publishedBy, version`; `declared` con los 4 `must_match`) y tipos exactos: partes objeto o `null` (nunca `false` ni una lista); `contract`, `entry`, `issuedAt`, `catalogVersion`, `contentHash`, `version` e `intent.driver`, texto; `seq`, `approvedBy`, `publishedBy`, enteros (`"1"`, `1.0`, `true` no valen); los otros tres de `declared`, booleanos. **Los valores no se validan** (formato del uuid, de la huella o de `issuedAt`; el fixture fija `issuedAt` como `AAAA-MM-DDTHH:MM:SSZ` en su sección `effectiveJson`, que es del Studio). Las claves duplicadas no se detectan (PHP se queda con la última): sin la `APP_KEY` nadie firma un JSON así, y `canonicalJson` no las produce.

### 3.3 Vectores (Python 3, `hmac` + `hashlib`; fijados en `UltronPublishedConfigTest`, además de los del fixture)

`K1 = base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=` (bytes 0x00…0x1f), `K2 = clave-de-prueba-sin-prefijo`, `J1` = la constante `J1` de la prueba, `J3 = {"nota":"ñandú"}`.

| Caso | Valor |
|---|---|
| subclave(K1) (coincide con el `subkeyHex` del fixture para la misma clave) | `3059c3cdeeb5cc101c324228c5246f7814b638efe63cbd07da2ccf050a5271c3` |
| subclave(K2) | `3db2a02c7ffe232e6f86f04d1c9fe8f7d6ab808ea0117b34d4f425987b5352d4` |
| firma(K1, J1) | `1ef3a49eb99dba5d0ceb376937b3c449156ae040f9bcf29eeeea3e604e3e7b18` |
| firma(K2, J1) | `f2820b29a40bcd32aa84b9a24528a0349cf0600527c869109c46ab66af221178` |
| firma(K1, J3) | `1747600ef9e86d2130f4174d2f1633edbb88eaa249401dc5e43c800c080db58f` |
| firma(K1, "") | `d1cfd2d0214ff4a2bff1174f5350d7fbd8bf64210ecb45ab564a2373c90b9797` |
| NO es: HMAC con la APP_KEY tal cual (la del `decide_token`) | `e4993e04c87a860f57a0e6f938dc1775d5643ec9a908bc2689941293a4055465` |
| NO es: la lectura literal descartada por la Enmienda 1 (la etiqueta como clave) | `2c40c4cb11310239b51ed278de151299b72463483a6816ee45e57dd5d894187d` |
| NO es: sin el prefijo del contrato | `edc753bb89614006229af8a78edae626b302430f0b40b3a83cad672e6ae4a4aa` |
| NO es: la subclave en hexadecimal | `e4237ed474e691f1dd668d2369ab1f5ecd6ef8221012daf323fe1db796c89951` |

### 3.4 El sello (`metadata.studio`)

Siempre las nueve claves de §A4.5, en ese orden: `{v:1, env, state, entry, seq, part, version, contentHash, reason}` (fixture R-2 y R-3).

| Estado | `entry`/`seq` | `part`/`version`/`contentHash` | `reason` |
|---|---|---|---|
| `applied` | columnas `uuid`/`seq` de la cabeza | la parte aplicada (`canary`/`rest`) | null |
| `legacy` sin ninguna fila | null | null | null |
| `legacy` con cabeza cuya parte es null | la cabeza | null | null |
| `out_of_scope` (llave `canary` y conversación no canaria, o sin canario configurado) | la cabeza | null | null |
| `fallback` con fila (`contract_unknown`, `hash_mismatch`, `signature_invalid`, `malformed`) | **la fila leída**: así el sello dice qué fila está mal | null | el motivo |
| `fallback/table_missing`, `fallback/read_failed` | null | null | el motivo |

**En PostgreSQL `metadata` es `jsonb`:** el orden de las claves NO se conserva al releer (PG pone primero las cortas). Hay que leer por clave (`metadata->'studio'->>'state'`), nunca por posición; la prueba del commit compara sin el orden. El `version` del sello es literalmente el de `effective_json`.

### 3.5 `describe()`

```php
['env' => 'off'|'canary'|'all',
 'head' => null | ['entry' => ?string, 'seq' => ?int, 'contract' => ?string, 'hashOk' => bool, 'signatureOk' => bool, 'parseOk' => bool],
 'parts' => ['canary' => bool, 'rest' => bool],     // solo con la cabeza verificada entera; si no, false/false
 'canaryConfigured' => bool]                          // nunca el id
```

- Lee la cabeza **con cualquier llave** (también `off`): la llama el Studio, no el commit; `env` dice si el motor la está leyendo. Si se prefiere que con `off` no consulte, es una línea (decisión del lead, §5 D-6).
- Cada comprobación por su lado: el Studio distingue una huella alterada (`hashOk:false`) de una `APP_KEY` rotada (`hashOk:true, signatureOk:false`).
- **Propaga** un fallo de lectura (tabla inexistente, error de consulta, sin `APP_KEY`): `StudioEngineReadings` tiene que envolverlo en su `reading()` para que salga `{available:false}` y no «sin publicaciones» (mismo criterio que la revisión I-1 del Hito G).

## 4. Qué verifiqué ejecutando (comando y resultado)

Entorno: PHP 8.3.33, PHPUnit 11.5.55, SQLite 3.53.4 (PDO), **PostgreSQL 14.24** desechable (`127.0.0.1:55432`, bases `ultron_studio_h5_unit_motor` y `ultron_studio_h5_motor` (pruebas), `ultron_studio_h5_motor_mut` (mutaciones) y `ultron_studio_h5_motor_base` (línea base); todas desechables, nunca la del `.env`). La máquina (8 GB) estuvo todo el tiempo paginando (4,8 GB de swap) por procesos ajenos; por eso las pasadas largas van en serie y fuera del árbol compartido (ver más abajo).

**Dónde.** Las pruebas de mis tres ficheros se corrieron en el árbol. Las pasadas largas, en una copia («arena») = `git archive f4d64b8` + mis siete ficheros + los dos del fixture del tester + `vendor` copiado + `.env.testing`, comprobada con `diff -rq` contra el árbol (`app config database routes tests bootstrap`): idéntica salvo los ficheros NUEVOS de B-S del paso 2 (`StudioPublicationDeclaration.php`, `StudioCanaryEvidence.php` y sus tres pruebas), que no son de este paquete y estaban a medio hacer. Motivo: otro agente corría PHPUnit en el mismo árbol y dos suites de Marketing a la vez se pisan el disco falso `whatsapp` (§6.5).

| Qué | Comando | Resultado |
|---|---|---|
| Mis tres ficheros, SQLite (árbol) | `php -d memory_limit=2G vendor/bin/phpunit tests/Unit/Marketing/UltronPublishedConfigTest.php tests/Feature/Marketing/UltronCommitStudioStampTest.php tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | **OK (253 tests, 915 assertions)** |
| Los mismos, PostgreSQL 14 (árbol) | `UNIT_DB=ultron_studio_h5_unit_motor docs/ultron-studio/ops/pgunit.sh <los tres>` | **OK (253 tests, 915 assertions)** (6 min 44 s) |
| Fixture del contrato | dentro de lo anterior: `signature` 24/24 y `scope` 63/63, en los dos motores | **87/87** |
| Firma frente al fixture, fuera de PHPUnit | `php -r` sobre `publication-cases.json` → `signatureFor`/`hash_equals` | 24 bien, 0 mal |
| La llave llega del entorno al commit | prueba desechable (scratchpad) con `MARKETING_ULTRON_STUDIO_CONFIG=all|canary|off`: un commit real | `all`/`canary` → sello `legacy` (cadena vacía); `off` → sin sello |
| Diff del motor | `git diff origin/main --stat -- app/Services/Marketing app/Jobs app/Http/Controllers/Api/Internal routes config/marketing.php` + `git status --porcelain` | `UltronCommitService.php` (M, +58/−9), `config/marketing.php` (M, +18) y `UltronPublishedConfig.php` (?? nuevo). Nada más |
| `auth:coverage --unmapped` | `php artisan auth:coverage --unmapped` | exit 0, salida vacía (0 bytes) |
| Rutas del Studio | `php artisan route:list --path=api/admin/ultron-studio --json` | 33 |
| Pint | `vendor/bin/pint --test` sobre mis seis ficheros PHP | passed |
| ULTRON, llave `off` (arena, SQLite) | `MARKETING_ULTRON_STUDIO_CONFIG=off php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Marketing tests/Unit/Marketing` | **OK (4977 tests, 21747 assertions)**, 0 omitidas |
| ULTRON, llave `all` (arena, SQLite) | lo mismo con `=all` (el sondeo previo mostró que la llave del entorno llega al commit y sella `legacy`) | **OK (4977 tests, 21747 assertions)**, 0 omitidas |
| Studio + Access + Chaos (arena, SQLite) | `phpunit tests/Feature/UltronStudio tests/Unit/UltronStudio tests/Feature/Access tests/Feature/Chaos` | 1212: 1 fallo + 17 omitidas («solo en PostgreSQL»). El fallo, `UltronStudioHardeningTest::test_una_lista_por_encima_de_su_tope…`, es su umbral de 50 ms: sola tarda **52,9 ms** con mi código y **52,4 ms en la base** `f4d64b8`, que también falla |
| Studio en PG (arena) | `UNIT_DB=ultron_studio_h5_motor docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio tests/Unit/UltronStudio` | 961: 2 fallos, el de los 50 ms y `UltronStudioImmutabilityTest` (sonda `PUBLISHED`, §6.1) |
| Marketing en PG (arena) | `… pgunit.sh tests/Feature/Marketing tests/Unit/Marketing` | 4977: 7 errores + 3 fallos (`LeadNeedsHumanLifecycleTest` ×5 y `PaymentLinkHardGateTest` ×1: datos de prueba no-uuid en columnas `uuid`, 22P02; `UltronCommitTest` ×2 y `UltronIncidentTest` ×1: arrays comparados en orden tras `jsonb`; `UltronPresenceTest` ×1: 25P02 con la llave en `off`, sin lector de por medio). **Los mismos 10, exactos, en la base `f4d64b8`** (`pgunit.sh` sobre esos cinco ficheros: 7 errores + 3 fallos, conjunto idéntico) |
| La unitaria con igualdad estricta frente al fixture | `pgunit.sh tests/Unit/Marketing/UltronPublishedConfigTest.php` y en SQLite | **OK (217 tests, 331 assertions)** en los dos |

**Pasadas con la versión anterior del lector** (antes de alinearlo con el fixture; el cableado del commit es el mismo): ULTRON `off` 4885/4885 en verde (2 min 8 s, sola); Studio + Access + Chaos 1212 OK (17 omitidas, todas «solo en PostgreSQL»); Studio en PG 961 con 1 fallo, el de §6.1. Las otras pasadas de entonces se corrieron en paralelo en el mismo árbol y dieron los rojos del disco `whatsapp` (§6.5); no cuentan como evidencia.

**Fallos de PG en Marketing que ya estaban:** en la línea base `f4d64b8` (sin mi código), los mismos 5 errores aparecen en las mismas posiciones (683–697) que en mi pasada de PG; nombrados y confirmados en la tabla de arriba.

### Mutaciones (en una tercera copia, nunca en el árbol; restauración verificada por sha256 en cada una)

| # | Mutación (cambio exacto) | Pruebas que la cazan | Resultado |
|---|---|---|---|
| **M1** (pedida) | Quitar el `try`/`catch` de `forConversation()` | `scope` del fixture «la tabla no existe» y «la consulta lanza otro error»; las cuatro «nunca lanza» de la unitaria; las averías «tabla inexistente» y «error de consulta» del commit | **cazada**: 6 errores + 2 fallos de 247 |
| **M2** (pedida) | Sellar solo las filas ejecutadas: `null` en lugar de `$studio` en `blocked()` (×2), hora de la visita, rendición y crítico fallido | `toda_fila_que_crea_un_commit_lleva_el_sello` (inelegible, relevo, crítico, rendición), `la_fila_de_la_hora_de_la_visita…` y la instantánea en esos cuatro caminos | **cazada**: 5 errores + 4 fallos de 30 |
| **M3** (pedida) | Leer con la llave en `off` (quitar el `return null` del paso 1) | la llave (`off`, vacía, `on`, `true`, nula, lista…), la matriz con `off`, los 5 `off` del fixture, `con_la_llave_en_off_ninguna_fila_lleva_sello`, A9.2 con `off` | **cazada**: 39 fallos de 247 |
| **M4** (pedida) | Recanonicalizar antes de firmar: `signatureFor(recanonicalizada($json))` (claves ordenadas, sin escapes) | `se_verifican_los_bytes_guardados_sin_recanonicalizar` y los dos «sangría» / «otro orden» del fixture, entre otros | **cazada**: 9 fallos de 217 |
| M5 | Sin punto de guardado: `return $leer();` siempre (en **PostgreSQL**) | «la conexión sigue viva» (×2), las averías del commit (×2: el commit muere con `25P02 current transaction is aborted`) y el nivel de transacción de A9.2 (×4) | **cazada**: 2 errores + 6 fallos de 247 |
| M6 | Traer `effective_json` entero (`addSelect('effective_json')`) | `una_declaracion_enorme_no_se_trae_entera` (y, en SQLite, las de `read_failed`) | **cazada**: 3 fallos de 217 |
| M7 | Subclave al revés (etiqueta como clave) | los 24 de firma del fixture, los vectores propios y todo lo firmado | **cazada**: 55 fallos de 217 |
| M8 | Caché entre lecturas (`static $memo` en `head()`) | «una entrada nueva se ve en la lectura siguiente», «dos commits seguidos…» y la mayoría de los casos con fila | **cazada**: 172 fallos de 247 |
| M9 | Sin atar `entry`/`seq` del texto a la fila | los dos «distinto de la fila» del fixture y los propios | **cazada**: 5 fallos de 217 |
| M10a | El lector nombra otra tabla del Studio | `UltronEngineIsolationTest` (rastreo, zonas, aserción propia) | **cazada**: 3 fallos de 6 |
| M10b | El lector escribe (`DB::table(…)->insert`) | `la_unica_lectura_del_motor_es_de_solo_lectura…` | **cazada**: 1 fallo de 6 |
| M10c | Otro fichero del motor nombra al lector | `…solo_la_usa_el_commit` | **cazada**: 1 fallo de 6 (fichero creado y borrado) |

## 5. Decisiones fuera de la letra del contrato y desviaciones (con motivo)

Alineadas con el fixture del contrato; ya **no son desviaciones**: la Enmienda 1 (`02d1cf5`) congeló todas las decisiones del README del fixture y decidió Q-1:

- **D-1 · La subclave** (Q-1/S-2 del fixture): `HMAC(clave = APP_KEY, mensaje = etiqueta)` (§3.1).
- **D-2 · `entry`/`seq` del sello son las columnas de la fila**, también en un `fallback` con fila (R-2/R-3). Mi primera versión no ponía nada de la fila en un `fallback` (por no copiar datos sin verificar al sello); en PostgreSQL son un `uuid` y un entero de la propia cadena, y nombrar la fila mala sirve para diagnosticar.
- **D-3 · Decodificación estricta = claves y tipos** (R-5): mi primera versión validaba además formatos (uuid, huella, `issuedAt`, enteros positivos, largos), más de lo que dice §A4.2; los quité. Lo que sí se exige además de claves y tipos: que el texto firmado hable de la misma fila (`contract`, `entry`, `seq`).

Mías, de robustez (no cambian ningún resultado con datos reales):

- **D-4 · Punto de guardado si hay una transacción abierta** (`head()` `:270`). En producción el commit no abre ninguna antes de leer (lectura simple, nivel 0, probado con una conexión aparte); en las pruebas (RefreshDatabase) y si algún día alguien envolviera el commit, una SELECT fallida en PostgreSQL abortaría la transacción entera (25P02) y el turno moriría. Con el punto de guardado se deshace solo la lectura. Es la mitigación de R1 del plan; la mutación M5 lo demuestra en PG. La prueba A9.2 comprueba además que el commit no abre ninguna transacción antes de leer (nivel de la prueba + 1, el del punto de guardado).
- **D-5 · `effective_json` acotado en la propia SELECT** (`substr(effective_json, 1, 16385)`, `:263`). Una fila enorme escrita a mano (el rol `iron_app` tiene DML) agotaría la memoria del proceso: eso es un error fatal que ningún `catch` atrapa y dejaría un turno mudo. Las declaraciones reales no se ven afectadas; una truncada sale `hash_mismatch`.
- **D-6 · `describe()` lee con cualquier llave y propaga los fallos** (§3.5).
- **D-7 · `persist()` con el sello como parámetro obligatorio** y no en una propiedad del servicio: un camino nuevo que cree filas tiene que decidir qué sella, y dos commits no pueden compartir estado.
- **D-8 · `entry` frente a la columna `uuid` sin distinguir mayúsculas** (el fixture dice «igual»): PostgreSQL normaliza el tipo `uuid` a minúsculas; es el mismo valor.
- **D-9 · Pint** convirtió una referencia de docblock en `use App\Services\Marketing\MarketingAgentSwitch;` en el lector (clase del motor, no del Studio; la frontera no cambia).
- **D-10 · La prueba A9.2** está en `UltronCommitStudioStampTest` (tiene el andamio de commits completos) y no en `UltronStudioEngineReadOnlyTest`, que prueba la dirección contraria (el Studio no escribe en el motor) y no necesitó cambios en 1c. El docblock de `UltronEngineIsolationTest` la referencia.
- **D-11 · `.env.example`:** además de la variable, una línea del bloque del Studio corregida (decía «El motor de ULTRON NO lee nada del Studio»).

## 6. Hallazgos para otros dueños (no tocados)

1. **B-S (paso 3) · `UltronStudioImmutabilityTest.php:178-181`** sondea `PUBLISHED` como estado prohibido; con `usv_status_chk` ampliado (`f4d64b8`) la base lo acepta y en PG falla («La base aceptó una escritura que tenía que rechazar»). Ya anotado por el lead en `decisiones-lead-h5.md`; lo reproduje en la pasada del Studio en PG (es su único fallo).
2. **B-S · `config/ultron_studio.php:9-11`** dice «Ninguna clase de app/Services/Marketing… consulta… las tablas `ultron_studio_*`»: deja de ser exacto con la llave encendida. Es de B-S.
3. **B-S · `StudioEngineReadings`:** `describe()` propaga fallos a propósito; tiene que ir dentro de `reading()`.
4. **B-S · evidencia (§A6):** `stamped` debe filtrar por `metadata->'studio'->>'state' = 'applied'` y `->>'version' = <uuid>`.
4b. **B-S · `UltronStudioEngineReadOnlyTest::ENGINE_READERS`** (`:79`) admite hoy solo tres ficheros del Studio que nombran clases del motor; los que llamen a `UltronPublishedConfig::signatureFor()`/`describe()` (publicar, la evidencia, `StudioEngineReadings`) tienen que entrar en esa lista en el paso 3. Y `UltronEngineIsolationTest::ENGINE_READER_NAMERS` solo mira FUERA del Studio: llamar al lector desde `app/Services/UltronStudio/**` o `app/Console/Commands/UltronStudio/**` no la rompe.
5. **Quien corra las suites:** dos suites de Marketing a la vez en el MISMO árbol se pisan el disco falso `whatsapp` (`storage/framework/testing/disks/whatsapp`, que `Storage::fake` borra al empezar): me salieron `AttachmentAccessTest`, `OutboundAttachmentTest` y `MediaRetentionTest` rojas por eso, y verdes de una en una (§4). Con varios agentes en este árbol, conviene correrlas en una copia aparte.
6. **Tester · casos límite conocidos desde dentro:** commit con la tabla borrada a mitad (PG, punto de guardado); `effective_json` > 16 384 caracteres; en SQLite un identificador entre comillas dobles que no existe se lee como cadena literal (un `select "signature"` sin la columna no da error: da `signature_invalid`); cabeza insertada entre la lectura y `persist()` (el sello refleja la cabeza del momento de la lectura, por diseño); `seq` fuera de orden de inserción (manda `max(seq)`); llave `' ALL '` → `all`, `'true'` → `off`.
7. **Security / api-security:** la rotación de la `APP_KEY` deja todas las firmas en `fallback/signature_invalid` (R6 del plan; no cambia nada más); el log del `fallback` lleva motivo y clase del error, nunca el mensaje (probado); el id del canario no sale de `describe()` (probado).

## 7. Qué NO verifiqué

- Producción (nada desplegado), el E2E con la pila real y el p95 del commit con la llave en `off` y en `all` (A4.7): del tester.
- La rama de MySQL (42S02) del criterio de tabla inexistente: el proyecto no usa MySQL.
- El camino sin transacción (nivel 0) solo en SQLite (conexión aparte); en PG la lectura a nivel 0 es una SELECT en autocommit, sin nada que abortar.
- Una publicación insertada a la vez que un commit (entre la lectura y `persist()`): por diseño el sello refleja la cabeza del momento de la lectura; no lo probé con concurrencia real.
- Las pasadas largas se hicieron en la arena (`f4d64b8` + este paquete + el fixture), no en el árbol compartido, que además tiene los ficheros a medio hacer de B-S del paso 2 (§4).
