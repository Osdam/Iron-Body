# Informe SSE9-f · Tiempo real del Studio en el CRM (frontend)

**Paquete:** SSE9 (contrato H9, sección «Paquete SSE9»). **Agente:** frontend (SSE9-f). **Fecha:** 09-11-2026.
**Dónde:** `_worktrees/ultron-h8/Iron-Body_Front` (rama `feat/ultron-studio-h8-voice`), por orden del lead; el contrato nombra `_worktrees/ultron-h9/Iron-Body_Front` (rama `feat/ultron-studio-sse`). **Sin commit. Sin tocar producción.**

> Estado de este informe: **FINAL** (09-11-2026, 21:0x UTC-5). Verificación cerrada en §3; lo que no se pudo verificar, en §5; pendientes (ninguno propio), en §6.

## 1. Qué se entrega

### 1.1 `src/app/services/studio-stream.service.ts` (+ `studio-stream.service.spec.ts`)

El canal único del Studio en el CRM, sobre la misma infraestructura que membresías, Clases y Caja (`ensureStreamTicket`/`withStreamToken` de `auth.service.ts`, el tique de `GET admin/stream-ticket`, `EventSource`). **Alineado con el backend que SSE9-b escribió en paralelo** (`UltronStudioStreamController`, `StreamCursors`, `StreamEvent`), no con la lectura literal del contrato:

| Contrato / brief | Lo que hace el backend de SSE9-b | Lo que hace el cliente |
|---|---|---|
| `Last-Event-ID` por tema, `id` = `<tema>:<cursor>` | el `id` SSE es el **vector** de cursores de todos los temas suscritos (`ledger:123,voice.calls:9`); los cursores son **texto opaco** `[A-Za-z0-9._-]{1,64}`; la reconexión manda el vector en `?after=` (un `EventSource` nuevo no manda la cabecera) | guarda el vector entero en cada evento y lo devuelve en `?after=`; repetidos descartados (mismo cursor, o menor si los dos son numéricos) |
| — | `event: ready` al abrir, `data: {topics: {tema: cursor}, resync: true}` | fija los cursores de partida; con `resync !== false` pide **releer** a todos los oyentes (también en cada reconexión: así lo dicta el servidor hoy, ver Q-3); con `resync: false`, nada |
| — | `event: stream.notice`, `data: {type: 'topic_unavailable', id: <tema>}` | ese tema pasa a **sondear él solo** hasta el siguiente `ready` |
| 409 `stream_disabled` → sondeo | también 403 `stream_no_topics`, 422 (tema o vector malos) | `EventSource` no da el estado HTTP: un `error` **sin haber abierto** y con `readyState === CLOSED` es un rechazo → **sondeo en el acto**, se olvidan los cursores (por si el rechazo era el `after`) y se sigue reintentando con retroceso |
| canal falla N veces → sondeo | — | N = 3 caídas seguidas sin abrir (red) → sondeo; retroceso 1, 2, 4 … 30 s; al volver, releer una vez y parar el sondeo |
| cierre a los 55 s normal | `retry: 3000` dictado al navegador; cierre a `max_seconds` | se cierra el `EventSource` y se reabre al **segundo** con tique fresco y `?after=`; no cuenta como fallo |

API del servicio: `subscribe(tema, oyente)` (baja por función), `events(tema)` (observable), `refreshes(tema, {pollMs})` (hechos + relecturas + `poll` cada `pollMs` **solo** mientras el tema no tiene canal), `mode` (señal: `off | connecting | live | polling`), `mode$`, `pollingReason` (`rejected | failing`), `topicsUnavailable`. Una conexión por pestaña con la unión de temas (`?topics=`, ordenados); cambiar los temas reabre sin contarlo como caída; dos paneles que montan a la vez abren **una** conexión. Agrupación de 350 ms por tema. Nada en consola; el tique solo vive en la URL del `EventSource`.

`data` de cada hecho: `{type, id, state, at}` más `hash`/`ref` cuando llegan (`ref` = tipo de sujeto en el libro). El cliente nunca pinta el evento: **cada panel vuelve a pedir lo suyo**.

### 1.2 H-1 · parser del laboratorio hablado (`studio-voice-parse.ts:1035`)

Causa raíz: el lector cruzaba `actions.end.allowed` con el estado («solo se termina una sesión abierta»). En el servidor (`StudioVoiceLabSessions::actions()`) `end` también es «contar lo que pasó»: se ofrece a quien abrió una sesión que el proveedor ya cerró (`status: ended`, `endReason: provider_report`) mientras falte su informe. Corrección: se quita ese cruce (el estado manda; `actions` es lo que el servidor ofrece y vuelve a comprobar al actuar). El cruce del **veredicto** se mantiene. Caso añadido al spec con la captura real `labSessionEnded` en la forma literal del E2E (`reported.tester: false`, `actions.end: {allowed: true}`), más la captura tal cual (`voice_lab_already_reported`) y el negativo del veredicto.

### 1.3 FE8-4 · textos y campos de S8-d / B8-g

- Textos: `missing_post_call` (evidencia), `number_unverified` y `number_mismatch` (puerta de salientes y motivos de acción), `voice_destinations_unreadable` (motivos de acción), `not_applicable_outbound_only` (número solo saliente), `addressFormText`, `missingPostCallText`, `entryNumberText`.
- Medida `missingPostCall`: séptima clave de `VoiceEvidenceMeasuresDto`, **leída como opcional** (`null` si el servidor no la manda) y pintada en la evidencia del canario («Sin post-llamada»); «Llamadas selladas» pasa a «Llamadas con post-llamada» con su nota. Motivo de leerla opcional: el backend desplegado en producción (`fe29126`) es anterior a B8-g y las capturas del banco (`backend-voice.testing.ts`, commit `af6bd805`) traen seis claves; una lectura estricta rompería el panel contra producción hasta el siguiente despliegue (Q-2).
- `entries[].number` (`{action, outcome, reason} | null`) en `VoiceChainEntryDto`, leído con el mismo lector que las escrituras (`leerNumero`), pintado en cada entrada `publish` de la cadena («Número: no se tocó; es solo saliente…» / «sin registro en esta entrada» en las anteriores a S8-d).
- Trato (`content.addressForm`): `VoiceAddressForm = 'tu' | 'usted'`; el contenido del perfil lo lee (conjunto cerrado; ausente = `tu`; `addressForm` entra en `CLAVES_DEL_CONTENIDO`), el detalle del perfil lo enseña («Trato»), y el diálogo del perfil tiene el selector **solo si el servidor anuncia `template.aiNotices`** (los dos tratos y solo ellos; si falta uno, la plantilla no se cree y el formulario no se abre); el aviso de IA mostrado cambia con el trato; `content.addressForm` viaja **solo** a un servidor que lo conoce (uno anterior rechazaría el campo). Los avisos vienen del backend (`aiNotices`), no se inventan.

### 1.4 Cableado del canal en los paneles

| Panel | Tema(s) | Qué relee al recibir el aviso |
|---|---|---|
| Voz · Llamadas | `voice.calls`, `voice.reconcile` | lista con su consulta actual, detalle abierto, métricas; evidencia como mucho cada 30 s (su GET está limitado a 10/min) |
| Voz · Estado (freno, puertas) | `voice.brake`, `voice.reconcile` | frenos, telefonía, ruta 45 (una relectura por tanda) |
| Voz · Publicación y evidencia | `voice.publications` | publicaciones, evidencia, telefonía, ruta 45 |
| Voz · Laboratorio | `voice.lab` | estado del laboratorio, sesiones |
| Tablero · Versiones | `ledger` (sujeto `version`) | lista y detalle abierto (no mientras se escribe) |
| Tablero · Auditoría | `ledger` | la página que se mira |
| Tablero · Historial de la cadena y barra de publicación | `ledger` (sujeto `version`) | la página abierta / la ruta de estado |
| Monitor e incidentes | `monitor.incidents` | **no hay panel en el CRM** para este módulo; el tema queda disponible en el servicio |

Todo pasa por `refreshes(tema, {pollMs: STUDIO_STREAM_POLL_MS})` (60 s): sin canal, un sondeo por minuto y panel; con canal, cero sondeo. Las secciones de voz se releen **solo si ya están leídas** (`ready`/`error`): una `idle` la pide su pestaña y una `loading` vuelve con datos frescos (así el `ready` de arranque no duplica las lecturas iniciales).

Indicador: `us-live-indicator` (`studio-live-indicator.ts`), un chip con icono y texto («En vivo», «Sondeo», «Conectando…»; nada sin sesión), con los tokens del Studio (`--us-cyan`, `--us-muted`), en la cabecera de **Voz y telefonía** y en la del **tablero**.

## 2. Ficheros

Nuevos: `src/app/services/studio-stream.service.ts`, `src/app/services/studio-stream.service.spec.ts`, `src/app/modules/ultron-studio/studio-live-indicator.ts`, `src/app/modules/ultron-studio/studio-live-indicator.scss`.

Modificados: `studio-voice-parse.ts`, `studio-voice-parse.spec.ts`, `studio-voice-api.types.ts`, `studio-voice-text.ts`, `studio-voice-publications.ts`, `studio-voice-profiles.ts`, `studio-voice-profile-dialog.ts` (+ `.scss`), `studio-voice-session.ts`, `studio-voice-api.service.ts`, `studio-voice-page.ts`, `ultron-studio-page.ts`, `studio-versions-panel.ts`, `studio-audit-panel.ts`, `studio-publication-history.ts`, `studio-publication-bar.ts` (todos bajo `src/app/modules/ultron-studio/`).

## 3. Verificación

| Comprobación | Resultado |
|---|---|
| `tsc -p tsconfig.spec.json --noEmit` (todos los specs y lo que importan) | **limpio** (0 diagnósticos) |
| `tsc -p tsconfig.app.json --noEmit` | 2 errores, **ambos en `src/app/modules/commercial-automation/commercial-automation-canonical.ts` (FE9, ajeno)**: `TS1161` (73,40) y (74,24); ninguno en ficheros de SSE9-f |
| `tsc` de la app **excluyendo** `commercial-automation/**` (tsconfig temporal, borrado después) | **limpio** |
| `npm run check:literals` | 381 ficheros, literales correctos |
| Prettier | no es puerta del proyecto: los ficheros hermanos (`membership-stream.service.ts`, `studio-voice-text.ts`…) tampoco pasan `--check`; no se reformateó nada |
| `ng test` en el árbol real (canal + `ultron-studio/**/*.spec.ts`), 20:40–20:50 | **no ejecutó ninguna prueba**: el compilador del builder tumba el programa entero por `commercial-automation-canonical.ts` (FE9): `TS1161`, `TS2362`, `TS2769`, `TS2304` en las líneas 73–75. Por eso la medida válida se hace en la copia aislada (fila siguiente), como indicó el lead |
| Spec del canal (`studio-stream.service.spec.ts`, **17 pruebas**) + specs del módulo Studio (`ultron-studio/**/*.spec.ts`), en la copia aislada (HEAD + SSE9-f, sin FE9) | **82 ficheros, 1184 pruebas: en verde.** Pasada completa: 1182 en verde y 2 en rojo, las dos de prueba y no de código: (a) la aritmética del retroceso en mi spec («tres caídas seguidas…»: el reloj va 50 ms por delante del primer intento); (b) la paridad D8-12 (`studio-voice-parity.spec.ts`), que exigía los 26 motivos de salientes del banco y el CRM ya lleva los 28 de B8-g (`number_unverified`, `number_mismatch`, que el banco `af6bd805` no conoce): la prueba comprueba ahora que los 26 del banco siguen en su orden y que los únicos añadidos son esos dos, tras `voice_number_is_whatsapp`, con la nota de volver al `toEqual` al regenerar el banco. Repetidos los dos ficheros: **33/33 en verde** (2,58 s). Pasadas previas del spec del canal en el árbol real, antes de que apareciera el fichero de FE9: 10/13 (primera versión, aún con `last_event_id` y cursores numéricos) y 13/15 (ya con el protocolo real de SSE9-b; los dos fallos eran de la propia prueba: un `id` ajeno guardado en el vector y la misma aritmética) |
| `ng build --configuration production` **antes** (copia aislada de HEAD `45dd73a`, sin cambios) | `ultron-studio-page` **719,75 kB** (148,56 kB transferidos) · `studio-voice-page` **304,68 kB** (67,57 kB) · `studio-lab-page` 188,58 kB · `main` 149,39 kB · inicial 829,07 kB (los avisos de presupuesto son los de siempre) |
| `ng build --configuration production` **después** (copia aislada = HEAD `45dd73a` + los ficheros finales de SSE9-f, **sin** `commercial-automation/` de FE9, que hoy no compila) | **exit 0.** `ultron-studio-page` **720,70 kB** (+0,95 kB; 148,72 kB transferidos, +0,16) · `studio-voice-page` **310,70 kB** (+6,02 kB; 68,98 kB transferidos, +1,41) · chunk compartido nuevo con el servicio del canal y el indicador **10,28 kB** (lo cargan el tablero y la voz) · `studio-lab-page` 188,58 kB (igual) · `main` 149,39 kB (igual) · inicial 829,59 kB (+0,52) · JS total ≈ **+17,8 kB (+0,27 %)**. Mismos avisos de presupuesto que antes (ninguno nuevo) |

## 5. Lo que NO se pudo verificar y por qué

- `ng build` y `ng test` **en el árbol real**: bloqueados por el fichero de FE9 (`commercial-automation-canonical.ts`, TS1161 y derivados; `tsconfig.app.json` incluye `src/**/*.ts`). Se midió en una copia aislada idéntica salvo ese directorio; cuando FE9 lo arregle, basta repetir `ng build --configuration production` en el árbol para confirmar las mismas cifras.
- El canal **contra el servidor real**: SSE9-b estaba escribiéndolo en paralelo; el cliente se alineó leyendo su código (`?after=`, vector de cursores, `ready`, `stream.notice`, 409/403/422), no con una conexión real. La prueba T24 de extremo a extremo (CRM ↔ backend con la bandera encendida) queda para el tester del paquete.
- El sondeo de respaldo y el indicador en un navegador real: cubiertos por specs (jsdom, `EventSource` falso), no vistos en Chrome.

## 6. Pendientes

- **De SSE9-f: ninguno.** Sin commit (regla del lead).
- Ajenos: el arreglo de FE9 (U+2028/U+2029 escapados) para que el árbol real compile; la regeneración del banco de voz (`gen-backend-voice-3.py`) contra un backend con B8-g, que permitirá endurecer `missingPostCall` (Q-2) y devolver D8-12 al `toEqual`.

## 4. Q-n para el lead

- **Q-1 · Worktree — RESUELTA por el lead (L9-48).** El contrato sitúa SSE9-f en `_worktrees/ultron-h9/Iron-Body_Front` (rama `feat/ultron-studio-sse`); por orden del lead el trabajo está en `_worktrees/ultron-h8/Iron-Body_Front` (rama `feat/ultron-studio-h8-voice`). Decisión: no se porta nada; el commit del CRM del cierre del Hito 8 llevará el servicio y el cableado **dormidos** (sin el canal en el servidor, el CRM queda en sondeo sin ruido: 404 → `polling` en el acto, reintento cada 30 s → 2 min, nada en consola; cubierto por spec), junto con H-1 y FE8-4; `commercial-automation/` (FE9) se excluye y va a la rama del Hito 9.
- **Q-2 · `missingPostCall` opcional.** Leída como opcional (`null` → «Sin medir en este servidor») por compatibilidad con el backend desplegado y con las capturas del banco. Cuando B8-g esté en producción y se regenere `backend-voice.testing.ts` (gen-backend-voice-3.py), puede pasar a obligatoria (una línea en `leerRecuentos`).
- **Q-3 · `resync: true` en cada reconexión.** SSE9-b emite `ready` con `resync: true` también al reconectar con `?after=`: cada panel suscrito relee **cada 55 s** (una lectura por panel por minuto con el canal encendido, que es lo que el canal pretendía evitar; la evidencia de voz tiene un límite de 10 lecturas/min). El cliente ya respeta `resync: false`: si SSE9-b lo manda cuando honró el `after` exacto (como el canal de Clases), la relectura periódica desaparece sin tocar el CRM. Hasta entonces, el coste es ese.
- **Q-4 · Ritmo del sondeo de respaldo.** 60 s por panel (constante `STUDIO_STREAM_POLL_MS`). Hoy ningún panel del Studio sondea: con la bandera apagada en producción esto AÑADE una lectura por minuto y panel abierto. Si se prefiere conservar exactamente el comportamiento actual (solo manual), basta con no pasar `pollMs`.
- **Q-5 · Rechazo vs. 409.** Sin el cuerpo del 409 (`EventSource` no lo expone), el CRM no distingue «bandera apagada» de «403» o «5xx»: todos son «Sondeo» (el título del chip lo explica). Distinguirlos exigiría una sonda HTTP aparte; no se ha hecho.
- **Q-6 · `monitor.incidents`.** No hay panel de monitor/incidentes en el CRM (el monitor es `outreach:monitor`, del H9/B9-run): nada que cablear hoy; FE9 lo consumirá con el mismo servicio.
- **Q-7 · Ficheros ajenos en el worktree.** Durante el trabajo apareció sin seguimiento `src/app/modules/commercial-automation/` (otro agente, 20:23–20:33 UTC); `tsc -p tsconfig.app.json` lo rechaza (`commercial-automation-canonical.ts(73,40) TS1161`, U+2028/U+2029 literales en un regex). `tsconfig.app.json` incluye `src/**/*.ts`, así que `ng build` lo compila. No es de SSE9-f.
