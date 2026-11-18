-- Qué campos cambió el único ajuste (solo nombres de campo y valores booleanos/numéricos de ajustes, sin personas)
SELECT actor_role, created_at, jsonb_path_query_array(changes::jsonb, '$[*].field') AS campos,
       (SELECT jsonb_agg(jsonb_build_object('f', e->>'field', 'from', e->'from', 'to', e->'to')) FROM jsonb_array_elements(changes::jsonb) e WHERE e->>'field' IN ('member_app_enabled','default_duration_minutes','internal_unattended_enabled')) AS cambios_clave
FROM audit_logs WHERE module='Valoraciones' AND entity='ajustes_valoraciones';
