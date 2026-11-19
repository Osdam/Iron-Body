-- SOLO LECTURA (lead, 19-11-2026): de dónde salió el texto de cada respuesta de ULTRON en las dos conversaciones
-- canarias (la del propietario: #273 hasta el 13-11 y #882 desde entonces). Solo conteos: ningún texto de mensajes.
select 'A · acciones por tipo', conversation_id, action_type, count(*), min(created_at)::date, max(created_at)::date
from marketing_ai_actions where conversation_id in (273, 882) group by 2, 3 order by 2, 3;

select 'B · claves de metadata en reply', k, count(*)
from marketing_ai_actions a, lateral jsonb_object_keys(a.metadata::jsonb) k
where a.conversation_id in (273, 882) and a.action_type = 'reply' group by k order by 3 desc limit 50;

select 'C · origen del texto', conversation_id,
  case
    when metadata::jsonb ? 'fallback_mode' then '3 respaldo del servidor (revisor falló o caído): ' || coalesce(metadata::jsonb->>'fallback_mode', '?')
    when jsonb_array_length(coalesce(metadata::jsonb->'policy_violations', '[]'::jsonb)) > 0 and metadata::jsonb ? 'policy_discarded_draft' then '2 texto del servidor por la política del commit'
    when metadata::jsonb->'length'->>'action' = 'trimmed' then '1b texto del modelo, sin su pregunta final (tope)'
    else '1 texto del modelo, tal cual'
  end as origen,
  count(*)
from marketing_ai_actions
where conversation_id in (273, 882) and action_type = 'reply' and status = 'executed'
group by 2, 3 order by 2, 3;

select 'D · violaciones de política que sustituyeron el borrador', v, count(*)
from marketing_ai_actions a, lateral jsonb_array_elements_text(coalesce(a.metadata::jsonb->'policy_violations', '[]'::jsonb)) v
where a.conversation_id in (273, 882) and a.action_type = 'reply' and a.metadata::jsonb ? 'policy_discarded_draft'
group by v order by 3 desc;

select 'E · por mes', to_char(created_at, 'YYYY-MM') as mes,
  count(*) filter (where not (metadata::jsonb ? 'fallback_mode') and not (metadata::jsonb ? 'policy_discarded_draft')) as del_modelo,
  count(*) filter (where metadata::jsonb ? 'policy_discarded_draft' and not (metadata::jsonb ? 'fallback_mode')) as por_politica,
  count(*) filter (where metadata::jsonb ? 'fallback_mode') as respaldo,
  count(*) as total
from marketing_ai_actions
where conversation_id in (273, 882) and action_type = 'reply' and status = 'executed'
group by 2 order by 2;

select 'F · veredicto del revisor', coalesce(metadata::jsonb->'critic'->>'verdict', '(sin dato)') as veredicto,
  coalesce(metadata::jsonb->'critic'->>'attempt', '-') as intento, count(*)
from marketing_ai_actions
where conversation_id in (273, 882) and action_type = 'reply' and status = 'executed'
group by 2, 3 order by 4 desc;
