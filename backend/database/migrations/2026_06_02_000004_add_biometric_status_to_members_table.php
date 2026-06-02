<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            // Estado de la inscripción biométrica facial (Apple: la biometría es
            // OPCIONAL al crear cuenta). Valores: pending | registered | skipped
            // | manual_required. No guarda datos biométricos: solo el estado.
            $table->string('biometric_status', 30)->default('pending')->after('is_minor');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('biometric_status');
        });
    }
};
