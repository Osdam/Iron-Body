\pset format unaligned
\pset fieldsep ' | '
select count(*) as citas, count(professional_assessment_id) as con_valoracion_enlazada,
       count(*) filter (where status = 'scheduled' and starts_at < now() at time zone 'UTC') as programadas_ya_pasadas,
       count(*) filter (where status = 'completed') as completadas
  from valuation_appointments;
select status, count(*) from valuation_appointments group by 1 order by 2 desc;
select column_name from information_schema.columns where table_name = 'valuation_settings' and column_name ilike '%member_app%';
