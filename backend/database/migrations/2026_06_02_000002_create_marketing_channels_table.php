<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Canales de marketing (instagram | facebook | whatsapp | ads | organic). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type'); // instagram | facebook | whatsapp | ads | organic
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_channels');
    }
};
