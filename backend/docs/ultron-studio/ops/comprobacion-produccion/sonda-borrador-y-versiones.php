<?php
// SOLO LECTURA (lead, 23-11-2026 · tras desplegar la unidad 3). Se ejecuta en el servidor como www-data:
//     ssh ironbody-vps "cd /var/www/api/backend && sudo -u www-data php" < sonda-borrador-y-versiones.php
// Para guiar la creación de la versión 4 «con el contenido de la 3»: qué versiones hay y, de cada borrador guardado, qué
// huella de contenido tendría la versión que se creara AHORA desde él (el mismo cálculo que `StudioVersions::create()`),
// para ver si coincide con la de la versión en canario. De las personas, solo el id interno de su cuenta y su rol (nunca
// el nombre ni el correo). No escribe: transacción de SOLO LECTURA que se deshace.
declare(strict_types=1);

use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioDraftNormalizer;
use App\Services\UltronStudio\StudioGraphValidator;
use App\Services\UltronStudio\StudioPromptBundle;
use App\Services\UltronStudio\StudioRules;
use Illuminate\Support\Facades\DB;

require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const CANARIA = '39cfae9f-19a1-44bd-8d8e-f9f3327fed81';

DB::beginTransaction();
DB::statement('SET TRANSACTION READ ONLY');
try {
    echo "[1] versiones del Studio (de la más antigua a la más nueva):\n";
    $v3 = null;
    foreach (StudioVersion::query()->orderBy('id')->get() as $v) {
        printf("    %s · «%s» · %s · contenido %s · creada %s UTC por la cuenta %d (%s) · formato %s\n", substr($v->uuid, 0, 8), $v->label, $v->status, substr((string) $v->content_hash, 0, 8),
            substr((string) $v->created_at, 0, 16), (int) $v->created_by_admin_id, (string) $v->created_by_role, (string) (($v->content['format'] ?? '?')));
        if ($v->uuid === CANARIA) {
            $v3 = $v;
        }
    }
    echo "    revisiones de versiones (decisión · cuenta que revisó · rol): ".DB::table('ultron_studio_version_reviews')->orderBy('id')->get()->map(fn ($r) => ($r->decision ?? '?').' · '.(int) ($r->reviewer_admin_id ?? $r->admin_id ?? 0).' · '.($r->reviewer_role ?? $r->role ?? '?'))->implode(' | ')."\n";
    echo "    veredictos del laboratorio (decisión · cuenta · rol): ".DB::table('ultron_studio_lab_verdicts')->orderBy('id')->get()->map(fn ($r) => ($r->decision ?? '?').' · '.(int) ($r->admin_id ?? $r->created_by_admin_id ?? $r->reviewer_admin_id ?? 0).' · '.($r->admin_role ?? $r->created_by_role ?? $r->reviewer_role ?? '?'))->implode(' | ')."\n";

    echo "[2] borradores guardados, y la versión que saldría AHORA de cada uno:\n";
    $reglas = StudioRules::snapshotForVersion(StudioContentHasher::LATEST_FORMAT);
    foreach (DB::table('ultron_studio_drafts')->orderBy('id')->get() as $d) {
        try {
            $content = StudioDraftNormalizer::normalize(is_string($d->content) ? json_decode($d->content, true) : (array) $d->content);
            $copia = ['catalogVersion' => StudioCatalog::VERSION, 'format' => StudioContentHasher::LATEST_FORMAT, 'definition' => $content['definition'], 'configs' => $content['configs'], 'layout' => $content['layout'], 'rules' => $reglas];
            $hash = StudioContentHasher::versionHash(StudioCatalog::VERSION, $copia, $reglas);
            $gob = StudioPromptBundle::governedFrom($copia);
            $paq = StudioPromptBundle::rolesFor($gob, null, $hash);
            $igual = $v3 !== null && hash_equals((string) $v3->content_hash, $hash);
            $mismoPaquete = $v3 !== null && StudioPromptBundle::rolesFor(StudioPromptBundle::governedFrom((array) $v3->content), null, null)['bundleHash'] === $paq['bundleHash'];
            printf("    borrador de la cuenta %d · revisión %d · guardado %s UTC · huella de contenido que saldría: %s (%s a la de la versión en canario) · paquete de prompts con el compilador de hoy: %s (%s al de la versión en canario)\n",
                (int) $d->admin_id, (int) $d->revision, substr((string) $d->updated_at, 0, 16), substr($hash, 0, 8), $igual ? 'IGUAL' : 'DISTINTA', substr((string) $paq['bundleHash'], 0, 8), $mismoPaquete ? 'IGUAL' : 'DISTINTO');
            if (! $igual && $v3 !== null) {
                $a = (array) $v3->content; $dif = [];
                foreach (['definition', 'configs', 'layout', 'rules'] as $k) {
                    if (json_encode($a[$k] ?? null) !== json_encode($copia[$k] ?? null)) { $dif[] = $k; }
                }
                echo '      lo que difiere de la versión en canario: '.($dif === [] ? 'nada en definition, configs, layout ni rules (solo el formato o el catálogo)' : implode(', ', $dif))."\n";
                if (in_array('configs', $dif, true)) {
                    $ca = $a['configs'] ?? []; $cb = $copia['configs'] ?? []; $claves = [];
                    foreach (array_unique(array_merge(array_keys($ca), array_keys($cb))) as $capa) {
                        foreach (array_unique(array_merge(array_keys((array) ($ca[$capa] ?? [])), array_keys((array) ($cb[$capa] ?? [])))) as $c) {
                            if (json_encode($ca[$capa][$c] ?? null) !== json_encode($cb[$capa][$c] ?? null)) { $claves[] = "$capa.$c"; }
                        }
                    }
                    echo '      claves de configuración distintas (solo nombres): '.implode(', ', array_slice($claves, 0, 20))."\n";
                }
            }
        } catch (Throwable $e) {
            printf("    borrador de la cuenta %d · revisión %d · no se pudo calcular: %s\n", (int) $d->admin_id, (int) $d->revision, substr(get_class($e).' '.$e->getMessage(), 0, 160));
        }
    }
    echo '[3] reglas vivas del Studio: '.count($reglas).' · catálogo: '.StudioCatalog::VERSION."\n";
} catch (Throwable $e) {
    echo 'NO SE PUDO: '.get_class($e).' · '.substr($e->getMessage(), 0, 240)."\n";
} finally {
    DB::rollBack();
}
echo "transacción de solo lectura deshecha; no se ha escrito nada\n";
