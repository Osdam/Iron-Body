# Fixtures del paquete TW (Twilio de solo lectura)

Escritos **a mano** el 31-10-2026 para `tests/Unit/Twilio/**`. No los genera
ningún script y no los consume el CRM: el fixture del contrato es
`tests/fixtures/ultron-studio/integrations-cases.json` (sección
`twilioProbeSanitize`, del tester). Si los dos discrepan, manda aquel y se
corrige el saneador.

## `twilio-api-responses.json`

Cuerpos con la **forma pública** de la API de Twilio, comprobada ese día en la
documentación oficial:

| Paso | Recurso | Lista | Paginación |
|---|---|---|---|
| `account` | `GET /2010-04-01/Accounts/{Sid}.json` | — (objeto; trae `auth_token`) | — |
| `numbers` | `…/IncomingPhoneNumbers.json` | `incoming_phone_numbers` | `next_page_uri` |
| `caller_ids` | `…/OutgoingCallerIds.json` | `outgoing_caller_ids` | `next_page_uri` |
| `calls` | `…/Calls.json` (`StartTime>` = «empezó ese día o después», `YYYY-MM-DD` UTC) | `calls` | `next_page_uri` |
| `alerts` | `https://monitor.twilio.com/v1/Alerts` (`StartDate` = `YYYY-MM-DDThh:mm:ssZ` GMT) | `alerts` | `meta.next_page_url` |

- `capabilities` va en minúsculas (`voice`, `sms`, `mms`, `fax`).
- Estados de una llamada: `queued`, `ringing`, `in-progress`, `completed`,
  `busy`, `failed`, `no-answer`, `canceled`. Niveles de una alerta: `error`,
  `warning`, `notice`, `debug`; `error_code` es una cadena.

**Ningún dato real.** SIDs inventados (`ACdeadbeef…`, `PN…`, `CA…`, `NO…`,
`SM…`, `VA…`, `VE…`), teléfonos de ficción (NANP `555-01xx` y el rango de Ofcom
`020 7946 0xxx`), dominios `.test`, nombres inventados y un `auth_token`
centinela. Los textos son **hostiles a propósito**: teléfonos en
`friendly_name`, `alert_text`, `request_url`, `request_variables` y
`response_body`, URLs con credenciales y nombres de personas.

## `sanitize-cases.json`

17 casos `respuestas por paso → {outcome, facts}` exactos, con el orden de
claves del contrato (§I5.4). Un cuerpo `{"$ref": "nombre"}` sale del fichero
anterior **sin pasar por el cliente** (con su `auth_token`). `sentinels` son
cadenas que tienen que estar en los cuerpos y no pueden aparecer en ningún
resultado.

Convenciones que el contrato no fijaba (las congela el lead en una enmienda si
hace falta):

1. Un paso que falla, se salta o trae un cuerpo con otra forma deja su bloque en
   **`null`** y una entrada en `errors` («sin dato» no es «cero»).
2. Un paso que no viene en las respuestas cuenta como `skipped`.
3. Lo que no casa con su conjunto cuenta como **`other`** en los recuentos
   (`byStatus`, `byCode`, `byLevel`) y queda en `null` en la ficha de la cuenta.
4. `byCode` solo admite códigos de 1 a 6 cifras sin cero inicial (un teléfono no
   cabe); `last4` solo sale de un E.164 estricto (`^\+[1-9][0-9]{7,14}$` con `D`: 8 a 15 cifras, Enmienda 2 Q-13; sin
   salto de línea final).
5. Un cuerpo inesperado: la ficha sin un `sid` `AC…` válido; una lista que falta,
   no es una lista o tiene una entrada que no es un objeto. Es `error` con el
   estado HTTP que vino (200).
6. Más filas de las pedidas: `truncated = true` y se recorta a la página.
7. Los recuentos van ordenados por clave como cadena; uno vacío es `[]` (aquí
   se escribe `{}`, que `json_decode(…, true)` deja en `[]`).
