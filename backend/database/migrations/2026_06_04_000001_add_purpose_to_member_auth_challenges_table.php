<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reutiliza el reto OTP del login para acciones sensibles autenticadas
 * (eliminar cuenta, desvincular dispositivos, cambio de número…). Un mismo
 * miembro puede tener un reto vivo por propósito; el login sigue usando
 * `purpose = 'login'` (valor por defecto = comportamiento idéntico al actual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_auth_challenges', function (Blueprint $table): void {
            if (! Schema::hasColumn('member_auth_challenges', 'purpose')) {
                $table->string('purpose', 40)->default('login')->after('member_id');
                $table->index(['member_id', 'purpose', 'status'], 'mac_member_purpose_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('member_auth_challenges', function (Blueprint $table): void {
            if (Schema::hasColumn('member_auth_challenges', 'purpose')) {
                $table->dropIndex('mac_member_purpose_status_idx');
                $table->dropColumn('purpose');
            }
        });
    }
};
