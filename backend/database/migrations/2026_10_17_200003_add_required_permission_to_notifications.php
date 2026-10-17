<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bandeja del CRM (`notifications`, audience admin) era GLOBAL: toda cuenta
 * autenticada veía todas las filas. Los avisos de la agenda de valoraciones
 * nombran a un socio y a su entrenador, y no deben llegar a quien no puede ver
 * la agenda.
 *
 * `required_permission` (nullable): el permiso que tiene que tener la sesión
 * para VER la fila —y por tanto para marcarla como leída—. NULL = como hasta
 * ahora, la ve todo el CRM: las notificaciones existentes no cambian.
 *
 * Solo una columna nullable sin clave foránea: en SQLite es un ADD COLUMN, sin
 * reconstruir la tabla (y sin perder el índice único de `event_key`).
 *
 * SIN índice (revisión de BD M1): ninguna consulta lo usaría. El filtro es
 * `required_permission IS NULL OR required_permission IN (…)` sobre filas casi
 * todas NULL, y la bandeja va por `created_at`, el stream por la PK, el
 * contador y «marcar todas» por `(audience, status)`, y «leer» por `uuid`
 * (EXPLAIN en PostgreSQL 14 con 30.000 filas: 0 lecturas del índice). Solo
 * costaría una escritura más por cada notificación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('required_permission', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('required_permission');
        });
    }
};
