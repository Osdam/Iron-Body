<?php

/*
|--------------------------------------------------------------------------
| Autonomía comercial (Hito 9, B9-run): la EJECUCIÓN, TODO APAGADO
|--------------------------------------------------------------------------
| Contrato: docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md (§3 protección 1,
| §9.1, D9-14, L9-44). Informe: plans/informes-h9/informe-run.md.
|
| TODO NACE APAGADO O EN CERO: la maestra en `false`, el modo en `off`, el
| programador y el monitor apagados, los canales apagados y los cupos y
| presupuestos en 0. Solo `true`, `1`, `on` o `yes` encienden (el mismo
| `filter_var` que config/voice.php y config/ultron_studio.php: `off`, `no`,
| vacío o cualquier otro texto dejan apagado). Con estos defectos ninguna
| orden `outreach:*` hace trabajo (salen con 0 y lo dicen) y nada se programa.
|
| Las claves son las que el mando de OPS9 (`ops/h9-outreach-scope.sh`) lee y
| escribe (L9-44): `enabled`, `mode`, `scheduler.enabled`, `monitor.enabled`,
| `assisted.{enabled,daily_cap}`, `whatsapp.{enabled,daily_cap,daily_budget_usd}`,
| `voice.{enabled,daily_cap,daily_budget_usd,reserve_usd_per_call}`,
| `classifier.enabled` y `own_hours`. Cambiarlas de nombre rompe el mando: se
| cambian a la vez o no se cambian.
|
| Encima de todas estas banderas está la CONSTANTE DE CÓDIGO
| `App\Services\Outreach\Run\OutreachSendGate::SENDS_ENABLED_IN_CODE = false`
| (protección 2): mientras sea `false`, el modo `live` solo admite el canal
| asistido y el simulado; ningún mensaje ni llamada automática sale hacia una
| persona, diga lo que diga este fichero. Solo se gira en un commit de una
| línea `outreach(sends-open): …` con la autorización escrita del propietario.
|
| Lo interpreta `App\Services\Outreach\Run\OutreachConfig`, que vuelve a
| aplicar las reglas de forma (aquí se aplican al leer el entorno para que la
| caché de configuración ya las lleve). El motor de ULTRON no lee esto.
*/

/*
 * Un entero del entorno acotado a [min, max]; vacío o que no sea un entero →
 * el defecto. Así una línea `X=` no deja un cupo en cualquier cosa sin que
 * nadie lo decida.
 */
$entero = static function (string $clave, int $defecto, int $min, int $max): int {
    $valor = trim((string) env($clave, ''));

    return preg_match('/^-?\d+$/', $valor) === 1 ? min($max, max($min, (int) $valor)) : $defecto;
};

/*
 * Un importe en dólares con hasta 6 decimales (centésimas de céntimo), como
 * texto; vacío o ilegible → '0'. Se convierte a micros al leerlo.
 */
$dolares = static function (string $clave): string {
    $valor = trim((string) env($clave, ''));

    return preg_match('/^\d{1,6}(\.\d{1,6})?$/', $valor) === 1 ? $valor : '0';
};

/*
 * El modo: `off`, `shadow` o `live`; cualquier otro valor es `off`
 * (protección 1). En minúsculas y sin espacios alrededor.
 */
$modo = strtolower(trim((string) env('COMMERCIAL_AUTOMATION_MODE', 'off')));

return [

    // La maestra. Apagada, ninguna orden `outreach:*` hace trabajo.
    'enabled' => filter_var(env('COMMERCIAL_AUTOMATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // `off` | `shadow` (todo simulado: decide y registra, nada sale) | `live`.
    'mode' => in_array($modo, ['off', 'shadow', 'live'], true) ? $modo : 'off',

    // El bloque propio de routes/console.php (OPS9) solo programa con esto encendido.
    'scheduler' => [
        'enabled' => filter_var(env('COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // `outreach:monitor`: SOLO apaga (abre frenos e incidentes). Nunca suelta nada.
    'monitor' => [
        'enabled' => filter_var(env('COMMERCIAL_AUTOMATION_MONITOR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Umbrales de autoapertura (P9-20), en tanto por mil: opt-out > 5 % en 24 h por estrategia; fallos > 20 % por canal.
        'opt_out_threshold_permille' => $entero('COMMERCIAL_AUTOMATION_OPT_OUT_PERMILLE', 50, 1, 1000),
        'failure_threshold_permille' => $entero('COMMERCIAL_AUTOMATION_FAILURE_PERMILLE', 200, 1, 1000),
        // Mínimo de trabajos que salieron en 24 h para que una tasa signifique algo (una muestra de 1 no abre frenos).
        'min_sample' => $entero('COMMERCIAL_AUTOMATION_MONITOR_MIN_SAMPLE', 5, 1, 1000),
    ],

    // El canal asistido (una persona ejecuta lo que ULTRON decidió), solo en `live`.
    'assisted' => [
        'enabled' => filter_var(env('COMMERCIAL_AUTOMATION_ASSISTED_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'daily_cap' => $entero('COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP', 0, 0, 10000),
    ],

    // WhatsApp por plantillas (W9): la puerta no existe el 15-11; con la constante cerrada, nunca sale.
    'whatsapp' => [
        'enabled' => filter_var(env('COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'daily_cap' => $entero('COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_CAP', 0, 0, 100000),
        'daily_budget_usd' => $dolares('COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_BUDGET_USD'),
        // Precio por mensaje entregado según la categoría de Meta [por confirmar las tarifas de Colombia, W9]. 0 = sin precio = `budget_zero`.
        'price_marketing_usd' => $dolares('OUTREACH_WHATSAPP_PRICE_MARKETING_USD'),
        'price_utility_usd' => $dolares('OUTREACH_WHATSAPP_PRICE_UTILITY_USD'),
    ],

    // Voz por estrategia (V9, Enmienda 17 del H8, L9-81): además rigen todas las del H8.
    'voice' => [
        'enabled' => filter_var(env('COMMERCIAL_AUTOMATION_VOICE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // El cupo diario de llamadas de la automatización (0–20, el recorte de TWILIO_VOICE_DAILY_CALL_CAP del H8). 0 = `budget_zero`.
        'daily_cap' => $entero('COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP', 0, 0, 20),
        // El techo diario de gasto en dólares de la voz de la automatización. 0 = `budget_zero`.
        'daily_budget_usd' => $dolares('COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD'),
        // Lo que se reserva por llamada al planificar (la reserva del H8, VoiceCostGuard, pide ≥ 0,97 US$ con los precios de E16-8).
        // 0 = sin reserva: el H9 no puede contar lo que gasta una llamada y la voz queda en `budget_zero` (falla cerrado).
        'reserve_usd_per_call' => $dolares('COMMERCIAL_AUTOMATION_VOICE_RESERVE_USD'),
    ],

    // El clasificador de respuestas (AI9): solo frena o deriva. Apagado.
    'classifier' => [
        'enabled' => filter_var(env('COMMERCIAL_AUTOMATION_CLASSIFIER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | El horario PROPIO (P9-17, L9-15), solo más estricto que el legal (la
    | ley ∩ el silencio: de lunes a viernes de 08:00 a 19:00 y sábados de 08:00
    | a 15:00). Vacío = el legal. `HH:MM-HH:MM` vale para todos los días; con
    | `;` se separa el de los sábados (`09:00-18:00;09:00-13:00`); `-` como
    | segundo tramo cierra los sábados. Un valor ilegible NO amplía nada: la
    | ejecución lo trata como «todo fuera» (falla cerrado, DF-W4).
    */
    'own_hours' => trim((string) env('COMMERCIAL_AUTOMATION_OWN_HOURS', '')),

    /*
    | Los límites del presupuesto de contacto que el H9 solo puede ENDURECER
    | (D9-7): el suelo es el del motor comercial y la voz (hoy 2 a la semana y
    | 48 h). Vacío = el suelo.
    */
    'limits' => [
        'per_week' => $entero('COMMERCIAL_AUTOMATION_PER_WEEK', 0, 0, 2) ?: null,
        'min_hours_between' => $entero('COMMERCIAL_AUTOMATION_MIN_HOURS_BETWEEN', 0, 0, 720) ?: null,
    ],

    // P9-7, L9-20: sin contacto comercial a quien tenga cartera vencida de MÁS de N días (la estrategia solo lo baja).
    'collections' => [
        'commercial_block_days' => $entero('COMMERCIAL_AUTOMATION_COLLECTIONS_BLOCK_DAYS', 15, 0, 365),
    ],

    // L9-41: `human_requested` reciente = 30 días, el mismo valor que la simulación del Studio.
    'human_requested_days' => 30,

    /*
    | Las pasadas (D9-8): ≤ 500 personas por estrategia y pasada; ≤ 50 trabajos
    | por pasada del despachador; un trabajo reclamado o en despacho con más de
    | 10 min es ambiguo (la conciliación).
    */
    'sweep' => ['batch' => $entero('COMMERCIAL_AUTOMATION_SWEEP_BATCH', 500, 1, 500)],
    'dispatch' => ['batch' => $entero('COMMERCIAL_AUTOMATION_DISPATCH_BATCH', 50, 1, 50)],
    'reconcile' => ['stale_minutes' => 10],

    // El carril de cola del despacho (config/queue.php `lanes.commercial`, P4): el mismo que el motor comercial, sin nombrarlo.
    'queue' => ['lane' => 'commercial'],

];
