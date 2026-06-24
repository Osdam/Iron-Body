<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traza append-only de la integración con Factus. Cada paso (enqueue, token,
 * emit, retry, sync, credit_note, download) deja un registro con el payload YA
 * SANEADO (sin password, client_secret, access_token ni datos sensibles).
 *
 * Sin updated_at: las filas no se modifican (auditoría inmutable).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('electronic_invoice_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('electronic_invoice_id')->constrained('electronic_invoices')->cascadeOnDelete();
            $table->string('action')->index();        // ver App\Enums\InvoiceLogAction
            $table->string('endpoint')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('result')->nullable();     // ok | error
            $table->text('message')->nullable();
            $table->json('payload_excerpt')->nullable(); // SANEADO
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_invoice_logs');
    }
};
