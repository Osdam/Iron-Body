<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RECLASIFICAR UN COBRO NO ES BORRARLO NI REESCRIBIRLO.
 *
 * Cuando un dinero que SÍ entró se registró con el concepto equivocado (el caso
 * que motivó esto: el piso de un entrenador cobrado como si fuera el plan
 * «Comisión de personalizados», que además alargaba la membresía), la
 * corrección contable es la de siempre: se revierte el asiento equivocado y se
 * hace el bueno, CON LA FECHA EN QUE ENTRÓ EL DINERO y dejando escrito que se
 * hizo después, quién y por qué.
 *
 * Esta tabla es esa constancia. Une el cobro original (que queda anulado, con
 * su id, su recibo y su historia intactos) con lo que lo sustituye (el cobro
 * del piso, su deuda y el abono que la salda en el MISMO turno de caja), y
 * guarda por separado las dos fechas que no se pueden confundir:
 *
 *   economic_at   cuándo entró el dinero (el `paid_at` original);
 *   performed_at  cuándo se corrigió, de verdad, hoy.
 *
 * Es inmutable (lo impone el modelo): una reclasificación equivocada no se
 * edita, se compensa con otra operación documentada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reclassifications', function (Blueprint $table): void {
            $table->id();

            // UNA reclasificación por cobro. Es lo que hace el procedimiento
            // idempotente también en la base: dos ejecuciones a la vez no pueden
            // reclasificar dos veces el mismo dinero.
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();

            // Qué pasó a ser: 'trainer_commission' (piso de entrenador).
            $table->string('kind', 40);

            $table->decimal('amount', 12, 2);
            $table->string('method', 40)->nullable();

            // Cuándo entró el dinero. NO es cuándo se corrigió.
            $table->timestamp('economic_at');

            // El turno donde estaba y sigue estando el dinero.
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->restrictOnDelete();

            // Lo que lo sustituye. `restrict` a propósito: mientras exista la
            // constancia, nadie puede borrar por debajo una de sus piezas.
            $table->foreignId('agreement_id')->nullable()->constrained('trainer_commission_agreements')->restrictOnDelete();
            $table->foreignId('charge_id')->nullable()->unique()->constrained('trainer_commission_charges')->restrictOnDelete();
            $table->foreignId('receivable_id')->nullable()->constrained('receivables')->restrictOnDelete();
            $table->foreignId('receivable_payment_id')->nullable()->unique()->constrained('receivable_payments')->restrictOnDelete();

            // El comprobante que recibió quien pagó: sigue siendo el que vale
            // para ese dinero (por eso el abono nuevo no imprime otro).
            $table->foreignId('original_receipt_id')->nullable()->constrained('receipts')->restrictOnDelete();

            // El borrador de factura del concepto equivocado, si se canceló.
            $table->foreignId('cancelled_invoice_id')->nullable()->constrained('electronic_invoices')->restrictOnDelete();

            // Lo que la reversión le hizo a la vigencia del socio.
            $table->date('membership_end_before')->nullable();
            $table->date('membership_end_after')->nullable();
            $table->integer('membership_days_removed')->nullable();

            $table->string('reason', 500);
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->string('performed_by_name', 120);
            $table->timestamp('performed_at');
            // Qué procedimiento y qué versión lo hizo.
            $table->string('procedure', 80);

            // Cifras de antes y de después, sin datos personales: lo que
            // permite comprobar, meses después, que el turno y el día cuadraban.
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();

            $table->timestamps();

            $table->index('cash_shift_id');
            $table->index('kind');
        });

        // INMUTABLE TAMBIÉN EN LA BASE, no solo en el modelo (revisión de
        // seguridad H-5). En producción la aplicación corre con un rol que solo
        // hace DML y la migración con el DUEÑO; los privilegios por defecto le
        // dan a la aplicación también modificar, borrar y vaciar. Aquí se le
        // quitan a TODO rol que no sea el dueño (y a PUBLIC), sea cual sea la
        // conexión con la que se migre: leer e insertar es todo lo que el
        // procedimiento hace. En SQLite, o si la aplicación ya es la dueña
        // (entornos locales), no hay nada que quitar.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $roles = DB::select(<<<'SQL'
                select distinct case when a.grantee = 0 then 'PUBLIC' else a.grantee::regrole::text end as rol
                  from pg_class c, aclexplode(c.relacl) a
                 where c.oid = 'payment_reclassifications'::regclass
                   and a.privilege_type in ('UPDATE', 'DELETE', 'TRUNCATE')
                   and a.grantee <> c.relowner
                SQL);
            foreach ($roles as $r) {
                // `regrole::text` ya entrecomilla el nombre cuando hace falta.
                DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON payment_reclassifications FROM '.$r->rol);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reclassifications');
    }
};
