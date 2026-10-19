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

/*
 * Un entero del entorno; vacío o que no sea un entero → el defecto. Así una
 * línea `X=` en el .env no deja un tope a cero sin que nadie lo decida.
 */
$entero = static function (string $clave, int $defecto): int {
    $valor = trim((string) env($clave, ''));

    return preg_match('/^-?\d+$/', $valor) === 1 ? (int) $valor : $defecto;
};

return [

    /*
    | Bandera de publicación. APAGADA. Aunque se encienda, publicar y revertir
    | siguen respondiendo 409 mientras StudioPublishing::ENGINE_READS_VERSIONS
    | sea false (`engine_not_reading_versions`): marcar «publicada» una versión
    | que el motor no lee sería mentir. La fase que conecte el motor cambia esa
    | constante en el mismo commit que el motor.
    */
    'publishing_enabled' => (bool) env('ULTRON_STUDIO_PUBLISHING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Laboratorio de IA (Hito 4)
    |--------------------------------------------------------------------------
    | Contrato: docs/ultron-studio/ULTRON_STUDIO_H4_CONTRACT.md, §A1.
    |
    | SIMULACIÓN DEL STUDIO, NO ES ULTRON: un actor de laboratorio interpreta la
    | versión sobre escenarios sintéticos y un juez opina; el motor no lee nada
    | de esto y el laboratorio no usa la clave, el modelo ni la configuración
    | del motor ni de IRON IA.
    |
    | CUATRO CERROJOS ANTES DE GASTAR, independientes: la bandera apagada, el
    | driver `fake`, el presupuesto diario 0 y la clave ausente o compartida. Lo
    | interpreta StudioLabConfig, que vuelve a aplicar los topes duros (aquí se
    | aplican al leer el entorno para que la caché de configuración ya los
    | lleve) y NUNCA devuelve ni registra la clave.
    */
    'lab' => [

        // Apagada: todas las rutas `lab/*` responden 409 `lab_disabled`, salvo `GET lab/status`.
        'enabled' => (bool) env('ULTRON_STUDIO_LAB_ENABLED', false),

        // `fake` (sin red, coste 0) u `openai`. Cualquier otro valor deja el
        // laboratorio sin configurar (`invalid_driver`): nunca se cae en
        // silencio de uno a otro.
        'driver' => strtolower(trim((string) env('ULTRON_STUDIO_LAB_DRIVER', 'fake'))),

        'openai' => [
            // La clave PROPIA del laboratorio (un proyecto de OpenAI con su
            // presupuesto). Solo la lee el cliente, en el momento de llamar.
            'api_key' => env('ULTRON_STUDIO_LAB_OPENAI_API_KEY'),

            // La huella (sha256) de la clave que el laboratorio NO puede usar:
            // la de OpenAI que ya usan IRON IA y el cerebro legado. Se calcula
            // aquí para que ningún servicio del laboratorio tenga que leer la
            // configuración de otros (una prueba por tokens lo vigila). Solo
            // sirve para comparar: igual a la del laboratorio → `shared_key`.
            'forbidden_key_sha256' => ($otra = trim((string) env('OPENAI_API_KEY', ''))) !== '' ? hash('sha256', $otra) : null,

            // Los elige el propietario con la página oficial de precios.
            'model' => env('ULTRON_STUDIO_LAB_OPENAI_MODEL'),

            // Vacías: no se envían. Que los modelos de razonamiento rechacen
            // `temperature` es una suposición: se comprueba con el modelo elegido.
            'temperature' => env('ULTRON_STUDIO_LAB_OPENAI_TEMPERATURE'),
            'reasoning_effort' => env('ULTRON_STUDIO_LAB_OPENAI_REASONING_EFFORT'),

            // SE IGNORA EN `production`, donde la URL es siempre
            // https://api.openai.com. Fuera de producción apunta al simulador
            // local del E2E.
            'base_url' => env('ULTRON_STUDIO_LAB_OPENAI_BASE_URL'),
        ],

        // US$ por millón de tokens, decimal con hasta 6 cifras (`0.40`). Sin
        // precio no hay estimación (`missing_price`).
        'price_input_usd_per_mtok' => env('ULTRON_STUDIO_LAB_PRICE_INPUT_USD_PER_MTOK'),
        'price_output_usd_per_mtok' => env('ULTRON_STUDIO_LAB_PRICE_OUTPUT_USD_PER_MTOK'),

        // Con 0 no se permite ninguna ejecución de pago (`zero_budget`). El día
        // del presupuesto es el de America/Bogota.
        'daily_budget_usd' => env('ULTRON_STUDIO_LAB_DAILY_BUDGET_USD', '0'),
        'run_budget_usd' => env('ULTRON_STUDIO_LAB_RUN_BUDGET_USD', '0.50'),

        // Cuenta también las ejecuciones `fake`.
        'max_runs_per_day' => max(0, $entero('ULTRON_STUDIO_LAB_MAX_RUNS_PER_DAY', 20)),

        // Topes duros: 20 casos, 800 y 1600 tokens de salida, 60 s (el job tiene 150).
        'max_cases_per_run' => min(20, max(1, $entero('ULTRON_STUDIO_LAB_MAX_CASES_PER_RUN', 12))),
        'actor_max_output_tokens' => min(800, max(16, $entero('ULTRON_STUDIO_LAB_ACTOR_MAX_OUTPUT_TOKENS', 400))),
        'judge_max_output_tokens' => min(1600, max(16, $entero('ULTRON_STUDIO_LAB_JUDGE_MAX_OUTPUT_TOKENS', 900))),
        'timeout_seconds' => min(60, max(10, $entero('ULTRON_STUDIO_LAB_TIMEOUT_SECONDS', 60))),

    ],

];
