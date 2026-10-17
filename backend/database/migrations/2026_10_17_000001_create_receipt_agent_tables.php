<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SERVICIO LOCAL DE IMPRESIÓN DE RECIBOS (ESC/POS por el spooler de Windows).
 *
 * El navegador ya no rasteriza la tirilla: le entrega al servicio del equipo de
 * caja un TICKET de un solo uso, y el servicio canjea ese ticket aquí por las
 * líneas YA MAQUETADAS del recibo. Contrato: CONTRATO_AGENTE_IMPRESION.md v1.
 *
 * SOLO ADITIVA. Dos tablas nuevas y dos columnas NULLABLE en `receipt_prints`.
 * Ninguna fila existente cambia de significado: un intento previo queda con
 * `station_id` y `dispatched_at` a null, que es exactamente lo que fue (no pasó
 * por ningún servicio). Ni `payments`, ni `product_sales`, ni
 * `receivable_payments`, ni `cash_shifts`, ni `receipts` se tocan.
 *
 *  · receipt_stations       — cada equipo de caja con el servicio instalado. Lo
 *                             registra un administrador con `cash.*.manage`: un
 *                             servicio que nadie registró no recibe nada.
 *  · receipt_agent_tickets  — la autorización de cada trabajo. Se guarda SOLO el
 *                             SHA-256 del token: con una copia de la base no se
 *                             puede canjear ningún ticket.
 *  · receipt_prints         — + station_id (qué equipo) y dispatched_at (cuándo
 *                             lo tomó el servicio; «ENVIANDO» en el CRM).
 *
 * Reversible: down() quita las columnas y las dos tablas. Lo único que se pierde
 * es el rastro de los tickets y estaciones, que solo existe desde aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_stations', function (Blueprint $table): void {
            $table->id();
            // Lo genera el servicio en su primer arranque y no cambia. No es un
            // secreto: es el nombre del equipo, y por eso solo con él no se
            // imprime nada (hace falta además un ticket emitido para él).
            $table->uuid('station_key')->unique();
            $table->string('name', 60);
            $table->boolean('active')->default(true);
            // Lo que el administrador configura para ese equipo…
            $table->string('printer_name', 120)->nullable();
            $table->smallInteger('columns')->default(32);
            $table->string('codepage', 16)->default('cp850');
            $table->smallInteger('feed_lines')->default(4);
            // …y lo que el servicio contó la última vez que canjeó algo.
            $table->string('agent_version', 32)->nullable();
            $table->smallInteger('agent_protocol')->nullable();
            $table->string('last_printer_name', 120)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
        });

        // Antes que la tabla de tickets: en SQLite añadir una clave foránea a una
        // tabla existente la RECONSTRUYE, y conviene que nada la referencie aún.
        Schema::table('receipt_prints', function (Blueprint $table): void {
            $table->foreignId('station_id')->nullable()->constrained('receipt_stations')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
        });
        $this->restoreOneOriginalIndex();

        Schema::create('receipt_agent_tickets', function (Blueprint $table): void {
            $table->id();
            // El identificador PÚBLICO del ticket (ticket_id en el canje). El
            // token no se guarda: solo su huella.
            $table->uuid('uuid')->unique();
            $table->char('token_hash', 64)->unique();
            // print | test | configure
            $table->string('purpose', 16);
            $table->foreignId('receipt_station_id')->constrained('receipt_stations');
            $table->foreignId('receipt_print_id')->nullable()->constrained('receipt_prints');
            // Página de prueba: minimal | full | sample_1 | sample_5 | sample_20 |
            // sample_membership. 32 y no 16 (lo que decía el contrato §8):
            // «sample_membership» tiene 17 caracteres y PostgreSQL lo rechazaría.
            $table->string('variant', 32)->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('issued_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('issued_by_name')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            // Canje: una vez, por la estación del ticket.
            $table->timestamp('redeemed_at')->nullable();
            $table->string('redeemed_ip', 45)->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->smallInteger('columns')->nullable();
            // sha256 de las líneas entregadas: qué se mandó a imprimir, sin
            // guardar el texto (lleva nombres de clientes).
            $table->char('content_sha256', 64)->nullable();
            // Resultado: una vez, dentro de la ventana.
            $table->timestamp('reported_at')->nullable();
            $table->string('outcome', 16)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->bigInteger('spool_job_id')->nullable();
            $table->string('printer_name', 120)->nullable();
            $table->timestamps();

            // Los últimos eventos de una estación, y los tickets de un intento
            // (para revocarlos en una repetición).
            $table->index(['receipt_station_id', 'id']);
            $table->index('receipt_print_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_agent_tickets');

        Schema::table('receipt_prints', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('station_id');
            $table->dropColumn('dispatched_at');
        });
        $this->restoreOneOriginalIndex();

        Schema::dropIfExists('receipt_stations');
    }

    /**
     * EL ÍNDICE ÚNICO PARCIAL de 2026_10_16_000001 («un solo original por
     * recibo») tiene que sobrevivir a esta migración.
     *
     * En PostgreSQL añadir o quitar la clave foránea es un ALTER TABLE y no lo
     * toca. En SQLite, Laravel RECONSTRUYE la tabla y vuelve a crear los índices
     * a partir de su descripción, que no incluye el `WHERE`: el índice volvería
     * como único sobre `receipt_id` a secas y solo se podría registrar UN intento
     * por recibo. Se rehace aquí, igual que en la migración original.
     */
    private function restoreOneOriginalIndex(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS receipt_prints_one_original_idx');
        DB::statement(
            "CREATE UNIQUE INDEX receipt_prints_one_original_idx ON receipt_prints (receipt_id) WHERE kind = 'original'"
        );
    }
};
