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
 *
 * I-4: el barrido pide sus páginas con {@see pageFrom()} desde una POSICIÓN
 * (keyset), para que pasadas sucesivas recorran la audiencia entera; la
 * guarda la pasada ({@see OutreachRuns::SWEEP_CURSOR_NEXT}).
 */
final class TriggerPrefilters
{
    public const PERIODIC = [
        'class.upcoming_with_capacity', 'lead.followup_due', 'member.inactive', 'membership.expired_window',
        'membership.expiring_window', 'payment_link.abandoned', 'receivable.overdue', 'valuation.outdated',
    ];

    public const PAGE = 500;

    /** La posición en `class.upcoming_with_capacity`: `<session_date>:<class_id>:<users.id>` (I-4). */
    private const CLASS_POSITION = '/^(\d{4}-\d{2}-\d{2}):([1-9]\d{0,18}):(\d{1,19})$/D';

    /** La posición en los disparadores por id: el último id de la página anterior (I-4). */
    private const ID_POSITION = '/^[1-9]\d{0,18}$/D';

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
            'class.upcoming_with_capacity' => self::flagNext($this->classCandidates($now, $n('withinDays', 1), $limite)),
            default => null,
        };
    }

    /**
     * I-4: una página desde una POSICIÓN del barrido, para que las pasadas
     * sucesivas de una estrategia recorran su audiencia entera: keyset, sin
     * saltarse a nadie ni repetir a nadie dentro de una vuelta.
     *
     * La posición es opaca para quien llama y solo la produce esta clase:
     *  - `''` = el principio de una vuelta;
     *  - el último id de la página anterior, en los disparadores por id;
     *  - `<session_date>:<class_id>:<users.id>` en
     *    `class.upcoming_with_capacity`, cuyas ocurrencias van por fecha y
     *    clase, y dentro de cada una por `users.id`.
     *
     * `next` es la posición de la página siguiente; `''` si esta llegó al
     * final, y la próxima pasada empieza otra vuelta. Una posición que no se
     * entiende (de otra forma de disparador, o estropeada) empieza desde el
     * principio: se repasa a alguien, pero nunca se salta a nadie.
     *
     * @param  array{event: string, params?: array<string, int>}  $trigger
     * @return array{candidates: list<Candidate>, next: string}|null null = sin prefiltro
     */
    public function pageFrom(array $trigger, DateTimeInterface $now, string $position, int $limit = self::PAGE): ?array
    {
        $evento = (string) ($trigger['event'] ?? '');
        if ($evento === 'class.upcoming_with_capacity') {
            $p = is_array($trigger['params'] ?? null) ? $trigger['params'] : [];
            $desde = preg_match(self::CLASS_POSITION, $position, $m) === 1 ? [$m[1], (int) $m[2], (int) $m[3]] : null;
            $r = $this->classCandidates($now, is_int($p['withinDays'] ?? null) ? $p['withinDays'] : 1, max(1, min(self::PAGE, $limit)), $desde);

            return ['candidates' => $r['candidates'], 'next' => $r['next'] ?? ''];
        }
        $r = $this->page($trigger, $now, $limit, preg_match(self::ID_POSITION, $position) === 1 ? (int) $position : 0);

        return $r === null ? null : ['candidates' => $r['candidates'], 'next' => $r['next'] === null ? '' : (string) $r['next']];
    }

    /**
     * @param  array{candidates: list<Candidate>, next: string|null}  $r
     * @return array{candidates: list<Candidate>, next: int|null}
     */
    private static function flagNext(array $r): array
    {
        // `page()` conserva su forma: en las clases `next` solo dice si quedaron más (1); la posición la da `pageFrom()`.
        return ['candidates' => $r['candidates'], 'next' => $r['next'] === null ? null : 1];
    }

    /**
     * Las clases con cupo en la ventana y, por cada una, las personas a las
     * que se podría invitar (≤ `$limit` en total). Las ocurrencias van por
     * `(session_date, class_id)` ({@see ClassFacts::upcomingWithCapacity()}) y,
     * dentro de cada una, por `users.id`. Con `$from`, se empieza justo
     * después de esa posición: las ocurrencias anteriores ya se recorrieron
     * en esta vuelta. `next` es la posición donde seguir, o null si se llegó
     * al final.
     *
     * @param  array{0: string, 1: int, 2: int}|null  $from  `[session_date, class_id, users.id]`
     * @return array{candidates: list<Candidate>, next: string|null}
     */
    private function classCandidates(DateTimeInterface $now, int $withinDays, int $limit, ?array $from = null): array
    {
        $todos = [];
        foreach ($this->classes->upcomingWithCapacity($now, $withinDays) as $o) {
            if ($o['available'] <= 0) {
                continue;
            }
            $ocurrencia = [(string) $o['session_date'], (int) $o['class_id']];
            $despues = 0;
            if ($from !== null) {
                $orden = $ocurrencia <=> [$from[0], $from[1]];
                if ($orden < 0) {
                    continue;
                }
                $despues = $orden === 0 ? $from[2] : 0;
            }
            $resto = $limit - count($todos);
            if ($resto <= 0) {
                return ['candidates' => $todos, 'next' => "{$ocurrencia[0]}:{$ocurrencia[1]}:{$despues}"];
            }
            $r = $this->classes->candidatesForOccurrence($now, $ocurrencia[1], $ocurrencia[0], $o['class_session_id'], $resto, $despues);
            $todos = [...$todos, ...$r['candidates']];
            if ($r['next'] !== null) {
                // La página de esta ocurrencia se llenó: lo que queda de ella va en la página siguiente.
                return ['candidates' => $todos, 'next' => "{$ocurrencia[0]}:{$ocurrencia[1]}:{$r['next']}"];
            }
        }

        return ['candidates' => $todos, 'next' => null];
    }
}
