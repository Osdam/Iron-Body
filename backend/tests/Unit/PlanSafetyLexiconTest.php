<?php

namespace Tests\Unit;

use App\Services\Trainer\PlanSafetyLexicon;
use PHPUnit\Framework\TestCase;

/**
 * El léxico de restricciones compara por PALABRA COMPLETA.
 *
 * Lo destapó la primera evaluación real (28-10-2026, Luisa, vegetariana): «res»
 * se trataba como raíz y chocaba con «respetando», «resto» o «resistencia».
 * El modelo no podía corregirlo —le decían «res» y volvía a escribir
 * «respeta»— y la generación fallaba después de reparar. Un ingrediente
 * prohibido tiene que ser esa palabra (o su plural), sin distinguir tildes ni
 * mayúsculas; nunca el principio de otra.
 */
class PlanSafetyLexiconTest extends TestCase
{
    private PlanSafetyLexicon $l;

    protected function setUp(): void
    {
        parent::setUp();
        $this->l = new PlanSafetyLexicon;
    }

    public function test_palabras_que_empiezan_o_contienen_un_termino_prohibido_no_chocan(): void
    {
        foreach ([
            'Fresas con yogur vegetal', 'Frutos frescos de temporada', 'Según tus intereses',
            'Mejora la resistencia', 'Cambia las lentejas por garbanzos respetando tu dieta',
            'El resto del día, fruta', 'Respeta los horarios', 'Resultados en 4 semanas',
            'Crema de ahuyama',
        ] as $texto) {
            $this->assertNull($this->l->conflict($texto, ['vegetarian']), "«{$texto}» no nombra ningún alimento prohibido");
        }
        $this->assertNull($this->l->conflict('Panela en el tinto no', ['gluten']), '«panela» no es «pan»');
        $this->assertNull($this->l->conflict('Papaya picada', ['gluten', 'vegetarian']), '«papaya» no es nada prohibido');
        $this->assertNull($this->l->conflict('Clarifica el caldo', ['egg']), '«clarifica» no es «clara»');
    }

    public function test_la_palabra_completa_y_su_plural_chocan_sin_distinguir_tildes_ni_mayusculas(): void
    {
        foreach (['Carne de RES molida', 'En lugar de res, lentejas', 'Pollo', 'pollos asados', 'Pechuga'] as $t) {
            $this->assertNotNull($this->l->conflict($t, ['vegetarian']), $t);
        }
        $this->assertSame('atun', $this->l->conflict('ATÚN en agua', ['fish_seafood'])['term']);
        $this->assertSame('atun', $this->l->conflict('atunes', ['fish_seafood'])['term']);
        $this->assertSame('mani', $this->l->conflict('Maní tostado', ['peanut'])['term']);
        $this->assertSame('pan', $this->l->conflict('Dos panes integrales', ['gluten'])['term']);
        $this->assertSame('yogur', $this->l->conflict('Yogures griegos', ['lactose'])['term']);
        $this->assertNull($this->l->conflict('Leche deslactosada', ['lactose']), 'el calificador sigue permitiéndolo');
    }

    public function test_el_choque_dice_la_palabra_que_aparece_y_su_contexto(): void
    {
        $c = $this->l->conflict('Puedes usar lentejas en lugar de carne de res en el almuerzo.', ['vegetarian']);

        // La primera que aparece manda: «carne».
        $this->assertSame('carne', $c['term']);
        $this->assertSame('carne', $c['found']);
        $this->assertStringContainsString('en lugar de carne de res', $c['snippet']);
    }

    public function test_las_senales_clinicas_siguen_leyendo_raices(): void
    {
        $this->assertSame(['cardio'], array_column($this->l->clinicalSignalsIn(['Refiere hipertensión controlada']), 'key'));
        $this->assertSame(['diabetes'], array_column($this->l->clinicalSignalsIn(['DIABÉTICA tipo 2']), 'key'));
        $this->assertSame(['vegetarian'], array_column($this->l->restrictionsIn(['Es vegetariana']), 'key'));
    }

    /**
     * Seguridad A2 y revisor 2: términos que faltaban, y la negación ANTE LA
     * DUDA no niega. Solo «no», «niega» o «descarta» justo antes —o al
     * principio de la enumeración— la anulan, hasta el final de la frase.
     */
    public function test_senales_clinicas_que_tienen_que_detectarse(): void
    {
        foreach ([
            'Hipotiroidismo en tratamiento' => 'thyroid', 'Antecedente de hipertiroidismo' => 'thyroid',
            'Paciente con HTA' => 'cardio', 'Tiene tensión alta' => 'cardio', 'Riesgo cardiovascular alto' => 'cardio',
            'EPOC leve' => 'respiratory', 'Está amamantando' => 'pregnancy', 'En lactancia materna' => 'pregnancy',
            'Toma metformina' => 'diabetes', 'Se aplica insulina' => 'diabetes',
            'No controla su diabetes' => 'diabetes', 'Sin control de la diabetes' => 'diabetes',
            'Sin diabetes' => 'diabetes', 'No refiere diabetes' => 'diabetes',
        ] as $frase => $clave) {
            $this->assertContains($clave, array_column($this->l->clinicalSignalsIn([$frase]), 'key'), "«{$frase}» es señal");
        }
    }

    public function test_frases_que_no_son_senal_clinica(): void
    {
        foreach ([
            'Niega hipertensión, diabetes y asma.', 'No diabetes.', 'Descarta asma', 'Niega diabetes e hipertensión. Lumbar sensible.',
            'Frecuencia cardíaca en reposo de 70', 'En época de exámenes', 'Trabajo cardiovascular de 20 minutos', 'Mejorar la resistencia cardiovascular',
        ] as $frase) {
            $this->assertSame([], $this->l->clinicalSignalsIn([$frase]), "«{$frase}» no es señal");
        }
        // La negación acaba con la frase.
        $this->assertSame(['respiratory'], array_column($this->l->clinicalSignalsIn(['Niega diabetes. Asma leve.']), 'key'));
    }

    /** Revisor 3: la pista no cruza «.» ni «;», «sin carne roja» no es vegetariano y «lácteos» es débil. */
    public function test_restricciones_sin_falsos_positivos(): void
    {
        $this->assertSame(['lactose'], array_column($this->l->restrictionsIn(['Sin lactosa. Come huevo']), 'key'));
        $this->assertSame(['lactose'], array_column($this->l->restrictionsIn(['Sin lactosa, come huevo y pescado']), 'key'));
        $this->assertSame(['lactose'], array_column($this->l->restrictionsIn(['Sin lactosa; huevo al desayuno']), 'key'));
        $this->assertSame(['red_meat'], array_column($this->l->restrictionsIn(['Prefiere pollo, sin carne roja']), 'key'));
        $this->assertSame([], $this->l->restrictionsIn(['Come lácteos bajos en grasa']));
        $this->assertSame(['dairy'], array_column($this->l->restrictionsIn(['Sin lácteos']), 'key'));
        $this->assertSame(['egg', 'peanut'], array_column($this->l->restrictionsIn(['Alergia al maní y al huevo']), 'key'));
        $this->assertNotNull($this->l->conflict('Carne de res', ['red_meat']));
        $this->assertNull($this->l->conflict('Pechuga de pollo', ['red_meat']));
    }

    /** M2: lo que nunca puede aparecer en un plan generado, ni para desaconsejarlo. */
    public function test_terminos_de_riesgo_en_el_texto_generado(): void
    {
        foreach (['Haz un ayuno prolongado de 48 horas', 'Toma un diurético', 'Usa laxantes', 'Clembuterol para definir', 'Ciclo de esteroides', 'Anabólicos'] as $t) {
            $this->assertNotNull($this->l->riskIn($t), $t);
        }
        foreach (['Ayuno nocturno normal entre la cena y el desayuno', 'Esterilla para estirar', 'Diurno'] as $t) {
            $this->assertNull($this->l->riskIn($t), $t);
        }
    }

    public function test_los_terminos_prohibidos_de_una_restriccion_se_pueden_listar(): void
    {
        $t = $this->l->forbiddenTerms(['vegetarian']);

        $this->assertContains('res', $t);
        $this->assertContains('pollo', $t);
        $this->assertSame($t, array_values(array_unique($t)));
    }
}
