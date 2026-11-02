<?php
/*
 * Siembra del banco para medir las 3 rutas de los presupuestos que dependen del estado (smoke-h7.py, fase de
 * presupuestos): una versión en CANARY con su reseña aprobada y la entrada `publish` de la cadena FIRMADA con la clave
 * del banco cuya parte canaria la nombra (seq = cabeza + 1 apuntando a la cabeza: el disparador del H7 lo exige).
 * Calca PublicationFixtures::seededVersion() y seedEntry() de las pruebas y la siembra de PERF7. Es un estado de
 * JUGUETE (sin pasar por la máquina de estados ni por la puerta del laboratorio): sirve para cronometrar la
 * evidencia del canario y el detalle de la versión, no para ensayar la publicación (eso lo prueban las suites del H5).
 * La ejecución del laboratorio la lanza sembrar-presupuestos.sh por la API (conductor fake) y la termina este mismo
 * fichero con «drain <uuid>» (StudioLabRunner::step, como LabFixtures::drain).
 * Uso: php sembrar-presupuestos.php <directorio backend del banco> canary <id autora> <id revisora>
 *      php sembrar-presupuestos.php <directorio backend del banco> drain <uuid de la ejecución>
 * Imprime una línea; nunca secretos.
 */
declare(strict_types=1);

[$_, $dir, $orden] = $argv + [null, null, null];
(is_string($dir) && is_dir($dir) && in_array($orden, ['canary', 'drain'], true)) || exit("uso: php sembrar-presupuestos.php <backend> canary <autora> <revisora> | drain <uuid>\n");
require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVersionReview;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioDrafts;
use App\Services\UltronStudio\StudioGraphValidator;
use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioRuleCoherence;
use App\Services\UltronStudio\StudioRules;
use App\Services\UltronStudio\StudioVersions;
use Illuminate\Support\Str;

if ($orden === 'drain') {
    $uuid = (string) ($argv[3] ?? '');
    $run = StudioLabRun::query()->where('uuid', $uuid)->firstOrFail();
    $runner = app(StudioLabRunner::class);
    for ($i = 0; $i < 80 && ! $run->refresh()->isTerminal(); $i++) {
        $runner->step($run->id);
    }
    echo json_encode(['run' => $uuid, 'status' => $run->status, 'steps' => $i, 'cases' => (int) $run->case_count]), PHP_EOL;
    exit($run->isTerminal() ? 0 : 1);
}

$autora = Admin::query()->findOrFail((int) ($argv[3] ?? 0));
$revisora = Admin::query()->findOrFail((int) ($argv[4] ?? 0));

// 1. La versión, como StudioFixtures::seedVersion() con la línea base, en CANARY.
$formato = StudioContentHasher::LATEST_FORMAT;
$contenido = StudioDrafts::baselineContent();
$reglas = StudioRules::snapshotForVersion($formato);
$copia = ['catalogVersion' => StudioCatalog::VERSION] + ($formato === StudioContentHasher::FORMAT_1 ? [] : ['format' => $formato]) + $contenido + ['rules' => $reglas];
$hallazgos = [
    ...StudioGraphValidator::validate(StudioCatalog::graph(), $contenido['definition'], $contenido['configs']),
    ...StudioRuleCoherence::evaluate($reglas, $contenido['definition'], $contenido['configs'], StudioCatalog::toArray(), $formato),
];
$version = StudioVersion::query()->forceCreate([
    'client_request_id' => (string) Str::uuid(),
    'label' => 'Banco H7: versión en canario (presupuestos)',
    'status' => 'CANARY',
    'content' => $copia,
    'content_hash' => StudioContentHasher::versionHash(StudioCatalog::VERSION, $copia, $reglas),
    'catalog_version' => StudioCatalog::VERSION,
    'catalog_hash' => StudioCatalog::hash(),
    'source_draft_revision' => 1,
    'created_by_admin_id' => $autora->id,
    'created_by_name' => $autora->name,
    'created_by_role' => $autora->role,
    'last_validation' => [
        'catalogVersion' => StudioCatalog::VERSION,
        'validatedAt' => now()->toIso8601String(),
        'publishable' => StudioGraphValidator::isPublishable($hallazgos),
        'warnings' => StudioGraphValidator::warnings($hallazgos),
        'findings' => $hallazgos,
    ],
    'submitted_at' => now(),
    'status_changed_at' => now(),
]);

// 2. Su reseña aprobada por otra persona (PublicationFixtures::seededVersion()).
$avisos = StudioGraphValidator::warnings(StudioVersions::findings($version->content));
StudioVersionReview::query()->forceCreate([
    'version_id' => $version->id,
    'decision' => StudioVersionReview::DECISION_APPROVED,
    'reviewer_admin_id' => $revisora->id,
    'reviewer_name' => $revisora->name,
    'reviewer_role' => $revisora->role,
    'note' => null,
    'acknowledged_warnings' => $avisos,
    'validation' => ['catalogVersion' => $version->catalog_version, 'warnings' => $avisos],
    'content_hash' => $version->content_hash,
]);

// 3. La entrada `publish` firmada cuya parte canaria es esta versión (PublicationFixtures::seedEntry()).
$cabeza = StudioPublication::query()->orderByDesc('seq')->first();
$seq = ($cabeza !== null ? (int) $cabeza->seq : 0) + 1;
$uuid = strtolower((string) Str::uuid());
$parte = D::part((string) $version->uuid, (string) $version->content_hash, (string) $version->catalog_version, $revisora->id, $autora->id, D::declared($version->content['configs']));
$d = D::declaration($uuid, $seq, now(), $parte, null, UltronPublishedConfig::signatureFor(...));
StudioPublication::query()->forceCreate([
    'uuid' => $uuid, 'seq' => $seq, 'client_request_id' => (string) Str::uuid(), 'action' => D::ACTION_PUBLISH,
    'version_id' => $version->id, 'restores_publication_id' => null, 'previous_publication_id' => $cabeza?->id,
    'engine_contract' => $d['contract'], 'effective_json' => $d['effectiveJson'], 'effective_hash' => $d['effectiveHash'],
    'signature' => $d['signature'], 'catalog_version' => (string) $version->catalog_version, 'lab_gate' => ['mode' => 'waived'],
    'acknowledged' => null, 'evidence' => null, 'reason' => null,
    'actor_admin_id' => $autora->id, 'actor_name' => $autora->name, 'actor_role' => $autora->role, 'created_at' => now(),
]);

echo json_encode(['version' => 'CANARY', 'seq' => $seq, 'verified' => StudioPublication::query()->where('uuid', $uuid)->count() === 1]), PHP_EOL;
