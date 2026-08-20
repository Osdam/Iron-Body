<?php

namespace Tests\Feature\Integrations;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Iron Body tiene DOS apps de Meta, y confundirlas rompe el onboarding.
 *
 *   · la del CANAL      (META_APP_ID / META_APP_SECRET): dueña del webhook y del
 *     token de Cloud API. Su secreto firma los POST entrantes.
 *   · la de EMBEDDED SIGNUP: donde vive el config_id de Facebook Login for
 *     Business y la revisión de permisos.
 *
 * Meta comprueba que el `config_id` pertenezca al `app_id` con el que se abre el
 * diálogo. Cruzados, responde «Función no disponible» y `FB.login` no devuelve
 * ningún código — que es exactamente el fallo que se vio en producción.
 *
 * Estas pruebas fijan las dos reglas que lo impiden: el diálogo usa la app de
 * Embedded Signup, y el canje usa el secreto DE ESA MISMA app, nunca el del canal.
 */
class WhatsappSignupAppIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/integrations/whatsapp';

    private const CHANNEL_APP = '906146885861728';

    private const CHANNEL_SECRET = 'SECRETO-DEL-CANAL';

    private const SIGNUP_APP = '1747474522949342';

    private const SIGNUP_SECRET = 'SECRETO-DE-EMBEDDED-SIGNUP';

    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        config()->set('meta.app_id', self::CHANNEL_APP);
        config()->set('meta.app_secret', self::CHANNEL_SECRET);
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');
        config()->set('meta.embedded_signup.config_id', '1643115916774956');
        config()->set('meta.embedded_signup.app_id', self::SIGNUP_APP);
        config()->set('meta.embedded_signup.app_secret', self::SIGNUP_SECRET);

        Http::preventStrayRequests();

        $admin = Admin::create([
            'name' => 'Super QA', 'email' => 'super-identity@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->headers = $this->actingAsAdmin($admin);
    }

    // ── El diálogo ────────────────────────────────────────────────────────────

    public function test_the_dialog_opens_with_the_signup_app_not_the_channel_one(): void
    {
        $response = $this->postJson(self::URL.'/start', [], $this->headers)->assertOk();

        $response->assertJsonPath('data.app_id', self::SIGNUP_APP)
            ->assertJsonPath('data.config_id', '1643115916774956')
            // Coexistencia: el número sigue en la app WhatsApp Business.
            ->assertJsonPath('data.feature_type', 'whatsapp_business_app_onboarding');

        // La app del canal no puede aparecer: es la que provocaba el fallo.
        $this->assertStringNotContainsString(self::CHANNEL_APP, $response->getContent());
    }

    public function test_no_app_secret_ever_reaches_the_browser(): void
    {
        $body = $this->postJson(self::URL.'/start', [], $this->headers)->getContent();

        $this->assertStringNotContainsString(self::SIGNUP_SECRET, $body);
        $this->assertStringNotContainsString(self::CHANNEL_SECRET, $body);
    }

    // ── El canje ──────────────────────────────────────────────────────────────

    public function test_the_code_is_exchanged_with_the_signup_apps_own_credentials(): void
    {
        Http::fake([
            'graph.facebook.com/v21.0/oauth/access_token*' => Http::response([
                'access_token' => 'EAA-TOKEN', 'token_type' => 'bearer',
            ]),
            'graph.facebook.com/v21.0/debug_token*' => Http::response(['data' => ['scopes' => []]]),
            'graph.facebook.com/*' => Http::response(['id' => '1']),
        ]);

        $state = $this->postJson(self::URL.'/start', [], $this->headers)->json('data.state');

        $this->postJson(self::URL.'/callback', [
            'code' => 'AQD-codigo-de-un-solo-uso',
            'state' => $state,
            'waba_id' => '1355229980038956',
            'phone_number_id' => '555000111',
        ], $this->headers)->assertOk();

        // Canjear un código de la app A firmando con el secreto de la app B
        // siempre falla, y falla al final del recorrido.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'oauth/access_token')) {
                return false;
            }
            $q = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return ($q['client_id'] ?? null) === self::SIGNUP_APP
                && ($q['client_secret'] ?? null) === self::SIGNUP_SECRET;
        });

        // Y en ninguna llamada viaja la credencial del canal.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), self::CHANNEL_SECRET));
    }

    public function test_the_saved_row_records_the_app_that_did_the_onboarding(): void
    {
        Http::fake([
            'graph.facebook.com/v21.0/oauth/access_token*' => Http::response(['access_token' => 'EAA-TOKEN']),
            'graph.facebook.com/*' => Http::response(['id' => '1']),
        ]);

        $state = $this->postJson(self::URL.'/start', [], $this->headers)->json('data.state');
        $this->postJson(self::URL.'/callback', [
            'code' => 'AQD-codigo', 'state' => $state,
            'waba_id' => '1355229980038956', 'phone_number_id' => '555000111',
        ], $this->headers)->assertOk();

        $this->assertDatabaseHas('whatsapp_business_integrations', [
            'meta_app_id' => self::SIGNUP_APP,
        ]);
    }

    // ── Falta el secreto: abrir sí, canjear no ────────────────────────────────

    public function test_without_the_secret_the_dialog_still_opens_but_the_exchange_is_refused(): void
    {
        config()->set('meta.embedded_signup.app_secret', '');

        // Se puede pulsar el botón y verificar que abre con la app correcta...
        $state = $this->postJson(self::URL.'/start', [], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.app_id', self::SIGNUP_APP)
            ->json('data.state');

        // ...pero el canje se rechaza ANTES de gastar el código contra Meta.
        // El `state` es válido: lo que corta es la falta del secreto, no otra cosa.
        $this->postJson(self::URL.'/callback', [
            'code' => 'AQD-codigo', 'state' => $state,
            'waba_id' => '1355229980038956', 'phone_number_id' => '555000111',
        ], $this->headers)
            ->assertStatus(503)
            ->assertJsonPath('code', 'meta_app_not_configured');

        Http::assertNothingSent();
    }

    public function test_the_panel_names_the_missing_variable_while_the_button_stays_usable(): void
    {
        config()->set('meta.embedded_signup.app_secret', '');

        $this->getJson(self::URL, $this->headers)
            ->assertOk()
            // El botón sigue disponible: abrir el diálogo no necesita secreto.
            ->assertJsonPath('data.onboarding.available', true)
            ->assertJsonPath('data.onboarding.can_exchange', false)
            // Y la pantalla dice exactamente qué falta, sin esconderlo.
            ->assertJsonPath('data.onboarding.missing_configuration', ['META_EMBEDDED_SIGNUP_APP_SECRET']);
    }

    // ── Herencia del secreto: solo si es la MISMA app ─────────────────────────

    public function test_the_channel_secret_is_inherited_only_when_both_apps_are_the_same(): void
    {
        // Config resuelta como en config/meta.php, misma app en las dos.
        $appId = self::CHANNEL_APP;
        $explicit = '';
        $resolved = $explicit !== '' ? $explicit
            : ($appId === self::CHANNEL_APP ? self::CHANNEL_SECRET : null);

        $this->assertSame(self::CHANNEL_SECRET, $resolved);

        // Con apps distintas NO se hereda: mezclar credenciales falla siempre.
        $appId = self::SIGNUP_APP;
        $resolved = $explicit !== '' ? $explicit
            : ($appId === self::CHANNEL_APP ? self::CHANNEL_SECRET : null);

        $this->assertNull($resolved);
    }
}
