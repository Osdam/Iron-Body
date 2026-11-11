<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsureAdminAuth;
use App\Models\Admin;
use App\Models\Member;
use App\Models\MemberBiometric;
use App\Models\User;
use App\Services\Admin\AdminSessionService;
use App\Services\Admin\StreamTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * SC-6 (revisión de seguridad del cierre, `informe-sec-cierre.md`): la
 * finalidad del vale la decide la RUTA que atiende la petición, de una lista
 * cerrada ({@see StreamTicketService::ROUTES}), y solo con GET; ya no el camino
 * (acabar en `/stream` o contener `/face-image/`).
 *
 * La sonda del informe: un vale `stream` autenticaba
 * `GET …/marketing/analytics/breakdown/stream` (la ruta
 * `breakdown/{dimension}`) y uno `face-image`,
 * `GET …/receivables/people/face-image/1` (`people/{type}/{id}`) y
 * `GET …/members/face-image/identity/front` (`members/{member}/identity/{side}`,
 * las fotos del documento de identidad). Hoy esos controladores no devolvían
 * datos, pero una ruta futura con un parámetro libre quedaba abierta al vale.
 * En una ruta que no lo admite el vale ni se mira: no se gasta, y su dueño
 * sigue abriendo con él su canal o su foto.
 */
class StreamTicketRouteScopeTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGEN = "\xFF\xD8\xFF\xE0rostro-de-prueba";

    /** Los caminos de la sonda del informe y la finalidad del vale con que se probaron. */
    private const SONDA = [
        ['/api/admin/marketing/analytics/breakdown/stream', StreamTicketService::SCOPE_STREAM],
        ['/api/admin/receivables/people/face-image/1', StreamTicketService::SCOPE_FACE_IMAGE],
        ['/api/admin/members/face-image/identity/front', StreamTicketService::SCOPE_FACE_IMAGE],
    ];

    private ?string $fotoDe = null;

    private function admin(string $role = Admin::ROLE_SUPER_ADMIN): Admin
    {
        return Admin::create([
            'name' => 'Vale '.$role, 'email' => 'sc6-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => $role, 'status' => 'active',
        ]);
    }

    /** Un vale pedido como lo pide el CRM: con la cabecera de su sesión. */
    private function vale(string $scope, ?Admin $admin = null): string
    {
        $token = app(AdminSessionService::class)->issueSession($admin ?? $this->admin())['token'];

        return (string) $this->getJson('/api/admin/stream-ticket?scope='.$scope, ['Authorization' => 'Bearer '.$token])->assertOk()->json('data.ticket');
    }

    /** Una socia con foto biométrica guardada; devuelve el camino de su foto. */
    private function foto(): string
    {
        if ($this->fotoDe !== null) {
            return $this->fotoDe;
        }
        Storage::fake('local');
        $user = User::factory()->create(['name' => 'Socia Con Rostro']);
        $member = Member::create([
            'user_id' => $user->id, 'full_name' => 'Socia Con Rostro', 'document_number' => '1075333444',
            'phone' => '+573003334444', 'access_hash' => 'tok-sc6-'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
        Storage::disk('local')->put('members/sc6/face.jpg', self::IMAGEN);
        MemberBiometric::create(['member_id' => $member->id, 'face_path' => 'members/sc6/face.jpg', 'face_mime' => 'image/jpeg', 'captured_at' => now()]);

        return $this->fotoDe = "/api/attendances/face-image/{$user->id}";
    }

    /** El camino legítimo de cada finalidad (para ver que el vale sigue sin gastar). */
    private function legitimo(string $scope): string
    {
        return $scope === StreamTicketService::SCOPE_FACE_IMAGE ? $this->foto() : '/api/admin/notifications/stream';
    }

    private static function sinRuta(string $path, string $ticket, string $method = 'GET'): Request
    {
        return Request::create($path, $method, ['ticket' => $ticket]);
    }

    private function assertNoAutentica(?Response $r, string $donde): void
    {
        $this->assertNotNull($r, "el vale autenticó {$donde}");
        $this->assertSame(401, $r->getStatusCode(), $donde);
        $this->assertSame('admin_token_required', json_decode((string) $r->getContent(), true)['code'], $donde);
    }

    // ── La sonda del informe ────────────────────────────────────────────────

    public function test_la_guarda_no_acepta_el_vale_en_los_caminos_de_la_sonda_y_no_lo_gasta(): void
    {
        foreach (self::SONDA as [$camino, $finalidad]) {
            $ticket = $this->vale($finalidad);
            $this->assertNull(StreamTicketService::scopeFor(self::sinRuta($camino, $ticket)), $camino);
            $this->assertNoAutentica(EnsureAdminAuth::challenge(self::sinRuta($camino, $ticket)), $camino);

            // No se gastó: su dueño abre con él lo suyo, una vez.
            $this->assertNull(EnsureAdminAuth::challenge(self::sinRuta($this->legitimo($finalidad), $ticket)), "el vale de {$camino} se gastó");
        }
    }

    public function test_por_http_las_rutas_de_la_sonda_no_entregan_nada_con_un_vale(): void
    {
        $stream = $this->vale(StreamTicketService::SCOPE_STREAM);
        $this->get('/api/admin/marketing/analytics/breakdown/stream?ticket='.urlencode($stream))
            ->assertStatus(401)->assertJsonPath('code', 'admin_token_required');
        $this->assertNotContains($this->get('/api/admin/notifications/stream?ticket='.urlencode($stream))->getStatusCode(), [401, 403], 'el vale sin gastar abre su canal');

        $foto = $this->vale(StreamTicketService::SCOPE_FACE_IMAGE);
        $this->get('/api/admin/receivables/people/face-image/1?ticket='.urlencode($foto))
            ->assertStatus(401)->assertJsonPath('code', 'admin_token_required');
        // `members/{member}`: el enlace del modelo corre antes que la puerta (PX-2 del informe) y responde 404;
        // nunca 200, y la guarda, llamada sobre esa ruta, tampoco lo acepta (prueba de arriba).
        $this->assertContains($this->get('/api/admin/members/face-image/identity/front?ticket='.urlencode($foto))->getStatusCode(), [401, 404]);
        $res = $this->get($this->foto().'?ticket='.urlencode($foto))->assertOk();
        $this->assertSame(self::IMAGEN, $res->streamedContent(), 'el vale sin gastar abre su foto');
    }

    // ── Solo GET ────────────────────────────────────────────────────────────

    public function test_un_vale_solo_autentica_un_get_ni_head_ni_post_disfrazado(): void
    {
        foreach ([StreamTicketService::SCOPE_STREAM, StreamTicketService::SCOPE_FACE_IMAGE] as $finalidad) {
            $ticket = $this->vale($finalidad);
            $camino = $this->legitimo($finalidad);

            $this->assertNoAutentica(EnsureAdminAuth::challenge(self::sinRuta($camino, $ticket, 'HEAD')), "HEAD {$camino}");
            $disfrazado = self::sinRuta($camino, $ticket, 'POST');
            $disfrazado->headers->set('X-HTTP-Method-Override', 'GET');
            $this->assertNoAutentica(EnsureAdminAuth::challenge($disfrazado), "POST disfrazado de GET {$camino}");

            $this->assertNull(EnsureAdminAuth::challenge(self::sinRuta($camino, $ticket)), "el GET de {$camino}, con el vale sin gastar");
        }
    }

    public function test_por_http_head_y_el_post_con_metodo_get_no_se_autentican(): void
    {
        $ticket = $this->vale(StreamTicketService::SCOPE_STREAM);

        $this->call('HEAD', '/api/admin/notifications/stream?ticket='.urlencode($ticket))->assertStatus(401);
        // Laravel atiende un POST con `_method=GET` en la ruta GET: el vale mira el método REAL.
        $this->call('POST', '/api/admin/notifications/stream?ticket='.urlencode($ticket), ['_method' => 'GET'])
            ->assertStatus(401)->assertJsonPath('code', 'admin_token_required');

        $this->assertNotContains($this->get('/api/admin/notifications/stream?ticket='.urlencode($ticket))->getStatusCode(), [401, 403]);
    }

    // ── Lo legítimo sigue igual ─────────────────────────────────────────────

    public function test_cada_ruta_de_la_lista_acepta_el_vale_de_su_finalidad_y_no_el_de_la_otra(): void
    {
        foreach (StreamTicketService::ROUTES as $finalidad => $uris) {
            $otra = $finalidad === StreamTicketService::SCOPE_STREAM ? StreamTicketService::SCOPE_FACE_IMAGE : StreamTicketService::SCOPE_STREAM;
            foreach ($uris as $uri) {
                $camino = '/'.str_replace('{userId}', '7', $uri);
                $this->assertSame($finalidad, StreamTicketService::scopeFor(self::sinRuta($camino, 'x')), $uri);

                $deLaOtra = $this->vale($otra);
                $rechazo = EnsureAdminAuth::challenge(self::sinRuta($camino, $deLaOtra));
                $this->assertSame(403, $rechazo?->getStatusCode(), "{$uri} con un vale de {$otra}");
                $this->assertNull(EnsureAdminAuth::challenge(self::sinRuta($this->legitimo($otra), $deLaOtra)), 'probarlo en la ruta equivocada no lo gasta');

                $this->assertNull(EnsureAdminAuth::challenge(self::sinRuta($camino, $this->vale($finalidad))), "{$uri} con su vale");
            }
        }
    }

    public function test_la_lista_cerrada_casa_con_el_enrutador(): void
    {
        /** @var list<Route> $rutas */
        $rutas = array_values(app('router')->getRoutes()->getRoutes());
        $protegida = static fn (Route $r): bool => str_starts_with($r->uri(), 'api/admin/') || in_array('auth.admin', $r->gatherMiddleware(), true);
        $porUri = [];
        foreach ($rutas as $r) {
            if (in_array('GET', $r->methods(), true)) {
                $porUri[$r->uri()] = $r;
            }
        }

        // Cada ruta de la lista existe, es solo GET/HEAD, pasa por la guarda del CRM y es lo que dice ser.
        foreach (StreamTicketService::ROUTES as $finalidad => $uris) {
            $this->assertSame($uris, array_values(array_unique($uris)), "{$finalidad}: sin repetidas");
            foreach ($uris as $uri) {
                $this->assertArrayHasKey($uri, $porUri, "{$uri} no es una ruta GET");
                $r = $porUri[$uri];
                $this->assertSame(['GET', 'HEAD'], $r->methods(), $uri);
                $this->assertTrue($protegida($r), "{$uri} no pasa por EnsureAdminAuth");
                $this->assertSame($finalidad === StreamTicketService::SCOPE_STREAM ? 'stream' : 'faceImage', $r->getActionMethod(), "{$uri}: no es un canal SSE ni la foto");
            }
        }

        // Y al revés: todo canal SSE detrás de la guarda del CRM está en la lista (uno nuevo se clasifica a mano)…
        $canales = [];
        $fotos = [];
        foreach ($porUri as $uri => $r) {
            if (! $protegida($r)) {
                continue;
            }
            if (str_ends_with($uri, '/stream') || $r->getActionMethod() === 'stream') {
                $canales[] = $uri;
            }
            if (str_contains($uri, 'face-image') || $r->getActionMethod() === 'faceImage') {
                $fotos[] = $uri;
            }
        }
        sort($canales);
        $esperados = StreamTicketService::ROUTES[StreamTicketService::SCOPE_STREAM];
        sort($esperados);
        $this->assertSame($esperados, $canales);
        // …y ninguna otra ruta de fotos lo admite.
        $this->assertSame(StreamTicketService::ROUTES[StreamTicketService::SCOPE_FACE_IMAGE], $fotos);
    }
}
