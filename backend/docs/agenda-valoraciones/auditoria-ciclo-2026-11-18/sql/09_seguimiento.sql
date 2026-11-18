SELECT to_char(created_at,'YYYY-MM') AS mes, count(*) AS eventos, count(DISTINCT member_id) AS socios FROM automation_events WHERE event_type='evaluation.outdated' GROUP BY 1 ORDER BY 1;
SELECT type, status, count(*), count(*) FILTER (WHERE seen_at IS NOT NULL) AS vistas, count(*) FILTER (WHERE completed_at IS NOT NULL) AS completadas, count(DISTINCT trainer_id) AS entrenadores, max(created_at)
FROM trainer_tasks GROUP BY 1,2 ORDER BY 1,2;
-- Planes: claves de features y si los beneficios mencionan valoración (solo banderas)
SELECT id, (features IS NOT NULL) AS tiene_features,
       (SELECT string_agg(k, ',' ORDER BY k) FROM jsonb_object_keys(CASE WHEN jsonb_typeof(features::jsonb)='object' THEN features::jsonb ELSE '{}'::jsonb END) k) AS claves_features,
       (benefits::text ~* 'valora|evaluaci|medici|antropom|inbody|bioimped') AS beneficios_mencionan_valoracion,
       (benefits::text ~* '(mensual|cada mes|trimestral|cada [0-9]+)') AS beneficios_mencionan_frecuencia
FROM plans ORDER BY id;
SELECT column_name, data_type FROM information_schema.columns WHERE table_name='plans' AND (column_name ILIKE '%valu%' OR column_name ILIKE '%eval%' OR column_name ILIKE '%assess%' OR column_name ILIKE '%follow%' OR column_name ILIKE '%interval%' OR column_name ILIKE '%benefit%' OR column_name ILIKE '%feature%');
-- ¿Alguna columna en el esquema que sugiera intervalo/seguimiento de valoración?
SELECT table_name, column_name FROM information_schema.columns WHERE table_schema='public' AND (column_name ILIKE '%follow_up%' OR column_name ILIKE '%next_valuation%' OR column_name ILIKE '%next_evaluation%' OR column_name ILIKE '%valuation_interval%' OR column_name ILIKE '%evaluation_interval%' OR column_name ILIKE '%reassess%') ORDER BY 1,2;
-- Preferencias de notificación de socios
SELECT count(*) AS filas, count(*) FILTER (WHERE opted_out_at IS NOT NULL) AS opt_out, count(*) FILTER (WHERE quiet_hours_enabled) AS con_silencio FROM member_notification_preferences;
SELECT category, status, count(*) FROM notification_dispatches GROUP BY 1,2 ORDER BY 1,2;
