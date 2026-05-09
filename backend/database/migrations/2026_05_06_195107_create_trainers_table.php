<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('document', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('main_specialty')->nullable();
            $table->json('specialties')->nullable();
            $table->integer('experience_years')->default(0);
            $table->string('contract_type')->nullable();
            $table->string('status')->default('active');
            $table->float('rating')->default(0);
            $table->text('bio')->nullable();
            $table->text('certifications')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->json('availability')->nullable();
            $table->integer('assigned_classes')->default(0);
            $table->integer('assigned_members')->default(0);
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainers');
    }
};
