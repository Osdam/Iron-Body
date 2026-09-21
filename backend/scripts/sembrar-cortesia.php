<?php
/*
 * Siembra en produccion SOLO lo que V4 necesita. Dos filas, las dos aditivas.
 *
 * Lo que NO hace y es deliberado: correr el seeder entero. El repositorio
 * tiene 26 items y produccion 17, y uno de esos 17 (escalation.rules) esta
 * en una version anterior. Correr el seeder completo arrastraria nueve altas
 * y una reescritura que nadie ha pedido, la vispera de una demo. Eso se
 * decide aparte.
 *
 * 1. `courtesy.day` sale del PROPIO seeder desplegado, por reflexion, para que
 *    la fila de produccion sea identica al repositorio sin teclear el texto
 *    otra vez. origin=seeder es origen de confianza, asi que nace aprobada.
 * 2. `windows` en `schedule.opening_hours`: NO es horario nuevo. Es el horario
 *    ya aprobado que vive en el `content` de esa misma fila, escrito en la
 *    forma que `CourtesyAuthority` sabe leer. Una sola fuente para la frase
 *    que lee la persona y para el dato que valida la hora.
 */

use App\Models\MarketingKnowledgeItem;
use Database\Seeders\MarketingKnowledgeSeeder;

$seeder = new MarketingKnowledgeSeeder;
$m = new ReflectionMethod($seeder, 'items');
$m->setAccessible(true);

$cortesia = null;
foreach ($m->invoke($seeder) as $item) {
    if ($item['key'] === 'courtesy.day') {
        $cortesia = $item;
    }
}
if ($cortesia === null) {
    echo "ABORTA: el seeder desplegado no trae courtesy.day\n";

    return;
}

$fila = MarketingKnowledgeItem::updateOrCreate(
    ['key' => 'courtesy.day'],
    [
        'category' => $cortesia['category'],
        'title' => $cortesia['title'],
        'content' => $cortesia['content'],
        'priority' => $cortesia['priority'] ?? 100,
        'is_active' => true,
        'source' => 'seeder',
        'origin' => MarketingKnowledgeItem::ORIGIN_SEEDER,
    ],
);
echo 'courtesy.day  id='.$fila->id.'  review='.$fila->review_status.'  activo='.(int) $fila->is_active."\n";

// Lunes a viernes 5:00 a. m. a 10:00 p. m.; sabados, domingos y festivos de
// 8:00 a. m. a 2:00 p. m. Los festivos NO se distinguen: caen en la ventana
// del dia de la semana que les toque. Queda dicho, no escondido.
$ventanas = [
    'lunes' => ['05:00', '22:00'],
    'martes' => ['05:00', '22:00'],
    'miercoles' => ['05:00', '22:00'],
    'jueves' => ['05:00', '22:00'],
    'viernes' => ['05:00', '22:00'],
    'sabado' => ['08:00', '14:00'],
    'domingo' => ['08:00', '14:00'],
];

$horario = MarketingKnowledgeItem::where('key', 'schedule.opening_hours')->first();
if ($horario === null) {
    echo "ABORTA: no existe schedule.opening_hours en produccion\n";

    return;
}

$meta = (array) ($horario->metadata ?? []);
$meta['windows'] = $ventanas;
$horario->metadata = $meta;
$horario->save();

echo 'schedule.opening_hours  id='.$horario->id.'  review='.$horario->review_status.'  activo='.(int) $horario->is_active."\n";
echo 'windows='.json_encode($horario->fresh()->metadata['windows'] ?? null)."\n";
