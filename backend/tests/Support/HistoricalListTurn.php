<?php

namespace Tests\Support;

use App\Models\MarketingMessage;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\UltronDecideToken;
use App\Services\UltronStudio\StudioPromptBundle;

/**
 * A compiler-8 turn already in flight when compiler 9 is deployed.
 *
 * Production does not offer an old publication under a new compiler. These
 * regression tests instead retain the real current decision and explicitly
 * issue its historical stamp with compile(..., 8), so check/commit continue
 * exercising their old-token compatibility. This is never a generation or
 * acceptance measurement, nor an assertion that today's decide offers c8.
 */
trait HistoricalListTurn
{
    protected function historicalListTurn(array $decide, MarketingMessage $message): array
    {
        if (($decide['studio_prompts']['mode'] ?? null) !== 'studio') {
            return $decide;
        }
        $verified = app(UltronDecideToken::class)->peek($decide['decide_token'], (int) $message->conversation_id, (int) $message->id);
        $this->assertTrue($verified['valid']);
        $extra = $verified['extra'];
        $stamp = $extra['sp'];
        $governed = array_replace(UltronPromptCompiler::BASELINE, [
            UltronPromptCompiler::TONE => $stamp['t'] ?? UltronPromptCompiler::BASELINE[UltronPromptCompiler::TONE],
            UltronPromptCompiler::MAX_CHARS => $stamp['n'] ?? UltronPromptCompiler::BASELINE[UltronPromptCompiler::MAX_CHARS],
        ]);
        $compiled = UltronPromptCompiler::compile($governed, 8);
        $this->assertSame(8, $compiled['compiler'], 'historical list regression uses a genuinely compiled c8 bundle');
        $extra['sp']['h'] = $compiled['bundleHash'];
        if (isset($extra['sp']['k'])) {
            $extra['sp']['k'] = 8;
        }
        $decide['studio_prompts']['compiler'] = 8;
        $decide['studio_prompts']['bundle_hash'] = $compiled['bundleHash'];
        $decide['studio_prompts']['roles'] = $compiled['roles'];
        unset($decide['context']['commercial_turn_policy']['plans_overview']['render_contract']);
        $decide['decide_token'] = app(UltronDecideToken::class)->issue((int) $message->conversation_id, (int) $message->id,
            $decide['commercial_phase'], $decide['allowed_transitions'], $decide['knowledge_version'], $extra)['token'];

        return $decide;
    }

    protected static function historicalListBundle(array $governed, ?string $version = null, ?string $contentHash = null): array
    {
        $compiled = UltronPromptCompiler::compile($governed, 8);
        $bundle = StudioPromptBundle::rolesFor($governed, $version, $contentHash);
        $bundle['roles'] = $compiled['roles'];
        $bundle['bundleHash'] = $compiled['bundleHash'];
        $bundle['sp']['h'] = $compiled['bundleHash'];
        if (isset($bundle['sp']['k'])) {
            $bundle['sp']['k'] = 8;
        }

        return $bundle;
    }
}
