<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_legal_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->string('contract_version', 80)->nullable();
            $table->boolean('terms_and_conditions')->default(false);
            $table->boolean('data_processing')->default(false);
            $table->boolean('truthfulness')->default(false);
            $table->boolean('service_contract')->default(false);
            $table->boolean('physical_risk_waiver')->default(false);
            $table->boolean('guardian_authorization')->default(false);
            $table->timestamps();

            $table->unique('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_legal_consents');
    }
};
