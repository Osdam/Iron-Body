<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apelaciones del miembro sancionado.
 *
 * Regla dura: UNA apelación abierta por acción de moderación. El índice único
 * parcial no es portable, así que la unicidad se garantiza en el servicio
 * dentro de una transacción con lock; el índice de abajo la hace barata.
 *
 * El texto de la apelación es del usuario: se sanea y se acota. Las notas de
 * resolución son internas y NUNCA se devuelven a la app; al usuario se le
 * entrega solo el estado y, si el admin lo marca, un mensaje público.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('moderation_appeals')) {
            return;
        }

        Schema::create('moderation_appeals', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            $table->unsignedBigInteger('moderation_action_id');
            $table->unsignedBigInteger('member_id');

            $table->text('appeal_text');

            // submitted | under_review | upheld | granted | rejected
            $table->string('status', 24)->default('submitted');

            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            // Interno — no viaja a la app.
            $table->text('resolution_notes')->nullable();
            // Mensaje público opcional para el miembro.
            $table->string('public_resolution', 300)->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['moderation_action_id', 'status'], 'moderation_appeals_action_idx');
            $table->index(['member_id', 'status'], 'moderation_appeals_member_idx');
            $table->index('status', 'moderation_appeals_status_idx');
        });

        try {
            Schema::table('moderation_appeals', function (Blueprint $table) {
                $table->foreign('moderation_action_id', 'moderation_appeals_action_fk')
                    ->references('id')->on('moderation_actions')->cascadeOnDelete();
                $table->foreign('member_id', 'moderation_appeals_member_fk')
                    ->references('id')->on('members')->cascadeOnDelete();
                $table->foreign('reviewed_by_admin_id', 'moderation_appeals_admin_fk')
                    ->references('id')->on('admins')->nullOnDelete();
            });
        } catch (Throwable $e) {
            // Integridad garantizada por el servicio de apelaciones.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_appeals');
    }
};
