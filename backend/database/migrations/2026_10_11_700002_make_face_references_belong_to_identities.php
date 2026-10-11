<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La plantilla facial pasa a ser de la PERSONA, con ciclo de vida y cifrada.
 *
 * No se crea una segunda tabla biométrica: se amplía la que ya existe
 * (`trainer_face_references`, 4 filas en producción el 2026-10-10) para que una
 * misma persona —entrenador, recepcionista o las dos cosas— tenga UNA plantilla.
 *
 * QUÉ CAMBIA
 *  · `identity_id` (único): la dueña de la plantilla. Se rellena desde
 *    `trainers.identity_id` (los 11 entrenadores de producción tienen identidad).
 *  · `trainer_id` pasa a admitir NULL: una recepcionista no es entrenadora.
 *    Sigue siendo única: el código anterior la busca por entrenador.
 *  · `template`: el embedding CIFRADO con la APP_KEY. En este diseño el
 *    servidor compara lo que manda el teléfono, así que una plantilla en claro
 *    filtrada en un volcado es casi una credencial. La columna `embedding`
 *    (JSON en claro) se vacía y queda solo para poder deshacer.
 *  · Estado y trazabilidad: quién la registró, con qué consentimiento, desde
 *    qué dispositivo, cuándo se verificó por última vez y, si se revocó,
 *    quién, cuándo y por qué. Revocar BORRA la plantilla (template = NULL) y
 *    conserva la fila como evidencia.
 *
 * Reversible: `down()` vuelve a dejar el embedding en claro en `embedding` y
 * quita las columnas. Se niega si hay plantillas de personas sin perfil de
 * entrenador (no tendrían dónde volver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_face_references', function (Blueprint $table): void {
            $table->unsignedBigInteger('identity_id')->nullable()->after('trainer_id');
            $table->text('template')->nullable()->after('embedding');
            $table->string('status', 30)->default('active')->after('template');
            $table->string('template_version', 60)->nullable();
            $table->string('enrolled_via', 40)->nullable();
            $table->unsignedBigInteger('enrolled_device_id')->nullable();
            $table->unsignedBigInteger('enrolled_by_admin_id')->nullable();
            $table->string('enrolled_by_name', 120)->nullable();
            $table->string('consent_version', 20)->nullable();
            $table->timestamp('consent_accepted_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_admin_id')->nullable();
            $table->string('revoked_by_name', 120)->nullable();
            $table->string('revoked_reason', 500)->nullable();

            $table->unique('identity_id');
            $table->index('status');
        });

        Schema::table('trainer_face_references', function (Blueprint $table): void {
            $table->unsignedBigInteger('trainer_id')->nullable()->change();
            $table->json('embedding')->nullable()->change();
        });

        // Dos plantillas de la misma persona no caben en el único de identidad:
        // parar ANTES, con el motivo, en vez de a mitad del relleno.
        $repetidas = DB::table('trainer_face_references as f')
            ->join('trainers as t', 't.id', '=', 'f.trainer_id')
            ->whereNotNull('t.identity_id')
            ->select('t.identity_id')
            ->groupBy('t.identity_id')
            ->havingRaw('count(*) > 1')
            ->count();
        if ($repetidas > 0) {
            throw new RuntimeException("Hay {$repetidas} identidad(es) con más de una plantilla facial de entrenador. Revísalas antes de migrar.");
        }

        DB::table('trainer_face_references')->orderBy('id')->each(function (object $fila): void {
            $identidad = DB::table('trainers')->where('id', $fila->trainer_id)->value('identity_id');
            $vector = $fila->embedding !== null ? json_decode((string) $fila->embedding, true) : null;

            DB::table('trainer_face_references')->where('id', $fila->id)->update([
                'identity_id' => $identidad,
                'template' => is_array($vector) ? Crypt::encryptString(json_encode(array_map('floatval', $vector))) : null,
                'embedding' => null,
                'status' => is_array($vector) ? 'active' : 'revoked',
                'template_version' => 'mobilefacenet-192/legacy',
                'enrolled_via' => 'trainer_portal_legacy',
            ]);
        });
    }

    public function down(): void
    {
        // Las revocadas no tienen plantilla: se borran abajo como las demás.
        $sinEntrenador = DB::table('trainer_face_references')->whereNull('trainer_id')->whereNotNull('template')->count();
        if ($sinEntrenador > 0) {
            throw new RuntimeException("Hay {$sinEntrenador} plantilla(s) de personas sin perfil de entrenador: deshacer las perdería. Revócalas antes.");
        }

        // Las revocadas no tienen plantilla: no hay nada que devolver.
        DB::table('trainer_face_references')->whereNull('template')->delete();
        DB::table('trainer_face_references')->orderBy('id')->each(function (object $fila): void {
            DB::table('trainer_face_references')->where('id', $fila->id)->update([
                'embedding' => Crypt::decryptString((string) $fila->template),
            ]);
        });

        Schema::table('trainer_face_references', function (Blueprint $table): void {
            $table->dropUnique(['identity_id']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'identity_id', 'template', 'status', 'template_version', 'enrolled_via',
                'enrolled_device_id', 'enrolled_by_admin_id', 'enrolled_by_name', 'consent_version',
                'consent_accepted_at', 'last_verified_at', 'revoked_at', 'revoked_by_admin_id',
                'revoked_by_name', 'revoked_reason',
            ]);
        });

        Schema::table('trainer_face_references', function (Blueprint $table): void {
            $table->unsignedBigInteger('trainer_id')->nullable(false)->change();
            $table->json('embedding')->nullable(false)->change();
        });
    }
};
