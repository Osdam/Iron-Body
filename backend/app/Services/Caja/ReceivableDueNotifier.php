<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\AppNotification;
use App\Models\CommercialAlert;
use App\Models\Receivable;
use App\Models\TrainerTask;
use App\Services\Billing\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Avisa de una deuda: al que debe, y a quien tiene que cobrarla.
 *
 * NO INVENTA NINGÚN CANAL. Usa los que ya existen —notificaciones in-app del
 * socio, tareas del entrenador, alertas comerciales del CRM— porque un aviso en
 * un sitio que nadie mira no es un aviso. Y NO usa SMS: el control de coste de
 * Twilio se acaba de cerrar y mandar recordatorios por ahí lo desharía.
 *
 * QUIÉN RECIBE QUÉ. Al deudor se le avisa de su propia deuda por el canal que
 * tenga: el socio en la app, el entrenador en su bandeja. A un administrador o a
 * recepción no se les manda un aviso personal —no tienen app— y su deuda se ve
 * en la alerta interna como cualquier otra.
 *
 * A administración se le avisa SOLO cuando algo vence. Recordar que «vence
 * mañana» es asunto del deudor; si se alertara al equipo en cada recordatorio,
 * la bandeja se llenaría de cosas que no requieren hacer nada y dejarían de
 * leerse justo cuando alguna sí lo requiera.
 *
 * La idempotencia NO se resuelve aquí. Sólo se llama cuando la transición es
 * nueva de verdad (ver {@see \App\Console\Commands\DetectOverdueReceivables}),
 * y tener dos mecanismos de deduplicación es la forma segura de que se
 * contradigan.
 */
class ReceivableDueNotifier
{
    public const DUE_SOON = 'due_soon';

    public const DUE_TODAY = 'due_today';

    public const OVERDUE = 'overdue';

    public const CLEARED = 'cleared';

    /** Tipo de alerta interna para una cuenta por cobrar vencida. */
    public const ALERT_TYPE = 'cuenta_por_cobrar_vencida';

    /**
     * Marca de que la alerta la cerró el sistema porque la deuda se pagó, y no
     * una persona decidiendo que no hacía falta seguir. La diferencia importa:
     * lo primero se puede deshacer solo, lo segundo no.
     */
    public const RESOLUTION_PAID = 'paid';

    /** La deuda se anuló: ya no hay nada que cobrar, pero nadie pagó. */
    public const RESOLUTION_CANCELLED = 'cancelled';

    /** Se pactó un plazo nuevo: sigue debiéndose, pero hoy ya no está vencida. */
    public const RESOLUTION_RESCHEDULED = 'rescheduled';

    /**
     * Los cierres que puso el sistema y que el sistema puede deshacer solo.
     *
     * Ninguno de los tres es una decisión de «esto ya no hace falta seguirlo»:
     * los tres describen un hecho —se pagó, se anuló, se aplazó— que puede
     * dejar de ser cierto. El cierre a mano no está aquí a propósito.
     */
    public const SYSTEM_RESOLUTIONS = [
        self::RESOLUTION_PAID,
        self::RESOLUTION_CANCELLED,
        self::RESOLUTION_RESCHEDULED,
    ];

    public function notify(Receivable $cuenta, string $transicion, Carbon $hoy, string $llave): void
    {
        try {
            $this->avisarAlDeudor($cuenta, $transicion, $hoy, $llave);

            if ($transicion === self::OVERDUE) {
                $this->alertarAdministracion($cuenta, $hoy);
            }

            if ($transicion === self::CLEARED) {
                $this->cerrarAlerta($cuenta);
            }
        } catch (\Throwable $e) {
            // Un aviso que falla no puede tumbar la detección: el estado de la
            // deuda es la verdad, la notificación solo la cuenta.
            Log::warning('receivable.notify_failed', [
                'receivable_id' => $cuenta->id,
                'transition' => $transicion,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── El deudor ────────────────────────────────────────────────────────────

    private function avisarAlDeudor(Receivable $cuenta, string $transicion, Carbon $hoy, string $llave): void
    {
        [$titulo, $cuerpo] = $this->mensaje($cuenta, $transicion, $hoy);

        match ($cuenta->debtor_type) {
            DebtorType::MEMBER => $this->avisarSocio($cuenta, $transicion, $titulo, $cuerpo),
            DebtorType::TRAINER => $this->avisarEntrenador($cuenta, $titulo, $cuerpo, $llave),
            // Administración y recepción no tienen bandeja personal: su deuda
            // aparece en la alerta interna, que es donde ya miran.
            default => null,
        };
    }

    private function avisarSocio(Receivable $cuenta, string $transicion, string $titulo, string $cuerpo): void
    {
        if ($cuenta->member_id === null) {
            return;
        }

        AppNotification::create([
            'member_id' => $cuenta->member_id,
            'type' => 'receivable_'.$transicion,
            'title' => $titulo,
            'body' => $cuerpo,
            'action_type' => 'route',
            // A dónde tiene que ir para resolverlo.
            'action_route' => $cuenta->type === CashShiftType::GYM ? '/membership' : '/payments',
            'payload_json' => $this->carga($cuenta),
            'source' => 'caja',
            'priority' => $transicion === self::OVERDUE ? 'high' : 'normal',
        ]);
    }

    private function avisarEntrenador(Receivable $cuenta, string $titulo, string $cuerpo, string $llave): void
    {
        if ($cuenta->debtor_id === null) {
            return;
        }

        TrainerTask::updateOrCreate(
            ['idempotency_key' => $llave],
            [
                'trainer_id' => $cuenta->debtor_id,
                'type' => 'receivable_due',
                'title' => $titulo,
                'body' => $cuerpo,
                'priority' => 'normal',
                'status' => TrainerTask::STATUS_PENDING,
                'metadata' => $this->carga($cuenta),
                'due_at' => $cuenta->due_at,
            ],
        );
    }

    // ── Administración ───────────────────────────────────────────────────────

    /**
     * Alerta interna, una por cuenta.
     *
     * La huella lleva el id de la cuenta y nada más: mientras siga vencida es
     * el MISMO problema, y abrir una alerta por cada día de mora convertiría la
     * bandeja en un contador. Si alguien ya la cerró a mano, no se reabre: esa
     * decisión se respeta.
     */
    public function alertarAdministracion(Receivable $cuenta, Carbon $hoy): void
    {
        $huella = 'receivable_overdue:'.$cuenta->id;
        $saldo = $cuenta->balance();
        $dias = $cuenta->daysOverdue($hoy);
        $deudor = $cuenta->debtor()['name'] ?? $cuenta->member?->full_name ?? 'Deudor sin ficha';

        $existente = CommercialAlert::where('fingerprint', $huella)->first();

        if ($existente !== null) {
            // Cerrada POR UNA PERSONA: no se reabre. Si alguien decidió que no
            // hacía falta seguir, volver a abrirla en la siguiente corrida
            // convierte su decisión en ruido y entrena a no usar el botón.
            //
            // Cerrada POR NOSOTROS al cobrarse: eso es otra cosa. Si el pago se
            // anula la deuda vuelve a existir de verdad, y callarla dejaría una
            // mora real sin que nadie se entere.
            $laCerroElSistema = ! $existente->isOpen()
                && in_array($existente->resolution, self::SYSTEM_RESOLUTIONS, true);

            if (! $existente->isOpen() && ! $laCerroElSistema) {
                return;
            }

            $existente->forceFill([
                'status' => CommercialAlert::STATUS_OPEN,
                'resolved_at' => null,
                'resolution' => null,
                'resolution_note' => null,
                'severity' => $this->severidad($dias),
                'summary' => $this->resumenAlerta($deudor, $saldo, $dias, $cuenta),
                'evidence' => $this->carga($cuenta),
            ])->save();

            return;
        }

        CommercialAlert::create([
            'type' => self::ALERT_TYPE,
            'severity' => $this->severidad($dias),
            'status' => CommercialAlert::STATUS_OPEN,
            'member_id' => $cuenta->member_id,
            'title' => ($cuenta->type === CashShiftType::GYM ? 'Membresía' : 'Cafetería')
                .' · saldo vencido de '.$this->money($saldo),
            'summary' => $this->resumenAlerta($deudor, $saldo, $dias, $cuenta),
            'evidence' => $this->carga($cuenta),
            'suggested_action' => $cuenta->type === CashShiftType::GYM
                ? 'Contactar al socio: su membresía está retenida hasta que salde.'
                : 'Contactar al deudor para cobrar el crédito de cafetería.',
            'opportunity_value' => $saldo->toFloat(),
            'detected_at' => now(),
            'due_at' => $cuenta->due_at,
            'fingerprint' => $huella,
        ]);
    }

    /**
     * La alerta deja de tener sentido: se cierra diciendo POR QUÉ.
     *
     * El motivo no es decorativo. `alertarAdministracion()` lo lee para decidir
     * si puede reabrirla cuando el problema vuelva, y meterlo todo bajo «pagada»
     * haría que una deuda anulada figurase como cobrada en la bandeja de quien
     * revisa la mora del mes.
     */
    public function cerrarAlerta(
        Receivable $cuenta,
        string $resolucion = self::RESOLUTION_PAID,
        string $nota = 'La cuenta quedó saldada.',
    ): void {
        CommercialAlert::where('fingerprint', 'receivable_overdue:'.$cuenta->id)
            ->where('status', CommercialAlert::STATUS_OPEN)
            ->update([
                'status' => CommercialAlert::STATUS_RESOLVED,
                'resolved_at' => now(),
                'resolution' => $resolucion,
                'resolution_note' => $nota,
            ]);
    }

    // ── Texto ────────────────────────────────────────────────────────────────

    /** @return array{0:string,1:string} */
    private function mensaje(Receivable $cuenta, string $transicion, Carbon $hoy): array
    {
        $saldo = $this->money($cuenta->balance());
        $esGimnasio = $cuenta->type === CashShiftType::GYM;

        return match ($transicion) {
            self::DUE_SOON => [
                'Tu saldo vence mañana',
                "Te quedan {$saldo} por pagar de «{$cuenta->concept}». Vence mañana.",
            ],
            self::DUE_TODAY => [
                'Tu saldo vence hoy',
                "Hoy es el último día para pagar los {$saldo} de «{$cuenta->concept}».",
            ],
            self::OVERDUE => [
                $esGimnasio ? 'Tu membresía quedó retenida' : 'Tienes un saldo vencido',
                $esGimnasio
                    ? "Quedan {$saldo} pendientes de «{$cuenta->concept}». Tu membresía se reactiva en cuanto pagues."
                    : "Quedan {$saldo} pendientes de «{$cuenta->concept}». Acércate a recepción para saldarlo.",
            ],
            default => [
                'Saldo al día',
                "Quedó saldado «{$cuenta->concept}». Gracias.",
            ],
        };
    }

    private function resumenAlerta(string $deudor, Money $saldo, int $dias, Receivable $cuenta): string
    {
        return sprintf(
            '%s debe %s de «%s». Venció el %s (hace %d día%s).%s',
            $deudor,
            $this->money($saldo),
            $cuenta->concept,
            optional($cuenta->due_at)->toDateString() ?? 'sin fecha',
            $dias,
            $dias === 1 ? '' : 's',
            $cuenta->type === CashShiftType::GYM ? ' Su membresía está retenida.' : '',
        );
    }

    /** Cuanto más tiempo lleva vencida, más alto sube en la bandeja. */
    private function severidad(int $dias): string
    {
        return match (true) {
            $dias >= 30 => CommercialAlert::SEVERITY_CRITICAL,
            $dias >= 8 => CommercialAlert::SEVERITY_HIGH,
            $dias >= 3 => CommercialAlert::SEVERITY_MEDIUM,
            default => CommercialAlert::SEVERITY_LOW,
        };
    }

    /** @return array<string,mixed> */
    private function carga(Receivable $cuenta): array
    {
        return [
            'receivable_id' => $cuenta->id,
            'type' => $cuenta->type->value,
            'concept' => $cuenta->concept,
            'balance' => $cuenta->balance()->toFloat(),
            'due_date' => optional($cuenta->due_at)->toDateString(),
        ];
    }

    private function money(Money $m): string
    {
        return '$'.number_format($m->toFloat(), 0, ',', '.');
    }
}
