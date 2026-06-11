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
            // Programa multi-día: cada elemento es un día de entrenamiento con su
            // propio título/enfoque y lista de ejercicios. Si es null, la rutina
            // es de un solo día (lista plana en `exercises`, compatibilidad).
            if (! Schema::hasColumn('routines', 'days')) {
                $table->json('days')->nullable()->after('exercises');
            }
            // Género objetivo de la plantilla: 'Mujer' | 'Hombre' | null (mixto).
            if (! Schema::hasColumn('routines', 'gender')) {
                $table->string('gender', 20)->nullable()->after('level');
            }
            // Plantilla pública: visible en el catálogo "Explorar rutinas" de la
            // app para que cualquier miembro la adopte (no está asignada a nadie).
            if (! Schema::hasColumn('routines', 'is_template')) {
                $table->boolean('is_template')->default(false)->after('created_by_admin');
                $table->index('is_template');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('routines')) {
            return;
        }

        Schema::table('routines', function (Blueprint $table) {
            if (Schema::hasColumn('routines', 'is_template')) {
                $table->dropIndex(['is_template']);
                $table->dropColumn('is_template');
            }
            foreach (['days', 'gender'] as $col) {
                if (Schema::hasColumn('routines', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
