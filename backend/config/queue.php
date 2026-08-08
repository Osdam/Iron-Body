<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Carriles de Iron Body
        |----------------------------------------------------------------------
        |
        | Cinco conexiones sobre la MISMA tabla `jobs`, que se diferencian solo
        | en el nombre de la cola y en `retry_after`. No es duplicación: en el
        | driver `database`, `retry_after` es por CONEXIÓN, no por cola, así que
        | es el único sitio donde se puede decir que un mensaje entrante se
        | recupera en dos minutos y una factura de Factus en diez.
        |
        | La regla que ordena los números: **retry_after > timeout del worker**,
        | siempre y con margen. `retry_after` es el tiempo tras el cual la cola
        | da por muerto un trabajo reservado y deja que otro worker lo tome. Si
        | fuera menor o igual que el timeout, un trabajo lento —una llamada a
        | OpenAI, una emisión a Factus— seguiría corriendo mientras un segundo
        | worker empieza el mismo: dos respuestas al mismo cliente, dos facturas
        | con dos números. Producción tenía hoy retry_after=90 con el worker de
        | billing a timeout=180, que es exactamente ese agujero esperando a que
        | alguien añadiera un segundo proceso.
        |
        | El sentido contrario también cuesta: cuanto más alto el `retry_after`,
        | más tarda en rescatarse un trabajo cuyo worker murió de verdad. Por eso
        | no hay un número global grande, sino uno por carril, ajustado a lo que
        | tarda su trabajo más lento.
        */

        // P0 · Entrada de WhatsApp: persistir el mensaje y mover estados. Es lo
        // que decide si un cliente aparece o no en el inbox, y no espera a nadie.
        'whatsapp' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('QUEUE_WHATSAPP', 'whatsapp-high'),
            'retry_after' => (int) env('QUEUE_WHATSAPP_RETRY_AFTER', 120),
            'after_commit' => false,
        ],

        // P2 · El agente. Lento por definición: depende de un modelo. Vive
        // aparte precisamente para que su lentitud no sea la de nadie más.
        'agent' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('QUEUE_AGENT', 'agent'),
            'retry_after' => (int) env('QUEUE_AGENT_RETRY_AFTER', 360),
            'after_commit' => false,
        ],

        // P3 · Multimedia. Un vídeo de 50 MB tarda lo que tarda; que tarde en
        // su propio carril y no delante del mensaje de texto de otra persona.
        'media' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('QUEUE_MEDIA', 'media'),
            'retry_after' => (int) env('QUEUE_MEDIA_RETRY_AFTER', 600),
            'after_commit' => false,
        ],

        // P4 · Eventos comerciales, oportunidades, alertas y analítica. Nada de
        // esto lo está esperando una persona con el teléfono en la mano.
        'commercial' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('QUEUE_COMMERCIAL', 'commercial'),
            'retry_after' => (int) env('QUEUE_COMMERCIAL_RETRY_AFTER', 300),
            'after_commit' => false,
        ],

        // Facturación electrónica. Ya estaba separada y funcionando; lo único
        // que cambia es que su `retry_after` deja de ser menor que su timeout.
        'billing' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('FACTUS_QUEUE', 'billing'),
            'retry_after' => (int) env('QUEUE_BILLING_RETRY_AFTER', 900),
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Mapa de carriles
    |--------------------------------------------------------------------------
    |
    | Lo consultan los propios jobs (para colocarse solos en su carril), el
    | detector de salud y el panel. Tenerlo en un sitio evita que el nombre de
    | una cola se escriba a mano en ocho ficheros y que uno de los ocho se
    | quede atrás el día que cambie.
    |
    | `slo_wait_ms` es el compromiso de espera en cola (p95) de cada carril.
    | IRON GUARD lo usa para decidir si algo va mal, y está escrito aquí en vez
    | de en el detector porque es una decisión de negocio, no de vigilancia:
    | medio segundo para el mensaje de un cliente, y todo el tiempo del mundo
    | para recalcular una analítica.
    */

    'lanes' => [
        'whatsapp' => ['connection' => 'whatsapp', 'queue' => env('QUEUE_WHATSAPP', 'whatsapp-high'), 'priority' => 0, 'slo_wait_ms' => 500],
        'agent' => ['connection' => 'agent', 'queue' => env('QUEUE_AGENT', 'agent'), 'priority' => 2, 'slo_wait_ms' => 30000],
        'media' => ['connection' => 'media', 'queue' => env('QUEUE_MEDIA', 'media'), 'priority' => 3, 'slo_wait_ms' => 60000],
        'commercial' => ['connection' => 'commercial', 'queue' => env('QUEUE_COMMERCIAL', 'commercial'), 'priority' => 4, 'slo_wait_ms' => 300000],
        'billing' => ['connection' => 'billing', 'queue' => env('FACTUS_QUEUE', 'billing'), 'priority' => 4, 'slo_wait_ms' => 300000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
