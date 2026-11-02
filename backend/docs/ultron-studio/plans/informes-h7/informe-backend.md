# Hito 7 · B7 (backend): informe

**Fecha:** 01-11-2026. **Rama:** `feat/ultron-studio-h7` (base `f78adfc`; el lead guardó la primera tanda como WIP en `9530a51`). **Sin commit** de lo posterior (H-2 y este informe). No ejecuté `composer`.

**Decisiones del lead (01-11):** los dos topes nuevos de M-2 (columnas de exportación y catálogo de cargos) quedan confirmados; el ajuste de `ReceiptAgentSecurityTest` es correcto; el resto de M-2 queda solo informado; `no-store` fuera de `/api/admin` queda para el propietario. Encargo añadido a B7: **H-2 de SEC7** (§2bis).

## 1. Entregas

| # | Qué | Estado | Ficheros |
|---|---|---|---|
| a | `Cache-Control: no-store, private` en todo `/api/admin/*` | Hecho | `app/Http/Middleware/NoStoreAdminResponses.php` (nuevo), `bootstrap/app.php` (`prepend`) |
| b | `StudioAudit::recordDenied` con READ COMMITTED | **Ya estaba** (H5 §A10.6): `StudioAudit.php:111-121`; pruebas PG `UltronStudioAuditTest::test_la_deduplicacion_de_los_denegados_fija_read_committed…` y `UltronStudioPublicationRaceTest` (20 intentos en REPEATABLE READ → 1 fila). Sin cambios | — |
| c | B-2: `verified` por entrada | Hecho | `app/Services/UltronStudio/StudioPublications.php` (`page`, `forVersion`, `historyEntryDto` nuevo, `withJson` estática), docblock de `UltronStudioPublicationController.php` |
| d | Tope de cuerpo antes de `TrimStrings` | Hecho | `app/Http/Middleware/ValidatePostSizeWithStudioCap.php` (nuevo), `bootstrap/app.php` (`replace`) |
| e | M-2 fuera del Studio | 8 rutas corregidas (9 con `bundles`+`preview`), resto informado (§3) | controladores de §3 |
| f | `TrustProxies` | Comprobado, sin cambios de código; prueba + guarda | `tests/Feature/Hardening/TrustedProxiesNetworkTest.php` |
| H-2 | Restablecer la contraseña desde el CRM cierra las demás sesiones | Hecho (§2bis) | `app/Http/Controllers/Api/Admin/AdminUserController.php` (`guardarClave` + `revocarOtrasSesiones`), `tests/Feature/Hardening/AdminPasswordResetRevokesSessionsTest.php` (nueva) |

**Fuera de mi lista, tocado por necesidad:** `tests/Feature/Caja/ReceiptAgentSecurityTest.php` (una aserción): afirmaba que la impresión silenciosa en `/api/admin/receipts/*/prints` NO llevaba `no-store`; con B7-a toda `/api/admin/*` lo lleva. Cambiada a `assertHeader('Cache-Control', 'no-store, private')`. Caja 680/680.

## 2. Decisiones y cifras

**(a) no-store.** Global y primero de la pila (no en el grupo `api`): un middleware de grupo no ve 404/405, los 413, el modo mantenimiento ni la verificación previa de CORS. Sustituye lo que pusiera el controlador. Hallazgos medidos en la prueba: la exportación XLSX (`response()->download()`) respondía **`public`**, los SSE `no-cache`, el JSON `no-cache, private`. Sin `Pragma` (el proyecto no la usa). **No cubre** las 65 rutas del CRM fuera de `/api/admin` (`users` 19, `plans` 9, `attendances` 8, `routines` 6, `turnstile` 6…), fuera del contrato; destaco `GET api/attendances/face-image/{userId}` con `private, max-age=3600` (rostro biométrico en la caché del navegador) → para SEC7/propietario.

**(c) verified.** Las MISMAS cuatro comprobaciones que `head.signatureOk` (`verify()`: contrato, huella, firma con `signatureFor()`, decodificación atada a `uuid`/`seq`; una entrada copiada de otra fila no verifica). No añade ninguna sentencia que nombre al motor (la prueba de solo lectura exige 2). Cabeza, motor y `publication-check` sin cambios. Cubre lo firmado; `action`, `reason`, actor, `version_id` y fecha no están en la firma (solo los protege la cadena de solo inserción). Las respuestas de escritura NO llevan `verified`. **Contrato para FE/DOC:** clave aditiva `verified: boolean` al final de cada entrada de `GET publications` (`data.data[]`) y de `GET versions/{uuid}` (`data.publications[]`); el CRM del H6 la ignora sin romperse; la exportación de fixtures (`StudioPublicationExport`) no cambia.

**(d) tope temprano.** Laravel no permite insertar en medio de la pila global: el middleware ocupa el hueco de `ValidatePostSize` (extiende y delega), queda **detrás de `HandleCors`** (el CRM es de otro origen: sin CORS no leería el 413) y del mantenimiento, **delante de `TrimStrings`**. Mismo 413 byte a byte que el de la ruta (que se queda como segunda capa). Cambio visible: sin sesión, >64 KB en el Studio da 413 (antes 401 tras recorrer el cuerpo); en el Studio, >`post_max_size` da `payload_too_large` en vez de `ocr_image_too_large`. Medido (local, PHP 8.3.33, 8 387 580 bytes, 1 397 924 textos cortos): **antes 2,7–3,3 s y +149 MB de pico (TrimStrings y Convert recorren 1 397 924 textos cada uno); después 43–46 ms y +75 MB, 0 textos recorridos.** **Residual:** `Request::capture()` (public/index.php) decodifica el JSON entero antes de cualquier middleware (~40 ms, ~75 MB con ese cuerpo); solo lo evita un tope en nginx (`client_max_body_size 64k` en `location ^~ /api/admin/ultron-studio/`; su 413 sería HTML sin CORS) → decisión de OPS7/propietario. Conviene confirmar el `memory_limit` de FPM.

**(f) TrustProxies.** Topología documentada (FINAL_REPORT): nginx → FastCGI → FPM, sin CDN. Sin `trustProxies()` ni `config/trustedproxy.php`: `ip()` = `REMOTE_ADDR` = cliente. La prueba observa el cubo real `ultron-studio-read:ultron-studio-net:<red>`: `X-Forwarded-For`/`X-Real-IP`/`Forwarded` no lo mueven (ni desde 127.0.0.1); con `TrustProxies::at(['127.0.0.1'])` simulado, solo el proxy fija la IP y de una cadena cuenta la última no confiable; la guarda falla si alguien configura proxies (o `*`). No verificado en el servidor (sin acceso).

## 2bis. H-2 de SEC7: restablecer la contraseña cierra las demás sesiones

**Cambio de comportamiento.** `POST /api/admin/users/{admin}/reset-password` (temporal o personalizada), en la MISMA transacción que la contraseña y la traza, revoca las sesiones activas de la cuenta (`admin_sessions.revoked_at = now()`, `revoked_reason = 'password_reset'`), es decir, la cuenta afectada sale de todos sus dispositivos:
- el «recuérdame» del CRM no es un mecanismo aparte: son filas de `admin_sessions` con 30 días (`config/admin.php`, `session_remember_days`); no hay `remember_token` en `admins`. Caen con las demás;
- los vales de SSE (`StreamTicketService::resolve()` exige la sesión activa) dejan de valer en el acto; un SSE ya abierto sigue hasta el final de su conexión (la del canal de usuarios dura ~25 s) y no puede reconectar;
- **la sesión con la que se hace la petición se conserva** cuando la cuenta es la propia (un Super Admin que restablece la suya): su flujo no cambia; sus otros dispositivos sí salen;
- solo se tocan sesiones vivas: las ya cerradas conservan su motivo (`logout`…) y las caducadas no se marcan;
- la traza (`audit_logs.metadata`) añade `sessions_revoked` (el número, sin tokens ni huellas); el evento del canal SSE de usuarios no cambia (es una lista blanca de claves);
- si el restablecimiento falla (500), no se cierra ninguna sesión.

**Qué ve el afectado:** su siguiente petición con el token viejo responde **403 `admin_token_invalid`** (no 401: `EnsureAdminAuth` da 401 solo sin token y 403 a cualquier token que no resuelve). El CRM (`auth.interceptor.ts`, `isAdminAuthFailure`) cierra la sesión ante 401 **o** 403 con ese código, así que vuelve al login. No toqué `EnsureAdminAuth`.

**Residual:** un login con la contraseña ANTERIOR que se confirme en los milisegundos que separan la comprobación del hash de la transacción del restablecimiento podría dejar una sesión nueva viva (carrera estrecha; exige conocer la contraseña anterior). Cerrarla del todo exigiría bloquear la fila de `admins` en el login; no lo hice. Mitigación existente: desactivar la cuenta corta al instante.

**Pruebas** (`AdminPasswordResetRevokesSessionsTest`, 4/4, 22 aserciones): normal + «recuérdame» + vale → 403 y vale nulo, cerrada y caducada intactas, quien restablece sigue dentro, `sessions_revoked = 2`; con la contraseña nueva se vuelve a entrar; quien restablece la suya conserva la sesión actual (200) y pierde la otra (403), `sessions_revoked = 1`; un fallo de la traza (500) no cierra nada. Mutaciones: sin la revocación caen 3/4; sin conservar la sesión propia cae la de la cuenta propia. Pint limpio en los dos ficheros.

## 3. M-2 fuera del Studio

Laravel expande el comodín sobre todos los elementos ANTES de mirar el `max` de la lista: el tope existente no protege. Medido con 10 000 elementos (antes → después):

| Ruta (commit propuesto, uno por fila) | Tope | Antes | Después |
|---|---|---|---|
| `POST api/admin/iron-ai/chat` — `IronCrmAiController` | `history` 50 (existente) | 11,5 s | 200 ms |
| `POST api/admin/marketing/inbox/conversations/{id}/messages` — `MarketingInboxController` (import + trait + `sendMessage`) | `attachment_ids` `max_per_send` (existente) | 1,7 s | 43 ms |
| `POST …/conversations/{id}/tags` — mismo fichero, trozo `tags` | `add`/`remove` 10 (existente) | 1,5 s | 58 ms |
| `POST api/admin/electronic-invoices/bundles` y `/preview` — `ManualInvoiceBundleController::validateSelection` | `items` 30 (existente) | 2,3 s | 66 ms |
| `PUT api/users/{user}/employee-access` — `EmployeeAccessController` | 7 / 6 (existentes) | 15,6 s | 158 ms |
| `PUT api/admin/valuations/trainers/{id}/availability` — `ValuationAvailabilityController` | `rules` 100 (existente) | **100 s** | 202 ms |
| `GET api/admin/exports/{dataset}` — `ExportController` (dos fases a mano: es descarga, siempre JSON) | **nuevo**: nº de columnas del dataset (con `distinct` + lista blanca, una lista válida nunca pasa de ahí) | 24,2 s | 66 ms |
| `POST`/`PATCH api/admin/workforce/staff` — `WorkforceStaffController` | **nuevo**: `count(Positions::staffKeys())` (el CRM, `staff-form-dialog.ts`, marca casillas del catálogo sin repetir) | 60,9 s | 54 ms |

Pruebas: `tests/Feature/Hardening/ListSizesFirst/*.php` (un fichero por ruta, 16 pruebas): lista por encima → 422 solo con la clave de la lista y **el validador no expande la lista** (menos de 10 atributos validados; devolviendo `iron-ai/chat` a una sola fase, 20 003: la mutación cae); dentro del tope → los errores de siempre; en las dos de tope nuevo, el máximo legítimo pasa. La primera versión medía tiempo (<1 s) y falló una vez con la máquina cargada (1 373 ms con tres suites en paralelo); la aserción estructural no depende de la carga. Los tiempos de la tabla son de una medida aislada. Con el trait, el 422 de una lista que no pasa sus reglas de lista trae solo esos errores (como en el Studio); las peticiones legítimas responden igual.

**Ya protegidas (comprobación previa manual):** `POST api/admin/receipts` (`source_ids`), las 4 de `plan-transfers` (`guardSize`).

**Solo informadas (sin máximo legítimo evidente; decisión de producto):** `POST api/admin/trainers/{trainer}/members` (`member_ids`, `exists` por elemento: **68,6 s** con 10 000), `POST api/admin/caja/sales` (`items`, `exists` por elemento), `POST api/admin/caja/sales/{sale}/refund` (`items`), `POST api/admin/audit-logs` (`changes`), `settle`/`settle-preview` de cuentas por cobrar (`only_ids`), `POST/PUT api/admin/exercises` (`steps`, `tips`…), `PUT api/admin/trainers/{trainer}/professional` y `PUT api/admin/{members|users}/{id}/fiscal-profile` (FormRequest), `POST/PUT api/plans` (`features` sin tope impide el trait), `api/routines`, `api/members/{member}/routines` (`exists` por elemento), `api/trainers` (`roles`), y el CRM legado `crm/*` (apagado por defecto). **Fuera del CRM** (app del socio, kiosco, entrenador; más expuestas): `AppClass`, `AppRoutine`, `AppStore`, `AppWorkoutSession`, `GymEquipment`, `IronAiRealtime`, `Kiosk*`, `MemberSupport`, `NotificationPreference`, `Payment`, `Trainer/IntegralPlan`, `SignMemberContractRequest`, `StoreNutritionGuideRequest`: no auditadas a fondo; recomiendo una pasada propia.

## 4. Verificación ejecutada

Árbol compartido con DB7, SEC7 y OPS7 trabajando a la vez (HEAD `167e34c` más sus cambios sin confirmar): las cifras son de ese árbol en movimiento.

**Suites completas (01-11, 23:46–00:11 UTC):**

| Suite | Motor | Resultado |
|---|---|---|
| `tests/Feature/UltronStudio tests/Unit/UltronStudio tests/Feature/Access tests/Feature/Chaos tests/Feature/Hardening` | SQLite | **2 798 pruebas, 28 025 aserciones, 0 fallos**, 106 omitidas (solo PG), 2 min 30 s (repetición sin carga, 00:08–00:11). La primera pasada, con tres suites en paralelo, dio 2 rojos: la sonda de Twilio (`UltronStudioIntegrationsProbeTest`, viaja 299 s desde el reloj real y con la máquina cargada el enfriamiento de 300 s ya había vencido; aislada 8/8; no es mía) y mi prueba de valoraciones por tiempo (1 373 ms; pasada a aserción estructural, §3) |
| `tests/Feature/Marketing` | SQLite | **2 155/2 155 OK**, 12 830 aserciones |
| `tests/Unit/Marketing` | SQLite | **2 829/2 829 OK**, 8 904 aserciones (el «Marketing 4977» es Feature + Unit: hoy 4 984) |
| Puerta PG `UNIT_DB=ultron_studio_h7_b pgunit.sh tests/Feature/UltronStudio tests/Unit/UltronStudio` | PG 14 | **2 502/2 503**, 31 302 aserciones. El rojo: `UltronStudioMonitorTest::test_integraciones_distintas_de_lo_esperado_abren_su_incidente` (OPS7, fichero en edición): compara con `assertSame` un JSON que PostgreSQL guarda en `jsonb`, que reordena las claves (`id`, `actual`, `expected`, las cortas primero); en SQLite pasa. Repetido aislado en PG: sigue rojo. Para OPS7 |
| `tests/Feature/Hardening` | PG 14 | **44/44 OK**, 219 aserciones (H-2, M-2, `no-store`, `TrustProxies`) |

**Puntuales:** `UltronStudioEarlyBodyCapTest` 5/5, `UltronStudioPublicationVerifiedTest` 6/6 (y 11/11 con la anterior en PG), `AdminNoStoreCacheTest` 3/3, `TrustedProxiesNetworkTest` 3/3, `ListSizesFirst` 16/16, `AdminPasswordResetRevokesSessionsTest` 4/4, `tests/Feature/Caja` 680/680, `tests/Feature/Exports` 21/21, 29 ficheros existentes de las rutas M-2: 515/515 (3 deprecaciones previas de `AdminRoutesSecurityTest`).

**Mutaciones** (todas restauradas, comprobado con `cmp`): quitar el `replace` (4/5 caen), quitar el `prepend` (cae), `verified` siempre true (5 caen), verificar sin atar `uuid`/`seq` (cae la variante «de otra fila»), `trustProxies('*')` (3/3 caen), `iron-ai/chat` en una fase (cae: 20 003 atributos), H-2 sin revocar (3/4 caen) y sin conservar la sesión propia (cae la de la cuenta propia).

**Puertas:** diff del motor desde `7d2c6d8`: solo `InternalMarketingKnowledgeController.php` (de SEC7, nombrado en el contrato); `auth:coverage --unmapped` vacío (salida 0); 56 rutas del Studio. Pint: lo mío limpio; `bootstrap/app.php` y `MarketingInboxController.php` fallan con los mismos *fixers* que en `HEAD` (previo, no lo arreglé).

## 5. Dudas y pendientes para el lead

1. **OPS7:** el rojo de `UltronStudioMonitorTest` en PG (orden de claves de `jsonb`; `assertEquals` o comparar por clave lo resuelve).
2. Topes de producto para las rutas M-2 solo informadas (sobre todo `trainers/{trainer}/members`, 68,6 s con 10 000, y `caja/sales`).
3. Tope en nginx para el Studio (evitaría la decodificación del JSON en `Request::capture()`), decisión de OPS7/propietario.
4. H-2: el afectado ve 403 `admin_token_invalid` (no 401); el CRM lo trata igual. Si se quiere 401 para las sesiones cerradas, es un cambio de `EnsureAdminAuth` que afecta a todo el CRM: no lo hice.
