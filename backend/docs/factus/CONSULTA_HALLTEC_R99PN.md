# Consulta técnica a Factus / Halltec

**Asunto:** Representación de servicios gravables vendidos por emisor NO responsable de IVA (responsabilidad RUT 49)

**Emisor:** Iron Body
**Rango de numeración:** 2076 — prefijo `IBFE`
**Ambiente:** Producción
**Endpoint:** `POST /v2/bills/validate` (API V2)

---

## 1. Situación tributaria del emisor

Iron Body figura en el RUT con **responsabilidad 49 — No responsable de IVA**, condición confirmada
por su contador.

En consecuencia, y según entendemos, la responsabilidad equivalente del emisor en facturación
electrónica sería **R-99-PN**.

Es importante precisar el motivo del IVA en cero, porque determina cómo debe representarse el
documento:

- Los servicios vendidos (planes de gimnasio, mensualidades, productos) **no son exentos**.
- **No son excluidos** por su naturaleza.
- **No son no gravados.**
- La **única** causa de que el IVA sea cero es la **condición fiscal del emisor**, no la naturaleza
  del bien o servicio.

Clasificar el ítem como exento o excluido afirmaría ante la DIAN algo que es falso sobre el producto.

## 2. Desglose requerido

Para un servicio con precio comercial de $80.000:

| Concepto | Valor |
|---|---|
| Precio comercial | 80.000 |
| Subtotal | 80.000 |
| IVA | 0 |
| **Total** | **80.000** |

El precio comercial debe conservarse íntegro. No se suma 19 % (no debe dar 95.200) y no se extrae
19 % de dentro del precio (no debe dar 67.226,89 + 12.773,11).

## 3. Comportamiento observado en la API

Hemos probado las siguientes representaciones del bloque `items[].taxes` sobre una solicitud real
(`reference_code: 9b5bd29d-982f-454f-8dfc-3e56ad4a5860`, ítem `price: 80000.00`, `quantity: 1.00`).

### Variante A — declarar el ítem como NO excluido, sin tarifa

```json
"taxes": [ { "is_excluded": false } ]
```

**Respuesta: HTTP 422**

```json
{
  "items.0.taxes.0.code": ["El campo código tributo es obligatorio."],
  "items.0.taxes.0.rate": ["El campo porcentaje de impuesto es obligatorio."]
}
```

### Variante B — omitir por completo el bloque `taxes`

```json
"items": [ { "code_reference": "...", "name": "...", "quantity": "1.00", "price": "80000.00", ... } ]
```

**Respuesta: HTTP 422**

```json
{
  "items.0.taxes": ["El campo items.0.taxes es obligatorio."]
}
```

### Variante C — `is_excluded: true`

**No la hemos enviado**, porque declararía el servicio como jurídicamente excluido, lo que sería
una afirmación falsa ante la DIAN según lo indicado en el punto 1.

### Observación adicional

Al consultar mediante `GET /v2/bills/{number}` una factura ya validada de esta misma cuenta,
la representación almacenada del impuesto es:

```json
"taxes": [
  {
    "tribute": { "code": "01", "name": "IVA" },
    "is_excluded": false,
    "rates": [ { "taxable_amount": "67226.89", "tax_amount": "12773.11", "rate": "19.00" } ]
  }
]
```

Es decir, el modelo de datos parece exigir siempre un tributo asociado al ítem con una tarifa
concreta. Con las dos únicas formas que la API acepta —`{code, rate}` o `{is_excluded: true}`— no
encontramos manera de representar un servicio **gravable por naturaleza** cuyo IVA es cero
**por la condición del emisor**.

Adicionalmente, notamos que el payload de `POST /v2/bills/validate` no incluye ningún bloque de
datos del emisor (solo `customer` e `items`), por lo que entendemos que la responsabilidad fiscal
del emisor se toma de la configuración de la cuenta y no del documento.

Consultando `GET /v2/bills/{number}`, el bloque `company` de la respuesta devuelve `nit`, `dv`,
`economic_activity` y `establishment`, pero **no expone ninguna responsabilidad tributaria**. No
tenemos por tanto forma de verificar desde la API con qué responsabilidad está registrado el emisor,
lo que motiva la primera pregunta.

## 3-bis. El perfil del emisor YA está correcto

Consultando `GET /v2/companies`, el perfil registrado en nuestra cuenta es:

```json
{
  "responsibilities":     [{ "code": "R-99-PN", "name": "No responsable" }],
  "tribute":              { "code": "ZZ", "name": "No aplica" },
  "legal_organization":   { "code": "2",  "name": "Persona Natural" },
  "economic_activity":    "9311"
}
```

Es decir, **el emisor ya figura como R-99-PN con tributo de empresa ZZ**, que entendemos es
exactamente la configuración correspondiente a la responsabilidad 49 del RUT.

Aun así, la API sigue exigiendo un tributo a nivel de ítem. Lo hemos verificado en el ambiente
de **sandbox**, con el mismo perfil de empresa y sin tocar producción:

```
POST /v2/bills/validate  (sandbox)
→ HTTP 422
   items.0.taxes.0: "El campo items.0.taxes es obligatorio."
```

Esto descarta que se trate de una configuración incorrecta de la cuenta y sitúa la pregunta en el
contrato de la API.

## 4. Preguntas concretas

1. Dado que el emisor **ya está registrado como R-99-PN con tributo ZZ**, ¿por qué la API sigue
   exigiendo `items[].taxes` con código y tarifa? ¿Hay alguna configuración adicional en la cuenta
   que deba habilitarse?

2. ¿Cuál es el **payload exacto** que debemos enviar para un servicio gravable vendido por un
   emisor no responsable de IVA, de modo que el documento quede con subtotal 80.000, IVA 0 y
   total 80.000?

3. ¿La API V2 **soporta** esta representación sin obligarnos a clasificar el ítem como exento o
   excluido? Si no la soporta, agradecemos que nos lo confirmen por escrito.

4. ¿El campo `is_excluded: true` recibe algún **tratamiento especial** cuando el emisor está
   registrado como R-99-PN? Es decir, ¿la DIAN lo interpreta como exclusión del producto, o
   simplemente como ausencia de IVA por el régimen del emisor?

5. ¿Requieren **ajustar la configuración tributaria** de nuestra cuenta (tributos habilitados,
   tarifas por defecto, perfil del emisor) para habilitar este escenario?

## 5. Estado actual

La emisión permanece **detenida** hasta recibir su respuesta. No autorizamos clasificar los
servicios como exentos o excluidos sin esta confirmación por escrito.

Agradecemos su respuesta documentada.
