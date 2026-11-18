-- Valoraciones profesionales por estado
SELECT status, count(*), count(*) FILTER (WHERE parent_id IS NOT NULL) AS enmiendas, count(*) FILTER (WHERE acknowledged_at IS NOT NULL) AS leidas_por_socio
FROM professional_assessments GROUP BY 1 ORDER BY 1;
-- Por mes (Bogotá, por created_at) y estado
SELECT to_char((created_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota','YYYY-MM') AS mes, status, count(*), count(DISTINCT trainer_id) AS entrenadores, count(DISTINCT member_id) AS socios
FROM professional_assessments GROUP BY 1,2 ORDER BY 1,2;
-- Tiempo de borrador a envío (minutos)
SELECT count(*) AS enviadas, percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM submitted_at-created_at)/60)::numeric(10,1) AS mediana_min_borrador_a_envio,
       max(extract(epoch FROM submitted_at-created_at)/60)::int AS max_min
FROM professional_assessments WHERE submitted_at IS NOT NULL AND parent_id IS NULL;
-- Borradores vivos y su antigüedad
SELECT count(*) AS borradores, min(created_at) AS mas_viejo, max(created_at) AS mas_nuevo FROM professional_assessments WHERE status='draft';
-- Evaluaciones físicas: origen heurístico
WITH pe AS (
  SELECT e.id, e.member_id, e.trainer_id, e.created_at,
    EXISTS (SELECT 1 FROM professional_assessments a WHERE a.member_id=e.member_id AND a.submitted_at IS NOT NULL
            AND abs(extract(epoch FROM (e.created_at - a.submitted_at))) <= 120) AS de_assessment,
    (e.trainer_notes LIKE 'Observaciones:%' OR e.trainer_notes LIKE 'Recomendaciones:%') AS notas_formato_sync
  FROM physical_evaluations e
)
SELECT to_char((created_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota','YYYY-MM') AS mes,
  count(*) AS total,
  count(*) FILTER (WHERE de_assessment) AS sync_desde_assessment,
  count(*) FILTER (WHERE NOT de_assessment AND trainer_id IS NOT NULL) AS crm_o_otro_con_entrenador,
  count(*) FILTER (WHERE NOT de_assessment AND trainer_id IS NULL) AS sin_entrenador_app_o_crm,
  count(*) FILTER (WHERE notas_formato_sync) AS notas_con_formato_sync
FROM pe GROUP BY 1 ORDER BY 1;
-- Socios con más de una evaluación física / valoración profesional enviada
SELECT 'physical_evaluations' AS tabla, n, count(*) AS socios FROM (SELECT member_id, count(*) n FROM physical_evaluations GROUP BY 1) t GROUP BY 2 ORDER BY 2;
SELECT 'professional_assessments_enviadas' AS tabla, n, count(*) AS socios FROM (SELECT member_id, count(*) n FROM professional_assessments WHERE status IN ('submitted','amended') AND parent_id IS NULL GROUP BY 1) t GROUP BY 2 ORDER BY 2;
-- Duplicados sospechosos: mismo socio, dos evaluaciones físicas en <5 min
SELECT count(*) AS pares_en_menos_de_5_min FROM physical_evaluations a JOIN physical_evaluations b ON a.member_id=b.member_id AND a.id<b.id AND abs(extract(epoch FROM (b.created_at-a.created_at)))<300;
-- Mismo socio, dos assessments enviados el mismo día por el mismo entrenador
SELECT count(*) AS grupos FROM (SELECT member_id, trainer_id, ((submitted_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date d, count(*) c FROM professional_assessments WHERE submitted_at IS NOT NULL AND parent_id IS NULL GROUP BY 1,2,3 HAVING count(*)>1) t;
