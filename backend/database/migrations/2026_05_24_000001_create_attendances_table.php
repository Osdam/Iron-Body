<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('action', ['entry', 'exit']);
            $table->enum('source', ['facial', 'manual'])->default('manual');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('captured_at')->useCurrent();
            $table->timestamps();

            $table->index(['user_id', 'captured_at']);
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
