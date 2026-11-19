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

    /**
     * «Regenerar antes de sustituir» (P-2): como mucho dos reescrituras, así que
     * el intento es 1, 2 o 3. Antes el tope era 2 (una sola reescritura); un
     * n8n S1 nunca manda 3, así que lo de siempre se guarda igual.
     */
    public function test_at_most_two_rewrites_so_attempt_never_exceeds_three(): void
    {
        $this->assertSame(3, CriticContract::MAX_ATTEMPTS);
        $this->assertSame(3, CriticContract::forMetadata(['attempt' => 7])['attempt']);
        $this->assertSame(3, CriticContract::forMetadata(['attempt' => 3])['attempt']);
        $this->assertSame(2, CriticContract::forMetadata(['attempt' => 2])['attempt']);
        $this->assertSame(1, CriticContract::forMetadata(['attempt' => 0])['attempt']);
        $this->assertSame(1, CriticContract::forMetadata([])['attempt']);
    }
}
