<?php
// SOLO LECTURA (lead, 23-11-2026 · unidad 3 del cierre operativo, PT-17). Se ejecuta en el servidor como www-data, ANTES y
// DESPUÉS del despliegue del compilador 5, para comparar:
//     ssh ironbody-vps "cd /var/www/api/backend && sudo -u www-data php" < sonda-paquete-y-oferta.php
//  1. El paquete de prompts que el compilador desplegado hace con el contenido de la versión en canario: de cada papel, su
//     tamaño y su huella (nunca el texto) y si lleva el tope de longitud, el registro y el texto de la lista de planes.
//     Sirve para ver qué papeles cambian con el compilador nuevo y cuáles no (los dos revisores no deben cambiar).
//  2. Lo que `decide` ofrecería AHORA (la misma clase que usa el motor): a la conversación canaria, a la conversación con
//     actividad más reciente que no es la canaria (un cliente real) y a una que no existe. Solo el modo y el motivo.
//  3. La cadena de publicaciones del Studio: cuántas entradas y cuál es la cabeza (sin nombres de personas).
// No llama a ningún modelo, no sale a la red y no escribe: todo dentro de una transacción de SOLO LECTURA que se deshace.
// No imprime ids de conversaciones, teléfonos, nombres ni textos de prompts.
declare(strict_types=1);

use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Support\Facades\DB;

require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const VERSION = '39cfae9f-19a1-44bd-8d8e-f9f3327fed81';

DB::beginTransaction();
DB::statement('SET TRANSACTION READ ONLY');
try {
    $v = StudioVersion::query()->where('uuid', VERSION)->firstOrFail();
    $p = StudioPromptBundle::rolesFor(StudioPromptBundle::governedFrom((array) $v->content), $v->uuid, $v->content_hash);
    printf("[1] compilador desplegado: %d · versión «%s» (%s) · huella del paquete que compila hoy: %s\n", UltronPromptCompiler::VERSION, $v->label, $v->status, substr((string) $p['bundleHash'], 0, 16));
    foreach ($p['roles'] as $rol => $texto) {
        $t = (string) $texto;
        printf("    %-16s %6d bytes · sha256 %s · tope de longitud: %s · registro: %s · texto de la lista de planes: %s\n", $rol, strlen($t), substr(hash('sha256', $t), 0, 16),
            (str_contains($t, 'TOPE DE LONGITUD PUBLICADO') || str_contains($t, 'TOPE PUBLICADO')) ? 'sí' : 'no',
            str_contains($t, 'REGISTRO PUBLICADO') ? 'sí' : 'no',
            str_contains($t, 'LISTA DE PLANES DEL BACKEND') ? 'sí' : 'no');
    }

    $oferta = app(UltronPromptOffer::class);
    $can = config('marketing.ultron.canary_conversation_id');
    $dice = static function (array $r): string {
        return sprintf('%s · versión %s · huella %s · motivo %s', $r['mode'] ?? '?', $r['version'] === null ? '-' : substr((string) $r['version'], 0, 8),
            $r['bundle_hash'] === null ? '-' : substr((string) $r['bundle_hash'], 0, 8), $r['reason'] ?? '-');
    };
    echo "[2] llave de prompts: ".UltronPromptOffer::key()." · lo que decide ofrecería ahora:\n";
    echo '    a la conversación CANARIA .................. '.$dice($oferta->forConversation($can === null ? 0 : (int) $can)['response'])."\n";
    $otra = DB::table('marketing_conversations')->when($can !== null, fn ($q) => $q->where('id', '<>', (int) $can))->orderByDesc('last_message_at')->value('id');
    echo '    a un cliente real (la conversación con actividad más reciente que no es la canaria) ... '.($otra === null ? 'no hay' : $dice($oferta->forConversation((int) $otra)['response']))."\n";
    echo '    a una conversación que no existe ........... '.$dice($oferta->forConversation(0)['response'])."\n";

    $n = DB::table('ultron_studio_publications')->count();
    $cab = DB::table('ultron_studio_publications')->orderByDesc('seq')->first();
    printf("[3] publicaciones del Studio: %d · cabeza: entrada %s (%s, %s UTC)\n", $n, $cab->seq ?? '?', $cab->action ?? '?', substr((string) ($cab->created_at ?? ''), 0, 19));
    echo '    versiones por estado: '.DB::table('ultron_studio_versions')->selectRaw('status, count(*) n')->groupBy('status')->orderBy('status')->get()->map(fn ($r) => $r->status.'='.$r->n)->implode(' · ')."\n";
} catch (Throwable $e) {
    echo 'NO SE PUDO: '.get_class($e).' · '.substr($e->getMessage(), 0, 240)."\n";
} finally {
    DB::rollBack();
}
echo "transacción de solo lectura deshecha; no se ha escrito nada\n";
