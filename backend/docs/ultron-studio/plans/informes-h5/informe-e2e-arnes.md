# Hito 5 · paso 1e (A10.5): arnés E2E de ULTRON STUDIO como activo del repo

**Autor:** tester · **Fecha:** 24/25-10-2026 (noche de Bogotá) · **Estado:** entregado; **130/130 en verde** contra `origin/main` de los dos repos

## 1. Qué se entrega

Arnés en `Iron-Body_Front/e2e/ultron-studio/` (rama `feat/ultron-studio-h5` del CRM, sobre `5c1dbd7`). No toca `src/` (lo edita otro agente en paralelo), no añade dependencias (`puppeteer-core` ya estaba) y no sale a la red.

```bash
bash e2e/ultron-studio/run.sh <backend-ref> <crm-ref> [spec ...]
bash e2e/ultron-studio/run.sh 0366009 5c1dbd7          # origin/main de los dos repos
```

| Fichero | Qué hace |
|---|---|
| `run.sh` | Instantánea del backend (`git archive` + vendor **copiado**, con comprobación de que `App\` sale de ella), instantánea del CRM (`git archive` + `node_modules` enlazado), `.env` desde `.env.example` (entrecomillando valores con espacios y validado con Dotenv), PG desechable `ultron_studio_e2e` en `127.0.0.1:55432`, migraciones + `import-baseline` + `lab-import-scenarios` + semilla, `artisan serve --no-reload` :8092 con `PGGSSENCMODE=disable`, workers reales (`lab/studio-lab`, `whatsapp/whatsapp-high`, `commercial/commercial`), proxy :8080, `ng serve` :4300, simuladores y valla de red; PIDs con el patrón correcto y parada de nietos, hijo y padre con comprobación de puertos |
| `lib/make-env.mjs`, `lib/boot.php`, `lib/seed.php`, `lib/seed-accounts.php` | `.env`; arranque de Laravel desde la instantánea con negativa fuera de `ultron_studio_*`/127.0.0.1/no-producción; semilla (plan y conversación canaria creada con los mismos servicios que la ingesta de Meta); cuentas |
| `accounts.mjs` | Autora, revisora, publicadora, lectora y Administrador del sistema (sin `publish`), contraseñas aleatorias por ejecución, login real comprobado; se niega si la API no es local o si `APP_ENV=production` |
| `proxy.cjs` | :8080 → :8092; traduce el origen :4300 → :4200 (CORS del backend) y registra cada petición sin valores de consulta, cabeceras ni cuerpos |
| `mock-openai.cjs` | Responses API (:8093): valida `Authorization` con la clave del laboratorio de la ejecución, `store:false`, `json_schema` estricto en todos los niveles, modelo, `max_output_tokens` ≤ 1600, un único mensaje de usuario y sin `tools` |
| `mock-n8n.cjs` (nuevo) | :8094: verifica bearer + HMAC del webhook de ULTRON con el secreto de pruebas, comprueba que el evento es el mínimo del contrato, llama a `ai/decide` y `ai/commit` (bearer + HMAC del secreto interno) con una propuesta determinista sin cifras ni enlaces, reintenta una vez con `recovery` si el commit da un 422 de guardia y responde al final como n8n |
| `meta-webhook.mjs` (nuevo) | Entrante de WhatsApp firmado (`X-Hub-Signature-256`) para la conversación canaria de la semilla; `--bad-signature` para el negativo |
| `fence.cjs` (no pedido) | Valla de red: proxy HTTP(S) que rechaza y anota cualquier salida del backend que no sea a 127.0.0.1 |
| `specs/` | `lib.cjs` y cinco especificaciones: `f-versions`, `g-inspector-compare`, `h4-lab-fake`, `ultron-turn`, `cierre` |
| `mutations.sh` | Pasada de mutaciones sobre una pila en pie (M1–M5): rompe en la instantánea lo que cubre cada spec, comprueba que cae y lo revierte |
| `README.md` | Uso, piezas, cuentas, especificaciones, mutaciones y las 22 trampas ya pagadas |

## 2. Decisiones que concretan el contrato (o lo exceden)

1. **El carril de ULTRON son dos workers**: la ingesta del entrante (`ProcessMetaWebhookEvent`, cola `whatsapp-high`, donde corre `UltronEventEmitter`) y la entrega a n8n (`SendUltronEventToN8n`, cola `commercial`). Se arrancan los dos con los comandos de `docs/ops/supervisor/ironbody-workers.conf`, más el del laboratorio.
2. **Valla de red** (no estaba en A10.5): el backend y los workers corren con `http(s)_proxy` apuntando a `fence.cjs` y `no_proxy=127.0.0.1,localhost`. Una sonda HTTPS al arrancar demuestra que la valla caza; el cierre exige cero intentos. Límite: cubre los clientes que respetan las variables de proxy (Guzzle/cURL, el `Http::` de Laravel), no sockets crudos.
3. **CORS por el proxy**: `config/cors.php` solo admite `:4200`; el proxy traduce el origen en vez de tocar el backend.
4. **La autora tiene `ultron.approve`**: sin la llave, su 403 sería de permiso y no probaría los cuatro ojos por identidad.
5. **Publicar queda apagado** (`ULTRON_STUDIO_PUBLISHING_ENABLED=false`, como en producción): la respuesta esperada es 409 `publishing_disabled`.
6. **`CACHE_STORE=array`** (como en los arneses anteriores): anula los limitadores; el arnés no cubre los 429.
7. **Estado por ejecución en `~/.cache/ironbody-e2e/ultron-studio/`** (0700), fuera de `/tmp`. El vendor copiado (117 MB) se borra al terminar salvo `KEEP_VENDOR=1`.
8. **Simulador de n8n con rendición**: si el commit rechaza el borrador con un 422 de guardia, repite una vez con `recovery {reason, attempt: 1}`, como el workflow real.

## 3. Ejecución contra `origin/main` (`0366009` + `5c1dbd7`)

`E2E_TIMEOUT_SCALE=2 bash e2e/ultron-studio/run.sh 0366009 5c1dbd7`, 25-10-2026 03:20–04:08 UTC, ejecución `~/.cache/ironbody-e2e/ultron-studio/run-20261025T032043Z-0366009-5c1dbd7/`. **Salida 0.**

```
== Resumen
   ✓ f-versions: 39/39
   ✓ g-inspector-compare: 25/25
   ✓ h4-lab-fake: 27/27
   ✓ ultron-turn: 30/30
   ✓ cierre: 9/9
   TOTAL 130/130
== Duración: 2853 s
== Parada
   procesos parados; registros en …/run-20261025T032043Z-0366009-5c1dbd7/logs
```

Preparación (misma salida): instantánea con `App\` cargado de ella ✓; `make-env: 44 sustituidas, 6 añadidas, 1 entrecomilladas (FACTUS_CONSUMER_FINAL_NAME)` y `.env` aceptado por Dotenv ✓; 313 migraciones; semilla `{"canaryConversationId":1,"leadId":1,"plans":1}`; 8 workers de `php -S`; la sonda de la valla registrada y bloqueada ✓; 5 cuentas con login comprobado.

**Duración.** 2853 s, de los que 1790 s fueron la primera compilación de `ng serve`: la máquina compartía CPU y memoria con otras suites y aplicaciones (carga 20–30, swap 4,5 de 5 GB, `ng serve` paginado a 33 MB de RSS). Por eso las esperas son escalables (`E2E_TIMEOUT_SCALE`) y el CRM se extrae ahora en una ruta estable por commit, para que la caché de Angular sirva a la siguiente ejecución. Las specs: F 532 s, G 217 s, H4 231 s, turno 38 s, cierre 9 s.

### 3.1 El turno simulado llega al commit en `dry_run` sin salir a la red

Registro del simulador de n8n (`logs/mock-n8n.jsonl`, completo; sin secretos ni textos):

```
{"step":"webhook","signatureOk":false,"rejected":"signature","idempotencyKey":"e2e-negativo","message_id":999999999,…}   ← negativo: HMAC malo → 401
{"step":"webhook","signatureOk":true,"event":"marketing.message.received","idempotencyKey":"wamid.E2E871812F3A83D55BD06ED2524","conversation_id":1,"message_id":1,"textChars":4,"extraKeys":[]}
{"step":"decide","conversation_id":1,"message_id":1,"status":200,"ms":5391,"superseded":false,"phase":"NEW_LEAD"}
{"step":"commit","attempt":1,"conversation_id":1,"message_id":1,"status":200,"ms":4900,"outcome":"dry_run","draftChars":87,"nextState":"GOAL_DISCOVERY"}
{"step":"respond","conversation_id":1,"message_id":1,"status":200,"outcome":"dry_run"}
{"step":"webhook","signatureOk":true,"event":"marketing.message.received","idempotencyKey":"wamid.E2EF9098C3387FBD68BEEA68FD6","conversation_id":1,"message_id":3,"textChars":44,"extraKeys":[]}
{"step":"decide","conversation_id":1,"message_id":3,"status":200,"ms":1506,"superseded":false,"phase":"GOAL_DISCOVERY"}
{"step":"commit","attempt":1,"conversation_id":1,"message_id":3,"status":200,"ms":2857,"outcome":"dry_run","draftChars":90,"nextState":"GOAL_DISCOVERY"}
{"step":"respond","conversation_id":1,"message_id":3,"status":200,"outcome":"dry_run"}
```

`marketing_ai_actions` en la base del E2E (`ultron_studio_e2e`) tras la ejecución:

| id | source_type | status | idempotency_key | origin | intent | previous → next | outbound | policy_violations |
|---|---|---|---|---|---|---|---|---|
| 1 | — | skipped | — | — | — | — | — | — (contabilidad del router: `auto_analyze_disabled`) |
| 2 | inbound_message | executed | `wamid.E2E871812F3A83D55BD06ED2524` | ultron | greeting | NEW_LEAD → GOAL_DISCOVERY | `{"sent":false,"reason":"meta_disabled_or_unconfigured","dry_run":true,"message_id":2}` | `["policy_greeting_sells"]` |
| 3 | — | skipped | — | — | — | — | — | — (router) |
| 4 | inbound_message | executed | `wamid.E2EF9098C3387FBD68BEEA68FD6` | ultron | general_info | GOAL_DISCOVERY → GOAL_DISCOVERY | `{"sent":false,"reason":"meta_disabled_or_unconfigured","dry_run":true,"message_id":4}` | — |

- Salientes 2 y 4: `sender_type = ai`, `status = dry_run`. En el saludo, Laravel sustituyó el borrador del simulador por su recepción (`policy_greeting_sells`: un saludo puro no admite descubrimiento); en la consulta salió el borrador del simulador tal cual (90 caracteres = `draftChars`).
- `marketing_automation_events` 1 y 2: `sent` al primer intento; conversación 1 en `GOAL_DISCOVERY`.
- **Sin red:** la valla solo registró su sonda (`{"kind":"connect","host":"e2e-fence-probe.invalid:443"}`); en `channel.log`, la presencia de los dos turnos se cortó con `meta.send.skipped` (`disabled_or_unconfigured`) y no hay ni un `meta.send.ok`/`exception`; el simulador de OpenAI recibió 0 peticiones en toda la ejecución.
- Negativos: el entrante con la firma de Meta mala dio 403 y no guardó nada; el webhook con HMAC malo dio 401 en el simulador sin llegar a `decide`.

### 3.2 Qué comprueba cada spec (resumen; detalle en `README.md`)

- **F (39):** login por formulario de cuatro cuentas; borrador → versión (formato 2, 12 reglas del código) → enviar; la autora, CON `ultron.approve`, no ve «Aprobar» y recibe 403 `four_eyes_required` auditado como `version.four_eyes_denied`; la revisora aprueba con el botón deshabilitado hasta reconocer los 2 avisos (`opcional-E14`, `opcional-E16`); publicar: botón deshabilitado con motivo, 409 `publishing_disabled` para la publicadora, 403 al Administrador y a la lectora, la versión sigue APPROVED; lectora en solo lectura y 403 al guardar; libro con los cuatro eventos; sin errores de JS ni 5xx.
- **G (25):** `engine/readings` con el entorno real (encendido, canario, cobro apagado; sin el id del canario; 401 sin sesión); «Motor ahora» de Gobierno «solo la conversación canaria» con todas sus lecturas `ok`; `paymentLinksEnabled` con lectura real, prevención `config-cobro-sin-camino`, «≠ motor» y «Usar el valor del motor», guardado en el servidor; comparar: versión = línea base (`same: true` y «Sin diferencias de comportamiento») y versión ↔ borrador con la diferencia en la API y en el CRM.
- **H4 (27):** página en `ready`, modo `fake`, pestaña de ejecuciones y versión preseleccionada; 11 escenarios; estimar «permitida» sin crear ejecución; ejecutar con el worker real hasta `completed` (11 casos `done`, coste 0, 0 peticiones al simulador, cola `studio-lab` vacía); veredicto «no-disponible» explicado; 422 sin `resultsHash`; 409 `run_not_reviewable`/`fake_mode` a la revisora y también a la autora (en `fake`, «no revisable» va antes que los cuatro ojos); ningún veredicto.
- **Turno (30):** §3.1.
- **Cierre (9):** huella de 17 tablas del motor y de las colas idéntica tras F/G/H4; 378 peticiones del CRM sin 5xx; `laravel.log` sin ERROR; sin `failed_jobs` ni trabajos en cola; 0 violaciones en los dos simuladores; la valla vacía; ninguno de los 4 secretos de la ejecución en 19 registros ni en 7 tablas.

### 3.3 Pasadas previas (defectos del arnés, no del producto)

| Pasada | Resultado | Causa | Corrección |
|---|---|---|---|
| 1 | No arrancó | `git archive <sha>:backend` ejecutado dentro de `backend/` filtra por el prefijo y sale vacío sin error | Se archiva desde la raíz del repo y se exige `artisan`/`composer.lock` |
| 2 | 54/67 | Login de la lectora > 30 s bajo carga; `state.json` escrito al final de F (G y H4 no arrancaron); en el turno, el negativo usaba `message_id` 1 (colisión) y se comprobaba antes de que n8n respondiera | Esperas escalables, estado escrito al aprobar, correlación por `wamid` y espera al final del turno |
| 3 | 52/55 (turno 30/30, cierre 9/9) | `goto` con `networkidle0` agotó 90 s con el shell del CRM bajo carga | `domcontentloaded` + espera del selector |
| 4 | **130/130** | — | — |

En ninguna pasada falló una comprobación por un comportamiento del producto: el turno, el commit y el Studio respondieron siempre lo esperado (verificado en la base y en los registros de cada pasada).

## 4. Pendiente y límites conocidos de esta entrega

- **Mutaciones sin ejecutar.** `mutations.sh` (M1–M5) está escrito y documentado, pero la pasada no se llegó a correr: la pila en pie para ella se paró en el paso 3 al agotarse el tiempo. Hasta correrla, que cada spec caiga con su mutación es una hipótesis, no un resultado.
- **Cambio posterior a la pasada en verde, sin pasada completa:** la instantánea del CRM en ruta estable por commit (`run.sh`, paso 2, para reutilizar la caché de Angular). Solo se ejecutó hasta el paso 3 (extracción correcta); los 130/130 son de la versión anterior de ese paso.
- **La huella mide 16 tablas reales, no 17:** `marketing_agent_pauses` no existe en `0366009` (su valor es `null` en las dos tomas); la pausa del agente vive en otra tabla (`marketing_agent_settings`, por confirmar). Corregir la lista en `specs/lib.cjs` (`ENGINE_TABLES`).
- **Orden observado, no juzgado:** en `fake`, el veredicto de la autora sale 409 `run_not_reviewable` antes que 403 `four_eyes_required`. Las dos puertas niegan; si el contrato pide otro orden, es del backend.

## 5. Qué no cubre (y por qué)

- **La pila de producción**: nginx + PHP-FPM, `config:cache`/`route:cache` y el build de producción del CRM. Esto es `artisan serve` y `ng serve` en desarrollo; los fallos que solo salen con la caché de configuración (memoria «config:cache y los workers») no los ve.
- **Los 429 y la exclusión mutua entre procesos**: `CACHE_STORE=array` anula los limitadores y los cerrojos de `Cache::lock` son por proceso. Una carrera de dos commits del mismo turno no se cubre aquí (la cubren las pruebas de PG con barrera).
- **El laboratorio en `openai`**: `mock-openai.cjs` está y valida el contrato del cliente, pero el subconjunto de regresión corre en `fake` (como pidió el lead) y el simulador termina la ejecución con 0 peticiones. Cambiar el driver exige reiniciar servidor y worker con otro `.env` (502/CORS en las pestañas abiertas): queda como spec aparte.
- **El vigía, el acta del canario y el freno**: no hay scheduler en el arnés; el turno mudo y el freno no se ejercitan. Tampoco el parte de avería de n8n (`ai/ultron/incidents`): el simulador no lo llama.
- **El reintento del job hacia n8n** (backoff 20/60/180 s) y el `timeout` de 45 s: el simulador responde en milisegundos.
- **Las 280 comprobaciones de F/G/H4 una a una** (texto hostil, teclado, tres tamaños, cupos, `incomplete`, cancelar en curso…): el encargo es un subconjunto por flujos críticos; reconstruirlas es trabajo aparte (§10 del contrato).
- **Safari, Firefox y lectores de pantalla.**
