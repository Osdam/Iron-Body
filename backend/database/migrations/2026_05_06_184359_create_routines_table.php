<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('objective')->nullable();
            $table->string('level')->nullable();
            $table->integer('duration_minutes')->default(0);
            $table->integer('days_per_week')->default(0);
            $table->string('trainer_name')->nullable();
            $table->unsignedBigInteger('trainer_id')->nullable();
            $table->string('assigned_member_name')->nullable();
            $table->unsignedBigInteger('assigned_member_id')->nullable();
            $table->string('status')->default('Activa');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->json('exercises')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('assigned_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routines');
    }
};
