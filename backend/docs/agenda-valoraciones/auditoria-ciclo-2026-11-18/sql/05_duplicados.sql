-- Pares de valoraciones enviadas (no enmiendas) del mismo socio y entrenador, por separación temporal
WITH a AS (
  SELECT id, member_id, trainer_id, submitted_at, created_at,
         md5(concat_ws('|',weight_kg,height_cm,body_fat_pct,muscle_mass_pct,waist_cm,hip_cm,chest_cm,arm_cm,leg_cm)) AS huella_medidas,
         md5(concat_ws('|',observations,recommendations)) AS huella_texto
  FROM professional_assessments WHERE submitted_at IS NOT NULL AND parent_id IS NULL
)
SELECT
  CASE WHEN abs(extract(epoch FROM (b.submitted_at-a.submitted_at))) < 60 THEN '<1 min'
       WHEN abs(extract(epoch FROM (b.submitted_at-a.submitted_at))) < 600 THEN '1-10 min'
       WHEN abs(extract(epoch FROM (b.submitted_at-a.submitted_at))) < 86400 THEN '10 min-1 dia'
       ELSE '>1 dia' END AS separacion,
  count(*) AS pares,
  count(*) FILTER (WHERE a.huella_medidas=b.huella_medidas) AS medidas_identicas,
  count(*) FILTER (WHERE a.huella_medidas=b.huella_medidas AND a.huella_texto=b.huella_texto) AS todo_identico
FROM a JOIN a b ON a.member_id=b.member_id AND a.trainer_id=b.trainer_id AND a.id<b.id
GROUP BY 1 ORDER BY 1;
-- Pares de evaluaciones físicas del mismo socio a <5 min: ¿medidas idénticas?
SELECT count(*) AS pares,
  count(*) FILTER (WHERE md5(concat_ws('|',a.weight_kg,a.height_cm,a.body_fat_pct,a.muscle_mass_pct,a.waist_cm,a.hip_cm,a.chest_cm,a.arm_cm,a.leg_cm)) = md5(concat_ws('|',b.weight_kg,b.height_cm,b.body_fat_pct,b.muscle_mass_pct,b.waist_cm,b.hip_cm,b.chest_cm,b.arm_cm,b.leg_cm))) AS medidas_identicas
FROM physical_evaluations a JOIN physical_evaluations b ON a.member_id=b.member_id AND a.id<b.id AND abs(extract(epoch FROM (b.created_at-a.created_at)))<300;
-- Valoraciones sin medidas (no generan evaluación física)
SELECT count(*) FILTER (WHERE coalesce(weight_kg,height_cm,body_fat_pct,muscle_mass_pct,waist_cm,hip_cm,chest_cm,arm_cm,leg_cm) IS NULL) AS sin_medidas,
       count(*) AS total_enviadas
FROM professional_assessments WHERE submitted_at IS NOT NULL;
-- Valoraciones de socios con cita vs sin cita (desde la agenda, 17-10)
WITH a AS (
  SELECT id, member_id, trainer_id, ((submitted_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date AS dia
  FROM professional_assessments WHERE submitted_at IS NOT NULL AND parent_id IS NULL AND submitted_at >= '2026-10-17 19:30:00'
)
SELECT count(*) AS enviadas_desde_agenda,
  count(*) FILTER (WHERE EXISTS (SELECT 1 FROM valuation_appointments c WHERE c.member_id=a.member_id AND ((c.starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date = a.dia)) AS con_cita_mismo_dia,
  count(*) FILTER (WHERE EXISTS (SELECT 1 FROM valuation_appointments c WHERE c.member_id=a.member_id AND ((c.starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date = a.dia AND c.trainer_id=a.trainer_id)) AS con_cita_mismo_dia_mismo_entrenador,
  count(*) FILTER (WHERE EXISTS (SELECT 1 FROM valuation_appointments c WHERE c.member_id=a.member_id AND ((c.starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date = a.dia AND c.trainer_id<>a.trainer_id)) AS con_cita_mismo_dia_otro_entrenador,
  count(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM valuation_appointments c WHERE c.member_id=a.member_id)) AS socio_sin_ninguna_cita
FROM a;
-- ¿Algún socio con dos citas el mismo día?
SELECT count(*) AS socio_dia_con_varias_citas FROM (SELECT member_id, ((starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date d FROM valuation_appointments GROUP BY 1,2 HAVING count(*)>1) t;
