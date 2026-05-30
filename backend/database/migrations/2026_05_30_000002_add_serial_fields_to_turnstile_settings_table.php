<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnstile_settings', function (Blueprint $table) {
            // Modo "serial" — replica el flujo de NetGymValidator: cadena ASCII
            // (ej. "PULSE 3000\r\n") vía COM (USB-CH340 → RS485 → placa SATT).
            $table->string('serial_port', 20)->nullable()->after('device_comm_key');
            $table->unsignedInteger('serial_baud')->default(9600)->after('serial_port');
            $table->string('serial_command', 120)->default("PULSE 3000")->after('serial_baud');
        });
    }

    public function down(): void
    {
        Schema::table('turnstile_settings', function (Blueprint $table) {
            $table->dropColumn(['serial_port', 'serial_baud', 'serial_command']);
        });
    }
};
