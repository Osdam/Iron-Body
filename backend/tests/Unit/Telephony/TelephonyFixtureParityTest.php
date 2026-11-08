<?php

namespace Tests\Unit\Telephony;

use App\Services\Telephony\InboundPolicy;
use App\Services\Telephony\OutboundPolicy;
use App\Services\Telephony\OutboundSchedule;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoiceConsentRule;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceExclusionRule;
use App\Services\Telephony\VoiceFrequency;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\Telephony\VoiceTranscriptGuard;
use App\Services\Voice\ElevenLabs\ElevenLabsEventVerifier;
use DateTimeImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PARIDAD de la telefonía de B8-b con los fixtures de TEST8-a (contrato H8;
 * `tests/fixtures/voice/*.json`). Cada caso, por su nombre.
 *
 * LA CONSTANTE (protección 2): en el árbol compartido
 * `CALLS_ENABLED_IN_CODE = false`, así que se compara la columna `codeClosed`
 * tal cual. La columna `codeOpen` se comprueba quitando de lo que da la
 * política `calls_disabled_in_code`: las dos políticas ACUMULAN los motivos y
 * ninguno depende de la constante salvo ese (no hay ninguna palanca para
 * «abrirla» aquí; la vía permitida de verdad la recorre TEST8 en una copia del
 * banco con la constante girada).
 *
 * DIFERENCIAS DECLARADAS (no se doblan casos para que den verde):
 *  - consent: E3-4 (posterior al fixture) exige cuatro ojos también en
 *    `written_form`; los casos que daban por bueno un formulario sin segunda
 *    persona se comprueban con la regla de E3-4 y se nombran en
 *    {@see SUPERSEDED_BY_E3_4}.
 */
class TelephonyFixtureParityTest extends TestCase
{
    private const DIR = 'tests/fixtures/voice/';

    private const APP_KEY_RAW_B64 = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** Los casos de consentimiento que E3-4 cambia (written_form sin segunda persona ya no vale). */
    private const SUPERSEDED_BY_E3_4 = [
        'consent · voice · granted por formulario escrito → sí',
        'consent · voice · revoked y después un granted nuevo → sí',
        'consent · voice · un granted pendiente de aprobar no tapa uno anterior válido',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.self::APP_KEY_RAW_B64]);
    }

    /** @return array<string, mixed> */
    private static function fixture(string $nombre): array
    {
        return json_decode((string) file_get_contents(base_path(self::DIR.$nombre)), true, 512, JSON_THROW_ON_ERROR);
    }

    // ── InboundPolicy ───────────────────────────────────────────────────────

    public function test_inbound_policy_cuadra_con_el_fixture_caso_a_caso(): void
    {
        $casos = self::fixture('policy-tables.json')['inboundPolicy'];
        $this->assertCount(15, $casos);
        foreach ($casos as $c) {
            $d = InboundPolicy::decide($c['facts']);
            $this->assertSame($c['expected']['codeClosed']['reasons'], $d['reasons'], $c['name'].' (codeClosed)');
            $this->assertSame($c['expected']['codeClosed']['authorized'], $d['authorized'], $c['name']);
            $this->assertSame($c['expected']['codeClosed']['callerAuthorized'], $d['callerAuthorized'], $c['name']);
            $abierta = array_values(array_diff($d['reasons'], ['calls_disabled_in_code']));
            $this->assertSame($c['expected']['codeOpen']['reasons'], $abierta, $c['name'].' (codeOpen)');
            $this->assertSame($c['expected']['codeOpen']['authorized'], $abierta === [], $c['name'].' (codeOpen)');
        }
    }

    public function test_inbound_policy_rechaza_un_hecho_fuera_de_su_conjunto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InboundPolicy::decide(['inboundEnabled' => true, 'scope' => 'canary', 'braked' => false, 'publication' => 'quizas', 'caller' => 'active_voice',
            'activeOrDialing' => 0, 'maxConcurrent' => 1, 'inboundToday' => 0, 'inboundDailyCap' => 1]);
    }

    // ── OutboundPolicy ──────────────────────────────────────────────────────

    /** Los hechos del fixture → los de la política (los nombres de B8-b). @param  array<string, mixed>  $f */
    private static function outboundFacts(array $f): array
    {
        $t = $f['target'];

        return [
            'outboundEnabled' => $f['flags']['outboundEnabled'],
            'twilioVoiceEnabled' => $f['flags']['twilioVoiceEnabled'],
            'voiceFromConfigured' => $f['number']['voiceFromConfigured'],
            'voiceNumberCollides' => $f['number']['voiceNumberIsWhatsapp'],
            'providerState' => $f['provider']['state'],
            'publication' => $f['publication']['head'],
            'agentDrift' => $f['publication']['agentDrift'],
            'braked' => $f['publication']['braked'],
            'scope' => $t['scope'],
            'source' => $t['source'],
            'canary' => $t['canary'] ?? null,
            'contactAuthorized' => ($t['contactStatus'] ?? null) === 'authorized',
            'voiceConsent' => (bool) ($t['voiceConsent'] ?? false),
            'excluded' => (bool) ($t['excluded'] ?? false),
            'rneCheckMissing' => (bool) ($t['rneCheckMissing'] ?? false) && $t['scope'] === 'contacts',
            'outsideHours' => $f['calendar']['outsideHours'],
            'holiday' => $f['calendar']['holiday'],
            'frequencyExceeded' => $f['quotas']['frequencyExceeded'],
            'activeOrDialing' => $f['quotas']['activeOrDialing'],
            'maxConcurrent' => $f['quotas']['maxConcurrent'],
            'dailyCap' => $f['quotas']['dailyCap'],
            'placedToday' => $f['quotas']['placedToday'],
            'budgetFits' => $f['quotas']['budgetFits'],
            'duplicate' => $f['request']['clientRequestIdSeenForSamePerson'],
        ];
    }

    public function test_outbound_policy_cuadra_con_los_43_casos_del_fixture(): void
    {
        $fx = self::fixture('outbound-gate-cases.json');
        $this->assertSame($fx['closedSets']['reasonsInOrder'], OutboundPolicy::REASONS, 'el conjunto cerrado y su orden (D8-12)');
        $this->assertCount(43, $fx['decide']);
        foreach ($fx['decide'] as $c) {
            $d = OutboundPolicy::decide(self::outboundFacts($c['facts']));
            $this->assertSame($c['expected']['codeClosed']['reasons'], $d['reasons'], $c['name'].' (codeClosed)');
            $this->assertSame($c['expected']['codeClosed']['http'], $d['http'], $c['name'].' (HTTP codeClosed)');
            $abierta = array_values(array_diff($d['reasons'], ['calls_disabled_in_code']));
            $this->assertSame($c['expected']['codeOpen']['reasons'], $abierta, $c['name'].' (codeOpen)');
            $this->assertSame($c['expected']['codeOpen']['http'], OutboundPolicy::httpStatus($abierta), $c['name'].' (HTTP codeOpen)');
            $this->assertSame($c['expected']['codeOpen']['allowed'], $abierta === [], $c['name']);
        }
    }

    public function test_la_guarda_del_numero_de_whatsapp_cuadra_con_el_fixture(): void
    {
        foreach (self::fixture('outbound-gate-cases.json')['whatsappGuard'] as $c) {
            $this->assertSame($c['expected']['voiceNumberIsWhatsapp'], VoiceNumberGuard::collides($c['voiceFrom'], $c['whatsappDisplayPhone'], $c['whatsappProtectedNumbers']), $c['name']);
        }
        // La lista de config/meta.php llega ya en dígitos.
        $this->assertTrue(VoiceNumberGuard::collides('+573000000050', null, ['573000000050']));
        $this->assertFalse(VoiceNumberGuard::collides(null, '+573000000050', []), 'sin número de voz no hay con qué chocar');
    }

    // ── Horario, frecuencia, consentimiento y exclusiones ───────────────────

    public function test_el_horario_legal_y_el_propio_cuadran_con_el_fixture(): void
    {
        $fx = self::fixture('policy-tables.json');
        foreach ($fx['scheduleLegal'] as $c) {
            $r = OutboundSchedule::evaluate(new DateTimeImmutable($c['at']), null);
            $local = OutboundSchedule::local(new DateTimeImmutable($c['at']));
            $this->assertSame($c['expected']['bogotaDate'], $local->format('Y-m-d'), $c['name']);
            $this->assertSame($c['expected']['bogotaTime'], $local->format('H:i:s'), $c['name']);
            $this->assertSame($c['expected']['outsideHours'], $r['outsideHours'], $c['name']);
            $this->assertSame($c['expected']['holiday'], $r['holiday'], $c['name']);
            $this->assertSame($c['expected']['allowed'], ! $r['outsideHours'] && ! $r['holiday'], $c['name']);
        }
        foreach ($fx['scheduleOwn'] as $c) {
            $w = $c['ownWindow'];
            $propio = $w === null ? null : OutboundSchedule::windows($w['weekdays'] === null ? '' : implode('-', $w['weekdays']), $w['saturday'] === null ? '' : implode('-', $w['saturday']));
            $r = OutboundSchedule::evaluate(new DateTimeImmutable($c['at']), $propio);
            $this->assertSame($c['expected']['outsideHours'], $r['outsideHours'], $c['name']);
        }
    }

    public function test_la_frecuencia_cuadra_con_el_fixture_en_las_dos_lecturas_de_la_semana(): void
    {
        foreach (self::fixture('policy-tables.json')['frequency'] as $c) {
            $cuenta = VoiceFrequency::count(new DateTimeImmutable($c['now']), $c['history']);
            $this->assertSame($c['expected']['countToday'], $cuenta['today'], $c['name']);
            $this->assertSame($c['expected']['countWeek'], $cuenta['week'], $c['name']);
            $this->assertSame($c['expected']['countRolling7Days'], $cuenta['rolling7'], $c['name']);
            $this->assertSame($c['expected']['frequencyExceeded'], VoiceFrequency::exceeded($cuenta, $c['limits']['perWeek'], VoiceFrequency::WEEK_CALENDAR), $c['name'].' (calendario)');
            $this->assertSame($c['expected']['frequencyExceededRolling7Days'], VoiceFrequency::exceeded($cuenta, $c['limits']['perWeek'], VoiceFrequency::WEEK_ROLLING), $c['name'].' (7 días)');
        }
    }

    public function test_el_consentimiento_cuadra_con_el_fixture_y_e3_4_supera_tres_casos(): void
    {
        $vistos = [];
        foreach (self::fixture('policy-tables.json')['consent'] as $c) {
            $tiene = VoiceConsentRule::hasConsent($c['voiceConsents'], $c['recordingEnabled']);
            if (in_array($c['name'], self::SUPERSEDED_BY_E3_4, true)) {
                $vistos[] = $c['name'];
                $this->assertTrue($c['expected']['hasVoiceConsent'], 'el fixture lo daba por bueno: '.$c['name']);
                // E3-4: un formulario escrito sin la segunda persona no habilita salientes.
                $this->assertFalse($tiene, $c['name'].' (E3-4)');
                // Y con la confirmación de OTRA persona, como decía el fixture.
                $confirmados = array_map(static function (array $e): array {
                    if ($e['source'] === 'written_form') {
                        $e['approvedBy'] = 9;
                    }

                    return $e;
                }, $c['voiceConsents']);
                $this->assertTrue(VoiceConsentRule::hasConsent($confirmados, $c['recordingEnabled']), $c['name'].' (con la confirmación de otra persona)');

                continue;
            }
            $this->assertSame($c['expected']['hasVoiceConsent'], $tiene, $c['name']);
        }
        $this->assertSame(self::SUPERSEDED_BY_E3_4, $vistos, 'los casos superados existen en el fixture');
    }

    public function test_las_exclusiones_y_el_rne_cuadran_con_el_fixture(): void
    {
        foreach (self::fixture('policy-tables.json')['exclusions'] as $c) {
            $lead = $c['lead'] === null ? null : ['doNotContact' => $c['lead']['doNotContact'], 'consentStatus' => $c['lead']['consentStatus']];
            $r = VoiceExclusionRule::evaluate($c['scope'], $c['exclusions'] !== [], $lead, $c['rneCheck'], new DateTimeImmutable($c['now']), $c['rneValidityDays']);
            $this->assertSame($c['expected'], $r, $c['name']);
        }
        // P-14 sin decidir (vigencia 0): ninguna consulta vale, ni la de hace un segundo.
        $this->assertTrue(VoiceExclusionRule::evaluate('contacts', false, null, ['checkedAt' => '2026-11-11T14:59:59Z', 'result' => 'not_listed'], new DateTimeImmutable('2026-11-11T15:00:00Z'), 0)['rneCheckMissing']);
    }

    // ── Coste y freno ────────────────────────────────────────────────────────

    public function test_la_reserva_y_el_presupuesto_cuadran_con_el_fixture(): void
    {
        $fx = self::fixture('policy-tables.json');
        $precios = [
            'elevenlabs' => $fx['pricesMicroUsdPerMinute']['elevenlabs'],
            'twilio_outbound_mobile_co' => $fx['pricesMicroUsdPerMinute']['twilioOutboundMobileCO'],
            'twilio_outbound_landline_co' => $fx['pricesMicroUsdPerMinute']['twilioOutboundLandlineCO'],
            'twilio_inbound_local_co' => $fx['pricesMicroUsdPerMinute']['twilioInboundLocalCO'],
            'twilio_media_streams' => $fx['pricesMicroUsdPerMinute']['twilioMediaStreams'],
        ];
        $this->assertSame($precios, array_intersect_key(VoiceCostGuard::prices(), $precios), 'los precios de config/voice.php son los del fixture');
        $this->assertSame($fx['pricesMicroUsdPerMinute']['asOf'], VoiceCostGuard::prices()['as_of'], 'con su fecha');
        foreach ($fx['costReservation'] as $c) {
            if (isset($c['maxDurationSeconds'])) {
                $this->assertSame($c['expected']['reserveMicroUsd'], VoiceCostGuard::reserveMicros($c['destinationKind'], $c['maxDurationSeconds'], $c['transferDestinationKind'], $precios), $c['name']);

                continue;
            }
            $presupuesto = VoiceCostGuard::budgetMicros($c['budgetRaw']);
            $this->assertSame($c['expected']['budgetMicroUsd'], $presupuesto, $c['name']);
            $cabe = VoiceCostGuard::fits($presupuesto, $c['spentMicroUsd'] + $c['reservedMicroUsd'], $c['newReserveMicroUsd']);
            $this->assertSame($c['expected']['fits'], $cabe, $c['name']);
        }
    }

    public function test_el_freno_lo_abren_solo_las_causas_del_fixture(): void
    {
        foreach (self::fixture('policy-tables.json')['brake'] as $c) {
            if (isset($c['trigger'])) {
                $this->assertSame($c['expected']['opensBrake'], VoiceBrake::opensFor($c['trigger']), $c['name']);
            }
        }
        // Y cada causa tiene su motivo cerrado de la base (DB8): los seis, y ninguno más.
        $this->assertSame(['guard_violation', 'seal_mismatch', 'agent_drift', 'cost_cap', 'subaccount_alert', 'unknown_provider_call'], array_values(array_unique(VoiceBrake::TRIGGERS)));
        // E4-8: la deriva del número (que el fixture no lista) se guarda como deriva del agente.
        $this->assertSame('agent_drift', VoiceBrake::TRIGGERS['number_drift']);
    }

    // ── La huella del teléfono ──────────────────────────────────────────────

    public function test_la_huella_de_voz_cuadra_con_el_fixture_y_no_normaliza(): void
    {
        $clave = base64_decode(self::APP_KEY_RAW_B64, true);
        foreach (self::fixture('policy-tables.json')['phoneHash'] as $c) {
            if (isset($c['expected']['throws'])) {
                try {
                    VoicePhone::hashWithKey($c['e164'], $clave);
                    $this->fail($c['name'].': tenía que lanzar');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }

                continue;
            }
            $this->assertSame($c['expected']['remoteHash'], VoicePhone::hashWithKey($c['e164'], $clave), $c['name']);
            $this->assertSame($c['expected']['remoteHash'], VoicePhone::hash($c['e164']), $c['name'].' (con la APP_KEY de la aplicación)');
            $this->assertSame($c['expected']['remoteLast4'], VoicePhone::last4($c['e164']), $c['name']);
            $this->assertNotSame($c['expected']['canaryFingerprint'], VoicePhone::hash($c['e164']), 'la huella de voz no es la de los canarios del H6');
        }
    }

    // ── La cadena de voz ────────────────────────────────────────────────────

    public function test_la_cadena_de_voz_cuadra_con_los_vectores(): void
    {
        $fx = self::fixture('voice-chain-vectors.json');
        $this->assertSame($fx['closedSets']['keys'], VoicePublishedConfig::MESSAGE_KEYS);
        $clave = base64_decode(self::APP_KEY_RAW_B64, true);
        $previa = null;
        foreach ($fx['chain'] as $c) {
            $mensaje = VoicePublishedConfig::message($c['entry']);
            $this->assertSame($c['expected']['message'], $mensaje, $c['name']);
            $this->assertSame($c['expected']['entryHash'], hash('sha256', $mensaje), $c['name']);
            $this->assertSame($c['expected']['signature'], VoicePublishedConfig::signatureWithKey($mensaje, $clave), $c['name']);
            $this->assertSame($c['expected']['signature'], VoicePublishedConfig::signatureFor($mensaje), $c['name'].' (con la APP_KEY de la aplicación)');
            $this->assertSame($c['expected']['signature'], (VoicePublishedConfig::signer())($mensaje), $c['name'].' (la Closure del Studio)');
            $this->assertSame('ok', VoicePublishedConfig::verifyEntry($mensaje, $c['expected']['signature'], $previa, $clave), $c['name']);
            $previa = ['seq' => $c['entry']['seq'], 'entryHash' => $c['expected']['entryHash']];
        }
        foreach ($fx['subkey'] as $c) {
            $cruda = str_starts_with($c['appKey'], 'base64:') ? base64_decode(substr($c['appKey'], 7), true) : $c['appKey'];
            $sub = bin2hex(hash_hmac('sha256', $c['label'], $cruda, true));
            $this->assertSame($c['expected']['subkeyHex'], $sub, $c['name']);
            if ($c['label'] === VoicePublishedConfig::SUBKEY_LABEL) {
                $this->assertSame($c['expected']['subkeyHex'], bin2hex(VoicePublishedConfig::subkey($cruda)), $c['name']);
            }
        }
        $this->assertCount(27, $fx['verify']);
        foreach ($fx['verify'] as $c) {
            $this->assertSame($c['expected']['result'], VoicePublishedConfig::verifyEntry($c['message'], $c['signature'], $c['previous'], $clave), $c['name']);
        }
    }

    // ── La guarda posterior ─────────────────────────────────────────────────

    public function test_la_guarda_posterior_cuadra_con_el_fixture_por_el_verificador_real(): void
    {
        $casos = self::fixture('elevenlabs-webhook-bodies.json')['guard'];
        $this->assertCount(9, $casos);
        foreach ($casos as $c) {
            // El sobre firmado lo lee el verificador de B8-a: los turnos del agente, con su índice, salen solo en memoria.
            $veredicto = ElevenLabsEventVerifier::envelope((string) json_encode(['type' => 'post_call_transcription', 'event_timestamp' => 1, 'data' => [
                'agent_id' => 'agent_h8fx_canary_0003', 'conversation_id' => 'conv_h8fx_guard', 'transcript' => $c['transcript'],
            ]], JSON_UNESCAPED_UNICODE));
            $violaciones = VoiceTranscriptGuard::inspect($veredicto->guardTurns(), null);
            $this->assertSame($c['expected']['violations'], $violaciones, $c['name']);
            $this->assertSame($c['expected']['brakeOpens'], $violaciones !== [], $c['name']);
        }
    }

    public function test_la_guarda_tapa_las_cifras_de_la_herramienta_antes_de_inspeccionar(): void
    {
        $turnos = [
            ['index' => 0, 'text' => 'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Si no quieres recibir más llamadas, dímelo.', 'tools' => [], 'transfers' => [], 'planResults' => []],
            ['index' => 2, 'text' => 'Lo consulto.', 'tools' => ['consultar_planes'], 'transfers' => [], 'planResults' => ['{"planes":[{"nombre":"Mensual","precio":"$ 80.000"}]}']],
            // A-9 de SEC8: perdonar el precio sin volver a inspeccionar ensombrecería el descuento y la URL de detrás.
            ['index' => 4, 'text' => 'El plan cuesta $ 80.000, te lo dejo en promoción, entra a x.co', 'tools' => [], 'transfers' => [], 'planResults' => []],
        ];
        $this->assertSame([['turn' => 4, 'code' => 'machine_reply_invented_discount']], VoiceTranscriptGuard::inspect($turnos, null));
        // Sin la llamada a la herramienta, la cifra es la violación.
        $turnos[1]['planResults'] = [];
        $this->assertSame([['turn' => 4, 'code' => 'voice_money_not_from_tool']], VoiceTranscriptGuard::inspect($turnos, null));
        // Con P-3 = nunca, tampoco vale la de la herramienta.
        $turnos[1]['planResults'] = ['{"planes":[{"nombre":"Mensual","precio":"$ 80.000"}]}'];
        $this->assertSame([['turn' => 4, 'code' => 'voice_money_not_from_tool']], VoiceTranscriptGuard::inspect($turnos, null, pricesFromToolAllowed: false));
    }

    public function test_una_transferencia_a_un_destino_aprobado_no_es_violacion(): void
    {
        $aviso = 'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Si no quieres recibir más llamadas, dímelo.';
        $turnos = [
            ['index' => 0, 'text' => $aviso, 'tools' => [], 'transfers' => [], 'planResults' => []],
            ['index' => 1, 'text' => 'Te comunico.', 'tools' => ['transfer_to_number'], 'transfers' => ['+576010000011'], 'planResults' => []],
        ];
        $this->assertSame([], VoiceTranscriptGuard::inspect($turnos, [VoicePhone::hash('+576010000011')]));
        $this->assertSame([['turn' => 1, 'code' => 'voice_transfer_not_approved']], VoiceTranscriptGuard::inspect($turnos, [VoicePhone::hash('+576010000012')]));
        $this->assertSame([['turn' => 1, 'code' => 'voice_transfer_not_approved']], VoiceTranscriptGuard::inspect($turnos, null), 'sin la lista de aprobados, ninguno lo es');
        // Sin la herramienta en ese turno, el traspaso prometido es del motor (no autorizado).
        $turnos[1]['tools'] = [];
        $turnos[1]['transfers'] = [];
        $this->assertSame([['turn' => 1, 'code' => 'machine_reply_unauthorized_handoff']], VoiceTranscriptGuard::inspect($turnos, null));
    }
}
