<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jornada laboral: entrada y salida de una persona que trabaja aquí.
 *
 * NO es `attendances` (la puerta de los socios: rachas, eventos comerciales,
 * consumo de planes) ni `class_sessions` (inicio y fin de una clase). Son
 * operaciones distintas y no comparten estados: un entrenador de planta que
 * dicta una clase registra las dos cosas por caminos separados.
 *
 *  · workdays ........ una jornada: abierta, cerrada o incidencia. Las horas son
 *                      las del SERVIDOR. `business_date` es el día del gimnasio
 *                      (Bogotá) de la entrada; una jornada que cruza la
 *                      medianoche sigue siendo de ese día.
 *  · workday_events .. el historial inmutable: cada entrada, salida, incidencia
 *                      y corrección, con el método de identificación y el
 *                      dispositivo. `operation_id` único = idempotencia de los
 *                      reintentos de la tablet.
 *
 * LA INVARIANTE en la base: una persona no puede tener dos jornadas abiertas.
 * Índice único PARCIAL sobre `identity_id` donde `status = 'open'`, con la misma
 * sintaxis en PostgreSQL (producción) y SQLite (suite): la carrera de dos
 * entradas simultáneas la decide la base, no un SELECT previo.
 */
return new class extends Migration
{
    private const OPEN_INDEX = 'workdays_one_open_per_identity';

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('El índice único parcial '.self::OPEN_INDEX." no está escrito para el driver '{$driver}'.");
        }

        Schema::create('workdays', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('identity_id');
            // Foto del cargo con el que entró (el cargo puede cambiar después).
            $table->string('position', 40);
            $table->string('status', 20); // open | closed | incident
            $table->date('business_date');

            $table->timestamp('clock_in_at');
            $table->string('clock_in_method', 20);
            $table->unsignedBigInteger('clock_in_device_id')->nullable();

            $table->timestamp('clock_out_at')->nullable();
            $table->string('clock_out_method', 20)->nullable();
            $table->unsignedBigInteger('clock_out_device_id')->nullable();
            // employee | admin_correction
            $table->string('clock_out_source', 30)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->string('incident_reason', 40)->nullable();
            $table->timestamp('incident_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 20)->nullable(); // set_clock_out | dismissed
            $table->unsignedBigInteger('resolved_by_admin_id')->nullable();
            $table->string('resolved_by_name', 120)->nullable();
            $table->string('resolution_note', 500)->nullable();

            $table->timestamps();

            $table->index(['business_date', 'status']);
            $table->index(['identity_id', 'clock_in_at']);
            $table->index('status');
            // Integridad referencial: nada de esto se borra (las jornadas son
            // registro laboral). Sin FK al administrador que resolvió (ver 700003).
            $table->foreign('identity_id')->references('id')->on('identities')->restrictOnDelete();
            $table->foreign('clock_in_device_id')->references('id')->on('authorized_devices')->restrictOnDelete();
            $table->foreign('clock_out_device_id')->references('id')->on('authorized_devices')->restrictOnDelete();
        });

        Schema::create('workday_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workday_id')->nullable();
            $table->unsignedBigInteger('identity_id');
            // clock_in | clock_out | incident_flagged | incident_resolved
            $table->string('type', 30);
            $table->timestamp('occurred_at');
            $table->string('method', 20)->nullable();
            $table->decimal('face_distance', 6, 4)->nullable();
            $table->unsignedBigInteger('authorized_device_id')->nullable();
            $table->boolean('device_verified')->default(false);
            $table->string('actor_type', 20); // employee | admin | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 120)->nullable();
            $table->uuid('operation_id')->nullable()->unique();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('workday_id');
            $table->index(['identity_id', 'occurred_at']);
            $table->foreign('workday_id')->references('id')->on('workdays')->restrictOnDelete();
            $table->foreign('identity_id')->references('id')->on('identities')->restrictOnDelete();
            $table->foreign('authorized_device_id')->references('id')->on('authorized_devices')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::OPEN_INDEX
            ." ON workdays (identity_id) WHERE status = 'open'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::OPEN_INDEX);
        Schema::dropIfExists('workday_events');
        Schema::dropIfExists('workdays');
    }
};
