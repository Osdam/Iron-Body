<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de hechos de clases y reservas, con cursor, para el tiempo real.
 *
 * POR QUÉ UNA TABLA. Una reserva cancelada se BORRA: no queda nada en
 * `class_reservations` que diga que existió. Sin un registro propio, el CRM
 * solo puede saber que «algo cambió» (por huella), nunca que se canceló esta
 * reserva de esta clase para esta fecha, ni reanudar sin huecos tras una
 * reconexión. Aquí se guarda el HECHO una vez, en la misma transacción que el
 * cambio, y el canal lo lee a partir de un id.
 *
 * Lo que NO lleva: datos del socio. Es una señal ("la clase 21 del 5 de
 * octubre ganó una reserva"); quien la recibe vuelve a pedir la lista por la
 * API, con sus permisos. Sin clave foránea a propósito: borrar la clase
 * también se avisa, y en cascada el aviso desaparecería con ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_events', function (Blueprint $table): void {
            $table->id();
            // booking.created | booking.cancelled | booking.updated | class.updated
            $table->string('type', 40);
            $table->unsignedBigInteger('class_id')->nullable();
            $table->date('session_date')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            // app | crm | trainer | system
            $table->string('source', 20)->default('system');
            // Detalle de class.updated: session.started, class.deleted, renewal…
            $table->string('reason', 40)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_events');
    }
};
