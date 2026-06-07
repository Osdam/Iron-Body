<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nutrition_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->date('summary_date');
            $table->decimal('calories', 10, 2)->default(0);
            $table->decimal('protein', 10, 2)->default(0);
            $table->decimal('carbs', 10, 2)->default(0);
            $table->decimal('fat', 10, 2)->default(0);
            $table->decimal('sugar', 10, 2)->nullable();
            $table->decimal('fiber', 10, 2)->nullable();
            $table->decimal('sodium', 10, 2)->nullable();
            $table->integer('entry_count')->default(0);
            $table->timestamps();
            $table->unique(['member_id', 'summary_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nutrition_daily_summaries');
    }
};
