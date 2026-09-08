# CUSTOMER_BODY_HARDENING = DEFERRED

**Estado:** aplazado a propósito. NO se corrige en el release móvil 2.0.3 (13).
**Fecha de la nota:** 2026-09-08
**Origen:** auditoría de precedencia de campos PSE (commits `07ca36e`, `f1a3631`).

## Qué pasa

`WompiPaymentController::resolveSubject()` construye el bloque `customer` con
esta precedencia:

```php
$data['customer'] = array_merge(
    [/* valores del miembro autenticado */],
    (array) ($data['customer'] ?? []),   // ← viene del body, SIN validar
    $overrides,                          // ← validado, solo lo pasa payPse()
);
```

El término del medio es un bloque `customer` arbitrario que llega en el cuerpo
de la petición y no pasa por ninguna regla de `FormRequest`. Un socio
autenticado puede enviarlo y alterar el `customer_data` que se manda a Wompi
(nombre, correo, teléfono, documento) en **CARD, NEQUI y DAVIPLATA**.

En PSE ya no gana: `payPse()` pasa `customerOverrides` validados y `array_merge`
los coloca en último lugar. Los otros tres métodos no pasan overrides, así que
ahí el bloque del body sigue siendo el último valor efectivo.

## Qué NO afecta

Comprobado, no supuesto:

- **No cambia quién recibe la membresía.** `member_id` y `user_id` salen de la
  sesión autenticada, no del body. Cubierto por
  `WompiPseCheckoutFieldsTest::test_the_charge_still_belongs_to_the_authenticated_member`,
  que manda `member_id` y `user_id` ajenos y comprueba que la transacción queda
  a nombre del miembro autenticado.
- **No cambia el importe.** El precio se resuelve desde el plan en servidor.
- **No permite pagar con la cuenta de otro.** Solo altera datos de contacto que
  viajan a la pasarela.

Por eso es *hardening*, no un fallo explotable para robar membresías ni dinero.

## Por qué se aplaza

Tocar la precedencia de `customer` afecta a los tres métodos de pago que están
validados y funcionando en producción (CARD, NEQUI, DAVIPLATA). Mezclarlo con
un release de tienda que solo arregla PSE convierte un cambio acotado en uno
que obliga a revalidar los tres flujos de pago de punta a punta.

## Cómo se arregla cuando toque

1. Dejar de leer `customer` del body en `resolveSubject()`: que cada método
   pase overrides explícitos y validados, como ya hace `payPse()`.
2. Si algún método necesita datos del pagador distintos a los del perfil,
   añadir los campos concretos a su `FormRequest` con reglas propias.
3. Test de regresión por método: enviar un bloque `customer` en el body y
   comprobar que el `customer_data` que sale a Wompi NO lo refleja.
4. Revalidar CARD, NEQUI y DAVIPLATA end-to-end antes de desplegar.
