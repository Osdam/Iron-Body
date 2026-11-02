# Hito 7 · REV7 · informe del `reviewer`

**Fecha:** 02-11-2026. **Primera pasada** (la segunda, sobre la entrega de `h7-devops-b`, va en §9 cuando el lead la pida).

**Qué se revisó:**
- **Backend:** worktree `_worktrees/ultron-h7/Iron-Body`, rama `feat/ultron-studio-h7`. Revisado sobre `ac031dd`; vale para el HEAD de cierre, `ee9f128`, que solo cambia la etiqueta de `ops/perf-h7/tramos-campanas.php` (`git diff --stat ac031dd ee9f128 -- app routes config database bootstrap tests composer.lock` sale vacío). Diff del hito `origin/main...HEAD` (`origin/main` = `bc6c0ff` = producción `1e5f9a0` + documentación).
- **CRM:** `16c433c`. `git diff origin/main...HEAD --stat` sale vacío: el H7 no cambia el CRM.
- **Fuera de esta pasada:** lo que `h7-devops-b` tiene sin confirmar en el árbol: `ops/deploy-h7-backend.sh`, `smoke-h7.py`, `smoke-run-h7.sh`, `banco-h7/{ensayar,preparar}.sh` y el nuevo `h7-monitor-scope.sh`. De esos scripts he revisado la versión CONFIRMADA en HEAD (`git show HEAD:…`).

**Lo que no he hecho:** no he tocado código, pruebas, scripts ni informes ajenos, no he entrado al VPS y no hay commit.

## 0. Veredicto

**APROBADO CON OBSERVACIONES.** No hay bloqueantes, hay un mayor y tres menores.

M-1 no obliga a cambiar el código del H7 si el lead acepta el comportamiento. Lo que sí pide antes del paso 5 es una decisión, una línea en el runbook y una lectura en producción.

| # | Severidad | Dónde | Dueño |
|---|---|---|---|
| M-1 | Mayor | `app/Rules/PlainText.php:47` (regla compartida): agenda de valoraciones y Caja | `security` (alcance) y `documentation-specialist` (runbook y guía). Decide el lead |
| m-1 | Menor | `ops/deploy-h7-backend.sh:287` y `ops/smoke-run-h7.sh:92` (en HEAD) | `devops` |
| m-2 | Menor | Estados y afirmaciones desfasados en los documentos maestros (§6) | `documentation-specialist` / lead |
| m-3 | Menor | `tests/Feature/UltronStudio/UltronEngineIsolationTest.php:74` | `tester` |

## 1. Bloqueantes

Ninguno.

## 2. Mayores

### M-1 · `PlainText` cambia también fuera del Studio, sin inventario ni aviso, y la agenda enseña un mensaje que no es la causa

**Qué cambia.** `PlainText` es una regla compartida.
- **Antes del H7** usaba `UntrustedText::isPlain`: controles, U+200B–200F, U+202A–202E, U+2066–2069 y U+FEFF.
- **Desde el H7** usa la clase de `lab-invisibles` (§3, punto 6).
- **Lo que se rechaza por primera vez:**
  - U+00AD, el guion blando;
  - U+2060–2064 y U+206A–206F;
  - U+061C, U+180E, U+0600–0605, U+06DD, U+070F, U+0890–0891, U+08E2 y U+FFF9–FFFB;
  - **U+2028 y U+2029, también en los campos de varias líneas;**
  - las etiquetas U+E0000–E007F.
- **Lo que se acepta por primera vez:** el ZWJ dentro de un emoji.
- **Comprobado en local** con la clase real, la de antes y la de ahora caso por caso (§4).

**Consumidores fuera del Studio.** El inventario de SEC7 es solo del Studio (`informe-security.md:36`). Quedan fuera:

| Fichero | Líneas | Qué valida |
|---|---|---|
| `ValuationController.php` | 98, 187, 189, 208, 224, 237, 336, 405 | Notas, motivos, búsqueda y filtro de la agenda de valoraciones, que está en producción |
| `ValuationAvailabilityController.php` | 110 | Motivo de la disponibilidad |
| `ReceiptStationController.php` | 79, 113, 115 | Nombre e impresora de la estación |
| `ReceiptAgentController.php` | 78, 80, 118 | Versión e impresora que declara el agente de impresión silenciosa: un 422 allí es `invalid_request` y no imprime. Riesgo muy bajo, porque los nombres de impresora no traen estos caracteres |

**Escenario de fallo.** Comprobado leyendo el CRM `16c433c` y probando la clase en local; no ejecutado de punta a punta:
1. Una nota de valoración guardada ANTES del H7 lleva un U+00AD o un U+2028 pegado. Entonces se aceptaba: `ValuationAgenda::texto()` solo hace `trim`.
2. Con el H7, alguien cambia el tipo de la cita. El CRM reenvía siempre `notes` (`Iron-Body_Front/src/app/modules/valuations/va-detail.ts:702`).
3. El servidor responde 422 en `errors.notes`, con «…carácter invisible o de control (U+00AD)…».
4. El CRM tira el texto del servidor y enseña `FIELD_MESSAGES['notes']`, «Las notas no pueden pasar de 1000 caracteres.» (`va-detail.ts:711-714`, `valuation-labels.ts:268`).
   - En el alta pasa lo mismo: `spanishFieldErrors()`, `valuation-labels.ts:292-297`, «nunca el del servidor».
   - En `reason` y `past_reason` el mensaje es «El motivo necesita entre 5 y 500 caracteres.».

**Resultado:** la edición queda bloqueada con un mensaje que no dice la causa, hasta que alguien reescriba la nota. No se pierden datos y no hay 500. Lo mismo pasa con un pegado nuevo.

**Dentro del Studio el efecto es el previsto y se ve:**
- el CRM enseña por campo el mensaje del servidor (`fieldErrors`);
- solo dos sitios vuelven a pasar contenido guardado por `PlainText`: el guardado del borrador (`StudioDrafts.php:87`) y la creación de versión (`StudioVersions.php:125`);
- enviar, aprobar, promover y publicar no lo hacen, así que una versión ya creada no se bloquea;
- las frases y etiquetas de `conditions` siguen con `UntrustedText` (H-6 de SEC7), sin cambios.

**La documentación dice otra cosa.** Ni `ULTRON_STUDIO_DEPLOYMENT_PLAN.md:1110` («Quién lo nota: el personal, si pega un texto…») ni `ULTRON_STUDIO_GUIA_ADMINISTRACION.md:220` («el Studio lo rechaza») nombran la agenda ni la Caja.

**Corrección.** Cualquiera de las dos; decide el lead:
- **(a) Sin tocar código.**
  - Añadir la agenda y la Caja a «Qué cambia» del runbook del H7 y a la guía.
  - Antes del paso 5, ejecutar en producción la lectura de abajo.
  - Si da 0, lo que queda son los pegados futuros con un mensaje engañoso en valoraciones. Se corrige en el CRM después del H7 (`frontend`): ante un 422 cuyo texto nombra `U+`, enseñar el del servidor.
- **(b) Acotar la clase nueva al Studio.** Por ejemplo, `new PlainText(strict: true)` en sus 9 controladores y en el normalizador, y la lista de antes por defecto (`security`/`backend`). Hay que repetir sus pruebas y las de Valoraciones y Caja.

```sql
-- SOLO LECTURA. Filas cuyo texto el PlainText del H7 rechazaría al reenviarse (U+00A0 no está en la clase).
WITH c(re) AS (SELECT '[\u00AD\u0600-\u0605\u061C\u06DD\u070F\u0890\u0891\u08E2\u180E\u2028\u2029\u2060-\u2064\u206A-\u206F\uFFF9-\uFFFB]|[\U000E0000-\U000E007F]'::text)
SELECT 'valuation_appointments.notes' AS donde, count(*) FROM valuation_appointments, c WHERE notes ~ c.re
UNION ALL SELECT 'ultron_studio_drafts.content', count(*) FROM ultron_studio_drafts, c WHERE content::jsonb::text ~ c.re
UNION ALL SELECT 'ultron_studio_rules.description', count(*) FROM ultron_studio_rules, c WHERE description ~ c.re
UNION ALL SELECT 'receipt_stations.name/printer_name', count(*) FROM receipt_stations, c WHERE coalesce(name, '') || coalesce(printer_name, '') ~ c.re;
```

**Corrección (02-11-2026, a petición del lead).** La primera versión de este bloque llevaba los caracteres de la clase LITERALES (U+00AD, U+2028, U+2029…) en lugar de escapes. Al copiarla, el U+2028 se convirtió en un espacio dentro del corchete y la expresión casaba espacios normales: falsos positivos 3/1/12/1 en producción, según el lead, que la ejecutó con los escapes. Ahora la clase va solo en ASCII, con los escapes de las ARE de PostgreSQL (barra invertida, `u` y cuatro cifras hexadecimales; `U` y ocho cifras para las etiquetas). Vuelta a comprobar en PostgreSQL 14.24 local: no casa con un espacio, ni con U+00A0, ni con «Juan Pérez, 10:00 a. m.»; casa con U+00AD, U+2028 y la etiqueta U+E0041.

Validada en local:
- la sintaxis, en PostgreSQL 14.24 (`ultron_studio_h7_rev`, en solo lectura);
- la expresión casa con U+00AD y con la etiqueta U+E0041, y no con U+00A0.

No la he ejecutado en producción.

## 3. Menores

### m-1 · `devops` · «0 tareas del Studio» pasa también cuando `schedule:list` falla

En HEAD, `ops/deploy-h7-backend.sh:287` y `ops/smoke-run-h7.sh:92` hacen `$(artisan schedule:list 2>/dev/null | grep -c 'ultron-studio:' || true)`.

**Escenario de fallo:**
1. `schedule:list` lanza. En local, sin base, lo vi lanzar una `QueryException` sobre `cache_locks`.
2. La salida queda vacía y `grep -c` imprime `0`.
3. El script da «OK, 0 tareas del Studio» sin haber mirado nada, con el monitor encendido o apagado.

**Corrección:** comprobar aparte el código de salida de `schedule:list` y exigir también el total de tareas. Es 26 con el monitor apagado, medido en local; en el servidor hay que leerlo una vez. h7-devops-b está editando los dos ficheros: conviene incluirlo en su entrega.

### m-2 · `documentation-specialist` / lead · Estados y afirmaciones desfasados

Están en §6. Ninguno cambia lo que hace el despliegue, pero cuatro documentos mandan comprobar a mano algo que el script ya garantiza.

### m-3 · `tester` · La lista blanca de aislamiento admite `routes/console.php` entero

`UltronEngineIsolationTest.php:74` admite el fichero completo. Ese fichero programa también las tareas del motor.

**Escenario de fallo:** un cambio futuro que programe un trabajo del motor condicionado a la configuración o a clases del Studio pasaría esta prueba. La puerta del despliegue solo exige que el fichero añada líneas.

**Corrección:** una excepción por cadena, solo para `ultron_studio.monitor.enabled`, como propuso DB7 (`informe-db.md:284`).

## 4. Qué verifiqué y cómo

Todo en el Mac, con PHP 8.3.33, PHPUnit 11.5.55, PCRE 10.47 y PostgreSQL 14.24 desechable en `127.0.0.1:55432`, con base propia `ultron_studio_h7_rev`.

| Comprobación | Comando | Resultado |
|---|---|---|
| Puerta del motor | `git diff 1e5f9a0 --stat -- <la lista del encargo>` | **10 ficheros**: los 7 de M-2, `AdminUserController`, `InternalMarketingKnowledgeController` y `routes/console.php` (+35/−0). Nada en `app/Services/Marketing/**` ni en `app/Jobs/**` |
| Lista `PUERTA` / `PERMITIDOS_EN_PUERTA` del script (HEAD, `:50-63`) | Lectura frente a lo anterior | Coinciden; `PREVIO=1e5f9a0` (`:41`) |
| Migración y avance rápido | `git diff --name-only --diff-filter=AMDR 1e5f9a0 HEAD -- backend/database/migrations`; `merge-base --is-ancestor` | Una sola migración, `2026_11_02_100001`; avance rápido desde `1e5f9a0` |
| UPDATE / DELETE / bloqueo de fila sobre la cadena, el libro y las instantáneas | `grep` de los tres modelos y de las tablas en `app`, `routes`, `config` y `database/seeders`. También `lockForUpdate`/`sharedLock`/`->lock(`, `FOR UPDATE/SHARE`, `upsert`/`updateOrCreate`/`updateOrInsert`/`insertOrIgnore`/`truncate`/`increment`, `$touches`, `Prunable` y SQL crudo | **Ningún camino.** Solo `forceCreate`, lecturas y modelos en memoria de las exportaciones. Los tres modelos lanzan al actualizar o borrar. Las FK que tocan la cadena son todas `restrictOnDelete`, y ninguna FK apunta al libro ni a las instantáneas: no hay acción referencial que haga UPDATE o DELETE por debajo |
| 409 `publication_moved` | Lectura de `insertEntry` (`StudioPublications.php:1336-1375`), de `conflictRefusal` (`:1466-1482`) y del disparador | `seq` = cabeza + 1 y `previous` = cabeza. Una carrera perdida pasa el disparador (D-1) y choca en el UNIQUE → 23505 → 409 |
| Predicado de H-2 del H6 | Comprobación previa frente a `uscn_one_live` | Los dos son `status <> 'revoked'` (`…integration_guards.php:248`) |
| `h7-schema-check.sql` | Lectura | Coherente con la migración y con el script: 43/49/4/14/3 → 44/50/4/14/4, función INVOKER de `iron` y privilegios uno a uno |
| Rutas | `artisan route:list --path=admin/ultron-studio --json` | **56 / 18 / 8 / 10** |
| Monitor programado | `schedule:list` con `CACHE_STORE=array`, con la bandera sin poner y con `true` | **26 → 28**; apagado, 0 del Studio. Encendido: `*/30 * * * *` el monitor y `10 12 * * *` (07:10 en Bogotá) la instantánea |
| Pruebas acotadas, SQLite | F-1, F-2, `tests/Feature/Hardening` (44), `InvisibleCharactersTest`, `StudioPlainTextInvisiblesTest`, `KnowledgeInvisiblesTest` | **OK, 159 pruebas y 1 123 aserciones** |
| Pruebas acotadas, SQLite | H7 de endurecimiento, tope temprano ×2, `verified`, monitor ×2, esquemas H5/H6, aislamiento, lector y sello del motor, `ReceiptAgentSecurityTest` | **OK, 378 pruebas y 3 281 aserciones**, 12 omitidas (solo PG) |
| Pruebas acotadas, PG | `UNIT_DB=ultron_studio_h7_rev pgunit.sh`: endurecimiento H7, `verified`, monitor ×2, invisibles del conocimiento | **OK, 54 pruebas y 587 aserciones** |
| Oráculo de integraciones (F-2) | `python3 -I tests/fixtures/ultron-studio/tools/integrations-cases-check.py --php --tw --impl` | **722 casos, 0 discrepancias, «RESULTADO: OK»** |
| Referencia de F-1 | `git show d629202:…UltronCanaryReport.php`, `turnos()` frente a la copia de la prueba | Idéntica, salvo `private` → `protected` y el contador `llamadas++`. `d629202` = `1e5f9a0` en ese fichero |
| Clase de invisibles | Constantes por reflexión; recorrido de los 1 112 064 puntos de código | `INVISIBLE_CLASS` y `PICTO` **idénticas** a las del laboratorio. La clase son 265 puntos: los 264 de SEC7 más el ZWJ |
| Pint | `vendor/bin/pint --test` sobre los 52 PHP del hito | Fallan 3 (`MarketingInboxController.php`, `bootstrap/app.php`, `routes/console.php`) **con los mismos fixers que su versión de `origin/main`**: es previo, no del H7 |
| Sintaxis de los scripts (HEAD) | `bash -n` y `python3 -I -m py_compile` sobre copias en el scratchpad | OK: `deploy-h7-backend.sh`, `smoke-run-h7.sh`, `smoke-h7.py` e `ironbody-migrate-propuesta.sh` |
| CRM y `verified` | `readPublicationEntry()` (`studio-parse.ts:542-574`) | No exige claves exactas: la clave aditiva se ignora |
| CRM y H-2 | `auth.interceptor.ts:52-67` | Un 403 `admin_token_invalid` cierra la sesión y lleva al login |
| Regresión del H6 sobre el H7 (paso 8) | Lectura de `smoke-h5.py`/`smoke-h6.py` | Ni invisibles (ni literales ni escapados), ni cuerpos de más de 64 KB, ni `Cache-Control`, ni claves exactas del historial: no debería verse afectada. Leído, **no ejecutado** |

**Comparación de la regla, antes y después** (`violation()` frente a `UntrustedText::isPlain`):

| Texto | Antes | H7 |
|---|---|---|
| U+00A0 (espacio duro) y U+202F | Se acepta | Se acepta |
| U+00AD (guion blando) | Se acepta | **Se rechaza** |
| U+2060 y U+180E | Se acepta | **Se rechaza** |
| U+2028 en un campo de varias líneas | Se acepta | **Se rechaza** |
| Bandera de subdivisión (etiquetas) | Se acepta | **Se rechaza** |
| U+200B, ZWJ suelto | Se rechaza | Se rechaza |
| Familia con ZWJ, arcoíris con FE0F y ZWJ | Se rechaza | **Se acepta** |
| Corazón con FE0F, tecla con FE0F + U+20E3 | Se acepta | Se acepta |

## 5. El encargo, punto por punto

### 1. Migración y REVOKE

- **Aditiva:** sin tablas ni columnas, sin tocar filas, y la función de F no cambia.
- **Transaccional:** una sola transacción, y en SQLite la abre ella.
- **No es idempotente en sentido estricto:** `CREATE TRIGGER` y `ADD CONSTRAINT` van sin `IF NOT EXISTS`. Repetir `up()` sin `down()` fallaría con 42710 sin dejar nada. Laravel no la repite y la reanudación del script es segura (la compuerta 44/50 hace `break`). No es un hallazgo.
- **`down()`:** se niega con filas y nunca borra.
- **El REVOKE:** verificado en PG con un rol real, en las pruebas H7.
- **Ningún camino de la aplicación hace UPDATE o DELETE en las tres tablas** (§4).
- **El 409 se conserva.**
- **`h7-schema-check.sql`:** coherente.

### 2. `no-store`

- Va global y antepuesto. Solo afecta a `api/admin` y `api/admin/*`, que se comparan sin prefijo laxo.
- `AdminNoStoreCacheTest` cubre: CSV y XLSX (`download()` respondía `public`), el SSE con su `Content-Type`, la verificación previa de CORS, 401, 403, 404 y 413. Fuera de `/api/admin` no cambia nada (`/up` y `legal`).
- La única caché intencionada dentro de `/api/admin` era la de los SSE, `no-cache`. `max-age=300` de los adjuntos y `max-age=3600` de `face-image` están FUERA de `/api/admin` y no cambian.
- Las descargas del CRM van por `HttpClient` en blob: no dependen de la caché.
- El ajuste de `ReceiptAgentSecurityTest` es legítimo: esa aserción solo comprobaba que un modo NO llevara `no-store`.

### 3. Tope temprano

- Solo actúa en `api/admin/ultron-studio` y `api/admin/ultron-studio/*`. Todo lo demás delega en `ValidatePostSize` tal cual.
- Todas las rutas del Studio ya tenían `LimitRequestBodySize:65536` (`routes/ultron-studio.php:90`): ningún cuerpo legítimo cambia.
- No hay rutas del Studio en otros ficheros.
- Fotos, importaciones, Factus, inventario, mercadeo y lectores no pasan por la rama nueva.

### 4. Topes de M-2

Los dos topes nuevos no pueden quedarse por debajo de lo que envía el CRM `16c433c`:
- **Exportaciones:** el CRM manda un subconjunto de `ds.columns` (`modules/exports.ts:411, 558`). Una lista más larga ya era un 422 por `distinct` y la lista blanca.
- **Cargos del personal:** las casillas se marcan y desmarcan sin repetir (`staff-form-dialog.ts:326-327`); el estado inicial viene de los cargos del servidor (`:303`).

En las otras 6 rutas el tope ya existía. El trait solo cambia el orden de la validación, y no lanza `LogicException` con ninguno de los 7 juegos de reglas: un nivel de comodín, todos con tope.

### 5. Restablecer la contraseña

- Usa el atributo correcto, `auth_admin_session` (`EnsureAdminAuth.php:65, 86`), y `active()`.
- Va en la misma transacción que la contraseña y la traza.
- La resolución de sesiones no tiene caché (`AdminSessionService.php:46-52`).
- La traza solo lleva el recuento.
- El CRM saca a la cuenta afectada (punto 5 de §4).

### 6. Invisibles

**La clase son exactamente 265 puntos de código** (PCRE 10.47):
- U+0000–0008, U+000B–000C, U+000E–001F y U+007F–009F;
- U+00AD;
- U+0600–0605, U+061C, U+06DD, U+070F, U+0890–0891 y U+08E2;
- U+180E;
- U+200B–200F, U+2028–202E, U+2060–2064 y U+2066–206F;
- U+FEFF y U+FFF9–FFFB;
- U+110BD, U+110CD, U+13430–1343F, U+1BCA0–1BCA3 y U+1D173–1D17A;
- U+E0000–E007F.

El ZWJ (U+200D) solo pasa dentro de un emoji. En una sola línea se añaden `\t`, `\n` y `\r`.

**U+00A0 NO está en la clase**, como tampoco U+202F, U+2007, U+3000, U+034F, U+115F, U+3164 y U+FE0F. **U+00AD SÍ.** El riesgo de los textos legítimos ya guardados es M-1.

**La API interna** limpia antes de validar y de buscar la `key`, registra sin el contenido, añade `cleaned` (aditivo) y convierte un UTF-8 inválido en `?` en lugar de un 500 (probado en PG). **En producción** queda la sonda PCRE 10.39 del paso 9 del runbook.

### 7. Monitor

- **Con la bandera apagada:** 0 tareas. **Encendida:** 28 en total, cada 30 minutos.
- `--dry-run` no anota nada (`test_con_dry_run_solo_lee` y MON-05).
- Fuente `ultron_studio`. `routes/console.php` +35/−0.
- **Solo lee:** `state()`, `providers()`, `overview()` y `snapshot()`, sin escrituras ni red.
- **Sin notificaciones externas:** `IncidentRecorder` solo escribe en la base y en `ChannelLog`.
- **Antes de encenderlo:** H-1 de OPS7 (`schedule:run` como root). Está en el paso 10 del runbook.

### 8. F-1 y F-2

**F-1 conserva la semántica:**
- los PRIMEROS T turnos desde `--since`, cortando en el entrante T+1;
- `max(1, T)`;
- los empates con `??=` en orden de id y por las dos vías;
- el cierre en `PHP_INT_MAX`, exclusivo;
- `==` en la dirección;
- mensajes y acciones ordenados por id (`UltronCanaryReport.php:73, 79`).

El atajo de `mensajeQueNombra()` es correcto: solo un escape `\u` puede producir esa clave. Que `turnos()` pase a `protected` no abre nada: nadie más lo extiende.

**F-2:** el único consumidor de `dto` es `campaignsPage()` (`StudioCampaignLinks.php:136`), y el oráculo queda en verde.

### 9. Puerta del motor

Coincide con el contrato y con el script (§4).

### 10. Scripts (versión de HEAD)

**`deploy-h7-backend.sh` cubre las trampas de siempre:**
- `git` como root con `umask 022` (`:27-28`);
- `www-data` lee cada fichero del merge (`:214-224`);
- `route:cache` como `www-data` con `umask 077`, y el `chmod` lo hace `www-data` y nunca sobre un enlace (`:267-274`);
- respaldo de menos de 2 h, con al menos 226 tablas, en 700/600 y con `.sha256` (`:157-166`);
- 13 workers (`:195-196` y `:292-294`);
- `ironbody-migrate` con `PGOPTIONS` y 3 intentos (`:236-252`);
- `api-commit.txt` tiene que ser `PREVIO` (`:157-160`);
- nunca imprime valores del fichero de entorno.

**`ironbody-migrate-propuesta.sh`:**
- H-5 corregido: `chmod` como `www-data` y rechazo de enlaces (`:218-226`);
- un fichero de entorno escribible lo rechaza (`:117-122`).

**`supresion-1581.sql` (HEAD):**
- en seco por defecto: `BEGIN READ ONLY` y `ROLLBACK`;
- nunca DELETE, solo UPDATE de redacción y un INSERT en el libro;
- apaga solo los disparadores de UPDATE con una función conocida, dentro de la transacción;
- antes del COMMIT comprueba que vuelven a estar todos activos.

**ROLLBACK H7:** el nivel 3 solo hace `checkout` si producción sigue en el commit de la fase, y el esquema del H7 con el código del H6 funciona (OPS7 13/13; no lo he repetido). Lo que falla es m-1.

### 11. Documentación

Las cifras de §4 cuadran con el código. Discrepancias y marcadores, en §6.

## 6. Discrepancias entre los informes y lo observado, y `[PENDIENTE]` que quedan

### 6.1 Afirmaciones que ya no son ciertas en HEAD (m-2)

| Dónde | Dice | Realidad |
|---|---|---|
| `ULTRON_STUDIO_ROLLBACK.md:276`, `DEPLOYMENT_PLAN.md:1156` y `:1255` (con su `[PENDIENTE]` para `devops`), `CHECKLIST_OPERATIVO.md:127`, `FINAL_REPORT.md:761` | El paso 0 de `deploy-h7-backend.sh` acepta en una reanudación un `api-commit.txt` con el commit nuevo (`:157-161`) | Corregido en `1c76a7f`: `:157-160` solo aceptan `PREVIO` |
| `DEPLOYMENT_PLAN.md:1130`, `CHECKLIST_OPERATIVO.md:101`, `FINAL_REPORT.md:742`, `MASTER_ROADMAP.md:101` (C-1), `BACKEND_REPORT.md:1061-1062`, `GUIA_ADMINISTRACION.md:132` | F-1 y F-2 «en curso». La guía dice además que la evidencia tarda ~1 s con 200 turnos y ~4 s con 1 000 | Hechos en `e2411e3`, con medida en `informe-backend.md` (p95 70 ms y 96 ms), evidencia en `ac031dd` y las 6 pruebas nuevas en verde (§4) |
| `DEPLOYMENT_PLAN.md:1129`, `CHECKLIST_OPERATIVO.md:100`, `FINAL_REPORT.md:741`, `BACKEND_REPORT.md:1084` | Fase B de TEST7 «en curso» | Hecha (`informe-tester.md`, `df0140f`). Lo que falta es la fase C sobre el HEAD final |
| `ROLLBACK.md:261`, `SECURITY_REPORT.md:474`, `FINAL_REPORT.md:694` | HEAD `5ed9525` | HEAD `ee9f128`. Desde `5ed9525` el código cambió en F-1 y F-2 (`e2411e3`) |
| Cifra «189 pruebas nuevas» (TEST7) | — | Se midió sobre `1c76a7f`. Con F-1 y F-2 hay 6 más (4 + 2, según `informe-backend.md`) |
| `informe-security.md:36` y `DEPLOYMENT_PLAN.md:1110` | El cambio de `PlainText` es del Studio | Afecta también a la agenda y a la Caja (M-1) |
| `informe-devops-h7.md:71` | `PREVIO=pendiente` | Describe el estado anterior a `1c76a7f`; hoy es `1e5f9a0`. Relacionado: el T-1 del tester (`banco-h7/preparar.sh` espera `pendiente`), que `h7-devops-b` está corrigiendo |

### 6.2 `[PENDIENTE]` legítimos, por dueño

| Dueño | Marcadores |
|---|---|
| Lead | Verificación de las 24 h del H6 (`DEPLOYMENT_PLAN.md:1127`, `CHECKLIST_OPERATIVO.md:98`); todo lo posterior al despliegue (`DEPLOYMENT_PLAN.md:1321`, `ROLLBACK.md:261`, `FINAL_REPORT.md:778`, `MASTER_ROADMAP.md:205`, `GUIA_ADMINISTRACION.md:32` y `:218`); D-F14 (`VISUAL_REPORT.md:606`) |
| `reviewer` | `DEPLOYMENT_PLAN.md:1128`, `CHECKLIST_OPERATIVO.md:99`, `FINAL_REPORT.md:743`: este informe |
| `tester` | Fase C sobre el HEAD final. Cómo enseña cada formulario el 422 de invisibles en el navegador (`VISUAL_REPORT.md:679`, `:709`) |
| `devops` | Presupuestos de PERF7 en el smoke (`DEPLOYMENT_PLAN.md:1277`); el smoke del H6 contra el H7 en el banco (`:1285`); el mando del monitor (`:1307`, `h7-monitor-scope.sh` en curso); la sección «Supresión» del informe a la que remite el SQL (`GUIA_ADMINISTRACION.md:214`); lecturas en producción de `iron_app` en READ COMMITTED y de `opcache.validate_timestamps` (`DEPLOYMENT_PLAN.md:1037-1038`, `CHECKLIST_OPERATIVO.md:154-155`) |
| Propietario | Clave de OpenAI del laboratorio: rotarla y fijar un límite de gasto (`GUIA_ADMINISTRACION.md:147`; B-1) |

## 7. Pendientes del propietario: completos

Están en `MASTER_ROADMAP.md`:

| Código | Qué |
|---|---|
| A-6 | `log_error_verbosity = terse` |
| B-1 | Clave de OpenAI del laboratorio y límite de gasto |
| B-2 | `ironbody-migrate` sin root |
| B-4 | `schedule:run` en el crontab de `www-data` (H-1 de OPS7) |
| B-5 | Dependencias |
| B-6 | Twilio: credencial restringida y rotación de la maestra |
| B-7 | Comprobaciones del servidor |
| B-8 | Meta |
| B-13 | Topes de producto de M-2 y `no-store` fuera de `/api/admin` |
| C-2 | F-3, F-4 y F-5 |
| C-3 | H-7 de OPS7 |
| C-6 | m-1…m-4 del CRM (reviewer del H6) |
| C-7 | H-4, H-6 y H-8 de SEC7 |

H-6 de OPS7 (`--no-owner`) está corregido en `BACKUPS.md`: ahora restaura con dueños y ACL, así que el REVOKE sobrevive a una restauración.

**Falta añadir:** la decisión de M-1.

## 8. Lo que NO verifiqué, y por qué

- **Producción y el VPS:** quedan fuera por el encargo. En particular, que el `ironbody-migrate` de hoy (como root) deje pasar `PGOPTIONS` (N-10 de `security`, sin cerrar: `SECURITY_REPORT.md:311`).
  - El H7 es la migración que pide ACCESS EXCLUSIVE sobre la cadena.
  - Con la llave del motor en `off`, el motor no la lee, y el paso 0 exige 0 cerrojos.
  - Si el envoltorio no la pasara, una transacción larga sobre la cadena haría esperar a la migración sin límite. Hay que vigilarlo en el paso 3.
- **Las mutaciones** (TEST7 53/53, DB7 14/14, F-1 13/13, F-2 4/4) y los bancos de OPS7 (despliegue, smoke, recuperación 21/21, vuelta atrás 13/13): no las he repetido. He leído sus informes y he visto que las pruebas que las cazan están en verde.
- **Las suites completas, la puerta PG entera y el E2E:** son de la fase C del tester; no las he duplicado.
- **El PCRE 10.39 de producción:** no está en local.
- **El CRM en un navegador con el backend del H7.**
- **La consulta de M-1 en producción.**
- **La entrega en curso de `h7-devops-b`:** es la segunda pasada.

**Para limpiar después (mío):** la base desechable `ultron_studio_h7_rev` en `127.0.0.1:55432` y los ficheros del scratchpad de la sesión.

## 9. Segunda pasada (`h7-devops-b`)

[PENDIENTE: cuando el lead la pida (reviewer).]
