<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ¿ESTA DEUDA LE QUITA LA APP AL SOCIO?
 *
 * Hasta ahora la respuesta era «cualquier deuda de gimnasio vencida, sí», y
 * para lo que había era correcto: todas eran membresías o planes a plazos, y
 * no pagar la membresía es exactamente el caso en que el acceso se retiene.
 *
 * El piso de los entrenadores rompe esa equivalencia. Es un cobro del gimnasio
 * —va a la caja del gimnasio, es ingreso del gimnasio— pero no es lo que el
 * socio compró para usar la app. Si el trato dice que lo asume el cliente y se
 * le pasa la fecha, con la regla anterior se quedaba sin rutinas y sin
 * nutrición por una comisión que se pactó entre su entrenador y el gimnasio.
 * Puede que eso sea justo lo que el gimnasio quiere; puede que no. Lo que no
 * puede es decidirse por omisión.
 *
 * Así que ahora lo decide cada deuda, no la categoría.
 *
 * NACE EN `true`. Todo lo que ya existe —y todo lo que se abra sin decir nada—
 * se sigue comportando igual: una membresía impagada sigue reteniendo el
 * acceso. Solo quien marque lo contrario al pactar un piso obtiene una deuda
 * que se reclama pero no castiga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table): void {
            $table->boolean('blocks_benefits')->default(true)->after('due_at');
        });

        Schema::table('trainer_commission_agreements', function (Blueprint $table): void {
            // EL VALOR POR DEFECTO ES «NO BLOQUEA», y es lo contrario del de
            // `receivables` a propósito.
            //
            // Una deuda normal nace bloqueando porque es lo que el socio
            // compró. El piso nace sin bloquear porque castigar al cliente con
            // perder la app por una comisión entre su entrenador y el gimnasio
            // es una consecuencia que sorprende, y las consecuencias que
            // sorprenden no se activan solas: se marcan.
            $table->boolean('blocks_app')->default(false)->after('payer');
        });
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table): void {
            $table->dropColumn('blocks_benefits');
        });

        Schema::table('trainer_commission_agreements', function (Blueprint $table): void {
            $table->dropColumn('blocks_app');
        });
    }
};
