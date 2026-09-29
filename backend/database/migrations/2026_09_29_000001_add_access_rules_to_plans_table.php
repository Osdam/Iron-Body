<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REGLAS DE ACCESO DEL PLAN: por qué un plan puede valer 30 días y 15 entradas.
 *
 * El plan Valera se vendía como «15 días» porque `duration_days` era lo único
 * que había, y no es eso: son 15 ENTRADAS dentro de un mes. Con la columna vieja
 * el socio perdía la mitad del mes, o —si se le ponían 30 días— entraba 30 veces
 * pagando 15.
 *
 * Son dos preguntas distintas y ahora se guardan por separado:
 *   `duration_days`  → hasta cuándo vale (la vigencia; no cambia de significado)
 *   `entry_credits`  → cuántas veces puede entrar dentro de esa vigencia
 *
 * Y dos restricciones más que el gimnasio ya vendía de palabra:
 *   `access_days`    → qué días de la semana (ISO: 1 lunes … 7 domingo)
 *   `access_windows` → en qué franjas horarias («horas valle»)
 *
 * Todo es ADITIVO y con valores por defecto que describen lo que hay hoy: un
 * plan existente queda 'unlimited', sin tope de entradas y sin restricción de
 * día ni de hora, así que nada cambia hasta que alguien lo configure. La app
 * móvil no lee ninguna de estas columnas —usa `months`/`period`, que el backend
 * calcula—, por eso se puede añadir sin desplegarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'access_mode')) {
                // 'unlimited' = entra cuantas veces quiera mientras esté vigente.
                // 'entries'   = por consumo: cada día que entra gasta una entrada.
                $table->string('access_mode', 20)->default('unlimited')->after('duration_days');
            }
            if (! Schema::hasColumn('plans', 'entry_credits')) {
                $table->unsignedInteger('entry_credits')->nullable()->after('access_mode');
            }
            if (! Schema::hasColumn('plans', 'access_days')) {
                $table->json('access_days')->nullable()->after('entry_credits');
            }
            if (! Schema::hasColumn('plans', 'access_windows')) {
                $table->json('access_windows')->nullable()->after('access_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            foreach (['access_windows', 'access_days', 'entry_credits', 'access_mode'] as $columna) {
                if (Schema::hasColumn('plans', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
