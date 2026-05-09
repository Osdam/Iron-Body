<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'plan')) {
                $table->string('plan', 100)->nullable()->after('status');
            }
            if (!Schema::hasColumn('users', 'membership_start_date')) {
                $table->date('membership_start_date')->nullable()->after('plan');
            }
            if (!Schema::hasColumn('users', 'membership_end_date')) {
                $table->date('membership_end_date')->nullable()->after('membership_start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['membership_end_date', 'membership_start_date', 'plan'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
