<?php

/*
 * AUTORIZAR UNA FRASE PUBLICITARIA FUERTE. Una, y con firma.
 *
 * Existe porque el cerrojo de superlativos falla CERRADO: ULTRON no puede
 * decir «el mejor gimnasio de Neiva», ni ninguna variante con alcance
 * geografico o competitivo, a menos que la frase exista literalmente aprobada
 * en la categoria `brand_copy` de la base de conocimiento. Eso es deliberado:
 * un ranking de ciudad no se deduce redactando, hace falta un dato que este
 * sistema no tiene, asi que la unica forma honesta de que salga es que el
 * negocio la haya escrito.
 *
 * Y existe como script y no como comando porque autorizar una afirmacion
 * publicitaria no es una tarea de rutina: se hace una vez, mirando lo que se
 * escribe, y queda firmado quien lo hizo.
 *
 * Uso (en el servidor, desde backend/):
 *   php artisan tinker --execute="\$_SERVER['argv']=['x','brand_copy.neiva','Iron Body es el mejor gimnasio de Neiva.','alejandro']; require 'scripts/autorizar-frase-de-marca.php';"
 *
 * NO borra nada. Si la key existe, reescribe su contenido y lo deja aprobado
 * de nuevo; para retirar una frase basta desactivar la fila (is_active=false),
 * y el cerrojo vuelve a bloquear esa afirmacion en el turno siguiente.
 */

use App\Models\MarketingKnowledgeItem;
use App\Services\Marketing\MarketingKnowledgeBaseService;

$argv = $_SERVER['argv'] ?? [];
$key = trim((string) ($argv[1] ?? ''));
$frase = trim((string) ($argv[2] ?? ''));
$quien = trim((string) ($argv[3] ?? ''));

if ($key === '' || $frase === '' || $quien === '') {
    echo "ABORTA: uso -> key, frase, quien-autoriza\n";

    return;
}

if (! str_starts_with($key, 'brand_copy.')) {
    echo "ABORTA: la key debe empezar por brand_copy. (recibida: {$key})\n";

    return;
}

// La etiqueta de auditoria es varchar(80): una firma larga revienta con un
// SQLSTATE[22001] crudo, y este script se corre en produccion.
if (mb_strlen($quien) > 60) {
    echo "ABORTA: la firma no puede pasar de 60 caracteres\n";

    return;
}

$item = MarketingKnowledgeItem::updateOrCreate(
    ['key' => $key],
    [
        'category' => 'brand_copy',
        'title' => 'Frase autorizada por el negocio',
        'content' => $frase,
        'priority' => 10,
        'is_active' => true,
        /*
         * `human_panel` es origen de confianza, asi que la fila nace aprobada:
         * una persona identificada escribiendo la frase ES la aprobacion. Lo
         * que NO puede autorizarse solo es lo que propone la maquina, que
         * entra por la API interna con origen `internal_api` y nace en
         * borrador hasta que alguien la revisa por consola.
         */
        'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
        'submitted_by' => $quien,
    ],
);

echo $item->key.'  id='.$item->id
    .'  review='.$item->review_status
    .'  activo='.(int) $item->is_active."\n";
echo 'contenido='.$item->content."\n";

$aprobadas = app(MarketingKnowledgeBaseService::class)->approvedBrandCopy();
echo 'frases autorizadas ahora mismo='.count($aprobadas)."\n";
foreach ($aprobadas as $a) {
    echo '  · '.$a."\n";
}
