SELECT DISTINCT status FROM admins ORDER BY 1;
SELECT role, permission, granted, updated_at FROM role_permissions
WHERE permission LIKE 'valuations.%' OR permission IN ('members.view','members.create','members.edit','content.manage','trainers.view')
ORDER BY 1,2;
-- Notificaciones de valoraciones en la bandeja CRM (admin) por origen y estado
SELECT audience, type, coalesce(metadata::jsonb->>'source_event','(sin)') AS origen, status, required_permission, count(*)
FROM notifications WHERE type IN ('valuation','professional_assessment','professional_assessment_amended') OR metadata::jsonb->>'source_event' LIKE 'valuation%'
GROUP BY 1,2,3,4,5 ORDER BY 1,2,3,4;
-- Avisos a socio por valoración profesional
SELECT type, should_popup, count(*), min(created_at), max(created_at)
FROM notifications WHERE type LIKE 'professional_assessment%' GROUP BY 1,2 ORDER BY 1,2;
-- Cualquier notificación admin que mencione valoración enviada/assessment (por tipo)
SELECT type, count(*) FROM notifications WHERE audience='admin' AND created_at >= '2026-10-17' GROUP BY 1 ORDER BY 2 DESC;
