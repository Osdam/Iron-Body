<?php

namespace App\Services\Wompi;

use App\Models\PaymentTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliación automática: el webhook es el mecanismo PRINCIPAL, pero no el
 * único. Pagos que quedaron pending/requires_action (app cerrada, red perdida,
 * webhook no recibido) se consultan directamente contra Wompi
 * (GET /transactions/{id}) y se resuelven por la máquina de estados (activación
 * idempotente si Wompi responde APPROVED).
 *
 * Política:
 *   - Solo transacciones provider=wompi en estados EN VUELO con id de Wompi.
 *   - Backoff por reintentos (retry_count) y expiración por antigüedad.
 *   - Al superar max_retries o max_pending_minutes → expired (terminal).
 */
class WompiReconciliationService
{
    public function __construct(
        private WompiClient $client,
        private WompiTransactionService $tx,
        private PaymentStateMachine $sm,
        private array $cfg,
    ) {
    }

    public static function make(): self
    {
        return new self(
            WompiClient::fromConfig(),
            WompiTransactionService::make(),
            new PaymentStateMachine(),
            (array) config('wompi'),
        );
    }

    /** @return array{checked:int,updated:int,expired:int,skipped:int} */
    public function reconcilePending(int $limit = 100): array
    {
        $stats = ['checked' => 0, 'updated' => 0, 'expired' => 0, 'skipped' => 0];

        if (! ($this->cfg['reconciliation']['enabled'] ?? true)) {
            return $stats;
        }

        $candidates = PaymentTransaction::query()
            ->where('provider', 'wompi')
            ->whereIn('status', PaymentStateMachine::IN_FLIGHT)
            ->whereNotNull('wompi_transaction_id')
            ->orderBy('last_reconciled_at')
            ->limit($limit)
            ->get();

        foreach ($candidates as $tx) {
            $stats['checked']++;
            $result = $this->reconcileOne($tx);
            $stats[$result] = ($stats[$result] ?? 0) + 1;
        }

        if ($stats['checked'] > 0) {
            Log::info('wompi.reconcile.run', $stats);
        }

        return $stats;
    }

    /** @return 'updated'|'expired'|'skipped' */
    public function reconcileOne(PaymentTransaction $tx): string
    {
        $maxRetries = (int) ($this->cfg['reconciliation']['max_retries'] ?? 24);
        $maxPendingMin = (int) ($this->cfg['reconciliation']['max_pending_minutes'] ?? 60);

        // Expiración por antigüedad o por exceso de reintentos.
        $age = $tx->created_at ? Carbon::parse($tx->created_at)->diffInMinutes(now()) : 0;
        if ($age >= $maxPendingMin || (int) $tx->retry_count >= $maxRetries) {
            $this->tx->transitionTo($tx, PaymentStateMachine::EXPIRED, [
                'status_message' => 'El pago expiró sin confirmarse.',
            ]);
            return 'expired';
        }

        $res = $this->client->getTransaction($tx->wompi_transaction_id);

        // Marca el intento aunque falle la consulta (backoff por orden).
        $tx->forceFill([
            'retry_count'        => (int) $tx->retry_count + 1,
            'last_reconciled_at' => now(),
        ])->save();

        if (! $res['ok'] || empty($res['data']['id'])) {
            return 'skipped';
        }

        $before = $tx->status;
        $updated = $this->tx->applyWompiTransaction($tx, $res['data']);

        return $updated->status !== $before ? 'updated' : 'skipped';
    }
}
