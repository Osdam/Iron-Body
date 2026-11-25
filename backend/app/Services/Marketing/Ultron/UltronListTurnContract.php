<?php

namespace App\Services\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\Ultron\Prompts\UltronPromptStamp;
use InvalidArgumentException;

/** The price-free rendering budget delivered before the first list draft. */
final class UltronListTurnContract
{
    public const CONTRACT = 'ultron-list-turn/1';

    public const SINCE_COMPILER = 9;

    private const FIELDS = ['contract', 'slot', 'placement', 'final_limit', 'list_chars', 'prose_budget', 'slot_chars', 'raw_draft_limit', 'separator_chars',
        'app_links_reserved_chars', 'app_links_fits', 'prose_budget_with_app_links', 'app_links_slot', 'app_links_slot_chars', 'raw_draft_limit_with_app_links'];

    /**
     * Read the same price-free contract from any role's user payload.
     * Absence preserves the old route; a present but malformed contract must
     * never silently select the generic prompt or transport.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,int|string|bool>|null
     */
    public static function fromPayload(array $payload): ?array
    {
        $overview = $payload['commercial_turn_policy']['plans_overview'] ?? null;
        if (! is_array($overview) || ! array_key_exists('render_contract', $overview)) {
            return null;
        }
        $contract = $overview['render_contract'];
        $keys = self::FIELDS;
        $actualKeys = is_array($contract) ? array_keys($contract) : [];
        sort($actualKeys);
        $expectedKeys = $keys;
        sort($expectedKeys);
        $plans = $overview['plans'] ?? null;
        $ids = [];
        if (is_array($plans) && array_is_list($plans)) {
            foreach ($plans as $plan) {
                $id = is_array($plan) ? ($plan['plan_id'] ?? null) : null;
                if (! is_int($id) || $id <= 0 || $id > 9007199254740991) {
                    throw new InvalidArgumentException('Invalid list-turn rendering contract.');
                }
                $ids[] = $id;
            }
        }
        if ($actualKeys !== $expectedKeys || UltronPlansOverview::signedList($ids) === null) {
            throw new InvalidArgumentException('Invalid list-turn rendering contract.');
        }
        foreach (['final_limit', 'list_chars', 'prose_budget', 'slot_chars', 'raw_draft_limit', 'separator_chars',
            'app_links_reserved_chars', 'prose_budget_with_app_links', 'app_links_slot_chars', 'raw_draft_limit_with_app_links'] as $key) {
            if (! is_int($contract[$key])) {
                throw new InvalidArgumentException('Invalid list-turn rendering contract.');
            }
        }
        $slot = '{{'.UltronPlansOverview::SLOT.'}}';
        if ($contract['contract'] !== self::CONTRACT || $contract['slot'] !== $slot || $contract['placement'] !== 'explicit_slot'
            || $contract['final_limit'] < 320 || $contract['final_limit'] > UltronReplyFitter::MAX_CAP
            || $contract['list_chars'] <= 0 || $contract['list_chars'] > $contract['final_limit']
            || $contract['prose_budget'] !== $contract['final_limit'] - $contract['list_chars']
            || $contract['slot_chars'] !== UltronReplyFitter::measure($slot)
            || $contract['raw_draft_limit'] !== $contract['prose_budget'] + $contract['slot_chars']
            || $contract['separator_chars'] !== 0
            || $contract['app_links_reserved_chars'] !== self::appLinksReservedChars()
            || ! is_bool($contract['app_links_fits'])
            || $contract['app_links_fits'] !== ($contract['prose_budget'] >= $contract['app_links_reserved_chars'])
            || $contract['prose_budget_with_app_links'] !== max(0, $contract['prose_budget'] - $contract['app_links_reserved_chars'])
            || $contract['app_links_slot'] !== '{{'.UltronDraftPlaceholders::APP_LINKS.'}}'
            || $contract['app_links_slot_chars'] !== UltronReplyFitter::measure($contract['app_links_slot'])
            || $contract['raw_draft_limit_with_app_links'] !== $contract['prose_budget_with_app_links'] + $contract['slot_chars'] + $contract['app_links_slot_chars']) {
            throw new InvalidArgumentException('Invalid list-turn rendering contract.');
        }

        return array_replace(array_fill_keys($keys, null), $contract);
    }

    /**
     * No database reads: the caller passes the current sellable catalog.
     * The list itself stays on the server; only its measured size travels.
     *
     * Every character outside the exact slot belongs to the prose budget,
     * including spaces, punctuation and any question. Explicit-slot rendering
     * adds no wrapper or separator, so there is no hidden insertion overhead.
     *
     * @param  array<string,mixed>  $sp
     * @param  list<int>  $po
     * @param  array<int,array<string,mixed>>  $activePlans
     * @param  array<int,mixed>  $tools
     * @return array<string,int|string|bool>|null
     */
    public static function build(array $sp, array $po, array $activePlans, string $intent, array $tools = []): ?array
    {
        $compiler = $sp['k'] ?? null;
        if (($sp['m'] ?? null) !== UltronPromptStamp::MODE_STUDIO || ($sp['o'] ?? null) !== 1
            || ! is_int($compiler) || $compiler < self::SINCE_COMPILER || $po === []
            || UltronPlansOverview::isMoneyTurn($intent, $tools)) {
            return null;
        }
        if (UltronPlansOverview::signedList($po) === null) {
            throw new InvalidArgumentException('Invalid list-turn catalog selection.');
        }

        $cap = UltronReplyFitter::plansListCap($sp, $po);
        if ($cap === null) {
            throw new InvalidArgumentException('Missing list-turn character limit.');
        }
        $texts = [];
        foreach ($activePlans as $entry) {
            $id = $entry['id'] ?? null;
            if (! is_int($id) || ! in_array($id, $po, true)) {
                continue;
            }
            if (! is_string($entry['name'] ?? null) || trim($entry['name']) === ''
                || ! is_numeric($entry['price'] ?? null) || ! is_finite((float) $entry['price']) || (float) $entry['price'] <= 0
                || ! is_int($entry['duration_days'] ?? null) || $entry['duration_days'] <= 0
                || array_key_exists($id, $texts)) {
                throw new InvalidArgumentException('Invalid list-turn catalog data.');
            }
            $plan = (new Plan)->forceFill([
                'name' => $entry['name'], 'price' => $entry['price'], 'duration_days' => $entry['duration_days'],
            ]);
            $texts[$id] = UltronPlansOverview::item($plan);
        }
        $list = UltronPlansOverview::compose($po, $texts);
        if ($list === null) {
            throw new InvalidArgumentException('List-turn catalog cannot be rendered safely.');
        }
        $listChars = UltronReplyFitter::measure($list);
        if ($listChars > $cap) {
            throw new InvalidArgumentException('List-turn catalog exceeds the character limit.');
        }
        $slot = '{{'.UltronPlansOverview::SLOT.'}}';
        $slotChars = UltronReplyFitter::measure($slot);
        $appSlot = '{{'.UltronDraftPlaceholders::APP_LINKS.'}}';
        $appSlotChars = UltronReplyFitter::measure($appSlot);
        $appReserved = self::appLinksReservedChars();
        $appProseBudget = max(0, $cap - $listChars - $appReserved);

        return [
            'contract' => self::CONTRACT,
            'slot' => $slot,
            'placement' => 'explicit_slot',
            'final_limit' => $cap,
            'list_chars' => $listChars,
            'prose_budget' => $cap - $listChars,
            'slot_chars' => $slotChars,
            'raw_draft_limit' => $cap - $listChars + $slotChars,
            'separator_chars' => 0,
            'app_links_reserved_chars' => $appReserved,
            'app_links_fits' => $cap - $listChars >= $appReserved,
            'prose_budget_with_app_links' => $appProseBudget,
            'app_links_slot' => $appSlot,
            'app_links_slot_chars' => $appSlotChars,
            'raw_draft_limit_with_app_links' => $appProseBudget + $slotChars + $appSlotChars,
        ];
    }

    /**
     * Optional APP_LINKS uses the larger server block: either links plus the
     * two appended newlines, or the already-sent replacement. URLs are exempt
     * from the existing measure, but labels and separators still count.
     */
    public static function appLinksReservedChars(): int
    {
        return max(UltronReplyFitter::measure(MobileAppCatalog::linksInline()) + 2, UltronReplyFitter::measure(MobileAppCatalog::LINKS_ALREADY_SENT));
    }

    /** The generated n8n adapter uses the same fields and numeric constants. */
    public static function javascript(): string
    {
        $javascript = <<<'JS'
(function fromPayload(payload) {
  const overview = payload?.commercial_turn_policy?.plans_overview;
  if (!overview || typeof overview !== 'object' || !Object.prototype.hasOwnProperty.call(overview, 'render_contract')) return null;
  const invalid = () => { throw new Error('Invalid list-turn rendering contract.'); };
  const c = overview.render_contract;
  const fields = __FIELDS__;
  if (!c || typeof c !== 'object' || Array.isArray(c) || JSON.stringify(Object.keys(c).sort()) !== JSON.stringify([...fields].sort())) return invalid();
  const plans = overview.plans;
  if (!Array.isArray(plans) || plans.length < 2 || plans.length > 3 || plans.some(p => !p || !Number.isSafeInteger(p.plan_id) || p.plan_id <= 0) || new Set(plans.map(p => p.plan_id)).size !== plans.length) return invalid();
  for (const key of ['final_limit', 'list_chars', 'prose_budget', 'slot_chars', 'raw_draft_limit', 'separator_chars', 'app_links_reserved_chars', 'prose_budget_with_app_links', 'app_links_slot_chars', 'raw_draft_limit_with_app_links']) {
    if (!Number.isSafeInteger(c[key])) return invalid();
  }
  if (c.contract !== __CONTRACT__ || c.slot !== '{{LISTA_DE_PLANES}}' || c.placement !== 'explicit_slot'
      || c.final_limit < 320 || c.final_limit > __MAX_CAP__ || c.list_chars <= 0 || c.list_chars > c.final_limit
      || c.prose_budget !== c.final_limit - c.list_chars || c.slot_chars !== 19
      || c.raw_draft_limit !== c.prose_budget + c.slot_chars || c.separator_chars !== 0
      || c.app_links_reserved_chars !== __APP_RESERVED__
      || typeof c.app_links_fits !== 'boolean' || c.app_links_fits !== (c.prose_budget >= c.app_links_reserved_chars)
      || c.prose_budget_with_app_links !== Math.max(0, c.prose_budget - c.app_links_reserved_chars)
      || c.app_links_slot !== '{{APP_LINKS}}' || c.app_links_slot_chars !== 13
      || c.raw_draft_limit_with_app_links !== c.prose_budget_with_app_links + c.slot_chars + c.app_links_slot_chars) return invalid();
  return Object.fromEntries(fields.map(key => [key, c[key]]));
})
JS;

        return strtr($javascript, [
            '__FIELDS__' => json_encode(self::FIELDS, JSON_THROW_ON_ERROR),
            '__CONTRACT__' => json_encode(self::CONTRACT, JSON_THROW_ON_ERROR),
            '__MAX_CAP__' => (string) UltronReplyFitter::MAX_CAP,
            '__APP_RESERVED__' => (string) self::appLinksReservedChars(),
        ]);
    }
}
