<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de etiquetas, y de dónde salió cada asignación.
 *
 * Hasta ahora una etiqueta era una cadena suelta en la conversación. Eso basta
 * para escribir «vip» y volver a encontrarlo, y falla en todo lo demás: no hay
 * color, ni descripción, ni forma de distinguir la etiqueta que puso una
 * persona de la que dedujo el sistema. Y esa última distinción es la que
 * importa, porque una etiqueta de origen publicitario que alguien puede editar
 * a mano deja de ser evidencia y pasa a ser una opinión.
 *
 * Dos tablas:
 *
 *  · `marketing_tags` — el catálogo. Nombre visible, color, categoría y quién
 *    puede tocarla.
 *  · las asignaciones existentes se enriquecen con el ORIGEN de cada una, sin
 *    romper la columna `tag` que ya usa el resto del sistema.
 *
 * La compatibilidad hacia atrás es deliberada: `marketing_conversation_tags.tag`
 * sigue siendo la babosa de texto que devuelven los endpoints actuales, así que
 * nada de lo ya entregado deja de funcionar mientras se adopta el catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_tags', function (Blueprint $table): void {
            $table->id();

            // La babosa es la identidad: es lo que ya viaja por la API.
            $table->string('slug', 40)->unique();
            $table->string('name', 60);
            $table->string('description', 200)->nullable();

            // commercial | operational | attribution
            $table->string('category')->default('commercial');

            /*
             * manual    — la pone una persona.
             * automatic — la deduce el motor comercial (segmentos).
             * system    — la pone el sistema por un hecho operativo.
             * source    — viene de la atribución. Es evidencia, no opinión.
             */
            $table->string('kind')->default('manual');

            $table->string('color', 20)->default('neutral');

            /*
             * Bloqueada contra edición manual. Las de atribución lo están: si
             * alguien pudiera quitar «Meta Ads» de una conversación que vino de
             * un anuncio, la analítica de pauta dejaría de ser fiable y nadie
             * sabría por qué.
             */
            $table->boolean('locked')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);

            $table->timestamps();

            $table->index(['category', 'active']);
            $table->index('kind');
        });

        Schema::table('marketing_conversation_tags', function (Blueprint $table): void {
            $table->unsignedBigInteger('tag_id')->nullable()->after('tag');
            // Copia del tipo en el momento de asignar: si mañana el catálogo
            // cambia, el histórico sigue diciendo cómo se puso ESTA.
            $table->string('assigned_kind')->default('manual')->after('tag_id');
            // Por qué se puso. Lo que convierte una etiqueta automática en algo
            // defendible delante de quien pregunta.
            $table->jsonb('evidence')->nullable()->after('assigned_kind');
            $table->timestamp('removed_at')->nullable()->after('evidence');
            $table->unsignedBigInteger('removed_by')->nullable()->after('removed_at');

            $table->index('tag_id');
            $table->index(['assigned_kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('marketing_conversation_tags', function (Blueprint $table): void {
            $table->dropIndex(['tag_id']);
            $table->dropIndex(['assigned_kind', 'created_at']);
            $table->dropColumn(['tag_id', 'assigned_kind', 'evidence', 'removed_at', 'removed_by']);
        });

        Schema::dropIfExists('marketing_tags');
    }
};
