-- Fragmentos de beneficios (catálogo público de planes, sin datos personales) que mencionan valoración
SELECT p.id, p.active, (p.archived_at IS NOT NULL) AS archivado, m[1] AS fragmento
FROM plans p, LATERAL regexp_matches(p.benefits, '([^",\[\]]{0,40}(valora|evaluaci|medici|antropom|inbody|bioimped)[^",\[\]]{0,40})', 'gi') AS m
ORDER BY p.id;
SELECT column_name FROM information_schema.columns WHERE table_name='plans' AND column_name IN ('is_active','status','archived_at','active') ;
