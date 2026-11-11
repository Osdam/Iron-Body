# Fixtures de voz y telefonía del Hito 8 (TEST8-a, paso 1, con las Enmiendas 1 y 2)

Escritos **desde el contrato y antes del código**: `docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md`, congelado el 03-11-2026 (commit `57bab8a`), y su auditoría `ULTRON_STUDIO_H8_VOICE_AUDIT.md`. Desde el 03-11-2026 aplican también la **Enmienda 1** (commit `75a6384`):
- **E1-1:** las herramientas responden en `dialing` o `active`, y la primera pasa una saliente a `active`;
- **E1-2:** las entrantes denegadas llevan fila `denied` y cada tramo de transferencia se enlaza con su llamada madre;
- **E1-3:** el aviso nuevo;
- **E1-4:** la cadena de voz;
- **E1-9:** el compilador alineado con S8-a.

Son el oráculo de las pruebas puras de B8, S8, DB8 y OPS8.

**Regla de uso.** Si una implementación discrepa de un caso con `basis` `contrato`, `oficial` o `ley`, se corrige la implementación. Si el caso es `decision-fixture`, lo congela el lead (§Decisiones). Si el lead decide otra cosa, se cambia aquí con el generador. **Nunca se dobla un caso para que dé verde.**

`compiler-cases.json` es la excepción declarada (E1-9): se alinea **con el compilador real**. Sus `expected.realCompiler` son una instantánea de lo que ese compilador da hoy (DF-C12).

**No los ejecuta la suite.** PHPUnit solo recorre `tests/Unit` y `tests/Feature`. Aquí hay `.json`, `.md`, `.py` y un exportador `.php` que se lanza a mano.

## Cómo se generan y se comprueban

```
python3 -I tests/fixtures/voice/tools/build/build.py            # reescribe los 10 JSON (reproducible byte a byte)
php tests/fixtures/voice/tools/compiler-export.php > tests/fixtures/voice/tools/build/compiler-export.json   # E1-9: el compilador real
python3 -I tests/fixtures/voice/tools/build/build.py            # otra vez: incrusta expected.realCompiler y exige que cada caso cuadre
python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py   # forma + segunda derivación + cruces + variantes
python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py --php                       # + PHP real (ver abajo)
python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py --sdk-python /ruta/python   # + el SDK oficial de ElevenLabs (cuando haya uno instalado)
```

- **El generador** está en `tools/build/`:
  - `build.py` contiene las primitivas, las claves sintéticas y la serialización;
  - un `sec_*.py` por fichero contiene los casos **a mano**;
  - `compiler-export.json` es la instantánea del compilador real.

  Cada `expected` es un literal escrito desde el contrato y su Enmienda. El generador solo calcula lo que no se puede escribir a mano (HMAC, sha256, fechas) y contrasta los literales con una referencia propia (`assert`).

  Si una entrada del compilador cambia, su huella vieja no se usa: se compara el sha256 de la entrada y el generador avisa «vuelve a exportar».
- **El exportador** (`tools/compiler-export.php`) pasa cada caso de `compiler-cases.json` por `StudioVoiceCompiler` con la **misma traducción** que `tests/Unit/UltronStudio/StudioVoiceCompilerParityTest.php` (S8-a). Está explicada en su cabecera:
  - nombres de campo;
  - huella `voice-phone` de los destinos;
  - versión real del catálogo congelado con una variante de `maxChars` por cada huella de versión distinta, en orden de aparición;
  - tope recortado.

  Carga el autoload de Composer: no arranca Laravel, ni toca la base ni la red. En la segunda pasada, el generador **exige** que cada caso cuadre con el compilador real: motivo y campo traducidos, que compile, y la igualdad de huellas de cada relación. La única excepción es la declarada en `realCompiler.knownMismatch` (DF-C9).
- **El comprobador** (`tools/voice-fixtures-check.py`) **no importa el generador**. Vuelve a derivar cada caso con otra implementación y otra maquinaria:
  - toda la criptografía con el binario `openssl`;
  - la Pascua por Gauss;
  - Bogotá con `zoneinfo`;
  - el aviso comparado con el texto de E1-3 copiado del contrato.

  Además prueba 79 implementaciones equivocadas.
- **`--php`** carga solo clases puras y comprueba que siguen sin cambios:
  - `StudioContentHasher`;
  - `ElevenLabsWebhookSignature` y `ElevenLabsWebhookEnvelope`;
  - `hash_hmac` y `easter_days`;
  - la derivación de la subclave del H5 para la cadena de voz.

  Además **vuelve a ejecutar el exportador** sobre el fichero que se comprueba y compara cada huella y cada motivo.

## Forma común de los 10 ficheros

**Cabecera:**
- `fixture`, `version`, `contract`, `audit` y `contractSections`;
- `generatedBy`, `checkedBy` y `readme`;
- `basisLegend`;
- `sources`: cada fuente con `what`, `url`, `consulted` y `how`;
- `syntheticData`;
- `decisions`: solo las que usan sus casos;
- `counts`;
- y lo propio de cada fichero: `keys`, `closedSets`, `algorithm`, `world`, `years`, `realCompilerExport`…

Después, una lista de casos por sección.

**Cada caso** lleva:
- `name`, único y con el formato `<sección> · <kind> · <descripción>`;
- `kind`;
- `contractRef`: de qué sección del contrato o de la Enmienda sale;
- `basis`, uno de estos:
  - `contrato`;
  - `oficial`;
  - `ley`;
  - `supuesto-lead`;
  - `decision-fixture`;
- `decision` (DF-…) cuando el caso depende de una decisión;
- sus entradas;
- `expected`.

**Convenciones:**
- Una cabecera HTTP con valor `null` significa **ausente**.
- `mustNotPersist` y `responseMustNotContain` son centinelas que **están** en la entrada. No pueden aparecer en ninguna fila, evento, log ni respuesta.
- Ningún dato es real:
  - SIDs, ids, secretos y dominios `.test` inventados;
  - teléfonos de ficción;
  - la `APP_KEY` del H6 (bytes 00..1f).

  Ni invisibles literales: van como `\uXXXX`.

## Índice

| Fichero | Para | Secciones (casos) | Contrato |
|---|---|---|---|
| `twilio-signature-vectors.json` | B8 `TwilioRequestSignature` | `compute` 15 (los 6 oficiales + 9 derivados), `validate` 25, `urlBuild` 5 | D8-4, D8-16 |
| `elevenlabs-webhook-vectors.json` | B8 `ElevenLabsEventVerifier` | `verify` 27 | D8-16, D8-3, D8-5 |
| `elevenlabs-webhook-bodies.json` | B8 webhooks, herramientas y guarda; OPS8 | `postCall` 16, `guard` 9, `initiation` 11, `tools` 21, `routeRules` 11 | D8-8, D8-10, D8-11, D8-13, D8-16, D8-17, D8-19, E1-1, E1-2, E1-3 |
| `twilio-webhook-bodies.json` | B8 `usage-trigger` y conciliación; OPS8 | `usageTrigger` 7, `callStatus` 8, `callsPage` 1 (9 llamadas) | D8-16, D8-19, D8-21, E1-2 |
| `holidays-co-2026-2028.json` | B8 `ColombianHolidays` | `years` (3 × 19) e `isHoliday` 85 | D8-15 (Ley 51 de 1983), E2-1 (Ley 2578 de 2026) |
| `policy-tables.json` | B8 políticas y sus tablas, `VoiceCostGuard`, freno | `scheduleLegal` 22, `scheduleOwn` 11, `frequency` 19, `consent` 13, `exclusions` 16, `inboundPolicy` 15, `costReservation` 17, `brake` 16, `phoneHash` 7 | D8-11, D8-15, D8-17, D8-18, D8-19, E1-1, E1-2 |
| `state-transitions.json` | DB8 (disparador), B8 | `allowed` 25, `matrix` 288 (12 × 12 por dirección), `insert` 24, `sequences` 19, `columns` 25, `eventDedupe` 5 | D8-13, D8-8, E1-1, E1-2 |
| `compiler-cases.json` | S8 `StudioVoiceCompiler`; B8 `VoiceAgentSpec` | `compile` 83 (13 válidas + 70 negativas), `capClamp` 11, `hashRelations` 15, `canonicalHash` 9 | D8-6, D8-7, D8-11, D8-14, D8-15, D8-17, E1-3, E1-5…E1-9 |
| `outbound-gate-cases.json` | B8 `OutboundPolicy` y `PlaceOutboundVoiceCall` | `decide` 43, `whatsappGuard` 7, `job` 22 | D8-12, D8-9, D8-5, D8-13, D8-19, D8-21 |
| **`voice-chain-vectors.json`** (nuevo) | S8-b `VoicePublishedConfig::signatureFor()` y su lector | `chain` 5, `subkey` 3, `verify` 27 | E1-4, D8-8, D8-9, D8-20 |

**Total: 988 casos.** Por base:
- `contrato`: 443;
- `decision-fixture`: 392 (163 de `state-transitions.json`, 98 de ellos de la matriz);
- `ley`: 105;
- `oficial`: 32;
- `supuesto-lead`: 16.

### Cómo se consume cada uno (resumen)

- **Firmas de Twilio.**
  - `compute`: `signature(token, url, params | rawBody)`.
  - `validate`: `valid(…)`. `validWithoutPortTolerance` es lo que daría un validador sin la tolerancia de puerto del SDK.
  - `urlBuild`: `url(base, requestUri)`.
- **Firmas de ElevenLabs.** `ElevenLabsWebhookSignature::verify(rawBody, header, secret, now)` → `signatureResult`, y el controlador → `http`. `sdkConstructEvent` está **por confirmar con el SDK**.
- **Cuerpos de ElevenLabs.**
  - `postCall`: con `given.call` como fila previa → `http`, `events`, `transition` (o `statusAfter`), `seal`, `costRaw`, `guard`, `brakeOpens` y `mustNotPersist`.
  - `guard`: `transcript` → `violations`.
  - `initiation`: la respuesta exacta y la fila. Con E1-2, las denegadas nacen en `denied`.
  - `tools`: `verdict`, `http`, `transition` y `sideEffects`. Con E1-1, la primera herramienta de una saliente la pasa de `dialing` a `active`.
- **Cuerpos de Twilio.**
  - `usageTrigger`: formulario firmado → `http`, `persisted`, `eventKind`, `dedupeKey` y `brakeOpens`.
  - `callsPage`: `listCalls` → `perCall`. Con E1-2, la entrante denegada es conocida por su fila y el tramo de transferencia se enlaza con la madre: solo la llamada sin fila abre el freno.
- **Festivos.** `years` y `isHoliday`.
- **Políticas.** Tablas de entrada y `expected`. `inboundPolicy` da `codeOpen` y `codeClosed`.
- **Estados.**
  - `matrix`: `(from, to, direction)` → `allowed`.
  - `insert`: los estados iniciales por dirección (`initialStatus`: listas).
  - `sequences`, `columns` y `eventDedupe`.
- **Compilador.**
  - `compile`: `input` neutro → `compiles` con `invariants`, o `reason` y `field` (http 422).
  - `realCompiler`: lo que da el compilador real (`specHash` y `voiceProfileHash`, o su `reason` y `field` con sus nombres).
  - `hashRelations`: `sameSpecHash` y `realCompiler.specHashA` y `specHashB`.
  - `capClamp` y `canonicalHash`.
- **Puerta de salientes.** `decide` → `codeOpen` y `codeClosed`; `whatsappGuard`; `job`.
- **Cadena de voz.**
  - `chain`: cada `entry` → su `message` (JSON canónico), `entryHash` (sha256), `signature` (HMAC-SHA256 con la subclave) y el enlace `prev_hash`.
  - `subkey`: la subclave de cada APP_KEY y etiqueta.
  - `verify`: `message`, `signature` y `previous` (`{seq, entryHash}` o `null`) → `result` (`ok`, `malformed`, `not_canonical`, `signature_malformed`, `signature_mismatch`, `seq_gap` o `chain_broken`).

## Lo que cambió con la Enmienda 2 (03-11-2026)

- **E2-1.** La Ley 2578 del 01-06-2026 añade la Virgen de Chiquinquirá: el 9 de julio, trasladable al lunes como los de la Ley 51 de 1983, desde 2026. Cae el 13-07-2026, el 12-07-2027 y el 10-07-2028, así que hay **19 festivos por año**.
  - En `years`, sus filas llevan `pendingConfirmation` = «por confirmar (Ley 2578/2026, demandada ante la Corte Constitucional; E2-1)»; las demás, `null`.
  - En `isHoliday` hay 8 casos nuevos: los tres lunes, sus tres fechas de origen y dos instantes en el borde de medianoche de Bogotá.
  - En `policy-tables.json` hay 2 casos nuevos de `scheduleLegal`: el lunes festivo y el jueves 9. Ningún caso anterior caía en esos días.
  - Tratar el día como festivo solo restringe más las salientes. Si la Corte tumba la ley, se quita en un commit: la regla, los 8 casos y los 2 de horario.
- **E2-7.** Confirma la cadena de voz tal como está en `voice-chain-vectors.json`: sin prefijo y con `prev_hash` = sha256 del mensaje anterior. Cierra Q-17 y Q-18.
- **Comprobador.**
  - Exige 19 festivos por año y la marca solo en Chiquinquirá.
  - Variante nueva `ho_no_ley_2578` (cazada).
  - Con `--php` cruza además con `app/Services/Telephony/ColombianHolidays.php` de B8-a: `forYear()` en los tres años, `isHolidayDate()` e `isHoliday()` en los 85 casos. Son 88 cruces, 0 fallos.

## Lo que cambió con la Enmienda 1 (03-11-2026)

| Id | Dónde | Cambio |
|---|---|---|
| E1-1 | `state-transitions`, `policy-tables`, `elevenlabs-webhook-bodies` | Transiciones nuevas: `dialing → active` en las salientes, y `active → completed`, `hung_up` y `failed` también en las salientes. La frecuencia cuenta `active`. Las herramientas valen en `dialing` y `active`, y la primera hace la transición. El caso de la saliente con herramientas parte de `active`, y se añade una saliente sin herramientas (`dialing → completed`) |
| E1-2 | `state-transitions`, `elevenlabs-webhook-bodies`, `twilio-webhook-bodies`, `policy-tables` | Estado `denied`: final, de entrada e inicial de las entrantes. Las iniciaciones denegadas crean fila `denied` (con el caller ID oculto, sin huella: DF-E17). Hay una post-llamada sobre una `denied` y una herramienta sobre una `denied` → `call_not_active`. En la conciliación, la denegada es «conocida» y el tramo de transferencia queda enlazado con la madre (contrato). La denegada no cuenta para la frecuencia |
| E1-3 | `elevenlabs-webhook-bodies` (`world.aiNotice` y transcripciones), `elevenlabs-webhook-vectors` (sus firmas), `compiler-cases` | El aviso nuevo en todos los cuerpos y perfiles. El de P-9 pasa a ser una negativa (`notice_missing`) |
| E1-4 | `voice-chain-vectors.json` (nuevo) | Mensaje, huella, firma y lector de la cadena de voz |
| E1-5…E1-9 | `compiler-cases.json` | Alineado con el compilador de S8-a, más las huellas reales (lista abajo) |

**El detalle de `compiler-cases.json` (E1-9):**
- **Caracteres de la plantilla.** El emoji pasa a negativa (`slot_characters`), con casos nuevos de llaves, comillas angulares y símbolos (E1-8).
- **Buzón.** `leave_message` pasa a negativa (`voicemail_not_allowed`).
- **Herramientas.**
  - «Transferir sin `solicitar_humano`» pasa a negativa (`transfer_requires_human_tool`), con una válida nueva: dos herramientas sin transferir.
  - «Sin `no_volver_a_llamar`» es negativa (`opt_out_tool_missing`). La relación «una herramienta menos» quita ahora `consultar_planes`.
- **Orden de los motivos.** Manda el compilador: primero la versión y después el perfil campo a campo en el orden declarado; dentro de cada texto, el orden de la plantilla.
  - Los tres casos solapados de antes (teléfono o dinero, correo o URL) pasan a tener un motivo exacto.
  - El caso «dos campos» distingue ahora los dos órdenes posibles.
- **Uuids.** Entran en `specHash`: la relación de los uuids da ahora huellas distintas.
- **Primer mensaje.** Es solo el aviso; los saludos van en el prompt (E1-6). La invariante `firstMessageEquals` y `systemPromptContainsGreetings` sustituyen a `firstMessage*StartsWith/Contains`.
- **Destinos.** Van por alias y por su huella `voice-phone` (`transferDestinationRefs`). `phonesNotInSpec` sustituye a `phonesOnlyInTransferDestinations`.
- **Variables y versiones.** La variable `call_direction` (E1-5). Las versiones que compilan, APPROVED, CANARY y PUBLISHED, pasan de decisión a contrato (E1-7).

### Para la paridad de S8 (`StudioVoiceCompilerParityTest`, fichero de S8)

Con este fixture, su lista `CONFLICTS` se queda **vacía**. Su prueba va a fallar hasta que la actualice: lo digo leyendo su código, no la ejecuté. No la toqué. Hay que cambiar esto:
1. **Quitar las 6 entradas de `CONFLICTS`.** Sus casos ya existen con otro nombre o con otra expectativa:
   - `compile · negative · slot_characters en slots.cierre: «Te esperamos ❤⟦U+FE0F⟧ 👨⟦U+200D⟧👩⟦U+200D⟧👧»`;
   - `compile · negative · buzón leave_message: no existe (solo hang_up)`;
   - `compile · negative · transfer_to_number sin solicitar_humano` y `compile · valid · dos herramientas sin transferir (no_volver_a_llamar se queda)`;
   - `compile · negative · dos campos con problemas distintos (cifra en el saludo de entrada, URL en un slot): manda el primer campo declarado aunque su motivo vaya después, slot_money en greetingInbound`;
   - `hashRelations · specHash · otro uuid de perfil y de versión (mismo contenido) → otra huella (E1-9: los uuids entran)`;
   - `hashRelations · specHash · una herramienta menos (consultar_planes; no_volver_a_llamar no se quita) → otra huella`.
2. **`REASONS`:**
   - `text_characters` → `slot_characters`;
   - `opt_out_tool_required` → `opt_out_tool_missing`;
   - `transfer_tools_mismatch` → `transfer_requires_human_tool`.

   **`REASONS_BY_FIELD`:** `profile_field_invalid|profile.content.voicemail` → `voicemail_not_allowed`. Es la misma tabla que usa `tools/compiler-export.php`.
3. **Invariantes:**
   - `firstMessageEquals` (en lugar de `firstMessage*StartsWith`);
   - `systemPromptContainsGreetings` (en lugar de `firstMessage*Contains`);
   - `transferDestinationAliases` y `transferDestinationRefs` (en lugar de `transferDestinations`);
   - `phonesNotInSpec`;
   - `dynamicVariables`.
4. **Opcional:** comparar `specHash` con `expected.realCompiler.specHash`. Hace falta la misma asignación de variantes de versión de la cabecera (`realCompilerExport.versionVariants`).

`NOT_APPLICABLE` no cambia (los dos casos conservan el nombre).

## Decisiones del fixture (las congela el lead)

El texto exacto de cada una está en la cabecera `decisions` del fichero que la usa (72 con caso). DF-E7, DF-G4 y DF-G6 solo están en el generador. Desde la Enmienda 1:
- **Pasan a contrato:**
  - DF-C2 (E1-7);
  - DF-C11, porque con el orden del compilador ya no hace falta `reasonOneOf`;
  - DF-W5 (E1-2);
  - DF-E10 (E1-2: una entrante denegada está en `denied`).
- **Cambian de texto:**
  - DF-E9: los veredictos, con E1-1 y E1-2;
  - DF-W4: el tramo, enlazado con la madre por E1-2;
  - DF-C1: el orden del compilador real;
  - DF-C3: los caracteres de E1-8;
  - DF-C4: el aviso de E1-3;
  - DF-C7: los uuids dentro;
  - DF-C8: el primer mensaje de E1-6;
  - DF-P3: `active` cuenta y `denied` no;
  - DF-S4 y DF-S8: las iniciales de las entrantes.
- **Nuevas:**
  - **DF-E17:** fila `denied` sin huella con el caller ID oculto;
  - **DF-C12:** `realCompiler` es una instantánea de regresión;
  - **DF-V1…V6:** la cadena de voz:
    - `prev_hash` = sha256 del mensaje anterior, no su firma;
    - `published_at` en UTC y al segundo;
    - `scope` ∈ {canary, rest, off}, y una `off` va sin artefactos;
    - el lector exige los bytes canónicos;
    - la firma, en hex en minúscula;
    - el orden de las comprobaciones del lector.

**El resto, por grupo:**
- **T:** URL, puerto, orden de bytes, `bodySHA256` y token vacío.
- **E:** 401, deduplicación, JSON roto, finales, llamada desconocida, iniciación mal formada, tres variables, esquema estricto, turnos de la guarda, `seal_mismatch` en la iniciación, `called_number`, Twilio `canceled`, HTTP de las herramientas y divergencias del SDK.
- **W:** `usage_alert`, 401, sin token, tramo y precio.
- **P:** horario, frecuencia, consentimiento, exclusiones, RNE, entrantes, coste, freno y huella.
- **S:** sin cambio de estado, webhook antes que el job, `ambiguous`, inmutables, una sola escritura, eventos sin clave e iniciales.
- **C:** forma del perfil, tope, `handoffAliases` y JSON de PHP.
- **G:** acumulación, orígenes, cupos, `duplicate_request`, reserva, respuestas raras, job y WhatsApp.

## Preguntas abiertas para el lead

**Resueltas por la Enmienda 1:**
- **Q-1** (E1-3);
- **Q-3** (E1-1);
- **Q-6** (E1-2): «hay que fijar si una entrante denegada crea fila» queda cerrado, porque crea fila `denied`;
- **Q-14** (E1-4).

**Siguen abiertas:**
- **Q-2:** `duplicate_request`, ¿acumula o corta?
- **Q-4:** las exclusiones en `canary`.
- **Q-5:** `usage_alert` no está en el conjunto cerrado de eventos.
- **Q-7:** ¿un `version_id` no nulo es deriva?
- **Q-8:** ¿el horario se evalúa al pedir o al marcar?
- **Q-9:** los reintentos de ElevenLabs con el `t` original.
- **Q-10:** el tramo transferido no lo acota `max_duration_seconds`.
- **Q-11:** los defectos 300 y 1 frente a la protección 1.
- **Q-12:** E1-3 confirma el texto, y el compilador lo tiene en la plantilla y lo compara con el perfil.
- **Q-13:** ¿`voice-phone` o `voice-phone/1`?
- **Q-15:** la frecuencia de los canarios en la fase 4a.
- **Q-16:** el tope de `usage-trigger`, NFC y `version_label` en las salientes.

**Nuevas:**
- **Q-17 (E1-4), cerrada por E2-7: sin prefijo.** El H5 firma `CONTRACT + "\n" + json`. E1-4 define el mensaje como el JSON canónico a secas, y así van los vectores. Si S8-b prefiere el prefijo, como el H5, hay que cambiarlo aquí y en E1-4. La variante `vc_contract_prefix` lo caza.
- **Q-18 (E1-4), cerrada por E2-7: `prev_hash` = sha256 del mensaje anterior.** La forma de `prev_hash`: aquí es el sha256 del mensaje anterior (DF-V1), como `effective_hash` del H5. Si se quiere la firma anterior, la variante `vc_prev_is_signature` lo caza.

## Observaciones para otros paquetes (no son del fixture)

- **O-1 (SEC8, S8):** queda resuelta en S8-a. La especificación lleva la huella `voice-phone` de cada destino, no el número.
- **O-2 (B8):** `inspect()` marca cualquier cifra y, con P-3, el adaptador de voz tiene que descontar las de `consultar_planes`.
- **O-3 (B8):** `SORT_STRING` en el validador de Twilio.
- **O-4 (OPS8):** los vectores y cuerpos firmados sirven a los simuladores.
- **O-5 (S8-b):** la cadena de voz necesita su propio `HEX64` en minúscula y la recanonicalización en el lector (DF-V4, DF-V5). Los 27 casos de `verify` los fijan.

## Lo que no cubre, y por qué

- **Los valores de `specHash` de cualquier plantilla futura.** `realCompiler` es la de hoy: `ultron-voice-template/3` (P-M2, 10-11-2026: las negativas exactas; antes `/2` de S8-d y `/1`), con el aviso de E1-3, huella de la plantilla `aa8d8f80…` y sha256 del fichero `6011f48b…` (los dos en `realCompilerExport.compiler`). Si cambia a propósito, se reexporta y se revisa: con la `/3` solo cambiaron la cabecera de la plantilla y las 43 huellas de las compilaciones válidas y de las relaciones; ningún motivo, perfil ni aviso.
- **La carga y la proyección de ElevenLabs** (B8) y la deriva.
- **Lo que no es puro:**
  - disparadores y `REVOKE` en PostgreSQL;
  - RBAC;
  - el laboratorio hablado;
  - la evidencia y la promoción;
  - el conector del RNE;
  - los DTO del CRM;
  - el E2E.
- **El SDK de ElevenLabs:** sin verificar (`sdkConstructEvent` por confirmar).
- **Fuentes que no se abrieron** (sin red): la Ley 51 de 1983 (`approvedByOwner: false`), la Ley 2578 de 2026 (por confirmar, demandada) y la página de Usage Trigger.

## Verificación (salida real del 03-11-2026, tras la Enmienda 2)

```
ficheros: 10 · total 988
forma: OK
derivación independiente: 882 casos rehechos · discrepancias: 0
cruces entre ficheros: 221 comprobaciones
variantes equivocadas: 80 · cazadas 80
php: canonicalHash+perfiles 92, elevenlabsVerify 27, envelope 16, huellasTelefono 6, firmasTwilio 15, pascua 3, cadenaVoz 8, compiladorReal 98, festivosB8 88 · fallos 0 (PHP 8.3.33)
RESULTADO: OK
```

El detalle está en `docs/ultron-studio/plans/informes-h8/informe-tester.md` (§E2 y §E1).
