<?php
// Sonda de SOLO LECTURA para el reporte de impacto de la recuperación de rutinas de Iron IA.
// Sesión READ ONLY + transacción que se revierte; savepoint por consulta. Solo agregados:
// ningún nombre, teléfono, correo ni texto libre de los socios sale de aquí.
require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
DB::beginTransaction();
$out = [];
$q = function (string $clave, string $sql) use (&$out) {
    DB::statement('SAVEPOINT s');
    try {
        $out[$clave] = array_map(fn ($r) => (array) $r, DB::select($sql));
        DB::statement('RELEASE SAVEPOINT s');
    } catch (Throwable $e) {
        DB::statement('ROLLBACK TO SAVEPOINT s');
        $out[$clave] = ['ERROR' => substr($e->getMessage(), 0, 300)];
    }
};

// 0. Esquema real de las tablas que usa el análisis.
$q('esquema', "select table_name, string_agg(column_name || ':' || data_type, ', ' order by ordinal_position) cols
  from information_schema.columns where table_schema = current_schema() and table_name in
  ('routines','member_routine_assignments','nutrition_ai_runs','nutrition_goals','members','users',
   'member_trainer_assignments','iron_ai_user_profiles','professional_assessments','workout_sessions',
   'routine_completions','membership_ai_capabilities','nutrition_guides')
  group by table_name order by table_name");

// 1. Corridas del plan integral.
$q('corridas_integral', "select status, routine_power, count(*) n, min(created_at) primera, max(created_at) ultima,
    sum(case when (response_json::jsonb -> 'trainer_context') is not null then 1 else 0 end) con_contexto,
    round(avg(total_tokens)) tokens_medios
  from nutrition_ai_runs where mode = 'integral_plan' group by 1,2 order by 2,1");

// Base: cada rutina de IA con su corrida (misma persona, creada durante la corrida).
$base = "with ia as (
    select r.id, r.member_id, r.status, r.is_assigned, r.is_template, r.days_per_week, r.created_at,
      case when r.days is null then null else jsonb_array_length(r.days::jsonb) end dias_json,
      (r.is_assigned or exists (select 1 from member_routine_assignments a where a.routine_id = r.id)) visible,
      run.id run_id, run.routine_power,
      ((run.response_json::jsonb -> 'trainer_context') is not null) con_contexto,
      coalesce(run.response_json::jsonb->'brief'->>'days_source', case when run.id is null then null else 'modelo_nivel_1' end) fuente_dias,
      nullif(trim(coalesce(run.response_json::jsonb->'analysis'->>'limitations', '')), '') is not null limitaciones_ia
    from routines r
    left join lateral (
      select x.* from nutrition_ai_runs x
      where x.mode = 'integral_plan' and x.member_id = r.member_id and x.status = 'ok'
        and x.created_at <= r.created_at + interval '5 seconds'
        and r.created_at <= x.updated_at + interval '5 seconds'
      order by x.created_at desc limit 1) run on true
    where r.generated_by_ai = true
  ),
  af as (select * from ia where run_id is not null and not con_contexto
         and created_at < timestamp '2026-11-14 05:00:00'),
  posterior as (
    select af.id, exists (
      select 1 from routines r2
      where r2.member_id = af.member_id and r2.id <> af.id and r2.created_at > af.created_at
        and coalesce(r2.is_template, false) = false
        and (r2.is_assigned or exists (select 1 from member_routine_assignments a2 where a2.routine_id = r2.id))
    ) tiene_posterior_visible
    from af)
";

// 2. Totales de rutinas.
$q('rutinas_totales', "select
    (select count(*) from routines where coalesce(is_template, false) = false) personalizadas_todas,
    (select count(*) from routines where generated_by_ai = true) ia_todas,
    (select min(created_at) from routines where generated_by_ai = true) ia_primera,
    (select max(created_at) from routines where generated_by_ai = true) ia_ultima");

$q('ia_por_origen', $base."select case when run_id is null then 'sin_corrida_integral'
      when con_contexto then 'corregida_con_contexto' else 'flujo_antiguo_sin_contexto' end origen,
    count(*) n, count(distinct member_id) socios from ia group by 1 order by 1");

// 3. Las potencialmente afectadas: estado, visibilidad, días y si ya hay algo posterior.
$q('afectadas_estado', $base."select status, visible, count(*) n from af group by 1,2 order by 1,2");
$q('afectadas_dias', $base."select coalesce(days_per_week::text, '?') dias_guardados, coalesce(dias_json::text, '?') dias_en_json, fuente_dias, count(*) n
  from af group by 1,2,3 order by 1,2,3");
$q('afectadas_resumen', $base."select count(*) rutinas, count(distinct member_id) socios,
    sum(case when visible then 1 else 0 end) visibles,
    sum(case when visible and not p.tiene_posterior_visible then 1 else 0 end) visibles_sin_posterior,
    sum(case when p.tiene_posterior_visible then 1 else 0 end) con_rutina_posterior_visible,
    sum(case when status = 'Retirada' then 1 else 0 end) retiradas,
    sum(case when status = 'Borrador' then 1 else 0 end) borradores,
    sum(case when coalesce(days_per_week, dias_json) >= 6 then 1 else 0 end) ya_seis_o_mas,
    sum(case when limitaciones_ia then 1 else 0 end) con_limitaciones_en_el_analisis
  from af join posterior p using (id)");

// 4. Por socio: lo que importa para la elegibilidad (vigencia, entrenador, meta, lesiones).
$q('socios_afectados', $base.", s as (select distinct member_id from af)
  select count(*) socios,
    sum(case when u.membership_end_date >= (now() at time zone 'America/Bogota')::date then 1 else 0 end) membresia_vigente,
    sum(case when exists (select 1 from member_trainer_assignments t where t.member_id = s.member_id and t.status = 'active') then 1 else 0 end) con_entrenador_activo,
    sum(case when g.training_days_per_week >= 6 then 1 else 0 end) meta_6_o_mas,
    sum(case when g.training_days_per_week between 1 and 5 then 1 else 0 end) meta_1_a_5,
    sum(case when g.training_days_per_week is null then 1 else 0 end) sin_meta_de_dias,
    sum(case when nullif(trim(coalesce(m.injuries, '')), '') is not null
              or nullif(trim(coalesce(p.injuries_summary, '')), '') is not null then 1 else 0 end) con_lesiones_registradas
  from s
  join members m on m.id = s.member_id
  left join users u on u.id = m.user_id
  left join lateral (select training_days_per_week from nutrition_goals ng
                     where ng.member_id = s.member_id and coalesce(ng.is_active, true)
                     order by ng.updated_at desc limit 1) g on true
  left join lateral (select injuries_summary from iron_ai_user_profiles ip
                     where ip.member_id = s.member_id order by ip.updated_at desc limit 1) p on true");

$q('meta_dias_fuente', $base.", s as (select distinct member_id from af)
  select ng.training_days_per_week dias, ng.source fuente, count(distinct ng.member_id) socios
  from s join nutrition_goals ng on ng.member_id = s.member_id and coalesce(ng.is_active, true)
  group by 1,2 order by 1,2");

// Menciones de «6/seis días» en las notas de la valoración: solo se CUENTAN, no se leen.
$q('notas_mencionan_6_dias', $base.", s as (select distinct member_id from af)
  select count(distinct pa.member_id) socios from s join professional_assessments pa on pa.member_id = s.member_id
  where coalesce(pa.observations, '') || ' ' || coalesce(pa.recommendations, '') ~* '(\\m6|\\mseis)\\s*(d[ií]as|veces)'");

// 5. Historial que la recuperación no puede tocar.
$q('historial_ligado', $base."select
    (select count(*) from workout_sessions w where w.routine_id in (select id from af)) sesiones,
    (select count(distinct w.routine_id) from workout_sessions w where w.routine_id in (select id from af)) rutinas_con_sesiones,
    (select count(*) from routine_completions c where c.routine_id in (select id from af)) completados");

// 6. Coste: potencia de las corridas afectadas y potencia actual de los planes.
$q('potencias_afectadas', $base."select routine_power, count(*) n from af group by 1 order by 1");
$q('potencias_planes', "select nutrition_power, routine_power, count(*) n from membership_ai_capabilities group by 1,2 order by 1,2");

DB::rollBack();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
