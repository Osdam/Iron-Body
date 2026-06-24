<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil fiscal reutilizable del adquiriente. Se liga a `identities` (capa
 * central que agrupa miembro + entrenador) con relación 1:1, y opcionalmente a
 * user/member por conveniencia. Centraliza los datos DIAN que hoy están
 * dispersos o incompletos (tipo de documento real, DV, municipio, régimen).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('identity_id')->nullable()->unique()
                ->constrained('identities')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('member_id')->nullable()->index();

            $table->string('doc_type')->nullable();        // CC | NIT | CE | PAS ...
            $table->string('doc_number')->nullable()->index();
            $table->string('dv')->nullable();              // dígito de verificación (NIT)
            $table->string('person_type')->nullable();     // natural | juridica
            $table->string('legal_name')->nullable();      // razón social
            $table->json('tax_responsibilities')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city_code')->nullable();
            $table->string('department_code')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_profiles');
    }
};
