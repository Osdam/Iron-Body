<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración a Firebase Storage para stories (como los reels).
 *
 * Diseño de almacenamiento dual — sin romper stories existentes:
 * - Stories antiguas: `disk='public'`, `file_path`=ruta relativa al disco
 *   Laravel; la URL pública se resuelve con `Storage::disk('public')->url()`.
 * - Stories nuevas: `disk='firebase'`, `file_path`=ruta del objeto en el
 *   bucket (`stories/{uid}/{uuid}.ext`) — la usa el backend para BORRAR el
 *   objeto físicamente vía service account; `download_url`=URL https
 *   tokenizada que devuelve Firebase, usada por la app para mostrar/compartir
 *   sin tener que re-resolver el `gs://` en cada carga.
 *
 * `download_url` es nullable: las filas antiguas la dejan en NULL y siguen
 * sirviéndose por el disco público. Idempotente vía `hasColumn`.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('stories', 'download_url')) {
            Schema::table('stories', function (Blueprint $table) {
                // 1000 para tolerar URLs largas de Firebase con token.
                $table->string('download_url', 1000)->nullable()->after('file_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stories', 'download_url')) {
            Schema::table('stories', function (Blueprint $table) {
                $table->dropColumn('download_url');
            });
        }
    }
};
