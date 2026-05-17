<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache de match FitGif: evita llamar a FitGif (3 req/min) en cada flip.
 *  - local_name:     nombre del ejercicio de la rutina (ES) que originó el match.
 *  - matched_query:  candidato EN que devolvió GIF.
 *  - gif_path:       GIF descargado y guardado (la signed URL caduca en 60 s).
 *  - last_synced_at: cuándo se resolvió (para revalidar si vence).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exercises')) {
            return;
        }
        Schema::table('exercises', function (Blueprint $table) {
            if (! Schema::hasColumn('exercises', 'local_name')) {
                $table->string('local_name')->nullable()->index()->after('name');
            }
            if (! Schema::hasColumn('exercises', 'matched_query')) {
                $table->string('matched_query')->nullable()->after('local_name');
            }
            if (! Schema::hasColumn('exercises', 'gif_path')) {
                $table->string('gif_path')->nullable()->after('gif_url');
            }
            if (! Schema::hasColumn('exercises', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('exercises')) {
            return;
        }
        Schema::table('exercises', function (Blueprint $table) {
            foreach (['local_name', 'matched_query', 'gif_path', 'last_synced_at'] as $c) {
                if (Schema::hasColumn('exercises', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
