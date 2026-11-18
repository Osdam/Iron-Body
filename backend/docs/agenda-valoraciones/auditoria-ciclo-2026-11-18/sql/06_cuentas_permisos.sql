-- Cuentas CRM por rol y vínculo con entrenador
SELECT role, count(*) AS cuentas, count(*) FILTER (WHERE trainer_id IS NOT NULL) AS con_trainer_id,
       count(*) FILTER (WHERE status IS NULL OR status IN ('active','activo')) AS activas
FROM admins GROUP BY 1 ORDER BY 1;
-- Columnas de admins relevantes (por si status no existe)
SELECT column_name FROM information_schema.columns WHERE table_name='admins' AND column_name IN ('status','is_active','active','trainer_id','role') ORDER BY 1;
-- Políticas persistidas de permisos de valoraciones / miembros por rol
SELECT column_name FROM information_schema.columns WHERE table_name='role_permissions' ORDER BY ordinal_position;
