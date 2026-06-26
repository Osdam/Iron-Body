<?php

namespace App\Services\Payments;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\InvoicingService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Activación de membresía al aprobarse un pago — fuente ÚNICA y compartida por
 * todos los métodos de la pasarela Wompi (tarjeta/PSE/Nequi/DaviPlata).
 *
 * Garantiza que la membresía se active UNA sola vez por referencia (idempotente
 * vía `payments.reference` único) y que la extensión de fechas sea idéntica sin
 * importar el proveedor. Best-effort: nunca rompe la confirmación del pago.
 *
 * REGLA: el Home solo se desbloquea cuando MembershipService::isActive() es true
 * (membresía vigente). Aquí se establece esa verdad; ningún cliente la fabrica.
 */
class PaymentMembershipActivator
{
    /**
     * Al aprobarse: crea el registro legado en `payments` y extiende membresía.
     * Si llega member_id, usa su user_id enlazado para mantener una sola ficha.
     *
     * @param  string  $method  método persistido en `payments.method` (wompi|nequi).
     */
    public function activate(PaymentTransaction $tx, string $method = 'wompi'): void
    {
        try {
            if (! $tx->user_id && $tx->member_id) {
                $member = Member::with('user')->find($tx->member_id);
                if ($member?->user_id) {
                    $tx->forceFill(['user_id' => $member->user_id])->save();
                }
            }

            if (! $tx->user_id || ! User::whereKey($tx->user_id)->exists()) {
                return; // sin usuario al que asociar (app con usuario mock)
            }

            // Idempotencia dura: una sola fila legada por referencia → la
            // membresía se extiende UNA vez aunque el webhook reintente.
            $payment = Payment::firstOrCreate(
                ['reference' => $tx->reference],
                [
                    'user_id'   => $tx->user_id,
                    'member_id' => $tx->member_id,
                    'plan_id'   => $tx->plan_id,
                    'amount'    => $tx->amount,
                    'method'    => $method,
                    'status'    => 'paid',
                    'paid_at'   => $tx->paid_at ?? now(),
                ]
            );
            if ($payment->wasRecentlyCreated && $tx->plan_id) {
                $this->extendMembership($payment);
            }

            if ($tx->member_id) {
                Member::whereKey($tx->member_id)->update(['status' => Member::STATUS_ACTIVE]);
            }

            // Notificaciones (ADITIVO; idempotentes por event_key).
            $member   = $tx->member_id ? Member::find($tx->member_id) : null;
            $notifier = app(NotificationService::class);
            $notifier->notifyPaymentApproved($member, $tx);
            if ($tx->plan_id) {
                $plan = Plan::find($tx->plan_id);
                $endDate = $tx->user_id ? optional(User::find($tx->user_id))->membership_end_date : null;
                $notifier->notifyMembershipActivated($member, [
                    'name'                => $plan?->name,
                    'id'                  => $tx->plan_id,
                    'membership_end_date' => $endDate,
                ]);
            }

            // Facturación electrónica (ADITIVO, best-effort, idempotente por
            // source+type). Con FACTUS_ENABLED=false solo crea la factura
            // 'pending'; nunca llama a Factus ni bloquea la activación del pago.
            //
            // Si el cliente SOLICITÓ la factura desde la app (metadata.wants_invoice),
            // se fuerza la emisión a Factus aunque auto_emit global esté apagado:
            // mismo camino que la emisión manual del CRM (force=true). El envío del
            // comprobante por correo lo resuelve el job según la config de billing.
            $wantsInvoice = (bool) ($tx->metadata['wants_invoice'] ?? false);
            app(InvoicingService::class)->enqueueForPayment($payment, force: $wantsInvoice);
        } catch (Throwable $e) {
            Log::warning('Activación de membresía post-pago falló', [
                'reference' => $tx->reference,
                'provider'  => $tx->provider,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /** Extiende (o inicia) la membresía del usuario según el plan pagado. */
    public function extendMembership(Payment $payment): void
    {
        $user = User::find($payment->user_id);
        $plan = $payment->plan_id ? Plan::find($payment->plan_id) : null;
        if (! $user || ! $plan || (int) $plan->duration_days <= 0) {
            return;
        }
        $paidDate = $payment->paid_at
            ? Carbon::parse($payment->paid_at)->startOfDay()
            : Carbon::today();
        $currentEnd = $user->membership_end_date
            ? Carbon::parse($user->membership_end_date)->startOfDay()
            : null;
        $baseDate = $currentEnd && $currentEnd->greaterThan($paidDate)
            ? $currentEnd
            : $paidDate;
        if (! $currentEnd || $currentEnd->lessThan($paidDate) || ! $user->membership_start_date) {
            $user->membership_start_date = $paidDate->toDateString();
        }
        $user->membership_end_date = $baseDate->copy()
            ->addDays((int) $plan->duration_days)->toDateString();
        $user->plan = $plan->name;
        $user->status = 'active';
        $user->save();
    }
}
