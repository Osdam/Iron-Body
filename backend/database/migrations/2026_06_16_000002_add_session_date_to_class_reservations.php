<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Organizar mi semana": la reserva pasa a ser POR OCURRENCIA (fecha de la
 * sesión), no solo por plantilla. ADITIVO y NO destructivo:
 *
 *  - `session_date` NULL  = reserva legacy del ciclo vigente (compatibilidad
 *    total con el flujo actual; se trata como "aplica a la sesión de hoy").
 *  - El unique pasa de (class_id, member_id) a (class_id, member_id, session_date):
 *    permite reservar la MISMA clase en semanas distintas, pero impide duplicar
 *    la misma ocurrencia (anti-doble reserva por fecha).
 *
 * Backfill: las reservas existentes heredan la fecha de su PRÓXIMA ocurrencia,
 * para que la renovación date-aware no las trate como vencidas por error. No se
 * borra ni se pierde ninguna reserva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('class_reservations', 'session_date')) {
            Schema::table('class_reservations', function (Blueprint $table): void {
                $table->date('session_date')->nullable()->after('member_id');
                $table->index(['class_id', 'session_date']);
            });
        }

        // Backfill: una fecha por clase (próxima ocurrencia) aplicada a sus reservas
        // legacy sin fecha. En tests la tabla está vacía → no-op.
        foreach (\App\Models\MyClass::query()->get() as $class) {
            $date = optional($class->nextOccurrence())->toDateString();
            if ($date === null) {
                continue;
            }
            DB::table('class_reservations')
                ->where('class_id', $class->getKey())
                ->whereNull('session_date')
                ->update(['session_date' => $date]);
        }

        // Reemplaza el unique por plantilla por el unique por ocurrencia.
        Schema::table('class_reservations', function (Blueprint $table): void {
            $table->dropUnique(['class_id', 'member_id']);
        });
        Schema::table('class_reservations', function (Blueprint $table): void {
            $table->unique(['class_id', 'member_id', 'session_date'], 'class_reservations_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('class_reservations', function (Blueprint $table): void {
            $table->dropUnique('class_reservations_occurrence_unique');
            $table->dropIndex(['class_id', 'session_date']);
            $table->unique(['class_id', 'member_id']);
            $table->dropColumn('session_date');
        });
    }
};
