<?php
// SOLO LECTURA (lead, 12-11-2026): las piezas de la base de conocimiento que el motor usa como «ficha del gimnasio»
// (identidad, información, ubicación, horario), con su longitud. Sin personas ni conversaciones. Los números largos
// (teléfonos) se enmascaran. No escribe nada.
use App\Services\Marketing\MarketingKnowledgeBaseService;

$kb = app(MarketingKnowledgeBaseService::class);
$n = 0;
foreach ($kb->activeItems() as $item) {
    $n++;
    $c = trim((string) $item->content);
    $c = preg_replace('/\d[\d\s().-]{6,}\d/u', '[número]', $c);
    echo sprintf("[%s] «%s» · %d caracteres\n  %s\n", $item->category, mb_substr((string) $item->title, 0, 60), mb_strlen(trim((string) $item->content)), mb_substr($c, 0, 420));
}
echo "piezas activas: {$n}\n";
