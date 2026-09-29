<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La asistencia que NO debió haber pasado, marcada como tal.
 *
 * El terminal de recepción decide en la puerta y sin internet, así que puede
 * dejar entrar a alguien con lo que tenía guardado y estar equivocado: entradas
 * que ya se habían gastado en otra estación, una franja horaria que cambió esta
 * mañana. Cuando el registro sube, el backend —que sí tiene la cuenta completa—
 * lo compara con las reglas del plan.
 *
 * Y lo GUARDA IGUAL. Rechazarlo sería mentir: la persona entró, está adentro y
 * el terminal ya le abrió la puerta; además el envío descarta los 4xx como
 * registros envenenados, así que un rechazo borraría la única prueba de esa
 * entrada. Se registra y se marca, para que el gimnasio la vea en Asistencias y
 * decida si le cobra, la perdona o revisa el lector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendances', 'over_limit')) {
                $table->boolean('over_limit')->default(false)->after('note');
            }
            if (! Schema::hasColumn('attendances', 'limit_reason')) {
                $table->string('limit_reason', 40)->nullable()->after('over_limit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            foreach (['limit_reason', 'over_limit'] as $columna) {
                if (Schema::hasColumn('attendances', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
