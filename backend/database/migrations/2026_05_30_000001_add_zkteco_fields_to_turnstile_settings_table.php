<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turnstile_settings', function (Blueprint $table) {
            // 'webhook' (ESP32, Sonoff, Shelly...) o 'zkteco' (SDK standalone TCP).
            $table->string('mode', 20)->default('webhook')->after('enabled');
            $table->string('device_host', 120)->nullable()->after('mode');
            $table->unsignedSmallInteger('device_port')->default(4370)->after('device_host');
            $table->string('device_comm_key', 60)->nullable()->after('device_port');
        });
    }

    public function down(): void
    {
        Schema::table('turnstile_settings', function (Blueprint $table) {
            $table->dropColumn(['mode', 'device_host', 'device_port', 'device_comm_key']);
        });
    }
};
