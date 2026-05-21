<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table): void {
            if (! Schema::hasColumn('classes', 'date_time')) {
                $table->dateTime('date_time')->nullable()->after('id');
            }
            if (! Schema::hasColumn('classes', 'instructor')) {
                $table->string('instructor')->nullable()->after('trainer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table): void {
            $table->dropColumn(['date_time', 'instructor']);
        });
    }
};
