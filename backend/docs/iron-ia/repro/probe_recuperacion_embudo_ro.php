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
$ctx = $base.", eleg as (
    select af.*, m.user_id, u.membership_end_date,
      (u.membership_end_date >= (now() at time zone 'America/Bogota')::date) vigente,
      (select t.trainer_id from member_trainer_assignments t where t.member_id = af.member_id and t.status = 'active' order by t.id desc limit 1) entrenador_activo,
      (select r.trainer_id from routines r where r.id = af.id) entrenador_original,
      (nullif(trim(coalesce(m.injuries, '')), '') is not null or exists (select 1 from iron_ai_user_profiles ip where ip.member_id = af.member_id and nullif(trim(coalesce(ip.injuries_summary, '')), '') is not null)) lesiones,
      p.tiene_posterior_visible
    from af join posterior p using (id) join members m on m.id = af.member_id left join users u on u.id = m.user_id)
";
$q('embudo', $ctx."select
    count(*) afectadas,
    sum(case when visible then 1 else 0 end) visibles,
    sum(case when visible and not tiene_posterior_visible then 1 else 0 end) visibles_sin_posterior,
    sum(case when visible and not tiene_posterior_visible and vigente then 1 else 0 end) mas_membresia_vigente,
    sum(case when visible and not tiene_posterior_visible and vigente and entrenador_activo is not null then 1 else 0 end) mas_entrenador_activo,
    count(distinct case when visible and not tiene_posterior_visible and vigente and entrenador_activo is not null then member_id end) socios_candidatos,
    count(distinct case when visible and not tiene_posterior_visible and vigente and entrenador_activo is not null then entrenador_activo end) entrenadores_que_revisarian,
    sum(case when visible and not tiene_posterior_visible and vigente and entrenador_activo is not null and entrenador_activo <> entrenador_original then 1 else 0 end) cambio_de_entrenador,
    sum(case when visible and not tiene_posterior_visible and vigente and entrenador_activo is not null and lesiones then 1 else 0 end) candidatas_con_lesiones,
    sum(case when visible and tiene_posterior_visible then 1 else 0 end) visibles_ya_sustituidas,
    sum(case when not visible and tiene_posterior_visible then 1 else 0 end) borradores_con_posterior,
    sum(case when not visible and not tiene_posterior_visible then 1 else 0 end) borradores_sin_posterior,
    sum(case when visible and not tiene_posterior_visible and not coalesce(vigente, false) then 1 else 0 end) visibles_membresia_vencida
  from eleg");
$q('candidatas_dias', $ctx."select days_per_week dias, count(*) n from eleg
  where visible and not tiene_posterior_visible and vigente and entrenador_activo is not null group by 1 order by 1");
$q('candidatas_por_socio', $ctx."select n_rutinas, count(*) socios from (select member_id, count(*) n_rutinas from eleg
  where visible and not tiene_posterior_visible and vigente and entrenador_activo is not null group by 1) x group by 1 order by 1");
$q('potencia_actual_candidatos', $ctx.", c as (select distinct member_id, user_id from eleg where visible and not tiene_posterior_visible and vigente and entrenador_activo is not null)
  select coalesce(mac.routine_power::text, 'sin_plan_con_potencia') potencia, count(*) socios
  from c join users u on u.id = c.user_id left join plans pl on pl.name = u.plan
  left join membership_ai_capabilities mac on mac.plan_id = pl.id group by 1 order by 1");
$q('afectadas_despues_del_arreglo_backend', $base."select count(*) n, count(distinct member_id) socios, max(created_at) ultima from af where created_at >= timestamp '2026-11-12 12:15:00'");
$q('corregidas', $base."select count(*) n, count(distinct member_id) socios, min(created_at) primera from ia where con_contexto");
$q('modelo', "select model, count(*) n from nutrition_ai_runs where mode = 'integral_plan' and status = 'ok' group by 1");
$q('mac_cols', "select string_agg(column_name, ', ') from information_schema.columns where table_name = 'membership_ai_capabilities'");

DB::rollBack();
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
