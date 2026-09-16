<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repara las membresías que quedaron largas por cobros anulados.
 *
 *   php artisan memberships:repair-cancelled            (solo informa)
 *   php artisan memberships:repair-cancelled --apply
 *   php artisan memberships:repair-cancelled --user=1234 --apply
 *
 * POR QUÉ. Hasta el arreglo de MembershipPeriod::revert(), anular un cobro
 * cambiaba su estado y nada más: los días que había sumado seguían dentro de
 * `users.membership_end_date`. Una socia con plan de 30 días llegó a ver 55 en
 * la app porque su primer cobro se anuló, el segundo se encadenó al final de un
 * periodo que ya no existía, y nadie le restó el mes fantasma.
 *
 * El código nuevo no arregla lo ya guardado. Este comando sí: recorre los
 * cobros que NO están cobrados pero conservan el periodo congelado —la huella
 * exacta de «se anuló y nunca se devolvió»— y les aplica la misma devolución.
 *
 * NO ADIVINA. `revert()` deja intacto cualquier socio cuya fecha de
 * vencimiento no la expliquen sus propios pagos (importaciones del sistema
 * anterior, ajustes manuales): esos salen listados como omitidos para mirarlos
 * a mano. Correrlo dos veces no hace nada la segunda: al devolver los días el
 * pago pierde su periodo y deja de ser candidato.
 */
class RepairCancelledPaymentMemberships extends Command
{
    protected $signature = 'memberships:repair-cancelled
        {--apply : Guarda los cambios. Sin esta opción solo informa}
        {--user= : Reparar un solo socio (users.id)}';

    protected $description = 'Devuelve los días de membresía de cobros anulados que nunca se restaron';

    public function handle(MembershipPeriod $periodos): int
    {
        $aplicar = (bool) $this->option('apply');

        $candidatos = Payment::query()
            ->whereNotNull('plan_id')
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereRaw(
                'LOWER(status) NOT IN ('.implode(',', array_fill(0, count(PaymentController::PAID_STATUSES), '?')).')',
                PaymentController::PAID_STATUSES
            )
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderBy('user_id')
            ->orderBy('period_start')
            ->get();

        if ($candidatos->isEmpty()) {
            $this->info('No hay cobros anulados con periodo pendiente de devolver.');

            return self::SUCCESS;
        }

        $this->line($candidatos->count().' cobro(s) anulado(s) conservan el periodo que dieron.');
        $this->newLine();

        $reparados = 0;
        $omitidos = 0;

        foreach ($candidatos as $pago) {
            $user = User::find($pago->user_id);
            $quien = $user ? "#{$user->id} {$user->name}" : "user_id={$pago->user_id}";
            $periodo = $pago->period_start->toDateString().' → '.$pago->period_end->toDateString();

            // En seco se calcula igual y se deshace: es la única forma de decir
            // de antemano qué fecha va a quedar sin duplicar las reglas.
            DB::beginTransaction();
            $resultado = $periodos->revert($pago);
            $aplicar ? DB::commit() : DB::rollBack();

            if ($resultado === null) {
                $omitidos++;
                $this->warn("  omitido  cobro #{$pago->id} ({$quien}) [{$periodo}] — su vencimiento no lo explican sus pagos");

                continue;
            }

            $reparados++;
            $this->info("  {$quien}: {$resultado['previous_end']} → {$resultado['end']}"
                ." (−{$resultado['days']} días, cobro #{$pago->id} {$pago->status})");
        }

        $this->newLine();
        $this->line($aplicar
            ? "Listo: {$reparados} membresía(s) corregida(s), {$omitidos} para revisar a mano."
            : "En seco: {$reparados} se corregirían, {$omitidos} para revisar a mano. Repite con --apply.");

        return self::SUCCESS;
    }
}
