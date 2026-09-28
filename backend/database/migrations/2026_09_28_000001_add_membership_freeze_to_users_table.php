<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONGELAR UNA MEMBRESÍA: el reloj se para y los días se guardan.
 *
 * Quien se va de viaje tres semanas no debería gastarlas. Congelar es eso:
 * apartar los días que le quedan y devolvérselos al volver.
 *
 * POR QUÉ ESTAS COLUMNAS Y NO UN ESTADO NUEVO
 * -------------------------------------------
 * Hay dos clientes que NO se pueden tocar: la app del socio y el terminal de
 * recepción (LocalGym). Los dos deciden si alguien entra con lo que ya leen:
 *
 *   LocalGym  → `status` en ('inactive','expired') bloquea; si no, mira que
 *               `membership_end_date` no haya pasado.
 *   La app    → solo mira el plan y `membership_end_date`.
 *
 * Inventar un estado «frozen» habría sido invisible para los dos: seguirían
 * dejando entrar. Así que el congelamiento se EXPRESA con lo que ya entienden
 * —cuenta inactiva y vigencia terminada— y la verdad de cuántos días se
 * apartaron vive en estas columnas, que solo el CRM necesita leer.
 *
 * Nada se pierde al congelar: `membership_frozen_days_left` guarda los días que
 * quedaban, y al reanudar se suman a partir de ese momento. La fecha de
 * vencimiento anterior se puede reconstruir siempre: `frozen_at + días`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Cuándo empezó y cuándo se reanuda sola.
            $table->date('membership_frozen_at')->nullable()->after('membership_end_date');
            $table->date('membership_frozen_until')->nullable()->after('membership_frozen_at');

            // Los días que le quedaban de membresía en el momento de congelar.
            // Es LO QUE SE DEVUELVE al reanudar, y por eso no se recalcula: la
            // fecha de vencimiento se pone en el pasado para que los otros
            // sistemas denieguen el paso, así que ya no se puede deducir.
            $table->unsignedSmallInteger('membership_frozen_days_left')->nullable()
                ->after('membership_frozen_until');

            // El estado de la cuenta ANTES de congelar, para devolverlo tal cual.
            // Suponer «activa» al reanudar reactivaría a quien estaba inactivo
            // por otro motivo.
            $table->string('membership_status_before_freeze', 20)->nullable()
                ->after('membership_frozen_days_left');

            $table->string('membership_frozen_reason', 255)->nullable()
                ->after('membership_status_before_freeze');

            // Quién lo hizo, congelado como instantánea: el histórico debe
            // seguir diciéndolo aunque la cuenta se elimine después.
            $table->foreignId('membership_frozen_by')->nullable()
                ->after('membership_frozen_reason')
                ->constrained('admins')->nullOnDelete();
            $table->string('membership_frozen_by_name', 120)->nullable()
                ->after('membership_frozen_by');

            // La consulta del trabajo diario: «¿a quién le toca reanudarse hoy?».
            $table->index('membership_frozen_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['membership_frozen_until']);
            $table->dropConstrainedForeignId('membership_frozen_by');
            $table->dropColumn([
                'membership_frozen_at',
                'membership_frozen_until',
                'membership_frozen_days_left',
                'membership_status_before_freeze',
                'membership_frozen_reason',
                'membership_frozen_by_name',
            ]);
        });
    }
};
