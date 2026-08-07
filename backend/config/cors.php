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

    /*
    | Cuanto puede el navegador recordar el permiso de una peticion previa.
    |
    | Estaba en 0, o sea: NUNCA. El SPA vive en ironbodyneiva.cloud y la API en
    | api.ironbodyneiva.cloud, asi que son origenes distintos y cada llamada
    | pedia permiso primero. Medido en el inbox, eso significaba DOS viajes de
    | red por cada peticion -abrir una conversacion son dos llamadas, luego
    | cuatro viajes-, y el primero de cada uno paga ademas la conexion TLS.
    |
    | Dos horas es el techo que respeta Chrome; mas no sirve de nada. No relaja
    | ninguna comprobacion: la respuesta real sigue validandose contra la lista
    | de origenes permitidos. Lo unico que se retrasa hasta dos horas es que un
    | navegador ya abierto note un cambio en los metodos o cabeceras admitidos.
    */
    'max_age' => 7200,

    'supports_credentials' => false,
];