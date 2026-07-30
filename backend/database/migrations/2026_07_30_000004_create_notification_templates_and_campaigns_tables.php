<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas versionadas y campañas manuales del CRM.
 *
 * Las plantillas del catálogo (motivación, hidratación, suplementos) se siembran
 * desde código y se marcan `is_seeded`: el CRM puede activarlas o desactivarlas
 * y editar su texto, pero borrarlas no tiene sentido porque el sembrador las
 * repondría. Las campañas manuales nacen SIEMPRE como borrador y necesitan una
 * confirmación explícita para salir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_templates')) {
            Schema::create('notification_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 80)->unique();
                $table->string('category', 40);
                $table->string('supplement_kind', 40)->nullable();
                $table->string('title');
                $table->text('body');
                $table->string('action_route')->nullable();

                // Sube al editar: el historial de envíos guarda con qué versión
                // se mandó, así un texto corregido no reescribe el pasado.
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_seeded')->default(false);

                // Aviso educativo al pie (suplementos). Configurable, no fijo.
                $table->text('disclaimer')->nullable();

                $table->timestamps();

                $table->index(['category', 'is_active'], 'nt_cat_active_idx');
            });
        }

        if (! Schema::hasTable('notification_campaigns')) {
            Schema::create('notification_campaigns', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('category', 40);
                $table->string('title');
                $table->text('body');
                $table->string('action_route')->nullable();

                // draft | scheduled | sending | sent | cancelled
                $table->string('status', 20)->default('draft');
                $table->json('audience')->nullable();

                $table->timestamp('scheduled_for')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();

                $table->unsignedInteger('estimated_recipients')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('suppressed_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);

                // Quién la creó y quién la aprobó: nunca la misma acción.
                $table->string('created_by')->nullable();
                $table->string('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->string('cancelled_by')->nullable();
                $table->timestamp('cancelled_at')->nullable();

                $table->timestamps();

                $table->index(['status', 'scheduled_for'], 'nc_status_sched_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaigns');
        Schema::dropIfExists('notification_templates');
    }
};
