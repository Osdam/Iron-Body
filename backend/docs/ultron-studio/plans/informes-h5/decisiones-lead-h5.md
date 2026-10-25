# Hito 5 · decisiones del lead durante la implementación (para las enmiendas del contrato y los informes)

Vive en el repo a propósito: el scratchpad de `/tmp` se purga a los tres días.

## Bloque 1 — migraciones (informe-db.md), 25-10
- Commit `f4d64b8`. Recuentos del Studio en PG: 27 → **30 disparadores**, 24 → **27 CHECK**; SQLite 18 → 20 disparadores. `ultron_studio_forbid()` redeclarada con el mismo cuerpo que F (huella md5 igual).
- **Pendiente para el paquete B-S (paso 3):** `tests/Feature/UltronStudio/UltronStudioImmutabilityTest.php:178-181` usa `PUBLISHED` como estado inválido (esperaba 23514); H5 lo admite → cambiar la sonda a `VALIDATING` o a un estado inventado. En SQLite esa prueba se omite; en PG hoy falla por diseño hasta ese cambio.
- `UltronEngineIsolationTest` cae por las dos migraciones nuevas hasta que B-M (paso 1c) las añada a la lista blanca (mismo commit que el lector).
- `AdminUserPasswordTest` ya fallaba en la base en PG: ajeno al Studio (lo verá la comparación de suites del mismo día).
