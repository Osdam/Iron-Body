-- Ajustes de la agenda (sin datos personales)
SELECT id, default_duration_minutes, slot_interval_minutes, buffer_minutes, min_notice_minutes, max_horizon_days,
       block_on_class_conflict, confirmation_enabled, reminder_long_enabled, reminder_long_hours,
       reminder_short_enabled, reminder_short_minutes, internal_alert_enabled, internal_alert_minutes,
       internal_unattended_enabled, member_app_enabled, (updated_by_admin_id IS NOT NULL) AS editado_por_admin,
       created_at, updated_at
FROM valuation_settings;
-- Citas por estado
SELECT status, count(*) FROM valuation_appointments GROUP BY 1 ORDER BY 1;
-- Citas por mes (Bogotá, por starts_at) y estado
SELECT to_char((starts_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Bogota','YYYY-MM') AS mes, status, type, source, count(*)
FROM valuation_appointments GROUP BY 1,2,3,4 ORDER BY 1,2,3,4;
-- Fechas extremas
SELECT min(created_at) AS primera_creacion, max(created_at) AS ultima_creacion, min(starts_at) AS primera_hora, max(starts_at) AS ultima_hora FROM valuation_appointments;
-- Columnas de resultado rellenas
SELECT count(*) FILTER (WHERE professional_assessment_id IS NOT NULL) AS con_assessment_id,
       count(*) FILTER (WHERE completed_at IS NOT NULL) AS con_completed_at,
       count(*) FILTER (WHERE started_at IS NOT NULL) AS con_started_at,
       count(*) FILTER (WHERE confirmed_at IS NOT NULL) AS con_confirmed_at,
       count(*) FILTER (WHERE outcome_by_admin_id IS NOT NULL) AS con_outcome_admin,
       count(*) FILTER (WHERE version > 1) AS version_mayor_1,
       max(version) AS version_max
FROM valuation_appointments;
-- Eventos del historial por acción
SELECT action, count(*) FROM valuation_appointment_events GROUP BY 1 ORDER BY 1;
-- Avisos (outbox) por tipo, canal y estado
SELECT kind, channel, status, count(*) FROM valuation_reminders GROUP BY 1,2,3 ORDER BY 1,2,3;
-- Disponibilidad configurada
SELECT count(*) AS reglas, count(DISTINCT trainer_id) AS entrenadores_con_reglas, count(*) FILTER (WHERE active) AS activas FROM valuation_availability_rules;
SELECT kind, count(*) FROM valuation_availability_blocks GROUP BY 1 ORDER BY 1;
-- Citas por entrenador (ids) y estado
SELECT trainer_id, status, count(*) FROM valuation_appointments GROUP BY 1,2 ORDER BY 1,2;
-- Socios con más de una cita
SELECT n_citas, count(*) AS socios FROM (SELECT member_id, count(*) AS n_citas FROM valuation_appointments GROUP BY 1) t GROUP BY 1 ORDER BY 1;
