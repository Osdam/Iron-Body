<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal GLOBAL de cambios de catálogo para el stream SSE.
 *
 * `member_realtime_events` es por socio, y eso no sirve aquí: un cambio de
 * catálogo afecta a los 3.739 a la vez, así que emitirlo por ese canal
 * significaría escribir una fila por socio en cada venta de Caja. Esta tabla
 * guarda el hecho UNA vez y el mismo stream la multiplexa.
 *
 * El evento es una INVALIDACIÓN, no una fuente de verdad: dice qué producto
 * cambió y en qué, y el cliente vuelve a pedir el estado canónico por la API.
 * Por eso no lleva precio, stock ni nada que pudiera quedar obsoleto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            // SIN clave foránea a propósito: al archivar o borrar un producto
            // hay que poder avisar de ello, y con un FK en cascada el aviso
            // desaparecería junto con el producto.
            $table->unsignedBigInteger('product_id')->nullable();
            $table->json('changed')->nullable();
            // Marca monotónica en milisegundos: permite al cliente descartar
            // eventos duplicados tras una reconexión.
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamp('created_at')->nullable();

            // El stream lee siempre «lo posterior a este id».
            $table->index('id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_events');
    }
};
