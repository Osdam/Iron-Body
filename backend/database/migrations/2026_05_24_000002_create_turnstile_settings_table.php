<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnstile_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->default('Torniquete principal');
            $table->boolean('enabled')->default(false);

            // Endpoint del relé (ESP32, Sonoff, Shelly, controlador comercial).
            $table->string('webhook_url', 500)->nullable();
            $table->string('http_method', 10)->default('POST');
            $table->string('auth_header', 500)->nullable();
            $table->text('request_payload')->nullable();

            // Comportamiento.
            $table->unsignedInteger('open_duration_ms')->default(3000);
            $table->boolean('fire_on_entry')->default(true);
            $table->boolean('fire_on_exit')->default(false);
            $table->boolean('sound_enabled')->default(true);

            // Estado de la última activación.
            $table->timestamp('last_triggered_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('last_http_code')->nullable();

            $table->timestamps();
        });

        // Singleton — solo una fila.
        DB::table('turnstile_settings')->insert([
            'name' => 'Torniquete principal',
            'enabled' => false,
            'http_method' => 'POST',
            'open_duration_ms' => 3000,
            'fire_on_entry' => true,
            'fire_on_exit' => false,
            'sound_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('turnstile_settings');
    }
};
