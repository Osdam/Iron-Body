<?php
// SOLO LECTURA. La ruta real de los datos comerciales de ULTRON en WhatsApp, medida en producción: qué catálogo lee
// del CRM en este momento, qué le pasa al modelo (sin precios), qué plan cotiza por defecto y qué lista determinista
// armaría ahora mismo el commit con ese catálogo. Llama por reflexión a métodos privados que solo leen: no escribe nada,
// no envía nada y no toca ninguna conversación. Sin personas ni mensajes.
use App\Models\Plan;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\Ultron\UltronCommitService;
use App\Services\Marketing\Ultron\UltronDecideService;
use App\Services\Marketing\Ultron\UltronReplyFitter;

$j = static fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$kb = app(MarketingKnowledgeBaseService::class);

echo '1. Catálogo que lee el motor ahora (Plan::sellable(), tabla plans del CRM): '.count($kb->activePlans())." planes\n";
foreach ($kb->activePlans() as $p) {
    $fila = Plan::find($p['id']);
    echo sprintf("   #%d %s · %d días · \$%s · actualizado en el CRM %s UTC\n", $p['id'], $p['name'], $p['duration_days'], number_format($p['price'], 0, ',', '.'), optional($fila->updated_at)->format('Y-m-d H:i'));
}

$decide = app(UltronDecideService::class);
$m = new ReflectionMethod($decide, 'plansWithoutPrice');
$m->setAccessible(true);
$alModelo = $m->invoke($decide);
echo "\n2. Lo que decide le pasa al modelo (active_plans): ".count($alModelo).' planes · claves del primero: '.$j(array_keys($alModelo[0] ?? []))."\n";
echo '   ¿alguno lleva la clave price?: '.(array_filter($alModelo, fn ($p) => array_key_exists('price', $p)) === [] ? 'NO' : 'SÍ')."\n";
$d = $kb->defaultMonthlyPlan();
echo '   default_plan_id (el que se cotiza ante «¿cuánto vale la mensualidad?»): '.($d ? '#'.$d->id.' '.$d->name.' (sort_order '.$d->sort_order.', recomendado '.($d->is_recommended ? 'sí' : 'no').')' : 'ninguno')."\n";
echo '   planes de un mes por orden del catálogo: '.$j(array_values(array_map(fn ($p) => $p['name'].' (#'.$p['id'].')', array_filter($kb->activePlans(), fn ($p) => $p['duration_days'] >= 28 && $p['duration_days'] <= 31))))."\n";

$commit = app(UltronCommitService::class);
$r = new ReflectionMethod($commit, 'respaldoDePrecios');
$r->setAccessible(true);
foreach ([null, 200, 160] as $tope) {
    $lista = $r->invoke($commit, [], $tope);
    echo "\n3. Lista determinista que armaría el commit AHORA con ese catálogo (tope ".($tope ?? 'sin tope')."):\n";
    echo '   texto ('.UltronReplyFitter::measure((string) ($lista['texto'] ?? '')).'): '.($lista['texto'] ?? 'null')."\n";
    if ($tope === null) { echo '   esqueleto que ve el guard: '.($lista['esqueleto'] ?? 'null')."\n"; }
}
