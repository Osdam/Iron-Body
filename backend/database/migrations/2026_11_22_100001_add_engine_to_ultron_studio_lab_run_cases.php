<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 * down(): quita la columna (en SQLite, `ALTER TABLE … DROP COLUMN`, que
 * tampoco reconstruye la tabla: ningún disparador nombra la columna). Se pierde
 * el recorrido guardado de las ejecuciones del motor; las simulaciones no
 * pierden nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ultron_studio_lab_run_cases', function (Blueprint $table): void {
            $table->json('engine')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ultron_studio_lab_run_cases', function (Blueprint $table): void {
            $table->dropColumn('engine');
        });
    }
};
