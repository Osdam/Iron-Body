\pset format unaligned
\pset fieldsep ' | '
select member_app_enabled, updated_at from valuation_settings;
select count(*) as programadas_pasadas_con_valoracion_mismo_dia_bogota
  from valuation_appointments a
 where a.status = 'scheduled' and a.starts_at < now() at time zone 'UTC'
   and exists (select 1 from professional_assessments p
                where p.member_id = a.member_id and p.submitted_at is not null
                  and ((p.submitted_at at time zone 'UTC') at time zone 'America/Bogota')::date = ((a.starts_at at time zone 'UTC') at time zone 'America/Bogota')::date);
