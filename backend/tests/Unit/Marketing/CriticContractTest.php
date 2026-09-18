<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\Ultron\CriticContract;
use Tests\TestCase;

/** El contrato del Critic, sin base de datos: qué cuenta como veredicto y qué cuenta como fallo. */
class CriticContractTest extends TestCase
{
    public function test_a_block_with_every_key_null_is_not_a_verdict(): void
    {
        $this->assertFalse(CriticContract::judged([]));
        $this->assertFalse(CriticContract::judged(['verdict' => null, 'attempt' => null, 'score' => null, 'notes' => null, 'issues' => null, 'hard_fail' => null]), 'n8n emite todas las claves; todas en null es «nadie juzgó»');
        $this->assertTrue(CriticContract::judged(['verdict' => 'pass']));
    }

    public function test_an_unknown_hard_fail_closes_instead_of_opening(): void
    {
        $this->assertTrue(CriticContract::isFail(['verdict' => 'pass', 'hard_fail' => 'too_boring']), 'un código que no entendemos no puede autorizar el envío');
        $this->assertNull(CriticContract::hardFailOf(['hard_fail' => 'too_boring']), 'pero no se audita basura');
        $this->assertFalse(CriticContract::isFail(['verdict' => 'pass', 'hard_fail' => null]));
        $this->assertFalse(CriticContract::isFail(['verdict' => 'pass', 'hard_fail' => '']));
    }

    public function test_there_is_only_one_retry_so_attempt_never_exceeds_two(): void
    {
        $this->assertSame(2, CriticContract::forMetadata(['attempt' => 7])['attempt']);
        $this->assertSame(1, CriticContract::forMetadata(['attempt' => 0])['attempt']);
    }
}
