<?php

namespace Tests\Feature\Outreach\B9Cierre;

use App\Services\Outreach\Core\DecisionTraceText;
use App\Services\Outreach\Run\Channels\VoiceStrategyChannel;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Telephony\OutboundPolicy;
use Tests\TestCase;

/**
 * Cierre de B9 · W9 gancho 6 y V9 §5/§7: cada CÓDIGO DE DETALLE que los
 * canales de W9 y V9 escriben en trabajos, resultados y decisiones tiene su
 * frase en `DecisionTraceText` (y, por el fixture exportado, en el espejo del
 * CRM). Dos vías: la lista de los informes de W9 (§1.1, §3) y V9 (§1, §5, §7)
 * y un BARRIDO del código de los canales y los normalizadores (todo
 * `ChannelAttempt::failed('…')`/`ambiguous('…')` y todo detalle literal), para
 * que un código nuevo sin frase haga caer esta prueba. Un ESTADO de trabajo no
 * es un código de detalle: el CRM pinta los dos con la misma función y no
 * puede llevar la frase de otro.
 */
class DetailCodeTextsTest extends TestCase
{
    /** W9 · el canal (§1.1), la puerta de WhatsApp (§3) y su normalizador (§1.4). */
    private const W9 = [
        'sends_disabled_in_code', 'job_channel_mismatch', 'mode_not_live', 'channel_disabled', 'channel_not_live', 'budget_not_reserved',
        'template_not_approved', 'template_not_approved_by_meta', 'unknown_subject', 'no_whatsapp_identity', 'no_consent_for_purpose_channel',
        'opt_out_word', 'reply_pending', 'template_marker_unresolvable', 'template_violates_restriction', 'template_parameter_invalid',
        'do_not_contact', 'no_proactive_consent', 'lead_without_phone', 'do_not_contact_sibling', 'agent_paused', 'no_whatsapp_conversation',
        'meta_disabled_or_unconfigured', 'provider_send_failed', 'human_takeover', 'gate_refused', 'gate_error', 'already_dispatched',
        'dead', 'template_metadata_invalid', 'meta_failed', 'outbox_dead',
    ];

    /** V9 · el canal (§1), la puerta (§7: 409 de carrera e identificador, 422 con sus motivos) y el normalizador (§1.2). */
    private const V9 = [
        'channel_not_live', 'channel_mismatch', 'mode_off', 'channel_disabled', 'sends_disabled_in_code', 'budget_not_reserved', 'unknown_subject',
        'no_consent_for_purpose_channel', 'voice_call_ref_missing', 'voice_gate_error', 'voice_gate_denied',
        'outbound_denied', 'outbound_race', 'client_request_reused', 'job_uuid_invalid', 'purpose_key_invalid', 'subject_invalid', 'strategy_key_invalid',
        'no_volver_a_llamar', 'solicitar_humano', 'guard_clean', 'guard_violation', 'busy', 'no_answer', 'call_failed', 'provider_rejected',
        'hung_up', 'voice_call_absent', 'voice_unknown', 'job_failed', 'job_lost',
    ];

    /** La ejecución: las cancelaciones de m-9 y la conciliación. */
    private const RUN = ['strategy_moved', 'catalog_incompatible', 'chain_unreadable', 'stale'];

    /** Los estados de `outreach_jobs` que NO son también resultados (`ambiguous` sí lo es). */
    private const JOB_STATUSES_WITHOUT_TEXT = ['planned', 'claimed', 'dispatching', 'started', 'simulated', 'failed_to_start', 'canceled', 'expired'];

    /** Los ficheros cuyos códigos se barren. */
    private const SCANNED = [
        'app/Services/Outreach/Run/Channels/WhatsappTemplateChannel.php',
        'app/Services/Outreach/Run/Channels/VoiceStrategyChannel.php',
        'app/Services/Outreach/Run/WhatsappTemplateOutcomes.php',
        'app/Services/Outreach/Run/VoiceCallOutcomes.php',
    ];

    public function test_cada_codigo_de_detalle_de_w9_v9_y_de_la_ejecucion_tiene_su_frase(): void
    {
        $codigos = [...self::W9, ...self::V9, ...self::RUN, ...OutboundPolicy::REASONS, ...array_keys(OutreachDispatcher::TRANSIENT_FAILURES),
            WhatsappTemplateChannel::SENDS_CLOSED, WhatsappTemplateChannel::NOT_LIVE, WhatsappTemplateChannel::NOT_APPROVED_BY_META, VoiceStrategyChannel::NOT_LIVE];
        $sin = array_values(array_filter(array_unique($codigos), static fn (string $c): bool => DecisionTraceText::text($c) === null));
        $this->assertSame([], $sin, 'Códigos de detalle sin frase en DecisionTraceText.');
        $this->assertCount(28, OutboundPolicy::REASONS, 'los 28 motivos del H8 (E16-21)');
    }

    public function test_el_barrido_del_codigo_de_los_canales_y_los_normalizadores_no_deja_codigos_sin_frase(): void
    {
        $vistos = [];
        foreach (self::SCANNED as $relativa) {
            $codigo = (string) file_get_contents(base_path($relativa));
            preg_match_all("/ChannelAttempt::(?:failed|ambiguous)\\('([a-z][a-z0-9_]*)'\\)/", $codigo, $a);
            preg_match_all("/'detail' => '([a-z][a-z0-9_]*)'/", $codigo, $b);
            preg_match_all("/self::code\\([^,]+, '([a-z][a-z0-9_]*)'\\)/", $codigo, $c);
            preg_match_all("/=> \\['(?:answered|no_answer|failed)', '([a-z][a-z0-9_]*)'\\]/", $codigo, $d);
            preg_match_all("/'(?:clean|violation)' => '([a-z][a-z0-9_]*)'/", $codigo, $e);
            preg_match_all("/\\?\\? '([a-z][a-z0-9_]*)'\\]/", $codigo, $f);
            preg_match_all("/: '(meta_[a-z_]+|outbox_[a-z_]+)'/", $codigo, $g);
            foreach ([...$a[1], ...$b[1], ...$c[1], ...$d[1], ...$e[1], ...$f[1], ...$g[1]] as $visto) {
                $vistos[$visto] = $relativa;
            }
        }
        $this->assertGreaterThanOrEqual(30, count($vistos), 'el barrido ve los códigos: '.json_encode(array_keys($vistos)));
        // `canceled` es también un ESTADO de trabajo (abajo): se enseña tal cual.
        unset($vistos['canceled']);
        $sin = array_keys(array_filter($vistos, static fn (string $f, string $c): bool => DecisionTraceText::text($c) === null, ARRAY_FILTER_USE_BOTH));
        $this->assertSame([], $sin, 'Códigos de los canales o normalizadores sin frase.');
    }

    public function test_el_rechazo_de_meta_con_su_codigo_usa_la_frase_de_meta_error_y_un_estado_de_trabajo_no_tiene_frase(): void
    {
        $this->assertSame(DecisionTraceText::text('meta_error'), DecisionTraceText::text(DecisionTraceText::META_ERROR_PREFIX.'131026'));
        $this->assertSame(DecisionTraceText::META_ERROR_PREFIX, DecisionTraceText::export()['metaErrorPrefix']);
        foreach (self::JOB_STATUSES_WITHOUT_TEXT as $estado) {
            $this->assertNull(DecisionTraceText::text($estado), "{$estado} es un estado de trabajo: el CRM lo pinta tal cual");
        }
    }
}
