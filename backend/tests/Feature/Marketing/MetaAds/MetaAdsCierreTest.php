<?php

namespace Tests\Feature\Marketing\MetaAds;

use App\Models\MetaAdEntity;
use App\Models\MetaAdInsightDaily;
use App\Models\MetaAdReachSnapshot;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Meta\MetaAdsSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Marketing\Attribution\SeedsAttribution;
use Tests\TestCase;

/**
 * Cierre del Objetivo 3, lado Meta: las métricas reales de la cuenta, el estado
 * de cada nivel, el alcance (que no se suma), la comprobación de la cuenta antes
 * de sincronizar, el relleno de 2026 y la conciliación.
 *
 * Reloj: 5 de octubre de 2026, 10:00 en Bogotá. Ninguna prueba sale a la red.
 */
class MetaAdsCierreTest extends TestCase
{
    use FakesMetaAds, RefreshDatabase, SeedsAttribution;

    private const STARTED = 'onsite_conversion.messaging_conversation_started_7d';

    private const REPLIED = 'onsite_conversion.messaging_conversation_replied_7d';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 15:00:00');
        config()->set('caja.timezone', 'America/Bogota');
        $this->configureMetaAds();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── FASE 3: métricas reales ─────────────────────────────────────────────

    /** Las métricas de Meta salen de sus campos y de `actions`; lo raro de `actions` no tumba la pasada. */
    public function test_guarda_las_metricas_reales_y_la_lista_cruda_de_acciones(): void
    {
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-02', '9001', '60000.00', [
                'impressions' => '1000', 'clicks' => '50', 'inline_link_clicks' => '40',
                'actions' => [
                    ['action_type' => self::STARTED, 'value' => '10'],
                    ['action_type' => self::REPLIED, 'value' => '6'],
                    ['action_type' => 'landing_page_view', 'value' => '4'],
                    ['action_type' => 'link_click', 'value' => '40'],
                    ['action_type' => '<script>', 'value' => '1'],       // forma inválida: se ignora
                    ['action_type' => 'post_engagement', 'value' => 'x'], // valor no numérico: se ignora
                ],
            ]),
            // Sin `actions`: las columnas quedan en 0 y la lista en null.
            $this->insightRow('2026-10-03', '9001', '40000.00', ['impressions' => '3000', 'clicks' => '30', 'inline_link_clicks' => '20']),
        ]]);

        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);

        $dia = MetaAdInsightDaily::where('date', '2026-10-02')->sole();
        $this->assertSame([40, 10, 6, 4], [$dia->inline_link_clicks, $dia->messaging_conversations_started, $dia->messaging_conversations_replied, $dia->landing_page_views]);
        $this->assertSame([
            ['action_type' => 'landing_page_view', 'value' => 4],
            ['action_type' => 'link_click', 'value' => 40],
            ['action_type' => self::REPLIED, 'value' => 6],
            ['action_type' => self::STARTED, 'value' => 10],
        ], $dia->actions);

        $otro = MetaAdInsightDaily::where('date', '2026-10-03')->sole();
        $this->assertSame([20, 0, 0, 0], [$otro->inline_link_clicks, $otro->messaging_conversations_started, $otro->messaging_conversations_replied, $otro->landing_page_views]);
        $this->assertNull($otro->actions);

        // Por campaña: sumas del periodo, y las métricas que Meta reporta.
        [$desde, $hasta] = $this->bogotaDays('2026-10-01', '2026-10-05');
        $fila = $this->reader()->byLevel('campaign', $desde, $hasta)->sole();
        $this->assertSame(
            [100000.0, 4000, 80, 60, 10, 6, 4],
            [$fila['spend'], $fila['impressions'], $fila['clicks'], $fila['link_clicks'], $fila['messaging_started'], $fila['messaging_replied'], $fila['landing_page_views']],
        );
        $this->assertSame(['messaging_started' => true, 'messaging_replied' => true, 'landing_page_views' => true, 'reach' => false], $this->reader()->metricsAvailable());
    }

    /** Dos pasadas iguales: mismas filas lógicas, mismo gasto, mismas métricas, cero duplicados. */
    public function test_dos_pasadas_iguales_no_duplican_nada(): void
    {
        $filas = [
            $this->insightRow('2026-10-02', '9001', '60000.00', ['actions' => [['action_type' => self::STARTED, 'value' => '10']]]),
            $this->insightRow('2026-10-03', '9002', '40000.00', ['ad_name' => 'Video 2']),
        ];
        $this->fakeMeta(['data' => $filas]);

        $foto = function (): array {
            return [
                MetaAdInsightDaily::count(),
                round((float) MetaAdInsightDaily::sum('spend'), 2),
                (int) MetaAdInsightDaily::sum('messaging_conversations_started'),
                MetaAdInsightDaily::query()->orderBy('date')->orderBy('ad_id')->get(['date', 'ad_id', 'spend', 'messaging_conversations_started', 'actions'])->toArray(),
                MetaAdEntity::count(),
            ];
        };

        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));
        $primera = $foto();
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        $this->assertSame($primera, $foto());
        $this->assertSame([2, 100000.0, 10], array_slice($primera, 0, 3));
        $this->assertSame(2, MetaSyncRun::where('status', 'ok')->count());
    }

    /**
     * Los estados de conjuntos y anuncios salen de los bordes de la cuenta
     * (`/adsets` y `/ads`), que aquí contestan como Meta: solo lo que se pide, y
     * error si se piden eliminados. `?ids=` ya no existe en v26 (el falso lo
     * rechaza). Las listas pedidas se fijan literales, no contra la constante.
     */
    public function test_estados_de_conjuntos_y_anuncios_por_los_bordes_de_la_cuenta(): void
    {
        $this->fakeMeta(
            ['data' => [$this->insightRow('2026-10-02', '9001', '1000.00')]],
            $this->bordeComoMeta([['id' => '120201', 'name' => 'Campaña Octubre', 'effective_status' => 'PAUSED'], ['id' => '120299', 'name' => 'Sin gasto', 'effective_status' => 'ARCHIVED']]),
        );
        $this->fakeMetaExtras(
            adsets: $this->bordeComoMeta([['id' => '120211', 'effective_status' => 'CAMPAIGN_PAUSED'], ['id' => '555', 'effective_status' => 'ACTIVE']]),
            ads: $this->bordeComoMeta([['id' => '9001', 'effective_status' => 'ARCHIVED']]),
        );

        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);

        $this->assertSame(['PAUSED', 'CAMPAIGN_PAUSED', 'ARCHIVED'], [
            MetaAdEntity::where('level', 'campaign')->sole()->effective_status,
            MetaAdEntity::where('level', 'adset')->sole()->effective_status,
            MetaAdEntity::where('level', 'ad')->sole()->effective_status,
        ]);
        $this->assertSame(0, MetaAdEntity::whereIn('entity_id', ['555', '120299'])->count(), 'un estado no mete entidades sin gasto en la dimensión (tampoco campañas)');

        $pedidos = fn (string $borde) => json_decode($this->queryOf($this->requestsTo($borde)[0])['effective_status'], true);
        $this->assertSame(['ACTIVE', 'PAUSED', 'ARCHIVED', 'IN_PROCESS', 'WITH_ISSUES'], $pedidos('campaigns'));
        $this->assertSame(['ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ARCHIVED', 'IN_PROCESS', 'WITH_ISSUES'], $pedidos('adsets'));
        $this->assertSame([
            'ACTIVE', 'PAUSED', 'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED', 'PENDING_BILLING_INFO',
            'CAMPAIGN_PAUSED', 'ARCHIVED', 'ADSET_PAUSED', 'IN_PROCESS', 'WITH_ISSUES',
        ], $pedidos('ads'));
        $this->assertSame([], collect(Http::recorded())->filter(fn (array $p) => isset($this->queryOf($p[0])['ids']))->all(), 'nada pide ?ids=');
    }

    /** Si un borde de estados falla, el otro se aplica igual y lo conocido del que falló no se borra (en las dos direcciones). */
    public function test_un_borde_de_estados_que_falla_no_impide_el_otro(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-02', '9001', '1000.00')]], ['data' => []]);
        $this->fakeMetaExtras(
            adsets: $this->bordeComoMeta([['id' => '120211', 'effective_status' => 'ACTIVE']]),
            ads: $this->bordeComoMeta([['id' => '9001', 'effective_status' => 'ACTIVE']]),
        );
        $pasar = fn () => $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status'];
        $estados = fn () => [MetaAdEntity::where('level', 'adset')->sole()->effective_status, MetaAdEntity::where('level', 'ad')->sole()->effective_status];
        $this->assertSame('ok', $pasar());
        $this->assertSame(['ACTIVE', 'ACTIVE'], $estados());

        // /ads falla (un error cualquiera, no un límite): /adsets se aplica y el anuncio conserva lo que tenía.
        $this->fakeMetaExtras(adsets: $this->bordeComoMeta([['id' => '120211', 'effective_status' => 'PAUSED']]), ads: fn () => $this->graphError(1, 'An unknown error has occurred.', 500));
        $this->assertSame('ok', $pasar());
        $this->assertSame(['PAUSED', 'ACTIVE'], $estados());

        // Al revés: /adsets falla y /ads se aplica.
        $this->fakeMetaExtras(adsets: fn () => $this->graphError(1, 'An unknown error has occurred.', 500), ads: $this->bordeComoMeta([['id' => '9001', 'effective_status' => 'ARCHIVED']]));
        $this->assertSame('ok', $pasar());
        $this->assertSame(['PAUSED', 'ARCHIVED'], $estados());
    }

    /** refreshStatuses() respeta el cerrojo de la sincronización y nunca lanza (ni con la base caída). */
    public function test_refrescar_estados_respeta_el_cerrojo_y_no_lanza(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-02', '9001', '1000.00')]], ['data' => []]);
        $this->assertSame('ok', $this->sync()->run(MetaSyncRun::TRIGGER_BACKFILL, $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);
        $this->fakeMetaExtras(ads: $this->bordeComoMeta([['id' => '9001', 'effective_status' => 'PAUSED']]));

        $cerrojo = Cache::lock(MetaAdsSync::LOCK_KEY, 60);
        $this->assertTrue($cerrojo->get());
        $this->assertSame(['status' => 'running', 'campaigns' => 0, 'adsets' => 0, 'ads' => 0], $this->sync()->refreshStatuses());
        $this->assertNull(MetaAdEntity::where('level', 'ad')->sole()->effective_status, 'con una pasada en curso no toca nada');
        $cerrojo->release();

        $this->assertSame(['status' => 'ok', 'campaigns' => 0, 'adsets' => 0, 'ads' => 1], $this->sync()->refreshStatuses());
        $this->assertSame('PAUSED', MetaAdEntity::where('level', 'ad')->sole()->effective_status);

        Schema::rename('meta_ad_entities', 'meta_ad_entities_fuera');
        $this->assertSame('failed', $this->sync()->refreshStatuses()['status']);
        Schema::rename('meta_ad_entities_fuera', 'meta_ad_entities');
        $this->assertTrue(Cache::lock(MetaAdsSync::LOCK_KEY, 60)->get(), 'el cerrojo se suelta también al fallar');
    }

    /**
     * Una pasada manual de días viejos (un re-sync de enero) trae cobertura,
     * pero no hace «al día» un panel cuya foto de hoy es vieja, ni su fallo lo
     * pone en «Error».
     */
    public function test_una_pasada_de_dias_viejos_no_cambia_la_frescura(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-02', '9001', '1000.00')]]);
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->assertSame('ok', $this->sync()->run(MetaSyncRun::TRIGGER_SCHEDULE)['status']);

        Carbon::setTestNow('2026-10-05 15:00:00');
        $this->fakeMeta(['data' => []]);
        $this->assertSame('ok', $this->sync()->run(MetaSyncRun::TRIGGER_MANUAL, $this->day('2026-01-01'), $this->day('2026-01-31'))['status']);
        $estado = $this->sync()->status();
        $this->assertSame(['stale', 300, '2026-10-05T10:00:00+00:00'], [$estado['status'], $estado['minutes_since_success'], $estado['last_success_at']]);

        $this->fakeMeta(fn () => $this->graphError(1, 'An unknown error has occurred.', 500));
        $this->assertSame('failed', $this->sync()->run(MetaSyncRun::TRIGGER_MANUAL, $this->day('2026-02-01'), $this->day('2026-02-28'))['status']);
        $this->assertSame(['stale', null], [$this->sync()->status()['status'], $this->sync()->status()['last_error']]);

        // Una manual que llega hasta hoy sí cuenta.
        $this->fakeMeta(['data' => []]);
        $this->assertSame('ok', $this->sync()->run(MetaSyncRun::TRIGGER_MANUAL)['status']);
        $this->assertSame(['ok', 0], [$this->sync()->status()['status'], $this->sync()->status()['minutes_since_success']]);
    }

    /** Los estados llegan también a entidades que trajo OTRA pasada (un tramo del relleno de hace horas). */
    public function test_los_estados_llegan_a_entidades_de_otras_pasadas(): void
    {
        $this->fakeMeta(fn (Request $r) => Http::response(['data' => json_decode($this->queryOf($r)['time_range'], true)['since'] <= '2026-01-02'
            && json_decode($this->queryOf($r)['time_range'], true)['until'] >= '2026-01-02'
            ? [$this->insightRow('2026-01-02', '9001', '1000.00')] : []]));

        $this->assertSame('ok', $this->sync()->run(MetaSyncRun::TRIGGER_BACKFILL, $this->day('2026-01-01'), $this->day('2026-01-14'))['status']);
        $this->assertNull(MetaAdEntity::where('level', 'ad')->sole()->effective_status, 'el tramo no pide estados');

        Carbon::setTestNow(now()->addHour());
        $this->fakeMetaExtras(
            adsets: ['data' => [['id' => '120211', 'effective_status' => 'CAMPAIGN_PAUSED']]],
            ads: ['data' => [['id' => '9001', 'effective_status' => 'ARCHIVED']]],
        );
        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);

        $this->assertSame(['CAMPAIGN_PAUSED', 'ARCHIVED'], [
            MetaAdEntity::where('level', 'adset')->sole()->effective_status,
            MetaAdEntity::where('level', 'ad')->sole()->effective_status,
        ]);
    }

    /**
     * Un tramo del relleno no pide estados (son de toda la cuenta y la app tiene
     * un cupo corto): el relleno los refresca UNA vez al final, también los de
     * las entidades que trajeron los tramos.
     */
    public function test_el_relleno_pide_los_estados_una_sola_vez_al_final(): void
    {
        $this->fakeMeta(
            // Solo el tramo que contiene el 2 de enero trae la fila.
            fn (Request $r) => Http::response(['data' => json_decode($this->queryOf($r)['time_range'], true)['since'] <= '2026-01-02'
                && json_decode($this->queryOf($r)['time_range'], true)['until'] >= '2026-01-02'
                ? [$this->insightRow('2026-01-02', '9001', '1000.00')] : []]),
            ['data' => [['id' => '120201', 'name' => 'Campaña Octubre', 'effective_status' => 'PAUSED']]],
        );
        $this->fakeMetaExtras(
            adsets: ['data' => [['id' => '120211', 'effective_status' => 'CAMPAIGN_PAUSED']]],
            ads: ['data' => [['id' => '9001', 'effective_status' => 'CAMPAIGN_PAUSED']]],
        );
        Sleep::fake();

        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-01-01', '--to' => '2026-01-28', '--batch-days' => 14])
            ->expectsOutputToContain('Estados de Meta aplicados: 1 campañas, 1 conjuntos y 1 anuncios (ok)')
            ->assertSuccessful();

        $this->assertCount(1, $this->requestsTo('campaigns'));
        $this->assertCount(1, $this->requestsTo('adsets'));
        $this->assertCount(1, $this->requestsTo('ads'));
        $this->assertSame(['PAUSED', 'CAMPAIGN_PAUSED', 'CAMPAIGN_PAUSED'], [
            MetaAdEntity::where('level', 'campaign')->sole()->effective_status,
            MetaAdEntity::where('level', 'adset')->sole()->effective_status,
            MetaAdEntity::where('level', 'ad')->sole()->effective_status,
        ]);
    }

    /**
     * Una campaña archivada deja de salir como ACTIVE: `/campaigns` devuelve las
     * archivadas si se piden. Pedir las eliminadas en ese borde es un error
     * (400, 100/1815001, comprobado con la cuenta real), así que no se piden: una
     * eliminada conserva su último estado conocido, y su gasto sigue entrando.
     */
    public function test_una_campana_archivada_no_se_queda_en_activa_y_nunca_se_piden_eliminadas(): void
    {
        $estado = 'ACTIVE';
        $this->fakeMeta(
            ['data' => [$this->insightRow('2026-10-02', '9001', '1000.00')]],
            function (Request $r) use (&$estado) {
                $pedidos = json_decode($this->queryOf($r)['effective_status'] ?? '[]', true) ?: [];
                // Como Meta: pedir eliminadas en este borde es un error.
                if (in_array('DELETED', $pedidos, true)) {
                    return $this->graphError(100, 'No se pueden solicitar objetos eliminados en este extremo.', 400, ['error_subcode' => 1815001]);
                }
                // Sin pedirlas, ni archivadas ni eliminadas; las eliminadas, nunca.
                $ve = $estado === 'ACTIVE' || ($estado !== 'DELETED' && in_array($estado, $pedidos, true));

                return Http::response(['data' => $ve ? [['id' => '120201', 'name' => 'Campaña Octubre', 'effective_status' => $estado]] : []]);
            },
        );

        $pasar = fn () => $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status'];
        $estadoGuardado = fn () => MetaAdEntity::where('level', 'campaign')->sole()->effective_status;

        $this->assertSame('ok', $pasar());
        $this->assertSame('ACTIVE', $estadoGuardado());

        $estado = 'ARCHIVED';
        $this->assertSame('ok', $pasar());
        $this->assertSame('ARCHIVED', $estadoGuardado());

        $estado = 'DELETED';
        $this->assertSame('ok', $pasar());
        $this->assertSame('ARCHIVED', $estadoGuardado(), 'una eliminada conserva el último estado conocido');
        $this->assertSame(1000.0, (float) MetaAdInsightDaily::sum('spend'), 'el gasto no depende del estado');
    }

    // ── Alcance: no se suma por días ───────────────────────────────────────

    /** El alcance se pide por rango entero y se guarda con sus fechas; un fallo de un rango no toca los demás. */
    public function test_el_alcance_se_guarda_por_rango_y_no_se_suma(): void
    {
        $this->fakeMeta(['data' => []]);
        $peticiones = [];
        $this->fakeMetaExtras(reach: function (Request $r) use (&$peticiones) {
            $q = $this->queryOf($r);
            $rango = json_decode($q['time_range'], true);
            $peticiones[] = [$q['level'], $rango['since'], $rango['until'], $q['time_increment'] ?? null, $q['filtering'] ?? null];

            if ($rango['since'] === '2026-10-05') {
                // Un fallo cualquiera (no un límite: tras un límite se deja de pedir).
                return $this->graphError(1, 'An unknown error has occurred.', 500);
            }

            return Http::response(['data' => $q['level'] === 'account'
                ? [['reach' => '5000', 'impressions' => '9000', 'frequency' => '1.8']]
                : [['campaign_id' => '120201', 'campaign_name' => 'Campaña Octubre', 'reach' => '4000', 'impressions' => '8000', 'frequency' => '2.0']]]);
        });

        $resultado = $this->sync()->refreshReach();

        // Hoy (falló), 7 días, 30 días, este mes y el mes anterior.
        $this->assertSame(['status' => 'failed', 'ranges_ok' => 4, 'ranges_failed' => 1, 'rows' => 8], $resultado);
        foreach ($peticiones as [$nivel, , , $incremento, $filtro]) {
            $this->assertNull($incremento, 'el alcance se pide para el rango entero');
            if ($nivel === 'campaign') {
                $this->assertContains('DELETED', json_decode($filtro, true)[0]['value']);
            }
        }

        $this->assertSame(4000, MetaAdReachSnapshot::where('level', 'campaign')->where('date_from', '2026-09-06')->where('date_to', '2026-10-05')->sole()->reach);
        $this->assertSame(['reach' => 5000, 'impressions' => 9000, 'frequency' => 1.8], array_slice($this->reader()->accountReach('2026-09-06', '2026-10-05'), 0, 3));
        $this->assertNull($this->reader()->accountReach('2026-10-05', '2026-10-05'), 'el rango que falló no tiene foto');
        // Del 1 al 5 de octubre es «Este mes»: Meta lo calculó. Del 2 al 5 no es ningún botón del panel.
        $this->assertSame(5000, $this->reader()->accountReach('2026-10-01', '2026-10-05')['reach']);
        $this->assertNull($this->reader()->accountReach('2026-10-02', '2026-10-05'), 'un rango que Meta no calculó no tiene alcance');

        // Repetirlo no duplica; una campaña que ya no sale de ese rango se borra solo de ese rango.
        $this->fakeMetaExtras(reach: fn (Request $r) => Http::response(['data' => $this->queryOf($r)['level'] === 'account'
            ? [['reach' => '5100', 'impressions' => '9100', 'frequency' => '1.78']]
            : []]));
        $this->sync()->refreshReach();
        $this->assertSame(0, MetaAdReachSnapshot::where('level', 'campaign')->where('date_to', '2026-10-05')->count());
        $this->assertSame(5, MetaAdReachSnapshot::where('level', 'account')->count());
    }

    // ── FASE 2: comprobar la cuenta antes de sincronizar ────────────────────

    /**
     * Tras un límite de Meta (17/2446079, 80000…) en una petición accesoria no se
     * pide nada más en esa pasada: ni los otros bordes de estados ni el alcance.
     * El gasto se guarda igual.
     */
    public function test_tras_un_limite_de_meta_no_se_le_pide_nada_mas_en_la_pasada(): void
    {
        $this->fakeMeta(
            ['data' => [$this->insightRow('2026-10-02', '9001', '1000.00')]],
            fn () => $this->graphError(17, 'User request limit reached', 400, ['error_subcode' => 2446079]),
        );
        $this->fakeMetaExtras(
            adsets: ['data' => [['id' => '120211', 'effective_status' => 'PAUSED']]],
            ads: ['data' => [['id' => '9001', 'effective_status' => 'ACTIVE']]],
            reach: ['data' => [['reach' => '10', 'impressions' => '20']]],
        );

        // La misma instancia para la pasada y el alcance, como el job y el comando.
        $sync = $this->sync();
        $this->assertSame('ok', $sync->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);
        $this->assertSame([], $this->requestsTo('adsets'), 'tras el límite no se piden los estados de conjuntos');
        $this->assertSame([], $this->requestsTo('ads'));
        $this->assertSame(['status' => 'rate_limit', 'ranges_ok' => 0, 'ranges_failed' => 0, 'rows' => 0], $sync->refreshReach());
        $this->assertSame(0, MetaAdReachSnapshot::count());
        $this->assertSame(1000.0, (float) MetaAdInsightDaily::sum('spend'));

        // La pasada siguiente (misma instancia) empieza limpia: el límite era de la anterior.
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-02', '9001', '1000.00')]], ['data' => []]);
        $this->assertSame('ok', $sync->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);
        $this->assertCount(1, $this->requestsTo('adsets'));
    }

    /** @return array<string, mixed> */
    private function cuenta(array $overrides = []): array
    {
        return array_merge([
            'id' => 'act_'.self::ACCOUNT, 'account_id' => self::ACCOUNT, 'name' => 'Fredy Medina', 'currency' => 'COP',
            'timezone_name' => 'America/Bogota', 'timezone_offset_hours_utc' => -5, 'account_status' => 1,
        ], $overrides);
    }

    /** @return array<string, mixed> `/me/permissions` con estos permisos concedidos (y uno rechazado, que no cuenta) */
    private function permisos(array $concedidos = ['ads_read']): array
    {
        return ['data' => [
            ...array_map(fn (string $p): array => ['permission' => $p, 'status' => 'granted'], $concedidos),
            ['permission' => 'ads_management', 'status' => 'declined'],
        ]];
    }

    public function test_la_comprobacion_valida_cuenta_zona_y_permisos_sin_enseñar_el_token(): void
    {
        $this->fakeMeta(['data' => []]);
        $this->fakeMetaExtras(account: $this->cuenta(), me: ['id' => '100001', 'name' => 'iron-ads-reader'], permissions: $this->permisos());

        $this->artisan('marketing:meta-ads-check', ['--expect-account' => self::ACCOUNT, '--expect-name' => 'fredy medina'])
            ->expectsOutputToContain('META_ACCOUNT_ID='.self::ACCOUNT)
            ->expectsOutputToContain('META_ACCOUNT_NAME=Fredy Medina')
            ->expectsOutputToContain('META_ACCOUNT_TIMEZONE=America/Bogota')
            ->expectsOutputToContain('META_ACCOUNT_CURRENCY=COP')
            ->expectsOutputToContain('TOKEN_SCOPES_OK=YES')
            ->expectsOutputToContain('ADS_READ=YES')
            ->doesntExpectOutputToContain(self::ADS_TOKEN)
            ->assertSuccessful();

        // El token solo viaja en la cabecera: en ninguna URL (ni /me ni /me/permissions ni la cuenta).
        $peticiones = collect(Http::recorded())->map(fn (array $p): Request => $p[0]);
        $this->assertSame(['/v99.0/me', '/v99.0/me/permissions', '/v99.0/act_'.self::ACCOUNT], $peticiones->map(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH))->all());
        foreach ($peticiones as $r) {
            $this->assertStringNotContainsString(self::ADS_TOKEN, $r->url());
            $this->assertStringNotContainsString('token', (string) parse_url($r->url(), PHP_URL_QUERY));
            $this->assertSame(['Bearer '.self::ADS_TOKEN], $r->header('Authorization'));
        }
        $this->assertSame(0, MetaSyncRun::count(), 'comprobar no sincroniza');
        $this->assertSame(0, MetaAdInsightDaily::count());
    }

    /** @return array<string, array{0: array<string,mixed>, 1: list<string>, 2: array<string,string>, 3: string}> */
    public static function cuentasQueNoValen(): array
    {
        return [
            'otra cuenta' => [[], ['ads_read'], ['--expect-account' => '999'], 'Cuenta esperada'],
            'otro nombre' => [[], ['ads_read'], ['--expect-name' => 'Otra Persona'], 'Nombre esperado'],
            'otra zona' => [['timezone_name' => 'America/Mexico_City'], ['ads_read'], [], 'Zona horaria del CRM'],
            'sin ads_read' => [[], ['business_management'], [], 'Permiso ads_read'],
        ];
    }

    #[DataProvider('cuentasQueNoValen')]
    public function test_la_comprobacion_falla_si_no_es_la_cuenta_o_no_puede_leerla(array $cuenta, array $concedidos, array $opciones, string $motivo): void
    {
        $this->fakeMeta(['data' => []]);
        $this->fakeMetaExtras(account: $this->cuenta($cuenta), permissions: $this->permisos($concedidos));

        $this->artisan('marketing:meta-ads-check', $opciones)
            ->expectsOutputToContain($motivo)
            ->doesntExpectOutputToContain(self::ADS_TOKEN)
            ->assertFailed();
    }

    /** Un token caducado o inválido: Meta contesta 190 y la comprobación falla sin enseñarlo. */
    public function test_un_token_invalido_no_pasa_la_comprobacion(): void
    {
        $this->fakeMeta(['data' => []]);
        $this->fakeMetaExtras(me: fn () => $this->graphError(190, 'Error validating access token: Session has expired.', 400));

        $this->artisan('marketing:meta-ads-check')
            ->expectsOutputToContain('Meta no contestó a la comprobación (permission)')
            ->doesntExpectOutputToContain(self::ADS_TOKEN)
            ->assertFailed();
    }

    /** Otra moneda solo se avisa: con el token como debe ser (y `public_profile`, que trae todo token), pasa. */
    public function test_la_comprobacion_avisa_de_otra_moneda_sin_bloquear(): void
    {
        $this->fakeMeta(['data' => []]);
        $this->fakeMetaExtras(account: $this->cuenta(['currency' => 'USD']), permissions: $this->permisos(['ads_read', 'public_profile']));

        $this->artisan('marketing:meta-ads-check')
            ->expectsOutputToContain('TOKEN_SCOPES_OK=YES')
            ->expectsOutputToContain('ADS_READ=YES')
            ->expectsOutputToContain('META_ACCOUNT_CURRENCY=USD')
            ->assertSuccessful();
    }

    /** @return array<string, array{0: list<string>, 1: string, 2: string}> */
    public static function permisosQueNoValen(): array
    {
        return [
            // ads_management también lee, pero escribe: la regla es un token SOLO con ads_read.
            'ads_read y ads_management' => [['ads_read', 'ads_management'], 'ADS_READ=YES', 'TOKEN_SCOPES_OK=NO (sobran: ads_management)'],
            // El usuario de sistema de WhatsApp de hoy con ads_read añadido: envía mensajes y administra el negocio.
            'el de WhatsApp con ads_read' => [
                ['ads_read', 'business_management', 'whatsapp_business_management', 'whatsapp_business_messaging', 'public_profile'],
                'ADS_READ=YES',
                'TOKEN_SCOPES_OK=NO (sobran: business_management, whatsapp_business_management, whatsapp_business_messaging)',
            ],
            'solo ads_management' => [['ads_management'], 'ADS_READ=NO', 'TOKEN_SCOPES_OK=NO (sobran: ads_management)'],
        ];
    }

    /** Un permiso de más bloquea, el que sea: el token vive en el servidor y no debe poder más que leer anuncios. */
    #[DataProvider('permisosQueNoValen')]
    public function test_la_comprobacion_falla_con_permisos_de_mas(array $concedidos, string $adsRead, string $scopes): void
    {
        $this->fakeMeta(['data' => []]);
        $this->fakeMetaExtras(account: $this->cuenta(), permissions: $this->permisos($concedidos));

        $this->artisan('marketing:meta-ads-check')
            ->expectsOutputToContain($adsRead)
            ->expectsOutputToContain($scopes)
            ->expectsOutputToContain('No se debe sincronizar')
            ->doesntExpectOutputToContain('solo lectura')
            ->doesntExpectOutputToContain(self::ADS_TOKEN)
            ->assertFailed();
    }

    public function test_sin_token_la_comprobacion_lo_dice(): void
    {
        $this->configureMetaAds(['access_token' => '']);

        $this->artisan('marketing:meta-ads-check')
            ->expectsOutputToContain('META_TOKEN_REQUIRED=YES')
            ->assertFailed();
    }

    // ── FASE 5: relleno de 2026 ─────────────────────────────────────────────

    /** Filas de Meta para el rango pedido: 1.000 COP por día y anuncio, dos anuncios. */
    private function metaPorRango(): void
    {
        $this->fakeMeta(function (Request $r) {
            $rango = json_decode($this->queryOf($r)['time_range'], true);
            $filas = [];
            for ($d = Carbon::parse($rango['since']); $d->toDateString() <= $rango['until']; $d->addDay()) {
                $filas[] = $this->insightRow($d->toDateString(), '9001', '1000.00');
                $filas[] = $this->insightRow($d->toDateString(), '9002', '500.00', ['adset_id' => '120212', 'adset_name' => 'Otro']);
            }

            return Http::response(['data' => $filas]);
        });
    }

    public function test_el_relleno_simula_sin_escribir_y_luego_rellena_por_tramos_reanudable_e_idempotente(): void
    {
        Sleep::fake();
        $this->metaPorRango();

        // Simulación: cuenta y no escribe.
        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-09-20', '--to' => '2026-10-05', '--batch-days' => 7, '--pause' => 0, '--dry-run' => true])
            ->expectsTable(['dato', 'valor'], [
                ['days', 16], ['batches', 3], ['campaigns', 1], ['adsets', 2], ['ads', 2],
                ['insight_rows', 32], ['spend', '24000.00'], ['estimated_requests', 3 + 10 + 3],
            ])
            ->assertSuccessful();
        $this->assertSame(0, MetaAdInsightDaily::count());
        $this->assertSame(0, MetaSyncRun::count());

        // De verdad: tres tramos.
        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-09-20', '--to' => '2026-10-05', '--batch-days' => 7, '--pause' => 0])
            ->expectsOutputToContain('BACKFILL_2026')
            ->assertSuccessful();
        $this->assertSame(32, MetaAdInsightDaily::count());
        $this->assertSame(24000.0, round((float) MetaAdInsightDaily::sum('spend'), 2));
        $this->assertSame(
            [['2026-09-20', '2026-09-26'], ['2026-09-27', '2026-10-03'], ['2026-10-04', '2026-10-05']],
            MetaSyncRun::where('trigger', 'backfill')->orderBy('id')->get()->map(fn ($r) => [$r->range_from, $r->range_to])->all(),
        );

        // Reanudable: lo cubierto se salta.
        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-09-20', '--to' => '2026-10-05', '--batch-days' => 7, '--pause' => 0])
            ->expectsOutputToContain('ya cubierto')
            ->assertSuccessful();
        $this->assertSame(3, MetaSyncRun::where('trigger', 'backfill')->count());

        // Forzado: repite y no duplica.
        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-09-20', '--to' => '2026-10-05', '--batch-days' => 7, '--pause' => 0, '--force' => true])
            ->assertSuccessful();
        $this->assertSame(32, MetaAdInsightDaily::count());
        $this->assertSame(24000.0, round((float) MetaAdInsightDaily::sum('spend'), 2));
    }

    public function test_el_relleno_no_empieza_antes_de_2026(): void
    {
        $this->fakeMeta(['data' => []]);

        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2025-12-31', '--dry-run' => true])
            ->expectsOutputToContain('no puede empezar antes de 2026-01-01')
            ->assertFailed();
        $this->assertSame([], Http::recorded()->all());
    }

    /** El suelo de 2026 no es solo del relleno: ninguna pasada trae días de antes, tampoco la manual. */
    public function test_ninguna_sincronizacion_trae_dias_de_antes_de_2026(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2025-12-15', '9001', '5000')]]);

        $this->artisan('marketing:meta-ads-sync', ['--from' => '2025-12-01', '--to' => '2025-12-31', '--no-reach' => true])
            ->expectsOutputToContain('Meta Ads no se sincroniza antes de 2026-01-01.')
            ->assertFailed();
        $this->artisan('marketing:meta-ads-sync', ['--from' => '2025-12-31', '--to' => '2026-01-05', '--no-reach' => true])
            ->assertFailed();

        $this->assertSame([], Http::recorded()->all());
        $this->assertSame(0, MetaAdInsightDaily::count());
        $this->assertSame(0, MetaSyncRun::count());
    }

    public function test_el_relleno_espera_ante_un_limite_y_se_detiene_ante_un_permiso(): void
    {
        Sleep::fake();
        $llamadas = 0;
        $this->fakeMeta(function () use (&$llamadas) {
            return ++$llamadas === 1
                ? $this->graphError(80000, 'There have been too many calls to this ad-account')
                : Http::response(['data' => [$this->insightRow('2026-10-04', '9001', '1000.00')]]);
        });

        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-10-01', '--to' => '2026-10-05', '--batch-days' => 30, '--pause' => 0])
            ->expectsOutputToContain('reintento en 60 s')
            ->assertSuccessful();
        Sleep::assertSequence([Sleep::for(60)->seconds()]);
        $this->assertSame(['failed', 'ok'], MetaSyncRun::where('trigger', 'backfill')->orderBy('id')->pluck('status')->all());

        // Un permiso denegado no se arregla esperando: se detiene sin reintentar.
        MetaSyncRun::query()->delete();
        $this->fakeMeta(fn () => $this->graphError(200, 'Requires ads_read permission'));
        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-09-01', '--to' => '2026-09-30', '--batch-days' => 30, '--pause' => 0])
            ->expectsOutputToContain('se detiene el relleno (permission)')
            ->assertFailed();
        $this->assertSame(1, MetaSyncRun::where('trigger', 'backfill')->count());
    }

    // ── FASE 4: conciliación ────────────────────────────────────────────────

    private function conciliable(string $metaSpend): void
    {
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-02', '9001', '60000.00', ['impressions' => '1000']),
            $this->insightRow('2026-10-03', '9001', '40000.00', ['impressions' => '3000']),
        ]]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-04'));

        $this->fakeMetaExtras(reach: fn (Request $r) => Http::response(['data' => $this->queryOf($r)['level'] === 'account'
            ? [['spend' => $metaSpend, 'impressions' => '4000', 'reach' => '3000']]
            : [['campaign_id' => '120201', 'campaign_name' => 'Campaña Octubre', 'spend' => $metaSpend, 'impressions' => '4000', 'reach' => '3000']]]));
    }

    public function test_la_conciliacion_cuadra_meta_tablas_y_panel(): void
    {
        $this->conciliable('100000.00');

        $this->artisan('marketing:meta-ads-reconcile', ['--period' => 'custom', '--from' => '2026-10-01', '--to' => '2026-10-04'])
            ->expectsOutputToContain('META_SPEND=100.000,00')
            ->expectsOutputToContain('LOCAL_SPEND=100.000,00')
            ->expectsOutputToContain('DASHBOARD_SPEND=100.000,00')
            ->expectsOutputToContain('SPEND_MATCH=YES')
            ->expectsOutputToContain('IMPRESSIONS_MATCH=YES')
            ->expectsOutputToContain('CAMPAIGNS_MATCH=YES')
            ->assertSuccessful();
    }

    public function test_la_conciliacion_no_aprueba_si_el_gasto_no_concuerda(): void
    {
        $this->conciliable('100002.00');

        $this->artisan('marketing:meta-ads-reconcile', ['--period' => 'custom', '--from' => '2026-10-01', '--to' => '2026-10-04'])
            ->expectsOutputToContain('SPEND_MATCH=NO')
            ->assertFailed();
    }

    /** Dos campañas con el mismo nombre se concilian cada una con la suya: por id, no por nombre. */
    public function test_la_conciliacion_empareja_las_campanas_por_id(): void
    {
        $otra = ['campaign_id' => '120202', 'campaign_name' => 'Total Access', 'adset_id' => '120212'];
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-02', '9001', '300000.00', ['campaign_name' => 'Total Access', 'impressions' => '3000']),
            $this->insightRow('2026-10-02', '9002', '100000.00', $otra + ['impressions' => '1000']),
        ]]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-04'));

        $this->fakeMetaExtras(reach: fn (Request $r) => Http::response(['data' => $this->queryOf($r)['level'] === 'account'
            ? [['spend' => '400000.00', 'impressions' => '4000', 'reach' => '3500']]
            : [
                ['campaign_id' => '120201', 'campaign_name' => 'Total Access', 'spend' => '300000.00', 'impressions' => '3000', 'reach' => '2500'],
                ['campaign_id' => '120202', 'campaign_name' => 'Total Access', 'spend' => '100000.00', 'impressions' => '1000', 'reach' => '1000'],
            ]]));

        $this->artisan('marketing:meta-ads-reconcile', ['--period' => 'custom', '--from' => '2026-10-01', '--to' => '2026-10-04'])
            ->expectsOutputToContain('SPEND_MATCH=YES')
            ->expectsOutputToContain('CAMPAIGNS_MATCH=YES')
            ->assertSuccessful();
    }

    // ── Diagnóstico: de qué cuenta es cada anuncio que trae leads ───────────

    /** Leads reales de pauta con su anuncio de primer toque: anuncio → cuántos. */
    private function leadsDePautaCon(array $anuncios): void
    {
        foreach ($anuncios as $ad => $n) {
            for ($i = 0; $i < $n; $i++) {
                $this->touch($this->lead('2026-10-01 15:00:00'), $this->adReferral((string) $ad), '2026-10-01 15:00:00');
            }
        }
    }

    /**
     * Cada anuncio se pregunta a Meta por id (`GET /{ad_id}`), sin asumir que sea
     * de la cuenta configurada. Con dos cuentas a la vista: MULTI=YES; un anuncio
     * de una cuenta que el token no ve sale «sin acceso». No cambia nada.
     */
    public function test_el_diagnostico_dice_de_que_cuenta_es_cada_anuncio(): void
    {
        Sleep::fake();
        $this->fakeMeta(['data' => []]);
        $this->leadsDePautaCon(['120253617613620387' => 3, '120251095823850062' => 2, '9001' => 1]);
        $this->fakeMetaExtras(nodes: [
            '9001' => ['id' => '9001', 'account_id' => self::ACCOUNT, 'effective_status' => 'PAUSED'],
            '120251095823850062' => ['id' => '120251095823850062', 'account_id' => '555000111', 'effective_status' => 'ACTIVE'],
            // 120253617613620387: de una cuenta que el token no ve (el falso contesta 100/33, como Meta).
        ]);

        $this->artisan('marketing:meta-ads-ad-accounts', ['--since' => '2026-09-01'])
            ->expectsOutputToContain('AD_IDS_CHECKED=3 de 3')
            ->expectsOutputToContain('AD_ACCOUNTS=555000111,'.self::ACCOUNT)
            ->expectsOutputToContain('CONNECTED_ACCOUNT_ADS=1 · leads 1')
            ->expectsOutputToContain('OTHER_ACCOUNT_ADS=1')
            ->expectsOutputToContain('UNREADABLE_ADS=1 · leads 3')
            ->expectsOutputToContain('CONNECTED_ACCOUNT_HAS_LEAD_ADS=YES')
            ->expectsOutputToContain('MULTI_AD_ACCOUNT_REQUIRED=YES')
            ->doesntExpectOutputToContain(self::ADS_TOKEN)
            ->assertSuccessful();

        $rutas = collect(Http::recorded())->map(fn (array $p) => $p[0])->filter(fn (Request $r) => preg_match('#/v99\.0/\d+$#', (string) parse_url($r->url(), PHP_URL_PATH)) === 1);
        $this->assertCount(3, $rutas, 'una petición por anuncio');
        foreach ($rutas as $r) {
            $this->assertSame('account_id,campaign_id,effective_status', $this->queryOf($r)['fields']);
            $this->assertArrayNotHasKey('ids', $this->queryOf($r));
            $this->assertSame(['Bearer '.self::ADS_TOKEN], $r->header('Authorization'));
        }
        $this->assertSame(self::ACCOUNT, config('meta.ads.ad_account_id'), 'el diagnóstico no cambia la configuración');
    }

    /** @return array<string, array{0: array<string,int>, 1: array<string, array<string,string>>, 2: string}> */
    public static function diagnosticos(): array
    {
        return [
            'todo de la cuenta conectada' => [['9001' => 2, '9002' => 1], ['9001' => ['account_id' => self::ACCOUNT], '9002' => ['account_id' => self::ACCOUNT]], 'NO'],
            'una cuenta vista y otro sin acceso' => [['9001' => 2, '9003' => 1], ['9001' => ['account_id' => self::ACCOUNT]], 'UNKNOWN'],
        ];
    }

    #[DataProvider('diagnosticos')]
    public function test_el_diagnostico_no_supone_una_sola_cuenta(array $anuncios, array $nodos, string $multi): void
    {
        Sleep::fake();
        $this->fakeMeta(['data' => []]);
        $this->leadsDePautaCon($anuncios);
        $this->fakeMetaExtras(nodes: $nodos);

        $this->artisan('marketing:meta-ads-ad-accounts', ['--since' => '2026-09-01'])
            ->expectsOutputToContain("MULTI_AD_ACCOUNT_REQUIRED={$multi}")
            ->assertSuccessful();
    }

    /**
     * Todos los anuncios con leads son de UNA cuenta ajena. Si la conectada gastó
     * en la ventana, también está en juego: dos cuentas, MULTI=YES. Si no gastó,
     * una sola cuenta, pero la conectada no tiene ningún anuncio con leads, y el
     * diagnóstico lo dice sin cambiar nada.
     */
    public function test_el_diagnostico_con_una_sola_cuenta_ajena_cuenta_la_conectada(): void
    {
        Sleep::fake();
        $ajena = ['9101' => ['id' => '9101', 'account_id' => '555000111'], '9102' => ['id' => '9102', 'account_id' => '555000111']];

        // La conectada gastó el 20 de septiembre.
        $this->fakeMeta(['data' => [$this->insightRow('2026-09-20', '7777', '5000.00')]]);
        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-09-20'), $this->day('2026-09-20'))['status']);
        $this->leadsDePautaCon(['9101' => 2, '9102' => 1]);
        $this->fakeMetaExtras(nodes: $ajena);

        $this->artisan('marketing:meta-ads-ad-accounts', ['--since' => '2026-09-01'])
            ->expectsOutputToContain('CONNECTED_ACCOUNT_SPEND_SINCE=YES')
            ->expectsOutputToContain('OTHER_ACCOUNT_ADS=2')
            ->expectsOutputToContain('CONNECTED_ACCOUNT_HAS_LEAD_ADS=NO')
            ->expectsOutputToContain('MULTI_AD_ACCOUNT_REQUIRED=YES')
            ->expectsOutputToContain('Nada cambia')
            ->assertSuccessful();

        // Sin gasto de la conectada en la ventana: una sola cuenta, y se dice que la conectada no tiene ninguno.
        $this->artisan('marketing:meta-ads-ad-accounts', ['--since' => '2026-09-21'])
            ->expectsOutputToContain('CONNECTED_ACCOUNT_SPEND_SINCE=NO')
            ->expectsOutputToContain('CONNECTED_ACCOUNT_HAS_LEAD_ADS=NO')
            ->expectsOutputToContain('MULTI_AD_ACCOUNT_REQUIRED=NO')
            ->expectsOutputToContain('Ningún anuncio con leads es de la cuenta conectada')
            ->expectsOutputToContain('Nada cambia')
            ->assertSuccessful();
    }

    /** Con el token inválido (190) se detiene en el primero: las demás fallarían igual. Un 5xx es «error pasajero», no «sin acceso». */
    public function test_el_diagnostico_se_detiene_con_el_token_invalido_y_distingue_lo_pasajero(): void
    {
        Sleep::fake();
        $this->fakeMeta(['data' => []]);
        $this->leadsDePautaCon(['9001' => 2, '9002' => 1]);
        $this->fakeMetaExtras(nodes: fn (Request $r) => $this->graphError(190, 'Error validating access token', 400, ['error_subcode' => 463]));

        $this->artisan('marketing:meta-ads-ad-accounts', ['--since' => '2026-09-01'])
            ->expectsOutputToContain('Meta rechaza el token (190')
            ->expectsOutputToContain('AD_IDS_CHECKED=0 de 2')
            ->expectsOutputToContain('MULTI_AD_ACCOUNT_REQUIRED=UNKNOWN')
            ->doesntExpectOutputToContain(self::ADS_TOKEN)
            ->assertSuccessful();
        $nodos = collect(Http::recorded())->map(fn (array $p) => $p[0])->filter(fn (Request $r) => preg_match('#/v99\.0/\d+$#', (string) parse_url($r->url(), PHP_URL_PATH)) === 1);
        $this->assertCount(1, $nodos, 'una sola petición con el token rechazado');

        $this->fakeMetaExtras(nodes: fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/9001')
            ? $this->graphError(2, 'An unexpected error has occurred', 500, ['is_transient' => true])
            : Http::response(['id' => '9002', 'account_id' => self::ACCOUNT]));
        $this->artisan('marketing:meta-ads-ad-accounts', ['--since' => '2026-09-01'])
            ->expectsOutputToContain('error pasajero')
            ->doesntExpectOutputToContain('sin acceso')
            ->expectsOutputToContain('MULTI_AD_ACCOUNT_REQUIRED=UNKNOWN')
            ->assertSuccessful();
    }

    /** Ante un límite de Meta se detiene, lo dice y no concluye nada. */
    public function test_el_diagnostico_se_detiene_ante_un_limite(): void
    {
        Sleep::fake();
        $this->fakeMeta(['data' => []]);
        $this->leadsDePautaCon(['9001' => 2, '9002' => 1]);
        $this->fakeMetaExtras(nodes: fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/9001')
            ? Http::response(['id' => '9001', 'account_id' => self::ACCOUNT])
            : $this->graphError(17, 'User request limit reached', 400, ['error_subcode' => 2446079]));

        $this->artisan('marketing:meta-ads-ad-accounts', ['--since' => '2026-09-01'])
            ->expectsOutputToContain('límite de peticiones')
            ->expectsOutputToContain('AD_IDS_CHECKED=1 de 2')
            ->expectsOutputToContain('MULTI_AD_ACCOUNT_REQUIRED=UNKNOWN')
            ->assertSuccessful();
    }

    // ── El relleno no es la pasada que mantiene el panel al día ───────────────

    /**
     * Un tramo del relleno trae días de enero: no dice si el panel está al día ni
     * su fallo es el de la pasada que lo mantiene. Lo que sí se dice es que está
     * en curso, porque mientras corre ocupa la sincronización.
     */
    public function test_un_tramo_del_relleno_no_cambia_la_frescura_del_panel(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-02', '120230001', '1000.00')]]);
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->assertSame('ok', $this->sync()->run(MetaSyncRun::TRIGGER_SCHEDULE)['status']);
        $this->fakeMeta(['data' => []]);

        Carbon::setTestNow('2026-10-05 15:00:00');
        $this->assertSame(['stale', 300], array_values(array_intersect_key($this->sync()->status(), array_flip(['status', 'minutes_since_success']))));

        $this->assertSame('ok', $this->sync()->run(MetaSyncRun::TRIGGER_BACKFILL, $this->day('2026-01-01'), $this->day('2026-01-14'))['status']);
        $estado = $this->sync()->status();
        $this->assertSame(['stale', 300, null], [$estado['status'], $estado['minutes_since_success'], $estado['last_error']]);
        $this->assertSame('2026-10-05T10:00:00+00:00', $estado['last_success_at']);
        $this->assertSame('stale', $this->spendFor('2026-09-29', '2026-10-05')['status'], 'el gasto de hoy sigue teniendo cinco horas');

        // Un tramo que falla tampoco pone el panel en «Error».
        $this->fakeMeta(fn () => $this->graphError(17, 'User request limit reached'));
        $this->assertSame('failed', $this->sync()->run(MetaSyncRun::TRIGGER_BACKFILL, $this->day('2026-01-15'), $this->day('2026-01-28'))['status']);
        $estado = $this->sync()->status();
        $this->assertSame(['stale', null], [$estado['status'], $estado['last_error']]);

        // Un tramo en curso sí se dice.
        MetaSyncRun::create([
            'kind' => 'insights', 'status' => 'running', 'trigger' => MetaSyncRun::TRIGGER_BACKFILL, 'ad_account_id' => self::ACCOUNT,
            'range_from' => '2026-01-29', 'range_to' => '2026-02-11', 'started_at' => now(),
        ]);
        $this->assertSame('running', $this->sync()->status()['status']);
    }
}
