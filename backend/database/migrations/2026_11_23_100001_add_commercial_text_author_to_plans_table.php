<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PT-14 · A (diseño `docs/ultron-studio/plans/informes-cierre/diseno-arq-pt14.md`
 * §3 D4, §4.1; corregido por B-1 de la revisión de security, decisión del
 * lead): las DOS autorías del texto comercial de un plan, separadas.
 *
 *  - `commercial_text_by`: quién ESCRIBIÓ el texto vigente (nombre y
 *    beneficios). Cambia solo cuando cambia el texto, y en el alta.
 *  - `commercial_sale_by`: quién lo PUSO A LA VENTA (alta vendible, encenderlo,
 *    darle precio o vigencia, reactivarlo). Antes era la misma columna, y
 *    encender un plan sobrescribía a quien escribió el texto.
 *
 * Las dos → `admins`, nulas si la cuenta se elimina: el plan se queda. Es el
 * mismo patrón que `archived_by` (2026_10_31_100002). Las escribe
 * `PlanController` (y `PlanArchive` al reactivar) en la MISMA transacción que
 * el cambio, y las leen los cuatro ojos de la publicación de la voz: ni quien
 * escribió el texto ni quien lo puso a la venta publican la voz que lo recoge.
 * Ocultas en el listado público (`Plan::$hidden`).
 *
 * SIN RELLENO: los textos anteriores quedan con null («sin autor registrado»,
 * D10). No sale de `audit_logs`: esa tabla nació auto-reportada por el CRM y
 * nunca sirve para autorizar.
 *
 * PostgreSQL 14: columnas anulables sin valor por defecto, así que no reescribe
 * la tabla. El ALTER toma ACCESS EXCLUSIVE un instante sobre `plans`, que cada
 * cobro referencia con FOR KEY SHARE: si la sesión no trae `lock_timeout` (el
 * de `ironbody-migrate` es de 5 s), `SET LOCAL lock_timeout = '5s'`, como
 * E19-3. Con una transacción larga sobre la tabla, cae limpia (55P03) y se
 * reintenta. Los privilegios no se tocan: `iron_app` tiene DML sobre `plans` a
 * nivel de tabla y las columnas nuevas lo heredan.
 *
 * SQLite: la llave reconstruye la tabla, como hizo `archived_by`. `plans` no
 * tiene índices parciales: su único índice (`plans_active_billing_idx`) es
 * total y se conserva.
 *
 * Aditiva y reversible: `down()` quita las dos columnas y sus llaves (y con
 * ellas las autorías; el texto y la auditoría se quedan).
 */
return new class extends Migration
{
    public const LOCK_TIMEOUT = '5s';

    public function up(): void
    {
        $this->bounded(static function (): void {
            Schema::table('plans', function (Blueprint $table): void {
                $table->foreignId('commercial_text_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->foreignId('commercial_sale_by')->nullable()->constrained('admins')->nullOnDelete();
            });
        });
    }

    public function down(): void
    {
        $this->bounded(static function (): void {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('commercial_sale_by');
                $table->dropConstrainedForeignId('commercial_text_by');
            });
        });
    }

    /**
     * En PostgreSQL, el ALTER con su límite de espera, en una transacción (un
     * SAVEPOINT dentro de la del migrador): SET LOCAL vale hasta su final. En
     * SQLite, tal cual, como lo corre el migrador (sin transacción de esquema).
     */
    private function bounded(Closure $alter): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $alter();

            return;
        }
        DB::transaction(function () use ($alter): void {
            $this->boundLockWait();
            $alter();
        });
    }

    /**
     * Sin un límite en la sesión, esta migración no espera más de
     * LOCK_TIMEOUT por un cerrojo. Si la sesión ya trae uno, manda ese.
     */
    private function boundLockWait(): void
    {
        $sentencia = "SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'";
        if (DB::connection()->pretending()) {
            DB::statement($sentencia);

            return;
        }
        if ((string) DB::scalar("SELECT current_setting('lock_timeout')") === '0') {
            DB::statement($sentencia);
        }
    }
};
