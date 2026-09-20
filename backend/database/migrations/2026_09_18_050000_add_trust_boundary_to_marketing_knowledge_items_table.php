<?php

use App\Models\MarketingKnowledgeItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RC-4 — procedencia y estado de revisión de la base de conocimiento.
 *
 * La tabla guarda lo que ULTRON recibe como «hechos del gimnasio»
 * (context.knowledge_base y context.gym). Hasta aquí cualquiera con el secreto
 * interno compartido escribía en ella y publicaba en el mismo instante. Estas
 * columnas separan dos preguntas que estaban mezcladas: DE DÓNDE vino el ítem
 * y SI alguien lo dio por bueno.
 *
 * ADITIVA Y SIN PÉRDIDA DE COMPORTAMIENTO:
 *  - `review_status` nace con default 'pending' (una fila insertada por un
 *    camino que no conocemos NO publica: falla cerrado), pero esta misma
 *    migración marca como 'approved' TODO lo que ya existía. Los ítems de hoy
 *    son legítimos y no pueden desaparecer del prompt al desplegar.
 *  - `origin` se rellena desde la columna `source` que ya existía, para que el
 *    doctor pueda señalar lo que en su día entró por la máquina anónima.
 *  - No se borra ni se renombra nada. `source` sigue tal cual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_knowledge_items', function (Blueprint $table): void {
            // De dónde vino: seeder | server | human_panel | legacy | internal_api | import.
            $table->string('origin', 32)->nullable()->after('source');
            // Estado de publicación: pending | approved | rejected.
            $table->string('review_status', 16)->default(MarketingKnowledgeItem::REVIEW_PENDING)->after('origin');
            // Auditoría, sin datos personales: etiquetas cortas, nunca correos ni teléfonos.
            $table->string('submitted_by', 60)->nullable()->after('review_status');
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            $table->string('reviewed_by', 60)->nullable()->after('submitted_at');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            // El consumidor filtra por (is_active, review_status) en cada turno.
            $table->index(['review_status', 'is_active'], 'mki_review_active_idx');
        });

        // Procedencia de lo que ya existía, deducida de `source`.
        DB::table('marketing_knowledge_items')->where('source', 'seeder')
            ->update(['origin' => MarketingKnowledgeItem::ORIGIN_SEEDER]);
        DB::table('marketing_knowledge_items')->where('source', 'api')
            ->update(['origin' => MarketingKnowledgeItem::ORIGIN_INTERNAL_API]);
        DB::table('marketing_knowledge_items')->whereNull('origin')
            ->update(['origin' => MarketingKnowledgeItem::ORIGIN_LEGACY]);

        /*
         * Lo que ya está publicado sigue publicado. Esto NO es una absolución:
         * las filas con origin=internal_api quedan aprobadas pero marcadas, y
         * `knowledge/doctor` las cuenta en `approved_from_untrusted_origin`
         * para que alguien las mire. Bajarlas aquí dejaría al asesor sin
         * conocimiento en el despliegue, que es un daño cierto frente a uno
         * hipotético.
         */
        DB::table('marketing_knowledge_items')
            ->update(['review_status' => MarketingKnowledgeItem::REVIEW_APPROVED]);
    }

    public function down(): void
    {
        Schema::table('marketing_knowledge_items', function (Blueprint $table): void {
            $table->dropIndex('mki_review_active_idx');
            $table->dropColumn(['origin', 'review_status', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at']);
        });
    }
};
