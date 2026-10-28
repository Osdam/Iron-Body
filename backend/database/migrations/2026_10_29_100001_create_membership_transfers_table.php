<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TRANSFERIR MEMBRESÍA (docs/ops/TRANSFERENCIA-DE-MEMBRESIAS.md).
 *
 * Una fila por traspaso: la titular (A) cede lo que le queda del periodo de UN
 * cobro a un beneficiario (B). La vigencia viva sigue en `users`; esto es la
 * traza y lo único que permite deshacerlo: guarda los valores que tenían los
 * dos ANTES, porque después de escribir `users` ya no quedan en ninguna parte.
 *
 * El cobro de origen NO cambia de dueño ni se toca: sigue siendo de A, que es
 * quien pagó. Por eso esta tabla es lo que leen el rebuild, las guardas de
 * anular y devolver y la ficha del socio: sin ella, los pagos de A dirían que
 * A sigue cubierta hasta el fin y nadie sabría de dónde salió la vigencia de B.
 *
 * DOS LLAVES ÚNICAS, porque son lo único que sigue siendo cierto cuando dos
 * peticiones llegan a la vez:
 *   · `client_request_id`: el doble clic encuentra el traspaso del primero.
 *   · UN traspaso VIVO por cobro de origen (`reverted_at IS NULL`): dos
 *     cesiones del mismo cobro a dos personas distintas no pueden convivir.
 *     Revertir lo libera y se puede ceder otra vez. Índice parcial escrito a
 *     mano: el constructor de esquemas no los expresa, y `CREATE UNIQUE INDEX
 *     … WHERE` vale igual en PostgreSQL y en SQLite.
 *
 * Las referencias a socios, cobro y plan se anulan si la fila de origen se
 * borra (dar de baja a un socio borra sus pagos en cascada): la traza se
 * queda, con el nombre del plan congelado.
 *
 * Tabla nueva y aditiva. `down()` solo la borra a ella; borra también el
 * historial de traspasos, así que revertir la migración en producción exige
 * antes un respaldo de la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_transfers', function (Blueprint $table): void {
            $table->id();

            // A (titular, cede) y B (beneficiario, recibe).
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('beneficiary_user_id')->nullable()->constrained('users')->nullOnDelete();

            // El cobro de A cuyo periodo se cede. Sigue siendo de A.
            $table->foreignId('source_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            // El plan de ese cobro, por id y por nombre EXACTO (el que recibe B).
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('plan_name', 100);

            // D (hoy en Bogotá), E (el fin que tenía A) y E − D + 1.
            $table->date('effective_date');
            $table->date('end_date');
            $table->unsignedInteger('days');

            // Cómo estaban los dos ANTES. Es lo que restaura la reversión.
            $table->string('holder_plan_before', 100)->nullable();
            $table->date('holder_start_before')->nullable();
            $table->date('holder_end_before')->nullable();
            $table->string('holder_status_before', 20)->nullable();
            $table->string('beneficiary_plan_before', 100)->nullable();
            $table->date('beneficiary_start_before')->nullable();
            $table->date('beneficiary_end_before')->nullable();
            $table->string('beneficiary_status_before', 20)->nullable();

            // Por qué, con qué respaldo y con el consentimiento de los dos.
            $table->string('reason', 500);
            $table->string('evidence', 255)->nullable();
            $table->timestamp('holder_consent_at');
            $table->timestamp('beneficiary_acceptance_at');

            // Quién. Instantánea además del id: la traza sigue diciendo quién
            // fue aunque la cuenta se renombre o se elimine.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->string('client_request_id', 100)->unique();

            // Deshacer no borra: marca.
            $table->timestamp('reverted_at')->nullable();
            $table->unsignedBigInteger('reverted_by')->nullable();
            $table->string('reverted_by_name', 120)->nullable();
            $table->string('revert_reason', 500)->nullable();

            $table->timestamps();

            // La ficha de cada socio lista lo que cedió y lo que recibió.
            $table->index('holder_user_id');
            $table->index('beneficiary_user_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX membership_transfers_one_live_per_payment ON membership_transfers (source_payment_id) WHERE reverted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS membership_transfers_one_live_per_payment');
        Schema::dropIfExists('membership_transfers');
    }
};
