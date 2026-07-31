<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cupo diario propio para el bienestar.
 *
 * Hasta ahora el único techo diario era `max_per_day`, que cuenta TODO: pagos,
 * clases, seguridad y bienestar en el mismo saco. Con cinco franjas eso rompe la
 * promesa por el lado equivocado — a un socio con tres avisos de pago se le
 * caerían las notificaciones de acompañamiento, que es justo lo contrario de lo
 * que se pretende.
 *
 * Se separan: `max_per_day` sigue siendo el techo global de cortesía y
 * `max_wellness_per_day` gobierna solo lo discrecional.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_notification_preferences')) {
            return;
        }

        Schema::table('member_notification_preferences', function (Blueprint $table): void {
            if (! Schema::hasColumn('member_notification_preferences', 'max_wellness_per_day')) {
                $table->unsignedSmallInteger('max_wellness_per_day')->nullable()->after('max_per_day');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('member_notification_preferences', 'max_wellness_per_day')) {
            Schema::table('member_notification_preferences', function (Blueprint $table): void {
                $table->dropColumn('max_wellness_per_day');
            });
        }
    }
};
