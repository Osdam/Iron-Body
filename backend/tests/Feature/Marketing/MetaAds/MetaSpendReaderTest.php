<?php

namespace Tests\Feature\Marketing\MetaAds;

use App\Models\MetaAdInsightDaily;
use App\Models\MetaSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * El lector del gasto: cuándo una suma vale cero y cuándo significa «no se sabe».
 *
 * La regla que se comprueba en TODAS las lecturas de este fichero: `amount = 0`
 * solo con `real_zero`; con `unknown`, `not_configured` y `sync_failed`, null.
 */
class MetaSpendReaderTest extends TestCase
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

    /** Contrato 17: un cero comprobado frente a un cero que no se sabe. */
    public function test_gasto_cero_real_frente_a_desconocido(): void
    {
        // Nada sincronizado: no se sabe.
        $gasto = $this->leer('2026-10-01', '2026-10-05');
        $this->assertSame('unknown', $gasto['status']);
        $this->assertNull($gasto['amount']);
        $this->assertSame('never_synced', $gasto['reason']);

        // Una pasada con éxito miró esos días y Meta no devolvió nada: cero de verdad.
        $this->fakeMeta(['data' => []]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        $gasto = $this->leer('2026-10-01', '2026-10-05');
        $this->assertSame('real_zero', $gasto['status']);
        $this->assertSame(0.0, $gasto['amount']);
        $this->assertNull($gasto['reason']);
        $this->assertSame('2026-10-05T15:00:00+00:00', $gasto['last_synced_at']);
        $this->assertSame('2026-10-05', $gasto['covered_to']);

        // Días que nadie miró siguen sin saberse, aunque haya éxitos en otros.
        $gasto = $this->leer('2026-09-20', '2026-09-25');
        $this->assertSame('unknown', $gasto['status']);
        $this->assertNull($gasto['amount']);
        $this->assertSame('not_covered', $gasto['reason']);

        // Cubierto solo en parte: no se presenta la suma parcial.
        $gasto = $this->leer('2026-09-28', '2026-10-05');
        $this->assertSame('unknown', $gasto['status']);
        $this->assertNull($gasto['amount']);

        // Meta devuelve filas con gasto 0,00: también es cero de verdad.
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '0.00')]]);
        $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'));
        $gasto = $this->leer('2026-10-05', '2026-10-05');
        $this->assertSame('real_zero', $gasto['status']);
        $this->assertSame(0.0, $gasto['amount']);
        $this->assertSame('COP', $gasto['currency']);
    }

    /** Sin token o sin cuenta: «sin conexión», nunca $0, aunque haya datos de antes. */
    public function test_sin_configuracion_no_es_cero(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '30000')]]);
        $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'));

        $this->configureMetaAds(['access_token' => '']);
        $gasto = $this->leer('2026-10-05', '2026-10-05');
        $this->assertSame('not_configured', $gasto['status']);
        $this->assertNull($gasto['amount']);
        $this->assertSame('missing_access_token', $gasto['reason']);

        $this->configureMetaAds(['ad_account_id' => '']);
        $gasto = $this->leer('2026-10-05', '2026-10-05');
        $this->assertSame('not_configured', $gasto['status']);
        $this->assertSame('missing_ad_account_id', $gasto['reason']);

        $this->assertTrue($this->reader()->byLevel('campaign', ...$this->bogotaDays('2026-10-05', '2026-10-05'))->isEmpty());
    }

    /**
     * Contrato 3, del lado del lector: un fallo deja la última foto, y al envejecer
     * se dice. Los días que el éxito vio enteros siguen con su cifra, marcada como
     * vieja; el día en que corrió (hoy, a las 10:00) solo lo vio en parte, así que,
     * vieja la foto, ya no se da por completo.
     */
    public function test_un_fallo_conserva_la_ultima_foto_y_al_envejecer_se_marca(): void
    {
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-04', '9001', '20000'),
            $this->insightRow('2026-10-05', '9001', '30000'),
        ]]);
        $this->sync()->run('manual', $this->day('2026-10-04'), $this->day('2026-10-05'));

        $this->fakeMeta(fn () => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503));
        $this->assertSame('failed', $this->sync()->run('schedule')['status']);

        // Recién fallado, la foto es reciente: hoy vale lo que valía, también entero.
        $gasto = $this->leer('2026-10-05', '2026-10-05');
        $this->assertSame(['ok', 30000.0, true], [$gasto['status'], $gasto['amount'], $gasto['complete']]);

        $gasto = $this->leer('2026-09-29', '2026-10-05');
        $this->assertSame('sync_failed', $gasto['status']);
        $this->assertNull($gasto['amount']);
        $this->assertSame('transient', $gasto['reason']);

        // Tres horas y un minuto sin un éxito.
        Carbon::setTestNow(now()->addMinutes(181));

        // El día que el éxito vio entero: la cifra sigue, marcada como vieja.
        $gasto = $this->leer('2026-10-04', '2026-10-04');
        $this->assertSame(['stale', 20000.0, 'stale', true], [$gasto['status'], $gasto['amount'], $gasto['reason'], $gasto['complete']]);

        // Hoy solo se vio hasta las 10:00: sin cifra, con el error. Nunca un importe completo.
        $gasto = $this->leer('2026-10-05', '2026-10-05');
        $this->assertSame(['sync_failed', null, 'transient'], [$gasto['status'], $gasto['amount'], $gasto['reason']]);

        // Los dos días: lo visto entero, como parcial y con su fecha.
        $gasto = $this->leer('2026-10-04', '2026-10-05');
        $this->assertSame(['sync_failed', 20000.0, false, '2026-10-04'], [$gasto['status'], $gasto['amount'], $gasto['complete'], $gasto['covered_to']]);
    }

    /**
     * Un cero comprobado que envejece sigue siendo cero comprobado, con el aviso en
     * `reason`, en los días que el éxito vio enteros. El día en que corrió solo lo
     * vio en parte: vieja la foto, ese día no es un cero comprobado.
     */
    public function test_un_cero_viejo_sigue_siendo_real_zero(): void
    {
        $this->fakeMeta(['data' => []]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        Carbon::setTestNow(now()->addMinutes(181));

        $gasto = $this->leer('2026-10-01', '2026-10-04');
        $this->assertSame('real_zero', $gasto['status']);
        $this->assertSame(0.0, $gasto['amount']);
        $this->assertSame('stale', $gasto['reason']);

        $gasto = $this->leer('2026-10-01', '2026-10-05');
        $this->assertSame(['stale', 0.0, false, '2026-10-04', 'partial'], [$gasto['status'], $gasto['amount'], $gasto['complete'], $gasto['covered_to'], $gasto['reason']]);

        $gasto = $this->leer('2026-10-05', '2026-10-05');
        $this->assertSame(['unknown', null, 'not_covered'], [$gasto['status'], $gasto['amount'], $gasto['reason']]);
    }

    /**
     * Contrato 19: el borde de las 19:00 a las 23:59 de Bogotá.
     *
     * A las 21:00 del día 5 en Bogotá ya son las 02:00 del 6 en UTC. Meta pone ese
     * gasto en el día 5 de la cuenta, y el día 5 del panel (de medianoche a
     * medianoche de Bogotá, en UTC de 05:00 a 05:00) tiene que incluirlo, sin
     * arrastrar nada del 4 ni del 6.
     */
    public function test_el_borde_de_las_19_a_las_24_de_bogota_cae_en_su_dia(): void
    {
        // 22:30 del 5 en Bogotá; ya es el 6 en UTC.
        Carbon::setTestNow('2026-10-06 03:30:00');

        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-05', '9001', '50000'),
            $this->insightRow('2026-10-04', '9001', '20000'),
        ]]);
        $this->assertSame('ok', $this->sync()->run('schedule')['status']);

        [$desde, $hasta] = $this->bogotaDays('2026-10-05', '2026-10-05');
        $this->assertSame('2026-10-05 05:00:00', $desde->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-06 05:00:00', $hasta->format('Y-m-d H:i:s'));

        $hoy = $this->reader()->snapshot($desde, $hasta);
        $this->revisar($hoy);
        $this->assertSame('ok', $hoy['status']);
        $this->assertSame(50000.0, $hoy['amount']);

        // El mismo día pedido con fin inclusivo o «hasta ahora» da lo mismo.
        $bogota = CarbonImmutable::parse('2026-10-05', 'America/Bogota');
        $this->assertSame(50000.0, $this->reader()->snapshot($bogota->startOfDay(), $bogota->endOfDay())['amount']);
        $this->assertSame(50000.0, $this->reader()->snapshot($desde, CarbonImmutable::now())['amount']);

        // El día anterior, solo lo suyo; los dos juntos, la suma.
        $this->assertSame(20000.0, $this->leer('2026-10-04', '2026-10-04')['amount']);
        $this->assertSame(70000.0, $this->leer('2026-10-04', '2026-10-05')['amount']);
    }

    /** Un periodo que llega a fin de mes se cubre hasta hoy; uno entero en el futuro no se sabe. */
    public function test_los_dias_futuros_no_existen_para_meta(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-03', '9001', '1000')]]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        // «Este mes»: del 1 de octubre al 1 de noviembre (exclusivo).
        $gasto = $this->reader()->snapshot(
            CarbonImmutable::parse('2026-10-01', 'America/Bogota')->utc(),
            CarbonImmutable::parse('2026-11-01', 'America/Bogota')->utc(),
        );
        $this->revisar($gasto);
        $this->assertSame('ok', $gasto['status']);
        $this->assertSame(1000.0, $gasto['amount']);

        $gasto = $this->leer('2026-10-10', '2026-10-12');
        $this->assertSame('unknown', $gasto['status']);
        $this->assertNull($gasto['amount']);
        $this->assertSame('future_period', $gasto['reason']);
    }

    /** Dos monedas en el mismo periodo no se suman: no se sabe. */
    public function test_dos_monedas_no_se_suman(): void
    {
        MetaAdInsightDaily::create(['ad_account_id' => self::ACCOUNT, 'date' => '2026-10-04', 'ad_id' => '1', 'spend' => '100', 'currency' => 'COP']);
        MetaAdInsightDaily::create(['ad_account_id' => self::ACCOUNT, 'date' => '2026-10-05', 'ad_id' => '1', 'spend' => '5', 'currency' => 'USD']);
        MetaSyncRun::create([
            'kind' => 'insights', 'status' => 'ok', 'trigger' => 'manual', 'ad_account_id' => self::ACCOUNT,
            'range_from' => '2026-10-01', 'range_to' => '2026-10-05', 'started_at' => now(), 'finished_at' => now(),
        ]);

        $gasto = $this->leer('2026-10-04', '2026-10-05');
        $this->assertSame('unknown', $gasto['status']);
        $this->assertNull($gasto['amount']);
        $this->assertSame('mixed_currency', $gasto['reason']);

        $this->assertSame(100.0, $this->leer('2026-10-04', '2026-10-04')['amount']);
    }

    /** Por nivel: sin cobertura completa las cifras van en null, no una suma parcial. */
    public function test_por_nivel_sin_cobertura_no_presenta_sumas_parciales(): void
    {
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-05', '9001', '1000'),
            $this->insightRow('2026-10-05', '9002', '500'),
        ]]);
        $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'));

        $cubierto = $this->reader()->byLevel('campaign', ...$this->bogotaDays('2026-10-05', '2026-10-05'));
        $this->assertSame(1500.0, $cubierto->sole()['spend']);
        $this->assertSame(2000, $cubierto->sole()['impressions']);
        $this->assertSame('Campaña Octubre', $cubierto->sole()['name']);

        $parcial = $this->reader()->byLevel('campaign', ...$this->bogotaDays('2026-10-01', '2026-10-05'));
        $this->assertSame('120201', $parcial->sole()['id']);
        $this->assertNull($parcial->sole()['spend']);
        $this->assertNull($parcial->sole()['impressions']);
        $this->assertNull($parcial->sole()['clicks']);

        $this->expectException(InvalidArgumentException::class);
        $this->reader()->byLevel('creative', ...$this->bogotaDays('2026-10-05', '2026-10-05'));
    }

    /**
     * Por nivel, con las entidades que pide quien llama (las de los leads): una
     * sin ninguna fila de gasto en la cuenta configurada (aquí, de la cuenta
     * anterior) sale con gasto null, no con un 0 «comprobado»; una de esta
     * cuenta que no gastó en el periodo cubierto, con 0.
     */
    public function test_por_nivel_una_entidad_sin_gasto_en_la_cuenta_actual_no_es_cero(): void
    {
        // La cuenta anterior gastó 50.000 en «Campaña Octubre».
        $this->configureMetaAds(['ad_account_id' => '1111111111']);
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '50000')]]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        // La cuenta nueva: «Campaña B» gasta el día 5; «Campaña C», solo el día 1.
        $this->configureMetaAds(['ad_account_id' => '2222222222']);
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-05', '9002', '30000', ['campaign_id' => '120202', 'campaign_name' => 'Campaña B']),
            $this->insightRow('2026-10-01', '9003', '8000', ['campaign_id' => '120203', 'campaign_name' => 'Campaña C']),
        ]]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        [$desde, $hasta] = $this->bogotaDays('2026-10-05', '2026-10-05');

        // Sin nada que incluir: solo lo que gastó la cuenta configurada en el periodo.
        $this->assertSame(['120202'], $this->reader()->byLevel('campaign', $desde, $hasta)->pluck('id')->all());

        $filas = $this->reader()->byLevel('campaign', $desde, $hasta, ['120201', '120202', '120203', '120299', '120202', '']);
        $this->assertSame(['120202', '120203', '120201', '120299'], $filas->pluck('id')->all());
        [$conGasto, $ceroComprobado, $deOtraCuenta, $desconocida] = $filas->all();

        $this->assertSame([30000.0, '2026-10-05'], [$conGasto['spend'], $conGasto['last_day']]);
        // Todas sus métricas de Meta son 0 comprobado; el alcance no, porque no
        // hay foto de Meta de este rango (no se suma por días).
        $this->assertSame(
            ['id' => '120203', 'name' => 'Campaña C', 'campaign_id' => '120203', 'adset_id' => null,
                'spend' => 0.0, 'currency' => 'COP', 'impressions' => 0, 'clicks' => 0, 'link_clicks' => 0,
                'messaging_started' => 0, 'messaging_replied' => 0, 'landing_page_views' => 0,
                'reach' => null, 'frequency' => null, 'status' => null, 'partial' => false, 'last_day' => null],
            $ceroComprobado,
        );
        // Ni su gasto ni su nombre salen de la otra cuenta.
        $this->assertSame(
            ['id' => '120201', 'name' => null, 'campaign_id' => '120201', 'adset_id' => null,
                'spend' => null, 'currency' => null, 'impressions' => null, 'clicks' => null, 'link_clicks' => null,
                'messaging_started' => null, 'messaging_replied' => null, 'landing_page_views' => null,
                'reach' => null, 'frequency' => null, 'status' => null, 'partial' => false, 'last_day' => null],
            $deOtraCuenta,
        );
        $this->assertNull($desconocida['spend']);

        // Lo mismo por anuncio, con la jerarquía de la cuenta configurada.
        $anuncios = $this->reader()->byLevel('ad', $desde, $hasta, ['9001', '9003'])->keyBy('id');
        $this->assertNull($anuncios['9001']['spend']);
        $this->assertSame([0.0, '120203', '120211'], [$anuncios['9003']['spend'], $anuncios['9003']['campaign_id'], $anuncios['9003']['adset_id']]);

        // Sin una pasada que cubra el periodo, tampoco lo de esta cuenta es cero.
        [$desde, $hasta] = $this->bogotaDays('2026-09-20', '2026-09-20');
        $this->assertNull($this->reader()->byLevel('campaign', $desde, $hasta, ['120203'])->sole()['spend']);
    }

    /**
     * Si la cuenta parte los días en otra zona que el CRM, un día de Bogotá toca
     * dos días de la cuenta, y sin el desglose por hora no se pueden repartir: el
     * gasto no se sabe, en vez de sumar los dos días enteros.
     */
    public function test_si_la_cuenta_parte_los_dias_en_otra_zona_el_gasto_no_se_sabe(): void
    {
        $this->configureMetaAds(['timezone' => 'America/Los_Angeles']);
        $angeles = fn (string $dia): CarbonImmutable => CarbonImmutable::parse($dia, 'America/Los_Angeles');

        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-01', '9001', '30000'),
            $this->insightRow('2026-10-02', '9001', '70000'),
        ]]);
        $this->assertSame('ok', $this->sync()->run('manual', $angeles('2026-10-01'), $angeles('2026-10-05'))['status']);

        // El 2 de octubre de Bogotá va de las 22:00 del 1 a las 22:00 del 2 en Los Ángeles.
        foreach ([['2026-10-02', '2026-10-02'], ['2026-10-01', '2026-10-05']] as [$desde, $hasta]) {
            $gasto = $this->leer($desde, $hasta);
            $this->assertSame('unknown', $gasto['status']);
            $this->assertNull($gasto['amount']);
            $this->assertSame('timezone_mismatch', $gasto['reason']);
        }

        // Por nivel, las filas salen sin cifras.
        $fila = $this->reader()->byLevel('campaign', ...$this->bogotaDays('2026-10-02', '2026-10-02'))->sole();
        $this->assertSame(['120201', null, null, null], [$fila['id'], $fila['spend'], $fila['impressions'], $fila['clicks']]);

        // Lo que falla es la diferencia, no la zona: con el CRM en la misma, la cifra vuelve.
        config()->set('caja.timezone', 'America/Los_Angeles');
        $gasto = $this->reader()->snapshot($angeles('2026-10-02'), $angeles('2026-10-03'));
        $this->revisar($gasto);
        $this->assertSame(['ok', 70000.0], [$gasto['status'], $gasto['amount']]);

        // El nombre de la zona se compara sin distinguir mayúsculas.
        config()->set('caja.timezone', 'America/Bogota');
        $this->configureMetaAds(['timezone' => 'america/bogota']);
        $this->assertSame(70000.0, $this->leer('2026-10-02', '2026-10-02')['amount']);
    }

    /** Lee el gasto de días de Bogotá y comprueba la regla del cero en cada lectura. */
    private function leer(string $desde, string $hasta): array
    {
        $gasto = $this->spendFor($desde, $hasta);
        $this->revisar($gasto);

        return $gasto;
    }

    private function revisar(array $gasto): void
    {
        $this->assertSame(
            ['status', 'amount', 'currency', 'last_synced_at', 'covered_to', 'reason', 'complete'],
            array_keys($gasto),
        );

        // Un 0 solo es real_zero, o la parte comprobada de un periodo que se dice incompleto.
        if ($gasto['amount'] !== null && (float) $gasto['amount'] === 0.0 && $gasto['complete']) {
            $this->assertSame('real_zero', $gasto['status'], 'Un 0 que no es real_zero.');
        }

        if (in_array($gasto['status'], ['unknown', 'not_configured'], true)) {
            $this->assertNull($gasto['amount'], "Con {$gasto['status']} el importe tiene que ser null.");
        }

        // Tras un fallo solo sale el último dato bueno, y dicho como parcial y hasta qué día.
        if ($gasto['status'] === 'sync_failed' && $gasto['amount'] !== null) {
            $this->assertFalse($gasto['complete'], 'Un sync_failed con importe tiene que ser parcial.');
            $this->assertNotNull($gasto['covered_to']);
        }

        if (! $gasto['complete']) {
            $this->assertNotNull($gasto['amount'], 'Solo un importe puede ser parcial.');
            $this->assertNotNull($gasto['covered_to'], 'Un importe parcial dice hasta qué día llega.');
        }
    }
}
