<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de notificación por socio.
 *
 * Aditiva: no toca ninguna tabla existente. Sin fila, el socio recibe los
 * valores por defecto de {@see App\Models\MemberNotificationPreference}, así que
 * el sistema funciona igual para los miles de socios que ya existen sin tener
 * que rellenarles nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_notification_preferences')) {
            return;
        }

        Schema::create('member_notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id')->unique();

            // La app corre en UTC; las horas de silencio solo significan algo en
            // la hora local de quien duerme.
            $table->string('timezone', 64)->default('America/Bogota');
            $table->boolean('quiet_hours_enabled')->default(true);
            $table->unsignedTinyInteger('quiet_hours_start')->default(21);
            $table->unsignedTinyInteger('quiet_hours_end')->default(7);

            // {categoria: bool}. Lo ausente se considera activado, salvo las
            // categorías que nacen apagadas (ver el modelo).
            $table->json('categories')->nullable();
            $table->json('supplement_kinds')->nullable();

            $table->unsignedSmallInteger('max_per_day')->default(4);
            $table->unsignedSmallInteger('max_wellness_per_week')->default(3);

            // Interruptor general: apaga TODO lo opcional de una vez.
            $table->timestamp('opted_out_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notification_preferences');
    }
};
