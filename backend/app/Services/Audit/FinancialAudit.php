<?php

namespace App\Services\Audit;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CashRefund;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use Illuminate\Http\Request;

/**
 * Deja traza de las operaciones que mueven dinero, desde el servidor.
 *
 * Antes la escribía el navegador: tras cobrar, el CRM lanzaba un segundo
 * `POST /api/admin/audit-logs`. Recepción recibía 403 —escribir en el dominio
 * `audit` exige `roles.manage`, el permiso más alto del CRM— y la llamada iba
 * dentro de un `tap()` sin manejador de error, así que fallaba en silencio. El
 * resultado medido en producción: las ventas de un Super Admin quedaban
 * auditadas y las de recepción no. La traza dependía de quién cobrara.
 *
 * Pedirle a recepción una segunda petición para dejar constancia de la primera
 * era el error de fondo. La traza es una CONSECUENCIA de la operación, no un
 * favor que el cliente hace después: aquí se escribe en la misma transacción
 * que crea la venta o el pago, así que o existen las dos cosas o no existe
 * ninguna. Un rollback no puede dejar una auditoría de algo que no ocurrió.
 *
 * El actor sale de {@see AdminActor}, nunca de `$request->user()`: en las rutas
 * del CRM ese guard está vacío y devolvería null en silencio.
 *
 * Sin persona identificada NO se escribe nada. Los cobros de pasarela y las
 * importaciones no los ejecuta nadie, y firmar una traza sin responsable sería
 * peor que no tenerla: daría por auditado lo que no lo está.
 */
class FinancialAudit
{
    /**
     * Una venta de mostrador acaba de crearse.
     *
     * Se llama DENTRO de la transacción de la venta. `$sale` ya tiene id.
     */
    public function saleCreated(ProductSale $sale, ?Admin $actor, Request $request): void
    {
        $this->write($actor, $request, [
            'action' => 'create',
            'module' => 'Caja',
            'entity' => 'venta',
            'entity_id' => (string) $sale->id,
            'target_name' => $sale->code,
            'summary' => "Registró la venta {$sale->code}",
            'metadata' => [
                'total' => (string) $sale->total,
                'payment_method' => $sale->payment_method,
                'cash_shift_id' => $sale->cash_shift_id,
            ],
        ]);
    }

    /** Un cobro de mostrador acaba de registrarse. */
    public function paymentCreated(Payment $payment, ?Admin $actor, Request $request): void
    {
        $this->write($actor, $request, [
            'action' => 'create',
            'module' => 'Pagos',
            'entity' => 'pago',
            'entity_id' => (string) $payment->id,
            'target_name' => $payment->reference,
            'summary' => 'Registró un cobro de membresía',
            'metadata' => [
                'amount' => (string) $payment->amount,
                'method' => $payment->method,
                'status' => $payment->status,
                'plan_id' => $payment->plan_id,
                'cash_shift_id' => $payment->cash_shift_id,
            ],
        ]);
    }

    /**
     * Un cobro cambió de estado o de datos.
     *
     * `$estadoPrevio` se recoge ANTES de guardar: sin él la traza diría a qué
     * quedó el pago pero no de dónde venía, y en un cobro lo que importa es
     * precisamente el salto —de pendiente a pagado entra dinero, y al revés
     * sale—. Se distingue `status` de `update` para que un cambio de importe no
     * se confunda con una confirmación de cobro.
     *
     * `$antes` lleva medio, importe, fecha y desglose ANTES del cambio: una
     * corrección de medio que solo dejara el valor nuevo no permitiría
     * reconstruir el arqueo que ese turno tenía. Cada campo que cambió va en
     * `changes` con sus dos lados; `$reason` y `$extra` (turno, si estaba
     * cerrado, días devueltos, deuda vinculada) van en los metadatos.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $extra
     */
    public function paymentUpdated(
        Payment $payment,
        ?string $estadoPrevio,
        ?Admin $actor,
        Request $request,
        array $antes = [],
        ?string $reason = null,
        array $extra = [],
    ): void {
        $cambioDeEstado = $estadoPrevio !== null && $estadoPrevio !== $payment->status;

        $despues = self::paymentSnapshot($payment);
        $cambios = $cambioDeEstado
            ? [['field' => 'status', 'before' => $estadoPrevio, 'after' => $payment->status]]
            : [];
        foreach (['method', 'amount', 'paid_at', 'splits'] as $campo) {
            if (array_key_exists($campo, $antes) && $antes[$campo] !== $despues[$campo]) {
                $cambios[] = ['field' => $campo, 'before' => $antes[$campo], 'after' => $despues[$campo]];
            }
        }

        $this->write($actor, $request, [
            'action' => $cambioDeEstado ? 'status' : 'update',
            'module' => 'Pagos',
            'entity' => 'pago',
            'entity_id' => (string) $payment->id,
            'target_name' => $payment->reference,
            'summary' => $cambioDeEstado
                ? "Cambió el cobro de {$estadoPrevio} a {$payment->status}"
                : (count($cambios) > 0 ? 'Corrigió '.implode(', ', array_column($cambios, 'field')).' de un cobro' : 'Modificó los datos de un cobro'),
            'changes' => $cambios ?: null,
            'metadata' => array_filter([
                'amount' => (string) $payment->amount,
                'method' => $payment->method,
                'status' => $payment->status,
                'cash_shift_id' => $payment->cash_shift_id,
                'reason' => $reason,
            ], static fn ($v) => $v !== null) + $extra,
        ]);
    }

    /**
     * Foto de lo que un cobro dice del dinero: lo que una corrección puede
     * cambiar y la traza tiene que poder enseñar antes y después.
     *
     * @return array{method: ?string, amount: string, paid_at: ?string, splits: list<array{method: string, amount: string}>}
     */
    public static function paymentSnapshot(Payment $payment): array
    {
        return [
            'method' => $payment->method,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'paid_at' => optional($payment->paid_at)->toIso8601String(),
            'splits' => $payment->splits()->orderBy('id')->get(['method', 'amount'])
                ->map(fn ($p) => ['method' => (string) $p->method, 'amount' => number_format((float) $p->amount, 2, '.', '')])
                ->all(),
        ];
    }

    /**
     * Se devolvió el dinero de un cobro.
     *
     * Dos turnos en la misma traza a propósito: el del cobro (donde el dinero
     * ENTRÓ y sigue contando) y el de la devolución (de donde SALE). Sin los
     * dos, quien revise un arqueo no puede casar la salida con su entrada.
     *
     * @param  array<string, mixed>  $extra
     */
    public function paymentRefunded(Payment $payment, CashRefund $refund, string $estadoPrevio, ?Admin $actor, Request $request, array $extra = []): void
    {
        $this->write($actor, $request, [
            'action' => 'refund',
            'module' => 'Pagos',
            'entity' => 'pago',
            'entity_id' => (string) $payment->id,
            'target_name' => $payment->reference,
            'summary' => 'Devolvió $'.number_format((float) $refund->amount, 0, ',', '.').' de un cobro',
            'changes' => [['field' => 'status', 'before' => $estadoPrevio, 'after' => $payment->status]],
            'metadata' => array_filter([
                'amount' => (string) $refund->amount,
                'method' => $refund->method,
                'refund_id' => $refund->id,
                'refund_cash_shift_id' => $refund->cash_shift_id,
                'external_reference' => $refund->external_reference,
                'cash_shift_id' => $payment->cash_shift_id,
                'reason' => $refund->reason,
            ], static fn ($v) => $v !== null) + $extra,
        ]);
    }

    /**
     * Se anuló o canceló una venta de cafetería.
     *
     * @param  array<string, mixed>  $extra
     */
    public function saleCancelled(ProductSale $sale, string $estadoPrevio, string $reason, bool $restocked, ?Admin $actor, Request $request, array $extra = []): void
    {
        $this->write($actor, $request, [
            'action' => 'status',
            'module' => 'Caja',
            'entity' => 'venta',
            'entity_id' => (string) $sale->id,
            'target_name' => $sale->code,
            'summary' => $estadoPrevio === 'pending'
                ? "Canceló el pedido {$sale->code}"
                : "Anuló la venta {$sale->code}".($restocked ? ' y reingresó el producto' : ''),
            'changes' => [['field' => 'status', 'before' => $estadoPrevio, 'after' => $sale->status]],
            'metadata' => array_filter([
                'total' => (string) $sale->total,
                'payment_method' => $sale->payment_method,
                'cash_shift_id' => $sale->cash_shift_id,
                'restocked' => $restocked,
                'reason' => $reason,
            ], static fn ($v) => $v !== null) + $extra,
        ]);
    }

    /**
     * Se devolvió (total o parcialmente) el dinero de una venta.
     *
     * @param  array<string, mixed>  $extra
     */
    public function saleRefunded(ProductSale $sale, CashRefund $refund, string $estadoPrevio, ?Admin $actor, Request $request, array $extra = []): void
    {
        $this->write($actor, $request, [
            'action' => 'refund',
            'module' => 'Caja',
            'entity' => 'venta',
            'entity_id' => (string) $sale->id,
            'target_name' => $sale->code,
            'summary' => ($sale->status === ProductSale::STATUS_REFUNDED ? 'Devolvió la venta ' : 'Devolvió parte de la venta ')
                .$sale->code.' ($'.number_format((float) $refund->amount, 0, ',', '.').')',
            'changes' => $estadoPrevio !== $sale->status
                ? [['field' => 'status', 'before' => $estadoPrevio, 'after' => $sale->status]]
                : null,
            'metadata' => array_filter([
                'amount' => (string) $refund->amount,
                'method' => $refund->method,
                'refund_id' => $refund->id,
                'refund_cash_shift_id' => $refund->cash_shift_id,
                'items' => $refund->items,
                'restocked' => (bool) $refund->restocked,
                'cash_shift_id' => $sale->cash_shift_id,
                'reason' => $refund->reason,
            ], static fn ($v) => $v !== null) + $extra,
        ]);
    }

    /**
     * Se corrigió el medio de pago de una venta.
     *
     * @param  array{method: ?string, splits: list<array{method: string, amount: string}>}  $antes
     * @param  array{method: ?string, splits: list<array{method: string, amount: string}>}  $despues
     * @param  array<string, mixed>  $extra
     */
    public function saleMethodChanged(ProductSale $sale, array $antes, array $despues, string $reason, ?Admin $actor, Request $request, array $extra = []): void
    {
        $this->write($actor, $request, [
            'action' => 'update',
            'module' => 'Caja',
            'entity' => 'venta',
            'entity_id' => (string) $sale->id,
            'target_name' => $sale->code,
            'summary' => "Corrigió el medio de pago de la venta {$sale->code}",
            'changes' => [
                ['field' => 'payment_method', 'before' => $antes['method'], 'after' => $despues['method']],
                ['field' => 'splits', 'before' => $antes['splits'], 'after' => $despues['splits']],
            ],
            'metadata' => array_filter([
                'total' => (string) $sale->total,
                'cash_shift_id' => $sale->cash_shift_id,
                'reason' => $reason,
            ], static fn ($v) => $v !== null) + $extra,
        ]);
    }

    /**
     * Se corrigió el medio de pago de un abono.
     *
     * @param  array<string, mixed>  $extra
     */
    public function receivablePaymentMethodChanged(ReceivablePayment $payment, Receivable $receivable, string $antes, string $reason, ?Admin $actor, Request $request, array $extra = []): void
    {
        $this->write($actor, $request, [
            'action' => 'update',
            'module' => 'Caja',
            'entity' => 'abono',
            'entity_id' => (string) $payment->id,
            'target_name' => $receivable->concept,
            'summary' => "Corrigió el medio de pago de un abono de {$receivable->concept}",
            'changes' => [['field' => 'method', 'before' => $antes, 'after' => $payment->method]],
            'metadata' => array_filter([
                'receivable_id' => $receivable->id,
                'amount' => (string) $payment->amount,
                'cash' => $receivable->type->value,
                'cash_shift_id' => $payment->cash_shift_id,
                'reason' => $reason,
                'member_id' => $receivable->member_id,
            ], static fn ($v) => $v !== null) + $extra,
        ]);
    }

    /**
     * Nace una cuenta por cobrar: alguien se lleva algo sin pagarlo entero.
     *
     * Se llama DENTRO de la transacción que crea la cuenta, igual que el resto:
     * una deuda sin traza de quién la abrió es exactamente lo que no puede
     * pasar con dinero ajeno.
     */
    public function receivableOpened(Receivable $receivable, ?Admin $actor, Request $request): void
    {
        $this->write($actor, $request, [
            'action' => 'create',
            'module' => 'Caja',
            'entity' => 'cuenta por cobrar',
            'entity_id' => (string) $receivable->id,
            'target_name' => $receivable->concept,
            'summary' => "Abrió una deuda de {$receivable->concept}",
            'metadata' => [
                'total' => (string) $receivable->total_amount,
                'cash' => $receivable->type->value,
                'debtor_type' => $receivable->debtor_type?->value,
                'debtor_id' => $receivable->debtor_id,
                'member_id' => $receivable->member_id,
            ],
        ]);
    }

    /**
     * Un abono acaba de aplicarse.
     *
     * El saldo ANTES y DESPUÉS va en la traza a propósito: sin ellos habría que
     * reconstruirlo sumando el resto de abonos, y eso deja de funcionar en
     * cuanto uno se revierte.
     */
    public function receivablePaid(ReceivablePayment $payment, Receivable $receivable, ?Admin $actor, Request $request): void
    {
        $liquidada = (float) $payment->balance_after === 0.0;

        $this->write($actor, $request, [
            'action' => 'create',
            'module' => 'Caja',
            'entity' => 'abono',
            'entity_id' => (string) $payment->id,
            'target_name' => $receivable->concept,
            'summary' => $liquidada
                ? "Registró el abono que liquida {$receivable->concept}"
                : "Registró un abono de {$receivable->concept}",
            'metadata' => [
                'receivable_id' => $receivable->id,
                'amount' => (string) $payment->amount,
                'method' => $payment->method,
                'cash' => $receivable->type->value,
                'cash_shift_id' => $payment->cash_shift_id,
                'balance_before' => (string) $payment->balance_before,
                'balance_after' => (string) $payment->balance_after,
                'settled' => $liquidada,
                'member_id' => $receivable->member_id,
            ],
        ]);
    }

    /**
     * Un abono se anuló.
     *
     * `action` es `status` y no `delete` porque no se borra nada: el abono
     * sigue ahí, marcado. La traza tiene que decir lo mismo que la tabla.
     */
    public function receivableReversed(ReceivablePayment $payment, Receivable $receivable, string $reason, ?Admin $actor, Request $request): void
    {
        $this->write($actor, $request, [
            'action' => 'status',
            'module' => 'Caja',
            'entity' => 'abono',
            'entity_id' => (string) $payment->id,
            'target_name' => $receivable->concept,
            'summary' => "Anuló un abono de {$receivable->concept}",
            'changes' => [[
                'field' => 'status',
                'before' => ReceivablePayment::STATUS_APPLIED,
                'after' => ReceivablePayment::STATUS_REVERSED,
            ]],
            'metadata' => [
                'receivable_id' => $receivable->id,
                'amount' => (string) $payment->amount,
                'method' => $payment->method,
                'cash' => $receivable->type->value,
                'cash_shift_id' => $payment->cash_shift_id,
                'reason' => $reason,
                'member_id' => $receivable->member_id,
            ],
        ]);
    }

    /**
     * Una deuda se anuló.
     *
     * `action` es `status`, nunca `delete`: la fila no se borra y sus abonos
     * siguen aplicados. La traza guarda el SALDO que se dejó de exigir —no el
     * total— porque es la cifra que alguien tendrá que justificar: anular una
     * deuda de 80.000 con 60.000 ya cobrados perdona 20.000, no 80.000.
     *
     * `$estadoPrevio` llega por parámetro y no de `getOriginal()`: para cuando
     * se audita, el guardado ya sincronizó el original y preguntarle devolvería
     * `cancelled` en los dos lados del cambio.
     */
    public function receivableCancelled(
        Receivable $receivable,
        string $estadoPrevio,
        string $reason,
        ?Admin $actor,
        Request $request,
    ): void {
        $this->write($actor, $request, [
            'action' => 'status',
            'module' => 'Caja',
            'entity' => 'cuenta por cobrar',
            'entity_id' => (string) $receivable->id,
            'target_name' => $receivable->concept,
            'summary' => "Anuló la deuda de {$receivable->concept}",
            'changes' => [[
                'field' => 'status',
                'before' => $estadoPrevio,
                'after' => Receivable::STATUS_CANCELLED,
            ]],
            'metadata' => [
                'total' => (string) $receivable->total_amount,
                'paid' => (string) $receivable->paidAmount()->toFloat(),
                'forgiven' => (string) $receivable->balance()->toFloat(),
                'cash' => $receivable->type->value,
                'due_at' => optional($receivable->due_at)->toDateString(),
                'reason' => $reason,
                'member_id' => $receivable->member_id,
            ],
        ]);
    }

    /**
     * Una deuda anulada volvió a estar viva.
     *
     * El estado al que vuelve NO se elige: lo recalcula el saldo. Va en la traza
     * porque reabrir una deuda con plazo vencido vuelve a retener al socio en el
     * acto, y quien revise el caso después necesita ver que eso fue una
     * consecuencia de esta operación y no un fallo del detector.
     */
    public function receivableReopened(Receivable $receivable, string $reason, ?Admin $actor, Request $request): void
    {
        $this->write($actor, $request, [
            'action' => 'status',
            'module' => 'Caja',
            'entity' => 'cuenta por cobrar',
            'entity_id' => (string) $receivable->id,
            'target_name' => $receivable->concept,
            'summary' => "Reabrió la deuda de {$receivable->concept}",
            'changes' => [[
                'field' => 'status',
                'before' => Receivable::STATUS_CANCELLED,
                'after' => $receivable->status,
            ]],
            'metadata' => [
                'total' => (string) $receivable->total_amount,
                'balance' => (string) $receivable->balance()->toFloat(),
                'cash' => $receivable->type->value,
                'due_at' => optional($receivable->due_at)->toDateString(),
                'overdue' => $receivable->isOverdue(),
                'reason' => $reason,
                'member_id' => $receivable->member_id,
            ],
        ]);
    }

    /**
     * Se movió —o se quitó— el plazo de una deuda.
     *
     * Las dos fechas van como `changes` y no como metadatos sueltos: mover un
     * vencimiento es lo que levanta o impone una retención, y para revisarlo
     * después hace falta ver el salto, no solo dónde acabó.
     */
    public function receivableDueDateChanged(
        Receivable $receivable,
        ?string $antes,
        ?string $despues,
        string $reason,
        ?Admin $actor,
        Request $request,
    ): void {
        $this->write($actor, $request, [
            'action' => 'update',
            'module' => 'Caja',
            'entity' => 'cuenta por cobrar',
            'entity_id' => (string) $receivable->id,
            'target_name' => $receivable->concept,
            'summary' => $despues === null
                ? "Quitó el plazo de {$receivable->concept}"
                : "Cambió el plazo de {$receivable->concept} al {$despues}",
            'changes' => [[
                'field' => 'due_at',
                'before' => $antes,
                'after' => $despues,
            ]],
            'metadata' => [
                'balance' => (string) $receivable->balance()->toFloat(),
                'cash' => $receivable->type->value,
                'overdue' => $receivable->isOverdue(),
                'reason' => $reason,
                'member_id' => $receivable->member_id,
            ],
        ]);
    }

    /**
     * Escribe la fila.
     *
     * A diferencia del resto de auditorías del proyecto esto NO va envuelto en
     * try/catch. Es deliberado: al ir dentro de la transacción de la operación,
     * tragarse el fallo devolvería exactamente lo que se está corrigiendo —una
     * venta cobrada sin traza— y encima en silencio. Si la traza no se puede
     * escribir, la operación no se confirma.
     *
     * @param  array<string, mixed>  $evento
     */
    private function write(?Admin $actor, Request $request, array $evento): void
    {
        if ($actor === null) {
            return;
        }

        AuditLog::create(array_merge($evento, [
            'actor_id' => (string) $actor->id,
            // Congelado como instantánea: la traza debe seguir diciendo quién
            // fue aunque la cuenta se renombre o se elimine después.
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'created_at' => now(),
        ]));
    }
}
