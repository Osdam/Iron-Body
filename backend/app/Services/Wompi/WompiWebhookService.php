<?php

namespace App\Services\Wompi;

use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Services\Billing\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Procesa los eventos de webhook de Wompi de forma SEGURA e IDEMPOTENTE:
 *
 *  1. Verifica el checksum oficial (signature.properties + timestamp + secreto).
 *  2. Valida el ambiente.
 *  3. Registra el evento ANTES de procesarlo (dedupe por hash del payload).
 *  4. Ubica la transacción por referencia o por id de Wompi.
 *  5. Valida monto y moneda.
 *  6. Aplica la máquina de estados dentro de una transacción DB (lockForUpdate),
 *     activando la membresía UNA sola vez en approved.
 *
 * Resultado: ['status' => ..., 'http' => int]. Para eventos válidos (incluido un
 * duplicado ya procesado) se responde 200; firma inválida → 401 CONTROLADO.
 * Nunca se revelan secretos ni se loguean payloads sin sanitizar.
 */
class WompiWebhookService
{
    /**
     * A partir de cuánto se da por muerto un procesamiento que nunca se cerró.
     *
     * Por debajo, una entrega simultánea sigue siendo un duplicado. Por encima,
     * nadie está trabajando en esa fila: el proceso se cayó. El primer reintento
     * de Wompi llega a los treinta minutos, así que esto no compite con él.
     */
    private const MINUTOS_PARA_DAR_POR_MUERTO = 5;

    public function __construct(
        private WompiSignatureService $signature,
        private WompiTransactionService $tx,
        private array $cfg,
    ) {}

    public static function make(): self
    {
        return new self(
            WompiSignatureService::fromConfig(),
            WompiTransactionService::make(),
            (array) config('wompi'),
        );
    }

    /**
     * @param  array  $payload  cuerpo decodificado del evento.
     * @param  string  $rawBody  cuerpo crudo (para el hash de dedupe).
     */
    public function handle(array $payload, string $rawBody): array
    {
        // 1) Firma.
        if (! $this->signature->verifyWebhookChecksum($payload)) {
            Log::warning('wompi.webhook.invalid_signature', [
                'event' => $payload['event'] ?? null,
            ]);

            return ['status' => 'invalid_signature', 'http' => 401];
        }

        // 2) Ambiente.
        $eventEnv = (string) ($payload['environment'] ?? '');
        $expectedEnv = ($this->cfg['env'] ?? 'sandbox') === 'production' ? 'prod' : 'test';
        if ($eventEnv !== '' && ! str_contains(strtolower($eventEnv), $expectedEnv)) {
            Log::warning('wompi.webhook.env_mismatch', ['event_env' => $eventEnv]);

            // 200 para que Wompi no reintente un evento que no nos corresponde.
            return ['status' => 'env_mismatch', 'http' => 200];
        }

        $eventType = (string) ($payload['event'] ?? '');
        $wt = (array) data_get($payload, 'data.transaction', []);
        $payloadHash = hash('sha256', $rawBody);

        // 3) Registrar evento (dedupe por payload_hash único).
        $event = $this->recordEvent($payload, $wt, $eventType, $payloadHash);
        if ($event === null) {
            /*
             * REENTREGA: idéntica no es lo mismo que ya aplicada.
             *
             * Cuando el procesamiento de abajo falla, este método devuelve 500
             * precisamente PARA QUE Wompi reintente (lo hace a los 30 min, 3 h
             * y 24 h). Pero la fila del evento se escribe ANTES y sobrevive al
             * rollback de la transacción de pago, así que el reintento chocaba
             * aquí con la unicidad de `payload_hash` y se contestaba
             * «duplicate, 200»: pedíamos una reentrega y luego la tirábamos.
             *
             * El caso que lo vuelve grave: si la activación de membresía lanza,
             * la transición entera se revierte —el pago NO queda aprobado— y la
             * única oportunidad de repararlo era ese reintento. Dinero dentro,
             * servicio fuera, y sin más avisos.
             *
             * La idempotencia de verdad es sobre lo PROCESADO, no sobre lo
             * visto: un evento que terminó bien (o que se descartó a
             * propósito) no se vuelve a aplicar jamás; uno que quedó en
             * `failed` se reprocesa sobre su misma fila. Los reintentos de
             * Wompi son tres y acotados, así que esto no puede convertirse en
             * un bucle.
             */
            $previo = PaymentWebhookEvent::query()
                ->where('provider', 'wompi')
                ->where('payload_hash', $payloadHash)
                ->first();

            /*
             * Y el que se quedó en `received` también vuelve a intentarse.
             *
             * `failed` sólo cubre la mitad en que el código llegó a ejecutar su
             * propio catch. La otra —la más probable en un fallo de
             * infraestructura— es que el proceso muriera ENTRE el registro del
             * evento y el final: OOM, kill, timeout de php-fpm, 504 del
             * balanceador. Esa fila se queda en `received` para siempre porque
             * nadie llega a cerrarla, y la reentrega la veía «ya registrada».
             * Mismo desenlace que el otro agujero: dinero dentro, servicio
             * fuera.
             *
             * El umbral de antigüedad es lo que separa «se murió» de «se está
             * procesando ahora mismo»: dentro de la ventana, dos entregas
             * simultáneas siguen siendo un duplicado y sólo trabaja una. Cinco
             * minutos caben de sobra en cualquier procesamiento nuestro y muy
             * por debajo del primer reintento de Wompi, que es a los treinta.
             *
             * Reprocesar es seguro aunque nos equivoquemos: `transitionTo`
             * serializa con `lockForUpdate` y la activación cuelga de ENTRAR en
             * approved, así que dos pasadas convergen en una sola membresía.
             */
            $reintentable = $previo !== null && (
                $previo->processing_status === PaymentWebhookEvent::STATUS_FAILED
                || (
                    $previo->processing_status === PaymentWebhookEvent::STATUS_RECEIVED
                    && $previo->updated_at !== null
                    && $previo->updated_at->lt(now()->subMinutes(self::MINUTOS_PARA_DAR_POR_MUERTO))
                )
            );

            if (! $reintentable) {
                return ['status' => 'duplicate', 'http' => 200];
            }

            Log::info('wompi.webhook.retry_after_failure', [
                'reference' => (string) ($wt['reference'] ?? ''),
                'previous_status' => $previo->processing_status,
                'previous_error' => $previo->error_message,
            ]);

            $event = $previo;
        }

        // Solo procesamos transaction.updated (extensible a más eventos).
        if ($eventType !== 'transaction.updated') {
            $this->finishEvent($event, PaymentWebhookEvent::STATUS_SKIPPED);

            return ['status' => 'ignored_event', 'http' => 200];
        }

        // 4) Ubicar transacción.
        $reference = (string) ($wt['reference'] ?? '');
        $wompiId = (string) ($wt['id'] ?? '');
        $transaction = $this->findTransaction($reference, $wompiId);

        if (! $transaction) {
            Log::warning('wompi.webhook.tx_not_found', ['reference' => $reference]);
            $this->finishEvent($event, PaymentWebhookEvent::STATUS_FAILED, 'Transacción no encontrada');

            // 200: no reintentar; no es un error nuestro recuperable.
            return ['status' => 'tx_not_found', 'http' => 200];
        }

        // 5) Validar monto y moneda contra lo esperado.
        $mismatch = $this->amountOrCurrencyMismatch($transaction, $wt);
        if ($mismatch) {
            Log::warning('wompi.webhook.amount_mismatch', [
                'reference' => $transaction->reference,
                'detail' => $mismatch,
            ]);
            $this->finishEvent($event, PaymentWebhookEvent::STATUS_FAILED, $mismatch);

            // No degradamos a approved un pago con monto alterado.
            return ['status' => 'amount_mismatch', 'http' => 200];
        }

        // 6) Aplicar estado (idempotente, con lock + activación única).
        try {
            $this->tx->applyWompiTransaction($transaction, $wt);
            $this->finishEvent($event, PaymentWebhookEvent::STATUS_PROCESSED);

            return ['status' => 'processed', 'http' => 200];
        } catch (\Throwable $e) {
            Log::error('wompi.webhook.process_error', [
                'reference' => $transaction->reference,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
            $this->finishEvent($event, PaymentWebhookEvent::STATUS_FAILED, 'error de procesamiento');

            // 500 para que Wompi reintente la entrega.
            return ['status' => 'process_error', 'http' => 500];
        }
    }

    /**
     * Crea el registro del evento. Devuelve null si ya existía (duplicado).
     */
    private function recordEvent(array $payload, array $wt, string $eventType, string $payloadHash): ?PaymentWebhookEvent
    {
        try {
            return PaymentWebhookEvent::create([
                'uuid' => (string) Str::uuid(),
                'provider' => 'wompi',
                'event_type' => $eventType,
                'checksum' => (string) data_get($payload, 'signature.checksum'),
                'transaction_id' => $wt['id'] ?? null,
                'environment' => $payload['environment'] ?? null,
                'payload_hash' => $payloadHash,
                'payload' => $this->safePayload($payload),
                'processing_status' => PaymentWebhookEvent::STATUS_RECEIVED,
            ]);
        } catch (QueryException $e) {
            // Choque de unicidad (provider,payload_hash) → reentrega ya registrada.
            return null;
        }
    }

    private function findTransaction(string $reference, string $wompiId): ?PaymentTransaction
    {
        $q = PaymentTransaction::query()->where('provider', 'wompi');
        if ($reference !== '') {
            $tx = (clone $q)->where('reference', $reference)->first();
            if ($tx) {
                return $tx;
            }
        }
        if ($wompiId !== '') {
            return (clone $q)->where('wompi_transaction_id', $wompiId)->first();
        }

        return null;
    }

    private function amountOrCurrencyMismatch(PaymentTransaction $tx, array $wt): ?string
    {
        // Se valida contra el importe CONGELADO al autorizar (gross_amount), no
        // contra el precio actual del plan: si el catálogo cambió entre la
        // autorización y el webhook, el cobro sigue siendo el que se firmó.
        $expectedCents = Money::fromAmount($tx->gross_amount ?? $tx->amount)->toWompiCents();
        $gotCents = (int) ($wt['amount_in_cents'] ?? 0);
        if ($gotCents > 0 && $gotCents !== $expectedCents) {
            return "monto: esperado {$expectedCents}c, recibido {$gotCents}c";
        }
        $currency = strtoupper((string) ($wt['currency'] ?? ''));
        if ($currency !== '' && $currency !== strtoupper((string) $tx->currency)) {
            return "moneda: esperado {$tx->currency}, recibido {$currency}";
        }

        return null;
    }

    private function finishEvent(PaymentWebhookEvent $event, string $status, ?string $error = null): void
    {
        $event->forceFill([
            'processing_status' => $status,
            'processed_at' => now(),
            'error_message' => $error ? mb_substr($error, 0, 200) : null,
        ])->save();
    }

    /** Sanea el payload antes de persistirlo (nunca debería traer secretos). */
    private function safePayload(array $payload): array
    {
        unset($payload['data']['transaction']['payment_method']['token']);

        return $payload;
    }
}
