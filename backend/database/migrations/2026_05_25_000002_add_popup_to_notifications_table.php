<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte de "push interno" (popup in-app) sobre la tabla notifications.
 *
 * `should_popup`: la app debe mostrar esta notificación como cápsula premium.
 * `popup_shown_at`: una vez mostrada, se sella para no repetirla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->boolean('should_popup')->default(false)->index();
            $table->timestamp('popup_shown_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn(['should_popup', 'popup_shown_at']);
        });
    }
};
