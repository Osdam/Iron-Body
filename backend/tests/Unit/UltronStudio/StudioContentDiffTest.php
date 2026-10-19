<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentDiff as D;
use App\Services\UltronStudio\StudioDraftNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * La comparación de dos contenidos (contrato G §A4, `GET compare`), contra el
 * catálogo REAL. Es pura: dos lados con la forma del contenido de una versión
 * y el catálogo.
 *
 * LO QUE SE PROTEGE. Que `same` diga «sin diferencias estructurales» solo
 * cuando no las hay (aristas, configuración, reglas) y que no le afecten la
 * disposición, el orden ni la versión del catálogo; que cada diferencia salga
 * con su etiqueta del catálogo y, si el catálogo ya no la conoce, en crudo;
 * que las reglas se comparen por código, con sus campos en un orden fijo y una
 * regla de F (sin `conditions`) igual a una documental de G; y que los avisos
 * de cada lado se calculen con el catálogo ACTUAL y según SU formato (una
 * versión de F, sin hallazgos de reglas). No hay paridad con el CRM: el diff lo
 * calcula solo el servidor (Enmienda 1).
 */
class StudioContentDiffTest extends TestCase
{
    private const VACIAS = ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []];

    /** El contenido de la línea base con la forma de una versión de formato 2, sin reglas. */
    private static function lado(array $cambios = [], ?array $aristas = null, array $reglas = [], int $formato = 2, string $catalogo = StudioCatalog::VERSION): array
    {
        $base = StudioDraftNormalizer::normalize([
            'definition' => ['edgeIds' => StudioCatalog::baseline()['edgeIds']],
            'configs' => StudioCatalog::baseline()['configs'],
            'layout' => ['positions' => StudioCatalog::baseline()['positions'], 'viewport' => null],
        ]);
        foreach ($cambios as $capa => $valores) {
            $base['configs'][$capa] = array_merge($base['configs'][$capa] ?? [], $valores);
        }
        if ($aristas !== null) {
            $base['definition']['edgeIds'] = $aristas;
        }

        return ['catalogVersion' => $catalogo] + ($formato === 1 ? [] : ['format' => $formato]) + $base + ['rules' => $reglas];
    }

    private static function regla(string $codigo, int $revision = 1, ?array $condiciones = null, array $extra = []): array
    {
        $studio = str_starts_with($codigo, 'S-');

        return $extra + [
            'code' => $codigo,
            'revision' => $revision,
            'source' => $studio ? 'studio' : 'legacy_baseline',
            'enforcement' => $studio ? 'none' : 'code',
            'status' => $studio ? 'draft' : 'active',
            'description' => "Descripción de {$codigo}",
            'type' => 'style',
            'layers' => ['persona'],
            'priority' => 'normal',
            'refs' => self::VACIAS,
            'conditions' => $condiciones,
        ];
    }

    private static function maxChars(string $op, int $valor): array
    {
        return ['when' => [], 'then' => [['kind' => 'limit', 'target' => 'config', 'layer' => 'persona', 'key' => 'maxChars', 'op' => $op, 'value' => $valor]]];
    }

    public function test_dos_lados_iguales_son_same_y_no_tienen_diferencias(): void
    {
        $lado = self::lado(reglas: [self::regla('R-01'), self::regla('S-001', 1, self::maxChars('lte', 400))]);

        $this->assertSame([
            'same' => true,
            'edges' => ['added' => [], 'removed' => []],
            'configs' => [],
            'rules' => ['added' => [], 'removed' => [], 'changed' => []],
            'warnings' => ['added' => [], 'removed' => []],
            'catalogChanged' => false,
        ], D::compare($lado, $lado, StudioCatalog::toArray()));
    }

    public function test_ni_la_disposicion_ni_el_orden_ni_la_version_del_catalogo_hacen_diferencia(): void
    {
        $a = self::lado(reglas: [self::regla('R-01'), self::regla('S-001')]);
        $b = $a;
        $b['layout']['positions']['persona'] = ['x' => 0, 'y' => 0];
        $b['layout']['viewport'] = ['x' => 10, 'y' => 20, 'scale' => 0.5];
        $b['definition']['edgeIds'] = array_reverse($b['definition']['edgeIds']);
        $b['rules'] = array_reverse($b['rules']);
        $b['configs']['persona'] = array_reverse($b['configs']['persona'], true);
        $b['catalogVersion'] = '2026-10-18.1';

        $d = D::compare($a, $b, StudioCatalog::toArray());

        $this->assertTrue($d['same']);
        // Lo que sí dice es que los dos lados se guardaron con catálogos distintos.
        $this->assertTrue($d['catalogChanged']);
    }

    public function test_aristas_anadidas_y_quitadas_con_su_etiqueta_y_en_crudo_lo_que_no_conoce(): void
    {
        $base = StudioCatalog::baseline()['edgeIds'];
        $a = self::lado(aristas: [...$base, 'E99']);
        $b = self::lado(aristas: [...array_values(array_diff($base, ['E10'])), 'E14']);

        $d = D::compare($a, $b, StudioCatalog::toArray());

        $this->assertFalse($d['same']);
        $this->assertSame([
            'added' => [['id' => 'E14', 'label' => 'enlace de pago como mensaje aparte']],
            'removed' => [['id' => 'E10', 'label' => 'borrador, veredicto y token → commit'], ['id' => 'E99', 'label' => 'E99']],
        ], $d['edges']);
    }

    public function test_la_configuracion_va_en_el_orden_del_catalogo_y_lo_desconocido_al_final(): void
    {
        $a = self::lado(['persona' => ['firma' => 'Equipo Iron Body', 'maxChars' => 320.0]]);
        $b = self::lado([
            'strategy' => ['goal' => 'venta'],
            'persona' => ['maxChars' => 400],
            'knowledge' => ['sources' => ['Planes vendibles']],
        ]);

        $d = D::compare($a, $b, StudioCatalog::toArray());

        $this->assertSame([
            ['layer' => 'knowledge', 'key' => 'sources', 'label' => 'Fuentes autorizadas', 'from' => ['Base de conocimiento aprobada', 'Planes vendibles', 'Clases y horarios'], 'to' => ['Planes vendibles']],
            ['layer' => 'persona', 'key' => 'maxChars', 'label' => 'Longitud máxima', 'from' => 320.0, 'to' => 400],
            ['layer' => 'strategy', 'key' => 'goal', 'label' => 'Objetivo comercial', 'from' => 'cita', 'to' => 'venta'],
            // Un parámetro que el catálogo actual no tiene: su clave hace de etiqueta, y el otro lado no lo tiene.
            ['layer' => 'persona', 'key' => 'firma', 'label' => 'firma', 'from' => 'Equipo Iron Body', 'to' => null],
        ], $d['configs']);

        // `320.0` es `320`: como en JSON, no es un cambio.
        $this->assertSame([], D::compare(self::lado(['persona' => ['maxChars' => 320.0]]), self::lado(), StudioCatalog::toArray())['configs']);
    }

    public function test_reglas_anadidas_quitadas_y_cambiadas_por_codigo(): void
    {
        $a = self::lado(reglas: [
            self::regla('S-001'),
            self::regla('R-01', 1),
            self::regla('R-03', 1),
            self::regla('S-004', 3),
        ], formato: 1);
        $b = self::lado(reglas: [
            self::regla('R-01', 2, self::maxChars('lte', 480), ['refs' => ['configs' => [], 'edges' => ['E07', 'E19'], 'knowledgeKeys' => [], 'knowledgeCategories' => []]]),
            self::regla('R-03', 1),
            self::regla('S-002', 1, null, ['description' => 'La nueva']),
            self::regla('S-004', 4),
        ]);

        $d = D::compare($a, $b, StudioCatalog::toArray());

        $this->assertSame([
            'added' => [['code' => 'S-002', 'description' => 'La nueva']],
            'removed' => [['code' => 'S-001', 'description' => 'Descripción de S-001']],
            'changed' => [
                // Los campos, en el orden fijo de la comparación (`refs` antes que `conditions`).
                ['code' => 'R-01', 'fromRevision' => 1, 'toRevision' => 2, 'fields' => ['refs', 'conditions']],
                // Solo cambió la revisión: también es un cambio, sin campos.
                ['code' => 'S-004', 'fromRevision' => 3, 'toRevision' => 4, 'fields' => []],
            ],
        ], $d['rules']);
        $this->assertFalse($d['same']);
    }

    public function test_una_regla_de_f_sin_conditions_es_igual_que_una_documental_de_g(): void
    {
        $deF = self::regla('R-03');
        unset($deF['conditions']);

        $d = D::compare(self::lado(reglas: [$deF], formato: 1), self::lado(reglas: [self::regla('R-03')]), StudioCatalog::toArray());

        $this->assertSame(['added' => [], 'removed' => [], 'changed' => []], $d['rules']);
        $this->assertTrue($d['same']);
    }

    public function test_los_avisos_de_cada_lado_se_calculan_segun_su_formato(): void
    {
        // S-001 (≤ 300) y la línea base (320): en formato 2 es un aviso; en una
        // versión de F, la evaluación de reglas no corre.
        $reglas = [self::regla('S-001', 1, self::maxChars('lte', 300))];
        $deF = self::lado(reglas: $reglas, formato: 1);
        $deG = self::lado(reglas: $reglas);

        $this->assertSame(['opcional-E14', 'opcional-E16'], D::warnings($deF, StudioCatalog::toArray()));
        $this->assertSame(['opcional-E14', 'opcional-E16', 'regla-S-001', 'reglas-sin-aplicacion'], D::warnings($deG, StudioCatalog::toArray()));

        $d = D::compare($deF, $deG, StudioCatalog::toArray());
        $this->assertSame(['added' => ['regla-S-001', 'reglas-sin-aplicacion'], 'removed' => []], $d['warnings']);
        // Mismo contenido y mismas reglas: estructuralmente iguales aunque los avisos cambien.
        $this->assertTrue($d['same']);

        // Con E14 se va su aviso de opcional y llega el de la bandera apagada.
        $conE14 = self::lado(aristas: [...StudioCatalog::baseline()['edgeIds'], 'E14']);
        $this->assertSame(['opcional-E14'], D::compare(self::lado(), $conE14, StudioCatalog::toArray())['warnings']['removed']);
    }

    public function test_un_formato_desconocido_no_se_compara(): void
    {
        $this->expectException(InvalidArgumentException::class);

        D::compare(self::lado(), ['format' => 3] + self::lado(), StudioCatalog::toArray());
    }
}
