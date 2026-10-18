<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · persistencia (hito F). Contrato:
 * docs/ultron-studio/ULTRON_STUDIO_F_CONTRACT.md, §A1.
 *
 * Dominio NUEVO y SOLO ADITIVO: no toca ninguna tabla existente ni nada del
 * motor de ULTRON (`ultron_aborts`, `marketing_*`). El prefijo `ultron_studio_`
 * es deliberado: la frontera «el motor no lee el Studio» se comprueba con un
 * grep.
 *
 *  · ultron_studio_drafts          — un borrador por persona (UNIQUE admin_id),
 *                                    editable, con revisión.
 *  · ultron_studio_versions        — copias inmutables. Solo cambian `status`,
 *                                    `last_validation`, `submitted_at`,
 *                                    `status_changed_at` y `updated_at`.
 *  · ultron_studio_version_reviews — decisiones de cuatro ojos; solo inserción.
 *  · ultron_studio_rules           — reglas del código (R-01…, importadas) y
 *                                    del Studio (S-001…).
 *  · ultron_studio_audit_events    — libro de solo inserción.
 *
 * Las cuentas del CRM son `admins`, no `users` (los socios). Solo el borrador
 * lleva FK a `admins`. Versiones, reseñas, reglas y auditoría guardan al actor
 * como INSTANTÁNEA (id + nombre + rol) SIN FK: la historia sigue diciendo quién
 * fue aunque la cuenta se renombre o desaparezca, y una FK con ON DELETE
 * cambiaría o bloquearía filas que la base declara inmutables.
 *
 * Compatibilidad PostgreSQL 14 + SQLite (la suite):
 *  · `json`, no `jsonb`. En PG `json` no tiene operador de igualdad: nada de
 *    DISTINCT, GROUP BY ni UNION sobre estas columnas, y los disparadores
 *    comparan por lista de columnas, no por valor.
 *  · Sin índices parciales.
 *  · Todas las FKs nacen con su tabla. Un `Schema::table` posterior con FK hace
 *    que SQLite RECONSTRUYA la tabla y pierda sus disparadores.
 *
 * Las guardas (CHECK de dominio en PG y disparadores de inmutabilidad en los
 * dos motores) van en 2026_10_18_100002.
 *
 * Reversible: down() borra las cinco tablas en orden inverso. En un entorno
 * con uso real, revertir DESTRUYE la auditoría de solo inserción: para retirar
 * el Studio se apaga la bandera del CRM, no se revierte esto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ultron_studio_drafts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Un borrador por persona, garantizado por la base. El primer
            // guardado inserta en un SAVEPOINT; el choque es 409 `draft_conflict`.
            $table->foreignId('admin_id')->unique()->constrained('admins')->cascadeOnDelete();
            // 1 en el primer guardado, +1 por guardado o descarte. Concurrencia
            // optimista: UPDATE … WHERE admin_id = ? AND revision = ?.
            $table->unsignedInteger('revision');
            $table->string('catalog_version', 32);
            // {definition:{layers, edgeIds}, configs, layout:{positions, viewport|null}}
            $table->json('content');
            $table->char('content_hash', 64);
            $table->timestamps();
        });

        Schema::create('ultron_studio_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Idempotencia de la creación (doble clic, reintento): la segunda
            // petición encuentra la versión de la primera.
            $table->uuid('client_request_id')->unique();
            $table->string('label', 80);
            $table->string('note', 500)->nullable();
            // DRAFT | VALIDATED | REVIEW_REQUIRED | APPROVED | REJECTED. En PG un
            // CHECK (2026_10_18_100002): los estados que el motor no respalda no
            // caben en la base.
            $table->string('status', 20)->default('DRAFT');
            // {catalogVersion, definition, configs, layout, rules[]}
            $table->json('content');
            // Huella del comportamiento (sin la disposición): dos versiones que
            // se comportan igual la comparten.
            $table->char('content_hash', 64)->index('usv_content_hash_idx');
            $table->string('catalog_version', 32);
            $table->char('catalog_hash', 64);
            $table->unsignedInteger('source_draft_revision');
            // Instantánea del creador, SIN FK. Los cuatro ojos comparan este id
            // con el del revisor.
            $table->unsignedBigInteger('created_by_admin_id');
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            // {catalogVersion, validatedAt, publishable, warnings[], findings[]}
            $table->json('last_validation')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id'], 'usv_status_idx');
            $table->index(['created_by_admin_id', 'id'], 'usv_creator_idx');
        });

        Schema::create('ultron_studio_version_reviews', function (Blueprint $table): void {
            $table->id();
            // Una decisión por versión en F. RESTRICT: una versión revisada no
            // se borra (y la base tampoco deja borrar versiones).
            $table->foreignId('version_id')->unique()->constrained('ultron_studio_versions')->restrictOnDelete();
            // approved | rejected
            $table->string('decision', 16);
            // Instantánea del revisor, SIN FK.
            $table->unsignedBigInteger('reviewer_admin_id');
            $table->string('reviewer_name', 120);
            $table->string('reviewer_role', 60)->nullable();
            // Obligatoria en un rechazo (el motivo): la exige el servicio.
            $table->string('note', 500)->nullable();
            $table->json('acknowledged_warnings')->nullable();
            // La validación que vio el revisor y la huella de lo que revisó.
            $table->json('validation');
            $table->char('content_hash', 64);
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');
        });

        Schema::create('ultron_studio_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // R-01…R-12 (del código, importadas) · S-001… (del Studio).
            $table->string('code', 16)->unique();
            // legacy_baseline | studio
            $table->string('source', 20);
            // code | none. Una regla del Studio nunca la aplica el motor (CHECK en PG).
            $table->string('enforcement', 10);
            // active | draft | archived
            $table->string('status', 10);
            $table->string('description', 500);
            // restriction | permission | condition | style
            $table->string('type', 16);
            // critical | high | normal
            $table->string('priority', 10);
            $table->json('layers');
            // {configs:[{layer,key}], edges:[], knowledgeKeys:[], knowledgeCategories:[]}
            $table->json('refs');
            // Clases que la aplican; solo en las del código.
            $table->string('origin', 200)->nullable();
            // Concurrencia optimista (baseRevision), como el borrador.
            $table->unsignedInteger('revision')->default(1);
            $table->char('content_hash', 64);
            // Idempotencia de la creación desde el Studio; NULL en las importadas.
            $table->uuid('client_request_id')->nullable()->unique();
            // Instantáneas SIN FK. NULL = el comando de importación.
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('archive_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'code'], 'usr_status_code_idx');
        });

        Schema::create('ultron_studio_audit_events', function (Blueprint $table): void {
            $table->id();
            // draft.saved, version.approved, rule.archived… (catálogo en §A6).
            $table->string('event', 48);
            // draft | version | rule | system
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
            $table->char('content_hash', 64)->nullable();
            $table->string('summary', 255);
            $table->json('diff')->nullable();
            $table->json('metadata')->nullable();
            // De la persona del CRM, nunca de prospectos. La API no los devuelve.
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');

            $table->index(['subject_type', 'subject_uuid', 'id'], 'usae_subject_idx');
            $table->index(['actor_admin_id', 'id'], 'usae_actor_idx');
            $table->index(['event', 'id'], 'usae_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ultron_studio_audit_events');
        Schema::dropIfExists('ultron_studio_rules');
        Schema::dropIfExists('ultron_studio_version_reviews');
        Schema::dropIfExists('ultron_studio_versions');
        Schema::dropIfExists('ultron_studio_drafts');
    }
};
