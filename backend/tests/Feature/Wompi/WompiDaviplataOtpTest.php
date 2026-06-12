<?php

namespace Tests\Feature\Wompi;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiDaviplataPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ciclo OTP nativo de DaviPlata: extracción de url_services (con espera), envío
 * (guarda access_token), validación (usa el nuevo token, envía `code` no `otp`),
 * reenvío (reemplaza token), anti-duplicados, aprobación 574829 y resiliencia.
 */
class WompiDaviplataOtpTest extends TestCase
{
    use RefreshDatabase;

    private const SEND = 'https://api.wompi.test/otp/send';
    private const VALIDATE = 'https://api.wompi.test/otp/validate';

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
            'methods' => ['daviplata' => true],
            'daviplata' => ['poll_attempts' => 4, 'poll_sleep_ms' => 0, 'otp_ttl_minutes' => 10, 'max_attempts' => 3, 'max_resends' => 2],
        ]));
        $this->plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->user = User::create(['name' => 'O', 'email' => 'o@e.co', 'password' => bcrypt('x'), 'document' => '123', 'phone' => '3000000000', 'status' => 'pending']);
        $this->member = Member::create(['full_name' => 'O', 'email' => 'o@e.co', 'document_number' => '123', 'phone' => '3000000000', 'status' => Member::STATUS_ACTIVE, 'user_id' => $this->user->id]);
    }

    private function svc(): WompiDaviplataPaymentService
    {
        return WompiDaviplataPaymentService::make();
    }

    private function tx(array $otp = []): PaymentTransaction
    {
        $meta = $otp ? ['otp' => $otp] : null;
        return PaymentTransaction::create([
            'uuid' => (string) Str::uuid(), 'reference' => 'IRON-'.Str::random(6),
            'idempotency_key' => (string) Str::uuid(), 'provider' => 'wompi', 'environment' => 'sandbox',
            'amount' => 80000, 'currency' => 'COP', 'status' => PaymentStateMachine::PENDING,
            'method' => 'daviplata', 'member_id' => $this->member->id, 'user_id' => $this->user->id,
            'wompi_transaction_id' => 'wtx_'.Str::random(6), 'metadata' => $meta,
        ]);
    }

    private function txWithServices(string $status = 'PENDING'): array
    {
        return ['data' => [
            'id' => 'wtx_remote', 'status' => $status, 'amount_in_cents' => 8000000, 'currency' => 'COP',
            'payment_method' => ['type' => 'DAVIPLATA', 'extra' => ['url_services' => [
                'token' => 'tok_initial_123',
                'code_otp_send' => self::SEND,
                'code_otp_validate' => self::VALIDATE,
            ]]],
        ]];
    }

    private function txNoServices(): array
    {
        return ['data' => [
            'id' => 'wtx_remote', 'status' => 'PENDING', 'amount_in_cents' => 8000000, 'currency' => 'COP',
            'payment_method' => ['type' => 'DAVIPLATA', 'extra' => []],
        ]];
    }

    public function test_extracts_url_services_waiting_and_send_saves_access_token(): void
    {
        // 1ª consulta SIN url_services, 2ª CON (prueba la espera con backoff).
        Http::fake([
            'sandbox.wompi.co/v1/transactions/*' => Http::sequence()
                ->push($this->txNoServices(), 200)
                ->push($this->txWithServices(), 200)
                ->whenEmpty(Http::response($this->txWithServices(), 200)),
            self::SEND => Http::response(['data' => ['authorization' => ['access_token' => 'access_456']]], 200),
        ]);

        $tx = $this->tx();
        $res = $this->svc()->sendOtp($tx);

        $this->assertTrue($res['ok']);
        $otp = $tx->fresh()->metadata['otp'];
        $this->assertSame(self::SEND, $otp['send_url']);
        $this->assertSame(self::VALIDATE, $otp['validate_url']);
        $this->assertSame('tok_initial_123', $otp['initial_token']);
        $this->assertSame('access_456', $otp['access_token']);

        // El primer envío usa como Bearer el token INICIAL de url_services.
        Http::assertSent(fn ($r) => $r->url() === self::SEND
            && $r->hasHeader('Authorization', 'Bearer tok_initial_123'));
    }

    public function test_validate_uses_new_token_sends_code_not_otp_and_approves(): void
    {
        Http::fake([
            self::VALIDATE => Http::response(['data' => ['authorization' => ['access_token' => 'access_789']]], 200),
            'sandbox.wompi.co/v1/transactions/*' => Http::response($this->txWithServices('APPROVED'), 200),
        ]);

        $tx = $this->tx([
            'send_url' => self::SEND, 'validate_url' => self::VALIDATE,
            'initial_token' => 'tok_initial_123', 'access_token' => 'access_456',
            'attempts' => 0, 'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        $res = $this->svc()->validateOtp($tx, '574829');
        $this->assertTrue($res['ok']);

        Http::assertSent(function ($r) {
            return $r->url() === self::VALIDATE
                && $r['code'] === '574829'           // envía `code`
                && ! isset($r['otp'])                 // NUNCA `otp`
                && $r->hasHeader('Authorization', 'Bearer access_456'); // último token
        });

        $tx->refresh();
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }

    public function test_resend_replaces_access_token(): void
    {
        Http::fake([
            self::SEND => Http::response(['data' => ['authorization' => ['access_token' => 'access_999']]], 200),
        ]);
        $tx = $this->tx([
            'send_url' => self::SEND, 'validate_url' => self::VALIDATE,
            'initial_token' => 'tok_initial_123', 'access_token' => 'access_456',
            'resends' => 0, 'attempts' => 2,
        ]);

        $res = $this->svc()->resendOtp($tx);
        $this->assertTrue($res['ok']);

        $otp = $tx->fresh()->metadata['otp'];
        $this->assertSame('access_999', $otp['access_token']); // reemplazado
        $this->assertSame(0, $otp['attempts']);                // reiniciado
        // El reenvío usa como Bearer el token VIGENTE (access_456).
        Http::assertSent(fn ($r) => $r->url() === self::SEND
            && $r->hasHeader('Authorization', 'Bearer access_456'));
    }

    public function test_double_tap_does_not_duplicate_calls(): void
    {
        Http::fake([self::SEND => Http::response(['data' => ['authorization' => ['access_token' => 'x']]], 200)]);
        $tx = $this->tx([
            'send_url' => self::SEND, 'validate_url' => self::VALIDATE, 'initial_token' => 'tok_initial_123',
        ]);

        // Simula que ya hay una operación OTP en curso para esta referencia.
        $held = Cache::lock('wompi:daviplata:otp:'.$tx->reference, 10);
        $this->assertTrue($held->get());

        $res = $this->svc()->sendOtp($tx);
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['busy']);
        Http::assertNotSent(fn ($r) => $r->url() === self::SEND);

        $held->release();
    }

    public function test_temporary_wompi_error_does_not_destroy_transaction(): void
    {
        Http::fake([self::SEND => Http::response([], 500)]);
        $tx = $this->tx([
            'send_url' => self::SEND, 'validate_url' => self::VALIDATE,
            'initial_token' => 'tok_initial_123', 'access_token' => 'access_456',
        ]);

        $res = $this->svc()->sendOtp($tx);
        $this->assertFalse($res['ok']);
        $this->assertArrayNotHasKey('busy', $res);

        // La transacción SIGUE viva (pending), no se marca error/expired.
        $this->assertSame(PaymentStateMachine::PENDING, $tx->fresh()->status);
    }

    public function test_url_services_unavailable_returns_controlled_preparing(): void
    {
        // Nunca llegan url_services → tras el backoff, error controlado (no terminal).
        Http::fake(['sandbox.wompi.co/v1/transactions/*' => Http::response($this->txNoServices(), 200)]);
        $tx = $this->tx();

        $res = $this->svc()->sendOtp($tx);
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['preparing']);
        $this->assertSame(PaymentStateMachine::PENDING, $tx->fresh()->status);
    }
}
