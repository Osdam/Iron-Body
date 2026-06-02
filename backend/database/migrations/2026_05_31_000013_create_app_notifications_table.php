<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centro de notificaciones proactivas del coach IRON IA (por miembro).
 *
 * Distinta del sistema `notifications` (CRM/general): estas las generan los
 * eventos de automatización para acompañar al usuario hacia su objetivo. Llevan
 * `action_route` para que la app abra la pantalla correcta al tocarlas.
 *
 * NUNCA guarda datos sensibles (el servicio sanea el payload).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->string('action_type')->nullable();   // route
            $table->string('action_route')->nullable();  // /iron-ai?focus=nutrition
            $table->jsonb('payload_json')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('delivered_at')->nullable(); // push entregado
            $table->string('source')->nullable();         // automation | coach | system
            $table->string('priority')->nullable()->default('normal'); // low|normal|high
            $table->timestamps();

            $table->index(['member_id', 'read_at']);
            $table->index(['member_id', 'type', 'created_at']);
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
