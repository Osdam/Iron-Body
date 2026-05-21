<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iron_ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('member_id')->nullable();
            // Clave estable del caller (documento; o conversation_id si anónimo
            // sin documento) → permite contar cuota por documento/día/mes.
            $table->string('document')->nullable();
            $table->unsignedBigInteger('membership_plan_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('model')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->decimal('estimated_cost', 10, 6)->nullable();
            // success | fallback | blocked | error
            $table->string('status');
            $table->string('block_reason')->nullable();
            $table->timestamps();

            $table->index(['document', 'created_at']);
            $table->index(['member_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iron_ai_usage_logs');
    }
};
