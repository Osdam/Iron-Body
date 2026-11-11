<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdminAuth;
use App\Models\Admin;
use App\Models\AdminSession;
use App\Services\Admin\AdminSessionService;
use App\Services\Admin\StreamTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Login real del panel/CRM (email + contraseña): emisión de sesión, `me`,
 * `logout` (revocación), caducidad y anti-enumeración.
 */
class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(array $overrides = []): Admin
    {
        return Admin::create(array_merge([
            'name' => 'Admin Uno',
            'email' => 'admin@ironbody.test',
            'password' => 'super-secret',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ], $overrides));
    }

    public function test_login_correcto_emite_token_y_datos(): void
    {
        $this->makeAdmin();

        $res = $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@ironbody.test',
            'password' => 'super-secret',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.email', 'admin@ironbody.test')
            ->assertJsonPath('user.role', Admin::ROLE_SUPER_ADMIN);

        $this->assertNotEmpty($res->json('token'));
        $this->assertDatabaseCount('admin_sessions', 1);
    }

    public function test_login_password_incorrecta_devuelve_401_generico(): void
    {
        $this->makeAdmin();

        $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@ironbody.test',
            'password' => 'mala',
        ])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_login_email_inexistente_devuelve_mismo_401(): void
    {
        // Anti-enumeración: misma respuesta que password incorrecta.
        $this->postJson('/api/admin/auth/login', [
            'email' => 'noexiste@ironbody.test',
            'password' => 'lo-que-sea',
        ])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_admin_deshabilitado_no_puede_entrar(): void
    {
        $this->makeAdmin(['status' => 'disabled']);

        $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@ironbody.test',
            'password' => 'super-secret',
        ])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_me_devuelve_admin_con_token_de_sesion(): void
    {
        $admin = $this->makeAdmin();

        $this->getJson('/api/admin/auth/me', $this->actingAsAdmin($admin))
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@ironbody.test');
    }

    public function test_logout_revoca_la_sesion(): void
    {
        $admin = $this->makeAdmin();
        $issued = app(AdminSessionService::class)->issueSession($admin);
        $headers = ['Authorization' => 'Bearer '.$issued['token']];

        $this->postJson('/api/admin/auth/logout', [], $headers)->assertOk();

        $this->assertNotNull($issued['session']->fresh()->revoked_at);

        // El token ya no sirve.
        $this->getJson('/api/admin/auth/me', $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'admin_token_invalid');
    }

    public function test_stream_ya_no_acepta_el_token_de_sesion_por_query(): void
    {
        // Esta prueba afirmaba lo CONTRARIO: que `?token=` abría un stream,
        // porque EventSource no puede mandar la cabecera Authorization. El
        // precio era que nginx registraba la línea de petición entera y esos
        // tokens quedaban en claro en access.log y en sus rotaciones; quien
        // pudiera leerlos tenía sesión de administrador.
        //
        // Ahora esas rutas piden un VALE de corta vida (StreamTicketService).
        // Se conserva el caso, invertido, para que quede constancia de que el
        // token por query se retiró a propósito y no por descuido.
        $admin = $this->makeAdmin();
        $issued = app(AdminSessionService::class)->issueSession($admin);

        $request = Request::create('/api/admin/exercises/stream', 'GET', ['token' => $issued['token']]);

        $this->assertNotNull(EnsureAdminAuth::challenge($request), 'el token de sesión no puede abrir un stream por la URL');
    }

    public function test_stream_acepta_un_vale_de_corta_vida(): void
    {
        $admin = $this->makeAdmin();
        $issued = app(AdminSessionService::class)->issueSession($admin);
        $ticket = app(StreamTicketService::class)->issue($issued['session'])['ticket'];

        $request = Request::create('/api/admin/exercises/stream', 'GET', ['ticket' => $ticket]);

        $this->assertNull(EnsureAdminAuth::challenge($request)); // pasa (autenticado)
    }

    // ── P9-25: un solo uso, vida corta y finalidad ─────────────────────────
    //
    // Antes el vale valía 5 minutos, se podía repetir y abría cualquier stream
    // y las fotos biométricas: quien leyera el access.log a tiempo entraba como
    // esa sesión. Cada prueba arma peticiones NUEVAS, como llegarían dos
    // conexiones distintas.

    private function vale(string $scope = StreamTicketService::SCOPE_STREAM): string
    {
        $issued = app(AdminSessionService::class)->issueSession($this->makeAdmin(['email' => 'vale-'.uniqid().'@ironbody.test']));

        return app(StreamTicketService::class)->issue($issued['session'], $scope)['ticket'];
    }

    private function conVale(string $path, string $ticket): Request
    {
        return Request::create($path, 'GET', ['ticket' => $ticket]);
    }

    public function test_el_vale_es_de_un_solo_uso(): void
    {
        $ticket = $this->vale();

        $this->assertNull(EnsureAdminAuth::challenge($this->conVale('/api/admin/exercises/stream', $ticket)));

        // La misma URL leída del access.log, otra vez y en otro stream: ya no abre.
        foreach (['/api/admin/exercises/stream', '/api/admin/earnings/stream'] as $path) {
            $rechazo = EnsureAdminAuth::challenge($this->conVale($path, $ticket));
            $this->assertNotNull($rechazo, "el vale gastado abrió {$path}");
            $this->assertSame(403, $rechazo->getStatusCode());
            $this->assertSame('admin_ticket_invalid', json_decode($rechazo->getContent(), true)['code']);
        }
    }

    public function test_las_guardas_de_una_misma_peticion_canjean_el_vale_una_sola_vez(): void
    {
        // ProtectAdminPaths, EnforceAdminAuthorization y `auth.admin` llaman a
        // la guarda sobre la MISMA petición: la segunda pasada no puede gastar
        // el vale otra vez ni rechazarlo por gastado.
        $ticket = $this->vale();
        $peticion = $this->conVale('/api/admin/memberships/stream', $ticket);

        $this->assertNull(EnsureAdminAuth::challenge($peticion));
        $this->assertNull(EnsureAdminAuth::challenge($peticion));
        $this->assertNull(EnsureAdminAuth::challenge($peticion));

        // Eso no lo hace reutilizable: otra petición con el mismo vale no entra.
        $this->assertSame(403, EnsureAdminAuth::challenge($this->conVale('/api/admin/memberships/stream', $ticket))?->getStatusCode());
    }

    public function test_el_vale_de_stream_no_abre_las_fotos_ni_el_de_las_fotos_un_stream_y_no_se_gasta(): void
    {
        $deStream = $this->vale(StreamTicketService::SCOPE_STREAM);
        $deFoto = $this->vale(StreamTicketService::SCOPE_FACE_IMAGE);

        $this->assertSame(403, EnsureAdminAuth::challenge($this->conVale('/api/attendances/face-image/7', $deStream))?->getStatusCode());
        $this->assertSame(403, EnsureAdminAuth::challenge($this->conVale('/api/admin/exercises/stream', $deFoto))?->getStatusCode());
        $this->assertSame(403, EnsureAdminAuth::challenge($this->conVale('/api/attendances/stream', $deFoto))?->getStatusCode());

        // Probarlo en la ruta equivocada no se lo gasta a quien lo pidió.
        $this->assertNull(EnsureAdminAuth::challenge($this->conVale('/api/admin/exercises/stream', $deStream)));
        $this->assertNull(EnsureAdminAuth::challenge($this->conVale('/api/attendances/face-image/7', $deFoto)));
    }

    public function test_el_vale_vive_un_minuto(): void
    {
        $this->assertLessThanOrEqual(60, StreamTicketService::TTL_SECONDS);

        $aTiempo = $this->vale();
        $tarde = $this->vale();
        $this->travel(StreamTicketService::TTL_SECONDS - 1)->seconds();
        $this->assertNull(EnsureAdminAuth::challenge($this->conVale('/api/admin/exercises/stream', $aTiempo)));

        $this->travel(2)->seconds();
        $this->assertSame(403, EnsureAdminAuth::challenge($this->conVale('/api/admin/exercises/stream', $tarde))?->getStatusCode());
    }

    public function test_un_vale_del_formato_anterior_no_abre_aunque_no_haya_caducado(): void
    {
        // Lo que hoy puede estar en el access.log: {sid, exp} sin finalidad ni jti.
        $issued = app(AdminSessionService::class)->issueSession($this->makeAdmin());
        $anterior = Crypt::encryptString(json_encode(['sid' => $issued['session']->id, 'exp' => now()->addMinutes(5)->getTimestamp()]));

        $this->assertSame(403, EnsureAdminAuth::challenge($this->conVale('/api/admin/exercises/stream', $anterior))?->getStatusCode());
        $this->assertSame(403, EnsureAdminAuth::challenge($this->conVale('/api/attendances/face-image/7', $anterior))?->getStatusCode());
    }

    public function test_token_por_query_no_vale_fuera_de_stream(): void
    {
        // Fuera de /stream el token por query se ignora (no normalizar token-en-URL).
        $admin = $this->makeAdmin();
        $issued = app(AdminSessionService::class)->issueSession($admin);

        $request = Request::create('/api/admin/exercises', 'GET', ['token' => $issued['token']]);
        $response = EnsureAdminAuth::challenge($request);

        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_sesion_caducada_no_autentica(): void
    {
        $admin = $this->makeAdmin();
        $issued = app(AdminSessionService::class)->issueSession($admin);

        // Forzamos caducidad en el pasado.
        AdminSession::query()->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$issued['token']])
            ->assertStatus(403)
            ->assertJsonPath('code', 'admin_token_invalid');
    }
}
