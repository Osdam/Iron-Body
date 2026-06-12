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
    ) {
    }

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
                'reference'  => $transaction->reference,
                'status'     => $res['status'],
                'error_code' => $res['error_code'],
            ]);
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
            'amount_in_cents'            => $cents,
            'currency'                   => $currency,
            'reference'                  => $transaction->reference,
            'customer_email'             => $transaction->customer_email ?: ($c['email'] ?? null),
            'signature'                  => $this->signature->integritySignature($transaction->reference, $cents, $currency),
            'acceptance_token'           => $tokens['acceptance_token'],
            'accept_personal_auth_token' => $tokens['accept_personal_auth_token'],
            'redirect_url'               => $this->cfg['redirect_url'] ?? null,
            'payment_method'             => $paymentMethod,
            'customer_data'              => array_filter([
                'full_name'     => $c['name'] ?? null,
                'phone_number'  => $transaction->customer_phone ?: ($c['phone'] ?? null),
                'legal_id'      => $transaction->customer_legal_id ?: ($c['doc_number'] ?? null),
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
