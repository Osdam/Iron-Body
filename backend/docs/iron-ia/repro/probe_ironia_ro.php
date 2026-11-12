<?php
// Sonda de SOLO LECTURA (sesión READ ONLY + rollback). Solo agregados, sin datos personales.
require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
DB::beginTransaction();
$q = fn (string $sql) => array_map(fn ($r) => (array) $r, DB::select($sql));
$out = [];

$out['powers_por_plan'] = $q("select nutrition_power, routine_power, count(*) n from membership_ai_capabilities group by 1,2 order by 1,2");

$out['corridas_integral_45d'] = $q("select status, nutrition_power np, routine_power rp, count(*) n,
    round(percentile_cont(0.5) within group (order by latency_ms)::numeric) p50_ms,
    round(percentile_cont(0.95) within group (order by latency_ms)::numeric) p95_ms,
    max(latency_ms) max_ms
  from nutrition_ai_runs where mode='integral_plan' and created_at > now() - interval '45 days'
  group by 1,2,3 order by 2,3,1");

$out['corridas_integral_total'] = $q("select count(*) n, min(created_at) primera, max(created_at) ultima from nutrition_ai_runs where mode='integral_plan'");

$out['brief_dias_fuente'] = $q("select response_json::jsonb->'brief'->>'days_source' fuente, response_json::jsonb->'brief'->>'days_available' dias, count(*) n
  from nutrition_ai_runs where mode='integral_plan' and response_json is not null and (response_json::jsonb->'brief') is not null group by 1,2 order by 1,2");

$out['rutinas_ia_nivel_dias'] = $q("select level, days_per_week, count(*) n from routines where generated_by_ai = true group by 1,2 order by 1,2");

$out['rutinas_ia_ultimas'] = $q("select r.id, to_char(r.created_at,'YYYY-MM-DD HH24:MI') creada, r.level, r.days_per_week dias,
    jsonb_array_length(coalesce(r.days::jsonb,'[]'::jsonb)) dias_json, r.status, r.is_assigned publicada,
    (select g.training_days_per_week from nutrition_goals g where g.member_id = r.member_id and g.is_active order by g.id desc limit 1) meta_dias,
    (select ru.routine_power from nutrition_ai_runs ru where ru.member_id = r.member_id and ru.mode='integral_plan' and ru.created_at <= r.created_at order by ru.id desc limit 1) potencia_rutina
  from routines r where r.generated_by_ai = true order by r.id desc limit 15");

$out['metas_activas_dias_socios_con_plan'] = $q("select g.training_days_per_week dias, count(*) n from nutrition_goals g
  where g.is_active and g.member_id in (select distinct member_id from nutrition_ai_runs where mode='integral_plan') group by 1 order by 1");

DB::rollBack();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
