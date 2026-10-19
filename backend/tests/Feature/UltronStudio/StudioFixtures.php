<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioDrafts;
use App\Services\UltronStudio\StudioGraphValidator;
use App\Services\UltronStudio\StudioRuleCoherence;
use App\Services\UltronStudio\StudioRules;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Datos y peticiones de ULTRON STUDIO para las pruebas Feature.
 *
 * Las peticiones van con una sesión REAL de cada persona (`actingAsAdmin`),
 * nunca con el token compartido salvo donde se prueba precisamente eso: los
 * cuatro ojos comparan identidades y sin dos personas distintas no se prueba
 * nada.
 */
trait StudioFixtures
{
    /** @var array<int, array<string, string>> */
    private array $sesiones = [];

    protected function person(string $role = Admin::ROLE_ADMINISTRADOR, string $name = 'Ana Pérez'): Admin
    {
        return Admin::create([
            'name' => $name,
            'email' => 'studio-'.Str::random(10).'@ironbody.test',
            'password' => 'secret-password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /** @return array<string, string> */
    protected function as(Admin $admin): array
    {
        return $this->sesiones[$admin->id] ??= $this->actingAsAdmin($admin);
    }

    /** @param  array<string, mixed>  $body */
    protected function studio(string $method, string $path, Admin $as, array $body = []): TestResponse
    {
        return $this->json($method, '/api/admin/ultron-studio/'.ltrim($path, '/'), $body, $this->as($as));
    }

    /** @return array<string, mixed> La línea base en forma canónica. */
    protected function baseline(): array
    {
        return StudioDrafts::baselineContent();
    }

    /**
     * La línea base con cambios en la configuración.
     *
     * @param  array<string, array<string, mixed>>  $configs
     * @return array<string, mixed>
     */
    protected function contentWith(array $configs = [], ?array $edgeIds = null): array
    {
        $c = $this->baseline();
        foreach ($configs as $capa => $valores) {
            $c['configs'][$capa] = array_merge($c['configs'][$capa], $valores);
        }
        if ($edgeIds !== null) {
            $c['definition']['edgeIds'] = $edgeIds;
        }

        return $c;
    }

    /** @param  array<string, mixed>|null  $content */
    protected function saveDraft(Admin $as, ?array $content = null, int $baseRevision = 0): TestResponse
    {
        return $this->studio('PUT', 'draft', $as, ['baseRevision' => $baseRevision, 'content' => $content ?? $this->baseline()]);
    }

    /** Guarda (si hace falta) el borrador y crea una versión. Devuelve la respuesta de POST versions. */
    protected function createVersion(Admin $as, string $label = 'Propuesta de prueba', ?string $clientRequestId = null, ?array $content = null): TestResponse
    {
        $actual = $this->studio('GET', 'draft', $as)->assertOk()->json('data');
        if ($content !== null || ! $actual['exists']) {
            $actual = $this->saveDraft($as, $content, $actual['revision'])->assertOk()->json('data');
        }

        return $this->studio('POST', 'versions', $as, [
            'label' => $label,
            'note' => null,
            'draftRevision' => $actual['revision'],
            'clientRequestId' => $clientRequestId ?? (string) Str::uuid(),
        ]);
    }

    /** Crea una versión y la envía a revisión. Devuelve su uuid. */
    protected function submittedVersion(Admin $creator, string $label = 'Propuesta enviada'): string
    {
        $uuid = (string) $this->createVersion($creator, $label)->assertCreated()->json('data.uuid');
        $this->studio('POST', "versions/{$uuid}/submit", $creator)->assertOk();

        return $uuid;
    }

    protected function importBaseline(): void
    {
        $this->assertSame(0, Artisan::call('ultron-studio:import-baseline'));
    }

    /**
     * Una versión sembrada DIRECTAMENTE en la base, con su huella bien
     * calculada (salvo que se pida otra). Sirve para los estados y contenidos
     * a los que la API no deja llegar con el catálogo de hoy: una versión sin
     * avisos (VALIDATED), una con bloqueos que ya estaba enviada, una con la
     * huella alterada.
     *
     * `$format` (Hito G): 2, como las crea hoy la API (reglas con
     * `conditions`), o 1, con la forma exacta de F (sin `format` y con las
     * reglas reducidas a los campos de F).
     *
     * @param  array<string, mixed>  $content  {definition, configs, layout}
     */
    protected function seedVersion(Admin $creator, array $content, string $status = 'DRAFT', ?string $hash = null, int $format = StudioContentHasher::LATEST_FORMAT): StudioVersion
    {
        $reglas = StudioRules::snapshotForVersion($format);
        $copia = ['catalogVersion' => StudioCatalog::VERSION]
            + ($format === StudioContentHasher::FORMAT_1 ? [] : ['format' => $format])
            + $content + ['rules' => $reglas];
        $findings = [
            ...StudioGraphValidator::validate(StudioCatalog::graph(), $content['definition'], $content['configs']),
            ...StudioRuleCoherence::evaluate($reglas, $content['definition'], $content['configs'], StudioCatalog::toArray(), $format),
        ];

        return StudioVersion::query()->forceCreate([
            'client_request_id' => (string) Str::uuid(),
            'label' => 'Sembrada '.Str::random(4),
            'status' => $status,
            'content' => $copia,
            'content_hash' => $hash ?? StudioContentHasher::versionHash(StudioCatalog::VERSION, $copia, $reglas),
            'catalog_version' => StudioCatalog::VERSION,
            'catalog_hash' => StudioCatalog::hash(),
            'source_draft_revision' => 1,
            'created_by_admin_id' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_role' => $creator->role,
            'last_validation' => [
                'catalogVersion' => StudioCatalog::VERSION,
                'validatedAt' => now()->toIso8601String(),
                'publishable' => StudioGraphValidator::isPublishable($findings),
                'warnings' => StudioGraphValidator::warnings($findings),
                'findings' => $findings,
            ],
            'submitted_at' => $status === 'DRAFT' ? null : now(),
            'status_changed_at' => now(),
        ]);
    }

    /** La versión del catálogo de F: la de las versiones que ya hay en producción. */
    protected const CATALOG_F = '2026-10-18.1';

    /**
     * La huella de una versión de F con la línea base y las doce reglas del
     * código recién importadas, calculada con node desde F (el vector de
     * `StudioContentHasherTest`).
     */
    protected const F_BASELINE_VERSION_HASH = '667c9168ee6dc3951d6da09266e3ac677a0038641f07b32297fe83acf0a1abff';

    /**
     * Una versión EXACTAMENTE como la dejó el código de F en producción:
     * catálogo `2026-10-18.1`, sin `format`, y las doce reglas del código como
     * se copiaban entonces (revisión 1, sin `conditions` y sin las aristas que
     * añadió G). Con la línea base, su huella es {@see F_BASELINE_VERSION_HASH}.
     *
     * @param  array<string, array<string, mixed>>  $configs  cambios sobre la línea base
     */
    protected function seedFormat1Version(Admin $creator, string $status = 'DRAFT', array $configs = []): StudioVersion
    {
        $contenido = $this->contentWith($configs);
        $reglas = array_map(static fn (array $r): array => [
            'code' => $r['code'],
            'revision' => 1,
            'source' => 'legacy_baseline',
            'enforcement' => 'code',
            'status' => 'active',
            'description' => $r['description'],
            'type' => $r['type'],
            'layers' => $r['layers'],
            'priority' => $r['priority'],
            'refs' => array_replace($r['refs'], ['edges' => []]),
        ], StudioCatalog::legacyRules());
        $copia = ['catalogVersion' => self::CATALOG_F] + $contenido + ['rules' => $reglas];
        $findings = StudioGraphValidator::validate(StudioCatalog::graph(), $contenido['definition'], $contenido['configs']);

        return StudioVersion::query()->forceCreate([
            'client_request_id' => (string) Str::uuid(),
            'label' => 'De F '.Str::random(4),
            'status' => $status,
            'content' => $copia,
            'content_hash' => StudioContentHasher::versionHash(self::CATALOG_F, $copia, $reglas),
            'catalog_version' => self::CATALOG_F,
            'catalog_hash' => '704dd094ddbbd2a5ccbfec6de3989be15b078401dac9a74c0fd6316628268473',
            'source_draft_revision' => 1,
            'created_by_admin_id' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_role' => $creator->role,
            'last_validation' => [
                'catalogVersion' => self::CATALOG_F,
                'validatedAt' => now()->toIso8601String(),
                'publishable' => StudioGraphValidator::isPublishable($findings),
                'warnings' => StudioGraphValidator::warnings($findings),
                'findings' => $findings,
            ],
            'submitted_at' => $status === 'DRAFT' ? null : now(),
            'status_changed_at' => now(),
        ]);
    }

    /**
     * Contenido SIN AVISOS: todas las conexiones y la configuración sin la
     * bandera de cobro (una clave ausente no se comprueba). La API no lo deja
     * guardar así —el normalizador rellena la bandera desde la línea base—, y
     * con el catálogo de hoy toda versión real acaba en REVIEW_REQUIRED.
     *
     * @return array<string, mixed>
     */
    protected function contentWithoutWarnings(): array
    {
        $c = $this->baseline();
        $c['definition']['edgeIds'] = array_column(StudioCatalog::graph()['edges'], 'id');
        unset($c['configs']['rules']['paymentLinksEnabled']);

        return $c;
    }

    /**
     * Ejecuta `$alAntes` UNA vez, justo antes de la primera sentencia que
     * contenga `$fragmento`, en la misma conexión: es otra petición que se
     * adelanta entre la lectura y la escritura de esta.
     */
    protected function beforeStatement(string $fragmento, Closure $alAntes): void
    {
        $hecho = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$hecho, $fragmento, $alAntes): void {
            if ($hecho || ! str_contains($query, $fragmento)) {
                return;
            }
            $hecho = true;
            $alAntes();
        });
    }

    protected function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * POST rules (Hito G: con `conditions` si se pasan) con valores por
     * defecto razonables.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function newStudioRule(Admin $as, array $extra = []): TestResponse
    {
        return $this->studio('POST', 'rules', $as, $extra + [
            'description' => 'Regla del Studio para la prueba.',
            'type' => 'style',
            'priority' => 'normal',
            'layers' => ['persona'],
            'clientRequestId' => (string) Str::uuid(),
        ]);
    }

    /**
     * `limit <capa>.<clave> <lte|gte> <valor>` sobre un parámetro de rango.
     *
     * @return array<string, mixed>
     */
    protected static function limitOn(string $layer, string $key, string $op, int $value): array
    {
        return ['kind' => 'limit', 'target' => 'config', 'layer' => $layer, 'key' => $key, 'op' => $op, 'value' => $value];
    }

    /**
     * `require <capa>.<clave> eq <valor>`.
     *
     * @return array<string, mixed>
     */
    protected static function requireEq(string $layer, string $key, mixed $value): array
    {
        return ['kind' => 'require', 'target' => 'config', 'layer' => $layer, 'key' => $key, 'op' => 'eq', 'value' => $value];
    }

    /**
     * Unas condiciones en su forma canónica.
     *
     * @param  list<array<string, mixed>>  $then
     * @param  list<array<string, mixed>>  $when
     * @return array{when:list<array<string,mixed>>,then:list<array<string,mixed>>}
     */
    protected static function conditionsOf(array $then, array $when = []): array
    {
        return ['when' => $when, 'then' => $then];
    }
}
