<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hace falta para que la lista del Inbox no empeore con el uso.
 *
 * Medido con 5.004 conversaciones y 425.018 mensajes, una lista de veinte
 * filas costaba 36 consultas. Veinte de ellas eran la misma: «dame el último
 * mensaje de esta conversación», una por fila. No se nota con veinte
 * conversaciones y se nota mucho el día que la base tenga latencia de red.
 *
 * Se guarda el texto del último mensaje EN la conversación. Es duplicar un
 * dato, y aquí está justificado: la tabla ya lleva `last_message_at`,
 * `last_inbound_at` y `unread_count` por el mismo motivo, y quien lo mantiene
 * es un observador sobre la tabla de mensajes, así que ningún camino de
 * escritura puede olvidarse de actualizarlo.
 *
 * El índice de orden es el otro: sin filtro de estado, la lista ordenaba
 * `last_message_at` con un escaneo secuencial de la tabla entera. Existía un
 * índice compuesto `(status, last_message_at)` que no sirve cuando no hay
 * filtro por estado, que es justo el caso normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table): void {
            $table->string('last_message_preview', 160)->nullable()->after('last_message_at');

            // Orden por defecto de la bandeja. DESC porque siempre se pide lo
            // más reciente primero; un índice ascendente sirve, pero este deja
            // la lectura en orden físico y evita el paso de ordenación.
            $table->index([DB::raw('last_message_at DESC')], 'mc_last_message_at_desc_idx');
        });

        $this->backfillPreviews();
    }

    /**
     * Relleno inicial.
     *
     * Sin esto la lista se veria sin previsualizacion hasta que cada
     * conversacion recibiera un mensaje nuevo, que en las cerradas es nunca.
     * Se hace en una sola sentencia por motor: recorrer las conversaciones en
     * PHP seria exactamente el N+1 que se esta quitando.
     */
    private function backfillPreviews(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE marketing_conversations c
                SET last_message_preview = left(m.body, 160)
                FROM (
                    SELECT DISTINCT ON (conversation_id) conversation_id, body
                    FROM marketing_messages
                    ORDER BY conversation_id, created_at DESC, id DESC
                ) m
                WHERE m.conversation_id = c.id
            SQL);

            return;
        }

        // SQLite (pruebas) y cualquier otro motor: subconsulta correlacionada.
        // Es mas lenta, y da igual: aqui las tablas son de juguete.
        DB::statement(<<<'SQL'
            UPDATE marketing_conversations
            SET last_message_preview = (
                SELECT substr(body, 1, 160) FROM marketing_messages
                WHERE conversation_id = marketing_conversations.id
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table): void {
            $table->dropIndex('mc_last_message_at_desc_idx');
            $table->dropColumn('last_message_preview');
        });
    }
};
