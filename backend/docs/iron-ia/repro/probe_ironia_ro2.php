<?php
// Sonda 2, SOLO LECTURA. Sin nombres, sin textos libres: solo cifras extraídas.
require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
DB::beginTransaction();
$q = fn (string $sql) => array_map(fn ($r) => (array) $r, DB::select($sql));
$out = [];

// Las rutinas IA de nivel intermedio: cuándo, con qué potencia y qué decía el brief.
$out['rutinas_intermedio'] = $q("select r.id, to_char(r.created_at,'YYYY-MM-DD HH24:MI') creada, r.days_per_week dias, r.status,
    ru.id run_id, ru.nutrition_power np, ru.routine_power rp, ru.prompt_version pv,
    ru.response_json::jsonb->'brief'->>'days_available' brief_dias, ru.response_json::jsonb->'brief'->>'days_source' brief_fuente,
    ru.response_json::jsonb->'brief'->>'training_level' brief_nivel
  from routines r
  left join lateral (select * from nutrition_ai_runs x where x.member_id = r.member_id and x.mode='integral_plan' and x.status='ok'
                     and x.created_at <= r.created_at + interval '5 seconds' order by x.created_at desc limit 1) ru on true
  where r.generated_by_ai = true and r.level = 'intermedio' order by r.id");

// Nivel 1: el «profile» del análisis. Solo el número que precede a «día(s)», nada más del texto.
$filas = DB::select("select id, to_char(created_at,'YYYY-MM-DD') dia, prompt_version pv,
    response_json::jsonb->'analysis'->>'profile' profile,
    jsonb_array_length(coalesce(response_json::jsonb->'routine'->'days','[]'::jsonb)) rutina_dias,
    response_json::jsonb->'routine'->>'level' nivel
  from nutrition_ai_runs where mode='integral_plan' and status='ok' and coalesce(routine_power,1)=1 and nutrition_power is not distinct from nutrition_power
  order by id desc limit 40");
$out['v1_profile_dias'] = array_map(function ($f) {
    $p = (string) $f->profile;
    preg_match_all('/(\d+|dos|tres|cuatro|cinco|seis)\s*(?:d[ií]as?|sesiones|veces)/iu', $p, $m);
    return ['run' => $f->id, 'dia' => $f->dia, 'pv' => $f->pv, 'nivel' => $f->nivel, 'rutina_dias' => $f->rutina_dias,
        'profile_menciona' => $m[0] ? implode(' | ', $m[0]) : (preg_match('/disponib/iu', $p) ? 'disponibilidad sin cifra' : '—')];
}, $filas);

DB::rollBack();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
