<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRASLADO DE SOCIOS ENTRE PLANES: un socio dentro de un lote.
 *
 * Una fila por socio trasladado, con su estado ANTES y DESPUÉS: el plan, las
 * fechas (que no cambian, y precisamente por eso se guardan: es la prueba),
 * el acceso, las funciones de la app y si tenía IRON IA. Es lo que permite
 * responder «¿qué le pasó a este socio el 15 de octubre?» sin reconstruirlo
 * de la auditoría.
 *
 * `before.plan` guarda el texto ORIGINAL de `users.plan`, con su variante de
 * escritura si la tenía: revertir devuelve exactamente lo que había.
 *
 * `user_id` se anula si el socio se borra (UserController::destroy borra de
 * verdad), y el nombre congelado sigue diciendo de quién era la fila.
 *
 * `reverts_item_id` es UNIQUE: un mismo socio de un mismo lote no puede
 * revertirse dos veces, ni siquiera con dos peticiones a la vez. La reversión
 * lo comprueba antes, bajo bloqueo; esto es lo que queda si algo se le escapa.
 *
 * Tabla nueva y aditiva: no toca ninguna existente. `down()` solo la borra a
 * ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('plan_transfer_batches')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 255)->nullable();

            $table->json('before');
            $table->json('after');
            // [{code, severity, message}] que se confirmaron al ejecutar.
            $table->json('warnings')->nullable();

            // applied | reverted
            $table->string('status', 20)->default('applied');
            $table->timestamp('reverted_at')->nullable();
            // En un lote de reversión: el ítem del traslado que deshace.
            $table->foreignId('reverts_item_id')->nullable()->unique()
                ->constrained('plan_transfer_items')->restrictOnDelete();

            $table->timestamps();

            // Un socio aparece una sola vez por lote.
            $table->unique(['batch_id', 'user_id']);
            // «¿En qué traslados ha estado este socio?»
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_transfer_items');
    }
};
