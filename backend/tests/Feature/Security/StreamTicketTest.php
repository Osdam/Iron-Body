<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Services\Admin\AdminSessionService;
use App\Services\Admin\StreamTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Vales para abrir los streams SSE.
 *
 * EL FALLO QUE SE CIERRA
 * ----------------------
 * `EventSource` no puede mandar la cabecera Authorization, así que el CRM ponía
 * el token de sesión del administrador en la query string. Nginx registra la
 * línea de petición entera: quedaban en claro en access.log y en sus 13
 * rotaciones —812 peticiones y 3 tokens distintos en un solo día—. Quien
 * pudiera leer esos ficheros, o una copia de ellos, tenía sesión de admin.
 *
 * Lo que estas pruebas fijan, por orden de importancia:
 *   1. que el token de sesión YA NO abra un stream por la URL;
 *   2. que el vale no sirva para nada más que los streams;
 *   3. que caduque y no se pueda fabricar.
 */
class StreamTicketTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test', 'email' => 'st-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
    }

    private function sessionToken(Admin $admin): string
    {
        return app(AdminSessionService::class)->issueSession($admin)['token'];
    }

    private function ticketFor(Admin $admin): string
    {
        $res = $this->getJson('/api/admin/stream-ticket', [
            'Authorization' => 'Bearer '.$this->sessionToken($admin),
        ])->assertOk();

        return $res->json('data.ticket');
    }

    // ── La garantía que más importa ───────────────────────────────────────

    public function test_el_token_de_sesion_ya_no_abre_un_stream_por_la_url(): void
    {
        // Es EL punto del cambio: aunque un token viejo siga en un access.log,
        // ya no sirve para conectarse.
        $token = $this->sessionToken($this->admin());

        $this->get('/api/admin/notifications/stream?token='.$token)
            ->assertStatus(401);
    }

    public function test_con_vale_si_se_abre(): void
    {
        $ticket = $this->ticketFor($this->admin());

        $res = $this->get('/api/admin/notifications/stream?ticket='.urlencode($ticket));

        $this->assertNotSame(401, $res->getStatusCode(), 'el vale tiene que autenticar');
        $this->assertNotSame(403, $res->getStatusCode());
    }

    // ── El vale no es un token ────────────────────────────────────────────

    public function test_el_vale_no_sirve_para_la_api(): void
    {
        // Si abriera la API sería otro token con otro nombre.
        $ticket = $this->ticketFor($this->admin());

        $this->getJson('/api/admin/products', ['Authorization' => 'Bearer '.$ticket])
            ->assertStatus(403);
    }

    public function test_el_vale_no_abre_rutas_que_no_sean_stream(): void
    {
        $ticket = $this->ticketFor($this->admin());

        $this->getJson('/api/admin/products?ticket='.urlencode($ticket))
            ->assertStatus(401);
    }

    // ── No se puede fabricar ni estirar ───────────────────────────────────

    public function test_un_vale_caducado_no_vale(): void
    {
        $admin = $this->admin();
        $session = app(AdminSessionService::class)->issueSession($admin);
        $caducado = Crypt::encryptString(json_encode([
            'sid' => \App\Models\AdminSession::query()->latest('id')->first()->id,
            'exp' => now()->subMinute()->getTimestamp(),
        ]));

        $this->get('/api/admin/notifications/stream?ticket='.urlencode($caducado))
            ->assertStatus(403);
        $this->assertNotEmpty($session['token']);
    }

    public function test_un_vale_inventado_no_vale(): void
    {
        $this->get('/api/admin/notifications/stream?ticket='.urlencode('no-soy-un-vale'))
            ->assertStatus(403);
    }

    public function test_no_se_puede_alterar_la_caducidad(): void
    {
        // `Crypt` autentica además de cifrar: tocar un byte lo invalida.
        $ticket = $this->ticketFor($this->admin());
        $manipulado = substr($ticket, 0, -4).'AAAA';

        $this->get('/api/admin/notifications/stream?ticket='.urlencode($manipulado))
            ->assertStatus(403);
    }

    // ── Emisión ───────────────────────────────────────────────────────────

    public function test_hace_falta_sesion_para_pedir_un_vale(): void
    {
        $this->getJson('/api/admin/stream-ticket')->assertStatus(401);
    }

    public function test_el_vale_no_contiene_el_token_en_claro(): void
    {
        $admin = $this->admin();
        $token = $this->sessionToken($admin);
        $ticket = $this->ticketFor($admin);

        $this->assertStringNotContainsString($token, $ticket);
    }

    public function test_el_vale_caduca_pronto(): void
    {
        $res = $this->getJson('/api/admin/stream-ticket', [
            'Authorization' => 'Bearer '.$this->sessionToken($this->admin()),
        ])->assertOk();

        $this->assertLessThanOrEqual(600, $res->json('data.expires_in'),
            'un vale de larga vida volvería a ser una credencial en la URL');
    }

    public function test_revocar_la_sesion_invalida_su_vale(): void
    {
        $admin = $this->admin();
        $ticket = $this->ticketFor($admin);

        \App\Models\AdminSession::query()->latest('id')->first()
            ->forceFill(['revoked_at' => now(), 'revoked_reason' => 'logout'])->save();

        $this->get('/api/admin/notifications/stream?ticket='.urlencode($ticket))
            ->assertStatus(403);
    }
}
