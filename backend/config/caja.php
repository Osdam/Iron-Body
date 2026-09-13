<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zona horaria OPERATIVA del negocio
    |--------------------------------------------------------------------------
    |
    | `app.timezone` sigue siendo UTC y NO se toca: cambiarlo reinterpretaría
    | todos los timestamps ya almacenados y movería la historia contable varias
    | horas. Los datos se guardan en UTC, como siempre.
    |
    | Esta zona es solo para INTERPRETAR y PRESENTAR fechas de negocio: cuando
    | alguien filtra el historial por "3 de septiembre", quiere el día del
    | gimnasio (00:00–23:59 en Colombia), no una ventana UTC que empieza a las
    | 19:00 del día anterior.
    |
    */
    'timezone' => env('CAJA_TIMEZONE', 'America/Bogota'),

    /*
    |--------------------------------------------------------------------------
    | Política de apertura
    |--------------------------------------------------------------------------
    |
    | 'zero': cada turno abre contablemente en 0. Es la política del negocio.
    |
    | No se arrastra el efectivo esperado del cierre anterior porque nadie
    | garantiza que ese dinero siga físicamente en el cajón, y un arrastre falso
    | propaga el error a todos los turnos siguientes.
    |
    | Si algún día hace falta una base fija para vueltas, será una configuración
    | explícita e independiente, no un arrastre implícito.
    |
    */
    'opening_policy' => 'zero',

    /*
    |--------------------------------------------------------------------------
    | Plazo por defecto para saldar una obligación
    |--------------------------------------------------------------------------
    |
    | Días que se conceden desde que nace la deuda, cuando quien la crea no pacta
    | una fecha concreta. El mostrador SIEMPRE puede poner otra: este número solo
    | evita que una deuda nazca sin plazo por olvido.
    |
    | 15 días, y no es un número al azar: es medio mes, el término comercial
    | corriente aquí, y sobre todo cabe dentro del mes que paga la membresía. Un
    | plazo más largo dejaría la deuda viva más tiempo que el plan que financió,
    | y entonces el socio estaría renovando mientras aún debe el anterior.
    |
    | Poner 0 desactiva el default: sin fecha explícita, la deuda nace sin plazo
    | y por tanto nunca vence sola.
    |
    */
    'default_payment_term_days' => (int) env('CAJA_DEFAULT_PAYMENT_TERM_DAYS', 15),

];
