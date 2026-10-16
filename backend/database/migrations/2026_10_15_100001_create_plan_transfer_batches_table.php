<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRASLADO DE SOCIOS ENTRE PLANES: el lote.
 *
 * EL PROBLEMA. Un plan heredado del sistema anterior («TOTAL ACCESS - ELITE»)
 * y el plan que el gimnasio vende hoy («Élite») son el mismo producto con dos
 * nombres. Pasar a sus socios de uno a otro se hacía ficha por ficha, y la
 * única herramienta que cambiaba el plan era la mejora, que COBRA y recalcula
 * la vigencia: justo lo que aquí no puede pasar.
 *
 * LO QUE ESTO GUARDA. Cada traslado confirmado es una fila: quién lo hizo, por
 * qué, desde qué plan y hacia cuál, con las condiciones de los dos planes tal
 * como estaban ese día —porque el catálogo cambia y dentro de un mes nadie
 * recordará qué incluía cada uno—, y qué socios se quedaron fuera y por qué.
 *
 * LA REVERSIÓN ES OTRO LOTE (`kind = reversal`), no un borrado: deshacer
 * también es una decisión con autor y motivo, y el traslado original sigue
 * existiendo para explicar por qué hubo que deshacerlo.
 *
 * `client_request_id` es la llave del doble clic: la misma operación que llega
 * dos veces encuentra el lote de la primera en vez de crear otro. Es UNIQUE en
 * la base porque es lo único que sigue siendo cierto cuando dos peticiones
 * llegan a la vez.
 *
 * Tabla nueva y aditiva: no toca ninguna existente. `down()` solo la borra a
 * ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_transfer_batches', function (Blueprint $table): void {
            $table->id();

            // transfer | reversal
            $table->string('kind', 20)->default('transfer');
            // La reversión apunta al traslado que deshace. Nada se borra, así
            // que la referencia no puede quedarse colgando.
            $table->foreignId('reverts_batch_id')->nullable()
                ->constrained('plan_transfer_batches')->restrictOnDelete();
            // applied | partially_reverted | reverted
            $table->string('status', 30)->default('applied');

            // Los planes por id (si alguien borra uno, el lote no se pierde) y
            // por nombre congelado, que es lo que dice la historia.
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('from_plan_name', 100);
            $table->string('to_plan_name', 100);
            // Condiciones de cada plan el día del traslado: precio, acceso,
            // funciones de la app, IRON IA. Instantánea, no referencia.
            $table->json('from_conditions');
            $table->json('to_conditions');

            $table->string('reason', 500);
            // Huella de la simulación que se confirmó. Permite comprobar que lo
            // aplicado es exactamente lo que se vio.
            $table->char('fingerprint', 64)->index();
            $table->string('client_request_id', 100)->unique();

            $table->unsignedInteger('members_count')->default(0);
            // [{user_id, code}] de quienes se pidieron y no se trasladaron.
            $table->json('excluded')->nullable();

            // El día del gimnasio (Bogotá), no el día UTC del servidor.
            $table->date('business_day');
            $table->timestamp('executed_at');

            // Quién. Instantánea además del id: la traza tiene que seguir
            // diciendo quién fue aunque la cuenta se renombre o se elimine.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->string('created_by_role', 60)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_transfer_batches');
    }
};
