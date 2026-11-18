-- Quién cerró la única cita completada (rol, no nombre)
SELECT a.role, c.status, ((c.completed_at - c.starts_at)) AS completada_tras_inicio FROM valuation_appointments c JOIN admins a ON a.id=c.outcome_by_admin_id WHERE c.status='completed';
-- Quién crea las citas (rol)
SELECT a.role, count(*) FROM valuation_appointments c LEFT JOIN admins a ON a.id=c.created_by_admin_id GROUP BY 1 ORDER BY 2 DESC;
-- Señales tiempo real (efímeras, se podan a 5 min): lo que queda
SELECT 'member_realtime_events' AS t, type, count(*), max(created_at) FROM member_realtime_events GROUP BY 1,2
UNION ALL SELECT 'trainer_realtime_events', type, count(*), max(created_at) FROM trainer_realtime_events GROUP BY 1,2
ORDER BY 1,3 DESC;
SELECT 'member_realtime_events' t, max(id) AS max_id FROM member_realtime_events UNION ALL SELECT 'trainer_realtime_events', max(id) FROM trainer_realtime_events UNION ALL SELECT 'class_events', max(id) FROM class_events UNION ALL SELECT 'catalog_events', max(id) FROM catalog_events UNION ALL SELECT 'notifications', max(id) FROM notifications;
-- class_events (outbox CRM con cursor) por tipo, últimos 7 días
SELECT type, count(*) FROM class_events WHERE created_at >= (now() AT TIME ZONE 'UTC') - interval '7 days' GROUP BY 1 ORDER BY 2 DESC;
-- Seguimiento: eventos de automatización evaluation.outdated y tareas de entrenador
SELECT event_type, status, count(*), max(created_at) FROM automation_events WHERE event_type LIKE 'evaluation.%' OR event_type LIKE 'valuation.%' GROUP BY 1,2 ORDER BY 1,2;
SELECT column_name FROM information_schema.columns WHERE table_name='trainer_tasks' ORDER BY ordinal_position;
