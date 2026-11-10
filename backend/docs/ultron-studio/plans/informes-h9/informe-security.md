# Informe SEC9-a · Seguridad del Hito 9 (ULTRON STUDIO FULL)

**Paquete:** SEC9-a (`security`). **Árbol:** worktree `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice` con el H9 sin confirmar (HEAD `ab54a256` = `origin/main`). **Cierre de esta pasada:** 10-11-2026 02:21 UTC. **Sin commit, sin red, sin producción.**

## 0. Resumen

- **Hecho en disco:** el modelo de amenazas (`docs/ultron-studio/ULTRON_STUDIO_H9_AMENAZAS.md`, STRIDE por 9 superficies más la revisión del canal SSE), la lista blanca del H9 en `UltronEngineIsolationTest` (+217/−2), la guarda dinámica `tests/Feature/UltronStudio/OutreachIsolationTest.php` (5 pruebas, 820 aserciones) y las 5 guardas estáticas de `tests/Feature/Outreach/Guards/` (19 pruebas, ≈ 6 700 aserciones). Pint en verde en todo lo mío.
- **Estado de las guardas al cierre:** 33 pruebas, **29 en verde, 4 en rojo**, y las 4 son de rutas y de ficheros de otros dueños: `app/Services/Outreach/Run/OutreachRoutes.php` (B9-run) nombra dos controladores del Studio desde la ejecución (3 pruebas), y el recuento de rutas del Studio es 108 ≠ 77 (la rama del H9, L9-38). Ninguna de las 4 es un agujero: son fronteras que la rama del H9 tiene que cerrar.
- **Lo que las pruebas demuestran hoy:** el H9 no escribe en ninguna tabla ajena (558 sentencias recorridas, 32 escrituras, todas en sus 15 tablas, el libro, los incidentes y la sesión; el gobierno no escribe `outreach_*` y la ejecución no escribe el gobierno); hechos, puente y canal SSE son 100 % lectura sin `FOR UPDATE`; **cero red** con las banderas por defecto y con la configuración de producción con la ejecución en `live` (la constante cierra); puerta de diff vacía frente a `origin/main` en ULTRON Legacy, n8n, OTP y motor comercial; todo nace apagado y en 0; ningún dato personal sembrado sale por las 10 lecturas del gobierno ni por el canal SSE; cuatro ojos **por persona** en aprobar, publicar y soltar frenos.
- **Los tres riesgos mayores** (detalle en §2): **R-1** el tique del SSE en la URL es reutilizable 5 minutos y abre también `face-image` (diseño heredado del CRM, no del H9); **R-2** `commercial_automation` no está en `SHARED_TOKEN_EXCLUDED_DOMAINS` y las 31 rutas nuevas están sin mapa (hoy denegadas por defecto; en la rama del H9 hay que mapear y excluir **a la vez**, o el token compartido entrará donde las llaves sean solo `commercial_automation.*`); **R-3** la supresión 1581 y la rotación de `APP_KEY` no tienen runbook para las tablas del H9 (`subject_ref`, `phone_ref` y la cadena firmada quedan obsoletos; el fallo es cerrado, pero deja todo el gobierno ilegible).

## 1. Qué entrega, por fichero

| Fichero | Qué es | Estado |
|---|---|---|
| `docs/ultron-studio/ULTRON_STUDIO_H9_AMENAZAS.md` | Modelo de amenazas STRIDE: catálogo y gramática; planificador, colas, reserva y repetición; canales; gobierno; API y permisos; datos personales; IA; operación; canal SSE; tabla de guardas | Escrito (170 líneas) |
| `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` (edición) | Lista blanca del H9: las 2 migraciones de DB9 por ruta exacta (Anexo B), `config/ultron_studio_stream.php`, los 3 lectores de `Facts` atados a su `TABLES`, el puente por referencias exactas, `OutreachTables` con sus 5 cadenas, los temas `outreach.*` del canal solo con la interfaz de SSE9; prueba nueva `test_hito_9_los_lectores_de_facts_y_el_puente_solo_leen_sus_tablas` | 9 pruebas: 6 verdes, 3 rojas por `OutreachRoutes.php` y el recuento 108/77 (§3) |
| `tests/Feature/UltronStudio/OutreachIsolationTest.php` | Escuchador de consultas por tramos (S9, hechos, puente, ejecución, SSE); cero red por defecto y en producción; puerta de diff `git`; lectores del Studio en `Run/` | 5/5 verdes, 820 aserciones |
| `tests/Feature/Outreach/Guards/OutreachFlagsGuardTest.php` | Las 3 configuraciones sin variables: todo `false`/0/`off`/`required`/`07:00-19:00`; solo `true/1/on/yes` encienden; programador sin `outreach:*` fuera de su bloque | 3/3 |
| `tests/Feature/Outreach/Guards/OutreachStaticGuardsTest.php` | Constante anclada; nombres prohibidos (proveedores, red, n8n, OTP, Legacy, cadena del motor); importaciones de `Outreach` (lista cerrada L9-28/D9-2); claves de `Log::`; modelos blindados; `released_at` con un solo escritor; etiquetas HMAC | 5 pruebas: 4 verdes, 1 roja por `OutreachRoutes.php` |
| `tests/Feature/Outreach/Guards/OutreachPersonalDataGuardTest.php` | Columnas de las 15 tablas; teléfonos en el código; lista blanca del evento SSE y columnas por tema; supresión 1581 (pendiente de OPS9 con lista exacta) | 4/4 |
| `tests/Feature/Outreach/Guards/OutreachFourEyesGuardTest.php` | Dos cuentas de la misma persona no aprueban ni publican; quien abre un freno no lo suelta ni con otra cuenta; `samePerson` por cuenta o identidad | 3/3 |
| `tests/Feature/Outreach/Guards/OutreachRoutesMapGuardTest.php` | Toda ruta del H9 mapeada o en la lista cerrada de pendientes (31 hoy), nunca en las dos; canal SSE en `Crm/UltronStudio` y `routes/ultron-studio.php`; `ultron` excluido del token compartido; `commercial_automation` anotado | 3/3 |

## 2. Hallazgos (impacto real y ubicación)

### R-1 · Media · El tique del canal SSE va en la URL, vale 5 minutos, no es de un solo uso y abre también las imágenes biométricas

- **Dónde:** `app/Services/Admin/StreamTicketService.php:33` (`TTL_SECONDS = 300`, `Crypt::encryptString({sid, exp})`, sin nonce ni consumo); `app/Http/Middleware/EnsureAdminAuth.php:58` (`*/stream` **y** `*/face-image/*`); `app/Services/UltronStudio/Stream/StreamRateLimit.php:56-60` (el limitador usa `sha256(ticket)`, bien).
- **Impacto:** la URL con el tique queda en el `access.log` de nginx, en el historial del navegador y en el `Referer`. Quien la lea dentro de los 5 minutos abre **cualquier** stream del CRM como esa sesión (membresías, cajas, usuarios, clases, el Studio) y las fotos de `face-image`. No entrega la sesión (bien) y el Studio no empeora el diseño: lo hereda. Es preexistente (H1–H8 ya usan el vale) y **no es del H9**.
- **Recomendación (fuera del H9, para el lead):** tique de un uso (`Cache::add("stream-ticket:{nonce}", 1, 300)` al emitir y `Cache::pull` al resolver) o TTL de 60 s; `Referrer-Policy: no-referrer` en el CRM; no registrar la query de `*/stream` en nginx. Mientras, el riesgo se acepta como está en los otros cinco streams.

### R-2 · Media (pendiente de la rama) · 31 rutas sin mapa y el dominio `commercial_automation` sin excluir del token compartido

- **Dónde:** `php artisan auth:coverage --unmapped` lista las 30 de `strategies/**` y, desde que SSE9-b retiró su entrada para cumplir L9-43, `GET api/admin/ultron-studio/stream` (`AuthorizationMap.php` ya no la tiene; `routes/api.php` ya no la declara). `app/Support/Access/CrmPermission.php:266` no incluye `commercial_automation`.
- **Impacto hoy:** ninguno hacia fuera: `EnforceAdminAuthorization` **deniega** la ruta sin clasificar (`app/Http/Middleware/EnforceAdminAuthorization.php:77-84`, `auth:admin:unmapped_route`), así que en producción el gobierno y el canal son inalcanzables hasta que se mapeen (falla cerrado). El riesgo es **de secuencia en la rama del H9**: si se mapean las rutas de B9-run con llaves `commercial_automation.*` **antes** de añadir el dominio a `SHARED_TOKEN_EXCLUDED_DOMAINS`, el token compartido de automatizaciones podrá leer el panel, el historial por `subjectRef` y la bandeja, y accionar la PARADA GLOBAL (D9-13 lo prohíbe). `OutreachRoutesMapGuardTest` lo detecta (ninguna ruta mapeada puede depender de una llave de ese dominio mientras no esté excluido) y cae a propósito cuando una pendiente se mapee, para que la lista se vacíe.
- **Recomendación:** en la rama, un solo commit con las 31 entradas del mapa (`informe-studio.md` §2 + `UltronStudioOutreachController::ROUTES`), el dominio en `SHARED_TOKEN_EXCLUDED_DOMAINS` y el vaciado de `PENDING_IN_H9_BRANCH` y `SHARED_TOKEN_DOMAIN_PENDING` en la guarda.

### R-3 · Media (operación) · Ni la supresión 1581 ni la rotación de `APP_KEY` conocen las tablas del H9

- **Dónde:** `docs/ultron-studio/ops/supresion-1581.sql` nombra 16 tablas, todas del H3–H7 (`ultron_studio_canary_numbers`, `…_audit_events`, `…_rules`, `…_publications`, `…_lab_*`, `…_versions`, `…_campaign_links`, `…_version_reviews`, `…_integration_snapshots`, `…_drafts`); ninguna `outreach_*`, ninguna `ultron_studio_strateg*`, ninguna `voice_*`. Las cuatro tablas con `subject_key`/`phone_ref` (`outreach_enrollments`, `outreach_jobs`, `outreach_decisions`, `outreach_outcomes`) llevan su `redacted_at` (L9-5, comprobado por `OutreachPersonalDataGuardTest`), pero nadie las suprime todavía. `SubjectRef`/`PhoneRef` derivan de `APP_KEY` (`app/Services/Outreach/Facts/SubjectRef.php`, `PhoneRef.php`) y la cadena de estrategias se firma con ella (`StrategyChainSignature::appKeyRaw`).
- **Impacto:** una solicitud de supresión de la Ley 1581 deja trazas de la persona en `outreach_*` (seudónimo + `member_id`/`lead_id` en la inscripción). Una rotación de `APP_KEY` deja **toda la cadena** `signature_mismatch` → freno global y `chain_unreadable` en todas las estrategias, y rompe el tope por teléfono (`phone_ref` histórico ya no cruza). El fallo es cerrado: nada sale; pero el gobierno queda ilegible hasta rederivar.
- **Recomendación (OPS9, D9-15, L9-4):** ampliar el script con las 4 tablas y ensayarlo en el banco (T5); runbook de rotación: PARADA GLOBAL → cerrar la cadena con la clave vieja → rotar → rederivar `subject_ref`/`phone_ref` en lote desde `subject_key` → publicar de nuevo. `OutreachPersonalDataGuardTest::test_la_supresion_1581…` cae a propósito cuando el script incorpore una tabla, para vaciar `SUPPRESSION_PENDING_OPS9`.

### M-1 · Media (frontera, B9-run) · `Run/OutreachRoutes.php` declara rutas y nombra controladores del Studio desde la ejecución

- **Dónde:** `app/Services/Outreach/Run/OutreachRoutes.php:5-6` importa `App\Http\Controllers\Crm\UltronStudio\UltronStudioOutreachController` y `…AssistedController` y declara las 13 rutas `outreach/**` para que el lead las monte en `routes/ultron-studio.php` con una línea.
- **Impacto:** no es un fallo de seguridad; es una frontera: la ejecución nombra el Studio (D9-2 solo admite el puente), y `UltronEngineIsolationTest` lo marca (3 pruebas en rojo). Además las rutas **no existen todavía** en el router (nadie llama a `register()`), así que B9-run tiene rutas, controladores y llaves sin cerradura comprobable hasta que el lead las monte y mapee.
- **Recomendación:** mover el bloque a `routes/ultron-studio.php` (donde vive el resto del Studio y donde la lista blanca ya cubre), o que el lead admita `OutreachRoutes.php` por referencias exactas (dos `use`) como hizo con el puente. Yo no lo he admitido: es decisión del lead (**Q-2**).

### M-2 · Media (frontera, B9-run) · **Decidido por el lead (L9-51):** lista cerrada de lectores de `ultron_studio_strategies` desde la ejecución

- **Decisión (Q-1, lead):** D9-2 queda enmendada: versiones, publicaciones, plantillas y vínculos **solo por el puente**; `ultron_studio_strategies` la pueden leer, solo para joins de lectura (uuid/estado), los ficheros que la guarda enumera, y la guarda falla si aparece uno más o si alguno escribe.
- **Estado al cerrar:** la lista pasó de 6 (cuando el lead decidió) a **9** mientras B9-run seguía escribiendo: `Run/{AssistedQueue, OutcomeRecorder, OutreachDispatcher, OutreachMonitor, OutreachPublishedStrategies, OutreachReadModels, OutreachReconciler, OutreachStop}` y **`app/Console/Commands/Outreach/OutreachSuppressSubjectCommand.php`** (el único fuera de `Run/`). `OutreachIsolationTest::test_los_lectores_de_las_tablas_del_studio_dentro_de_outreach_son_una_lista_cerrada` fija los 9 por ruta exacta, comprueba que ninguna sentencia de la ejecución escribe ni bloquea una tabla del Studio (`STUDIO_WRITE_IN_STATEMENT`) y que nadie nombra una tabla del Studio por su cuenta; el escuchador por tramos prueba que la ejecución no escribe el gobierno. Un décimo lector hace caer la guarda hasta que SEC9 lo revise y lo añada.
- **Nota para el lead:** si los 3 lectores nuevos (`AssistedQueue`, `OutreachReadModels`, `OutreachSuppressSubjectCommand`) no estaban en tu cuenta de seis, dilo y los saco (volvería al rojo hasta que B9-run los pase por el puente).

### B-1 · Baja · El límite por tique del canal acota reconexiones, no concurrencia; cada conexión ocupa un trabajador de PHP-FPM 55 s

- **Dónde:** `StreamRateLimit` (30/min por tique, 10/min por red); `config/ultron_studio_stream.php` (`max_seconds` 55, `poll_ms` 1500); el pool de producción tiene 24 trabajadores.
- **Impacto:** un administrador con 20 pestañas del Studio mantiene 20 trabajadores ocupados de forma continua; un tique nuevo estrena cubo (`GET admin/stream-ticket` es `SELF`). Es el mismo riesgo de los otros cinco streams del CRM: el Studio suma uno por pestaña, no cambia el orden de magnitud.
- **Recomendación (OPS9):** `limit_conn` por IP en nginx para `*/stream`; vigilar `pm.max_children` tras encender `ULTRON_STUDIO_STREAM_ENABLED`; bajar `max_seconds` si el pool sufre.

### B-2 · Baja · La puerta de diff del despliegue admite todavía la ruta vieja del canal

- **Dónde:** `docs/ultron-studio/ops/deploy-h9-backend-fase1.sh:107-111` admite en la puerta `routes/api.php` y `app/Http/Controllers/Api/Admin/UltronStudio/UltronStudioStreamController.php`; con L9-43 ninguno de los dos debe cambiar por el H9.
- **Recomendación (OPS9):** quitar esas dos entradas de `PERMITIDOS_EN_PUERTA` y `routes/api.php` de `SOLO_ANADEN`; así la puerta vuelve a decir que `routes/api.php` es intocable.

### Confirmado sin hallazgo (lectura y pruebas)

- `StudioAccess::requirePerson()` exige persona y `ultron.view` antes de buscar nada (`StudioAccess.php:78-91`); el token compartido da `personRequired` (`AdminActor::from()` → null) y el dominio `ultron` está excluido: las 30 rutas de S9 responden 403 al token compartido (`UltronStudioStrategyRoutesTest`).
- Cuatro ojos **por persona**: `StudioPersonBindings::samePerson()` (`:130`) y `OutreachStop::release()` (`:327`) comparan cuenta **o** identidad; crear/verificar/revocar un vínculo exige otra persona (`:153-155`, `:210-215`, `:250`); `bootstrap` solo por consola con autorización (`:290`). Probado con dos cuentas de la misma identidad (`OutreachFourEyesGuardTest`, `UltronStudioStrategyFourEyesTest`).
- La cadena firmada: `verifyRow` comprueba huella del mensaje, firma HMAC con el anterior, `row_mismatch` fila ↔ mensaje y la persona del mensaje (`OutreachPublishedStrategies.php`); la cabeza ilegible niega todo; `ultron_studio_forbid()` en PG rechaza `UPDATE`/`DELETE` (DB9).
- Evento SSE: lista blanca `{type,id,state,at,hash,ref}` y escalares ≤ 128 sin saltos impuesta en el constructor (`StreamEvent.php:35-75`); los temas proyectan solo ids, uuids, estados, códigos, huellas e instantes (lista cerrada en `OutreachPersonalDataGuardTest`); el libro oculta borradores ajenos sin `audit.view` (`LedgerTopic.php:86-92`); la bandera se comprueba **después** de la puerta (no revela el canal); un tema negado se omite y se anota con `admin_id` y `topic` (`UltronStudioStreamController.php:106`), coherente con «el canal no escribe» aunque el contrato decía «log de auditoría del Studio» (el lead decide qué frase manda, **Q-3**); el controlador ya vive en `Crm/UltronStudio` y la ruta en `routes/ultron-studio.php:280` (L9-43 cumplido por SSE9-b).
- Los 11 `Log::` del H9 llevan ids, uuids, códigos y `error_class`; ninguna clave de dato personal (guarda).
- Los 7 modelos del gobierno con `$guarded = ['*']`; la ejecución escribe por `DB::table` con columnas explícitas (sin modelos).
- Ninguna columna de las 15 tablas con el dato de la persona en claro; `name` es el de la estrategia (80) y `*_by_name` el del personal (patrón del libro del H5); `phone_ref`/`subject_ref` son HMAC con subclave propia y etiqueta anclada (`outreach-subject/1`, `outreach-phone/1`).
- Nada del H9 nombra Meta, el outbox de WhatsApp, `MarketingMessageDispatcher`, ElevenLabs, Twilio, OpenAI, `Http::`, `curl`, sockets, n8n, `otp.*`, `voice.twilio.*` ni la cadena del motor; de ULTRON Legacy solo `UltronAbortLatch::engaged()` desde `Facts\OperationalBrakes`.
- `OutreachSendGate::SENDS_ENABLED_IN_CODE = false` literal, una vez, declarada solo ahí y exigida por `automaticSendAllowed()`; `LIVE_CHANNELS = ['simulated','assisted']`; con todo encendido en `live`, `automaticSendAllowed()` es `false` para `whatsapp_template` y `voice_call`.

## 3. Estado de las guardas (cifras)

| Suite | Pruebas | Aserciones | Verde | Rojo | Causa del rojo |
|---|---|---|---|---|---|
| `UltronEngineIsolationTest` (editada) | 9 | ≈ 1 400 | 6 | 3 | `Run/OutreachRoutes.php` nombra 2 controladores del Studio (2 pruebas); recuento de rutas 108 ≠ 77 (L9-38) |
| `OutreachIsolationTest` | 5 | 820 | 5 | 0 | — |
| `Guards/OutreachFlagsGuardTest` | 3 | 322 | 3 | 0 | — |
| `Guards/OutreachStaticGuardsTest` | 5 | ≈ 5 900 | 4 | 1 | `Run/OutreachRoutes.php` importa controladores del Studio |
| `Guards/OutreachPersonalDataGuardTest` | 4 | ≈ 300 | 4 | 0 | — |
| `Guards/OutreachFourEyesGuardTest` | 3 | ≈ 60 | 3 | 0 | — |
| `Guards/OutreachRoutesMapGuardTest` | 3 | ≈ 120 | 3 | 0 | — |
| **Total** | **33** | **10 043** | **29** | **4** | todas «de rutas» o de la rama del H9 |

Avisos que las guardas imprimen (estado conocido, no fallos): `routes/console.php` sin bloque del H9 (L9-46); `supresion-1581.sql` sin las 4 tablas `outreach_*` (OPS9); 31 rutas sin mapa (lead, rama); `commercial_automation` fuera de la exclusión del token compartido (lead, rama); 2 ficheros nuevos de la voz del H8 sin seguimiento (`VoiceTranscriptAllowlist.php`, `VoiceTranscriptLexicon.php`: del cierre del H8, sin código del H9); 8 lectores de `ultron_studio_strategies` en `Run/`.

Lo recorrido por `OutreachIsolationTest` (en SQLite): 558 sentencias en el tramo normal y 554 en el de producción, 32 escrituras cada uno, todas en tablas del H9 (`s9` → gobierno + libro + sesión; `run` → `outreach_*` + incidentes); `facts`, `bridge` y `sse` sin una sola escritura ni `FOR UPDATE`; recuentos de 38 tablas ajenas sin cambio; `Http::assertNothingSent()` en los dos tramos y con las banderas por defecto.

## 4. Lo que NO verifiqué (y por qué)

- **PostgreSQL:** todas las pasadas son en SQLite (la suite por defecto). Las guardas de escritura y de red no dependen del motor; los CHECK y disparadores de PG los prueba DB9.
- **Mutaciones:** no hice una pasada de mutaciones de las guardas (el H8 las hizo en una copia APFS). Lo que sí comprobé es que cada guarda **cae** con el árbol tal como está cuando la frontera se cruza: `OutreachRoutes.php`, el canal antes de moverse (`Api/Admin` + `routes/api.php`), el recuento, la columna `phone_ref` (falso positivo corregido con los sufijos seguros), el tramo SSE sin eventos de voz. Pendiente para la revisión final del H9.
- **B9-run en marcha:** el barrido, el despachador, la bandeja asistida, el historial por la API y las 13 rutas no están en el recorrido de `OutreachIsolationTest` porque no tenían orden registrada ni ruta montada al cerrar esta pasada. Cuando B9-run cierre hay que añadir a `exercise()`: `outreach:sweep`, `outreach:dispatch`, `outreach:reconcile`, `outreach:replay`, `outreach:suppress-subject` y las 13 rutas (con el token compartido → 403, y la bandeja «solo para quien reclamó», T6). También la revisión de BOLA/IDOR de esas rutas (D9-13) queda para entonces.
- **W9, V9, AI9, C9:** fuera del 15-11 (P9-3); sus guardas (`MarketingMessageDispatcher` y `OutboundGate` como únicas puertas, OpenAI con `store: false`) se añaden con sus enmiendas.
- **El CRM (FE9, SSE9-f):** no revisado (otro worktree).

## 5. Q-n para el lead

- **Q-1 (D9-2):** ¿aceptas que 8 ficheros de `Run/` lean `ultron_studio_strategies` por `OutreachTables::STRATEGIES` (solo el uuid/clave; el escuchador prueba que no escriben), documentándolo como enmienda de D9-2, o pides a B9-run que lo centralice en el puente? La guarda hoy exige que todos vivan en `Run/` y los imprime; si eliges el puente, la cierro a lista exacta.
- **Q-2 (L9-36/L9-43):** `Run/OutreachRoutes.php` nombra dos controladores del Studio desde la ejecución y mantiene 3 pruebas en rojo. ¿Lo mueves a `routes/ultron-studio.php` (recomendado) o lo admito por referencias exactas en `UltronEngineIsolationTest` y en la guarda de importaciones?
- **Q-3 (SSE9):** el tema negado se anota en el log de la aplicación (`Log::notice`), no en el libro `ultron_studio_audit_events` como decía el contrato, porque «el canal no escribe». ¿Cuál de las dos frases manda? Si manda el libro, el canal pasa a escribir una tabla y la guarda `sse` de solo lectura tendría que admitir **esa** inserción y ninguna otra.
- **Q-4 (R-2):** confirmar que en la rama del H9 el mapa de las 31 rutas, la exclusión de `commercial_automation` y el vaciado de las dos listas de la guarda van en **un mismo commit**.
- **Q-5 (R-1, fuera del H9):** ¿abrimos un seguimiento para el tique de un solo uso / TTL 60 s y `Referrer-Policy` en el CRM? No bloquea la fase 1 del H9.
- **Q-6 (R-3, OPS9):** supresión 1581 de las 4 tablas y runbook de rotación de `APP_KEY` antes de la fase de sombra con datos reales.

## 6. Ficheros

Nuevos: `docs/ultron-studio/ULTRON_STUDIO_H9_AMENAZAS.md`, `tests/Feature/UltronStudio/OutreachIsolationTest.php`, `tests/Feature/Outreach/Guards/{OutreachFlagsGuardTest,OutreachStaticGuardsTest,OutreachPersonalDataGuardTest,OutreachFourEyesGuardTest,OutreachRoutesMapGuardTest}.php`, `docs/ultron-studio/plans/informes-h9/informe-security.md`. Editado: `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` (+217/−2, todo en bloques «Hito 9»; el fichero estaba limpio frente a HEAD, así que el diff entero es del H9 y va a su rama, L9-34). Nada más tocado.

Reproducir: `php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Outreach/Guards tests/Feature/UltronStudio/UltronEngineIsolationTest.php tests/Feature/UltronStudio/OutreachIsolationTest.php` y `vendor/bin/pint --test` sobre esos ficheros.
