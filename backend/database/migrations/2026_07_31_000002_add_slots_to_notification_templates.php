<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En qué franjas del día tiene sentido cada plantilla.
 *
 * «Come algo antes de entrenar» sirve a las siete de la mañana y es absurdo a
 * las diez de la noche. Sin esta columna, cinco envíos diarios significan cinco
 * oportunidades de decir algo fuera de lugar.
 *
 * `null` significa «vale en cualquier franja», que es el comportamiento que ya
 * tenían las plantillas sembradas: la columna nace sin restringir nada y es el
 * catálogo el que va marcando las que sí dependen de la hora.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_templates')) {
            return;
        }

        Schema::table('notification_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('notification_templates', 'slots')) {
                $table->json('slots')->nullable()->after('requires_active_membership');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('notification_templates', 'slots')) {
            Schema::table('notification_templates', function (Blueprint $table): void {
                $table->dropColumn('slots');
            });
        }
    }
};
