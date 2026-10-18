<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · hito G: condiciones estructuradas de las reglas. Contrato:
 * docs/ultron-studio/ULTRON_STUDIO_G_CONTRACT.md, §A3 y §A7.
 *
 * Una sola columna: `ultron_studio_rules.conditions`, json, NULL. NULL es una
 * regla documental (sin condiciones): todas las filas de F quedan así, y su
 * huella no cambia (`ruleHash` solo incluye `conditions` cuando no es NULL).
 * Nada más del Studio cambia: versiones, reseñas y auditoría siguen igual.
 *
 *  · `json`, no `jsonb`, como el resto del Studio (cabecera de
 *    2026_10_18_100001). El servicio la lee y la escribe entera: no se
 *    filtra ni se ordena por ella.
 *  · Sin CHECK: la forma (y el tope de 4 KB) la garantiza el servicio.
 *  · Sin índice: ninguna consulta la usa como filtro, y la tabla tiene una
 *    fila por regla.
 *
 * PostgreSQL 14: `ADD COLUMN … json` sin DEFAULT solo escribe en el catálogo.
 * No reescribe la tabla ni lee sus filas, pero pide un ACCESS EXCLUSIVE breve.
 * En el despliegue va con `lock_timeout` (ironbody-migrate): si una transacción
 * larga tiene la tabla, la migración falla en vez de quedarse en cola y parar
 * a todos los que vienen detrás. Laravel la envuelve en una transacción, así
 * que no queda registrada y se reintenta. No toca disparadores ni CHECK. El rol
 * de la aplicación (solo DML) tiene sus privilegios sobre la TABLA, y esos
 * cubren las columnas nuevas: no hace falta GRANT.
 *
 * SQLite (la suite): ADD COLUMN y, desde 3.35, DROP COLUMN son nativos y no
 * reconstruyen la tabla. Por debajo de 3.35, Laravel reconstruye la tabla para
 * el DROP, y la reconstrucción se lleva sus disparadores (`usr_forbid_delete`;
 * ver la cabecera de 2026_10_18_100002). Por eso, en los dos sentidos, se lee
 * la definición de los disparadores de la tabla ANTES del cambio y se vuelve a
 * ejecutar la de los que falten DESPUÉS. Es el mismo texto que escribió F, con
 * su mensaje `ultron_studio:`, sin copiarlo aquí. Va todo en una transacción
 * (en SQLite Laravel no la abre), para que la tabla no se quede nunca sin su
 * guarda.
 *
 * down() quita la columna Y SU CONTENIDO (las condiciones de las reglas). Para
 * volver el backend a F no hace falta: la columna se queda y F la ignora
 * (contrato §A7, «Compatibilidad»).
 */
return new class extends Migration
{
    private const TABLE = 'ultron_studio_rules';

    private const COLUMN = 'conditions';

    public function up(): void
    {
        $this->alterKeepingTriggers(function (Blueprint $table): void {
            $table->json(self::COLUMN)->nullable();
        });
    }

    public function down(): void
    {
        $this->alterKeepingTriggers(function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }

    private function alterKeepingTriggers(Closure $change): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table(self::TABLE, $change);

            return;
        }

        DB::transaction(function () use ($change): void {
            $before = $this->sqliteTriggers();

            Schema::table(self::TABLE, $change);

            foreach (array_diff_key($before, $this->sqliteTriggers()) as $createTrigger) {
                DB::statement($createTrigger);
            }
        });
    }

    /** @return array<string, string> nombre → su CREATE TRIGGER, tal como lo guarda SQLite */
    private function sqliteTriggers(): array
    {
        $triggers = [];
        foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [self::TABLE]) as $row) {
            $triggers[$row->name] = $row->sql;
        }

        return $triggers;
    }
};
