<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets de soporte enviados desde la app por los miembros. Incluye el mensaje
 * del usuario y el contexto técnico automático (plataforma, dispositivo,
 * pantalla y errores recientes) para que el CRM tenga control de lo que pasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('document')->nullable();
            $table->string('type', 40)->default('other');     // error|payment|classes|routine|other
            $table->text('message');
            $table->string('status', 20)->default('new');      // new|in_progress|resolved
            $table->string('app_version', 40)->nullable();
            $table->string('platform', 40)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->string('screen', 120)->nullable();
            $table->json('recent_errors')->nullable();
            $table->json('metadata')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_support_tickets');
    }
};
