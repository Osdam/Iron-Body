<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('account_deletion_requests')) {
            return;
        }

        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            // No FK con cascade: la solicitud debe sobrevivir como evidencia de
            // auditoría aunque el miembro se anonimice/borre después.
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // pending | processing | completed | rejected
            $table->string('status', 30)->default('pending')->index();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            // Qué se eliminó/anonimizó, qué se conservó por obligación legal, etc.
            $table->json('metadata')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};
