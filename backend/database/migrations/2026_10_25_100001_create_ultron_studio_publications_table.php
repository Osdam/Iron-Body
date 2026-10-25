<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · Hito 5 (gobierno y versionado): la cadena de publicaciones.
 * Contrato: docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md, §A3.1.
 *
 * Una tabla NUEVA y SOLO ADITIVA, `ultron_studio_publications`: cada fila es
 * una entrada de la cadena (publicar, promover, revertir, volver a Legacy), de
 * solo inserción. La cabeza es la fila con `max(seq)`, y es lo ÚNICO del
 * Studio que lee el motor (`UltronPublishedConfig`, contrato §A4 y §A9): una
 * lectura por el índice único de `seq` en cada commit de ULTRON.
 *
 *  · `seq` UNIQUE: 1, 2, 3… La carrera por la cabeza la gana una sola
 *    inserción; la otra choca (23505 en PG) y el servicio responde 409
 *    `publication_moved`.
 *  · `client_request_id` UNIQUE: idempotencia del reintento.
 *  · `effective_json` es `text`, NUNCA json/jsonb: guarda los BYTES EXACTOS que
 *    firma el Studio y verifica el motor (`effective_hash` = su sha256, y
 *    `signature`). PostgreSQL reordenaría o normalizaría un jsonb, y un `json`
 *    invitaría a tratarlo como estructura; el motor no recanonicaliza.
 *  · `lab_gate`, `acknowledged` y `evidence` son `json`, no `jsonb`, como el
 *    resto del Studio (cabecera de 2026_10_18_100001): nada de DISTINCT, GROUP
 *    BY ni UNION sobre ellas.
 *  · Quien actúa se guarda como INSTANTÁNEA (id + nombre + rol) SIN FK a
 *    `admins`, como en F: la cadena sigue diciendo quién fue aunque la cuenta
 *    cambie, y una FK con ON DELETE cambiaría o bloquearía filas que la base
 *    declara inmutables. Lleva nombre y rol del personal: el procedimiento de
 *    redacción con el rol dueño (Hito 7) tiene que cubrir también esta tabla.
 *  · Solo inserción: sin `updated_at`.
 *
 * FKs, todas RESTRICT y todas nacen con su tabla: `version_id` →
 * `ultron_studio_versions` (NULL en `legacy`), y las dos propias
 * (`restores_publication_id`, `previous_publication_id`). Las filas no se
 * pueden borrar por sus disparadores (2026_10_25_100002); RESTRICT deja
 * escrito que la base no borraría en cascada aunque alguien los quitara.
 * Ninguna migración de H5 altera `ultron_studio_versions` con un
 * `Schema::table` (una FK o una columna nuevas en ella) ni la reconstruye: en
 * SQLite eso la recrearía sin sus disparadores ni el WHERE de los índices
 * parciales de 2026_10_25_100002 (memoria «SQLite reconstruye y pierde el
 * WHERE»). La FK de aquí nace con ESTA tabla y solo la REFERENCIA: crearla no
 * toca la definición de versiones en ninguno de los dos motores.
 *
 * Índices: los tres UNIQUE y `uspub_version_idx` (version_id, id), que sirve
 * a «las entradas que nombran esta versión» y a la FK. Las dos FKs propias no
 * llevan índice: las filas no se borran ni cambian de id, así que PostgreSQL
 * nunca tiene que buscar quién las referencia, y ninguna consulta filtra por
 * ellas (la cadena se recorre por `seq`).
 *
 * PostgreSQL, cerrojos: la tabla es nueva y nadie la usa. La FK a versiones
 * pide SHARE ROW EXCLUSIVE sobre `ultron_studio_versions`: no choca con las
 * lecturas, sí con una transacción abierta que haya ESCRITO en versiones. Con
 * el `lock_timeout` de `ironbody-migrate` (5 s), en ese caso la migración falla
 * limpia (55P03): Laravel la envuelve en una transacción, no queda registrada
 * y se reintenta. El rol de la aplicación (solo DML) hereda sus privilegios
 * sobre la tabla y su secuencia de los privilegios por defecto del dueño: no
 * hace falta GRANT.
 *
 * Las guardas (disparadores de solo inserción, CHECK en PG, `usv_status_chk`
 * ampliado e índices únicos parciales) van en 2026_10_25_100002.
 *
 * down() SE NIEGA si hay alguna publicación o alguna versión en un estado de H5
 * (contrato §A3.4): DROP no pasa por los disparadores de fila y destruiría la
 * cadena. Sin filas de H5, borra la tabla. Volver al código de H4 no exige
 * revertir esto (§A3.5): la tabla se queda sin lector.
 */
return new class extends Migration
{
    private const TABLE = 'ultron_studio_publications';

    /** Los estados de versión que solo existen con H5 (contrato §A2). */
    private const H5_VERSION_STATUSES = ['CANARY', 'PUBLISHED', 'RETIRED', 'ROLLED_BACK'];

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // 1, 2, 3…; la cabeza es max(seq). La carrera la gana uno.
            $table->unsignedInteger('seq')->unique();
            // Idempotencia: misma persona y mismo cuerpo → la entrada de la
            // primera vez.
            $table->uuid('client_request_id')->unique();
            // publish | promote | rollback | legacy (CHECK en PG, 2026_10_25_100002).
            $table->string('action', 10);
            // La versión sobre la que se actuó; NULL en legacy.
            $table->foreignId('version_id')->nullable()->constrained('ultron_studio_versions')->restrictOnDelete();
            // En rollback: la entrada cuya «parte resto» vuelve.
            $table->foreignId('restores_publication_id')->nullable()->constrained(self::TABLE)->restrictOnDelete();
            // La cabeza anterior (NULL solo en seq = 1).
            $table->foreignId('previous_publication_id')->nullable()->constrained(self::TABLE)->restrictOnDelete();
            // 'ultron-engine-config/1' (CHECK en PG).
            $table->string('engine_contract', 32);
            // BYTES EXACTOS que firma el Studio y lee el motor: text, nunca json/jsonb.
            $table->text('effective_json');
            // sha256(effective_json), hex.
            $table->char('effective_hash', 64);
            // hex(HMAC-SHA256), contrato §A4.1.
            $table->char('signature', 64);
            $table->string('catalog_version', 32);
            // {mode:'required'|'waived', runUuid?, verdictId?, promptVersion?}
            $table->json('lab_gate');
            // {noEffect:[…]} (publish)
            $table->json('acknowledged')->nullable();
            // Recuentos del canario (promote), sin ids ni textos.
            $table->json('evidence')->nullable();
            // Obligatorio en rollback y legacy (`uspub_reason_chk` en PG).
            $table->string('reason', 500)->nullable();
            // Instantánea de quien actúa, SIN FK.
            $table->unsignedBigInteger('actor_admin_id');
            $table->string('actor_name', 120);
            $table->string('actor_role', 60)->nullable();
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');

            $table->index(['version_id', 'id'], 'uspub_version_idx');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->lockForDown();
            $this->refuseWithH5Rows();

            Schema::dropIfExists(self::TABLE);
        });
    }

    /**
     * Comprobar y borrar tiene que ser atómico: sin el cerrojo, una inserción
     * confirmada entre el recuento y el DROP se perdería con la tabla. En PG
     * se toman los dos cerrojos que el DROP pediría de todos modos (también
     * ACCESS EXCLUSIVE sobre versiones, por la FK), primero la cadena y
     * después versiones, el mismo orden que la migración de guardas y que
     * publicar (insertar la entrada, después mover la versión), y la espera la
     * acota el `lock_timeout`. En SQLite (la suite) basta la transacción.
     */
    private function lockForDown(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        // Con `--pretend` las lecturas devuelven vacío: se escribe igual, para
        // que la vista previa enseñe el cerrojo.
        if (! DB::connection()->pretending() && ! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::statement('LOCK TABLE '.self::TABLE.', ultron_studio_versions IN ACCESS EXCLUSIVE MODE');
    }

    /**
     * Contrato §A3.4: con filas de H5, revertir el esquema destruiría la
     * cadena (y dejaría versiones en estados sin su registro). Se niega con un
     * mensaje claro, antes de tocar nada.
     */
    private function refuseWithH5Rows(): void
    {
        $publications = Schema::hasTable(self::TABLE) ? DB::table(self::TABLE)->count() : 0;
        $versions = DB::table('ultron_studio_versions')->whereIn('status', self::H5_VERSION_STATUSES)->count();

        if ($publications > 0 || $versions > 0) {
            throw new RuntimeException(self::TABLE.': down() se niega a revertir el Hito 5 con datos del Hito 5 '
                ."({$publications} publicaciones, {$versions} versiones en ".implode(', ', self::H5_VERSION_STATUSES).'). '
                .'La cadena es de solo inserción y revertir la destruiría (contrato H5 §A3.4). '
                .'Para volver al código de H4 no hace falta revertir el esquema (§A3.5).');
        }
    }
};
