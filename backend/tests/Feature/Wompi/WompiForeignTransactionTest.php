<?php

namespace Tests\Feature\Wompi;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Marketing\WompiPaymentLinkService;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiNequiPaymentService;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * UNA REFERENCIA PUEDE TENER VARIAS TRANSACCIONES. UN COBRO, UNA SOLA.
 *
 * La `reference` la ponemos nosotros y el checkout se puede abrir más de una
 * vez, así que en Wompi conviven varias transacciones sobre la misma
 * referencia: el intento que se cae y el que pasa. Que el segundo intento se
 * aplique sobre una fila que aún no ha cobrado es el camino normal y tiene que
 * seguir funcionando.
 *
 * Lo que no puede pasar es lo de después de cobrar. El estado ya estaba
 * protegido —`approved` es absorbente—, pero los DATOS no: la transición
 * persiste los atributos no nulos aunque el estado no avance, así que un evento
 * firmado de otra transacción reescribía `wompi_transaction_id` y `provider_ref`
 * de un pago ya cobrado. Dos daños concretos: el rastro contable deja de
 * apuntar al dinero que entró, y si ese id ya es de otra fila la unicidad
 * revienta en una excepción que nadie captura —500 al webhook, y Wompi
 * reintentando contra el mismo choque—.
 */
class WompiForeignTransactionTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Member $member;

    private User $user;

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

        $this->plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->user = User::create([
            'name' => 'Oscar Mancipe', 'email' => 'oscar@example.com', 'password' => bcrypt('x'),
            'document' => '1004301550', 'phone' => '3215542105', 'status' => 'pending',
        ]);
        $this->member = Member::create([
            'full_name' => 'Oscar Mancipe', 'email' => 'oscar@example.com', 'document_number' => '1004301550',
            'phone' => '3215542105', 'status' => Member::STATUS_PENDING_REGISTRATION, 'user_id' => $this->user->id,
        ]);
    }

    private function fakeWompi(): void
    {
        Http::fake([
            'sandbox.wompi.co/v1/merchants/*' => Http::response(['data' => [
                'presigned_acceptance' => ['acceptance_token' => 'accept_tok_123', 'permalink' => 'https://wompi.co/terminos', 'type' => 'END_USER_POLICY'],
                'presigned_personal_data_auth' => ['acceptance_token' => 'personal_tok_456', 'permalink' => 'https://wompi.co/datos', 'type' => 'PERSONAL_DATA_AUTH'],
            ]], 200),
            'sandbox.wompi.co/v1/transactions*' => fn ($request) => Http::response(['data' => [
                'id' => 'wompi-tx-001', 'status' => 'PENDING',
                'reference' => json_decode($request->body(), true)['reference'] ?? 'ref',
                'amount_in_cents' => 8000000, 'currency' => 'COP',
                'payment_method' => ['type' => 'NEQUI'],
            ]], 200),
        ]);
    }

    private function cobroPendiente(): PaymentTransaction
    {
        return WompiNequiPaymentService::make()->process([
            'plan_id' => $this->plan->id, 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'phone' => '3215542105',
            'customer' => ['email' => 'oscar@example.com', 'name' => 'Oscar Mancipe', 'phone' => '3215542105'],
        ], '200.21.179.249', 'phpunit');
    }

    /** Un evento REAL de Wompi: firmado con el secreto de eventos, como el que entra. */
    private function evento(string $reference, string $transactionId, string $status, int $cents = 8000000): array
    {
        $payload = [
            'event' => 'transaction.updated',
            'data' => ['transaction' => [
                'id' => $transactionId, 'status' => $status, 'reference' => $reference,
                'amount_in_cents' => $cents, 'currency' => 'COP',
            ]],
            'environment' => 'test',
            'signature' => [
                'properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
                'checksum' => '',
            ],
            // El timestamp entra en el checksum: dos eventos con distinto sello
            // son dos payloads distintos y ninguno se descarta por duplicado.
            'timestamp' => 1700000000 + crc32($transactionId.$status) % 1000,
        ];
        $payload['signature']['checksum'] = strtoupper(
            (new WompiSignatureService(['events_secret' => 'test_events_xyz']))
                ->computeWebhookChecksum($payload, 'test_events_xyz')
        );

        return $payload;
    }

    private function entregar(array $payload): array
    {
        return WompiWebhookService::make()->handle($payload, (string) json_encode($payload));
    }

    /**
     * Después de cobrar, el evento de OTRA transacción no toca nada.
     *
     * Falla sobre el commit anterior: allí `wompi_transaction_id` pasaba a ser
     * el de la transacción ajena.
     */
    public function test_a_foreign_transaction_never_overwrites_an_approved_payment(): void
    {
        $this->fakeWompi();
        $tx = $this->cobroPendiente();

        $this->entregar($this->evento($tx->reference, 'wompi-tx-001', 'APPROVED'));
        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertSame('wompi-tx-001', $tx->wompi_transaction_id);
        $pagadoEn = $tx->paid_at;

        /*
         * Y esto, además, es dinero cobrado DOS VECES.
         *
         * Dos transacciones aprobadas sobre la misma referencia sólo pasan si
         * alguien abrió el checkout dos veces y completó las dos. El sistema
         * hace lo correcto —no la aplica, no activa dos membresías— pero eso no
         * devuelve nada: hay un cobro real esperando a que alguien lo mire. Se
         * registra como error y no como aviso justamente por eso.
         */
        $spy = Log::spy();
        Log::shouldReceive('channel')->andReturn($spy);
        Log::shouldReceive('getFacadeRoot')->andReturn($spy);

        // Otra transacción de Wompi, misma referencia, firma válida.
        $r = $this->entregar($this->evento($tx->reference, 'wompi-tx-999', 'APPROVED'));

        $spy->shouldHaveReceived('error')
            ->withArgs(fn (string $evento) => $evento === 'wompi.tx.possible_double_charge')
            ->once();

        $this->assertSame(200, $r['http'], 'un 500 haría que Wompi reintentara contra el mismo choque');

        $tx->refresh();
        $this->assertSame('wompi-tx-001', $tx->wompi_transaction_id, 'el rastro contable apunta a otro dinero');
        $this->assertSame('wompi-tx-001', $tx->provider_ref);
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertEquals($pagadoEn, $tx->fresh()->paid_at, 'la fecha del cobro cambió');

        /*
         * Y de nuestro lado hay UN cobro contable y UNA extensión.
         *
         * Ojo con leer esto como «el dinero sigue siendo uno»: no lo es. En la
         * pasarela hay dos transacciones aprobadas, y esta rama existe
         * precisamente porque alguien tiene que mirar la segunda. Lo que se
         * mide aquí es que el sistema no multiplique el servicio, no que no se
         * haya cobrado dos veces —eso ya pasó, y por eso el log es de error—.
         */
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }

    /**
     * Un cobro RECHAZADO no resucita, y eso deja de pasar en silencio.
     *
     * Que la máquina se niegue es el anti doble pago y no se toca: los
     * terminales no salen. Lo que aquí se fija es el contrato completo, que es
     * lo que faltaba —la parte visible—. Si un `approved` firmado por la
     * pasarela llega sobre una fila ya cerrada en otro desenlace, significa que
     * puede haber entrado dinero sin servicio detrás; se registra como error,
     * no como un no-op mudo.
     *
     * El reintento del producto NO es éste: una transacción rechazada no está
     * en vuelo, así que el siguiente enlace se acuña con una referencia nueva
     * ({@see WompiPaymentLinkService}).
     */
    public function test_a_declined_payment_never_revives_and_the_refusal_is_recorded(): void
    {
        $this->fakeWompi();
        $tx = $this->cobroPendiente();

        $this->entregar($this->evento($tx->reference, 'wompi-tx-001', 'DECLINED'));
        $this->assertSame(PaymentStateMachine::DECLINED, $tx->fresh()->status);

        $spy = Log::spy();
        Log::shouldReceive('channel')->andReturn($spy);
        Log::shouldReceive('getFacadeRoot')->andReturn($spy);

        $r = $this->entregar($this->evento($tx->reference, 'wompi-tx-002', 'APPROVED'));
        $this->assertSame(200, $r['http']);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::DECLINED, $tx->status, 'un terminal no sale');
        $this->assertSame('wompi-tx-001', $tx->wompi_transaction_id, 'ni cambia de transacción');
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count(), 'ninguna membresía');

        $spy->shouldHaveReceived('error')
            ->withArgs(fn (string $evento) => $evento === 'wompi.tx.transition_rejected')
            ->atLeast()->once();
    }

    /**
     * Y la única salida que la máquina sí permite sigue abierta.
     *
     * `expired` lo decidimos NOSOTROS por tiempo, no Wompi; si luego la pasarela
     * confirma el desenlace real, manda ella. Una guarda que también cerrara
     * esta puerta dejaría sin membresía a quien pagó justo al filo.
     */
    public function test_an_expired_payment_is_still_corrected_by_the_gateway(): void
    {
        $this->fakeWompi();
        $tx = $this->cobroPendiente();
        $tx->forceFill(['status' => PaymentStateMachine::EXPIRED])->save();

        $r = $this->entregar($this->evento($tx->reference, 'wompi-tx-777', 'APPROVED'));
        $this->assertSame(200, $r['http']);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertSame('wompi-tx-777', $tx->wompi_transaction_id, 'el desenlace real trae su transacción');
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }
}
