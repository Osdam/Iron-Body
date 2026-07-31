<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Franja y motivo de selección en el libro mayor.
 *
 * La llave de idempotencia ya lleva la franja dentro, pero una llave es una
 * cadena: no se puede agrupar por ella ni preguntarle a la base de datos
 * «cuántas salieron ayer a media tarde». Con la columna aparte, el CRM y las
 * métricas responden esa pregunta sin trocear texto.
 *
 * `selection_reason` guarda POR QUÉ se eligió ese contenido —«lleva 9 días sin
 * venir», «entrenó ayer»—. Sin eso, revisar un envío raro obliga a reconstruir
 * a mano el estado que tenía el socio aquel día, que es justo lo que nunca se
 * puede reconstruir.
 *
 * Aditiva y anulable: las filas históricas se quedan con null y siguen valiendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_dispatches')) {
            return;
        }

        Schema::table('notification_dispatches', function (Blueprint $table): void {
            if (! Schema::hasColumn('notification_dispatches', 'slot')) {
                $table->string('slot', 20)->nullable()->after('category');
            }
            if (! Schema::hasColumn('notification_dispatches', 'selection_reason')) {
                $table->string('selection_reason', 80)->nullable()->after('reason');
            }
        });

        Schema::table('notification_dispatches', function (Blueprint $table): void {
            $table->index(['member_id', 'slot', 'created_at'], 'nd_member_slot_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_dispatches')) {
            return;
        }

        Schema::table('notification_dispatches', function (Blueprint $table): void {
            $table->dropIndex('nd_member_slot_idx');
        });

        Schema::table('notification_dispatches', function (Blueprint $table): void {
            $table->dropColumn(['slot', 'selection_reason']);
        });
    }
};
