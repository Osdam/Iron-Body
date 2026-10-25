<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioLabCatalog as L;
use App\Services\UltronStudio\StudioLabPersonalData;
use PHPUnit\Framework\TestCase;

/**
 * El catálogo de escenarios del laboratorio (contrato H4 §A2).
 *
 * LO QUE SE PROTEGE. Los once escenarios de la tabla del contrato (C-11 se
 * retiró en 2026-10-20.1: Enmienda 6.1), en su orden y con sus hechos; que cumplan los límites que se le exigen al
 * personal; que no lleven datos personales (ni nombres, ni teléfonos, ni
 * cifras de 7 dígitos, ni correos, ni invisibles); que solo citen reglas que
 * existen; y que nadie cambie un texto sin subir la versión.
 */
class StudioLabCatalogTest extends TestCase
{
    /**
     * La huella de cada versión del catálogo de escenarios. Si falla porque
     * cambió, cambiaron los datos: sube StudioLabCatalog::VERSION, añade aquí
     * su huella y vuelve a exportar los fixtures del CRM.
     */
    private const HUELLAS = [
        '2026-10-19.1' => 'b1ba17529c01c55713ba9e2d62f40d81fc8a07b8b4cf4ce677f8fc30afb79916',
        '2026-10-20.1' => '9dfdd4f741f1d22ca06baba739d897f78315c81a996371e01e70134a0dc44cbc',
    ];

    /** La tabla del contrato: código → categoría y hechos. */
    private const TABLA = [
        'C-01' => ['saludo', 'none', false],
        'C-02' => ['precio', 'none', false],
        'C-03' => ['descuento', 'none', false],
        'C-04' => ['pago', 'none', false],
        'C-05' => ['pago', 'none', false],
        'C-06' => ['derivacion', 'none', true],
        'C-07' => ['derivacion', 'none', false],
        'C-08' => ['cortesia', 'none', false],
        'C-09' => ['hechos', 'none', false],
        'C-10' => ['estilo', 'none', false],
        'C-12' => ['membresia', 'none', false],
    ];

    /** Las reglas que cita cada escenario en sus criterios `must`/`must_not` (la columna «Criterios principales»). */
    private const REGLAS_PRINCIPALES = [
        'C-01' => ['R-08'], 'C-02' => ['R-01'], 'C-03' => ['R-02'], 'C-04' => ['R-04'], 'C-05' => ['R-03'], 'C-06' => ['R-07'],
        'C-07' => ['R-07'], 'C-08' => ['R-06'], 'C-09' => ['R-06'], 'C-10' => ['R-10'], 'C-12' => ['R-05'],
    ];

    /** Nombres de pila frecuentes, por si alguno se colara en un guion. */
    private const NOMBRES = ['Ana', 'Carlos', 'Juan', 'María', 'Maria', 'Luis', 'Laura', 'Andrés', 'Camila', 'Sofía', 'Daniel', 'Valentina', 'Root', 'Pedro', 'José'];

    public function test_la_version_y_su_huella_estan_fijadas(): void
    {
        $this->assertSame('2026-10-20.1', L::VERSION);
        $this->assertArrayHasKey(L::VERSION, self::HUELLAS, 'Versión nueva del catálogo de escenarios: añade su huella.');
        $this->assertSame(self::HUELLAS[L::VERSION], L::hash(), 'Cambió un escenario sin subir StudioLabCatalog::VERSION.');
        $this->assertSame(StudioContentHasher::hash(['version' => L::VERSION, 'scenarios' => L::scenarios()]), L::hash());
    }

    public function test_son_los_once_escenarios_de_la_tabla_del_contrato(): void
    {
        $this->assertSame(array_keys(self::TABLA), L::codes());
        // C-11 («no me escriban más») no vuelve: el motor no contesta a un
        // opt-out (R-11) y el laboratorio no puede simular un silencio.
        $this->assertNull(L::byCode('C-11'), 'C-11 se retiró del catálogo (Enmienda 6.1)');
        foreach (L::scenarios() as $s) {
            [$categoria, $pago, $traspaso] = self::TABLA[$s['code']];
            $this->assertSame($categoria, $s['category'], $s['code']);
            $this->assertSame(['paymentState' => $pago, 'handoffRequested' => $traspaso], $s['facts'], $s['code']);
            $this->assertSame(['code', 'category', 'title', 'description', 'turns', 'facts', 'criteria'], array_keys($s), $s['code']);
        }
        $this->assertSame('C-04', L::byCode('C-04')['code']);
        $this->assertNull(L::byCode('C-99'));
    }

    public function test_cada_escenario_cumple_los_limites_que_se_exigen_al_personal(): void
    {
        $reglas = array_column(StudioCatalog::legacyRules(), 'code');
        foreach (L::scenarios() as $s) {
            $c = $s['code'];
            $this->assertContains($s['category'], L::CATEGORIES, $c);
            $this->assertGreaterThanOrEqual(L::TITLE_MIN, mb_strlen($s['title']), $c);
            $this->assertLessThanOrEqual(L::TITLE_MAX, mb_strlen($s['title']), $c);
            $this->assertLessThanOrEqual(L::DESCRIPTION_MAX, mb_strlen($s['description']), $c);

            $this->assertGreaterThanOrEqual(L::TURNS_MIN, count($s['turns']), $c);
            $this->assertLessThanOrEqual(L::TURNS_MAX, count($s['turns']), $c);
            $this->assertSame('prospect', $s['turns'][count($s['turns']) - 1]['role'], "{$c}: el último turno es de la persona");
            foreach ($s['turns'] as $t) {
                $this->assertSame(['role', 'text'], array_keys($t), $c);
                $this->assertContains($t['role'], L::TURN_ROLES, $c);
                $this->assertGreaterThanOrEqual(L::TURN_TEXT_MIN, mb_strlen($t['text']), $c);
                $this->assertLessThanOrEqual(L::TURN_TEXT_MAX, mb_strlen($t['text']), $c);
            }

            $this->assertContains($s['facts']['paymentState'], L::PAYMENT_STATES, $c);
            $this->assertIsBool($s['facts']['handoffRequested'], $c);

            $this->assertGreaterThanOrEqual(L::CRITERIA_MIN, count($s['criteria']), $c);
            $this->assertLessThanOrEqual(L::CRITERIA_MAX, count($s['criteria']), $c);
            foreach ($s['criteria'] as $i => $cr) {
                $this->assertSame(['id', 'kind', 'text', 'rules'], array_keys($cr), $c);
                $this->assertSame('c'.($i + 1), $cr['id'], "{$c}: los ids van por posición");
                $this->assertContains($cr['kind'], L::CRITERION_KINDS, $c);
                $this->assertGreaterThanOrEqual(L::CRITERION_TEXT_MIN, mb_strlen($cr['text']), $c);
                $this->assertLessThanOrEqual(L::CRITERION_TEXT_MAX, mb_strlen($cr['text']), $c);
                $this->assertLessThanOrEqual(L::RULE_REFS_MAX, count($cr['rules']), $c);
                foreach ($cr['rules'] as $r) {
                    $this->assertContains($r, $reglas, "{$c} cita {$r}, que no existe en las reglas del código");
                }
            }

            $contenido = json_encode(['turns' => $s['turns'], 'facts' => $s['facts'], 'criteria' => $s['criteria']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertLessThanOrEqual(L::MAX_BYTES, strlen($contenido), $c);
        }
    }

    public function test_los_criterios_principales_citan_la_regla_de_la_tabla(): void
    {
        foreach (L::scenarios() as $s) {
            $citadas = [];
            foreach ($s['criteria'] as $cr) {
                if ($cr['kind'] !== 'should') {
                    $citadas = [...$citadas, ...$cr['rules']];
                }
            }
            foreach (self::REGLAS_PRINCIPALES[$s['code']] as $regla) {
                $this->assertContains($regla, $citadas, "{$s['code']} no cita {$regla} en sus criterios principales");
            }
            $this->assertNotEmpty(array_filter($s['criteria'], static fn (array $c): bool => $c['kind'] !== 'should'), "{$s['code']} sin criterios must/must_not");
        }
    }

    public function test_ningun_texto_lleva_datos_personales_ni_nombres(): void
    {
        foreach (L::scenarios() as $s) {
            $this->assertSame([], StudioLabPersonalData::scan($s), "{$s['code']}: algo no pasa el filtro de datos personales");

            $textos = [$s['title'], $s['description'], ...array_column($s['turns'], 'text'), ...array_column($s['criteria'], 'text')];
            foreach ($textos as $texto) {
                $this->assertDoesNotMatchRegularExpression('/\d(?:\D?\d){6,}/u', $texto, "{$s['code']}: cifra de 7 dígitos");
                $this->assertStringNotContainsString('@', $texto, $s['code']);
                $this->assertStringNotContainsString('+57', $texto, $s['code']);
                foreach (self::NOMBRES as $nombre) {
                    $this->assertDoesNotMatchRegularExpression('/\b'.preg_quote($nombre, '/').'\b/u', $texto, "{$s['code']}: lleva un nombre propio");
                }
                // Una palabra con mayúscula inicial a mitad de frase es un nombre
                // propio (las siglas como CRM o URL van enteras en mayúsculas).
                preg_match_all('/(?<=[\p{Ll},] )\p{Lu}\p{Ll}+\b/u', $texto, $m);
                $this->assertSame([], $m[0], "{$s['code']}: parece llevar un nombre propio");
            }
        }
    }

    public function test_la_huella_de_un_escenario_es_la_de_sus_seis_campos(): void
    {
        $s = L::byCode('C-04');
        $esperada = StudioContentHasher::hash([
            'category' => $s['category'], 'title' => $s['title'], 'description' => $s['description'],
            'turns' => $s['turns'], 'facts' => $s['facts'], 'criteria' => $s['criteria'],
        ]);
        $this->assertSame($esperada, L::scenarioHash($s));
        // El código no cuenta; cualquier campo del contenido, sí.
        $this->assertSame(L::scenarioHash($s), L::scenarioHash(['code' => 'E-001'] + $s));
        $otro = $s;
        $otro['facts']['paymentState'] = 'approved';
        $this->assertNotSame(L::scenarioHash($s), L::scenarioHash($otro));

        $todas = array_map(L::scenarioHash(...), L::scenarios());
        $this->assertCount(11, array_unique($todas), 'dos escenarios del catálogo comparten huella');
    }

    public function test_lo_que_se_exporta_lleva_la_huella_de_cada_escenario(): void
    {
        $exportado = L::toArray();
        $this->assertSame(L::VERSION, $exportado['version']);
        $this->assertSame(L::hash(), $exportado['hash']);
        foreach ($exportado['scenarios'] as $i => $s) {
            $this->assertSame(L::scenarioHash(L::scenarios()[$i]), $s['contentHash']);
        }
        $this->assertSame(
            ['titleMax' => 120, 'descriptionMax' => 500, 'turnsMax' => 8, 'turnTextMax' => 600, 'criteriaMax' => 8, 'criterionTextMax' => 240, 'ruleRefsMax' => 5, 'maxBytes' => 16384],
            L::limits(),
            'los límites de `GET lab/status` (contrato §A7)',
        );
        $this->assertSame(100, L::MAX_LIVE_SCENARIOS);
    }
}
