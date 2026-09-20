<?php

use App\Models\MarketingKnowledgeItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `origin` deja de poder ser nulo, porque el nulo era invisible.
 *
 * `scopeApprovedFromUntrustedOrigin()` pregunta `origin NOT IN (confiables)`, y
 * en SQL eso con `origin = NULL` no da verdadero: da NULL. O sea que una fila
 * aprobada y SIN procedencia no la contaba nadie —ni el doctor ni la consulta
 * equivalente— mientras `scopeActiveNow()` sí la servía al prompt, porque ese
 * filtra por activo, aprobado y vigencia, y no mira el origen. El peor tipo de
 * agujero: el que el instrumento de medida no puede ver.
 *
 * Hoy no debería existir ninguna fila así —la migración de la frontera rellenó
 * todo lo existente y el hook del modelo normaliza siempre—, pero el hueco se
 * abre por los caminos que NO pasan por el modelo: un `DB::table()->insert()`
 * crudo, una migración futura y, el más realista de todos, **restaurar un dump
 * anterior a la frontera de confianza**.
 *
 * Con la columna NOT NULL y por defecto `unknown` —que no es confiable— esa
 * clase de problema se cierra entera: lo que entre sin decir de dónde viene
 * queda contado y a la vista, en vez de colarse en silencio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('marketing_knowledge_items', 'origin')) {
            return;
        }

        DB::table('marketing_knowledge_items')
            ->whereNull('origin')
            ->update(['origin' => MarketingKnowledgeItem::ORIGIN_UNKNOWN]);

        Schema::table('marketing_knowledge_items', function (Blueprint $table): void {
            $table->string('origin', 32)->default(MarketingKnowledgeItem::ORIGIN_UNKNOWN)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('marketing_knowledge_items', 'origin')) {
            return;
        }

        Schema::table('marketing_knowledge_items', function (Blueprint $table): void {
            $table->string('origin', 32)->nullable()->change();
        });
    }
};
