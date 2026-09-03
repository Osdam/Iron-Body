<?php

namespace App\Services\Audit;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\ProductSale;
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
     */
    public function paymentUpdated(Payment $payment, ?string $estadoPrevio, ?Admin $actor, Request $request): void
    {
        $cambioDeEstado = $estadoPrevio !== null && $estadoPrevio !== $payment->status;

        $this->write($actor, $request, [
            'action' => $cambioDeEstado ? 'status' : 'update',
            'module' => 'Pagos',
            'entity' => 'pago',
            'entity_id' => (string) $payment->id,
            'target_name' => $payment->reference,
            'summary' => $cambioDeEstado
                ? "Cambió el cobro de {$estadoPrevio} a {$payment->status}"
                : 'Modificó los datos de un cobro',
            'changes' => $cambioDeEstado
                ? [['field' => 'status', 'before' => $estadoPrevio, 'after' => $payment->status]]
                : null,
            'metadata' => [
                'amount' => (string) $payment->amount,
                'method' => $payment->method,
                'status' => $payment->status,
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
