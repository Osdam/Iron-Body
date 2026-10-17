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

    /*
    |--------------------------------------------------------------------------
    | Plazo por caja
    |--------------------------------------------------------------------------
    |
    | Fiar un plan de gimnasio y fiar un batido no son la misma operación
    | comercial, así que no comparten plazo.
    |
    |   gym (15 días): el crédito recae sobre un servicio que se consume durante
    |   todo el mes. Si el plazo lo desborda, el socio acaba renovando mientras
    |   todavía debe el plan anterior.
    |
    |   products (8 días): importes pequeños y frecuentes. Ocho días es "el mismo
    |   día de la semana que viene", que es como se pacta de verdad en el
    |   mostrador, con un día de margen.
    |
    | 0 en cualquiera de los dos = sin plazo por defecto en esa caja: la deuda
    | nace sin vencimiento y por tanto nunca vence sola.
    |
    */
    'payment_terms' => [
        'gym'      => (int) env('CAJA_GYM_DEFAULT_PAYMENT_TERM_DAYS', 15),
        'products' => (int) env('CAJA_PRODUCTS_DEFAULT_PAYMENT_TERM_DAYS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recibos térmicos de caja
    |--------------------------------------------------------------------------
    |
    | Numeración propia por caja, sin huecos: RG-000001 en el gimnasio y
    | RP-000001 en productos. Los prefijos distinguen la caja a simple vista en
    | la tirilla y en el informe de cierre. NO es la numeración DIAN: esa la
    | administra Factus y un recibo de caja no es una factura electrónica.
    |
    | `late_after_minutes`: un recibo emitido más tarde que esto respecto a la
    | operación se marca `issued_late`. El que se imprime al día siguiente desde
    | el informe del turno no es el que se le entregó al cliente en el mostrador,
    | y eso tiene que poder verse.
    |
    | `business_name`: el nombre comercial que encabeza la tirilla. «IRON BODY
    | WORKOUT» lo pidió expresamente el propietario («ENCABEZADO: IRON BODY
    | WORKOUT»); CAJA_RECEIPT_BUSINESS_NAME lo sobrescribe. Solo afecta a los
    | recibos que se emitan desde el cambio: los ya emitidos llevan el nombre
    | congelado en su snapshot y se reimprimen tal cual. La razón social, el
    | NIT, la dirección y el teléfono salen de `billing.company`
    | (FACTUS_COMPANY_*), que es lo que ya se declara ante la DIAN.
    |
    */
    'receipts' => [
        'prefixes' => [
            'gym' => env('CAJA_RECEIPT_PREFIX_GYM', 'RG-'),
            'products' => env('CAJA_RECEIPT_PREFIX_PRODUCTS', 'RP-'),
        ],
        'number_padding' => 6,
        'late_after_minutes' => (int) env('CAJA_RECEIPT_LATE_AFTER_MINUTES', 10),
        'business_name' => env('CAJA_RECEIPT_BUSINESS_NAME', 'IRON BODY WORKOUT'),
        'template_version' => 1,
        'legend' => 'Este documento no es una factura electrónica de venta',

        /*
        | Servicio local de impresión (ESC/POS por el spooler de Windows).
        |
        | `enabled` es el INTERRUPTOR GENERAL: en false, emitir un ticket y
        | canjearlo responden 503 `agent_disabled`. Los equipos en modo «agent»
        | NO pasan solos al navegador: el CRM muestra el error, el cobro queda y
        | el recibo queda pendiente (se reimprime desde el cierre). Para volver
        | un equipo al método anterior lo cambia un administrador en
        | Configuración › Pagos. No toca ningún recibo ni intento ya registrado. Activar el modo «agent» es además por equipo (lo decide el
        | CRM) y exige una estación registrada: desplegar esto no cambia nada
        | para nadie.
        |
        | `ticket_ttl` en segundos: un ticket de impresión vive lo justo para ir
        | del navegador al servicio y de ahí aquí; uno de prueba o de
        | configuración, lo que tarda alguien en ir al equipo.
        |
        | `report_window_hours`: hasta cuándo el servicio puede contar cómo
        | terminó un trabajo canjeado. Más tarde, el resultado ya no describe la
        | tirilla que se le dio a nadie.
        */
        'agent' => [
            'enabled' => (bool) env('RECEIPT_AGENT_ENABLED', true),
            'min_protocol' => (int) env('RECEIPT_AGENT_MIN_PROTOCOL', 1),
            'ticket_ttl' => [
                'print' => 120,
                'test' => 300,
                'configure' => 300,
            ],
            'report_window_hours' => 24,
        ],
    ],

];
