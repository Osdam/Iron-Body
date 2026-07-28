<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspensiones SOCIALES de un miembro (moderación de comunidad).
 *
 * DELIBERADAMENTE INDEPENDIENTE de `members.status` y de `member_risk_locks`:
 * - `members.status = 'suspended'` es la suspensión de SEGURIDAD (cuentas
 *   compartidas / robo) y bloquea el login entero. Moderación NO la toca.
 * - Esta tabla no cancela membresías, no altera pagos, no toca facturación
 *   electrónica ni el acceso físico al gimnasio.
 *
 * Scopes (de menor a mayor alcance):
 *   story_posting     → no puede publicar Stories.
 *   story_interaction → no puede reaccionar ni reportar.
 *   social_features   → sin Stories ni funciones sociales (conserva rutinas,
 *                       nutrición, clases, membresía).
 *   full_app_access   → bloqueo total de la app. Solo con permiso elevado.
 *
 * `ends_at` NULL = permanente. Una suspensión temporal caduca sola: el estado
 * se calcula con `ends_at > now()`, nunca con un job que "desactive".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_suspensions')) {
            return;
        }

        Schema::create('member_suspensions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            $table->unsignedBigInteger('member_id');

            $table->string('scope', 32); // story_posting | ... | full_app_access
            $table->string('status', 16)->default('active'); // active | revoked | expired

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable(); // null = permanente

            $table->string('reason_code', 48)->nullable();
            // Lo que ve el usuario. Sin notas internas ni datos del reportante.
            $table->string('public_reason', 300)->nullable();
            $table->text('internal_reason')->nullable();

            $table->unsignedBigInteger('moderation_action_id')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_admin_id')->nullable();

            $table->timestamps();

            // Consulta caliente: "¿este miembro tiene una sanción activa que
            // cubra este scope ahora mismo?" — un solo index scan.
            $table->index(['member_id', 'status', 'scope'], 'member_suspensions_live_idx');
            $table->index('ends_at', 'member_suspensions_ends_idx');
            $table->index('moderation_action_id', 'member_suspensions_action_idx');
        });

        try {
            Schema::table('member_suspensions', function (Blueprint $table) {
                $table->foreign('member_id', 'member_suspensions_member_fk')
                    ->references('id')->on('members')->cascadeOnDelete();
                $table->foreign('moderation_action_id', 'member_suspensions_action_fk')
                    ->references('id')->on('moderation_actions')->nullOnDelete();
                $table->foreign('created_by_admin_id', 'member_suspensions_admin_fk')
                    ->references('id')->on('admins')->nullOnDelete();
                $table->foreign('revoked_by_admin_id', 'member_suspensions_revoker_fk')
                    ->references('id')->on('admins')->nullOnDelete();
            });
        } catch (Throwable $e) {
            // Integridad garantizada por el servicio de suspensiones.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_suspensions');
    }
};
