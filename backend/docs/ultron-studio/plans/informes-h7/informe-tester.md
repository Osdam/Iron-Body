# Hito 7 · TEST7 (`tester`): informe

**Fecha:** 01/02-11-2026 (las horas, en UTC). **Rama:** `feat/ultron-studio-h7`, worktree `_worktrees/ultron-h7`. **CRM:** `16c433c`. **Sin commit** de esta versión final.

**Backend en la fase A:** HEAD `8f70d18`, el WIP de PERF7, que solo toca `ops/perf-h7/**` y su informe. `git diff --stat fbb1901 8f70d18 -- app routes config bootstrap database tests composer.lock` sale vacío, así que el código es el de `fbb1901`.

**Entorno:**
- Mac local, PHP 8.3.33, PHPUnit 11.5.55, SQLite en memoria y PostgreSQL 14.24 desechable en `127.0.0.1:55432`. En PG, solo mis bases: `ultron_studio_h7_t` y las de mis bancos (§6).
- 8 núcleos y 8 GB, compartidos con PERF7, que medía a la vez. Por eso todo lo de la fase A fue un solo proceso PHPUnit cada vez y nunca a la vez que otro mío.

**Estado:** fases A (§2), B (§3) y C (§3bis) hechas. Ningún rojo nuevo en ninguna suite.
- **La fase C** repite las cuatro corridas tras F-1/F-2 (`e2411e3`), con el mismo código hasta `0d43401`.
- **T-1, hallazgo de herramientas** (§3.4): el banco de OPS7 no podía ensayar el guion de despliegue de `1c76a7f`. Las cifras de recuperación salen de un clon con el mismo código. Pasó a `h7-devops-b`, que lo corrigió en `0d43401`.
- **Limpieza hecha** (§6).

**Refs al cerrar:**

| Paso | Ref | Nota |
|---|---|---|
| Rama del backend al cerrar la fase B | `1c76a7f` | Desde `1426130` entraron PERF7, DOC7, el cierre de la fase 1 del H6 y `1c76a7f` (PREVIO del guion de despliegue), **sin cambios de código ni de pruebas** |
| B4 | `1426130` | |
| B6 | `5ed9525` | |
| B7 | `5ed9525`, más un ensayo extra con `1c76a7f` | |
| **Fase C** | `e2411e3` | Código de F-1/F-2. Hasta `0d43401` solo entra ops y documentación |
| C1 y C2 | `ac031dd` | |
| C3 | `ee9f128` | |
| C4 | `0d43401` | |

## 0. En una pantalla

| Punto | Resultado real |
|---|---|
| A1 · Mutaciones de lo nuevo del H7 | **53 mutantes distintos, 53 mueren.** La 1.ª tanda dejó **5 vivos**, caminos que ninguna prueba ejercitaba: la deriva de la publicación y la de la configuración autorizada en el monitor, una comprobación que revienta (×2) y la lectura del flujo del tope temprano. Los cerré con **2 pruebas nuevas `*H7*`** (5 pruebas, 86 aserciones), que también cazan 2 mutantes nuevos. El árbol compartido no se mutó nunca (§1) |
| A2 · Legacy y suites del motor con la llave en `off` y en `all` | **5 011/5 011 en las dos llaves**, con los mismos nombres y 0 omitidas (215 s y 195 s). Acta del canario 32/32, vigía 32/32, `InternalSecretAuthoritySurfaceTest` 12/12 |
| A3 · Puerta PG del grupo del Studio | **2 799/2 799, 0 omitidas** (8 min 03 s), con `UNIT_DB=ultron_studio_h7_t`: el Studio entero, Hardening, el lector del motor y el sello. El rojo de OPS7 que vio B7 (`jsonb`) sale en verde. Mis 5 pruebas nuevas también pasan en PG |
| B4 · `suite-compare.sh` contra `origin/main` de hoy (`1e5f9a0`) | **0 rojos nuevos.** Rama 12 587 pruebas y base 12 399, con 57 = 57 rojos idénticos por nombre (Factus, contratos, IVA y el artefacto de la instantánea). 15 min 57 s + 15 min 38 s |
| B5 · CRM `16c433c` | **`ng test`: 3 312/3 328, solo los 16 de la línea base** (los mismos 5 ficheros y nombres), en 74 s. `tsc` de la app y de las specs: salida 0. **`ng build` de producción: salida 0** (195 ficheros, 0 mapas, 0 patrones de secretos) |
| B6 · E2E de todas las specs | **660/660 en 1 174 s**: F 40, G 25, H4 27, turno 30, H5 276, h6 250 y cierre 12. **Valla vacía; 0 de 12 secretos** en 23 registros y 12 tablas; 0 violaciones en los 4 simuladores |
| B7 · Recuperación con OPS7 | **Recuperación 21/21** (PG caído 11/11 y worker muerto 10/10). **Vuelta atrás 13/13.** Despliegue con 55P03 real y su reintento, smoke del H7 22/22, smoke del H6 78 + 26 en la vuelta. **T-1:** el banco no monta con el guion de despliegue de HEAD (`preparar.sh` espera `PREVIO=pendiente`). Pasó a `h7-devops-b`, que lo corrigió en `0d43401` (OPS7-bis); no lo he vuelto a ensayar |
| **C · tras F-1/F-2 (`e2411e3`; mismo código hasta `0d43401`)** | **C1** puerta PG + acta/vigía/superficie: **2 881/2 881**, 0 omitidas (7 min 10 s). **C2** motor con `off` y `all`: **5 015/5 015** cada una (202 s y 202 s). **C3** `suite-compare.sh` contra `1e5f9a0`: **57 = 57, 0 nuevos** (12 593 frente a 12 399). **C4** E2E: **660/660** en 1 043 s, con la valla vacía y 0 secretos |

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

## 3. Fase B (tras el «GO REGRESIÓN», 05:06 UTC; de una en una)

### 3.1 B4 · Suite completa del backend por nombre (`suite-compare.sh`)

**Base: `1e5f9a0`**, `origin/main` del mismo día. Lo decidió el lead.
- `origin/main` pasó de `2660173` a `1e5f9a0` el 02-11 a las 04:53 UTC, con el H6 fusionado.
- `1e5f9a0` es `cac4d5b` más dos commits de documentación y ops, sin código ni pruebas. `cac4d5b` está dentro del HEAD del H7, así que la comparación aísla lo que añade el H7.
- El H6 ya se había comparado contra `2660173` (0 rojos nuevos): sin tercer lado.

**Rama:** `1426130`, que ya lleva mis dos pruebas, así que sin `SUITE_EXTRA`.

```text
bash backend/docs/ultron-studio/ops/suite-compare.sh <scratchpad>/suite-1426130-vs-1e5f9a0 1e5f9a0 1426130   (SQLite)
[rama] 1426130 05:06:14–05:22:12  Tests: 12587, Assertions: 103099, Errors: 55, Failures: 2, Skipped: 133   (15 min 57 s)
[base] 1e5f9a0 05:22:18–05:37:56  Tests: 12399, Assertions: 101657, Errors: 55, Failures: 2, Skipped: 129   (15 min 38 s)
rojos en la rama: 57 · en la base: 57 · NUEVOS: 0          → exit 0
```

**Los 57 rojos** son idénticos por nombre (`cmp`) y conocidos:

| Rojos | Causa |
|---|---|
| 29 · `MemberContractTest` | Falta la plantilla PDF de `storage/`, un fichero ignorado |
| 26 · Billing | Factus y emisión con credenciales locales: `FiscalReconciliationTest` 12, `FactusPayloadNoVatTest` 10, `ProductionEmissionGuardTest` 2 y `EmissionBlockedTest` 2 |
| 1 · `PricingV2SalesAndPaymentsTest` | El IVA |
| 1 · `SecretExposureTest::test_git_never_tracks_environment_files_with_secrets` | La instantánea no es un repo git |

**Diferencias de inventario:**
- **188 pruebas más en la rama:** 189 nuevas menos 1 renombrada. Todas son del H7:
  - SEC7: `InvisibleCharactersTest` 91, `StudioPlainTextInvisiblesTest` 11 y `KnowledgeInvisiblesTest` 7;
  - DB7: `UltronStudioHardeningH7Test` 25;
  - OPS7: `UltronStudioMonitorTest` 12;
  - B7: `PublicationVerified` 6, `EarlyBodyCap` 5 y `Hardening` 26;
  - TEST7: 5;
  - el renombrado de DB7.
- **La que ya no existe:** `UltronPublishedConfigTest::test_la_cabeza_es_la_de_mayor_seq_aunque_no_sea_la_ultima_insertada`. Es el renombrado que decidió el lead (informe DB7 §8): en la rama es `…_aunque_no_tenga_el_mayor_id` y está en verde.
- **4 omitidas más (133 frente a 129):** las 4 solo-PG de `UltronStudioHardeningH7Test`, que corren en la puerta PG (§2.3).

**Durante la fase B se movieron el HEAD y `origin/main`, sin cambiar código:**

| Ref | Ahora | Qué añade | Código y pruebas |
|---|---|---|---|
| HEAD | `1426130` → `5ed9525` → `1c76a7f` | PERF7 completo (`d629202`), la fusión del cierre de la fase 1 del H6 en producción (`bc6c0ff`), DOC7 (`ea937a2`) y el `PREVIO` del guion de despliegue (`1c76a7f`): solo documentación y ops | Idénticos (`git diff --stat 1426130 1c76a7f -- app routes config bootstrap database tests composer.lock phpunit.xml .env.example` vacío) |
| `origin/main` | `bc6c0ff` | Solo documentación sobre `1e5f9a0` | Idénticos |

Por eso B4 vale para el HEAD final (`1c76a7f`), y su base para el `origin/main` de hoy. El E2E (§3.3) corrió sobre `5ed9525`, con el mismo código. El cambio de `1c76a7f` en el guion de despliegue es el origen de T-1 (§3.4).

### 3.2 B5 · CRM `16c433c`

El worktree estaba limpio en `16c433c` y sigue limpio al terminar. El build se hizo en una copia aislada (`git archive 16c433c` con `node_modules` enlazado, como el E2E), para no tocar el `dist/` compartido.

| Comando | Resultado | Duración |
|---|---|---|
| `node_modules/.bin/ng test --watch=false` (worktree) | **176 ficheros: 171 en verde y 5 rojos · 3 328 pruebas: 3 312 en verde y 16 rojas** | 74 s (05:38:17–05:39:31) |
| `node_modules/.bin/tsc -p tsconfig.app.json --noEmit` | Salida 0, sin mensajes | 7 s |
| `node_modules/.bin/tsc -p tsconfig.spec.json --noEmit` | Salida 0, sin mensajes | 10 s |
| `node_modules/.bin/ng build --configuration production` (copia aislada) | **Salida 0.** 195 ficheros, 0 source maps, paquete inicial de 818,85 kB (169,56 kB transferidos) | 40 s |

**Los 16 rojos son exactamente la línea base,** por fichero y por nombre:

| Fichero | Rojos | Ejemplos |
|---|---|---|
| `access-control.moderation` | 6 | «Administrativo puede revisar y asignar, nada más», «Administrador modera pero no ejecuta acciones ELEVADAS», «la matriz cubre exactamente los cuatro roles del backend»… |
| `auth.moderation-menu` | 7 | |
| `auth.guard.moderation` | 1 | «Administrador entra» |
| `financial-realtime.integration` | 1 | «CAJA + listado montados juntos comparten UNA conexión…» |
| `receivables-pagination` | 1 | «un movimiento de dinero recarga la página sin cambiar de módulo» |

Ninguno es nuevo. El CRM no cambia en el H7: es el del final del H6.

**Los 36 avisos del build** son los de siempre:
- 28 presupuestos de estilos de componente, entre ellos `ultron-studio.scss` con 27,88 kB (el F-4 de PERF7);
- 1 del paquete inicial;
- 7 NG8107 de encadenado opcional.

**Barrido de patrones de secretos en el build** (solo recuentos, sin imprimir valores): `sk-…`, `AC…` y `SK…` de 32 hex, `EAA…`, `base64:` largo, `-----BEGIN`, `AKIA…`, `xi-api-key`, `DB_PASSWORD` y `APP_KEY`. Todos dan **0**.

### 3.3 B6 · E2E de todas las specs

```text
cd Iron-Body_Front && bash e2e/ultron-studio/run.sh 5ed9525 16c433c          (sin lista: el orden por defecto)
ejecución: ~/.cache/ironbody-e2e/ultron-studio/run-20261102T054102Z-5ed9525-16c433c   05:41:02–06:00:40
TOTAL 660/660 · 1174 s según el arnés (1178 s de pared) · salida 0
```

| Spec | Resultado | Tiempo |
|---|---|---|
| `f-versions` | 40/40 | 14 s |
| `g-inspector-compare` | 25/25 | 8 s |
| `h4-lab-fake` | 27/27 | 13 s |
| `ultron-turn` | 30/30 | 5 s |
| `h5-governance` | 276/276 | 131 s |
| `h6-integrations` | 250/250 | 674 s |
| `cierre` | 12/12 | 1 s |

**La valla y los secretos:**
- La valla caza su sonda al arrancar («la sonda https quedó registrada y bloqueada»). Después, **ningún intento de salir en toda la ejecución**.
- **Secretos: ninguno de los 12 aparece en 23 registros ni en 12 tablas.**
- Los cuatro simuladores (OpenAI, n8n, Twilio y ElevenLabs) dan **0 violaciones**.
- `laravel.log` sin ERROR, `failed_jobs` vacío y ninguna cola con trabajo.
- El detector de fugas de `h5-governance` caza las 4 fugas sembradas.
- Al salir no queda ningún puerto escuchando.

**Comparación con el H6:** mismas comprobaciones que la pasada final del H6 (660/660 con `2ac7f54`/`8536c0e`), ahora con el backend del H7.
- Para lo que el E2E ve, el H7 es transparente: el disparador de la cadena, el `REVOKE` (no se aplica en local: no hay `iron_app`), `PlainText` con la clase completa, `no-store`, el tope temprano y el monitor apagado.
- Ninguna spec mira `Cache-Control`, el 413 ni `verified`. Eso lo cubren la suite (§2.1) y el smoke del H7 en el banco (§3.4).
- `h6-integrations` tardó 674 s frente a los 1 253 s del H6: la spec solo espera lo que queda del enfriamiento de la sonda desde su última instantánea.

### 3.4 B7 · Recuperación de errores con OPS7 (`ops/banco-h7`)

**Hallazgo T-1 (para OPS7 y el lead; bloquea el banco con el HEAD de hoy).** Con el HEAD `1c76a7f` el banco no se puede montar.
- **Qué pasa:** `ensayar.sh desplegar` → `preparar.sh` sale con «`PREVIO=pendiente` aparece 0 veces en `deploy-h7-backend.sh` (esperado 1): el script cambió».
- **Causa:** `1c76a7f`, del lead, fijó `PREVIO=1e5f9a0 # HEAD de producción…` en el guion. La regla `preparar.sh:33` (`('PREVIO=pendiente', f'PREVIO={previo}', 1)`) busca el texto antiguo.
- Falla en voz alta, como está diseñado, pero desde `1c76a7f` el banco ya no puede ensayar el guion de despliegue actual. Primer intento registrado en `banco/intento-1/`.
- **Corrección propuesta** (OPS7; yo no toco sus ficheros): que la regla sustituya la línea `PREVIO=` sea cual sea su valor, por ejemplo con una expresión `^PREVIO=.*$` que exija una sola coincidencia.

**Cómo saqué las cifras sin tocar nada de OPS7:**
- Un clon local independiente (`git clone` del repo, en el scratchpad) en **`5ed9525`**, con el `vendor/` copiado.
- Entre `5ed9525` y `1c76a7f` el código es idéntico (`git diff --stat` de `app routes config bootstrap database` vacío). Solo cambian `ops/README.md` y las dos líneas del guion: `PREVIO` y la regla de `api-commit.txt` (m-6).
- Las herramientas del banco son las de OPS7 sin modificar; son las mismas que en `fa6eb81`.
- Bancos propios: `~/.cache/ironbody-banco-h7-test7` (base `ultron_studio_banco_h7_test7`, puerto 8177) y `…-vuelta` (`…_vuelta`, puerto 8178). La recuperación levanta su clúster en 55440; el 55432 no se paró.

**Recuperación** (README: montar → API → sembrar → `desplegar --55p03` → smoke → `sembrar-1581` → `ensayo-recuperacion`):

| Paso | Resultado | Duración |
|---|---|---|
| Montar + desplegar `--55p03` | Intento 1: `2026_11_02_100001 … 5s FAIL` (55P03 por el cerrojo). Reintento a los 15 s: `6.86ms DONE`. Esquema 44/50/4/14, 0 ERROR en `laravel.log` y **`DESPLIEGUE H7 (BACKEND) COMPLETADO`** | 57 s |
| Smoke del H7 (`ensayar.sh smoke`) | **22 OK · 0 FALLO · 0 OMITIDO**: `no-store`, 413 sin sesión y con sesión, `verified` = `signatureOk`, invisibles → 422 con sus contrapruebas, sin fugas. El lanzador da 14 tablas idénticas, 0 incidentes y el token muerto (403) | 2 s |
| `sembrar-1581` | Cadena 1, libro 3, instantánea 1, canarios 2 y laboratorio 1 | <1 s |
| **`ensayo-recuperacion.sh`** | **21 OK · 0 FALLO** (A 11/11 y B 10/10) | 45 s (06:04:45–06:05:30) |

**Detalle de A.** PostgreSQL cae durante el commit del motor, con la llave en `canary`:

| Caso | Resultado |
|---|---|
| Con PG arriba | `legacy` en 5 ms |
| PG caído | **`fallback/read_failed` en 4 ms, sin excepción** |
| La escritura del commit con PG caído | Falla (`QueryException`): lo que no es del Studio no se tapa |
| PG vuelve | El MISMO proceso reconecta: `legacy` en 7 ms, y escribe |
| Cadena bloqueada 3 s, como la migración | La lectura **espera 2 498 ms** y sella `legacy` |
| Conexión terminada a mitad | Laravel reconecta (conexión 21194 → 21241) y repite: `legacy` a los 19 504 ms |
| La lectura siguiente | 1 ms |
| Registro | 1 aviso `read_failed` en el canal, con el motivo y la clase, sin el mensaje |

**Detalle de B.** El worker del laboratorio muere con `kill -9`:
- **Camino 1:** sin caso reclamado → `completed · done,done` en 8 s (1 trabajo fallido).
- **Camino 2:** con el caso reclamado → `completed · error/worker_lost,done` en 4 s.
- **Confirmo el H-7 de OPS7:** con el worker muerto y el trabajo reservado, el monitor da `problemas []`.

Las cifras coinciden con las de OPS7: 11/11, 10/10, `read_failed` en milisegundos, 2,5 s de espera con el cerrojo y unos 19,5 s en la reconexión a mitad (OPS7 anotó 19 510 ms).

**Vuelta atrás** (banco RECIÉN desplegado: montar → API → sembrar → desplegar → `ensayo-vuelta`):

| Paso | Resultado | Duración |
|---|---|---|
| Montar + desplegar | **`DESPLIEGUE H7 (BACKEND) COMPLETADO`** | 35 s |
| **`ensayo-vuelta.sh`** | **`VUELTA ATRÁS EN EL BANCO: 13/13 OK`** | 14 s (06:07:26–06:07:40) |

El primer intento de `ensayo-vuelta` paró en su primera comprobación, sin escribir nada. Busca `h6-seed.php` en `$WT/../Iron-Body_Front/…`, y mi clon no tiene ese hermano. Es entorno mío, no un fallo. Lo relancé sobre el mismo banco con `BANCO_SEED` apuntando al `h6-seed.php` del CRM `16c433c`; la variable la admite el propio guion.

Los 13 casos:

| Nivel | Caso | Resultado |
|---|---|---|
| 2 | Monitor encendido (`true` + `config:cache`) | 2 tareas del Studio |
| 2 | Una pasada real sobre el banco sano | 0 y ningún incidente |
| 2 | Monitor apagado otra vez | 0 tareas |
| 2 | Fichero de entorno | Idéntico al de antes |
| 3 | Código del H6 (`7d2c6d8`) sobre el esquema del H7 | 56 rutas, 44/50 intacto |
| 3 | Vuelve el comportamiento del H6 | `no-cache, private`, y 70 000 bytes sin sesión → 401 |
| 3 | `publication-check` del H6 sobre la cadena del H7 | 0 |
| 3 | **Smoke del H6 de la fase 1** | **78 OK + 26 OK, 0 FALLO, 0 OMITIDO** |
| 3 | Volver a Legacy con el H6 | 409 `already_legacy` sin escribir |
| 3 | La cabeza | Verifica |
| 3 | De vuelta al H7 | `no-store` y 413 temprano otra vez |
| 3 | Smoke del H7 | 22 OK |
| 4 | `migrate:rollback` de la migración del H7 con filas | **Se niega**: filas 0 0 3 → 0 0 3, esquema 44/50 |

OPS7 anotó unos 12 min para este ensayo; aquí fueron 14 s con la máquina tranquila, y los dos smokes corrieron enteros (recuentos arriba).

**Extra: el guion de despliegue de HEAD (`1c76a7f`, con m-6) en el banco.**
- **Montaje:** un tercer banco (`…-m6`, base `ultron_studio_banco_h7_test7_m6`, puerto 8179) desde un clon en `1c76a7f`.
- **El único cambio** es la regla de `PREVIO` de **la copia de `preparar.sh` del clon**: `PREVIO=pendiente` → `PREVIO=1e5f9a0`, es decir, T-1 corregido en local. Así se ensaya la lógica del guion de producción.

| Paso | Resultado |
|---|---|
| `--solo-comprobar` | **`COMPROBACIONES PREVIAS EN VERDE`** |
| `desplegar --55p03` | Intento 1 `5s FAIL` (55P03) → `6.09ms DONE`, 44/50/4/14, 0 ERROR y **`DESPLIEGUE H7 (BACKEND) COMPLETADO`** (41 s) |
| La regla nueva de `api-commit.txt` | Se cumple: el respaldo del banco lleva el PREVIO del banco |
| Smoke del H7 | **22 OK · 0 FALLO** |

Conclusión: el guion de HEAD funciona. Lo que falla es solo la herramienta del banco (T-1).

Al terminar quedan parados los APIs de los tres bancos (8177, 8178 y 8179) y el clúster de 55440, que la trampa del ensayo borra.

## 3bis. Fase C: regresión tras F-1/F-2 (`e2411e3`)

**Qué cambió:** `e2411e3` confirmó el F-1 (el acta del canario en una sola pasada) y el F-2 (las campañas de Meta) de PERF7:
- código: `UltronCanaryReport.php`, `StudioCampaignLinks.php` y `StudioMetaCampaigns.php`;
- pruebas nuevas: `UltronCanaryReportSinglePassH7Test` (4) y `UltronStudioCampaignsPageH7Test` (2).

**Refs.** El HEAD avanzó durante la fase C sin cambiar código (`git diff --stat` de `app routes config bootstrap database tests composer.lock phpunit.xml` vacío entre `e2411e3`, `ac031dd`, `ee9f128`, `0d43401` y `f73555d`):

| Ref | Qué añade | Dónde se usó |
|---|---|---|
| `ac031dd` | Evidencia de PERF7 | Instantánea de C1 y C2 |
| `ee9f128` | La etiqueta de un tramo del arnés de PERF7 | Rama de C3 |
| `0d43401` | OPS7-bis (con T-1) y el informe del reviewer | Backend de C4 |
| `f73555d` | DOC7 en su segunda pasada y el m-1 del reviewer en ops | HEAD al cerrar |

Mismo orden que la fase B y de una en una. El banco (`banco-h7/`) no se tocó: T-1 es de `h7-devops-b`.

### C1 · Puerta PG del grupo del Studio + acta, vigía y superficie interna

```text
cd <instantánea ac031dd>/backend && PGGSSENCMODE=disable UNIT_DB=ultron_studio_h7_t bash docs/ultron-studio/ops/pgunit.sh --do-not-cache-result --log-junit … \
  tests/Feature/UltronStudio tests/Unit/UltronStudio tests/Feature/Hardening tests/Unit/Marketing/UltronPublishedConfigTest.php \
  tests/Feature/Marketing/UltronCommitStudioStampTest.php tests/Feature/Marketing/UltronCanaryReportTest.php \
  tests/Feature/Marketing/UltronCanaryReportSinglePassH7Test.php tests/Feature/Marketing/UltronTurnWatchdogTest.php \
  tests/Feature/Marketing/InternalSecretAuthoritySurfaceTest.php
→ OK (2881 tests, 32600 assertions)   Time: 07:09.909 (15:58:15–16:05:25), 0 omitidas
```

Cuenta: las 2 799 de la fase A + `CampaignsPageH7` 2 + acta 32 + acta de una pasada 4 + vigía 32 + superficie interna 12 = **2 881**.

En PG:

| Clase | Pruebas | Aserciones |
|---|---|---|
| `UltronCanaryReportTest` | 32 | 82 |
| `UltronCanaryReportSinglePassH7Test` | 4 | 180 |
| `UltronTurnWatchdogTest` | 32 | 82 |
| `InternalSecretAuthoritySurfaceTest` | 12 | 47 |
| `UltronStudioCampaignsPageH7Test` | 2 | 35 |
| `UltronStudioIntegrationsCampaignLinkTest` | 9 | 166 |
| Paridad de integraciones con el oráculo de 722 casos (`StudioIntegrationsParityTest` + `…ParityHttpTest`) | 411 + 466 | |
| Lo nuevo del H7 de la fase A (monitor, TEST7, DB7) | | Igual que en §2.3 |

### C2 · Suites del motor con la llave en `off` y en `all` (SQLite, instantánea `ac031dd`)

| Llave | Resultado | Duración |
|---|---|---|
| `off` | **OK, 5 015 pruebas, 25 976 aserciones**, 0 omitidas | 202 s (16:05:45–16:09:07) |
| `all` | **OK, 5 015 pruebas, 25 976 aserciones**, 0 omitidas | 202 s (16:09:08–16:12:30) |

- La sonda confirma la llave antes de cada corrida.
- Mismos nombres en las dos llaves (`cmp`) y ficheros de rojos vacíos.
- Son las 5 011 de la fase A más las 4 del acta de una pasada (+180 aserciones).
- **Por clase, idéntico en las dos llaves:** acta 32/32 (82), acta de una pasada 4/4 (180), vigía 32/32 (82) y `InternalSecretAuthoritySurfaceTest` 12/12 (47).

### C3 · `suite-compare.sh` contra `1e5f9a0`

En un directorio nuevo y en SQLite:

```text
bash backend/docs/ultron-studio/ops/suite-compare.sh <scratchpad>/suite-ee9f128-vs-1e5f9a0 1e5f9a0 ee9f128
[rama] ee9f128 16:12:55–16:29:15  Tests: 12593, Assertions: 103314, Errors: 55, Failures: 2, Skipped: 133   (16 min 18 s)
[base] 1e5f9a0 16:29:20–16:45:15  Tests: 12399, Assertions: 101657, Errors: 55, Failures: 2, Skipped: 129   (15 min 55 s)
rojos en la rama: 57 · en la base: 57 · NUEVOS: 0          → exit 0
```

- **Los 57 rojos** son idénticos a los de la base y a los de la rama de la fase B, por nombre (`cmp`): Factus, contratos, IVA y el artefacto de la instantánea.
- **195 pruebas más en la rama:** las 189 de la fase B y las 6 de F-1/F-2. La que ya no existe es el mismo renombrado de DB7.
- **Omitidas:** las mismas 133 y 129.

### C4 · E2E (backend `0d43401`, CRM `16c433c`)

- **El backend.** El HEAD avanzó a `0d43401` antes de lanzar: OPS7-bis (con la corrección de T-1 en `preparar.sh`) y el informe del reviewer, solo ops y documentos. El código es el mismo que en C1–C3 (`git diff --stat ee9f128 0d43401 -- app routes config bootstrap database tests composer.lock phpunit.xml .env.example` vacío).
- **El alcance.** El lead pidió `h6-integrations` + cierre, y H5 si cabía. Corrí la lista por defecto entera: F, G, H4 y el turno cuestan menos de un minuto más, y así el total se compara con el de la fase B.

```text
cd Iron-Body_Front && bash e2e/ultron-studio/run.sh 0d43401 16c433c
ejecución: ~/.cache/ironbody-e2e/ultron-studio/run-20261102T164523Z-0d43401-16c433c   16:45:23–17:02:49
TOTAL 660/660 · 1043 s según el arnés (1046 s de pared) · salida 0
```

| Spec | Resultado | Tiempo |
|---|---|---|
| `f-versions` | 40/40 | 27 s |
| `g-inspector-compare` | 25/25 | 10 s |
| `h4-lab-fake` | 27/27 | 15 s |
| `ultron-turn` | 30/30 | 5 s |
| `h5-governance` | 276/276 | 124 s |
| `h6-integrations` (incluye las campañas del F-2) | 250/250 | 766 s |
| `cierre` | 12/12 | 2 s |

**La valla y los secretos:**
- **Ningún intento de salir en toda la ejecución.**
- **Secretos: ninguno de los 12 aparece en 23 registros ni en 12 tablas.**
- 0 violaciones en los simuladores: OpenAI, n8n, Twilio (11 peticiones, solo GET) y ElevenLabs (5 GET, ninguna llamada).
- Al salir no queda ningún puerto escuchando.

**Resumen de la fase C: 0 rojos nuevos en las cuatro corridas tras F-1/F-2.** El acta del canario en una sola pasada y las campañas no rompen nada de Legacy ni del Studio:
- la puerta PG: 2 881/2 881;
- el motor: 5 015/5 015 con las dos llaves;
- la suite completa: 57 = 57;
- el E2E: 660/660.

## 4. Rojos y su causa

No relajé ninguna comprobación, no marqué ninguna omitida y no adapté ninguna prueba al código.

| Dónde | Rojos | Causa |
|---|---|---|
| Fase A | **Ninguno** en las suites | Los 5 mutantes vivos eran huecos de prueba, ya cubiertos (§2.1) |
| B4 | 57 en la rama = 57 en la base, idénticos por nombre | Conocidos y ajenos al H7: Factus y emisión por credenciales locales (26), la plantilla PDF ignorada de `MemberContractTest` (29), el IVA de PricingV2 (1) y el artefacto de la instantánea, que no es un repo git (1). **Nuevos: 0** |
| B5 | 16 | Los de la línea base del CRM, por fichero y nombre. **Nuevos: 0** |
| B6 | Ninguno | |
| B7 | Ninguno en los ensayos | Ver T-1 |
| C1–C4 | 57 = 57 en C3 (los mismos de B4); ninguno en C1, C2 y C4 | **Nuevos: 0** |

**T-1 (herramientas, no código):**
- `ops/banco-h7/preparar.sh:33` buscaba `PREVIO=pendiente`, que `1c76a7f` cambió por `PREVIO=1e5f9a0`. Con ese HEAD el banco no montaba: `ensayar.sh desplegar` salía antes de tocar nada.
- Propuse sustituir la línea `PREVIO=` sea cual sea su valor. Con esa corrección en una copia, el guion de `1c76a7f` desplegó en el banco y pasó el smoke (§3.4).
- **Lo asignó el lead a `h7-devops-b`.** Según su commit `0d43401` (OPS7-bis): «`preparar.sh` sustituye `PREVIO=` con cualquier valor (T-1); despliegue H7 en el banco COMPLETADO con 55P03 reintentado».
- Yo no lo he vuelto a ensayar: el lead pidió no tocar `banco-h7/`.

## 5. Lo que sigue sin cubrir, y por qué

1. **El monitor con PostgreSQL caído de verdad.** La prueba nueva simula el fallo con una excepción al resolver el servicio (un error SQL envenenaría la transacción de la prueba en PG). En el banco, la caída real se ensaya para el commit del motor, no para el monitor. Lo que sí está probado: con una comprobación que revienta, las demás se hacen, sale `monitor_check_failed` y sin mensaje; con IRON GUARD sin poder anotar, `not_recorded`.
2. **El worker muerto con su trabajo reservado no lo ve nadie** (H-7 de OPS7, confirmado aquí: `problemas []` en los dos caminos). No es un hueco de prueba sino de observabilidad: el monitor y `queue:health` solo cuentan trabajos sin reservar. Sigue abierto para `backend`/observabilidad.
3. **El guion de despliegue actual en el banco del repo.** Lo ensayé con mi copia corregida de `preparar.sh` sobre `1c76a7f`. El de `0d43401` (OPS7-bis, que cambia otra vez `deploy-h7-backend.sh`, `smoke-h7.py` y `smoke-run-h7.sh`) lo ensayó `h7-devops-b` según su commit; yo no.
4. **El H7 en la pila de producción** (nginx + PHP-FPM, `config:cache`/`route:cache`). El E2E y los bancos usan `artisan serve`. El `no-store`, el 413 temprano y `verified` se vieron con el smoke del H7 en el banco y en la suite, no detrás de nginx. El tope delante de PHP (`client_max_body_size`) sigue siendo decisión de OPS7 o del propietario.
5. **El `REVOKE` con los roles reales `iron`/`iron_app`.** Cubierto por la puerta PG de DB7 (rol de prueba creado en la transacción) y por el banco (`h5_app`), no en producción: sin acceso.
6. **El tope temprano con `multipart/form-data`:** PHP parsea ese cuerpo antes de Laravel, así que el tope solo lo ve por `Content-Length`. Sin prueba.
7. **En el E2E, el CRM con la clave aditiva `verified`.** Ninguna spec la comprueba. La única evidencia es indirecta: el CRM del H6 corre las 660 comprobaciones contra el backend del H7 sin romperse.
8. **Latencias y el F-1** (evidencia del canario cuadrática): son de PERF7. Ninguna prueba funcional los vigila.
9. **F-1/F-2** (el acta del canario en una sola pasada y las campañas), que al cerrar la fase B eran trabajo en curso sin confirmar, **ya están cubiertos**: el lead los confirmó en `e2411e3` y la fase C repitió las cuatro corridas sobre ese código (§3bis), con 0 rojos nuevos.
   - **Lo que la fase C no repitió:** el CRM (no cambia), los bancos (B7 no depende del acta ni de las campañas, y T-1 era de `h7-devops-b`) y las mutaciones.
   - **El byte a byte del acta** frente al algoritmo anterior y sus 13 mutaciones los informa el dueño de F-1 en su commit. Yo no los he repetido.
10. **La puerta PG con todo `tests/Feature/Marketing`:** C1 añadió al grupo del Studio el acta, el vigía y la superficie interna, no la carpeta entera. El resto de Marketing pasa en SQLite con las dos llaves (C2) y en la comparación completa (C3), sin línea base en PG.
11. **El trabajo en curso que hay en el árbol al cerrar la fase C (17:05 UTC), sin confirmar y de otro agente; esta regresión NO lo cubre.** Por los ficheros, parece el M-1 del reviewer: «`PlainText` más estricto también fuera del Studio», con la marca de DOC7 «invisibles acotados al Studio, hasta el commit de security».
    - `app/Rules/PlainText.php` y `app/Services/UltronStudio/StudioDraftNormalizer.php`;
    - los 8 controladores del Studio: `CampaignLink`, `Canary`, `Integration`, `Lab`, `LabScenario`, `Publication`, `Rule` y `Version`;
    - las pruebas `StudioPlainTextInvisiblesTest`, `InvisibleCharactersTest` y `UltronEngineIsolationTest`, y la nueva `PlainTextOutsideStudioTest`.
    
    El HEAD confirmado (`f73555d`) tiene el mismo código que la fase C. Cuando eso se confirme, hay que repetir al menos:
    - la puerta PG del grupo del Studio;
    - `tests/Feature/Security`, `tests/Unit/Support` y las suites de caja y valoraciones (el M-1 habla de `PlainText` fuera del Studio);
    - `suite-compare.sh` contra `1e5f9a0`;
    - el E2E (`f-versions`, `h5-governance` y `h6-integrations` envían etiquetas, notas y motivos);
    - las mutaciones IC/PT/KN de §2.1, porque la regla cambia.

## 6. Ficheros

En el repo del backend (rutas relativas a `backend/`):

| Fichero | Estado |
|---|---|
| `tests/Feature/UltronStudio/UltronStudioMonitorDriftAndFailuresH7Test.php` (nuevo, 4 pruebas) | Confirmado por el lead en `1426130` |
| `tests/Feature/UltronStudio/UltronStudioEarlyBodyCapStreamH7Test.php` (nuevo, 1 prueba) | Confirmado en `1426130` |
| `docs/ultron-studio/plans/informes-h7/informe-tester.md` (este) | Final de la fase B confirmado en `df0140f`; esta versión con la fase C queda **sin commit** en el árbol |

En la fase C no escribí nada más en el repo (solo este informe). En el CRM no toqué nada (worktree limpio en `16c433c`).

De lo que enseña `git status` al cerrar la fase C (HEAD `f73555d`), lo único mío es `informe-tester.md`. Lo demás es trabajo en curso de otros agentes (§5, punto 11):
- `PlainText.php`, `StudioDraftNormalizer.php` y 8 controladores del Studio;
- cuatro pruebas;
- `smoke-h7.py`, `presupuestos-h7.json`, `informe-devops-h7.md` e `informe-security.md`.

**Limpieza hecha** (02-11, tras C4, por orden del lead):
- los tres bancos `~/.cache/ironbody-banco-h7-test7{,-vuelta,-m6}`, con sus APIs ya paradas, están borrados;
- `dropdb` de `ultron_studio_banco_h7_test7`, `…_vuelta`, `…_m6` y `ultron_studio_h7_t`;
- comprobado después: 0 directorios, 0 bases y los puertos 8177–8179 y 55440 libres.

**Lo que queda fuera del repo:**

| Qué | Dónde | Por qué |
|---|---|---|
| Instantáneas, clones, arnés, sonda, guiones, registros y junit | Scratchpad de la sesión: `clean/` y `mut/` (instantáneas `8f70d18`); `faseC/` (`ac031dd`); `repo-5ed9525/` y `repo-1c76a7f/` (clones; el segundo con la regla de `PREVIO` cambiada en su copia de `preparar.sh`); `mutate.py` y sus tres especificaciones; `ScopeProbeH7Test.php`; `bin/*.sh`; `crm-build/` | Efímero, se purga solo |
| Las dos ejecuciones del E2E, con sus registros | `~/.cache/ironbody-e2e/ultron-studio/run-20261102T054102Z-5ed9525-16c433c` y `run-20261102T164523Z-0d43401-16c433c` | El arnés las deja, como las de los hitos anteriores |
| `ultron_studio_e2e` | PG 55432 | La borra y la crea el arnés en cada ejecución |

## 7. Reproducir y limpiar

```bash
# Fase A (desde backend/ o una instantánea suya con vendor copiado)
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/UltronStudio/UltronStudioMonitorDriftAndFailuresH7Test.php tests/Feature/UltronStudio/UltronStudioEarlyBodyCapStreamH7Test.php
MARKETING_ULTRON_STUDIO_CONFIG=all php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Marketing tests/Unit/Marketing \
  tests/Feature/UltronStudio/UltronEngineIsolationTest.php tests/Feature/UltronStudio/UltronStudioEngineReadOnlyTest.php \
  tests/Feature/UltronStudio/UltronStudioEngineReadingsTest.php tests/Feature/UltronStudio/UltronStudioLabNoEngineEffectsTest.php   # y con off
PGGSSENCMODE=disable UNIT_DB=ultron_studio_h7_t bash docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio tests/Unit/UltronStudio \
  tests/Feature/Hardening tests/Unit/Marketing/UltronPublishedConfigTest.php tests/Feature/Marketing/UltronCommitStudioStampTest.php
# Fase B
bash backend/docs/ultron-studio/ops/suite-compare.sh <dir vacío> 1e5f9a0 <rama>          # desde la raíz del worktree
node_modules/.bin/ng test --watch=false; node_modules/.bin/tsc -p tsconfig.app.json --noEmit; node_modules/.bin/tsc -p tsconfig.spec.json --noEmit
node_modules/.bin/ng build --configuration production                                     # en una copia: git archive 16c433c + node_modules enlazado
bash e2e/ultron-studio/run.sh <backend> 16c433c                                            # desde el CRM
# B7: ops/banco-h7/README.md (T-1 corregido en 0d43401; antes, desde un clon en 5ed9525 y BANCO_SEED=<CRM>/e2e/ultron-studio/specs/h6-seed.php)
# Fase C: lo mismo sobre e2411e3 o posterior; la puerta PG añade
#   tests/Feature/Marketing/{UltronCanaryReportTest,UltronCanaryReportSinglePassH7Test,UltronTurnWatchdogTest,InternalSecretAuthoritySurfaceTest}.php
# Limpieza local (YA HECHA el 02-11; queda como referencia)
for b in ~/.cache/ironbody-banco-h7-test7 ~/.cache/ironbody-banco-h7-test7-vuelta ~/.cache/ironbody-banco-h7-test7-m6; do bash backend/docs/ultron-studio/ops/banco-h6/api.sh "$b" stop; rm -rf "$b"; done
for d in ultron_studio_banco_h7_test7 ultron_studio_banco_h7_test7_vuelta ultron_studio_banco_h7_test7_m6 ultron_studio_h7_t; do dropdb -h 127.0.0.1 -p 55432 -U ironbody "$d"; done
```
