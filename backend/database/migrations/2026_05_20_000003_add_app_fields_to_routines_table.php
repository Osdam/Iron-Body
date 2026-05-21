<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('routines')) {
            return;
        }
        Schema::table('routines', function (Blueprint $table) {
            if (! Schema::hasColumn('routines', 'muscle_group')) {
                $table->string('muscle_group')->nullable()->after('level');
            }
            if (! Schema::hasColumn('routines', 'estimated_minutes')) {
                $table->unsignedSmallInteger('estimated_minutes')->default(0)->after('muscle_group');
            }
            if (! Schema::hasColumn('routines', 'is_assigned')) {
                $table->boolean('is_assigned')->default(false)->after('estimated_minutes');
            }
            if (! Schema::hasColumn('routines', 'member_id')) {
                $table->unsignedBigInteger('member_id')->nullable()->after('is_assigned');
                $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
            }
            if (! Schema::hasColumn('routines', 'created_by_admin')) {
                $table->boolean('created_by_admin')->default(true)->after('member_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('routines')) {
            return;
        }
        Schema::table('routines', function (Blueprint $table) {
            if (Schema::hasColumn('routines', 'member_id')) {
                $table->dropForeign(['member_id']);
                $table->dropColumn('member_id');
            }
            foreach (['muscle_group', 'estimated_minutes', 'is_assigned', 'created_by_admin'] as $col) {
                if (Schema::hasColumn('routines', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
