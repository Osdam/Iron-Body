<?php
// SOLO LECTURA: cuántos ejercicios del catálogo que ve Iron IA no tienen grupo clasificable.
require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
DB::beginTransaction();
$e = app(App\Services\Trainer\IntegralPlanStrategy::class);
$m = new ReflectionMethod($e, 'tagsOf'); $m->setAccessible(true);
$cat = app(App\Services\Trainer\EligibleExerciseCatalog::class)->forPrompt();
$sin = 0; $grupos = []; $porTag = [];
foreach ($cat as $x) {
    $t = $m->invoke($e, $x);
    if ($t === []) { $sin++; $grupos[(string) ($x['muscle_group'] ?? '')] = ($grupos[(string) ($x['muscle_group'] ?? '')] ?? 0) + 1; }
    foreach ($t as $k) { $porTag[$k] = ($porTag[$k] ?? 0) + 1; }
}
arsort($grupos); ksort($porTag);
DB::rollBack();
echo json_encode(['catalogo' => count($cat), 'sin_grupo' => $sin, 'grupos_sin_clasificar' => array_slice($grupos, 0, 8, true), 'por_grupo' => $porTag], JSON_UNESCAPED_UNICODE), "\n";
