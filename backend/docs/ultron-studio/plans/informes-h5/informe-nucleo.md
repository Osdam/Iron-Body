# Hito 5 · paso 2 · informe del paquete B-S: núcleo puro

**Fecha:** 24-10-2026 noche (Bogotá). **Árbol:** `_worktrees/thermal-receipts/Iron-Body/backend`, rama `feat/ultron-studio-h5` (HEAD `02d1cf5`). **Sin commit.** No toqué el motor, `StudioPublishing`, `StudioVersions`, controladores, rutas, migraciones ni el fixture. El trabajo sin commit de B-M que ya estaba en el árbol sigue intacto.

## 1. Ficheros (todos nuevos)

| Fichero | Qué |
|---|---|
| `app/Services/UltronStudio/StudioPublicationDeclaration.php` | Clasificación (A1), partes y bytes de `effective_json` (A4.2), `compose` (A4.2, A5.2–A5.4, Q-2 y Q-3), firma por inyección, decodificación de la cabeza, `previewHash` (Q-4), reconocimiento exacto, pasos 8 y 9 de publicar y deriva (Q-5) |
| `app/Services/UltronStudio/StudioCanaryEvidence.php` | La parte pura de A6: horas, `failing`, `passes`, `evidenceHash`, y las dos puertas de la evidencia al promover. El docblock dice que las consultas las añade el paso 3 |
| `tests/Unit/UltronStudio/StudioPublicationParityTest.php` | Paridad con el fixture, 106 pruebas |
| `tests/Unit/UltronStudio/StudioPublicationDeclarationTest.php` | Comportamiento fuera del fixture |
| `tests/Unit/UltronStudio/StudioCanaryEvidenceTest.php` | Ídem para la evidencia |

## 2. Firmas para el paso 3

```php
// StudioPublicationDeclaration (final, estático, puro). Constantes: CONTRACT, MAX_EFFECTIVE_JSON, MUST_MATCH (id → tipo),
// ACTION_{PUBLISH,PROMOTE,ROLLBACK,LEGACY}, RULE_PREFIX, LAB_REQUIRED/LAB_WAIVED, LAB_LATEST, CODE_* de los pasos 8 y 9.
classify(array $configs, array $rules, array $baseline, array $readings, array $catalog): array   // {mustMatch, matches, noEffect, mismatches, engineReadingUnavailable}
requiredAcknowledgements(array $classification): list<string>
acknowledges(array $classification, array $acknowledgedNoEffect): bool                            // conjunto
refusal(array $classification, array $ack, string $sentPreviewHash, string $currentPreviewHash): ?array{code, payload}   // pasos 8–9; payload null en preview_stale
previewHash(string $versionUuid, string $contentHash, string $catalogVersion, array $classification, array{mode,latest} $labGate): string
drift(array $declared, array $readings, array $catalog): list<{param, declared, engine}>         // lectura caída → engine null
declared(array $configs): array                                                                    // los 4 must_match
part(string $version, string $contentHash, string $catalogVersion, int $approvedBy, int $publishedBy, array $declared): array
compose(string $action, ?string $version, ?string $versionStatus, ?array $head, ?array $part = null, ?array $restores = null): array{canary, rest}
effectiveJson(string $entry, int $seq, DateTimeInterface $issuedAt, ?array $canary, ?array $rest): string
effectiveHash(string $json): string
declaration(string $entry, int $seq, DateTimeInterface $issuedAt, ?array $canary, ?array $rest, Closure $sign): array{contract, effectiveJson, effectiveHash, signature}
parts(string $effectiveJson, string $entry, int $seq): array{canary, rest}                        // la cabeza ya escrita, estricta
utc(DateTimeInterface $instant): string                                                           // AAAA-MM-DDTHH:MM:SSZ, truncado

// StudioCanaryEvidence (final, estático, puro). Constantes: MEASURES, GATES, BY_STATE (10), MIN/MAX_HOURS, MIN/MAX_TURNS, CODE_INSUFFICIENT, CODE_CHANGED.
evaluate(array $measures, array $thresholds, DateTimeInterface $now): array{hours, passes, failing, evidenceHash}
evidence(array $measures, array $thresholds): array        // el objeto de V-8 normalizado: lo que va a la columna `evidence`
evidenceJson(array $measures, array $thresholds): string
hours(DateTimeInterface|string $since, DateTimeInterface $now): int
refusal(array $evaluation, string $sentEvidenceHash): ?array{code, payload}   // insuficiente → cambiada; payload null
```

**Cómo se encadena al publicar:**
1. Clasificar: `classify($v->content['configs'], $v->content['rules'], StudioCatalog::baseline()['configs'], $lecturas['params'], StudioCatalog::toArray())`.
2. Pasos 8 y 9: `refusal($clasif, $ack, $sent, previewHash(...))`.
3. Componer: `compose('publish', $v->uuid, $v->status, parts($cabeza->effective_json, $cabeza->uuid, $cabeza->seq) o null, part($v->uuid, $v->content_hash, $v->catalog_version, $aprobó, $publica, declared($configs)))`.
4. Firmar: `declaration($entrada, $seq, now(), $p['canary'], $p['rest'], UltronPublishedConfig::signatureFor(...))`.

## 3. Decisiones fuera de la letra del contrato (con motivo)

- **D-1 · La firma entra como `Closure`.** No hay interfaz nueva. Si el núcleo nombrara `UltronPublishedConfig` en código, `UltronStudioEngineReadOnlyTest` (lista `ENGINE_READERS`) caería. El docblock la nombra solo en comentarios. `declaration()` rechaza con `UnexpectedValueException` una firma que no sean 64 hexadecimales en minúscula.
- **D-2 · `evaluate()` recibe `$now`.** El esbozo del encargo era `evaluate(counts, thresholds)`. Pero las horas deciden (V-1) y la clase no puede leer el reloj. También devuelve `hours`, que es lo que enseña el DTO.
- **D-3 · Validación al escribir más estricta que la del lector.** Uuid en minúscula, huella de 64 hexadecimales en minúscula, ids ≥ 1, textos cortos de 1 a 32 caracteres ASCII visibles y el tope de 16 384. Así nada de lo que escribe el Studio puede salir `malformed`.
  - La entrada más larga posible queda por debajo de 2 048 caracteres (hay una prueba que lo mide).
  - Las 26 cabezas válidas de `scope` cumplen estas reglas.
- **D-4 · Formas inesperadas → `InvalidArgumentException`, nunca un valor por defecto.** Aplica a configs con parámetros o capas de más o de menos, reglas sin código, con otro prefijo o repetidas, y a un catálogo cuyos `env_flag` no sean los cuatro de `declared` en su orden.
  - **Excepción:** una lectura sin la forma de `StudioEngineReadings` cuenta como caída. Así falla cerrado y explica el 409, en vez de dar un 500.
- **D-5 · `compose` comprueba la invariante de A2.** No compone si:
  - publicar borraría un canario en curso;
  - se promueve o revierte una versión que no es la de la cabeza;
  - se restaura la misma versión;
  - Legacy viene con una versión.
  
  «Canario en curso» = la parte canaria de la cabeza es distinta de la del resto.
- **D-6 · Evidencia incoherente → excepción.** Es el caso de `byState` que no suma `turns`, de `stamped > applied` y de un total del acta distinto de la suma de sus recuentos. **El paso 3 debe sacar `turns` y `byState` de una sola consulta**, porque si no, una carrera daría un 500.
- **D-7 · Un reloj por detrás de `since` da `hours: 0`.** No da negativo, y la puerta falla igual.
- **D-8 · `previewHash` recibe el `catalogVersion` de la versión,** que es el mismo que lleva su parte. El del catálogo actual ya está dentro de las listas. **El paso 3 tiene que pasar eso**, porque Q-4 no lo precisaba.
- **D-9 · `parts()` existe** para que `compose` lea la cabeza en estricto. No verifica la huella ni la firma: **el paso 3 tiene que verificarlas antes** (con `describe()` o con `signatureFor`). El contrato no dice qué hacer al publicar, promover o revertir sobre una cabeza alterada. **Lo decide el lead.**

## 4. Paridad, por sección (igualdad estricta)

| Sección | Resultado |
|---|---|
| Catálogo transcrito = `StudioCatalog::bindings()` / `baseline()['configs']`; versión y recuentos | OK |
| `classify` | 34/34 |
| `effectiveJson` bytes + huella + la firma recibe esos bytes + vuelta por `parts()` | 11/11 |
| `compose` | 10/10 |
| `evidence` (horas, `passes`, `failing`, `evidenceJson`, `evidenceHash`) | 18/18 |
| `gates`, parte pura | 30/30. Son 25 de publicar y 5 de promover; las 41 restantes son de puertas anteriores o de rollback/legacy, del paso 3. Una prueba comprueba que cada código cae antes de la parte pura, en ella o después |
| Cruce con las cabezas de `scope` | `parts()` lee las 26 que el lector aplica o resuelve, y rechaza las 16 `malformed` |

**El fixture me parece coherente: no encontré nada que discutir.**

## 5. Lo que verifiqué ejecutando

- **Línea base antes de tocar nada:** `php -d memory_limit=2G vendor/bin/phpunit tests/Unit/UltronStudio` → `OK (706 tests, 9419 assertions)`.
- **Mis tres pruebas:** → `OK (236 tests, 904 assertions)`.
- **`php -l`** de los 5 ficheros: sin errores.
- **`vendor/bin/pint --test`** de los 5: `passed`.
- **`UltronStudioEngineReadOnlyTest`** (las dos pruebas estáticas por tokens): `OK (2 tests, 100 assertions)`.
- **Mutaciones:** 53 de 53 cazadas, y el control sin cambios en verde.
  - Cada mutante se cargó desde una copia en el scratchpad, con un bootstrap propio; el árbol compartido no se tocó (comprobado con sha256).
  - En la primera pasada sobrevivió uno (byState sin la suma). El caso de prueba estaba enmascarado y lo corregí.
  - Arnés: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY-Iron-Body-Crm/caa290a5-d8e2-4481-b0b8-f30f8bbc87ab/scratchpad/h5nucleo/mutate.php`, con la salida en `mutantes.txt` en la misma carpeta. Se purga en 3 días.

## 6. Lo que NO verifiqué

- **La suite completa `tests/Unit/UltronStudio` con mis ficheros.** La pasada que vale es la de la línea base de antes; después solo ejecuté mis tres pruebas. Mis cambios son ficheros nuevos y no modifican ninguno existente, pero **falta esa pasada**.
- **La ida y vuelta escritor → lector real**, es decir, una fila escrita con `declaration()` y leída con `UltronPublishedConfig::forConversation()`: necesita base de datos. Es del paso 3 o del tester.
- **Las puertas no puras:** 1–7 y 10–12 de publicar, promover, rollback y legacy.

## 7. Para el tester (casos límite desde dentro)

- `turns` y `byState` en la misma consulta (D-6).
- La cabeza alterada al componer (D-9).
- Revertir la publicada con un canario en curso: `{B, R}` (Q-2).
- Que un `fake` crudo en las lecturas no cambie la huella de la vista previa frente a `rules`.
