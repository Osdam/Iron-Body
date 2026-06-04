<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story Live / transmisiones en vivo (Bloque 5). Solo staff puede crear; los
 * miembros entran a mirar. El proveedor de video (LiveKit) acuña tokens
 * server-side; aquí solo vive la sesión y su estado.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('live_streams', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('host_member_id')->nullable();
            $table->string('status', 20)->default('scheduled'); // scheduled|live|ended|failed
            $table->string('provider', 40)->default('livekit');
            $table->string('provider_room_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['host_member_id']);
        });

        // Identifica al staff/admin del gimnasio DENTRO de la app (solo ellos
        // pueden crear/transmitir). Por defecto nadie es staff.
        Schema::table('members', function (Blueprint $table) {
            if (! Schema::hasColumn('members', 'is_staff')) {
                $table->boolean('is_staff')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_streams');
        Schema::table('members', function (Blueprint $table) {
            if (Schema::hasColumn('members', 'is_staff')) {
                $table->dropColumn('is_staff');
            }
        });
    }
};
