<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\Ultron\CriticContract as C;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PT-17 · PUERTA 1 · L4 · LAS DIMENSIONES QUE VETAN. Decisión P-1 A del
 * propietario, 24-11-2026, literal: «El revisor solo podrá provocar fallback
 * ante errores graves, defectos de servidor o incumplimientos reales de
 * seguridad, exactitud o política. Los rechazos injustificados deben evitarse
 * sin eliminar las validaciones críticas».
 *
 * {@see C::isSoftFail()} decide si un rechazo del revisor es BLANDO (en un
 * turno de lista, sobre la lista limpia del turno, es un aviso y sale el texto
 * del modelo) o veta (el respaldo de siempre). Por categoría:
 *  - seguridad (`payment_safety`, `url_safety`, `handoff_leak`), exactitud
 *    (`factuality`, `app_factuality`, `membership_factuality`,
 *    `tool_consistency`, `memory_consistency`) y política (`pressure_control`):
 *    con una sola de ellas, aunque vaya junto a otras de estilo, veta;
 *  - estilo y juicio comercial (las otras trece): blandas;
 *  - un fallo duro veta siempre, y lo que no se puede leer, también (falla
 *    cerrado).
 */
class CriticContractVetoDimensionsTest extends TestCase
{
    /** Las trece que no vetan: estilo y juicio comercial. */
    private const BLANDAS = ['relevance', 'empathy', 'naturalness', 'context_use', 'sales_judgment', 'next_step', 'question_discipline',
        'repetition', 'phase_alignment', 'reference_resolution', 'novelty', 'customer_fit', 'progressive_disclosure'];

    /** @param  list<string>  $issues */
    private static function rechazo(array $issues, mixed $hardFail = null): array
    {
        return ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.4, 'issues' => $issues, 'notes' => 'nota', 'hard_fail' => $hardFail];
    }

    public function test_las_categorias_son_las_de_la_decision_y_cubren_el_vocabulario(): void
    {
        $this->assertSame(['payment_safety', 'url_safety', 'handoff_leak'], C::VETO_SECURITY);
        $this->assertSame(['factuality', 'app_factuality', 'membership_factuality', 'tool_consistency', 'memory_consistency'], C::VETO_EXACTNESS);
        $this->assertSame(['pressure_control'], C::VETO_POLICY);
        $this->assertSame([...C::VETO_SECURITY, ...C::VETO_EXACTNESS, ...C::VETO_POLICY], C::VETO_DIMENSIONS);
        $this->assertSame([], array_diff(C::VETO_DIMENSIONS, C::DIMENSIONS), 'todas son del vocabulario del revisor');
        $this->assertSame(count(C::VETO_DIMENSIONS), count(array_unique(C::VETO_DIMENSIONS)));
        // Lo que no veta queda escrito: cambiar una de lado es una decisión.
        $this->assertSame(self::BLANDAS, array_values(array_diff(C::DIMENSIONS, C::VETO_DIMENSIONS)));
    }

    /** @return array<string, array{string}> */
    public static function seguridad(): array
    {
        return array_combine(C::VETO_SECURITY, array_map(static fn (string $d): array => [$d], C::VETO_SECURITY));
    }

    /** @return array<string, array{string}> */
    public static function exactitud(): array
    {
        return array_combine(C::VETO_EXACTNESS, array_map(static fn (string $d): array => [$d], C::VETO_EXACTNESS));
    }

    /** @return array<string, array{string}> */
    public static function politica(): array
    {
        return array_combine(C::VETO_POLICY, array_map(static fn (string $d): array => [$d], C::VETO_POLICY));
    }

    /** @return array<string, array{string}> */
    public static function estiloYJuicioComercial(): array
    {
        return array_combine(self::BLANDAS, array_map(static fn (string $d): array => [$d], self::BLANDAS));
    }

    #[DataProvider('seguridad')]
    public function test_una_dimension_de_seguridad_veta(string $dimension): void
    {
        $this->assertVeta($dimension);
    }

    #[DataProvider('exactitud')]
    public function test_una_dimension_de_exactitud_veta(string $dimension): void
    {
        $this->assertVeta($dimension);
    }

    #[DataProvider('politica')]
    public function test_una_dimension_de_politica_veta(string $dimension): void
    {
        $this->assertVeta($dimension);
    }

    #[DataProvider('estiloYJuicioComercial')]
    public function test_una_dimension_de_estilo_o_juicio_comercial_es_blanda(string $dimension): void
    {
        $this->assertTrue(C::isSoftFail(self::rechazo([$dimension])), "{$dimension}: blanda");
        $this->assertTrue(C::isSoftFail(self::rechazo([$dimension, 'naturalness', 'progressive_disclosure'])), "{$dimension} con otras de estilo: blanda");
        $this->assertTrue(C::isFail(self::rechazo([$dimension])), 'sigue siendo un fail (el aviso lo decide el commit)');
        $this->assertFalse(C::isSoftFail(self::rechazo([$dimension], 'pressure')), 'con un fallo duro, veta');
    }

    public function test_un_fallo_duro_o_lo_que_no_se_puede_leer_veta_siempre(): void
    {
        $this->assertTrue(C::isSoftFail(self::rechazo([])), 'sin issues ni fallo duro: blando');
        $this->assertTrue(C::isSoftFail(['verdict' => 'fail']), 'sin la clave issues: blando');
        $this->assertTrue(C::isSoftFail(self::rechazo(['naturalness'], '')), 'un fallo duro vacío no es un fallo duro');
        foreach (['un fallo duro del vocabulario' => self::rechazo(['naturalness'], 'invented_price'),
            'un fallo duro fuera del vocabulario' => self::rechazo(['naturalness'], 'otro_motivo'),
            'un fallo duro que no es texto' => self::rechazo(['naturalness'], true),
            'issues que no son una lista' => ['verdict' => 'fail', 'issues' => 'naturalness', 'hard_fail' => null],
            'un issue fuera del vocabulario' => self::rechazo(['naturalness', 'nueva_dimension']),
            'un issue que no es texto' => self::rechazo(['naturalness', ['factuality']]),
            // Revisión de la puerta 1 (Importante 1): n8n corta los issues a 8 sin ordenarlos; 8 de estilo pudieron dejar
            // fuera una dimensión de veto (p. ej. factuality, novena). Con la lista llena no se sabe: no es blando.
            'ocho issues de estilo (la lista pudo venir cortada)' => self::rechazo(['relevance', 'empathy', 'naturalness', 'context_use',
                'sales_judgment', 'next_step', 'question_discipline', 'repetition']),
            'un pass' => ['verdict' => 'pass', 'issues' => ['naturalness'], 'hard_fail' => null],
            'un pass con fallo duro' => ['verdict' => 'pass', 'issues' => [], 'hard_fail' => 'pressure'],
            'sin veredicto' => []] as $caso => $revisor) {
            $this->assertFalse(C::isSoftFail($revisor), $caso);
        }
    }

    private function assertVeta(string $dimension): void
    {
        $this->assertFalse(C::isSoftFail(self::rechazo([$dimension])), "{$dimension}: veta");
        $this->assertFalse(C::isSoftFail(self::rechazo(['naturalness', 'progressive_disclosure', $dimension])), "{$dimension} junto a dimensiones de estilo: veta");
        $this->assertTrue(C::isFail(self::rechazo([$dimension])), 'y es un fail: el respaldo de siempre');
    }
}
