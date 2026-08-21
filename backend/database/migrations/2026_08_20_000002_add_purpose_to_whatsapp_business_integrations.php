<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Para qué sirve cada conexión guardada.
 *
 * Hasta ahora todas las filas eran lo mismo y `current()` devolvía la última
 * conectada, fuese cual fuese. En el momento en que existe una conexión de
 * DEMOSTRACIÓN —una WABA de prueba para la revisión de Meta— esa regla se vuelve
 * peligrosa: la fila de prueba sería la más reciente, pasaría a ser la que
 * alimenta las credenciales del canal, y a partir de ahí el sistema intentaría
 * enviar desde el número de prueba y descartaría los eventos del número real por
 * no coincidir. Una demostración no puede tener ese poder.
 *
 * Con `purpose` la separación es estructural y no depende de que nadie se
 * acuerde: `current()` solo mira las de producción, y las de revisión se
 * guardan, se enseñan y se borran sin tocar el canal.
 *
 * Las filas existentes son de producción por definición: la columna nace con
 * ese valor por defecto, así que instalar esto no cambia el comportamiento de
 * ninguna conexión ya hecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_business_integrations', function (Blueprint $table): void {
            // production | review
            $table->string('purpose')->default('production')->after('meta_app_id');
            $table->index(['purpose', 'status'], 'wa_integrations_purpose_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_business_integrations', function (Blueprint $table): void {
            $table->dropIndex('wa_integrations_purpose_status_index');
            $table->dropColumn('purpose');
        });
    }
};
