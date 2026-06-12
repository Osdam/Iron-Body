# Wompi — Seguridad

## Secretos (nunca en el repo, nunca en Flutter)

| Secreto | Dónde vive | En Flutter |
|---|---|---|
| `WOMPI_PRIVATE_KEY` | Solo backend (`.env`) | ❌ |
| `WOMPI_INTEGRITY_SECRET` | Solo backend | ❌ |
| `WOMPI_EVENTS_SECRET` | Solo backend | ❌ |
| `WOMPI_PUBLIC_KEY` | Backend + app (no es secreta) | ✅ (solo pública) |

`.env.example` contiene únicamente **placeholders vacíos**. `WompiConfigValidator`
aborta el arranque en producción si las llaves no corresponden al ambiente.

> ⚠️ Las credenciales compartidas previamente se consideran **expuestas** y deben
> **rotarse** antes de producción.

## PCI

- El **PAN y el CVC** se tokenizan en Flutter contra Wompi con la llave pública;
  **jamás** pasan por Laravel ni se persisten/loguean.
- La app no guarda tarjeta/CVC/OTP en SharedPreferences, SecureStorage, logs ni
  crash reports. Los controladores de tarjeta se limpian tras cada intento.
- Laravel nunca almacena PAN/CVC/OTP ni secretos de la pasarela. Solo guarda
  datos NO sensibles si Wompi los devuelve (marca, últimos 4, cuotas).

## Firma de integridad (crear transacción)

`SHA256(reference + amount_in_cents + CURRENCY [+ expiration_time] + integrity_secret)`.
Solo backend. Tests determinísticos en `tests/Unit/Wompi/WompiSignatureTest`.

## Webhook (checksum de eventos)

`SHA256( <valores de signature.properties en orden, relativos a data> + timestamp + events_secret )`,
comparado con `hash_equals` (constant-time, case-insensitive). Además:

- Se valida el **ambiente** del evento.
- Se registra el evento **antes** de procesarlo; dedupe por `payload_hash` único
  (un evento idéntico reentregado no se procesa dos veces → responde 200).
- Se valida que la transacción exista y que **monto y moneda coincidan** (no se
  aprueba un pago con monto alterado).
- Procesamiento dentro de transacción DB con `lockForUpdate`.
- Firma inválida → **401 controlado**; nunca se revelan secretos ni payloads sin
  sanitizar.

## Autorización / abuso

- Rutas privadas bajo `auth.member`; el sujeto se toma del **miembro autenticado**
  (no del body) → anti suplantación.
- Ownership: un miembro no puede consultar pagos de otro (404 uniforme).
- `throttle` por endpoint. Webhook con `throttle:120,1` además del checksum.

## Logs

`WompiClient` registra método, path, status, `error_code` y `correlation_id`.
Nunca: `Authorization`, tokens, datos del pagador ni cuerpos crudos. El OTP de
DaviPlata se transmite pero **no se loguea ni se persiste**.

## Riesgos residuales

- **Monto de tienda** (`purpose=store`): hoy viaja desde el cliente (paridad con
  el flujo previo). Para una garantía completa, el carrito debería tarificarse en
  el backend desde el catálogo de productos. Las **membresías** ya usan el precio
  autoritativo del plan.
- DaviPlata requiere validar el contrato OTP real en sandbox antes de producción.
