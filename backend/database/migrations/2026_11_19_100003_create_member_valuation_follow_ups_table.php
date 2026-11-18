<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEGUIMIENTO DE VALORACIONES POR SOCIO (CONTRATO_CICLO_VALORACIONES.md §3).
 *
 * Lo que el CRM decide para UN socio y que no se deduce de nada más:
 *
 *   interval_days  cada cuántos días le toca (NULL = hereda el del gimnasio,
 *                  `valuation_settings.follow_up_interval_days`);
 *   enabled        si se le hace seguimiento (false = pausado);
 *   due_on         una fecha puntual que sustituye al cálculo.
 *
 * NO GUARDA el «próximo control sugerido» ni el estado: se calculan al leer
 * (ValuationFollowUps), así que nunca hay que reescribir histórico cuando
 * cambia una regla.
 *
 * Tabla propia y no columnas en `members`: la fila del socio es el cerrojo de
 * la agenda (`FOR NO KEY UPDATE`); escribir aquí no la toca.
 *
 *  · `member_id` ÚNICO y CASCADE: una fila por socio, que se va con él (como
 *    sus citas y sus evaluaciones físicas).
 *  · `version`: concurrencia optimista del PUT (409 `follow_up_changed`). Nace
 *    en 0: es la versión que el GET da a un socio sin fila.
 *  · `updated_by_admin_id`: como `valuation_settings`, SET NULL si la cuenta
 *    desaparece; el quién de verdad queda en `audit_logs`.
 *
 * En PostgreSQL, CHECKs con los límites de la API. Aditiva: el código viejo no
 * la conoce. Reversible: `down()` la borra (lo configurado se pierde con ella;
 * la auditoría se queda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_valuation_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            $table->smallInteger('interval_days')->nullable();
            $table->boolean('enabled')->default(true);
            $table->date('due_on')->nullable();
            $table->integer('version')->default(0);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE member_valuation_follow_ups ADD CONSTRAINT member_valuation_follow_ups_interval_chk'
                .' CHECK (interval_days IS NULL OR interval_days BETWEEN 1 AND 365)');
            DB::statement('ALTER TABLE member_valuation_follow_ups ADD CONSTRAINT member_valuation_follow_ups_version_chk'
                .' CHECK (version >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_valuation_follow_ups');
    }
};
