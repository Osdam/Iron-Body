<?php

namespace Tests\Feature\Integrations;

use App\Models\WhatsappBusinessIntegration;
use App\Services\Meta\MetaAuthService;
use App\Services\Meta\MetaDoctorService;
use App\Services\Meta\MetaMessagingService;
use App\Services\Meta\WhatsappIntegrationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * De dónde salen las credenciales del canal, y en qué orden.
 *
 * Esta es la prueba de que conectar desde el CRM sirve de algo: sin ella, la
 * pantalla guardaría datos bonitos y el envío seguiría usando el `.env`, que es
 * exactamente la integración decorativa que no queremos.
 *
 * Y es también la prueba de que no rompimos nada: sin fila conectada, todo
 * responde igual que antes de que esta funcionalidad existiera.
 */
class WhatsappCredentialPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private const ENV_TOKEN = 'TOKEN-DEL-ENV';

    private const ENV_PHONE = '111000111';

    private const DB_TOKEN = 'TOKEN-GUARDADO-EN-BD';

    private const DB_PHONE = '999888777';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', true);
        config()->set('meta.app_secret', 'app-secret');
        config()->set('meta.access_token', self::ENV_TOKEN);
        config()->set('meta.whatsapp_phone_number_id', self::ENV_PHONE);
        config()->set('meta.whatsapp_business_account_id', 'WABA-DEL-ENV');
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');

        Http::preventStrayRequests();
    }

    private function auth(): MetaAuthService
    {
        app(WhatsappIntegrationRegistry::class)->forget();

        return app(MetaAuthService::class);
    }

    private function connectedRow(array $overrides = []): WhatsappBusinessIntegration
    {
        return WhatsappBusinessIntegration::create(array_merge([
            'waba_id' => 'WABA-DE-BD',
            'phone_number_id' => self::DB_PHONE,
            'business_id' => 'BUSINESS-DE-BD',
            'display_phone_number' => '+57 314 345 5483',
            'status' => WhatsappBusinessIntegration::STATUS_CONNECTED,
            'access_token' => self::DB_TOKEN,
            'connected_at' => now(),
        ], $overrides));
    }

    // ── Sin conexión: nada cambió ─────────────────────────────────────────────

    public function test_without_a_connection_everything_reads_the_env(): void
    {
        $auth = $this->auth();

        $this->assertSame(self::ENV_TOKEN, $auth->accessToken());
        $this->assertSame(self::ENV_PHONE, $auth->phoneNumberId());
        $this->assertSame('WABA-DEL-ENV', $auth->wabaId());
        $this->assertSame('env', $auth->credentialSource());
        $this->assertTrue($auth->isConfigured());
    }

    // ── Con conexión: manda la base de datos ──────────────────────────────────

    public function test_a_connected_row_takes_precedence_over_the_env(): void
    {
        $this->connectedRow();
        $auth = $this->auth();

        $this->assertSame(self::DB_TOKEN, $auth->accessToken());
        $this->assertSame(self::DB_PHONE, $auth->phoneNumberId());
        $this->assertSame('WABA-DE-BD', $auth->wabaId());
        $this->assertSame('database', $auth->credentialSource());
    }

    public function test_the_real_send_uses_the_connected_number_and_token(): void
    {
        $this->connectedRow();
        app(WhatsappIntegrationRegistry::class)->forget();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]]),
        ]);

        $result = app(MetaMessagingService::class)->sendWhatsappText('573150000000', 'hola');

        $this->assertTrue($result['ok']);

        // El envío fue al número de la conexión, no al del fichero, y firmado
        // con el token de la conexión. Si esto se rompe, el CRM diría
        // "conectado" mientras los mensajes salen por la cuenta anterior.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), self::DB_PHONE.'/messages')
                && ! str_contains($request->url(), self::ENV_PHONE)
                && $request->hasHeader('Authorization', 'Bearer '.self::DB_TOKEN);
        });
    }

    // ── Estados que NO deben ganarle al .env ──────────────────────────────────

    public function test_a_disconnected_row_hands_the_channel_back_to_the_env(): void
    {
        $row = $this->connectedRow();
        $row->forceFill([
            'status' => WhatsappBusinessIntegration::STATUS_DISCONNECTED,
            'access_token' => null,
        ])->save();

        $auth = $this->auth();

        $this->assertSame(self::ENV_PHONE, $auth->phoneNumberId());
        $this->assertSame(self::ENV_TOKEN, $auth->accessToken());
        $this->assertSame('env', $auth->credentialSource());
    }

    public function test_a_connected_row_without_a_token_does_not_win(): void
    {
        // Un canje a medias. Dejarla ganar apagaría un canal que funcionaba.
        $this->connectedRow(['access_token' => null]);

        $this->assertSame(self::ENV_PHONE, $this->auth()->phoneNumberId());
    }

    public function test_an_expired_token_does_not_win(): void
    {
        $this->connectedRow(['token_expires_at' => now()->subDay()]);

        $this->assertSame(self::ENV_TOKEN, $this->auth()->accessToken());
    }

    public function test_a_token_without_a_declared_expiry_is_valid(): void
    {
        // Ausencia de caducidad = token de larga duración, NO token caducado.
        $this->connectedRow(['token_expires_at' => null]);

        $this->assertSame(self::DB_TOKEN, $this->auth()->accessToken());
    }

    public function test_the_precedence_can_be_switched_off_without_disconnecting(): void
    {
        $this->connectedRow();
        config()->set('meta.embedded_signup.db_credentials_precedence', false);

        // La vuelta atrás más rápida si una conexión sale mal: el .env manda
        // otra vez sin tener que tocar la fila ni desplegar nada.
        $this->assertSame(self::ENV_PHONE, $this->auth()->phoneNumberId());
        $this->assertSame('env', $this->auth()->credentialSource());
    }

    // ── El diagnóstico refleja la credencial efectiva ─────────────────────────

    public function test_the_doctor_reports_where_the_credentials_come_from(): void
    {
        $this->connectedRow();
        app(WhatsappIntegrationRegistry::class)->forget();

        $report = app(MetaDoctorService::class)->report();

        $this->assertSame('database', $report['credential_source']);
        $this->assertTrue($report['present']['whatsapp_phone_number_id']);
        $this->assertTrue($report['live_send_allowed']);
        $this->assertSame('real', $report['send_mode']);
    }

    public function test_the_doctor_never_prints_a_token_from_the_database(): void
    {
        $this->connectedRow();
        app(WhatsappIntegrationRegistry::class)->forget();

        $json = json_encode(app(MetaDoctorService::class)->report());

        $this->assertStringNotContainsString(self::DB_TOKEN, $json);
    }

    // ── Robustez ──────────────────────────────────────────────────────────────

    public function test_a_missing_table_falls_back_to_the_env_instead_of_crashing(): void
    {
        // Durante un despliegue el código nuevo corre unos segundos contra un
        // esquema sin migrar. En ese hueco el canal no puede caerse.
        Schema::drop('whatsapp_business_integrations');

        $this->assertSame(self::ENV_PHONE, $this->auth()->phoneNumberId());
        $this->assertSame('env', $this->auth()->credentialSource());
    }
}
