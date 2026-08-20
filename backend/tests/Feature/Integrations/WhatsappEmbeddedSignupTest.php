<?php

namespace Tests\Feature\Integrations;

use App\Models\Admin;
use App\Models\WhatsappBusinessIntegration;
use App\Services\Meta\WhatsappIntegrationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Conexión de WhatsApp Business desde el CRM (Embedded Signup de Meta).
 *
 * Lo que estas pruebas protegen, por orden de gravedad si se rompiera:
 *
 *  1. Que ni el App Secret ni el token de acceso salgan nunca en una respuesta.
 *  2. Que conectar NO encienda el envío: `META_ENABLED` sigue mandando.
 *  3. Que un canje fallido no tumbe una conexión que estaba funcionando.
 *  4. Que sin conexión guardada todo siga leyendo el `.env`, byte a byte igual
 *     que antes de que existiera esta funcionalidad.
 */
class WhatsappEmbeddedSignupTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/integrations/whatsapp';

    private const APP_SECRET = 'SECRETO-DE-LA-APP-QUE-JAMAS-DEBE-SALIR';

    private Admin $superAdmin;

    private array $saHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        config()->set('meta.app_id', '906146885861728');
        config()->set('meta.app_secret', self::APP_SECRET);
        config()->set('meta.embedded_signup.config_id', '1234567890');
        config()->set('meta.graph_version', 'v21.0');
        config()->set('meta.graph_base', 'https://graph.facebook.com');

        Http::preventStrayRequests();

        $this->superAdmin = Admin::create([
            'name' => 'Super QA', 'email' => 'super-wa@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->saHeaders = $this->actingAsAdmin($this->superAdmin);
    }

    /** El registro memoriza por petición; en pruebas hay que soltarlo a mano. */
    private function forgetRegistry(): void
    {
        app(WhatsappIntegrationRegistry::class)->forget();
    }

    /**
     * Borrón y cuenta nueva de los dobles HTTP.
     *
     * `Http::fake()` ACUMULA: un segundo fake no sustituye al primero, y el
     * stub registrado antes sigue ganando. Para probar «primero funciona y
     * luego Meta rechaza» hay que cambiar la fábrica entera.
     */
    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    /** Respuestas de Graph para un onboarding que sale bien. */
    private function fakeHappyGraph(): void
    {
        Http::fake([
            'graph.facebook.com/v21.0/oauth/access_token*' => Http::response([
                'access_token' => 'EAA-TOKEN-DE-NEGOCIO', 'token_type' => 'bearer',
            ]),
            'graph.facebook.com/v21.0/debug_token*' => Http::response([
                'data' => ['scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging']],
            ]),
            'graph.facebook.com/v21.0/1355229980038956/subscribed_apps*' => Http::response(['success' => true]),
            'graph.facebook.com/v21.0/1355229980038956*' => Http::response([
                'id' => '1355229980038956',
                'name' => 'IRON BODY NEIVA',
                'owner_business_info' => ['id' => '778899', 'name' => 'IRON BODY NEIVA'],
            ]),
            'graph.facebook.com/v21.0/555000111*' => Http::response([
                'id' => '555000111',
                'display_phone_number' => '+57 314 345 5483',
                'verified_name' => 'IRON BODY NEIVA',
                'quality_rating' => 'GREEN',
                'platform_type' => 'CLOUD_API',
            ]),
        ]);
    }

    /** Recorre el flujo entero y devuelve la respuesta del callback. */
    private function connect(array $overrides = []): TestResponse
    {
        $state = $this->postJson(self::URL.'/start', [], $this->saHeaders)->json('data.state');

        return $this->postJson(self::URL.'/callback', array_merge([
            'code' => 'AQD-codigo-de-un-solo-uso-de-meta',
            'state' => $state,
            'waba_id' => '1355229980038956',
            'phone_number_id' => '555000111',
            'business_id' => '778899',
        ], $overrides), $this->saHeaders);
    }

    // ── Autenticación y permisos ──────────────────────────────────────────────

    public function test_status_requires_authentication(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_shared_automation_token_cannot_operate_the_integration(): void
    {
        // El secreto compartido cruza el blindaje pero NO resuelve un admin, y
        // el onboarding registra quién conectó: una máquina no tiene nombre.
        config(['admin.api_token' => 'secreto-de-automatizacion']);

        $this->getJson(self::URL, ['Authorization' => 'Bearer secreto-de-automatizacion'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'integration_requires_admin');
    }

    public function test_reception_can_look_but_not_connect(): void
    {
        $reception = Admin::create([
            'name' => 'Recepción', 'email' => 'recepcion-wa@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);
        $headers = $this->actingAsAdmin($reception);

        $this->getJson(self::URL, $headers)
            ->assertOk()
            ->assertJsonPath('data.capabilities.can_view', true)
            ->assertJsonPath('data.capabilities.can_connect', false);

        $this->postJson(self::URL.'/start', [], $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'integration_forbidden');
    }

    public function test_disabled_admin_is_rejected(): void
    {
        $this->superAdmin->forceFill(['status' => 'disabled'])->save();

        $this->getJson(self::URL, $this->saHeaders)->assertStatus(403);
    }

    // ── Estado ────────────────────────────────────────────────────────────────

    public function test_status_reports_not_connected_when_there_is_nothing(): void
    {
        $this->getJson(self::URL, $this->saHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', 'not_connected')
            ->assertJsonPath('data.integration', null)
            ->assertJsonPath('data.channel.credential_source', 'env')
            ->assertJsonPath('data.onboarding.available', true);
    }

    public function test_status_says_what_is_missing_instead_of_offering_a_broken_button(): void
    {
        config()->set('meta.embedded_signup.config_id', '');

        $this->getJson(self::URL, $this->saHeaders)
            ->assertOk()
            ->assertJsonPath('data.onboarding.available', false)
            ->assertJsonPath('data.onboarding.missing_configuration', ['META_EMBEDDED_SIGNUP_CONFIG_ID']);
    }

    // ── Arranque ──────────────────────────────────────────────────────────────

    public function test_start_hands_the_browser_public_ids_only(): void
    {
        $response = $this->postJson(self::URL.'/start', [], $this->saHeaders)->assertOk();

        $response->assertJsonPath('data.app_id', '906146885861728')
            ->assertJsonPath('data.config_id', '1234567890')
            ->assertJsonPath('data.feature_type', 'whatsapp_business_app_onboarding');

        $this->assertNotEmpty($response->json('data.state'));
        // La prueba que de verdad importa de este endpoint.
        $this->assertStringNotContainsString(self::APP_SECRET, $response->getContent());
    }

    public function test_start_refuses_when_the_server_is_not_configured(): void
    {
        config()->set('meta.embedded_signup.config_id', '');

        $this->postJson(self::URL.'/start', [], $this->saHeaders)
            ->assertStatus(503)
            ->assertJsonPath('code', 'meta_app_not_configured');
    }

    // ── Callback ──────────────────────────────────────────────────────────────

    public function test_callback_exchanges_the_code_and_persists_the_connection(): void
    {
        $this->fakeHappyGraph();

        $this->connect()
            ->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.integration.waba_id', '1355229980038956')
            ->assertJsonPath('data.integration.phone_number_id', '555000111')
            ->assertJsonPath('data.integration.display_phone_number', '+57 314 345 5483')
            ->assertJsonPath('data.integration.business_name', 'IRON BODY NEIVA')
            ->assertJsonPath('data.integration.has_access_token', true);

        $this->assertDatabaseHas('whatsapp_business_integrations', [
            'waba_id' => '1355229980038956',
            'phone_number_id' => '555000111',
            'status' => WhatsappBusinessIntegration::STATUS_CONNECTED,
            'connected_by' => $this->superAdmin->id,
        ]);
    }

    public function test_the_access_token_is_encrypted_at_rest_and_never_returned(): void
    {
        $this->fakeHappyGraph();

        $body = $this->connect()->assertOk()->getContent();
        $this->assertStringNotContainsString('EAA-TOKEN-DE-NEGOCIO', $body);
        $this->assertStringNotContainsString(self::APP_SECRET, $body);

        // En la columna hay cifrado, no el token en claro: un volcado de la base
        // de datos no puede convertirse en permiso para escribir a los clientes.
        $stored = DB::table('whatsapp_business_integrations')->first();
        $this->assertNotSame('EAA-TOKEN-DE-NEGOCIO', $stored->access_token);
        $this->assertStringNotContainsString('EAA-TOKEN-DE-NEGOCIO', (string) $stored->access_token);

        // Y aun así el modelo lo devuelve descifrado para poder operar.
        $this->assertSame('EAA-TOKEN-DE-NEGOCIO', WhatsappBusinessIntegration::current()->access_token);
    }

    public function test_connecting_does_not_turn_the_channel_on(): void
    {
        $this->fakeHappyGraph();

        $this->connect()->assertOk()->assertJsonPath('data.meta_enabled', false);

        // El interruptor maestro sigue donde estaba. Guardar credenciales y
        // autorizar mensajes a clientes reales son dos decisiones distintas.
        $this->assertFalse((bool) config('meta.enabled'));
        $this->getJson(self::URL, $this->saHeaders)
            ->assertJsonPath('data.channel.meta_enabled', false)
            ->assertJsonPath('data.channel.can_send', false);
    }

    public function test_the_state_is_single_use(): void
    {
        $this->fakeHappyGraph();

        $state = $this->postJson(self::URL.'/start', [], $this->saHeaders)->json('data.state');
        $payload = [
            'code' => 'AQD-codigo', 'state' => $state,
            'waba_id' => '1355229980038956', 'phone_number_id' => '555000111',
        ];

        $this->postJson(self::URL.'/callback', $payload, $this->saHeaders)->assertOk();
        // Un doble clic no puede volver a canjear un código ya gastado.
        $this->postJson(self::URL.'/callback', $payload, $this->saHeaders)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_signup_state');
    }

    public function test_a_state_from_another_admin_is_refused(): void
    {
        $other = Admin::create([
            'name' => 'Otro', 'email' => 'otro-wa@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active',
        ]);
        $stolenState = $this->postJson(self::URL.'/start', [], $this->actingAsAdmin($other))
            ->json('data.state');

        $this->postJson(self::URL.'/callback', [
            'code' => 'AQD-codigo', 'state' => $stolenState,
            'waba_id' => '1355229980038956', 'phone_number_id' => '555000111',
        ], $this->saHeaders)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_signup_state');
    }

    public function test_identifiers_that_are_not_numeric_are_rejected(): void
    {
        $this->connect(['waba_id' => 'DROP TABLE'])->assertStatus(422);
    }

    public function test_a_rejected_exchange_does_not_break_a_working_connection(): void
    {
        $this->fakeHappyGraph();
        $this->connect()->assertOk();
        $this->forgetRegistry();

        // Ahora Meta rechaza el código (caducado, ya usado, permiso retirado...).
        $this->resetHttp();
        Http::fake([
            'graph.facebook.com/v21.0/oauth/access_token*' => Http::response([
                'error' => ['message' => 'This authorization code has expired.', 'code' => 100],
            ], 400),
        ]);

        $this->connect()
            ->assertStatus(502)
            ->assertJsonPath('code', 'code_exchange_failed');

        // La conexión anterior sigue en pie y sigue sirviendo credenciales.
        $this->forgetRegistry();
        $current = WhatsappBusinessIntegration::current();
        $this->assertNotNull($current);
        $this->assertTrue($current->isUsable());
        $this->assertSame('code_exchange_failed', $current->last_error_code);
        $this->assertSame('555000111', app(WhatsappIntegrationRegistry::class)->phoneNumberId());
    }

    public function test_reconnecting_the_same_number_updates_instead_of_duplicating(): void
    {
        $this->fakeHappyGraph();

        $this->connect()->assertOk();
        $this->forgetRegistry();
        $this->connect()->assertOk();

        $this->assertSame(1, WhatsappBusinessIntegration::count());
    }

    // ── Desconexión ───────────────────────────────────────────────────────────

    public function test_disconnect_destroys_the_token_but_keeps_the_history(): void
    {
        $this->fakeHappyGraph();
        $this->connect()->assertOk();
        $this->forgetRegistry();

        $this->postJson(self::URL.'/disconnect', [], $this->saHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', 'not_connected');

        // La fila NO se borra: saber qué número estuvo conectado y cuándo es lo
        // primero que se pregunta cuando algo dejó de llegar.
        $row = WhatsappBusinessIntegration::first();
        $this->assertSame(WhatsappBusinessIntegration::STATUS_DISCONNECTED, $row->status);
        $this->assertNotNull($row->disconnected_at);
        $this->assertSame($this->superAdmin->id, (int) $row->disconnected_by);
        // Pero la credencial se destruye: no se guarda lo que ya no se usa.
        $this->assertNull($row->access_token);
    }

    public function test_disconnect_without_a_connection_says_so(): void
    {
        $this->postJson(self::URL.'/disconnect', [], $this->saHeaders)
            ->assertStatus(404)
            ->assertJsonPath('code', 'whatsapp_not_connected');
    }
}
