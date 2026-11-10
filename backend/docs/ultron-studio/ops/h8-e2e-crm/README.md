# E2E del CRM de «Voz y telefonía» contra la pila del arnés del H8 (`ops/h8-e2e-crm/`, TEST8-b parte 2)

```bash
H8_E2E_VENTANA= bash backend/docs/ultron-studio/ops/h8-e2e-crm/h8-e2e-crm.sh abierta recorridos   # desde la raíz del worktree del backend
H8_CRM_EVIDENCIA=0 bash backend/docs/ultron-studio/ops/h8-e2e-crm/h8-e2e-crm.sh abierta humo        # solo la fontanería
H8_CRM_HOLD=1 … h8-e2e-crm.sh abierta humo      # deja la pila y el CRM en pie; luego: node recorridos.cjs <run>/crm-context.json
```

**Nunca toca producción ni sale a la red.** La pila es la del arnés del backend (`ops/h8-e2e.sh`, retenida con
`H8_E2E_HOLD=1`): PG desechable, simuladores de ElevenLabs y Twilio de OPS8 en 127.0.0.1 con los adaptadores reales
apuntados a ellos, la valla, `artisan serve --no-reload` y el worker real de `voice`. Chrome no puede resolver ningún
nombre salvo 127.0.0.1 y localhost (`--host-resolver-rules`).

## Qué hace `h8-e2e-crm.sh`

1. Copia el CRM de su commit (`git archive`) en `~/.cache/ironbody-h8-e2e-crm/crm-<sha>` (ruta estable: la caché de
   Angular sirve de una ejecución a otra), enlaza el `node_modules` del worktree del CRM y arranca `ng serve` en :4300
   (su `environment.ts` apunta la API a 127.0.0.1:8080).
2. Arranca el arnés del backend con `H8_E2E_HOLD=1 H8_E2E_EVIDENCIA=0` en la fase pedida y espera su línea «PILA EN PIE»
   (de ahí saca `context.json`: API, puertos, base, rutas de los tokens 600 y de las piezas). No duplica nada de él.
3. Arranca el proxy del E2E del H6 (`e2e/ultron-studio/proxy.cjs` de la MISMA copia del CRM) en :8080 → la API del
   arnés: presenta el origen :4300 como :4200 (el CORS del backend) y anota cada petición sin valores de la consulta.
4. Ejecuta los guiones (`humo`, `recorridos`) con Chrome headless (puppeteer-core del CRM).
5. El cierre: violaciones de los simuladores, la valla, el `lsof` del arnés, `ERROR` en `laravel.log`, `failed_jobs`,
   colas, 5xx del proxy y el barrido del arnés (`h8-e2e/barrido.py`) sobre todo lo que deja este E2E.
6. Para TODO (CRM, proxy y el arnés con SIGTERM, que para su pila), comprueba los 6 puertos libres, escribe el resumen
   con el hash de resultados y copia la evidencia a `ops/evidencia-h8/<fecha>/crm-<sello>-<backend>-<crm>/` (barrida;
   `sha256-evidencia.txt` con el hash de cada fichero).

Variables: `H8_E2E_VENTANA` (la del arnés: vacía = sin ventana de publicar; por defecto 07:00–19:00 de Bogotá, que de
noche cierra publicar) · `H8_E2E_REF` · `H8_E2E_ARBOL=1` y `H8_E2E_FUENTE=<ejecución>/fuente` (las lee el arnés: el
backend del árbol de trabajo, o la copia de una ejecución anterior; su huella va en el nombre de la evidencia) ·
`H8_CRM_DIR` · `H8_CRM_REF` · `H8_CRM_EVIDENCIA` · `H8_CRM_HOLD` · `CHROME_PATH` · `H8_CRM_PASOS=a,b` (solo esos pasos de
`recorridos.cjs`).

**Las salientes desde el CRM dependen de la hora** (E4-6, B8-g): con el proveedor verificado, fuera del horario legal la
puerta se niega con EXACTAMENTE el calendario (oráculo propio con el fixture de festivos); dentro, deja pasar y el paso
corta desde el CRM la saliente que marcó el worker residente. Las dos ramas quedan en el registro con su nombre.

**Parar a mano:** SIGTERM al proceso de `node` del guion y después al lanzador (bash no atiende la señal hasta que
termina lo que tiene en primer plano); su trampa para la pila y el CRM y libera los 6 puertos.

## Las piezas

| Fichero | Qué es |
|---|---|
| `h8-e2e-crm.sh` | El lanzador (arriba) |
| `lib.cjs` | Contexto, sesiones por cuenta, registro de red por CDP, lecturas sin gesto, desbordamiento, foco, barrido de la página |
| `humo.cjs` | Las tres cuentas abren las 7 pestañas (fontanería) |
| `recorridos.cjs` | Los recorridos (abajo) y las transversales |

## Sesiones del CRM

Las cuentas del arnés (`ayudante.php cuentas`) no tienen contraseña: su sesión se emite con el servicio de sesiones del
CRM. El guion la deja en `sessionStorage` ANTES de que arranque Angular (`evaluateOnNewDocument`), como el login sin
«Recordarme» (`auth_token`, `auth_user` con lo que devuelve `GET admin/auth/me`, `auth_session_type`). Es lo único que
el barrido del almacenamiento excluye.

## Costuras y lo que es precondición

- **Precondiciones de hitos anteriores, por la API:** los dos canarios de voz del H6 (Ana registra; Beto autoriza y
  activa) y la versión del Studio del H5 (Ana la crea; Beto la aprueba). No son de esta sección.
- **Lo que viene de fuera** (entrantes, herramientas, fin de llamada, post-llamadas, el sello roto, la post-llamada web
  del laboratorio): el control GET de los simuladores (`/__inbound`, `/__tool`, `/__call`, `/__emit/post-call`).
- **Salientes en `requested` y `dialing`:** las costuras del arnés (`ayudante.php saliente` y `despachar`, el worker de
  UN trabajo de `voice-e2e` con `reloj.php` en un martes a las 10:00). La puerta del CRM no deja pasar ninguna (E4-6).
- **`spec_changed`:** un perfil y una versión aprobados son inmutables, así que entre previsualizar y publicar no hay
  edición que cambie la compilación del mismo par sin un despliegue. Se provoca alterando en el navegador (CDP `Fetch`)
  la huella de la PRIMERA petición de publicar; el 409 `spec_changed` es el real del servidor.
- **«Firefox»:** no está instalado. Se simula en Chrome quitando `sampleRate` de `getSupportedConstraints()`.
- **La URL firmada del laboratorio (TEST8-c):** el simulador firma `ws://127.0.0.1:<puerto>` (el banco no tiene TLS) y
  el lector del CRM solo acepta `wss://` (`studio-voice-parse.ts:1110`; bien hecho: nunca conecta sin TLS). La sesión 1
  usa la URL tal cual y comprueba que el CRM se niega sin abrir ningún WebSocket (Ana la cierra contando lo que pasó).
  La sesión 2 reescribe en el navegador el esquema de la respuesta 201 a `wss://` (CDP `Fetch` en la fase de respuesta,
  como la costura de `spec_changed`): el cliente oficial abre el WebSocket con la URL firmada del simulador, falla el
  TLS (el simulador no modela la conversación) y la sesión la cierra la post-llamada web del emisor.
- **El id del agente por la red:** viaja entero, por diseño, en el `SessionDto` del laboratorio y en la telefonía
  (`GET admin/voice/status`, `calls`, `calls/{uuid}`); el CRM lo enmascara al leer (D-F13). Se admite SOLO en esas
  respuestas y se exige que nunca llegue al DOM (observación O-3 del informe §TEST8-c).
- **El registro de llamadas** enseña del otro extremo solo los 4 últimos dígitos: el servidor no manda el alias de un
  canario (`VoiceCallsQuery.php:13`). El alias con sus 4 dígitos se ve al elegir a quién llamar (diálogo de la saliente).
- **El 429 de la telefonía** (E9-4): el CRM enseña el MENSAJE del servidor y no reintenta solo; el mensaje no dice los
  segundos (queda en el detalle de la comprobación). El paso espera `retry_after` antes de seguir con Carla.

## Trampas

- `page.evaluate` de puppeteer cuenta como gesto del usuario: las lecturas van por CDP con `userGesture: false`.
- `page.select` sobre una opción que todavía no existe no hace nada y no falla: `elegir()` espera a ESA opción y
  comprueba que quedó elegida (era la causa del rojo de «Firefox» de la pasada a medias del 09-11).
- La consola que ve puppeteer mezcla la de la página con lo que escribe Chrome por la red (CDP `Log`, fuente
  `network`): la URL de un WebSocket que falló la escribe Chrome, no la página. `barrerPagina()` las separa.
- `waitForSelector(…, {hidden})` falla con `ib-dialog` (el host mide 0×0): el diálogo es el `[role=dialog][aria-modal]`.
- Angular mete `&ngsp;` (U+E500) entre elementos: el texto se normaliza antes de comparar.
- La consola de Chrome escribe por su cuenta la URL de un WebSocket que falla (`source: network`): se separa de lo que
  escribe la página (`javascript`/`console-api`) con CDP `Log`.
- El simulador no modela el WebSocket del laboratorio: la conversación falla al conectar y la sesión la cierra la
  post-llamada web del emisor del simulador, como en el E2E del backend.
- Las fuentes de Google del `index.html` (anteriores al H8) no resuelven: los iconos salen como texto en las capturas.
- bash 3.2 de macOS: el lanzador usa arrays simples y `${#a[@]}`.
