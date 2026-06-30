<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de operación del Inbox CRM (Fase 2A). Todo aditivo y nullable: no toca
 * el flujo de IA, pagos ni membresías. `last_message_at`, `human_takeover`,
 * `human_takeover_source` y `ai_enabled` YA existen y no se duplican aquí.
 *
 * - asignación a nivel conversación (distinta del string legado lead.assigned_to)
 * - lectura / no leídos para la bandeja
 * - timestamps de operación (in/out, primera respuesta) para métricas
 * - estado denormalizado de staff_review para filtros rápidos
 * - rastro del takeover manual (quién/cuándo)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_conversations', 'assigned_to_admin_id')) {
                $table->unsignedBigInteger('assigned_to_admin_id')->nullable()->after('lead_id');
                $table->timestamp('assigned_at')->nullable()->after('assigned_to_admin_id');
                $table->unsignedBigInteger('assigned_by')->nullable()->after('assigned_at');
            }
            if (! Schema::hasColumn('marketing_conversations', 'unread_count')) {
                $table->unsignedInteger('unread_count')->default(0)->after('last_message_at');
                $table->timestamp('last_read_at')->nullable()->after('unread_count');
            }
            if (! Schema::hasColumn('marketing_conversations', 'last_inbound_at')) {
                $table->timestamp('last_inbound_at')->nullable()->after('last_read_at');
                $table->timestamp('last_outbound_at')->nullable()->after('last_inbound_at');
                $table->timestamp('first_response_at')->nullable()->after('last_outbound_at');
            }
            if (! Schema::hasColumn('marketing_conversations', 'staff_review_pending')) {
                $table->boolean('staff_review_pending')->default(false)->after('ai_enabled');
                $table->string('staff_review_reason')->nullable()->after('staff_review_pending');
                $table->timestamp('staff_review_resolved_at')->nullable()->after('staff_review_reason');
                $table->unsignedBigInteger('staff_review_resolved_by')->nullable()->after('staff_review_resolved_at');
            }
            if (! Schema::hasColumn('marketing_conversations', 'manual_takeover_at')) {
                $table->timestamp('manual_takeover_at')->nullable()->after('human_takeover_source');
                $table->unsignedBigInteger('manual_takeover_by')->nullable()->after('manual_takeover_at');
            }
            if (! Schema::hasColumn('marketing_conversations', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('status');
                $table->timestamp('snooze_until')->nullable()->after('closed_at');
            }
        });

        Schema::table('marketing_conversations', function (Blueprint $table): void {
            $table->index(['assigned_to_admin_id', 'status']);
            $table->index('staff_review_pending');
            $table->index(['status', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::table('marketing_conversations', function (Blueprint $table): void {
            $table->dropIndex(['assigned_to_admin_id', 'status']);
            $table->dropIndex(['staff_review_pending']);
            $table->dropIndex(['status', 'last_message_at']);
            $table->dropColumn([
                'assigned_to_admin_id', 'assigned_at', 'assigned_by',
                'unread_count', 'last_read_at',
                'last_inbound_at', 'last_outbound_at', 'first_response_at',
                'staff_review_pending', 'staff_review_reason',
                'staff_review_resolved_at', 'staff_review_resolved_by',
                'manual_takeover_at', 'manual_takeover_by',
                'closed_at', 'snooze_until',
            ]);
        });
    }
};
