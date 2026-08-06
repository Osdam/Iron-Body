<?php

namespace App\Services\Commercial;

use App\Models\CommercialOpportunity;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolExecutor;
use App\Services\Commercial\Tools\ToolResult;
use App\Services\Observability\ChannelLog;

/**
 * De una decisión a un efecto real.
 *
 * El motor decide «hay que mandarle el enlace del plan mensual». Esta clase es
 * lo que traduce esa frase a una llamada concreta con argumentos validados, y
 * lo que deja escrito qué se decidió, por qué, con qué se ejecutó, qué
 * devolvió y cuál es el objetivo siguiente.
 *
 * Tres cosas que hace y que no son evidentes:
 *
 *  · **Vuelve a comprobar la política de contacto justo antes de actuar.** La
 *    decisión pudo tomarse hace horas; entre medias la persona pudo pedir que
 *    no le escriban, o pudo entrar un asesor. Confiar en la comprobación del
 *    momento de decidir es lo que produce el mensaje que llega después de que
 *    alguien dijera «no me escriban más».
 *
 *  · **La clave de idempotencia sale de la OPORTUNIDAD y del intento**, no de
 *    la llamada. Así un reintento del mismo intento no duplica el efecto, pero
 *    un segundo intento legítimo —el seguimiento de la semana siguiente— sí
 *    puede ejecutarse.
 *
 *  · **No redacta.** Devuelve el hecho ejecutado; el texto que se le manda al
 *    cliente lo escribe el agente con ese hecho delante. Separar las dos cosas
 *    es lo que impide que un fallo de la pasarela se convierta en una frase
 *    inventada.
 */
class OpportunityExecutor
{
    /**
     * Qué herramienta ejecuta cada acción decidida.
     *
     * Un mapa explícito y cerrado. Las acciones que no aparecen —`wait`,
     * `check_in`, `ask_discovery`— son conversación pura: no tienen efecto en
     * ningún sistema y las resuelve el agente hablando.
     */
    private const ACTION_TOOLS = [
        V::ACTION_SEND_PAYMENT_LINK => 'create_payment_link',
        V::ACTION_RESEND_PAYMENT_LINK => 'create_payment_link',
        V::ACTION_OFFER_APPOINTMENT => 'book_appointment',
        V::ACTION_ESCALATE_HUMAN => 'escalate_to_human',
        V::ACTION_GUIDE_APP_LINK => 'get_app_account_status',
        V::ACTION_CONFIRM_ACTIVATION => 'get_membership_status',
    ];

    public function __construct(
        private readonly ToolExecutor $tools,
        private readonly ContactPolicy $policy,
        private readonly NextBestActionEngine $engine,
    ) {}

    /**
     * Ejecuta la acción decidida de una oportunidad.
     *
     * @return array{executed:bool, reason:?string, tool:?string, result:?array, next_goal:?string}
     */
    public function execute(CommercialOpportunity $opportunity): array
    {
        if (! (bool) config('commercial.enabled')) {
            return $this->outcome(false, 'engine_disabled');
        }

        $lead = $opportunity->marketing_lead_id
            ? MarketingLead::find($opportunity->marketing_lead_id)
            : null;
        $member = $opportunity->member_id
            ? Member::find($opportunity->member_id)
            : null;

        if ($lead === null && $member === null) {
            return $this->outcome(false, 'no_subject');
        }

        $subject = CommercialSubject::build($lead, $member);

        // La política se vuelve a comprobar AQUÍ, pegada al efecto. Entre la
        // decisión y este instante la persona pudo pedir que no le escriban.
        $permission = $this->policy->check($opportunity, $subject);

        if (! $permission['allowed']) {
            // No es un fallo: es el sistema comportándose. Se aplaza.
            $opportunity->forceFill(['act_after' => $permission['retry_at']])->save();

            ChannelLog::info('commercial.execution.deferred', [
                'opportunity_id' => $opportunity->id,
                'reason' => $permission['reason'],
                'retry_at' => $permission['retry_at']?->toIso8601String(),
            ]);

            return $this->outcome(false, $permission['reason']);
        }

        $action = (string) $opportunity->next_action;
        $toolName = self::ACTION_TOOLS[$action] ?? null;

        if ($toolName === null) {
            // Conversación pura: no hay nada que ejecutar, y eso es correcto.
            return $this->outcome(false, 'conversational_action', nextGoal: $opportunity->goal);
        }

        $arguments = $this->argumentsFor($action, $opportunity, $subject);

        if ($arguments === null) {
            return $this->outcome(false, 'missing_arguments', $toolName);
        }

        $conversation = $opportunity->marketing_conversation_id
            ? MarketingConversation::find($opportunity->marketing_conversation_id)
            : MarketingConversation::query()->where('lead_id', $lead?->id)->latest('id')->first();

        $context = new ToolContext(
            lead: $lead,
            member: $member,
            conversation: $conversation,
            opportunity: $opportunity,
            requestedBy: 'engine',
            correlationId: $opportunity->correlation_id,
            // Oportunidad + número de intento: un reintento del MISMO intento no
            // duplica; el seguimiento de la semana que viene sí puede ejecutarse.
            idempotencyKey: "opportunity:{$opportunity->id}:attempt:".($opportunity->attempts + 1),
        );

        $result = $this->tools->execute($toolName, $arguments, $context);

        return $this->recordOutcome($opportunity, $subject, $toolName, $result);
    }

    /**
     * Construye los argumentos desde la DECISIÓN, nunca desde texto libre.
     *
     * El plan sale de `offer_plan_id`, que puso el motor leyendo el catálogo.
     * En ningún punto de este camino hay un importe que alguien pueda proponer.
     */
    private function argumentsFor(
        string $action,
        CommercialOpportunity $opportunity,
        CommercialSubject $subject,
    ): ?array {
        return match ($action) {
            V::ACTION_SEND_PAYMENT_LINK,
            V::ACTION_RESEND_PAYMENT_LINK => $opportunity->offer_plan_id !== null
                ? ['plan_id' => (int) $opportunity->offer_plan_id]
                : null,

            V::ACTION_ESCALATE_HUMAN => [
                'reason' => $subject->needsHuman ? 'customer_request' : 'complex_case',
            ],

            // La cita se PROPONE en la conversación y la confirma la persona; el
            // motor no elige la hora por su cuenta. Sin hora acordada no hay
            // nada que reservar, y por eso esto devuelve null a propósito.
            V::ACTION_OFFER_APPOINTMENT => null,

            default => [],
        };
    }

    /** Escribe el desenlace en la oportunidad y calcula el objetivo siguiente. */
    private function recordOutcome(
        CommercialOpportunity $opportunity,
        CommercialSubject $subject,
        string $toolName,
        ToolResult $result,
    ): array {
        if ($result->successful()) {
            // Se cuenta el intento SOLO cuando hubo efecto. Contar los fallos
            // agotaría los intentos de alguien sin haberle escrito nunca.
            $opportunity->recordAttempt();

            ChannelLog::info('commercial.execution.succeeded', [
                'opportunity_id' => $opportunity->id,
                'goal' => $opportunity->goal,
                'tool' => $toolName,
                'attempts' => $opportunity->attempts,
            ]);

            // Ninguna venta termina la relación: se recalcula de inmediato.
            $next = $this->engine->decide($subject);

            return $this->outcome(
                true,
                null,
                $toolName,
                $result->toArray(),
                $next['goal'] ?? null,
            );
        }

        $opportunity->forceFill([
            'outcome_reason' => $result->errorCode,
        ])->save();

        // Un fallo transitorio se reintenta; uno definitivo cierra el objetivo,
        // porque insistir con los mismos datos daría el mismo error.
        if (! $result->retryable) {
            $opportunity->close(V::STATUS_LOST, $result->errorCode ?? 'tool_failed');
        }

        ChannelLog::warning('commercial.execution.failed', [
            'opportunity_id' => $opportunity->id,
            'tool' => $toolName,
            'error_code' => $result->errorCode,
            'retryable' => $result->retryable,
        ]);

        return $this->outcome(false, $result->errorCode, $toolName, $result->toArray());
    }

    private function outcome(
        bool $executed,
        ?string $reason = null,
        ?string $tool = null,
        ?array $result = null,
        ?string $nextGoal = null,
    ): array {
        return [
            'executed' => $executed,
            'reason' => $reason,
            'tool' => $tool,
            'result' => $result,
            'next_goal' => $nextGoal,
        ];
    }
}
