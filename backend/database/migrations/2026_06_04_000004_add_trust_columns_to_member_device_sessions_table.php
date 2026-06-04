<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas de confianza del dispositivo (Fase 2/3). Se introducen ahora para que
 * el modelo de datos esté listo; el LOGIN sigue igual (OTP + cara siempre) hasta
 * que se active el login adaptativo (Bloque 3b). `trusted_at` ya existía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_device_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('member_device_sessions', 'is_primary')) {
                $table->boolean('is_primary')->default(false)->after('platform');
            }
            if (! Schema::hasColumn('member_device_sessions', 'is_trusted')) {
                $table->boolean('is_trusted')->default(false)->after('is_primary');
            }
            if (! Schema::hasColumn('member_device_sessions', 'risk_score')) {
                $table->integer('risk_score')->default(0)->after('is_trusted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('member_device_sessions', function (Blueprint $table): void {
            foreach (['is_primary', 'is_trusted', 'risk_score'] as $col) {
                if (Schema::hasColumn('member_device_sessions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
