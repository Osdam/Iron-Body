<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_biometrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('face_path');
            $table->string('face_mime', 80)->nullable();
            $table->unsignedBigInteger('face_size')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->unsignedBigInteger('bytes_length')->nullable();
            $table->timestamps();

            $table->unique('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_biometrics');
    }
};
