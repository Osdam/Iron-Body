<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('signature_path');
            $table->string('signature_mime', 80)->nullable();
            $table->unsignedBigInteger('signature_size')->nullable();
            $table->timestamps();

            $table->unique('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_signatures');
    }
};
