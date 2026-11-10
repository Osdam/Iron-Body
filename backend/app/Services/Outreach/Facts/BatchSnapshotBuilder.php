<?php

namespace App\Services\Outreach\Facts;

use App\Services\Outreach\Core\RelativeTime;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Construye la FOTO DE HECHOS DE UN LOTE (contrato H9, D9-8 etapas 2, 4 y 6;
 * D9-16 «sin N+1: los hechos se cargan por lotes por adaptador»).
 *
 * UNA CONSULTA POR FUENTE PARA N PERSONAS, nunca N × fuentes: el resolutor y
 * cada adaptador leen el lote entero con `IN (…)` y reparten en memoria. Un
 * adaptador cuyos hechos no se piden ({@see FactSelection}) no consulta nada.
 *
 * SOLO LECTURA: ningún adaptador escribe (lo prueba un escuchador de consultas
 * en `FactsReadOnlyTest`). El reloj entra como argumento: misma fecha de
 * referencia y misma base, misma foto y misma huella.
 */
final class BatchSnapshotBuilder
{
    public function __construct(
        private readonly SubjectResolver $resolver = new SubjectResolver,
        private readonly MembershipFacts $membership = new MembershipFacts,
        private readonly AttendanceFacts $attendance = new AttendanceFacts,
        private readonly PaymentFacts $payments = new PaymentFacts,
        private readonly ReceivableFacts $receivables = new ReceivableFacts,
        private readonly LeadFacts $leads = new LeadFacts,
        private readonly ClassFacts $classes = new ClassFacts,
        private readonly ValuationFacts $valuations = new ValuationFacts,
        private readonly VoiceConsentFacts $voice = new VoiceConsentFacts,
        private readonly EngineFacts $engine = new EngineFacts,
        private readonly ContactHistoryFacts $history = new ContactHistoryFacts,
        private readonly CanaryFacts $canaries = new CanaryFacts,
        private readonly OperationalBrakes $brakes = new OperationalBrakes,
    ) {}

    /**
     * @param  list<string>  $subjectKeys  claves pedidas (`m:<id>`/`l:<id>`), sin repetir; ≤ {@see BatchSnapshot::MAX_SUBJECTS}
     *
     * @throws InvalidArgumentException con más personas de las admitidas, una clave repetida o sin forma
     */
    public function build(array $subjectKeys, DateTimeInterface $now, ?FactSelection $selection = null): BatchSnapshot
    {
        $seleccion = $selection ?? FactSelection::all();
        $claves = array_values($subjectKeys);
        if (count($claves) > BatchSnapshot::MAX_SUBJECTS) {
            throw new InvalidArgumentException('Una foto admite como mucho '.BatchSnapshot::MAX_SUBJECTS.' personas.');
        }
        if (count(array_unique($claves)) !== count($claves)) {
            throw new InvalidArgumentException('Una foto no admite claves repetidas.');
        }
        foreach ($claves as $c) {
            if (! is_string($c) || ! SubjectKey::isValid($c)) {
                throw new InvalidArgumentException('Una foto solo admite claves m:<id> o l:<id>.');
            }
        }

        $lote = new FactPass($now, $claves === [] ? [] : $this->resolver->forKeys($claves));
        $hechos = array_fill_keys($claves, []);
        $politica = array_fill_keys($claves, []);
        $estado = array_fill_keys($claves, []);
        $eventos = [];
        $transiciones = [];
        $contactos = [];

        if ($claves !== []) {
            $fusionar = static function (array $porClave, array &$destino): void {
                foreach ($porClave as $clave => $valores) {
                    $destino[$clave] = [...$destino[$clave], ...$valores];
                }
            };
            $conEstado = $seleccion->wants(MembershipFacts::FACTS) || $seleccion->transitions;
            if ($conEstado) {
                $fusionar($this->membership->facts($lote), $hechos);
                $estado = $this->membership->lastTransitions();
            }
            foreach ([
                [$this->attendance, AttendanceFacts::FACTS], [$this->payments, PaymentFacts::FACTS], [$this->receivables, ReceivableFacts::FACTS],
                [$this->classes, ClassFacts::FACTS], [$this->valuations, ValuationFacts::FACTS], [$this->engine, EngineFacts::FACTS],
            ] as [$adaptador, $suyos]) {
                if ($seleccion->wants($suyos)) {
                    $fusionar($adaptador->facts($lote), $hechos);
                }
            }
            // Los dos que dan hechos Y política.
            if ($seleccion->wants(LeadFacts::FACTS) || $seleccion->policy) {
                foreach ($this->leads->facts($lote) as $clave => $r) {
                    $hechos[$clave] = [...$hechos[$clave], ...$r['facts']];
                    $politica[$clave] = [...$politica[$clave], ...$r['policy']];
                }
            }
            if ($seleccion->wants(VoiceConsentFacts::FACTS) || $seleccion->policy) {
                foreach ($this->voice->facts($lote) as $clave => $r) {
                    $hechos[$clave] = [...$hechos[$clave], ...$r['facts']];
                    $politica[$clave] = [...$politica[$clave], ...$r['policy']];
                }
            }
            if ($seleccion->policy) {
                $fusionar($this->canaries->facts($lote), $politica);
            }

            // Eventos: los pedidos y, si se piden transiciones, los que las prueban (una sola lectura).
            $tipos = $seleccion->events;
            if ($seleccion->transitions) {
                $tipos = array_values(array_unique([...$tipos, ...array_keys(EngineFacts::TRANSITION_EVENTS)]));
            }
            $leidos = $tipos !== [] ? $this->engine->events($lote, $tipos) : [];
            foreach ($claves as $clave) {
                $lista = $leidos[$clave] ?? null;
                if ($seleccion->events !== []) {
                    $eventos[$clave] = $lista === null ? null : array_values(array_filter($lista, static fn (array $e): bool => in_array($e['key'], $seleccion->events, true)));
                }
                if ($seleccion->transitions) {
                    // Fuente sin datos (eventos apagados o persona sin resolver): desconocido, también lo que prueba el estado (DF-C4).
                    $transiciones[$clave] = $lista === null ? null : self::sortTransitions([...($estado[$clave] ?? []), ...EngineFacts::transitionsFrom($lista)]);
                }
            }
            if ($seleccion->contacts) {
                $contactos = $this->history->contacts($lote);
            }
        }

        // L9-29: los seudónimos de sus teléfonos, con la política (el tope por
        // teléfono es del presupuesto). Las filas ya las leyó la política: sin
        // consultas de más. Los números no salen de aquí.
        $claveApp = $seleccion->policy && $claves !== [] ? SubjectRef::appKey() : null;

        $sujetos = [];
        foreach ($claves as $clave) {
            $s = $lote->subjects[$clave];
            $telefono = null;
            $telefonos = null;
            if ($claveApp !== null) {
                $telefonos = [];
                if ($s->resolved()) {
                    foreach ($lote->phonesOf($s) as $numero) {
                        $telefonos[] = PhoneRef::withKey($numero, $claveApp);
                    }
                    $principal = $lote->primaryPhoneOf($s);
                    $telefono = $principal !== null ? PhoneRef::withKey($principal, $claveApp) : null;
                }
                $telefonos = array_values(array_unique($telefonos));
                sort($telefonos, SORT_STRING);
            }
            $pedidos = array_intersect_key($hechos[$clave], array_flip($seleccion->facts));
            ksort($pedidos, SORT_STRING);
            $pol = null;
            if ($seleccion->policy) {
                $pol = array_intersect_key($politica[$clave], array_flip(FactNames::POLICY));
                ksort($pol, SORT_STRING);
            }
            $sujetos[$clave] = new SubjectFacts(
                key: $clave,
                canonicalKey: $s->key,
                ref: SubjectRef::of($s->key ?? $clave),
                facts: $pedidos,
                policy: $pol,
                events: $seleccion->events !== [] ? ($eventos[$clave] ?? null) : null,
                contacts: $seleccion->contacts ? ($contactos[$clave] ?? null) : null,
                transitions: $seleccion->transitions ? ($transiciones[$clave] ?? null) : null,
                phoneRef: $telefono,
                phoneRefs: $telefonos,
            );
        }

        $fuentes = [];
        if ($seleccion->contacts) {
            $fuentes['outreach_history'] = $this->history->hasOutreachSource();
        }
        if ($seleccion->events !== [] || $seleccion->transitions) {
            $fuentes['commercial_events'] = $this->engine->sourceEnabled();
        }

        return new BatchSnapshot(
            at: RelativeTime::format($lote->now),
            day: $lote->today,
            subjects: $sujetos,
            global: $seleccion->global ? $this->brakes->read() : null,
            sources: $fuentes,
        );
    }

    /**
     * @param  list<array{at: string, entity: string, from: string, to: string}>  $t
     * @return list<array{at: string, entity: string, from: string, to: string}>
     */
    private static function sortTransitions(array $t): array
    {
        usort($t, static fn (array $a, array $b): int => [$b['at'], $a['entity'], $a['from'], $a['to']] <=> [$a['at'], $b['entity'], $b['from'], $b['to']]);

        return $t;
    }
}
