<?php

namespace App\Services\Marketing\Ultron;

/**
 * Lo que ULTRON recuerda de una conversación, como ESTADO y no como prosa.
 *
 * Cada campo responde a una pregunta que el modelo no puede contestar leyendo
 * diez mensajes crudos: ¿qué precio ya di? ¿qué beneficios ya conté? ¿qué le
 * ofrecí en mi última frase, para saber a qué dice «sí»? Es un objeto de
 * valor: se carga del JSON de la conversación, se muta con métodos con nombre
 * y se vuelve a guardar entero. Nunca guarda datos de Meta ni teléfonos.
 */
final class ConversationMemory
{
    public const VERSION = 1;

    /** Cuántas entradas conserva cada lista antes de olvidar las más viejas. */
    private const MAX_ITEMS = 40;

    /** @var array<string,mixed> */
    private array $d;

    /** @param array<string,mixed>|null $data */
    private const LISTAS = ['facts_delivered', 'benefits_delivered', 'prices_delivered', 'plans_discussed', 'classes_discussed', 'objections_seen', 'questions_answered'];

    private function __construct(?array $data)
    {
        // Un JSON parcial o corrupto (una lista en null, un objeto donde iba
        // una lista) no puede tumbar decide con un TypeError: se sanea por clave.
        $blank = self::blank();
        $clean = [];
        foreach ($blank as $k => $default) {
            $v = is_array($data) && array_key_exists($k, $data) ? $data[$k] : $default;
            if (in_array($k, self::LISTAS, true)) {
                $clean[$k] = is_array($v) ? array_values($v) : [];
            } elseif (is_array($default) || $default === null) {
                $clean[$k] = is_array($v) ? $v : null;
            } else {
                $clean[$k] = $v;
            }
        }
        $this->d = $clean;
        $this->d['version'] = self::VERSION;
    }

    /** @param array<string,mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        return new self($data);
    }

    public static function empty(): self
    {
        return new self(null);
    }

    /** @return array<string,mixed> */
    public static function blank(): array
    {
        return [
            'version' => self::VERSION,
            'facts_delivered' => [],        // [{key, at, message_id}]
            'benefits_delivered' => [],     // [{plan_id, benefit, at}]
            'prices_delivered' => [],       // [{plan_id, at, message_id}]
            'plans_discussed' => [],        // [plan_id]
            'classes_discussed' => [],      // [string]
            'objections_seen' => [],        // [{type, at}]
            'questions_answered' => [],     // [{key, at}]
            'last_agent_question' => null,  // {text, at, message_id}
            'last_agent_offer' => null,     // {kind, plan_id, text, at}
            // La solicitud de día de cortesía viva, para no volver a pedir la
            // fecha que la persona ya dio y para no prometer una confirmación
            // que no existe. El valor es SIEMPRE un array: bajo un default
            // null, un escalar se pierde al releer.
            'courtesy_request' => null,     // {status, action_id, scheduled_at, date, time, at}
            'last_user_question' => null,   // {text, at, message_id}
            'unresolved_question' => null,  // {text, at, message_id}
            'pending_reference' => null,    // {type, kind|plan_id, at}
            'pending_confirmation' => null, // {question, kind, at}
            'commercial_commitment' => null, // {plan_id, at}
            'last_recommendation' => null,  // {plan_ids, at}
            'last_reference_resolution' => null, // {type, ...} del último turno
            'payment_context' => null,      // lo llena el motor de pagos
            'app_context' => null,          // lo llena el bloque de la app
            'updated_at' => null,
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->d;
    }

    public function get(string $key): mixed
    {
        return $this->d[$key] ?? null;
    }

    // ── Consultas ─────────────────────────────────────────────────────────────

    public function priceDeliveredFor(int $planId): bool
    {
        foreach ($this->d['prices_delivered'] as $p) {
            if ((int) ($p['plan_id'] ?? 0) === $planId) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    public function benefitsDeliveredFor(int $planId): array
    {
        $out = [];
        foreach ($this->d['benefits_delivered'] as $b) {
            if ((int) ($b['plan_id'] ?? 0) === $planId) {
                $out[] = (string) $b['benefit'];
            }
        }

        return array_values(array_unique($out));
    }

    public function factDelivered(string $key): bool
    {
        foreach ($this->d['facts_delivered'] as $f) {
            if (($f['key'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

    /** @return int[] */
    public function plansDiscussed(): array
    {
        return array_values(array_map('intval', $this->d['plans_discussed']));
    }

    /** El plan del que se viene hablando: la última recomendación, o el último discutido. */
    public function pendingPlanId(): ?int
    {
        $rec = $this->d['last_recommendation']['plan_ids'] ?? null;
        if (is_array($rec) && $rec !== []) {
            return (int) $rec[0];
        }
        $disc = $this->plansDiscussed();

        return $disc === [] ? null : (int) end($disc);
    }

    // ── Mutaciones (todas devuelven $this) ───────────────────────────────────

    public function deliverPrice(int $planId, string $at, int $messageId): self
    {
        if (! $this->priceDeliveredFor($planId)) {
            $this->push('prices_delivered', ['plan_id' => $planId, 'at' => $at, 'message_id' => $messageId]);
        }

        return $this->discussPlan($planId);
    }

    public function deliverBenefit(int $planId, string $benefit, string $at): self
    {
        if (! in_array($benefit, $this->benefitsDeliveredFor($planId), true)) {
            $this->push('benefits_delivered', ['plan_id' => $planId, 'benefit' => $benefit, 'at' => $at]);
        }

        return $this->discussPlan($planId);
    }

    public function deliverFact(string $key, string $at, int $messageId): self
    {
        if (! $this->factDelivered($key)) {
            $this->push('facts_delivered', ['key' => $key, 'at' => $at, 'message_id' => $messageId]);
        }

        return $this;
    }

    public function discussPlan(int $planId): self
    {
        if (! in_array($planId, $this->plansDiscussed(), true)) {
            $this->d['plans_discussed'][] = $planId;
            $this->d['plans_discussed'] = array_slice($this->d['plans_discussed'], -self::MAX_ITEMS);
        }

        return $this;
    }

    public function discussClass(string $name): self
    {
        if (! in_array($name, $this->d['classes_discussed'], true)) {
            $this->d['classes_discussed'][] = $name;
        }

        return $this;
    }

    public function seeObjection(string $type, string $at): self
    {
        return $this->push('objections_seen', ['type' => $type, 'at' => $at]);
    }

    public function answerQuestion(string $key, string $at): self
    {
        return $this->push('questions_answered', ['key' => $key, 'at' => $at]);
    }

    /** @param int[] $planIds */
    public function recommend(array $planIds, string $at): self
    {
        $ids = array_values(array_unique(array_map('intval', $planIds)));
        if ($ids !== []) {
            $this->d['last_recommendation'] = ['plan_ids' => $ids, 'at' => $at];
            foreach ($ids as $id) {
                $this->discussPlan($id);
            }
        }

        return $this;
    }

    public function commitTo(?int $planId, string $at): self
    {
        $this->d['commercial_commitment'] = $planId === null ? null : ['plan_id' => $planId, 'at' => $at];

        return $this;
    }

    /** La última pregunta del agente y, si era una oferta, qué ofrecía. */
    public function agentAsked(?string $question, ?string $offerKind, ?int $planId, string $at, int $messageId): self
    {
        $this->d['last_agent_question'] = $question === null ? null
            : ['text' => $question, 'at' => $at, 'message_id' => $messageId];

        if ($offerKind !== null) {
            $this->d['last_agent_offer'] = ['kind' => $offerKind, 'plan_id' => $planId, 'text' => $question, 'at' => $at];
            $this->d['pending_reference'] = ['type' => 'offer', 'kind' => $offerKind, 'plan_id' => $planId, 'at' => $at];
            $this->d['pending_confirmation'] = ['question' => $question, 'kind' => $offerKind, 'at' => $at];
        } else {
            // La última pregunta del agente sustituye a la anterior: una oferta
            // que ya no es la última frase no puede seguir cobrando un «dale».
            $this->d['last_agent_offer'] = null;
            $this->d['pending_confirmation'] = null;
            $plan = $this->pendingPlanId();
            $this->d['pending_reference'] = $plan === null ? null : ['type' => 'plan', 'plan_id' => $plan, 'at' => $at];
        }

        return $this;
    }

    /** Una oferta aceptada o rechazada deja de estar pendiente. */
    public function offerResolved(): self
    {
        $this->d['last_agent_offer'] = null;
        $this->d['pending_confirmation'] = null;

        return $this;
    }

    public function userAsked(?string $question, string $at, int $messageId): self
    {
        $this->d['last_user_question'] = $question === null ? null
            : ['source' => 'lead_verbatim', 'text' => $question, 'at' => $at, 'message_id' => $messageId];

        return $this;
    }

    /** @param array<string,mixed>|null $resolution */
    public function referenceResolved(?array $resolution): self
    {
        $this->d['last_reference_resolution'] = $resolution;

        return $this;
    }

    public function touch(string $at): self
    {
        $this->d['updated_at'] = $at;

        return $this;
    }

    /** @param array<string,mixed> $row */
    private function push(string $list, array $row): self
    {
        $this->d[$list][] = $row;
        $this->d[$list] = array_slice($this->d[$list], -self::MAX_ITEMS);

        return $this;
    }
}
