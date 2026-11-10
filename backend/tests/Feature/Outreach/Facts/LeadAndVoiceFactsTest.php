<?php

namespace Tests\Feature\Outreach\Facts;

use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Outreach\Facts\CanaryFacts;
use App\Services\Outreach\Facts\LeadFacts;
use App\Services\Outreach\Facts\PhoneKey;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Facts\VoiceConsentFacts;
use App\Services\Telephony\OutboundPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\TestCase;

/**
 * B9-facts · PROSPECTOS, AUTORIZACIÓN DE WHATSAPP, VOZ y CANARIOS (contrato H9,
 * D9-4, D9-7 N1, D9-11.6, D9-15; prueba 6 en su parte de hechos). Toda señal
 * negativa gana; tener el teléfono no autoriza nada; el consentimiento de voz
 * es a cuatro ojos; las exclusiones van por huella.
 */
class LeadAndVoiceFactsTest extends TestCase
{
    use FactsWorld;
    use RefreshDatabase;

    private const NOW = '2026-11-09 15:00:00';

    public function test_autorizacion_de_whatsapp_por_finalidad_y_senales_negativas(): void
    {
        $granted = $this->lead(['phone' => '3001000001']);
        $this->conversation($granted, ['last_inbound_at' => self::utc('2026-11-09 14:00:00')]);
        $pendiente = $this->lead(['phone' => '3001000002', 'consent_status' => 'pending']);
        $this->conversation($pendiente);
        $denegado = $this->lead(['phone' => '3001000003', 'consent_status' => 'denied']);
        $this->conversation($denegado);
        $raro = $this->lead(['phone' => '3001000004', 'consent_status' => 'quizas']);
        $this->conversation($raro);
        $sinRegistro = $this->lead(['phone' => '3001000005', 'consent_status' => null]);
        $this->conversation($sinRegistro, ['last_inbound_at' => self::utc('2026-11-08 15:00:00')]);
        $sinConversacion = $this->lead(['phone' => '3001000006']);

        $claves = array_map(fn (int $id) => SubjectKey::lead($id), [$granted, $pendiente, $denegado, $raro, $sinRegistro, $sinConversacion]);
        $foto = $this->snap($claves, self::NOW, self::only(LeadFacts::FACTS, false, true));

        $g = $foto->subject($claves[0]);
        $this->assertTrue($g->policy('consent.whatsapp.commercial'));
        $this->assertTrue($g->policy('consent.whatsapp.service'));    // ventana abierta (1 h)
        $this->assertFalse($g->policy('consent.whatsapp.collections')); // P9-15: cobranza, ninguna
        $this->assertSame('granted', $g->fact('lead.consent_status'));
        $this->assertTrue($g->fact('conversation.window_open'));
        $this->assertSame(-1, $g->fact('lead.last_inbound_at'));
        $this->assertSame(0, $g->fact('lead.days_since_last_inbound'));

        $this->assertFalse($foto->subject($claves[1])->policy('consent.whatsapp.commercial'));
        $this->assertFalse($foto->subject($claves[1])->policy('lead.consent_denied'));
        $this->assertTrue($foto->subject($claves[2])->policy('lead.consent_denied'));
        $this->assertSame('denied', $foto->subject($claves[2])->fact('lead.consent_status'));
        // Un valor que no se sabe leer: niega (falla cerrado) y el hecho es desconocido.
        $this->assertTrue($foto->subject($claves[3])->policy('lead.consent_denied'));
        $this->assertNull($foto->subject($claves[3])->fact('lead.consent_status'));
        // Sin registro: «unknown» del dominio; responder sí, escribir primero no.
        $sr = $foto->subject($claves[4]);
        $this->assertSame('unknown', $sr->fact('lead.consent_status'));
        $this->assertFalse($sr->policy('consent.whatsapp.commercial'));
        $this->assertFalse($sr->fact('conversation.window_open'));     // 24 h justas: cerrada
        $this->assertFalse($sr->policy('consent.whatsapp.service'));
        // Tener teléfono sin conversación de WhatsApp no da identidad ni autorización.
        $sc = $foto->subject($claves[5]);
        $this->assertFalse($sc->fact('subject.has_whatsapp_identity'));
        $this->assertFalse($sc->policy('consent.whatsapp.commercial'));
        $this->assertNull($sc->fact('lead.days_since_last_inbound'));
    }

    public function test_el_opt_out_de_un_hermano_por_numero_normalizado_calla_a_la_familia(): void
    {
        // El mismo número con y sin el 57: un hermano con do_not_contact calla a los demás.
        $hermano = $this->lead(['phone' => '+57 300 222 3344', 'do_not_contact' => true]);
        $yo = $this->lead(['phone' => '3002223344']);
        $socio = $this->socio([], ['phone' => '3002223344']);
        $otro = $this->lead(['phone' => '3009998877']);
        $foto = $this->snap([SubjectKey::lead($yo), SubjectKey::member($socio['member']), SubjectKey::lead($otro), SubjectKey::lead($hermano)], self::NOW, self::only([], false, true));

        $this->assertTrue($foto->subject(SubjectKey::lead($yo))->policy('lead.do_not_contact_sibling'));
        $this->assertFalse($foto->subject(SubjectKey::lead($yo))->policy('lead.do_not_contact'));
        $this->assertTrue($foto->subject(SubjectKey::member($socio['member']))->policy('lead.do_not_contact_sibling'));
        $this->assertFalse($foto->subject(SubjectKey::lead($otro))->policy('lead.do_not_contact_sibling'));
        // El propio: es `do_not_contact`, no hermano.
        $this->assertTrue($foto->subject(SubjectKey::lead($hermano))->policy('lead.do_not_contact'));
        $this->assertFalse($foto->subject(SubjectKey::lead($hermano))->policy('lead.do_not_contact_sibling'));
        // Ningún teléfono en la foto.
        $this->assertStringNotContainsString('2223344', $foto->canonicalJson());
    }

    public function test_paridad_de_la_normalizacion_con_la_puerta_de_whatsapp(): void
    {
        $puerta = app(MarketingMessageDispatcher::class);
        foreach (['3001234567', '+57 300 123 4567', '573001234567', '(300) 123-4567', '6012345678', '12345', '', null, '0057 3001234567', '+1 415 555 0100'] as $n) {
            $this->assertSame($puerta->normalizePhone($n), PhoneKey::normalize($n), 'Normalización distinta para un caso de paridad.');
        }
    }

    public function test_opt_out_comercial_vale_el_ultimo_gesto_y_seguimientos_vencidos(): void
    {
        $vuelve = $this->lead();
        $sale = $this->lead();
        $conTarea = $this->lead();
        $futuro = $this->lead();
        $hecho = $this->lead();
        $vence = $this->lead();
        foreach ([[$vuelve, 'commercial_opt_out'], [$vuelve, 'commercial_opt_in'], [$sale, 'commercial_opt_in'], [$sale, 'commercial_opt_out']] as [$l, $tipo]) {
            DB::table('marketing_ai_actions')->insert(['lead_id' => $l, 'action_type' => $tipo, 'status' => 'executed', 'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00')]);
        }
        $seguimiento = static fn (int $lead, string $tipo, string $estado, string $vence): array => ['lead_id' => $lead, 'type' => $tipo, 'status' => $estado,
            'due_at' => self::utc($vence), 'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00')];
        DB::table('marketing_followups')->insert([
            $seguimiento($conTarea, 'task', 'pending', '2026-11-09 10:00:00'),   // una tarea interna no es un contacto
            $seguimiento($futuro, 'message', 'pending', '2026-11-09 15:00:01'),  // aún no vence
            $seguimiento($hecho, 'message', 'done', '2026-11-09 10:00:00'),      // ya se hizo
            $seguimiento($vence, 'call', 'pending', '2026-11-09 15:00:00'),      // vence justo ahora
        ]);
        $claves = array_map(fn (int $id) => SubjectKey::lead($id), [$vuelve, $sale, $conTarea, $futuro, $hecho, $vence]);
        $foto = $this->snap($claves, self::NOW, self::only(['lead.followup_due'], false, true));

        $this->assertFalse($foto->subject($claves[0])->policy('lead.commercial_opt_out'));
        $this->assertTrue($foto->subject($claves[1])->policy('lead.commercial_opt_out'));
        $this->assertSame([false, false, false, true], array_map(fn (string $k) => $foto->subject($k)->fact('lead.followup_due'), array_slice($claves, 2)));

        $r = (new LeadFacts)->followupsDue(self::at(self::NOW));
        $this->assertSame([SubjectKey::lead($vence)], array_map(fn ($c) => $c->subjectKey, $r['candidates']));
    }

    public function test_una_persona_al_mando_y_quien_no_tiene_conversacion(): void
    {
        $tomada = $this->lead();
        $this->conversation($tomada, ['human_takeover' => true]);
        $cerrada = $this->lead();
        $this->conversation($cerrada, ['human_takeover' => true, 'status' => 'closed']);
        $revision = $this->lead();
        $this->conversation($revision, ['staff_review_pending' => true]);
        $socioSinLead = $this->socio();

        $claves = [SubjectKey::lead($tomada), SubjectKey::lead($cerrada), SubjectKey::lead($revision), SubjectKey::member($socioSinLead['member'])];
        $foto = $this->snap($claves, self::NOW, self::only(LeadFacts::FACTS));
        $this->assertSame([true, false, true, false], array_map(fn (string $k) => $foto->subject($k)->fact('conversation.human_in_control'), $claves));
        // Sin prospecto se SABE que no hay conversación ni seguimiento; lo del prospecto es desconocido.
        $s = $foto->subject($claves[3]);
        $this->assertFalse($s->fact('subject.is_lead'));
        $this->assertFalse($s->fact('conversation.window_open'));
        $this->assertFalse($s->fact('lead.followup_due'));
        $this->assertNull($s->fact('lead.consent_status'));
        $this->assertNull($s->fact('lead.temperature'));
    }

    public function test_temperatura_y_fase_solo_del_dominio_del_catalogo(): void
    {
        $caliente = $this->lead(['temperature' => 'very_hot']);
        $this->conversation($caliente, ['commercial_phase' => 'CLOSING']);
        $rara = $this->lead(['temperature' => 'tibia']);
        $this->conversation($rara, ['commercial_phase' => 'INVENTADA']);
        $foto = $this->snap([SubjectKey::lead($caliente), SubjectKey::lead($rara)], self::NOW, self::only(['lead.temperature', 'lead.commercial_phase']));
        $this->assertSame('very_hot', $foto->subject(SubjectKey::lead($caliente))->fact('lead.temperature'));
        $this->assertSame('CLOSING', $foto->subject(SubjectKey::lead($caliente))->fact('lead.commercial_phase'));
        $this->assertNull($foto->subject(SubjectKey::lead($rara))->fact('lead.temperature'));
        $this->assertNull($foto->subject(SubjectKey::lead($rara))->fact('lead.commercial_phase'));
    }

    public function test_voz_consentimiento_a_cuatro_ojos_exclusion_por_huella_y_rne(): void
    {
        config(['voice.contacts.rne_validity_days' => 90]);
        $this->assertSame(OutboundPolicy::SCOPE_CONTACTS, VoiceConsentFacts::SCOPE_CONTACTS);

        $confirmado = $this->socio();
        $c1 = $this->voiceContact('member', $confirmado['member'], '+573001110001');
        $this->voiceConsent($c1['id'], 'granted', 2, '2026-11-01 12:00:00');
        $this->rneCheck($c1['hash'], '2026-11-01 12:00:00');

        // La misma cuenta no puede confirmar (la base lo rechaza: `vcs_four_eyes`); lo que sí existe y NO
        // vale es la petición grabada en una entrante con la grabación apagada (P-7, `VoiceConsentRule`).
        $mismaPersona = $this->socio();
        $c2 = $this->voiceContact('member', $mismaPersona['member'], '+573001110002');
        $this->voiceConsent($c2['id'], 'granted', null, '2026-11-01 12:00:00', null, 'recorded_inbound_request');

        $revocado = $this->socio();
        $c3 = $this->voiceContact('member', $revocado['member'], '+573001110003');
        $this->voiceConsent($c3['id'], 'granted', 2, '2026-11-01 12:00:00');
        $this->voiceConsent($c3['id'], 'revoked', null, '2026-11-02 12:00:00', 3);

        $excluido = $this->lead();
        $c4 = $this->voiceContact('lead', $excluido, '+573001110004');
        $this->voiceConsent($c4['id'], 'granted', 2, '2026-11-01 12:00:00');
        // «No volver a llamar» dicho en una llamada a ese número: la exclusión es del número, no del contacto.
        $this->voiceExclusion($c4['hash'], 'spoken_opt_out', $this->outboundCall('+573001110004', 'completed', '2026-10-30 15:00:00'));
        $this->rneCheck($c4['hash'], '2026-08-11 15:00:00');                     // 90 días justos: vale

        $listado = $this->socio();
        $c5 = $this->voiceContact('member', $listado['member'], '+573001110005');
        $this->rneCheck($c5['hash'], '2026-11-01 12:00:00', 'listed');

        $sinVoz = $this->socio();
        $claves = [SubjectKey::member($confirmado['member']), SubjectKey::member($mismaPersona['member']), SubjectKey::member($revocado['member']),
            SubjectKey::lead($excluido), SubjectKey::member($listado['member']), SubjectKey::member($sinVoz['member'])];
        $foto = $this->snap($claves, self::NOW, self::only(VoiceConsentFacts::FACTS, false, true));
        $p = fn (int $i, string $k) => $foto->subject($claves[$i])->policy($k);

        $this->assertTrue($foto->subject($claves[0])->fact('subject.has_voice_contact'));
        $this->assertTrue($p(0, 'consent.voice_call.commercial'));
        $this->assertFalse($p(0, 'voice.excluded'));
        $this->assertFalse($p(0, 'voice.rne_check_missing'));
        $this->assertFalse($p(0, 'consent.voice_call.collections'));
        $this->assertFalse($p(1, 'consent.voice_call.commercial'));
        $this->assertTrue($p(1, 'voice.rne_check_missing'));
        $this->assertFalse($p(2, 'consent.voice_call.commercial'));
        $this->assertTrue($p(3, 'voice.excluded'));
        $this->assertFalse($p(3, 'voice.rne_check_missing'));
        $this->assertTrue($p(4, 'voice.excluded'));
        $this->assertFalse($foto->subject($claves[5])->fact('subject.has_voice_contact'));
        $this->assertFalse($p(5, 'consent.voice_call.commercial'));

        // Con la vigencia en 0 (el defecto mientras P-14 no se decida) ninguna consulta vale.
        config(['voice.contacts.rne_validity_days' => 0]);
        $cero = $this->snap([$claves[0]], self::NOW, self::only([], false, true));
        $this->assertTrue($cero->subject($claves[0])->policy('voice.rne_check_missing'));
    }

    public function test_canario_activo_por_huella_del_numero_de_la_persona(): void
    {
        $this->canary('+573004440001', 'whatsapp');
        $conLead = $this->lead(['phone' => '300 444 0001']);
        $socio = $this->socio([], ['phone' => '3004440001']);
        $otro = $this->lead(['phone' => '3004440002']);
        $foto = $this->snap([SubjectKey::lead($conLead), SubjectKey::member($socio['member']), SubjectKey::lead($otro)], self::NOW, self::only([], false, true));
        $this->assertTrue($foto->subject(SubjectKey::lead($conLead))->policy('canary.whatsapp'));
        $this->assertFalse($foto->subject(SubjectKey::lead($conLead))->policy('canary.voice'));
        $this->assertTrue($foto->subject(SubjectKey::member($socio['member']))->policy('canary.whatsapp'));
        $this->assertFalse($foto->subject(SubjectKey::lead($otro))->policy('canary.whatsapp'));
        $this->assertSame(['ultron_studio_canary_numbers'], CanaryFacts::TABLES);
    }
}
