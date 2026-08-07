<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjuntos salientes: el archivo existe antes que el mensaje.
 *
 * Hasta ahora un adjunto solo podia nacer atado a un mensaje, porque los
 * entrantes llegan asi: primero el webhook con el mensaje, despues el archivo.
 * En la salida el orden es el contrario. Quien atiende suelta la foto en el
 * compositor, la ve, escribe el pie y recien entonces pulsa enviar —y puede no
 * pulsarlo nunca—. Obligar a crear el mensaje para poder subir el archivo
 * significaria dejar mensajes vacios en el historial cada vez que alguien se
 * arrepiente.
 *
 * Por eso `message_id` pasa a ser opcional: un adjunto sin mensaje es un
 * BORRADOR. Se queda en el disco privado con su expediente completo hasta que
 * lo reclama un envio, o hasta que la limpieza se lo lleva por viejo.
 *
 * `uploaded_by_admin_id` no es decorativo: mientras es borrador, ese campo es
 * lo UNICO que dice de quien es el archivo. Sin el, cualquiera con sesion
 * podria adjuntar a su mensaje un borrador ajeno pasando un id al azar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_message_attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('message_id')->nullable()->change();
            $table->unsignedBigInteger('uploaded_by_admin_id')->nullable()->after('direction');
        });

        Schema::table('marketing_message_attachments', function (Blueprint $table): void {
            // Barrido de borradores huerfanos: los que nunca llegaron a enviarse.
            $table->index(['direction', 'message_id', 'created_at'], 'mma_drafts_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_message_attachments', function (Blueprint $table): void {
            $table->dropIndex('mma_drafts_idx');
            $table->dropColumn('uploaded_by_admin_id');
        });

        // `message_id` se deja opcional a proposito: revertirlo a NOT NULL
        // fallaria si ya existe cualquier borrador, y romper una vuelta atras
        // es peor que dejar una columna mas permisiva de lo que estaba.
    }
};
