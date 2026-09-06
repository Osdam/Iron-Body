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
 *   - Expiración por ANTIGÜEDAD (max_pending_minutes) y nada más.
 *
 * DOS CAMINOS QUE NO SE MEZCLAN
 * -----------------------------
 * `refresh()` responde a una consulta del socio: pregunta a Wompi y aplica el
 * estado real. No cuenta intentos ni expira nada.
 *
 * `reconcileOne()` es el job de fondo: además de refrescar, lleva la cuenta de
 * sus propias pasadas y aplica la política de vencimiento.
 *
 * Confundir ambos costó seis transacciones en producción. El endpoint de estado
 * llamaba a `reconcileOne()`, y como la app consulta cada 2,5 s, las 24 pasadas
 * que el job habría tardado DOS HORAS en gastar se consumían en 60 segundos:
 * PSE y DaviPlata se sellaban `expired` mientras el socio seguía autorizando en
 * el portal de su banco —dos de ellas con Wompi diciendo aún PENDING—. Un
 * contador de "cuántas veces han preguntado" nunca dijo nada sobre si un pago
 * sigue vivo; el único dato que lo dice es cuánto tiempo lleva en vuelo.
 */
class WompiReconciliationService
{
    public function __construct(
        private WompiClient $client,
        private WompiTransactionService $tx,
        private PaymentStateMachine $sm,
        private array $cfg,
    ) {}

    public static function make(): self
    {
        return new self(
            WompiClient::fromConfig(),
            WompiTransactionService::make(),
            new PaymentStateMachine,
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
     * Refresca UNA transacción contra Wompi y aplica el estado real.
     *
     * Es el camino de las CONSULTAS: lo usa el endpoint de estado que la app
     * llama mientras el socio paga. Pregunta, aplica y punto — no cuenta pasadas
     * ni vence nada, porque que alguien mire cómo va su pago no es información
     * sobre si el pago sigue vivo.
     *
     * Un fallo temporal de Wompi no toca el estado local: se devuelve la fila tal
     * como está y se reintenta en la siguiente consulta.
     */
    public function refresh(PaymentTransaction $tx): PaymentTransaction
    {
        if (! $tx->wompi_transaction_id) {
            return $tx;
        }

        $res = $this->client->getTransaction($tx->wompi_transaction_id);
        if (! $res['ok'] || empty($res['data']['id'])) {
            return $tx;
        }

        return $this->tx->applyWompiTransaction($tx, $res['data']);
    }

    /**
     * Reconcilia UNA transacción desde el JOB de fondo.
     *
     * ORDEN CRÍTICO: se consulta Wompi PRIMERO y solo se expira si Wompi sigue en
     * vuelo tras una consulta VÁLIDA y la transacción ya es demasiado vieja. Un
     * fallo temporal de Wompi NO expira ni cambia el estado (así no se marca
     * expired un pago que Wompi ya aprobó). La activación de membresía es
     * idempotente (la garantiza applyWompiTransaction → transitionTo).
     *
     * @return 'updated'|'expired'|'skipped'
     */
    public function reconcileOne(PaymentTransaction $tx): string
    {
        $maxPendingMin = (int) ($this->cfg['reconciliation']['max_pending_minutes'] ?? 60);

        if (! $tx->wompi_transaction_id) {
            return 'skipped';
        }

        // 1) Consultar Wompi PRIMERO (fuente de verdad). Se marca la pasada del
        //    job (retry_count + last_reconciled_at) aunque la consulta falle.
        //    `retry_count` es DIAGNÓSTICO —cuántas veces miró el job—, nunca un
        //    veredicto sobre el pago: usarlo como tal fue exactamente el fallo.
        $res = $this->client->getTransaction($tx->wompi_transaction_id);
        $tx->forceFill([
            'retry_count' => (int) $tx->retry_count + 1,
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

        // 4) Wompi SIGUE en vuelo (consulta válida) → expirar solo por ANTIGÜEDAD.
        //    Un PSE legítimo tarda lo que el socio tarde en su banco; el único
        //    límite defendible es el reloj, y con holgura.
        $age = $updated->created_at ? Carbon::parse($updated->created_at)->diffInMinutes(now()) : 0;
        if ($age >= $maxPendingMin) {
            $this->tx->transitionTo($updated, PaymentStateMachine::EXPIRED, [
                'status_message' => 'El pago expiró sin confirmarse.',
            ]);

            return 'expired';
        }

        return $updated->status !== $before ? 'updated' : 'skipped';
    }
}
