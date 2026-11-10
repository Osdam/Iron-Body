<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\ChannelSelector;
use App\Services\Outreach\Core\DecisionTraceText;
use PHPUnit\Framework\TestCase;

/**
 * §13.1 #8, texto literal del propietario: «Persona sin consentimiento para
 * llamadas». Canal voz denegado en N1 (`no_consent_for_purpose_channel`); si
 * hay otro canal autorizado, el selector lo toma; si no, asistido o `deny`.
 * Sobre `PolicyLadder` y `ChannelSelector` reales. Incluye la parte pura de
 * #6 (con B9-facts): sin autorización expresa, N1 niega.
 */
class H9PolicyLadderN1Test extends TestCase
{
    use H9Scenario;

    private const CANALES = ['voice_call', 'whatsapp_template', 'assisted:whatsapp'];

    private const SIN_VOZ = ['consent' => ['voice' => null]];

    public function test_la_voz_sin_consentimiento_se_niega_en_n1_y_ningun_nivel_inferior_la_levanta(): void
    {
        foreach (['voice_call', 'assisted:voice'] as $canal) {
            foreach ([null, 'unknown', 'pending'] as $estado) {
                // Además, todo lo de abajo «querría» contactar: la persona pidió que la llamen y la estrategia lo permite.
                $d = self::ladderFor($canal, ['consent' => ['voice' => $estado], 'conversational' => ['callbackAt' => '2026-11-12T20:00:00Z']]);
                $this->assertSame(['deny', false, 'N1', ['no_consent_for_purpose_channel'], null], [$d['verdict'], $d['allowed'], $d['level'], $d['reasons'], $d['retryAt']], "{$canal} con ".json_encode($estado));
                $this->assertSame(['N1' => 'deny', 'N2' => 'not_evaluated', 'N3' => 'not_evaluated', 'N4' => 'not_evaluated', 'N5' => 'not_evaluated', 'N6' => 'not_evaluated', 'N7' => 'not_evaluated'], self::statuses($d));
            }
        }
        // La negativa expresa tiene su propio código.
        $this->assertSame(['consent_denied'], self::ladderFor('voice_call', ['consent' => ['voice' => 'denied']])['reasons']);
    }

    public function test_si_hay_otro_canal_autorizado_el_selector_lo_toma(): void
    {
        $porCanal = self::perChannel(self::CANALES, self::SIN_VOZ);
        $e = ChannelSelector::choose(self::CANALES, $porCanal);

        $this->assertSame(['selected', 'whatsapp_template'], [$e['verdict'], $e['channel']]);
        $this->assertSame(['channel' => 'voice_call', 'verdict' => 'deny', 'level' => 'N1', 'reasons' => ['no_consent_for_purpose_channel'], 'retryAt' => null], $e['perChannel'][0]);
        $this->assertContains('Canal voice_call: No se contacta — No hay autorización registrada para esta finalidad por este medio.', DecisionTraceText::explain($porCanal['whatsapp_template'], $e));
    }

    public function test_si_no_queda_otro_canal_automatico_sale_el_asistido(): void
    {
        // La plantilla de WhatsApp no está aprobada (N4); el asistido no la necesita.
        $porCanal = [
            'voice_call' => self::ladderFor('voice_call', self::SIN_VOZ),
            'whatsapp_template' => self::ladderFor('whatsapp_template', self::SIN_VOZ + ['step' => ['templateApproved' => false]]),
            'assisted:whatsapp' => self::ladderFor('assisted:whatsapp', self::SIN_VOZ),
        ];
        $e = ChannelSelector::choose(self::CANALES, $porCanal);

        $this->assertSame(['selected', 'assisted:whatsapp'], [$e['verdict'], $e['channel']]);
        $this->assertSame([['N1', ['no_consent_for_purpose_channel']], ['N4', ['template_not_approved']]], [[$e['perChannel'][0]['level'], $e['perChannel'][0]['reasons']], [$e['perChannel'][1]['level'], $e['perChannel'][1]['reasons']]]);
    }

    public function test_sin_ningun_medio_autorizado_deny_con_todos_los_motivos(): void
    {
        $canales = [...self::CANALES, 'assisted:voice'];
        $e = ChannelSelector::choose($canales, self::perChannel($canales, ['consent' => ['voice' => null, 'whatsapp' => null]]));

        $this->assertSame(['deny', null, null], [$e['verdict'], $e['channel'], $e['retryAt']]);
        $this->assertCount(4, $e['perChannel']);
        foreach ($e['perChannel'] as $fila) {
            $this->assertSame(['deny', 'N1', ['no_consent_for_purpose_channel']], [$fila['verdict'], $fila['level'], $fila['reasons']], $fila['channel']);
        }
    }

    /** §13.1 #6, la parte pura (el fichero `H9LeadFollowupTest.php` es de B9-facts con B9-core): sin autorización expresa, N1 niega. */
    public function test_prospecto_sin_autorizacion_expresa_no_se_contacta(): void
    {
        $this->assertTrue(self::ladderFor('whatsapp_template')['allowed'], 'con la autorización registrada, sale');
        foreach ([null, 'unknown'] as $estado) {
            $d = self::ladderFor('whatsapp_template', ['consent' => ['whatsapp' => $estado]]);
            $this->assertSame(['deny', 'N1', ['no_consent_for_purpose_channel']], [$d['verdict'], $d['level'], $d['reasons']]);
        }
    }
}
