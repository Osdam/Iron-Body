<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

// Nivel por defecto seguro: en producción NO se registra en 'debug' (evita ruido
// y fugas de datos en logs); fuera de producción sí para depurar (BACK-011).
// Un LOG_LEVEL explícito en el .env siempre manda.
$defaultLogLevel = env('APP_ENV', 'production') === 'production' ? 'info' : 'debug';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', $defaultLogLevel),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', $defaultLogLevel),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        /*
         * Canal de WhatsApp (webhook, cola, agente, envíos) y IRON GUARD.
         *
         * Va aparte de laravel.log a propósito: es el único log que se lee como
         * DATOS. Una línea = un JSON con el mismo esqueleto (event, correlation_id,
         * duration_ms, status…), lo que permite agrupar incidentes con jq o con el
         * detector determinista sin escribir expresiones regulares frágiles sobre
         * prosa. Se escribe siempre a través de App\Services\Observability\ChannelLog,
         * que enmascara teléfonos y elimina secretos antes de llegar aquí.
         *
         * Rotación propia y más larga que la de laravel.log: cuando alguien
         * investiga por qué un prospecto no recibió respuesta, suele hacerlo días
         * después.
         */
        'channel' => [
            'driver' => 'daily',
            'path' => storage_path('logs/channel.log'),
            'level' => env('CHANNEL_LOG_LEVEL', 'info'),
            'days' => env('CHANNEL_LOG_DAYS', 30),
            // Este log lo escriben tres identidades distintas: php-fpm y los
            // workers como www-data, y el scheduler —que en este servidor
            // arrancaba desde el crontab de root—. Sin permiso de grupo, el
            // primero que crea el fichero del día deja fuera a los demás: eso
            // es lo que hacía que el webhook de Meta contestara 500 en vez de
            // 403. El 0664 es la mitad del arreglo; la otra es que el scheduler
            // no corra como root (ver docs/ops/ROTACION-CREDENCIALES.md).
            'permission' => 0664,
            'formatter' => Monolog\Formatter\JsonFormatter::class,
            'formatter_with' => [
                'batchMode' => Monolog\Formatter\JsonFormatter::BATCH_MODE_JSON,
                'appendNewline' => true,
            ],
            'replace_placeholders' => false,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
