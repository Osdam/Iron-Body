<?php

namespace Tests\Feature\Wompi;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Wompi\WompiNequiPaymentService;
use App\Services\Wompi\WompiTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * UNA COMPRA DE TIENDA NO COMPRA UNA MEMBRESÍA (revisión de seguridad, H-1).
 *
 * EL FALLO. En las cuatro vías de pago de la app, `purpose=store` se saltaba
 * todas las comprobaciones del plan, pero `plan_id` seguía viajando hasta la
 * transacción. Al aprobarse el pago, el activador compartido extendía la
 * membresía de ese plan: uno oculto, inactivo, archivado o no vendible se
 * compraba por la puerta de la tienda. Y con un plan de precio 0 el importe lo
 * ponía el cliente (la sonda pagó 1.500 por un año de «ACCESO INTERNO»).
 *
 * LO QUE QUEDA FIJADO
 *   · `purpose=store` con `plan_id` es un 422 en `plan_id`, en las cuatro vías
 *     y con cualquier plan, sin transacción y sin salir nada a Wompi;
 *   · con plan, el importe es SIEMPRE el del plan: un plan sin precio no se
 *     cobra, ni aunque una puerta fallara y llegara al servicio;
 *   · la compra de tienda sin plan sigue igual: cobra el carrito y no da
 *     membresía;
 *   · la compra de un plan vendible y visible sigue igual.
 *
 * Wompi es el doble de siempre (Http::fake): esta prueba nunca sale a la red.
 */
class WompiStorePurposeTest extends TestCase
{
    use RefreshDatabase;

    /** El contrato con la app: el texto exacto del 422. */
    private const MOTIVO = 'Una compra de la tienda no lleva plan de membresía.';

    private User $user;

    private Member $ficha;

    /** @var array<string, Plan> */
    private array $planes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00', 'America/Bogota')->utc());

        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox',
            'api_url' => 'https://sandbox.wompi.co/v1',
            'public_key' => 'pub_test_x',
            'private_key' => 'prv_test_x',
            'integrity_secret' => 'test_integrity_xyz',
            'events_secret' => 'test_events_xyz',
            'methods' => ['card' => true, 'pse' => true, 'nequi' => true, 'daviplata' => true],
            // DaviPlata consulta url_services tras crear: una vez y sin esperar.
            'daviplata' => ['poll_attempts' => 1, 'poll_sleep_ms' => 0],
        ]));
        $this->fakeWompi();

        $this->user = User::create([
            'name' => 'Socia Tienda', 'email' => 'tienda-'.uniqid().'@ironbody.test',
            'password' => 'secret', 'document' => '75111222', 'status' => 'active',
        ]);
        $this->ficha = Member::create([
            'user_id' => $this->user->id, 'full_name' => 'Socia Tienda', 'email' => $this->user->email,
            'document_number' => '75111222', 'phone' => '3110000001',
            'access_hash' => 'tok-tienda-'.uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);

        // Cada estado que la puerta normal rechaza, más el que acepta: con
        // `purpose=store` ninguno puede viajar, tampoco el vendible.
        $this->planes = [
            'vendible' => Plan::create(['name' => 'Élite', 'price' => 150000, 'duration_days' => 30, 'active' => true,
                'sellable' => true, 'visible_in_app' => true]),
            'no_vendible' => Plan::create(['name' => 'Interno', 'price' => 90000, 'duration_days' => 30, 'active' => true,
                'sellable' => false]),
            'oculto' => Plan::create(['name' => 'Oculto', 'price' => 150000, 'duration_days' => 30, 'active' => true,
                'sellable' => true, 'visible_in_app' => false]),
            'inactivo' => Plan::create(['name' => 'Viejo', 'price' => 150000, 'duration_days' => 30, 'active' => false,
                'sellable' => true]),
            'precio_cero' => Plan::create(['name' => 'ACCESO INTERNO', 'price' => 0, 'duration_days' => 365, 'active' => true,
                'sellable' => false]),
        ];
        $archivado = Plan::create(['name' => 'Archivado', 'price' => 150000, 'duration_days' => 30, 'active' => false]);
        $archivado->forceFill(['sellable' => false, 'visible_in_app' => false, 'counter_sellable' => false,
            'archived_at' => now()])->save();
        $this->planes['archivado'] = $archivado->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Las cuatro vías de la app: ruta, lo que cada método pide además de lo
     * común y el tipo con el que Wompi devuelve la transacción.
     */
    public static function vias(): iterable
    {
        yield 'tarjeta' => ['/api/payments/wompi/card', ['card_token' => 'tok_test_1234567890', 'installments' => 1], 'CARD'];
        yield 'pse' => ['/api/payments/wompi/pse', ['financial_institution_code' => '1007', 'user_type' => 'natural',
            'user_legal_id_type' => 'CC', 'user_legal_id' => '75111222'], 'PSE'];
        yield 'nequi' => ['/api/payments/wompi/nequi', ['phone' => '3215542105'], 'NEQUI'];
        yield 'daviplata' => ['/api/payments/wompi/daviplata/start', ['user_legal_id' => '75111222',
            'user_legal_id_type' => 'CC'], 'DAVIPLATA'];
    }

    // ── La puerta de la tienda no lleva plan ────────────────────────────────

    #[DataProvider('vias')]
    public function test_store_con_plan_se_rechaza_sin_crear_nada_con_cualquier_plan(string $ruta, array $metodo): void
    {
        foreach ($this->planes as $estado => $plan) {
            $antes = $this->huella();

            $this->pagar($ruta, $metodo, ['purpose' => 'store', 'plan_id' => $plan->id, 'amount' => 1500])
                ->assertStatus(422)
                ->assertJsonPath('errors.plan_id', [self::MOTIVO]);

            $this->assertSame($antes, $this->huella(), "{$estado}: un 422 no escribe nada");
        }

        $this->assertSame(0, PaymentTransaction::count(), 'ninguna transacción, tampoco con plan');
        Http::assertNothingSent();
        $this->assertNull($this->user->fresh()->membership_end_date, 'sin membresía que activar');
    }

    // ── El importe de un plan es del plan ───────────────────────────────────

    public function test_un_plan_de_precio_cero_no_se_compra_por_ninguna_puerta_de_la_app(): void
    {
        $cero = $this->planes['precio_cero'];
        $nequi = ['phone' => '3215542105'];

        // Por la app no llega: la vía normal lo rechaza (no es vendible en la
        // app) y la de tienda también (lleva plan).
        $this->pagar('/api/payments/wompi/nequi', $nequi, ['plan_id' => $cero->id, 'amount' => 1500])
            ->assertStatus(422)->assertJsonPath('errors.plan_id', ['Ese plan no está disponible para comprarlo en la app.']);
        $this->pagar('/api/payments/wompi/nequi', $nequi, ['purpose' => 'store', 'plan_id' => $cero->id, 'amount' => 1500])
            ->assertStatus(422)->assertJsonPath('errors.plan_id', [self::MOTIVO]);

        $this->assertSame(0, PaymentTransaction::count());
        Http::assertNothingSent();
        $this->assertNull($this->user->fresh()->membership_end_date);
    }

    public function test_el_servicio_no_cobra_un_plan_sin_precio_al_importe_del_cliente(): void
    {
        // La red por si una puerta fallara: con Pricing V2 apagado y encendido,
        // con el plan de precio 0 y con uno que no existe.
        foreach ([false, true] as $v2) {
            config(['billing.pricing.v2_enabled' => $v2]);
            foreach ([$this->planes['precio_cero']->id, 999999] as $planId) {
                $caso = "plan {$planId}, V2 ".($v2 ? 'encendido' : 'apagado');
                $error = null;
                try {
                    WompiNequiPaymentService::make()->process([
                        'plan_id' => $planId, 'amount' => 1500, 'phone' => '3215542105',
                        'member_id' => $this->ficha->id, 'user_id' => $this->user->id,
                        'customer' => ['email' => $this->user->email, 'name' => 'Socia Tienda'],
                    ]);
                } catch (RuntimeException $e) {
                    $error = $e;
                }

                $this->assertNotNull($error, "{$caso}: se cobró al importe del cliente");
                $this->assertSame('El plan no tiene un precio cobrable: el importe de un plan nunca lo fija el cliente.',
                    $error->getMessage(), $caso);
            }
        }

        $this->assertSame(0, PaymentTransaction::count(), 'sin transacción no hay nada que aprobar');
        Http::assertNothingSent();
        $this->assertNull($this->user->fresh()->membership_end_date);
    }

    // ── Lo legítimo sigue igual ─────────────────────────────────────────────

    #[DataProvider('vias')]
    public function test_la_compra_de_tienda_sin_plan_cobra_el_carrito_y_no_da_membresia(string $ruta, array $metodo, string $tipo): void
    {
        $this->pagar($ruta, $metodo, ['purpose' => 'store', 'amount' => 45000])
            ->assertOk()
            ->assertJsonPath('provider', 'wompi')
            ->assertJsonPath('amount', 45000);

        $tx = PaymentTransaction::query()->sole();
        $this->assertNull($tx->plan_id);
        $this->assertSame(45000.0, (float) $tx->amount);
        $this->assertSame(4500000, $this->centavosEnviados(), 'lo firmado y enviado a Wompi es el carrito');

        $this->aprobar($tx, $tipo);

        $pago = Payment::query()->where('reference', $tx->reference)->sole();
        $this->assertNull($pago->plan_id);
        $this->assertSame(45000.0, (float) $pago->amount);
        $this->assertNull($this->user->fresh()->membership_end_date, 'una compra de tienda no da membresía');
    }

    #[DataProvider('vias')]
    public function test_la_compra_de_un_plan_vendible_y_visible_sigue_igual(string $ruta, array $metodo, string $tipo): void
    {
        $vendible = $this->planes['vendible'];

        // Lo que manda la app (sin `purpose`): el `amount` del cliente se ignora.
        $this->pagar($ruta, $metodo, ['plan_id' => $vendible->id, 'amount' => 1500])
            ->assertOk()
            ->assertJsonPath('amount', 150000);

        $tx = PaymentTransaction::query()->sole();
        $this->assertSame($vendible->id, (int) $tx->plan_id);
        $this->assertSame(150000.0, (float) $tx->amount);
        $this->assertSame(15000000, $this->centavosEnviados());

        $this->aprobar($tx, $tipo);

        $pago = Payment::query()->where('reference', $tx->reference)->sole();
        $this->assertSame($vendible->id, (int) $pago->plan_id);
        $this->assertSame(150000.0, (float) $pago->amount);
        $this->assertNotNull($this->user->fresh()->membership_end_date, 'el plan comprado activa la membresía');

        // `purpose=membership` explícito es lo mismo que no mandarlo.
        $this->pagar($ruta, $metodo, ['purpose' => 'membership', 'plan_id' => $vendible->id])->assertOk();
        $this->assertSame($vendible->id, (int) PaymentTransaction::query()->latest('id')->firstOrFail()->plan_id);
    }

    // ────────────────────────────────────────────────────────────────────────

    private function pagar(string $ruta, array $metodo, array $extra): TestResponse
    {
        return $this->postJson($ruta, array_merge([
            'accepted_terms' => true,
            'accepted_personal_data' => true,
            'client_request_id' => 'req-'.uniqid('', true),
        ], $metodo, $extra), ['Authorization' => 'Bearer '.$this->ficha->access_hash]);
    }

    /** Lo que hace el webhook validado: Wompi dice APPROVED. */
    private function aprobar(PaymentTransaction $tx, string $tipo): void
    {
        WompiTransactionService::make()->applyWompiTransaction($tx->fresh(), [
            'id' => $tx->wompi_transaction_id, 'status' => 'APPROVED', 'payment_method' => ['type' => $tipo],
        ]);
    }

    /** Centavos del ÚNICO POST /transactions que salió hacia Wompi. */
    private function centavosEnviados(): int
    {
        $creaciones = Http::recorded(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/transactions'));
        $this->assertCount(1, $creaciones);

        return (int) $creaciones->first()[0]['amount_in_cents'];
    }

    /** Lo que un rechazo no puede tocar. */
    private function huella(): array
    {
        $tablas = ['payment_transactions', 'payment_consents', 'payments', 'electronic_invoices', 'notifications'];
        $out = array_combine($tablas, array_map(fn (string $t) => DB::table($t)->count(), $tablas));
        $out['membresia'] = DB::table('users')->where('id', $this->user->id)->value('membership_end_date');

        return $out;
    }

    private function fakeWompi(): void
    {
        Http::fake([
            'sandbox.wompi.co/v1/merchants/*' => Http::response(['data' => [
                'presigned_acceptance' => ['acceptance_token' => 'a_tok', 'permalink' => 'https://w/terms'],
                'presigned_personal_data_auth' => ['acceptance_token' => 'p_tok', 'permalink' => 'https://w/data'],
            ]], 200),
            'sandbox.wompi.co/v1/transactions*' => function (HttpRequest $r) {
                if ($r->method() === 'POST') {
                    $cuerpo = json_decode($r->body(), true) ?: [];

                    return Http::response(['data' => [
                        'id' => 'wompi-tx-'.uniqid(), 'status' => 'PENDING',
                        'reference' => $cuerpo['reference'] ?? 'ref',
                        'amount_in_cents' => $cuerpo['amount_in_cents'] ?? 0, 'currency' => 'COP',
                        'payment_method' => ['type' => $cuerpo['payment_method']['type'] ?? 'CARD'],
                    ]], 200);
                }

                // La consulta de DaviPlata tras crear: sigue pendiente, sin url_services.
                return Http::response(['data' => [
                    'id' => basename((string) parse_url($r->url(), PHP_URL_PATH)), 'status' => 'PENDING',
                    'currency' => 'COP', 'payment_method' => ['type' => 'DAVIPLATA', 'extra' => []],
                ]], 200);
            },
        ]);
    }
}
