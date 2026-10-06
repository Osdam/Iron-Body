<?php

namespace Tests\Feature\Marketing\MetaAds;

use App\Models\MetaAdEntity;
use App\Models\MetaAdInsightDaily;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

/**
 * La sincronización de Meta Ads: trae el gasto real, no duplica, no convierte un
 * fallo en $0, respeta el día de la cuenta, guarda la jerarquía de campañas y
 * no deja escapar el token.
 *
 * Todo contra un Graph fingido: ninguna prueba sale a la red.
 */
class MetaAdsSyncTest extends TestCase
{
    use FakesMetaAds, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 del 5 de octubre en Bogotá.
        Carbon::setTestNow('2026-10-05 15:00:00');
        $this->configureMetaAds();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Contrato 1: el gasto que dice Meta es el que queda, y es el que se lee. */
    public function test_trae_el_gasto_real_de_meta_por_anuncio_y_dia(): void
    {
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-04', '9001', '45000.50'),
            $this->insightRow('2026-10-05', '9001', '30000'),
            $this->insightRow('2026-10-05', '9002', '12500.25', ['impressions' => '400', 'clicks' => '9']),
        ]]);

        $result = $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        $this->assertSame('ok', $result['status']);
        $this->assertSame(3, $result['rows_upserted']);
        $this->assertSame('2026-10-01', $result['range_from']);
        $this->assertSame('2026-10-05', $result['range_to']);

        $fila = MetaAdInsightDaily::where('date', '2026-10-05')->where('ad_id', '9002')->sole();
        $this->assertSame(self::ACCOUNT, $fila->ad_account_id);
        $this->assertSame('12500.25', $fila->spend);
        $this->assertSame('COP', $fila->currency);
        $this->assertSame(400, $fila->impressions);
        $this->assertSame(9, $fila->clicks);
        $this->assertSame('120201', $fila->campaign_id);
        $this->assertSame('120211', $fila->adset_id);

        $run = MetaSyncRun::sole();
        $this->assertSame('ok', $run->status);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame(3, $run->rows_upserted);
        $this->assertNull($run->error_code);

        // La petición es la del contrato, y solo una.
        $peticiones = $this->requestsTo('insights');
        $this->assertCount(1, $peticiones);
        $this->assertStringStartsWith(self::GRAPH.'/act_'.self::ACCOUNT.'/insights?', $peticiones[0]->url());
        $query = $this->queryOf($peticiones[0]);
        $this->assertSame('ad', $query['level']);
        $this->assertSame('1', $query['time_increment']);
        $this->assertSame(['since' => '2026-10-01', 'until' => '2026-10-05'], json_decode($query['time_range'], true));
        $this->assertSame(
            'date_start,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,spend,impressions,reach,clicks,inline_link_clicks,actions,account_currency',
            $query['fields'],
        );
        // Con los anuncios eliminados y archivados: su gasto cuenta en el total
        // de la cuenta del Administrador de anuncios, y sin ellos no cuadraría.
        $filtro = json_decode($query['filtering'], true);
        $this->assertSame('ad.effective_status', $filtro[0]['field']);
        $this->assertSame('IN', $filtro[0]['operator']);
        $this->assertContains('DELETED', $filtro[0]['value']);
        $this->assertContains('ARCHIVED', $filtro[0]['value']);
        $this->assertContains('ACTIVE', $filtro[0]['value']);
        $this->assertContains('PAUSED', $filtro[0]['value']);
        // Todos los estados: uno que faltara dejaría fuera su gasto y la pasada borraría sus filas.
        $this->assertSame(
            ['ACTIVE', 'ADSET_PAUSED', 'ARCHIVED', 'CAMPAIGN_PAUSED', 'DELETED', 'DISAPPROVED', 'IN_PROCESS', 'PAUSED', 'PENDING_BILLING_INFO', 'PENDING_REVIEW', 'PREAPPROVED', 'WITH_ISSUES'],
            collect($filtro[0]['value'])->sort()->values()->all(),
        );

        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame('ok', $gasto['status']);
        $this->assertSame(87500.75, $gasto['amount']);
        $this->assertSame('COP', $gasto['currency']);
        $this->assertNull($gasto['reason']);
        $this->assertSame('2026-10-05T15:00:00+00:00', $gasto['last_synced_at']);
    }

    /** Contrato 2: repetir la pasada actualiza, no duplica; y lo que Meta ya no da, se va. */
    public function test_sincronizar_dos_veces_no_duplica(): void
    {
        // Una fila vieja, fuera del rango que se va a repasar: no se toca.
        MetaAdInsightDaily::create([
            'ad_account_id' => self::ACCOUNT, 'date' => '2026-09-20', 'ad_id' => '9001', 'spend' => '777.00', 'currency' => 'COP',
        ]);

        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-04', '9001', '45000.50'),
            $this->insightRow('2026-10-05', '9001', '30000'),
            $this->insightRow('2026-10-05', '9002', '12500.25'),
        ]]);

        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        $this->assertSame(4, MetaAdInsightDaily::count());
        $this->assertSame(87500.75, $this->spendFor('2026-10-01', '2026-10-05')['amount']);
        $this->assertSame(1, MetaAdEntity::where('level', 'campaign')->count());
        $this->assertSame(1, MetaAdEntity::where('level', 'adset')->count());
        $this->assertSame(2, MetaAdEntity::where('level', 'ad')->count());
        $this->assertSame(2, MetaSyncRun::where('status', 'ok')->count());

        // Meta corrige: el 9001 del día 5 sube, y el 9002 desaparece de ese día.
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-04', '9001', '45000.50'),
            $this->insightRow('2026-10-05', '9001', '32000'),
        ]]);

        $result = $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        $this->assertSame(1, $result['rows_deleted']);
        $this->assertSame(3, MetaAdInsightDaily::count());
        $this->assertSame('32000.00', MetaAdInsightDaily::where('date', '2026-10-05')->where('ad_id', '9001')->sole()->spend);
        $this->assertFalse(MetaAdInsightDaily::where('ad_id', '9002')->exists());
        $this->assertSame(77000.5, $this->spendFor('2026-10-01', '2026-10-05')['amount']);
        // La fila de septiembre no estaba en el rango repasado: sigue ahí.
        $this->assertSame('777.00', MetaAdInsightDaily::where('date', '2026-09-20')->sole()->spend);
    }

    /** Contrato 3: si Meta falla, la foto anterior queda intacta y nada se pone a cero. */
    public function test_un_fallo_de_meta_no_toca_la_ultima_foto(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '30000')]]);
        $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'));

        $this->fakeMeta(fn () => Http::response(['error' => [
            'message' => 'An unexpected error has occurred. Please retry your request later.',
            'code' => 2,
            'is_transient' => true,
        ]], 503));

        $result = $this->sync()->run('schedule');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('transient', $result['error_code']);
        $this->assertTrue($result['retryable']);

        $this->assertSame(1, MetaAdInsightDaily::count());
        $this->assertSame('30000.00', MetaAdInsightDaily::sole()->spend);
        $this->assertSame(1, MetaAdEntity::where('level', 'ad')->count());

        $fallida = MetaSyncRun::where('status', 'failed')->sole();
        $this->assertSame('transient', $fallida->error_code);
        $this->assertSame(0, $fallida->rows_upserted);
        $this->assertNotNull($fallida->finished_at);

        // El día que cubrió el éxito sigue valiendo lo que valía, no $0.
        $gasto = $this->spendFor('2026-10-05', '2026-10-05');
        $this->assertSame('ok', $gasto['status']);
        $this->assertSame(30000.0, $gasto['amount']);

        // Lo que solo cubría el intento fallido no se sabe: null, nunca 0.
        $gasto = $this->spendFor('2026-09-29', '2026-10-05');
        $this->assertSame('sync_failed', $gasto['status']);
        $this->assertNull($gasto['amount']);
        $this->assertSame('transient', $gasto['reason']);

        $this->assertSame('failed', $this->sync()->status()['status']);
    }

    /** Contrato 3, el caso traicionero: falla una página intermedia. No se escribe NADA. */
    public function test_si_falla_una_pagina_intermedia_no_se_escribe_ninguna(): void
    {
        $this->fakeMeta(function (Request $request) {
            if (! isset($this->queryOf($request)['after'])) {
                return Http::response([
                    'data' => [$this->insightRow('2026-10-05', '9001', '30000')],
                    'paging' => [
                        'cursors' => ['after' => 'CUR-2'],
                        'next' => self::GRAPH.'/act_'.self::ACCOUNT.'/insights?after=CUR-2',
                    ],
                ]);
            }

            return $this->graphError(17, 'User request limit reached');
        });

        $result = $this->sync()->run('schedule');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('rate_limit', $result['error_code']);
        $this->assertCount(2, $this->requestsTo('insights'));
        $this->assertSame(0, MetaAdInsightDaily::count());
        $this->assertSame(0, MetaAdEntity::count());

        // La primera página traía 30.000, pero no se da por buena a medias.
        $gasto = $this->spendFor('2026-10-05', '2026-10-05');
        $this->assertSame('sync_failed', $gasto['status']);
        $this->assertNull($gasto['amount']);
        $this->assertSame('rate_limit', $gasto['reason']);
    }

    /**
     * Contrato 19, del lado de la sincronización: a las 22:30 de Bogotá ya es el
     * día siguiente en UTC, pero el rango que se pide y el día que se guarda son
     * los de la cuenta.
     */
    public function test_el_rango_por_defecto_y_el_dia_guardado_son_los_de_la_cuenta(): void
    {
        Carbon::setTestNow('2026-10-06 03:30:00');

        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-05', '9001', '50000'),
            $this->insightRow('2026-10-04', '9001', '20000'),
        ]]);

        $result = $this->sync()->run('schedule');

        $this->assertSame('2026-09-29', $result['range_from']);
        $this->assertSame('2026-10-05', $result['range_to']);

        $query = $this->queryOf($this->requestsTo('insights')[0]);
        $this->assertSame(['since' => '2026-09-29', 'until' => '2026-10-05'], json_decode($query['time_range'], true));

        // El día se guarda tal como lo da Meta, sin pasar por UTC.
        $this->assertSame(['2026-10-04', '2026-10-05'], MetaAdInsightDaily::orderBy('date')->pluck('date')->all());

        // Un `--to` en el futuro se recorta a hoy de la cuenta.
        $result = $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-09'));
        $this->assertSame('2026-10-05', $result['range_to']);
    }

    /** Contrato 21: campaña, conjunto y anuncio, con su jerarquía, sus nombres y su estado. */
    public function test_guarda_campana_conjunto_y_anuncio(): void
    {
        $this->fakeMeta(
            ['data' => [
                $this->insightRow('2026-10-05', '7001', '1000', ['campaign_id' => '501', 'campaign_name' => 'Campaña A', 'adset_id' => '601', 'adset_name' => 'Conjunto A1', 'ad_name' => 'Anuncio 1']),
                $this->insightRow('2026-10-05', '7002', '2000', ['campaign_id' => '501', 'campaign_name' => 'Campaña A', 'adset_id' => '601', 'adset_name' => 'Conjunto A1', 'ad_name' => 'Anuncio 2']),
                $this->insightRow('2026-10-05', '7003', '4000', ['campaign_id' => '502', 'campaign_name' => 'Campaña B', 'adset_id' => '602', 'adset_name' => 'Conjunto B1', 'ad_name' => 'Anuncio 3']),
            ]],
            ['data' => [
                ['id' => '501', 'name' => 'Campaña A', 'effective_status' => 'ACTIVE'],
                ['id' => '502', 'name' => 'Campaña B', 'effective_status' => 'PAUSED'],
            ]],
        );

        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'))['status']);

        $this->assertSame(2, MetaAdEntity::where('level', 'campaign')->count());
        $this->assertSame(2, MetaAdEntity::where('level', 'adset')->count());
        $this->assertSame(3, MetaAdEntity::where('level', 'ad')->count());

        $anuncio = MetaAdEntity::where('level', 'ad')->where('entity_id', '7001')->sole();
        $this->assertSame(['Anuncio 1', '501', '601'], [$anuncio->name, $anuncio->campaign_id, $anuncio->adset_id]);

        $conjunto = MetaAdEntity::where('level', 'adset')->where('entity_id', '601')->sole();
        $this->assertSame(['Conjunto A1', '501', '601'], [$conjunto->name, $conjunto->campaign_id, $conjunto->adset_id]);

        $campana = MetaAdEntity::where('level', 'campaign')->where('entity_id', '501')->sole();
        $this->assertSame(['Campaña A', '501', null, 'ACTIVE'], [$campana->name, $campana->campaign_id, $campana->adset_id, $campana->effective_status]);
        $this->assertSame('PAUSED', MetaAdEntity::where('level', 'campaign')->where('entity_id', '502')->value('effective_status'));

        $estados = $this->requestsTo('campaigns');
        $this->assertCount(1, $estados);
        $this->assertSame('id,name,effective_status', $this->queryOf($estados[0])['fields']);
        // Todas menos DELETED, que ese borde rechaza (100/1815001): una eliminada conserva su último estado.
        $this->assertSame(MetaAdsApiClient::CAMPAIGN_EDGE_STATUSES, json_decode($this->queryOf($estados[0])['effective_status'], true));
        $this->assertNotContains('DELETED', MetaAdsApiClient::CAMPAIGN_EDGE_STATUSES);

        // Por nivel, de mayor a menor gasto, con nombre y estado.
        [$desde, $hasta] = $this->bogotaDays('2026-10-05', '2026-10-05');

        $campanas = $this->reader()->byLevel('campaign', $desde, $hasta);
        $metricasNuevas = ['link_clicks' => 0, 'messaging_started' => 0, 'messaging_replied' => 0, 'landing_page_views' => 0, 'reach' => null, 'frequency' => null];
        $this->assertSame([
            ['id' => '502', 'name' => 'Campaña B', 'campaign_id' => '502', 'adset_id' => null, 'spend' => 4000.0, 'currency' => 'COP', 'impressions' => 1000, 'clicks' => 25, ...$metricasNuevas, 'status' => 'PAUSED', 'partial' => false, 'last_day' => '2026-10-05'],
            ['id' => '501', 'name' => 'Campaña A', 'campaign_id' => '501', 'adset_id' => null, 'spend' => 3000.0, 'currency' => 'COP', 'impressions' => 2000, 'clicks' => 50, ...$metricasNuevas, 'status' => 'ACTIVE', 'partial' => false, 'last_day' => '2026-10-05'],
        ], $campanas->all());

        $conjuntos = $this->reader()->byLevel('adset', $desde, $hasta);
        $this->assertSame(['602', '601'], $conjuntos->pluck('id')->all());
        $this->assertSame(['502', '501'], $conjuntos->pluck('campaign_id')->all());
        $this->assertSame([4000.0, 3000.0], $conjuntos->pluck('spend')->all());

        $anuncios = $this->reader()->byLevel('ad', $desde, $hasta);
        $this->assertSame(['7003', '7002', '7001'], $anuncios->pluck('id')->all());
        $this->assertSame(['Anuncio 3', 'Anuncio 2', 'Anuncio 1'], $anuncios->pluck('name')->all());
        $this->assertSame(['602', '601', '601'], $anuncios->pluck('adset_id')->all());

        // La campaña cambia de nombre y /campaigns falla: el nombre se actualiza
        // por los insights, el estado conocido se conserva y nada se duplica.
        $this->fakeMeta(
            ['data' => [
                $this->insightRow('2026-10-05', '7001', '1500', ['campaign_id' => '501', 'campaign_name' => 'Campaña A (renovada)', 'adset_id' => '601', 'adset_name' => 'Conjunto A1', 'ad_name' => 'Anuncio 1']),
            ]],
            fn () => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503),
        );

        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'))['status']);

        $campana->refresh();
        $this->assertSame('Campaña A (renovada)', $campana->name);
        $this->assertSame('ACTIVE', $campana->effective_status);
        $this->assertSame(2, MetaAdEntity::where('level', 'campaign')->count());
        $this->assertSame(3, MetaAdEntity::where('level', 'ad')->count());
    }

    /**
     * La dimensión lleva la cuenta. Si cambia META_AD_ACCOUNT_ID, lo sincronizado
     * de la cuenta anterior se conserva, pero solo se lee lo de la configurada:
     * una campaña vieja no resuelve leads de la cuenta nueva.
     */
    public function test_la_dimension_lleva_la_cuenta_y_solo_se_lee_la_configurada(): void
    {
        $this->configureMetaAds(['ad_account_id' => '1111111111']);
        $this->fakeMeta(
            ['data' => [$this->insightRow('2026-10-05', '9001', '50000')]],
            ['data' => [['id' => '120201', 'name' => 'Campaña Octubre', 'effective_status' => 'ACTIVE']]],
        );
        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'))['status']);

        $this->configureMetaAds(['ad_account_id' => 'act_2222222222']);
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9002', '30000', [
            'campaign_id' => '120202', 'campaign_name' => 'Campaña B', 'adset_id' => '120212', 'adset_name' => 'Conjunto B',
        ])]]);
        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'))['status']);

        // Cada entidad con su cuenta, sin `act_`; las de la anterior siguen ahí.
        $this->assertSame(
            [
                ['1111111111', 'ad', '9001'], ['1111111111', 'adset', '120211'], ['1111111111', 'campaign', '120201'],
                ['2222222222', 'ad', '9002'], ['2222222222', 'adset', '120212'], ['2222222222', 'campaign', '120202'],
            ],
            MetaAdEntity::orderBy('ad_account_id')->orderBy('level')->get()
                ->map(fn (MetaAdEntity $e): array => [$e->ad_account_id, $e->level, $e->entity_id])->all(),
        );
        $this->assertSame('ACTIVE', MetaAdEntity::where('ad_account_id', '1111111111')->where('entity_id', '120201')->value('effective_status'));

        // Solo la cuenta configurada resuelve un anuncio.
        $this->assertSame(['9002'], MetaAdEntity::forConfiguredAccount()->where('level', 'ad')->pluck('entity_id')->all());
        $this->assertNull(MetaAdEntity::forConfiguredAccount()->where('level', 'ad')->where('entity_id', '9001')->first());
        $this->assertSame(['120202'], MetaAdEntity::forConfiguredAccount()->where('level', 'campaign')->pluck('entity_id')->all());

        // Sin una cuenta válida no resuelve nada, ni contra la cuenta anterior.
        foreach (['', 'act_123/../me'] as $cuenta) {
            $this->configureMetaAds(['ad_account_id' => $cuenta]);
            $this->assertSame(0, MetaAdEntity::forConfiguredAccount()->count(), "cuenta «{$cuenta}»");
        }
    }

    /** Contrato 23: con otra pasada en curso no se llama a Meta ni se escribe nada. */
    public function test_con_otra_pasada_en_curso_no_hace_nada(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '30000')]]);

        $cerrojo = Cache::lock('meta-ads-sync', 900);
        $this->assertTrue($cerrojo->get());

        $this->assertSame(['status' => 'running'], $this->sync()->run('schedule'));
        Http::assertNothingSent();
        $this->assertSame(0, MetaSyncRun::count());
        $this->assertSame(0, MetaAdInsightDaily::count());

        $cerrojo->release();

        $this->assertSame('ok', $this->sync()->run('schedule')['status']);
        $this->assertSame(1, MetaAdInsightDaily::count());
    }

    /** Contrato 23: el cerrojo se suelta también cuando Meta falla. */
    public function test_el_cerrojo_se_suelta_aunque_meta_falle(): void
    {
        $this->fakeMeta(fn () => $this->graphError(17, 'User request limit reached'));

        $this->assertSame('failed', $this->sync()->run('schedule')['status']);

        $cerrojo = Cache::lock('meta-ads-sync', 900);
        $this->assertTrue($cerrojo->get(), 'El cerrojo quedó tomado tras un fallo.');
        $cerrojo->release();
    }

    /** Contrato 23: la clave única es la última barrera contra el duplicado. */
    public function test_la_clave_unica_impide_duplicar_un_anuncio_en_un_dia(): void
    {
        $fila = [
            'ad_account_id' => self::ACCOUNT, 'date' => '2026-10-05', 'ad_id' => '9001', 'spend' => '10.00',
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('meta_ad_insights_daily')->insert($fila);

        // Otro día u otra cuenta sí caben.
        DB::table('meta_ad_insights_daily')->insert(['date' => '2026-10-04'] + $fila);
        DB::table('meta_ad_insights_daily')->insert(['ad_account_id' => '999'] + $fila);

        // Cada inserción que debe fallar va en su propia transacción (un savepoint
        // dentro de la del test): PostgreSQL aborta la transacción entera tras un
        // error, y sin el savepoint las comprobaciones siguientes no podrían leer.
        try {
            DB::transaction(fn () => DB::table('meta_ad_insights_daily')->insert($fila));
            $this->fail('Se insertó dos veces el mismo anuncio el mismo día.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(3, DB::table('meta_ad_insights_daily')->count());
        }

        // La dimensión: la clave es (cuenta, nivel, id). La misma entidad desde
        // otra cuenta es otra fila, y una fila sin cuenta no entra.
        $entidad = ['ad_account_id' => self::ACCOUNT, 'level' => 'ad', 'entity_id' => '9001', 'name' => 'Anuncio'];
        DB::table('meta_ad_entities')->insert($entidad);
        DB::table('meta_ad_entities')->insert(['ad_account_id' => '999'] + $entidad);
        $this->assertSame(2, DB::table('meta_ad_entities')->count());

        try {
            DB::transaction(fn () => DB::table('meta_ad_entities')->insert(['level' => 'ad', 'entity_id' => '9002', 'name' => 'Sin cuenta']));
            $this->fail('Entró una entidad sin cuenta publicitaria.');
        } catch (QueryException $e) {
            $this->assertNotInstanceOf(UniqueConstraintViolationException::class, $e);
            $this->assertStringContainsString('ad_account_id', $e->getMessage());
        }

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('meta_ad_entities')->insert($entidad);
    }

    /** Una pasada que murió sin cerrar su fila no deja el estado en «en curso» para siempre. */
    public function test_una_pasada_que_murio_sin_cerrar_se_marca_interrumpida(): void
    {
        $huerfana = MetaSyncRun::create([
            'kind' => 'insights', 'status' => 'running', 'trigger' => 'schedule', 'ad_account_id' => self::ACCOUNT,
            'range_from' => '2026-09-29', 'range_to' => '2026-10-05', 'started_at' => now()->subMinutes(5),
        ]);

        // Reciente: en curso.
        $this->assertSame('running', $this->sync()->status()['status']);

        // Más vieja que el cerrojo: murió.
        Carbon::setTestNow(now()->addMinutes(20));
        $estado = $this->sync()->status();
        $this->assertSame('failed', $estado['status']);
        $this->assertSame('interrupted', $estado['last_error']['code']);

        $this->fakeMeta(['data' => []]);
        $this->assertSame('ok', $this->sync()->run('schedule')['status']);

        $huerfana->refresh();
        $this->assertSame('failed', $huerfana->status);
        $this->assertSame('interrupted', $huerfana->error_code);
        $this->assertSame('ok', $this->sync()->status()['status']);
    }

    /** El token va en la cabecera y en ningún otro sitio; y es el de anuncios, no el de WhatsApp. */
    public function test_el_token_va_solo_en_la_cabecera(): void
    {
        // El canal de WhatsApp apagado y con su propio token: nada de eso se usa.
        config()->set('meta.enabled', false);
        config()->set('meta.access_token', 'whatsapp-token-que-no-debe-usarse');
        $this->configureMetaAds(['ad_account_id' => 'act_'.self::ACCOUNT]);

        $this->fakeMeta(
            ['data' => [$this->insightRow('2026-10-05', '9001', '30000')]],
            ['data' => [['id' => '120201', 'name' => 'Campaña Octubre', 'effective_status' => 'ACTIVE']]],
        );

        $this->assertSame('ok', $this->sync()->run('schedule')['status']);

        // Gasto y estados de campañas, conjuntos y anuncios: los cuatro, bordes de la
        // cuenta (`?ids=` ya no existe en v26).
        $peticiones = Http::recorded()->map(fn (array $par): Request => $par[0]);
        $this->assertSame(['insights', 'campaigns', 'adsets', 'ads'], $peticiones->map(fn (Request $r) => basename((string) parse_url($r->url(), PHP_URL_PATH)))->values()->all());

        foreach ($peticiones as $peticion) {
            $this->assertStringStartsWith(self::GRAPH.'/act_'.self::ACCOUNT.'/', $peticion->url());
            $this->assertStringNotContainsString(self::ADS_TOKEN, $peticion->url());
            $this->assertStringNotContainsString('access_token', $peticion->url());
            $this->assertStringNotContainsString('act_act_', $peticion->url());
            $this->assertSame(['Bearer '.self::ADS_TOKEN], $peticion->header('Authorization'));
        }
    }

    /** Se pagina con el cursor sobre el mismo endpoint; la URL de `paging.next` no se pide nunca. */
    public function test_pagina_con_el_cursor_after_y_no_sigue_paging_next(): void
    {
        $this->fakeMeta(function (Request $request) {
            return match ($this->queryOf($request)['after'] ?? null) {
                null => Http::response([
                    'data' => [$this->insightRow('2026-10-04', '9001', '100')],
                    'paging' => [
                        'cursors' => ['before' => 'CUR-0', 'after' => 'CUR-1'],
                        'next' => 'https://evil.example.com/steal?access_token=LEAK&after=CUR-1',
                    ],
                ]),
                // Una página vacía que aún trae `next` no es el final.
                'CUR-1' => Http::response([
                    'data' => [],
                    'paging' => ['cursors' => ['after' => 'CUR-2'], 'next' => self::GRAPH.'/act_'.self::ACCOUNT.'/insights?after=CUR-2&access_token=LEAK'],
                ]),
                'CUR-2' => Http::response([
                    'data' => [$this->insightRow('2026-10-05', '9001', '200')],
                    'paging' => ['cursors' => ['after' => 'CUR-3']],
                ]),
            };
        });

        $this->assertSame('ok', $this->sync()->run('schedule')['status']);

        $peticiones = $this->requestsTo('insights');
        $this->assertSame([null, 'CUR-1', 'CUR-2'], array_map(fn (Request $r) => $this->queryOf($r)['after'] ?? null, $peticiones));

        foreach (Http::recorded() as [$peticion]) {
            $this->assertSame('graph.facebook.com', parse_url($peticion->url(), PHP_URL_HOST));
            $this->assertStringNotContainsString('LEAK', $peticion->url());
        }

        // Cada página repite la consulta completa.
        foreach ($peticiones as $peticion) {
            $this->assertSame('ad', $this->queryOf($peticion)['level']);
            $this->assertArrayHasKey('time_range', $this->queryOf($peticion));
        }

        $this->assertSame(2, MetaAdInsightDaily::count());
        $this->assertSame(300.0, $this->spendFor('2026-10-04', '2026-10-05')['amount']);
    }

    /** Una fila rara invalida la pasada entera: guardar el resto daría un total incompleto. */
    public function test_una_fila_invalida_de_meta_no_deja_un_total_a_medias(): void
    {
        $casos = [
            'sin anuncio' => $this->insightRow('2026-10-05', '9002', '100', ['ad_id' => null]),
            'día fuera del rango' => $this->insightRow('2026-09-01', '9002', '100'),
            'gasto que no es un número' => $this->insightRow('2026-10-05', '9002', 'mucho'),
        ];

        foreach ($casos as $caso => $fila) {
            $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '30000'), $fila]]);

            $result = $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

            $this->assertSame('failed', $result['status'], $caso);
            $this->assertSame('other', $result['error_code'], $caso);
            $this->assertFalse($result['retryable'], $caso);
            $this->assertSame(0, MetaAdInsightDaily::count(), $caso);
        }
    }

    /** Un `next` sin cursor no se sigue: es un fallo, no una lista incompleta dada por buena. */
    public function test_una_pagina_siguiente_sin_cursor_es_un_fallo(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '100')], 'paging' => ['next' => 'https://evil.example.com/x']]);

        $result = $this->sync()->run('schedule');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('other', $result['error_code']);
        $this->assertFalse($result['retryable']);
        $this->assertSame(0, MetaAdInsightDaily::count());
    }

    /** El mensaje de error que se guarda y se enseña no lleva el token ni ids completos. */
    public function test_el_mensaje_de_error_no_lleva_el_token_ni_ids_completos(): void
    {
        // Forma de token de Meta, armada por partes para no dejar el patrón en el repo.
        $otroToken = 'EA'.'A'.str_repeat('Zx9', 20);

        $this->fakeMeta(fn () => $this->graphError(
            190,
            'Error validating access token: Session has expired. token='.self::ADS_TOKEN.' otro='.$otroToken
                .' en act_'.self::ACCOUNT.' access_token='.self::ADS_TOKEN.' Bearer '.self::ADS_TOKEN,
            400,
            ['error_subcode' => 463],
        ));

        $result = $this->sync()->run('schedule');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('permission', $result['error_code']);
        $this->assertFalse($result['retryable']);

        $run = MetaSyncRun::sole();
        $this->assertStringContainsString('código 190/463', $run->error_message);
        $this->assertStringContainsString('act_***7890', $run->error_message);

        foreach ([$run->error_message, $result['error_message'], json_encode($this->sync()->status())] as $texto) {
            $this->assertStringNotContainsString(self::ADS_TOKEN, $texto);
            $this->assertStringNotContainsString($otroToken, $texto);
            $this->assertStringNotContainsString(self::ACCOUNT, $texto);
        }
    }

    /**
     * Un fallo nuestro (aquí, la base) no enseña en el panel el host, la base ni
     * el SQL. La fila y status() dicen «error interno»; el detalle queda en el
     * log del canal y en la excepción, que el job deja en failed_jobs.
     */
    public function test_un_fallo_interno_no_ensena_la_base_ni_el_sql(): void
    {
        Log::spy();
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '30000')]]);

        // PostgreSQL rechaza el upsert. Así formatea Laravel esa QueryException:
        // con el host, el puerto, la base y el SQL con sus valores.
        DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
            if (str_starts_with($query, 'insert into "meta_ad_insights_daily"')) {
                throw new QueryException(
                    'pgsql',
                    $query,
                    $bindings,
                    new PDOException('SQLSTATE[22003]: Numeric value out of range: 7 ERROR:  numeric field overflow'),
                    ['driver' => 'pgsql', 'host' => '10.0.3.17', 'port' => 5432, 'database' => 'ironbody_prod'],
                    'write',
                );
            }
        });

        try {
            $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'));
            $this->fail('Un fallo propio se relanza, para que el job lo deje en failed_jobs.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Host: 10.0.3.17', $e->getMessage());
        }

        $run = MetaSyncRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('internal', $run->error_code);
        $this->assertSame('Error interno al guardar la sincronización.', $run->error_message);
        $this->assertSame(0, MetaAdInsightDaily::count());

        $estado = $this->sync()->status();
        $this->assertSame('failed', $estado['status']);
        $this->assertSame(['code' => 'internal', 'message' => 'Error interno al guardar la sincronización.'], $estado['last_error']);

        $gasto = $this->spendFor('2026-10-05', '2026-10-05');
        $this->assertSame('sync_failed', $gasto['status']);
        $this->assertNull($gasto['amount']);
        $this->assertSame('internal', $gasto['reason']);

        foreach ([$run->error_message, json_encode($estado), json_encode($gasto)] as $texto) {
            foreach (['10.0.3.17', 'Port: 5432', 'ironbody_prod', 'pgsql', 'SQLSTATE', 'insert into', 'meta_ad_insights_daily', 'Video promo', 'QueryException'] as $detalle) {
                $this->assertStringNotContainsString($detalle, $texto);
            }
        }

        // El detalle, en el log del canal.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $evento, array $contexto): bool => $evento === 'meta.ads.sync.crashed'
                && ($contexto['error_code'] ?? null) === 'internal'
                && ($contexto['exception'] ?? null) === 'QueryException'
                && str_contains((string) ($contexto['error_message'] ?? ''), 'Database: ironbody_prod')
                && str_contains((string) ($contexto['error_message'] ?? ''), 'insert into "meta_ad_insights_daily"'))
            ->once();
    }

    /** Un timeout se guarda resumido: el texto de Guzzle trae la URL completa. */
    public function test_un_timeout_no_guarda_la_url(): void
    {
        $this->fakeMeta(fn (Request $request) => Http::failedConnection(
            'cURL error 28: Operation timed out after 5001 milliseconds for '.$request->url().'&access_token='.self::ADS_TOKEN,
        )($request));

        $result = $this->sync()->run('schedule');

        $this->assertSame('transient', $result['error_code']);
        $this->assertTrue($result['retryable']);
        $this->assertSame('Sin respuesta de Meta (cURL error 28).', MetaSyncRun::sole()->error_message);
    }

    /** Sin token o sin cuenta: no se llama a Meta, no se toca nada y queda constancia. */
    public function test_sin_configuracion_no_llama_a_meta(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '30000')]]);

        $casos = [
            'missing_access_token' => ['access_token' => ''],
            'missing_ad_account_id' => ['ad_account_id' => ''],
            'invalid_ad_account_id' => ['ad_account_id' => 'act_123/../me'],
            'invalid_timezone' => ['timezone' => 'Marte/Olympus'],
        ];

        foreach ($casos as $motivo => $config) {
            $this->configureMetaAds($config);

            $result = $this->sync()->run('schedule');

            $this->assertSame('not_configured', $result['status'], $motivo);
            $this->assertSame($motivo, $result['error_code'], $motivo);
            $this->assertFalse($result['retryable'], $motivo);

            $estado = $this->sync()->status();
            $this->assertSame('not_configured', $estado['status'], $motivo);
            $this->assertFalse($estado['configured'], $motivo);
            $this->assertSame($motivo, $estado['reason'], $motivo);
        }

        Http::assertNothingSent();
        $this->assertSame(4, MetaSyncRun::where('status', 'not_configured')->count());
        $this->assertSame(0, MetaAdInsightDaily::count());
    }

    /** status(): nunca sincronizado, ok, viejo, fallido y en curso. */
    public function test_el_estado_de_la_sincronizacion(): void
    {
        $estado = $this->sync()->status();
        $this->assertSame('stale', $estado['status']);
        $this->assertTrue($estado['configured']);
        $this->assertNull($estado['last_success_at']);
        $this->assertNull($estado['minutes_since_success']);
        $this->assertNull($estado['covered_to']);

        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '30000')]]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        $estado = $this->sync()->status();
        $this->assertSame('ok', $estado['status']);
        $this->assertSame('2026-10-05T15:00:00+00:00', $estado['last_success_at']);
        $this->assertSame('2026-10-05T15:00:00+00:00', $estado['last_attempt_at']);
        $this->assertSame(0, $estado['minutes_since_success']);
        $this->assertNull($estado['last_error']);
        $this->assertSame(['2026-10-01', '2026-10-05'], [$estado['covered_from'], $estado['covered_to']]);

        Carbon::setTestNow(now()->addMinutes(181));
        $estado = $this->sync()->status();
        $this->assertSame('stale', $estado['status']);
        $this->assertSame(181, $estado['minutes_since_success']);

        $this->fakeMeta(fn () => $this->graphError(17, 'User request limit reached'));
        $this->sync()->run('schedule');
        $estado = $this->sync()->status();
        $this->assertSame('failed', $estado['status']);
        $this->assertSame('rate_limit', $estado['last_error']['code']);
        $this->assertStringContainsString('código 17', $estado['last_error']['message']);
        $this->assertSame('2026-10-05T15:00:00+00:00', $estado['last_success_at']);

        MetaSyncRun::create([
            'kind' => 'insights', 'status' => 'running', 'trigger' => 'manual', 'ad_account_id' => self::ACCOUNT,
            'range_from' => '2026-10-05', 'range_to' => '2026-10-05', 'started_at' => now(),
        ]);
        $this->assertSame('running', $this->sync()->status()['status']);
    }

    /** La cobertura funde tramos que se tocan y no cuenta fallos ni otras cuentas. */
    public function test_la_cobertura_solo_cuenta_exitos_de_esta_cuenta(): void
    {
        $pasada = fn (string $desde, string $hasta, string $estado = 'ok', string $cuenta = self::ACCOUNT) => MetaSyncRun::create([
            'kind' => 'insights', 'status' => $estado, 'trigger' => 'manual', 'ad_account_id' => $cuenta,
            'range_from' => $desde, 'range_to' => $hasta, 'started_at' => now(), 'finished_at' => now(),
        ]);

        $pasada('2026-09-01', '2026-09-07');
        $pasada('2026-09-08', '2026-09-10');
        $pasada('2026-09-05', '2026-09-09');
        $pasada('2026-09-20', '2026-09-25');
        $pasada('2026-09-11', '2026-09-19', 'failed');
        $pasada('2026-09-11', '2026-09-19', 'ok', '999');

        $this->assertSame([['2026-09-01', '2026-09-10'], ['2026-09-20', '2026-09-25']], $this->sync()->coverage());
        $this->assertTrue($this->sync()->covers('2026-09-02', '2026-09-10'));
        $this->assertFalse($this->sync()->covers('2026-09-09', '2026-09-21'));
        $this->assertFalse($this->sync()->covers('2026-09-12', '2026-09-12'));
    }
}
