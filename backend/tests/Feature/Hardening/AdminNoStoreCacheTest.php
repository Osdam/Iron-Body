<?php

namespace Tests\Feature\Hardening;

use App\Http\Middleware\NoStoreAdminResponses;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Hito 7 de ULTRON STUDIO, B7-a: `Cache-Control: no-store, private` en TODAS
 * las respuestas de /api/admin/* (pendiente del propietario desde el Hito 3:
 * respondía `no-cache, private`, que deja guardar la respuesta en el disco del
 * navegador de un puesto compartido).
 *
 * Una muestra de rutas del Studio y de fuera, de éxito y de error, más lo que
 * se responde antes del enrutado (404, 405, CORS), las descargas (la de Excel
 * respondía `public`) y un flujo SSE (respondía `no-cache`). Y lo que NO debe
 * tocar: la app móvil, `/up` y los documentos públicos con su propia caché.
 */
class AdminNoStoreCacheTest extends TestCase
{
    use RefreshDatabase;

    private const NO_STORE = 'no-store, private';

    private function persona(string $rol, string $nombre): Admin
    {
        return Admin::create([
            'name' => $nombre,
            'email' => 'nostore-'.Str::random(10).'@ironbody.test',
            'password' => 'secret-password',
            'role' => $rol,
            'status' => 'active',
        ]);
    }

    private function noStore(TestResponse $respuesta, string $que): void
    {
        $this->assertSame(self::NO_STORE, $respuesta->headers->get('Cache-Control'), "{$que} ({$respuesta->getStatusCode()}) no va con no-store.");
    }

    public function test_las_respuestas_de_la_api_administrativa_van_con_no_store(): void
    {
        $root = $this->actingAsAdmin($this->persona(Admin::ROLE_SUPER_ADMIN, 'Root'));
        $ana = $this->actingAsAdmin($this->persona(Admin::ROLE_ADMINISTRADOR, 'Ana'));
        $recepcion = $this->actingAsAdmin($this->persona(Admin::ROLE_RECEPCION, 'Recepción'));
        User::create(['name' => 'Socia Uno', 'email' => 'socia-'.Str::random(6).'@example.com', 'password' => 'secret', 'document' => '10203040', 'phone' => '3001234567', 'status' => 'active']);

        $muestra = [
            // El Studio: la puerta, el libro con nombres del personal y la cadena.
            'Studio: estado' => [$this->getJson('/api/admin/ultron-studio/status', $ana), 200],
            'Studio: libro' => [$this->getJson('/api/admin/ultron-studio/audit', $ana), 200],
            'Studio: publicaciones' => [$this->getJson('/api/admin/ultron-studio/publications', $ana), 200],
            'Studio: 422' => [$this->putJson('/api/admin/ultron-studio/draft', [], $ana), 422],
            'Studio: 404 de una versión' => [$this->getJson('/api/admin/ultron-studio/versions/'.Str::uuid(), $ana), 404],
            // Fuera del Studio.
            'sesión' => [$this->getJson('/api/admin/auth/me', $ana), 200],
            'libro del CRM' => [$this->getJson('/api/admin/audit-logs', $root), 200],
            'login fallido (ruta pública bajo /admin)' => [$this->postJson('/api/admin/auth/login', ['email' => 'nadie@ironbody.test', 'password' => 'mala']), null],
            'sin sesión' => [$this->getJson('/api/admin/auth/me'), 401],
            'sin permiso' => [$this->getJson('/api/admin/users/stream', $recepcion), 403],
            // Antes del enrutado.
            'ruta inexistente' => [$this->getJson('/api/admin/esto-no-existe', $ana), 404],
            'el prefijo solo' => [$this->getJson('/api/admin', $ana), 404],
            'método no admitido' => [$this->deleteJson('/api/admin/ultron-studio/status', [], $ana), 405],
            'verificación previa de CORS' => [$this->call('OPTIONS', '/api/admin/ultron-studio/status', [], [], [], $this->transformHeadersToServerVars([
                'Origin' => 'https://ironbodyneiva.cloud', 'Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'authorization',
            ])), 204],
            // Descargas y flujos.
            'exportación CSV' => [$this->get('/api/admin/exports/members?format=csv&columns[]=name', $root), 200],
            'exportación Excel' => [$this->get('/api/admin/exports/members?format=xlsx&columns[]=name', $root), 200],
            'flujo SSE' => [$this->get('/api/admin/users/stream', $root), 200],
        ];

        foreach ($muestra as $que => [$respuesta, $estado]) {
            if ($estado !== null) {
                $respuesta->assertStatus($estado);
            }
            $this->noStore($respuesta, $que);
        }
        $this->assertStringStartsWith('text/event-stream', (string) $muestra['flujo SSE'][0]->headers->get('Content-Type'));
        $this->assertSame('https://ironbodyneiva.cloud', $muestra['verificación previa de CORS'][0]->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * Lo que había antes, sin el middleware: la descarga de Excel salía
     * `public` (`response()->download()`), el SSE `no-cache` y el JSON
     * `no-cache, private`. Ninguno prohibía guardar la respuesta.
     */
    public function test_sin_el_middleware_las_descargas_salian_public_y_el_resto_no_cache(): void
    {
        $root = $this->actingAsAdmin($this->persona(Admin::ROLE_SUPER_ADMIN, 'Root'));
        $this->withoutMiddleware(NoStoreAdminResponses::class);

        $excel = (string) $this->get('/api/admin/exports/members?format=xlsx&columns[]=name', $root)->assertOk()->headers->get('Cache-Control');
        $sse = (string) $this->get('/api/admin/users/stream', $root)->assertOk()->headers->get('Cache-Control');
        $json = (string) $this->getJson('/api/admin/auth/me', $root)->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('public', $excel);
        $this->assertSame(['no-cache, private', 'no-cache, private'], [$sse, $json]);
        foreach ([$excel, $sse, $json] as $cabecera) {
            $this->assertStringNotContainsString('no-store', $cabecera);
        }
    }

    public function test_fuera_de_la_api_administrativa_no_cambia_nada(): void
    {
        // La app móvil (login de socio), la salud y un documento público con su propia caché corta.
        $socio = $this->postJson('/api/members/login', ['document' => '1', 'password' => 'x']);
        $salud = $this->get('/up')->assertOk();
        $legal = $this->get('/api/legal/privacy')->assertOk();
        // Un prefijo que se le parece no es /api/admin.
        $parecido = $this->getJson('/api/administracion');

        foreach (['socio' => $socio, 'salud' => $salud, 'parecido' => $parecido] as $que => $respuesta) {
            $this->assertSame('no-cache, private', $respuesta->headers->get('Cache-Control'), $que);
        }
        $this->assertStringContainsString('public', (string) $legal->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $legal->headers->get('Cache-Control'));
    }
}
