<?php

namespace Tests\Feature\Outreach\Schema;

use App\Models\OutreachConsent;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * EL LIBRO DE AUTORIZACIONES DE CONTACTO EN LA BASE (cierre de ULTRON STUDIO,
 * C9-1; migración 2026_11_22_100001, `outreach_consents`), en los dos motores:
 * columnas, guardas, cada invariante con su prueba NEGATIVA, la única mutación
 * (la redacción 1581), la consulta de la tanda del lector, el modelo, `down()`
 * y, solo en PostgreSQL, el rol de la aplicación (un rol que NO es el dueño,
 * como `iron_app` en producción).
 *
 * Recuentos: PG 8 disparadores, 13 CHECK, 3 funciones `outreach_consent_%`
 * (ninguna SECURITY DEFINER), 0 parciales; SQLite 20 disparadores (los 7 de
 * fila y entre filas sin el de TRUNCATE, más los 13 equivalentes de los CHECK).
 * Informe: plans/informes-cierre/informe-db-consent.md.
 */
class OutreachConsentsSchemaTest extends TestCase
{
    use OutreachConsentFixtures;
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    /** columna → [tipo PG, tipo SQLite, anulable] */
    private const COLUMNS = [
        'id' => ['bigint', 'integer', false],
        'uuid' => ['uuid', 'varchar', false],
        'client_request_id' => ['uuid', 'varchar', false],
        'subject_key' => ['character varying(24)', 'varchar', false],
        'member_id' => ['bigint', 'integer', true],
        'lead_id' => ['bigint', 'integer', true],
        'purpose' => ['character varying(12)', 'varchar', false],
        'channel' => ['character varying(16)', 'varchar', false],
        'kind' => ['character varying(10)', 'varchar', false],
        'request_uuid' => ['uuid', 'varchar', true],
        'source' => ['character varying(20)', 'varchar', false],
        'evidence' => ['character varying(300)', 'varchar', false],
        'legal_version' => ['character varying(48)', 'varchar', true],
        'legal_hash' => ['character(64)', 'varchar', true],
        'phone_ref' => ['character(64)', 'varchar', true],
        'actor_type' => ['character varying(8)', 'varchar', false],
        'recorded_by_admin_id' => ['bigint', 'integer', true],
        'recorded_by_name' => ['character varying(120)', 'varchar', false],
        'recorded_by_role' => ['character varying(60)', 'varchar', true],
        'confirmed_by_admin_id' => ['bigint', 'integer', true],
        'confirmed_by_name' => ['character varying(120)', 'varchar', true],
        'confirmed_by_role' => ['character varying(60)', 'varchar', true],
        'redacted_at' => ['timestamp(0) without time zone', 'datetime', true],
        'created_at' => ['timestamp(0) without time zone', 'datetime', false],
    ];

    private const PG_TRIGGERS = [
        'ocon_forbid_delete', 'ocon_forbid_immutable_update', 'ocon_forbid_truncate', 'ocon_grant_not_superseded', 'ocon_redaction_only',
        'ocon_resolves_request', 'ocon_staff_names_redaction_only', 'ocon_starts_unredacted',
    ];

    private const PG_CHECKS = [
        'ocon_actor_chk', 'ocon_actor_type_chk', 'ocon_channel_chk', 'ocon_confirmed_chk', 'ocon_evidence_chk', 'ocon_four_eyes_chk', 'ocon_kind_chk',
        'ocon_legal_chk', 'ocon_phone_chk', 'ocon_purpose_chk', 'ocon_request_chk', 'ocon_source_chk', 'ocon_subject_chk',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        putenv('ULTRON_STUDIO_DB_APP_ROLE');
        parent::tearDown();
    }

    // ── La forma ────────────────────────────────────────────────────────────

    public function test_columnas_indices_fk_y_guardas_con_sus_nombres(): void
    {
        $motor = $this->isPostgres() ? 0 : 1;
        $reales = [];
        foreach (Schema::getColumns(self::T_CONSENTS) as $c) {
            $reales[$c['name']] = [$c['type'], (bool) $c['nullable']];
        }
        $this->assertSame(array_map(static fn (array $e): array => [$e[$motor], $e[2]], self::COLUMNS), $reales);

        $m = $this->consentMigration();
        $clasificadas = [...$m::IMMUTABLE_COLUMNS, ...$m::SUBJECT_REDACTABLE_COLUMNS, ...$m::STAFF_NAME_COLUMNS];
        $this->assertEqualsCanonicalizing(array_keys(self::COLUMNS), $clasificadas, 'cada columna, clasificada una vez');
        $this->assertCount(count(self::COLUMNS), array_unique($clasificadas));

        $indices = [];
        foreach (Schema::getIndexes(self::T_CONSENTS) as $i) {
            $indices[$i['name']] = [implode(',', $i['columns']), (bool) $i['unique']];
        }
        ksort($indices);
        $this->assertSame([
            'ocon_client_request_unique' => ['client_request_id', true],
            'ocon_request_unique' => ['request_uuid', true],
            'ocon_subject_idx' => ['subject_key,purpose,channel,id', false],
            'ocon_uuid_unique' => ['uuid', true],
        ], array_diff_key($indices, ['primary' => 1, 'outreach_consents_pkey' => 1]));
        $this->assertSame([], $this->partialPredicates([self::T_CONSENTS]), 'sin índices parciales (un único total no lo rehace SQLite al reconstruir)');

        $fks = array_map(static fn (array $fk): string => implode(',', $fk['columns']).' → '.$fk['foreign_table'].'.'.implode(',', $fk['foreign_columns']).' '.strtolower((string) $fk['on_delete']),
            Schema::getForeignKeys(self::T_CONSENTS));
        $this->assertSame(['request_uuid → outreach_consents.uuid restrict'], $fks, 'la única FK: la resolución a su petición, dentro de la tabla');

        $sqliteMirrors = array_map($m::sqliteTriggerFor(...), self::PG_CHECKS);
        $esperados = $this->isPostgres()
            ? self::PG_TRIGGERS
            : [...array_values(array_diff(self::PG_TRIGGERS, ['ocon_forbid_truncate'])), ...$sqliteMirrors];
        sort($esperados);
        $this->assertSame($esperados, $this->triggersOn(self::T_CONSENTS));
        $this->assertCount($this->isPostgres() ? 8 : 20, $esperados);
        if ($this->isPostgres()) {
            $this->assertSame(self::PG_CHECKS, $this->checksOn(self::T_CONSENTS));
            $this->assertEqualsCanonicalizing(self::PG_CHECKS, array_keys($m::checks()));
        }
    }

    public function test_el_modelo_copia_los_conjuntos_cerrados_y_no_expone_modificaciones(): void
    {
        $m = $this->consentMigration();
        $this->assertSame($m::KINDS, OutreachConsent::KINDS);
        $this->assertSame($m::PURPOSES, OutreachConsent::PURPOSES);
        $this->assertSame($m::CHANNELS, OutreachConsent::CHANNELS);
        $this->assertSame($m::SOURCES, OutreachConsent::SOURCES);
        $this->assertSame($m::ACTOR_TYPES, OutreachConsent::ACTOR_TYPES);
        $this->assertSame($m::REDACTED, OutreachConsent::REDACTED);
        $this->assertSame($m::EVIDENCE_MAX, OutreachConsent::EVIDENCE_MAX);
        $this->assertSame(self::T_CONSENTS, (new OutreachConsent)->getTable());
        $this->assertNull(OutreachConsent::UPDATED_AT);

        // Crear sí (con su uuid); leer sí, con los casts.
        $fila = $this->consentRequestRow();
        unset($fila['uuid'], $fila['created_at']);
        $creada = OutreachConsent::forceCreate($fila);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $creada->uuid);
        $leida = OutreachConsent::query()->findOrFail($creada->id);
        $this->assertSame(7001, $leida->member_id);
        $this->assertSame(1, $leida->recorded_by_admin_id);
        $this->assertNull($leida->redacted_at);

        // Nada de modificar: ni por el modelo, ni por su constructor en bloque.
        foreach ([
            'save' => function () use ($leida): void {
                $leida->evidence = 'otra';
                $leida->save();
            },
            'delete' => fn () => $leida->delete(),
            'destroy' => fn () => OutreachConsent::destroy($leida->id),
            'increment' => fn () => $leida->increment('member_id'),
            'query update' => fn () => OutreachConsent::query()->whereKey($leida->id)->update(['evidence' => 'otra']),
            'query delete' => fn () => OutreachConsent::query()->whereKey($leida->id)->delete(),
            'query forceDelete' => fn () => OutreachConsent::query()->whereKey($leida->id)->forceDelete(),
            'query upsert' => fn () => OutreachConsent::query()->upsert([$fila], ['client_request_id'], ['evidence']),
            'query increment' => fn () => OutreachConsent::query()->whereKey($leida->id)->increment('member_id'),
            'query updateOrInsert' => fn () => OutreachConsent::query()->updateOrInsert(['id' => $leida->id], ['evidence' => 'otra']),
            'query truncate' => fn () => OutreachConsent::query()->truncate(),
        ] as $que => $intento) {
            try {
                $intento();
                $this->fail("El modelo dejó {$que}");
            } catch (LogicException $e) {
                $this->assertSame(OutreachConsent::insertOnlyMessage(), $e->getMessage(), $que);
            }
        }
        // update() ni llega al evento: `$guarded = ['*']` lo para antes (como en VoiceConsent).
        try {
            $leida->update(['evidence' => 'otra']);
            $this->fail('El modelo dejó update()');
        } catch (MassAssignmentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, $this->consentCount());
        $this->assertSame('Formulario firmado en recepción, carpeta de noviembre', $this->db()->table(self::T_CONSENTS)->value('evidence'));
    }

    // ── El ciclo y la lectura de la tanda ───────────────────────────────────

    public function test_el_ciclo_de_una_autorizacion_y_la_consulta_de_la_tanda(): void
    {
        // m:1 · pedida y confirmada (cuatro ojos) → granted con su número.
        $p1 = $this->requestConsent(['member_id' => 1]);
        $this->resolveConsent($p1);
        // m:2 · confirmada y luego retirada por el sistema → revoked.
        $p2 = $this->requestConsent(['member_id' => 2]);
        $this->resolveConsent($p2);
        $this->revokeConsent(['member_id' => 2]);
        // m:3 · retirada y vuelta a pedir y confirmar → granted otra vez (con el número nuevo).
        $this->resolveConsent($this->requestConsent(['member_id' => 3]));
        $this->revokeConsent(['member_id' => 3, 'actor_type' => 'admin', 'recorded_by_admin_id' => 3, 'recorded_by_name' => 'Caro', 'recorded_by_role' => 'recepcion']);
        $this->resolveConsent($this->requestConsent(['member_id' => 3, 'phone_ref' => $this->phoneRefOf('B')]), 'granted', ['confirmed_by_admin_id' => 3]);
        // m:4 · pendiente → nada.  m:5 · rechazada, por quien la pidió (retirarla es seguro) → nada.
        $this->requestConsent(['member_id' => 4]);
        $this->resolveConsent($this->requestConsent(['member_id' => 5]), 'rejected', ['confirmed_by_admin_id' => 1]);
        // m:6 · comercial confirmada; servicio pedido y confirmado; cobranza nada (la comercial no vale para cobranza).
        $this->resolveConsent($this->requestConsent(['member_id' => 6]));
        $this->resolveConsent($this->requestConsent(['member_id' => 6, 'purpose' => 'service', 'source' => 'member_request']));
        // La persona 7 tiene dos claves (su socio m:7 y su prospecto l:70): confirmada en l:70 y retirada después en m:7.
        $this->resolveConsent($this->requestConsent(['member_id' => null, 'lead_id' => 70]));
        $this->revokeConsent(['member_id' => 7]);
        // La persona 8: pedida en m:8, retirada en l:80 (la guarda es por clave y no lo ve), confirmada después en m:8:
        // la confirmación cuenta en el lugar de SU PETICIÓN, anterior a la retirada → revoked.
        $p8 = $this->requestConsent(['member_id' => 8]);
        $this->revokeConsent(['member_id' => null, 'lead_id' => 80]);
        $this->resolveConsent($p8);

        // Idempotencia de la API: la misma petición otra vez no es otra fila.
        $this->assertSame(0, $this->db()->table(self::T_CONSENTS)->insertOrIgnore(array_merge($this->consentRequestRow(['member_id' => 1]), ['client_request_id' => $p1['client_request_id']])));

        $tanda = $this->consentBatch([
            'p1' => ['m:1'], 'p2' => ['m:2'], 'p3' => ['m:3'], 'p4' => ['m:4'], 'p5' => ['m:5'], 'p6' => ['m:6'], 'p7' => ['m:7', 'l:70'], 'p8' => ['m:8', 'l:80'],
            'p9' => ['m:9'],
        ]);
        $ver = array_map(static fn (array $porFinalidad): array => array_map(static fn (array $e): string => $e['kind'].($e['phone_ref'] === null ? '' : ':'.substr($e['phone_ref'], 0, 8)), $porFinalidad), $tanda);
        $a = substr($this->phoneRefOf('A'), 0, 8);
        $b = substr($this->phoneRefOf('B'), 0, 8);
        $this->assertSame([
            'p1' => ['commercial' => "granted:{$a}"],
            'p2' => ['commercial' => 'revoked'],
            'p3' => ['commercial' => "granted:{$b}"],
            'p6' => ['commercial' => "granted:{$a}", 'service' => "granted:{$a}"],
            'p7' => ['commercial' => 'revoked'],
            'p8' => ['commercial' => 'revoked'],
        ], $ver, 'el último evento por persona, finalidad y canal; revoked gana; sin fila, ninguna');
        $this->assertSame(23, $this->consentCount());
    }

    // ── Cada invariante, con su negativa ────────────────────────────────────

    public function test_conjuntos_cerrados_de_tipo_finalidad_canal_fuente_y_actor(): void
    {
        foreach ([
            // Un tipo desconocido sobre una fila sin petición, número ni texto legal: solo falla el conjunto cerrado.
            'ocon_kind_chk' => $this->consentRevocationRow(['kind' => 'accepted', 'actor_type' => 'admin', 'recorded_by_admin_id' => 3, 'recorded_by_name' => 'Caro']),
            'ocon_purpose_chk' => $this->consentRequestRow(['purpose' => 'marketing']),
            'ocon_channel_chk' => $this->consentRequestRow(['channel' => 'voice_call']),
            'ocon_source_chk' => $this->consentRequestRow(['source' => 'phone_call']),
            'ocon_actor_type_chk' => $this->consentRequestRow(['actor_type' => 'robot']),
        ] as $check => $fila) {
            $this->assertConsentRule($fila, $check, $check);
        }
        $this->assertSame(0, $this->consentCount());
        $this->assertAccepted($this->consentInsertClosure($this->consentRequestRow(['purpose' => 'collections', 'source' => 'contract_checkbox'])), 'cobranza, por su propia fila');
    }

    public function test_la_persona_es_su_clave_con_su_referencia(): void
    {
        foreach ([
            'otra forma' => ['subject_key' => 'x:7001'],
            'clave de otro socio' => ['subject_key' => 'm:7002'],
            'socio cero' => ['member_id' => 0, 'subject_key' => 'm:0'],
            'prospecto con clave de socio' => ['member_id' => null, 'lead_id' => 9, 'subject_key' => 'm:9'],
            'sin socio ni prospecto' => ['member_id' => null, 'lead_id' => null, 'subject_key' => 'l:9'],
        ] as $que => $cambio) {
            $this->assertConsentRule($this->consentRequestRow($cambio), 'ocon_subject_chk', $que);
        }
        // Un socio puede llevar su prospecto como referencia (socio manda).
        $this->assertAccepted($this->consentInsertClosure($this->consentRequestRow(['lead_id' => 55])));
    }

    public function test_cuatro_ojos_por_cuenta(): void
    {
        $p = $this->requestConsent();
        // Quien registró no confirma su propia petición.
        $this->assertConsentRule($this->consentResolutionRow($p, 'granted', ['confirmed_by_admin_id' => 1]), 'ocon_four_eyes_chk', 'la misma cuenta');
        // Ni haciéndose pasar por otra en la copia de quién la registró: la base la compara con la petición.
        $this->assertConsentGuard($this->consentInsertClosure($this->consentResolutionRow($p, 'granted', ['recorded_by_admin_id' => 3, 'recorded_by_name' => 'Caro', 'confirmed_by_admin_id' => 1])),
            $this->consentReason('ocon_resolves_request'), 'INSERT', 'la copia de quién registró miente');
        // Una confirmación sin quien la resuelve, o una petición con él.
        $this->assertConsentRule($this->consentResolutionRow($p, 'granted', ['confirmed_by_name' => null]), 'ocon_confirmed_chk', 'sin nombre de quien confirma');
        $this->assertConsentRule($this->consentRequestRow(['confirmed_by_admin_id' => 2, 'confirmed_by_name' => 'Beto']), 'ocon_confirmed_chk', 'una petición ya confirmada');
        $this->assertSame(1, $this->consentCount());
        // Rechazarla sí puede quien la pidió (retirar es seguro); confirmarla, otra cuenta.
        $this->assertAccepted($this->consentInsertClosure($this->consentResolutionRow($this->requestConsent(), 'rejected', ['confirmed_by_admin_id' => 1])));
        $this->assertAccepted($this->consentInsertClosure($this->consentResolutionRow($p, 'granted', ['confirmed_by_admin_id' => 3])));
    }

    public function test_una_resolucion_por_peticion_que_casa_con_ella(): void
    {
        $p = $this->requestConsent();
        $this->resolveConsent($p);
        foreach (['otra confirmación' => 'granted', 'un rechazo después' => 'rejected'] as $que => $kind) {
            $this->assertUniqueRejects($this->consentInsertClosure($this->consentResolutionRow($p, $kind, ['confirmed_by_admin_id' => 3])), 'ocon_request_unique', self::T_CONSENTS, ['request_uuid'], $que);
        }

        $q = $this->requestConsent(['member_id' => 7002]);
        $motivo = $this->consentReason('ocon_resolves_request');
        $granted = $this->db()->table(self::T_CONSENTS)->where('kind', 'granted')->first();
        foreach ([
            'una petición que no existe' => ['request_uuid' => (string) Str::uuid()],
            'una fila que no es una petición' => ['request_uuid' => $granted->uuid],
            'otra persona' => ['subject_key' => 'm:7003', 'member_id' => 7003],
            'otra finalidad' => ['purpose' => 'service'],
            'otra fuente' => ['source' => 'member_request'],
            'otro número' => ['phone_ref' => $this->phoneRefOf('B')],
            'otra versión del texto legal' => ['legal_version' => 'c9-whatsapp-comercial/2026-11-22.2'],
            'otro texto legal' => ['legal_hash' => $this->legalHashOf('Otro texto')],
            'otro rol de quien la registró' => ['recorded_by_role' => 'gerencia'],
        ] as $que => $cambio) {
            $this->assertConsentGuard($this->consentInsertClosure($this->consentResolutionRow($q, 'granted', $cambio)), $motivo, 'INSERT', $que);
        }
        // Un rechazo no copia número ni texto (no autoriza nada), pero sí la persona.
        $this->assertConsentGuard($this->consentInsertClosure($this->consentResolutionRow($q, 'rejected', ['subject_key' => 'm:7003', 'member_id' => 7003])), $motivo, 'INSERT', 'rechazo de otra persona');
        $this->assertSame(3, $this->consentCount());
    }

    public function test_la_peticion_va_solo_en_la_resolucion(): void
    {
        $p = $this->requestConsent();
        $this->assertConsentRule($this->consentRequestRow(['request_uuid' => $p['uuid']]), 'ocon_request_chk', 'una petición que apunta a otra');
        $this->assertConsentRule($this->consentRevocationRow(['request_uuid' => $p['uuid']]), 'ocon_request_chk', 'una retirada que apunta a una petición');
        if ($this->isPostgres()) {
            // Sin petición, la guarda de la resolución salta antes que el CHECK: se mira el CHECK solo.
            $this->assertSame(['ocon_request_chk'], $this->violatedChecks(self::T_CONSENTS, $this->consentResolutionRow($p, 'granted', ['request_uuid' => null])));
        }
        // Viola dos reglas: en PG salta antes la guarda (BEFORE), en SQLite el equivalente del CHECK; las dos la rechazan.
        $sinPeticion = $this->consentInsertClosure($this->consentResolutionRow($p, 'granted', ['request_uuid' => null]));
        $this->isPostgres()
            ? $this->assertConsentGuard($sinPeticion, $this->consentReason('ocon_resolves_request'), 'INSERT', 'una confirmación sin petición')
            : $this->assertRejected($sinPeticion, '23000', $this->consentMigration()::message($this->consentMigration()::checks()['ocon_request_chk'][2], 'INSERT'), 'una confirmación sin petición');
    }

    public function test_idempotencia_por_la_peticion_del_cliente(): void
    {
        $p = $this->requestConsent();
        $otra = $this->consentRequestRow(['client_request_id' => $p['client_request_id'], 'member_id' => 7009]);
        $this->assertUniqueRejects($this->consentInsertClosure($otra), 'ocon_client_request_unique', self::T_CONSENTS, ['client_request_id']);
        $this->assertSame(0, $this->db()->table(self::T_CONSENTS)->insertOrIgnore($otra), 'ON CONFLICT DO NOTHING: la segunda vez no escribe');
        $this->assertUniqueRejects($this->consentInsertClosure($this->consentRequestRow(['uuid' => $p['uuid']])), 'ocon_uuid_unique', self::T_CONSENTS, ['uuid']);
        $this->assertSame(1, $this->consentCount());
    }

    public function test_la_evidencia_es_texto_plano_sin_telefonos(): void
    {
        $casos = [
            'siete dígitos seguidos' => 'Pidió que le escriban al 3001234 por la mañana',
            'un celular entero' => 'Su número es 3001234567',
            'vacía' => '   ',
        ];
        if ($this->isPostgres()) {
            // Lo que GLOB no expresa, solo en PG (la puerta PG es obligatoria): la serie con separadores, un correo, un control.
            $casos += [
                'un celular con espacios' => 'Llamar al 300 123 4567',
                'un celular con puntos y guiones' => 'Tel. 300.123-4567',
                'un correo' => 'Confirmó desde socio@ejemplo.com',
                'un salto de línea' => "Firmado\nen recepción",
            ];
        }
        foreach ($casos as $que => $texto) {
            $this->assertConsentRule($this->consentRequestRow(['evidence' => $texto]), 'ocon_evidence_chk', $que);
        }
        $larga = str_repeat('a', 301);
        $this->isPostgres()
            ? $this->assertRejected($this->consentInsertClosure($this->consentRequestRow(['evidence' => $larga])), '22001', 'character varying(300)', 'más de 300')
            : $this->assertConsentRule($this->consentRequestRow(['evidence' => $larga]), 'ocon_evidence_chk', 'más de 300');
        // Una fecha, un radicado corto o seis cifras no son un teléfono.
        foreach (['Firmado el 22 de noviembre, folio 123456', 'Contrato v3, casilla 2', str_repeat('b', 300)] as $texto) {
            $this->assertAccepted($this->consentInsertClosure($this->consentRequestRow(['evidence' => $texto])), $texto);
        }
    }

    public function test_los_textos_legales_van_en_la_peticion_y_en_su_confirmacion(): void
    {
        foreach ([
            'petición sin versión' => ['legal_version' => null],
            'petición sin huella' => ['legal_hash' => null],
            'versión con espacios' => ['legal_version' => 'contrato v3'],
            'huella que no es un sha256' => ['legal_hash' => strtoupper($this->legalHashOf('x'))],
        ] as $que => $cambio) {
            $this->assertConsentRule($this->consentRequestRow($cambio), 'ocon_legal_chk', $que);
        }
        $p = $this->requestConsent();
        $this->assertConsentRule($this->consentResolutionRow($p, 'rejected', ['legal_version' => $p['legal_version'], 'legal_hash' => $p['legal_hash']]),
            'ocon_legal_chk', 'un rechazo no lleva texto legal');
        $this->assertConsentRule($this->consentRevocationRow(['legal_version' => 'x', 'legal_hash' => $this->legalHashOf('x')]), 'ocon_legal_chk', 'una retirada tampoco');
        if ($this->isPostgres()) {
            $this->assertSame(['ocon_legal_chk'], $this->violatedChecks(self::T_CONSENTS, $this->consentResolutionRow($p, 'granted', ['legal_hash' => null])), 'confirmación sin huella');
        }
    }

    public function test_el_numero_es_su_seudonimo_y_solo_en_la_peticion_y_su_confirmacion(): void
    {
        foreach ([
            'petición sin número' => ['phone_ref' => null],
            'el número en claro' => ['phone_ref' => '570000000000'],
            'un seudónimo en mayúsculas' => ['phone_ref' => strtoupper($this->phoneRefOf('A'))],
        ] as $que => $cambio) {
            $this->assertConsentRule($this->consentRequestRow($cambio), 'ocon_phone_chk', $que);
        }
        $this->assertConsentRule($this->consentRevocationRow(['phone_ref' => $this->phoneRefOf('A')]), 'ocon_phone_chk', 'una retirada vale para la persona, sin número');
    }

    public function test_quien_registra_es_una_cuenta_y_el_sistema_solo_retira(): void
    {
        foreach ([
            'el sistema pide' => $this->consentRequestRow(['actor_type' => 'system', 'recorded_by_admin_id' => null, 'recorded_by_role' => null]),
            'una cuenta sin id' => $this->consentRequestRow(['recorded_by_admin_id' => null]),
            'sin nombre' => $this->consentRequestRow(['recorded_by_name' => ' ']),
            'el sistema con cuenta' => $this->consentRevocationRow(['recorded_by_admin_id' => 4]),
        ] as $que => $fila) {
            $this->assertConsentRule($fila, 'ocon_actor_chk', $que);
        }
        $this->assertAccepted($this->consentInsertClosure($this->consentRevocationRow()), 'el sistema retira (la palabra de baja)');
        $this->assertAccepted($this->consentInsertClosure($this->consentRevocationRow(['actor_type' => 'admin', 'recorded_by_admin_id' => 3, 'recorded_by_name' => 'Caro'])), 'una cuenta retira, sin segunda');
    }

    public function test_una_peticion_anterior_a_una_retirada_ya_no_se_confirma(): void
    {
        $vieja = $this->requestConsent();
        $this->revokeConsent();
        $this->assertConsentGuard($this->consentInsertClosure($this->consentResolutionRow($vieja)), $this->consentReason('ocon_grant_not_superseded'), 'INSERT', 'la retirada gana');
        // Rechazarla sí (no autoriza nada); y una petición nueva, posterior a la retirada, sí se confirma.
        $this->assertAccepted($this->consentInsertClosure($this->consentResolutionRow($vieja, 'rejected')));
        $this->resolveConsent($this->requestConsent());
        // La retirada de OTRA finalidad no la tapa.
        $servicio = $this->requestConsent(['purpose' => 'service']);
        $this->revokeConsent(['purpose' => 'commercial']);
        $this->assertAccepted($this->consentInsertClosure($this->consentResolutionRow($servicio)));
        $this->assertSame(['commercial' => 'revoked', 'service' => 'granted'], array_map(static fn (array $e): string => $e['kind'], $this->consentBatch(['x' => ['m:7001']])['x']));
    }

    // ── Solo inserción y la única mutación ──────────────────────────────────

    public function test_solo_insercion_ni_se_cambia_ni_se_borra(): void
    {
        $p = $this->requestConsent();
        $g = $this->resolveConsent($p);
        $inmutable = $this->consentReason('ocon_forbid_immutable_update');
        foreach ([
            'kind' => 'revoked', 'purpose' => 'service', 'channel' => 'sms', 'source' => 'member_request', 'request_uuid' => null, 'legal_hash' => $this->legalHashOf('y'),
            'legal_version' => 'v2', 'actor_type' => 'system', 'recorded_by_admin_id' => 2, 'confirmed_by_admin_id' => 1, 'confirmed_by_role' => 'x',
            'recorded_by_role' => 'x', 'client_request_id' => (string) Str::uuid(), 'uuid' => (string) Str::uuid(), 'created_at' => '2026-01-01 00:00:00',
        ] as $columna => $valor) {
            $this->assertConsentGuard(fn () => $this->db()->table(self::T_CONSENTS)->where('id', $g['id'])->update([$columna => $valor]), $inmutable, 'UPDATE', $columna);
        }
        $this->assertConsentGuard(fn () => $this->db()->table(self::T_CONSENTS)->where('id', $p['id'])->delete(), $this->consentReason('ocon_forbid_delete'), 'DELETE');
        if ($this->isPostgres()) {
            $this->assertRejected(fn () => DB::statement('TRUNCATE '.self::T_CONSENTS), 'P0001', 'outreach: las autorizaciones de contacto son de solo inserción (TRUNCATE en outreach_consents)');
        }
        // Nace sin redactar.
        $this->assertConsentGuard($this->consentInsertClosure($this->consentRequestRow(['redacted_at' => '2026-11-22 16:00:00'])), $this->consentReason('ocon_starts_unredacted'), 'INSERT');
        $this->assertSame(2, $this->consentCount());
    }

    public function test_la_unica_mutacion_es_la_redaccion_1581_entera_y_una_vez(): void
    {
        $p = $this->requestConsent(['lead_id' => 71]);
        $g = $this->resolveConsent($p);
        $otra = $this->requestConsent(['member_id' => 7002]);
        $redaccion = $this->consentReason('ocon_redaction_only');
        $q = fn () => $this->db()->table(self::T_CONSENTS)->where('id', $p['id']);
        foreach ([
            'solo la evidencia' => ['evidence' => '[suprimido]'],
            'solo la marca de tiempo' => ['redacted_at' => '2026-11-22 18:00:00'],
            'el número a otro' => ['phone_ref' => $this->phoneRefOf('C')],
            'a otra persona' => ['subject_key' => 'm:7002', 'member_id' => 7002, 'lead_id' => null],
            'sin el prospecto' => ['subject_key' => 'x:'.$p['id'], 'member_id' => null, 'phone_ref' => null, 'evidence' => '[suprimido]', 'redacted_at' => '2026-11-22 18:00:00'],
            'con otra marca' => ['subject_key' => 'x:'.$p['id'], 'member_id' => null, 'lead_id' => null, 'phone_ref' => null, 'evidence' => 'borrado', 'redacted_at' => '2026-11-22 18:00:00'],
            'con la clave de otra fila' => ['subject_key' => 'x:'.$otra['id'], 'member_id' => null, 'lead_id' => null, 'phone_ref' => null, 'evidence' => '[suprimido]', 'redacted_at' => '2026-11-22 18:00:00'],
        ] as $que => $cambio) {
            $this->assertConsentGuard(fn () => $q()->update($cambio), $redaccion, 'UPDATE', $que);
        }

        // La forma entera pasa (la de ops/supresion-1581.sql) y deja la fila válida para los CHECK.
        $this->assertSame(2, $this->redactSubject('m:7001'));
        $filas = $this->db()->table(self::T_CONSENTS)->whereIn('id', [$p['id'], $g['id']])->orderBy('id')->get();
        foreach ($filas as $f) {
            $this->assertSame(['x:'.$f->id, null, null, null, '[suprimido]'], [$f->subject_key, $f->member_id, $f->lead_id, $f->phone_ref, $f->evidence]);
            $this->assertNotNull($f->redacted_at);
            // Lo que no es de la persona se queda: tipo, finalidad, texto legal, quién registró y confirmó.
            $this->assertNotNull($f->legal_hash);
        }
        $this->assertSame('m:7002', $this->db()->table(self::T_CONSENTS)->where('id', $otra['id'])->value('subject_key'), 'la otra persona no cambia');
        // Una vez: ni otra redacción ni deshacerla.
        $this->assertConsentGuard(fn () => $q()->update(['redacted_at' => '2026-11-23 00:00:00']), $redaccion, 'UPDATE', 'otra vez');
        $this->assertConsentGuard(fn () => $q()->update(['subject_key' => 'm:7001', 'member_id' => 7001, 'redacted_at' => null]), $redaccion, 'UPDATE', 'deshacerla');
        // Su petición ya no se puede resolver, y la persona desaparece de la tanda.
        $pendiente = $this->requestConsent(['member_id' => 7004]);
        $this->redactSubject('m:7004');
        $this->assertConsentGuard($this->consentInsertClosure($this->consentResolutionRow($pendiente, 'granted')), $this->consentReason('ocon_resolves_request'), 'INSERT', 'una petición redactada');
        $this->assertSame([], $this->consentBatch(['a' => ['m:7001', 'l:71'], 'b' => ['m:7004']]));

        // El nombre del personal: solo a la marca, y solo si lo hay.
        $nombres = $this->consentReason('ocon_staff_names_redaction_only');
        $this->assertSame(2, $this->db()->table(self::T_CONSENTS)->where('recorded_by_admin_id', 1)->where('member_id', 7002)->orWhere('id', $g['id'])->update(['recorded_by_name' => '[suprimido]']));
        $this->assertSame(1, $this->db()->table(self::T_CONSENTS)->where('confirmed_by_admin_id', 2)->update(['confirmed_by_name' => '[suprimido]']));
        $this->assertConsentGuard(fn () => $this->db()->table(self::T_CONSENTS)->where('id', $otra['id'])->update(['recorded_by_name' => 'Otra']), $nombres, 'UPDATE', 'a otro nombre');
        $this->assertConsentGuard(fn () => $this->db()->table(self::T_CONSENTS)->where('id', $otra['id'])->update(['confirmed_by_name' => '[suprimido]']), $nombres, 'UPDATE', 'donde no lo hay');
        $this->assertConsentGuard(fn () => $this->db()->table(self::T_CONSENTS)->where('id', $g['id'])->update(['confirmed_by_name' => 'Beto']), $nombres, 'UPDATE', 'deshacerlo');
    }

    // ── down() ──────────────────────────────────────────────────────────────

    public function test_down_se_niega_con_filas_y_sin_filas_vuelve_al_mismo_esquema(): void
    {
        $m = $this->consentMigration();
        $antes = $this->footprint([self::T_CONSENTS], $m::ownFunctions());

        DB::beginTransaction();
        try {
            $this->revokeConsent();
            try {
                $m->down();
                $this->fail('down() no se negó con una fila');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('outreach_consents (C9): down() se niega a revertir el libro de autorizaciones con datos (1 filas)', $e->getMessage());
            }
            $this->assertSame($antes, $this->footprint([self::T_CONSENTS], $m::ownFunctions()), 'down() tocó el esquema con una fila');
        } finally {
            DB::rollBack();
        }

        DB::beginTransaction();
        try {
            $m->down();
            $this->assertFalse(Schema::hasTable(self::T_CONSENTS));
            if ($this->isPostgres()) {
                $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname LIKE 'outreach\\_consent\\_%'"), 'down() dejó funciones');
                $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname = 'outreach_forbid'"), 'la función de DB9 no es suya');
            }
            $m->up();
            $this->assertSame($antes, $this->footprint([self::T_CONSENTS], $m::ownFunctions()), 'up() no dejó lo mismo');
            $this->assertConsentGuard($this->consentInsertClosure($this->consentRequestRow(['redacted_at' => '2026-11-22 16:00:00'])), $this->consentReason('ocon_starts_unredacted'), 'INSERT', 'las guardas vuelven');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($antes, $this->footprint([self::T_CONSENTS], $m::ownFunctions()));
    }

    // ── Solo PostgreSQL: funciones y el rol de la aplicación ────────────────

    public function test_solo_en_postgres_las_funciones_son_propias_invoker_y_cualificadas(): void
    {
        $this->requirePostgres('las funciones de los disparadores solo existen en PostgreSQL.');

        $m = $this->consentMigration();
        $this->assertSame(['outreach_consent_chk_ocon_resolves_request', 'outreach_consent_chk_ocon_grant_not_superseded', 'outreach_consent_forbid'], $m::ownFunctions());
        $this->assertSame(3, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname LIKE 'outreach\\_consent\\_%'"));
        foreach ($m::ownFunctions() as $funcion) {
            $f = DB::selectOne('SELECT l.lanname AS lang, p.provolatile AS vol, p.prosecdef::int AS definer, pg_get_userbyid(p.proowner) AS owner, p.prosrc AS src
                FROM pg_proc p JOIN pg_language l ON l.oid = p.prolang WHERE p.proname = ?', [$funcion]);
            $this->assertSame(['plpgsql', 'v', 0, (string) DB::scalar('SELECT current_user')], [$f->lang, $f->vol, (int) $f->definer, $f->owner], $funcion);
            preg_match_all('/(?:FROM|JOIN)\s+([a-z_.]+)\s+AS\s/', (string) $f->src, $tablas);
            foreach ($tablas[1] as $tabla) {
                $this->assertSame('public.outreach_consents', $tabla, $funcion);
            }
            $this->assertStringContainsString('outreach: %', (string) $f->src, $funcion);
        }
        // Ningún disparador de esta tabla usa outreach_forbid() (la de DB9): la supresión no los apaga.
        $this->assertSame(['outreach_consent_chk_ocon_grant_not_superseded', 'outreach_consent_chk_ocon_resolves_request', 'outreach_consent_forbid'], array_map(static fn (object $f): string => $f->proname,
            DB::select("SELECT DISTINCT p.proname FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid WHERE t.tgrelid = 'outreach_consents'::regclass AND NOT t.tgisinternal ORDER BY 1")));
    }

    public function test_solo_en_postgres_el_rol_de_la_aplicacion_inserta_y_lee_pero_no_cambia_ni_borra(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (): void {
            $m = $this->consentMigration();
            $m->down();
            $m->up();
            $this->assertSame([true, true, false, false, false], $this->privileges(self::T_CONSENTS), 'SELECT e INSERT; ni UPDATE, ni DELETE, ni TRUNCATE');
            $rol = $this->appRoleName();
            $this->assertNotSame($rol, (string) DB::scalar("SELECT tableowner FROM pg_tables WHERE tablename = 'outreach_consents'"), 'la prueba corre con un rol que NO es el dueño');

            // Todo el ciclo como la aplicación: pedir, confirmar (la FK a la petición no pide UPDATE), retirar, repetir, leer la tanda.
            [$estado, $mensaje] = $this->asApp(function (): void {
                $p = $this->requestConsent();
                $this->resolveConsent($p);
                $this->revokeConsent(['member_id' => 7002]);
                $this->resolveConsent($this->requestConsent(['member_id' => 7002]));
                $this->assertSame(0, $this->db()->table(self::T_CONSENTS)->insertOrIgnore(array_merge($this->consentRequestRow(), ['client_request_id' => $p['client_request_id']])));
                $this->assertSame(['a' => 'granted', 'b' => 'granted'], array_map(static fn (array $f): string => $f['commercial']['kind'], $this->consentBatch(['a' => ['m:7001'], 'b' => ['m:7002']])));
                $this->assertSame(5, $this->consentCount());
                $this->assertSame(5, OutreachConsent::query()->count(), 'el modelo lee');
            });
            $this->assertNull($estado, "el rol de la aplicación no completa el ciclo: {$mensaje}");

            $uno = (string) Str::uuid();
            foreach ([
                'UPDATE outreach_consents SET evidence = evidence WHERE id = -1',
                "UPDATE outreach_consents SET subject_key = 'x:' || id, member_id = NULL, lead_id = NULL, phone_ref = NULL, evidence = '[suprimido]', redacted_at = now() WHERE id = -1",
                "UPDATE outreach_consents SET recorded_by_name = '[suprimido]' WHERE id = -1",
                'DELETE FROM outreach_consents WHERE id = -1',
                'TRUNCATE outreach_consents',
                'SELECT id FROM outreach_consents WHERE id = -1 FOR UPDATE',
                'SELECT id FROM outreach_consents WHERE id = -1 FOR SHARE',
                "INSERT INTO outreach_consents (uuid, client_request_id, subject_key, member_id, purpose, channel, kind, source, evidence, actor_type, recorded_by_name, created_at) VALUES ('{$uno}', '{$uno}', 'm:1', 1, 'commercial', 'whatsapp', 'revoked', 'member_request', 'baja', 'system', 'Sistema', now()) "
                    .'ON CONFLICT (client_request_id) DO UPDATE SET evidence = EXCLUDED.evidence',
                'ALTER TABLE outreach_consents DISABLE TRIGGER ocon_forbid_immutable_update',
                'ALTER TABLE outreach_consents DROP CONSTRAINT ocon_four_eyes_chk',
                'DROP INDEX ocon_request_unique',
                'CREATE OR REPLACE FUNCTION outreach_consent_forbid() RETURNS trigger LANGUAGE plpgsql AS $fn$ BEGIN RETURN NEW; END; $fn$',
                'SET session_replication_role = replica',
            ] as $sql) {
                [$sqlstate, $mensaje] = $this->asApp(fn () => DB::statement($sql));
                $this->assertSame('42501', $sqlstate, "{$sql}: {$mensaje}");
            }
        });
    }

    public function test_solo_en_postgres_up_falla_sin_dejar_nada_si_el_rol_aun_puede_cambiar(): void
    {
        $this->requirePostgres('los roles y los privilegios solo existen en PostgreSQL.');

        $this->withAppRole(function (): void {
            $m = $this->consentMigration();
            $m->down();
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT UPDATE ON TABLES TO PUBLIC');
            try {
                $m->up();
                $this->fail('up() dio por buena una aplicación que todavía puede cambiar el libro');
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(QueryException::class, $e, $e->getMessage());
                $this->assertStringContainsString('todavía puede UPDATE en outreach_consents', $e->getMessage());
            }
            $this->assertFalse(Schema::hasTable(self::T_CONSENTS), 'up() dejó la tabla');
            $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_proc WHERE proname LIKE 'outreach\\_consent\\_%'"), 'up() dejó funciones');
        });
    }

    public function test_solo_en_postgres_sin_el_rol_no_hay_nada_que_revocar_salvo_en_produccion(): void
    {
        $this->requirePostgres('los roles solo existen en PostgreSQL.');

        $m = $this->consentMigration();
        $nadie = 'ultron_studio_c9_rol_inexistente';
        putenv('ULTRON_STUDIO_DB_APP_ROLE='.$nadie);
        DB::beginTransaction();
        try {
            $m->down();
            $m->up();
            $this->assertContains('ocon_redaction_only', $this->triggersOn(self::T_CONSENTS));
            $m->down();
            $this->app['env'] = 'production';
            try {
                $m->up();
                $this->fail('En producción, up() se calló un REVOKE que no podía aplicar');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString("outreach_consents (C9): el rol de la aplicación «{$nadie}» no existe en este clúster y APP_ENV es production", $e->getMessage());
            } finally {
                $this->app['env'] = 'testing';
            }
            $this->assertFalse(Schema::hasTable(self::T_CONSENTS), 'up() dejó la mitad');
        } finally {
            DB::rollBack();
        }
        $this->assertContains('ocon_redaction_only', $this->triggersOn(self::T_CONSENTS));

        putenv('ULTRON_STUDIO_DB_APP_ROLE=iron_app; DROP TABLE outreach_consents');
        $this->expectException(RuntimeException::class);
        $m::appRole();
    }
}
