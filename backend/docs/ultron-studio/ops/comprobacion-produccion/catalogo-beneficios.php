<?php
// SOLO LECTURA: los planes vendibles tal como los ve el motor (id, nombre, duración y beneficios), en JSON. Sin personas.
$planes = app(\App\Services\Marketing\MarketingKnowledgeBaseService::class)->activePlans();
echo json_encode(array_map(fn (array $p) => ['id' => $p['id'], 'name' => $p['name'], 'duration_days' => $p['duration_days'] ?? null, 'benefits' => $p['benefits'] ?? null], $planes), JSON_UNESCAPED_UNICODE)."\n";
