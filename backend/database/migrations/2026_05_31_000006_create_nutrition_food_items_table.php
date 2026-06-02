<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de alimentos (base de la app + personalizados por miembro).
 *
 * Macros por porción base (serving). `member_id` null = alimento global del
 * catálogo; con member_id = alimento personalizado creado por ese miembro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_food_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id')->nullable(); // null = global
            $table->string('name');
            $table->string('brand')->nullable();
            $table->decimal('calories', 7, 2)->default(0);
            $table->decimal('protein_g', 6, 2)->default(0);
            $table->decimal('carbs_g', 6, 2)->default(0);
            $table->decimal('fat_g', 6, 2)->default(0);
            $table->string('serving_label')->nullable(); // "100 g", "1 unidad"
            $table->string('source')->nullable(); // base | custom
            $table->timestamps();

            $table->index('member_id');
            $table->index('name');
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_food_items');
    }
};
