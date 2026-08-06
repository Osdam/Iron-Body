<?php

namespace App\Services\Commercial\Tools\Payments;

use App\Models\PaymentTransaction;
use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;
use App\Services\Wompi\PaymentStateMachine as SM;

/**
 * Consulta el estado real de un cobro.
 *
 * Existe para responder «¿ya me llegó el pago?» sin que nadie tenga que
 * creerse una captura de pantalla. La verdad está en la fila de la transacción,
 * que solo escriben el webhook firmado de Wompi y la reconciliación oficial.
 *
 * Devuelve además `confirmed`, un booleano explícito, en lugar de dejar que
 * quien lea interprete la cadena de estado. Es la diferencia entre «pending» y
 * «aún no ha pagado»: la primera es un dato, la segunda es la frase que hay que
 * decirle a una persona, y confundirlas lleva a dar por cobrado algo que no lo
 * está.
 *
 * La búsqueda se limita al sujeto del contexto. Sin ese cerco, una referencia
 * ajena —inventada o repetida de otra conversación— dejaría consultar el pago
 * de otra persona.
 */
class GetPaymentStatusTool extends BaseTool
{
    public function name(): string
    {
        return 'get_payment_status';
    }

    public function description(): string
    {
        return 'Consulta si un pago está confirmado. Úsala antes de dar por cobrado nada: '
            .'una captura de pantalla o la palabra del cliente no confirman un pago.';
    }

    public function schema(): array
    {
        return $this->strictSchema([
            'reference' => $this->stringProp('Referencia del pago. Si se omite, se mira el intento más reciente de esta persona.'),
        ]);
    }

    public function rules(): array
    {
        return ['reference' => ['sometimes', 'string', 'max:120']];
    }

    public function featureFlag(): ?string
    {
        return 'commercial.tools.payments';
    }

    public function mutates(): bool
    {
        return false;
    }

    public function timeoutSeconds(): int
    {
        return 10;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $memberId = $context->memberId();
        $leadId = $context->leadId();

        if ($memberId === null && $leadId === null) {
            return ToolResult::failed('no_subject', 'No hay persona sobre la que consultar.');
        }

        $query = PaymentTransaction::query()->latest('id');

        // El cerco: solo transacciones de esta persona. Una referencia suelta
        // nunca debe poder sacar información de otra.
        if ($memberId !== null) {
            $query->where('member_id', $memberId);
        } else {
            $query->whereRaw('1 = 0');
        }

        if (isset($arguments['reference'])) {
            $query->where('reference', $arguments['reference']);
        }

        $tx = $query->first(['id', 'reference', 'status', 'amount', 'currency', 'plan_id', 'paid_at', 'failure_reason', 'created_at']);

        if ($tx === null) {
            return ToolResult::ok([
                'found' => false,
                'confirmed' => false,
            ], 'No hay ningún intento de pago registrado para esta persona.');
        }

        $confirmed = (string) $tx->status === SM::APPROVED;

        return ToolResult::ok([
            'found' => true,
            // Explícito a propósito: que nadie tenga que interpretar la cadena.
            'confirmed' => $confirmed,
            'status' => $tx->status,
            'reference' => $tx->reference,
            'amount' => (float) $tx->amount,
            'currency' => $tx->currency,
            'paid_at' => $tx->paid_at?->toIso8601String(),
            'failure_reason' => $tx->failure_reason,
            'created_at' => $tx->created_at?->toIso8601String(),
        ], $confirmed
            ? 'Pago confirmado por la pasarela.'
            : 'El pago NO está confirmado. No actives ninguna membresía.');
    }
}
