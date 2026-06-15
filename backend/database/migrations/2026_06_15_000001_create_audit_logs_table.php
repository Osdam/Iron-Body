<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de auditoría GENERAL del CRM (append-only): quién hizo qué, sobre qué
 * entidad y qué campos cambiaron. Reemplaza el almacenamiento en `localStorage`
 * del navegador por una fuente persistente, multi-usuario e inmutable.
 *
 * Solo `created_at` (no se edita ni se borra evidencia). El actor lo reporta el
 * CRM (su sesión es de cliente); por eso `actor_*` son auto-reportados y NUNCA
 * se usan como autorización, solo como traza. No guardar aquí secretos, tokens
 * ni datos sensibles de tarjeta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            // Qué pasó. create | update | delete | status | assign | settings.
            $table->string('action', 20)->index();
            $table->string('module', 60)->index();
            $table->string('entity', 60);
            $table->string('entity_id', 64)->nullable();
            $table->string('target_name', 191)->nullable();

            // Quién (auto-reportado por el CRM; nunca para autorizar).
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_name', 120)->default('Sistema')->index();
            $table->string('actor_role', 60)->nullable();

            // Detalle del evento.
            $table->text('summary')->nullable();
            $table->json('changes')->nullable();   // [{ field, before, after }]
            $table->json('metadata')->nullable();

            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
