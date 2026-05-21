<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_identity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 20)->nullable();
            $table->string('document_number', 50);
            $table->date('birth_date')->nullable();
            $table->string('ocr_full_name')->nullable();
            $table->decimal('ocr_confidence', 5, 2)->nullable();
            $table->string('identity_status', 40)->default('needs_manual_review');
            $table->string('front_path');
            $table->string('front_mime', 80)->nullable();
            $table->unsignedBigInteger('front_size')->nullable();
            $table->string('back_path')->nullable();
            $table->string('back_mime', 80)->nullable();
            $table->unsignedBigInteger('back_size')->nullable();
            $table->timestamps();

            $table->unique('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_identity_documents');
    }
};
