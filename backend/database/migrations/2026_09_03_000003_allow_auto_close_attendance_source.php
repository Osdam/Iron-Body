<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admite el cierre automático de jornada como origen de una asistencia.
 *
 * El lector facial del gimnasio cierra al final del día las jornadas de quienes
 * entraron y nunca marcaron salida, y las envía con `source = 'auto-close'`. Ese
 * valor no existía: ni en la validación ni en este CHECK. El resultado fue que
 * desde el 1 de septiembre el cliente reintentaba el mismo registro cada quince
 * segundos, siempre con 422, y su cola quedó bloqueada por la cabecera: durante
 * dos días y medio no entró NINGUNA asistencia, ni siquiera las de gente
 * cruzando la puerta con normalidad.
 *
 * Se añade un valor, no se quita el control: la lista sigue cerrada. Y sigue
 * siendo un valor DISTINTO a propósito — guardar una salida deducida por una
 * máquina como `manual` diría que alguien la registró, y como `facial` que un
 * rostro fue leído. Ninguna de las dos cosas ocurrió, y quien mire el historial
 * necesita poder distinguirlas.
 */
return new class extends Migration
{
    private const ORIGENES = ['facial', 'manual', 'auto-close'];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->recrearCheck(self::ORIGENES);

            return;
        }

        // SQLite —donde corren las pruebas— no sabe alterar un CHECK: habría que
        // reconstruir la tabla entera. Se deja la columna como texto y la lista
        // cerrada la sostiene la validación, que es el mismo gate que atraviesa
        // cualquier petición real.
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('source', 255)->default('manual')->change();
        });
    }

    /**
     * Vuelve a la lista anterior.
     *
     * Si ya se guardaron cierres automáticos, PostgreSQL rechazará el CHECK más
     * estrecho y la migración fallará en vez de borrarlos. Es lo correcto:
     * revertir un esquema no puede llevarse por delante asistencias reales. Para
     * poder revertir habría que decidir antes qué hacer con esas filas.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->recrearCheck(['facial', 'manual']);
        }
    }

    /** @param  string[]  $origenes */
    private function recrearCheck(array $origenes): void
    {
        $lista = implode(', ', array_map(fn (string $o) => "'".str_replace("'", "''", $o)."'", $origenes));

        DB::statement('ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_source_check');
        DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_source_check CHECK (source::text = ANY (ARRAY[{$lista}]::text[]))");
    }
};
