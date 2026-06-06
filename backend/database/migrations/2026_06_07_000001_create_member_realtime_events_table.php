<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bus de eventos real-time por miembro. El backend inserta una fila cuando un
 * dato crítico cambia (membresía/pago/perfil/staff/story/seguridad) y el stream
 * SSE privado del miembro (GET /member/realtime) la emite al instante. Se poda
 * sola (eventos efímeros): NO es histórico, solo señal de cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_realtime_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->string('type', 60);            // p.ej. membership.updated
            $table->json('changed')->nullable();   // ['membership']
            $table->unsignedBigInteger('version')->default(0); // monotónico (ms)
            $table->timestamp('created_at')->nullable();

            $table->index(['member_id', 'id']);    // cursor por miembro
            $table->index('created_at');           // poda
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_realtime_events');
    }
};
