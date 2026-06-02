<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 80)->unique();
            $table->string('name');
            $table->string('version', 40);
            // adult | minor | any
            $table->string('applies_to', 20)->default('any');
            // Ruta (relativa al disco privado) del PDF oficial fuente.
            $table->string('source_file_path');
            // SHA256 del archivo fuente registrado (detecta alteraciones).
            $table->string('source_checksum', 64)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
