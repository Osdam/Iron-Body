<?php

namespace Tests\PerfH7;

require_once __DIR__.'/PerfH7Seeding.php';

use App\Models\Admin;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Models\UltronStudio\StudioCampaignLink;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioLabScenario;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\UltronStudio\IntegrationFixtures;
use Tests\Feature\UltronStudio\LabFixtures;
use Tests\Feature\UltronStudio\PublicationFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\TestCase;

/**
 * PERF7 (Hito 7): arnés DETERMINISTA de consultas, bytes y tiempo EN PROCESO de
 * las rutas del Studio y del commit del motor, contra un PostgreSQL desechable.
 *
 * No es una prueba de la suite (vive fuera de tests/ y no afirma nada del
 * producto): mide y deja los resultados en JSON. Se lanza con run-queries.sh,
 * que fija la base (`ultron_studio_h7_perf*`), la caché `database` (como
 * producción: los limitadores y la caché de permisos cuentan) y la cola
 * `database` (el commit no ejecuta los trabajos en línea).
 *
 * Método por ruta y tamaño:
 *  1. UNA petición perfilada con DB::listen: número de sentencias, por tabla, y
 *     la sentencia normalizada más repetida (si crece con los datos, es N+1).
 *  2. Si PERF_N > 0: PERF_WARM peticiones de calentamiento y PERF_REPS tandas
 *     de PERF_N peticiones cronometradas (hrtime) → p50, p95 y máx por tanda.
 * Estado ESTABLE de los limitadores (como en producción dentro de su ventana):
 * una petición de cebado crea sus filas en `cache` y, antes de cada petición
 * medida, solo se pone su contador a 0 (fuera de la medida); la caché de
 * permisos queda caliente. El «primer golpe» de una ventana suma 4 sentencias
 * (dos `insert … on conflict do nothing` y sus lecturas) y se informa aparte.
 *
 * Los datos crecen de forma monótona dentro de cada familia (la cadena, el libro
 * y las instantáneas son de solo inserción: no hay TRUNCATE posible), y cada
 * familia empieza con `migrate:fresh` sobre la base propia.
 */
final class PerfH7QueriesTest extends TestCase
{
    use IntegrationFixtures, LabFixtures, PerfH7Seeding, PublicationFixtures, StudioFixtures {
        PublicationFixtures::personWith insteadof IntegrationFixtures;
    }

    private const SECRET = 'perf-h7-internal';

    private bool $capturando = false;

    /** @var list<array{0:string,1:float}> */
    private array $sentencias = [];

    /** @var list<array<string,mixed>> */
    private array $resultados = [];

    private string $familia = '';

    protected function setUp(): void
    {
        parent::setUp();

        $db = (string) DB::connection()->getDatabaseName();
        if (DB::connection()->getDriverName() !== 'pgsql' || ! str_starts_with($db, 'ultron_studio_h7_perf')) {
            $this->fail("Base no permitida para el arnés: {$db}. Usa run-queries.sh (ultron_studio_h7_perf*, pgsql).");
        }
        if (config('cache.default') !== 'database') {
            $this->fail('El arnés exige CACHE_STORE=database (como producción): lanza con run-queries.sh.');
        }

        // Cada familia parte de una base vacía y con el esquema de este árbol.
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));

        DB::listen(function (QueryExecuted $q): void {
            if ($this->capturando) {
                $this->sentencias[] = [$q->sql, (float) $q->time];
            }
        });
        Http::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->resultados !== []) {
            $dir = (string) (getenv('PERF_OUT_DIR') ?: base_path('docs/ultron-studio/ops/perf-h7/resultados'));
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $sufijo = (int) (getenv('PERF_N') ?: 0) > 0 ? 'tiempos' : 'consultas';
            file_put_contents(
                "{$dir}/{$this->familia}-{$sufijo}.json",
                json_encode(['family' => $this->familia, 'env' => self::entorno(), 'results' => $this->resultados], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
        }
        parent::tearDown();
    }

    // ── A · la página del Studio (forkJoin del CRM) y la lista de versiones ──

    public function test_a_carga_de_la_pagina_y_versiones(): void
    {
        $this->familia = 'a-pagina';
        $this->importBaseline();
        $this->openKeys(['marketing.ultron.studio_config' => 'canary']);
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Pérez');
        $luis = $this->person(Admin::ROLE_ADMINISTRADOR, 'Luis Gómez');
        // La cabeza existe (una publicación): `status.engine.publication` hace todo su trabajo.
        $publicada = $this->seededVersion($ana, $luis, $this->baseline(), 'PUBLISHED');
        $this->seedEntry(D::ACTION_PROMOTE, $publicada, null, $this->partOf($publicada));
        $this->saveDraft($ana)->assertOk();

        foreach ([1, 50, 200] as $n) {
            $this->crecerVersiones($ana, $luis, $n);
            $detalle = $publicada->uuid;
            $this->medir('GET status', $n, fn () => $this->studio('GET', 'status', $ana));
            $this->medir('GET catalog', $n, fn () => $this->studio('GET', 'catalog', $ana));
            $this->medir('GET draft', $n, fn () => $this->studio('GET', 'draft', $ana));
            $this->medir('GET rules', $n, fn () => $this->studio('GET', 'rules', $ana));
            $this->medir('GET versions (por defecto)', $n, fn () => $this->studio('GET', 'versions', $ana));
            $this->medir('GET versions?per_page=50', $n, fn () => $this->studio('GET', 'versions?per_page=50', $ana));
            $this->medir('GET versions/{uuid} (1 entrada)', $n, fn () => $this->studio('GET', "versions/{$detalle}", $ana));
            $this->medir('GET audit', $n, fn () => $this->studio('GET', 'audit', $ana));
            $this->medir('GET engine/readings', $n, fn () => $this->studio('GET', 'engine/readings', $ana));
        }
    }

    // ── B · la cadena: GET publications y el detalle de la versión con `verified` ──

    public function test_b_cadena_de_publicaciones(): void
    {
        $this->familia = 'b-cadena';
        $this->importBaseline();
        $this->openKeys(['marketing.ultron.studio_config' => 'canary']);
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Pérez');
        $luis = $this->person(Admin::ROLE_ADMINISTRADOR, 'Luis Gómez');
        $v = $this->seededVersion($ana, $luis, $this->baseline(), 'PUBLISHED');

        $hechas = 0;
        foreach ([1, 50, 200] as $n) {
            // Todas las entradas nombran la misma versión: su detalle lista n entradas (el peor caso de forVersion, sin LIMIT).
            for (; $hechas < $n; $hechas++) {
                $this->seedEntry($hechas % 2 === 0 ? D::ACTION_PUBLISH : D::ACTION_PROMOTE, $v, $this->partOf($v), $this->partOf($v));
            }
            $this->medir('GET publications (20)', $n, fn () => $this->studio('GET', 'publications', $ana), fn (TestResponse $r) => $this->todasVerificadas($r->json('data.data')));
            $this->medir('GET publications?per_page=50', $n, fn () => $this->studio('GET', 'publications?per_page=50', $ana), fn (TestResponse $r) => $this->todasVerificadas($r->json('data.data')));
            $this->medir('GET versions/{uuid} (n entradas)', $n, fn () => $this->studio('GET', "versions/{$v->uuid}", $ana), fn (TestResponse $r) => $this->todasVerificadas($r->json('data.publications')));
            $this->medir('GET status', $n, fn () => $this->studio('GET', 'status', $ana));
        }
    }

    // ── C · la evidencia del canario (turnos + acta) ─────────────────────────

    public function test_c_evidencia_del_canario(): void
    {
        $this->familia = 'c-evidencia';
        $this->importBaseline();
        $this->openKeys(['marketing.ultron.studio_config' => 'canary']);
        Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso completo'])]);
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Pérez');
        $luis = $this->person(Admin::ROLE_ADMINISTRADOR, 'Luis Gómez');
        $v = $this->seededVersion($ana, $luis, $this->baseline(), 'CANARY');
        $desde = CarbonImmutable::now()->subDays(5);
        $this->travelTo($desde);
        $this->seedEntry(D::ACTION_PUBLISH, $v, $this->partOf($v), null);
        $this->travelBack();

        // Fondo: otras conversaciones con su historial (≈ 6 meses de producción a ~100 filas/día).
        $fondo = (int) (getenv('PERF_BG_ACTIONS') ?: 20000);
        $this->sembrarFondo($fondo);

        $hechos = 0;
        foreach ([0, 40, 200, 1000] as $turnos) {
            $this->sembrarTurnosDelCanario((string) $v->uuid, $desde, $hechos, $turnos);
            $hechos = $turnos;
            $this->medir('GET versions/{uuid}/canary-evidence', $turnos, fn () => $this->studio('GET', "versions/{$v->uuid}/canary-evidence", $ana));
        }
    }

    // ── G · dónde se va el tiempo de la evidencia (descomposición, no suposición) ──

    public function test_g_descomposicion_de_la_evidencia(): void
    {
        $this->familia = 'g-evidencia-tramos';
        $this->importBaseline();
        $this->openKeys(['marketing.ultron.studio_config' => 'canary']);
        Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso completo'])]);
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN, 'Ana Pérez');
        $luis = $this->person(Admin::ROLE_ADMINISTRADOR, 'Luis Gómez');
        $v = $this->seededVersion($ana, $luis, $this->baseline(), 'CANARY');
        $desde = CarbonImmutable::now()->subDays(5);
        $this->travelTo($desde);
        $this->seedEntry(D::ACTION_PUBLISH, $v, $this->partOf($v), null);
        $this->travelBack();
        $this->sembrarFondo((int) (getenv('PERF_BG_ACTIONS') ?: 20000));
        $vueltas = max(3, (int) (getenv('PERF_N') ?: 3));

        $hechos = 0;
        foreach ([40, 200, 1000] as $turnos) {
            $this->sembrarTurnosDelCanario((string) $v->uuid, $desde, $hechos, $turnos);
            $hechos = $turnos;
            $tramos = [
                'ruta entera (en proceso)' => function () use ($ana, $v): void {
                    $this->limpiarLimitadores();
                    $this->studio('GET', "versions/{$v->uuid}/canary-evidence", $ana)->assertOk();
                },
                'StudioCanaryMeasures::measure (recuento + frenos + acta)' => fn () => app(\App\Services\UltronStudio\StudioCanaryMeasures::class)->measure((string) $v->uuid, $desde),
                'acta: ultron:canary-report --json (--turns ≤ 200)' => function () use ($desde, $turnos): void {
                    $salida = new \Symfony\Component\Console\Output\BufferedOutput;
                    Artisan::call('ultron:canary-report', ['conversation' => self::CANARY_ID, '--since' => D::utc($desde), '--turns' => min(200, max(40, $turnos)), '--json' => true], $salida);
                    $salida->fetch();
                },
                'lecturas del motor (only MUST_MATCH)' => fn () => app(\App\Services\UltronStudio\StudioEngineReadings::class)->only(\App\Services\UltronStudio\StudioEngineReadings::MUST_MATCH),
            ];
            foreach ($tramos as $nombre => $tramo) {
                $tramo();
                $this->sentencias = [];
                $this->capturando = true;
                $tramo();
                $this->capturando = false;
                $sentencias = $this->sentencias;
                $tiempos = [];
                for ($i = 0; $i < $vueltas; $i++) {
                    $t = hrtime(true);
                    $tramo();
                    $tiempos[] = (hrtime(true) - $t) / 1e6;
                }
                $this->resultados[] = ['route' => $nombre, 'size' => "{$turnos} turnos (mensajes del canario: ".(2 * $turnos).')', 'status' => 200, 'bytes' => 0]
                    + self::resumenSentencias($sentencias)
                    + ['timing' => ['reps' => [self::percentiles($tiempos)], 'all' => self::percentiles($tiempos), 'load1' => [sys_getloadavg()[0]]]];
            }
        }
    }

    // ── D · integraciones: estado, campañas con el espejo lleno, asociaciones ──

    public function test_d_integraciones(): void
    {
        $this->familia = 'd-integraciones';
        $this->importBaseline();
        $this->integrationsFlags(true);
        $super = $this->personWith(self::SUPER_KEYS, 'Root de integraciones');
        $otra = $this->personWith(self::SUPER_KEYS, 'Segunda persona');
        $this->engineCanary('+573001234567');
        $this->metaConfigured();
        foreach (['+573105550001', '+573105550002', '+573105550003'] as $i => $tel) {
            $this->activeCanary($super, $otra, $tel, 'whatsapp', 'Móvil de pruebas '.($i + 1));
        }
        $versiones = [
            $this->versionIn($super, 'APPROVED'),
            $this->versionIn($super, 'APPROVED'),
            $this->versionIn($super, 'PUBLISHED'),
        ];

        $campanas = 0;
        $enlaces = 0;
        foreach ([[0, 0], [114, 20], [500, 50]] as [$c, $l]) {
            $this->crecerEspejo($campanas, $c);
            $campanas = $c;
            $this->crecerEnlaces($super, $versiones, $enlaces, $l);
            $enlaces = $l;
            $etiqueta = "{$c} campañas / {$l} activas";
            $this->medir('GET integrations/status', $etiqueta, fn () => $this->integrations('GET', 'status', $super));
            $this->medir('GET integrations/meta/campaigns (20)', $etiqueta, fn () => $this->integrations('GET', 'meta/campaigns', $super));
            $this->medir('GET integrations/meta/campaigns?per_page=50', $etiqueta, fn () => $this->integrations('GET', 'meta/campaigns?per_page=50', $super));
            $this->medir('GET integrations/meta/campaigns?linked=yes', $etiqueta, fn () => $this->integrations('GET', 'meta/campaigns?linked=yes&per_page=50', $super));
            $this->medir('GET integrations/meta/links (20)', $etiqueta, fn () => $this->integrations('GET', 'meta/links', $super));
            $this->medir('GET integrations/canaries', $etiqueta, fn () => $this->integrations('GET', 'canaries', $super));
            $this->medir('GET integrations/config', $etiqueta, fn () => $this->integrations('GET', 'config', $super));
            $this->medir('GET integrations/twilio', $etiqueta, fn () => $this->integrations('GET', 'twilio', $super));
            $this->medir('GET integrations/voice', $etiqueta, fn () => $this->integrations('GET', 'voice', $super));
        }
    }

    // ── E · el laboratorio: lista y detalle de ejecuciones ───────────────────

    public function test_e_laboratorio(): void
    {
        $this->familia = 'e-laboratorio';
        $this->importBaseline();
        $this->importLabScenarios();
        $this->labFake(['ultron_studio.lab.max_runs_per_day' => 100000]);
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');

        // Una ejecución REAL con el conductor falso y 12 casos (el máximo por ejecución): el modelo de las demás.
        $codigos = StudioLabScenario::query()->orderBy('code')->limit(12)->pluck('code')->all();
        $uuid = (string) $this->launch($root, ['kind' => 'baseline'], $codigos)->assertCreated()->json('data.uuid');
        $modelo = $this->drain($uuid, 200);
        $this->assertTrue($modelo->isTerminal(), 'la ejecución modelo no terminó');

        $hechas = 1;
        foreach ([1, 50, 200] as $n) {
            for (; $hechas < $n; $hechas++) {
                $this->clonarEjecucion($modelo);
            }
            $this->medir('GET lab/status', $n, fn () => $this->lab('GET', 'status', $root));
            $this->medir('GET lab/runs (por defecto)', $n, fn () => $this->lab('GET', 'runs', $root));
            $this->medir('GET lab/runs?per_page=50', $n, fn () => $this->lab('GET', 'runs?per_page=50', $root));
            $this->medir('GET lab/runs/{uuid} (12 casos)', $n, fn () => $this->lab('GET', "runs/{$uuid}", $root));
            $this->medir('GET lab/scenarios', $n, fn () => $this->lab('GET', 'scenarios', $root));
            $this->medir('GET lab/events', $n, fn () => $this->lab('GET', 'events', $root));
        }
    }

    // ── F · el commit del motor con la llave en off / canary / all ───────────

    public function test_f_commit_del_motor(): void
    {
        $this->familia = 'f-commit';
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso completo'])]);
        $this->assertSame('database', config('queue.default'), 'el commit se mide con la cola database (como producción): run-queries.sh');

        $creadas = 0;
        foreach ([0, 1, 50] as $cadena) {
            for (; $creadas < $cadena; $creadas++) {
                $this->publicaCruda();
            }
            foreach ([UltronPublishedConfig::OFF, UltronPublishedConfig::CANARY, UltronPublishedConfig::ALL] as $llave) {
                config()->set('marketing.ultron.studio_config', $llave);
                $this->medirCommit($llave, $cadena);
                $this->medirLector($llave, $cadena);
            }
        }
    }

    // ── Medida ───────────────────────────────────────────────────────────────

    /**
     * @param  callable():TestResponse  $peticion
     * @param  (callable(TestResponse):void)|null  $comprobar
     */
    private function medir(string $ruta, int|string $tamano, callable $peticion, ?callable $comprobar = null): void
    {
        $peticion();   // cebado: las filas de los limitadores existen, como dentro de una ventana real
        [$r, $sentencias] = $this->perfilada($peticion);
        $this->assertSame(200, $r->getStatusCode(), "{$ruta} [{$tamano}] → {$r->getStatusCode()}: ".substr((string) $r->getContent(), 0, 300));
        if ($comprobar !== null) {
            $comprobar($r);
        }

        $this->resultados[] = [
            'route' => $ruta,
            'size' => $tamano,
            'status' => $r->getStatusCode(),
            'bytes' => strlen((string) $r->getContent()),
        ] + self::resumenSentencias($sentencias) + ['timing' => $this->cronometrar($peticion)];
    }

    /**
     * Un commit completo como n8n (decide → commit), con una conversación nueva
     * por commit para que el historial no crezca entre celdas; la conversación es
     * la canaria (la llave `canary` aplica su parte). Solo se cronometra el commit.
     */
    private function medirCommit(string $llave, int $cadena): void
    {
        $preparar = function (): array {
            $conv = $this->conversacionNueva();
            $m = MarketingMessage::create([
                'conversation_id' => $conv->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
                'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'hola, ¿cuánto vale la mensualidad?',
                'meta_message_id' => 'wamid.perf.'.$this->mundoPerf.'.'.Str::random(6), 'status' => 'received',
            ]);
            $token = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $conv->id, 'message_id' => $m->id], $this->secreto())
                ->assertOk()->json('decide_token');

            return [
                'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
                'conversation_id' => $conv->id, 'decide_token' => $token,
                'proposal' => [
                    'strategy_goal' => 'collect_data', 'next_state' => P::GOAL_DISCOVERY, 'intent' => SalesIntents::GENERAL_INFO,
                    'reply_draft' => 'Claro, te cuento. ¿Qué te gustaría lograr?', 'recommended_plan_id' => null, 'main_barrier' => null,
                    'next_best_action' => 'ask_discovery', 'confidence' => 0.82, 'tools_requested' => [],
                ],
                'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
            ];
        };
        $commit = fn (array $payload): TestResponse => $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->secreto());

        $commit($preparar());   // cebado (limitadores y cachés de la ruta interna)
        $payload = $preparar();
        [$r, $sentencias] = $this->perfilada(fn () => $commit($payload));
        $this->assertSame(200, $r->getStatusCode(), "commit [{$llave}/{$cadena}] → ".substr((string) $r->getContent(), 0, 300));
        $sello = MarketingAiAction::query()->latest('id')->value('metadata')['studio'] ?? null;
        $studio = array_values(array_filter($sentencias, static fn (array $s): bool => str_contains($s[0], 'ultron_studio_')));

        $this->resultados[] = [
            'route' => 'POST internal/marketing/ai/commit',
            'size' => "llave {$llave} / cadena {$cadena}",
            'status' => $r->getStatusCode(),
            'bytes' => strlen((string) $r->getContent()),
            'stamp' => is_array($sello) ? ($sello['state'] ?? null).($sello['reason'] ?? null ? '/'.$sello['reason'] : '') : null,
            'studioStatements' => array_map(static fn (array $s): string => $s[0], $studio),
            'studioMs' => round(array_sum(array_column($studio, 1)), 3),
        ] + self::resumenSentencias($sentencias) + ['timing' => $this->cronometrar(fn () => $commit($preparar()), sinPreparacionCronometrada: true)];
    }

    /** El lector a pelo: lo que añade por commit, fuera y dentro de una transacción (punto de guardado). */
    private function medirLector(string $llave, int $cadena): void
    {
        $n = (int) (getenv('PERF_N') ?: 0);
        if ($n <= 0) {
            return;
        }
        $lector = new UltronPublishedConfig;
        $conv = (int) config('marketing.ultron.canary_conversation_id');
        $vueltas = 2000;
        $medidas = [];
        foreach (['autocommit' => false, 'en transacción' => true] as $modo => $enTx) {
            for ($i = 0; $i < 200; $i++) {
                $lector->forConversation($conv);
            }
            $tiempos = [];
            if ($enTx) {
                DB::beginTransaction();
            }
            for ($i = 0; $i < $vueltas; $i++) {
                $t = hrtime(true);
                $lector->forConversation($conv);
                $tiempos[] = (hrtime(true) - $t) / 1e6;
            }
            if ($enTx) {
                DB::rollBack();
            }
            $medidas[$modo] = self::percentiles($tiempos);
        }
        $this->resultados[] = [
            'route' => 'UltronPublishedConfig::forConversation (a pelo)',
            'size' => "llave {$llave} / cadena {$cadena}",
            'samples' => $vueltas,
            'reader' => $medidas,
            'load' => sys_getloadavg(),
        ];
    }

    /**
     * @param  callable():TestResponse  $peticion
     * @return array{0:TestResponse,1:list<array{0:string,1:float}>}
     */
    private function perfilada(callable $peticion): array
    {
        $this->limpiarLimitadores();
        $this->sentencias = [];
        $this->capturando = true;
        try {
            $r = $peticion();
        } finally {
            $this->capturando = false;
        }

        return [$r, $this->sentencias];
    }

    /**
     * @param  callable():TestResponse  $peticion
     * @return array<string,mixed>|null
     */
    private function cronometrar(callable $peticion, bool $sinPreparacionCronometrada = false): ?array
    {
        $n = (int) (getenv('PERF_N') ?: 0);
        if ($n <= 0) {
            return null;
        }
        $calentar = (int) (getenv('PERF_WARM') ?: 5);
        $tandas = (int) (getenv('PERF_REPS') ?: 3);

        for ($i = 0; $i < $calentar; $i++) {
            $this->limpiarLimitadores();
            $peticion();
        }
        $todas = [];
        $porTanda = [];
        $cargas = [sys_getloadavg()[0]];
        for ($k = 0; $k < $tandas; $k++) {
            $tiempos = [];
            for ($i = 0; $i < $n; $i++) {
                $this->limpiarLimitadores();
                if ($sinPreparacionCronometrada) {
                    // El commit: la preparación (mensaje + decide) va dentro del callable; se descuenta midiendo solo el commit.
                    $tiempos[] = $this->soloCommit($peticion);
                } else {
                    $t = hrtime(true);
                    $r = $peticion();
                    $tiempos[] = (hrtime(true) - $t) / 1e6;
                    if ($r->getStatusCode() !== 200) {
                        $this->fail('respuesta '.$r->getStatusCode().' durante el cronometraje: '.substr((string) $r->getContent(), 0, 200));
                    }
                }
            }
            $porTanda[] = self::percentiles($tiempos);
            $todas = [...$todas, ...$tiempos];
            $cargas[] = sys_getloadavg()[0];
        }

        return ['reps' => $porTanda, 'all' => self::percentiles($todas), 'load1' => $cargas];
    }

    /** @param  callable():TestResponse  $commitConPreparacion */
    private function soloCommit(callable $commitConPreparacion): float
    {
        // El callable prepara y hace el commit; para cronometrar SOLO el commit, se cronometra la petición al kernel.
        $this->ultimoCommitMs = null;
        $this->beforeCommitHook = true;
        $r = $commitConPreparacion();
        $this->beforeCommitHook = false;
        if ($r->getStatusCode() !== 200) {
            $this->fail('commit '.$r->getStatusCode().': '.substr((string) $r->getContent(), 0, 200));
        }

        return (float) $this->ultimoCommitMs;
    }

    private ?float $ultimoCommitMs = null;

    private bool $beforeCommitHook = false;

    /**
     * POST JSON que cronometra solo las peticiones al commit cuando se pide
     * (el decide de la preparación no cuenta).
     *
     * @param  array<string,mixed>  $data
     * @param  array<string,string>  $headers
     */
    public function postJson($uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        if ($this->beforeCommitHook && str_ends_with((string) $uri, '/ai/commit')) {
            $t = hrtime(true);
            $r = parent::postJson($uri, $data, $headers, $options);
            $this->ultimoCommitMs = (hrtime(true) - $t) / 1e6;

            return $r;
        }

        return parent::postJson($uri, $data, $headers, $options);
    }

    /**
     * Estado estable de los limitadores: sus filas (md5 y su `:timer`) se
     * conservan y el contador vuelve a 0, con la ventana viva. La caché de
     * permisos (`crm.role_permissions.v1`) no se toca.
     */
    private function limpiarLimitadores(): void
    {
        $tabla = (string) config('cache.stores.database.table', 'cache');
        $vence = now()->getTimestamp() + 600;
        // 5 y no 0: con el contador a 0, `RateLimiter::increment` ve hits == 1 sin haber añadido la fila y hace un `put` de más
        // que solo existe en el primer golpe de una ventana. 5 + 1 queda por debajo de todos los cupos (10/min el más bajo).
        DB::table($tabla)->whereRaw("key ~ '[0-9a-f]{32}$'")->update(['value' => serialize(5), 'expiration' => $vence]);
        DB::table($tabla)->whereRaw("key ~ '[0-9a-f]{32}:timer$'")->update(['expiration' => $vence]);
    }

    /**
     * @param  list<array{0:string,1:float}>  $sentencias
     * @return array<string,mixed>
     */
    private static function resumenSentencias(array $sentencias): array
    {
        $porTabla = [];
        $msPorTabla = [];
        $normalizadas = [];
        foreach ($sentencias as [$sql, $ms]) {
            $tabla = preg_match('/\b(?:from|into|update|join)\s+"?([a-z0-9_]+)"?/i', $sql, $m) ? $m[1] : (preg_match('/^\s*(savepoint|release|rollback|begin|commit|set)\b/i', $sql, $m2) ? strtoupper($m2[1]) : 'otra');
            $porTabla[$tabla] = ($porTabla[$tabla] ?? 0) + 1;
            $msPorTabla[$tabla] = round(($msPorTabla[$tabla] ?? 0) + $ms, 3);
            $norm = preg_replace(['/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '/\b\d+\b/', '/\s+/'], ['(?…)', 'N', ' '], $sql);
            $normalizadas[$norm] = ($normalizadas[$norm] ?? 0) + 1;
        }
        arsort($porTabla);
        arsort($normalizadas);
        $top = array_key_first($normalizadas);

        return [
            'queries' => count($sentencias),
            'dbMs' => round(array_sum(array_column($sentencias, 1)), 3),
            'perTable' => $porTabla,
            'perTableMs' => $msPorTabla,
            'mostRepeated' => $top === null ? null : ['count' => $normalizadas[$top], 'sql' => mb_substr($top, 0, 240)],
            'statements' => array_map(static fn (array $s): string => mb_substr($s[0], 0, 400), $sentencias),
        ];
    }

    /**
     * @param  list<float>  $t
     * @return array{n:int,p50:float,p95:float,max:float,min:float}
     */
    private static function percentiles(array $t): array
    {
        sort($t);
        $n = count($t);
        $p = static fn (float $q): float => $n === 0 ? 0.0 : round($t[max(0, (int) ceil($q * $n) - 1)], 3);

        return ['n' => $n, 'p50' => $p(0.50), 'p95' => $p(0.95), 'max' => $n === 0 ? 0.0 : round($t[$n - 1], 3), 'min' => $n === 0 ? 0.0 : round($t[0], 3)];
    }

    /** @return array<string,mixed> */
    private static function entorno(): array
    {
        return [
            'at' => gmdate('c'),
            'php' => PHP_VERSION,
            'opcacheCli' => (bool) ini_get('opcache.enable_cli'),
            'db' => DB::connection()->getDatabaseName(),
            'pg' => DB::selectOne('select version() as v')->v ?? null,
            'cache' => config('cache.default'),
            'queue' => config('queue.default'),
            'n' => (int) (getenv('PERF_N') ?: 0),
            'warm' => (int) (getenv('PERF_WARM') ?: 5),
            'reps' => (int) (getenv('PERF_REPS') ?: 3),
            'load' => sys_getloadavg(),
            'host' => php_uname('n').' '.php_uname('m'),
        ];
    }

    /** @param  mixed  $entradas */
    private function todasVerificadas($entradas): void
    {
        $this->assertIsArray($entradas);
        $this->assertNotEmpty($entradas);
        foreach ($entradas as $e) {
            $this->assertTrue($e['verified'] ?? false, 'una entrada sembrada no verifica: el arnés mediría el camino corto');
        }
    }

    /** @return array<string,string> */
    private function secreto(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }
}
