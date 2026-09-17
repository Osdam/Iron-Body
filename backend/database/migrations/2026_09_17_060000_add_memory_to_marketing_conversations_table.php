<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memoria conversacional ESTRUCTURADA de ULTRON.
 *
 * `summary` seguía siendo una frase; el referente de «sí por favor» vivía solo
 * en el texto y el modelo lo perdía. Aquí va el estado: qué se entregó ya
 * (precios, beneficios, hechos), qué ofreció el agente en su última pregunta y
 * a qué apuntaría un «eso» o un «dale». Aditiva y nullable: nada existente cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table) {
            $table->json('memory')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table) {
            $table->dropColumn('memory');
        });
    }
};
