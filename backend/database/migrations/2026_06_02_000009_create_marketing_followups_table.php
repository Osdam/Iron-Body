<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Seguimientos programados a leads (recuperación / no dejar enfriar). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_followups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->timestamp('due_at')->nullable();
            $table->string('type')->default('message'); // message | call | task
            $table->string('status')->default('pending'); // pending | done | cancelled
            $table->text('message_template')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index('lead_id');
            $table->foreign('lead_id')->references('id')->on('marketing_leads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_followups');
    }
};
