<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AGENDA DE VALORACIONES (Entrenadores › Calendario de citas). Contrato:
 * CONTRATO_AGENDA_VALORACIONES.md v1, §1.
 *
 * Dominio NUEVO y SOLO ADITIVO. No toca `marketing_appointments` (la Agenda
 * comercial es de prospectos y la usa ULTRON), ni clases, ni pagos, ni
 * membresías, ni asignaciones entrenador-socio: aquí solo nacen tablas.
 *
 *  · valuation_settings              — una fila, los ajustes de la agenda.
 *  · valuation_availability_rules    — franjas semanales por entrenador, en hora
 *                                      de PARED de Bogotá (TIME), sin cruzar la
 *                                      medianoche.
 *  · valuation_availability_blocks   — bloqueos puntuales (UTC).
 *  · valuation_appointments          — las citas. Horas en UTC.
 *  · valuation_appointment_events    — historial APPEND-ONLY de cada cita.
 *  · valuation_reminders             — outbox de avisos (socio y CRM).
 *
 * La red de seguridad contra el solape (EXCLUDE con btree_gist) va aparte, en
 * 2026_10_17_200002, porque solo existe en PostgreSQL y su fallo se tolera.
 *
 * Reversible: down() borra las seis tablas en orden inverso. Lo único que se
 * pierde es lo que se agendó desde que existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valuation_settings', function (Blueprint $table): void {
            $table->id();
            $table->smallInteger('default_duration_minutes')->default(60);
            $table->smallInteger('slot_interval_minutes')->default(30);
            $table->smallInteger('buffer_minutes')->default(0);
            $table->integer('min_notice_minutes')->default(0);
            $table->smallInteger('max_horizon_days')->default(90);
            $table->boolean('block_on_class_conflict')->default(true);
            $table->boolean('confirmation_enabled')->default(true);
            $table->boolean('reminder_long_enabled')->default(true);
            $table->smallInteger('reminder_long_hours')->default(24);
            $table->boolean('reminder_short_enabled')->default(true);
            $table->smallInteger('reminder_short_minutes')->default(60);
            $table->boolean('internal_alert_enabled')->default(true);
            $table->smallInteger('internal_alert_minutes')->default(15);
            $table->boolean('internal_unattended_enabled')->default(true);
            // Apagado hasta verificar en producción la entrega a la app del socio.
            $table->boolean('member_app_enabled')->default(false);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        // La fila única, con los valores por defecto del contrato.
        DB::table('valuation_settings')->insert([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('valuation_availability_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trainer_id')->constrained('trainers')->cascadeOnDelete();
            // ISO: 1 = lunes … 7 = domingo, en el calendario de Bogotá.
            $table->smallInteger('weekday');
            // Hora de pared del gimnasio. end > start, sin cruzar medianoche.
            $table->time('start_time');
            $table->time('end_time');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['trainer_id', 'weekday'], 'val_rules_trainer_weekday_idx');
        });

        Schema::create('valuation_availability_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trainer_id')->constrained('trainers')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            // break | absence | day_off | manual
            $table->string('kind', 16);
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['trainer_id', 'starts_at'], 'val_blocks_trainer_starts_idx');
        });

        Schema::create('valuation_appointments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Idempotencia de la creación (doble clic, reintento). Null en las
            // importadas.
            $table->uuid('client_request_id')->nullable()->unique();
            // SOCIO → CASCADE, como `physical_evaluations`: los datos del socio se
            // van con él. Con RESTRICT, dar de baja a un socio con una cita
            // (`UserController::destroy`, que borra sus ficheros ANTES del DELETE)
            // fallaba con 500 y lo dejaba vivo sin sus ficheros (revisión de BD B1).
            // ENTRENADOR → RESTRICT: sus citas son historia de los socios; se
            // desactiva en vez de borrarlo (TrainerController responde 409).
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained('trainers')->restrictOnDelete();
            // initial | follow_up | other
            $table->string('type', 24);
            // scheduled | confirmed | in_progress | completed | no_show | cancelled
            $table->string('status', 16);
            // UTC. ends_at > starts_at (lo exige el servicio; en PostgreSQL además
            // un CHECK, ver 2026_10_17_200002).
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->smallInteger('duration_minutes');
            // Administrativas, NO clínicas. ≤ 1000 (lo valida la API).
            $table->text('notes')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            // Quien hizo la última de confirmar / iniciar / completar / no asistió.
            $table->foreignId('outcome_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            // Referencia futura a la valoración profesional. Sin FK; v1 no la rellena.
            $table->unsignedBigInteger('professional_assessment_id')->nullable();
            // crm | import
            $table->string('source', 16)->default('crm');
            // Sube con cada cambio de hora, entrenador o estado.
            $table->integer('version')->default(1);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['trainer_id', 'starts_at'], 'val_appts_trainer_starts_idx');
            $table->index(['member_id', 'starts_at'], 'val_appts_member_starts_idx');
            $table->index(['status', 'starts_at'], 'val_appts_status_starts_idx');
        });

        Schema::create('valuation_appointment_events', function (Blueprint $table): void {
            $table->id();
            // Se va con la cita, y la cita solo se borra con su socio (cascada
            // desde `members`). La aplicación nunca borra ni cambia un evento.
            $table->foreignId('appointment_id')->constrained('valuation_appointments')->cascadeOnDelete();
            // created | rescheduled | updated | confirmed | started | completed |
            // no_show | cancelled | imported
            $table->string('action', 24);
            // Instantánea del actor, sin FK: la fila no cambia aunque la cuenta
            // desaparezca (append-only de verdad, también en la base).
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->timestamp('from_starts_at')->nullable();
            $table->timestamp('to_starts_at')->nullable();
            $table->unsignedBigInteger('from_trainer_id')->nullable();
            $table->unsignedBigInteger('to_trainer_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['appointment_id', 'id'], 'val_events_appt_idx');
        });

        Schema::create('valuation_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->constrained('valuation_appointments')->cascadeOnDelete();
            // confirmation | reminder_long | reminder_short | cancellation |
            // rescheduled | internal_upcoming | internal_unattended
            $table->string('kind', 32);
            // member_app | crm
            $table->string('channel', 16);
            $table->integer('appointment_version');
            $table->timestamp('due_at');
            // pending | processing | accepted | skipped | error | failed | cancelled
            $table->string('status', 16)->default('pending');
            $table->smallInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('processed_at')->nullable();
            // uuid de la notificación creada.
            $table->string('provider_ref', 64)->nullable();
            $table->timestamps();

            $table->unique(['appointment_id', 'kind', 'channel', 'appointment_version'], 'val_reminders_once_idx');
            $table->index(['status', 'due_at'], 'val_reminders_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valuation_reminders');
        Schema::dropIfExists('valuation_appointment_events');
        Schema::dropIfExists('valuation_appointments');
        Schema::dropIfExists('valuation_availability_blocks');
        Schema::dropIfExists('valuation_availability_rules');
        Schema::dropIfExists('valuation_settings');
    }
};
