<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El canal GLOBAL de la app del socio también avisa de las clases.
 *
 * Cada cambio de cupo escribía una fila POR SOCIO ACTIVO en
 * `member_realtime_events` (unas 3.900 por reserva, y otras tantas por cada
 * marca de asistencia). Este canal ya existe, lo leen todas las versiones de la
 * app que están en uso y enruta por `type`, así que el mismo aviso cabe en UNA
 * fila. Estas columnas dicen de qué clase y de qué ocurrencia se trata, para
 * que un cliente pueda refrescar solo esa; las versiones actuales las ignoran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('class_id')->nullable()->after('product_id');
            $table->date('session_date')->nullable()->after('class_id');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_events', function (Blueprint $table): void {
            $table->dropColumn(['class_id', 'session_date']);
        });
    }
};
