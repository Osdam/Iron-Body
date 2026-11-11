<?php

namespace Tests\Unit\Outreach\Core;

use App\Models\MarketingLead;
use App\Services\Commercial\CommercialVocabulary;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\SalesIntents;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\UltronStudio\StudioContentHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El CATÁLOGO del H9 (D9-3, D9-4) contra `catalog-snapshot.json`: el `export`
 * es byte a byte el `catalog` del fixture, su huella la de `catalogHash`, las
 * claves de ciclo y la compatibilidad (DF-K5, DF-K6) caso a caso, y los
 * dominios copiados atados a sus fuentes del motor (si el motor cambia, esta
 * prueba obliga a decidir y a subir la versión).
 */
class OutreachCatalogTest extends TestCase
{
    use LoadsOutreachFixtures;

    /** Versión → huella. Cambiar un dato del catálogo sin subir la versión rompe aquí. */
    private const HUELLAS = [
        'outreach-catalog/2026-11-10.1' => '4580c4477ddc86ef14f0c5c7ce494d767caff102e5377d7f10a58e406295fe47',
        'outreach-catalog/2026-11-10.2' => 'ce5fcdab1e1265b70a1e7133b3eeccbd62bb2ab690acfa11a700ad6779a13039',
        'outreach-catalog/2026-11-11.1' => '079930a2a391f318ee7812a9b6e6fcce893458987ecbf5a14ec37d0232a86e11',
    ];

    public function test_el_export_es_el_catalogo_del_fixture_byte_a_byte(): void
    {
        $f = self::fixture('catalog-snapshot');
        $this->assertSame($f['catalogHash'], StudioContentHasher::hash($f['catalog']), 'el fixture es coherente consigo mismo');
        // L9-29: si el fixture aún es de la `.1`, se compara con el fixture más exactamente ese cambio.
        $catalogo = self::ratifiedCatalog($f['catalog']);
        [$version, $huella] = self::ratifiedPin($f['catalogVersion'], $f['catalogHash']);
        $bytes = $f['catalogCanonicalBytes'] + (self::fixturesBeforeL929() ? strlen(self::L9_29['toCycleKey']) - strlen(self::L9_29['fromCycleKey']) : 0);

        $this->assertSame($version, OutreachCatalog::VERSION);
        $this->assertCanonicalSame($catalogo, OutreachCatalog::export());
        $this->assertSame($bytes, strlen(CanonicalJson::encode(OutreachCatalog::export())));
        $this->assertSame($huella, OutreachCatalog::hash());
        // La huella es la de StudioContentHasher (la regla del fixture, `hashRule`).
        $this->assertSame($huella, StudioContentHasher::hash(OutreachCatalog::export()));
    }

    public function test_la_version_esta_atada_a_su_huella(): void
    {
        $this->assertArrayHasKey(OutreachCatalog::VERSION, self::HUELLAS, 'Versión nueva del catálogo: añade su huella y regenera los fixtures.');
        $this->assertSame(self::HUELLAS[OutreachCatalog::VERSION], OutreachCatalog::hash(), 'Los datos del catálogo cambiaron sin subir OutreachCatalog::VERSION.');
    }

    public function test_los_conjuntos_del_contrato(): void
    {
        $c = OutreachCatalog::export();
        $this->assertCount(41, $c['events']);
        $this->assertCount(42, $c['facts']);
        $this->assertSame(OutcomeTable::sortedOutcomes(), array_column($c['outcomes'], 'code'));
        $this->assertCount(18, $c['outcomes']);
        $this->assertSame(['days', 'hours', 'weeks'], OutreachCatalog::UNITS);
        $this->assertSame(['class_types', 'plans'], $c['dynamicDomains']);
        // Los 27 del vocabulario, todos utilizables en condiciones; no disparan human_requested y complaint_created (DF-K4) ni los cinco
        // que hoy nadie emite (I-5: no disponibles hasta que tengan emisor).
        $crm = array_filter($c['events'], static fn (array $e): bool => $e['class'] === 'crm_fact');
        $this->assertCount(27, $crm);
        $this->assertSame(['complaint_created', 'customer_satisfied', 'human_requested', 'objection_raised', 'offer_presented', 'offer_rejected', 'referral_opportunity'],
            array_values(array_column(array_filter($crm, static fn (array $e): bool => ! $e['triggerable']), 'key')));
        // I-5: no están disponibles los tres de integración (W9/V9) y los seis del vocabulario sin emisor; el constructor ofrece
        // como disparador solo lo disparable Y disponible: 20 del vocabulario (los 21 con emisor menos human_requested) y los 8 periódicos.
        $noDisponibles = array_values(array_column(array_filter($c['events'], static fn (array $e): bool => ! $e['available']), 'key'));
        $this->assertSame(['complaint_created', 'customer_satisfied', 'integration.inbound_message', 'integration.meta_status', 'integration.voice_call_status',
            'objection_raised', 'offer_presented', 'offer_rejected', 'referral_opportunity'], $noDisponibles);
        $ofrecidos = array_filter($c['events'], static fn (array $e): bool => $e['triggerable'] && $e['available']);
        $this->assertSame([20, 8], [count(array_filter($ofrecidos, static fn (array $e): bool => $e['class'] === 'crm_fact')), count(array_filter($ofrecidos, static fn (array $e): bool => $e['class'] === 'periodic'))]);
        foreach (OutreachCatalog::EVENTS_WITHOUT_EMITTER as $k) {
            $this->assertTrue(OutreachCatalog::event($k)['conditionUsable'], "{$k}: sigue legible en condiciones");
        }
        // Ningún hecho «tiene teléfono» habilita nada (D9-15) ni hay un hecho con datos personales.
        foreach (OutreachCatalog::export()['facts'] as $h) {
            $this->assertDoesNotMatchRegularExpression('/phone|telefono|name|nombre|document|email|amount|importe|birth/i', $h['key']);
        }
    }

    public function test_los_dominios_copiados_estan_atados_a_sus_fuentes(): void
    {
        $ordenado = static function (array $l): array {
            sort($l, SORT_STRING);

            return $l;
        };
        $this->assertSame($ordenado(CommercialVocabulary::GOALS), OutreachCatalog::GOALS);
        $this->assertSame(CommercialVocabulary::GOAL_PRIORITY, OutreachCatalog::GOAL_PRIORITY);
        $this->assertSame($ordenado(CommercialVocabulary::EVENTS), array_keys(OutreachCatalog::CRM_EVENTS));
        $this->assertSame($ordenado(CommercialPhaseMachine::PHASES), OutreachCatalog::LEAD_PHASES);
        $this->assertSame($ordenado(MarketingLead::CONSENT_STATUSES), OutreachCatalog::LEAD_CONSENT_STATUSES);
        $this->assertSame($ordenado([SalesIntents::TEMP_COLD, SalesIntents::TEMP_WARM, SalesIntents::TEMP_HOT, SalesIntents::TEMP_VERY_HOT, SalesIntents::TEMP_RISK]), OutreachCatalog::LEAD_TEMPERATURES);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function cycleKeys(): iterable
    {
        return self::cases('catalog-snapshot', ['cycleKeys']);
    }

    #[DataProvider('cycleKeys')]
    public function test_clave_de_ciclo(array $caso): void
    {
        $clave = OutreachCatalog::cycleKey($caso['event'], $caso['occurrence'], RelativeTime::parseInstant($caso['now']));
        $esperado = $caso['expected'];
        if ($caso['event'] === self::L9_29['event'] && self::fixturesBeforeL929()) {
            // L9-29: con la plantilla nueva, la pieza antigua sola (`class_session_id`) ya no forma clave.
            $this->assertSame(['class_session_id'], array_keys($caso['occurrence']));
            $esperado = ['cycleKey' => null, 'enrolls' => false];
        }

        $this->assertSame($esperado, ['cycleKey' => $clave, 'enrolls' => $clave !== null]);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function compatibility(): iterable
    {
        return self::cases('catalog-snapshot', ['compatibility']);
    }

    #[DataProvider('compatibility')]
    public function test_compatibilidad_de_una_version(array $caso): void
    {
        $this->assertSame($caso['expected'], OutreachCatalog::compatibility($caso['used']['facts'], $caso['used']['events']));
    }

    public function test_claves_de_ciclo_que_no_se_pueden_formar(): void
    {
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        $this->assertNull(OutreachCatalog::cycleKey('no.existe', [], $ahora));
        $this->assertNull(OutreachCatalog::cycleKey('membership.expiring_window', ['membership_end_date' => '2026-02-30'], $ahora), 'fecha imposible');
        $this->assertNull(OutreachCatalog::cycleKey('payment_failed', ['commercial_event_id' => 0], $ahora), 'id 0');
        $this->assertNull(OutreachCatalog::cycleKey('payment_failed', ['commercial_event_id' => '5'], $ahora), 'id como texto');
        $this->assertSame('rcv:9:0', OutreachCatalog::cycleKey('receivable.overdue', ['receivable_id' => 9, 'generation' => 0], $ahora), 'la generación 0 vale');
        $this->assertSame('job:6a000000-0000-4000-8000-000000000001', OutreachCatalog::cycleKey('outcome.no_answer', ['outreach_job_uuid' => '6a000000-0000-4000-8000-000000000001'], $ahora));
        $this->assertNull(OutreachCatalog::cycleKey('outcome.no_answer', ['outreach_job_uuid' => '6A000000-0000-4000-8000-000000000001'], $ahora), 'uuid en mayúsculas');
        $this->assertSame('msg:4:read', OutreachCatalog::cycleKey('integration.meta_status', ['marketing_message_id' => 4, 'status' => 'read'], $ahora));
        $this->assertNull(OutreachCatalog::cycleKey('integration.meta_status', ['marketing_message_id' => 4, 'status' => 'Read Now'], $ahora));
    }

    public function test_tramos_de_la_cartera_y_medios(): void
    {
        $this->assertSame(['0-7', '0-7', '8-30', '8-30', '31-90', '31-90', '>90', '>90'], array_map(OutreachCatalog::receivableBucket(...), [0, 7, 8, 30, 31, 90, 91, 36500]));
        $this->assertSame('0-7', OutreachCatalog::receivableBucket(-3), 'un número imposible cae en el primer tramo, nunca se enseña');
        $this->assertSame('whatsapp', OutreachCatalog::mediumOf('assisted:whatsapp'));
        $this->assertSame('voice', OutreachCatalog::mediumOf('voice_call'));
        $this->assertNull(OutreachCatalog::mediumOf('sms'));
        $this->assertNull(OutreachCatalog::fact('consent.commercial.whatsapp'), 'las entradas de la escalera no son hechos de condiciones');
        $this->assertSame('decimal', OutreachCatalog::fact('attendance.weekly_rate')['type']);
        $this->assertSame(2, OutreachCatalog::fact('attendance.weekly_rate')['scale']);
        $this->assertSame(['collections'], OutreachCatalog::fact('receivable.overdue_days_max')['purposes']);
    }

    /** L9-29: la clave de las clases es la clase y la fecha de la sesión (la fila de la sesión solo nace al iniciarla). */
    public function test_la_clave_de_ciclo_de_las_clases(): void
    {
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        $clase = static fn (array $piezas): ?string => OutreachCatalog::cycleKey('class.upcoming_with_capacity', $piezas, $ahora);
        $this->assertSame('cls:12:2026-11-12', $clase(['class_id' => 12, 'session_date' => '2026-11-12', 'class_session_id' => null]), 'lo que entrega la foto de B9-facts');
        $this->assertSame('cls:12:2026-11-12', $clase(['class_id' => 12, 'session_date' => '2026-11-12', 'class_session_id' => 3301]), 'la fila de la sesión no cambia la clave');
        $this->assertNull($clase(['class_session_id' => 3301]), 'la pieza antigua sola ya no forma clave');
        $this->assertNull($clase(['class_id' => 12]));
        $this->assertNull($clase(['class_id' => 12, 'session_date' => '2026-02-30']), 'fecha imposible');
        $this->assertNull($clase(['class_id' => 12, 'session_date' => '2026-11-12T10:00:00Z']), 'un instante no es una fecha');
        $this->assertNull($clase(['class_id' => 0, 'session_date' => '2026-11-12']));
        $this->assertNull($clase(['class_id' => '12', 'session_date' => '2026-11-12']), 'id como texto');

        $evento = OutreachCatalog::event('class.upcoming_with_capacity');
        $this->assertSame(['cls:{class_id}:{session_date}', 2], [$evento['cycleKey'], $evento['keyVersion']]);
        // Una versión hecha con la clave antigua (keyVersion 1) ya no es compatible: no se reinterpreta (N3).
        $this->assertSame(
            ['result' => 'catalog_incompatible', 'missing' => ['event:class.upcoming_with_capacity@1'], 'level' => 'N3'],
            OutreachCatalog::compatibility([], [['key' => 'class.upcoming_with_capacity', 'keyVersion' => 1]]),
        );
        $this->assertSame('compatible', OutreachCatalog::compatibility([], [['key' => 'class.upcoming_with_capacity', 'keyVersion' => 2]])['result']);
    }
}
