<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tres preguntas que un pago no sabía contestar.
 *
 * 1. ¿QUÉ PERIODO PAGÓ? «Fecha de pago» hacía dos trabajos a la vez: decía
 *    cuándo entró el dinero y desde cuándo empezaba la membresía. Quien pagaba
 *    hoy para empezar el lunes obligaba a fechar el cobro el lunes, y eso
 *    movía el ingreso de día en los reportes. Ahora el cobro es de hoy y el
 *    inicio pedido va aparte (`starts_on`); el periodo que de verdad cubrió
 *    —que en una renovación anticipada no empieza el día pedido, sino al final
 *    de la vigente— queda congelado en `period_start` / `period_end`. Sin eso
 *    el perfil del socio no puede contar su historia: `users` solo guarda el
 *    periodo actual y lo sobrescribe en cada pago.
 *
 * 2. ¿POR DÓNDE PAGÓ? App (pasarela) o gimnasio (caja). Se sabía por el código
 *    que creaba la fila ({@see \App\Support\Caja\PaymentOrigin}) pero no se
 *    guardaba, así que Analítica no podía separarlos.
 *
 * 3. ¿QUIÉN LO REGISTRÓ? La traza estaba en `audit_logs`, fuera del alcance de
 *    cualquier consulta de negocio. Nombre y rol se congelan como instantánea:
 *    un informe de hace seis meses tiene que seguir diciendo que cobró
 *    «Recepción» aunque esa persona hoy sea administradora o ya no esté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->date('starts_on')->nullable()->after('paid_at');
            $table->date('period_start')->nullable()->after('starts_on');
            $table->date('period_end')->nullable()->after('period_start');

            // counter | gateway | legacy | automation. Nullable solo por los
            // datos que el relleno de abajo no sepa clasificar.
            $table->string('origin', 16)->nullable()->after('cash_shift_id');

            $table->foreignId('registered_by')->nullable()->after('origin')
                ->constrained('admins')->nullOnDelete();
            $table->string('registered_by_name', 120)->nullable()->after('registered_by');
            $table->string('registered_by_role', 60)->nullable()->after('registered_by_name');

            $table->index('origin');
            $table->index('registered_by');
        });

        $this->backfillOrigin();
        $this->backfillRegisteredBy();
        $this->backfillLegacyPeriods();
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['origin']);
            $table->dropIndex(['registered_by']);
            $table->dropConstrainedForeignId('registered_by');
            $table->dropColumn([
                'starts_on', 'period_start', 'period_end',
                'origin', 'registered_by_name', 'registered_by_role',
            ]);
        });
    }

    /**
     * Origen de los pagos que ya existían, con las mismas reglas que
     * PaymentOrigin documenta. El orden importa: la primera que aplica gana.
     *
     * No se usa `method = 'nequi'` para detectar la app: «Nequi» también es lo
     * que elige recepción cuando el socio transfiere en el mostrador. Lo que
     * distingue a la pasarela es que existe una transacción con esa referencia.
     */
    private function backfillOrigin(): void
    {
        // Pagos migrados del sistema anterior.
        DB::table('payments')->whereNull('origin')
            ->where('reference', 'like', 'MIGR-%')
            ->update(['origin' => 'legacy']);

        // Pasarela: hay una transacción de Wompi/Nequi detrás.
        if (Schema::hasTable('payment_transactions')) {
            DB::table('payments')->whereNull('origin')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('payment_transactions')
                    ->whereColumn('payment_transactions.reference', 'payments.reference'))
                ->update(['origin' => 'gateway']);
        }

        // `wompi` solo lo escribe la pasarela.
        DB::table('payments')->whereNull('origin')
            ->whereRaw('LOWER(method) = ?', ['wompi'])
            ->update(['origin' => 'gateway']);

        // Todo lo demás lo registró el CRM: con turno (desde que existe la
        // caja) o sin él (antes), pero siempre desde el mostrador.
        DB::table('payments')->whereNull('origin')->update(['origin' => 'counter']);
    }

    /**
     * Quién registró cada cobro del mostrador, sacado de su traza de auditoría.
     *
     * Solo lo que consta. Un pago sin traza se queda sin responsable: inventar
     * uno —el que abrió la caja, por ejemplo— atribuiría a alguien un cobro que
     * quizá hizo otra persona en su turno.
     */
    private function backfillRegisteredBy(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $roles = DB::table('admins')->pluck('role', 'id');

        DB::table('audit_logs')
            ->where('action', 'create')
            ->whereIn('entity', ['pago', 'payment'])
            ->whereNotNull('entity_id')
            ->orderBy('id')
            ->select(['id', 'entity_id', 'actor_id', 'actor_name', 'actor_role'])
            ->chunkById(500, function ($trazas) use ($roles): void {
                foreach ($trazas as $traza) {
                    $paymentId = (int) $traza->entity_id;
                    $adminId = is_numeric($traza->actor_id) ? (int) $traza->actor_id : null;
                    if ($paymentId <= 0) {
                        continue;
                    }

                    DB::table('payments')
                        ->where('id', $paymentId)
                        ->whereNull('registered_by_name')
                        ->where('origin', 'counter')
                        ->update([
                            // La cuenta puede haberse borrado: el id solo se
                            // enlaza si todavía existe, el nombre se queda.
                            'registered_by' => $adminId !== null && $roles->has($adminId) ? $adminId : null,
                            'registered_by_name' => $traza->actor_name,
                            'registered_by_role' => $traza->actor_role
                                ?? ($adminId !== null ? $roles->get($adminId) : null),
                        ]);
                }
            });
    }

    /**
     * Los pagos migrados sí traen su periodo: es la membresía de la que salen.
     * Se toma de las fechas del socio solo cuando ese pago es el ÚNICO migrado
     * del socio; con varios, las fechas de `users` son de la última membresía y
     * atribuirlas a todas sería falso. El importador las rellena a partir de
     * ahora con el dato exacto de cada membresía.
     */
    private function backfillLegacyPeriods(): void
    {
        $unicos = DB::table('payments')
            ->where('origin', 'legacy')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('user_id');

        foreach ($unicos->chunk(500) as $lote) {
            $socios = DB::table('users')
                ->whereIn('id', $lote->all())
                ->whereNotNull('membership_start_date')
                ->whereNotNull('membership_end_date')
                ->get(['id', 'membership_start_date', 'membership_end_date']);

            foreach ($socios as $socio) {
                DB::table('payments')
                    ->where('user_id', $socio->id)
                    ->where('origin', 'legacy')
                    ->whereNull('period_start')
                    ->update([
                        'period_start' => substr((string) $socio->membership_start_date, 0, 10),
                        'period_end' => substr((string) $socio->membership_end_date, 0, 10),
                    ]);
            }
        }
    }
};
