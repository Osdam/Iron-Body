<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->timestamp('reserved_at')->useCurrent();
            $table->timestamps();

            $table->unique(['class_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_reservations');
    }
};
