<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\Ultron\UltronRegenerationTrace as T;
use PHPUnit\Framework\TestCase;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · OLA 4 · LA TRAZA DE LAS REESCRITURAS (plan
 * del arquitecto `plan-arquitecto-a4-regenerar.md` §3 P-7, §4.3 y §6 «Ola 4»).
 *
 * El bloque `regeneration` que manda n8n en el commit (solo si `decide` lo
 * ofreció) pasa a `metadata.regeneration` con una forma cerrada: cuántos
 * borradores, revisores y comprobaciones hubo, quién pidió cada reescritura y
 * con qué códigos, los tokens si existen y `consistent`. Es TRAZA: nada del
 * commit se ramifica por ella; un dato que no cuadra solo se marca
 * (`consistent: false`). Sin bloque, null: la fila queda como hoy.
 *
 * Pura: la llama `persist()` con el bloque y el veredicto tal como queda en la
 * fila. Por HTTP el controlador ya rechaza la forma mala (422); esto es la
 * defensa en profundidad para quien llame sin pasar por él.
 */
class UltronRegenerationTraceTest extends TestCase
{
    private const BLOQUE = [
        'contract' => 'ultron-regen/1',
        'drafts' => 3,
        'critics' => 2,
        'checks' => 2,
        'check_errors' => 0,
        'rewrites' => [
            ['by' => 'server', 'codes' => ['register_tuteo', 'length_over_cap'], 'issues' => ['naturalness', 'progressive_disclosure']],
            ['by' => 'critic', 'codes' => [], 'issues' => ['relevance']],
        ],
        'tokens' => ['input' => 41000, 'output' => 900],
    ];

    public function test_sin_bloque_no_hay_traza(): void
    {
        $this->assertNull(T::forMetadata(null, ['attempt' => 1]));
        $this->assertNull(T::forMetadata('ultron-regen/1', ['attempt' => 1]));
        $this->assertNull(T::forMetadata([], null));
    }

    public function test_el_bloque_pasa_a_la_fila_con_su_forma_cerrada(): void
    {
        $this->assertSame([
            'drafts' => 3,
            'critics' => 2,
            'checks' => 2,
            'check_errors' => 0,
            'rewrites' => [
                ['by' => 'server', 'codes' => ['register_tuteo', 'length_over_cap'], 'issues' => ['naturalness', 'progressive_disclosure']],
                ['by' => 'critic', 'codes' => [], 'issues' => ['relevance']],
            ],
            'tokens' => ['input' => 41000, 'output' => 900],
            'consistent' => true,
        ], T::forMetadata(self::BLOQUE, ['verdict' => 'pass', 'attempt' => 3]));

        // Lo mínimo: el contrato y los borradores.
        $this->assertSame([
            'drafts' => 1, 'critics' => null, 'checks' => null, 'check_errors' => null, 'rewrites' => [], 'tokens' => null, 'consistent' => true,
        ], T::forMetadata(['contract' => 'ultron-regen/1', 'drafts' => 1], ['attempt' => 1]));
    }

    /** `consistent = (drafts === critic.attempt)` si hay revisor; sin revisor que cuadrar, null. */
    public function test_consistent_solo_marca(): void
    {
        $this->assertTrue(T::forMetadata(self::BLOQUE, ['attempt' => 3])['consistent']);
        $this->assertFalse(T::forMetadata(self::BLOQUE, ['attempt' => 1])['consistent']);
        $this->assertFalse(T::forMetadata(['drafts' => 1] + self::BLOQUE, ['attempt' => 2])['consistent']);
        $this->assertNull(T::forMetadata(self::BLOQUE, null)['consistent'], 'sin revisor en la fila (rendición, relevo, la hora de la visita)');
        $this->assertNull(T::forMetadata(self::BLOQUE, ['verdict' => 'pass'])['consistent'], 'un veredicto sin intento no cuadra con nada');
        $this->assertNull(T::forMetadata(self::BLOQUE, ['attempt' => '3'])['consistent'], 'el intento de la fila es un entero');
    }

    public function test_lo_que_no_es_del_contrato_no_entra(): void
    {
        $this->assertNull(T::forMetadata(['contract' => 'ultron-regen/2'] + self::BLOQUE, ['attempt' => 3]), 'otro contrato');
        $this->assertNull(T::forMetadata(array_diff_key(self::BLOQUE, ['contract' => 1]), ['attempt' => 3]), 'sin contrato');
        foreach ([0, 4, '3', null, 2.0] as $malo) {
            $this->assertNull(T::forMetadata(['drafts' => $malo] + self::BLOQUE, ['attempt' => 3]), 'drafts '.var_export($malo, true));
        }

        $raro = [
            'contract' => 'ultron-regen/1',
            'drafts' => 2,
            'critics' => 9,
            'checks' => -1,
            'check_errors' => '1',
            'rewrites' => [
                ['by' => 'server', 'codes' => ['register_tuteo', 'inventado', 'register_tuteo'], 'issues' => ['naturalness', 'vibes']],
                ['by' => 'model', 'codes' => [], 'issues' => []],
                'no es un objeto',
                ['by' => 'critic', 'codes' => 'register_tuteo', 'issues' => null, 'text' => 'Hola Ramiro, te escribo al 3001234567'],
            ],
            'tokens' => ['input' => -5, 'output' => 12, 'cost' => 3],
            'notes' => 'REESCRIBE DE USTED',
        ];
        $traza = T::forMetadata($raro, ['attempt' => 2]);
        $this->assertSame([
            'drafts' => 2,
            'critics' => null,
            'checks' => null,
            'check_errors' => null,
            'rewrites' => [
                ['by' => 'server', 'codes' => ['register_tuteo'], 'issues' => ['naturalness']],
                ['by' => 'critic', 'codes' => [], 'issues' => []],
            ],
            'tokens' => ['input' => null, 'output' => 12],
            'consistent' => true,
        ], $traza);
        $this->assertStringNotContainsString('Ramiro', json_encode($traza));
        $this->assertStringNotContainsString('REESCRIBE', json_encode($traza), 'ningún texto viaja a la fila');

        // Como mucho dos reescrituras: lo que sobra no entra.
        $tres = self::BLOQUE;
        $tres['rewrites'][] = ['by' => 'server', 'codes' => ['length_over_cap'], 'issues' => []];
        $this->assertCount(2, T::forMetadata($tres, ['attempt' => 3])['rewrites']);
        $this->assertNull(T::forMetadata(['tokens' => 'muchos'] + self::BLOQUE, ['attempt' => 3])['tokens']);
    }
}
