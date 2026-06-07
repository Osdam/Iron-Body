<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('nutrition_foods')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['member_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_favorites');
    }
};
