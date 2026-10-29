<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARCHIVAR UN PLAN en vez de borrarlo.
 *
 * Un plan con historia (cobros, socios, traslados) ya no se borra
 * (PlanController::destroy responde 409 `plan_has_history`): se archiva. Un
 * plan archivado no se vende por ninguna vía y no se ve en la app, pero sigue
 * existiendo para lo que lo nombra: los cobros, los traslados y los socios que
 * lo tienen. Las banderas de siempre (`active`, `sellable`, `visible_in_app`)
 * hacen el trabajo; esto solo deja constancia de que fue un ARCHIVO, cuándo,
 * quién y por qué, y permite filtrar el listado. El historial completo está en
 * `audit_logs`.
 *
 * `archived_by` → `admins`, nulo si la cuenta se elimina: el archivo se queda.
 *
 * `plans` no tiene índices parciales: en SQLite la reconstrucción que provoca
 * la llave conserva su único índice (`plans_active_billing_idx`), que es total.
 *
 * Aditiva y reversible: `down()` quita las tres columnas (y con ellas la marca
 * de archivo; las banderas y la auditoría se quedan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('archive_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['archived_at', 'archive_reason']);
        });
    }
};
