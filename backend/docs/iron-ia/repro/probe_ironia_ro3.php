<?php
// Sonda 3, SOLO LECTURA. Fidelidad nivel/objetivo y carreras de valoración. Solo categorías y recuentos.
require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
DB::beginTransaction();
$q = fn (string $sql) => array_map(fn ($r) => (array) $r, DB::select($sql));
$out = [];

$norm = "case when m.training_level is null or btrim(m.training_level) = '' then '(vacío)'
             when lower(m.training_level) like '%princip%' then 'principiante'
             when lower(m.training_level) like '%interm%' then 'intermedio'
             when lower(m.training_level) like '%avanz%' then 'avanzado'
             else 'otro' end";

// Nivel registrado en el perfil HOY vs nivel de la rutina generada (por potencia de la corrida).
$out['nivel_perfil_vs_rutina'] = $q("select $norm perfil, r.level rutina,
    coalesce(ru.routine_power,1) potencia, count(*) n
  from routines r join members m on m.id = r.member_id
  left join lateral (select x.routine_power from nutrition_ai_runs x where x.member_id = r.member_id and x.mode='integral_plan'
      and x.status='ok' and x.created_at <= r.created_at + interval '5 seconds' order by x.created_at desc limit 1) ru on true
  where r.generated_by_ai = true group by 1,2,3 order by 3,1,2");

// Socios con plan IA: cuántos tienen objetivo, nivel y lesiones registrados en el perfil.
$out['perfil_socios_con_rutina_ia'] = $q("select count(*) socios,
    count(*) filter (where coalesce(btrim(m.goal),'') <> '') con_objetivo,
    count(*) filter (where coalesce(btrim(m.training_level),'') <> '') con_nivel,
    count(*) filter (where coalesce(btrim(m.injuries),'') <> '') con_lesiones
  from members m where m.id in (select distinct member_id from routines where generated_by_ai = true)");

// Carrera: guía y rutina del MISMO plan apuntando a valoraciones distintas.
$out['guia_y_rutina_con_valoracion_distinta'] = $q("select count(*) n from routines r
  join nutrition_guides g on g.member_id = r.member_id and g.generated_by_ai = true
       and abs(extract(epoch from (g.created_at - r.created_at))) < 2
  where r.generated_by_ai = true and r.source_assessment_id is distinct from g.source_assessment_id");
$out['pares_guia_rutina_total'] = $q("select count(*) n from routines r
  join nutrition_guides g on g.member_id = r.member_id and g.generated_by_ai = true
       and abs(extract(epoch from (g.created_at - r.created_at))) < 2
  where r.generated_by_ai = true");

DB::rollBack();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
