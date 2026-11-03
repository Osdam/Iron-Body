# Fixtures de voz y telefonía del Hito 8 (TEST8-a, paso 1)

Escritos **desde el contrato y antes del código**: `docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md`, congelado el 03-11-2026 (commit `57bab8a`), y su auditoría `ULTRON_STUDIO_H8_VOICE_AUDIT.md`. Son el oráculo de las pruebas puras de B8, S8, DB8 y OPS8.

**Regla de uso.** Si una implementación discrepa de un caso con `basis` `contrato`, `oficial` o `ley`, se corrige la implementación. Si el caso es `decision-fixture`, lo congela el lead (§Decisiones). Si el lead decide otra cosa, se cambia aquí con el generador. **Nunca se dobla un caso para que dé verde.**

**No los ejecuta la suite.** PHPUnit solo recorre `tests/Unit` y `tests/Feature`. Aquí hay `.json`, `.md` y `.py`.

## Cómo se generan y se comprueban

```
python3 -I tests/fixtures/voice/tools/build/build.py            # reescribe los 9 JSON (reproducible byte a byte)
python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py   # forma + segunda derivación + cruces + variantes
python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py --php                       # + StudioContentHasher, ElevenLabsWebhookSignature/Envelope, hash_hmac, easter_days
python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py --sdk-python /ruta/python   # + el SDK oficial de ElevenLabs (cuando haya uno instalado)
```

- **El generador** está en `tools/build/`:
  - `build.py` contiene las primitivas, las claves sintéticas y la serialización;
  - un `sec_*.py` por fichero contiene los casos **a mano**.

  Cada `expected` es un literal escrito desde el contrato. El generador solo calcula lo que no se puede escribir a mano: HMAC, sha256 y fechas. Además contrasta los literales con una referencia propia (`assert`).
- **El comprobador** (`tools/voice-fixtures-check.py`) **no importa el generador**. Vuelve a derivar cada caso con otra implementación y otra maquinaria:
  - toda la criptografía con el binario `openssl`;
  - la Pascua por Gauss (el generador usa el algoritmo gregoriano anónimo);
  - Bogotá con `zoneinfo` (el generador usa un desfase fijo).

  Además prueba 66 implementaciones equivocadas (§Verificación).
- **`--php`** carga solo tres clases puras del árbol, sin Laravel, y comprueba que siguen sin cambios:
  - `app/Services/UltronStudio/StudioContentHasher.php`;
  - `app/Services/Voice/ElevenLabs/ElevenLabsWebhookSignature.php`;
  - `app/Services/Voice/ElevenLabs/ElevenLabsWebhookEnvelope.php`.

## Forma común de los 9 ficheros

**Cabecera:**
- `fixture`, `version`, `contract`, `audit` y `contractSections`;
- `generatedBy`, `checkedBy` y `readme`;
- `basisLegend`;
- `sources`: cada fuente con `what`, `url`, `consulted` y `how`;
- `syntheticData`;
- `decisions`: solo las que usan sus casos;
- `counts`;
- y lo propio de cada fichero: `keys`, `closedSets`, `algorithm`, `world`, `years`…

Después, una lista de casos por sección.

**Cada caso** lleva:
- `name`, único y con el formato `<sección> · <kind> · <descripción>`;
- `kind`;
- `contractRef`: de qué sección del contrato sale;
- `basis`, uno de estos:
  - `contrato`: lo dice el contrato;
  - `oficial`: documentación o SDK del proveedor;
  - `ley`;
  - `supuesto-lead`: P-n de la congelación;
  - `decision-fixture`: el contrato no lo fija;
- `decision` (DF-…) cuando el caso depende de una decisión;
- sus entradas;
- `expected`.

**Convenciones:**
- Una cabecera HTTP con valor `null` significa **ausente**.
- `mustNotPersist` y `responseMustNotContain` son centinelas que **están** en el cuerpo de entrada (el comprobador lo verifica). No pueden aparecer en ninguna fila, evento, log ni respuesta.
- Ningún dato es real:
  - SIDs, ids, secretos y dominios `.test` inventados;
  - teléfonos de ficción (`+57 300 000 00xx` y `+57 601 000 00xx`);
  - la `APP_KEY` del fixture del H6 (bytes 00..1f).

  Los textos no llevan invisibles literales: van como `\uXXXX`.

## Índice

| Fichero | Para | Secciones (casos) | Contrato |
|---|---|---|---|
| `twilio-signature-vectors.json` | B8 `Telephony\TwilioRequestSignature` | `compute` 15 (los 6 oficiales + 9 derivados), `validate` 25, `urlBuild` 5 | D8-4, D8-16, OTP-2/3 |
| `elevenlabs-webhook-vectors.json` | B8 `ElevenLabsEventVerifier` (envuelve la firma del H6 sin cambios) | `verify` 27 | D8-16, D8-3, D8-5 |
| `elevenlabs-webhook-bodies.json` | B8 webhooks, herramientas y guarda; OPS8 simulador | `postCall` 14, `guard` 9, `initiation` 11, `tools` 18, `routeRules` 11 | D8-8, D8-10, D8-11, D8-13, D8-16, D8-17, D8-19 |
| `twilio-webhook-bodies.json` | B8 `usage-trigger` y conciliación; OPS8 simulador | `usageTrigger` 7, `callStatus` 8, `callsPage` 1 (9 llamadas) | D8-16, D8-19, D8-21 |
| `holidays-co-2026-2028.json` | B8 `Telephony\ColombianHolidays` | `years` (3 × 18 festivos) y `isHoliday` 77 | D8-15 (Ley 51 de 1983) |
| `policy-tables.json` | B8 `OutboundPolicy`/`InboundPolicy` y sus tablas, `VoiceCostGuard`, el freno | `scheduleLegal` 20, `scheduleOwn` 11, `frequency` 17, `consent` 13, `exclusions` 16, `inboundPolicy` 15, `costReservation` 17, `brake` 16, `phoneHash` 7 | D8-11, D8-15, D8-17, D8-18, D8-19 |
| `state-transitions.json` | DB8 (disparador), B8 | `allowed` 21, `matrix` 242 (11 × 11 por dirección), `insert` 22, `sequences` 14, `columns` 25, `eventDedupe` 5 | D8-13, D8-8 |
| `compiler-cases.json` | S8 `StudioVoiceCompiler`; B8 `ElevenLabsAgentMapper` (hash) | `compile` 73 (13 válidas + 60 negativas), `capClamp` 11, `hashRelations` 14, `canonicalHash` 9 | D8-6, D8-7, D8-11, D8-14, D8-15, D8-17 |
| `outbound-gate-cases.json` | B8 `OutboundPolicy` y `PlaceOutboundVoiceCall` | `decide` 43, `whatsappGuard` 7, `job` 22 | D8-12, D8-9, D8-5, D8-13, D8-19, D8-21 |

**Total: 868 casos.** Por base:
- `contrato`: 350;
- `decision-fixture`: 375 (152 son la matriz de estados);
- `ley`: 95;
- `oficial`: 32;
- `supuesto-lead`: 16.

### Cómo se consume cada uno (resumen)

- **Firmas de Twilio.**
  - `compute`: `signature(token, url, params | rawBody)` tiene que dar `expected.signature`. Con `rawBody`, la base es la URL y `bodySHA256` es el sha256 hex.
  - `validate`: `valid(token, url, header, params | rawBody)` tiene que dar `expected.valid`. Con un token vacío, `throws`.
  - `validWithoutPortTolerance` dice lo que daría un validador sin la tolerancia de puerto del SDK (DF-T2).
  - `urlBuild`: `url(base, requestUri)`.
- **Firmas de ElevenLabs.** `ElevenLabsWebhookSignature::verify(rawBody, header, secret, now)` tiene que dar `expected.signatureResult`, y el controlador, `expected.http`.
  - `sdkConstructEvent` es lo que haría el **ejemplo documentado** del SDK: **por confirmar con el SDK** (`sdkVerified: false`).
  - Las divergencias con el H6 están marcadas DF-E16 y manda el H6 (D8-16 «sin cambios»).
- **Cuerpos de ElevenLabs.**
  - `postCall`: con `given.call` como fila previa, al recibir `rawBody` bien firmado se esperan `http`, `events` (terna de deduplicación), `transition` (o `statusAfter`), `seal`, `costRaw`, `guard`, `brakeOpens` y `mustNotPersist`.
  - `guard`: `transcript` → `violations` `[{turn, code}]`.
  - `initiation`: la respuesta exacta (`response`; `<uuid>` y `<texto>` son formas), las claves prohibidas y la fila.
  - `tools`: `verdict`, `http`, `sideEffects` y `mustNotCreateExclusionFor`.
  - `routeRules`: la tabla de D8-16 por escenario.
- **Cuerpos de Twilio.**
  - `usageTrigger`: lleva `url`, `requestUri`, `params`, `rawBody` (formulario) y `headers` (firmados con el token sintético de la subcuenta) → `http`, `persisted`, `eventKind`, `dedupeKey` y `brakeOpens`.
  - `callsPage`: la página de `listCalls` y `given` → `perCall` (coincidencia, estado antes y después, coste en micro-US$, eventos).
- **Festivos.** `years` es la lista completa para `forYear()`. `isHoliday`: `date` → `holiday` y `name`; `instant` (UTC) → la fecha de Bogotá y `holiday`.
- **Políticas.** Cada sección es una tabla de entrada y `expected`. `inboundPolicy` da `codeOpen` (la constante girada en la copia del banco) y `codeClosed` (el árbol compartido, `CALLS_ENABLED_IN_CODE = false`).
- **Estados.**
  - `matrix`: `(from, to, direction)` → `allowed`; la diagonal es `noChange`.
  - `insert`: el estado inicial por dirección.
  - `sequences`: pasos aceptados o rechazados.
  - `columns`: inmutables y de una sola escritura.
  - `eventDedupe`: la UNIQUE de eventos.
- **Compilador.**
  - `compile`: `input` (forma neutra de `inputShape`) → `compiles` con `invariants`, o `reason` (o `reasonOneOf`) con `field` y `http` 422.
  - `capClamp`: el tope del entorno.
  - `hashRelations`: `sameSpecHash` de dos entradas.
  - `canonicalHash`: el JSON canónico y su sha256, exactos.
- **Puerta de salientes.**
  - `decide`: `facts` → `codeOpen` y `codeClosed`, con `allowed`, `reasons` (todas, en orden) y `http`.
  - `whatsappGuard`: los tres valores crudos → booleano.
  - `job`: `row`, `given` y `elevenlabsResponse` → `request` (método, ruta, veces, aserciones del cuerpo y rutas prohibidas), `statusAfter`, `reason`, `events`, `reservation` y `retries`.

## Decisiones del fixture (las congela el lead)

El texto exacto de cada una está en la cabecera `decisions` del fichero que la usa (69 con caso). E7, G4 y G6 solo están en el generador, sin caso propio todavía. Resumen por grupo:

- **T: firma de Twilio.**
  - **T1.** URL = `rtrim(VOICE_WEBHOOK_BASE_URL, '/')` + la URI cruda (`getRequestUri()`). El esquema y el host salen solo de la base.
  - **T2.** Tolerancia de puerto como `validate()` del SDK oficial.
  - **T3.** Orden de bytes (`SORT_STRING`) en claves y valores: «10» va antes que «9».
  - **T4.** `bodySHA256` se compara exacto.
  - **T5.** Un token vacío lanza.
- **E: webhooks y herramientas de ElevenLabs.**
  - **E1.** Toda firma que no es `ok` da 401.
  - **E2.** La deduplicación usa `(elevenlabs, type, conversation_id)`.
  - **E3.** Un JSON roto con la firma buena da 200 y se ignora.
  - **E4.** Un evento sobre un estado final no cambia el estado.
  - **E5.** Una post-llamada sin su fila es `unknown_provider_call` y abre el freno.
  - **E6.** En la iniciación, un cuerpo mal formado da 200 con `no`.
  - **E7.** La respuesta lleva siempre las tres variables.
  - **E8.** Esquema estricto en las herramientas.
  - **E9.** «En curso» = `dialing` o `active`.
  - **E10.** «El autorizado es el sellado» = `caller_authorized = si`.
  - **E11.** Los turnos se numeran desde 0. Códigos de voz propuestos: `voice_notice_missing`, `voice_money_not_from_tool` y `voice_transfer_not_approved`.
  - **E12.** Iniciación con otro `agent_id`: `seal_mismatch` y freno.
  - **E13.** `called_number` ajeno da `no`.
  - **E14.** `canceled` de Twilio se concilia como `failed`.
  - **E15.** Una autenticación fallida da 401; una herramienta desconocida, 404; un rechazo de la llamada, 200 con mensaje neutro.
  - **E16.** Divergencias del ejemplo del SDK: manda el H6.
- **W: avisos y conciliación de Twilio.**
  - **W1.** `usage_alert` (fuera del conjunto cerrado, Q-5).
  - **W2.** Una firma mala da 401.
  - **W3.** Sin `IdempotencyToken`, se persiste igual.
  - **W4.** Un tramo de transferencia se concilia con su llamada madre.
  - **W5.** Una entrante denegada queda reconocida por su `call_sid`.
  - **W6.** Precio en micro-US$; `null` = sin tarificar.
- **P: tablas de política.**
  - **P1.** Intervalos semiabiertos en Bogotá; 19:00 y 15:00 ya están fuera.
  - **P2.** Horario propio = intersección con el legal; mal configurado = fuera.
  - **P3.** Qué estados cuentan como intento.
  - **P4.** Día y semana de Bogotá, y además los 7 días móviles.
  - **P5.** Consentimiento: manda el último evento; cuatro ojos; P-7.
  - **P6.** Las exclusiones también valen para los canarios.
  - **P7.** RNE: el borde de la vigencia vale.
  - **P8.** `InboundPolicy` acumula los motivos.
  - **P9.** Caller ID oculto = no es canario.
  - **P10.** Topes con `≥`.
  - **P11.** Un alcance distinto de `canary` = `inbound_disabled`.
  - **P12.** Fórmula de la reserva.
  - **P13.** Presupuesto en micro-US$.
  - **P14.** Soltar el freno.
  - **P15.** La huella de voz no normaliza.
- **S: estados.**
  - **S1.** Sin cambio de estado no hay transición.
  - **S2.** Un webhook antes que el job.
  - **S3.** `ambiguous` se resuelve hacia delante.
  - **S4.** `active` → `failed`.
  - **S5.** El sello y la identidad son inmutables.
  - **S6.** Las columnas posteriores se escriben una sola vez.
  - **S7.** Eventos sin clave del proveedor.
  - **S8.** El estado inicial por dirección.
  - **S9.** Lista cerrada de transiciones.
- **C: compilador.**
  - **C1.** Nombres y orden de los motivos.
  - **C2.** Versiones y perfiles aprobados.
  - **C3.** Comprobaciones de los textos.
  - **C4.** Aviso exacto.
  - **C5.** Forma cerrada del perfil.
  - **C6.** Tope del entorno.
  - **C7.** Qué entra en `specHash`.
  - **C8.** Invariantes de la salida.
  - **C9.** `handoffAliases`.
  - **C10.** Rarezas del JSON canónico de PHP.
  - **C11.** Detectores solapados (`reasonOneOf`).
- **G: puerta de salientes.**
  - **G1.** Acumula, y se dan las dos variantes de la constante.
  - **G2.** Orígenes y alcance.
  - **G3.** Los cupos.
  - **G4.** HTTP 202 o 409.
  - **G5.** `duplicate_request`.
  - **G6.** Un rechazo no crea fila.
  - **G7.** La reserva.
  - **G8.** Respuestas raras de `outbound-call`.
  - **G9.** Qué vuelve a mirar el job.
  - **G10.** La guarda de WhatsApp por dígitos.

## Preguntas abiertas para el lead

Las cito en los casos con su número.

- **Q-1 (P-9 frente a D8-17, legal).** El texto provisional del aviso no dice **la finalidad** ni **cómo pedir no recibir más llamadas**, que D8-17 exige. Es la revocación de la Ley 2300. El fixture usa el texto de P-9 tal cual.
- **Q-2 (D8-12).** ¿`duplicate_request` corta la evaluación (idempotencia: devuelve la fila sin mirar nada más) o se acumula el último? El fixture acumula (DF-G5), que es la lectura literal.
- **Q-3 (D8-10 frente a D8-13).** Las herramientas exigen la llamada `active`, pero una saliente contestada sigue en `dialing`: `active` es solo de entrantes. Leído al pie de la letra, **ninguna herramienta respondería en una saliente**. El fixture admite `dialing` o `active` (DF-E9).
- **Q-4 (D8-12 frente a D8-18).** `excluded` está en el grupo del alcance `contacts`, pero D8-18 dice que las exclusiones se consultan «antes de cada saliente». El fixture las aplica también a los canarios (DF-P6).
- **Q-5 (D8-13).** Un aviso de gasto se persiste como evento (D8-16), pero no hay ningún `kind` para él en el conjunto cerrado. El fixture propone `usage_alert`.
- **Q-6 (D8-19, D8-21).** La conciliación ve como «llamada sin fila» los tramos de transferencia y, si no se registran, las entrantes denegadas. Con el freno automático, **cualquier desconocido que llame al número abriría el freno** y pararía la voz. El fixture reconoce los dos casos (DF-W4, DF-W5). Hay que fijar si una entrante denegada crea fila.
- **Q-7 (P-2).** Una post-llamada con `version_id` no nulo indica que alguien activó el versionado en el panel, y eso es irreversible. ¿Es deriva o `seal_mismatch`? Hoy el sello solo compara el agente.
- **Q-8 (D8-15).** ¿El horario se evalúa al pedir o al marcar? El job no vuelve a mirar el calendario (DF-G9) y una saliente pedida a las 18:59:59 puede marcarse a las 19:00:05.
- **Q-9 (D8-16).** Si ElevenLabs reintenta con el `t` original, un reintento a los 30 min da 401 y cuenta para la desactivación automática tras 10 fallos. Está por confirmar con la cuenta real.
- **Q-10 (D8-14, D8-15).** El tramo transferido no lo acota `max_duration_seconds`: la reserva no puede acotar su coste.
- **Q-11 (protección 1 frente a D8-15).** `VOICE_PER_CALL_MAX_SECONDS` tiene 300 por defecto y `VOICE_MAX_CONCURRENT_CALLS`, 1, pero la protección 1 pide `VOICE_*` en `false` o `0`. El fixture toma 300 (DF-C6).
- **Q-12 (D8-6 frente a P-9).** El aviso aparece como campo del perfil (D8-6) y también como «solo texto, en la plantilla» (congelación). El fixture exige el texto exacto en el perfil (DF-C4).
- **Q-13 (D8-17).** La etiqueta de la huella es `voice-phone`, literal, o `voice-phone/1`, versionada como las del H6. El fixture usa la literal.
- **Q-14 (D8-8).** Falta el formato exacto del mensaje que se firma en la cadena de voz: ¿`"ultron-voice-config/1\n" + effective_json`, como el H5? **No hay vectores de la cadena hasta fijarlo.**
- **Q-15 (D8-22 y fase 4a).** La frecuencia de 1 al día también vale para los canarios: las 7 pruebas de la fase 4a necesitan varios canarios o varios días.
- **Q-16.** Otros puntos sin fijar:
  - el tope de cuerpo de `usage-trigger` (D8-16 no lo da);
  - la longitud de los «huecos cortos»;
  - si los textos del perfil se normalizan a NFC (una tilde NFD cambia la huella; O-3 del H6);
  - si las salientes también envían `version_label`.

## Observaciones para otros paquetes (no son del fixture)

- **O-1 (SEC8, S8).** `specHash` y la huella del perfil son sha256 sin clave sobre contenido que incluye el **E.164 en claro** de los destinos de transferencia. Quien tenga la base y el código puede probar números por fuerza bruta. Son números del personal (riesgo bajo), pero conviene valorarlo.
- **O-2 (B8).** `OutboundContentGuard::inspect()` marca **cualquier** cifra (`machine_reply_invented_price`). Con P-3, el adaptador de voz tiene que descontar las cifras que devolvió `consultar_planes` en esa llamada. Las cifras dichas con letras («ochenta y nueve mil») quedan sin cubrir.
- **O-3 (B8).** Con el `sort()` por defecto de PHP, los valores numéricos repetidos se ordenan como números. Hace falta `SORT_STRING`: el vector «10/9» lo caza.
- **O-4 (OPS8).** Los vectores y cuerpos firmados sirven tal cual al simulador de ElevenLabs y Twilio, con los secretos sintéticos de `keys`.

## Lo que no cubre, y por qué

- **El valor de `specHash` y `payloadHash` de una compilación real.** Depende de la plantilla de S8, que vive en el código (P-6). El fixture fija el algoritmo de la huella (vectores exactos, cruzados con PHP), las negativas y las relaciones entre huellas.
- **La carga y la proyección de ElevenLabs** (rutas y campos de crear, leer y asignar agentes). El contrato las deja **[por confirmar]** (D8-3), y la deriva necesita el mapeador de B8.
- **La cadena firmada de voz** (D8-8): Q-14.
- **Lo que no es núcleo puro:**
  - los disparadores y los `REVOKE` en PostgreSQL (DB8, en su puerta PG);
  - la matriz RBAC y `AuthorizationMap` (D8-22);
  - el laboratorio hablado (D8-23) y la evidencia del canario y la promoción (D8-19, P-16);
  - el conector del RNE (P-14);
  - los DTO del CRM;
  - el E2E con simuladores (paso 3 de TEST8).
- **Verificación con el SDK de ElevenLabs.** No hay red ni copia local: `sdkConstructEvent` queda **por confirmar con el SDK**. El comando está en la cabecera de `elevenlabs-webhook-vectors.json`.
- **Fuentes que no se abrieron hoy** (sin red):
  - los vectores oficiales de Twilio salen de la descarga del H6 del 31-10-2026 (sha256 del fichero en la cabecera);
  - el texto de la Ley 51 de 1983 lo tiene que aprobar el propietario antes de dar el fixture de festivos por bueno (`approvedByOwner: false`);
  - los campos de los cuerpos que la auditoría no enumera van marcados **[por confirmar]**.

## Verificación (salida real del 03-11-2026)

```
derivación independiente: 767 casos rehechos · discrepancias: 0
cruces entre ficheros: 90 comprobaciones
variantes equivocadas: 66 · cazadas 66
php: canonicalHash+perfiles 82, elevenlabsVerify 27, envelope 14, huellasTelefono 6, firmasTwilio 15, pascua 3 · fallos 0 (PHP 8.3.33)
RESULTADO: OK
```

Los 101 casos que no se vuelven a derivar se validan con los cruces entre ficheros:
- los cuerpos de ElevenLabs (63);
- los de Twilio (16);
- el job (22).

Los cruces comprueban que las transiciones están permitidas, que los eventos son del conjunto cerrado, que los centinelas están presentes, que la clave de deduplicación es `conversation_id`, que las firmas de los avisos casan con el HTTP esperado, y que el formulario y el coste están bien convertidos.

El detalle está en `docs/ultron-studio/plans/informes-h8/informe-tester.md`.
