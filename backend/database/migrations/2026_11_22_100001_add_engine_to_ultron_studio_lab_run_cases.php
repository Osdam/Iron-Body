<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EL RECORRIDO DEL MOTOR DE UN CASO DEL LABORATORIO (apartado 5 del cierre de
 * ULTRON STUDIO; diseño `docs/ultron-studio/plans/informes-cierre/diseno-arq-lab.md`
 * §3.1 y §3.2, B0). Lo revisa database-specialist.
 *
 * ⚠ PRODUCCIÓN: necesita la autorización del propietario antes de ejecutarse
 * (`php artisan migrate --force`). Es aditiva: una columna JSON NULL y nada
 * más. Ninguna fila cambia; las ejecuciones de simulación la dejan NULL.
 *
 * `ultron_studio_lab_run_cases.engine` (json NULL, ≤ 16 KB por construcción
 * en el código): el camino de nodos, la estrategia validada, cada borrador con
 * su comprobación y su revisor, quién pidió cada reescritura y la predicción
 * del texto final. Solo la escriben las ejecuciones del motor
 * (`prompt_version` = `motor-…`); en una simulación queda NULL y su
 * `results_hash` no cambia (prueba dorada de T6).
 *
 * LAS GUARDAS DE `2026_10_19_100003` LA CUBREN SIN TOCARLAS:
 *  · es MUTABLE mientras el caso no sea terminal y, una vez terminal (done,
 *    error o skipped), `uslc_forbid_terminal_update` rechaza CUALQUIER UPDATE
 *    de la fila, también de esta columna (mira `OLD.status`, no una lista de
 *    columnas). La escribe el mismo UPDATE `running → done|error` que liquida
 *    el caso, como `checks`, `judge` o `calls`;
 *  · no es de las que un caso congela al crearse: no entra en
 *    `uslc_forbid_immutable_update` (`UPDATE OF` su lista).
 * En PostgreSQL, `ADD COLUMN … NULL` sin valor por defecto solo toca el
 * catálogo (sin reescribir la tabla) y no toca los disparadores. En SQLite,
 * `Schema::table` con una columna nueva es un `ALTER TABLE … ADD COLUMN`, que
 * NO reconstruye la tabla: los 11 disparadores siguen (lo comprueba la prueba,
 * que además recorre down() y up()).
 *
 * down() (C-A1 de database-specialist): SE NIEGA si algún caso lleva `engine`.
 * Quitar la columna borraría el recorrido de las ejecuciones del motor y
 * rompería PARA SIEMPRE la verificación de su `results_hash` (la huella se
 * recalcula de las filas con `engine`; sin él, aunque se vuelva a hacer up(),
 * no coincide y el veredicto se rechaza), y esos casos terminales la base los
 * declara inmutables. Primero el cerrojo (PostgreSQL: ACCESS EXCLUSIVE, el
 * mismo que toma el DROP) y después el recuento, en una transacción: ningún
 * caso del motor se liquida entre los dos. Sin filas con `engine`, quita la
 * columna (en SQLite, `ALTER TABLE … DROP COLUMN`, que tampoco reconstruye la
 * tabla: ningún disparador nombra la columna); las simulaciones no pierden
 * nada. Para volver al código anterior no hace falta: la columna NULL no le
 * afecta. Mismo patrón que `refuseWithRows()` de `2026_11_02_100001`.
 */
return new class extends Migration
{
    private const TABLE = 'ultron_studio_lab_run_cases';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->json('engine')->nullable();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            // El cerrojo ANTES de contar: nadie liquida un caso del motor entre el recuento y el DROP.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
            }
            $this->refuseWithRows();

            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('engine');
            });
        });
    }

    private function refuseWithRows(): void
    {
        $filas = DB::table(self::TABLE)->whereNotNull('engine')->count();
        if ($filas > 0) {
            throw new RuntimeException("ultron_studio (apartado 5): down() se niega a quitar la columna engine con {$filas} casos del recorrido del motor que la llevan. "
                .'Se perdería su recorrido y su results_hash ya no se podría verificar nunca (tampoco tras volver a hacer up()). '
                .'Para volver al código anterior no hace falta revertir el esquema: la columna NULL no le afecta.');
        }
    }
};
