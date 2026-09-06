<?php

namespace Tests\Feature\Wompi;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sincronización de estados: el endpoint /status reconcilia contra Wompi cuando
 * el pago sigue en vuelo; no consulta si ya es final; un fallo temporal de Wompi
 * conserva el estado y responde 200; un pago aprobado por Wompi no se expira por
 * antigüedad; la activación de membresía ocurre una sola vez; y el comando de
 * reconciliación corre sin error de dependencia.
 */
class WompiStatusReconcileTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private Member $member;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox', 'api_url' => 'https://sandbox.wompi.co/v1',
            'public_key' => 'pub_test_x', 'private_key' => 'prv_test_x',
            'integrity_secret' => 'test_integrity_xyz', 'events_secret' => 'test_events_xyz',
            'methods' => ['card' => true, 'pse' => true, 'nequi' => true, 'daviplata' => true],
        ]));

        $this->plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->user = User::create([
            'name' => 'Oscar', 'email' => 'oscar@example.com', 'password' => bcrypt('x'),
            'document' => '1004301550', 'phone' => '3215542105', 'status' => 'pending',
        ]);
        $this->member = Member::create([
            'full_name' => 'Oscar', 'email' => 'oscar@example.com', 'document_number' => '1004301550',
            'phone' => '3215542105', 'status' => Member::STATUS_ACTIVE, 'user_id' => $this->user->id,
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    private function tx(array $overrides = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'uuid' => (string) Str::uuid(), 'reference' => 'IRON-'.Str::random(6),
            'idempotency_key' => (string) Str::uuid(), 'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => 80000, 'currency' => 'COP', 'status' => PaymentStateMachine::PENDING,
            'method' => 'card', 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'wompi_transaction_id' => 'wtx_'.Str::random(6),
        ], $overrides));
    }

    /**
     * Envejece la fila de verdad.
     *
     * `created_at` NO es fillable, así que pasarlo a `create()` se ignora en
     * silencio: los tests que creían estar probando un pago viejo probaban uno
     * recién nacido, y por eso pasaban por la rama de `retry_count` —la que
     * expiraba pagos vivos en producción— sin que nadie lo notara.
     */
    private function age(PaymentTransaction $tx, int $hours): PaymentTransaction
    {
        $tx->forceFill(['created_at' => now()->subHours($hours)])->save();

        return $tx->fresh();
    }

    private function fakeGet(string $status): void
    {
        Http::fake([
            'sandbox.wompi.co/v1/transactions*' => Http::response(['data' => [
                'id' => 'wtx_remote', 'status' => $status, 'amount_in_cents' => 8000000,
                'currency' => 'COP', 'payment_method' => ['type' => 'CARD'],
            ]], 200),
        ]);
    }

    public function test_status_pending_reconciles_and_becomes_approved(): void
    {
        $this->fakeGet('APPROVED');
        $tx = $this->tx();

        $this->getJson('/api/payments/wompi/'.$tx->reference.'/status', $this->auth())
            ->assertOk()
            ->assertJsonPath('status', PaymentStateMachine::APPROVED)
            ->assertJsonPath('is_final', true);

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());

        // Consultar el estado NO es una pasada del job: no gasta su contador ni
        // su marca de tiempo. Mezclarlos fue el fallo de producción.
        $this->assertSame(0, (int) $tx->retry_count);
        $this->assertNull($tx->last_reconciled_at);
    }

    public function test_status_does_not_query_wompi_when_final(): void
    {
        Http::fake(); // si se llamara a Wompi, lo detectaríamos abajo
        $tx = $this->tx(['status' => PaymentStateMachine::APPROVED, 'approved_at' => now(), 'paid_at' => now()]);

        $this->getJson('/api/payments/wompi/'.$tx->reference.'/status', $this->auth())
            ->assertOk()
            ->assertJsonPath('status', PaymentStateMachine::APPROVED);

        Http::assertNothingSent();
    }

    public function test_status_keeps_pending_and_returns_200_on_wompi_error(): void
    {
        // Error temporal de Wompi (HTTP 500).
        Http::fake(['sandbox.wompi.co/v1/transactions*' => Http::response([], 500)]);
        $tx = $this->tx();

        $this->getJson('/api/payments/wompi/'.$tx->reference.'/status', $this->auth())
            ->assertOk()
            ->assertJsonPath('status', PaymentStateMachine::PENDING);

        $this->assertSame(PaymentStateMachine::PENDING, $tx->fresh()->status);
    }

    public function test_reconcile_command_runs_without_dependency_error(): void
    {
        Http::fake(['sandbox.wompi.co/v1/transactions*' => Http::response(['data' => []], 200)]);
        $this->tx(); // un candidato pendiente

        $this->artisan('payments:wompi-reconcile', ['--limit' => 5])
            ->assertExitCode(0);
    }

    public function test_old_pending_approved_in_wompi_is_not_expired(): void
    {
        $this->fakeGet('APPROVED');
        // Pago ANTIGUO y con muchas pasadas del job, pero Wompi lo aprobó.
        $tx = $this->age($this->tx(['retry_count' => 100]), 3);

        $result = WompiReconciliationService::make()->reconcileOne($tx);

        $this->assertSame('updated', $result);
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);
    }

    public function test_old_pending_still_pending_in_wompi_expires(): void
    {
        $this->fakeGet('PENDING');
        $tx = $this->age($this->tx(), 3);

        $result = WompiReconciliationService::make()->reconcileOne($tx);

        $this->assertSame('expired', $result);
        $this->assertSame(PaymentStateMachine::EXPIRED, $tx->fresh()->status);
    }

    /**
     * EL FALLO DE PRODUCCIÓN, REPRODUCIDO.
     *
     * La app consulta el estado cada 2,5 s mientras el socio autoriza en el
     * portal de su banco. Cuando esa consulta llamaba a `reconcileOne()`, las 24
     * pasadas que el job habría tardado dos horas en gastar se consumían en 60
     * segundos y el pago quedaba sellado `expired` estando vivo. Seis
     * transacciones reales murieron así, dos de ellas con Wompi diciendo PENDING.
     */
    public function test_member_polling_does_not_expire_a_live_payment(): void
    {
        $this->fakeGet('PENDING');
        $tx = $this->tx(['method' => 'pse']);

        // 40 consultas: más del doble del antiguo presupuesto de 24.
        for ($i = 0; $i < 40; $i++) {
            $this->getJson('/api/payments/wompi/'.$tx->reference.'/status', $this->auth())
                ->assertOk()
                ->assertJsonPath('is_final', false);
        }

        $fresh = $tx->fresh();
        $this->assertNotSame(PaymentStateMachine::EXPIRED, $fresh->status);
        $this->assertSame(PaymentStateMachine::PENDING, $fresh->status);
        $this->assertSame(0, (int) $fresh->retry_count);
    }

    /**
     * Y su consecuencia grave: si el pago se hubiera aprobado DESPUÉS de que
     * nosotros lo diéramos por vencido, el socio habría pagado sin membresía.
     * `expired` es una inferencia nuestra y cede ante el desenlace real.
     */
    public function test_expired_yields_to_a_later_approval_from_wompi(): void
    {
        $this->fakeGet('APPROVED');
        $tx = $this->tx(['status' => PaymentStateMachine::EXPIRED, 'expires_at' => now()]);

        WompiReconciliationService::make()->refresh($tx);

        $fresh = $tx->fresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $fresh->status);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }

    /** Pero un `expired` NO vuelve a estar en vuelo porque Wompi diga PENDING. */
    public function test_expired_does_not_return_to_in_flight(): void
    {
        $this->fakeGet('PENDING');
        $tx = $this->tx(['status' => PaymentStateMachine::EXPIRED, 'expires_at' => now()]);

        WompiReconciliationService::make()->refresh($tx);

        $this->assertSame(PaymentStateMachine::EXPIRED, $tx->fresh()->status);
    }

    public function test_membership_activation_happens_once(): void
    {
        $this->fakeGet('APPROVED');
        $tx = $this->tx();

        // Dos pasadas de reconciliación (p. ej. /status + job).
        WompiReconciliationService::make()->reconcileOne($tx->fresh());
        WompiReconciliationService::make()->reconcileOne($tx->fresh());

        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }
}
