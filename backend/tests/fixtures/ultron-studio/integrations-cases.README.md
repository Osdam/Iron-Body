# `integrations-cases.json`: integraciones comerciales (Hito 6)

**Procedencia.** Lo escribió el tester **a mano** desde `docs/ultron-studio/ULTRON_STUDIO_H6_CONTRACT.md`: §I2 (conjuntos cerrados), §I3 (Meta), §I4 (números canarios), §I5 (Twilio), §I6 (voz), §I7 (rutas, permisos, errores) y §I11 (secciones y mínimos). También usa su **Enmienda 1** (01-11-2026):
- **E1.1.** Importes de `facts` como texto con dos decimales, recuentos vacíos `[]`, etiquetas de `StudioVersionStatus::label()` y PCRE con `D` y `[0-9]` sin `/u`.
- **E1.2.** Las seis decisiones de V que acepta el lead: `provider`, región, firma estricta, un 200 que no es objeto JSON es `error` y huellas del secreto recortado.
- **E1.3.** D-1 y D-2 del esquema.
- **E1.4.** Las convenciones del saneador de Twilio, que son las D-10 del paquete TW.

Ningún `expected` sale de ejecutar las clases del hito, que se escribían en paralelo y no se leyeron. Cada `expected` es un literal decidido a mano, en `tools/integrations-cases/sec_*.py`. Las huellas y las firmas, que no se pueden escribir a mano, se calcularon con `hmac`/`hashlib` de Python sobre datos sintéticos.

Hay tres comprobaciones de apoyo, y ninguna es fuente de verdad:
- **Segunda derivación independiente**: `tools/integrations-cases-check.py`, escrito aparte y sin importar nada del generador.
  - Rehace los 701 casos con reglas desde la entrada. Los 18 de `errorOrder` se cruzan con `closedSets.errorCodes` y con los códigos de las secciones de casos. Resultado: 0 discrepancias.
  - Toda la criptografía va por **openssl 3.6.4**.
  - Simula **115 implementaciones equivocadas** plausibles. Cada una hace fallar al menos un caso.
  - Con `--tw`, la misma derivación rehace los 17 casos confirmados del paquete TW (`tests/Unit/Twilio/fixtures/`, `df1241c`): 0 discrepancias.
  - Con `--impl`, ejecuta las clases ya confirmadas contra este fichero:
    - `ElevenLabsWebhookSignature`: 52/52 idénticos;
    - `ElevenLabsWebhookEnvelope`: 13/13;
    - `TwilioProbeSanitizer`: 27/28. El que falta es la discrepancia DT-1 (abajo): se corrige la clase o se enmienda; el fixture no se dobla.
- **Las primitivas reales de PHP 8.3** (`--php`). Usa `hash_hmac`, `hash_equals`, `base64_decode`, `number_format` y el `StudioContentHasher` del árbol, el del H5, sin tocar:
  - `canonicalJson` da los bytes de los 10 `configFacts`, y `hash` sus huellas y las de `configDrift`;
  - rehace las 9 huellas de teléfono, los 21 `engineHash`, las 22 firmas de ElevenLabs que deciden `ok`/`mismatch` y los 7 vectores de Twilio;
  - y demuestra en este PCRE2 las trampas que fija el fixture (TEL-2).
- **Reproducible**: `python3 -I tools/integrations-cases/build.py` reescribe este fichero byte a byte.

**Quién lo consume** (§I11, «Núcleo puro»):

| Sección | Clase | Dueño |
|---|---|---|
| `phone`, `alias` | `StudioCanaryPhone` | S |
| `fingerprint` | `App\Support\Privacy\PhoneFingerprint` | S |
| `canaryTransitions`, `campaignLinkRules`, `probeRules`, `acknowledgeRules` | Las puertas de las rutas 47–56 (puras si se separan en un evaluador; si no, Feature) | S |
| `coherence`, `expectation` | `StudioCanaryCoherence` y la resolución de `effectiveVersion` | S |
| `metaClassification`, `providerClassification` | `StudioMetaCampaigns` y la clasificación de la ruta 39 | S |
| `configFacts`, `configDrift` | `StudioIntegrationConfig` | S |
| `elevenlabsSignature`, `elevenlabsEnvelope` | `ElevenLabsWebhookSignature`, `ElevenLabsWebhookEnvelope` | V |
| `voiceDoctor`, `voiceVerify` | `ElevenLabsConfig`, `ElevenLabsVoiceProvider` (con `Http::fake`) y el `ELEVENLABS_VERIFIED` del Studio | V (y S el último) |
| `voiceCallPolicy` | `VoiceCallPolicy` y los niveles de la ruta 45 | V (y S el contexto) |
| `twilioProbeSanitize` | `TwilioProbeSanitizer` | TW |
| `twilioSignatureOfficial` | El validador de Twilio el día que se extraiga (T-1/T-2) | — |
| `routeMatrix`, `errorOrder`, `closedSets` | `AuthorizationMap`, `PermissionCatalog`, `UltronStudioException` y las Feature de la matriz; `closedSets` también para el CRM | S y FE |

Tienen que reproducir cada `expected` **exactamente**. Si PHP o TS discrepan, se corrige la implementación. Este fichero solo cambia con una enmienda: se edita el `sec_*.py`, se regenera y se pasa el comprobador.

**Recuento:** 719 casos en 22 secciones, más `closedSets` y `keys`.

| Sección | Casos | Mínimo de I11 | Desglose |
|---|---|---|---|
| `phone` | 88 | 60 | 84 `parse` + 4 `masked` |
| `alias` | 39 | 15 | — |
| `fingerprint` | 18 | 8 vectores | 9 `vector` + 7 `invalidE164` + 2 `emptyKey` |
| `canaryTransitions` | 121 | las 6 filas de I4.4 | 32 `register` + 19 `authorize` + 19 `activate` + 8 `deactivate` + 11 `revoke` + 16 `expectation` + 16 `actions` |
| `coherence` | 34 | los 7 veredictos | — |
| `expectation` | 24 | 12 | — |
| `campaignLinkRules` | 64 | 20 | 53 `create` + 11 `deactivate` |
| `metaClassification` | 39 | 15 | 16 `classify` + 17 `possiblyDeleted` + 4 `stale` + 2 `order` |
| `providerClassification` *(extra)* | 21 | — | 12 `twilio` + 5 `twilio_voice` + 4 `elevenlabs` |
| `configFacts` | 11 | 6 | 4 `twilio` + 3 `meta_ads` + 4 `voice` |
| `configDrift` *(extra)* | 8 | — | — |
| `elevenlabsSignature` | 52 | 20 | — |
| `elevenlabsEnvelope` *(extra)* | 13 | — | — |
| `voiceDoctor` | 38 | 12 | 22 `status` + 8 `baseUrl` + 8 `studioState` |
| `voiceVerify` *(extra)* | 15 | — | — |
| `voiceCallPolicy` | 24 | 16 | 18 `decide` + 6 `context` |
| `twilioProbeSanitize` | 28 | 12 | 20 de entrada hostil + 8 de las convenciones de E1.4 y D-10 |
| `twilioSignatureOfficial` | 7 | 1 | 1 `form` + 1 `json` + 1 `duplicateKeys` + 3 `port` (oficiales) + 1 `derived` |
| `probeRules` *(extra)* | 22 | — | — |
| `acknowledgeRules` *(extra)* | 9 | — | — |
| `routeMatrix` *(extra, §I7.1)* | 29 | — | 18 `route` + 10 `noView` + 1 `legacy` |
| `errorOrder` *(extra, §I7.3)* | 18 | — | una por ruta |

**Dónde vive.** En `tests/fixtures/ultron-studio/`, junto a los de F, G, H4 y H5. Las herramientas están en `tools/` y la suite no las ejecuta: PHPUnit solo recorre `tests/Unit` y `tests/Feature`, y ninguna prueba recorre este directorio.

## Forma

```jsonc
{ "version": 1, "catalogVersion": "2026-10-19.1", "contract": "…", "source": "…",
  "keys": { "appKeyBase64": "base64:AAECAwQF…Hh8=", "appKeyRaw": "h6-fixture-app-key-NOT-A-SECRET!",
            "phoneFingerprintLabel": "ultron-canary-phone/1", "configFingerprintLabel": "ultron-studio-config-fingerprint/1" },
  "closedSets": { … },
  "<sección>": [ { "name": "<sección> · <kind> · <descripción>", "kind": "…", …entrada…, "expected": { … } } ] }
```

- **`name`** es único en todo el fichero y empieza por `<sección> · <kind> · `: la lista se puede aplanar sin perder nada.
- **Claves de prueba.** Son sintéticas y no valen en ningún entorno:
  - `appKeyBase64` son los 32 bytes 00…1f;
  - `appKeyRaw` son 32 bytes ASCII sin `base64:`.

  El arnés las pone con `config(['app.key' => …])`. Cada caso que las usa lleva su `appKey`.
- **Identidades sintéticas.**
  - Personas: 7 y 8 (Super Admin), 9 y 10 (Administrador), y roles a medida: 11 (solo `ultron.view`), 12 (`view` + `canary_authorize`), 13 (`canary_manage` + `canary_authorize` sin `view`) y 14 y 15 (una llave sin `view`).
  - Versiones: A `1111…`, B `2222…` y C `3333…`.
  - Conversación canaria 501 y lead 9001. Ninguno es de producción.
  - Teléfonos ficticios: `+57 300 000 00NN`, `+57 60X 000 00NN`, el rango 555-01xx de EE. UU., el rango de ficción 7946 0xxx de Reino Unido y los números de prueba de Twilio (`+1 500 555 0006`).
  - El único teléfono «real» es el del vector oficial de Twilio (`+1 415 867 5309`, `+1 800 555 1212`): es su vector publicado y va tal cual.
- **Secretos sintéticos.** Llevan `h6fixture` en el valor (`h6fixturetwiliotoken…`, `wsec_h6fixture…`, …).
- **No hay ningún `{}`.** PHP no distingue `{}` de `[]` al decodificar con `true`, y escribe `[]` para un mapa vacío. Por eso `byStatus`, `byCode` y `byLevel` vacíos van como `[]` (ver SO-8 y O-4). Los nulos van explícitos.
- **Caracteres invisibles.** Van escapados (` `, `​`, `́`…). El valor es el mismo; el fichero no tiene ninguno literal.

## Cómo se compara

- Igualdad de valores JSON. Las listas cuentan en orden, salvo `audit` de `campaignLinkRules · create`, que es un conjunto de eventos.
- El orden de las claves es el del fixture. Con `assertSame`, la implementación lo devuelve en ese orden o el arnés lo normaliza. `facts` **tiene** que salir en el orden de `closedSets.configFactsKeys` (§I5.3).
- `canonicalJson`, `fingerprint` y las firmas se comparan **byte a byte**.
- **Claves que no se afirman:**
  - un `payload` ausente no se mira;
  - `payloadIncludes` es un subconjunto: el resto de la carga, como `drift` en `config_changed`, no se mira;
  - un `audit` ausente no se mira. `audit: null` afirma que **no** hay evento.
- **`null` en `activeWhatsapp`** (solo en `coherence` con `failure`) significa «no se afirma» (ver CO-4).
- **`mustNotContain`.** Ninguna de esas cadenas puede aparecer en el JSON de lo que devuelve la implementación (`facts`). El comprobador verifica además que cada centinela existe de verdad en alguna entrada de su sección.
- Ningún caso depende del reloj real, de la red ni de otro caso.

## Lo que el contrato no fijaba y fija este fichero

Cada punto cita los casos que lo fijan. **El lead tiene que congelarlos** en la Enmienda 1 del H6. Los que piden una decisión expresa van además en «Preguntas».

### Teléfono (`phone`)

**TEL-1. Solo se quitan U+0020, `-`, `.`, `(` y `)`, estén donde estén.** No se mira la estructura: «un guion entre cada dígito», «el prefijo entre paréntesis con el + dentro», «un guion antes del +» y «paréntesis, puntos y guiones repetidos» son válidos.

**TEL-2. «Cualquier otro carácter» es cualquier otro.** Incluye:
- el tabulador, U+00A0, U+202F y U+200B;
- U+2013, U+2212 y el `+` de ancho completo;
- los dígitos que no son ASCII;
- NUL, CR y LF.

En PHP eso exige dos cosas (casos «salto de línea al final…» y «dígitos de ancho completo…»):
- anclar al final de verdad: `\z` o el modificador `D`. El `$` de PCRE sin `D` acepta un `\n` final; en este PHP, `preg_match('/^\+[1-9]\d{7,14}$/', "+573000000001\n") === 1`;
- dígitos ASCII: `[0-9]`, o `\d` **sin** `/u`. Con `/u`, PHP activa UCP y `\d` acepta `３`.

**TEL-3. Orden: formato → país → canal.** Casos: «el formato va antes que el país…» y «el país va antes que el canal…».

**TEL-4. País = el prefijo más largo de la lista con el que empiezan los dígitos**, sea cual sea el orden de la lista. Empezar por él, no contenerlo: caso «57 aparece dentro del número pero no al principio».
- Con una lista patológica (`['5','57']`) un número de México sale con país `5` y sin regla de canal. Es literal.
- Con la lista vacía, nada casa.

**TEL-5. Un número colombiano con un dígito de más o de menos pasa E.164 y cae en `not_allowed_for_channel`**, no en `invalid_format`. Casos: los «… un dígito de más/menos». Ver O-2.

**TEL-6. `last4`, `masked` y 60X.**
- `last4` son los 4 últimos caracteres del E.164.
- `masked` es `"+<país> ••• ••• <last4>"`: U+0020 entre los grupos y tres U+2022 en cada uno, para cualquier país. Generaliza el ejemplo del contrato.
- `60X` es literal: `600` y `609` valen para voz.

### Alias (`alias`)

- **AL-1. Se cuentan caracteres (puntos de código), no bytes.** Casos «40 caracteres con tildes (42 bytes)», «ñu…» y «ñúü…».
- **AL-2. `aliasError()` no recorta:** «sin recorte: un espacio delante cuenta». En HTTP, `TrimStrings` recorta antes. Ver Q-9.
- **AL-3. Un `\n` final no vale** (el `$` sin `D`, como en TEL-2).
- **AL-4. No se normaliza a NFC.** Una tilde combinada (U+0301) no está en la lista. Ver O-3.
- **AL-5 (revisada por la Enmienda 3, AS-1):** además de `\d{4,}` y `@`, el alias se rechaza si lleva un dato de contacto (`StudioCanaryPhone::contactDataError`: correo, o 8 o más dígitos con hasta 3 separadores que no sean letras ni dígitos entre ellos). «30 00 00 00 01» (10 dígitos en grupos) ya es inválido: O-1 queda cerrada. «solo signos admitidos, sin letras» sigue siendo válido.

### Huella de teléfono (`fingerprint`)

- **HU-1. La APP_KEY cruda.** Con `base64:` se decodifica el resto; sin el prefijo, son los bytes de la cadena (como S-1 del H5). `appKeyRawHex` es lo que recibe `withKey()`.
- **HU-2. `withKey()` valida igual que `of()`** (`InvalidArgumentException`) y no normaliza: «con espacios: la huella no normaliza».
- **HU-3. `of()` con la clave vacía** (`""` o `"base64:"`) lanza `RuntimeException`.

### Máquina de estados (`canaryTransitions`)

**CN-1. Entrada.** `facts` son los hechos que mira la ruta, ya calculados:
- `actor`: `{person, adminId, name, role, permissions}`;
- `number`: `{exists, channel, status, registeredByAdminId, registeredByName, registeredByRole}`;
- `clientRequest` ∈ `new`, `replay`, `reusedOtherBody`, `reusedOtherActor`;
- los recuentos y topes de cada ruta.

`expected` es la **primera** comprobación que rechaza, o el éxito con su `toStatus`.

**CN-2. Cuatro ojos por `adminId`.** El nombre y el rol de la instantánea no cuentan: «mismo nombre y otro id» y «el mismo id con otro rol en la instantánea». Activar exige ser distinto de quien **registró**, no de quien autorizó: «quien autorizó … activa».

**CN-3. `allowedFrom`:**
- `authorize` → `["pending"]`;
- `activate` → `["authorized"]`;
- `deactivate` → `["active"]`;
- `revoke` y `expectation` → `["pending","authorized","active"]`, en ese orden.

**CN-4. Los 403 son los del dominio** (como P-10 del H5). La puerta de `AuthorizationMap` puede responder antes con su propio cuerpo. **Enmienda 4 (`errorOrder`):** en las escrituras 47–56 el paso `permission` (403) va ANTES de `shape` (422), porque la llave de cada ruta la exige la puerta de `AuthorizationMap` antes de que el controlador valide la forma; una persona sin la llave y con un cuerpo mal formado recibe 403. El `required_permission` de `forbidden` es la primera llave que falta de `[ultron.view, la llave]` (`StudioAccess::require`).

**CN-5. Registrar.**
- Bandera → reintento → versión esperada → tope de vivos → INSERT.
- El número ya vivo en el canal con el canal en el tope da `canary_live_limit`: el tope va antes que el INSERT.
- La misma persona puede tener una fila viva por canal; una revocada no cuenta.

**CN-6. El orden de las demás rutas.**
- 50 y 51: la identidad va antes que el estado.
- 51: el estado va antes que el tope, y el tope es por canal.
- 54: el estado del número va antes que la versión.

**CN-7. Lo que se afirma del libro.**
- Las transiciones llevan `{event, from, to}`, y `diff` cuando no está vacío.
- `four_eyes_denied` lleva `diff: {action}` y `from`/`to` nulos.
- `expectation_set` lleva `from`/`to` nulos y `toStatus` sin cambio.
- `revoke` lleva `cipherErased = (channel === 'voice')`.
- La bandera apagada no deja evento (`audit: null`, §I8). En los demás rechazos no se afirma.

**CN-8. `actions`.** Precedencia del contrato: `forbidden` → `writes_disabled` → `invalid_transition` → `four_eyes_required` → `active_limit`.
- En `actions` el **estado** va antes que la identidad; en las rutas 50 y 51, la **identidad** va antes que el estado. Los dos órdenes son literales del contrato y el fixture los fija los dos. Ver «ante un autorizado que registró él» frente a los 403 de `authorize` y `activate`.
- Un `forbidden` va antes que los cuatro ojos.

### Coherencia (`coherence`)

**CO-1. «Sin teléfono»** es cualquiera de estas: la conversación no existe, `lead_id` nulo, el lead no existe, o `phone` nulo o vacío.

**CO-2. Normalización.**
- Se quitan todos los caracteres que no sean dígitos ASCII. Con 10 dígitos que empiezan por 3 se antepone 57, y después `+`.
- Si el resultado no es E.164, el teléfono es inservible. No se aplica la lista de países.
- Consecuencias literales: «9 dígitos que empiezan por 3…» da `+300000001`, y «un fijo de 10 dígitos (601…)» da `+6010000001`. Ninguno casa con el registro.

**CO-3. `isEngineCanary`.**
- Vale `true` solo en la fila WhatsApp **viva** con esa huella.
- Una fila revocada con la misma huella da `false`: «revocado y vuelto a registrar». Ver Q-6.
- Con el teléfono inservible, las filas WhatsApp dan `false` y las de voz `null`.

**CO-4. `activeWhatsapp`.** Se cuenta también con `no_engine_canary`. Con `failure` no se afirma (`null`). Ver Q-6.

**CO-5. `no_engine_canary` manda aunque la base esté caída**, porque no llega a leer. Una APP_KEY rotada deja la fila sin casar: «la fila se registró con otra APP_KEY».

**CO-6. `engineHash` y `normalizedE164` son internos.** Sirven para depurar el arnés y **nunca** salen en un DTO (§I4.5).

### Expectativa (`expectation`)

**EX-1. `none` va antes que `not_applicable`.** Sin versión esperada sale `none`, también con `source = none`: «la cadena no se puede leer y no hay versión esperada…» y «voz sin versión esperada». Ver Q-7.

**EX-2. La cadena solo se consulta para el WhatsApp que es el canario del motor:** «… la cadena ni se consulta». Con las dos partes nulas, `applied` se lee como `legacy`.

**EX-3. `expectedVersionStale`.**
- Es `null` sin versión esperada.
- Si hay versión: es `true` si su estado no es asociable o no es íntegra.
- Es independiente de `expectation`: se puede tener `match` con `stale: true`.

### Asociaciones de campaña (`campaignLinkRules`)

**CL-1. Referencias simbólicas.**
- `activeLink` es `"L1"` o `null`.
- `replaces` es una de:
  - `L1`, la activa;
  - `L0`, una inactiva de esta campaña;
  - `LX`, la activa de otra campaña;
  - `L404`, un uuid que no existe.
- `activeTotal` cuenta las activas de **todas** las campañas.

**CL-2. Sin activa y con `replaces` → `campaign_link_moved` con `current: null`.**

**CL-3. El tope no cuenta la que se sustituye**, y solo si `replaces` es la activa.

**CL-4. `meta_not_configured` es solo `configurationProblem() ≠ null`.** La sincronización apagada no impide asociar: «la sincronización apagada no es «sin configurar»».

**CL-5. Al sustituir.** La sustituida queda `inactive` con `deactivationReason = "Sustituida por otra asociación"` y `replacedBy = "new"` (la nueva). `audit` es el conjunto `{created, deactivated}`.

**CL-6. Desactivar no lleva cuatro ojos.** Quien la creó también puede desactivarla.

### Meta Ads (`metaClassification`, `providerClassification`)

**ME-1. `state` es el `status` de `statusAll()`; con un problema de configuración, `not_configured`.**
- `sync_disabled` manda sobre `stale` y `failed`, pero no sobre un problema de configuración.
- `state` y `reason` son también el `connection.*` de la ruta 40.

**ME-2. `possiblyDeleted`.**
- Se compara con la **última por `id`** de las pasadas `insights` de la cuenta con `ok`, sin contar los rellenos.
- Es **anterior estricto**, y se comparan **instantes**, no textos: «instantes con zonas distintas…».
- Sin ninguna pasada que cumpla, solo `synced_at` nulo da `true`.
- `referenceRunId` es informativo.

**ME-3. Orden.** Primero `ACTIVE`; después el nombre en minúsculas (ASCII); después `entityId` como texto, todos de la misma longitud.

**PC-1. Twilio (ruta 39).**
- Las reglas van en el orden del contrato, y la primera manda: driver → Verify → interruptor → techo.
- El techo es `≥`, y el umbral blando no degrada.
- `state` de Twilio no se afirma: el contrato no lo define.

**PC-2. Twilio Voice.** `from` E.164 estricto; uno con espacios cuenta como no configurado.

### Configuración (`configFacts`, `configDrift`)

**CF-1. Entrada: `config` con las claves de `config()` tal cual las lee la clase.** Incluye `ultron_studio.integrations.twilio_aliases` ya calculado, como lo deja la caché (§I12).

**CF-2. Presente = `filled()`.** Las huellas de voz (`apiKey`, `webhookSecret`, `toolSecret`) son del secreto **recortado**, el valor efectivo que se envía (E1.2, decisión 6 de V; caso «las huellas de voz son del secreto recortado»). En Twilio y Meta no hay ningún caso con espacios.
- Un valor ausente o en blanco es `{"present": false}` y nada más: sin `source`, `ref` ni `fp`. Ver Q-10.
- Presente:
  - `ref` son los 2 primeros caracteres, `…` (U+2026) y los 4 últimos;
  - `agentId.ref` son los 4 últimos y nada más;
  - `from.last4` son los 4 últimos;
  - `phoneNumberId` solo lleva `present`;
  - `accounts[].ref` es `maskId` (`***` + 4).

**CF-3. Hosts.** Se toma el host de la URL, así que `user@host` da el host de después de la arroba, y es oficial si es **exactamente** `api.twilio.com` o `verify.twilio.com`. Casos: `api.twilio.com.evil.test` y `verify.twilio.com@evil.test`. El esquema `http`, las mayúsculas y el puerto no se cubren.

**CF-4. Importes: texto con dos decimales** (`number_format($v, 2, '.', '')`: `"6.00"`, `"7.50"`, `"15.25"`). Es la Enmienda 1, Q-1: `canonicalize` rechaza los decimales, y el cruce de PHP lo demuestra.

**CF-5. `probe.credential = restricted_key` si `key_sid` y `key_secret` están los dos; si no, `otp`. `region` de voz va tal cual en la configuración**, aunque no sea válida.

**CF-6. `fingerprint = sha256(StudioContentHasher::canonicalJson(facts))`.** El fixture trae los bytes (`canonicalJson`) para ver dónde diverge una implementación.

**DR-1. Estado y deriva.**
- Sin reconocimiento: `never_authorized` y `drift: []`.
- `state` se decide por las huellas.
- `drift` sigue el orden de las claves de `facts` de su proveedor.
- Los valores se comparan canónicamente: el orden de las claves no cuenta en ningún nivel, como lo devolvería un `jsonb`, y el de las listas sí.
- Una clave anidada que cambia arrastra toda su clave de primer nivel.

### Firma de ElevenLabs (`elevenlabsSignature`, `elevenlabsEnvelope`)

**FI-1. El secreto vacío se mira primero:** lanza aunque la cabecera sea nula.

**FI-2. «Recortar» es el `trim` de PHP** (`" \t\n\r\0\x0B"`).
- El tabulador se quita; el U+00A0 no, y la parte queda con una clave desconocida.
- En TS no vale `String.prototype.trim`, que sí quita U+00A0.

**FI-3. Tras partir por el primer `=`, ni la clave ni el valor se recortan.** Las claves distinguen mayúsculas: `t = …`, `T=` y `V0=` dan `malformed_signature`.

**FI-4. Estricto** (E1.2 congela el `v0` mal formado y las mayúsculas en las claves). Una parte sin `=` es `malformed_signature`, aunque `t` y `v0` estén bien. Además:
- hay exactamente un `t`;
- hay uno o más `v0`;
- **toda** parte `t` o `v0` tiene que tener su forma: un `v0` bueno y otro mal formado dan `malformed_signature`. Ver Q-5.

**FI-5. Se firma el texto de `t` tal cual llega**, con sus ceros a la izquierda: «t con ceros…» (los dos casos).

**FI-6. Bordes y orden.**
- `t = now − 1800` y `t = now + 300` todavía valen.
- El tiempo va antes que la firma.
- `now` = 1793545200 (01-11-2026 15:00:00 UTC).

**EN-1. `type()`.** Casa exacto, sin recortar ni pasar a minúsculas. Un BOM, el cuerpo vacío, `null` o una lista dan `null`.

### Voz (`voiceDoctor`, `voiceVerify`, `voiceCallPolicy`)

**VZ-1. `provider` vale `elevenlabs` solo con `ELEVENLABS_UNVERIFIED`**, que es cuando `VoiceProviders::current()` devuelve el adaptador; si no, `none`. Lo congela E1.2 (decisión 1 de V).

**VZ-2. Región** (E1.2, decisión 2 de V): `null` → `default`. Se normaliza con `strtolower(trim())`, así que `EU` y ` eu ` valen. Vacía o fuera de la lista entra en `missing`. Casos «región EU…», «región « eu »…», «región nula…» y «región vacía».

**VZ-3. `api_key` y `webhook_secret` cuentan con `filled()`.** `agent_id`, con el patrón anclado de verdad: un `\n` final no vale.

**VZ-4. `baseUrl()` es `null` sin región válida**, aunque haya `base_url` fuera de producción. Sigue siendo decisión de este fichero (Q-8): E1.2 no la toca y esta sección no se ha cruzado con la clase real.

**VZ-5. `ELEVENLABS_VERIFIED` (Studio).** La **última** sonda de voz tiene que ser `ok` y de menos de 30 días, estricto (2 592 000 s). Una `ok` anterior no cuenta si la última falló. Ver Q-4.

**VV-1. `verifyCredentials()`.**
- Un 200 cuyo cuerpo no es un **objeto** JSON (HTML, una lista, `null`) es `error` (E1.2). Un 3xx es `error`: no se siguen redirecciones.
- `agentAudioUlaw8000` es `true` si vienen los dos formatos y los dos son `ulaw_8000`, `false` si vienen los dos y no lo son, y `null` en otro caso.
- `snapshotOutcome` es la correspondencia de §I5.3.

**PO-1. Niveles de la ruta 45.**
- `levels` es la lista de §I6.6.
- `integration_test` excluye solo `not_an_active_voice_canary` y `daily_cap_zero`.
- `available` es `false` mientras haya motivos.

**PO-2. El contexto `canary` solo mira filas de voz.**

### Sonda de Twilio (`twilioProbeSanitize`)

E1.4 congela SO-1, SO-2, SO-8 y `last4`. Las demás son las D-10 del paquete TW (`tests/Unit/Twilio/fixtures/README.md`), adoptadas porque fallan cerradas. E1.4 no nombra tres de ellas: la ficha sin `sid`, el recorte de la página y los sobres raros. **Hay que confirmarlas** (Q-12).

**SO-1. Las 7 claves de `facts` salen siempre.** Un paso que falla o se salta deja su sección en `null` y una entrada en `errors`, en el orden de `STEPS` y con sus nombres (`caller_ids`). El ejemplo del contrato con `alerts24h` lleno y un error de `alerts` es ilustrativo.

**SO-2. Los pasos no ejecutados.** Los que no se ejecutan tras un fallo de la cuenta llegan como `{skipped: true}`, porque el servicio los pasa así, y salen como `skipped`. Un paso que falta en la entrada cuenta igual.

**SO-3. `outcome`.** Con la cuenta saltada, `error`. Con la cuenta en `error` (500) y el resto bien, `error`.

**SO-4. Cuerpo inesperado = `error` del paso, con el estado que vino, no cero elementos** (D-10.6). Lo es cualquiera de estos:
- un cuerpo que no es un objeto, o ninguno;
- la ficha de la cuenta sin un `sid` que case con `^AC[0-9a-f]{32}$` (un error de Twilio con 200);
- una lista que falta o que no es una lista;
- una entrada que no es un objeto. No hay recuentos a medias.

**SO-5. `truncated` = la página vino llena** (≥ 20, 20, 50, 50). Con más filas de las pedidas, se recorta a la página (D-10.7). `next_page_uri` se ignora.

**SO-6. Capacidades:** solo `=== true` cuenta (`"true"` no).

**SO-7. Cuenta.** `status` y `type` salen de sus conjuntos (`active|suspended|closed`, `Trial|Full`); si no, `null`. Una cuenta suspendida no cambia el `outcome`.

**SO-8. Claves de los recuentos.**
- `byStatus` usa los 8 estados de llamada de Twilio.
- `byLevel` usa `error|warning|notice|debug`.
- `byCode` usa de 1 a 6 cifras sin cero inicial (`^[1-9][0-9]{0,5}$` con `D`, E1.4): un teléfono no cabe y `"0"` no es clave. Un `error_code` entero se lee como texto (D-10.3).
- Lo demás va a `"other"`, y las claves se ordenan por bytes.
- Un mapa vacío se escribe `[]` (E1.1).

**SO-9. `last4` solo sale de un E.164 estricto (E1.4)**, que es el de I4.3: `^\+[1-9][0-9]{7,14}$` con `D`. Con espacios, sin `+`, con un `\n` final o como número da `null`. Ver **DT-1**.

**SO-10. Un sobre de fallo raro** (D-10, caso C16 de TW) cuenta como `error` con `httpStatus: null`. Lo es una etiqueta fuera de `auth_failed|unreachable|error`, o un estado que no es un entero de 100 a 599.

### Twilio, vector oficial (`twilioSignatureOfficial`)

**TW-1. Los vectores oficiales.** Son de `twilio/twilio-python`, `tests/unit/test_request_validator.py`, rama `main`, descargado el 31-10-2026 (sha256 del fichero `4522667e1aef9fcc…`). Recalculados, dan exactamente la firma publicada:
- el de formulario;
- el JSON con `bodySHA256`;
- el de claves repetidas: los valores se ordenan y no se repiten;
- tres con puerto. El SDK prueba la URL con y sin puerto.

**TW-2. El vector derivado (T-1)** es la firma oficial contra la URL en `http`, que es lo que ve `fullUrl()` tras un proxy TLS sin `trustProxies`. No vale.

### Sonda y reconocimiento (`probeRules`, `acknowledgeRules`)

**PR-1. Orden de la ruta 56**, el de §I5.4:
1. persona → `view` → llave;
2. la bandera de la sonda;
3. el reintento;
4. la configuración;
5. el enfriamiento;
6. la red.

La sonda **no** depende de la bandera de escrituras. Una `clientRequestId` de otra persona da `client_request_reused`. Ver Q-11.

**PR-2. Un SID presente que no casa con `^AC[0-9a-f]{32}$` es `missing_account_sid`**, no un 5xx. Ver Q-11. La credencial es `restricted_key` con las dos piezas de la llave; si no, el token del OTP; si no, `missing_credential`.

**PR-3. Enfriamiento estricto:** con 299 s se reutiliza; con 300 s se vuelve a lanzar. Reutilizar no hace red ni deja evento.

**AK-1. Ruta 55.** No depende de la bandera de escrituras. El reintento va antes que `config_changed`. De `config_changed` se afirman `provider` y `effectiveFingerprint` (`payloadIncludes`); la deriva, en `configDrift`.

### Rutas y errores (`routeMatrix`, `errorOrder`, `closedSets`)

**RU-1. `allowed`** = la puerta y el dominio dejan pasar (2xx o el 409 de su estado).
- Las 38 rutas anteriores: 401, 403 y 403 para sin sesión, token compartido y roles del gimnasio; `unchanged` para Administrador y Super Admin.
- `noView`: una llave de escritura sin `ultron.view` da 403 `forbidden {ultron.view}` antes de buscar el recurso, también con un uuid que no existe.

**EO-1. `errorOrder` transcribe el orden de cada ruta.**
- Empieza siempre igual: sesión 401 → puerta 403 → `person_required` → `forbidden` (`view`) → forma 422.
- El 23505 se resuelve con el mismo código que la comprobación previa.

**CS-1. `closedSets`.**
- Transcribe §I2, los 4 permisos con su etiqueta y ayuda literales (§I7.1) y la tabla de errores (§I7.3), con las rutas de los reutilizados.
- También las constantes, y las etiquetas de `StudioVersionStatus::label()` (Enmienda 1, Q-2).
- Y los conjuntos que este fichero decide: los estados, niveles y códigos de Twilio (SO-7, SO-8) y `configFactsKeys` (§I5.3).

### Discrepancia abierta con una clase ya confirmada

**DT-1 · `TwilioProbeSanitizer` y el E.164 de `last4`.**
- La clase (D-10.4 de TW) acepta `^\+[1-9]\d{6,14}$`: **7** a 15 cifras.
- El contrato define E.164 una sola vez (I4.3: `^\+[1-9]\d{7,14}$`, 8 a 15 cifras). Lo usan `StudioCanaryPhone`, `PhoneFingerprint`, la coherencia y `voice.twilio.from`, y E1.4 remite a «un E.164 estricto».
- El caso «last4 solo de un E.164 estricto…» fija `+1234567` → `null` y `+12345678` → `5678`. La clase da `4567` para el primero.
- **Se corrige la clase** (`{6,14}` → `{7,14}`), salvo que el lead enmiende E1.4 con otro patrón. En la práctica no afecta a ningún número de Twilio, pero el fixture no se dobla.

### Lo que E1 deja como está en el fixture

- **D-1 (E1.3).** Al sustituir, la asociación sustituida se anota una sola vez (`replacedBy`). Es CL-5, y una sustituida ya no se puede volver a desactivar (`invalid_transition`) ni a sustituir (`campaign_link_moved`).
- **D-2 (E1.3).** Ningún `outcome` de la sonda es nulo: todos los casos de `twilioProbeSanitize` traen uno.
- **E1.5.** Con `applied` y las dos partes vacías, el **servidor** escribe `{source:'publication_chain', state:'legacy'}`, la forma de I4.6, sin `part`. El CRM tolera además `part: null`; eso es del CRM, no de este fichero.

## Lo que no cubre, y por qué

- **La validación 422 de Laravel.** Incluye `uuid`, `max:32`, `PlainText`, `boolean:strict`, `accepted`, los `regex` de forma, `page` y `per_page`, y los 64 KB. Es de las Feature.
- **La base de datos.** No cubre disparadores, CHECK, índices parciales ni SQLSTATE, ni las carreras con barrera, que son la puerta de PG (`ops/pgunit.sh`). Tampoco el cifrado del teléfono de voz ni su borrado al revocar en la base. Eso va en las pruebas de PG y de mutación del tester (paso 4).
- **Lo HTTP de `TwilioReadOnlyClient`.** URLs, `PageSize`, hosts fijos en producción, `next_page_uri` sin seguir y el SID en la ruta van con `Http::fake`, en el paquete TW.
- **Los DTO completos.** `CampaignLinkDto`, `CanaryNumberDto`, la ruta 43 y la ruta 39 completa, con `MetaAdEntity::opaqueId` y `maskDigits` de los nombres (código existente). Los exporta el backend con sus serializadores reales (§I10).
- **Los comandos.** `ultron-studio:integrations-snapshot` (inserta solo si la huella cambia), `integrations-doctor --expect` y `voice:doctor --verify`.
- **Bordes evitados a propósito**, porque no había base para decidirlos:
  - nombres de campaña nulos o con tildes en el orden;
  - `entity_id` de longitudes distintas;
  - `started_at` nulo en una pasada con éxito;
  - importes con tres decimales (`6.005`);
  - el esquema `http`, las mayúsculas y el puerto en los hosts de Twilio;
  - `phone_number` con menos de 4 dígitos (no es E.164: `null`);
  - un alias de solo espacios;
  - un canal fuera de `whatsapp|voice` en `parse()`.

## Preguntas para el lead

Q-1 y Q-2 están decididas en E1.1. **Q-3 queda resuelta en E1.2** (VZ-1). Q-5, Q-8 y Q-12 quedan resueltas en parte, y lo que falta se dice en cada una. Quedan estas:

- **Q-3 (VZ-1).** Resuelta (E1.2): `provider` es el id de `current()`.
- **Q-4 (VZ-5).** `ELEVENLABS_VERIFIED`: ¿la **última** sonda `ok` de menos de 30 días (lo que fija el fixture) o **cualquier** `ok` de los últimos 30 días? La segunda deja «verificado» después de revocar la clave.
- **Q-5 (FI-2 a FI-4).** E1.2 congela el `v0` mal formado y las mayúsculas de las claves. Faltan el `trim` de PHP y no recortar clave ni valor. La clase real coincide en los 52 casos, así que basta con que el lead lo dé por congelado.
- **Q-6 (CO-3, CO-4).** `isEngineCanary` de una fila revocada con la huella del motor: el fixture dice `false`. `activeWhatsapp` con `unreadable`: el fixture no lo afirma.
- **Q-7 (EX-1).** Sin versión esperada y con `source = none`: el fixture dice `none`; la alternativa es `not_applicable`.
- **Q-8 (VZ-4).** La región quedó resuelta (E1.2). Falta `baseUrl()` con una región inválida y `base_url` fuera de producción: el fixture dice `null`.
- **Q-9 (AL-2).** ¿`aliasError()` recorta? El fixture dice que no; `TrimStrings` ya recorta en HTTP.
- **Q-10 (CF-2, CF-3, CF-5).** En `facts`:
  - un valor ausente es solo `{present:false}`;
  - el host se compara exacto;
  - la región de voz va tal cual.
- **Q-11 (PR-1, PR-2).** En la ruta 56: el SID mal formado da `missing_account_sid`, y el reintento de otra persona da `client_request_reused`.
- **Q-12 (SO-4, SO-5, SO-10).** Confirmar las tres convenciones D-10 que E1.4 no nombra: la ficha sin `sid` válido, el recorte de la página y los sobres raros. Las cumplen el fixture, los casos de TW y la clase.
- **Q-13 (DT-1).** ¿`last4` usa el E.164 del contrato (8 a 15 cifras, lo que fija el fixture) o el de la clase de TW (7 a 15)?

## Observaciones para otros dueños (no cambian el fixture)

- **O-1 (security).** `\d{4,}` no detecta un teléfono escrito en grupos de 2 o 3 dígitos (`30 00 00 00 01`). Es la regla literal del contrato.
- **O-2 (S y FE).** `not_allowed_for_channel` sale también con un número colombiano de longitud equivocada y en voz. El texto fijo «Ese número no sirve para este canal (WhatsApp exige un móvil).» confunde en esos dos casos.
- **O-3 (FE).** El macOS puede mandar las tildes descompuestas (NFD), y el alias las rechaza (AL-4). Conviene `normalize('NFC')` en el formulario antes de enviar.
- **O-4 (FE y S).** PHP escribe `[]` para un mapa vacío. El CRM tiene que aceptar `[]` y `{}` en `byStatus`, `byCode`, `byLevel`, y en `calls.byStatus` y `calls.byDirection` de la ruta 43.
- **O-5 (T-2, sin comprobar).** El validador privado de `usage-trigger` concatena los valores repetidos sin ordenarlos ni quitar repetidos. PHP, además, se queda con el último valor de una clave repetida. No reproduciría el vector `duplicateKeys`. Solo importa el día que se extraiga.
- **O-6 (V y TW).** Sus WIP (`7241ad8` y `b647a8e`) traen fixtures propios. **No los leí.** Tienen que pasar también contra este oráculo.
