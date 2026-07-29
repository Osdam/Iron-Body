<?php

namespace App\Services\Payments;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\InvoiceEmail;
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
                array_merge([
                    'user_id' => $tx->user_id,
                    'member_id' => $tx->member_id,
                    'plan_id' => $tx->plan_id,
                    'amount' => $tx->amount,
                    'method' => $method,
                    'status' => 'paid',
                    'paid_at' => $tx->paid_at ?? now(),
                ], self::snapshotFromTransaction($tx))
            );
            if ($payment->wasRecentlyCreated && $tx->plan_id) {
                $this->extendMembership($payment);
            }

            if ($tx->member_id) {
                Member::whereKey($tx->member_id)->update(['status' => Member::STATUS_ACTIVE]);
            }

            // Notificaciones (ADITIVO; idempotentes por event_key).
            $member = $tx->member_id ? Member::find($tx->member_id) : null;
            $notifier = app(NotificationService::class);
            $notifier->notifyPaymentApproved($member, $tx);
            if ($tx->plan_id) {
                $plan = Plan::find($tx->plan_id);
                $endDate = $tx->user_id ? optional(User::find($tx->user_id))->membership_end_date : null;
                $notifier->notifyMembershipActivated($member, [
                    'name' => $plan?->name,
                    'id' => $tx->plan_id,
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
            $wantsInvoice = self::persistInvoiceRequest($payment, $tx);
            app(InvoicingService::class)->enqueueForPayment($payment, force: $wantsInvoice);
        } catch (Throwable $e) {
            Log::warning('Activación de membresía post-pago falló', [
                'reference' => $tx->reference,
                'provider' => $tx->provider,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Traslada al PAGO la solicitud de factura que viajó en la transacción.
     *
     * El cliente marcó la casilla en el checkout y eso quedó en
     * `metadata.wants_invoice`. A partir de aquí la intención vive en el pago,
     * que es el hecho económico: así la factura puede emitirse (o reintentarse
     * meses después) sin depender de que la transacción de pasarela siga
     * existiendo o conserve su metadata.
     *
     * Idempotente: `marcarFacturaSolicitada()` conserva la primera fecha, de
     * modo que un webhook repetido o una reconciliación no crean una segunda
     * solicitud ni mueven la fecha original. Se mantiene `metadata` intacta.
     *
     * El correo se toma primero del que indicó el cliente y, si no sirve, del
     * de la transacción — nunca se inventa uno: si ninguno es entregable, la
     * factura se emite igual pero sin envío, y el fallo queda visible.
     */
    private static function persistInvoiceRequest(Payment $payment, PaymentTransaction $tx): bool
    {
        if ((bool) ($tx->metadata['wants_invoice'] ?? false) !== true) {
            return (bool) $payment->invoice_requested;
        }

        $email = InvoiceEmail::primeroEntregable(
            $tx->metadata['invoice_email'] ?? null,
            $tx->customer_email,
            $tx->customer['email'] ?? null,
        );

        $payment->marcarFacturaSolicitada($email);

        return true;
    }

    /**
     * Traslada al pago la cotización CONGELADA con la que se autorizó el cobro.
     *
     * Es el eslabón que conecta "lo que Wompi cobró" con "lo que se factura":
     * el pago hereda el mismo desglose de la transacción, así que la factura no
     * necesita volver a mirar el plan. Sin snapshot (transacciones legacy)
     * devuelve un array vacío y el pago conserva el comportamiento anterior.
     *
     * @return array<string,mixed>
     */
    private static function snapshotFromTransaction(PaymentTransaction $tx): array
    {
        if ($tx->gross_amount === null) {
            return [];
        }

        return [
            'base_amount' => $tx->base_amount,
            'tax_amount' => $tx->tax_amount,
            'gross_amount' => $tx->gross_amount,
            'discount_amount' => $tx->discount_amount,
            'tax_rate_id' => $tx->tax_rate_id,
            'tax_rate' => $tx->tax_rate,
            'pricing_mode' => $tx->pricing_mode,
            'pricing_rules_version' => $tx->pricing_rules_version,
            'currency' => $tx->currency,
            'priced_at' => $tx->priced_at,
        ];
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
