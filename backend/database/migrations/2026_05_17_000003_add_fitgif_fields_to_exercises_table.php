<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos extra para soportar proveedores tipo FitGIF / Free Exercise DB:
 *  - thumbnail_url: 2.º frame del movimiento (o miniatura) para animación.
 *  - source: licencia/origen del recurso (transparencia).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exercises')) {
            return;
        }
        Schema::table('exercises', function (Blueprint $table) {
            if (! Schema::hasColumn('exercises', 'thumbnail_url')) {
                $table->text('thumbnail_url')->nullable()->after('gif_url');
            }
            if (! Schema::hasColumn('exercises', 'source')) {
                $table->string('source')->nullable()->after('provider');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('exercises')) {
            return;
        }
        Schema::table('exercises', function (Blueprint $table) {
            foreach (['thumbnail_url', 'source'] as $col) {
                if (Schema::hasColumn('exercises', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
