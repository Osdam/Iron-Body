# Hito 7 · TEST7 (`tester`): informe

**Fecha:** 01/02-11-2026 (las horas, en UTC). **Rama:** `feat/ultron-studio-h7`, worktree `_worktrees/ultron-h7`. **Backend:** HEAD `8f70d18`, el WIP de PERF7: solo toca `ops/perf-h7/**` y su informe. `git diff --stat fbb1901 8f70d18 -- app routes config bootstrap database tests composer.lock` sale vacío, así que el código es el de `fbb1901`. **CRM:** `16c433c`. **Sin commit.**

**Entorno:**
- Mac local, PHP 8.3.33, PHPUnit 11.5.55, SQLite en memoria y PostgreSQL 14.24 desechable en `127.0.0.1:55432`. En PG, solo mis bases: `ultron_studio_h7_t`.
- 8 núcleos y 8 GB, compartidos con PERF7, que medía a la vez. Por eso todo lo de la fase A fue un solo proceso PHPUnit cada vez y nunca a la vez que otro mío.

**Estado:** fase A hecha (§2). La fase B (§3) espera el «GO REGRESIÓN» del lead.

## 0. En una pantalla

| Punto | Resultado real |
|---|---|
| A1 · Mutaciones de lo nuevo del H7 | **53 mutantes distintos, 53 mueren.** La 1.ª tanda dejó **5 vivos**, caminos que ninguna prueba ejercitaba: la deriva de la publicación y la de la configuración autorizada en el monitor, una comprobación que revienta (×2) y la lectura del flujo del tope temprano. Los cerré con **2 pruebas nuevas `*H7*`** (5 pruebas, 86 aserciones), que también cazan 2 mutantes nuevos. El árbol compartido no se mutó nunca (§1) |
| A2 · Legacy y suites del motor con la llave en `off` y en `all` | **5 011/5 011 en las dos llaves**, con los mismos nombres y 0 omitidas (215 s y 195 s). Acta del canario 32/32, vigía 32/32, `InternalSecretAuthoritySurfaceTest` 12/12 |
| A3 · Puerta PG del grupo del Studio | **2 799/2 799, 0 omitidas** (8 min 03 s), con `UNIT_DB=ultron_studio_h7_t`: el Studio entero, Hardening, el lector del motor y el sello. El rojo de OPS7 que vio B7 (`jsonb`) sale en verde. Mis 5 pruebas nuevas también pasan en PG |
| B4 · `suite-compare.sh` contra `origin/main` | Pendiente del «GO REGRESIÓN» |
| B5 · CRM: `ng test`, `tsc`, `ng build` | Pendiente |
| B6 · E2E de todas las specs | Pendiente |
| B7 · Recuperación con OPS7 | Pendiente |

## 1. Método: instantáneas aisladas, nunca el árbol compartido

En el árbol compartido trabajaba PERF7, midiendo latencias sobre ese mismo código. Por eso ninguna mutación ni ninguna suite corrió allí:
- **`clean/` y `mut/`**, en el scratchpad de la sesión: `git archive 8f70d18 backend` con `vendor/` **copiado** (no enlazado, por la trampa 4 del E2E) y el fichero de entorno de pruebas. Cada copia tiene su propio `storage/`: dos PHPUnit de Marketing no se pisan el disco `whatsapp`.
- **El arnés de mutaciones** (`mutate.py`, en el scratchpad) hace, por cada mutante:
  - exige que el texto original aparezca **exactamente una vez** y lo sustituye;
  - pasa `php -l`;
  - corre las pruebas de la protección con `--log-junit` y saca de ahí los nombres que caen;
  - **restaura desde `clean/` y lo compara byte a byte**; si no coincide, aborta todo.
- **Al final:** `diff -rq` entre `mut/` y `clean/` sale idéntico. `git status` del árbol compartido solo enseña `ops/perf-h7/**` (PERF7) y mis dos pruebas nuevas.
- **La llave del motor en una corrida entera** se fija en el entorno del proceso (`MARKETING_ULTRON_STUDIO_CONFIG`). Las pruebas del H5 la fijan por prueba (`config()->set('marketing.ultron.studio_config', …)`); las que no la fijan heredan la del proceso.
  - Una sonda (`ScopeProbeH7Test`, fuera del repo) demuestra que la aplicación la ve: `all`, `off`, `canary`, `ALL` y `" all "` → su valor; `bogus` y sin variable → `off`.
  - La sonda **falla** si lo esperado no casa (`all` frente a `off` esperado: rojo).

## 2. Fase A

### 2.1 Mutaciones sobre lo nuevo del H7

**Controles sin mutar** (todos en verde antes de mutar):

| Grupo | Resultado |
|---|---|
| monitor | 12 pruebas, 135 aserciones |
| no-store + tope | 8 pruebas, 206 aserciones |
| restablecer contraseña | 4 pruebas, 22 aserciones |
| invisibles | 109 pruebas, 689 aserciones |
| `verified` | 6 pruebas, 78 aserciones |
| M-2 | 6 pruebas, 30 aserciones |
| monitor + H7 | 16 pruebas, 214 aserciones |
| tope + H7 | 9 pruebas, 213 aserciones |

**Tandas:**

| Tanda | Mutantes | Mueren | Sobreviven | Tiempo |
|---|---|---|---|---|
| 1 · monitor, no-store, tope temprano, restablecer contraseña, invisibles, `verified` | 46 | 41 | **5** | 198 s |
| 2 · los 5 vivos + 2 nuevos, con las pruebas de TEST7 | 7 | **7** | 0 | 24 s |
| Muestra de M-2: los dos topes nuevos y una ruta con el trait (B7 solo mutó `iron-ai/chat`) | 5 | 5 | 0 | 72 s |

**Cada mutante y la prueba que lo cazó:**

| Id | Mutación | Resultado | La cazó |
|---|---|---|---|
| MON-01 | `routes/console.php`: la bandera se ignora y siempre se programa | Muere | `UltronStudioMonitorTest::test_con_la_bandera_apagada_no_se_programa_nada_del_studio` |
| MON-02 | `config`: sin la variable, la bandera vale `true` | Muere | `…test_la_bandera_solo_se_enciende_con_true_y_la_expectativa_vacia_es_la_de_por_defecto` |
| MON-03 | `config`: `(bool)` de la cadena (`off` y `no` encienden) | Muere | la misma |
| MON-04 | una deriva no abre incidente | Muere (3) | cabeza que no verifica, integraciones distintas, expectativa mal escrita |
| MON-05 | `--dry-run` escribe incidentes | Muere | `…test_con_dry_run_solo_lee` |
| MON-06 | con `--json` sale 0 aunque no cuadre | Muere (5) | las 5 que esperan 1 |
| MON-07 | la deriva declarado≠leído de la cadena se ignora | **Vive** → muere en la tanda 2 | `UltronStudioMonitorDriftAndFailuresH7Test::test_la_deriva_entre_lo_declarado_y_lo_que_lee_el_motor_…` |
| MON-08 | la cabeza que no verifica se ignora | Muere (3) | cabeza que no verifica, `--dry-run`, paridad con los comandos |
| MON-09 | las colas abren incidente propio | Muere | `…test_un_carril_sin_worker_sale_con_1_sin_incidente_propio` |
| MON-10 | una comprobación que revienta tumba el comando | **Vive** → muere | `…H7Test::test_una_comprobacion_que_revienta_no_tumba_a_las_demas_ni_deja_su_mensaje` |
| MON-11 | el error lleva el mensaje (puede llevar SQL) en vez de la clase | **Vive** → muere | la misma |
| MON-12 | la deriva de la publicación baja a severidad media | Muere | `…test_una_cabeza_que_no_verifica_abre_un_incidente…` |
| MON-13 | el monitor cada hora (fuera de la ventana de 60 min de IRON GUARD) | Muere | `…test_con_la_bandera_encendida_el_monitor_cada_30_minutos…` |
| MON-14 | la deriva de la configuración autorizada no cuenta | **Vive** → muere | `…H7Test::test_la_deriva_de_la_configuracion_autorizada_…_sin_secretos` |
| MON-15 | un incidente que no se puede anotar revienta el comando (nuevo) | Muere | `…H7Test::test_si_el_incidente_no_se_puede_anotar_lo_dice_y_sale_con_1` |
| MON-16 | el fallo al anotar sale con el mensaje en vez de la clase (nuevo) | Muere | la misma |
| NS-01 | sin `prepend` del middleware | Muere (2) | `AdminNoStoreCacheTest::test_las_respuestas_…_van_con_no_store`, `UltronStudioEarlyBodyCapTest::test_el_413_lleva_cors_y_no_store_…` |
| NS-02 | `append` en vez de `prepend` (no ve el CORS previo ni el 413 temprano) | Muere (2) | las mismas |
| NS-03 | solo en respuestas 2xx | Muere (2) | las mismas |
| NS-04 | `no-store` en todas las rutas | Muere | `AdminNoStoreCacheTest::test_fuera_de_la_api_administrativa_no_cambia_nada` |
| NS-05 | `no-cache` en vez de `no-store` | Muere (2) | las de NS-01 |
| NS-06 | prefijo laxo `api/admin*` (alcanza `/api/administracion`) | Muere | `…test_fuera_de_la_api_administrativa_no_cambia_nada` |
| VPS-01 | sin `replace` de `ValidatePostSize` | Muere (4) | 4 de las 5 de `UltronStudioEarlyBodyCapTest` |
| VPS-02 | `Content-Length` con `>=` (64 KB exactos rechazados) | Muere | `…test_sin_content_length_o_con_uno_que_miente…` (justo en el tope → 422) |
| VPS-03 | cuerpo JSON con `>=` | Muere | la misma |
| VPS-04 | el JSON se fía de `Content-Length` | Muere | la misma |
| VPS-05 | lectura del flujo sin el `+1` (un cuerpo no JSON nunca supera el tope) | **Vive** → muere | `UltronStudioEarlyBodyCapStreamH7Test::test_un_cuerpo_que_no_es_json_y_sin_longitud_…` |
| VPS-06 | no delega en `ValidatePostSize` | Muere | `…test_fuera_del_studio_no_cambia_nada_y_post_max_size_sigue_igual` |
| VPS-07 | prefijo laxo `ultron-studio*` | Muere | la misma |
| AU-01 | restablecer sin revocar | Muere (3) | `AdminPasswordResetRevokesSessionsTest`: cierra las demás, se vuelve a entrar, la propia |
| AU-02 | sin conservar la sesión con la que se actúa | Muere | `…test_quien_restablece_su_propia_contrasena_conserva_la_sesion…` |
| AU-03 | sin `->active()`: toca las cerradas y las caducadas | Muere | `…test_restablecer_cierra_las_demas_sesiones…` |
| AU-04 | la revocación antes de la transacción, fuera de ella | Muere | `…test_si_el_restablecimiento_no_se_guarda_no_se_cierra_ninguna_sesion` |
| AU-05 | motivo `logout` en vez de `password_reset` | Muere | `…test_restablecer_cierra_las_demas_sesiones…` |
| AU-06 | la traza no cuenta las sesiones | Muere (2) | cierra las demás, la propia |
| IC-01 | la clase sin `\p{Cf}` | Muere (52) | `InvisibleCharactersTest`, `StudioPlainTextInvisiblesTest`, `KnowledgeInvisiblesTest` |
| IC-02 | se admite el ZWJ suelto | Muere (11) | `…test_el_zwj_suelto_se_rechaza_y_se_limpia`, el motivo de Legacy con un invisible |
| IC-03 | ZWJ tras un emoji y seguido de letra admitido | Muere (3) | `…test_el_zwj_suelto_se_rechaza_y_se_limpia` |
| IC-04 | la clase sin las etiquetas U+E0000–E007F | Muere (3) | rango a rango, la exhaustiva frente a `lab-invisibles` y la deriva de la clase |
| PT-01 | `PlainText` de una línea admite saltos | Muere | `StudioPlainTextInvisiblesTest::test_la_regla_en_una_linea_y_en_varias` |
| KN-01 | la API interna no limpia | Muere (6) | `KnowledgeInvisiblesTest` |
| KN-02 | solo se limpia `content` | Muere (2) | el gemelo de la `key` y el título/metadata |
| KN-03 | `metadata` sin limpiar | Muere | `…test_el_titulo_y_las_cadenas_de_metadata_tambien_se_limpian` |
| KN-04 | el registro lleva el texto | Muere | `…test_el_contenido_con_invisibles_se_guarda_limpio_…_sin_el_texto` |
| VER-01 | `verified` siempre `true` | Muere (5) | `UltronStudioPublicationVerifiedTest`: las 4 manipulaciones y la cabeza tocada |
| VER-02 | `verified` de cada entrada = el de la **cabeza** | Muere (5) | `…test_una_entrada_tocada_por_dml_sale_verified_false_y_solo_ella` (×4) y la cabeza |
| VER-03 | el detalle de la versión sin verificar | Muere (4) | `…_y_solo_ella`, en `detalle()` |
| VER-04 | el historial no lee el texto firmado | Muere (6) | todas |
| M2-01 | exportaciones sin la primera fase | Muere | `ExportColumnsListSizeTest::test_una_lista_por_encima_…_solo_por_el_tope` |
| M2-02 | exportaciones: tope = columnas − 1 | Muere (2) | las dos de `ExportColumnsListSizeTest` |
| M2-03 | personal (alta): una sola fase | Muere | `WorkforceStaffPositionsListSizeTest::…_solo_por_el_tope` |
| M2-04 | personal (alta): tope = catálogo − 1 | Muere | `…test_todo_el_catalogo_cabe…` |
| M2-05 | disponibilidad de valoraciones: una sola fase | Muere, en 57 s: la validación expandió de verdad las 10 000 reglas | `ValuationAvailabilityListSizeTest::…_solo_por_el_tope` |

**Por qué vivían los 5 y qué los cubre ahora.** Eran huecos de las pruebas, no del código: con el código de HEAD las pruebas nuevas pasan, y fallan contra cada mutante.
- **MON-07.** La prueba de OPS7 solo provoca `head_invalid` (una firma falsa). Nunca hay una cabeza que verifica y un motor que lee otra cosa, y la de paridad compara con `publication-check` solo en ese caso.
  - La nueva publica en canario por la API y cambia el cobro en el entorno.
  - Espera: `problems = ['drift']`, la cabeza verifica, `driftParams = ['rules.paymentLinksEnabled']` y paridad con `publication-check`.
  - Espera también el incidente `publication_drift` alto con el título de deriva, y sin `declared`/`engine`, el id canario, la huella ni el uuid.
- **MON-14.** Nunca se reconocía una configuración para cambiarla después.
  - La nueva sigue el camino `never_authorized → authorized` (reconocida por HTTP con las llaves del Super Admin del H6), y después cambia el servicio de Verify.
  - Espera: `problems = ['config_drift:twilio']` y `mismatches = []`, porque la expectativa del doctor sigue cuadrando.
  - Espera también el incidente `integrations_drift` alto, sin el token, los SID ni sus colas de cuatro cifras.
- **MON-10 y MON-11.** Ninguna prueba hacía reventar una comprobación.
  - Como `StudioPublications` es `final`, la nueva ata en el contenedor un cierre que lanza una excepción con un mensaje centinela (`SQLSTATE… host=… password=…`). No usa un error SQL: en PostgreSQL envenenaría la transacción de la prueba (25P02).
  - Espera: las tres comprobaciones se hacen, `check_failed` con `error = RuntimeException`, en seco ningún incidente, y de verdad `monitor_check_failed` medio.
  - El centinela no aparece en la salida JSON, en la de texto, en la evidencia ni en el título.
- **VPS-05.** La prueba de B7 mandaba el cuerpo no JSON a una ruta que existe, donde el tope de la ruta da el mismo 413 byte a byte.
  - La nueva usa una ruta inexistente bajo el prefijo, sin `Content-Length` y sin sesión: 65 537 bytes → 413 con `no-store`; 300 000 → 413; 65 536 → 404.
  - Impacto real bajo: `TrimStrings` no recorre un cuerpo no JSON. Pero el límite exacto del flujo ya está atado.
- **MON-15 y MON-16** (mutantes nuevos sobre el mismo patrón). Con IRON GUARD sin poder anotar, `IncidentRecorder` es un doble que lanza:
  - espera `incident = not_recorded`, `incidentError = RuntimeException`, código 1 y ningún incidente;
  - el centinela no sale.

**Las pruebas nuevas:** SQLite 5/5, 86 aserciones. Pint `--test`: passed. En PG van dentro de la puerta (§2.3).

### 2.2 Compatibilidad con ULTRON Legacy (SQLite)

Las suites del motor son:
- `tests/Feature/Marketing` (2 155) y `tests/Unit/Marketing` (2 829);
- las 4 del Studio que leen el motor o lo vigilan (27): `UltronEngineIsolationTest`, `UltronStudioEngineReadOnlyTest`, `UltronStudioEngineReadingsTest` y `UltronStudioLabNoEngineEffectsTest`.

En `clean/`, con la llave en el entorno del proceso:

| Llave | Comando | Resultado | Duración |
|---|---|---|---|
| `off` | `MARKETING_ULTRON_STUDIO_CONFIG=off php -d memory_limit=2G vendor/bin/phpunit --do-not-cache-result --log-junit … <rutas>` (04:44:30–04:48:05) | **OK, 5 011 pruebas, 25 796 aserciones**, 0 omitidas | 215 s |
| `all` | lo mismo con `all` (04:48:05–04:51:20) | **OK, 5 011 pruebas, 25 796 aserciones**, 0 omitidas | 195 s |

- Los dos ficheros de nombres son idénticos (`cmp`) y los de rojos, vacíos.
- Con `all` y la cadena vacía, el motor se comporta como Legacy en toda la suite. Es el estado de producción hoy, y la cadena ya lleva las guardas del H7: la suite migra con `2026_11_02_100001`.

**Lo que pedía el contrato, por clase** (idéntico en las dos llaves):

| Clase | Pruebas | Aserciones |
|---|---|---|
| Acta del canario (`UltronCanaryReportTest`) | 32 | 82 |
| Vigía (`UltronTurnWatchdogTest`: 29 métodos y 4 casos de proveedor) | 32 | 82 |
| `InternalSecretAuthoritySurfaceTest` | 12 | 47 |
| `UltronPublishedConfigTest` (lector del motor) | 217 | 331 |
| `UltronCommitStudioStampTest` (sello) | 30 | 226 |
| `UltronEngineIsolationTest` | 7 | 1 106 |
| `UltronStudioEngineReadOnlyTest` | 6 | 2 316 |
| `UltronStudioEngineReadingsTest` | 9 | 94 |
| `UltronStudioLabNoEngineEffectsTest` | 5 | 546 |

### 2.3 Puerta PG del grupo del Studio

```text
cd <clean>/backend && PGGSSENCMODE=disable UNIT_DB=ultron_studio_h7_t bash docs/ultron-studio/ops/pgunit.sh --do-not-cache-result \
  --log-junit … tests/Feature/UltronStudio tests/Unit/UltronStudio tests/Feature/Hardening \
  tests/Unit/Marketing/UltronPublishedConfigTest.php tests/Feature/Marketing/UltronCommitStudioStampTest.php
→ OK (2799 tests, 32172 assertions)   Time: 08:02.993 (04:51:35–04:59:41), 0 omitidas
```

- **El alcance** es la unión de los dos que pasaron los paquetes: el de B7 (`Feature/UltronStudio` + `Unit/UltronStudio`, 2 503) y el de DB7 (sus 20 ficheros, incluidos el lector del motor y el sello).
- **Además:** Hardening (44) y mis 5 pruebas nuevas. Cuenta: 2 503 + 5 + 44 + 217 + 30 = 2 799.
- **En PG no se omite nada:** las 106 «solo PG» que SQLite salta, corren aquí.

**Lo nuevo del H7, en PG:**

| Clase | Pruebas | Aserciones |
|---|---|---|
| `UltronStudioMonitorTest` (el rojo de B7 por el orden de claves de `jsonb`, corregido por OPS7) | 12 | 135 |
| `UltronStudioMonitorDriftAndFailuresH7Test` (TEST7) | 4 | 79 |
| `UltronStudioEarlyBodyCapStreamH7Test` (TEST7) | 1 | 7 |
| `UltronStudioHardeningH7Test` (DB7) | 25 | 250 |
| `UltronStudioPublicationVerifiedTest` | 6 | 78 |
| `UltronStudioEarlyBodyCapTest` | 5 | 156 |
| `tests/Feature/Hardening` (no-store, H-2, M-2, `TrustProxies`, F) | 44 | 219 |
| `UltronStudioPublicationRaceTest` (las 38 carreras del H5) | 38 | 2 762 |
| `UltronStudioIntegrationsRaceTest` (las 37 del H6) | 37 | 1 079 |
| Esquema H5 / H6 | 13 / 22 | 162 / 473 |
| `UltronPublishedConfigTest` | 217 | 331 |
| `UltronCommitStudioStampTest` | 30 | 226 |
| `UltronEngineIsolationTest` | 7 | 1 106 |

Las carreras del H5 dan 2 762 aserciones; DB7 anotó 2 763. El número depende de qué intento gana cada carrera: las pruebas son las mismas 38 y todas verdes.

## 3. Fase B

Pendiente del «GO REGRESIÓN» del lead (PERF7 necesita antes la máquina tranquila).

## 4. Rojos y su causa

Fase A: **ninguno** en las suites. Los 5 mutantes vivos eran huecos de prueba, ya cubiertos (§2.1).

## 5. Lo que sigue sin cubrir, y por qué

- **El monitor contra una base caída de verdad** (PDO sin conexión) en la suite: la prueba nueva lo simula con una excepción en la resolución del servicio. La caída real de PostgreSQL la ensayó OPS7 en el banco para el commit del motor, no para el monitor. Se puede repetir en la fase B7 si el lead lo quiere.
- **El tope temprano con `multipart/form-data`:** PHP parsea el cuerpo antes de Laravel, así que el tope solo lo ve por `Content-Length`. No lo he probado.

## 6. Ficheros

- `tests/Feature/UltronStudio/UltronStudioMonitorDriftAndFailuresH7Test.php` (nuevo, 4 pruebas)
- `tests/Feature/UltronStudio/UltronStudioEarlyBodyCapStreamH7Test.php` (nuevo, 1 prueba)
- `docs/ultron-studio/plans/informes-h7/informe-tester.md` (este)

Fuera del repo, en el scratchpad de la sesión: las instantáneas, `mutate.py` y sus tres especificaciones, la sonda `ScopeProbeH7Test`, `engine-scopes.sh` y los registros y junit de cada corrida.
