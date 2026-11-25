<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\Ultron\UltronListCriticGate as Gate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class UltronListCriticGateTest extends TestCase
{
    public function test_only_a_verified_clean_first_list_can_ignore_the_existing_soft_fail_policy(): void
    {
        $soft = ['verdict' => 'fail', 'hard_fail' => null, 'issues' => ['naturalness', 'progressive_disclosure']];
        $clean = ['statusCode' => 200, 'body' => [
            'ok' => true, 'contract' => 'ultron-regen/1', 'mode' => 'studio', 'attempt' => 1,
            'pass' => true, 'defects' => [], 'budget' => ['reason' => 'list_final'],
        ]];
        $cases = [
            [$soft, true, $clean, true],
            [$soft, false, $clean, false],
            [array_replace($soft, ['hard_fail' => 'invented_class']), true, $clean, false],
            [array_replace($soft, ['hard_fail' => 'progressive_disclosure']), true, $clean, false],
            [array_replace($soft, ['hard_fail' => 'unknown']), true, $clean, false],
            [array_replace($soft, ['issues' => ['factuality']]), true, $clean, false],
            [array_replace($soft, ['issues' => ['pressure_control']]), true, $clean, false],
            [array_replace($soft, ['issues' => ['payment_safety']]), true, $clean, false],
            [array_replace($soft, ['issues' => array_fill(0, 8, 'naturalness')]), true, $clean, false],
            [$soft, true, null, false],
            [$soft, true, ['statusCode' => 500, 'body' => $clean['body']], false],
            [$soft, true, ['statusCode' => 200, 'body' => array_replace($clean['body'], ['pass' => false])], false],
            [$soft, true, ['statusCode' => 200, 'body' => array_replace($clean['body'], ['defects' => [['code' => 'plans_overview']]])], false],
            [$soft, true, ['statusCode' => 200, 'body' => array_replace($clean['body'], ['budget' => ['reason' => 'time_budget']])], false],
            [$soft, true, ['statusCode' => 200, 'body' => json_encode($clean['body'])], true],
            [['verdict' => 'pass', 'hard_fail' => null, 'issues' => []], false, null, true],
        ];
        foreach ($cases as [$critic, $list, $check, $expected]) {
            $this->assertSame($expected, Gate::allows($critic, $list, $check));
        }
        $script = 'const allows = '.Gate::javascript().'; process.stdout.write(JSON.stringify('.json_encode($cases, JSON_THROW_ON_ERROR).'.map(c=>allows(c[0],c[1],c[2]))));';
        $process = new Process(['node', '-e', $script]);
        $process->mustRun();
        $this->assertSame(array_column($cases, 3), json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }
}
