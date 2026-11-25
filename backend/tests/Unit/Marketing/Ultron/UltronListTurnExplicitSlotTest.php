<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Models\Plan;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\UltronDraftDefects;
use App\Services\Marketing\Ultron\UltronPlansOverview as Overview;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as Notes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UltronListTurnExplicitSlotTest extends TestCase
{
    private const CATALOG = [
        ['id' => 3, 'name' => 'Plan Valera', 'price' => 65000, 'duration_days' => 30],
        ['id' => 4, 'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30],
        ['id' => 8, 'name' => 'Élite', 'price' => 180000, 'duration_days' => 30],
    ];

    public static function drafts(): array
    {
        return [
            'no slot' => ['Le comparto nuestros planes. ¿Cuál le interesa?', null, Overview::MISSING, false],
            'spaces inside slot' => ['Planes: {{ LISTA_DE_PLANES }}.', null, Overview::MISSING, false],
            'old markers' => ['Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}.', null, Overview::MISSING, false],
            'exact slot' => ['Planes: {{LISTA_DE_PLANES}}.', null, null, true],
            'slot repeated' => ['{{LISTA_DE_PLANES}} y {{LISTA_DE_PLANES}}.', Overview::MIXED, Overview::MIXED, false],
            'extra brace' => ['{{{LISTA_DE_PLANES}}}.', Overview::MIXED, Overview::MIXED, false],
            'slot with old marker' => ['{{LISTA_DE_PLANES}} y {{PLAN_1}}.', Overview::MIXED, Overview::MIXED, false],
            'app repeated' => ['{{LISTA_DE_PLANES}} {{APP_LINKS}} {{APP_LINKS}}', null, Overview::MIXED, true],
            'app spaces' => ['{{LISTA_DE_PLANES}} {{ APP_LINKS }}', null, Overview::MIXED, true],
            'price guard retains precedence' => ['Cuesta $80.000.', Overview::PRICE_WRITTEN, Overview::PRICE_WRITTEN, false],
            'unknown guard retains precedence' => ['{{PLAN_9}}.', Overview::UNKNOWN_MARKER, Overview::UNKNOWN_MARKER, false],
        ];
    }

    #[DataProvider('drafts')]
    public function test_c9_requires_the_exact_slot_and_c8_keeps_its_original_contract(string $raw, ?string $detail8, ?string $detail9, bool $placed9): void
    {
        $texts = self::texts();
        $this->assertSame($detail8, Overview::verify($raw, [3, 4, 8], self::CATALOG, true));
        $this->assertSame($detail9, Overview::verify($raw, [3, 4, 8], self::CATALOG, true, null, true));
        $rendered = Overview::render($raw, [3, 4, 8], $texts, self::CATALOG, true, true);
        $this->assertSame($placed9, $rendered['placed']);
        if (! $placed9) {
            $this->assertSame($raw, $rendered['text'], 'an invalid first draft is never repaired by inserting or normalizing its slot');
        }
        if ($detail8 === null && $detail9 === Overview::MISSING) {
            $this->assertTrue(Overview::render($raw, [3, 4, 8], $texts, self::CATALOG, true)['placed'], 'c8 remains unchanged');
        }
    }

    public function test_explicit_slot_mode_is_confined_to_studio_compiler_9(): void
    {
        foreach ([[], ['m' => 'legacy', 'k' => 9], ['m' => 'studio', 'k' => 8], ['m' => 'studio', 'k' => '9']] as $sp) {
            $this->assertFalse(Overview::requiresExplicitSlot($sp));
        }
        $this->assertTrue(Overview::requiresExplicitSlot(['m' => 'studio', 'k' => 9]));
    }

    public function test_every_c9_list_note_repeats_the_slot_and_exact_budgets_without_losing_length(): void
    {
        $budget = ['X' => 75, 'N' => 320, 'R' => 245];
        $length = Notes::explicitListNote(Notes::LENGTH_OVER_CAP, null, ['L' => 524] + $budget);
        foreach (Overview::DETAILS as $detail) {
            $note = Notes::explicitListNote(Notes::PLANS_OVERVIEW, $detail, $budget);
            $merged = Notes::merge([$note, $length]);
            $this->assertStringContainsString('{{LISTA_DE_PLANES}} exactamente una vez', $merged);
            $this->assertStringContainsString('Lista entera 75; limite 320; prosa maximo 245, o 199 con {{APP_LINKS}}', $merged);
            $this->assertStringContainsString('DEMASIADO LARGO: mide 524; limite 320.', $merged);
            $this->assertLessThanOrEqual(500, mb_strlen($merged));
        }
    }

    public function test_a_name_defect_gets_the_complete_c9_contract_and_the_original_c8_note(): void
    {
        $raw = 'El Plan Mensual está en esta lista: {{LISTA_DE_PLANES}}.';
        $texts = self::texts();
        $expanded = Overview::render($raw, [3, 4, 8], $texts, self::CATALOG, true, true)['text'];
        $draft = ['reply_draft' => $raw, 'intent' => SalesIntents::PRICING_QUESTION];
        $sp = ['m' => 'studio', 'o' => 1, 'n' => 200, 'k' => 9];
        $new = UltronDraftDefects::of($draft, $expanded, $sp, [3, 4, 8], self::CATALOG, '¿Qué planes tienen?', $texts);
        $old = UltronDraftDefects::of($draft, $expanded, array_replace($sp, ['k' => 8]), [3, 4, 8], self::CATALOG, '¿Qué planes tienen?', $texts);
        $this->assertSame('name_written', $new[0]['detail']);
        $this->assertStringContainsString('{{LISTA_DE_PLANES}}', $new[0]['note']);
        $this->assertStringContainsString('prosa maximo 245', $new[0]['note']);
        $this->assertSame(Notes::serverNote(Notes::PLANS_OVERVIEW, Overview::NAME_WRITTEN), $old[0]['note']);
    }

    private static function texts(): array
    {
        $texts = [];
        foreach (self::CATALOG as $plan) {
            $texts[$plan['id']] = Overview::item((new Plan)->forceFill($plan));
        }

        return $texts;
    }
}
