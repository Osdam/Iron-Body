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

## Paso 3 cerrado: la API de la publicación (25-10)
- Confirmado en dos commits: m-3 aparte (`5df1b1d`, umbral de desatención por carril) y el paquete B-S (informe `informe-api.md`). Verificación del lead, de primera mano: SQLite (Studio, Access, Chaos) 1654 pruebas con 18 omitidas de solo PG; PostgreSQL 14 1403 OK; `auth:coverage --unmapped` vacío; 38 rutas; `git diff origin/main` del motor limitado a los 3 ficheros del bloque 1.
- **D-1 aceptada:** código 409 nuevo `publication_head_invalid` (la cabeza de la cadena no verifica; la salida es Legacy). `publication_moved` mentiría (recargar no arregla una cabeza rota). **Para el CRM:** añadirlo a `CODIGOS_DEL_SERVIDOR` y a `StudioErrorCode` con su texto; va a la Enmienda 2 del contrato (A7).
- **D-2 a D-5 aceptadas:** `acknowledged` = `{noEffect, previewHash}`; `lab_gate` = `{"mode": null}` fuera de publicar; la nota de publicar y promover en `reason`; `governance.studioPublication` como extra de las lecturas, fuera del catálogo (el fixture fija `2026-10-19.1`); su descriptor vive en el CRM. D-6 a D-18 son de implementación y quedan documentadas en el informe; las que tocan el contrato (D-6 rechazos auditados, D-7 `blocking`, D-9, D-13, D-18 ventana) van a la Enmienda 2.
- `docs/ultron-studio/plans/informes-h5/export/` **no se confirma**: son los 5 ficheros que regenera `php artisan ultron-studio:export-fixtures` y la copia que cuenta es la del CRM (`src/app/modules/ultron-studio/testing/`). Pendiente del front: copiar `backend-publication.testing.ts` y fijar la paridad de sus lectores con él.
- `capturas-crm/` es del informe del paso 4 (CRM) y se confirma con él.
- El fallo de Pint en `app/Providers/AppServiceProvider.php` (4 fixers) ya estaba en HEAD; fuera de alcance.
- Para el script de la fase 1: además de las tres llaves, se escriben explícitas `ULTRON_STUDIO_CANARY_MIN_HOURS=24`, `ULTRON_STUDIO_CANARY_MIN_TURNS=3`, `ULTRON_STUDIO_PUBLISH_WINDOW=07:00-19:00` y `QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS=300` (todas con su defecto en el código; se escriben para que la configuración real quede a la vista).
