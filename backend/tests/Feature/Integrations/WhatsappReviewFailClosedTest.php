<?php

namespace Tests\Feature\Integrations;

use App\Models\Admin;
use App\Models\WhatsappBusinessIntegration;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * La demostración falla CERRADO.
 *
 * La versión anterior de esta barrera tenía un agujero de los que no se ven
 * leyendo el código de corrido: comprobaba el número preguntándole a Meta y, si
 * Meta no contestaba, seguía adelante. Timeout, 5xx, permiso insuficiente o un
 * campo ausente daban todos el mismo resultado —cadena vacía—, la comparación
 * no encontraba nada y el número entraba. Una barrera que se abre justo cuando
 * falla la red es peor que no tenerla, porque induce a confiar en ella.
 *
 * Aquí se fija la regla contraria y se prueba una por una: si no se puede
 * DEMOSTRAR que un activo es seguro, no lo es.
 *
 * Todo esto rige SOLO con purpose=review. En coexistencia productiva la WABA y
 * el número del gimnasio son el objetivo legítimo del módulo, y las dos últimas
 * pruebas existen para que nadie los bloquee ahí por error.
 */
class WhatsappReviewFailClosedTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/integrations/whatsapp';

    /** La cuenta que opera el canal del negocio. */
    private const WABA_PRODUCTIVA = '1381789767207733';

    /** Una de las WABA vacías, sin números, comprobadas en Meta. */
    private const WABA_SEGURA = '2426472051212622';

    private const NUMERO_PRODUCTIVO_VISIBLE = '+57 314 345 5483';

    private array $headers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        config()->set('meta.app_id', '906146885861728');
        config()->set('meta.app_secret', 'SECRETO-DEL-CANAL');
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');
        config()->set('meta.embedded_signup.app_id', '1747474522949342');
        config()->set('meta.embedded_signup.app_secret', 'SECRETO-DE-SIGNUP');
        config()->set('meta.embedded_signup.config_id', '1643115916774956');
        config()->set('meta.embedded_signup.review.enabled', true);
        config()->set('meta.embedded_signup.review.protected_waba_ids', [self::WABA_PRODUCTIVA]);
        config()->set('meta.embedded_signup.review.protected_phone_number_ids', []);
        config()->set('meta.embedded_signup.review.protected_numbers', ['573143455483']);
        config()->set('marketing.ai.driver', 'fake');

        Http::preventStrayRequests();

        $admin = Admin::create([
            'name' => 'Super QA', 'email' => 'super-failclosed@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->headers = $this->actingAsAdmin($admin);
    }

    /**
     * El canje siempre funciona; lo que cambia entre pruebas es qué contesta
     * Graph cuando se le pregunta QUÉ número es.
     */
    private function fakeGraph(mixed $lecturaDelNumero): void
    {
        /*
         * UNA sola clausura que despacha por URL, en vez de un array de patrones.
         *
         * `Http::fake([...])` con una clausura entre los valores la aplica a
         * TODAS las peticiones e ignora los patrones más específicos declarados
         * antes. Con el array, el fallo que solo debía afectar a la lectura del
         * número tumbaba también el canje del código, y las pruebas del modo
         * "Graph caído" acababan comprobando un 502 del canje en vez de la
         * barrera. Despachando a mano no hay competencia entre patrones.
         */
        Http::fake(function ($request) use ($lecturaDelNumero) {
            $url = $request->url();

            if (str_contains($url, 'oauth/access_token')) {
                return Http::response(['access_token' => 'EAA-TOKEN']);
            }

            if (str_contains($url, 'debug_token')) {
                return Http::response([
                    'data' => ['scopes' => ['whatsapp_business_management', 'business_management']],
                ]);
            }

            if (str_contains($url, 'subscribed_apps')) {
                return Http::response(['success' => true]);
            }

            // Todo lo demás es leer un activo: el número que se verifica, y el
            // WABA que `syncFromGraph()` consulta después.
            return is_callable($lecturaDelNumero) ? $lecturaDelNumero($request) : $lecturaDelNumero;
        });
    }

    /**
     * Un número que Meta resuelve y que no está protegido.
     *
     * Devuelve una promesa, no una `Response`: es lo que produce
     * `Http::response()` y lo que `Http::fake()` espera recibir.
     */
    private function numeroSeguro(): PromiseInterface
    {
        return Http::response([
            'id' => '1', 'name' => 'Demo',
            'display_phone_number' => '+57 300 000 0000',
        ]);
    }

    /**
     * Recorre start+callback. `$phone` a null omite el identificador del número,
     * que es el caso de un onboarding que autoriza la cuenta sin teléfono.
     */
    private function onboard(string $mode, string $waba, ?string $phone): TestResponse
    {
        $state = $this->postJson(self::URL.'/start', ['mode' => $mode], $this->headers)
            ->json('data.state');

        $cuerpo = [
            'code' => 'AQD-codigo', 'state' => $state, 'mode' => $mode,
            'waba_id' => $waba, 'business_id' => '178717441477530',
        ];

        if ($phone !== null) {
            $cuerpo['phone_number_id'] = $phone;
        }

        return $this->postJson(self::URL.'/callback', $cuerpo, $this->headers);
    }

    // ── 1. La WABA productiva ─────────────────────────────────────────────────

    public function test_review_refuses_the_production_waba(): void
    {
        $this->fakeGraph($this->numeroSeguro());

        $this->onboard('review', self::WABA_PRODUCTIVA, '555')
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_waba');

        $this->assertSame(0, WhatsappBusinessIntegration::count(), 'no puede quedar ni rastro');

        // La barrera actúa ANTES de canjear: el código no llegó a gastarse.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'oauth/access_token'));
    }

    // ── 2. El número productivo, resuelto contra Graph ────────────────────────

    public function test_review_refuses_the_production_number_resolved_from_graph(): void
    {
        $this->fakeGraph(Http::response([
            'id' => '1', 'display_phone_number' => self::NUMERO_PRODUCTIVO_VISIBLE,
        ]));

        $this->onboard('review', self::WABA_SEGURA, '555')
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_number');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    // ── 3-4. No poder comprobarlo NO es permiso para seguir ───────────────────

    public function test_review_refuses_when_graph_connection_fails(): void
    {
        $this->fakeGraph(fn () => throw new ConnectionException('timeout'));

        $this->onboard('review', self::WABA_SEGURA, '555')
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_number_unverifiable');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    public function test_review_refuses_when_graph_returns_an_error_status(): void
    {
        $this->fakeGraph(Http::response(['error' => ['code' => 200, 'message' => 'Permissions error']], 403));

        $this->onboard('review', self::WABA_SEGURA, '555')
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_number_unverifiable');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    public function test_review_refuses_when_graph_answers_without_the_display_number(): void
    {
        // 200 OK, pero sin el campo. Es el caso más traicionero: parece éxito.
        $this->fakeGraph(Http::response(['id' => '555', 'name' => 'Sin telefono']));

        $this->onboard('review', self::WABA_SEGURA, '555')
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_number_unverifiable');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    // ── 5-6. Lo que SÍ debe pasar la barrera ──────────────────────────────────

    public function test_review_accepts_a_safe_waba_with_a_verified_safe_number(): void
    {
        $this->fakeGraph($this->numeroSeguro());

        $this->onboard('review', self::WABA_SEGURA, '555')->assertOk();

        $fila = WhatsappBusinessIntegration::currentReview();
        $this->assertNotNull($fila);
        $this->assertSame(self::WABA_SEGURA, $fila->waba_id);
    }

    /**
     * Sin `phone_number_id` no hay nada que comprobar, y la ausencia del dato no
     * es sospechosa por sí misma. Rechazarlo aquí cerraría un onboarding válido.
     *
     * Hoy el callback exige el identificador, así que esta prueba comprueba la
     * barrera directamente contra el servicio: cuando el parser de eventos
     * acepte finales sin número, el comportamiento ya estará fijado.
     */
    public function test_review_barrier_allows_an_onboarding_without_a_phone_number_id(): void
    {
        $servicio = app(\App\Services\Meta\WhatsappEmbeddedSignupService::class);

        $metodo = new \ReflectionMethod($servicio, 'assertReviewNumberVerified');
        $metodo->setAccessible(true);

        // No lanza, y no le pregunta nada a la red: no hay número que resolver.
        Http::fake();
        $metodo->invoke($servicio, WhatsappBusinessIntegration::PURPOSE_REVIEW, 'EAA-TOKEN', '');

        Http::assertNothingSent();
    }

    public function test_review_barrier_still_refuses_the_production_waba_without_a_phone_number_id(): void
    {
        $servicio = app(\App\Services\Meta\WhatsappEmbeddedSignupService::class);

        $metodo = new \ReflectionMethod($servicio, 'assertReviewAssetsAllowed');
        $metodo->setAccessible(true);

        $this->expectException(\App\Exceptions\WhatsappOnboardingException::class);

        $metodo->invoke(
            $servicio,
            WhatsappBusinessIntegration::PURPOSE_REVIEW,
            self::WABA_PRODUCTIVA,
            '',
        );
    }

    // ── 7-8. Producción NO cambia ─────────────────────────────────────────────

    public function test_production_is_not_touched_by_the_review_waba_barrier(): void
    {
        $this->fakeGraph($this->numeroSeguro());

        // Es exactamente la WABA que la demostración tiene prohibida, y en
        // coexistencia es el objetivo legítimo del módulo.
        $this->onboard('production', self::WABA_PRODUCTIVA, '555')->assertOk();

        $this->assertNotNull(WhatsappBusinessIntegration::current());
    }

    public function test_production_is_not_touched_by_the_review_number_barrier(): void
    {
        $this->fakeGraph(Http::response([
            'id' => '1', 'display_phone_number' => self::NUMERO_PRODUCTIVO_VISIBLE,
        ]));

        $this->onboard('production', '111', '222')->assertOk();

        $this->assertNotNull(WhatsappBusinessIntegration::current());
    }

    /** Ni siquiera un Graph caído puede frenar una conexión de producción. */
    public function test_production_still_connects_when_graph_cannot_resolve_the_number(): void
    {
        $this->fakeGraph(fn () => throw new ConnectionException('timeout'));

        $this->onboard('production', '111', '222')->assertOk();

        $this->assertNotNull(WhatsappBusinessIntegration::current());
    }

    // ── 9. Cero mutaciones en la demostración ─────────────────────────────────

    public function test_review_never_subscribes_the_app_to_the_waba(): void
    {
        $this->fakeGraph($this->numeroSeguro());

        $this->onboard('review', self::WABA_SEGURA, '555')->assertOk();

        // La única escritura que este servicio hace contra Meta, y no ocurre.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'subscribed_apps'));
    }

    public function test_production_does_subscribe_the_app(): void
    {
        $this->fakeGraph($this->numeroSeguro());

        $this->onboard('production', '111', '222')->assertOk();

        // El contrapunto de la prueba anterior: si esto dejara de pasar, no
        // entraría ni un mensaje y no daría error en ninguna parte.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'subscribed_apps'));
    }
}
