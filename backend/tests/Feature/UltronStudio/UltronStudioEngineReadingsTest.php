<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingAgentSetting;
use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Models\UltronAbort;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Services\Marketing\MarketingAgentSwitch;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioEngineReadings;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * `GET engine/readings` (contrato G §A2): lo que lee HOY el proceso web del
 * motor, de solo lectura. Cada lectura con la configuración y las filas
 * sembradas; `fake` se lee como `rules`; una fuente caída da
 * `{available:false}` sin tumbar la respuesta; y nada de lo que no debe salir
 * sale (URL del webhook, ids de los canarios, credenciales, precios, ids de
 * planes, contenido de la base de conocimiento).
 */
class UltronStudioEngineReadingsTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private const WEBHOOK = 'https://n8n.ejemplo.test/webhook/ultron-ruta-secreta-7c1f';

    private const CANARIO = 987654321;

    private const CANARIO_COBRO = 555444333;

    private Admin $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
    }

    /** El motor como en producción: encendido, solo la canaria y el cobro apagado. */
    private function engineConfig(array $extra = []): void
    {
        config($extra + [
            'marketing.ultron.enabled' => true,
            'marketing.ultron.payment_links_enabled' => false,
            'marketing.ultron.canary_conversation_id' => self::CANARIO,
            'marketing.ultron.payment_canary_conversation_id' => 0,
            'marketing.ultron.webhook_url' => self::WEBHOOK,
            'marketing.ai.driver' => 'openai',
            'marketing.ai.openai.enabled' => true,
            'marketing.ai.openai.model' => 'gpt-prueba',
            'services.openai.api_key' => 'sk-prueba-que-no-sale-nunca',
        ]);
    }

    private function seedEngine(): void
    {
        MarketingAgentSetting::query()->whereKey(MarketingAgentSwitch::ROW_ID)->update(['paused' => true, 'paused_at' => now()]);
        UltronAbort::query()->create(['reason' => UltronAbort::REASON_MANUAL, 'engaged_by' => 'Consola de prueba', 'engaged_at' => now()]);

        $item = static fn (string $categoria, string $clave, array $extra = []): MarketingKnowledgeItem => MarketingKnowledgeItem::create($extra + [
            'category' => $categoria, 'key' => $clave, 'title' => "Título {$clave}", 'content' => "Contenido secreto de {$clave}",
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER,
        ]);
        $item('tone', 'tone.uno');
        $item('tone', 'tone.dos');
        $item('tone', 'tone.tres');
        $item('tone', 'tone.apagado', ['is_active' => false]);
        $item('brand_copy', 'brand_copy.uno');
        $item('brand_copy', 'brand_copy.dos');
        // Propuesto por la API interna: nace pendiente y no cuenta como activo.
        $item('faq', 'faq.propuesta', ['origin' => MarketingKnowledgeItem::ORIGIN_INTERNAL_API]);

        foreach ([
            ['name' => 'Trimestral', 'price' => 270000, 'sort_order' => 1, 'is_recommended' => false],
            ['name' => 'Mensualidad', 'price' => 98765, 'sort_order' => 2, 'is_recommended' => true],
            ['name' => 'Plan interno', 'price' => 0, 'sort_order' => 0, 'is_recommended' => false],
            ['name' => 'Cortesía', 'price' => 50000, 'sort_order' => 3, 'is_recommended' => false, 'sellable' => false],
        ] as $plan) {
            Plan::create($plan + ['duration_days' => 30, 'active' => true]);
        }
    }

    public function test_cada_lectura_con_la_configuracion_y_las_filas_sembradas(): void
    {
        $this->engineConfig();
        $this->seedEngine();
        $version = app(MarketingKnowledgeBaseService::class)->version();

        $r = $this->studio('GET', 'engine/readings', $this->ana)->assertOk();

        $r->assertJsonPath('ok', true);
        $data = $r->json('data');
        $this->assertSame(['readAt', 'params', 'extras', 'note'], array_keys($data));
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $data['readAt']), $data['readAt']);
        $this->assertSame('Lectura del proceso web. Tras cambiar el entorno, los workers lo ven cuando se reinician.', $data['note']);
        $this->assertSame([
            'intent.driver' => ['available' => true, 'value' => 'openai'],
            'rules.paymentLinksEnabled' => ['available' => true, 'value' => false],
            'governance.enabled' => ['available' => true, 'value' => true],
            'governance.canaryOnly' => ['available' => true, 'value' => true],
            // La insignia primero y luego por `sort_order`; ni el de precio cero ni el no vendible.
            'strategy.offerPriority' => ['available' => true, 'value' => ['Mensualidad', 'Trimestral']],
        ], $data['params']);

        $porCategoria = array_fill_keys(MarketingKnowledgeItem::CATEGORIES, 0);
        $porCategoria['tone'] = 3;
        $porCategoria['brand_copy'] = 2;
        $this->assertSame([
            'governance.paused' => ['available' => true, 'value' => true],
            'governance.brake' => ['available' => true, 'value' => true],
            'rules.paymentCanary' => ['available' => true, 'value' => false],
            'knowledge.base' => ['available' => true, 'value' => ['version' => $version, 'activeByCategory' => $porCategoria, 'pendingReview' => 1]],
            // Por `sort_order`, con la insignia marcada.
            'strategy.plans' => ['available' => true, 'value' => [['name' => 'Trimestral', 'recommended' => false], ['name' => 'Mensualidad', 'recommended' => true]]],
        ], $data['extras']);
        $this->assertCount(14, $data['extras']['knowledge.base']['value']['activeByCategory']);
        $this->assertStringStartsWith('5:', $version);
    }

    public function test_los_valores_contrarios_y_fake_se_lee_como_rules(): void
    {
        $this->engineConfig([
            'marketing.ultron.enabled' => false,
            'marketing.ultron.payment_links_enabled' => true,
            'marketing.ultron.canary_conversation_id' => null,
            'marketing.ultron.payment_canary_conversation_id' => self::CANARIO_COBRO,
            'marketing.ai.driver' => 'fake',
        ]);

        $data = $this->studio('GET', 'engine/readings', $this->ana)->assertOk()->json('data');

        $this->assertSame(['available' => true, 'value' => 'rules'], $data['params']['intent.driver']);
        $this->assertSame(['available' => true, 'value' => true], $data['params']['rules.paymentLinksEnabled']);
        $this->assertSame(['available' => true, 'value' => false], $data['params']['governance.enabled']);
        $this->assertSame(['available' => true, 'value' => false], $data['params']['governance.canaryOnly']);
        $this->assertSame(['available' => true, 'value' => []], $data['params']['strategy.offerPriority']);
        $this->assertSame(['available' => true, 'value' => false], $data['extras']['governance.paused']);
        $this->assertSame(['available' => true, 'value' => false], $data['extras']['governance.brake']);
        $this->assertSame(['available' => true, 'value' => true], $data['extras']['rules.paymentCanary']);
        $this->assertSame(['available' => true, 'value' => []], $data['extras']['strategy.plans']);
        $this->assertSame(0, $data['extras']['knowledge.base']['value']['pendingReview']);
        $this->assertSame('0:0', $data['extras']['knowledge.base']['value']['version']);

        // Con OpenAI listo pero sin clave, el motor cae a reglas: se lee lo efectivo, no lo configurado.
        $this->engineConfig(['services.openai.api_key' => '']);
        $this->assertSame(['available' => true, 'value' => 'rules'], $this->studio('GET', 'engine/readings', $this->ana)->assertOk()->json('data.params')['intent.driver']);
    }

    /**
     * Las consultas que contengan `$fragmento` fallan ANTES de ejecutarse, como
     * falla una tabla que no existe o una base que no responde, con el SQLSTATE
     * de PDO como código. Antes de ejecutarse: en PostgreSQL, una sentencia
     * fallida de verdad dejaría abortada la transacción de la prueba entera.
     */
    private function failQueriesOn(string $fragmento, string $sqlState, string $mensaje): void
    {
        DB::connection()->beforeExecuting(static function (string $sql, array $bindings, $conexion) use ($fragmento, $sqlState, $mensaje): void {
            if (str_contains($sql, $fragmento)) {
                $pdo = new PDOException("SQLSTATE[{$sqlState}]: {$mensaje}");
                (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $sqlState);

                throw new QueryException($conexion->getName(), $sql, $bindings, $pdo);
            }
        });
    }

    public function test_una_fuente_caida_da_available_false_y_la_respuesta_sigue(): void
    {
        $this->engineConfig();
        // La pausa puesta y el freno echado: si una consulta caída se enseñara
        // con un valor por defecto, saldría justo el contrario.
        $this->seedEngine();
        Log::spy();

        // La tabla de la pausa «no existe» (lo que MarketingAgentSwitch::current()
        // se traga y da como «sin pausa»), la base no responde al leer el freno
        // (lo que UltronAbortLatch::current() se traga entero y da como «freno
        // suelto») y la base de conocimiento falla: las otras siete lecturas
        // siguen (correcciones del Hito G, I-1).
        $this->failQueriesOn('"marketing_agent_settings"', 'HY000', 'General error: 1 no such table: marketing_agent_settings');
        $this->failQueriesOn('"ultron_aborts"', '08006', 'could not connect to server: host=db.interno contraseña=…');
        $this->app->instance(MarketingKnowledgeBaseService::class, new class extends MarketingKnowledgeBaseService
        {
            public function version(): string
            {
                throw new RuntimeException('fallo de la base');
            }
        });

        $r = $this->studio('GET', 'engine/readings', $this->ana)->assertOk();
        $data = $r->json('data');

        foreach (['governance.paused', 'governance.brake', 'knowledge.base'] as $caida) {
            $this->assertSame(['available' => false], $data['extras'][$caida], $caida);
        }
        $this->assertSame(['available' => true, 'value' => false], $data['extras']['rules.paymentCanary']);
        $this->assertTrue($data['extras']['strategy.plans']['available']);
        foreach (StudioEngineReadings::PARAMS as $id) {
            $this->assertTrue($data['params'][$id]['available'], $id);
        }
        // Sin mensaje técnico en la respuesta, y una línea sin datos en el log.
        foreach (['SQLSTATE', 'db.interno', 'contraseña', 'no such table', 'fallo de la base'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $r->getContent());
        }
        Log::shouldHaveReceived('warning')->with('ultron_studio.engine_reading_unavailable', ['reading' => 'governance.paused', 'error_class' => 'QueryException'])->once();
        Log::shouldHaveReceived('warning')->with('ultron_studio.engine_reading_unavailable', ['reading' => 'governance.brake', 'error_class' => 'QueryException'])->once();
        Log::shouldHaveReceived('warning')->with('ultron_studio.engine_reading_unavailable', ['reading' => 'knowledge.base', 'error_class' => 'RuntimeException'])->once();
    }

    public function test_el_freno_y_la_pausa_se_leen_con_la_consulta_del_motor_sin_su_red(): void
    {
        $this->engineConfig();

        // Sin pausa ni freno en la base: «no» y «no», leídos.
        $data = $this->studio('GET', 'engine/readings', $this->ana)->assertOk()->json('data.extras');
        $this->assertSame(['available' => true, 'value' => false], $data['governance.paused']);
        $this->assertSame(['available' => true, 'value' => false], $data['governance.brake']);

        // Con las dos tablas «sin crear» (un despliegue a medias), el motor sigue
        // como si no hubiera pausa ni freno: MarketingAgentSwitch::current() y
        // UltronAbortLatch::current() se tragan el error. El Studio no puede
        // enseñar eso como un «no» leído.
        $this->failQueriesOn('"marketing_agent_settings"', '42P01', 'Undefined table: 7 ERROR:  relation "marketing_agent_settings" does not exist');
        $this->failQueriesOn('"ultron_aborts"', '42P01', 'Undefined table: 7 ERROR:  relation "ultron_aborts" does not exist');

        $data = $this->studio('GET', 'engine/readings', $this->ana)->assertOk()->json('data.extras');
        $this->assertSame(['available' => false], $data['governance.paused']);
        $this->assertSame(['available' => false], $data['governance.brake']);
        $this->assertTrue($data['knowledge.base']['available']);
    }

    public function test_la_respuesta_no_lleva_ni_secretos_ni_ids_ni_precios_ni_contenido(): void
    {
        $this->engineConfig(['marketing.ultron.payment_canary_conversation_id' => self::CANARIO_COBRO]);
        $this->seedEngine();
        $planes = Plan::query()->pluck('id')->all();

        $cuerpo = $this->studio('GET', 'engine/readings', $this->ana)->assertOk()->getContent();

        foreach ([self::WEBHOOK, 'ruta-secreta', (string) self::CANARIO, (string) self::CANARIO_COBRO, 'Bearer', 'sk-', 'gpt-prueba',
            '270000', '98765', '50000', 'Contenido secreto', 'tone.uno', 'Título', 'Cortesía', 'Plan interno', '"id"', '"price"'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $cuerpo, "La lectura del motor contiene «{$prohibido}».");
        }
        // Los planes salen sin id: cada uno es exactamente {name, recommended}.
        $data = json_decode($cuerpo, true)['data'];
        foreach ($data['extras']['strategy.plans']['value'] as $plan) {
            $this->assertSame(['name', 'recommended'], array_keys($plan));
        }
        $this->assertNotEmpty($planes);
    }

    public function test_leer_el_motor_no_escribe_nada_ni_se_audita(): void
    {
        $this->engineConfig();
        $this->seedEngine();
        $antes = [
            DB::table('marketing_agent_settings')->orderBy('id')->get()->toJson(),
            DB::table('ultron_aborts')->orderBy('id')->get()->toJson(),
            DB::table('marketing_knowledge_items')->orderBy('id')->get()->toJson(),
            DB::table('plans')->orderBy('id')->get()->toJson(),
            json_encode(config('marketing')),
        ];

        $this->studio('GET', 'engine/readings', $this->ana)->assertOk();
        $this->studio('GET', 'engine/readings', $this->ana)->assertOk();

        $this->assertSame($antes, [
            DB::table('marketing_agent_settings')->orderBy('id')->get()->toJson(),
            DB::table('ultron_aborts')->orderBy('id')->get()->toJson(),
            DB::table('marketing_knowledge_items')->orderBy('id')->get()->toJson(),
            DB::table('plans')->orderBy('id')->get()->toJson(),
            json_encode(config('marketing')),
        ]);
        $this->assertSame(0, StudioAuditEvent::query()->count());
    }

    public function test_las_lecturas_son_las_que_describe_el_catalogo(): void
    {
        // `live` en el catálogo es exactamente lo que se lee aquí, y las
        // lecturas extra son las que describe `readings`, en el mismo orden.
        $enVivo = [];
        foreach (StudioCatalog::bindings() as $capa => $parametros) {
            foreach ($parametros as $clave => $b) {
                if ($b['valueSource'] === 'live') {
                    $enVivo[] = "{$capa}.{$clave}";
                }
            }
        }
        sort($enVivo);
        $params = StudioEngineReadings::PARAMS;
        sort($params);
        $this->assertSame($params, $enVivo);
        $this->assertSame(array_column(StudioCatalog::readings(), 'id'), StudioEngineReadings::EXTRAS);

        $data = $this->studio('GET', 'engine/readings', $this->ana)->assertOk()->json('data');
        $this->assertSame(StudioEngineReadings::PARAMS, array_keys($data['params']));
        $this->assertSame(StudioEngineReadings::EXTRAS, array_keys($data['extras']));
    }

    public function test_quien_no_tiene_ultron_view_no_lee_el_motor(): void
    {
        $this->engineConfig();
        foreach ([$this->person(Admin::ROLE_RECEPCION, 'Recepción'), $this->person(Admin::ROLE_ADMINISTRATIVO, 'Administrativo')] as $quien) {
            $this->studio('GET', 'engine/readings', $quien)
                ->assertStatus(403)->assertJsonPath('code', 'forbidden')->assertJsonPath('required_permission', 'ultron.view');
        }
        // Todo Mercadeo, incluido pausar el agente, tampoco.
        $this->json('GET', '/api/admin/ultron-studio/engine/readings', [], $this->adminWithPermissions(['marketing.view', 'marketing.manage', 'marketing.agent.manage']))
            ->assertStatus(403)->assertJsonPath('required_permission', 'ultron.view');
        $this->json('GET', '/api/admin/ultron-studio/engine/readings', [], $this->sharedTokenHeaders())->assertStatus(403);
    }
}
