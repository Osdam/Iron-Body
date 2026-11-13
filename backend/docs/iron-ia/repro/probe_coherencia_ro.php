<?php
// SOLO LECTURA. Calibra la validación muscular: para cada rutina de IA, qué parte de los
// ejercicios de cada día pertenece a los grupos que le tocaban según el reparto vigente
// (IntegralPlanStrategy::split + tagsOf, llamados tal cual por reflexión). Solo agregados.
require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Trainer\IntegralPlanStrategy;
use Illuminate\Support\Facades\DB;

DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
DB::beginTransaction();

$estrategia = app(IntegralPlanStrategy::class);
$tagsOf = new ReflectionMethod($estrategia, 'tagsOf');
$tagsOf->setAccessible(true);

$catalogo = [];
foreach (DB::table('exercises')->get(['id', 'muscle_group', 'body_part', 'target']) as $e) {
    $catalogo[(int) $e->id] = $tagsOf->invoke($estrategia, ['muscle_group' => $e->muscle_group ?: $e->body_part, 'target' => $e->target]);
}

$rutinas = DB::select("select r.id, r.level, r.days::text dias,
    (select x.routine_power from nutrition_ai_runs x where x.mode = 'integral_plan' and x.member_id = r.member_id and x.status = 'ok'
       and x.created_at <= r.created_at + interval '5 seconds' and r.created_at <= x.updated_at + interval '5 seconds'
       order by x.created_at desc limit 1) potencia
  from routines r where r.generated_by_ai = true");

$res = [];
$sinCatalogo = 0;
foreach ($rutinas as $r) {
    $dias = json_decode((string) $r->dias, true) ?: [];
    if ($dias === []) {
        continue;
    }
    $nivel = in_array($r->level, ['principiante', 'intermedio', 'avanzado'], true) ? $r->level : 'intermedio';
    $plan = $estrategia->split(count($dias), $nivel);
    $min = 1.0;
    $suma = 0;
    $n = 0;
    foreach (array_values($dias) as $i => $d) {
        $esperados = $plan[$i][2] ?? [];
        $ejs = $d['exercises'] ?? [];
        $total = 0;
        $bien = 0;
        foreach ($ejs as $e) {
            $id = (int) ($e['exercise_id'] ?? 0);
            if (! isset($catalogo[$id])) {
                $sinCatalogo++;
                continue;
            }
            $total++;
            if (array_intersect($catalogo[$id], $esperados) !== []) {
                $bien++;
            }
        }
        $cuota = $total > 0 ? $bien / $total : 0.0;
        $min = min($min, $cuota);
        $suma += $cuota;
        $n++;
    }
    $clave = $r->potencia === null ? 'nivel1_previo' : 'nivel'.$r->potencia;
    $tramo = $min < 0.4 ? 'a_<0.4' : ($min < 0.6 ? 'b_0.4-0.6' : ($min < 0.8 ? 'c_0.6-0.8' : 'd_>=0.8'));
    $res[$clave]['rutinas'] = ($res[$clave]['rutinas'] ?? 0) + 1;
    $res[$clave]['peor_dia'][$tramo] = ($res[$clave]['peor_dia'][$tramo] ?? 0) + 1;
    $res[$clave]['media_dias'][] = round($suma / max(1, $n), 2);
}
foreach ($res as $k => $v) {
    sort($v['media_dias']);
    $res[$k]['media_dias'] = ['min' => $v['media_dias'][0], 'mediana' => $v['media_dias'][intdiv(count($v['media_dias']), 2)], 'max' => end($v['media_dias'])];
    ksort($res[$k]['peor_dia']);
}
ksort($res);
DB::rollBack();
echo json_encode(['ejercicios_fuera_del_catalogo' => $sinCatalogo, 'por_potencia' => $res], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
