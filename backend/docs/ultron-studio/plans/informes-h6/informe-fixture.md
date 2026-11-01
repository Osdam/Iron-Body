# Hito 6 · paso 1b · informe del tester: el fixture de integraciones

**Fecha:** 31-10-2026 (Bogotá); puesto al día el 01-11 con la Enmienda 1 (§0 bis).
**Árbol:** `_worktrees/ultron-h6/Iron-Body/backend`, rama `feat/ultron-studio-h6`, sobre `7d69b77`. Es el WIP del lead con mis 142 casos parciales, recuperados del scratchpad.
**Contrato:** `ULTRON_STUDIO_H6_CONTRACT.md` §I2–I7 y §I11, más la Enmienda 1: Q-1, importes como texto, y Q-2, etiquetas de `StudioVersionStatus::label()`.
**Sin commit:** los cambios quedan en el árbol de trabajo, sin tocar el índice.
**Producción:** nada tocado.

## 0. Resumen

- **Entregado.**
  - `tests/fixtures/ultron-studio/integrations-cases.json`: 719 casos, 756 425 bytes, sha256 `fce35ec5…18d5ef`.
  - Su `integrations-cases.README.md`.
  - El generador `tests/fixtures/ultron-studio/tools/integrations-cases/`: `build.py` y 14 `sec_*.py` con los casos escritos a mano.
  - El comprobador independiente `tests/fixtures/ultron-studio/tools/integrations-cases-check.py`.
  - Este informe.
- **Recuento.**
  - `phone` 88, `alias` 36 y `fingerprint` 18.
  - `canaryTransitions` 121, `coherence` 34 (los 7 veredictos), `expectation` 24 y `campaignLinkRules` 64.
  - `metaClassification` 39 y `providerClassification` 21.
  - `configFacts` 11 y `configDrift` 8.
  - `elevenlabsSignature` 52 y `elevenlabsEnvelope` 13.
  - `voiceDoctor` 38, `voiceVerify` 15 y `voiceCallPolicy` 24.
  - `twilioProbeSanitize` 28 y `twilioSignatureOfficial` 7.
  - `probeRules` 22 y `acknowledgeRules` 9.
  - `routeMatrix` 29 y `errorOrder` 18.

  Todos los mínimos de I11 quedan superados. Hay 9 secciones extra que I11 no pedía y el lead sí: la matriz de rutas, el orden de errores, la sonda, el reconocimiento, la deriva, más la clasificación de la ruta 39, el sobre del webhook y la verificación de voz. Además van los `closedSets` (§I2, permisos, errores y constantes).
- **Cómo se escribió.** Todo `expected` es un literal escrito a mano desde el contrato. Python solo calcula HMAC y sha256 sobre datos sintéticos y serializa. No leí ni ejecuté el código del hito que se escribía en paralelo (§5).
- **Verificación** (§2):
  - una segunda derivación independiente, con toda la criptografía por openssl, rehace los 701 casos con reglas: **0 discrepancias**;
  - **115 de 115** implementaciones equivocadas simuladas caen;
  - la misma derivación rehace los 17 casos confirmados de TW sin ninguna discrepancia;
  - las clases confirmadas dan `ElevenLabsWebhookSignature` 52/52, `ElevenLabsWebhookEnvelope` 13/13 y `TwilioProbeSanitizer` 27/28. El que falta es **DT-1** (§0 bis);
  - el cruce con las primitivas de PHP 8.3 y el `StudioContentHasher` del árbol da **0 fallos**;
  - con tres `expected` corrompidos, el comprobador falla en los tres;
  - el generador es reproducible byte a byte.
- **Decidido aquí.** Lo que el contrato no fijaba va en el README, por sección (TEL, AL, HU, CN, CO, EX, CL, ME, PC, CF, DR, FI, EN, VZ, VV, PO, SO, TW, PR, AK, RU, EO y CS), cada punto con sus casos. **Hay que congelarlo** en la Enmienda 1. Hay diez preguntas nuevas (Q-3 a Q-12, §3).

## 0 bis. Tras la Enmienda 1 (01-11-2026)

**Cambios en el fixture**, porque E1 manda sobre mis decisiones previas:

| Decisión de E1 | Antes en el fixture | Ahora |
|---|---|---|
| E1.2, decisión 2 de V: región `null` → `default`, `strtolower(trim())` | `EU`, ` eu` y `null` en `missing` | valen; nuevo caso «región vacía» → `missing`; 2 casos de `baseUrl` |
| E1.2: un 200 que no es un objeto JSON es `error` | `credentials_ok` | `error`; casos nuevos: lista JSON, `null` y 302 |
| E1.2, decisión 6 de V: huellas del secreto recortado | — | caso nuevo de `configFacts` (voz) con espacios, `\n` y tabulador: las mismas huellas |
| E1.4: `byCode` de 1 a 6 cifras sin cero inicial | 5 cifras | `^[1-9][0-9]{0,5}$` con `D`; un entero se lee como texto |
| E1.4: `last4` solo de un E.164 estricto | de los dígitos | del E.164 de I4.3; caso nuevo con 7 formatos |
| D-10 de TW, que E1.4 no nombra (la ficha sin `sid`, una entrada que no es objeto, el recorte de la página, los sobres raros) | sin cubrir | 6 casos nuevos (ver Q-12) |

Cada cambio lleva su variante equivocada en el comprobador:
- región sin normalizar;
- 200 no objeto como `ok`;
- huella sin recortar;
- `last4` de los dígitos;
- `last4` con 7 cifras;
- códigos de 5 cifras;
- cuenta sin `sid` aceptada;
- entradas que no son objeto saltadas;
- sin recorte;
- sobre sin higiene.

**Q-1, Q-2, Q-3 y PCRE** quedan congeladas por E1.1 y E1.2. **Q-5, Q-8 y Q-12** quedan resueltas en parte.

**Cruce con las clases confirmadas** (`--impl`, salida real):

```
cruce con los fixtures de TW: 17 casos · discrepancias: 0
clase real · twilioProbeSanitize: 27/28 idénticos, 1 DISCREPAN
  DISCREPA twilioProbeSanitize · sanitize · last4 solo de un E.164 estricto (^\+[1-9][0-9]{7,14}$ con D, el de I4.3)
    la clase da: … {"last4": "4567", …}   ← para "+1234567"
clase real · elevenlabsSignature: 52/52 idénticos
clase real · elevenlabsEnvelope: 13/13 idénticos
```

**DT-1 (bloquea la paridad de TW, no el fixture).**
- `TwilioProbeSanitizer` usa `^\+[1-9]\d{6,14}$` para `last4`, de 7 a 15 cifras (D-10.4 de su README).
- El contrato define E.164 una sola vez (I4.3: `\d{7,14}`, de 8 a 15) y E1.4 remite a «un E.164 estricto».
- No he doblado el fixture. **O lo corrige TW** (`{6,14}` → `{7,14}`, una línea), **o el lead enmienda E1.4** con el otro patrón y regenero el caso.

**E1.3 y E1.5** no cambian nada del fixture. Las notas van en el README («Lo que E1 deja como está»).

## 1. Qué se entrega y dónde

| Fichero | Qué |
|---|---|
| `tests/fixtures/ultron-studio/integrations-cases.json` | El oráculo |
| `tests/fixtures/ultron-studio/integrations-cases.README.md` | Forma, comparación, decisiones con sus casos, lo que no cubre, preguntas y observaciones |
| `tests/fixtures/ultron-studio/tools/integrations-cases/build.py` + `sec_*.py` | Los casos a mano y la serialización: `python3 -I build.py` reescribe el fixture |
| `tests/fixtures/ultron-studio/tools/integrations-cases-check.py` | La segunda derivación, las variantes y `--php` |
| `docs/ultron-studio/plans/informes-h6/informe-fixture.md` | Este informe |

**Cambios sobre el WIP del lead.** Moví `build.py` y los tres `sec_*.py` de `tools/h6-fixture-parcial/` a `tools/integrations-cases/`, y borré `integrations-cases.PARTIAL.json`: lo sustituye el fixture final, que contiene sus 142 casos sin cambios. Los movimientos son de sistema de ficheros: git los ve como borrados más ficheros nuevos.

**Que no lo ejecute la suite.** PHPUnit solo recorre `tests/Unit` y `tests/Feature`. Las pruebas que recorren directorios (aislamiento, exportación) miran ficheros `.php` de `app/` y `routes/`, o los `.ts` del CRM. Aquí solo hay `.json`, `.md` y `.py`.

## 2. Verificación (salida real)

### 2.1 JSON, recuentos y estructura

```
$ python3 -I -c 'import json,sys; json.load(open(sys.argv[1])); print("JSON válido")' tests/fixtures/ultron-studio/integrations-cases.json
JSON válido
$ python3 -I tests/fixtures/ultron-studio/tools/integrations-cases-check.py --php --tw          # 01-11, tras la Enmienda 1
fixture: integrations-cases.json · 756425 bytes · openssl: OpenSSL 3.6.4 25 Aug 2026
estructura: OK
casos: {"phone": 88, "alias": 36, "fingerprint": 18, "canaryTransitions": 121, "coherence": 34, "expectation": 24,
        "campaignLinkRules": 64, "metaClassification": 39, "providerClassification": 21, "configFacts": 11, "configDrift": 8,
        "elevenlabsSignature": 52, "elevenlabsEnvelope": 13, "voiceDoctor": 38, "voiceVerify": 15, "voiceCallPolicy": 24,
        "twilioProbeSanitize": 28, "twilioSignatureOfficial": 7, "probeRules": 22, "acknowledgeRules": 9, "routeMatrix": 29,
        "errorOrder": 18} total 719
derivación independiente: 701 casos rehechos · discrepancias: 0 (errorOrder, 18 rutas, se cruza en la estructura)
variantes equivocadas simuladas: 115   (las 115 «cazada»)
{"phoneFp":9,"engineHash":21,"configFacts":11,"configDrift":8,"elevenlabs":22,"twilio":7,"trampaDolarSinD":1,"trampaDolarConD":0,
 "trampaUnicodeConU":1,"trampaUnicodeSinU":0,"canonicalJsonConDecimal":"InvalidArgumentException","trimDePhpQuitaNbsp":"no"}
fallos PHP: 0
cruce con los fixtures de TW: 17 casos · discrepancias: 0
RESULTADO: OK                                              (sale con 0; con --impl sale con 1 por DT-1, §0 bis)
```

«Estructura» comprueba lo siguiente:
- los recuentos que declara el README;
- los mínimos de I11, los 7 veredictos de `coherence` y las 6 filas de I4.4;
- nombres únicos con su prefijo, ningún `{}`, ningún carácter invisible literal y el salto de línea final;
- que cada código de `errorOrder` está declarado para esa ruta en `closedSets.errorCodes`, y al revés;
- que cada código de las secciones de casos está en el orden de su ruta;
- que cada centinela de `mustNotContain` existe de verdad en alguna entrada.

### 2.2 El comprobador no se dobla: tres corrupciones a propósito

En una copia del fixture se cambiaron tres `expected`: el veredicto de un `in_sync`, el borde `now − 1800` a `expired` y una huella de `configFacts`.

```
  DISCREPANCIA coherence · read · 10 dígitos que empiezan por 3: se antepone 57
  DISCREPANCIA configFacts · twilio · producción al congelar: los dos alias, Verify, remitente, hosts oficiales y sonda apagada
  DISCREPANCIA elevenlabsSignature · verify · en el límite: t = now − 1800 todavía vale
derivación independiente: 665 casos rehechos · discrepancias: 3
RESULTADO: FALLA
```

La copia es de antes de `providerClassification` y de la Enmienda 1, de ahí los 665 casos rehechos.

**Reproducible.** Regenerar da el mismo sha256.

**El comprobador cazó dos defectos del propio fixture**, y los corregí antes de esta salida:
1. Un centinela de la sonda (`+573000000099`) no aparecía literal en ninguna entrada, así que no probaba nada.
2. El caso «claves en otro orden» de `configDrift` solo reordenaba el primer nivel, y la variante «comparar sin canonicalizar» no caía. Ahora el caso reordena todos los niveles, como un `jsonb`.

### 2.3 Variantes equivocadas que el fixture caza (115)

Cada variante es un error plausible; entre paréntesis, los casos que la cazan.

| Área | Variantes |
|---|---|
| Teléfono | quitar todo `\s` (6), `$` sin `D` (1), `\d` Unicode (2), el primer prefijo en vez del más largo (2), «contiene» en vez de «empieza» (2), regla de canal en cualquier país (7), WhatsApp admite fijos (3), país antes que formato (8), máscara sin espacios (33) |
| Alias | bytes (3), recortar (2), `$` sin `D` (1), serie de 5 (4) |
| Huella | APP_KEY sin decodificar (27), subclave al revés (30), sin subclave (30), etiqueta del H5 (30), sin el `+` (30), sin validar (7), `$` sin `D` (1) |
| Canarios | sin `view` (2), integridad antes que estado (2), identidad por nombre (1), sin cuatro ojos (8), activar con `canary_authorize` (5), reintento antes que la bandera (1), existe antes que el tope (1), tope antes que la versión (1), estado antes que identidad (3), `>` en vez de `≥` (3), `cipherErased` siempre (5), y en `actions` identidad antes que estado (3) y tope antes que identidad (1) |
| Coherencia y expectativa | sin anteponer 57 (15), `in_sync` por defecto (10), revocadas cuentan (2), la voz cuenta como extra (1), el resto antes que el canario (6), la cadena para quien no es el canario (2), `not_applicable` antes que `none` (2), `stale` sin integridad (1) |
| Campañas y Meta | sincronización apagada = sin configurar (1), Meta antes que el reintento (1), integridad antes que estado (1), `already_linked` sin activa (2), el tope cuenta la sustituida (2), el problema después de la sincronización (4), `sync_disabled` solo con `ok` (3), cualquier estado de pasada (2), los rellenos cuentan (3), comparar textos de fecha (2), `≤` (2), orden con mayúsculas (1), techo con `>` (1), interruptor y techo al revés (1), `from` con espacios (1), los recuentos degradan (2) |
| Configuración | huella de voz sin recortar (1), APP_KEY sin decodificar (5), escapar Unicode (3), ausente con `source` (6), host «contiene» (1), importes en coma flotante (4), llave restringida a medias (1), deriva sin canonicalizar (1) |
| ElevenLabs | secreto vacío aceptado (2), `trim` Unicode (1), parte sin `=` ignorada (1), recortar clave y valor (2), claves sin mayúsculas (2), `v0` laxo (1), `t` como entero (2), sin el prefijo `t.` (15), `v0` con mayúsculas (1), firma antes que el tiempo (2), `≤` al caducar (1), sin el futuro (2), y en `type()`, minúsculas (1) y recorte (1) |
| Voz | región sin normalizar (5; antes de E1.2 era la contraria), 200 que no es objeto como `ok` (3), clave en blanco presente (1), `$` en `agent_id` (1), `provider` siempre `elevenlabs` (19), `base_url` en producción (2), cualquier `ok` en 30 días (1), `≤` 30 días (1), un formato basta (2), el contexto cuenta WhatsApp (1), `VERIFIED` añade motivo (13), solo el cupo negativo (3), solo el primer motivo (15), la prueba de integración excluye la fuente (4) |
| Sonda y Twilio | `last4` de los dígitos (1), `last4` con 7 cifras (1, el de DT-1), códigos de 5 cifras (1), cuenta sin `sid` aceptada (1), entradas que no son objeto saltadas (1), sin recorte de página (1), sobre sin higiene (1), lista ausente = 0 (1), `>` en `truncated` (3), capacidades «verdaderas» (1), `friendly_name` conservado (15), estados desconocidos conservados (1), `degraded` con la cuenta en error (1), sin ordenar parámetros (5), repetidos concatenados (1), la sonda exige la bandera de escrituras (1), reintento antes que su bandera (1), `≤` en el enfriamiento (1), enfriamiento antes que la configuración (1), el reconocimiento exige escrituras (1), `config_changed` antes que el reintento (1) |
| Matriz | el Administrador con las 4 llaves (3) |

### 2.4 Las primitivas de PHP (`--php`)

El script temporal carga **solo** `app/Services/UltronStudio/StudioContentHasher.php`, del H5 y sin tocar.
- `canonicalJson`, alimentado con `facts` tal cual, da los bytes de los 10 `configFacts`.
- `hash` da sus huellas y las 8 de `configDrift`, con la reconocida.
- `number_format` da los importes.
- `hash_hmac` da las huellas de token, de teléfono y de `engineHash`, las firmas de ElevenLabs que deciden y los vectores de Twilio.

También deja la evidencia de cuatro decisiones:
- en este PCRE2, `$` sin `D` acepta `\n` (1) y con `D` no (0);
- `/u` acepta `３` (1) y sin `/u` no (0);
- `canonicalJson` con un decimal lanza `InvalidArgumentException`, que motiva Q-1;
- `trim()` no quita U+00A0, como fija FI-2.

### 2.5 Vector oficial de Twilio

Los 6 vectores oficiales (formulario, JSON con `bodySHA256`, claves repetidas y tres de puerto) son literales de `twilio/twilio-python`, `tests/unit/test_request_validator.py` (rama `main`). Lo descargué el 31-10-2026 como dato, en un directorio aparte, y solo lo leí: sha256 `4522667e1aef9fcc1e598dad33f84315913feac4a7cc2cfe02b3f34ef5bc4509`. Mi cálculo con HMAC-SHA1 da exactamente las firmas publicadas, también con openssl y con PHP.

## 3. Preguntas para el lead (el detalle, en el README)

**Tras la Enmienda 1** siguen abiertas Q-4, Q-6, Q-7, Q-9, Q-10 y Q-11 tal como están abajo. Q-5, Q-8 y Q-12 quedan como se reescriben en el README. Q-3 está resuelta. Se añade **Q-13 = DT-1**. La lista de abajo es la del 31-10.

- **Q-3.** `VoiceProviderStatus.provider`: el fixture dice el id de `current()`, es decir `elevenlabs` solo en `UNVERIFIED`.
- **Q-4.** `ELEVENLABS_VERIFIED`: el fixture exige que la **última** sonda de voz sea `ok` y de menos de 30 días. La lectura «cualquier `ok` en 30 días» deja «verificado» después de revocar la clave.
- **Q-5.** El analizador estricto de la firma de ElevenLabs: toda parte `t`/`v0` con forma, el `trim` de PHP, y ni la clave ni el valor se recortan.
- **Q-6.** `isEngineCanary` de una fila revocada con la huella del motor: el fixture dice `false`. `activeWhatsapp` con `unreadable`: no se afirma.
- **Q-7.** Sin versión esperada y con `source = none`: el fixture dice `none`.
- **Q-8.** La región no se normaliza dentro de `ElevenLabsConfig`, y `baseUrl()` es `null` sin región válida aunque haya `base_url`.
- **Q-9.** `aliasError()` no recorta.
- **Q-10.** En `facts`: un ausente es `{present:false}`, el host se compara exacto y la región de voz va tal cual.
- **Q-11.** En la ruta 56: el SID mal formado da `missing_account_sid`, y la misma `clientRequestId` de otra persona da `client_request_reused`.
- **Q-12.** El saneado de la sonda: `other` para lo desconocido, secciones `null` al fallar, lista ausente = error, pasos no ejecutados = `skipped` y cuenta saltada = `error`.

**La que más pesa para S y TW es Q-12. Para V, Q-3 a Q-5.** El WIP de TW y V tiene fixtures propios (§5) y es donde más fácil es que diverjan.

## 4. Observaciones (no son del fixture)

- **O-1 (security).** La regla de alias `\d{4,}` no ve un teléfono partido en grupos de 2 (`30 00 00 00 01`).
- **O-2 (S y FE).** `not_allowed_for_channel` también sale en voz y con longitudes equivocadas, y el texto fijo dice «WhatsApp exige un móvil».
- **O-3 (FE).** Las tildes NFD que manda macOS se rechazan en el alias. Hay que normalizar a NFC en el formulario.
- **O-4 (FE y S).** PHP escribe `[]` para un mapa vacío (`byStatus`, `byCode`, `byLevel` y los de la ruta 43). El CRM tiene que aceptar las dos formas.
- **O-5 (T-2, hipótesis sin comprobar).** El validador privado de `usage-trigger` no reproduciría el vector oficial de claves repetidas.

## 5. Trabajo en paralelo en este árbol

Están en el historial `01ad03e` (DB), `7241ad8` (V), `b647a8e` (TW) y `7d69b77` (este fixture parcial). **No leí** `app/Services/Voice/**`, `app/Services/Twilio/**` ni sus fixtures y pruebas. Tienen que pasar contra este oráculo:
- `StudioIntegrationsParityTest` y los Unit de V y TW (§I11);
- `elevenlabsSignature`, `voiceDoctor`, `voiceVerify` y `voiceCallPolicy` para V;
- `twilioProbeSanitize` para TW.

Si discrepan, se corrige la implementación o se enmienda el contrato.

## 6. Lo que queda fuera, y por qué

Está en el README («Lo que no cubre»):
- **No es núcleo puro.** La validación 422, la base de datos con sus disparadores, CHECK, parciales y carreras (`ops/pgunit.sh`), lo HTTP del cliente de Twilio, los DTO completos y los comandos. Van en Feature, en PG, en las mutaciones y en el E2E (paso 4 del tester).
- **Bordes evitados a propósito.** Nombres nulos o con tildes en el orden, `started_at` nulo, importes de tres decimales, hosts con `http`, mayúsculas o puerto, páginas más largas que lo pedido, `phone_number` con menos de 4 dígitos, códigos numéricos de alerta y un alias de solo espacios.
