# Hito 5 · paso 1b · informe del tester: el fixture de publicación

**Fecha:** 24-10-2026 noche (Bogotá), 25-10 UTC.
**Árbol:** `_worktrees/thermal-receipts/Iron-Body/backend`, rama `feat/ultron-studio-h5`. Al empezar estaba en `0366009` y limpio; durante el trabajo entraron `3735b58` y `f4d64b8`.
**Contrato:** `ULTRON_STUDIO_H5_CONTRACT.md` §A1, §A4, §A5.1–A5.4, §A6 y §A12 («Núcleo»).
**Sin commit:** los tres ficheros quedan sin seguimiento en el árbol.
**Producción:** nada tocado.

## 0. Resumen

- **Entregado:**
  - `tests/fixtures/ultron-studio/publication-cases.json`: 231 casos, 382 KB.
  - `publication-cases.README.md`.
  - Este informe.
- **Recuento:** `classify` 34 · `effectiveJson` 21 (11 de bytes + 10 `compose`) · `signature` 24 (7 `sign` + 17 `verify`) · `scope` 63 · `gates` 71 (45 `publish` + 13 `promote` + 9 `rollback` + 4 `legacy`) · `evidence` 18. Todos los mínimos del encargo quedan superados.
- **Cómo se escribió.** Todo `expected` es un literal escrito a mano desde el contrato. Python solo serializa y calcula sha256/HMAC con `hashlib`/`hmac` sobre bytes escritos a mano. Nada se derivó del lector del motor, que se está escribiendo en paralelo en este mismo árbol (§5); no lo he leído ni ejecutado.
- **Verificación (§2):**
  - una segunda derivación independiente coincide en los 231 casos;
  - openssl y las primitivas de PHP rehacen todas las huellas y firmas;
  - el `canonicalJson` real reproduce los bytes;
  - el catálogo transcrito es el real;
  - las **29** implementaciones equivocadas simuladas caen todas en al menos un caso;
  - `tests/Unit/UltronStudio` sigue en verde (706 pruebas).
- **Decidido aquí.** Las decisiones que el contrato no fijaba van en el README, por secciones: C-1…C-10, E-1…E-5, S-1…S-4, R-1…R-7, P-1…P-10 y V-1…V-10, cada una con los casos que la fijan. **Hay que congelarlas** con una enmienda, como en G y H4.
- **Cinco preguntas para el lead** (§3). La que más pesa es **Q-1**: el orden de los argumentos de la subclave. Cambia todas las firmas del fichero y es donde más fácil es que el lector en curso (B-M) diverja.

## 1. Qué se entrega y dónde

| Fichero | Qué |
|---|---|
| `tests/fixtures/ultron-studio/publication-cases.json` | El fixture: `version`, `catalogVersion`, `contract`, `source`, `catalog` (la transcripción del catálogo real) y las seis secciones |
| `tests/fixtures/ultron-studio/publication-cases.README.md` | Forma, cómo se compara, las decisiones con sus casos, lo que no cubre y las preguntas |
| `docs/ultron-studio/plans/informes-h5/informe-fixture.md` | Este informe |

**Desviación de ruta, a propósito.** El plan (§3.1 y §7) y el encargo decían `tests/Fixtures/UltronStudio/`. La suite guarda todos sus fixtures del Studio en `tests/fixtures/ultron-studio/`, que es la convención de F, G y H4. Además, en el disco de macOS, que no distingue mayúsculas, aquella ruta habría caído en `tests/fixtures/UltronStudio/`, un tercer directorio en Linux. **Ningún test lo consume todavía**: lo leerán `StudioPublicationDeclarationTest` (B-S, paso 2) y `UltronPublishedConfigTest` (B-M, paso 1c).

## 2. Verificación (salida real)

### 2.1 JSON y forma

```
$ python3 -m json.tool tests/fixtures/ultron-studio/publication-cases.json > /dev/null   → OK
bytes 381977 · termina en \n · ningún «{}» · 0 caracteres invisibles literales · nombres únicos (231)
```

### 2.2 Segunda derivación independiente

- `check_fixture.py` rehace cada `expected` desde su entrada, con las reglas del README, en código escrito aparte. Comprueba también la coherencia de la evidencia: `byState` reparte los turnos y el total del acta es la suma.
- Usa **openssl 3.6.4** para todas las huellas y firmas.

```
casos: {'classify': 34, 'effectiveJson': 21, 'signature': 24, 'scope': 63, 'gates': 71, 'evidence': 18} total 231
discrepancias: 0
```

### 2.3 Contra las primitivas y las clases reales de PHP 8.3.33

`php_crosscheck.php` carga **solo** `StudioContentHasher.php` y `StudioCatalog.php` del árbol (no el `App\` de otro repo) y comprueba:

- el catálogo transcrito es igual a `StudioCatalog::bindings()` (21 parámetros, en orden de capa y parámetro) y a `baseline()['configs']`, con la versión `2026-10-19.1`;
- `canonicalJson` de la entrada **sin ordenar** da los bytes de los 11 casos `effectiveJson`, incluido el de las claves desordenadas;
- `canonicalJson` da los bytes de las 18 evidencias, y `hash` sus huellas;
- `hash_hmac`, `hash_equals` y `base64_decode` dan las 7 firmas `sign` y los 17 veredictos `verify`;
- las 58 filas de `scope` tienen la huella y la firma válidas o inválidas que su caso espera.

```
{"catalog":21,"effectiveJson":11,"evidence":18,"sign":7,"verify":17,"scopeHash":58}
fallos: 0
```

### 2.4 Un vector a mano, con openssl y shasum (reproducible)

```
APP_KEY decodificada: 00…00 (32 bytes)
subclave:   d8740e4e2cda80dbc14de4eed54183985b8d54c8675f88a18e4d84d210572564   = signature[0].expected.subkeyHex
firma:      9a11776c66eed69538eb7739d23f6a1ed2a535842e2492c2197d7f38e57b9632   = signature[0].expected.signature
sha256:     5373bde9f33d2d70c5c309393622dbf931e625a329df56f8b46d1932a7e9e3fe   = effectiveJson[0].expected.effectiveHash
```

Comandos:
- subclave: `printf 'ultron-studio-publication' | openssl dgst -sha256 -mac HMAC -macopt hexkey:<APP_KEY en hex>`;
- firma: `{ printf 'ultron-engine-config/1\n'; printf '%s' "$J"; } | openssl dgst -sha256 -mac HMAC -macopt hexkey:<subclave>`.

### 2.5 ¿El fixture discrimina? 29 implementaciones equivocadas

Cada variante es un error plausible. La caza un caso cuando su `expected` deja de cumplirse con ese error.

| Variante | Casos que la cazan |
|---|---|
| APP_KEY como cadena «base64:…» sin decodificar (el patrón de `UltronDecideToken`) | 51 |
| Subclave con los argumentos al revés | 53 |
| Sin traducir `fake` → `rules` | 3 |
| `entorno` da por buena una lectura caída | 2 |
| Listas comparadas como conjuntos | 1 |
| `offerPriority` comparada con el motor | 33 |
| `S-NNN` en el orden de entrada | 2 |
| `mismatches` en el orden del texto de A1 | 2 |
| `issuedAt` redondeado / sin convertir a UTC | 1 / 3 |
| La barra escapada (`\/`) / sin ordenar claves | 11 / 11 |
| Firmas comparadas sin distinguir mayúsculas | 1 |
| Lector laxo con la forma | 15 |
| Recanonicalizar antes de la huella | 2 |
| La firma antes que la huella | 1 |
| Sin canario configurado = «todos son canarios» | 6 |
| En la canaria, sin pasar a `rest` si `canary` es null | 2 |
| Ventana en UTC / con el final incluido | 6 / 3 |
| Lectura caída antes que el mismatch (paso 8) | 1 |
| Reconocimiento como lista | 1 |
| Revertir exige las llaves | 10 |
| Horas redondeadas / horas dentro de la huella | 1 / 18 |
| `entry`/`seq` del sello sacados del texto / `fallback` sin ellos | 3 / 27 |
| Legacy admitido sin ninguna fila | 1 |
| Cero turnos también suspenden `stamped` | 1 |

**29 de 29 cazadas.** Las que solo caza un caso lo hacen a propósito: un caso, un motivo de fallo.

### 2.6 Regresión

```
$ php vendor/bin/phpunit tests/Unit/UltronStudio
OK (706 tests, 9419 assertions)   — 03:31
```

- No corrí la suite Feature ni la de PG. Mis ficheros son datos que ningún test lee: no pueden cambiarlas.
- En el árbol hay trabajo ajeno sin commit (§5) que sí las mueve, y lo que saliera no hablaría de este paquete.

## 3. Preguntas para el lead

1. **Q-1 · Subclave (README S-2).** El fixture fija `k = hash_hmac('sha256', 'ultron-studio-publication', $appKey, true)`: la APP_KEY es la clave y la etiqueta el mensaje, en 32 bytes crudos.
   - Es la lectura de A4.1 en el orden de `hash_hmac` y la derivación habitual.
   - Leída en el mismo orden (clave, mensaje) que la fórmula de la firma, sale lo contrario.
   - Si el lead elige la otra lectura, cambian:
     - `subkeyHex`;
     - toda firma calculada de `signature`;
     - la firma de las 58 filas de `scope`.

     El caso «… argumentos al revés …» pasa a ser el válido y el del orden actual, el inválido. Lo regenero en minutos.
   - **Conviene decidirlo antes de que B-M cierre el paso 1c.**
2. **Q-2 · Revertir la `PUBLISHED` con otra versión en `CANARY`.**
   - A5.3 escribe `{R, R}` o `{null, null}` y borra la parte canaria de una versión que sigue en `CANARY`: rompe el invariante de A2.
   - Recomiendo **(a)** conservarla, `{canary: B, rest: R}`. Revertir no debería bloquearse por otro estado. Las alternativas son (b) rechazar y (c) revertir también el canario.
   - El fixture no lo afirma.
3. **Q-3 · Al promover, ¿`publishedBy` es el de la publicación o el de quien promueve?**
   - A4.1 pide la identidad de quien publica dentro del texto firmado, y `{canary: B, rest: B}` sugiere la misma parte. Dan bytes distintos.
   - `compose` compara solo versiones hasta que se decida.
4. **Q-4 · Qué entra en `previewHash`.**
   - A12 lo pide «estable», pero el contrato no da su entrada.
   - Propuesta en el README: identidades más la clasificación y el laboratorio, sin la hora.
   - Hasta entonces, las puertas solo comparan `sent` con `current`.
5. **Q-5 · Una lectura caída en `drift`.** Propuesta: cuenta como deriva con `engine: null`, falla cerrado, igual que C-6 al publicar.

## 4. Observaciones (no son del fixture, pero salieron al escribirlo)

- **Callejón sin salida al abrir el tráfico.** Sin canario del motor, publicar es siempre `engine_has_no_canary` (A5.1, paso 10), y una versión con `canaryOnly: true` choca en `must_match`. Tras quitar el canario no se puede publicar nada. Hoy está fuera de alcance; hay que confirmarlo como intencionado antes del hito que abra el tráfico.
- **`turns` cuenta filas, no turnos** (A6: «Filas de `marketing_ai_actions`…»). El commit crea filas desde cuatro sitios (`UltronCommitService.php:1236`, `:2018`, `:2259`, `:3851`). No he comprobado si un mismo commit puede crear más de una. Si puede, el umbral de 3 se alcanza con menos turnos reales. Lo debe mirar B-S al contar.
- **La conversación «otra» con el canario configurado nunca llega al lector desde el commit**, que antes lanza 403 (`UltronCommitService.php:290-302`). Los casos de `scope` con esa combinación fijan el algoritmo del lector por sí mismo.
- **Para el arnés de `scope`:**
  - una fila por caso, dentro de la transacción del caso: los disparadores de solo inserción impiden el `DELETE` y los `uuid`/`seq` se repiten entre casos;
  - los dos casos con `engine_contract = …/2` no entran en PG por su CHECK: van en SQLite o por un resolutor puro.

## 5. Trabajo en paralelo en este árbol (contexto para el lead)

Mientras escribía, el árbol cambió por otros dueños:
- entraron `3735b58` (docs) y `f4d64b8` (migraciones, con `informe-db.md`);
- aparecieron sin commit `UltronPublishedConfig.php` (nuevo), `UltronCommitService.php`, `config/marketing.php`, `.env.example`, `UltronEngineIsolationTest.php`, `UltronPublishedConfigTest.php`, `UltronCommitStudioStampTest.php`, `decisiones-lead-h5.md`, `informe-e2e-arnes.md` e `informe-motor.md`.

No toqué ninguno.

**Recomendación:** pasar este fixture a B-M **ya**. Que `UltronPublishedConfigTest` lo consuma (`signature` y `scope`) antes de cerrar el paso 1c. Los puntos donde es más probable divergir sin haberlo leído:
- S-1 y S-2: la decodificación de la APP_KEY y el orden de la subclave;
- R-1: un valor basura de la llave en el propio lector;
- R-3: `entry`/`seq` de la fila, también en `fallback`;
- R-5: los tipos exactos, y `contract` del texto igual a la columna;
- R-6: no recanonicalizar.

Si el lector discrepa, se corrige el lector o se enmienda el contrato; el fixture no se dobla.

## 6. Lo que queda fuera, y por qué

Está en el README («Lo que no cubre»). En corto:
- **No es núcleo puro:** 422, idempotencia guardada, libro, transacciones, carreras, disparadores, el sello en cada fila del commit, el «nunca lanza» en PG, el recuento de la evidencia en la base y el acta con `Artisan::call`. Va en Feature, PG y el E2E de A12.
- **Sin base en el contrato:** `previewHash`, `drift` con lecturas caídas, Q-2 y Q-3.
- **Ambigüedades evitadas a propósito:**
  - claves repetidas en `effective_json`;
  - el formato de `issuedAt` en el lector;
  - ventanas que cruzan la medianoche;
  - ids de conversación como texto.

## 7. Artefactos auxiliares (fuera del repo)

Los tres scripts están en el scratchpad de la sesión:
- `build_fixture.py`: los literales y su serialización;
- `check_fixture.py`: la segunda derivación y las variantes;
- `php_crosscheck.php`.

Ruta: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY-Iron-Body-Crm/e4424958-6ddc-4278-a1c5-20a44f3dc490/scratchpad/h5fixture/`.

**Se purgan en tres días** (memoria «El scratchpad de /tmp se purga»). El encargo limitaba los ficheros a estos tres, así que no los subí al repo. Si el lead quiere conservarlos para regenerar las firmas tras Q-1, o para enmiendas, van a `docs/ultron-studio/ops/` con una orden.
