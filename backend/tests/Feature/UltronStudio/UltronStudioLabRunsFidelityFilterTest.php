<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabRun;
use App\Services\UltronStudio\StudioLabEngineFlow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * APARTADO 5 DEL CIERRE: `GET lab/runs?fidelity=simulation|engine` (confirmado
 * por el lead el 22-11-2026), por HTTP y con las clases reales.
 *
 * SIN EL PARÁMETRO, LA LISTA DE SIEMPRE: las consultas, el orden, la paginación
 * y la forma se comparan con lo capturado con el código ANTERIOR al cambio
 * (`91d1c5f6`, iguales en SQLite y en PostgreSQL; las listas de ids de las
 * cargas, normalizadas, porque en PostgreSQL las secuencias no vuelven atrás).
 * Un resumen de simulación no gana ninguna clave; el de una del motor gana
 * `fidelityMode: "engine"`. Con el parámetro, solo las de su modo, también
 * junto a `version=`; otro valor, 422. Y la marca (`prompt_version` que empieza
 * por `motor-`) está escrita en un solo sitio y la leen igual la lista y la puerta.
 */
class UltronStudioLabRunsFidelityFilterTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** Las consultas de la lista con el código de antes (solo las de las tablas del laboratorio y las versiones). */
    private const SQL_DE_ANTES = [
        'runs' => ['select count(*) as aggregate from "ultron_studio_lab_runs"', 'select "id", "uuid", "target_kind", "target_version_id", "target_draft_admin_id", "target_label", "target_content_hash", "mode", "model", "prompt_version", "status", "stop_reason", "case_count", "estimate_max_micros", "cost_micros", "ai_recommendation", "created_by_admin_id", "created_by_name", "created_by_role", "cancel_requested_at", "started_at", "finished_at", "created_at", "summary" from "ultron_studio_lab_runs" order by "id" desc limit 20 offset 0', 'select * from "ultron_studio_lab_verdicts" where "ultron_studio_lab_verdicts"."run_id" in (…)', 'select "id", "uuid", "created_by_admin_id" from "ultron_studio_versions" where "ultron_studio_versions"."id" in (…)', 'select "run_id", "status", "outcome", COUNT(*) AS n from "ultron_studio_lab_run_cases" where "run_id" in (?, ?) group by "run_id", "status", "outcome"'],
        'runs?version=VERSION' => ['select count(*) as aggregate from "ultron_studio_lab_runs" where "target_version_id" in (select "id" from "ultron_studio_versions" where "uuid" = ?)', 'select "id", "uuid", "target_kind", "target_version_id", "target_draft_admin_id", "target_label", "target_content_hash", "mode", "model", "prompt_version", "status", "stop_reason", "case_count", "estimate_max_micros", "cost_micros", "ai_recommendation", "created_by_admin_id", "created_by_name", "created_by_role", "cancel_requested_at", "started_at", "finished_at", "created_at", "summary" from "ultron_studio_lab_runs" where "target_version_id" in (select "id" from "ultron_studio_versions" where "uuid" = ?) order by "id" desc limit 20 offset 0', 'select * from "ultron_studio_lab_verdicts" where "ultron_studio_lab_verdicts"."run_id" in (…)', 'select "id", "uuid", "created_by_admin_id" from "ultron_studio_versions" where "ultron_studio_versions"."id" in (…)', 'select "run_id", "status", "outcome", COUNT(*) AS n from "ultron_studio_lab_run_cases" where "run_id" in (?) group by "run_id", "status", "outcome"'],
        'runs?per_page=1&page=2' => ['select count(*) as aggregate from "ultron_studio_lab_runs"', 'select "id", "uuid", "target_kind", "target_version_id", "target_draft_admin_id", "target_label", "target_content_hash", "mode", "model", "prompt_version", "status", "stop_reason", "case_count", "estimate_max_micros", "cost_micros", "ai_recommendation", "created_by_admin_id", "created_by_name", "created_by_role", "cancel_requested_at", "started_at", "finished_at", "created_at", "summary" from "ultron_studio_lab_runs" order by "id" desc limit 1 offset 1', 'select * from "ultron_studio_lab_verdicts" where "ultron_studio_lab_verdicts"."run_id" in (…)', 'select "run_id", "status", "outcome", COUNT(*) AS n from "ultron_studio_lab_run_cases" where "run_id" in (?) group by "run_id", "status", "outcome"'],
    ];

    private const CLAVES_DE_LA_PAGINA = ['current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total'];

    private const CLAVES_DEL_RESUMEN = ['uuid', 'status', 'statusLabel', 'stopReason', 'budgetCap', 'expired', 'cancelRequested', 'target', 'mode', 'model', 'promptVersion', 'caseCount', 'progress', 'outcomes', 'aiRecommendation', 'cost', 'createdBy', 'mine', 'createdAt', 'startedAt', 'finishedAt', 'verdict'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->labFake();
        $this->importBaseline();
        $this->importLabScenarios();
    }

    public function test_sin_el_parametro_la_lista_es_la_de_antes_consultas_orden_paginacion_y_forma(): void
    {
        [$root, $version, $runs] = $this->mundo();

        foreach (['runs' => 'runs', 'runs?version=VERSION' => "runs?version={$version}", 'runs?per_page=1&page=2' => 'runs?per_page=1&page=2'] as $clave => $ruta) {
            [$pagina, $sql] = $this->listWithQueries($root, $ruta);
            $this->assertSame(array_map(self::normalize(...), self::SQL_DE_ANTES[$clave]), $sql, "las consultas de {$clave}");
            $this->assertSame(self::CLAVES_DE_LA_PAGINA, array_keys($pagina), "la página de {$clave}");
        }

        // El orden y la paginación: todas, por id descendente.
        $todas = $this->lab('GET', 'runs', $root)->assertOk()->json('data');
        $this->assertSame(StudioLabRun::query()->orderByDesc('id')->pluck('uuid')->all(), array_column($todas['data'], 'uuid'));
        $this->assertSame([4, 20], [$todas['total'], $todas['per_page']]);
        $segunda = $this->lab('GET', 'runs?per_page=1&page=2', $root)->assertOk()->json('data');
        $this->assertSame([$todas['data'][1]['uuid']], array_column($segunda['data'], 'uuid'));

        // La forma: una simulación, exactamente las claves de antes; una del motor, además `fidelityMode`.
        foreach ($todas['data'] as $r) {
            $motor = in_array($r['uuid'], $runs['engine'], true);
            $this->assertSame($motor ? [...self::CLAVES_DEL_RESUMEN, 'fidelityMode'] : self::CLAVES_DEL_RESUMEN, array_keys($r), $r['uuid']);
            if ($motor) {
                $this->assertSame('engine', $r['fidelityMode']);
                $this->assertStringStartsWith(StudioLabEngineFlow::PROMPT_VERSION_PREFIX, $r['promptVersion']);
            }
        }
    }

    public function test_con_el_parametro_solo_las_de_su_modo_tambien_con_la_version(): void
    {
        [$root, $version, $runs] = $this->mundo();

        $motor = $this->lab('GET', 'runs?fidelity=engine', $root)->assertOk()->json('data');
        $this->assertSame(array_reverse($runs['engine']), array_column($motor['data'], 'uuid'));
        $this->assertSame(2, $motor['total']);
        $sim = $this->lab('GET', 'runs?fidelity=simulation', $root)->assertOk()->json('data');
        $this->assertSame(array_reverse($runs['simulation']), array_column($sim['data'], 'uuid'));
        foreach ($sim['data'] as $r) {
            $this->assertSame(self::CLAVES_DEL_RESUMEN, array_keys($r));
        }

        // «La última ejecución frente al motor de esta versión», en una sola llamada.
        $ultima = $this->lab('GET', "runs?version={$version}&fidelity=engine&per_page=1", $root)->assertOk()->json('data');
        $this->assertSame([$runs['engine'][0]], array_column($ultima['data'], 'uuid'));
        $this->assertSame(1, $ultima['total']);

        // La consulta lleva la marca como parámetro, con el prefijo de su único sitio.
        $enlaces = [];
        DB::listen(function (QueryExecuted $q) use (&$enlaces): void {
            // PostgreSQL escribe `"prompt_version"::text like ?`; SQLite, sin el `::text`.
            if (preg_match('/prompt_version"(?:::text)? (?:not )?like \?/', $q->sql) === 1) {
                $enlaces[] = $q->bindings;
            }
        });
        $this->lab('GET', 'runs?fidelity=engine', $root)->assertOk();
        $this->lab('GET', 'runs?fidelity=simulation', $root)->assertOk();
        $this->assertContains([StudioLabEngineFlow::PROMPT_VERSION_PREFIX.'%'], $enlaces);
        $this->assertCount(4, $enlaces, 'el recuento y la página de cada una');

        foreach (['otra', 'ENGINE', '', 'motor'] as $malo) {
            $this->lab('GET', 'runs?fidelity='.urlencode($malo), $root)->assertStatus(422)->assertJsonValidationErrors(['fidelity']);
        }
    }

    public function test_la_marca_del_motor_esta_en_un_solo_sitio_y_la_leen_igual_la_lista_y_la_puerta(): void
    {
        $this->assertStringStartsWith(StudioLabEngineFlow::PROMPT_VERSION_PREFIX, StudioLabEngineFlow::promptVersion());
        $prefijo = [];
        foreach (glob(app_path('Services/UltronStudio/*.php')) as $ruta) {
            $codigo = (string) file_get_contents($ruta);
            $this->assertDoesNotMatchRegularExpression("/['\"]motor-%['\"]/", $codigo, basename($ruta).' escribe la marca a mano');
            if (str_contains($codigo, 'PROMPT_VERSION_PREFIX')) {
                $prefijo[] = basename($ruta);
            }
        }
        sort($prefijo);
        $this->assertSame(['StudioLabEngineFlow.php', 'StudioLabRuns.php'], $prefijo, 'la marca: escrita en el flujo, aplicada en whereFidelity()');
        $this->assertStringContainsString('StudioLabRuns::whereFidelity(', (string) file_get_contents(app_path('Services/UltronStudio/StudioLabVerdicts.php')), 'la puerta usa la misma función');
        $this->assertStringContainsString('self::whereFidelity(', (string) file_get_contents(app_path('Services/UltronStudio/StudioLabRuns.php')), 'la lista usa la misma función');
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    /**
     * Dos simulaciones y dos ejecuciones del motor (`fake`): una de cada, sobre
     * una versión y sobre la línea base, en este orden.
     *
     * @return array{0:Admin,1:string,2:array{simulation:list<string>,engine:list<string>}}
     */
    private function mundo(): array
    {
        Queue::fake();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $version = (string) $this->createVersion($root, 'V')->assertCreated()->json('data.uuid');
        $runs = ['simulation' => [], 'engine' => []];
        // Primero sobre la versión y después sobre la línea base: la página 2 de uno en uno cae, como en la captura, en una de la línea base.
        foreach ([['simulation', ['kind' => 'version', 'uuid' => $version]], ['engine', ['kind' => 'version', 'uuid' => $version]], ['simulation', ['kind' => 'baseline']], ['engine', ['kind' => 'baseline']]] as [$modo, $objetivo]) {
            $cuerpo = ['target' => $objetivo, 'scenarioUuids' => [$this->scenarioUuid('C-01')]] + ($modo === 'engine' ? ['fidelity' => 'engine'] : []);
            $max = $this->lab('POST', 'estimate', $root, $cuerpo)->assertOk()->json('data.estimate.maxMicros');
            $runs[$modo][] = (string) $this->lab('POST', 'runs', $root, $cuerpo + ['acceptedMaxCostMicros' => $max, 'clientRequestId' => (string) Str::uuid()])->assertCreated()->json('data.uuid');
        }

        return [$root, $version, $runs];
    }

    /** Las listas de ids o de marcadores de las cargas, sin su longitud (dependen de cuántas filas hay). */
    private static function normalize(string $sql): string
    {
        return (string) preg_replace('/ in \((?:\?|\d+|…)(?:, (?:\?|\d+))*\)/', ' in (…)', $sql);
    }

    /** @return array{0:array<string,mixed>,1:list<string>} la página y sus consultas del laboratorio y las versiones, con las listas normalizadas */
    private function listWithQueries(Admin $as, string $ruta): array
    {
        $sql = [];
        $oyendo = true;
        DB::listen(function (QueryExecuted $q) use (&$sql, &$oyendo): void {
            if ($oyendo && preg_match('/ultron_studio_(lab_|versions)/', $q->sql) === 1) {
                $sql[] = self::normalize($q->sql);
            }
        });
        $pagina = $this->lab('GET', $ruta, $as)->assertOk()->json('data');
        $oyendo = false;

        return [$pagina, $sql];
    }
}
