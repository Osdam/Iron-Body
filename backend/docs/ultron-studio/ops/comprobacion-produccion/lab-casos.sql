-- SOLO LECTURA. Las ejecuciones del laboratorio de texto posteriores a la 3 (las del 12-11-2026 en adelante), con sus casos,
-- y los veredictos. Datos sintéticos: los escenarios no llevan personas. Se imprime con lab-casos.py:
--   ssh ironbody-vps 'cd /tmp && PGOPTIONS="-c default_transaction_read_only=on -c statement_timeout=20000" sudo -E -u postgres psql -At -F " | " -d ironbody -f -' < lab-casos.sql > salida.txt
--   python3 -I lab-casos.py salida.txt [id de la ejecución]
select 'RUN', row_to_json(t) from (select r.id, r.uuid, r.status, r.stop_reason, r.mode, r.model, r.prompt_version, r.case_count, r.ai_recommendation, r.cost_micros, r.target_kind, r.target_label, left(r.results_hash, 8) as results_hash, r.created_by_admin_id, r.created_at, r.finished_at, r.summary from ultron_studio_lab_runs r where r.id > 3 order by r.id) t;
select 'CASE', row_to_json(t) from (select r.id as run_id, r.uuid as run, c.position, c.scenario_code, c.status, c.outcome, c.error_code, c.reply, c.checks, c.judge from ultron_studio_lab_run_cases c join ultron_studio_lab_runs r on r.id = c.run_id where r.id > 3 order by r.id, c.position) t;
select 'VER', row_to_json(t) from (select v.id, v.run_id, v.decision, left(v.results_hash, 8) as results_hash, v.reviewer_admin_id, v.reviewer_name, v.acknowledged_cases, v.created_at from ultron_studio_lab_verdicts v order by v.id) t;
