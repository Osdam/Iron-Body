<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriquece member_device_tokens (ya existía) con metadata para FCM v1:
 * nombre de dispositivo, versión de app, permiso de notificaciones, estado
 * activo y last_seen_at. Aditiva e idempotente (hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_device_tokens', function (Blueprint $table): void {
            if (!Schema::hasColumn('member_device_tokens', 'device_name')) {
                $table->string('device_name')->nullable()->after('platform');
            }
            if (!Schema::hasColumn('member_device_tokens', 'app_version')) {
                $table->string('app_version')->nullable()->after('device_name');
            }
            if (!Schema::hasColumn('member_device_tokens', 'notification_permission')) {
                $table->string('notification_permission')->nullable()->after('app_version');
            }
            if (!Schema::hasColumn('member_device_tokens', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('notification_permission');
            }
            if (!Schema::hasColumn('member_device_tokens', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('last_used_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('member_device_tokens', function (Blueprint $table): void {
            foreach (['device_name', 'app_version', 'notification_permission', 'is_active', 'last_seen_at'] as $col) {
                if (Schema::hasColumn('member_device_tokens', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
