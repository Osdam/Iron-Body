<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exercises')) {
            return;
        }
        Schema::table('exercises', function (Blueprint $table) {
            if (! Schema::hasColumn('exercises', 'common_mistakes')) {
                $table->json('common_mistakes')->nullable()->after('steps');
            }
            if (! Schema::hasColumn('exercises', 'secondary_muscles')) {
                $table->json('secondary_muscles')->nullable()->after('common_mistakes');
            }
            if (! Schema::hasColumn('exercises', 'suggested_sets')) {
                $table->unsignedTinyInteger('suggested_sets')->default(3)->after('secondary_muscles');
            }
            if (! Schema::hasColumn('exercises', 'suggested_reps')) {
                $table->string('suggested_reps', 20)->default('8-12')->after('suggested_sets');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('exercises')) {
            return;
        }
        Schema::table('exercises', function (Blueprint $table) {
            foreach (['common_mistakes', 'secondary_muscles', 'suggested_sets', 'suggested_reps'] as $col) {
                if (Schema::hasColumn('exercises', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
