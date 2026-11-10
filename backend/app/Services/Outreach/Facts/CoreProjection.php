<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\OutcomeTable;
use App\Services\Outreach\Core\OutreachCatalog;
use InvalidArgumentException;

/**
 * LA UNIÓN con el núcleo de B9-core (contrato H9, D9-5, D9-7, D9-12). PURA:
 * convierte la foto del lote ({@see BatchSnapshot} / {@see SubjectFacts}) en
 * lo que consumen las piezas puras, sin volver a la base.
 *
 *  - {@see selectionFor()}: qué leer para lo que USA una condición
 *    (`CanonicalCondition::uses()`): «solo los hechos que usa la estrategia».
 *  - {@see snapshot()}: la foto de UNA persona (`Core\FactSnapshot`: `facts`,
 *    `contacts`, `events`, `transitions`), la que evalúa `ConditionEvaluator` y
 *    se guarda en `outreach_decisions.facts_json`. Los contactos llevan
 *    `strategy` = `self` (los de la estrategia que evalúa), `other` (los de otra
 *    estrategia del H9) o null (de fuera del H9), y su resultado solo si es del
 *    conjunto cerrado de D9-10.
 *  - {@see budgetHistory()}: el historial de `ContactBudget::check()`.
 *  - {@see phoneKeys()} y {@see sharedPhones()} (L9-29): los seudónimos de
 *    teléfono con los que se aplica el tope diario TAMBIÉN por teléfono.
 *  - {@see policyInput()}: las entradas de PERSONA de `PolicyLadder::decide()`
 *    (`subject`, `consent`, `voice`, `history`, los frenos ajenos de
 *    `operations`, la identidad de canal de `channelState` y la cartera de
 *    `context`). El resto (banderas, estrategia, calendario) es de B9-run.
 *
 * Lo que no se leyó (no se pidió o la fuente no tiene datos) sale null: el
 * núcleo lo trata como DESCONOCIDO y niega o deja fuera (el lado seguro).
 */
final class CoreProjection
{
    /** Los hechos que mira N1–N4 aunque la condición no los use (D9-7, P9-7). */
    public const POLICY_FACTS = [
        'conversation.human_in_control', 'receivable.has_overdue', 'receivable.overdue_days_max',
        'subject.has_voice_contact', 'subject.has_whatsapp_identity', 'subject.is_member', 'subject.is_minor',
    ];

    /**
     * Lo que hay que leer para lo que usa una condición (y, si `$policy`, lo de N1–N4).
     *
     * @param  array{facts?: list<string>, events?: list<string>, contacts?: bool, transitions?: bool}  $uses  `CanonicalCondition::uses()`
     */
    public static function selectionFor(array $uses, bool $policy = true): FactSelection
    {
        $hechos = array_values(array_filter($uses['facts'] ?? [], static fn ($f): bool => is_string($f) && array_key_exists($f, FactNames::FACT_TYPES)));
        if ($policy) {
            $hechos = [...$hechos, ...self::POLICY_FACTS];
        }
        $hechos = array_values(array_unique($hechos));
        sort($hechos, SORT_STRING);
        $eventos = array_values(array_filter($uses['events'] ?? [], static fn ($e): bool => is_string($e) && array_key_exists($e, OutreachCatalog::CRM_EVENTS)));
        $eventos = array_values(array_unique($eventos));
        sort($eventos, SORT_STRING);

        return new FactSelection(
            facts: $hechos,
            events: $eventos,
            // El presupuesto de N1 necesita el historial aunque la condición no lo use.
            contacts: ($uses['contacts'] ?? false) === true || $policy,
            policy: $policy,
            global: $policy,
            transitions: ($uses['transitions'] ?? false) === true,
        );
    }

    /**
     * La foto de una persona para el evaluador.
     *
     * @param  string|null  $strategyUuid  la estrategia que evalúa (para `strategy: self`)
     *
     * @throws InvalidArgumentException si la foto del lote trae algo que el núcleo no admite (un error de construcción)
     */
    public static function snapshot(SubjectFacts $s, ?string $strategyUuid = null): FactSnapshot
    {
        $contactos = null;
        if ($s->contacts !== null) {
            $contactos = [];
            foreach ($s->contacts as $c) {
                $estrategia = $c['strategy'] ?? null;
                $contactos[] = [
                    'at' => (string) $c['at'],
                    'channel' => (string) $c['medium'],
                    'outcome' => is_string($c['outcome'] ?? null) && OutcomeTable::isOutcome($c['outcome']) ? $c['outcome'] : null,
                    'purpose' => (string) $c['purpose'],
                    'strategy' => $estrategia === null ? null : ($strategyUuid !== null && $estrategia === $strategyUuid ? 'self' : 'other'),
                ];
            }
        }

        return FactSnapshot::fromArray([
            'facts' => $s->facts,
            'contacts' => $contactos,
            'events' => $s->events,
            'transitions' => $s->transitions,
        ]);
    }

    /**
     * El historial de `ContactBudget::check()` (`{at, medium, purpose, source}`).
     * null si la foto no trae los contactos (no se sabe: N1 niega).
     *
     * @return list<array{at: string, medium: string, purpose: string, source: string}>|null
     */
    public static function budgetHistory(SubjectFacts $s): ?array
    {
        if ($s->contacts === null) {
            return null;
        }

        return array_map(static fn (array $c): array => [
            'at' => (string) $c['at'], 'medium' => (string) $c['medium'], 'purpose' => (string) $c['purpose'], 'source' => (string) $c['source'],
        ], $s->contacts);
    }

    /**
     * Las entradas de PERSONA de `PolicyLadder::decide()` para una finalidad,
     * con la forma de su `SPEC` (la `baseInput` de `policy-ladder-cases.json`):
     * `subject`, `consent`, `optOut`, `voice`, `history`, `conversation` e
     * `identity` enteros, y de `brakes` y `flags` solo los frenos y banderas
     * AJENOS que lee {@see OperationalBrakes}. Lo demás (el ahora, el canal, los
     * límites, el horario, los frenos propios, la estrategia, el contexto) lo
     * pone B9-run. Lo que no se leyó sale null (la escalera lo niega: falla
     * cerrado); sin la lectura de los frenos ajenos, todos FRENAN.
     *
     * @param  array{brakes: array<string, bool>, unreadable: list<string>}|null  $global
     * @return array{subject: array{known: bool, isMinor: ?bool}, consent: array{voice: string, whatsapp: string},
     *               optOut: array{commercial: ?bool, doNotContact: ?bool, siblingDoNotContact: ?bool}, voice: array{excluded: ?bool, rneOk: ?bool},
     *               history: list<array<string, string>>, conversation: array{humanInControl: ?bool}, identity: array{voiceContact: ?bool, whatsapp: ?bool},
     *               brakes: array{ultronAbortLatch: bool, marketingAgentPaused: bool, voiceBrake: bool}, flags: array{legacyAutonomy: bool, legacyFollowupsScheduler: bool}}
     *
     * @throws InvalidArgumentException con una finalidad fuera del conjunto cerrado, o una persona resuelta
     *                                  sin su lista de contactos (el presupuesto no se puede contar «a ojo»)
     */
    public static function policyInput(SubjectFacts $s, string $purpose, ?array $global): array
    {
        if (! in_array($purpose, OutreachCatalog::PURPOSES, true)) {
            throw new InvalidArgumentException('Finalidad fuera del conjunto cerrado.');
        }
        if ($s->resolved() && $s->contacts === null) {
            // Una lista vacía diría «nunca se le contactó» y dejaría pasar el presupuesto: es un error de quien construye la foto.
            throw new InvalidArgumentException('La política necesita la lista de contactos de la persona (FactSelection con contacts).');
        }
        $p = $s->policy;
        $bool = static fn (string $k): ?bool => $p === null ? null : ($p[$k] ?? null);
        $hechoBool = static fn (string $k): ?bool => $s->has($k) && is_bool($s->fact($k)) ? $s->fact($k) : null;
        $negado = $bool('lead.consent_denied') !== false;

        $consentimiento = static function (string $canal) use ($p, $purpose, $negado): string {
            if ($p === null) {
                return 'missing';
            }
            if ($negado) {
                return 'denied';
            }

            return ($p["consent.{$canal}.{$purpose}"] ?? false) === true ? 'granted' : 'missing';
        };

        $frenos = $global['brakes'] ?? null;
        $freno = static fn (string $k): bool => $frenos === null || ($frenos[$k] ?? true) === true;

        $tieneVoz = $hechoBool('subject.has_voice_contact');
        $rneFalta = $bool('voice.rne_check_missing');

        return [
            'subject' => ['known' => $s->resolved(), 'isMinor' => $hechoBool('subject.is_minor')],
            'consent' => ['voice' => $consentimiento('voice_call'), 'whatsapp' => $consentimiento('whatsapp')],
            'optOut' => [
                'commercial' => $bool('lead.commercial_opt_out'),
                'doNotContact' => $bool('lead.do_not_contact'),
                'siblingDoNotContact' => $bool('lead.do_not_contact_sibling'),
            ],
            'voice' => [
                'excluded' => $bool('voice.excluded'),
                // Sin contacto de voz no hay consulta al RNE que valga.
                'rneOk' => $rneFalta === null ? null : ($tieneVoz === true && $rneFalta === false),
            ],
            // Persona sin resolver: N1 la niega igual (`unknown_subject`).
            'history' => self::budgetHistory($s) ?? [],
            'conversation' => ['humanInControl' => $hechoBool('conversation.human_in_control')],
            'identity' => ['voiceContact' => $tieneVoz, 'whatsapp' => $hechoBool('subject.has_whatsapp_identity')],
            'brakes' => [
                'ultronAbortLatch' => $freno('ultron_abort_latch'),
                'marketingAgentPaused' => $freno('marketing_agent_paused'),
                'voiceBrake' => $freno('voice_braked'),
            ],
            'flags' => [
                'legacyAutonomy' => $freno('legacy_autonomy_on'),
                'legacyFollowupsScheduler' => $freno('legacy_followups_active'),
            ],
        ];
    }

    /**
     * Los seudónimos de teléfono que cuentan para el tope diario POR TELÉFONO
     * (L9-29): TODOS los números de la persona (la lectura estricta: un contacto
     * de hoy a cualquiera de ellos ocupa el día de quien comparta ese número).
     * null si la foto no los trae (no se pidió la política): no se sabe.
     *
     * @return list<string>|null
     */
    public static function phoneKeys(SubjectFacts $s): ?array
    {
        return $s->phoneRefs;
    }

    /**
     * Las personas del lote que comparten algún número, por seudónimo (solo los
     * compartidos, ordenados): dentro de una pasada, a lo sumo UNA de cada grupo
     * puede llevarse el contacto del día (la etapa 3 de D9-8, extendida al
     * teléfono). Entre pasadas, B9-run lo aplica con el `phone_ref` guardado.
     *
     * @return array<string, list<string>> `phone_ref` → claves pedidas
     */
    public static function sharedPhones(BatchSnapshot $snapshot): array
    {
        $grupos = [];
        foreach ($snapshot->subjects as $clave => $s) {
            foreach ($s->phoneRefs ?? [] as $ref) {
                $grupos[$ref][] = (string) $clave;
            }
        }
        $compartidos = [];
        foreach ($grupos as $ref => $claves) {
            $claves = array_values(array_unique($claves));
            if (count($claves) > 1) {
                sort($claves, SORT_STRING);
                $compartidos[(string) $ref] = $claves;
            }
        }
        ksort($compartidos, SORT_STRING);

        return $compartidos;
    }
}
