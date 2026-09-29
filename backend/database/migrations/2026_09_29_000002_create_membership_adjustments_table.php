<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AJUSTES DE MEMBRESÍA: los días y las entradas que se regalan a mano.
 *
 * Siempre va a pasar: se cayó la luz, el lector no lo reconoció y entró dos
 * veces, estuvo enfermo tres días, el recepcionista se equivocó. Hasta ahora eso
 * se arreglaba editándole la fecha de vencimiento al socio, y quedaba sin
 * rastro: nadie podía saber después quién le dio esos días ni por qué.
 *
 * Cada ajuste queda aquí como un asiento: quién, cuánto, cuándo y por qué. Y
 * como es un libro y no un contador, se puede leer al revés —un ajuste negativo
 * corrige uno anterior— sin perder la historia.
 *
 * DOS CLASES, CON VIDAS DISTINTAS
 *   'days'    → mueve `users.membership_end_date` en el momento; se guardan el
 *               antes y el después porque la columna se sobrescribe.
 *   'entries' → NO se guarda ningún contador: las entradas disponibles se
 *               calculan (plan + ajustes − días usados). Por eso el asiento lleva
 *               la ventana a la que pertenece: unas entradas regaladas en octubre
 *               no deben aparecer sumadas en el periodo de noviembre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'days' | 'entries'
            $table->string('kind', 12);
            // Con signo: negativo corrige un ajuste anterior.
            $table->integer('amount');

            // La ventana de membresía a la que pertenece el ajuste. En 'entries'
            // es lo que evita que se arrastren de un periodo al siguiente.
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();

            // Solo en 'days': la vigencia antes y después de moverla.
            $table->date('previous_end_date')->nullable();
            $table->date('new_end_date')->nullable();

            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'kind']);
            $table->index(['user_id', 'window_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_adjustments');
    }
};
