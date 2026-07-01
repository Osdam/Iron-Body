<?php

namespace App\Services\Marketing;

use App\Models\MarketingAgentAction;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;

/**
 * Motor de recomendaciones del agente (Fase 4C). Determinista (reglas), analiza
 * la conversación y PROPONE acciones CRM razonables. No ejecuta nada: solo crea
 * sugerencias (status=suggested). Evita spam con deduplicación y DEVUELVE detalle
 * de lo creado y lo saltado (para que el CRM muestre un mensaje claro).
 */
class MarketingAgentRecommendationService
{
    public function __construct(private readonly MarketingAgentActionService $actions)
    {
    }

    /**
     * Analiza la conversación y crea sugerencias nuevas (no duplicadas).
     *
     * @return array{created: MarketingAgentAction[], skipped: array<int,array<string,mixed>>, reason: ?string}
     */
    public function recommend(MarketingConversation $conversation): array
    {
        $conversation->loadMissing(['lead', 'tags']);
        $text = $this->recentInboundText($conversation);

        // Sin mensajes del lead: no hay nada que analizar (razón visible en UI).
        if ($text === '') {
            return ['created' => [], 'skipped' => [], 'reason' => 'conversation_has_no_messages'];
        }

        $created = [];
        $skipped = [];

        $existingTags = $conversation->tags->pluck('tag')->all();
        $hasFutureAppointment = $this->hasFutureAppointment($conversation);
        $staffReviewPending = (bool) $conversation->staff_review_pending;

        $base = [
            'marketing_lead_id'         => $conversation->lead_id,
            'marketing_conversation_id' => $conversation->id,
            'suggested_by'              => 'ai',
        ];

        // ── Interés / información / planes ───────────────────────────────────
        if ($this->matches($text, ['informacion', 'info', 'planes', 'plan mensual', 'membresia', 'quiero saber', 'como funciona', 'me interesa', 'horarios', 'inscrib'])) {
            $this->suggestTag($created, $skipped, $base, $existingTags, 'interesado', 'Etiquetar lead interesado', 0.7);
            $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_DRAFT_REPLY, array_merge($base, [
                'title'      => 'Respuesta sugerida (información)',
                'reason'     => 'El lead pide información de planes; respuesta con CTA suave (no se envía).',
                'priority'   => 'normal',
                'confidence' => 0.65,
                'payload'    => ['draft' => '¡Con gusto! Tenemos plan mensual con acceso completo y acompañamiento. ¿Quieres que te cuente cómo arrancar o prefieres pasar a conocernos primero?'],
            ]));
            $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_CREATE_FOLLOW_UP, array_merge($base, [
                'title'      => 'Seguimiento por interés',
                'reason'     => 'Programar seguimiento por interés en planes.',
                'priority'   => 'normal',
                'confidence' => 0.5,
                'payload'    => ['due_at' => now()->addDay()->toIso8601String(), 'type' => 'task', 'reason' => 'Seguir interés en planes'],
            ]));
        }

        // ── Precio ───────────────────────────────────────────────────────────
        if ($this->matches($text, ['precio', 'cuanto', 'cuesta', 'costo', 'vale', 'mensualidad', 'tarifa', 'cuanto sale'])) {
            $this->suggestTag($created, $skipped, $base, $existingTags, 'precio', 'Etiquetar interés de precio', 0.7);
            $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_DRAFT_REPLY, array_merge($base, [
                'title'      => 'Respuesta sugerida de precio',
                'reason'     => 'El lead preguntó por el precio; respuesta con plan + CTA suave (no se envía).',
                'priority'   => 'normal',
                'confidence' => 0.65,
                'payload'    => ['draft' => 'Con gusto. El plan mensual incluye acceso completo y acompañamiento. ¿Te gustaría que te cuente cómo arrancar o prefieres visitarnos primero?'],
            ]));
        }

        // ── Quiere visitar ───────────────────────────────────────────────────
        if ($this->matches($text, ['visitar', 'conocer', 'visita', 'ir al gym', 'ir al gimnasio', 'pasar por', 'quiero ir', 'puedo ir'])) {
            $this->suggestTag($created, $skipped, $base, $existingTags, 'quiere-visita', 'Etiquetar intención de visita', 0.7);

            $when = $this->parseDateTime($text);
            if ($when !== null && ! $hasFutureAppointment) {
                $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_CREATE_APPOINTMENT, array_merge($base, [
                    'title'      => 'Crear cita de visita',
                    'reason'     => 'El lead propuso un horario concreto para visitar.',
                    'priority'   => 'high',
                    'confidence' => 0.6,
                    'payload'    => ['type' => 'visit', 'title' => 'Visita al gimnasio', 'scheduled_at' => $when, 'duration_minutes' => 45],
                ]));
            } elseif (! $hasFutureAppointment) {
                $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_SUGGEST_APPOINTMENT, array_merge($base, [
                    'title'      => 'Sugerir visita (sin fecha clara)',
                    'reason'     => 'El lead quiere visitar pero no dio fecha/hora; confirmar con el lead.',
                    'priority'   => 'normal',
                    'confidence' => 0.55,
                    'payload'    => ['hint' => 'Proponer 2-3 horarios de visita.'],
                ]));
            }
        }

        // ── Pide humano ──────────────────────────────────────────────────────
        if ($this->matches($text, ['hablar con', 'con una persona', 'con alguien', 'un asesor', 'humano', 'me atienda'])) {
            if (! $staffReviewPending) {
                $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_REQUEST_STAFF_REVIEW, array_merge($base, [
                    'title'      => 'Marcar revisión humana',
                    'reason'     => 'El lead pidió hablar con una persona.',
                    'priority'   => 'high',
                    'confidence' => 0.8,
                    'payload'    => ['reason' => 'human_requested'],
                ]));
            } else {
                $skipped[] = ['action_type' => MarketingAgentAction::TYPE_REQUEST_STAFF_REVIEW, 'reason' => 'staff_review_already_pending'];
            }
            $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_DRAFT_REPLY, array_merge($base, [
                'title'      => 'Respuesta sugerida (derivar a humano)',
                'reason'     => 'Acusar recibo y avisar que un asesor continúa (no se envía).',
                'priority'   => 'high',
                'confidence' => 0.6,
                'payload'    => ['draft' => 'Claro, con gusto te ayudamos. Dejo tu solicitud marcada para que un asesor del equipo continúe contigo. ¿Hay algo puntual que quieras adelantar?'],
            ]));
            $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_PAUSE_AI, array_merge($base, [
                'title'      => 'Pausar IA (sugerido)',
                'reason'     => 'Intención clara de atención humana; requiere confirmación.',
                'priority'   => 'high',
                'confidence' => 0.6,
                'payload'    => ['reason' => 'human_requested'],
            ]));
        }

        // ── Lesión / salud ───────────────────────────────────────────────────
        if ($this->matches($text, ['lesion', 'duele', 'dolor', 'rodilla', 'espalda', 'medico', 'fractura', 'molestia', 'operaron'])) {
            if (! $staffReviewPending) {
                $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_REQUEST_STAFF_REVIEW, array_merge($base, [
                    'title'      => 'Marcar revisión (salud)',
                    'reason'     => 'El lead mencionó una posible lesión/condición de salud.',
                    'priority'   => 'urgent',
                    'confidence' => 0.8,
                    'payload'    => ['reason' => 'medical'],
                ]));
            }
            $this->suggestTag($created, $skipped, $base, $existingTags, 'requiere-cuidado', 'Etiquetar caso de salud', 0.7);
            $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_DRAFT_REPLY, array_merge($base, [
                'title'      => 'Respuesta responsable (salud)',
                'reason'     => 'Responder con cuidado y derivar a valoración profesional.',
                'priority'   => 'high',
                'confidence' => 0.6,
                'payload'    => ['draft' => 'Gracias por contarme. Para cuidar tu salud, lo ideal es una valoración con nuestro equipo antes de entrenar esa zona. ¿Quieres que te agende una valoración inicial?'],
            ]));
        }

        // ── Caliente / quiere empezar ────────────────────────────────────────
        if ($this->matches($text, ['quiero empezar', 'empezar hoy', 'inscribir', 'quiero entrar', 'cuando puedo empezar', 'listo para', 'quiero comenzar'])) {
            $this->suggestTag($created, $skipped, $base, $existingTags, 'lead-caliente', 'Etiquetar lead caliente', 0.75);
            if (! $hasFutureAppointment) {
                $this->suggestOnce($created, $skipped, $conversation, MarketingAgentAction::TYPE_CREATE_FOLLOW_UP, array_merge($base, [
                    'title'      => 'Seguimiento de cierre',
                    'reason'     => 'Lead con alta intención; agendar seguimiento de cierre.',
                    'priority'   => 'high',
                    'confidence' => 0.6,
                    'payload'    => ['due_at' => now()->addHours(4)->toIso8601String(), 'type' => 'call', 'reason' => 'Cerrar lead caliente'],
                ]));
            }
            $this->suggestProfile($created, $skipped, $conversation, $base, 'hot', 'interested');
        }

        // ── Objetivos ────────────────────────────────────────────────────────
        if ($this->matches($text, ['bajar grasa', 'bajar de peso', 'adelgazar', 'perder grasa', 'quemar grasa'])) {
            $this->suggestTag($created, $skipped, $base, $existingTags, 'objetivo-bajar-grasa', 'Etiquetar objetivo bajar grasa', 0.7);
        }
        if ($this->matches($text, ['ganar masa', 'aumentar musculo', 'ganar musculo', 'volumen', 'masa muscular'])) {
            $this->suggestTag($created, $skipped, $base, $existingTags, 'objetivo-ganar-masa', 'Etiquetar objetivo ganar masa', 0.7);
        }

        $reason = null;
        if ($created === []) {
            $reason = $skipped !== [] ? 'all_suggestions_deduplicated' : 'no_actionable_signals';
        }

        return ['created' => $created, 'skipped' => $skipped, 'reason' => $reason];
    }

    // ── Helpers de sugerencia / dedupe ───────────────────────────────────────

    /** Crea una sugerencia si no hay otra ABIERTA del mismo tipo; si no, registra skipped. */
    private function suggestOnce(array &$created, array &$skipped, MarketingConversation $conversation, string $type, array $data): void
    {
        if ($this->hasOpenAction($conversation, $type)) {
            $skipped[] = ['action_type' => $type, 'reason' => 'already_open'];

            return;
        }
        $created[] = $this->actions->createSuggestion(array_merge($data, ['action_type' => $type]));
    }

    /** Sugiere un tag si no existe ya y no hay otra sugerencia abierta de ese tag. */
    private function suggestTag(array &$created, array &$skipped, array $base, array $existingTags, string $tag, string $title, float $confidence): void
    {
        if (in_array($tag, $existingTags, true)) {
            $skipped[] = ['action_type' => MarketingAgentAction::TYPE_ADD_TAG, 'tag' => $tag, 'reason' => 'tag_exists'];

            return;
        }
        $dup = MarketingAgentAction::where('marketing_conversation_id', $base['marketing_conversation_id'])
            ->where('action_type', MarketingAgentAction::TYPE_ADD_TAG)
            ->whereIn('status', MarketingAgentAction::OPEN_STATUSES)
            ->get()
            ->contains(fn ($a) => ($a->payload['tag'] ?? null) === $tag);
        if ($dup) {
            $skipped[] = ['action_type' => MarketingAgentAction::TYPE_ADD_TAG, 'tag' => $tag, 'reason' => 'already_open'];

            return;
        }

        $created[] = $this->actions->createSuggestion(array_merge($base, [
            'action_type' => MarketingAgentAction::TYPE_ADD_TAG,
            'title'       => $title,
            'reason'      => 'Etiqueta sugerida por el análisis de la conversación.',
            'priority'    => 'low',
            'confidence'  => $confidence,
            'payload'     => ['tag' => $tag],
        ]));
    }

    private function suggestProfile(array &$created, array &$skipped, MarketingConversation $conversation, array $base, string $temperature, string $stage): void
    {
        if ($this->hasOpenAction($conversation, MarketingAgentAction::TYPE_UPDATE_LEAD_PROFILE)) {
            $skipped[] = ['action_type' => MarketingAgentAction::TYPE_UPDATE_LEAD_PROFILE, 'reason' => 'already_open'];

            return;
        }
        $created[] = $this->actions->createSuggestion(array_merge($base, [
            'action_type' => MarketingAgentAction::TYPE_UPDATE_LEAD_PROFILE,
            'title'       => 'Actualizar perfil comercial',
            'reason'      => 'Ajustar temperatura/etapa según la conversación.',
            'priority'    => 'normal',
            'confidence'  => 0.55,
            'payload'     => ['temperature' => $temperature, 'stage' => $stage],
        ]));
    }

    private function hasOpenAction(MarketingConversation $conversation, string $type): bool
    {
        return MarketingAgentAction::where('marketing_conversation_id', $conversation->id)
            ->where('action_type', $type)
            ->whereIn('status', MarketingAgentAction::OPEN_STATUSES)
            ->exists();
    }

    private function hasFutureAppointment(MarketingConversation $conversation): bool
    {
        return MarketingAppointment::query()
            ->where('status', MarketingAppointment::STATUS_SCHEDULED)
            ->where('scheduled_at', '>=', now())
            ->where(function ($q) use ($conversation): void {
                $q->where('marketing_conversation_id', $conversation->id);
                if ($conversation->lead_id) {
                    $q->orWhere('marketing_lead_id', $conversation->lead_id);
                }
            })
            ->exists();
    }

    private function recentInboundText(MarketingConversation $conversation): string
    {
        $bodies = MarketingMessage::where('conversation_id', $conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_INBOUND)
            ->latest('created_at')
            ->limit(10)
            ->pluck('body')
            ->all();

        return $this->normalize(implode(' ', array_filter($bodies)));
    }

    /** ¿El texto contiene alguno de los keywords (ya normalizados)? */
    private function matches(string $text, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (str_contains($text, $this->normalize($kw))) {
                return true;
            }
        }

        return false;
    }

    /** Parser best-effort de "mañana a las 5" / "hoy a las 17". Null si no es confiable. */
    private function parseDateTime(string $text): ?string
    {
        if (preg_match('/\b(hoy|manana)\b.*?\bla[s]?\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/u', $text, $m)) {
            $day = $m[1] === 'manana' ? now()->addDay() : now();
            $hour = (int) $m[2];
            $min = isset($m[3]) ? (int) $m[3] : 0;
            $ampm = $m[4] ?? '';
            if ($ampm === 'pm' && $hour < 12) {
                $hour += 12;
            }
            if ($ampm === '' && $hour >= 1 && $hour <= 7) {
                $hour += 12; // heurística: 1-7 sin am/pm => tarde
            }
            if ($hour > 23 || $min > 59) {
                return null;
            }

            return $day->setTime($hour, $min, 0)->toIso8601String();
        }

        return null;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        ]);
    }
}
