<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'document')) {
                $table->string('document', 50)->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable()->after('document');
            }
            if (!Schema::hasColumn('users', 'status')) {
                $table->string('status', 20)->default('active')->after('phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['status', 'phone', 'document'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
