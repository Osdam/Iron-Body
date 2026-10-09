<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UN MES ANULADO TIENE QUE PODERSE VOLVER A COBRAR.
 *
 * El índice era único por acuerdo y mes, y servía para lo que tenía que
 * servir: que un doble clic o dos recepcionistas a la vez no le cobraran
 * octubre dos veces a la misma persona.
 *
 * Pero la invariante estaba mal escrita. No es «una fila por mes», es «un
 * cobro VIGENTE por mes». Con la de antes, anular octubre dejaba el mes
 * muerto: la fila seguía ocupando el sitio y no se podía registrar el cobro
 * correcto. Y anular existe justamente para corregir y volver a cobrar; si lo
 * impide, no sirve de nada.
 *
 * El índice único se va y la invariante pasa al servicio, que es quien puede
 * distinguir una deuda anulada de una viva. Se pierde la red de la base de
 * datos contra el doble clic, y a cambio el histórico conserva los intentos
 * anulados —que es lo que permite contestar «¿se le cobró octubre?» con «sí,
 * y se anuló porque…»— en vez de borrarlos para dejar sitio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_commission_charges', function (Blueprint $table): void {
            $table->dropUnique(['agreement_id', 'period']);
            // Se conserva como índice normal: la consulta del panel sigue
            // buscando por acuerdo y mes en cada carga.
            $table->index(['agreement_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::table('trainer_commission_charges', function (Blueprint $table): void {
            $table->dropIndex(['agreement_id', 'period']);
            $table->unique(['agreement_id', 'period']);
        });
    }
};
