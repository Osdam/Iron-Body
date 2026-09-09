<?php

use App\Enums\DebtorType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién debe, de forma inequívoca.
 *
 * `receivables.member_id` obligaba a que TODO deudor fuera socio. En la práctica
 * también fía el gimnasio a su propio personal, y el personal no está en
 * `members`: las cuentas del CRM viven en `admins` y los entrenadores en
 * `trainers`. Sin esto, fiarle a la recepcionista solo se podía apuntar
 * escribiendo su nombre, y un nombre no es una identidad: tres meses después,
 * con dos «Alejandro Casas», la deuda no se puede reclamar.
 *
 * `member_id` NO se retira. Sigue siendo la clave foránea real cuando el deudor
 * es un socio, y de ella cuelgan el índice (member_id, status), la relación del
 * modelo y el filtrado del entrenador a sus socios. Retirarla obligaría a
 * reescribir todo eso a cambio de nada. La invariante —cuando debtor_type es
 * 'member', member_id es debtor_id, y en cualquier otro caso es null— vive en un
 * único sitio, {@see \App\Services\Caja\ReceivableService::create()}, y hay un
 * test que comprueba que las dos no se contradicen.
 *
 * El backfill NO inventa nada: las cuentas que ya existen son todas de socios,
 * así que se copia el dato que ya estaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table): void {
            $table->string('debtor_type', 20)->nullable()->after('member_id');
            $table->unsignedBigInteger('debtor_id')->nullable()->after('debtor_type');

            $table->index(['debtor_type', 'debtor_id']);
        });

        // Lo que ya existe es de socios, por construcción: hasta ahora la
        // columna no admitía otra cosa.
        DB::table('receivables')->whereNull('debtor_type')->update([
            'debtor_type' => DebtorType::MEMBER->value,
            'debtor_id' => DB::raw('member_id'),
        ]);

        // Y ahora sí puede quedar vacía: un administrador que debe no es socio.
        Schema::table('receivables', function (Blueprint $table): void {
            $table->unsignedBigInteger('member_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Las cuentas de quien no es socio no caben en el esquema anterior.
        // Se anulan en vez de borrarse: una deuda no desaparece porque cambie
        // la forma de guardarla, y quien revierta esto tiene que verlas.
        DB::table('receivables')
            ->where('debtor_type', '!=', DebtorType::MEMBER->value)
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        DB::table('receivables')->whereNull('member_id')->delete();

        Schema::table('receivables', function (Blueprint $table): void {
            $table->dropIndex(['debtor_type', 'debtor_id']);
            $table->dropColumn(['debtor_type', 'debtor_id']);
            $table->unsignedBigInteger('member_id')->nullable(false)->change();
        });
    }
};
