<?php

return [
    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:4200',
        'http://127.0.0.1:4200',
        'https://ironbodyneiva.cloud',
        'https://www.ironbodyneiva.cloud',
    ],

    // Cualquier subdominio de ironbodyneiva.cloud (crm, api, www, etc.).
    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)?ironbodyneiva\.cloud$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];