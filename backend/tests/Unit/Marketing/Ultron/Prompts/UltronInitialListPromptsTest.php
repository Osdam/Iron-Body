<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronListTurnPrompts as Prompts;
use App\Services\Marketing\Ultron\Prompts\UltronPromptBase as Base;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as Compiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronListTurnContract;
use App\Services\UltronStudio\StudioLabEngineFlow as Flow;
use App\Services\UltronStudio\StudioLabEngineTransport as Transport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UltronInitialListPromptsTest extends TestCase
{
    private static function package(): array
    {
        return Compiler::compile(array_replace(Compiler::BASELINE, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]));
    }

    private static function context(): array
    {
        $plans = [
            ['id' => 3, 'name' => 'Plan Valera', 'price' => 65000, 'duration_days' => 30],
            ['id' => 4, 'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30],
            ['id' => 8, 'name' => 'Élite', 'price' => 180000, 'duration_days' => 30],
        ];
        $contract = UltronListTurnContract::build(['m' => 'studio', 'k' => 9, 'o' => 1, 'n' => 200], [3, 4, 8], $plans, 'pricing_question');

        return ['commercial_turn_policy' => ['plans_overview' => [
            'plans' => array_map(static fn ($p) => ['plan_id' => $p['id'], 'name' => $p['name']], $plans),
            'price_asked' => true, 'render_contract' => $contract,
        ]]];
    }

    public function test_signed_variants_preserve_the_individual_quote_prompts_and_legacy(): void
    {
        $package = self::package();
        $general = [];
        foreach ($package['roles'] as $role => $envelope) {
            $general[$role] = Prompts::select($role, $envelope, false);
            $this->assertFalse(Prompts::isSpecialized($general[$role]));
            $this->assertNotSame($envelope, $general[$role]);
        }
        // Frozen compiler-8 c074, independently recorded before this change.
        $this->assertSame('8200e50e3da7f6fe7cfb0e21d1d93705d8eb824fb5e1cb7887ee3c2e557d3caa', Base::bundleHash($general));
        $this->assertSame(9, $package['compiler']);
        $this->assertNotSame(Base::bundleHash($general), $package['bundleHash']);
        $this->assertSame(Base::roles(), Compiler::compile(Compiler::BASELINE)['roles']);
        $this->assertSame(Base::LEGACY_BUNDLE_HASH, Compiler::compile(Compiler::BASELINE)['bundleHash']);
    }

    public function test_exclusive_list_instructions_contain_no_individual_quote_obligation(): void
    {
        foreach (self::package()['roles'] as $role => $envelope) {
            $list = Prompts::select($role, $envelope, true);
            $this->assertTrue(Prompts::isSpecialized($list));
            foreach (['PLAN_PRICE', 'PLAN_NAME', 'PLAN_DURATION', 'Elige el plan que mejor encaje', 'tal cual lo leera la persona', 'Nunca vuelques el catalogo'] as $old) {
                $this->assertStringNotContainsString($old, $list, $role);
            }
            $this->assertStringContainsString('usted', $list);
            if (in_array($role, ['composer', 'composer_retry'], true)) {
                $this->assertStringContainsString('{{LISTA_DE_PLANES}}', $list);
                $this->assertStringContainsString('prose_budget', $list);
                $this->assertStringContainsString('final_limit', $list);
                $this->assertStringContainsString('encabezado, espacios, signos', $list);
            }
        }
    }

    public function test_first_composer_payload_contains_the_contract_before_any_rejected_draft(): void
    {
        $context = self::context();
        $decide = ['context' => $context, 'commercial_phase' => 'NEW_LEAD'];
        $payload = Flow::userPayload(Flow::COMPOSER, $decide, '¿Qué planes tienen y cuánto cuesta la mensualidad?', [Flow::VALIDATE => ['strategy' => ['tools_requested' => []]]], []);
        $contract = UltronListTurnContract::fromPayload($payload);
        $this->assertSame(75, $contract['list_chars']);
        $this->assertSame(320, $contract['final_limit']);
        $this->assertSame(245, $contract['prose_budget']);
        $this->assertSame(264, $contract['raw_draft_limit']);
        $this->assertArrayNotHasKey('rejected_draft', $payload);
        $this->assertArrayNotHasKey('critic_notes', $payload);
        $instructions = Prompts::select('composer', self::package()['roles']['composer'], true);
        $body = Transport::body(Flow::COMPOSER, $instructions, Flow::encode($payload));
        $this->assertSame($instructions, $body['instructions']);
        $this->assertCount(1, $body['input']);
        $this->assertTrue($body['text']['format']['strict']);
        $this->assertSame(Transport::REPLY_PATTERN, $body['text']['format']['schema']['properties']['reply_draft']['pattern']);
    }

    public function test_critics_receive_the_same_contract_and_backend_result_instead_of_guessing(): void
    {
        $context = self::context();
        $payload = Flow::userPayload(Flow::CRITIC, ['context' => $context], '¿Qué planes tienen?', [
            Flow::READ_1 => ['ultron_check' => ['error' => false, 'codes' => [], 'may_rewrite' => false]],
        ], ['output' => [['content' => [['text' => ['reply_draft' => '{{LISTA_DE_PLANES}}', 'questions_asked' => 0, 'plans_mentioned' => 3]]]]]]);
        $this->assertSame(UltronListTurnContract::fromPayload($context), UltronListTurnContract::fromPayload($payload));
        $this->assertSame(['checked' => true, 'codes' => [], 'reason' => 'list_final'], $payload['backend_list_check']);
    }

    public function test_old_runtime_cannot_receive_compiler_nine_envelopes(): void
    {
        $offer = ['sp' => ['m' => 'studio', 'k' => 9], 'response' => ['mode' => 'studio']];
        $this->assertSame($offer, UltronPromptOffer::forRuntime($offer, UltronListTurnContract::CONTRACT));
        foreach ([null, '', 'ultron-list-turn/0'] as $runtime) {
            $closed = UltronPromptOffer::forRuntime($offer, $runtime);
            $this->assertSame('legacy', $closed['sp']['m']);
            $this->assertSame('generation_contract_missing', $closed['response']['reason']);
            $this->assertNull($closed['response']['roles']);
        }
        $old = ['sp' => ['m' => 'studio', 'k' => 8], 'response' => ['mode' => 'studio']];
        $this->assertSame($old, UltronPromptOffer::forRuntime($old, null));
    }

    public function test_missing_specialized_package_fails_closed_instead_of_sending_the_generic_prompt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Prompts::select('composer', Base::roles()['composer'], true);
    }

    public function test_all_historical_governed_combinations_preserve_general_behavior_and_sign_the_new_route(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 4).'/fixtures/ultron-prompts/compile-cases.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(8, $fixture['compiler'], 'Historical evidence must stay frozen.');
        foreach ($fixture['cases'] as $case) {
            $old = Compiler::compile($case['governed'], 8);
            $this->assertSame($case['expect']['bundleHash'], $old['bundleHash'], $case['id']);
            $new = Compiler::compile($case['governed']);
            $this->assertSame(9, $new['compiler']);
            $general = [];
            foreach ($new['roles'] as $role => $envelope) {
                $general[$role] = Prompts::select($role, $envelope, false);
                $this->assertSame($old['roles'][$role], $general[$role], $case['id'].'/'.$role);
                $this->assertLessThanOrEqual(Compiler::MAX_ROLE_BYTES, strlen($envelope));
                if ($new['patches'] !== []) {
                    $this->assertTrue(Prompts::isSpecialized(Prompts::select($role, $envelope, true)));
                    $changed = $new['roles'];
                    $decoded = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);
                    $decoded['plans_list'] .= ' Changed instruction.';
                    $changed[$role] = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $this->assertNotSame($new['bundleHash'], Base::bundleHash($changed), 'Every specialized instruction must be signed.');
                }
            }
            $this->assertSame($old['bundleHash'], Base::bundleHash($general));
            $this->assertLessThanOrEqual(Compiler::MAX_BUNDLE_BYTES, $new['bytes']);
        }
    }
}
