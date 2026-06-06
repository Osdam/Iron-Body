<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routine_exercises', function (Blueprint $table) {
            $table->string('reps', 20)->default('10')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Postgres no castea varchar -> smallint automáticamente: hay que
            // sanear los valores no numéricos y especificar el USING explícito.
            DB::statement("UPDATE routine_exercises SET reps = '10' WHERE reps !~ '^[0-9]+$' OR reps IS NULL");
            DB::statement('ALTER TABLE routine_exercises ALTER COLUMN reps TYPE SMALLINT USING reps::smallint');
            DB::statement('ALTER TABLE routine_exercises ALTER COLUMN reps SET DEFAULT 10');

            return;
        }

        Schema::table('routine_exercises', function (Blueprint $table) {
            $table->unsignedTinyInteger('reps')->default(10)->change();
        });
    }
};
