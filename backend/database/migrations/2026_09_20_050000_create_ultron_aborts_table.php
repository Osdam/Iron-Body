<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El freno de emergencia de ULTRON, que no vive en el entorno.
 *
 * Los dos interruptores que había —`MARKETING_ULTRON_ENABLED` y el id del
 * canario— son variables de entorno: apagarlos exige entrar al servidor, editar
 * un fichero, recachear y reiniciar la cola. Eso lo puede hacer una persona en
 * dos minutos; no lo puede hacer un detector a las tres de la mañana cuando
 * encuentra que un prospecto preguntó y nadie contestó.
 *
 * Esta tabla es lo que sí puede accionar el propio sistema. Una fila abierta
 * significa PARADO, y la regla que la hace segura es que sólo empuja en un
 * sentido: el freno APAGA, nunca enciende. Con `MARKETING_ULTRON_ENABLED=false`
 * releasear esta fila no arranca nada, y con el flag encendido una fila abierta
 * lo detiene igual. Dos barreras independientes, como el canario y el flag.
 *
 * Soltarlo es SIEMPRE un acto humano: la columna `released_by` no admite
 * «automático» por construcción, porque quien decide que un fallo ya está
 * entendido es una persona, no el detector que lo encontró.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ultron_aborts', function (Blueprint $table): void {
            $table->id();
            // Código corto: 'orphaned_turn', 'manual'… Nunca prosa del cliente.
            $table->string('reason', 64);
            $table->string('engaged_by', 80);
            $table->timestamp('engaged_at');
            $table->jsonb('evidence')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('released_by', 80)->nullable();
            $table->text('release_note')->nullable();
            $table->timestamps();

            // La consulta caliente: ¿hay alguno sin soltar?
            $table->index(['released_at', 'id'], 'ultron_aborts_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ultron_aborts');
    }
};
