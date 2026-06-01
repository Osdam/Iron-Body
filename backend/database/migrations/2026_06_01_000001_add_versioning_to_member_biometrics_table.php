<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versionado biométrico cross-platform (aditivo, no destructivo).
 *
 * Añade metadata a la referencia facial para distinguir plantillas creadas con
 * normalizadores distintos (Android viejo vs `face_norm_v1`). Las referencias
 * existentes quedan con `normalizer_version` NULL → se tratan como legacy sin
 * borrarlas ni bloquear a nadie. El estado por defecto es `active` para no
 * alterar el comportamiento actual de quienes ya verifican bien.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('member_biometrics', function (Blueprint $table): void {
            $table->string('biometric_template_version')->nullable()->after('bytes_length');
            $table->string('normalizer_version')->nullable()->after('biometric_template_version');
            $table->string('enrolled_platform')->nullable()->after('normalizer_version');
            $table->string('enrolled_device_type')->nullable()->after('enrolled_platform');
            // active | legacy | re_enrollment_required | disabled
            $table->string('biometric_reference_status')->default('active')->after('enrolled_device_type');
            $table->string('biometric_legacy_reason')->nullable()->after('biometric_reference_status');
            $table->timestamp('last_biometric_enrolled_at')->nullable()->after('biometric_legacy_reason');
            $table->timestamp('last_biometric_verified_at')->nullable()->after('last_biometric_enrolled_at');

            $table->index('biometric_reference_status');
        });
    }

    public function down(): void
    {
        Schema::table('member_biometrics', function (Blueprint $table): void {
            $table->dropIndex(['biometric_reference_status']);
            $table->dropColumn([
                'biometric_template_version',
                'normalizer_version',
                'enrolled_platform',
                'enrolled_device_type',
                'biometric_reference_status',
                'biometric_legacy_reason',
                'last_biometric_enrolled_at',
                'last_biometric_verified_at',
            ]);
        });
    }
};
