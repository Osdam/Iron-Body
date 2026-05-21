<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->uuid('member_uuid')->unique();
            $table->string('access_hash', 64)->unique();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('document_number', 50)->unique();
            $table->string('phone', 30)->nullable();
            $table->string('gender', 40)->nullable();
            $table->string('goal', 120)->nullable();
            $table->string('training_level', 80)->nullable();
            $table->text('injuries')->nullable();
            $table->date('birth_date')->nullable();
            $table->boolean('is_minor')->default(false);
            $table->string('status', 40)->default('created');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
