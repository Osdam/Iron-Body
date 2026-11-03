# Hito 8 · TEST8-a (paso 1) · informe del tester: los fixtures desde el contrato

**Fecha:** 03-11-2026.
**Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, sobre `57bab8a`.
**Contrato:** `ULTRON_STUDIO_H8_CONTRACT.md`, congelado el 03-11-2026, y su auditoría `ULTRON_STUDIO_H8_VOICE_AUDIT.md` (§d.1 y §d.2).
**Alcance tocado:** solo `tests/fixtures/voice/**` y este informe.
**Sin commit** y sin tocar el índice. **Producción:** nada tocado. **Red:** ninguna. **Suites del repo:** no lancé ninguna (máquina compartida).

## 0. Resumen

- **Entregado:** 9 fixtures, su README, el generador (`tools/build/`) y el comprobador independiente (`tools/voice-fixtures-check.py`).
- **Volumen:** 868 casos y 988 381 bytes.
- **Base de los casos:**

  | Base | Casos |
  |---|---|
  | `contrato` | 350 |
  | `ley` | 95 |
  | `oficial` | 32 |
  | `supuesto-lead` | 16 |
  | `decision-fixture` | 375 (152 son la matriz de estados de 11 × 11 por dirección) |

  Lo que el contrato no fija va como `decision-fixture`, en 69 decisiones DF-… que usan los casos (y 3 más, DF-E7, DF-G4 y DF-G6, definidas en el generador sin caso propio) que **tiene que congelar el lead**.
- **Verificación, salida real (§2):**
  - una segunda derivación independiente, con openssl, el algoritmo de Gauss y zoneinfo, rehace 767 casos con **0 discrepancias**;
  - 90 cruces entre ficheros;
  - **66 de 66** implementaciones equivocadas caen;
  - con `--php`, **0 fallos** frente a las clases reales que el H8 envuelve sin cambios: `StudioContentHasher`, `ElevenLabsWebhookSignature::verify` (27 de 27) y `ElevenLabsWebhookEnvelope::type` (14 de 14), más `hash_hmac` y `easter_days`;
  - con cinco `expected` estropeados a propósito, el comprobador **falla en los cinco**;
  - el generador es reproducible byte a byte.
- **Sin el SDK de ElevenLabs.** No hay red ni copia local (busqué en pip, npm, uv, pnpm y cachés). Los vectores salen del algoritmo documentado y llevan **`sdkVerified: false` («por confirmar con el SDK»)**. El modo `--sdk-python` queda listo y probado con una imitación (§2.5). Los 6 vectores oficiales de Twilio salen de la descarga del H6 del 31-10-2026, con el sha256 del fichero.
- **Lo que más pesa para el lead (§3):**
  - **Q-3:** leídas al pie de la letra, las herramientas no responderían en ninguna saliente;
  - **Q-6:** cualquier desconocido que llame podría abrir el freno automático;
  - **Q-1:** el aviso provisional de P-9 no dice cómo pedir no recibir más llamadas, que D8-17 y la Ley 2300 exigen;
  - **Q-14:** falta el formato de firma de la cadena de voz.

## §fixtures. Qué se entrega, recuento y fuente de cada formato

### Recuento por fichero (salida de `build.py`)

| Fichero | Bytes | Casos | Secciones |
|---|---|---|---|
| `twilio-signature-vectors.json` | 37 533 | **45** | `compute` 15 (los **6 oficiales** + 9 derivados: sin consulta, consulta en su orden, codificada, valor vacío, UTF-8, «10/9», mayúsculas, `+`, JSON con ñ), `validate` 25, `urlBuild` 5 |
| `elevenlabs-webhook-vectors.json` | 100 844 | **27** | `verify` 27: 6 válidos (uno por cuerpo), 5 de tiempo (bordes de 1800/1801 s y de +300/+301 s, uno de hace 1 día), 6 de cuerpo alterado, 3 de secreto equivocado, rotación y 6 de forma |
| `elevenlabs-webhook-bodies.json` | 111 795 | **63** | `postCall` 14, `guard` 9, `initiation` 11, `tools` 18, `routeRules` 11 |
| `twilio-webhook-bodies.json` | 48 400 | **16** | `usageTrigger` 7, `callStatus` 8, `callsPage` 1 (9 llamadas: conocidas, ambiguas resueltas, desconocida, tramo de transferencia, entrante denegada, sin tarificar) |
| `holidays-co-2026-2028.json` | 40 593 | **77** | `years` (18 festivos × 3 años, con regla, fecha de origen y día) e `isHoliday` 77 (trasladados y su fecha de origen, fijos en sábado, Semana Santa, bordes de medianoche de Bogotá en UTC) |
| `policy-tables.json` | 82 592 | **132** | `scheduleLegal` 20, `scheduleOwn` 11, `frequency` 17, `consent` 13, `exclusions` 16, `inboundPolicy` 15, `costReservation` 17, `brake` 16, `phoneHash` 7 |
| `state-transitions.json` | 125 341 | **329** | `allowed` 21, `matrix` 242, `insert` 22, `sequences` 14, `columns` 25, `eventDedupe` 5 |
| `compiler-cases.json` | 308 476 | **107** | `compile` 73 (13 válidas + 60 negativas; los 23 motivos tienen caso), `capClamp` 11, `hashRelations` 14, `canonicalHash` 9 |
| `outbound-gate-cases.json` | 126 608 | **72** | `decide` 43 (los 26 motivos de D8-12 aislados, bordes, orden y acumulación; cada uno con `codeOpen` y `codeClosed`), `whatsappGuard` 7, `job` 22 |

Cada caso dice de qué sección del contrato sale (`contractRef`) y con qué base (`basis`). Si depende de una decisión, lleva su `decision`. Cada fichero lleva en la cabecera sus fuentes y las decisiones que usa. El README del directorio explica la forma, cómo se consume cada fichero, las decisiones, las preguntas y lo que no cubre.

### Fuente oficial de cada formato

«Leída» la abrió el architect el 03-11-2026 (auditoría §d). Yo **no abrí ninguna URL hoy**, porque no hay red.

| Formato | Fuente | Fecha | Cómo entra |
|---|---|---|---|
| `X-Twilio-Signature` (HMAC-SHA1, URL exacta, `bodySHA256`, sin IP) | https://www.twilio.com/docs/usage/webhooks/webhooks-security | 03-11-2026 (auditoría §d.2, leída) | Algoritmo |
| Los 6 vectores oficiales de Twilio y la semántica de `validate()` (puerto) | https://github.com/twilio/twilio-python/blob/main/tests/unit/test_request_validator.py | 31-10-2026 (descarga del H6, sha256 del fichero `4522667e1aef9fcc1e598dad33f84315913feac4a7cc2cfe02b3f34ef5bc4509`, `informe-fixture.md` §2.5) | Literales; HMAC recalculado por openssl y PHP |
| `ElevenLabs-Signature` (`t=…,v0=…`, HMAC-SHA256 de `t.cuerpo`, 30 min) | https://elevenlabs.io/docs/agents-platform/workflows/post-call-webhooks | 03-11-2026 (auditoría §d.1: la página de hoy **no** detalla el algoritmo; lo fijó el H6 desde la investigación del 17-10) | `ElevenLabsWebhookSignature` del H6 (D8-16 «sin cambios»); el SDK, **por confirmar** |
| Cuerpos post-llamada (`post_call_transcription`, `post_call_audio`, `call_initiation_failure`; `branch_id`, `version_id`, `metadata`…) | La misma página | 03-11-2026 (auditoría §d.1, leída) | Los campos de la auditoría; `tool_calls`/`tool_results`, `metadata.phone_call` y `metadata.body` del fallo de inicio, **[por confirmar]** |
| Iniciación de entrantes y su respuesta | https://elevenlabs.io/docs/eleven-agents/phone-numbers/twilio-integration/customising-calls.md | 03-11-2026 (auditoría §d.1, leída) | Campos de la auditoría |
| Server tools (cuerpo propio, Bearer, sin HMAC) | https://elevenlabs.io/docs/eleven-agents/customization/tools/server-tools | 03-11-2026 (auditoría §d.1, leída) | El cuerpo lo define Laravel (DF-E8); `system__*` sin el LLM es C-A3 |
| `POST /v1/convai/twilio/outbound-call` | https://elevenlabs.io/docs/api-reference/twilio/outbound-call | 03-11-2026 (auditoría §d.1, leída) | Aserciones del cuerpo del job; el tipo de `twilio_machine_detection`, **[por confirmar]** |
| Usage Trigger (callback) | https://www.twilio.com/docs/usage/api/usage-trigger | **No consultada** (sin red) | La lista que conozco más la que lee el controlador del OTP; `DateFired` e `IdempotencyToken`, **[por confirmar]** |
| Recurso Call y lista Calls | https://www.twilio.com/docs/voice/api/call-resource | 03-11-2026 (auditoría §d.2, leída) y 31-10-2026 (`tests/Unit/Twilio/fixtures/twilio-api-responses.json`) | La forma de `calls_week` del H6 |
| Ley 2300 de 2023 (horario, canales, una vez al día) | https://normograma.dian.gov.co/dian/compilacion/docs/ley_2300_2023.htm | 03-11-2026 (auditoría §d.3, leída) | `scheduleLegal`, `frequency` y `consent` |
| Ley 51 de 1983 (festivos y traslado al lunes) | http://www.secretariasenado.gov.co/senado/basedoc/ley_0051_1983.html | **No consultada** (sin red) | Texto conocido de la ley; `approvedByOwner: false`, **lo aprueba el propietario** (D8-15) |
| Precios de la reserva | https://elevenlabs.io/pricing/api · https://www.twilio.com/en-us/voice/pricing/co | 03-11-2026 (auditoría §d.1 y §d.2, leídas) | Micro-US$ en la cabecera de `policy-tables.json` |
| JSON canónico y su sha256 | `app/Services/UltronStudio/StudioContentHasher.php` (H5, sin cambios) | 03-11-2026, en este árbol | Vectores exactos, cruzados con PHP |

### Ficheros (lista exacta)

```
tests/fixtures/voice/README.md
tests/fixtures/voice/twilio-signature-vectors.json
tests/fixtures/voice/elevenlabs-webhook-vectors.json
tests/fixtures/voice/elevenlabs-webhook-bodies.json
tests/fixtures/voice/twilio-webhook-bodies.json
tests/fixtures/voice/holidays-co-2026-2028.json
tests/fixtures/voice/policy-tables.json
tests/fixtures/voice/state-transitions.json
tests/fixtures/voice/compiler-cases.json
tests/fixtures/voice/outbound-gate-cases.json
tests/fixtures/voice/tools/voice-fixtures-check.py
tests/fixtures/voice/tools/build/build.py
tests/fixtures/voice/tools/build/sec_twilio_sig.py
tests/fixtures/voice/tools/build/sec_eleven_bodies.py
tests/fixtures/voice/tools/build/sec_eleven_sig.py
tests/fixtures/voice/tools/build/sec_twilio_bodies.py
tests/fixtures/voice/tools/build/sec_holidays.py
tests/fixtures/voice/tools/build/sec_policy.py
tests/fixtures/voice/tools/build/sec_states.py
tests/fixtures/voice/tools/build/sec_compiler.py
tests/fixtures/voice/tools/build/sec_gate.py
docs/ultron-studio/plans/informes-h8/informe-tester.md
```

## 1. Cómo se escribió

- **Contrato primero.**
  - Cada `expected` es un literal decidido leyendo el contrato, la ley o la documentación.
  - Donde el contrato calla, el caso lo dice (`decision-fixture` y su DF) y no se esconde como si fuera contrato.
  - El generador contrasta los literales con una referencia propia: por ejemplo, la lista de festivos está **escrita a mano** y se compara con la regla.
- **No leí el código de los paquetes paralelos**, para no contaminar el oráculo. En `git status` se ven `StudioVoiceTemplate.php` de S8 y los adaptadores nuevos de ElevenLabs de B8. Solo leí código ya en `HEAD` y en producción:
  - las clases que el H8 envuelve **sin cambios**: `ElevenLabsWebhookSignature`, `ElevenLabsWebhookEnvelope` y `StudioContentHasher`;
  - las primitivas que el contrato cita: `PhoneFingerprint`, `UltronPublishedConfig::subkey`, `StudioCanaryPhone`, `InvisibleCharacters`, `PlainText` y los patrones del motor;
  - `LimitRequestBodySize`, `TwilioUsageTriggerController` y `config/meta.php` (lista de protegidos).
- **Claves sintéticas.**
  - La `APP_KEY` es la del H6 (bytes 00..1f), para poder cruzar huellas.
  - Los secretos, tokens, SIDs e ids son inventados; los teléfonos, de ficción.
  - Ningún fichero lleva invisibles literales: el comprobador lo vigila, y también en las fuentes Python.
- **La forma de los DTO es neutra.** El contrato dice de las firmas: «B8 las fija al congelar y van al fixture». Cada fichero describe sus campos y la prueba traduce a las clases de B8 y S8: el caso no cambia.

## 2. Verificación (salida real)

### 2.1 Generador y comprobador

```
$ python3 -I tests/fixtures/voice/tools/build/build.py
twilio-signature-vectors.json: {"compute": 15, "validate": 25, "urlBuild": 5, "total": 45}
elevenlabs-webhook-bodies.json: {"postCall": 14, "guard": 9, "initiation": 11, "tools": 18, "routeRules": 11, "total": 63}
elevenlabs-webhook-vectors.json: {"verify": 27, "total": 27}
twilio-webhook-bodies.json: {"usageTrigger": 7, "callStatus": 8, "callsPage": 1, "total": 16}
holidays-co-2026-2028.json: {"isHoliday": 77, "total": 77}
policy-tables.json: {"scheduleLegal": 20, "scheduleOwn": 11, "frequency": 17, "consent": 13, "exclusions": 16, "inboundPolicy": 15, "costReservation": 17, "brake": 16, "phoneHash": 7, "total": 132}
state-transitions.json: {"allowed": 21, "matrix": 242, "insert": 22, "sequences": 14, "columns": 25, "eventDedupe": 5, "total": 329}
compiler-cases.json: {"compile": 73, "capClamp": 11, "hashRelations": 14, "canonicalHash": 9, "total": 107}
outbound-gate-cases.json: {"decide": 43, "whatsappGuard": 7, "job": 22, "total": 72}
total: 868

$ python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py --php            # sale con 0
ficheros: 9 · casos: {…} · total 868
openssl: OpenSSL 3.6.4 25 Aug 2026 (Library: OpenSSL 3.6.4 25 Aug 2026)
forma: OK
derivación independiente: 767 casos rehechos · discrepancias: 0
cruces entre ficheros: 90 comprobaciones
variantes equivocadas: 66 · cazadas 66
php: {"canonicalHash+perfiles": 82, "elevenlabsVerify": 27, "envelope": 14, "huellasTelefono": 6, "firmasTwilio": 15, "pascua": 3, "fallos": 0, "php": "PHP 8.3.33 (cli) (built: Jul 28 2026 17:56:10) (NTS)"}
sdk: no ejecutado (sin --sdk-python): sdkConstructEvent sigue «por confirmar con el SDK»
RESULTADO: OK
```

**Qué comprueba la «forma»:**
- la cabecera completa y las fuentes con sus cuatro campos;
- los recuentos y los nombres únicos con su prefijo;
- la base válida, y que un `decision-fixture` lleve su DF;
- que toda decisión usada esté definida en la cabecera, y que no haya ninguna definida sin usar;
- ningún invisible literal y el salto de línea final.

**Lo que la derivación no rehace** son 101 casos: los cuerpos de ElevenLabs (63), los de Twilio (16) y el job (22). Los validan los cruces, que comprueban:
- que cada transición citada está permitida en `state-transitions.json` y parte del estado de la fila;
- que los eventos pertenecen al conjunto cerrado;
- que cada centinela de `mustNotPersist` está de verdad en el cuerpo;
- que `envelopeType` casa con la regla del H6;
- que la clave de deduplicación es `data.conversation_id`;
- que los turnos marcados por la guarda son del agente;
- que la firma de cada aviso de gasto casa con su HTTP esperado y que `rawBody` es el formulario de `params`;
- la conversión del precio a micro-US$;
- que el estado final de cada job está permitido desde `requested`.

**Reproducible.** Dos ejecuciones del generador dan los mismos sha256 (comparados con `shasum`). Los sha256 de hoy:
- `compiler-cases.json` `436c0fe4…`;
- `outbound-gate-cases.json` `5b938e06…`;
- `state-transitions.json` `68150559…`;
- `policy-tables.json` `96210529…`;
- `holidays-co-2026-2028.json` `37a12519…`;
- `elevenlabs-webhook-vectors.json` `24d36cc9…`;
- `elevenlabs-webhook-bodies.json` `8c572b4c…`;
- `twilio-signature-vectors.json` `932fb5ea…`;
- `twilio-webhook-bodies.json` `386e28dd…`.

### 2.2 `--php`: frente a las clases reales

Carga solo tres ficheros puros, sin Laravel: `StudioContentHasher.php`, `ElevenLabsWebhookSignature.php` y `ElevenLabsWebhookEnvelope.php`. Antes de cada ejecución, `git diff --quiet` confirma que siguen **sin cambios** frente a `57bab8a`.

- **JSON canónico.** Los 9 vectores de `canonicalHash` y las 73 huellas `storedHash` de los perfiles de `compile` coinciden byte a byte con `StudioContentHasher`. Entran también las rarezas de PHP (DF-C10):
  - un objeto vacío sale `[]`;
  - unas claves `0` y `1` salen como lista;
  - U+2028 sale escapado;
  - un decimal lanza.
- **ElevenLabs.** `ElevenLabsWebhookSignature::verify` da **27 de 27** resultados iguales a los del fixture y `ElevenLabsWebhookEnvelope::type`, **14 de 14**. El fixture es, por tanto, lo que la clase que D8-16 manda usar «sin cambios» ya hace.
- **Resto.** Las firmas de Twilio con `hash_hmac('sha1')` (15), las huellas de teléfono `voice-phone` y de canario (6) y la Pascua con `easter_days()` (3).

### 2.3 El comprobador no se dobla

En una copia del scratchpad estropeé cinco `expected`:
- el T-1 de Twilio, que pasó a válido;
- el borde de 1800 s, que pasó a caducado;
- el Corpus Christi de 2027, que dejó de ser festivo;
- el orden de dos motivos de la puerta;
- el motivo de «falta end_call».

```
derivación independiente: 767 casos rehechos · discrepancias: 5
  twilio-signature-vectors.json: DISCREPA «validate · form · T-1: la firma oficial contra la URL en http (fullUrl() tras el proxy) no vale»
  elevenlabs-webhook-vectors.json: DISCREPA «verify · postCall · borde: t = now − 1800 todavía vale»
  holidays-co-2026-2028.json: DISCREPA «isHoliday · date · Corpus Christi 2027: se celebra el lunes 2027-05-31»
  compiler-cases.json: DISCREPA «compile · negative · falta end_call»
  outbound-gate-cases.json: DISCREPA «decide · canary · freno y fuera de horario: los dos, en orden»
RESULTADO: FALLA (7)
```

Los otros 2 fallos son de forma, y los cazó sin que yo los provocara a propósito: mi script de corrupción reescribió dos ficheros sin escapar los invisibles (U+0301, U+00AD, U+200B, U+200D, U+2028, U+202E), y el comprobador lo detectó.

### 2.4 Defectos del propio fixture que cazó el comprobador (ya corregidos)

1. **Tres negativas del compilador con un motivo que depende del detector.** Un teléfono «300 000 0031» también es una «cifra» para la regla de precios del motor (`\d{4,}`, `\d{1,3}\s\d{3}`). Un correo lleva un dominio que un detector de URL marca. El contrato no fija los detectores, así que esos tres casos pasan a `reasonOneOf` (DF-C11): lo que se exige es que **no compile**. Añadí un teléfono sin ambigüedad («3 0 0 0 0 0 0 0 3 1»).
2. **Un caso con un motivo anterior oculto.** Con cuatro destinos sin «Recepción», `transfer_destination_not_in_profile` saldría antes que `too_many_transfer_destinations`. Lo corregí y añadí un caso que fija el orden «el motivo antes que el campo».
3. **Una variante equivalente.** «La Ascensión en E+40» no cambia nada, porque E+39 (jueves) y E+40 (viernes) se trasladan al mismo lunes. La sustituí por «Semana Santa desplazada un día».

### 2.5 `--sdk-python`: el arnés probado, el SDK no

Monté en el scratchpad un entorno virtual sin red (`python3 -m venv --without-pip`) con una **imitación** del paquete `elevenlabs`, que implementa el algoritmo documentado. Con ella:
- el arnés da `{"vectores": 27, "discrepancias": 0}`;
- si cambio su tolerancia a 60 min, da 1 discrepancia (`caducada: t = now − 1801`). Eso prueba que el reloj se congela en `now` y que la comparación funciona.

**Esto no verifica el SDK.** El comando para hacerlo está en la cabecera de `elevenlabs-webhook-vectors.json` (`sdkVerification`):
1. instalar `elevenlabs` con la versión fijada, en una máquina con red y fuera del repo;
2. ejecutar `voice-fixtures-check.py --sdk-python <python del venv>`.

### 2.6 Variantes equivocadas que caen (66)

Entre paréntesis, los casos que la cazan.

| Área | Variantes |
|---|---|
| Twilio | sin ordenar (20), sin quitar repetidos (2), `sort()` numérico (1), claves sin mayúsculas (10), consulta reordenada como `fullUrl()` (7), `bodySHA256` sin comprobar (3), sin tolerancia de puerto (3), SHA-256 (24), base sin `rtrim` (1) |
| ElevenLabs | sin el prefijo `t.` (12), `≤` al caducar (1), sin el futuro (1), `v0` sensible a mayúsculas (1), solo el primer `v0` (1), cuerpo vuelto a serializar (3), tolerancia de 300 s (1) |
| Festivos | sin Emiliani (56), lunes que también se traslada (3), los de Pascua sin trasladar (18), Semana Santa desplazada un día (1), Pascua una semana tarde (8) |
| Horario | hora UTC (13), intervalo cerrado (3), sábado hasta las 19 (2), domingo permitido (1), sin festivos (3), horario propio mal escrito que abre (2) |
| Frecuencia | cuenta las canceladas (2), cuenta las entrantes (1), día UTC (1), 7 días móviles como semana (3), semana desde el domingo (1) |
| Consentimiento | sin cuatro ojos (2), grabada con la grabación apagada (1), empate por el id menor (1), el primer evento (4), el consentimiento del lead del motor (1) |
| Exclusiones y RNE | canarios sin exclusiones (1), `denied` ignorado (1), vigencia con el borde fuera (1) |
| Entrantes | la constante antes que la bandera (3), concurrencia con `>` (2), solo el primer motivo (12) |
| Coste | minutos por defecto (2), sin Media Streams (7), sin el tramo transferido (1), presupuesto con `<` (1) |
| Estados | finales mutables (144), hacia atrás (2), `dialing` → `canceled` (1), insertar en cualquier estado (9) |
| Compilador | autoaprobación (1), solo `APPROVED` (2), quitar invisibles antes (5), solo las frases del catálogo (2), `end_call` opcional (2), sin recortar el tope (7), herramientas con orden en la huella (1), uuid en la huella (1) |
| Puerta | solo el primer motivo (38), `daily_cap_reached` también con cupo 0 (3), `voice_contacts` prohibido (7), canarios sin exclusiones (1), concurrencia con `>` (2), WhatsApp sin el 57 nacional (1), WhatsApp literal (2) |

## 3. Preguntas para el lead (el detalle, en el README)

**Las cuatro que más pesan:**
- **Q-3 (D8-10 frente a D8-13).** Las herramientas exigen la llamada `active`, pero `active` es solo de entrantes: una saliente contestada sigue en `dialing`. Leído al pie de la letra, **ninguna herramienta respondería en una saliente**. El fixture admite `dialing` o `active` (DF-E9).
- **Q-6 (D8-19 y D8-21).** La conciliación marcaría como `unknown_provider_call`, y abriría el freno automático:
  - los **tramos de transferencia**, que son llamadas de Twilio sin fila propia;
  - **cualquier entrante denegada**, si la iniciación no deja fila.

  Así, cualquier desconocido que llame al número pararía la voz. El fixture reconoce los dos casos (DF-W4 y DF-W5). Hay que decidir si una entrante denegada crea fila: hoy no hay ningún estado para ella.
- **Q-1 (P-9 frente a D8-17, legal).** El aviso provisional no dice **la finalidad** ni **cómo pedir no recibir más llamadas**, que D8-17 exige. Es la revocación de la Ley 2300. El fixture usa el texto de P-9 literal.
- **Q-14 (D8-8).** Falta el mensaje exacto que firma la cadena de voz: ¿el prefijo `ultron-voice-config/1\n`, como el H5? Sin eso **no hay vectores de la cadena** (§5).

**El resto:**
- **Q-2.** `duplicate_request`: ¿corta la evaluación o se acumula?
- **Q-4.** Exclusiones en el alcance `canary`.
- **Q-5.** El `kind` de los avisos de gasto (`usage_alert`, fuera del conjunto cerrado).
- **Q-7.** Un `version_id` no nulo con P-2, ¿es deriva?
- **Q-8.** El horario, ¿al pedir o al marcar?
- **Q-9.** Los reintentos de ElevenLabs con el `t` original.
- **Q-10.** El tramo transferido no lo acota `max_duration_seconds`.
- **Q-11.** Los defectos 300 y 1 frente a la protección 1 («`VOICE_*` a 0»).
- **Q-12.** El aviso, ¿en el perfil o en la plantilla?
- **Q-13.** ¿`voice-phone` o `voice-phone/1`?
- **Q-15.** La frecuencia de 1 al día también para los canarios de la fase 4a.
- **Q-16.** Otros puntos sin fijar:
  - el tope de cuerpo de `usage-trigger`;
  - la longitud de los huecos;
  - NFC en los textos del perfil;
  - `version_label` en las salientes.

**Las 69 decisiones DF-… que usan los casos** (más DF-E7, DF-G4 y DF-G6, que solo están en el generador) quedan para que el lead las congele, por ejemplo en una enmienda, como E1 del H6. Las que más tocan el código:
- DF-G1 y DF-P8: las políticas **acumulan** los motivos, como `VoiceCallPolicy::decide`;
- DF-T2: tolerancia de puerto como el SDK de Twilio;
- DF-T3: `SORT_STRING`;
- DF-C1: los nombres y el orden de los 23 motivos del compilador;
- DF-S2 y DF-S3: las transiciones que el contrato no escribe;
- DF-P1: las 19:00 ya están fuera.

## 4. Observaciones para otros paquetes (no son del fixture)

- **O-1 (SEC8, S8).** `specHash` y la huella del perfil son sha256 sin clave sobre contenido que incluye el E.164 **en claro** de los destinos de transferencia. Con la base y el código se pueden probar números por fuerza bruta. Son números del personal (riesgo bajo).
- **O-2 (B8).** `OutboundContentGuard::inspect()` marca cualquier cifra. Con P-3, el adaptador de voz tiene que descontar las que devolvió `consultar_planes`. Las cifras dichas con letras quedan sin cubrir.
- **O-3 (B8).** Con el `sort()` por defecto de PHP, los valores numéricos repetidos se ordenan como números: hay que usar `SORT_STRING`. El vector «10/9» lo caza.
- **O-4 (OPS8).** Los vectores y los cuerpos firmados sirven tal cual a los simuladores, con las claves sintéticas de `keys`.

## 5. Lo que queda sin cubrir, y por qué

- **El valor de `specHash` y `payloadHash` de una compilación real.** Depende de la plantilla de S8, que está en el código (P-6) y que no leí, a propósito. Quedan fijados:
  - el **algoritmo** de la huella (vectores exactos y cruzados con PHP);
  - las **60 negativas**;
  - las **14 relaciones** entre huellas: qué cambia y qué no cambia `specHash`.
- **La carga y la proyección de ElevenLabs** (crear, leer y asignar agentes) y los casos de deriva. Sus rutas y campos están **[por confirmar]** en el contrato (D8-3) y dependen del mapeador de B8.
- **La cadena de voz** (D8-8): Q-14.
- **Lo que no es puro:**
  - los disparadores, CHECK y `REVOKE` en PostgreSQL. `state-transitions.json` da la tabla y DB8 la prueba en su puerta PG;
  - RBAC y `AuthorizationMap` (D8-22);
  - el laboratorio hablado (D8-23) y la evidencia y la promoción (D8-19, P-16);
  - el conector del RNE (P-14);
  - los DTO del CRM;
  - el E2E con simuladores. Es mi paso 3, que espera a la puerta 2.
- **El SDK de ElevenLabs.** Sin verificar (§2.5).
- **Fuentes que no abrí hoy:**
  - la Ley 51 de 1983 y la página de Usage Trigger;
  - el fichero oficial de Twilio, que sale de la descarga del H6.

  El fixture de festivos queda con `approvedByOwner: false` hasta que lo apruebe el propietario (D8-15).

## 6. Estado del árbol

- Solo añadí ficheros en `tests/fixtures/voice/` y este informe. No toqué nada más.
- Los cambios ajenos que se ven en `git status` (S8 y B8) no son míos y no los leí.
- No hay `__pycache__`: el generador ejecuta las secciones con `exec` y los scripts se lanzan con `-I`.
- Los temporales (la copia estropeada, la imitación del SDK y las salidas) están en el scratchpad de la sesión, fuera del repo.
