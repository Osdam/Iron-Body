<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ULTRON STUDIO · Hito 6 (integraciones comerciales): persistencia. Contrato:
 * docs/ultron-studio/ULTRON_STUDIO_H6_CONTRACT.md, §I3.1, §I4.1 y §I5.3.
 *
 * Tres tablas NUEVAS y SOLO ADITIVAS. No altera ninguna tabla existente (solo
 * REFERENCIA `ultron_studio_versions` con dos FKs) ni nada del motor de ULTRON
 * (`marketing_*`, `meta_*`, `ultron_aborts`): el motor no lee ninguna de las
 * tres (contrato §I1, P1 y P4). Son REGISTRO; nada de lo que guardan cambia lo
 * que ULTRON dice o hace.
 *
 *  · ultron_studio_campaign_links       — una fila es UN PERIODO de asociación
 *                                         campaña de Meta ↔ línea base o versión.
 *                                         El historial son filas, nunca
 *                                         ediciones: solo cambia su cierre.
 *  · ultron_studio_canary_numbers       — el registro de números canarios, con
 *                                         su máquina de estados (§I4.4) y los
 *                                         cuatro ojos (§I4.2).
 *  · ultron_studio_integration_snapshots — configuración reconocida y sondas;
 *                                         solo inserción.
 *
 * DATOS PERSONALES (Ley 1581, contrato §I13). Del teléfono de un canario solo
 * se guardan su huella HMAC (`phone_hash`, `PhoneFingerprint`) y sus 4 últimos
 * dígitos. El número completo, CIFRADO (cast `encrypted`, APP_KEY), solo en el
 * canal `voice`, y se borra al revocar: lo exigen `uscn_whatsapp_no_cipher_chk`,
 * `uscn_voice_cipher_chk` y `uscn_revoke_chk` (PG) y el disparador
 * `uscn_cipher_only_shrinks` (los dos motores), en 2026_11_01_100002. `facts`
 * nunca lleva un secreto ni el teléfono de una persona (lo garantiza el
 * servicio; §I5.3). Quien actúa se guarda como INSTANTÁNEA (id + nombre + rol)
 * SIN FK a `admins`, como en F: el registro sigue diciendo quién fue aunque la
 * cuenta cambie, y una FK con ON DELETE cambiaría o bloquearía filas que la
 * base declara inmutables. El procedimiento de redacción con el rol dueño
 * (Hito 7) tiene que cubrir también estas tres tablas.
 *
 * Compatibilidad PostgreSQL 14 + SQLite (la suite), como F, H4 y H5:
 *  · `json`, no `jsonb` (`facts`): nada de DISTINCT, GROUP BY ni UNION sobre
 *    ella, y el disparador de solo inserción no compara valores.
 *  · Todas las FKs nacen con su tabla, todas RESTRICT: `version_id` y
 *    `expected_version_id` → `ultron_studio_versions`, y la propia
 *    `replaced_by_link_id`. Las filas referenciadas tampoco se pueden borrar
 *    por sus disparadores; RESTRICT deja escrito que la base no borraría en
 *    cascada aunque alguien los quitara. Ninguna migración del Hito 6 hace un
 *    `Schema::table` sobre versiones: en SQLite la reconstruiría y perdería sus
 *    disparadores y el WHERE de `usv_one_canary`/`usv_one_published` (memoria
 *    «SQLite reconstruye y pierde el WHERE»). Estas FKs solo la REFERENCIAN.
 *  · Sin índice para las tres FKs (como las propias de H5, D-2 de su informe):
 *    versiones y asociaciones no se borran ni cambian de id (sus disparadores),
 *    así que PostgreSQL nunca busca quién las referencia, y ninguna consulta del
 *    contrato filtra por ellas. Las tablas tendrán decenas de filas.
 *  · `unsigned*` es solo documental en PG, que no tiene enteros sin signo.
 *  · Los índices únicos PARCIALES (`uscl_one_active`, `uscn_one_live`) y los
 *    CHECK van en 2026_11_01_100002, con SQL crudo e igual en los dos motores.
 *
 * PostgreSQL, cerrojos: las tablas son nuevas y nadie las usa. Cada FK a
 * versiones pide SHARE ROW EXCLUSIVE sobre `ultron_studio_versions`: no choca
 * con las lecturas, sí con una transacción abierta que haya ESCRITO en
 * versiones. Con el `lock_timeout` de `ironbody-migrate` (5 s), en ese caso la
 * migración falla limpia (55P03): Laravel la envuelve en una transacción, no
 * queda registrada y se reintenta. El rol de la aplicación (solo DML) hereda
 * sus privilegios sobre las tablas y sus secuencias de los privilegios por
 * defecto del dueño: no hace falta GRANT.
 *
 * down() SE NIEGA si hay alguna fila en las tres tablas o algún evento del
 * libro con un sujeto del Hito 6 (contrato, «Reversión», nivel 4), antes de
 * tocar nada y con los cerrojos ya tomados: DROP no pasa por los disparadores
 * de fila y destruiría el registro. Sin filas, borra las tres tablas. Volver al
 * código del Hito 5 no exige revertir esto (nivel 3): las tablas se quedan sin
 * lector. En producción, down() no se ejecuta nunca (límite del 25-10).
 */
return new class extends Migration
{
    private const LINKS = 'ultron_studio_campaign_links';

    private const CANARIES = 'ultron_studio_canary_numbers';

    private const SNAPSHOTS = 'ultron_studio_integration_snapshots';

    /** En orden de creación; down() las borra al revés. */
    private const TABLES = [self::LINKS, self::CANARIES, self::SNAPSHOTS];

    /** Los sujetos del libro que solo existen con el Hito 6 (contrato §I8). */
    private const H6_SUBJECT_TYPES = ['campaign_link', 'canary_number', 'integration'];

    public function up(): void
    {
        Schema::create(self::LINKS, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Idempotencia de la creación: misma persona y mismo cuerpo → la
            // asociación de la primera vez.
            $table->uuid('client_request_id')->unique();
            // Ids de Meta (sin `act_`, como `meta_ad_entities`). Nunca salen del
            // servidor: la API habla con ids opacos (§I2).
            $table->string('ad_account_id', 64);
            $table->string('campaign_id', 64);
            // El nombre al asociar, ya enmascarado (`maskDigits()`).
            $table->string('campaign_name_at_link', 255)->nullable();
            // baseline | version (CHECK en PG, 2026_11_01_100002).
            $table->string('target_kind', 10);
            // NOT NULL si y solo si target_kind = version (`uscl_target_chk`).
            $table->foreignId('version_id')->nullable()->constrained('ultron_studio_versions')->restrictOnDelete();
            // La huella de la versión al asociar; misma condición que version_id.
            $table->char('version_content_hash', 64)->nullable();
            // active | inactive. Como mucho una activa por campaña (`uscl_one_active`).
            $table->string('status', 10);
            // Instantánea de quien asocia, SIN FK.
            $table->unsignedBigInteger('created_by_admin_id');
            $table->string('created_by_name', 120);
            $table->string('created_by_role', 60)->nullable();
            // Sin updated_at: una fila es un periodo; lo que cambia es su cierre.
            $table->timestamp('created_at');
            // El cierre del periodo (`uscl_deactivation_chk` en PG).
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedBigInteger('deactivated_by_admin_id')->nullable();
            $table->string('deactivated_by_name', 120)->nullable();
            $table->string('deactivated_by_role', 60)->nullable();
            $table->string('deactivation_reason', 500)->nullable();
            // La asociación que sustituyó a esta (se anota una sola vez).
            $table->foreignId('replaced_by_link_id')->nullable()->constrained(self::LINKS)->restrictOnDelete();

            // El historial de una campaña, del más reciente al más antiguo.
            $table->index(['ad_account_id', 'campaign_id', 'id'], 'uscl_campaign_idx');
        });

        Schema::create(self::CANARIES, function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Idempotencia del registro.
            $table->uuid('client_request_id')->unique();
            // whatsapp | voice (CHECK en PG).
            $table->string('channel', 10);
            // Una función, no una persona (§I4.3). No va al libro.
            $table->string('alias', 40);
            // `PhoneFingerprint::of(E.164)`: 64 hex en minúsculas (`uscn_hash_chk`).
            $table->char('phone_hash', 64);
            $table->char('phone_last4', 4);
            $table->string('country_code', 4);
            // SOLO `voice`: el E.164 cifrado (cast `encrypted` del modelo). NULL
            // en WhatsApp y al revocar.
            $table->text('phone_encrypted')->nullable();
            // pending | authorized | active | revoked (terminal).
            $table->string('status', 12);
            // Expectativa auditada (§I4.6), NUNCA autoridad.
            $table->foreignId('expected_version_id')->nullable()->constrained('ultron_studio_versions')->restrictOnDelete();
            // Declaración de quien registra; tiene que ser true (`uscn_consent_chk`).
            $table->boolean('consent_attested');
            // Instantáneas SIN FK. Los cuatro ojos comparan estos ids con el de
            // quien registró (`uscn_four_eyes_chk`, `uscn_activation_four_eyes_chk`).
            $table->unsignedBigInteger('registered_by_admin_id');
            $table->string('registered_by_name', 120);
            $table->string('registered_by_role', 60)->nullable();
            $table->unsignedBigInteger('authorized_by_admin_id')->nullable();
            $table->string('authorized_by_name', 120)->nullable();
            $table->string('authorized_by_role', 60)->nullable();
            $table->timestamp('authorized_at')->nullable();
            // La ÚLTIMA activación.
            $table->unsignedBigInteger('activated_by_admin_id')->nullable();
            $table->string('activated_by_name', 120)->nullable();
            $table->string('activated_by_role', 60)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('revoked_by_admin_id')->nullable();
            $table->string('revoked_by_name', 120)->nullable();
            $table->string('revoked_by_role', 60)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamps();

            // Activos y vivos por canal (los topes de §I4.8).
            $table->index(['status', 'channel'], 'uscn_status_idx');
        });

        Schema::create(self::SNAPSHOTS, function (Blueprint $table): void {
            $table->id();
            // Sujeto del libro (`integration`).
            $table->uuid('uuid')->unique();
            // En reconocimientos y sondas lanzados por una persona; NULL en las
            // del comando (actor `system`).
            $table->uuid('client_request_id')->nullable()->unique();
            // meta_ads | twilio | voice (CHECK en PG).
            $table->string('provider', 16);
            // config | probe.
            $table->string('kind', 10);
            // sha256(StudioContentHasher::canonicalJson(facts)), hex (`usis_fingerprint_chk`).
            $table->char('fingerprint', 64);
            // Jamás un secreto ni el teléfono de una persona (§I5.3).
            $table->json('facts');
            // Solo en `probe` (`usis_outcome_chk`).
            $table->string('outcome', 16)->nullable();
            // admin | system. Instantánea SIN FK.
            $table->string('actor_type', 10);
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            // `system` → el nombre del comando.
            $table->string('actor_name', 120);
            $table->string('actor_role', 60)->nullable();
            // La nota del reconocimiento.
            $table->string('note', 500)->nullable();
            // Solo inserción: sin updated_at.
            $table->timestamp('created_at');

            // La última instantánea de un proveedor y clase.
            $table->index(['provider', 'kind', 'id'], 'usis_provider_idx');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->lockForDown();
            $this->refuseWithH6Rows();

            foreach (array_reverse(self::TABLES) as $table) {
                Schema::dropIfExists($table);
            }
        });
    }

    /**
     * Comprobar y borrar tiene que ser atómico: sin el cerrojo, una inserción
     * confirmada entre el recuento y el DROP se perdería con la tabla. En PG se
     * toman los cerrojos que los DROP pedirían de todos modos (también ACCESS
     * EXCLUSIVE sobre versiones, por las FKs), en el orden de up(): las tablas
     * nuevas y después versiones. La espera la acota el `lock_timeout`. Un
     * evento del libro con un sujeto del Hito 6 siempre nombra una fila de
     * estas tablas (que no se borran), así que bloquearlas basta. En SQLite (la
     * suite) basta la transacción.
     */
    private function lockForDown(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        // Con `--pretend` las lecturas devuelven vacío: se escribe igual, para
        // que la vista previa enseñe el cerrojo.
        $pretending = DB::connection()->pretending();
        $tables = array_values(array_filter(self::TABLES, static fn (string $table): bool => $pretending || Schema::hasTable($table)));
        if ($tables === []) {
            return;
        }

        DB::statement('LOCK TABLE '.implode(', ', [...$tables, 'ultron_studio_versions']).' IN ACCESS EXCLUSIVE MODE');
    }

    /**
     * Contrato H6, «Reversión», nivel 4: con filas del Hito 6, revertir el
     * esquema destruiría el registro (y dejaría en el libro sujetos sin su
     * fila). Se niega con un mensaje claro, antes de tocar nada.
     */
    private function refuseWithH6Rows(): void
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }
        $events = DB::table('ultron_studio_audit_events')->whereIn('subject_type', self::H6_SUBJECT_TYPES)->count();

        if (array_sum($rows) > 0 || $events > 0) {
            throw new RuntimeException('ultron_studio (Hito 6): down() se niega a revertir el Hito 6 con datos del Hito 6 '
                ."({$rows[self::LINKS]} asociaciones de campaña, {$rows[self::CANARIES]} números canarios, "
                ."{$rows[self::SNAPSHOTS]} instantáneas de integración y {$events} eventos del libro con los sujetos "
                .implode(', ', self::H6_SUBJECT_TYPES).'). Es registro de solo inserción o con historial, y revertir lo destruiría '
                .'(contrato H6, «Reversión», nivel 4). Para volver al código del Hito 5 no hace falta revertir el esquema (nivel 3).');
        }
    }
};
