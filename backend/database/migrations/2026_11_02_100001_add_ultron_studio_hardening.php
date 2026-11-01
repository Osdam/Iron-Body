<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · HITO 7 (seguridad y producción), paquete DB7: ENDURECIMIENTO
 * DE LAS TABLAS DE SOLO INSERCIÓN. Contrato:
 * docs/ultron-studio/ULTRON_STUDIO_H7_CONTRACT.md, DB7 (a), (b) y (d); origen:
 * AS-3 / N-3 y Enmienda 2 §E2.5 del contrato H5. SOLO ADITIVA: no crea tablas
 * ni columnas, no toca ninguna fila y no redeclara `ultron_studio_forbid()` (de
 * F: su definición no cambia ni un byte).
 *
 * (a) LA CADENA (`ultron_studio_publications`) ES CONSECUTIVA Y ENCADENADA.
 * Hasta ahora lo garantizaba solo el servicio (`seq` = cabeza + 1 y
 * `previous_publication_id` = la cabeza). Con el DML del rol de la aplicación
 * se podía insertar `seq = 2147483647`: esa fila se convertía en la cabeza, no
 * verificaba y la cadena se quedaba sin salida (volver a Legacy, 500 por
 * 22003). Ahora, en la base:
 *  · `uspub_chain_next` (BEFORE INSERT, los dos motores): por ENCIMA de la
 *    cabeza solo cabe la SIGUIENTE (`seq` = cabeza + 1) y apuntando a la cabeza
 *    (`previous_publication_id`; NULL con la cadena vacía, donde `seq` = 1). Lo
 *    demás es P0001 en PG (23000 en SQLite) con el texto de siempre
 *    («ultron_studio: … (INSERT en ultron_studio_publications)»).
 *  · Lo que NO pasa de la cabeza no lo decide el disparador: un `seq` repetido
 *    lo para el UNIQUE de `seq` (23505), que es justo la carrera perdida que el
 *    servicio traduce a 409 `publication_moved` (contrato H5 §A5, paso 12); un
 *    `seq` < 1 lo para `uspub_seq_chk` (23514). Si el disparador lo rechazara
 *    antes, la carrera dejaría de ser un 409 y pasaría a ser un 500. Como la
 *    cadena es consecutiva desde 1 y de solo inserción, todo `seq` entre 1 y la
 *    cabeza ya existe: el UNIQUE no deja ningún hueco.
 *  · `uspub_seq_chk` = `CHECK (seq >= 1)` en PG; en SQLite, que no admite
 *    añadir un CHECK a una tabla existente sin reconstruirla, su equivalente es
 *    el disparador `uspub_seq_min` (BEFORE INSERT WHEN NEW.seq < 1).
 *  · En PG el disparador llama a una función PROPIA,
 *    `ultron_studio_publication_chain()`: la de F solo sabe rechazar. Lee la
 *    cabeza por el índice único de `seq` (una lectura de una fila por inserción)
 *    con la tabla CUALIFICADA (`public.`): sin el esquema, una tabla temporal
 *    con el mismo nombre (pg_temp se busca antes que public, y el rol de la
 *    aplicación tiene TEMP por defecto) podría hacerse pasar por la cadena y
 *    falsear la cabeza. Es VOLATILE e INVOKER (lee con los privilegios de quien
 *    inserta, que tiene SELECT): sin SECURITY DEFINER.
 *  · Concurrencia (READ COMMITTED, el de PG y el del Studio): la función ve la
 *    cabeza CONFIRMADA en el momento de la inserción. Dos escrituras que leyeron
 *    la misma cabeza pasan las dos el disparador y chocan en el UNIQUE (23505),
 *    como hasta ahora; una que llega cuando la otra ya confirmó trae un `seq`
 *    que ya existe y también choca en el UNIQUE. Una fila por encima de la
 *    cabeza confirmada + 1 no entra nunca, se inserte cuando se inserte. No
 *    toma cerrojos propios: ni esperas ni interbloqueos nuevos.
 *
 * (b) EL ROL DE LA APLICACIÓN PIERDE UPDATE Y DELETE en las tablas de solo
 * inserción: la cadena, el libro (`ultron_studio_audit_events`) y las
 * instantáneas de integración (`ultron_studio_integration_snapshots`). Los
 * tenía por los privilegios por defecto del dueño (`arwd`) y hasta ahora los
 * paraban solo los disparadores de F, H5 y H6; ahora tampoco tiene el
 * privilegio (42501). NO se tocan asociaciones de campaña (se desactivan con
 * UPDATE) ni números canarios (cambian de estado con UPDATE). Lo que conserva:
 * SELECT, INSERT y la secuencia (`USAGE`). Ningún código usa UPDATE ni DELETE
 * sobre estas tablas, ni `SELECT … FOR UPDATE/SHARE` ni `ON CONFLICT DO UPDATE`
 * (que también exigen UPDATE en PG); las FKs propias de la cadena se comprueban
 * con los privilegios del dueño de la tabla, no con los de quien inserta.
 *  · El rol es `iron_app` (el de producción). Para ensayar con otro nombre (el
 *    banco de devops usa `h5_app`) se pone `ULTRON_STUDIO_DB_APP_ROLE` en el
 *    ENTORNO del proceso que migra; si no está, `iron_app`.
 *  · Lo ejecuta el rol que migra, el DUEÑO (`iron` vía `ironbody-migrate`): un
 *    REVOKE del dueño quita lo que el dueño concedió. Después se COMPRUEBA (por
 *    tabla, también por columna y por pertenencia a otros roles) que el rol ya
 *    no puede modificar ni borrar; si aún puede (lo heredó de otro rol o de
 *    PUBLIC, o la tabla no es del rol que migra), la migración falla.
 *  · Sin el rol en el clúster (local, CI, la suite) no hay nada que revocar y
 *    se sigue; en `APP_ENV=production` eso es un error y la migración falla:
 *    un REVOKE que no se aplica nunca se calla.
 *  · En SQLite no hay roles: nada.
 *
 * Cerrojos (PG). Primero, y explícito, ACCESS EXCLUSIVE sobre la cadena (lo
 * pedirían de todos modos el ADD CONSTRAINT y el CREATE TRIGGER): así se
 * comprueba la cadena existente y se instalan las guardas sin que nadie
 * inserte en medio, y sin subir de cerrojo a mitad (no hay interbloqueo por
 * subida). Choca con cualquier transacción abierta que haya leído la cadena;
 * con el `lock_timeout` de `ironbody-migrate` (5 s) falla limpia (55P03), no
 * queda registrada y se reintenta. Mientras espera, las lecturas nuevas de la
 * cadena hacen cola detrás (el motor la lee solo con su llave distinta de
 * `off`). El ADD CONSTRAINT valida la tabla entera, que tiene decenas de filas.
 * GRANT/REVOKE en PG 14 no toman cerrojo sobre la tabla (solo actualizan su
 * fila de `pg_class`): el libro y las instantáneas no esperan a nadie ni hacen
 * esperar a nadie.
 *
 * up() SE NIEGA si la cadena existente no es consecutiva desde 1 o no está
 * encadenada (alguna entrada con `seq` distinto de su posición o con
 * `previous_publication_id` distinto de la anterior): el disparador solo mira
 * hacia delante y daría por buena una cadena rota. Repararla no es de esta
 * migración: es del rol dueño con la autorización del propietario (runbook
 * del Hito 5, AS-3 / N-3). Con `migrate --pretend` no se lee nada y la vista
 * previa enseña las sentencias, el cerrojo incluido.
 *
 * SIN TOLERAR ERRORES: si algo no se puede crear o comprobar, la migración
 * falla y no queda registrada. En PG Laravel la envuelve en una transacción;
 * en SQLite no la abre, así que la abre esta migración.
 *
 * Lo que NO cubre:
 *  · El dueño de la tabla puede desactivar o borrar el disparador y conceder
 *    otra vez UPDATE/DELETE; el rol de la aplicación, no (42501).
 *  · Una entrada nueva bien encadenada pero con un texto que no verifica: la
 *    firma la delata (409 `publication_head_invalid`) y Legacy sigue abierto
 *    (D-1 del H5). Esto cierra la cadena SIN SALIDA, no el contenido falso.
 *  · Una restauración de SOLO DATOS (`pg_restore --data-only`) sobre el esquema
 *    ya creado pasa por el disparador fila a fila, en el orden físico del
 *    volcado. Una restauración completa (esquema + datos) crea los
 *    disparadores y los CHECK DESPUÉS de los datos y no le afecta.
 *
 * down() SE NIEGA si hay alguna fila en cualquiera de las tres tablas, antes
 * de tocar nada y con los cerrojos ya tomados (comprobar y deshacer es
 * atómico), y NUNCA borra filas. Sin filas: quita el disparador, su función y
 * el CHECK (en SQLite, los dos disparadores) y devuelve UPDATE y DELETE al rol
 * de la aplicación SOLO en las tablas donde conserva INSERT (el modelo
 * solo-DML); donde no tenía nada, no le da nada. En producción no se ejecuta
 * (contrato H7, «Protecciones intocables»).
 */
return new class extends Migration
{
    public const MESSAGE_PREFIX = 'ultron_studio:';

    public const TABLE = 'ultron_studio_publications';

    public const AUDIT = 'ultron_studio_audit_events';

    public const SNAPSHOTS = 'ultron_studio_integration_snapshots';

    /** Las tablas de solo inserción del Studio a las que el rol de la aplicación pierde UPDATE y DELETE (DB7 b), en este orden. */
    public const INSERT_ONLY_TABLES = [self::TABLE, self::AUDIT, self::SNAPSHOTS];

    /** Lo que se revoca (y lo que devuelve down()). */
    public const REVOKED_PRIVILEGES = ['UPDATE', 'DELETE'];

    /** El rol solo-DML de la aplicación en producción. */
    public const APP_ROLE = 'iron_app';

    /** La variable de ENTORNO para ensayar con otro rol (el banco de devops: `h5_app`). */
    public const APP_ROLE_ENV = 'ULTRON_STUDIO_DB_APP_ROLE';

    /** BEFORE INSERT en los dos motores: la siguiente a la cabeza y apuntando a ella. */
    public const CHAIN_TRIGGER = 'uspub_chain_next';

    /** Solo PG: la función del disparador de la cadena. */
    public const CHAIN_FUNCTION = 'ultron_studio_publication_chain';

    public const CHAIN_REASON = 'una entrada nueva de la cadena tiene que ser la siguiente a la cabeza (seq = cabeza + 1) y apuntar a ella (previous_publication_id)';

    /** Solo PG: `CHECK (seq >= 1)`. */
    public const SEQ_CHECK = 'uspub_seq_chk';

    /** Solo SQLite: el equivalente de `uspub_seq_chk`. */
    public const SEQ_TRIGGER = 'uspub_seq_min';

    public const SEQ_REASON = 'seq empieza en 1';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // `LOCK TABLE` exige un bloque de transacción: el migrador ya abre
            // una en PG (esto es un SAVEPOINT dentro de ella), pero up() no
            // depende de eso.
            DB::transaction(fn () => $this->upPostgres());

            return;
        }
        if ($driver === 'sqlite') {
            DB::transaction(fn () => $this->upSqlite());

            return;
        }

        throw self::unsupported($driver);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::transaction(fn () => $this->downPostgres());

            return;
        }
        if ($driver === 'sqlite') {
            DB::transaction(fn () => $this->downSqlite());

            return;
        }

        throw self::unsupported($driver);
    }

    /** El texto del error, igual en los dos motores (el de F). */
    public static function message(string $reason, string $operation, string $table): string
    {
        return self::MESSAGE_PREFIX.' '.$reason.' ('.$operation.' en '.$table.')';
    }

    /**
     * El rol de la aplicación al que se revoca: `iron_app`, o el de
     * `ULTRON_STUDIO_DB_APP_ROLE` en el entorno. Va en el SQL como
     * identificador (un REVOKE no admite parámetros): solo minúsculas, dígitos
     * y `_`, empezando por letra o `_`, hasta 63.
     */
    public static function appRole(): string
    {
        $env = getenv(self::APP_ROLE_ENV);
        $role = is_string($env) && $env !== '' ? $env : self::APP_ROLE;
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new RuntimeException('ultron_studio (Hito 7): '.self::APP_ROLE_ENV.' no es un nombre de rol válido (minúsculas, dígitos y _, hasta 63).');
        }

        return $role;
    }

    private function upPostgres(): void
    {
        DB::statement('LOCK TABLE '.self::TABLE.' IN ACCESS EXCLUSIVE MODE');
        $this->refuseBrokenChain();

        DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::SEQ_CHECK.' CHECK (seq >= 1)');

        // La cabeza, CONFIRMADA y con la tabla cualificada. Por encima de ella
        // solo la siguiente, apuntando a ella; lo que no pasa de la cabeza lo
        // deciden el UNIQUE de seq (23505) y uspub_seq_chk (23514).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ultron_studio_publication_chain() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
                cabeza_id bigint;
                cabeza_seq bigint;
            BEGIN
                SELECT p.id, p.seq INTO cabeza_id, cabeza_seq
                  FROM public.ultron_studio_publications AS p
                 ORDER BY p.seq DESC
                 LIMIT 1;
                cabeza_seq := COALESCE(cabeza_seq, 0);
                IF NEW.seq > cabeza_seq
                   AND (NEW.seq <> cabeza_seq + 1 OR NEW.previous_publication_id IS DISTINCT FROM cabeza_id) THEN
                    RAISE EXCEPTION 'ultron_studio: % (% en %)', TG_ARGV[0], TG_OP, TG_TABLE_NAME
                        USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END;
            $fn$
            SQL);
        DB::statement('CREATE TRIGGER '.self::CHAIN_TRIGGER.' BEFORE INSERT ON '.self::TABLE.' '
            .'FOR EACH ROW EXECUTE FUNCTION '.self::CHAIN_FUNCTION.'('.self::literal(self::CHAIN_REASON).')');

        $this->revokeAppWrites();
    }

    private function downPostgres(): void
    {
        // Los cerrojos ANTES de contar: nadie inserta entre el recuento y lo
        // que se deshace. Mismo orden que up(). Con `--pretend` las lecturas
        // devuelven vacío: se escribe igual, para que la vista previa lo enseñe.
        $pretending = DB::connection()->pretending();
        $tables = array_values(array_filter(self::INSERT_ONLY_TABLES, static fn (string $table): bool => $pretending || Schema::hasTable($table)));
        if ($tables !== []) {
            DB::statement('LOCK TABLE '.implode(', ', $tables).' IN ACCESS EXCLUSIVE MODE');
        }
        $this->refuseWithRows();

        $this->restoreAppWrites($tables);
        if (in_array(self::TABLE, $tables, true)) {
            DB::statement('DROP TRIGGER IF EXISTS '.self::CHAIN_TRIGGER.' ON '.self::TABLE);
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS '.self::SEQ_CHECK);
        }
        // Después del disparador, que depende de ella.
        DB::statement('DROP FUNCTION IF EXISTS '.self::CHAIN_FUNCTION.'()');
        // `ultron_studio_forbid()` es de F: ni se usa aquí ni se toca.
    }

    private function upSqlite(): void
    {
        $this->refuseBrokenChain();

        $cabezaSeq = 'COALESCE((SELECT max(seq) FROM '.self::TABLE.'), 0)';
        $cabezaId = '(SELECT id FROM '.self::TABLE.' ORDER BY seq DESC LIMIT 1)';
        DB::statement('CREATE TRIGGER '.self::CHAIN_TRIGGER.' BEFORE INSERT ON '.self::TABLE.' FOR EACH ROW '
            ."WHEN (NEW.seq > {$cabezaSeq} AND (NEW.seq <> {$cabezaSeq} + 1 OR NEW.previous_publication_id IS NOT {$cabezaId})) "
            .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message(self::CHAIN_REASON, 'INSERT', self::TABLE)).'); END');
        DB::statement('CREATE TRIGGER '.self::SEQ_TRIGGER.' BEFORE INSERT ON '.self::TABLE.' FOR EACH ROW '
            .'WHEN (NEW.seq < 1) '
            .'BEGIN SELECT RAISE(ABORT, '.self::literal(self::message(self::SEQ_REASON, 'INSERT', self::TABLE)).'); END');
        // Sin roles: no hay privilegios que revocar.
    }

    private function downSqlite(): void
    {
        $this->refuseWithRows();

        DB::statement('DROP TRIGGER IF EXISTS '.self::SEQ_TRIGGER);
        DB::statement('DROP TRIGGER IF EXISTS '.self::CHAIN_TRIGGER);
    }

    /**
     * DB7 (b): REVOKE UPDATE, DELETE del rol de la aplicación sobre las tres
     * tablas, y la comprobación de que de verdad ya no puede (por tabla, por
     * columna y por pertenencia a otros roles: `has_any_column_privilege` y
     * `has_table_privilege` miran todo eso). Sin el rol en el clúster no hay
     * nada que revocar, salvo en producción, donde es un error.
     */
    private function revokeAppWrites(): void
    {
        $role = self::appRole();
        $revoke = 'REVOKE '.implode(', ', self::REVOKED_PRIVILEGES).' ON '.implode(', ', self::INSERT_ONLY_TABLES).' FROM "'.$role.'"';

        if (DB::connection()->pretending()) {
            DB::statement($revoke);

            return;
        }
        if (! self::roleExists($role)) {
            if (app()->environment('production')) {
                throw new RuntimeException("ultron_studio (Hito 7): el rol de la aplicación «{$role}» no existe en este clúster y APP_ENV es production: "
                    .'el REVOKE de UPDATE y DELETE sobre las tablas de solo inserción no se puede aplicar. La migración no se registra; '
                    .'si el rol se llama de otra forma, '.self::APP_ROLE_ENV.' en el entorno del proceso que migra.');
            }

            return;
        }

        DB::statement($revoke);

        $todavia = [];
        foreach (self::INSERT_ONLY_TABLES as $table) {
            $puede = DB::selectOne(
                "SELECT has_any_column_privilege(?, ?, 'UPDATE')::int AS u, has_table_privilege(?, ?, 'DELETE')::int AS d",
                [$role, 'public.'.$table, $role, 'public.'.$table],
            );
            foreach (['UPDATE' => $puede->u, 'DELETE' => $puede->d] as $privilegio => $tiene) {
                if ((int) $tiene === 1) {
                    $todavia[] = "{$privilegio} en {$table}";
                }
            }
        }
        if ($todavia !== []) {
            throw new RuntimeException("ultron_studio (Hito 7): después del REVOKE, «{$role}» todavía puede ".implode(', ', $todavia)
                .' (heredado de otro rol o de PUBLIC, o concedido por alguien que no es el rol que migra). La migración no se registra.');
        }
    }

    /**
     * down(): UPDATE y DELETE vuelven al rol de la aplicación solo en las
     * tablas donde conserva INSERT (el modelo solo-DML que up() recortó).
     *
     * @param  list<string>  $tables
     */
    private function restoreAppWrites(array $tables): void
    {
        $role = self::appRole();
        $grant = static fn (string $table): string => 'GRANT '.implode(', ', self::REVOKED_PRIVILEGES).' ON '.$table.' TO "'.$role.'"';

        if (DB::connection()->pretending()) {
            foreach ($tables as $table) {
                DB::statement($grant($table));
            }

            return;
        }
        if (! self::roleExists($role)) {
            return;
        }
        foreach ($tables as $table) {
            if ((int) DB::selectOne("SELECT has_table_privilege(?, ?, 'INSERT')::int AS i", [$role, 'public.'.$table])->i === 1) {
                DB::statement($grant($table));
            }
        }
    }

    private static function roleExists(string $role): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]) !== null;
    }

    /**
     * La cadena existente tiene que ser consecutiva desde 1 y encadenada: la
     * entrada n-ésima por `seq` tiene `seq` = n y apunta a la anterior (la
     * primera, a NULL). El disparador solo mira hacia delante. Con
     * `--pretend` no se lee nada.
     */
    private function refuseBrokenChain(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        // Comparación a prueba de NULL y la misma en los dos motores: los ids
        // son positivos, así que -1 no es nunca un id (con `=` a secas, «NULL
        // = id» da NULL y una entrada sin encadenar no contaría).
        $cadena = DB::selectOne(
            'SELECT count(*) AS entradas, '
            .'COALESCE(sum(CASE WHEN c.seq <> c.n OR COALESCE(c.previous_publication_id, -1) <> COALESCE(c.anterior, -1) '
            .'THEN 1 ELSE 0 END), 0) AS rotas '
            .'FROM (SELECT seq, previous_publication_id, row_number() OVER (ORDER BY seq) AS n, '
            .'lag(id) OVER (ORDER BY seq) AS anterior FROM '.self::TABLE.') AS c',
        );
        $rotas = (int) $cadena->rotas;

        if ($rotas > 0) {
            throw new RuntimeException('ultron_studio (Hito 7): la cadena de publicaciones no es consecutiva desde 1 o no está encadenada '
                ."({$rotas} de {$cadena->entradas} entradas fuera de su sitio). Esta migración no la repara: repararla es del rol dueño "
                .'con la autorización del propietario (runbook del Hito 5, AS-3 / N-3); después se vuelve a aplicar.');
        }
    }

    /**
     * DB7 (c): con filas en cualquiera de las tres tablas, revertir quitaría
     * protección a registro real. Se niega con un mensaje claro, antes de tocar
     * nada, y nunca borra.
     */
    private function refuseWithRows(): void
    {
        $rows = [];
        foreach (self::INSERT_ONLY_TABLES as $table) {
            $rows[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        if (array_sum($rows) > 0) {
            throw new RuntimeException('ultron_studio (Hito 7): down() se niega a quitar el endurecimiento con filas en las tablas de solo inserción '
                ."({$rows[self::TABLE]} publicaciones, {$rows[self::AUDIT]} eventos del libro y {$rows[self::SNAPSHOTS]} instantáneas de integración). "
                .'Revertirlo dejaría ese registro sin sus guardas y nunca borra nada. Para volver al código del Hito 6 no hace falta revertir el esquema.');
        }
    }

    private static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private static function unsupported(string $driver): RuntimeException
    {
        return new RuntimeException("El endurecimiento del Hito 7 de ULTRON Studio solo existe para pgsql y sqlite, y es obligatorio: con «{$driver}» la migración no se aplica.");
    }
};
