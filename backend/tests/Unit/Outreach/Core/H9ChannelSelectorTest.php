<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\ChannelSelector;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use PHPUnit\Framework\TestCase;

/**
 * §13.1 #9, texto literal del propietario: «Persona con autorización para
 * WhatsApp, pero no para voz». El selector elige WhatsApp y nunca voz; la
 * traza lo explica. En TODOS los órdenes de los cuatro canales y con
 * cualquier preferencia de la persona, sobre `PolicyLadder` y
 * `ChannelSelector` reales.
 */
class H9ChannelSelectorTest extends TestCase
{
    use H9Scenario;

    private const CANALES = ['voice_call', 'assisted:voice', 'whatsapp_template', 'assisted:whatsapp'];

    public function test_elige_whatsapp_y_nunca_voz_en_cualquier_orden_y_preferencia(): void
    {
        $porCanal = self::perChannel(self::CANALES, ['consent' => ['voice' => null]]);
        $vistas = 0;
        foreach (self::orders(self::CANALES) as $orden) {
            foreach ([null, 'voice', 'whatsapp'] as $preferencia) {
                $e = ChannelSelector::choose($orden, $porCanal, $preferencia);
                $caso = implode(',', $orden).' / '.json_encode($preferencia);
                $this->assertSame('selected', $e['verdict'], $caso);
                $this->assertSame(OutreachCatalog::MEDIUM_WHATSAPP, OutreachCatalog::mediumOf($e['channel']), $caso);
                // El primero de WhatsApp en el orden declarado: la preferencia solo reordena entre los permitidos.
                $deWhatsapp = array_values(array_filter($orden, static fn (string $c): bool => OutreachCatalog::mediumOf($c) === OutreachCatalog::MEDIUM_WHATSAPP));
                $this->assertSame($deWhatsapp[0], $e['channel'], $caso);
                $this->assertSame($preferencia === 'voice' ? ['instruction_ignored_by_policy'] : ($preferencia === null ? [] : ['preference_applied']), $e['annotations'], $caso);
                $vistas++;
            }
        }
        $this->assertSame(24 * 3, $vistas);
    }

    public function test_la_traza_lo_explica(): void
    {
        $canales = ['voice_call', 'whatsapp_template'];
        $porCanal = self::perChannel($canales, ['consent' => ['voice' => null]]);
        $e = ChannelSelector::choose($canales, $porCanal, 'voice');

        $this->assertSame([
            'Se permite el contacto.',
            'Canal voice_call: No se contacta — No hay autorización registrada para esta finalidad por este medio.',
            'Canal whatsapp_template: Se permite el contacto.',
            'Lo que pidió la persona no cabe en las reglas: manda el orden del paso.',
        ], DecisionTraceText::explain($porCanal['whatsapp_template'], $e));

        // La traza guardada lleva la elección con el motivo de la voz, sin datos de la persona, y es reproducible (T12).
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        $traza = static fn (): array => DecisionTrace::build(['kind' => 'plan'], FactSnapshot::fromArray([]), $ahora, [], ['channel' => $e]);
        $this->assertSame('whatsapp_template', $traza()['channel']['channel']);
        $this->assertSame(['no_consent_for_purpose_channel'], $traza()['channel']['perChannel'][0]['reasons']);
        $this->assertSame(DecisionTrace::hash($traza()), DecisionTrace::hash($traza()));
    }

    /**
     * Todas las permutaciones, en orden lexicográfico de posiciones.
     *
     * @param  list<string>  $items
     * @return list<list<string>>
     */
    private static function orders(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }
        $out = [];
        foreach ($items as $i => $primero) {
            $resto = $items;
            unset($resto[$i]);
            foreach (self::orders(array_values($resto)) as $cola) {
                $out[] = [$primero, ...$cola];
            }
        }

        return $out;
    }
}
