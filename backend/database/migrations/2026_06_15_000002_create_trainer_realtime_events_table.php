<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bus de eventos real-time por ENTRENADOR. El backend inserta una fila cuando
 * cambia algo de sus clientes (asignación, valoración, asistencia, clase) y el
 * stream SSE del portal (GET /trainer/realtime) la emite al instante. Se poda
 * sola (efímero): NO es histórico, solo señal de cambio. Espejo de
 * `member_realtime_events`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trainer_realtime_events')) {
            return;
        }

        Schema::create('trainer_realtime_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('trainer_id');
            $table->string('type', 60);            // p.ej. members.updated
            $table->json('changed')->nullable();   // ['members']
            $table->unsignedBigInteger('version')->default(0); // monotónico (ms)
            $table->timestamp('created_at')->nullable();

            $table->index(['trainer_id', 'id']);   // cursor por entrenador
            $table->index('created_at');           // poda
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_realtime_events');
    }
};
