<?php

namespace Tests\Feature\Chaos;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Plan;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiNequiPaymentService;
use App\Services\Wompi\WompiReconciliationService;
use Illuminate\Support\Facades\Http;

/**
 * F6.19 – F6.25 · Fallos de la pasarela de pagos.
 *
 * Es la familia de escenarios donde un error de diseño no se paga con un log
 * feo, sino con el dinero de una persona. Por eso la regla es más estricta que
 * en el resto de la fase: aquí no basta con «no se rompió». Un cobro solo puede
 * terminar en tres sitios —cobrado una vez, no cobrado, o explícitamente
 * indeterminado— y el tercero no es un fallo del sistema, es el único final
 * honesto cuando la pasarela deja de contestar a media transacción.
 */
class ChaosPaymentsTest extends ChaosTestCase
{
    private Plan $plan;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = $this->plan('Mensual', 90000);
        $this->member = $this->member();
    }

    /** Tokens de aceptación siempre disponibles: no son el objeto de estas pruebas. */
    private function fakeAcceptance(array $extra = []): void
    {
        Http::fake(array_merge([
            'sandbox.wompi.co/v1/merchants/*' => Http::response([
                'data' => [
                    'presigned_acceptance' => [
                        'acceptance_token' => 'accept_tok_chaos',
                        'permalink' => 'https://wompi.co/terminos',
                        'type' => 'END_USER_POLICY',
                    ],
                    'presigned_personal_data_auth' => [
                        'acceptance_token' => 'personal_tok_chaos',
                        'permalink' => 'https://wompi.co/datos',
                        'type' => 'PERSONAL_DATA_AUTH',
                    ],
                ],
            ], 200),
        ], $extra));
    }

    private function chargeNequi(): PaymentTransaction
    {
        return WompiNequiPaymentService::make()->process([
            'plan_id' => $this->plan->id,
            'member_id' => $this->member->id,
            'user_id' => $this->member->user_id,
            'phone' => '3001112233',
            'customer' => ['email' => 'chaos@ironbody.test', 'phone' => '3001112233'],
        ]);
    }

    // ── F6.19 ───────────────────────────────────────────────────────────

    /**
     * F6.19 — La pasarela deja de contestar a mitad del POST /transactions.
     *
     * El caso más peligroso de todo el sistema, y el menos intuitivo: un
     * timeout NO significa que no se cobró. Significa que no sabemos. Wompi
     * pudo haber recibido la petición, creado la transacción y cobrado; lo
     * único que se perdió fue la respuesta.
     *
     * Por eso el estado no puede ser `error`: `error` es terminal y afirma algo
     * —«esto falló»— que nadie ha comprobado. Tiene que quedar en vuelo, que es
     * lo que la reconciliación y el webhook saben resolver después.
     */
    public function test_f619_wompi_timeout_deja_el_pago_indeterminado_no_fallido(): void
    {
        $this->fakeAcceptance([
            'sandbox.wompi.co/v1/transactions*' => $this->timeout(),
        ]);

        $tx = $this->chargeNequi();

        $this->assertFalse(
            (new PaymentStateMachine)->isTerminal($tx->status),
            "Un timeout dejó el pago en estado terminal '{$tx->status}'. "
            .'Nadie ha comprobado que ese cobro fallara: si Wompi lo aprobó, '
            .'el cliente pagó y el estado dice que no.',
        );

        // Ni aprobado ni membresía: tampoco se puede afirmar lo contrario.
        $this->assertNotSame(PaymentStateMachine::APPROVED, $tx->status);
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count());

        // Y no se creó un segundo intento por su cuenta.
        $this->assertSame(1, PaymentTransaction::where('member_id', $this->member->id)->count());
    }

    /**
     * F6.19b — Reintentar tras el timeout NO abre un segundo cobro.
     *
     * El cliente vuelve a pulsar «pagar» porque la app se quedó colgada. Si eso
     * creara una transacción nueva mientras la primera sigue viva en Wompi, se
     * cobraría dos veces.
     */
    public function test_f619b_reintento_del_cliente_reutiliza_el_intento_en_vuelo(): void
    {
        $this->fakeAcceptance(['sandbox.wompi.co/v1/transactions*' => $this->timeout()]);
        $first = $this->chargeNequi();

        $this->fakeAcceptance(['sandbox.wompi.co/v1/transactions*' => $this->timeout()]);
        $second = $this->chargeNequi();

        $this->assertSame($first->id, $second->id, 'El reintento creó un segundo cobro.');
        $this->assertSame(1, PaymentTransaction::where('member_id', $this->member->id)->count());
    }

    // ── F6.20 / F6.21 ───────────────────────────────────────────────────

    /**
     * F6.20 — 429 de Wompi: no se martillea y no se duplica la intención.
     *
     * Un POST con efecto de cobro no se reintenta a ciegas: ese es el diseño de
     * `WompiClient` y aquí se comprueba que se cumple, contando peticiones.
     */
    public function test_f620_wompi_429_no_reintenta_el_post_ni_duplica_la_intencion(): void
    {
        $this->fakeAcceptance([
            'sandbox.wompi.co/v1/transactions*' => $this->httpStatus(429, [
                'error' => ['type' => 'RATE_LIMIT', 'reason' => 'Demasiadas peticiones'],
            ], ['Retry-After' => '30']),
        ]);

        $tx = $this->chargeNequi();

        $posts = 0;
        Http::recorded(function ($request) use (&$posts) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/transactions')) {
                $posts++;
            }

            return true;
        });

        $this->assertSame(1, $posts, 'El POST de cobro se reintentó a ciegas: eso es un doble cobro potencial.');
        $this->assertSame(1, PaymentTransaction::where('member_id', $this->member->id)->count());
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count());
    }

    /**
     * F6.21 — 500 de Wompi: la referencia y la clave de idempotencia sobreviven.
     *
     * Sin ellas no hay forma de reconciliar después: son el único hilo que une
     * nuestro intento con lo que sea que haya quedado del otro lado.
     */
    public function test_f621_wompi_500_preserva_referencia_e_idempotency_key(): void
    {
        $this->fakeAcceptance([
            'sandbox.wompi.co/v1/transactions*' => $this->httpStatus(500, [
                'error' => ['type' => 'SERVER_ERROR', 'reason' => 'Algo se rompió'],
            ]),
        ]);

        $tx = $this->chargeNequi();

        $this->assertNotEmpty($tx->reference);
        $this->assertNotEmpty($tx->idempotency_key);
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count());

        // La cabecera de idempotencia viajó de verdad: es lo que permite a Wompi
        // reconocer el reintento como el mismo cobro y no como uno nuevo.
        Http::assertSent(fn ($request) => $request->method() !== 'POST'
            || ! str_contains($request->url(), '/transactions')
            || $request->hasHeader('Idempotency-Key', $tx->idempotency_key));
    }

    // ── F6.22 ───────────────────────────────────────────────────────────

    /**
     * F6.22 — Wompi aprueba DESPUÉS del timeout.
     *
     * El escenario que decide si el diseño de F6.19 sirve de algo. La petición
     * inicial se pierde; minutos después llega el webhook diciendo que ese
     * cobro fue aprobado. El cliente pagó. Tiene que quedar: una venta, una
     * membresía, un evento comercial. Ni cero ni dos.
     *
     * Cero es el fallo silencioso más caro que puede tener este sistema: alguien
     * pagó, el gimnasio cobró, y el software dice que no pasó nada.
     */
    public function test_f622_aprobacion_posterior_al_timeout_produce_exactamente_una_venta(): void
    {
        $this->fakeAcceptance(['sandbox.wompi.co/v1/transactions*' => $this->timeout()]);
        $tx = $this->chargeNequi();

        // Wompi sí lo había cobrado: el webhook llega después con la verdad.
        $this->wompiWebhook($this->wompiTransactionEvent(
            $tx->reference, 'APPROVED', 9000000,
        ))->assertOk();

        $fresh = $tx->fresh();

        $this->assertSame(
            PaymentStateMachine::APPROVED, $fresh->status,
            'El cliente pagó y el pago no quedó aprobado. El timeout previo enterró un cobro real.',
        );
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count(), 'La venta no es exactamente una.');
        $this->assertNotNull($fresh->paid_at);
    }

    /**
     * F6.22b — Y si además llega la reconciliación, sigue siendo UNA venta.
     *
     * Webhook y reconciliación son dos caminos hacia el mismo hecho. Que
     * converjan sin duplicar es justamente lo que hace seguro tener los dos.
     */
    public function test_f622b_webhook_y_reconciliacion_convergen_en_una_sola_venta(): void
    {
        $this->fakeAcceptance(['sandbox.wompi.co/v1/transactions*' => $this->timeout()]);
        $tx = $this->chargeNequi();

        $wompiId = 'wompi-chaos-'.substr(md5($tx->reference), 0, 8);

        $this->wompiWebhook($this->wompiTransactionEvent($tx->reference, 'APPROVED', 9000000))->assertOk();

        Http::fake([
            'sandbox.wompi.co/v1/transactions/*' => Http::response(['data' => [
                'id' => $wompiId, 'status' => 'APPROVED', 'reference' => $tx->reference,
                'amount_in_cents' => 9000000, 'currency' => 'COP',
            ]], 200),
        ]);
        WompiReconciliationService::make()->reconcileOne($tx->fresh());

        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);
    }

    // ── F6.23 ───────────────────────────────────────────────────────────

    /**
     * F6.23 — El mismo webhook diez veces: una sola consecuencia.
     *
     * Wompi reentrega cuando duda de que hayamos recibido. Diez entregas del
     * mismo hecho son un hecho, no diez.
     */
    public function test_f623_webhook_duplicado_diez_veces_activa_una_sola_vez(): void
    {
        $this->fakeAcceptance([
            'sandbox.wompi.co/v1/transactions*' => Http::response(['data' => [
                'id' => 'wompi-live-1', 'status' => 'PENDING',
                'reference' => 'x', 'amount_in_cents' => 9000000, 'currency' => 'COP',
            ]], 200),
        ]);
        $tx = $this->chargeNequi();

        $event = $this->wompiTransactionEvent($tx->reference, 'APPROVED', 9000000);

        for ($i = 0; $i < 10; $i++) {
            $this->wompiWebhook($event)->assertOk();
        }

        $this->assertSame(1, Payment::where('reference', $tx->reference)->count(), 'Diez webhooks produjeron más de una venta.');
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);

        // Nueve quedaron descartadas por el hash del payload, no procesadas.
        $this->assertSame(1, PaymentWebhookEvent::where('provider', 'wompi')->count());
    }

    // ── F6.24 ───────────────────────────────────────────────────────────

    /** F6.24 — Firma inválida: 401 y el pago intacto. */
    public function test_f624_firma_invalida_se_rechaza_y_no_toca_el_pago(): void
    {
        $this->fakeAcceptance([
            'sandbox.wompi.co/v1/transactions*' => Http::response(['data' => [
                'id' => 'wompi-live-2', 'status' => 'PENDING',
                'reference' => 'x', 'amount_in_cents' => 9000000, 'currency' => 'COP',
            ]], 200),
        ]);
        $tx = $this->chargeNequi();
        $before = $tx->fresh()->status;

        $this->wompiWebhook(
            $this->wompiTransactionEvent($tx->reference, 'APPROVED', 9000000),
            validSignature: false,
        )->assertStatus(401);

        $this->assertSame($before, $tx->fresh()->status, 'Un webhook sin firma válida movió el pago.');
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count());
        $this->assertSame(0, PaymentWebhookEvent::where('provider', 'wompi')->count());
    }

    // ── F6.25 ───────────────────────────────────────────────────────────

    /**
     * F6.25 — El webhook trae un monto que no es el que se firmó.
     *
     * Puede ser un ataque o puede ser un error de la pasarela; da igual, porque
     * la respuesta correcta es la misma: no se activa nada y queda constancia
     * para que lo mire una persona.
     */
    public function test_f625_monto_distinto_no_activa_membresia_y_deja_constancia(): void
    {
        $this->fakeAcceptance([
            'sandbox.wompi.co/v1/transactions*' => Http::response(['data' => [
                'id' => 'wompi-live-3', 'status' => 'PENDING',
                'reference' => 'x', 'amount_in_cents' => 9000000, 'currency' => 'COP',
            ]], 200),
        ]);
        $tx = $this->chargeNequi();

        // Aprobado… por 1.000 pesos en vez de 90.000.
        $this->wompiWebhook($this->wompiTransactionEvent($tx->reference, 'APPROVED', 100000))->assertOk();

        $this->assertNotSame(
            PaymentStateMachine::APPROVED, $tx->fresh()->status,
            'Se aprobó un pago cuyo monto no coincide con el firmado.',
        );
        $this->assertSame(0, Payment::where('reference', $tx->reference)->count());

        // El evento queda registrado como fallido con el motivo: sin esto, el
        // rechazo sería invisible y nadie sabría que alguien lo intentó.
        $event = PaymentWebhookEvent::where('provider', 'wompi')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame(PaymentWebhookEvent::STATUS_FAILED, $event->processing_status);
        $this->assertStringContainsString('monto', (string) $event->error_message);
    }

    /**
     * F6.25b — Y el rechazo por monto no bloquea el webhook correcto posterior.
     *
     * Si el gimnasio tuviera que borrar filas a mano para poder cobrar bien
     * después de un intento raro, el sistema sería peor que no tener defensa.
     */
    public function test_f625b_tras_el_rechazo_por_monto_el_webhook_correcto_sigue_funcionando(): void
    {
        $this->fakeAcceptance([
            'sandbox.wompi.co/v1/transactions*' => Http::response(['data' => [
                'id' => 'wompi-live-4', 'status' => 'PENDING',
                'reference' => 'x', 'amount_in_cents' => 9000000, 'currency' => 'COP',
            ]], 200),
        ]);
        $tx = $this->chargeNequi();

        $this->wompiWebhook($this->wompiTransactionEvent($tx->reference, 'APPROVED', 100000))->assertOk();
        $this->wompiWebhook($this->wompiTransactionEvent($tx->reference, 'APPROVED', 9000000))->assertOk();

        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);
        $this->assertSame(1, Payment::where('reference', $tx->reference)->count());
    }
}
