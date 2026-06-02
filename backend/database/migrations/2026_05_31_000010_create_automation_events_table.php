<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cola de eventos de automatización Laravel → n8n.
 *
 * Laravel es la fuente de verdad: cada evento se persiste aquí ANTES de
 * intentar enviarlo a n8n (outbox pattern). El `idempotency_key` único evita
 * duplicados. n8n NUNCA accede a esta tabla ni a PostgreSQL: solo recibe el
 * webhook con un payload mínimo y saneado (sin datos sensibles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type');
            $table->unsignedBigInteger('member_id')->nullable();
            $table->jsonb('payload_json')->nullable();
            // pending | sent | failed | skipped
            $table->string('status')->default('pending');
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'status']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_events');
    }
};
