<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_transactions', 'member_id')) {
                $table->foreignId('member_id')->nullable()->after('order_id')->constrained('members')->nullOnDelete();
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'member_id')) {
                $table->foreignId('member_id')->nullable()->after('user_id')->constrained('members')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('payment_transactions', 'member_id')) {
                $table->dropColumn('member_id');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'member_id')) {
                $table->dropColumn('member_id');
            }
        });
    }
};
