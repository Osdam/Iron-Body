<?php

namespace Tests\Feature\Chaos;

use App\Enums\InvoiceStatus;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\InvoicingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * F6.26 – F6.29 · Factus deja de contestar, contesta dos veces, o contesta mal.
 *
 * Una factura electrónica no es un registro interno: es un documento fiscal con
 * un número consumido de un rango autorizado. Por eso la simetría con los pagos
 * no es casual —emitir dos veces por lo mismo es tan caro como cobrar dos
 * veces— y por eso el criterio de esta familia es el mismo: una solicitud, un
 * documento, y ningún estado que afirme más de lo que se comprobó.
 *
 * La trampa concreta que se persigue aquí: dar por VALIDADA una factura de la
 * que faltan los campos que la hacen válida. Un CUFE es lo que prueba ante la
 * DIAN que el documento existe; sin él, «validada» es una etiqueta bonita sobre
 * un documento que no se puede defender en una inspección.
 */
class ChaosBillingTest extends ChaosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.enabled' => true,
            'billing.credentials' => [
                'username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's',
            ],
        ]);
    }

    private function paidPayment(): Payment
    {
        $plan = Plan::create(['name' => 'Pro', 'price' => 100000, 'duration_days' => 30, 'benefits' => '']);
        $user = User::factory()->create();

        return Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 100000,
            'method' => 'cash', 'reference' => 'PAY-CHAOS-'.uniqid(), 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    private function invoice(): ElectronicInvoice
    {
        Queue::fake(); // el job se corre a mano para poder observar cada intento

        return app(InvoicingService::class)->enqueueForPayment($this->paidPayment(), force: true);
    }

    private function runEmit(int $invoiceId): ?\Throwable
    {
        try {
            app()->call([new EmitElectronicInvoiceJob($invoiceId), 'handle']);

            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    /** El token de Factus siempre disponible: no es lo que se está probando. */
    private function fakeFactus(array $stubs): void
    {
        Http::fake(array_merge([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        ], $stubs));
    }

    // ── F6.26 ───────────────────────────────────────────────────────────

    /**
     * F6.26 — Factus deja de contestar a mitad de la emisión.
     *
     * Igual que con la pasarela: el silencio no dice que no se emitió. Lo que
     * NO puede pasar es que la solicitud se quede en `processing` para siempre
     * —sin número, sin error y sin nadie que lo sepa— porque el reintento se
     * rendiría contra ese mismo estado.
     */
    public function test_f626_timeout_de_factus_no_deja_la_solicitud_atascada(): void
    {
        $invoice = $this->invoice();
        $this->fakeFactus(['*/v2/bills/validate' => $this->timeout()]);

        $error = $this->runEmit($invoice->id);

        $this->assertNotNull($error, 'El fallo técnico debería relanzarse para que la cola reintente.');

        $invoice->refresh();
        $this->assertNotSame(InvoiceStatus::PROCESSING, $invoice->status, sprintf(
            'La solicitud quedó en «%s»: ni número, ni error, ni motivo. Un reintento '
            .'se rendiría contra ese estado y la factura no existiría nunca.',
            $invoice->status->value,
        ));
        $this->assertTrue($invoice->status->canRetry(), 'La solicitud quedó en un estado del que no se puede salir.');
        $this->assertNull($invoice->cufe);
        $this->assertNull($invoice->number);
    }

    /**
     * F6.26b — Y el reintento no crea una segunda factura.
     *
     * La solicitud es la unidad, no el intento: se reutiliza la misma fila con
     * el mismo payload congelado.
     */
    public function test_f626b_reintentar_tras_el_timeout_no_duplica_la_factura(): void
    {
        $payment = $this->paidPayment();
        Queue::fake();
        $invoice = app(InvoicingService::class)->enqueueForPayment($payment, force: true);

        $this->fakeFactus(['*/v2/bills/validate' => $this->timeout()]);
        $this->runEmit($invoice->id);
        $this->runEmit($invoice->id);
        $this->runEmit($invoice->id);

        $this->assertSame(1, ElectronicInvoice::where('source_id', $payment->id)->count(),
            'Los reintentos crearon más de un documento fiscal para el mismo pago.');
    }

    // ── F6.27 ───────────────────────────────────────────────────────────

    /**
     * F6.27 — 500 de Factus, y después Factus vuelve.
     *
     * El reintento tiene que ser idempotente de verdad: mismo payload
     * congelado, misma referencia, y al final UN documento validado. Es el
     * escenario que demuestra que el estado `error` es de paso y no un
     * cementerio.
     */
    public function test_f627_reintento_idempotente_tras_500_termina_en_una_factura(): void
    {
        $payment = $this->paidPayment();
        Queue::fake();
        $invoice = app(InvoicingService::class)->enqueueForPayment($payment, force: true);

        // Un solo stub que cambia: Factus se cae y luego se recupera.
        $caido = true;

        $this->fakeFactus([
            '*/v2/bills/validate' => function () use (&$caido) {
                return $caido
                    ? Http::response(['message' => 'internal error'], 500)
                    : Http::response(['data' => ['bill' => [
                        'id' => 'F-CHAOS-1', 'number' => '990000123', 'prefix' => 'SETP',
                        'cufe' => 'cufe-chaos-123', 'status' => 'Validada',
                    ]]], 201);
            },
            '*download-pdf' => Http::response(['pdf_base_64' => base64_encode('%PDF demo')]),
            '*download-xml' => Http::response(['xml_base_64' => base64_encode('<Invoice/>')]),
        ]);

        $this->assertNotNull($this->runEmit($invoice->id), 'Un 5xx debe relanzarse para que la cola reintente.');
        $this->assertSame(InvoiceStatus::ERROR, $invoice->fresh()->status);

        $payloadTrasElFallo = $invoice->fresh()->payload_snapshot;

        // Factus vuelve.
        $caido = false;
        $this->assertNull($this->runEmit($invoice->id));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::VALIDATED, $invoice->status);
        $this->assertSame('cufe-chaos-123', $invoice->cufe);
        $this->assertSame(1, ElectronicInvoice::where('source_id', $payment->id)->count());

        // El reintento mandó EXACTAMENTE lo mismo: un cambio de precio o de
        // tarifa entre intentos no puede alterar un documento fiscal en curso.
        $this->assertSame($payloadTrasElFallo, $invoice->payload_snapshot,
            'El reintento reconstruyó el payload en vez de reutilizar el congelado.');
    }

    // ── F6.28 ───────────────────────────────────────────────────────────

    /**
     * F6.28 — Factus contesta dos veces lo mismo.
     *
     * Reentrega, doble worker, o un job que se ejecutó dos veces. La segunda
     * pasada no puede consumir otro número ni reescribir el CUFE del documento
     * que ya está validado ante la DIAN.
     */
    public function test_f628_dos_respuestas_de_factus_dejan_una_sola_factura_logica(): void
    {
        $payment = $this->paidPayment();
        Queue::fake();
        $invoice = app(InvoicingService::class)->enqueueForPayment($payment, force: true);

        $llamadas = 0;

        $this->fakeFactus([
            '*/v2/bills/validate' => function () use (&$llamadas) {
                $llamadas++;

                return Http::response(['data' => ['bill' => [
                    // Un número DISTINTO en la segunda respuesta: si el sistema
                    // la aceptara, el documento cambiaría de identidad fiscal.
                    'id' => 'F-CHAOS-'.$llamadas,
                    'number' => '99000000'.$llamadas,
                    'prefix' => 'SETP',
                    'cufe' => 'cufe-chaos-'.$llamadas,
                    'status' => 'Validada',
                ]]], 201);
            },
            '*download-pdf' => Http::response(['pdf_base_64' => base64_encode('%PDF demo')]),
            '*download-xml' => Http::response(['xml_base_64' => base64_encode('<Invoice/>')]),
        ]);

        $this->runEmit($invoice->id);
        $this->runEmit($invoice->id);
        $this->runEmit($invoice->id);

        $invoice->refresh();

        $this->assertSame(1, $llamadas,
            'Se llamó a Factus más de una vez sobre una factura ya validada: eso consume otro número del rango.');
        $this->assertSame('cufe-chaos-1', $invoice->cufe, 'El CUFE del documento validado cambió.');
        $this->assertSame('990000001', $invoice->number);
        $this->assertSame(1, ElectronicInvoice::where('source_id', $payment->id)->count());
    }

    // ── F6.29 ───────────────────────────────────────────────────────────

    /**
     * F6.29 — Factus contesta 201, pero le faltan los campos que importan.
     *
     * El caso que más se parece a un éxito: código correcto, cuerpo presente y
     * ningún error a la vista. Solo que sin CUFE ese documento no es defendible
     * ante la DIAN, y marcarlo validado es escribir en la base una afirmación
     * que no se puede sostener en una inspección.
     */
    public function test_f629_respuesta_sin_cufe_no_se_marca_como_validada(): void
    {
        $invoice = $this->invoice();

        $this->fakeFactus([
            '*/v2/bills/validate' => Http::response(['data' => ['bill' => [
                'id' => 'F-CHAOS-SIN-CUFE',
                'number' => '990000999',
                'prefix' => 'SETP',
                'status' => 'Validada',
                // sin `cufe`
            ]]], 201),
            '*download-pdf' => Http::response(['pdf_base_64' => base64_encode('%PDF demo')]),
            '*download-xml' => Http::response(['xml_base_64' => base64_encode('<Invoice/>')]),
        ]);

        $this->runEmit($invoice->id);

        $invoice->refresh();

        $this->assertNotSame(InvoiceStatus::VALIDATED, $invoice->status, sprintf(
            'La factura quedó «%s» sin CUFE. Ante la DIAN eso no es un documento '
            .'válido, y el panel diría que sí lo es.',
            $invoice->status->value,
        ));
    }

    /** F6.29b — Y una respuesta con el cuerpo vacío tampoco vale. */
    public function test_f629b_respuesta_vacia_no_se_da_por_buena(): void
    {
        $invoice = $this->invoice();

        $this->fakeFactus(['*/v2/bills/validate' => Http::response([], 201)]);

        $this->runEmit($invoice->id);

        $invoice->refresh();
        $this->assertNotSame(InvoiceStatus::VALIDATED, $invoice->status);
        $this->assertNull($invoice->cufe);
        $this->assertNull($invoice->number);
    }

    /**
     * F6.29c — Un 4xx no se reintenta.
     *
     * Un rechazo de datos vuelve a fallar igual por mucho que se insista: se
     * marca rechazado y espera corrección humana. Reintentarlo sería el bucle
     * que agota la cuota sin arreglar nada.
     */
    public function test_f629c_rechazo_de_datos_no_entra_en_bucle_de_reintentos(): void
    {
        $invoice = $this->invoice();

        $llamadas = 0;
        $this->fakeFactus([
            '*/v2/bills/validate' => function () use (&$llamadas) {
                $llamadas++;

                return Http::response(['message' => 'El documento del cliente es inválido'], 422);
            },
        ]);

        $this->assertNull($this->runEmit($invoice->id), 'Un rechazo de datos no debe relanzarse.');
        $this->runEmit($invoice->id);
        $this->runEmit($invoice->id);

        $this->assertSame(1, $llamadas, 'Un rechazo de datos entró en bucle contra Factus.');
        $this->assertSame(InvoiceStatus::REJECTED, $invoice->fresh()->status);
    }
}
