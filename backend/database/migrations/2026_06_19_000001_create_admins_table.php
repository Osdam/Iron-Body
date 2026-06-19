<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas del panel/CRM (administración). Identidades DEDICADAS, separadas de
 * `users` (clientes del gimnasio) y de `trainers` (portal profesional). El login
 * es por email + contraseña (`Hash`), a diferencia del resto del backend que usa
 * OTP/rostro. `role` almacena el rol de display del CRM ('Super Admin',
 * 'Administrador', ...) para que el front lo mapee directo a sus permisos.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('Administrador');
            $table->string('status')->default('active'); // active | disabled
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
