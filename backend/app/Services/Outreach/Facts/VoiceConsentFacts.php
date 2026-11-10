<?php

namespace App\Services\Outreach\Facts;

use App\Services\Telephony\VoiceConsentRule;
use App\Services\Telephony\VoiceExclusionRule;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de SOLO LECTURA de la autorización y las exclusiones de VOZ
 * (contrato H9, D9-4 `subject.has_voice_contact`, D9-7 N1 `voice_excluded` y
 * `rne_check_missing`, D9-15 «voz»). Tablas: `voice_contacts`,
 * `voice_consents`, `voice_exclusions`, `voice_rne_checks`; de cada una, solo
 * las columnas que deciden (nunca `phone_encrypted`).
 *
 * Es el MISMO patrón que `VoiceLeadSignals` (el único adaptador de la
 * telefonía hacia los leads): sentencias de lectura con las columnas justas, y
 * las reglas PURAS del H8 para no tener dos verdades:
 *  - el consentimiento vigente de un contacto lo decide
 *    `VoiceConsentRule::hasConsent()` (el último evento válido; un `granted`
 *    cargado por una persona solo vale confirmado por OTRA, E3-4; la grabación
 *    apagada, P-7, como `VoiceContacts::hasConsent()`);
 *  - la exclusión y la vigencia de la consulta al RNE, `VoiceExclusionRule`
 *    con el alcance `contacts` y `voice.contacts.rne_validity_days` (0 = ninguna
 *    vigente: falla cerrado).
 *
 * LA PERSONA ↔ CONTACTO: un contacto ACTIVO cuyo origen es una de sus fichas
 * (`source_type` = `member`/`lead` y `source_id`). Las exclusiones (también el
 * «no volver a llamar» dicho en una llamada, `spoken_opt_out`) van por la
 * huella del número y valen para cualquier contacto con ese número.
 *
 *  - `consent.voice_call.commercial` ⇔ algún contacto activo de la persona con
 *    consentimiento vigente. Cobranza y servicio: NUNCA por voz en el H9 (D9-15).
 *  - `voice.excluded` ⇔ alguna exclusión sobre el número de alguno de sus
 *    contactos activos, o una consulta al RNE vigente que dice `listed`.
 *  - `voice.rne_check_missing` ⇔ algún contacto activo sin consulta vigente.
 */
final class VoiceConsentFacts
{
    public const TABLES = ['voice_contacts', 'voice_consents', 'voice_exclusions', 'voice_rne_checks'];

    public const FACTS = ['subject.has_voice_contact'];

    public const POLICY = [
        'consent.voice_call.commercial', 'consent.voice_call.collections', 'consent.voice_call.service',
        'voice.excluded', 'voice.rne_check_missing',
    ];

    /** El alcance de las salientes a contactos (`OutboundPolicy::SCOPE_CONTACTS`). */
    public const SCOPE_CONTACTS = 'contacts';

    /**
     * @return array<string, array{facts: array<string, ?bool>, policy: array<string, bool>}> por clave pedida
     */
    public function facts(FactPass $b): array
    {
        $socios = $b->memberIds();
        $leads = $b->leadIds();

        $contactos = [];
        if ($socios !== [] || $leads !== []) {
            $q = DB::table('voice_contacts')->where('status', 'active')->orderBy('id')->where(function ($w) use ($socios, $leads): void {
                if ($socios !== []) {
                    $w->orWhere(fn ($m) => $m->where('source_type', 'member')->whereIn('source_id', $socios));
                }
                if ($leads !== []) {
                    $w->orWhere(fn ($l) => $l->where('source_type', 'lead')->whereIn('source_id', $leads));
                }
            });
            foreach ($q->get(['id', 'source_type', 'source_id', 'phone_hash']) as $c) {
                $contactos[(int) $c->id] = $c;
            }
        }

        $eventos = [];
        $excluidas = [];
        $rne = [];
        if ($contactos !== []) {
            // Solo la finalidad y el canal que se piden (hoy la base no admite otros: `vcs_purpose_chk`, `vcs_channel_chk`).
            foreach (DB::table('voice_consents')->whereIn('contact_id', array_keys($contactos))
                ->where('purpose', 'commercial')->where('channel', 'voice_call')->orderBy('id')
                ->get(['id', 'contact_id', 'kind', 'source', 'recorded_at', 'recorded_by_admin_id', 'confirmed_by_admin_id']) as $e) {
                $eventos[(int) $e->contact_id][] = [
                    'id' => (int) $e->id, 'kind' => (string) $e->kind, 'source' => (string) $e->source,
                    'at' => FactValues::instant($e->recorded_at) ?? '1970-01-01 00:00:00',
                    'actorId' => $e->recorded_by_admin_id !== null ? (int) $e->recorded_by_admin_id : null,
                    'approvedBy' => $e->confirmed_by_admin_id !== null ? (int) $e->confirmed_by_admin_id : null,
                ];
            }
            $huellas = array_values(array_unique(array_map(fn (object $c): string => (string) $c->phone_hash, $contactos)));
            sort($huellas, SORT_STRING);
            $excluidas = array_fill_keys(DB::table('voice_exclusions')->whereIn('phone_hash', $huellas)
                ->distinct()->pluck('phone_hash')->map(fn ($h): string => (string) $h)->all(), true);
            // La consulta más reciente de cada huella (por `checked_at` y después por id).
            foreach (DB::table('voice_rne_checks')->whereIn('phone_hash', $huellas)
                ->orderByDesc('checked_at')->orderByDesc('id')->get(['phone_hash', 'checked_at', 'result']) as $r) {
                $rne[(string) $r->phone_hash] ??= ['checkedAt' => FactValues::instant($r->checked_at) ?? '1970-01-01 00:00:00', 'result' => (string) $r->result];
            }
        }

        $vigencia = (int) config('voice.contacts.rne_validity_days', 0);
        $salida = [];
        foreach ($b->subjects as $clave => $s) {
            // No admite nulo (catálogo): sin persona resuelta, no tiene contacto de voz.
            $hechos = ['subject.has_voice_contact' => false];
            $politica = array_fill_keys(self::POLICY, false);
            if ($s->resolved()) {
                $mios = [];
                foreach ($contactos as $id => $c) {
                    $suyo = ($c->source_type === 'member' && in_array((int) $c->source_id, $s->memberIds, true))
                        || ($c->source_type === 'lead' && in_array((int) $c->source_id, $s->leadIds, true));
                    if ($suyo) {
                        $mios[$id] = $c;
                    }
                }
                $hechos['subject.has_voice_contact'] = $mios !== [];
                foreach ($mios as $id => $c) {
                    $huella = (string) $c->phone_hash;
                    if (VoiceConsentRule::hasConsent($eventos[$id] ?? [], false)) {
                        $politica['consent.voice_call.commercial'] = true;
                    }
                    $regla = VoiceExclusionRule::evaluate(self::SCOPE_CONTACTS, isset($excluidas[$huella]), null, $rne[$huella] ?? null, $b->now, $vigencia);
                    $politica['voice.excluded'] = $politica['voice.excluded'] || $regla['excluded'];
                    $politica['voice.rne_check_missing'] = $politica['voice.rne_check_missing'] || $regla['rneCheckMissing'];
                }
            }
            $salida[$clave] = ['facts' => $hechos, 'policy' => $politica];
        }

        return $salida;
    }
}
