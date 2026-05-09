<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-point classes.trainer_id from users(id) → trainers(id).
 *
 * Implementation note (SQLite):
 *   SQLite has limited ALTER TABLE support for changing FKs in-place,
 *   so we drop and re-create the column. We first set NULL on existing rows.
 */
return new class extends Migration {
    public function up(): void
    {
        // Limpia referencias antes de cambiar la FK (apuntaban a users, no aplica más)
        DB::table('classes')->update(['trainer_id' => null]);

        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['trainer_id']);
            $table->dropColumn('trainer_id');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('trainer_id')
                ->nullable()
                ->after('type')
                ->constrained('trainers')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['trainer_id']);
            $table->dropColumn('trainer_id');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('trainer_id')
                ->nullable()
                ->after('type')
                ->constrained('users')
                ->onDelete('set null');
        });
    }
};
