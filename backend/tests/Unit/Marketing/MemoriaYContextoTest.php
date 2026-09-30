<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\ConversationMemory as CM;
use App\Services\Marketing\Ultron\CourtesyAuthority;
use App\Services\Marketing\Ultron\CustomerIntelligenceService;
use App\Services\Marketing\Ultron\PromiseAuthority;
use App\Services\Marketing\Ultron\StrategyContract;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Las piezas puras del refinamiento: qué es una pregunta de memoria, qué
 * fecha y hora dijo la persona con sus palabras, qué frases dan por
 * agendada una visita que no lo está, y qué pregunta toca hacer.
 */
class MemoriaYContextoTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Pregunta de memoria ───────────────────────────────────────────────────

    public static function preguntasDeMemoria(): array
    {
        return [
            'me acuerdas' => ['me acuerdas mañana a qué horas agendé', true],
            'me recuerdas' => ['hola, me recuerdas a qué hora era mi visita?', true],
            'a que hora quede' => ['a qué hora quedé para la visita?', true],
            'que habiamos quedado de la visita' => ['qué habíamos quedado de la visita?', true],
            'se me borro, la cita' => ['se me borró la conversación, cuándo era mi cita?', true],
            'cuando era mi cita' => ['cuándo era mi cita?', true],
            'en que quedamos con la cortesia' => ['en qué quedamos con la cortesía?', true],
            'que dia agende' => ['qué día agendé?', true],
            'recuerdas la hora' => ['recuerdas a qué hora quedamos?', true],
            // Memoria, pero no sobre un compromiso: se contesta como lo que es (precio, enlace, plan).
            'recuerda un precio' => ['me recuerdas cuánto vale el mensual?', false],
            'lo hablado y el link' => ['sobre lo que hablamos ayer, mándame el link', false],
            'como quedamos a secas' => ['entonces cómo quedamos?', false],
            'que habiamos quedado a secas' => ['qué habíamos quedado?', false],
            'se me borro a secas' => ['se me borró la conversación, qué me dijiste?', false],
            'saludo puro' => ['hola buenos días', false],
            'pregunta de precio' => ['a qué precio está el plan?', false],
            'pregunta de horario' => ['a qué hora abren?', false],
            'hora de una clase' => ['a qué hora es la clase de hoy?', false],
            'hora futura' => ['a qué hora puedo ir mañana?', false],
            'agenda nueva' => ['quiero agendar para mañana a las 2', false],
            'agenda nueva con visita' => ['quiero agendar una visita para mañana', false],
            'objetivo' => ['quiero bajar de peso', false],
            // «Era» sin nada que ver con un compromiso propio: no es memoria.
            'el feriado' => ['qué día era el feriado?', false],
            'la hora de la visita, en presente' => ['a qué hora tengo la visita?', true],
            'mi visita es a que hora' => ['mi visita es a qué hora?', true],
            // Formas frecuentes que se escapaban.
            'para que dia quedo' => ['¿para qué día quedó la visita?', true],
            'quedo para que dia' => ['la visita quedó para qué día?', true],
            'me dijiste que era' => ['a qué hora me dijiste que era la visita', true],
            'me podrias recordar' => ['me podrías recordar la hora de la cita?', true],
            'mi visita era hoy' => ['mi visita era hoy?', true],
            'tenia que ir' => ['a qué hora tenía que ir?', true],
            // Lo prospectivo es pedir la visita, no recordarla.
            'prospecto pregunta la hora' => ['Hola, ¿a qué hora es la visita de cortesía?', false],
            'prospecto propone' => ['¿A qué hora es la visita de cortesía? ¿Puedo ir mañana a las 6?', false],
            'que dia seria' => ['quiero hacer la visita de cortesía, ¿qué día sería posible?', false],
            'cuando es la cita sin posesivo' => ['cuándo es la cita?', false],
            'como funciona la cortesia' => ['¿cuándo es la visita de cortesía? ¿cómo funciona?', false],
            // Cambiar o cancelar es la herramienta de cortesía, no una respuesta de memoria.
            'recuerda y cancela' => ['cuando era mi visita? ya no puedo ir, cancelala por favor', false],
            'recuerda y cambia' => ['a que hora era mi visita? necesito cambiarla para el sabado a las 10', false],
            'recuerda y pasa' => ['se me olvido a que hora quede para la cita, la puedo pasar para el viernes a las 6pm?', false],
            // Lo que no es la visita.
            'la clase que reserve' => ['a qué hora es la clase que reservé', false],
            'cita con el entrenador' => ['cuándo es mi cita con el entrenador', false],
            // Segunda revisión: lo que parece memoria y es otra cosa, y lo que sí lo es.
            'ya no voy a poder ir' => ['ya no voy a poder ir a la visita', false],
            'puedo ir con mi hijo' => ['¿puedo ir a la visita con mi hijo?', false],
            'opina de la visita hecha' => ['la visita fue muy buena, ¿cuándo empiezo?', false],
            'que llevo' => ['me recuerdas qué llevo a la visita?', false],
            'otra cita: la valoracion' => ['a qué hora era mi valoración?', false],
            'da una hora: propone' => ['mi visita es a las 10?', false],
            'seria si voy' => ['¿a qué hora sería mi visita si voy el sábado?', false],
            'la de otra persona' => ['cuándo era la visita de mi mamá?', false],
            'el link de pago' => ['me recuerdas el link de pago?', false],
            'si es gratis' => ['la visita es gratis?', false],
            'recuerda y mueve' => ['a qué hora era la visita? la quiero mover para el viernes', false],
            'la sede' => ['me recuerdas en qué sede es la visita?', false],
            'se me olvido a que hora agende' => ['se me olvidó a qué hora agendé', true],
            'mi cita es hoy' => ['mi cita es hoy?', true],
            'me confirmas mi cita' => ['me confirmas mi cita?', true],
            // Tercera revisión: pedir mover o cancelar sin esas palabras tampoco es memoria.
            'la dejamos para mañana' => ['mi cita es hoy? no alcanzo, ¿la dejamos para mañana?', false],
            'se puede el viernes' => ['¿Cuándo era mi cita? ¿Se puede el viernes mejor?', false],
            'un inconveniente' => ['¿cuándo es mi visita? tengo un inconveniente, la podemos dejar para el lunes', false],
            'me enfermé' => ['mi cita es hoy? me enfermé, no puedo', false],
            'mejor olvídalo' => ['a qué hora era mi visita? mejor olvídalo', false],
            'no voy a poder' => ['¿mi cita es mañana? es que no voy a poder', false],
            'se me complicó' => ['¿mi visita es hoy? es que se me complicó', false],
        ];
    }

    #[DataProvider('preguntasDeMemoria')]
    public function test_reconoce_una_pregunta_de_memoria(string $texto, bool $esperado): void
    {
        $this->assertSame($esperado, SalesIntents::isMemoryQuestion($texto), $texto);
    }

    public function test_en_modo_memoria_la_politica_prohibe_vender_y_lo_detecta(): void
    {
        $planes = [['id' => 7, 'name' => 'TOTAL ACCESS - ELITE', 'price' => 180000, 'is_recommended' => true]];
        $p = CommercialTurnPolicy::decide(SalesIntents::SCHEDULE_QUESTION, 'DISCOVERY', CM::empty(), $planes, ['type' => 'none'], 'me recuerdas a qué hora era mi visita?');

        $this->assertTrue($p['memory_mode']);
        $this->assertFalse($p['reception_mode']);
        $this->assertNull($p['required_plan_id']);
        $this->assertNull($p['preferred_commercial_plan']);
        $this->assertContains('plan_recommendation', $p['forbidden_actions']);
        $this->assertContains('payment', $p['forbidden_actions']);
        $this->assertSame(['answer', 'confirm_commitment', 'wait'], $p['allowed_next_actions']);

        $vende = 'Tu visita es mañana a las 2. Y el TOTAL ACCESS - ELITE por $180.000 es el ideal para ti, ¿lo activamos?';
        $this->assertContains(CommercialTurnPolicy::VIOLACION_MEMORIA_VENDE, CommercialTurnPolicy::violations($vende, $p, $planes));
        $pregunta = 'Tu visita quedó solicitada para mañana a las 14:00. ¿Cuál es tu objetivo principal?';
        $this->assertContains(CommercialTurnPolicy::VIOLACION_MEMORIA_VENDE, CommercialTurnPolicy::violations($pregunta, $p, $planes));
        $bien = 'Claro. Tu visita quedó solicitada para mañana miércoles a las 14:00; el equipo la confirma contigo.';
        $this->assertNotContains(CommercialTurnPolicy::VIOLACION_MEMORIA_VENDE, CommercialTurnPolicy::violations($bien, $p, $planes));

        // Un turno normal no lleva el modo.
        $normal = CommercialTurnPolicy::decide(SalesIntents::PRICING_QUESTION, 'DISCOVERY', CM::empty(), $planes, ['type' => 'none'], 'cuánto vale el élite?');
        $this->assertFalse($normal['memory_mode']);
        $this->assertNotContains(CommercialTurnPolicy::VIOLACION_MEMORIA_VENDE, CommercialTurnPolicy::violations($vende, $normal, $planes));

        // «¿Qué te parece?» no vende: en modo memoria puede cerrar una respuesta honesta.
        $pregunta = 'Claro. Tu visita quedó solicitada para el miércoles a las 14:00. ¿Qué te parece esa hora?';
        $this->assertNotContains(CommercialTurnPolicy::VIOLACION_MEMORIA_VENDE, CommercialTurnPolicy::violations($pregunta, $p, $planes));

        // Lo sensible y lo que no quiere seguir tienen su propio texto: nunca modo memoria.
        foreach ([SalesIntents::COMPLAINT, SalesIntents::HUMAN_REQUEST, SalesIntents::FRAUD_OR_PAYMENT_CLAIM, SalesIntents::MEDICAL_RISK_ESCALATION, SalesIntents::NOT_INTERESTED, SalesIntents::DO_NOT_CONTACT_REQUEST] as $intent) {
            $this->assertFalse(CommercialTurnPolicy::esTurnoDeMemoria($intent, 'me recuerdas a qué hora era mi visita?', $planes), $intent);
        }
        // Visita + precio en el mismo mensaje: se contesta el precio, con la memoria de contexto.
        $this->assertFalse(CommercialTurnPolicy::esTurnoDeMemoria(SalesIntents::GENERAL_INFO, 'a qué hora era mi visita? y cuánto vale el plan mensual?', $planes));
        $this->assertFalse(CommercialTurnPolicy::esTurnoDeMemoria(SalesIntents::GENERAL_INFO, 'a qué hora era mi visita? me interesa el TOTAL ACCESS - ELITE', $planes));
        $this->assertTrue(CommercialTurnPolicy::esTurnoDeMemoria(SalesIntents::SCHEDULE_QUESTION, 'me recuerdas a qué hora era mi visita?', $planes));

        // Y un turno de pago, cierre o precio nunca entra en modo memoria aunque el texto recuerde la visita.
        foreach ([SalesIntents::PAYMENT_LINK_REQUEST, SalesIntents::HIGH_INTENT_CLOSE, SalesIntents::PRICING_QUESTION] as $intent) {
            $p2 = CommercialTurnPolicy::decide($intent, 'DISCOVERY', CM::empty(), $planes, ['type' => 'none'], 'me recuerdas a qué hora era mi visita? y mándame el link');
            $this->assertFalse($p2['memory_mode'], $intent);
        }
    }

    // ── Fecha y hora desde el texto ───────────────────────────────────────────

    public static function fechas(): array
    {
        // Ahora: martes 22 de septiembre de 2026, 09:00 en Neiva.
        return [
            'mañana' => ['mañana miércoles a las 2 pm', '2026-09-23'],
            'pasado mañana' => ['pasado mañana', '2026-09-24'],
            'hoy' => ['hoy en la tarde', '2026-09-22'],
            'día de la semana futuro' => ['el sábado a las 10', '2026-09-26'],
            'hoy mismo por nombre' => ['este martes', '2026-09-22'],
            'el 25' => ['el 25 a las 6', '2026-09-25'],
            'el 25 de septiembre' => ['el 25 de septiembre', '2026-09-25'],
            'el 3 ya pasó este mes: el próximo' => ['el 3', '2026-10-03'],
            'el 31 de septiembre no existe' => ['el 31 de septiembre', null],
            '«en la mañana» no es una fecha' => ['puedo en la mañana', null],
            'nada' => ['quiero información', null],
            // Cláusula a cláusula: la negada se descarta, la que corrige manda, la duda no se adivina.
            'el viernes no, mejor el miércoles' => ['el viernes no, mejor el miércoles a las 10', '2026-09-23'],
            'el sábado no puedo, mejor el lunes' => ['el sábado no puedo, mejor el lunes', '2026-09-28'],
            'mañana no, pero el jueves sí' => ['mañana no puedo, pero el jueves sí', '2026-09-24'],
            'no, el sábado' => ['no, el sábado', '2026-09-26'],
            'hoy en día no es hoy' => ['hoy en día me sirve el sábado a las 10', '2026-09-26'],
            'mañana en la mañana' => ['mañana en la mañana', '2026-09-23'],
            'esta tarde es hoy' => ['esta tarde a las 4', '2026-09-22'],
            'duda entre dos días' => ['el sábado o el domingo', null],
            'día y número que no cuadran' => ['el lunes 29', null],
            'mañana jueves, siendo martes' => ['mañana jueves', null],
            'día con su número' => ['el martes 29', '2026-09-29'],
            'el próximo martes nunca es hoy' => ['el próximo martes', '2026-09-29'],
            'el lunes que viene' => ['el lunes que viene', '2026-09-28'],
            'dd/mm' => ['29/09', '2026-09-29'],
            'mes que ya pasó: el año que viene' => ['el 3 de enero', '2027-01-03'],
            'el uno a uno no es una fecha' => ['el 1 a 1 con el entrenador', null],
            'una hora no es un día' => ['el 2 de la tarde', null],
            'los sábados es costumbre' => ['los sábados trabajo', null],
            'duda' => ['no sé si el sábado', null],
            'un «si no» de cortesía no niega' => ['mañana a las 2 si no es molestia', '2026-09-23'],
            // La semana siguiente es la semana siguiente.
            'miercoles de la proxima semana' => ['el miércoles de la próxima semana', '2026-09-30'],
            'la proxima semana el martes' => ['la próxima semana el martes', '2026-09-29'],
            'la semana que viene, el jueves' => ['la semana que viene, el jueves', '2026-10-01'],
            'el otro martes es ambiguo' => ['el otro martes', null],
            'la proxima semana negada' => ['la próxima semana no puedo, mejor el jueves', '2026-09-24'],
            // Comparativos: se queda lo elegido, no lo comparado.
            'mejor X que Y' => ['mejor el sábado que el viernes', '2026-09-26'],
            'prefiero X que Y, a las 10' => ['prefiero el sábado que el viernes, a las 10', '2026-09-26'],
            'X me quedaria mejor que Y' => ['el sábado me quedaría mejor que el viernes', '2026-09-26'],
            'prefiero X antes que Y' => ['prefiero el sábado antes que el domingo', '2026-09-26'],
            // Lo que no elige nada.
            'mejor no suelto' => ['el viernes, mejor no', null],
            'mañana o pasado' => ['mañana o pasado', null],
            'si no se puede, entonces' => ['mañana a las 2, si no se puede entonces el jueves', null],
            'mejor X o Y' => ['mejor el sábado o el domingo', null],
            // El día de la semana con su número y su mes.
            'jueves 1 de octubre' => ['el jueves 1 de octubre a las 2 pm', '2026-10-01'],
            // Las cláusulas del gimnasio no dan la fecha de la visita.
            'abren el domingo' => ['¿abren el domingo? quiero ir el sábado', '2026-09-26'],
            // Segunda revisión.
            'pasao mañana' => ['pasao mañana', '2026-09-24'],
            'mañana o pasao' => ['mañana o pasao', null],
            // Pegado a «en la mañana», el 10 es la hora.
            'el 10 del sábado es la hora' => ['el sábado 10 en la mañana', '2026-09-26'],
            // Con la hora aparte, el 10 es un día, y el 10 de octubre también es sábado: no se adivina.
            'sábado 10 con otra hora' => ['el sábado 10 a las 8', null],
            'viernes 9 a las 6' => ['el viernes 9 a las 6', null],
            'menos el viernes' => ['puedo cualquier día menos el viernes', null],
            'pues mejor no' => ['el viernes, pues mejor no', null],
            'pues no' => ['el viernes, pues no', null],
            // «Mentiras» o «es broma» a secas retiran lo dicho, como un «no».
            'mentiras, corrige' => ['el sábado. mentiras, el domingo', '2026-09-27'],
            'mentiras, retira' => ['el viernes a las 10. mentiras', null],
            'no digas mentiras no retira' => ['no digas mentiras, el sábado', '2026-09-26'],
            'de la próxima, sin «semana»' => ['el martes de la próxima', '2026-09-29'],
            'de la otra' => ['el jueves de la otra', '2026-10-01'],
            'tipo 3 meses no es una fecha' => ['en tipo 3 meses empiezo', null],
            'hoy no, mañana sí' => ['hoy no, mañana sí', '2026-09-23'],
        ];
    }

    #[DataProvider('fechas')]
    public function test_la_fecha_que_dijo_la_persona(string $texto, ?string $esperado): void
    {
        $ahora = Carbon::parse('2026-09-22 14:00:00', 'UTC');
        $this->assertSame($esperado, CourtesyAuthority::fechaDesdeTexto($texto, $ahora), $texto);
    }

    /** Cuando el número y el día de la semana más próximo son el mismo día, no hay duda. */
    public function test_el_dia_de_la_semana_con_su_numero_si_coinciden(): void
    {
        $ahora = Carbon::parse('2026-09-29 15:00:00', 'UTC'); // martes 29 de septiembre, 10:00 en Neiva
        $this->assertSame('2026-10-03', CourtesyAuthority::fechaDesdeTexto('el sábado 3 a las 10', $ahora));
        $this->assertSame('2026-10-05', CourtesyAuthority::fechaDesdeTexto('el lunes 5', $ahora));
        $this->assertNull(CourtesyAuthority::fechaDesdeTexto('el sábado 10 a las 8', $ahora), 'registraba el 3 de octubre a quien pidió el 10');
        $this->assertNull(CourtesyAuthority::fechaDesdeTexto('el martes 6 a las 5', $ahora), 'registraba hoy a quien pidió el 6 de octubre');
    }

    public static function horas(): array
    {
        return [
            '2 pm' => ['mañana a las 2 pm', '14:00'],
            '2 p. m.' => ['a las 2 p. m.', '14:00'],
            'a las 2 sin más: tarde' => ['puedo ir a las 2', '14:00'],
            'a las 10 sin más: mañana' => ['a las 10', '10:00'],
            '10 am' => ['10 am', '10:00'],
            '14:00' => ['a las 14:00', '14:00'],
            '2 de la tarde' => ['tipo 2 de la tarde', '14:00'],
            '7 de la noche' => ['las 7 de la noche', '19:00'],
            '7 y media de la mañana' => ['a las 7 y media de la mañana', '07:30'],
            '6 y cuarto' => ['a las 6 y cuarto', '18:15'],
            'cifra suelta no es hora' => ['tengo 2 hijos', null],
            'hora imposible' => ['a las 25', null],
            'nada' => ['quiero ir mañana', null],
            // Todas las cifras, no la primera: la primera suele ser el día.
            'día y hora' => ['el 25 de septiembre a las 2 pm', '14:00'],
            'el 24 a las 3 pm' => ['quiero ir el 24 a las 3 pm', '15:00'],
            'lunes 29 a las 5' => ['voy el lunes 29 a las 5', '17:00'],
            'corrige la hora' => ['a las 2 no puedo, mejor a las 4', '16:00'],
            'duda entre dos horas' => ['a las 2 o a las 3', null],
            'la hora de la cláusula que vale' => ['el viernes no puedo a las 2, pero el sábado a las 10 sí', '10:00'],
            'mediodía' => ['a las 12 del mediodia', '12:00'],
            'medianoche' => ['a las 12 de la noche', '00:00'],
            'minutos imposibles' => ['a las 14:75', null],
            'minutos de la tarde' => ['a las 3:30', '15:30'],
            'veinticuatro horas con su cero' => ['a las 03:30', '03:30'],
            'días no son horas' => ['6 días a la semana', null],
            // Comparativos y rangos.
            'mejor a las 4 que a las 2' => ['mejor a las 4 que a las 2', '16:00'],
            'despues de las 5 es un rango' => ['después de las 5', null],
            'antes de las 8 es un rango' => ['mañana antes de las 8', null],
            'a partir de las 6 es un rango' => ['a partir de las 6', null],
            // El horario del gimnasio o el trabajo no son la hora de la visita.
            'hasta que hora abren, las 8' => ['quiero ir el sábado, ¿hasta qué hora abren? ¿las 8?', null],
            'abren a las 5' => ['quiero ir mañana, abren a las 5?', null],
            'salgo del trabajo a las 5' => ['salgo del trabajo a las 5, quiero ir mañana', null],
            // «Las 2» a secas no es una hora; con letras y «y media», sí.
            'las 2 personas' => ['mañana vamos las 2 con mi hermana', null],
            'y media sin prefijo' => ['7 y media', '07:30'],
            'dos de la tarde en letras' => ['a las dos de la tarde', '14:00'],
            'a las cinco' => ['a las cinco', '17:00'],
            'al mediodia' => ['al mediodía', '12:00'],
            // Segunda revisión.
            '7.30 pm' => ['a las 7.30 pm', '19:30'],
            '6 30' => ['a las 6 30', '18:30'],
            '5 y 30' => ['a las 5 y 30', '17:30'],
            'la franja delante' => ['en la tarde a las 5', '17:00'],
            'la franja de la mañana delante' => ['en la mañana a las 6', '06:00'],
            'sábado 10 en la mañana' => ['el sábado 10 en la mañana', '10:00'],
            '5 o 6' => ['a las 5 o 6', null],
            'menos cuarto' => ['a las 5 menos cuarto', '16:45'],
            'de 5 a 7' => ['de 5 a 7', null],
            'entre las 4 y las 6' => ['entre las 4 y las 6', null],
            'tipo 3 meses' => ['tipo 3 meses', null],
            'corrige con la franja' => ['a las 7 de la mañana no, mejor en la tarde a las 5', '17:00'],
            // Tercera revisión: «temprano» y «la madrugada» son la mañana.
            'mañana temprano' => ['mañana temprano a las 6', '06:00'],
            'de la madrugada' => ['mañana a las 5 de la madrugada', '05:00'],
            'tempranito' => ['mañana tempranito a las 5', '05:00'],
            'temprano detrás' => ['el lunes a las 6 temprano', '06:00'],
        ];
    }

    #[DataProvider('horas')]
    public function test_la_hora_que_dijo_la_persona(string $texto, ?string $esperado): void
    {
        $this->assertSame($esperado, CourtesyAuthority::horaDesdeTexto($texto), $texto);
    }

    // ── Frases que dan por agendada una visita ────────────────────────────────

    public static function afirmaciones(): array
    {
        return [
            'tienes agendada (producción)' => ['Tienes agendada tu visita mañana a las 2 pm.', true],
            'ya tienes confirmada' => ['Ya tienes confirmada tu cita.', true],
            'negada' => ['Todavía no tienes agendada la visita: el equipo la confirma.', false],
            'honesta: solicitada' => ['Tu visita quedó solicitada para el miércoles 23 a las 14:00; el equipo la confirma.', false],
            'honesta: dejé registrada tu solicitud' => ['Dejé registrada tu solicitud y el equipo te confirma.', false],
            // Anclado a la visita: lo confirmado de otras cosas sale.
            'pregunta del agente' => ['¿Ya tienes agendado un día para venir?', false],
            'la zona reservada' => ['Tienes que traer ropa cómoda, tenemos reservada la zona funcional para las clases.', false],
            'el horario confirmado' => ['Tengo confirmado con el equipo que el horario del sábado es de 7 a 2.', false],
            'el pago confirmado' => ['Sí, ya tienes confirmado tu pago del plan mensual.', false],
            'la membresia confirmada' => ['Listo: tu pago quedó aprobado y ya tienes confirmada tu membresía.', false],
            // Los huecos que quedaban.
            'tienes tu cita confirmada' => ['Tienes tu cita confirmada para el miércoles a las 2.', true],
            'queda agendada tu visita' => ['Queda agendada tu visita para el miércoles a las 2.', true],
            'ya esta agendada tu visita' => ['Ya está agendada tu visita para mañana.', true],
            'agendamos tu visita' => ['Listo, agendamos tu visita para el miércoles a las 2.', true],
            'con la fecha en medio' => ['Tu visita del miércoles 1 de octubre a las 14:00 ya está confirmada.', true],
            'la tienes agendada' => ['La tienes agendada para mañana a las 2.', true],
            // Segunda revisión.
            'listo, te agende' => ['Listo, te agendé para el miércoles a las 2.', true],
            'quedo programada' => ['Hecho: queda programada tu visita para el sábado.', true],
            'el equipo ya confirmo' => ['El equipo ya confirmó tu visita del sábado.', true],
            'aprobada tu solicitud' => ['Ya está aprobada tu solicitud de cortesía.', true],
            'ofrece agendar' => ['¿Quieres que te agende para el sábado?', false],
            // Tercera revisión: el pretérito con el día, «¡listo!», «ya te agendé» a secas, «te dejé agendada».
            'te agendé el sábado' => ['Listo, te agendé el sábado a las 10.', true],
            'ya te agendé' => ['Listo, ya te agendé.', true],
            'te agendé mañana' => ['Listo, te agendé mañana a las 6 pm.', true],
            'ya te reservé el sábado' => ['Listo, ya te reservé el sábado a las 10.', true],
            'te separé el cupo' => ['Listo, te separé el cupo del sábado a las 10.', true],
            '¡listo! agendé' => ['¡Listo! Agendé tu visita del sábado a las 10.', true],
            'te dejé agendada' => ['Listo, te dejé agendada tu visita para el sábado a las 10.', true],
            'si quieres te agendo' => ['Si quieres te agendo el sábado a las 10.', false],
            'la zona reservada' => ['La zona la dejamos reservada para las clases.', false],
            'ya te agendé no' => ['Ya te agendé no, todavía falta la hora.', false],
        ];
    }

    public static function registros(): array
    {
        return [
            'queda registrado tu interés (producción)' => ['Listo, queda registrado tu interés para el miércoles a las 2.', true],
            'tu visita quedó registrada' => ['Listo, tu visita quedó registrada para el sábado a las 10.', true],
            'dejé registrada tu visita' => ['Listo, dejé registrada tu visita para el sábado.', true],
            'registré tu visita' => ['Listo, registré tu visita del sábado.', true],
            'quedó solicitada tu visita' => ['Listo, quedó solicitada tu visita para el sábado.', true],
            'ya quedaste registrado' => ['Ya quedaste registrado para el sábado a las 10.', true],
            'la app' => ['¿Ya estás registrado en la app?', false],
            'registrado en la app' => ['Ya quedaste registrado en la app, ahora inicia sesión.', false],
            'todavía no' => ['Todavía no queda registrada tu visita: dime la hora.', false],
            'no quedó registrada' => ['Tu visita no quedó registrada porque el domingo cerramos.', false],
            'para que quede' => ['Para que tu visita quede registrada, dime la hora.', false],
            'ofrece registrar' => ['¿Quieres que registre tu visita para el sábado?', false],
            'futuro' => ['Tu visita quedará registrada cuando me digas la hora.', false],
        ];
    }

    /**
     * Sin ninguna solicitud, dar la visita por REGISTRADA también es falso. Va
     * aparte de la confirmación: registrar no es confirmar, y el contraste del
     * modo memoria sólo pregunta por lo segundo.
     */
    #[DataProvider('registros')]
    public function test_la_guarda_reconoce_la_visita_dada_por_registrada(string $frase, bool $cae): void
    {
        $g = new OutboundContentGuard;
        $this->assertSame($cae, $g->courtesyRegistrationIn($frase) !== null, $frase);
        $this->assertNull($g->courtesyConfirmationIn($frase), 'registrar no es confirmar: '.$frase);
    }

    #[DataProvider('afirmaciones')]
    public function test_la_guarda_reconoce_las_frases_que_salieron_en_produccion(string $frase, bool $cae): void
    {
        $g = new OutboundContentGuard;
        $this->assertSame($cae, $g->courtesyConfirmationIn($frase) !== null, $frase);
    }

    // ── Lo que se registra y lo que se contrasta ─────────────────────────────

    public static function dudas(): array
    {
        return [
            'día y hora' => ['mañana a las 2 pm', true],
            'día y hora, a secas' => ['el sábado a las 10', true],
            'con un gracias' => ['mañana a las 2, gracias', true],
            'dos horas' => ['mañana a las 2 o 3', false],
            'mejor no' => ['el viernes mejor no', false],
            'aproximada: tipo' => ['tipo 4 pm', false],
            'aproximada: como a' => ['como a las 4', false],
            'pregunta' => ['mañana a las 2?', false],
            'rango' => ['después de las 5', false],
        ];
    }

    /** Registrar una visita desde el texto sólo cuando nada en él duda: ante la duda se pregunta. */
    #[DataProvider('dudas')]
    public function test_solo_se_registra_lo_dicho_sin_dudas(string $texto, bool $sinDudas): void
    {
        $this->assertSame($sinDudas, CourtesyAuthority::textoSinDudas($texto), $texto);
    }

    /** Para contrastar un borrador se leen TODAS sus fechas y horas, y la hora sin marca lleva sus dos lecturas. */
    public function test_las_menciones_del_borrador_son_todas(): void
    {
        $ahora = Carbon::parse('2026-09-22 14:00:00', 'UTC');
        $m = CourtesyAuthority::mencionesEn('Tu visita del miércoles 23 a las 14:00 o, si prefieres, el jueves 24 a las 4 pm.', $ahora);
        $this->assertSame(['2026-09-23', '2026-09-24'], $m['fechas']);
        $this->assertSame([['14:00'], ['16:00']], $m['horas']);
        $this->assertSame([['09:00', '21:00']], CourtesyAuthority::mencionesEn('Claro, el martes a las 9.', $ahora)['horas']);
        $this->assertSame([['19:00']], CourtesyAuthority::mencionesEn('Te esperamos a las 7 p. m. del sábado.', $ahora)['horas']);
    }

    public static function cambiosDeVisita(): array
    {
        return [
            // Lo afirman: sin la herramienta, no salen.
            'cancelé tu visita' => ['Listo, cancelé tu visita del sábado.', true],
            'la moví' => ['Ya la moví para el jueves a las 10.', true],
            'quedó cancelada' => ['Tu visita quedó cancelada.', true],
            'la pasamos para' => ['Perfecto, la pasamos para el viernes.', true],
            'la reagendé' => ['Ya la reagendé.', true],
            'la segunda afirma' => ['No cancelé la visita del sábado; la moví para el lunes.', true],
            'tras otra pregunta' => ['¿Viste? Ya la moví para el viernes.', true],
            // Lo ofrecen, lo preguntan o hablan de otra cosa.
            'los horarios' => ['Te pasé los horarios para el sábado.', false],
            'que la cancele' => ['¿Quieres que la cancele?', false],
            'que te la cambie' => ['Si quieres que te la cambie, dime el día.', false],
            'que te la reagende' => ['Dime si quieres que te la reagende.', false],
            'que la cancele el equipo' => ['Lo mejor es que la cancele el equipo desde el panel.', false],
            'negada' => ['No cancelé tu visita: sigue en pie.', false],
            'si quieres, la cambiamos' => ['Si quieres, la cambiamos para el sábado.', false],
            'la cambiamos, te parece' => ['La cambiamos para el viernes, ¿te parece?', false],
            'cuando me confirmes' => ['Cuando me confirmes, la cancelamos.', false],
            'la pasamos bien' => ['¡En las clases la pasamos increíble!', false],
            'el plan' => ['El plan lo cambiamos cuando quieras.', false],
            // Tercera revisión: lo que afirma aunque lleve coletilla, tranquilizador o el participio delante.
            'con coletilla interrogativa' => ['Listo, cancelé tu visita del sábado, ¿algo más?', true],
            'coletilla tras salto de línea' => ["Listo, cancelé tu visita del sábado\n¿Te ayudo con algo más?", true],
            'coletilla tras emoji' => ['Listo, cancelé tu visita del sábado 👍 ¿Te ayudo con algo más?', true],
            'sin problema' => ['Sin problema, cancelé tu visita del sábado.', true],
            'no hay problema' => ['No hay problema, ya la cancelé.', true],
            'no te preocupes' => ['No te preocupes, ya la moví para el lunes.', true],
            'bueno no es no' => ['Bueno ya te la cancelé', true],
            'participio y coletilla' => ['Perfecto, tu visita quedó cancelada, ¿algo más?', true],
            'movida a un domingo, con coletilla' => ['Listo, la moví para el domingo a las 9, ¿te queda bien?', true],
            'participio delante' => ['Listo, ya quedó cancelada tu visita del sábado.', true],
            'pasiva refleja' => ['Listo, se canceló tu visita del sábado.', true],
            'pasiva refleja detrás' => ['Tu visita del sábado ya se canceló.', true],
            'la dejé cancelada' => ['Listo, te la dejé cancelada.', true],
            'dejé cancelada tu visita' => ['Listo, dejé cancelada tu visita.', true],
            'te la acabo de cancelar' => ['Listo, te la acabo de cancelar.', true],
            'acabo de cancelar tu visita' => ['Listo, acabo de cancelar tu visita del sábado.', true],
            'reprogramada delante' => ['Ya quedó reprogramada tu visita para el lunes a las 5 pm.', true],
            'participio solo' => ['Listo, ya quedó anulada.', true],
            'eliminé' => ['Listo, eliminé tu visita del sábado.', true],
            'he cancelado' => ['He cancelado tu visita del sábado.', true],
            'ha sido cancelada' => ['Tu visita ha sido cancelada.', true],
            'dos puntos' => ['Tranquilo, no hay lío: ya la pasé para el viernes a las 5 pm.', true],
            'ya la cancelamos' => ['Listo, ya la cancelamos, ¿algo más?', true],
            'hecho y ofrece otra' => ['Ya te la cancelé, cuando quieras agendamos otra.', true],
            // Y lo que no afirma: la negación pegada, la pregunta, otra cosa, el ofrecimiento.
            'no está cancelada' => ['Tu visita no está cancelada, sigue en pie.', false],
            'no quedó cancelada' => ['Tu visita no quedó cancelada: como ya la confirmó el equipo, desde aquí no puedo cancelarla.', false],
            'sigue en pie, no está cancelada' => ['Tu visita sigue en pie, no está cancelada.', false],
            'todavía no' => ['Tu visita todavía no está cancelada.', false],
            'no fue cancelada' => ['No, tu visita no fue cancelada.', false],
            'todavía no la he cancelado' => ['Todavía no la he cancelado: dime si de verdad quieres cancelarla.', false],
            'pregunta' => ['¿Tu visita quedó cancelada?', false],
            'pregunta sin abrir' => ['Ya se canceló tu visita?', false],
            'cuando quieras, detrás' => ['Tranquilo, reagendamos tu visita cuando quieras. ¿Qué día te sirve?', false],
            'si quieres, detrás' => ['La cancelamos si quieres.', false],
            'si te parece, detrás' => ['La cambiamos para el viernes si te parece.', false],
            'si no puedes' => ['Si no puedes venir, la cancelamos sin problema.', false],
            'dime y' => ['Dime y la cancelamos.', false],
            'avísame y' => ['Avísame y la pasamos para el lunes.', false],
            'dime si' => ['Dime si la cancelamos o la pasamos para otro día.', false],
            'con gusto' => ['Con gusto la reagendamos. ¿Qué día y hora te quedan bien?', false],
            'pide el día después' => ['Claro, la reagendamos. ¿Qué día te sirve?', false],
            'la membresía' => ['La membresía la cancelamos directamente en recepción; acércate con tu documento.', false],
            'el débito' => ['El débito automático lo cancelamos desde recepción.', false],
            'la clase' => ['La clase de spinning de hoy la movimos para mañana a las 6 a. m.', false],
            'la clase en otra frase' => ['Tu clase era hoy. La moví para mañana a las 6.', false],
        ];
    }

    /** Cancelar o mover la visita es un efecto: decirlo hecho exige la herramienta; ofrecerlo, no. */
    #[DataProvider('cambiosDeVisita')]
    public function test_cancelar_o_mover_la_visita_se_dice_solo_si_se_hizo(string $frase, bool $afirma): void
    {
        $kind = app(PromiseAuthority::class)->detectar($frase)['kind'];
        $this->assertSame($afirma ? PromiseAuthority::EFECTO_DURABLE : null, $kind, $frase);
    }

    /** A un socio, a un exsocio o a quien tiene un pago pendiente no se le pregunta si es su primera vez. */
    public function test_la_pregunta_de_descubrimiento_no_es_para_quien_ya_conocemos(): void
    {
        $desconocido = ['known' => [], 'inferred' => [], 'unknown' => ['objective', 'experience_level', 'time_constraints']];
        $this->assertNotNull(StrategyContract::hints($desconocido + ['customer_lifecycle' => CustomerIntelligenceService::PROSPECT], [], false)['suggested_question']);
        foreach ([CustomerIntelligenceService::ACTIVE_MEMBER, CustomerIntelligenceService::PAYMENT_PENDING, CustomerIntelligenceService::LAPSED, CustomerIntelligenceService::ONBOARDING] as $ciclo) {
            $this->assertNull(StrategyContract::hints($desconocido + ['customer_lifecycle' => $ciclo], [], false)['suggested_question'], $ciclo);
        }
        // Y en un turno de memoria, ninguna.
        $this->assertNull(StrategyContract::hints($desconocido, [], false, null, [], [], false, true)['suggested_question']);
    }

    /** Un plan que la persona señala con la referencia («ese», «el que me dijiste») saca el turno de memoria. */
    public function test_el_plan_de_la_referencia_saca_el_turno_de_memoria(): void
    {
        $planes = [['id' => 7, 'name' => 'TOTAL ACCESS - ELITE', 'price' => 180000, 'is_recommended' => true]];
        $texto = 'me recuerdas a qué hora era mi visita?';
        $this->assertTrue(CommercialTurnPolicy::esTurnoDeMemoria(SalesIntents::SCHEDULE_QUESTION, $texto, $planes, ['type' => 'none']));
        $this->assertFalse(CommercialTurnPolicy::esTurnoDeMemoria(SalesIntents::SCHEDULE_QUESTION, $texto, $planes, ['type' => 'choose_plan', 'plan_id' => 7]));
        // Un plan que ya no se vende no cuenta.
        $this->assertTrue(CommercialTurnPolicy::esTurnoDeMemoria(SalesIntents::SCHEDULE_QUESTION, $texto, $planes, ['type' => 'choose_plan', 'plan_id' => 99]));
    }

    // ── La pregunta que toca ─────────────────────────────────────────────────

    public function test_la_pregunta_adaptativa_depende_de_lo_que_ya_se_sabe(): void
    {
        $todo = ['objective', 'experience_level', 'time_constraints'];
        $this->assertSame('seria tu primera vez entrenando o ya tienes experiencia?', StrategyContract::preguntaAdaptativa(null, null, null, $todo));
        // Sin «te gustaría»: el detector de ofertas lo registra como una oferta.
        $this->assertSame('que buscas lograr al empezar: bajar grasa, ganar masa o coger el habito?', StrategyContract::preguntaAdaptativa(null, 'beginner', null, $todo));
        $this->assertSame('vienes buscando mantener tu rendimiento o cambiar tu composicion corporal?', StrategyContract::preguntaAdaptativa(null, 'experienced', null, $todo));
        $this->assertSame('vienes buscando mantener tu rendimiento o cambiar tu composicion corporal?', StrategyContract::preguntaAdaptativa(null, 'advanced', null, $todo));
        $this->assertSame('seria tu primera vez entrenando o ya vienes con experiencia?', StrategyContract::preguntaAdaptativa('bajar grasa', null, null, $todo));
        $this->assertSame('que dias o en que franja te queda mejor entrenar?', StrategyContract::preguntaAdaptativa('bajar grasa', 'beginner', null, $todo));
        $this->assertNull(StrategyContract::preguntaAdaptativa('bajar grasa', 'beginner', 'noches', $todo), 'sabiendo todo, no se pregunta');
        $this->assertNull(StrategyContract::preguntaAdaptativa(null, null, null, []), 'sin permiso para preguntar, nada');
        $this->assertNull(StrategyContract::preguntaAdaptativa(null, null, null, ['main_barrier']), 'lo que no está en may_ask no se pregunta');
        foreach (['cual es tu objetivo', 'que necesitas', 'para orientarte'] as $muletilla) {
            foreach ([[null, null, null], [null, 'beginner', null], [null, 'experienced', null], ['x', null, null], ['x', 'beginner', null]] as [$o, $e, $a]) {
                $this->assertStringNotContainsString($muletilla, (string) StrategyContract::preguntaAdaptativa($o, $e, $a, $todo));
            }
        }
    }
}
