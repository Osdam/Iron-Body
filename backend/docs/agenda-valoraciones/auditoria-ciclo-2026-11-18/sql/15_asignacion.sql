SELECT c.status,
  count(*) AS citas,
  count(*) FILTER (WHERE EXISTS (SELECT 1 FROM member_trainer_assignments t WHERE t.member_id=c.member_id AND t.trainer_id=c.trainer_id AND t.status='active')) AS entrenador_asignado_hoy,
  count(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM member_trainer_assignments t WHERE t.member_id=c.member_id AND t.status='active')) AS socio_sin_ningun_entrenador
FROM valuation_appointments c GROUP BY 1 ORDER BY 1;
-- Citas activas pasadas SIN registro, ¿el entrenador de la cita está asignado?
SELECT count(*) AS activas_pasadas_sin_registro,
  count(*) FILTER (WHERE EXISTS (SELECT 1 FROM member_trainer_assignments t WHERE t.member_id=c.member_id AND t.trainer_id=c.trainer_id AND t.status='active')) AS con_entrenador_asignado
FROM valuation_appointments c
WHERE c.status IN ('scheduled','confirmed') AND c.ends_at < (now() AT TIME ZONE 'UTC')
  AND NOT EXISTS (SELECT 1 FROM professional_assessments a WHERE a.member_id=c.member_id AND ((coalesce(a.submitted_at,a.created_at) AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date >= ((c.starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date);
