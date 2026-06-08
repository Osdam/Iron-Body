<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base comunitaria: los alimentos creados por usuarios pueden retroalimentar la
 * base para otros, con control de calidad (visibilidad, estado de verificación,
 * confirmaciones, reportes y fusión de duplicados). Todo con defaults seguros
 * → no rompe datos ni migraciones existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nutrition_foods', function (Blueprint $table) {
            // private (solo creador) | community (aporta a la base) | verified (staff)
            $table->string('visibility', 20)->default('private')->after('is_public')->index();
            // private | pending | community | verified | rejected
            $table->string('verification_status', 20)->default('private')->after('visibility')->index();
            $table->foreignId('verified_by_admin_id')->nullable()->after('verification_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by_admin_id');
            $table->unsignedInteger('community_confirmations_count')->default(0)->after('verified_at');
            $table->unsignedInteger('community_votes_count')->default(0)->after('community_confirmations_count');
            $table->unsignedInteger('reports_count')->default(0)->after('community_votes_count');
            // Duplicado fusionado → apunta al alimento canónico.
            $table->foreignId('canonical_food_id')->nullable()->after('reports_count')
                ->constrained('nutrition_foods')->nullOnDelete();
            $table->unsignedInteger('version')->default(1)->after('canonical_food_id');
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_foods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by_admin_id');
            $table->dropConstrainedForeignId('canonical_food_id');
            $table->dropColumn([
                'visibility', 'verification_status', 'verified_at',
                'community_confirmations_count', 'community_votes_count',
                'reports_count', 'version',
            ]);
        });
    }
};
