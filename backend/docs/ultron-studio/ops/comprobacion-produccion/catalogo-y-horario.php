<?php
// SOLO LECTURA (lead, 12-11-2026): el catálogo vendible tal como lo ve el motor de ULTRON y las líneas del
// horario de la base de conocimiento. Sin personas, teléfonos ni conversaciones. No escribe nada.
use App\Models\Plan;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\Ultron\GymFactsProvider;

$kb = app(MarketingKnowledgeBaseService::class);
$planes = $kb->activePlans();
echo 'PLANES VENDIBLES (orden del motor: recomendado primero, luego sort_order): '.count($planes)."\n";
$orden = Plan::query()->sellable()->pluck('sort_order', 'id');
foreach ($planes as $p) {
    echo sprintf("  #%d | %s | $%s | %d días | tier %s | recomendado %s | sort %s | precio anterior %s | %d beneficios\n",
        $p['id'], $p['name'], number_format($p['price'], 0, ',', '.'), $p['duration_days'], $p['tier'] ?? '-',
        $p['is_recommended'] ? 'SÍ' : 'no', $orden[$p['id']] ?? '-',
        $p['original_price'] !== null ? number_format($p['original_price'], 0, ',', '.') : '-', count($p['benefits']));
}
$m = $kb->defaultMonthlyPlan();
echo 'PLAN QUE SE COTIZA POR DEFECTO (defaultMonthlyPlan): '.($m ? '#'.$m->id.' '.$m->name.' · '.$m->duration_days.' días' : 'ninguno')."\n";
echo 'Planes en total: '.Plan::query()->count().' · activos: '.Plan::query()->where('active', true)->count()."\n";

$h = app(GymFactsProvider::class)->openingHours();
echo 'HORARIO (openingHours): '.(is_array($h) ? count($h).' línea(s)' : (string) $h)."\n";
if (is_array($h)) {
    foreach ($h as $l) {
        echo '  ['.mb_strlen((string) $l).'] '.$l."\n";
    }
}
