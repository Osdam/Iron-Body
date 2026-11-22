<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\Ultron\UltronReplyOrigin as O;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · OLA 6 · DE DÓNDE SALIÓ EL TEXTO DE UNA
 * RESPUESTA (plan del arquitecto `plan-arquitecto-a4-regenerar.md` §3 P-7 y §6
 * «Ola 6»; condición 5 del propietario).
 *
 * `UltronReplyOrigin::classify()` es pura y sigue las reglas de la consulta de
 * la línea base (`ops/comprobacion-produccion/origen-respuestas.sql`, C), en su
 * orden: `fallback_mode` → respaldo (del revisor o de la rendición); si no, la
 * política con su borrador descartado → respaldo de la política; si no, el
 * modelo (recortado si la longitud quitó su pregunta final). Así las filas
 * HISTÓRICAS (sin `metadata.regeneration`) se clasifican igual que en la
 * línea base, y las nuevas dicen además cuántas redacciones hubo y quién pidió
 * cada reescritura. Lo que se fija aquí: cada camino con metadata de fixture,
 * la precedencia, la hora de la visita (texto del servidor que la consulta
 * cuenta como del modelo: se marca aparte) y que nada fuera del vocabulario
 * cerrado sale de la clasificación.
 */
class UltronReplyOriginTest extends TestCase
{
    private const CLAVES = ['origin', 'attempt', 'drafts', 'critics', 'checks', 'rewrites', 'fallback_reason', 'policy_codes', 'recovery_reason', 'trimmed', 'server_text', 'traced', 'tokens'];

    /** Un bloque `metadata.regeneration` tal como lo deja `UltronRegenerationTrace`. */
    private static function traza(int $drafts, ?int $critics, array $rewrites, ?array $tokens = null, ?int $checks = 2): array
    {
        return ['drafts' => $drafts, 'critics' => $critics, 'checks' => $checks, 'check_errors' => 0, 'rewrites' => $rewrites, 'tokens' => $tokens, 'consistent' => true];
    }

    /**
     * metadata, tipo de acción, lo esperado (solo las claves que importan en cada caso).
     *
     * @return array<string, array{array<string,mixed>, string, array<string,mixed>}>
     */
    public static function caminos(): array
    {
        $pasa = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null, 'notes' => null];
        $pasa2 = ['attempt' => 2] + $pasa;
        $falla2 = ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3, 'issues' => ['relevance'], 'hard_fail' => null, 'notes' => 'no contesta'];
        $servidor = ['by' => 'server', 'codes' => ['register_tuteo', 'length_over_cap'], 'issues' => ['naturalness', 'progressive_disclosure']];
        $revisor = ['by' => 'critic', 'codes' => [], 'issues' => ['relevance']];
        $historica = ['by' => 'critic', 'codes' => [], 'issues' => []];

        return [
            'normal, al primer intento' => [['critic' => $pasa, 'length' => ['action' => 'fit', 'from' => 120, 'dropped' => 0]], 'reply',
                ['origin' => O::MODEL, 'attempt' => O::ATTEMPT_FIRST, 'drafts' => 1, 'critics' => 1, 'checks' => null, 'rewrites' => [], 'fallback_reason' => null, 'trimmed' => false, 'traced' => false]],
            'recortado por el tope' => [['critic' => $pasa, 'length' => ['action' => 'trimmed', 'from' => 230, 'dropped' => 1]], 'reply',
                ['origin' => O::MODEL, 'attempt' => O::ATTEMPT_FIRST, 'trimmed' => true]],
            'recortado por el registro' => [['critic' => $pasa, 'register' => ['expected' => 'usted', 'found' => ['tu'], 'action' => 'trimmed']], 'reply',
                ['origin' => O::MODEL, 'trimmed' => true]],
            'histórica: la reescritura del revisor' => [['critic' => $pasa2], 'reply',
                ['origin' => O::MODEL, 'attempt' => O::ATTEMPT_REGENERATED, 'drafts' => 2, 'critics' => 2, 'rewrites' => [$historica], 'traced' => false]],
            'con traza: la pidió el servidor' => [['critic' => $pasa2, 'regeneration' => self::traza(2, 1, [$servidor], ['input' => 41000, 'output' => 900])], 'reply',
                ['origin' => O::MODEL, 'attempt' => O::ATTEMPT_REGENERATED, 'drafts' => 2, 'critics' => 1, 'checks' => 2, 'rewrites' => [$servidor], 'traced' => true, 'tokens' => ['input' => 41000, 'output' => 900]]],
            'con traza: dos reescrituras' => [['critic' => ['attempt' => 3] + $pasa, 'regeneration' => self::traza(3, 2, [$servidor, $revisor])], 'reply',
                ['origin' => O::MODEL, 'attempt' => O::ATTEMPT_REGENERATED, 'drafts' => 3, 'critics' => 2, 'rewrites' => [$servidor, $revisor]]],
            'con traza: al primer intento' => [['critic' => $pasa, 'regeneration' => self::traza(1, 1, [], null, 1)], 'reply',
                ['origin' => O::MODEL, 'attempt' => O::ATTEMPT_FIRST, 'drafts' => 1, 'critics' => 1, 'checks' => 1, 'rewrites' => [], 'traced' => true, 'tokens' => null]],
            'la política sustituyó el borrador' => [['critic' => $pasa, 'policy_violations' => ['policy_greeting_sells'], 'policy_discarded_draft' => 'Hola, ¿cuál es tu objetivo?'], 'reply',
                ['origin' => O::FALLBACK, 'attempt' => null, 'drafts' => 1, 'fallback_reason' => O::REASON_POLICY, 'policy_codes' => ['policy_greeting_sells']]],
            'la política, tras una reescritura del servidor' => [['critic' => $pasa2, 'regeneration' => self::traza(2, 1, [$servidor]), 'policy_violations' => ['policy_register_mismatch', 'policy_length_exceeded'], 'policy_discarded_draft' => '…'], 'reply',
                ['origin' => O::FALLBACK, 'drafts' => 2, 'rewrites' => [$servidor], 'fallback_reason' => O::REASON_POLICY, 'policy_codes' => ['policy_register_mismatch', 'policy_length_exceeded']]],
            'el revisor rechazó los dos intentos' => [['critic' => $falla2, 'fallback_mode' => 'SAFE_CURATED_REPLY', 'discarded_draft' => '…'], 'reply',
                ['origin' => O::FALLBACK, 'attempt' => null, 'drafts' => 2, 'critics' => 2, 'rewrites' => [$historica], 'fallback_reason' => O::REASON_CRITIC, 'policy_codes' => []]],
            'el revisor rechazó los tres (con traza)' => [['critic' => ['attempt' => 3] + $falla2, 'regeneration' => self::traza(3, 3, [$servidor, $revisor]), 'fallback_mode' => 'SAFE_CURATED_REPLY'], 'reply',
                ['origin' => O::FALLBACK, 'drafts' => 3, 'critics' => 3, 'fallback_reason' => O::REASON_CRITIC]],
            'la rendición (el revisor caído o el commit rechazó)' => [['fallback_mode' => 'SAFE_CURATED_REPLY', 'recovery' => ['reason' => 'critic_unavailable', 'attempt' => 2]], 'reply',
                ['origin' => O::FALLBACK, 'drafts' => null, 'critics' => null, 'rewrites' => [], 'fallback_reason' => O::REASON_RECOVERY, 'recovery_reason' => 'critic_unavailable']],
            'respaldo sin causa conocida' => [['fallback_mode' => 'SAFE_CURATED_REPLY'], 'reply',
                ['origin' => O::FALLBACK, 'fallback_reason' => O::REASON_UNKNOWN]],
            'la hora de la visita (la consulta la cuenta como del modelo)' => [['visit_hour' => ['mode' => 'ask', 'date' => '2026-11-21', 'options' => ['07:00', '19:00']], 'discarded_draft' => '…'], 'reply',
                ['origin' => O::MODEL, 'attempt' => O::ATTEMPT_UNKNOWN, 'drafts' => null, 'server_text' => O::SERVER_VISIT_HOUR]],
            'repetida y recuperada' => [['risk_flags' => ['repeated_reply'], 'critic' => $pasa], 'repeated_reply_recovered',
                ['origin' => O::FALLBACK, 'fallback_reason' => O::REASON_LAST_RESORT, 'drafts' => 1]],
            'reventa recuperada' => [['critic' => $pasa], 'resell_blocked_recovered',
                ['origin' => O::FALLBACK, 'fallback_reason' => O::REASON_LAST_RESORT]],
            'sin nada (una fila vieja)' => [['origin' => 'ultron'], 'reply',
                ['origin' => O::MODEL, 'attempt' => O::ATTEMPT_UNKNOWN, 'drafts' => null, 'critics' => null, 'rewrites' => [], 'trimmed' => false, 'server_text' => null, 'traced' => false]],
        ];
    }

    /** @param array<string,mixed> $esperado */
    #[DataProvider('caminos')]
    public function test_cada_camino(array $metadata, string $tipo, array $esperado): void
    {
        $c = O::classify($metadata, $tipo);

        $this->assertSame(self::CLAVES, array_keys($c), 'la forma, cerrada y en su orden');
        // Lo esperado, en el orden de la forma.
        $this->assertSame(array_replace(array_intersect_key(array_flip(self::CLAVES), $esperado), $esperado), array_intersect_key($c, $esperado));
    }

    /** El orden de la consulta de la línea base: `fallback_mode` antes que la política, la política antes que el recorte. */
    public function test_la_precedencia_es_la_de_la_consulta(): void
    {
        $todo = [
            'fallback_mode' => 'SAFE_CURATED_REPLY', 'critic' => ['verdict' => 'fail', 'attempt' => 2],
            'policy_violations' => ['policy_greeting_sells'], 'policy_discarded_draft' => '…',
            'length' => ['action' => 'trimmed'],
        ];
        $this->assertSame(O::REASON_CRITIC, O::classify($todo)['fallback_reason']);

        unset($todo['fallback_mode']);
        $this->assertSame(O::REASON_POLICY, O::classify($todo)['fallback_reason']);

        // La consulta exige las dos cosas: violaciones Y el borrador descartado.
        $this->assertSame(O::MODEL, O::classify(['policy_violations' => ['policy_greeting_sells']])['origin']);
        $this->assertSame(O::MODEL, O::classify(['policy_violations' => [], 'policy_discarded_draft' => '…'])['origin']);
        $this->assertSame(O::MODEL, O::classify(['policy_violations' => 'policy_greeting_sells', 'policy_discarded_draft' => '…'])['origin'], 'no es una lista');

        // El recorte es del modelo y no cambia el origen.
        $this->assertSame([O::MODEL, true], [O::classify(['length' => ['action' => 'trimmed']])['origin'], O::classify(['length' => ['action' => 'trimmed']])['trimmed']]);
        // `fallback_mode` cuenta aunque venga vacío (la consulta mira la clave).
        $this->assertSame(O::FALLBACK, O::classify(['fallback_mode' => null])['origin']);
    }

    /** Nada fuera del vocabulario cerrado sale de aquí: ni textos, ni etiquetas raras, ni números fuera de rango. */
    public function test_lo_que_no_es_del_vocabulario_no_sale(): void
    {
        $c = O::classify([
            'fallback_mode' => 'SAFE_CURATED_REPLY',
            'recovery' => ['reason' => 'Ramiro 3209876543 dijo hola', 'attempt' => 2],
        ]);
        $this->assertNull($c['recovery_reason'], 'una etiqueta que no es un código no sale');

        $c = O::classify([
            'policy_violations' => ['policy_greeting_sells', 'Llámame al 3209876543', 'policy_greeting_sells', 7, ['x'], str_repeat('a', 65)],
            'policy_discarded_draft' => 'Hola Ramiro',
        ]);
        $this->assertSame(['policy_greeting_sells'], $c['policy_codes'], 'solo códigos, sin repetir');

        $c = O::classify(['regeneration' => [
            'drafts' => 9, 'critics' => -1, 'checks' => 'dos',
            'rewrites' => [['by' => 'model', 'codes' => ['x']], ['by' => 'server', 'codes' => ['register_tuteo', 'Ramiro'], 'issues' => ['vibes', 'naturalness'], 'text' => 'Hola Ramiro']],
            'tokens' => ['input' => -5, 'output' => '9'],
        ], 'critic' => ['attempt' => 2]]);
        $this->assertSame([2, null, null], [$c['drafts'], $c['critics'], $c['checks']], 'fuera de rango: no se usa (los borradores, del revisor)');
        $this->assertSame([['by' => 'server', 'codes' => ['register_tuteo'], 'issues' => ['naturalness']]], $c['rewrites']);
        $this->assertSame(['input' => null, 'output' => null], $c['tokens']);
        $this->assertStringNotContainsString('Ramiro', (string) json_encode($c));
        $this->assertStringNotContainsString('3209876543', (string) json_encode($c));
    }

    /** Sin traza y con tres borradores (S2 sin bloque), no se adivina quién pidió las reescrituras. */
    public function test_sin_traza_no_se_adivina_el_autor_de_un_tercer_borrador(): void
    {
        $c = O::classify(['critic' => ['verdict' => 'pass', 'attempt' => 3]]);
        $this->assertSame([3, O::ATTEMPT_REGENERATED], [$c['drafts'], $c['attempt']]);
        $this->assertSame([['by' => O::BY_UNKNOWN, 'codes' => [], 'issues' => []], ['by' => O::BY_UNKNOWN, 'codes' => [], 'issues' => []]], $c['rewrites']);
    }

    /** Las llamadas al modelo de un turno: 1 (el estratega) + redacciones + revisores, si se saben. */
    public function test_las_llamadas_al_modelo(): void
    {
        $this->assertSame(3, O::modelCalls(O::classify(['critic' => ['verdict' => 'pass', 'attempt' => 1]])));
        $this->assertSame(5, O::modelCalls(O::classify(['critic' => ['verdict' => 'pass', 'attempt' => 2]])));
        $this->assertSame(4, O::modelCalls(O::classify(['regeneration' => self::traza(2, 1, [['by' => 'server', 'codes' => ['register_tuteo'], 'issues' => []]])])));
        $this->assertSame(7, O::modelCalls(O::classify(['regeneration' => self::traza(3, 3, [])])));
        $this->assertNull(O::modelCalls(O::classify(['regeneration' => self::traza(2, null, [])])), 'sin revisores no se sabe');
        $this->assertNull(O::modelCalls(O::classify(['fallback_mode' => 'SAFE_CURATED_REPLY', 'recovery' => ['reason' => 'x']])));
    }
}
