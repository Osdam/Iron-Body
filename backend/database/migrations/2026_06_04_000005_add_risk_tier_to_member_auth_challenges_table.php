<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nivel de riesgo decidido por el login adaptativo (Bloque 3b): local | otp |
 * otp_face. Null = login clásico (flag SECURITY_ADAPTIVE_LOGIN apagado) →
 * comportamiento idéntico al actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_auth_challenges', function (Blueprint $table): void {
            if (! Schema::hasColumn('member_auth_challenges', 'risk_tier')) {
                $table->string('risk_tier', 20)->nullable()->after('purpose');
            }
        });
    }

    public function down(): void
    {
        Schema::table('member_auth_challenges', function (Blueprint $table): void {
            if (Schema::hasColumn('member_auth_challenges', 'risk_tier')) {
                $table->dropColumn('risk_tier');
            }
        });
    }
};
