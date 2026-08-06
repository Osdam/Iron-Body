<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Adjuntos de WhatsApp. Disco PRIVADO y separado del resto: fotos,
         * notas de voz y documentos que manda un prospecto no son contenido
         * público y no deben poder servirse por URL directa nunca.
         *
         * Se eligió disco local frente a MinIO o S3 tras medir el servidor:
         * 184 GB libres y 4 vCPU para un solo gimnasio. Levantar MinIO añadiría
         * un contenedor, su RAM, su backup y su superficie de ataque para
         * resolver un problema de escala que este negocio no tiene. Cuando
         * llegue —si llega—, WHATSAPP_MEDIA_DISK apunta a un disco S3 y el
         * resto del código no cambia: nada fuera de esta capa conoce la ruta.
         */
        'whatsapp' => [
            'driver' => 'local',
            'root' => storage_path('app/private/whatsapp'),
            // Sin 'url' y sin visibilidad pública a propósito: el acceso pasa
            // SIEMPRE por el controlador, que valida sesión, permiso y firma.
            'visibility' => 'private',
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
