<?php

/*
|--------------------------------------------------------------------------
| ULTRON STUDIO · Canal SSE único (Hito 9, paquete SSE9)
|--------------------------------------------------------------------------
| Contrato: docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md, «Paquete SSE9».
|
| Un solo `GET /api/admin/ultron-studio/stream?ticket=…&topics=a,b` por pestaña
| del CRM, con TEMAS por módulo (ledger, voice.*, monitor.incidents,
| outreach.*). Las API de lectura siguen siendo la fuente para la carga
| inicial y el respaldo cuando el canal está apagado o cae: el canal solo
| avisa de que algo cambió ({type, id, state, at[, hash|ref]}), nunca lleva
| datos personales ni importes.
|
| SOLO LEE: ninguna escritura en ninguna tabla y cero red saliente. Nace
| APAGADO; apagar la bandera es la reversión (409 `stream_disabled` y el CRM
| vuelve al sondeo). El motor de ULTRON no lee esto.
*/

/*
 * Un entero del entorno acotado a [min, max]; vacío o que no sea un entero →
 * el defecto. Así una línea `X=` no deja un latido a cero sin que nadie lo
 * decida (el mismo criterio que config/ultron_studio.php).
 */
$entero = static function (string $clave, int $defecto, int $min, int $max): int {
    $valor = trim((string) env($clave, ''));

    return preg_match('/^-?\d+$/', $valor) === 1 ? min($max, max($min, (int) $valor)) : $defecto;
};

return [

    /*
    | La bandera. Solo `true` (o 1/on/yes) la enciende: `false`, `off`, `no`,
    | vacío o cualquier otro texto la dejan apagada (lección M-1 de H5:
    | `(bool) env()` daba `off` por encendida). Apagada, la ruta responde
    | 409 `stream_disabled` a quien tiene permiso; la puerta (permiso) y la
    | persona se exigen ANTES, para no revelar la bandera a quien no entra.
    */
    'enabled' => filter_var(env('ULTRON_STUDIO_STREAM_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | El compás de la conexión (contrato: latido `: ping` cada 15 s, conexión
    | máxima de 55 s; el cliente reconecta con retroceso exponencial de 1 a
    | 30 s). El sondeo interno (`poll_ms`) marca la latencia máxima de un
    | evento: 1,5 s deja margen a los 2 s de la prueba T24. Se acotan aquí
    | para que la caché de configuración ya los lleve.
    */
    'heartbeat_seconds' => $entero('ULTRON_STUDIO_STREAM_HEARTBEAT_SECONDS', 15, 5, 30),
    'max_seconds' => $entero('ULTRON_STUDIO_STREAM_MAX_SECONDS', 55, 10, 55),
    'poll_ms' => $entero('ULTRON_STUDIO_STREAM_POLL_MS', 1500, 250, 5000),

    /*
    | Lo que un tema entrega por sondeo (`since(cursor, limit)`): filas por
    | encima, en el sondeo siguiente. Acota cada consulta por cursor.
    */
    'batch_limit' => 50,

    /*
    | El `retry:` que se le dicta al navegador (ms) cuando la conexión se corta
    | sin que el servidor la cierre. El cierre a los 55 s lo maneja el cliente
    | con su propio retroceso.
    */
    'retry_ms' => 3000,

    /*
    | Las fuentes de IRON GUARD que enseña el tema `monitor.incidents`: el
    | monitor del Studio (H7) y el de las estrategias (H9). Lista cerrada en
    | código, no en el entorno.
    */
    'incident_sources' => ['ultron_studio', 'outreach'],

    /*
    | El limitador con nombre `studio-stream` (como `voice-admin-read`): por
    | TIQUE (o por persona si llega con la cabecera), y un cubo propio por red
    | para quien no trae credencial válida. Una pestaña abre una conexión cada
    | 55 s y reconecta con retroceso: 30 por minuto deja sitio a varias
    | pestañas y corta un bucle de reconexión roto.
    */
    'rate' => [
        'per_minute' => $entero('ULTRON_STUDIO_STREAM_PER_MINUTE', 30, 5, 120),
        'anonymous_per_minute' => 10,
    ],

];
