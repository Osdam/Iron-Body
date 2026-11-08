<?php

namespace Tests\Feature\Telephony;

use App\Services\Telephony\VoiceRateLimits;
use App\Support\Cache\ExpiredCachePruner;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `voice:prune-rate-limits` (PERF8, hallazgo P8-3; B8-c): borra de la caché
 * `database` SOLO las claves `voice-rl:*` CADUCADAS que dejan los límites de las
 * rutas públicas (cada llamada estrena su cubo y nadie lo vuelve a leer).
 *
 * Las claves se escriben con el limitador REAL (`VoiceRateLimits` sobre el
 * almacén `database`, como en producción). El prefijo del almacén lleva `_` y
 * `%` para probar el escape del LIKE: una clave que solo casaría con los
 * comodines sin escapar se queda. Las vivas, las ajenas y las de un prefijo
 * parecido, también. Sin red.
 */
class VoicePruneRateLimitsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Con `_` y `%`: sin escape, el LIKE los tomaría por comodines. */
    private const STORE_PREFIX = 'iron_body%prueba-';

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->t0 = Carbon::parse('2026-11-09T15:00:00Z');
        $this->travelTo($this->t0);
        $this->useDatabaseCache();
    }

    public function test_borra_solo_las_claves_de_la_voz_caducadas_y_en_seco_solo_las_cuenta(): void
    {
        // Lo que deja el tráfico real: autenticadas por llamada (y el techo global), fallos por IP y los silenciados.
        foreach (['CA'.str_repeat('1', 32), 'CA'.str_repeat('2', 32), 'CA'.str_repeat('3', 32)] as $sid) {
            $this->assertNull(VoiceRateLimits::authenticated(VoiceRateLimits::ROUTE_INITIATION, $sid));
        }
        $this->assertNull(VoiceRateLimits::authenticated(VoiceRateLimits::ROUTE_TOOLS, 'conversation_id:conv_poda_1'));
        // El cubo de fallos lleno y tres silenciados: el primero saca la línea y vacía el contador; los otros dos lo dejan vivo (1 h).
        for ($i = 0; $i < VoiceRateLimits::FAILURES_PER_MINUTE + 3; $i++) {
            VoiceRateLimits::failure(VoiceRateLimits::ROUTE_POST_CALL, '203.0.113.7');
        }
        $deLaVoz = $this->voiceKeys();
        $this->assertGreaterThanOrEqual(14, count($deLaVoz), 'el limitador escribe en la caché database: '.implode(', ', $deLaVoz));
        $silenciados = self::STORE_PREFIX.VoiceRateLimits::PREFIX.'post-call|silenciados';
        $this->assertContains($silenciados, $deLaVoz);

        // Lo que NO es suyo: otra clave de la aplicación (caducada), un prefijo parecido y una que solo casa con los comodines sin escapar.
        $ajenas = [self::STORE_PREFIX.'otro-dominio:clave', self::STORE_PREFIX.'voice-rlx:clave', 'ironXbodyZZZprueba-voice-rl:initiation|global'];
        foreach ($ajenas as $clave) {
            DB::table('cache')->insert(['key' => $clave, 'value' => serialize(1), 'expiration' => $this->t0->getTimestamp() - 1]);
        }

        // Un minuto después: caducan los cubos de 60 s; el contador de silenciados (1 h) sigue vivo, y una llamada nueva estrena el suyo.
        $this->travelTo($this->t0->copy()->addSeconds(61));
        $this->assertNull(VoiceRateLimits::authenticated(VoiceRateLimits::ROUTE_INITIATION, 'CA'.str_repeat('4', 32)));
        $caducadas = $this->expiredVoiceKeys();
        $vivas = array_values(array_diff($this->voiceKeys(), $caducadas));
        $this->assertNotEmpty($caducadas);
        $this->assertContains($silenciados, $vivas);
        $this->assertNotEmpty(array_filter($vivas, static fn (string $k): bool => str_ends_with($k, '|global')), 'el techo global de la nueva, vivo');

        // En seco: las cuenta y no borra nada.
        $antes = $this->allKeys();
        $this->assertSame(0, Artisan::call('voice:prune-rate-limits', ['--dry-run' => true, '--json' => true]));
        $seco = json_decode(Artisan::output(), true);
        $this->assertSame(['store' => 'database', 'driver' => 'database', 'supported' => true, 'dryRun' => true, 'expired' => count($caducadas), 'deleted' => 0, 'batches' => 0], $seco);
        $this->assertSame($antes, $this->allKeys());

        // De verdad: exactamente las caducadas de la voz.
        $this->assertSame(0, Artisan::call('voice:prune-rate-limits', ['--json' => true]));
        $hecho = json_decode(Artisan::output(), true);
        $this->assertSame([count($caducadas), count($caducadas), 1], [$hecho['expired'], $hecho['deleted'], $hecho['batches']]);
        $this->assertSame(array_values(array_diff($antes, $caducadas)), $this->allKeys());
        foreach ([...$ajenas, ...$vivas] as $clave) {
            $this->assertContains($clave, $this->allKeys(), "{$clave} no es una clave caducada de la voz");
        }

        // Y el limitador sigue funcionando sobre lo que queda (el cupo de la llamada nueva no se perdió).
        $this->assertSame(1, app(CacheRateLimiter::class)->attempts(VoiceRateLimits::PREFIX.'initiation|'.substr(hash('sha256', 'CA'.str_repeat('4', 32)), 0, 40)));

        // Sin nada caducado: cero, sin tandas.
        $this->assertSame(0, Artisan::call('voice:prune-rate-limits', ['--json' => true]));
        $this->assertSame([0, 0, 0], array_values(array_intersect_key(json_decode(Artisan::output(), true), array_flip(['expired', 'deleted', 'batches']))));
    }

    public function test_un_atraso_grande_se_borra_en_tandas(): void
    {
        $filas = [];
        for ($i = 0; $i < 2 * ExpiredCachePruner::BATCH + 500; $i++) {
            $filas[] = ['key' => self::STORE_PREFIX.VoiceRateLimits::PREFIX.'initiation|'.substr(hash('sha256', "atraso-{$i}"), 0, 40), 'value' => serialize(1), 'expiration' => $this->t0->getTimestamp() - 3600];
        }
        foreach (array_chunk($filas, 400) as $tanda) {
            DB::table('cache')->insert($tanda);
        }

        $this->assertSame(0, Artisan::call('voice:prune-rate-limits', ['--json' => true]));
        $hecho = json_decode(Artisan::output(), true);
        $this->assertSame([2500, 2500, 3], [$hecho['expired'], $hecho['deleted'], $hecho['batches']]);
        $this->assertSame(0, DB::table('cache')->count());
    }

    public function test_con_otro_almacen_del_limitador_no_hay_nada_que_purgar_y_no_toca_la_tabla(): void
    {
        DB::table('cache')->insert(['key' => self::STORE_PREFIX.VoiceRateLimits::PREFIX.'tools|'.str_repeat('a', 40), 'value' => serialize(1), 'expiration' => $this->t0->getTimestamp() - 60]);
        config(['cache.default' => 'array']);

        $this->assertSame(0, Artisan::call('voice:prune-rate-limits', ['--json' => true]));
        $this->assertSame(['store' => 'array', 'driver' => 'array', 'supported' => false, 'dryRun' => false, 'expired' => 0, 'deleted' => 0, 'batches' => 0], json_decode(Artisan::output(), true));
        $this->assertSame(1, DB::table('cache')->count());
        Http::assertNothingSent();
    }

    public function test_sin_prefijo_no_purga_la_cache_entera(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExpiredCachePruner::prune('');
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** El limitador sobre la caché `database` de la suite, con un prefijo de almacén con comodines de LIKE. */
    private function useDatabaseCache(): void
    {
        config(['cache.default' => 'database', 'cache.prefix' => self::STORE_PREFIX, 'cache.stores.database.connection' => null, 'cache.stores.database.lock_connection' => null]);
        app('cache')->forgetDriver(['database', 'array']);
        app()->forgetInstance(CacheRateLimiter::class);
        Facade::clearResolvedInstances();
        $this->assertSame(self::STORE_PREFIX, Cache::store()->getPrefix());
    }

    /** @return list<string> todas las claves de la tabla, ordenadas */
    private function allKeys(): array
    {
        return DB::table('cache')->orderBy('key')->pluck('key')->all();
    }

    /** @return list<string> las de la voz (el prefijo EXACTO), ordenadas */
    private function voiceKeys(): array
    {
        return array_values(array_filter($this->allKeys(), static fn (string $k): bool => str_starts_with($k, self::STORE_PREFIX.VoiceRateLimits::PREFIX)));
    }

    /** @return list<string> las de la voz ya caducadas para la caché (`expiration <= ahora`) */
    private function expiredVoiceKeys(): array
    {
        $ahora = now()->getTimestamp();

        return array_values(array_filter($this->voiceKeys(), static fn (string $k): bool => (int) DB::table('cache')->where('key', $k)->value('expiration') <= $ahora));
    }
}
