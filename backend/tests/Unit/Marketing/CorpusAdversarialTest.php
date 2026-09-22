<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy as Pol;
use App\Services\Marketing\Ultron\ConversationMemory as CM;
use App\Services\Marketing\Ultron\ConversationMemoryService as Mem;
use App\Services\Marketing\Ultron\ReferenceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CORPUS ADVERSARIAL: COMO ESCRIBE LA GENTE, NO COMO ESCRIBE EL PATRÓN.
 *
 * Sin tildes, sin signos, con faltas, a trozos, con jerga, con dos preguntas
 * en una y cambiando de tema y volviendo. No está escrito alrededor de
 * ninguna regex: está escrito para romperla. Cada fila dice qué NO puede
 * pasar (un plan resuelto que nadie eligió, una visita perdida, una duda de
 * precio leída como objeción) y qué SÍ tiene que pasar cuando la persona sí
 * elige.
 */
class CorpusAdversarialTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function catalogo(): array
    {
        return [
            ['id' => 22, 'name' => 'TOTAL ACCESS - ELITE', 'is_recommended' => true, 'benefits' => ['Acceso completo']],
            ['id' => 2, 'name' => 'Plan Semana', 'is_recommended' => false, 'benefits' => ['Siete días']],
            ['id' => 4, 'name' => 'Plan Mensual', 'is_recommended' => false, 'benefits' => ['Acceso ilimitado']],
            ['id' => 5, 'name' => 'Trimestre', 'is_recommended' => false, 'benefits' => ['Tres meses']],
        ];
    }

    /** @return array<int,array{id:int,name:string}> */
    private function vendibles(): array
    {
        return array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']], $this->catalogo());
    }

    // ── 1 · disponibilidad no es un plan ─────────────────────────────────────

    /** @return array<string,array{0:string}> */
    public static function disponibilidadQueNoEsPlan(): array
    {
        return [
            'seis dias' => ['tengo 6 dias disponibles'],
            'seis dias a la semana' => ['puedo ir 6 dias a la semana'],
            'toda la semana' => ['entreno toda la semana'],
            'casi todos los dias' => ['voy casi todos los dias'],
            'la semana que viene' => ['la semana q viene empiezo'],
            'un mes de vacaciones' => ['tengo un mes de vacaciones y quiero aprovechar'],
            'tres meses de viaje' => ['en 3 meses me voy de viaje'],
            'sin tildes y sin signos' => ['q dias abren en la semana'],
            'jerga' => ['parce puedo ir toda la semana en la noche'],
            'audio transcrito' => ['eh si mira yo tengo como seis dias libres a la semana mas o menos'],
        ];
    }

    #[DataProvider('disponibilidadQueNoEsPlan')]
    public function test_la_disponibilidad_no_resuelve_ningun_plan(string $texto): void
    {
        $r = (new ReferenceResolver)->resolve($texto, CM::fromArray(null), $this->vendibles());
        $this->assertNull($r['plan_id'], "«{$texto}» resolvió el plan {$r['plan_id']}");

        $p = Pol::decide(SalesIntents::GOAL_MUSCLE_GAIN, P::DISCOVERY, CM::fromArray(null), $this->catalogo(), $r, $texto);
        $this->assertNull($p['hard_required_plan'], "«{$texto}» obligó a un plan que nadie nombró");
    }

    /** @return array<string,array{0:string,1:int}> */
    public static function eleccionesReales(): array
    {
        return [
            'cuanto vale el plan semana' => ['cuanto vale el plan semana', 2],
            'sin tildes ni mayusculas' => ['cuanto vale el plan mensual', 4],
            'el trimestre' => ['me interesa el trimestre', 5],
            'solo el nombre' => ['mensual', 4],
            'quiero el elite' => ['quiero el total access - elite', 22],
        ];
    }

    #[DataProvider('eleccionesReales')]
    public function test_nombrar_un_plan_si_lo_elige(string $texto, int $esperado): void
    {
        // Como en producción: el referente lo resuelve el resolutor y la
        // política lo recibe. «mensual» a secas es el nombre del producto.
        $r = (new ReferenceResolver)->resolve($texto, CM::fromArray(null), $this->vendibles());
        $p = Pol::decide(SalesIntents::PRICING_QUESTION, P::DISCOVERY, CM::fromArray(null), $this->catalogo(), $r, $texto);
        $this->assertSame($esperado, $p['hard_required_plan'], "«{$texto}»");
    }

    // ── 2 · la visita sobrevive a fragmentos y cambios de tema ───────────────

    /** @return array<string,array{0:string}> */
    public static function fragmentosDeVisita(): array
    {
        return [
            'mañana en la noche' => ['mañana en la noche'],
            'tipo 7' => ['tipo 7'],
            'a las 7 pm' => ['a las 7 pm'],
            'el sabado' => ['el sabado'],
            'de noche' => ['de noche'],
            'listo' => ['listo'],
            'dale' => ['dale'],
            'si' => ['si'],
            'sin tildes' => ['manana en la noche tipo 7'],
        ];
    }

    #[DataProvider('fragmentosDeVisita')]
    public function test_un_fragmento_con_la_visita_a_medias_sigue_en_la_visita(string $texto): void
    {
        $m = CM::fromArray(array_merge(CM::blank(), ['greeted' => ['at' => 'x', 'message_id' => 1]]))
            ->setActiveGoal(Mem::GOAL_COURTESY, Mem::GOAL_COLLECTING, [], 'x');
        $p = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $m, $this->catalogo(), [], $texto);

        $this->assertContains('plan_recommendation', $p['forbidden_actions'], "«{$texto}» abrió la venta con la visita a medias");
        $this->assertNull($p['required_plan_id']);
    }

    /** @return array<string,array{0:string}> */
    public static function incidentalesQueRetoman(): array
    {
        return [
            'hasta que hora abren' => ['hasta q hora abren'],
            'donde quedan' => ['donde quedan'],
            'cuanto vale' => ['y cuanto vale'],
            'seguro que cuesta eso' => ['seguro q el plan semana cuesta eso'],
            'dos preguntas' => ['donde quedan y hasta que hora abren?'],
            'jerga' => ['parce y cuanto es la mensualidad'],
        ];
    }

    #[DataProvider('incidentalesQueRetoman')]
    public function test_una_pregunta_incidental_se_contesta_y_se_retoma_la_visita(string $texto): void
    {
        $m = CM::fromArray(array_merge(CM::blank(), ['greeted' => ['at' => 'x', 'message_id' => 1]]))
            ->setActiveGoal(Mem::GOAL_COURTESY, Mem::GOAL_COLLECTING, ['date' => '2026-09-23'], 'x')
            ->discussPlan(2);
        $intent = str_contains($texto, 'vale') || str_contains($texto, 'cuesta') || str_contains($texto, 'mensualidad')
            ? SalesIntents::PRICING_QUESTION
            : (str_contains($texto, 'donde') ? SalesIntents::LOCATION_QUESTION : SalesIntents::SCHEDULE_QUESTION);
        $p = Pol::decide($intent, P::DISCOVERY, $m, $this->catalogo(), [], $texto);

        $this->assertTrue($p['answer_first'], "«{$texto}» no se contesta primero");
        $this->assertTrue($p['resume_goal_after_answer'], "«{$texto}» borró la visita");
        $this->assertNotNull(Pol::courtesyResumption($p));
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function rechazosDeVisita(): array
    {
        return [
            'prefiero no ir' => ['no, prefiero no ir por ahora', true],
            'no quiero ir' => ['no quiero ir', true],
            'ya no quiero la visita' => ['ya no quiero la visita', true],
            'no me interesa la visita' => ['no me interesa la visita', true],
            'no puedo ir' => ['no puedo ir', true],
            'despues te digo' => ['despues te digo', true],
            'mejor no' => ['mejor no', true],
            'sin tildes ni signos' => ['mejor otro dia te aviso', true],
            // Lo que NO es un rechazo:
            'no tengo carro pero voy' => ['no tengo carro pero voy', false],
            'no se si a las 7 o a las 8' => ['no se si a las 7 o a las 8', false],
            'no, mejor el jueves' => ['no, mejor el jueves', false],
            'la hora' => ['tipo 7', false],
        ];
    }

    #[DataProvider('rechazosDeVisita')]
    public function test_rechazar_la_visita_con_palabras_se_reconoce_y_dudar_no(string $texto, bool $rechaza): void
    {
        $this->assertSame($rechaza, Pol::declinesVisit($texto), "«{$texto}»");

        $m = CM::fromArray(array_merge(CM::blank(), ['greeted' => ['at' => 'x', 'message_id' => 1]]))
            ->setActiveGoal(Mem::GOAL_COURTESY, Mem::GOAL_COLLECTING, [], 'x');
        $p = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $m, $this->catalogo(), [], $texto);
        if ($rechaza) {
            $this->assertNull($p['active_goal'], "«{$texto}» rechazó la visita y la política la mantuvo viva");
            $this->assertNotContains('continue_goal', $p['allowed_next_actions'], "«{$texto}» siguió mandando la visita");
            $this->assertFalse($p['resume_goal_after_answer']);
        }
    }

    // ── 3 · saludo solo, sin inferir nada ────────────────────────────────────

    /** @return array<string,array{0:string}> */
    public static function saludos(): array
    {
        return [
            'hola' => ['hola'],
            'buenas' => ['buenas'],
            'buenas noches' => ['buenas noches'],
            'hola buenas noches' => ['hola buenas noches'],
            'ola sin h' => ['ola'],
            'con emoji' => ['hola 👋'],
            'alo' => ['alo'],
        ];
    }

    #[DataProvider('saludos')]
    public function test_un_saludo_a_secas_no_lleva_plan_ni_exige_hecho(string $texto): void
    {
        $p = Pol::decide(SalesIntents::GREETING, P::NEW_LEAD, CM::fromArray(null), $this->catalogo(), [], $texto);

        $this->assertNull($p['required_plan_id'], "«{$texto}» llevó un plan");
        $this->assertNull($p['hard_required_plan']);
        $this->assertTrue($p['greet_required']);
        $this->assertSame([], $p['required_facts'], 'a un saludo no se le exige informar');
        $this->assertFalse($p['price_verification']);
    }

    // ── 4 · rechazo de plan vs rechazo de venta ──────────────────────────────

    /** La negación se ata al plan que niega, no al mensaje entero. */
    public function test_la_negacion_es_del_plan_que_niega_y_no_del_mensaje(): void
    {
        $m = CM::fromArray(array_merge(CM::blank(), ['greeted' => ['at' => 'x', 'message_id' => 1]]));

        // «no quiero esperar más» no niega ningún plan: pide el mensual por su nombre.
        $p = Pol::decide(SalesIntents::HIGH_INTENT_CLOSE, P::RECOMMENDATION, $m, $this->catalogo(), [], 'no quiero esperar mas, dame el plan mensual');
        $this->assertSame(4, $p['hard_required_plan'], 'quien pide un plan por su nombre recibe otro');
        $this->assertFalse($p['wants_alternative']);
        $this->assertNotContains(22, $p['forbidden_plan_ids'], 'el insignia quedó vetado sin que nadie lo rechazara');

        // Dos planes: uno elegido y otro negado.
        $p = Pol::decide(SalesIntents::PRICING_QUESTION, P::RECOMMENDATION, $m, $this->catalogo(), [], 'el plan mensual esta bien, no me gusta el trimestre');
        $this->assertSame(4, $p['hard_required_plan']);
        $this->assertContains(5, $p['forbidden_plan_ids'], 'el plan negado siguió permitido');
        $this->assertNotContains(4, $p['forbidden_plan_ids']);

        // Negado DETRÁS del nombre.
        $p = Pol::decide(SalesIntents::PRICE_OBJECTION, P::RECOMMENDATION, $m, $this->catalogo(), [], 'el trimestre no me sirve, que mas hay');
        $this->assertContains(5, $p['forbidden_plan_ids']);
        $this->assertNull($p['hard_required_plan']);
        $this->assertTrue($p['wants_alternative']);
    }

    public function test_rechazar_el_plan_abre_otro_y_rechazar_la_venta_no(): void
    {
        $m = CM::fromArray(array_merge(CM::blank(), ['greeted' => ['at' => 'x', 'message_id' => 1]]));

        foreach (['no quiero total access - elite, muestrame otro', 'ese no, otro plan', 'muy caro, algo mas economico', 'no me sirve ese, hay otra opcion?'] as $texto) {
            $p = Pol::decide(SalesIntents::PRICE_OBJECTION, P::RECOMMENDATION, $m, $this->catalogo(), [], $texto);
            $this->assertTrue($p['wants_alternative'], "«{$texto}» no pidió otro");
            $this->assertNotContains(22, $p['allowed_plan_ids'], "«{$texto}» dejó al insignia como permitido");
            $this->assertNotSame([], $p['allowed_plan_ids'], 'sin alternativa real que ofrecer');
        }

        foreach (['gracias, no estoy interesado', 'no quiero nada, gracias', 'no me interesa'] as $texto) {
            $p = Pol::decide(SalesIntents::NOT_INTERESTED, P::RECOMMENDATION, $m, $this->catalogo(), [], $texto);
            $this->assertFalse($p['wants_alternative'], "«{$texto}» se leyó como «muéstrame otro»");
            $this->assertNull($p['required_plan_id'], "«{$texto}» recibió un plan");
        }
    }
}
