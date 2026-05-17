<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versión MP4 (H.264) optimizada del GIF de FitGif.
 *  - video_path:     MP4 generado por ffmpeg (el GIF original se conserva).
 *  - media_type:     'video' si hay MP4, si no 'gif'.
 *  - playback_speed: factor aplicado al transcodear (1.3 = 1.3x).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exercises')) {
            return;
        }
        Schema::table('exercises', function (Blueprint $table) {
            if (! Schema::hasColumn('exercises', 'video_path')) {
                $table->string('video_path')->nullable()->after('gif_path');
            }
            if (! Schema::hasColumn('exercises', 'media_type')) {
                $table->string('media_type')->default('gif')->after('video_path');
            }
            if (! Schema::hasColumn('exercises', 'playback_speed')) {
                $table->decimal('playback_speed', 3, 2)->nullable()->after('media_type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('exercises')) {
            return;
        }
        Schema::table('exercises', function (Blueprint $table) {
            foreach (['video_path', 'media_type', 'playback_speed'] as $c) {
                if (Schema::hasColumn('exercises', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
