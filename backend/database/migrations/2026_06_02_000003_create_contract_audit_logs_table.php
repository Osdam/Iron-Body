<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_contract_id')->constrained()->cascadeOnDelete();
            // member | admin | system
            $table->string('actor_type', 20);
            $table->string('actor_id', 80)->nullable();
            // created | viewed | accepted | signed | pdf_generated | downloaded | voided
            $table->string('action', 40);
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['member_contract_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_audit_logs');
    }
};
