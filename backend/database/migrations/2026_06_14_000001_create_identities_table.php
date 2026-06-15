<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capa de identidad (aditiva). Una fila = una persona, anclada a su documento
 * normalizado. Sobre una identidad cuelgan, de forma OPCIONAL e independiente,
 * un perfil de miembro (`members.identity_id`) y/o uno o más perfiles
 * profesionales (`trainers.identity_id`).
 *
 * Esta tabla NO concede acceso por sí misma: el acceso de miembro y el acceso
 * profesional se siguen autorizando en sus propias capas. Aquí solo se modela
 * "quién es la persona" para evitar identidades duplicadas y permitir perfiles
 * dobles. Conocer el documento NO vincula perfiles en runtime: el enlace
 * self-service exige verificación OTP (ver IdentityLinkService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Documento normalizado (clave natural de la persona). Único cuando
            // está presente; se permiten múltiples NULL (personas sin documento
            // registrado todavía no se fusionan entre sí).
            $table->string('document_normalized', 80)->nullable()->unique();

            // Teléfono normalizado (solo dígitos). Referencia para el destino del
            // OTP de enlace; NUNCA es credencial ni clave de unión por sí solo.
            $table->string('phone_normalized', 40)->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
