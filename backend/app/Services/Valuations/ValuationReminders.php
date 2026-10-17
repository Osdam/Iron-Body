<?php

namespace App\Services\Valuations;

use App\Models\ValuationAppointment;
use App\Models\ValuationReminder;
use App\Models\ValuationSetting;
use Illuminate\Support\Carbon;

/**
 * Planifica y anula las filas del outbox `valuation_reminders` (contrato §5).
 *
 * Se llama SIEMPRE dentro de la transacción de la operación que cambia la cita:
 * o cambia la cita y sus avisos, o no cambia nada.
 *
 * Cada fila pertenece a una VERSIÓN de la cita. El procesador descarta la que no
 * coincide con la versión actual, así que un aviso viejo no puede salir con la
 * hora vieja aunque alguien olvidara anularlo.
 */
final class ValuationReminders
{
    /**
     * Las filas de la versión actual tras crear (`confirmation`) o reprogramar
     * (`rescheduled`).
     *
     * @param  string|null  $inmediato  KIND_CONFIRMATION | KIND_RESCHEDULED | null (ninguno)
     */
    public function plan(ValuationAppointment $cita, ValuationSetting $ajustes, ?string $inmediato): void
    {
        $ahora = Carbon::now()->utc();
        $inicio = $cita->starts_at->copy()->utc();
        $filas = [];

        // Una cita que ya empezó (agendada en el pasado con permiso, o importada)
        // no avisa a nadie: «quedó programada para» un día que ya pasó sería
        // falso, y «sin iniciar» sería ruido sobre algo que se registra a posteriori.
        if (! $inicio->gt($ahora)) {
            return;
        }

        if ($inmediato === ValuationReminder::KIND_CONFIRMATION && $ajustes->confirmation_enabled) {
            $filas[] = [ValuationReminder::KIND_CONFIRMATION, ValuationReminder::CHANNEL_MEMBER_APP, $ahora];
        }
        if ($inmediato === ValuationReminder::KIND_RESCHEDULED) {
            $filas[] = [ValuationReminder::KIND_RESCHEDULED, ValuationReminder::CHANNEL_MEMBER_APP, $ahora];
        }

        if ($ajustes->reminder_long_enabled) {
            $cuando = $inicio->copy()->subHours((int) $ajustes->reminder_long_hours);
            if ($cuando->gt($ahora)) {
                $filas[] = [ValuationReminder::KIND_REMINDER_LONG, ValuationReminder::CHANNEL_MEMBER_APP, $cuando];
            }
        }
        if ($ajustes->reminder_short_enabled) {
            $cuando = $inicio->copy()->subMinutes((int) $ajustes->reminder_short_minutes);
            if ($cuando->gt($ahora)) {
                $filas[] = [ValuationReminder::KIND_REMINDER_SHORT, ValuationReminder::CHANNEL_MEMBER_APP, $cuando];
            }
        }
        if ($ajustes->internal_alert_enabled) {
            $filas[] = [ValuationReminder::KIND_INTERNAL_UPCOMING, ValuationReminder::CHANNEL_CRM, $inicio->copy()->subMinutes((int) $ajustes->internal_alert_minutes)];
        }
        if ($ajustes->internal_unattended_enabled) {
            $filas[] = [ValuationReminder::KIND_INTERNAL_UNATTENDED, ValuationReminder::CHANNEL_CRM, $inicio->copy()->addMinutes(15)];
        }

        foreach ($filas as [$kind, $canal, $cuando]) {
            $this->add($cita, $kind, $canal, $cuando);
        }
    }

    /** El aviso de cancelación al socio, de la versión actual (ya cancelada). */
    public function planCancellation(ValuationAppointment $cita): void
    {
        $this->add($cita, ValuationReminder::KIND_CANCELLATION, ValuationReminder::CHANNEL_MEMBER_APP, Carbon::now()->utc());
    }

    /**
     * Anula lo que aún podía enviarse (pendientes y reintentos), de cualquier
     * versión: reprogramar, cancelar, completar o «no asistió».
     */
    public function cancelWaiting(ValuationAppointment $cita): int
    {
        return ValuationReminder::query()
            ->where('appointment_id', $cita->id)
            ->whereIn('status', ValuationReminder::WAITING_STATUSES)
            ->update(['status' => ValuationReminder::STATUS_CANCELLED, 'updated_at' => Carbon::now()->utc()]);
    }

    /**
     * La versión subió por un cambio que NO mueve la hora (confirmar, iniciar):
     * los avisos de la versión anterior siguen siendo verdad, así que pasan a la
     * nueva. Sin esto, confirmar una cita silenciaría sus recordatorios.
     *
     * Incluye los `processing`: el procesador relee la fila con bloqueo antes de
     * decidir, así que o ve la versión nueva o ya la había resuelto.
     */
    public function carryForward(ValuationAppointment $cita, int $versionAnterior): int
    {
        return ValuationReminder::query()
            ->where('appointment_id', $cita->id)
            ->where('appointment_version', $versionAnterior)
            ->whereIn('status', [...ValuationReminder::WAITING_STATUSES, ValuationReminder::STATUS_PROCESSING])
            ->update(['appointment_version' => (int) $cita->version, 'updated_at' => Carbon::now()->utc()]);
    }

    private function add(ValuationAppointment $cita, string $kind, string $canal, Carbon $cuando): void
    {
        ValuationReminder::query()->firstOrCreate(
            [
                'appointment_id' => $cita->id,
                'kind' => $kind,
                'channel' => $canal,
                'appointment_version' => (int) $cita->version,
            ],
            [
                'due_at' => $cuando->copy()->utc(),
                'status' => ValuationReminder::STATUS_PENDING,
                'attempts' => 0,
            ],
        );
    }
}
