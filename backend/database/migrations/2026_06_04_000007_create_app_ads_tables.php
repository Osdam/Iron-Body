<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publicidad gestionada desde el CRM (Bloque 4). La imagen vive en Firebase
 * Storage (IRONBODYADS/{uuid}/image.jpg) o en el disco público (fallback);
 * `image_url` es lo que muestra la app, `image_path` la ruta del objeto para
 * poder borrarlo. La frecuencia limita cuántas veces se muestra por miembro.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_ads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_url', 1000);
            $table->string('image_path', 1000)->nullable(); // ruta del objeto (Firebase) para borrar
            $table->string('target_url', 1000)->nullable();  // acción/link opcional
            $table->string('placement', 40)->default('home');
            $table->string('frequency_rule', 20)->default('once'); // once | daily | always
            $table->integer('priority')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('app_ad_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_ad_id')->constrained('app_ads')->cascadeOnDelete();
            $table->unsignedBigInteger('member_id');
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['app_ad_id', 'member_id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_ad_views');
        Schema::dropIfExists('app_ads');
    }
};
