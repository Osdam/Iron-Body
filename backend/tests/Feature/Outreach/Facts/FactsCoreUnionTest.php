<?php

namespace Tests\Feature\Outreach\Facts;

use App\Models\Member;
use App\Services\Outreach\Core\ContactBudget;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\ContactHistoryFacts;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\FactNames;
use App\Services\Outreach\Facts\OperationalBrakes;
use App\Services\Outreach\Facts\SubjectKey;
use Tests\TestCase;

/**
 * B9-facts · LA UNIÓN CON EL NÚCLEO DE B9-core (contrato H9, D9-4, D9-7). Si
 * el catálogo, el presupuesto o la escalera cambian sus nombres o tipos, esta
 * prueba cae y obliga a cambiar los adaptadores en el mismo commit (no se
 * descubre en producción).
 */
class FactsCoreUnionTest extends TestCase
{
    public function test_los_hechos_de_la_foto_son_los_del_catalogo_con_sus_tipos(): void
    {
        $catalogo = OutreachCatalog::export()['facts'];
        $porClave = [];
        foreach ($catalogo as $k => $f) {
            $porClave[is_int($k) ? $f['key'] : $k] = $f;
        }
        $escalares = array_diff(array_keys($porClave), FactNames::HISTORY_FACTS);
        sort($escalares);
        $mios = array_keys(FactNames::FACT_TYPES);
        sort($mios);
        $this->assertSame($escalares, $mios, 'Los hechos escalares de la foto no son los del catálogo.');
        foreach (FactNames::FACT_TYPES as $hecho => $tipo) {
            $this->assertSame($porClave[$hecho]['type'], $tipo, "Tipo distinto para {$hecho}.");
        }
        foreach ($porClave as $hecho => $f) {
            if (($f['nullable'] ?? true) === false) {
                $this->assertContains($hecho, FactNames::NOT_NULLABLE, "{$hecho} no admite nulo en el catálogo.");
            }
        }
        foreach (FactNames::HISTORY_FACTS as $h) {
            $this->assertArrayHasKey($h, $porClave);
        }
    }

    public function test_el_historial_habla_el_idioma_del_presupuesto_y_los_frenos_el_de_la_escalera(): void
    {
        $this->assertSame([], array_values(array_diff(ContactHistoryFacts::SOURCES, ContactBudget::COUNTED_SOURCES)));
        $this->assertContains(ContactHistoryFacts::LEGACY_PURPOSE, OutreachCatalog::PURPOSES);
        // Cada freno ajeno es un motivo de N2 de la escalera (con su mismo nombre).
        foreach (OperationalBrakes::KEYS as $motivo) {
            $this->assertContains($motivo, PolicyLadder::REASON_ORDER['N2'], "El freno {$motivo} no es un motivo de N2.");
        }
        // Las claves de persona que pone la proyección existen en la entrada de la escalera.
        foreach (['subject', 'consent', 'optOut', 'voice', 'history', 'conversation', 'identity', 'brakes', 'flags'] as $k) {
            $this->assertArrayHasKey($k, PolicyLadder::SPEC);
        }
        $this->assertSame(RelativeTime::ZONE, Member::BUSINESS_TZ);
        $this->assertTrue(SubjectKey::isValid('m:1') && ! SubjectKey::isValid('m:01'));
    }

    public function test_la_seleccion_de_una_condicion_pide_solo_lo_que_usa(): void
    {
        $sel = CoreProjection::selectionFor(['facts' => ['membership.days_to_expiry', 'contact.count'], 'events' => ['human_requested', 'no_existe'], 'contacts' => true, 'transitions' => false], false);
        $this->assertSame(['membership.days_to_expiry'], $sel->facts);
        $this->assertSame(['human_requested'], $sel->events);
        $this->assertTrue($sel->contacts);
        $this->assertFalse($sel->policy);
        $this->assertFalse($sel->transitions);
        $conPolitica = CoreProjection::selectionFor([], true);
        $this->assertTrue($conPolitica->policy && $conPolitica->global && $conPolitica->contacts);
        $this->assertContains('subject.is_minor', $conPolitica->facts);
    }
}
