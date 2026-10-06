<?php

namespace Tests\Feature\Marketing\Attribution;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Services\Marketing\Attribution\LeadIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El índice «10 últimos dígitos → socios y usuarios» se reutiliza entre
 * peticiones del panel durante unos minutos, en vez de recorrer todas las
 * fichas en cada GET. Se mide con el registro de consultas.
 */
class MetaDashboardCacheTest extends TestCase
{
    use RefreshDatabase, SeedsAttribution;

    private const BASE = '/api/admin/marketing/meta-dashboard';

    /** Más de dos lotes de 1.000 en cada tabla: el barrido son 3 consultas por tabla. */
    private const FICHAS = 2500;

    protected function setUp(): void
    {
        parent::setUp();
        // La cobertura histórica tiene sus propias pruebas: aquí todo el año está cubierto.
        config()->set('marketing.attribution.coverage_since', '2026-01-01');
        Carbon::setTestNow('2026-10-05 15:00:00');
        foreach (Admin::ROLES as $rol) {
            AdminRole::firstOrCreate(['name' => $rol], ['is_system' => true]);
        }
        config()->set('marketing.attribution.phone_index_cache_seconds', 300);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Socios y usuarios con teléfono, en lote: el volumen de producción, más o menos. */
    private function seedPeople(int $n): void
    {
        $now = now()->toDateTimeString();
        foreach (array_chunk(range(1, $n), 250) as $chunk) {
            DB::table('users')->insert(array_map(fn (int $i): array => [
                'name' => 'Usuario '.$i,
                'email' => "usuario{$i}@ironbody.test",
                'password' => 'x',
                'phone' => '31'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
            DB::table('members')->insert(array_map(fn (int $i): array => [
                'member_uuid' => (string) Str::uuid(),
                'access_hash' => Str::random(40),
                'full_name' => 'Socio '.$i,
                'document_number' => (string) (2000000000 + $i),
                'phone' => '32'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }

    /**
     * Consultas de una petición, y cuántas son del barrido de teléfonos.
     *
     * @return array{total: int, barrido: int}
     */
    private function measure(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $sweep = array_filter($log, fn (string $sql): bool => preg_match('/from "(members|users)" where "phone" is not null/', $sql) === 1);

        return ['total' => count($log), 'barrido' => count($sweep)];
    }

    public function test_el_indice_de_telefonos_se_reutiliza_entre_peticiones(): void
    {
        $this->seedPeople(self::FICHAS);
        [$user] = $this->customer('3001112233');
        $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);
        $this->lead('2026-10-02 16:00:00', ['phone' => '573200000007']); // un socio sin usuario
        $this->payment($user, 120000, '2026-10-03 15:00:00');
        $h = $this->adminAs(Admin::ROLE_ADMINISTRADOR);
        $get = fn () => $this->getJson(self::BASE.'?period=7d', $h)->assertOk()->assertJsonPath('data.kpis.converted', 1);

        // Sin caché: cada petición recorre socios y usuarios (3 lotes cada una).
        // La primera petición de la prueba carga además sesión y permisos: se
        // compara con la segunda.
        config()->set('marketing.attribution.phone_index_cache_seconds', 0);
        $this->assertSame(6, $this->measure($get)['barrido']);
        $sinCache = $this->measure($get);
        $this->assertSame(6, $sinCache['barrido'], 'sin caché, todas las peticiones barren');

        // Con caché: la primera barre y guarda; las siguientes, no.
        config()->set('marketing.attribution.phone_index_cache_seconds', 300);
        Cache::forget(LeadIdentityResolver::PHONE_INDEX_CACHE_KEY);
        $frio = $this->measure($get);
        $caliente = $this->measure($get);

        $this->assertSame(6, $frio['barrido']);
        $this->assertSame(0, $caliente['barrido']);
        $this->assertSame($sinCache['total'] - 6, $caliente['total']);

        // Las otras lecturas del panel tampoco barren mientras dure.
        $this->assertSame(0, $this->measure(fn () => $this->getJson(self::BASE.'/leads?period=7d', $h)->assertOk())['barrido']);
        $this->assertSame(0, $this->measure(fn () => $this->getJson(self::BASE.'/campaigns?level=ad&period=7d', $h)->assertOk())['barrido']);
    }

    /**
     * La caché es corta y tiene salida: un socio dado de alta después tarda como
     * mucho la vida del índice en enlazarse, y el comando lo renueva al momento.
     */
    public function test_el_indice_caduca_y_el_comando_lo_renueva(): void
    {
        $lead = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);
        $h = $this->adminAs(Admin::ROLE_ADMINISTRADOR);
        $converted = fn (): int => $this->getJson(self::BASE.'?period=7d', $h)->assertOk()->json('data.kpis.converted');

        $this->assertSame(0, $converted());

        // Lo dan de alta en el mostrador y paga: el índice guardado aún no lo tiene.
        [$user] = $this->customer('300 111 2233');
        $this->payment($user, 120000, '2026-10-03 15:00:00');
        $this->assertSame(0, $converted(), 'el índice en caché es de antes del alta');

        // El comando lee de la base y renueva el índice.
        $this->artisan('marketing:resolve-lead-identities')->assertSuccessful();
        $this->assertSame(1, $converted());

        // Y si nadie corre el comando, caduca solo.
        [$otro] = $this->customer('300 444 5566');
        $this->lead('2026-10-02 16:00:00', ['phone' => '573004445566']);
        $this->payment($otro, 90000, '2026-10-03 16:00:00');
        $this->assertSame(1, $converted());
        Carbon::setTestNow(now()->addSeconds(301));
        $this->assertSame(2, $converted());
        $this->assertNotNull($lead->fresh());
    }
}
