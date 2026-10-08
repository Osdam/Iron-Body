<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ¿POR QUÉ PUERTA ENTRÓ?
 *
 * El registro decía quién y cuándo, pero no por qué se le dejó pasar. Mientras
 * solo hubo una razón —tener la membresía al día— no hacía falta.
 *
 * Con el acceso de empleado hay dos, y sin esta columna no se puede contestar
 * «¿a qué hora entran los empleados?»: un entrenador que además paga su
 * membresía queda indistinguible de cualquier otro socio, porque lo que los
 * separa no es cómo se llaman sino la puerta que usaron.
 *
 * SE CONGELA AL REGISTRAR, y por eso es una columna y no un cálculo. Dentro de
 * un mes esa persona puede haber dejado de trabajar aquí; el histórico tiene
 * que seguir diciendo que aquel martes entró como entrenador.
 *
 * Null es lo de antes: las filas anteriores a esta columna son todas de socios
 * entrando con su membresía, porque el acceso de empleado no existía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            // 'membership' | 'employee'
            $table->string('access_via', 12)->nullable()->after('limit_reason');
            $table->string('employee_role', 60)->nullable()->after('access_via');
            $table->index(['access_via', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropIndex(['access_via', 'captured_at']);
            $table->dropColumn(['access_via', 'employee_role']);
        });
    }
};
