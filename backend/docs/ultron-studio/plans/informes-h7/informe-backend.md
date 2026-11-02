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

## F-1 (PERF7): el acta del canario en una sola pasada

**Encargo del lead (02-11):** reescribir `UltronCanaryReport::turnos()` como una sola pasada **sin cambiar la semántica**, con una prueba que compare byte a byte contra el algoritmo de antes, y el criterio de PERF7 §6. **Base:** `d629202`. Durante el trabajo HEAD pasó a `1c76a7f` (cierre del H6, DOC7 y `deploy-h7-backend.sh`), sin ningún cambio en `app/`, `tests/`, `routes/` ni `config/` (`git diff d629202 HEAD` sobre esas rutas, vacío). **Sin commit.** El árbol lo compartían a la vez DOC7 (documentos de `docs/ultron-studio/*.md`) y el tester (`informe-tester.md`): no toqué nada suyo.

**Qué cambia** (`app/Console/Commands/Marketing/UltronCanaryReport.php`: `turnos()` y un método privado nuevo; `handle()`, `revisar()`, `imprimir()` y las consultas, intactos):
1. **Las acciones se indexan una vez.** Cada id de mensaje apunta a la PRIMERA acción (por id) que lo nombra por `source_event_id` o por `metadata.message_id`: `??=` en el orden de la consulta, que es lo que devolvía el `first()` que antes se repetía por cada entrante.
2. **Los mensajes se recorren una vez**, en el orden de la consulta (por id). Cada saliente va al último entrante visto y el recorrido se corta en el entrante `max(1, --turns) + 1`, que solo cierra el último turno.
3. **Cada turno lee `created_at` y `metadata` una vez.** Antes eran 10 conversiones de fecha y 2 decodificaciones por turno: ~60 de los ~100 µs que costaba armar uno (Eloquent crea un Carbon nuevo en cada lectura).
4. **`mensajeQueNombra()`** obtiene `metadata.message_id` sin decodificar el JSON cuando la clave no puede estar: si el texto no lleva «message_id» ni `\u`, ninguna clave decodificada puede llamarse así, porque los demás escapes de JSON solo dan comillas, barras y caracteres de control. Ante la duda, decodifica. La `metadata` de la acción elegida se decodifica como antes, una vez en lugar de dos.
5. **`turnos()` pasa de `private` a `protected`.** Es la única costura para que la prueba ponga la referencia en su lugar; nadie más extiende el comando.

**Qué se conserva (lo vigila la prueba):**
- Cuenta los PRIMEROS T turnos desde `--since`, no los últimos.
- `--turns` ≤ 0 o no numérico da 1 turno (el corte de antes iba después de añadir), y «7x» se lee como 7.
- En un empate gana la primera acción por id, venga por la vía que venga, y una misma acción puede ser la de dos turnos.
- El último turno se cierra en `PHP_INT_MAX`, exclusivo.
- La dirección se compara con `==`, como hacía `Collection::where`.
- Se tratan igual que antes: las notas internas, los salientes anteriores al primer entrante, lo que queda fuera de `--since`, lo de otra conversación y lo que no tiene fecha.
- Nada cambia en lo que ULTRON dice o hace: el acta solo lee.

**Prueba nueva:** `tests/Feature/Marketing/UltronCanaryReportSinglePassH7Test.php` (4 pruebas, 180 aserciones).
- **La referencia** es el comando con el `turnos()` de `d629202` copiado tal cual: una subclase anónima registrada como `ultron:canary-report-referencia-h7`. Un contador comprueba que la referencia se ejecuta de verdad, para no comparar el acta consigo misma.
- **La comparación:** cada caso ejecuta las dos actas y exige el mismo código de salida, la misma salida byte a byte (JSON y texto) y las mismas sentencias con sus valores (`DB::listen` + `prepareBindings`).
- **Los datos** salen de un generador con semilla fija (`Random\Randomizer` + `Mt19937`):
  - **0 turnos:** conversación vacía, sin entrantes, todo antes de `--since`; también una conversación inexistente y una fecha ilegible.
  - **40 turnos:** un «zoo» de casos raros:
    - turnos sin respuesta, sin acción y con 3–4 salientes (uno con `kind` nulo);
    - el router por `message_id` entero, como texto, con espacios delante y con letras detrás;
    - acciones duplicadas, un empate en el que la del router va antes que la de `source_event_id`, y una acción que ata dos turnos;
    - respaldo, rendición y negación de cobro, y los siete hallazgos;
    - presencia con marca válida, ilegible y vacía;
    - `metadata` que no es un objeto, `message_id` de tipos raros, la clave escrita con el escape unicode del guion bajo («message», barra, «u005fid»; la prueba la arma con `chr(92)`) y un `message_id` anidado;
    - filas sin fecha, latencia 0 y negativa, un saliente con id `PHP_INT_MAX`, notas internas, acciones tardías y ruido de otra conversación.

    Se ejecuta con `--turns` 0/1/−3/«7x»/39/40/41/200, en JSON y en texto, y con tres ventanas.
  - **200 turnos:** 5 variantes.
  - **1 000 turnos:** los 200 primeros (el turno 1 y el 200 son los entrantes 1 y 200), `--since` en el 750 con `--turns=1000`, y en texto.
- **Además comprueba** que los datos ejercitan lo que dicen (los siete contadores con casos, turnos sin acción, sin respuesta, con 3+ salientes, sin fecha, respaldos, presencia y empates resueltos como antes). Con 1 000 turnos exige que la referencia tarde más de 5 veces lo que el comando. Medido: 82× en SQLite (2 529–2 534 ms frente a 30,8–31,0, en tres pasadas) y 71× en PG (2 537 frente a 35,5). Es el cociente de dos ejecuciones seguidas sobre los mismos datos, que la carga afecta por igual.
- **Resultado:** SQLite 4/4 (13–18 s) y PG 4/4 (15 s).

**Mutaciones: 13/13 cazadas**; el acta se restauró y se comprobó por sha256 tras cada una.
- Cómo se elige la acción: la última gana; acciones recorridas al revés; sin el respaldo por `message_id`; `message_id` nunca decodificado; comprobación previa sin `\u` (la caza el caso de la clave escapada en SQLite; PostgreSQL normaliza el `jsonb`).
- Cómo se cortan y reparten los turnos: sin el mínimo de un turno; sin el corte en T+1; corte uno tarde; sin el cierre en `PHP_INT_MAX`; lo que no es entrante cuenta como saliente; saliente al turno anterior.
- Qué se lee de cada turno: la fecha del turno tomada de la respuesta; la presencia tomada de la `metadata` de la acción.

**Cifras** con el arnés de PERF7, sobre la base propia `ultron_studio_h7_perf_f1` y en la misma máquina. «Antes» es `d629202` medido el mismo día. Carga a 1 min entre 2,1 y 2,9. Datos crudos en `docs/ultron-studio/ops/perf-h7/resultados/f1-f2/`.

`tramos-acta.php` (p50 de 5, ms):

| T | M | consultas | `turnos()` antes → después | `revisar()` | total antes → después |
|---|---|---|---|---|---|
| 40 | 80 | 3,5 → 3,7 | 43,2 → **2,4** | 1,7 | 48,8 → 8,2 |
| 100 | 200 | 5,0 → 5,1 | 253,0 → **5,9** | 3,5 | 262,5 → 15,5 |
| 200 | 400 | 8,3 → 7,4 | 985,1 → **11,8** | 6,5 | 1 001,8 → 27,6 |
| 200 | 800 | 12,1 → 12,0 | 1 685,0 → **12,2** | 6,5–6,7 | 1 705,7 → 32,6 |
| 200 | 2 000 | 27,1 → 25,9 | 3 835,0 → **13,7** | 6,6 | 3 870,6 → 48,1 |

`GET versions/{uuid}/canary-evidence` en proceso (`run-queries.sh test_c`), p50/p95 en ms:

| Turnos | antes, PERF7 (n = 90) | antes, hoy (n = 10 tras 2) | **después (n = 90 = 3 × 30 tras 5)** | sentencias | bytes |
|---|---|---|---|---|---|
| 0 | 30,1 / 31,1 | 29,8 / 31,5 | 30,3 / 32,3 | 42 → 42 | 868 = 868 |
| 40 | 79,9 / 81,0 | 80,0 / 81,8 | 38,0 / 39,8 | 42 → 42 | 863 = 863 |
| 200 | 1 041,6 / 1 049,8 | 1 040,4 / 1 042,4 | **67,7 / 69,9** | 43 → 42 | 866 = 866 |
| 1 000 | 3 947,3 / 5 708,4 | 3 929,4 / 3 950,8 | **90,7 / 95,6** (máx 112,5) | 43 → 42 | 869 = 869 |

Las sentencias de la ruta oscilan ±1 por el `touch` de la sesión (PERF7 §4.1); las del acta son 6 antes y después. Familia G (n = 3):
- **Acta `--json`:** 54,2 → 11,8 ms con 40 turnos; 1 013,8 → 39,6 con 200; 3 925,7 → 61,9 con 1 000 (M = 2 000).
- **Ruta entera:** 82,3 → 39,4; 1 043,1 → 63,5; 3 942,4 → 91,2 ms.

**Criterio de PERF7 §6: cumplido.**
- `turnos()`: **13,7 ms** ≤ 20 con T = 200 y M = 2 000.
- Evidencia p95: **69,9 ms** ≤ 100 con 200 turnos y **95,6** ≤ 150 con 1 000.
- JSON idéntico y mismas sentencias: lo prueba la H7, y el arnés lo confirma (6 sentencias del acta, mismos bytes).

El presupuesto del smoke (`presupuestos-h7.json`: «400 tras F-1») es de OPS7/PERF7 y no lo he tocado.

**Cómo se llegó** (para la revisión), con T = 200 y M = 2 000:
1. La primera pasada única dejó `turnos()` en **30,6 ms**. El perfil por tramos (`f1-f2/perfil-turnos.php`) mostró ~100 µs por turno, 60 de ellos en las lecturas de `created_at`.
2. Leer cada valor una vez por turno lo bajó a **18,4 ms**. Lo que todavía crecía con la ventana era el índice: decodificar 1 000 `metadata` costaba 5,8 ms.
3. La comprobación previa de `mensajeQueNombra()` lo dejó en **13,7 ms**.

Una medición intermedia de G y `tramos-acta`, tomada con la carga en 4,7–6,2 por otro agente, se repitió con la carga en 2,4–2,5: las cifras de arriba son las de esa repetición.

**Puerta del motor.** `git diff 1e5f9a0 --stat -- <rutas del motor>` da **exactamente lo mismo antes y después** de mi cambio: los 10 ficheros de B7, SEC7 y OPS7 que nombra el contrato. El acta vive en `app/Console/Commands/Marketing/`, fuera de esas rutas, como ya decía PERF7 §5 (punto 4). Aun así es la herramienta del motor que usa el vigía, y el dueño debe saberlo.

**Siguiente cuello de botella (medido).** Con 1 000 turnos el acta tarda 62 ms: 26 son las dos consultas sin tope desde `--since` (M = 2 000) y 14 son `turnos()`. Leer solo hasta el entrante T+1 cambiaría las sentencias, así que queda fuera de este encargo y es decisión del lead. Los 30 ms de la ruta con 0 turnos son el coste fijo de F-3.

## F-2 (PERF7): campañas de Meta

**Qué cambia.** El contrato de la respuesta no se toca.
- **(a)** En `StudioCampaignLinks::dtos()`/`versionRef()`, `integrityOk()` se calcula una vez por instancia de versión dentro de la llamada (`spl_object_id`). Es exacto por dos razones: `with('version')` da la misma instancia a todas las asociaciones de una versión, e `integrityOk()` es pura (lee `content` y `content_hash`, sin efectos). Se indexa por instancia y no por id para no suponer nada sobre filas cargadas en momentos distintos.
- **(b)** `StudioMetaCampaigns::campaigns()` devuelve en `dto` un cierre con los valores ya leídos e inmutables (incluido el `$ahora` de ese momento), y `campaignsPage()` solo lo invoca para las filas de la página. Como `campaignDto()` es pura y no lanza (HMAC, recortes y una regex), armarla después da la misma DTO.
  - Único consumidor en la aplicación: `campaignsPage()`. `tramos-campanas.php` (de PERF7) solo usa `entityId`, pero su tramo 2 deja de incluir las DTO: su etiqueta ya no describe lo que mide.

**Cifras:** `tramos-campanas.php` sobre `ultron_studio_h7_perf_http` (500 campañas y 50 activas sobre 3 versiones), p50/p95 en ms.

**Por qué A/B.** Durante la sesión la velocidad de la máquina cambió casi al doble según la carga de los demás agentes, también en tramos que no toco. Por ejemplo, `connection()` pasó de 4,65 a 2,23 ms y la huella de una versión de 0,26 a 0,15. Así que el «antes» y el «después» de (a) + (b) se midieron intercalados en los mismos minutos (`f1-f2/ab-campanas.py`): HEAD, árbol, HEAD, árbol, con los ficheros restaurados por sha256 y la carga en 1,5–1,6. Los tramos que no cambian salen iguales en las cuatro vueltas, que sirven de control: `connection()` 2,2, consulta 2,0, orden 1,5 y huella 0,15. La tabla da las dos vueltas.

| Tramo | sentencias | HEAD | árbol, (a) + (b) |
|---|---|---|---|
| 2. `campaigns()` (500 filas) | 4 = 4 | 21,57 / 21,90 · 21,55 / 21,95 | **17,50 / 17,68 · 17,44 / 17,68** |
| 4. `dtos()` de las 50 activas | 2 = 2 | 8,88 / 9,08 · 8,86 / 9,26 | **3,59 / 3,64 · 3,59 / 3,69** |
| 6. `campaignsPage()` entero (página de 20) | 17 = 17 | 36,47 / 36,93 · 36,44 / 36,95 | **27,19 / 27,55 · 27,16 / 27,79** |

`campaignsPage()` baja un 25 %, de 36,5 a 27,2 ms: ~5,3 ms por (a) y ~4,1 por (b). La medición secuencial de (a) sola, con la máquina en el estado lento y los controles iguales, cuenta lo mismo: `dtos()` 15,27 → 5,87 y `campaignsPage()` 61,17 → 51,12.

**Corrección de una estimación mía.** Antes de medir el A/B escribí que las DTO eran 24,7 ms de los 37 de `campaigns()`. Ese tramo de `perfil-campanas.php` incluía leer `synced_at` y convertirlo con `CarbonImmutable::instance`, y eso se sigue haciendo antes de paginar. Lo que (b) difiere de verdad es ~4 ms con 500 filas; con las 114 filas de producción, ~1 ms (estimación lineal, no medida). He corregido la etiqueta del guion. La ganancia de (b) es modesta: la mantengo porque no cambia la respuesta y está vigilada, y quitarla son un fichero y una línea si el lead prefiere no tenerla.

**Pruebas:**
- **Las del H6 de campañas** (`UltronStudioIntegrations*`, 14 ficheros, más `StudioIntegrationsParityTest`): 1 006 en verde en SQLite (46 solo PG). En PG, **1 008/1 008** con las 2 nuevas, 6 370 aserciones y sin omisiones.
- **El oráculo** (`integrations-cases-check.py --php --tw --impl`): 722 casos, 0 discrepancias, «RESULTADO: OK».
- **Prueba nueva** `tests/Feature/UltronStudio/UltronStudioCampaignsPageH7Test.php` (2 pruebas, 35 aserciones). Con asociaciones intercaladas a una versión íntegra, a otra adulterada, a una en canario y a la línea base, cada una lleva la integridad de SU versión, tanto en campañas como en asociaciones. Y la lista recorrida página a página (`per_page=2`, más una página vacía) es exactamente la lista entera, también con filtros.
- **Mutaciones: 4/4 cazadas:** memoria con una sola clave, memoria por estado de la versión, DTO que captura la fila por referencia y página con la DTO de la primera fila.

## Ficheros de F-1 y F-2 (sin commit)

- `app/Console/Commands/Marketing/UltronCanaryReport.php` (F-1)
- `app/Services/UltronStudio/StudioCampaignLinks.php` (F-2 a y b)
- `app/Services/UltronStudio/StudioMetaCampaigns.php` (F-2 b)
- `tests/Feature/Marketing/UltronCanaryReportSinglePassH7Test.php` (nueva)
- `tests/Feature/UltronStudio/UltronStudioCampaignsPageH7Test.php` (nueva)
- `docs/ultron-studio/ops/perf-h7/resultados/f1-f2/`: los datos crudos de antes y después (`antes/`, `despues/`) y tres guiones de solo lectura (`perfil-turnos.php`, `perfil-campanas.php` y el A/B `ab-campanas.py`)
- este informe

Pint: limpio en los siete ficheros PHP (los cinco de código y prueba y los dos guiones).

**Bases desechables:** queda `ultron_studio_h7_perf_f1`, con los datos de la familia C, para repetir `tramos-acta.php`.

**Otras pruebas ejecutadas:**
- **SQLite:** acta 32, vigía (`UltronTurnWatchdogTest`) 32 e `InternalSecretAuthoritySurfaceTest` 12, en total **76/76**. Las de evidencia y `promote` del Studio dan **197 en verde**: `UltronStudioPublication{Canary,Lifecycle,Access,Race}Test`, `UltronStudioEngineReadOnlyTest`, `UltronStudioLabNoEngineEffectsTest`, `UltronStudioPermissionsTest` y `StudioCanaryEvidenceTest`. Se omiten 38, todas carreras «solo PostgreSQL».
- **PG** (`UNIT_DB=ultron_studio_h7_b`): las mismas, **273/273**, sin omisiones.

**No verificado, y por qué:**
- No lancé suites completas ni el banco HTTP (`serve.sh` + `http-bench.py`): el lead pidió solo pruebas acotadas, y el tester corre la regresión aparte.
- No hay medición en producción. El techo del smoke para la evidencia («400 tras F-1» en `presupuestos-h7.json`) lo calibra OPS7.
- La ganancia de F-2 con los tamaños de producción (114 campañas) es una estimación lineal, no una medida.
