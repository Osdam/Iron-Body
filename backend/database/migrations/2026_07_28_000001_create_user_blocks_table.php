<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueos entre miembros (UGC / Stories).
 *
 * Semántica: el bloqueo es DIRECCIONAL en su registro (quién bloqueó a quién)
 * pero SIMÉTRICO en su efecto (ninguno de los dos ve el contenido del otro).
 * El filtrado se aplica SIEMPRE en backend (nunca solo en la UI), consultando
 * ambos sentidos: `blocker = yo OR blocked = yo`.
 *
 * Aislamiento de dominio: bloquear NO toca membresías, pagos, facturación ni
 * acceso físico al gimnasio. Es exclusivamente una relación social.
 *
 * Índices: uno por cada sentido de la consulta del feed (excluir autores que
 * yo bloqueé + autores que me bloquearon) para que el filtro sea un index scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_blocks')) {
            return;
        }

        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('blocker_member_id');
            $table->unsignedBigInteger('blocked_member_id');

            // Motivo opcional que el usuario puede dar al bloquear. Texto
            // saneado y acotado en el FormRequest — nunca autoridad de negocio.
            $table->string('reason', 200)->nullable();

            $table->timestamps();

            // Idempotencia a nivel DB: bloquear dos veces no duplica la fila.
            $table->unique(
                ['blocker_member_id', 'blocked_member_id'],
                'user_blocks_unique_pair'
            );

            $table->index('blocker_member_id', 'user_blocks_blocker_idx');
            $table->index('blocked_member_id', 'user_blocks_blocked_idx');
        });

        // FKs en migración aparte y tolerante: en entornos donde `members` use
        // un motor/estado incompatible preferimos conservar la tabla antes que
        // romper el despliegue. La integridad se garantiza además en servicio.
        try {
            Schema::table('user_blocks', function (Blueprint $table) {
                $table->foreign('blocker_member_id', 'user_blocks_blocker_fk')
                    ->references('id')->on('members')->cascadeOnDelete();
                $table->foreign('blocked_member_id', 'user_blocks_blocked_fk')
                    ->references('id')->on('members')->cascadeOnDelete();
            });
        } catch (Throwable $e) {
            // Silencioso a propósito: la unicidad y los índices ya están puestos.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_blocks');
    }
};
