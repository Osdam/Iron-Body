<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Búsqueda del Inbox: que dejar de escanear 425.000 mensajes.
 *
 * Buscar «trimestral» en la bandeja hacía esto, medido:
 *
 *   Seq Scan on marketing_messages … rows=53125 … Rows Removed by Filter: 371893
 *   Execution Time: 93 ms
 *
 * Y eso por cada pulsación de tecla que sobreviva al antirrebote. Un índice
 * normal no ayuda: `LIKE '%algo%'` no tiene prefijo por el que empezar, así que
 * un B-tree no se puede usar y PostgreSQL recorre la tabla entera.
 *
 * La solución es un índice de TRIGRAMAS. Parte cada texto en secuencias de tres
 * caracteres y las indexa; buscar «trimestral» pasa a ser buscar las filas que
 * contienen «tri», «rim», «ime»… e intersecarlas. Por eso el índice deja de
 * servir con menos de tres caracteres, y por eso el servicio exige tres antes
 * de buscar en el texto de los mensajes.
 *
 * Si la extensión no se puede instalar, la migración NO falla: los índices se
 * saltan y la búsqueda sigue funcionando como hasta ahora, más lenta. Una
 * mejora de rendimiento no puede ser el motivo de que un despliegue se caiga.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // SQLite (pruebas) no tiene trigramas ni los necesita.
        }

        if (! $this->ensureTrigramExtension()) {
            return;
        }

        // Los tres campos por los que se busca de verdad: lo que escribió el
        // cliente, y el nombre y el teléfono con los que se le identifica.
        $this->createIndex('mm_body_trgm_idx', 'marketing_messages', 'body');
        $this->createIndex('ml_name_trgm_idx', 'marketing_leads', 'name');
        $this->createIndex('ml_phone_trgm_idx', 'marketing_leads', 'phone');
    }

    private function ensureTrigramExtension(): bool
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            return true;
        } catch (\Throwable $e) {
            // Instalar una extensión puede exigir superusuario. Se deja dicho
            // en el log y se sigue: la bandeja funciona igual, solo más lenta.
            Log::warning('marketing.search.trgm_unavailable', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function createIndex(string $name, string $table, string $column): void
    {
        try {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$table} USING gin ({$column} gin_trgm_ops)");
        } catch (\Throwable $e) {
            Log::warning('marketing.search.index_failed', [
                'index' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['mm_body_trgm_idx', 'ml_name_trgm_idx', 'ml_phone_trgm_idx'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
