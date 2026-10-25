# Hito 5 · decisiones del lead durante la implementación (para las enmiendas del contrato y los informes)

Vive en el repo a propósito: el scratchpad de `/tmp` se purga a los tres días.

## Bloque 1 — migraciones (informe-db.md), 25-10
- Commit `f4d64b8`. Recuentos del Studio en PG: 27 → **30 disparadores**, 24 → **27 CHECK**; SQLite 18 → 20 disparadores. `ultron_studio_forbid()` redeclarada con el mismo cuerpo que F (huella md5 igual).
- **Pendiente para el paquete B-S (paso 3):** `tests/Feature/UltronStudio/UltronStudioImmutabilityTest.php:178-181` usa `PUBLISHED` como estado inválido (esperaba 23514); H5 lo admite → cambiar la sonda a `VALIDATING` o a un estado inventado. En SQLite esa prueba se omite; en PG hoy falla por diseño hasta ese cambio.
- `UltronEngineIsolationTest` cae por las dos migraciones nuevas hasta que B-M (paso 1c) las añada a la lista blanca (mismo commit que el lector).
- `AdminUserPasswordTest` ya fallaba en la base en PG: ajeno al Studio (lo verá la comparación de suites del mismo día).

## Bloque 1 cerrado (25-10, madrugada)
- Motor (B-M): lector `UltronPublishedConfig` + sello en `UltronCommitService::persist()` + `marketing.ultron.studio_config`; 87/87 casos `signature`/`scope` del fixture; ULTRON 4977/4977 con `off` y con `all`; 12 mutaciones; punto de guardado para no dejar abortada la transacción en PG (25P02); `effective_json` leído con tope de 16 385 caracteres. Para B-S: el sello en PG es `jsonb` (leer por clave), `ENGINE_READERS` debe admitir los ficheros del Studio que llamen a `signatureFor()`/`describe()`, y `describe()` lanza a propósito (envolver en `reading()`).
- Núcleo (paso 2): `StudioPublicationDeclaration` y `StudioCanaryEvidence` (parte pura); paridad 34/34, 11/11, 10/10, 18/18, 30/30; 53 mutaciones. Para B-S: verificar la cabeza (huella y firma) antes de componer; `turns` y `byState` de UNA consulta; `catalogVersion` de la versión en `previewHash`.
- CRM (1d): capa de datos `9b70337` (801 specs en máquina libre); arnés E2E `3989f90` (130/130 contra `0366009`+`5c1dbd7`; pendientes del tester: ejecutar `mutations.sh`, probar la caché de la instantánea del CRM, 16 tablas reales en `specs/lib.cjs`, orden 409/403 del veredicto en `fake`).
- Ajustes del lead en pruebas de F/G: sonda de inmutabilidad `PUBLISHED` → `VALIDATING` (el CHECK de H5 admite 9 estados); umbral de `UltronStudioHardeningTest` 50 → 150 ms (fallaba también sobre la base, 52 ms; la vía lenta tardaba segundos).
