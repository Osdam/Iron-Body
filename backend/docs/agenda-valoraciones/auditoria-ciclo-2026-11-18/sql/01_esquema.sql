-- Esquema real en producción (solo catálogo)
SELECT now() AT TIME ZONE 'UTC' AS ahora_utc, current_setting('default_transaction_read_only') AS ro;
SELECT table_name, count(*) AS columnas
FROM information_schema.columns
WHERE table_schema='public' AND table_name IN ('valuation_appointments','valuation_appointment_events','valuation_reminders','valuation_settings','valuation_availability_rules','valuation_availability_blocks','physical_evaluations','professional_assessments','notifications','member_realtime_events','trainer_realtime_events','class_events','catalog_events','trainer_tasks','automation_events','member_notification_preferences','notification_dispatches','member_trainer_assignments')
GROUP BY table_name ORDER BY table_name;
SELECT conrelid::regclass AS tabla, conname, contype, pg_get_constraintdef(oid) AS def
FROM pg_constraint
WHERE conrelid::regclass::text IN ('valuation_appointments','physical_evaluations','professional_assessments')
ORDER BY 1,2;
SELECT tablename, indexname, indexdef FROM pg_indexes
WHERE schemaname='public' AND tablename IN ('valuation_appointments','physical_evaluations','professional_assessments','members','users')
ORDER BY 1,2;
SELECT extname, extversion FROM pg_extension ORDER BY 1;
SELECT migration, batch FROM migrations WHERE migration LIKE '%valuation%' OR migration LIKE '%physical_evaluations%' OR migration LIKE '%professional_assessments%' OR migration LIKE '%realtime_events%' ORDER BY id;
SELECT max(batch) AS ultimo_lote FROM migrations;
