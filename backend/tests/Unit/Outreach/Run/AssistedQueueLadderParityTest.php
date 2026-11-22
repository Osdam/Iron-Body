<?php

namespace Tests\Unit\Outreach\Run;

use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutreachSendGate;
use PHPUnit\Framework\TestCase;

/**
 * m-1 de `informe-reviewer-bandeja.md`: la bandeja asistida rehace a mano una parte de N1–N6 sobre la entrada de la
 * escalera (`optedOut()`, `ineligible()`, `outOfCanaryScope()`…) y puede separarse de ella en silencio. Esta tabla
 * clasifica CADA motivo de {@see PolicyLadder::REASON_ORDER}, nivel por nivel, como:
 *  - `retiene:<códigos>`: la bandeja lo vuelve a mirar al ofrecer y al reclamar, y retiene con esos motivos de
 *    {@see AssistedQueue::WITHHELD_REASONS};
 *  - `ruta:outreach_disabled`: toda la ruta de la bandeja responde 409 (la maestra apagada);
 *  - `excluido:<decisión>`: no lo vuelve a mirar, por una decisión que consta en el contrato del H9 (D9-9.5, «La
 *    bandeja frente a la escalera»).
 * Si alguien añade un motivo a la escalera (o lo quita, o lo mueve de nivel), esta prueba cae hasta que se clasifique.
 * La misma tabla está en el contrato.
 */
class AssistedQueueLadderParityTest extends TestCase
{
    /** @var array<string, array<string, string>> nivel → motivo → clasificación */
    private const TABLA = [
        'N1' => [
            'calendar_outside_legal_hours' => 'excluido:al_salir',
            'calendar_sunday' => 'excluido:al_salir',
            'calendar_holiday' => 'excluido:al_salir',
            // Por voz, R3-1 (`opted_out` si consta la revocación; si no, `policy_unknown`); por WhatsApp comercial, I-1
            // (`policy_unknown`). WhatsApp de servicio y de cobranza, no: `ventana_24h`.
            'no_consent_for_purpose_channel' => 'retiene:opted_out,policy_unknown',
            // `lead.consent_denied` y la retirada del libro de C9 (C-1); sin leer, `policy_unknown`.
            'consent_denied' => 'retiene:opted_out,policy_unknown',
            'do_not_contact' => 'retiene:opted_out,policy_unknown',
            'do_not_contact_sibling' => 'retiene:opted_out,policy_unknown',
            'commercial_opt_out' => 'retiene:opted_out,policy_unknown',
            'voice_excluded' => 'retiene:opted_out,policy_unknown',
            'rne_check_missing' => 'retiene:rne_check_missing,policy_unknown',
            'subject_minor' => 'retiene:subject_minor',
            'daily_contact_limit' => 'excluido:al_salir',
            'weekly_contact_limit' => 'excluido:al_salir',
            'min_hours_between_contacts' => 'excluido:al_salir',
            'cross_channel_week_rule' => 'excluido:al_salir',
            'collections_channel_week_rule' => 'excluido:al_salir',
            'unknown_subject' => 'retiene:policy_unknown',
        ],
        'N2' => [
            'master_flag_off' => 'ruta:outreach_disabled',
            'mode_off' => 'retiene:brake',
            'outreach_brake_global' => 'retiene:brake',
            'outreach_brake_channel' => 'retiene:brake',
            'outreach_brake_strategy' => 'retiene:brake',
            'ultron_abort_latch' => 'excluido:D-3',
            'marketing_agent_paused' => 'excluido:D-3',
            'voice_braked' => 'excluido:D-3',
            'human_in_control' => 'excluido:es_una_persona',
            'legacy_autonomy_on' => 'excluido:D-3',
            'legacy_followups_active' => 'excluido:D-3',
            'global_suppression' => 'retiene:opted_out,policy_unknown',
        ],
        'N3' => [
            'studio_reference_invalid' => 'retiene:strategy_stopped',
            'catalog_incompatible' => 'excluido:al_salir',
            'payment_links_disabled' => 'excluido:al_salir',
            'template_violates_restriction' => 'excluido:al_salir',
            'canary_only_declared' => 'retiene:canary_scope_mismatch,policy_unknown',
        ],
        'N4' => [
            'sends_disabled_in_code' => 'excluido:solo_automaticos',
            'channel_disabled' => 'retiene:brake',
            'channel_not_live' => 'excluido:solo_automaticos',
            'template_not_approved' => 'retiene:template_not_approved',
            'template_category_mismatch' => 'excluido:al_salir',
            // Sin identidad de WhatsApp no hay consentimiento comercial concedido: I-1 (`policy_unknown`).
            'no_whatsapp_identity' => 'retiene:policy_unknown',
            // Sin contacto de voz activo: R3-1 y R3-3 (`policy_unknown`).
            'no_voice_contact' => 'retiene:policy_unknown',
            'voice_policy:*' => 'excluido:solo_automaticos',
            'budget_zero' => 'excluido:al_salir',
            'budget_exhausted' => 'excluido:solo_automaticos',
            'channel_daily_cap' => 'excluido:solo_automaticos',
            'assisted_capacity_reached' => 'excluido:al_salir',
        ],
        'N5' => [
            'strategy_not_published' => 'retiene:strategy_stopped',
            'strategy_paused' => 'retiene:strategy_stopped',
            'chain_unreadable' => 'retiene:strategy_stopped',
            'canary_scope_mismatch' => 'retiene:canary_scope_mismatch,policy_unknown',
            'calendar_outside_own_hours' => 'excluido:al_salir',
            'outside_strategy_schedule' => 'excluido:al_salir',
            'max_touches_reached' => 'excluido:al_salir',
            'strategy_daily_cap' => 'excluido:al_salir',
            'enrollment_window_over' => 'excluido:al_salir',
            'cooldown_active' => 'excluido:al_salir',
            'step_guard_false' => 'excluido:al_salir',
        ],
        'N6' => [
            'success_reached' => 'excluido:F-4',
            'context_changed' => 'excluido:F-4',
            'human_requested_recently' => 'excluido:L9-66',
            'complaint_recent' => 'retiene:complaint_recent,complaint_unknown',
            'collections_overdue_blocks_commercial' => 'excluido:F-4',
            'purpose_conflict_today' => 'excluido:al_salir',
            'lower_priority' => 'excluido:al_salir',
        ],
        'N7' => [
            'callback_scheduled' => 'excluido:al_salir',
            'enrollment_window_over' => 'excluido:al_salir',
        ],
    ];

    /**
     * Las decisiones por las que la bandeja NO vuelve a mirar un motivo. Cada una consta en el contrato del H9 con su
     * nombre exacto (la tabla «La bandeja frente a la escalera», D9-9.5).
     */
    private const DECISIONES = [
        'al_salir' => 'lo decidió la escalera al salir (horario, frecuencia, cupos, ventana, enfriamiento, guarda, contenido de la versión); no es una petición de la persona y `expired` acota el plazo',
        'solo_automaticos' => 'solo lo evalúa un canal automático (la constante de envío, el presupuesto en dólares, la voz del H8); un asistido nunca lo recibe',
        'es_una_persona' => 'el asistido ya es una persona: la toma humana de la conversación no lo para',
        'D-3' => 'los frenos ajenos al H9 no retienen la bandeja (pendiente del propietario)',
        'F-4' => 'el contexto de N6 no se reevalúa mientras la inscripción espera el resultado asistido (nota de diseño)',
        'L9-66' => '`human_requested` no retira nada',
    ];

    /** @return array<string, list<string>> */
    private static function escalera(): array
    {
        return PolicyLadder::REASON_ORDER;
    }

    public function test_la_tabla_clasifica_exactamente_los_motivos_de_la_escalera_nivel_por_nivel(): void
    {
        $this->assertSame(array_keys(self::escalera()), array_keys(self::TABLA), 'los mismos niveles, en el mismo orden');
        foreach (self::escalera() as $nivel => $motivos) {
            $deLaTabla = array_keys(self::TABLA[$nivel]);
            sort($motivos);
            sort($deLaTabla);
            $this->assertSame($motivos, $deLaTabla, "{$nivel}: cada motivo de la escalera, clasificado (y ninguno de más)");
        }
    }

    public function test_cada_clasificacion_nombra_un_motivo_de_la_bandeja_o_una_decision_del_contrato(): void
    {
        $contrato = (string) file_get_contents(dirname(__DIR__, 4).'/docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md');
        $this->assertStringContainsString('La bandeja frente a la escalera', $contrato);
        $cubiertos = [];
        foreach (self::TABLA as $nivel => $motivos) {
            foreach ($motivos as $motivo => $clase) {
                [$tipo, $valor] = explode(':', $clase, 2);
                match ($tipo) {
                    'retiene' => (function () use ($valor, $nivel, $motivo, &$cubiertos): void {
                        foreach (explode(',', $valor) as $codigo) {
                            $this->assertContains($codigo, AssistedQueue::WITHHELD_REASONS, "{$nivel}.{$motivo}: {$codigo} es un motivo de la bandeja");
                            $cubiertos[$codigo] = true;
                        }
                    })(),
                    'ruta' => $this->assertSame('outreach_disabled', $valor, "{$nivel}.{$motivo}"),
                    'excluido' => (function () use ($valor, $nivel, $motivo, $contrato): void {
                        $this->assertArrayHasKey($valor, self::DECISIONES, "{$nivel}.{$motivo}: decisión conocida");
                        $this->assertStringContainsString("`excluido:{$valor}`", $contrato, "{$nivel}.{$motivo}: la decisión {$valor} consta en el contrato");
                    })(),
                    default => $this->fail("{$nivel}.{$motivo}: clasificación desconocida «{$clase}»"),
                };
            }
        }
        // Y al revés: la bandeja no retiene por un motivo que la escalera no tenga, salvo el plazo del trabajo (el borde del despachador).
        $sinEscalera = array_values(array_diff(AssistedQueue::WITHHELD_REASONS, array_keys($cubiertos)));
        $this->assertSame(['expired'], $sinEscalera);
        foreach (array_keys(self::DECISIONES) as $decision) {
            $this->assertStringContainsString("`excluido:{$decision}`", $contrato, "la decisión {$decision} consta en el contrato");
        }
    }

    public function test_lo_que_solo_evaluan_los_canales_automaticos_no_le_llega_al_asistido(): void
    {
        // `channel_not_live`: el asistido es un canal implementado; `sends_disabled_in_code`, `budget_exhausted`,
        // `channel_daily_cap` y `voice_policy:*` solo los marca la escalera a un canal automático (PolicyLadder, N4).
        $this->assertTrue(OutreachSendGate::channelLive('assisted'));
        $this->assertSame('assisted', OutreachSendGate::jobChannelOf('assisted:whatsapp'));
        $this->assertSame('assisted', OutreachSendGate::jobChannelOf('assisted:voice'));
    }
}
