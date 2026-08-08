<?php

namespace App\Services\Wompi;

use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Plantilla compartida del cobro Wompi por método. Cada método concreto solo
 * define su bloque `payment_method` y, si aplica, datos extra; toda la lógica de
 * idempotencia, firma, aceptación, consentimiento, envío y mapeo de estado vive
 * aquí (separación de responsabilidades + cero duplicación).
 *
 * NINGÚN dato sensible (PAN/CVC/OTP) pasa por aquí: las tarjetas se tokenizan en
 * Flutter; este servicio recibe únicamente el token.
 */
abstract class AbstractWompiPaymentService
{
    public function __construct(
        protected WompiClient $client,
        protected WompiTransactionService $tx,
        protected WompiSignatureService $signature,
        protected WompiAcceptanceService $acceptance,
        protected array $cfg,
    ) {}

    /** Nombre interno del método (card|pse|nequi|daviplata). */
    abstract protected function method(): string;

    /**
     * Construye el bloque `payment_method` de Wompi para este método.
     * Devuelve null si los datos son insuficientes (el caller marca error).
     */
    abstract protected function buildPaymentMethod(array $data, PaymentTransaction $transaction): ?array;

    /**
     * Procesa el cobro: crea/reutiliza la transacción, envía a Wompi y deja el
     * estado real. Nunca aprueba localmente; nunca cobra dos veces.
     */
    public function process(array $data, ?string $ip = null, ?string $userAgent = null): PaymentTransaction
    {
        $data['method'] = $this->method();
        $transaction = $this->tx->createOrReuse($data);

        // Anti doble pago / idempotencia.
        if ($transaction->status === PaymentStateMachine::APPROVED) {
            return $transaction;
        }
        if ($transaction->wompi_transaction_id
            && in_array($transaction->status, PaymentStateMachine::IN_FLIGHT, true)) {
            // Ya hay una transacción viva en Wompi: no se crea otra.
            return $transaction;
        }
        // Y tampoco se manda otra cuando del intento anterior no se sabe si
        // llegó a cobrarse: sin id de Wompi el guardia de arriba no lo ve, y es
        // justo el caso en el que un segundo POST cobraría dos veces.
        if (! empty(data_get($transaction->metadata, 'outcome_unknown'))
            && in_array($transaction->status, PaymentStateMachine::IN_FLIGHT, true)) {
            Log::info('wompi.create_transaction.skipped_indeterminate', [
                'reference' => $transaction->reference,
            ]);

            return $transaction;
        }

        // Método habilitado.
        if (! ($this->cfg['methods'][$this->method()] ?? false)) {
            return $this->tx->markError($transaction, 'Este método de pago no está disponible por el momento.');
        }

        // Tokens de aceptación VIGENTES (frescos). Sin ellos no se puede cobrar.
        $tokens = $this->acceptance->freshTokensForTransaction();
        if (empty($tokens['acceptance_token']) || empty($tokens['accept_personal_auth_token'])) {
            return $this->tx->markError($transaction, 'No pudimos validar los términos de pago. Intenta nuevamente.');
        }
        $this->tx->recordConsent($transaction, $tokens, $ip, $userAgent);

        // Bloque payment_method del método concreto.
        $paymentMethod = $this->buildPaymentMethod($data, $transaction);
        if ($paymentMethod === null) {
            return $this->tx->markError($transaction, 'Faltan datos para procesar el pago con este método.');
        }

        // Pasa a pending antes de enviar (refleja "enviado a la pasarela").
        $this->tx->transitionTo($transaction, PaymentStateMachine::PENDING);

        $payload = $this->baseTransactionPayload($transaction, $tokens, $paymentMethod, $data);

        $res = $this->client->createTransaction($payload, $transaction->idempotency_key);

        if (! $res['ok']) {
            Log::info('wompi.create_transaction.failed', [
                'reference' => $transaction->reference,
                'status' => $res['status'],
                'error_code' => $res['error_code'],
                'outcome_known' => ! $this->outcomeIsUnknown($res),
            ]);

            // Un fallo del que NO sabemos el desenlace no se puede sellar como
            // rechazo: ver más abajo.
            if ($this->outcomeIsUnknown($res)) {
                return $this->markIndeterminate($transaction, $res);
            }

            return $this->tx->markError(
                $transaction,
                $this->friendlyError($res),
                ['processor_response_code' => $res['error_code']]
            );
        }

        // La transacción ES el objeto `data` en POST /transactions.
        $wt = is_array($res['data']) ? $res['data'] : [];
        if (empty($wt['id'])) {
            return $this->tx->markError($transaction, 'Respuesta inválida de la pasarela. No se realizó ningún cobro.');
        }

        return $this->tx->applyWompiTransaction($transaction, $wt);
    }

    /**
     * ¿El fallo nos deja SIN SABER si Wompi creó la transacción?
     *
     * La distinción no es cosmética, es la diferencia entre un pago perdido y
     * uno resuelto. Un 4xx es una respuesta: Wompi leyó la petición, la rechazó
     * y no cobró nada; ahí `error` es la verdad. Un timeout (`status` 0) o un
     * 5xx no son respuestas sobre el cobro, son la ausencia de una: la petición
     * pudo llegar entera, crearse la transacción y cobrarse, y lo único que se
     * perdió por el camino fue el acuse.
     *
     * Tratar ese silencio como rechazo es afirmar algo que nadie comprobó, y se
     * paga caro: `error` es TERMINAL, así que el webhook que llegara después
     * diciendo «aprobada» no podría mover el estado, y el cobro real quedaría
     * enterrado con el cliente pagando y sin membresía.
     */
    protected function outcomeIsUnknown(array $res): bool
    {
        $status = (int) ($res['status'] ?? 0);

        return $status === 0 || $status >= 500;
    }

    /**
     * Deja el intento EN VUELO y dicho que su desenlace se desconoce.
     *
     * No es un limbo: `pending` es exactamente lo que significa —enviado a la
     * pasarela, sin resultado— y es el estado que el resto del sistema ya sabe
     * resolver. El webhook lo cierra en cuanto Wompi cuente qué pasó; si el
     * webhook no llegara, IRON GUARD lo levanta como `payments_stuck` a la hora
     * y la bandeja comercial abre «pago a medias» a las dos, que es como una
     * persona se entera de que tiene que mirar el panel de Wompi.
     *
     * Lo que NO se hace es reintentar el cobro solo. Un segundo POST sobre algo
     * que quizá ya se cobró es precisamente el doble cargo que se quiere evitar.
     */
    private function markIndeterminate(PaymentTransaction $transaction, array $res): PaymentTransaction
    {
        return $this->tx->transitionTo($transaction, PaymentStateMachine::PENDING, [
            'status_message' => 'Estamos confirmando tu pago con la pasarela.',
            'metadata' => array_merge((array) $transaction->metadata, [
                'outcome_unknown' => [
                    'at' => now()->toIso8601String(),
                    'http_status' => (int) ($res['status'] ?? 0),
                    'error_code' => $res['error_code'] ?? null,
                    'correlation_id' => $res['correlation_id'] ?? null,
                ],
            ]),
        ]);
    }

    /** Payload común de POST /transactions (firma de integridad incluida). */
    protected function baseTransactionPayload(
        PaymentTransaction $transaction,
        array $tokens,
        array $paymentMethod,
        array $data
    ): array {
        $cents = $this->tx->amountInCents($transaction);
        $currency = strtoupper((string) $transaction->currency);
        $c = is_array($transaction->customer) ? $transaction->customer : [];

        return array_filter([
            'amount_in_cents' => $cents,
            'currency' => $currency,
            'reference' => $transaction->reference,
            'customer_email' => $transaction->customer_email ?: ($c['email'] ?? null),
            'signature' => $this->signature->integritySignature($transaction->reference, $cents, $currency),
            'acceptance_token' => $tokens['acceptance_token'],
            'accept_personal_auth_token' => $tokens['accept_personal_auth_token'],
            'redirect_url' => $this->cfg['redirect_url'] ?? null,
            'payment_method' => $paymentMethod,
            'customer_data' => array_filter([
                'full_name' => $c['name'] ?? null,
                'phone_number' => $transaction->customer_phone ?: ($c['phone'] ?? null),
                'legal_id' => $transaction->customer_legal_id ?: ($c['doc_number'] ?? null),
                'legal_id_type' => $transaction->customer_legal_id_type ?: ($c['doc_type'] ?? 'CC'),
            ], fn ($v) => $v !== null && $v !== ''),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** Mensaje sanitizado y legible para la app a partir del error de Wompi. */
    protected function friendlyError(array $res): string
    {
        $code = (string) ($res['error_code'] ?? '');
        $map = (array) ($this->cfg['error_messages'] ?? []);
        foreach ($map as $key => $msg) {
            if ($code !== '' && str_contains(strtoupper($code), $key)) {
                return $msg;
            }
        }

        return $map['ERROR'] ?? 'No pudimos procesar el pago. No se realizó ningún cobro.';
    }

    protected function description(PaymentTransaction $transaction): string
    {
        return $transaction->description ?: 'Membresía Iron Body';
    }
}
