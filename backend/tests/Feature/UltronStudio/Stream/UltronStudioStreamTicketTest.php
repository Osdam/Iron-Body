<?php

namespace Tests\Feature\UltronStudio\Stream;

use App\Models\Admin;
use App\Models\Member;
use App\Models\MemberBiometric;
use App\Models\User;
use App\Services\Admin\AdminSessionService;
use App\Services\Admin\StreamTicketService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * P9-25 (hallazgo R-1 de SEC9-a): el VALE de la URL del canal del Studio y de
 * las fotos biométricas.
 *
 * EL RIESGO. El vale viaja en la URL (`EventSource` y `<img src>` no mandan
 * cabeceras), así que queda en el access.log de nginx. Valía 5 minutos, se
 * podía repetir y el mismo vale abría cualquier stream del CRM y las fotos de
 * `face-image`: quien leyera el log a tiempo entraba en todo eso como esa
 * sesión.
 *
 * LO QUE SE FIJA AQUÍ, por la ruta real:
 *   1. un solo uso: la segunda conexión con el mismo vale es 403;
 *   2. finalidad: el vale de las fotos no abre el canal ni el del canal la
 *      foto, y probarlo en la ruta equivocada no lo gasta;
 *   3. vida de un minuto;
 *   4. dos canjes A LA VEZ del mismo vale, en procesos distintos y con un
 *      almacén compartido de verdad (el `database` de producción y `file`):
 *      gana uno y no queda rastro en la caché;
 *   5. las respuestas que llevan el vale (la emisión) o lo traen en la URL no
 *      se guardan en caché ni lo mandan en `Referer`.
 */
class UltronStudioStreamTicketTest extends StreamTestCase
{
    private const IMAGEN = "\xFF\xD8\xFF\xE0rostro-de-prueba";

    private ?string $carrera = null;

    protected function tearDown(): void
    {
        if ($this->carrera !== null) {
            DB::purge('p925_carrera');
            File::deleteDirectory($this->carrera);
        }
        parent::tearDown();
    }

    private function vale(Admin $quien, string $scope = StreamTicketService::SCOPE_STREAM): string
    {
        return $this->getJson('/api/admin/stream-ticket?scope='.$scope, $this->as($quien))->assertOk()->json('data.ticket');
    }

    private function canal(string $ticket): TestResponse
    {
        return $this->get(self::URL.'?topics=ledger&ticket='.urlencode($ticket));
    }

    /** Un socio con rostro guardado; devuelve la URL de su foto. */
    private function foto(): string
    {
        Storage::fake('local');
        $user = User::factory()->create(['name' => 'Socia Con Rostro']);
        $member = Member::create([
            'user_id' => $user->id,
            'full_name' => 'Socia Con Rostro',
            'document_number' => '1075999888',
            'phone' => '+573009998888',
            'access_hash' => 'tok-p925-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
        Storage::disk('local')->put('members/p925/face.jpg', self::IMAGEN);
        MemberBiometric::create([
            'member_id' => $member->id,
            'face_path' => 'members/p925/face.jpg',
            'face_mime' => 'image/jpeg',
            'captured_at' => now(),
        ]);

        return "/api/attendances/face-image/{$user->id}";
    }

    // ── 1. Un solo uso ──────────────────────────────────────────────────────

    public function test_el_vale_abre_el_canal_una_sola_vez(): void
    {
        $ana = $this->person();
        $ticket = $this->vale($ana);

        $this->assertStringContainsString('event: ready', $this->canal($ticket)->assertOk()->streamedContent());

        // La URL copiada del access.log ya no abre nada.
        $this->canal($ticket)->assertStatus(403)->assertJsonPath('code', 'admin_ticket_invalid');
        $this->get('/api/admin/notifications/stream?ticket='.urlencode($ticket))->assertStatus(403);

        // La pestaña pide otro vale para reconectar y entra.
        $this->canal($this->vale($ana))->assertOk();
    }

    public function test_la_foto_se_abre_una_sola_vez_con_su_vale(): void
    {
        $foto = $this->foto();
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN);
        $ticket = $this->vale($ana, StreamTicketService::SCOPE_FACE_IMAGE);

        $this->assertSame(self::IMAGEN, $this->get($foto.'?ticket='.urlencode($ticket))->assertOk()->streamedContent());
        $this->get($foto.'?ticket='.urlencode($ticket))->assertStatus(403)->assertJsonPath('code', 'admin_ticket_invalid');
    }

    public function test_un_vale_no_sirve_para_pedir_otro_vale(): void
    {
        // Si un vale leído del log pudiera emitir vales nuevos, el solo uso no serviría de nada.
        $ticket = $this->vale($this->person());

        $this->getJson('/api/admin/stream-ticket?ticket='.urlencode($ticket))->assertStatus(401);
        $this->getJson('/api/admin/stream-ticket', ['Authorization' => 'Bearer '.$ticket])->assertStatus(403);

        // Ninguno de los dos intentos lo gastó: abre el canal, una vez.
        $this->canal($ticket)->assertOk();
    }

    // ── 2. Finalidad ────────────────────────────────────────────────────────

    public function test_el_vale_de_la_foto_no_abre_el_canal_ni_el_del_canal_la_foto(): void
    {
        $foto = $this->foto();
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN);
        $deFoto = $this->vale($ana, StreamTicketService::SCOPE_FACE_IMAGE);
        $deCanal = $this->vale($ana, StreamTicketService::SCOPE_STREAM);

        $this->canal($deFoto)->assertStatus(403)->assertJsonPath('code', 'admin_ticket_invalid');
        $this->get($foto.'?ticket='.urlencode($deCanal))->assertStatus(403)->assertJsonPath('code', 'admin_ticket_invalid');

        // Ninguno se gastó en la ruta equivocada: cada uno abre lo suyo.
        $this->canal($deCanal)->assertOk();
        $this->get($foto.'?ticket='.urlencode($deFoto))->assertOk();
    }

    // ── 3. Vida corta ───────────────────────────────────────────────────────

    public function test_el_vale_caducado_no_abre_el_canal(): void
    {
        $ana = $this->person();
        $ticket = $this->vale($ana);

        $this->travel(StreamTicketService::TTL_SECONDS + 1)->seconds();

        $this->canal($ticket)->assertStatus(403)->assertJsonPath('code', 'admin_ticket_invalid');
    }

    public function test_sin_su_anotacion_en_la_cache_el_vale_no_abre(): void
    {
        // Si la caché se pierde, los vales pendientes dejan de valer: falla cerrado.
        $ticket = $this->vale($this->person());
        Cache::flush();

        $this->canal($ticket)->assertStatus(403);
    }

    // ── 4. Dos canjes a la vez ──────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function almacenesCompartidos(): array
    {
        return [
            'database (el de producción), en un SQLite de fichero' => ['database'],
            'file' => ['file'],
        ];
    }

    /**
     * Ocho procesos con el MISMO vale salen a la vez de una barrera y lo
     * canjean contra un almacén que comparten de verdad. Un canje no atómico
     * (leer y luego borrar) deja ganar a varios; aquí gana exactamente uno.
     */
    #[DataProvider('almacenesCompartidos')]
    public function test_dos_canjes_simultaneos_del_mismo_vale_solo_uno_gana(string $driver): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('La carrera necesita pcntl y posix.');
        }

        $dir = $this->almacenCompartido($driver);
        $ana = $this->person();
        $sesion = app(AdminSessionService::class)->issueSession($ana)['session'];
        $ticket = app(StreamTicketService::class)->issue($sesion)['ticket'];
        if ($driver === 'database') {
            // Cada hijo abre su propia conexión al fichero; no se hereda la del padre.
            DB::disconnect('p925_carrera');
        }

        $n = 8;
        $hijos = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'no se pudo crear el proceso hijo');
            if ($pid === 0) {
                $this->canjearEnElHijo($driver, $dir, $i, $ticket);
            }
            $hijos[] = $pid;
        }

        $hasta = microtime(true) + 20;
        while (count(glob("{$dir}/listo-*")) < $n && microtime(true) < $hasta) {
            usleep(1000);
        }
        $this->assertCount($n, glob("{$dir}/listo-*"), 'no todos los hijos llegaron a la barrera');
        touch("{$dir}/ya");
        foreach ($hijos as $pid) {
            pcntl_waitpid($pid, $estado);
        }

        $resultados = array_map(static fn (int $i): string => (string) @file_get_contents("{$dir}/resultado-{$i}"), range(0, $n - 1));
        $this->assertSame(1, count(array_keys($resultados, 'gana', true)), 'tenía que ganar exactamente uno: '.json_encode($resultados));
        $this->assertSame($n - 1, count(array_keys($resultados, 'pierde', true)), 'los demás pierden sin error: '.json_encode($resultados));

        if ($driver === 'database') {
            // Ni la anotación ni el cerrojo quedan en las tablas de la caché.
            DB::reconnect('p925_carrera');
            $this->assertSame(0, DB::connection('p925_carrera')->table('cache')->count(), 'el vale canjeado dejó fila en `cache`');
            $this->assertSame(0, DB::connection('p925_carrera')->table('cache_locks')->count(), 'el cerrojo del canje quedó tomado');
        }
    }

    /** El almacén `p925_carrera`, compartido entre procesos, como almacén por defecto. */
    private function almacenCompartido(string $driver): string
    {
        $dir = $this->carrera = storage_path('framework/testing/p925-'.Str::lower(Str::random(10)));
        File::ensureDirectoryExists($dir);

        if ($driver === 'database') {
            touch("{$dir}/cache.sqlite");
            config(['database.connections.p925_carrera' => [
                'driver' => 'sqlite',
                'database' => "{$dir}/cache.sqlite",
                'prefix' => '',
                'foreign_key_constraints' => false,
                'busy_timeout' => 10000,
                'journal_mode' => null,
                'synchronous' => null,
                'transaction_mode' => 'DEFERRED',
            ]]);
            Schema::connection('p925_carrera')->create('cache', function (Blueprint $t): void {
                $t->string('key')->primary();
                $t->mediumText('value');
                $t->integer('expiration')->index();
            });
            Schema::connection('p925_carrera')->create('cache_locks', function (Blueprint $t): void {
                $t->string('key')->primary();
                $t->string('owner');
                $t->integer('expiration')->index();
            });
            config(['cache.stores.p925_carrera' => [
                'driver' => 'database',
                'connection' => 'p925_carrera',
                'table' => 'cache',
                'lock_connection' => 'p925_carrera',
                'lock_table' => 'cache_locks',
            ]]);
        } else {
            config(['cache.stores.p925_carrera' => ['driver' => 'file', 'path' => "{$dir}/cache", 'lock_path' => "{$dir}/cache"]]);
        }
        config(['cache.default' => 'p925_carrera']);

        return $dir;
    }

    /** El hijo: espera la barrera, canjea, deja su resultado en un fichero y muere sin pasar por el cierre de PHPUnit. */
    private function canjearEnElHijo(string $driver, string $dir, int $i, string $ticket): never
    {
        $resultado = 'sin resultado';
        try {
            if ($driver === 'database') {
                DB::reconnect('p925_carrera');
            }
            touch("{$dir}/listo-{$i}");
            $hasta = microtime(true) + 30;
            while (! file_exists("{$dir}/ya") && microtime(true) < $hasta) {
                clearstatcache(true, "{$dir}/ya");
            }
            $resultado = app(StreamTicketService::class)->redeem($ticket, StreamTicketService::SCOPE_STREAM) !== null ? 'gana' : 'pierde';
        } catch (Throwable $e) {
            $resultado = 'error: '.class_basename($e).': '.$e->getMessage();
        }
        file_put_contents("{$dir}/resultado-{$i}", $resultado);
        posix_kill(posix_getpid(), SIGKILL);
        exit(1);
    }

    // ── 5. Cabeceras ────────────────────────────────────────────────────────

    public function test_la_emision_dice_su_finalidad_y_su_vida_y_no_se_guarda(): void
    {
        $ana = $this->person();

        $r = $this->getJson('/api/admin/stream-ticket', $this->as($ana))->assertOk()
            ->assertJsonPath('data.scope', StreamTicketService::SCOPE_STREAM)
            ->assertJsonPath('data.expires_in', StreamTicketService::TTL_SECONDS);
        $this->assertLessThanOrEqual(60, $r->json('data.expires_in'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $r->headers->get('Referrer-Policy'));

        $this->getJson('/api/admin/stream-ticket?scope=face-image', $this->as($ana))->assertOk()
            ->assertJsonPath('data.scope', StreamTicketService::SCOPE_FACE_IMAGE);
        $this->getJson('/api/admin/stream-ticket?scope=api', $this->as($ana))->assertStatus(422)
            ->assertJsonPath('code', 'ticket_scope_invalid');
        $this->getJson('/api/admin/stream-ticket?scope[]=stream', $this->as($ana))->assertStatus(422);
    }

    public function test_las_respuestas_con_el_vale_en_la_url_no_se_guardan_ni_lo_refieren(): void
    {
        $foto = $this->foto();
        $ana = $this->person(Admin::ROLE_SUPER_ADMIN);

        $conVale = $this->get($foto.'?ticket='.urlencode($this->vale($ana, StreamTicketService::SCOPE_FACE_IMAGE)))->assertOk();
        $this->assertStringContainsString('no-store', (string) $conVale->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age', (string) $conVale->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $conVale->headers->get('Referrer-Policy'));

        $canal = $this->canal($this->vale($ana))->assertOk();
        $this->assertStringContainsString('no-store', (string) $canal->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $canal->headers->get('Referrer-Policy'));

        $rechazo = $this->canal('no-es-un-vale')->assertStatus(403);
        $this->assertStringContainsString('no-store', (string) $rechazo->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $rechazo->headers->get('Referrer-Policy'));

        // Por cabecera (el lector local de la sede) la foto no cambia.
        $porCabecera = $this->get($foto, $this->as($ana))->assertOk();
        $this->assertStringContainsString('max-age=3600', (string) $porCabecera->headers->get('Cache-Control'));
    }
}
