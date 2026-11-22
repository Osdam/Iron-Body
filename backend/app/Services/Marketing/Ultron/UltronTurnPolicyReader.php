<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingConversation;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesIntents;

/**
 * LA POLÍTICA DEL TURNO, LEÍDA COMO LA CALCULA EL COMMIT Y SIN ESCRIBIR NADA
 * (cierre V2, apartado 4, «regenerar antes de sustituir», ola 4b; decisión L-3
 * del lead; plan del arquitecto `plan-arquitecto-a4-regenerar.md` §11).
 *
 * La comprobación de solo lectura (`ai/check`, {@see UltronDraftCheck}) marca
 * también las reglas de política que el modelo puede corregir reescribiendo.
 * Para que marque lo mismo que el commit, calcula la política con las MISMAS
 * funciones y las MISMAS entradas que el commit (`UltronCommitService`, paso
 * «10.bis) LA POLÍTICA DEL TURNO»):
 *  - la intención del borrador, con el saludo puro como saludo (el commit la
 *    pisa igual tras el validador; el validador no la cambia de otro modo,
 *    porque la puerta ya exige una intención del catálogo);
 *  - la fase normalizada de la conversación;
 *  - la memoria de ANTES del turno, con `ConversationMemoryService::load()`,
 *    que la LEE (la pregunta sin resolver y el objetivo caducado se derivan al
 *    leer y no se guardan);
 *  - el referente, con el resolutor sobre los planes vendibles;
 *  - el catálogo (`activePlans()`) y el texto de la persona;
 *  - y los hechos del gimnasio ({@see CommercialTurnPolicy::knownFacts()}),
 *    solo si la política los exige: `violations()` no los mira en otro caso.
 *
 * DE SOLO LECTURA, y es la mitad del diseño: del servicio de la memoria solo
 * llama a `load()` y a `sellablePlansForMemory()`. Ni cerrojo, ni escrituras,
 * ni red. Lo fijan `UltronCheckEndpointTest` (una guarda estática sobre este
 * fichero y otra que escucha las consultas: solo `select`, la memoria igual) y
 * `UltronCheckCommitParityTest` (la política leída aquí es la que guarda el
 * commit en `metadata.policy`).
 */
final class UltronTurnPolicyReader
{
    public function __construct(
        private readonly ConversationMemoryService $memoryService,
        private readonly ReferenceResolver $references,
        private readonly CommercialPhaseMachine $phases,
        private readonly MarketingKnowledgeBaseService $knowledge,
        private readonly GymFactsProvider $gym = new GymFactsProvider,
    ) {}

    /**
     * La intención con la que el commit decide la política: la del borrador,
     * salvo un saludo puro, que es un saludo diga lo que diga la etiqueta.
     */
    public static function intentForPolicy(string $intent, string $inbound): string
    {
        return $intent !== SalesIntents::GREETING && SalesIntents::isPureGreeting($inbound) ? SalesIntents::GREETING : $intent;
    }

    /**
     * @param  string  $intent  la intención del borrador (ya del catálogo)
     * @param  array<int,array<string,mixed>>  $catalog  `activePlans()`, leído por quien llama
     * @return array{policy:array<string,mixed>,facts:list<string>}
     */
    public function read(MarketingConversation $conversation, string $inbound, string $intent, array $catalog): array
    {
        $memoria = $this->memoryService->load($conversation);
        $resolucion = $this->references->resolve($inbound, $memoria, $this->memoryService->sellablePlansForMemory());
        $politica = CommercialTurnPolicy::decide(
            self::intentForPolicy($intent, $inbound),
            $this->phases->normalize($conversation->commercial_phase),
            $memoria,
            $catalog,
            $resolucion,
            $inbound,
        );

        $hechos = in_array('gym_identity', (array) ($politica['required_facts'] ?? []), true)
            ? CommercialTurnPolicy::knownFacts($this->knowledge->activeItems(), (array) ($this->gym->forPrompt()['classes'] ?? []))
            : [];

        return ['policy' => $politica, 'facts' => $hechos];
    }
}
