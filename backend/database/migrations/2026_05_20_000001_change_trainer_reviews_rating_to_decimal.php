<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return; // SQLite usa type affinity; valores decimales ya funcionan
        }

        Schema::table('trainer_reviews', function (Blueprint $table) {
            $table->decimal('rating', 3, 1)->default(1.0)->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('trainer_reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->default(1)->change();
        });
    }
};
