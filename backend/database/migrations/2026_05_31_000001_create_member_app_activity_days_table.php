<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Días en que un miembro abrió la app con sesión válida.
 *
 * Fuente de verdad de la racha semanal ("Esta semana"). Una fila por
 * (member_id, activity_date): el índice único garantiza 1 día activo por
 * fecha calendario, aunque la app llame `/touch` muchas veces el mismo día.
 *
 * `activity_date` se calcula SIEMPRE en el servidor con timezone
 * America/Bogota (la fecha del cliente nunca es fuente de verdad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_app_activity_days', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->date('activity_date');
            $table->string('source')->nullable()->default('app_open');
            $table->timestamps();

            $table->unique(['member_id', 'activity_date']);
            $table->index(['member_id', 'activity_date']);

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_app_activity_days');
    }
};
