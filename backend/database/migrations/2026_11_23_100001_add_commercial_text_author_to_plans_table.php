<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PT-14 · A (diseño `docs/ultron-studio/plans/informes-cierre/diseno-arq-pt14.md`
 * §3 D4, §4.1): QUIÉN ESCRIBIÓ el texto comercial vigente de un plan —su nombre
 * y sus beneficios— o lo puso a la venta.
 *
 * `commercial_text_by` → `admins`, nulo si la cuenta se elimina: el plan se
 * queda. Es el mismo patrón que `archived_by` (2026_10_31_100002). La escribe
 * `PlanController` (y `PlanArchive` al reactivar) en la MISMA transacción que
 * el texto, y la leen los cuatro ojos de la publicación de la voz: quien la
 * escribió no publica la voz que la recoge. Oculta en el listado público
 * (`Plan::$hidden`).
 *
 * SIN RELLENO: los textos anteriores quedan con null («sin autor registrado»,
 * D10). No sale de `audit_logs`: esa tabla nació auto-reportada por el CRM y
 * nunca sirve para autorizar.
 *
 * PostgreSQL 14: columna anulable sin valor por defecto, así que no reescribe
 * la tabla. El ALTER toma ACCESS EXCLUSIVE un instante sobre `plans`, que cada
 * cobro referencia con FOR KEY SHARE: si la sesión no trae `lock_timeout` (el
 * de `ironbody-migrate` es de 5 s), `SET LOCAL lock_timeout = '5s'`, como
 * E19-3. Con una transacción larga sobre la tabla, cae limpia (55P03) y se
 * reintenta. Los privilegios no se tocan: `iron_app` tiene DML sobre `plans` a
 * nivel de tabla y la columna nueva lo hereda.
 *
 * SQLite: la llave reconstruye la tabla, como hizo `archived_by`. `plans` no
 * tiene índices parciales: su único índice (`plans_active_billing_idx`) es
 * total y se conserva.
 *
 * Aditiva y reversible: `down()` quita la columna y su llave (y con ellas la
 * autoría; el texto y la auditoría se quedan).
 */
return new class extends Migration
{
    public const LOCK_TIMEOUT = '5s';

    public function up(): void
    {
        $this->bounded(static function (): void {
            Schema::table('plans', function (Blueprint $table): void {
                $table->foreignId('commercial_text_by')->nullable()->constrained('admins')->nullOnDelete();
            });
        });
    }

    public function down(): void
    {
        $this->bounded(static function (): void {
            Schema::table('plans', function (Blueprint $table): void {
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
