<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de autor en mensajes salientes humanos (Inbox CRM, Fase 2A).
 * Aditivo y nullable: los mensajes de la IA (sender_type='ai') lo dejan en null.
 * Guarda qué admin/asesor envió un mensaje manual (sender_type='human').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_messages', 'sender_user_id')) {
                $table->unsignedBigInteger('sender_user_id')->nullable()->after('sender_type');
                $table->index('sender_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_messages', function (Blueprint $table): void {
            $table->dropIndex(['sender_user_id']);
            $table->dropColumn('sender_user_id');
        });
    }
};
