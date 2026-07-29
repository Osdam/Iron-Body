<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitud EXPRESA de factura electrónica, guardada en el hecho económico.
 *
 * Por qué no basta con `payment_transactions.metadata`: hasta ahora la intención
 * de facturar vivía únicamente en la transacción de pasarela, así que un pago en
 * efectivo o una venta de mostrador —que no crean transacción— NO PODÍAN
 * facturarse nunca, por mucho que el cliente lo pidiera. La solicitud pertenece
 * a la compra, no al medio de pago.
 *
 * `invoice_requested` es false por defecto y NO se rellena hacia atrás: los 488
 * pagos históricos y los importados `MIGR-*` quedan exactamente como estaban.
 * Facturar requiere una acción explícita posterior, nunca una migración.
 *
 * Rollback: `down()` sólo elimina columnas añadidas aquí; ningún dato previo a
 * esta migración depende de ellas.
 */
return new class extends Migration
{
    /** Tablas que representan un hecho económico facturable. */
    private const TABLAS = ['payments', 'product_sales'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                if (! Schema::hasColumn($tabla, 'invoice_requested')) {
                    // NOT NULL con default false: ninguna fila histórica queda en
                    // un estado ambiguo «no se sabe si pidió factura».
                    $table->boolean('invoice_requested')->default(false);
                }
                if (! Schema::hasColumn($tabla, 'invoice_email')) {
                    $table->string('invoice_email', 160)->nullable();
                }
                if (! Schema::hasColumn($tabla, 'invoice_requested_at')) {
                    $table->timestamp('invoice_requested_at')->nullable();
                }
            });

            // Índice parcial: las consultas buscan SIEMPRE las que sí pidieron
            // factura, que son minoría. Indexar sólo esas mantiene el índice
            // pequeño aunque la tabla crezca.
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $indice = $tabla.'_invoice_requested_index';
                if (! $this->indiceExiste($tabla, $indice)) {
                    $table->index('invoice_requested', $indice);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $indice = $tabla.'_invoice_requested_index';
                if ($this->indiceExiste($tabla, $indice)) {
                    $table->dropIndex($indice);
                }

                $columnas = array_values(array_filter(
                    ['invoice_requested', 'invoice_email', 'invoice_requested_at'],
                    fn (string $c) => Schema::hasColumn($tabla, $c),
                ));

                if ($columnas !== []) {
                    $table->dropColumn($columnas);
                }
            });
        }
    }

    private function indiceExiste(string $tabla, string $indice): bool
    {
        return collect(Schema::getIndexes($tabla))
            ->contains(fn (array $i) => ($i['name'] ?? null) === $indice);
    }
};
