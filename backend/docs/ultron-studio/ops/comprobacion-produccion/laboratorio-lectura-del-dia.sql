-- LABORATORIO FRENTE AL MOTOR · las ejecuciones creadas desde una fecha (UTC), una línea JSON por caso. SOLO LECTURA
-- (se lanza con default_transaction_read_only=on; ver laboratorio-recuento.py). Son ejecuciones sintéticas del
-- laboratorio: no hay datos de personas. No se lee el nombre de quien las lanzó (solo su rol) ni el contenido gobernado de
-- la versión (solo las huellas del objetivo). Escrita para la línea base del 23-11-2026; para otra medición, CAMBIAR LA
-- FECHA del `where` (y, si ese día hubo otras ejecuciones, filtrarlas por `prompt_version like 'motor-%'` y la versión).
select row_to_json(x) from (
  select r.id as run_id, left(r.uuid::text, 8) as run8, r.status as run_status, r.stop_reason, r.target_kind, r.target_label, left(r.target_content_hash, 8) as content8,
         r.mode, r.model, r.prompt_version, r.catalog_version, r.case_count, r.estimate_expected_micros, r.estimate_max_micros, r.accepted_max_micros,
         r.cost_micros as run_cost, r.input_tokens as run_in, r.output_tokens as run_out, r.ai_recommendation, r.budget_day, r.created_by_role, r.created_by_admin_id,
         r.created_at, r.started_at, r.finished_at, r.summary, r.prices,
         (r.target_snapshot::jsonb -> 'engine') - 'governed' as engine_target,
         (select left(v.uuid::text, 8) from ultron_studio_versions v where v.id = r.target_version_id) as version8,
         c.id as case_id, c.position, c.scenario_code, c.scenario_revision, c.scenario_snapshot, c.status as case_status, c.outcome, c.error_code, c.reply,
         c.checks, c.judge, c.calls, c.reserved_micros, c.cost_micros as case_cost, c.overrun_micros, c.input_tokens as case_in, c.output_tokens as case_out,
         c.started_at as case_started, c.finished_at as case_finished, c.engine
  from ultron_studio_lab_runs r left join ultron_studio_lab_run_cases c on c.run_id = r.id
  where r.created_at >= '2026-11-23 00:00:00'
  order by r.id, c.position
) x;
