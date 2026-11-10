<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Facts\AttendanceFacts;
use App\Services\Outreach\Facts\Candidate;
use App\Services\Outreach\Facts\ClassFacts;
use App\Services\Outreach\Facts\LeadFacts;
use App\Services\Outreach\Facts\MembershipFacts;
use App\Services\Outreach\Facts\PaymentFacts;
use App\Services\Outreach\Facts\ReceivableFacts;
use App\Services\Outreach\Facts\ValuationFacts;
use DateTimeInterface;

/**
 * LA ÚNICA CORRESPONDENCIA disparador → prefiltro (contrato H9, D9-3, D9-5
 * «Prefiltros», L9-41): la usan el barrido (`OutreachPlanner::sweep()`) y la
 * simulación sobre muestra real del Studio (S9), para que los dos vean a las
 * mismas personas. SOLO LECTURA, por los adaptadores de B9-facts; ≤ 500 por
 * página, por id. Un prefiltro NO decide: el veredicto lo da siempre el
 * evaluador puro sobre la foto de hechos (D9-5).
 *
 *  | disparador                      | prefiltro (B9-facts)                                   | ventana de días de Bogotá                 |
 *  | membership.expiring_window      | MembershipFacts::endingBetween(0, daysBefore)          | vence en [hoy, hoy + N] (#1, #2: N = 0 es hoy) |
 *  | membership.expired_window       | MembershipFacts::endedBetween(min, max)                | venció hace [min, max], cerrado (#3)      |
 *  | member.inactive                 | AttendanceFacts::inactiveSince(days)                   | última entrada hace ≥ N                   |
 *  | receivable.overdue              | ReceivableFacts::overdueBetween(min, max)              | vencida hace [min, max]                   |
 *  | payment_link.abandoned          | PaymentFacts::abandonedLinks(graceHours)               | enlace abierto sin usar pasado el plazo   |
 *  | lead.followup_due               | LeadFacts::followupsDue()                              | seguimientos autorizados vencidos         |
 *  | valuation.outdated              | ValuationFacts::outdated(days)                         | última valoración hace ≥ N                |
 *  | class.upcoming_with_capacity    | ClassFacts::upcomingWithCapacity + candidatesForOccurrence | clases con cupo en [hoy, hoy + N]     |
 *
 * Los disparadores `crm_fact` no tienen prefiltro: llegan por el cursor de
 * `commercial_events` ({@see OutreachPlanner::ingest()}); aquí devuelven null.
 */
final class TriggerPrefilters
{
    public const PERIODIC = [
        'class.upcoming_with_capacity', 'lead.followup_due', 'member.inactive', 'membership.expired_window',
        'membership.expiring_window', 'payment_link.abandoned', 'receivable.overdue', 'valuation.outdated',
    ];

    public const PAGE = 500;

    public function __construct(
        private readonly MembershipFacts $membership = new MembershipFacts,
        private readonly AttendanceFacts $attendance = new AttendanceFacts,
        private readonly ReceivableFacts $receivables = new ReceivableFacts,
        private readonly PaymentFacts $payments = new PaymentFacts,
        private readonly LeadFacts $leads = new LeadFacts,
        private readonly ValuationFacts $valuations = new ValuationFacts,
        private readonly ClassFacts $classes = new ClassFacts,
    ) {}

    /** ¿Tiene prefiltro (es periódico)? Los `crm_fact` van por cursor. */
    public static function hasPrefilter(string $event): bool
    {
        return in_array($event, self::PERIODIC, true);
    }

    /** La cadencia del catálogo de un disparador periódico, en minutos (null si no es periódico). */
    public static function cadenceMinutes(string $event): ?int
    {
        $e = OutreachCatalog::event($event);

        return $e !== null && is_int($e['cadenceMinutes'] ?? null) ? $e['cadenceMinutes'] : null;
    }

    /**
     * Una página de candidatos del disparador, con sus parámetros ya
     * validados por `StrategyContent`.
     *
     * @param  array{event: string, params?: array<string, int>}  $trigger
     * @return array{candidates: list<Candidate>, next: int|null}|null null = sin prefiltro
     */
    public function page(array $trigger, DateTimeInterface $now, int $limit = self::PAGE, int $after = 0): ?array
    {
        $p = is_array($trigger['params'] ?? null) ? $trigger['params'] : [];
        $limite = max(1, min(self::PAGE, $limit));
        $n = static fn (string $k, int $default = 0): int => is_int($p[$k] ?? null) ? $p[$k] : $default;

        return match ((string) ($trigger['event'] ?? '')) {
            'membership.expiring_window' => $this->membership->endingBetween($now, 0, $n('daysBefore'), $limite, $after),
            'membership.expired_window' => $this->membership->endedBetween($now, $n('daysAfterMin', 1), $n('daysAfterMax', 1), $limite, $after),
            'member.inactive' => $this->attendance->inactiveSince($now, $n('days', 7), $limite, $after),
            'receivable.overdue' => $this->receivables->overdueBetween($now, $n('daysMin', 1), $n('daysMax', 1), $limite, $after),
            'payment_link.abandoned' => $this->payments->abandonedLinks($now, $n('graceHours', 1), $limite, $after),
            'lead.followup_due' => $this->leads->followupsDue($now, $limite, $after),
            'valuation.outdated' => $this->valuations->outdated($now, $n('days', 1), $limite, $after),
            'class.upcoming_with_capacity' => $this->classCandidates($now, $n('withinDays', 1), $limite),
            default => null,
        };
    }

    /**
     * Las clases con cupo en la ventana y, por cada una, las personas a las
     * que se podría invitar (≤ `$limit` en total; `next` = 1 si quedaron más).
     *
     * @return array{candidates: list<Candidate>, next: int|null}
     */
    private function classCandidates(DateTimeInterface $now, int $withinDays, int $limit): array
    {
        $todos = [];
        $mas = false;
        foreach ($this->classes->upcomingWithCapacity($now, $withinDays) as $o) {
            if ($o['available'] <= 0) {
                continue;
            }
            $r = $this->classes->candidatesForOccurrence($now, (int) $o['class_id'], (string) $o['session_date'], $o['class_session_id'], $limit - count($todos));
            $todos = [...$todos, ...$r['candidates']];
            if (count($todos) >= $limit) {
                $mas = true;

                break;
            }
        }

        return ['candidates' => $todos, 'next' => $mas ? 1 : null];
    }
}
