<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reacciones rápidas a stories — heart, fire, muscle, clap, trophy, lightning.
 *
 * Diseño:
 * - Una reacción por (story, viewer). Si el mismo viewer reacciona de nuevo,
 *   se actualiza el `type` y el `reacted_at` — no se crea duplicado.
 *   Esto se garantiza con `unique(story_id, viewer_type, viewer_id)`.
 * - Soporta el mismo modelo de viewer polimórfico que `story_views`:
 *   member (app) o user (admin del CRM).
 * - `type` se valida en el controller contra una lista cerrada (enum
 *   nivel app) — la columna es VARCHAR para flexibilidad futura.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('story_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')
                ->constrained('stories')
                ->cascadeOnDelete();
            $table->string('viewer_type', 16); // 'member' | 'user'
            $table->unsignedBigInteger('viewer_id');
            $table->string('type', 16); // heart | fire | muscle | clap | trophy | lightning
            $table->timestamp('reacted_at')->useCurrent();

            // Una reacción por (story, viewer) — UPSERT cambia type sin
            // crear duplicado. Garantía a nivel DB, no solo aplicación.
            $table->unique(
                ['story_id', 'viewer_type', 'viewer_id'],
                'story_reactions_unique_reactor'
            );
            $table->index(['story_id', 'type'], 'story_reactions_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_reactions');
    }
};
