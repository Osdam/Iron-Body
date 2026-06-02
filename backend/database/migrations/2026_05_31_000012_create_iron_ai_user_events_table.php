<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Línea de eventos relevantes para la memoria de IRON IA (por miembro).
 *
 * Guarda hitos importantes (evaluación creada, meta cambiada, racha completada,
 * lesión reportada…) con su importancia, para que el coach tenga continuidad
 * sin reenviar todo el historial. `idempotency_key` opcional evita duplicados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iron_ai_user_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->string('event_type');
            $table->jsonb('payload_json')->nullable();
            $table->unsignedTinyInteger('importance')->default(1);
            $table->timestamp('occurred_at')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['member_id', 'event_type']);
            $table->index(['member_id', 'occurred_at']);
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iron_ai_user_events');
    }
};
