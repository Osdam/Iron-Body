<?php

namespace App\Console\Commands;

use App\Enums\CashShiftType;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\AutomationEventService;
use App\Services\RealtimeEvents;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Detecta que una obligación CRUZÓ el vencimiento, o que dejó de estar vencida.
 *
 * ESTO NO BLOQUEA A NADIE. El bloqueo se calcula al leer, en
 * {@see \App\Services\Caja\MembershipFinancialStanding}, y por eso pagar
 * desbloquea en la misma petición sin esperar a este comando. Lo único que hace
 * aquí es avisar: alertar a administración, empujar el refresco de la app y
 * alimentar la automatización.
 *
 * IDEMPOTENCIA. Cada transición lleva una llave única en `automation_events`,
 * así que correr el comando dos veces no emite dos avisos. La parte delicada es
 * que una MISMA cuenta puede vencer, pagarse, revertirse y volver a vencer, y
 * todas esas transiciones son legítimas: si la llave no las distinguiera, la
 * segunda mora quedaría silenciada para siempre.
 *
 * Por eso la llave lleva una GENERACIÓN del saldo —cuántos abonos hay, cuál es
 * el último y cuántos siguen aplicados— que avanza tanto al abonar como al
 * revertir y nunca repite un valor anterior del ciclo.
 */
class DetectOverdueReceivables extends Command
{
    protected $signature = 'ironbody:detect-overdue-receivables
        {--days=30 : Ventana hacia atrás para buscar cuentas ya saldadas que avisar}
        {--dry-run : Solo informa; no emite ningún evento}';

    protected $description = 'Detecta transiciones de mora en cuentas por cobrar (gimnasio y productos).';

    public function handle(AutomationEventService $events): int
    {
        $hoy = Receivable::businessToday();
        $seco = (bool) $this->option('dry-run');

        $vencidas = $this->emitirVencidas($events, $hoy, $seco);
        $saldadas = $this->emitirSaldadas($events, $hoy, $seco, max((int) $this->option('days'), 1));

        $this->info(sprintf(
            'Mora al %s → transiciones a vencida: %d · a saldada: %d%s',
            $hoy->toDateString(), $vencidas, $saldadas, $seco ? ' (simulacro)' : '',
        ));

        return self::SUCCESS;
    }

    /** Cuentas que hoy están vencidas y todavía deben. */
    private function emitirVencidas(AutomationEventService $events, Carbon $hoy, bool $seco): int
    {
        $emitidas = 0;

        Receivable::query()
            ->overdue($hoy)
            ->chunkById(200, function ($cuentas) use ($events, $hoy, $seco, &$emitidas): void {
                foreach ($cuentas as $cuenta) {
                    if ($this->emitirTransicion($events, $cuenta, 'overdue', $hoy, $seco)) {
                        $emitidas++;
                    }
                }
            });

        return $emitidas;
    }

    /**
     * Cuentas que tuvieron plazo, ya se saldaron y merecen el aviso de cierre.
     *
     * Se acota a las que recibieron movimiento en la ventana: sin ese límite el
     * comando releería toda la historia del gimnasio cada noche para no emitir
     * nada, y eso es exactamente lo que pediste evitar.
     */
    private function emitirSaldadas(AutomationEventService $events, Carbon $hoy, bool $seco, int $dias): int
    {
        $emitidas = 0;
        $desde = now()->subDays($dias);

        Receivable::query()
            ->where('status', Receivable::STATUS_PAID)
            ->whereNotNull('due_at')
            ->whereHas('payments', fn ($q) => $q->where('receivable_payments.updated_at', '>=', $desde))
            ->chunkById(200, function ($cuentas) use ($events, $hoy, $seco, &$emitidas): void {
                foreach ($cuentas as $cuenta) {
                    // Solo se avisa del cierre de lo que llegó a estar vencido.
                    if (! $this->huboMora($cuenta)) {
                        continue;
                    }
                    if ($this->emitirTransicion($events, $cuenta, 'cleared', $hoy, $seco)) {
                        $emitidas++;
                    }
                }
            });

        return $emitidas;
    }

    /**
     * Emite una transición si procede. Devuelve true solo si la emitió de nuevo.
     *
     * El predicado se revalida DENTRO de la transacción y con la fila bloqueada:
     * entre que la consulta trajo la cuenta y llegamos aquí puede haber entrado
     * el pago final por caja, y avisar entonces de una mora que ya no existe
     * sería una notificación fantasma.
     */
    private function emitirTransicion(
        AutomationEventService $events,
        Receivable $cuenta,
        string $transicion,
        Carbon $hoy,
        bool $seco,
    ): bool {
        return DB::transaction(function () use ($events, $cuenta, $transicion, $hoy, $seco): bool {
            $fresca = Receivable::whereKey($cuenta->id)->lockForUpdate()->first();
            if ($fresca === null) {
                return false;
            }

            $vencida = $fresca->isOverdue($hoy);
            $procede = $transicion === 'overdue' ? $vencida : ($fresca->balance()->isZero() && ! $fresca->isCancelled());
            if (! $procede) {
                return false;
            }

            $tipo = $this->nombreEvento($fresca, $transicion);
            $llave = $this->llave($fresca, $transicion);

            if ($seco) {
                $this->line("  [simulacro] {$tipo} · {$llave}");

                return true;
            }

            $esNueva = $events->emit($tipo, $fresca->member_id, [
                'receivable_id' => $fresca->id,
                'type' => $fresca->type->value,
                'concept' => $fresca->concept,
                'total' => $fresca->total()->toFloat(),
                'balance' => $fresca->balance()->toFloat(),
                'due_date' => optional($fresca->due_at)->toDateString(),
                'days_overdue' => $fresca->daysOverdue($hoy),
            ], $llave)->wasRecentlyCreated;

            // Solo se empuja el refresco de la app cuando la transición es
            // nueva; si no, cada corrida despertaría a todos los móviles.
            if ($esNueva && $fresca->type === CashShiftType::GYM && $fresca->member_id !== null) {
                RealtimeEvents::emit($fresca->member_id, RealtimeEvents::APP_STATE, ['membership', 'financial']);
            }

            return $esNueva;
        });
    }

    /** ¿Esta cuenta llegó a estar vencida alguna vez? */
    private function huboMora(Receivable $cuenta): bool
    {
        return DB::table('automation_events')
            ->where('event_type', $this->nombreEvento($cuenta, 'overdue'))
            ->where('idempotency_key', 'like', $this->prefijoLlave($cuenta, 'overdue').'%')
            ->exists();
    }

    private function nombreEvento(Receivable $cuenta, string $transicion): string
    {
        return $cuenta->type === CashShiftType::GYM
            ? "receivable.{$transicion}"
            : "receivable.products_{$transicion}";
    }

    private function prefijoLlave(Receivable $cuenta, string $transicion): string
    {
        return $this->nombreEvento($cuenta, $transicion).':'.$cuenta->id.':';
    }

    /**
     * Llave de idempotencia con generación del saldo.
     *
     * `{abonos totales}.{último id}.{abonos aplicados}` avanza al abonar (sube
     * el total y el último id) y también al revertir (baja los aplicados), y no
     * vuelve a un valor ya usado: por eso el ciclo vencida → saldada → reverso →
     * vencida otra vez emite las cuatro transiciones, una sola vez cada una.
     *
     * En la mora entra además `due_at`: cambiar la fecha pactada es una mora
     * distinta y merece su propio aviso.
     */
    private function llave(Receivable $cuenta, string $transicion): string
    {
        $gen = $this->generacion($cuenta);
        $llave = $this->prefijoLlave($cuenta, $transicion);

        return $transicion === 'overdue'
            ? $llave.optional($cuenta->due_at)->toDateString().':'.$gen
            : $llave.$gen;
    }

    private function generacion(Receivable $cuenta): string
    {
        $fila = ReceivablePayment::query()
            ->where('receivable_id', $cuenta->id)
            ->selectRaw('COUNT(*) AS todos, COALESCE(MAX(id),0) AS ultimo')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS aplicados', [ReceivablePayment::STATUS_APPLIED])
            ->first();

        return ($fila->todos ?? 0).'.'.($fila->ultimo ?? 0).'.'.($fila->aplicados ?? 0);
    }
}
