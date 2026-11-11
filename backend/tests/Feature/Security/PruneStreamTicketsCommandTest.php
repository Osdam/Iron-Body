<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsureAdminAuth;
use App\Models\Admin;
use App\Services\Admin\AdminSessionService;
use App\Services\Admin\StreamTicketService;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `admin:prune-stream-tickets` (SC-7 de la revisión de seguridad del cierre):
 * borra de la caché `database` SOLO las claves `admin-stream-ticket:*`
 * CADUCADAS: las anotaciones de los vales que nadie canjeó (un 429 del canal
 * del Studio, un 5xx, una pestaña cerrada), el cubo del limitador de la
 * emisión y la cuenta de fotos. La caché solo borra una fila caducada cuando
 * alguien vuelve a leer su clave, y esas no las vuelve a leer nadie.
 *
 * Lo que deja: las vivas (un vale recién pedido se sigue canjeando después),
 * las ajenas, las de un prefijo parecido y las que solo casarían con los
 * comodines del LIKE sin escapar (el prefijo del almacén lleva `_` y `%`).
 * Sin red.
 */
class PruneStreamTicketsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Con `_` y `%`: sin escape, el LIKE los tomaría por comodines. */
    private const STORE_PREFIX = 'iron_body%prueba-';

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->t0 = Carbon::parse('2026-11-10T15:00:00Z');
        $this->travelTo($this->t0);
        $this->useDatabaseCache();
    }

    private function useDatabaseCache(): void
    {
        config(['cache.default' => 'database', 'cache.prefix' => self::STORE_PREFIX, 'cache.stores.database.connection' => null, 'cache.stores.database.lock_connection' => null]);
        app('cache')->forgetDriver(['database', 'array']);
        app()->forgetInstance(CacheRateLimiter::class);
        Facade::clearResolvedInstances();
        $this->assertSame(self::STORE_PREFIX, Cache::store()->getPrefix());
    }

    /** @return array<string,string> */
    private function pestana(): array
    {
        $admin = Admin::create([
            'name' => 'Purga', 'email' => 'purga-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);

        return ['Authorization' => 'Bearer '.app(AdminSessionService::class)->issueSession($admin)['token']];
    }

    private function vale(array $pestana, string $scope = StreamTicketService::SCOPE_STREAM): string
    {
        return (string) $this->getJson('/api/admin/stream-ticket?scope='.$scope, $pestana)->assertOk()->json('data.ticket');
    }

    /** @return list<string> */
    private function allKeys(): array
    {
        return DB::table('cache')->orderBy('key')->pluck('key')->all();
    }

    /** @return list<string> las del vale (el prefijo EXACTO) */
    private function ticketKeys(): array
    {
        return array_values(array_filter($this->allKeys(), static fn (string $k): bool => str_starts_with($k, self::STORE_PREFIX.StreamTicketService::CACHE_PREFIX)));
    }

    /** @return list<string> las del vale ya caducadas para la caché (`expiration <= ahora`) */
    private function expiredTicketKeys(): array
    {
        $prefijo = self::STORE_PREFIX.StreamTicketService::CACHE_PREFIX;

        return DB::table('cache')->where('expiration', '<=', now()->getTimestamp())->orderBy('key')->pluck('key')
            ->filter(static fn (string $k): bool => str_starts_with($k, $prefijo))->values()->all();
    }

    public function test_borra_solo_lo_caducado_de_los_vales_y_en_seco_solo_lo_cuenta(): void
    {
        // Lo que deja el tráfico: tres vales pedidos (uno se canjea: su anotación ya no está), el cubo del limitador y la cuenta de fotos.
        $pestana = $this->pestana();
        $canjeado = $this->vale($pestana);
        $this->vale($pestana);
        $this->vale($pestana, StreamTicketService::SCOPE_FACE_IMAGE);
        $this->assertNull(EnsureAdminAuth::challenge(Request::create('/api/admin/notifications/stream', 'GET', ['ticket' => $canjeado])));
        $deLosVales = $this->ticketKeys();
        $this->assertContains(self::STORE_PREFIX.StreamTicketService::CACHE_PREFIX.'faces', $deLosVales, 'la cuenta de fotos del cupo');
        $this->assertNotEmpty(array_filter($deLosVales, static fn (string $k): bool => str_contains($k, StreamTicketService::CACHE_PREFIX.'rate:')), 'el cubo del limitador');
        $this->assertCount(2, array_filter($deLosVales, static fn (string $k): bool => preg_match('/'.preg_quote(StreamTicketService::CACHE_PREFIX, '/').'[0-9a-f]{64}$/', $k) === 1), 'dos anotaciones sin canjear');

        // Lo que NO es suyo: otra clave caducada de la aplicación, un prefijo parecido y una que solo casa con los comodines sin escapar.
        $ajenas = [self::STORE_PREFIX.'otro-dominio:clave', self::STORE_PREFIX.'admin-stream-ticketx:clave', 'ironXbodyZZZprueba-admin-stream-ticket:'.str_repeat('a', 64)];
        foreach ($ajenas as $clave) {
            DB::table('cache')->insert(['key' => $clave, 'value' => serialize(1), 'expiration' => $this->t0->getTimestamp() - 1]);
        }

        // Un minuto después: caducan las anotaciones (60 s) y el cubo; la cuenta de fotos (10 min) sigue, y un vale nuevo estrena la suya.
        $this->travelTo($this->t0->copy()->addSeconds(StreamTicketService::TTL_SECONDS + 1));
        $nuevo = $this->vale($this->pestana());
        $caducadas = $this->expiredTicketKeys();
        $vivas = array_values(array_diff($this->ticketKeys(), $caducadas));
        $this->assertNotEmpty($caducadas);
        $this->assertContains(self::STORE_PREFIX.StreamTicketService::CACHE_PREFIX.'faces', $vivas);

        // En seco: las cuenta y no borra nada.
        $antes = $this->allKeys();
        $this->assertSame(0, Artisan::call('admin:prune-stream-tickets', ['--dry-run' => true, '--json' => true]));
        $seco = json_decode(Artisan::output(), true);
        $this->assertSame(['store' => 'database', 'driver' => 'database', 'supported' => true, 'dryRun' => true, 'expired' => count($caducadas), 'deleted' => 0, 'batches' => 0], $seco);
        $this->assertSame($antes, $this->allKeys());

        // De verdad: se van exactamente las caducadas de los vales.
        $this->assertSame(0, Artisan::call('admin:prune-stream-tickets', ['--json' => true]));
        $hecho = json_decode(Artisan::output(), true);
        $this->assertSame(count($caducadas), $hecho['deleted']);
        $this->assertSame([], $this->expiredTicketKeys());
        $this->assertSame($vivas, $this->ticketKeys(), 'las vivas se quedan');
        foreach ($ajenas as $clave) {
            $this->assertContains($clave, $this->allKeys(), "la ajena {$clave} se queda");
        }

        // El vale vivo se sigue canjeando después de la purga, una vez.
        $this->assertNull(EnsureAdminAuth::challenge(Request::create('/api/admin/notifications/stream', 'GET', ['ticket' => $nuevo])));
        $this->assertSame(403, EnsureAdminAuth::challenge(Request::create('/api/admin/notifications/stream', 'GET', ['ticket' => $nuevo]))?->getStatusCode());

        // Sin nada caducado, no borra nada.
        $this->assertSame(0, Artisan::call('admin:prune-stream-tickets'));
        $this->assertStringContainsString('Caducadas   : 0', Artisan::output());
    }

    public function test_si_los_vales_y_el_limitador_van_a_almacenes_distintos_no_purga_y_falla(): void
    {
        $this->vale($this->pestana());
        $this->travelTo($this->t0->copy()->addSeconds(StreamTicketService::TTL_SECONDS + 1));
        $antes = $this->allKeys();

        config(['cache.limiter' => 'array']);
        $this->assertSame(1, Artisan::call('admin:prune-stream-tickets'));
        $this->assertStringContainsString('no se purga nada', Artisan::output());
        $this->assertSame($antes, $this->allKeys());
    }

    public function test_con_otro_almacen_no_hay_nada_que_purgar(): void
    {
        config(['cache.default' => 'array']);
        $this->assertSame(0, Artisan::call('admin:prune-stream-tickets', ['--json' => true]));
        $resumen = json_decode(Artisan::output(), true);
        $this->assertSame(['store' => 'array', 'supported' => false, 'deleted' => 0], ['store' => $resumen['store'], 'supported' => $resumen['supported'], 'deleted' => $resumen['deleted']]);
    }
}
