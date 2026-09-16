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
 * El final de la COEXISTENCIA, que hasta ahora no se podía completar.
 *
 * Meta cierra ese flujo con `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`, y su
 * payload oficial lleva un único campo:
 *
 *     { data: { waba_id: "…" }, type: "WA_EMBEDDED_SIGNUP",
 *       event: "FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING", version: 3 }
 *
 * NO trae `phone_number_id`, porque en coexistencia el número ya estaba dado de
 * alta en la app WhatsApp Business y no se acaba de registrar. El callback lo
 * exigía como obligatorio, así que el recorrido moría con un 422 incluso cuando
 * Meta lo había completado.
 *
 * Ahora el número se resuelve contra Graph, con el token del canje y sobre la
 * WABA que el propio diálogo autorizó. Lo que estas pruebas fijan es que esa
 * resolución NO sea una puerta trasera: falla cerrado si Graph no contesta, y
 * vuelve a pasar por las barreras de activos protegidos con el número ya
 * resuelto, no solo con el que llegó (que no llegó).
 */
class WhatsappCoexistenceOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/integrations/whatsapp';

    /** La cuenta que opera el canal del negocio. */
    private const WABA_PRODUCTIVA = '1381789767207733';

    /** Una WABA cualquiera que no es la productiva. */
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
            'name' => 'Super QA', 'email' => 'super-coexistence@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->headers = $this->actingAsAdmin($admin);
    }

    /**
     * Una sola clausura que despacha por URL. Con un array de patrones, una
     * clausura entre los valores se aplica a TODAS las peticiones e ignora los
     * patrones declarados antes.
     *
     * @param  mixed  $listaDeNumeros  lo que devuelve GET /{waba}/phone_numbers
     * @param  mixed  $lecturaDelNumero  lo que devuelve GET /{phone_number_id}
     */
    private function fakeGraph(mixed $listaDeNumeros, mixed $lecturaDelNumero = null): void
    {
        $lecturaDelNumero ??= $this->numeroSeguro();

        Http::fake(function ($request) use ($listaDeNumeros, $lecturaDelNumero) {
            $url = $request->url();

            if (str_contains($url, 'oauth/access_token')) {
                return Http::response(['access_token' => 'EAA-TOKEN']);
            }

            if (str_contains($url, 'debug_token')) {
                return Http::response([
                    'data' => ['scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging']],
                ]);
            }

            if (str_contains($url, 'subscribed_apps')) {
                return Http::response(['success' => true]);
            }

            // La búsqueda del número a partir de la cuenta: lo nuevo.
            if (str_contains($url, '/phone_numbers')) {
                return is_callable($listaDeNumeros) ? $listaDeNumeros($request) : $listaDeNumeros;
            }

            // Leer un activo suelto: el número que se verifica, o el WABA.
            return is_callable($lecturaDelNumero) ? $lecturaDelNumero($request) : $lecturaDelNumero;
        });
    }

    private function numeroSeguro(): PromiseInterface
    {
        return Http::response([
            'id' => '555', 'name' => 'Demo',
            'display_phone_number' => '+57 300 000 0000',
        ]);
    }

    /** GET /{waba}/phone_numbers con un número dentro. */
    private function listaConNumero(string $id = '555'): PromiseInterface
    {
        return Http::response(['data' => [
            ['id' => $id, 'display_phone_number' => '+57 300 000 0000', 'platform_type' => 'CLOUD_API'],
        ]]);
    }

    /**
     * Cierra el diálogo como lo haría cada evento de Meta.
     * `$phone` a null reproduce FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING.
     */
    private function onboard(string $mode, string $waba, ?string $phone): TestResponse
    {
        $state = $this->postJson(self::URL.'/start', ['mode' => $mode], $this->headers)
            ->json('data.state');

        $cuerpo = ['code' => 'AQD-codigo', 'state' => $state, 'mode' => $mode, 'waba_id' => $waba];

        if ($phone !== null) {
            $cuerpo['phone_number_id'] = $phone;
        }

        return $this->postJson(self::URL.'/callback', $cuerpo, $this->headers);
    }

    // ── A y B. El evento de coexistencia, sin número ──────────────────────────

    public function test_coexistence_completes_with_only_a_waba_id(): void
    {
        $this->fakeGraph($this->listaConNumero('900111'));

        $this->onboard('production', self::WABA_SEGURA, null)->assertOk();

        $fila = WhatsappBusinessIntegration::current();
        $this->assertNotNull($fila, 'la coexistencia no persistió la conexión');
        $this->assertSame(self::WABA_SEGURA, $fila->waba_id);
        $this->assertSame('900111', $fila->phone_number_id, 'el número no se resolvió desde la cuenta');
    }

    public function test_the_missing_phone_number_id_is_resolved_from_the_authorized_waba(): void
    {
        $this->fakeGraph($this->listaConNumero('900111'));

        $this->onboard('production', self::WABA_SEGURA, null)->assertOk();

        // Se preguntó por los números DE ESA cuenta, no de ninguna otra.
        Http::assertSent(fn ($r) => str_contains($r->url(), self::WABA_SEGURA.'/phone_numbers'));
    }

    /** El callback ya no rechaza por sí solo un cuerpo sin número. */
    public function test_the_callback_no_longer_rejects_a_body_without_a_phone_number_id(): void
    {
        $this->fakeGraph($this->listaConNumero());

        $this->onboard('production', self::WABA_SEGURA, null)
            ->assertOk()
            ->assertJsonPath('data.status', 'connected');
    }

    // ── Falla CERRADO: el número se resuelve o no se guarda nada ──────────────

    public function test_it_refuses_when_the_waba_has_no_phone_numbers_yet(): void
    {
        $this->fakeGraph(Http::response(['data' => []]));

        $this->onboard('production', self::WABA_SEGURA, null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'phone_number_not_resolvable');

        $this->assertSame(0, WhatsappBusinessIntegration::count(), 'no puede quedar ni rastro');
    }

    public function test_it_refuses_when_the_phone_lookup_fails(): void
    {
        $this->fakeGraph(fn () => throw new ConnectionException('timeout'));

        $this->onboard('production', self::WABA_SEGURA, null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'phone_number_not_resolvable');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    public function test_it_refuses_when_the_phone_lookup_returns_an_error_status(): void
    {
        $this->fakeGraph(Http::response(['error' => ['code' => 200, 'message' => 'Permissions error']], 403));

        $this->onboard('production', self::WABA_SEGURA, null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'phone_number_not_resolvable');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    /** Nunca se inventa: sin respuesta utilizable, no hay fila que valga. */
    public function test_it_never_falls_back_to_the_configured_phone_number_id(): void
    {
        config()->set('meta.whatsapp_phone_number_id', '1221649421024405');
        $this->fakeGraph(Http::response(['data' => []]));

        $this->onboard('production', self::WABA_SEGURA, null)->assertStatus(422);

        $this->assertSame(0, WhatsappBusinessIntegration::count());
        $this->assertNull(
            WhatsappBusinessIntegration::query()->where('phone_number_id', '1221649421024405')->first(),
            'se coló el identificador del .env',
        );
    }

    // ── Varios números: no se elige, se para ──────────────────────────────────

    /**
     * El agujero que esto cierra: coger `data[0]`.
     *
     * Meta no documenta que ese array venga ordenado, y el evento de
     * coexistencia no dice qué número participó. Elegir el primero era decidir
     * a ciegas sobre qué número va a operar el canal del negocio.
     */
    public function test_it_refuses_when_the_waba_has_more_than_one_number(): void
    {
        $this->fakeGraph(Http::response(['data' => [
            ['id' => '900111', 'display_phone_number' => '+57 300 000 0001'],
            ['id' => '900222', 'display_phone_number' => '+57 300 000 0002'],
        ]]));

        $this->onboard('production', self::WABA_SEGURA, null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'phone_number_ambiguous');

        $this->assertSame(0, WhatsappBusinessIntegration::count(), 'no puede quedar ni rastro');
    }

    public function test_with_several_numbers_it_never_takes_the_first_one(): void
    {
        $this->fakeGraph(Http::response(['data' => [
            ['id' => '900111', 'display_phone_number' => '+57 300 000 0001'],
            ['id' => '900222', 'display_phone_number' => '+57 300 000 0002'],
        ]]));

        $this->onboard('production', self::WABA_SEGURA, null)->assertStatus(422);

        foreach (['900111', '900222'] as $id) {
            $this->assertNull(
                WhatsappBusinessIntegration::query()->where('phone_number_id', $id)->first(),
                "se eligió {$id} sin poder saber si era el correcto",
            );
        }
    }

    public function test_with_several_numbers_it_neither_subscribes_nor_falls_back_to_the_env(): void
    {
        config()->set('meta.whatsapp_phone_number_id', '1221649421024405');
        $this->fakeGraph(Http::response(['data' => [
            ['id' => '900111'], ['id' => '900222'], ['id' => '900333'],
        ]]));

        $this->onboard('production', self::WABA_SEGURA, null)->assertStatus(422);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'subscribed_apps'));
        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    /** Exactamente uno sí se usa: la ambigüedad empieza en dos. */
    public function test_exactly_one_number_still_resolves(): void
    {
        $this->fakeGraph(Http::response(['data' => [
            ['id' => '900111', 'display_phone_number' => '+57 300 000 0000'],
        ]]));

        $this->onboard('production', self::WABA_SEGURA, null)->assertOk();

        $this->assertSame('900111', WhatsappBusinessIntegration::current()?->phone_number_id);
    }

    /** Y en review la ambigüedad también para, antes de tocar nada. */
    public function test_review_also_refuses_an_ambiguous_account(): void
    {
        $this->fakeGraph(Http::response(['data' => [
            ['id' => '900111'], ['id' => '900222'],
        ]]));

        $this->onboard('review', self::WABA_SEGURA, null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'phone_number_ambiguous');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    // ── C. El final tradicional no cambia ─────────────────────────────────────

    public function test_the_traditional_finish_still_works_untouched(): void
    {
        $this->fakeGraph($this->listaConNumero());

        $this->onboard('production', '111', '222')->assertOk();

        $fila = WhatsappBusinessIntegration::current();
        $this->assertNotNull($fila);
        $this->assertSame('222', $fila->phone_number_id, 'se pisó el número que SÍ mandó Meta');
    }

    public function test_the_traditional_finish_does_not_ask_graph_for_the_phone_list(): void
    {
        $this->fakeGraph($this->listaConNumero());

        $this->onboard('production', '111', '222')->assertOk();

        // Si Meta ya dio el número, preguntar otra vez sobra.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/phone_numbers'));
    }

    // ── F y G. Las barreras siguen en pie con el número resuelto ──────────────

    public function test_review_still_refuses_the_production_waba_without_a_phone_number_id(): void
    {
        $this->fakeGraph($this->listaConNumero());

        $this->onboard('review', self::WABA_PRODUCTIVA, null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_waba');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
        // La barrera actúa ANTES del canje: el código no llegó a gastarse.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'oauth/access_token'));
    }

    /**
     * La puerta trasera que este parche NO puede abrir: llegar sin número a una
     * WABA permitida y que el resuelto resulte ser el del gimnasio.
     */
    public function test_review_refuses_when_the_resolved_number_is_the_protected_one(): void
    {
        $this->fakeGraph(
            $this->listaConNumero('900111'),
            Http::response(['id' => '900111', 'display_phone_number' => self::NUMERO_PRODUCTIVO_VISIBLE]),
        );

        $this->onboard('review', self::WABA_SEGURA, null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_number');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    public function test_review_refuses_when_the_resolved_phone_number_id_is_protected(): void
    {
        config()->set('meta.embedded_signup.review.protected_phone_number_ids', ['900111']);
        $this->fakeGraph($this->listaConNumero('900111'));

        $this->onboard('review', self::WABA_SEGURA, null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_number');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    public function test_review_refuses_when_the_resolved_number_cannot_be_verified(): void
    {
        $this->fakeGraph(
            $this->listaConNumero('900111'),
            Http::response(['id' => '900111', 'name' => 'sin telefono']),
        );

        $this->onboard('review', self::WABA_SEGURA, null)
            ->assertStatus(422)
            ->assertJsonPath('code', 'protected_number_unverifiable');

        $this->assertSame(0, WhatsappBusinessIntegration::count());
    }

    public function test_review_without_a_phone_number_id_still_never_subscribes_the_app(): void
    {
        $this->fakeGraph($this->listaConNumero('900111'));

        $this->onboard('review', self::WABA_SEGURA, null)->assertOk();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'subscribed_apps'));
    }

    /** Coexistencia NO puede adoptar un par que ya es de la demostración. */
    public function test_coexistence_cannot_take_over_a_pair_owned_by_review(): void
    {
        $this->fakeGraph($this->listaConNumero('900111'));
        $this->onboard('review', self::WABA_SEGURA, null)->assertOk();

        $this->onboard('production', self::WABA_SEGURA, null)
            ->assertStatus(409)
            ->assertJsonPath('code', 'purpose_conflict');

        $this->assertSame(1, WhatsappBusinessIntegration::count());
        $this->assertNull(WhatsappBusinessIntegration::current(), 'la demostración pasó a operar el canal');
    }
}
