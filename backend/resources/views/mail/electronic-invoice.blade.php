<x-mail::message>
# Tu factura electrónica

Hola, adjuntamos tu factura electrónica emitida por **Iron Body Neiva**.

@if($fullNumber)
- **Número de factura:** {{ $fullNumber }}
@endif
- **Total:** {{ $currency }} {{ $total }}
@if($cufe)
- **CUFE:** {{ $cufe }}
@endif
@if($validatedAt)
- **Fecha de validación:** {{ $validatedAt }}
@endif

Este comprobante fue validado ante la DIAN. Si tienes alguna duda, responde a este correo.

Gracias por tu compra,<br>
**Iron Body Neiva**
</x-mail::message>
