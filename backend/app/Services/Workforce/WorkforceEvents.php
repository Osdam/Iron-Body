<?php

namespace App\Services\Workforce;

use App\Models\ClassEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hechos de personal, jornadas, biometría y dispositivos hacia el CRM.
 *
 * Reutiliza el outbox del canal SSE del CRM (`class_events`) en lugar de abrir
 * otro stream: se escribe DENTRO de la transacción del cambio (o quedan los dos
 * o ninguno) y el canal lo entrega por cursor solo a quien tiene el permiso de
 * `audience`. La carga no lleva datos de nadie: un uuid y un motivo; el CRM
 * vuelve a pedir lo que su permiso le deja ver.
 */
class WorkforceEvents
{
    public const WORKDAY_UPDATED = 'workday.updated';

    public const STAFF_UPDATED = 'staff.updated';

    public const BIOMETRIC_UPDATED = 'biometric.updated';

    public const DEVICE_UPDATED = 'device.updated';

    /** Permiso necesario para recibir cada tipo. */
    public const AUDIENCE = [
        self::WORKDAY_UPDATED => 'workforce.view',
        self::STAFF_UPDATED => 'workforce.view',
        self::BIOMETRIC_UPDATED => 'biometrics.view',
        self::DEVICE_UPDATED => 'devices.view',
    ];

    public const SOURCE_KIOSK = 'kiosk';

    public const SOURCE_CRM = 'crm';

    public const SOURCE_TRAINER = 'trainer';

    public const SOURCE_SYSTEM = 'system';

    public function publish(string $type, string $reason, string $subject, string $source, ?string $date = null): void
    {
        try {
            // SAVEPOINT, igual que ClassEventBus: en PostgreSQL un error dentro
            // de la transacción la invalida entera; si la fila del aviso no se
            // pudiera escribir, se deshace solo ella y la operación sigue (el
            // CRM tiene además el resync al reconectar).
            DB::transaction(fn () => ClassEvent::create([
                'type' => $type,
                'class_id' => null,
                'session_date' => $date,
                'reservation_id' => null,
                'source' => $source,
                'reason' => $reason,
                'subject' => $subject,
                'audience' => self::AUDIENCE[$type] ?? null,
                'created_at' => now(),
            ]));
        } catch (\Throwable $e) {
            Log::warning('workforce_event.record_failed', ['type' => $type, 'reason' => $reason, 'error' => $e->getMessage()]);
        }
    }
}
