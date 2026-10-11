<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil LABORAL de una persona que no es (solo) entrenador: recepción,
 * administración, mantenimiento.
 *
 * Hasta ahora una recepcionista solo existía como cuenta del CRM (`admins`):
 * email y contraseña, a menudo compartidos, sin documento ni identidad de
 * persona. Eso no sirve para saber QUIÉN entró a trabajar. La persona es la
 * `identity` (documento), igual que para socios y entrenadores; este perfil
 * cuelga de ella como un perfil más, sin duplicar a nadie:
 *
 *   identities ─┬─ members          (socio)
 *               ├─ trainers         (perfil profesional, roles de planta/funcional)
 *               └─ staff_profiles   (perfil laboral, cargos de personal)  ← NUEVO
 *
 * Los cargos de una persona son la unión de sus roles de entrenador activos y
 * los de este perfil activo (ver config/workforce.php). Un entrenador de planta
 * NO necesita fila aquí: su cargo sale de su rol.
 *
 * `positions` es JSON a propósito: son unas decenas de filas y el filtrado por
 * cargo de las jornadas usa la foto del cargo guardada en cada jornada, no esta
 * columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Una persona, un perfil laboral. Con FK de motor (como
            // fiscal_profiles): las identidades no se borran ni se fusionan.
            $table->unsignedBigInteger('identity_id')->unique();
            $table->string('full_name', 160);
            $table->string('phone', 40)->nullable();
            $table->json('positions');
            $table->string('status', 20)->default('active'); // active | inactive
            $table->timestamp('deactivated_at')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->foreign('identity_id')->references('id')->on('identities')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
