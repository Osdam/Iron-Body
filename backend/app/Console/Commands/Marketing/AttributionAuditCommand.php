<?php

namespace App\Console\Commands\Marketing;

use App\Models\MarketingLead;
use App\Models\MarketingLeadAttribution;
use App\Models\MarketingMessage;
use App\Services\Marketing\Attribution\LeadIdentityResolver;
use App\Services\Marketing\Attribution\LeadSourceClassifier;
use App\Services\Marketing\Attribution\LeadUniverse;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto de lo que llega se puede atribuir, y por qué no lo demás.
 *
 * SOLO LECTURA: no escribe atribuciones ni identidades (las calcula en memoria).
 * Se puede correr en producción.
 *
 *  · ATTRIBUTABLE            el primer toque tiene evidencia completa: un
 *                            anuncio con su campaña resuelta, o una publicación.
 *  · PARTIALLY_ATTRIBUTABLE  hay evidencia a medias: un anuncio cuya campaña no
 *                            se resuelve contra la cuenta publicitaria conectada
 *                            (falta acceso a Meta Ads, o es de otra cuenta), o un
 *                            lead sin fila de atribución con un referral guardado
 *                            en sus mensajes (lo recupera
 *                            `marketing:attribution-backfill`).
 *  · UNATTRIBUTABLE          sin evidencia: nada en el primer contacto, o ni
 *                            fila ni referral.
 */
class AttributionAuditCommand extends Command
{
    public const ATTRIBUTABLE = 'ATTRIBUTABLE';

    public const PARTIALLY_ATTRIBUTABLE = 'PARTIALLY_ATTRIBUTABLE';

    public const UNATTRIBUTABLE = 'UNATTRIBUTABLE';

    /** Categoría y detalle, en el orden en que se informan. */
    private const DETAILS = [
        'paid_campaign_resolved' => self::ATTRIBUTABLE,
        'organic' => self::ATTRIBUTABLE,
        'direct' => self::ATTRIBUTABLE,
        'paid_campaign_pending_meta_ads' => self::PARTIALLY_ATTRIBUTABLE,
        'no_row_with_stored_referral' => self::PARTIALLY_ATTRIBUTABLE,
        'unknown_first_touch' => self::UNATTRIBUTABLE,
        'no_row_without_referral' => self::UNATTRIBUTABLE,
    ];

    protected $signature = 'marketing:attribution-audit
        {--json : Imprime el resultado en JSON}';

    protected $description = 'Cuenta los leads reales atribuibles, parcialmente atribuibles y sin atribución (solo lectura).';

    public function handle(LeadSourceClassifier $classifier, LeadIdentityResolver $resolver): int
    {
        $details = array_fill_keys(array_keys(self::DETAILS), 0);
        $total = 0;
        $firstUnknownLastKnown = 0;
        $touchesDiffer = 0;

        LeadUniverse::constrain(MarketingLead::query())
            ->select(['id', 'channel'])
            ->chunkById(500, function (Collection $leads) use ($classifier, &$details, &$total, &$firstUnknownLastKnown, &$touchesDiffer): void {
                $ids = $leads->pluck('id')->map(fn ($id) => (int) $id)->all();
                $attributions = MarketingLeadAttribution::query()
                    ->whereIn('marketing_lead_id', $ids)
                    ->get()
                    ->keyBy(fn (MarketingLeadAttribution $a) => (int) $a->marketing_lead_id);

                $classifier->preload($attributions->flatMap(fn (MarketingLeadAttribution $a) => [
                    $a->first_touch_ad_id, $a->last_touch_ad_id, $a->ad_id,
                ])->filter()->all());

                $withoutRow = array_values(array_diff($ids, $attributions->keys()->all()));
                $withReferral = $this->leadsWithStoredReferral($withoutRow);

                foreach ($leads as $lead) {
                    $total++;
                    $a = $attributions->get((int) $lead->id);

                    if ($a === null) {
                        $details[isset($withReferral[(int) $lead->id]) ? 'no_row_with_stored_referral' : 'no_row_without_referral']++;

                        continue;
                    }

                    $first = $classifier->classify($a, $lead, 'first');
                    $last = $classifier->classify($a, $lead, 'last');

                    $details[match ($first['origin']) {
                        LeadSourceClassifier::ORIGIN_PAID => $first['campaign_id'] !== null ? 'paid_campaign_resolved' : 'paid_campaign_pending_meta_ads',
                        LeadSourceClassifier::ORIGIN_ORGANIC => 'organic',
                        LeadSourceClassifier::ORIGIN_DIRECT => 'direct',
                        default => 'unknown_first_touch',
                    }]++;

                    if ($first['key'] !== $last['key'] || $first['ad_id'] !== $last['ad_id']) {
                        $touchesDiffer++;
                    }
                    if ($first['origin'] === LeadSourceClassifier::ORIGIN_UNKNOWN && $last['origin'] !== LeadSourceClassifier::ORIGIN_UNKNOWN) {
                        $firstUnknownLastKnown++;
                    }
                }
            });

        $categories = array_fill_keys([self::ATTRIBUTABLE, self::PARTIALLY_ATTRIBUTABLE, self::UNATTRIBUTABLE], 0);
        foreach ($details as $detail => $count) {
            $categories[self::DETAILS[$detail]] += $count;
        }

        $report = [
            'real_leads' => $total,
            'categories' => $categories,
            'details' => $details,
            'first_and_last_touch_differ' => $touchesDiffer,
            'first_unknown_last_known' => $firstUnknownLastKnown,
            'identity' => $resolver->summarize(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Leads reales: {$total}");
        $this->table(
            ['categoría', 'detalle', 'leads'],
            array_map(fn (string $detail) => [self::DETAILS[$detail], $detail, $details[$detail]], array_keys(self::DETAILS)),
        );
        $this->table(['categoría', 'leads'], array_map(fn ($c) => [$c, $categories[$c]], array_keys($categories)));
        $this->line("Primer y último toque distintos: {$touchesDiffer}. Primer toque sin atribuir y último conocido: {$firstUnknownLastKnown}.");
        $this->table(['identidad', 'leads'], array_map(fn ($m) => [$m, $report['identity'][$m]], array_keys($report['identity'])));
        $this->comment('Solo lectura: no se escribió nada.');

        return self::SUCCESS;
    }

    /**
     * De estos leads, los que tienen algún mensaje entrante con el referral de
     * Meta guardado en su metadata.
     *
     * @param  list<int>  $leadIds
     * @return array<int, true>
     */
    private function leadsWithStoredReferral(array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        return DB::table('marketing_messages as m')
            ->join('marketing_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->whereIn('c.lead_id', $leadIds)
            ->where('m.direction', MarketingMessage::DIRECTION_INBOUND)
            ->whereNotNull('m.metadata->referral')
            ->distinct()
            ->pluck('c.lead_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
