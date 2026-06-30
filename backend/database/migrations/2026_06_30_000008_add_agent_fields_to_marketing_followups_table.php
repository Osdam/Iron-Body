<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capa de seguimiento comercial (Fase 4C): da a marketing_followups los campos
 * que la acción create_follow_up necesita. Aditivo y nullable: no rompe los
 * followups automáticos del orquestador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_followups', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_followups', 'assigned_to_admin_id')) {
                $table->unsignedBigInteger('assigned_to_admin_id')->nullable()->after('lead_id');
            }
            if (! Schema::hasColumn('marketing_followups', 'marketing_conversation_id')) {
                $table->unsignedBigInteger('marketing_conversation_id')->nullable()->after('assigned_to_admin_id');
            }
            if (! Schema::hasColumn('marketing_followups', 'reason')) {
                $table->text('reason')->nullable()->after('message_template');
            }
            $table->index('assigned_to_admin_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_followups', function (Blueprint $table): void {
            $table->dropIndex(['assigned_to_admin_id']);
            $table->dropColumn(['assigned_to_admin_id', 'marketing_conversation_id', 'reason']);
        });
    }
};
