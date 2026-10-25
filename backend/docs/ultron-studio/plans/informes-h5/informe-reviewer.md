# Hito 5 · informe del reviewer (PARCIAL: revisión interrumpida por límite de uso)

**Fecha:** 25-10-2026. **Backend:** `fix/h5-lead` @ `5772bdf`, diff `0366009..HEAD`. **CRM:** `feat/ultron-studio-h5` @ `a29bcc8` (NO revisado).
Solo lectura; este es el único fichero escrito. Sin commit ni push.

## Veredicto

**SIN VEREDICTO FINAL.** La revisión se cortó por el límite de uso antes de cubrir los criterios 6–9.
- Criterios 1–5 (backend): revisados, **sin bloqueantes ni importantes**; dos menores y notas.
- Criterios 6 (CRM), 7 (scripts de producción), 8 (documentación) y 9 (regresiones): **NO revisados**. No se puede aprobar la fase 1 sin ellos (el 7 es el que más pesa para la fase 1: es lo que se ejecuta en producción).
- Fases 2–3: sin veredicto (dependen además de las carreras de PG del tester, que no están en este árbol).

## Verificado (ejecutado aquí, salida real)

| Comando (desde `backend/` del worktree) | Resultado |
|---|---|
| `php -r` con `ReflectionClass` sobre `UltronPublishedConfig` | carga el código de ESTE worktree (vendor propio, no enlazado) |
| `phpunit UltronStudioPublicationConfigTest UltronEngineIsolationTest` (SQLite) | OK 24/24, 416 aserciones |
| `phpunit StudioPublicationParityTest StudioPublicationGatesParityTest StudioPublicationDeclarationTest StudioCanaryEvidenceTest UltronPublishedConfigTest` (SQLite) | OK 525/525, 1428 aserciones |
| `phpunit --display-skipped` Lifecycle, Rollback, Canary, Access, Schema, EngineReadOnly, `UltronCommitStudioStampTest`, Hardening, Immutability (SQLite) | OK 181, 3001 aserciones, 4 omitidas (todas de solo PostgreSQL) |
| `UNIT_DB=ultron_studio_h5_review pgunit.sh` Schema, Immutability, Audit, `UltronPublishedConfigTest`, `UltronCommitStudioStampTest` (PG 14 local) | OK 278/278, 901 aserciones, 0 omitidas |
| Fixture: recuento por sección | classify 34, effectiveJson 21, signature 24, scope 63, gates 71, evidence 18 = 231; `StudioPublicationParityTest` afirma 231 nombres y `GatesParityTest` 71 |

No ejecutado: Lifecycle/Rollback/Canary/Access en PG, suites completas, specs del CRM, E2E, scripts.

## Criterios

1. **Protección de publicar — CUMPLE, con un matiz (m-1).** `flagEnabled()` exige `=== true` sobre un valor de `filter_var(..., FILTER_VALIDATE_BOOLEAN)` (`config/ultron_studio.php`, `StudioPublishing.php`); `assertKeys()` corre antes del reintento y de leer la versión; el lector con `off` devuelve null sin consultar. Los casos del fixture con llaves cerradas comprueban 409 y «un rechazo no escribe en la cadena» (`UltronStudioPublicationLifecycleTest::assertOutcome`, :828). `ENGINE_READS_VERSIONS=true` no abre nada solo (`UltronEngineIsolationTest`, evaluando los dos ficheros de configuración sin las variables).
2. **Aislamiento del motor — CUMPLE.** Fuera del Studio cambian en el motor solo `UltronPublishedConfig.php`, `UltronCommitService.php` y `config/marketing.php` (`git diff --stat` sobre `app/Services/Marketing app/Jobs app/Http/Controllers/Api/Internal routes config/marketing.php`; `routes/ultron-studio.php` es del Studio). El lector: un `SELECT` por `max(seq)`, `substr(effective_json, 1, 16385)`, punto de guardado si hay transacción abierta, `catch (Throwable)` → `fallback`, log con la clase y nunca el mensaje, subclave `hash_hmac('sha256', etiqueta, APP_KEY, true)`. El sello viaja como argumento OBLIGATORIO hasta `persist()`; revisé las 7 llamadas a `persist/blocked/safeFallback` y todas lo pasan. Nada del sello cambia el camino del commit (opción A).
3. **Cadena — CUMPLE.** Inserción con `seq = cabeza + 1` tras `verifiedParts()` (contrato, huella, firma, `D::parts` atado a uuid/seq) → 409 `publication_head_invalid` si no verifica; Legacy no copia y sigue abierta. `UniqueConstraintViolationException` se resuelve FUERA de `DB::transaction` (reintento y luego `conflictRefusal`; un UNIQUE desconocido se relanza, no se disfraza). Reintento por `client_request_id` (el controlador lo pasa a minúsculas; en PG la columna es `uuid`) ligado a persona + acción + versión + cuerpo (`bodyOf`). Libro en la misma transacción. Migraciones: disparadores en los dos motores, TRUNCATE y CHECK en PG, índices parciales, `down()` que se niega con filas de H5 y con cerrojo previo.
4. **Puertas — CUMPLE.** `publishRefusal()` sigue el orden de A5.1 y `promoteStateRefusal()/promoteEvidenceRefusal()` el de A5.2; 71/71 casos `gates` por la vía pura y 58 por HTTP. Evidencia: `turns`, `stamped`, `byState`, `recoveries` en UNA consulta; del acta solo código, total y siete recuentos (búfer propio); `promote` comparte `throttle:ultron-studio-evidence` (AS-2). Zona horaria de la app = UTC, así que `created_at >= since` es coherente.
5. **Calidad de las pruebas — CUMPLE (ninguna debilitada sin motivo).** Inmutabilidad: `PUBLISHED` → `VALIDATING` mantiene la fuerza (el CHECK de 9 estados excluye VALIDATING; pasa en PG). `httpExpectation()`: 3 casos, motivo escrito (D-10), sus códigos cubiertos por `StudioPublicationGatesParityTest`. Hardening 50 → 150 ms: motivo escrito, la vía vigilada tardaba segundos; **no reproduje** los 52 ms sobre la base. Lifecycle :820-822 acepta `forbidden` en lugar de `person_required` para el token de máquina: documentado en `informe-api.md` §1 (la puerta de `AuthorizationMap` responde antes). Los únicos `markTestSkipped` nuevos son de solo PostgreSQL.
6. **CRM — NO REVISADO.** Solo comprobé un punto de regresión: el CRM de producción (`5c1dbd7`) nunca pide `GET rules?status=archived`, así que el cambio de forma de esa respuesta (A10.2, paginada) no lo rompe; el CRM nuevo acepta las dos formas (`readArchivedRulePage`).
7. **Scripts de producción — NO REVISADO.**
8. **Documentación — NO REVISADO** (D-1…D-18 están en `informe-api.md` §5; no comprobé su traslado a `decisiones-lead-h5.md` ni a la Enmienda 2).
9. **Regresiones — NO REVISADO** (no repetí las cifras de los informes).

## Hallazgos

| # | Sev. | Dónde | Qué y escenario de fallo | Corrección propuesta |
|---|---|---|---|---|
| m-1 | menor | `config/ultron_studio.php` (`publishing_enabled`), prueba en `UltronStudioPublicationConfigTest.php:145` | La llave del Studio se enciende también con `1`, `on`, `yes` o `TRUE`, no solo con `true` exacto como dice el criterio 1 del encargo. Escenario: quien opera escribe `ULTRON_STUDIO_PUBLISHING_ENABLED=1` pensando que no abre nada y, con la llave del motor en `canary`, publicar queda abierto. Es coherente con A13 («booleano») y está escrito en el comentario. | Que el lead elija: o se acepta y el runbook/criterio dicen «true, 1, on, yes», o se exige el literal `true`. → lead / backend |
| m-2 | menor (a confirmar) | `StudioPublications.php:985-993` y `:846-847` | Si `D::classify` lanza (contenido que el catálogo actual no sabe leer), `revalidation` pasa a `blocked` pero `findings` puede ir vacío: 409 `revalidation_blocked` «tiene bloqueos» sin ningún bloqueo que enseñar. No lo provoqué. | Añadir un hallazgo sintético o un mensaje propio para «no clasificable». → backend |

## Pendiente para cerrar la revisión

- Criterio 7 entero (`deploy-h5-backend-fase1.sh`, `h5-engine-scope.sh`, `smoke-h5.py`, `smoke-run-h5.sh`): secretos, fallo cerrado, git como `www-data`, Dotenv antes de las cachés, «COMPLETADA» solo en verde, trap de la cuenta 32. **Es lo que bloquea la fase 1.**
- Criterio 6 (CRM `5c1dbd7..a29bcc8`, specs del módulo, sha256 de `backend-publication.testing.ts`, `ultron-studio.scss` intacto, sin `innerHTML`, texto de A11).
- Criterios 8 y 9, y Lifecycle/Rollback/Canary en PG.
