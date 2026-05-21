<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_ai_capabilities', function (Blueprint $table) {
            $table->id();
            // Apunta al plan/membresía EXISTENTE (tabla plans) cuando se puede
            // resolver. plan_code es el fallback (coincide con users.plan, que
            // es un string libre que no siempre matchea plans.name).
            $table->foreignId('membership_plan_id')->nullable()
                ->constrained('plans')->nullOnDelete();
            $table->string('plan_code')->nullable();

            $table->boolean('ai_enabled')->default(true);
            $table->integer('free_trial_messages')->default(5);
            $table->integer('monthly_messages_limit')->nullable();
            $table->integer('daily_messages_limit')->nullable();
            $table->integer('max_output_tokens')->default(600);
            $table->string('context_level')->default('basic'); // basic | personalized | full
            $table->boolean('progress_analysis_enabled')->default(false);
            $table->boolean('smart_recommendations_enabled')->default(false);
            $table->boolean('weekly_summary_enabled')->default(false);
            $table->boolean('proactive_notifications_enabled')->default(false);
            $table->integer('fair_use_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('membership_plan_id');
            $table->index('plan_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_ai_capabilities');
    }
};
