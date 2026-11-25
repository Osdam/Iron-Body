<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\UltronDraftDefects;
use App\Services\Marketing\Ultron\UltronDraftPlaceholders;
use App\Services\Marketing\Ultron\UltronListTurnContract as Contract;
use App\Services\Marketing\Ultron\UltronPlansOverview as Overview;
use App\Services\Marketing\Ultron\UltronRegenerationNotes;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class UltronListTurnContractTest extends TestCase
{
    private const SP = ['m' => 'studio', 'o' => 1, 'k' => 9, 'n' => 200];

    private const CATALOG = [
        ['id' => 3, 'name' => 'Plan Valera', 'price' => 65000, 'duration_days' => 30],
        ['id' => 4, 'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30],
        ['id' => 8, 'name' => 'Élite', 'price' => 180000, 'duration_days' => 30],
    ];

    public function test_the_first_payload_reserves_the_exact_list_without_exposing_prices(): void
    {
        $contract = Contract::build(self::SP, [3, 4, 8], self::CATALOG, SalesIntents::PRICING_QUESTION);

        $this->assertSame([
            'contract' => 'ultron-list-turn/1', 'slot' => '{{LISTA_DE_PLANES}}', 'placement' => 'explicit_slot',
            'final_limit' => 320, 'list_chars' => 75, 'prose_budget' => 245, 'slot_chars' => 19,
            'raw_draft_limit' => 264, 'separator_chars' => 0,
            'app_links_reserved_chars' => 46, 'app_links_fits' => true, 'prose_budget_with_app_links' => 199,
            'app_links_slot' => '{{APP_LINKS}}', 'app_links_slot_chars' => 13, 'raw_draft_limit_with_app_links' => 231,
        ], $contract);
        $payload = self::payload($contract);
        $this->assertSame($contract, Contract::fromPayload($payload));
        $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['price', '$', '65.000', '65000', '80.000', '80000', '180.000', '180000'] as $secretPrice) {
            $this->assertStringNotContainsString($secretPrice, $serialized);
        }
    }

    public static function renderingCases(): array
    {
        $unicode = self::CATALOG;
        $unicode[0]['name'] = 'Plan Ágil 🏋️';
        $unicode[1]['name'] = 'Plan {mañana}';
        $unicode[1]['duration_days'] = 7;

        return [
            'three monthly plans' => [self::CATALOG, [3, 4, 8], 200],
            'two plans reversed' => [self::CATALOG, [8, 3], 200],
            'unicode braces and weekly duration' => [$unicode, [4, 3, 8], 200],
            'published cap above list minimum' => [self::CATALOG, [3, 4, 8], 480],
        ];
    }

    #[DataProvider('renderingCases')]
    public function test_budget_matches_the_actual_renderer_at_the_boundary(array $catalog, array $po, int $publishedCap): void
    {
        $contract = Contract::build(array_replace(self::SP, ['n' => $publishedCap]), $po, $catalog, SalesIntents::PRICING_QUESTION);
        $texts = [];
        foreach ($catalog as $entry) {
            $texts[$entry['id']] = Overview::item((new Plan)->forceFill($entry));
        }
        foreach ([0, 1] as $excess) {
            // Space and punctuation around the slot consume the same budget
            // as prose: the renderer adds no punctuation of its own.
            $outside = 'Á. '.str_repeat('ñ', $contract['prose_budget'] - 6 + $excess).' ¿?';
            $raw = 'Á. '.$contract['slot'].substr($outside, strlen('Á. '));
            $rendered = Overview::render($raw, $po, $texts, $catalog, true);
            $this->assertTrue($rendered['placed']);
            $this->assertSame($contract['raw_draft_limit'] + $excess, UltronReplyFitter::measure($raw));
            $this->assertSame($contract['final_limit'] + $excess, UltronReplyFitter::measure($rendered['text']));
            $this->assertSame($contract['prose_budget'] + $excess, UltronReplyFitter::measure(str_replace($contract['slot'], '', $raw)));
            $this->assertSame($excess === 0, UltronReplyFitter::fits($rendered['text'], $contract['final_limit']));
        }
    }

    public function test_old_compilers_no_list_and_money_turns_keep_the_old_route(): void
    {
        foreach ([[], ['m' => 'legacy', 'o' => 1, 'k' => 9], array_replace(self::SP, ['k' => 8]), array_replace(self::SP, ['o' => 0])] as $sp) {
            $this->assertNull(Contract::build($sp, [3, 4, 8], self::CATALOG, SalesIntents::PRICING_QUESTION));
        }
        $this->assertNull(Contract::build(self::SP, [], self::CATALOG, SalesIntents::PRICING_QUESTION));
        $this->assertNull(Contract::build(self::SP, [3, 4, 8], self::CATALOG, SalesIntents::PAYMENT_LINK_REQUEST));
        $this->assertNull(Contract::build(self::SP, [3, 4, 8], self::CATALOG, SalesIntents::PRICING_QUESTION, [SalesIntents::TOOL_PAYMENT_LINK_SEND]));
        $this->assertNull(Contract::fromPayload([]));
        $this->assertNull(Contract::fromPayload(['commercial_turn_policy' => ['plans_overview' => ['plans' => []]]]));
    }

    public function test_optional_app_blocks_reserve_the_larger_backend_replacement(): void
    {
        $contract = Contract::build(self::SP, [3, 4, 8], self::CATALOG, SalesIntents::PRICING_QUESTION);
        $texts = [];
        foreach (self::CATALOG as $entry) {
            $texts[$entry['id']] = Overview::item((new Plan)->forceFill($entry));
        }
        $placeholders = new UltronDraftPlaceholders;
        foreach ([0, 1] as $excess) {
            $prose = str_repeat('a', $contract['prose_budget_with_app_links'] + $excess);
            $raw = $prose.$contract['slot'].$contract['app_links_slot'];
            $rendered = Overview::render($raw, [3, 4, 8], $texts, self::CATALOG, true, true)['text'];
            $repeated = $placeholders->resolveAppLinks($rendered, MobileAppCatalog::LINKS_ALREADY_SENT);
            $this->assertSame($contract['raw_draft_limit_with_app_links'] + $excess, UltronReplyFitter::measure($raw));
            $this->assertSame(320 + $excess, UltronReplyFitter::measure($repeated));
            $defects = UltronDraftDefects::of(['reply_draft' => $raw, 'intent' => SalesIntents::PRICING_QUESTION], $repeated, self::SP, [3, 4, 8], self::CATALOG, '¿Qué planes tienen?', $texts);
            $this->assertSame($excess === 0 ? [] : ['length_over_cap'], array_column($defects, 'code'));
            $inline = $placeholders->resolveAppLinks($rendered, MobileAppCatalog::linksInline());
            $this->assertLessThanOrEqual(320 + $excess, UltronReplyFitter::measure($inline));
            $appended = $prose.Overview::compose([3, 4, 8], $texts)."\n\n".MobileAppCatalog::linksInline();
            $this->assertLessThanOrEqual(320 + $excess, UltronReplyFitter::measure($appended));
        }
    }

    public function test_a_zero_optional_budget_does_not_claim_an_impossible_app_block_will_fit(): void
    {
        $catalog = self::CATALOG;
        $catalog[0]['name'] = str_repeat('a', 236);
        $contract = Contract::build(self::SP, [3, 4, 8], $catalog, SalesIntents::PRICING_QUESTION);
        $this->assertSame(300, $contract['list_chars']);
        $this->assertSame(20, $contract['prose_budget']);
        $this->assertFalse($contract['app_links_fits']);
        $this->assertSame(0, $contract['prose_budget_with_app_links']);
        $this->assertGreaterThan($contract['final_limit'], $contract['list_chars'] + $contract['app_links_reserved_chars']);
        $this->assertSame($contract, Contract::fromPayload(self::payload($contract)));
        $note = UltronRegenerationNotes::explicitListNote('plans_overview', 'missing', ['X' => 300, 'N' => 320, 'R' => 20]);
        $this->assertStringContainsString('APP_LINKS no cabe', $note);
        $this->assertStringNotContainsString('o 0 con', $note);
        $script = 'const read = '.Contract::javascript().'; const payload = JSON.parse(require("node:fs").readFileSync(0,"utf8")); process.stdout.write(JSON.stringify(read(payload)));';
        $node = new Process(['node', '-e', $script]);
        $node->setInput(json_encode(self::payload($contract), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $node->mustRun();
        $this->assertSame($contract, json_decode($node->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    public static function invalidCatalogs(): array
    {
        $unsafe = self::CATALOG;
        $unsafe[0]['name'] = "Un nombre\ncon salto";
        $long = self::CATALOG;
        $long[0]['name'] = str_repeat('ñ', 400);
        $missingPrice = self::CATALOG;
        unset($missingPrice[0]['price']);

        return [
            'missing plan' => [[3, 4, 8], array_slice(self::CATALOG, 1), self::SP],
            'unsafe name' => [[3, 4, 8], $unsafe, self::SP],
            'list itself exceeds cap' => [[3, 4, 8], $long, self::SP],
            'missing price' => [[3, 4, 8], $missingPrice, self::SP],
            'invalid selection' => [[3, 3], self::CATALOG, self::SP],
            'invalid cap' => [[3, 4, 8], self::CATALOG, array_replace(self::SP, ['n' => '200'])],
        ];
    }

    #[DataProvider('invalidCatalogs')]
    public function test_an_expected_but_unusable_list_fails_closed(array $po, array $catalog, array $sp): void
    {
        $this->expectException(InvalidArgumentException::class);
        Contract::build($sp, $po, $catalog, SalesIntents::PRICING_QUESTION);
    }

    public static function malformedContracts(): array
    {
        return [
            'unknown contract' => ['contract', 'unknown'],
            'inexact slot' => ['slot', '{{ LISTA_DE_PLANES }}'],
            'implicit placement' => ['placement', 'implicit'],
            'wrong final limit type' => ['final_limit', '320'],
            'limit below accepted minimum' => ['final_limit', 200],
            'wrong list chars type' => ['list_chars', 75.0],
            'inconsistent prose budget' => ['prose_budget', 246],
            'wrong slot length' => ['slot_chars', 18],
            'inconsistent raw limit' => ['raw_draft_limit', 265],
            'unreserved separators' => ['separator_chars', 1],
            'unexpected field' => ['unexpected', 'ignored'],
            'wrong app reserve' => ['app_links_reserved_chars', 0],
            'wrong app feasibility' => ['app_links_fits', false],
            'wrong app feasibility type' => ['app_links_fits', 1],
            'wrong app prose budget' => ['prose_budget_with_app_links', 245],
            'wrong app slot' => ['app_links_slot', '{{ APP_LINKS }}'],
            'wrong app slot size' => ['app_links_slot_chars', 14],
            'wrong app raw size' => ['raw_draft_limit_with_app_links', 232],
        ];
    }

    #[DataProvider('malformedContracts')]
    public function test_present_contracts_are_validated_before_transport(string $key, mixed $value): void
    {
        $contract = Contract::build(self::SP, [3, 4, 8], self::CATALOG, SalesIntents::PRICING_QUESTION);
        $contract[$key] = $value;
        $this->expectException(InvalidArgumentException::class);
        Contract::fromPayload(self::payload($contract));
    }

    public function test_the_contract_requires_the_unique_ordered_list_in_the_payload(): void
    {
        $contract = Contract::build(self::SP, [3, 4, 8], self::CATALOG, SalesIntents::PRICING_QUESTION);
        $payload = self::payload($contract);
        $payload['commercial_turn_policy']['plans_overview']['plans'][1]['plan_id'] = 3;
        $this->expectException(InvalidArgumentException::class);
        Contract::fromPayload($payload);
    }

    public function test_the_generated_javascript_and_php_validate_the_same_payloads(): void
    {
        $contract = Contract::build(self::SP, [3, 4, 8], self::CATALOG, SalesIntents::PRICING_QUESTION);
        $samples = [[], self::payload($contract), self::payload(array_reverse($contract, true))];
        foreach (self::malformedContracts() as [$key, $value]) {
            // JSON has one numeric type; fractional notation of an integer is
            // PHP-specific after decoding and is checked by the PHP test.
            if (is_float($value)) {
                continue;
            }
            $bad = $contract;
            $bad[$key] = $value;
            $samples[] = self::payload($bad);
        }
        $duplicate = self::payload($contract);
        $duplicate['commercial_turn_policy']['plans_overview']['plans'][1]['plan_id'] = 3;
        $samples[] = $duplicate;
        $expected = array_map(static function (array $payload): array {
            try {
                return ['contract' => Contract::fromPayload($payload)];
            } catch (InvalidArgumentException) {
                return ['invalid' => true];
            }
        }, $samples);
        $script = 'const read = '.Contract::javascript().'; const samples = JSON.parse(require("node:fs").readFileSync(0, "utf8")); process.stdout.write(JSON.stringify(samples.map(payload => { try { return {contract: read(payload)}; } catch { return {invalid: true}; } })));';
        $node = new Process(['node', '-e', $script]);
        $node->setInput(json_encode($samples, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $node->mustRun();
        $this->assertSame($expected, json_decode($node->getOutput(), true, 512, JSON_THROW_ON_ERROR));
    }

    private static function payload(array $contract): array
    {
        return ['commercial_turn_policy' => ['plans_overview' => [
            'plans' => Overview::items([3, 4, 8], self::CATALOG, false),
            'render_contract' => $contract,
        ]]];
    }
}
