<?php

namespace Tests\Feature\Integrations;

use App\Jobs\ProcessMetaWebhookEvent;
use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MetaWebhookEvent;
use App\Models\WhatsappBusinessIntegration;
use App\Services\Meta\WhatsappIntegrationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Aislamiento entre la conexión que OPERA el canal y la de DEMOSTRACIÓN.
 *
 * La demostración para la revisión de Meta usa una WABA de prueba, y la app de
 * Meta está suscrita a un único endpoint. Sin separación estricta, esa WABA de
 * prueba acabaría dando las credenciales del canal, creando leads reales y
 * disparando al agente comercial.
 *
 * Cada prueba de aquí corresponde a una forma concreta en la que la
 * demostración podría contaminar producción.
 */
class WhatsappReviewIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/integrations/whatsapp';

    private const APP = '1747474522949342';

    private const PROD_PHONE = '111000111';

    /** El número del gimnasio. Jamás debe poder conectarse como demostración. */
    private const PROTEGIDO = '+57 314 345 5483';

    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        config()->set('meta.app_id', '906146885861728');
        config()->set('meta.app_secret', 'SECRETO-DEL-CANAL');
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');
        config()->set('meta.whatsapp_phone_number_id', self::PROD_PHONE);
        config()->set('meta.embedded_signup.app_id', self::APP);
        config()->set('meta.embedded_signup.app_secret', 'SECRETO-DE-SIGNUP');
        config()->set('meta.embedded_signup.config_id', '1643115916774956');
        config()->set('meta.embedded_signup.review.enabled', true);
        config()->set('meta.protected_numbers', ['573143455483']);
        config()->set('marketing.ai.driver', 'fake');

        Http::preventStrayRequests();

        $admin = Admin::create([
            'name' => 'Super QA', 'email' => 'super-isolation@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->headers = $this->actingAsAdmin($admin);
    }

    private function fakeGraph(string $display = '+57 300 000 0000'): void
    {
        Http::fake([
            'graph.facebook.com/v21.0/oauth/access_token*' => Http::response(['access_token' => 'EAA-TOKEN']),
            'graph.facebook.com/v21.0/debug_token*' => Http::response(['data' => ['scopes' => ['business_management']]]),
            '*/subscribed_apps*' => Http::response(['success' => true]),
            '*' => Http::response(['id' => '1', 'display_phone_number' => $display, 'name' => 'Demo']),
        ]);
    }

    /** Recorre start+callback para un propósito y devuelve la respuesta. */
    private function onboard(string $mode, string $waba, string $phone): TestResponse
    {
        $state = $this->postJson(self::URL.'/start', ['mode' => $mode], $this->headers)
            ->json('data.state');

        return $this->postJson(self::URL.'/callback', [
            'code' => 'AQD-codigo', 'state' => $state, 'mode' => $mode,
            'waba_id' => $waba, 'phone_number_id' => $phone, 'business_id' => '778899',
        ], $this->headers);
    }

    // ── 1. El onboarding de cada modo no contamina al otro ────────────────────

    public function test_review_onboarding_does_not_create_a_production_connection(): void
    {
        $this->fakeGraph();

        $this->onboard('review', '999', '555')->assertOk()
            ->assertJsonPath('data.purpose', 'review');

        $this->assertNull(WhatsappBusinessIntegration::current());
        $this->assertNotNull(WhatsappBusinessIntegration::currentReview());
        // Y sobre todo: las credenciales del canal NO cambian.
        app(WhatsappIntegrationRegistry::class)->forget();
        $this->assertSame(self::PROD_PHONE, app(WhatsappIntegrationRegistry::class)->phoneNumberId());
        $this->assertSame('env', app(WhatsappIntegrationRegistry::class)->source());
    }

    public function test_production_onboarding_does_not_create_a_review_connection(): void
    {
        $this->fakeGraph();

        $this->onboard('production', '111', '222')->assertOk()
            ->assertJsonPath('data.purpose', 'production');

        $this->assertNull(WhatsappBusinessIntegration::currentReview());
        $this->assertNotNull(WhatsappBusinessIntegration::current());
    }

    public function test_current_never_returns_review_even_when_it_is_the_newest(): void
    {
        $this->fakeGraph();
        $this->onboard('production', '111', '222')->assertOk();
        app(WhatsappIntegrationRegistry::class)->forget();
        $this->onboard('review', '999', '555')->assertOk();

        // La de revisión es la más reciente; aun así no manda.
        $this->assertSame('222', WhatsappBusinessIntegration::current()?->phone_number_id);
        $this->assertSame('555', WhatsappBusinessIntegration::currentReview()?->phone_number_id);

        app(WhatsappIntegrationRegistry::class)->forget();
        $this->assertSame('222', app(WhatsappIntegrationRegistry::class)->phoneNumberId());
    }

    // ── 2. Conflicto de propósito ─────────────────────────────────────────────

    public function test_a_review_onboarding_cannot_take_over_a_production_pair(): void
    {
        $this->fakeGraph();
        $this->onboard('production', '111', '222')->assertOk();

        $this->onboard('review', '111', '222')
            ->assertStatus(409)
            ->assertJsonPath('code', 'purpose_conflict');

        // La conexión productiva sigue intacta y sigue siendo de producción.
        $fila = WhatsappBusinessIntegration::current();
        $this->assertNotNull($fila);
        $this->assertSame(WhatsappBusinessIntegration::PURPOSE_PRODUCTION, $fila->purpose);
        $this->assertTrue($fila->isUsable());
    }

    // ── 3. El número protegido ────────────────────────────────────────────────

    public function test_the_protected_number_is_refused_before_anything_is_stored(): void
    {
        $this->fakeGraph(self::PROTEGIDO);

        $this->onboard('review', '999', '555')
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_number');

        // Ni una fila. La barrera actúa antes de persistir.
        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    public function test_the_production_phone_number_id_is_refused_in_review_mode(): void
    {
        $this->fakeGraph();

        $this->onboard('review', '999', self::PROD_PHONE)
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_number');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    /**
     * En COEXISTENCIA ese número sí es el objetivo legítimo: es toda la razón
     * de ser del módulo. La barrera protege la demostración, no el producto.
     */
    public function test_the_protected_number_is_allowed_in_production_coexistence(): void
    {
        $this->fakeGraph(self::PROTEGIDO);

        $this->onboard('production', '111', '222')->assertOk();

        $this->assertNotNull(WhatsappBusinessIntegration::current());
    }

    // ── 4. El state no se puede cruzar ────────────────────────────────────────

    public function test_a_review_state_cannot_complete_a_production_connection(): void
    {
        $this->fakeGraph();

        $state = $this->postJson(self::URL.'/start', ['mode' => 'review'], $this->headers)
            ->json('data.state');

        $this->postJson(self::URL.'/callback', [
            'code' => 'AQD-codigo', 'state' => $state, 'mode' => 'production',
            'waba_id' => '999', 'phone_number_id' => '555',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_signup_state');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
        Http::assertNothingSent();
    }

    public function test_a_production_state_cannot_complete_a_review_connection(): void
    {
        $this->fakeGraph();

        $state = $this->postJson(self::URL.'/start', ['mode' => 'production'], $this->headers)
            ->json('data.state');

        $this->postJson(self::URL.'/callback', [
            'code' => 'AQD-codigo', 'state' => $state, 'mode' => 'review',
            'waba_id' => '999', 'phone_number_id' => '555',
        ], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_signup_state');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    // ── 5. Desconectar ────────────────────────────────────────────────────────

    public function test_disconnecting_review_leaves_production_untouched(): void
    {
        $this->fakeGraph();
        $this->onboard('production', '111', '222')->assertOk();
        app(WhatsappIntegrationRegistry::class)->forget();
        $this->onboard('review', '999', '555')->assertOk();

        $this->postJson(self::URL.'/disconnect', ['mode' => 'review'], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.purpose', 'review');

        $this->assertNull(WhatsappBusinessIntegration::currentReview());

        $prod = WhatsappBusinessIntegration::current();
        $this->assertNotNull($prod);
        $this->assertTrue($prod->isUsable(), 'la conexión productiva perdió su token');
    }

    public function test_disconnecting_production_leaves_review_untouched(): void
    {
        $this->fakeGraph();
        $this->onboard('production', '111', '222')->assertOk();
        app(WhatsappIntegrationRegistry::class)->forget();
        $this->onboard('review', '999', '555')->assertOk();

        $this->postJson(self::URL.'/disconnect', ['mode' => 'production'], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.purpose', 'production');

        $this->assertNull(WhatsappBusinessIntegration::current());
        $this->assertNotNull(WhatsappBusinessIntegration::currentReview());
    }

    public function test_review_disconnect_reports_nothing_to_disconnect_when_only_production_exists(): void
    {
        $this->fakeGraph();
        $this->onboard('production', '111', '222')->assertOk();

        $this->postJson(self::URL.'/disconnect', ['mode' => 'review'], $this->headers)
            ->assertStatus(404)
            ->assertJsonPath('code', 'whatsapp_not_connected');

        // No pudo tocar la productiva ni por descarte.
        $this->assertNotNull(WhatsappBusinessIntegration::current());
    }

    // ── 6. El interruptor del modo demostración ───────────────────────────────

    public function test_review_mode_is_refused_when_the_flag_is_off(): void
    {
        config()->set('meta.embedded_signup.review.enabled', false);

        $this->postJson(self::URL.'/start', ['mode' => 'review'], $this->headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'review_mode_disabled');
    }

    public function test_production_keeps_working_with_the_review_flag_off(): void
    {
        config()->set('meta.embedded_signup.review.enabled', false);

        $this->postJson(self::URL.'/start', [], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.app_id', self::APP)
            ->assertJsonPath('data.feature_type', 'whatsapp_business_app_onboarding');
    }

    // ── 7. Los dos modos piden lo mismo salvo el featureType ──────────────────

    public function test_review_asks_meta_for_everything_except_companion_pairing(): void
    {
        $prod = $this->postJson(self::URL.'/start', ['mode' => 'production'], $this->headers)->json('data');
        $rev = $this->postJson(self::URL.'/start', ['mode' => 'review'], $this->headers)->json('data');

        foreach (['app_id', 'config_id', 'scopes'] as $igual) {
            $this->assertSame($prod[$igual], $rev[$igual], "difieren en {$igual}");
        }

        // La única diferencia funcional, que es la que Meta bloquea hoy (#4563039).
        $this->assertSame('whatsapp_business_app_onboarding', $prod['feature_type']);
        $this->assertNull($rev['feature_type']);
    }

    // ── 8. El webhook de la demostración no toca nada productivo ──────────────

    public function test_a_review_webhook_creates_no_production_side_effects(): void
    {
        $this->fakeGraph();
        $this->onboard('review', '999', '555')->assertOk();

        $evento = $this->eventoCrudo('555', 'test-review');

        ProcessMetaWebhookEvent::dispatchSync($evento->id);

        // Ni leads, ni conversaciones, ni mensajes. Nada entró al negocio.
        $this->assertSame(0, MarketingLead::count(), 'la demostración creó un lead');
        $this->assertSame(0, MarketingConversation::count(), 'la demostración creó una conversación');
        $this->assertSame(0, MarketingMessage::count(), 'la demostración creó un mensaje');
    }

    public function test_a_production_webhook_is_still_processed_normally(): void
    {
        $evento = $this->eventoCrudo(self::PROD_PHONE, 'test-prod');

        ProcessMetaWebhookEvent::dispatchSync($evento->id);

        // El aislamiento no puede haber roto el camino real.
        $this->assertSame(1, MarketingLead::count());
        $this->assertSame(1, MarketingMessage::count());
    }

    /**
     * Un evento crudo como el que deja el ingest tras validar la firma.
     *
     * `payload_hash` es obligatorio: es la clave de idempotencia con la que se
     * reconoce una reentrega de Meta del mismo cuerpo.
     */
    private function eventoCrudo(string $phoneNumberId, string $correlacion): MetaWebhookEvent
    {
        $payload = $this->payloadEntrante($phoneNumberId);
        $crudo = json_encode($payload);

        return MetaWebhookEvent::create([
            'object' => 'whatsapp_business_account',
            'payload' => $payload,
            'payload_hash' => hash('sha256', (string) $crudo),
            'correlation_id' => $correlacion,
            'phone_number_id' => $phoneNumberId,
            'messages_count' => 1,
            'statuses_count' => 0,
            'payload_bytes' => strlen((string) $crudo),
        ]);
    }

    /** @return array<string,mixed> */
    private function payloadEntrante(string $phoneNumberId): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $phoneNumberId, 'display_phone_number' => '57300'],
                        'contacts' => [['wa_id' => '573150000000', 'profile' => ['name' => 'Prueba']]],
                        'messages' => [[
                            'from' => '573150000000',
                            'id' => 'wamid.'.$phoneNumberId,
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => 'hola'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
