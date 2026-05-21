<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistencia local de referencias visuales de ejercicios.
 *
 * Actúa como caché duradera de WorkoutX (u otro proveedor): así NO se llama a
 * la API externa en cada request y, si el proveedor falla, el backend sigue
 * respondiendo con lo ya sincronizado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exercises')) {
            return; // reutilizar si ya existe
        }

        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->index();
            $table->string('name')->index();
            $table->string('body_part')->nullable()->index();
            $table->string('target')->nullable()->index();
            $table->string('equipment')->nullable();
            $table->text('gif_url')->nullable();
            $table->json('instructions')->nullable();
            $table->string('provider')->default('workoutx')->index();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
