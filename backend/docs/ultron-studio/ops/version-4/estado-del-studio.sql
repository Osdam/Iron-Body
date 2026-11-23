-- SOLO LECTURA (lead, 23-11-2026). El estado del Studio ANTES y DESPUÉS de preparar la versión 4 con la cuenta técnica
-- (autorización del propietario, opción A): cuentas y permisos (huellas: tienen que quedar idénticas), versiones,
-- revisiones, veredictos, publicaciones, borradores, escenarios del laboratorio, ejecuciones del día y la traza de
-- auditoría desde las 05:50 UTC. De las personas, solo el id interno de la cuenta y su rol (nunca nombres ni correos).
\set ON_ERROR_STOP on
begin transaction read only;
select 'HORA: ' || to_char(now() at time zone 'utc', 'YYYY-MM-DD HH24:MI:SS') || ' UTC';
select 'CUENTA TÉCNICA (32): ' || role || ' · ' || status || ' · sesiones vivas: ' || (select count(*) from admin_sessions where admin_id = 32) from admins where id = 32;
select 'CUENTAS (id:rol:estado), huella: ' || md5(string_agg(id || ':' || role || ':' || status, ',' order by id)) || ' · ' || count(*) || ' cuentas' from admins;
select 'PERMISOS POR ROL, huella: ' || md5(string_agg(role || ':' || permission || ':' || granted, ',' order by role, permission)) || ' · ' || count(*) || ' filas' from role_permissions;
select 'ROLES, huella: ' || md5(string_agg(id || ':' || name || ':' || coalesce(archived_at::text, '-'), ',' order by id)) || ' · ' || count(*) || ' roles' from admin_roles;
select 'VERSIÓN · ' || left(uuid::text, 8) || ' · «' || label || '» · ' || status || ' · contenido ' || left(content_hash, 8) || ' · borrador rev ' || source_draft_revision || ' · creada por la cuenta ' || created_by_admin_id
       || ' (' || created_by_role || ') el ' || to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') || ' UTC · enviada a revisión: ' || coalesce(to_char(submitted_at, 'YYYY-MM-DD HH24:MI:SS') || ' UTC', '-')
from ultron_studio_versions order by id;
select 'REVISIONES DE VERSIONES: ' || count(*) from ultron_studio_version_reviews;
select 'VEREDICTOS DEL LABORATORIO: ' || count(*) from ultron_studio_lab_verdicts;
select 'PUBLICACIONES: ' || count(*) || ' · cabeza: entrada ' || max(seq) from ultron_studio_publications;
select 'BORRADOR · cuenta ' || admin_id || ' · revisión ' || revision || ' · guardado ' || to_char(updated_at, 'YYYY-MM-DD HH24:MI') || ' UTC · huella del borrador ' || left(content_hash, 8) from ultron_studio_drafts order by id;
select 'ESCENARIOS · ' || source || '/' || status || ': ' || count(*) from ultron_studio_lab_scenarios group by source, status order by 1;
select 'ESCENARIO DEL PERSONAL · ' || code || ' · rev ' || revision || ' · ' || status || ' · ' || category || ' · «' || title || '» · huella ' || left(content_hash, 8) || ' · creado por la cuenta ' || created_by_admin_id
       || ' el ' || to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') || ' UTC'
from ultron_studio_lab_scenarios where source = 'studio' order by id;
select 'LABORATORIO · ejecuciones en total: ' || count(*) || ' · del día 23-11 de Bogotá: ' || count(*) filter (where budget_day = '2026-11-23')
       || ' · gastado ese día US$ ' || coalesce(round(sum(cost_micros) filter (where budget_day = '2026-11-23') / 1000000.0, 4), 0)
from ultron_studio_lab_runs;
select 'AUDITORÍA DEL STUDIO · ' || to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') || ' UTC · ' || event || ' · ' || subject_type || ' ' || left(coalesce(subject_uuid::text, '-'), 8) || ' · cuenta ' || coalesce(actor_admin_id::text, '-')
       || ' (' || coalesce(actor_role, '-') || ') · ' || coalesce(from_status, '-') || ' → ' || coalesce(to_status, '-') || ' · contenido ' || left(coalesce(content_hash, '-'), 8)
from ultron_studio_audit_events where created_at >= '2026-11-23 05:50:00' order by id;
select 'EVENTO DEL LABORATORIO · ' || to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') || ' UTC · ' || event || ' · ' || subject_type || ' ' || left(coalesce(subject_uuid::text, '-'), 8) || ' · cuenta ' || coalesce(actor_admin_id::text, '-')
       || ' (' || coalesce(actor_role, '-') || ') · ' || coalesce(from_status, '-') || ' → ' || coalesce(to_status, '-')
from ultron_studio_lab_events where created_at >= '2026-11-23 05:50:00' order by id;
select 'FIN DE LA LECTURA (auditoría y eventos: solo los posteriores a las 05:50 UTC del 23-11)';
rollback;
