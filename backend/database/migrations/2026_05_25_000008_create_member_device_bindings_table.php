<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo físico dispositivo ↔ miembro titular. Un dispositivo queda "asociado"
 * al primer miembro que completa la verificación facial en él. Si OTRO documento
 * intenta ingresar desde ese mismo equipo, el acceso se deniega ("cuenta
 * asociada a otro usuario") hasta que un admin lo libere. Una fila por device_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_device_bindings', function (Blueprint $table): void {
            $table->id();
            $table->string('device_id')->unique();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('device_name')->nullable();
            $table->string('platform')->nullable();

            $table->timestamp('bound_at')->nullable();
            $table->timestamps();

            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_device_bindings');
    }
};
