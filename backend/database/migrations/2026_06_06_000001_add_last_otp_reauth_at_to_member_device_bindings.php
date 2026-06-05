<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revalidación periódica del login adaptativo: marca de la última vez que ESTE
 * dispositivo confiable verificó por OTP (SMS). Si supera `trusted_reauth_days`
 * (o es null), el login vuelve a exigir un OTP una vez antes de permitir el
 * desbloqueo local de nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_device_bindings', function (Blueprint $table): void {
            $table->timestamp('last_otp_reauth_at')->nullable()->after('bound_at');
        });
    }

    public function down(): void
    {
        Schema::table('member_device_bindings', function (Blueprint $table): void {
            $table->dropColumn('last_otp_reauth_at');
        });
    }
};
