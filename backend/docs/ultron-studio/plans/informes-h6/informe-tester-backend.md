# Hito 6 · tester de backend: carreras en PostgreSQL, guardas como rol de aplicación, mutaciones y centinela ampliada

**Fecha:** 01-11-2026 (Bogotá). **Árbol:** `_worktrees/ultron-h6/Iron-Body/backend`, rama `feat/ultron-studio-h6`, HEAD `f549c50` (S confirmado en `4e6f5fe`). Mientras trabajé, la «tanda 2» de S estaba **sin confirmar** en el árbol (coherencia Q-6, textos O-2 y paridad de las 22 secciones): las carreras, las guardas y la centinela se corrieron contra ese árbol; las mutaciones, contra una copia congelada de él (§5). **Sin commit.** **Producción: nada.** PostgreSQL 14.24 local (`127.0.0.1:55432`, usuario `ironbody`), bases propias: `ultron_studio_h6_t` (pruebas), `…_h6_t_mut` (mutaciones), `…_h6_t_head` y `…_h6_t_base` (línea base). No he tocado ningún fichero de S, DB, V, TW ni OPS.

## 0. Resumen

- **Carreras en PostgreSQL con sesiones reales** (procesos hijos por el kernel HTTP): 29 pruebas, 37 casos, naturales (barrera) y forzadas (`pg_blocking_pids` + 40 ms). Todas las de §I11 (mismo teléfono, misma campaña, autorizar frente a revocar, 4 activaciones contra 3, reconocer frente a un cambio de configuración) y además: tope de vivos y tope global de asociaciones con su cerrojo, sustitución concurrente (D-1), doble clic con la misma `clientRequestId` en registrar, asociar, sustituir, reconocer y la sonda, cinco clics en autorizar y activar, diez intentos de quien registró (también en REPEATABLE READ) y la sonda con la caché compartida de producción (4 pulsaciones → 1 salida de 5 GET). **Corrida final: OK, 45 pruebas y 1526 aserciones en PG** (148 sesiones en las 37 carreras); en SQLite se omiten con su motivo.
- **Un defecto real** encontrado por la carrera forzada: H-1 (= B-2 de la Enmienda 3), revocar escribía en el libro el estado leído antes de esperar la fila. S ya lo corrigió en el árbol (sin confirmar) y la prueba lo verifica. **Uno nuevo de severidad baja:** H-2, la huella del teléfono llega al log del servidor de PostgreSQL en cada registro duplicado; la corrección propuesta está validada en la copia (F2).
- **Guardas como rol de aplicación** (sin superusuario, solo DML, sin `TRUNCATE`): 4 pruebas, 243 aserciones, sobre filas que deja la API: P0001, 23514 (los dos cuatro ojos por DML), 23505 (parciales), 42501 (nadie apaga una guarda) y `down()` que se niega.
- **Mutaciones:** 31 mutantes de la campaña (los 8 del lead, los 10 de §I11 y 9 de concurrencia, más variantes) + C7b, C9b, E1 y E2: **33 de 35 caen**; los 2 que sobreviven (C7, C9) son **equivalentes** (el `catch` exterior del H-1 del H5 repite la comprobación) y C7b/C9b, que quitan las dos, caen. C1–C9 solo los cazan mis carreras; I8 solo mi centinela de entorno; L7 solo el oráculo; I6a/I6b una sola prueba de S. Huella de la copia idéntica antes y después.
- **Centinela ampliada:** el exportador de fixtures, las filas del registro, los secretos que la de S no siembra y la configuración calculada de verdad desde el entorno (alias de Twilio con valores distintos): nada sale. PG 4/4, SQLite 3/3.
- **Línea base** contra `origin/main`: **pendiente**: quedó corriendo al cortarse mi sesión (resultados en `runs/cierre.txt` del scratchpad, pasos 5 y 6); no afirmo su resultado.

## 1. Ficheros (los únicos que he creado; ninguno modificado)

| Fichero | Líneas | Qué |
|---|---|---|
| `tests/Support/IntegrationRaceSessions.php` | 490 | Sesiones rivales en procesos hijos, calcado de `PublicationRaceSessions` (H5): barrera, pausas `blocked`/`released`, rival crudo; **más** lo que pide el H6: configuración de las integraciones por hijo (y retoques por sesión), entorno propio sin heredar la configuración (`inherit: false`), `Http::fake` + `preventStrayRequests` dentro del hijo (la sonda nunca sale), caché `database` compartida (el cerrojo de la sonda), modo comando (`artisan`), pausa `signal`, y log/stderr del hijo en el resultado |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsRaceTest.php` | 1233 | 29 pruebas, 37 casos: las carreras de §I11 y las que faltaban (topes de vivos y global de asociaciones), naturales y forzadas (§3). Solo PG |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsGuardsAdversarialTest.php` | 399 | Las guardas contra un rol de aplicación sin superusuario y con solo DML, sobre filas que deja la API; `down()` que se niega (§4) |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsSentinelAdversarialTest.php` | 278 | Centinela ampliada: el exportador de fixtures, las filas del registro y los secretos que la de S no siembra (§6). Los dos motores |
| `tests/Feature/UltronStudio/UltronStudioIntegrationsEnvSentinelAdversarialTest.php` | 129 | Centinela con la configuración calculada **de verdad** desde el entorno en un proceso hijo (`twilio_aliases` con valores distintos) (§6). Solo PG |
| `docs/ultron-studio/plans/informes-h6/informe-tester-backend.md` | — | Este informe |

Fuera del árbol (scratchpad de la sesión): el corredor de mutaciones `h6-mutations.php` (calcado de `ops/h5-mutations.php`), la copia congelada y los resultados (`mut-out/`), los rastros de las carreras y las corridas de la línea base. Las rutas completas, en §9.

Convenciones que sigo de la suite: `RefreshDatabase` con transacción envolvente salvo en las clases de procesos hijos (`$connectionsToTransact = []`, esquema nuevo por prueba, como la carrera del H5); fábricas de `IntegrationFixtures`/`StudioFixtures` (personas con exactamente sus llaves y sesión real); reloj congelado en UTC; red siempre falsa (`Http::preventStrayRequests()` + `Http::fake`) también en el proceso de la prueba. Las clases solo-PG se omiten **antes** de arrancar la aplicación (ver O-1).

## 2. Hallazgos

### H-1 · El libro dice que un número se revocó desde `pending` cuando se revocó ya autorizado (dueño: S) — **= B-2 de la Enmienda 3; corregido por S en el árbol, sin confirmar; verificado**

Yo la clasifiqué **MEDIA** (integridad del libro, sin efecto de seguridad); la Enmienda 3 la recoge como B-2 sin severidad media. Lo que aporta esta prueba es la **reproducción determinista** entre sesiones reales y la verificación de la corrección.


- **Dónde (en `4e6f5fe`):** `app/Services/UltronStudio/StudioCanaryNumbers.php`, `revoke()`: lee el número fuera de la transacción (`:323`, `gate()`), toma `$desde = (string) $numero->status` de esa lectura (`:327`), hace el UPDATE condicionado a **cualquier** estado vivo (`:332`, `ALLOWED_FROM['revoke'] = LIVE`, `:69`) y escribe `'fromStatus' => $desde` en el evento (`:344`).
- **Escenario (reproducido con certeza):** número `pending`. Root autoriza (su UPDATE `pending → authorized` sin confirmar). Bea revoca: su `gate()` lee `pending`; su UPDATE espera la fila; Root confirma; PostgreSQL reevalúa el `WHERE status IN (pending, authorized, active)` con la fila nueva y revoca la autorizada. Las dos responden 200 y el estado final es correcto, pero el libro queda así:
  `registered (— → pending)`, `authorized (pending → authorized)`, `revoked (pending → revoked)` ← falso: se revocó desde `authorized`.
- **Prueba:** `UltronStudioIntegrationsRaceTest::test_autorizar_frente_a_revocar_forzado_gana_quien_toma_la_fila` con «gana autorizar» — determinista (la autorización se para antes de su evento hasta que la revocación espera su fila). **En rojo contra `4e6f5fe`** (primera corrida completa: 32/33, ese el único rojo, con `'pending'` donde tenía que decir `'authorized'`). La versión natural (`test_autorizar_y_revocar_a_la_vez_…`) afirma lo mismo y se pone en rojo solo si el planificador cae en la ventana (en mis corridas cayó fuera). `cipherErased` sale de la misma lectura: hoy no puede mentir (solo revocar borra el cifrado y revocado es definitivo), pero es el mismo patrón.
- **Por qué importa:** §I4.4 («cada transición es un UPDATE condicionado al estado esperado, en la misma transacción que su evento del libro») y §I8 (`canary_number.revoked`: `*` → `revoked`). El libro es la única historia de quién movió qué; un `from` falso rompe su cadena (dos eventos seguidos «desde pending»).
- **Corrección:** dentro de la transacción, leer el estado con la fila bloqueada y usar ESE como `fromStatus`. Revocar sigue pudiéndose siempre (es el sentido seguro) y el libro dice la verdad. **S ya la tiene en el árbol** (tanda 2, sin confirmar): `$desde = (string) StudioCanaryNumber::query()->whereKey($numero->id)->lockForUpdate()->value('status');`. **Verificado:** con ese cambio, la prueba forzada y la natural pasan **sin tocarlas** (3/3, 59 aserciones). La corrida final de §3 (14:47 UTC, **45/45 en PG**) las vuelve a pasar con ese árbol. Queda para el `reviewer` que se confirme junto con la prueba.

### H-2 · BAJA · La huella del teléfono acaba en el log del servidor de PostgreSQL en cada registro duplicado (dueños: S; alternativa operativa, devops) — **nuevo: no lo cubre la Enmienda 3** (N-1 trata el log de la aplicación y AS-3 el sondeo por el 409; esto es el log del servidor de la base)

- **Dónde:** `StudioCanaryNumbers.php:201` inserta sin comprobar antes si el número ya está vivo y `:227` resuelve el duplicado capturando el 23505. Es el camino **normal** de «Ese número ya está registrado», no solo el de las carreras.
- **Evidencia:** en el `server.log` del clúster local (`log_min_error_statement = error`, verbosidad por defecto), cada duplicado deja `ERROR: duplicate key value violates unique constraint "uscn_one_live"` + `DETAIL: Key (channel, phone_hash)=(whatsapp, <64 hex>) already exists.` Lo comprobé con la prueba secuencial de S `test_el_mismo_telefono_puede_tener_una_fila_viva_por_canal…` (una línea nueva con la huella) y con mis carreras. La sentencia va con marcadores (`$1 … $17`): **ni el alias, ni los 4 dígitos, ni el cifrado** llegan al log. En `uscl_one_active` el `DETAIL` lleva `(ad_account_id, campaign_id)` en claro (ids de Meta, no datos personales; solo en carreras, porque el servicio mira `activeFor` antes de insertar).
- **Impacto:** una segunda copia de la huella fuera de las tablas del Studio, con la retención y los lectores del log del servidor; quien tenga ese log y la `APP_KEY` recupera el número (H-3). No sale en respuestas, libro, `facts` ni log de la aplicación (lo vigilan la centinela de S y la mía).
- **Lo que ya hay:** la tanda 2 de S trae N-1 (cualquier otro fallo de la base en el alta se relanza sin el SQL ni sus valores), pero el duplicado se sigue resolviendo capturando el 23505: el `DETAIL` del servidor no cambia.
- **Corrección (S):** dentro de la transacción ya cerrada por el cerrojo de vivos, `exists(channel, phone_hash, status ≠ revoked)` antes del INSERT y 409 `canary_number_exists` sin error de la base; el 23505 queda solo de respaldo (rival sin cerrojo). **Alternativa (devops):** `log_error_verbosity = terse` (sin `DETAIL`) para el rol de la aplicación; no lo he probado.

### H-3 · BAJA · «No se puede recuperar ni con la `APP_KEY`» (I13) no era cierto — **ya corregido en la Enmienda 3 (B-1 = AS-4, `0b7b41d`)**; lo dejo por la medida

- **Dónde:** contrato §I13, fila «Teléfono de un canario de WhatsApp». La huella es `HMAC(subclave(APP_KEY), E.164)` (§I4.3) y WhatsApp solo admite `^\+573[0-9]{9}$`: **10⁹** números posibles.
- **Medido en esta máquina:** `hash_hmac('sha256', …)` en PHP 8.3, **~705 000 por segundo y núcleo** → los 10⁹ en **~24 min con un núcleo** (~3 min con 8). Con código nativo, menos.
- **Consecuencia:** la protección real de la huella es el **secreto de la `APP_KEY`**. Eso es lo que dice ya I13 tras la Enmienda 3, que además cuenta con los 4 dígitos guardados (con ellos el espacio baja a 10⁵: milisegundos). Mi medida sin los 4 dígitos (solo la huella, como la que deja H-2 en el log del servidor) es la cota alta: minutos. Nada más que hacer en H6.

### Observaciones (sin defecto del H6 o fuera de mi alcance)

- **O-1 · BAJA · infraestructura de pruebas (dueño: lead / quien mantenga la carrera del H5).** `tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php` (H5) deja **sin esquema** a las clases que corren después en SQLite si es la primera clase del proceso: con `$connectionsToTransact = []` migra la base en memoria sin guardar su PDO (`RefreshDatabase::updateLocalCacheOfInMemoryDatabases()` recorre `connectionsToTransact()`) y se omite después de `parent::setUp()`. Reproducido: `phpunit tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php tests/Feature/UltronStudio/UltronStudioIntegrationsSentinelAdversarialTest.php` → `no such table: meta_ad_entities`. En la corrida del directorio completo no aflora (otra clase migra antes). Mis dos clases solo-PG ya se omiten **antes** de `parent::setUp()` (comprobado: con mi carrera primera, las clases siguientes pasan). Corrección de una línea, igual que en las mías.
- **O-2 · BAJA · Si el rol de la aplicación tuviera `default_transaction_isolation = repeatable read`, 7 de las 37 carreras responden 500 (dueños: S y devops).** Diagnóstico con `ULTRON_STUDIO_RACE_ISOLATION='repeatable read'` (todas las sesiones en RR, como lo dejaría ese ajuste en el rol; la lección B-2 de G): **37 pruebas, 956 aserciones, 7 rojos, todos SQLSTATE 40001** («could not serialize access due to concurrent update») convertidos en 500:
  - **4 de transiciones sin el cerrojo de `StudioIntegrations::lock()`** (que sí fija READ COMMITTED): autorizar frente a revocar (las dos forzadas), 5 clics en autorizar y sustituir frente a desactivar. Es el UPDATE condicionado (o el `FOR UPDATE` de B-2) que espera una fila que otra sesión cambia y confirma: en RR no se reevalúa, se aborta. Lo correcto (200 revocando la autorizada, 409 `invalid_transition`) solo sale en READ COMMITTED.
  - **3 de la sonda**, por el **limitador de Laravel** sobre la caché `database` (`select * from "cache" where "key" = … for update` e `insert into "cache" … on conflict do nothing` al contar los cupos de la misma red a la vez). No es código del Studio: con ese ajuste, **cualquier ruta con cupo** podría dar 500 ante dos peticiones simultáneas de la misma oficina.
  - Lo que ya fija READ COMMITTED (registrar, activar, asociar y `recordDenied`) pasa en RR: los topes y la deduplicación aguantan.
  - **En READ COMMITTED** (el defecto de PostgreSQL y lo que llevan las demás corridas): 0 rojos. **No he comprobado** el aislamiento por defecto del rol `iron_app` en producción.
  - **Qué haría:** S, empezar las transacciones de autorizar, desactivar, revocar, expectativa y desactivar asociación con el mismo `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` que ya usa `lock()` (una línea; coherente con §I4.8); devops, comprobar en solo lectura `SHOW default_transaction_isolation` como `iron_app` y dejarlo anotado en el runbook (si fuera RR, el limitador ya fallaría hoy en todo el CRM).
- **O-3 · referencia cruzada.** El único rojo de la línea base de las mutaciones es el que S dejó **a propósito** (su §6.1): `ElevenLabsConfig` (V) aceptaba un `agent_id` con salto de línea final y el oráculo espera `missing: ['agent_id']`. Es N-5 de la Enmienda 3, ya corregido por V en `bd1c7af`/`fb152fe` (después de congelar mi copia: por eso sigue rojo en ella y no cuenta como rojo nuevo de ningún mutante).
- **O-4 · referencia.** Por DML, `authorized_by_*` de un número autorizado se puede reescribir con un tercero (los CHECK solo prohíben a quien registró: lo compruebo en §4). Es la propuesta `uscn_authorizer_once` del §11 del informe de datos, no implementada; el contrato no la exige.

## 3. Carreras en PostgreSQL con sesiones reales

Cada sesión es un **proceso hijo** con su conexión, que arranca la aplicación entera y manda la petición por el kernel HTTP con la sesión real de una persona (middleware, cupos, validación, controlador, servicio y transacción reales). **Natural**: N sesiones esperan en una barrera de la base y salen a la vez. **Forzada**: una sesión se para en un punto exacto de su transacción hasta que la rival espera uno de sus cerrojos (`pg_blocking_pids` + escalón de 40 ms; la prueba exige `waiters ≥ 1`, si no la carrera no ocurrió) o hasta que la prueba la suelta. En cada una se afirma **primero la integridad** (filas, estado, libro: un evento por efecto y ninguno de la perdedora) y después el contrato de las respuestas; en las perdedoras, ni el teléfono, ni sus cifras, ni su huella, ni ids de Meta (cuerpo, log y stderr del hijo). Ninguna respuesta 5xx.

**Corrida final** (01-11-2026, 14:47 UTC, árbol con la tanda 2 de S): **OK, 45 pruebas y 1526 aserciones** en PG (las 37 de carreras, las 4 de guardas y las 4 de centinela), 2 min 9 s. Lo que respondió cada una de las **148 sesiones** de las 37 carreras (rastro `ULTRON_STUDIO_RACE_LOG`). `waiters` = sesiones que esperaban un cerrojo de la que se paró (las forzadas exigen ≥ 1); el aislamiento es el `default_transaction_isolation` de cada sesión.

| Prueba | Sesiones | Respuestas | `waiters` | Aislamiento |
|---|---|---|---|---|
| test_diez_registros_simultaneos_del_mismo_telefono_dejan_una_fila_viva_y_un_evento | 10 | 9×409 canary_number_exists + 1×201 | — | read committed |
| test_el_registro_que_espera_el_cerrojo_de_vivos_de_la_ganadora_recibe_canary_number_exists | 2 | 1×201 + 1×409 canary_number_exists | 1 | read committed |
| test_un_rival_sin_cerrojo_hace_esperar_al_registro_en_uscn_one_live_y_la_transaccion_abortada_no_deja_nada | 2 | 1×409 canary_number_exists + 1×rival | 1 | read committed |
| test_diez_registros_con_la_misma_client_request_id_dan_una_201_y_nueve_replayed_con_el_mismo_numero | 10 | 9×200 replayed + 1×201 | — | read committed |
| test_el_doble_clic_que_espera_a_la_primera_recibe_replayed_con_un_solo_efecto with data set "registrar" | 2 | 1×200 replayed + 1×201 | 1 | read committed |
| test_el_doble_clic_que_espera_a_la_primera_recibe_replayed_con_un_solo_efecto with data set "asociar" | 2 | 1×200 replayed + 1×201 | 1 | read committed |
| test_el_doble_clic_que_espera_a_la_primera_recibe_replayed_con_un_solo_efecto with data set "sustituir" | 2 | 1×200 replayed + 1×201 | 1 | read committed |
| test_el_doble_clic_que_espera_a_la_primera_recibe_replayed_con_un_solo_efecto with data set "reconocer" | 2 | 1×200 replayed + 1×201 | 1 | read committed |
| test_tres_registros_simultaneos_contra_el_ultimo_hueco_de_vivos_registran_uno | 3 | 2×409 canary_live_limit + 1×201 | — | read committed |
| test_el_registro_que_espera_el_cerrojo_de_vivos_cuenta_despues_y_ve_el_tope | 2 | 1×201 + 1×409 canary_live_limit | 1 | read committed |
| test_cuatro_activaciones_simultaneas_contra_un_tope_de_tres_activan_tres | 4 | 3×200 + 1×409 canary_active_limit | — | read committed |
| test_la_activacion_que_espera_el_cerrojo_del_canal_cuenta_despues_y_ve_el_tope with data set "por defecto" | 2 | 1×200 + 1×409 canary_active_limit | 1 | read committed |
| test_la_activacion_que_espera_el_cerrojo_del_canal_cuenta_despues_y_ve_el_tope with data set "repeatable read" | 2 | 1×200 + 1×409 canary_active_limit | 1 | repeatable read |
| test_autorizar_y_revocar_a_la_vez_dejan_el_numero_revocado_y_un_libro_que_cuenta_lo_que_paso | 2 | 2×200 | — | read committed |
| test_autorizar_frente_a_revocar_forzado_gana_quien_toma_la_fila with data set "gana autorizar" | 2 | 2×200 | 1 | read committed |
| test_autorizar_frente_a_revocar_forzado_gana_quien_toma_la_fila with data set "gana revocar" | 2 | 1×200 + 1×409 invalid_transition·revoked | 1 | read committed |
| test_cinco_clics_simultaneos_en_autorizar_o_activar_hacen_un_solo_efecto with data set "autorizar" | 5 | 4×409 invalid_transition·authorized + 1×200 | — | read committed |
| test_cinco_clics_simultaneos_en_autorizar_o_activar_hacen_un_solo_efecto with data set "activar" | 5 | 4×409 invalid_transition·active + 1×200 | — | read committed |
| test_diez_intentos_simultaneos_de_quien_registro_dejan_un_solo_four_eyes_denied with data set "autorizar en repeatable read" | 10 | 10×403 four_eyes_required | — | repeatable read |
| test_diez_intentos_simultaneos_de_quien_registro_dejan_un_solo_four_eyes_denied with data set "activar" | 10 | 10×403 four_eyes_required | — | read committed |
| test_diez_asociaciones_simultaneas_de_la_misma_campana_dejan_una_activa_y_un_evento | 10 | 9×409 campaign_already_linked + 1×201 | — | read committed |
| test_la_asociacion_que_espera_el_cerrojo_de_la_ganadora_recibe_campaign_already_linked | 2 | 1×201 + 1×409 campaign_already_linked | 1 | read committed |
| test_un_rival_sin_cerrojo_hace_esperar_a_la_asociacion_en_uscl_one_active_y_no_queda_nada_suyo | 2 | 1×409 campaign_already_linked + 1×rival | 1 | read committed |
| test_dos_asociaciones_simultaneas_de_campanas_distintas_contra_el_ultimo_hueco_crean_una | 2 | 1×201 + 1×409 campaign_links_limit | — | read committed |
| test_la_asociacion_que_espera_el_cerrojo_del_tope_cuenta_despues_y_ve_el_tope | 2 | 1×201 + 1×409 campaign_links_limit | 1 | read committed |
| test_seis_sustituciones_simultaneas_de_la_misma_activa_dejan_una_sustituta_anotada_una_vez | 6 | 5×409 campaign_link_moved + 1×201 | — | read committed |
| test_la_sustitucion_que_entra_despues_de_otra_no_cierra_nada_y_recibe_campaign_link_moved | 2 | 1×201 + 1×409 campaign_link_moved | — | read committed |
| test_sustituir_frente_a_desactivar_la_misma_activa with data set "gana sustituir" | 2 | 1×201 + 1×409 invalid_transition·inactive | 1 | read committed |
| test_sustituir_frente_a_desactivar_la_misma_activa with data set "gana desactivar" | 2 | 1×200 + 1×409 campaign_link_moved | 1 | read committed |
| test_diez_asociaciones_con_la_misma_client_request_id_dan_una_201_y_nueve_replayed | 10 | 9×200 replayed + 1×201 | — | read committed |
| test_dos_reconocimientos_simultaneos_de_dos_personas_son_dos_instantaneas_con_su_evento_cada_una | 2 | 2×201 | — | read committed |
| test_diez_reconocimientos_con_la_misma_client_request_id_dejan_una_instantanea | 10 | 9×200 replayed + 1×201 | — | read committed |
| test_reconocer_frente_a_un_cambio_de_configuracion_nunca_autoriza_una_huella_que_no_es_la_efectiva | 3 | 1×201 + 1×409 config_changed + 1×cmd | — | read committed |
| test_cuatro_pulsaciones_simultaneas_de_la_sonda_salen_una_vez | 4 | 3×200 reused + 1×201 (5 GET) | — | read committed |
| test_la_segunda_pulsacion_que_espera_el_cerrojo_de_la_cache_reutiliza_la_primera_sin_red | 2 | 1×200 reused + 1×201 (5 GET) | — | read committed |
| test_tres_clics_de_la_sonda_con_la_misma_client_request_id_salen_una_vez_y_dos_replayed | 3 | 2×200 replayed + 1×201 (5 GET) | — | read committed |
| test_tres_pulsaciones_simultaneas_dentro_del_enfriamiento_reutilizan_la_ultima_sin_red_ni_evento | 3 | 3×200 reused | — | read committed |


## 4. Guardas de la base contra el rol de la aplicación

`UltronStudioIntegrationsGuardsAdversarialTest` modela el de producción (`iron_app`, §7.4 del informe de datos): un rol `ultron_studio_h6_t_app` **sin superusuario ni `BYPASSRLS`**, con `SELECT, INSERT, UPDATE, DELETE` sobre las tablas y `USAGE, SELECT, UPDATE` sobre las secuencias, **sin `TRUNCATE`**, que no es dueño de nada (la prueba lo comprueba). Las filas son las que deja **la API** (una asociación sustituida por otra, números `pending`/`authorized`/voz cifrada/`revoked`, un reconocimiento y una sonda), no sembradas. Cada intento va como `SET LOCAL ROLE` dentro de un punto de guardado que **siempre** se deshace, y al final la huella de todas las filas del hito y del libro es idéntica a la de antes.

| Qué se intenta como rol de aplicación | Resultado |
|---|---|
| `DELETE` en las 3 tablas | **P0001** con el motivo del contrato («… no se borran», «… se revocan, no se borran», «… solo inserción») |
| `UPDATE` de una columna inmutable, **también con el mismo valor** (`campaign_id = campaign_id`, `alias = alias`, `target_kind`, `phone_hash`, `registered_by_admin_id`) | **P0001** |
| Reactivar una asociación inactiva o cambiar su motivo; tocar un número revocado; reescribir o poner un cifrado | **P0001** (`uscl_inactive_is_final`, `uscn_revoked_is_final`, `uscn_cipher_only_shrinks`) |
| `UPDATE` o `DELETE` de una instantánea; `UPDATE`/`DELETE` del libro con los sujetos nuevos | **P0001** |
| `TRUNCATE` de las 3 tablas sin el privilegio (como en producción) | **42501** |
| `TRUNCATE` con el privilegio concedido (defensa en profundidad) | **P0001** `(TRUNCATE en <tabla>)` |
| **Autorizar con la misma identidad que registró**, por DML directo | **23514 `uscn_four_eyes_chk`** |
| **Activar con la misma identidad que registró**, por DML directo | **23514 `uscn_activation_four_eyes_chk`** |
| Reescribir `authorized_by_admin_id` con quien registró | **23514 `uscn_four_eyes_chk`** |
| Saltarse autorizar (`pending → active`) / activo sin quién | **23514** `uscn_authorized_chk` / `uscn_active_chk` |
| **Voz revocada sin borrar el cifrado** / borrar el cifrado sin revocar | **23514 `uscn_revoke_chk`** / **23514 `uscn_voice_cipher_chk`** |
| Revocar sin motivo ni quién; estado fuera del dominio; asociación cerrada sin quién | **23514** `uscn_revoke_chk`, `uscn_status_chk`, `uscl_deactivation_chk` |
| INSERT a mano: sin consentimiento, cifrado en WhatsApp, voz sin cifrado, huella con el teléfono, `last4` no numérico | **23514** (`uscn_consent_chk`, `uscn_whatsapp_no_cipher_chk`, `uscn_voice_cipher_chk`, `uscn_hash_chk`, `uscn_last4_chk`); `last4` de 5 cifras → 22001 |
| **Segunda viva** con el mismo canal y teléfono que dejó la API / **segunda activa** de la misma campaña | **23505 `uscn_one_live`** / **23505 `uscl_one_active`** |
| Sonda con `outcome` NULL (D-2), `config` con resultado, `admin` sin id, huella en mayúsculas, proveedor ajeno | **23514** `usis_outcome_chk` (×2), `usis_actor_chk`, `usis_fingerprint_chk`, `usis_provider_chk` |
| Evento del libro con un sujeto fuera de los 7 | **23514 `usae_subject_type_chk`** |
| D-1: volver a anotar la sustituta / anotarla en una activa | **P0001 `uscl_replacement_once`** |
| Apagar una guarda: `DISABLE TRIGGER` (uno y `ALL`), `DROP TRIGGER`, `DROP CONSTRAINT` (los dos cuatro ojos, `usis_outcome_chk`, `usae_subject_type_chk`), `DROP INDEX` (`uscn_one_live`, `uscl_one_active`), `SET session_replication_role = replica`, `CREATE OR REPLACE FUNCTION ultron_studio_forbid()` | **42501** las 11 |
| Lo que la API sí hace (autorizar por UPDATE condicionado con otra persona) | **pasa** (control: el modelo de privilegios no es más estricto que el de producción) |
| **`down()` de las dos migraciones con las filas que dejó la API** (en la base de la prueba, dentro de un punto de guardado) | **se niega** con el recuento «2 asociaciones, 4 números, 2 instantáneas y N eventos» y el esquema queda idéntico antes y después (tablas, recuento de disparadores y de CHECK, e índices con su definición; en SQLite, `sqlite_master` entero); en **SQLite** también |

**Resultado:** PG **4 pruebas, 243 aserciones, OK**; SQLite 1 prueba (la de `down()`) OK y las otras 3 omitidas con el motivo (rol y CHECK son de PG; en SQLite los disparadores los cubre `UltronStudioIntegrationsSchemaTest`). Lo que el informe de datos hizo a mano en §7.4 queda así como prueba automática y sobre filas reales de la API.

## 5. Mutaciones (en una copia, nunca en el árbol)

**Cómo.** Copia congelada del árbol tal como estaba al empezar (HEAD `f549c50` + la tanda 2 de S sin confirmar **en ese momento** —anterior a B-2 y N-1, que S añadió después— + mis pruebas; `rsync` del backend, `vendor/` **copiado** y `.env.testing`; sin `.git`). Corredor `h6-mutations.php` calcado de `ops/h5-mutations.php`: cada mutante son sustituciones **exactas** (cada cadena aparece una sola vez: `MUT_CHECK=1`), `php -l`, sus ejecuciones y restauración en `finally` comprobada por sha256; antes, una **línea base sin mutar** de cada ejecución distinta, y un mutante «cae» solo por **rojos nuevos** respecto de ella. Huella de `app/`, `tests/`, `config/`, `database/` y `routes/` antes y después de la campaña: tiene que ser idéntica.

**Ejecuciones.** `sqlite` = las 13 pruebas de integraciones (las de S, la de esquema de DB y las mías salvo la de carreras) + paridad del oráculo + `UltronStudioEngineReadOnlyTest` + `UltronEngineIsolationTest` + `tests/Unit/{Twilio,Voice}` + `tests/Feature/Voice` (1074 pruebas). `pgsql` = las mismas 13 de integraciones en PostgreSQL (551). `pgsql·<filtro>` = mis carreras con ese filtro. Línea base sin mutar: SQLite 1 rojo (O-3, de V), PG 0, todas las carreras 0.

**Dueño de la prueba que caza:** **S** (pruebas del paquete S), **fixture** (paridad con `integrations-cases.json`, la pura y la HTTP), **DB** (`UltronStudioIntegrationsSchemaTest`), **TW**/**V** (sus Unit), **T** (las mías).

| Id | Mutante | Veredicto (rojos nuevos) | Por ejecución | Quién lo caza (pruebas) | Lectura |
|---|---|---|---|---|---|
| L1 | Sin cuatro ojos en la API (assertFourEyes no comprueba nada) | CAE (25) | sqlite: 11, pgsql: 12, pgsql·quien_registro: 2 | **S** `UltronStudioIntegrationsCanaryTest` (1); **fixture** `UltronStudioIntegrationsParityHttpTest` (2); **S** `UltronStudioIntegrationsSentinelTest` (1); **T** `UltronStudioIntegrationsRaceTest` (1) | En PG, además, la base lo para aparte: `uscn_four_eyes_chk` y `uscn_activation_four_eyes_chk` (500 en lugar de 403). Mis 2 carreras salieron como *error* del ayudante (la respuesta 500 con su traza pasaba de 64 KB y no se leía entera), ya corregido en el árbol |
| L2 | Sin la bandera de escrituras (writesEnabled siempre true) | CAE (33) | sqlite: 33 | **S** `UltronStudioIntegrationsCampaignLinkTest` (1); **S** `UltronStudioIntegrationsCanaryTest` (1); **fixture** `UltronStudioIntegrationsParityHttpTest` (3); **S** `UltronStudioIntegrationsPermissionsTest` (2); **S** `UltronStudioIntegrationsStatusTest` (1) |  |
| L3a | Sin huella del teléfono: phone_hash = el E.164 en claro | CAE (87) | sqlite: 7, pgsql: 80 | **S** `UltronStudioIntegrationsCanaryTest` (12); **fixture** `UltronStudioIntegrationsParityHttpTest` (3); **T** `UltronStudioIntegrationsSentinelAdversarialTest` (3); **T** `UltronStudioIntegrationsGuardsAdversarialTest` (4); **S** `UltronStudioIntegrationsPermissionsTest` (9); **S** `UltronStudioIntegrationsSentinelTest` (1); **S** `UltronStudioIntegrationsStatusTest` (1) | En PG lo para `uscn_hash_chk` en cada alta (500); en SQLite, la coherencia de S y mi centinela de filas |
| L3b | Huella sin subclave: phone_hash = sha256(E.164), se invierte recorriendo los móviles | CAE (8) | sqlite: 8 | **S** `UltronStudioIntegrationsCanaryTest` (3); **fixture** `UltronStudioIntegrationsParityHttpTest` (2); **S** `UltronStudioIntegrationsProbeTest` (1); **T** `UltronStudioIntegrationsSentinelAdversarialTest` (1) | Pasa los CHECK (64 hex): solo lo ven la coherencia, el oráculo y mi centinela de filas (≠ sha256) |
| L4 | Sin enfriamiento de la sonda | CAE (7) | sqlite: 4, pgsql·sonda o pulsacion: 3 | **fixture** `UltronStudioIntegrationsParityHttpTest` (2); **S** `UltronStudioIntegrationsProbeTest` (1); **T** `UltronStudioIntegrationsRaceTest` (3) |  |
| L5a | Una lectura (ruta 43) sale a la red; NoNetwork conserva preventStrayRequests | CAE (7) | sqlite: 7 | **S** `UltronStudioEngineReadOnlyTest` (2); **S** `UltronStudioIntegrationsNoNetworkTest` (2); **fixture** `UltronStudioIntegrationsParityHttpTest` (2) |  |
| L5b | La misma salida a la red y además SIN Http::preventStrayRequests() en la prueba NoNetwork | CAE (4) | sqlite: 4 | **S** `UltronStudioEngineReadOnlyTest` (2); **S** `UltronStudioIntegrationsNoNetworkTest` (2) | NoNetwork no depende de `preventStrayRequests`: cuenta lo grabado por `Http::fake` |
| L6a | Saltarse el libro al registrar un número | CAE (12) | sqlite: 11, pgsql·diez_registros_simultaneos_del_mismo: 1 | **S** `UltronStudioIntegrationsCanaryTest` (2); **fixture** `UltronStudioIntegrationsParityHttpTest` (1); **S** `UltronStudioIntegrationsSentinelTest` (1); **T** `UltronStudioIntegrationsRaceTest` (1) |  |
| L6b | Saltarse el libro al crear una asociación | CAE (13) | sqlite: 13 | **S** `UltronStudioIntegrationsCampaignLinkTest` (1); **fixture** `UltronStudioIntegrationsParityHttpTest` (1) |  |
| L6c | Saltarse el libro al reconocer la configuración | CAE (3) | sqlite: 3 | **S** `UltronStudioIntegrationsConfigTest` (1); **fixture** `UltronStudioIntegrationsParityHttpTest` (1) |  |
| L6d | Saltarse el libro en la sonda | CAE (7) | sqlite: 7 | **fixture** `UltronStudioIntegrationsParityHttpTest` (1); **S** `UltronStudioIntegrationsProbeTest` (2) |  |
| L7 | last4 de la sonda acepta E.164 de 7 cifras (antes de Q-13) | CAE (1) | sqlite: 1 | **fixture** `StudioIntegrationsParityTest` (1) | Solo el oráculo (1 caso): las pruebas de TW no tienen un número de 7 cifras |
| L8 | usis_outcome_chk literal del contrato: acepta una sonda con outcome NULL (sin D-2) | CAE (2) | pgsql: 2 | **T** `UltronStudioIntegrationsGuardsAdversarialTest` (1); **DB** `UltronStudioIntegrationsSchemaTest` (1) | Solo PG (el CHECK no existe en SQLite): la prueba de esquema de DB y la mía de guardas |
| I1 | Regla de canal: WhatsApp admite un fijo (60X) | CAE (4) | sqlite: 4 | **S** `UltronStudioIntegrationsCanaryTest` (1); **fixture** `StudioIntegrationsParityTest` (1) |  |
| I2 | Revocar sin borrar el cifrado | CAE (7) | sqlite: 2, pgsql: 5 | **S** `UltronStudioIntegrationsCanaryTest` (1); **T** `UltronStudioIntegrationsSentinelAdversarialTest` (1); **fixture** `UltronStudioIntegrationsParityHttpTest` (1); **S** `UltronStudioIntegrationsSentinelTest` (1) | En PG lo para `uscn_revoke_chk` (500); en SQLite, la de voz de S y mi centinela de filas |
| I3 | Saneado de la sonda: los números conservan friendly_name y el número entero | CAE (32) | sqlite: 32 | **S** `UltronStudioIntegrationsProbeTest` (1); **T** `UltronStudioIntegrationsSentinelAdversarialTest` (1); **S** `UltronStudioIntegrationsSentinelTest` (1); **TW** `TwilioProbePipelineTest` (3); **TW** `TwilioProbeSanitizerTest` (5); **fixture** `StudioIntegrationsParityTest` (1) |  |
| I4 | Hosts sin fijar: en production también se usa la base configurable | CAE (8) | sqlite: 8 | **S** `UltronStudioIntegrationsProbeTest` (1); **TW** `TwilioProbePipelineTest` (6); **TW** `TwilioReadOnlyClientTest` (1) |  |
| I5 | La constante de llamadas a true | CAE (23) | sqlite: 23 | **S** `UltronStudioIntegrationsStatusTest` (1); **V** `VoiceBoundaryTest` (1); **fixture** `StudioIntegrationsParityTest` (1); **V** `VoiceCallPolicyTest` (2); **V** `VoiceIsolationTest` (1) |  |
| I6a | Coherencia sin try: un fallo de la lectura sube como excepción | CAE (1) | sqlite: 1 | **S** `UltronStudioIntegrationsCanaryTest` (1) | Red fina: solo la prueba de Q-6 de S (tanda 2, sin confirmar); el caso `unreadable` del oráculo rompe la PRIMERA lectura, no esta |
| I6b | Coherencia: un fallo da in_sync en lugar de unreadable | CAE (1) | sqlite: 1 | **S** `UltronStudioIntegrationsCanaryTest` (1) | Ídem I6a |
| I7 | Meta fuera de la lista blanca: la lectura llama a refreshStatuses() | CAE (1) | sqlite: 1 | **S** `UltronStudioEngineReadOnlyTest` (1) | Solo la lista blanca estática de `UltronStudioEngineReadOnlyTest` |
| I8 | Cálculo de alias al cachear: token_consistent siempre true | CAE (1) | sqlite: 0, pgsql: 1 | **T** `UltronStudioIntegrationsEnvSentinelAdversarialTest` (1) | Solo mi centinela de entorno: S pone `twilio_aliases` con `config()` y nunca recorre el cálculo al cachear |
| C1 | Activar sin el cerrojo consultivo del canal | CAE (3) | sqlite: 0, pgsql·activaciones_simultaneas o cerrojo_del_canal: 3 | **T** `UltronStudioIntegrationsRaceTest` (2) | Solo las carreras: un proceso no puede cazarlo |
| C2 | El cerrojo sin READ COMMITTED primero (en REPEATABLE READ la instantánea se toma antes de esperar) | CAE (1) | sqlite: 0, pgsql·cerrojo_del_canal: 1 | **T** `UltronStudioIntegrationsRaceTest` (1) | Solo la carrera forzada en REPEATABLE READ |
| C3 | Registrar sin el cerrojo de vivos | CAE (2) | sqlite: 0, pgsql·vivos: 2 | **T** `UltronStudioIntegrationsRaceTest` (2) | Solo las carreras |
| C4 | Asociar sin el cerrojo del tope global | CAE (3) | sqlite: 0, pgsql·campanas_distintas o cerrojo_del_tope o sustitu o asociacion: 3 | **T** `UltronStudioIntegrationsRaceTest` (3) | Solo las carreras |
| C5 | La sonda sin el cerrojo de la caché compartida | CAE (3) | sqlite: 0, pgsql·sonda o pulsacion: 3 | **T** `UltronStudioIntegrationsRaceTest` (3) | Solo las carreras (con la caché `database` compartida) |
| C6 | Sustituir sin anotar la sustituta en la vieja (D-1) | CAE (9) | sqlite: 5, pgsql·sustitu: 4 | **S** `UltronStudioIntegrationsCampaignLinkTest` (1); **fixture** `UltronStudioIntegrationsParityHttpTest` (1); **T** `UltronStudioIntegrationsRaceTest` (4) | También S (el DTO sin `replacedBy`) |
| C7 | Registrar: tras el 23505 no se mira el reintento (el doble clic da canary_number_exists) | **SOBREVIVE** (0) | sqlite: 0, pgsql·doble_clic o misma_client_request_id: 0 | — | **Equivalente**: el `catch` exterior de `register()` (H-1 del H5) vuelve a mirar el reintento y el doble clic sigue dando `replayed`; ver C7b |
| C8 | Reconocer: tras el 23505 no se mira el reintento | CAE (2) | sqlite: 0, pgsql·doble_clic o reconocimientos_con_la_misma: 2 | **T** `UltronStudioIntegrationsRaceTest` (2) | Sin `catch` exterior en reconocer: el 23505 sube como 500; solo el doble clic |
| C9 | Asociar: tras el 23505 no se mira el reintento | **SOBREVIVE** (0) | sqlite: 0, pgsql·doble_clic o asociaciones_con_la_misma: 0 | — | **Equivalente** por la misma razón que C7 (`create()` tiene su `catch` exterior); ver C9b |
| C7b | Registrar: sin mirar el reintento ni tras el 23505 ni en el catch exterior (H-1 del H5) | CAE (2) | sqlite: 0, pgsql·doble_clic o misma_client_request_id: 2 | **T** `UltronStudioIntegrationsRaceTest` (2) | Sin las dos comprobaciones: solo lo caza el doble clic con la misma `clientRequestId` (forzado y natural) |
| C9b | Asociar: sin mirar el reintento ni tras el 23505 ni en el catch exterior (H-1 del H5) | CAE (3) | sqlite: 0, pgsql·doble_clic o asociaciones_con_la_misma: 3 | **T** `UltronStudioIntegrationsRaceTest` (2) | Sin las dos comprobaciones: solo el doble clic |
| E1 | El exportador deja de sustituir el token de Twilio: la huella del de la máquina entra en el fichero del CRM | CAE (1) | sqlite: 1 | **T** `UltronStudioIntegrationsSentinelAdversarialTest` (1) | Mi centinela del exportador (huella con la clave sintética) |
| E2 | El exportador deja de sustituir el SID de Verify: la referencia del de la máquina entra en el fichero del CRM | CAE (1) | sqlite: 1 | **T** `UltronStudioIntegrationsSentinelAdversarialTest` (1) | Mi centinela del exportador (referencia `VA…`) |

F2 (validación de la corrección de H-2 en la copia) quedó corriendo al cortarse la sesión: su resultado está en `mut-out/campaña-extra.txt`; **no lo afirmo**.

## 6. Centinela ampliada

La de S (`UltronStudioIntegrationsSentinelTest`) ya recorre las 18 rutas, el libro, los `facts`, la consola y el log con valores reconocibles en los cinco secretos de I9.6, el teléfono del lead canario y los registrados. Lo que **no** recorría, y ahora sí:

| Qué | Prueba | Resultado |
|---|---|---|
| **El exportador** `ultron-studio:export-fixtures` (su fichero acaba en el repositorio del CRM): con la máquina llena de secretos centinela (Twilio, Meta, ElevenLabs con `tool_secret`, `agent_id` y `agent_phone_number_id`, la llave de la sonda, el SID de Verify, el número de voz del gimnasio, cuentas de Meta propias), canarios registrados, reconocimientos y una sonda | `UltronStudioIntegrationsSentinelAdversarialTest::test_el_exportador_…` | Los 6 ficheros no llevan **ningún** secreto, teléfono, id de cuenta o referencia (`AC…xxxx`, `VA…xxxx`, `***xxxx`) de la máquina, **ni la huella de 8 hex de ningún secreto, ni con la `APP_KEY` real ni con la sintética** del exportador; la `APP_KEY` y la configuración quedan como estaban (D-6 de S confirmada) |
| **Las filas del registro**: ninguna columna (salvo el cifrado de voz) guarda el teléfono ni sus 6 primeras cifras; la huella es exactamente `PhoneFingerprint::of()` y **no** coincide con `sha256(E.164)`, `sha256` de las cifras ni la HMAC con la `APP_KEY` sin subclave; el cifrado de voz descifra al número y desaparece al revocar; el alias, la huella y los 4 dígitos no van al libro | `…::test_ninguna_columna_del_registro_…` | OK en los dos motores. Es la que caza «guardar en claro» en SQLite (en PG lo para antes `uscn_hash_chk`) |
| **Los secretos que la de S no siembra** (`tool_secret`, id del número de ElevenLabs, número de voz del gimnasio, SID de Verify, cuentas propias) por las 18 rutas, `GET audit?subjectType=integration|canary_number`, una deriva de un secreto rotado (409 `config_changed`), los comandos `integrations-doctor`, `integrations-snapshot` y `voice:doctor`, el libro y las instantáneas | `…::test_los_secretos_que_la_centinela_de_s_no_siembra_…` | Ninguno sale; la clave `toolSecret` sí sale (como huella), como dice I5.3 |
| **La configuración calculada de verdad** desde el entorno, en un proceso hijo con `config/*.php` (sin `config([...])`): `TWILIO_TOKEN` y `TWILIO_AUTH_TOKEN` con valores **distintos**, `TWILIO_SID` y `TWILIO_ACCOUNT_SID` distintos, y el resto de secretos del entorno; 5 lecturas y los dos comandos | `UltronStudioIntegrationsEnvSentinelAdversarialTest` (PG) | El panel dice `aliasesConsistent = {sid:false, token:false}` y `source` = `TWILIO_SID`/`TWILIO_TOKEN` (calculado al cargar la configuración, §I12); **ni el valor ni la huella del alias que no se usa** aparecen en respuestas, instantáneas, libro, consola, log o stderr del hijo; la huella que sí sale es la del token que se usa |
| **Las perdedoras de las carreras** (409 `canary_number_exists`, `campaign_already_linked`…) | `UltronStudioIntegrationsRaceTest` (`assertNoPhoneIn`, `assertNoMetaIdIn`) | Ni el teléfono, ni sus cifras, ni su huella, ni los ids de Meta en el cuerpo, el log o el stderr de ninguna sesión |

**Resultado:** PG 4 pruebas, 203 aserciones, OK; SQLite 3 pruebas OK (la del entorno se omite: el hijo no ve una base en memoria). Lo que **no** cubre ninguna centinela de la aplicación: el log del servidor de PostgreSQL (H-2).

## 7. Línea base (por nombre, el mismo día y en secuencia)

`docs/ultron-studio/ops/suite-compare.sh` con `tests/Feature/UltronStudio` y `tests/Unit/UltronStudio`: cada lado es una instantánea `git archive` con `vendor/` copiado y su `.env.testing`; primero la rama y después la base; se comparan los **nombres** de las pruebas en rojo. La rama es el **HEAD confirmado** en ese momento (sin la tanda 2 de S ni mis ficheros, que no están confirmados); la base, `origin/main` = `2660173` (H5 en su fase 1, sin el H6): sirve para ver que nada del Studio del H5 se rompió, no para medir el H6.

**Pendiente:** el guion `cierre.sh` (pasos 5 y 6) seguía ejecutándose cuando se acabó mi sesión. Resultados en `runs/cierre.txt`, `runs/cmp-sqlite.txt`, `runs/cmp-pg.txt` y `cmp-sqlite/logs/rojos-nuevos.txt`, `cmp-pg/logs/rojos-nuevos.txt` del scratchpad. Sin leerlos no afirmo nada sobre la línea base.

## 8. Lo que no verifiqué, y por qué

- **Producción:** nada, ni lectura. Los roles reales `iron`/`iron_app`, `ironbody-migrate` y la configuración de log del PostgreSQL de producción (dónde escribe, retención, `log_error_verbosity`): no los he visto; H-2 está medido en el clúster local con la configuración por defecto de PostgreSQL 14.
- **`CACHE_STORE=database` en producción:** lo tomo del contrato («Hechos de producción al congelar»). Mis carreras de la sonda **fuerzan** esa caché en los hijos porque el cerrojo `Cache::lock` solo pone en fila a procesos con una caché compartida; con la caché `array` de la suite (una por proceso), ninguna prueba en un solo proceso puede cazar que falte (por eso C5 solo lo caza la carrera). Con otra caché en producción (p. ej. `file`), esta garantía no la he medido.
- **El cupo `ultron-studio-probe` entre procesos:** en las carreras de la sonda los hijos usan la caché `database` (también para los cupos), pero no he probado el 429 de la séptima sonda por hora y persona entre procesos; lo prueba S en un solo proceso.
- **Carreras naturales:** dicen lo que pasó en estas corridas, no todos los órdenes posibles; los órdenes difíciles están forzados aparte. La natural de autorizar frente a revocar puede ponerse en rojo por H-1 si el planificador cae en la ventana.
- **El E2E con la pila real** (`h6-integrations.cjs`) no es de este encargo.
- **Marketing, OTP y la suite completa del backend:** no las he corrido (las corre S; mis ficheros son solo pruebas nuevas). La línea base es la del Studio (Feature + Unit), en los dos motores (§7).
- **La tanda 2 de S:** corrí mis pruebas contra el árbol con ella **sin confirmar**; si cambia antes del commit, hay que repetir §3, §4 y §6 (≈ 4 min en PG). La copia de las mutaciones la congela tal como estaba (huellas en `mut-out/mutaciones.jsonl`).

## 9. Reproducir

```bash
cd Iron-Body/backend
createdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_h6_t   # si no existe
# Carreras, guardas y centinelas (PG; ≈ 3 min). Rastro opcional de cada sesión con ULTRON_STUDIO_RACE_LOG=<fichero>.
UNIT_DB=ultron_studio_h6_t bash docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioIntegrationsRaceTest.php \
  tests/Feature/UltronStudio/UltronStudioIntegrationsGuardsAdversarialTest.php tests/Feature/UltronStudio/UltronStudioIntegrationsSentinelAdversarialTest.php \
  tests/Feature/UltronStudio/UltronStudioIntegrationsEnvSentinelAdversarialTest.php
# Diagnóstico de O-2: todas las sesiones en REPEATABLE READ por defecto.
ULTRON_STUDIO_RACE_ISOLATION='repeatable read' UNIT_DB=ultron_studio_h6_t bash docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioIntegrationsRaceTest.php
# SQLite (las de PG se omiten con su motivo).
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/UltronStudio/UltronStudioIntegrations*AdversarialTest.php tests/Feature/UltronStudio/UltronStudioIntegrationsRaceTest.php
# Mutaciones: SIEMPRE en una copia (rsync del árbol + vendor copiado + .env.testing), nunca en el worktree.
php h6-mutations.php <copia>/backend <salida> [ids…]        # MUT_PG_DB=ultron_studio_h6_t_mut; MUT_CHECK=1 comprueba las cadenas
# Línea base por nombre, el mismo día y en secuencia.
bash docs/ultron-studio/ops/suite-compare.sh <dir vacío> origin/main HEAD -- tests/Feature/UltronStudio tests/Unit/UltronStudio
SUITE_PG_DB_HEAD=ultron_studio_h6_t_head SUITE_PG_DB_BASE=ultron_studio_h6_t_base bash docs/ultron-studio/ops/suite-compare.sh <dir vacío> origin/main HEAD -- tests/Feature/UltronStudio tests/Unit/UltronStudio
```

Artefactos de esta sesión (scratchpad): `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h6-Iron-Body/702e9f2d-c4b2-4f9d-9a80-08ca83bbe084/scratchpad/h6-mutations.php` (el corredor), `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h6-Iron-Body/702e9f2d-c4b2-4f9d-9a80-08ca83bbe084/scratchpad/mut/` (la copia congelada: `HEAD.txt`, `wip.diff` y `status.txt` dicen de qué árbol salió), `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h6-Iron-Body/702e9f2d-c4b2-4f9d-9a80-08ca83bbe084/scratchpad/mut-out/` (`campaña.txt`, `mutaciones.jsonl`, junit de cada ejecución), `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h6-Iron-Body/702e9f2d-c4b2-4f9d-9a80-08ca83bbe084/scratchpad/runs/` (corridas y rastros de las carreras) y `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h6-Iron-Body/702e9f2d-c4b2-4f9d-9a80-08ca83bbe084/scratchpad/cmp-sqlite/`, `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h6-Iron-Body/702e9f2d-c4b2-4f9d-9a80-08ca83bbe084/scratchpad/cmp-pg/` (línea base). El scratchpad es de la sesión: si hay que conservarlos, el lead decide dónde (el corredor podría vivir en `docs/ultron-studio/ops/` junto al del H5; ese directorio es de OPS).
