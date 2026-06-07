<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_recent_foods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('nutrition_foods')->cascadeOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->integer('use_count')->default(1);
            $table->timestamps();
            $table->unique(['member_id', 'food_id']);
            $table->index(['member_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_recent_foods');
    }
};
