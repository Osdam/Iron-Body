<?php

namespace App\Services\Marketing;

use App\Models\MarketingAgentAction;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;

/**
 * Motor de recomendaciones del agente (Fase 4C). Determinista (reglas), analiza
 * la conversación y PROPONE acciones CRM razonables. No ejecuta nada: solo crea
 * sugerencias (status=suggested). Evita spam con reglas de deduplicación.
 */
class MarketingAgentRecommendationService
{
    public function __construct(private readonly MarketingAgentActionService $actions)
    {
    }

    /**
     * Analiza la conversación y crea sugerencias nuevas (no duplicadas).
     *
     * @return MarketingAgentAction[]
     */
    public function recommend(MarketingConversation $conversation): array
    {
        $conversation->loadMissing(['lead', 'tags']);
        $text = $this->recentInboundText($conversation);
        $created = [];

        $existingTags = $conversation->tags->pluck('tag')->all();
        $hasFutureAppointment = $this->hasFutureAppointment($conversation);
        $staffReviewPending = (bool) $conversation->staff_review_pending;

        $base = [
            'marketing_lead_id'         => $conversation->lead_id,
            'marketing_conversation_id' => $conversation->id,
            'suggested_by'              => 'ai',
        ];

        // ── Precio ───────────────────────────────────────────────────────────
        if ($this->matches($text, ['precio', 'cuanto', 'cuesta', 'costo', 'vale', 'mensualidad', 'tarifa'])) {
            $this->suggestTag($created, $base, $existingTags, 'precio', 'Etiquetar interés de precio', 0.7);
            $this->suggestOnce($created, $conversation, MarketingAgentAction::TYPE_DRAFT_REPLY, array_merge($base, [
                'title'      => 'Respuesta sugerida de precio',
                'reason'     => 'El lead preguntó por el precio; respuesta con plan + CTA suave (no se envía).',
                'priority'   => 'normal',
                'confidence' => 0.65,
                'payload'    => ['draft' => 'Con gusto. El plan mensual incluye acceso completo y acompañamiento. ¿Te gustaría que te cuente cómo arrancar o prefieres visitarnos primero?'],
            ]));
            $this->suggestOnce($created, $conversation, MarketingAgentAction::TYPE_CREATE_FOLLOW_UP, array_merge($base, [
                'title'      => 'Seguimiento si no responde (precio)',
                'reason'     => 'Programar seguimiento por interés de precio sin cierre.',
                'priority'   => 'normal',
                'confidence' => 0.5,
                'payload'    => ['due_at' => now()->addDay()->toIso8601String(), 'type' => 'task', 'reason' => 'Seguir interés de precio'],
            ]));
        }

        // ── Quiere visitar ───────────────────────────────────────────────────
        if ($this->matches($text, ['visitar', 'conocer', 'visita', 'ir al gym', 'ir al gimnasio', 'pasar por', 'quiero ir', 'puedo ir'])) {
            $this->suggestTag($created, $base, $existingTags, 'quiere-visita', 'Etiquetar intención de visita', 0.7);

            $when = $this->parseDateTime($text);
            if ($when !== null && ! $hasFutureAppointment) {
                $this->suggestOnce($created, $conversation, MarketingAgentAction::TYPE_CREATE_APPOINTMENT, array_merge($base, [
                    'title'      => 'Crear cita de visita',
                    'reason'     => 'El lead propuso un horario concreto para visitar.',
                    'priority'   => 'high',
                    'confidence' => 0.6,
                    'payload'    => ['type' => 'visit', 'title' => 'Visita al gimnasio', 'scheduled_at' => $when, 'duration_minutes' => 45],
                ]));
            } elseif (! $hasFutureAppointment) {
                $this->suggestOnce($created, $conversation, MarketingAgentAction::TYPE_SUGGEST_APPOINTMENT, array_merge($base, [
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
                $this->suggestOnce($created, $conversation, MarketingAgentAction::TYPE_REQUEST_STAFF_REVIEW, array_merge($base, [
                    'title'      => 'Marcar revisión humana',
                    'reason'     => 'El lead pidió hablar con una persona.',
                    'priority'   => 'high',
                    'confidence' => 0.8,
                    'payload'    => ['reason' => 'human_requested'],
                ]));
            }
            $this->suggestOnce($created, $conversation, MarketingAgentAction::TYPE_PAUSE_AI, array_merge($base, [
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
                $this->suggestOnce($created, $conversation, MarketingAgentAction::TYPE_REQUEST_STAFF_REVIEW, array_merge($base, [
                    'title'      => 'Marcar revisión (salud)',
                    'reason'     => 'El lead mencionó una posible lesión/condición de salud.',
                    'priority'   => 'urgent',
                    'confidence' => 0.8,
                    'payload'    => ['reason' => 'medical'],
                ]));
            }
            $this->suggestTag($created, $base, $existingTags, 'requiere-cuidado', 'Etiquetar caso de salud', 0.7);
            $this->suggestOnce($created, $conversation, MarketingAgentAction::TYPE_DRAFT_REPLY, array_merge($base, [
                'title'      => 'Respuesta responsable (salud)',
                'reason'     => 'Responder con cuidado y derivar a valoración profesional.',
                'priority'   => 'high',
                'confidence' => 0.6,
                'payload'    => ['draft' => 'Gracias por contarme. Para cuidar tu salud, lo ideal es una valoración con nuestro equipo antes de entrenar esa zona. ¿Quieres que te agende una valoración inicial?'],
            ]));
        }

        // ── Caliente / quiere empezar ────────────────────────────────────────
        if ($this->matches($text, ['quiero empezar', 'empezar hoy', 'inscribir', 'me interesa', 'quiero entrar', 'cuando puedo empezar', 'listo para'])) {
            $this->suggestTag($created, $base, $existingTags, 'lead-caliente', 'Etiquetar lead caliente', 0.75);
            if (! $hasFutureAppointment) {
                $this->suggestOnce($created, $conversation, MarketingAgentAction::TYPE_CREATE_FOLLOW_UP, array_merge($base, [
                    'title'      => 'Seguimiento de cierre',
                    'reason'     => 'Lead con alta intención; agendar seguimiento de cierre.',
                    'priority'   => 'high',
                    'confidence' => 0.6,
                    'payload'    => ['due_at' => now()->addHours(4)->toIso8601String(), 'type' => 'call', 'reason' => 'Cerrar lead caliente'],
                ]));
            }
            $this->suggestProfile($created, $conversation, $base, 'hot', 'interested');
        }

        // ── Objetivos ────────────────────────────────────────────────────────
        if ($this->matches($text, ['bajar grasa', 'bajar de peso', 'adelgazar', 'perder grasa', 'quemar grasa'])) {
            $this->suggestTag($created, $base, $existingTags, 'objetivo-bajar-grasa', 'Etiquetar objetivo bajar grasa', 0.7);
        }
        if ($this->matches($text, ['ganar masa', 'aumentar musculo', 'ganar musculo', 'volumen', 'masa muscular'])) {
            $this->suggestTag($created, $base, $existingTags, 'objetivo-ganar-masa', 'Etiquetar objetivo ganar masa', 0.7);
        }

        return $created;
    }

    // ── Helpers de sugerencia / dedupe ───────────────────────────────────────

    /** Crea una sugerencia si no hay otra ABIERTA del mismo tipo en la conversación. */
    private function suggestOnce(array &$created, MarketingConversation $conversation, string $type, array $data): void
    {
        if ($this->hasOpenAction($conversation, $type)) {
            return;
        }
        $created[] = $this->actions->createSuggestion(array_merge($data, ['action_type' => $type]));
    }

    /** Sugiere un tag si no existe ya y no hay otra sugerencia abierta de ese tag. */
    private function suggestTag(array &$created, array $base, array $existingTags, string $tag, string $title, float $confidence): void
    {
        if (in_array($tag, $existingTags, true)) {
            return;
        }
        // Dedupe por tag: ¿ya hay un add_tag abierto con ese tag?
        $dup = MarketingAgentAction::where('marketing_conversation_id', $base['marketing_conversation_id'])
            ->where('action_type', MarketingAgentAction::TYPE_ADD_TAG)
            ->whereIn('status', MarketingAgentAction::OPEN_STATUSES)
            ->get()
            ->contains(fn ($a) => ($a->payload['tag'] ?? null) === $tag);
        if ($dup) {
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

    private function suggestProfile(array &$created, MarketingConversation $conversation, array $base, string $temperature, string $stage): void
    {
        if ($this->hasOpenAction($conversation, MarketingAgentAction::TYPE_UPDATE_LEAD_PROFILE)) {
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
            // Heurística: horas 1-7 sin am/pm => tarde (gym), súmale 12.
            if ($ampm === '' && $hour >= 1 && $hour <= 7) {
                $hour += 12;
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
