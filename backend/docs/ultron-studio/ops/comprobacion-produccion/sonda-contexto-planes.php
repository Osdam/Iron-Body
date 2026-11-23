<?php
// SOLO LECTURA (lead, 23-11-2026 · unidad 2): ¿el laboratorio «frente al motor» de PRODUCCIÓN mide el turno de planes con
// la frase de la prueba? Se ejecuta en el servidor como www-data:
//     ssh ironbody-vps "cd /var/www/api/backend && sudo -u www-data php" < sonda-contexto-planes.php
// Hace lo mismo que hará una ejecución del recorrido ANTES de llamar al modelo: compila el paquete de la versión en canario
// y construye el contexto sintético del turno con el catálogo real de planes. NO llama a ningún modelo, no sale a la red y
// no escribe nada: todo dentro de una transacción de SOLO LECTURA que se deshace al final (cualquier escritura fallaría).
// Imprime: la huella del paquete (para compararla con la publicada), si el contexto ofrece la lista de planes, cuántos y
// cuáles (id y nombre: es el catálogo público) y si cuenta como pregunta de precio. Ningún dato de personas.
declare(strict_types=1);

use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioLabCatalog;
use App\Services\UltronStudio\StudioLabEngineContext;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Support\Facades\DB;

require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const VERSION = '39cfae9f-19a1-44bd-8d8e-f9f3327fed81';
$casos = [
    'la frase de la prueba, sola' => [['role' => 'prospect', 'text' => '¿Qué planes tienen y cuánto cuesta la mensualidad?']],
    'la frase, tras el saludo y la bienvenida' => [
        ['role' => 'prospect', 'text' => 'Hola, buenas tardes'],
        ['role' => 'agent', 'text' => 'Buenas tardes. Le saluda Iron Body. ¿En qué le puedo ayudar?'],
        ['role' => 'prospect', 'text' => '¿Qué planes tienen y cuánto cuesta la mensualidad?'],
    ],
    'control: C-02 del catálogo, que NO debe dar la lista' => [['role' => 'prospect', 'text' => '¿Cuánto vale la mensualidad?']],
];
$busca = static function (mixed $a, string $clave) use (&$busca): mixed {
    if (! is_array($a)) {
        return null;
    }
    if (array_key_exists($clave, $a)) {
        return $a[$clave];
    }
    foreach ($a as $v) {
        $r = $busca($v, $clave);
        if ($r !== null) {
            return $r;
        }
    }

    return null;
};

DB::beginTransaction();
DB::statement('SET TRANSACTION READ ONLY');
try {
    $v = StudioVersion::query()->where('uuid', VERSION)->firstOrFail();
    $gobernado = StudioPromptBundle::governedFrom((array) $v->content);
    $paquete = StudioPromptBundle::rolesFor($gobernado, $v->uuid, $v->content_hash);
    $sp = $paquete['sp'];
    printf("versión «%s» · compilador %s · huella del paquete %s… · sp: m=%s n=%s t=%s o=%s\n", $v->label, (string) StudioPromptBundle::compilerVersion(),
        substr((string) $paquete['bundleHash'], 0, 8), $sp['m'] ?? '-', $sp['n'] ?? '-', $sp['t'] ?? '-', $sp['o'] ?? '-');
    $ctx = app(StudioLabEngineContext::class);
    foreach ($casos as $nombre => $turnos) {
        $r = $ctx->build(['turns' => $turnos, 'facts' => StudioLabCatalog::DEFAULT_FACTS], $sp);
        $po = $busca($r, 'plans_overview');
        if (! is_array($po)) {
            echo "  · {$nombre}: el contexto NO ofrece la lista de planes\n";

            continue;
        }
        $planes = array_map(static fn ($p) => is_array($p) ? trim(($p['marker'] ?? $p['key'] ?? '?').' = '.($p['id'] ?? $p['plan_id'] ?? '?').' «'.($p['name'] ?? '?').'»') : '?', (array) ($po['plans'] ?? []));
        printf("  · %s: el contexto OFRECE la lista de planes · %d planes [%s] · cuenta como pregunta de precio: %s\n", $nombre, count($planes), implode(' · ', $planes), ($po['price_asked'] ?? null) ? 'sí' : 'no');
        if ($planes !== [] && str_contains($planes[0], '?')) {
            echo '    (claves de un plan de la lista: '.implode(',', array_keys((array) (($po['plans'] ?? [])[0] ?? []))).")\n";
        }
    }
} catch (Throwable $e) {
    echo 'NO SE PUDO: '.get_class($e).' · '.substr($e->getMessage(), 0, 200)."\n";
} finally {
    DB::rollBack();
}
echo "transacción de solo lectura deshecha; no se ha escrito nada\n";
