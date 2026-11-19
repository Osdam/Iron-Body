<?php

// SOLO LECTURA (lead, 19-11-2026; cierre V2, apartado 4, H4-9): qué piezas de la base de conocimiento TUTEAN.
//
// Con una versión publicada en «profesional» (de usted), el modelo recibe estas piezas como `knowledge_base` y
// algunas salen tal cual en los respaldos de Laravel (la ficha del gimnasio). Si su texto tutea, el modelo tuteará
// más y la ficha saldrá de tú con solo un aviso en el log. Esto dice cuáles son, con las marcas, sin personas ni
// conversaciones. Los números largos se enmascaran. No escribe nada.
//
// Uso (el detector aún no está desplegado: se sube junto a esta sonda y se borra al terminar):
//   scp UltronRegisterDetector.php registro-base-conocimiento.php ironbody-vps:/tmp/sonda-registro/
//   cd /var/www/api/backend && sudo -u www-data php artisan tinker --execute='require "/tmp/sonda-registro/registro-base-conocimiento.php";'
use App\Services\Marketing\MarketingKnowledgeBaseService;

$clase = 'App\\Services\\Marketing\\Ultron\\UltronRegisterDetector';
if (! class_exists($clase)) {
    require_once __DIR__.'/UltronRegisterDetector.php';
}

$kb = app(MarketingKnowledgeBaseService::class);
$total = 0;
$tutean = 0;
foreach ($kb->activeItems() as $item) {
    $total++;
    $titulo = trim((string) $item->title);
    $contenido = trim((string) $item->content);
    $marcas = $clase::tuteo($titulo.'. '.$contenido);
    $tutean += $marcas === [] ? 0 : 1;
    echo sprintf(
        "[%s] «%s» · %d caracteres · %s\n",
        $item->category,
        mb_substr($titulo, 0, 50),
        mb_strlen($contenido),
        $marcas === [] ? 'sin tuteo' : 'TUTEA: '.implode(', ', $marcas),
    );
    // El tono es configuración del negocio, no un dato personal: se enseña para saber qué le pide al modelo.
    if ($item->category === 'tone') {
        echo '    '.mb_substr((string) preg_replace('/\d[\d\s().-]{6,}\d/u', '[número]', $contenido), 0, 400)."\n";
    }
}
echo "piezas activas: {$total} · con tuteo: {$tutean}\n";
echo 'PCRE '.PCRE_VERSION."\n";
