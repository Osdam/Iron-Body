<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RECIBOS TÉRMICOS DE CAJA.
 *
 * El CRM imprime una tirilla de 58 mm por cada cobro de mostrador. Lo que se
 * imprime NO se compone en el navegador: sale de aquí, con los datos reales de
 * la operación congelados en `receipts.snapshot`, un número propio por caja y
 * el rastro de cada intento de impresión.
 *
 * SOLO TABLAS NUEVAS. Ninguna columna de `payments`, `product_sales`,
 * `receivable_payments` ni `cash_shifts` cambia: un recibo es una LECTURA de una
 * operación que ya ocurrió, y si emitirlo pudiera tocar la operación, imprimir
 * dos veces podría cobrar dos veces.
 *
 *  · receipts           — el documento, inmutable una vez emitido.
 *  · receipt_sources    — de qué filas de dinero sale. unique(tipo, id): una
 *                         operación tiene UN recibo, por mucho que se pida.
 *  · receipt_sequences  — el contador por caja, bloqueado al emitir para que la
 *                         numeración no tenga huecos ni duplicados.
 *  · receipt_prints     — append-only: cada intento, cada omisión y cada
 *                         omisión denegada, con quién y cuándo.
 *
 * Reversible: down() borra las cuatro tablas. No hay datos previos que perder
 * fuera de estas tablas, y nada de fuera depende de ellas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // RG-000001 (gimnasio) / RP-000001 (productos). Prefijos en
            // config/caja.php. Único global: los prefijos ya distinguen la caja.
            $table->string('number', 32)->unique();
            $table->string('cash_type', 16);
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
            $table->string('kind', 40);
            // Identidad de la operación impresa: 'payment:123', 'product_sale:45',
            // 'receivable_payment:67' o 'rp:67,68' para un cobro de varias deudas.
            // Es la llave de idempotencia: pedir el mismo recibo dos veces
            // devuelve el mismo documento.
            $table->string('origin_key', 191)->unique();
            // Código REAL de la operación (V-000123, payments.reference) o null.
            $table->string('operation_ref', 120)->nullable();
            $table->timestamp('operation_at')->nullable();
            $table->timestamp('issued_at');
            // Emitido más de N minutos después de la operación (config). Un
            // recibo impreso al día siguiente no es el que recibió el cliente.
            $table->boolean('issued_late')->default(false);
            $table->foreignId('issued_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('issued_by_name')->nullable();
            $table->decimal('total', 12, 2);
            $table->string('customer_name')->nullable();
            // Lo que se imprime. INMUTABLE: el modelo rechaza cualquier cambio.
            $table->json('snapshot');
            $table->unsignedSmallInteger('template_version')->default(1);
            $table->timestamps();

            $table->index('cash_shift_id');
            $table->index(['cash_type', 'issued_at']);
        });

        Schema::create('receipt_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('receipts');
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');

            // Una fila de dinero pertenece a UN recibo como mucho. Es lo que
            // impide que el mismo abono salga en dos tirillas distintas aunque se
            // pida una vez suelto y otra dentro de un cobro de varias deudas.
            $table->unique(['source_type', 'source_id']);
            $table->index('receipt_id');
        });

        Schema::create('receipt_sequences', function (Blueprint $table): void {
            $table->string('cash_type', 16)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });

        // Las dos filas existen desde el principio: `lockForUpdate` sobre una
        // fila que no existe no bloquea nada, y la primera emisión concurrente
        // de cada caja podría repetir número.
        DB::table('receipt_sequences')->insert([
            ['cash_type' => 'gym', 'last_number' => 0],
            ['cash_type' => 'products', 'last_number' => 0],
        ]);

        Schema::create('receipt_prints', function (Blueprint $table): void {
            $table->id();
            // = client_attempt_id. Lo genera el CRM y hace idempotente el
            // registro del intento: un reintento de red no crea otra fila.
            $table->uuid('uuid')->unique();
            $table->foreignId('receipt_id')->constrained('receipts');
            // original | retry | reprint | skip. Lo decide el SERVIDOR.
            $table->string('kind', 16);
            // started | sent | error | skipped | denied. Cada fila transiciona
            // como mucho una vez (started → sent|error).
            $table->string('status', 16);
            $table->string('context', 16);
            $table->string('print_mode', 16)->default('unknown');
            $table->string('reason', 255)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->foreignId('actor_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_role', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['receipt_id', 'id']);
        });

        // UN SOLO ORIGINAL POR RECIBO. Índice único PARCIAL: la misma sintaxis
        // vale en PostgreSQL y en SQLite (que soporta índices parciales desde
        // 3.8). Es la red bajo el bloqueo del recibo: si dos pestañas pidieran
        // a la vez el primer intento, la segunda choca aquí en vez de dejar dos
        // «originales».
        DB::statement(
            "CREATE UNIQUE INDEX receipt_prints_one_original_idx ON receipt_prints (receipt_id) WHERE kind = 'original'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS receipt_prints_one_original_idx');
        Schema::dropIfExists('receipt_prints');
        Schema::dropIfExists('receipt_sequences');
        Schema::dropIfExists('receipt_sources');
        Schema::dropIfExists('receipts');
    }
};
