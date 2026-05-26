<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relación entrenador ↔ miembro. Un miembro puede tener UN entrenador activo a
 * la vez (status=active); el histórico queda con status=inactive + ended_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_trainer_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('trainer_id')->index();
            $table->string('assigned_by')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index(['trainer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_trainer_assignments');
    }
};
