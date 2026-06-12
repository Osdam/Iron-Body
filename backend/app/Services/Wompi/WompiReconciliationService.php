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

    /**
     * Reconcilia UNA transacción. ORDEN CRÍTICO: se consulta Wompi PRIMERO y solo
     * se expira si Wompi sigue PENDING tras una consulta VÁLIDA y se superó el
     * límite. Un fallo temporal de Wompi NO expira ni cambia el estado (así no se
     * marca expired un pago que Wompi ya aprobó). La activación de membresía es
     * idempotente (la garantiza applyWompiTransaction → transitionTo).
     *
     * @return 'updated'|'expired'|'skipped'
     */
    public function reconcileOne(PaymentTransaction $tx): string
    {
        $maxRetries = (int) ($this->cfg['reconciliation']['max_retries'] ?? 24);
        $maxPendingMin = (int) ($this->cfg['reconciliation']['max_pending_minutes'] ?? 60);

        if (! $tx->wompi_transaction_id) {
            return 'skipped';
        }

        // 1) Consultar Wompi PRIMERO (fuente de verdad). Se marca el intento
        //    (retry_count + last_reconciled_at) aunque la consulta falle.
        $res = $this->client->getTransaction($tx->wompi_transaction_id);
        $tx->forceFill([
            'retry_count'        => (int) $tx->retry_count + 1,
            'last_reconciled_at' => now(),
        ])->save();

        // 2) Fallo temporal de Wompi → NO expirar, NO cambiar estado.
        if (! $res['ok'] || empty($res['data']['id'])) {
            return 'skipped';
        }

        // 3) Aplicar el estado real de inmediato (idempotente). Si Wompi devolvió
        //    APPROVED/DECLINED/VOIDED/ERROR, la máquina de estados lo sella aquí.
        $before = $tx->status;
        $updated = $this->tx->applyWompiTransaction($tx, $res['data']);

        if ($this->sm->isTerminal($updated->status)) {
            return $updated->status !== $before ? 'updated' : 'skipped';
        }

        // 4) Wompi SIGUE PENDING (consulta válida) → expirar solo si se superó el
        //    límite de antigüedad o de reintentos.
        $age = $updated->created_at ? Carbon::parse($updated->created_at)->diffInMinutes(now()) : 0;
        if ($age >= $maxPendingMin || (int) $updated->retry_count >= $maxRetries) {
            $this->tx->transitionTo($updated, PaymentStateMachine::EXPIRED, [
                'status_message' => 'El pago expiró sin confirmarse.',
            ]);
            return 'expired';
        }

        return $updated->status !== $before ? 'updated' : 'skipped';
    }
}
