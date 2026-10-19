<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · Hito 4 (laboratorio de IA): persistencia. Contrato:
 * docs/ultron-studio/ULTRON_STUDIO_H4_CONTRACT.md, §A8.
 *
 * Dominio NUEVO y SOLO ADITIVO: cinco tablas `ultron_studio_lab_*`. No altera
 * ninguna tabla existente (solo REFERENCIA `ultron_studio_versions` con dos
 * FKs) ni nada del motor de ULTRON.
 *
 *  · ultron_studio_lab_scenarios — conversaciones sintéticas: C-01… del
 *                                  catálogo y E-001… del personal. Se editan
 *                                  con revisión y se archivan; no se borran.
 *  · ultron_studio_lab_runs      — una ejecución. Congela objetivo, modo,
 *                                  modelo, precios, estimación y autoría; solo
 *                                  avanzan su estado y sus resultados, y al
 *                                  terminar es inmutable.
 *  · ultron_studio_lab_run_cases — un caso por escenario de la ejecución, con
 *                                  su entrada congelada y su reserva del
 *                                  presupuesto del día.
 *  · ultron_studio_lab_verdicts  — el veredicto de una persona; uno por
 *                                  ejecución (UNIQUE run_id); solo inserción.
 *  · ultron_studio_lab_events    — libro propio del laboratorio; solo
 *                                  inserción; sin IP ni user agent.
 *
 * Las personas se guardan como INSTANTÁNEA (id + nombre + rol) SIN FK a
 * `admins`, como en F: la historia sigue diciendo quién fue aunque la cuenta
 * se renombre o desaparezca, y una FK con ON DELETE cambiaría o bloquearía
 * filas que la base declara inmutables. `target_draft_admin_id` tampoco lleva
 * FK: es el dueño del borrador al lanzar, y filtra la visibilidad (§A3).
 *
 * FKs, todas RESTRICT: caso → ejecución y → escenario; ejecución → versión
 * (solo con objetivo `version`); veredicto → ejecución y → versión. Las filas
 * referenciadas tampoco se pueden borrar por sus disparadores
 * (2026_10_18_100002 y 2026_10_19_100003); RESTRICT deja escrito que la base
 * no borraría en cascada aunque alguien los quitara.
 *
 * Compatibilidad PostgreSQL 14 + SQLite (la suite), como F:
 *  · `json`, no `jsonb`. En PG `json` no tiene operador de igualdad: nada de
 *    DISTINCT, GROUP BY ni UNION sobre estas columnas, y los disparadores
 *    comparan por lista de columnas, no por valor.
 *  · Sin índices parciales.
 *  · Todas las FKs nacen con su tabla. Un `Schema::table` posterior con FK hace
 *    que SQLite RECONSTRUYA la tabla y pierda sus disparadores y la cláusula
 *    WHERE de sus índices.
 *  · `unsigned*` es solo documental en PG, que no tiene enteros sin signo.
 *    Importes y tokens los calcula el servidor; nunca llegan del cliente.
 *
 * PostgreSQL, cerrojos: las tablas son nuevas y nadie las usa, salvo por las
 * dos FKs a `ultron_studio_versions`. Añadir una FK pide SHARE ROW EXCLUSIVE
 * sobre la tabla referenciada: no choca con las lecturas (ACCESS SHARE), sí
 * con una transacción abierta que haya escrito en versiones. Con el
 * `lock_timeout` de `ironbody-migrate` (5 s), en ese caso la migración falla
 * limpia (55P03): Laravel la envuelve en una transacción, no queda registrada
 * y se reintenta.
 *
 * Las guardas (CHECK de dominio en PG y disparadores en los dos motores) van
 * en 2026_10_19_100003.
 *
 * down() BORRA las cinco tablas con su contenido, en orden inverso. DROP no
 * pasa por los disparadores de fila: en un entorno con uso real, revertir
 * DESTRUYE escenarios, ejecuciones, veredictos y el libro. Para retirar el
 * laboratorio se apaga su bandera (ULTRON_STUDIO_LAB_ENABLED); revertir esto
 * en producción, solo con autorización expresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ultron_studio_lab_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // C-01… (catálogo) · E-001… (personal). Los reparte un cerrojo
            // consultivo, como las S-NNN.
            $table->string('code', 16)->unique();
            // catalog | studio
            $table->string('source', 10);
            // active | archived
            $table->string('status', 10);
            // saludo | precio | descuento | pago | derivacion | cortesia |
            // hechos | estilo | consentimiento | membresia | otro
            $table->string('category', 24);
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            // {turns, facts, criteria}
            $table->json('content');
            $table->char('content_hash', 64);
            // Concurrencia optimista (baseRevision): UPDATE … WHERE revision = ?.
            $table->unsignedInteger('revision')->default(1);
            // Idempotencia del alta desde el CRM; NULL en las del catálogo.
            $table->uuid('client_request_id')->nullable()->unique();
            // Instantáneas SIN FK. NULL = el comando de importación (actor system).
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('archive_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'code'], 'usls_status_code_idx');
        });

        Schema::create('ultron_studio_lab_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Idempotencia de la creación (doble clic, reintento): obligatoria.
            $table->uuid('client_request_id')->unique();
            // version | draft | baseline. En PG, `uslr_target_chk` ata cada
            // clase a su columna (y solo a ella).
            $table->string('target_kind', 10);
            // Solo con objetivo `version`.
            $table->foreignId('target_version_id')->nullable()->constrained('ultron_studio_versions')->restrictOnDelete();
            // Solo con objetivo `draft`: el dueño del borrador. SIN FK.
            $table->unsignedBigInteger('target_draft_admin_id')->nullable();
            $table->string('target_label', 80);
            $table->char('target_content_hash', 64);
            // Configuración y reglas usadas, congeladas al crear (§A3).
            $table->json('target_snapshot');
            $table->string('catalog_version', 32);
            // openai | fake
            $table->string('mode', 10);
            $table->string('model', 64);
            $table->string('prompt_version', 32);
            // Los precios con los que se estimó y se liquida.
            $table->json('prices');
            // queued | running | completed | cancelled | stopped. Los tres
            // últimos son terminales: la fila ya no cambia (disparador).
            $table->string('status', 12);
            $table->string('stop_reason', 32)->nullable();
            $table->unsignedSmallInteger('case_count');
            // Importes en micro-USD, enteros (§A3).
            $table->unsignedBigInteger('estimate_expected_micros');
            $table->unsignedBigInteger('estimate_max_micros');
            $table->unsignedBigInteger('accepted_max_micros');
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->json('summary')->nullable();
            // approve | review | reject: solo en `completed` y modo `openai`.
            $table->string('ai_recommendation', 10)->nullable();
            $table->char('results_hash', 64)->nullable();
            // Día del presupuesto, el de America/Bogota.
            $table->date('budget_day');
            // Instantánea de quien la lanzó, SIN FK.
            $table->unsignedBigInteger('created_by_admin_id');
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->string('cancel_requested_by_name', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id'], 'uslr_status_idx');
            // También sirve a la FK target_version_id.
            $table->index(['target_version_id', 'id'], 'uslr_target_version_idx');
            $table->index('budget_day', 'uslr_budget_day_idx');
            $table->index(['created_by_admin_id', 'id'], 'uslr_creator_idx');
        });

        Schema::create('ultron_studio_lab_run_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('ultron_studio_lab_runs')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            // El escenario y su revisión al crear la ejecución; su entrada va
            // congelada en scenario_snapshot.
            $table->foreignId('scenario_id')->constrained('ultron_studio_lab_scenarios')->restrictOnDelete();
            $table->string('scenario_code', 16);
            $table->unsignedInteger('scenario_revision');
            // {turns, facts, criteria}
            $table->json('scenario_snapshot');
            // pending | running | done | error | skipped. Los tres últimos son
            // terminales: la fila ya no cambia (disparador).
            $table->string('status', 10);
            // pass | review | fail: solo en `done`.
            $table->string('outcome', 8)->nullable();
            $table->string('error_code', 32)->nullable();
            // La respuesta del actor; el servicio la acota a 2000 caracteres.
            $table->text('reply')->nullable();
            $table->json('checks')->nullable();
            $table->json('judge')->nullable();
            // [{role, class, inputTokens, outputTokens, costMicros, latencyMs}]
            $table->json('calls')->nullable();
            // Reserva atómica (§A3): el día de Bogotá al crear el caso y su máximo.
            $table->date('budget_day');
            $table->unsignedBigInteger('reserved_micros');
            // NULL hasta liquidar el caso.
            $table->unsignedBigInteger('cost_micros')->nullable();
            $table->unsignedBigInteger('overrun_micros')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Una posición por ejecución. También sirve a la FK run_id.
            $table->unique(['run_id', 'position'], 'uslc_run_position_unique');
            // Lo comprometido del día (§A3).
            $table->index(['budget_day', 'status'], 'uslc_budget_status_idx');
        });

        Schema::create('ultron_studio_lab_verdicts', function (Blueprint $table): void {
            $table->id();
            // Un veredicto por ejecución, garantizado por la base: el segundo
            // es 409 `verdict_exists`.
            $table->foreignId('run_id')->unique()->constrained('ultron_studio_lab_runs')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('ultron_studio_versions')->restrictOnDelete();
            $table->char('version_content_hash', 64);
            // La huella de los resultados que vio la persona.
            $table->char('results_hash', 64);
            // approved | rejected | needs_changes
            $table->string('decision', 14);
            $table->string('note', 1000)->nullable();
            $table->json('acknowledged_cases')->nullable();
            // Instantánea del revisor, SIN FK.
            $table->unsignedBigInteger('reviewer_admin_id');
            $table->string('reviewer_name', 120);
            $table->string('reviewer_role', 60)->nullable();
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');
        });

        Schema::create('ultron_studio_lab_events', function (Blueprint $table): void {
            $table->id();
            // scenario.created, run.finished, run.verdict_recorded… (§A8).
            $table->string('event', 48);
            // scenario | run | system
            $table->string('subject_type', 16);
            $table->uuid('subject_uuid')->nullable();
            // admin | system. Instantánea SIN FK: el libro no cambia aunque la
            // cuenta sí.
            $table->string('actor_type', 10);
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            $table->string('actor_name', 120);
            $table->string('actor_role', 60)->nullable();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('summary', 255);
            $table->json('diff')->nullable();
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_uuid', 'id'], 'usle_subject_idx');
            $table->index(['event', 'id'], 'usle_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ultron_studio_lab_events');
        Schema::dropIfExists('ultron_studio_lab_verdicts');
        Schema::dropIfExists('ultron_studio_lab_run_cases');
        Schema::dropIfExists('ultron_studio_lab_runs');
        Schema::dropIfExists('ultron_studio_lab_scenarios');
    }
};
