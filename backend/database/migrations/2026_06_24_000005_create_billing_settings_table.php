<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de facturación editables desde el CRM (clave/valor). SOLO datos NO
 * sensibles: IVA por defecto por concepto, textos, toggles operativos. Las
 * credenciales y el ambiente NUNCA viven aquí: solo en .env / config/billing.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
