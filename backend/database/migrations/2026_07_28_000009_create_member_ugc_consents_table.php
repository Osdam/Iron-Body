<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aceptación VERSIONADA de los Lineamientos de Comunidad (UGC).
 *
 * Es una aceptación SEPARADA del contrato de membresía (`member_contracts`) y
 * de los consentimientos legales de registro (`member_legal_consents`): quien
 * solo usa rutinas, nutrición o clases NO necesita aceptar nada nuevo. La
 * aceptación se exige únicamente antes de PUBLICAR una Story.
 *
 * Versionada: cuando cambien los lineamientos se sube
 * `config('ugc.guidelines_version')` y se vuelve a pedir la aceptación, sin
 * perder la traza de la versión anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_ugc_consents')) {
            return;
        }

        Schema::create('member_ugc_consents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('member_id');
            $table->string('community_guidelines_version', 24);
            $table->timestamp('accepted_at');

            // Contexto mínimo de la aceptación (plataforma + versión de app).
            // Nada de IP en claro ni identificadores de dispositivo.
            $table->string('platform', 24)->nullable();
            $table->string('app_version', 24)->nullable();

            $table->timestamps();

            $table->unique(
                ['member_id', 'community_guidelines_version'],
                'member_ugc_consents_unique'
            );
            $table->index('member_id', 'member_ugc_consents_member_idx');
        });

        try {
            Schema::table('member_ugc_consents', function (Blueprint $table) {
                $table->foreign('member_id', 'member_ugc_consents_member_fk')
                    ->references('id')->on('members')->cascadeOnDelete();
            });
        } catch (Throwable $e) {
            // Integridad garantizada por el servicio.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_ugc_consents');
    }
};
