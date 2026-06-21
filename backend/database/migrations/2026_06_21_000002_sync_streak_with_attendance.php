<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza la racha semanal con la asistencia real al gimnasio.
 *
 * Antes, abrir la app marcaba un "día activo" (source = 'app_open'), por lo que
 * un usuario recién creado ya aparecía con racha sin haber ido al gimnasio. La
 * nueva regla: la racha SOLO la marca la asistencia de entrada (punto físico).
 *
 * Esta migración corrige los datos existentes:
 *  1) Borra los días marcados por 'app_open' (rachas falsas).
 *  2) Rellena (backfill) los días activos a partir de las asistencias de ENTRADA
 *     ya registradas, calculando la fecha en timezone America/Bogota (igual que
 *     WeeklyStreakService), para que la racha histórica refleje la realidad.
 */
return new class extends Migration
{
    private const TZ = 'America/Bogota';

    public function up(): void
    {
        // 1) Elimina las rachas falsas creadas solo por abrir la app.
        DB::table('member_app_activity_days')->where('source', 'app_open')->delete();

        // 2) Backfill desde asistencias de entrada (fuente de verdad real).
        $now = now();

        DB::table('attendances')
            ->leftJoin('members', 'members.user_id', '=', 'attendances.user_id')
            ->where('attendances.action', 'entry')
            ->whereNotNull('attendances.captured_at')
            ->orderBy('attendances.id')
            ->select([
                'attendances.captured_at as captured_at',
                DB::raw('COALESCE(attendances.member_id, members.id) as member_id'),
            ])
            ->chunk(1000, function ($rows) use ($now): void {
                $batch = [];

                foreach ($rows as $row) {
                    if (! $row->member_id) {
                        continue; // asistencia sin miembro vinculado: se omite.
                    }

                    // captured_at se guarda en UTC → convertir a hora del gimnasio.
                    $date = CarbonImmutable::parse($row->captured_at, 'UTC')
                        ->setTimezone(self::TZ)
                        ->toDateString();

                    // Deduplica dentro del lote (1 día activo por miembro/fecha).
                    $batch[$row->member_id . '|' . $date] = [
                        'member_id'     => (int) $row->member_id,
                        'activity_date' => $date,
                        'source'        => 'attendance',
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ];
                }

                if ($batch !== []) {
                    DB::table('member_app_activity_days')->upsert(
                        array_values($batch),
                        ['member_id', 'activity_date'],
                        ['updated_at']
                    );
                }
            });
    }

    public function down(): void
    {
        // No se puede restaurar la data borrada (eran rachas falsas). No-op.
    }
};
