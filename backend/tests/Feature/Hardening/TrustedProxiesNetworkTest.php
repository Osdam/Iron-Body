<?php

namespace Tests\Feature\Hardening;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Hito 7 de ULTRON STUDIO, B7-f: los cupos POR RED del Studio
 * (`ultron-studio-net:<red>` en los cinco limitadores) cuentan la IP real de
 * quien llama, y `X-Forwarded-For` no la cambia.
 *
 * La topología de producción (ULTRON_STUDIO_FINAL_REPORT, «TrustProxies»):
 * `api.ironbodyneiva.cloud` apunta al VPS, nginx habla FastCGI directo con
 * PHP-FPM y no hay CDN ni balanceador delante. `REMOTE_ADDR` ya es el cliente,
 * así que lo correcto es NO confiar en ningún proxy, que es lo que hay: ni
 * `trustProxies()` en bootstrap/app.php ni `config/trustedproxy.php`.
 *
 * Se observa el cubo que gasta de verdad `throttle:ultron-studio-read` (con las
 * claves sin resumir) en una lectura del Studio.
 */
class TrustedProxiesNetworkTest extends TestCase
{
    use RefreshDatabase;

    /** El cliente (TEST-NET-2). */
    private const CLIENTE = '198.51.100.7';

    /** Lo que dice la cabecera falsificada (TEST-NET-3). */
    private const FALSA = '203.0.113.9';

    /** @var array<string, string> */
    private array $sesion;

    protected function setUp(): void
    {
        parent::setUp();
        ThrottleRequests::shouldHashKeys(false);
        $this->sesion = $this->actingAsAdmin(Admin::create([
            'name' => 'Ana', 'email' => 'proxies-'.Str::random(8).'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active',
        ]));
    }

    protected function tearDown(): void
    {
        ThrottleRequests::shouldHashKeys(true);
        TrustProxies::flushState();
        parent::tearDown();
    }

    /** @param  array<string, string>  $cabeceras */
    private function leerDesde(string $remoteAddr, array $cabeceras = []): TestResponse
    {
        return $this->getJson('/api/admin/ultron-studio/status', $this->sesion + $cabeceras + ['REMOTE_ADDR' => $remoteAddr])->assertOk();
    }

    /** Lo que ha gastado una red en el cupo de lectura del Studio. */
    private function gastado(string $red): int
    {
        return RateLimiter::attempts('ultron-studio-read:ultron-studio-net:'.$red);
    }

    public function test_con_la_configuracion_de_produccion_las_cabeceras_de_proxy_no_cambian_la_red(): void
    {
        $falsas = ['X-Forwarded-For' => self::FALSA, 'X-Real-IP' => self::FALSA, 'Forwarded' => 'for='.self::FALSA];

        $this->leerDesde(self::CLIENTE, $falsas);
        $this->assertSame(self::CLIENTE, app('request')->ip());
        $this->assertSame([1, 0], [$this->gastado(self::CLIENTE), $this->gastado(self::FALSA)]);

        // Tampoco desde la propia máquina: hoy no se confía ni en 127.0.0.1.
        $this->leerDesde('127.0.0.1', $falsas);
        $this->assertSame([1, 0], [$this->gastado('127.0.0.1'), $this->gastado(self::FALSA)]);
    }

    /**
     * Si algún día hay un proxy delante y se configura (aquí, 127.0.0.1, como
     * haría `$middleware->trustProxies(at: [...])`), el cupo cuenta la IP del
     * cliente SOLO cuando la petición llega del proxy; desde cualquier otro
     * origen, la cabecera se ignora. De una cadena, cuenta la última dirección
     * que no es de un proxy de confianza: lo que el cliente escriba a la
     * izquierda no le sirve.
     */
    public function test_con_un_proxy_configurado_solo_el_proxy_puede_decir_quien_es_el_cliente(): void
    {
        TrustProxies::at(['127.0.0.1']);

        $this->leerDesde('127.0.0.1', ['X-Forwarded-For' => self::FALSA]);
        $this->assertSame(self::FALSA, app('request')->ip());
        $this->assertSame([1, 0], [$this->gastado(self::FALSA), $this->gastado('127.0.0.1')]);

        $this->leerDesde(self::CLIENTE, ['X-Forwarded-For' => self::FALSA]);
        $this->assertSame(self::CLIENTE, app('request')->ip());
        $this->assertSame([1, 1], [$this->gastado(self::CLIENTE), $this->gastado(self::FALSA)]);

        $this->leerDesde('127.0.0.1', ['X-Forwarded-For' => '10.9.9.9, '.self::FALSA]);
        $this->assertSame(self::FALSA, app('request')->ip());
        $this->assertSame([2, 0], [$this->gastado(self::FALSA), $this->gastado('10.9.9.9')]);
    }

    /**
     * La guarda: hoy no se confía en ningún proxy. Si se pone un CDN o un
     * balanceador delante, se configuran SOLO sus direcciones (nunca `*`, que
     * dejaría a cualquiera elegir su red en los cupos) y se cambia esta prueba.
     */
    public function test_la_aplicacion_no_confia_en_ningun_proxy(): void
    {
        $this->assertNull((new ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies'))->getValue(), 'bootstrap/app.php configura proxies de confianza.');
        $this->assertNull(config('trustedproxy.proxies'), 'config/trustedproxy.php configura proxies de confianza.');
        $this->assertFalse(laravel_cloud(), 'En Laravel Cloud TrustProxies confía en cualquier origen.');
    }
}
