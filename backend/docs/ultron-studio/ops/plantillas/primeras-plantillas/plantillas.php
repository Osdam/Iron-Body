<?php

// Las plantillas propuestas y los textos malos a propósito (datos del guion validar.php).

$baja = "\n\nSi no desea recibir más mensajes, responda BAJA.";
$w9 = static fn (array $extra = []): array => [
    1 => ['first_name' => 'Laura', 'gym_name' => (string) config('legal.brand')] + $extra,
    2 => ['first_name' => 'José', 'gym_name' => (string) config('legal.brand')] + array_map(static fn ($v) => $v === '15 de diciembre de 2026' ? '5 de enero de 2027' : $v, $extra),
];
$fecha = ['membership_end_date_text' => '15 de diciembre de 2026'];

$bien = [
    'A_seguimiento_prospecto' => [
        'key' => 'seguimiento-prospecto', 'purpose' => 'commercial', 'trigger' => 'lead.followup_due',
        'content' => [
            'metaName' => 'seguimiento_prospecto_v1', 'language' => 'es_CO', 'category' => 'marketing',
            'bodyPreview' => 'Hola, {{1}}. Le escribimos de {{2}} para retomar la conversación que tuvimos por WhatsApp. ¿Le sigue interesando entrenar con nosotros? Si tiene alguna pregunta, responda este mensaje y con gusto le ayudamos.'.$baja,
            'variables' => ['first_name', 'gym_name'],
            'examples' => ['first_name' => 'Laura', 'gym_name' => 'Iron Body'],
            'optOutLine' => true,
        ],
        'w9Values' => $w9(),
    ],
    'B_membresia_por_vencer' => [
        'key' => 'membresia-por-vencer', 'purpose' => 'commercial', 'trigger' => 'membership.expiring_window',
        'content' => [
            'metaName' => 'membresia_por_vencer_v1', 'language' => 'es_CO', 'category' => 'marketing',
            'bodyPreview' => 'Hola, {{1}}. Le recordamos que su membresía en {{2}} vence el {{3}}. Si desea renovarla o tiene alguna pregunta, responda este mensaje y con gusto le ayudamos.'.$baja,
            'variables' => ['first_name', 'gym_name', 'membership_end_date_text'],
            'examples' => ['first_name' => 'Laura', 'gym_name' => 'Iron Body', 'membership_end_date_text' => '15 de diciembre de 2026'],
            'optOutLine' => true,
        ],
        'w9Values' => $w9($fecha),
    ],
    'C_membresia_vencida' => [
        'key' => 'membresia-vencida', 'purpose' => 'commercial', 'trigger' => 'membership.expired_window',
        'content' => [
            'metaName' => 'membresia_vencida_v1', 'language' => 'es_CO', 'category' => 'marketing',
            'bodyPreview' => 'Hola, {{1}}. Su membresía en {{2}} terminó el {{3}}. Si desea retomar sus entrenamientos, responda este mensaje y le indicamos cómo renovarla.'.$baja,
            'variables' => ['first_name', 'gym_name', 'membership_end_date_text'],
            'examples' => ['first_name' => 'Laura', 'gym_name' => 'Iron Body', 'membership_end_date_text' => '15 de noviembre de 2026'],
            'optOutLine' => true,
        ],
        'w9Values' => $w9($fecha),
    ],
    'D_socio_inactivo' => [
        'key' => 'socio-inactivo', 'purpose' => 'commercial', 'trigger' => 'member.inactive',
        'content' => [
            'metaName' => 'socio_inactivo_v1', 'language' => 'es_CO', 'category' => 'marketing',
            'bodyPreview' => 'Hola, {{1}}. En {{2}} hace un tiempo no le vemos por el gimnasio y queremos saber cómo está. Si desea retomar su rutina o necesita ayuda para organizarse, responda este mensaje y con gusto le ayudamos.'.$baja,
            'variables' => ['first_name', 'gym_name'],
            'examples' => ['first_name' => 'Laura', 'gym_name' => 'Iron Body'],
            'optOutLine' => true,
        ],
        'w9Values' => $w9(),
    ],
    'E_valoracion_pendiente' => [
        'key' => 'valoracion-pendiente', 'purpose' => 'commercial', 'trigger' => 'valuation.outdated',
        'content' => [
            'metaName' => 'valoracion_pendiente_v1', 'language' => 'es_CO', 'category' => 'marketing',
            'bodyPreview' => 'Hola, {{1}}. En {{2}} vemos que ya pasó un tiempo desde su última valoración física. Si desea agendar una nueva para revisar su progreso, responda este mensaje y le ayudamos a programarla.'.$baja,
            'variables' => ['first_name', 'gym_name'],
            'examples' => ['first_name' => 'Laura', 'gym_name' => 'Iron Body'],
            'optOutLine' => true,
        ],
        'w9Values' => $w9(),
    ],
];

$base = $bien['B_membresia_por_vencer']['content'];
$sinBaja = str_replace($baja, '', $base['bodyPreview']);

$antes = [
    'ANTES_B_con_dias_y_plan' => ['key' => 'membresia-por-vencer', 'purpose' => 'commercial', 'trigger' => 'membership.expiring_window', 'content' => array_replace($base, [
        'bodyPreview' => 'Hola, {{1}}. Le recordamos que su {{2}} vence en {{3}} días. Si desea renovarla o tiene alguna pregunta, responda este mensaje y con gusto le ayudamos.'.$baja,
        'variables' => ['first_name', 'plan_name', 'days_to_expiry'],
        'examples' => ['first_name' => 'Laura', 'plan_name' => 'Plan Trimestral', 'days_to_expiry' => '5'],
    ]), 'w9Values' => [
        1 => ['first_name' => 'Laura', 'plan_name' => 'MENSUALIDAD', 'days_to_expiry' => '0'],
        2 => ['first_name' => 'José', 'plan_name' => 'Promo 2x1', 'days_to_expiry' => '1'],
    ]],
];

$malas = [
    'MALA_1_con_precio' => ['key' => 'mala-precio', 'purpose' => 'commercial', 'content' => array_replace($base, [
        'metaName' => 'mala_precio_v1',
        'bodyPreview' => 'Hola, {{1}}. Su membresía en {{2}} vence el {{3}}. Renueve hoy por $120.000 y siga entrenando.'.$baja,
    ])],
    'MALA_2a_sin_baja_y_sin_optOutLine' => ['key' => 'mala-sin-baja', 'purpose' => 'commercial', 'content' => array_diff_key(array_replace($base, [
        'metaName' => 'mala_sin_baja_v1', 'bodyPreview' => $sinBaja,
    ]), ['optOutLine' => true])],
    'MALA_2b_sin_baja_optOutLine_false' => ['key' => 'mala-sin-baja', 'purpose' => 'commercial', 'content' => array_replace($base, [
        'metaName' => 'mala_sin_baja_v1', 'bodyPreview' => $sinBaja, 'optOutLine' => false,
    ])],
    'MALA_2c_sin_baja_PERO_optOutLine_true' => ['key' => 'mala-sin-baja', 'purpose' => 'commercial', 'content' => array_replace($base, [
        'metaName' => 'mala_sin_baja_v1', 'bodyPreview' => $sinBaja,
    ]), 'w9Values' => $w9($fecha)],
    'MALA_3_comercial_como_utility' => ['key' => 'mala-categoria', 'purpose' => 'commercial', 'content' => array_replace($base, [
        'metaName' => 'mala_categoria_v1', 'category' => 'utility',
    ])],
];

return $bien + $antes + $malas;
