<?php

namespace Tests\Feature\Wompi;

use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Qué datos del checkout PSE llegan realmente a Wompi.
 *
 * EL FALLO QUE SE VIGILA
 * ----------------------
 * El formulario PSE pide correo, teléfono, documento y tipo de documento, pero
 * el correo y el teléfono NO formaban parte del contrato app→backend: el socio
 * escribía `laravel296@gmail.com` y la transacción salía con el del perfil,
 * `johnfloo199@gmail.com`. Eran inputs decorativos.
 *
 * Y peor: el documento viajaba por dos caminos que podían contradecirse. El del
 * checkout llegaba a `payment_method.user_legal_id`, mientras `customer_data`
 * seguía llevando el del perfil. Wompi recibía DOS identidades distintas en la
 * misma transacción, y PSE valida el documento contra el titular de la cuenta
 * bancaria: esa contradicción puede provocar el rechazo por sí sola.
 *
 * En PSE manda el checkout, porque quien autoriza en el banco no tiene por qué
 * ser el titular del perfil.
 */
class WompiPseCheckoutFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Member $member;

    private User $user;

    /** Lo que se envió a Wompi en el POST /transactions. */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox',
            'api_url' => 'https://sandbox.wompi.co/v1',
            'public_key' => 'pub_test_x',
            'private_key' => 'prv_test_x',
            'integrity_secret' => 'test_integrity_xyz',
            'events_secret' => 'test_events_xyz',
            'methods' => ['card' => true, 'pse' => true, 'nequi' => true, 'daviplata' => true],
        ]));

        $this->plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true,
        ]);
        $this->user = User::create([
            'name' => 'John Casas', 'email' => 'johnfloo199@gmail.com',
            'password' => bcrypt('x'), 'document' => '1004301550',
            'phone' => '3215542105', 'status' => 'pending',
        ]);
        $this->member = Member::create([
            'full_name' => 'John Casas', 'email' => 'johnfloo199@gmail.com',
            'document_number' => '1004301550', 'phone' => '3215542105',
            'status' => Member::STATUS_ACTIVE, 'user_id' => $this->user->id,
        ]);

        $this->fakeWompi();
    }

    private function fakeWompi(): void
    {
        Http::fake([
            'sandbox.wompi.co/v1/merchants/*' => Http::response([
                'data' => [
                    'presigned_acceptance' => [
                        'acceptance_token' => 'accept_tok_123',
                        'permalink' => 'https://wompi.co/terminos',
                        'type' => 'END_USER_POLICY',
                    ],
                    'presigned_personal_data_auth' => [
                        'acceptance_token' => 'personal_tok_456',
                        'permalink' => 'https://wompi.co/datos',
                        'type' => 'PERSONAL_DATA_AUTH',
                    ],
                ],
            ], 200),
            'sandbox.wompi.co/v1/pse/financial_institutions' => Http::response([
                'data' => [
                    ['financial_institution_code' => '1809', 'financial_institution_name' => 'NU'],
                ],
            ], 200),
            'sandbox.wompi.co/v1/transactions*' => function ($request) {
                if ($request->method() === 'POST') {
                    $this->sent = json_decode($request->body(), true) ?: [];
                }

                return Http::response([
                    'data' => [
                        'id' => 'wompi-tx-pse-001',
                        'status' => 'PENDING',
                        'reference' => $this->sent['reference'] ?? 'ref',
                        'amount_in_cents' => 8000000,
                        'currency' => 'COP',
                        'payment_method' => ['type' => 'PSE'],
                    ],
                ], 200);
            },
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    /** Lanza un PSE con los campos del checkout que se le pasen. */
    private function payPse(array $checkout = []): void
    {
        $this->postJson('/api/payments/wompi/pse', array_merge([
            'plan_id' => $this->plan->id,
            'financial_institution_code' => '1809',
            'user_type' => 'natural',
            'accepted_terms' => true,
            'accepted_personal_data' => true,
            'client_request_id' => 'req-'.uniqid(),
        ], $checkout), $this->auth());
    }

    // ── El caso reportado ───────────────────────────────────────────────────

    public function test_checkout_email_wins_over_the_profile_email(): void
    {
        $this->payPse(['customer_email' => 'laravel296@gmail.com']);

        $this->assertSame('laravel296@gmail.com', $this->sent['customer_email'] ?? null);
        $this->assertNotSame('johnfloo199@gmail.com', $this->sent['customer_email'] ?? null);
    }

    public function test_checkout_phone_wins_over_the_profile_phone(): void
    {
        $this->payPse(['customer_phone' => '3009998877']);

        $this->assertSame('3009998877', $this->sent['customer_data']['phone_number'] ?? null);
    }

    /**
     * El documento tiene que ser EL MISMO en los dos sitios.
     *
     * PSE lo valida contra el titular de la cuenta bancaria; mandar uno en
     * `payment_method` y otro en `customer_data` es pedirle al banco que decida
     * cuál de los dos se cree.
     */
    public function test_checkout_document_is_consistent_in_both_places(): void
    {
        $this->payPse([
            'user_legal_id' => '79123456',
            'user_legal_id_type' => 'CE',
        ]);

        $this->assertSame('79123456', $this->sent['payment_method']['user_legal_id'] ?? null);
        $this->assertSame('CE', $this->sent['payment_method']['user_legal_id_type'] ?? null);

        // Y lo mismo en customer_data, que antes llevaba el del perfil y 'CC'.
        $this->assertSame('79123456', $this->sent['customer_data']['legal_id'] ?? null);
        $this->assertSame('CE', $this->sent['customer_data']['legal_id_type'] ?? null);
        $this->assertNotSame('1004301550', $this->sent['customer_data']['legal_id'] ?? null);
    }

    // ── Fallback explícito ──────────────────────────────────────────────────

    public function test_empty_checkout_fields_fall_back_to_the_profile(): void
    {
        $this->payPse([
            'customer_email' => '',
            'customer_phone' => '',
        ]);

        $this->assertSame('johnfloo199@gmail.com', $this->sent['customer_email'] ?? null);
        $this->assertSame('3215542105', $this->sent['customer_data']['phone_number'] ?? null);
    }

    public function test_absent_checkout_fields_fall_back_to_the_profile(): void
    {
        $this->payPse();

        $this->assertSame('johnfloo199@gmail.com', $this->sent['customer_email'] ?? null);
        $this->assertSame('1004301550', $this->sent['customer_data']['legal_id'] ?? null);
    }

    // ── Los campos que ya funcionaban siguen funcionando ────────────────────

    public function test_bank_person_type_and_document_type_travel_intact(): void
    {
        $this->payPse([
            'user_type' => 'juridica',
            'user_legal_id' => '900123456',
            'user_legal_id_type' => 'NIT',
        ]);

        $pm = $this->sent['payment_method'] ?? [];
        $this->assertSame('PSE', $pm['type'] ?? null);
        $this->assertSame('1809', $pm['financial_institution_code'] ?? null);
        $this->assertSame(1, $pm['user_type'] ?? null, 'jurídica = 1');
        $this->assertSame('NIT', $pm['user_legal_id_type'] ?? null);
        $this->assertSame('900123456', $pm['user_legal_id'] ?? null);
    }

    public function test_natural_person_maps_to_zero(): void
    {
        $this->payPse(['user_type' => 'natural', 'user_legal_id' => '1004301550']);

        $this->assertSame(0, $this->sent['payment_method']['user_type'] ?? null);
    }

    // ── Validación ──────────────────────────────────────────────────────────

    public function test_a_malformed_checkout_email_is_rejected(): void
    {
        $this->postJson('/api/payments/wompi/pse', [
            'plan_id' => $this->plan->id,
            'financial_institution_code' => '1809',
            'customer_email' => 'no-es-un-correo',
            'accepted_terms' => true,
            'accepted_personal_data' => true,
        ], $this->auth())->assertStatus(422);
    }

    /**
     * La identidad del COBRO sigue siendo la del miembro autenticado. Que el
     * checkout mande los datos de contacto del pagador no puede convertirse en
     * una vía para pagarle la membresía a otra persona.
     */
    public function test_the_charge_still_belongs_to_the_authenticated_member(): void
    {
        $otro = Member::create([
            'full_name' => 'Ajeno', 'email' => 'ajeno@example.com',
            'document_number' => '999', 'phone' => '3000000000',
            'status' => Member::STATUS_ACTIVE,
        ]);

        $this->payPse([
            'customer_email' => 'laravel296@gmail.com',
            'member_id' => $otro->id,
            'user_id' => 999999,
        ]);

        $tx = PaymentTransaction::where('method', 'pse')->latest('id')->first();
        $this->assertNotNull($tx);
        $this->assertSame($this->member->id, $tx->member_id);
        $this->assertSame($this->user->id, $tx->user_id);
    }
}
