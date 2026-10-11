<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de hechos del CRM deja de ser solo de clases.
 *
 * `class_events` ya es el outbox tipado que alimenta el canal SSE del CRM: se
 * escribe en la misma transacción que el cambio y se lee por cursor con
 * solapamiento. En vez de abrir un segundo stream —otro worker de PHP-FPM por
 * pestaña abierta—, los hechos de personal, jornadas, biometría y dispositivos
 * viajan por el mismo canal:
 *
 *  · `subject` ... uuid de lo que cambió (jornada, identidad, dispositivo).
 *  · `audience` .. permiso que hace falta para RECIBIR el hecho. NULL = los
 *                  hechos de clases de siempre (`classes.view`).
 *
 * Aditiva; las filas existentes no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_events', function (Blueprint $table): void {
            $table->string('subject', 64)->nullable();
            $table->string('audience', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('class_events', function (Blueprint $table): void {
            $table->dropColumn(['subject', 'audience']);
        });
    }
};
