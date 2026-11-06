<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BENEFICIO AÑADIDO: un plan que se compra ENCIMA de otro, sin sustituirlo.
 *
 * El PLAN DÍA y las clases sueltas no son membresías: son un derecho puntual
 * que alguien compra para una actividad, y casi siempre teniendo ya otra cosa
 * vigente. Hasta ahora el sistema no sabía distinguirlos de una renovación, y
 * el resultado está documentado en el caso que origina esta columna: una socia
 * con una ANUALIDAD vigente compró un plan día para una clase, y
 *
 *  · el plan día se ENCADENÓ al final del año —su derecho quedó fechado doce
 *    meses más adelante, así que pagó un día para hoy y el sistema se lo apuntó
 *    para el año siguiente—;
 *  · el vencimiento de la anualidad se movió esos días;
 *  · la etiqueta `users.plan` pasó a «PLAN DÍA», y con ella las reglas de acceso
 *    de todo el año que le quedaba pagado: un plan de UNA entrada. Quedó
 *    bloqueada en el acto, con el año pagado y vigente.
 *
 * No se puede adivinar cuál es cuál. `starts_on` no sirve de señal: el mostrador
 * lo manda SIEMPRE con la fecha de hoy, así que tomarlo por «empieza hoy»
 * convertiría toda renovación anticipada en una pérdida de días pagados. Y la
 * duración tampoco: un plan de 30 días puede ser una renovación o un añadido.
 *
 * Así que lo marca quien vende los planes, una vez, en el catálogo. Es una
 * propiedad del PRODUCTO, no de cada venta: «plan día» siempre es un añadido.
 *
 * Aditiva y con el comportamiento de siempre por defecto (`false`): ningún plan
 * existente cambia de conducta al aplicar esta migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('benefit_only')->default(false)->after('duration_days');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('benefit_only');
        });
    }
};
