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
            if (! Schema::hasColumn('exercises', 'muscle_group')) {
                $table->string('muscle_group')->nullable()->after('body_part');
            }
            if (! Schema::hasColumn('exercises', 'difficulty')) {
                $table->string('difficulty')->nullable()->after('muscle_group');
            }
            if (! Schema::hasColumn('exercises', 'description')) {
                $table->text('description')->nullable()->after('difficulty');
            }
            if (! Schema::hasColumn('exercises', 'steps')) {
                $table->json('steps')->nullable()->after('description');
            }
            if (! Schema::hasColumn('exercises', 'tips')) {
                $table->json('tips')->nullable()->after('steps');
            }
            if (! Schema::hasColumn('exercises', 'muscles_worked')) {
                $table->json('muscles_worked')->nullable()->after('tips');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('exercises')) {
            return;
        }
        Schema::table('exercises', function (Blueprint $table) {
            foreach (['muscle_group', 'difficulty', 'description', 'steps', 'tips', 'muscles_worked'] as $col) {
                if (Schema::hasColumn('exercises', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
