<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SOLICITAR VALORACIÓN desde la app (CONTRATO_SOLICITUDES_VALORACION.md §1).
 *
 * Una SOLICITUD no es una cita: no tiene hora ni entrenador. Es la petición
 * del socio que administración convierte en una cita NORMAL de la agenda
 * (`valuation_appointments`) con el asistente de siempre. Aquí solo nace la
 * tabla de las peticiones; no se toca ninguna tabla existente.
 *
 * LLAVES QUE SIGUEN SIENDO CIERTAS CON DOS PETICIONES A LA VEZ:
 *  · `client_request_id` UNIQUE: el reintento de la app encuentra su solicitud.
 *  · UNA PENDIENTE POR SOCIO: índice único parcial `(member_id) WHERE
 *    status = 'pending'`. El servicio ya serializa por la fila del socio; esto
 *    es la segunda puerta. Escrito a mano: el constructor de esquemas no expresa
 *    índices parciales, y `CREATE UNIQUE INDEX … WHERE` vale igual en
 *    PostgreSQL y en SQLite.
 *
 * REFERENCIAS:
 *  · socio → CASCADE, como las citas: la petición se va con el socio;
 *  · cita → SET NULL: si la cita desaparece, la solicitud queda como historia;
 *  · quien la gestionó → SET NULL, con el nombre congelado en `handled_by_name`.
 *
 * ÍNDICES: `(member_id, created_at)` sirve el estado del socio y el borrado en
 * cascada (PostgreSQL no indexa las FK solo); `(status, created_at)` la bandeja
 * del CRM; `appointment_id` el SET NULL al borrar una cita.
 *
 * PostgreSQL: CHECK del dominio de `status` y de que un rechazo lleva motivo,
 * como la agenda (2026_10_17_200002). Sin `lock_timeout` en la sesión se pone
 * `SET LOCAL lock_timeout = '5s'`: las FK piden SHARE ROW EXCLUSIVE sobre
 * `members` y `valuation_appointments`, y no se espera indefinidamente por ellas.
 *
 * Reversible: down() borra el índice parcial y la tabla. Se pierden las
 * solicitudes hechas desde que existe (respaldarla antes en producción).
 */
return new class extends Migration
{
    private const PENDING_INDEX = 'valuation_requests_one_pending_per_member';

    private const LOCK_TIMEOUT = '5s';

    public function up(): void
    {
        $pg = DB::connection()->getDriverName() === 'pgsql';
        if ($pg) {
            $this->boundLockWait();
        }

        Schema::create('valuation_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // pending | scheduled | attended | rejected | cancelled
            $table->string('status', 16);
            // app (la única fuente de la v1)
            $table->string('source', 16)->default('app');
            // Idempotencia del botón de la app (un id por intento, reutilizado
            // en sus reintentos).
            $table->uuid('client_request_id')->nullable()->unique();
            // La cita de la agenda con la que se atendió, si la hay.
            $table->foreignId('appointment_id')->nullable()->constrained('valuation_appointments')->nullOnDelete();
            $table->foreignId('handled_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('handled_by_name')->nullable();
            $table->timestamp('handled_at')->nullable();
            // Obligatorio al rechazar (lo exige la API y, en PostgreSQL, un CHECK).
            // Texto plano: lo lee el socio.
            $table->string('rejection_reason', 500)->nullable();
            // Concurrencia optimista del CRM.
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->index(['member_id', 'created_at'], 'val_requests_member_created_idx');
            $table->index(['status', 'created_at'], 'val_requests_status_created_idx');
            $table->index('appointment_id', 'val_requests_appointment_idx');
        });

        DB::statement('CREATE UNIQUE INDEX '.self::PENDING_INDEX." ON valuation_requests (member_id) WHERE status = 'pending'");

        if ($pg) {
            DB::statement("ALTER TABLE valuation_requests ADD CONSTRAINT valuation_requests_status_chk CHECK (status IN ('pending', 'scheduled', 'attended', 'rejected', 'cancelled'))");
            DB::statement("ALTER TABLE valuation_requests ADD CONSTRAINT valuation_requests_rejection_reason_chk CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL)");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::PENDING_INDEX);
        Schema::dropIfExists('valuation_requests');
    }

    /** Sin un límite en la sesión, no se espera más de LOCK_TIMEOUT por un cerrojo (55P03 y se reintenta). */
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
