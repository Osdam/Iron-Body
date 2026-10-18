<?php

/*
|--------------------------------------------------------------------------
| ULTRON STUDIO (borradores, versiones, reglas y aprobaciones del agente)
|--------------------------------------------------------------------------
| Contrato: docs/ultron-studio/ULTRON_STUDIO_F_CONTRACT.md.
|
| EL MOTOR DE ULTRON NO LEE NADA DE ESTO. Ninguna clase de app/Services/Marketing,
| app/Jobs ni de las rutas internas consulta esta configuración ni las tablas
| `ultron_studio_*`; UltronEngineIsolationTest lo vigila con un grep.
*/

return [

    /*
    | Bandera de publicación. APAGADA. Aunque se encienda, publicar y revertir
    | siguen respondiendo 409 mientras StudioPublishing::ENGINE_READS_VERSIONS
    | sea false (`engine_not_reading_versions`): marcar «publicada» una versión
    | que el motor no lee sería mentir. La fase que conecte el motor cambia esa
    | constante en el mismo commit que el motor.
    */
    'publishing_enabled' => (bool) env('ULTRON_STUDIO_PUBLISHING_ENABLED', false),

];
