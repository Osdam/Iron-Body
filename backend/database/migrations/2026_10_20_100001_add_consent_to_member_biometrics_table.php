<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consentimiento biométrico del socio junto a su referencia facial (aditivo).
 *
 * El autorregistro desde Perfil exige que el socio acepte la autorización
 * versionada de config('members.biometric_consent'); aquí queda qué versión
 * aceptó y cuándo. Las referencias existentes (alta, recepción) quedan en
 * NULL: no se inventa un consentimiento que nadie registró.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('member_biometrics', function (Blueprint $table): void {
            $table->string('consent_version', 20)->nullable()->after('last_biometric_verified_at');
            $table->timestamp('consent_accepted_at')->nullable()->after('consent_version');
            $table->string('enrolled_via', 40)->nullable()->after('consent_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('member_biometrics', function (Blueprint $table): void {
            $table->dropColumn(['consent_version', 'consent_accepted_at', 'enrolled_via']);
        });
    }
};
