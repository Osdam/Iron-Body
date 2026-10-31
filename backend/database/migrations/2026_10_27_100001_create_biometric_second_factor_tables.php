<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rostros que el modelo no separa (contrato §10, caso 2026-10-26: dos
 * entrenadores distintos a 0,91 con el umbral en 1,0).
 *
 *  · face_lookalikes ............. pares de personas cuyo rostro el modelo
 *                                  confunde. Quien está en un par necesita
 *                                  SIEMPRE rostro + PIN. Sin distancias: el
 *                                  hecho basta para el control.
 *  · face_lookalike_exceptions ... autorización presencial de un administrador
 *                                  para registrar el rostro de una persona pese
 *                                  al parecido con la otra del par. Un uso, 30 min.
 *  · workforce_pins .............. PIN personal (segundo factor). HMAC con la
 *                                  APP_KEY + bcrypt; intentos y bloqueo aquí,
 *                                  no en la caché: un reinicio no da intentos.
 *
 * Los pares de los rechazos ya diagnosticados (face_duplicate_matches) se
 * cargan aquí: sin ellos, quien se parece y aún no tiene rostro registrado
 * podría pasar por la otra persona con el rostro solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_lookalikes', function (Blueprint $table): void {
            $table->id();
            // Par sin orden: siempre el id menor primero.
            $table->unsignedBigInteger('identity_low_id');
            $table->unsignedBigInteger('identity_high_id');
            $table->string('source', 30);
            $table->timestamp('first_detected_at')->useCurrent();
            $table->timestamp('last_detected_at')->useCurrent();
            $table->timestamps();

            $table->unique(['identity_low_id', 'identity_high_id']);
            $table->index('identity_high_id');
            $table->foreign('identity_low_id')->references('id')->on('identities')->cascadeOnDelete();
            $table->foreign('identity_high_id')->references('id')->on('identities')->cascadeOnDelete();
        });

        Schema::create('face_lookalike_exceptions', function (Blueprint $table): void {
            $table->id();
            // Quien va a registrar su rostro y la otra persona del par.
            $table->unsignedBigInteger('identity_id');
            $table->unsignedBigInteger('other_identity_id');
            $table->boolean('subject_verified_in_person');
            $table->boolean('other_verified_in_person');
            $table->string('reason', 500);
            // Sin FK a los administradores: una cuenta del CRM se puede borrar
            // y su nombre ya va copiado en la fila.
            $table->unsignedBigInteger('authorized_by_admin_id')->nullable();
            $table->string('authorized_by_name', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('consumed_reference_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['identity_id', 'other_identity_id']);
            $table->foreign('identity_id')->references('id')->on('identities')->cascadeOnDelete();
            $table->foreign('other_identity_id')->references('id')->on('identities')->cascadeOnDelete();
            $table->foreign('consumed_reference_id')->references('id')->on('trainer_face_references')->nullOnDelete();
        });

        Schema::create('workforce_pins', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('identity_id')->unique();
            // NULL tras anularlo: el PIN no se conserva.
            $table->string('pin_hash', 255)->nullable();
            $table->timestamp('set_at')->nullable();
            $table->unsignedBigInteger('set_device_id')->nullable();
            $table->unsignedBigInteger('set_authorization_code_id')->nullable();
            // Fallos dentro de la ventana (24 h desde el primero). Acertar NO la
            // reinicia: si no, quien se parece probaría PIN sin fin entre los
            // accesos del titular (revisión de seguridad 2026-10-27, M-1).
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('failures_window_started_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            // Bloqueos que escalan (15 min → 1 h → 4 h → 24 h) y se olvidan a
            // los 7 días sin bloqueo.
            $table->unsignedSmallInteger('lock_level')->default(0);
            $table->timestamp('last_locked_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_admin_id')->nullable();
            $table->string('revoked_by_name', 120)->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('identity_id')->references('id')->on('identities')->cascadeOnDelete();
            $table->foreign('set_device_id')->references('id')->on('authorized_devices')->nullOnDelete();
            $table->foreign('set_authorization_code_id')->references('id')->on('workforce_authorization_codes')->nullOnDelete();
        });

        // Pares de los rechazos ya diagnosticados: quien intentaba registrarse
        // (la bitácora) y el dueño de la plantilla con la que chocó.
        // Una plantilla heredada puede tener solo la ficha de entrenador: su
        // dueño es la identidad de esa ficha (igual que al rechazar en vivo).
        $pairs = DB::table('face_duplicate_matches as m')
            ->join('trainer_audit_logs as l', 'l.id', '=', 'm.audit_log_id')
            ->join('trainer_face_references as r', 'r.id', '=', 'm.face_reference_id')
            ->leftJoin('trainers as t', 't.id', '=', 'r.trainer_id')
            ->whereNotNull('l.identity_id')
            ->get(['l.identity_id as subject', 'r.identity_id as owner', 't.identity_id as trainer_owner', 'm.created_at']);

        $byPair = [];
        foreach ($pairs as $row) {
            $row->other = $row->owner ?? $row->trainer_owner;
            if ($row->other === null || (int) $row->other === (int) $row->subject) {
                continue;
            }
            $low = min((int) $row->subject, (int) $row->other);
            $high = max((int) $row->subject, (int) $row->other);
            $key = "{$low}-{$high}";
            $at = (string) $row->created_at;
            $byPair[$key] ??= ['identity_low_id' => $low, 'identity_high_id' => $high, 'first' => $at, 'last' => $at];
            $byPair[$key]['first'] = min($byPair[$key]['first'], $at);
            $byPair[$key]['last'] = max($byPair[$key]['last'], $at);
        }
        foreach ($byPair as $pair) {
            DB::table('face_lookalikes')->insert([
                'identity_low_id' => $pair['identity_low_id'],
                'identity_high_id' => $pair['identity_high_id'],
                'source' => 'duplicate_rejected',
                'first_detected_at' => $pair['first'],
                'last_detected_at' => $pair['last'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_pins');
        Schema::dropIfExists('face_lookalike_exceptions');
        Schema::dropIfExists('face_lookalikes');
    }
};
