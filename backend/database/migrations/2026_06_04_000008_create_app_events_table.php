<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos del gimnasio gestionados desde el CRM (Bloque 4). Imagen en Firebase
 * Storage (IRONBODYEVENTS/{uuid}/image.jpg) o disco público (fallback). La app
 * los lista y muestra el detalle; nada hardcodeado.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->string('image_path', 1000)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->string('cta_label', 80)->nullable();
            $table->string('cta_url', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_events');
    }
};
