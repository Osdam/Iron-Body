<?php

namespace Tests\Unit\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\ReferenceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A4 · H4-10 · ¿LA PERSONA PIDE EL PANORAMA DE PLANES? (plan del arquitecto
 * `plan-arquitecto-a4-resto.md` §3, decisión P-1 del lead; endurecido tras las
 * revisiones, G1: B-1, S-4, I-2 e I-3). Función pura: el texto, la política
 * del turno (su intención validada y sus modos) y la resolución del referente.
 * Cierra con casos cada lista: los filtros, las peticiones, las exclusiones
 * (negativa, «ya vi», recomendación) y los otros temas del mismo mensaje.
 */
class CommercialTurnPolicyPanoramaTest extends TestCase
{
    /** La política de un turno de información sin modos (la intención va dentro: G1.1). */
    private const POLITICA = ['intent' => SalesIntents::GENERAL_INFO, 'reception_mode' => false, 'memory_mode' => false, 'goal_takes_turn' => false];

    /** @return array<string, array{string}> */
    public static function panoramas(): array
    {
        return [
            'qué planes tienen' => ['¿Qué planes tienen?'],
            'cuáles planes manejan' => ['¿Cuáles planes manejan?'],
            'qué membresías hay' => ['¿Qué membresías hay?'],
            'planes hay' => ['¿Y planes hay?'],
            'membresías ofrecen' => ['¿Membresías ofrecen?'],
            'qué ofrecen' => ['¿Qué ofrecen?'],
            'los planes' => ['Quisiera saber los planes'],
            'todos los planes' => ['Mándame todos los planes'],
            'el de la prueba real' => ['¿Qué planes tienen y cuánto cuesta la mensualidad?'],
            'con precio en plural' => ['¿Qué planes tienen y cuánto cuestan?'],
            'un plan nombrado y qué otros hay' => ['¿Cuánto cuesta el Mensual y qué otros hay?'],
            'qué otros planes tienen' => ['¿Qué otros planes tienen?'],
            // I-2 (G1.3): las formas corrientes de pedir el panorama que antes no lo eran.
            'cuáles son los precios' => ['¿Cuáles son los precios?'],
            'qué precios manejan' => ['¿Qué precios manejan?'],
            'info de precios' => ['Info de precios'],
            'las tarifas' => ['Las tarifas'],
            'cuánto valen las membresías' => ['¿Cuánto valen las membresías?'],
            'tienen planes' => ['¿Tienen planes?'],
            // Las demás peticiones de la lista cerrada (G1.3).
            'opciones DE PLANES' => ['¿Qué opciones de planes tienen?'],
            'opciones DE MEMBRESÍAS' => ['Quiero ver las opciones de membresías'],
            'la lista de planes' => ['Mándame la lista de planes'],
            'cuáles son los planes' => ['¿Cuáles son los planes?'],
            'conocer los planes y sus precios' => ['Quiero conocer los planes y sus precios'],
            'información de los planes' => ['Hola, quisiera información de los planes'],
            // EL MENSAJE ENTERO es solo eso (añadido del lead, 19-11): la forma más corriente por WhatsApp.
            'precios, a secas' => ['Precios?'],
            'planes, a secas' => ['¿Planes?'],
            'y los planes, a secas' => ['¿Y los planes?'],
            'saludo y precios' => ['Hola, precios por favor'],
            'planes y precios' => ['Buenas tardes, planes y precios'],
            'info planes' => ['Info planes'],
        ];
    }

    #[DataProvider('panoramas')]
    public function test_lo_que_es_panorama(string $texto): void
    {
        $this->assertTrue(CommercialTurnPolicy::pideElPanorama($texto, self::POLITICA, []), $texto);
    }

    /** @return array<string, array{string}> */
    public static function noPanoramas(): array
    {
        return [
            'un plan nombrado' => ['¿Cuánto cuesta el Mensual?'],
            'la mensualidad, en singular' => ['¿Cuánto cuesta la mensualidad?'],
            'el precio a secas' => ['¿Cuánto cuesta?'],
            'el precio de UN plan' => ['El precio del Mensual'],
            'qué otros días (no son planes)' => ['¿Qué otros días abren?'],
            'el horario' => ['¿Cuál es el horario?'],
            'qué incluye el plan' => ['¿Qué incluye el plan?'],
            'qué plan (singular) me recomiendas' => ['¿Qué plan me recomiendas?'],
            'plan para parejas (singular y filtro)' => ['¿Tienen plan para parejas?'],
            // La lista de filtros que excluyen: la lista no contestaría lo que se pregunta.
            'para estudiantes' => ['¿Qué planes tienen para estudiantes?'],
            'para parejas' => ['¿Qué planes tienen para parejas?'],
            'para dos' => ['¿Qué planes hay para dos personas?'],
            'para niños' => ['¿Qué planes tienen para niños?'],
            'para adultos mayores' => ['¿Qué planes hay para adultos mayores?'],
            'para familias' => ['¿Qué planes tienen para familias?'],
            'para empresas' => ['¿Qué planes manejan para empresas?'],
            'para principiantes' => ['¿Qué planes tienen para principiantes?'],
            'por días' => ['¿Qué planes tienen por días?'],
            'por semana' => ['¿Qué planes hay por semana?'],
            'de un mes' => ['¿Qué planes tienen de un mes?'],
            'por meses' => ['¿Qué planes manejan por meses?'],
            'con descuento' => ['¿Qué planes tienen con descuento?'],
            'promociones' => ['¿Qué planes hay en promoción?'],
            'con entrenador personalizado' => ['¿Qué planes tienen con entrenador personalizado?'],
            'familiar' => ['¿Tienen planes familiares?'],
            // G1.3 · «opciones» solo con «de planes / de membresías» (B-1). Antes, «Hola, ¿qué opciones tienen?» era panorama.
            'qué opciones tienen, sin «de planes»' => ['Hola, ¿qué opciones tienen?'],
            'opciones de pago' => ['¿Qué opciones de pago tienen?'],
            'opciones de horario' => ['¿Qué opciones de horario hay?'],
            'opciones para un objetivo' => ['¿Qué opciones hay para bajar de peso?'],
            // G1.3 · «los planes» a secas no basta: tiene que ir dentro de una petición de conocerlos.
            'los planes sin petición (mirando)' => ['Estuve mirando los planes'],
            'los planes sin petición (me gustaron)' => ['Me gustaron los planes'],
            // Con algo más que el sustantivo y la cortesía ya no es «el mensaje entero» (añadido del lead, 19-11).
            'precios de UN plan' => ['Precios del mensual'],
            'el precio, en singular y a secas' => ['Precio?'],
            'el plan, en singular y a secas' => ['¿Plan?'],
            'los planes con otra cosa detrás' => ['Los planes que vi ayer'],
            'un asesor sobre los planes' => ['Quiero hablar con un asesor sobre los planes'],
            'pago reclamado (S-4)' => ['Ya pagué uno de los planes y no me llega nada'],
            'queja (S-4)' => ['Quiero poner una queja por los planes, me cobraron de más'],
            'una persona del equipo (S-4)' => ['Quiero hablar con una persona del equipo sobre los planes'],
            // G1.3 · la negativa y el «ya vi / ya conozco».
            'no me interesan (B-1)' => ['No me interesan los planes, gracias'],
            'ya vi, no me interesan (S-4)' => ['Ya vi los planes, no me interesan'],
            'dejen de mandarme (S-4)' => ['Ya no quiero saber nada, dejen de mandarme los planes'],
            'gracias, ya vi (S-4)' => ['Gracias, ya vi los planes'],
            'ya conozco' => ['Ya conozco los planes, solo quería saludar'],
            'ya me los mandaron' => ['Ya me mandaron los planes'],
            // G1.3 · la petición de recomendación.
            'cuál de los planes me recomienda (B-1)' => ['¿Cuál de los planes me recomienda para bajar de peso?'],
            'ya vi, cuál me recomiendas (B-1)' => ['Ya vi los planes, ¿cuál me recomiendas?'],
            'qué planes me recomienda' => ['¿Qué planes me recomienda?'],
            'cuál me conviene de los planes' => ['De los planes que tienen, ¿cuál me conviene más?'],
        ];
    }

    #[DataProvider('noPanoramas')]
    public function test_lo_que_no_es_panorama(string $texto): void
    {
        $this->assertFalse(CommercialTurnPolicy::pideElPanorama($texto, self::POLITICA, []), $texto);
    }

    /**
     * G1.4 · UN SOLO TEMA (I-3). Si el mismo mensaje pregunta además otra cosa,
     * la lista no sustituye al borrador: cada detector de la lista cerrada con
     * su caso. Los tres primeros son de la política (`PREGUNTA_INCIDENTAL`,
     * `HABLA_DE_VISITA`) y de `HumanHandoffAuthority`; clases, medios de pago y
     * lesión son la lista nueva.
     *
     * @return array<string, array{string}>
     */
    public static function otroTema(): array
    {
        return [
            'el horario (PREGUNTA_INCIDENTAL)' => ['¿Cuál es el horario y cuánto cuestan los planes?'],
            'hasta qué hora (PREGUNTA_INCIDENTAL)' => ['¿Qué planes tienen y hasta qué hora abren?'],
            'la dirección (PREGUNTA_INCIDENTAL)' => ['¿Dónde quedan y qué planes tienen?'],
            'una visita (HABLA_DE_VISITA, R7)' => ['Quiero ir el sábado a las 10 de la mañana. ¿Qué planes tienen?'],
            'una persona (HumanHandoffAuthority)' => ['¿Qué planes tienen? Quiero hablar con un asesor'],
            'las clases' => ['¿Qué clases tienen y qué planes manejan?'],
            'los medios de pago' => ['¿Qué planes tienen y cómo se paga?'],
            'con tarjeta' => ['¿Qué planes tienen? ¿Reciben tarjeta?'],
            'una lesión' => ['Tengo una lesión de rodilla, ¿qué planes tienen?'],
            'un dolor' => ['¿Qué planes tienen? Me duele la espalda'],
        ];
    }

    #[DataProvider('otroTema')]
    public function test_con_otra_pregunta_en_el_mismo_mensaje_no_es_panorama(string $texto): void
    {
        $this->assertFalse(CommercialTurnPolicy::pideElPanorama($texto, self::POLITICA, []), $texto);
    }

    /**
     * G1.1 · LA INTENCIÓN VALIDADA, EN LISTA BLANCA (B-1, S-4): solo
     * `pricing_question`, `general_info`, `greeting` y `unknown`. Ninguna otra,
     * con el mismo texto que sí es panorama.
     */
    public function test_solo_con_las_intenciones_de_la_lista_blanca(): void
    {
        $blanca = [SalesIntents::PRICING_QUESTION, SalesIntents::GENERAL_INFO, SalesIntents::GREETING, SalesIntents::UNKNOWN];
        $this->assertSame($blanca, CommercialTurnPolicy::INTENCIONES_DEL_PANORAMA);
        foreach (SalesAgentDecisionSchema::INTENTS as $intent) {
            $this->assertSame(
                in_array($intent, $blanca, true),
                CommercialTurnPolicy::pideElPanorama('¿Qué planes tienen?', ['intent' => $intent] + self::POLITICA, []),
                $intent,
            );
        }
        $sinIntencion = self::POLITICA;
        unset($sinIntencion['intent']);
        $this->assertFalse(CommercialTurnPolicy::pideElPanorama('¿Qué planes tienen?', $sinIntencion, []), 'sin intención no se supone ninguna');
    }

    public function test_elegir_entre_opciones_dadas_no_es_panorama(): void
    {
        // El texto ya no es «De los planes, el primero»: «los planes» a secas dejó de ser panorama (G1.3).
        $this->assertFalse(CommercialTurnPolicy::pideElPanorama('¿Qué planes tienen? El primero', self::POLITICA, ['type' => ReferenceResolver::CHOOSE_PLAN, 'plan_id' => 3]));
        $this->assertTrue(CommercialTurnPolicy::pideElPanorama('¿Qué planes tienen? El primero', self::POLITICA, ['type' => ReferenceResolver::NONE]));
    }

    /** @return array<string, array{string}> */
    public static function modos(): array
    {
        return ['recepción' => ['reception_mode'], 'memoria' => ['memory_mode'], 'visita a medias' => ['goal_takes_turn']];
    }

    #[DataProvider('modos')]
    public function test_recepcion_memoria_y_visita_a_medias_no_son_panorama(string $modo): void
    {
        $this->assertFalse(CommercialTurnPolicy::pideElPanorama('¿Qué planes tienen?', [$modo => true] + self::POLITICA, []));
    }

    public function test_la_pregunta_de_precio(): void
    {
        $this->assertTrue(CommercialTurnPolicy::preguntaPorPrecio('¿Qué planes tienen y cuánto cuestan?'));
        $this->assertTrue(CommercialTurnPolicy::preguntaPorPrecio('¿Qué planes tienen y cuánto cuesta la mensualidad?'));
        $this->assertTrue(CommercialTurnPolicy::preguntaPorPrecio('Los planes y sus precios'));
        $this->assertFalse(CommercialTurnPolicy::preguntaPorPrecio('¿Qué planes tienen?'));
    }
}
