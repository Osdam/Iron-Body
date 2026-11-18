SELECT column_name FROM information_schema.columns WHERE table_name='audit_logs' ORDER BY ordinal_position;
SELECT module, entity, action, count(*), min(created_at), max(created_at) FROM audit_logs WHERE module='Valoraciones' GROUP BY 1,2,3 ORDER BY 1,2,3;
