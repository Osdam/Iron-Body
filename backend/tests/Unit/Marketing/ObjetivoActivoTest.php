<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy as Pol;
use App\Services\Marketing\Ultron\ConversationMemory as CM;
use App\Services\Marketing\Ultron\ConversationMemoryService as Mem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * INVARIANTES DEL OBJETIVO ACTIVO, LA PREFERENCIA COMERCIAL Y EL PRECIO EN DUDA.
 *
 * Son invariantes de negocio, no frases: tienen que ponerse en rojo si
 * mañana alguien hace que una visita a medias vuelva a admitir un plan, que
 * el insignia vuelva a imponerse donde nadie pidió plan, o que dudar de un
 * precio se trate como una objeción en vez de contestarse con el precio.
 */
class ObjetivoActivoTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function catalogo(): array
    {
        return [
            ['id' => 22, 'name' => 'TOTAL ACCESS - ELITE', 'is_recommended' => true, 'benefits' => ['Acceso completo']],
            ['id' => 2, 'name' => 'Plan Semana', 'is_recommended' => false, 'benefits' => ['Siete días']],
            ['id' => 4, 'name' => 'Plan Mensual', 'is_recommended' => false, 'benefits' => ['Acceso ilimitado']],
        ];
    }

    private function conVisitaAMedias(array $data = []): CM
    {
        return CM::fromArray(array_merge(CM::blank(), ['greeted' => ['at' => 'x', 'message_id' => 1]]))
            ->setActiveGoal(Mem::GOAL_COURTESY, Mem::GOAL_COLLECTING, $data, '2026-09-22T04:51:00+00:00');
    }

    // ── 1 · con la visita a medias, el turno no vende ─────────────────────────

    public function test_con_la_visita_a_medias_no_se_recomienda_ningun_plan(): void
    {
        $p = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $this->conVisitaAMedias(), $this->catalogo(), [], 'me gustaria el dia de mañana en la noche');

        $this->assertNull($p['required_plan_id'], 'impuso un plan con la visita a medias');
        $this->assertNull($p['preferred_commercial_plan'], 'la preferencia comercial sobrevivió a la visita');
        $this->assertContains('plan_recommendation', $p['forbidden_actions']);
        $this->assertContains('checkout', $p['forbidden_actions']);
        $this->assertContains('continue_goal', $p['allowed_next_actions']);
        $this->assertNotContains('recommend_plan', $p['allowed_next_actions']);
        $this->assertSame(Mem::GOAL_COURTESY, $p['active_goal']['kind']);
        $this->assertFalse($p['resume_goal_after_answer']);
    }

    public function test_un_borrador_que_vende_con_la_visita_a_medias_incumple(): void
    {
        $p = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $this->conVisitaAMedias(), $this->catalogo(), [], 'mañana en la noche');

        // El borrador REAL de la prueba física, ya con el precio puesto.
        $v = Pol::violations('Para tu visita mañana en la noche, ten en cuenta que abrimos hasta las 10. El Plan Semana te ofrece acceso por $80.000 COP. ¿Quieres que te explique más?', $p, $this->catalogo());
        $this->assertContains(Pol::VIOLACION_OBJETIVO_PERDIDO, $v);

        // Y uno que sigue con la visita, no.
        $this->assertSame([], Pol::violations('Perfecto, mañana en la noche. ¿A qué hora te queda bien pasar?', $p, $this->catalogo()));
    }

    public function test_el_respaldo_de_la_visita_pide_solo_lo_que_falta(): void
    {
        $sinNada = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $this->conVisitaAMedias(), $this->catalogo(), [], 'ok');
        $conDia = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $this->conVisitaAMedias(['date' => '2026-09-23']), $this->catalogo(), [], 'ok');
        $conHora = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $this->conVisitaAMedias(['time' => '19:00']), $this->catalogo(), [], 'ok');

        $this->assertStringContainsString('qué día y a qué hora', Pol::courtesyReply($sinNada));
        $this->assertStringContainsString('solo me falta la hora', Pol::courtesyReply($conDia));
        $this->assertStringContainsString('solo me falta el día', Pol::courtesyReply($conHora));

        // Ninguno afirma que la visita quedó agendada ni confirmada: eso lo
        // confirma una persona, y la guarda de cortesía lo mataría.
        $guard = new OutboundContentGuard;
        foreach ([$sinNada, $conDia, $conHora] as $pol) {
            $this->assertNull($guard->courtesyClaimIn((string) Pol::courtesyReply($pol)), 'el respaldo afirma una cita que nadie confirmó');
            $this->assertNull($guard->courtesyClaimIn((string) Pol::courtesyResumption($pol)));
        }
    }

    // ── 2 · la pregunta incidental se contesta y se retoma la visita ──────────

    public function test_una_pregunta_de_precio_con_la_visita_a_medias_se_contesta_y_se_retoma(): void
    {
        $p = Pol::decide(SalesIntents::PRICE_OBJECTION, P::DISCOVERY, $this->conVisitaAMedias(['date' => '2026-09-23']), $this->catalogo(), [], 'seguro que el plan semana cuesta eso?');

        $this->assertTrue($p['answer_first'], 'la duda de precio no se contesta primero');
        $this->assertTrue($p['price_verification']);
        $this->assertSame(2, $p['required_plan_id'], 'el plan en duda no es el que la persona nombró');
        $this->assertSame('named_by_person', $p['required_plan_source'], 'la prioridad comercial podría pisar el plan en duda');
        $this->assertNotContains('plan_recommendation', $p['forbidden_actions'], 'con la persona preguntando por un plan, hablar de planes no está prohibido');
        $this->assertTrue($p['resume_goal_after_answer'], 'la visita no se retoma después de contestar');
        $this->assertContains('resume_goal', $p['allowed_next_actions']);

        // Hablar del plan en duda NO incumple el objetivo: la persona lo sacó.
        $this->assertSame([], Pol::violations('El Plan Semana cuesta $45.000 COP.', $p, $this->catalogo()));
        $this->assertTrue(Pol::mentionsVisit('Y sobre tu visita: ya tengo el día, solo me falta la hora.'));
        $this->assertFalse(Pol::mentionsVisit('El Plan Semana cuesta $45.000 COP.'));
    }

    /** @return array<string,array{0:string}> */
    public static function dudasDePrecio(): array
    {
        return [
            'seguro que cuesta' => ['seguro que el plan semana cuesta eso?'],
            'cuesta eso' => ['y cuesta eso?'],
            'me dijiste y luego' => ['me dijiste que costaba 80 y luego 45'],
            'dos cifras con mil' => ['porque en el aterior mensaje me dices que cuesta 80mil y luego que 45mil'],
            'dos cifras con k' => ['antes 80k ahora 45k, cual es?'],
            'precio real' => ['cual es el precio real del mensual'],
            'en serio vale' => ['en serio vale eso el mensual?'],
        ];
    }

    #[DataProvider('dudasDePrecio')]
    public function test_dudar_de_un_precio_es_verificacion_y_no_objecion(string $texto): void
    {
        $memoria = CM::fromArray(array_merge(CM::blank(), ['greeted' => ['at' => 'x', 'message_id' => 1]]))->discussPlan(2);
        $p = Pol::decide(SalesIntents::PRICE_OBJECTION, P::BARRIER_DISCOVERY, $memoria, $this->catalogo(), [], $texto);

        $this->assertTrue($p['price_verification'], "no se leyó como verificación: «{$texto}»");
        $this->assertTrue($p['answer_first']);
        $this->assertNotNull($p['required_plan_id'], 'sin plan no hay precio que verificar');
        $this->assertNotSame(22, $p['required_plan_id'], 'contestó la duda cotizando el insignia');
    }

    public function test_decir_que_esta_caro_sigue_siendo_una_objecion_y_no_una_verificacion(): void
    {
        $memoria = CM::fromArray(array_merge(CM::blank(), ['greeted' => ['at' => 'x', 'message_id' => 1]]))->discussPlan(2);
        $p = Pol::decide(SalesIntents::PRICE_OBJECTION, P::RECOMMENDATION, $memoria, $this->catalogo(), [], 'uy no, esta muy caro');

        $this->assertFalse($p['price_verification']);
        $this->assertTrue($p['wants_alternative']);
    }

    // ── 3 · preferencia comercial ≠ obligación ────────────────────────────────

    public function test_el_insignia_es_preferencia_cuando_se_habla_de_planes_y_obligacion_solo_si_lo_nombran(): void
    {
        $saludada = CM::fromArray(array_merge(CM::blank(), ['greeted' => ['at' => 'x', 'message_id' => 1]]));

        $pidePlanes = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $saludada, $this->catalogo(), [], 'que planes manejan?');
        $this->assertSame(22, $pidePlanes['preferred_commercial_plan']);
        $this->assertSame(22, $pidePlanes['required_plan_id'], 'quien pide planes recibe el insignia primero');
        $this->assertSame('commercial_priority', $pidePlanes['required_plan_source']);
        $this->assertNull($pidePlanes['hard_required_plan'], 'nadie nombró un plan: no hay obligación factual');

        $nombra = Pol::decide(SalesIntents::PRICING_QUESTION, P::DISCOVERY, $saludada, $this->catalogo(), [], 'cuanto vale el plan semana?');
        $this->assertSame(2, $nombra['hard_required_plan'], 'la persona nombró un plan: eso obliga');
        $this->assertSame(2, $nombra['required_plan_id']);
        $this->assertSame('named_by_person', $nombra['required_plan_source']);
        $this->assertSame(22, $nombra['preferred_commercial_plan'], 'la preferencia sigue existiendo, pero no manda');

        $rechazo = Pol::decide(SalesIntents::GENERAL_INFO, P::RECOMMENDATION, $saludada, $this->catalogo(), [], 'no quiero ese, muestrame otro plan');
        $this->assertNull($rechazo['preferred_commercial_plan'], 'la preferencia sobrevivió a un rechazo');
        $this->assertContains(22, $rechazo['forbidden_plan_ids']);

        $saluda = Pol::decide(SalesIntents::GREETING, P::NEW_LEAD, CM::fromArray(null), $this->catalogo(), [], 'hola buenas noches');
        $this->assertNull($saluda['required_plan_id'], 'un saludo no lleva plan obligatorio');
        $this->assertContains('plan_recommendation', Pol::decide(SalesIntents::GENERAL_INFO, P::NEW_LEAD, CM::fromArray(null), $this->catalogo(), [], 'hola, quisiera informacion del gimnasio')['forbidden_actions']);
    }

    // ── 4 · pedir información y recibir un hecho ─────────────────────────────

    public function test_quien_pide_informacion_y_no_la_tiene_recibe_un_hecho_del_gimnasio(): void
    {
        $hechos = ['Iron Body Neiva es un centro de acondicionamiento físico en Neiva.', 'En Iron Body entrenas en un lugar serio, con estructura y acompañamiento.'];
        $p = Pol::decide(SalesIntents::GENERAL_INFO, P::NEW_LEAD, CM::fromArray(null), $this->catalogo(), [], 'hola buenas noches, quisiera mas informacion del gimnasio');
        $this->assertSame(['gym_identity'], $p['required_facts']);

        // El borrador REAL: relleno más pregunta, ni un hecho.
        $real = 'Muy buenas noches, gracias por escribirnos. Para darte la mejor información sobre el gimnasio, ¿me puedes contar qué objetivo tienes con tu entrenamiento?';
        $this->assertContains(Pol::VIOLACION_SIN_HECHO, Pol::violations($real, $p, $this->catalogo(), $hechos));

        // Con un hecho del CRM dentro, cumple.
        $conHecho = 'Buenas noches. Iron Body Neiva es un centro de acondicionamiento físico en Neiva, con acompañamiento desde el primer día. ¿Qué te gustaría saber primero?';
        $this->assertSame([], Pol::violations($conHecho, $p, $this->catalogo(), $hechos));

        // Sin lista de hechos no se puede medir, y no se mide.
        $this->assertSame([], Pol::violations($real, $p, $this->catalogo(), []));
    }

    public function test_el_hecho_no_se_exige_si_ya_se_dio_o_si_no_lo_pidieron(): void
    {
        $yaInformada = CM::fromArray(null)->deliverFact('kb:business_identity:1', 'x', 1);
        $p = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $yaInformada, $this->catalogo(), [], 'quisiera mas informacion');
        $this->assertSame([], $p['required_facts'], 'volvió a exigir un hecho que ya se dio');

        $noPide = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, CM::fromArray(null), $this->catalogo(), [], 'me gustaria el dia de mañana en la noche');
        $this->assertSame([], $noPide['required_facts'], '«mañana en la noche» no pide información del gimnasio');
    }

    // ── 5 · el objetivo caduca al leer, no al escribir ────────────────────────

    public function test_los_planes_nombrados_se_leen_por_palabra_completa_y_en_orden(): void
    {
        $this->assertSame([2, 4], Pol::plansNamedIn('El Plan Semana y luego el Plan Mensual.', $this->catalogo()));
        $this->assertSame([], Pol::plansNamedIn('Puedes venir toda la semana; el mes que viene abrimos más temprano.', $this->catalogo()));
        $this->assertSame([22], Pol::plansNamedIn('Te recomiendo el TOTAL ACCESS - ELITE.', $this->catalogo()));
    }

    // ── 6 · la etiqueta no decide si el turno es de la visita ─────────────────

    /** @return array<string,array{0:string}> */
    public static function preguntasConEtiquetaGeneral(): array
    {
        return [
            'precio de una clase' => ['cuanto cuestan las clases de yoga?'],
            'un servicio' => ['tienen entrenador personal?'],
            'forma de pago, sin signo' => ['puedo pagar en efectivo en la sede'],
        ];
    }

    /**
     * El modelo etiquetó TODO como `general_info` en la 254, incluida la
     * pregunta. Con esa etiqueta `plan_recommendation` queda prohibido por la
     * regla de información, y `violations()` deducía de ahí que la visita
     * mandaba: la respuesta con el plan y su precio se tiraba y salía la
     * pregunta por el día y la hora. La decisión se lee ahora con su nombre.
     */
    #[DataProvider('preguntasConEtiquetaGeneral')]
    public function test_con_la_etiqueta_general_info_una_pregunta_no_es_un_secuestro(string $texto): void
    {
        $p = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $this->conVisitaAMedias(['date' => '2026-09-23']), $this->catalogo(), [], $texto);

        $this->assertFalse($p['goal_takes_turn'], "«{$texto}» se tomó por un turno de la visita");
        $this->assertTrue($p['resume_goal_after_answer'], "«{$texto}» no retoma la visita");
        $this->assertContains('resume_goal', $p['allowed_next_actions']);
        // La regla de `general_info` sigue prohibiendo recomendar: es justo lo
        // que contaminaba la deducción, y por eso no puede ser la señal.
        $this->assertContains('plan_recommendation', $p['forbidden_actions']);

        $this->assertSame([], Pol::violations('Las clases de yoga estan incluidas en el Plan Mensual, que cuesta $80.000 COP.', $p, $this->catalogo()));
    }

    public function test_concretar_la_visita_sigue_siendo_un_turno_de_la_visita(): void
    {
        $p = Pol::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $this->conVisitaAMedias(), $this->catalogo(), [], 'mañana en la noche');

        $this->assertTrue($p['goal_takes_turn']);
        $this->assertFalse($p['resume_goal_after_answer']);
        $this->assertContains(Pol::VIOLACION_OBJETIVO_PERDIDO, Pol::violations('El Plan Mensual cuesta $80.000 COP.', $p, $this->catalogo()));
    }
}
