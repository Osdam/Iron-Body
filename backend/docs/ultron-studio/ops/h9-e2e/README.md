# E2E del Hito 9 con navegador real (prueba #26 del contrato, `ops/h9-e2e/`)

```bash
bash backend/docs/ultron-studio/ops/h9-e2e/h9-e2e.sh                  # desde la raíz del worktree del backend: pila, guion, cierre y evidencia
H9_E2E_VENTANA= bash backend/docs/ultron-studio/ops/h9-e2e/h9-e2e.sh  # sin ventana de publicar (fuera de 07–19 de Bogotá)
H9_E2E_EVIDENCIA=0 … h9-e2e.sh                                          # sin copiar la evidencia al repo
… h9-e2e.sh pila                                                        # la pila en pie hasta SIGTERM; luego: node recorridos-h9.cjs <run>/context.json
H9_PASOS=plantillas,e1 node recorridos-h9.cjs <run>/context.json       # solo esos pasos (humo y barrido van siempre)
```

**Nunca toca producción ni sale a la red.** El backend y el CRM son copias `git archive` de sus commits, fuera de los
repos (`~/.cache/ironbody-h9-e2e/run-*` y `~/.cache/ironbody-h9-e2e-crm/crm-<sha>`); la base es PostgreSQL desechable
(`ultron_studio_h9_e2e`, se BORRA y se recrea) con el modelo de roles de producción (`h9e2e_owner` migra, `h9e2e_app`
solo DML, `h9e2e_lector` solo lectura para el guion); todo proceso PHP sale por la valla (`banco-h8/valla.cjs` del
commit: bloquea y anota el host), y Chrome no resuelve ningún nombre salvo 127.0.0.1 y localhost.

## Las banderas (solo en la copia)

`entorno-h9.ejemplo` → el `.env` de la copia (600). Encendidas, por la orden del lead del 10-11:
`ULTRON_STUDIO_STRATEGIES_ENABLED`, `ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED`, `COMMERCIAL_AUTOMATION_ENABLED`,
`COMMERCIAL_AUTOMATION_MODE=shadow`, `COMMERCIAL_EVENTS_ENABLED`, `ULTRON_STUDIO_STREAM_ENABLED`. La constante
`OutreachSendGate::SENDS_ENABLED_IN_CODE` sigue en `false`: el lanzador lo comprueba en la copia (`grep`) y en la
aplicación arrancada (`ayudante-h9.php preflight`) antes de levantar nada. Todo lo demás (n8n/Meta, ULTRON, voz,
telefonía, Factus, monitor, programador, WhatsApp y voz del H9) va apagado.

## Qué hace `h9-e2e.sh`

1. Copia el backend del commit (con el `vendor` clonado) y el CRM del suyo; compila el CRM UNA vez por commit
   (`ng build --configuration development`: el `environment.ts` que apunta la API a 127.0.0.1:8080) y lo sirve estático
   con `servidor-crm.cjs` en :4300.
2. Base desechable, migraciones como el dueño (sin red), `ultron-studio:import-baseline`, comprobación previa.
3. Valla (:8414) con su sonda positiva (`ConnectionException 403`), `artisan serve --no-reload` con 16 workers (:8411; los canales SSE de las dos sesiones ocupan 4;
   comprueba que TODOS los procesos llevan la valla), muestreo `lsof` cada segundo y el proxy del H6 del CRM (:8080 →
   :8411: presenta el origen :4300 como :4200 para el CORS y anota cada petición sin valores de la consulta).
4. Las dos personas: Ana Autora y Beto Revisor (Super Admin), cada una con su identidad y su perfil laboral sintético
   y una sesión del CRM (token en 600). **El vínculo cuenta → persona lo hace la orden de consola del arranque**
   (`ultron-studio:person-bindings --bootstrap`, P9-24): vinculadas y verificadas.
5. La siembra SINTÉTICA (`ayudante-h9.php sembrar sombra … T`): socios, prospectos y una clase para los tres
   disparadores (tabla abajo), con las fechas relativas a T, el reloj del motor («Costuras»). El manifiesto con nombres y teléfonos va a un fichero 600 y solo lo lee el barrido.
6. El guion (`recorridos-h9.cjs`, abajo) con Chrome headless (puppeteer-core del CRM).
7. El cierre: la valla (0 hosts salvo sus sondas), `lsof`, `ERROR` en `laravel.log`, `failed_jobs`, 5xx del proxy, las
   filas de las 16 tablas del H9 (`ayudante-h9.php filas`) y el barrido (`barrido-h9.py`: nombres y teléfonos
   sintéticos, APP_KEY y sesiones; nunca imprime un valor).
8. Para todo, comprueba los 4 puertos libres, escribe el resumen con el hash de resultados y copia la evidencia a
   `ops/evidencia-h9/<fecha>/e2e/<sello>-<backend>-crm-<crm>/` (barrida; `sha256-evidencia.txt`).

## Las piezas

| Fichero | Qué es |
|---|---|
| `h9-e2e.sh` | El lanzador (arriba) |
| `recorridos-h9.cjs` | El guion con el navegador (abajo) |
| `lib-h9.cjs` | Contexto, sesiones por cuenta, registro de red por CDP con el barrido de cuerpos y eventos SSE, lecturas sin gesto, capturas, barrido de la página, `artisan` con la valla, cambio de modo y reinicio de la API |
| `ayudante-h9.php` | `preflight`, `cuentas`, `sembrar`, `filas`, `revocar` (se niega fuera de la base desechable en 127.0.0.1) |
| `entorno-h9.ejemplo` | La plantilla del `.env` de la copia (sin secretos) |
| `barrido-h9.py` | Nombres y teléfonos sintéticos, APP_KEY y sesiones; nunca imprime un valor |
| `reloj-h9.php` | Carbon desplazado solo en los procesos de `outreach:*` del guion: el reloj del motor (la costura del H8) |
| `servidor-crm.cjs` | El CRM compilado, estático, solo 127.0.0.1 |
| `cinco-xx.py` | Los 5xx del proxy, fuera y dentro de la ventana de reinicio deliberado |

## Las personas sintéticas

| Id | Qué es | Lo que se espera |
|---|---|---|
| S1 | Socia, vence en 7 días, WhatsApp `granted`, canario | Vencimientos: inscrita, planificada y simulada |
| S2 | Socio, vence en 7 días, WhatsApp `denied`, canario | Vencimientos: N1 niega `consent_denied` |
| S3 | Socio, vence en 8 días | Fuera de la ventana de 7: no entra |
| S5 | Socia activa (vence en 30), sin reserva | Clase del día siguiente a T, con cupo: candidata |
| S6 | Socio activo que YA reservó esa clase | La audiencia lo deja fuera |
| L1 | Prospecta con seguimiento vencido, `granted`, canario | Prospectos: inscrita, planificada y simulada |
| L2 | Prospecto con seguimiento vencido, `pending` | N1 niega `no_consent_for_purpose_channel` |
| S4 | (fase asistida) Socia que vence en 7 días, `granted`, canario | Live: trabajo asistido en la bandeja |

Nombres «… Sintetica … E2E», correos en `.invalid`, teléfonos 300 999 01xx; la línea base del Studio exige canario
(N3), por eso cada persona que debe avanzar tiene su número en el registro de canarios (solo la huella y los 4 últimos).

## El guion

| Paso | Qué demuestra desde el navegador |
|---|---|
| `humo` | Las dos sesiones abren `marketing/automatizaciones`; chips del gobierno y de la persona vinculada; canal en vivo `live`; el panel dice sombra y constante cerrada |
| `plantillas` | Ana crea la plantilla asistida (guion sin datos de contacto); ni el botón ni el servidor le dejan aprobarla; Beto la aprueba |
| `e1` | Vencimientos (`membership.expiring_window`, 7 días) con audiencia, éxito y DOS pasos: `aviso` con `no_answer → wait` + `waitFor` 2 días y `no_response → end_exit`, y `seguimiento` a los 2 días. Ana crea (H9-E2E-1, abajo), guarda, valida en el servidor (misma huella que la forma en vivo), simula el borrador (una persona `plan`, otra `deny` en N1, y la de H9-E2E-2), congela y simula la VERSIÓN (la puerta de aprobar); Ana no puede aprobar; Beto reconoce los avisos, aprueba y publica en canario (huella del diálogo = la de la versión; entrada `publish` VERIFICADA en la cadena) |
| `e2` | Lo mismo con `class.upcoming_with_capacity` |
| `e3` | Lo mismo con `lead.followup_due` |
| `consola` | Con el panel de Ana abierto, `outreach:sweep` por consola: el canal en vivo trae `outreach.runs` sin recargar; filas de inscripciones y decisiones (N1 con su motivo); después `outreach:dispatch --inline`: trabajos `simulated` y resultados `not_attempted` |
| `panel` | El panel enseña las pasadas, las inscripciones y los trabajos; la decisión se explica en frases y su `traceHash` es el de la base |
| `parada` | Con el panel de Beto abierto, Ana echa la PARADA GLOBAL: a Beto le llega sola (`outreach.brakes`); el despacho no reclama nada; Ana intenta soltarla desde el CRM y el servidor se lo niega (H9-E2E-3); Beto la suelta con nota |
| `pausa` | Beto pausa la campaña de prospectos (entrada `pause` verificada); la pasada siguiente (`--force`) no la planifica |
| `asistido` | (Desviación, abajo) modo `live` con el asistido: S4 llega a la bandeja; antes de reclamar no hay ficha ni guion; Beto reclama, ve el guion y la ficha, Ana no; Beto registra `answered` y la inscripción cierra |
| `barrido` | DOM, almacenamiento, URL y consola de las dos sesiones en 5 pestañas; cuerpos de TODAS las respuestas de la API y eventos SSE; 0 hosts fuera de 127.0.0.1; 0 respuestas 5xx; 0 errores de página |

## Desviaciones y costuras (todas a la vista en la evidencia)

- **Fase asistida en `live`.** En sombra el despacho usa siempre el canal simulado y la bandeja asistida no recibe nada
  (`OutreachDispatcher`, docblock: «en sombra el canal es el simulado; en vivo, el asistido abre la tarea»). Para
  «reclamar y resultado» el guion cambia en el `.env` de la COPIA solo `COMMERCIAL_AUTOMATION_MODE=live`,
  `COMMERCIAL_AUTOMATION_ASSISTED_ENABLED=true` y `…_ASSISTED_DAILY_CAP=20`, reinicia la API (con la valla, comprobado)
  y repite la comprobación previa (constante cerrada). El asistido es trabajo de una persona; WhatsApp y voz siguen
  apagados y, además, son puertas vacías hasta W9/V9.
- **El reloj del MOTOR.** Toda orden `outreach:*` por consola corre con `now()` de Carbon en T, el próximo día hábil a
  las 10:00 de Bogotá (`reloj-h9.php`, `auto_prepend_file`; la costura del H8), y avanza de una orden a la siguiente; la
  siembra (vencimientos, la clase, los seguimientos) se calcula relativa a T, y las simulaciones del CRM usan T como
  instante. El despacho corre en el último `not_before` planificado + 1 s: el planificador pone un desfase determinista
  de 0–14 min por persona (`OutreachPlanner::OFFSET_MINUTES`). Así el resultado no depende de la hora a la que corra el
  E2E (fuera del horario legal, la Ley 2300 aplaza todo al primer hueco). El navegador, la API (creación, aprobación,
  publicación, pausa, frenos, bandeja) y la base siguen con el reloj real; T va en el resumen y cada instante en
  `logs/consola-*.out`.
- **El canal en vivo y el reloj del motor.** El cursor de `outreach.runs` y `outreach.jobs` avanza por `updated_at`
  (`OutreachTopic::compositeSince()`): las filas que escribe el motor en T quedan «en el futuro», así que la PRIMERA
  pasada sí se emite (está después del cursor), pero las filas reales posteriores no saldrían hasta que el reloj real
  alcanzara T. Por eso el canal se comprueba con esa primera pasada (`outreach.runs`) y con la PARADA GLOBAL
  (`outreach_brakes`, escrita por la API con el reloj real). Observación para SSE9: el cursor compuesto supone relojes
  monótonos entre procesos; un proceso con el reloj adelantado deja callado el tema hasta que el resto lo alcanza.
- **`outreach:sweep --force`** en las pasadas posteriores (tras la pausa y en la fase asistida): la cadencia del
  disparador (15–60 min) omitiría la estrategia; `--force` es la opción de la orden para eso.
- **El reinicio de la API** (fase asistida) corta los canales SSE abiertos: los 502 del proxy DENTRO de esa ventana
  (`reinicios-api.jsonl`, 5 s de margen; `cinco-xx.py`) no cuentan como 5xx, y se dicen aparte. Cualquier otro 5xx sí.
- **H9-E2E-1 (alta de estrategias).** El alta responde 201 pero el CRM no puede leer la respuesta (`draft.content: []`):
  el guion lo deja en ROJO y sigue con un borrador mínimo `{"format":"strategy-content/1"}` guardado por la API como Ana
  (`PUT strategies/{uuid}/draft`, la misma ruta que «Guardar borrador»).
- **H9-E2E-2 (simulación sintética).** Las personas de prueba que deben planificarse llevan un evento inocuo
  (`app_linked` hace 24 h): sin eventos, el CRM manda `events: null` y N6 las niega. Una tercera persona sin eventos deja
  el hallazgo en ROJO en cada vuelta.
- **CRM compilado en vez de `ng serve`.** Con la máquina cargada, la primera compilación del servidor de desarrollo no
  terminaba en 10 minutos por vuelta. El resultado (`development`, mismo `environment.ts`) se compila una vez por commit.

## Trampas

- Las del E2E del CRM del H8 (`ops/h8-e2e-crm/README.md`): `page.evaluate` cuenta como gesto (las lecturas van por CDP
  sin gesto), `&ngsp;` de Angular, el diálogo es `[role=dialog][aria-modal]`, `--no-reload` con PHP_CLI_SERVER_WORKERS.
- Los `<select>` del constructor se ponen con `value` + eventos `input`/`change` (lo que hace `page.select`): el guion
  comprueba antes que la opción existe y después que quedó elegida.
- Las claves de plantillas y estrategias llevan la hora de la vuelta: el guion se puede relanzar sobre la misma pila.
- «Un grupo Y» del editor de condiciones nace con un hecho vacío; «Añadir un hecho» de la simulación se vacía solo tras
  añadir (no se exige que conserve el valor).
- `started` es estado FINAL del trabajo asistido: lo que cierra la tarea es el resultado del personal (sale de la bandeja).
- Los workers de `php -S` pueden quedar huérfanos (PPID 1) escuchando en el puerto: `pararApi()` mata también a quien
  escuche en él y `arrancarApi()` exige que quien escucha descienda del proceso nuevo.
- Con la máquina saturada por otros procesos, `E2E_TIMEOUT_SCALE=3` (o 4) multiplica las esperas del guion.

## Comprobar que el E2E caza un fallo (mutación)

Sobre una pila retenida (`h9-e2e.sh pila`), se muta SOLO la copia de `~/.cache/ironbody-h9-e2e/run-*/backend` (nunca el
árbol), y se corre el paso que lo cubre (`H9_PASOS=parada node recorridos-h9.cjs <run>/context.json`); `php -S` lee el
fichero en cada petición. El 10-11 se retiró la guarda de cuatro ojos al soltar un freno (`OutreachStop.php:327`,
`if (false && …)`): el guion pasó a ROJO en «la API rechaza la suelta por la misma persona (403 four_eyes_required)»
(la API dio 500) y la parada siguió abierta porque la base la paró con `obrk_four_eyes_chk` (defensa en profundidad).

## Última vuelta

`ops/evidencia-h9/2026-11-11/e2e/20261111T001701Z-d701b0c8-crm-9572426/` (backend `d701b0c8`, CRM `9572426`): 194
comprobaciones, 182 OK y 12 FALLO, todos de los hallazgos H9-E2E-1/2/3; hash de resultados `ae61f029…`, idéntico en dos
vueltas seguidas. Informe: `plans/informes-h9/informe-tester-final.md` §B.
