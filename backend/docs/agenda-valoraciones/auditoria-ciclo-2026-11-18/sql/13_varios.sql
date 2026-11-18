SELECT count(*) AS socios, count(*) FILTER (WHERE anonymized_at IS NULL) AS no_anonimizados FROM members;
-- Hora de Bogotá en que salieron los avisos al socio (¿horas de silencio?)
SELECT kind, extract(hour FROM (processed_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::int AS hora_bogota, count(*)
FROM valuation_reminders WHERE channel='member_app' AND status='accepted' GROUP BY 1,2 HAVING extract(hour FROM (processed_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::int < 7 OR extract(hour FROM (processed_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::int >= 21 ORDER BY 1,2;
-- Valoraciones profesionales de socios que ya no existen (sin FK)
SELECT count(*) AS assessments_huerfanas FROM professional_assessments a WHERE NOT EXISTS (SELECT 1 FROM members m WHERE m.id=a.member_id);
-- Entrenadores activos y con citas / con valoraciones
SELECT count(*) AS entrenadores, count(*) FILTER (WHERE status='active') AS activos FROM trainers;
SELECT column_name FROM information_schema.columns WHERE table_name='trainers' AND column_name IN ('status','is_active','active');
-- Asignaciones activas socio-entrenador
SELECT status, count(*) FROM member_trainer_assignments GROUP BY 1;
-- Socios valorados (assessment enviado) sin asignación activa al entrenador que valoró
SELECT count(*) AS assessments_sin_asignacion_activa FROM professional_assessments a WHERE a.submitted_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM member_trainer_assignments t WHERE t.member_id=a.member_id AND t.trainer_id=a.trainer_id AND t.status='active');
