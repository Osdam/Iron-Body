<?php

namespace App\Services\Marketing\Analytics;

use Illuminate\Support\Carbon;

/**
 * Lo que los números dicen, calculado por reglas y no por un modelo.
 *
 * La regla que gobierna este archivo: **el modelo de lenguaje puede redactar un
 * insight, nunca calcularlo**. Cada afirmación que sale de aquí lleva la métrica
 * que la sostiene, con qué se comparó y en qué período; quien la lea puede
 * comprobarla en la tabla de campañas. Una conclusión económica generada por
 * una IA no es un dato, es una opinión con formato de dato, y en un panel de
 * rentabilidad esa confusión sale cara.
 *
 * Y ninguno de estos insights CAMBIA nada. Recomiendan revisar. Que una métrica
 * agregada modifique sola un precio o una promoción es exactamente el tipo de
 * automatismo que nadie sabe explicar tres meses después.
 */
class CommercialInsightsService
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Mínimo de leads para opinar sobre una campaña.
     *
     * Con tres leads y ninguna venta no hay nada que concluir: es ruido, y
     * anunciarlo como hallazgo entrena a la gente a ignorar el panel.
     */
    private const MIN_SAMPLE = 10;

    public function __construct(private readonly CampaignAnalyticsService $analytics) {}

    /**
     * @return array<int,array<string,mixed>> ordenados por gravedad
     */
    public function forPeriod(Carbon $from, Carbon $to): array
    {
        $rows = $this->analytics->breakdown('campaign', $from, $to);
        $quality = $this->analytics->attributionQuality($from, $to);
        $period = ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()];

        $insights = array_merge(
            $this->volumeWithoutConversion($rows, $period),
            $this->higherTicket($rows, $period),
            $this->moreRenewals($rows, $period),
            $this->linksWithoutPayment($rows, $period),
            $this->conversationsWithoutQualified($rows, $period),
            $this->slowToBuy($from, $to, $rows, $period),
            $this->attributionGap($quality, $period),
            $this->outdatedAds($quality, $period),
        );

        $order = [self::SEVERITY_CRITICAL => 0, self::SEVERITY_WARNING => 1, self::SEVERITY_INFO => 2];
        usort($insights, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return $insights;
    }

    // ── Reglas ──────────────────────────────────────────────────────────────

    /** Trae gente y no vende: es donde se está quemando presupuesto. */
    private function volumeWithoutConversion(array $rows, array $period): array
    {
        $average = $this->averageOf($rows, 'conversion_rate');
        $out = [];

        foreach ($rows as $row) {
            if ($row['leads'] < self::MIN_SAMPLE || $average === null) {
                continue;
            }

            $rate = $row['conversion_rate'];

            if ($rate === null || $rate >= $average * 0.5) {
                continue;
            }

            $out[] = $this->insight(
                type: 'high_volume_low_conversion',
                severity: self::SEVERITY_WARNING,
                subject: $row['bucket'],
                metric: 'conversion_rate',
                current: $rate,
                comparison: $average,
                period: $period,
                evidence: [
                    'leads' => $row['leads'],
                    'sales' => $row['sales'],
                    'revenue' => $row['revenue'],
                ],
                review: 'Revisar el mensaje de la campaña y a quién está llegando: trae gente que no compra.',
            );
        }

        return $out;
    }

    private function higherTicket(array $rows, array $period): array
    {
        $average = $this->averageOf($rows, 'average_ticket');
        $out = [];

        foreach ($rows as $row) {
            if ($row['sales'] < 3 || $average === null || $row['average_ticket'] === null) {
                continue;
            }

            if ($row['average_ticket'] <= $average * 1.2) {
                continue;
            }

            $out[] = $this->insight(
                type: 'higher_average_ticket',
                severity: self::SEVERITY_INFO,
                subject: $row['bucket'],
                metric: 'average_ticket',
                current: $row['average_ticket'],
                comparison: $average,
                period: $period,
                evidence: ['sales' => $row['sales'], 'revenue' => $row['revenue']],
                review: 'Atrae clientes de mayor valor. Vale la pena mirar qué dice y a quién.',
            );
        }

        return $out;
    }

    private function moreRenewals(array $rows, array $period): array
    {
        $out = [];

        foreach ($rows as $row) {
            if ($row['sales'] < 3 || $row['renewals'] === 0) {
                continue;
            }

            $share = $this->analytics->safeDivide($row['renewals'], $row['sales']);

            if ($share === null || $share < 0.4) {
                continue;
            }

            $out[] = $this->insight(
                type: 'retains_better',
                severity: self::SEVERITY_INFO,
                subject: $row['bucket'],
                metric: 'renewal_share',
                current: $share,
                comparison: null,
                period: $period,
                evidence: ['renewals' => $row['renewals'], 'sales' => $row['sales']],
                review: 'Los clientes que llegaron por aquí vuelven a pagar. Es la señal de calidad más difícil de conseguir.',
            );
        }

        return $out;
    }

    /** Se generan links y no se paga: se cae justo en la caja. */
    private function linksWithoutPayment(array $rows, array $period): array
    {
        $out = [];

        foreach ($rows as $row) {
            if ($row['payment_links'] < 5) {
                continue;
            }

            $rate = $this->analytics->safeDivide($row['payments_approved'], $row['payment_links']);

            if ($rate === null || $rate >= 0.4) {
                continue;
            }

            $out[] = $this->insight(
                type: 'links_without_payment',
                severity: self::SEVERITY_WARNING,
                subject: $row['bucket'],
                metric: 'link_to_payment_rate',
                current: $rate,
                comparison: 0.4,
                period: $period,
                evidence: [
                    'payment_links' => $row['payment_links'],
                    'payments_approved' => $row['payments_approved'],
                ],
                review: 'La gente llega hasta el pago y no lo termina. Mirar el medio de pago y el precio mostrado.',
            );
        }

        return $out;
    }

    private function conversationsWithoutQualified(array $rows, array $period): array
    {
        $out = [];

        foreach ($rows as $row) {
            if ($row['conversations'] < self::MIN_SAMPLE) {
                continue;
            }

            $rate = $this->analytics->safeDivide($row['qualified_leads'], $row['conversations']);

            if ($rate === null || $rate >= 0.25) {
                continue;
            }

            $out[] = $this->insight(
                type: 'conversations_without_qualified',
                severity: self::SEVERITY_WARNING,
                subject: $row['bucket'],
                metric: 'conversation_to_qualified_rate',
                current: $rate,
                comparison: 0.25,
                period: $period,
                evidence: [
                    'conversations' => $row['conversations'],
                    'qualified_leads' => $row['qualified_leads'],
                ],
                review: 'Abre muchas conversaciones que no llegan a ninguna parte. Puede estar atrayendo al público equivocado.',
            );
        }

        return $out;
    }

    private function slowToBuy(Carbon $from, Carbon $to, array $rows, array $period): array
    {
        $global = $this->analytics->timeToSale($from, $to);

        if (($global['samples'] ?? 0) < 5 || $global['median_days'] === null) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if ($row['sales'] < 3) {
                continue;
            }

            $campaign = $this->analytics->timeToSale($from, $to, ['campaign' => $row['bucket']]);

            if (($campaign['samples'] ?? 0) < 3 || $campaign['median_days'] === null) {
                continue;
            }

            if ($campaign['median_days'] <= $global['median_days'] * 1.5) {
                continue;
            }

            $out[] = $this->insight(
                type: 'slower_to_convert',
                severity: self::SEVERITY_INFO,
                subject: $row['bucket'],
                metric: 'median_days_to_sale',
                current: $campaign['median_days'],
                comparison: $global['median_days'],
                period: $period,
                evidence: ['samples' => $campaign['samples']],
                review: 'Tarda más en cerrar. No es malo por sí solo: puede pedir un seguimiento más largo.',
            );
        }

        return $out;
    }

    /**
     * Demasiado dinero sin poder decir de dónde vino.
     *
     * El insight más importante de la lista, porque cuestiona a todos los
     * demás: con la mitad de las ventas sin atribuir, el ranking de campañas
     * es una foto de una parte del negocio que nadie sabe cuál es.
     */
    private function attributionGap(array $quality, array $period): array
    {
        $share = $quality['unattributed_revenue_share'];

        if ($share === null || $share < 0.4) {
            return [];
        }

        return [$this->insight(
            type: 'high_unattributed_revenue',
            severity: $share >= 0.7 ? self::SEVERITY_CRITICAL : self::SEVERITY_WARNING,
            subject: 'global',
            metric: 'unattributed_revenue_share',
            current: $share,
            comparison: 0.4,
            period: $period,
            evidence: [
                'records' => $quality['records'],
                'known' => $quality['known'],
                'partial_records' => $quality['partial_records'],
            ],
            review: 'Buena parte de los ingresos no se puede atribuir. Los números por campaña describen solo una parte del negocio.',
        )];
    }

    private function outdatedAds(array $quality, array $period): array
    {
        if (($quality['outdated_campaigns'] ?? 0) === 0) {
            return [];
        }

        return [$this->insight(
            type: 'outdated_ads',
            severity: self::SEVERITY_WARNING,
            subject: 'global',
            metric: 'outdated_campaign_conversations',
            current: (float) $quality['outdated_campaigns'],
            comparison: 0.0,
            period: $period,
            evidence: ['conversations' => $quality['outdated_campaigns']],
            review: 'Hay pautas publicadas que prometen algo que ya no está en el catálogo. Conviene corregirlas en Meta.',
        )];
    }

    // ── Construcción ────────────────────────────────────────────────────────

    /**
     * Un insight con todo lo necesario para comprobarlo.
     *
     * `confidence` sale del tamaño de la muestra, no de una intuición: con
     * pocos datos se dice que hay pocos datos en vez de afirmar con seguridad.
     *
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function insight(
        string $type,
        string $severity,
        string $subject,
        string $metric,
        ?float $current,
        ?float $comparison,
        array $period,
        array $evidence,
        string $review,
    ): array {
        $sample = (int) ($evidence['leads'] ?? $evidence['sales'] ?? $evidence['conversations'] ?? $evidence['records'] ?? 0);

        return [
            'type' => $type,
            'severity' => $severity,
            'subject' => $subject,
            'metric' => $metric,
            'current_value' => $current,
            'comparison_value' => $comparison,
            'period' => $period,
            'evidence' => $evidence,
            'confidence' => match (true) {
                $sample >= 50 => 'high',
                $sample >= self::MIN_SAMPLE => 'medium',
                default => 'low',
            },
            'recommended_review' => $review,
            // Nada de esto ejecuta nada. Se dice explícitamente porque quien
            // lea la respuesta del endpoint tiene que saberlo sin preguntar.
            'automated_action' => null,
        ];
    }

    /** Media de una métrica, ignorando las filas donde no se pudo calcular. */
    private function averageOf(array $rows, string $key): ?float
    {
        $values = array_values(array_filter(
            array_column($rows, $key),
            fn ($v) => $v !== null,
        ));

        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }
}
