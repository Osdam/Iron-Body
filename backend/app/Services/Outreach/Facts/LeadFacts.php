<?php

namespace App\Services\Outreach\Facts;

use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador de SOLO LECTURA de los prospectos y su autorización de WhatsApp
 * (contrato H9, D9-4 «Prospecto» y «Conversación», D9-7 N1, D9-15). Tablas:
 * `marketing_leads` (por {@see FactPass::leadRows()} y la regla de hermanos),
 * `marketing_conversations`, `marketing_followups` y `marketing_ai_actions`.
 *
 * QUÉ PROSPECTO: la persona puede tener varios. Los hechos de UNO (temperatura,
 * fase, consentimiento) son del más reciente (`CommercialSubjectResolver`); las
 * señales NEGATIVAS son de CUALQUIERA (D9-15: «toda señal negativa de
 * cualquier fuente gana»).
 *
 * LA AUTORIZACIÓN (hechos de política, para N1):
 *  - `consent.whatsapp.commercial` ⇔ el prospecto de WhatsApp de la persona
 *    (el más reciente con conversación de WhatsApp y número) cumple
 *    `MarketingLead::canContactProactively()`: `granted` y sin *opt-out*.
 *  - `consent.whatsapp.service` ⇔ ese prospecto admite respuesta
 *    (`canReplyReactively()`) y la ventana de 24 h está abierta.
 *  - `consent.whatsapp.collections` = false SIEMPRE (P9-15: la autorización
 *    comercial no vale para cobranza; hoy no hay ninguna fuente).
 *  - `lead.do_not_contact`, `lead.consent_denied` (incluye un valor que no se
 *    sabe leer: falla cerrado), `lead.commercial_opt_out` (el ÚLTIMO gesto de
 *    `marketing_ai_actions`, como `CommercialSubject`) y
 *    `lead.do_not_contact_sibling`: otro prospecto con `do_not_contact` y el
 *    MISMO número normalizado que alguno de la persona (de sus prospectos o de
 *    su ficha de socio), la regla de `MarketingMessageDispatcher::phoneOptedOut()`.
 *    Una familia que comparte número calla entera: es el daño reparable.
 *
 * Tener el teléfono no autoriza nada: ningún hecho de aquí habilita un canal
 * por tener número.
 */
final class LeadFacts
{
    public const TABLES = ['marketing_leads', 'marketing_conversations', 'marketing_followups', 'marketing_ai_actions', 'members'];

    public const FACTS = [
        'subject.is_lead', 'subject.has_whatsapp_identity',
        'lead.temperature', 'lead.commercial_phase', 'lead.days_since_last_inbound', 'lead.last_inbound_at', 'lead.followup_due', 'lead.consent_status',
        'conversation.human_in_control', 'conversation.window_open',
    ];

    public const POLICY = [
        'consent.whatsapp.commercial', 'consent.whatsapp.collections', 'consent.whatsapp.service',
        'lead.do_not_contact', 'lead.consent_denied', 'lead.commercial_opt_out', 'lead.do_not_contact_sibling',
    ];

    public const EVENT_FOLLOWUP = 'lead.followup_due';

    /** Los seguimientos que son un CONTACTO con la persona (una tarea interna no lo es). */
    public const CONTACT_FOLLOWUP_TYPES = ['message', 'call'];

    /** Horas de la ventana de servicio de Meta (`ContactPolicy::requiresTemplate()`). */
    public const WINDOW_HOURS = 24;

    /**
     * @return array<string, array{facts: array<string, bool|int|string|null>, policy: array<string, bool>}> por clave pedida
     */
    public function facts(FactPass $b): array
    {
        $leads = $b->leadRows();
        $ids = array_keys($leads);
        $ahora = FactValues::sql($b->now);

        $conversaciones = [];
        $seguimientos = [];
        $ultimoGesto = [];
        if ($ids !== []) {
            foreach (DB::table('marketing_conversations')->whereIn('lead_id', $ids)->orderBy('id')
                ->get(['id', 'lead_id', 'channel', 'status', 'human_takeover', 'staff_review_pending', 'last_inbound_at', 'commercial_phase']) as $c) {
                $conversaciones[(int) $c->lead_id][] = $c;
            }
            $seguimientos = array_fill_keys(DB::table('marketing_followups')
                ->whereIn('lead_id', $ids)
                ->where('status', MarketingFollowup::STATUS_PENDING)
                ->whereIn('type', self::CONTACT_FOLLOWUP_TYPES)
                ->whereNotNull('due_at')
                ->where('due_at', '<=', $ahora)
                ->distinct()->pluck('lead_id')->map(fn ($id) => (int) $id)->all(), true);
            // Vale el ÚLTIMO gesto de cada prospecto (`CommercialSubject::conversationFacts()`).
            foreach (DB::table('marketing_ai_actions')->whereIn('lead_id', $ids)
                ->whereIn('action_type', ['commercial_opt_out', 'commercial_opt_in'])
                ->orderBy('id')->get(['lead_id', 'action_type']) as $g) {
                $ultimoGesto[(int) $g->lead_id] = (string) $g->action_type;
            }
        }

        $hermanos = $this->optedOutPhones($b);

        $salida = [];
        foreach ($b->subjects as $clave => $s) {
            $salida[$clave] = $this->forSubject($b, $s, $leads, $conversaciones, $seguimientos, $ultimoGesto, $hermanos);
        }

        return $salida;
    }

    /**
     * Seguimientos AUTORIZADOS vencidos de `marketing_followups` (D9-3): un
     * contacto pendiente (mensaje o llamada) cuya hora ya llegó. El
     * consentimiento NO se mira aquí: lo niega N1 (prueba 6). Clave de ciclo:
     * el seguimiento (`fu:<id>`).
     *
     * @return array{candidates: list<Candidate>, next: ?int}
     */
    public function followupsDue(DateTimeInterface $now, int $limit = 500, int $afterId = 0): array
    {
        $limite = max(1, min(500, $limit));
        $filas = DB::table('marketing_followups')
            ->where('id', '>', $afterId)
            ->where('status', MarketingFollowup::STATUS_PENDING)
            ->whereIn('type', self::CONTACT_FOLLOWUP_TYPES)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', FactValues::sql(RelativeTime::utc($now)))
            ->orderBy('id')
            ->limit($limite)
            ->get(['id', 'lead_id']);

        $sujetos = (new SubjectResolver)->forLeadIds($filas->pluck('lead_id')->map(fn ($id) => (int) $id)->all());
        $candidatos = [];
        foreach ($filas as $f) {
            $s = $sujetos[(int) $f->lead_id] ?? null;
            if ($s !== null) {
                $candidatos[] = new Candidate((string) $s->key, self::EVENT_FOLLOWUP, ['marketing_followup_id' => (int) $f->id], (int) $f->id);
            }
        }

        return ['candidates' => $candidatos, 'next' => $filas->count() === $limite ? (int) $filas->last()->id : null];
    }

    /**
     * @param  array<int, object>  $leads
     * @param  array<int, list<object>>  $conversaciones
     * @param  array<int, true>  $seguimientos
     * @param  array<int, string>  $ultimoGesto
     * @param  array<string, list<int>>  $hermanos  número normalizado → prospectos con do_not_contact
     * @return array{facts: array<string, bool|int|string|null>, policy: array<string, bool>}
     */
    private function forSubject(FactPass $b, Subject $s, array $leads, array $conversaciones, array $seguimientos, array $ultimoGesto, array $hermanos): array
    {
        $hechos = array_fill_keys(self::FACTS, null);
        $politica = array_fill_keys(self::POLICY, false);
        if (! $s->resolved()) {
            // Los `subject.*` no admiten nulo (catálogo): sin ficha encontrada, ni prospecto ni identidad de WhatsApp.
            $hechos['subject.is_lead'] = false;
            $hechos['subject.has_whatsapp_identity'] = false;

            return ['facts' => $hechos, 'policy' => $politica];
        }

        $propios = array_values(array_filter(array_map(fn (int $id) => $leads[$id] ?? null, $s->leadIds)));
        $hechos['subject.is_lead'] = $propios !== [];

        // Señales negativas: de cualquiera de sus prospectos.
        foreach ($propios as $l) {
            if (FactValues::bool($l->do_not_contact)) {
                $politica['lead.do_not_contact'] = true;
            }
            if (self::consentDenied($l->consent_status)) {
                $politica['lead.consent_denied'] = true;
            }
            if (($ultimoGesto[(int) $l->id] ?? null) === 'commercial_opt_out') {
                $politica['lead.commercial_opt_out'] = true;
            }
        }
        $mios = array_fill_keys(array_map(fn (object $l): int => (int) $l->id, $propios), true);
        foreach ($b->phonesOf($s) as $numero) {
            foreach ($hermanos[$numero] ?? [] as $otro) {
                if (! isset($mios[$otro])) {
                    $politica['lead.do_not_contact_sibling'] = true;
                }
            }
        }

        if ($propios === []) {
            // Sin prospecto no hay conversación: se SABE que nadie la lleva, que la
            // ventana está cerrada y que no hay seguimientos (N2 `human_in_control`
            // niega con un desconocido, y aquí no lo es). Lo que es del prospecto
            // (temperatura, fase, consentimiento, última entrada) no existe: desconocido.
            $hechos['subject.has_whatsapp_identity'] = false;
            $hechos['conversation.human_in_control'] = false;
            $hechos['conversation.window_open'] = false;
            $hechos['lead.followup_due'] = false;

            return ['facts' => $hechos, 'policy' => $politica];
        }

        $principal = $propios[count($propios) - 1];
        $hechos['lead.temperature'] = in_array($principal->temperature, OutreachCatalog::LEAD_TEMPERATURES, true) ? $principal->temperature : null;
        $hechos['lead.consent_status'] = self::consentValue($principal->consent_status);
        $hechos['lead.followup_due'] = false;
        foreach ($propios as $l) {
            if (isset($seguimientos[(int) $l->id])) {
                $hechos['lead.followup_due'] = true;
            }
        }

        // Conversaciones de todos sus prospectos.
        $ultimaEntrada = null;
        $humano = false;
        $ultimaDelPrincipal = null;
        foreach ($propios as $l) {
            foreach ($conversaciones[(int) $l->id] ?? [] as $c) {
                $entrada = FactValues::instant($c->last_inbound_at);
                if ($entrada !== null && $entrada <= $b->now && ($ultimaEntrada === null || $entrada > $ultimaEntrada)) {
                    $ultimaEntrada = $entrada;
                }
                if ($c->status !== 'closed' && (FactValues::bool($c->human_takeover) || FactValues::bool($c->staff_review_pending))) {
                    $humano = true;
                }
                if ((int) $l->id === (int) $principal->id) {
                    $ultimaDelPrincipal = $c;
                }
            }
        }
        $fase = $ultimaDelPrincipal?->commercial_phase;
        $hechos['lead.commercial_phase'] = is_string($fase) && in_array($fase, OutreachCatalog::LEAD_PHASES, true) ? $fase : null;
        $hechos['lead.days_since_last_inbound'] = $ultimaEntrada !== null ? RelativeTime::daysAgo($ultimaEntrada, $b->now) : null;
        // `date_relative` en horas: el desplazamiento con signo hasta el último entrante (≤ 0).
        $hechos['lead.last_inbound_at'] = $ultimaEntrada !== null ? -RelativeTime::hoursSince($ultimaEntrada, $b->now) : null;
        $hechos['conversation.human_in_control'] = $humano;
        $ventanaAbierta = $ultimaEntrada !== null && RelativeTime::hoursSince($ultimaEntrada, $b->now) < self::WINDOW_HOURS;
        $hechos['conversation.window_open'] = $ventanaAbierta;

        // El prospecto por el que se escribiría: el más reciente con conversación de WhatsApp y número.
        $deWhatsapp = null;
        foreach ($propios as $l) {
            $tieneConversacion = false;
            foreach ($conversaciones[(int) $l->id] ?? [] as $c) {
                if ($c->channel === 'whatsapp') {
                    $tieneConversacion = true;
                }
            }
            if ($tieneConversacion && PhoneKey::normalize($l->phone) !== null) {
                $deWhatsapp = $l;
            }
        }
        $hechos['subject.has_whatsapp_identity'] = $deWhatsapp !== null;
        if ($deWhatsapp !== null) {
            $reactivo = self::canReplyReactively($deWhatsapp);
            $politica['consent.whatsapp.commercial'] = $reactivo && $deWhatsapp->consent_status === MarketingLead::CONSENT_GRANTED;
            $politica['consent.whatsapp.service'] = $reactivo && $ventanaAbierta;
        }
        $politica['consent.whatsapp.collections'] = false;

        return ['facts' => $hechos, 'policy' => $politica];
    }

    /**
     * Los números normalizados que algún prospecto con `do_not_contact` tiene,
     * con sus ids (la regla de los hermanos). Una sentencia por lote: es la
     * misma lectura que hace la puerta por cada mensaje.
     *
     * @return array<string, list<int>>
     */
    private function optedOutPhones(FactPass $b): array
    {
        $hay = false;
        foreach ($b->subjects as $s) {
            if ($s->resolved()) {
                $hay = true;

                break;
            }
        }
        if (! $hay) {
            return [];
        }
        $porNumero = [];
        foreach (DB::table('marketing_leads')->where('do_not_contact', true)->whereNotNull('phone')->orderBy('id')->get(['id', 'phone']) as $l) {
            $n = PhoneKey::normalize($l->phone);
            if ($n !== null) {
                $porNumero[$n][] = (int) $l->id;
            }
        }

        return $porNumero;
    }

    /** `MarketingLead::canReplyReactively()` sobre la fila leída. */
    private static function canReplyReactively(object $l): bool
    {
        if (FactValues::bool($l->do_not_contact)) {
            return false;
        }
        $c = $l->consent_status;
        if ($c === null || $c === '') {
            return true;
        }

        return in_array($c, MarketingLead::CONSENT_STATUSES, true) && $c !== MarketingLead::CONSENT_DENIED;
    }

    /** `denied`, o un valor que no se sabe leer (falla cerrado, como el modelo). */
    private static function consentDenied(mixed $c): bool
    {
        if ($c === null || $c === '') {
            return false;
        }

        return ! in_array($c, MarketingLead::CONSENT_STATUSES, true) || $c === MarketingLead::CONSENT_DENIED;
    }

    /** El enumerado del catálogo: sin registrar es `unknown`; un valor ilegible, desconocido (null). */
    private static function consentValue(mixed $c): ?string
    {
        if ($c === null || $c === '') {
            return MarketingLead::CONSENT_UNKNOWN;
        }

        return in_array($c, OutreachCatalog::LEAD_CONSENT_STATUSES, true) ? $c : null;
    }
}
