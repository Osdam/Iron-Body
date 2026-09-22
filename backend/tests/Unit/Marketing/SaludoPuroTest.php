<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy as Pol;
use App\Services\Marketing\Ultron\ConversationMemory as CM;
use App\Services\Marketing\Ultron\CustomerIntelligenceService as CI;
use App\Services\Marketing\Ultron\StrategyContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * UN SALUDO Y NADA MÁS TIENE PRIORIDAD CONVERSACIONAL.
 *
 * Dos turnos reales de la misma noche: «Hola buenos dias» con la memoria
 * comercial del lead en READY_TO_BUY. Las pistas del estratega empujaban a
 * cerrar, el redactor preguntaba el objetivo o hablaba de pago, el Critic lo
 * tumbaba y Laravel caía en el respaldo genérico («dime qué necesitas
 * —planes, horarios…»). Aquí se fija que el saludo puro entra en modo
 * recepción en las tres capas deterministas: la detección, las pistas y la
 * política. La memoria del lead no se borra: deja de decidir ese turno.
 */
class SaludoPuroTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function catalogo(): array
    {
        return [
            ['id' => 22, 'name' => 'TOTAL ACCESS - ELITE', 'is_recommended' => true, 'benefits' => ['Acceso completo']],
            ['id' => 4, 'name' => 'Plan Mensual', 'is_recommended' => false, 'benefits' => ['Acceso ilimitado']],
        ];
    }

    /** El perfil de un lead que ya quiso pagar: lo que contaminaba el saludo. */
    private function clienteListo(): array
    {
        return [
            'lead_temperature' => CI::READY,
            'customer_lifecycle' => CI::READY_TO_BUY,
            'renewal_window' => false,
            'known' => ['objective' => 'bajar grasa', 'has_pending_payment_link' => false, 'plans_discussed' => [22]],
            'inferred' => ['plan_interest' => 22, 'buying_signals' => ['commitment']],
            'unknown' => ['experience_level', 'time_constraints'],
        ];
    }

    // ── 1 · qué es un saludo puro ───────────────────────────────────────────

    /** @return array<string,array{0:string,1:bool}> */
    public static function saludos(): array
    {
        return [
            'hola buenos dias' => ['Hola buenos dias', true],
            'hola' => ['hola', true],
            'buenas con signo' => ['Buenas!', true],
            'que tal con signos' => ['hola, ¿qué tal?', true],
            'con emoji' => ['Hola 😊', true],
            'de nuevo' => ['hola de nuevo', true],
            'a la marca' => ['buenas noches iron body', true],
            'muy buenos dias como estas' => ['hola muy buenos dias como estas', true],
            // Con cualquier otra cosa deja de ser un saludo puro.
            'hola quiero informacion' => ['hola quiero informacion del gimnasio', false],
            'buenos dias cuanto vale' => ['buenos dias cuanto vale', false],
            'saludo y pregunta de clase' => ['que tal, tienen yoga?', false],
            'quiero inscribirme' => ['Quiero inscribirme', false],
            'me parece caro' => ['Me parece caro', false],
            'vacio' => ['', false],
            // El relleno solo NO es un saludo: es contenido («¿planes, clases o cómo empezar?» → «gimnasio»).
            'gimnasio a secas' => ['gimnasio', false],
            'gym a secas' => ['gym', false],
            'la marca a secas' => ['iron body', false],
            'de nuevo a secas' => ['de nuevo', false],
            'muy a secas' => ['muy', false],
            'equipo a secas' => ['equipo', false],
        ];
    }

    #[DataProvider('saludos')]
    public function test_un_saludo_puro_es_solo_un_saludo(string $texto, bool $puro): void
    {
        $this->assertSame($puro, SalesIntents::isPureGreeting($texto), "«{$texto}»");
    }

    // ── 2 · las pistas entran en recepción sin borrar la memoria ────────────

    public function test_con_un_saludo_puro_las_pistas_no_empujan_a_cerrar(): void
    {
        $resolved = ['type' => 'none'];
        $sinSaludo = StrategyContract::hints($this->clienteListo(), $resolved, true, SalesIntents::GREETING, [], []);
        $conSaludo = StrategyContract::hints($this->clienteListo(), $resolved, true, SalesIntents::GREETING, [], [], true);

        // Lo que pasaba: el lead listo convertía el saludo en un cierre.
        $this->assertTrue($sinSaludo['hot_lead_fast_path']);
        $this->assertSame('closing', $sinSaludo['lifecycle_mode']);
        $this->assertTrue($sinSaludo['should_recommend_now']);
        $this->assertSame(0, $sinSaludo['question_budget']);
        $this->assertFalse($sinSaludo['greeting_only']);

        // Lo que pasa ahora: se recibe.
        $this->assertTrue($conSaludo['greeting_only']);
        $this->assertFalse($conSaludo['hot_lead_fast_path']);
        $this->assertNull($conSaludo['fast_path_kind']);
        $this->assertSame('consultative', $conSaludo['lifecycle_mode']);
        $this->assertFalse($conSaludo['should_recommend_now']);
        $this->assertFalse($conSaludo['open_objection']);
        $this->assertSame([], $conSaludo['may_ask']);
        $this->assertSame(1, $conSaludo['question_budget'], 'la apertura es una pregunta');
        // Los hechos del lead siguen ahí: no es memoria borrada.
        $this->assertTrue($conSaludo['payment_possible']);
        $this->assertFalse($conSaludo['do_not_sell']);
    }

    // ── 3 · la política recibe y no vende ───────────────────────────────────

    public function test_la_politica_de_un_saludo_puro_recibe_y_no_vende(): void
    {
        $memoria = CM::fromArray(CM::blank());
        $p = Pol::decide(SalesIntents::GREETING, P::NEW_LEAD, $memoria, $this->catalogo(), [], 'Hola buenos dias');

        $this->assertTrue($p['reception_mode']);
        $this->assertTrue($p['greet_required']);
        $this->assertTrue($p['welcome_required']);
        $this->assertFalse($p['answer_first'], 'un saludo no es una pregunta que responder');
        $this->assertNull($p['required_plan_id']);
        $this->assertNull($p['preferred_commercial_plan'], 'el insignia no se recomienda a quien dice hola');
        foreach (['discovery', 'plan_recommendation', 'payment', 'checkout', 'registration'] as $accion) {
            $this->assertContains($accion, $p['forbidden_actions'], "«{$accion}» no está prohibida en el saludo");
        }
        $this->assertSame(['greet', 'welcome', 'open_question'], $p['allowed_next_actions']);
        $this->assertSame([], $p['required_facts']);
    }

    public function test_un_saludo_con_algo_mas_no_es_recepcion(): void
    {
        $memoria = CM::fromArray(CM::blank());

        $info = Pol::decide(SalesIntents::GENERAL_INFO, P::NEW_LEAD, $memoria, $this->catalogo(), [], 'Hola buenos dias, quiero informacion del gimnasio');
        $this->assertFalse($info['reception_mode']);
        $this->assertTrue($info['answer_first'], '«quiero información» se contesta primero');

        $inscribirse = Pol::decide(SalesIntents::HIGH_INTENT_CLOSE, P::DISCOVERY, $memoria, $this->catalogo(), [], 'Quiero inscribirme');
        $this->assertFalse($inscribirse['reception_mode']);
        $this->assertSame(22, $inscribirse['preferred_commercial_plan'], 'la prioridad comercial sigue intacta fuera del saludo');

        // Y un «greeting» del clasificador sobre un texto que no es saludo puro tampoco.
        $raro = Pol::decide(SalesIntents::GREETING, P::NEW_LEAD, $memoria, $this->catalogo(), [], 'hola, cuanto vale el mensual');
        $this->assertFalse($raro['reception_mode']);
    }

    // ── 4 · lo que incumple un saludo ───────────────────────────────────────

    /** @return array<string,array{0:string,1:bool}> */
    public static function borradoresDeSaludo(): array
    {
        return [
            'bienvenida con apertura' => ['Hola, muy buenos días. Bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?', false],
            'solo la apertura' => ['¿En qué te podemos ayudar hoy?', false],
            'bienvenida sin pregunta' => ['Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderte.', false],
            // Lo que salió en producción y no puede volver a salir.
            'pregunta el objetivo' => ['Hola, muy buenos dias. ¿Qué objetivo tienes en mente para empezar a entrenar en Iron Body Neiva?', true],
            'manda a pagar por la app' => ['Buenas tardes, te recuerdo que puedes descargar la app Iron Body Workout para pagar y empezar cuando quieras.', true],
            'recomienda el insignia' => ['Hola, buenas tardes. El TOTAL ACCESS - ELITE es ideal para ti.', true],
            'cotiza' => ['Hola. El mensual está en {{PLAN_PRICE}}.', true],
            'inscripcion' => ['Hola, ¿quieres inscribirte hoy?', true],
            'objetivo fisico' => ['Hola, ¿buscas bajar grasa o ganar músculo?', true],
        ];
    }

    #[DataProvider('borradoresDeSaludo')]
    public function test_un_saludo_que_vende_o_descubre_incumple_y_una_bienvenida_no(string $borrador, bool $incumple): void
    {
        $p = Pol::decide(SalesIntents::GREETING, P::NEW_LEAD, CM::fromArray(CM::blank()), $this->catalogo(), [], 'Hola buenos dias');
        $fallos = Pol::violations($borrador, $p, $this->catalogo());

        $this->assertSame($incumple, in_array(Pol::VIOLACION_SALUDO_COMERCIAL, $fallos, true), "«{$borrador}» → ".json_encode($fallos));
        if (! $incumple) {
            $this->assertSame([], $fallos, 'la bienvenida no puede incumplir ninguna otra regla');
        }
    }

    /** Sin modo recepción, la regla del saludo comercial no existe: lo demás sigue igual. */
    public function test_fuera_del_saludo_la_regla_no_aplica(): void
    {
        $p = Pol::decide(SalesIntents::HIGH_INTENT_CLOSE, P::RECOMMENDATION, CM::fromArray(CM::blank()), $this->catalogo(), [], 'Quiero inscribirme');
        $fallos = Pol::violations('Perfecto. El TOTAL ACCESS - ELITE es el que más recomendamos, ¿te cuento cómo empezar?', $p, $this->catalogo());

        $this->assertNotContains(Pol::VIOLACION_SALUDO_COMERCIAL, $fallos);
    }
}
