<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice para paginar el historial por cursor.
 *
 * Ya existía `(conversation_id, created_at)`, que sirve para ordenar pero deja
 * un hueco: dos mensajes con la misma marca de tiempo —y los hay, porque
 * WhatsApp entrega en lotes— no tienen un orden definido entre ellos. Con
 * paginación eso no es un detalle estético: un orden que cambia entre dos
 * peticiones hace que un mensaje aparezca dos veces o no aparezca nunca.
 *
 * Añadir `id` al final da el desempate y, de paso, deja que el motor resuelva
 * la consulta sin volver a la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table): void {
            $table->index(['conversation_id', 'created_at', 'id'], 'mm_conversation_cursor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table): void {
            $table->dropIndex('mm_conversation_cursor_idx');
        });
    }
};
