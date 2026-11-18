-- Citas activas ya pasadas (sin cerrar) vs futuras
SELECT status,
       count(*) FILTER (WHERE ends_at < (now() AT TIME ZONE 'UTC')) AS ya_terminaron_por_hora,
       count(*) FILTER (WHERE starts_at > (now() AT TIME ZONE 'UTC')) AS futuras,
       count(*) FILTER (WHERE starts_at <= (now() AT TIME ZONE 'UTC') AND ends_at >= (now() AT TIME ZONE 'UTC')) AS en_ventana
FROM valuation_appointments GROUP BY 1 ORDER BY 1;

-- Cruce cita ↔ evaluación física / valoración profesional por socio y DÍA de Bogotá
WITH citas AS (
  SELECT id, member_id, trainer_id, status,
         ((starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date AS dia,
         starts_at, ends_at
  FROM valuation_appointments
), pe AS (
  SELECT id, member_id, trainer_id, ((created_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date AS dia, created_at
  FROM physical_evaluations
), pa AS (
  SELECT id, member_id, trainer_id, status, ((coalesce(submitted_at, created_at) AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date AS dia
  FROM professional_assessments
)
SELECT c.status,
       count(*) AS citas,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM pe WHERE pe.member_id=c.member_id AND pe.dia=c.dia)) AS con_eval_fisica_mismo_dia,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM pa WHERE pa.member_id=c.member_id AND pa.dia=c.dia)) AS con_assessment_mismo_dia,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM pa WHERE pa.member_id=c.member_id AND pa.dia=c.dia AND pa.status IN ('submitted','amended'))) AS con_assessment_enviado_mismo_dia,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM pa WHERE pa.member_id=c.member_id AND pa.dia=c.dia AND pa.status='draft')) AS con_assessment_borrador_mismo_dia,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM pe WHERE pe.member_id=c.member_id AND pe.dia BETWEEN c.dia AND c.dia+2)) AS con_eval_fisica_0a2_dias,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM pa WHERE pa.member_id=c.member_id AND pa.dia BETWEEN c.dia AND c.dia+2)) AS con_assessment_0a2_dias,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM pe WHERE pe.member_id=c.member_id AND pe.dia=c.dia AND pe.trainer_id=c.trainer_id)) AS eval_mismo_dia_mismo_entrenador
FROM citas c GROUP BY 1 ORDER BY 1;

-- Citas activas pasadas: ¿cuántas tienen evaluación física el mismo día? (las que debieron cerrarse)
WITH c AS (
  SELECT id, member_id, ((starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date AS dia
  FROM valuation_appointments
  WHERE status IN ('scheduled','confirmed','in_progress') AND ends_at < (now() AT TIME ZONE 'UTC')
)
SELECT count(*) AS activas_pasadas,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM physical_evaluations pe WHERE pe.member_id=c.member_id AND ((pe.created_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date = c.dia)) AS con_eval_mismo_dia,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM professional_assessments pa WHERE pa.member_id=c.member_id AND ((coalesce(pa.submitted_at,pa.created_at) AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date = c.dia)) AS con_assessment_mismo_dia,
       count(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM physical_evaluations pe WHERE pe.member_id=c.member_id AND ((pe.created_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date >= c.dia)
                         AND NOT EXISTS (SELECT 1 FROM professional_assessments pa WHERE pa.member_id=c.member_id AND ((coalesce(pa.submitted_at,pa.created_at) AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date >= c.dia)) AS sin_ningun_registro_posterior
FROM c;

-- Desfase en minutos entre inicio de cita y la evaluación del mismo día (distribución)
WITH x AS (
  SELECT c.id, extract(epoch FROM (pe.created_at - c.starts_at))/60 AS min_desde_inicio
  FROM valuation_appointments c
  JOIN physical_evaluations pe ON pe.member_id=c.member_id
   AND ((pe.created_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date = ((c.starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota')::date
)
SELECT count(*) AS pares, min(min_desde_inicio)::int AS min_min, percentile_cont(0.5) WITHIN GROUP (ORDER BY min_desde_inicio)::int AS mediana_min, max(min_desde_inicio)::int AS max_min,
       count(*) FILTER (WHERE min_desde_inicio < 0) AS antes_de_la_hora
FROM x;
