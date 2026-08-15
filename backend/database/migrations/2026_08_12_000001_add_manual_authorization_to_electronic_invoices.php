<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorización ADMINISTRATIVA de una emisión, separada de la solicitud del cliente.
 *
 * Hasta ahora `payments.invoice_requested` / `product_sales.invoice_requested`
 * respondían a dos preguntas distintas con un solo booleano:
 *
 *   1. ¿El cliente pidió factura al comprar?  → hecho histórico, inmutable.
 *   2. ¿Debe emitirse este documento?          → decisión, revisable.
 *
 * Fusionadas, un `false` del día de la compra se convertía en una prohibición
 * perpetua: ningún pago de `/payments` podía facturarse jamás, ni siquiera con
 * el cliente delante pidiéndolo en el mostrador. Estas columnas separan la
 * segunda pregunta SIN tocar la primera: `invoice_requested` conserva
 * exactamente su significado y ninguna fila histórica cambia de valor.
 *
 * Por qué una columna propia y no reutilizar `created_by_admin_id`: la barrera
 * de emisión corre dentro del job, en otro proceso, y necesita leer el hecho
 * desde la base. Y `created_by_admin_id` queda NULL cuando la llamada entra con
 * el token compartido de automatizaciones (EnsureAdminAuth), así que como
 * marcador de «hubo autorización» sería ambiguo. Esta responde «¿se autorizó?»;
 * la otra, «¿quién?».
 *
 * Aditiva, nullable y reversible: `down()` sólo suelta lo que añade aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('electronic_invoices')) {
            return;
        }

        Schema::table('electronic_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('electronic_invoices', 'manual_authorization_at')) {
                // NULL = nadie la autorizó a mano (emisión automática o sin tocar).
                $table->timestamp('manual_authorization_at')->nullable();
            }
            if (! Schema::hasColumn('electronic_invoices', 'manual_authorization_note')) {
                // Contexto operativo de la autorización (p.ej. la decisión de
                // adquiriente tomada en el modal). Nunca datos fiscales.
                $table->string('manual_authorization_note', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('electronic_invoices')) {
            return;
        }

        Schema::table('electronic_invoices', function (Blueprint $table) {
            $columnas = array_values(array_filter(
                ['manual_authorization_at', 'manual_authorization_note'],
                fn (string $c) => Schema::hasColumn('electronic_invoices', $c),
            ));

            if ($columnas !== []) {
                $table->dropColumn($columnas);
            }
        });
    }
};
