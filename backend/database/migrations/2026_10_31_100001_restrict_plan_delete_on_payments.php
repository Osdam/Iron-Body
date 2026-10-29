<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UN PLAN CON COBROS YA NO SE PUEDE BORRAR (solo PostgreSQL).
 *
 * `payments_plan_id_foreign` nació ON DELETE SET NULL. El 16-10-2026 se borró
 * el plan 22 «TOTAL ACCESS - ELITE» y sus 31 cobros se quedaron con `plan_id`
 * nulo, en silencio: el CRM los pinta «Pago sin plan» y la transferencia de
 * membresía no encuentra su origen. `PlanController::destroy` ya responde 409
 * con dependencias; esto es la segunda puerta, la que no depende del código.
 *
 * RESTRICT y no NO ACTION: se comprueba en el acto, no al final de la sentencia.
 *
 * CÓMO SE CAMBIA, para no dejar la tabla de cobros bloqueada:
 *   · FUERA de la transacción de la migración (`$withinTransaction = false`):
 *     dentro de una sola, el VALIDATE correría con el cerrojo fuerte del DROP
 *     todavía puesto.
 *   · `lock_timeout` de 5 s: si hay una transacción larga sobre `payments` o
 *     `plans`, falla (55P03) en vez de encolar detrás a toda la caja. Se
 *     reintenta después; la migración no queda registrada.
 *   · DROP + ADD … NOT VALID en UN solo ALTER: atómico (nunca hay un instante
 *     sin llave) y sin recorrer la tabla con el cerrojo fuerte. Después
 *     VALIDATE, que la recorre con un cerrojo que no bloquea escrituras. Los
 *     datos ya cumplen: la llave anterior exigía lo mismo salvo al borrar.
 *
 * Comprueba que la llave existe con ese nombre y apunta a `plans(id)` antes de
 * tocarla: si no, falla en vez de adivinar. Si ya está como se pide, solo
 * valida lo que hubiera quedado sin validar (una ejecución cortada a medias).
 *
 * SQLITE (la suite) NO SE TOCA: cambiar una llave reconstruye `payments`, y
 * Laravel recrearía el índice parcial `payments_gateway_reference_unique` sin
 * su WHERE (un único parcial pasaría a único total). Allí sigue SET NULL; la
 * guarda del controlador cubre los dos motores.
 *
 * Reversible: `down()` devuelve SET NULL con el mismo procedimiento.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const FK = 'payments_plan_id_foreign';

    public function up(): void
    {
        $this->swap(from: 'n', to: 'RESTRICT', toCode: 'r');
    }

    public function down(): void
    {
        $this->swap(from: 'r', to: 'SET NULL', toCode: 'n');
    }

    /** `confdeltype`: a = no action, r = restrict, c = cascade, n = set null, d = set default. */
    private function swap(string $from, string $to, string $toCode): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $actual = DB::selectOne(
            "SELECT confdeltype, convalidated, pg_get_constraintdef(oid) AS def FROM pg_constraint
              WHERE conname = ? AND conrelid = 'payments'::regclass AND contype = 'f'",
            [self::FK],
        );
        if ($actual === null) {
            throw new RuntimeException('No existe la llave '.self::FK.' en payments: no se adivina cuál cambiar.');
        }
        if (! str_contains($actual->def, 'FOREIGN KEY (plan_id) REFERENCES plans(id)')) {
            throw new RuntimeException('La llave '.self::FK.' no es la esperada ('.$actual->def.'): no se toca.');
        }

        DB::statement("SET lock_timeout = '5s'");
        try {
            if ($actual->confdeltype !== $toCode) {
                if ($actual->confdeltype !== $from) {
                    throw new RuntimeException('La llave '.self::FK.' no es la esperada ('.$actual->def.'): no se toca.');
                }
                DB::statement(
                    'ALTER TABLE payments DROP CONSTRAINT '.self::FK.', '.
                    'ADD CONSTRAINT '.self::FK.' FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE '.$to.' NOT VALID'
                );
            }
            DB::statement('ALTER TABLE payments VALIDATE CONSTRAINT '.self::FK);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }
};
