<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stories tipo Instagram/WhatsApp — content efímero 24h.
 *
 * Diseño:
 * - `author_type` + `author_id` polimórfico ligero (member o user) — un story
 *   puede crearse desde la app (member) o desde el CRM (user/admin).
 * - `expires_at` indexado para que el feed filtre rápido y la purga limpie.
 * - story_views es la tabla de "quién vio cada story". Unique compound impide
 *   contar la misma vista dos veces (idempotencia natural).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            // Polimórfico ligero — author puede ser 'member' o 'user' (admin CRM).
            $table->string('author_type', 16);
            $table->unsignedBigInteger('author_id');
            // Cache del nombre/avatar al momento de crear — evita JOINs en el
            // feed y sobrevive si el author cambia de avatar después.
            $table->string('author_name', 120);
            $table->string('author_avatar', 500)->nullable();

            // Contenido.
            $table->enum('type', ['image', 'video']);
            $table->string('file_path', 500);     // ruta relativa al disk
            $table->string('disk', 32)->default('public');
            $table->unsignedInteger('duration_ms')->nullable(); // solo video
            $table->string('caption', 280)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Lifecycle.
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['author_type', 'author_id'], 'stories_author_idx');
        });

        Schema::create('story_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')
                ->constrained('stories')
                ->cascadeOnDelete();
            $table->string('viewer_type', 16); // 'member' | 'user'
            $table->unsignedBigInteger('viewer_id');
            $table->timestamp('viewed_at')->useCurrent();

            // Una vista por (story, viewer) — idempotencia.
            $table->unique(
                ['story_id', 'viewer_type', 'viewer_id'],
                'story_views_unique_viewer'
            );
            $table->index(['viewer_type', 'viewer_id'], 'story_views_viewer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_views');
        Schema::dropIfExists('stories');
    }
};
